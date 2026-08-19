# 64 — Das Protokoll der Bilderrunde (P6, Schritt 12)

Die Vorschrift steht in `docs/63`. Dieses Protokoll entsteht **während** des
Laufs; es ist am 19. August 2026 angelegt worden, als die ersten beiden
Ansichten gemessen waren.

**Es ist unvollständig, und das steht in §3.** Von den neun Ansichten sind drei
aufgenommen und keine vollständig gemessen, von den zwanzig Zuständen ist einer
aufgenommen und keiner gemessen. Ein Protokoll, das seine Lücken nicht
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

**Zur Hälfte gemessen** — die beiden 390er Lagen vollständig, die beiden
1440er ohne Gegenprobe.

| Lage | `dokument` | Gegenprobe | `schiebt` |
|---|---|---|---|
| 390 hell | 0 | **200/200** | `thead` 119 · `tr` 91 |
| 390 dunkel | 0 | **200/200** | `thead` 119 · `tr` 91 |
| 1440 hell | 0 | nicht abgelesen | leer |
| 1440 dunkel | 0 | nicht abgelesen | leer |

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
| 390 dunkel | 0 | nicht abgelesen | — |
| 1440 hell | 0 | **200/200** | **leer** |
| 1440 dunkel | 0 | nicht abgelesen | — |

**`thead` und `tr` sind der Mechanismus und kein Fehler.** `.stacks thead` steht
in `app.css` auf `position: absolute; width: 1px; height: 1px; overflow: hidden;
clip-path: inset(50%)` — absichtlich aus dem Bild genommen, damit der
Screenreader die Spaltenüberschriften in der Vorlesereihenfolge behält. Die
gemessenen 350 px sind die Breite dieser Überschriften.

**Der `div` mit 468 px ist die rechte Hälfte von `.split`**
(`Files/Index.vue:722`, Baum links, Liste rechts) — und er ist ein Fund am
**Prüfmittel**, nicht am Panel.

---

## 2. Was dieser Lauf über sein eigenes Prüfmittel gelernt hat

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

**Was dort übrig blieb, ist damit benannt und nicht erklärt.** `.split` ist
reines CSS — `display: block` unter 720 px, darüber ein Grid mit `min-width: 0`
auf beiden Kindern —, und in `Files/Index.vue` gibt es keine Breitenabfrage in
JavaScript. Warum ein Breitenwechsel ohne Neuladen dort 468 px hinterlässt, ist
nicht untersucht; für diesen Lauf genügt, dass ein Neuladen es beseitigt und
dass `docs/63 §4` es seitdem verlangt.

**Kein Fund am Panel.** In keinem der beiden Zustände hat die Seite geschoben.

### `schiebt` ist ein Hinweis und kein Urteil

`docs/63 §5` zählte jeden Eintrag als Fund. Die erste Messung hat das widerlegt:
`.stacks thead` steht dort bei jeder Ansicht mit einer Tabelle, und es ist genau
das, was es sein soll.

> **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein Urteil.**

Das Kriterium ist seitdem `dokument: 0` mit gültiger Gegenprobe; jeder Eintrag
in `schiebt` wird einzeln beurteilt und hier benannt.

---

### Ansicht 3 — Editor (`/subscriptions/140/files/edit?path=/httpdocs/klein.txt`)

**Noch nicht erfüllt** — dieselbe Lücke wie bei Ansicht 1.

| Lage | `dokument` | Gegenprobe | `schiebt` |
|---|---|---|---|
| 1440 dunkel | 0 | nicht abgelesen | leer |
| 1440 hell | 0 | nicht abgelesen | leer |
| 390 hell | 0 | nicht abgelesen | leer |
| 390 dunkel | 0 | nicht abgelesen | leer |

**`schiebt` ist in allen vier Lagen leer** — kein `.stacks thead`, weil diese
Ansicht keine Tabelle trägt, und kein Rest, weil je Breite neu geladen wurde.
Der Rollbehälter des Editors (`.cm-scroller`) taucht nicht unter `rollt` auf:
Die Datei ist vier Zeilen lang und passt.

---

## 3. Was offen ist

- **Sechs Gegenproben**: Ansicht 1 bei 1440 (beide Themes), Ansicht 2 in den
  beiden dunklen Lagen, Ansicht 3 in allen vieren. Keine neuen Bilder — die
  Aufnahmen bleiben gültig, es fehlt allein die Zahl daneben.
- **Sechs der neun Ansichten** — 4 bis 9.
- **Neunzehn der zwanzig Zustände** aus `docs/63 §3`. Gemessen ist einer:
  „Läufe ohne Läufe" (Job C, angelegt und vor dem ersten Lauf aufgenommen) —
  **aber ohne Messzeile und bei Arbeitsplatzbreite**, also noch nicht
  protokollfähig.
- **Die beiden 1440er Lagen von Ansicht 2** mit Neuladen, siehe §2.

**Damit ist Schritt 12 nicht abgeschlossen**, und P6 ist nicht abgenommen.
