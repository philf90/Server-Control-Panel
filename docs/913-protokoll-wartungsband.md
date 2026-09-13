# Protokoll zum Abnahmelauf des Wartungsbands

Gefahren am **13. September 2026 auf `cloudsrv24`** gegen **`0.7.4-rc.6`**. Der
Plan des Merkmals ist `docs/911`, der Lauf `docs/912`. Angelegt nach §1, während
der Lauf läuft — je Punkt der **gemessene** Wert, nicht der erwartete.

---

## §1 Vorbereitung — gemessen

| | gemessen |
|---|---|
| `srvpanel version` | `0.7.4-rc.6` |
| Agent kennt `web.maintenance.state` | ja — `enabled: false`, `flag: /var/spool/srvpanel/wartung` (27 Zeichen) |
| `srvpanel-agentd` · `srvpanel-worker` | `active` · `active` |
| `/var/spool/srvpanel/wartung` | fehlt (`No such file or directory`) |
| `Clock::label()` | `CEST (UTC+02:00)` |
| `Clock::labelAt(jetzt)` | `CEST (UTC+02:00)` |
| Ablage `maintenance` | `enabled: false`, `until: null`, `since: null` |
| Abzeichen **N** | **3** |
| Prüfkörperdomain | `cloudlab24.ipv64.de` |
| ihr heiler Zustand **V** | **200** |

**Die Vorbedingung ist damit zugleich die erste Messung des neuen Merkmals auf
einem Server.** Die Operation antwortet, sie nennt denselben Pfad, den die Wache
im Server-Block trägt, und ihr Urteil deckt sich mit den beiden anderen Quellen
des Ausgangszustands: `ls` sagt „fehlt", die Ablage sagt `enabled: false`, der
Agent sagt `false`. Drei unabhängige Wege, ein Zustand — und genau dass diese
drei auseinanderlaufen können, ist der Grund für die Prüfung, die in Punkt 7
und 9 gemessen wird.

**Die beiden Zonenwerte stimmen überein, und das musste nicht so sein.**
`label()` gilt für „jetzt", `labelAt()` für einen genannten Zeitpunkt; im
September fallen sie zusammen, im Januar nicht. Gemessen sind beide, damit im
Protokoll steht, wogegen der Satz des Bandes später gehalten wird — und nicht
nur, dass er „eine Zone" trägt.

**Die drei Befunde des Ausgangszustands**, ausgeschrieben, weil das Abzeichen
später gegen ihre Zahl gemessen wird:

| Prüfung | Grund | Gegenstand |
|---|---|---|
| `orphan.row` | `certificate` | `tls.cloudlab24.de` |
| `tls.file` | `expiring` | `p6-b.invalid` |
| `unit.schedule` | `no_next` | `srvpanel-diagnose.timer` |

Der erste ist der benannt offene Rest aus P7 (`docs/113 §13`). Der dritte ist
neu und steht als Beobachtung 1.

**Sechs lebende Domains ohne Aliasse** — `p6-b.invalid`, `p6-abnahme.invalid`,
`cloudlab24.de`, `cloudlab24.ipv64.de`, `domain-mit-richtig-langem-namen.invalid`,
`neu.cloudlab24.ipv64.de`. Punkt 3 wird damit sechs Vorgänge anlegen; die
Zahl steht hier, damit sie später nicht wie ein Befund aussieht.

---

## Befund 1 — die Vorbedingung konnte nur rot sein *(im Prüfmittel)*

`docs/912 §1` verlangte in seinem ersten Wurf

```
grep -c 'web\.maintenance\.state' /opt/srvpanel/current/agent/src/Registry.php   # erwartet: 1
```

und bekam auf dem Server **`0`** — auf einer Fassung, die das Merkmal
nachweislich enthält (`v0.7.4-rc.6` zeigt auf `a78a66f9`, und
`git merge-base --is-ancestor` bestätigt den Band-Commit darin). Der berichtigte
Griff hat es danach in derselben Minute belegt: Der Agent beantwortet die
Operation.

**Die Datei war die richtige, die Zeichenkette nicht.** `Registry.php`
registriert die **Klasse** — `use SrvPanel\Agent\Ops\WebMaintenanceState;` und
`$this->register(new WebMaintenanceState);` —, den gepunkteten Namen trägt die
Operation selbst in `Ops/WebMaintenanceState.php`. Dieselbe Zeile gegen den
Arbeitsbaum gefahren, in dem das Merkmal unzweifelhaft steht, ergibt ebenfalls
`0`.

> **Eine Vorbedingung, die man nicht gegen den heilen Fall gemessen hat, ist
> keine Prüfung — sie ist eine Behauptung, die auch im heilen Fall rot ist.**

Das ist die Schwester von „Ein leerer Griff in die falsche Datei sieht aus wie
ein Befund" (`docs/78`) — hier die **richtige** Datei und die falsche
Zeichenkette, und deshalb umso überzeugender: Pfad, Fassung und Rückgabewert
waren alle plausibel.

**Und der Prüfkörper lag die ganze Zeit daneben.** Die Datei, die der Griff auf
dem Server liest, liegt byteweise gleich im Arbeitsbaum dieses Containers —
`packaging/build.sh` kopiert `agent` unverändert nach `build/release`. Eine
Zeile hätte den Irrtum vor dem Ausschreiben gezeigt.

**Berichtigt** (`docs/912 §1`): Gefragt wird jetzt der **laufende Agent** und
keine Datei —

```
srvpanel tinker --execute='var_dump(app(SrvPanel\Agent\Client::class)->call("web.maintenance.state"));'
```

Er belegt nicht, dass eine Zeichenkette irgendwo steht, sondern dass die
Operation beantwortet wird; lesend ist er gefahrlos (`mutating() === false`).
Damit misst die Vorbedingung dasselbe wie der Rest des Laufs: durch die Tür.

---

## Beobachtung 1 — der Nachtlauf hat keinen nächsten Termin

`unit.schedule / no_next — srvpanel-diagnose.timer` stand schon im
Ausgangszustand da. Das ist genau die Form, für die es A2 gibt:

> **Ein Dienst, der „active" meldet und keinen nächsten Termin hat, ist
> abgeschaltet und sieht aus wie eingeschaltet.**

**Für diesen Lauf ist es kein Hindernis** — `srvpanel diagnose` wird von Hand
gefahren, und `docs/912 §11` nimmt das Feuern des Timers ausdrücklich aus. Es
erklärt aber, warum der Abgleich aus diesem Merkmal auf diesem Server bis auf
Weiteres **nicht** von selbst liefe, und gehört deshalb hierher und nicht in
eine Fussnote.

Ungeklärt ist, ob der Timer ihn je hatte oder ihn verloren hat; gemessen ist
nur der Zustand von heute.

---

## §2 Punkt 1 — der Ausgangszustand ist der Prüfkörper *(erfüllt)*

Auf `/`, angemeldet als Betreiber, 1440 px:

```
{url: '/', prop: null, band: null, hoehe: null, abzeichen: 3}
```

Kein Band, keine Ablage, und das Abzeichen am Menüpunkt „Diagnose" zeigt
sichtbar **3** — dieselbe Zahl, die `PendingFindings::count()` auf der
Kommandozeile gab. Damit ist auch der Weg des Abzeichens zum ersten Mal auf
einem Server belegt, ganz nebenbei.

**Ohne diesen Punkt sagten die folgenden nichts.** Ein Band, das immer dasteht,
belegt nicht, dass es einen Zustand zeigt.

---

## §3 Punkt 2 — eingeschaltet, und die Behauptung gegengeprüft *(erfüllt)*

Eingeschaltet über `/maintenance`, Endzeit leer.

**Im Panel** (1440 px):

```
{url: '/maintenance', prop: Proxy(Object),
 band: 'Wartung Alle Kundenwebsites antworten mit 503 — seit 2026-09-13 10:21 Uhr (CEST (UTC+02:00)).',
 hoehe: 41, abzeichen: 3}
```

**Auf dem Server:**

```
-rw-r--r-- 1 root root 286 Sep 13 10:21 /var/spool/srvpanel/wartung
503
```

| | erwartet | gemessen |
|---|---|---|
| Satz | „… mit 503 — seit … Uhr (Zone)." | wörtlich so |
| Zone im Satz | die des Servers | `CEST (UTC+02:00)` |
| Höhe bei 1440 px | 41 px | **41 px** |
| Flagdatei | liegt | liegt, 286 Bytes, `10:21` |
| `cloudlab24.ipv64.de` | 503 | **503** |

**Die Zone ist der Teil, den nur ein Server sagen kann.** `docs/911 §6e` hat
denselben Satz im Container gemessen und dort stand `UTC`; der Weg war belegt,
der Wortlaut nicht. Jetzt steht er da.

**Und der Zwilling stimmt zum dritten Mal.** 41 px bei 1440 px ist auf das Pixel
der Wert aus `docs/911 §6e`. Nach M4b (214 px für drei Bänder) und der
Messrunde selbst ist das die dritte Übereinstimmung zwischen Nachbau und echter
Seite.

**Die 503 ist der Kern dieses Punktes und nicht sein Beiwerk.** Das Band
behauptet etwas über die Welt draussen; gemessen ist es an einer echten Domain
über die echte Leitung. Im Container gibt es keine, die antworten könnte.

---

## Beobachtung 2 — auf `/maintenance` steht der Zustand dreimal

Nach dem Einschalten trägt genau diese Seite

1. das Band ganz oben,
2. die grüne Erfolgsmeldung „Der Wartungsmodus ist eingeschaltet. Alle
   Kundenwebsites antworten mit 503.",
3. den roten Streifen der Seite „Der Wartungsmodus ist **eingeschaltet**. Alle
   Kundenwebsites antworten mit 503; das Panel und die Zertifikatsprüfung
   bleiben erreichbar."

Dazu zeigt das Band einen Verweis auf die Seite, auf der man schon steht.

**Kein Kriterium ist verletzt, und das Band gehört dorthin** — dass es auf
`/maintenance` verschwände, war der Befund, den `SharedPropTest` vor dem
Ausliefern gefangen hat (`docs/911 §6b`): Es ist die **einzige** Seite, auf der
man ausschaltet. Ein Band, das genau dort fehlt, fehlt am wichtigsten Ort.

Die Erfolgsmeldung ist flüchtig und verschwindet mit der nächsten Navigation;
übrig bleiben zwei dauerhafte Aussagen und ein Verweis auf sich selbst. Ob das
bleibt, ist eine Entscheidung des Betreibers und keine Frage an einen Wächter —
niemand hat beim Entwurf gefragt, wie das Band **auf** `/maintenance` aussieht.

> **Ein Bedienelement, das auf die Seite verweist, auf der es steht, ist kein
> Fehler — es ist eine Frage, die beim Entwurf nicht gestellt wurde.**

### Punkt 2 — die Ablage und die Warteschlange

Nachgereicht, weil die Konsole beim ersten Mal `Proxy(Object)` gedruckt und den
Inhalt weggeklappt hatte (`docs/903`):

```json
{"since":"2026-09-13 10:21","since_zone":"CEST (UTC+02:00)",
 "until":null,"until_zone":null,"overdue":false}
```

Und auf `/operations` stand als jüngster Vorgang weiterhin **850**
(`system.packages.refresh` vom 11. September): **null** neue Vorgänge. Die
Endzeit war `null` und blieb `null`, also schreibt `MaintenanceMode::set()`
keinen Server-Block neu — gemessen und nicht nur behauptet.

---

## §4 Punkt 3 — die Endzeit kommt dazu, und die Dauer springt nicht *(erfüllt)*

**Der Rundlauf ist da und stimmt in der Zahl.** Auf `/operations` stehen die
Vorgänge **851 bis 856**, alle `web.site.apply`, alle `fertig`, alle
`2026-09-13 10:32:01` bis `10:32:02` — **sechs**, also genau eine je lebender
Nicht-Alias-Domain aus §1.

Das ist die Bauart aus `docs/101` und kein Befund: Die Endzeit steht im
Server-Block jeder Domain, also müssen die Blöcke neu geschrieben werden, wenn
sie sich ändert.

**Und die eigentliche Messung dieses Punktes**, nachgereicht:

```json
{"since":"2026-09-13 10:21","since_zone":"CEST (UTC+02:00)",
 "until":"2026-09-13 12:30","until_zone":"CEST (UTC+02:00)","overdue":false}
```

`since` steht **unverändert** auf `10:21`, obwohl in derselben Handlung eine
Endzeit gesetzt wurde. Das ist der Gegenstand dieses Punktes und nicht das
`until`:

> **Ein Wert, der bei jeder Änderung neu entsteht, misst die letzte Änderung und
> nicht den Zustand.**

Der Satz im Band lautet dazu

```
Wartung Alle Kundenwebsites antworten mit 503 — seit 2026-09-13 10:21 Uhr
(CEST (UTC+02:00)), voraussichtlich bis 2026-09-13 12:30 Uhr (CEST (UTC+02:00)).
```

und die Höhe bleibt bei 1440 px **41 px**, also eine Zeile — trotz der zweiten
Zeitangabe mit ihrer Zone.

**Die erste Messung dieses Punktes war keine**, und sie sah wie eine aus: Sie
stand mit `until: null` da, weil sie **vor** dem Setzen gefahren wurde. Die
Formularfelder daneben waren leer und haben es verraten.

> **Zwei Messungen desselben Griffs unterscheiden sich durch den Zeitpunkt und
> nicht durch den Befehl — welche man vor sich hat, sagt nur der Zustand
> daneben.**

---

## Befund 2 — zwei Zertifikatsbestellungen sind fehlgeschlagen *(geklärt: harmlos)*

Unmittelbar nach dem Rundlauf stehen zwei weitere Vorgänge:

| Nr. | Aufgabe | Zustand | Zeit |
|---|---|---|---|
| 857 | `acme.certificate.issue` | **fehlgeschlagen** | `10:32:02` |
| 858 | `acme.certificate.issue` | **fehlgeschlagen** | `10:32:03` |

**Dass überhaupt bestellt wird, ist die Bauart und kein Befund.**
`CertificateLifecycle::afterSuccess()` behandelt `web.site.apply` und ruft
danach `request($domain, …)`: Jede angewandte Domain fragt, ob sie ein
Zertifikat braucht. Sechs Rundläufe können also Bestellungen nach sich ziehen.

**Warum zwei davon scheitern, ist noch nicht gemessen, und es gibt zwei
Erklärungen mit sehr verschiedenem Gewicht:**

1. **Harmlos.** Drei der sechs Domains liegen unter `.invalid` und können von
   Let's Encrypt grundsätzlich nicht geprüft werden. `docs/78` hat genau das
   schon einmal festgehalten: zwei Bestellungen aus `vhost --sites` galten
   Namen unter `.invalid` und sind zu Recht abgewiesen worden. Dazu passt, dass
   `tls.file / expiring — p6-b.invalid` schon im Ausgangszustand stand — das
   Wegwerfzertifikat aus `docs/100 §6` läuft ausgerechnet **heute** aus.
2. **Schwer.** Der Wartungsmodus blockiert die ACME-Prüfadresse. Genau dagegen
   gibt es die Ausnahme in der Wache (`docs/101` M24, M28), und genau dieser
   Fall — „während einer Wartung stürbe jede Zertifikatserneuerung" — ist der
   Grund, aus dem A12 nicht mit einem einfachen `if` gebaut wurde.

> **Ein Fehlschlag, der zwei Erklärungen hat, ist so lange keine von beiden, bis
> jemand nachgesehen hat — und die bequemere zuerst zu glauben ist die
> teuerste Gewohnheit.**

**Gemessen an den Vorgängen selbst: Erklärung 1.** Beide nennen einen Namen
unter `.invalid`, und beide tragen dieselbe Meldung der Zertifizierungsstelle:

| Nr. | Domain | Meldung |
|---|---|---|
| 857 | `p6-abnahme.invalid` | `rejectedIdentifier` — *Cannot issue for „p6-abnahme.invalid": Domain name does not end with a valid public suffix (TLD)* |
| 858 | `domain-mit-richtig-langem-namen.invalid` | dieselbe Meldung mit ihrem Namen |

Der Wartungsmodus hat damit nichts zu tun, und der Prüfling ist entlastet.
**Er ist damit aber nicht bestätigt**, und dieser Unterschied ist der Grund,
warum hier eine Zeile mehr steht als „harmlos":

> **Ein Fehlschlag, der vor dem Prüfschritt entsteht, sagt über den Prüfschritt
> nichts.**

`rejectedIdentifier` fällt bei der Bestellung — die Zertifizierungsstelle lehnt
den **Namen** ab, bevor irgendeine Prüfadresse abgerufen wird. Ob die Ausnahme
in der Wache während einer Wartung trägt, ist damit weder widerlegt noch
belegt; belegt hat es `docs/102` (A12, Punkt 8), und zwar an einem anderen Tag
und in einer anderen Fassung. Weil der Zustand gerade steht und der Griff eine
Zeile kostet, wird er hier nachgemessen — siehe Beobachtung 3.

---

## §5 Punkt 4 — die überschrittene Endzeit steht vorn *(erfüllt · Ausschlusskriterium)*

Endzeit auf `13.09.2026 10:30` gesetzt, also **nach** dem Beginn und vor jetzt.
Der Satz im Band lautet danach wörtlich:

```
Wartung Die angekündigte Endzeit ist seit 2026-09-13 10:30 Uhr (CEST (UTC+02:00))
vorbei. Alle Kundenwebsites antworten mit 503, seit 2026-09-13 10:21 Uhr
(CEST (UTC+02:00)).
```

| | erwartet | gemessen |
|---|---|---|
| Reihenfolge | überschrittene Endzeit **zuerst** | zuerst |
| zweiter Teil | „… mit 503, seit … Uhr (Zone)." | wörtlich so |
| Höhe bei 1440 px | eine Zeile | **41 px** |

**Der Satz schlägt um, und er schlägt in die richtige Richtung um.** Ein Band,
das nach der angekündigten Endzeit weiter „voraussichtlich bis" sagt,
wiederholt ein abgelaufenes Versprechen — es schwiege in genau dem Augenblick,
für den es gebaut ist. Das ist der Grund, aus dem dieser Punkt nicht ausfallen
darf, und er ist erfüllt.

**Was hier abgelesen und was gefolgert ist**, damit es später niemand
verwechselt: Der **Satz** ist gemessen, aus der Seite und aus `stand().band`.
`overdue: true`, `until = 10:30` und `since = 10:21` sind daraus **gefolgert** —
die überschrittene Fassung steht in einem `v-if` auf `overdue`, und beide
Zeitangaben stehen im Satz. Die Ablage selbst ist an diesem Punkt nicht noch
einmal ausgelesen worden.

### Nebenbei gemessen: der Rundlauf durch das Formular, mit echtem Versatz

Das Formular zeigt nach dem Speichern `13.09.2026` und `10:30` — also genau das
Eingetippte. Der Weg dahin ist `Clock::minuteToUtc()` beim Schreiben (`10:30`
CEST wird `08:30` UTC) und `Clock::minute()` samt Schnitt am Leerzeichen beim
Lesen.

**Im Container ist dieser Weg nicht prüfbar.** Dort ist die Anzeigezone UTC, und
eine fehlende Umrechnung sähe aus wie eine gelungene — genau die Falle, deretwegen
`MaintenanceRoundTripTest` mit einem **Versatz** misst. Hier trägt der Server
`CEST (UTC+02:00)`, und der Rundlauf stimmt über zwei Stunden Versatz hinweg.

> **Eine Prüfzone ohne Versatz lässt eine fehlende Umrechnung wie eine gelungene
> aussehen.**

---

## Beobachtung 3 — die ACME-Ausnahme trägt während der Wartung

Nachgemessen, weil Befund 2 den Prüfling entlastet, aber nicht bestätigt hat:

```
https://cloudlab24.ipv64.de/.well-known/acme-challenge/abc123XYZ_-   → 404
https://cloudlab24.ipv64.de/                                          → 503
```

Beide Zeilen im selben Augenblick, bei eingeschaltetem Wartungsmodus. Die
Ausnahme in der Wache (`if ($request_uri ~ ^/\.well-known/acme-challenge/…`)
greift also: Die Prüfadresse geht durch, alles andere nicht.

**Das ist keine Wiederholung von `docs/102`, sondern dieselbe Frage an eine
andere Fassung** — A12 ist gegen `0.7.3-rc.19` abgenommen, hier steht
`0.7.4-rc.6`, und dazwischen liegen das Band, die lesende Operation und eine
neue Prüfung. Der Griff kostete eine Zeile.

Die `404` ist dabei der richtige Wert und nicht ein zweiter Fehler: Die
Prüfdatei gibt es nicht, also antwortet der Webserver normal — und „normal" ist
genau das, was die Ausnahme herstellen soll.

---

## §6 Punkt 5 — die Bilderrunde, und zwei Bänder stapeln *(erfüllt)*

Gefahren auf `/announcements`, mit einer angelegten Ankündigung (Rang **Info**,
Text „Das ist ein Test"), also mit **zwei** Bändern übereinander. Vier Lagen,
jede in einer frisch geladenen Seite:

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` | `versteckt` |
|---|---|---|---|---|---|
| 1440 px dunkel | **0** | 200 (soll 200) | 0 | 0 | 0 |
| 1440 px hell | **0** | 200 | 0 | 0 | 0 |
| 390 px dunkel | **0** | 200 | 0 | 0 | 2 |
| 390 px hell | **0** | 200 | 0 | 0 | 2 |

**`stand=2026-09-06` in allen vier**, und das ist die Zahl, die im Repo steht —
der eingefügte Messaufsatz war der aktuelle und keiner aus der Zwischenablage
von vorgestern. Genau dafür gibt es das Feld.

**Die `versteckt=2` bei 390 px sind kein Befund**, sondern die eigene Buchhaltung
des Messmittels: Es zählt die Überläufe, die es weglässt, weil sie nur zum
Vorlesen da sind.

> **Kein stiller Deckel: Wer die Sicht begrenzt, nennt die Zahl dazu.**

**Was fehlt, ist die Lage der Bänder.** Der Griff danach hat in allen vier Lagen
`(2) [{…}, {…}]` gedruckt — zwei Einträge, und die Werte weggeklappt. Damit ist
die **Zahl** der Bänder gemessen und ihre **Lage** nicht.

> **Ein Objekt in der Konsole zeigt fünf Schlüssel und klappt den Rest weg — und
> was man abschreibt, ist dann eine Auswahl, die niemand getroffen hat.**

Dieselbe Falle, vor der `tests/bilder-messen.js` im eigenen Kopf warnt und
deretwegen es sein Urteil zusätzlich als **eine Zeile** druckt. Der Griff
daneben hatte diese Zeile nicht — er war für diesen Lauf neu getippt.

> **Ein Messmittel, das man aufhebt, macht die Fehler von letztem Mal nicht noch
> einmal** — und eines, das man daneben frisch tippt, macht sie doch.

**Nachgereicht als Zahl, und damit ist der Punkt erfüllt:**

| Breite | Wartungsband | Ankündigung | Fuge |
|---|---|---|---|
| 390 px | `oben 0`, `hoch 125` | `oben 133`, `hoch 41` | 8 px |
| 1440 px | `oben 12`, `hoch 41` | `oben 61`, `hoch 41` | 8 px |

Die Oberkanten wachsen streng, und die Rechnung geht in beiden Breiten auf
(`0+125+8 = 133`, `12+41+8 = 61`). Sie **stapeln**. Damit ist die Falle aus
`docs/103` — drei Bänder bei 1440 px übereinander, bei `schiebt = 0` — auf einem
Server ausgeschlossen und nicht nur im Nachbau.

**Und der Zwilling geht hier auseinander, aus einem Grund, der ihn bestätigt.**
`docs/911 §6e` hat für die überschrittene Fassung bei 390 px **104 px**
gemessen, hier sind es **125**. Der Unterschied ist die Zone: Im Container
heisst sie `UTC` (drei Zeichen), auf dem Server `CEST (UTC+02:00)` (17), und der
Satz trägt sie **zweimal**. Bei 1440 px, wo beides in eine Zeile passt, stimmen
die Zahlen wieder auf das Pixel (41).

> **Ein Zwilling, der bei der schmalen Breite abweicht und bei der breiten
> übereinstimmt, weicht nicht in der Form ab, sondern im Inhalt.**

---

## §7 Punkt 6 — wer nicht schalten darf, sieht es nicht *(erfüllt)*

Über „Anmelden als" in die Sicht eines Kunden gewechselt, bei laufender Wartung
und überschrittener Endzeit. Auf `/` steht danach:

| | |
|---|---|
| Impersonationsband | **da** — „Sie arbeiten in der Sicht dieses Kunden…" |
| Ankündigungsband | **da** — „Info Das ist ein Test" |
| **Wartungsband** | **fort** |

**Der Prüfkörper ist besser, als er bestellt war.** Gefragt war, ob das
Wartungsband verschwindet; gemessen ist zusätzlich, dass die Hülle darum
**weiterhin rendert** und ein anderes Band darin steht. Ein leeres `.bands` hätte
dieselbe Abwesenheit erzeugt und wäre von einem Rechteschnitt nicht zu
unterscheiden gewesen.

> **Eine Abwesenheit belegt eine Grenze erst, wenn daneben etwas anwesend ist,
> das dieselbe Hülle braucht.**

Dass die Ankündigung dem Kunden erscheint, ist dabei richtig und kein
Nebenbefund: Ihr Publikum ist „Betreiber · Administrator · Kunde" (A14). Die
Navigation daneben ist die des Kunden — kein „Wartungsmodus", keine „Diagnose".

**Was gemessen ist und was nicht.** Der Zustand ist von der **Seite** abgelesen.
Die Konsolenwerte fehlen: `stand` war nach dem Wechsel nicht mehr definiert, der
Griff aus §1 also nicht neu eingefügt — und weil beide Zeilen zusammen abgesetzt
wurden, riss der `ReferenceError` die zweite mit.

> **Zwei Zeilen in einem Absatz sind eine Anweisung — schlägt die erste fehl,
> ist auch die zweite nicht gemessen.**

Für das Kriterium reicht die Seite: Das Wartungsband ist ein `<a>` mit
`href="/maintenance"`, und ein solches steht dort nicht. Was die Ablage in
diesem Augenblick sagte, ist nicht abgelesen worden.

**Was dieser Punkt misst und was nicht:** Er misst, dass die Tür beisst. Dass es
die **richtige** Tür ist — `AdminAbility::OPERATE_SERVER` und keine andere —,
hält `MaintenanceBandTest` in der CI und ist dort mit einem Eingriff belegt.
Ein Administrator ist hier nicht geprüft worden; `docs/912 §0` sagt warum.

### Die Gegenprobe zu Punkt 6

Nach „Zurück zur Verwaltung" steht das Band wieder da, mit dem vollen Satz und
der Ankündigung darunter. Ohne diesen Blick bliebe offen, ob es überhaupt noch
erscheint — und der Punkt meldete ein Verschwinden, das mit der Fähigkeit nichts
zu tun hat.

---

## §8 Punkt 7 und 8 — die Datei verschwindet, und das Abzeichen trägt es *(erfüllt)*

`rm /var/spool/srvpanel/wartung` bei weiterhin eingeschalteter Ablage.

```
curl https://cloudlab24.ipv64.de/            → 200
srvpanel diagnose                            → 8 Prüfung(en) gefahren, 2026-09-13 10:57:03.
                                               Auffällig: 4
```

**Die Website ist im selben Augenblick wieder erreichbar** (`200`, also `V`) —
nginx liest die Datei bei jeder Anfrage. Genau das ist der Zustand, den niemand
bemerkt: Das Panel führt eine Wartung, die keine mehr ist.

**Acht Prüfungen statt sieben.** `docs/911 §2` M8 hat „alle sieben Prüfungen der
Bestandsdiagnose" ausgezählt; der Nachtlauf fährt jetzt acht. Die neue ist im
Katalog angekommen, und das steht hier, weil es die billigste Art ist, es zu
belegen.

**Die Befundliste danach:**

| Prüfung | Grund | Gegenstand | |
|---|---|---|---|
| `orphan.row` | `certificate` | `tls.cloudlab24.de` | aus §1 |
| `tls.file` | `expiring` | `p6-b.invalid` | aus §1 |
| `maintenance.window` | `overdue` | `cloudsrv24.de` | **A12**, nicht dieses Merkmal |
| **`maintenance.flag`** | **`missing`** | **`/var/spool/srvpanel/wartung`** | **Punkt 7** |

Der Gegenstand ist der Pfad, den der **Agent** genannt hat, und nicht die
Konstante des Panels — beide stimmen überein, und dass sie es müssen, sagt keine
Zeile, sondern die Wache im Server-Block, die denselben Pfad trägt.

**Und das Band behauptet weiter, es sei Wartung** (`prop` gesetzt, Satz
unverändert, `hoehe: 41`). Das ist die Grenze aus `docs/911 §2` M8, auf einem
Server gemessen statt hergeleitet — und **kein Mangel**: Das Band liest die
Ablage, und dass die beiden auseinanderlaufen können, ist der Grund für die neue
Prüfung.

### Punkt 8 — und warum die Zahl allein ihn nicht belegt hätte

`abzeichen: 4` im Payload, und die Seite zeigt dieselbe 4 am Menüpunkt
„Diagnose".

**Die Differenz zu §1 ist `+1`, und sie entsteht aus drei Änderungen.** Der
Ausgangsstand hatte `unit.schedule / no_next — srvpanel-diagnose.timer`; der
steht jetzt **nicht mehr** da. Dafür sind zwei gekommen. 3 − 1 + 2 = 4.

> **Eine Zahl, die um eins gestiegen ist, belegt keine Zunahme um eins — sie
> belegt eine Summe.**

Belegt ist Punkt 8 deshalb nicht durch die Differenz, sondern dadurch, dass die
**Liste hinter der Zahl** den neuen Befund führt und die Zahl zu der Liste passt.
`docs/912 §0` hatte verlangt, vorher und nachher zu messen statt eine absolute
Zahl zu erwarten; nötig war am Ende, die **Zeilen** zu vergleichen und nicht die
Zahlen.

---

## Beobachtung 1, nachgetragen — der Befund war vergänglich

`unit.schedule / no_next — srvpanel-diagnose.timer` stand um **10:16** in der
Liste und um **10:57** nicht mehr. Dazwischen liegt kein Griff dieses Laufs, der
einen Timer anfasst.

**Warum er verschwand, ist nicht gemessen.** Die naheliegende Erklärung — das
Paket `0.7.4-rc.6` war kurz zuvor eingespielt worden, und unmittelbar nach einer
Installation hat ein Timer seinen nächsten Termin noch nicht — ist eine
Vermutung und steht hier als solche.

> **Ein Befund, der zwischen zwei Läufen von selbst verschwindet, ist damit nicht
> erklärt — er ist nur nicht mehr da.**

Für diesen Lauf ändert es nichts; für die Nacht schon, denn der Nachtlauf braucht
den Termin.

---

## §9 Punkt 9 — noch offen, und der erste Anlauf hat etwas anderes gemessen

Der Lauf sollte mit dem **Ausschalten** beginnen. Es ist nicht geschehen: Die
Anweisung nannte es in Prosa, alles danach stand in einem Block zum Einfügen.

> **Ein Schritt, der nicht im Block steht, wird nicht ausgeführt — er wird
> gelesen.**

Was daraus folgte, ist Zeile für Zeile schlüssig und nur nicht das Gemeinte:

| Zeit | Zustand | Ablage | Datei | `maintenance.flag` | Auffällig |
|---|---|---|---|---|---|
| `11:04:38` | wie nach Punkt 7 | **an** | fehlt | `missing` | 4 |
| `11:04:56` | nach `touch` | **an** | liegt | **fort** | 3 |
| `11:05:21` | nach `rm` | **an** | fehlt | `missing` | 4 |

**Der `touch` hat die beiden nicht auseinandergebracht, sondern
zusammengeführt.** `unexpected` verlangt Ablage **aus** und Datei **da**; hier
war die Ablage an, und die Datei brachte die Übereinstimmung zurück, die Punkt 7
zerstört hatte.

**Punkt 9 ist damit nicht gemessen**, und er ist eines der beiden
Ausschlusskriterien.

### Was der Fehlgriff trotzdem belegt hat *(und es ist nicht wenig)*

Die Prüfung **verfolgt den Zustand und rastet nicht ein** — in beide Richtungen,
an drei Läufen hintereinander, jeder mit der Zahl daneben:

- Ablage und Datei auseinander → Befund **da**
- in Übereinstimmung gebracht → Befund **fort**
- wieder auseinander → Befund **wieder da**

Das ist die Gegenprobe, die Punkt 9 in seinem ersten Schritt ohnehin vorsah, nur
über die andere Kante gefahren: nicht „ausschalten, bis beide leer sind", sondern
„die Datei zurücklegen, bis beide übereinstimmen". Ein Befund, der einmal
geschrieben und nie wieder geprüft würde, hätte hier zweimal stehen bleiben
müssen.

> **Ein Befund, der verschwindet, wenn sein Grund verschwindet, ist gemessen. Ein
> Befund, der nur entsteht, ist abgelegt.**

Und nebenbei ist die **Richtung** belegt, um die es der Prüfung geht: Dieselbe
Datei erzeugt einmal einen Befund und einmal keinen — es zählt nicht ihr Dasein,
sondern ihr Verhältnis zur Ablage.

### Was der Zustand jetzt ist

Ablage **an**, Datei **fehlt**, Kundenwebsites **erreichbar** (`200`), Band
behauptet Wartung, Abzeichen **4**. Punkt 9 fängt von hier an.

### Zweiter Anlauf — erfüllt *(Ausschlusskriterium)*

**Block A, die Vorbedingung belegt statt vorausgesetzt:**

```
["enabled"]=> bool(false)
["until"]=>   string(19) "2026-09-13 08:30:00"
["since"]=>   NULL
8 Prüfung(en) gefahren, 2026-09-13 11:09:33.  Auffällig: 2
  orphan.row / certificate — tls.cloudlab24.de
  tls.file / expiring — p6-b.invalid
```

Beide Wartungsbefunde sind fort — `maintenance.flag`, weil Ablage und Datei
wieder übereinstimmen, und `maintenance.window`, weil es ohne eingeschalteten
Modus keine überschrittene Endzeit gibt. Das ist die Gegenprobe zu Punkt 7.

**`until` überlebt das Ausschalten** (`08:30:00` UTC = `10:30` CEST), `since`
nicht. Das ist so gebaut und kein Rest: Die Ablage nimmt, was das Formular
schickt, und dort stand das Datum noch. Die Erwartung in `docs/912 §10` hat das
Gegenteil verlangt und ist berichtigt.

**Block B, der Zustand, für den es die Prüfung gibt:**

```
touch /var/spool/srvpanel/wartung
curl https://cloudlab24.ipv64.de/            → 503
srvpanel diagnose                            → Auffällig: 2   Kaputt: 1
  orphan.row / certificate — tls.cloudlab24.de
  tls.file / expiring — p6-b.invalid
  maintenance.flag / unexpected — /var/spool/srvpanel/wartung
rm /var/spool/srvpanel/wartung
curl https://cloudlab24.ipv64.de/            → 200
```

**Die Kundenwebsite war 503, und das Panel hat nichts gesagt.** Die Ablage stand
die ganze Zeit auf `enabled: false`, also stand kein Band da — die Anzeige
*kann* diesen Zustand nicht zeigen, weil sie eine Ablage liest. Gefunden hat ihn
die Prüfung, und genau dafür gibt es sie.

**Die Schwere steht in der Zusammenfassung und nicht nur im Text.** `Auffällig: 2`
und **`Kaputt: 1`** — `unexpected` ist ein **Fehler**, `missing` war eine
Warnung. Die Richtung, die `docs/911` beim Entwurf entschieden hat („nur die
zweite schaltet jede Kundenwebsite ab"), ist damit auf der Kommandozeile
ablesbar.

**Und der Befund verschwindet mit seinem Grund:** nach dem `rm` ein weiterer
Lauf um `11:10:21`, `Auffällig: 2`, keine `Kaputt`-Zeile.

**Was gefolgert und nicht abgelesen ist:** Dass während des `touch`-Fensters kein
Band dastand, folgt aus der Ablage (`enabled: false` in Block A gemessen und
zwischen den beiden Blöcken nicht angefasst) und nicht aus einem Blick auf die
Seite in genau dieser Sekunde. Die Seite danach zeigt den ausgeschalteten
Zustand ohne Band.

---

## §10 Punkt 10 — der Endzustand *(erfüllt)*

```
ls -l /var/spool/srvpanel/wartung   → No such file or directory
curl https://cloudlab24.ipv64.de/   → 200
["enabled"]=> bool(false)
["until"]=>   string(19) "2026-09-13 08:30:00"
["since"]=>   NULL
Abzeichen = 2
  orphan.row / certificate — tls.cloudlab24.de
  tls.file / expiring — p6-b.invalid
```

Im Browser auf `/maintenance`: `{prop: null, band: null, hoehe: null,
abzeichen: 2}`, und die Seite sagt „Der Wartungsmodus ist ausgeschaltet. Alle
Websites werden normal ausgeliefert."

**Keine `maintenance.*`-Zeile mehr**, und das ist das Kriterium — nicht die
Summe. Sie steht bei **2** gegen **3** im Ausgangszustand, weil
`unit.schedule / no_next` sich zwischendurch von selbst erledigt hat
(Beobachtung 1).

`until` steht weiter auf `08:30:00` UTC. Das ist gebaut so und kein Rest: Die
Endzeit überlebt das Ausschalten, sie wirkt nur nicht mehr.

---

## §11 Bilanz

**Alle zehn Punkte aus `docs/912` sind gefahren und erfüllt**, beide
Ausschlusskriterien (4 und 9) darunter, keiner als „nicht herstellbar"
ausgefallen.

| Punkt | Gegenstand | |
|---|---|---|
| 1 | Ausgangszustand, kein Band | erfüllt |
| 2 | eingeschaltet, Kundenwebsite 503 | erfüllt |
| 3 | Endzeit dazu, Dauer springt nicht | erfüllt |
| **4** | **überschrittene Endzeit steht vorn** | **erfüllt** |
| 5 | Bilderrunde, zwei Bänder stapeln | erfüllt |
| 6 | Kundensicht ohne Band | erfüllt |
| 7 | Datei fort → `missing` | erfüllt |
| 8 | Abzeichen trägt den Befund | erfüllt |
| **9** | **Datei da, Panel schweigt → `unexpected`** | **erfüllt** |
| 10 | Endzustand | erfüllt |

**Vier Befunde und drei Beobachtungen — und keiner der Befunde steckt im
Prüfling:**

| | wo | |
|---|---|---|
| Befund 1 | Prüfmittel | Die Vorbedingung konnte nur rot sein |
| Befund 2 | — | Zwei fehlgeschlagene Bestellungen, geklärt: `.invalid`-Namen |
| Befund 3 | Vorschrift | `docs/912 §10` verlangte `until: null` und sagte zwei Zeilen weiter das Gegenteil |
| Befund 4 | Anweisung | Der Schritt vor dem Block wurde gelesen und nicht gefahren |
| Beobachtung 1 | Server | `srvpanel-diagnose.timer` ohne nächsten Termin — und von selbst wieder da |
| Beobachtung 2 | Entwurf | `/maintenance` nennt den Zustand dreimal, das Band verweist auf sich selbst |
| Beobachtung 3 | Bestätigung | Die ACME-Ausnahme trägt während der Wartung |

**Null Funde am Prüfling ist kein Freispruch.** Dieselbe Lage wie in `docs/78`,
`docs/906` und `docs/909`, und derselbe Grund: Der Plan entstand nach einer
Messrunde, der Lauf war vor dem Fahren ausgeschrieben, und das Messmittel lag
als geprüftes Werkzeug im Repo.

> **Ein Abnahmelauf ohne Fund am Prüfling sagt nicht, dass keiner da war — er
> sagt, wo sie gefunden wurden.**

Gefunden wurden sie beim **Bauen**: `docs/911 §6` führt vier Stellen auf, an
denen es anders lief als im Plan, und **drei davon hat ein bestehender Wächter
angehalten**, bevor sie einen Server gesehen haben — `SharedPropTest`,
`TimeDisplayTest`, `DiagnoseSeamTest`. Der vierte kam aus der Bilderrunde.

**Was dieser Lauf gekonnt hat und der Container nicht:**

1. Dass die Behauptung des Bandes stimmt — 503 an einer echten Domain über die
   echte Leitung, und 200, sobald die Datei fort ist.
2. Dass die Zone die des Servers ist — `CEST (UTC+02:00)` statt `UTC`, an beiden
   Uhren und über zwei Stunden Versatz im Rundlauf durch das Formular.
3. Dass der Abgleich einen Befund erzeugt, den das Abzeichen trägt — in beide
   Richtungen, mit `warn` und `fail` getrennt.

Genau die drei hat `docs/912` in seiner Einleitung als unmessbar im Container
benannt.

---

## §12 Was benannt offen bleibt

- **Der Rest aus P7:** `orphan.row / certificate — tls.cloudlab24.de` steht seit
  `docs/113 §13` da und ist von diesem Lauf unberührt.
- **`tls.file / expiring — p6-b.invalid`** — das Wegwerfzertifikat aus
  `docs/100 §6`, das an diesem Tag ausläuft. Erneuern lässt es sich nicht:
  `.invalid` ist für Let's Encrypt kein prüfbarer Name (Befund 2).
- **`unit.schedule / no_next` ist geklärt und war ein Befund im Prüfling**
  (§13 und §14): Der Nachtlauf hat seinen eigenen Timer gemeldet, weil er
  dessen ausgelöste Unit ist. Behoben in `Units::hasNext()`; **auf einem Server
  gesehen hat die Behebung nichts** — sie zeigt sich erst daran, dass im
  nächsten Nachtlauf die Zeile `Kaputt: 1` ausbleibt. Die Sorge, der Abgleich
  liefe nicht von selbst, ist erledigt: Die Nachtläufe vom 9. bis 13. September
  sind im Journal, keiner ist ausgefallen.
- **Der Administrator ist nicht geprüft.** Punkt 6 misst die Tür an der
  Kundensicht; dass es die richtige Tür ist, hält `MaintenanceBandTest`
  (`docs/912 §0`).
- **Ob eine Ankündigung und das Wartungsband zusammen mit dem
  Impersonationsband stapeln**, ist nicht herstellbar — die beiden schliessen
  einander aus (`docs/912 §0`).
- **Der Zustand „eingeschaltet, aber `since` fehlt"** ist nicht vorgekommen:
  `cloudsrv24` stand beim Einspielen nicht in Wartung.
- **Beobachtung 2** ist eine Entwurfsfrage und keine Aufgabe: Ob `/maintenance`
  den Zustand dreimal nennen soll, entscheidet der Betreiber.

---

## §13 Nachmessung zu Beobachtung 1 — was `no_next` überhaupt bedeutet

Gemessen am **13. September 2026 im Entwicklungscontainer**, gegen systemd 255
als PID 1 in einer eigenen PID- und Mount-Namespace (`docs/89 §1`). Der
Prüfkörper ist ein Wegwerf-Timer, dessen `[Timer]`-Block **wortgleich** der von
`srvpanel-diagnose.timer` ist — `OnCalendar=daily`, `Persistent=true`,
`RandomizedDelaySec=1h`, dazu `PartOf=` auf ein Wegwerf-Ziel.

Gefragt wird dieselbe Frage wie der Prüfling sie stellt: `systemctl show` nach
`NextElapseUSecRealtime` und `NextElapseUSecMonotonic`, geurteilt nach
`SrvPanel\Agent\Units::hasNext()`.

| Lage | Active | Sub | Realtime | Monoton | `has_next` |
|---|---|---|---|---|---|
| installiert, nie gestartet | `inactive` | `dead` | leer | `infinity` | **false** |
| Timer läuft, Dienst ruht *(Gegenprobe)* | `active` | `waiting` | Zeitstempel | `0` | true |
| Timer läuft, ausgelöster Dienst läuft | `active` | `waiting` | Zeitstempel | `0` | true |
| `daemon-reload` bei laufendem Dienst | `active` | `waiting` | Zeitstempel | `0` | true |
| `daemon-reload` bei ruhendem Dienst | `active` | `waiting` | Zeitstempel | `0` | true |
| Timer gestoppt | `inactive` | `dead` | leer | `infinity` | **false** |
| `stop` des Ziels *(über `PartOf=`)* | `inactive` | `dead` | leer | `infinity` | **false** |
| `restart` des Ziels *(über `PartOf=`)* | `active` | `waiting` | Zeitstempel | `0` | true |
| `enable`, aber nicht gestartet | `inactive` | `dead` | leer | `infinity` | **false** |
| `enable --now` | `active` | `waiting` | Zeitstempel | `0` | true |
| Start mit altem Stempel (Nachholung) | `active` | `waiting` | Zeitstempel | `0` | true |

**Damit hat `no_next` an diesem Timer genau eine Bedeutung: Er lief nicht.**
`ActiveState=inactive`, `SubState=dead`. Weder ein laufender ausgelöster Dienst
noch ein `daemon-reload` noch eine Nachholung durch `Persistent=true` erzeugt
ihn.

> **Ein Grund, der in jeder gemessenen Lage auf denselben Zustand zurückgeht,
> ist keine Familie von Erklärungen — es ist eine.**

**Die Vermutung aus Beobachtung 1 ist damit widerlegt.** Dort stand, ein Timer
habe „unmittelbar nach einer Installation seinen nächsten Termin noch nicht".
`packaging/scripts/postinstall.sh` fährt `systemctl enable --now
srvpanel-diagnose.timer`, und das ist die vorletzte Zeile der Tabelle: Der
Termin steht in derselben Sekunde da.

> **Eine Vermutung, die plausibel ist und die niemand gemessen hat, wird beim
> Nachmessen nicht ungenauer — sie wird falsch oder richtig.**

**Was das Paket beim Update wirklich tut**, ausgezählt an den Skripten:
`packaging/scripts/preremove.sh` hält alle sechs Timer an und schaltet sie ab —
**auch beim Update**, denn dpkg ruft `prerm` dort ebenfalls, und nur das
`rm -rf` des Rückwegs darunter ist auf `remove`/`purge` beschränkt.
`postinstall.sh` wirft sie über `restart_services()` wieder an, und zwar auf
**jedem** Weg, den ein eingerichtetes System nimmt — auch aus `roll_back()`
heraus. Zwischen den beiden liegt ein Fenster, in dem alle sechs `no_next`
ergäben; danach keiner.

**Was daraus für den Server folgt und hier nicht zu messen ist:** Am 13.
September stand der Befund um 10:16 da und um 10:57 nicht mehr. Nach dieser
Tabelle heisst das, der Timer war um 10:16 angehalten und lief um 10:57. Wer
ihn angehalten und wer ihn gestartet hat, weiss das Journal des Servers und
nicht dieser Container.

**Und die tragende Frage ist eine andere als die nach dem Befund.** Ob der
Nachtlauf je von selbst gefahren ist, sagt `stamp-srvpanel-diagnose.timer` und
das Journal von `srvpanel-diagnose.service` — nicht der Zustand des Timers von
heute.

> **Ein Timer, der jetzt einen Termin hat, belegt nicht, dass er je gefeuert
> hat.**

**Zwei Fallen dieser Messrunde**, beide bezahlt. Die erste hat die Gegenprobe
gefangen und nicht das Nachdenken: Der erste Wurf las `systemctl show` über
`eval "$(… | sed 's/^/V_/')"`, und ein `NextElapseUSecRealtime=Mon 2026-09-14
00:03:38 UTC` trägt Leerzeichen — die Zuweisung scheitert, die Variable bleibt
leer, und **jede** Lage meldete `no_next`, die gesunde eingeschlossen.

> **Eine Gegenprobe ist die einzige Stelle, an der ein Messmittel merkt, dass es
> jede Lage gleich beantwortet.**

Die zweite ist eine Spur ausserhalb der Namespace: `systemctl enable` legt
seinen Symlink unter `/etc/systemd/system/timers.target.wants/` an, und `/etc`
ist **nicht** namespace-privat — nur `/run` ist es. `Persistent=true` legt
ausserdem `/var/lib/systemd/timers/stamp-…` an. Beides ist weggeräumt und
nachgesehen.

> **Eine Namespace, die das Netz und die Einhängepunkte trennt, trennt die
> Dateien nicht — und `enable` schreibt in eine Datei.**

---

## §14 Beobachtung 1 ist geklärt — und war ein Befund im Prüfling

Gemessen am **13. September 2026 auf `cloudsrv24`** (Journal und Zeitstrahl) und
**im Container** (systemd 255 als PID 1). Der Befund war nicht vergänglich; er
stand jede Nacht da, und nur nachts.

### Was der Server gesagt hat

| | gemessen |
|---|---|
| Timer jetzt | `active` · `waiting` · `NextElapse=Mon 2026-09-14 00:23:04 CEST` · `enabled` |
| Stempel | `2026-09-13 00:47:04.891451000 +0200` |
| Nachtläufe | 9., 10., 11., 12., **13. September** — je einer, keiner ausgefallen |
| jeder Nachtlauf | `Auffällig: 2`, **`Kaputt: 1`** |
| jeder Lauf von Hand (10:57, 13:40) | `Auffällig: 2`, **keine `Kaputt`-Zeile** |
| Stop/Start des Timers | acht Paare, je **4 bis 5 Sekunden** — die Fenster der Paketupdates |
| am 13. September | `09:36:47` gestoppt, Neustart des Servers, `09:37:01` gestartet |

**Damit fällt die erste Erklärung.** Um 10:16 lief der Timer seit 39 Minuten;
angehalten war er nicht. Und der Nachtlauf ist nie ausgefallen — die Sorge aus
§12, der Abgleich liefe nicht von selbst, war unbegründet.

**Die Zahl, die es entscheidet, ist die Zeile `Kaputt: 1`.** Sie steht in
**jedem** Nachtlauf und in **keinem** Lauf von Hand. `no_next` ist der einzige
`Fail` unter den drei Befunden (`FindingCheck::UnitSchedule`), also ist der
Befund nicht verschwunden — er ist **nie in einem Lauf von Hand entstanden**.

> **Ein Befund, der nur in dem Lauf entsteht, den niemand sieht, sieht aus, als
> verschwände er von selbst.**

### Was der Container gesagt hat

Ein voller Zyklus, zweimal, jede Sekunde gemessen:

| `SubState` | `NextElapseUSecRealtime` | `NextElapseUSecMonotonic` | `has_next` |
|---|---|---|---|
| `waiting` | `Sun 2026-09-13 11:45:30 UTC` | `0` | true |
| **`running`** (feuert) | **leer** | **`infinity`** | **false → `no_next`** |
| `waiting` (danach) | `Sun 2026-09-13 11:46:00 UTC` | `0` | true |

**Die beiden Zeitfelder schreiben im feuernden und im kaputten Zustand
dasselbe.** Getrennt werden sie allein durch `SubState`.

> **Zwei Zustände, die in denselben Feldern dasselbe schreiben, trennt nur ein
> drittes Feld — und wer es nicht liest, hält den gesunden für den kaputten.**

### Der Befund

`srvpanel-diagnose.service` ist die **ausgelöste Unit ihres eigenen Timers**.
Solange sie läuft, steht `srvpanel-diagnose.timer` auf `running` und hat keinen
nächsten Termin. Die Prüfung läuft damit *innerhalb* des einen Fensters, in dem
ihre Antwort falsch ist — und meldet sich selbst als kaputt.

> **Eine Prüfung, die sich selbst mitprüft, misst ihren eigenen Ausnahmezustand
> als Normalfall.**

Von Hand gefahren war der Zustand nie herstellbar: Dort ist der Timer `waiting`.
Genau deshalb war der Befund über Wochen unsichtbar und sah, als er einmal
auffiel, nach einem Zufall aus.

### Und die eigene Messung war beim ersten Mal unvollständig

§13 hat elf Lagen gemessen und den entscheidenden nicht getroffen: Der Dienst
wurde dort **von Hand** gestartet, und der Timer bleibt dabei auf `waiting`.
Gemessen war „Dienst läuft" — gebraucht war „Timer hat gefeuert".

> **Ein Prüfkörper, der den Zustand auf einem anderen Weg herstellt als der
> Prüfling, stellt einen anderen Zustand her.**

Aufgefallen ist es nicht am Nachdenken, sondern daran, dass der Zeitstrahl des
Servers der Schlussfolgerung widersprach — der Timer lief um 10:16.

> **Eine Schlussfolgerung, die einer gemessenen Zeile widerspricht, ist nicht
> ungenau, sondern falsch.**

### Behoben

In **`SrvPanel\Agent\Units::hasNext()`** und dort allein. Vier Stellen lesen
`has_next === false` — die Diagnose, die Farbe der Zeile, die Datumsspalte und
der Zähler der kaputten Timer; jede davon hätte die Ausnahme sonst selbst
tragen müssen.

> **Wo vier Verbraucher denselben Wert deuten, gehört die Behebung an den
> Erzeuger — sonst sind es vier Fassungen derselben Regel.**

Gehalten von `UnitStateTest` (der Prüfkörper ist die gemessene Ausgabe, und ein
eigener Fall sichert zu, dass er in beiden Zeitfeldern dem gestoppten gleicht —
sonst misst er nicht mehr, was er messen soll) und von `UnitVerdictTest`, der
die **Naht** misst: durch `Units::read()` in `Units::judge()`, also den Weg des
Nachtlaufs, mit der Gegenprobe bei `SubState=dead`.

Gebrochen in beide Richtungen: Ohne die Kenntnis des feuernden Zustands fallen
drei Fälle, und der dritte druckt die Zeile wörtlich, die der Server jede Nacht
erzeugt hat — `ActiveState=active SubState=running`. Beide Eingriffe stehen in
`tests/waechter-brechen.sh`.

**Auf einem Server gesehen hat die Behebung nichts.** Sie zeigt sich erst im
nächsten Nachtlauf: Bleibt `Kaputt: 1` aus und meldet der Lauf `Auffällig: 2`,
ist sie belegt. Vorher ist sie gebaut und nicht gemessen.

> **Was nur nachts entsteht, lässt sich nur nachts widerlegen.**
