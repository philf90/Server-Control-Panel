#!/bin/sh
# Vor dem Entfernen: Dienste anhalten, in der Reihenfolge, in der sie
# voneinander abhängen. Der Agent zuletzt — die anderen brauchen ihn bis zum
# Schluss.
set -eu

for service in srvpanel-metrics srvpanel-worker srvpanel-web srvpanel-agentd; do
    systemctl stop "${service}.service" >/dev/null 2>&1 || true
    systemctl disable "${service}.service" >/dev/null 2>&1 || true
done

# Daten bleiben liegen: /var/lib/srvpanel, /var/log/srvpanel und
# /var/www/vhosts fasst das Paket nicht an. Wer Kundendaten beim Entfernen
# eines Pakets verliert, verliert sie genau einmal.

exit 0
