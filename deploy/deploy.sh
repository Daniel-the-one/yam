#!/usr/bin/env bash
# =========================================================
# deploy.sh — Déploiement FTP générique (o2switch & autres)
# =========================================================
#
# Script réutilisable pour TOUS tes projets. Il lit la config
# dans ~/.yam-deploy/<projet>.conf et pousse le dossier local
# vers le serveur via FTP (curl).
#
# ⚠️  Les identifiants sont stockés dans ~/.yam-deploy/
#   (protégé chmod 600, jamais commité).
#
# ── Usage ────────────────────────────────────────────────
#   ./deploy.sh setup <projet>          → configure un projet (1ère fois)
#   ./deploy.sh <projet>                → déploie TOUT le dossier
#   ./deploy.sh <projet> <fichier>      → déploie un seul fichier
#   ./deploy.sh <projet> <dossier>      → déploie un dossier
#   ./deploy.sh list                    → liste les projets configurés
#
# ── Exemple ──────────────────────────────────────────────
#   ./deploy.sh setup yam
#     → te demande : hôte, utilisateur, mot de passe, dossier local,
#       dossier distant
#   ./deploy.sh yam
#     → pousse tout le dossier local vers le serveur
#   ./deploy.sh yam js/spa-calls.js
#     → pousse un seul fichier
# =========================================================

set -euo pipefail

CONFIG_DIR="$HOME/.yam-deploy"

# ── Vérifie curl ─────────────────────────────────────────
if ! command -v curl >/dev/null 2>&1; then
  echo "❌ curl n'est pas installé." >&2
  exit 1
fi

# ── Affiche l'aide ───────────────────────────────────────
usage() {
  sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'
  exit 0
}

# ── Configure un nouveau projet ──────────────────────────
setup_project() {
  local name="$1"
  mkdir -p "$CONFIG_DIR"

  echo "🛠️  Configuration du projet '$name'"
  echo "----------------------------------"
  read -rp "Hôte FTP (ex: yam.mdkrlabs.dev) : " host
  read -rp "Utilisateur FTP : " user
  read -rsp "Mot de passe FTP : " pass
  echo ""
  read -rp "Dossier LOCAL à déployer (chemin absolu) : " local_dir
  read -rp "Dossier DISTANT sur le serveur (ex: /www/yam.mdkrlabs.dev) : " remote_dir

  # Vérifie que le dossier local existe
  if [ ! -d "$local_dir" ]; then
    echo "❌ Dossier local introuvable : $local_dir" >&2
    exit 1
  fi

  # Écrit la config (protégée)
  cat > "$CONFIG_DIR/$name.conf" <<EOF
HOST=$host
USER=$user
PASS=$pass
LOCAL_DIR=$local_dir
REMOTE_DIR=$remote_dir
EOF
  chmod 600 "$CONFIG_DIR/$name.conf"
  echo ""
  echo "✅ Projet '$name' configuré. Config stockée dans ~/.yam-deploy/$name.conf"
}

# ── Liste les projets configurés ─────────────────────────
list_projects() {
  if [ ! -d "$CONFIG_DIR" ] || [ -z "$(ls -A "$CONFIG_DIR" 2>/dev/null)" ]; then
    echo "Aucun projet configuré. Lance : ./deploy.sh setup <nom>"
    return
  fi
  echo "📁 Projets configurés :"
  for f in "$CONFIG_DIR"/*.conf; do
    name=$(basename "$f" .conf)
    host=$(grep '^HOST=' "$f" | cut -d= -f2)
    local_dir=$(grep '^LOCAL_DIR=' "$f" | cut -d= -f2)
    echo "  • $name → $host ($local_dir)"
  done
}

# ── Lit une clé depuis le fichier de config (robuste aux
#    caractères spéciaux du mot de passe) ────────────────
conf_get() {
  local conf="$1"
  local key="$2"
  grep "^$key=" "$conf" | cut -d= -f2-
}

# ── Encode un chemin pour une URL FTP (espaces, parenthèses, etc.) ──
urlencode_path() {
  # Encode chaque segment séparément pour préserver les "/".
  python3 -c '
import sys, urllib.parse
path = sys.argv[1]
segments = path.split("/")
print("/".join(urllib.parse.quote(s) for s in segments))
' "$1"
}

# ── Déploie un fichier ──────────────────────────────────
deploy_file() {
  local conf="$1"
  local local_file="$2"
  local HOST USER PASS LOCAL_DIR REMOTE_DIR
  HOST=$(conf_get "$conf" HOST)
  USER=$(conf_get "$conf" USER)
  PASS=$(conf_get "$conf" PASS)
  LOCAL_DIR=$(conf_get "$conf" LOCAL_DIR)
  REMOTE_DIR=$(conf_get "$conf" REMOTE_DIR)

  local rel_path="${local_file#$LOCAL_DIR/}"
  local remote_path="$REMOTE_DIR/$rel_path"
  local encoded_path
  encoded_path=$(urlencode_path "$remote_path")

  echo "📤 $rel_path → $HOST$encoded_path"
  curl --fail --silent --show-error \
    -u "$USER:$PASS" \
    --ftp-create-dirs \
    -T "$local_file" \
    "ftp://$HOST$encoded_path"
}

# ── Déploie un dossier récursivement ────────────────────
# Exclut systématiquement les fichiers sensibles / locaux :
#   - *.local.php   → configuration de dev (identifiants locaux)
#   - .env / .env.* → secrets d'environnement
#   - *.bak*        → sauvegardes
#   - *.sqlite      → bases de données locales (jamais en prod)
#   - .git/         → historique
deploy_dir() {
  local conf="$1"
  local local_dir="$2"

  echo "📁 Déploiement du dossier : $local_dir"
  find "$local_dir" -type f \
    ! -name '*.local.php' \
    ! -name '.env' \
    ! -name '.env.*' \
    ! -name '*.bak' \
    ! -name '*.bak-*' \
    ! -name '*.sqlite' \
    ! -path '*/.git/*' \
    | while read -r f; do
        deploy_file "$conf" "$f"
      done
}

# ── Point d'entrée ──────────────────────────────────────
if [ $# -lt 1 ]; then
  usage
fi

case "$1" in
  setup)
    [ $# -lt 2 ] && { echo "Usage: ./deploy.sh setup <nom-projet>"; exit 1; }
    setup_project "$2"
    ;;
  list)
    list_projects
    ;;
  *)
    PROJECT="$1"
    CONF="$CONFIG_DIR/$PROJECT.conf"
    if [ ! -f "$CONF" ]; then
      echo "❌ Projet '$PROJECT' non configuré." >&2
      echo "   Lance d'abord : ./deploy.sh setup $PROJECT" >&2
      exit 1
    fi

    LOCAL_DIR=$(conf_get "$CONF" LOCAL_DIR)
    REMOTE_DIR=$(conf_get "$CONF" REMOTE_DIR)
    HOST=$(conf_get "$CONF" HOST)

    TARGET="${2:-ALL}"
    if [ "$TARGET" = "ALL" ]; then
      echo "🚀 Déploiement complet : $LOCAL_DIR → $HOST$REMOTE_DIR"
      deploy_dir "$CONF" "$LOCAL_DIR"
      echo "✅ Déploiement terminé."
    elif [ -d "$LOCAL_DIR/$TARGET" ]; then
      deploy_dir "$CONF" "$LOCAL_DIR/$TARGET"
      echo "✅ Dossier '$TARGET' déployé."
    elif [ -f "$LOCAL_DIR/$TARGET" ]; then
      deploy_file "$CONF" "$LOCAL_DIR/$TARGET"
      echo "✅ Fichier '$TARGET' déployé."
    else
      echo "❌ Cible introuvable : $TARGET" >&2
      exit 1
    fi
    ;;
esac
