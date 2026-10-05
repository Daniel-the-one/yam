#!/usr/bin/env bash
# =========================================================
# backup-prod-snapshot.sh — Miroir FTP de la production o2switch
# =========================================================
#
# Télécharge une copie de l'état ACTUEL de la production avant
# tout déploiement. Sans ce filet, un upload raté est irréversible :
# le FTP est le seul canal d'accès, il n'y a pas de SSH ni de
# snapshots serveur à disposition.
#
# ── Usage ────────────────────────────────────────────────
#   ./backup-prod-snapshot.sh                    → horodaté, dans deploy/backups/
#   ./backup-prod-snapshot.sh <dossier-destination>
#
# ── Ce qui est sauvegardé ────────────────────────────────
#   app/  routes/  config/  database/  bootstrap/cache/
#   public/            (PHP + HTML + JS servis)
#   vendor/composer/   (autoload.php + autoload_classmap)
#
# ── Ce qui n'est PAS sauvegardé ──────────────────────────
#   .env                  → secrets, jamais sur disque en clair
#   vendor/ complet       → reinstallable via composer
#   storage/framework/    → cache et sessions, jetables
#   public/_migrate/      → runner jetable d'une migration
#
# Les secrets sont traités par une procédure séparée (§ SECRET)
# qui ne les écrit jamais en clair sur le disque.
# =========================================================

set -euo pipefail

CONFIG_DIR="$HOME/.yam-deploy"
PROJECT="${YAM_PROJECT:-yam}"

c_green='\033[0;32m'; c_red='\033[0;31m'; c_yellow='\033[1;33m'; c_off='\033[0m'
ok()   { echo -e "${c_green}✔${c_off} $1"; }
warn() { echo -e "${c_yellow}⚠${c_off} $1"; }
err()  { echo -e "${c_red}✘${c_off} $1"; }

conf="$CONFIG_DIR/$PROJECT.conf"
[[ -f "$conf" ]] || { err "Config absente : $conf"; exit 1; }

conf_get() { grep "^$1=" "$conf" | cut -d= -f2-; }
HOST=$(conf_get HOST); USER=$(conf_get USER); PASS=$(conf_get PASS)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Destination ───────────────────────────────────────────
if [[ $# -ge 1 ]]; then
  DEST="$1"
else
  DEST="$SCRIPT_DIR/backups/prod-$(date +%Y%m%d-%H%M%S)"
fi
mkdir -p "$DEST"

# chmod 700 : le miroir contiendra des fichiers applicatifs dont
# certains reveal l'existence de tables ou de colonnes.
chmod 700 "$DEST"

echo "╔══════════════════════════════════════════════════════╗"
echo "║  Sauvegarde production — $PROJECT                     ║"
echo "╚══════════════════════════════════════════════════════╝"
echo "  Hôte (masqué) : $(echo "$HOST" | sed 's/\(^....\).*/\1***/')"
echo "  Destination   : $DEST"
echo ""

# ── Compteur ──────────────────────────────────────────────
NB_OK=0; NB_KO=0; NB_SKIP=0

# ── ignore_case : motif de chemin à ne pas récupérer ──────
ignore_case() {
  # ⚠️ L'ORDRE COMPTE : bash évalue les motifs dans l'ordre et
  # s'arrête au premier qui matche. Les exceptions à vendor/
  # doivent donc précéder la règle vendor/*, sinon celle-ci les
  # avale et levendor/composer reste vide (constaté).
  case "$1" in
    *.env|.env.*)                 return 0 ;;   # secrets
    *.local.php)                  return 0 ;;   # config de dev
    *.sqlite-journal|*.sqlite-wal|*.sqlite-shm) return 0 ;;
    _migrate/*|_migrate)          return 0 ;;   # runner jetable
    .git/*|.git)                  return 0 ;;
    storage/framework/*)          return 0 ;;   # cache + sessions
    *.log)                        return 0 ;;

    # Exception AVANT vendor/* : l'autoloader composer.
    # vendor/ est ignoré car reinstallable, mais autoload_psr4.php
    # et autoload_static.php documentent à quoi le serveur résout
    # les classes. Si le PSR-4 de App\ disparaît, une nouvelle
    # classe ne sera pas chargée et le diagnostic post-déploiement
    # devient impossible. ~1 Mo, prix dérisoire.
    vendor/composer)               return 1 ;;
    vendor/composer/*)             return 1 ;;
    vendor/*)                     return 0 ;;   # reinstallable

    # public/ : le docroot contient des répertoires lourds
    # (vendor Bootstrap, assets, icônes, polices). Aucun n'est
    # touché par un déploiement de code applicatif : les récupérer
    # coûterait des dizaines de minutes de FTP pour un retour
    # arrière nul.
    public/vendor|public/assets|public/icons|public/sounds|public/css|public/kondjipro) return 0 ;;
    public/pages/*)               return 0 ;;   # HTML statique non modifié
  esac
  return 1
}

# ── walk_dir : parcours récursif d'un dossier distant ──────
#
# Trois pièges déjà payés sur cet hébergeur, documentés pour que
# le prochain ne les rencontre pas :
#
# 1. `--list-only` renvoie les noms de répertoires SANS slash
#    final : impossible de distinguer un dossier d'un fichier.
#    D'où le listing détaillé, où le 1er caractère de la ligne
#    vaut « d » pour un répertoire, « - » pour un fichier.
#
# 2. Le listing détaillé contient « . » et « .. ». Sans filtre,
#    la récursion boucle indéfiniment sur app/. puis app/./. …
#    (constaté : la sauvegarde ne produisait aucun fichier et
#   .methods lappeait le timeout).
#
# 3. Chaque opération FTP distincte coûte ~3 s ici. Un appel par
#    fichier ferait perdre une dizaine de minutes sur public/.
#    Toutes les URLs d'un même dossier sont donc passées à un
#    seul curl, qui réutilise la connexion de contrôle.
walk_dir() {
  local remote_dir="$1" local_dir="$2"

  # Slash final OBLIGATOIRE : sans lui le protocole FTP interprète
  # « app » comme un nom de FICHIER, le RETR échoue et la sortie
  # est vide — symptôme trompeur de « dossier illisible ».
  local listing
  listing=$(curl -s --max-time 60 -u "$USER:$PASS" \
              "ftp://$HOST/$remote_dir/" 2>/dev/null || true)

  if [[ -z "$listing" ]]; then
    warn "dossier distant vide ou illisible : /$remote_dir"
    return 0
  fi

  mkdir -p "$local_dir"

  local -a files=() subdirs=()
  local line type entry remote f

  while IFS= read -r line; do
    line="${line%$'\r'}"
    [[ -z "$line" ]] && continue

    type="${line:0:1}"

    # Listing FTP au format `ls -l` : perms, liens, propriétaire,
    # groupe, taille, mois, jour, heure, NOM — 9 champs avant le
    # nom, la date occupant 3 champs (« Sep  7 13:18 »).
    # awk plutôt que $NF : un nom contenant un espace serait
    # sinon tronqué.
    entry="$(printf '%s' "$line" \
             | awk '{$1="";$2="";$3="";$4="";$5="";$6="";$7="";$8="";print}' \
             | sed 's/^ *//')"

    # Source de boucle infinie si on l'omet.
    [[ -z "$entry" || "$entry" == "." || "$entry" == ".." ]] && continue

    remote="$remote_dir/$entry"
    if ignore_case "$remote"; then
      NB_SKIP=$((NB_SKIP + 1))
      continue
    fi

    if [[ "$type" == "d" ]]; then
      subdirs+=("$entry")
    else
      files+=("$entry")
    fi
  done <<< "$listing"

  # ── Fichiers du dossier, en un seul appel curl ──────────
  if [[ ${#files[@]} -gt 0 ]]; then
    local -a urls=() args=()
    for f in "${files[@]}"; do
      urls+=("ftp://$HOST/$remote_dir/$f")
      args+=(--output "$local_dir/$f")
    done
    if curl -s --max-time 300 -u "$USER:$PASS" "${args[@]}" "${urls[@]}" 2>/dev/null; then
      NB_OK=$((NB_OK + ${#files[@]}))
    else
      # curl sort non-zéro dès qu'un transfert échoue : on
      # recompte fichier par fichier plutôt que de tout perdre.
      for f in "${files[@]}"; do
        if [[ -s "$local_dir/$f" ]]; then
          NB_OK=$((NB_OK + 1))
        else
          warn "  non récupéré : $remote_dir/$f"
          NB_KO=$((NB_KO + 1))
        fi
      done
    fi
  fi

  # ── Sous-dossiers ───────────────────────────────────────
  for entry in "${subdirs[@]+"${subdirs[@]}"}"; do
    walk_dir "$remote_dir/$entry" "$local_dir/$entry"
  done
}

# ── § SECRET ───────────────────────────────────────────────
# Les secrets (.env) sont le seul élément qu'on ne peut pas se
# permettre de perdre. On ne les écrit JAMAIS en clair : on
# relève uniquement la liste des NOMS de clés, ce qui suffit à
# prouver que rien n'a bougé sans exposer la moindre valeur.
backup_env_keys() {
  local out="$DEST/env-keys-prod.txt"
  curl -s --max-time 60 -u "$USER:$PASS" "ftp://$HOST/.env" 2>/dev/null \
    | sed 's/=.*//' \
    | grep -v '^[[:space:]]*$' \
    | sort > "$out" || true

  local n
  n=$(wc -l < "$out" 2>/dev/null || echo 0)
  if [[ "$n" -gt 0 ]]; then
    ok "NOMS des clés .env sauvegardés (${n} clés, AUCUNE valeur) → env-keys-prod.txt"
  else
    warn "clé .env illisible : la comparaison de non-régression est impossible"
  fi
}

# ── Exécution ─────────────────────────────────────────────
echo "▸ Miroir des répertoires applicatifs…"
# Chemins SANS slash initial : l'URL FTP en fournit un, et les
# motifs de ignore_case sont écrits sans slash (« public/vendor »).
for d in app routes config database bootstrap/cache; do
  echo "  /$d"
  walk_dir "$d" "$DEST/${d//\//_}"
done

# vendor : uniquement ce qui est nécessaire au chargement
echo "  /vendor/composer (autoload seulement)"
walk_dir "vendor/composer" "$DEST/vendor_composer"

echo ""
echo "▸ Miroir de /public (le docroot servi)…"
walk_dir "public" "$DEST/public"

echo ""
echo "▸ Clés du .env (noms seulement)…"
backup_env_keys

# ── Résumé ────────────────────────────────────────────────
TOTAL=$((NB_OK + NB_KO))
echo ""
echo "──────────────────────────────────────────────────────"
echo "  Fichiers récupérés : $NB_OK / $TOTAL"
echo "  Ignorés (exclus)   : $NB_SKIP"
if [[ "$NB_KO" -gt 0 ]]; then
  warn "$NB_KO fichier(s) non récupéré(s) — voir les messages ci-dessus"
fi
echo "  Destination        : $DEST"
echo "  Taille             : $(du -sh "$DEST" 2>/dev/null | cut -f1)"
echo "──────────────────────────────────────────────────────"

[[ "$NB_KO" -gt 0 ]] && exit 1
ok "Sauvegarde complète."