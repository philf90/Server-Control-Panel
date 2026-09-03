# Protokoll des A10-Nachlaufs — die Bestandsdiagnose auf `cloudsrv24`

Der Lauf ist `docs/99`, ausgeschrieben am 2. September 2026 **vor** dem Fahren.
Dieses Dokument hält fest, was gemessen wurde, was dabei gefunden wurde und was
offen bleibt.

**Stand: Punkt 1 ist gefahren, die Punkte 2 bis 8 stehen aus.** Ein Protokoll
ohne seine Lücken liest sich wie eine Abnahme; deshalb steht das hier oben und
nicht am Ende.

Gefahren am **3. September 2026** auf `cloudsrv24`, in zwei Anläufen: der erste
gegen `0.7.3-rc.11`, der zweite gegen `0.7.3-rc.12`.

---

## 1 · Punkt 1 — ein heiler Server meldet nichts

### 1.1 Der erste Anlauf kam nicht bis zur ersten Messung

Gegen `0.7.3-rc.11`, nach 158 ms, `rc=1`:

    Target [App\Support\Diagnose\Wire] is not instantiable while building
    [App\Console\Commands\Diagnose, …, App\Support\Diagnose\Checks\Certificates]

Das ist **Befund 1** (§2.1). Behoben in `0.7.3-rc.12`.

### 1.2 Der zweite Anlauf ist durchgelaufen

Gegen `0.7.3-rc.12`: `rc=0`, **6 Prüfung(en) gefahren**, und die Seite
`/diagnose` nennt den Zeitpunkt — vorher stand dort „noch nie gelaufen".

**Die Gegenprobe zur Zeitangabe ist gefahren**, wie `docs/99 §1` sie verlangt:
Ein zweiter Lauf eine Minute später hat den angezeigten Zeitpunkt verschoben
(`09:27:36` → `09:29:42`). Dort steht also eine Messung und keine Vorlage.

> **Dieselbe leere Liste bedeutet „nichts gefunden" und „nie gemessen", und nur
> eine der beiden Bedeutungen ist eine Entwarnung.**

### 1.3 M19 ist beantwortet: der ganze Nachtlauf kostet 391 ms

`docs/98 §11` fragte, ob die Frist von 1800 Sekunden richtig gerechnet ist.
Gemessen über alle sechs Prüfungen: **391 ms**. Die Frist ist damit um drei
Grössenordnungen grosszügig — sie ist keine Schranke für den Regelfall, sondern
eine gegen einen Lauf, der hängt. Genau so war sie gemeint, und jetzt ist es
gemessen statt geschätzt.

### 1.4 Was der Lauf gemeldet hat

Vier Zeilen, und drei davon waren Befunde über das Panel und nicht über den
Server:

| Prüfung | Gegenstand | Grund | Was es war |
|---|---|---|---|
| `block.integrity` | `/etc/ssh/sshd_config` | `foreign_line` | Befund 2 |
| `block.integrity` | `/etc/ssh/sshd_config` | `line_missing` | Befund 2, dieselben Zeilen |
| `apt.key` | die Quelldatei des Panels | `unreachable` | Befund 3 |
| `orphan.row` | `tls.cloudlab24.de` | ohne Domain | **echt** (§3) |

**Ein `Kaputt` stand nicht dabei.** Nach dem Wortlaut von `docs/99 §1` ist der
Punkt damit erfüllt — und das ist genau die Art Erfüllung, die dieses Repo
misstrauisch macht: Drei der vier Zeilen waren Fehler des Prüflings, und keine
davon hat das Kriterium gesehen.

> **Ein Kriterium, das nach der Schwere fragt, prüft nicht, ob der Befund
> stimmt.**

---

## 2 · Die Befunde

### 2.1 Befund 1 — zwei von drei Nähten waren nie verdrahtet

Die Diagnose hat drei Nähte, weil sich die echten Klassen in keinem Test
ersetzen lassen: `LocalHost` fragt das Dateisystem, `TlsWire` öffnet eine
Verbindung, `Settings` ist `final`. Gebunden war genau **eine** — `RunLog` aus
Schritt 7, weil sie zuletzt entstand.

> **Eine Naht, die niemand verdrahtet, ist keine Naht — sie ist ein Loch, das
> erst der erste echte Lauf findet.**

**Kein einziger der 3011 Tests hat es gesehen**, und das ist die eigentliche
Lehre. Die Wächter der Prüfungen rufen `judge()` ohne Container,
`DiagnoseRunTest` baut `Run` aus handgemachten Attrappen, `DiagnosePageTest`
liest nur die Tabelle.

> **Ein Test, der seinen Gegenstand selbst zusammensetzt, prüft ihn — und nicht
> den Weg, auf dem er im Betrieb entsteht.**

`DiagnoseWiringTest` misst seitdem die Wirkung: Er baut jede Prüfung des
Katalogs über den Container, dazu `Run` und das Kommando — und fragt in der
Gegenrichtung, ob jede Schnittstelle eines Konstruktors auch **abgelegt** ist.

### 2.2 Befund 2 — der Vergleich stolperte über die Einrückung

`foreign_line` und `line_missing` nannten **dieselben sechzehn Zeilen**. Eine
Zeile fehlt und steht gleichzeitig zuviel da: Das kann kein Zustand einer Datei
sein.

`SshdConfig::block()` rückt den Rumpf eines `Match`-Bereichs um vier Leerzeichen
ein — acht Rumpfzeilen je Zugang, auf diesem Server zwei Zugänge.
`ManagedBlock::managed()` gibt die Zeilen getrimmt zurück. `compare()` verglich
die rohen Zeichenketten; durchgekommen ist genau die eine Zeile auf Spalte 0.

> **Zwei Leser derselben Zeilen, von denen einer die Einrückung wegwirft,
> vergleichen zwei Schreibweisen desselben Inhalts.**

Das ist M22 eine Ebene tiefer: Dort zählten zwei Leser die **Marken**
verschieden, hier formatieren zwei Schreiber dieselbe **Zeile** verschieden.

**Der Wächter daneben konnte es nicht sehen:**
`test_the_order_of_the_lines_is_not_a_finding` trägt seit Schritt 5b eine
eingerückte Zeile — auf **beiden** Seiten.

> **Ein Prüfkörper, der die Einrückung auf beide Seiten legt, kann den
> Unterschied nicht sehen, den nur eine Seite macht.**

PostgreSQL war nie betroffen, und das ist gemessen: `Hba::rule()` schreibt ohne
Einrückung, `pg_hba.conf` kennt keine Bereiche mit Rumpf.

### 2.3 Befund 3 — `gpg` legt sein Heimverzeichnis nicht an

    gpg: Fatal: /var/lib/srvpanel/gnupg: directory does not exist!
    rc=2

Diesen Ort legt niemand an. Im Kopf von `Keys::HOME` stand der Satz, der es
erklären sollte — „`gpg` legt sein Heimverzeichnis an, auch wenn es nur liest"
—, und der ist das Gegenteil dessen, was gemessen wird. Die Messung daneben
stimmte sogar; nur der Schluss war verkehrt herum.

> **Eine Messung und der Schluss daraus sind zwei Dinge — und aufgeschrieben
> wird der Schluss.**

**Die Folge war grösser als eine Prüfung.** `Keys::inspect()` gab auf jedem
Server `readable: false` zurück: Die Diagnose sah den Signaturschlüssel nie, und
die **Quellenseite** kannte zu keiner Quelle einen — sie ruft dieselbe Stelle.

**Und beim Nachmessen fiel die zweite Hälfte heraus.** In ein Heimverzeichnis,
das es gibt, schreibt `gpg --show-keys` seinen Hausrat: `pubring.kbx` und
`trustdb.gpg`, bei jedem ersten Lauf.

> **Ein Programm, das seinen Gegenstand nur liest, schreibt trotzdem — sein
> Arbeitsverzeichnis legt es beim ersten Mal an.**

Das trifft die erste Regel dieser Stufe. `DiagnoseWriteTest` hält jedes gerufene
Programm samt Schaltern — und auf dieser Ebene ist `--show-keys` eine lesende
Frage.

> **Ein Wächter über die Schalter eines Programms sieht nicht, was das Programm
> neben seinem Gegenstand anlegt.**

Zwei Schalter nehmen beides weg, einzeln gemessen gegen
`packaging/srvpanel-archive-keyring.gpg`:

| | Heimverzeichnis fehlt | Heimverzeichnis da |
|---|---|---|
| ohne die Schalter | `rc=2`, nichts gelesen | `rc=0`, legt zwei Dateien an |
| `--no-keyring --trust-model always` | `rc=0`, ein Schlüssel | `rc=0`, legt nichts an |

An der Auskunft ändern sie nichts, und auch das ist gemessen: Die Ausgabe ist
mit und ohne die beiden Zeile für Zeile identisch.

### 2.4 Befund 4 — die Nummer dieses Protokolls war nicht lesbar

Gefunden beim Anlegen **dieser Datei**, und zwar durch die Datei selbst.
`DocLinkTest` las Dokumentnummern mit einem Ausdruck über genau zwei Ziffern.
Gemessen mit einer dreistellig benannten Wegwerfdatei, die es gab: Der Wächter
meldete sie als Verweis auf ein Dokument mit der Nummer **10** — die ersten
zwei Ziffern ihres eigenen Namens —, und dieses Dokument gibt es seit dem
Repo-Übergang nicht.

Zwei Stellen waren nie eine Grenze, sondern eine Gewohnheit — es gab schlicht
noch kein dreistelliges Dokument. Der Wächter meldete also einen Verweis als
tot, der auf das Dokument zeigte, in dem er stand.

**Und die berichtigte Fassung hat diesen Absatz gleich noch einmal gefangen.**
In seiner ersten Form zitierte er die Meldung wörtlich — mitsamt der gekürzten
Nummer, und die liest der Wächter als Verweis. Dieselbe Familie wie
`OutcomeTest` am 1. September, nur in Prosa statt im Kommentar:

> **Ein Text, der eine Meldung über einen toten Verweis zitiert, enthält den
> toten Verweis.**

Ein Weg um diese Zwickmühle wäre, in Markdown die Codeblöcke zu überspringen —
dann sähe der Wächter die Verweise nicht mehr, die dort zu Recht stehen. Er
bleibt deshalb, wie er ist; wer eine solche Meldung festhalten will, schreibt
die Nummer ohne ihr `docs/`.

> **Ein Ausdruck, der die gewohnte Stellenzahl kennt, prüft die Gewohnheit und
> nicht die Regel.**

Das ist derselbe Satz, den dieses Repo schon über Schreibweisen hat
(`apt-get -q update`, `\App\Enums\AccountType::Admin`) — hier über die
Stellenzahl.

**Die Erweiterung allein war zu grob.** `{2,3}` ist gierig: Aus einem Text über
die Zahl 9999 — er steht seit dem 2. September im Changelog — wurde damit ein
Verweis auf ein Dokument 999, das niemand geschrieben hat.

> **Ein Ausdruck, der zu viel liest, meldet einen Verweis, den niemand
> geschrieben hat.**

Beide Leser tragen deshalb eine Ziffernabgrenzung; eine vierstellige Zahl ist
damit gar kein Verweis, und das ist die richtige Auskunft.

**Und „beide Leser" ist der eigentliche Befund.** Der Changelog-Eintrag über die
Erweiterung fiel eine Minute nach ihr an seiner eigenen Nummer durch:
`ChangelogTest` trägt einen zweiten Ausdruck über dieselbe Frage, mit derselben
Annahme. Erweitert war der erste.

> **Zwei Fassungen derselben Regel laufen auseinander, und die zweite ist die,
> die veraltet.**

`DocumentNumberReaderTest` hält das seitdem, und zwar **ohne die Familie
aufzuzählen**: Er sucht im Quelltext der Wächter die Ausdrücke, die eine
Dokumentnummer lesen, und misst jeden an denselben drei Fällen. Ein dritter
Leser kommt damit von selbst in die Messung.

> **Ein Wächter über eine Familie von Ausdrücken darf die Familie nicht
> aufzählen, sonst prüft er das Erinnerungsvermögen.**

**Und eine dritte Fassung lag daneben.**
`BreakScriptTest::test_every_placeholder_number_stays_free` verlangte eine
**zweistellige** Platzhalternummer und begründete das mit dem Wortlaut des
Ausdrucks in `DocLinkTest`. Als der dreistellig wurde, stimmte die Begründung
nicht mehr, während die Zeile weiter grün war — der stumme Halbteil der
bekannten Aufräumfalle. Gefragt wird deshalb jetzt der Ausdruck selbst, aus
`DocLinkTest` gelesen und nicht nachgebaut: Liest er die Platzhalternummer als
genau diese Nummer?

Belegt in beide Richtungen: Mit einem einstelligen Ausdruck meldet er, aus der
Platzhalternummer werde die Ziffer `0` gelesen; ohne jeden Ausdruck meldet er,
dass in `DocLinkTest` kein `preg_match_all` über Dokumentnummern mehr steht.
(Auch diese beiden Meldungen stehen hier ohne ihr `docs/` — aus demselben Grund
wie oben.)

---

## 3 · Was der Lauf über den **Server** gefunden hat

**Eine Zeile, und sie ist echt:** `orphan.row` / `tls.cloudlab24.de` / „ohne
Domain". Das ist ein Rest aus P7 — ein Zertifikat, dessen Domain zurückgebaut
wurde, das `CertificatePrune` seit dem 24. August genau so benennen soll. Die
Prüfung tut also, was sie soll.

Ob er abgeräumt wird (`srvpanel tls --prune`), entscheidet der Betreiber. Für
den Lauf ist er **nützlich**: Punkt 8 verlangt, dass derselbe Schaden über zwei
Läufe seinen `first_seen_at` behält, und ein Befund, der von allein
stehenbleibt, ist dafür der beste Prüfkörper, den dieser Server hergibt.

---

## 4 · Bilanz zu Punkt 1

**Vier Befunde, drei davon im Prüfling.** Das ist die Umkehrung von `docs/45`,
`docs/48`, `docs/59` und `docs/84`, wo die Mehrheit im Prüfmittel steckte — und
dieselbe Lage wie bei A2 (`docs/91`), aus demselben Grund: Die Vorschrift war
vor dem Lauf ausgeschrieben und dreimal berichtigt (`docs/99 §0`), das
Messmittel lag als geprüftes Werkzeug im Repo. Was blieb, war **neuer Code** —
und der hatte seine Fehler dort, wo kein Wächter hinsah.

Die drei haben eine gemeinsame Form, und sie ist nicht „Unachtsamkeit":

> **Drei Fehler an drei Nähten zwischen zwei Dateien** — Katalog und Container,
> Vorlage und Leser, Aufruf und Ablageort. Jeder für sich war in Ordnung; keiner
> der 3011 Wächter stand dazwischen.

**Keiner der drei hätte durch schärferes Hinsehen im Container auffallen
können**, und das ist der Grund, dass es Serverläufe gibt.

---

## 5 · Was offen ist

- **Die Punkte 2 bis 8 sind nicht gefahren.** Sie stehen in `docs/99`; Punkt 7
  (der angehaltene Agent) kommt zuletzt, weil er die übrigen Messungen blind
  machte.
- **Die drei Gegenproben zu den Befunden 2 und 3 stehen aus** und brauchen eine
  Fassung nach `0.7.3-rc.12` auf dem Server: `apt.key` nicht mehr „nicht
  gemessen"; `/etc/ssh/sshd_config` nicht mehr zweimal dieselben Zeilen; und
  **auf der Quellenseite ein Schlüssel mit Fingerabdruck, wo bisher keiner
  war** — die Stelle, die niemand als kaputt gemeldet hatte.
- **`orphan.row` / `tls.cloudlab24.de`** wartet auf die Entscheidung des
  Betreibers.
