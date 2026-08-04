# 30 — Zwei Richtungen ohne Leitstand

> **Zum Ansehen:** [`30-neue-richtungen.html`](30-neue-richtungen.html) — beide
> Entwürfe, beide Themes, breit und bei 390 px.
> Aufnahmen: `node docs/entwuerfe/30-aufnahmen.mjs`

„Leitstand" fällt — nicht seine Umsetzung, sondern seine Grundannahme.

---

## 1. Warum die Richtung fällt und nicht nur ihr Maßstab

[`29-optik-rework.md`](29-optik-rework.md) hat versucht, Leitstand zu retten:
größerer Maßstab, mehr Raum, gemeinsame Bausteine. Das war zu wenig. Der
Charakter steckt nicht im Maßstab, sondern in vier Entscheidungen, und alle
vier gehen:

| Leitstand | Neu |
|---|---|
| dunkel als Ausgangsfassung | **hell entworfen, dunkel mitgeführt** |
| Amber als einzige Farbe | eine tragende Farbe, aber eine, die nicht nach Warnung aussieht |
| **jede Zahl** in Monospace | Monospace **nur für Kennungen** — Pfad, Unit, Benutzer, Befehl |
| schmales dunkles Rail neben dem Inhalt | die Fläche gehört dem Inhalt |

Der Kostenvermerk von 2026 zu Vorschlag A steht in
`20-stilvorschlaege.html` und beschreibt genau, was heute stört:

> „Für Kunden ohne Serverwissen wirkt es schnell nach Werkzeug statt nach
> Dienstleistung" · „Dichte verzeiht keine langen Texte"

**Der naheliegende Gegenentwurf ist ebenfalls schon verworfen.** „Werkstatt" —
hell, luftig, große Karten — trägt den Vermerk:

> „Deutlich weniger Zeilen je Bildschirm — bei 50 Abonnements wird gescrollt"

Damit ist die eigentliche Aufgabe gestellt, und sie ist nicht trivial:
**Freundlichkeit ohne Verlust an Zeilen.** Wer Dichte gegen Luft tauscht, hat
nichts gewonnen — er hat nur den anderen Kostenvermerk eingekauft.

Beide Entwürfe unten beantworten das, und zwar auf verschiedenen Achsen.

### Was aus Dokument 29 stehen bleibt

**Die zehn Befunde gelten weiter.** Sie sind Aussagen über den gebauten Code
und nicht über die Gestaltungsrichtung: der unsichtbare Feldrand (1,13 : 1),
`--row-height` in 2 von 26 Seiten, `class="series empty"` gegen
`.series.leer`, 1322 Zeilen Seiten-CSS, drei Fassungen des Balkens, vier der
Zustandsmarke. Auch die Wächter-Planung bleibt: `ButtonStyleTest` erweitern,
`DesignTokensTest` bereinigen, `ClassReachTest` und `DensityReachTest` bauen.

**Hinfällig ist allein der Abschnitt „Die Marken"** — die dortige Skala und
Palette waren Leitstand mit größeren Zahlen.

---

## 2. A — Werkbank

**Der Bruch ist baulich: die Seitenleiste verschwindet.**

Die Navigation liegt oben über die volle Breite, darunter eine helle
Kontextleiste mit Seitentitel, Pfad und Hauptaktion. Warmes Papier
(`#f5f2ec`), weiße Blätter mit 13 px Ecken, Petrol (`#0e6455`) als einzige
tragende Farbe; die Kopfleiste in dunklem Petrol (`#123f39`).

**Dafür**
- **232 px mehr Inhalt auf jeder Seite** — die Seitenleiste kostet nichts mehr
- Serverkennung und Konto stehen dauerhaft sichtbar in der Kopfleiste
- Warm und gerundet, ohne Zeilen zu verlieren: Die Dichte sitzt in der
  Tabelle, die Freundlichkeit in Fläche und Ecke
- Auf dem Telefon wird die Kopfleiste zur Kopfzeile — dieselbe Leiste, kein
  zweiter Bau

**Kostet**
- **Zwölf Menüpunkte passen nicht waagerecht.** „Einstellungen ▾" wird ein
  Aufklappmenü — eine Ebene mehr. Und das Panel wächst: P4 bis P8 bringen TLS,
  Datenbanken, Dateien, Cron, DNS und Sicherungen dazu
- Wo man ist, steht oben und nicht links; tiefe Seiten brauchen eine Pfadleiste
- Blätter kosten Innenabstand — je Bereich rund 40 px, die B nicht ausgibt

## 3. B — Kontor

**Der Bruch ist typografisch: es gibt keine Karten.**

Weiß, und die Ordnung kommt aus Schriftgrad, Weißraum und Haarlinien. Ein Rail
links, aber in der Farbe der Seite (`#fafafb`) statt als dunkler Block. Indigo
(`#3730a3`) als einzige tragende Farbe. Bereichsüberschriften tragen eine
kräftige Linie — das ist die ganze Gliederung.

**Dafür**
- **Die meisten Zeilen je Bildschirm von allen drei Entwürfen** — eine Karte
  kostet rund 40 px Innenabstand, hier gibt es keine
- Jede neue Seite ist billig: eine Überschrift, eine Linie, Inhalt. Kein
  Baustein, den man falsch verschachteln kann
- Sehr ruhig bei langen Texten — Erklärsätze und Hilfen haben endlich einen Ort
- Die Navigation trägt zwölf Einträge mit Gruppen und wächst mit den
  Ausbaustufen, ohne umgebaut zu werden
- Druckt und exportiert sich, wie es aussieht

**Kostet**
- **Ohne Karten muss jede Seite ihre Ordnung selbst herstellen** — mehr
  Disziplin, und genau dafür braucht es die Wächter aus Dokument 29
- Wirkt nüchterner als A; wer „freundlich" mit „warm" gleichsetzt, findet es kühl
- Die Seitenleiste kostet weiter 236 px

---

## 4. Was in beiden gleich ist

| Was | Warum |
|---|---|
| **Monospace nur für Kennungen** | Pfad, Unit, Systembenutzer, Befehl, Vorgangsnummer. **Nicht** für jede Zahl — das war Leitstand, und es liess jede Tabelle wie ein Terminal aussehen. Zahlen stehen trotzdem spaltengenau: `font-variant-numeric: tabular-nums` leistet das in jeder Grotesk. |
| **Zustand ist eine Marke mit Wort** | Punkt und Wort, nie Farbe allein. Acht Prozent der männlichen Nutzer lesen eine rote Fläche nicht. |
| **Hell entworfen, dunkel mitgeführt** | Umgekehrt zu Leitstand. Das dunkle Theme ist eine eigene Rechnung, keine Umkehrung. |
| **Zwei Haltepunkte, kein dritter** | 720 px und 480 px wie docs/24. Beide Entwürfe brechen über Flexbox um, statt Spalten zu zählen. |
| **Keine nachgeladene Schrift** | System-Grotesk. Die einzige Regel aus §7.2, die ungeprüft weitergilt. |
| **Jeder Farbwert gerechnet** | 4,5 : 1 für Text, 3 : 1 für die Grenze eines Bedienelements. Alle vier Paletten (A und B, je hell und dunkel) sind durchgerechnet und halten. |

---

## 5. Zwei Funde aus dem Browser

Beide standen im ersten Entwurf und sind erst in der Aufnahme aufgefallen.

**1. Das Zeichen verträgt keinen Menüknopf neben sich.** In der schmalen
Kopfzeile von A stand „≡ ≡ SrvPanel". Das Zeichen sind drei gestapelte Balken —
und der Menüknopf ist dasselbe Bild. Nebeneinander sind sie nicht zu
unterscheiden; man sieht zwei Menüknöpfe und drückt auf den falschen.

Das ist **keine Eigenheit dieses Entwurfs, sondern eine Eigenschaft des
Zeichens.** Bei Leitstand fiel es nie auf, weil das Zeichen in der
Seitenleiste sass und der Menüknopf in der Kopfzeile — sie standen nie
zusammen. Jede künftige Gestaltung, die beide in eine Leiste setzt, hat
dasselbe Problem. Antwort hier: In der schmalen Kopfzeile trägt der Menüknopf
allein, die Marke steht als Schriftzug; im Reiter bleibt das Zeichen.

**2. Eine Tabelle aus Bezeichnung und Wert braucht eine Grenze.** In B stand
„Kontingente" über die volle Breite, weil der Bereich allein in seine Reihe
umbrach — „Domains" und „3 von 10" lagen 1300 px auseinander. Fläche ausnutzen
heißt nicht, zwei zusammengehörige Angaben an die gegenüberliegenden Ränder zu
schieben.

---

## 6. Empfehlung

**B — Kontor**, aus drei Gründen:

1. **Es beantwortet die Kritik am direktesten.** „Zu schmal, zu kleinlich, zu
   beschränkt" ist zuerst eine Frage von Zeilen und Ruhe, nicht von Wärme. B
   hat von allen drei Entwürfen die meisten Zeilen je Bildschirm und
   gleichzeitig den meisten Platz für Erklärsätze.
2. **Es ist am billigsten richtig zu bauen.** Ein Bereich ist eine Überschrift,
   eine Linie und Inhalt. Es gibt keinen Kartenbaustein, den 26 Seiten
   verschieden verschachteln können — und genau das ist der Fehler, der dieses
   Projekt in Dokument 29 zehnmal gekostet hat.
3. **Die Navigation wächst mit dem Panel.** Nach P4 bis P8 kommen TLS,
   Datenbanken, Dateien, Cron, DNS und Sicherungen dazu. Ein Rail mit Gruppen
   nimmt das auf; eine waagerechte Leiste, die schon bei zwölf Einträgen ein
   Aufklappmenü braucht, wird bei achtzehn zweimal umgebaut.

**Was gegen die Empfehlung spricht:** A ist wärmer, und „freundlich" war
ausdrücklich Teil des Auftrags. Wer die Kopfleiste sehen will, bekommt mit A
außerdem die volle Fensterbreite.

**Der Mittelweg ist echt und keine Verlegenheit:** B's Bau — Rail, keine
Karten, Haarlinien — mit A's Anmutung: warmes Papier statt Weiß, 9 px an
Bedienelementen statt 5, Petrol statt Indigo. Das ist eine Änderung an sechs
Marken und keine dritte Richtung.

---

## 7. Wenn entschieden ist

Der Ablauf aus Dokument 29 §6 gilt unverändert — nur die Marken kommen aus
diesem Dokument statt von dort:

1. Wächter zuerst (`ButtonStyleTest` erweitern, `DesignTokensTest` bereinigen,
   `ClassReachTest`, `DensityReachTest`), jeder absichtlich gebrochen
2. `app.css`: Marken und Bausteine
3. Komponenten: Bereich, Marke, Balken, Kennzahl, Leer
4. `PanelLayout.vue`
5. Die 26 Seiten in vier Gruppen, je Gruppe ein Durchgang mit Aufnahmen

**Abnahme:** alle 26 Seiten in beiden Themes bei 390 px und breit aufgenommen
und angesehen. Dazu `pint`, `phpunit`, `vue-tsc`.

Ausgangsstand heute: `phpunit` 822 Tests / 3044 Zusicherungen grün, `pint`
grün, `vue-tsc` grün.
