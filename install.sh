#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Ravan single-server installer.
#
#   ./install.sh                     interactive first install
#   ./install.sh --domain ravan.example.com --email admin@example.com --yes
#   ./install.sh --upgrade           rebuild and restart, keeping .env and data
#
# Everything it creates lives in this directory: .env (secrets) and the docker
# volumes. Re-running is safe — existing secrets are never regenerated.
# ---------------------------------------------------------------------------
set -euo pipefail

cd "$(dirname "$0")"

DOMAIN=""; EMAIL=""; ASSUME_YES=false; UPGRADE=false; SEED=""
while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="$2"; shift 2 ;;
        --email)  EMAIL="$2";  shift 2 ;;
        --seed)   SEED=true;   shift ;;
        --yes|-y) ASSUME_YES=true; shift ;;
        --upgrade) UPGRADE=true; shift ;;
        -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!  \033[0m%s\n' "$*" >&2; }
die()  { printf '\033[1;31mx  \033[0m%s\n' "$*" >&2; exit 1; }

# --------------------------------------------------------------- requirements
say "checking requirements"
command -v docker >/dev/null || die "docker is not installed — see https://docs.docker.com/engine/install/"
docker compose version >/dev/null 2>&1 || die "the docker compose plugin is missing (install docker-compose-plugin)"
command -v openssl >/dev/null || die "openssl is required to generate secrets"
docker info >/dev/null 2>&1 || die "cannot talk to the docker daemon — is it running, and are you in the docker group?"

mem_kb=$(awk '/MemTotal/{print $2}' /proc/meminfo 2>/dev/null || echo 0)
if [ "$mem_kb" -gt 0 ] && [ "$mem_kb" -lt 3800000 ]; then
    warn "this machine has $((mem_kb / 1024)) MB of RAM. Ravan needs about 4 GB;"
    warn "with the local large-v3 speech model, 8 GB. Set RAVAN_ASR_MODEL=small in .env on a small server."
fi
free_gb=$(df -Pk . | awk 'NR==2{print int($4/1048576)}')
[ "${free_gb:-99}" -lt 20 ] && warn "only ${free_gb} GB free here; the images and the speech model need about 20 GB."

# ------------------------------------------------------------------- env file
gen() { openssl rand -base64 "${1:-32}" | tr -d '\n=+/' | cut -c "1-${2:-40}"; }

if [ ! -f .env ]; then
    $UPGRADE && die "--upgrade needs an existing .env"
    say "creating .env"
    cp .env.example .env

    if [ -z "$DOMAIN" ]; then
        if $ASSUME_YES; then DOMAIN=localhost
        else
            read -rp "Domain for this server (blank = localhost, self-signed TLS): " DOMAIN
            DOMAIN="${DOMAIN:-localhost}"
        fi
    fi
    if [ -z "$EMAIL" ] && [ "$DOMAIN" != "localhost" ]; then
        if $ASSUME_YES; then EMAIL="admin@${DOMAIN}"
        else
            read -rp "E-mail for the Let's Encrypt certificate [admin@${DOMAIN}]: " EMAIL
            EMAIL="${EMAIL:-admin@${DOMAIN}}"
        fi
    fi

    set_env() { # set_env KEY VALUE
        local k="$1" v="$2"
        if grep -q "^${k}=" .env; then
            python3 - "$k" "$v" <<'PY'
import sys, pathlib
key, val = sys.argv[1], sys.argv[2]
p = pathlib.Path('.env')
p.write_text(''.join(
    f'{key}={val}\n' if line.startswith(key + '=') else line
    for line in p.read_text().splitlines(keepends=True)
))
PY
        else
            printf '%s=%s\n' "$k" "$v" >> .env
        fi
    }

    set_env RAVAN_DOMAIN "$DOMAIN"
    [ -n "$EMAIL" ] && set_env RAVAN_TLS_EMAIL "$EMAIL"
    set_env MAIL_FROM_ADDRESS "no-reply@${DOMAIN}"
    [ -n "$SEED" ] && set_env RAVAN_SEED true

    say "generating secrets"
    set_env APP_KEY "base64:$(openssl rand -base64 32)"
    set_env DB_PASSWORD          "$(gen 32 32)"
    set_env DB_ROOT_PASSWORD     "$(gen 32 32)"
    set_env REDIS_PASSWORD       "$(gen 32 32)"
    set_env REVERB_APP_ID        "$(gen 16 12)"
    set_env REVERB_APP_KEY       "$(gen 24 24)"
    set_env REVERB_APP_SECRET    "$(gen 32 32)"
    set_env LIVEKIT_API_KEY      "API$(gen 12 12)"
    set_env LIVEKIT_API_SECRET   "$(gen 48 48)"
    set_env TURN_SECRET          "$(gen 32 32)"
    set_env RAVAN_ANALYSIS_TOKEN "$(gen 40 40)"
    set_env RAVAN_WEBHOOK_SECRET "$(gen 40 40)"
    set_env RAVAN_ASR_TOKEN      "$(gen 40 40)"
    chmod 600 .env
    say ".env written — back this file up, it is the only copy of these secrets"
else
    say ".env already exists; keeping it and its secrets"
fi

# shellcheck disable=SC1091
set -a; . ./.env; set +a
: "${RAVAN_DOMAIN:?RAVAN_DOMAIN missing from .env}"

# ---------------------------------------------------------------- pre-flight
if [ "$RAVAN_DOMAIN" != "localhost" ]; then
    resolved=$(getent hosts "$RAVAN_DOMAIN" 2>/dev/null | awk '{print $1}' | head -1 || true)
    if [ -z "$resolved" ]; then
        warn "$RAVAN_DOMAIN does not resolve yet. Caddy will keep retrying, but TLS stays pending until DNS points here."
    fi
fi
for p in 80 443; do
    if command -v ss >/dev/null && ss -lnt "sport = :$p" 2>/dev/null | grep -q LISTEN; then
        die "port $p is already in use. Stop the service holding it (often nginx or apache) and re-run."
    fi
done

# ------------------------------------------------------------ vendor + build
say "fetching MediaPipe runtime and models (about 60 MB, cached after the first run)"
./deploy/fetch-vendor.sh flutter_app/web/vendor || warn "vendor fetch failed; the web image will retry during its build"

say "building images (first build takes 10-20 minutes)"
docker compose build

say "starting the stack"
docker compose up -d

say "waiting for the backend to finish migrations"
for i in $(seq 1 90); do
    if docker compose ps --format '{{.Service}} {{.Health}}' 2>/dev/null | grep -q 'mysql healthy'; then break; fi
    sleep 2
done
docker compose logs --no-log-prefix --tail 20 backend || true

# ------------------------------------------------------------------- summary
cat <<EOF

  Ravan is up.

  App          https://${RAVAN_DOMAIN}/
  API health   https://${RAVAN_DOMAIN}/api/health

  Create the first administrator:
      docker compose exec backend php artisan ravan:create-admin

  Logs         docker compose logs -f backend
  Stop         docker compose down
  Back up      ./deploy/backup.sh

EOF

if [ "$RAVAN_DOMAIN" != "localhost" ]; then
cat <<EOF
  Open these on the firewall, or calls will fail for anyone behind NAT:
      443/tcp  80/tcp        web and certificate renewal
      7881/tcp                LiveKit media over TCP
      50000-50200/udp         LiveKit media
      3478/tcp 3478/udp       TURN
      5349/tcp 5349/udp       TURN over TLS
      50300-50500/udp         TURN relay

EOF
fi
