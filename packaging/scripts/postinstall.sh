#!/bin/sh
#
# Nach dem Entpacken: PHP festlegen, Rechte setzen, Migrationen fahren,
# Dienste starten — und prüfen, ob das Panel danach antwortet.
#
# Antwortet es nicht, zeigt der Symlink wieder auf die vorige Fassung und die
# Dienste laufen mit ihr weiter. Das ist der Unterschied zwischen einem Update,
# das im schlimmsten Fall eine Minute kostet, und einem, das einen Server über
# Nacht dunkel lässt.
set -eu

VERSION="__VERSION__"
RELEASE_DIR="/opt/cloudsrv/releases/${VERSION}"
PREVIOUS_FILE="/var/lib/cloudsrv/previous-release"
READY_TIMEOUT=60

create_user() {
    if ! getent group cloudsrv >/dev/null; then
        addgroup --system cloudsrv
    fi

    if ! getent passwd cloudsrv >/dev/null; then
        adduser --system --ingroup cloudsrv --home /var/lib/cloudsrv \
            --no-create-home --shell /usr/sbin/nologin cloudsrv
    fi

    # nginx spricht über den FPM-Socket mit dem Panel; der steht auf
    # 0660 cloudsrv:www-data.
    if getent group www-data >/dev/null; then
        adduser cloudsrv www-data >/dev/null 2>&1 || true
    fi
}

select_php() {
    if [ -x /opt/cloudsrv/php/bin/php ]; then
        return 0
    fi

    # Nur 8.4: Laravel 13 und Symfony 8 verlangen >= 8.4.1. Eine ältere
    # Fassung zu nehmen hieße, ein Panel zu starten, das beim ersten Aufruf
    # mit einer Meldung über nicht erfüllte Plattformanforderungen abbricht.
    for release in 8.4; do
        if [ -x "/usr/bin/php${release}" ]; then
            printf 'CLOUDSRV_PHP=/usr/bin/php%s\nCLOUDSRV_PHP_FPM=/usr/sbin/php-fpm%s\n' \
                "${release}" "${release}" > /etc/cloudsrv/php.path
            chmod 0644 /etc/cloudsrv/php.path
            return 0
        fi
    done

    echo "CloudSrv: PHP 8.4 fehlt." >&2
    echo "Erwartet werden php8.4-cli und php8.4-fpm; die Quelle richtet" >&2
    echo "packaging/php-source.sh ein (bei der Installation über install.sh geschieht das)." >&2
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
    install -d -o cloudsrv -g cloudsrv -m 0700 /var/lib/cloudsrv/tmp
    install -d -o cloudsrv -g cloudsrv -m 0750 /var/log/cloudsrv
    install -d -o root -g root -m 0755 /etc/cloudsrv/tls
}

panel_port() {
    if [ -r /etc/cloudsrv/panel.env ]; then
        awk -F= '/^PANEL_PORT=/ {print $2; exit}' /etc/cloudsrv/panel.env
    fi
}

# Antwortet die Bereitschaftsprüfung?
#
# Geprüft wird über HTTP und nicht über die Kommandozeile: Nur so ist der Weg
# durch nginx und php-fpm mitgeprüft. Eine Anwendung, die sich starten lässt,
# hinter dem Webserver aber nicht erreichbar ist, wäre sonst „bereit".
panel_ready() {
    port="$(panel_port)"

    if [ -z "${port:-}" ]; then
        # Vor der Ersteinrichtung gibt es weder Port noch Oberfläche. Dann ist
        # eine ausbleibende Antwort kein Fehlschlag.
        return 0
    fi

    i=0
    while [ "${i}" -lt "${READY_TIMEOUT}" ]; do
        if curl -fsS -k --max-time 3 "https://127.0.0.1:${port}/gesundheit" >/dev/null 2>&1; then
            return 0
        fi
        i=$((i + 2))
        sleep 2
    done

    return 1
}

restart_services() {
    for service in cloudsrv-agentd cloudsrv-web cloudsrv-worker cloudsrv-metrics; do
        systemctl enable "${service}.service" >/dev/null 2>&1 || true
        systemctl restart "${service}.service" >/dev/null 2>&1 || true
    done
}

# Zurück auf die vorige Fassung: Symlink umlegen, Dienste mit ihr starten.
#
# Migrationen werden dabei nicht zurückgenommen — eine Migration, die
# durchgelaufen ist, gilt. Daraus folgt eine Regel für jede Ausbaustufe: Eine
# Migration muss mit der vorigen Fassung verträglich bleiben, sonst ist dieser
# Rückweg nur die Hälfte wert.
roll_back() {
    if [ ! -r "${PREVIOUS_FILE}" ]; then
        echo "CloudSrv: keine vorige Fassung hinterlegt — es bleibt bei ${VERSION}." >&2
        return 1
    fi

    previous="$(cat "${PREVIOUS_FILE}")"

    if [ ! -d "${previous}" ] || [ "${previous}" = "${RELEASE_DIR}" ]; then
        echo "CloudSrv: vorige Fassung ${previous} ist nicht verfügbar." >&2
        return 1
    fi

    echo "CloudSrv: Bereitschaftsprüfung fehlgeschlagen — zurück auf ${previous}." >&2
    ln -sfn "${previous}" /opt/cloudsrv/current
    restart_services

    if panel_ready; then
        echo "CloudSrv: die vorige Fassung antwortet wieder." >&2
        return 0
    fi

    echo "CloudSrv: auch die vorige Fassung antwortet nicht. systemctl status cloudsrv-web" >&2
    return 1
}

create_user
select_php
set_permissions

systemctl daemon-reload

# Der Agent zuerst: Ohne ihn kommt die Anwendung nicht ins System, und alles
# Weitere würde scheitern, ohne dass etwas kaputt wäre.
systemctl enable --now cloudsrv-agentd.service >/dev/null 2>&1 || true

if [ ! -r /etc/cloudsrv/panel.env ]; then
    echo ""
    echo "CloudSrv ist installiert. Die Ersteinrichtung steht aus:"
    echo ""
    echo "    sudo cloudsrv setup"
    echo ""
    exit 0
fi

# Ab hier ist es das Update einer eingerichteten Installation.
if ! /usr/local/bin/cloudsrv migrate --force --no-interaction; then
    echo "CloudSrv: Migration fehlgeschlagen." >&2
    roll_back || true
    exit 1
fi

restart_services

if ! panel_ready; then
    roll_back || true
    exit 1
fi

rm -f "${PREVIOUS_FILE}"
echo "CloudSrv ${VERSION} läuft."

exit 0
