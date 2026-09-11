# Ein Abzeichen am Menüpunkt „Updates"

**Geschrieben am 11. September 2026, nach der Messrunde.** Gemeldet hat es der
Betreiber an einem Bildschirmfoto: Auf `/updates` stehen 32 Aktualisierungen,
und die Navigation daneben sagt darüber nichts.

**Die Nummer kommt aus dem 900er-Block** (`docs/900`): Dieses Merkmal gehört
keiner Stufe — P7b ist durch, P8 hat noch keinen Plan —, und eine Nummer in der
laufenden Zählung stünde zwischen zwei Abnahmeläufen. Dieselbe Überlegung wie
bei `docs/904`.

---

## §1 Was die Messrunde entschieden hat

Fünf Messungen, und **vier davon haben den Entwurf verändert**, bevor eine Zeile
Code entstand.

### 1.1 Die Zahl live zu holen ist ausgeschlossen, und zwar um Grössenordnungen

| | gemessen |
|---|---|
| `system.packages.list` — die Zahl holen | **2954–3644 ms** (`cloudsrv24`, `docs/906 §2`) |
| `Setting::find()` — die Zahl abgelegt lesen | **0,104 ms**, eine Abfrage |

Die Navigation steht auf **jeder** Seite. Damit fallen zwei der drei denkbaren
Wege sofort, und keiner davon ist Geschmackssache:

- **Live fragen** macht jede Seite des Panels drei Sekunden langsam — das
  Gegenteil dessen, was `docs/904` gerade erreicht hat.
- **In `share()` nachreichen** setzt bei *jedem* Seitenaufruf einen apt-Lauf ab.
  Zwei davon enden in der dpkg-Sperre, die `AptLock` seit A1 Schritt 2 abfragt.

Bleibt der dritte: **abgelegt, von einem Einsammler gefüllt.**

> **Ein Wert, dessen Beschaffung dreitausendmal teurer ist als sein Lesen,
> gehört nicht in eine Leiste, die auf jeder Seite steht.**

### 1.2 Der Streifen trägt keine Zustandsfarbe — und das steht in `app.css`

Gemessen: `--nav-bg` ist **in beiden Themen** `#1a0b2e`. Der Streifen ist kein
Stück Seite, sondern eine Fläche mit **acht eigenen Marken** (`.rail, .topbar`),
und `--warn` gehört nicht dazu. Ein `.badge.warn` dort liest die Farbe der
*Seite* und setzt sie auf einen fremden Grund:

| Fassung | hell | dunkel |
|---|---|---|
| `.badge.warn` im Streifen | **2,67:1** — fällt durch | 7,04:1 |
| `--accent` auf `--accent-surface` des Streifens | **8,42:1** | **8,42:1** |

Die 8,42 sind **zweimal unabhängig da**: einmal gemessen, einmal im Kommentar
von `app.css`, der sie für die Fläche des aktiven Menüpunkts schon nennt.

Derselbe Kommentar sagt die Regel, sechzig Zeilen über der Stelle:

> **Eine Markenfläche endet dort, wo eine Zustandsfarbe anfängt — sonst ist sie
> keine Fläche, sondern ein zweites Theme.**

**Damit braucht das Abzeichen keine neue Farbe und keine neunte Marke** — es
nimmt die, die der Streifen ohnehin führt. Und das passt zur Entscheidung des
Betreibers: Gezeigt werden **alle** Aktualisierungen, nicht nur die
sicherheitsrelevanten. Peach sagt „hier ist etwas", nicht „Warnung" — und das
ist genau die Aussage, die zu der Zahl gehört.

### 1.3 Die breite Ansicht ist die engere

| Breite | Schiene | je Eintrag frei |
|---|---|---|
| 1440 px | **236 px** (`grid-template-columns: 236px 1fr`) | **203 px** |
| 390 px | **272 px** (`min(272px, 82vw)`) | **239 px** |

> **Ein Fehler, den nur die breite Ansicht hat, entgeht einer Prüfung, die auf
> die schmale zielt.** Zum dritten Mal nach dem A9-Lauf und A14.

Gemessen an drei Zuständen des Eintrags „Updates":

| | 1440 px | 390 px |
|---|---|---|
| ohne Abzeichen | Höhe **39** | Höhe **44** |
| `.badge` wie sie ist (50 px breit) | Höhe **44** (+5) | Höhe 44 (**±0**) |
| dieselbe mit zwölf Stellen (141 px) | **28 px Überlauf** | kein Überlauf |
| **ohne Punkt, `padding: 0 8px`** (34 px breit) | Höhe **39** (±0) | Höhe 44 (±0) |

Zwei Dinge stehen damit fest. Erstens: Bei 390 px ist das Abzeichen **gratis** —
der Eintrag liegt dort ohnehin auf dem Mindestmass von `--tap`. Zweitens: Bei
1440 px kostet `.badge` in ihrer heutigen Form **5 px Höhe an genau einem
Eintrag**, und der steht dann höher als seine siebzehn Nachbarn.

**Die 5 px kommen nicht vom Umbruch, sondern vom Punkt.** `.badge::before` ist
ein 6-px-Kreis mit `gap: 6px`, und `padding: 3px 10px` macht die Marke 26 px
hoch gegen eine Zeilenhöhe von 21. Ohne den Punkt und mit `padding: 0 8px` misst
sie **34 × 20 px**, und der Eintrag bleibt bei 39.

Der Platz reicht bis zu einem Abzeichen von rund **113 px**, also etwa acht
Stellen. Ein Server mit 32 offenen Paketen ist weit davon entfernt; ein Server,
der es nicht wäre, hätte ein anderes Problem.

**Der Seitenüberlauf ist in allen vier Lagen `dokument = 0`**, Gegenprobe 200 —
gemessen mit `tests/bilder-messen.js` aus dem Repo.

### 1.4 Und zwei eigene Prüfkörper haben je die Hälfte gesehen

**Die erste Messung war die falsche Dimension.** Sie fragte
`scrollWidth - clientWidth` je Eintrag und meldete für jeden `0`. Der Grund:
`.nav-item` ist eine Flexzeile, und der Text ist ein anonymes Flexkind, das
**umbricht** statt überzulaufen. Der Schaden hätte keine Breite gehabt, sondern
eine Höhe.

> **Ein Prüfkörper, der die Breite misst, sieht einen Umbruch nicht — der macht
> den Kasten höher und nicht breiter.**

**Die zweite Messung hatte eine Gegenprobe, die nicht ausschlug.** Ein Abzeichen
mit zwölf Stellen ergab dieselben 44 px wie eines mit zwei — weil es nicht
umbricht, sondern überläuft. Erst **beide** Messungen nebeneinander zeigen
sowohl den Überlauf (28 px) als auch die Höhe (39 → 44).

> **Zwei Messungen, von denen die eine den Schaden manchmal sieht, ersetzen
> einander nicht.** Derselbe Satz wie in `docs/108`, dort an einer
> Kennungszelle.

**Und der Aufsatz brauchte das `data-v`-Attribut.** `.nav-item` steht in einem
`<style scoped>` von `PanelLayout.vue`; Vite übersetzt das zu
`.nav-item[data-v-219b02fc]`. Ohne das Attribut am Markup hätte der Aufsatz
**keine einzige** Navigationsregel getroffen und trotzdem Zahlen geliefert.

### 1.5 Ein Verschluss und kein fertiger Wert

| `/services` | Abfragen | Dauer |
|---|---|---|
| voll | **3** | 25,1 ms |
| partiell nachgeladen | **2** | 2,7 ms |

Dieselbe Form wie `docs/103`: Ein **fertiger** Wert in `share()` läuft auch bei
einem partiellen Nachladen, das ihn gar nicht mitschickt; ein **Verschluss**
nicht. Bei 2,7 ms für ein partielles Nachladen wäre eine zusätzliche Abfrage
rund vier Prozent — vermeidbar, also vermieden.

**Und die Zahl ist gegen beide Treiber gemessen**, weil eine gegen SQLite
allein die Grenzen der falschen Datenbank prüft:

| `Setting::find()` | je Aufruf |
|---|---|
| SQLite (der Container) | **0,104 ms** |
| **MariaDB 10.11.14** (die Fassung von `cloudsrv24`) | **0,279 ms** |

Faktor 2,7 zwischen den beiden — und die MariaDB-Zahl steht damit immer noch
rund **zehntausendmal** unter den 2954 ms, die sie ersetzt.

> **Ein Test, der gegen eine andere Datenbank läuft als der Server, prüft die
> Grenzen der falschen.** Die Abfragezahlen oben (voll 3, partiell 2) sind
> treiberunabhängig; die Millisekunden waren es nicht.

### 1.6 Nachgemessen an der gebauten Seite — und ein Befund dabei

Alles über dieser Zeile ist an einem **handgeschriebenen** Aufsatz gemessen
worden: das Markup der Leiste nachgebaut, das gebaute Stylesheet daneben. Am
11. September steht die Seite wirklich da, und die Messung ist wiederholt
worden — vier Lagen, `/accounts`, Abzeichen mit dem Wert 32:

| gemessen | hell 390 | hell 1440 | dunkel 390 | dunkel 1440 |
|---|---|---|---|---|
| Fläche | `rgba(255,183,165,.14)` | dieselbe | dieselbe | dieselbe |
| Schrift | `rgb(255,183,165)` | dieselbe | dieselbe | dieselbe |
| Grund darunter | `26,11,46` | `26,11,46` | `26,11,46` | `26,11,46` |
| **Kontrast** | **8,42:1** | **8,42:1** | **8,42:1** | **8,42:1** |
| Grösse | 34,09 × 19,5 px | dieselbe | dieselbe | dieselbe |
| `dokument` | 0 | 0 | 0 | 0 |
| Gegenprobe | 200/200 | 200/200 | 200/200 | 200/200 |

Dass in drei Spalten „dieselbe" steht, ist die Aussage und keine Auslassung:
Die Leiste setzt `--nav-bg` in **beiden** Themen auf `#1a0b2e`, und deshalb
ändert das Umschalten dort nichts (§1.2).

Der Aufsatz hat also nicht ungefähr gestimmt, sondern **auf zwei
Nachkommastellen** — dieselbe Erfahrung wie am 16. August, als die
Kärtchenhöhen des Dateimanagers im Container und auf `cloudsrv24` dieselben
vier Zahlen ergaben.

**Die Kontrastrechnung trägt ihre eigene Gegenprobe**: Sie rechnet im selben
Lauf schwarz auf weiss (**21,00**) und weiss auf weiss (**1,00**). Ohne die
beiden wäre „8,42" eine Zahl und keine Messung.

> **Eine Zahl aus einer Rechnung, die man nicht an zwei bekannten Paaren
> nachgeprüft hat, ist ein Ergebnis der Rechnung und keines über den
> Gegenstand.**

**Und der Befund steht in der Zeile daneben: Auf dem Telefon sieht das
Abzeichen niemand.** Unter 720 px ist die Leiste eine Schublade
(`transform: translateX(-100%)`), und zugeklappt steht das Abzeichen bei
**x = −63 px**, also ganz ausserhalb des Bildes. Aufgeklappt ist es da und
richtig — gemessen sind dieselben 8,42:1 und dieselben 34,09 × 19,5 px.

Das ist kein Fehler im Bau: Die Schublade gibt es seit `docs/24`, und ein
Menüpunkt ist dort für jeden Zweck unsichtbar, bis jemand das Menü öffnet.
Es ist ein Fehler am **Zweck**. Bestellt war „erhöhte Aufmerksamkeit auf die
Updates", und Aufmerksamkeit setzt voraus, dass man etwas sieht, ohne es zu
suchen.

> **Ein Hinweis, der in einer Schublade liegt, erreicht nur den, der die
> Schublade ohnehin öffnet — und der wusste es schon.**

Gemessen und nicht behoben: Was in der Kopfleiste eines Telefons stehen darf,
ist eine Gestaltungsfrage und keine Messfrage. Sie steht in §4.

---

---

## §2 Die drei Entscheidungen des Betreibers

1. **Im Abzeichen steht die Zahl aller aktualisierbaren Pakete** — hier 32 —,
   nicht nur die der sicherheitsrelevanten.
2. **Eingesammelt wird nach jeder Änderung und zusätzlich stündlich.** Der
   stündliche Lauf ist der Rückfall für das, was ausserhalb des Panels
   geschieht — `unattended-upgrades` arbeitet auf eigenem Takt.
3. **Kein Flimmerschutz für den Platzhalter.** Die 300 ms aus `docs/904 §2`
   bleiben eine Planungsregel („was wird nachgereicht") und werden kein
   Mechanismus zur Laufzeit.

---

## §3 Der Bau

### 3.1 Wo die Zahl herkommt

Ein Einsammler schreibt `Setting` mit der Zahl **und dem Zeitpunkt**. Der
Zeitpunkt ist kein Beiwerk:

> **Ein Wert, der aus „jetzt" folgt und abgelegt wird, ist ab dem nächsten
> Augenblick falsch — die Frage ist nur, wie schnell es auffällt.**

Das ist die Narbe aus `docs/108`: `next_due` war eine Spalte, die einen falschen
Wert ein Jahr lang über eine Behebung hinweggetragen hat. Ein Abzeichen, das
„32" sagt, während null anstehen, ist dieselbe Form.

### 3.2 Wann eingesammelt wird

**„Nach jeder Änderung" ist nicht „nach jedem Klick".**
`system.packages.upgrade` ist **Form A** aus `docs/86 §5`: Es *setzt ab* und
kennt den Ausgang nicht. Der Einsammler hängt deshalb am **beendeten Lauf** und
nicht am Knopf — `AwaitDispatchedRun` gibt es seit A1 genau dafür. Am Knopf
gehängt schriebe er die alte Zahl in dem Augenblick fest, in dem sie sich
gerade ändert.

Dazu ein stündlicher Timer. **Er fragt `AptLock` nicht, und das stand hier
zuerst falsch.** Der Plan verlangte die Frage, weil der Lauf apt ruft — im
Quelltext steht begründet das Gegenteil: `system.packages.list` ist die **eine**
Operation, die die Sperre nicht braucht, weil `apt-get -s` bei gehaltener
Sperre läuft. `AptLockReachTest::EXCEPTIONS` trägt sie mit genau diesem
gemessenen Grund.

> **Ein Plan, der eine Vorkehrung verlangt, die der Prüfling begründet nicht
> braucht, prüft den Verfasser.**

### 3.3 Was die Navigation liest

Ein **Verschluss** in `HandleInertiaRequests::share()` (§1.5), und der Wert
trägt die Fähigkeit seiner Route: Der Menüpunkt „Updates" steht auf
`inspect-server`, das Abzeichen darf nicht weiter reichen.
`AdminPayloadTest` hält, dass jeder Menüpunkt die Fähigkeit seiner Route trägt.

### 3.4 Wie es aussieht

Eine Marke aus den Marken des Streifens — `--accent` auf `--accent-surface`,
**8,42:1** —, ohne den Punkt und mit `padding: 0 8px`, damit der Eintrag seine
39 px behält. Rechts ausgerichtet über `margin-left: auto`: `.nav-item` ist eine
Flexzeile mit `gap: 10px` und **ohne** `justify-content: space-between`, ein
drittes Kind klebte sonst am Wort.

---

## §4 Was benannt offen bleibt

- *(Beantwortet am 11. September — §7.)* Was das Abzeichen tut, wenn der Wert
  alt ist: **nichts**, und der Zeitpunkt steht auf `/updates`.
- *(Beantwortet am 11. September — §6.)* Dass das Abzeichen auf dem Telefon
  niemand sieht, ist mit einem Punkt am Menüknopf behoben.

---

## §6 Der Punkt am Menüknopf — gemessen am 11. September 2026

Der Betreiber hat ihn nach dem Befund aus §1.6 bestellt: „Punkt am Menüknopf in
der Kopfleiste, bitte messen und bauen." Gemessen wurde **vor** dem Bau, mit
einem Prüfkörper in der lebenden Seite; gebaut wurde danach die Form, die
gewonnen hat.

### 6.1 Die tragende Messung hat die Bauform entschieden

`.nav-toggle` ist ein Raster mit `place-items: center` und **einem** Kind. Was
ein zweites Kind anrichtet, stand nicht zur Vermutung:

| | Verschiebung des Zeichens | Höhe der Kopfleiste |
|---|---|---|
| ohne Punkt | — | 65 px |
| Punkt **im Fluss** | **y = −6,5 px** | 65 px |
| Punkt **absolut** | 0 | 65 px |
| Prüfkörper entfernt | 0 | 65 px |

> **Ein Schaden, der innerhalb eines Knopfes sitzt, hat auf Seitenebene keine
> Zahl, die sich beschwert.**

Dieselbe Familie wie die gestapelte Zelle aus dem A6-Lauf, die genau ein Kind
verträgt — dort zog `space-between` den Inhalt auseinander, hier zieht
`place-items` ihn nach oben. Der Punkt sitzt deshalb `absolute`, und
`.nav-toggle` trägt dafür `position: relative`.

### 6.2 Die Lage kommt von der Tinte und nicht vom Kasten

| | oben | rechts | Grösse |
|---|---|---|---|
| Kasten des `<svg>` | 10 px | 10 px | 24 × 24 |
| **Tinte der drei Striche** | **17 px** | **14 px** | **16 × 10** |

Der Kasten ist zu zwei Dritteln leer. Gegen ihn gemessen sähe `top/right: 8px`
nach einer Überlappung von 4 px aus; gegen die **Tinte** gemessen sind es 3 px
Luft. Drei Lagen durchprobiert (8, 6 und 4 px), alle drei frei — und die
Gegenprobe, ein Prüfkörper über den ganzen Knopf, **trifft** die Tinte. Ohne
sie hiesse „trifft nicht" nur, dass der Test nichts misst.

`top/right: 8px` gewinnt: Dort steht der Punkt genau an der rechten Kante der
Tinte und 3 px über ihr — angeheftet, ohne sie zu berühren. **6 px Durchmesser**
ist das Hausmass aus `.badge::before`; eine zweite Grösse daneben wäre die, die
veraltet.

### 6.3 Farbe, Überlauf und die breite Ansicht

| gemessen | hell | dunkel |
|---|---|---|
| gemalt | `rgb(255,183,165)` | dieselbe |
| Grund | `26,11,46` | `26,11,46` |
| **Kontrast** | **11,11:1** | **11,11:1** |
| `dokument` | 0 | 0 |
| Gegenprobe | 200/200 | 200/200 |

Verlangt sind **3:1** (WCAG 1.4.11) — der Punkt ist kein Text. Und bei 1440 px
ist die Kopfleiste `display: none`, der Knopf 0 px hoch: Der Punkt ist
ausschliesslich ein Telefon-Merkmal, während das Abzeichen die breite Ansicht
deckt. Zusammen schliessen sie die Lücke aus §1.6.

### 6.4 Der Punkt bekommt seinen Namen vom Knopf

Er trägt `aria-hidden` und hat keinen Namen, den man vorlesen könnte. Die
Beschriftung des Knopfes ist deshalb gebunden und lautet gemessen
**„Navigation, 32 Aktualisierungen stehen an"**. Der Wortlaut ist abgeschrieben
und nicht erfunden: `/updates` sagt „Es steht keine Aktualisierung an". Die
Einzahl steht ausdrücklich da — „1 Aktualisierungen" ist genau der Befund, für
den es `CountedNounTest` gibt.

> **Ein Hinweis, der nur eine Farbe ist, erreicht niemanden, der die Farbe nicht
> sieht.**

### 6.5 Zwei Befunde am Prüfmittel, und beide hat dieselbe Gegenprobe verfehlt

**Der erste:** Die Farbe wurde zuerst über
`getComputedStyle(el).getPropertyValue('--accent')` geholt und kam als
**`#ffb7a5`** zurück — eine Marke ist die Zeichenkette, die in der Datei steht,
und keine berechnete Farbe. Der Ausdruck `[\d.]+` fand darin „7" und „5", und
heraus kam `NaN`.

> **Eine Marke, die man als Text ausliest, ist nicht die Farbe, die gemalt wird.**

**Der zweite:** Die Nachmessung am gebauten Punkt las ihre Rechnung per Regex
**aus einer Datei**. Dort steht `[\\d.]+`; die Verdopplung löst das
Template-Literal erst beim Auswerten auf. Als Dateitext gelesen blieb sie
stehen, die Zeichenklasse traf danach Backslashes und Punkte — **keine
Ziffern**. `grund()` fiel auf sein Weiss zurück und meldete `1,00:1` für einen
Punkt mit 11,11:1.

> **Eine Zeichenkette, die ein Template-Literal erzeugt, ist nicht die
> Zeichenkette, die in der Datei steht — die Maskierung geschieht beim Auswerten
> und nicht beim Lesen.**

**Und beide Male stand die Gegenprobe auf Grün.** Sie rechnet schwarz auf weiss
(21,00) und weiss auf weiss (1,00) — beides mit fest hingeschriebenen Zahlen,
die keinen Leser brauchen.

> **Eine Gegenprobe an der Rechnung sagt nichts über den Wert, der in sie
> hineingeht.**

Die Messung trägt seitdem eine Gegenprobe am **Leser**: `parse('rgb(26, 11, 46)')`
muss `26,11,46,1` ergeben. Sie hätte beide Fehler gefangen.

### 6.6 Ein Eingriff, den dieser Zweig stumpf gemacht hat

Der volle Bruchlauf meldete **2346 beissende Prüfungen und eine ohne Biss**:
„Wrapper-Liste zusammengefallen — Eingriff hat nichts geändert". Er trug die
Kommandoliste von `packaging/bin/srvpanel` **wörtlich**, und der Bau des
Abzeichens hat `packages` hineingeschrieben.

> **Ein Eingriff misst nicht nur die Datei, die er anfasst — er misst jede, die
> sein Wächter liest.**

Gefangen hat es die Zusicherung im Eingriff selbst (`s.count(alt) == 1`): Er hat
sich geweigert, blind zu messen, statt still nichts zu tun. Er sucht seine
Zielstelle seitdem **ihrer Form nach** — eine Zeile aus mindestens sechs
`|`-getrennten Namen, die auf `)` endet — und nicht ihrem Wortlaut nach; sonst
wäre er beim nächsten Kommando wieder blind.

---

## §7 Was das Abzeichen tut, wenn die Zahl alt ist — entschieden am 11. September

Die Frage aus §4. Vier Formen standen zur Wahl, und **zwei fallen an einer
Messung aus, die schon vorlag**:

| | was es tut | warum nicht |
|---|---|---|
| A | nichts, Zahl ohne Alter | zwei Zustände sehen gleich aus |
| B | Abzeichen verschwindet ab einer Schwelle | **Stille heisst „nichts zu tun"** |
| C | Abzeichen wird grau | der Streifen führt **keine** Zustandsfarbe (2,67:1, §1.2) |
| **D** | **Zahl bleibt, das Alter steht, wo Platz ist** | gebaut |

B ist nicht Geschmackssache: Es ist derselbe Fehler wie eine `0`, die „nicht
nachgesehen" bedeutet, nur in der Navigation. Und die Richtungen sind nicht
gleich teuer:

> **Ein Zeiger, der etwas zu viel behauptet, kostet einen Klick. Einer, der
> schweigt, kostet den Weg.**

### 7.1 Drei Viertel der Antwort standen schon da

**`/updates` stellt diese Frage seit P7b und beantwortet sie.** Im Kopf der
Seite steht wörtlich, dass „0 Aktualisierungen" zwei sehr verschiedene Gründe
hat — der Server ist aktuell, oder apt kommt an seine Quellen nicht heran —,
und die Antwort dort ist die **Quellenliste neben der Zahl**.

**Der Einsammler wird bewacht.** `srvpanel-packages.timer` steht im Katalog
(`agent/src/Catalog.php`); `/services` zeigt ihn, und der Nachtlauf meldet ihn
über `unit.schedule / no_next` — *ein Timer ohne Termin ist ein Befund*. Die
**Ursache** einer alten Zahl ist also schon sichtbar.

**Und der Gegenstand des Zeigers ist frisch:** `/updates` holt live (3033 ms).

> **Eine zweite Fassung einer Auskunft, die es schon gibt, ist die, die
> veraltet.**

### 7.2 Was wirklich fehlte, war ein Leser

`Settings::pendingUpdatesCheckedAt()` stand seit dem Vormittag da und wurde von
**niemandem** gerufen — ausgezählt über `app/` und `resources/js`.

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt.**

Gebaut ist ein Satz auf `/updates`: *„Am Menüpunkt „Updates" steht die Zahl vom
…"* — und für `null` der andere Satz, denn „noch nie" ist etwas anderes als
„vor langer Zeit".

**Zwei Entscheidungen daran sind gemessen und nicht geraten.**

Der Zeitpunkt wird in `show()` gelesen und **nicht** in `packages()`: `show()`
läuft vor dem Nachreichen, der Wert ist also der von **vor** diesem Besuch.
Stünde er im nachgereichten Teil, schriebe ihn derselbe Aufruf, der ihn zeigt —
und die Antwort wäre immer „gerade eben".

Und der Satz steht **über** dem dreiwertigen Zweig: Sein nützlichster
Augenblick ist der Platzhalter, in dem die Zahl am Menüpunkt das Einzige ist,
was dasteht. Im nachgereichten Teil fehlte er genau dann.

**Keine Schwelle in Stunden** — sie wäre eine Zahl, die niemand gemessen hat,
und sie müsste raten, ab wann ein Betreiber eine Auskunft nicht mehr glauben
soll.

### 7.3 Und der Wächter dazu hat sich beim Gegenprüfen selbst korrigiert

`PendingUpdatesReachTest` hält beide Richtungen: Jeder Leser der Ablage wird
gerufen **und** kommt auf einer Seite an, und der Schreiber legt nichts ab, das
kein Leser holt. Die Leser kommen aus der Klasse und nicht aus einer Liste im
Test.

**Sein erster Wurf las die `.vue` im Ganzen — und blieb grün**, als der
gerenderte Satz entfernt wurde: Die Prop-Deklaration im `<script setup>` trägt
denselben Namen. Gemessen, nicht überlegt; gesucht wird seitdem im
Vorlagenblock.

> **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
> steht — und eine Deklaration ist keine Anzeige.**

Drei Eingriffe, alle gefahren, alle rot: der Satz fort (Deklaration bleibt), der
Aufrufer fort, ein drittes Feld ohne Leser.

### 7.4 Und ein bestehender Wächter hat den Satz gemeldet, bevor ihn ein Auge sah

`BlockSpacingTest` wurde beim vollen Lauf rot: Der neue `<p class="quiet">`
steht unmittelbar über `.skeleton-stack`, und `app.css` kannte diese
Nachbarschaft nicht. Behoben ist es **im Stylesheet** und nicht mit einem Rand
auf der Seite — ein Abstand in der Vorlage wäre derselbe Fehler wie ein Hexwert
in einer Komponente.

**Gemessen im Platzhalterzustand**, festgehalten durch Anhalten der
nachgereichten Anfrage (`X-Inertia-Partial-Data`, `docs/906`):

| | Fuge `.quiet` → `.skeleton-stack` |
|---|---|
| **mit** der Regel | **24 px** |
| ohne die Regel (Gegenprobe) | **0 px** |

Derselbe Lauf belegt nebenbei, wofür der Satz an dieser Stelle steht: Im
Platzhalter ist er da und lesbar — *„Am Menüpunkt „Updates" steht die Zahl vom
2026-09-11 13:55:29."* — während vom Paketstand noch nichts dasteht.

> **Ein Abstand, der aus der Reihenfolge der Seite abgeleitet ist, fällt mit der
> nächsten Ergänzung.** Zum zehnten Mal an derselben Regelkette, und zum ersten
> Mal, bevor eine Aufnahme entstanden ist.

---

## §5 Was dieses Merkmal ausdrücklich **nicht** wird

- **Kein zweites Abzeichen an einem anderen Menüpunkt.** Die Regel aus §1.2
  gilt für jedes: Der Streifen trägt keine Zustandsfarbe, und wer dort eine
  zweite Zahl unterbringen will, misst sie zuerst.
- **Keine Zahl, die live geholt wird** — §1.1.
- **Keine Automatik, die Updates einspielt.** Das Abzeichen zeigt und handelt
  nicht.
