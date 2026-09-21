# B1 — der Abnahmelauf

Ausgeschrieben am 24. September 2026, **vor** dem Fahren. Das Kriterium steht in
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
seit dem 24. September.** Beide verlangen einen Rumpf mit `text` beziehungsweise
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

```bash
# Auf einer Domain dieses Servers mit gültigem Zertifikat, z. B. haken.cloudlab24.de
D=/var/www/vhosts/<benutzer>/haken.cloudlab24.de/httpdocs
install -d -o <benutzer> -g <benutzer> "$D"
cat > "$D/index.php" <<'PHP'
<?php
file_put_contents(__DIR__.'/../haken.log',
    date('c')."\t".($_SERVER['HTTP_X_SRVPANEL_SIGNATURE'] ?? '-')."\t".file_get_contents('php://input')."\n",
    FILE_APPEND);
http_response_code(204);
PHP
chown <benutzer>:<benutzer> "$D/index.php"

# Gegenprobe, dass der Empfänger überhaupt annimmt — sonst misst Punkt 3 den Empfänger
curl -sS -o /dev/null -w '%{http_code}\n' -X POST -d '{"probe":1}' https://haken.cloudlab24.de/
tail -1 /var/www/vhosts/<benutzer>/haken.cloudlab24.de/haken.log
```

> **Eine Gegenprobe, deren Ausschlag vom Empfänger abhängt, gehört vor die
> Messung und nicht daneben.**

---

## §3 · Der Zeitplan

| | wann | was |
|---|---|---|
| **T0** | nachmittags | Punkte 1–2: Ziel hinterlegen, `http` abweisen lassen |
| **T0** | gleich danach | Punkt 3: **den stehenden Bestand abräumen** — der Lauf, der die Kette belegt |
| **T0** | gleich danach | Punkt 4: Dienst anhalten, Lauf fahren — und **jetzt** schweigt er |
| **T0 + ~10 h** | nachts, von selbst | Punkt 5: der Zeitgeber feuert und **schweigt zu Recht** |
| **T0 + 21 h** | am Morgen danach | Punkte 6–8: der Lauf sendet, der nächste schweigt, Dienst zurück |
| | anschliessend | Punkte 9–10: die Gegenrichtungen |

**Der nächtliche Lauf ist kein Störfall, sondern eine Messung, die sich von
selbst einstellt** — und sie gehört vorhergesagt. Wer sie nicht erwartet, liest
das Schweigen des Zeitgebers als Ausfall.

---

## §4 · Die Punkte

### Punkt 1 · Das Meldeziel steht, und die Adresse kommt nicht zurück

```bash
# Auf /settings/notices eintragen: Adresse + Geheimnis (mind. 16 Zeichen).
# Danach von der Kommandozeile gegenprüfen:
srvpanel tinker --execute='
  $t = app(App\Support\Notify\NotifyTarget::class);
  var_dump($t->reachable(), $t->describe());
'
grep -c 'haken.cloudlab24.de' /var/lib/srvpanel/*.log 2>/dev/null || true
ls -l /etc/srvpanel/notify/
```

**Erwartet:** `reachable` = `true`; `describe()` trägt `host`, `stored_at`,
`signed: true` — und **weder die volle Adresse noch das Geheimnis**. Die Datei
liegt `-rw------- root root` in einem `drwx------`-Verzeichnis.

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
systemctl start srvpanel-diagnose.service
journalctl -u srvpanel-diagnose.service -n 30 --no-pager

srvpanel tinker --execute='
  printf("Buchungen: %d\n", App\Models\FindingNotification::query()->count());
  foreach (App\Models\FindingNotification::query()->get()->groupBy("channel") as $k => $g)
      printf("  %-10s %d\n", $k, $g->count());
'
wc -l < /var/www/vhosts/<benutzer>/haken.cloudlab24.de/haken.log
```

**Erwartet, ausgerechnet aus der Bestandsaufnahme in §1 Block 4** — `N` ist die
Zahl der beurteilten Serverbefunde dort, `M` die Zahl verschiedener Gegenstände
darunter:

- `mail: 1 Nachricht(en) über N Befund(e)` — **eine** Mail, `N` Zeilen darin.
- `webhook: M Nachricht(en) über N Befund(e)`.
- `Buchungen: 2 × N`, davon `mail` = `N` und `webhook` = `N`.
- `M` neue Zeilen im Protokoll des Empfängers.

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
ZEILEN=$(wc -l < /var/www/vhosts/<benutzer>/haken.cloudlab24.de/haken.log)

systemctl start srvpanel-diagnose.service
journalctl -u srvpanel-diagnose.service -n 20 --no-pager

srvpanel tinker --execute='
  $b = App\Models\Finding::withoutGlobalScopes()
      ->where("check", "unit.state")->where("subject", "srvpanel-metrics.service")->first();
  printf("Befund: %s / %s seit %s\n", $b?->check->value ?? "-", $b?->reason ?? "-", $b?->first_seen_at ?? "-");
  printf("Buchungen: %d\n", App\Models\FindingNotification::query()->count());
'
echo "Buchungen vorher: $VORHER   Zeilen vorher: $ZEILEN"
wc -l < /var/www/vhosts/<benutzer>/haken.cloudlab24.de/haken.log
```

**Erwartet:** `unit.state / inactive` steht mit einem frischen `first_seen_at`
da, und daneben ein zweiter Befund auf `srvpanel-dns.timer`; beide Kanäle
drucken `0 Nachricht(en) über 0 Befund(e)`; die Zahl der Buchungen ist
**dieselbe wie vorher**; im Protokoll des Empfängers keine neue Zeile; kein
Brief.

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
systemctl show srvpanel-diagnose.timer -p LastTriggerUSec
journalctl -u srvpanel-diagnose.service --since '-14h' --no-pager | grep -E 'Nachricht|nicht eingerichtet|Befund'
srvpanel tinker --execute='printf("Buchungen: %d\n", App\Models\FindingNotification::query()->count());'
```

**Erwartet:** Der Zeitgeber hat gefeuert (`LastTriggerUSec` liegt in der
Nacht), und die Zahl der Buchungen ist **dieselbe wie in Punkt 4**. Die Frist
war noch nicht um.

> **Ein Zeitgeber, der feuert und nichts ändert, ist von einem, der nicht
> gefeuert hat, nur an seinem Zeitstempel zu unterscheiden** — deshalb steht
> `LastTriggerUSec` in derselben Ablesung und nicht daneben.

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
wc -l < /var/www/vhosts/<benutzer>/haken.cloudlab24.de/haken.log
tail -5 /var/www/vhosts/<benutzer>/haken.cloudlab24.de/haken.log
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
tail -1 /var/www/vhosts/<benutzer>/haken.cloudlab24.de/haken.log | awk -F'\t' '{print $2"\n"$3}' \
| { read SIG; read RUMPF;
    T=$(echo "$SIG" | sed 's/^t=\([0-9]*\),.*/\1/')
    V=$(echo "$SIG" | sed 's/.*v1=//')
    echo "gelesen : $V"
    printf '%s.%s' "$T" "$RUMPF" | openssl dgst -sha256 -hmac "$GEHEIM" -r | cut -d' ' -f1 | sed 's/^/gerechnet: /'
  }
```

**Erwartet:** zwei gleiche Zeilen. Eine Kopfzeile, die dasteht, sagt nichts
darüber, worüber sie gebildet wurde.

**Und die Seite:** `/settings/notices` trägt jetzt für **beide** Kanäle einen
Zeitpunkt unter „Zuletzt erfolgreich zugestellt". Bildschirmfoto in beiden
Themen und bei 390 px.

### Punkt 7 · Derselbe Zustand, keine zweite Meldung

Unmittelbar danach:

```bash
systemctl start srvpanel-diagnose.service
journalctl -u srvpanel-diagnose.service -n 15 --no-pager
srvpanel tinker --execute='printf("Buchungen: %d\n", App\Models\FindingNotification::query()->count());'
wc -l < /var/www/vhosts/<benutzer>/haken.cloudlab24.de/haken.log
```

**Erwartet:** `0 Nachricht(en) über 0 Befund(e)` auf beiden Kanälen, dieselbe
Zahl Buchungen wie am Ende von Punkt 6, dieselbe Zahl Zeilen im Protokoll,
keine zweite Mail.

### Punkt 8 · Der Dienst kommt zurück, und der Befund verschwindet mitsamt seiner Buchung

```bash
systemctl start srvpanel-metrics.service
systemctl is-active srvpanel-metrics.service
sleep 5
systemctl start srvpanel-diagnose.service

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

### Punkt 8b · Und der Empfänger erfährt, dass es vorbei ist

**Unmittelbar nach Punkt 8 und vor irgendeinem weiteren Lauf**, denn die
Warteschlange wird beim Zustellen geleert.

```bash
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

**Erwartet:** **genau eine** offene Zeile, und zwar für den Kanal `webhook` —
der Mailkanal entwarnt nicht, seine Zeile ist beim Lauf davor schon verbraucht
worden. Der Lauf druckt `webhook: 1 Entwarnung(en) verschickt.`, beim Empfänger
steht eine Meldung, deren Kopf mit `behoben:` **vor** dem Namen des Dienstes
beginnt, und danach ist die Warteschlange leer.

**Die Zeile für `mail` ist die Gegenprobe und nicht ein Rest.** Steht sie nach
dem Lauf noch da, verbraucht sie niemand, und `finding_resolutions` wächst mit
jedem behobenen Befund.

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
  /var/www/vhosts/<benutzer>/haken.cloudlab24.de/httpdocs/index.php

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

**Der Punkt, den es ohne einen Befund vom 24. September 2026 nicht gäbe.**
`notify.target.store` verwarf den Empfänger und legte für jede Wahl `generic`
ab; wer Slack wählte, bekam die JSON-Form und von Slack ein `400`. Gemessen
wird deshalb nicht das Formular, sondern die **Ablage**.

```bash
# Auf /settings/notices „Slack" wählen, eine Adresse eintragen, speichern.
jq -r '.provider, .config' /etc/srvpanel/notify/webhook.json
```

**Erwartet:** `slack` und `{}`. Danach dasselbe mit **Telegram**, Adresse
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

---

## §5 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Ob der Zeitgeber über viele Nächte trägt.** Er feuert in diesem Lauf
  einmal von selbst (Punkt 4); alles andere wird angestossen.
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

**Erfüllt, wenn die Punkte 1 bis 8c, 10, 11 und 12 erfüllt sind.**

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

**Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist nicht
„nicht herstellbar"** — er wird nachgeholt.
