#!/bin/sh
# Vor dem Entfernen: Dienste anhalten, in der Reihenfolge, in der sie
# voneinander abhängen. Der Agent zuletzt — die anderen brauchen ihn bis zum
# Schluss.
set -eu

# Der Timer zuerst: Sonst startet er die Messung noch, während die Dienste
# unter ihm weggehen.
for timer in srvpanel-usage srvpanel-tls srvpanel-cron; do
    systemctl stop "${timer}.timer" >/dev/null 2>&1 || true
    systemctl disable "${timer}.timer" >/dev/null 2>&1 || true
done

for service in srvpanel-metrics srvpanel-worker srvpanel-web srvpanel-agentd; do
    systemctl stop "${service}.service" >/dev/null 2>&1 || true
    systemctl disable "${service}.service" >/dev/null 2>&1 || true
done

# Der Rückweg dagegen geht mit.
#
# Er liegt unter /opt/srvpanel/rollback und gehört nicht zum Paket — dpkg
# räumt ihn also nicht weg. Er enthält aber keine Daten, sondern eine Kopie
# des Programms, und ohne Paket ist sie wertlos. Was bliebe, wären 60 MiB, die
# niemand mehr zuordnen kann.
#
# Nur beim vollständigen Entfernen, nicht beim Update: dpkg ruft dieses Skript
# auch dort auf, und dann wird der Rückweg gerade gebraucht.
if [ "${1:-}" = "remove" ] || [ "${1:-}" = "purge" ]; then
    rm -rf /opt/srvpanel/rollback
fi

# Daten bleiben liegen: /var/lib/srvpanel, /var/log/srvpanel und
# /var/www/vhosts fasst das Paket nicht an. Wer Kundendaten beim Entfernen
# eines Pakets verliert, verliert sie genau einmal.

exit 0
