# B1 — der Abnahmelauf

Ausgeschrieben am 21. September 2026, **vor** dem Fahren. Das Kriterium steht in
`docs/129 §9`:

> Ein herbeigeführter Zustand (Dienst gestoppt) erzeugt **eine** Meldung, die
> zweite Nacht erzeugt **keine**, und nach `systemctl start` meldet der Lauf
> nichts mehr. Über beide Kanäle, mit „zuletzt erfolgreich zugestellt" auf der
> Seite.

---

## §0 · Was beim Ausschreiben umgefallen ist

**Sechs Zeilen, und fünf davon hätten den Lauf verfälscht.**

**1 · Der Lauf braucht eine Freigabe, und die setzt der Betreiber.** Gemessen
wird die **installierte** Fassung und nicht die eingespielte (`docs/96 §1b`);
B1 liegt auf dem Zweig und ist nicht veröffentlicht. Schritt 0 gehört deshalb
dem Betreiber, und dieser Container kann ihn nicht abnehmen — ein annotierter
Tag ist von hier aus nicht zu setzen (zweimal HTTP 403 gemessen).

**2 · „Die zweite Nacht" ist die Entprellung und nicht der Kalender.**
`Notices::HOLD_HOURS` ist **20**, der Zeitgeber läuft `OnCalendar=daily` mit
einer Stunde Streuung. Ein Zustand, der um 14:00 entsteht, ist beim nächtlichen
Lauf um 00:30 erst **zehneinhalb** Stunden alt — dieser Lauf schweigt zu Recht,
und er sieht aus wie der, der gemeint war.

> **Ein Kriterium, das nach einer Nacht fragt, meint eine Frist — und welche
> Nacht sie trifft, entscheidet die Uhrzeit, zu der man anfängt.**

Der Lauf triggert deshalb selbst und misst die **Frist**, nicht den Kalender.
Dass der Zeitgeber die Unit wirklich startet, ist an anderer Stelle gemessen
(`docs/909`, Glied für Glied) und nicht Gegenstand hier.

**3 · Der erste Lauf schweigt nicht — und die erste Fassung dieses Dokuments
hat es behauptet.** `finding_notifications` ist auf diesem Server leer, also
ist **alles**, was länger als zwanzig Stunden dasteht, sofort fällig — auf
`cloudsrv24` mindestens der Rest aus `docs/125 §9`. Ein Lauf unmittelbar nach
dem Anhalten des Dienstes verschickt deshalb den **ganzen stehenden Bestand**,
und die Null, die das Kriterium an dieser Stelle erwartet, stünde nirgends.

> **Eine Erwartung, die man aus den Zahlen ausrechnet statt sie zu schätzen,
> macht aus dem Ergebnis einen Beleg — eine geschätzte hätte hier einen Befund
> erfunden.**

Gefallen ist es beim Ausrechnen und nicht beim Nachdenken: Die Zeile „erwartet
wird `0 Nachricht(en) über 0 Befund(e)`" stand schon da, und daneben die
Bestandsaufnahme aus §1, die genau das widerlegt.

**Der Lauf räumt den Bestand deshalb zuerst ab** (Punkt 3), und das ist kein
Vorgeplänkel, sondern die erste Messung: Sie belegt, dass die Kette trägt.
Erst danach ist die Ablage warm, der hergestellte Zustand der einzige
ungebuchte — und sein Schweigen in Punkt 4 eine Aussage über die Frist statt
über den leeren Bestand.

> **Eine Null, die man vor der ersten Zustellung abliest, misst den leeren
> Anfang und nicht die Regel.**

**4 · Der Webhook bündelt je Gegenstand und die Mail je Empfänger.** Erwartet
werden also **eine** Mail und **so viele** Zustellungen, wie es fällige
Gegenstände gibt. Wer hier „eine Meldung über beide Kanäle" abliest, misst den
Webhook falsch.

**5 · Slack und Discord waren als Meldeziel nicht zu gebrauchen — und sind es
seit dem 21. September.** Beide verlangen einen Rumpf mit `text` beziehungsweise
`content` und weisen alles andere mit `400` ab; unser Rumpf trug `server`, `at`
und `event`. Der Betreiber hat die beiden daraufhin bestellt; seitdem wählt man
den Empfänger auf `/settings/notices`, und `Notify\Providers` baut den Rumpf,
den er annimmt.

**Gemessen ist damit die Form und nicht die Annahme.** Dass Slack ein `text`
wirklich annimmt, sagt seine Schnittstellenbeschreibung und kein Lauf von hier —
aus diesem Container ist keiner der beiden erreichbar. Punkt 9 misst es, wenn
ein solcher Haken zur Hand ist.

**Zwei Dinge daran gehören in den Lauf:** Bei Slack und Discord weist der Agent
ein Geheimnis **ab** — dort ist die Adresse das Zugangsmittel —, und der Text
wird auf die Grenze des Empfängers gekürzt (Discord: 2000 Zeichen) mit einem
„… und N weitere" am Ende.

**Und dieser Absatz stand bis zuletzt mit „Punkt 8" da** — die Umnummerierung
nach §0 Punkt 3 hat die Verweise in den Punkten mitgenommen und den hier nicht.

> **Eine Umnummerierung nimmt die Verweise mit, die im selben Abschnitt stehen
> — und lässt die zurück, die woanders auf ihn zeigen.**

**6 · Welcher Dienst sich anhalten lässt, ist keine freie Wahl.**
`srvpanel-agentd` trägt den Webhook, `srvpanel-worker` die Warteschlange,
`srvpanel-web` die Oberfläche selbst. Bleibt `srvpanel-metrics` — und sein
Stillstand kostet die Kacheln aus B4 für einen Tag, weil der Ringpuffer genau
24 Stunden hält. Das ist der Preis und er ist benannt.

---

## §1 · Vorbedingungen — gemessen und nicht angenommen

**Alles in einem Block, und jede Zeile druckt, was sie gefunden hat.** Eine
Vorbedingung, die man nicht gegen den heilen Fall gemessen hat, ist keine
Prüfung (`docs/913 §1`).

```bash
# 0 · Welche Fassung läuft hier, und trägt sie B1?
srvpanel version
srvpanel notices --help >/dev/null 2>&1 && echo "Kommando da" || echo "Kommando FEHLT"

# 1 · Die vier Dauerdienste und der Zeitgeber der Diagnose
systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-web srvpanel-metrics
systemctl show srvpanel-diagnose.timer -p NextElapseUSecRealtime -p RandomizedDelayUSec

# 2 · Das Relay — und die Gegenprobe, dass es trägt
#     (Testmail auf /settings/mail drücken und im Postfach nachsehen)
srvpanel tinker --execute='
  $m = app(App\Support\Settings\Settings::class)->mail();
  printf("Relay: %s:%d  nutzbar: %s\n", $m->host, $m->port, $m->usable() ? "ja" : "nein");
'

# 3 · Wer ist Betreiber, und hat er eine Adresse?
srvpanel tinker --execute='
  foreach (App\Models\Account::operators()->get() as $a)
      printf("%-24s %-28s %s\n", $a->name, $a->email ?? "(keine)", $a->status->value);
'

# 4 · Die Klammer um den Gegenstand: der Bestand VOR dem Lauf
srvpanel tinker --execute='
  $f = App\Models\Finding::withoutGlobalScopes()->orderBy("check")->orderBy("subject")->get();
  printf("Befunde: %d\n", $f->count());
  foreach ($f as $b)
      printf("  %-18s %-30s %-14s seit %s\n", $b->check->value, $b->subject, $b->reason, $b->first_seen_at);
  printf("Buchungen: %d\n", App\Models\FindingNotification::query()->count());
'
```

**Zwei Fallen stehen in diesen Zeilen, und beide sind bezahlt.**
`srvpanel tinker` läuft **ohne angemeldetes Konto**; jedes Modell mit der
Mandantenklammer antwortet im Grundzustand mit einer leeren Liste und nicht mit
einem Fehler. `Finding` trägt sie bewusst nicht (`docs/98 §9` Frage 5), das
`withoutGlobalScopes()` steht trotzdem da — es kostet nichts und beantwortet
die Frage, die man sonst beim Lesen stellt. Und ein `srvpanel tinker`, das
**gar nichts** druckt, ist kein leeres Ergebnis, sondern ein nicht gelaufener
Block (`packaging/bin/srvpanel` erklärt es an `HOME`).

> **Ein Befehl, der schweigt, sieht aus wie einer, der nichts gefunden hat.**

---

## §2 · Der Empfänger des Webhooks

**Er muss https sprechen, ein gültiges Zertifikat haben und `2xx` antworten.**
`Acme\Curl` prüft `VERIFYPEER` und `VERIFYHOST`; ein selbstsigniertes
Zertifikat auf `127.0.0.1` fällt durch, und das ist Absicht.

**Der Rumpf trägt die Befunde dieses Servers.** Ein fremder Einsichtsdienst
(`webhook.site` und Verwandte) macht sie damit für jeden lesbar, der die Adresse
kennt — das ist eine Veröffentlichung und keine Messung. Der eigene Empfänger
kostet vier Zeilen und bleibt auf der Maschine:

**Drei Dinge daran sind am 21. September 2026 auf `cloudsrv24` falsch
gewesen**, und alle drei stehen im Quelltext:

- **Das Dokumentenverzeichnis einer zusätzlichen Domain ist das Verzeichnis
  selbst**, nicht ein `httpdocs` darin. `Site::documentRootPath()` setzt es aus
  der Abonnementwurzel und `document_root` zusammen; `httpdocs` ist der Wert
  für die **erste** Domain und steht eine Ebene höher
  ({@see SubscriptionProvision::TREE}).
- **Die Abonnementwurzel gehört `root:root`**, und zwar mit Absicht: Ihr
  Zugriffsbit ist der Schalter von `subscription.suspend`. Wer den
  Systembenutzer sucht, fragt das **Dokumentenverzeichnis** —
  `SystemUserVerdictTest` gibt es genau dafür.
- **Der Empfänger gehört in ein Unterverzeichnis** und nicht an die Wurzel der
  Domain, sonst überschreibt er die `index.php`, die dort steht.

> **Ein Pfad, den eine Vorschrift aus dem Gedächtnis nennt, ist eine Vermutung
> — und der Quelltext steht daneben.**

**Und eine vierte ist beim Laufen umgefallen, nicht beim Ausschreiben** — es
ist wieder ein Pfad, und es ist der teuerste der vier:

- **Der erste `root` eines Server-Blocks ist nie der der Domain.**
  {@see SiteTemplate::render()} setzt `HttpChallenge::nginxLocation()` **vor**
  den Inhaltsblock, und diese `location` bringt ihr eigenes `root` mit:
  `/var/spool/srvpanel/acme-challenge`, für alle Domains des Servers an einer
  Stelle, damit kein Kunde irgendwo Schreibrechte braucht. Ein `awk` mit `exit`
  hinter dem ersten Treffer liest deshalb **immer** das Prüfverzeichnis.

Gemessen auf `cloudsrv24` am 21. September 2026: sechs Server-Blöcke, sechsmal
`continue`, null Kandidaten — bei drei Domains mit gültigem Zertifikat, die auf
Nachfrage alle mit `200` und `ssl_verify_result=0` antworteten. Der Filter hat
das Material weggeworfen, nicht der Server.

Und weil der Block das nicht sagte, sah sein Ergebnis aus wie vier andere
Zustände zugleich: leeres Verzeichnis, falsches `$ABO`, fehlende Leserechte,
kein gültiges Zertifikat. Es ist derselbe Fehler, den `packaging/bin/srvpanel`
seit dem 18. August im Kopf trägt — dort schweigt `tinker` ohne `HOME`, und das
Schweigen sieht aus wie ein leeres Ergebnis. Hier schwieg eine Schleife.

> **Eine Schleife, die nichts ausgibt, hat nicht nichts gefunden — sie sagt
> gar nichts. Wieviele Dateien sie angesehen hat und wieviele davon in Frage
> kamen, muss sie selbst nennen.**

Der Block fragt die Wurzel deshalb bei **nginx** nach, statt sie aus dem Namen
abzuleiten; `awk` sucht die **erste Wurzel unterhalb des Abonnements** statt
der ersten überhaupt, und beide Zahlen — angesehen und in Frage gekommen —
stehen vor dem Ergebnis:

```bash
ABO=/var/www/vhosts/<abonnement>
HAKEN=""; WURZEL=""; TREFFER=0

# Die Zahlen vor der Schleife. Ohne sie sieht ein leeres Verzeichnis aus wie
# „keine Domain hat ein gültiges Zertifikat" — und ein Filter, der danebengreift,
# auch.
DATEIEN=$(ls -1 /etc/nginx/srvpanel.d/*.conf 2>/dev/null | wc -l)
printf 'Server-Blöcke: %s   Abonnementwurzel: %s\n' \
    "$DATEIEN" "$([ -d "$ABO" ] && echo vorhanden || echo FEHLT)"

for f in /etc/nginx/srvpanel.d/*.conf; do
  [ -f "$f" ] || continue

  # Die erste Wurzel *unterhalb des Abonnements* — nicht die erste im Block.
  # Die ist das Prüfverzeichnis von ACME und gehört keiner Domain.
  r=$(awk -v abo="$ABO/" '$1=="root"{gsub(/;/,"",$2); if (index($2,abo)==1) {print $2; exit}}' "$f")
  [ -n "$r" ] || continue
  TREFFER=$((TREFFER + 1))

  n=$(awk '$1=="server_name"{gsub(/;/,"",$0); print $2; exit}' "$f")
  p=$(awk '$1=="fastcgi_pass"{print "ja"; exit}' "$f"); p=${p:-nein}
  m=$(curl -sS -o /dev/null -m 10 -w '%{http_code}:%{ssl_verify_result}' "https://$n/" 2>/dev/null || echo '000:1')
  printf '  %-30s %-44s php=%-5s %s\n' "$n" "$r" "$p" "$m"

  # Ohne PHP führt der Empfänger nichts aus: nginx liefert die index.php als
  # Datei aus, der Aufruf sieht mit 200 gelungen aus, und das Protokoll bleibt leer.
  case "$m:$p" in *:0:ja) [ -z "$HAKEN" ] && { HAKEN="$n"; WURZEL="$r"; } ;; esac
done

printf 'Angesehen: %s   unter %s: %s   gewählt: %s\n' \
    "$DATEIEN" "$ABO" "$TREFFER" "${HAKEN:-KEINE}"

# Ohne Kandidat wird hier nichts angelegt. `install -d "$WURZEL/haken"` mit
# leerem WURZEL legte `/haken` an — als root, an der Wurzel des Dateisystems.
if [ -n "$HAKEN" ]; then

BEN=$(stat -c %U "$WURZEL")
D="$WURZEL/haken"
LOG="$ABO/tmp/haken.log"

install -d -o "$BEN" -g www-data -m 2750 "$D"
cat > "$D/index.php" <<'PHP'
<?php
file_put_contents(dirname(__DIR__, 2).'/tmp/haken.log',
    date('c')."\t".($_SERVER['HTTP_X_SRVPANEL_SIGNATURE'] ?? '-')."\t".file_get_contents('php://input')."\n",
    FILE_APPEND);
http_response_code(204);
PHP
chown "$BEN:$BEN" "$D/index.php"
: > "$LOG"; chown "$BEN:$BEN" "$LOG"; chmod 640 "$LOG"

# Gegenprobe, dass der Empfänger überhaupt annimmt — sonst misst Punkt 3 den Empfänger
curl -sS -o /dev/null -m 10 -w '%{http_code}\n' -X POST -d '{"probe":1}' "https://$HAKEN/haken/"
tail -1 "$LOG"

fi
```

> **Ein Installierer, der seinen Zielpfad aus einer Variablen baut, gehört
> hinter die Frage, ob sie gefüllt ist — sonst legt er bei der leeren an der
> Wurzel an.**

**Das Protokoll liegt in `tmp/` und nicht neben der Domain.** `tmp` gehört dem
Systembenutzer (`2700`), liegt in der `open_basedir` des Pools und **ausserhalb
jedes Dokumentenverzeichnisses** — in den Rümpfen stehen die Befunde dieses
Servers, und was unter der Wurzel liegt, liest jeder, der die Adresse rät.

> **Eine Gegenprobe, deren Ausschlag vom Empfänger abhängt, gehört vor die
> Messung und nicht daneben.**

---

## §3 · Der Zeitplan

**Gemessen, nicht geplant.** Die erste Fassung dieser Tabelle nahm einen
Nachmittag an; angefangen wurde am 21. September um **21:59** (`stored_at` des
Meldeziels). Die Zeiten darunter sind daraus ausgerechnet:

| | wann | was |
|---|---|---|
| **T0** | 21. Sep, 21:59 | Punkte 1–2: Ziel hinterlegt, `http` abgewiesen |
| **T0 + 6 min** | 22:05 | Punkt 3: **den stehenden Bestand abgeräumt** — der Lauf, der die Kette belegt |
| **T0 + ~10 min** | ~22:10 | Punkt 4: Dienste anhalten, Lauf fahren — und **jetzt** schweigt er |
| **T0 + ~2 h** | 22. Sep, 00:15:22, von selbst | Punkt 5: der Zeitgeber feuert und **schweigt zu Recht** |
| **T0 + 20 h** | 22. Sep, **abends** ab ~18:10 | Punkte 6–8c: der Lauf sendet, der nächste schweigt, der Dienst kommt zurück |
| | gleich danach | Punkte 10–12: die Gegenrichtungen ohne Frist |
| **T1 + 20 h** | einen Tag später | Punkt 9: das Ziel, das abweist — er braucht einen **zweiten** alten Zustand |
| | zum Schluss | Punkt 13: die Maschine bleibt, wie sie war |

**Zwei Zeilen haben sich dadurch verschoben, und beide sind §0 Punkt 2 im
Kleinen.** Der nächtliche Lauf ist nicht `T0 + 10 h`, sondern `T0 + 2 h` — der
Zustand ist dann zwei Stunden alt statt zehn, und er schweigt umso
deutlicher zu Recht. Und der Lauf, der sendet, liegt nicht am *Morgen* danach,
sondern am **Abend**: Zwanzig Stunden ab 22:10 sind um 18:10, und vorher misst
Punkt 6 nur die Frist ein zweites Mal.

> **Wer einen Ablauf in Tageszeiten plant und in Fristen misst, bekommt beides
> — aber nicht am selben Tag.**

Punkt 6 wird **von Hand ausgelöst**, sobald `Fällig ab` aus Punkt 4 erreicht
ist. Wer wartet, bekommt ihn in der Nacht auf den 23. vom Zeitgeber — dieselbe
Messung, nur einen halben Tag später und ohne jemanden davor.

**Punkt 9 kostet eine zweite Frist, und er lässt sich nicht vorziehen.** Er
braucht einen Befund, der für den Webhook fällig ist; nach Punkt 6 ist jeder
vorhandene gebucht. Ihn **vor** Punkt 6 zu fahren, scheitert an der anderen
Seite: Der Lauf, dessen Webhook abweist, bucht für `mail` trotzdem — und Punkt
6 läse danach `mail: 0 über 0`, weil die Mail schon draussen war. `srvpanel
notices` kennt keinen Schalter, der die Frist überginge (`SendNotices` hat kein
Argument), und das ist richtig so.

> **Zwei Messungen, die dieselbe Buchung verbrauchen, lassen sich nicht in
> dieselbe Frist legen.**

**Der nächtliche Lauf ist kein Störfall, sondern eine Messung, die sich von
selbst einstellt** — und sie gehört vorhergesagt. Wer sie nicht erwartet, liest
das Schweigen des Zeitgebers als Ausfall.

---

## §4 · Die Punkte

**Jeder Punkt kann in einer frischen Schale anfangen**, und mehrere liegen
Stunden auseinander. Die vier Namen aus §2 stehen deshalb hier einmal, und die
Punkte darunter benutzen sie, statt ihre Pfade noch einmal zu buchstabieren —
sonst steht derselbe Pfad neunmal da, und acht davon zieht niemand nach, wenn
der Empfänger wechselt:

```bash
ABO=/var/www/vhosts/<abonnement>
HAKEN=<der in §2 gewählte Name>
LOG=$ABO/tmp/haken.log

# Die Wurzel wird auch hier nicht zusammengesetzt, sondern nachgefragt:
# `$ABO/$HAKEN` gilt für eine Zusatzdomain und für die Hauptdomain nicht — die
# liegt in `httpdocs`. Es ist dieselbe Regel wie in §2 und deshalb derselbe
# Ausdruck.
WURZEL=$(awk -v abo="$ABO/" '$1=="root"{gsub(/;/,"",$2); if (index($2,abo)==1) {print $2; exit}}' \
    "/etc/nginx/srvpanel.d/$HAKEN.conf")
printf 'Empfänger: %s   Wurzel: %s   Protokoll: %s\n' "$HAKEN" "${WURZEL:-FEHLT}" "$LOG"
```

> **Ein Pfad, der in neun Blöcken steht, ist eine Angabe an acht Stellen zu
> viel — und eine Wurzel, die man aus zwei Namen zusammensetzt, ist für die
> Hauptdomain falsch.**

### Punkt 1 · Das Meldeziel steht, und die Adresse kommt nicht zurück

```bash
# Auf /settings/notices eintragen: Adresse + Geheimnis (mind. 16 Zeichen).
# Das Geheimnis erzeugt der Betreiber auf dem Server und nirgends sonst:
#   openssl rand -hex 32
GEHEIMNIS='<das eingetragene Geheimnis>'

srvpanel tinker --execute='
  $t = app(App\Support\Notify\NotifyTarget::class);
  var_dump($t->reachable(), $t->describe());
'
ls -la /etc/srvpanel/notify/

# Steht das Geheimnis irgendwo im Klartext? Mit der Zahl daneben, die sagt,
# wieviel überhaupt durchsucht wurde — sonst misst die Null den leeren Korb.
PROT=$(ls /var/lib/srvpanel/storage/logs/*.log /var/log/srvpanel/*.log 2>/dev/null)
printf 'Durchsucht: %s Datei(en), %s Zeile(n)\n' \
    "$(printf '%s\n' "$PROT" | grep -c .)" "$(cat $PROT 2>/dev/null | wc -l)"
printf 'Geheimnis in den Protokollen: %s   (erwartet 0)\n' \
    "$(grep -Fl "$GEHEIMNIS" $PROT 2>/dev/null | wc -l)"

# Die Gegenprobe: Steht der Vorgang, in dem das Geheimnis als Argument reiste,
# überhaupt in dem, was eben durchsucht wurde? Sonst misst die Null darüber den
# leeren Korb. Der Agent schreibt nach /var/log/srvpanel/agent.log
# ({@see Config::DEFAULT_LOG_FILE}) und nicht ins Journal von systemd.
printf 'notify.target.store in den Protokollen: %s   (Gegenprobe, erwartet > 0)\n' \
    "$(grep -h 'notify.target.store' $PROT 2>/dev/null | wc -l)"

# Und die Zeile selbst — sie zeigt beides auf einmal. Ausdrücklich die
# `request`-Zeile: `tail -1` allein nimmt die letzte, und die letzte ist die
# Antwort. Argumente trägt nur die Anfrage.
grep -h 'notify.target.store' /var/log/srvpanel/agent.log 2>/dev/null \
    | grep '"kind":"request"' | tail -1 | cut -c1-400; echo
```

**Erwartet:** `reachable` = `true`; `describe()` trägt `host`, `provider`,
`stored_at` und `signed: true` — und **weder die volle Adresse noch das
Geheimnis noch `config`**. Die Datei liegt `-rw------- root root` in einem
`drwx------`-Verzeichnis. Der Geheimniszähler steht auf `0`, die beiden
Zahlen darüber und der Gegenprobenzähler darunter nicht — und die letzte Zeile
zeigt die **Anfrage** selbst, mit `"secret":"···"` und `"url"` im Klartext.
Eine `"kind":"result"`-Zeile trägt keine Argumente und belegt hier nichts.

**Was dieser Punkt nicht sagt:** dass die Adresse nirgends steht.
{@see Connection::redactArgs()} ersetzt jedes Argument, dessen Name `secret`,
`key`, `token`, `password` oder `pem` enthält, durch `···`; `url` steht nicht
darunter und erscheint **vollständig** in `agent.log`. Das ist Absicht — die
Datei liegt unter `/var/log/srvpanel`, das liest root, und die Grenze, die
dieser Punkt misst, ist die zur **Seite**. Gemessen wird hier das Geheimnis,
nicht die Adresse.

**Und `journalctl` ist hier die falsche Tür.** Am 21. September stand in der
ersten Fassung dieses Punktes `journalctl -u srvpanel-agentd`; die Gegenprobe
kam mit `0` zurück, und die Null darunter hätte ohne sie als Beleg gegolten.
Der Agent schreibt seine Vorgänge als JSON-Zeilen in eine Datei und an den
Journald-Kanal nur das, was er **nicht** protokollieren konnte
({@see Journal::write()}).

> **Eine Gegenprobe, die selbst danebengreift, ist der einzige Grund, warum
> eine Null hier je als Beleg durchgeht — sie gehört deshalb in dieselbe
> Ausgabe wie die Null.**

**Gegenrichtung im selben Punkt:** Auf der Seite steht der Rechnername und
sonst nichts vom Ziel. Ein Bildschirmfoto der Seite gehört dazu — es ist der
Beleg, dass die Adresse dort nicht steht.

### Punkt 2 · Eine `http`-Adresse wird abgewiesen

```bash
# Auf /settings/notices im Feld „Adresse" eintragen und speichern:
#   http://127.0.0.1:9200/x
```

**Erwartet:** Eine Prüfmeldung am Feld — der Satz aus `lang/de/validation.php`
(`starts_with`) —, **kein** Vorgang, und die hinterlegte Adresse aus Punkt 1
steht unverändert da. Das ist Grenze 1 an der Tür.

### Punkt 3 · Der stehende Bestand wird abgeräumt — und die Kette belegt

**Vor** dem Anhalten des Dienstes, damit die Ablage warm ist (§0 Punkt 3):

```bash
date -Is
VORHER=$(wc -l < "$LOG")

systemctl start srvpanel-diagnose.service
journalctl -u srvpanel-diagnose.service -n 30 --no-pager

srvpanel tinker --execute='
  printf("Buchungen: %d\n", App\Models\FindingNotification::query()->count());
  foreach (App\Models\FindingNotification::query()->get()->groupBy("channel") as $k => $g)
      printf("  %-10s %d\n", $k, $g->count());
'

# Beide Zahlen in einer Zeile. „2 Zeilen" allein ist keine Aussage — die
# Gegenprobe aus §2 hat selbst eine hinterlassen.
printf 'Empfängerprotokoll: %s -> %s Zeile(n)\n' "$VORHER" "$(wc -l < "$LOG")"
tail -1 "$LOG" | awk -F'\t' '{printf "  Signatur: %s\n  Rumpf   : %.200s\n", $2, $3}'
```

**Erwartet, ausgerechnet aus der Bestandsaufnahme in §1 Block 4** — `N` ist die
Zahl der beurteilten Serverbefunde dort, `M` die Zahl verschiedener Gegenstände
darunter:

- `mail: 1 Nachricht(en) über N Befund(e)` — **eine** Mail, `N` Zeilen darin.
- `webhook: M Nachricht(en) über N Befund(e)`.
- `Buchungen: 2 × N`, davon `mail` = `N` und `webhook` = `N`.
- `M` neue Zeilen im Protokoll des Empfängers — abgelesen als `vorher -> nachher`
  und nicht als Endstand: In der Datei steht schon die Gegenprobe aus §2.
- **Eine Signatur, die nicht `-` ist.** Die Gegenprobe aus §2 kam mit `curl` und
  ohne Kopfzeile; steht in der neuen Zeile eine, hat der Agent sie gesetzt — und
  das `signed: true` aus Punkt 1 ist keine Beschriftung mehr, sondern gemessen.

**Das ist die Messung, die belegt, dass die Kette trägt** — und ohne sie wäre
das Schweigen in Punkt 4 von einem kaputten Mailweg nicht zu unterscheiden.

Steht in §1 Block 4 **kein** beurteilter Befund, entfällt dieser Punkt und
Punkt 4 misst trotzdem: Die Ablage ist dann ohnehin leer.

### Punkt 4 · Der Zustand wird hergestellt, und der Lauf schweigt

```bash
date -Is
systemctl stop srvpanel-metrics.service
systemctl stop srvpanel-dns.timer
systemctl is-active srvpanel-metrics.service srvpanel-dns.timer

VORHER=$(srvpanel tinker --execute='printf("%d", App\Models\FindingNotification::query()->count());')
ZEILEN=$(wc -l < "$LOG")

systemctl start srvpanel-diagnose.service
journalctl -u srvpanel-diagnose.service -n 20 --no-pager

# **Der ganze Bestand**, und nicht zwei erratene Zeilen daraus. Welche Prüfung
# einen angehaltenen Gegenstand meldet, entscheidet {@see Units::judge()} und
# nicht das Gedächtnis: Ein **Timer** ohne Termin fällt in den Zweig davor und
# kommt als `unit.schedule / no_next` heraus, nie zusätzlich als `inactive`.
srvpanel tinker --execute='
  foreach (App\Models\Finding::withoutGlobalScopes()->orderBy("check")->orderBy("subject")->get() as $b)
      printf("  %-16s %-28s %-14s seit %s\n", $b->check->value, $b->subject, $b->reason,
          $b->first_seen_at->toIso8601String());
  printf("Buchungen: %d\n", App\Models\FindingNotification::query()->count());
'
printf 'Buchungen: %s -> (oben)   Empfängerprotokoll: %s -> %s Zeile(n)\n' \
    "$VORHER" "$ZEILEN" "$(wc -l < "$LOG")"

# Wann die Frist um ist — ausgerechnet, nicht geschätzt, und **mit Zone**.
FAELLIG=$(srvpanel tinker --execute='
  $b = App\Models\Finding::withoutGlobalScopes()->orderByDesc("first_seen_at")->first();
  if ($b !== null) printf("%s", $b->first_seen_at->copy()
      ->addHours(App\Support\Notify\Notices::HOLD_HOURS)->toIso8601String());
')
printf 'Fällig ab: %s   lokal: %s\n' "$FAELLIG" "$(date -d "$FAELLIG" '+%Y-%m-%d %H:%M:%S %Z')"
```

**Erwartet:** `Kaputt: N + 2`, und in der Tabelle stehen die beiden neuen
Zeilen — `unit.state / srvpanel-metrics.service / inactive` und
**`unit.schedule / srvpanel-dns.timer / no_next`**, beide mit frischem
`first_seen_at`. Beide Kanäle drucken `0 Nachricht(en) über 0 Befund(e)`; die
Zahl der Buchungen ist **dieselbe wie vorher**; im Protokoll des Empfängers
keine neue Zeile; kein Brief. `Fällig ab` nennt den Augenblick, an dem Punkt 6
frühestens misst — abgelesen, nicht geschätzt.

**Die Zeit steht zweimal da, und das ist kein Schmuck.** `first_seen_at` liegt
in UTC; am 21. September stand `Fällig ab: 2026-09-22 16:09:39` neben einer
Uhr, die `22:09` zeigte — zwei Stunden Unterschied, und nichts an der Zeile
sagte, welche der beiden Zonen sie meint. Dieselbe Regel, die
{@see Site::$maintenanceZone} für die Wartungsseite aufschreibt, gilt für eine
Vorschrift genauso:

> **Eine Zeitangabe mit ihrer Zone bleibt wahr, auch wenn die Zone sich
> seither geändert hat — eine ohne wird still falsch.**

**Warum zwei Gegenstände und nicht einer.** Mit **einem** Befund auf **einem**
Gegenstand drucken beide Kanäle in Punkt 6 dieselbe Zahl — `1 über 1` gegen
`1 über 1` —, und ein Kanal, der nach dem falschen Schlüssel bündelt, druckt
dasselbe. Die Bündelung ist dann nicht gemessen, sondern nur nicht widerlegt.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

Der zweite Gegenstand kostet einen pausierten DNS-Abgleich für einen Tag und
trennt die beiden Kanäle in Punkt 6. Gewählt ist ein **Timer** und kein
zweiter Dauerdienst: Ein angehaltener `srvpanel-worker` nähme die
Warteschlange mit, und ein angehaltener `srvpanel-web` das Panel.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Sie bekommt ihre Bedeutung hier zweifach: durch den Befund, der
> daneben in der Tabelle steht, und durch die `N` Zustellungen, die derselbe
> Weg eine Minute vorher geschafft hat.

### Punkt 5 · Der Zeitgeber feuert und schweigt

Am nächsten Morgen, **ohne** etwas zu tun:

```bash
systemctl show srvpanel-diagnose.timer -p LastTriggerUSec -p NextElapseUSecRealtime

# `--since` auf den Zeitpunkt aus Punkt 4 und nicht relativ: Ein `-14h` misst
# je nach Ablesestunde einen anderen Ausschnitt.
journalctl -u srvpanel-diagnose.service --since '<T0, aufgerundet auf die volle Stunde>' \
    --no-pager | grep -E 'Starting|Prüfung|Kaputt|Nachricht|Entwarnung|nicht eingerichtet'

srvpanel tinker --execute='printf("Buchungen: %d\n", App\Models\FindingNotification::query()->count());'
printf 'Empfängerprotokoll: %s Zeile(n)\n' "$(wc -l < "$LOG")"
```

**Erwartet:** Der Zeitgeber hat gefeuert (`LastTriggerUSec` liegt in der
Nacht), der Dienst hat dabei **wirklich gemessen** (`N Prüfung(en) gefahren`
mit einem Zeitstempel aus derselben Minute), beide Kanäle drucken `0
Nachricht(en) über 0 Befund(e)` — und Buchungen wie Protokollzeilen stehen
**unverändert wie in Punkt 4**. Die Frist war noch nicht um.

> **Ein Zeitgeber, der feuert und nichts ändert, ist von einem, der nicht
> gefeuert hat, nur an seinem Zeitstempel zu unterscheiden** — deshalb steht
> `LastTriggerUSec` in derselben Ablesung und nicht daneben.

**Und `LastTriggerUSec` steht daneben, weil `NextElapseUSecRealtime` es nicht
tut.** Gemessen am 21./22. September: Abends nannte die Einheit
`NextElapseUSecRealtime=00:15:22`, gefeuert hat sie um **00:49:35** — vierund­
dreissig Minuten später. Beide Zeiten liegen in der Streuung, die
`srvpanel-diagnose.timer` mitbringt (`OnCalendar=daily`,
`RandomizedDelaySec=1h`); **warum die Vorhersage und der Schuss auseinander­
fallen, ist nicht gemessen** und wird hier auch nicht behauptet. Für diesen
Punkt genügt, was folgt: Wer den angekündigten Augenblick abwartet und dann
nachsieht, hält ein Schweigen für einen Ausfall, das keiner ist.

> **Eine angekündigte Zeit ist keine abgelesene.** Gemessen wird, wann etwas
> geschehen ist, und nicht, wann es geschehen sollte.

**Fällt dieser Punkt aus** — weil der Zeitgeber aus irgendeinem Grund nicht
gefeuert hat —, ist das kein Ausfall des Kriteriums; er ist ein Zugewinn und
kein Ausschlusskriterium.

### Punkt 6 · Der Lauf sendet, über beide Kanäle, genau einmal

Frühestens **20 Stunden** nach dem `first_seen_at` aus Punkt 4 — und die
Bestandsaufnahme aus §1 Block 4 wird davor noch einmal gefahren, weil die Nacht
dazwischen neue Befunde gebracht haben kann:

```bash
date -Is
systemctl start srvpanel-diagnose.service
journalctl -u srvpanel-diagnose.service -n 30 --no-pager

srvpanel tinker --execute='
  foreach (App\Models\FindingNotification::query()->with("finding")->get() as $n)
      printf("%-10s %-18s %-30s %s\n", $n->channel, $n->finding->check->value, $n->finding->subject, $n->notified_at);
'
printf 'Empfängerprotokoll: %s -> %s Zeile(n)\n' "$ZEILEN" "$(wc -l < "$LOG")"
tail -5 "$LOG"
```

**Erwartet — Punkt 3 hat den Bestand geräumt, und Punkt 4 hat zwei Gegenstände
hergestellt. Hier trennen sich die Kanäle:**

- `mail: 1 Nachricht(en) über 2 Befund(e)` — **eine** Mail, zwei Zeilen darin.
- `webhook: 2 Nachricht(en) über 2 Befund(e)` — **zwei** Meldungen.
- **Vier** Buchungen mehr als in Punkt 4, zwei je Kanal.
- Im Postfach des Betreibers **eine** Mail, und ihr Betreff steht jetzt in der
  **Mehrzahl**. Die Einzahl ist in Punkt 3 abgelesen, wo `N` = 1 war — beide
  Formen sind gebaut, und jede wird an dem Lauf gemessen, der sie erzeugt.
- **Zwei** neue Zeilen im Protokoll des Empfängers, je mit einer Signatur
  `t=…,v1=…`.

**Diese vier Zahlen sind der Grund für den zweiten Gegenstand.** `1 über 2`
gegen `2 über 2` lässt sich von einer falschen Bündelung unterscheiden; `1 über
1` gegen `1 über 1` nicht.

**Kommt in der Nacht dazwischen ein weiterer Befund auf** — eine Sicherung
scheitert, ein Zertifikat rutscht in die Frist —, dann sind es entsprechend
mehr, und die Zahlen stehen in der Ausgabe daneben. Die Eins im Betreff wäre
dann eine Zahl. Das ist kein Ausfall; es ist der Grund, aus dem die
Bestandsaufnahme aus §1 vor **jedem** Ablesen noch einmal gefahren wird.

**Und die Signatur wird nachgerechnet und nicht abgelesen:**

```bash
GEHEIM='<das hinterlegte Geheimnis>'
tail -1 "$LOG" | awk -F'\t' '{print $2"\n"$3}' \
| { read -r SIG; read -r RUMPF;
    T=$(echo "$SIG" | sed 's/^t=\([0-9]*\),.*/\1/')
    V=$(echo "$SIG" | sed 's/.*v1=//')
    echo "gelesen : $V"
    printf '%s.%s' "$T" "$RUMPF" | openssl dgst -sha256 -hmac "$GEHEIM" -r | cut -d' ' -f1 | sed 's/^/gerechnet: /'
  }
```

**Erwartet:** zwei gleiche Zeilen. Eine Kopfzeile, die dasteht, sagt nichts
darüber, worüber sie gebildet wurde.

Die Formel ist nicht erfunden, sondern {@see Delivery::signature()}:
`hash_hmac('sha256', $at.'.'.$body, $secret)`, und der Rumpf geht mit
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` hinaus. **`read -r` ist
deshalb kein Stilmittel:** Ein `\"` im Rumpf, das die Schale unterwegs frisst,
liefert eine andere Summe — und die sähe aus wie eine falsche Signatur.

**Und die Seite:** `/settings/notices` trägt jetzt für **beide** Kanäle einen
Zeitpunkt unter „Zuletzt erfolgreich zugestellt". Bildschirmfoto in beiden
Themen und bei 390 px.

### Punkt 7 · Derselbe Zustand, keine zweite Meldung

Unmittelbar danach:

```bash
ZEILEN=$(wc -l < "$LOG")
systemctl start srvpanel-diagnose.service
journalctl -u srvpanel-diagnose.service -n 15 --no-pager
srvpanel tinker --execute='printf("Buchungen: %d\n", App\Models\FindingNotification::query()->count());'
printf 'Empfängerprotokoll: %s -> %s Zeile(n)\n' "$ZEILEN" "$(wc -l < "$LOG")"
```

**Erwartet:** `0 Nachricht(en) über 0 Befund(e)` auf beiden Kanälen, dieselbe
Zahl Buchungen wie am Ende von Punkt 6, dieselbe Zahl Zeilen im Protokoll,
keine zweite Mail.

### Punkt 8 · Der Dienst kommt zurück, und der Befund verschwindet mitsamt seiner Buchung

```bash
systemctl start srvpanel-metrics.service
systemctl is-active srvpanel-metrics.service
sleep 5

# **`srvpanel diagnose` und nicht die Unit.** Die Unit hat zwei ExecStart-Zeilen
# — erst die Messung, dann den Versand. Sie legte die Entwarnungszeilen an und
# verbrauchte sie im selben Lauf; Punkt 8b fände danach eine leere
# Warteschlange und läse sie als fehlendes Merkmal.
srvpanel diagnose

srvpanel tinker --execute='
  printf("Befund noch da: %s\n", App\Models\Finding::withoutGlobalScopes()
      ->where("check","unit.state")->where("subject","srvpanel-metrics.service")->exists() ? "ja" : "nein");
  printf("Buchungen: %d\n", App\Models\FindingNotification::query()->count());
'
```

**Erwartet:** `Befund noch da: nein`, und die Zahl der Buchungen ist gegenüber
Punkt 7 um **zwei** gefallen — `cascadeOnDelete` nimmt sie mit. Damit ist belegt, dass
derselbe Zustand später **wieder** melden dürfte.

> **Ein Befund, der verschwindet, wenn sein Grund verschwindet, ist gemessen.
> Ein Befund, der nur entsteht, ist abgelegt.**

Das `sleep 5` steht da, weil ein `is-active` unmittelbar nach dem `start` den
Übergang misst und nicht den Zustand — derselbe Satz wie am 4. September bei
`srvpanel.target`.

> **Ein Punkt, der eine Warteschlange füllen soll, damit der nächste sie misst,
> darf nicht den Befehl nehmen, der sie auch leert.**

### Punkt 8b · Und der Empfänger erfährt, dass es vorbei ist

**Unmittelbar nach Punkt 8 und vor irgendeinem weiteren Lauf**, denn die
Warteschlange wird beim Zustellen geleert.

```bash
# Vorbedingung, und zwar ausdrücklich: Ohne die Tabelle misst dieser Punkt
# nichts, und ein Fehler hier ist ein fehlendes Update und kein fehlendes
# Merkmal.
srvpanel tinker --execute='
  printf("finding_resolutions: %s\n",
      Illuminate\Support\Facades\Schema::hasTable("finding_resolutions") ? "da" : "FEHLT");
'

srvpanel tinker --execute='
  foreach (App\Models\FindingResolution::query()->get() as $z) {
      printf("offen: %s / %s / %s / %s\n", $z->check->value, $z->subject, $z->reason, $z->channel);
  }
'

srvpanel notices
# (über die Unit fährt `systemctl start srvpanel-diagnose.service` beides:
#  erst die Messung, dann den Versand — zwei ExecStart-Zeilen einer Unit.)

srvpanel tinker --execute='
  printf("Warteschlange: %d\n", App\Models\FindingResolution::query()->count());
'
```

**Erwartet:** **zwei** offene Zeilen für denselben Gegenstand — eine je Kanal,
`mail` und `webhook`. {@see FindingLog::forgetMissing()} schreibt sie je
Buchung, und der Befund hatte zwei. Dann druckt `srvpanel notices`
`webhook: 1 Entwarnung(en) verschickt.`, beim Empfänger steht eine Meldung,
deren Kopf mit `behoben:` **vor** dem Namen des Dienstes beginnt, und danach
ist die Warteschlange **leer** — beide Zeilen.

**Die Zeile für `mail` verschwindet, ohne dass eine Mail hinausgeht, und das
ist die eigentliche Messung.** Der Mailkanal entwarnt nicht; seine Zeilen
löscht {@see Notices::deliver()} ungelesen. Bliebe sie stehen, nähme sie
niemand, und `finding_resolutions` wüchse mit jedem behobenen Befund. Bliebe
statt dessen die Webhook-Zeile stehen, wäre die Entwarnung nicht zugestellt,
sondern nur vorbereitet.

> **Zwei Zeilen hinein, keine hinaus, und genau eine davon ist unterwegs
> gewesen** — das ist der Unterschied zwischen einer Warteschlange und einem
> Protokoll.

> **Eine Warteschlange, aus der niemand nimmt, ist eine Tabelle, die wächst.**

### Punkt 8c · Und eine Entwarnung ohne vorangegangene Warnung gibt es nicht

```bash
# Einen Befund erzeugen und ihn VOR der Haltezeit wieder verschwinden lassen.
systemctl stop srvpanel-metrics.service
systemctl start srvpanel-diagnose.service
systemctl start srvpanel-metrics.service
sleep 5
systemctl start srvpanel-diagnose.service

srvpanel tinker --execute='
  printf("Warteschlange: %d\n", App\Models\FindingResolution::query()->count());
'
```

**Erwartet:** `Warteschlange: 0`. Der Befund stand keine zwanzig Stunden, ist
also nie gemeldet worden — und was nie hinausging, wird nicht zurückgenommen.

> **Eine Entwarnung ohne vorangegangene Warnung ist eine Meldung über
> nichts.**

### Punkt 9 · Ein Ziel, das abweist, bucht nichts

```bash
# Den Empfänger auf 500 stellen:
sed -i 's/http_response_code(204)/http_response_code(500)/' \
  "$WURZEL/haken/index.php"

systemctl stop srvpanel-metrics.service
systemctl start srvpanel-diagnose.service      # Lauf A — stellt den Zustand her
# … 20 Stunden später oder mit einem zweiten Zustand, der schon alt genug ist …
systemctl start srvpanel-diagnose.service      # Lauf B — sendet
journalctl -u srvpanel-diagnose.service -n 20 --no-pager
srvpanel tinker --execute='
  foreach (App\Models\FindingNotification::query()->get()->groupBy("channel") as $k => $g)
      printf("%-10s %d\n", $k, $g->count());
'
```

**Erwartet:** `webhook: N Nachricht(en) sind nicht angekommen. Sie bleiben
fällig.` — und in `finding_notifications` stehen Zeilen für `mail` und
**keine** für `webhook`. Das ist der Fall, für den es die Tabelle gibt.

**Wer einen Slack- oder Discord-Haken hat, misst hier §0 Punkt 5 mit:**
Empfänger wählen, Adresse eintragen, Probezustellung drücken — und im Kanal
nachsehen, dass der Satz dort ankommt und lesbar ist. Das ist die eine Sache
an dieser Stufe, die nur ein echter Empfänger sagen kann.

**Und die Gegenrichtung im selben Griff:** Bei Slack steht das Feld für das
Geheimnis gar nicht erst da — wer es über die Anfrage mitschickt, bekommt die
Ablehnung des Agenten.

### Punkt 10 · Ohne Ziel und ohne Relay wird nichts gebucht

```bash
# Ziel entfernen (auf /settings/notices, Knopf „Entfernen"), dann:
systemctl start srvpanel-diagnose.service
journalctl -u srvpanel-diagnose.service -n 15 --no-pager
```

**Erwartet:** `webhook: nicht eingerichtet — N Befund(e) bleiben fällig.` und
keine neue Buchung für diesen Kanal.

---

### Punkt 11 · Der gewählte Empfänger überlebt das Hinterlegen

**Der Punkt, den es ohne einen Befund vom 21. September 2026 nicht gäbe.**
`notify.target.store` verwarf den Empfänger und legte für jede Wahl `generic`
ab; wer Slack wählte, bekam die JSON-Form und von Slack ein `400`. Gemessen
wird deshalb nicht das Formular, sondern die **Ablage**.

```bash
# Auf /settings/notices „Slack" wählen, eine Adresse eintragen, speichern.
jq -r '.provider, .config' /etc/srvpanel/notify/webhook.json
```

**Erwartet:** `slack` und `[]` — ein leeres PHP-Array, und `json_encode`
schreibt dafür `[]` und nicht `{}`. Danach dasselbe mit **Telegram**, Adresse
`https://api.telegram.org/bot<marke>/sendMessage` und einem Chat:

**Erwartet:** `telegram` und `{"chat_id": "…"}` — und auf der Seite steht
weiterhin nur der Rechnername, kein Chat und keine Marke.

> **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
> keine.**

### Punkt 12 · Der Empfänger nimmt den Rumpf wirklich an

**Für den Empfänger, den der Betreiber hat** — einer genügt. Auf
`/settings/notices` den Knopf „Probezustellung" drücken und im Kanal, im
Telefon oder im Postfach nachsehen.

**Erwartet:** Die Meldung kommt an und trägt den Rechnernamen. Bei ntfy ist
der Rumpf der Text selbst; bei Gotify stehen Titel und Nachricht getrennt.
Danach **ein echter Befund** über denselben Weg (Punkt 6), damit nicht nur die
Probezustellung gemessen ist:

> **Ein Beleg für den Weg ist keiner für das Ziel.**

### Punkt 13 · Die Maschine bleibt, wie sie war

**Der Lauf hat zwei Gegenstände angehalten, und einer davon ist ein Zeitgeber,
den niemand vermisst, solange niemand hinsieht.** `srvpanel-dns.timer` gleicht
die DNS-Einträge ab; er steht seit Punkt 4 still, und Punkt 8 holt nur den
Dienst zurück.

```bash
systemctl start srvpanel-dns.timer
systemctl start srvpanel-metrics.service
sleep 5
systemctl is-active srvpanel-metrics.service srvpanel-dns.timer
systemctl list-timers srvpanel-dns.timer --no-pager

srvpanel diagnose
srvpanel tinker --execute='
  foreach (App\Models\Finding::withoutGlobalScopes()->orderBy("check")->get() as $b)
      printf("  %-16s %-28s %s\n", $b->check->value, $b->subject, $b->reason);
  printf("Buchungen: %d\n", App\Models\FindingNotification::query()->count());
'
```

**Erwartet:** beide `active`, der Zeitgeber hat wieder einen `NEXT`-Termin, und
die beiden Befunde aus Punkt 4 stehen **nicht** mehr da — übrig bleibt der
Bestand aus §1. Damit ist zugleich Punkt 8 ein zweites Mal gemessen, diesmal an
`unit.schedule` statt an `unit.state`: Auch dieser Befund verschwindet, wenn
sein Grund verschwindet.

**Steht zu diesem Zeitpunkt noch ein Meldeziel**, erzeugt der Lauf eine zweite
Entwarnung; wurde es in Punkt 10 entfernt, bleibt die Warteschlange für
`webhook` stehen, bis wieder eines da ist. Beides ist richtig, und welches von
beidem gilt, entscheidet die Reihenfolge, in der gefahren wurde — nicht der
Zufall.

> **Ein Abnahmelauf, der einen Zeitgeber angehalten lässt, hat den Server
> schlechter zurückgegeben, als er ihn vorgefunden hat.**

---

## §5 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Ob der Zeitgeber über viele Nächte trägt.** Er feuert in diesem Lauf
  einmal von selbst (Punkt 5); alles andere wird angestossen.
- **Ob Mattermost und Rocket.Chat unseren Rumpf annehmen.** Sie sagen in ihrer
  Dokumentation zu, Slacks Eingangshaken zu nehmen, und stehen deshalb als
  Hinweis neben dem Eintrag „Slack" — gemessen hat es niemand.
- **Die Empfänger, die der Betreiber nicht hat.** Punkt 12 misst einen; über
  die übrigen vier sagt dieser Lauf nichts.
- **Die Bündelung über mehr als zwei Gegenstände.** Punkt 6 trennt die beiden
  Kanäle an zwei Befunden auf zwei Gegenständen; dass ein Kunde mit Platz
  **und** Verkehr eine Mail mit zwei Zeilen bekommt, hält
  `NoticeAudienceTest` über drei Gegenstände und nicht dieser Lauf.
- **Den Fall „Prüfung nicht durchgelaufen".** `unreachable` ist
  `FindingState::Unknown` und wird bewusst nicht gemeldet; ob das für den
  Betreiber richtig ist, steht als Frage im Kopf von `Notices::due()`.
- **Die Kundenmail.** Sie ist seit B5 abgenommen; dieser Lauf misst den
  Betreiberweg und den Webhook.
- **Den Inhalt des Betreiberbriefs bei 390 px.** Es ist reiner Text.

---

## §6 · Wann er durch ist

**Erfüllt, wenn die Punkte 1 bis 8c, 10, 11, 12 und 13 erfüllt sind.**

**Punkt 6 und Punkt 7 dürfen nicht ausfallen** — sie sind das Kriterium: eine
Meldung über beide Kanäle, und beim nächsten Lauf keine.

**Punkt 8b darf ebenfalls nicht ausfallen** — er ist das Kriterium des
Ereignisses „behoben", und ohne ihn ist von aussen nicht zu unterscheiden, ob
eine Entwarnung hinausging oder nur eine Zeile verschwand.

**Punkt 5 darf ausfallen** (der Zeitgeber ist anderswo gemessen), **Punkt 3
entfällt**, wenn in §1 Block 4 kein beurteilter Befund steht, **Punkt 9
darf auf den Webhook-Teil verkürzt werden**, wenn kein zweiter alter Zustand zur
Hand ist, und **Punkt 12 darf auf die Probezustellung verkürzt werden**, wenn
in der Zeit des Laufs kein Befund nachwächst.

**Punkt 13 darf nicht ausfallen, und er ist keine Messung, sondern eine
Schuld.** Der Lauf hat einen Dienst und einen Zeitgeber angehalten; wer ihn
abbricht, holt wenigstens diesen Punkt nach.

**Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist nicht
„nicht herstellbar"** — er wird nachgeholt.
