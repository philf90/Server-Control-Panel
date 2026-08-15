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
#
# **`storage` steht bewusst nicht dabei.** Es gehört nicht in die Fassung,
# sondern nach /var/lib/srvpanel/storage; das Paket legt an seiner Stelle einen
# Verweis. Warum, steht in packaging/nfpm.yaml.
#
# **`lang` steht hier seit dem 15. August 2026, und sein Fehlen war ein
# ausgelieferter Fehler.** Die deutschen Prüfmeldungen lagen im Repo, ein
# Wächter bestätigte sie dort — und das Paket nahm sie nicht mit. Auf
# `cloudsrv24` stand deshalb weiter „The name field must not be greater than 255
# characters." unter einer deutschen Zeile (`docs/55`, Befund 11).
#
# > **Eine Datei, die ein Wächter im Repo prüft, ist damit noch nicht auf dem
# > Server.**
for part in \
    agent app bootstrap config database lang public resources/views routes vendor artisan composer.json composer.lock
do
    if [ -e "${part}" ]; then
        mkdir -p "${STAGE}/$(dirname "${part}")"
        cp -a "${part}" "${STAGE}/${part}"
    fi
done

# Die Fassung steht im Paket, nicht in der Umgebung: Wer später fragt, welche
# Fassung läuft, soll die Antwort im Dateisystem finden.
sed -i "s/'0\.1\.0-dev'/'${VERSION}'/" "${STAGE}/config/app.php"
sed -i "s/const AGENT = '[^']*'/const AGENT = '${VERSION}'/" "${STAGE}/agent/src/Version.php"

echo "==> Paket"
mkdir -p "${ROOT}/dist"
sed "s/__VERSION__/${VERSION}/g" packaging/scripts/postinstall.sh > "${ROOT}/build/postinstall.sh"
chmod +x "${ROOT}/build/postinstall.sh"

# **`${VERSION}` wird hier ersetzt und nicht nfpm überlassen.**
#
# nfpm setzt Umgebungsvariablen in `version:` und `arch:` ein, aber nicht in
# den Pfaden unter `contents:`. Das Verzeichnis der Fassung hiess deshalb in
# jedem bisher gebauten Paket wörtlich `/opt/srvpanel/releases/${VERSION}` —
# nachgesehen im ausgelieferten 0.2.0~rc.4.
#
# Aufgefallen ist es nie, weil auch der Symlink dieselbe Zeichenkette trug:
# Eine Neuinstallation läuft damit tadellos. Kaputt war das Update. Der
# Rückweg vergleicht die vorige Fassung mit der neuen und bricht ab, wenn
# beide dasselbe Verzeichnis sind — und das waren sie immer. „Update mit
# Rückweg" konnte nie zurück, und die neue Fassung wurde über die laufende
# geschrieben statt neben sie.
VERSION="${VERSION}" ARCH="${ARCH}" nfpm package \
    --config <(sed -e "s#./packaging/scripts/postinstall.sh#${ROOT}/build/postinstall.sh#" \
                   -e "s#\${VERSION}#${VERSION}#g" packaging/nfpm.yaml) \
    --packager deb \
    --target "${ROOT}/dist"

# Das Helferpaket für die PHP-Quelle.
#
# Es steht getrennt, weil es getrennt eingespielt werden muss: apt löst die
# Abhängigkeiten von srvpanel auf, bevor irgendein Paketskript läuft — ein
# postinst, das die Quelle nachreicht, käme immer zu spät. `arch: all`, es
# enthält nur ein Skript.
echo "==> Paket srvpanel-php-source"
VERSION="${VERSION}" nfpm package \
    --config packaging/nfpm-php-source.yaml \
    --packager deb \
    --target "${ROOT}/dist"

echo "==> Fertig"
ls -lh "${ROOT}/dist"
