#!/bin/sh
# Vor dem Entfernen: Dienste anhalten, in der Reihenfolge, in der sie
# voneinander abhängen. Der Agent zuletzt — die anderen brauchen ihn bis zum
# Schluss.
set -eu

for dienst in cloudsrv-metrics cloudsrv-worker cloudsrv-web cloudsrv-agentd; do
    systemctl stop "${dienst}.service" >/dev/null 2>&1 || true
    systemctl disable "${dienst}.service" >/dev/null 2>&1 || true
done

# Daten bleiben liegen: /var/lib/cloudsrv, /var/log/cloudsrv und
# /var/www/vhosts fasst das Paket nicht an. Wer Kundendaten beim Entfernen
# eines Pakets verliert, verliert sie genau einmal.

exit 0
