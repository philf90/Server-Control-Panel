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
