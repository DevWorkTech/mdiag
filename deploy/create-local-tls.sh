#!/usr/bin/env bash
set -euo pipefail

domain_name="${1:-diag.devwork.local}"
tls_dir="${2:-/etc/nginx/mdiag-tls}"

if [[ ! "$domain_name" =~ ^[A-Za-z0-9.-]+$ ]]; then
  echo "Invalid DNS name: $domain_name" >&2
  exit 2
fi

install -d -m 0700 "$tls_dir"
umask 077

for required_path in \
  "$tls_dir/mdiag-local-ca.key" \
  "$tls_dir/mdiag-local-ca.crt" \
  "$tls_dir/$domain_name.key" \
  "$tls_dir/$domain_name.crt"
do
  if [[ -e "$required_path" ]]; then
    echo "Refusing to overwrite existing TLS material: $required_path" >&2
    exit 1
  fi
done

openssl genrsa -out "$tls_dir/mdiag-local-ca.key" 4096
openssl req -x509 -new -sha256 -days 3650 \
  -key "$tls_dir/mdiag-local-ca.key" \
  -out "$tls_dir/mdiag-local-ca.crt" \
  -subj "/CN=MDiag Local CA/O=DevWorkTech/C=DE" \
  -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
  -addext "keyUsage=critical,keyCertSign,cRLSign" \
  -addext "subjectKeyIdentifier=hash"

openssl genrsa -out "$tls_dir/$domain_name.key" 4096
openssl req -new -sha256 \
  -key "$tls_dir/$domain_name.key" \
  -out "$tls_dir/$domain_name.csr" \
  -subj "/CN=$domain_name/O=DevWorkTech/C=DE"

extensions_file="$(mktemp)"
trap 'rm -f "$extensions_file"' EXIT
printf '%s\n' \
  "basicConstraints=critical,CA:FALSE" \
  "keyUsage=critical,digitalSignature,keyEncipherment" \
  "extendedKeyUsage=serverAuth" \
  "subjectAltName=DNS:$domain_name" \
  "authorityKeyIdentifier=keyid,issuer" \
  "subjectKeyIdentifier=hash" > "$extensions_file"

openssl x509 -req -sha256 -days 825 \
  -in "$tls_dir/$domain_name.csr" \
  -CA "$tls_dir/mdiag-local-ca.crt" \
  -CAkey "$tls_dir/mdiag-local-ca.key" \
  -CAcreateserial \
  -out "$tls_dir/$domain_name.crt" \
  -extfile "$extensions_file"

rm -f "$tls_dir/$domain_name.csr" "$tls_dir/mdiag-local-ca.srl"
chmod 0600 "$tls_dir"/*.key
chmod 0644 "$tls_dir"/*.crt

# DER удобен для импорта через системный выбор CA на Android.
openssl x509 -in "$tls_dir/mdiag-local-ca.crt" -outform DER -out "$tls_dir/mdiag-local-ca.cer"
chmod 0644 "$tls_dir/mdiag-local-ca.cer"

echo "Server certificate: $tls_dir/$domain_name.crt"
echo "Server private key: $tls_dir/$domain_name.key"
echo "Install this CA certificate on the Android tablet:"
echo "$tls_dir/mdiag-local-ca.cer"
