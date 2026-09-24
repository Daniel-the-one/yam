#!/usr/bin/env bash
# =============================================================
# sync-web.sh — Synchronise le client web vers le dossier servi
#
# Le client web vit dans yam/web/ (source) mais est servi par
# Laravel depuis api/public/. Ce script copie les fichiers
# modifiés vers api/public/ pour que le serveur les serve.
#
#   ./scripts/sync-web.sh          copie TOUS les fichiers web
#   ./scripts/sync-web.sh spa-calls.js   copie un fichier précis
# =============================================================
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO_DIR/../web"       # yam/web
DST="$REPO_DIR/public"       # api/public

# Résout le chemin source réel (le repo api est dans yam/api, le web dans yam/web)
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../web" && pwd)"

c_green='\033[0;32m'; c_off='\033[0m'
ok() { echo -e "${c_green}✔${c_off} $1"; }

[[ -d "$SRC" ]] || { echo "✘ Source introuvable : $SRC"; exit 1; }
[[ -d "$DST" ]] || { echo "✘ Destination introuvable : $DST"; exit 1; }

# Fichiers à synchroniser (source → destination)
FILES=(
  "index.html:app.html"
  "js/spa-auth.js:js/spa-auth.js"
  "js/spa-bootstrap.js:js/spa-bootstrap.js"
  "js/spa-calls.js:js/spa-calls.js"
  "js/spa-contacts.js:js/spa-contacts.js"
  "js/spa-core.js:js/spa-core.js"
  "js/spa-network.js:js/spa-network.js"
  "js/spa-push.js:js/spa-push.js"
)

# Si un fichier précis est demandé, on ne synchronise que lui
if [[ $# -gt 0 ]]; then
  requested="$1"
  matched=()
  for entry in "${FILES[@]}"; do
    src="${entry%%:*}"; dst="${entry##*:}"
    if [[ "$(basename "$src")" == "$requested" || "$src" == "$requested" ]]; then
      matched+=("$entry")
    fi
  done
  if [[ ${#matched[@]} -eq 0 ]]; then
    echo "✘ Fichier inconnu : $requested"
    echo "   Fichiers disponibles :"
    for entry in "${FILES[@]}"; do echo "   - ${entry%%:*}"; done
    exit 1
  fi
  FILES=("${matched[@]}")
fi

for entry in "${FILES[@]}"; do
  src="$SRC/${entry%%:*}"; dst="$DST/${entry##*:}"
  if [[ -f "$src" ]]; then
    cp "$src" "$dst"
    ok "Synchronisé : ${entry%%:*} → ${entry##*:}"
  else
    echo "⚠ Source manquante : $src (ignoré)"
  fi
done

echo ""
ok "Client web synchronisé vers api/public/ (recharge le navigateur avec Ctrl+Shift+R)."
