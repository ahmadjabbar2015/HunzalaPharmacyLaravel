#!/bin/sh
# nginx refuses to start without the certificate files its config names, so a
# fresh server would fail its very first `docker compose up` before certbot has
# ever had a chance to run.
#
# This mints a self-signed placeholder if - and only if - no certificate is
# present, so the stack always comes up. Replace it with a real one:
#
#   docker compose run --rm certbot
#   docker compose exec nginx nginx -s reload
#
# Staff log in over this connection, so a self-signed certificate is a
# bootstrap convenience, not an acceptable end state.
set -e

CERT_DIR=/etc/nginx/certs

if [ -s "$CERT_DIR/fullchain.pem" ] && [ -s "$CERT_DIR/privkey.pem" ]; then
    echo "[certs] using existing certificate"
    exit 0
fi

echo "[certs] no certificate found - generating a self-signed placeholder"
echo "[certs] THIS IS NOT TRUSTED. Run certbot before going live."

mkdir -p "$CERT_DIR"

openssl req -x509 -nodes -newkey rsa:2048 -days 365 \
    -keyout "$CERT_DIR/privkey.pem" \
    -out "$CERT_DIR/fullchain.pem" \
    -subj "/CN=${NGINX_SERVER_NAME:-localhost}" 2>/dev/null

chmod 600 "$CERT_DIR/privkey.pem"
