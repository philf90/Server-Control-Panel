# 29 — Rework der Optik: Plan

> **Zum Ansehen:** [`29-optik-rework.html`](29-optik-rework.html) — die Entwürfe
> in beiden Themes, breit und bei 390 px. Diese Datei begründet sie.

Der Auftrag in einem Satz: Die heutige Fläche ist zu schmal, zu kleinlich, zu
beschränkt und macht Dinge kompliziert; sie soll offen, freundlich und
technisch informativ werden und die vorhandene Fläche ausnutzen.

**„Leitstand" bleibt.** Dunkel, instrumentenhaft, Zahlen in Monospace, Farbe
bedeutet etwas oder wird nicht benutzt — das ist nicht das Problem. Was sich
ändert, ist der **Maßstab**, der **Raum** und die Frage, **wo ein Baustein
steht**.

---

## 1. Was gemessen wurde

Zehn Befunde. Sechs davon widersprechen keiner Regel, sondern zeigen eine
fehlende — und das sind die teuren.

| # | Befund | Gemessen |
|---|---|---|
| 1 | **Der Rand eines Eingabefeldes ist unsichtbar.** 11 Seiten setzen `border: 1px solid var(--line)` an `input`, `select`, `textarea`. | **1,13 : 1** dunkel · **1,09 : 1** hell |
| 2 | **`--row-height` trägt die Dichtestufe — und wird von zwei Seiten benutzt.** | **2 / 26** |
| 3 | **Jede Seite baut ihre eigene Tabelle und ihr eigenes Formular.** 10 Seiten definieren `table`, 11 definieren `input`, 4 definieren `.section`. | **1322 Zeilen** Seiten-CSS |
| 4 | **Drei Fassungen des Balkens, vier der Zustandsmarke.** `.bar` · `.balken` · `.fuellung` — `.badge` · `.chip` · `.marke` · `.status` | **3 und 4** |
| 5 | **Eine Klasse zeigt auf eine CSS-Regel, die es nicht gibt.** `Tile.vue` schreibt `class="series empty"`, das CSS kennt `.series.leer`. | tot |
| 6 | **Die Schriftskala drängt drei Rollen in 2,5 px.** 10,5 · 11 · 12 · 13 · 16 | **2,5 px** über drei Rollen |
| 7 | **§7.2 sagt „Fünf Größen, und keine sechste" — und listet darunter sechs.** app.css führt sieben. | 5 / 6 / 7 |
| 8 | **§7.2 sagt „Schriftgrößen nicht nach Dichte gestaffelt" — und `--block-heading-size` ist es.** | 13 px / 15 px |
| 9 | **§7.2 verlangt 3 px Radius, „kein größerer Wert, nirgends".** Gebaut sind 3, 5, 6, 8 und 999 px. | **0 / 26** Seiten halten ihn |
| 10 | **Die Seitenleiste ist 186 px — und das eigene Zeichen passt nicht hinein.** | **177–190 px** gebraucht, **158 px** da |

### Was daran das Muster dieses Projekts ist

Befund 1, 2 und 5 sind derselbe Fehler, den `CLAUDE.md` als den
wiederkehrenden benennt: *eine Zeichenkette, die auf etwas verweist, ohne dass
ein Typ, ein Test oder ein Werkzeug den Bezug prüft.*

- Befund 1 ist **wörtlich der Knopf-Fund von 1,04 : 1 noch einmal**, nur an
  `input` statt an `button`. Der Wächter, den der Knopf damals bekam, prüft
  `--button-line` — und nur die. Die Regel dahinter (WCAG 1.4.11) gilt für die
  Grenze *jedes* Bedienelements.
- Befund 5 ist **wörtlich der `knopf betont`-Fund noch einmal**, nur außerhalb
  von Knöpfen. `ButtonStyleTest` prüft seit P3, dass jede Knopfklasse in
  app.css existiert. Für jede andere Klasse fragt niemand.
- Befund 2 ist eine Vorgabe aus §7.2, für die nie ein Wächter gebaut wurde. Die
  Kundenfläche ist auf 24 von 26 Seiten nicht ruhiger als die Adminfläche, und
  gemerkt hat es niemand.

Befund 7, 8 und 9 sind eine dritte Sorte: **Die Vorgabe widerspricht sich
selbst oder dem Code, und der Test wurde um den Widerspruch herumgebaut.**
`DesignTokensTest` lässt `--block-heading-size` ausdrücklich durch — die
Ausnahme steht im regulären Ausdruck. Damit hält der Test die Regel nicht mehr
fest, sondern ihre Verletzung.

---

## 2. Die Richtung

Drei Sätze, aus denen alles Weitere folgt:

1. **Der Maßstab wächst.** Fließtext 15 px statt 13, Tabelle 13,5 statt 12,
   Seitenüberschrift 22 statt 16, Kachelzahl 30 statt 22. Acht Rollen mit
   erkennbarem Abstand statt fünf, von denen drei in 2,5 px liegen.
2. **Die Fläche wird gerastert, nicht gefüllt.** Bereiche stehen nebeneinander,
   sobald sie nebeneinander passen; Kacheln füllen ihre Reihe. Aber:
   **Fließtext behält seine Zeilenlänge** (`--prose: 68ch`). Fläche ausnutzen
   heißt nicht, einen Satz über 1900 px zu ziehen.
3. **Ein Baustein steht an einer Stelle.** Tabelle, Formularfeld, Bereich,
   Zustandsmarke, Balken, Kennzahl, leerer Zustand — heute je vier bis elf
   Fassungen über 32 Dateien, künftig in `app.css` und in Komponenten.

Der dritte Satz ist der eigentliche Rework. Die ersten beiden sind sichtbar,
der dritte ist der Grund, warum die ersten beiden nicht in einem halben Jahr
wieder auseinanderlaufen.

### Was der Entwurf im Browser gelernt hat

Zwei Fehler standen im ersten Entwurf und sind erst in der Aufnahme aufgefallen:

- **`grid-template-columns: repeat(auto-fit, minmax(…, 1fr))` war die falsche
  Antwort.** Ein Raster hält seine Spaltenzahl über alle Zeilen: Sobald eine
  Reihe nur einen Bereich trägt, steht er in *einer* Spalte, und zwei Drittel
  der Zeile bleiben leer. „Dateisysteme" stand so allein mit 900 px Leerraum
  daneben — derselbe Vorwurf wie heute, nur in neuer Gestalt. Flexbox verteilt
  den Rest an die Bereiche, die in der Reihe stehen.
- **Kennungen brachen um.** `41 200 MB` und `3 von 10` standen zweizeilig da,
  sobald ein Bereich schmal wurde. Eine Kennung ist ein Wert und keine zwei
  Zeilen.

Beides steht als Kommentar im Entwurf. Es ist genau der Grund, warum die
Vorgabe „im Browser nachsehen, nicht nur bauen" in `CLAUDE.md` steht.

---

## 3. Die Marken

### Schrift — acht Rollen, je eine Marke

| Marke | heute | neu | Rolle |
|---|---|---|---|
| `--text-label` | 10,5 px | **11 px** | Versalien: Spaltenkopf, Kachelbeschriftung |
| `--text-small` | 11 px | **12,5 px** | Feldbeschriftung, Hinweis, Fehlertext |
| `--text-table` | 12 px | **13,5 px** | Tabellenzelle |
| `--text-body` | 13 px | **15 px** | Fließtext, Knopf, Eingabe |
| `--text-section` | — | **17 px** | Bereichsüberschrift |
| `--text-heading` | 16 px | **22 px** | Seitenüberschrift |
| `--text-metric` | 22 px | **30 px** | die große Zahl auf einer Kachel |
| `--text-input` | 13 / 16 px | **15 / 16 px** | Eingabefeld (schmal: 16, siehe docs/24 §3) |

`--block-heading-size` fällt weg. `--text-section` tritt an seine Stelle und ist
**nicht nach Dichte gestaffelt** — damit gilt §7.2 an dieser Stelle zum ersten
Mal.

### Farbe — zwei neue Marken, sonst dieselbe Palette

| Marke | dunkel | hell | Warum |
|---|---|---|---|
| `--control-line` | `#68798b` | `#6f7d8b` | Die Grenze **jedes** Bedienelements — Knopf, Feld, Auswahl. Ersetzt `--button-line` und behebt Befund 1. |
| `--control-bg` | `#1d2836` | `#ffffff` | Die Fläche eines Bedienelements. Ersetzt `--button-bg`. |
| `--field-bg` | `#10171f` | `#ffffff` | Ein Feld liegt auf dunklem Grund vertieft, auf hellem bündig. |
| `--surface` | `#111922` → `#151f2a` | unverändert | Karten waren gegen den Grund kaum zu sehen (1,27 : 1). |
| `--surface-border` | `#1c2733` → `#2b3846` | `#dbe2e8` → `#c9d4df` | Ein Bereichsrand, den man sieht. |

Gerechnet, nicht gewählt:

```
--control-line gegen --bg        4,30 : 1  dunkel   3,72 : 1  hell
--control-line gegen --surface   3,72 : 1           4,22 : 1
--control-line gegen --control-bg 3,33 : 1          4,22 : 1
--text auf --control-bg          9,40 : 1          11,46 : 1
```

WCAG 1.4.11 verlangt 3 : 1. Alle Zustandsfarben und Textmarken bleiben
unverändert und erreichen weiter mindestens 4,5 : 1.

### Raum

| Marke | heute | neu | Warum |
|---|---|---|---|
| `--radius` | — (3/5/6/8 px verstreut) | **6 px** | Knopf, Feld, Marke |
| `--radius-card` | — | **10 px** | Bereich, Kachel |
| `--nav-width` | 186 px fest | **232 px** | damit Zeichen, Schriftzug und Version in eine Zeile passen |
| `--tile-min` | `--tile-columns: 4` | **210 / 270 px** | eine Spaltenzahl ist eine Behauptung über die Bildschirmbreite |
| `--bereich-min` | — | **360 / 420 px** | ab hier stehen zwei Bereiche nebeneinander |
| `--prose` | — | **68ch** | die Grenze, die nur für Fließtext gilt |
| `--row-height` | 34 / 42 px | **40 / 48 px** | und wirkt künftig auf jeder Tabelle |

---

## 4. Die Bausteine

§7.2 zählt sie unter „Bausteine, die in P1 fertig werden" auf. Sie wurden
fertig — je Seite einmal. Künftig:

| Baustein | Wo | Ersetzt |
|---|---|---|
| Tabelle (`table`, `th`, `td`, `.rollt`, `.stapelt`) | `app.css` | 10 Seitenfassungen, davon zwei unvereinbare |
| Formularfeld (`.feld`, `input`, `select`, `textarea`, `.hinweis`, `.fehler`) | `app.css` | 11 Seitenfassungen |
| `Bereich.vue` — Karte mit Überschrift, Erklärsatz, Aktionsreihe | Komponente | 4 Fassungen von `.block`/`.section` |
| `Marke.vue` — Zustandsmarke, immer mit Wort | Komponente | `.badge` · `.chip` · `.marke` · `.status` |
| `Balken.vue` — Anteil mit Zahl daneben | Komponente | `.bar` · `.balken` · `.fuellung` |
| `Kennzahl.vue` — die Zahl mit Beschriftung und Zusatz | Komponente | `.zahl` aus der Übersicht |
| `Leer.vue` — leerer Zustand mit Satz | Komponente | heute je Seite ein `<p>` |

Erwartung: Die 1322 Zeilen Seiten-CSS gehen auf **unter 400** zurück. Was
bleibt, ist das, was wirklich nur eine Seite betrifft.

---

## 5. Was mit den acht Wächtern geschieht

**Keine Regel fällt ersatzlos.** Zwei ändern sich, und beide, weil sie
nachweislich falsch waren — nicht, weil sie im Weg stehen.

| Wächter | Was geschieht |
|---|---|
| **ButtonStyleTest** | **erweitert.** Der Kontrasttest kennt nur `--button-line`; die Regel dahinter gilt für jedes Bedienelement. Er liest künftig **jede `*-line`-Marke** und rechnet sie gegen `--bg`, `--surface` und ihre eigene Fläche. Dazu: keine Seite gestaltet ein **Eingabefeld** selbst — dieselbe Prüfung wie für Knöpfe, auf `input`/`select`/`textarea` erweitert. |
| **DesignTokensTest** | **eine Ausnahme fällt, eine Prüfung kommt.** Die Sonderbehandlung von `block-heading-size` im Ausdruck verschwindet (Befund 8). Neu: **jede Marke der Skala wird auch benutzt** — eine Rolle ohne Nutzer ist keine Rolle, und `--text-section` ohne Verwendung wäre der nächste Befund 5. |
| **MobileLayoutTest** | **unverändert.** Zwei Haltepunkte bleiben. Der Entwurf braucht **keinen dritten**, weil Flexbox umbricht, statt Spalten zu zählen — das ist die Prüfung des Entwurfs an der Regel, nicht die Lockerung der Regel. |
| **ThemeTest** | unverändert — prüft die Mechanik der Umschaltung, nicht das Aussehen. |
| **IconTest** | unverändert. |
| **InertiaPagesTest** | unverändert. |
| **WordChoiceTest** | unverändert — aber jede neue Beschriftung fällt darunter, und die neuen Bausteine bringen welche mit. |
| **CI „Farbwerte stehen nur in app.css"** | unverändert und gilt für jede neue Komponente. |

### Zwei neue Wächter

**`ClassReachTest`** — jede Klasse, die eine Vue-Datei im `<template>` schreibt,
gibt es entweder in `app.css` oder im `<style>` derselben Datei.

> Begründung: Befund 5. `class="series empty"` gegen `.series.leer` ist derselbe
> Fund wie `class="knopf betont"` in P3 — nur außerhalb von Knöpfen, und dort
> fragt niemand. Der Test verallgemeinert eine Prüfung, die es schon gibt, auf
> den Fall, für den sie fehlt.

**`DensityReachTest`** — jede Tabelle bezieht ihre Zeilenhöhe aus
`--row-height`; jeder Bereichsabstand kommt aus `--gap` oder `--block-gap`.

> Begründung: Befund 2. §7.2 verspricht zwei Dichtestufen. Ohne diesen Test ist
> das Versprechen auf 24 von 26 Seiten nicht eingelöst, und niemand merkt es.

### Die Regeln, die fallen — mit Begründung

**1. §7.2: „Radius 3 px. Kein größerer Wert, nirgends."**

Sie war nie in Kraft: **keine der 26 Seiten hält sie**, und der Plan hält die
Abweichung selbst als „nicht entschieden" fest. Sie war außerdem zu eng — die
Aufgabe lautet „offen und freundlich", und das ist mit 3 px nicht zu haben.

*Ersatz:* zwei Marken (`--radius`, `--radius-card`) plus die Prüfung, dass
daneben kein Radius-Literal steht. Derselbe Schutz wie vorher, nur mit einem
Wert, der gilt.

**2. §7.2: „Fünf Größen, und keine sechste."**

Der Satz widerspricht schon heute seiner eigenen Tabelle (sechs Zeilen) und der
Datei (sieben Marken). Was er *meint* — eine Marke je Rolle, kein Literal —
bleibt und wird schärfer geprüft als vorher. Neu sind acht Rollen, jede mit
einem Satz, wofür sie da ist.

**Was ausdrücklich nicht fällt:** „Schriftgrößen sind nicht nach Dichte
gestaffelt" (§7.2). Diese Regel bleibt — und gilt nach dem Rework zum ersten
Mal, weil `--block-heading-size` verschwindet.

### Und: jeder Wächter wird gebrochen

Für jeden geänderten und jeden neuen Test wird die Regel absichtlich verletzt
und nachgewiesen, dass er zubeißt:

| Wächter | Der Bruch, der rot werden muss |
|---|---|
| ButtonStyleTest (Kontrast) | `--control-line: var(--line)` setzen |
| ButtonStyleTest (Felder) | einer Seite ein `input { border: … }` geben |
| DesignTokensTest | `--text-section` einführen und nirgends benutzen |
| ClassReachTest | `class="bereich weit"` schreiben, `.weit` aus app.css nehmen |
| DensityReachTest | einer Tabelle `height: 34px` statt `var(--row-height)` geben |

---

## 6. Reihenfolge

Fünf Schritte. **Die Wächter kommen vor dem Umbau**, sonst prüfen sie den
Umbau statt den Bestand.

| Schritt | Was | Umfang |
|---|---|---|
| **1** | Wächter zuerst: ButtonStyleTest erweitern, DesignTokensTest bereinigen, ClassReachTest und DensityReachTest bauen — jeder absichtlich gebrochen. Sie sind danach **rot**, und das ist richtig: Sie melden die zehn Befunde. | 4 Testdateien |
| **2** | `app.css`: Marken, Tabelle, Formularfeld, Knopf, Meldung. Die Wächter werden grün. | 1 Datei, ~+350 Zeilen |
| **3** | Bausteine: `Bereich`, `Marke`, `Balken`, `Kennzahl`, `Leer`. Dazu `Tile.vue` (Befund 5 beheben). | 5 neue, 1 geänderte Komponente |
| **4** | `PanelLayout.vue`: Seitenleiste, Kopfzeile, Bereichsraster. | 1 Datei |
| **5** | Die 26 Seiten in vier Gruppen: Verzeichnisse (6) → Detailseiten (5) → Formulare (8) → Einstellungen und Anmeldung (7). Je Gruppe ein Durchgang mit Aufnahmen. | 26 Dateien, ~−950 Zeilen CSS |

**Abnahme:** Alle 26 Seiten in beiden Themes bei 390 px **und** breit
aufgenommen und angesehen — nicht nur grün getestet. Dazu `pint`, `phpunit`,
`vue-tsc`. Zum Schluss `CHANGELOG.md` und die Nachträge in `docs/19`,
`docs/20 §7.2` und `docs/24`.

---

## 7. Was nicht getan wird

- **Kein neues Gestaltungssystem.** „Werkstatt" und „Raster" aus
  `20-stilvorschlaege.html` bleiben verworfen; die Gründe von damals gelten.
- **Keine Icon-Bibliothek.** Eigene Geometrie mit `currentColor`, wie bisher
  (docs/19 §3a).
- **Keine nachgeladene Schrift.** System-Stack, wie §7.2 es verlangt.
- **Kein dritter Haltepunkt.** docs/24 bleibt unberührt.
- **Keine Änderung an Routen, Rechten, Agent oder Datenmodell.** Das hier ist
  ein Rework der Oberfläche und berührt keine der drei Grenzen aus `CLAUDE.md`.
- **Kein Umbau der Navigationsstruktur.** Dieselben Einträge, dieselben
  Gruppen — nur breiter.

---

## 8. Drei Entscheidungen, die der Betreiber trifft

1. **Wie weit soll die Schrift wachsen?** Vorgeschlagen sind 15 px Fließtext
   und 13,5 px Tabelle. Die zurückhaltende Fassung wäre 14 / 13 px — dichter,
   näher am heutigen Charakter. Die Entwürfe zeigen 15 / 13,5.
2. **Fällt der 3-px-Radius wirklich?** Vorgeschlagen: ja, 6 px und 10 px. Die
   Gegenposition ist, dass 3 px zur Instrumentenhaftigkeit gehört und der Code
   stattdessen nachziehen müsste.
3. **Bleibt es bei einer Dichteachse?** Vorgeschlagen: ja — die Kundenfläche
   wird ruhiger durch Luft, nicht durch eine zweite Gestaltung. Das ist die
   Entscheidung aus §7.2 und wird hier nur zum ersten Mal wirksam.

Erst danach beginnt Schritt 1.
