# 64 — Das Protokoll der Bilderrunde (P6, Schritt 12)

Die Vorschrift steht in `docs/63`. Dieses Protokoll entsteht **während** des
Laufs; es ist am 19. August 2026 angelegt worden, als die ersten beiden
Ansichten gemessen waren.

**Es ist unvollständig, und das steht in §3.** Von den neun Ansichten sind
**sieben** aufgenommen und vollständig gemessen, von den zwanzig Zuständen ist
einer aufgenommen und keiner gemessen. Zwei Befunde am Panel stehen offen. Ein Protokoll, das seine Lücken nicht
nennt, liest sich wie eine Abnahme.

| | |
|---|---|
| Maschine | `cloudsrv24` |
| Panel | `v0.6.0-rc.18` |
| Abonnement | 140 (`p6-abnahme.invalid`, Systembenutzer `p1139`) |
| Messmittel | `tests/bilder-messen.js` |
| Breiten | 390 × 844 und 1440 × 900, Geräteansicht |

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

## 3. Was offen ist

- **Zwei der neun Ansichten** — 8 und 9.
- **Drei Befunde am Panel** — zwei an Ansicht 4, einer an Ansicht 6. Beheben
  und nachmessen. Der dritte und der zweite sind derselbe Fehler in zwei
  Richtungen; die Behebung sollte die Regel treffen und nicht den Einzelfall.
- **Neunzehn der zwanzig Zustände** aus `docs/63 §3`. Gemessen ist einer:
  „Läufe ohne Läufe" (Job C, angelegt und vor dem ersten Lauf aufgenommen) —
  **aber ohne Messzeile und bei Arbeitsplatzbreite**, also noch nicht
  protokollfähig.

**Damit ist Schritt 12 nicht abgeschlossen**, und P6 ist nicht abgenommen.
