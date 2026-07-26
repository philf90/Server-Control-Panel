#!/bin/sh
# Nach dem Entfernen des .deb-Pakets.
#
# Konfiguration und Daten bleiben bei "remove" erhalten und verschwinden erst
# bei "purge" — so verliert niemand seine Einstellungen durch ein missglücktes
# Upgrade.
set -e

rm -f /usr/bin/asylum

if [ -d /run/systemd/system ]; then
  systemctl daemon-reload || true
fi

if [ "${1:-}" = "purge" ]; then
  rm -rf /etc/asylum /var/lib/asylum /var/log/asylum
  if id -u asylum >/dev/null 2>&1; then
    userdel asylum >/dev/null 2>&1 || true
  fi
fi

exit 0
