#!/usr/bin/env bash
# =========================================================
# deploy-backend.sh — Déploiement FTP du BACKEND Laravel (o2switch)
# =========================================================
#
# Complète deploy.sh, qui ne pousse QUE api/public/ (le docroot
# servi). Les routes, contrôleurs, services et migrations vivent
# au-dessus du docroot : sans ce script, les routes ajoutées en
# local n'existent jamais en prod. C'est exactement ce qui
# expliquait qu'en prod :
#   - /api/demo/v1/wallet répondait (routes anciennes présentes)
#   - /api/demo/v1/auth/login répondait 404 (routes nouvelles absentes)
#   - /api/v1/wallet était classé 401 puis aurait fait 500
#     (route déclarée, contrôleur WalletController non uploadé)
#
# ── Usage ────────────────────────────────────────────────
#   ./deploy-backend.sh --check              liste ce qui SERA poussé
#   ./deploy-backend.sh --dry-run            liste + compare au distant
#   ./deploy-backend.sh --upload [chemins]   pousse (défaut : tout)
#   ./deploy-backend.sh --upload routes/api.php app/Services/
#
# ── Chemins ──────────────────────────────────────────────
# Par défaut : app/ routes/ config/ database/migrations/
# (le reste du backend — vendor/, storage/, .env — n'est jamais
#  touché : voir § Ce qui n'est JAMAIS poussé.)
#
# ⚠️  Ne pushed PAS public/ ici : c'est deploy.sh qui s'en charge,
#     avec un dossier distant différent (/public).
# =========================================================

set -euo pipefail

CONFIG_DIR="$HOME/.yam-deploy"
PROJECT="${YAM_PROJECT:-yam}"

c_green='\033[0;32m'; c_red='\033[0;31m'
c_yellow='\033[1;33m'; c_blue='\033[0;36m'; c_off='\033[0m'
ok()   { echo -e "${c_green}✔${c_off} $1"; }
warn() { echo -e "${c_yellow}⚠${c_off} $1"; }
err()  { echo -e "${c_red}✘${c_off} $1"; }
info() { echo -e "${c_blue}ℹ${c_off} $1"; }

# ── Args ─────────────────────────────────────────────────
MODE="check"
declare -a TARGETS=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    --check)    MODE="check"; shift ;;
    --dry-run)  MODE="dry-run"; shift ;;
    --upload)   MODE="upload"; shift ;;
    -h|--help)  sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    -*)         err "Option inconnue : $1"; exit 1 ;;
    *)          TARGETS+=("$1"); shift ;;
  esac
done

[[ ${#TARGETS[@]} -eq 0 ]] && TARGETS=(app routes config database/migrations)

# ── Config FTP ───────────────────────────────────────────
conf="$CONFIG_DIR/$PROJECT.conf"
[[ -f "$conf" ]] || { err "Config absente : $conf"; exit 1; }
conf_get() { grep "^$1=" "$conf" | cut -d= -f2-; }

HOST=$(conf_get HOST); USER=$(conf_get USER); PASS=$(conf_get PASS)

# Racine du projet Laravel en LOCAL et en DISTANT.
# On la déduit de la config du frontend : celle-ci pointe vers
# api/public → /public, donc la racine est le parent des deux.
LOCAL_FRONT=$(conf_get LOCAL_DIR)          # …/yam/api/public
REMOTE_FRONT=$(conf_get REMOTE_DIR)        # /public

LOCAL_ROOT="$(cd "$LOCAL_FRONT/.." && pwd)"                       # …/yam/api
REMOTE_ROOT="$(dirname "$REMOTE_FRONT")"                          # / ou /www/site
REMOTE_ROOT="${REMOTE_ROOT#/}"                                    # sans slash initial

[[ -d "$LOCAL_ROOT/app" ]] || { err "Racine Laravel introuvable : $LOCAL_ROOT"; exit 1; }

# ── Ce qui n'est JAMAIS poussé ───────────────────────────
exclure() {
  case "$1" in
    # Secrets : jamais par FTP, même en prod
    *.env|*.env.*)            return 0 ;;
    *.local.php)              return 0 ;;   # config propre au serveur
    storage/firebase/*)       return 0 ;;   # clé de service Firebase = SECRET
    # Dépendances et données : hors périmètre de ce script
    vendor/*)                 return 0 ;;
    storage/*)                return 0 ;;
    *.sqlite|*.sqlite3)       return 0 ;;
    # Front : deploy.sh s'en charge, avec un autre dossier distant
    public/*)                 return 0 ;;
    # Artefacts de développement
    .git/*|.git)              return 0 ;;
    *.bak|*.bak-*|*.off)      return 0 ;;
    *.log)                    return 0 ;;
    tests/*)                  return 0 ;;   # jamais utile en prod
    # Doc : sans valeur d'exécution, allège le transfert
    *.md)                     return 0 ;;
  esac
  return 1
}

# ── Collecte des fichiers à pousser ─────────────────────
declare -a A_PUSHER=()
collect() {
  local chemin="$1"
  local src="$LOCAL_ROOT/$chemin"

  if [[ -f "$src" ]]; then
    if exclure "$chemin"; then warn "Ignoré (exclu) : $chemin"; return 0; fi
    A_PUSHER+=("$chemin"); return 0
  fi

  [[ -d "$src" ]] || { warn "Introuvable, ignoré : $chemin"; return 0; }

  local f
  while IFS= read -r f; do
    f="${f#$LOCAL_ROOT/}"
    if exclure "$f"; then continue; fi
    A_PUSHER+=("$f")
  done < <(find "$src" -type f | sort)
}

for t in "${TARGETS[@]}"; do collect "$t"; done

# ── Encodage URL (espaces, caractères spéciaux) ──────────
urlencode_path() {
  python3 -c '
import sys, urllib.parse
print("/".join(urllib.parse.quote(s) for s in sys.argv[1].split("/")))
' "$1"
}

# ── Taille des fichiers, pour estimer le transfert ──────
declare -i TOTAL_OCTETS=0
TOTAL_FICHIERS=${#A_PUSHER[@]}

echo "╔══════════════════════════════════════════════════════╗"
echo "║  Déploiement BACKEND — $PROJECT                         ║"
echo "╚══════════════════════════════════════════════════════╝"
echo "  Racine locale   : $LOCAL_ROOT"
echo "  Racine distante : /$REMOTE_ROOT"
echo "  Mode            : $MODE"
echo ""

if [[ "$MODE" == "check" ]]; then
  for f in "${A_PUSHER[@]}"; do
    TOTAL_OCTETS=$((TOTAL_OCTETS + $(wc -c < "$LOCAL_ROOT/$f")))
  done
  printf "  %d fichiers, %s\n" "$TOTAL_FICHIERS" "$(numfmt --to=iec "$TOTAL_OCTETS")"
  echo ""
  printf '  %s\n' "${A_PUSHER[@]}" | sed 's|^|    |'
  exit 0
fi

# ── --dry-run : compare au distant sans rien écrire ──────
if [[ "$MODE" == "dry-run" ]]; then
  TMP="$(mktemp -d)"
  trap 'rm -rf "$TMP"' EXIT
  declare -i IDENTIQUES=0 DIFFS=0 NOUVEAUX=0
  declare -a A_DIFFS=() A_NOUVEAUX=()

  for f in "${A_PUSHER[@]}"; do
    url="ftp://$HOST/${REMOTE_ROOT:+$REMOTE_ROOT/}$(urlencode_path "$f")"
    if curl -s --fail --max-time 45 -u "$USER:$PASS" -o "$TMP/remote" "$url" 2>/dev/null; then
      if cmp -s "$TMP/remote" "$LOCAL_ROOT/$f"; then
        IDENTIQUES=$((IDENTIQUES + 1))
        printf "  ${c_green}=${c_off} %s\n" "$f"
      else
        DIFFS=$((DIFFS + 1)); A_DIFFS+=("$f")
        printf "  ${c_yellow}~${c_off} %s  (sera écrasé)\n" "$f"
      fi
    else
      NOUVEAUX=$((NOUVEAUX + 1)); A_NOUVEAUX+=("$f")
      printf "  ${c_blue}+${c_off} %s  (nouveau)\n" "$f"
    fi
  done

  echo ""
  echo "──────────────────────────────────────────────────────"
  echo "  identiques : $IDENTIQUES    différents : $DIFFS    nouveaux : $NOUVEAUX"
  if [[ "$DIFFS" -gt 0 ]]; then
    warn "$DIFFS fichier(s) déjà en prod seront ÉCRASÉS."
    info "La sauvegarde deploy/backups/ permet le retour arrière."
  fi
  echo "──────────────────────────────────────────────────────"
  exit 0
fi

# ── --upload ─────────────────────────────────────────────
warn "ÉCRITURE EN PRODUCTION — $REMOTE_ROOT"
echo ""

declare -i OK=0; declare -i KO=0
for f in "${A_PUSHER[@]}"; do
  url="ftp://$HOST/${REMOTE_ROOT:+$REMOTE_ROOT/}$(urlencode_path "$f")"
  if curl -s --fail --max-time 90 -u "$USER:$PASS" \
        --ftp-create-dirs -T "$LOCAL_ROOT/$f" "$url" 2>/dev/null; then
    OK=$((OK + 1))
  else
    KO=$((KO + 1)); err "échec : $f"
  fi
done

echo ""
echo "──────────────────────────────────────────────────────"
echo "  Poussés : $OK / $TOTAL_FICHIERS"
[[ "$KO" -gt 0 ]] && echo "  Échecs  : $KO"
echo "──────────────────────────────────────────────────────"

if [[ "$KO" -gt 0 ]]; then
  err "Déploiement incomplet. NE poursuis pas avec migrate."
  exit 1
fi

cat <<'SUITE'

▸ Étape suivante : les migrations.

  Elles ne se sont pas exécutées. FTP ne lance pas de PHP CLI,
  et il n'y a pas de SSH chez o2switch. Il reste à faire passer
  `php artisan migrate --force` par un script web protégé par
  token (deploy/prod-migration/artisan-migrate.php).

  Tant que ce n'est pas fait, l'état est INCOHÉRENT :
  routes/api.php référence désormais App\Http\Controllers\
  \WalletController, présent sur le disque, mais la table
  `transactions` n'existe pas en base. Un appel authentifié à
  /api/v1/wallet renverra 500 jusqu'à la migration.
SUITE