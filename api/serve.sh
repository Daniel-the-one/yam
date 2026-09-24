#!/usr/bin/env bash
# serve.sh — Démarre l'API Laravel sur l'IP locale et met à jour APP_URL dans .env
# Usage : ./serve.sh [port] [--kill]
#   port    Port HTTP souhaité (défaut : 8000)
#   --kill  Tue le processus qui occupe le port au lieu de changer de port
set -euo pipefail

# ── Arguments ──────────────────────────────────────────────
PORT=8000
KILL_IF_BUSY=false

for arg in "$@"; do
    case "$arg" in
        --kill)  KILL_IF_BUSY=true ;;
        [0-9]*)  PORT="$arg" ;;
    esac
done

# ── Utilitaires ────────────────────────────────────────────

# Vérifie si un port est occupé
port_in_use() {
    ss -tln "sport = :$1" 2>/dev/null | grep -q LISTEN
}

# Affiche le PID et la commande qui occupe un port
who_uses_port() {
    local addr
    addr=$(ss -tlnp "sport = :$1" 2>/dev/null | grep -oP 'pid=\K[0-9]+' | head -n1)
    if [[ -n "$addr" ]]; then
        ps -p "$addr" -o pid=,comm=,args= 2>/dev/null || true
    fi
}

# Trouve le prochain port libre à partir d'un port donné
find_free_port() {
    local p="$1"
    while port_in_use "$p"; do
        ((p++))
    done
    echo "$p"
}

# Détecte l'IP locale (wlan0 en priorité, sinon eth0, sinon première IPv4 dispo)
detect_ip() {
    local iface ip
    for iface in wlan0 eth0; do
        ip=$(ip -4 addr show "$iface" 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -n1)
        if [[ -n "$ip" ]]; then
            echo "$ip"
            return
        fi
    done
    # Fallback : première IPv4 renvoyée par hostname
    hostname -I | tr ' ' '\n' | grep -E '^[0-9]+\.' | head -n1
}

# ── IP locale ──────────────────────────────────────────────

LOCAL_IP=$(detect_ip)

if [[ -z "$LOCAL_IP" ]]; then
    echo "❌ Impossible de détecter une IP locale (pas de réseau ?)." >&2
    exit 1
fi

echo "🌐 IP locale détectée : $LOCAL_IP"

# ── Gestion du port HTTP ──────────────────────────────────

if port_in_use "$PORT"; then
    echo "⚠️  Le port $PORT est déjà occupé :"
    who_uses_port "$PORT"
    echo ""

    if $KILL_IF_BUSY; then
        # Mode --kill : on tue le processus
        PID=$(ss -tlnp "sport = :$PORT" 2>/dev/null | grep -oP 'pid=\K[0-9]+' | head -n1)
        if [[ -n "$PID" ]]; then
            echo "🔪 Arrêt du processus $PID…"
            kill "$PID" 2>/dev/null
            sleep 1
            # Vérification
            if port_in_use "$PORT"; then
                echo "❌ Le processus $PID n'a pas voulu mourir. Essaie avec kill -9." >&2
                exit 1
            fi
            echo "✅ Port $PORT libéré."
        fi
    else
        # Mode normal : on cherche le prochain port libre
        NEW_PORT=$(find_free_port "$((PORT + 1))")
        echo "🔄 Basculement sur le port $NEW_PORT."
        PORT="$NEW_PORT"
    fi
fi

# ── .env ───────────────────────────────────────────────────

# Met à jour APP_URL dans .env (crée la ligne si absente)
if grep -q '^APP_URL=' .env; then
    sed -i "s|^APP_URL=.*|APP_URL=http://${LOCAL_IP}:${PORT}|" .env
else
    echo "APP_URL=http://${LOCAL_IP}:${PORT}" >> .env
fi

# Met à jour REVERB_HOST pour que les clients se connectent au bon WebSocket
if grep -q '^REVERB_HOST=' .env; then
    sed -i "s|^REVERB_HOST=.*|REVERB_HOST=${LOCAL_IP}|" .env
else
    echo "REVERB_HOST=${LOCAL_IP}" >> .env
fi

# Vide le cache de config pour que le changement soit pris en compte
php artisan config:clear >/dev/null 2>&1 || true

# ── Reverb (WebSocket) ────────────────────────────────────

REVERB_PORT=$(grep -E '^REVERB_PORT=' .env | cut -d= -f2)
REVERB_PORT="${REVERB_PORT:-6001}"

if port_in_use "$REVERB_PORT"; then
    echo "✅ Reverb déjà en écoute sur le port $REVERB_PORT"
else
    echo "🚀 Démarrage de Reverb (WebSocket) sur le port $REVERB_PORT…"
    nohup php artisan reverb:start --host=0.0.0.0 --port="$REVERB_PORT" \
        > /tmp/yam-reverb.log 2>&1 &
    sleep 2
    if port_in_use "$REVERB_PORT"; then
        echo "✅ Reverb démarré sur ws://${LOCAL_IP}:${REVERB_PORT}"
    else
        echo "⚠️  Reverb semble avoir échoué. Vérifie /tmp/yam-reverb.log"
    fi
fi

# ── Démarrage ──────────────────────────────────────────────

echo ""
echo "✅ .env mis à jour : APP_URL=http://${LOCAL_IP}:${PORT} / REVERB_HOST=${LOCAL_IP}"
echo "🚀 Serveur sur http://${LOCAL_IP}:${PORT}  (Ctrl+C pour arrêter)"

php artisan serve --host=0.0.0.0 --port="$PORT"
