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

- **Ob `journalctl --lines=500` einen vergleichbaren Deckel hat.** Dieser
  Container hat kein Journal; gemessen ist nur der Dateiweg.
- **Was der Bytedeckel auf `cloudsrv24` wirklich trifft.** Die Schwelle ist
  gerechnet und im Container belegt; welche der sieben Quellen aus `Logs`
  darüber liegen, sagt erst ein Blick auf den Server.
- **Die Kosten von (c).** Wie teuer es ist, die Zeilen einer grossen Datei zu
  zählen, ist nicht gemessen — deshalb steht (c) nur dort, wo es ohne
  Zusatzarbeit belegbar ist.
- **Warum der Wunsch nie im Repo landete.** Die frühere Sitzung liegt nicht
  vor; festgehalten ist nur, dass er nirgends steht.
