# 64 — Das Protokoll der Bilderrunde (P6, Schritt 12)

Die Vorschrift steht in `docs/63`. Dieses Protokoll entsteht **während** des
Laufs; es ist am 19. August 2026 angelegt worden, als die ersten beiden
Ansichten gemessen waren.

**Es ist unvollständig, und das steht in §3.** Von den neun Ansichten sind
**alle neun** aufgenommen und in vier Lagen gemessen, von den zwanzig Zuständen ist
einer aufgenommen und keiner gemessen. Zwei Befunde am Panel stehen offen. Ein Protokoll, das seine Lücken nicht
nennt, liest sich wie eine Abnahme.

| | |
|---|---|
| Maschine | `cloudsrv24` |
| Panel | `v0.6.0-rc.18` |
| Abonnement | 140 (`p6-abnahme.invalid`, Systembenutzer `p1139`) |
| Messmittel | `tests/bilder-messen.js` |
| Breiten | 390 × 844 und 1440 × 900, Geräteansicht |
| Dichte | **`customer`** in jeder Aufnahme — siehe unten |

**Jede Aufnahme dieses Laufs entsteht in der Kundensicht, und damit in der
Dichtestufe `customer`.** `app.blade.php` setzt sie an einer einzigen Stelle:
`auth()->user()?->isAdmin() === false ? 'customer' : config(…)`. Beim Wechsel in
die Sicht eines Kunden ist der angemeldete Benutzer der Kunde — auch wenn ein
Administrator ihn ausgelöst hat —, also gilt `customer`. Auch die drei
Auswahlseiten, die es nur mit zwei Abonnements gibt, sind so aufgenommen.

Das steht hier, weil Befund 6 genau diese Stufe ändert.

---

## 1. Die Ansichten

### Ansicht 1 — Dateien, Auswahl (`/files`)

**Erfüllt** in allen vier Lagen. Die beiden 1440er Lagen sind am 19. August
nachgemessen worden; ihre Gegenprobe fehlte.

| Lage | `dokument` | Gegenprobe | `schiebt` |
|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` 119 · `tr` 91 · *(LastPass)* |
| 390 dunkel | 0 | **200/200** | `thead` 119 · `tr` 91 · *(LastPass)* |
| 1440 hell | 0 | **200/200** | *(LastPass)* |
| 1440 dunkel | 0 | **200/200** | *(LastPass)* |

**Die vier Zeilen des Nachlaufs sind ohne Neuladen zwischen den Breiten
entstanden**, also gegen `docs/63 §4`. Für das Ergebnis trägt das hier nicht:
`dokument` ist in allen vier Lagen 0, die Gegenprobe schlägt in allen vier mit
200/200 aus, und die einzigen Einträge in `schiebt` sind der Mechanismus
`.stacks thead` und die Meldezeile von LastPass (§2). Der erste Wert bei 1440
stammt aus der frisch geladenen Seite.

`thead` und `tr` sind `.stacks thead`, der Mechanismus aus `app.css` — hier mit
119 px, weil diese Tabelle nur eine Spaltenüberschrift trägt. Beide Themes
liefern **dieselben** Zahlen; die Geometrie hängt nicht am Thema.

**Hier stand zuerst viermal `200/200`, und niemand hatte es gesehen.** Die
Konsole klappt ein Objekt ein; neben `dokument: 0` stand `gegenprobe: {…}`, und
ich habe den erwarteten Wert eingetragen statt den abgelesenen.

> **Eine Zahl, die man aus einem eingeklappten Objekt abschreibt, hat man nicht
> gemessen.**

Ohne die Gegenprobe ist `dokument: 0` keine Aussage, sondern zwei mögliche —
genau das, wogegen sie gebaut ist. Die Ansicht wird nachgemessen; `docs/63 §4`
verlangt seitdem `JSON.stringify(bilderMessen())`.

**Die Seite ist in der Kundensicht nicht zu erreichen, und das ist kein
Fehler.** `FileController::pick()` leitet durch, wenn genau **ein** Abonnement
erreichbar ist; der Kunde von 140 hat nur dieses eine. Gemessen wurde deshalb
als Administrator, der beide sieht — die Seite listet `p6-abnahme.invalid` und
`p6-nochmaltest`.

> **Eine Seite, die es nur bei mehr als einem Gegenstand gibt, misst man nicht
> dort, wo es einen gibt.**

Dasselbe gilt für Ansicht 5 (`/sftp`) und 7 (`/cron`).

### Ansicht 2 — Dateimanager (`/subscriptions/140/files`)

**Erfüllt** in allen vier Lagen.

**Nachgemessen mit Neuladen je Breite.** Die erste Runde entstand ohne, und
ihre Zahlen stehen unten in §2 als das, was sie waren.

| Lage | `dokument` | Gegenprobe | `schiebt` |
|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` 350 · `tr` 322 |
| 390 dunkel | 0 | **200/200** | `thead` 350 · `tr` 322 · *(LastPass)* |
| 1440 hell | 0 | **200/200** | **leer** |
| 1440 dunkel | 0 | **200/200** | *(LastPass)* |

**Die beiden dunklen Lagen sind am 19. August nachgemessen worden**, mit der
Messung, die `pfad` und `anfang` führt. `thead` und `tr` stehen dort als
`div:1 > div.frame > main.content > div.split:2 > div:2 > div.scrolls >
table.stacks > thead` — der Mechanismus `.stacks thead` mit seinen
Spaltenüberschriften, benannt statt vermutet.

**Und sie belegen nebenbei, dass der Breitenwechsel nichts hinterlässt.** Sie
sind ohne Neuladen zwischen 1440 und 390 entstanden und liefern bei 390 exakt
die Zahlen der frisch geladenen hellen Lage — 350 und 322, `dokument` 0. Der
einzige zusätzliche Eintrag ist die Fremdzeile aus §2.

**`thead` und `tr` sind der Mechanismus und kein Fehler.** `.stacks thead` steht
in `app.css` auf `position: absolute; width: 1px; height: 1px; overflow: hidden;
clip-path: inset(50%)` — absichtlich aus dem Bild genommen, damit der
Screenreader die Spaltenüberschriften in der Vorlesereihenfolge behält. Die
gemessenen 350 px sind die Breite dieser Überschriften.

**Der `div` mit 468 px ist die Meldezeile von LastPass** — nicht `.split`, wie
hier zuerst stand, und überhaupt nichts aus diesem Bau. §2 hat die Zeile.

### Ansicht 3 — Editor (`/subscriptions/140/files/edit?path=/httpdocs/klein.txt`)

**Erfüllt — und die erste Ansicht, die in allen vier Lagen vollständig gemessen
ist.**

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 1440 hell | 0 | **200/200** | leer | leer |
| 1440 dunkel | 0 | **200/200** | leer | leer |
| 390 hell | 0 | **200/200** | leer | leer |
| 390 dunkel | 0 | **200/200** | leer | leer |

**Vier Lagen, vier gültige Gegenproben, kein einziger Eintrag.** Je Breite wurde
neu geladen; die Themawechsel ändern hier keine einzige Zahl.

**`schiebt` ist in allen vier Lagen leer** — kein `.stacks thead`, weil diese
Ansicht keine Tabelle trägt, und kein Rest, weil je Breite neu geladen wurde.
Der Rollbehälter des Editors (`.cm-scroller`) taucht nicht unter `rollt` auf:
Die Datei ist vier Zeilen lang und passt.

---

### Ansicht 4 — Suche (`/subscriptions/140/files/search?query=eins`)

**Gemessen in allen vier Lagen, und die Seite schiebt nicht — aber sie hat zwei
Fehler, die keine Zahl erzeugen.**

| Lage | `dokument` | Gegenprobe | `schiebt` |
|---|---|---|---|
| 1440 dunkel | 0 | 200/200 | `div` 468 |
| 1440 hell | 0 | 200/200 | `div` 468 |
| 390 hell | 0 | 200/200 | `thead` 157 · `tr` 129 · `div` 468 |
| 390 dunkel | 0 | 200/200 | `thead` 157 · `tr` 129 · `div` 468 |

**Der `div` mit 468 px steht hier auch frisch geladen** — die beiden 1440er
Lagen sind die ersten Messungen nach dem Laden. Bei Ansicht 2 war derselbe Wert
ein Rest; hier ist er keiner. Dieselbe Zahl auf zwei Seiten, einmal als Rest und
einmal nicht — das ist noch nicht erklärt und steht in §3.

**Und die Erklärung von Ansicht 2 trug hier nicht.** Dort hiess es, es sei die
rechte Hälfte von `.split`; `Files/Search.vue` hat aber gar kein `.split`. Die
Zahl war dieselbe, der Kasten konnte es nicht sein — und die Messung konnte das
nicht sagen, weil ein Element ohne Klasse bei ihr nur `div` heisst.

**Aufgelöst am 19. August, und es ist kein Fund am Panel** — es ist die
Meldezeile von LastPass. §2 hat die Zeile und die Lehre daraus.

#### Befund 1 — die Checkbox trägt die Regel eines Textfeldes

**Gemeldet vom Betreiber beim Ansehen des Bildes**, nicht von einer Zahl: Bei
390 px steht zwischen „auch im Inhalt" und dem Knopf ein grosser leerer Kasten
in der Mitte.

`Files/Search.vue` gibt der Checkbox `class="field inline"`. Damit greift

```css
.field input, .field select, .field textarea, .with-unit input {
  width: 100%;
  min-height: var(--tap);
  padding: 9px 12px;
}
```

— eine Regel, die für Textfelder gedacht ist. Der Baustein für ein Kästchen ist
`.toggle`, und er setzt ausdrücklich `width: 17px; min-width: 0; height: 17px;
min-height: 0`, um genau das zurückzunehmen. Fünf andere Seiten benutzen ihn.

> **Ein Baustein, der die Regel eines anderen erbt, sieht aus wie der andere.**

#### Befund 2 — der Abstand zum Satz unter dem Formular

Zwischen der Knopfreihe und „Gesucht unter …" steht nichts. Die Abstände sind
als Paare gesetzt — `.button-row + .notice`, `+ .sections`, `+ .split` —, und
`p.quiet` ist keines davon.

Der Kommentar in `app.css` sagt neben dieser Regel selbst, was daran nicht
trägt:

> **Eine Liste von Nachbarn, die wächst, ist keine Regel — sie ist eine
> Aufzählung der Fälle, die schon jemand gesehen hat.**

**Beide Befunde sind nicht behoben.** Sie werden gesammelt und gegen die nächste
Fassung im Browser nachgeprüft — so wie `docs/48 §4` es für P5c gehalten hat.
Eine Fassung je Befund kostet mehr Runden, als der Lauf wert ist.

### Ansicht 5 — SFTP, Auswahl (`/sftp`)

**Erfüllt** in allen vier Lagen — und die erste Ansicht ohne einen einzigen
fremden Eintrag.

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` 119 · `tr` 91 | leer |
| 390 dunkel | 0 | **200/200** | `thead` 119 · `tr` 91 | leer |
| 1440 hell | 0 | **200/200** | **leer** | leer |
| 1440 dunkel | 0 | **200/200** | **leer** | leer |

`thead` steht als `div.frame > main.content > div.scrolls:2 > table.stacks >
thead` da — dieselbe Tabelle mit einer Spaltenüberschrift wie bei Ansicht 1,
dieselben 119 px, und ohne das `.split` des Dateimanagers, das es hier nicht
gibt. Die beiden 1440er Lagen sind frisch geladen gemessen.

**Und die Meldezeile der Erweiterung fehlt** — in keiner der vier Lagen steht
sie. Damit ist §2 nicht nur erklärt, sondern gegengeprobt: Dieselbe Bauart von
Seite, dasselbe Messmittel, ein anderes Fenster, und der Eintrag ist weg.

> **Ein Fund, der mit dem Fenster verschwindet, gehörte dem Fenster.**

**Wie Ansicht 1 ist die Seite in der Kundensicht nicht zu erreichen**, aus
demselben Grund: `SftpController::pick()` leitet bei genau einem erreichbaren
Abonnement durch.

### Ansicht 6 — SFTP-Zugang (`/subscriptions/140/sftp`)

**Die Seite schiebt in keiner Lage — und sie hat einen Fehler, den keine Zahl
erzeugt.**

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` 342 · `tr` 314 | leer |
| 390 dunkel | 0 | **200/200** | `thead` 342 · `tr` 314 | leer |
| 1440 hell | 0 | **200/200** | **leer** | `.scrolls` 217 |
| 1440 dunkel | 0 | **200/200** | **leer** | `.scrolls` 217 |

**Der Eintrag unter `rollt` ist der gewollte.** Die Schlüsseltabelle steht bei
1440 px in der linken Hälfte von `.sections`, und ein `SHA256:`-Fingerabdruck
passt dort nicht neben Bezeichnung, Art und Aktion. `.scrolls` ist genau dafür
da, `darf` steht auf `true`, und `dokument` bleibt 0 — die Zelle rollt, die
Seite nicht.

`thead` steht als `div.frame > main.content > div.sections:2 > section.section:3
> div.scrolls:2 > table.stacks > thead` da.

#### Befund 3 — der Knopf „Eintragen" klebt am Satz darüber

**Gemeldet vom Betreiber beim Ansehen des Bildes.** Zwischen „…ed25519, ECDSA
und RSA ab 2048 Bit." und dem Knopf steht nichts.

`Subscriptions/Sftp.vue` setzt den Erklärsatz als `.section-note`, und der hat
`margin: 10px 0 0` — oben Luft, unten keine. Der Abstand müsste also von der
Knopfreihe kommen, und die holt ihn aus einer Aufzählung:

```css
:is(.field, .hint, .error, .scrolls, .pager, .cell-value) + .button-row {
  margin-top: 16px;
}
```

`.section-note` steht nicht darin. **Das ist derselbe Fehler wie Befund 2 an
Ansicht 4**, nur in die andere Richtung: Dort fehlte der Nachbar *hinter* der
Knopfreihe, hier der *davor*. Zwei Funde derselben Bauart in einem Lauf, und
der Kommentar neben der Regel nennt den Grund seit P5c selbst:

> **Eine Regel, die eine Liste von Nachbarn führt, ist eine Liste, die wächst —
> der Grund steht nicht in ihr, sondern daneben.**

Die Liste ist seit P4 dreimal verlängert worden (`.hint`, dann `.scrolls`,
`.pager`, `.cell-value`, dann die Gegenrichtung mit `.sections` und `.split`).
Jedes Mal hat ein Bild sie verlängert, und jedes Mal blieb der nächste Fall
offen. **Die Behebung sollte deshalb nicht der sechste Eintrag sein**, sondern
die Frage umdrehen: Ein Baustein, der bündig endet, bringt seinen Abstand nach
unten selbst mit — dann braucht die Knopfreihe keine Liste ihrer Vorgänger.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.**

Derselbe Satz steht seit `docs/59` Befund 19 im Projekt, dort über einen
Menüpunkt. Hier trifft er einen Abstand.

**Nicht behoben.** Er kommt zu den beiden Befunden an Ansicht 4 und wird mit
ihnen gegen die nächste Fassung nachgeprüft.

### Ansicht 7 — Cron, Auswahl (`/cron`)

**Erfüllt** in allen vier Lagen, ohne einen einzigen fremden Eintrag.

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` 119 · `tr` 91 | leer |
| 390 dunkel | 0 | **200/200** | `thead` 119 · `tr` 91 | leer |
| 1440 hell | 0 | **200/200** | **leer** | leer |
| 1440 dunkel | 0 | **200/200** | **leer** | leer |

**Zeile für Zeile dieselben Zahlen wie Ansicht 1 und 5** — auch derselbe Weg:
`div.frame > main.content > div.scrolls:2 > table.stacks > thead`. Das ist kein
Zufall und auch keine überflüssige Messung: Die drei Auswahlseiten sind
derselbe Baustein mit einer anderen Überschrift, und **dass sie sich gleich
messen, ist der Beleg dafür.** Wären es drei Zahlen, stünde dort eine
Sonderbehandlung, von der niemand weiss.

Die beiden 1440er Lagen sind frisch geladen gemessen. Auch hier gilt der Grund
aus Ansicht 1: `CronController::pick()` leitet bei genau einem erreichbaren
Abonnement durch, gemessen wird deshalb als Administrator.

### Ansicht 8 — Cronjobs (`/subscriptions/140/cron`)

**Die Seite schiebt in keiner Lage — und der Betreiber hat vier Dinge am Bild
gesehen, von denen kein einziges eine Zahl erzeugt.**

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` 484 · `tr` 456 | leer |
| 390 dunkel | 0 | **200/200** | `thead` 484 · `tr` 456 | leer |
| 1440 hell | 0 | **200/200** | **leer** | `.scrolls` 191 |
| 1440 dunkel | 0 | **200/200** | **leer** | `.scrolls` 191 |

Der Roller bei 1440 ist die Jobliste mit ihren fünf Spalten in der rechten
Hälfte von `.sections` — gewollt, `darf: true`. Die 484 px bei 390 sind
`.stacks thead` mit fünf Überschriften, also der Mechanismus.

**Aufgenommen in der Kundensicht**, und das ist für die Befunde 5 und 6 wichtig:
Dort gilt `:root[data-density='customer']` mit `--block-gap: 34px` und
`--bereich-gap: 38px 52px`, in der Verwaltungssicht 26px und 30px/44px.

#### Befund 4 — die Meldung klebt am Satz darüber (390 px)

Im Bereich „Zeitplan und Zeitzone" steht `<p>Cronjobs laufen als p1139 …</p>`
und direkt darunter `<p class="notice neutral">`. `.notice` trägt
`margin-bottom: var(--block-gap)` und **keinen** Rand nach oben; ein `<p>` hat
durch Tailwinds Reset gar keinen. Zwischen beiden steht also nichts ausser der
Polsterung der Meldung.

Die Paarliste kennt den Fall nicht:

```css
:is(.field, .hint, .error, .scrolls, .pager, .cell-value, .button-row) + .notice
```

**Das ist der dritte Fund derselben Bauart in diesem Lauf** — Befund 2 (nach der
Knopfreihe), Befund 3 (vor der Knopfreihe), jetzt vor der Meldung. Drei Stellen,
drei Listen, dreimal derselbe Grund.

> **Ein Fehler, der an drei Stellen unabhängig gemacht wurde, ist keine
> Unachtsamkeit, sondern eine fehlende Stelle.**

Der Satz steht seit `docs/48` im Projekt, dort über „geschätzt 1 Zeilen". Er gilt
hier wörtlich: **Die Behebung ist nicht der nächste Listeneintrag, sondern die
Umkehrung der Frage.** Ein Baustein, der bündig endet, bringt seinen Abstand nach
unten selbst mit; dann braucht weder `.button-row` noch `.notice` eine Liste
ihrer Vorgänger. Was das an Wächtern kostet, ist beim Beheben zu klären —
`ClassReachTest` und `DesignTokensTest` hängen an diesen Regeln.

#### Befund 5 — die Cron-Schreibweise bricht mitten im Ausdruck (390 px)

Unter den Zeitplanfeldern steht der Hinweis „Erlaubt sind `*`, Zahlen, Spannen
(`9-17`), Listen (`1,4`) und Schritte (`*/15`) … Ergibt: `* * * * *`". Jedes
dieser Stücke ist ein `.ident`, und `.ident` trägt seit `docs/46 §20.11`
`overflow-wrap: anywhere` — **absichtlich**, weil eine Kennung sonst die Seite
schiebt. Bei 390 px bricht damit auch ein vierstelliges `*/15`.

**Das ist keine Nachlässigkeit, sondern eine Regel an der falschen Stelle**, und
zwar dieselbe Verwechslung wie in P5c:

> **Ein Format, das für Bezeichner reicht, reicht nicht für Werte.**

Hier andersherum: Ein Format für **lange** Kennungen (`/usr/local/bin:/usr/bin:/bin`,
ein Dumppfad, ein Fingerabdruck) reicht nicht für **kurze Literale**, die als
Ganzes gelesen werden. `*/15` sagt nur als `*/15` etwas; über zwei Zeilen sagt es
nichts.

Der Betreiber wünscht sie ausserdem sichtbar als Code ausgezeichnet. Beides
zusammen ist ein eigener Baustein — ein Codestück, das nicht bricht — und nicht
eine Ausnahme an `.ident`; die Ausnahme wäre die vierte an derselben Klasse
(`.ident`, `.stacks td .ident`, der Bereichstitel aus `docs/46 §20.11`).
**Er braucht eine Grenze in der Länge**, sonst ist er der alte Fehler unter
neuem Namen, und dazu einen Wächter.

#### Befund 6 — die Abstände zwischen den Bereichen sind zu gross (1440 px)

Gemeldet vom Betreiber; es ist eine Angabe über die Gestaltung und keine über
einen Bruch. Die Zahlen dazu stehen oben: In der Kundensicht ist `--block-gap`
34px statt 26 und `--bereich-gap` 38px/52px statt 30/44. Die Seite trägt vier
Bereiche untereinander, und die Summe fällt hier zum ersten Mal auf, weil keine
andere Ansicht dieses Laufs so viele hat.

**Entschieden am 19. August: Der Befund gilt der Dichtestufe `customer`**, nicht
dieser Seite. Damit ändert die Behebung `--block-gap` und `--bereich-gap` in
`:root[data-density='customer']` — und damit **jede Kundenseite dieses Panels**.

**Und damit jede Aufnahme dieses Laufs.** Alle acht gemessenen Ansichten stehen
in dieser Stufe (siehe Kopf). Eine Änderung an den beiden Abständen ist eine
Änderung des Grundrisses; die Bilder zeigen danach etwas anderes, und die
Messungen sind an einem anderen Layout entstanden.

> **Ein Befund an einer Stellschraube, die alle Seiten teilen, macht die
> Aufnahmen aller Seiten ungültig — auch die, an denen niemand etwas
> auszusetzen hatte.**

**Daraus folgt die Reihenfolge, und sie ist nicht die naheliegende.** Zuerst wird
der Lauf **zu Ende gemessen** — Ansicht 9 und die zwanzig Zustände —, damit jeder
Befund bekannt ist. Dann werden alle Befunde **in einer Fassung** behoben. Dann
wird die Runde **neu gefahren**. Wer stattdessen jetzt behebt, misst ab der
nächsten Ansicht ein anderes Layout als in den acht davor und hat am Ende zwei
halbe Läufe.

#### Wunsch 1 — eine Experteneingabe für den Zeitplan

Der Betreiber wünscht neben den fünf getrennten Feldern eine Eingabe des ganzen
Ausdrucks, mit einem Umschalter „Einfach / Experte".

**Das ist kein Befund, sondern ein Merkmal**, und es steht in keinem
Abnahmekriterium von P6 (`docs/51`, `docs/61`).

Hier stand zuerst, es werde in diesem Schritt nicht gebaut — die Bilderrunde sei
der letzte Schritt vor der Abnahme, und ein neues Eingabefeld darin ändere die
Ansicht, die gerade geprüft wird. **Der Betreiber hat anders entschieden, und
sein Einwand trägt:** Die Runde ist wegen Befund 6 ohnehin ein zweites Mal zu
fahren, also kostet das Merkmal keine zusätzliche Runde. Gebaut am 19. August;
gefahren wird es mit der zweiten Runde und einem neuen `rc`.

**Beide Vorfragen sind beantwortet.** Der Ausdruck schreibt in die fünf Felder
zurück — er ist eine `computed`-Sicht mit Setzer und kein zweiter Wert —, und
über die Form eines Zeitplans urteilt weiter nur `Schedule::parse()` im Agenten:
Das Formular schickt kein neues Feld, und im Browser wird nichts beurteilt.

> **Eine Zusammenfügung darf doppelt stehen, eine Regel nicht.**

`CronScheduleFormTest::test_the_free_expression_is_a_view_on_the_five_fields` ist
der Wächter dazu, drei Brüche im Bruchskript. Die fünf Griffe, mit denen das auf
dem Server zu belegen ist, stehen als **`docs/63 §6b`** — das Bild allein sieht
einer Sicht nicht an, ob sie eine ist.

Im Container gemessen (echtes Chromium, gebautes Stylesheet, Dichtestufe
`customer`, 390 px): `dokument 0`, Gegenprobe `200/200`, `schiebt` leer, beide
Themes. Auf `cloudsrv24` steht die Messung aus.

### Ansicht 9 — Läufe (`/subscriptions/140/cron/8/runs`)

**Teil 1: Job A, mit Ausgabe.** Die Seite schiebt in keiner Lage — und der
Betreiber hat wieder einen Abstand gesehen, den keine Zahl erzeugt.

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` 304 · `tr` 276 | leer |
| 390 dunkel | 0 | **200/200** | `thead` 304 · `tr` 276 | leer |
| 1440 hell | 0 | **200/200** | **leer** | `.scrolls` 19 |
| 1440 dunkel | 0 | **200/200** | **leer** | `.scrolls` 19 |

Die 19 px bei 1440 sind die Laufliste in der rechten Hälfte von `.sections` —
knapp, aber gewollt, `darf: true`.

**Der Zustand „Lauf mit Ausgabe, aufgeklappt" ist damit mitgemessen**: Die
Aufnahmen zeigen die Ausgabekästen von Job A, und die vier Zeilen oben sind an
genau diesem Zustand entstanden.

#### Befund 7 — der Ausgabekasten stösst an die Trennlinie (1440 px)

`.output` trägt `margin-top: 14px` und **keinen** Rand nach unten. Die Zelle
darunter hat auch keinen: `td` steht auf `padding: 0 14px 0 0` und holt seine
Höhe aus `height: var(--row-height)` — für eine einzeilige Zelle richtig, für
einen Block darin nicht. Der Kasten endet also genau dort, wo die
`border-bottom` der Zeile anfängt.

**Der vierte Fund derselben Familie in diesem Lauf.** Befund 2, 3 und 4 waren
fehlende Nachbarpaare; dieser ist ein einseitig gesetzter Rand — dieselbe Frage,
nur an einem einzelnen Baustein statt zwischen zweien:

> **Eine Regel über den Nachbarn davor sagt nichts über den danach.**

Der Satz steht seit P5c neben der Paarliste in `app.css`. Dass er hier zum
vierten Mal zutrifft, ist kein Zufall: `margin-top: 14px` ist eine Aussage über
das, was über dem Kasten steht, und über das, was darunter kommt, hat nie jemand
etwas gesagt.

**Nicht behoben** — und die Ursache steht bei Befund 8, mit dem er sich eine
teilt.

#### Teil 2 — Job B, mit Rückgabewert 3

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` 304 · `tr` 276 | leer |
| 390 dunkel | 0 | **200/200** | `thead` 304 · `tr` 276 | leer |
| 1440 hell | 0 | **200/200** | **leer** | **leer** |
| 1440 dunkel | 0 | **200/200** | **leer** | **leer** |

**Bei 1440 rollt hier nichts** — anders als bei Job A, wo die Laufliste um 19 px
überlief. Der Unterschied ist die Ausgabespalte: Job B hat keine, dort steht
„keine Ausgabe", und damit passt die Tabelle in ihre Hälfte. Der Zustand „Lauf
mit Rückgabewert ≠ 0" ist damit gemessen.

#### Befund 8 — die Marke stösst an die Linie darüber, der Rückgabewert an die darunter (1440 px)

Eine Zeile von Job B trägt in der Ergebnisspalte zwei Zeilen: die Marke
„fehlgeschlagen" und darunter „Rückgabewert 3" (`td .quiet` mit
`display: block; margin-top: 3px`). Oben und unten steht nichts zwischen ihnen
und der Trennlinie.

**Befund 7 und 8 haben dieselbe Ursache, und sie steht in einer Zeile:**

```css
td {
  padding: 0 14px 0 0;
  height: var(--row-height);
}
```

Der senkrechte Rhythmus einer Tabellenzeile kommt hier **allein aus `height`**.
Das trägt, solange der Inhalt einzeilig ist und in die Höhe passt: Die Zeile ist
höher als ihr Text, und der Rest sieht aus wie Polsterung. Sobald der Inhalt
höher wird — ein Ausgabekasten, zwei Textzeilen —, ist die Höhe wirkungslos, und
was dann übrig bleibt, ist die Polsterung, die es nie gab.

> **Eine Höhe ist keine Polsterung. Sie sieht nur so aus, solange der Inhalt
> hineinpasst.**

Der Kommentar neben `td .quiet` benennt den Fall sogar — „eine zweite Textzeile
in der Zelle wächst darüber hinaus, und das ist hier gewollt" —, zieht daraus
aber keinen Schluss über den Abstand.

**Der erste Vorschlag lautete `padding: 8px 14px 8px 0` — und er war falsch.**
Gemessen war er, aber nur in der Dichtestufe `customer` mit ihren 48 px
Zeilenhöhe. Dort wächst nichts. In `admin` mit 40 px wächst eine **einzeilige**
Zeile damit auf **43 px**, und dann bestimmt nicht mehr die Dichtestufe die
Zeilenhöhe, sondern die Polsterung.

> **Eine Messung an einer Dichtestufe ist keine über die Achse.**

**Nachgemessen über beide Stufen und alle Werte von 0 bis 8 px**, im Container
gegen das gebaute Stylesheet, dieselbe Tabelle im selben Dokument:

| Polsterung | Zeile `admin` | Zeile `customer` | Kasten → Linie | Marke → Linie |
|---|---|---|---|---|
| **0** (vorher) | 40 | 48 | **0** | **1** |
| 4 | 40 | 48 | 4 | 5 |
| 5 | 40 | 48 | 5 | 6 |
| **6** | **40** | **48** | **6** | **7** |
| 7 | **41** | 48 | 7 | 8 |
| 8 | **43** | 48 | 8 | 9 |

**6 px ist der grösste Wert, bei dem keine der beiden Stufen ihre Zeilenhöhe
verliert** — das ist keine Vorliebe, sondern das Ergebnis der Messung.

### Behoben am 19. August

`td` trägt `padding: 6px 14px` mit `padding-left: 0`. Gegen das **gebaute**
Stylesheet nachgemessen: `admin` 40 px, `customer` 48 px, Kasten 6 px von der
Linie, Marke 7 px. Auf schmaler Fläche ändert sich nichts, weil `.stacks td`
dort seine eigene Polsterung und `height: auto` setzt — was zugleich erklärt,
warum beide Befunde nur bei 1440 px auffielen.

`TableStyleTest::test_a_cell_has_vertical_padding_below_the_measured_ceiling`
hält beide Enden fest: Die Polsterung ist grösser als 0 und höchstens 6. Zwei
Brüche im Bruchskript, beide gegengeprüft.

> **Ein Abstand, der fehlt, überläuft nicht — er sieht nur falsch aus.**

**Und es ist ein Eingriff wie Befund 6:** Er trifft jede Tabelle dieses Panels
und damit jede Aufnahme der Runde. Er ändert nichts an der Reihenfolge — die
Runde ist ohnehin zweimal zu fahren.

**Nicht behoben.**

## 1b. Die Zustände

**Dieser Durchgang ist eine Fehlersuche und kein Protokoll.** Nach der Behebung
ändern sich `--block-gap`, `--bereich-gap` und die Polsterung jeder
Tabellenzelle; die Zahlen hier gelten danach nicht mehr. Ihr Zweck ist, alle
Befunde zu kennen, **bevor** eine Fassung gebaut wird — die belastbaren Zeilen
entstehen in der zweiten Runde.

Gemessen wird je Zustand einmal bei 390 px. Das Thema wechselt die Farbe und
nicht die Geometrie; wo ein Zustand einen eigenen Kontrast mitbringt, kommt er
zusätzlich im zweiten Theme dazu.

| Zustand | Lage | `dokument` | Gegenprobe | `schiebt` | Fund |
|---|---|---|---|---|---|
| Dateimanager — Mehrfachauswahl | 390 dunkel | 0 | **200/200** | `thead` 367 · `tr` 339 | — |
| Dateimanager — nach dem Verschieben | 390 dunkel | 0 | **200/200** | `thead` 367 · `tr` 339 | — |
| Dateimanager — langer Name in den Krümeln | 390 dunkel | 0 | **200/200** | `thead` 367 · `tr` 339 | — |
| Dateimanager — Ziel im Baum wählen | 390 dunkel | 0 | **200/200** | `thead` 367 · `tr` 339 | — |
| Dateimanager — Packen, Namensfeld offen | 390 dunkel | 0 | **200/200** | `thead` 367 · `tr` 339 | **Befund 9** |
| Dateimanager — Verzeichnis anlegen | 390 dunkel | 0 | **200/200** | `thead` 367 · `tr` 339 | — |
| Dateimanager — Datei anlegen | 390 dunkel | 0 | **200/200** | `thead` 367 · `tr` 339 | — |
| Dateimanager — Datei hochladen | 390 dunkel | 0 | **200/200** | `thead` 367 · `tr` 339 | — |
| Dateimanager — Suchen | 390 dunkel | 0 | **200/200** | `thead` 367 · `tr` 339 | — |
| Dateimanager — Rechte an einer Zeile | 390 dunkel | 0 | **200/200** | `thead` 367 · `tr` 339 | **Befund 10** |
| Dateimanager — Umbenennen an einer Zeile | 390 dunkel | 0 | **200/200** | `thead` 367 · `tr` 339 | **Befund 10** |
| Editor — „zu gross" (`gross.bin`) | 390 hell **und** dunkel | 0 | **200/200** | **leer** | — |
| Editor — „binär" (`binaer.dat`) | 390 hell **und** dunkel | 0 | **200/200** | **leer** | — |
| Editor — nur lesbar (`/conf/hinweis.txt`) | 390 hell **und** dunkel | 0 | **200/200** | leer (`rollt`: `.cm-scroller` 189) | — |
| Suche — kein Treffer | 390 hell **und** dunkel | 0 | **200/200** | `thead` 166 · `tr` 138 | — |
| Suche — kein Treffer | 1440 hell **und** dunkel | 0 | **200/200** | `input` 24 · *(LastPass 489)* | — |
| Suche — gekürzt (500 Treffer) | 390 hell **und** dunkel | 0 | **200/200** | `thead` 166 · `tr` 138 · *(LastPass 489)* | **Befund 4**, zweite Fundstelle |
| Suche — gekürzt (500 Treffer) | 1440 hell **und** dunkel | 0 | **200/200** | **leer** | dieselbe |
| SFTP — ohne Schlüssel | 390 hell **und** dunkel | 0 | **200/200** | `thead` 358 · `tr` 330 | — |
| SFTP — ohne Schlüssel | 1440 hell **und** dunkel | 0 | **200/200** | **leer** (`rollt` leer) | — |
| Läufe — ohne Läufe (Job C) | 390 hell **und** dunkel | 0 | **200/200** | `thead` 318 · `tr` 290 | — |
| Cron — Formular „Ändern" offen | 390 dunkel | 0 | **200/200** | `thead` 511 · `tr` 483 | **Befund 10**, zweite Fundstelle |
| Cron — Kontingent voll (10 von 10) | 390 hell **und** dunkel | 0 | **200/200** | `thead` 511 · `tr` 483 | — |
| Cron — Kontingent voll (10 von 10) | 1440 hell **und** dunkel | 0 | **200/200** | **leer** (`rollt`: `.scrolls` 298) | — |
| Cron — ohne Jobs (Abonnement 137) | 390 hell **und** dunkel | 0 | **200/200** | `thead` 484 · `tr` 456 | — |
| Cron — ohne Jobs (Abonnement 137) | 1440 hell **und** dunkel | 0 | **200/200** | **leer** (`rollt` leer) | — |

**Damit sind alle Zustände gemessen**, die sich herstellen lassen. Zwei bleiben
ungemessen und sind es absichtlich: „Zugang gestört" und „Abonnement nicht
benutzbar" werden nur aufgenommen, wenn sie ohnehin eintreten — sie
herzustellen hiesse, einen Server kaputtzumachen, um ein Bild zu bekommen.

**Zur Mehrfachauswahl.** Zwei Einträge angekreuzt, die Auswahlleiste offen mit
sechs Knöpfen, und im Baum daneben steht das lange Verzeichnis aus `docs/63
§2.3` über acht Zeilen umgebrochen. `dokument` bleibt 0 — der Umbruch aus
`docs/46 §20.11` trägt hier also, und er trägt an der Stelle, an der er zuletzt
gebrochen war.

Die 367 px sind `.stacks thead` mit seiner Ankreuzspalte; der Mechanismus, nicht
der Fund.

**Zum Verschieben.** Aufgenommen ist der Zustand **nach** dem Vorgang: die
Meldung „Der Eintrag ist verschoben." und darunter der Baum mit dem Zielordner.
**Der Griff selbst — die Auswahl des Ziels im Baum — steht noch aus**; er ist ein
eigener Zustand aus `docs/63 §3` und wird von dieser Aufnahme nicht gezeigt. Ein
Protokoll, das einen Zustand behauptet, den sein Bild nicht trägt, ist derselbe
Fehler wie eine Zahl ohne Gegenprobe.

**Und dieselbe Aufnahme trägt einen zweiten Zustand mit**, den die Liste einzeln
führt: den langen Verzeichnisnamen in den Krümeln. Er steht dort über zwei Zeilen
umgebrochen, `dokument` bleibt 0. **Das ist die Stelle aus `docs/46 §20.11`**,
und sie ist die dritte Fassung derselben Ausnahme — hier mit einem Namen von 54
Zeichen statt der 63, an denen sie damals brach.

**Der offene Zielbaum ist nachgereicht** und damit der Zustand, der oben noch
fehlte: „Ziel im Baum wählen — 1 Eintrag verschieben", der Baum darüber
aufgeklappt, `dokument` 0.

### Befund 9 — der Knopf „Packen" steht 14 px zu hoch (390 px)

Gemeldet vom Betreiber am Bild. Im Namensfeld-Zustand der Auswahlleiste steht
der Knopf „Packen" neben dem Eingabefeld, aber nicht auf dessen Höhe.

**Die Ursache steht in einer Regel, die für sechs Knöpfe geschrieben wurde:**

```css
@media (max-width: 480px) {
  .selection .button-row { flex-direction: row; align-items: center; }
}
```

Sie stammt aus `docs/55` Befund 15 und ist dort richtig: Die Auswahlleiste soll
umbrechen statt zu stapeln, und sechs gleich hohe Knöpfe zentriert man. Im
Packen-Zustand steht in derselben Reihe aber ein `.field.inline`, und das ist
unter 480 px **eine Spalte** — Beschriftung über dem Feld. `align-items: center`
zentriert den Knopf dann gegen die ganze Gruppe aus Beschriftung *und* Feld, und
das Feld sitzt unten.

> **Eine Ausrichtung, die für gleich hohe Geschwister gilt, sagt nichts über
> eine Reihe, in der eines zwei Zeilen hoch ist.**

**Gemessen im Container** (echtes Markup, gebautes Stylesheet, `customer`,
390 px), dieselbe Seite einmal ohne und einmal mit `align-items: flex-end`:

| | ohne | mit |
|---|---|---|
| Mitte des Eingabefeldes | 94 px | 94 px |
| Mitte des Knopfes | 80 px | **94 px** |
| Versatz | **−14 px** | **0 px** |
| Reine Knopfreihe, sechs Knöpfe, Oberkanten | 182 · 182 · 236 · 236 · 290 · 290 | **unverändert** |

Die letzte Zeile ist die Gegenprobe: Der Eingriff bewegt die Leiste in ihrem
gewöhnlichen Zustand um **kein Pixel**, weil gleich hohe Geschwister unter
`center` und `flex-end` dieselbe Lage haben.

> **Ein Eingriff, der nur den kaputten Fall bewegt, ist an der richtigen Stelle.**

**Nicht behoben** — er kommt zu den anderen acht.

**Zu den Formularen der Kopfleiste.** Vier sind gemessen: Verzeichnis anlegen,
Datei anlegen, Datei hochladen, Suchen. Alle vier stehen als Feld über der
Schaltfläche, alle vier mit `dokument: 0`.

**„Rechte" und „Umbenennen" sind die beiden anderen**, und sie hängen nicht an
der Kopfleiste, sondern an einer **Zeile** der Liste (`Files/Index.vue`,
`chmodFor` und `renameFor`). Die Zählung „die vier Formulare" war meine, und sie
war falsch: Es sind sechs Griffe an zwei verschiedenen Orten.

> **Eine Aufzählung, die zwei Orte in einen Satz zieht, lässt den zweiten
> weg.**

Beide sind gemessen, beide ohne Überlauf — und beide tragen denselben Fehler, den
keine Zahl zeigt.

### Befund 10 — der geöffnete Bereich steht ausserhalb des Bildes (390 px)

Gemeldet vom Betreiber: Wer bei 390 px in einer Zeile weit unten „Rechte" oder
„Umbenennen" drückt, sieht **nichts geschehen**. Der Bereich öffnet sich am Kopf
der Seite; man muss von Hand hinaufrollen, um ihn zu finden.

**Für diesen Fehler gibt es in diesem Repo bereits die Behebung** — sie ist bloss
nur an einer Stelle angeschlossen. `resources/js/scroll.ts` steht seit dem
15. August genau deswegen da, und sein Kopf beschreibt denselben Vorgang: Der
Betreiber drückte auf einem iPhone „Entfernen" an einer Zeile weit unten, die
Rückfrage erschien oben, und sichtbar geschah gar nichts.

> **Eine Antwort, die ausserhalb des Bildes steht, ist für den Fragenden
> keine.**

`bringIntoView()` löst das, und `useConfirmation` ruft es. `startChmod()` und
`startRename()` in `Files/Index.vue` setzen nur ihre Referenz und rollen nicht.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.**

Derselbe Satz wie bei `docs/59` Befund 19 (der Menüpunkt) — und diesmal ist die
Regel sogar als Funktion vorhanden. **Die Behebung ist deshalb nicht ein Aufruf
an zwei Stellen, sondern ein Wächter darüber**, dass jeder Griff, der einen
Bereich am Seitenkopf öffnet, ihn auch ins Bild holt. Betroffen sind mindestens
`startChmod`, `startRename` — und zu prüfen sind `startPack` und die beiden
Zielwahlen (`picking`), die dieselbe Bauart haben.

**Nicht behoben.**

**Zu den beiden Editor-Zuständen.** Beide tragen eine gelbe Meldung und sind
deshalb in beiden Themes aufgenommen; die Zahlen sind in allen vier Aufnahmen
gleich. **`schiebt` ist vollständig leer** — kein `.stacks thead`, weil diese
Ansicht keine Tabelle hat, und sonst nichts. Dieselbe Lage wie bei Ansicht 3.

Die beiden Prüfkörper aus `docs/63 §2.1` haben damit ausgelöst, wofür sie
gebaut wurden: `gross.bin` (3 MiB) die Schwelle aus `FilesRead::MAX_BYTES`,
`binaer.dat` (31 Byte, davon vier, die es in UTF-8 nie gibt) die Prüfung
`mb_check_encoding()`. **Die Grösse entscheidet den zweiten Fall nicht** — das
war der Grund, ihn nicht als grosse Zufallsdatei zu bauen, und die Aufnahme
belegt es.

**Zu „ohne Läufe".** Der Zustand stand seit dem Beginn dieses Laufs als
aufgenommen, aber unbrauchbar in §3: Das Bild war bei Arbeitsplatzbreite
entstanden und trug keine Messzeile. Jetzt liegt er bei 390 px in beiden Themes
vor. **Ein Bild ohne seine Zahl ist kein halber Zustand, sondern keiner** — die
Frage dieses Schritts ist, ob die Seite bei 390 px schiebt, und die beantwortet
nur die Zahl.

**Zum nur lesbaren Zustand.** Hier taucht zum ersten Mal in diesem Lauf der
Rollbehälter des Editors auf — `.cm-scroller` mit 189 px unter `rollt`,
`darf: true`. `docs/63 §6` führt ihn als erwartet, und die Zeile belegt jetzt,
dass er auch wirklich erscheint: Bei Ansicht 3 war er leer, weil die Datei dort
vier Zeilen hat und passt.

> **Ein erwarteter Roller, der nie auftaucht, ist nicht geprüft — er ist
> unbeobachtet.**

**Nachgesehen, weil das Bild die Frage aufwarf: Der Knopf „Speichern" ist
abgeschaltet und nicht bloss wirkungslos.** `Files/Edit.vue` setzt `:disabled`
aus `can.edit` **und** `entry.writable`; `app.css` zeichnet einen abgeschalteten
Knopf mit `opacity: 0.5`. Kein Befund — festgehalten, weil ein farbiger Knopf
unter dem Satz „lässt sich lesen und nicht ändern" genau die Stelle ist, an der
`AbilityReachTest` sonst zuschlägt.

### Befund 10 gilt auch für die Cronseite — und zwar nach unten

Der Zustand „Job ändern" ist gemessen und schiebt nicht. Der Betreiber hat dabei
denselben Satz gesagt wie beim Dateimanager: **Beim Druck auf „Ändern" hat man
das Gefühl, es passiert nichts.**

`bearbeiten(job)` in `Cron.vue` setzt `bearbeitet` und füllt das Formular — der
Abschnitt heisst danach „Job ändern" statt „Job anlegen" —, aber er steht
**unterhalb** der Jobliste, und bei 390 px ist das ausserhalb des Bildes.

**Dieselbe Regel, die andere Richtung.** Bei `startChmod` und `startRename`
öffnete sich der Bereich oben, hier unten; für den Bedienenden ist es dasselbe.
Das ist der Beleg dafür, dass die Behebung nicht „nach oben rollen" heissen
darf, sondern **den geöffneten Bereich ins Bild holen** — genau das, was
`bringIntoView()` seit dem 15. August tut und was `fullyVisible()` dort in beide
Richtungen prüft.

> **Eine Behebung, die die Richtung nennt statt das Ziel, ist beim nächsten Fall
> die Hälfte wert.**

Damit stehen für Befund 10 drei Griffe fest: `startChmod`, `startRename`,
`bearbeiten`.

### Behoben am 19. August

Alle drei holen ihren Bereich jetzt ins Bild. `startPack` und die beiden
Zielwahlen brauchen es **nicht**, und das ist kein Übersehen: Ihr Bereich ist
die Auswahlleiste, in der der gedrückte Knopf selbst steht — sie ist damit
immer im Bild, und `fullyVisible()` liesse den Aufruf ohnehin wirkungslos.

**`RevealTest` erkennt den Fall am Argument.** Ein Griff, der einen Gegenstand
mitbekommt, steht in einer Schleife und damit an einer Zeile; ein Umschalter der
Seite kommt ohne Argument aus. Eine Näherung, keine Herleitung — sie trifft die
drei bekannten Fälle und lässt die Umschalter in Ruhe.

**Und sie hat neunzehn Griffe derselben Bauart in der Datenbankkonsole
gefunden.** Keiner davon ist untersucht: Die Konsole gehört zu P5c, und ob ihre
Bereiche bei 390 px ausserhalb des Bildes aufgehen, hat niemand gemessen. Sie
stehen einzeln in `RevealTest::UNEXAMINED` — **als Zahl, die kleiner werden
kann, und nicht als Befund.**

> **Was man beim Beheben nebenbei findet, ist noch kein Fehler — aber es ist
> auch keine Ruhe.**

**Der zweite Fall des Wächters kam aus dem Beheben selbst:** `bringIntoView()`
setzt am Ende den Fokus, und ohne `tabindex="-1"` nimmt ein `div`, `form` oder
`section` ihn nicht an. Der Sprung geschieht, der Tastaturweg bleibt zu.

> **Ein Aufruf, der stillschweigend nichts tut, sieht aus wie einer, der gewirkt
> hat.**

**Und der Wächter hat beim ersten Anlauf `FormErrors.vue` zu Unrecht gemeldet** —
sein Ausdruck war `<[^>]*ref="block"[^>]*tabindex="-1"`, und das Tag dort beginnt
mit `<p v-if="messages.length > 0"`.

> **Ein Ausdruck, der bei `>` aufhört, hört mitten in einem Attribut auf.**

### Zum Kontingent: der Roller wächst mit dem längsten Wert, nicht mit der Zeilenzahl

Bei 1440 px rollt die Jobliste hier um **298** px; bei Ansicht 8 mit drei Jobs
waren es **191**. Zehn Zeilen statt drei — aber die Zahl hängt nicht daran. Sie
hängt an der Spalte „Zeitplan": Dort steht jetzt „am 1. jedes Monats um 05:00"
statt „jede Minute", und die Tabelle wird so breit wie ihre breiteste Zelle.

> **Eine Tabelle wird nicht von der Zahl ihrer Zeilen breit, sondern von einem
> einzigen Wert darin.**

Dasselbe Muster wie bei der Schlüsseltabelle (§1b, SFTP ohne Schlüssel): Der
Roller kommt und geht mit dem Inhalt, nicht mit dem Bestand.

### Eine Zahl, die sich zwischen zwei Messungen derselben Seite ändert

`.stacks thead` steht auf der Cronseite bei Ansicht 8 mit **484** px, bei „Job
ändern" mit **511** und beim vollen Kontingent wieder mit **511** — dieselbe
Seite, dieselbe Breite, dieselben fünf Spaltenüberschriften. Der Unterschied von
27 px ist **nicht erklärt.**

**Hier stand nach dem dritten Messwert, die 484 sei der Ausreisser.** Der vierte
hat das widerlegt: Abonnement 137 **ohne einen einzigen Job** liefert wieder
**484**. Die Werte stehen damit auf 484 · 511 · 511 · 484, und die
naheliegendste Erklärung — die Zahl hänge am Bestand der Tabelle — trägt auch
nicht: Bei Ansicht 8 lagen drei Jobs und es waren 484, beim Formular „Ändern"
lagen dieselben drei und es waren 511.

> **Eine Erklärung, die zum dritten Messwert passt, ist keine — sie ist eine
> Erklärung für drei Messwerte.**

Was **feststeht**: Der Wert gehört zu `.stacks thead`, also zum Mechanismus aus
`app.css` und nicht zu einem Fund; `dokument` ist in allen vier Fällen 0; und
die Frage berührt das Kriterium dieses Schritts nicht.

Was **offen bleibt**: warum dieselbe Kopfzeile aus fünf Wörtern zwei
verschiedene Breiten hat. Die Vermutung „Schriftart noch nicht geladen" steht
weiter da und ist weiter unbewiesen; der vierte Wert schwächt sie eher, weil
zwischen den Messungen viel Zeit lag. Vor der zweiten Runde zu klären — mit dem
Messmittel, nicht mit einer Überlegung.

**Für das Kriterium dieses Schritts ist es gleichgültig** (`dokument` ist beide
Male 0, und `.stacks thead` ist ohnehin der Mechanismus und kein Fund). Für die
**zweite Runde** gehört die Frage geklärt, sonst steht dort wieder eine Zahl, die
sich beim nächsten Ansehen ändert.

> **Zwei verschiedene Zahlen für denselben Gegenstand sind ein Befund am
> Messmittel, bis eine von beiden erklärt ist.**

### Ohne Schlüssel rollt die Schlüsseltabelle nicht mehr — und das erklärt Ansicht 6

Bei 1440 px ist hier **`rollt` leer**. Ansicht 6 hatte an derselben Stelle
`.scrolls` mit 217 px, und die Erklärung dort lautete: Ein
`SHA256:`-Fingerabdruck passt in der halben `.sections`-Spalte nicht neben
Bezeichnung, Art und Aktion.

**Dieser Zustand belegt sie.** Ohne Schlüssel steht in der Tabelle nur „Kein
Schlüssel eingetragen.", der Fingerabdruck fehlt — und der Roller verschwindet
mit ihm. Dieselbe Seite, dieselbe Breite, ein Datensatz weniger.

> **Eine Erklärung, die man an einem zweiten Zustand nachrechnen kann, ist keine
> Vermutung mehr.**

Und die Meldung „Es ist kein Schlüssel eingetragen" steht hier **mit** Abstand
nach oben: Sie folgt unmittelbar auf einen `.section-head`, und der bringt seinen
Rand mit. Befund 4 trifft sie deshalb nicht — was zugleich zeigt, woran er
hängt: nicht an `.notice`, sondern an dem, was davorsteht.

### Befund 4 hat eine zweite Fundstelle — und der Prüfkörper hat gehalten

**Der Zustand „gekürzt" ist erreicht**, und `docs/63 §2.3b` hat dabei genau
getan, wofür die Zahl 520 gewählt wurde: Die Seite meldet **„angesehene
Einträge: 515"** und darunter den Abbruch. Der Suchlauf ist also bei 500
Treffern stehen geblieben, **bevor** er alle 520 Dateien gesehen hatte — mit 500
Dateien wäre die Liste vorher zu Ende gewesen und `truncated` `false`
geblieben.

> **Ein Prüfkörper, der die Grenze nur erreicht, belegt sie nicht — er muss
> darüber hinausgehen.**

**Und der Betreiber hat am Bild gesehen, was `dokument: 0` nicht zeigt:** Die
gelbe Meldung klebt am Satz „Gesucht unter / — angesehene Einträge: 515."
darüber. `Files/Search.vue` setzt dort

```html
<p class="quiet">Gesucht unter …</p>
<p v-if="props.truncated" class="notice warn">…</p>
```

— und das ist **derselbe Fall wie Befund 4** auf der Cronseite: ein `<p>` vor
einer `.notice`. `.notice` trägt `margin-bottom` und keinen Rand nach oben, ein
`<p>` hat durch Tailwinds Reset gar keinen, und die Paarliste kennt weder das
eine noch das andere.

**Deshalb keine neue Nummer.** Es ist eine zweite Fundstelle desselben Fehlers,
und die Behebung ist dieselbe. Sie ist hier festgehalten, damit die
Nachprüfung **beide** Stellen ansieht — eine Behebung, die nur dort geprüft
wird, wo der Fehler zuerst auffiel, ist keine Regel, sondern ein Pflaster.

Der Kasten in der Mitte bei 390 px ist Befund 1 und schon bekannt.

### Ein Eingabefeld steht unter `schiebt` und ist keiner

Bei 1440 px meldet die Suchseite einen neuen Eintrag: `input` mit 24 px, Weg
`div.frame > main.content > form.button-row > label.field:1 > input`, `anfang`
`<input type="search" autocomplete="off" required>`. Der Suchbegriff
`zeichenkettediesnichtgibt` ist breiter als das Feld.

**Das ist kein Fund.** Ein Textfeld rollt seinen Inhalt von sich aus, ohne dazu
`overflow-x: auto` zu tragen — der Wert steht auf `clip`, und damit landet es
in `schiebt` statt in `rollt`. Die Seite schiebt dabei nicht: `dokument` bleibt
0, und bei 390 px fehlt der Eintrag ganz, weil das Feld dort die volle Breite
hat und der Begriff hineinpasst.

> **Ein Behälter, der von sich aus rollt, sagt es der Messung nicht — sie kennt
> nur `overflow-x`.**

Die Liste `schiebt` hat damit ihre dritte Sorte gewollter Einträge, nach
`.stacks thead` und der Meldezeile der Erweiterung. Sie bleibt, was sie seit der
ersten Messung ist:

> **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein Urteil.**

**Und die Fremdzeile ist zurück** — bei 1440 mit 489 px, bei 390 nicht. Dasselbe
Fenster, dieselbe Seite, zwei Breiten: Die Erweiterung setzt ihre Meldezeile,
wann sie will. Das ist der Grund, aus dem `docs/63 §6` für die **zweite** Runde
ein Fenster ohne Erweiterungen verlangt.

**Und eine Randnotiz aus dem `anfang`:** In der Kopfzeile steht ein Attribut
`wfd-id="id8"`, das dieses Panel nicht schreibt — wieder eine Erweiterung, die
ins Dokument fasst. Hier ohne Folge für die Geometrie; benannt, weil dieselbe
Sorte Element vier Ansichten lang wie ein Befund aussah (§2).

---

## 2. Was dieser Lauf über sein eigenes Prüfmittel gelernt hat

### Der `div` mit 468 px gehörte nicht zur Seite — er gehörte zum Browser

**Vier Ansichten lang stand in `schiebt` eine Zeile `div` mit 468 px**, und drei
Erklärungsversuche daneben: erst ein Rest eines Breitenwechsels, dann die rechte
Hälfte von `.split`, dann die Feststellung, dass es die auf der Suchseite gar
nicht gibt. Am 19. August hat die um `pfad` und `anfang` erweiterte Messung die
Frage in einer Zeile beantwortet:

```
"pfad": "div:3",  "ueberlauf": 468,
"anfang": "<div id=\"lp-menu-live-region\" role=\"status\" aria-live=\"polite\"
           aria-atomic=\"true\" aria-relevant=\"all\" style=\"clip: rect(…"
```

`lp-menu-live-region` ist **LastPass**. Die Erweiterung setzt in jedes Dokument
eine verborgene Meldezeile — 1 px breit, `clip: rect(…)`, also `overflow`
versteckt und damit `darf: false`. Damit stimmt alles, was vorher unerklärlich
war: Die Zahl ändert sich mit der Fensterbreite nicht, weil sie die Textbreite
in einem 1-px-Kasten ist. Sie steht auf drei verschiedenen Seiten gleich, weil
sie zu keiner davon gehört. Und sie kommt und geht, weil die Erweiterung sie
nicht beim Laden setzt, sondern wenn sie die Seite angesehen hat — deshalb war
sie bei Ansicht 2 frisch geladen weg und nach einem Breitenwechsel da.

**Das Panel hat damit nie geschoben, und keine Zahl war falsch.** `dokument`
stand in jeder dieser Lagen auf 0. Falsch war die Zuordnung: Zwei Absätze in
diesem Protokoll haben einen fremden Kasten dem eigenen Bau zugeschrieben, und
einer davon nannte sogar eine Zeilennummer.

> **Eine Messung am Dokument misst auch, was der Browser hineingeschrieben hat.**

> **Ein Kasten, der auf jeder Seite gleich breit ist, gehört zu keiner von
> ihnen.**

Der Preis dafür war klein, weil `dokument` unbeeindruckt blieb — aber er wäre es
nicht gewesen, wenn die Meldezeile breiter als das Fenster gewesen wäre: Dann
hätte die Runde einen Fehler am Panel gemeldet, den es nicht gibt, und ihn
gesucht. `docs/63 §6` verlangt seitdem ein Fenster ohne Erweiterungen.

**Und die Ursache dafür, dass es vier Ansichten gedauert hat, war nicht die
Erweiterung.** Sie war, dass `wo` aus Marke und Klassen bestand: Ein
eingesetztes Element hat keine Klasse dieses Projekts, heisst also `div` — und
`div` sieht aus wie etwas Eigenes.

> **Eine Zahl, die nicht sagt, welche, zwingt zum Suchen.**


### Eine Messung nach einem Breitenwechsel trägt Reste mit

Die vier Lagen oben sind so entstanden, wie `docs/63 §4` es zuerst vorschrieb:
einmal laden, dann Breite und Thema umschalten und je neu messen. Der `div` mit
468 px steht deshalb bei **beiden** Breiten mit **derselben** Zahl da — und eine
Zahl, die sich mit der Fensterbreite nicht ändert, gehört zu keinem Kasten, der
am Fenster hängt.

Die Gegenprobe: Dieselbe Seite bei 1440 **frisch geladen**, mit einem Ausdruck
über *jedes* überlaufende Element, ergibt `[]` — kein einziges.

| 1440 px | Ergebnis |
|---|---|
| nach Wechsel von 390, ohne Neuladen | `div`, Überlauf 468 |
| frisch geladen | **nichts** |

> **Eine Messung nach einem Wechsel der Breite misst auch, was von vorher übrig
> ist.**

**Nachgemessen, und der Eintrag ist vollständig verschwunden** — bei 1440 *und*
bei 390. Ansicht 2 zeigt frisch geladen nur noch `.stacks thead` mit seinem
`tr`, und bei 1440 gar nichts.

| Ansicht 2, 390 px | `schiebt` |
|---|---|
| nach Wechsel von 1440, ohne Neuladen | `thead` 350 · `tr` 322 · **`div` 468** |
| frisch geladen | `thead` 350 · `tr` 322 |

**Und die Erklärung dafür ist inzwischen eine andere, als hier zuerst stand.**
Der Eintrag war kein Rest des Layouts: Es war die Meldezeile von LastPass, die
beim Laden noch nicht dasteht und nach einer Weile im Dokument erscheint. Was
zwischen den beiden Messungen lag, war also nicht der Breitenwechsel, sondern
die Zeit.

**Damit hat dieser Abschnitt seine Beweislage verloren, und die Gegenprobe hat
sie widerlegt.** Die beiden dunklen Lagen von Ansicht 2 sind ohne Neuladen
zwischen den Breiten entstanden und liefern bei 390 exakt die Zahlen der frisch
geladenen hellen Lage. Ein Rest war da nie.

**Die Regel steht trotzdem.** Ein Neuladen nach dem Breitenwechsel ist billig,
und die Fälle, die es abfängt — eine Komponente, die ihre Breite beim Aufbau
einmal liest —, gibt es wirklich; auf diesen Seiten gibt es sie nur nicht. Der Satz darüber bleibt richtig
und ist hier bloss nicht bewiesen worden:

> **Eine Messung nach einem Wechsel der Breite misst auch, was von vorher übrig
> ist.**

> **Ein Unterschied zwischen zwei Messungen gehört nicht dem, was man dazwischen
> getan hat, solange man nicht weiss, was sonst noch dazwischen lag.**

**Kein Fund am Panel.** In keinem der beiden Zustände hat die Seite geschoben.

### `schiebt` ist ein Hinweis und kein Urteil

`docs/63 §5` zählte jeden Eintrag als Fund. Die erste Messung hat das widerlegt:
`.stacks thead` steht dort bei jeder Ansicht mit einer Tabelle, und es ist genau
das, was es sein soll.

> **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein Urteil.**

Das Kriterium ist seitdem `dokument: 0` mit gültiger Gegenprobe; jeder Eintrag
in `schiebt` wird einzeln beurteilt und hier benannt.

### Ein Eingriff des Bruchskripts ist mit seiner Regel umgezogen

**Gefunden hat es die CI, und hier wäre es zu finden gewesen.** Die Behebung der
Befunde 7 und 8 hat der Liste vor `+ .notice` einen Eintrag hinzugefügt und drei
Zeilen mit `.quiet` daruntergesetzt. Der Eingriff aus `tests/waechter-brechen.sh`
griff aber die **ganze Zeile** — vom `:is(` bis zum ` {` — und fand seinen Text
danach nicht mehr. Beide roten Läufe des ersten Anlaufs sind dieser eine
Fehlschlag: `BreakScriptTest::test_every_intervention_still_grips_its_file`, bei
2075 grünen Fällen daneben.

> **Ein Eingriff, der die ganze Zeile greift, zieht mit jedem Eintrag um, der
> dazukommt.**

Gegriffen wird jetzt nur noch das Stück um `.pager` — das ist der Teil, der die
Regel trägt. Nachgemessen: heil `4 grün / 0 rot`, mit dem Eingriff
`3 grün / 1 rot`, und die rote Zeile nennt `test_every_seam_between_two_flush_blocks_is_covered`
— genau den Fall, auf den der Eingriff zeigt.

**Und der zweite Teil der Lehre ist teurer als der erste:** `BreakScriptTest`
erbt von `PHPUnit\Framework\TestCase` und läuft damit **in diesem Container**,
im framework-freien Gestell. Ich habe die 46 Fälle der neuen und geänderten
Wächter gefahren und den Wächter über die Wächter nicht — weil ich an die
Regeln gedacht habe, die ich gebaut hatte, und nicht an die, die ich unterwegs
verändert habe.

> **Ein Wächter, der die eigene Änderung nicht im Blick hatte, wird nicht
> gefahren — man denkt an das Gebaute und nicht an das Berührte.**

### Und der Eingriff daneben war wirkungslos — der neue Wächter war blind

**Derselbe Lauf, eine Stufe tiefer.** Der Bruchlauf meldete
`1 Prüfung(en) ohne Biss, davon 0 ohne Messung` — der Eingriff „leiser Text nur
in der Zelle" veränderte `app.css` also wirklich, und `StandaloneClassTest`
blieb trotzdem grün. Das ist der Fall, den dieses Repo am meisten fürchtet: eine
Regel mit einem Wächter, der nichts hält.

**Zwei Fehler steckten übereinander, und beide im Wächter.**

Der erste ist ein Trennzeichen. `selectors()` zerlegte jeden Selektorkopf mit
`explode(',', …)` — auch **innerhalb** einer Klammer. Aus

```
:is(.field, .hint, .error, .scrolls, .pager, .cell-value, .quiet, .section-note) + .button-row
```

wurden dabei acht Bruchstücke, darunter das nackte `.quiet`. Das sieht aus wie
eine freistehende Regel und ist keine — es ist ein Stück aus der Mitte einer
Liste.

> **Ein Trennzeichen, das innerhalb einer Klammer trennt, erfindet Selektoren.**

Der zweite ist der Kombinator. Auch mit heiler Klammer stand `.quiet` noch in
`.quiet + .notice` und `.quiet + .scrolls` als **erste** Verbindung da, und die
zählte der Wächter. Gestaltet wird dort aber `.notice` beziehungsweise
`.scrolls`; `.quiet` ist nur die Bedingung und bekommt selbst nichts.

> **Eine Regel, die den Nachbarn gestaltet, gestaltet nicht die Klasse.**

Beides ist behoben: Das Komma trennt nur noch ausserhalb von Klammern
(`splitOutsideParentheses()`), und eine erste Verbindung vor `+` oder `~` zählt
nicht mehr (`firstConnection()`, klammerfest — wer am ersten Leerzeichen
schneidet, hört mitten in `:is(.field, .hint)` auf).

**Gemessen an beiden Enden, weil eine schärfere Regel auch zu scharf sein kann:**

| | Klassen mit freistehender Regel | `quiet` |
|---|---|---|
| heil, alte Rechnung | 91 | freistehend |
| heil, neue Rechnung | **91** | freistehend |
| mit dem Eingriff, neue Rechnung | 90 | **fehlt** |

Keine Klasse kommt dazu, keine fällt weg — die neue Rechnung urteilt über die
heile Datei genau wie die alte und beisst nur dort, wo sie soll. Vier Klassen
hätte eine naheliegendere Verschärfung mitgenommen (`over`, `rows`, `running`,
`tight`): Sie stehen als `.bar.over > i`, `.rows td`, `.badge.running::before`
da und gestalten, was **unter** ihnen liegt — das reist mit dem Element und ist
sehr wohl eine eigene Regel. Der Unterschied liegt nicht darin, ob ein
Kombinator folgt, sondern **welcher**.

**Warum das nicht vorher auffiel.** Der Eingriff stand im Bruchskript, und das
braucht `vendor/bin/phpunit` — hier nicht vorhanden. Also ist er ungeprüft
gepusht worden. Nötig gewesen wäre er nicht: Ein Eingriff lässt sich von Hand
anwenden, der Wächter im Gestell fahren, die Datei zurückholen. Genau so ist er
jetzt belegt — heil `2 grün / 0 rot`, mit dem Eingriff `0 grün / 2 rot`,
zurückgesetzt wieder `2 grün / 0 rot`.

> **Ein Wächter, der nie rot war, ist kein Wächter — und „das Bruchskript läuft
> hier nicht" ist keine Ausrede, sondern ein Handgriff mehr.**

---

## 2b. Befund 11 — `.quiet` gilt nur in einer Tabelle

**Gefunden beim Vorbereiten der Behebung**, nicht in einem Bild. Für die
Nachbarpaare (Befunde 2, 3, 4) war zu klären, welche Bausteine überhaupt
beteiligt sind — einer davon ist `.quiet`. In `app.css` steht dazu:

```css
td.quiet { … }
td .quiet { … }
```

**Und sonst nichts.** Ausserhalb einer Tabellenzelle greift die Klasse nicht.

**Gemessen im Container** (gebautes Stylesheet, echtes Chromium, Dichtestufe
`customer`):

| | Farbe | entspricht |
|---|---|---|
| `<p>` ohne Klasse | `rgb(58, 63, 73)` | `--text` |
| **`<p class="quiet">`** | **`rgb(58, 63, 73)`** | **`--text`** — kein Unterschied |
| `<td class="quiet">` | `rgb(92, 100, 112)` | `--text-muted` |

**Das steht auf Bildern dieses Laufs, und ich habe es viermal übersehen.** Auf
der Suchseite ist „Gesucht unter / — angesehene Einträge: 20." ein
`<p class="quiet">`; im Editor ist „Diese Datei gehört nicht dem Abonnement —
sie lässt sich lesen und nicht ändern." einer. Beide sollen leise sein und sind
es nicht.

> **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
> verdeckt alles, was daneben steht.**

Derselbe Satz wie in `docs/59`, dort über neun Meldungen in Markdown. Hier war
die Frage „schiebt die Seite?", und die Farbe eines Absatzes stand daneben.

**Und `ClassReachTest` ist dabei grün**, zu Recht nach seinem Wortlaut: Die
Klasse `.quiet` **kommt** in `app.css` vor. Dass sie nur als Nachfahrenregel
vorkommt, prüft er nicht.

> **Eine Klasse, die es nur als Nachfahrenregel gibt, ist ausserhalb ihres
> Vorfahren ein Wunsch.**

**Umfang: 161 Stellen, davon fünf ausserhalb einer Zelle.** Gezählt über die
Vorlagen und danach einzeln nachgesehen:

| Stelle | was dort steht |
|---|---|
| `Files/Search.vue:61` | „Gesucht unter … — angesehene Einträge: 20." |
| `Files/Edit.vue:97` | „Diese Datei gehört nicht dem Abonnement …" |
| `Files/Index.vue:643` | die Fortschrittszahl beim Hochladen |
| `Files/Index.vue:726` | der Trenner `/` in den Krümeln |
| `Components/PermissionEditor.vue:235` | `rw-r--r--` neben dem Oktalfeld |

**Die letzte steht auf dem Bild des Zustands „Rechte" aus §1b**, die erste auf
dem der Suche, die zweite auf dem des Editors. Drei von fünf lagen offen vor mir.

**Und die Zählung selbst brauchte eine Gegenprobe.** Das Skript, das den Weg zu
jeder Stelle mitzählt, hat **zwei** Stellen als „ausserhalb" gemeldet, die in
Wahrheit in einer Zelle stehen: Sein Stapel war vorher aus dem Tritt geraten.
Von Hand nachgesehen blieben fünf statt sieben.

> **Eine Zählung, die einen Weg mitführt, ist nur so gut wie ihr Weg — und der
> bricht bei einem Zeichen, das niemand erwartet.**

### Behoben am 19. August

Die Farbe steht jetzt einmal als `.quiet`; `td .quiet` behält nur, was wirklich
zur Zelle gehört. Nach der Änderung im Container gemessen: `<p class="quiet">`
trägt `--text-muted`, die Zelle ist unverändert, ein Absatz ohne Klasse
ebenfalls.

**`StandaloneClassTest` ist der Wächter dazu**: Jede Klasse, die eine Vorlage
benutzt und die `app.css` kennt, braucht eine freistehende Regel — acht dürfen
ausdrücklich anders sein und nennen ihren Zusammenhang. Drei Brüche im
Bruchskript, alle drei gegengeprüft.

**Beim ersten Anlauf war der Wächter blind**, und der Fehler lohnt die Notiz: Er
zählte **jede** Verbindung eines Selektors statt nur der ersten und hielt damit
`.split > .aside` für eine freistehende Regel von `.aside`. Ein Wächter gegen
Nachfahrenregeln, der Nachfahrenregeln für freistehend hält, prüft das Gegenteil
von dem, wofür er da ist.

> **Ein Wächter, der die Sorte Fehler nicht kennt, gegen die er gebaut wurde,
> ist grün.**

Aufgefallen ist es sofort, weil `.aside` als erster Eintrag der Liste rot
meldete — die Sperrklinke hat den Wächter gegen sich selbst verteidigt.

---

## 3. Was offen ist

- **Alle elf Befunde sind behoben** und im Container gemessen. Was aussteht,
  ist die Gegenprobe auf `cloudsrv24` — sie ist die zweite Runde.

  | Befund | Behebung | gemessen |
  |---|---|---|
  | 1 | Kästchen als `.toggle` | 390×44 → 17×17 px, Leiste 207 → 171 |
  | 2, 3, 4 | `.section-note` mit eigenem Rand, drei Fugen um `.quiet`, Absatz ohne Klasse | vier Fugen 0 → 34 px |
  | 5 | `.ident.literal` mit Wächter über Länge und Inhalt | — |
  | 6 | Dichtestufe eine Sprosse tiefer | drei Bereiche 401 → 385 px |
  | 7, 8 | `padding: 6px 14px` an `td` | Zeile bleibt 40/48, Kasten 0 → 6, Marke 1 → 7 |
  | 9 | `align-items: flex-end` | Versatz −14 → 0 px |
  | 10 | `bringIntoView` an drei Griffen | — |
  | 11 | `.quiet` als freistehende Regel | `--text` → `--text-muted` |
- **Die vollständige Umkehrung der Abstandsregel** — jeder Block holt sich
  seinen Rand nach oben selbst — ist **nicht** getan. Ein erster Anlauf hat
  beim Messen Fugen mitgedeckt, die niemand angesehen hatte
  (`button-row + scrolls`, `scrolls + scrolls`). Sie gehört in einen eigenen
  Durchgang mit eigenen Aufnahmen.

  > **Eine Regel, die mehr deckt als das Gemessene, ändert mehr als das
  > Gemessene.**
- **Neunzehn Griffe in der Datenbankkonsole**, gefunden beim Beheben von
  Befund 10 und nicht untersucht. Sie stehen in `RevealTest::UNEXAMINED`.

  Was über sie bekannt ist, ist ausschliesslich dies: Sie öffnen einen Bereich,
  und ob der dabei im Bild landet, hat niemand angesehen. Die Konsole gehört
  nicht zu den neun Ansichten dieses Schritts, deshalb bleiben sie hier stehen
  statt geraten zu werden.

  > **Ein Eintrag auf einer Ausnahmeliste ist kein Befund und keine
  > Unbedenklichkeit — er ist eine offene Frage mit einem Namen.**

  **Hier stand bis zum 20. August eine Tabelle über die elf Befunde**, eingerückt
  unter diesem Punkt und eingeleitet mit „Sie fallen in drei Gruppen". Gemeint
  waren die Befunde, gelesen wurden die neunzehn Griffe — und damit sah eine
  offene Frage aus wie fünfmal „behoben". Die Tabelle steht jetzt oben, wo sie
  hingehört.

  > **Eine Tabelle unter der falschen Überschrift beantwortet die falsche
  > Frage — und zwar zuversichtlich.**
- **Die 27 px an `.stacks thead` der Cronseite** — vier Messungen, 484 · 511 ·
  511 · 484, und weder die Reihenfolge noch der Bestand der Tabelle erklärt sie.
  Vor der zweiten Runde klären, mit dem Messmittel und nicht mit einer
  Überlegung.
- **Die Behebungen auf `cloudsrv24` gegenprüfen.** Im Container sind sie
  gemessen; auf dem Server stehen sie aus und gehören in die zweite Runde.
- **Wunsch 1 ist gebaut** und auf `cloudsrv24` noch nicht gefahren: die fünf
  Griffe aus `docs/63 §6b` und der Zustand „Zeitplan als Ausdruck" aus §3.
- **Die Runde danach noch einmal.** Befund 6 ändert die Dichtestufe `customer`,
  und in der stehen alle Aufnahmen dieses Laufs. Erst zu Ende messen, dann alles
  in einer Fassung beheben, dann neu fahren.
- **Kein Zustand mehr** — alle herstellbaren sind gemessen (§1b). Alles andere
  ist gemessen — siehe §1b. „Zugang gestört" und „Abonnement nicht benutzbar"
  bleiben ungemessen, weil sie ausdrücklich nicht hergestellt werden.

**Damit ist Schritt 12 nicht abgeschlossen**, und P6 ist nicht abgenommen.

---

## 4. Die zweite Runde — gegen `v0.6.0-rc.19`

**Angelegt am 20. August 2026, bevor eine Zahl darinsteht.** `rc.19` ist auf
`cloudsrv24` installiert; der Tag steht auf `2d751df`, dem Merge von PR #154.

Diese Runde ist keine Wiederholung der ersten. Sie hat drei Aufgaben, und die
dritte ist die, an der die Stufe hängt:

1. **Die elf Behebungen auf dem echten Server gegenprüfen.** Gemessen sind sie
   im Container. Was dort aufs Pixel stimmt, stimmt hier aufs Pixel — aber nur,
   solange es dieselbe Seite ist, und das ist eine Behauptung über echte Daten.
2. **Die Experteneingabe fahren**, Bild und fünf Griffe (`docs/63 §6b`).
3. **Und alles noch einmal messen, was die erste Runde gemessen hat** — denn
   Befund 6 hat die Dichtestufe `customer` verändert, und in der stehen
   sämtliche Aufnahmen des ersten Laufs. Jede Zahl von dort ist damit die Zahl
   einer Fassung, die es nicht mehr gibt.

   > **Eine Behebung, die an der Achse ansetzt, macht jede Messung daneben
   > ungültig — auch die, die nichts mit ihr zu tun hatte.**

**Was diese Runde anders macht als die erste:** Sie wird in einem Fenster
**ohne Erweiterungen** gefahren. Die erste hat 468 px falschen Überlaufs
gekostet, zweimal falsch erklärt, bis der Ort im Fund die Ursache nannte —
`lp-menu-live-region`, ein Kasten des Browsers und nicht der Seite.

### 4.1 Die neun Ansichten

`dokument` ist überall 0, die Gegenprobe überall 200/200 — sonst stünde die
Zeile hier nicht als gemessen.

| # | Ansicht | 390 hell | 390 dunkel | 1440 hell | 1440 dunkel |
|---|---|---|---|---|---|
| 1 | Dateien, Auswahl | — | — | — | — |
| 2 | Dateimanager | — | — | — | — |
| 3 | Editor | — | — | — | — |
| 4 | Suche | — | — | — | — |
| 5 | SFTP, Auswahl | — | — | — | — |
| 6 | SFTP-Zugang | — | — | — | — |
| 7 | Cron, Auswahl | — | — | — | — |
| 8 | Cronjobs | **0** | **0** | **0** | **0** |
| 9 | Läufe | **0** | **0** | **0** | **0** |

#### Ansicht 8 — Cronjobs, gemessen am 20. August gegen `rc.19`

Aufgenommen in der Kundensicht auf Abonnement 140, **mit vollem Kontingent**
(10 von 10 Jobs) — die Seite stand nach dem ersten Lauf so da.

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` **484** · `tr` **456** | leer |
| 390 dunkel | 0 | **200/200** | `thead` **484** · `tr` **456** | leer |
| 1440 hell | 0 | **200/200** | leer | `.scrolls` 248, `darf: true` |
| 1440 dunkel | 0 | **200/200** | leer | `.scrolls` 250, `darf: true` |

Der Roller bei 1440 ist die Jobliste in der rechten Hälfte von `.sections`,
gewollt. Dass er zwischen den Themes um 2 px auseinandergeht (248 gegen 250),
ist die Schriftlage und kein Fund — er darf rollen.

**Die vier Fugen aus den Befunden 2, 3 und 4 sind im Bild da**, die Meldung über
die Zeitzone steht mit Abstand unter dem Satz darüber, und `*/15` bricht im
Erklärungstext nicht mehr (Befund 5). Die Dichtestufe aus Befund 6 trägt: Die
Bereiche stehen enger als im ersten Lauf.

#### Die 27 px sind keine Eigenschaft des Bestands — und der einzige Kandidat, der bleibt, ist der Weg zur Seite

Die offene Frage aus §2 lautete: `.stacks thead` misst auf derselben Seite
**484** oder **511**, und weder Reihenfolge noch Bestand erklären es. Die
Messwerte des ersten Laufs:

| Zustand | Jobs | `thead` · `tr` | wie die Seite entstand |
|---|---|---|---|
| Ansicht 8 | 3 | 484 · 456 | frisch geladen |
| Abonnement 137 | 0 | 484 · 456 | frisch geladen |
| Formular „Ändern" offen | 3 | **511 · 483** | Knopf gedrückt, **kein** Neuladen |
| Kontingent voll | 10 | **511 · 483** | zehn Jobs angelegt, **kein** Neuladen |

**Und jetzt derselbe Zustand „Kontingent voll" frisch geladen: 484 · 456.**

Damit fällt die letzte Erklärung, die am Zustand hing. Zehn Jobs ergeben einmal
511 und einmal 484 — der Unterschied liegt nicht darin, *was* auf der Seite
steht, sondern darin, *wie sie dorthin gekommen ist*: Die beiden 511er sind die
beiden Lagen, die durch einen Klick entstanden sind und nicht durch ein
Neuladen. Beide Male sind es exakt 27 px, an `thead` wie an `tr`.

> **Eine Zahl, die nur nach einem Klick auftritt, ist eine Aussage über den
> Klick und nicht über die Seite.**

Das ist derselbe Fund wie beim Breitenwechsel aus §2, eine Stufe allgemeiner:
Nicht nur ein Wechsel der Breite trägt Reste mit, sondern **jede** Lage, die
ohne Neuladen entstanden ist.

**Das ist eine Vermutung mit vier Messwerten und noch keine Erklärung** — warum
eine Kopfzeile aus fünf Wörtern nach einem Klick 27 px breiter misst, ist offen.
Der Prüfschritt dafür steht in §4.4 und kostet drei Messungen.

**Für das Kriterium bleibt es gleichgültig** — `dokument` ist in allen Lagen 0,
und `.stacks thead` ist der Mechanismus und kein Fund. Für das *Messmittel* ist
es das nicht.

#### Befund 12 — die Auskunft über das volle Kontingent steht dort, wo niemand hinkommt (390 px)

Gemeldet vom Betreiber am Bild. `dokument` ist 0, es gibt nichts zu messen.

Die Meldung „Das Kontingent dieses Plans ist ausgeschöpft" steht **im Bereich
„Job anlegen"**, also im dritten von drei Bereichen. Bei 1440 px ist das
richtig: Der Bereich liegt unter der Jobliste und die Meldung oben in ihm, beides
im Bild. Bei 390 px stapeln sich die drei Bereiche, die Jobliste dazwischen ist
zehn Kärtchen hoch — und die Meldung liegt damit **hinter der ganzen Liste**.

Wer wissen will, warum er keinen Job anlegen kann, erfährt es an der Stelle, an
der er es nicht mehr braucht: Er ist dort erst, wenn er ohnehin schon scrollt.

> **Eine Auskunft, die erklärt, warum etwas nicht geht, gehört dorthin, wo man
> es versucht — nicht dorthin, wo es scheitert.**

Bemerkenswert daran ist die Bauart: Die Meldung sitzt **richtig** — direkt über
dem Formular, auf das sie sich bezieht. Falsch ist nicht ihr Ort im Bereich,
sondern dass der Bereich bei 390 px unerreichbar weit unten liegt. Eine
Behebung, die sie nur nach oben schiebt, nimmt ihr den Bezug.

#### Befund 13 — es gibt bei 390 px keinen Weg zum Formular ausser Scrollen

Gemeldet vom Betreiber, ebenfalls ohne Zahl. Der Bereich „Job anlegen" ist bei
390 px nur zu erreichen, indem man an zehn Kärtchen vorbeirollt. Einen Griff, der
dorthin führt, gibt es nicht.

**Das ist dieselbe Familie wie Befund 10 und wie `docs/55` Befund 8**, und damit
das dritte Mal:

| | wo | was fehlte |
|---|---|---|
| `docs/55` Befund 8 | Dateimanager | der Menüpunkt lag drei Klicks tief |
| `docs/59` Befund 19 | SFTP-Zugang | derselbe Fehler, dasselbe Merkmal |
| `docs/64` Befund 10 | Rechte, Umbenennen, Cron ändern | der geöffnete Bereich stand ausserhalb des Bildes |
| **Befund 13** | Cron, Job anlegen | der Bereich ist da und niemand findet ihn |

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.**

Der Satz steht seit `docs/59` im Projekt. Er ist hier zum zweiten Mal
zugeschlagen, und das heisst: **Die Behebung ist diesmal die Regel und nicht die
Stelle.** `RevealTest` prüft bisher nur den umgekehrten Weg — ein Griff, der
einen Bereich öffnet, holt ihn ins Bild. Dass ein Bereich, den man *nicht*
öffnen muss, überhaupt erreichbar ist, prüft nichts.

#### Befund 14 — der Bereich „Job anlegen" steht bei 1440 px lose da

Gemeldet vom Betreiber. Vier Gruppen liegen als 2×2 nebeneinander:
Beschriftung / Befehl, darunter Schnellwahl / Zeitplan. Die Schnellwahl ist
sechs Knöpfe hoch, der Zeitplan mit fünf Feldern und Erklärung deutlich höher —
unter der Schnellwahl steht damit eine grosse leere Fläche, und die vier Gruppen
lesen sich nicht als ein Formular, sondern als vier Kästen.

Das ist kein Überlauf und kein Kontrastfehler; es ist die Anordnung selbst. Eine
Zahl gibt es nicht — nur einen Betrachter.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

**Was hier noch nicht entschieden ist:** wie die Anordnung stattdessen aussieht.
Das gehört gemessen und nicht geraten — der Aufsatz im Container trifft die
echte Seite aufs Pixel, und die Frage „welche von zwei Anordnungen ist ruhiger"
lässt sich an Höhen und Fugen zeigen. Entschieden wird sie beim Beheben, nicht
hier.

#### Ansicht 9 — Läufe, Teil 1: Job A mit Ausgabe (`/subscriptions/140/cron/8/runs`)

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` **304** · `tr` **276** | leer |
| 390 dunkel | 0 | **200/200** | `thead` **304** · `tr` **276** | leer |
| 1440 hell | 0 | **200/200** | leer | `.scrolls` **17**, `darf: true` |
| 1440 dunkel | 0 | **200/200** | leer | `.scrolls` **17**, `darf: true` |

**Die Befunde 7 und 8 sind im Bild erledigt.** Der Ausgabekasten hat unten Luft
zur Trennlinie, die Marke „erfolgreich" steht frei zwischen den Linien. Beide
hingen an derselben Zeile — `td` ohne senkrechte Polsterung —, und beide sind
mit `padding: 6px 14px` weg.

#### Die 2 px am Roller sind die Dichtestufe, und zwar auf den Pixel ausgerechnet

Im ersten Lauf rollte dieselbe Laufliste bei 1440 px um **19** px, jetzt um
**17**. Zwei Pixel weniger, in beiden Themes, bei unverändertem Inhalt.

Das ist **Befund 6**, und die Rechnung geht auf: `.sections` ist eine Flexbox
mit `gap: var(--bereich-gap)`, und die Dichtestufe `customer` ist von
`38px 52px` auf `34px 48px` gegangen — der **Spaltenabstand** also um 4 px
kleiner. Beide Bereiche dieser Seite sind gewöhnliche `.section` mit
`flex: 1 1 var(--bereich-min)`, teilen sich den frei gewordenen Platz also zu
gleichen Teilen: **2 px je Spalte.** Genau um diese 2 px rollt die Tabelle
weniger.

> **Eine Marke, die für den senkrechten Abstand geändert wurde, taucht waagerecht
> wieder auf — und die Zahl sagt, ob sie angekommen ist.**

Das ist kein Fund, sondern eine Quittung: Ohne diese 2 px wäre offen, ob die
Dichtestufe auf dem Server überhaupt greift. Bei 390 px ist sie folgerichtig
**nicht** zu sehen — dort steht nur ein Bereich je Reihe, und ein Spaltenabstand
ohne zweite Spalte wirkt nicht. `thead` 304 und `tr` 276 stehen deshalb
unverändert.

---

### 4.2 Die Zustände

*(noch nichts gemessen — die Liste steht in `docs/63 §3`)*

### 4.3 Die fünf Griffe der Experteneingabe

*(noch nichts gefahren — die Vorschrift steht in `docs/63 §6b`)*

| | Griff | erwartet | gemessen |
|---|---|---|---|
| 1 | Kästchen ankreuzen | Feld zeigt `* * * * *` | — |
| 2 | `*/15 9-17 * * 1-5`, Kästchen ab | fünf Felder tragen die Teile | — |
| 3 | Schnellwahl bei angekreuztem Kästchen | Feld zeigt `0 9 * * 1-5` | — |
| 4 | `* * *` anlegen | Abweisung, Satz oben | — |
| 5 | `*/15 * * * *` anlegen | Job steht mit genau diesem Ausdruck | — |

### 4.4 Die offene Frage aus der ersten Runde

**Die 27 px an `.stacks thead` der Cronseite.** Der Stand nach Ansicht 8 steht
in §4.1: Der Bestand erklärt sie nicht — derselbe Zustand „Kontingent voll"
misst frisch geladen 484 und nach zehn Klicks 511. Was bleibt, ist der Weg zur
Seite.

**Der Prüfschritt dafür, drei Messungen bei 390 px in einem Theme:**

| | Handgriff | was er beantwortet |
|---|---|---|
| a | Seite neu laden, messen | der Ausgangswert — erwartet 484 |
| b | „Ändern" an einem Job drücken, **ohne** Neuladen messen | tritt die 511 nach einem Klick auf? |
| c | „Abbrechen" drücken, wieder messen | geht sie mit dem Formular wieder weg — oder bleibt sie? |

Bleibt die Zahl in (c) bei 511, ist der Klick die Ursache und nicht das
geöffnete Formular; fällt sie auf 484 zurück, hängt sie am Formular. Beides ist
ein Befund am Messmittel und keiner am Panel — aber ein benannter statt eines
offenen.

> **Zwei verschiedene Zahlen für denselben Gegenstand sind ein Befund am
> Messmittel, bis eine von beiden erklärt ist.**

Die Vermutung aus dem ersten Lauf — die Schrift sei noch nicht geladen gewesen —
wird damit unwahrscheinlicher, aber nicht widerlegt: Ein Klick lädt keine
Schrift nach. Sie steht weiter als Vermutung da und nicht als Erklärung.
