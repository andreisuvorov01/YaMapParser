#!/usr/bin/env bash
set -e

PARS_DIR="$(cd "$(dirname "$0")" && pwd)"
VENV="$PARS_DIR/.venv"

RED='\033[0;31m'; GREEN='\033[0;32m'; NC='\033[0m'
info()  { echo -e "${GREEN}[+]${NC} $*"; }
error() { echo -e "${RED}[✗]${NC} $*"; exit 1; }

[ -f "$PARS_DIR/.env" ] || error ".env не найден в $PARS_DIR"

# ── Node.js 22 ──────────────────────────────────────────────────────────────
NODE_BIN=$(command -v node || true)
NODE_OK=false
if [ -n "$NODE_BIN" ]; then
  NODE_VER=$("$NODE_BIN" --version 2>/dev/null | sed 's/v//' | cut -d. -f1)
  [ "${NODE_VER:-0}" -ge 22 ] 2>/dev/null && NODE_OK=true
fi

if [ "$NODE_OK" = false ]; then
  info "Устанавливаю Node.js 22..."
  apt-get install -y curl &>/dev/null
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash - &>/dev/null
  apt-get install -y nodejs &>/dev/null
  hash -r
  NODE_BIN=$(command -v node) || error "node не найден после установки"
fi
info "Node.js $("$NODE_BIN" --version)"

# ── Python venv ─────────────────────────────────────────────────────────────
info "Устанавливаю python3-venv..."
apt-get install -y python3-venv python3-pip
python3 -m venv "$VENV" || error "Не удалось создать venv. Проверь вывод выше."
info "venv создан"

info "Устанавливаю Python зависимости..."
"$VENV/bin/pip" install -q --upgrade pip
"$VENV/bin/pip" install -q -r "$PARS_DIR/requirements.txt"

if grep -q "^playwright" "$PARS_DIR/requirements.txt" 2>/dev/null; then
  info "Устанавливаю Playwright Chromium..."
  "$VENV/bin/playwright" install chromium
  "$VENV/bin/playwright" install-deps chromium
fi

# ── npm ─────────────────────────────────────────────────────────────────────
info "Устанавливаю npm зависимости..."
cd "$PARS_DIR" && "$NODE_BIN" "$(command -v npm)" install --silent

# ── tmux ────────────────────────────────────────────────────────────────────
command -v tmux &>/dev/null || { info "Устанавливаю tmux..."; apt-get install -y tmux; }

tmux kill-session -t pars-proxy 2>/dev/null || true
tmux kill-session -t pars-sync  2>/dev/null || true

# ── прокси ──────────────────────────────────────────────────────────────────
info "Запускаю proxy_server.py..."
tmux new-session -d -s pars-proxy -c "$PARS_DIR" \
  "source $VENV/bin/activate && python3 proxy_server.py; read -p 'Нажми Enter...'"

for i in $(seq 1 15); do
  curl -sf http://localhost:8765/health &>/dev/null && break
  sleep 1
  [ "$i" -eq 15 ] && error "Прокси не ответил. Проверь: tmux attach -t pars-proxy"
done
info "Прокси готов"

# ── синк ────────────────────────────────────────────────────────────────────
info "Запускаю sync-gdebenz-comments.mjs..."
tmux new-session -d -s pars-sync -c "$PARS_DIR" \
  "$NODE_BIN sync-gdebenz-comments.mjs $*; echo 'Синк завершён.'; read -p 'Нажми Enter...'"

echo ""
info "Готово."
echo "  tmux attach -t pars-proxy   # логи прокси"
echo "  tmux attach -t pars-sync    # прогресс синка"
echo "  tmux kill-session -t pars-proxy && tmux kill-session -t pars-sync"
