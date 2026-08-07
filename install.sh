#!/bin/sh
#
# SrvPanel installieren.
#
#   curl -fsSL --proto '=https' --tlsv1.2 https://repo.cloudsrv24.de/install.sh -o install.sh
#   sudo sh install.sh
#
# Das Skript richtet die Paketquelle ein und installiert daraus. Es lädt kein
# Programm direkt herunter: Was über apt kommt, lässt sich später über apt
# aktualisieren und entfernen — und die Signatur prüft apt, nicht dieses
# Skript.
set -eu

# **Der Kanal, aus dem eine Erstinstallation kommt.**
#
# Hier stand `stable`, und das war falsch, solange es keine stabile Freigabe
# gibt. Unter dists/stable lag auf der Seite noch der Index des Vorgängers:
# 68 Fassungen zweier fremder Pakete, deren Dateien im August aus dem Pool
# entfernt wurden, signiert mit einem anderen Schlüssel als dem, den dieses
# Skript unter Signed-By einträgt. Wer dem dokumentierten Weg folgte, bekam
# beim ersten `apt-get update` ein NO_PUBKEY und danach ein „srvpanel" das es
# nicht gibt — die Erstinstallation war kaputt, und nichts hat es gemeldet.
#
# Der Freigabelauf schreibt nur dists/<kanal> des gerade freigegebenen Kanals
# neu (.github/workflows/release.yml). Ein Kanal, in dem noch nie etwas
# freigegeben wurde, enthält deshalb, was immer dort lag.
#
# Welcher Kanal richtig ist, entscheidet packaging/stable-release: Solange die
# Marke leer ist, weist version-channel.sh jede Fassung ohne Zusatz ab, es
# kann also gar nichts nach stable gelangen. Dass diese Vorgabe und die Marke
# zusammenpassen, prüft PackagingTest — von selbst weiß das Skript es nicht,
# denn es wird allein heruntergeladen und hat das Repository nicht zur Hand.
CHANNEL="${SRVPANEL_CHANNEL:-beta}"
REPO_URL="https://repo.cloudsrv24.de/apt"
KEYRING="/usr/share/keyrings/srvpanel-archive-keyring.gpg"

note() { printf '\033[1m==>\033[0m %s\n' "$1"; }
fail() { printf '\033[31mFehler:\033[0m %s\n' "$1" >&2; exit 1; }

[ "$(id -u)" = "0" ] || fail "Bitte mit sudo ausführen."
[ -d /run/systemd/system ] || fail "SrvPanel braucht systemd."
command -v apt-get >/dev/null || fail "SrvPanel braucht apt (Debian oder Ubuntu)."

if [ -r /etc/os-release ]; then
    . /etc/os-release
    case "${ID}:${VERSION_ID}" in
        debian:12|debian:13|ubuntu:22.04|ubuntu:24.04) ;;
        *) printf 'Warnung: %s wird nicht geprüft und nicht zugesagt.\n' "${PRETTY_NAME:-unbekannt}" >&2 ;;
    esac
fi

note "Belegte Ports prüfen"
for port in 80 443; do
    if command -v ss >/dev/null && ss -ltn "sport = :${port}" 2>/dev/null | grep -q LISTEN; then
        printf 'Hinweis: Port %s ist belegt. SrvPanel verwaltet nginx — ein anderer\n' "${port}" >&2
        printf 'Webserver auf diesem Port wird nicht angefasst und blockiert die Einrichtung.\n' >&2
    fi
done

note "Speicherplatz prüfen"
free_mb="$(df -Pm /opt | awk 'NR==2 {print $4}')"
[ "${free_mb:-0}" -ge 2048 ] || fail "Unter /opt sind ${free_mb} MB frei, gebraucht werden 2048 MB."

note "Kontingente prüfen"
if ! grep -qE '(usrquota|grpquota|prjquota)' /etc/fstab 2>/dev/null; then
    printf 'Hinweis: In /etc/fstab ist kein Dateisystem-Kontingent eingerichtet.\n' >&2
    printf 'Ohne usrquota auf dem Dateisystem von /var/www lässt sich der Speicher\n' >&2
    printf 'je Abonnement nicht begrenzen. Nachzurüsten ist das jederzeit.\n' >&2
fi

note "Paketquelle einrichten"
apt-get update -qq
apt-get install -y -qq curl ca-certificates gnupg >/dev/null

curl -fsSL --proto '=https' --tlsv1.2 "${REPO_URL}/KEY.asc" | gpg --dearmor -o "${KEYRING}"
chmod 0644 "${KEYRING}"

cat > /etc/apt/sources.list.d/srvpanel.sources <<EOF
Types: deb
URIs: ${REPO_URL}
Suites: ${CHANNEL}
Components: main
Architectures: $(dpkg --print-architecture)
Signed-By: ${KEYRING}
EOF

note "PHP-Quelle und PHP 8.4"
# Das Panel braucht PHP 8.4 (Laravel 13). Woher es kommt, entscheidet
# php-source.sh — dasselbe Skript benutzt die CI.
#
# Hier stand einmal `sh …/php-source.sh 2>/dev/null || curl … | sh`, und beide
# Hälften konnten stillschweigend nichts tun: Die Datei lag nicht neben diesem
# Skript (wer den Kopf oben liest, lädt nur install.sh herunter), und auf der
# Seite lag sie auch nicht. `curl -f` endete im 404, gab nichts aus, und das
# leere `sh` beendete sich mit 0. Der Fehler tauchte erst Schritte später als
# apt-Meldung über php8.4-cli auf — an einer Stelle, an der ihn niemand mit
# der PHP-Quelle in Verbindung bringt.
#
# Deshalb: erst in eine Datei holen, dann prüfen, dann ausführen. Eine Pipe
# nach `sh` verbirgt in POSIX-sh den Rückgabewert des Herunterladens, und
# `set -o pipefail` gibt es dort nicht.
php_source="$(dirname "$0")/php-source.sh"

if [ ! -r "${php_source}" ]; then
    php_source="$(mktemp)"
    curl -fsSL --proto '=https' --tlsv1.2 "${REPO_URL%/apt}/php-source.sh" -o "${php_source}" \
        || fail "PHP-Quelle: ${REPO_URL%/apt}/php-source.sh ist nicht erreichbar."
    [ -s "${php_source}" ] || fail "PHP-Quelle: ${REPO_URL%/apt}/php-source.sh kam leer an."
fi

sh "${php_source}" || fail "Die PHP-Quelle liess sich nicht einrichten."

# Die Probe aufs Exempel — als Trockenlauf und nicht über `apt-cache policy`:
# Dessen Ausgabe ist übersetzt („Kandidat" statt „Candidate"), und eine
# Prüfung, die auf einem deutschen Server anders ausgeht als auf einem
# englischen, ist keine.
if ! apt-get install -s -qq php8.4-cli >/dev/null 2>&1; then
    fail "PHP 8.4 ist aus keiner eingerichteten Paketquelle zu bekommen.
  Auf Debian 12, Ubuntu 22.04 und 24.04 kommt es von deb.sury.org; dass es
  fehlt, heisst, dass diese Quelle nicht eingerichtet werden konnte.
  Prüfen: apt-cache policy php8.4-cli und /etc/apt/sources.list.d/php-sury.sources"
fi

apt-get install -y -qq php8.4-cli php8.4-fpm php8.4-mbstring php8.4-xml php8.4-curl php8.4-mysql

note "SrvPanel installieren"
apt-get update -qq
apt-get install -y srvpanel

note "Ersteinrichtung"
srvpanel setup

printf '\n'
note "Fertig."
