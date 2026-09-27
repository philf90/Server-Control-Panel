#!/bin/bash
# Platte voll: Was sieht der Agent, was sieht df — und was tut MariaDB?
#
#     bash tests/platte-voll-messen.sh
#
# Die Messrunde vor „Platte voll" (docs/136 §3), gefahren am 27. September
# 2026. Sechs Messungen, jede mit ihrer Gegenprobe:
#
#   M1 · Was `disk_free_space()` liefert — den Platz ohne die Reserve von root
#        (`f_bavail`) oder mit ihr (`f_bfree`). Gegenprobe: dieselbe Platte
#        ohne Reserve (`tune2fs -m 0`).
#   M2 · Voll für alle ausser root: nobody schreibt bis ENOSPC. Was zeigen der
#        Agent und df? Gegenprobe: root schreibt weiter, in die Reserve.
#   M3 · Voll an Inodes: nobody legt leere Dateien an bis ENOSPC. Gegenprobe:
#        An eine bestehende Datei anhängen geht weiter.
#   M4 · Die Sandbox der Agenten-Unit: `filesystems()` unter `PrivateTmp=yes`,
#        mit /tmp auf der Wurzel wie auf einem Server. Gegenprobe:
#        `PrivateTmp=no`.
#   M5 · MariaDB auf einer vollen Platte: eine Zeile, dann eine Anweisung, die
#        den Tablespace wachsen lässt. Gegenprobe: dieselbe Anweisung mit Platz.
#   M6 · Was die Griffe kosten: `disk_*_space()` gegen `stat -f`.
#
# **Gemessen wird am echten Leser** — `SystemInfo::filesystems()` über
# Reflection — und nicht an einem Nachbau seiner Formel. Ein Nachbau, der die
# Formel abschreibt, folgt ihr auch dorthin, wo sie falsch ist.
#
# **Schreibend, aber nur im eigenen Prüfstand** unter /var/tmp (nobody und
# mysql müssen hinein, der Scratchpad ist dafür zu eng) und in der eigenen
# Namespace. Ein systemd, das dieses Skript startet, beendet es auch wieder.
# Gebraucht werden root, mkfs.ext4, tune2fs, php, stat, df, setpriv, unshare,
# nsenter, systemd und MariaDB — die holt `apt-get install mariadb-server` aus
# dem Ubuntu-Archiv (CLAUDE.md, „Diese Umgebung").
#
# Die `$` in den `php -r`-Zeilen gehören PHP und nicht der Shell.
# shellcheck disable=SC2016
set -u
REPO=$(cd "$(dirname "$0")/.." && pwd)
P=/var/tmp/srvpanel-platte-voll
AUTOLOAD="$REPO/agent/src/autoload.php"
NOBODY=(setpriv --reuid=65534 --regid=65534 --clear-groups)

[ "$(id -u)" = 0 ] || { echo "FEHLT: root — Messung abgebrochen."; exit 2; }
for w in mkfs.ext4 tune2fs php stat df setpriv unshare nsenter mariadbd mariadb-install-db mariadb mariadb-admin; do
    command -v "$w" >/dev/null || { echo "FEHLT: $w — Messung abgebrochen."; exit 2; }
done
[ -x /usr/lib/systemd/systemd ] || { echo "FEHLT: systemd — Messung abgebrochen."; exit 2; }

# Die Zeilen des echten Lesers: Einhängepunkt, Gerät, Art, Prozent.
LESER='require $argv[1];
$op = new SrvPanel\Agent\Ops\SystemInfo();
foreach ((new ReflectionMethod($op, "filesystems"))->invoke($op) as $r) {
    printf("%s %s %s %.1f\n", $r["mount"], $r["device"], $r["type"], $r["percent"]);
}'

SD=''
EIGENES=0
innen() { nsenter -t "$SD" -m -p -- "$@"; }

aufraeumen() {
    mariadb-admin --socket="$P/run/s.sock" -uroot shutdown 2>/dev/null
    # Nur der eigene: ein `pkill mariadbd` träfe auf einem Rechner mit echter
    # Datenbank die echte.
    [ -s "$P/run/m.pid" ] && kill -KILL "$(cat "$P/run/m.pid")" 2>/dev/null
    [ "$EIGENES" = 1 ] && kill -KILL "$SD" 2>/dev/null
    for m in "$P"/m-*; do
        [ -d "$m" ] && umount "$m" 2>/dev/null
    done
    rm -rf "$P"
}
trap aufraeumen EXIT

rm -rf "$P"
mkdir -p "$P"
chmod 755 "$P"

# Eine Wegwerf-Platte im Loop. $1 Name, $2 Grösse, danach Schalter für mkfs.ext4.
platte() {
    local name=$1 groesse=$2
    shift 2
    truncate -s "$groesse" "$P/$name.img"
    mkfs.ext4 -q -F "$@" "$P/$name.img"
    mkdir -p "$P/m-$name"
    mount -o loop "$P/$name.img" "$P/m-$name"
    chmod 1777 "$P/m-$name"
}

# Der Agent über einen Einhängepunkt: seine Prozentzahl, oder „fehlt".
agent() {
    php -r "$LESER" "$AUTOLOAD" | awk -v m="$1" '$1 == m { print $4 " %"; f = 1 } END { if (!f) print "fehlt" }'
}

zeile() {
    printf '    %-26s %s\n' "$1" "$2"
}

stand() {
    zeile "statvfs" "$(stat -f -c 'blocks=%b bfree=%f bavail=%a files=%c ffree=%d' "$1")"
    zeile "Agent (filesystems())" "$(agent "$1")"
    zeile "df Platz / Inodes" "$(df --output=pcent,ipcent "$1" | tail -1 | xargs)"
}

echo "M1 · Was disk_free_space() liefert"
platte m1 64M -m 5
M="$P/m-m1"
stand "$M"
zeile "disk_free_space()" "$(php -r 'echo disk_free_space($argv[1]);' "$M") B"
zeile "bavail × Blockgrösse" "$(stat -f -c '%a %S' "$M" | awk '{ printf "%.0f", $1 * $2 }') B"
zeile "bfree × Blockgrösse" "$(stat -f -c '%f %S' "$M" | awk '{ printf "%.0f", $1 * $2 }') B"
echo "  Gegenprobe — dieselbe Platte ohne Reserve (tune2fs -m 0):"
# Ausgehängt: tune2fs schreibt in die Abbilddatei, und der eingehängte Kernel
# liest seinen Superblock nicht nach.
umount "$M"
tune2fs -m 0 "$P/m1.img" >/dev/null
mount -o loop "$P/m1.img" "$M"
stand "$M"
echo

echo "M2 · Voll für alle ausser root"
platte m2 64M -m 5
M="$P/m-m2"
"${NOBODY[@]}" dd if=/dev/zero of="$M/kunde.bin" bs=64k status=none 2>&1 | sed 's/^/    nobody: /'
stand "$M"
echo "  Gegenprobe — root schreibt weiter, in die Reserve:"
dd if=/dev/zero of="$M/root.bin" bs=64k count=16 status=none 2>&1 | sed 's/^/    root: /'
zeile "root.bin" "$(stat -c %s "$M/root.bin") B"
"${NOBODY[@]}" sh -c "echo x >> '$M/kunde.bin'" 2>&1 | sed 's/^/    nobody danach: /'
stand "$M"
echo

echo "M3 · Voll an Inodes"
platte m3 64M -m 5 -N 128
M="$P/m-m3"
# **In einer Unterschale, und das ist bezahlt.** `:` ist in dash ein
# Spezial-Builtin; scheitert seine Umleitung, beendet sich die ganze Shell —
# der erste Lauf endete so wortlos an Datei 117, ohne diese Zeile zu drucken.
"${NOBODY[@]}" sh -c '
    i=0
    while ( : > "$1/f$i" ) 2>/dev/null; do i=$((i + 1)); done
    echo "    nobody: $i Dateien angelegt, die nächste: $( ( : > "$1/f$i" ) 2>&1 )"
' _ "$M"
stand "$M"
echo "  Gegenprobe — an eine bestehende Datei anhängen:"
"${NOBODY[@]}" sh -c "echo inhalt >> '$M/f0' && wc -c < '$M/f0'" 2>&1 | sed 's/^/    f0 danach: /;s/$/ B/'
echo

echo "M4 · Die Sandbox der Agenten-Unit"
# **Mit eigenem /tmp** — der Grund steht in tests/wiederoeffnen-nachbauen.sh:
# Beim Hochfahren leert systemd-tmpfiles das /tmp, das es sieht.
SYSTEMD='^/usr/lib/systemd/systemd --system --unit=basic.target'
SD=$(pgrep -f "$SYSTEMD" || true)
if [ -z "$SD" ]; then
    unshare -m -p -f --mount-proc bash -c \
        'mount -t tmpfs tmpfs /tmp; mount -t cgroup2 none /sys/fs/cgroup; exec /usr/lib/systemd/systemd --system --unit=basic.target' \
        >/dev/null 2>&1 &
    EIGENES=1
    for _ in $(seq 1 60); do
        SD=$(pgrep -f "$SYSTEMD" || true)
        if [ -n "$SD" ]; then
            case "$(innen systemctl is-system-running 2>/dev/null)" in
                running|degraded) break ;;
            esac
        fi
        sleep 0.5
    done
fi
if [ -z "$SD" ]; then
    echo "  systemd kam in der eigenen Namespace nicht hoch — M4 übersprungen."
elif [ "$EIGENES" = 0 ]; then
    echo "  Ein fremdes systemd läuft schon — M4 fasst dessen /tmp nicht an und fällt aus."
else
    # **Auf einem Server ist /tmp ein Verzeichnis auf der Wurzel** (Debian 12,
    # Ubuntu) und keine Einhängung. In der Namespace liegt dort das tmpfs aus
    # dem Rezept; nach dem Hochfahren wird es abgehängt, und /tmp ist wieder das
    # Verzeichnis auf der Wurzel. Aufgeräumt hat systemd-tmpfiles beim Booten —
    # also unter dem tmpfs —, und sein Zeitgeber kommt erst nach 15 Minuten.
    #
    # Der erste Lauf hängte stattdessen ein Verzeichnis der Wurzel über /tmp —
    # und machte damit /tmp selbst zu einer Einhängung. Die Gegenprobe zeigte
    # dann zwei Zeilen statt einer, und der Unterschied war der Prüfstand.
    innen umount /tmp
    WURZEL=$(findmnt -n -o SOURCE /)
    for schalter in PrivateTmp=yes PrivateTmp=no; do
        echo "  Unit mit $schalter — Zeilen des Lesers für $WURZEL:"
        innen systemd-run --quiet --wait --pipe -p "$schalter" /usr/bin/php -r "$LESER" "$AUTOLOAD" \
            | awk -v d="$WURZEL" '$2 == d { printf "    %-12s %-10s %-5s %s %%\n", $1, $2, $3, $4 }'
    done
fi
echo

echo "M5 · MariaDB auf einer vollen Platte"
platte db 400M -m 5
M="$P/m-db"
mkdir -p "$M/data" "$P/run"
chown mysql:mysql "$M/data" "$P/run"
S="$P/run/s.sock"
starten() {
    mariadbd --no-defaults --datadir="$M/data" --socket="$S" --pid-file="$P/run/m.pid" \
        --skip-networking --user=mysql --innodb-buffer-pool-size=32M \
        --innodb-log-file-size=16M --log-error="$P/run/$1.err" >/dev/null 2>&1 &
    for _ in $(seq 1 60); do
        mariadb --socket="$S" -uroot -e 'select 1' >/dev/null 2>&1 && return 0
        sleep 0.5
    done
    return 1
}
sql() {
    local beginn ende rc ausgabe
    beginn=$(date +%s%N)
    ausgabe=$(timeout 120 mariadb --socket="$S" -uroot -N -e "$1" 2>&1)
    rc=$?
    ende=$(date +%s%N)
    printf '    %-26s rc=%d · %d ms · %s\n' "$2" "$rc" $(((ende - beginn) / 1000000)) "$(echo "$ausgabe" | tail -1)"
}
VIEL="set session max_recursive_iterations = 100000;
insert into p.findings (subject, detail)
with recursive n(i) as (select 1 union all select i + 1 from n where i < 20000)
select concat('zeile ', i), repeat('z', 1000) from n;
select count(*) from p.findings;"

mariadb-install-db --datadir="$M/data" --user=mysql --auth-root-authentication-method=normal --skip-test-db >/dev/null 2>&1
if ! starten erster; then
    echo "  MariaDB kam nicht hoch — M5 übersprungen."
else
    zeile "Fassung" "$(mariadb --socket="$S" -uroot -N -e 'select version()')"
    mariadb --socket="$S" -uroot -e "create database p; create table p.findings (id int auto_increment primary key, subject varchar(255), detail text) engine = innodb;"
    sql "insert into p.findings (subject, detail) values ('vorher', 'x'); select count(*) from p.findings;" "eine Zeile, Platz frei"
    "${NOBODY[@]}" dd if=/dev/zero of="$M/fueller.bin" bs=64k status=none 2>&1 | sed 's/^/    nobody: /'
    zeile "statvfs" "$(stat -f -c 'bavail=%a bfree=%f' "$M")"
    sql "insert into p.findings (subject, detail) values ('voll', 'y'); select count(*) from p.findings;" "eine Zeile, Platte voll"
    sql "$VIEL" "20000 Zeilen, Platte voll"
    zeile "mariadbd danach" "$(pgrep -x mariadbd >/dev/null && echo läuft || echo 'läuft nicht mehr')"
    # Je Meldung die erste Zeile, in ihrer Reihenfolge.
    grep -E 'is full|FATAL|got signal' "$P/run/erster.err" \
        | awk '{ k = $0; sub(/^.*\[ERROR\] /, "", k) } !gesehen[k]++' | cut -c1-110 | sed 's/^/    err: /'
    echo "  Gegenprobe — Platz frei, neu gestartet, dieselbe Anweisung:"
    rm -f "$M/fueller.bin"
    if starten zweiter; then
        sql "select count(*) from p.findings;" "nach dem Neustart"
        sql "$VIEL" "20000 Zeilen, Platz frei"
        zeile "Fehlerzeilen" "$(grep -c '\[ERROR\]' "$P/run/zweiter.err")"
    else
        echo "    MariaDB kam nach dem Absturz nicht wieder hoch."
    fi
fi
echo

echo "M6 · Was die Griffe kosten"
php -r '
$t = hrtime(true);
for ($i = 0; $i < 1000; $i++) { disk_total_space("/"); disk_free_space("/"); }
printf("    disk_*_space() je Einhängepunkt      %.3f ms\n", (hrtime(true) - $t) / 1e6 / 1000);
$t = hrtime(true);
for ($i = 0; $i < 50; $i++) { exec("LC_ALL=C /usr/bin/stat -f -c \"%c %d %n\" / /var/tmp /usr", $o); }
printf("    stat -f über drei Pfade je Aufruf     %.3f ms\n", (hrtime(true) - $t) / 1e6 / 50);
'
echo "  Und die Form, wenn ein Pfad fehlt:"
LC_ALL=C /usr/bin/stat -f -c '%c %d %n' / /gibt/es/nicht 2>&1 | sed 's/^/    /'
echo "    rc=${PIPESTATUS[0]}"
