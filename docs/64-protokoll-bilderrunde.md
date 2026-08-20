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

**Der Vorschlag ist gemessen, nicht gerechnet.** `td` bekommt einen senkrechten
Rand: `padding: 8px 14px 8px 0`. Im Container gegen echtes Chromium, echtes
Markup, gebautes Stylesheet, Dichtestufe `customer` bei 1440 px — dieselbe
Tabelle einmal ohne und einmal mit dem Eingriff, im selben Dokument, damit nur
die eine Zeile CSS sich unterscheidet:

| | ohne | mit |
|---|---|---|
| Zeile mit einer Textzeile — Höhe | **48 px** | **48 px** |
| Zeile mit Ausgabekasten — Höhe | 89 px | 105 px |
| … Abstand des Kastens zur Linie darunter | **0 px** | **8 px** |
| Zeile mit Marke und Rückgabewert — Marke zur Linie darüber | **1 px** | **9 px** |
| … Rückgabewert zur Linie darunter | **1 px** | **9 px** |

**Die erste Zeile ist der Punkt.** Einzeilige Zeilen wachsen nicht — `height`
wirkt an einer Tabellenzelle als Mindestmass, und Textzeile plus Polsterung
bleiben darunter. Der Eingriff kostet also nichts, wo nichts fehlt.

**Die zweite und dritte sind die Befunde selbst, in Zahlen.** `0 px` und `1 px`
— beides ist genau das, was der Betreiber am Bild gesehen hat, und keine der
zwölf Messzeilen dieses Laufs hat es je genannt.

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

Damit stehen für Befund 10 vier Griffe fest — `startChmod`, `startRename`,
`bearbeiten` — und `startPack` sowie die beiden Zielwahlen sind zu prüfen.

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

**Umfang:** 151 Stellen in 30 Vorlagen tragen `class="quiet"`. Die Mehrzahl
steht in einer Zelle und ist in Ordnung; wie viele es nicht tun, ist zu zählen,
bevor die Regel geschrieben wird — eine Klasse, die plötzlich überall wirkt,
ändert auch dort etwas, wo bisher niemand etwas vermisst hat.

**Der Wächter dazu gehört zur Behebung**: Er muss den Unterschied zwischen einer
eigenständigen Regel und einer Nachfahrenregel kennen, sonst fängt er den
nächsten Fall so wenig wie diesen.

---

## 3. Was offen ist

- **Elf Befunde am Panel** — zwei an Ansicht 4, einer an Ansicht 6, drei an
  Ansicht 8, zwei an Ansicht 9, zwei an Zuständen, einer beim Vorbereiten der
  Behebung (§2b). Beheben und nachmessen.

  Sie fallen in **drei** Gruppen und drei Einzelfälle:

  | Gruppe | Befunde | eine Regel |
  |---|---|---|
  | fehlende Nachbarpaare | 2, 3, **4** (zwei Fundstellen) | ein Baustein, der bündig endet, bringt seinen Abstand selbst mit |
  | fehlende Polsterung an `td` | 7, 8 | `padding: 8px 14px 8px 0`, im Container gemessen |
  | Wirkung ausserhalb des Bildes | **10** (zwei Fundstellen, beide Richtungen) | jeder Griff, der einen Bereich öffnet, holt ihn ins Bild |
  | einzeln | 1 (Kästchen), 5 (Codestück), 6 (Dichtestufe), 9 (Ausrichtung), **11** (`.quiet`) | — |
- **Die 27 px an `.stacks thead` der Cronseite** — vier Messungen, 484 · 511 ·
  511 · 484, und weder die Reihenfolge noch der Bestand der Tabelle erklärt sie.
  Vor der zweiten Runde klären, mit dem Messmittel und nicht mit einer
  Überlegung.
- **Die Zahlen zu Befund 8 auf `cloudsrv24` gegenprüfen.** Im Container ist der
  Eingriff gemessen (einzeilige Zeilen bleiben bei 48 px); auf dem Server steht
  er noch aus und gehört in die zweite Runde.
- **Wunsch 1 ist gebaut** und auf `cloudsrv24` noch nicht gefahren: die fünf
  Griffe aus `docs/63 §6b` und der Zustand „Zeitplan als Ausdruck" aus §3.
- **Die Runde danach noch einmal.** Befund 6 ändert die Dichtestufe `customer`,
  und in der stehen alle Aufnahmen dieses Laufs. Erst zu Ende messen, dann alles
  in einer Fassung beheben, dann neu fahren.
- **Kein Zustand mehr** — alle herstellbaren sind gemessen (§1b). Alles andere
  ist gemessen — siehe §1b. „Zugang gestört" und „Abonnement nicht benutzbar"
  bleiben ungemessen, weil sie ausdrücklich nicht hergestellt werden.

**Damit ist Schritt 12 nicht abgeschlossen**, und P6 ist nicht abgenommen.
