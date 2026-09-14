# 917 — Die Reihenfolge der Protokollzeilen, und zwei Fragen an die Form

> **Geschrieben am 13. September 2026, nach dem Abnahmelauf der Protokollseite
> (`docs/915`) und vor dem Bau.** Der Anlass sind drei Fragen des Betreibers,
> gestellt in einem Satz, als die vier Befunde aus `docs/916` zu bauen waren:
>
> 1. Warum steht vor den Zeilennummern ein `−`?
> 2. Warum werden sie nicht normal durchnummeriert und stattdessen vom Text
>    abgesetzt — kursiv, farblich hinterlegt, andere Schrift?
> 3. Warum lässt sich die Sortierung nicht umdrehen, sodass die neuesten
>    Einträge oben stehen?

**Zwei der drei Fragen haben eine Antwort im Bestand, die dritte hatte keine.**
Das ist der Grund, warum es dieses Dokument gibt und nicht nur einen Absatz in
`docs/916`: Die Umkehrung ist ein Merkmal und kein Befund.

---

## §1 Zur ersten Frage — das Zeichen war eine Antwort auf eine echte Frage

**Die Nummer bedeutet nicht immer dasselbe, und das ist gemessen.**
`docs/914 §3` hat das entschieden: `WebLogsTail` liest eine Datei **von hinten**
und hört an einer von drei Stellen auf. Nur bei einer davon — der Anfang der
Datei ist erreicht (`complete`) — ist die Lage einer Zeile in der Anzeige auch
ihre Lage in der Datei. In den beiden anderen Fällen (genug Zeilen, Bytedeckel)
weiss niemand, die wievielte Zeile der Datei man vor sich hat; sie zu zählen
hiesse, die Datei ganz zu lesen, und genau das tut der Leser aus gutem Grund
nicht.

> **Eine Nummer, die manchmal die Zeile der Datei meint und manchmal die Lage
> im Ausschnitt, ist keine Nummer, sondern zwei — und wer sie gleich aussehen
> lässt, behauptet etwas, das er nicht weiss.**

Das Zeichen davor ist also nicht Zierrat, sondern die einzige Stelle, an der
die Anzeige sagt, welche der beiden Grössen dasteht. Was falsch war, ist die
**Wahl** des Zeichens: `−1` liest sich wie „minus eins" und damit wie eine
Rechnung, und ein Betrachter fragt zu Recht, wovon abgezogen wird.

**Entschieden: `↑17` statt `−17`.** Der Pfeil sagt, was gemeint ist — siebzehn
Zeilen nach oben vom Ende her. Er ist kein Rechenzeichen, verlangt keine
Erklärung und steht in derselben Spalte wie eine echte Nummer.

**Die Länge ändert sich dadurch nicht:** `↑500` ist wie `−500` vier Zeichen,
und `MAX_LINES` ist 500. Die `min-width: 4ch` der Spalte bleibt.

---

## §2 Zur zweiten Frage — durchnummerieren geht nicht, absetzen schon

**Die zweite Frage besteht aus zwei Wünschen, und sie haben verschiedene
Antworten.**

**„Normal durchnummerieren" geht nicht** — aus dem Grund in §1. Eine
fortlaufende `1, 2, 3` würde behaupten, die erste angezeigte Zeile sei die
erste der Datei, und das ist sie in zwei von drei Fällen nicht. Die Zahl wäre
in jedem Ausschnitt eine andere für dieselbe Zeile, und beim Umdrehen der
Reihenfolge (§3) wäre sie es ein zweites Mal. Wo die Datei **vollständig**
gelesen ist, wird längst durchnummeriert: `props.result.complete` schaltet auf
`offset + 1`, ohne Zeichen davor.

> **Zwei Zustände, die man gleich aussehen lässt, unterscheidet der Betrachter
> nicht — und der Fehler fällt ihm als seiner zur Last.**

**„Vom Text absetzen" ist berechtigt und war nicht gebaut.** Die Spalte trug
`--surface`, also dieselbe Fläche wie der Rahmen daneben; sie war allein durch
die Textfarbe (`--text-muted`) unterschieden. Das reicht nicht: Ein Rinnstein,
der aussieht wie sein Nachbar, ist keiner.

**Entschieden: Tönung, und kein Kursiv und keine zweite Schrift.**

- **Kursiv** in einer Monospace-Schrift verschiebt die Zeichenbreite nicht,
  aber die Grundlinie liest sich unruhig, und an einer Zahl trägt Kursiv keine
  Bedeutung.
- **Eine andere Schrift** wäre eine zweite Familie für ein Zeichen und die
  Spalte liefe aus der Rasterbreite; `docs/20 §7.2` erlaubt Monospace für
  Kennungen und für nichts sonst.
- **Eine Fläche** ist die Form, die dieses Gestaltungssystem für „eigener
  Bereich" schon führt, und sie kostet keine Zeile Sonderfall.

**Die Marke heisst `--gutter-bg` und steht in beiden Themen** — hell `#eceef2`,
dunkel `#1e222b`. Gerechnet und nicht geschätzt: **5,15:1** gegen
`--text-muted` im hellen und **6,06:1** im dunklen Thema, also über den 4,5:1
für Text; **1,11:1** beziehungsweise **1,13:1** gegen `--surface` daneben, also
sichtbar und nicht laut. Eine Fläche, die keine Bedienelementgrenze ist,
braucht die 3:1 aus WCAG 1.4.11 nicht.

> **Eine Farbe, die man schätzt, ist auf einem der beiden Themen falsch — die
> Frage ist nur, auf welchem.**

**Und das Polster wandert mit.** Eine getönte Spalte, die am Rahmenpolster
endet, lässt links von sich einen Streifen frei, durch den beim Rollen der Text
sichtbar wird — das ist Befund 3 aus `docs/916 §7`, und die Tönung macht ihn
erst sichtbar. Deshalb hat `.log` kein `padding-inline` mehr; die sechzehn
Pixel liegen an der Nummer (links) und am Text (rechts).

---

## §3 Zur dritten Frage — es gab keinen Grund, nur keine Zeile

**Die Umkehrung fehlte, weil sie nie zur Sprache kam.** `docs/914 §10` zählt
auf, was das Vorhaben ausdrücklich nicht wird — die Reihenfolge steht dort
nicht.

> **Eine Aufzählung dessen, was ein Merkmal nicht wird, ist nur dann eine
> Entscheidung, wenn das Fehlende darin steht — sonst ist sie eine Lücke mit
> Überschrift.** Derselbe Satz wie bei A14 (`docs/105`), wo das Bearbeiten
> einer Ankündigung auf demselben Weg fehlte.

**Der Wunsch ist berechtigt und der Bestand gibt ihm recht.** Die Seite zeigt
das **Ende** einer Datei; wer sie öffnet, will wissen, was gerade passiert ist.
Das steht unten, und bei 500 Zeilen ist das ein Rollweg. Die Fusszeile, die
sagt, wie viel gelesen wurde, steht ebenfalls unten — man rollt also ohnehin
dorthin, und genau deshalb fiel es nicht auf.

### §3.1 Wo umgedreht wird, und warum nicht im Agenten

**Auf der Seite.** Der Agent liest eine Datei von hinten und liefert sie in
**Dateireihenfolge**; das ist seine Auskunft und keine Darstellung. Eine zweite
Reihenfolge durch den Socket wäre eine zweite Fassung derselben Frage, und die
zweite ist die, die veraltet. Die Zeilen liegen ohnehin schon vollständig auf
der Seite — es sind höchstens 500.

> **Was eine Anzeige entscheidet, entscheidet nicht die Quelle.**

### §3.2 Die Nummer bleibt an ihrer Zeile

**Sie wandert mit und wird nicht neu vergeben.** Eine Nummer ist eine Lage in
der Quelle und kein Index der Anzeige; würde sie neu vergeben, hiesse dieselbe
Zeile in zwei Ansichten verschieden, und die Auskunft wäre wertlos. Umgedreht
steht `↑1` oben statt unten — und das ist genau, was der Wunsch will.

### §3.3 Kein Wahrheitswert in der Adresse

**Der Zustand reist in der Adresszeile**, wie Quelle und Filter, damit ein
Blick sich weitergeben lässt und das Herunterladen dieselben Werte bekommt.
Dort ist alles Text: Aus `false` würde das Wort `"false"`, und Laravels Regel
`boolean` nimmt kein Wort (`docs/66`). Die Reihenfolge ist deshalb **ein Wort
mit zwei erlaubten Werten** und kein Kästchen.

    oldest   die Reihenfolge der Datei — wie tail, wie less   (Vorgabe)
    newest   die neueste Zeile zuerst

Geprüft wird gegen die Liste im Controller und nicht gegen ein Muster; ein
unbekannter Wert fällt auf die Vorgabe zurück, statt die Seite mit einer
Meldung aufzuhalten. Eine Ansicht ist keine Eingabe, die man berichtigen muss.

**Und das Feld heisst „Sortierung" und nicht „Reihenfolge" — entschieden hat
das ein Wächter.** Der erste Wurf trug „Reihenfolge"; `AttributeLabelTest` hat
beim ersten vollen Lauf gemeldet, dass der Server denselben Schlüssel `order`
in `lang/de/validation.php` „Sortierung" nennt. Der Name gehört der
Datenbankkonsole, die über `order` ihre Zeilen sortiert — dort **wird** geprüft,
und dort erscheint der Name in einer Meldung. Zwei Wörter für einen Schlüssel
hiessen: Ein Kunde liest einen Feldnamen, den seine Seite nicht führt.

> **Ein Feld, das zwei Seiten teilen, teilen sie auch im Wortlaut — oder eine
> von beiden nennt es anders, als die Meldung es tut.**

### §3.4 Der Knopf „Angezeigtes sichern" folgt der Anzeige

Ohne diese Zeile hiesse er das und täte etwas anderes, sobald jemand umdreht —
und der Unterschied fiele erst auf, wenn man beide Dateien nebeneinanderlegt.

> **Ein Knopf, der sagt, was er sichert, muss das Gesicherte danach richten und
> nicht umgekehrt.**

---

## §4 Was gebaut ist

| Ort | Änderung |
|---|---|
| `resources/css/app.css` | `--gutter-bg` in beiden Themen, mit den gerechneten Werten im Kommentar |
| `resources/js/Pages/Logs/Index.vue` | `↑` statt `−`; `.log-number` auf `--gutter-bg`; `padding-inline: 0` an `.log`, Polster an die Kinder; Eigenschaft `order`; `computed zeilen`; drittes Feld „Reihenfolge" |
| `app/Http/Controllers/LogsController.php` | `ORDERS`, Prüfung gegen die Liste, `order` in der Ablage, `array_reverse` in `download()` |
| `agent/src/Ops/WebLogsTail.php` | Befund 2 aus `docs/916 §3`: beim Bytedeckel fällt die angebrochene erste Zeile weg |

**Der Agent bekommt für die Reihenfolge keine Zeile.** Das ist der Beleg für
§3.1 und nicht nur seine Behauptung.

---

## §5 Die Wächter

- **`LogOrderTest`** — die Naht: Die `value` der beiden `<option>` sind genau
  die Werte aus `LogsController::ORDERS`; im Filterbereich steht kein
  `type="checkbox"`; `download()` dreht um. Was er nicht kann: ob die Zeilen
  wirklich umgedreht ankommen — das ist eine Frage an eine gerenderte Seite und
  steht als Punkt in §6.
- **`LineNumberTest`** — erweitert um die Tönung (`background: var(--gutter-bg)`
  **und** `padding-inline: 0` **und** `padding-left` an der Nummer in **einem**
  Fall, weil eine Tönung ohne das gewanderte Polster Befund 3 sichtbar macht
  statt ihn zu beheben), um den Pfeil statt des Minus, und darum, dass die
  Nummer mit ihrer Zeile reist.
- **`LogWindowTest`** — erweitert um den Bytedeckel: keine halbe Zeile, und
  ohne Deckel fällt nichts weg. Beide Richtungen in einem Fall.

---

## §6 Abnahme — sechs Punkte

Gefahren auf `cloudsrv24` gegen die nächste Fassung. **Punkt 2 und Punkt 4
dürfen nicht ausfallen.**

1. **Das Zeichen.** Eine Quelle wählen, deren Fenster **nicht** bis zum Anfang
   reicht — abzulesen an der Fusszeile: dort steht „Die Nummern zählen vom
   Ende". Erwartet: `↑` vor jeder Nummer und kein `−` auf der Seite.
   Gegenprobe: eine kurze Quelle sagt „Das ist die ganze Quelle" und zeigt
   Nummern **ohne** Zeichen.

   *Beim Ausschreiben berichtigt:* Der erste Wurf liess zwei Sätze zu — den
   obigen **oder** „Weiter zurück wurde nicht gelesen". Die beiden sind aber
   nicht zwei Ausgänge, sondern zwei Anzeichen: Der erste ist `complete =
   false` und trägt den Punkt, der zweite ist `capped` und kommt manchmal
   dazu. Genannt wird deshalb der eine, der den Zustand wirklich bedeutet.

   > **Ein Kriterium, das zwei Sätze zulässt, misst den, der gerade dasteht —
   > und nicht den, der den Zustand bedeutet.**

2. **Die Umkehrung.** *(Ausschlusskriterium)* „Neueste zuerst" wählen.
   Erwartet: Die Zeile, die vorher unten stand, steht oben; **ihre Nummer ist
   dieselbe wie vorher**. Abgelesen wird das Paar aus erster und letzter Zeile
   in beiden Reihenfolgen — vier Werte, und die beiden Nummern tauschen die
   Plätze, statt sich zu ändern.

3. **Die Adresse trägt sie.** Nach dem Umschalten steht `order=newest` in der
   Adresszeile; ein Neuladen zeigt dieselbe Reihenfolge. Gegenprobe:
   `?order=quatsch` von Hand — die Seite zeigt „Älteste zuerst" und keine
   Fehlermeldung.

4. **Der Knopf sichert das Angezeigte.** *(Ausschlusskriterium)* Gefahren wird
   er an einer Quelle, die **während der Messung nicht wächst** — also nicht an
   `agent`, sondern zum Beispiel an `panel-update`.

   **Warum das tragend ist.** Die Sicherung ist eine **neue** Anfrage: Sie liest
   die Datei ein zweites Mal. Bei `agent` schreibt das Panel dabei selbst
   hinein — jeder Seitenaufruf und jede Sicherung hinterlassen dort ihre
   `system.logs.tail`-Zeile. Das Fenster ist also ein anderes als das auf der
   Seite, und zwar um genau die Zeilen, die das Messen erzeugt hat.

   > **Ein Protokoll, das der Prüfling selbst beschreibt, verändert sich durch
   > das Messen — und die gesicherte Datei kann der Anzeige dann nie Zeile für
   > Zeile gleichen.**

   **a) Der Knopf selbst.** Bei „Neueste zuerst" einmal auf „Angezeigtes
   sichern". Erwartet: Die erste Zeile der Datei ist die, die auf der Seite oben
   stand.

   **b) Der Vergleich beider Reihenfolgen**, in der Konsole und in einem Zug:
   Die erste Zeile von `oldest` ist die letzte von `newest` und umgekehrt. Zwei
   Aufrufe nacheinander sind dafür eng genug — an einer wachsenden Quelle wären
   sie es nicht, und der Punkt meldete einen Befund am Prüfling, der keiner ist.

   Verglichen wird die **erste Zeile beider Antworten**, nicht ihre Länge.

   *Beim Ausschreiben stand hier zuerst ein anderer Grund für die Konsole: zwei
   Sicherungen derselben Quelle trügen denselben Dateinamen. Das ist falsch —
   `filename()` hängt `Ymd-His` an, zwei Sicherungen kollidieren also nur
   innerhalb derselben Sekunde. Gelesen war der Aufruf, nicht die Methode.*

   > **Ein Wert, den nur der Aufruf nennt, ist eine Vermutung, bis jemand die
   > Methode dahinter liest.**

5. **Die Tönung, gemessen und nicht angesehen.** In beiden Themen bei 1440 px
   und 390 px: `getComputedStyle` der Nummernspalte und des Rahmens daneben
   geben **verschiedene** `background-color`. Eine Aufnahme daneben, weil eine
   Zahl über zwei Farben nicht sagt, ob man sie auseinanderhält.

6. **Die Klebeprobe, mit `deckt=true`.** `tests/kleben-messen.js` bei 1440 px
   im dunklen Thema. Erwartet: `klebt=true`, `misst=true` und **`deckt=true`** —
   das ist die Behebung von Befund 3, und `deckt=false` wäre ihr Ausbleiben.
   Ohne `misst=true` bedeutet keiner der beiden Werte etwas.

---

## §7 Was das ausdrücklich nicht wird

- **Keine dritte Reihenfolge.** Kein Sortieren nach Inhalt, kein Gruppieren.
- **Kein Springen zu einer Nummer.** Die Nummer ist eine Auskunft und kein
  Bedienelement.
- **Kein Merken der Wahl über die Sitzung hinaus.** Sie steht in der Adresse;
  wer sie behalten will, setzt ein Lesezeichen.
- **Keine Umkehrung im Agenten.** Siehe §3.1.
- **Keine Zeilennummern im kopierten Text.** Das ist gemessen und soll so
  bleiben (`docs/916 §14`).

---

## §8 Was ungemessen bleibt

- **Ob 500 Zeilen umzudrehen auf einem Telefon spürbar ist.** Gerechnet ist es
  eine Liste von höchstens 500 Einträgen in einem `computed`; gemessen ist es
  nicht.
- **Ob jemand die Tönung im hellen Thema auf einem schlechten Bildschirm
  sieht.** 1,11:1 ist gerechnet, und die Zahl sagt über den Bildschirm nichts.
