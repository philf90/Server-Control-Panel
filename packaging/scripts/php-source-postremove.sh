#!/bin/sh
#
# Beim vollständigen Entfernen die Quelle wieder wegnehmen — aber nur die
# eigene.
#
# **Nur bei `purge`, nicht bei `remove`.** Ein `apt remove` soll das Paket
# loswerden, nicht die Paketquelle, aus der das installierte PHP stammt. Wer
# sie dabei mitnähme, hinterliesse ein System mit PHP 8.4, für das es keine
# Sicherheitsupdates mehr gibt — und zwar unbemerkt.
#
# **Und nur, wenn wir sie angelegt haben.** Der Merker aus dem postinst sagt
# das. Hat install.sh oder ein Mensch die Quelle vorher eingerichtet, gehört
# sie uns nicht; sie beim Aufräumen mitzunehmen wäre ein Übergriff auf eine
# fremde Einstellung.
set -eu

SOURCE="/etc/apt/sources.list.d/php-sury.sources"
KEYRING="/usr/share/keyrings/php-sury-keyring.gpg"
STAMP="/var/lib/srvpanel/php-source.stamp"

if [ "${1:-}" = "purge" ] && [ -f "${STAMP}" ]; then
    rm -f "${SOURCE}" "${KEYRING}" "${STAMP}"
    rmdir --ignore-fail-on-non-empty "$(dirname "${STAMP}")" 2>/dev/null || true
fi

exit 0
