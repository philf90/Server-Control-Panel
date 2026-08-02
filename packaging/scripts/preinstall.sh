#!/bin/sh
# Vor dem Entpacken: prüfen, ob die Maschine passt — und festhalten, wohin
# zurückgegangen wird, wenn die neue Fassung nicht antwortet.
#
# Lieber hier abbrechen als nach dem Entpacken. Ein Paket, das sich auf einem
# ungeeigneten System halb installiert, hinterlässt Dienste, die niemand
# bestellt hat.
set -eu

if [ ! -d /run/systemd/system ]; then
    echo "SrvPanel braucht systemd. Container ohne systemd werden nicht unterstützt." >&2
    exit 1
fi

if [ -r /etc/os-release ]; then
    . /etc/os-release
    case "${ID}:${VERSION_ID}" in
        debian:12|debian:13|ubuntu:22.04|ubuntu:24.04) ;;
        *)
            echo "SrvPanel ist für Debian 12/13 und Ubuntu 22.04/24.04 gebaut." >&2
            echo "Gefunden: ${PRETTY_NAME:-unbekannt}. Die Installation wird fortgesetzt," >&2
            echo "aber diese Plattform wird nicht geprüft und nicht zugesagt." >&2
            ;;
    esac
fi

# Den Stand vor dem Update festhalten. Danach ist er nicht mehr zu ermitteln:
# dpkg legt den Symlink beim Entpacken um, und der alte Pfad wäre nur noch zu
# raten. Geraten wird beim Zurücknehmen einer Fassung nicht.
if [ -L /opt/srvpanel/current ]; then
    previous="$(readlink -f /opt/srvpanel/current || true)"

    if [ -n "${previous}" ] && [ -d "${previous}" ]; then
        mkdir -p /var/lib/srvpanel
        printf '%s\n' "${previous}" > /var/lib/srvpanel/previous-release
    fi
fi

exit 0
