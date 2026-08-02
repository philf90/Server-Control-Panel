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

KANAL="${CLOUDSRV_KANAL:-stable}"
QUELLE="https://repo.cloudsrv24.de/apt"
KEYRING="/usr/share/keyrings/cloudsrv-archive-keyring.gpg"

melde() { printf '\033[1m==>\033[0m %s\n' "$1"; }
fehler() { printf '\033[31mFehler:\033[0m %s\n' "$1" >&2; exit 1; }

[ "$(id -u)" = "0" ] || fehler "Bitte mit sudo ausführen."
[ -d /run/systemd/system ] || fehler "CloudSrv braucht systemd."
command -v apt-get >/dev/null || fehler "CloudSrv braucht apt (Debian oder Ubuntu)."

if [ -r /etc/os-release ]; then
    . /etc/os-release
    case "${ID}:${VERSION_ID}" in
        debian:12|debian:13|ubuntu:22.04|ubuntu:24.04) ;;
        *) printf 'Warnung: %s wird nicht geprüft und nicht zugesagt.\n' "${PRETTY_NAME:-unbekannt}" >&2 ;;
    esac
fi

melde "Belegte Ports prüfen"
for port in 80 443; do
    if command -v ss >/dev/null && ss -ltn "sport = :${port}" 2>/dev/null | grep -q LISTEN; then
        printf 'Hinweis: Port %s ist belegt. CloudSrv verwaltet nginx — ein anderer\n' "${port}" >&2
        printf 'Webserver auf diesem Port wird nicht angefasst und blockiert die Einrichtung.\n' >&2
    fi
done

melde "Speicherplatz prüfen"
frei_mb="$(df -Pm /opt | awk 'NR==2 {print $4}')"
[ "${frei_mb:-0}" -ge 2048 ] || fehler "Unter /opt sind ${frei_mb} MB frei, gebraucht werden 2048 MB."

melde "Kontingente prüfen"
if ! grep -qE '(usrquota|grpquota|prjquota)' /etc/fstab 2>/dev/null; then
    printf 'Hinweis: In /etc/fstab ist kein Dateisystem-Kontingent eingerichtet.\n' >&2
    printf 'Ohne usrquota auf dem Dateisystem von /var/www lässt sich der Speicher\n' >&2
    printf 'je Abonnement nicht begrenzen. Nachzurüsten ist das jederzeit.\n' >&2
fi

melde "Paketquelle einrichten"
apt-get update -qq
apt-get install -y -qq curl ca-certificates gnupg >/dev/null

curl -fsSL --proto '=https' --tlsv1.2 "${QUELLE}/KEY.asc" | gpg --dearmor -o "${KEYRING}"
chmod 0644 "${KEYRING}"

cat > /etc/apt/sources.list.d/cloudsrv.sources <<EOF
Types: deb
URIs: ${QUELLE}
Suites: ${KANAL}
Components: main
Architectures: $(dpkg --print-architecture)
Signed-By: ${KEYRING}
EOF

melde "PHP-Quelle einrichten"
# Die Distributionen liefern je eine PHP-Fassung; ein Hosting-Panel braucht
# mehrere. Warum das Panel sie nicht selbst spiegelt, steht in §4.3 des Plans.
if [ ! -f /etc/apt/sources.list.d/php-sury.sources ]; then
    curl -fsSL --proto '=https' https://packages.sury.org/php/apt.gpg \
        -o /usr/share/keyrings/php-sury-keyring.gpg
    cat > /etc/apt/sources.list.d/php-sury.sources <<EOF
Types: deb
URIs: https://packages.sury.org/php/
Suites: $(. /etc/os-release && echo "${VERSION_CODENAME}")
Components: main
Signed-By: /usr/share/keyrings/php-sury-keyring.gpg
EOF
fi

melde "CloudSrv installieren"
apt-get update -qq
apt-get install -y cloudsrv-panel

melde "Ersteinrichtung"
cloudsrv einrichten --link

printf '\n'
melde "Fertig. Der Link oben führt zur Ersteinrichtung und gilt einmal."
