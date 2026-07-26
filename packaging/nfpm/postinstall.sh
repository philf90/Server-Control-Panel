#!/bin/sh
# Nach der Installation des .deb-Pakets.
set -e

RUN_USER="asylum"
CONFIG_DIR="/etc/asylum"
CONFIG_FILE="${CONFIG_DIR}/config.yaml"
DATA_DIR="/var/lib/asylum"
LOG_DIR="/var/log/asylum"
BINARY="/usr/lib/asylum/asylumd"

if ! id -u "$RUN_USER" >/dev/null 2>&1; then
  useradd --system --no-create-home --shell /usr/sbin/nologin "$RUN_USER"
fi

install -d -m 0750 -o root -g "$RUN_USER" "$CONFIG_DIR" "${CONFIG_DIR}/tls" "$DATA_DIR" "$LOG_DIR"

# Bestehende Konfiguration wird nie überschrieben.
if [ ! -f "$CONFIG_FILE" ]; then
  cat > "$CONFIG_FILE" <<'YAML'
# Project Asylum — Konfiguration
server:
  bind: "0.0.0.0"
  port: 8443
  tls:
    cert: /etc/asylum/tls/server.crt
    key: /etc/asylum/tls/server.key

paths:
  data: /var/lib/asylum
  log: /var/log/asylum

log:
  level: info
  format: text

updates:
  channel: stable
  check: daily
  auto_apply: security
  window: "03:00-05:00"
  base_url: https://repo.cloudsrv24.de
YAML
  chown root:"$RUN_USER" "$CONFIG_FILE"
  chmod 0640 "$CONFIG_FILE"
fi

# /usr/bin steht im PATH vor /usr/games, wo das gleichnamige Debian-Spiel
# liegt. Der Befehl asylum meint deshalb dieses Panel.
ln -sf "$BINARY" /usr/bin/asylum

"$BINARY" migrate --config "$CONFIG_FILE" >/dev/null

if [ -d /run/systemd/system ]; then
  systemctl daemon-reload || true
  systemctl enable asylumd >/dev/null 2>&1 || true
  # restart statt start: bei einem Upgrade läuft der Dienst bereits.
  systemctl restart asylumd || true
fi

exit 0
