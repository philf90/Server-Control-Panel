# Der Wartungsmodus als Band

Geschrieben am **11. September 2026, nach der Messrunde**. Der Anlass ist ein
Wunsch des Betreibers, gestellt neben der Frage nach weiteren Abzeichen: „Als
Möglichkeit fällt mir der Wartungsmodus ein, wenn dieser eingeschaltet ist."

**Als Band und nicht als Abzeichen, und das ist eine Entscheidung über die
Form.** Ein Abzeichen sagt „hier steht eine Zahl an"; der Wartungsmodus sagt
„jede Kundenwebsite antwortet gerade mit 503". Das ist kein Zähler, sondern ein
Zustand des ganzen Servers — und die Form dafür gibt es seit A14.

---

## §1 Der Bestand, gelesen und nicht geraten

| | |
|---|---|
| Schalten | `MaintenanceMode::set()` → `web.maintenance.set` → Flagdatei |
| Die Wahrheit | `/var/spool/srvpanel/wartung` (`Maintenance::FLAG`) |
| Was das Panel zeigt | `Settings::maintenance()` — eine **Ablage** |
| Die Seite | `/maintenance`, GET und POST, beide `can:operate-server` |
| Die Bandform | `.bands` im `PanelLayout`, seit A14 mit zwei Geschwistern |

`MaintenanceMode::set()` liest dabei **was der Agent gemessen hat** und nicht,
was gefragt war (`$ist = ($result['enabled'] ?? null) === true`) — der Zustand
folgt dem Agenten und nicht dem Klick. Für die **Anzeige** gilt das nicht, und
genau daran hängt M8.

---

## §2 Die Messrunde

Gefahren am 11. September 2026 im Container, gegen die lokale Datenbank und das
gebaute Stylesheet. Jede Zeitmessung **warm** und über 50 Runden.

### M1 — Das Band sähe der Kunde *(entscheidet den Zuschnitt)*

`PanelLayout.vue` ist die Hülle von **54 Seiten** — Kundenseiten eingeschlossen;
das Impersonationsband steht genau dort. Ein Band ohne Zuschnitt erreicht damit
jeden Angemeldeten, auch den Kunden, dessen Website gerade 503 gibt und der
weder schalten noch etwas beeinflussen kann.

Gemessen ist, dass ein Zuschnitt möglich wäre: `abilities` wird für **jedes**
angemeldete Konto geteilt (`[]` für keines), ein `v-if` darauf ist also für
einen Kunden zuverlässig `false`.

**Die Frage selbst ist keine Messung** und steht in §3.

### M2 — Was der Wert je Anfrage kostet

| | |
|---|---|
| `Settings::maintenance()` **kalt** | 10,575 ms |
| `Settings::maintenance()` **warm** | **0,122 ms** |
| `Settings::pendingUpdates()` warm, als Massstab | 0,113 ms |
| Abfragen je Aufruf | 2 |

Die beiden nehmen sich nichts. Der kalte Wert ist die bekannte Falle:

> **Eine Messung, die man nur einmal fährt, misst den Zwischenspeicher mit — und
> ob sie ihn kalt oder warm erwischt, sagt sie nicht.**

**Und eine Zahl von gestern ist damit berichtigt:** `docs/910 §2` M8 hat für
`pendingUpdates()` 0,211 ms gemessen, heute sind es 0,113. Dieselbe Methode,
dieselbe Datenbank, anderer Lauf — was trägt, ist das **Verhältnis** der beiden
und nicht der absolute Wert.

### M3 — Verschluss und nicht fertiger Wert

**Nicht neu gemessen**, sondern aus `docs/103` übernommen (voll 2 Abfragen,
partiell 1): Ein fertiger Wert in `share()` läuft auch bei einem partiellen
Nachladen, das ihn gar nicht mitschickt. Die Regel ist hier bloss anzuwenden.

### M4 — Vier Bänder stapeln, und was sie kosten

Gemessen im Nachbau mit **beiden** Stylesheets und dem `data-v`-Attribut des
Übersetzers, vier Lagen, Ladebeleg `flex/8px band=3px`, `dokument = 0`,
Gegenprobe **200**:

| Bänder | Hülle bei 390 px | Hülle bei 1440 px |
|---|---|---|
| 1 | 155 px | 72 px |
| 2 | 225 px | 121 px |
| 3 | 295 px | 170 px |
| 4 | **365 px** | **219 px** |

**Je Band 70 px bei 390 px und 49 px bei 1440 px** — 62 beziehungsweise 41 px
für das Band und 8 px Fuge. Die Oberkanten wachsen streng
(`0,151,221,291` und `12,68,117,166`): Sie **stapeln**.

**Damit ist die Falle aus `docs/103` geschlossen und das ist gemessen statt
angenommen.** Dort nahm `.band` sein `grid-row: 1` ausdrücklich, und drei Bänder
lagen bei 1440 px übereinander, bei `schiebt = 0`. Seit dem 5. September ist
`.bands` selbst der Stapel (`display: flex; flex-direction: column; gap: 8px`)
und nimmt die Rasterzeile **einmal für die Gruppe**.

### M4b — Der Zwilling stimmt dort überein, wo beide messen

Drei Ankündigungsbänder ohne das Impersonationsband, bei 390 px: **214 px** —
dieselbe Zahl, die `docs/105` auf `cloudsrv24` gemessen hat.

> **Ein Zwilling, dem man dort glaubt, wo er allein misst, muss dort
> übereinstimmen, wo beide messen.**

Das erste Band des Stapels ist dabei mit **143 px** mehr als doppelt so hoch wie
die anderen: Es ist das Impersonationsband, sein Knopf bricht bei 390 px um.

### M5 — Kein neuer Rang und keine neue Farbe

`app.css` führt drei: `warn`, `critical`, `info`, jeder aus **Fläche, Rand und
Textfarbe**. Gemessen sind die Flächen des Bandes `rgba(132, 83, 6, 0.11)` im
hellen und `rgba(226, 169, 74, 0.14)` im dunklen Thema.

Die zwölf Kontrastwerte stehen in `docs/103` (5,40:1 bis 9,21:1) und sind hier
**nicht** neu gemessen. Was hier dazukommt, ist eine Beobachtung: `warn` trägt
heute schon das Impersonationsband. Ein Wartungsband auf demselben Rang wäre die
dritte Bedeutung dort — unterschieden allein durch das Rangwort.

### M6 — Der Satz passt in die Klammer

Ein Satz von rund 150 Zeichen ergibt bei 390 px **62 px**, also dieselbe
Zweizeilen-Klammer wie jede Ankündigung. Die Grenze ist damit keine über
Zeichen:

> **Eine Klammer über Zeilen ist auf jeder Breite dieselbe Regel. Eine über
> Zeichen ist es nicht.**

### M7 — Ein Verweis im Band wäre ungehalten *(Befund)*

`OperatorControlTest` sammelt über **zwei** Iteratoren, und die tragende Regel
nimmt den schmaleren:

| Fall | liest |
|---|---|
| `test_every_ability_key_a_page_reads_exists` | `resources/js` — **alles** |
| `test_a_control_for_a_stricter_route_sits_behind_its_ability` | `resources/js/Pages` |

`PanelLayout.vue` liegt unter `resources/js/Layouts`. **Die Regel, dass ein
Bedienelement für eine strengere Route hinter seiner Fähigkeit steht, erreicht
das Layout also nicht** — und das Layout ist die Hülle aller 54 Seiten.

Gegengeprüft, ob die Lücke heute schon eine ist: Es gibt im Layout **keinen
einzigen** wörtlichen `href="/…"`; die Navigation filtert über `darf()`. Die
Lücke ist damit offen und noch nicht betreten — sie entstünde mit dem ersten
Verweis im Band.

> **Ein Wächter, der die geschriebenen Seiten prüft, sagt nichts über die
> Datei, die niemand in diesen Ordner gelegt hat.**

### M8 — Die Ablage ist nicht die Wahrheit, und niemand merkt es *(die teuerste)*

`agent/src/Ops/` führt genau **eine** Wartungsoperation: `web.maintenance.set`,
und sie verlangt `enabled` als Pflichtfeld. `is_file($this->flag)` steht darin
**nach** dem Schalten. Das Panel kann den Zustand also nicht erfragen, ohne ihn
zu setzen.

Und nichts gleicht die beiden ab, ausgezählt über alle sieben Prüfungen der
Bestandsdiagnose:

- `MaintenanceWindow` liest `Settings::maintenance()` und meldet **nur** eine
  überschrittene Endzeit.
- `Verdict::guard()` prüft die Wache in den Vhost-Dateien — die steht seit A12
  **dauerhaft** dort, gleich ob der Modus an ist.

> **Ein Zustand, den man nur durch Setzen erfahren kann, ist von aussen nicht
> lesbar — und die Anzeige daneben liest zwangsläufig eine Ablage.**

Verschwindet die Flagdatei von Hand, behauptet das Panel weiter „Wartung läuft",
und umgekehrt. Das ist heute schon so und auf `/maintenance` verborgen; ein Band
auf **jeder** Seite macht aus einer stillen Ungenauigkeit eine laute.

### M9 — Was der Besucher liest

`Maintenance::page()`, feste Form und kein Freitext:

> Diese Website ist wegen Wartungsarbeiten vorübergehend nicht erreichbar.
> Voraussichtlich ab **&lt;Zeit&gt; Uhr &lt;Zone&gt;** wieder erreichbar.

**Der Besucher liest „ab", das Formular sagt „bis".** Beides meint denselben
Wert und beschreibt ihn von zwei Seiten. Der Satz im Band darf davon keine
dritte Fassung bauen.

---

## §3 Die vier Entscheidungen

Vier Fragen, keine davon messbar — **alle am 12. September entschieden**, die
vierte vom Betreiber, die drei anderen aus dem Zweck und mit seiner Zustimmung.

| | entschieden |
|---|---|
| 1. Wer es sieht | **`operate-server`** — wer schalten darf |
| 2. Rang | **`warn`**, nicht `critical` |
| 3. Verweis auf `/maintenance` | **ja** |
| 4. Ablage oder lesende Operation | **beides**, siehe unten |

**Zu 4, und das ist der Zuschnitt:** `web.maintenance.state` wird gebaut, aber
nicht für die Anzeige. Das Band steht auf jeder Seite und liest weiter die
Ablage — ein Sockelaufruf je Seitenaufbau wäre der Fehler, den `docs/904` für
`/updates` gerade behoben hat. Die Operation gibt es, damit die
**Bestandsdiagnose** einmal pro Nacht Ablage und Datei aneinanderhalten kann.

> **Eine Anzeige, die teurer wird, je öfter man sie ansieht, wird genau dann
> langsam, wenn jemand arbeitet.**

Damit wird aus einer stillen Ungenauigkeit ein Befund — und der erscheint über
das Abzeichen am Menüpunkt „Diagnose", das am Tag davor entstanden ist.

Die Erwägungen, die zu den vier Antworten geführt haben:

**1. Wer sieht das Band?** Drei Zuschnitte:

| | sieht es |
|---|---|
| a | nur wer schalten darf (`operate-server`) |
| b | jeder Admin — der Administrator sieht zu, der Betreiber dreht |
| c | jeder Angemeldete, Kunden eingeschlossen |

Für **a** spricht, dass die Seite selbst so zugeschnitten ist („die Seite *ist*
der Schalter"). Für **c** spricht, dass der Kunde am ehesten merkt, dass seine
Website nicht antwortet — und dann wenigstens weiss, warum. Dagegen spricht,
dass er nichts tun kann und die Auskunft ihn bei jedem Klick begleitet.

**2. Welcher Rang?** `warn` teilt sich das Band dann mit der Impersonation;
`critical` steht sonst für Störungen, und eine geplante Wartung ist keine.

**3. Führt das Band nach `/maintenance`?** Ein Verweis macht es zum
Bedienelement — mit der Lücke aus M7 im Rücken. Ohne Verweis ist es eine
Auskunft und sonst nichts.

**4. Ablage oder eine lesende Operation?** Entweder das Band liest
`Settings::maintenance()` und die Grenze aus M8 wird benannt, oder es entsteht
`web.maintenance.state` — eine Operation, die die Datei liest und nichts
schaltet, und dann kann auch die Bestandsdiagnose den Abgleich melden.

---

## §4 Was gemessen feststeht

1. **Keine neue Komponente und keine neue Farbe.** `.bands` trägt heute zwei
   Geschwister; das Wartungsband ist ein drittes, in der Form des
   Impersonationsbandes.
2. **Der Wert reist als Verschluss** (M3).
3. **Der Platz ist da.** Vier Bänder kosten bei 390 px 365 px Hülle; die
   Rechnung ist 70 px je Band und gemessen (M4).
4. **Der Satz trägt die Endzeit mit ihrer Zone** — über `Clock` und mit
   `labelAt()` und nicht `label()`: Berlin heisst im Januar anders als im Juli.
   Und er nimmt den Wortlaut des Besuchers auf (M9) statt einen dritten.

---

## §5 Was dieses Merkmal ausdrücklich nicht wird

Damit die Aufzählung eine Entscheidung ist und keine Lücke mit Überschrift
(`docs/105`):

- **Kein Schalten aus dem Band heraus.** Der Schalter bleibt `/maintenance`.
- **Keine Automatik und kein Zeitgeber.** Gestrichen in `docs/101 §2`, und die
  Begründung gilt unverändert.
- **Kein Wegklicken.** Ein Zustand, der den ganzen Server betrifft, ist nicht
  die Sache des Betrachters.
- **Keine Änderung an A12.** Die Wache, die Flagdatei und die Vhost-Dateien
  bleiben, wie sie abgenommen sind.

---

## §6 Gebaut am 12. September 2026 — und was dabei anders war als im Plan

Sechs Teile: die lesende Operation, das `seit`, der Abgleich als Prüfung, der
geteilte Wert, das Band, und die Lücke aus M7.

### a) Der Kommentar, der die Bestandsdiagnose beschrieb, war eine Vermutung

`AgentOperationReachTest` trug bei `web.maintenance.set` den Satz *„Dass die
beiden auseinanderlaufen können, meldet die Bestandsdiagnose — das ist der
Grund, aus dem sie danach fragt."* Nachgezählt las `Maintenance::FLAG` genau
**zwei** Stellen: die schaltende Operation und die Vorlage des Server-Blocks.
`SystemDiagnose` kam darin nicht vor.

> **Ein Satz, den ein Kommentar behauptet und den niemand gemessen hat, ist
> eine Vermutung mit Fussnote — und er hält länger als der Handgriff, weil ihn
> der Nächste liest und glaubt.**

Seit diesem Bau stimmt er. Das ist keine Behebung eines Fehlers, sondern das
Nachliefern einer Zusage, die jemand schon gegeben hatte.

### b) Der geteilte Wert heisst nicht `maintenance` *(Befund eines Wächters)*

`SharedPropTest` wurde rot: `MaintenanceController` gibt seiner Seite eine
Eigenschaft dieses Namens, und Seitenwerte überschreiben geteilte. Auf
`/maintenance` — der **einzigen** Seite, auf der man den Modus ausschaltet —
wäre das Band damit fort gewesen.

> **Ein geteilter Schlüssel, den eine Seite auch benutzt, ist auf genau dieser
> Seite fort — und der Ausfall liest sich wie ein Rechteproblem.**

Derselbe Fehler wie `can` gegen `abilities` (`docs/82`) und `errors`
(`docs/904`), zum dritten Mal — und zum ersten Mal von einem Wächter gefangen,
bevor ihn jemand gesehen hat. Er heisst jetzt `maintenanceBand`.

### c) M7 ist geschlossen, und darunter lag ein zweiter Befund

`OperatorControlTest` liest jetzt **alle** `.vue` statt nur `resources/js/Pages`
und kennt eine zweite Art Wächter: ein `computed`, das eine geteilte
Eigenschaft liest, welche die Mittelschicht an eine Fähigkeit bindet. Die
Zuordnung Eigenschaft → Fähigkeit kommt aus `HandleInertiaRequests` und nicht
aus einer Liste im Test.

**Beim Erweitern fiel auf, dass der Wächter etwas Falsches gemessen hat.** Sein
Ausdruck über `computed(…)` endete auf `\n)`, und ein einzeiliges
`const fehler = computed(() => …)` hat kein solches Ende: Der Treffer lief bis
zur nächsten mehrzeiligen Klammer und schrieb `operate-server` der Variablen
`fehler` zu — dem Leser der Fehlermeldung.

> **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat nicht wenig
> gemessen — er hat an dieser Stelle etwas Falsches gemessen.**

Die gefährliche Richtung ist dabei nicht das falsche Rot, an dem es auffiel,
sondern das falsche Grün daneben: Ein Bedienelement in einem `v-if` auf die
unbeteiligte Variable käme durch. Gezählt werden seitdem die Klammern.

**Und was er trotzdem nicht kann:** Nimmt man dem Verschluss seine
Fähigkeitsprüfung, findet er für die Datei keine Wächtervariable mehr und
überspringt sie — zu Recht, denn wo keine Variable Betrachter unterscheidet,
gibt es für ihn nichts zu verstecken. Der Eingriff blieb grün.

> **Ein Wächter, der beim Fehlen seiner Voraussetzung überspringt, meldet das
> Fehlen der Voraussetzung nicht.**

Diese Naht hält deshalb `MaintenanceBandTest` und nicht er.

### d) Die Zweizeilen-Klammer schnitt die Auskunft weg *(Befund aus dem Bild)*

Gemessen war alles grün: `dokument = 0`, Gegenprobe 200, 62 px — genau die Höhe
aus M4. Und auf dem Bild stand bei 390 px *„Wartung Alle Kundenwebsites
antworten mit 503. Seit 2026-09-11 18:35 Uhr (UTC…"* — abgeschnitten vor der
überschrittenen Endzeit, und mitten in einer Zeitangabe, der damit ihre Zone
fehlt.

> **Eine Klammer über zwei Zeilen schneidet das Ende ab — und das Ende war hier
> das, was den Streifen rechtfertigt.**

> **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite schiebt.
> Keines von beiden ersetzt das andere.**

Für eine Ankündigung ist die Klammer richtig: Deren Text schreibt der Betreiber,
er hat keine Obergrenze, und der volle Wortlaut steht auf `/announcements`.
Dieser Satz ist unserer und besteht aus zwei Zeitangaben und einer Zone. Das
Band trägt sie deshalb **nicht**, und die überschrittene Endzeit steht **vorn**
— sie ist der Grund, aus dem jemand hinsieht.

### e) Die Messung an der echten Seite

Gefahren gegen `artisan serve`, vier Lagen für den überfälligen Fall und drei
Zustände bei 390 px. `dokument = 0` und Gegenprobe **200** überall, Ladebeleg
`rand=3px`, Thema an der Grundfarbe abgelesen (`rgb(255,255,255)` gegen
`rgb(15,17,22)`).

| Zustand (390 px) | Band | Satz |
|---|---|---|
| an, ohne Endzeit | 83 px | „… mit 503 — seit … Uhr (UTC)." |
| an, Endzeit offen | 104 px | „… mit 503 — seit … Uhr (UTC), voraussichtlich bis … Uhr (UTC)." |
| an, überschritten | 104 px | „Die angekündigte Endzeit ist seit … Uhr (UTC) vorbei. …" |
| **aus** | **keins** | — |

Bei 1440 px ist es in allen Fällen **41 px**, also eine Zeile.

**Der ausgeschaltete Zustand ist der Prüfkörper**, ohne den die anderen drei
nichts sagen: Ein Band, das immer dasteht, belegt nicht, dass es einen Zustand
zeigt.

**Und der Zwilling stimmt zum zweiten Mal:** M4 hat im Nachbau 62 px je Band bei
390 px gemessen; die echte Seite gab für dieselbe Form dieselben 62 px, bevor
die Klammer fiel.

**Was diese Messung nicht sagt:** Die Zone steht hier auf `UTC`, weil die
Anzeigezone dieses Containers UTC ist. Auf `cloudsrv24` steht dort `CEST` — der
Weg ist gemessen, dieser Wortlaut nicht.

---

## §7 Was offen bleibt und benannt ist

- **Auf einem Server ist keine Zahl dieser Runde gemessen.** Die Zeiten stammen
  aus SQLite; `cloudsrv24` fährt MariaDB. Die **Lage** der Bänder ist dagegen
  über M4b an der Serverzahl aus `docs/105` geeicht.
- **Nichts davon hat einen Server gesehen.** Die Zahlen stammen aus dem
  Container; `cloudsrv24` fährt MariaDB und eine andere Anzeigezone. Der
  Abnahmelauf steht noch aus und gehört vor das Fahren ausgeschrieben.
- **Der Abgleich läuft einmal pro Nacht und nicht laufend.** Zwischen zwei
  Nachtläufen kann das Band einen Zustand behaupten, den die Datei nicht mehr
  trägt. Das ist der Preis dafür, dass die Anzeige keinen Sockelaufruf je
  Seitenaufbau kostet — und er ist benannt und nicht verschwiegen.
- **Wer die Datei angelegt oder entfernt hat, sagt niemand.** Der Agent kennt
  ihr Dasein; ein Ort im Dateisystem trägt keine Herkunft.
- **Die Dauer steht als Zeitpunkt da und nicht als Spanne.** „seit
  2026-09-11 18:35 Uhr" verlangt vom Leser eine Rechnung; „seit dreizehn
  Stunden" nicht. Eine Spanne altert allerdings in einer offenen Seite, und ob
  das den Tausch wert ist, ist nicht gemessen.
