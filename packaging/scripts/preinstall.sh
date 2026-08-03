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

# Den Stand vor dem Update festhalten — und zwar als Kopie, nicht als Pfad.
#
# **Warum eine Kopie sein muss.** Hier stand nur der Pfad. Das reichte nicht:
# dpkg entfernt beim Update alle Dateien, die im neuen Paket nicht mehr
# vorkommen, und sobald das Verzeichnis die Fassung im Namen trägt, ist das die
# gesamte vorige Fassung. Sie ist weg, bevor postinst überhaupt läuft — der
# Rückweg zeigte auf ein Verzeichnis, das es nicht mehr gab. Belegt in der CI
# auf allen vier Plattformen: „Die vorige Fassung … ist verschwunden."
#
# **`cp -al`, also harte Verweise.** Ein vollständiges Kopieren wäre eine
# Minute Arbeit und 60 MiB, jedes Mal. Harte Verweise kosten Verzeichnis-
# einträge; die Daten liegen ohnehin schon da. Wenn dpkg gleich darauf die
# ursprünglichen Pfade entfernt, halten diese Verweise die Dateien am Leben —
# genau das ist der Zweck.
#
# **Unter /opt und nicht unter /var**, obwohl der Vermerk dort liegt: Harte
# Verweise gehen nur innerhalb eines Dateisystems, und /opt und /var sind auf
# einem sortierten Server oft getrennt. Neben den Fassungen ist der einzige
# Ort, an dem sie sicher funktionieren.
ROLLBACK_DIR=/opt/srvpanel/rollback

previous=""

if [ -L /opt/srvpanel/current ]; then
    previous="$(readlink -f /opt/srvpanel/current || true)"
fi

# Ein Rest von einem abgebrochenen Update ist wertlos, sobald ein neues
# beginnt, und würde sonst beliebig lange Platz halten — mit einer Ausnahme:
# Ist das vorige Update fehlgeschlagen, läuft das Panel *aus dem Rückweg
# heraus*. Ihn dann wegzuräumen hieße, die laufende Fassung unter den Diensten
# zu löschen. Hier stand nur `rm -rf`, und die Reihenfolge machte es
# unsichtbar: Erst wurde entfernt, dann gelesen, wohin `current` zeigt — auf
# ein Verzeichnis, das es in dem Moment nicht mehr gab.
case "${previous}" in
    "${ROLLBACK_DIR}"/*)
        # Die laufende Fassung liegt schon dort und gehört keinem Paket an;
        # dpkg fasst sie nicht an. Eine Kopie der Kopie erübrigt sich.
        mkdir -p /var/lib/srvpanel
        printf '%s\n' "${previous}" > /var/lib/srvpanel/previous-release
        echo "SrvPanel: Rückweg liegt bereits unter ${previous}."
        ;;
    *)
        rm -rf "${ROLLBACK_DIR}"

        if [ -n "${previous}" ] && [ -d "${previous}" ]; then
            mkdir -p /var/lib/srvpanel "${ROLLBACK_DIR}"
            keep="${ROLLBACK_DIR}/$(basename "${previous}")"

            if cp -al "${previous}" "${keep}" 2>/dev/null; then
                printf '%s\n' "${keep}" > /var/lib/srvpanel/previous-release
                echo "SrvPanel: Rückweg bereitgestellt unter ${keep}."
            else
                # Getrennte Dateisysteme oder ein Dateisystem ohne harte
                # Verweise. Dann lieber ehrlich ohne Rückweg als mit einem, der
                # nicht hält.
                rm -rf "${ROLLBACK_DIR}"
                rm -f /var/lib/srvpanel/previous-release
                echo "SrvPanel: Rückweg konnte nicht bereitgestellt werden — das Update läuft ohne." >&2
            fi
        fi
        ;;
esac

exit 0
