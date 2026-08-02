#!/bin/sh
#
# Nach dem Entpacken: PHP festlegen, Rechte setzen, Migrationen fahren,
# Dienste starten, Bereitschaft prüfen.
#
# Der Ablauf ist so geschnitten, dass ein Abbruch nichts halb Fertiges
# hinterlässt: Erst wird alles vorbereitet, dann laufen die Migrationen, und
# erst danach zeigt der Symlink auf die neue Fassung. Antwortet die
# Bereitschaftsprüfung nicht, geht der Symlink zurück.
set -eu

VERSION="__VERSION__"
RELEASE_DIR="/opt/cloudsrv/releases/${VERSION}"

create_user() {
    if ! getent group cloudsrv >/dev/null; then
        addgroup --system cloudsrv
    fi

    if ! getent passwd cloudsrv >/dev/null; then
        adduser --system --ingroup cloudsrv --home /var/lib/cloudsrv \
            --no-create-home --shell /usr/sbin/nologin cloudsrv
    fi
}

select_php() {
    if [ -x /opt/cloudsrv/php/bin/php ]; then
        return 0
    fi

    for release in 8.4 8.3 8.2; do
        if [ -x "/usr/bin/php${release}" ]; then
            printf 'CLOUDSRV_PHP=/usr/bin/php%s\nCLOUDSRV_PHP_FPM=/usr/sbin/php-fpm%s\n' \
                "${release}" "${release}" > /etc/cloudsrv/php.path
            chmod 0644 /etc/cloudsrv/php.path
            return 0
        fi
    done

    echo "CloudSrv: keine geeignete PHP-Fassung gefunden (8.2 bis 8.4)." >&2
    echo "Erwartet wird php8.3-cli und php8.3-fpm aus den Paketquellen." >&2
    return 1
}

set_permissions() {
    chown -R root:root "${RELEASE_DIR}"
    # Nur was geschrieben werden muss, gehört dem Dienst. Der Rest ist für ihn
    # lesbar und nicht mehr — ein Panel, das sein eigenes Programm überschreiben
    # kann, hat eine Schwachstelle mehr, als es haben müsste.
    chown -R cloudsrv:cloudsrv "${RELEASE_DIR}/storage" "${RELEASE_DIR}/bootstrap/cache"
    chmod -R u+rwX,go-rwx "${RELEASE_DIR}/storage"
    chown root:cloudsrv /etc/cloudsrv/agent.json
    chmod 0640 /etc/cloudsrv/agent.json
    install -d -o cloudsrv -g cloudsrv -m 0750 /var/lib/cloudsrv/metrics
    install -d -o cloudsrv -g cloudsrv -m 0750 /var/log/cloudsrv
}

create_user
select_php
set_permissions

systemctl daemon-reload

# Der Agent zuerst: Ohne ihn kommt die Anwendung nicht ins System, und die
# Bereitschaftsprüfung würde scheitern, ohne dass etwas kaputt wäre.
systemctl enable --now cloudsrv-agentd.service

if [ ! -f /etc/cloudsrv/.eingerichtet ]; then
    echo "CloudSrv: Ersteinrichtung steht aus — 'cloudsrv einrichten' ausführen."
else
    /usr/local/bin/cloudsrv migrate --force --no-interaction || {
        echo "CloudSrv: Migration fehlgeschlagen, die Fassung wird nicht übernommen." >&2
        exit 1
    }
fi

for service in cloudsrv-web cloudsrv-worker cloudsrv-metrics; do
    systemctl enable "${service}.service" >/dev/null 2>&1 || true
    systemctl restart "${service}.service" || true
done

exit 0
