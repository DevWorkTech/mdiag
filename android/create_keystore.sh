#!/usr/bin/env bash
set -euo pipefail

output="${1:-mdiag-release.jks}"
alias_name="${MDIAG_KEY_ALIAS:-mdiag}"
store_password="${MDIAG_STORE_PASSWORD:?Set MDIAG_STORE_PASSWORD}"
key_password="${MDIAG_KEY_PASSWORD:-$store_password}"

keytool -genkeypair   -keystore "$output"   -storepass "$store_password"   -keypass "$key_password"   -alias "$alias_name"   -keyalg RSA   -keysize 4096   -validity 10000   -dname "CN=MDiag DWT, OU=DevWorkTech, O=DevWorkTech, L=Local, C=DE"

echo "Created $output"
echo "Keep this file and its passwords safe. Losing it prevents in-place APK updates."
