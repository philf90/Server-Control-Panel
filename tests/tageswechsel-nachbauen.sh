#!/bin/bash
# Der Tageswechsel eines Zugriffsprotokolls, nachgebaut: Wieviel von einem Tag
# landet in der Tabelle, wenn logrotate nicht um Mitternacht dreht?
#
#     bash tests/tageswechsel-nachbauen.sh
#
# **Der Anlass steht in `docs/134 §0`.** Der Nachtlauf von B2 liest je Domain
# `access.log` und `access.log.1` und legt jeden fertigen Tag überschreibend
# ab. Das trägt nur, wenn die Rotation genau um Mitternacht läuft: Dreht
# logrotate um 00:02, liegen die ersten zwei Minuten eines Tages am nächsten
# Morgen schon in `access.log.2.gz`.
#
# **Gebaut wird mit den echten Teilen und nicht mit einer zweiten Fassung.**
# Die Konfiguration kommt aus `WebLogrotate::template()`, gedreht wird mit dem
# echten logrotate, gezählt mit `WebAccessCount::overRoot()` und aufgeteilt mit
# `AccessCounts::split()`. Nachgebaut ist allein die Ablage: „der letzte Stand
# eines Tages gewinnt" — so schreibt `Daily::record()` mit `upsert()`.
#
# **Der Prüfkörper je Tag:** eine Zeile um 00:01, vor der Rotation, dann die
# Rotation um 00:02, dann drei Zeilen (00:03, 12:00, 23:59). Wahr sind also
# vier Anfragen je Tag, und jede trägt eine eigene Byte-Zahl — eine Summe
# verrät, welche fehlt.
#
# **Drei Reihenfolgen, weil es auf dem Server drei gibt.** Der Zähllauf fällt
# zufällig in die Stunde nach Mitternacht (`RandomizedDelaySec=1h`), logrotate
# an eine feste Stelle darin (`AccuracySec=1h`). Je Nacht kommt er also vor
# oder nach der Rotation.
#
# **Schreibend, aber nur im eigenen Prüfstand** unter /var/tmp, der am Ende
# weggeräumt wird. Gebraucht werden logrotate, gzip und php samt `vendor/`.
#
# Die `$` in den `php -r`-Zeilen gehören PHP und nicht der Shell.
# shellcheck disable=SC2016
set -u
REPO=$(cd "$(dirname "$0")/.." && pwd)
R=/var/tmp/srvpanel-tageswechsel
ABO=abo1
DOM=beispiel.test

trap 'rm -rf "$R"' EXIT

for w in logrotate gzip php; do
    command -v "$w" >/dev/null || { echo "FEHLT: $w — Nachbau abgebrochen."; exit 2; }
done

[ -f "$REPO/vendor/autoload.php" ] || { echo "FEHLT: vendor/autoload.php — Nachbau abgebrochen."; exit 2; }

# Ein Nachtlauf: der Agent zählt, das Panel teilt auf, die Ablage überschreibt.
cat > /var/tmp/srvpanel-tageswechsel-zaehlen.php <<'PHP'
<?php
[$_, $autoload, $root, $heute, $ablage] = $argv;
require $autoload;
$zahl = SrvPanel\Agent\Ops\WebAccessCount::overRoot($root, microtime(true) + 60);
$teile = App\Support\Web\AccessCounts::split($zahl, $heute);
$a = is_file($ablage) ? (json_decode((string) file_get_contents($ablage), true) ?: []) : [];
foreach ($teile['countable'] as $z) {
    $a[$z['day']] = ['requests' => $z['requests'], 'sent' => $z['sent']];
}
file_put_contents($ablage, json_encode($a));
PHP
trap 'rm -rf "$R" /var/tmp/srvpanel-tageswechsel-zaehlen.php' EXIT

# Die Konfiguration aus der Vorlage — mit dem Prüfstand als Wurzel und ohne den
# Wink an nginx, den es hier nicht gibt.
vorlage() {
    php -r 'require $argv[1]; echo SrvPanel\Agent\Ops\WebLogrotate::template($argv[2], "root");' \
        "$REPO/vendor/autoload.php" "$ABO" \
        | sed -e "s#/var/www/vhosts/$ABO#$R/vhosts/$ABO#g" \
              -e 's#/usr/bin/systemctl kill --signal=USR1 nginx.service#true#'
}

# Eine Zeile im Format srvpanel; die Byte-Zahl ist ihre laufende Nummer mal 1000.
zeile() {
    n=$((n + 1))
    printf '203.0.113.9 - - [%s:%s:00 +0200] "GET /?n=%03d HTTP/1.1" 200 %d "-" "nachbau" %d %d\n' \
        "$1" "$2" "$n" $((1000 * n)) $((1000 * n)) "$n" >> "$LOG/access.log"
    printf '%s\t%d\n' "$1" $((1000 * n)) >> "$R/wahr.tsv"
}

rotieren() { logrotate -f -s "$R/state" "$R/rotate.conf"; }
zaehlen()  { php /var/tmp/srvpanel-tageswechsel-zaehlen.php "$REPO/vendor/autoload.php" "$R/vhosts" "$1" "$R/ablage.json"; }

# $1 bis $3: vor|nach je Nacht — ob der Zähllauf vor oder nach der Rotation kommt.
durchlauf() {
    rm -rf "$R" && mkdir -p "$R/vhosts/$ABO/logs/$DOM"
    LOG="$R/vhosts/$ABO/logs/$DOM"
    vorlage > "$R/rotate.conf" && chmod 644 "$R/rotate.conf"
    : > "$R/wahr.tsv"
    n=0

    zeile 21/Sep/2026 00:01; rotieren
    zeile 21/Sep/2026 00:03; zeile 21/Sep/2026 12:00; zeile 21/Sep/2026 23:59

    local nacht=0 tag heute ord
    for ord in "$@"; do
        nacht=$((nacht + 1))
        tag=$((21 + nacht))/Sep/2026
        heute=2026-09-$((21 + nacht))

        zeile "$tag" 00:01
        if [ "$ord" = vor ]; then zaehlen "$heute"; rotieren; else rotieren; zaehlen "$heute"; fi
        zeile "$tag" 00:03; zeile "$tag" 12:00; zeile "$tag" 23:59
    done
}

# Wahr gegen abgelegt, je Tag: „3 von 4" heisst, eine Anfrage fehlt in der Tabelle.
vergleich() {
    php -r '
        $w = [];
        foreach (file($argv[1]) as $l) {
            [$t, $s] = explode("\t", trim($l));
            $d = DateTime::createFromFormat("d/M/Y", $t)->format("Y-m-d");
            $w[$d] = ($w[$d] ?? 0) + 1;
        }
        $a = json_decode((string) file_get_contents($argv[2]), true) ?: [];
        foreach (["2026-09-21", "2026-09-22", "2026-09-23"] as $d) {
            printf("%-14s", isset($a[$d]) ? $a[$d]["requests"]." von ".$w[$d] : "keine Zeile");
        }
        echo "\n";' "$R/wahr.tsv" "$R/ablage.json"
}

echo "=== Nachbau mit $(logrotate --version 2>&1 | head -1), PHP $(php -r 'echo PHP_VERSION;')"
echo
printf '  %-35s%-14s%-14s%-14s\n' 'Zähllauf je Nacht' '21.' '22.' '23.'
for reihe in "nach nach nach" "vor vor vor" "vor nach nach"; do
    # shellcheck disable=SC2086
    durchlauf $reihe
    printf '  %-34s' "$reihe"
    vergleich
done

# Der Morgen des 23., nach zwei Nächten mit dem Zähllauf hinter der Rotation:
# Was sagt eine Nachzählung aus access.log.1 allein, was eine über alle Dateien?
durchlauf nach nach
echo
echo "=== Morgen des 23. — der 22. nachgezählt"
nachzaehlen() {
    awk -F'"' -v t="[22/Sep/2026:" '
        index($1, t) { split($7, b, " "); n++; s += b[1] }
        END { printf "%d Anfragen, %d B\n", n, s }'
}
printf '  %-32s %s\n' "abgelegt" \
    "$(php -r '$a = json_decode(file_get_contents($argv[1]), true); printf("%d Anfragen, %d B", $a["2026-09-22"]["requests"], $a["2026-09-22"]["sent"]);' "$R/ablage.json")"
printf '  %-32s %s\n' "aus access.log.1 allein" "$(zcat -f "$LOG/access.log.1" | nachzaehlen)"
printf '  %-33s %s\n' "über alle Dateien" "$(zcat -f "$LOG"/access.log* | nachzaehlen)"
for f in "$LOG"/access.log*; do
    printf '    %-18s %s Zeile(n) vom 22.\n' "$(basename "$f")" "$(zcat -f "$f" | grep -c '\[22/Sep/2026:')"
done
