# Protokoll — Nachlauf zum Abzeichen, `0.7.4-rc.4` auf `cloudsrv24`

Der Lauf ist `docs/908`, der Plan des Merkmals `docs/907`. Gefahren am
**11. September 2026** ab 19:27 CEST auf `cloudsrv24`, `srvpanel version` →
`0.7.4-rc.4`.

**Stand: Punkte 1, 2 und 3 erfüllt, darunter beide, die nicht ausfallen
durften** (2 und 3). Punkt 8 — das Ausschlusskriterium, das eine Stunde kostet
— steht aus.

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

## §4 Zwei Beobachtungen, beide am Prüfmittel und keine am Prüfling

### §4a `RandomizedDelaySec` hat nichts gedruckt

`systemctl show … -p Triggers -p Persistent -p RandomizedDelaySec -p
NextElapseUSecRealtime` gab **drei** Zeilen und nicht vier. `Persistent` und
`Triggers` standen da, `RandomizedDelaySec` nicht.

Der wahrscheinliche Grund ist eine Verwechslung von **Direktive** und
**Eigenschaft**: In der Unit-Datei heisst es `RandomizedDelaySec=300`, über den
Bus heisst dieselbe Grösse `RandomizedDelayUSec`. `systemctl show` fragt den
Bus. Ein Name, den es dort nicht gibt, erzeugt **keine Fehlermeldung, sondern
keine Zeile** — und eine fehlende Zeile liest sich wie „nicht gesetzt".

> **Ein Eigenschaftsname, den es nicht gibt, druckt nichts — und nichts sieht
> aus wie „nicht gesetzt".** Dieselbe Familie wie `systemctl is-active` für
> eine Unit, die es nicht gibt.

Der Punkt hängt nicht daran: Die Streuung ist über §1 an ihrer **Wirkung**
belegt. Offen ist allein der Grund des Schweigens, und er wird mit
`systemctl show srvpanel-packages.timer | grep -i random` beantwortet — also
ohne Namensraten, über die volle Liste.

### §4b `LAST` war abgeschnitten, und dahinter steckt mehr als eine Spaltenbreite

`list-timers` zeigte `LAST  Fri 2026-09-11 19:…`; der Rest lag hinter dem
Bildrand. Das Journal daneben trägt eine Zeile, die dazugehört:

```
Sep 11 19:09:07 cloudsrv24 systemd[1]: Finished srvpanel-packages.service
```

**Dieser Lauf war keiner von Hand.** Die Messung von Punkt 1 lief um 19:27,
also **vor** dem ersten `systemctl start` dieses Laufs — was `LAST` dort
nannte, hat der Timer ausgelöst und niemand sonst. Wahrscheinlich ist es der
Nachholer, den `Persistent=true` beim `enable --now` des postinstall-Skripts
auslöst: Es gab keinen vorigen Lauf, also feuert er sofort.

Damit ist der Weg Timer → Dienst → Ablage auf dieser Maschine **schon einmal
gegangen worden** — nur eben über `Persistent` und nicht über die stündliche
Folge. Punkt 8 fragt nach der Folge und bleibt offen.

> **Ein Beleg für den Weg ist keiner für das Ziel.**

Der genaue Zeitpunkt steht in `LastTriggerUSec` und wird dort geholt, statt aus
einer abgeschnittenen Spalte geschlossen zu werden.

> **Eine abgeschnittene Liste sieht aus wie eine vollständige — sie sagt nicht,
> wo sie aufhört.**

---

## §5 Was aussteht

- **Punkt 4 bis 6** — was man sieht: das Abzeichen mit **32**, der Punkt am
  Menüknopf bei 390 px, der Satz auf `/updates` mit dem Zeitpunkt aus §2.
- **Punkt 7** — die Bestandsdiagnose samt Gegenprobe.
- **Punkt 8** — das Feuern um **20:04:44 CEST**. Ausschlusskriterium.

Der Marker für Punkt 8 ist gesetzt: Der letzte Lauf von Hand war
**19:27:30 CEST**. Jeder spätere `ExecMainStartTimestamp` gehört dem Timer.
