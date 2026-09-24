#!/usr/bin/env bash
# ==============================================================================
# share.sh — Partage et accès distant pour Yam (Web & Mobile Multi-Réseaux)
# ==============================================================================
# Usage :
#   ./scripts/share.sh [mode]
# Modes disponibles :
#   lan          (par défaut) Démarre les serveurs locaux et affiche l'URL locale
#   pinggy       Ouvre un tunnel SSH public sécurisé instantané (sans inscription)
#   localtunnel  Ouvre un tunnel via localtunnel (npx)
#   ngrok        Ouvre un tunnel via ngrok (port 8000)
#   stop         Arrête tous les tunnels et serveurs de fond
# ==============================================================================
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_DIR"

API_PORT=8000
WS_PORT=6001
MODE="${1:-lan}"

c_green='\033[0;32m'; c_yellow='\033[0;33m'; c_cyan='\033[0;36m'; c_bold='\033[1m'; c_off='\033[0m'
say()  { echo -e "$1"; }
ok()   { say "${c_green}✔${c_off} $1"; }
warn() { say "${c_yellow}⚠${c_off} $1"; }
info() { say "${c_cyan}ℹ${c_off} $1"; }

detect_ip() {
    local iface ip
    for iface in wlan0 eth0; do
        ip=$(ip -4 addr show "$iface" 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -n1)
        if [[ -n "$ip" ]]; then
            echo "$ip"
            return
        fi
    done
    hostname -I | tr ' ' '\n' | grep -E '^[0-9]+\.' | head -n1
}

LOCAL_IP=$(detect_ip)

start_local_servers() {
    # 1. Mise à jour de .env
    if grep -q '^APP_URL=' .env; then
        sed -i "s|^APP_URL=.*|APP_URL=http://${LOCAL_IP}:${API_PORT}|" .env
    fi
    if grep -q '^REVERB_HOST=' .env; then
        sed -i "s|^REVERB_HOST=.*|REVERB_HOST=${LOCAL_IP}|" .env
    fi
    php artisan config:clear >/dev/null 2>&1 || true

    # 2. Reverb
    if ! ss -tln "sport = :$WS_PORT" 2>/dev/null | grep -q LISTEN; then
        say "🚀 Démarrage de Reverb WebSocket sur port $WS_PORT..."
        nohup php artisan reverb:start --host=0.0.0.0 --port="$WS_PORT" >/tmp/yam-reverb.log 2>&1 &
        sleep 1
    fi
    ok "Reverb actif sur port $WS_PORT"

    # 3. API
    if ! ss -tln "sport = :$API_PORT" 2>/dev/null | grep -q LISTEN; then
        say "🚀 Démarrage de l'API Laravel sur port $API_PORT..."
        nohup php artisan serve --host=0.0.0.0 --port="$API_PORT" >/tmp/yam-api.log 2>&1 &
        sleep 1
    fi
    ok "API Laravel active sur port $API_PORT"
}

stop_all() {
    say "Arrêt des tunnels et services en cours..."
    pkill -f "ssh.*pinggy.io" 2>/dev/null || true
    pkill -f "localtunnel" 2>/dev/null || true
    pkill -f "cloudflared" 2>/dev/null || true
    pkill -f "ngrok" 2>/dev/null || true
    ok "Tous les tunnels sont arrêtés."
}

case "$MODE" in
    lan)
        start_local_servers
        say ""
        say "${c_bold}════════════════════════════════════════════════════════════════${c_off}"
        say "${c_bold}  🎉 YAM — ACCÈS EN RÉSEAU LOCAL (Même Wi-Fi / Hotspot)${c_off}"
        say "${c_bold}════════════════════════════════════════════════════════════════${c_off}"
        say ""
        say "📱 ${c_bold}Lien Web à ouvrir sur vos téléphones / PC :${c_off}"
        say "   👉 ${c_green}http://${LOCAL_IP}:${API_PORT}/app.html${c_off}"
        say ""
        say "📦 ${c_bold}Télécharger l'application Android (APK) :${c_off}"
        say "   👉 http://${LOCAL_IP}:${API_PORT}/yam-debug.apk"
        say ""
        say "⚙️  ${c_bold}Dans l'application Mobile (Icône Réglages) :${c_off}"
        say "   Adresse du serveur : ${c_cyan}http://${LOCAL_IP}:${API_PORT}${c_off}"
        say "${c_bold}════════════════════════════════════════════════════════════════${c_off}"
        ;;

    pinggy)
        start_local_servers
        say ""
        say "${c_bold}🚀 Lancement du tunnel public sécurisé Pinggy (compatible 4G / tous réseaux)...${c_off}"
        say "Appuyez sur Ctrl+C pour arrêter le tunnel."
        say ""
        ssh -o StrictHostKeyChecking=no -p 443 -R0:localhost:$API_PORT a.pinggy.io
        ;;

    localtunnel)
        start_local_servers
        say ""
        say "${c_bold}🚀 Lancement du tunnel LocalTunnel...${c_off}"
        npx localtunnel --port $API_PORT
        ;;

    ngrok)
        start_local_servers
        say ""
        say "${c_bold}🚀 Lancement du tunnel Ngrok...${c_off}"
        if command -v ngrok >/dev/null 2>&1; then
            ngrok http $API_PORT
        elif [[ -f ~/.local/bin/ngrok ]]; then
            ~/.local/bin/ngrok http $API_PORT
        else
            warn "Ngrok n'est pas installé dans le PATH."
            say "Pour installer ngrok : curl -s https://ngrok-agent.s3.amazonaws.com/ngrok.asc | sudo tee /etc/apt/trusted.gpg.d/ngrok.asc && echo 'deb https://ngrok-agent.s3.amazonaws.com buster main' | sudo tee /etc/apt/sources.list.d/ngrok.list && sudo apt update && sudo apt install ngrok"
        fi
        ;;

    stop)
        stop_all
        ;;

    *)
        say "Usage : $0 [lan|pinggy|localtunnel|ngrok|stop]"
        exit 1
        ;;
esac
