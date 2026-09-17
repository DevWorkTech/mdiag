#!/usr/bin/env python3

from __future__ import annotations

import argparse
import json
import re
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

# Старые IP используются APK только в штатных download-маршрутах. Ограничение
# по пути не позволяет случайно перенаправить в Laravel адреса производителей.
LEGACY_DOWNLOAD_PATHS = (
    "/mobile/softCenter/adaspointdown.php",
    "/mobile/softCenter/diagpointdown.php",
    "/mobile/softCenter/downloadEncryptDiagSoft.action",
    "/mobile/softCenter/downloadDiagSoftWs.action",
    "/opendiag/downloadDiagSoftForDiag.php",
    "/diag/dlDiagSoftPack.php",
)


def android_attr(name: str) -> str:
    return f"{{{ANDROID_NS}}}{name}"


def qualify_class_name(value: str, package_name: str) -> str:
    if value.startswith("."):
        return package_name + value
    if "." not in value:
        return f"{package_name}.{value}"
    return value


def patch_manifest(root: Path, new_package: str, label: str) -> tuple[str, int]:
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
    application = manifest.find("application")

    if application is None:
        raise RuntimeError("AndroidManifest.xml has no application element")

    application.set(android_attr("label"), label)
    application.set(android_attr("usesCleartextTraffic"), "false")
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


def write_network_security(root: Path, lan_host: str) -> None:
    if not re.fullmatch(r"[A-Za-z0-9.-]+", lan_host):
        raise RuntimeError(f"Invalid LAN host: {lan_host!r}")

    xml_dir = root / "res" / "xml"
    xml_dir.mkdir(parents=True, exist_ok=True)
    (xml_dir / "mdiag_network_security_config.xml").write_text(
        f"""<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
    <base-config cleartextTrafficPermitted="false">
        <trust-anchors>
            <certificates src="system" />
        </trust-anchors>
    </base-config>
    <domain-config cleartextTrafficPermitted="false">
        <domain includeSubdomains="false">{lan_host}</domain>
        <trust-anchors>
            <certificates src="system" />
            <certificates src="user" />
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
    # идёт один из известных путей скачивания. SOAP namespace не затрагивается.
    legacy = re.compile(
        r"https?://(?:\d{1,3}\.){3}\d{1,3}(?::\d+)?"
        r"(?=(?:"
        + "|".join(re.escape(path) for path in LEGACY_DOWNLOAD_PATHS)
        + r"))",
        re.IGNORECASE,
    )
    updated, legacy_count = legacy.subn(new_base.rstrip("/"), updated)
    return updated, primary_count + config_count + legacy_count


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
            updated, count = const_string.subn(
                lambda match: match.group(1) + new_package + match.group(2),
                updated,
            )
            package_string_changes += count

        if updated != text:
            path.write_text(updated, encoding="utf-8")

    return endpoint_changes, package_string_changes


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--decoded", required=True, type=Path)
    parser.add_argument("--old-base", required=True)
    parser.add_argument("--new-base", required=True)
    parser.add_argument("--lan-host")
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
    )
    namespace_files, namespace_replacements = patch_resource_namespaces(
        root,
        original_package,
    )
    write_network_security(root, lan_host)

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
                item["value"] = args.new_base.rstrip("/") + (parsed.path or "/") + (
                    "?" + parsed.query if parsed.query else "")
        bootstrap.write_text(json.dumps(config, ensure_ascii=False), encoding="utf-8")

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
