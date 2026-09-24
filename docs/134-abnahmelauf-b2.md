# B2 — der Abnahmelauf

Ausgeschrieben am 24. September 2026, **vor** dem Fahren. Das Kriterium steht in
`docs/129 §9`:

> Für eine Domain mit echtem Verkehr steht am Morgen eine Tageszeile, deren
> Zahlen sich von Hand aus `access.log.1` nachrechnen lassen — `stat` davor und
> danach.

Der Plan ist `docs/129 §5`. Gebaut sind das Format und der Nachtlauf
(`srvpanel:traffic` am Timer `srvpanel-traffic.timer`), die Ablage dahinter ist
`Daily::record()` aus B3.

**Gegen die Fassung, die zuletzt auf `cloudsrv24` gemessen wurde, fällt dieser
Lauf voraussichtlich aus** — nicht am Server und nicht an der Vorschrift, sondern
am Prüfling. §0 Punkt 2 sagt warum, mit einem Nachbau daneben, und §6, woran man
es abliest.

**Entschieden am 24. September 2026: zuerst beheben.** Die Behebung ist am selben
Tag gebaut (§0 Punkt 2, am Ende), und gefahren wird der Lauf gegen eine Fassung,
die sie trägt. **Keine Freigabe tut das bisher** — die jüngste ist `v0.9.0-rc.1`
(`docs/133`) —, also steht vor dem Lauf ein Tag des Betreibers. §1 Block 0 fragt
die installierte Fassung, bevor irgendetwas anderes gemessen wird, und die Punkte
4 bis 6 nennen neben der Erwartung, was eine Fassung ohne die Behebung zeigt.

---

## §0 · Was beim Ausschreiben umgefallen ist

**Sieben Zeilen. Zwei davon betreffen nicht den Lauf, sondern den Prüfling — und
die erste der beiden hätte der Lauf, so wie das Kriterium lautet, bestätigt statt
gefunden.** Beide sind am 24. September behoben. Sie stehen hier weiter in der
Form, in der sie gefunden wurden, weil die Punkte 4 bis 6 an ihnen ablesen, ob die
behobene Fassung läuft.

**1 · Das Kriterium erbt die Lücke des Prüflings.** „Von Hand aus `access.log.1`
nachrechnen" liest dieselbe Datei wie der Nachtlauf, und damit fehlt der
Nachrechnung genau das, was dem Nachtlauf fehlt (Punkt 2). Gemessen im Nachbau
am Morgen des 23. September, für den 22.:

| | Anfragen | Bytes |
|---|---|---|
| abgelegt | 3 | 21 000 |
| aus `access.log.1` allein nachgerechnet | 3 | 21 000 |
| über alle Dateien nachgerechnet | **4** | **26 000** |

Die vierte Anfrage steht in `access.log.2.gz`. Das Kriterium hätte diesen Tag als
erfüllt gemeldet.

> **Ein Kriterium, das an derselben Datei nachzählt wie der Prüfling, erbt
> dessen Lücke — und bestätigt sie.**

**Nachgerechnet wird deshalb über alle Dateien der Domain** (`zcat -f
access.log*`), und der gemessene Tag muss **mindestens eine Zeile vor der
Rotation** tragen. Ohne sie gibt es die Lücke an diesem Tag nicht, und die
Gleichheit belegt nichts. Das berichtigte Kriterium steht in §6, und `docs/129
§9` verweist darauf.

**2 · Der Nachtlauf verliert den Kopf jedes Tages.** Drei Stellen im Quelltext
sagen, dass das nicht geschehen kann:

- `WebAccessCount`: *„Mit beiden Dateien ist die Reihenfolge gleichgültig: Vor
  der Rotation steht der gestrige Tag vollständig in `access.log`, danach
  vollständig in `.1`."*
- `CollectTraffic`: dieselbe Begründung, fast wörtlich.
- `srvpanel-traffic.timer`: *„…dann ist es gleichgültig, ob er vor oder nach dem
  Umbenennen ankommt."*

**Der Satz stimmt für eine Rotation um Punkt Mitternacht, und genau die gibt es
nicht.** `logrotate` dreht irgendwann in der Stunde danach (`AccuracySec=1h`).
Was ein Tag zwischen Mitternacht und diesem Augenblick schreibt — sein **Kopf** —,
steht in der Datei des Vortags, und nach der nächsten Rotation heisst sie
`access.log.2.gz`. Die liest niemand.

Zwei Wege führen zum selben Verlust:

- **Der Lauf kommt nach der Rotation.** Er liest `access.log` und `.1` und sieht
  den Tag ohne seinen Kopf.
- **Der Lauf kommt davor.** Er sieht den Tag vollständig — Kopf in `.1`, Rest in
  `access.log`. Kommt die **nächste** Nacht ebenfalls davor, sieht sie ihn noch
  einmal, diesmal ohne Kopf, und `Daily::record()` überschreibt.
  `DailyMetricsTest::test_a_corrected_day_replaces_the_number` hält genau diese
  Regel, und sie ist richtig — nur ist die spätere Sicht nicht die vollständigere.

> **Ein Lauf, der denselben Tag mehrfach sieht und überschreibt, behält die
> letzte Sicht — und die letzte ist nicht die vollständigste.**

**Gebaut mit den echten Teilen** als `tests/tageswechsel-nachbauen.sh`: die
Konfiguration aus `WebLogrotate::template()`, das echte logrotate 3.21.0,
gezählt mit `WebAccessCount::overRoot()`, aufgeteilt mit `AccessCounts::split()`;
nachgebaut ist allein das Überschreiben. Je Tag eine Zeile um 00:01, vor der
Rotation, und drei danach:

| Zähllauf je Nacht | 21. | 22. | 23. |
|---|---|---|---|
| nach · nach · nach | 3 von 4 | 3 von 4 | 3 von 4 |
| vor · vor · vor | 3 von 4 | 3 von 4 | 4 von 4 |
| vor · nach · nach | **4 von 4** | 3 von 4 | 3 von 4 |

Die Vier beim 23. in der zweiten Zeile ist nur deshalb eine, weil keine vierte
Nacht mehr kommt. Vollständig bleibt ein Tag allein in der Folge *erst davor,
dann danach*. **Die Gegenprobe** ohne die Zeile um 00:01 gibt in allen drei
Reihenfolgen `3 von 3`: Ohne Kopf verliert der Lauf nichts — und ein Prüfstand,
der seine Zeilen nicht um Mitternacht schreibt, sieht den Fehler nie.

**Wie oft es trifft, folgt aus zwei gemessenen Grössen.** Der Zähllauf liegt
gleichverteilt in der Stunde nach Mitternacht (`RandomizedDelaySec=1h`, jede
Nacht neu gewürfelt); die Rotation lag auf `cloudsrv24` am 22. September in
`(23:59:25, 00:04:25]` (`docs/129 §10` Punkt 1). Liegt sie `r` Minuten nach
Mitternacht, kommt der Lauf in `1 − r/60` der Nächte danach — bei `r ≤ 4:25`
also in **mindestens 92 von 100**. Vollständig bleibt ein Tag mit
`r/60 · (1 − r/60)`, dort in **höchstens 7 von 100**.

**Wie viel es ist, hängt an `r`**: auf `cloudsrv24` höchstens die ersten
viereinhalb Minuten jedes Tages, auf einem Server, dessen Rotation später im
Fenster liegt, bis zu einer Stunde. `systemd.timer(5)` sagt über `AccuracySec=`
nur, die Stelle im Fenster sei *„host-specific, randomized, but stable"*; warum
sie auf `cloudsrv24` so früh liegt, ist nicht gemessen.

**Was daraus folgt, entscheidet dieser Lauf nicht.** Mehr Dateien lesen, nur den
Vortag ablegen oder beides — das ist eine Frage an den Bau. Der Lauf ist so
geschrieben, dass er beide Sichten auf den Tag herstellt, statt auf die Nacht zu
warten, die sie zufällig liefert (Punkt 5).

**Behoben am 24. September 2026, mit beidem.** Jede Hälfte allein lässt zwei
Folgen von Nächten offen, und zwar verschiedene:

- **`web.access.count` liest `access.log.2.gz` mit**, gepackt über den
  Datenstrom `compress.zlib://`. Damit steht der Vortag in jeder Sicht der
  folgenden Nacht vollständig da: vor der Rotation Kopf in `.1` und Rumpf in
  `access.log`, danach Kopf in `.2.gz` und Rumpf in `.1`. Gemessen gleich teuer
  wie ungepackt — 200 000 Zeilen in 0,255 s gegen 0,252 s.
- **`srvpanel:traffic` legt nur noch den Vortag ab** (`AccessCounts::split()`).
  Was älter ist, hatte seine Nacht; es steht in einem eigenen Topf und wird
  nicht noch einmal geschrieben — die spätere Sicht wäre die unvollständigere.

`TrafficRotationTest` fährt den Tag mit den echten Teilen durch zwei Nächte, in
allen vier Reihenfolgen und bis in die Datenbank; die Nacht, in der sein Kopf
entsteht, zählt nach der Rotation. Gemessen, je Fassung:

| Fassung | scheitert in |
|---|---|
| beide Hälften zurückgenommen — so stand es vorher | *vor · vor*, *nach · vor*, *nach · nach* |
| ohne die gepackte Datei | *nach · vor*, *nach · nach* |
| ohne die Vortagsregel | *vor · nach*, *nach · nach* |
| behoben | keiner |

Die eine Folge, die vorher hielt — *vor · nach* —, ist dieselbe, die der Nachbau
oben als *erst davor, dann danach* gefunden hat. **Der Nachbau mit dem echten
logrotate** gibt nach der Behebung in allen drei Reihenfolgen `4 von 4`, und am
Morgen des 23. stehen für den 22. abgelegt und über alle Dateien nachgezählt
dieselben **4 Anfragen und 26 000 Bytes**; aus `access.log.1` allein wären es
weiter 3 und 21 000. Gegen den Stand davor gefahren, gibt er die Tabelle oben
Zeile für Zeile wieder.

**Was die Behebung nicht abdeckt** — hergeleitet und nicht gemessen:

- **Ein Tag, nach dem einen ganzen Tag lang kein Lauf kommt, bleibt ohne
  Zeile.** Ein nachgeholter Lauf sieht ihn dann nicht mehr als Vortag. Das ist
  entworfen: „Ein Tag ohne Zahlen ist ehrlicher als ein Tag mit halben"
  (`docs/129 §5`).
- **Eine zweite Rotation am selben Tag** — ein `logrotate -f` von Hand —
  schiebt den Kopf eines Tages bis `.3.gz`, und die liest niemand.
- **Ein Lauf, der sich mit der Rotation überschneidet.** logrotate benennt
  nacheinander um und packt danach; wer dazwischen öffnet, kann eine Datei
  zweimal oder eine halb gepackte lesen. Wie lange die Rotation auf dem Server
  dauert, ist nicht gemessen; ob sich die beiden in einer Nacht dieses Laufs
  überschnitten, sagt Punkt 2 (*überlappend*).

**3 · Der Nachtlauf druckt eine Zeile zweimal, die zweite falsch.**
`CollectTraffic` schreibt „Laufender Tag auf dem Server: …" zweimal
hintereinander. Die zweite liest `$result['timezone']`, und das schickt der
Agent nicht mehr: Eingeführt hat die Zeile `3d90b657`, als die Zone noch aus dem
Agenten kam; `bd5611bb` hat die Frage an `ServerZone` gegeben, die neue Zeile
davor gesetzt und den alten Leser stehen lassen. Seitdem steht in jeder Nacht
unter der richtigen Zone ein `(Zeitzone unbekannt)`.

> **Ein Feld, das gelesen und nicht mehr geschrieben wird, liest sich als
> „unbekannt" — und die Zeile sieht aus wie eine Auskunft.**

**Behoben am 24. September 2026:** Die zweite Zeile ist fort, und
`TrafficReportSeamTest` hält seitdem, dass der Nachtlauf aus der Antwort von
`web.access.count` nur liest, was die Operation schreibt — was sie schreibt, kommt
dabei aus einem echten Aufruf und nicht aus einer Liste im Test. Punkt 6 erwartet
deshalb **eine** Zeile; steht die zweite noch da, läuft eine Fassung ohne die
Behebung.

**4 · „Echter Verkehr" gibt es auf `cloudsrv24` nicht.** Die sechs
Zugriffsprotokolle trugen zu Beginn der Plattenkurve **286 Bytes** zusammen und
an ihrem Ende null (`docs/129 §10` Punkt 2) — ein Server ohne nennenswerten
Web-Verkehr. Der Lauf erzeugt den Verkehr deshalb selbst, und zwar
so, dass „echt" das Richtige bleibt: Die Anfragen gehen über den Webserver der
Domain, und die Zeilen schreibt nginx, nicht der Lauf. Jede Anfrage trägt eine
Nummer (`?b2=00042`) und steht mit dem, was curl auf der anderen Seite gezählt
hat, in einem Buch. Fremde Zeilen — Suchmaschinen, Scanner — zählen mit; das Buch
trennt die eigenen heraus. Das ist der Handgriff aus `docs/921`: **jede erzeugte
Zeile nummerieren.**

**5 · Nicht jede Anfrage steht im Protokoll.** `SiteTemplate` schaltet es an zwei
Stellen ab, in der `location` der ACME-Prüfadresse und in der für Punktdateien
(`access_log off;`). Daraus folgt zweierlei. Die Zählung in §1 muss `access_log
off` auslassen, sonst stehen dreimal so viele Zeilen da wie Server-Blöcke, und
keine Zahl stimmt. Und der Prüfkörper für einen Fehler kann keine Punktdatei
sein: Gemessen gegen den gerenderten PHP-Block im Container antwortet `/.env` mit
`403` und steht **nicht** im Protokoll. Der Fehler dieses Laufs ist deshalb ein
`DELETE` auf die Prüfdatei — `405`, und der steht darin.

**6 · Zwei Griffe an systemd tun nicht, was sie sagen.** Beide im Container
gegen systemd 255 als PID 1 gemessen, beide beim Ausschreiben von §3:

- **`--timestamp=unix` ändert an `systemctl show` nichts.** `NextElapseUSecRealtime`
  kam mit und ohne die Option als `Thu 2026-09-24 15:42:20 UTC`. Die erste
  Fassung von §3 strich dort ein `@` und rechnete mit dem Rest — sie wäre an
  diesem Text gestorben. `date -d` liest die
  ausgeschriebene Form dagegen richtig, auch mit `CEST`
  (`00:00:12 CEST` → `22:00:12 UTC`).
- **Ein transienter Timer ist nach dem Feuern fort.** Gemessen an zwei, beide
  mit `systemd-run --on-calendar=…` angelegt: Einer mit
  `--timer-property=RemainAfterElapse=yes` stand trotzdem auf
  `RemainAfterElapse=no`, und einer ohne war nach dem Feuern verschwunden —
  `list-timers` nannte null Timer, `LastTriggerUSec` war leer. Beleg, dass der
  Lastgeber lief, ist deshalb die erste Zeile im Buch, nicht der Timer.

> **Eine Option, die ein Werkzeug annimmt, ist keine Zusage, dass es sie an
> dieser Stelle beachtet.**

**7 · Nebenbei beantwortet: warum nur drei von sechs Dateien rotierten.**
`docs/129 §10` Punkt 1 liess die Frage offen und nannte `notifempty` eine
Vermutung. Im Container mit der echten Vorlage und logrotate 3.21.0 gemessen:
Eine leere Datei wird übergangen, eine mit einer Zeile gedreht — auch mit `-f`.
Bei sechs Protokollen, die zusammen 286 Bytes trugen, ist das eine hinreichende
Erklärung. Ob es auf
`cloudsrv24` die war, sagt erst eine Nacht, deren Dateigrössen vor der Rotation
jemand aufgeschrieben hat.

**Und daraus folgt eine weitere Nacht, die denselben Tag sieht.** Schreibt eine
Domain einen ganzen Tag lang nichts, dreht logrotate sie nicht, `.1` bleibt
stehen, und der Vortag kommt in der nächsten Nacht noch einmal am Zähllauf
vorbei. Das ist hergeleitet und nicht gemessen; Punkt 7 sorgt dafür, dass es in
diesem Lauf nicht vorkommt. **Mit der Behebung ist die weitere Nacht harmlos:**
Für sie ist dieser Tag nicht mehr der Vortag, und er wird nicht noch einmal
abgelegt.

---

## §1 · Vorbedingungen — gemessen und nicht angenommen

**Alles in einem Block, und jede Zeile druckt, was sie gefunden hat.** Eine
Vorbedingung, die man nicht gegen den heilen Fall gemessen hat, ist keine
Prüfung (`docs/913 §1`).

```bash
# 0 · Welche Fassung läuft, und trägt sie den Nachtlauf und seine Ablage?
srvpanel version
systemctl cat srvpanel-traffic.timer | grep -E '^(OnCalendar|Persistent|RandomizedDelaySec)='
printf 'Timer: %s, %s\n' "$(systemctl is-enabled srvpanel-traffic.timer)" "$(systemctl is-active srvpanel-traffic.timer)"
srvpanel tinker --execute='
  foreach (["domain_metrics", "subscription_metrics"] as $t)
      printf("%-22s %s\n", $t, Illuminate\Support\Facades\Schema::hasTable($t) ? "da" : "FEHLT");
'

# 0b · Trägt sie die Behebung aus §0 Punkt 2? Gefragt wird der geladene Code, nicht sein Text.
/opt/srvpanel/bin/php -r 'require $argv[1];
  printf("drei Dateien: %s   gepackt lesbar: %s\n",
      defined("SrvPanel\\Agent\\Site::SECOND_ROTATED_ACCESS_LOG") ? "ja" : "NEIN",
      in_array("compress.zlib", stream_get_wrappers(), true) ? "ja" : "NEIN");' /opt/srvpanel/current/agent/src/autoload.php
srvpanel tinker --execute='
  $t = App\Support\Web\AccessCounts::split(["domains" => [["subscription" => "a", "domain" => "b.test",
      "days" => ["2026-09-22" => ["requests" => 1, "sent" => 1, "received" => 1, "errors" => 0, "legacy" => 0]]]]], "2026-09-24");
  printf("der 22. am 24.: zählbar %d, älter %s\n", count($t["countable"]), isset($t["earlier"]) ? count($t["earlier"]) : "(kein Topf)");
'

# 1 · Die Erklärung des Formats steht vor ihrem Gebrauch, und nginx nimmt beides an
grep -n 'log_format srvpanel\|include /etc/nginx/srvpanel.d' /etc/nginx/conf.d/srvpanel-sites.conf
nginx -t 2>&1 | tail -1

# 2 · Jeder Server-Block nennt das Format — gezählt ohne `access_log off`
printf 'access_log ohne off: %s   davon im Format srvpanel: %s\n' \
    "$(grep -h '^[[:space:]]*access_log ' /etc/nginx/srvpanel.d/*.conf | grep -vc 'access_log off;')" \
    "$(grep -h '^[[:space:]]*access_log .* srvpanel;' /etc/nginx/srvpanel.d/*.conf | wc -l)"

# 3 · Die letzte Zeile jedes Protokolls: zwei Zahlen hinter dem User-Agent heisst neues Format
for f in /var/www/vhosts/*/logs/*/access.log; do
  printf '  %-72s %s\n' "$f" \
    "$(tail -1 "$f" | awk -F'"' '{z = (split($7, b, " ") == 2) ? "neu" : "ALT"} END {print z ? z : "leer"}')"
done

# 4 · Die Rotation: wie ihr Timer steht und wann er zuletzt gefeuert hat
systemctl cat logrotate.timer | grep -E '^(OnCalendar|AccuracySec|RandomizedDelaySec|Persistent)='
systemctl show logrotate.timer -p LastTriggerUSec -p NextElapseUSecRealtime
```

**Erwartet:** eine Fassung; `OnCalendar=daily`, `Persistent=true`,
`RandomizedDelaySec=1h`, der Timer `enabled` und `active`; beide Tabellen `da`.
In Block 0b `drei Dateien: ja   gepackt lesbar: ja` und
`der 22. am 24.: zählbar 0, älter 1`. In Block 1 zwei Zeilen, **die Erklärung
mit der kleineren Zeilennummer**, und `test is successful`. In Block 2 **zwei gleiche Zahlen** über null, in Block 3
kein `ALT`. In Block 4 `OnCalendar=daily` und `AccuracySec=1h`, die letzte
Auslösung in der vergangenen Nacht.

**Weichen Block 2 oder 3 ab, schreibt noch ein Server-Block das alte Format.**
Dann gehört `srvpanel vhost --sites` davor, und zwar **am Tag X**, also vor der
Mitternacht, mit der der gemessene Tag beginnt: `postinstall` fährt es nicht
(`docs/129 §5`). Der Tag X wird damit zum Übergangstag, und Punkt 3 misst ihn.

**Steht in Block 0b ein `NEIN` oder `(kein Topf)`, läuft eine Fassung ohne die
Behebung, und der Lauf wird verschoben.** Gegen sie fielen die Punkte 4 und 5 am
Prüfling aus, und das ist seit §0 Punkt 2 bekannt. Im Container gegen beide
Stände gemessen: behoben `ja · ja` und `zählbar 0, älter 1`, der Stand davor
(`46911fa8`) `NEIN · ja` und `zählbar 1, älter (kein Topf)`.

**`gepackt lesbar` steht da, weil `/opt/srvpanel/bin/php` nicht zwingend
`php8.4-cli` startet** — der Umschlag nimmt zuerst `/opt/srvpanel/php/bin/php`,
wenn es das gibt. In `php8.4-cli` ist zlib eingebaut, auch mit `-n`. Die
Gegenprobe ist deshalb im selben Prozess gefahren, mit abgemeldetem Datenstrom:
Die Zeile sagt dann `NEIN`, und `AccessLog::countFile()` gibt für eine gepackte
Datei null Zeilen und null unlesbare — daneben nur zwei Warnungen von PHP.

**Seit dem 24. September verweigert die Zählung in diesem Fall, statt still
halb zu zählen.** Über den echten Socket gemessen endet `srvpanel:traffic` dann
mit `Zählung scheiterte: Dem PHP des Agenten fehlt zlib …` und rc=1; derselbe
Agent mit dem Datenstrom zählt und endet mit rc=0. Ein `NEIN` hier wäre also
auch in Punkt 6 zu sehen — hier steht es, bevor eine Nacht darauf wartet.

> **Ein Befehl, der schweigt, sieht aus wie einer, der nichts gefunden hat.**
> Ein `srvpanel tinker`, das gar nichts druckt, ist kein leeres Ergebnis, sondern
> ein nicht gelaufener Block (`docs/133 §1`).

---

## §2 · Die Domain und die Werkzeuge

**Gebraucht wird eine Domain, deren Server-Block das neue Format schreibt, deren
Zertifikat gilt und deren Dokumentenverzeichnis unter `/var/www/vhosts` liegt.**
Die Wurzel wird bei nginx nachgefragt und nicht aus dem Namen abgeleitet — und
**der erste `root` eines Server-Blocks ist nie der der Domain**, sondern das
Prüfverzeichnis von ACME (`docs/133 §2`, dort auf dem Server bezahlt). Die
Schleife nennt, wie viele Blöcke sie angesehen hat und wie viele in Frage kamen,
bevor sie wählt: Eine Schleife, die nichts ausgibt, hat nicht nichts gefunden.

**Und `ssl_verify_result = 0` allein heisst nicht „gültig".** Gemessen mit
curl im Container: ohne Verbindung `000:0`, gültige Kette mit falschem Namen
`000:1`, gültige Kette mit richtigem Namen `200:0`. Die Null im ersten Fall
sagt, dass gar nichts geprüft wurde — deshalb fragt die Schleife den Code mit.

Gefahren ist sie im Container gegen drei mit `SiteTemplate::render()` erzeugte
Blöcke — einer passend, einer ohne Verbindung, einer im alten Format —, mit
einem Ersatz für curl, der die gemessenen Antworten gibt: gewählt wird der
erste, die beiden anderen fallen heraus, und die Schlusszeile nennt `Angesehen:
3   in Frage: 1`.

```bash
DOM=""; WURZEL=""; LOGS=""; ANGESEHEN=0; IN_FRAGE=0

for f in /etc/nginx/srvpanel.d/*.conf; do
  [ -f "$f" ] || continue
  ANGESEHEN=$((ANGESEHEN + 1))

  n=$(awk '$1=="server_name"{gsub(/;/,""); print $2; exit}' "$f")

  # Die erste Wurzel unter /var/www/vhosts — nicht die erste im Block.
  r=$(awk '$1=="root"{gsub(/;/,"",$2); if (index($2,"/var/www/vhosts/")==1) {print $2; exit}}' "$f")

  # Das erste access_log, das nicht `off` ist: ACME und Punktdateien schalten ab.
  l=$(awk '$1=="access_log" && $2!="off;"{gsub(/;/,""); print $2, $3; exit}' "$f")
  pfad=${l%% *}; format=${l#"$pfad"}; format=${format# }

  # 000 heisst: keine Verbindung — und dann ist auch ssl_verify_result 0, weil
  # gar nichts geprüft wurde. Ein gültiges Zertifikat braucht beides.
  m=$(curl -s --noproxy '*' --resolve "$n:443:127.0.0.1" -o /dev/null -m 10 \
        -w '%{http_code}:%{ssl_verify_result}' "https://$n/" 2>/dev/null)
  case "$m" in 000:*|'') tls=nein ;; *:0) tls=ja ;; *) tls=nein ;; esac

  printf '  %-28s format=%-9s tls=%-5s %s\n' "$n" "${format:-(keins)}" "$tls" "${r:-(keine Wurzel)}"

  if [ "$format" = srvpanel ] && [ "$tls" = ja ] && [ -n "$r" ]; then
    IN_FRAGE=$((IN_FRAGE + 1))
    [ -z "$DOM" ] && { DOM=$n; WURZEL=$r; LOGS=$(dirname "$pfad"); }
  fi
done

printf 'Angesehen: %s   in Frage: %s   gewählt: %s\n' "$ANGESEHEN" "$IN_FRAGE" "${DOM:-KEINE}"

# Ohne Kandidat wird nichts abgelegt — die Punkte darunter lesen diese Datei.
if [ -n "$DOM" ]; then
  ABO=$(basename "$(dirname "$(dirname "$LOGS")")")
  printf 'DOM=%s\nABO=%s\nWURZEL=%s\nLOGS=%s\n' "$DOM" "$ABO" "$WURZEL" "$LOGS" > /root/b2-lauf.env
  cat /root/b2-lauf.env
fi
```

**`--noproxy '*'` steht dort nicht aus Vorsicht.** `--resolve` wirkt nicht, wenn
eine Umgebungsvariable einen Proxy setzt: curl fragt dann den Proxy nach dem
Namen, und der Code ist `000`. Im Container so gemessen; auf dem Server kostet
die Option nichts.

**Die Rotation dieses Abonnements**, gelesen und nicht erinnert — gegen sie wird
Punkt 7 gehalten:

```bash
. /root/b2-lauf.env
cat "/etc/logrotate.d/srvpanel-$ABO"
```

**Erwartet** die Vorlage aus `WebLogrotate::template()`: `daily`, `rotate 14`,
`missingok`, `notifempty`, `compress`, `delaycompress`, `sharedscripts`, `su root
adm`, `create 0640 <benutzer> adm` und im `postrotate` das `kill --signal=USR1`
an nginx — und **kein `nocreate`** (`docs/129 §5`, die tote Zeile).

**Die Prüfdatei** liegt im Dokumentenverzeichnis, gehört dessen Eigentümer und
ist tausend Bytes lang, damit sich der Rumpf in jeder Summe wiederfindet:

```bash
. /root/b2-lauf.env
P="$WURZEL/b2-probe.txt"
head -c 1000 /dev/zero | tr '\0' 'x' > "$P"
chown "$(stat -c %U "$WURZEL"):$(stat -c %G "$WURZEL")" "$P"
chmod 644 "$P"
ls -l "$P"
```

**Vier Werkzeuge**, alle unter `/root`. Der Lastgeber schreibt ins Buch, die
beiden Prüfer lesen die Protokolle, und der vierte fragt die Ablage. Keiner
schreibt selbst in die Protokolle oder in die Datenbank — Zeilen entstehen nur,
weil nginx die Anfragen des Lastgebers protokolliert.

```bash
cat > /root/b2-last.sh <<'SH'
#!/bin/bash
# Nummerierte Anfragen an eine Domain, jede mit dem, was curl gezählt hat.
# Jede zehnte ist ein DELETE — auf eine statische Datei gibt das 405, und der
# Tag trägt damit Fehler, gegen die `errors` etwas misst.
# Aufruf: b2-last.sh <Domain> <Ende als Epoch-Sekunde> <Takt in Sekunden>
set -u
DOM=$1; ENDE=$2; TAKT=$3; BUCH=${BUCH:-/root/b2-buch.tsv}
n=0; [ -f "$BUCH" ] && n=$(wc -l < "$BUCH")
while [ "$(date +%s)" -lt "$ENDE" ]; do
  n=$((n + 1)); nr=$(printf '%05d' "$n")
  m=GET; [ $((n % 10)) -eq 0 ] && m=DELETE
  w=$(curl -s --http1.1 --noproxy '*' --resolve "$DOM:443:127.0.0.1" -o /dev/null -m 10 -X "$m" \
        -w '%{http_code}\t%{size_header}\t%{size_download}\t%{size_request}' \
        "https://$DOM/b2-probe.txt?b2=$nr")
  printf '%s\t%s\t%s\t%s\n' "$nr" "$(date +%FT%T%z)" "$m" "$w" >> "$BUCH"
  sleep "$TAKT"
done
SH

cat > /root/b2-nachzaehlen.sh <<'SH'
#!/bin/bash
# Zählt einen Tag über ALLE Dateien einer Domain nach — ohne den Nachtlauf und
# ohne seinen Leser. Aufruf: b2-nachzaehlen.sh <Protokollverzeichnis> <JJJJ-MM-TT>
set -u
LOGS=$1; TAG=$2
STEMPEL=$(LC_ALL=C date -d "$TAG" +%d/%b/%Y)   # so schreibt nginx den Tag: 22/Sep/2026
echo "Klammer davor:";  stat -c '  %i %10s %y %n' "$LOGS"/access.log*
for f in "$LOGS"/access.log*; do
  printf '  %-20s %6s Zeile(n) vom %s\n' "$(basename "$f")" "$(zcat -f "$f" | grep -c "\[$STEMPEL:")" "$TAG"
done
zcat -f "$LOGS"/access.log* | awk -F'"' -v t="[$STEMPEL:" '
  index($1, t) {
    split($3, a, " "); split($7, b, " ")
    if (b[1] != "" && b[2] != "") { n++; s += b[1]; r += b[2]; if (a[1] >= 400) e++ } else alt++
  }
  END { printf "Nachgezählt %s  requests %d  traffic_sent_bytes %d  traffic_received_bytes %d  errors %d  (altes Format: %d)\n", substr(t, 2), n, s, r, e, alt }'
echo "Klammer danach:"; stat -c '  %i %10s %y %n' "$LOGS"/access.log*
SH

cat > /root/b2-buch-pruefen.sh <<'SH'
#!/bin/bash
# Hält die nummerierten Zeilen eines Tages gegen das, was curl auf der anderen
# Seite gezählt hat — und prüft, dass keine Anfrage fehlt oder doppelt steht.
# Aufruf: b2-buch-pruefen.sh <Protokollverzeichnis> <JJJJ-MM-TT>
set -u
LOGS=$1; TAG=$2; BUCH=${BUCH:-/root/b2-buch.tsv}; ZW=${ZW:-/root/b2-tag.txt}
STEMPEL=$(LC_ALL=C date -d "$TAG" +%d/%b/%Y)
zcat -f "$LOGS"/access.log* | awk -F'"' -v t="[$STEMPEL:" '
  index($1, t) && match($2, /b2=[0-9]+/) {
    split($3, a, " "); split($7, b, " ")
    print substr($2, RSTART + 3, RLENGTH - 3), a[1], b[1], b[2]
  }' | sort > "$ZW"
awk 'NR == FNR { c[$1] = $4; k[$1] = $5 + $6; q[$1] = $7; next }
     ($1 in k) { n++; s += $3; r += $4; ks += k[$1]; kq += q[$1]; if ($2 >= 400) e++; if ($2 != c[$1]) anders++; next }
     { fremd++ }
     END { printf "Nummerierte Zeilen vom %s: %d, davon Fehler: %d\n", tag, n, e
           printf "  gesendet  laut Protokoll %d  laut curl %d\n", s, ks
           printf "  empfangen laut Protokoll %d  laut curl %d\n", r, kq
           printf "  Status anders als bei curl: %d   ohne Buchzeile: %d\n", anders, fremd }' \
     tag="$TAG" FS='\t' "$BUCH" FS=' ' "$ZW"
printf '  doppelt im Protokoll: %d\n' "$(cut -d' ' -f1 "$ZW" | uniq -d | wc -l)"
IM_PROTOKOLL=$(zcat -f "$LOGS"/access.log* | grep -o 'b2=[0-9]*' | cut -d= -f2 | sort -u)
printf '  im Buch und in keiner Datei (über alle Tage): %d von %d\n' \
  "$(cut -f1 "$BUCH" | sort -u | comm -23 - <(printf '%s\n' "$IM_PROTOKOLL") | grep -c .)" "$(wc -l < "$BUCH")"
SH

cat > /root/b2-zeile.sh <<'SH'
#!/bin/bash
# Die Tageszeile einer Domain aus der Ablage. Die Werte reisen über die Umgebung
# und nicht über eine Datei: `srvpanel tinker` läuft nach seinem setpriv als
# srvpanel und liest nichts unter /root.
# Aufruf: b2-zeile.sh <Domain> <JJJJ-MM-TT>
set -u
B2_DOM=$1 B2_TAG=$2 srvpanel tinker --execute='
  $dom = App\Models\Domain::withoutGlobalScopes()->where("name", getenv("B2_DOM"))->first();
  if ($dom === null) { printf("Domain nicht gefunden: %s\n", getenv("B2_DOM")); return; }
  $z = App\Models\DomainMetric::withoutGlobalScopes()->where("domain_id", $dom->id)
      ->whereDate("day", getenv("B2_TAG"))->get()
      ->mapWithKeys(fn ($m) => [$m->metric->value => $m->value]);
  printf("Abgelegt %s am %s: %d Zeile(n)\n", $dom->name, getenv("B2_TAG"), $z->count());
  printf("  requests %d  traffic_sent_bytes %d  traffic_received_bytes %d  errors %d   (-1 = keine Zeile)\n",
      $z["requests"] ?? -1, $z["traffic_sent_bytes"] ?? -1, $z["traffic_received_bytes"] ?? -1, $z["errors"] ?? -1);
'
SH

chmod 700 /root/b2-last.sh /root/b2-nachzaehlen.sh /root/b2-buch-pruefen.sh /root/b2-zeile.sh
ls -l /root/b2-*.sh
```

**Alle vier sind im Container gefahren, die drei Prüfer mit Gegenprobe.** Das
Buch gegen die Zeilen eines Wegwerf-nginx mit dem echten Format: zwölf
Anfragen, eine davon ein `DELETE`, gesendet `14 071` gegen `14 071`, empfangen
`1 155` gegen `1 155`. Dann fünf Eingriffe, jeder einzeln, und jeder schlägt an
seiner Zeile an:

| Eingriff | angeschlagen |
|---|---|
| eine Buchzeile gestrichen | `ohne Buchzeile: 1` |
| eine Protokollzeile verdoppelt | `doppelt im Protokoll: 1` |
| ein Status im Buch verfälscht | `Status anders als bei curl: 1` |
| eine Buchzeile ohne Anfrage | `im Buch und in keiner Datei: 1 von 13` |
| eine Zeile im alten Format | `(altes Format: 1)` |

`b2-zeile.sh` gegen eine Wegwerf-Datenbank: vier Zeilen für einen Tag mit Werten,
`-1` für einen ohne, `Domain nicht gefunden` für einen Namen, den es nicht gibt —
und **ohne** `withoutGlobalScopes()` null Zeilen für den Tag mit Werten. Die
Mandantenklammer antwortet im Grundzustand mit einer leeren Liste und nicht mit
einem Fehler; `-1` statt `0` steht dort, damit eine fehlende Zeile nicht wie eine
gezählte Null aussieht.

**Der Ladebeleg:** eine nummerierte Anfrage, und sie steht im Protokoll — mit den
beiden Zahlen am Ende.

```bash
. /root/b2-lauf.env
/root/b2-last.sh "$DOM" "$(( $(date +%s) + 1 ))" 1
NR=$(tail -1 /root/b2-buch.tsv | cut -f1)
tail -1 /root/b2-buch.tsv
grep "b2=$NR " "$LOGS/access.log"
```

**Erwartet:** eine Buchzeile `… GET 200 <kopf> 1000 <anfrage>` und genau eine
Protokollzeile, die auf `<kopf + 1000> <anfrage>` endet. Steht dort `000` oder
keine Zeile, misst jeder Punkt darunter den Lastgeber und nicht den Prüfling.

---

## §3 · Der Zeitplan

**Gemessen wird ein ganzer Tag, D, und er beginnt um Mitternacht.** Aufgebaut
wird am Abend davor (Tag X), gelesen an den beiden Morgen danach.

| wann | was |
|---|---|
| **Tag X, abends** | §1, §2, Punkt 1; die beiden Lastgeber einplanen |
| **X, 23:59:30** bis kurz nach der Rotation | Lastgeber `b2-mitternacht`, eine Anfrage je Sekunde — **der Kopf von D** |
| **D, 00:00–01:00** | logrotate und `srvpanel-traffic` feuern, jeder irgendwo in dieser Stunde — nichts zu tun |
| **D, 12:00–12:05** | Lastgeber `b2-mittag`, alle zehn Sekunden — der Rumpf von D |
| **D+1, morgens** (nach 01:30) | Punkte 2, 3, 4, 6, 7 und **5a** |
| **D+1, abends** (nach 22:00, vor Mitternacht) | **Punkt 5b** |
| **D+2, morgens** (nach 01:30) | Punkt 2 für die zweite Nacht, **5c**, zum Schluss Punkt 8 |

**Der Lastgeber über Mitternacht läuft bis fünf Minuten nach der Stelle, an der
logrotate in der vergangenen Nacht gefeuert hat.** `systemd.timer(5)` nennt die
Stelle, an die `AccuracySec=1h` den Timer legt, stabil; das ist die Zusage der
Dokumentation, gemessen wird sie jede Nacht (Punkt 2). Fehlt die letzte
Auslösung, deckt der Lastgeber das ganze Fenster ab.

```bash
. /root/b2-lauf.env
X=$(date +%F); D=$(date -d "$X + 1 day" +%F)

# `--timestamp=unix` wirkt auf `show` nicht (§0 Punkt 6); `date -d` liest die
# ausgeschriebene Form samt Zonenkürzel.
LETZTE=$(systemctl show logrotate.timer -p LastTriggerUSec --value)
if [ -n "$LETZTE" ]; then
  L=$(date -d "$LETZTE" +%s)
  VERSATZ=$(( L - $(date -d "$(date -d "@$L" +%F) 00:00" +%s) ))
else
  VERSATZ=3600    # keine letzte Auslösung: das ganze Fenster abdecken
fi
ENDE=$(( $(date -d "$D 00:00" +%s) + VERSATZ + 300 ))
printf 'X=%s  D=%s  letzte Rotation: %s  Versatz %s s  Lastgeber bis %s\n' \
    "$X" "$D" "${LETZTE:-keine}" "$VERSATZ" "$(date -d "@$ENDE" '+%F %T %Z')"

systemd-run --unit=b2-mitternacht --on-calendar="$X 23:59:30" --timer-property=AccuracySec=1s \
    /bin/bash /root/b2-last.sh "$DOM" "$ENDE" 1
systemd-run --unit=b2-mittag --on-calendar="$D 12:00:00" --timer-property=AccuracySec=1s \
    /bin/bash /root/b2-last.sh "$DOM" "$(date -d "$D 12:05" +%s)" 10

printf 'X=%s\nD=%s\n' "$X" "$D" >> /root/b2-lauf.env
systemctl list-timers 'b2-*' --all --no-pager
```

**Erwartet:** zwei Timer mit ihren Terminen. Beide Lastgeber schreiben in
**dasselbe** Buch und laufen nie gleichzeitig — die Nummer ergibt sich aus der
Zahl der Buchzeilen beim Start, und zwei gleichzeitige Läufe vergäben dieselben
Nummern zweimal.

Im Container gefahren gegen systemd 255 als PID 1: Der Timer feuerte in der
angesetzten Sekunde, der Lastgeber schrieb sieben Zeilen und endete mit
`Result=success` — und danach war der Timer fort (§0 Punkt 6). Wer am Morgen
wissen will, ob er gelaufen ist, liest das Buch:

```bash
awk -F'\t' '{print substr($2, 1, 10)}' /root/b2-buch.tsv | sort | uniq -c
journalctl -u b2-mitternacht.service -u b2-mittag.service -o short-iso --no-pager \
    | grep -E 'Start|Finished|Deactivated|Failed'
```

Gesucht wird nach allen vier Wörtern: Welche davon systemd schreibt, hängt an
der Art der Einheit, und die ist für eine transiente hier nicht nachgesehen.

**Die Blöcke dieses Dokuments sind im Container gefahren, wörtlich wie sie hier
stehen** — aus dieser Datei gezogen und nicht abgeschrieben. Gegen das echte
nginx 1.24 mit einem PHP-Block aus `SiteTemplate::render()`, umbenannt auf
`localhost`, weil der Container das vorhandene Snakeoil-Zertifikat für diesen
Namen kennt; Schlüssel ist dafür keiner entstanden. `srvpanel` war durch einen
Ersatz vertreten, der `tinker` an eine Wegwerf-Datenbank reicht.

| Block | gemessen |
|---|---|
| §1, Block 0b | behoben `ja · ja` und `zählbar 0, älter 1`, der Stand davor `NEIN · ja` und `zählbar 1, älter (kein Topf)` — mit `php` statt `/opt/srvpanel/bin/php` und `artisan tinker` statt `srvpanel tinker` |
| §1, Blöcke 1–3 | Erklärung in Zeile 4, `include` in Zeile 9; `2` gegen `2`; `leer` |
| §2, Schleife | `Angesehen: 1   in Frage: 1   gewählt: localhost` |
| §2, Ladebeleg | Buch `249 + 1000`, Protokoll `1249`; Anfrage `93` gegen `93` |
| Punkt 1 | dreimal `stimmt` — `1249`, `189`, `332` |
| Punkt 4 | die echte Kette (`overRoot()` → `split()` → `record()`) in die Wegwerf-Datenbank: Zeile `20 · 21163 · 1872 · 3`, Nachzählung dieselben vier |
| Punkt 6 | die Ausgabe von `srvpanel:traffic` gegen einen Agenten ohne Domains, über den echten Socket: eine Zeile „Laufender Tag", die Zeile der vier Töpfe, `rc=0` |
| Punkt 7 | nach einem echten logrotate mit der Vorlage: `-rw-r----- www-data:adm`, `.1` ungepackt, die neue Anfrage `1`-mal in `access.log` |

**Die Zeile zu Punkt 4 und der Absatz darunter sind vor der Behebung gemessen**,
am selben Tag; nach ihr ist der Prüfstand mit dem Webserver nicht noch einmal
aufgebaut worden. Die behobene Kette bis in die Datenbank fährt
`TrafficRotationTest`, mit dem echten logrotate der Nachbau (§0 Punkt 2).

**Und der zweite Weg aus §0 Punkt 2 mit dem echten Webserver:** zwei Rotationen
am selben Kalendertag, nach jeder eine Sicht. Nach der ersten stehen Zeile und
Nachzählung bei 21, nach der zweiten steht die Zeile bei **1** — der Rest liegt
in `access.log.2.gz` — und die Nachzählung weiter bei 21. Der Block aus Punkt 4
stellt beides untereinander.

**Nicht gefahren** ist, was systemd als PID 1 oder das echte `srvpanel` braucht:
§1 Block 0 bis auf 0b, Block 4, der Zeitplan oben, das Journal in Punkt 2 und 6,
das `systemctl start` in Punkt 5. Die Griffe an systemd darin sind einzeln gemessen
(§0 Punkt 6). Das `postrotate` lief als `nginx -s reopen`, weil es ohne systemd
kein `systemctl kill` gibt; ob das `USR1` auf dem Server wirkt, misst Punkt 7.

**Zwischen Tag X und dem Morgen von D+2 fasst niemand den Nachtlauf an** — kein
`srvpanel traffic` von Hand ausser in Punkt 5, kein `logrotate -f`, kein
`srvpanel vhost --sites`. Jeder davon ist eine weitere Sicht auf den Tag D oder
verschiebt seine Grenze, und dann misst Punkt 4 die Hand und nicht die Nacht.

---

## §4 · Die Punkte

**Jeder Punkt fängt mit derselben Zeile an**, und mehrere liegen Tage
auseinander:

```bash
. /root/b2-lauf.env
```

Sie setzt `DOM`, `ABO`, `WURZEL`, `LOGS` und ab §3 auch `X` und `D`.

### Punkt 1 · Was über die Leitung ging, steht in der Zeile

Am Tag X. Drei Anfragen, deren Verhältnis von Kopf und Rumpf verschieden ist:
ein voller Abruf, ein Wiederbesuch mit `304` und ein Fehler.

```bash
. /root/b2-lauf.env
C=(-s --http1.1 --noproxy '*' --resolve "$DOM:443:127.0.0.1" -o /dev/null -m 10)
U="https://$DOM/b2-probe.txt"
ETAG=$(curl "${C[@]}" -D - "$U?draht=0" | awk -F': ' 'tolower($1)=="etag"{print $2}' | tr -d '\r')
W='%{http_code} %{size_header} %{size_download} %{size_request}\n'
{
  printf 'draht=1 '; curl "${C[@]}" -w "$W" "$U?draht=1"
  printf 'draht=2 '; curl "${C[@]}" -w "$W" -H "If-None-Match: $ETAG" "$U?draht=2"
  printf 'draht=3 '; curl "${C[@]}" -w "$W" -X DELETE "$U?draht=3"
} > /root/b2-draht.txt
sleep 1
awk -F'"' 'match($2, /draht=[123] /) { split($3, a, " "); split($7, b, " ");
             print substr($2, RSTART, RLENGTH - 1), a[1], a[2], b[1], b[2] }' "$LOGS/access.log" \
  | awk 'NR == FNR { c[$1] = $2; k[$1] = $3 + $4; q[$1] = $5; next }
         { printf "%s  Status %s/%s  Rumpf %s  gesendet %s/%s  empfangen %s/%s  %s\n",
                  $1, $2, c[$1], $3, $4, k[$1], $5, q[$1],
                  ($2 == c[$1] && $4 == k[$1] && $5 == q[$1]) ? "stimmt" : "WEICHT AB" }' /root/b2-draht.txt -
```

**Erwartet:** drei Zeilen `stimmt` — `200`, `304` und `405`, jeweils das Paar
*Protokoll/curl*. `gesendet` ist Kopf **und** Rumpf, `empfangen` die Anfrage
samt Kopfzeilen. Im Container am gerenderten PHP-Block gemessen:

| | curl: Kopf + Rumpf | Protokoll: gesendet | Anfrage | alter Rumpf-Wert |
|---|---|---|---|---|
| `200` | 249 + 1000 | 1249 | 95 | 1000 |
| `304` | 189 + 0 | **189** | 126 | **0** |
| `405` | 166 + 166 | 332 | 98 | 166 |

Die Spalte rechts ist, was `combined` allein geschrieben hätte — bei `304` eine
Null (`docs/128` M2). Auf dem Server sind die Zahlen andere, weil die
Kopfzeilen dort andere sind; gemessen wird die **Gleichheit**, nicht der Wert.

### Punkt 2 · Wann in jeder Nacht die Rotation lief und wann der Zähllauf

An beiden Morgen, D+1 und D+2. **Dieser Punkt ist kein Kriterium, aber ohne ihn
ist Punkt 4 nicht zu lesen**: Ob der Zähllauf vor oder nach der Rotation kam,
entscheidet, welche Sicht auf den Tag er hatte.

```bash
. /root/b2-lauf.env
M=$(date +%F)
journalctl -u logrotate.service -u srvpanel-traffic.service --since "$M 00:00" --until "$M 01:30" \
    -o short-iso --no-pager | grep -E ' (Starting|Finished) '
stat -c '%w  geboren  %n' "$LOGS/access.log" "$LOGS/access.log.1"
```

**Erwartet:** je ein `Starting` und `Finished` beider Einheiten, alle vier
zwischen 00:00 und 01:10. Die Geburt von `access.log` ist der Augenblick, in dem
logrotate die Datei dieser Nacht angelegt hat (`create`), die von
`access.log.1` der der Nacht davor — ein Umbenennen ändert sie nicht.

**Abgelesen wird die Reihenfolge:** *nach*, wenn `srvpanel-traffic` nach der
Geburt von `access.log` startete; *vor*, wenn es davor endete; *überlappend*
sonst. Zeigt `stat` statt der Geburt ein `-`, kennt das Dateisystem sie nicht;
dann gilt das `Finished` von logrotate als obere Grenze. Die beiden Nächte
gehören ins Protokoll, weil §0 Punkt 2 für jede der vier Folgen etwas anderes
vorhersagt.

### Punkt 3 · Ein Tag mit altem Format bekommt keine Zeile

Am Morgen von D+1, falls es einen Übergang gab — am Tag X, wenn §1 `srvpanel
vhost --sites` verlangt hat, oder früher, solange das Journal zurückreicht.

```bash
U=$(journalctl -u srvpanel-traffic.service --since 2026-09-20 -o cat --no-pager \
      | sed -n 's#.*übersprungen: [^ ]* / \([^ ]*\) am \([0-9-]*\) .*#\1 \2#p' | sort -u)
printf '%s\n' "${U:-(im Journal kein Tag übersprungen)}"
B2_U="$U" srvpanel tinker --execute='
  foreach (array_filter(explode("\n", (string) getenv("B2_U"))) as $z) {
      [$name, $tag] = explode(" ", $z);
      $dom = App\Models\Domain::withoutGlobalScopes()->where("name", $name)->first();
      $zeilen = fn (string $t) => $dom === null ? -1 : App\Models\DomainMetric::withoutGlobalScopes()
          ->where("domain_id", $dom->id)->whereDate("day", $t)->count();
      printf("  %-32s %s  Zeilen: %d   am Tag danach: %d\n", $name, $tag, $zeilen($tag),
          $zeilen(date("Y-m-d", strtotime($tag." +1 day"))));
  }
'
```

**Erwartet:** je übersprungenem Tag **null** Zeilen — und am Tag danach vier,
wenn der schon im neuen Format stand. Die Vier ist die Gegenprobe: Ohne sie
hiesse die Null auch „diese Domain wird gar nicht abgelegt".

**Dieser Punkt darf ausfallen**, wenn es im Lauf keinen Übergang gab und das
Journal nicht mehr zurückreicht; `TrafficEraTest` und `LogEraTest` halten die
Regel an gemessenen Zeilen beider Formen.

### Punkt 4 · Die Tageszeile ist die Nachzählung über alle Dateien

Am Morgen von D+1, nach Punkt 2. **Das ist das Kriterium.**

```bash
. /root/b2-lauf.env
/root/b2-nachzaehlen.sh "$LOGS" "$D"
/root/b2-buch-pruefen.sh "$LOGS" "$D"
/root/b2-zeile.sh "$DOM" "$D"
```

**Erwartet, in dieser Reihenfolge:**

- Die **Klammer** davor und danach gleich für `.1` und `.2.gz`; `access.log`
  darf wachsen, es trägt keine Zeile vom D mehr.
- Je Datei die Zeilen vom D: `access.log` **0**, `access.log.1` der Rumpf,
  `access.log.2.gz` der Kopf — **mindestens eine**. Steht dort eine Null, hat
  der Tag keinen Kopf, und dieser Punkt misst nichts; er wird um einen Tag
  verschoben und nicht abgehakt.
- `(altes Format: 0)`.
- Im Buch: gesendet und empfangen laut Protokoll **gleich** laut curl, `Status
  anders 0`, `ohne Buchzeile 0`, `doppelt 0`, `im Buch und in keiner Datei 0`.
  Damit ist belegt, dass nginx jede Anfrage genau einmal und richtig
  geschrieben hat; was die Nachzählung darüber hinaus zählt, ist fremder
  Verkehr und zählt mit.
- **Die vier Zahlen der Tageszeile gleich den vier Zahlen der Nachzählung.**

**Mit der Behebung stimmt die Zeile, gleich ob der Zähllauf vor oder nach der
Rotation kam**; Punkt 2 sagt, welche der beiden Sichten die Nacht geliefert hat.
**Gegen eine Fassung ohne sie** — Block 0b sagt dann `NEIN` — sagt §0 Punkt 2
das Ergebnis voraus: Kam der Zähllauf **nach** der Rotation, ist die Tageszeile
die Nachzählung **ohne den Kopf** — um genau die Zeilen, die in
`access.log.2.gz` stehen, und auf das Byte um deren Summe. Kam er davor, stimmt
die Zeile, und Punkt 5a nimmt es ihr.

### Punkt 5 · Keine weitere Sicht ändert die Zeile

**Drei weitere Sichten, und zwei davon stellt der Lauf selbst her**, statt auf
die Nacht zu warten, die sie zufällig liefert. Die erste Nacht hatte eine der
beiden Sichten, die es auf den Vortag gibt — vor oder nach der Rotation;
5a und 5b stellen beide her, solange D noch der Vortag ist. 5c ist die zweite
Nacht. Mit der Behebung ist D dort nicht mehr der Vortag und bleibt stehen;
ohne sie sähe sie ihn in mindestens 92 von 100 Nächten gar nicht und sonst
ohne Kopf.

Gestartet wird **die Einheit** und nicht das Kommando: Sie läuft als
`srvpanel`, mit derselben Umgebung und denselben Schranken wie in der Nacht.
Ein Prüfkörper, der den Zustand auf einem anderen Weg herstellt als der
Prüfling, stellt einen anderen Zustand her.

**Der Start zählt jede Domain des Servers**, nicht nur diese, und schreibt
ihren Vortag noch einmal. Mit der Behebung ist das dieselbe vollständige Zahl,
die die Nacht geschrieben hat; ohne sie die Sicht, die die meisten Nächte
ohnehin schreiben.

**5a · am Morgen von D+1, gleich nach Punkt 4** — die Sicht *nach* der Rotation:

```bash
. /root/b2-lauf.env
systemctl start srvpanel-traffic.service
systemctl show srvpanel-traffic.service -p Result -p ExecMainStatus
/root/b2-zeile.sh "$DOM" "$D"
```

**5b · am Abend von D+1, nach 22:00 und vor Mitternacht** — die Sicht *vor* der
nächsten Rotation. `access.log` trägt dann den Tag D+1, `.1` den Rumpf von D und
`.2.gz` seinen Kopf. Mit der Behebung ist das die späteste Sicht, in der D noch
der Vortag ist; ohne sie ist es Datei für Datei, was der Nachtlauf sähe, wenn er
in der zweiten Nacht vor logrotate käme:

```bash
. /root/b2-lauf.env
systemctl start srvpanel-traffic.service
systemctl show srvpanel-traffic.service -p Result -p ExecMainStatus
/root/b2-zeile.sh "$DOM" "$D"
```

**5c · am Morgen von D+2** — die Nacht selbst, und die Nachzählung noch einmal:

```bash
. /root/b2-lauf.env
/root/b2-nachzaehlen.sh "$LOGS" "$D"
/root/b2-zeile.sh "$DOM" "$D"
```

**Erwartet:** `Result=success`, und **alle drei Mal dieselben vier Zahlen wie
die Nachzählung aus Punkt 4**. In 5c steht der Rumpf dann in `.2.gz` und der Kopf
in `.3.gz`; die Summe ändert sich nicht. Mit der Behebung hat die zweite Nacht
die Zeile gar nicht angefasst — `.3.gz` liest sie nicht, und D ist für sie kein
Vortag mehr.

**Gegen eine Fassung ohne die Behebung** schlagen 5a und 5b in jedem Fall an, in
dem Punkt 4 noch stimmte: Beide Sichten lesen die Datei mit dem Kopf nicht. Das
ist der zweite Weg aus §0 Punkt 2, hergestellt statt abgewartet.

### Punkt 6 · Was der Nachtlauf druckt

Am Morgen von D+1.

```bash
. /root/b2-lauf.env
M=$(date +%F)
journalctl -u srvpanel-traffic.service --since "$M 00:00" --until "$M 01:30" -o cat --no-pager
```

**Erwartet:**

- **Genau eine** Zeile `Laufender Tag auf dem Server: <D+1> (<Zone>).` mit der
  Zone des Servers, und **keine** mit `(Zeitzone unbekannt)`. Steht die zweite
  da, läuft eine Fassung ohne die Behebung (§0 Punkt 3) — dann hätte schon
  Block 0b `NEIN` gesagt.
- `… Domain(s) gelesen, …`, darin `0 aus dem alten Zeitalter, 0 unlesbar`.
- `… Tageswert(e) vom Vortag zählbar, 0 übersprungen (gemischtes Format), …
  noch offen (laufender Tag), … älter und nicht erneut abgelegt.` Ein
  `übersprungen` hiesse, ein Block schrieb am D noch das alte Format. Die Zahl
  unter `älter` ist kein Befund: Es sind die Tage vor dem Vortag, die in den
  drei gelesenen Dateien noch stehen.
- `Abgelegt: …`, **keine** Zeile `ohne Zeile im Panel` und keine `blieben
  ungezählt`, am Ende `Fertig in … ms.`
- **Keine** Zeile `Zählung scheiterte`. Die mit „fehlt zlib" darin hiesse, dass
  das PHP des Agenten die gepackte Datei nicht lesen kann (Block 0b,
  `gepackt lesbar`).

### Punkt 7 · Die Rotation legt die neue Datei richtig an, und nginx schreibt hinein

Am Morgen von D+1.

```bash
. /root/b2-lauf.env
ls -l --time-style=+%FT%T "$LOGS"
stat -c '%A %U:%G  geboren %w  %n' "$LOGS/access.log"
/root/b2-last.sh "$DOM" "$(( $(date +%s) + 1 ))" 1
NR=$(tail -1 /root/b2-buch.tsv | cut -f1)
grep -c "b2=$NR " "$LOGS/access.log"
```

**Erwartet:** `access.log` als `-rw-r----- <benutzer>:adm` — derselbe Benutzer
wie im `create` aus §2 —, geboren in der vergangenen Nacht; daneben
`access.log.1` **unkomprimiert** (`delaycompress`) und ab `access.log.2.gz`
gepackt. Die neue Anfrage steht **einmal** in `access.log`: nginx hat nach dem
Umbenennen die neue Datei geöffnet, und das `kill --signal=USR1` im `postrotate`
hat gewirkt.

**Und die Anfrage hat einen zweiten Zweck.** Ohne sie trüge D+1 womöglich keine
Zeile; dann drehte logrotate in der zweiten Nacht nicht (`notifempty`, §0
Punkt 7), `.1` bliebe stehen, und 5c mässe eine Nacht, die es sonst nicht gibt.

### Punkt 8 · Die Maschine bleibt, wie sie war

Am Morgen von D+2, zum Schluss.

```bash
. /root/b2-lauf.env
systemctl stop b2-mitternacht.timer b2-mittag.timer 2>/dev/null
systemctl list-timers 'b2-*' --all --no-pager
rm -f "$WURZEL/b2-probe.txt" /root/b2-last.sh /root/b2-nachzaehlen.sh /root/b2-buch-pruefen.sh \
      /root/b2-zeile.sh /root/b2-tag.txt /root/b2-draht.txt
ls -l "$WURZEL/b2-probe.txt" /root/b2-*.sh 2>&1
```

**Erwartet:** `0 timers listed`, und zweimal `No such file or directory`. Das
Buch und `/root/b2-lauf.env` bleiben liegen, bis das Protokoll steht.

**Was bleibt und bleiben darf:** die Zeilen in den Protokollen der Domain — sie
rotieren in vierzehn Tagen fort — und die Tageszeilen in der Ablage. Die sind
richtig gezählt; was der Lauf erzeugt hat, ist Verkehr wie jeder andere, und nach
dreissig Tagen räumt ihn `Daily::forget()` ab.

---

## §5 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Dreissig Nächte und die Aufbewahrung.** Das ist B3 und sein eigenes
  Kriterium.
- **Die Kacheln.** Das ist B4.
- **Verkehr in Menge.** Der Lastgeber schreibt einige hundert Zeilen; den kalten
  Durchsatz der Platte (`docs/129 §10` Punkt 4) und das Budget von 120 s
  (`pending`) erreicht er nicht.
- **HTTP/2 und IPv6.** `SiteTemplate` schaltet HTTP/2 mit Absicht nicht ein; was
  `$bytes_sent` dort zählt, ist nicht gemessen. Der Lastgeber spricht IPv4 über
  die Schleife.
- **Eine ausgefallene Nacht.** `Persistent=true` holt einen verpassten Lauf nach
  dem Einschalten nach. Der Kommentar daneben begründete das bis zum
  24. September mit *„`access.log.1` liegt vierzehn Tage"* — es liegt einen Tag;
  vierzehn Tage liegen die gedrehten Dateien zusammen. Berichtigt mit der
  Behebung: Ein nachgeholter Lauf zählt den Vortag des Tages, an dem er läuft,
  und bleibt der Server einen ganzen Tag aus, bekommt der verpasste Tag keine
  Zahl (§0 Punkt 2). Gemessen ist der Fall nicht.
- **Eine Datei, die sich nicht öffnen lässt.** `WebAccessCount` nennt die Grenze
  selbst. Eine Ursache davon — ein PHP ohne zlib — weist die Zählung seit dem
  24. September ab, und Block 0b fragt sie vorher; die übrigen stellt hier
  niemand her.
- **Eine zweite Rotation am selben Tag**, etwa ein `logrotate -f` von Hand. Für
  sie ist die Behebung nicht gebaut (§0 Punkt 2).
- **Ein Lauf, der sich mit der Rotation überschneidet** (§0 Punkt 2). Herstellen
  lässt er sich nicht; Punkt 2 hält nur fest, ob er vorkam.
- **Was die Zahl grundsätzlich nicht zählt:** Anfragen an Punktdateien und an die
  ACME-Prüfadresse. Sie stehen in keinem Protokoll (§0 Punkt 5) und damit in
  keiner Tageszeile. Das ist entworfen und kein Mangel des Laufs — aber es gehört
  neben die Zahl, nicht in eine Fussnote.

---

## §6 · Wann er durch ist

**Das Kriterium, berichtigt** (§0 Punkt 1):

> Für eine Domain mit Verkehr über den echten Webserver steht am Morgen eine
> Tageszeile, deren Zahlen der Nachzählung über **alle** Dateien der Domain
> gleichen — für einen Tag, der mindestens eine Zeile vor der Rotation trägt, und
> nach jeder weiteren Sicht auf diesen Tag unverändert. `stat` davor und danach.

**Erfüllt, wenn die Punkte 1, 2, 4, 5, 6, 7 und 8 erfüllt sind.**

**Punkt 4 und alle drei Teile von Punkt 5 dürfen nicht ausfallen** — sie sind das
Kriterium. **Punkt 4 zählt nur mit Kopf:** Trägt `access.log.2.gz` am Morgen von
D+1 keine Zeile vom D, ist der Punkt nicht gemessen und wird mit dem nächsten Tag
wiederholt, nicht abgehakt.

**Punkt 2 darf nicht fehlen**, obwohl er kein Kriterium ist: Er sagt, welche der
beiden Sichten die erste Nacht hatte und welche 5a und 5b dazulegen. Und ohne ihn
wäre ein erfüllter Punkt 4 auch gegen eine Fassung ohne die Behebung möglich — in
den höchstens sieben Nächten von hundert, in denen sie richtig zählt.

**Punkt 3 darf ausfallen**, wenn es keinen Übergang gab und das Journal nicht
mehr zurückreicht.

**Block 0b darf kein `NEIN` und kein `(kein Topf)` zeigen.** Dann läuft der
falsche Prüfling, und der Lauf wird verschoben, nicht gefahren. Dasselbe gilt
für eine zweite Zeile `(Zeitzone unbekannt)` in Punkt 6: Sie stand bis zum
24. September in jeder Nacht und wurde damals als Befund geführt; seitdem heisst
sie, dass eine Fassung ohne die Behebung läuft.

**Punkt 8 darf nicht ausfallen, und er ist keine Messung, sondern eine Schuld.**
Der Lauf legt eine Datei in das Dokumentenverzeichnis eines Kunden und zwei
Timer auf den Server; wer ihn abbricht, holt wenigstens diesen Punkt nach.

**Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist nicht „nicht
herstellbar"** — er wird nachgeholt.

**Das Ergebnis kommt als §7 dazu**, mit den abgelesenen Zahlen und ohne die
Erwartungen darüber nachträglich anzupassen.
