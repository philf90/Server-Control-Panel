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

## 11 · Punkt 5 — erfüllt, und die Seite trägt ihre Gegenprobe selbst

| | gemessen |
|---|---|
| Zeitzone unter Einstellungen → Allgemein | **Europe/Berlin** |
| „Gespeichert" | `2026-08-31 11:09:20 UTC` |
| „Angezeigt" | `2026-08-31 13:09:20 CEST (UTC+02:00)` |
| `date -u` / `date` auf dem Server | `11:08 UTC` / `13:08 CEST` |

**Die Gegenprobe, die `docs/90 §6` verlangt, war nicht nötig.** Sie sollte die
Zone kurz umstellen, falls Anzeigezone und UTC zusammenfallen und der Vergleich
deshalb nichts sagt. Hier fallen sie nicht zusammen — und die Einstellungsseite
zeigt **denselben Augenblick zweimal**, genau zwei Stunden auseinander.

> **Eine Anzeige, die ihren Rohwert neben ihr Ergebnis stellt, belegt die
> Umrechnung, ohne dass jemand daran drehen muss.**

Die Termine aus Punkt 3 fügen sich ein: `00:48:49` ist CEST; wäre die Anzeige
UTC, stünde dort der Vortag.

---

## 12 · Punkt 6 — nicht prüfbar, und das ist kein Ausfall

Auf diesem Server zeigt **keine** Zeile „nicht installiert". Der Grund ist
`Catalog::pick()`: Für jede der vier fremden Rollen ist ein Kandidat vorhanden
(`nginx`, `mariadb`, `ssh`, `cron`), der fehlende fällt weg, bevor eine Zeile
entsteht. `crond.service` stand in §1 als `not-found` da und taucht auf der
Seite gar nicht auf.

`docs/90 §7` lässt das ausdrücklich zu. Der Fall ist in `UnitStateTest` mit
gemessenen Prüfkörpern gehalten: `FEHLT` liefert `pid: null`, `restarts: null`,
`description: ''` — und der Wächter hält, dass daraus keine `0` und kein
wiederholter Unitname wird.

> **Ein Zustand, den die Umgebung nicht zulässt, wird nicht dadurch hergestellt,
> dass man nichts tut.**

Herstellbar wäre er nur, indem man einen laufenden Dienst entfernt — ein
Prüfkörper, der teurer ist als die Auskunft, die er brächte.

---

## 13 · Punkt 7 — erfüllt

Auf `/` zeigt der Bereich „Dienste" **vier** Zeilen, alle `active`:

| Unit | Beschreibung |
|---|---|
| `srvpanel-agentd.service` | SrvPanel — privilegierter Agent |
| `nginx.service` | A high performance web server and a reverse proxy server |
| `mariadb.service` | MariaDB 10.11.14 database server |
| `postgresql@16-main.service` | PostgreSQL Cluster 16-main |

Die drei aus `Catalog::essential()` plus der Cluster, den das Kriterium
ausdrücklich zulässt. **Keine** Zeile `mysql.service` — `pick()` fällt auch hier
zusammen. Nicht sechzehn Zeilen. Und der neue Verweis **„Alle Dienste"** steht im
Bereichskopf.

### Befund 5 — dieselbe Tatsache, zwei Vokabulare

**Am Prüfling, gefunden am Bild.** Die Übersicht schreibt **`active`**, die
Dienste-Seite schreibt für dieselbe Unit **„läuft"**:

    Overview.vue:676   {{ service.present ? service.active_state : 'nicht installiert' }}

**Erstens ist `active` englisch.** `docs/19 §4a` bindet Texte der Oberfläche auf
Deutsch. `WordChoiceTest` kann das nicht sehen: Der Wert kommt zur Laufzeit vom
Server und steht als Zeichenkette nirgends in der Vorlage.

> **Ein Wort, das erst zur Laufzeit entsteht, entgeht jedem Wächter, der
> Zeichenketten liest.**

**Zweitens ist es eine zweite Fassung derselben Regel**, und die ärmere:
`dienstRang()` fragt `active_state === 'active'` und kennt weder `activating`
noch die Nachsicht für Dienste, die ein Timer startet. Heute fällt das nicht auf,
weil `essential()` keinen `Type=oneshot` enthält — käme je einer dazu, stünde er
rot mit dem Wort `inactive` da. Das ist genau der Befund aus §3, nur latent.

> **Zwei Fassungen derselben Regel laufen auseinander, und die zweite ist die,
> die veraltet.** Zum dritten Mal in dieser Stufe.

**Vor A2 gab es die zweite Stelle nicht.** Die Übersicht war die einzige Anzeige
eines Unit-Zustands; die Divergenz ist erst durch diese Stufe entstanden.

> **Eine Stufe, die eine zweite Anzeige für dieselbe Sache baut, erzeugt die
> Abweichung, die sie danach halten muss.**

Zu bauen: `rang()` und `zustand()` an eine geteilte Stelle, beide Seiten lesen
sie, und ein Wächter hält, dass keine Vorlage `active_state` roh rendert.

---

## 14 · Punkt 8 — erfüllt

Als Betreiber bei 390 px, Schublade offen und bis ans Ende gerollt. Vier Gruppen
in der Reihenfolge aus `docs/90 §9`:

| Gruppe | gesehen |
|---|---|
| Verwaltung | Kunden · Pläne · Abonnements · Domains · Datenbanken |
| **Betrieb** | Vorgänge · Protokoll · Logs · **Dienste** · Updates · Konten |
| **Einstellungen** | Zugang · Allgemein · PHP-Versionen · Datenbankserver · Mailversand · Zertifikat · DNS-Zugang |
| Konto | Mein Konto |

Alle vier Überschriften lesbar, kein waagerechtes Rollen, „Mein Konto"
erreichbar.

**Der Messwert, nach dem der Punkt ausdrücklich fragt:** Die Schublade braucht
bei 390 px **zwei Bildschirme** — das erste Bild endet bei „Konten", „Mein Konto"
steht auf dem zweiten. Kein Ausfall, aber die Zahl, an der die Frage hängt, ob
als Nächstes die Kundennavigation (neun Punkte unter „Konto") geteilt wird.

### Eine Verwechslung, die keine wurde

Im Fuss der Schublade steht **„Administrator"**. Das liest sich wie ein Befund:
Ein Administrator dürfte die sieben `operate-server`-Punkte unter Einstellungen
nicht sehen, und `AbilityReachTest` verlangt das.

Nachgesehen in `PanelLayout.vue:574` steht dort `{{ account.name }}` — der
**Name** des Kontos und nicht seine Rolle. Das Konto heisst „Administrator" und
ist der Betreiber.

> **Ein Wort, das auch eine Rolle sein könnte, ist noch keine — nachgesehen wird
> im Quelltext und nicht im Kopf.**

Für Punkt 9(a) heisst das: Das zweite Adminkonto aus dem A9-Lauf ist ein
anderes, und die Probe der Rollenteilung ist, dass dort unter Einstellungen nur
**Allgemein** (`manage-settings`) steht.

---

## 15 · Punkt 9 — erfüllt

| | gemessen |
|---|---|
| (a) Administrator („Zweite Verwaltung") | Seite lädt, „Dienste" im Menü |
| (b) Kunde → `/services` | **403** |
| (c) Kunde → `/domains` | **200**, drei Domains, Knopf da |

**Die Gegenprobe hat sich gelohnt:** Dieselbe Sitzung, dasselbe Konto, zwei
verschiedene Ergebnisse. Der 403 kommt von der fehlenden Fähigkeit und nicht von
einer abgelaufenen Anmeldung.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

**(a) zeigt mehr, als das Kriterium abgefragt hat.** Gegen die Fähigkeiten aus
`routes/web.php` gehalten, fehlen dem Administrator **neun** Menüpunkte und
bleibt **einer**:

| | Administrator | Betreiber |
|---|---|---|
| Betrieb | Vorgänge · Protokoll · **Dienste** · Updates | + Logs + Konten |
| Einstellungen | nur **Allgemein** (`manage-settings`) | + sechs `operate-server` |

Keiner davon ist im Code als Sonderfall geschrieben — das ist der Beleg, dass
die Navigation aus der Policy kommt und nicht aus dem Kontotyp (A9 Schritt 5).

Dass der Administrator PID und Neustarts sieht, ist kein Befund:
`ServicesController` hält im Kopf fest, dass hier kein Geheimnis des Betreibers
steht — Unitnamen aus dem Katalog, Beschreibungen von systemd.

**Und die 403-Seite ist die entworfene:** „Kein Zutritt — Dieser Bereich gehört
einer Rolle, die dieses Konto nicht hat", mit dem Weg zurück. `docs/84` hatte
notiert, dass `resources/views/errors/` gar nicht existierte und jeder 403
Laravels englische Vorgabeseite war; A9 hat das zum entworfenen Zustand gemacht,
und hier steht der Beleg auf einem Server.

### Die offene Frage aus Punkt 8 ist damit beantwortet

Die Kundenschublade trägt **neun** Punkte unter „Konto" — Abonnements, Domains,
Datenbanken, Dateien, SFTP-Zugang, Cronjobs, Vorgänge, Protokoll, Mein Konto —
und sie passen bei 390 px auf **einen** Bildschirm, ohne zu rollen.

`docs/90 §9` fragte, ob die Kundennavigation als Nächstes geteilt werden muss.
**Gemessen: nein.** Die zwei Bildschirme sind ein Problem der Betreiberschublade
allein, und dort sind es zwanzig Punkte in vier Gruppen.

> **Eine Frage nach „ist es zu viel geworden" wird an der Ansicht gemessen, die
> es betrifft — und nicht an der, die daneben liegt.**

---

## 16 · Punkt 10 — erfüllt, beide Richtungen

| | Vorgang | Meldung |
|---|---|---|
| mit der Wegwerfquelle | 723, **fertig** | **bernstein** — „Nicht erreicht: http://nicht.erreichbar.invalid/ubuntu/ (Could not resolve …)" |
| nach dem Aufräumen | 724, **fertig** | **grün** — „Alle Quellen erreicht" |

Form B aus `docs/86 §5`, genau wie gebaut: **fertig mit Vorbehalt**, und der
Vorbehalt hat seine eigene Zeile — mit der Quelle *und* dem Grund.

> **Ein Feld im Payload ist noch keine Spalte.**

### Ein Beleg, den kein Kriterium bestellt hat

Nach dem Aufräumen steht in `apt-get update` weiterhin eine `W:`-Zeile:

    W: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/noble/InRelease:
       Signature by key 14AA40EC… uses weak algorithm (rsa1024)

**Und Vorgang 724 meldet trotzdem grün.** Nachgesehen statt vermutet:
`Apt::readFailures()` fängt `/^[WE]: Failed to fetch (\S+)\s*(.*?)\s*$/D` —
also ausdrücklich nur „Failed to fetch" und nicht jede `W:`-Zeile. Die
Sury-Warnung sagt „erreicht, aber schwach signiert" und ist keine
Unerreichbarkeit.

Hätte der Leser bloss `W:` gezählt, stünde die Meldung auf diesem Server **jeden
Tag** auf bernstein.

> **Ein Leser, der eine Warnung zählt statt sie zu lesen, meldet denselben
> Vorbehalt jeden Tag — und danach sieht ihn niemand mehr an.**

Im Container war das nicht messbar; dort gibt es die Sury-Warnung nicht.

**Als Beobachtung, nicht als Befund:** Dass ein Depot mit rsa1024 signiert, sieht
heute niemand im Panel. `system.packages.refresh` beantwortet „habe ich alle
Quellen erreicht" und nicht „sind sie sicher"; beides in einen Vorbehalt zu
werfen wäre schlechter. Die Frage gehört zu A3 in P9b.

---

## 17 · Punkt 11 — gefahren, **nicht** erfüllt

Sieben Pakete standen an, der Punkt war also fahrbar.

| | Vorgang | Zustand | Meldung |
|---|---|---|---|
| erster Lauf | 725 | **fertig** | grün — „7 von 7 Aktualisierungen eingespielt, 0 bleiben offen." |
| zweiter Lauf | 726 | **fehlgeschlagen** | rot — „Der Lauf hat nichts verändert — offene Aktualisierungen vorher wie nachher: 0." |

Der erste Lauf ist Form A aus `docs/86 §5` in Ordnung: Der absetzende Vorgang
trägt seinen Ausgang nach.

**Der zweite verletzt das Kriterium.** Es lautete: „Der zweite Lauf meldet, dass
er nichts verändert hat, und **nicht** einen Fehlschlag." Gemessen meldet er
**beides** — der Satz stimmt, der Zustand nicht. Der Fortschritt blieb bei 50 %
stehen, weil der Vorgang in den Fehlerzweig ging.

### Befund 6 — „nichts zu tun" und „nicht geschafft" heissen gleich

Die Ursache steht an zwei Stellen, und beide sind bewusst geschrieben.

`packaging/bin/apt-run:206`:

    if [ "$vorher" = "$nachher" ]; then
        echo "$NAME: Der Lauf hat nichts verändert — $einheit vorher wie nachher: $nachher."
        exit 3
    fi

`agent/src/Outcome.php:70`:

    public const BAD = [
        'apt-get endete mit ',
        'Der Lauf hat nichts verändert',
    ];

Der Kommentar über der Zeile in `apt-run` nennt **drei** Ursachen — veraltete
Paketlisten (M5), eine abgeschaltete Quelle, ein bereits aktuelles Paket. Alle
drei sind echte Fehlschläge, und dafür gibt es das Skript.

**Der vierte Fall fehlt in der Aufzählung:** `vorher = nachher = 0`. Da war
nichts einzuspielen.

Und er ist von den drei anderen **an der Zahl unterscheidbar — sie steht in der
Meldung**:

| | Urteil |
|---|---|
| `vorher 7, nachher 7` | Fehlschlag — sollte sieben einspielen, hat null geschafft |
| `vorher 0, nachher 0` | kein Fehlschlag — es stand nichts an |

`Outcome::BAD` liest aber nur den **Anfang** des Satzes.

> **Ein Urteil, das seine Zahl mitbringt und nur an seinem Anfang gelesen wird,
> wirft die Unterscheidung weg, die es trägt.**

**Eine Ebene tiefer ist es die Spiegelung von M5**, dem Befund, mit dem P7b
angefangen hat:

> **Ein Rückgabewert, der „nichts zu tun" und „nicht geschafft" gleich benennt,
> ist derselbe Fehler wie einer, der einen Fehlschlag nicht tragen kann — nur in
> die andere Richtung.** Bei M5 gab `apt-get update` eine `0` für einen Lauf, der
> jede Quelle verfehlt hatte; hier gibt `apt-run` eine `3` für einen Lauf, dem
> nichts zu tun blieb.

**Warum es zählt:** Ein Server mit eingeschalteter Automatik hat den Fall
regelmässig. Nach der dritten Woche mit roten Vorgängen, die nichts bedeuten,
sieht niemand mehr einen roten Vorgang an.

**Wohin die Behebung gehört:** in `apt-run` und nicht in den Leser. Das Skript
weiss, ob `vorher` null war, und `exit 3` ist schon dort falsch — wer es von Hand
fährt, bekommt heute Rückgabewert 3 für einen Lauf ohne Anlass. Das berührt die
Paketierung und braucht eine neue Fassung. Der Fassungsmodus (`--fassung`) bleibt
unberührt: Dort ist `nachher` eine Versionsnummer und nie `0`.

---

## 18 · Bilanz — A2 ist abgenommen

`docs/90 §14`: **Abgenommen ist A2, wenn Punkt 4 erfüllt ist.** Er ist es, und
die beiden Ausschlusskriterien stehen grün.

| Punkt | | |
|---|---|---|
| 1 | die Seite, sechzehn Zeilen | ✓ |
| 2 | ein Aufruf, neunzehn Blöcke | ✓ **Ausschlusskriterium** |
| 3 | die vier Termine | ✓ |
| **4** | **ein Timer ohne Termin ist erkennbar** | ✓ **das Kriterium der Stufe** |
| 5 | Anzeigezone statt UTC | ✓ |
| 6 | eine Unit, die es nicht gibt | — nicht herstellbar, `docs/90 §14` lässt es zu |
| 7 | die Übersicht unverändert | ✓ **Ausschlusskriterium** |
| 8 | die neue Navigation | ✓ |
| 9 | Administrator ja, Kunde nein | ✓ |
| 10 | die Behebungen aus `docs/86 §5` | ✓ |
| 11 | derselbe Lauf zweimal | **✗ Befund 6** |

**Sechs Befunde, und fünf davon stecken im Prüfling.** Das ist die Umkehrung von
`docs/45`, `docs/48`, `docs/59` und `docs/84`, wo die Mehrheit im Prüfmittel lag.

| | |
|---|---|
| 1 · der gesunde Server meldete vier Schäden | Prüfling · **behoben** |
| 2 · „Alle Dienste laufen" | Prüfling · **behoben** |
| 3 · der Zustand bei 390 px angeschnitten | Prüfling · **behoben** |
| 4 · die Vorschrift zu Punkt 4 (c) | Prüfmittel · berichtigt |
| 5 · die Übersicht sagt „active" | Prüfling · **offen** |
| 6 · „nichts zu tun" meldet fehlgeschlagen | Prüfling · **offen** |

Dazu zwei berichtigte Erwartungen, die der Prüfling nicht erfüllen konnte (§1
„loaded **und** active", Punkt 1 „jede Unit zeigt eine PID") — mit Befund 4 sind
das **drei desselben Musters**, und alle drei standen in der Unit-Datei.

> **Wer eine Erwartung an eine Unit aufschreibt, liest vorher ihre Unit-Datei.**

**Warum diesmal mehr im Prüfling steckt als im Prüfmittel:** Die Vorschrift war
vor dem Lauf ausgeschrieben und das Messmittel lag als geprüftes Werkzeug im
Repo. Was blieb, war eine **neue Seite** — und die hatte ihre Fehler dort, wo
kein Wächter hinsah: in einer Bauart, die im Prüfstand nicht vorkam
(`Type=oneshot`), und in einer Tabelle ohne Form.

---

## 19 · Was noch aussteht

**Befund 5** (die Übersicht sagt „active") und **Befund 6** („nichts zu tun"
meldet fehlgeschlagen). Beide sind am Prüfling, beide brauchen eine neue
Fassung — Befund 6 auch ein neues Paket, weil er `packaging/bin/apt-run`
berührt.

Und der Rest aus `docs/88`, den Punkt 11 nun eingelöst hat: Er hat auf
Paketbestand gewartet, ihn bekommen und einen Befund geliefert. Genau dafür war
er da.

**Punkt 4 hängt an Befund 3.** Er trägt das Abnahmekriterium der ganzen Stufe,
und er wird auf einem Telefon gelesen werden — die Reihenfolge ist also: erst
Befund 3, dann Punkt 4, sonst misst der Punkt eine Anzeige, von der schon
feststeht, dass sie ihren eigenen Satz abschneidet.

> **Ein Punkt, den man gegen einen bekannten Fehler misst, misst den Fehler.**

---

## 20 · Befund 5 und 6 sind behoben — und ein siebter fiel dabei heraus

Gebaut am **31. August 2026**, ohne Server; was hier steht, ist im Container
gemessen und gehört in einen Nachlauf auf `cloudsrv24`.

### 20.1 Befund 5 — zwei Seiten, ein Server, zwei Auskünfte

Die Übersicht druckte `service.active_state`, also wörtlich **`active`**. Die
Dienste-Seite sagte für denselben Zustand **`läuft`**, für einen wartenden
oneshot-Dienst **`wartet auf seinen Timer`** und für einen Timer ohne Termin
**`kein nächster Termin`**.

> **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte Auskunft,
> sondern eine widersprüchliche.**

`WordChoiceTest` konnte es nicht sehen, und das ist der Grund für den neuen
Wächter: Das englische Wort steht **nirgends im Quelltext**. Es entsteht zur
Laufzeit aus einem Feld, das der Agent liefert.

**Behoben ist es nicht durch eine zweite Übersetzung, sondern durch eine
gemeinsame Stelle.** `resources/js/Composables/useUnitState.ts` trägt `rang()`
und `zustand()`; beide Seiten rufen sie. Die Übersicht hatte vorher ein eigenes
`dienstRang()`, das die Nachsicht für oneshot-Dienste nicht kannte — sie hätte
denselben Befund 1 noch einmal bekommen, sobald jemand ihn dort bemerkt hätte.

**Und der Umzug hat einen Wächter rot gemacht, der nichts Kaputtes fand.** Drei
Fälle von `ServicesViewTest` zeigten auf `Pages/Services/Index.vue`, wo die
Regeln nicht mehr stehen.

> **Ein Wächter, der beim Aufräumen zubeisst, wird beim Aufräumen
> abgeschaltet.**

Die Antwort war nicht, ihn abzuschwächen, sondern ihn dorthin zu zeigen, wo die
Regel jetzt steht. Was die **Seite** entscheidet — zwei Bereiche, der schweigende
Agent, das Datum vom Server, die Meldung über `rang` — bleibt an der Seite.

**Dabei ist ein zweiter Wächter stumpf geworden, und das hat kein Test
gemeldet.** `test_the_colour_of_a_timer_follows_its_next_date` prüft, dass der
Termin **vor** `active_state` gefragt wird. Gemessen wurde über die ganze Datei
— und seit dem Umzug steht die Bedingung im Helfer `ohneTermin()`, der **oben**
definiert ist. Sie war damit immer zuerst da, auch bei verkehrter Reihenfolge.

> **Ein Wächter über eine Reihenfolge wird stumpf, sobald einer der beiden
> Ausdrücke in einen Helfer zieht, der weiter oben steht.**

Gemeldet hat es der Bruchlauf und nicht der Wächter: Sein Eingriff fand seinen
Text nicht mehr. Gemessen wird jetzt im Rumpf von `rang()`.

**Dazu ein neuer Wächter für die Regel selbst**
(`ServicesViewTest::test_no_page_prints_a_raw_unit_state`): Keine der 70 `.vue`
druckt `active_state`, `sub_state` oder `load_state` in einer Ausgabe. Die
Untergrenze steht daneben — beide Seiten, die Units zeigen, müssen `zustand(`
rufen.

### 20.2 Befund 6 — „nichts zu tun" und „nicht geschafft"

`apt-run` schrieb für beide Fälle denselben Satz und endete mit `3`:

    apt-run: Der Lauf hat nichts verändert — offene Aktualisierungen vorher wie nachher: 0.

Auf `cloudsrv24` gemessen (§17): Der zweite Druck auf denselben Knopf meldete
`fehlgeschlagen`, mit der `0` im eigenen Satz.

> **Ein Urteil, das seine Zahl mitbringt und nur an seinem Anfang gelesen wird,
> wirft die Unterscheidung weg, die es trägt.**

**Behoben im Skript und nicht im Leser**, und der Leser brauchte dafür keine
Zeile: Sein Kopf sah den Fall vorher — ein Urteil, das nicht in `Outcome::BAD`
steht, ist ein Erfolg mit einer Meldung.

> **Eine Voreinstellung, die zur sicheren Seite fällt, trägt den Fall, den
> niemand vorhergesehen hat — und den, den jemand vorhergesehen und nicht
> gebaut hat, ebenso.**

**Die Nachsicht ist ausdrücklich auf den Zählmodus beschränkt.** Bei `--fassung`
ist `nachher` eine Versionsnummer und nie `0`; dort bleibt „vorher wie nachher"
ein Fehlschlag, denn eine Fassung, die sich nicht ändert, war der Grund des
Laufs. Beide Hälften haben ihren Eingriff im Bruchskript.

**Und der Prüfkörper von `OutcomeTest` hielt den Fehler fest, statt ihn zu
melden.** Er stand dort wörtlich als `…vorher wie nachher: 0.` — und die
Behauptung daneben erklärte ihn für einen Fehlschlag.

> **Ein Prüfkörper, der den Fehler enthält, hält ihn fest statt ihn zu melden —
> wenn die Behauptung daneben ihn für richtig erklärt.**

### 20.3 Befund 7 — 59 px, gefunden von der Bilderrunde zu A und B

Die Vorgangsseite hat mit `docs/92` A und B eine Zeile für ihren Gegenstand
bekommen. Der erste Wurf schrieb

    <td class="right"><a class="link ident">…</a></td>

und **schob das Dokument bei 390 px um 59 px aus dem Bild**. Die Zelle daneben —
`<td class="right ident name">` — brach im selben Tabellenkörper richtig um.

Der Grund steht in `app.css` zweimal ausgeschrieben: `table.pairs td.right.ident`
löst die Zelle aus ihrem `flex: none` und erlaubt den Umbruch. Eine Kennung, die
nur *in* der Zelle steht, erreicht diese Ausnahme nicht, und
`td .ident { white-space: nowrap }` gewinnt.

> **Eine Ausnahme, die für die Zelle geschrieben ist, gilt nicht für das, was in
> ihr steht — und beide sehen im Markup gleich aus.**

Das ist die **vierte** Wiederholung desselben Fehlers an derselben Tabelle.
Behoben ist er nicht durch eine fünfte Regel in `app.css`, sondern durch die
Klasse an der Zelle: Der Verweis erbt die Schrift über `.link { font: inherit }`.

**`MobileTableTest::test_an_identifier_in_a_pairs_cell_belongs_to_the_cell`
hält die Regel — und hat beim ersten Lauf sofort eine zweite Stelle gefunden**,
die es seit P6 gibt: `Subscriptions/CronRuns.vue`. Dort zeigt dieselbe Zelle
einmal einen gesprochenen Satz und einmal den Cron-Ausdruck; ein festes `ident`
machte aus dem Satz Monoschrift. Sie trägt die Klasse jetzt an einer Bedingung,
als Objektschlüssel, damit `ClassReachTest` sie sieht.

### 20.4 Die Messwerte

Gemessen im Container mit echtem Markup und **beiden** gebauten Stylesheets,
Chromium, je Lage mit Gegenprobe.

| Lage | vor der Behebung | nach der Behebung | Gegenprobe |
|---|---|---|---|
| hell 390 | **59 px** | 0 px | 200/200 |
| hell 1440 | 0 px | 0 px | 200/200 |
| dunkel 390 | **59 px** | 0 px | 200/200 |
| dunkel 1440 | 0 px | 0 px | 200/200 |

**Die erste Fassung dieser Messung war keine.** Der Aufsatz lag als
`public/brotkruemel.html` und lud die Stylesheets über `/build/assets/…`; unter
`file://` zeigt der führende Schrägstrich auf die Wurzel des Dateisystems. Die
Seite war **ungestaltet**, meldete 66 px, und die Gegenprobe daneben schlug aus.

> **Eine Messung, bei der der Prüfling gar nicht geladen wurde, sieht aus wie
> ein Ergebnis — die Gegenprobe belegt nur, dass die Messung rechnet, nicht dass
> sie ihren Gegenstand hat.**

Gemerkt hat es nicht das Bild und nicht die Zahl, sondern die Frage nach der
**berechneten** Eigenschaft: `overflow-wrap` stand auf `normal`, wo `app.css`
`anywhere` schreibt. Seitdem liest der Aufsatz die Stylesheets relativ, und der
Prüfkörper ist danach aus `public/` weggeräumt.

**Und das Bild hat gesagt, was die Zahl nicht konnte.** Bei 0 px Überlauf nahm
der Brotkrümel trotzdem **drei Zeilen**, weil er
`/updates?nur=sicherheit&herkunft=…&name=…` vollständig zeigte.

> **Eine Beschriftung, die den ganzen Zustand nennt, sagt nicht mehr, wo man war
> — sie sagt nur, dass es kompliziert war.**

Gezeigt wird jetzt der Pfad ohne seine Frage; der **Verweis** behält sie, damit
der Filter beim Zurückgehen wiederkommt. Beide Richtungen haben ihren Eingriff.

### 20.5 Was das für den nächsten Nachlauf heisst

Keine dieser Behebungen hat einen Server gesehen. Zu messen bleibt:

1. **Befund 5** — die Übersicht zeigt für dieselbe Unit denselben Satz wie die
   Dienste-Seite, und ein wartender oneshot-Dienst ist auf **beiden** grün.
2. **Befund 6** — derselbe Knopf zweimal: der zweite Lauf meldet `fertig` mit
   dem Vorbehalt „Es stand nichts an", und die transiente Unit steht nicht auf
   `failed`. Dazu die Gegenprobe an `--fassung`.
3. **Befund 7** — die Vorgangsseite eines Vorgangs mit langem Gegenstand bei
   390 px, gemessen an der echten Seite und nicht am Aufsatz.
4. **A und B** — der Weg zurück führt dorthin, wo man war, und der Gegenstand
   ist von der Vorgangsseite aus erreichbar. Ein Vorgang der
   Zertifikatsautomatik trägt **keine** Herkunft.
