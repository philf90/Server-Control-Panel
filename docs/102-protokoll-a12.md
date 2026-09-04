# Protokoll — der Wartungsmodus (A12) auf `cloudsrv24`

Gefahren ab dem **4. September 2026** gegen `0.7.3-rc.16`, Domain
`cloudlab24.ipv64.de`. Der Plan ist `docs/101`, die Abnahmepunkte stehen dort in
§7. Dieses Protokoll wird während des Laufs geschrieben und nicht danach.

**Stand: Punkt 1 gemessen, Punkte 2 bis 8 offen.** Zwei Befunde, beide im
Prüfling, beide vor dem ersten Schalten gefunden.

---

## 1 · Der Zustand ohne Wartung — Punkt 1, erfüllt

Gemessen über `--resolve` auf `127.0.0.1`, also am Server-Block selbst und nicht
über den Weg durchs Internet und zurück. Host-Kopfzeile und SNI bleiben dabei
`cloudlab24.ipv64.de`, nginx wählt denselben Block wie für einen Besucher.

| Griff | gemessen | erwartet |
| --- | --- | --- |
| `http /` | `301` | `301` |
| `https /` | `200` | `200` |
| `https /.well-known/acme-challenge/…` | `404` | `404` |
| `/var/spool/srvpanel/wartung` | fehlt | fehlt |

Die Wache steht damit in jedem Block und **schweigt**, solange die Datei fehlt —
das ist die Hälfte, die man leicht für selbstverständlich hält und die
`SiteTemplate` seit `0.7.3-rc.16` in vier Formen schreibt.

### Was die erste Fassung dieser Messung gekostet hat

Sie hatte **keine Frist**: `curl` ohne `--connect-timeout` und ohne
`--max-time`, dazu die öffentliche Adresse statt `127.0.0.1`. Der Block hing,
und jedes `^C` beendete nur den gerade laufenden Aufruf — die übrigen
eingefügten Zeilen liefen danach los und hingen ihrerseits.

> **Ein Prüfkörper ohne Frist ist keiner — er misst, bis jemand ihn abbricht.**

> **Eine Messung über die öffentliche Adresse misst den Weg dorthin mit — und
> wenn der Server ihn nicht hat, misst sie gar nichts.**

---

## 2 · Befund 1 — das Feld war auf dem Telefon nicht ausfüllbar

**Gemeldet vom Betreiber**, beim ersten Versuch, den Modus einzuschalten. Kein
Test hat es gefunden, und keiner hätte es können.

„Voraussichtlich bis" war **ein** Textfeld für die Form `Y-m-d H:i`, mit
`inputmode="numeric"`. Die Zifferntastatur von iOS gibt weder Bindestrich noch
Doppelpunkt noch Leerzeichen her. Auf dem Bildschirmfoto steht `20260904`, und
weiter kam er nicht — das Feld war dort nicht umständlich, sondern **gar nicht**
zu füllen.

> **Ein Format, das kein Eingabetyp hergibt, ist auf dem Telefon nicht tippbar —
> und `inputmode` gibt weniger Zeichen her, als das Format verlangt.**

**Auf `/audit` stand das richtige Paar seit P2 da:** `date_format:Y-m-d` und
daneben ein `type="date"`. Die Vermeidung war nur nie die Regel geworden.

> **Ein Fehler, den man an einer Stelle vermieden hat, ist an der nächsten wieder
> da, wenn die Vermeidung nicht die Regel wurde.**

Gebaut sind seitdem **zwei** Felder, `type="date"` und `type="time"`, mit je
einer eigenen Regel (`date_format:Y-m-d` und `date_format:H:i`) und
`required_with` in beide Richtungen. Zusammengesetzt wird in der Steuerung — in
der Seite wäre es eine zweite Fassung des Formats.

**Ein dritter Typ kommt dafür nicht in Frage, und das ist keine Meinung:**
`datetime-local` trägt zwischen Datum und Uhrzeit ein `T` und nicht das
Leerzeichen, das `Y-m-d H:i` verlangt.

Der Wächter ist `DateInputTest`: Jede Regel `date_format:X` in `app/Http` hat ein
`<input>`, dessen `type` das Format hergibt — und ein zusammengesetztes Format
meldet er mit dem Grund, statt es zu dulden.

---

## 3 · Befund 2 — die Endzeit kam beim Agenten gar nicht an

Beim Bau von Befund 1 gefunden, nicht durch Nachdenken, sondern beim Lesen der
Naht. **Zwei Fehler an einer Zeile, und keiner davon von der Wartungsseite aus
zu sehen.**

`Clock::minuteToUtc()` legt `Y-m-d H:i:s` ab — mit Sekunden, denn ein abgelegter
Zeitpunkt ist ein Zeitpunkt. `Maintenance::UNTIL` verlangt `Y-m-d H:i` — ohne,
denn es ist ein Satz auf einer Seite. Hinaus ging der **abgelegte** Wert.

**Sobald eine Endzeit gesetzt war, wies der Agent damit jedes `web.site.apply`
ab** — jede Domain, jedes Mal, mit „Die voraussichtliche Endzeit muss die Form
JJJJ-MM-TT HH:MM haben."

Und wäre er durchgekommen, stünde auf der Wartungsseite die UTC-Zeit unter dem
Wort „Uhr": zwei Stunden vor der, die der Betreiber eingetippt hat.

> **Ein Wert, der abgelegt ist, wird zu einer Auskunft erst durch die Umrechnung
> — und die Stelle, an der niemand von uns hinsieht, ist die, an der sie
> fehlt.**

### Warum nichts das gemeldet hat

`MaintenanceGuardTest` füttert `Maintenance::until()` mit einem selbst
geschriebenen `'2026-09-04 16:00'`; die Prüfungen um `MaintenanceMode` sprechen
mit einem Doppel. **Beide Seiten waren geprüft; geprüft war nie, dass die eine
der anderen etwas gibt, das sie annimmt.**

> **Zwei Prüfungen, die je eine Seite einer Naht mit einem selbst geschriebenen
> Wert füttern, prüfen die Naht nicht — sie prüfen zweimal denselben
> Prüfkörper.**

Behoben mit **einer** Zeile: `Clock::minute()` in `WebLifecycle::payload()`.
Sie liefert Ortszeit **in** der Form, die der Agent annimmt — beide Fehler
zugleich. Der Wächter ist `MaintenanceSeamTest`, gemessen an der Wirkung: Der
Wert geht durch `Site::fromArgs()`, also durch die Tür, an der er auf dem Server
ankommt, und die Gegenrichtung belegt, dass der abgelegte Wert dort **nicht**
durchkommt.

**Die Gegenprobe war beim ersten Lauf keine.** Sie war grün, weil `fromArgs`
zwei Zeilen früher an einem fehlenden `system_user` flog — an einer anderen
Hürde als der gemeinten. Sie nennt die Meldung seitdem beim Namen.

---

## 3b · Die Zone steht jetzt neben der Zeit

**Entschieden vom Betreiber am 4. September 2026**, nachdem die Frage aus §3
gestellt war: Der Satz auf der Wartungsseite nennt die Zone.

> Diese Website ist wegen Wartungsarbeiten vorübergehend nicht erreichbar.
> Voraussichtlich ab **2026-09-04 16:00 Uhr CEST (UTC+02:00)** wieder erreichbar.

**Der Grund ist nicht nur die Höflichkeit gegenüber dem Besucher.** Die
Wartungsseite ist die einzige Fläche dieses Panels, die jemand liest, der die
Anzeigezeitzone des Betreibers nicht kennt — und sie macht den Satz haltbar:

> **Eine Zeitangabe mit ihrer Zone bleibt wahr, auch wenn die Zone sich seither
> geändert hat — eine ohne wird still falsch.**

Ändert der Betreiber später seine Anzeigezone, nennt ein Block, der noch nicht
neu geschrieben ist, weiterhin **denselben Augenblick**, nur in der alten Zone.
Ohne die Angabe wäre er still falsch geworden.

### Zwei Dinge, die dabei gemessen wurden

**Die Zone gehört zum Zeitpunkt und nicht zu „jetzt".** Berlin heisst im Juli
`CEST (UTC+02:00)` und im Januar `CET (UTC+01:00)`. `Clock::label()` beschriftet
den Augenblick des Schreibens; eine Endzeit im Winter, im Sommer gesetzt, bekäme
damit die Abkürzung des Sommers. Dafür gibt es jetzt `Clock::labelAt()`, und
beide Wege gehen durch **eine** Formatierung.

> **Eine Zonenangabe, die für „jetzt" gilt, gehört nicht neben einen Zeitpunkt,
> der woanders liegt.**

**Die Form der Abkürzung ist gemessen und nicht abgelesen.** Über alle
Zeitzonen von PHP, in beiden Hälften des Jahres, hat sie **drei** Formen:
Buchstaben (`GMT`, `CEST`, `EEST` — höchstens fünf), einen kurzen Versatz
(`+03`, `-11`) und einen langen (`+0530`). Ein Ausdruck, der nur `CEST
(UTC+02:00)` kennt, wiese Colombo und Kathmandu ab — und der Betreiber läse eine
Meldung über einen Programmierfehler, wo er nur eine Zeitzone eingestellt hat.

> **Eine Form, die man an der eigenen Zone abliest, ist eine von dreien.**

Geprüft wird sie im Agenten gegen `Maintenance::ZONE`, aus demselben Grund wie
die Endzeit: Der Wert landet als Text *in* einer nginx-Zeichenkette.

### Und der Rundlauf ist jetzt gehalten

`MaintenanceRoundTripTest` misst, was der Betreiber verlangt hat: Was eingetippt
wird, kommt in der eingestellten Zone zurück. Gemessen mit einem **Versatz** und
mit einer zweiten Zone daneben — dieselbe Ablage liest sich in `America/New_York`
als `10:00 · EDT (UTC-04:00)`.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.** Mit `UTC` als Prüfzone sähe eine fehlende Umrechnung wie eine
> gelungene aus.

**Was er nicht kann:** den vollen Weg durch `POST /maintenance`. Der geht über
den Agenten, und den gibt es im Prüfstand nicht — er steht als Punkt 2 in §5.

---

## 4 · Die Bilderrunde zu den zwei Feldern

Vier Lagen, `dokument: 0 px` in allen vieren, Gegenprobe `200/200` in allen
vieren.

**Und eine Falle des Messmittels, die eine Aufnahme gekostet hat.** Chromium
zeigt in einem `type="date"` die Schreibweise **seiner Oberflächensprache**, und
die ist hier englisch: Das erste Bild las `mm/dd/yyyy` und `--:-- --` mit
AM/PM. `locale: 'de-DE'` am Kontext ändert daran nichts — es setzt
`Accept-Language` und `navigator.language`. Erst `--lang=de-DE` beim Start des
Browsers zeigt, was ein deutsches Gerät zeigt: **`tt.mm.jjjj`** und ein
24-Stunden-Feld.

> **Ein Bild, das in der Sprache des Prüfstands aufgenommen wurde, sagt über die
> Anzeige auf dem Gerät des Lesers nichts.**

---

## 5 · Was offen ist

Die Punkte 2 bis 8 aus `docs/101 §7`, darunter beide Ausschlusskriterien
(3 und 4). Zu fahren sind sie in dieser Reihenfolge, jeder Griff mit Frist:

1. einschalten über die Seite, danach `https /` → `503`, ACME → `404`,
   Flagdatei vorhanden
2. `Retry-After: 3600` und die Wartungsseite mit der Zeitangabe — **das ist
   zugleich die Gegenprobe zu Befund 2 auf einem echten Server**
3. ausschalten, dieselben Werte wie in §1, `nginx -t` mit `rc=0` und **ohne
   Reload**
4. die zwei Prüfungen der Bestandsdiagnose (`docs/101 §5`) — noch nicht gebaut
