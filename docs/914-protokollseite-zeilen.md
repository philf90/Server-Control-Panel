# 914 — Die Protokollseite weiss nicht, wie viel sie zeigt

> **Geschrieben am 13. September 2026, nach der Messrunde in §1 und nicht
> davor.** Es fasst zwei Dinge zusammen, die getrennt dastanden und dieselbe
> Naht haben: **Befund 14** aus `docs/86` (die Fusszeile von `/logs` nennt
> ein Fenster, das es nicht gibt, und bietet Zeilen an, die es nicht gibt) und
> den **Wunsch des Betreibers nach Zeilennummern**.

## §0 Warum beides ein Vorhaben ist — und warum der Wunsch fast verloren war

Beide brauchen dieselbe eine Ergänzung am Agenten: **was hat er tatsächlich
gelesen.** Ohne sie kann die Fusszeile ihre beiden Zahlen nicht bilden, und
eine Zeilennummer wäre eine Behauptung über eine Lage, die niemand kennt.
Getrennt gebaut, entstünden zwei Fassungen derselben Frage.

**Der Wunsch stand nirgends.** Gesucht am 13. September 2026 in `docs/`,
`CLAUDE.md`, `CHANGELOG.md`, der ganzen Commit-Historie und der Mitschrift der
laufenden Sitzung — kein Treffer ausser der Nachfrage selbst; frühere
Mitschriften liegen in diesem Container nicht. Gebaut ist er auch nicht:
`/logs` rendert **ein** `<pre>` mit `lines.join('\n')`.

Das ist die Spiegelung von A8 (`CLAUDE.md`, 7. September): Dort war ein Merkmal
gebaut und in keinem Plan vermerkt und deshalb von aussen nicht von einem zu
unterscheiden, das es nicht gibt. Hier ist eines angefragt und nirgends
vermerkt.

> **Was man zweimal braucht, gehört ins Repo — auch wenn es keine Zeile Code
> ist.** Der Satz gilt für Wünsche genauso wie für Abnahmeläufe. Ein Wunsch,
> der nur in einem Gespräch steht, ist fort, wenn das Gespräch fort ist.

## §1 Die Messrunde

Gemessen an einer nachgebauten Fassung von `WebLogsTail::tail()` mit denselben
Konstanten (`CHUNK = 8192`, `MAX_BYTES = 512 * 1024`, `MAX_LINES = 500`) — das
Original gibt seinen Abbruchgrund nicht heraus, und genau darum geht es.

| | Prüfkörper | gewünscht | geliefert | Abbruchgrund | Rest der Datei |
|---|---|---|---|---|---|
| M1 | 118 Zeilen à 80 B | 500 | **118** | Anfang erreicht | 0 B |
| M2 | 5000 Zeilen à 80 B | 500 | **500** | genug Zeilen | 364 040 B |
| M3 | 500 Zeilen à 4 KiB | 500 | **128** | **Bytedeckel** | 1 524 212 B |
| M4 | leere Datei | 500 | 0 | leer | 0 B |

**M5 — die Lage im Fenster gibt es und wird weggeworfen.** `array_filter`
erhält die Schlüssel, `array_values` in `SystemLogsTail::apply()` wirft sie
weg:

    array_filter   → {"0":"a1","2":"a3","4":"a5"}
    array_values   → ["a1","a3","a5"]

**M6 — die Schwelle des Bytedeckels, gemessen an 2000 Zeilen:**

| Zeilenbreite | geliefert | Abbruchgrund |
|---|---|---|
| 200 B · 512 B · 900 B · 1000 B · 1024 B | 500 | genug Zeilen |
| 1100 B | 477 | Bytedeckel |
| 1200 B | 437 | Bytedeckel |
| 2048 B | 256 | Bytedeckel |
| 4096 B | 128 | Bytedeckel |

Die Rechnung dahinter ist `512 KiB ÷ 500 = 1048 B`, und die Messung trifft sie:
Zwischen 1024 B und 1100 B kippt es.

## §2 Die Fusszeile lügt dreifach und nicht zweifach

`docs/86` nennt zwei Symptome mit **einer** Ursache: Die Seite weiss nicht, wie
viele Zeilen die Datei hat. **M3 ist eine zweite Ursache**, und sie stand in
keinem Dokument.

Ein Protokoll, dessen letzte 500 Zeilen im Schnitt über **1048 Bytes** lang
sind, wird vom Bytedeckel gekürzt — und nach aussen sieht das Zeichen für
Zeichen aus wie eine kurze Datei. `truncated` ist dabei `false`
(`count($matched) > $lines` trifft nicht zu), also meldet die Seite eine
vollständige Sicht auf einen Ausschnitt.

> **Zwei Gründe, die dasselbe Ergebnis erzeugen, sind nicht derselbe Grund —
> und die Abhilfe für den einen lässt den anderen stehen.**

Das ist kein Randfall: Ein nginx-`error.log` mit Stacktraces und ein
`upgrade.log` von apt liegen regelmässig über 1 KiB je Zeile. Der Fall trifft
also genau die Protokolle, die man liest, wenn etwas kaputt ist.

**Drei Aussagen der Seite sind damit unbelegt:**

1. „gelesen wurden die letzten 500 Zeilen" — `window` ist die Konstante
   `MAX_LINES` und keine Messung.
2. Der Knopf „Mehr Zeilen" steht unter `props.lines < 500` und fragt nicht, ob
   es mehr zu holen gibt.
3. Die Zahl links behauptet bei gesetztem Filter Treffer aus einem Fenster,
   dessen Grösse niemand kennt.

## §3 Was eine Zeilennummer hier überhaupt bedeuten kann

Drei Bedeutungen sind möglich, und sie sind nicht gleich viel wert.

**(a) `1..n` über das Angezeigte.** Billig und mit Filter eine Lüge: Die
angezeigten Zeilen sind dann nicht zusammenhängend, und „Zeile 7" ist nicht die
siebte Zeile von irgendetwas. Auch ohne Filter ist sie nur dann die Zeile der
Datei, wenn das Fenster bis zum Anfang gereicht hat (M1) — bei M2 und M3 nicht.

**(b) Die Lage im gelesenen Fenster, vom Ende gezählt.** Immer wahr, für jede
Quelle, ohne neue Messung: Sie folgt aus dem, was der Agent ohnehin in der Hand
hat (M5). Sie beantwortet „wie weit vom Ende" und nicht „welche Zeile der
Datei".

**(c) Die echte Zeilennummer der Datei.** Die einzige, nach der ein Betreiber
fragt — und die teuerste: Sie braucht die Gesamtzahl der Zeilen, und die kennt
ein Leser, der rückwärts liest, nur dann, wenn er bis zum Anfang gekommen ist.
Sie für jede Datei zu ermitteln hiesse, das ganze Protokoll zu lesen — also
genau das, wogegen `MAX_BYTES` steht.

**Für das Journal gibt es (c) gar nicht.** `journalctl` liefert Einträge und
keine Zeilen einer Datei; eine Zeilennummer wäre dort eine erfundene Grösse.

> **Eine Nummer, die auf zwei Quellen zwei verschiedene Dinge bedeutet, ist
> keine Nummer, sondern zwei.**

Der gangbare Weg ist deshalb **(c) wo sie belegbar ist, (b) sonst** — und die
Seite sagt, welche der beiden sie zeigt. Nicht (a): Sie kostet dasselbe wie (b)
und behauptet mehr.

## §4 Die Naht — was der Agent zusätzlich sendet

Drei Felder, alle aus dem, was der Leser ohnehin weiss:

| Feld | Bedeutung | woher |
|---|---|---|
| `read` | wie viele Zeilen das Fenster wirklich hatte | `count($all)` vor dem Kürzen |
| `complete` | ob das Fenster den **Anfang** der Quelle erreicht hat | `$position === 0` nach der Schleife |
| `capped` | ob der **Bytedeckel** die Schleife beendet hat | `strlen($text) >= MAX_BYTES` |

Dazu je Zeile ihre Lage im Fenster — der Schlüssel, den `array_values` heute
wegwirft (M5).

**`complete` und `capped` sind zwei Felder und nicht eines**, weil sie die
beiden Ursachen aus §2 trennen. Ein einzelnes `partial` wäre wieder die
Zusammenfassung, die die Unterscheidung einspart, die die Behebung braucht.

**Erst damit sind beide Aussagen der Fusszeile bildbar:** Ist `complete` wahr,
liegt das Ganze vor und der Knopf gehört fort; ist `capped` wahr, gibt es mehr,
und zwar nicht in Zeilen, sondern in Bytes gemessen — der Satz muss das sagen,
sonst verspricht der Knopf wieder etwas, was er nicht liefert.

## §5 Die Form auf der Seite

`.log` ist ein `<style scoped>` in `Logs/Index.vue` mit `white-space: pre`,
`overflow: auto` und `max-height: 60dvh` — **die bewusste Entscheidung, dass
ein Protokoll rollt und nicht umbricht.** Sie steht dort begründet und wird von
diesem Vorhaben nicht angefasst.

Daraus folgen zwei Auflagen, die kein Test halten kann und die gemessen werden
müssen:

1. **Die Nummern dürfen waagerecht nicht wegrollen.** In einem Rollbehälter
   heisst das `position: sticky; left: 0` an der Nummernspalte — sonst ist die
   Nummer bei einer langen Zeile ausserhalb des Sichtbaren, also genau dann
   fort, wenn man sie braucht.
2. **Die Nummern dürfen beim Kopieren nicht mitgehen.** Ein `<pre>`, in dem
   Nummern und Inhalt Text sind, liefert beim Markieren beides. `user-select:
   none` an der Spalte.

`docs/56` hat für den Dateieditor schon entschieden, dass Rollen hier richtig
ist und Umbrechen falsch — *„Ein Editor, der Quelltext umbräche, verschöbe die
Zeilennummern gegen den Inhalt"*. Derselbe Grund, dieselbe Antwort.

## §6 Entscheidungen für den Betreiber

1. **Welche Nummer?** (c) wo belegbar, (b) sonst — oder ausschliesslich (b),
   was einfacher und ehrlicher, aber weniger nützlich ist.
2. **Was tun, wenn der Bytedeckel greift?** Ihn erhöhen (kostet Speicher je
   Anfrage), ihn melden („gelesen wurden 128 Zeilen — weiter zurück hätte 512
   KiB überschritten"), oder beides.
3. **Soll der Knopf „Mehr Zeilen" bleiben?** Mit `complete` kann er
   verschwinden, sobald alles da ist. Bei `capped` müsste er etwas anderes
   anbieten als Zeilen.
4. **Nummern auch im Vorgangsprotokoll (`.output`)?** Dort steht dieselbe Form,
   aber ein Vorgang hat keine Datei und keine Lage.

## §7 Der Bau

1. `WebLogsTail::tail()` gibt `read`, `complete` und `capped` mit heraus —
   eine zweite Methode neben der bestehenden, damit `web.logs.tail` unberührt
   bleibt.
2. `SystemLogsTail::apply()` behält die Lage je Zeile.
3. `SystemLogsTail::execute()` sendet die drei Felder und die Lagen.
4. Der Controller reicht sie durch; `Logs/Index.vue` bildet Fusszeile und
   Knopf daraus.
5. Die Nummernspalte im `<pre>`, sticky und nicht markierbar.
6. Bilderrunde mit `tests/bilder-messen.js`, vier Lagen, dazu die waagerechte
   Rollprobe an einer langen Zeile.

## §8 Die Wächter

- **`LogWindowTest`** — `read`, `complete` und `capped` entstehen aus dem
  Leser und nicht aus einer Konstante; gemessen an den vier Prüfkörpern aus
  §1, **einschliesslich M3**, denn der ist der Fall, den die heutige Fassung
  nicht kennt. Beide Richtungen: Bei M1 ist `complete` wahr und `capped`
  falsch, bei M3 umgekehrt.
- **`LogFooterTest`** — die Fusszeile nennt keine Zahl, die nicht aus einem
  gesendeten Feld kommt, und der Knopf steht unter `capped || ! complete` und
  nicht unter `props.lines < 500`.
- **`LineNumberTest`** — die Nummernspalte trägt `position: sticky` und
  `user-select: none`; ohne beides ist sie bei einer langen Zeile fort
  beziehungsweise klebt am kopierten Text.

Jeder mit seinem Eingriff in `tests/waechter-brechen.sh`, **vor** dem
abschliessenden `exit "$fehler"`.

## §9 Abnahmekriterium

Auf einem echten Server, gegen echte Protokolle:

1. Eine Datei **kürzer** als das Fenster: Die Fusszeile nennt keine 500, und
   der Knopf „Mehr Zeilen" steht nicht da.
2. Eine Datei **länger** als das Fenster: Die Fusszeile nennt die gelesene Zahl,
   der Knopf steht da und liefert beim Drücken mehr.
3. Eine Datei mit **Zeilen über 1048 B** (M3 auf dem Server): Die Seite sagt,
   dass sie am Bytedeckel abgebrochen hat, und behauptet keine vollständige
   Sicht. **Darf nicht ausfallen** — das ist der Fall, den es ohne diese
   Messrunde nicht gäbe.
4. Mit gesetztem Filter: Die Nummern sind nicht fortlaufend, und die Lücken
   sind sichtbar.
5. Das **Journal** als Quelle: Es zeigt (b) und nicht (c), und die Seite sagt
   welche. **Darf nicht ausfallen.**
6. Bei 390 px: `dokument = 0`, Gegenprobe 200/200 — und die Nummer einer langen
   Zeile steht nach dem waagerechten Rollen **noch da**.
7. Markieren und Kopieren einer Zeile liefert die Zeile ohne ihre Nummer.
8. Beide Themen, beide Breiten.

## §10 Was dieses Vorhaben ausdrücklich nicht wird

- **Kein Suchen über die ganze Datei.** Der Filter bleibt ein Filter über das
  gelesene Fenster; alles andere wäre `grep` über beliebig grosse Dateien
  hinter einem Formular.
- **Kein Sprung zu einer Zeilennummer**, kein Verweis auf eine Zeile.
- **Kein Hervorheben**, keine Farben im Protokoll.
- **Kein Anfassen von `.log`** — dass ein Protokoll rollt und nicht umbricht,
  ist entschieden und begründet.
- **Kein zweiter Leser.** `WebLogsTail::tail()` bleibt die eine Stelle; sie
  bekommt mehr Auskunft, keine Schwester.

## §11 Was nicht gemessen ist

- ~~Ob `journalctl --lines=500` einen vergleichbaren Deckel hat.~~
  **Beantwortet beim Bauen, und ohne Server** — siehe §12, Fund 2. Der Deckel
  steht nicht in `journalctl`, sondern im Agenten.
- **Was der Bytedeckel auf `cloudsrv24` wirklich trifft.** Die Schwelle ist
  gerechnet und im Container belegt; welche der sieben Quellen aus `Logs`
  darüber liegen, sagt erst ein Blick auf den Server.
- **Die Kosten von (c).** Wie teuer es ist, die Zeilen einer grossen Datei zu
  zählen, ist nicht gemessen — deshalb steht (c) nur dort, wo es ohne
  Zusatzarbeit belegbar ist.
- **Warum der Wunsch nie im Repo landete.** Die frühere Sitzung liegt nicht
  vor; festgehalten ist nur, dass er nirgends steht.

---

## §12 Was beim Bauen anders war als im Plan

Gebaut am 13. September 2026. Zehn Stellen liefen anders — zwei davon sind
Funde, zwei sind Widersprüche im Plan selbst, und drei sind Fehler an meinen
eigenen Messmitteln.

### Der Plan widersprach sich, und zwar in einem Dokument

**§7 Schritt 1 verlangte „eine zweite Methode neben der bestehenden", §10
verbot „keinen zweiten Leser".** Beides über dieselbe Frage, drei Absätze
auseinander.

> **Zwei Zeilen desselben Dokuments über dieselbe Frage laufen auseinander, und
> keine von beiden ist der Ort, an dem man nachsieht.**

Gebaut ist §10: `WebLogsTail::tail()` bleibt die eine Stelle und gibt statt
`list<string>` eine Form mit `complete` und `capped` zurück. Beide Aufrufer
sind nachgezogen — es waren zwei.

### Fund 1 — der Knopf brauchte kein neues Feld

**Das Fenster ist immer `MAX_LINES` Zeilen gross; `lines` schneidet nur das
Ergebnis.** „Mehr Zeilen" liest also nichts nach — es schneidet weniger ab. Die
Bedingung, unter der der Knopf etwas bewirkt, ist damit genau `count($matched)
> $lines`, und das ist das Feld `truncated`, das es seit jeher gibt.

Der Fehler war nicht, dass die Auskunft fehlte, sondern dass der Knopf sie
nicht benutzt hat: Er stand unter `props.lines < 500`.

> **Ein Feld, das die Frage beantwortet, und ein Bedienelement, das eine andere
> stellt, sind von aussen dasselbe wie ein fehlendes Feld.**

Damit schrumpft §4: `read` folgt aus `count($found['lines'])` und braucht den
Leser nicht. Nur `complete` und `capped` kommen wirklich von dort.

### Fund 2 — der Journalweg hat denselben Deckel, und er steht woanders

§11 hat die Frage dem Server zugeschoben: „Dieser Container hat kein Journal."
Sie war ohne Journal zu beantworten. Der Deckel steht nicht in `journalctl`,
sondern in **`Runner::OUTPUT_MAX` = 4 MiB**; der Runner schneidet dort jede
Ausgabe ab und hält es in `Result::truncated` fest.

**Dieses Feld hat im ganzen Repo niemand gelesen** — ausgezählt über
`agent/src`, `app` und `tests`: geschrieben an einer Stelle, gelesen an keiner.

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt.** Zum dritten Mal in diesem Repo
> nach `context` (`docs/66`) und `subject_type` (`docs/94`).

Und es gab dabei eine zweite Falle: In derselben Antwort heisst `truncated`
schon etwas anderes — „es gibt mehr Treffer als gezeigt". Deshalb heisst das
neue Feld `capped` und nicht `truncated`.

> **Zwei Felder desselben Namens in einem Weg bedeuten zwei Dinge — und das
> zweite verdeckt das erste.**

### Fund 3 — `origin` wurde gesendet und von niemandem gelesen

Gefunden hat es `LogFooterTest` bei seinem **ersten Lauf**, also der Wächter und
nicht das Nachdenken. Die Seite nimmt Pfad beziehungsweise Unit aus
`system.logs.list`; `system.logs.tail` sandte denselben Wert ein zweites Mal,
und weder die Seite noch der Herunterladeweg las ihn.

Er steht **nicht** als Ausnahme im Wächter, sondern ist entfernt — eine
Ausnahmeliste hätte den Befund zugedeckt, für den es den Wächter gibt.

### Drei Fehler an den eigenen Messmitteln

**Der erste Bruchlauf hat nichts gemessen.** Er suchte `^OK` in PHPUnits
Ausgabe, und die trägt Farbcodes davor; alle drei Eingriffe meldeten nichts,
und das sah aus wie „beisst nicht". Gemessen wird seitdem der Rückgabewert.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Ein Eingriff hat nicht gebissen, und der Wächter war schuld.**
`LineNumberTest` suchte `white-space: pre` — und `pre-wrap` enthält das. Der
Eingriff, der den Umbruch zurückholte, blieb grün. Der Ausdruck trägt seitdem
das Semikolon.

> **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
> steht.**

**Ein Prüfkörper fehlte.** Eine Datei, die ganz in einen Block passt und
trotzdem mehr Zeilen hat als gewünscht, verlässt die Schleife über das `break`
— und ist vollständig gelesen. Wer `complete` am Ausstiegsgrund festmachte
statt an der Lage (`$position === 0`), nennte sie unvollständig. Der Fall steht
als M7 im Wächter.

### Zwei Dinge, die PHPStan gefunden hat

Ein früher Ausstieg in `fromFile()` gab die neuen Schlüssel nicht mit (eine
Datei, die es nicht gibt — dort ist `complete` wahr, denn nichts ist
ungelesen), und ein `array_values` um ein `array_keys` tat nichts.

### `<div>` und nicht `<pre>`

Vue erhält den Leerraum der Vorlage **innerhalb eines `<pre>`**. Eine Nummer
neben der Zeile braucht Elemente je Zeile; in einem `<pre>` stünde damit die
Einrückung dieser Datei im Protokoll. Die Form kommt ohnehin aus `.output`, der
Umbruch aus `.log-text` — und `.output > div` trifft die Zeilen nicht, weil sie
`<span>` sind.

### Was das für die Entscheidungen aus §6 heisst

Alle vier stehen, wie der Betreiber sie am 13. September entschieden hat:
die echte Nummer wo belegbar und sonst die Lage; der Bytedeckel wird gemeldet
und nicht erhöht; der Knopf bleibt (und hängt jetzt an der richtigen Frage);
keine Nummern im Vorgangsprotokoll.

### Was offen bleibt

**Das Abnahmekriterium aus §9 ist nicht gefahren.** Gemessen ist der Leser an
echten Dateien und die Naht im Quelltext; **nicht** gemessen sind die acht
Punkte auf einem Server, und **keine Aufnahme** existiert bisher. Die Punkte 3
und 5 dürfen dabei nicht ausfallen.

> **Ein Beleg für den Weg ist keiner für das Ziel.**

Und `web.logs.tail` sendet `complete` und `capped` jetzt mit, **ohne dass die
Domainseite sie zeigt** — dieselbe Fusszeile hat dort dasselbe Problem. Das ist
benannt und nicht gebaut; wer es anfasst, fängt hier an und nicht bei null.

---

## §13 Die Bilderrunde — zwei Befunde, die kein Test finden konnte

Gefahren am 13. September 2026 im Container, gegen die **echte Seite** mit
echten Daten: Agent auf einem eigenen Socket, `artisan serve`, Playwright mit
dem vorinstallierten Chromium, gemessen mit `tests/bilder-messen.js`.

### Der Prüfstand

Drei Dateien an den Pfaden, die `Logs` kennt — je eine für einen Zustand. Durch
die **echte Operation** gemessen, nicht durch den Leser allein:

| Quelle | `read` | `complete` | `capped` | stellt her |
|---|---|---|---|---|
| `agent` (118 Zeilen à 80 B) | 118 | **ja** | nein | echte Zeilennummern |
| `apt-history` (2000 Zeilen) | 500 | nein | nein | Nummern vom Ende |
| `panel` (500 Zeilen à 4 KiB) | **130** | nein | **ja** | Bytedeckel |

`panel` ist der Prüfkörper M3 auf dem echten Weg: eine Datei mit 500 Zeilen,
von der 130 ankommen.

### Befund 1 — `position: sticky` stand da und klebte nicht

Nach `scrollLeft = 3000` stand die Nummer bei **−1908 px**, also weit
ausserhalb des Sichtbaren. Die Angaben `position: sticky` und `left: 0` waren
die ganze Zeit richtig; ein klebendes Element kann nur seinen **eigenen
Kasten** nicht verlassen, und jede Zeile war nur so breit wie der Sichtbereich.

> **Ein Wächter, der die Angabe prüft, hat über die Wirkung nichts gesagt.**

`LineNumberTest` war grün — er fragte nach `position: sticky`, und die stand
da. Behoben mit einer Hülle `.log-body` (`width: max-content` plus
`min-width: 100%`); beide Hälften haben ihren Eingriff, denn ohne die zweite
endete der Streifen bei kurzem Inhalt vor dem rechten Rand.

**Nebenbei hat die Hülle die Messung aufgeräumt:** Vorher meldete `schiebt` bis
zu **100** Zeilen, jetzt **0** in allen zwölf Lagen.

### Befund 2 — `user-select: none` hält die Nummer nicht aus dem Kopierten

§5 hat es als Auflage hingeschrieben, und es stimmt nicht. Mit der Maus über
drei Zeilen gezogen, enthielt die Auswahl `⏎ 20 ⏎ … ⏎ 21 ⏎` — die Nummern.

> **Eine Regel, die das Auswählen verbietet, verbietet nicht das
> Ausgewähltwerden.** Sie hält die Einfügemarke ab; eine Auswahl, die über das
> Element **hinweggeht**, hält sie nicht ab.

Was trägt, ist **erzeugter Inhalt**: Die Nummer reist als `data-nummer` und
wird über `content: attr(data-nummer)` gezeichnet. Erzeugter Inhalt steht nicht
im Dokument und wird deshalb nicht kopiert. `user-select: none` bleibt daneben
stehen — es hält die Einfügemarke aus der Spalte.

### Zwei Fehler an der eigenen Messung, beide am selben Punkt

**Die erste Fassung von Punkt 7 mass mit einem programmatischen `Range`.** Ein
Range nimmt den Text eines `user-select: none` mit; ein Mensch zieht mit der
Maus, und gemessen werden soll, was er bekommt.

> **Ein Prüfkörper, der den Zustand auf einem anderen Weg herstellt als der
> Benutzer, stellt einen anderen Zustand her.**

**Die zweite Fassung hat nichts gemessen und sah aus wie ein bestandener
Punkt.** Der Schritt davor hatte `.log` auf 3000 gerollt; die Koordinaten
zeigten ins Leere, die Auswahl traf die Navigation, und das Ergebnis lautete
`ausgewählt: 'VERLAUF'` mit `enthaeltNummern: false`.

> **Eine Messung, bei der der Prüfling gar nicht getroffen wurde, sieht aus wie
> ein Ergebnis.** Die Messung druckt seitdem `hatGegenstand` mit — ob im
> ausgewählten Text überhaupt eine Protokollzeile steht.

### Die zwölf Lagen

Drei Quellen × zwei Themen × zwei Breiten, jede in einer frisch geladenen
Seite:

- `dokument = 0` — in allen zwölf
- Gegenprobe **200/200** — in allen zwölf
- `schiebt = 0` — in allen zwölf
- `klebt = 17->17` — die Nummer steht nach dem Rollen an derselben Stelle, bei
  Rollwegen bis **31 433 px**

**Eine Lage misst dabei weniger, als sie aussieht:** `apt-history` bei 1440 px
hat `rollbar = 0`, dort ist `klebt` trivial wahr. Die übrigen zehn haben
echten Rollweg (291 bis 31 433 px), und dort bedeutet die Zahl etwas.

### Ein Kriterium war unscharf formuliert

§9 Punkt 1 verlangte für eine Datei kürzer als das Fenster, „der Knopf steht
nicht da". Gemessen steht er sehr wohl — bei `lines=100` und 118 Zeilen gibt es
mehr zu zeigen, und das ist richtig. Mit `lines=200` verschwindet er, und der
Satz lautet „118 Zeilen · gelesen wurden die letzten 118 Zeilen".

> **Ein Kriterium, das zwei Zustände in einem Satz zusammenfasst, misst
> keinen von beiden.** „Das Fenster ist vollständig" und „alles ist gezeigt"
> sind zwei Dinge.

### Eine Beobachtung, nicht behoben

Ohne Filter und bei vollständig gezeigter Quelle sagt die Fusszeile dieselbe
Zahl zweimal: „118 Zeilen · gelesen wurden die letzten 118 Zeilen", und die
Notiz darunter sagt es ein drittes Mal in Worten. Gelogen ist nichts; ob es
zuviel ist, entscheidet der Blick auf dem Server und nicht dieser Container.

### Was weiterhin fehlt

**Die acht Punkte auf einem echten Server.** Dieser Container stellt die
Zustände mit selbstgeschriebenen Dateien her; ein Journal hat er nicht, also
ist Punkt 5 — die Nummern am Journal — hier **nicht** gemessen. Er darf nicht
ausfallen.
