#!/usr/bin/env bash
# =============================================================
# link.sh — Génère UN SEUL lien pour tout Yam (API + WebSocket)
#
#   ./scripts/link.sh          démarre tout et affiche le lien unique (+QR)
#   ./scripts/link.sh stop     arrête le tunnel + le proxy
#   ./scripts/link.sh url      réaffiche le lien du dernier lancement
#
# Principe : un reverse proxy (yam-proxy.cjs) regroupe l'API (port 8000)
# et Reverb WebSocket (port 6001) sur UN SEUL port (8080). Un seul tunnel
# cloudflared pointe vers ce port → un lien unique sert tout.
# =============================================================
set -euo pipefail

API_PORT=8000
WS_PORT=6001
PROXY_PORT=8080
LOG_DIR="/tmp/yam-tunnels"
SINGLE_URL_FILE="$LOG_DIR/single-url.txt"
PROXY_LOG="$LOG_DIR/yam-proxy.log"
TUNNEL_LOG="$LOG_DIR/tunnel-single.log"

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

    # Reverse proxy unique (API + WebSocket sur un seul port)
    if port_ecoute "$PROXY_PORT"; then
        ok "Proxy unique déjà en écoute sur :$PROXY_PORT"
    else
        say "Démarrage du proxy unique (yam-proxy.cjs)…"
        nohup node scripts/yam-proxy.cjs "$PROXY_PORT" >"$PROXY_LOG" 2>&1 &
        attend_port "$PROXY_PORT" "Le proxy" 20
        ok "Proxy unique démarré sur :$PROXY_PORT"
    fi
}

arrete_tunnel() {
    pkill -f '^cloudflared tunnel' 2>/dev/null || true
    sleep 1
}

extrait_url() {
    # Les vrais tunnels ont un hostname aléatoire avec des tirets
    # (ex: political-making-detected-connectivity.trycloudflare.com).
    # On exclut api.trycloudflare.com (domaine de l'API Cloudflare).
    grep -oE 'https://[a-z0-9]+(-[a-z0-9]+)+\.trycloudflare\.com' "$TUNNEL_LOG" 2>/dev/null | head -1 || true
}

dns_publique_ok() {
    nslookup -type=A "$1" 1.1.1.1 2>/dev/null \
        | grep -E '^Address: [0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' >/dev/null
}

attend_dns() {
    local i
    for ((i = 0; i < 45; i++)); do
        dns_publique_ok "$1" && { ok "DNS publié pour $1"; return 0; }
        sleep 2
    done
    warn "DNS pas encore publié pour $1 après 90 s"
    return 1
}

attend_enregistrement() {
    local i
    for ((i = 0; i < 30; i++)); do
        grep -q 'Registered tunnel connection' "$TUNNEL_LOG" 2>/dev/null && return 0
        sleep 2
    done
    warn "Tunnel non enregistré au edge après 60 s"
    return 1
}

demarre_tunnel() {
    arrete_tunnel
    rm -f "$TUNNEL_LOG" "$SINGLE_URL_FILE"

    local url="" i essai
    for essai in 1 2 3; do
        say "Ouverture du tunnel Cloudflare unique (essai $essai/3)…"
        nohup cloudflared tunnel --url "http://127.0.0.1:$PROXY_PORT" >"$TUNNEL_LOG" 2>&1 &

        url=""
        for ((i = 0; i < 40; i++)); do
            url="${url:-$(extrait_url)}"
            [[ -n "$url" ]] && break
            sleep 1
        done

        [[ -n "$url" ]] && break

        # Échec (timeout Cloudflare API) → on relance
        warn "Tunnel non créé (voir $TUNNEL_LOG), relance…"
        arrete_tunnel
        sleep 3
    done

    [[ -z "$url" ]] && { err "URL du tunnel introuvable après 3 essais (voir $TUNNEL_LOG)"; exit 1; }

    attend_enregistrement || true
    attend_dns "${url#https://}" || true

    # Test de santé via le lien unique
    local http_code=""
    for essai in 1 2 3 4 5 6 7 8; do
        http_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 "$url/app.html" || true)"
        [[ "$http_code" == "200" ]] && break
        sleep 3
    done
    if [[ "$http_code" == "200" ]]; then
        ok "Lien unique vérifié ($http_code)"
    else
        warn "Le lien répond ${http_code:-rien} après $essai essais — vérifie $TUNNEL_LOG"
    fi

    echo "$url" > "$SINGLE_URL_FILE"
}

affiche_lien() {
    [[ -f "$SINGLE_URL_FILE" ]] || { err "Aucun lien généré — lance ./scripts/link.sh d'abord."; exit 1; }
    local url
    url="$(cat "$SINGLE_URL_FILE")"

    say ""
    say "${c_bold}────────────── Yam — Lien unique ──────────────${c_off}"
    say ""
    say "${c_bold}🌐 Ouvre ce lien (API + WebSocket + client web) :${c_off}"
    say "   ${c_green}$url/app.html${c_off}"
    say ""
    say "   API       : $url/api/v1"
    say "   WebSocket : $url/app/local (même hôte)"
    say "${c_bold}───────────────────────────────────────────────${c_off}"

    if command -v qrencode >/dev/null 2>&1; then
        say ""
        say "${c_bold}Scanne ce QR :${c_off}"
        qrencode -t UTF8 "$url/app.html"
    else
        warn "QR code indisponible — installe-le avec : sudo apt install qrencode"
    fi
}

case "${1:-start}" in
    start)
        demarre_services
        demarre_tunnel
        affiche_lien
        ok "Lien unique actif en arrière-plan. Pour tout arrêter : ./scripts/link.sh stop"
        ;;
    url)
        affiche_lien
        ;;
    stop)
        arrete_tunnel
        pkill -f 'yam-proxy.cjs' 2>/dev/null || true
        rm -f "$SINGLE_URL_FILE"
        ok "Tunnel + proxy arrêtés (les serveurs locaux continuent de tourner)."
        ;;
    *)
        say "Usage : $0 [start|stop|url]"
        exit 1
        ;;
esac
