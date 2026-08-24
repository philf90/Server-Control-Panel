#!/bin/sh
# Die Conffile-Messung — was ein Paketlauf mit einer von Hand geänderten
# Konfigurationsdatei macht.
#
# **Diese Probe installiert und entfernt ein Paket.** Sie gehört deshalb nicht
# in `tests/apt-messen.sh`, das rein lesend ist und genau deswegen auf einem
# echten Server fahren darf. Diese hier braucht eine Wegwerf-Maschine.
#
# Sie baut sich ihr Prüfpaket selbst, Byte für Byte — aus demselben Grund, aus
# dem `ArchiveDepthTest` seine Archive selbst baut: Ein Paket aus dem Bestand
# hat nicht den Fall, an dem es hier hängt, und ein Prüfling, der seinen eigenen
# Prüfkörper liefert, prüft sich gegen sich selbst.
#
# Gemessen am 24. August 2026 auf Ubuntu 24.04, dpkg 1.22.6. Die Antwort auf
# Frage 3 aus docs/81 §3 hängt daran.
#
# Aufruf:  sh tests/apt-conffile-messen.sh
set -u

PAKET="srvpanel-conffile-probe"
ZIEL="/etc/srvpanel-probe/test.conf"
BAU="${TMPDIR:-/tmp}/conffile-messen.$$"

aufraeumen() {
    dpkg -P "${PAKET}" >/dev/null 2>&1
    rm -rf "${BAU}" /etc/srvpanel-probe
}
trap aufraeumen EXIT INT TERM

titel() { printf '\n=== %s\n' "$1"; }
wert()  { printf '  %-24s %s\n' "$1" "$2"; }

if [ "$(id -u)" -ne 0 ]; then
    echo "Braucht root — die Probe installiert ein Paket." >&2
    exit 2
fi

baue() { # $1 = Fassung, $2 = Inhalt der Conffile
    D="${BAU}/pkg-$1"
    mkdir -p "${D}/DEBIAN" "${D}/etc/srvpanel-probe"
    printf 'Package: %s\nVersion: %s\nArchitecture: all\nMaintainer: Messung <m@example.invalid>\nDescription: Wegwerf-Pruefpaket fuer die Conffile-Messung\n' \
        "${PAKET}" "$1" > "${D}/DEBIAN/control"
    printf '%s\n' "${ZIEL}" > "${D}/DEBIAN/conffiles"
    printf '%s\n' "$2" > "${D}${ZIEL}"
    dpkg-deb --build -Znone "${D}" "${BAU}/probe-$1.deb" >/dev/null 2>&1
}

frisch() { # v1 installieren und die Conffile von Hand ändern
    dpkg -P "${PAKET}" >/dev/null 2>&1
    rm -rf /etc/srvpanel-probe
    dpkg -i "${BAU}/probe-1.deb" >/dev/null 2>&1
    printf 'vom Betreiber von Hand geaendert\n' > "${ZIEL}"
}

mkdir -p "${BAU}"
baue 1 "urspruenglich"
baue 2 "neu vom Paket"

# ---------------------------------------------------------------------------
# M12a — Ohne --force-conf*: zwei Ausgaenge, je nach stdin.
#
# **DEBIAN_FRONTEND=noninteractive beantwortet diese Frage nicht.** Die Fahne
# gilt fuer debconf; die Conffile-Frage stellt dpkg selbst, auf stdin. Was dann
# geschieht, haengt daran, wie stdin steht — und beide Ausgaenge sind schlecht:
#
#   stdin offen und schweigt  -> der Lauf wartet **ohne Zeitgrenze**
#   stdin am Dateiende        -> `end of file on stdin at conffile prompt`, rc=1
#
# Beide lassen das Paket in `iU` zurueck, mit einer `.dpkg-new` daneben: halb
# ausgepackt, nicht eingerichtet. Gemessen werden deshalb **beide**; die erste
# Fassung dieser Probe hat nur einen der Faelle getroffen und den anderen fuer
# den gemessenen gehalten.
# ---------------------------------------------------------------------------
titel "M12a — ohne --force-conf*, stdin am Dateiende"
frisch
timeout 20 env DEBIAN_FRONTEND=noninteractive dpkg -i "${BAU}/probe-2.deb" </dev/null >"${BAU}/a.txt" 2>&1
wert "rc" "$?  (erwartet 1)"
wert "Meldung" "$(grep -oE 'end of file on stdin at conffile prompt' "${BAU}/a.txt" | head -1)"
wert "Paketzustand" "$(dpkg -l "${PAKET}" 2>/dev/null | tail -1 | awk '{print $1}')  (ii = fertig, iU = halb)"
wert "Nachbardateien" "$(ls /etc/srvpanel-probe/ 2>/dev/null | tr '\n' ' ')"

titel "M12a2 — ohne --force-conf*, stdin offen und schweigend"
frisch
timeout 20 env DEBIAN_FRONTEND=noninteractive dpkg -i "${BAU}/probe-2.deb" >"${BAU}/a2.txt" 2>&1
wert "rc" "$?  (erwartet 124 — der Lauf haengt)"
wert "Paketzustand" "$(dpkg -l "${PAKET}" 2>/dev/null | tail -1 | awk '{print $1}')"

# ---------------------------------------------------------------------------
# M12b — Mit --force-confold, Conffile geändert.
#
# Der Bestand bleibt, die neue Fassung landet als `.dpkg-dist` daneben, und die
# Ausgabe sagt es auch:
#
#   ==> Modified (by you or by a script) since installation.
#   ==> Package distributor has shipped an updated version.
#   ==> Using current old file as you requested.
#
# **Der erste Wurf dieser Probe hat „die Ausgabe sagt darüber nichts" gemessen**
# — mit einem grep, das drei Zeilen zu frueh abgeschnitten hat. Der Satz waere
# so in den Plan gegangen und haette das Abnahmekriterium 6 falsch begruendet.
#
# > Eine Messung, die zu frueh abschneidet, meldet nicht „nichts gefunden",
# > sondern „nicht hingesehen" — und die beiden sehen gleich aus.
#
# Der Grund, das Dateisystem trotzdem abzusuchen, bleibt: In einem Lauf ueber
# 146 Pakete gehen drei Zeilen unter. Untergehen ist aber etwas anderes als
# nicht dastehen, und nur das eine ist wahr.
# ---------------------------------------------------------------------------
titel "M12b — mit --force-confold, Conffile geaendert"
frisch
timeout 20 env DEBIAN_FRONTEND=noninteractive dpkg -i --force-confold "${BAU}/probe-2.deb" </dev/null >"${BAU}/b.txt" 2>&1
wert "rc" "$?"
wert "Paketzustand" "$(dpkg -l "${PAKET}" 2>/dev/null | tail -1 | awk '{print $1}')"
wert "Inhalt" "$(cat "${ZIEL}" 2>/dev/null)  (erwartet: die Fassung des Betreibers)"
wert "Nachbardateien" "$(ls /etc/srvpanel-probe/ 2>/dev/null | tr '\n' ' ')"
wert "==>-Zeilen in der Ausgabe" \
    "$(grep -c '==>' "${BAU}/b.txt")  (erwartet 3 — sie stehen da, sie gehen nur unter)"

# ---------------------------------------------------------------------------
# M12c — Die Gegenprobe: unveränderte Conffile, dieselbe Fahne.
#
# Ohne sie bedeutet „`.dpkg-dist` liegt da" nichts: Es könnte auch bei jedem
# Lauf so sein. Hier muss die neue Fassung durchkommen und **keine** Datei
# danebenliegen — sonst misst M12b nicht die Änderung, sondern die Fahne.
# ---------------------------------------------------------------------------
titel "M12c — Gegenprobe: unveraenderte Conffile"
dpkg -P "${PAKET}" >/dev/null 2>&1
rm -rf /etc/srvpanel-probe
dpkg -i "${BAU}/probe-1.deb" >/dev/null 2>&1
timeout 20 env DEBIAN_FRONTEND=noninteractive dpkg -i --force-confold "${BAU}/probe-2.deb" </dev/null >"${BAU}/c.txt" 2>&1
wert "rc" "$?"
wert "Inhalt" "$(cat "${ZIEL}" 2>/dev/null)  (erwartet: neu vom Paket)"
wert "Nachbardateien" "$(ls /etc/srvpanel-probe/ 2>/dev/null | tr '\n' ' ')  (erwartet: nur test.conf)"

printf '\n=== fertig. Prüfpaket und /etc/srvpanel-probe sind entfernt.\n'
