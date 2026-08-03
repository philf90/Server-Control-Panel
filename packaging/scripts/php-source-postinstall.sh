#!/bin/sh
#
# Richtet die PHP-Quelle ein — mehr tut dieses Paket nicht.
#
# **Warum es das Panel nicht selbst kann.** apt löst die Abhängigkeiten eines
# Pakets auf, *bevor* eines seiner Skripte läuft. Ein `postinst` von
# `srvpanel`, das hier dasselbe täte, käme zu spät: Die Installation ist dann
# schon an „php8.4-cli ist aber nicht installierbar" gescheitert. Deshalb ein
# eigenes Paket, das man vorher einspielt.
set -eu

SOURCE="/etc/apt/sources.list.d/php-sury.sources"
STAMP="/var/lib/srvpanel/php-source.stamp"

# Wem gehört die Quelldatei? Diese Frage entscheidet, ob wir sie beim Entfernen
# wieder wegnehmen dürfen. Hat sie schon vorher jemand angelegt — install.sh
# etwa, oder der Administrator von Hand —, dann ist sie nicht unsere, und ein
# `apt purge` dieses Pakets darf sie nicht mitnehmen.
existed_before="no"

# Als if und nicht als `[ … ] && …`: Unter `set -e` ist der Rückgabewert einer
# UND-Liste der des fehlgeschlagenen Tests, und das Skript bräche hier ab,
# sobald die Datei fehlt — also genau im Normalfall.
if [ -f "${SOURCE}" ]; then
    existed_before="yes"
fi

SRVPANEL_SKIP_APT_UPDATE=1 sh /usr/share/srvpanel/php-source.sh

if [ "${existed_before}" = "no" ] && [ -f "${SOURCE}" ]; then
    mkdir -p "$(dirname "${STAMP}")"
    : > "${STAMP}"
fi

# Ohne diesen Hinweis wäre das Paket eine Wohltat, die niemand bemerkt: Der
# laufende apt-Vorgang kann die neue Quelle nicht mehr verwenden, seine
# Auflösung ist längst gelaufen.
if [ -f "${SOURCE}" ]; then
    cat <<'TEXT'

  Die PHP-Quelle ist eingerichtet. Weiter mit:

      apt update && apt install srvpanel

TEXT
else
    # Debian 13 und neuer brauchen sie nicht — dort ist PHP 8.4 im Bestand.
    cat <<'TEXT'

  Diese Plattform liefert PHP 8.4 aus eigenen Quellen; es war nichts
  einzurichten. Weiter mit:

      apt install srvpanel

TEXT
fi

exit 0
