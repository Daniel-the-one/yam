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
#
# Il génère en plus public/demo.html : l'UNIQUE page d'entrée de
# la démo. Elle sert la même SPA mais force le mode démo avant le
# chargement de spa-core.js, pour qu'un testeur n'ait rien à
# configurer. Voir api/docs/API_DEMO.md.
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
  "js/spa-devices.js:js/spa-devices.js"
  "js/spa-core.js:js/spa-core.js"
  "js/spa-network.js:js/spa-network.js"
  "js/spa-push.js:js/spa-push.js"
  "js/spa-wallet.js:js/spa-wallet.js"
)

# ── Construit public/demo.html ──────────────────────────────────
# Copie de index.html avec un garde-fou injecté AVANT spa-core.js :
# sans cela le client démarrerait en mode réel et chercherait
# /api/v1, donc le testeur verrait l'écran de connexion plutôt que
# la démo. Injecter ici plutôt que dans web/index.html garde la
# source web unique (app.html reste le client réel).
genere_demo_html() {
  local src_html="$SRC/index.html"
  local out="$DST/demo.html"

  [[ -f "$src_html" ]] || { echo "⚠ $src_html absent — demo.html non généré"; return 0; }

  python3 - "$src_html" "$out" <<'PY'
import sys, io

src, out = sys.argv[1], sys.argv[2]
html = io.open(src, encoding="utf-8").read()

# Doit être inséré avant spa-core.js : c'est ce fichier qui lit
# yam_demo_mode au chargement.
ancre = '<script src="js/spa-core.js"></script>'
garde = """<script>
  /* Généré par api/scripts/sync-web.sh — ne pas éditer à la main.
   * Force le mode démo AVANT spa-core.js, qui lit ce drapeau au
   * chargement. Sans ce script, la SPA viserait /api/v1 (mode réel)
   * et afficherait l'écran de connexion au lieu de la démo. */
  try {
    localStorage.setItem("yam_demo_mode", "1");
    // Ouvre la démo même si l'onglet avait mémorisé l'adresse d'un
    // ancien tunnel ou d'un ancien LAN : on repart de l'URL courante.
    localStorage.removeItem("yam_server_url");
    localStorage.removeItem("auth_token");
    localStorage.removeItem("user_id");
  } catch (e) { /* mode privé : la SPA tombera sur l'API réelle */ }
</script>
"""

if ancre not in html:
    sys.exit("ancre spa-core.js introuvable dans " + src)

io.open(out, "w", encoding="utf-8").write(html.replace(ancre, garde + ancre, 1))
PY

  ok "Généré : demo.html (mode démo forcé)"
}

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
else
  genere_demo_html
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