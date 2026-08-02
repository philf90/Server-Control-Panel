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
# Dieses Skript wird von install.sh und von der CI benutzt — an zwei Stellen
# dieselben Schritte zu pflegen, hieße, sie irgendwann auseinanderlaufen zu
# lassen.
set -eu

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

apt-get install -y -qq curl ca-certificates gnupg >/dev/null

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

apt-get update -qq
