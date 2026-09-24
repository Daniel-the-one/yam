#!/usr/bin/env bash
# =============================================================
# tunnel.sh — Environnement de test Yam en une commande
#
#   ./scripts/tunnel.sh          démarre tout et affiche les URLs (+QR)
#   ./scripts/tunnel.sh stop     arrête les tunnels cloudflared
#   ./scripts/tunnel.sh url      réaffiche les URLs du dernier lancement
#
# Ce que fait « start » (comportement par défaut) :
#   1. Vérifie que l'API (port 8000) et Reverb (port 8080) tournent,
#      les démarre si besoin.
#   2. Redémarre deux tunnels trycloudflare.com (API + WebSocket).
#   3. Attend les URLs publiques, teste qu'elles répondent.
#   4. Affiche les URLs à ouvrir sur le téléphone + QR code si
#      qrencode est installé (apt install qrencode).
# =============================================================
set -euo pipefail

API_PORT=8000
WS_PORT=6001
LOG_DIR="/tmp/yam-tunnels"
URLS_FILE="$LOG_DIR/urls.txt"

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_DIR"

mkdir -p "$LOG_DIR"

c_green='\033[0;32m'; c_yellow='\033[0;33m'; c_red='\033[0;31m'; c_bold='\033[1m'; c_off='\033[0m'
say()  { echo -e "$1"; }
ok()   { say "${c_green}✔${c_off} $1"; }
warn() { say "${c_yellow}⚠${c_off} $1"; }
err()  { say "${c_red}✘${c_off} $1"; }

port_ecoute() { ss -tln "sport = :$1" 2>/dev/null | grep -q LISTEN; }

attend_port() { # $1=port $2=nom $3=tentatives
    local i
    for ((i = 0; i < ${3:-20}; i++)); do
        port_ecoute "$1" && return 0
        sleep 0.5
    done
    err "$2 ne répond pas sur le port $1"
    return 1
}

demarre_services() {
    if port_ecoute "$API_PORT"; then
        ok "API déjà en écoute sur :$API_PORT"
    else
        say "Démarrage de l'API (php artisan serve)…"
        nohup php artisan serve --host=0.0.0.0 --port="$API_PORT" >"$LOG_DIR/api.log" 2>&1 &
        attend_port "$API_PORT" "L'API" 30
        ok "API démarrée sur :$API_PORT"
    fi

    if port_ecoute "$WS_PORT"; then
        ok "Reverb déjà en écoute sur :$WS_PORT"
    else
        say "Démarrage de Reverb…"
        nohup php artisan reverb:start --host=0.0.0.0 --port="$WS_PORT" >"$LOG_DIR/reverb.log" 2>&1 &
        attend_port "$WS_PORT" "Reverb" 30
        ok "Reverb démarré sur :$WS_PORT"
    fi
}

arrete_tunnels() {
    # Ne tue que les vrais processus cloudflared (cmdline qui COMMENCE par
    # « cloudflared tunnel ») — jamais le shell appelant ni un wrapper.
    pkill -f '^cloudflared tunnel' 2>/dev/null || true
    sleep 1
}

extrait_url() { # $1=fichier_log — renvoie toujours 0 (le log peut ne pas
    # contenir encore l'URL ; sous set -e un échec de grep tuerait le script)
    # Exclut api.trycloudflare.com (domaine de l'API Cloudflare) : seuls les
    # vrais tunnels ont un hostname aléatoire avec des tirets.
    grep -oE 'https://[a-z0-9]+(-[a-z0-9]+)+\.trycloudflare\.com' "$1" 2>/dev/null | head -1 || true
}

dns_publique_ok() { # $1=hostname — vrai si un enregistrement A existe chez 1.1.1.1.
    # Indispensable : interroger la box AVANT la propagation DNS met en cache
    # un NXDOMAIN qui rendrait l'URL injoignable des dizaines de minutes.
    nslookup -type=A "$1" 1.1.1.1 2>/dev/null \
        | grep -E '^Address: [0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' >/dev/null
}

attend_dns() { # $1=hostname $2=label
    local i
    for ((i = 0; i < 45; i++)); do
        dns_publique_ok "$1" && { ok "DNS publié pour $2"; return 0; }
        sleep 2
    done
    warn "DNS pas encore publié pour $2 après 90 s"
    return 1
}

attend_enregistrement() { # $1=fichier_log — attend que le edge Cloudflare
    # confirme le rattachement du tunnel (sinon réponses 530 pendant ~30 s).
    local i
    for ((i = 0; i < 30; i++)); do
        grep -q 'Registered tunnel connection' "$1" 2>/dev/null && return 0
        sleep 2
    done
    warn "Tunnel non enregistré au edge après 60 s ($1)"
    return 1
}

demarre_tunnels() {
    arrete_tunnels
    rm -f "$LOG_DIR"/tunnel-api.log "$LOG_DIR"/tunnel-ws.log "$URLS_FILE"

    say "Ouverture des tunnels Cloudflare (≈5–10 s)…"
    nohup cloudflared tunnel --url "http://127.0.0.1:$API_PORT" >"$LOG_DIR/tunnel-api.log" 2>&1 &
    nohup cloudflared tunnel --url "http://127.0.0.1:$WS_PORT" >"$LOG_DIR/tunnel-ws.log" 2>&1 &

    local api_url="" ws_url="" i
    for ((i = 0; i < 40; i++)); do
        api_url="${api_url:-$(extrait_url "$LOG_DIR/tunnel-api.log")}"
        ws_url="${ws_url:-$(extrait_url "$LOG_DIR/tunnel-ws.log")}"
        [[ -n "$api_url" && -n "$ws_url" ]] && break
        sleep 1
    done

    [[ -z "$api_url" ]] && { err "URL du tunnel API introuvable (voir $LOG_DIR/tunnel-api.log)"; exit 1; }
    [[ -z "$ws_url" ]]  && { err "URL du tunnel WS introuvable (voir $LOG_DIR/tunnel-ws.log)"; exit 1; }

    # Attendre l'enregistrement au edge PUIS la publication DNS, avant
    # toute requête HTTP : sinon 530 côté Cloudflare et NXDOMAIN toxique
    # mis en cache par le DNS de la box.
    attend_enregistrement "$LOG_DIR/tunnel-api.log" || true
    attend_enregistrement "$LOG_DIR/tunnel-ws.log" || true
    attend_dns "${api_url#https://}" "l'API" || true
    attend_dns "${ws_url#https://}" "Reverb" || true

    # Test de santé : la page doit répondre 200 à travers le tunnel.
    # (Cloudflare propage une URL neuve pendant quelques secondes : on réessaie.)
    local http_code="" essai
    for essai in 1 2 3 4 5 6 7 8; do
        http_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 "$api_url/app.html" || true)"
        [[ "$http_code" == "200" ]] && break
        sleep 3
    done
    if [[ "$http_code" == "200" ]]; then
        ok "Tunnel API vérifié ($http_code)"
    else
        warn "Tunnel API répond ${http_code:-rien} après $essai essais — vérifie $LOG_DIR/tunnel-api.log"
    fi

    # Si le DNS de la box traîne encore (cache négatif d'une session
    # précédente), on prévient clairement : le téléphone sur le même
    # réseau aurait le même problème.
    if ! getent hosts "${api_url#https://}" >/dev/null 2>&1; then
        warn "Le DNS de ta box ne résout pas encore ce nom (cache négatif)."
        warn "→ Réessaie dans ~5 min, ou passe le DNS de la box en 1.1.1.1."
    fi

    {
        echo "PAGE_PHONE=$api_url/app.html"
        echo "PAGE_COURTE=$api_url/app.html"
        echo "API=$api_url"
        echo "WS=$ws_url"
    } > "$URLS_FILE"
}

affiche_urls() {
    [[ -f "$URLS_FILE" ]] || { err "Aucun lancement connu — lance ./scripts/tunnel.sh d'abord."; exit 1; }
    # shellcheck disable=SC1090
    source "$URLS_FILE"

    say ""
    say "${c_bold}────────────── Yam — URLs publiques ──────────────${c_off}"
    say ""
    say "${c_bold}📱 À ouvrir sur le téléphone (WebRTC + Audio) :${c_off}"
    say "   $PAGE_PHONE"
    say ""
    say "🖥  PC (même réseau) : http://$(hostname -I | awk '{print $1}'):$API_PORT/app.html"
    say "🔌 API    : $API"
    say "🔌 Reverb : $WS"
    say "${c_bold}───────────────────────────────────────────────────${c_off}"

    if command -v qrencode >/dev/null 2>&1; then
        say ""
        say "${c_bold}Scanne ce QR avec l'A51 :${c_off}"
        qrencode -t UTF8 "$PAGE_PHONE"
    else
        warn "QR code indisponible — installe-le avec : sudo apt install qrencode"
    fi
}

case "${1:-start}" in
    start)
        demarre_services
        demarre_tunnels
        affiche_urls
        ok "Tunnels actifs en arrière-plan. Pour tout arrêter : ./scripts/tunnel.sh stop"
        ;;
    url)
        affiche_urls
        ;;
    stop)
        arrete_tunnels
        rm -f "$URLS_FILE"
        ok "Tunnels arrêtés (les serveurs locaux continuent de tourner)."
        ;;
    *)
        say "Usage : $0 [start|stop|url]"
        exit 1
        ;;
esac
