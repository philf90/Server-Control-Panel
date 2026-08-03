#!/bin/sh
# Die PHP-Quelle einrichten.
#
# Das Panel braucht PHP 8.4: Laravel 13 und Symfony 8 verlangen es. Von den
# vier Zielplattformen liefert das nur Debian 13 aus eigenen Quellen — Debian
# 12 bringt 8.2, Ubuntu 24.04 bringt 8.3, Ubuntu 22.04 bringt 8.1. Für die
# übrigen drei kommt PHP aus deb.sury.org.
#
# Warum das Panel die Pakete nicht selbst spiegelt, steht in §4.3 des Plans.
#
# Dieses Skript wird von install.sh, von der CI und vom Paket
# srvpanel-php-source benutzt — an drei Stellen dieselben Schritte zu pflegen,
# hieße, sie irgendwann auseinanderlaufen zu lassen.
#
# **Aus einem postinst heraus gelten zwei Einschränkungen**, und beide stehen
# unten als Bedingung im Code:
#
# 1. Kein `apt-get install`. Während ein Paket eingerichtet wird, hält dpkg
#    seine Sperre; ein zweiter Aufruf liefe hinein und scheiterte. Deshalb
#    werden curl, gnupg und ca-certificates nur nachinstalliert, wenn sie
#    fehlen — und das Paket führt sie ohnehin als Abhängigkeit, damit sie
#    vorher da sind.
# 2. Kein `apt-get update`. Es hilft dem laufenden Vorgang nicht: apt hat
#    seine Abhängigkeiten längst aufgelöst. Der Aufrufer setzt deshalb
#    SRVPANEL_SKIP_APT_UPDATE=1 und sagt dem Menschen, was als Nächstes kommt.
set -eu

SKIP_UPDATE="${SRVPANEL_SKIP_APT_UPDATE:-0}"

KEYRING="/usr/share/keyrings/php-sury-keyring.gpg"

if [ -f /etc/apt/sources.list.d/php-sury.sources ]; then
    exit 0
fi

# shellcheck source=/dev/null
. /etc/os-release

# Debian 13 und neuer haben PHP 8.4 im eigenen Bestand.
if [ "${ID}" = "debian" ] && [ "${VERSION_ID%%.*}" -ge 13 ]; then
    exit 0
fi

missing=""
for tool in curl gpg; do
    command -v "${tool}" >/dev/null || missing="${missing} ${tool}"
done

if [ -n "${missing}" ]; then
    apt-get install -y -qq curl ca-certificates gnupg >/dev/null
fi

case "${ID}" in
    debian) base="https://packages.sury.org/php/" ;;
    ubuntu) base="https://ppa.launchpadcontent.net/ondrej/php/ubuntu/" ;;
    *)      echo "Unbekannte Distribution ${ID} — PHP-Quelle wird nicht eingerichtet." >&2; exit 0 ;;
esac

if [ "${ID}" = "debian" ]; then
    curl -fsSL --proto '=https' https://packages.sury.org/php/apt.gpg -o "${KEYRING}"
else
    curl -fsSL --proto '=https' "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x4f4ea0aae5267a6c" \
        | gpg --dearmor -o "${KEYRING}"
fi

chmod 0644 "${KEYRING}"

cat > /etc/apt/sources.list.d/php-sury.sources <<EOF
Types: deb
URIs: ${base}
Suites: ${VERSION_CODENAME}
Components: main
Signed-By: ${KEYRING}
EOF

[ "${SKIP_UPDATE}" = "1" ] || apt-get update -qq
