# Die sechs Farben — was von ihnen einbaubar ist

**Geschrieben am 7. September 2026**, nach der Prüfung von sechs Farben aus drei
Bildschirmfotos gegen das Gestaltungssystem „Kontor". Der Prüfling war
`main @ 9d2a245`; **am 9. September gegen `81e13d8` nachgeprüft** — keine der
Marken in `app.css` hat sich geändert, `.rail` und `.topbar` lesen weiterhin
beide `--nav-bg`, und `AnnouncementCategory::badge()` gibt „Info" nach wie vor
die Marke `ok`. Alle Zahlen unten gelten damit unverändert.

**Warum die Nummer 900 und nicht die nächste freie.** Die laufende Zählung
gehört den Stufen — dort steht je Merkmal ein Plan, ein Lauf und ein Protokoll,
und die Nummern rücken in der Reihenfolge nach, in der gebaut wird. Dieses
Dokument gehört keiner Stufe: Es betrifft das Gestaltungssystem quer über alle.
Eine Nummer in der laufenden Zählung hätte es zwischen zwei Abnahmeläufe
gesetzt und wäre ausserdem doppelt vergeben worden — 108 ist beim Schreiben
dieses Plans an `docs/108-protokoll-a11.md` gegangen, während er auf seinem
Zweig lag.

> **Zwei Zweige, die beide die nächste freie Nummer nehmen, vergeben dieselbe —
> und gemerkt wird es erst beim Zusammenführen.**

Der 900er-Block ist deshalb der ferne: Er ist für allgemeine Anpassungen da, die
neben den Stufen stehen, und kollidiert mit keiner laufenden Zählung. Wer hier
etwas ablegt, nimmt die erste freie Nummer **darin**.

**Dieses Dokument ist der Bauplan und nicht die Prüfung.** Es enthält die
Messwerte, die tragen, und die Handgriffe, die daraus folgen — nicht die
Herleitung. Wer wissen will, warum eine Farbe *keinen* Ort bekommen hat, findet
das in §1 als Zeile und nirgends ausführlicher.

**Am 9. September 2026 sind E1 bis E5 gebaut**, nachdem der Betreiber alle
vier Fragen aus §2 mit Ja beantwortet hat — die Kopfleiste trägt den Markensatz
mit. Was beim Bauen anders war als im Plan, steht in §12; der Rest dieses
Dokuments ist der Stand davor und wird nicht rückwirkend geglättet.

> **Ein Plan, den man nach dem Bauen glattzieht, verliert die Stelle, an der
> er falsch lag — und die ist das einzige, was der nächste daraus lernt.**

---

## 0 · Die sechs Farben

| Name | Hex | gegen `#ffffff` | gegen `#0f1116` | Urteil |
|---|---|---|---|---|
| Inkberry | `#1A0B2E` | 18,56:1 | 1,02:1 | einbaubar — E1, E2 |
| Peach | `#FFB7A5` | 1,67:1 | 11,30:1 | nur **auf** Inkberry — E1, E2 |
| Dark Aubergine | `#2E0F35` | 16,99:1 | 1,11:1 | kein Ort |
| Electric Pink | `#FF7FEC` | 2,21:1 | 8,54:1 | einbaubar, nur dunkel — E3 |
| Dragonfruit | `#FF4696` | 3,20:1 | 5,91:1 | einbaubar — E4, E5 |
| Night Violet | `#1E1033` | 17,84:1 | 1,06:1 | kein Ort |

Kontraste nach WCAG 2.1 (relative Leuchtdichte, sRGB), ΔE als CIE76 im Lab-Raum
bei D65.

---

## 1 · Was die Prüfung entschieden hat

Sieben Dinge stehen nicht mehr zur Wahl, weil sie gemessen sind. Sie stehen
hier, damit niemand sie im Bauen noch einmal aufmacht.

| | gemessen | Folge für den Bau |
|---|---|---|
| F1 | Inkberry ./. Night Violet **ΔE 2,12** | Night Violet bekommt **keine** Marke — die Schwelle dieses Projekts ist 2,3 (`AnnouncementCategory`) |
| F2 | Inkberry ./. Dark Aubergine ΔE 8,10, gleiche Rolle | Dark Aubergine ist **Alternative** zu Inkberry, nie daneben |
| F3 | die drei Dunklen 1,02–1,11:1 gegen `#0f1116` | sie tragen **nur** im hellen Thema |
| F4 | die drei Hellen 1,67–3,20:1 gegen `#ffffff` | sie tragen **nur** im dunklen Thema — Ausnahme Dragonfruit als grafisches Objekt |
| F5 | Peach hat den Farbton **39,3°**, `--critical` auch | Peach wird **keine** eigene Marke, in keinem Thema |
| F6 | Dragonfruit auf `--surface` hell: **3,06:1** | über 3:1, mit sechs Hundertstel Luft — und ohne Wächter |
| F7 | eine Bandfläche aus Dragonfruit: **ΔE 8,52** gegen die Seite | trägt den Rang, den `neutral` mit ΔE 1,78 nicht tragen konnte |

**F5 ist der Grund, dass Peach keinen eigenen Wert bekommt.** Als Bandfläche im
dunklen Thema gemessen liegt sie **ΔE 3,1** von der Störungsfläche entfernt —
unter dem Paar, das dieses Panel selbst als sein schwächstes führt (Warnung
gegen Störung im hellen Thema, ΔE 3,8). Peach bleibt, was sie auf dem Bild ist:
die Farbe **auf** Inkberry.

---

## 2 · Was der Betreiber vor dem ersten Handgriff entscheidet

**Frage 1 — Bekommt Kontor eine dunkle Fläche im hellen Thema?**
Das ist E1 und E2, und es ist keine Messfrage. `app.css` nennt als Grundannahme
1 „hell entworfen, dunkel mitgeführt"; ein pflaumenfarbener Streifen widerspricht
dem nicht wörtlich, verschiebt aber den Charakter der Oberfläche. Ohne ein Ja
entfallen E1 und E2, und mit ihnen Inkberry und Peach vollständig.

**Frage 2 — Wenn ja: gilt es auch für die Kopfleiste bei 390 px?**
`.topbar` liest dieselbe Marke `--nav-bg` wie `.rail` (§3, Falle 1). Entweder
beide tragen den Markensatz — dann ist die Kopfzeile auf dem Telefon ebenfalls
pflaumenfarben — oder nur die Schublade, und die Kopfleiste bleibt hell. Beides
ist in sich stimmig; gemischt ist es keines.

**Frage 3 — Darf das dunkle Thema einen eigenen Farbton bekommen?**
Das ist E3. Heute sind Indigo hell und `#a3aaff` dunkel dieselbe Farbe in zwei
Helligkeiten. Electric Pink macht daraus zwei Farben. `app.css` lässt das
ausdrücklich zu („das dunkle Theme ist keine Umkehrung"), gemeint war damit
bisher aber die Helligkeit und nicht der Farbton.

**Frage 4 — Soll „Info" einen eigenen Rang bekommen?**
Das ist E5, und es ist der einzige Punkt, der einen benannten Mangel behebt statt
etwas Laufendes zu tauschen. Der Preis ist ein vierter Rang: drei Regeln in
`app.css`, ein erweiterter Rückgabetyp und zwei neue Marken in **beiden**
Theme-Blöcken.

---

## 3 · E1 — Der Navigationsstreifen in Inkberry

**Was.** `.rail` bekommt einen eigenen Grund und einen eigenen Markensatz; der
aktive Menüpunkt trägt Peach.

**Die Dateien.** Nur `resources/css/app.css`. Keine `.vue`, keine Regel, keine
Struktur — ausschliesslich ein neuer Selektor mit Marken.

**Die Marken** (jede gegen Inkberry gemessen):

```css
.rail, .topbar {
  --nav-bg: #1a0b2e;
  --nav-border: #3a2954;   /* 1,44:1 — Haarlinie, kein Bedienelement */
  --text: #d9d2e6;         /* 12,65:1 — Menüpunkt, nicht aktiv */
  --text-strong: #ffffff;  /* 18,56:1 — „SrvPanel", Titel der Kopfleiste */
  --text-muted: #a99cbe;   /*  7,24:1 — Konto, Quelltext, Umschalter */
  --text-faint: #9b8fb0;   /*  6,14:1 — Gruppenüberschrift */
  --accent: #ffb7a5;       /* 11,11:1 — aktiver Punkt, Verweis beim Überfahren */
  --accent-surface: rgb(255 183 165 / 0.14);
}
```

Die Fläche des aktiven Punktes ergibt damit `#3a233f`, Peach darauf **8,42:1**.

**Falle 1 — `.topbar` liest dieselbe Marke.** Bei ≤ 720 px zeichnet
`PanelLayout` eine Kopfleiste mit `background: var(--nav-bg)` und
`border-bottom: 1px solid var(--nav-border)`; ihr Titel liest `--text-strong`,
der Umschalter `--text-muted`. Wer nur `.rail` in den Selektor schreibt, bekommt
auf dem Telefon eine helle Kopfleiste über einer dunklen Schublade. Wer
stattdessen `:root` ändert, färbt beide und zerreisst dabei jeden dunklen Text
darin. Das ist Frage 2 aus §2.

**Falle 2 — der naive Wurf setzt nur `--nav-bg`.** Dann steht `--text` weiter auf
`#3a3f49` und erreicht auf Inkberry **1,76:1**: Der Streifen ist da, die
Navigation ist fort. Es sind acht Marken und nicht eine.

**Falle 3 — der Schleier ist gegen den hellen Grund gerechnet.** `--scrim` steht
auf `rgb(15 17 21 / 0.42)` und bleibt eine Marke der Seite, nicht des Streifens.
Bei 390 px liegt die Schublade darüber; die Wirkung ist zu messen und nicht
herzuleiten.

**Der Wächter.** `ButtonStyleTest` liest diesen Selektor nicht — er prüft
Bedienelemente, und `--nav-bg` ist keines. Ein neuer Wächter rechnet die vier
Textmarken gegen `--nav-bg` **je Theme** und verlangt 4,5:1; sein Bruch setzt
`--text` auf den Wert aus `:root` zurück und muss die 1,76:1 melden.

**Die Messung.** Vier Lagen mit `tests/bilder-messen.js` samt Gegenprobe: beide
Themen × 390 und 1440 px, bei 390 px mit **offener** Schublade.

---

## 4 · E2 — Die Anmeldeseite als Markenfläche

**Was.** `.signin` bekommt den Grund, das Formular die Fläche, die Überschrift
Peach.

**Die Dateien.** Nur `resources/css/app.css`.

**Die Marken** (gegen die Formularfläche `#1a0b2e` gemessen):

```css
.signin {
  --bg: #140823;           /* die Seite hinter dem Blatt */
  --surface: #1a0b2e;      /* das Blatt selbst — Inkberry */
  --line: #3a2954;
  --text: #efe9f2;         /* 15,57:1 */
  --text-strong: #ffb7a5;  /* 11,11:1 — die Überschrift */
  --text-muted: #bfb2ce;   /*  9,26:1 */
  --text-faint: #a99cbe;   /*  7,24:1 */
  --control-bg: #2a1745;
  --control-line: #9a86b8; /*  4,96:1 auf der Feldfläche — verlangt 3,0 */
  --accent: #ffb7a5;
  --accent-on: #1a0b2e;    /* 11,11:1 — die Beschriftung des Knopfes */
  --accent-surface: rgb(255 183 165 / 0.14);
  --focus: #ffb7a5;
}
```

**Die Marken sitzen auf `.signin` und nicht auf `.signin-frame`, und das ist
tragend.** Über dem Formular steht seit A14 ein Band mit der Kategorie
**Störung** (`docs/103 §4.4`), und ein `.band` trägt seinen Rang in Fläche, Rand
**und** Textfarbe. Diese drei Zustandsfarben sind gegen den hellen Grund
gerechnet. Läge der Markensatz auf `.signin-frame`, erbte das Band den
pflaumenfarbenen Grund und jede der drei müsste ein zweites Mal gerechnet
werden.

> **Eine Markenfläche endet dort, wo eine Zustandsfarbe anfängt — sonst ist sie
> keine Fläche, sondern ein zweites Theme.**

**Der Wächter.** Derselbe wie bei E1, um den Selektor `.signin` erweitert: vier
Textmarken gegen `--surface`, dazu `--control-line` gegen `--control-bg` mit
3:1. Letzteres prüft `ButtonStyleTest` heute schon für `:root` — der
Selektor-Fall fehlt ihm.

**Die Messung.** Zwei Lagen (390 und 1440 px) mit einem Band der Kategorie
Störung im Bild — ohne das Band ist die Grenze der Markenfläche nicht gemessen.

---

## 5 · E3 — Electric Pink als Akzent des dunklen Themas

**Was.** Im Block `:root[data-theme='dark']` wechselt der Akzent von `#a3aaff`
auf `#ff7fec`.

**Die Dateien.** Nur `resources/css/app.css`, vier Zeilen im dunklen Block.

```css
:root[data-theme='dark'] {
  --accent: #ff7fec;                          /* 8,54:1 (heute 8,77:1) */
  --accent-surface: rgb(255 127 236 / 0.14);  /* Pink darauf 6,85:1 */
  --mark-accent: #ff7fec;
  --focus: #ff7fec;
}
```

`--accent-on` bleibt `#12142a` und erreicht auf der neuen Fläche **8,19:1**.
Der Abstand zu `--critical` im dunklen Thema beträgt ΔE 68,1 — keine
Verwechslungsgefahr.

**Der Tausch ist ein Wert und kein Umbau.** Vierzehn Regeln in `app.css` und
sechs Stellen in Komponenten lesen `--accent`; keine davon wird angefasst.

**Die Falle.** Im hellen Thema steht die Farbe bei **2,21:1** und ist damit nicht
einmal als Linie zulässig. Wer sie versehentlich in den hellen Block schreibt,
bekommt einen Akzent, den `ButtonStyleTest` erst dann meldet, wenn er auf einer
Knopffläche landet — als Textfarbe eines Verweises meldet ihn heute nichts.

**Der Wächter.** Ein Fall in `ButtonStyleTest`, der `--accent` **je Theme** gegen
`--bg` rechnet und 4,5:1 verlangt. Sein Bruch trägt den dunklen Wert in den
hellen Block.

**Die Messung.** Zwei Lagen im dunklen Thema (390 und 1440 px) mit einer Seite,
auf der Menüpunkt, Kurve, Knopf und Marke gleichzeitig stehen — die Übersicht
leistet das.

---

## 6 · E4 — Dragonfruit als zweite Kurve

**Was.** `--accent-second` — die gestrichelte Kurve einer Kachel mit zwei
Richtungen — wechselt vom Petrol auf Dragonfruit, in **beiden** Themen.

**Die Dateien.** `resources/css/app.css`, je eine Zeile im hellen und im dunklen
Block. Die Begründung im Kopf der Marke ist mitzuziehen: Sie nennt heute die
gemessenen 6,18:1 und 11,10:1.

```css
:root                     { --accent-second: #ff4696; }  /* 3,20:1 hell */
:root[data-theme='dark']  { --accent-second: #ff4696; }  /* 5,91:1 dunkel */
```

**Die Zusage der Marke trägt weiter.** `app.css` verlangt für die zweite Kurve
eine Farbe, die „weder nach *gut* noch nach *Warnung* aussieht" — Pink ist keine
Zustandsfarbe dieses Panels, Abstand zu `--critical` hell ΔE 53,7. Und die Kurve
unterscheidet sich weiter durch drei Dinge und nicht nur durch die Farbe:
gestrichelt, ohne Fläche, obenauf (WCAG 1.4.1).

**Die enge Stelle, und sie bleibt es.** Auf `--surface` (`#fafafb`) erreicht
Dragonfruit **3,06:1**. Das ist über den 3:1, die WCAG 1.4.11 für ein grafisches
Objekt verlangt, mit sechs Hundertstel Luft. Der heutige Wert hat dort 6,18:1.

> **Ein Wert, der die Grenze um sechs Hundertstel überschreitet, ist zulässig und
> nicht robust — und was ihn hält, muss man dazuschreiben.**

**Der Wächter — und er fehlt heute.** `ButtonStyleTest` prüft Bedienelemente;
eine Kurve ist keines. `--accent-second` steht seit seiner Einführung mit einer
gerechneten Zahl im Kommentar und ohne Prüfung daneben. **Dieser Punkt wird
nicht ohne seinen Wächter gebaut:** Er rechnet `--accent-second` gegen `--bg`
*und* `--surface`, je Theme, und verlangt 3:1. Sein Bruch dunkelt `--surface`
um einen Schritt ab und muss die Kurve melden.

**Die Messung.** Vier Lagen mit einer Kachel, die beide Richtungen führt — die
Netzkachel der Übersicht.

---

## 7 · E5 — Das Info-Band bekommt einen eigenen Rang

**Was.** Ankündigungen der Kategorie **Info** tragen nicht mehr die Marke `ok`,
sondern einen eigenen Rang `info` in Dragonfruit.

**Der Mangel steht wörtlich im Quelltext.** `AnnouncementCategory::badge()`
begründet selbst, warum Grün falsch ist — „Grün behauptet daneben, etwas sei
*gut*" — und nennt den Grund, aus dem `neutral` ausschied: dessen Fläche steht
im hellen Thema bei **ΔE 1,78** gegen die Seite, unter der Wahrnehmungsschwelle
von 2,3. Gesucht war eine Fläche, die sichtbar ist, keine Zustandsfarbe und
nicht grün.

**Die Messwerte.**

| | hell | dunkel |
|---|---|---|
| Fläche | `#ffeef6` | `#311828` |
| ΔE gegen die Seite | **8,52** (`ok`: 7,02 · `neutral`: 1,78) | 16,74 |
| Abstand zur Störungsfläche | ΔE 5,7 (heute schwächstes Paar: 3,8) | ΔE 11,9 |
| Text auf der eigenen Fläche | 4,83:1 | 5,08:1 |

**Es sind zwei Werte und nicht einer.** Dragonfruit selbst erreicht auf seiner
hellen Fläche nur **2,86:1** und kann den Text eines Bandes dort nicht tragen.
Der helle Wert der Marke ist deshalb `#c0306f` — eine um ΔE 14,9 abgedunkelte
Verwandte, gegen die Seite 5,39:1. Das ist der Normalfall dieses Systems: `--ok`,
`--warn` und `--critical` führen alle je zwei Werte, und keiner ist aus dem
anderen abgeleitet.

**Die Dateien, alle vier.**

1. `resources/css/app.css` — zwei Marken je Theme:
   ```css
   :root                    { --info: #c0306f; --info-surface: rgb(255 70 150 / 0.09); }
   :root[data-theme='dark'] { --info: #ff4696; --info-surface: rgb(255 70 150 / 0.14); }
   ```
2. `resources/css/app.css` — **drei** Regeln, denn der Wert aus `badge()` fährt
   in drei verschiedene Bausteine:
   ```css
   .band.info   { color: var(--info); background: var(--info-surface); border-color: var(--info); }
   .badge.info  { color: var(--info); background: var(--info-surface); }
   .notice.info { background: var(--info-surface); border-color: var(--info); }
   ```
3. `app/Enums/AnnouncementCategory.php` — `badge()` gibt `Info` künftig `'info'`
   zurück; der Rückgabetyp wächst von `'ok'|'warn'|'critical'` auf vier. Der
   Kommentar, der die alte Wahl begründet, wird zur Begründung der neuen — was
   vorher falsch war, gehört stehengelassen und nicht gelöscht.
4. `CHANGELOG.md` — der Ort, an dem steht, *warum* es jetzt anders ist.

**Die drei Bausteine sind der Fund, der diesen Punkt teuer macht.**
`Bands.vue:39` setzt `.band`, `Announcements/Index.vue:121` setzt `.badge`,
`Announcements/Show.vue:34` setzt `.notice` — alle drei aus demselben Feld. Wer
nur `.band.info` schreibt, bekommt auf der Übersicht der Ankündigungen eine
Marke ohne Farbe und auf der Einzelseite eine Meldung ohne Rang.

**Der Wächter.** ~~`ClassReachTest` greift von selbst, sobald die drei Regeln
fehlen — vorausgesetzt, die Klasse steht als Objektschlüssel und nicht als
Ausdruck.~~ **Die Voraussetzung ist an allen drei Stellen nicht erfüllt**, und
das ist beim Bauen aufgefallen: `Bands.vue:39`, `Announcements/Index.vue:121`
und `Announcements/Show.vue:34` schreiben die Klasse als Ausdruck, und
`ClassReachTest` führt dafür keine Ausnahmeliste. Er kann den Rang nicht
ableiten und fängt eine fehlende Regel **nicht**.

`RankReachTest` ist damit keine Redundanz, sondern die einzige Deckung: Er hält
die Rückgabewerte von `badge()` gegen die Regeln in `app.css`, in **beide**
Richtungen — jeder Rang hat drei Regeln, und jede `.band`-Regel hat einen
Erzeuger.

**Die Messung.** Vier Lagen mit drei Ankündigungen gleichzeitig — Info, Warnung,
Störung untereinander, damit der Abstand der Ränge im Bild steht und nicht nur
in der Tabelle.

---

## 8 · Die Reihenfolge

**E5 zuerst, und wenn nur eines gebaut wird, dann dieses.** Es ist der einzige
Punkt, der einen benannten Mangel behebt. Er hängt an keiner Entscheidung über
den Charakter der Oberfläche und ist in sich abgeschlossen.

**E4 als zweites, mit seinem Wächter.** Der Wächter ist dabei mehr wert als der
Farbwechsel: Er schliesst eine Lücke, die seit der Einführung der zweiten Kurve
offen ist.

**E3 als drittes.** Ein Wert, ein Wächter, eine Bilderrunde.

**E1 und E2 zuletzt und nur zusammen.** Ein pflaumenfarbener Streifen mit einer
weissen Anmeldeseite davor ist keine halbe Entscheidung, sondern eine
sichtbare Inkonsistenz. Beide hängen an Frage 1 aus §2.

---

## 9 · Was dieser Plan ausdrücklich **nicht** wird

- **Kein neuer Baustein.** Kein Punkt fügt eine Regel hinzu, die es nicht schon
  gibt; E5 fügt einer vorhandenen Regelfamilie ein viertes Glied hinzu.
- **Keine Karte.** Kontor kennt keine Karten (`app.css`, Grundannahme 2). Die
  Farbpaare der Bilder sind Karten; hier werden sie zu Flächen mit eigenem
  Markensatz oder zu gar nichts.
- **Kein Markenton für den QR-Code.** `--qr-dark` steht mit Absicht auf demselben
  Wert wie `--text-strong`, und `QrThemeTest` hält, dass die beiden QR-Marken nur
  im hellen Block stehen. Inkberry wäre dort mit 18,56:1 lesbar — es ist trotzdem
  der einzige Vorschlag, den die Prüfung nicht macht: Über die Lesbarkeit
  entscheidet an dieser Stelle ein Gerät und kein Auge.
- **Keine Marke für Night Violet, Dark Aubergine oder Peach.** F1, F2 und F5.
- **Kein Umbau des `--accent` im hellen Thema.** Keine der sechs erreicht dort
  4,5:1 ausser den drei Dunklen, und die stehen bei ΔE 24–28 von `--text-strong`:
  ein Verweis in Inkberry wäre von einer Überschrift nicht zu unterscheiden.

---

## 10 · Wann er durch ist

Je Punkt drei Dinge, und keines ersetzt ein anderes:

1. **Die Zahl.** Jede neue Marke steht mit ihrem gemessenen Kontrast im
   Kommentar daneben — gerechnet, nicht geschätzt.
2. **Der Wächter.** Für jede neue Regel einer, und er wird gebrochen: Ein
   Eingriff in `tests/waechter-brechen.sh`, der die Regel verletzt, muss ihn rot
   machen. Ein Wächter, der nie rot war, ist kein Wächter.
3. **Das Bild.** Vier Lagen je berührter Seite — beide Themen × 390 und 1440 px
   — mit `tests/bilder-messen.js` und seiner Gegenprobe. Eine Null ist nur dann
   eine Messung, wenn daneben etwas anderes als Null steht.

**Und keiner der fünf Punkte gilt als abgenommen, bevor er auf einem echten
Server angesehen wurde.** Der Container misst aufs Pixel (`docs/56` Punkt 5),
aber die Frage, ob eine Fläche als Marke *trägt*, beantwortet kein Messwert.

---

## 11 · Was zu wissen ist, bevor jemand anfängt

**Die Vorschauen gibt es als Artefakt.** Alle fünf Punkte sind in den echten
Seiten vorgeführt — gebautes Stylesheet aus `public/build`, beide Dateien, mit
dem Markup aus `PanelLayout.vue`, `Tile.vue` und `Login.vue`; geändert war
ausschliesslich der Wert der Marken. Es ist kein Teil des Repos und kann
verfallen; die Zahlen in diesem Dokument stehen für sich.

**Der Aufsatz braucht beide Stylesheets.** Der Eintrag `resources/js/app.ts` im
Manifest führt zwei — in dem einen stehen die Seitenregeln aus `app.css`, in dem
anderen die `scoped`-Regeln aller Komponenten. Wer nur eines nimmt, misst eine
Seite ohne die Regeln jeder Komponente, und das Ergebnis sieht aus wie eines.

**Die Höhe einer Vorschau wird gemessen und nicht geraten.** Beim Bau des
Artefakts waren drei von sieben Rahmen zu klein geraten; zwei schnitten ihren
Inhalt ab, einer liess ein Loch. Auf dem Bild sah beides aus wie ein Ergebnis.

> **Ein Rahmen, dessen Höhe man rät, schneidet ab oder lässt ein Loch — und
> beides liest sich wie ein Befund am Prüfling.**

---

## 12 · Was beim Bauen anders war als in diesem Plan

Gebaut am 9. September 2026, alle fünf Punkte. Vier Dinge standen hier falsch
oder gar nicht; sie stehen unten, damit der nächste sie nicht noch einmal
herleitet.

**1 · `ClassReachTest` deckt den vierten Rang nicht — §7 hat das Gegenteil
behauptet.** Der Satz trug seine Voraussetzung selbst („vorausgesetzt, die
Klasse steht als Objektschlüssel"), und geprüft hatte sie niemand. Alle drei
Stellen schreiben sie als Ausdruck.

> **Eine Zusage mit einer Voraussetzung, die niemand nachgesehen hat, ist eine
> Vermutung mit Fussnote.**

**2 · `.band.ok` ist fort, und gefunden hat es die Gegenrichtung des neuen
Wächters.** Sobald `Info` den Rang `info` bekam, hatte `.band.ok` keinen
Erzeuger mehr — `.band`-Klassen entstehen nur aus `badge()` und aus dem einen
wörtlichen `class="band warn"` des Sichtwechsels. `.badge.ok` und `.notice.ok`
bleiben: Sie bedienen vier andere Enums.

> **Eine Regel, die mehrere Erzeuger hat, ist nicht tot, weil einer von ihnen
> sie nicht mehr braucht.**

**3 · Die drei alten Ränge wurden bis dahin nur zufällig erreicht.**
`ClassNameTest` fand `ok`, `warn` und `critical`, weil andere Bausteine
dieselben Wörter wörtlich hinschreiben. `info` hat keinen solchen Zwilling und
steht deshalb als benannte Ausnahme in der Erreichbarkeitsliste — mit dem
Wächter daneben, der ihn hält.

> **Eine Regel, die nur erreicht wird, weil ein anderer Baustein zufällig
> dasselbe Wort benutzt, ist nicht gehalten — sie ist unentdeckt.**

**4 · Und der teuerste Fehler des Tages steckte im Messmittel.** Der Aufsatz
für die Bilderrunde trug die `scoped`-Kennung von `PanelLayout.vue` fest im
Quelltext — den Hash von vor dem letzten Merge auf `main`. Danach passte keine
Regel des Streifens mehr. Die Marken standen korrekt auf dem Element, und die
Messung meldete einen durchsichtigen Streifen mit dunkler Schrift: **genau das
Bild der Falle 2 aus §3, die dieser Lauf belegen sollte.**

> **Ein Aufsatz, der eine Kennung fest verdrahtet, misst nach der nächsten
> Änderung an der Komponente eine Seite ohne deren Regeln — und das Ergebnis
> sieht aus wie ein Befund am Prüfling.**

Die Kennung wird jetzt aus dem gebauten Stylesheet abgeleitet, und der Aufsatz
bricht ab, wenn er sie nicht findet.

**Was gemessen ist.** Vier Lagen (beide Themes × 390 und 1440 px) plus die
Anmeldeseite in beiden Themes, jeweils mit **abgelesenen** Rechenwerten statt
eines Blicks: Streifen `#1a0b2e`, Menüpunkt `#d9d2e6`, aktiver Punkt und
Überschrift der Anmeldeseite `#ffb7a5`, Kopfleiste bei 390 px `#1a0b2e`,
Info-Band `#c0306f` hell und `#ff4696` dunkel, zweite Kurve `#ff4696` in
beiden. `schiebt` in jeder Lage 0 — die Änderung fasst kein Kastenmass an.

**Und das Störungsband der Anmeldeseite trägt weiter die Zustandsfarben der
Seite** (`#ab2b19` hell, `#f08a72` dunkel). Das ist die Zusage aus §4, gemessen
und nicht behauptet.

**Was nicht gemessen ist:** die echte Seite mit echten Daten auf einem echten
Server. Der Aufsatz trifft aufs Pixel (`docs/56` Punkt 5), aber die Frage, ob
eine Fläche als Marke *trägt*, beantwortet kein Messwert.
