# 91 — Protokoll des Nachlaufs zu A2

Der Lauf ist `docs/90`, gefahren auf `cloudsrv24` ab dem **31. August 2026**.
Hier steht, was gemessen wurde — Punkt für Punkt, mit den Werten und nicht mit
dem Eindruck.

**Der Lauf ist nicht durch.** §1 ist gemessen und hat einen Befund am Prüfling
gebracht, der vor Punkt 1 behoben werden musste: Ohne ihn läse man Punkt 1 gegen
eine Seite, von der schon feststeht, dass sie vier Zeilen falsch färbt.

---

## 1 · §1 — Was vorher dasteht

Gemessen als `root@cloudsrv24` (`netcup/cloudsrv24.de`).

| | gefragt | gemessen | erwartet |
|---|---|---|---|
| a | `srvpanel version` | `0.7.3-rc.1` | `0.7.3-rc.1` ✓ |
| b | `systemctl --version` | **255** (255.4-1ubuntu8.17) | 249 bis 257 ✓ |
| c | `LoadState` / `ActiveState` je Unit | siehe unten | **nein** — siehe §3 |

**(b) ist mehr wert, als es aussieht.** Punkt 3 hängt daran, dass
`list-timers --output=json` seinen Termin als rohe Mikrosekunden liefert, und
gemessen ist das nur gegen systemd 255. Der Server fährt dieselbe Fassung wie
die Container-Namespace, in der A2 entstanden ist — Punkt 3 steht damit auf
gemessenem Boden statt auf einer Hoffnung.

> **Eine Annahme, die auf dem Zielserver zufällig zutrifft, ist dort keine
> Annahme mehr — aber auf dem nächsten wieder.** Für 249 und 257 bleibt sie
> ungemessen, und `SystemUnitsList::schedule()` darf genau deshalb
> fehlschlagen, ohne die erste Frage mitzunehmen.

**(c) im Einzelnen.** Alle zwölf eigenen Units `loaded`; keine fehlt.

| Unit | LoadState | ActiveState |
|---|---|---|
| `srvpanel-agentd.service` | loaded | active |
| `srvpanel-web.service` | loaded | active |
| `srvpanel-worker.service` | loaded | active |
| `srvpanel-metrics.service` | loaded | active |
| `srvpanel-usage.service` | loaded | **inactive** |
| `srvpanel-usage.timer` | loaded | active |
| `srvpanel-tls.service` | loaded | **inactive** |
| `srvpanel-tls.timer` | loaded | active |
| `srvpanel-cron.service` | loaded | **inactive** |
| `srvpanel-cron.timer` | loaded | active |
| `srvpanel-dns.service` | loaded | **inactive** |
| `srvpanel-dns.timer` | loaded | active |
| `nginx.service` | loaded | active |
| `mariadb.service` | loaded | active |
| `mysql.service` | loaded | active |
| `ssh.service` | loaded | active |
| `sshd.service` | loaded | active |
| `cron.service` | loaded | active |
| `crond.service` | **not-found** | — |

**Zwei Beobachtungen daneben, beide gut.** `mysql.service` und `sshd.service`
stehen **beide** neben ihrer Schwester — der Server übt also wirklich die beiden
Zusammenfälle, die `Catalog::pick()` auflösen soll, und nicht nur einen
konstruierten. Neunzehn Kandidaten ergeben damit **sechzehn** Zeilen, genau die
Zahl, die Punkt 1 erwartet.

**Und die Erwartung in §1 war falsch.** Dort stand „die zwölf eigenen Units
`loaded` **und** `active`". Vier davon können das nicht sein — siehe §3.

---

## 2 · Die Messrunde, die der Befund nötig gemacht hat

Gemessen im Container gegen echtes systemd 255 in einer eigenen PID- und
Mount-Namespace (der Handgriff steht in `docs/89 §1`), mit **je einem eigenen
Prüfkörper je Fall** und in beide Richtungen.

Fünf Sonden, jede eine eigene Unit:

| Sonde | Bauart | Timer | `ActiveState` | `SubState` | `Result` |
|---|---|---|---|---|---|
| A | `Type=oneshot`, lief durch | aktiv | `inactive` | `dead` | `success` |
| B | `Type=oneshot` | **keiner** | `inactive` | `dead` | `success` |
| C | `Type=simple`, läuft | keiner | `active` | `running` | `success` |
| D | `Type=oneshot`, `ExecStart` scheitert | aktiv | **`failed`** | `failed` | `exit-code` |
| E | `Type=oneshot`, nie gelaufen | vorhanden, nie gestartet | `inactive` | `dead` | `success` |

**Zeile D ist die, an der die Behebung hängt.** Ein oneshot-Dienst, dessen
letzter Lauf scheiterte, steht auf `failed` und nicht auf `inactive` — die
Nachsicht für wartende Dienste kann also an `inactive` hängen und verdeckt
keinen Schaden.

### Warum `Triggers` am Timer und nicht `TriggeredBy` am Dienst

Die naheliegende Frage ist die vom Dienst aus: „wer startet mich?" Systemd
beantwortet sie mit `TriggeredBy`. Gemessen an A und E, jeweils über den vollen
Zyklus:

| Zustand des Timers | `TriggeredBy` am Dienst | `Triggers` am Timer |
|---|---|---|
| nie gestartet | **leer** | der Dienst |
| gestartet | der Timer | der Dienst |
| wieder gestoppt | **leer** | der Dienst |

`TriggeredBy` entsteht beim **Aktivieren** des Timers und verschwindet, sobald
er stoppt — es ist eine Laufzeittatsache und keine über die Bauart. Ein von Hand
gestoppter Timer machte seinen oneshot-Dienst damit wieder zu einem Dauerdienst,
und die Seite malte den **Dienst** rot für einen Schaden, der dem **Timer**
gehört und in dessen eigener Zeile schon steht.

> **Eine Eigenschaft, die nur dasteht, solange der andere läuft, beantwortet
> nicht „wozu gehört dieser Dienst", sondern „läuft der andere gerade".**

`Triggers` kommt aus der Unit-Datei und steht in allen drei Zuständen da. Es
wird für die Timer ohnehin schon gelesen (`Units::read()` gibt es als
`triggers` weiter, die Seite zeigt es in der Spalte „Startet") — die Zuordnung
kostet damit **keine zweite Frage an systemd und keine Liste in diesem Repo**.

**Zwei Nebenbefunde der Runde**, beide gemessen und beide ohne Folge für die
Behebung:

1. `ExecMainStartTimestamp` ist bei Sonde A **leer** und bei Sonde D gesetzt.
   Ein `Type=oneshot`, der durchläuft, gibt seinen Zeitstempel beim Zurückfallen
   auf `inactive` wieder her; ein gescheiterter behält ihn. Die Spalte „seit"
   der Seite bleibt für die vier wartenden Dienste deshalb leer — richtig, weil
   sie gerade nicht laufen, und nicht dieselbe Auskunft wie „nie gelaufen".
2. Die Prüfkörper gehören hinterher weggeräumt. Sie liegen in `/run/systemd`
   **innerhalb** der Namespace und verschwinden mit ihr — anders als am
   26. August, als eine liegengebliebene Datei `SourceOwnershipTest` in der CI
   rot und hier grün gemacht hat.

---

## 3 · Befund 1 — Der gesunde Server meldet vier Schäden

**Am Prüfling, gefunden durch §1.**

Vier der zwölf eigenen Dienste sind `Type=oneshot` — `srvpanel-usage`,
`srvpanel-tls`, `srvpanel-cron` und `srvpanel-dns`. Sie tun ihre Arbeit in
einem Lauf und fallen danach auf `inactive` zurück; ihre vier Timer stehen
`active` daneben. Auf `cloudsrv24` stand genau das (§1c), und der Server ist in
Ordnung.

Die Seite hat daraus **vier rote Zeilen** gemacht (`rang()` fällt für alles, was
nicht `active` oder `activating` ist, auf `critical` durch), in jeder Zeile den
Zustand **„gestoppt"** geschrieben, und darüber die gelbe Meldung
**„4 Dienste laufen nicht."** gesetzt.

> **Ein Dienst, den ein Timer startet, läuft zwischen seinen Läufen nicht — das
> ist keine Störung, sondern seine Bauart.**

**Das ist derselbe Fehler wie beim Timer, nur spiegelverkehrt.** A2 gibt es,
weil `ActiveState` beim gesunden und beim kaputten Timer gleich aussieht — dort
sieht der kaputte gesund aus, hier der gesunde kaputt. Beide Male ist die
Ursache dieselbe: **eine Zustandsspalte, die für eine Bauart gedacht ist, auf
alle angewandt.**

> **Ein Feld, das für einen Dauerdienst eine Auskunft ist, ist für einen
> Dienst, der von einem Timer gestartet wird, keine.**

**Kein Wächter konnte ihn sehen**, und der Grund gehört aufgeschrieben: Die
Prüfkörper von `UnitStateTest` sind aus der Messrunde vom 30. August, und dort
gab es **keinen oneshot-Dienst**. Gemessen worden waren ein laufender Dienst,
vier Timer und eine fehlende Unit — die Bauart, die auf dem Zielserver in vier
von zwölf Fällen vorkommt, kam im Prüfstand nicht vor.

> **Ein Prüfstand, dem eine Bauart fehlt, ist über sie nicht still — er ist
> grün.**

### Was gebaut ist

`Units::markScheduled()` trägt an jedem Dienst nach, ob ein Timer aus derselben
Antwort ihn in `Triggers` nennt; `SystemUnitsList` ruft es zwischen `readMany()`
und dem Bau der Zeilen. Das Feld heisst `scheduled` und ist **`null` bei allem,
was kein Dienst ist** — dieselbe Unterscheidung wie bei `pid` und `has_next`:
„wird nicht von einem Timer gestartet" und „kann gar nicht" sind zwei Auskünfte.

Die Seite hat daraus zwei Zeilen bekommen, beide ausdrücklich an `inactive`
gebunden und nicht an „nicht aktiv":

    if (zeile.scheduled === true && zeile.active_state === 'inactive') return 'ok'
    if (zeile.scheduled === true && zeile.active_state === 'inactive') return 'wartet auf seinen Timer'

Und die Meldung darüber zählt seitdem **über `rang`** statt über `active_state`:

> **Zwei Fassungen derselben Regel laufen auseinander, und die zweite ist die,
> die veraltet.** Ohne diesen Schritt wären nach der Behebung vier Zeilen grün
> gewesen — und darüber hätte weiter „4 Dienste laufen nicht" gestanden.

### Sechs Wächter, acht Brüche

`UnitStateTest` bekam zwei gemessene Prüfkörper (`ONESHOT_WARTET`,
`ONESHOT_GESCHEITERT`) und fünf Fälle: dass ein Dienst mit Timer markiert wird,
dass einer **ohne** Timer es **nicht** wird, dass ein gescheiterter Lauf
`failed` bleibt, dass die Zuordnung einen **gestoppten** Timer überlebt, und
dass `TriggeredBy` weder in `Units::FIELDS` noch im Quelltext ohne Kommentare
vorkommt.

Dazu einer, der die Rechnung von ihrem Gebrauch trennt:
`test_the_operation_actually_pairs_the_rows` prüft, dass `markScheduled`
**gerufen wird**. Dieselbe Lehre wie bei `SourceKeyFilterTest` — ein Leser, der
stimmt und den niemand aufruft, ist an der Anzeige von einem, den es nicht gibt,
nicht zu unterscheiden.

`ServicesViewTest` hält die beiden Zeilen der Seite und die Reihenfolge im Rumpf
von `zustand`: Der Fehlschlag muss vor der Nachsicht stehen.

**Alle acht Eingriffe beissen, einzeln belegt.** Einer hat dabei einen Fehler im
frisch gebauten Wächter gefunden: `test_a_service_a_timer_starts_may_stand_still`
suchte die Bedingung **irgendwo auf der Seite** und blieb grün, als der Eingriff
sie aus `rang` entfernte und in `zustand` stehenliess.

> **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald die Zeichenkette
> irgendwo steht.** Zum zweiten Mal in dieser Stufe — beim ersten Mal war es
> `ClassReachTest` und ein `<style>`-Block im Vorlagenblock.

Gefragt wird seitdem je Funktionsrumpf. Und der erste Wurf desselben Wächters
hatte einen zweiten Fehler derselben Art: Er verglich die Reihenfolge zweier
Fundstellen aus **zwei** Funktionen.

> **Zwei Fundstellen aus zwei Funktionen haben keine Reihenfolge zueinander.**

---

## 4 · Punkt 1 — erfüllt

Gefahren am 31. August 2026 auf `cloudsrv24` gegen **`0.7.3-rc.2`**, im Browser
eines iPhones, über den Menüpunkt „Dienste" in der Gruppe „Betrieb".

| Erwartung | gemessen |
|---|---|
| zwei Bereiche | Dienste und Timer ✓ |
| zwölf Dienstzeilen | acht eigene, dann `nginx`, `mariadb`, `ssh`, `cron` ✓ |
| vier Timerzeilen | `usage`, `tls`, `cron`, `dns` ✓ |
| keine Unit doppelt | kein `mysql`, kein `sshd` ✓ |
| die vier oneshot-Dienste grün, „wartet auf seinen Timer" | ✓ |
| keine gelbe Meldung | ✓ |

**Das Zusammenfallen ist damit auf einem Server belegt und nicht an Attrappen.**
Auf `cloudsrv24` sind `mysql.service` **und** `mariadb.service`, `ssh.service`
**und** `sshd.service` alle vier `loaded active` (§1) — `Catalog::pick()` musste
also wirklich wählen, und die Seite zeigt aus neunzehn Kandidaten sechzehn
Zeilen.

**Und die Behebung aus §3 ist angekommen.** Vier Zeilen, die gegen `rc.1` rot mit
„gestoppt" dastanden und eine gelbe Meldung über sich hatten, stehen jetzt grün
mit „wartet auf seinen Timer" und ohne Meldung.

---

## 5 · Befund 2 — „Alle Dienste laufen" stimmt nicht mehr

**Am Prüfling, vor dem Lauf angekündigt und im Bild belegt.**

Steht nichts an, schreibt die Seite **„Alle Dienste laufen, und jeder Timer hat
einen Termin."** Direkt darunter stehen vier Zeilen mit „wartet auf seinen
Timer". Vier der zwölf laufen gerade **nicht**, und das ist ihr gesunder Zustand.

> **Ein Satz, der beruhigen soll, darf nicht das Gegenteil dessen behaupten, was
> drei Zeilen tiefer steht.**

Der Satz stammt aus der Zeit vor der Behebung, als „läuft" und „in Ordnung"
dasselbe waren. Seit §3 sind es zwei Dinge — und die Meldung ist die Stelle, an
der der alte Sprachgebrauch stehengeblieben ist.

> **Eine Behebung ändert, was ein Wort bedeutet — und die Sätze, die es
> benutzen, ändert sie nicht mit.**

---

## 6 · Befund 3 — Der Zustand ist bei 390 px angeschnitten

**Am Prüfling, gefunden am Bild und nachgemessen.**

Gemessen mit dem echten Markup und beiden gebauten Stylesheets, dunkel bei
390 px (Gegenprobe 216, Dokumentüberlauf 0):

| | sichtbar | Inhalt | rollt |
|---|---|---|---|
| Dienste (fünf Spalten) | 358 px | **1005 px** | 647 px |
| Timer (vier Spalten) | 358 px | **744 px** | 386 px |

| Marke | Breite | ragt über den Rand |
|---|---|---|
| „wartet auf seinen Timer" | 208 px | **58 px** |
| „kein nächster Termin" | 186 px | **10 px** |

**Die zweite Zeile ist der Befund.** „kein nächster Termin" ist der Satz, an dem
das Abnahmekriterium von A2 hängt — Punkt 4 verlangt, dass ein Timer ohne Termin
erkennbar ist, „ohne dass man die Zahl deuten muss". Bei 390 px liest man
`kein nächster Termi`, und der Rest steht hinter dem Rand.

> **Ein Satz, der einen Schaden benennt, ist bei der Breite, bei der man ihn
> liest, angeschnitten — und zehn Pixel sind genug dafür.**

Das Dokument schiebt dabei **nicht**: Beide Tabellen liegen in `.scrolls` und
rollen wie entworfen. Die Messung, die dieses Projekt seit `v0.4.0-rc.4` fährt,
meldet hier zu Recht `0`.

> **Eine Zahl, die am Dokument misst, sagt nichts über eine Zelle, die selbst
> rollen darf.** Derselbe Satz wie am 11. August, an derselben Art Behälter.

**Was fehlt, ist die Kärtchenform.** Die Übersichtsseite trägt für dieselbe Art
Tabelle `class="stacks"`; die Dienste-Seite hat sie nicht. Behoben und gemessen
in §7.

---

## 7 · Befund 2 und 3 sind behoben — und es war keine Entscheidung, sondern eine Auslassung

**Ausgezählt am 31. August 2026 über `resources/js`:** 25 Tabellen tragen
`stacks`, 25 tragen `pairs`, eine trägt `rows` — und **keine einzige** steht ohne
Form da, ausser den beiden der Dienste-Seite.

Damit war Befund 3 keine Gestaltungsfrage. Ich hatte gefragt, ob die Kärtchenform
hierher passt und was sie kostet; die Frage war falsch gestellt.

> **Eine Voreinstellung, die niemand getroffen hat, sieht aus wie eine
> Entscheidung.**

**Und der Grund, warum sie ausblieb, steht in `app.css`.** Der Kommentar über
`.scrolls` nannte „Für Messwerte: Dateisysteme, Prozesse, **Dienste**" — und die
Übersicht stapelt genau diese drei Tabellen, seit es sie gibt. Ich habe die
Seite nach dem Kommentar gebaut und nicht nach dem Code.

> **Eine Zeile im Kommentar, die eine Konvention behauptet, veraltet ohne
> Vorwarnung — und der Code daneben sagt seit langem etwas anderes.**

### Was die Behebung kostet, gemessen

Acht Lagen, echtes Markup mit allen sechzehn Zeilen, beide gebauten
Stylesheets, Gegenprobe 216 überall:

| | Höhe bei 390 px | „kein nächster Termin" | 1440 px |
|---|---|---|---|
| vorher | 1084 px | ragt **+10 px** über den Rand | 987 px |
| nachher | **3608 px** | **−15 px**, vollständig sichtbar | **987 px** |

**Auf dem Bildschirm kostet es nichts** — 987 px vor wie nach, `schiebt: []`.
`.stacks` wirkt erst unter 720 px. Bezahlt wird bei 390 px mit **2524 px** mehr
Höhe, also gut drei Bildschirmen.

> **Ein Preis, den nur die schmale Ansicht zahlt, ist kein Preis der Seite,
> sondern einer der Breite.**

`schiebt` meldet nachher `[thead, tr, thead, tr]` — das gewollte
`.stacks thead`, das unter 720 px ausgeblendet wird.

**Nicht gemessen und benannt offen:** ob `PID` und `Neustarts` auf einem Telefon
überhaupt hingehören. Zwölfmal `NEUSTARTS 0` untereinander ist ein guter Teil
der 2524 px, und für eine Spalte, die je Zeile ausgeblendet wird, hat `app.css`
heute keinen Mechanismus. Wer das anfasst, misst zuerst.

### Befund 2

Die Meldung heisst jetzt **„Jeder Dienst ist in Ordnung, und jeder Timer hat
einen Termin."** — „läuft" und „in Ordnung" sind seit §3 zweierlei.

### Der Wächter, und die drei Fehler in ihm selbst

**`MobileTableTest`** hält drei Richtungen: jede Tabelle nennt ihre Form, jede
Form ist in `app.css` gestaltet, und jede gestapelte Zelle trägt ihre
Beschriftung. Drei Eingriffe, alle belegt.

**Sein erster Lauf war zweimal rot, und beide Male gehörte der Fehler ihm.**

1. Er verlangte `^\s*\.pairs` — die Regel heisst `table.pairs`.
   > **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die Gewohnheit
   > und nicht die Regel.**
2. Er meldete sechs Zellen ohne `data-column` aus sechs Seiten, alle sechs zu
   Recht so geschrieben: `app.css` führt „die Zelle ohne Beschriftung — der
   Knopf am Zeilenende" ausdrücklich.
   > **Ein Wächter, der zu viel meldet, wird abgeschaltet — und zwar von dem,
   > der ihn gebaut hat.**

   Und der erste Versuch, das zu berichtigen, war auch falsch: `strip_tags()`
   liess „Bearbeiten" stehen.
   > **Ein Wächter, der Marken abstreift, hält den Text darin für Inhalt.**

**Und der dritte Fehler steckte im Bruch.** Der Eingriff zu „eine Form ohne
Regel" benannte allein `table.pairs {` um und biss nicht — `table.pairs
td.ident {` blieb stehen und beantwortete die Frage „gibt es eine Regel?"
weiter mit ja.

> **Eine zweite Regel für dieselbe Hülle macht die Frage „gibt es eine?"
> stumpf.** Zum zweiten Mal in dieser Stufe, nach der Untergrenze in §3.

---

## 8 · Punkt 2 — erfüllt

Gefahren auf `cloudsrv24` gegen `0.7.3-rc.3`, mit genau dem Aufruf, den der
Agent macht.

| | erwartet | gemessen |
|---|---|---|
| Blöcke (`awk RS=""`) | 19 | **19** |
| `Id=`-Zeilen | 19 | **19** |

**Das ist das Ausschlusskriterium der Stufe.** Die Blocktrennung von
`systemctl show a b c` war gegen **drei** Units gemessen (`docs/89 §4`); hier
sind es neunzehn. Passt die Zahl nicht, wirft `Units::readMany()` — mit Absicht,
weil eine verschobene Zuordnung stiller Unsinn wäre —, und die ganze Seite gibt
einen 500er statt einer falschen Zeile.

> **Eine Zusicherung, die im Container hält, ist auf dem Server eine Vermutung —
> bis jemand sie dort misst.**

Neunzehn und nicht sechzehn: Der Agent fragt **alle** Kandidaten und lässt erst
danach die zusammenfallen, die dieselbe Rolle haben. Dass `ssh.service` und
`sshd.service` zwei Blöcke mit **demselben `Id`** ergeben, ist gemessen und
richtig — gezählt werden Blöcke und nicht verschiedene Namen.

---

## 9 · Punkt 3 — erfüllt

Gefahren am 31. August 2026 gegen `0.7.3-rc.3`.

**(a) `--output=json` trägt auf systemd 255.** Die Antwort ist eine Liste mit
`unit` und `next`; `next` sind rohe Mikrosekunden seit 1970. Nachgerechnet:

| | `next` | umgerechnet | `NEXT` aus (b) |
|---|---|---|---|
| `srvpanel-cron.timer` | 1788174020727199 | 2026-08-31 13:00:20 CEST | 13:00:20 CEST ✓ |
| `srvpanel-dns.timer` | 1788174053776401 | 2026-08-31 13:00:53 CEST | 13:00:53 CEST ✓ |

**(c) Auf der Seite:** alle vier **bereit**, keiner `—`, keiner `unbekannt`.

**Verglichen werden konnte nur einer von vieren, und das gehört aufgeschrieben.**
Zwischen Terminal (≈13:00) und Seite (13:01) sind die drei schnellen Timer
**gefeuert** und stehen einen Takt weiter — `cron` 13:00:20 → 13:05:15, `dns`
13:00:53 → 13:16:36, `usage` 13:01:16 → 13:15:15. Die neuen Werte passen auf
Takt und Streuung, belegen aber nicht die Gleichheit, um die es ging.

> **Ein Vergleich zweier Messungen, zwischen denen der Gegenstand weiterläuft,
> belegt nur den Teil, der stillsteht.**

Der Teil, der stillsteht, ist `srvpanel-tls.timer` — täglich, bis zum nächsten
Morgen ruhig —, und er stimmt **auf die Sekunde**: `2026-09-01 00:48:49` hier
wie dort.

**Und die Anzeigezone ist damit nebenbei belegt:** `00:48:49` auf der Seite
gegen `00:48:49 CEST` im Terminal — Europe/Berlin und nicht UTC.

### Eine Beobachtung, die keinen Befund ergibt und trotzdem zählt

Im JSON steht `"next":1788174020727199,"left":1788174020727199` — **`left`
trägt denselben Wert wie `next`**. Und `passed` ist `1188143340330` µs, also
**13,8 Tage**, während die Tabelle daneben „4min 19s ago" druckt.

Beide Felder heissen also nicht, was ihr Name verspricht. Woran es liegt, ist
hier **nicht** gemessen, und das steht als Lücke da und nicht als Erklärung.

Für dieses Panel geht es gut aus: `SystemUnitsList::schedule()` liest
ausschliesslich `unit` und `next`. Das war Vorsicht und kein Wissen.

> **Ein Feld, dessen Name etwas anderes verspricht als sein Wert, ist
> gefährlicher als eines, das fehlt.**

---

## 10 · Punkt 4 — erfüllt. Das Abnahmekriterium von A2 steht.

**(b) Der Zustand ist hergestellt** — `systemctl stop srvpanel-tls.timer`:

    NextElapseUSecRealtime=
    NextElapseUSecMonotonic=infinity
    ActiveState=inactive
    SubState=dead

Genau die vier Werte, die `docs/89 §3` als „kein Termin" gemessen hat.

**Auf der Seite, alle drei Belege:**

1. `srvpanel-tls.timer` trägt die Marke **„kein nächster Termin"** in **Rot** —
   nicht „gestoppt", nicht „nicht installiert".
2. Spalte Nächster Termin: **`—`**, nicht `unbekannt`.
3. Oben in Bernstein: **„1 Timer hat keinen nächsten Termin und meldet trotzdem
   „active"."**

Der dritte ist der eigentliche Beleg: Die Meldung **zählt** den Zustand und
benennt ihn, ohne dass man eine Zahl deutet. Das ist der Wortlaut des
Kriteriums aus `docs/81 §A2`.

**(c) Der Rückweg ist belegt und nicht angenommen:** Nach `systemctl start` steht
der Timer wieder auf **bereit** mit Datum, und die bernsteinfarbene Meldung ist
fort.

> **Eine Anzeige, die einen Zustand meldet, muss ihn auch wieder zurücknehmen —
> sonst hat sie ihn nicht gemessen, sondern behalten.**

### Befund 4 — die Vorschrift zu (c) war falsch

Sie verlangte „derselbe `NEXT`-Wert wie in (a), **oder ein späterer**".
Gemessen: vorher `00:48:49`, nachher **`00:46:26`** — zwei Minuten
dreiundzwanzig **früher**.

`srvpanel-tls.timer` trägt `RandomizedDelaySec=1h`, und die Streuung wird bei
**jeder** Aktivierung neu gezogen. Beide Werte liegen in derselben Stunde nach
`OnCalendar=daily`; ein früherer Termin ist genauso richtig wie ein späterer.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

Zum **dritten** Mal in diesem Lauf, nach „die zwölf eigenen Units `loaded` und
`active`" (§1) und „jede eigene Unit zeigt eine PID" (Punkt 1). Dreimal
derselbe Fehlertyp heisst: Es fehlt kein besseres Auge, sondern ein Handgriff.

> **Wer eine Erwartung an eine Unit aufschreibt, liest vorher ihre Unit-Datei.**
> Alle drei Fehler standen dort: `Type=oneshot` bei vieren,
> `RandomizedDelaySec=1h` bei diesem einen.

---

## 11 · Was noch aussteht

Die Punkte 5 bis 11 aus `docs/90` — Punkt 5 ist über Punkt 3 schon zur Hälfte beantwortet.

**Punkt 4 hängt an Befund 3.** Er trägt das Abnahmekriterium der ganzen Stufe,
und er wird auf einem Telefon gelesen werden — die Reihenfolge ist also: erst
Befund 3, dann Punkt 4, sonst misst der Punkt eine Anzeige, von der schon
feststeht, dass sie ihren eigenen Satz abschneidet.

> **Ein Punkt, den man gegen einen bekannten Fehler misst, misst den Fehler.**
