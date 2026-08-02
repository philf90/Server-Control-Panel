#!/usr/bin/env bash
#
# Baut den Auslieferungsbaum und daraus das .deb.
#
# Was ins Paket kommt, wird hier zusammengestellt und nicht von nfpm
# ausgesucht: Ein Paket, das den Arbeitsbaum einpackt, enthält irgendwann
# .env, node_modules und den Test-Ordner. Deshalb eine Positivliste.
#
#   packaging/build.sh 0.1.0 [amd64]
set -euo pipefail

VERSION="${1:?Aufruf: packaging/build.sh VERSION [ARCH]}"
ARCH="${2:-amd64}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGE="${ROOT}/build/release"

cd "${ROOT}"

echo "==> Abhängigkeiten"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative
npm ci
npm run build

echo "==> Auslieferungsbaum"
rm -rf "${ROOT}/build"
mkdir -p "${STAGE}"

# Positivliste: alles, was das Panel zum Laufen braucht — und nichts sonst.
for part in \
    agent app bootstrap config database public resources/views routes storage vendor artisan composer.json composer.lock
do
    if [ -e "${part}" ]; then
        mkdir -p "${STAGE}/$(dirname "${part}")"
        cp -a "${part}" "${STAGE}/${part}"
    fi
done

# Zustand aus der Entwicklung gehört nicht ins Paket.
rm -rf "${STAGE}/storage/logs/"* "${STAGE}/storage/framework/cache/data/"* \
       "${STAGE}/storage/framework/sessions/"* "${STAGE}/storage/framework/views/"*
find "${STAGE}/storage" -type d -exec touch {}/.gitkeep \; 2>/dev/null || true

# Die Fassung steht im Paket, nicht in der Umgebung: Wer später fragt, welche
# Fassung läuft, soll die Antwort im Dateisystem finden.
sed -i "s/'0\.1\.0-dev'/'${VERSION}'/" "${STAGE}/config/app.php"
sed -i "s/const AGENT = '[^']*'/const AGENT = '${VERSION}'/" "${STAGE}/agent/src/Version.php"

echo "==> Paket"
mkdir -p "${ROOT}/dist"
sed "s/__VERSION__/${VERSION}/g" packaging/scripts/postinstall.sh > "${ROOT}/build/postinstall.sh"
chmod +x "${ROOT}/build/postinstall.sh"

VERSION="${VERSION}" ARCH="${ARCH}" nfpm package \
    --config <(sed "s#./packaging/scripts/postinstall.sh#${ROOT}/build/postinstall.sh#" packaging/nfpm.yaml) \
    --packager deb \
    --target "${ROOT}/dist"

echo "==> Fertig"
ls -lh "${ROOT}/dist"
