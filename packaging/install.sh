#!/bin/sh
#
# CloudSrv installieren.
#
#   curl -fsSL --proto '=https' --tlsv1.2 https://repo.cloudsrv24.de/install.sh -o install.sh
#   sudo sh install.sh
#
# Das Skript richtet die Paketquelle ein und installiert daraus. Es lädt kein
# Programm direkt herunter: Was über apt kommt, lässt sich später über apt
# aktualisieren und entfernen — und die Signatur prüft apt, nicht dieses
# Skript.
set -eu

CHANNEL="${CLOUDSRV_CHANNEL:-stable}"
REPO_URL="https://repo.cloudsrv24.de/apt"
KEYRING="/usr/share/keyrings/cloudsrv-archive-keyring.gpg"

note() { printf '\033[1m==>\033[0m %s\n' "$1"; }
fail() { printf '\033[31mFehler:\033[0m %s\n' "$1" >&2; exit 1; }

[ "$(id -u)" = "0" ] || fail "Bitte mit sudo ausführen."
[ -d /run/systemd/system ] || fail "CloudSrv braucht systemd."
command -v apt-get >/dev/null || fail "CloudSrv braucht apt (Debian oder Ubuntu)."

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
        printf 'Hinweis: Port %s ist belegt. CloudSrv verwaltet nginx — ein anderer\n' "${port}" >&2
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

cat > /etc/apt/sources.list.d/cloudsrv.sources <<EOF
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
sh "$(dirname "$0")/php-source.sh" 2>/dev/null || curl -fsSL --proto '=https' --tlsv1.2 \
    "${REPO_URL%/apt}/php-source.sh" | sh
apt-get install -y -qq php8.4-cli php8.4-fpm php8.4-mbstring php8.4-xml php8.4-curl php8.4-mysql

note "CloudSrv installieren"
apt-get update -qq
apt-get install -y cloudsrv-panel

note "Ersteinrichtung"
cloudsrv setup

printf '\n'
note "Fertig."
