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
PREVIOUS_FILE="/var/lib/srvpanel/previous-release"
READY_TIMEOUT=60

# Das Verzeichnis der neuen Fassung wird nicht aus der Version zusammengesetzt,
# sondern beim Symlink erfragt, den dpkg gerade gelegt hat.
#
# Der Grund ist eine Falle, die beim ersten Vorabtag zugeschlagen hätte: Eine
# Debian-Version darf den Bindestrich nicht als Trenner für eine Vorabfassung
# führen, und nfpm schreibt deshalb aus „0.1.0-rc.1" ein „0.1.0~rc.1". Das
# Verzeichnis im Paket heißt dann anders als die Zeichenkette, die hier
# eingesetzt wird — und das Skript fasste ein Verzeichnis an, das es nicht gibt.
# Der Symlink weiß es besser als jede Umrechnung von Versionsschreibweisen.
RELEASE_DIR="$(readlink -f /opt/srvpanel/current 2>/dev/null || true)"

if [ -z "${RELEASE_DIR}" ] || [ ! -d "${RELEASE_DIR}" ]; then
    echo "SrvPanel: /opt/srvpanel/current zeigt auf kein Verzeichnis — das Paket ist unvollständig." >&2
    exit 1
fi

create_user() {
    if ! getent group srvpanel >/dev/null; then
        addgroup --system srvpanel
    fi

    if ! getent passwd srvpanel >/dev/null; then
        adduser --system --ingroup srvpanel --home /var/lib/srvpanel \
            --no-create-home --shell /usr/sbin/nologin srvpanel
    fi

    # nginx spricht über den FPM-Socket mit dem Panel; der steht auf
    # 0660 srvpanel:www-data.
    if getent group www-data >/dev/null; then
        adduser srvpanel www-data >/dev/null 2>&1 || true
    fi
}

select_php() {
    if [ -x /opt/srvpanel/php/bin/php ]; then
        return 0
    fi

    # Nur 8.4: Laravel 13 und Symfony 8 verlangen >= 8.4.1. Eine ältere
    # Fassung zu nehmen hieße, ein Panel zu starten, das beim ersten Aufruf
    # mit einer Meldung über nicht erfüllte Plattformanforderungen abbricht.
    if [ -x /usr/bin/php8.4 ]; then
        printf 'SRVPANEL_PHP=/usr/bin/php8.4\nSRVPANEL_PHP_FPM=/usr/sbin/php-fpm8.4\n' \
            > /etc/srvpanel/php.path
        chmod 0644 /etc/srvpanel/php.path
        return 0
    fi

    echo "SrvPanel: PHP 8.4 fehlt." >&2
    echo "Erwartet werden php8.4-cli und php8.4-fpm; die Quelle richtet" >&2
    echo "packaging/php-source.sh ein (bei der Installation über install.sh geschieht das)." >&2
    return 1
}

set_permissions() {
    chown -R root:root "${RELEASE_DIR}"
    # Nur was geschrieben werden muss, gehört dem Dienst. Der Rest ist für ihn
    # lesbar und nicht mehr — ein Panel, das sein eigenes Programm überschreiben
    # kann, hat eine Schwachstelle mehr, als es haben müsste.
    chown -R srvpanel:srvpanel "${RELEASE_DIR}/storage" "${RELEASE_DIR}/bootstrap/cache"
    chmod -R u+rwX,go-rwx "${RELEASE_DIR}/storage"
    chown root:srvpanel /etc/srvpanel/agent.json
    chmod 0640 /etc/srvpanel/agent.json
    install -d -o srvpanel -g srvpanel -m 0750 /var/lib/srvpanel/metrics
    install -d -o srvpanel -g srvpanel -m 0700 /var/lib/srvpanel/tmp
    install -d -o srvpanel -g srvpanel -m 0750 /var/log/srvpanel
    install -d -o root -g root -m 0755 /etc/srvpanel/tls
}

panel_port() {
    if [ -r /etc/srvpanel/panel.env ]; then
        awk -F= '/^PANEL_PORT=/ {print $2; exit}' /etc/srvpanel/panel.env
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
    for service in srvpanel-agentd srvpanel-web srvpanel-worker srvpanel-metrics; do
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
        echo "SrvPanel: keine vorige Fassung hinterlegt — es bleibt bei ${VERSION}." >&2
        return 1
    fi

    previous="$(cat "${PREVIOUS_FILE}")"

    if [ ! -d "${previous}" ] || [ "${previous}" = "${RELEASE_DIR}" ]; then
        echo "SrvPanel: vorige Fassung ${previous} ist nicht verfügbar." >&2
        return 1
    fi

    echo "SrvPanel: Bereitschaftsprüfung fehlgeschlagen — zurück auf ${previous}." >&2
    ln -sfn "${previous}" /opt/srvpanel/current
    restart_services

    if panel_ready; then
        echo "SrvPanel: die vorige Fassung antwortet wieder." >&2
        return 0
    fi

    echo "SrvPanel: auch die vorige Fassung antwortet nicht. systemctl status srvpanel-web" >&2
    return 1
}

create_user
select_php
set_permissions

systemctl daemon-reload

# Der Agent zuerst: Ohne ihn kommt die Anwendung nicht ins System, und alles
# Weitere würde scheitern, ohne dass etwas kaputt wäre.
systemctl enable --now srvpanel-agentd.service >/dev/null 2>&1 || true

if [ ! -r /etc/srvpanel/panel.env ]; then
    echo ""
    echo "SrvPanel ist installiert. Die Ersteinrichtung steht aus:"
    echo ""
    echo "    sudo srvpanel setup"
    echo ""
    exit 0
fi

# Ab hier ist es das Update einer eingerichteten Installation.
if ! /usr/local/bin/srvpanel migrate --force --no-interaction; then
    echo "SrvPanel: Migration fehlgeschlagen." >&2
    roll_back || true
    exit 1
fi

restart_services

if ! panel_ready; then
    roll_back || true
    exit 1
fi

rm -f "${PREVIOUS_FILE}"
echo "SrvPanel ${VERSION} läuft."

exit 0
