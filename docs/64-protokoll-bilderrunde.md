# 64 — Das Protokoll der Bilderrunde (P6, Schritt 12)

Die Vorschrift steht in `docs/63`. Dieses Protokoll entsteht **während** des
Laufs; es ist am 19. August 2026 angelegt worden, als die ersten beiden
Ansichten gemessen waren.

**Es ist unvollständig, und das steht in §3.** Von den neun Ansichten sind zwei
gemessen, von den zwanzig Zuständen einer. Ein Protokoll, das seine Lücken nicht
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

**Erfüllt** in allen vier Lagen.

| Lage | `dokument` | Gegenprobe | `schiebt` |
|---|---|---|---|
| 390 dunkel | 0 | 200/200 | 2 Einträge — siehe unten |
| 390 hell | 0 | 200/200 | 2 Einträge |
| 1440 hell | 0 | 200/200 | **leer** |
| 1440 dunkel | 0 | 200/200 | **leer** |

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

| Lage | `dokument` | Gegenprobe | `schiebt` |
|---|---|---|---|
| 390 dunkel | 0 | 200/200 | `thead` 350 · `tr` 322 · `div` 468 |
| 390 hell | 0 | 200/200 | dieselben drei |
| 1440 hell | 0 | 200/200 | `div` 468 |
| 1440 dunkel | 0 | 200/200 | `div` 468 |

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

**Was dort übrig bleibt, ist nicht geklärt.** `.split` ist reines CSS —
`display: block` unter 720 px, darüber ein Grid mit `min-width: 0` auf beiden
Kindern —, und in `Files/Index.vue` gibt es keine Breitenabfrage in JavaScript.
Geklärt ist allein, dass es übrig bleibt und dass ein Neuladen es beseitigt.
`docs/63 §4` verlangt es seitdem nach **jedem** Breitenwechsel.

**Offen:** die beiden 1440er Lagen von Ansicht 2 mit Neuladen nachmessen. Das
Kriterium hängt nicht daran — `dokument` stand in beiden Zuständen auf 0 —,
aber eine Zahl, deren Herkunft man kennt, ist eine andere Auskunft als eine,
deren Herkunft man vermutet.

### `schiebt` ist ein Hinweis und kein Urteil

`docs/63 §5` zählte jeden Eintrag als Fund. Die erste Messung hat das widerlegt:
`.stacks thead` steht dort bei jeder Ansicht mit einer Tabelle, und es ist genau
das, was es sein soll.

> **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein Urteil.**

Das Kriterium ist seitdem `dokument: 0` mit gültiger Gegenprobe; jeder Eintrag
in `schiebt` wird einzeln beurteilt und hier benannt.

---

## 3. Was offen ist

- **Sieben der neun Ansichten** — 3 bis 9.
- **Neunzehn der zwanzig Zustände** aus `docs/63 §3`. Gemessen ist einer:
  „Läufe ohne Läufe" (Job C, angelegt und vor dem ersten Lauf aufgenommen) —
  **aber ohne Messzeile und bei Arbeitsplatzbreite**, also noch nicht
  protokollfähig.
- **Die beiden 1440er Lagen von Ansicht 2** mit Neuladen, siehe §2.

**Damit ist Schritt 12 nicht abgeschlossen**, und P6 ist nicht abgenommen.
