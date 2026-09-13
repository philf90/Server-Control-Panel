# Protokoll zum Abnahmelauf des Wartungsbands

Gefahren am **12. September 2026 auf `cloudsrv24`** gegen **`0.7.4-rc.6`**. Der
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
