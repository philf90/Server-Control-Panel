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
WURZEL="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIEL="${WURZEL}/build/release"

cd "${WURZEL}"

echo "==> Abhängigkeiten"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative
npm ci
npm run build

echo "==> Auslieferungsbaum"
rm -rf "${WURZEL}/build"
mkdir -p "${ZIEL}"

# Positivliste: alles, was das Panel zum Laufen braucht — und nichts sonst.
for teil in \
    agent app bootstrap config database public resources/views routes storage vendor artisan composer.json composer.lock
do
    if [ -e "${teil}" ]; then
        mkdir -p "${ZIEL}/$(dirname "${teil}")"
        cp -a "${teil}" "${ZIEL}/${teil}"
    fi
done

# Zustand aus der Entwicklung gehört nicht ins Paket.
rm -rf "${ZIEL}/storage/logs/"* "${ZIEL}/storage/framework/cache/data/"* \
       "${ZIEL}/storage/framework/sessions/"* "${ZIEL}/storage/framework/views/"*
find "${ZIEL}/storage" -type d -exec touch {}/.gitkeep \; 2>/dev/null || true

# Die Fassung steht im Paket, nicht in der Umgebung: Wer später fragt, welche
# Fassung läuft, soll die Antwort im Dateisystem finden.
sed -i "s/'0\.1\.0-dev'/'${VERSION}'/" "${ZIEL}/config/app.php"
sed -i "s/const AGENT = '[^']*'/const AGENT = '${VERSION}'/" "${ZIEL}/agent/src/Version.php"

echo "==> Paket"
mkdir -p "${WURZEL}/dist"
sed "s/__VERSION__/${VERSION}/g" packaging/scripts/postinstall.sh > "${WURZEL}/build/postinstall.sh"
chmod +x "${WURZEL}/build/postinstall.sh"

VERSION="${VERSION}" ARCH="${ARCH}" nfpm package \
    --config <(sed "s#./packaging/scripts/postinstall.sh#${WURZEL}/build/postinstall.sh#" packaging/nfpm.yaml) \
    --packager deb \
    --target "${WURZEL}/dist"

echo "==> Fertig"
ls -lh "${WURZEL}/dist"
