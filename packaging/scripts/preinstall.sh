#!/bin/sh
# Vor dem Entpacken: prüfen, ob die Maschine passt.
#
# Lieber hier abbrechen als nach dem Entpacken. Ein Paket, das sich auf einem
# ungeeigneten System halb installiert, hinterlässt Dienste, die niemand
# bestellt hat.
set -eu

if [ ! -d /run/systemd/system ]; then
    echo "CloudSrv braucht systemd. Container ohne systemd werden nicht unterstützt." >&2
    exit 1
fi

if [ -r /etc/os-release ]; then
    . /etc/os-release
    case "${ID}:${VERSION_ID}" in
        debian:12|debian:13|ubuntu:22.04|ubuntu:24.04) ;;
        *)
            echo "CloudSrv ist für Debian 12/13 und Ubuntu 22.04/24.04 gebaut." >&2
            echo "Gefunden: ${PRETTY_NAME:-unbekannt}. Die Installation wird fortgesetzt," >&2
            echo "aber diese Plattform wird nicht geprüft und nicht zugesagt." >&2
            ;;
    esac
fi

exit 0
