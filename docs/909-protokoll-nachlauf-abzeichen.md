# Protokoll — Nachlauf zum Abzeichen, `0.7.4-rc.4` auf `cloudsrv24`

Der Lauf ist `docs/908`, der Plan des Merkmals `docs/907`. Gefahren am
**11. September 2026** ab 19:27 CEST auf `cloudsrv24`, `srvpanel version` →
`0.7.4-rc.4`.

**Stand: alle acht Punkte erfüllt**, darunter alle drei, die nicht ausfallen
durften (2, 3 und 8). Kein Befund am Prüfling; die Bilanz steht in §9.


---

## §1 Punkt 1 — der Timer hat einen Termin · **erfüllt**

| gemessen | Wert |
|---|---|
| `NEXT` | `Fri 2026-09-11 20:04:44 CEST` |
| `LEFT` | `37min` |
| `NextElapseUSecRealtime` | `Fri 2026-09-11 20:04:44 CEST` |
| `Persistent` | `yes` |
| `Triggers` | `srvpanel-packages.service` |

`NEXT` ist ein Datum und nicht `-`; der Termin zeigt auf die richtige Unit.

**Die Streuung ist an ihrer Wirkung belegt und nicht an ihrer Angabe.**
`OnCalendar=hourly` allein ergäbe **20:00:00**. Gemessen steht dort
**20:04:44**, also 4 min 44 s später — innerhalb des Fensters von
`RandomizedDelaySec=300`. Das ist der bessere Beleg, denn er misst, was der
Timer **tut**, und nicht, was in seiner Datei steht.

> **Eine Angabe, die man zurückliest, ist eine Abschrift der Datei. Ihre
> Wirkung ist die Messung.**

---

## §2 Punkt 2 — der Dienst läuft und schreibt · **erfüllt** *(Ausschlusskriterium)*

| Schritt | gemessen |
|---|---|
| Ausgangszustand zerstört | `zahl=999  zeit='2026-09-11 17:27:30'` |
| `Result` | `success` |
| `ExecMainStatus` | `0` |
| `ExecMainStartTimestamp` | `Fri 2026-09-11 19:27:30 CEST` |
| Journal | `32 aktualisierbare Pakete festgehalten.` (19:27:33) |
| Ablage danach | `zahl=32  zeit='2026-09-11 17:27:33'` |

Die erfundene 999 ist fort, der Zeitpunkt ist gewandert. **Damit ist die Frage
beantwortet, die dieser Punkt nebenbei trug:** Die Unit schreibt unter
`ProtectSystem=strict` mit `ReadWritePaths=/var/lib/srvpanel /var/log/srvpanel`
in die Datenbank. Dass drei Nachbarunits dasselbe können, war ein Indiz; jetzt
ist es gemessen.

**`ActiveState=inactive` ist hier richtig und kein Befund.** `Type=oneshot`
ohne `RemainAfterExit` — die Unit tut ihre Arbeit und geht. Das Urteil steht in
`Result`.

**Und die Ablage steht in UTC, die Anzeige in CEST.** `zeit=17:27:33` gegen
`19:27:33` im Journal sind derselbe Augenblick. So ist es gebaut:
`savePendingUpdates()` legt `now()->toDateTimeString()` ab, `Clock` rechnet
beim Anzeigen um. Wer das für eine Abweichung hält, meldet einen Befund an
etwas, das zu Recht so ist.

---

## §3 Punkt 3 — die Zahl ist die des echten apt · **erfüllt** *(Ausschlusskriterium)*

| Frage | Antwort |
|---|---|
| `apt-get -s dist-upgrade \| grep -c '^Inst '` | **32** |
| Ablage `pendingUpdates()` | **32** |
| `apt-get -s upgrade \| grep -c '^Inst '` | 13 *(nur zur Ansicht)* |

### §3a Die Falle aus `docs/908 §0` Nummer 3 war keine Vermutung

**32 gegen 13.** Auf dieser Maschine gehen die beiden apt-Fragen um
**19 Pakete** auseinander. Wäre die Gegenprobe gegen `apt-get -s upgrade`
geschrieben worden — und das war der erste Entwurf —, hätte dieser Lauf eine
Abweichung von 19 gemeldet und den Prüfling für etwas gerügt, das er zu Recht
tut: `Packages::read()` baut `upgradable` allein aus dem `dist-upgrade`-Lauf.

> **Ein Kriterium, das man am falschen Paket misst, meldet den Prüfling für
> etwas, das er zu Recht tut.**

Gefunden hat es das Nachlesen am Quelltext vor dem Fahren. Hier ist es
**gemessen**, und die Zahl sagt, was es gekostet hätte.

### §3b Die Übereinstimmung ist kein Zufall — sie ist ursächlich

Der freiwillige Griff aus `docs/908 §4` ist gefahren worden. Prüflingspaket:
`motd-news-config`.

| Zustand | apt | Ablage |
|---|---|---|
| Ausgang | 32 | 32 |
| nach `apt-mark hold` + Lauf | **31** | **31** |
| nach `apt-mark unhold` + Lauf | **32** | **32** |

Die Wahrheit wurde verschoben, und die Ablage ist mitgegangen — hinunter und
wieder herauf. Ohne diesen Griff bliebe „beide Zahlen sind 32" eine
Übereinstimmung, die auch Zufall sein könnte.

> **Zwei Zahlen, die gleich sind, sagen nichts darüber, ob die eine der anderen
> folgt.**

`motd-news-config` hat keine Abhängigen; die Zahl fiel deshalb um genau eins.
Der Server steht wieder wie vorher.

---

## §4 Punkte 4 bis 6 — was man sieht · **alle drei erfüllt**

Gemessen im Browser gegen die laufende Instanz, mit der **lebenden**
Inertia-Ablage (`__vue_app__…$page`) und nicht mit dem Script-Element des
Ladevorgangs. Jede Messung druckt `seite` und `breite` mit.

| | Punkt 4 | Punkt 5 | Punkt 6 |
|---|---|---|---|
| `seite` / `breite` | `/` · 1440 | `/` · 390, zugeklappt | `/updates` · 1440 |
| `ablage` | 32 | 32 | 32 |
| Abzeichen | `"32"`, x=174, **imBild: true** | `"32"`, **x = −62**, imBild: false | `"32"`, imBild: true |
| Punkt | 0×0, imBild: false | **6×6, x=42, y=18, imBild: true** | 0×0 |
| `knopfName` | `Navigation, 32 Aktualisierungen stehen an` | dito | dito |
| Satz | `null` | `null` | **steht** |

**Punkt 4.** Das Abzeichen trägt die Zahl aus der Ablage, und die Ablage trägt
die Zahl von apt. Damit ist die Kette geschlossen: Bis §2 war belegt, dass der
Dienst *schreibt*; jetzt ist belegt, dass die Navigation *liest*, was er
geschrieben hat.

**Punkt 5 bestätigt den Befund, für den es den Punkt am Menüknopf gibt.** Bei
390 px und zugeklappter Schublade steht das Abzeichen bei **x = −62** —
ausserhalb des Bildes, wie gemeldet. Der Punkt steht bei x=42, y=18 und ist
sichtbar, ohne dass jemand das Menü öffnet.

**Und die Containermessung war auf ein Pixel genau.** `docs/907` hat im
Nachbau **−63** gemessen, der echte Server sagt **−62**.

> **Ein Aufsatz, der das echte Markup und das gebaute Stylesheet benutzt, misst
> die echte Seite — und nicht etwas Ähnliches.**

**Punkt 6 misst nebenbei die Umrechnung.** Der Satz lautet „Am Menüpunkt
„Updates" steht die Zahl vom **2026-09-11 19:36:24**." In der Ablage steht
derselbe Augenblick als **17:36:24** UTC. `Clock::displayText()` rechnet also
auf dem Server um, und nicht nur im Test.

Dass der Zeitpunkt nicht mehr der aus §2 ist (19:27:33), ist der Entwurf und
kein Befund: Jeder Aufruf von `/updates` zahlt den Agentenaufruf ohnehin und
hält das Ergebnis fest. Der Wert ist beim Öffnen der Seite entstanden.

**Der `knopfName` trägt die Einzahlregel mit.** Gemessen ist hier nur die
Mehrzahl (32); der Einzahlfall steht als Wächter in `NavDotTest` und ist auf
dem Server nicht ausgelöst worden.

> **Ein Mechanismus, der an einer Stelle belegt ist, trägt die anderen Stellen
> — ihre Texte trägt er nicht.**

---

## §5 Punkt 7 — die Bestandsdiagnose · **erfüllt**

| Zustand | Lauf | `unit.*`-Befunde |
|---|---|---|
| ohne Eingriff | 7 Prüfungen, `Auffällig: 2` | **keine** |
| Timer angehalten | 7 Prüfungen, `Auffällig: 2`, **`Kaputt: 1`** | `unit.schedule  srvpanel-packages.timer  no_next` |
| wieder eingeschaltet | 7 Prüfungen, `Auffällig: 2` | **keine** |

Die Gegenprobe nennt die Unit beim Namen und verschwindet wieder. Damit ist
belegt, dass die Diagnose sie **ansieht** und nicht bloss schweigt.

> **Eine Abwesenheit ist nur dann ein Befund, wenn die Anwesenheit im
> Erfolgsfall belegt ist.**

`Auffällig: 2` steht in allen drei Zuständen — zwei Befunde, die mit dieser
Stufe nichts zu tun haben. Gerade weil die Zahl gleich bleibt, bedeutet die
`Kaputt: 1` daneben etwas: Sie ist die Wirkung des Eingriffs und nicht das
Rauschen des Bestands.

---

## §6 Zwei Beobachtungen, beide am Prüfmittel und keine am Prüfling

### §6a `RandomizedDelaySec` hat nichts gedruckt

`systemctl show … -p Triggers -p Persistent -p RandomizedDelaySec -p
NextElapseUSecRealtime` gab **drei** Zeilen und nicht vier. `Persistent` und
`Triggers` standen da, `RandomizedDelaySec` nicht.

**Nachgemessen über die volle Liste, und die Vermutung stimmt:**

```
RandomizedDelayUSec=5min
FixedRandomDelay=no
```

Es ist eine Verwechslung von **Direktive** und **Eigenschaft**: In der
Unit-Datei heisst es `RandomizedDelaySec=300`, über den Bus heisst dieselbe
Grösse `RandomizedDelayUSec`. `systemctl show` fragt den Bus. Ein Name, den es
dort nicht gibt, erzeugt **keine Fehlermeldung, sondern keine Zeile** — und
eine fehlende Zeile liest sich wie „nicht gesetzt".

Der Wert deckt sich mit der Datei (300 s = 5 min) und mit der Wirkung aus §1
(4 min 44 s).

**Und `FixedRandomDelay=no` ist im selben Lauf an seiner Wirkung belegt
worden.** Das Anhalten und Wiedereinschalten des Timers in §5 hat den Versatz
**neu gewürfelt**: `NEXT` stand vorher auf `20:04:44` und danach auf
`20:02:35`. Die Angabe und ihre Wirkung sagen dasselbe.

> **Ein Versatz, der bei jedem Termin neu gewürfelt wird, ist keine Grösse, die
> man einmal abliest und danach nachrechnet.** Ein Lauf, der 20:04:44
> weitergeschrieben hätte, hätte zwei Minuten zu spät nachgesehen.

> **Ein Eigenschaftsname, den es nicht gibt, druckt nichts — und nichts sieht
> aus wie „nicht gesetzt".** Dieselbe Familie wie `systemctl is-active` für
> eine Unit, die es nicht gibt.

Gefunden hat es nicht das Raten eines zweiten Namens, sondern die Frage an die
**volle** Liste: `systemctl show <unit> | grep -i random`. Wer einen zweiten
Namen probiert hätte, hätte bei einem dritten Fehlversuch wieder nichts
gewusst.

### §6b `LAST` war abgeschnitten — und der Timer hat schon einmal gefeuert

`list-timers` zeigte `LAST  Fri 2026-09-11 19:…`; der Rest lag hinter dem
Bildrand. Geschlossen wurde daraus nichts; geholt wurde der Wert dort, wo er
ungekürzt steht.

> **Eine abgeschnittene Liste sieht aus wie eine vollständige — sie sagt nicht,
> wo sie aufhört.**

**Gemessen:** `LastTriggerUSec=Fri 2026-09-11 19:09:04 CEST`. Daneben im
Journal: `Finished srvpanel-packages.service` um **19:09:07** — drei Sekunden
Laufzeit, dieselbe Dauer wie der Lauf von Hand (19:27:30 → 19:27:33).

**Dieser Lauf war keiner von Hand.** Die Messung von Punkt 1 lief um 19:27,
also **vor** dem ersten `systemctl start` dieses Laufs. Damit ist der Weg
Timer → Dienst → Ablage auf dieser Maschine belegt.

**Die stündliche Folge war es trotzdem nicht.** Ein Kalendertermin um 19:00
läge mit `RandomizedDelayUSec=5min` spätestens bei 19:05:00, und 19:09:04 liegt
danach. Ausgelöst hat also einer der beiden anderen Sockel beim Einschalten
durch `enable --now` im postinstall-Skript — der Nachholer von
`Persistent=true` oder das längst verstrichene `OnBootSec=10min`. **Welcher
von beiden, ist nicht gemessen**, und für diesen Punkt trägt es nichts: Beide
sagen „der Timer wurde eingeschaltet", keiner sagt „die Stunde ist
vergangen".

**Das Anhalten und Wiedereinschalten in §5 hat die Erklärung eingegrenzt.**
`LAST` stand danach unverändert auf `19:09:04` — der Neustart des Timers hat
**keinen** Lauf ausgelöst. Das passt zum Nachholer von `Persistent=true`: Beim
ersten Einschalten gab es keine Marke, also war der Kalendertermin von 19:00
offen und wurde sofort nachgeholt; danach steht die Marke auf 19:09:04, und es
gibt nichts mehr nachzuholen. Zu einem verstrichenen `OnBootSec=10min` passt es
schlechter — der Rechner läuft seit sechs Tagen, und dann hätte auch der
Neustart des Timers feuern müssen.

**Das ist eine Eingrenzung und keine Messung.** Sie stützt sich auf eine
einzige Beobachtung, und ein Lauf, der sie als Befund führte, schriebe eine
Vermutung als Ergebnis. Gemessen wäre sie an der Marke selbst:
`ls -l --time-style=full-iso /var/lib/systemd/timers/stamp-srvpanel-packages.timer`.
Für Punkt 8 trägt sie nichts — beide Erklärungen sagen „der Timer wurde
eingeschaltet", keine sagt „die Stunde ist vergangen".

Punkt 8 fragt nach der Folge und bleibt offen.

> **Ein Beleg für den Weg ist keiner für das Ziel.**


---

## §7 Punkt 8 — der Zustand unmittelbar davor

Gemessen um **19:55:02 CEST**, also sieben Minuten vor dem Termin.

| | gemessen |
|---|---|
| `NextElapseUSecRealtime` | `Fri 2026-09-11 20:02:35 CEST` |
| `LastTriggerUSec` | `Fri 2026-09-11 19:09:04 CEST` |
| `ExecMainStartTimestamp` | `Fri 2026-09-11 19:29:08 CEST` |
| `checked_at` (UTC) | `2026-09-11 17:49:21` |

### §7a Zwei Marker waren veraltet, und einer hätte den Punkt gefälscht

Dieses Protokoll hat die Marker für Punkt 8 **aufgeschrieben statt gemessen**:
`ExecMainStartTimestamp = 19:27:30` und `checked_at = 17:36:24`. Beide stimmten
zum Zeitpunkt des Aufschreibens und waren es sieben Minuten später nicht mehr.

**Der erste ist der gefährliche.** `19:27:30` war der erste Lauf von Hand — der
freiwillige Griff aus §3b hat danach **zwei weitere** abgesetzt, der letzte um
`19:29:08`. Wäre Punkt 8 ohne frische Vormessung gefahren worden, stünde
nachher `19:29:08` gegen einen Marker von `19:27:30`, und das läse sich als
„gewandert" — **ein Beleg für das Feuern des Timers, der in Wahrheit ein Lauf
von Hand war.**

> **Ein Marker, den man aufschreibt statt ihn zu messen, altert zwischen dem
> Aufschreiben und dem Messen — und ein Marker, der zu früh steht, macht aus
> einem fremden Lauf einen Beleg.**

Gefangen hat es der Vorher-Block, und er hat nichts gekostet als zwei Sekunden.

### §7b Wer die Zahl um 19:49:21 geschrieben hat, ist abgeleitet und nicht geraten

`checked_at` stand auf `17:49:21` UTC, also `19:49:21` CEST — nach der
Browsermessung aus §4 (`19:36:24`) und vor der Diagnose aus §5 (`19:49:44`).

Ausgezählt hat die Ablage genau **zwei** Schreiber: `UpdatesController` beim
Holen des Paketstands und `CollectPendingUpdates` im Dienst. Die Diagnose
gehört nicht dazu. Und der Dienst kann es nicht gewesen sein — sein
`ExecMainStartTimestamp` steht auf `19:29:08`. **Es bleibt der Controller**,
also ein weiterer Aufruf von `/updates`.

Das ist der Entwurf und kein Befund: Wer die Seite öffnet, bezahlt den
Agentenaufruf ohnehin, und das Ergebnis wird festgehalten.

---

## §8 Punkt 8 — der Timer hat gefeuert · **erfüllt** *(Ausschlusskriterium)*

Gemessen um **20:04:20 CEST**, eine Minute und 45 Sekunden nach dem Termin.

| | vorher | nachher |
|---|---|---|
| `LastTriggerUSec` | `19:09:04` | **`20:02:35 CEST`** |
| `ExecMainStartTimestamp` | `19:29:08` | **`20:02:35 CEST`** |
| `checked_at` (UTC) | `17:49:21` | **`18:02:38`** = `20:02:38 CEST` |
| `Result` / `ExecMainStatus` | — | `success` / `0` |
| `NextElapseUSecRealtime` | `20:02:35` | `21:02:37 CEST` |

Das Journal daneben:

```
20:02:35  Starting srvpanel-packages.service …
20:02:38  php[458138]: 32 aktualisierbare Pakete festgehalten.
20:02:38  Deactivated successfully.
20:02:38  Finished srvpanel-packages.service …
```

**Alle drei Marker sind gewandert, und sie zeigen auf denselben Vorgang.**
Der Timer hat um `20:02:35` ausgelöst — auf die Sekunde der Termin, der in §7
vorhergesagt stand —, der Dienst ist in derselben Sekunde angelaufen, und die
Ablage trägt `20:02:38`, also genau den Augenblick der Zeile im Journal.

**Der dritte Marker ist der tragende.** Die ersten beiden belegen, dass der
Timer einen Dienst gestartet hat. Erst `checked_at` verbindet ihn mit der
Ablage, aus der das Abzeichen liest — und damit ist die Kette geschlossen:
**Timer → Dienst → Ablage → Navigation**, jedes Glied einzeln gemessen.

> **Ein Beleg für den Weg ist keiner für das Ziel.**

Und `NEXT` steht wieder auf einem Datum, nicht auf `-`: **`21:02:37`**, mit
neu gewürfeltem Versatz.

**Die drei gemessenen Versätze der stündlichen Folge** waren 4 min 44 s,
2 min 35 s und 2 min 37 s. Dass die letzten beiden zwei Sekunden auseinander
liegen, ist bei drei Ziehungen aus einem Fenster von fünf Minuten ein Zufall
und kein Muster — drei Werte können weder das eine noch das andere belegen,
und mehr als „es wird gewürfelt" sagt dieser Lauf dazu nicht.

---

## §9 Bilanz

**Alle acht Punkte erfüllt**, **alle drei Ausschlusskriterien** (2, 3 und 8)
darunter, keiner als „nicht herstellbar" ausgefallen. Die beiden Dinge, die der
PR zum Abzeichen ausdrücklich als ungemessen benannt hatte, sind es nicht mehr:

1. **Der Timer feuert stündlich** — belegt in §1 (Termin), §8 (das Feuern
   selbst) und durch den neuen Termin danach.
2. **Die Zahl kommt aus einem echten `system.packages.list`** — belegt in §3,
   und in §3b sogar ursächlich: Mit einem gehaltenen Paket geht sie mit hinunter
   und wieder herauf.

**Befunde am Prüfling: keiner.** Was der Lauf gefunden hat, steckte durchweg im
Prüfmittel oder in der Vorschrift — dieselbe Lage wie in P7, bei A10 und beim
Platzhalter, und aus demselben Grund: Die Vorschrift war vor dem Fahren
ausgeschrieben, die Messmittel lagen als geprüfte Werkzeuge im Repo.

> **Ein Abnahmelauf ohne Fund am Prüfling sagt nicht, dass keiner da war — er
> sagt, wo sie gefunden wurden.**

### §9a Die drei, die etwas gekostet hätten

| | wo | was es gekostet hätte |
|---|---|---|
| `apt-get -s upgrade` statt `dist-upgrade` | §0 Nr. 3, gemessen in §3a | Eine gemeldete Abweichung von **19 Paketen** am Prüfling, der zu Recht so rechnet |
| Der Marker aus dem Gedächtnis | §7a | Ein Lauf von Hand (`19:29:08`) wäre als Feuern des Timers durchgegangen — **ein falsches Grün am Ausschlusskriterium** |
| „Der Timer feuert stündlich" als **ein** Punkt | §0 Nr. 1 | Entweder unfahrbar oder beim Abhaken weicher gelesen als geschrieben |

Zwei davon hat das Nachlesen am Quelltext **vor** dem Fahren gefunden, einen
der Vorher-Block **während** des Fahrens. Keinen das Nachdenken.

### §9b Was benannt offen bleibt

- **Ob der Timer einen Neustart übersteht.** `OnBootSec=10min` und
  `Persistent=true` sind gelesen und nicht gefahren (`docs/908 §9`).
- **Ob die Folge über viele Stunden trägt.** Ein Feuern belegt den Mechanismus,
  `NEXT` den Plan; über den zwölften Lauf sagt keiner von beiden etwas.
- **Der Einzahlfall der Knopfbeschriftung** ist auf dem Server nicht ausgelöst
  worden (§4); er steht als Wächter in `NavDotTest`.
- **Welcher Sockel den Lauf um 19:09:04 ausgelöst hat** (§6b) — eingegrenzt,
  nicht gemessen, und für keinen Punkt tragend.
- Der Rest aus P7 (`orphan.row` für `tls.cloudlab24.de`), der zu dieser Stufe
  nicht gehört.
