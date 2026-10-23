#!/bin/sh
# Creates (and renews) the certificates that encrypt the app <-> MySQL connection.
#
# Runs as a one-shot compose service (quacktrack-db-certs) before the database starts.
# It is idempotent: an existing CA is kept, and the server certificate is only
# replaced when it is missing, close to expiry, or no longer signed by the CA.
#
# Three volumes keep each secret away from the containers that do not need it:
#   /ca-private  CA private key + CA cert      mounted here only
#   /tls         server key + cert + CA cert   mounted into the database container
#   /ca          CA cert only (public)         mounted into the app containers, which
#                                              use it to verify the database
set -eu

CA_KEY=/ca-private/ca-key.pem
CA_CERT=/ca-private/ca.pem
SERVER_KEY=/tls/server-key.pem
SERVER_CERT=/tls/server-cert.pem
CA_DAYS=3650
SERVER_DAYS=1095
RENEW_BEFORE=$((30 * 86400))
# Names the app may use to reach the database. The certificate is only valid for these.
SERVER_NAMES="${DB_TLS_NAMES:-quacktrack-db db localhost}"

umask 077
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# --- CA ---------------------------------------------------------------------------------------
if [ ! -f "$CA_KEY" ] || [ ! -f "$CA_CERT" ] || ! openssl x509 -checkend "$RENEW_BEFORE" -noout -in "$CA_CERT" >/dev/null; then
    echo "Creating database CA"
    openssl genrsa -out "$CA_KEY" 3072 2>/dev/null
    openssl req -x509 -new -key "$CA_KEY" -sha256 -days "$CA_DAYS" -subj "/CN=Quack Track database CA" \
        -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
        -addext "keyUsage=critical,keyCertSign,cRLSign" \
        -out "$CA_CERT"
    rm -f "$SERVER_CERT"   # anything signed by the old CA is no longer valid
fi

# --- Server certificate -------------------------------------------------------------------------
san=""
for name in $SERVER_NAMES; do
    san="${san:+$san,}DNS:$name"
done

if [ ! -f "$SERVER_KEY" ] || [ ! -f "$SERVER_CERT" ] \
    || ! openssl verify -CAfile "$CA_CERT" "$SERVER_CERT" >/dev/null 2>&1 \
    || ! openssl x509 -checkend "$RENEW_BEFORE" -noout -in "$SERVER_CERT" >/dev/null \
    || [ "$(openssl x509 -noout -ext subjectAltName -in "$SERVER_CERT" | tail -n 1 | tr -d ' ' | sed 's/DNS://g')" != "$(echo "$san" | sed 's/DNS://g')" ]; then
    echo "Creating database server certificate for: $SERVER_NAMES"
    openssl genrsa -out "$SERVER_KEY" 3072 2>/dev/null
    openssl req -new -key "$SERVER_KEY" -subj "/CN=quacktrack-db" -out "$tmp/server.csr"
    cat > "$tmp/server.ext" <<EXT
subjectAltName=$san
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
EXT
    openssl x509 -req -in "$tmp/server.csr" -CA "$CA_CERT" -CAkey "$CA_KEY" -CAcreateserial \
        -CAserial "$tmp/ca.srl" -days "$SERVER_DAYS" -sha256 -extfile "$tmp/server.ext" -out "$SERVER_CERT" 2>/dev/null
fi

# --- Publish with the right owners and modes ------------------------------------------------------
cp "$CA_CERT" /tls/ca.pem
cp "$CA_CERT" /ca/ca.pem
chown 999:999 /tls/ca.pem "$SERVER_CERT" "$SERVER_KEY"   # 999 = the mysql user in the image
chmod 0644 /tls/ca.pem "$SERVER_CERT" /ca/ca.pem
chmod 0600 "$SERVER_KEY"
chmod 0600 "$CA_KEY"

openssl verify -CAfile /ca/ca.pem "$SERVER_CERT" >/dev/null
echo "Database TLS ready. CA expires: $(openssl x509 -noout -enddate -in "$CA_CERT" | cut -d= -f2)," \
     "server certificate expires: $(openssl x509 -noout -enddate -in "$SERVER_CERT" | cut -d= -f2)"
