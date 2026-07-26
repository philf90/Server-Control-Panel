#!/bin/sh
# Vor dem Entfernen des .deb-Pakets.
set -e

if [ -d /run/systemd/system ]; then
  systemctl stop asylumd >/dev/null 2>&1 || true
  systemctl disable asylumd >/dev/null 2>&1 || true
fi

exit 0
