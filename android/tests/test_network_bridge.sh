#!/usr/bin/env bash
set -euo pipefail
# Все сертификаты тестовые; стенд работает только на loopback CI runner.
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
curl -fsSL --retry 3 https://repo.maven.apache.org/maven2/com/squareup/okhttp3/okhttp/3.12.13/okhttp-3.12.13.jar -o "$work/okhttp.jar"
curl -fsSL --retry 3 https://repo.maven.apache.org/maven2/com/squareup/okio/okio/1.15.0/okio-1.15.0.jar -o "$work/okio.jar"
for item in good wrong untrusted; do
  dns=localhost
  if [[ "$item" == wrong ]]; then dns=wrong.invalid; fi
  keytool -genkeypair -noprompt -storetype JKS -keystore "$work/$item.jks" -storepass local-test -keypass local-test -alias server -keyalg RSA -keysize 2048 -validity 1 -dname "CN=$dns" -ext "SAN=dns:$dns" >/dev/null 2>&1
  if [[ "$item" != untrusted ]]; then
    keytool -exportcert -keystore "$work/$item.jks" -storepass local-test -alias server -file "$work/$item.crt" >/dev/null 2>&1
    keytool -importcert -noprompt -storetype JKS -keystore "$work/trust.jks" -storepass local-test -alias "$item" -file "$work/$item.crt" >/dev/null 2>&1
  fi
done
mkdir -p "$work/src" "$work/classes"
# В release APK имя статического поля OkHostnameVerifier обфусцировано в a.
# ABI проверяет patch_mdiag.py. Для stock OkHttp в JVM тесте оно называется INSTANCE.
sed -e 's|__MDIAG_BASE__|https://localhost:18443/xdiag|g' -e 's/verifier.getField("a")/verifier.getField("INSTANCE")/' android/NetworkBridge.java > "$work/src/NetworkBridge.java"
classpath="$work/okhttp.jar:$work/okio.jar"
javac --release 8 -cp "$classpath" -d "$work/classes" "$work/src/NetworkBridge.java" android/tests/bridge/j/*.java android/tests/NetworkBridgeTest.java
java -Djavax.net.ssl.trustStore="$work/trust.jks" -Djavax.net.ssl.trustStorePassword=local-test -cp "$work/classes:$classpath" NetworkBridgeTest "$work/good.jks" "$work/wrong.jks" "$work/untrusted.jks"
