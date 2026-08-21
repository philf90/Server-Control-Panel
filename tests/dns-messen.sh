#!/bin/bash
#
# Die Messvorschrift für P7 — gegen ein laufendes PowerDNS.
#
# **Warum das im Repo liegt und nicht im Sitzungsverlauf.** In `docs/45`,
# `docs/48` und `docs/59` steckte die Mehrheit der Befunde im Prüfmittel und
# nicht im Prüfling. Seit `tests/bilder-messen.js` als geprüfte Vorschrift
# danebenliegt, ist dieses Verhältnis gekippt (`docs/66`):
#
#   Ein Messmittel, das man aufhebt, macht die Fehler von letztem Mal nicht
#   noch einmal.
#
# **Jede Messung nennt ihre Gegenprobe.** Eine Null ist nur dann eine Messung,
# wenn daneben etwas anderes als Null steht — deshalb steht neben jedem
# „abgewiesen" ein „angenommen" und neben jedem „nicht sichtbar" ein
# „sichtbar".
#
# Aufruf:
#   tests/dns-messen.sh                       # Vorgaben des Wegwerf-Dienstes
#   API=http://127.0.0.1:8081 KEY=… tests/dns-messen.sh
#
# Voraussetzung ist ein laufendes PowerDNS mit eingeschalteter API. Wie man
# eines im Container hochzieht, steht in `docs/71 §2`.

set -u

API=${API:-http://127.0.0.1:8081}
KEY=${KEY:-wegwerf}
DNSPORT=${DNSPORT:-5300}
ZONE=${ZONE:-messrunde.invalid}
B="$API/api/v1/servers/localhost"

a()  { curl -s --noproxy '*' --max-time 15 -H "X-API-Key: $KEY" -H 'Content-Type: application/json' "$@"; }
code() { curl -s --noproxy '*' --max-time 15 -o /dev/null -w '%{http_code}' -H "X-API-Key: $KEY" -H 'Content-Type: application/json' "$@"; }

# Eine Frage an den Nameserver — ohne dig, weil das Programm sonst auf die
# Positivliste des Agenten und als Abhängigkeit ins Paket müsste (`Resolver`).
# $1 Name · $2 Typ als Zahl · $3 „do" setzt das DNSSEC-Bit
frage() {
  php -r '
    $name = $argv[1]; $typ = (int) $argv[2]; $do = ($argv[3] ?? "") === "do"; $port = (int) $argv[4];
    $s = @stream_socket_client("udp://127.0.0.1:$port", $e, $m, 3);
    if (! $s) { echo "KEINE VERBINDUNG\n"; exit(1); }
    $ar = $do ? 1 : 0;
    $q = pack("n6", random_int(0, 65535), 0, 1, 0, 0, $ar);
    foreach (explode(".", trim($name, ".")) as $l) { $q .= chr(strlen($l)).$l; }
    $q .= chr(0).pack("n2", $typ, 1);
    if ($do) { $q .= chr(0).pack("nnNn", 41, 4096, 0x00008000, 0); }
    fwrite($s, $q); stream_set_timeout($s, 3); $antwort = fread($s, 8192); fclose($s);
    if (! is_string($antwort) || $antwort === "") { echo "KEINE ANTWORT\n"; exit(1); }
    $h = unpack("nid/nflags/nqd/nan/nns/nar", $antwort);
    $r = $h["flags"] & 0xF;
    $namen = ["NOERROR","FORMERR","SERVFAIL","NXDOMAIN","NOTIMP","REFUSED"];
    printf("%-9s an=%d %dB\n", $namen[$r] ?? "rcode$r", $h["an"], strlen($antwort));
  ' "$1" "$2" "${3:-}" "$DNSPORT"
}

zeile() { printf '%-52s %s\n' "$1" "$2"; }
titel() { printf '\n\033[1m%s\033[0m\n' "$1"; }

titel "0. Läuft überhaupt etwas? (ohne das misst alles Folgende nichts)"
zeile "API erreichbar" "$(code "$B")"
zeile "falscher Schlüssel — Gegenprobe" "$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' -H 'X-API-Key: bestimmt-falsch' "$B/zones")"

titel "1. Die Zone und ihre Vorlage"
a -X DELETE "$B/zones/$ZONE." > /dev/null 2>&1
zeile "Zone anlegen" "$(code -X POST "$B/zones" -d "{\"name\":\"$ZONE.\",\"kind\":\"Native\",\"nameservers\":[\"ns1.$ZONE.\",\"ns2.$ZONE.\"]}")"
zeile "dieselbe Zone noch einmal — Gegenprobe" "$(code -X POST "$B/zones" -d "{\"name\":\"$ZONE.\",\"kind\":\"Native\"}")"
zeile "Vorlage in einem Aufruf" "$(code -X PATCH "$B/zones/$ZONE." -d "{\"rrsets\":[
  {\"name\":\"$ZONE.\",\"type\":\"A\",\"ttl\":3600,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"203.0.113.10\"}]},
  {\"name\":\"$ZONE.\",\"type\":\"AAAA\",\"ttl\":3600,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"2001:db8::10\"}]},
  {\"name\":\"www.$ZONE.\",\"type\":\"A\",\"ttl\":3600,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"203.0.113.10\"}]},
  {\"name\":\"*.$ZONE.\",\"type\":\"A\",\"ttl\":3600,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"203.0.113.10\"}]},
  {\"name\":\"$ZONE.\",\"type\":\"CAA\",\"ttl\":3600,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"0 issue \\\"letsencrypt.org\\\"\"}]}]}")"

titel "2. Wird sie ausgeliefert?"
zeile "SOA am Apex" "$(frage "$ZONE" 6)"
zeile "A für www" "$(frage "www.$ZONE" 1)"
zeile "A über den Platzhalter" "$(frage "beliebig.$ZONE" 1)"
zeile "CAA am Apex" "$(frage "$ZONE" 257)"
zeile "ein Name ausserhalb — Gegenprobe" "$(frage "nichts.fremd.invalid" 1)"

titel "3. Was die API abweist — und was nicht"
pruef() { printf '%-52s %s\n' "$1" "$(code -X PATCH "$B/zones/$ZONE." -d "$2")"; }
pruef "gültiger A-Satz — Gegenprobe (soll 204)" "{\"rrsets\":[{\"name\":\"gut.$ZONE.\",\"type\":\"A\",\"ttl\":300,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"203.0.113.11\"}]}]}"
pruef "A mit kaputter Adresse" "{\"rrsets\":[{\"name\":\"x.$ZONE.\",\"type\":\"A\",\"ttl\":300,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"999.1.2.3\"}]}]}"
pruef "MX ohne Priorität" "{\"rrsets\":[{\"name\":\"$ZONE.\",\"type\":\"MX\",\"ttl\":300,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"mail.$ZONE.\"}]}]}"
pruef "unbekannter Satztyp" "{\"rrsets\":[{\"name\":\"x.$ZONE.\",\"type\":\"WATDENN\",\"ttl\":300,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"1\"}]}]}"
pruef "Name ausserhalb der Zone" "{\"rrsets\":[{\"name\":\"x.fremd.invalid.\",\"type\":\"A\",\"ttl\":300,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"1.2.3.4\"}]}]}"
pruef "CNAME am Apex neben dem SOA" "{\"rrsets\":[{\"name\":\"$ZONE.\",\"type\":\"CNAME\",\"ttl\":300,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"anderswo.invalid.\"}]}]}"

titel "4. Atomarität — ein gutes und ein kaputtes RRset in EINEM Aufruf"
#
# **Hier ist der erste Lauf dieser Vorschrift hereingefallen.** Die Frage lautete
# „antwortet `heil` mit NXDOMAIN?", und die Antwort war NOERROR — nicht weil der
# Eintrag angekommen war, sondern weil der Platzhalter aus §1 jeden Namen unter
# der Zone beantwortet.
#
#   Eine Gegenprobe, die ein Platzhalter beantwortet, hat den Gegenstand nicht
#   gefragt.
#
# Gefragt wird deshalb der **Bestand der Zone** über die API und nicht der
# Nameserver: Dort steht ein RRset oder es steht keines, und ein Platzhalter
# kann die Frage nicht beantworten.
zeile "der Aufruf" "$(code -X PATCH "$B/zones/$ZONE." -d "{\"rrsets\":[
  {\"name\":\"heil.$ZONE.\",\"type\":\"A\",\"ttl\":300,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"203.0.113.12\"}]},
  {\"name\":\"kaputt.$ZONE.\",\"type\":\"A\",\"ttl\":300,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"kein-ip\"}]}]}")"
bestand() { a "$B/zones/$ZONE." | php -r '
  $j = json_decode(stream_get_contents(STDIN), true);
  $gesucht = $argv[1];
  foreach ($j["rrsets"] ?? [] as $r) {
      if ($r["name"] === $gesucht && $r["type"] === "A") {
          $werte = array_map(static fn ($x) => $x["content"], $r["records"]);
          echo "steht drin: ", implode(",", $werte), "\n"; exit;
      }
  }
  echo "steht nicht drin\n";
' "$1"; }
zeile "'heil' im Zonenbestand? (soll: nicht drin)" "$(bestand "heil.$ZONE.")"
zeile "'gut' aus §3 — Gegenprobe (soll: drin)" "$(bestand "gut.$ZONE.")"
zeile "und über den Nameserver gefragt — Platzhalter" "$(frage "heil.$ZONE" 1)"

titel "5. Wie schnell ist eine Änderung draussen?"
php -r '
  $api = $argv[1]; $key = $argv[2]; $zone = $argv[3]; $port = (int) $argv[4];
  $name = "sichtbarkeit.$zone."; $wert = bin2hex(random_bytes(16));
  $txt = function () use ($name, $port) {
      $s = @stream_socket_client("udp://127.0.0.1:$port", $e, $m, 2);
      if (! $s) { return []; }
      $q = pack("n6", random_int(0, 65535), 0, 1, 0, 0, 0);
      foreach (explode(".", trim($name, ".")) as $l) { $q .= chr(strlen($l)).$l; }
      $q .= chr(0).pack("n2", 16, 1);
      fwrite($s, $q); stream_set_timeout($s, 2); $a = fread($s, 4096); fclose($s);
      if (! is_string($a) || $a === "") { return []; }
      preg_match_all("/[0-9a-f]{32}/", $a, $t);
      return $t[0];
  };
  $vorher = $txt();
  $ch = curl_init("$api/api/v1/servers/localhost/zones/$zone.");
  curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => "PATCH", CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => "",
      CURLOPT_HTTPHEADER => ["X-API-Key: $key", "Content-Type: application/json"],
      CURLOPT_POSTFIELDS => json_encode(["rrsets" => [[
          "name" => $name, "type" => "TXT", "ttl" => 60, "changetype" => "REPLACE",
          "records" => [["content" => "\"$wert\"", "disabled" => false]]]]]),
  ]);
  $start = hrtime(true); curl_exec($ch); $nachApi = (hrtime(true) - $start) / 1e6;
  $sichtbar = null;
  for ($i = 0; $i < 3000; $i++) {
      if (in_array($wert, $txt(), true)) { $sichtbar = (hrtime(true) - $start) / 1e6; break; }
      usleep(1000);
  }
  printf("%-52s %s\n", "vorher sichtbar — Gegenprobe", $vorher === [] ? "nichts (richtig)" : implode(",", $vorher));
  printf("%-52s %.1f ms\n", "API antwortet nach", $nachApi);
  printf("%-52s %s\n", "ausgeliefert nach", $sichtbar === null ? "NIE (3 s Zeitlimit)" : sprintf("%.1f ms", $sichtbar));
' "$API" "$KEY" "$ZONE" "$DNSPORT"

titel "6. DNSSEC"
zeile "einschalten" "$(code -X PUT "$B/zones/$ZONE." -d '{"dnssec":true}')"
zeile "Schlüssel und DS" "$(a "$B/zones/$ZONE./cryptokeys" | php -r '
  $j = json_decode(stream_get_contents(STDIN), true);
  if (! is_array($j) || $j === []) { echo "KEINE\n"; exit; }
  foreach ($j as $k) { printf("%s %s · DS-2 %s\n", $k["keytype"], $k["algorithm"], substr($k["ds"][1] ?? "-", 0, 24)); }
')"
zeile "DNSKEY wird ausgeliefert" "$(frage "$ZONE" 48 do)"
zeile "A-Satz trägt jetzt eine Signatur" "$(frage "www.$ZONE" 1 do)"

echo
echo "Hinweis: 'dnssec:false' wirkt im Bestand sofort, in der Auslieferung erst"
echo "nach dnssec-key-cache-ttl (Vorgabe 30 s). Wer sofort danach fragt, misst"
echo "den Zwischenspeicher und nicht die Änderung — siehe docs/71 §4.6."
