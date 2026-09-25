#!/bin/bash
# Nach der Rotation: Schreibt nginx in die neue Datei?
#
#     bash tests/wiederoeffnen-nachbauen.sh
#
# **Der Anlass ist Befund 7 des B2-Laufs (docs/134).** Auf cloudsrv24 schrieb
# nginx nach jeder Rotation in die umbenannte Datei weiter: Das `USR1` aus dem
# postrotate-Abschnitt lässt jeden Arbeiter die Dateien **selbst, über den
# Pfad** neu öffnen — als www-data —, und die Protokollverzeichnisse tragen
# `<benutzer>:adm 02750`. Jeder Arbeiter meldete `(13: Permission denied)` und
# behielt die alte Datei. Seit dem 25. September 2026 lädt die Rotation nginx
# neu; dann öffnet der Master die Dateien als root, und die neuen Arbeiter
# erben sie.
#
# **Gefahren wird die Zeile wörtlich und nicht als nachgebautes Signal.** Ein
# systemd als PID 1 in eigener Namespace (docs/89 §1) führt die nginx-Unit aus
# dem Paket aus — nur mit eigener Konfiguration und pid-Datei —, und logrotate
# läuft darin mit der echten Vorlage aus `WebLogrotate::template()`. Ersetzt
# ist allein die Wurzel.
#
# **Die Messrunde vor P9 hat genau das nicht gemessen** (docs/128 Z6): Dort
# schrieb nginx nach `$R/logs`, gedreht wurde eine Kopie, in die niemand
# schrieb, und die Rechte des Servers standen nicht nach. Gemessen war das
# Umbenennen, nicht das Weiterschreiben.
#
# **Fünf Fälle und eine angehaltene Unit:**
#
#   1. die Vorlage, Verzeichnisse 02750 — erwartet: die Anfrage nach der
#      Rotation steht in access.log;
#   2. die Zeile von vorher (`kill --signal=USR1`), 02750 — erwartet:
#      access.log.1. Ohne diesen Fall wäre offen, ob der Prüfstand den Fehler
#      überhaupt zeigen kann;
#   3. die Zeile von vorher, 0755 — erwartet: access.log. Er legt die Ursache
#      auf das Verzeichnis und nicht auf das Signal;
#   4. die Datei aus dem Paket (packaging/etc/logrotate) mit panel-access.log
#      in einem Verzeichnis wie /var/log/srvpanel, 0750 — erwartet:
#      panel-access.log;
#   5. dieselbe Datei ohne postrotate, also der Stand davor — erwartet:
#      panel-access.log.1;
#   6. die Zeile der Vorlage gegen eine **angehaltene** Unit — erwartet: rc=0,
#      und die Unit bleibt aus. Daneben `reload` als Gegenprobe (rc=1): Mit
#      dieser Zeile meldete logrotate in jeder Nacht einen Fehler, in der
#      nginx steht.
#
# **Schreibend, aber nur im eigenen Prüfstand** unter /var/tmp (www-data muss
# die Arbeitsverzeichnisse von nginx erreichen, und der Scratchpad ist dafür
# zu eng), und nur in der eigenen Namespace. Ein systemd, das dieses Skript
# startet, beendet es auch wieder. Gebraucht werden root, nginx, logrotate,
# curl, php, unshare, nsenter und systemd.
#
# Die `$` in den `php -r`-Zeilen gehören PHP und nicht der Shell.
# shellcheck disable=SC2016
set -u
REPO=$(cd "$(dirname "$0")/.." && pwd)
P=/var/tmp/srvpanel-wiederoeffnen
PORT=18089
ABO=abo1
DOM=beispiel.test
LOG="$P/vhosts/$ABO/logs/$DOM"

# Der Eigentümer der Verzeichnisse ist nicht www-data und www-data nicht in
# seiner Gruppe — wie auf dem Server, wo ein Abonnement `p1136:adm` trägt.
EIGNER=nobody

# Die Gegenprobe: die Zeile, die bis zum 25. September 2026 in der Vorlage
# stand. Sie steht hier mit Absicht als Wortlaut — sie ist der bekannte Fehler.
VORHER='/usr/bin/systemctl kill --signal=USR1 nginx.service'

[ "$(id -u)" = 0 ] || { echo "FEHLT: root — Nachbau abgebrochen."; exit 2; }
for w in nginx logrotate curl php unshare nsenter; do
    command -v "$w" >/dev/null || { echo "FEHLT: $w — Nachbau abgebrochen."; exit 2; }
done
[ -x /usr/lib/systemd/systemd ] || { echo "FEHLT: systemd — Nachbau abgebrochen."; exit 2; }
getent passwd www-data >/dev/null || { echo "FEHLT: www-data — Nachbau abgebrochen."; exit 2; }
if id -nG www-data | tr ' ' '\n' | grep -qx adm; then
    echo "www-data ist hier in adm — der Prüfstand bildet den Server nicht nach. Abgebrochen."
    exit 2
fi

# **Mit eigenem /tmp, und das ist gemessen.** Beim Hochfahren läuft
# `systemd-tmpfiles-setup` mit `--remove --boot`, und `D /tmp` aus
# /usr/lib/tmpfiles.d leert das Verzeichnis. Die Mount-Namespace teilt sich das
# Dateisystem mit dem Container: Ohne das tmpfs darüber war am 25. September
# 2026 das /tmp des Containers leer — samt dem Scratchpad der Sitzung, zweimal.
SYSTEMD='^/usr/lib/systemd/systemd --system --unit=basic.target'
SD=$(pgrep -f "$SYSTEMD" || true)
EIGENES=0
if [ -z "$SD" ]; then
    unshare -m -p -f --mount-proc bash -c \
        'mount -t tmpfs tmpfs /tmp; mount -t cgroup2 none /sys/fs/cgroup; exec /usr/lib/systemd/systemd --system --unit=basic.target' \
        >/dev/null 2>&1 &
    EIGENES=1
    for _ in $(seq 1 60); do
        SD=$(pgrep -f "$SYSTEMD" || true)
        if [ -n "$SD" ]; then
            case "$(nsenter -t "$SD" -m -p -- systemctl is-system-running 2>/dev/null)" in
                running|degraded) break ;;
            esac
        fi
        sleep 0.5
    done
fi
[ -n "$SD" ] || { echo "systemd kam in der eigenen Namespace nicht hoch — abgebrochen."; exit 2; }
innen() { nsenter -t "$SD" -m -p -- "$@"; }

aufraeumen() {
    innen systemctl stop nginx.service 2>/dev/null
    innen rm -rf /run/systemd/system/nginx.service.d
    innen systemctl daemon-reload 2>/dev/null
    rm -rf "$P"
    if [ "$EIGENES" = 1 ]; then
        kill -KILL "$SD" 2>/dev/null
    fi
}
trap aufraeumen EXIT

# Die Zeile nach der Rotation, so wie die Vorlage sie heute schreibt.
VORLAGE=$(php -r 'require $argv[1]; echo SrvPanel\Agent\Ops\WebLogrotate::RELOAD;' "$REPO/agent/src/autoload.php")

# Ein Prüfstand je Fall: frische Verzeichnisse, frisches nginx, frischer
# logrotate-Zustand.
leeren() {
    innen systemctl stop nginx.service 2>/dev/null
    rm -rf "$P"
    mkdir -p "$P/tmp"
    chmod 755 "$P" "$P/tmp"
}

# Die Verzeichnisse eines Abonnements: logs/ und logs/<domain>, dazwischen
# betretbar wie /var/www/vhosts/<abo> auf dem Server. $1 die Rechte.
verzeichnisse_abo() {
    mkdir -p "$LOG"
    chmod 755 "$P/vhosts" "$P/vhosts/$ABO"
    chown "$EIGNER:adm" "$P/vhosts/$ABO/logs" "$LOG"
    chmod "$1" "$P/vhosts/$ABO/logs" "$LOG"
}

# Das Verzeichnis des Panels: /var/log/srvpanel ist 0750 srvpanel:srvpanel
# (postinstall.sh). Hier trägt nobody:nogroup die Rolle von srvpanel.
PANEL="$P/log-srvpanel"
verzeichnisse_panel() {
    mkdir -p "$PANEL" "$P/storage-logs"
    chown nobody:nogroup "$PANEL" "$P/storage-logs"
    chmod 750 "$PANEL" "$P/storage-logs"
}

# nginx mit genau einer Protokolldatei. $1 die Datei.
nginx_starten() {
    cat > "$P/nginx.conf" <<CONF
user www-data;
worker_processes 2;
pid $P/nginx.pid;
error_log $P/error.log notice;
events { worker_connections 64; }
http {
    access_log off;
    client_body_temp_path $P/tmp/body;
    proxy_temp_path $P/tmp/proxy;
    fastcgi_temp_path $P/tmp/fastcgi;
    uwsgi_temp_path $P/tmp/uwsgi;
    scgi_temp_path $P/tmp/scgi;
    server {
        listen 127.0.0.1:$PORT;
        access_log $1;
        location / { return 200 "ok\n"; }
    }
}
CONF

    innen sh -c 'mkdir -p /run/systemd/system/nginx.service.d && cat > /run/systemd/system/nginx.service.d/pruefstand.conf' <<UNIT
[Service]
PIDFile=$P/nginx.pid
ExecStartPre=
ExecStartPre=/usr/sbin/nginx -t -q -c $P/nginx.conf -g 'daemon on; master_process on;'
ExecStart=
ExecStart=/usr/sbin/nginx -c $P/nginx.conf -g 'daemon on; master_process on;'
ExecReload=
ExecReload=/usr/sbin/nginx -c $P/nginx.conf -g 'daemon on; master_process on;' -s reload
UNIT
    innen systemctl daemon-reload
    innen systemctl start nginx.service || { echo "nginx startet im Prüfstand nicht — abgebrochen."; exit 1; }
}

# Die Vorlage, wie der Agent sie schreibt. Ersetzt wird die Wurzel und — nur in
# den Gegenproben — die Zeile nach der Rotation, und zwar genau einmal oder gar
# nicht. $1 die Zeile nach der Rotation.
conf_vorlage() {
    php -r '
        require $argv[1];
        $conf = SrvPanel\Agent\Ops\WebLogrotate::template($argv[2], $argv[3]);
        $alt = SrvPanel\Agent\Ops\WebLogrotate::RELOAD;
        if (substr_count($conf, $alt) !== 1) {
            fwrite(STDERR, "Die Zeile nach der Rotation steht nicht genau einmal in der Vorlage.\n");
            exit(3);
        }
        $conf = str_replace($alt, $argv[5], $conf);
        echo str_replace("/var/www/vhosts/".$argv[2], $argv[4]."/vhosts/".$argv[2], $conf);' \
        "$REPO/agent/src/autoload.php" "$ABO" "$EIGNER" "$P" "$1" > "$P/rotate.conf" || exit 3
}

# Die Datei aus dem Paket, wie dpkg sie nach /etc/logrotate.d/srvpanel legt.
# Ersetzt werden die beiden Pfade und srvpanel — jedes genau so oft, wie es
# dasteht, oder der Nachbau bricht ab. $1 „mit" oder „ohne": ohne heisst ohne
# `sharedscripts` und postrotate, also der Stand vor dem 25. September 2026.
conf_paket() {
    php -r '
        $conf = file_get_contents($argv[1]);
        $ersetzen = [
            "/var/log/srvpanel/" => $argv[2]."/",
            "/var/lib/srvpanel/storage/logs/" => $argv[3]."/",
            "su srvpanel srvpanel" => "su nobody nogroup",
            "create 0640 srvpanel srvpanel" => "create 0640 nobody nogroup",
        ];
        foreach ($ersetzen as $alt => $neu) {
            $n = substr_count($conf, $alt);
            if ($n < 1) {
                fwrite(STDERR, "„{$alt}\" steht nicht in der Paketdatei — Nachbau abgebrochen.\n");
                exit(3);
            }
            $conf = str_replace($alt, $neu, $conf);
        }
        if ($argv[4] === "ohne") {
            $conf = preg_replace("/^\s*sharedscripts\n\s*postrotate\n.*?^\s*endscript\n/ms", "", $conf, -1, $n);
            if ($n !== 1 || str_contains($conf, "postrotate")) {
                fwrite(STDERR, "Der postrotate-Abschnitt liess sich nicht genau einmal entfernen.\n");
                exit(3);
            }
        }
        echo $conf;' \
        "$REPO/packaging/etc/logrotate" "$PANEL" "$P/storage-logs" "$1" > "$P/rotate.conf" || exit 3
}

zaehle() { if [ -f "$1" ]; then grep -c "$2" "$1"; else echo fehlt; fi; }

# Anfrage, Rotation, Anfrage — und wo die zweite landet. $1 der Name der Zeile,
# $2 die Datei, in die nginx schreibt, $3 die Rechte, wie sie dastehen sollen.
messen() {
    local name=$1 datei=$2 rechte=$3 rc
    curl -s -o /dev/null "http://127.0.0.1:$PORT/?probe=vorher"
    sleep 0.3
    if [ "$(zaehle "$datei" 'probe=vorher')" != 1 ]; then
        echo "  $name: Ladebeleg fehlt — nginx schreibt nicht in den Prüfstand. Abgebrochen."
        exit 1
    fi

    innen logrotate -f -s "$P/state" "$P/rotate.conf"
    rc=$?
    sleep 1
    curl -s -o /dev/null "http://127.0.0.1:$PORT/?probe=nachher"
    sleep 0.3

    printf '  %-34s %-6s %-5s %-12s %-14s %s\n' "$name" "$rechte" "$rc" \
        "$(zaehle "$datei" 'probe=nachher')" \
        "$(zaehle "$datei.1" 'probe=nachher')" \
        "$(grep -c '(13: Permission denied)' "$P/error.log")"
}

fall_abo() {
    leeren; verzeichnisse_abo "$3"; nginx_starten "$LOG/access.log"; conf_vorlage "$2"
    messen "$1" "$LOG/access.log" "$3"
}

fall_paket() {
    leeren; verzeichnisse_panel; nginx_starten "$PANEL/panel-access.log"; conf_paket "$2"
    messen "$1" "$PANEL/panel-access.log" 750
}

echo "=== Nachbau mit $(nginx -v 2>&1 | sed 's/^nginx version: //'), $(innen systemctl --version | head -1), $(logrotate --version 2>&1 | head -1)"
echo "    Zeile der Vorlage: $VORLAGE"
echo
echo "--- Ein Abonnement: WebLogrotate::template()"
printf '  %-34s %-6s %-5s %-12s %-14s %s\n' 'postrotate' 'Rechte' 'rc' 'Datei' 'Datei.1' 'Fehler 13'
fall_abo 'die Vorlage'             "$VORLAGE" 2750
fall_abo 'Gegenprobe: USR1'        "$VORHER"  2750
fall_abo 'Gegenprobe: USR1, offen' "$VORHER"  755
echo
echo "  Erwartet: Vorlage 1/0, USR1 bei 2750 0/1, USR1 bei 755 1/0."
echo "  Die Fehler bei 755 sind ein Wettlauf und keine Zahl: systemd schickt das USR1"
echo "  allen Prozessen zugleich (kill-whom=all). Ein Arbeiter, der vor dem chown des"
echo "  Masters öffnet, scheitert an der Datei und gelingt in der zweiten Runde —"
echo "  gemessen 0 und 4 in zwei Läufen. Bei 2750 scheitert auch die zweite."

echo
echo "--- Das Panel selbst: packaging/etc/logrotate, panel-access.log"
printf '  %-34s %-6s %-5s %-12s %-14s %s\n' 'postrotate' 'Rechte' 'rc' 'Datei' 'Datei.1' 'Fehler 13'
fall_paket 'die Paketdatei'                    mit
fall_paket 'Gegenprobe: ohne postrotate'       ohne
echo
echo "  Erwartet: Paketdatei 1/0, ohne postrotate 0/1 (nginx erfährt nichts"
echo "  von der Rotation und schreibt in die umbenannte Datei)."

# Die angehaltene Unit: Was die Zeile tut, wenn nginx steht.
echo
echo "=== Gegen eine angehaltene Unit"
innen systemctl stop nginx.service
for zeile in "$VORLAGE" '/usr/bin/systemctl reload nginx.service'; do
    ausgabe=$(innen sh -c "$zeile" 2>&1)
    rc=$?
    printf '  %-58s rc=%s  danach %-8s %s\n' "$zeile" "$rc" "$(innen systemctl is-active nginx.service)" "$ausgabe"
done
echo
echo "  Erwartet: die Vorlage rc=0 und inactive, reload rc=1."
