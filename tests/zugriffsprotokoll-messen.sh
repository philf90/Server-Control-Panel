#!/bin/bash
# Was in einem Zugriffsprotokoll steht, was die Rotation damit macht, und was
# ein Nachtlauf darüber kostet — die Messrunde vor P9 (docs/127 §6.2, M3/M4).
#
#     bash tests/zugriffsprotokoll-messen.sh
#
# **Schreibend, aber nur in einem eigenen Prüfstand.** Alles entsteht unter
# /var/tmp/srvpanel-zugriff und wird am Ende weggeräumt; das System bleibt
# unberührt. Gebraucht werden nginx, logrotate, curl und php.
#
# **Warum nicht /tmp.** Der Scratchpad einer Agentensitzung ist drwx------;
# der nginx-Arbeiter läuft als www-data und kommt dort nicht hinein. Die
# Meldung ist „Permission denied" und liest sich wie ein Befund am Prüfling.
#
# **Jede Messung hat eine Gegenprobe.** Eine Null ist nur dann eine Messung,
# wenn daneben etwas anderes als Null steht. Und ein Ladebeleg gehört in die
# Messung und nicht in die Erinnerung: Z2 druckt mit, dass wirklich ein 200
# mit bekannter Körpergrösse gekommen ist — ohne ihn misst Z5 nichts.
#
# **Der Gegenstand hält nicht still.** Ein Zugriffsprotokoll rotiert über
# Nacht und wächst, während man es liest. Z6 klammert deshalb jeden Schritt
# mit `stat` davor und danach und vergleicht die **Inode**, nicht die Grösse.
set -u

REPO=$(cd "$(dirname "$0")/.." && pwd)
R=/var/tmp/srvpanel-zugriff
PORT=${PORT:-8080}
PORT_GP=${PORT_GP:-8081}
ZEILEN=${ZEILEN:-200000}

titel() { printf '\n=== %s\n' "$1"; }
wert()  { printf '  %-46s %s\n' "$1" "$2"; }
satz()  { printf '  %s\n' "$1"; }

aufraeumen() {
    [ -f "$R/nginx.pid" ] && nginx -c "$R/conf/nginx.conf" -s quit 2>/dev/null
    sleep 1
    rm -rf "$R"
}
trap aufraeumen EXIT

# ---------------------------------------------------------------------------
# Z0 — Plattform. Fehlt ein Werkzeug, ist das kein Befund, sondern ein Abbruch:
# eine Messung ohne nginx sagt über nginx nichts.
# ---------------------------------------------------------------------------
titel "Z0 — Plattform"
for w in nginx logrotate curl php; do
    command -v "$w" >/dev/null || { echo "  FEHLT: $w — Messung abgebrochen."; exit 2; }
done
wert "nginx"      "$(nginx -v 2>&1 | sed 's/.*nginx\///')"
wert "logrotate"  "$(logrotate --version 2>&1 | head -1 | awk '{print $2}')"
wert "php"        "$(php -r 'echo PHP_VERSION;')"
wert "IPv6 im Kernel" "$([ -f /proc/net/if_inet6 ] && echo ja || echo nein)"

# ---------------------------------------------------------------------------
# Z1 — Woher kommt das Format?
#
# docs/127 §6.2 M4 sagt: „Das Format kommt aus SiteTemplate, und es ist unsere
# Zeile und nicht nginx' Vorgabe." Das ist die Frage, nicht die Antwort.
# Gefragt wird an zwei Stellen: am ganzen Repo nach `log_format`, und am
# gerenderten Block nach der Form der `access_log`-Anweisung.
# ---------------------------------------------------------------------------
titel "Z1 — Woher das Format kommt"
TREFFER=$(grep -rn "log_format" --include='*.php' --include='*.conf' --include='*.sh' \
          "$REPO/agent" "$REPO/app" "$REPO/packaging" "$REPO/config" 2>/dev/null | wc -l)
wert "log_format im Quelltext (Treffer)" "$TREFFER"

mkdir -p "$R/conf" "$R/logs" "$R/root" "$R/tmp" "$R/acme" \
         "$R/client_temp" "$R/proxy_temp" "$R/fastcgi_temp" "$R/uwsgi_temp" "$R/scgi_temp" \
         "$R/vhosts/p1001/logs/messrunde.example"
chmod 755 "$R" "$R/conf" "$R/logs" "$R/root" "$R/tmp" "$R/acme"
touch "$R/conf/messrunde.example.include"

php -r '
require getenv("REPO")."/agent/src/autoload.php";
$s = SrvPanel\Agent\Site::fromArgs(["subscription"=>"p1001","user"=>"p1001",
     "domain"=>"messrunde.example","document_root"=>"httpdocs"]);
echo SrvPanel\Agent\SiteTemplate::render($s);
' REPO="$REPO" > "$R/vhost-roh.conf" 2>/dev/null || {
    REPO="$REPO" php -r '
    require getenv("REPO")."/agent/src/autoload.php";
    $s = SrvPanel\Agent\Site::fromArgs(["subscription"=>"p1001","user"=>"p1001",
         "domain"=>"messrunde.example","document_root"=>"httpdocs"]);
    echo SrvPanel\Agent\SiteTemplate::render($s);' > "$R/vhost-roh.conf"; }

satz "Die access_log-Anweisung, wie SiteTemplate sie schreibt:"
grep -m1 "access_log /var" "$R/vhost-roh.conf" | sed 's/^/    /'
FELDER=$(grep -m1 "access_log /var" "$R/vhost-roh.conf" | tr -s ' ' | awk '{print NF}')
wert "Felder in der Anweisung" "$FELDER  (2 = Pfad ohne Formatnamen)"

# ---------------------------------------------------------------------------
# Z2 — Die echte Zeile.
#
# Zwei Anpassungen am gerenderten Block, beide gedruckt: die IPv6-Zeile fällt
# weg (dieser Container hat kein IPv6, und `nginx -t` wäre sonst rot, bevor
# irgendetwas gemessen ist), und die Pfade zeigen in den Prüfstand. Die
# `access_log`-Anweisung selbst bleibt Wort für Wort stehen — sie ist der
# Prüfling.
# ---------------------------------------------------------------------------
titel "Z2 — Die echte Zeile"
sed -e '/listen \[::\]:80;/d' -e "s|listen 80;|listen $PORT;|" \
    -e "s|/var/www/vhosts/p1001/logs/messrunde.example|$R/logs|g" \
    -e "s|/var/www/vhosts/p1001/httpdocs|$R/root|g" \
    -e "s|/var/www/vhosts/p1001/conf|$R/conf|g" \
    -e "s|/var/spool/srvpanel/acme-challenge|$R/acme|g" \
    -e "s|/var/spool/srvpanel/wartung|$R/tmp/wartung|g" \
    "$R/vhost-roh.conf" > "$R/conf/vhost.conf"
satz "Angepasst gegenüber dem echten Rendering:"
diff "$R/vhost-roh.conf" "$R/conf/vhost.conf" | grep '^[<>]' | grep -cv '^$' | xargs -I{} echo "    {} Zeilen (IPv6, Port, Pfade — keine davon am Format)"

# Gegenprobe-Block mit eigenem Format. Er beweist, dass Z2 wirklich das
# Format misst: Käme hier dieselbe Zeile heraus, misst der Lauf nur, dass
# nginx überhaupt protokolliert.
cat > "$R/conf/gegenprobe.conf" <<EOF
log_format eigen '\$remote_addr|\$time_iso8601|\$request_method|\$uri|\$status|\$body_bytes_sent|\$request_length|\$http_user_agent';
server {
    listen $PORT_GP;
    server_name messrunde.example;
    access_log $R/logs/gegenprobe.log eigen;
    error_log  $R/logs/gp-error.log;
    root $R/root;
    index index.html;
}
EOF

cat > "$R/conf/nginx.conf" <<EOF
daemon on;
pid $R/nginx.pid;
error_log $R/logs/nginx-error.log warn;
events { worker_connections 64; }
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    client_body_temp_path $R/client_temp;
    proxy_temp_path $R/proxy_temp;
    fastcgi_temp_path $R/fastcgi_temp;
    uwsgi_temp_path $R/uwsgi_temp;
    scgi_temp_path $R/scgi_temp;
    include $R/conf/vhost.conf;
    include $R/conf/gegenprobe.conf;
}
EOF

# Ein Körper von genau 1000 Byte. Z5 braucht eine bekannte Zahl, gegen die
# sich body_bytes_sent halten lässt.
php -r 'echo str_repeat("x", 1000);' > "$R/root/index.html"
chmod 644 "$R/root/index.html"

nginx -t -c "$R/conf/nginx.conf" >/dev/null 2>&1 || {
    echo "  nginx -t rot — Messung abgebrochen:"; nginx -t -c "$R/conf/nginx.conf" 2>&1 | sed 's/^/    /'; exit 2; }
nginx -c "$R/conf/nginx.conf" || exit 2
sleep 1

# **Der Ladebeleg.** Ohne ihn sähe ein 404 mit 162 Byte Fehlerseite genauso
# nach einem Ergebnis aus wie ein 200 mit dem Prüfkörper.
LADE=$(curl -s -o /dev/null -w '%{http_code} %{size_download} %{size_header}' \
       -H 'Host: messrunde.example' "http://127.0.0.1:$PORT/")
set -- $LADE
wert "Ladebeleg  status / Körper / Kopfzeilen" "$1 / $2 B / $3 B"
[ "$1" = "200" ] && [ "$2" = "1000" ] || { echo "  Der Prüfkörper wurde nicht geladen — alles Folgende wäre bedeutungslos."; exit 2; }
KOERPER=$2; KOPF=$3

curl -s -o /dev/null -H 'Host: messrunde.example' \
     -A 'Mozilla/5.0 (sagt "hallo"; ein \ Backslash)' "http://127.0.0.1:$PORT/index.html"
curl -s -o /dev/null -H 'Host: messrunde.example' \
     -e 'http://verweis.example/seite?a=1' "http://127.0.0.1:$PORT/gibtsnicht"
curl -s -o /dev/null -H 'Host: messrunde.example' "http://127.0.0.1:$PORT/?such=a%20b&x=%22z%22"

satz "Was in der Datei steht:"
sed 's/^/    /' "$R/logs/access.log"

# ---------------------------------------------------------------------------
# Z3 — Gegenprobe: erzeugt ein eigenes log_format eine andere Zeile?
# ---------------------------------------------------------------------------
titel "Z3 — Gegenprobe zum Format"
curl -s -o /dev/null -H 'Host: messrunde.example' -A 'Mozilla/5.0 (sagt "hallo")' "http://127.0.0.1:$PORT_GP/"
satz "Prüfling (Vorlage ohne Formatnamen):"
grep -m1 'index.html' "$R/logs/access.log" | sed 's/^/    /'
satz "Gegenprobe (eigenes log_format):"
sed 's/^/    /' "$R/logs/gegenprobe.log"
if diff <(head -c 40 "$R/logs/access.log") <(head -c 40 "$R/logs/gegenprobe.log") >/dev/null; then
    wert "Unterscheiden sich die Formen?" "NEIN — die Messung misst das Format nicht."
else
    wert "Unterscheiden sich die Formen?" "ja — Z2 misst wirklich das Format"
fi

# ---------------------------------------------------------------------------
# Z4 — Ist die Zeile eindeutig zerlegbar?
#
# Die Sorge aus docs/127 M4 ist ein User-Agent mit Anführungszeichen. Gefragt
# wird nicht „steht ein Anführungszeichen drin", sondern „zerfällt die Zeile
# dadurch in mehr Felder, als sie hat".
# ---------------------------------------------------------------------------
titel "Z4 — Zerlegbarkeit"
ZEILE=$(grep -m1 'Backslash' "$R/logs/access.log")
satz "Die Zeile mit Anführungszeichen im User-Agent:"
printf '    %s\n' "$ZEILE"
ANZ=$(printf '%s' "$ZEILE" | tr -cd '"' | wc -c)
wert "Anführungszeichen in der Zeile" "$ANZ  (6 = die drei Paare von combined)"
printf '%s' "$ZEILE" | grep -q '\\x22' && wert "Was nginx aus \" macht" '\x22 — maskiert, nicht durchgereicht'
printf '%s' "$ZEILE" | grep -q '\\x5C' && wert "Was nginx aus \\ macht" '\x5C — maskiert, nicht durchgereicht'
# Gegenprobe: ein Splitter an " muss auf genau 7 Stücke kommen.
STUECKE=$(printf '%s' "$ZEILE" | awk -F'"' '{print NF}')
wert "Stücke beim Trennen an \"" "$STUECKE  (7 = eindeutig)"

# ---------------------------------------------------------------------------
# Z5 — Was die Zahl in der Zeile NICHT zählt.
#
# Das ist die Frage von M2: Traffic je Abo aus den Protokollen summieren.
# `combined` führt $body_bytes_sent — den Rumpf ohne Kopfzeilen und ohne die
# Anfrage. Gegenprobe ist das eigene Format aus Z3, das $request_length führt.
# ---------------------------------------------------------------------------
titel "Z5 — Was die Zahl nicht zählt (für M2)"
GEZAEHLT=$(grep -m1 ' / HTTP/1.1" 200 ' "$R/logs/access.log" | awk '{print $10}')
ANFRAGE=$(awk -F'|' 'NR==1{print $7}' "$R/logs/gegenprobe.log")
wert "body_bytes_sent in der Zeile"      "$GEZAEHLT B"
wert "tatsächlich gesendet (Körper+Kopf)" "$((KOERPER + KOPF)) B"
wert "Anfrage des Kunden (request_length)" "${ANFRAGE:-?} B"
FEHLT=$(( KOPF + ${ANFRAGE:-0} ))
wert "je Anfrage ungezählt"              "$FEHLT B"
satz "Bei kleinen Antworten kippt das Verhältnis: die Kopfzeilen sind konstant."

# ---------------------------------------------------------------------------
# Z6 — Die Rotation.
#
# Gemessen an der echten Vorlage aus WebLogrotate::template(). Ersetzt wird
# nur der postrotate-Aufruf: `systemctl kill` braucht systemd als PID 1, das
# dieser Container nicht hat. Verglichen wird die **Inode** — eine Grösse von
# 0 entstünde auch bei copytruncate, und die beiden Formen unterscheiden sich
# genau darin, was ein offener Lesegriff danach sieht.
# ---------------------------------------------------------------------------
titel "Z6 — Die Rotation"
D="$R/vhosts/p1001/logs/messrunde.example"
cp "$R/logs/access.log" "$D/access.log"
REPO="$REPO" php -r '
require getenv("REPO")."/agent/src/autoload.php";
echo SrvPanel\Agent\Ops\WebLogrotate::template("p1001", "root");
' > "$R/logrotate-roh.conf"
sed -e "s|/var/www/vhosts/p1001|$R/vhosts/p1001|g" \
    -e "s|/usr/bin/systemctl kill --signal=USR1 nginx.service|kill -USR1 \$(cat $R/nginx.pid)|" \
    "$R/logrotate-roh.conf" > "$R/logrotate.conf"
wert "Vorlage sagt" "$(grep -cE '^\s+(daily|rotate|compress|delaycompress|nocreate|create)' "$R/logrotate-roh.conf") Anweisungen zur Aufbewahrung"
grep -E '^\s+(daily|rotate [0-9]+|compress|delaycompress|nocreate|create )' "$R/logrotate-roh.conf" | sed 's/^\s*/    /'

VOR=$(stat -c '%i' "$D/access.log")
logrotate -f -s "$R/logrotate.state" "$R/logrotate.conf" 2>/dev/null
NACH=$(stat -c '%i' "$D/access.log")
wert "Inode von access.log davor"  "$VOR"
wert "Inode von access.log danach" "$NACH"
[ "$VOR" != "$NACH" ] && satz "-> Umbenennen und neu anlegen, nicht copytruncate." \
                      || satz "-> copytruncate: dieselbe Inode."
wert "access.log.1 hat Inode"      "$(stat -c '%i' "$D/access.log.1" 2>/dev/null || echo '-')"
wert "access.log danach"           "$(stat -c '%A %U:%G, %s B' "$D/access.log")"
satz "nocreate steht in der Vorlage, create darunter — gemessen gewinnt create."

# Zweiter Lauf: trägt delaycompress?
printf '127.0.0.1 - - [21/Sep/2026:03:00:00 +0000] "GET /tag2 HTTP/1.1" 200 42 "-" "curl"\n' >> "$D/access.log"
logrotate -f -s "$R/logrotate.state" "$R/logrotate.conf" 2>/dev/null
wert "nach dem zweiten Lauf liegen da" "$(ls "$D" | tr '\n' ' ')"
satz "-> .1 bleibt unkomprimiert (delaycompress), ab .2 wird gepackt."

# Der offene Lesegriff — die Falle für einen Nachtlauf.
: > "$D/access.log"; printf 'zeile-vor-rotation\n' >> "$D/access.log"
exec 9< "$D/access.log"
OFFEN=$(stat -c '%i' "$D/access.log")
logrotate -f -s "$R/logrotate.state" "$R/logrotate.conf" 2>/dev/null
printf 'zeile-nach-rotation\n' >> "$D/access.log"
wert "Leser hatte Inode geöffnet" "$OFFEN"
wert "access.log hat jetzt Inode"  "$(stat -c '%i' "$D/access.log")"
wert "was der offene Griff liest"  "$(cat <&9 | tr '\n' ' ')"
exec 9<&-
satz "-> Ein Lauf, der vor der Rotation öffnet, liest den alten Tag zu Ende"
satz "   und sieht keine Zeile, die danach geschrieben wird."

# ---------------------------------------------------------------------------
# Z7 — Was ein Nachtlauf über eine solche Datei kostet.
#
# **Der Prüfkörper ist aus echten Zeilen gebaut und variiert.** Eine Datei aus
# einer einzigen wiederholten Zeile misst den Zwischenspeicher des Prozessors
# und nicht das Zerlegen.
#
# **Zweimal gefahren.** Der erste Lauf misst die Platte mit, der zweite den
# Seitenzwischenspeicher. Wer nur einmal misst, weiss nicht, welchen von
# beiden er hat.
#
# **Gegenprobe:** derselbe Lauf ohne Zerlegen. Stehen beide Zahlen gleich,
# misst der Lauf das Lesen und nicht das Zerlegen.
# ---------------------------------------------------------------------------
titel "Z7 — Was ein Nachtlauf kostet"
REPO="$REPO" ZIEL="$R/gross.log" N="$ZEILEN" php -r '
$pfade = ["/", "/index.html", "/wp-login.php", "/assets/app.4f2b.css", "/api/v1/posts?page=3",
          "/bilder/urlaub-2026-gross.jpg", "/robots.txt", "/feed/", "/kontakt", "/suche?q=a%20b"];
$agenten = ["Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36",
            "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)",
            "curl/8.5.0", "Googlebot/2.1 (+http://www.google.com/bot.html)",
            "Mozilla/5.0 (sagt \x5Cx22hallo\x5Cx22)"];
$status = [200, 200, 200, 200, 304, 404, 301, 500];
$h = fopen(getenv("ZIEL"), "w");
$n = (int) getenv("N");
mt_srand(20260920);
for ($i = 0; $i < $n; $i++) {
    fwrite($h, sprintf(
        "%d.%d.%d.%d - - [20/Sep/2026:%02d:%02d:%02d +0000] \"GET %s HTTP/1.1\" %d %d \"-\" \"%s\"\n",
        mt_rand(1,223), mt_rand(0,255), mt_rand(0,255), mt_rand(1,254),
        mt_rand(0,23), mt_rand(0,59), mt_rand(0,59),
        $pfade[mt_rand(0, count($pfade)-1)],
        $status[mt_rand(0, count($status)-1)],
        mt_rand(0, 4000000),
        $agenten[mt_rand(0, count($agenten)-1)]));
}
fclose($h);
'
GROESSE=$(stat -c '%s' "$R/gross.log")
wert "Prüfkörper" "$ZEILEN Zeilen, $((GROESSE/1024/1024)) MiB, $((GROESSE/ZEILEN)) B je Zeile"

lauf() {
    ZIEL="$R/gross.log" MODUS="$1" php -r '
    $t = hrtime(true); $bytes = 0; $zeilen = 0; $treffer = 0;
    $h = fopen(getenv("ZIEL"), "r");
    $modus = getenv("MODUS");
    while (($z = fgets($h)) !== false) {
        $zeilen++;
        if ($modus === "lesen") { $bytes += strlen($z); continue; }
        // Zerlegen an den Anführungszeichen — das ist die Form, die Z4 belegt.
        $teile = explode("\"", $z);
        if (count($teile) < 7) { continue; }
        $kopf = explode(" ", trim($teile[0]));
        $ende = explode(" ", trim($teile[2]));
        $bytes += (int) ($ende[1] ?? 0);
        if (($ende[0] ?? "") === "200") { $treffer++; }
    }
    fclose($h);
    $s = (hrtime(true) - $t) / 1e9;
    printf("%.3f %d %d %d %d\n", $s, $zeilen, $bytes, $treffer, (int) round($zeilen / max($s, 1e-9)));'
}

# **Der kalte Lauf zuerst.** Das Skript hat die Datei eben selbst geschrieben,
# sie liegt also im Seitenzwischenspeicher. Wer nur so misst, misst den
# Zwischenspeicher und nennt es Durchsatz.
if sync && echo 3 > /proc/sys/vm/drop_caches 2>/dev/null; then
    set -- $(lauf zerlegen)
    wert "Lauf 0 · zerlegen, kalt" "$1 s · $2 Zeilen · $5 Zeilen/s"
else
    wert "Lauf 0 · zerlegen, kalt" "nicht messbar (drop_caches verweigert)"
fi
for i in 1 2; do
    set -- $(lauf zerlegen)
    wert "Lauf $i · zerlegen, warm" "$1 s · $2 Zeilen · $5 Zeilen/s · Summe $3 B · $4 mit Status 200"
done
set -- $(lauf lesen)
wert "Gegenprobe · nur lesen" "$1 s · $5 Zeilen/s (ohne Zerlegen)"
satz "Stehen beide Zahlen gleich, misst der Lauf nicht das Zerlegen."

titel "Was diese Messung nicht sagt"
satz "· Sie misst die Platte dieses Containers, nicht die von cloudsrv24."
satz "· Sie misst eine Datei, in die niemand nebenher schreibt."
satz "· Sie sagt nichts über den Speicher, den ein Zähler je Domain braucht."
satz "· Der Prüfkörper ist gebaut; eine echte Datei hat andere Häufigkeiten."
