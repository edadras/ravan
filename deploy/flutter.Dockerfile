# ---------------------------------------------------------------------------
# Ravan web app — builds the Flutter web bundle and vendors the MediaPipe
# runtime + models into the bundle, so a deployed server never has to reach
# cdn.jsdelivr.net or storage.googleapis.com at runtime.
#
# Output is a plain static directory copied into the `web` volume that Caddy
# serves; this image exits after the copy.
# ---------------------------------------------------------------------------
FROM debian:bookworm-slim AS flutter

ARG FLUTTER_VERSION=3.27.4
ARG RAVAN_API_URL=/api
ARG RAVAN_WS_URL=""
ARG RAVAN_LIVEKIT_URL=""

ENV DEBIAN_FRONTEND=noninteractive \
    PATH="/opt/flutter/bin:/opt/flutter/bin/cache/dart-sdk/bin:${PATH}"

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git curl ca-certificates unzip xz-utils zip libglu1-mesa python3; \
    rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 --branch "${FLUTTER_VERSION}" https://github.com/flutter/flutter.git /opt/flutter \
    && git config --global --add safe.directory /opt/flutter \
    && flutter --version \
    && flutter config --no-analytics --enable-web \
    && flutter precache --web

WORKDIR /src
COPY flutter_app/pubspec.yaml flutter_app/pubspec.lock* ./
RUN flutter pub get

COPY flutter_app/ ./
# Pre-fetched vendor assets (MediaPipe wasm + .task models). fetch-vendor.sh
# populates web/vendor/ on the host; if it is empty the script below downloads.
COPY deploy/fetch-vendor.sh /usr/local/bin/fetch-vendor
RUN chmod +x /usr/local/bin/fetch-vendor && fetch-vendor /src/web/vendor

RUN flutter build web --release \
        --dart-define=RAVAN_API_URL="${RAVAN_API_URL}" \
        --dart-define=RAVAN_WS_URL="${RAVAN_WS_URL}" \
        --dart-define=RAVAN_LIVEKIT_URL="${RAVAN_LIVEKIT_URL}"

# ---------------------------------------------------------------------------
FROM alpine:3.20
RUN apk add --no-cache rsync
COPY --from=flutter /src/build/web /srv/web
# `web` is the shared volume Caddy serves from.
CMD ["sh", "-c", "rsync -a --delete /srv/web/ /out/ && echo '[web] published' && ls /out | head"]
