#!/bin/sh
# Append the runtime-only settings (secret, external IP, TLS material) to the
# static config so no credential is ever written into the repository.
set -eu

CONF=/tmp/turnserver.conf
cp /etc/coturn/turnserver.conf "$CONF"

: "${TURN_SECRET:?TURN_SECRET is required}"
echo "static-auth-secret=${TURN_SECRET}" >> "$CONF"

if [ -n "${TURN_REALM:-}" ]; then
    sed -i "s/^realm=.*/realm=${TURN_REALM}/" "$CONF"
fi
if [ -n "${TURN_EXTERNAL_IP:-}" ]; then
    echo "external-ip=${TURN_EXTERNAL_IP}" >> "$CONF"
fi
# Reuse the certificate Caddy already obtained for the domain, if it is there.
CERT_DIR=$(find /caddy-data/caddy/certificates -type d -name "${TURN_REALM:-_}" 2>/dev/null | head -1)
if [ -n "$CERT_DIR" ] && [ -f "$CERT_DIR/${TURN_REALM}.crt" ]; then
    echo "cert=$CERT_DIR/${TURN_REALM}.crt" >> "$CONF"
    echo "pkey=$CERT_DIR/${TURN_REALM}.key" >> "$CONF"
else
    echo "[coturn] no certificate mounted; TURN over TLS (5349) is disabled" >&2
    echo "no-tls" >> "$CONF"
    echo "no-dtls" >> "$CONF"
fi

exec turnserver -c "$CONF" -n --log-file=stdout
