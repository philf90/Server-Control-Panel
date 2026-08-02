#!/usr/bin/env bash
#
# Eine Wegwerf-Maschine mit systemd, das gebaute Paket darauf, ein Rauchtest.
#
# Denselben Weg fährt die CI (.github/workflows/ci.yml, Job „integration").
# Dass er auch von Hand läuft, ist kein Komfort: Ein Fehler, den nur die CI
# findet, wird in Zwanzig-Minuten-Schritten debuggt. Hier dauert eine Runde
# zwei Minuten.
#
#   packaging/testbed.sh                 # Debian 13
#   packaging/testbed.sh ubuntu:24.04
#   packaging/testbed.sh debian:12 --keep # Container stehen lassen
set -euo pipefail

IMAGE="${1:-debian:13}"
KEEP="${2:-}"
NAME="srvpanel-testbed"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

note() { printf '\033[1m==>\033[0m %s\n' "$1"; }
fail() { printf '\033[31mFehler:\033[0m %s\n' "$1" >&2; exit 1; }

command -v docker >/dev/null || fail "docker fehlt."
command -v nfpm >/dev/null || fail "nfpm fehlt: https://nfpm.goreleaser.com"

cleanup() {
    if [ "${KEEP}" != "--keep" ]; then
        docker rm -f "${NAME}" >/dev/null 2>&1 || true
    else
        note "Container ${NAME} bleibt stehen: docker exec -it ${NAME} bash"
    fi
}
trap cleanup EXIT

note "Paket bauen"
"${ROOT}/packaging/build.sh" 0.0.0-testbed amd64

note "Container mit systemd starten (${IMAGE})"
docker rm -f "${NAME}" >/dev/null 2>&1 || true
docker run -d --name "${NAME}" --privileged \
    --tmpfs /run --tmpfs /run/lock \
    -v /sys/fs/cgroup:/sys/fs/cgroup:rw --cgroupns=host \
    -v "${ROOT}/dist:/packages:ro" \
    "${IMAGE}" /bin/sh -c \
    'apt-get update -qq && apt-get install -y -qq systemd systemd-sysv >/dev/null && exec /sbin/init' >/dev/null

for _ in $(seq 1 60); do
    if docker exec "${NAME}" systemctl is-system-running 2>/dev/null | grep -qE 'running|degraded'; then
        break
    fi
    sleep 2
done

docker exec "${NAME}" systemctl --version | head -1

note "PHP-Quelle und PHP 8.4"
# Über die Standardeingabe statt über docker cp: systemd hängt beim Booten ein
# tmpfs über /tmp, und docker cp schreibt in den darunter liegenden Baum.
docker exec -i "${NAME}" sh -s < "${ROOT}/packaging/php-source.sh"
docker exec "${NAME}" sh -c 'DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    php8.4-cli php8.4-fpm php8.4-mbstring php8.4-xml php8.4-curl php8.4-mysql curl'
docker exec "${NAME}" php8.4 --version

note "Paket installieren"
docker exec "${NAME}" sh -c 'DEBIAN_FRONTEND=noninteractive apt-get install -y /packages/*.deb'

note "Agent"
docker exec "${NAME}" systemctl is-active srvpanel-agentd.service
docker exec "${NAME}" /opt/srvpanel/current/agent/bin/srvpanel-agentd call agent.ping

note "Rechte am Socket"
mode="$(docker exec "${NAME}" stat -c '%a %U:%G' /run/srvpanel/agent.sock)"
[ "${mode}" = "660 root:srvpanel" ] || fail "Socket steht auf ${mode}, erwartet 660 root:srvpanel"
echo "  ${mode}"

note "Ersteinrichtung"
docker exec "${NAME}" sh -c 'service mariadb start >/dev/null 2>&1 || systemctl start mariadb || true'
sleep 4
docker exec "${NAME}" srvpanel setup --port=8443

note "Oberfläche antwortet"
docker exec "${NAME}" curl -fsS -k https://127.0.0.1:8443/health
echo

note "Entfernen hinterlässt keine Dienste, aber die Daten"
docker exec "${NAME}" sh -c 'DEBIAN_FRONTEND=noninteractive apt-get remove -y -qq srvpanel >/dev/null'
docker exec "${NAME}" sh -c '! systemctl is-active --quiet srvpanel-agentd.service'
docker exec "${NAME}" test -d /var/lib/srvpanel

note "Durchgelaufen."
