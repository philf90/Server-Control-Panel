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

## §3 Die Fragen an den Betreiber

Vier, und keine davon lässt sich messen.

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

## §6 Was offen bleibt und benannt ist

- **Auf einem Server ist keine Zahl dieser Runde gemessen.** Die Zeiten stammen
  aus SQLite; `cloudsrv24` fährt MariaDB. Die **Lage** der Bänder ist dagegen
  über M4b an der Serverzahl aus `docs/105` geeicht.
- **Die Lücke aus M7 gehört nicht diesem Merkmal**, sondern
  `OperatorControlTest`. Sie ist hier gefunden worden und bleibt offen, bis
  jemand entscheidet, ob die Regel das Layout mitnimmt.
- **Der Abgleich Ablage ↔ Flagdatei** ist heute nirgends. Ob er gebaut wird,
  hängt an Frage 4.
