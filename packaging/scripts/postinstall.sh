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

# Der Schreibbereich der Anwendung liegt unter /var/lib/srvpanel/storage; in
# der Fassung steht nur ein Verweis darauf (siehe packaging/nfpm.yaml).
#
# Die Unterverzeichnisse legt Laravel nicht selbst an — es erwartet sie. Sie
# hier aufzuzählen ist stumpf, aber die Alternative wäre ein Panel, das beim
# ersten Schreibversuch mit „directory does not exist" abbricht.
create_storage() {
    # **Der Elternteil ausdrücklich, und das ist gemessen.**
    #
    # `install -d` setzt Modus und Eigentümer **nur auf die letzte Ebene**;
    # fehlende Elternverzeichnisse entstehen mit 0755 und gehören dem
    # Aufrufer, also root. Ohne diese Zeile war `/var/lib/srvpanel` danach
    # `0755 root:root` statt `0750 srvpanel:srvpanel` — obwohl nfpm es genau so
    # ausliefert.
    #
    # Die Folge war lange still: Der Dienst schreibt in `storage/`, und das
    # gehört ihm. Erst `srvpanel tinker` fiel darüber, weil psysh sein
    # `.config` unter HOME anlegen will und nicht darf — mit einer Warnung und
    # **ohne den übergebenen Code auszuführen**.
    #
    # > **Ein Befehl, der schweigt, sieht aus wie einer, der nichts gefunden
    # > hat.**
    #
    # `install -d` auf ein vorhandenes Verzeichnis setzt Modus und Eigentümer
    # nach — die Zeile richtet bestehende Installationen also mit.
    install -d -o srvpanel -g srvpanel -m 0750 /var/lib/srvpanel

    # 0700, nicht 0750: Hier stand vorher ein `chmod -R go-rwx` auf den
    # Schreibbereich in der Fassung, und diese Absicht zieht mit um. Es liest
    # ohnehin nur der Dienst selbst — nginx bedient public/, nicht storage/.
    install -d -o srvpanel -g srvpanel -m 0700 /var/lib/srvpanel/storage
    for part in app app/private app/public framework framework/cache \
                framework/cache/data framework/sessions framework/views logs
    do
        install -d -o srvpanel -g srvpanel -m 0700 "/var/lib/srvpanel/storage/${part}"
    done
}

set_permissions() {
    # `chown -R` folgt keinen Verweisen: Der Verweis auf storage wechselt
    # dadurch den Eigentümer, sein Ziel unter /var/lib/srvpanel bleibt
    # unangetastet. Genau so soll es sein — create_storage hat es gesetzt.
    chown -R root:root "${RELEASE_DIR}"
    # Nur was geschrieben werden muss, gehört dem Dienst. Der Rest ist für ihn
    # lesbar und nicht mehr — ein Panel, das sein eigenes Programm überschreiben
    # kann, hat eine Schwachstelle mehr, als es haben müsste.
    chown -R srvpanel:srvpanel "${RELEASE_DIR}/bootstrap/cache"
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

    # Zwei Pfade, und das ist kein Schmuck.
    #
    # Die Bereitschaftsprüfung hieß bis 0.1.0-rc.1 /gesundheit und heißt seit
    # rc.2 /health. Für das Update nach vorn reicht der neue Pfad — geprüft
    # wird ja die neue Fassung. Der Rückweg prüft aber die *vorige*, und die
    # kennt nur den alten. Ohne diese zweite Zeile meldete ein Rückweg auf
    # rc.1 „auch die vorige Fassung antwortet nicht", obwohl sie längst
    # wieder läuft — und zwar genau in dem Moment, in dem man sich auf die
    # Meldung verlassen muss.
    #
    # Die zweite Zeile darf weg, sobald keine Installation mehr von rc.1
    # kommen kann.
    i=0
    while [ "${i}" -lt "${READY_TIMEOUT}" ]; do
        if curl -fsS -k --max-time 3 "https://127.0.0.1:${port}/health" >/dev/null 2>&1; then
            return 0
        fi
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

    # Die Speichermessung hängt an einem Timer und nicht an einem Dauerlauf.
    # `enable --now` auf den *Timer*: Ein `restart` auf srvpanel-usage.service
    # führte die Messung sofort aus und stellte den Takt trotzdem nicht an.
    systemctl enable --now srvpanel-usage.timer >/dev/null 2>&1 || true
    systemctl enable --now srvpanel-tls.timer >/dev/null 2>&1 || true
    systemctl enable --now srvpanel-cron.timer >/dev/null 2>&1 || true
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

# Fassungen abräumen, die nicht mehr gebraucht werden.
#
# **Warum das nötig ist.** dpkg entfernt beim Update die Dateien der vorigen
# Fassung, aber nur die aus dem Paket. Was zur Laufzeit entstanden ist — bis
# rc.5 das Protokoll unter storage/logs, dauerhaft die von Laravel erzeugten
# Dateien unter bootstrap/cache — bleibt liegen, und mit ihm das Verzeichnis:
#
#     dpkg: Warnung: Altes Verzeichnis »/opt/srvpanel/releases/…/storage/logs«
#           kann nicht gelöscht werden: Directory not empty
#
# Ohne diesen Schritt sammelt sich unter /opt/srvpanel/releases nach jedem
# Update ein weiteres Gerippe an. Das gilt auch für das Verzeichnis, das bis
# rc.4 wörtlich `${VERSION}` hieß — der Glob findet es wie jedes andere, ein
# eigener Sonderfall ist dafür nicht nötig.
#
# **Warum das gefahrlos ist, obwohl der Rückweg noch gebraucht werden könnte.**
# Der Rückweg liegt unter /opt/srvpanel/rollback und nicht hier. Er besteht aus
# harten Verweisen auf dieselben Dateien; ein `rm -rf` auf den alten Pfad
# entfernt nur diesen einen Namen, die Daten hängen weiter am Rückweg. Genau
# darum ist die Kopie im preinst eine Kopie und kein Pfad.
prune_releases() {
    current="$(readlink -f /opt/srvpanel/current 2>/dev/null || true)"

    if [ -z "${current}" ]; then
        return 0
    fi

    for dir in /opt/srvpanel/releases/*; do
        if [ ! -d "${dir}" ]; then
            continue
        fi

        if [ "$(readlink -f "${dir}")" = "${current}" ]; then
            continue
        fi

        rm -rf "${dir}"
        echo "SrvPanel: Reste der Fassung $(basename "${dir}") abgeräumt."
    done
}

create_user
create_storage
select_php
set_permissions
prune_releases

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

# Die ausgelieferte nginx-Konfiguration wieder auf den Stand der Vorlage
# bringen.
#
# **Die Vorlage lebt im Agenten, die Datei unter /etc/nginx ist eine Kopie —
# und die zog bis 0.4.0 niemand nach.** `panel.vhost.apply` rief allein
# `srvpanel setup`; wer einmal eingerichtet hatte, behielt den Block von damals,
# beliebig alt. Aufgefallen ist das an der teuersten Stelle: P4 hat der
# Oberfläche einen Block auf Port 80 gegeben, damit sie die ACME-Prüfung
# beantwortet. Auf dem Zielserver kam die Bestellung trotzdem nicht durch — der
# neue Block stand im Code, nicht in /etc/nginx, und die Prüfung landete beim
# Vorgabeserver auf Port 80. Antwort: 404, ohne Fehler, ohne Meldung.
#
# **Vor der Bereitschaftsprüfung und nicht danach:** Der Agent nimmt eine
# Konfiguration nur an, die `nginx -t` besteht, und legt sonst die alte zurück.
# Käme trotzdem etwas Unbrauchbares dabei heraus, meldet die Prüfung gleich
# darunter das Panel als nicht erreichbar — und der Rückweg greift.
#
# **Ein Fehlschlag bricht das Update nicht ab.** Der alte Block bleibt liegen
# und liefert weiter aus; ein Update wegen einer Konfigurationsdatei
# zurückzunehmen, wäre die teurere Antwort. Die Meldung steht dafür deutlich da.
if ! /usr/local/bin/srvpanel vhost --no-interaction; then
    echo "SrvPanel: Der Server-Block der Oberfläche liess sich nicht neu schreiben." >&2
    echo "SrvPanel: Es gilt der bisherige. Nachholen mit: sudo srvpanel vhost" >&2
fi

if ! panel_ready; then
    roll_back || true
    exit 1
fi

# Der Rückweg hat seinen Zweck erfüllt und wird abgeräumt.
#
# Ihn zu behalten hiesse, auf jedem Kundenserver dauerhaft eine Fassung
# zusätzlich zu halten — die harten Verweise aus dem preinst sind billig,
# solange dpkg die Originale noch hält, aber danach sind sie die einzigen und
# tragen die Daten allein. Nach einer bestandenen Bereitschaftsprüfung ist das
# Platz für einen Fall, der nicht mehr eintreten kann: Wer später zurück will,
# installiert die vorige Fassung aus der Paketquelle.
if [ -d /opt/srvpanel/rollback ]; then
    rm -rf /opt/srvpanel/rollback
    echo "SrvPanel: Rückweg nicht mehr nötig, abgeräumt."
fi

rm -f "${PREVIOUS_FILE}"
echo "SrvPanel ${VERSION} läuft."

exit 0
