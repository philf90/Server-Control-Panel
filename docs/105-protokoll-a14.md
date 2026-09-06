# Protokoll des A14-Abnahmelaufs — die Ankündigungen auf `cloudsrv24`

Der Plan ist `docs/103`, der Lauf `docs/104`, ausgeschrieben am 6. September
2026 **vor** dem Fahren. Dieses Dokument hält fest, was gemessen wurde, was
dabei gefunden wurde und was offen bleibt.

**Stand: alle acht Punkte sind gefahren und erfüllt**, beide
Ausschlusskriterien (3 und 6) darunter, keiner als „nicht herstellbar"
ausgefallen. Die Bilanz steht in §11, was offen bleibt in §12.

Gefahren am **6. September 2026** auf `cloudsrv24`, gegen drei Fassungen:

| | Fassung |
|---|---|
| Punkte 1, 2, 4, 5, 6, 7, 8 | `0.7.3-rc.20` |
| Nachsehen der zwei Behebungen | `0.7.3-rc.21` |
| Punkt 3 und die Bilderrunde | `0.7.3-rc.22` |

**Drei Fassungen sind kein Mangel des Laufs, sondern seine Wirkung.** Zwei
Befunde am Prüfling wurden während des Laufs behoben und danach an derselben
Stelle nachgesehen; der dritte kam aus einem Merkmal, das der Betreiber
mitten im Lauf entschieden hat.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**

---

## 1 · Punkt 1 — eine Ankündigung erscheint

Gegen `0.7.3-rc.20`, **erfüllt.** Eine Ankündigung angelegt; der Streifen steht
oben, die Kategorie als Wort, die Farbe der Marke.

Der Punkt ist eine Beobachtung und keine Messung mit Prüfkörper — er fragt, ob
etwas erscheint, nicht wie gross es ist.

## 2 · Punkt 2 — drei gleichzeitig, und keiner verdeckt

Gegen `0.7.3-rc.20`, **erfüllt.** Drei Streifen untereinander, bei 1440 px.

**Der Punkt gibt es wegen M2** (`docs/81 §2.3q`): `.frame` ist ein Raster mit
zwei Zeilen, `.band` nimmt `grid-row: 1` ausdrücklich, und drei Bänder lägen
damit bei 1440 px **aufeinander** — sichtbar wäre nur das letzte, und `schiebt`
stünde dabei auf `0`. Bei 390 px stapeln dieselben drei korrekt.

> **Ein Fehler, den nur die breite Ansicht hat, entgeht einer Prüfung, die auf
> die schmale zielt.**

## 3 · Punkt 3 — die Höhe hängt nicht an der Textlänge *(Ausschluss)*

Gegen `0.7.3-rc.22`, **erfüllt.** Gemessen mit `tests/baender-messen.js` bei
genau 390 px, drei Ankündigungen, zwei Runden:

| | Runde 1 | Runde 2 |
|---|---|---|
| `zeichen` (mit Rangwort) | 128 · 128 · 125 | **508 · 508 · 505** |
| `hoehen` | `[62]` | `[62]` |
| `gleich` | `true` | `true` |
| `huelle` | **214** | **214** |
| `schiebt` | 0 | 0 |
| `gegenprobe` | **200** | **200** |

Der vierfache Text ändert an der Höhe nichts. Das ist die Eigenschaft, für die
es diesen Punkt gibt; die Zahlen daneben stehen da, damit ein Ausreisser
auffällt.

**Die offene Zahl ist entschieden: 214.** `docs/103 §8` liess sie ausdrücklich
offen — 214 aus einer Messung an der echten Seite gegen 226 aus einem
Wegwerf-Aufsatz. Sie steht dort seit dem 6. September nachgetragen; die
Begründung ist Befund 7 (§10.7).

## 4 · Punkt 4 — 500 Zeichen, und ein Kunde kommt an den vollen Text

Gegen `0.7.3-rc.20`, **erfüllt.** Im Streifen zwei Zeilen; als Kunde das Band
angeklickt und den vollen Text auf `/announcements/{id}` gelesen, ohne 403.

**Dieser Punkt war vor dem Lauf nicht erfüllbar**, gefunden im Vorflug — das ist
Befund 6 (§10.6). Die Leseseite ist deswegen entstanden.

## 5 · Punkt 5 — das Publikum trennt

Gegen `0.7.3-rc.20`, **erfüllt.** Ein Kundenkonto sieht die Kundenankündigung
und nicht die für Administratoren.

## 6 · Punkt 6 — das Fenster, mit einem Versatz *(Ausschluss)*

Gegen `0.7.3-rc.20`, **erfüllt** — aber erst nach Befund 1 (§10.1).

Mit der Anzeigezeitzone auf `Europe/Berlin`: sichtbar **während** der
eingetippten Ortszeit, davor und danach nicht. Auf der Übersicht stand danach
**genau ein** Band von dreien.

**Die erste Messung war keine.** Sie lief auf `/announcements`, und genau dort
verdeckte die Seiten-Eigenschaft die geteilte — alle drei Bänder standen oben,
obwohl die Tabelle daneben zwei als `wartet` und `abgelaufen` führte.

> **Ein Prüfkörper, der an der einen Stelle misst, an der der Gegenstand
> verdeckt ist, misst etwas anderes.**

**Der Punkt misst mit einem Versatz und nicht in UTC**, und das ist tragend: In
UTC sind der richtige und der falsche Vergleich gleich, eine fehlende Umrechnung
sähe aus wie eine gelungene (M7).

## 7 · Punkt 7 — die Anmeldeseite trägt Störungen und sonst nichts

Gegen `0.7.3-rc.20`, **erfüllt.** Abgemeldet sichtbar; eine Ankündigung der
Kategorie Info dort nicht.

Befund 2 (§10.2) betrifft diesen Punkt und ist **kein** Kriterienausfall: Er
fragt, **was** dort steht, nicht wie es eingerückt ist.

## 8 · Punkt 8 — das partielle Nachladen

Gegen `0.7.3-rc.20`, **erfüllt.** Der Streifen steht nach dem Selbstlauf der
Übersicht unverändert da.

---

## 9 · Die Bilderrunde

Gegen `0.7.3-rc.22`, auf `/announcements`, mit `tests/bilder-messen.js`:

| Lage | `dokument` | `gegenprobe` | `schiebt` | `rollt` | `versteckt` |
|---|---|---|---|---|---|
| 390 hell | 0 | **200** | 0 | 0 | 2 |
| 390 dunkel | 0 | **200** | 0 | 0 | 2 |
| 1440 hell | 0 | **200** | 0 | 0 | 0 |
| 1440 dunkel | 0 | **200** | 0 | 0 | 0 |

Die Gegenprobe schlägt in allen vieren an; die Nullen daneben bedeuten also
etwas. Beide Themen sind über den Umschalter des Panels gestellt und nicht über
`prefers-color-scheme` — `app.css` kennt keine solche Regel.

**Gemessen wurde `/announcements` und nicht die Übersicht**, weil Punkt 3 die
Übersicht bei 390 px bereits mit `schiebt=0` und Gegenprobe 200 abgedeckt hat.
Offen war das dunkle Thema, die breite Ansicht — und die Seite mit der Tabelle,
der Vorschauzeile und der Knopfreihe aus Befund 3.

---

## 10 · Die Befunde

**Zehn Befunde, fünf im Prüfling und fünf im Prüfmittel, im Kriterium oder in
der Vorschrift.** Keinen hat ein Test gefunden.

### 10.1 Die Seiten-Eigenschaft `announcements` überschrieb die geteilte

*(Prüfling · behoben in `0.7.3-rc.21` · nachgesehen)*

Auf `/announcements` zeigte der Streifen alle Zeilen der Verwaltung statt der
sichtbaren. Der Filter war in Ordnung, der Schlüssel nicht:
`AnnouncementController::index()` gab seine Liste als `announcements` heraus,
und genau so heisst die geteilte Eigenschaft.

> **Ein geteilter Schlüssel, den eine Seite auch benutzt, ist auf genau dieser
> Seite fort.**

Es fiel nicht als Fehler auf, weil die Verwaltungszeilen dieselben Felder
tragen, die der Streifen braucht. Aufgefallen ist es als **Widerspruch auf
derselben Seite**.

> **Zwei Anzeigen derselben Sache auf einer Seite, die einander widersprechen,
> sind der einzige Weg, diesen Fehler zu sehen.**

Denselben Satz hat A9 schon bezahlt — die geteilte Fähigkeitsablage heisst
`abilities` und nicht `can`, weil `can` vergeben war. Daraus wurde damals keine
Regel; `SharedPropTest` ist sie jetzt.

### 10.2 Auf den Anmeldeseiten fehlte die Hülle `.bands`

*(Prüfling · behoben in `0.7.3-rc.21` · nachgesehen)*

Gemeldet vom Betreiber am Bild: Das Band lag bündig am Bildschirmrand statt
eingerückt. Kein Kriterienausfall. In die Komponente kann die Hülle nicht
ziehen — im Panel trägt dieselbe Hülle den Balken für „Anmelden als" und nimmt
`grid-row: 1`; zwei Geschwister mit derselben Rasterzeile wären der M2-Befund
zurück.

### 10.3 Zwei Knöpfe in einer Zelle ohne Abstand

*(Prüfling · behoben in `0.7.3-rc.22` · nachgesehen)*

Gemeldet vom Betreiber an der Vorschau, die einen Tag vorher entstanden war:
„Vorschau" und „Entfernen" stiessen direkt aneinander. **Die Regel dafür gab es
seit P5b** — `td.right > .button-row` steht in `app.css`, und ihr Kommentar
nennt die Folge wörtlich. Ihr Anlass war derselbe Fehler auf der PHP-Seite
(`docs/38 §24.2`), damals ebenfalls vom Betreiber auf dem Server gefunden.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.**

Nachgesehen bei 1440 px auf dem Server: 10 px Lücke statt 0.

### 10.4 Eine Ankündigung lässt sich nicht ändern

*(Prüfling · offen)*

Gemeldet vom Betreiber beim Fahren von Punkt 3: Auf `/announcements` gibt es
„Vorschau" und „Entfernen" und nichts dazwischen. `routes/web.php` kennt
`store` und `destroy`; ein `edit`/`update` gibt es nicht.

**Und es war keine Entscheidung.** `docs/103 §10` zählt auf, was A14
ausdrücklich nicht wird — kein Wegklicken, keine Adressierung je Abonnement,
kein Zeitgeber, keine Benachrichtigung. Bearbeiten steht dort nicht, und §5
listet „Felder je Ankündigung" so, wie man ein Formular beschreibt, das auch
zum Ändern dient.

> **Eine Aufzählung dessen, was ein Merkmal nicht wird, ist nur dann eine
> Entscheidung, wenn das Fehlende darin steht — sonst ist sie eine Lücke mit
> Überschrift.**

Zwei Folgen machen das mehr als eine Bequemlichkeit. Der Streifen ist ein
**Verweis** auf `/announcements/{id}`; wer eine laufende Ankündigung durch
Löschen und Neuanlegen berichtigt, gibt ihr eine neue Kennung, und ein Kunde
mit dem alten Verweis bekommt einen 404.

> **Eine Ressource, auf die ein Verweis zeigt, lässt sich nicht durch Löschen
> und Neuanlegen berichtigen — der Verweis zeigt danach ins Leere.**

Und das Protokoll erzählt etwas anderes als geschehen: eine Berichtigung
hinterlässt „entfernt" und „angelegt" statt „geändert".

Der Betreiber hat entschieden, dass es nachgerüstet wird. Es steht in §12.

### 10.5 Das Messmittel druckte sein Urteil nicht

*(Prüfmittel · behoben)*

Die Bilderrunde wurde einmal vollständig gefahren und war nicht lesbar: Die
Konsole klappt ein zurückgegebenes Objekt auf fünf Schlüssel zusammen, und in
allen vier Lagen stand `gegenprobe: {…}` da und `schiebt` gar nicht. Sichtbar
war `dokument: 0` — also derselbe Wert, den auch eine Messung liefert, die
nichts misst.

> **Ein Objekt in der Konsole zeigt fünf Schlüssel und klappt den Rest weg —
> und was man abschreibt, ist dann eine Auswahl, die niemand getroffen hat.**

`baender-messen.js` hatte die gedruckte Zeile am selben Vormittag bekommen, mit
genau dieser Begründung im Kommentar. `bilder-messen.js` nicht — dieselbe
Familie wie Befund 3, nur im Prüfmittel.
`OverflowProbeTest::test_every_instrument_prints_one_line` hält sie jetzt für
**jedes** Messmittel.

### 10.6 Punkt 4 des Kriteriums war nicht erfüllbar

*(Kriterium · behoben vor dem Lauf)*

Gefunden im Vorflug. Punkt 4 verlangte einen Verweis vom Streifen auf den vollen
Text, `docs/103 §4.3` nannte dafür die Verwaltungsseite — der Verweis war nie
gebaut, und sein Ziel steht hinter `operate-server`.

> **Ein Verweis auf einen Ort, den der Leser nicht betreten darf, ist kein Weg
> zum Text — er ist eine zweite Sackgasse.**

Der Betreiber hat die Leseseite für alle gewählt. `GET /announcements/{id}`
liegt ausserhalb der auth-Klammer, damit der Streifen der Anmeldeseite seine
Störung zu Ende erzählen kann; neu sichtbar wird nichts, und alles andere ist
404 statt 403 — ein 403 bestätigte die Existenz.

### 10.7 Zwei Messungen der Hüllenhöhe gingen auseinander

*(Prüfmittel · entschieden)*

214 aus einer Messung an der echten Seite gegen 226 aus einem Wegwerf-Aufsatz.
Entschieden hat es eine dritte Messung: an der echten Seite unter
`artisan serve`, angemeldet, in drei Bestückungen und beiden Themen — jedes Mal
**214**. Der Serverlauf hat es bestätigt.

> **Zwei Messungen, die auseinandergehen, entscheidet keine Überlegung, sondern
> die dritte — und die muss den Weg des Prüflings nehmen und nicht den
> bequemeren.**

Der Wegwerf-Aufsatz lag um 12 px daneben, systematisch. Er bleibt richtig für
eine Frage nach dem **Überlauf** und ist es nicht für eine nach der **Höhe**:
Was ihm fehlt, ist alles, was oberhalb und unterhalb des gemessenen Bausteins
auf der Seite steht. Derselbe Grund stand als 63 px im Kommentar an
`.band .clamped`; dort stehen jetzt die gemessenen 62.

### 10.8 Das Kriterium war wörtlich falsch

*(Kriterium · berichtigt)*

„Jedes Band gleich hoch, gleich wie lang sein Text ist" gilt **oberhalb der
Umbruchschwelle**. Gemessen bei 390 px, mitsamt Rangwort: 23 Zeichen → 41 px,
40 → 41 px, 65 → 62 px. Zugesagt ist eine **Obergrenze** und keine feste Höhe.

`docs/104 §3` verlangte als Prüfkörper 60 Zeichen — mit „Störung" davor sind das
67, also keine zwanzig Zeichen über der Schwelle.

> **Ein Prüfkörper, der dicht an einer Schwelle liegt, misst die Schwelle und
> nicht die Eigenschaft.**

Der Lauf nennt jetzt 120. Und er misst bei 390 px und nicht breit: Bei 1440 px
ergeben dieselben drei Bänder `[41, 62]`, weil dort rund 160 Zeichen in eine
Zeile passen.

> **Eine Zusage, die an eine Breite gebunden ist, liest sich wie eine über jede
> Breite.**

### 10.9 Eine Erwartung der Vorschrift stimmte auf dem Server nicht

*(Vorschrift · benannt)*

Für die Bilderrunde bei 1440 px war `rollt=1` angesagt — der Behälter um die
Tabelle. Gemessen wurde **0**. Ein Roller taucht nur auf, wenn er auch
überläuft; im Container war die Tabelle 43 px breiter als ihr Behälter, auf dem
Server passt sie hinein.

> **Eine Erwartung aus einer Messung unter anderen Bedingungen ist eine
> Vermutung, auch wenn sie aus einer Messung stammt.**

Hätte sie als Sollwert im Protokoll gestanden, wäre aus richtigem Verhalten ein
Mangel geworden — derselbe Fall wie `tls.wire` in `docs/100 §6`.

### 10.10 `SharedPropTest` fand drei Kollisionen und nicht eine

*(Prüfling · behoben, eine davon älter als A14)*

Der Wächter zu Befund 1 hat beim ersten Lauf drei Stellen gemeldet. Die dritte
sitzt in `Accounts/Form` und stammt aus A9: Die Seite gab das **bearbeitete**
Konto als `account` heraus — den Namen, unter dem `PanelLayout` das
**angemeldete** liest und über dessen `is_admin` es die ganze Navigation
umschaltet. Dass sie nicht umsprang, lag allein daran, dass das Feld in der
Seitenlast fehlt.

> **Eine Sicherheit, die aus einer Eigenschaft der Daten folgt und nicht aus
> einer Prüfung, hält genau so lange, bis jemand die Daten ändert.**

---

## 11 · Die Bilanz

**Alle acht Punkte erfüllt**, beide Ausschlusskriterien (3 und 6) darunter,
keiner als „nicht herstellbar" ausgefallen. Die Bilderrunde ist in vier Lagen
gefahren, die Gegenprobe schlägt in jeder an.

**Fünf der zehn Befunde stecken im Prüfling** — dasselbe Verhältnis wie bei A10
(`docs/100`) und A2 (`docs/91`) und aus demselben Grund: Die Vorschrift war vor
dem Lauf ausgeschrieben, und die Messmittel lagen als geprüfte Werkzeuge im
Repo. Was blieb, war **neuer Code**.

**Vier der fünf Befunde am Prüfling hat der Betreiber beim Benutzen gemeldet**,
nicht eine Messung: die drei Bänder, die trotz Fenster oben standen; das Band
ohne Einrückung; die klebenden Knöpfe; die fehlende Bearbeiten-Funktion.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**

**Und zwei der fünf am Prüfmittel sind Wiederholungen** — Befund 3 und Befund 5
sind derselbe Satz an zwei Orten: eine Behebung, die nicht die Regel wurde. Beim
dritten Mal in diesem Projekt heisst das, dass es keinen Wächter fehlt, sondern
eine Gewohnheit: **Wer etwas an einer Datei behebt, sieht in derselben Stunde
nach, wo dieselbe Frage noch gestellt wird.**

---

## 12 · Was offen bleibt

**Benannt und kein Kriterienausfall:**

- **Die Bearbeiten-Funktion** (Befund 4). Vom Betreiber entschieden, noch nicht
  gebaut. Sie braucht `edit`/`update`, den Aussperrschutz gibt es hier nicht —
  wohl aber die Frage, ob eine Änderung an einer **laufenden** Ankündigung im
  Protokoll als solche erscheint.
- **Der Rest aus P7** — `orphan.row` zu `tls.cloudlab24.de`, unverändert seit
  `docs/100 §12`.
- **Das hochgeladene Wegwerfzertifikat** aus `docs/100 §6`, läuft am
  13. September von selbst aus.

**Was dieser Lauf ausdrücklich nicht geprüft hat**, steht in `docs/104 §9`.

**Der Prüfstand ist abgeräumt, und das ist belegt.** Die drei
Prüf-Ankündigungen gelöscht:

    srvpanel tinker --execute='echo \App\Models\Announcement::count();'
    0

Null ist die Zahl, die vor dem Lauf dastand. Die Anzeigezeitzone blieb
unverändert auf `Europe/Berlin` — sie stand schon vorher so, und Punkt 6
brauchte genau diese; es war also nichts umzustellen und nichts
zurückzustellen.

> **Ein Abräumen, das man nicht belegt, ist von einem, das nicht stattgefunden
> hat, nicht zu unterscheiden.**
