#!/usr/bin/env python3

from __future__ import annotations

import argparse
import json
import re
import subprocess
from pathlib import Path
from urllib.parse import urlparse
from xml.etree import ElementTree as ET

ANDROID_NS = "http://schemas.android.com/apk/res/android"
RESOURCE_NS_PREFIX = "http://schemas.android.com/apk/res/"
RESOURCE_NS_AUTO = "http://schemas.android.com/apk/res-auto"
ET.register_namespace("android", ANDROID_NS)

TEXT_SUFFIXES = {
    ".json", ".xml", ".txt", ".properties", ".conf", ".ini", ".smali"
}

COMPONENT_TAGS = {
    "application",
    "activity",
    "activity-alias",
    "service",
    "receiver",
    "provider",
    "instrumentation",
}

# Подтверждённые пути запросов на числовые серверы. Голый SOAP namespace
# https://79.174.70.97 не является HTTP endpoint и должен остаться неизменным.
LEGACY_DOWNLOAD_PATHS = (
    "/mobile/softCenter/adaspointdown.php",
    "/mobile/softCenter/diagpointdown.php",
    "/mobile/softCenter/downloadEncryptDiagSoft.action",
    "/mobile/softCenter/downloadDiagSoftWs.action",
    "/opendiag/downloadDiagSoftForDiag.php",
    "/diag/dlDiagSoftPack.php",
    "/services/",
    "/uc/services/",
    "/diagdevice/",
)


def android_attr(name: str) -> str:
    return f"{{{ANDROID_NS}}}{name}"


def qualify_class_name(value: str, package_name: str) -> str:
    if value.startswith("."):
        return package_name + value
    if "." not in value:
        return f"{package_name}.{value}"
    return value


def patch_manifest(root: Path, new_package: str, label: str, cleartext: bool = False) -> tuple[str, int]:
    manifest_path = root / "AndroidManifest.xml"
    tree = ET.parse(manifest_path)
    manifest = tree.getroot()
    original_package = manifest.attrib.get("package", "")

    if not original_package:
        raise RuntimeError("AndroidManifest.xml has no package attribute")

    qualified_components = 0

    for element in manifest.iter():
        tag = element.tag.rsplit("}", 1)[-1]
        if tag not in COMPONENT_TAGS:
            continue

        name = element.attrib.get(android_attr("name"))
        if name:
            qualified = qualify_class_name(name, original_package)
            if qualified != name:
                element.set(android_attr("name"), qualified)
                qualified_components += 1

        if tag == "activity-alias":
            target = element.attrib.get(android_attr("targetActivity"))
            if target:
                qualified = qualify_class_name(target, original_package)
                if qualified != target:
                    element.set(android_attr("targetActivity"), qualified)
                    qualified_components += 1

    # Классы остаются на исходных именах: меняется applicationId, а не Java ABI.
    # Отдельные custom permissions не должны конфликтовать с установленным оригиналом.
    for element in manifest.iter():
        if element.tag in {"permission", "uses-permission"}:
            name = element.get(android_attr("name"), "")
            if name.startswith(original_package + "."):
                element.set(android_attr("name"), new_package + name[len(original_package):])

    manifest.set("package", new_package)
    # Видно в настройках Android, установлен ли новый транспорт, а не старый APK.
    # Apktool берёт эти значения из apktool.yml, поэтому обновляем оба источника.
    manifest.set(android_attr("versionName"), "7.00.014-mdiag2")
    metadata = root / "apktool.yml"
    if metadata.exists():
        text = metadata.read_text()
        text = re.sub(r'(?m)^(\s*versionName:).*$' , r'\1 7.00.014-mdiag2', text)
        metadata.write_text(text)
    application = manifest.find("application")

    if application is None:
        raise RuntimeError("AndroidManifest.xml has no application element")

    application.set(android_attr("label"), label)
    # Сохраняем исходное разрешение HTTP: проверки Интернета и сторонние
    # страницы оригинала не должны ломаться из-за настройки нового домена.
    application.set(
        android_attr("networkSecurityConfig"),
        "@xml/mdiag_network_security_config",
    )

    for element in manifest.iter():
        for key, value in list(element.attrib.items()):
            if key == "package":
                continue
            if original_package in value and (
                key == android_attr("authorities")
                or key == android_attr("permission")
                or key == android_attr("readPermission")
                or key == android_attr("writePermission")
            ):
                element.set(key, value.replace(original_package, new_package))

    # WelcomeActivity имеет собственный @string/app_name, поэтому одной label недостаточно.
    for strings in (root / "res").glob("values*/strings.xml"):
        content = strings.read_text(encoding="utf-8")
        content = re.sub(r'(<string name="app_name"[^>]*>).*?(</string>)',
                         lambda m: m.group(1) + label + m.group(2), content)
        strings.write_text(content, encoding="utf-8")
    properties = root / "assets" / "config.properties"
    if properties.exists():
        content = properties.read_text(encoding="utf-8")
        content = re.sub(r"(?m)^package_path=.*$", "package_path=mXDiagPro3", content)
        properties.write_text(content, encoding="utf-8")
    tree.write(manifest_path, encoding="utf-8", xml_declaration=True)
    return original_package, qualified_components


def patch_resource_namespaces(
    root: Path,
    original_package: str,
) -> tuple[int, int]:
    old_namespace = RESOURCE_NS_PREFIX + original_package
    files_changed = 0
    replacements = 0

    for path in (root / "res").rglob("*.xml"):
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue

        count = text.count(old_namespace)
        if count == 0:
            continue

        path.write_text(
            text.replace(old_namespace, RESOURCE_NS_AUTO),
            encoding="utf-8",
        )
        files_changed += 1
        replacements += count

    return files_changed, replacements


def write_network_security(root: Path, lan_host: str, ca_file: Path | None = None, cleartext: bool = False) -> None:
    if not re.fullmatch(r"[A-Za-z0-9.-]+", lan_host):
        raise RuntimeError(f"Invalid LAN host: {lan_host!r}")

    xml_dir = root / "res" / "xml"
    xml_dir.mkdir(parents=True, exist_ok=True)
    extra_anchor = ""
    if ca_file is not None:
        # В APK попадает только публичный сертификат. PEM и DER нормализуем в PEM.
        data = ca_file.read_bytes()
        if b"PRIVATE KEY" in data:
            raise RuntimeError("CA file must not contain a private key")
        fmt = "PEM" if b"-----BEGIN CERTIFICATE-----" in data else "DER"
        checked = subprocess.run(["openssl", "x509", "-inform", fmt, "-in", str(ca_file),
                                  "-outform", "PEM"], check=True, capture_output=True).stdout
        raw_dir = root / "res" / "raw"
        raw_dir.mkdir(parents=True, exist_ok=True)
        (raw_dir / "mdiag_local_ca.pem").write_bytes(checked)
        extra_anchor = '            <certificates src="@raw/mdiag_local_ca" />'
    original = xml_dir / "network_security_config.xml"
    base_cleartext = "true"
    if original.exists():
        base = ET.parse(original).getroot().find("base-config")
        if base is not None:
            base_cleartext = base.get("cleartextTrafficPermitted", "true")
    (xml_dir / "mdiag_network_security_config.xml").write_text(
        f"""<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
    <base-config cleartextTrafficPermitted="{base_cleartext}">
        <trust-anchors>
            <certificates src="system" />
        </trust-anchors>
    </base-config>
    <domain-config cleartextTrafficPermitted="{str(cleartext).lower()}">
        <domain includeSubdomains="false">{lan_host}</domain>
        <trust-anchors>
            <certificates src="system" />
            <certificates src="user" />
{extra_anchor}
        </trust-anchors>
    </domain-config>
</network-security-config>
""",
        encoding="utf-8",
    )


def replace_xdiag_origins(text: str, old_base: str, new_base: str) -> tuple[str, int]:
    """Заменяет production-домен со всеми портами и download-IP из APK."""
    old_host = urlparse(old_base).hostname
    if not old_host:
        raise RuntimeError("--old-base must contain a hostname")

    # Важно заменять весь origin вместе с :8000/:8008/:8082 и необязательным
    # /dev. Обычный str.replace создавал ошибочный адрес /xdiag:8000.
    primary = re.compile(
        r"https?://" + re.escape(old_host) + r"(?::\d+)?(?:/dev)?",
        re.IGNORECASE,
    )
    updated, primary_count = primary.subn(new_base.rstrip("/"), text)

    # config.<зона> является отдельным origin, но после /xdiag сохраняется его
    # исходный /getConfig.php; локальный Laravel обрабатывает поддержанные методы без внешнего proxy.
    host_parts = old_host.split(".", 1)
    config_count = 0
    if len(host_parts) == 2:
        config_origin = re.compile(
            r"https?://config\." + re.escape(host_parts[1]) + r"(?::\d+)?",
            re.IGNORECASE,
        )
        updated, config_count = config_origin.subn(new_base.rstrip("/"), updated)

    # Старые числовые адреса перенаправляем только когда сразу после origin
    # идёт один из известных путей API/скачивания. SOAP namespace не затрагивается.
    legacy = re.compile(
        r"https?://(?:\d{1,3}\.){3}\d{1,3}(?::\d+)?"
        r"(?=(?:"
        + "|".join(re.escape(path) for path in LEGACY_DOWNLOAD_PATHS)
        + r"))",
        re.IGNORECASE,
    )
    updated, legacy_count = legacy.subn(new_base.rstrip("/"), updated)
    # Web-сервисы xdiagpro сохраняют host-префикс, чтобы одинаковые пути не конфликтовали.
    web = re.compile(r"https?://(?:(?P<sub>[a-z0-9-]+)\.)?xdiagpro\.com(?::\d+)?(?=/|[\"\s]|$)", re.IGNORECASE)
    updated, web_count = web.subn(lambda m: new_base.rstrip("/") + "/" + (m.group("sub") or "portal"), updated)
    # Строка разрешённых WebView-доменов в APK, не SOAP namespace.
    updated = updated.replace(",diagnosticonline.xdiagpro.com,", "," + urlparse(new_base).hostname + ",")
    return updated, primary_count + config_count + legacy_count + web_count


def patch_text_files(
    root: Path,
    old_base: str,
    new_base: str,
    original_package: str,
    new_package: str,
) -> tuple[int, int]:
    endpoint_changes = 0
    package_string_changes = 0

    const_string = re.compile(
        r'(const-string(?:/jumbo)?\s+[vp]\d+,\s*")'
        + re.escape(original_package)
        + r'((?:\.fileprovider)?")'
    )

    for path in root.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in TEXT_SUFFIXES:
            continue

        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue

        updated, count = replace_xdiag_origins(text, old_base, new_base)
        endpoint_changes += count

        if path.suffix.lower() == ".smali":
            # Эти два fallback-пути зашиты отдельно от assets/config.properties.
            # Меняем только строковые константы каталога, не методы диагностики.
            if path.name in {"PathUtils.smali", "DeviceProperties.smali"}:
                updated = updated.replace('"XDiagPro3"', '"mXDiagPro3"')
            updated, count = const_string.subn(
                lambda match: match.group(1) + new_package + match.group(2),
                updated,
            )
            package_string_changes += count

        if updated != text:
            path.write_text(updated, encoding="utf-8")

    return endpoint_changes, package_string_changes


def patch_apache_lan_tls(root: Path, lan_host: str) -> None:
    """Дополнительный Apache-стек; основной вход выполняется через OkHttp."""
    def source(suffix: str) -> Path:
        matches = [d / suffix for d in root.glob('smali*') if (d / suffix).is_file()]
        if len(matches) != 1:
            raise RuntimeError('Expected one Apache TLS class: ' + suffix)
        return matches[0]

    factory = source('org/apache/http/conn/ssl/SSLConnectionSocketFactory.smali').read_text()
    for signature in ('<init>(Ljavax/net/ssl/SSLContext;)V',
                      '<init>(Ljavax/net/ssl/SSLContext;Lorg/apache/http/conn/ssl/X509HostnameVerifier;)V'):
        if signature not in factory:
            raise RuntimeError('Unsupported Apache TLS constructor: ' + signature)
    builder = source('org/apache/http/impl/client/HttpClientBuilder.smali').read_text()
    if 'setSSLSocketFactory(Lorg/apache/http/conn/socket/LayeredConnectionSocketFactory;)' not in builder:
        raise RuntimeError('Unsupported Apache builder factory signature')
    client = source('com/xdiagpro/framework/network/http/AsyncHttpClient.smali')
    text = client.read_text()
    instruction = ('    invoke-virtual {v0, v1}, Lorg/apache/http/impl/client/HttpClientBuilder;'
                   '->setSslcontext(Ljavax/net/ssl/SSLContext;)Lorg/apache/http/impl/client/HttpClientBuilder;')
    if text.count(instruction) != 1:
        raise RuntimeError('Unrecognized AsyncHttpClient TLS initialization')
    hook = ('    invoke-static {v0, v1}, Ltech/devwork/mdiag/LanTls;'
            '->configureTrusted(Ljava/lang/Object;Ljavax/net/ssl/SSLContext;)V\n\n')
    client.write_text(text.replace(instruction, hook + instruction))
    helper = source('tech/devwork/mdiag/LanTls.smali')
    contents = helper.read_text()
    if '__MDIAG_LAN_HOST__' not in contents:
        raise RuntimeError('LAN TLS helper host placeholder missing')
    for part in helper.parent.glob('LanTls*.smali'):
        part.write_text(part.read_text().replace('__MDIAG_LAN_HOST__', lan_host))
    print('Apache TLS: system trust and hostname verification for LAN; original external BKS retained')


def patch_okhttp_transport(root: Path, new_base: str) -> None:
    """Проверяем реальные сигнатуры версии 7.00.014, а не похожие имена классов."""
    def source(suffix: str) -> Path:
        files = [p / suffix for p in root.glob('smali*') if (p / suffix).is_file()]
        if len(files) != 1:
            raise RuntimeError('Expected one transport class: ' + suffix)
        return files[0]

    checks = {
        'j/x$b.smali': ['a(Ljavax/net/ssl/SSLSocketFactory;Ljavax/net/ssl/X509TrustManager;)',
                        '<init>(Lj/x;)V', 'a(Lj/g;)', '.field public final e:Ljava/util/List;'],
        'j/a0.smali': ['g()Lj/t;', 'f()Lj/a0$a;', 'a(Ljava/lang/String;)Ljava/lang/String;'],
        'j/a0$a.smali': ['b(Ljava/lang/String;)Lj/a0$a;', 'a(Ljava/lang/String;)Lj/a0$a;',
                         'b(Ljava/lang/String;Ljava/lang/String;)Lj/a0$a;'],
        'j/z.smali': ['static a(Lj/x;Lj/a0;Z)Lj/z;'],
        'j/u$a.smali': ['request()Lj/a0;', 'a(Lj/a0;)Lj/c0;'],
        'j/c0.smali': ['c()I', 'a(Ljava/lang/String;)Ljava/lang/String;'],
        'j/g.smali': ['.field public static final c:Lj/g;'],
        'okhttp3/internal/tls/OkHostnameVerifier.smali': ['.field public static final a:'],
    }
    for path, signatures in checks.items():
        text = source(path).read_text()
        for signature in signatures:
            if signature not in text:
                raise RuntimeError('Unsupported OkHttp ABI: '+path+' '+signature)
    client = source('j/x.smali')
    text = client.read_text()
    pattern = r'(?ms)^\.method public a\(Lj/a0;\)Lj/e;.*?^\.end method'
    replacement = '''.method public a(Lj/a0;)Lj/e;
    .locals 1
    invoke-static {p0, p1}, Ltech/devwork/mdiag/NetworkBridge;->newCall(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/Object;
    move-result-object v0
    check-cast v0, Lj/e;
    return-object v0
.end method'''
    text, count = re.subn(pattern, lambda _: replacement, text)
    if count != 1:
        raise RuntimeError('Unrecognized OkHttp newCall')
    client.write_text(text)
    login = source('com/xdiagpro/xdiasft/module/b1/a/a.smali')
    text = login.read_text()
    # Здесь p1 — Context, p2 — TelephonyManager. Возвращаем совместимый DeviceId
    # даже когда READ_PHONE_STATE/IMEI недоступен. Сетевую проверку не меняем.
    old = 'invoke-virtual {p2}, Landroid/telephony/TelephonyManager;->getDeviceId()Ljava/lang/String;'
    if text.count(old) != 1:
        raise RuntimeError('Unrecognized login device ID call')
    login.write_text(text.replace(old,
        'invoke-static {p2, p1}, Ltech/devwork/mdiag/NetworkBridge;->deviceId(Ljava/lang/Object;Ljava/lang/Object;)Ljava/lang/String;'))
    helper = source('tech/devwork/mdiag/NetworkBridge.smali')
    for part in helper.parent.glob('NetworkBridge*.smali'):
        part.write_text(part.read_text().replace('__MDIAG_BASE__', new_base.rstrip('/')))
    print('OkHttp newCall: runtime URL routing, per-local-client system TLS, safe DeviceId, correlated trace')


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--decoded", required=True, type=Path)
    parser.add_argument("--old-base", required=True)
    parser.add_argument("--new-base", required=True)
    parser.add_argument("--lan-host")
    parser.add_argument("--ca-cert", type=Path, help="Public LAN CA certificate (PEM/DER); never a key")
    parser.add_argument("--new-package", required=True)
    parser.add_argument("--label", default="mX-DIAG")
    args = parser.parse_args()

    root = args.decoded.resolve()
    lan_host = args.lan_host or urlparse(args.new_base).hostname

    if not lan_host:
        raise RuntimeError("--new-base must contain a hostname or --lan-host must be set")

    original_package, qualified_components = patch_manifest(
        root,
        args.new_package,
        args.label,
        urlparse(args.new_base).scheme == "http",
    )
    namespace_files, namespace_replacements = patch_resource_namespaces(
        root,
        original_package,
    )
    write_network_security(root, lan_host, args.ca_cert, urlparse(args.new_base).scheme == "http")
    patch_apache_lan_tls(root, lan_host)
    patch_okhttp_transport(root, args.new_base)

    endpoints, package_strings = patch_text_files(
        root,
        args.old_base,
        args.new_base,
        original_package,
        args.new_package,
    )

    # JSON содержит экранированные \/: регулярная замена обычного URL их не видит.
    # Все config endpoints (включая телеметрию) ведут в локальный профиль;
    # неизвестные методы сервер отклоняет, никуда не пересылая данные планшета.
    bootstrap = root / "assets" / "configurl.json"
    if bootstrap.exists():
        config = json.loads(bootstrap.read_text(encoding="utf-8"))
        for item in config.get("data", {}).get("urls", []):
            parsed = urlparse(item["value"])
            if parsed.scheme in {"http", "https"}:
                web_prefix = ""
                if parsed.hostname and (parsed.hostname == "xdiagpro.com" or parsed.hostname.endswith(".xdiagpro.com")):
                    web_prefix = "/" + (parsed.hostname.removesuffix(".xdiagpro.com") if parsed.hostname != "xdiagpro.com" else "portal")
                if parsed.hostname == lan_host:
                    continue
                item["value"] = args.new_base.rstrip("/") + web_prefix + (parsed.path or "/") + (
                    "?" + parsed.query if parsed.query else "")
        bootstrap.write_text(json.dumps(config, ensure_ascii=False), encoding="utf-8")
        # Проверяем именно адреса bootstrap, а не XML/SOAP namespace.
        for item in config.get("data", {}).get("urls", []):
            if urlparse(item["value"]).hostname != lan_host:
                raise RuntimeError("Bootstrap still contains a non-local endpoint: " + str(item.get("key")))

    if endpoints == 0:
        raise RuntimeError(
            f"No occurrences of {args.old_base!r} were patched; refusing to build"
        )

    print(f"Original package: {original_package}")
    print(f"New package: {args.new_package}")
    print(f"LAN base: {args.new_base}")
    print(f"LAN host: {lan_host}")
    print(f"Qualified component names: {qualified_components}")
    print(f"Resource namespace files changed: {namespace_files}")
    print(f"Resource namespace replacements: {namespace_replacements}")
    print(f"Endpoint replacements: {endpoints}")
    print(f"Package string replacements: {package_strings}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
