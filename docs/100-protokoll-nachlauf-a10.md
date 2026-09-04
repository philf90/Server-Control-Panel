# Protokoll des A10-Nachlaufs — die Bestandsdiagnose auf `cloudsrv24`

Der Lauf ist `docs/99`, ausgeschrieben am 2. September 2026 **vor** dem Fahren.
Dieses Dokument hält fest, was gemessen wurde, was dabei gefunden wurde und was
offen bleibt.

**Stand: alle acht Punkte sind gefahren und erfüllt**, beide
Ausschlusskriterien (5 und 8) darunter. Die Bilanz steht in §11, was offen
bleibt in §12.

Gefahren am **3. September 2026** auf `cloudsrv24`, gegen drei Fassungen:
Punkt 1 gegen `0.7.3-rc.11` und `0.7.3-rc.12`, die Punkte 2 bis 4 gegen
`0.7.3-rc.13`, die Punkte 5 bis 8 gegen `0.7.3-rc.14`. Die Reihenfolge war
1 · 2 · 3 · 4 · 5 · 8 · 6 · 7 — Punkt 8 braucht den Schaden aus Punkt 5, und
Punkt 7 steht zuletzt, weil er jede andere Messung blind macht.

---

## 1 · Punkt 1 — ein heiler Server meldet nichts

### 1.1 Der erste Anlauf kam nicht bis zur ersten Messung

Gegen `0.7.3-rc.11`, nach 158 ms, `rc=1`:

    Target [App\Support\Diagnose\Wire] is not instantiable while building
    [App\Console\Commands\Diagnose, …, App\Support\Diagnose\Checks\Certificates]

Das ist **Befund 1** (§9.1). Behoben in `0.7.3-rc.12`.

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
| `orphan.row` | `tls.cloudlab24.de` | ohne Domain | **echt** (§10) |

**Ein `Kaputt` stand nicht dabei.** Nach dem Wortlaut von `docs/99 §1` ist der
Punkt damit erfüllt — und das ist genau die Art Erfüllung, die dieses Repo
misstrauisch macht: Drei der vier Zeilen waren Fehler des Prüflings, und keine
davon hat das Kriterium gesehen.

> **Ein Kriterium, das nach der Schwere fragt, prüft nicht, ob der Befund
> stimmt.**

---

## 2 · Punkt 2 — der entfernte verwaltete Bereich

**Der erste Anlauf hat die Hälfte gemessen, und das lag an der Vorschrift.**

    grep -c 'srvpanel' → 0        ← Zustand belegt
    srvpanel diagnose → Kaputt: 1
    zurückgelegt, sshd -t rc=0, reload
    srvpanel diagnose → Kaputt fort

Der Zustand war belegt, der Befund kam, der Rückweg trug — aber `docs/99 §2`
verlangt `reason=block_missing` und den Wortlaut, und dastand die Zahl `1`.
`Kaputt: 1` wäre auch ein `begin_without_end` oder ein ganz anderer Schaden
gewesen. Der tinker-Auszug stand in meiner Anleitung **hinter** dem Rückbau
statt zwischen den beiden Schritten; nachträglich ist die Zeile nicht mehr zu
lesen.

> **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt
> wurde.**

Das ist wörtlich die Lehre aus P4, und sie ist hier nicht am Prüfling
aufgetreten, sondern an der Anleitung, die ihn misst.

**Der zweite Anlauf ist vollständig:**

    grep -c 'srvpanel' → 0                        ← Zustand belegt
    block.integrity | /etc/ssh/sshd_config | block_missing
        Im Bestand stehen:  Match User p1136 … Match User p1139 … Match all
    sshd -t → rc=0, reload, diagnose → Zeile fort

Prüfung, Gegenstand und Grund stimmen, und der Wortlaut nennt **alle neunzehn**
Zeilen, die im Bestand stehen und in der Datei fehlten — zwei `Match`-Blöcke à
neun plus das abschliessende `Match all`. Daran hängt der Punkt: nicht
„irgendetwas fehlt", sondern *was*.

**Und das ist zugleich die Gegenprobe zu Befund 2 von der anderen Seite.** Am
Morgen nannte derselbe Gegenstand dieselben Zeilen zweimal, in beiden
Richtungen; jetzt nennt er sie einmal, in der richtigen — und nach dem Rückbau
gar nicht mehr.

**Eine Beobachtung, kein Befund:** Der Wortlaut zeigt die Zeilen **getrimmt**,
weil `compare()` seit der Behebung von Befund 2 beide Seiten normalisiert. Für
den sshd ist das gleichwertig — ein `Match`-Block wird vom nächsten `Match`
begrenzt und nicht von der Einrückung. Es fällt nur auf, wenn jemand die Zeilen
von dort abschreibt.

---

## 3 · Punkt 3 — der gestoppte Timer

    NextElapseUSecRealtime=          NextElapseUSecMonotonic=infinity
    ActiveState=inactive                                       ← Zustand belegt

    unit.schedule | srvpanel-cron.timer | no_next
        ActiveState=inactive SubState=dead

    nach systemctl start → die Zeile ist fort

**Der Befund kam sofort und nicht erst nach dem verpassten Termin.** Das ist
der Unterschied zwischen einer Diagnose und einem Nachsehen im Protokoll — und
genau der Zustand, den dieses Projekt am 19. August 2026 zweiundzwanzig Stunden
lang unbemerkt hatte.

**Die zweite Hälfte hält:** `srvpanel-cron.service` steht **nicht** dabei. Die
Zuordnung aus `Triggers` am Timer hat überlebt, dass der Timer gestoppt war —
ein Dienst, den ein Timer startet, steht zwischen zwei Terminen still, und das
ist kein Schaden.

**Zwei Dinge im Auszug sehen aus wie Befunde und sind keine.**

Die Marke sagte noch „Sieht jemand hin". Der Server lief `0.7.3-rc.13`; die
neue Marke war committet und noch nicht ausgeliefert (Befund 6).

Und `ActiveState=inactive SubState=dead` steht englisch da — **das ist
Absicht.** `docs/98 §2` legt `detail` als „der Wortlaut des Werkzeugs,
ungekürzt" fest: Die Deutung steht im `reason` (`no_next`), das Zitat im
Detail. Der Fehler aus `docs/91` war eine **Spalte**, die einen Rohwert
**statt** einer Deutung zeigte.

---

## 4 · Punkt 4 — `BEGIN` ohne `END`

Der Zustand, den `managed()` bis Schritt 2 gar nicht sehen konnte, und der
Anlass für M22.

    tail -n 20 → der Bereich endet mit "# END srvpanel" am Dateiende
    grep -c BEGIN → 1        grep -c END → 0     ← beide Zahlen belegt

    block.integrity | /etc/ssh/sshd_config | begin_without_end
        BEGIN in Zeile 134, END in Zeile –.

    sshd -t → rc=0, reload, diagnose → Zeile fort

Prüfung, Gegenstand, Grund und der Wortlaut mit beiden Zeilennummern, `END` als
`–`. **Und genau ein Befund** — kein zweiter mit `foreign_line`, und das ist
hier die richtige Antwort: Das `tail` hat vorher gezeigt, dass der Bereich die
Datei abschliesst, also liest `managed()` ohne `END` bis zum Dateiende und
findet dahinter nichts Fremdes. Stünde danach noch etwas, käme der zweite
Befund, und er wäre richtig.

**Das `tail` steht in der Vorschrift bewusst vor dem Eingriff** — ohne es wäre
„genau ein Befund" eine Beobachtung ohne Bedeutung.

---

## 5 · Punkt 5 — das fehlende Semikolon *(Ausschlusskriterium)*

**Der Punkt, der die ganze Stufe trägt.** Fällt er aus, ist A10 ein Aufruf von
`nginx -t` mit einer Seite davor.

    24:  index index.php index.html index.htm     ← Semikolon fort
    26-  client_max_body_size 64m;                ← verschluckt

    nginx: configuration file /etc/nginx/nginx.conf test is successful
    rc=0                                     ← der Prüfer hält sie für gültig

    Auffällig: 1        Kaputt: 1
    web.file | p6-b.invalid | directive_lost
        client_max_body_size fehlt als Anweisung

`nginx -t` sagt `rc=0`, und die Diagnose findet den Schaden trotzdem. Das ist
der Unterschied, für den es A10 gibt.

**Und der Punkt ist im ersten Anlauf gefahren worden, weil die Zusage einen Tag
vorher umgebaut worden war** (Befund 5). Gegen `0.7.3-rc.13` hätte hier nichts
gestanden — dieselbe Datei, dieselbe Zeile, und die Zusage war neun Anweisungen
gross statt zwanzig.

Die Marke sagt **„Auffällig"** (Befund 6). Die Datei bleibt danach kaputt;
Punkt 8 baut sie zurück.

---

## 6 · Punkt 6 — das Zertifikat in drei Zuständen

Die Prüfung sieht nur Domains, die ein Zertifikat **zugeordnet** haben — eine
ohne wird übersprungen. Der Zustand wurde deshalb über das Formular „Eigenes
Zertifikat" hergestellt und nicht an einer lebenden Domain.

**Die Prüfkörper sind EC und nicht RSA**, weil das Formular zwei Textfelder hat
und keinen Dateiupload: 619 Byte Zertifikat, 241 Byte Schlüssel — von Hand
kopierbar. Der Ablageort heisst `_uploaded.p6-b.invalid`.

| Prüfkörper | Grund | Zustand | Wortlaut |
|---|---|---|---|
| a — zehn Tage | `expiring` | Auffällig | „gültig bis 2026-09-13 19:10 UTC" |
| b — seit gestern abgelaufen | `expired` | **Kaputt** | „gültig bis 2026-09-02 19:11 UTC" |
| c — anderer Name | `name_mismatch` | **Kaputt** | „p6-b.invalid steht nicht im Zertifikat (andere.invalid)" |

**Eine Vorhersage von mir ist nicht eingetroffen, und das Verhalten ist
richtig.** Ich hatte zu (a) einen `tls.wire`-Befund erwartet — auf der Leitung
lag noch das alte Zertifikat. Er kam nicht: Findet die Datei einen Befund, wird
die Leitung gar nicht erst gefragt (`continue` vor dem Fingerabdruckvergleich).
`CertificateVerdictTest` hält genau das. **Kein Befund ist hier die richtige
Antwort und nicht eine fehlende** — hätte ich die Vorhersage stehenlassen, wäre
aus einem richtigen Verhalten ein Befund geworden.

**Und die Läufe (b) und (c) haben etwas mitgeliefert, das nicht bestellt war:**

    web.config | /etc/nginx/nginx.conf | invalid
        [emerg] SSL_CTX_use_PrivateKey(".../privkey.pem") failed
                (x509 certificate routines::key values mismatch)

Getauscht wurde nur `fullchain.pem`, nicht `privkey.pem` — Zertifikat und
Schlüssel passen also nicht mehr zusammen, und nginx verweigert. Das ist der
Zustand, den ein unachtsamer Zertifikatstausch im Ernstfall erzeugt, und die
beiden Prüfungen haben unabhängig voneinander gemeldet, was sie sehen: die
Datei ihr Urteil, der Prüfer seines.

Nach dem Rückbau von `fullchain.pem` gab `nginx -t` wieder `rc=0`, `web.config`
war fort, und `tls.file | expiring` stand wieder da — zu Recht, das
hochgeladene Zertifikat läuft wirklich am 13. September 2026 ab. Die
Wegwerfschlüssel unter `/root/abnahme-tls` sind gelöscht.

---

## 7 · Punkt 7 — der angehaltene Agent

Der letzte des Laufs, und bewusst dort: Danach ist nichts anderes mehr messbar.

    systemctl is-active → inactive
    6 Prüfung(en) gefahren, 21:29:27.
    Auffällig: 2
    Nicht gemessen: 39
    rc=0

| | erwartet | gemessen |
|---|---|---|
| Rückgabewert | `0` | **`rc=0`** |
| **Kaputt** | keine Zahl | steht gar nicht da |
| agentabhängige Schlüssel | alle elf auf `unreachable` | **elf**, nachgezählt |
| **`orphan.row`** | misst weiter | **`certificate`, kein `unreachable`** |

> **Ein Rückgabewert, der einen gefundenen Schaden als Fehlschlag meldet, macht
> aus dem Boten den Schuldigen.**

Die 39 Zeilen sind elf Prüfschlüssel über ihre Gegenstände: `apt.key` ·
`block.integrity` (2) · `php.config` · `php.file` (3) · `quota.state` ·
`ssh.config` · `tls.file` (5) · `unit.schedule` (5) · `unit.state` (14) ·
`web.config` · `web.file` (5). Dazu die zwei wahren Befunde — **41 Zeilen**
insgesamt.

**Die Gegenprobe trägt den halben Punkt**, und sie ist grün: `orphan.row` steht
mit seinem echten Grund da und nicht mit `unreachable`. Der Agent ist also
wirklich die Ursache und nicht die Zuordnung. Ohne sie wäre „39 nicht gemessen"
von „die Diagnose meldet bei jedem Aussetzer alles als ungemessen" nicht zu
unterscheiden.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**`tls.file | p6-b.invalid` steht zweimal** — einmal `expiring`, einmal
`unreachable`. `unreachable()` legt Zeilen dazu und löscht keine: Der Befund
von vorhin ist nicht widerlegt, nur ungeprüft.

**Die Seite sagt es in Worten:** *„39 Prüfungen sind nicht durchgelaufen. Über
diese Stellen ist gerade nichts bekannt — weder im Guten noch im Schlechten."*
Marke **Nicht gemessen**, und im Wortlaut steht die Ursache: *„Der Agent läuft
nicht: Socket ist nicht vorhanden."* Das ist der vierte Zustand, für den es ihn
überhaupt gibt — kein grünes Häkchen und kein rotes Kreuz.

**`system.user` fehlt in der Liste, und das ist richtig:** Es hat nichts
gefunden, und `ok` erzeugt keine Zeile.

Nach `systemctl start srvpanel-agentd` waren alle 39 `unreachable` fort — und
zwei Zeilen standen da, die vorher nicht dagewesen waren. Das ist Befund 10.

---

## 8 · Punkt 8 — derselbe Schaden über zwei Läufe *(Ausschlusskriterium)*

Gemessen am `web.file`-Befund aus Punkt 5. In derselben Datei wurde ein zweiter
Schaden angelegt (`server_name` ohne Semikolon), damit sich der **Wortlaut**
ändert und die Kennung nicht.

| | vorher (19:26:48) | nachher (19:30:34) |
|---|---|---|
| `id` | 11 | **11** |
| `subject` | `p6-b.invalid` | **`p6-b.invalid`** |
| `reason` | `directive_lost` | **`directive_lost`** |
| `first_seen_at` | `17:26:48Z` | **`17:26:48Z`** |
| `measured_at` | `17:26:48Z` | `17:30:34Z` |
| `detail` | eine Zeile | **zwei Zeilen** |

**Eine Zeile für `web.file`, nicht zwei.**

> **Die Kennung eines Befundes ist `check` + `subject` + `reason` — der Wortlaut
> gehört nicht dazu.** Stünde er darin, hätte jede geänderte Meldung einen neuen
> Befund erzeugt, und „Steht seit" wäre eine Zahl ohne Bedeutung.

Die Nebenwirkung war die aufgeschriebene: `nginx -t` gab `rc=1` mit
`server_names_hash`, und `Kaputt` stand auf 2 statt 1. Vorhergesagt, gemessen,
kein Befund — der zweite Prüfkörper ist laut, weil er einen Pfad verschluckt,
der als Servername zu lang ist (Befund 8).

**Der Rückbau ist der eigentliche Ertrag dieses Punktes** und steht als
Befund 9.

---

## 9 · Die Befunde

### 9.1 Befund 1 — zwei von drei Nähten waren nie verdrahtet

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

### 9.2 Befund 2 — der Vergleich stolperte über die Einrückung

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

### 9.3 Befund 3 — `gpg` legt sein Heimverzeichnis nicht an

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

### 9.4 Befund 4 — die Nummer dieses Protokolls war nicht lesbar

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

### 9.5 Befund 5 — die Zusage einer Vhost-Datei war die Schnittmenge

Gefunden beim Ausschreiben von Punkt 5, am Quelltext und nicht auf dem Server.

`SiteTemplate::PROMISED` war **eine** Liste für alle vier Formen — also die
Schnittmenge dessen, was eine gesperrte, eine weiterleitende, eine statische
und eine PHP-Domain gemeinsam ausgeben. Neun Anweisungen. Eine PHP-Domain gibt
zwanzig aus.

**Gemessen an der echten Vorlage, Zeile für Zeile:** Von fünfundzwanzig
Anweisungen lässt `nginx -t` genau **eine** Auslassung durchgehen — die
`index`-Zeile — und verschluckt wird, was darauf folgt: `client_max_body_size`.
Diese eine lag ausserhalb der Schnittmenge. Die Prüfung schwieg also, und zwar
zu Recht.

> **Eine Zusage über neun Anweisungen sagt über die siebzehn daneben nichts —
> und die stille Form des Schadens traf genau eine davon.**

Behoben durch eine Zusage **je Form**: gesperrt und weiterleitend je 9,
statisch 15, mit PHP 20; ein Zertifikat legt vier `ssl_`-Anweisungen dazu. Jede
Liste ist aus dem Rendering ihrer Form gemessen, und `PromiseReachTest` rechnet
in beide Richtungen nach — zu gross meldete jede Nacht jede heile Domain, zu
klein meldete nichts.

**Und die Form kommt von der Stelle, die schreibt, und nicht aus der geprüften
Datei.** Das ist der Teil, der leicht falsch geraten wäre: Läse die Prüfung die
Form aus dem Dateiinhalt, schrumpfte die Zusage mit dem Schaden — eine Datei,
der `fastcgi_pass` fehlt, sähe aus wie eine statische, und genau die
Anweisungen, die verschwunden sind, würden nicht mehr erwartet.
`WebLifecycle::form()` ist die eine Stelle, die es beantwortet.

### 9.6 Befund 6 — eine Marke, die eine Handlung benannte

Der Zustand `Warn` hiess „Sieht jemand hin". Die anderen drei Marken benennen
einen **Zustand** — „In Ordnung", „Kaputt", „Nicht gemessen"; diese eine
benannte eine Handlung, und sie las sich in einer Tabellenspalte wie eine
Frage. Sie heisst jetzt **„Auffällig"**.

`FindingStateTest` hält es an der Form und nicht am Wortlaut: höchstens zwei
Wörter, kein unbestimmtes Fürwort. **Das Fürwort ist das Merkmal, an dem sich
eine Handlung von einem Zustand unterscheiden lässt, ohne Deutsch zu parsen** —
ein Zustand hat keinen Handelnden. Beide Hälften einzeln belegt: „Sieht jemand
hin" fällt über die Länge, ein „Jemand hinsehen" über das Fürwort.

Im selben Zug ist der Hinweis darunter geschärft worden: „Noch ist nichts
kaputt" stimmte für ein ablaufendes Zertifikat und nicht für eine verwaiste
Zeile.

### 9.7 Befund 7 — die Vorschrift nannte zweimal denselben falschen Ort

`docs/99 §5` nannte `/etc/nginx/sites-available/` als Ort der Vhost-Dateien.
Das Panel schreibt nach `Site::CONF_DIR`, also `/etc/nginx/srvpanel.d/`.
Behoben — und **`docs/99 §7` trug denselben Fehler**, unbemerkt, weil nur
die eine gemeldete Stelle angesehen worden war.

> **Ein Fehler, den man an einer Stelle behoben hat, ist an der nächsten wieder
> da, wenn die Behebung nicht die Regel wurde.**

Das ist derselbe Satz, den dieses Projekt seit `docs/59` viermal bezahlt hat.

### 9.8 Befund 8 — ein Prüfkörper, der lauter ist als gemeint

Der zweite Eingriff von Punkt 8 (`server_name` ohne Semikolon) ist **laut**:
Das `server_name` verschluckt die folgende Zeile, und die ist ein Pfad zum
Zugriffsprotokoll — 64 Zeichen, also länger als
`server_names_hash_bucket_size`. `nginx -t` gibt `rc=1`, und ein zweiter Befund
`web.config` kommt dazu.

**Das ist für Punkt 8 gleichgültig** — er misst die Kennung der einen
`web.file`-Zeile über zwei Läufe — und war vorhergesagt. Es macht ihn aber
untauglich für Punkt 5, und beim Nachmessen an M3 zeigte sich, warum: Die
stille Form dort verschluckte ein kurzes Wort und keinen Pfad.

> **Eine Messung an einem selbstgebauten Prüfkörper sagt über die Datei, die das
> Panel schreibt, nur so viel, wie die beiden gemeinsam haben.**

### 9.9 Befund 9 — der Rückbau wurde mit dem falschen Prüfer abgenommen

**Der teuerste Befund des Nachmittags, und er steckt in der Vorschrift.**

Nach dem Rückbau von Punkt 8 stand `Kaputt: 1`, wo nichts hätte stehen sollen.
Der Auszug entschied es in einem Blick: `gemessen 17:31:04` ist der Rückbaulauf
selbst, `replace()` hatte die Zeile also neu geschrieben — kein Rest, sondern
ein Schaden, den die Datei auf der Platte wirklich noch trug. Und der Wortlaut
nannte nur noch `client_max_body_size`: Der `server_name`-Schaden war fort, der
`index`-Schaden nicht.

**Die Sicherung selbst trug ihn schon** — Datei *und* `/root/…abnahme`, beide
ohne Semikolon in Zeile 24.

> **Eine Sicherung, die man vor dem Eingriff nimmt, ist nur dann ein Rückweg,
> wenn der Zustand davor heil war — und das hat in diesem Lauf niemand
> gemessen.**

Die zweite Hälfte wiegt schwerer, weil sie den ganzen Nachmittag betrifft:
**Jeder Rückbau war mit `nginx -t` abgenommen worden.** Genau dieser Schaden
ist der, den `nginx -t` nicht sieht — der Grund, aus dem es Punkt 5 gibt.

> **Ein Rückbau, den man mit dem Prüfer misst, den der Schaden nicht auslöst,
> ist nicht gemessen.**

Die Regel steht seitdem in `docs/99 §0` für **jeden** Punkt, der eine Datei
anfasst.

**Und der saubere Rückbau ist beim ersten Versuch zu früh gemessen worden.**
`srvpanel vhost --sites` meldet „5 Server-Blöcke der Kundendomains
**eingereiht**" — geschrieben werden sie vom Arbeiter. `nginx -t` und
`srvpanel diagnose` liefen im selben Rutsch, also Sekunden später, und trafen
die alte Datei an. Das ist **Form A** aus `docs/86 §5`:

> **Ein Vorgang, der nur meldet, dass er abgesetzt wurde, sagt über den Ausgang
> dessen, was er abgesetzt hat, nichts.**

Bemerkenswert daran ist, dass das Messmittel richtig gearbeitet hat: Abgenommen
wurde mit der Diagnose statt mit `rc=0`, und die Diagnose meldete
wahrheitsgemäss, dass die Datei noch kaputt war. Falsch war nicht das
Instrument, sondern **wann** es angesetzt wurde.

Nach der Warteschlange stand das Semikolon wieder, und in der Liste blieb nur
`orphan.row`.

**Der ganze Vorgang ist zugleich der beste Beleg dieser Stufe:** A10 hat einen
stillen Schaden auf einer Datei gefunden, die `nginx -t` für gültig hält, in
einer Lage, die niemand gestellt hat — und hat den Rückbau abgenommen, den
`nginx -t` durchgewinkt hätte.

### 9.10 Befund 10 — `Requires=` überträgt das Anhalten und nicht das Starten

**Gefunden von der Diagnose selbst, im letzten Lauf von Punkt 7.** Nach
`systemctl start srvpanel-agentd` waren die 39 `unreachable` fort, und zwei
neue Zeilen standen da:

    unit.state | srvpanel-worker.service  | inactive
    unit.state | srvpanel-metrics.service | inactive

Beide tragen `Requires=srvpanel-agentd.service`. Das Anhalten des Agenten hat
sie mitgenommen, das Starten hat sie nicht zurückgeholt; `Restart=always` greift
nur, wenn ein Prozess von selbst endet, nicht wenn systemd die Unit absichtlich
angehalten hat.

**Der Worker ist die Warteschlange.** Ohne ihn bleibt jeder Vorgang auf
„wartet" stehen — kein Zertifikat wird bestellt, kein Server-Block geschrieben,
kein Update abgesetzt. Es gäbe keine Fehlermeldung, nur Stillstand.

> **Eine Abhängigkeit, die das Anhalten überträgt und das Starten nicht,
> hinterlässt einen Zustand, den nur der herstellt, der ihn auch beheben
> müsste.**

Gebaut ist die Antwort als `srvpanel.target` (Variante A, entschieden vom
Betreiber): Es führt die vier Dauerdienste und die fünf Timer, jede dieser neun
Units trägt `PartOf=srvpanel.target`. Nicht `Upholds=` auf der Agent-Unit —
das spräche die Absicht nicht aus, sondern erzwänge sie, und ein absichtlich
gestoppter Worker käme von selbst wieder hoch.

**Zwei Begründungen dazu standen zuerst da und waren beide falsch**, beide
gegen systemd 255 in einer eigenen Namespace nachgemessen, jede mit Gegenprobe:

| Handgriff | Ziel vorher | Unit mit `PartOf=` | Gegenprobe ohne |
|---|---|---|---|
| `stop` | **nie gestartet** | angehalten | bleibt |
| `start` | inactive | gestartet | gestartet |
| `restart` | active | neue PID | PID unverändert |
| `stop` | active | angehalten | bleibt |

Ein `stop` auf ein nie gestartetes Ziel überträgt also sehr wohl; `--now` im
postinst steht wegen der Anzeige und nicht wegen der Wirkung. Und ein Ziel mit
`Requires=` nimmt die übrigen Units **nicht** mit — es bleibt selbst `inactive`
und gibt 1 zurück, während sie laufen.

> **Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist auch
> dann falsch, wenn der Handgriff daneben richtig ist — und er hält länger als
> der Handgriff, weil ihn der Nächste liest und glaubt.**

**Der erste Prüfkörper dieser Messung war keiner.** Ein `Type=simple` mit
`ExecStart=/bin/false` gilt als gestartet, sobald der Prozess forkt; der
Fehlschlag kommt danach und erreicht den Startauftrag nie. `Requires=` und
`Wants=` zeigten damit Zeile für Zeile dasselbe. Erst ein `Type=oneshot`
scheitert im Startauftrag.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

**Und beim Aufräumen wäre der Container beinahe als sauber durchgegangen.** Der
Beleg für das `[Install]`-Stück ist ein `systemctl enable` im Namespace — und
`/etc` liegt nicht darin, der Symlink landete auf dem Host. Nachgesehen wurde
mit `ls -la … | head`, und der Name steht alphabetisch hinter der zehnten
Zeile.

> **Eine abgeschnittene Liste sieht aus wie eine vollständige — sie sagt nicht,
> wo sie aufhört.**

`UnitTargetTest` hält den Sollzustand **aus den Unit-Dateien** und nicht aus
einer Liste im Test: Ein `.service` gehört ins Ziel, wenn er kein
`Type=oneshot` ist, jeder `.timer` gehört hinein. Fünf Eingriffe im
Bruchskript, alle einzeln belegt.

**Auf `cloudsrv24` nachgemessen am 4. September 2026** gegen `0.7.3-rc.15`:

    systemctl status  → active since 08:48:09, enabled; preset: enabled
    systemctl show -p Wants → neun Units: vier .service, fünf .timer,
                              kein Type=oneshot darunter

    stop srvpanel.target;  sleep 3 → inactive inactive inactive inactive
    start srvpanel.target; sleep 3 → active   active   active   active

    stop + start srvpanel-agentd   → worker und metrics bleiben inactive

Die letzte Zeile ist die wichtigste: **Das Ziel behebt die Ursache nicht.** Ein
`Requires=` überträgt weiterhin nur das Anhalten; das Ziel gibt den Griff, der
den Zustand wieder aufhebt, und nicht eine Reparatur der Abhängigkeit.

**Und die erste Messung war keine.** Sie stand ohne `sleep` da und ergab
`active · deactivating · deactivating · deactivating`: Der Agent trägt
`Before=srvpanel-worker.service srvpanel-metrics.service`, geht beim Anhalten
also zuletzt, und `systemctl stop` auf das Ziel kehrt zurück, bevor die
übertragenen Aufträge durch sind.

> **Ein `is-active` unmittelbar nach dem `stop` misst den Übergang und nicht den
> Zustand.**

---

## 10 · Was der Lauf über den **Server** gefunden hat

**Zwei Zeilen, und beide sind wahr.**

`orphan.row` / `tls.cloudlab24.de` / „ohne Domain" — ein Rest aus P7, ein
Zertifikat, dessen Domain zurückgebaut wurde. `CertificatePrune` soll es seit
dem 24. August genau so benennen; die Prüfung tut also, was sie soll. Für den
Lauf war er **nützlich**: Punkt 8 verlangt einen Befund, der von allein
stehenbleibt, und das ist der beste Prüfkörper, den dieser Server hergibt.

`tls.file` / `p6-b.invalid` / `expiring` — das hochgeladene Wegwerfzertifikat
aus Punkt 6. Es läuft am **13. September 2026** ab; der Befund ist wahr und
verschwindet von selbst.

Beide sind **Auffällig** und keines **Kaputt**. Ein einziger echter Schaden ist
auf `cloudsrv24` nicht gefunden worden.

**Und der dritte kam erst im letzten Lauf**, aus einem Eingriff des Laufs
selbst: die beiden stillstehenden Dienste aus Befund 10. Das ist der einzige
Befund dieses Nachlaufs, den ein Betreiber auch im Alltag hätte auslösen können
— und der einzige, den kein Wächter dieses Repos je hätte finden können, weil
er nicht im Quelltext steht, sondern in der Wirkung zweier Unit-Dateien
aufeinander.

---

## 11 · Bilanz

**Acht von acht Punkten erfüllt**, beide Ausschlusskriterien (5 und 8)
darunter. Kein Punkt ist als „nicht herstellbar" ausgefallen.

| Punkt | | |
|---|---|---|
| 1 | ein heiler Server meldet nichts | erfüllt |
| 2 | entfernter verwalteter Bereich | erfüllt (im zweiten Anlauf vollständig) |
| 3 | gestoppter Timer | erfüllt |
| 4 | `BEGIN` ohne `END` | erfüllt |
| 5 | fehlendes Semikolon | **erfüllt** *(Ausschlusskriterium)* |
| 6 | Zertifikat in drei Zuständen | erfüllt |
| 7 | angehaltener Agent | erfüllt |
| 8 | derselbe Schaden über zwei Läufe | **erfüllt** *(Ausschlusskriterium)* |

**Zehn Befunde: sechs im Prüfling, vier im Prüfmittel oder in der Vorschrift.**

| | im Prüfling | im Prüfmittel / in der Vorschrift |
|---|---|---|
| 1 | zwei von drei Nähten nie verdrahtet | |
| 2 | Vergleich stolperte über die Einrückung | |
| 3 | `gpg` legt sein Heimverzeichnis nicht an | |
| 4 | | Dokumentnummern mit zwei Ziffern gelesen |
| 5 | die Zusage war die Schnittmenge | |
| 6 | eine Marke benannte eine Handlung | |
| 7 | | zweimal derselbe falsche Ort |
| 8 | | ein Prüfkörper, der lauter ist als gemeint |
| 9 | | der Rückbau mit dem falschen Prüfer abgenommen |
| 10 | `Requires=` überträgt das Starten nicht | |

**Das ist die Umkehrung von `docs/45`, `docs/48`, `docs/59` und `docs/84`**, wo
die Mehrheit im Prüfmittel steckte — und dieselbe Lage wie bei A2 (`docs/91`),
aus demselben Grund: Die Vorschrift war vor dem Lauf ausgeschrieben und dreimal
berichtigt (`docs/99 §0`), das Messmittel lag als geprüftes Werkzeug im Repo.
Was blieb, war **neuer Code**.

Die sechs im Prüfling haben eine gemeinsame Form, und sie ist nicht
„Unachtsamkeit":

> **Fehler an Nähten zwischen zwei Dateien** — Katalog und Container, Vorlage
> und Leser, Aufruf und Ablageort, Vorlage und Zusage, Unit und Unit. Jede
> Seite für sich war in Ordnung; kein Wächter stand dazwischen.

**Keiner der sechs hätte durch schärferes Hinsehen im Container auffallen
können**, und das ist der Grund, dass es Serverläufe gibt. Befund 10 ist dabei
der deutlichste Fall: Er entsteht erst, wenn jemand einen Dienst anhält und
wieder startet — ein Handgriff, den kein Test macht.

**Und ein Befund war keiner.** Zu Punkt 6 hatte ich einen `tls.wire`-Eintrag
vorhergesagt, der nicht kam. Das Verhalten ist richtig, `CertificateVerdictTest`
hält es, und die Vorhersage war schlicht falsch — hätte ich sie stehenlassen,
wäre aus richtigem Verhalten ein Mangel geworden.

---

## 12 · Was offen bleibt

- **`orphan.row` / `tls.cloudlab24.de`** — der Rest aus P7. Ob er über
  `srvpanel tls --prune` abgeräumt wird, entscheidet der Betreiber; die Prüfung
  meldet ihn zu Recht.
- **`tls.file` / `p6-b.invalid` / `expiring`** — das hochgeladene
  Wegwerfzertifikat aus Punkt 6. Es läuft am 13. September 2026 von selbst aus.
  Ob es vorher entfernt wird, ist ebenfalls offen; ob ein Umstellen auf „es
  entscheidet wieder die Automatik" dafür genügt, ist **ungemessen** —
  `CertificateChoice` kann für eine `.invalid`-Domain nichts bestellen, und was
  dann ausgeliefert wird, gehört gemessen und nicht vorhergesagt.
- **Der Warnpfad von `apt.key` kann auf diesem Server nie feuern.** Feld 7 der
  `pub`-Zeile ist leer — der Signaturschlüssel läuft nie ab. Dass die Prüfung
  **liest**, ist belegt (ed25519, Fingerabdruck `58EE…3E86`, und
  `/var/lib/srvpanel/gnupg` existiert weiterhin nicht); dass sie bei einem
  ablaufenden Schlüssel auch **meldet**, ist es nicht.

**Ein vierter Punkt stand hier bis zum 4. September und ist gemessen:**
`srvpanel.target` hatte keinen Server gesehen. Jetzt hat es einen — die Messung
steht in §9.10.
