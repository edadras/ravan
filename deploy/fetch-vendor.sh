#!/bin/sh
# Download the MediaPipe Tasks-Vision runtime and the landmarker models into a
# local directory so the deployed app never fetches them from a public CDN.
#
#   fetch-vendor.sh <target-dir>
#
# Re-running is cheap: existing files with the right size are kept.
set -eu

TARGET="${1:-flutter_app/web/vendor}"
TASKS_VERSION="${MEDIAPIPE_TASKS_VERSION:-0.10.22-rc.20250304}"
CDN="https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@${TASKS_VERSION}"
MODEL_BASE="https://storage.googleapis.com/mediapipe-models"

mkdir -p "$TARGET/wasm" "$TARGET/models"

get() { # get <url> <dest>
    dest="$2"
    [ -s "$dest" ] && { echo "  = $(basename "$dest")"; return 0; }
    echo "  + $(basename "$dest")"
    curl -fsSL --retry 3 --retry-delay 2 -o "$dest.part" "$1"
    mv "$dest.part" "$dest"
}

echo "[vendor] MediaPipe tasks-vision ${TASKS_VERSION} -> $TARGET"
get "${CDN}/vision_bundle.mjs"                        "$TARGET/vision_bundle.mjs"
get "${CDN}/wasm/vision_wasm_internal.js"             "$TARGET/wasm/vision_wasm_internal.js"
get "${CDN}/wasm/vision_wasm_internal.wasm"           "$TARGET/wasm/vision_wasm_internal.wasm"
get "${CDN}/wasm/vision_wasm_nosimd_internal.js"      "$TARGET/wasm/vision_wasm_nosimd_internal.js"
get "${CDN}/wasm/vision_wasm_nosimd_internal.wasm"    "$TARGET/wasm/vision_wasm_nosimd_internal.wasm"

echo "[vendor] landmarker models"
get "${MODEL_BASE}/face_landmarker/face_landmarker/float16/1/face_landmarker.task" \
    "$TARGET/models/face_landmarker.task"
# `heavy` is the most accurate pose model MediaPipe ships (~29 MB). The lite
# model is ~5 MB but its landmark error is roughly twice as large; Ravan
# measures small postural changes against a personal baseline, so accuracy
# matters more than the download.
get "${MODEL_BASE}/pose_landmarker/pose_landmarker_heavy/float16/1/pose_landmarker_heavy.task" \
    "$TARGET/models/pose_landmarker_heavy.task"
get "${MODEL_BASE}/pose_landmarker/pose_landmarker_full/float16/1/pose_landmarker_full.task" \
    "$TARGET/models/pose_landmarker_full.task"
get "${MODEL_BASE}/hand_landmarker/hand_landmarker/float16/1/hand_landmarker.task" \
    "$TARGET/models/hand_landmarker.task"

echo "[vendor] done:"
du -sh "$TARGET" 2>/dev/null || true
