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

**Damit war Schritt 12 nach diesem Lauf noch nicht abgeschlossen.** Die zweite
Runde hat ihn zu Ende gemessen, und der Abschlusslauf `docs/68`/`docs/69` hat die
vier letzten Reste geschlossen: **P6 ist am 21. August 2026 abgenommen**, gegen
`v0.6.0-rc.24`.

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
| 1 | Dateien, Auswahl | **0** | **0** | **0** | **0** |
| 2 | Dateimanager | **0** | **0** | **0** | **0** |
| 3 | Editor | **0** | **0** | **0** | **0** |
| 4 | Suche | **0** | **0** | **0** | **0** |
| 5 | SFTP, Auswahl | **0** | **0** | **0** | **0** |
| 6 | SFTP-Zugang | **0** | **0** | **0** | **0** |
| 7 | Cron, Auswahl | **0** | **0** | **0** | **0** |
| 8 | Cronjobs | **0** | **0** | **0** | **0** |
| 9 | Läufe | **0** | **0** | **0** | **0** |

#### Ansicht 1 — Dateien, Auswahl (`/files`)

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` **119** · `tr` **91** | leer |
| 390 dunkel | 0 | **200/200** | `thead` **119** · `tr` **91** | leer |
| 1440 hell | 0 | **200/200** | **leer** | leer |
| 1440 dunkel | 0 | **200/200** | **leer** | leer |

#### Der Fremdeintrag ist weg — alle vier, und das schliesst die 468 px

Im ersten Lauf trug **jede** der vier Lagen dieser Ansicht einen Eintrag der
Erweiterung LastPass in `schiebt`, und bei 1440 px war er der einzige. Jetzt
steht dort nichts mehr: bei 390 px nur noch `.stacks thead` und sein `tr`, bei
1440 px beide Listen leer.

Damit ist der teuerste Irrtum dieses Laufs **gemessen** erledigt und nicht nur
begründet. Der `div` mit 468 px ist in diesem Protokoll zweimal falsch erklärt
worden — erst als Rest eines Breitenwechsels, dann als rechte Hälfte von
`.split`, mit Zeilennummer. Beide Male war die Erklärung plausibel, und beide
Male gehörte der Kasten gar nicht zur Seite.

> **Eine Messung am Dokument misst auch, was der Browser hineingeschrieben
> hat.**

Die Vorschrift „gemessen wird in einem Fenster ohne Erweiterungen" (`docs/63
§5`) steht seit dem ersten Lauf da. Dies ist der Beleg, dass sie wirkt: vier
Lagen, vier Einträge weniger, sonst dieselbe Seite.

**Die 119 px sind der Mechanismus**, hier so niedrig, weil diese Tabelle nur
eine einzige Spaltenüberschrift trägt („Abonnement"). Beide Themes liefern
dieselben Zahlen — die Geometrie hängt nicht am Thema.

**Und die Quittung der Dichtestufe fehlt hier folgerichtig**: Bei 1440 px rollt
nichts, weil die Seite zwei Zeilen Inhalt hat. Was der engere Spaltenabstand
schenkt, ist nur an einer Tabelle abzulesen, die überläuft.

#### Ansicht 2 — Dateimanager (`/subscriptions/140/files`)

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` **350** · `tr` **322** | leer |
| 390 dunkel | 0 | **200/200** | `thead` **350** · `tr` **322** | leer |
| 1440 hell | 0 | **200/200** | **leer** | leer |
| 1440 dunkel | 0 | **200/200** | **leer** | leer |

Dieselben Zahlen wie im ersten Lauf, und wieder ohne einen einzigen
Fremdeintrag. Bei 1440 px rollt nichts — die Quittung der Dichtestufe ist hier
also erneut nicht ablesbar, wie schon bei Ansicht 1 und 9 Teil 2.

**Und der Prüfkörper aus `docs/46 §20.11` liegt im Bestand und ist im Bild.**
Der lange Verzeichnisname steht im Baum und bricht dort über acht Zeilen — genau
das, wofür `overflow-wrap: anywhere` dasteht. `dokument` ist trotzdem 0. Die
Regel, die im ersten Lauf von P5c 99 px Überlauf verhindert hat, hält auf dem
echten Server.

> **Ein Prüfkörper, der im Bestand liegen bleibt, misst bei jeder Runde mit —
> und das ist der Grund, ihn nicht aufzuräumen.**

#### Was die Bilder nebenbei zeigen: sieben Meldungen im Register „Issues"

**Bei 1440 px steht in den Entwicklerwerkzeugen `7 Issues`, bei 390 px `No
Issues`** — auf allen bisher gemessenen Ansichten stand dort nichts. Das ist
keine Zahl aus `bilderMessen()` und kein Fund; es ist eine Auskunft, die der
Browser von sich aus anbietet und die dieser Lauf bisher nicht gelesen hat.

Was dort steht, ist unbekannt. Es kann eine Abkündigung sein, eine Sache mit
Formularfeldern, ein Bild ohne Grösse — oder nichts, was zählt. Aufgeschrieben
wird es, weil es sonst niemandem mehr auffällt.

> **Eine Auskunft, die man nicht liest, ist nicht dasselbe wie eine, die es
> nicht gibt.**

**Und gemessen am 20. August ist es weder das eine noch das andere: Das Register
sammelt über die Sitzung.** Auf der frisch geladenen Seite steht `No Issues`,
bei sonst gleicher Lage und gleicher Breite. Die sieben gehörten also zu allem,
was vorher in diesem Fenster passiert ist, und nicht zu dieser Ansicht.

> **Eine Zahl, die sich über die Sitzung ansammelt, gehört nicht zu der Seite,
> auf der man sie abliest.**

Das ist der dritte Fund derselben Bauart in dieser Runde — nach dem Kasten der
Erweiterung und dem Rest des Breitenwechsels. Alle drei stehen im Bild neben der
Seite und sehen aus, als gehörten sie dazu.

#### Befund 17 — zwei Elemente tragen die Kennung `app`

Die Gegenprobe zu den sieben Meldungen hat etwas anderes gefunden. Gemessen bei
1440 px auf `/subscriptions/140/files`:

```json
{"ohneNamen":[],"labelInsLeere":[],"doppelteKennung":["app"],"knopfOhneTyp":0}
```

Die ersten beiden Listen sind leer und die vierte 0 — jedes Eingabefeld hat
`id` oder `name`, keine Beschriftung zeigt ins Leere, kein Knopf in einem
Formular ist ohne `type`. **Die dritte nicht:** Mindestens zwei Elemente tragen
`id="app"`.

Eine Kennung ist im Dokument einmalig, sonst ist sie keine. Was daran hängt,
sucht sich das erste Element und findet je nach Weg ein anderes:
`document.getElementById`, ein `label[for]`, ein `aria-labelledby`, ein
Sprungziel `#app`.

**Im Quelltext gibt es sie genau einmal.** `resources/views/app.blade.php` hat
im Rumpf nur `@inertia`, und die Direktive setzt das eine `<div id="app">`; in
keiner `.vue`-Vorlage steht `id="app"`. Woher das zweite kommt, ist **nicht
geklärt**.

> **Eine Kennung, die es im Quelltext einmal gibt und im Dokument zweimal, ist
> nicht dort entstanden, wo man sie sucht.**

**Gemessen, und die Ursache steht im Quelltext.** Die beiden Elemente:

```
DIV | — | <div id="app" data-v-app=""><!----><div data-v-7b870e66="" class="frame">…
DIV | — | <div id="app"></div>
```

Eines trägt die ganze Anwendung, das andere ist **leer**. Und
`resources/views/app.blade.php` hat `@inertia` **zweimal**:

```blade
    @vite('resources/js/app.ts')
    @inertia          {{-- Zeile 123, im <head> --}}
</head>
<body>
@inertia              {{-- Zeile 126, im <body> --}}
</body>
```

**In den Kopf gehört `@inertiaHead`, nicht `@inertia`.** Die beiden Direktiven
sehen sich ähnlich und tun Entgegengesetztes: Die eine setzt Kopfzeilen, die
andere das Wurzelelement der Anwendung.

**Und die Folge ist grösser als eine doppelte Kennung.** Ein `<div>` ist im
`<head>` nicht erlaubt; der Parser schliesst den Kopf an dieser Stelle und
beginnt den Rumpf. Die Anwendung hängt damit in dem Element, das nie eines sein
sollte — `getElementById` liefert das erste, und das ist das aus dem Kopf. Das
`<div>` aus dem Rumpf, also das gemeinte, bleibt leer stehen.

**Es funktioniert trotzdem, und zwar durch Zufall:** Die falsche Zeile ist die
**letzte** vor `</head>`. Stünde nach ihr noch ein `<link>` oder ein `<meta>`,
läge das im Rumpf und wäre wirkungslos — Favicon, Manifest, Farbschema.

> **Ein Fehler, der nur deshalb nichts kaputt macht, weil er an der letzten
> Stelle steht, ist kein kleiner Fehler — er ist einer mit Glück.**

**Und `<Head>` wird wirklich gebraucht:** Zehn Seiten binden die Komponente ein
(`Databases/Show.vue`, `Console.vue`, `Plans/Form.vue` und weitere). Die
Direktive im Kopf ist also nicht überflüssig, sondern **falsch geschrieben**.

**Kein Wächter sieht auf diese Datei** — `ThemeTest` und `IconTest` lesen sie,
aber für andere Fragen. Die Behebung bekommt einen.

#### Ansicht 3 — Editor (`/subscriptions/140/files/edit?path=/httpdocs/klein.txt`)

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | leer | leer |
| 390 dunkel | 0 | **200/200** | leer | leer |
| 1440 hell | 0 | **200/200** | leer | leer |
| 1440 dunkel | 0 | **200/200** | leer | leer |

**Vier Lagen, vier gültige Gegenproben, kein einziger Eintrag** — Wort für Wort
dasselbe Bild wie im ersten Lauf. Diese Seite hat keine `.stacks`-Tabelle,
deshalb fehlt auch der Mechanismus, der sonst überall in `schiebt` steht.

Die Datei trägt eine Zeile mit Umlauten („Grüsse aus Köln"), und sie steht
richtig da — die Kodierung reist unverändert durch Agent, Panel und Editor.

#### Ansicht 4 — Suche (`/subscriptions/140/files/search?path=%2F&query=eins`)

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` **157** · `tr` **129** | leer |
| 390 dunkel | 0 | **200/200** | `thead` **157** · `tr` **129** | leer |
| 1440 hell | 0 | **200/200** | leer | leer |
| 1440 dunkel | 0 | **200/200** | leer | leer |

**Aufgenommen im Zustand „kein Treffer".** `?query=eins` unter `/` findet in
diesem Bestand nichts mehr — 20 angesehene Einträge, „Nichts gefunden". Die
Zahlen stimmen trotzdem mit dem ersten Lauf überein, weil die Kopfzeile
(„Datei", „Fundstelle") dieselbe ist, ob darunter Zeilen stehen oder nicht.

**Und genau darin liegt eine Grenze dieser Messung.** Was hier gemessen ist, ist
die Geometrie der leeren Tabelle. Eine Trefferliste trägt Pfade in der Spalte
„Datei", und die Breite einer Tabelle hängt an ihrem längsten Wert und nicht an
ihrer Zeilenzahl — der Satz steht seit dem Kontingent in diesem Protokoll. Der
Zustand „gekürzt" aus `docs/63 §3` ist die Lage, die das prüft, und er ist in
dieser Runde noch nicht gefahren.

> **Zwei Zustände, die dieselbe Zahl liefern, sind nicht derselbe Zustand — sie
> haben nur dieselbe Kopfzeile.**

**Der `div` mit 468 px ist weg** — und das ist die Lage, auf die es ankommt.
Bei Ansicht 2 war er ein Rest des Breitenwechsels, hier stand er **frisch
geladen** da, und genau daran ist die zweite Erklärung („die rechte Hälfte von
`.split`") zerbrochen: `Files/Search.vue` hat gar kein `.split`. Im Fenster ohne
Erweiterungen ist er nicht mehr da. Damit hält die dritte Erklärung auch dort,
wo die beiden ersten gescheitert sind.

**Befund 1 ist auf dem echten Server bestätigt, auf den Pixel.** Gemessen bei
390 px:

```json
{"kaestchen":[17,17],"leiste":171,"klasse":"toggle"}
```

Im Container waren es **17 × 17** und **171** — dieselben drei Zahlen. Vorher
war das Kästchen **390 × 44** und die Leiste **207**.

> **Ein Aufsatz, der das echte Markup und das gebaute Stylesheet benutzt, misst
> die echte Seite — und nicht etwas Ähnliches.**

Der Satz steht seit `docs/56` im Projekt und hat hier zum zweiten Mal geliefert.

#### Eine Beobachtung, die kein Befund ist: 57 Felder ohne `id` und ohne `name`

Das Register „Issues" meldet auf dieser Seite genau einen Eintrag, und dieser
gehört wirklich der Seite: *„A form field element should have an id or name
attribute."* Gemeint ist das Kästchen „auch im Inhalt".

**Es ist nicht durch die Behebung von Befund 1 entstanden.** Die Fassung davor
hatte dasselbe `<input v-model="imInhalt" type="checkbox" />`; getauscht wurde
nur das Label darum.

Gemessen über alle Vorlagen: **57 Felder** in rund zwei Dutzend Dateien tragen
weder `id` noch `name`.

**Und trotzdem wird das hier nicht als Befund geführt**, denn es folgt daraus
nichts, was das Panel heute falsch machen würde:

| Frage | Antwort |
|---|---|
| Kommt der Wert an? | Ja — die Formulare senden über Inertia mit `v-model`, nicht als HTML-Formular. `name` trägt hier nichts. |
| Hat das Feld eine Beschriftung? | Ja — es steht **in** seinem `<label>`, und das verbindet ohne `for`/`id`. |
| Zeigt eine Fehlermeldung darauf? | Nein — keine Zusammenfassung im Panel verlinkt ein Feld; es gibt kein `href="#feld"` und kein `aria-describedby`. |

Was der Browser meldet, ist eine Sache des Ausfüllhelfers und der
Formularwiederherstellung, nicht der Richtigkeit.

> **Eine Meldung des Browsers ist ein Hinweis auf eine Gewohnheit, nicht auf
> einen Fehler — welche von beiden es ist, entscheidet die Frage, was davon
> abhängt.**

**Der Grund, es dennoch aufzuschreiben:** Sobald eine Fehlerzusammenfassung
einmal auf ihr Feld zeigen soll — und `docs/19 §6` liegt nicht weit davon
entfernt —, sind es 57 Stellen und nicht eine. Wer das baut, fängt hier an.

#### Ansicht 5 — SFTP, Auswahl (`/sftp`)

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` **119** · `tr` **91** | leer |
| 390 dunkel | 0 | **200/200** | `thead` **119** · `tr` **91** | leer |
| 1440 hell | 0 | **200/200** | leer | leer |
| 1440 dunkel | 0 | **200/200** | leer | leer |

Zahl für Zahl dieselbe Ansicht wie im ersten Lauf und wie Ansicht 1 — es ist
dieselbe Tabelle mit einer Spaltenüberschrift, nur ohne das `.split` des
Dateimanagers.

**Fünf Ansichten ohne einen einzigen Fremdeintrag.** Im ersten Lauf war das eine
Auszeichnung dieser einen Seite; in dieser Runde ist es der Normalfall.

#### Ansicht 6 — SFTP-Zugang (`/subscriptions/140/sftp`), **ohne Schlüssel**

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` **342** · `tr` **314** | leer |
| 390 dunkel | 0 | **200/200** | `thead` **342** · `tr` **314** | leer |
| 1440 hell | 0 | **200/200** | leer | **leer** |
| 1440 dunkel | 0 | **200/200** | leer | **leer** |

¹ **Gemessen ist hier nicht der Grundzustand, sondern der Zustand „ohne
Schlüssel".** Im Bestand steht „Kein Schlüssel eingetragen"; die Schlüsseltabelle
hat eine Kopfzeile und keine Zeile. Der Grundzustand mit Schlüssel ist in dieser
Runde **nicht** gemessen.

Bei 390 px ändert das nichts — `thead` 342 und `tr` 314 sind die
Spaltenüberschriften und stehen unverändert. Bei 1440 px ändert es alles.

#### Der Grundzustand, mit Schlüssel — und die Vorhersage steht auf dem Pixel

Nachgeholt am selben Tag: ein Schlüssel eingetragen („test", `ssh-ed25519`
256 Bit), dann dieselben vier Lagen.

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` **342** · `tr` **314** | leer |
| 390 dunkel | 0 | **200/200** | `thead` **342** · `tr` **314** | leer |
| 1440 hell | 0 | **200/200** | leer | `.scrolls` **215**, `darf: true` |
| 1440 dunkel | 0 | **200/200** | leer | `.scrolls` **215**, `darf: true` |

**Im ersten Lauf waren es 217. Vorhergesagt waren „rund 215". Gemessen sind
215.**

Die Rechnung dahinter, aufgestellt an Ansicht 9 Teil 1 und hier zum zweiten Mal
geprüft: `.sections` ist eine Flexbox mit `gap: var(--bereich-gap)`; die
Dichtestufe `customer` ist von `38px 52px` auf `34px 48px` gegangen, der
Spaltenabstand also um 4 px kleiner. Zwei gewöhnliche `.section` mit gleicher
Basis teilen sich den frei gewordenen Platz — 2 px je Spalte, und um genau die
rollt eine überlaufende Tabelle weniger.

**Was diese zweite Messung wert ist, liegt an ihrer Grösse.** Bei Ansicht 9
ging es um 19 → 17 px; zwei Pixel Unterschied bei zwei Pixeln Erwartung sind
schwer von einem Zufall zu trennen. Hier sind es 217 → 215 an einer Zahl, die
zehnmal so gross ist, und die Vorhersage stand **vor** der Messung im Protokoll.

> **Eine Rechnung, die vor der Messung aufgeschrieben wird, ist eine Prüfung.
> Dieselbe Rechnung danach ist eine Erklärung.**

Das ist der Unterschied zu den beiden Irrtümern dieses Laufs — dem Kasten mit
468 px und den 27 px am Klick. Beide waren Erklärungen für Messwerte, die schon
dastanden. Diese hier war eine Behauptung über eine Zahl, die es noch nicht gab.

#### Der Zwischenstand davor: ohne Schlüssel war die Vorhersage nicht prüfbar

Vor der Messung stand hier: Der Roller müsste von **217** auf rund **215** px
gehen, weil der engere Spaltenabstand der Dichtestufe jeder Hälfte 2 px
schenkt — dieselbe Rechnung, die bei Ansicht 9 Teil 1 aufging.

Gemessen ist stattdessen: **`rollt` ist leer.**

Das ist kein Gegenbeweis, sondern ein anderer Gegenstand. Die 217 px des ersten
Laufs entstanden an einem `SHA256:`-Fingerabdruck, der neben Bezeichnung, Art
und Aktion keinen Platz hat. Ohne Schlüssel gibt es keinen Fingerabdruck, und
eine Tabelle aus vier Überschriften passt in ihre Hälfte.

Das Protokoll kennt den Satz dazu schon aus dem ersten Lauf (§1b): **Der Roller
kommt und geht mit dem Inhalt, nicht mit dem Bestand.** Hier ist er gegangen.

> **Eine Vorhersage, die einen anderen Zustand antrifft als den vorhergesagten,
> ist weder bestätigt noch widerlegt — sie ist ungeprüft, und das ist ein
> dritter Ausgang.**

Der Satz steht hier, weil er der Grund war, den Grundzustand nachzuholen statt
den Zwischenstand als Antwort zu nehmen. **Nachgeholt ist er, und die Zahl steht
oben: 215.**

**Und das Register meldet hier zwei Einträge**, beide der Sorte aus Ansicht 4:
die Felder „Bezeichnung" und „Öffentlicher Schlüssel" tragen weder `id` noch
`name`. Zwei der 57 — dieselbe Beobachtung, kein neuer Fund.

#### Ansicht 7 — Cron, Auswahl (`/cron`)

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` **119** · `tr` **91** | leer |
| 390 dunkel | 0 | **200/200** | `thead` **119** · `tr` **91** | leer |
| 1440 hell | 0 | **200/200** | leer | leer |
| 1440 dunkel | 0 | **200/200** | leer | leer |

Dieselben vier Zeilen wie Ansicht 1 und Ansicht 5 — drei Controller, drei
Vorlagen, dieselben Zahlen. Dass sie gleich aussehen **sollen**, ist der Grund,
alle drei zu messen und nicht eine.

#### Damit sind die neun Ansichten vollständig

| # | Ansicht | 390 | 1440 |
|---|---|---|---|
| 1 | Dateien, Auswahl | `thead` 119 · `tr` 91 | — |
| 2 | Dateimanager | `thead` 350 · `tr` 322 | — |
| 3 | Editor | — | — |
| 4 | Suche | `thead` 157 · `tr` 129 | — |
| 5 | SFTP, Auswahl | `thead` 119 · `tr` 91 | — |
| 6 | SFTP-Zugang | `thead` 342 · `tr` 314 | `.scrolls` **215** |
| 7 | Cron, Auswahl | `thead` 119 · `tr` 91 | — |
| 8 | Cronjobs | `thead` 484 · `tr` 456 | `.scrolls` 248 / 250 |
| 9 | Läufe (Job A) | `thead` 304 · `tr` 276 | `.scrolls` **17** |
| 9 | Läufe (Job B) | `thead` 304 · `tr` 276 | — |

**Sechsunddreissig Lagen, sechsunddreissig mal `dokument: 0`, sechsunddreissig
gültige Gegenproben.** Jeder Eintrag unter `schiebt` ist `.stacks thead` mit
seinem `tr`, also der Mechanismus aus `app.css`; jeder Eintrag unter `rollt`
trägt `darf: true`.

**Und kein einziger Fremdeintrag.** Im ersten Lauf trugen sieben von neun
Ansichten einen Kasten der Erweiterung LastPass, zweimal falsch erklärt und
einmal mit Zeilennummer. Das Fenster ohne Erweiterungen hat sie alle entfernt,
ohne eine einzige Zahl der Seite zu ändern.

**Die Behebungen der ersten Runde sind auf dem Server angekommen**, und zwei
davon mit einer Zahl: das Kästchen aus Befund 1 mit `17 × 17` und `171`
(Container: dieselben drei Zahlen), und die Dichtestufe aus Befund 6 an zwei
Rollern — 19 → 17 bei Ansicht 9 und **217 → 215** bei Ansicht 6, letzteres
vorhergesagt, bevor es gemessen war.

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

**Der Bestand dieser Aufnahme hat am 20. August aufgehört zu existieren.** Für
Griff 4 und 5 der Experteneingabe musste ein Platz frei werden; **Job J ist
gelöscht**, und anschliessend legt Griff 5 einen neuen mit `*/15 * * * *` an. Wer
Ansicht 8 später noch einmal aufruft, sieht also nicht mehr dieselbe Seite —
zwischendurch 9 von 10 und **ohne** die Meldung über das volle Kontingent, danach
wieder 10 von 10 mit einem anderen Job darin.

Die vier Zeilen oben gelten für den Zustand, unter dem sie entstanden sind, und
das ist der Grund, dass er hier steht.

> **Eine Messung ohne ihren Bestand ist beim nächsten Ansehen nicht falsch —
> sie ist unvergleichbar.**

**Die vier Fugen aus den Befunden 2, 3 und 4 sind im Bild da**, die Meldung über
die Zeitzone steht mit Abstand unter dem Satz darüber, und `*/15` bricht im
Erklärungstext nicht mehr (Befund 5). Die Dichtestufe aus Befund 6 trägt: Die
Bereiche stehen enger als im ersten Lauf.

#### Die 27 px: drei Kandidaten gemessen, drei erledigt — und meine Folgerung dazwischen war falsch

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

Damit fällt die letzte Erklärung, die am Zustand hing: Zehn Jobs ergeben einmal
511 und einmal 484. Was die beiden 511er gemeinsam hatten, war, dass sie ohne
Neuladen entstanden sind — durch einen Klick.

**Diese Vermutung ist am selben Tag gemessen und widerlegt worden.** Der
Prüfschritt aus §4.4, dreimal bei 390 px auf derselben Seite:

| | Handgriff | `thead` · `tr` |
|---|---|---|
| a | frisch geladen | 484 · 456 |
| b | „Ändern" gedrückt, **kein** Neuladen | **484 · 456** |
| c | „Abbrechen" gedrückt | **484 · 456** |

Der Klick erzeugt die 27 px nicht. Das offene Formular auch nicht.

**Damit steht die Zahl auf fünf Messungen 484 gegen `rc.19` und auf keiner
einzigen 511** — und drei benannte Kandidaten sind erledigt: der Bestand, der
Klick, das geöffnete Formular. Übrig ist der, der von Anfang an dastand und den
ich zwischendurch für unwahrscheinlich erklärt habe: **die Schrift.** 484 → 511
ist ein Zuwachs von 5,6 %, 456 → 483 einer von 5,9 % — die Grössenordnung, die
zwischen einer Ersatzschrift und der geladenen liegt. Bewiesen ist auch das
nicht; der Prüfschritt dafür steht in §4.4.

#### Und der Fehler dabei war meiner, zum zweiten Mal in derselben Runde

Ich habe „der Klick ist die Ursache" auf fünf Messwerte gestützt und als
Richtung ausgegeben. Drei weitere haben sie umgeworfen. Das ist derselbe Fehler
wie beim `div` mit 468 px, der in diesem Lauf **zweimal** falsch erklärt wurde,
bevor der Ort im Fund die Ursache nannte.

Und das Bittere daran: Der Satz dagegen steht seit dem ersten Lauf in genau
diesem Dokument, ein paar Abschnitte weiter oben.

> **Eine Erklärung, die zum dritten Messwert passt, ist keine — sie ist eine
> Erklärung für drei Messwerte.**

> **Ein Satz, den man selbst aufgeschrieben hat, schützt nicht davor, denselben
> Fehler noch einmal zu machen — er macht ihn nur teurer zu übersehen.**

Richtig gewesen wäre, was §4.4 ohnehin vorsah: erst messen, dann benennen. Die
Tabelle war die Messung; der Satz darunter war eine Vermutung im Gewand einer
Folgerung.

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

#### Ansicht 9 — Teil 2: Job B mit Rückgabewert 3 (`/subscriptions/140/cron/9/runs`)

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` **304** · `tr` **276** | leer |
| 390 dunkel | 0 | **200/200** | `thead` **304** · `tr` **276** | leer |
| 1440 hell | 0 | **200/200** | leer | **leer** |
| 1440 dunkel | 0 | **200/200** | leer | **leer** |

**Vier Zeilen, die dem ersten Lauf aufs Wort gleichen** — dort standen dieselben
304 · 276 und dieselben zwei leeren Lagen. Dass bei 1440 px nichts rollt, ist
der Unterschied zu Job A: Job B hat keine Ausgabe, in der Spalte steht „keine
Ausgabe", und damit passt die Tabelle in ihre Hälfte.

**Und die Quittung aus Teil 1 kann hier gar nicht erscheinen.** Die 2 px, die
der engere Spaltenabstand jeder Hälfte schenkt, sind nur an einer Tabelle
abzulesen, die überläuft. Diese läuft nicht über — sie hat vorher wie nachher
Platz.

> **Eine Änderung, die man an einem Überlauf misst, ist dort unsichtbar, wo
> nichts überläuft — und das ist kein Gegenbeweis.**

**Damit belegt an dieser Seite keine einzige Zahl die Behebung von Befund 8.**
Der Beleg ist das Bild: Die Marke „fehlgeschlagen" steht frei zwischen den
Linien statt an der oberen zu kleben, und „Rückgabewert 3" hat nach unten Luft.
Gemessen im Container waren es 1 → 7 px.

> **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite schiebt.
> Keines von beiden ersetzt das andere.**

---

### 4.2 Die Zustände

Die Liste steht in `docs/63 §3`. Drei sind bei den Ansichten nebenbei
mitgemessen worden und stehen dort: **„ohne Schlüssel"** (Ansicht 6),
**„kein Treffer"** (Ansicht 4) und **„Kontingent voll"** (Ansicht 8).

| Zustand | Lage | `dokument` | Gegenprobe | `schiebt` |
|---|---|---|---|---|
| Dateimanager — Mehrfachauswahl | 390 dunkel | 0 | **200/200** | `thead` **367** · `tr` **339** |
| Dateimanager — Verschieben, Zielbaum offen | 390 dunkel | 0 | **200/200** | `thead` **367** · `tr` **339** |
| Dateimanager — Packen, mit Namensfeld | 390 dunkel | 0 | **200/200** | `thead` **367** · `tr` **339** |
| Dateimanager — „Verzeichnis anlegen" offen | 390 dunkel | 0 | **200/200** | `thead` **367** · `tr` **339** |
| Dateimanager — „Datei anlegen" offen | 390 dunkel | 0 | **200/200** | `thead` **367** · `tr` **339** |
| Dateimanager — „Datei hochladen" offen | 390 dunkel | 0 | **200/200** | `thead` **367** · `tr` **339** |
| Dateimanager — „Suchen" offen | 390 dunkel | 0 | **200/200** | `thead` **367** · `tr` **339** |
| Dateimanager — „Rechte" an einer Zeile | 390 dunkel | 0 | **200/200** | `thead` **367** · `tr` **339** |
| Dateimanager — „Umbenennen" an einer Zeile | 390 dunkel | 0 | **200/200** | `thead` **367** · `tr` **339** |
| Dateimanager — langer Name in den Krümeln | 390 dunkel | 0 | **200/200** | `thead` **350** · `tr` **322** |
| Editor — „zu gross" (`gross.bin`) | 390 dunkel | 0 | **200/200** | leer |
| Editor — „binär" (`binaer.dat`) | 390 dunkel | 0 | **200/200** | leer |
| Editor — nur lesbar (`/conf/hinweis.txt`) | 390 dunkel | 0 | **200/200** | leer, `rollt`: `.cm-scroller` **189** |
| Cron — Formular „Ändern" offen | 390 dunkel | 0 | **200/200** | `thead` **484** · `tr` **456** |
| Cron — Zeitplan als Ausdruck | 390 dunkel | 0 | **200/200** | `thead` **484** · `tr` **456** |
| Cron — ohne Läufe (`/cron/16/runs`) | 390 dunkel | 0 | **200/200** | `thead` **304** · `tr` **276** |
| Suche — gekürzt (500 Treffer) | 390 dunkel | 0 | **200/200** | `thead` **157** · `tr` **129** |

#### Gekürzt — Befund 4 an seiner zweiten Stelle, und eine Zahl aus dem ersten Lauf fehlt

Die 520 Dateien sind neu angelegt worden; die Seite meldet **„angesehene
Einträge: 515"** und darunter „Der Suchlauf ist nicht zu Ende gelaufen." Der Lauf
ist also bei 500 Treffern stehen geblieben, **bevor** er alle 520 gesehen hatte —
genau wofür die Zahl 520 gewählt wurde.

> **Ein Prüfkörper, der die Grenze nur erreicht, belegt sie nicht — er muss
> darüber hinausgehen.**

**Befund 4 ist damit an beiden Stellen bestätigt.** Im ersten Lauf klebte die
gelbe Meldung am Satz darüber; jetzt steht die Fuge dazwischen. Die erste Stelle
war die Cronseite (Ansicht 8), diese hier die zweite.

**Und eine Zahl des ersten Laufs lässt sich nicht wiederherstellen.** Dort stand
dieser Zustand auf `thead` **166** · `tr` **138**, jetzt auf **157** · **129** —
neun Pixel weniger. Bemerkenswert daran ist, womit die neue Zahl übereinstimmt:
Sie ist **exakt** die des Zustands „kein Treffer" derselben Seite. Beide tragen
dieselbe Kopfzeile („Datei", „Fundstelle"), also ist das die stimmige Lesart.

Im ersten Lauf war sie es nicht: dort 157 ohne Treffer und 166 mit gekürzter
Liste, bei unveränderter Kopfzeile.

**Damit stehen zwei nicht wiederherstellbare Abweichungen aus dem ersten Lauf
nebeneinander, beide an `.stacks thead`, beide nach oben:**

| | erster Lauf | zweiter Lauf | Differenz |
|---|---|---|---|
| Cron, zwei Zustände | 511 | 484 | +27 |
| Suche, gekürzt | 166 | 157 | +9 |

**Ob sie eine gemeinsame Ursache haben, ist unbekannt, und hier steht keine.**
Dieser Abschnitt hat für die erste Abweichung zwei Vermutungen verbraucht, und
beide waren falsch. Festgehalten wird, was gilt: Die Zahlen des zweiten Laufs
sind untereinander stimmig, die des ersten sind es an zwei Stellen nicht, und
`dokument` war in jedem einzelnen Fall 0.

> **Zwei Abweichungen, die sich nicht wiederherstellen lassen, sind zusammen
> nicht mehr wert als einzeln — nur auffälliger.**

#### Und das Formular „Ändern" schliesst die 27 px

**Im ersten Lauf hat genau dieser Zustand 511 · 483 geliefert.** Er ist einer der
beiden, die den Verdacht auf den Klick gebracht haben. Jetzt liefert er
**484 · 456** — dieselben Zahlen wie die Seite ohne geöffnetes Formular.

Damit steht die Bilanz auf **sechs Messungen 484 gegen `rc.19`**, davon zwei in
genau den Zuständen, die im ersten Lauf 511 ergaben („Kontingent voll" und
„Ändern offen"). Kein benannter Kandidat ist übrig, und keiner der vier war es:
nicht der Bestand, nicht der Klick, nicht das Formular, nicht die Schrift.

Die 511 aus dem ersten Lauf bleibt damit **unerklärt und nicht herstellbar**.
Sie steht als Notiz da und nicht als offener Punkt — so, wie §4.4 es vorsieht.

**Der Zustand „Zeitplan als Ausdruck" ist nebenbei die sechste Bestätigung für
Wunsch 1.** Bearbeitet wird ein Job mit „am 1. jedes Monats um 05:00", und im
Ausdrucksfeld steht `0 5 1 * *` — die Sicht liest die fünf Felder auch dann
richtig, wenn sie nicht leer sind, sondern aus einem gespeicherten Job kommen.

**„Ohne Läufe"** zeigt den Satz „Für diesen Job ist noch kein Lauf aufgezeichnet.
Läufe werden alle fünf Minuten eingesammelt." Die Zahlen sind die der Laufseite
(304 · 276) — dieselbe Kopfzeile, kein Eintrag darunter.

#### Der lange Verzeichnisname in den Krümeln — und eine Zahl, die eine Erklärung prüft

Der Name steht in der Krümelzeile unter „Abo-Wurzel / httpdocs /" und bricht dort
über **fünf Zeilen**. `dokument` bleibt 0.

Damit ist der Prüfkörper aus `docs/46 §20.11` an **beiden** Stellen gemessen: im
Baum (acht Zeilen, Ansicht 2) und im Fliesstext der Krümel. Die zweite ist die
gefährlichere — in P4 hat ein Bezeichner im Fliesstext einmal 83 px aus dem Bild
geschoben, und daraus ist die Regel entstanden.

**Und die Tabelle steht hier auf 350 · 322 statt auf 367 · 339.** Das ist keine
Abweichung, sondern die Bestätigung der Erklärung von vorhin: In diesem
Verzeichnis liegt nichts ausser dem Weg eine Ebene höher, es gibt also keinen
auswählbaren Eintrag — und ohne einen solchen trägt die Kopfzeile kein
„Alle auswählen"-Kästchen. Genau die 17 px, die bei der Mehrfachauswahl
dazukamen, fehlen hier wieder.

> **Eine Erklärung, die an einer dritten Messung dieselbe Zahl vorhersagt, ist
> keine Erzählung mehr.**

**Damit sind alle Zustände des Dateimanagers gemessen** — sieben Lagen, alle mit
`dokument: 0` und gültiger Gegenprobe.

#### Die drei Editor-Zustände — und der erste fremde Roller dieser Runde

„Zu gross" und „binär" zeigen beide nur eine Meldung und kein Feld; `schiebt`
und `rollt` sind leer. Der dritte ist der interessante.

**Bei der nur lesbaren Datei rollt `.cm-scroller` um 189 px**, mit `darf: true`.
Das ist der Rollbehälter von CodeMirror — der erste Eintrag dieser Runde, der
keinem Baustein aus `app.css` gehört. Bei Ansicht 3 (`klein.txt`) war `rollt`
leer; der Unterschied ist die Zeile selbst: „Diese Datei gehört root. Der Kunde
darf sie …" ist länger als das Feld breit ist.

Dass die Messung ihn richtig einordnet, liegt daran, dass sie nach dem
**berechneten Stil** fragt und nicht nach einer Liste bekannter Klassen. Ein
Behälter mit `overflow-x: auto` darf rollen, gleich wer ihn gebaut hat.

> **Eine Messung, die nach der Eigenschaft fragt statt nach dem Namen, kennt
> auch, was sie nicht kennt.**

**Und der Knopf „Speichern" steht dort abgeschaltet und nicht nur blass.**
`Files/Edit.vue` bindet ihn an
`!props.can.edit || !(props.entry?.writable ?? false)`. Der Satz daneben —
„sie lässt sich lesen und nicht ändern" — und der Zustand des Knopfes sagen
dasselbe. Nachgesehen, weil ein Knopf, der etwas verspricht, das er nicht kann,
genau die Sorte Fehler wäre, die dieser Lauf sucht.

#### Befund 10 ist auf dem echten Server bestätigt

Beide Formulare wurden von einer Zeile **weit unten** in der Liste geöffnet —
das ist die Bedingung, unter der der Fehler auftrat, und ohne sie misst man eine
Lage, die gar nicht scheitern kann.

| Griff | `oben` | `unten` | Fenster | ganz im Bild |
|---|---|---|---|---|
| Rechte | **117** | 727 | 844 | **ja** |
| Umbenennen | **307** | 536 | 844 | **ja** |

Vor der Behebung öffnete sich der Bereich am Kopf der Seite, während der Griff
weit unten sass — sichtbar war nichts, und es sah aus, als täte der Knopf nichts.
`bringIntoView` holt ihn jetzt ins Bild, und zwar ganz: `oben` ist in beiden
Fällen deutlich positiv, `unten` bleibt unter der Fensterhöhe.

**Das ist die vierte Behebung dieser Runde mit einer Zahl statt eines
Eindrucks** — nach 17 × 17 (Befund 1), 217 → 215 (Befund 6) und 0 statt −14
(Befund 9).

Die Formulare sind dabei unterschiedlich hoch: „Rechte" trägt neun Kästchen,
ein Oktalfeld, zwei Vorlagenknöpfe und vier Sätze Erklärung und misst 610 px;
„Umbenennen" hat ein Feld und zwei Knöpfe und misst 229. Beide passen.

**Damit sind alle vier Formulare der Kopfleiste gemessen.** Drei stehen bei
`476 – 602`, „Suchen" bei `476 – 656` — 54 px höher, weil es als einziges zwei
Knöpfe trägt („Suchen" und „Abbrechen"). Alle vier ganz im Bild, alle vier ohne
Überlauf, und die Tabelle darunter bleibt in jedem Fall bei 367 · 339.

**Beide Kopfleistenformulare stehen ganz im Bild**, mit der berichtigten Zeile
gemessen und in beiden Fällen derselbe Wert:

```json
{"oben":476,"unten":602,"fenster":844,"ganzImBild":true}
```

Oberkante 476, Unterkante 602, Fenster 844 — 242 px Luft nach unten. **Befund 18
gilt für die Kopfleiste nicht**, und das ist damit gemessen statt vermutet. Der
Unterschied zum Zielbaum ist die Richtung: Die Kopfleiste steht **unter** ihrem
Griff, der Zielbaum **über** seinem.

**Und das Hochladen beantwortet eine Frage, die keine unserer Regeln stellen
kann.** Das Feld ist ein `<input type="file">`; seinen Knopf und dessen
Beschriftung — hier „Dateien auswählen · Keine ausgewählt" — zeichnet der
Browser selbst, in seiner Sprache und in seiner Breite. `app.css` erreicht davon
nichts.

Gemessen bleibt er bei 390 px innerhalb der Feldbreite, `dokument` ist 0.

> **Ein Bedienelement, das der Browser zeichnet, hält sich an keine Marke — dass
> es passt, ist eine Messung und keine Zusage.**

Wörtlich heisst das: Diese Zeile gilt für Chromium in dieser Fassung und in
dieser Sprache. Ein Browser mit einer längeren Beschriftung ist ein anderer
Messwert, und niemand im Panel kann ihn kürzen.

**Befund 9 ist auf den Pixel bestätigt.** Der Versatz zwischen der Unterkante
des Knopfes „Packen" und der des Namensfeldes:

```json
{"versatz":0}
```

Im Container waren es vorher **−14** — der Knopf stand um genau diesen Betrag zu
hoch, weil `.selection .button-row` bei ≤ 480 px auf `align-items: center` stand
statt auf `flex-end`. Damit ist die dritte Behebung dieser Runde mit einer Zahl
belegt und nicht nur mit einem Eindruck: 17 × 17 (Befund 1), 217 → 215
(Befund 6), 0 statt −14 (Befund 9).

#### Mehrfachauswahl — und die Kopfzeile wächst um genau ein Kästchen

Aufgenommen unter `/httpdocs` mit drei angekreuzten Einträgen; die Auswahlleiste
trägt sechs Knöpfe („Kopieren", „Verschieben", „Als Zip packen", „Entfernen",
„Alle auswählen", „Auswahl aufheben") und bricht bei 390 px über vier Zeilen.
`dokument` bleibt 0.

**367 gegen 350 bei Ansicht 2** — dieselbe Tabelle, 17 px mehr. Das ist die
Spalte, die es im Wurzelverzeichnis nicht gibt: Dort gehört jeder Eintrag zum
Aufbau und ist nicht auswählbar, also trägt die Kopfzeile kein Kästchen. Unter
`/httpdocs` gibt es auswählbare Einträge, und `<th>` bekommt das
„Alle auswählen"-Kästchen.

**17 px ist genau die Breite, die Befund 1 auf dieser Seite gemessen hat.** Die
Zahl taucht hier zum zweiten Mal auf, an einer ganz anderen Stelle und aus einem
ganz anderen Grund — und sie stimmt.

> **Eine Zahl, die an zwei unabhängigen Stellen dieselbe ist, ist keine
> Übereinstimmung mehr, sondern eine Grösse.**

#### Verschieben — dieselben Zahlen, und ein Befund daneben

Der offene Zielbaum ändert an der Tabelle nichts: `thead` 367 und `tr` 339 wie
bei der Mehrfachauswahl, `dokument` 0. Der lange Verzeichnisname steht auch im
Zielbaum und bricht dort über zehn Zeilen, ohne zu schieben.

#### Befund 18 — der Zielbaum wird nicht ins Bild geholt (abgeschwächt durch die Messung)

**Belegt im Quelltext, noch nicht am Bild gemessen.**

`bringIntoView` hängt in `Files/Index.vue` an genau zwei Stellen: `chmodBlock`
und `renameBlock`. Der Zielbaum, den „Verschieben" und „Kopieren" öffnen, hat
keinen solchen Griff.

Und er steht **oberhalb**: `.split > .aside` ist die erste Hälfte, bei 390 px
also der obere Stapel. Der Knopf „Verschieben" sitzt in der Auswahlleiste, und
die gehört zur Liste — dem unteren Stapel. Wer ihn drückt, soll als Nächstes
etwas benutzen, das über ihm liegt, und die Seite bewegt sich nicht.

**Das ist der dritte Fall derselben Familie in dieser Runde:**

| | wo | was fehlt |
|---|---|---|
| Befund 10 | Rechte, Umbenennen, Cron ändern | **behoben** |
| Befund 13 | Cron, „Job anlegen" | der Bereich ist da und niemand findet ihn |
| **Befund 18** | Dateimanager, Zielbaum | der Griff öffnet etwas oberhalb seiner selbst |

**Und `RevealTest` konnte ihn nicht finden.** Der Wächter prüft die Griffe **an
einer Zeile** — „Rechte", „Umbenennen". „Verschieben" steht in der
Auswahlleiste und ist kein Zeilengriff, fällt also aus seinem Ausdruck heraus.

> **Ein Wächter, der eine Sorte Griff prüft, sagt über die andere Sorte
> nichts — und die zweite Sorte fällt niemandem auf, weil der Wächter grün
> ist.**

Damit gilt der Satz aus Befund 13 zum zweiten Mal in derselben Runde: Die
Behebung muss die Regel werden und nicht die Stelle. Drei Fälle sind kein
Zufall mehr.

**Die Messung dazu fehlt und kostet eine Zeile.** Direkt nach dem Drücken von
„Verschieben", ohne zu scrollen:

```js
(() => { const b = document.querySelector('.aside'), r = b?.getBoundingClientRect()
  return JSON.stringify(r ? { oben: Math.round(r.top), unten: Math.round(r.bottom),
    fenster: window.innerHeight, imBild: r.bottom > 0 && r.top < window.innerHeight } : 'nicht gefunden') })()
```

**Gemessen am 20. August:**

```json
{"oben":-98,"unten":315,"fenster":844,"imBild":true}
```

**Und das nimmt dem Befund die Hälfte.** Der Zielbaum ist **nicht** ausserhalb
des Bildes — sein unteres Ende steht bei 315 px, gut sichtbar. Abgeschnitten
sind die oberen **98 px**, und darin liegen die Wurzel „Abo-Wurzel" sowie
`.ssh`, `conf` und `httpdocs`.

Was bleibt, ist kleiner als hergeleitet und immer noch ein Fehler: Wer eine
Datei ins Wurzelverzeichnis oder nach `conf` verschieben will, muss nach oben
rollen, um das Ziel überhaupt zu sehen — und nichts sagt ihm, dass dort noch
etwas steht. Wer sie nach `logs`, `mail` oder `tmp` verschiebt, merkt nichts.

**Der zweite Teil des Fundes betrifft mein eigenes Messmittel.** `imBild` stand
auf `true`, obwohl 98 px fehlen — weil die Bedingung `r.bottom > 0 && r.top <
innerHeight` lautet und damit jedes Element für sichtbar hält, von dem ein
Pixel im Fenster steht.

> **Ein Prüfkriterium, das „teilweise sichtbar" als „im Bild" zählt,
> beantwortet eine andere Frage als die gestellte.**

Für Befund 10 war die Frage richtig gestellt — dort stand der ganze Block
unterhalb des Fensters. Für einen Block, der oben angeschnitten wird, taugt sie
nicht. Wer das nachprüft, fragt nach `oben >= 0`.

**Und meine Herleitung war zu stark.** Aus „es gibt kein `bringIntoView`" und
„`.aside` steht oben" folgt nicht „ausserhalb des Bildes" — es folgt nur, dass
niemand dafür sorgt. Wie viel davon zu sehen ist, hängt daran, wo die Seite
gerade steht, und das ist eine Messung und keine Folgerung.

> **Aus einer fehlenden Ursache folgt keine Wirkung — nur die Möglichkeit
> einer.**

#### Und die vierte Panne am Messmittel in dieser Runde, diesmal ein Selektor

Für die Kopfleiste lautete die Zeile
`document.querySelector('.block[tabindex="-1"], form[tabindex="-1"], .selection')`.
Sie hat `"nicht gefunden"` geliefert, und zwar zu Recht: Die vier Formulare der
Kopfleiste tragen **kein** `tabindex="-1"` — das haben nur die beiden Formulare
an einer Zeile, weil `bringIntoView` sie anspringen muss. Sie sind
`<form class="button-row">`. Und `.selection` gibt es nur, solange Einträge
angekreuzt sind.

Der Selektor war aus dem Gedächtnis geschrieben statt aus der Vorlage.

> **Ein Messmittel, das aus dem Gedächtnis geschrieben wird, ist eine Vermutung
> mit Klammern.**

**Das ist die vierte Panne dieser Art in dieser Runde**, und alle vier gehen auf
dieselbe Ungeduld zurück: der Kasten mit 468 px (zweimal falsch erklärt), die
27 px am Klick, das Kriterium `imBild`, jetzt der Selektor. Kein einziger davon
war ein Fehler des Panels.

Dasselbe Verhältnis wie in `docs/45`, `docs/47`, `docs/48` und `docs/59`: **Die
Mehrheit der Fehler steckt nicht im Prüfling.** Nur steht es diesmal nicht über
einen Lauf verteilt da, sondern über einen Nachmittag.

Die berichtigte Zeile fragt nach dem offenen Formular selbst — von den vieren
ist immer höchstens eines im Dokument, weil jedes an einem eigenen `v-if`
hängt:

```js
(() => { const f = document.querySelector('form.button-row'), r = f?.getBoundingClientRect()
  return JSON.stringify(r ? { oben: Math.round(r.top), unten: Math.round(r.bottom),
    fenster: window.innerHeight, ganzImBild: r.top >= 0 && r.bottom <= window.innerHeight } : 'nicht gefunden') })()
```

### 4.3 Die fünf Griffe der Experteneingabe

Gefahren am 20. August auf `cloudsrv24` gegen `rc.19`, Kundensicht auf
Abonnement 140, bei 390 px. Für Griff 4 und 5 ist **Job J gelöscht** worden.

| | Griff | erwartet | gemessen |
|---|---|---|---|
| 1 | Kästchen ankreuzen | Feld zeigt `* * * * *` | **`* * * * *`** ✓ |
| 2 | `*/15 9-17 * * 1-5`, Kästchen ab | fünf Felder tragen die Teile | **`*/15` · `9-17` · `*` · `*` · `1-5`** ✓ |
| 3 | Schnellwahl bei angekreuztem Kästchen | Feld zeigt `0 9 * * 1-5` | **`0 9 * * 1-5`** ✓ |
| 4 | `* * *` anlegen | Abweisung, Satz oben | abgewiesen, Satz oben ✓ — **aber Befund 15 und 16** |
| 5 | `*/15 * * * *` anlegen | Job steht mit genau diesem Ausdruck | Job „X", Zeitplan **`*/15 * * * *`** ✓ |

**Wunsch 1 trägt.** Griff 2 ist der eigentliche Punkt gewesen, und er sitzt: Der
Ausdruck schreibt in die fünf Felder zurück, es gibt also nicht zwei Wahrheiten
über denselben Zeitplan. Griff 3 zeigt, dass die Schnellwahl in beiden Ansichten
wirkt, und Griff 5, dass in der Liste steht, was getippt wurde — nicht etwas,
das ihm ähnlich sieht.

Griff 4 hat die Abweisung geliefert, die er sollte: Über die Form eines
Zeitplans urteilt der Server, und die Meldung steht oben in der Zusammenfassung.
**Was in ihr steht, ist der Befund.**

#### Befund 15 — jede Rückmeldung dieses Panels nennt einen englischen Feldnamen

Die Meldung zu Griff 4 lautet wörtlich:

> Das Formular wurde nicht gespeichert.
> Das Feld **month** ist erforderlich.
> Das Feld **day of week** ist erforderlich.

Deutscher Satzbau, englische Wörter darin. Die Ursache steht in
`lang/de/validation.php`:

```php
'attributes' => [],
```

Ist diese Liste leer, setzt Laravel den **Feldnamen** ein und macht aus
`day_of_week` das Wort „day of week". Die Namen sind englisch, weil sie
Bezeichner sind und sein sollen (`docs/19 §4a`) — sie waren nie als Anzeige
gedacht.

**Das ist keine Eigenheit der Cronseite.** Gemessen über `app/`:

| | Zahl |
|---|---|
| Feldnamen in Validierungsregeln | **85** |
| davon mehrwortig, also unübersehbar englisch | **21** |

Darunter `current password`, `first name`, `postal code`, `private key`,
`shared secret`, `login email`, `plan id`. Die einwortigen fallen weniger auf
und sind derselbe Fehler: „Das Feld **label** ist erforderlich", „Das Feld
**command** ist erforderlich".

**Die Zahl stand hier zuerst auf 95 und 23, und sie war falsch.** Der Ausdruck
der ersten Messung nahm jeden Schlüssel, dessen Wert nach einer Regel aussah —
und zählte damit die `$casts` der Modelle mit (`duration_ms`, `exit_code`,
`truncated`).

> **Ein Ausdruck, der die Form trifft, trifft noch nicht den Ort.**

Gezählt am Ort — `->validate([…])`, `Validator::make(…, […])` und
`rules(): array` — waren es **80**. Die fünf, die dann noch dazukamen, sind der
zweite Irrtum dieser Messung, und der ist der teurere; er steht beim Bau von
Befund 15 weiter unten.

**Und kein Wächter konnte das finden.** `WordChoiceTest` liest die Zeichenketten
im Quelltext und die Vorlagen. Dieses Wort steht in keiner von beiden — es
entsteht erst beim Ausführen, aus einem Bezeichner.

> **Ein Wort, das erst beim Ausführen entsteht, steht in keiner Datei — und kein
> Wächter, der Dateien liest, findet es.**

Das ist die Umkehrung des Fehlers, der dieses Projekt trägt: Sonst ist es eine
Zeichenkette, die auf etwas verweist, ohne dass der Bezug geprüft wird. Hier ist
es ein Bezeichner, der zu einer Anzeige wird, ohne dass jemand ihn dafür
vorgesehen hat.

**Es steht seit P0 so da** und ist durch jeden Abnahmelauf dieses Projekts
gegangen. Gefunden hat es der erste Griff, der eine Abweisung **absichtlich**
herbeigeführt hat.

> **Ein Fehlerweg, den nie jemand ausgelöst hat, ist ungeprüft — auch wenn die
> Seite darüber hundertmal fotografiert wurde.**

#### Befund 16 — in der Experteneingabe zeigt die Meldung auf Felder, die es dort nicht gibt

Derselbe Griff, der zweite Fehler darin. Getippt war `* * *` in **ein** Feld —
„Ausdruck". Die Meldung nennt zwei andere, „month" und „day of week", und beide
sind in diesem Zustand **nicht auf der Seite**: Das Kästchen ist angekreuzt, die
fünf Felder sind eingeklappt.

Wer der Meldung folgt, sucht etwas, das er nicht sehen kann. Richtig wäre ein
Satz über den Ausdruck selbst — er hat drei Teile und braucht fünf.

**Das ist dieselbe Familie wie `docs/59`:**

> **Ein roter Rand am Feld behauptet, das Feld sei falsch. Wer ihn für einen
> Zustand des Servers setzt, schickt den Leser dorthin, wo nichts zu ändern
> ist.**

Und hier eine Stufe weiter:

> **Eine Meldung, die ein Feld nennt, das gerade nicht zu sehen ist, ist keine
> Auskunft — sie ist eine Suchaufgabe.**

**Der Bau von Wunsch 1 hat das nicht bedacht,** und das war absehbar: Die
Experteneingabe ist eine Sicht auf die fünf Felder — die Prüfung ist es nicht.
Sie urteilt weiter über die fünf, und ihre Antwort geht an fünf Empfänger, von
denen vier eingeklappt sind. Genau deshalb steht in `docs/63 §6b` überhaupt ein
Griff 4.

> **Eine Sicht auf eine Sache ist noch keine Sicht auf ihre Fehlermeldungen.**

### 4.4 Die offene Frage aus der ersten Runde — beantwortet am 20. August

**Die 27 px an `.stacks thead` der Cronseite.** Der Prüfschritt ist gefahren,
dreimal bei 390 px auf `/subscriptions/140/cron`:

| | Handgriff | `thead` · `tr` |
|---|---|---|
| a | Seite neu geladen | 484 · 456 |
| b | „Ändern" gedrückt, **kein** Neuladen | 484 · 456 |
| c | „Abbrechen" gedrückt | 484 · 456 |

**Kein einziges Mal 511.** Damit sind drei Kandidaten gemessen und erledigt: der
Bestand der Tabelle, der Klick, das geöffnete Formular. Die Begründung und der
Irrtum, der dazwischen lag, stehen in §4.1.

#### Der vierte Kandidat fällt im Quelltext, nicht auf dem Server

Übrig war die Schrift — die Vermutung aus dem ersten Lauf, und die einzige, die
zur Grössenordnung passte (5,6 % und 5,9 %). Sie ist **unmöglich**, und das
steht in `app.css`:

```css
--font-sans: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
--font-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
```

Kein `@font-face`, keine `.woff`, kein Verweis auf eine Schriftquelle — dieses
Panel lädt keine Schrift, es benutzt die des Systems. Es gibt also nichts, was
„noch nicht geladen" sein könnte; `document.fonts` ist leer und
`document.fonts.ready` sofort erfüllt.

**Und hier stand vorher ein Prüfschritt, der nicht laufen konnte.** Er lautete,
man solle vor dem Neuladen ein `addEventListener('load', …)` in die Konsole
einfügen — der stirbt aber mit dem Seitenkontext, den das Neuladen wegwirft. Er
hätte nie gemessen und dabei ausgesehen, als sei nichts zu finden.

> **Ein Messmittel, das ein Neuladen überleben soll, überlebt es nicht — es wird
> mitentfernt und meldet das nicht.**

Derselbe Satz steht als Falle schon in `docs/63 §2`, dort über das Werkzeug aus
der Zwischenablage. Er gilt für die Messung genauso wie für das Gemessene.

#### Die Gegenprobe zum Quelltext, eine Zeile, ohne Neuladen

Ein Wert, den nur die Datei kennt, ist eine Vermutung mit Fussnote. In der
Konsole:

```js
JSON.stringify({
  schriften: document.fonts.size,
  status: document.fonts.status,
  familie: getComputedStyle(document.querySelector('table.stacks th')).fontFamily,
})
```

**Gefahren am 20. August auf `cloudsrv24`, gegen `rc.19`:**

```json
{"schriften":0,"status":"loaded","familie":"system-ui, -apple-system, \"Segoe UI\", Roboto, sans-serif"}
```

Null Schriften im Dokument, der Ladezustand sofort `loaded`, und die Familie ist
Wort für Wort der Stapel aus `app.css`. Der Browser bestätigt, was die Datei
sagt.

> **Ein Wert, den nur die Datei kennt, ist eine Vermutung mit Fussnote — eine
> Zeile in der Konsole macht daraus eine Messung.**

#### Was damit gilt

Vier benannte Kandidaten, vier erledigt. Die 511 aus dem ersten Lauf ist gegen
`rc.19` in fünf Messungen nicht ein einziges Mal wieder aufgetreten und hat
keine Erklärung mehr, die jemand benennen könnte.

> **Eine Zahl, die sich nicht wieder herstellen lässt, ist kein Befund mehr —
> sie ist eine Notiz darüber, dass einmal etwas anderes gemessen wurde.**

Sie bleibt genau so stehen: als Notiz, nicht als offener Punkt. Was sie **nicht**
bekommt, ist eine fünfte Vermutung — dieser Abschnitt hat in zwei Tagen zwei
davon verbraucht, und beide waren falsch.

**Für das Kriterium ist es die ganze Zeit gleichgültig gewesen** (`dokument` ist
in jeder Lage 0, `.stacks thead` ist der Mechanismus). Geklärt wurde das
Vertrauen in das Messmittel und nicht der Zustand des Panels.

---

## 5. Wunsch 2 — Schlüssel im Panel erzeugen, eintragen und herunterladen

**Bestellt vom Betreiber am 20. August 2026**, während der zweiten Bilderrunde.
Heute kann das Panel einen öffentlichen Schlüssel nur **entgegennehmen**; wer
keinen hat, braucht eine Kommandozeile und `ssh-keygen`. Das ist für einen
Kunden, der über SFTP an seine Dateien will, die falsche Voraussetzung.

Dies ist ein Vorschlag und kein Plan. Was hier steht, ist die Frage, die vor dem
Plan beantwortet gehört, und die Messungen, die sie beantworten.

### 5.1 Die eine Frage: Wo entsteht der private Schlüssel

Alles andere folgt daraus. Es gibt zwei Wege.

**Weg A — im Browser.** `crypto.subtle.generateKey` erzeugt das Paar auf dem
Gerät des Kunden. Das Panel bekommt **nur den öffentlichen Teil**, auf demselben
Weg wie heute die Eingabe von Hand. Der private Teil wird im Browser zum
Herunterladen angeboten und verlässt ihn nie.

**Weg B — im Agenten.** `ssh-keygen` erzeugt das Paar auf dem Server, der Agent
gibt beide Teile zurück, das Panel reicht den privaten einmal an den Kunden
durch und vergisst ihn.

### 5.2 Was gegen Weg B spricht, steht im Quelltext und nicht in einer Meinung

Ein privater Schlüssel, der auf Weg B entsteht, reist durch zwei Einrichtungen
dieses Panels, die **beide auf die Platte schreiben** — und zwar nicht als
Versehen, sondern ihrer Bauart nach:

| Stelle | was sie tut | Beleg |
|---|---|---|
| Die Sitzung | `SESSION_DRIVER=database` — eine Flash-Meldung liegt in der Tabelle `sessions` | `config/session.php`, `.env.example` |
| Der Vorgang | `operations.payload` und `operations.result` sind JSON-Spalten; die Antwort des Agenten wird gespeichert | Migration `2026_08_02_120100` |

Wer also `->with('schluessel', …)` schreibt, legt einen privaten Schlüssel in
die Datenbank. Und wer ihn über eine gewöhnliche Agent-Operation holt, ebenso —
in `operations.result`, wo er das Zurückbauen des Abonnements überlebt
(`2026_08_07_100100`).

Beides ist umgehbar, aber nur, indem man den Schlüssel **an genau den
Mechanismen vorbeiführt, auf denen dieses Panel sonst überall besteht**. Eine
Ausnahme von der dritten Grenze, eine Ausnahme vom Vorgangsprotokoll, und beide
für das empfindlichste Datum des ganzen Merkmals.

> **Ein privater Schlüssel, den der Server nie hatte, kann er nicht verlieren.**

Dazu kommt eine Eigenschaft von Weg B, die den Ablauf verbiegt: Zwischen dem
Erzeugen und dem Herunterladen liegt ein zweiter Aufruf, und dazwischen **muss
der Schlüssel irgendwo liegen**. Es sei denn, die Antwort auf das Erzeugen ist
selbst die Datei — dann kann sie aber die Seite nicht aktualisieren.

> **Ein Datum, das zwischen zwei Anfragen überleben muss, wird gespeichert —
> die Frage ist nur, wo.**

### 5.3 Vorschlag: Weg A, mit Weg B als benanntem Rückfall

Der Ablauf auf der Seite `SFTP-Zugang`, neben „Schlüssel eintragen":

1. **„Schlüssel erzeugen"** — ein Feld für die Bezeichnung, ein Knopf.
2. **Vor** dem Erzeugen steht der Satz, dass der private Teil **einmal** gezeigt
   wird und danach fort ist. Nicht danach.
3. Nach dem Erzeugen: der private Teil in einem Feld, ein Knopf „Herunterladen"
   (`Blob` und `<a download>`, ohne Weg über den Server), und der öffentliche
   Teil geht auf demselben Weg an den Server wie eine Eingabe von Hand.
4. Der Fingerabdruck steht danach in der Schlüsseltabelle — dieselbe Zeile, die
   es heute schon gibt, mit demselben Rückweg über „Entfernen".

**Was das Panel dabei niemals tut:** den privaten Teil senden, protokollieren,
in eine Flash-Meldung legen oder ein zweites Mal zeigen.

### 5.4 Was vor dem Plan gemessen gehört

Drei Fragen, und die dritte ist die, an der Weg A scheitern kann:

| | Frage | wie |
|---|---|---|
| 1 | Kann der Browser Ed25519? | `crypto.subtle.generateKey({name:'Ed25519'}, true, ['sign'])` in der Konsole — eine Zeile |
| 2 | Lässt sich der **öffentliche** Teil ins OpenSSH-Format bringen? | 32 rohe Bytes in die Drahtform `ssh-ed25519`; gegen `ssh-keygen -l` gegengeprüft |
| 3 | Nimmt OpenSSH den **privaten** Teil, wie ihn WebCrypto ausgibt? | WebCrypto gibt PKCS#8; OpenSSH schreibt sein eigenes Format. Ob es PKCS#8 auch **liest**, ist die Frage |

**Punkt 3 entscheidet den Weg.** Liest OpenSSH es, ist Weg A ein kurzer Weg.
Liest es das nicht, muss der Browser das OpenSSH-Format selbst schreiben — ein
Containerformat von Hand, und das ist kein Nachmittag.

**Alle drei sind am 20. August gemessen; die Antworten stehen in §5.6.**

> **Wissen aus zweiter Hand sieht aus wie Wissen.** Der Satz steht seit `docs/37`
> im Projekt; für Punkt 3 gilt er wörtlich, und deshalb steht hier keine
> Antwort.

**Und gemessen werden kann Punkt 3 nicht in diesem Container**, denn dafür
müsste ein privater Schlüssel entstehen. Die Regel dagegen ist älter als dieses
Merkmal und gilt auch für einen Wegwerfschlüssel zu einer Formatfrage. Der Ort
für diese Messung ist `cloudsrv24` mit einem Schlüssel, der danach gelöscht
wird — oder ein anderer Rechner des Betreibers.

**Dieser Absatz war eine Auslegung und keine Ansage, und der Betreiber hat sie
am 20. August aufgehoben.** Der Satz „Privates Schlüsselmaterial wird in diesem
Container nie erzeugt" steht in `CLAUDE.md` unter *Ablauf*, also bei den
Freigaben — er handelt von Signaturschlüsseln. Ein Wegwerfpaar zu einer
Formatfrage gibt Zugang zu nichts. Gemessen wurde hier, mit Chromium 141 und
OpenSSH 9.6p1, und alles Schlüsselmaterial ist danach gelöscht worden.

> **Eine Regel, die man ausweitet, gehört als Auslegung gekennzeichnet — sonst
> liest sie sich beim nächsten Mal wie die Regel selbst.**

### 5.5 Was dieser Vorschlag ausdrücklich nicht löst

- **RSA und ECDSA.** Der Vorschlag deckt Ed25519. Das Formular nimmt heute auch
  RSA ab 2048 Bit und ECDSA an; erzeugt würde nur eine Art, und das gehört
  gesagt statt stillschweigend eingeschränkt.
- **Den Verlust.** Wer den privaten Teil verliert, erzeugt einen neuen und
  entfernt den alten. Ein zweites Herunterladen darf es nicht geben — sonst läge
  er doch irgendwo.

### 5.6 Gemessen am 20. August — und Punkt 3 fällt negativ aus

Chromium 141 und OpenSSH 9.6p1, beides im Container. Jede Messung mit ihrer
Gegenprobe; das Skript steht als `tests/schluessel-messen.mjs` im Repo und misst
**den ausgelieferten Baustein**, nicht eine Abschrift.

| | Frage | Ergebnis |
|---|---|---|
| 1 | Kann der Browser Ed25519? | **ja** — `crypto.subtle.generateKey({name:'Ed25519'})` |
| 2 | Öffentlicher Teil im OpenSSH-Format? | **ja** — `ssh-keygen -l` liest ihn und nennt `(ED25519)` |
| 3 | Nimmt OpenSSH den privaten Teil als PKCS#8? | **nein**, in keiner der drei Formen |

Die drei Versuche zu Punkt 3, wörtlich:

    ssh-keygen -y -f …            Load key: invalid format
    ssh-keygen -l -f …            is not a key file
    ssh-keygen -i -m PKCS8 -f …   not a recognised public key format

**Und die Falle, die eine Runde gekostet hat, steckte nicht in OpenSSH.** Der
erste Versuch zu Punkt 1 lief über `about:blank` und meldete
`crypto.subtle → undefined`. Das sieht aus wie „der Browser kann kein Ed25519"
und heisst „es gibt hier keinen sicheren Kontext". Das Panel wird über HTTPS
ausgeliefert, also trifft es niemanden — aber es hätte den ganzen Weg A
umgeworfen.

> **Ein Merkmal, das nur im sicheren Kontext existiert, fehlt daneben nicht mit
> einer Meldung, sondern als `undefined`.**

**Der Rückfall trägt, und er ist kürzer als befürchtet.** Der Container
`openssh-key-v1` ist reine Serialisierung — Längenangaben, Zeichenketten, eine
Auffüllung, **kein Stück Kryptographie**. Gemessen:

| | |
|---|---|
| `ssh-keygen -y` liest die selbst geschriebene Datei | ja |
| … und leitet genau den passenden öffentlichen Teil ab | ja |
| Gegenprobe: **ein** Byte verdreht | abgewiesen (`error in libcrypto`) |
| Anmeldung über SFTP an einem Wegwerf-`sshd` | **gelungen** |
| Gegenprobe: ein anderer gültiger Schlüssel, nicht eingetragen | abgewiesen |

Die Anmeldung ist der Beleg, auf den es ankommt: `ssh-keygen -y` sagt, dass die
Datei lesbar ist; erst der `sshd` sagt, dass ein Kunde damit an seine Dateien
kommt.

**Und der Rand, an dem so eine Serialisierung still bricht, ist die
Auffüllung.** Sie hängt an der Länge der Bemerkung, und im ausgerichteten Fall
kommt **nichts** dazu. Gemessen sind alle acht Restklassen, jede zweimal, dazu
die leere Bemerkung — sechzehn Dateien, sechzehnmal lesbar und passend.

> **Ein Rand, der von der Länge einer Beschriftung abhängt, ist keiner, den man
> an einem Beispiel prüft.**

---

## 6. Wunsch 3 — eine ständige Suchleiste im Dateimanager

**Vorgeschlagen vom Betreiber am 20. August 2026**, beim Messen des vierten
Kopfleistenformulars: statt eines Knopfes, der ein Formular aufklappt, eine
Suchleiste, die immer da ist.

**Der Grund liegt in den Messwerten dieser Runde.** Die vier Griffe der
Kopfleiste sind gleich gebaut — Knopf, Formular, absenden. Für „Verzeichnis
anlegen" und „Datei anlegen" passt das: Man tut es selten und einmal. Suchen tut
man oft und beiläufig, und dafür sind zwei Klicks bis zum Feld einer zu viel.

> **Gleich gebaute Griffe für ungleich häufige Handgriffe kosten den häufigen
> mehr, als sie dem seltenen sparen.**

### 6.1 Das eine Risiko, und es ist kein kleines

Das heutige Formular trägt einen Satz über dem Feld: „Wonach **unterhalb dieses
Verzeichnisses** gesucht wird." Der Satz ist die halbe Auskunft — die Suche gilt
nicht dem Abonnement, sondern dem Verzeichnis, in dem man gerade steht.

Eine ständige Leiste hat für diesen Satz keinen Platz. Und sie sieht aus wie
etwas, das überall sucht.

> **Eine Leiste, die immer da ist, sieht aus, als suchte sie überall.**

Wer im Wurzelverzeichnis steht, bekommt dasselbe Ergebnis wie heute. Wer in
`/httpdocs/irgendwo/tief` steht und die Leiste für global hält, sucht am Bestand
vorbei und schliesst daraus, die Datei gebe es nicht. Das ist schlimmer als ein
Klick zu viel.

### 6.2 Drei Formen, und was jede kostet

| | Form | trägt den Geltungsbereich | Platz bei 390 px |
|---|---|---|---|
| A | Feld mit dem Ort im Platzhalter — `In /httpdocs suchen …` | im Platzhalter, also nur solange leer | eine Zeile statt eines Knopfes |
| B | Feld mit einer Marke daneben, die den Ort nennt und ihn auf die Wurzel umstellen lässt | sichtbar, immer | eine Zeile plus Marke |
| C | Knopf bleibt, öffnet aber ein Feld **an Ort und Stelle** statt eines Formulars darunter | wie heute | wie heute |

**A ist die einfachste und die gefährlichste**: Sobald jemand tippt, ist der
Platzhalter fort — und mit ihm die einzige Stelle, an der der Ort stand.

> **Eine Auskunft im Platzhalter ist genau so lange da, wie man sie nicht
> braucht.**

**B löst das Risiko und kostet die Marke.** Sie ist zugleich die Stelle, an der
„im ganzen Abonnement suchen" hingehört — eine Frage, die es heute gar nicht
gibt und die jeder stellen wird, sobald die Leiste da ist.

### 6.3 Was vor dem Bauen gemessen gehört

1. **Die Höhe der Kopfleiste bei 390 px.** Heute sind es vier Knöpfe
   untereinander; eine Leiste ersetzt einen davon und bringt ein Feld mit
   `--tap`-Höhe mit. Ob die Kopfleiste dadurch wächst oder schrumpft, ist eine
   Messung im Container und keine Schätzung.
2. **Der Weg von der Leiste zur Trefferseite.** Die Seite „Suche" (Ansicht 4)
   hat ein **eigenes** Feld. Zwei Felder für dieselbe Sache müssen dasselbe
   sagen — sonst steht in der Leiste ein Wort und auf der Trefferseite ein
   anderes. Derselbe Fehler wie bei Wunsch 1, nur zwischen zwei Seiten statt
   innerhalb einer.

> **Zwei Eingaben für dieselbe Sache sind eine Sicht und eine Kopie — welche
> von beiden, entscheidet, wer sie schreibt.**

**Entschieden ist hier nichts.** Der Vorschlag steht, das Risiko steht dabei,
und gebaut wird nach der Runde.

### 6.4 Gemessen am 20. August — und die Zahl aus §6.2 war die einer anderen Form

**Messung 1: die Höhe des Seitenkopfs.** Echtes Markup, gebautes Stylesheet,
Inhaltsbreite = Fenster − 300 (Rail 236 + Polster 2 × 32).

| Fenster | heute (zu) | Leiste in der Kopfzeile | Leiste als eigene Zeile |
|---|---|---|---|
| 390 px | 331 px | 458 px (+127) | 472 px (+141) |
| 1280 px | 115 px | 216 px (**+101**) | 187 px (+72) |
| 1440 px | 115 px | 168 px (**+53**) | 187 px (+72) |
| 1728 px | 115 px | 117 px (+2) | 187 px (+72) |

**Der erste Durchgang hat +2 px bei 1440 gemeldet, und die Zahl war richtig —
für Form A.** Die trägt keine sichtbare Beschriftung, den Ort im Platzhalter und
kein Kästchen. Beide Entscheidungen des Betreibers kosten Breite, und zusammen
passt die Leiste nicht mehr neben die drei Knöpfe: Umsonst ist sie erst ab
1728 px.

> **Ein Preis, den man für eine Form gemessen hat, gilt nicht für die nächste.**

Der zweite Fehler war grober: Der erste Durchgang setzte die Inhaltsbreite gleich
der Fensterbreite und übersah damit das Rail. 300 px sind bei diesen Zahlen der
Unterschied zwischen „passt" und „passt nicht".

> **Eine Messung am Fenster misst nicht die Fläche, auf der gezeichnet wird.**

**Messung 2: die beiden Eingaben.** Sie sagen heute **nicht** dasselbe:

| | Beschriftung | schickt |
|---|---|---|
| Kopfleiste | „Wonach unterhalb dieses Verzeichnisses gesucht wird" | `query`, `path` |
| Trefferseite | „Suchbegriff" + Kästchen „auch im Inhalt" | `query`, `path`, `content` |

Wer von der Kopfleiste sucht, kann also **nie** im Inhalt suchen — und erfährt
erst auf der Trefferseite, dass es die Möglichkeit gibt.

> **Zwei Eingaben für dieselbe Sache sind eine Sicht und eine Kopie — und die
> Kopie ist die, die weniger kann.**

---

## 7. Bilanz der zweiten Runde

**Gemessen am 20. August 2026 auf `cloudsrv24` gegen `v0.6.0-rc.19`**, in einem
Fenster ohne Erweiterungen.

| | Zahl |
|---|---|
| Ansichten in je vier Lagen | 9 × 4 = **36** |
| Zustände | **16** |
| Griffe der Experteneingabe | **5** |
| Lagen mit `dokument > 0` | **0** |
| Gegenproben, die nicht `200/200` ergaben | **0** |
| Fremdeinträge in `schiebt` | **0** |

**Jeder Eintrag unter `schiebt` ist `.stacks thead` mit seinem `tr`**, also der
Mechanismus aus `app.css`. **Jeder Eintrag unter `rollt` trägt `darf: true`** —
einschliesslich `.cm-scroller`, der niemandem hier gehört.

### 7.1 Was die Runde belegt hat

**Vier Behebungen der ersten Runde stehen mit einer Zahl da**, nicht mit einem
Eindruck:

| Befund | Behebung | gemessen auf `cloudsrv24` |
|---|---|---|
| 1 | Kästchen als `.toggle` | **17 × 17**, Leiste **171** — dieselben drei Zahlen wie im Container |
| 6 | Dichtestufe eine Sprosse tiefer | **217 → 215** und **19 → 17**, das erste **vorhergesagt** |
| 9 | `align-items: flex-end` | Versatz **0** statt −14 |
| 10 | `bringIntoView` an drei Griffen | `oben` 117 und 307, beide ganz im Bild |

**Die übrigen sieben sind im Bild bestätigt** — die vier Fugen (2, 3, 4, an
beiden Stellen), das Literal (5), die Polsterung (7, 8) und der leise Text (11).

**Und der Fremdeintrag ist verschwunden.** Im ersten Lauf trugen sieben von neun
Ansichten einen Kasten der Erweiterung LastPass — zweimal falsch erklärt, einmal
mit Zeilennummer. Das Fenster ohne Erweiterungen hat sie alle entfernt und dabei
**keine einzige Zahl der Seite verändert.**

### 7.2 Was die Runde gefunden hat

**Sieben Befunde, und keinen davon hat ein Test gefunden:**

| # | Befund | Reichweite |
|---|---|---|
| 12 | Die Auskunft über das volle Kontingent liegt bei 390 px hinter zehn Kärtchen | eine Seite |
| 13 | Kein Weg zum Formular „Job anlegen" ausser Scrollen | eine Seite |
| 14 | Der Bereich „Job anlegen" steht bei 1440 px als vier Kästen da | eine Seite |
| 15 | **Jede Rückmeldung des Panels nennt einen englischen Feldnamen** | 85 Felder, 21 mehrwortig |
| 16 | In der Experteneingabe zeigt die Meldung auf eingeklappte Felder | eine Seite |
| 17 | **Zwei Elemente tragen `id="app"`** — `@inertia` statt `@inertiaHead` | jede Seite |
| 18 | Der Zielbaum wird nicht ins Bild geholt (98 px angeschnitten) | eine Seite |

Dazu eine Beobachtung, die **kein** Befund ist: 57 Felder ohne `id` und ohne
`name` (§4.2).

### 7.3 Was die Runde über sich selbst gelernt hat

**Vier Pannen am Messmittel, und kein einziger davon war ein Fehler des
Panels:** der Kasten mit 468 px (zweimal falsch erklärt), die 27 px am Klick,
das Kriterium `imBild`, der Selektor aus dem Gedächtnis.

Dasselbe Verhältnis wie in `docs/45`, `47`, `48` und `59` — nur nicht über einen
Lauf verteilt, sondern über einen Nachmittag.

> **Ein Messmittel, das aus dem Gedächtnis geschrieben wird, ist eine Vermutung
> mit Klammern.**

**Und zwei Zahlen des ersten Laufs lassen sich nicht wiederherstellen** (511
gegen 484, 166 gegen 157). Beide an `.stacks thead`, beide nach oben, beide ohne
Erklärung. Sie stehen als Notiz da.

### 7.4 Die Reihenfolge fürs Bauen

**Keine dieser Behebungen rührt eine Marke an, die über eine Seite hinausgeht.**
Befund 6 hat das getan und damit sämtliche Messwerte des ersten Laufs entwertet;
hier ist das nicht der Fall. **Eine dritte volle Runde ist deshalb nicht nötig**
— es genügt, die berührten Stellen einzeln nachzumessen.

> **Das gilt nur, solange es gilt.** Wird Befund 14 in `app.css` behoben statt in
> `Cron.vue`, ändert sich die Form **jedes** Formulars, und dann ist die dritte
> Runde fällig. Wer ihn anfasst, hält ihn auf der Seite.

**Vorgeschlagene Reihenfolge, und der Grund steht dabei:**

**1. Befund 17 — `@inertiaHead`.** Eine Zeile, ein Wächter über
`app.blade.php`. Zuerst, weil er einen **stillen** Zustand beendet: Die
Anwendung hängt heute im Element, das in den Kopf gehört, und die nächste Zeile,
die jemand vor `</head>` schreibt, landet im Rumpf. Der billigste Griff mit dem
grössten Rückbau an Risiko.

**2. Befunde 13 und 18 — die Regel statt der Stelle.** `RevealTest` prüft heute
nur Griffe **an einer Zeile**. Er muss jeden Griff prüfen, der etwas öffnet oder
etwas Entferntes betrifft.

Der Grund für Platz 2 ist **Wunsch 2 und 3**: Beide bringen neue Griffe mit — ein
„Schlüssel erzeugen" und eine Suchleiste. Ohne die Regel wird derselbe Fehler
zum vierten und fünften Mal gemacht.

> **Eine Regel, die man vor dem nächsten Merkmal aufstellt, kostet einmal. Nach
> dem nächsten Merkmal kostet sie zweimal.**

**3. Befunde 15 und 16 — die Rückmeldungen.** Beide hängen an derselben Leitung.
15 ist mechanisch (85 Namen in `lang/de/validation.php`) und hat die grösste
Reichweite von allen; 16 fährt mit, weil die Experteneingabe ihre Meldung
umlenken muss.

Der Wächter dazu ist der interessante Teil: Er muss ein Wort prüfen, das **in
keiner Datei steht**, sondern erst beim Ausführen entsteht. Also nicht der Text,
sondern die Vollständigkeit der Liste gegen die Menge der validierten Felder.

**4. Befunde 12 und 14 — die Cronseite.** Zuletzt, weil sie als einzige eine
Entscheidung brauchen und nicht nur eine Behebung: wohin die Auskunft über das
Kontingent gehört, und wie der Bereich „Job anlegen" stattdessen aussieht. Beides
gehört **gemessen** und nicht am Schreibtisch entschieden — der Aufsatz im
Container trifft die echte Seite aufs Pixel.

**Danach die Wünsche**, in dieser Reihenfolge:

- **Wunsch 2** (Schlüssel erzeugen) — die drei Messungen aus §5.4 zuerst, und die
  dritte kann nur der Betreiber machen.
- **Wunsch 3** (Suchleiste) — die zwei Messungen aus §6.3 zuerst.

**Und was ausdrücklich nicht in dieser Fassung gebaut wird:**

- die vollständige Umkehrung der Abstandsregel (`* + *`) — ein eigener Durchgang
  mit eigenen Aufnahmen,
- die neunzehn ungeprüften Griffe in `Databases/Console.vue`
  (`RevealTest::UNEXAMINED`) — sie gehören zu Schritt 2 der Regel, aber die
  Konsole ist keine der neun Ansichten und braucht ihre eigene Messung,
- die 57 Felder ohne `id` und `name` — heute folgt daraus nichts.

---

## 8. Gebaut — Befunde 15 und 16

Gebaut am 20. August 2026, in der Reihenfolge aus §7.4 an dritter Stelle.

### 8.1 Befund 15 — die Namen

`lang/de/validation.php` trägt jetzt **85** Namen unter `attributes`. Drei
Bezeichner bedeuten an zwei Orten Verschiedenes; die Liste trägt den häufigeren
Fall, der andere seinen Namen als dritten Wert am Aufruf:

| Bezeichner | in der Liste | am Aufruf |
|---|---|---|
| `to` | Empfänger (Testmail) | `Bis` in `AuditController` |
| `host` | Rechner | `Erreichbar von` in `DatabaseController` |
| `mode` | Rechte | `Art der Änderung` in `DatabaseController` |

**Und die Kontingente bekommen ihre Namen aus der Aufzählung, aus der auch ihre
Regeln kommen.** `Quotas::names()` baut sie aus `Quota::cases()` und
`Feature::cases()`; `PlanController` und `SubscriptionController` reichen sie als
dritten Wert weiter. Eine Abschrift in der Sprachdatei wäre die zweite Liste
gewesen — und die zweite ist die, die beim nächsten Kontingent vergessen wird.

### 8.2 Der teuerste Fund beim Bauen: der Wächter war blind, wo der Befund war

`AttributeNameTest` stand grün — und die fünf Felder, an denen Befund 15
überhaupt gefunden wurde, hatten **keinen einzigen deutschen Namen**. Der
Wortlaut des Befundes ist „Das Feld **day of week** ist erforderlich"; genau
dieses Feld sah der Wächter nicht.

Der Grund steht in `CronController`:

```php
...array_fill_keys(Schedule::FIELDS, ['required', 'string', 'max:192']),
```

Der Ausdruck liest die Schlüssel auf der obersten Ebene eines Regelblocks. `minute`,
`hour`, `day_of_month`, `month` und `day_of_week` stehen dort **nicht** — sie
entstehen erst beim Ausführen aus einer Konstanten.

> **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat nicht wenig gemessen
> — er hat an dieser Stelle gar nicht gemessen.**

Das ist derselbe Satz wie „Eine Null ist nur dann eine Messung, wenn daneben
etwas anderes als Null steht", nur eine Ebene tiefer: Hier fehlt nicht der
Ausschlag, sondern der Prüfkörper.

**Dieselbe Blindstelle lag über zwei weiteren Aufrufen**, und dort geht es um
mehr Felder als fünf: `...Quotas::rules()` in `PlanController` und
`...Quotas::overrideRules()` in `SubscriptionController` erzeugen je einen
Schlüssel pro Kontingent und Merkmal. Sie waren aus demselben Grund unsichtbar,
und ihre Meldung lautete „Das Feld **quotas.disk mb** muss vorhanden sein".

`test_no_rule_block_hides_its_fields` schliesst die Lücke als Regel und nicht als
Einzelfall: Ein Spread auf der obersten Ebene eines Regelblocks ist ab jetzt
eines von beidem — aufgelöst (`RESOLVED_SPREADS`) oder am Aufruf benannt
(`NAMED_AT_CALL_SITE`). Ein dritter Fall ist Rot. Beide Richtungen sind von Hand
gegengeprüft: Fehlt `Quotas::names()` am Aufruf, beisst er; steht dort ein
Ausdruck, den er nicht kennt, ebenfalls.

### 8.3 Befund 16 — die Meldung der Experteneingabe

`experte` reist als Feld des Formulars mit. Der Server prüft davon unabhängig
denselben Zeitplan aus fünf Feldern — er **benennt** nur anders: In der
Expertenansicht geht die Meldung an `expression` und nennt die Stelle im
Ausdruck („Im Ausdruck fehlt der 4. Teil (Monat).") statt eines Feldes, das
eingeklappt ist.

> **Eine Sicht auf eine Sache ist noch keine Sicht auf ihre Fehlermeldungen.**

Zwei Dinge, die dabei aufgefallen sind:

**Der bestehende Wächter hat das sechste Feld sofort gemeldet.**
`test_the_free_expression_is_a_view_on_the_five_fields` bestand darauf, dass das
Formular **genau** die fünf Felder plus Beschriftung, Befehl und Zustand
schickt. Das ist die Regel, die es die Expertenansicht überhaupt erst geben
lässt. `experte` steht deshalb nicht als Ausnahme da, sondern in
`VIEW_FIELDS` — mit Begründung, und mit einer Sperrklinke daneben: Ein Feld des
Zeitplans in dieser Liste ist Rot.

**Und die Namen der fünf Stellen kamen im ersten Anlauf als eigene Konstante.**
`PART_NAMES` im Controller — dieselben fünf Wörter ein zweites Mal, direkt neben
der Sprachdatei, die sie ohnehin führt. Sie kommen jetzt aus
`trans('validation.attributes.'.$feld)`.

> **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von beiden
> ist der Ort, an dem man nachsieht.**

### 8.4 Was dabei nachgetragen wurde

Das rot markierte Feld: `zeitplanFalsch` kannte nur die fünf Feldnamen. Seit der
Server seine Meldung unter `expression` ablegt, wäre das eine sichtbare Feld
ausgerechnet dann unmarkiert geblieben, wenn die Meldung von ihm handelt.

### 8.5 Was offen bleibt

Nichts aus 15 und 16. Als Nächstes stehen **Befunde 12 und 14** an — die
Cronseite, und sie ist die einzige Gruppe, die eine gemessene Entscheidung
braucht. Sie gehört **in `Cron.vue`** behoben und nicht in `app.css`; sonst wird
die dritte volle Runde fällig (§7.4).

---

## 9. Gebaut — Befunde 12 und 14

Gebaut am 20. August 2026, als vierte und letzte Gruppe der Reihenfolge aus
§7.4. Beide Befunde haben keine Zahl aus dem Abnahmelauf — sie sind vom
Betreiber am Bild gemeldet worden. Die Zahlen unten sind deshalb **beim
Beheben** entstanden, im Container gegen das gebaute Stylesheet.

### 9.1 Wie gemessen wurde

Der Aufsatz aus `CLAUDE.md`: das echte Markup aus `Cron.vue`, das gebaute
Stylesheet aus `public/build`, gerendert im vorinstallierten Chromium. Die
Inhaltsbreite ist gerechnet und nicht geschätzt — `PanelLayout` ist ein Raster
aus `236px 1fr` mit `26px 32px` Polster, also **1140 px** bei 1440 und
**358 px** bei 390.

Zwei Vorsichtsmassnahmen:

- **`<style scoped>` gilt in diesem Aufsatz nicht** (`docs/59`). `Cron.vue` hat
  keinen — nachgesehen, nicht angenommen.
- **Jede Messung trägt ihre Gegenprobe.** Der Prüfkörper ist an
  `scrollWidth + 200` gebunden und schlägt in allen Lagen mit `200` aus; ein
  `dokument: 0` daneben ist damit eine Messung und keine Null.

Gemessen wurde ausserdem eine Grösse, die dieses Repo bisher nicht kannte: die
**tote Fläche**. Für jede Reihe der Flexverteilung `.form` ist das die Summe aus
`(Unterkante der Reihe − Unterkante der Gruppe) × Breite der Gruppe`, in
Tausend Pixeln². Sie beziffert genau das, was der Betreiber gesehen hat: den
Platz, der leer bleibt, weil eine Gruppe neben einer höheren steht.

### 9.2 Befund 12 — die Meldung stand vier Bildschirme zu tief

Gemessen an der ganzen Seite bei 390 px, mit zehn Jobs in der Liste:

| Ort der Meldung | Abstand von der Oberkante | im ersten Bild |
|---|---|---|
| im Bereich „Job anlegen" (bisher) | **3566 px** | nein |
| vor `.sections` (jetzt) | **18 px** | ja |

Die Seite ist 3795 px hoch — die Meldung stand also bei **94 %**. Vier
Bildschirme.

**Der Einwand aus Befund 12 ist gewogen und beantwortet.** Dort steht: „Eine
Behebung, die sie nur nach oben schiebt, nimmt ihr den Bezug." Der Bezug hängt
aber nicht am Ort, sondern am Satz — „Entfernen Sie einen Job, **um einen neuen
anzulegen**" nennt die Handlung. Dazu nennt die Meldung jetzt die Zahl
(„10 von 10"), und der Griff „Job anlegen" aus Befund 13 steht in der Kopfzeile
der Liste, also unmittelbar darunter.

Was der Wächter davon hält, ist der **Ort** und nicht der Bezug: Der Bezug ist
eine Frage an einen Leser.

### 9.3 Befund 14 — vier Kästen werden drei Gruppen

Gemessen bei 1440 px, Inhaltsbreite 1140 px:

| Anordnung | Reihen | tote Fläche | Höhe des Formulars |
|---|---|---|---|
| vier Gruppen als 2×2 (bisher) | 4 | **134k** | 741 px |
| Schnellwahl im Zeitplan (jetzt) | 4 | **34k** | 774 px |
| beide Gruppen je über die volle Zeile | 5 | 34k | 799 px |

Die 34k, die bleiben, stehen unter „Beschriftung": Der Hinweis unter „Befehl"
ist zwei Zeilen hoch. Das ist der Rest, den zwei Felder nebeneinander immer
haben — 33k davon lassen sich ausrechnen (`(172 − 102) × 477`).

**Zwei Fassungen sind gemessen worden, die nicht gebaut wurden**, und die eine
davon ist die lehrreiche: Dieselbe Zusammenlegung **ohne** `wide` ergibt
**193k** — mehr als der Ausgangszustand. Der Zeitplan bleibt dann in den 540 px
von `.field`, wird dadurch 524 px hoch, und der Schalter „Aktiv" rutscht neben
ihn.

> **Eine Umgruppierung, die die Breite nicht mitnimmt, verschiebt die leere
> Fläche, statt sie zu schliessen.**

**Und die Zusammenlegung ist nicht nur die ruhigere Anordnung, sondern die
richtige.** Die Schnellwahl stellt den Zeitplan ein — sie füllt genau die fünf
Felder darunter, und `CronScheduleFormTest` rechnet für jede Vorlage nach, dass
ihre Beschriftung das auch sagt.

> **Zwei Gruppen, von denen die eine nur die andere füllt, sind eine.**

`wide` ist keine neue Marke: `.field.wide` steht seit `docs/53` Befund 9 in
`app.css`, für den Editor. Ihr Kommentar nennt jetzt beide Fälle. **Damit ist
keine Regel angefasst worden, die über diese Seite hinausgeht** — die dritte
volle Bilderrunde aus §7.4 bleibt erspart.

### 9.4 Ein Artefakt des Messmittels, benannt statt verschwiegen

Der Auszug aus `Cron.vue` streicht die Vue-Direktiven; damit fällt auch das
`v-if` von „Abbrechen" weg, und die Knopfreihe ist bei 390 px 98 statt 44 px
hoch. Das ist eine Eigenschaft des Auszugs und keine der Seite — bei 1440 px
passen beide Knöpfe in eine Zeile, und dort stehen in beiden Fassungen 38 px.

> **Ein Auszug, der Bedingungen wegwirft, zeigt alle Zweige zugleich.**

### 9.5 Damit ist die Reihenfolge aus §7.4 abgearbeitet

Vier Gruppen, siebzehn Befunde behoben, jeder mit einem Wächter und jeder
Wächter von Hand gebrochen. Als Nächstes stehen die beiden Wünsche an —
**Wunsch 2** (Schlüssel erzeugen, §5.4 zuerst messen; die dritte Messung kann
nur der Betreiber machen) und **Wunsch 3** (Suchleiste, §6.3 zuerst messen).

**Was ausdrücklich offen bleibt**, unverändert aus §7.4: die vollständige
Umkehrung der Abstandsregel (`* + *`), die neunzehn ungeprüften Griffe in
`Databases/Console.vue` und die 57 Felder ohne `id` und `name`.

---

## 10. Gebaut — Wunsch 2: Schlüssel im Panel erzeugen

Gebaut am 20. August 2026, nach den Messungen aus §5.6. Zwei Entscheidungen des
Betreibers tragen es: Punkt 3 durfte **hier** gemessen werden (ein Wegwerfpaar
zu einer Formatfrage gibt Zugang zu nichts), und erzeugt wird **nur Ed25519** —
entgegengenommen wird weiterhin alles, was heute geht.

### 10.1 Wo die Handlung steht

Die Frage aus `CLAUDE.md`, gestellt vor dem Merkmal und nicht danach: **Wo sucht
jemand das?** Dort, wo er nach einem Schlüssel gefragt wird — also im Bereich
„Schlüssel eintragen", neben dem Feld, in das er ihn sonst einfügt. Kein eigener
Bereich darunter: Wer keinen Schlüssel hat, merkt es genau hier.

Damit ist es zugleich die Antwort auf Befund 14 der Cronseite: eine Gruppe statt
zweier, weil die zweite nur die erste füllt.

### 10.2 Der Ablauf

1. Ein Satz **vor** dem Knopf: Der private Teil entsteht auf dem Gerät und wird
   **einmal** angeboten. Danach gelesen wäre er eine Feststellung.
2. „Schlüssel erzeugen" erzeugt, füllt das Feld mit dem öffentlichen Teil und
   schickt ihn auf demselben Weg wie eine Eingabe von Hand.
3. **Erst nach `onSuccess`** erscheint der private Teil. Wer ihn sähe, ohne dass
   der öffentliche angekommen ist, hielte einen Schlüssel für ein Schloss, das
   es nicht gibt.
4. Der Bereich holt sich ins Bild — über `watch` und nicht über den Klick, denn
   dazwischen liegt eine Antwort des Servers.
5. „Herunterladen" über `Blob` und `<a download>`; der Weg über den Server
   existiert nicht.

Kann der Browser kein Ed25519, steht dort statt des Knopfes der Satz, wie man
den Schlüssel auf dem eigenen Rechner erzeugt. Kein roter Rand an einem Feld:
Das Feld ist nicht falsch, der Browser kann es nicht.

### 10.3 Gemessen an der Ansicht

Echtes Markup aus `Sftp.vue`, gebautes Stylesheet, vier Lagen:

| Lage | `dokument` | Gegenprobe | Schlüsselfeld |
|---|---|---|---|
| 390 hell | 0 | **200** | 358×236 |
| 390 dunkel | 0 | **200** | 358×236 |
| 1440 hell | 0 | **200** | 1140×223 |
| 1440 dunkel | 0 | **200** | 1140×223 |

**Zwei Fassungen der Höhe, und die erste war falsch.** `.code` bringt die
Editorhöhe von 60dvh mit — bei 844 px Fenster sind das 506 px für einen Inhalt
von neun Zeilen, also ein Kasten, der zu zwei Dritteln leer ist. Dieselbe leere
Fläche wie in Befund 14, an einer anderen Stelle.

> **Eine Höhe, die für unbekannte Länge gemacht ist, ist für bekannte falsch.**

**Und die erste Fassung rollte in sich selbst.** `.code` steht auf
`white-space: pre`; bei 390 px waren das gemessen **330 px** waagerechtes Rollen
im Kasten, bei einem `dokument` von 0. Genau der Fall aus `docs/48`: eine Zelle,
die rollen darf, hat keine Zahl, die sich beschwert. Ein erzeugter Schlüssel
wird nicht Zeile für Zeile gelesen, sondern als Ganzes genommen — er darf
brechen.

> **Ein Feld, dessen Inhalt man als Ganzes nimmt, braucht keine Spalten.**

### 10.4 Was der Wächter hält — und was er nicht halten kann

`PrivateKeyTest` hält die eine Zusage, die dieses Merkmal trägt:

- Der Baustein kennt **kein** Transportmittel — kein `fetch`, kein `router.`,
  kein `sendBeacon`. Das ist die stärkste Fassung, weil sie an keiner
  Schreibweise hängt.
- Auf der Seite steht der private Teil auf keiner Zeile, die etwas verschickt —
  **zeilenweise**, denn die Datei *muss* `form.post` enthalten.
- Das Formular trägt genau zwei Felder.
- Er wird genau einmal gesetzt.
- Die Messung bindet **den ausgelieferten Baustein** ein und keine Abschrift.

**Zwei der fünf Eingriffe im Bruchskript sind beim ersten Versuch durchgegangen,
und beide trafen genau das, was der Wächter halten soll.**

Der erste: `form.key = privaterTeil.value` — eine **Zuweisung**, kein Versand.
Die Liste führte nur `form.post`, `form.put`, `form.patch`; der Schlüssel reist
eine Zeile später mit, und dort steht er nicht mehr.

> **Ein Wächter, der auf den Versand sieht, verpasst das Einpacken.**

Der zweite: Die Einbindung zeigte auf eine Abschrift, und der Wächter blieb
grün — weil der Kopf der Messdatei den Baustein ohnehin im Text nennt.

> **Ein Wächter, der einen Satz sucht statt seiner Erreichbarkeit, ist grün,
> sobald der Satz irgendwo steht.**

Derselbe Satz steht seit `docs/62` im Projekt.

### 10.5 Der Nebenfund: `RevealTest` sah ein Drittel seiner Griffe nicht

Der neue Knopf heisst `@click="erzeugen"` — **ohne Klammern**. Der Wächter
suchte `@click="name("`, also nur Griffe mit einem Wert, und ging an ihm vorbei.
Neunundzwanzig solcher Griffe gibt es in `resources/js`.

> **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat nicht wenig
> gemessen — er hat an dieser Stelle gar nicht gemessen.**

Derselbe Satz wie bei Befund 15, zwei Stunden später und an einer anderen Datei.
Erweitert hat der Wächter dann vier Dinge gefunden:

| | was |
|---|---|
| `PasswordFields.vue generate touched` | eine offene Frage — steht in `UNEXAMINED`, ungemessen |
| `Files/Index.vue startSearch searching` | derselbe Bereich, unter einem zweiten Namen; einmal erfasst, einmal nicht |
| `Files/Index.vue startPack archiveName` | steht an seinem Platz |
| `Operations/Show.vue cancel cancelRequested` | steht an seinem Platz |

**Und vier der neunzehn gezählten Löcher gab es nie.** Zwei entstanden daraus,
dass der Ausdruck `openTable.value === null` für eine Zuweisung hielt — `=` ist
auch das erste Zeichen von `===`. Zwei weitere setzen ihren Wert nur auf `null`,
machen also zu und öffnen nichts.

> **Eine Zahl, die offene Fragen zählt, zählt auch die erfundenen mit.**

Dieselbe Zeile habe ich eine Stunde später in `PrivateKeyTest` noch einmal
geschrieben, und dort hat sie drei Zuweisungen gezählt, wo eine steht.

> **Ein Ausdruck, der eine Zuweisung sucht, findet jeden Vergleich mit, solange
> er das Gleichheitszeichen nicht abgrenzt.**

### 10.6 Was offen bleibt

- **Der Download ist nicht im Container geprüft.** `Blob` und `<a download>`
  brauchen die laufende Seite. Er gehört in den nächsten Lauf auf `cloudsrv24`.
- **RSA und ECDSA werden nicht erzeugt** — Entscheidung des Betreibers,
  entgegengenommen werden sie weiter.
- **`PasswordFields.vue generate touched`** ist ungemessen und steht als offene
  Frage in `RevealTest::UNEXAMINED`.
- **Wunsch 3** (die Suchleiste) — die zwei Messungen aus §6.3 zuerst.

---

## 11. Gebaut — Wunsch 3: die Suchleiste

Gebaut am 20. August 2026. Zwei Entscheidungen des Betreibers tragen es: **die
Leiste als eigene Zeile ab 720 px**, darunter bleibt der Knopf — und **die
Kopfleiste bekommt das Kästchen „auch im Inhalt"**.

### 11.1 Was die Form entschieden hat

Nicht der Geschmack, sondern §6.4: Neben den drei Knöpfen ist die Leiste erst ab
1728 px umsonst und kostet an den häufigen Breiten +101 px (1280) und +53 px
(1440). Als eigene Zeile kostet sie gleichmässig eine Zeile — an genau diesen
Breiten also weniger. Bei 390 px ist eine Leiste ohnehin keine: Dort stapelt
alles in voller Breite.

Die Schwelle steht **nur in `app.css`**. Die breite Fläche ist die Vorgabe, die
schmale weicht ab — so gibt es keine Breite, an der beide Regeln zugleich
greifen. Bei `max-width: 720px` neben `min-width: 720px` wäre das genau 720 px,
und dort stünde weder Leiste noch Knopf.

### 11.2 Gemessen an der fertigen Ansicht

Sechzehn Lagen, zwei Pfadlängen; der ungünstige Fall ist 78 Zeichen.

| Pfad | Zustand | 390 px | 720 px | 1280 px | 1440 px |
|---|---|---|---|---|---|
| kurz | zu | 331 px | 169 px | 187 px | 187 px |
| kurz | offen | 527 px | 237 px | 187 px | 187 px |
| lang | zu | 331 px | 169 px | 208 px | 208 px |
| lang | offen | 569 px | 310 px | 208 px | 208 px |

`dokument` ist in allen sechzehn Lagen **0**, die Gegenprobe **200/200**. Bei
390 px und 720 px ist der geschlossene Zustand auf das Pixel der heutige — dort
ändert sich nichts. Der geöffnete ist mit 527 px sogar **acht Pixel kürzer als
heute** (535 px), obwohl er den Ort und das Kästchen dazubekommen hat.

### 11.3 Zwei Regeln, die ich geschrieben habe und die nicht taten, was sie sollten

**Die erste war schädlich.** `.search .field.inline { flex: 1 1 240px }` sollte
das Feld den freien Platz nehmen lassen. Eine Flex-Grundgrösse gilt für die
**Hauptachse** — in einer Reihe ist das die Breite, bei 390 px steht die Leiste
aber als Spalte, und das Feld wurde **240 px hoch**: gemessen 358×240 statt
358×73, mit einer leeren Fläche zwischen Eingabe und Kästchen.

> **Eine Flex-Grundgrösse ist eine Breite oder eine Höhe, je nachdem, wie die
> Reihe gerade steht.**

Aufgefallen ist es nicht am Bild, sondern an der Zahl: Ein kurzer und ein langer
Pfad ergaben **exakt dieselbe** Höhe von 738 px.

> **Eine Regel, die für zwei verschiedene Inhalte dasselbe misst, misst nicht
> den Inhalt.**

**Die zweite tat gar nichts.** Die berichtigte Fassung (`flex-grow: 1;
min-width: 240px`) wurde bei 1440 px von `.field { max-width: 540px }`
weggeschnitten — einer Regel mit gemessener Begründung, die zwei Bildschirme
weiter oben steht. Mit Regel wie ohne blieb das Feld auf 540 px. Sie ist wieder
raus; an ihrer Stelle steht, warum.

### 11.4 Und eine Messung, die eine Stunde lang das Falsche gemessen hat

Nach einem `npm run build` heisst die gebaute CSS-Datei anders. Der Prüfstand
zeigte auf die alte, fand sie nicht — und meldete Zahlen, die nach „kein Stil"
aussahen: `label.field.inline 319×17`, `align=normal`.

> **Ein Aufsatz, der auf ein gebautes Stylesheet zeigt, zeigt nach dem nächsten
> Bau ins Leere — und misst weiter.**

Der Prüfstand baut seine Seiten seitdem in einem Schritt mit der Messung und
besteht darauf, dass es **genau ein** Stylesheet gibt.

### 11.5 Was die Wächter dabei gefunden haben

`ButtonStyleTest` und `BlockSpacingTest` haben beide zugebissen, und beide zu
Recht: Die Knopfklasse hiess `.search-toggle` statt `.button.search-toggle`.
`ButtonStyleTest` **liest** die bekannten Knopfvarianten aus `app.css`, statt
sie aufzuzählen — die Regel richtig zu schreiben, macht sie ihm bekannt. Eine
Zeile in einer Liste im Test wäre der zweite Ort für dieselbe Sache gewesen.

`RevealTest` hat den Griff verloren, als der Schalter von `v-if` auf `:class`
wechselte — nicht, weil er in Ordnung war, sondern weil das Mittel gewechselt
hatte.

> **Ein Wächter, der ein Mittel prüft statt einer Wirkung, hört auf zu messen,
> sobald jemand das Mittel wechselt.**

Erweitert um `v-show` und `:class` hat er sofort einen Griff gefunden, den er
noch nie gesehen hatte: `toggleActions` im Dateimanager klappt seine Knopfreihe
über eine Klasse auf.

**Und der neue Wächter war beim ersten Lauf rot wegen eines Wortes in meinem
eigenen Kommentar.** `FileSearchTest` verbietet `matchMedia` in `Index.vue` —
und dort steht die Begründung, warum es *kein* `matchMedia` gibt. Dasselbe
Missverständnis wie bei `RootElementTest` am selben Tag, nur andersherum: Dort
machte ein Kommentar den Wächter grün, hier rot.

> **Ein Wächter, der eine Datei liest, liest auch, was jemand über sie
> geschrieben hat.**

### 11.6 Was offen bleibt

- **Der Blick auf die echte Seite.** Gemessen und fotografiert ist der
  Aufsatz — echtes Markup, gebautes Stylesheet, aufs Pixel (`docs/56`). Die
  laufende Seite mit echten Daten gehört in den nächsten Lauf auf `cloudsrv24`,
  zusammen mit dem Herunterladen aus Wunsch 2.
- **Die Suche im ganzen Abonnement** gibt es weiterhin nicht. §6.2 hat vermutet,
  dass jeder sie fragen wird, sobald die Leiste da ist; das ist eine Vermutung
  und bleibt eine, bis jemand fragt.

---

## 12. Gebaut — die Kopfleiste des Dateimanagers auf dem Telefon

**Gemeldet vom Betreiber am 20. August 2026**, mit einem Bild von `cloudsrv24`:
Die vier Knöpfe stehen bei 390 px gestapelt und nehmen **225 px** ein — vier
Zeilen, bevor eine einzige Datei zu sehen ist. Die Frage war, ob sich daraus
Zeichen nebeneinander machen lassen.

### 12.1 Sechs Formen, gemessen

Echtes Markup, gebautes Stylesheet, 390 px, nur der Seitenkopf. `dokument` in
allen Fällen 0, Gegenprobe 200/200.

| Form | Höhe | Zeilen | |
|---|---|---|---|
| heute, gestapelt | 225 px | 4 | |
| nur umbrechen, gleiche Wörter | 215 px | 3 | −10 |
| Zeichen **neben** dem Wort | 215 px | 3 | −10 |
| ein Wort je Knopf, umbrechend | 161 px | 2 | −64 |
| **Zeichen über dem Wort** | **119 px** | **1** | **−106** |
| nur Zeichen | 107 px | 1 | −118 |

Zwei Zahlen tragen die Entscheidung.

> **Ein Zeichen, das neben seinem Wort steht, kostet die Breite des Wortes noch
> einmal. Erst über dem Wort kostet es nichts.**

Und die Form, nach der gefragt war — reine Zeichen — spart **zwölf Pixel** mehr
und kostet die Beschriftung. Das schliesst eine Regel aus, die dieses Projekt
zweimal aufgeschrieben hat: `NavIcon.vue` in seinem eigenen Kopf („Sie tragen
keine Bedeutung allein … WCAG 1.1.1") und der Befund des Betreibers vom
7. August an der Domainauswahl. Entschieden hat der Betreiber gegen die zwölf
Pixel.

### 12.2 Was gebaut ist

`ActionIcon.vue` ist ein **zweiter geschlossener Satz** neben `NavIcon` — vier
Zeichnungen, dasselbe 24er-Raster, dieselbe Strichstärke, keine Fläche. Er ist
nicht Teil von `NavIcon`, weil `NavIconTest` dessen Satz eins zu eins gegen die
Menüpunkte hält; eine Zeichnung für einen Knopf wäre dort eine Waise.

Das **Plus** in den beiden Anlegen-Zeichnungen trägt das Verb mit. Sichtbar
steht auf der schmalen Fläche nur das Objekt („Verzeichnis", „Datei"); der Rest
des Satzes steht als `.verb` daneben und ist **nicht** `display: none` —

> **Ein Wort, das man aus dem Bild nimmt, nimmt man auch aus dem Namen, wenn man
> `display: none` dafür benutzt.**

— sondern aus dem Bild geklippt. Der Knopf heisst damit überall „Verzeichnis
anlegen", und WCAG 2.5.3 ist erfüllt, weil der sichtbare Text darin vorkommt.

### 12.3 Zwei Fehler beim Bauen, beide von der Messung gefunden

**Die Schwelle stand zuerst auf 480 px** — dort, wo `.button-row` ohnehin
stapelt. Gemessen ergab das eine Kante: **480 px → 120 px, 481 px → 215 px.**
Über der Schwelle kommen die vollen Wörter zurück, und die passen erst ab rund
690 px Inhaltsbreite in eine Zeile.

> **Eine Schwelle, hinter der es schlechter wird als davor, steht an der
> falschen Stelle.**

Sie steht jetzt auf 720 px — dem Haltepunkt, den dieses Stylesheet ohnehin führt.

**Und der Suchknopf wechselte sein Wort.** „Zuklappen", solange die Leiste offen
ist — dieselbe Form wie „Aktionen zuklappen" in der Tabelle darunter. In einer
Reihe aus vier Knöpfen, die gerade eben passt, kostet das eine Zeile: Der
Seitenkopf sprang beim Öffnen von 120 px auf **188 px**.

> **Ein Wort, das sich ändert, ändert auch die Breite — in einer Reihe, die
> gerade eben passt, ist das eine Zeile.**

Er ist ausserdem der einzige der vier Griffe, der sein Wort wechseln würde;
„Verzeichnis anlegen" tut es auch nicht. Den Zustand trägt `aria-expanded`.

### 12.4 Das Band, in dem es gilt

| Fenster | Kopfhöhe | Zeilen |
|---|---|---|
| 320 px | 188 px | 2 |
| 360 px | 188 px | 2 |
| **375 px** | **120 px** | **1** |
| 390–720 px | 120 px | 1 |
| über 720 px | unverändert | |

Unter 375 px bricht die Reihe in zwei — immer noch 37 px besser als heute, und
ohne Überlauf. Über 720 px ändert sich **nichts**: Alle neuen Regeln stehen in
`@media (max-width: 720px)`, ausser der einen, die das Zeichen dort ausblendet.

### 12.5 Was ein bestehender Wächter dabei gemeldet hat

`FileCreationTest` suchte „Datei anlegen" im Quelltext und fand es nicht mehr —
dort steht jetzt `Datei<span class="verb"> anlegen</span>`. Der Knopf ist
unverändert da; nur seine Auszeichnung war neu.

> **Ein Wächter, der eine Schreibweise liest, verliert das Feld beim Umschreiben
> — nicht beim Löschen.**

Derselbe Satz wie bei `AttributeNameTest` am selben Tag. Er liest jetzt, was der
Leser sieht, statt was im Markup steht.

### 12.6 Was offen bleibt

- **Der Blick auf die echte Seite**, zusammen mit dem Herunterladen aus Wunsch 2
  und der Suchleiste aus Wunsch 3.
- **Unter 375 px** stehen zwei Zeilen. Ob das jemanden betrifft, ist eine Frage
  an die Wirklichkeit und keine an das Stylesheet.
- Die anderen Seiten dieses Panels haben dieselben gestapelten Knopfreihen. Ob
  die Form dort auch trägt, ist **nicht gemessen** — sie hat hier vier Knöpfe
  mit kurzen Objekten, und das ist nicht überall so.

### 12.7 Nachgetragen — die Regel gilt für jeden Seitenkopf, nicht für diesen

Beim Weiterarbeiten nachgesehen und **nicht** gemeldet bekommen: Der Kommentar
über der neuen Regel hiess „die Kopfleiste des Dateimanagers". Der Selektor
lautet `.page-head .button-row` und trifft **sechzehn Seiten**.

> **Ein Kommentar, der eine Seite nennt, und ein Selektor, der alle trifft — der
> Kommentar ist der Fehler, nicht der Selektor.**

Der Bericht an den Betreiber sagte „über 720 px ändert sich nichts", und das
stimmt; er sagte nicht, dass sich **darunter auf jeder Seite** etwas ändert. Das
ist eine Auslassung und kein Irrtum, und sie wäre erst auf einem Bild
aufgefallen, das niemand vorhatte aufzunehmen.

Nachgemessen bei 390 px, Höhe des Seitenkopfs, vorher gegen nachher. Kein Knopf
schneidet ab, `dokument` überall 0, Gegenprobe 200/200:

| Kopf | vorher | nachher | |
|---|---|---|---|
| Abonnement (Marke + vier Knöpfe) | 263 px | **161 px** | −102 |
| Kunde (Marke + drei Knöpfe) | 209 px | **161 px** | −48 |
| zwei Knöpfe | 117 px | 107 px | −10 |
| Kopf mit Formular und Auswahlfeld | 188 px | 188 px | — |

Die Regel trägt also überall, und der letzte Fall zeigt, wo sie nichts tut: Ein
Formular im Kopf bleibt, wie es war. Dem Dateimanager gehören allein die Zeichen
und das `.verb`; beides ist opt-in und steht nur dort, wo es verlangt wird.

**Das ändert nichts am Ergebnis und hätte den Bericht geändert.** Deshalb steht
es hier und nicht nur im Kommentar.

---

## 13. Der volle Bruchlauf hat vier Löcher gefunden — eines davon im Code

Gefahren am 20. August in der CI, nachdem der PR offen war. **CI grün,
„Wächter brechen" rot:** vier Prüfungen ohne Biss. Alle achtzehn Eingriffe
waren vorher **einzeln von Hand** gefahren worden und bissen.

> **Ein Eingriff, der einzeln beisst, beisst nicht unbedingt im Lauf** — er
> steht dort neben anderen, und die verändern seinen Gegenstand.

### 13.1 Befund 18 war seit seinem ersten Tag wirkungslos

`Files/Index.vue` trug die Zeile `})})`. Die schliessende Klammer des
`renameFor`-Wächters war an die des `picking`-Wächters gerutscht; damit standen
`const asideBlock` und `watch(picking, …)` **innerhalb** des
`renameFor`-Rückrufs. Der Wächter wurde erst registriert, wenn jemand etwas
umbenannte, und `ref="asideBlock"` zeigte auf nichts.

**Nichts davon war rot.** Es ist gültiges JavaScript: `vue-tsc` und
`npm run build` liefen durch — gegengeprüft am Stand von `557a934`, die
Fehlerliste war leer. Jeder Wächter fand jedes Wort, das er suchte.

> **Ein Wächter, der Wörter liest, sieht keine Klammern.**

Der Typprüfer meldete es, **sobald** die Klammer richtig sass: `picking` wurde
dann vor seiner Deklaration benutzt.

> **Ein Fehler, der in einer Funktion sitzt, wird vom Typprüfer entschuldigt —
> die Funktion läuft ja später.**

`RevealTest::test_every_watch_is_registered_at_the_top_level` zählt seitdem die
Klammertiefe jedes `watch(` in `<script setup>`, an Zeichenketten und
Kommentaren vorbei.

### 13.2 Und derselbe Fehler versteckte einen zweiten

Der Eingriff zu Befund 18 biss nicht mehr, weil `RevealTest` fragte, ob die
**Datei** irgendwo `bringIntoView` enthält. In `Files/Index.vue` stehen drei
solche Wächter; einer genügte, um alle drei für verdrahtet zu erklären.

> **Ein Wächter, der die Datei fragt statt die Stelle, wird mit jeder zweiten
> Stelle stumpfer.**

Von Hand geprüft hatte ich ihn, als es die anderen beiden noch nicht gab.
Gefragt wird jetzt der Rumpf genau dieses `watch` — und damit beisst auch der
Eingriff auf `renameBlock`, der vorher stumm war.

### 13.3 Zwei Eingriffe lagen ausserhalb des Rückwegs

Meine beiden Eingriffe zu `AttributeNameTest` brechen `lang/de/validation.php`.
`BAEUME` kannte `lang/` nicht — die Datei blieb nach dem Bruch kaputt stehen,
und beide Gegenproben meldeten „nicht wieder grün". Genau der Satz, den das
Skript in seinem eigenen Kopf führt:

> **Ein Bruch, der eine Datei ausserhalb des Rückwegs anfasst, wird nicht
> zurückgenommen — und vergiftet jeden Lauf danach.**

**Der Wächter darüber war dabei grün**, und das ist der eigentliche Fund:
`BreakScriptTest` liest den gesuchten Text aus dem `s.replace("…")`-Aufruf.
**52 von 562 Blöcken** halten ihn in einer Variablen — und die waren für ihn
nicht vorhanden, weder für die Frage nach dem Rückweg noch für die, ob ihr
Griff noch greift.

> **Ein Wächter, der eine Schreibweise liest, sieht die andere nicht — und
> meldet für sie „alles in Ordnung".**

Aufgelöst kommen jetzt 562 Blöcke an; vier bleiben unlesbar, weil sie ihren Text
erst umformen. Der erste Anlauf der Auflösung hat dabei prompt einen
**Fehlalarm** erzeugt — er löste `alt.replace('Eintraege', 'Einträge')` zu `alt`
auf und meldete einen lebenden Eingriff als tot.

> **Ein Wächter, der Fehlalarm gibt, wird abgeschaltet.**

Aus den 52 neu sichtbaren Blöcken kam danach **kein** weiterer toter Eingriff.

### 13.4 Und ich habe beim Prüfen eigene Arbeit weggeworfen

Um den Rückweg zu belegen, habe ich `git checkout -- $BAEUME` von Hand
abgesetzt — mit drei ungespeicherten Dateien im Baum. `CLAUDE.md` warnt davor
wörtlich („`git checkout -- resources/` wirft eigene Arbeit weg"), und das
Bruchskript weigert sich bei schmutzigem `resources/` genau deshalb.

> **Eine Regel, die man kennt, schützt nicht vor dem Handgriff, den man
> nebenbei macht.**

Die drei Änderungen sind neu geschrieben; verloren ist nur Zeit.

### 13.5 Was daraus für den nächsten Lauf gilt

Zwei der vier Regeln lassen sich **nicht** durch einen Eingriff belegen, und das
gehört gesagt statt verschwiegen: Der Eintrag `lang/` in `BAEUME` und die
Auflösung der Variablen stehen beide *im Bruchskript selbst* — und das nimmt
sich vom Rückweg aus. Ein Eingriff darauf bliebe stehen.

> **Was den Prüfstand prüft, kann der Prüfstand nicht mit seinen eigenen Mitteln
> brechen.**

Beide sind stattdessen von Hand gefahren: `lang/` aus `BAEUME` genommen →
`BreakScriptTest` rot; zurück → grün.

### 13.6 Der zweite Lauf: von vier auf eine — und ein dritter Blindfleck

Nach der Behebung meldete der Lauf noch **eine** Prüfung ohne Biss:
`FileCreationTest: der Weg zum Anlegen einer Datei faellt weg`, mit dem
Vermerk „Eingriff hat nichts geändert".

Der Eingriff ist ein `sed -i 's/          Datei anlegen/…/'`, und die
Beschriftung heisst seit der neuen Kopfleiste
`Datei<span class="verb"> anlegen</span>`. Das Muster traf nichts mehr.

**Und `BreakScriptTest` hat auch das nicht gemeldet** — er liest nur
Python-Blöcke. **Sechsundzwanzig** Eingriffe dieses Skripts sind ein `sed -i`,
und die waren für jede seiner Fragen unsichtbar.

> **Ein Wächter, der eine Form von Eingriff liest, sagt über die andere Form
> nichts.**

Das ist derselbe Satz wie in §13.3, nur eine Ebene höher: Dort war es eine
Schreibweise innerhalb der Python-Blöcke, hier eine ganze Bauart daneben.

Gelesen werden jetzt **elf von sechsundzwanzig** `sed`-Eingriffen. Zwölf haben
ein Muster statt eines Textes auf der linken Seite (`^`, `$`, `.*`), drei eine
Adressform — beide bleiben aussen vor und sind **gezählt**:

> **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die kleiner
> werden kann.**

**Der erste Anlauf dieses Ausdrucks war wieder ein Fehlalarm.** Er las aus
`sed -i '0,/class="sections"/s//class="unwrapped"/'` den Dateinamen
`/s//class=` und meldete zwei Befunde über eine Datei, die es nicht gibt. Der
Ausdruck nimmt seitdem nur die eindeutige Form.

**Und die Gegenprobe dazu ist beim ersten Versuch danebengegangen** — ich habe
ein `sed`-Ziel umbenannt, das viermal in `app.css` steht, und nur die erste
Stelle geändert. Der Wächter fand die anderen drei und schwieg zu Recht.

> **Ein Prüfkörper, den es mehrfach gibt, misst nicht, ob der Wächter hinsieht
> — nur, ob er zählen kann.**

Belegt ist er an einem Ziel, das genau einmal vorkommt.

