# Nachlauf zum Abzeichen — `0.7.4-rc.4` auf `cloudsrv24`

Ausgeschrieben am **11. September 2026, vor dem Fahren**. Der Plan des
Merkmals ist `docs/907`, die Freigabe `v0.7.4-rc.4`, installiert auf
`cloudsrv24` (`srvpanel version` → `0.7.4-rc.4`, vom Betreiber belegt).

**Wofür es diesen Lauf gibt.** Der PR zum Abzeichen hat zwei Dinge ausdrücklich
als ungemessen benannt, und beide lassen sich in dem Container, in dem gebaut
wurde, grundsätzlich nicht messen — er hat kein systemd als PID 1:

1. Ob `srvpanel-packages.timer` stündlich wirklich feuert.
2. Ob die Zahl aus einem echten `system.packages.list` kommt. Im Container ist
   sie über den echten Schreiber **von Hand** abgelegt worden.

Alles andere am Abzeichen ist gemessen und steht in `docs/907`. Dieser Lauf
holt genau die beiden nach und nimmt mit, was ohne zusätzliche Arbeit daneben
abfällt.

---

## §0 Was beim Ausschreiben umgefallen ist

Drei Kriterien standen schon da und waren falsch. Gefunden hat sie das
Nachlesen am Quelltext und nicht das Nachdenken.

**1. „Der Timer feuert stündlich" ist als ein Punkt nicht fahrbar.** Um
„stündlich" zu messen, müsste der Lauf Stunden dauern; um es *nicht* zu messen
und trotzdem abzuhaken, müsste jemand das Kriterium weicher lesen als
geschrieben. Es zerfällt in zwei, und beide sind einzeln scharf: Der Termin
steht **jetzt** da (Punkt 1), und dass er wirklich zuschlägt, zeigt sich nach
der nächsten vollen Stunde (Punkt 8). Der zweite ist der teurere und darf
nicht ausfallen.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten,
> ist keines mehr — es ist eine Zusammenfassung.**

**2. Ein Vergleich der abgelegten Zahl mit `apt` allein misst nicht.** Die Zahl
in der Ablage ist bereits richtig — wer `/updates` öffnet, schreibt sie. Ein
Punkt, der „Ablage stimmt mit apt überein" prüft, wäre also **vorher schon
erfüllt** und bliebe es, auch wenn der Dienst gar nichts täte. Der Ausgangs­
zustand wird deshalb zuerst zerstört (eine erfundene 999), und der Erfolgsfall
ist genau der, der sie wieder loswird.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

**3. Die Gegenprobe wäre gegen die falsche apt-Frage gelaufen.**
`Packages::read()` baut `upgradable` **allein aus dem `dist-upgrade`-Lauf**;
`apt-get -s upgrade` liefert dort nur die zurückgehaltenen Pakete für ein
anderes Feld (`held`). Auf einer Maschine mit zurückgehaltenen Paketen gehen
die beiden Zahlen auseinander — der Lauf hätte den Prüfling für etwas
gemeldet, das er zu Recht tut. Gemessen wird gegen `apt-get -s dist-upgrade`.

> **Ein Kriterium, das man am falschen Paket misst, meldet den Prüfling für
> etwas, das er zu Recht tut.**

---

## §1 Vorbereitung — und sie wird belegt, nicht vorausgesetzt

Alles als `root` auf `cloudsrv24`.

```bash
srvpanel version                       # erwartet: 0.7.4-rc.4
systemctl cat srvpanel-packages.timer  # muss die Unit-Datei drucken
systemctl cat srvpanel-packages.service
```

**`systemctl cat` und nicht `is-active`.** Für eine Unit, die es nicht gibt,
meldet `is-active` schlicht `inactive` — ununterscheidbar von einer, die
angehalten ist. Genau daran wäre `docs/905` beinahe gescheitert, weil die
Vorschrift `srvpanel-agent` schrieb und die Unit `srvpanel-agentd` heisst.
`cat` bricht bei einem falschen Namen ab und sagt es.

> **`systemctl is-active` meldet für eine Unit, die es nicht gibt, `inactive`
> — ununterscheidbar von einer, die angehalten ist.**

---

## §2 Punkt 1 — der Timer hat einen Termin

```bash
systemctl list-timers srvpanel-packages.timer --all
systemctl show srvpanel-packages.timer \
    -p Triggers -p Persistent -p RandomizedDelaySec -p NextElapseUSecRealtime
```

**Erfüllt, wenn:**

- `ACTIVATES` nennt `srvpanel-packages.service`, und `Triggers=` ebenso.
- `NEXT` ist ein Datum und **nicht** `-`.
- `Persistent=yes`, `RandomizedDelaySec=5min`.

**Warum das ein eigener Punkt ist.** Ein Timer, der `active` meldet und keinen
nächsten Termin hat, ist abgeschaltet und sieht aus wie eingeschaltet — zwei
der drei Timer dieses Panels waren im August genau so gebaut. Dass es diesen
hier nicht trifft, hängt an seinem `OnCalendar=hourly`: `Persistent=true` wirkt
allein darauf und nicht auf monotone Sockel.

---

## §3 Punkt 2 — der Dienst läuft und schreibt *(darf nicht ausfallen)*

**Zuerst den Zustand unbrauchbar machen.** Ohne diesen Schritt ist der Punkt
keine Messung, siehe §0 Nummer 2.

```bash
srvpanel tinker --execute='app(\App\Support\Settings\Settings::class)->savePendingUpdates(999);'

srvpanel tinker --execute='$s=app(\App\Support\Settings\Settings::class);
  printf("zahl=%s  zeitpunkt=%s\n", var_export($s->pendingUpdates(),true), var_export($s->pendingUpdatesCheckedAt(),true));'
```

Erwartet: `zahl=999`. Steht dort etwas anderes, hat das Schreiben nicht
gewirkt, und alles Folgende misst nichts.

**Dann den Dienst von Hand starten** — denselben Weg, den auch der Timer nimmt:

```bash
systemctl start srvpanel-packages.service
systemctl show srvpanel-packages.service -p Result -p ExecMainStatus -p ActiveEnterTimestamp
journalctl -u srvpanel-packages.service -n 10 --no-pager
```

**Erfüllt, wenn:**

- `Result=success`, `ExecMainStatus=0`.
- Das Journal trägt die Zeile `<N> aktualisierbare Pakete festgehalten.`
- Die Ablage steht **nicht mehr** auf 999, und `zeitpunkt` ist neuer als vorher.

**Was dieser Punkt nebenbei klärt.** Die Unit trägt `ProtectSystem=strict` und
`ReadWritePaths=/var/lib/srvpanel /var/log/srvpanel` — dieselbe Absperrung wie
`srvpanel-usage`, `srvpanel-diagnose` und `srvpanel-cron`, die alle in die
Datenbank schreiben. Das ist ein Indiz und kein Beleg: Drei Nachbarn, die es
können, sagen über den vierten nichts. Dieser Punkt sagt es.

---

## §4 Punkt 3 — die Zahl ist die des echten apt *(darf nicht ausfallen)*

Unmittelbar nach Punkt 2, ohne dass dazwischen jemand Pakete anfasst:

```bash
apt-get -s dist-upgrade 2>/dev/null | grep -c '^Inst '

srvpanel tinker --execute='printf("%s\n", app(\App\Support\Settings\Settings::class)->pendingUpdates());'
```

**Erfüllt, wenn beide Zahlen gleich sind.**

`dist-upgrade` und nicht `upgrade` — der Grund steht in §0 Nummer 3. Zur
Sicherheit lohnt die zweite Zahl daneben; gehen sie auseinander, ist das kein
Befund, sondern der Beleg, dass die Unterscheidung zählt:

```bash
apt-get -s upgrade 2>/dev/null | grep -c '^Inst '   # nur zur Ansicht
```

**Schärfer, aber freiwillig** — wenn die beiden Zahlen gleich sind, könnte das
Zufall sein. Dieser Griff verschiebt die Wahrheit und sieht nach, ob die Ablage
folgt. Er ist umkehrbar, und die Umkehrung steht daneben:

```bash
PAKET=$(apt-get -s dist-upgrade 2>/dev/null | awk '/^Inst /{print $2; exit}')
apt-mark hold "$PAKET"
apt-get -s dist-upgrade 2>/dev/null | grep -c '^Inst '   # sollte kleiner sein
systemctl start srvpanel-packages.service
srvpanel tinker --execute='printf("%s\n", app(\App\Support\Settings\Settings::class)->pendingUpdates());'

apt-mark unhold "$PAKET"                                  # zurück
systemctl start srvpanel-packages.service                  # und die Zahl wieder heben
```

Die Zahl kann dabei um **mehr als eins** fallen: Ein zurückgehaltenes Paket
hält seine Abhängigen mit zurück. Verlangt ist nicht „genau eins weniger",
sondern dass die Ablage der gemessenen Wahrheit folgt.

---

## §5 Punkt 4 bis 6 — was man sieht

Im Browser, angemeldet als Betreiber. Die Zahlen aus Punkt 3 stehen daneben.

| Punkt | Lage | Erfüllt, wenn |
|---|---|---|
| 4 | 1440 px, beide Themen | Am Menüpunkt „Updates" steht das Abzeichen mit **genau der Zahl aus Punkt 3** |
| 5 | 390 px, Menü zugeklappt | Am Menüknopf der Kopfleiste steht der Punkt; er ist sichtbar, ohne dass jemand das Menü öffnet |
| 6 | `/updates` | Der Satz „Am Menüpunkt „Updates" steht die Zahl vom …" nennt **den Zeitpunkt aus Punkt 2** |

Das Abzeichen ist im Container gegen die gebaute Seite gemessen — Kontrast
8,42:1, kein Überlauf in vier Lagen. Hier geht es nicht um die Gestaltung,
sondern darum, dass **die Zahl dieselbe ist wie die in der Ablage**: Bis hierher
ist nur belegt, dass der Dienst schreibt, nicht dass die Navigation liest, was
er geschrieben hat.

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt.**

---

## §6 Punkt 7 — die Bestandsdiagnose kennt die neue Unit

`docs/98` verlangt, dass jede paketierte Unit im Katalog steht und der
Nachtlauf sie beurteilt. Der Code ist über `UnitCatalogTest` gehalten; dass es
auf dem Server wirkt, ist es nicht.

```bash
srvpanel diagnose
srvpanel tinker --execute='foreach (\App\Models\Finding::query()->where("check","like","unit.%")->get() as $f)
  { printf("%s  %s  %s\n", $f->check->value, $f->subject, $f->reason); }'
```

**Erfüllt, wenn** über `srvpanel-packages` **kein** Befund steht.

**Und die Gegenprobe, die den Punkt erst zu einer Messung macht** — ohne sie
bliebe offen, ob die Diagnose die Unit überhaupt ansieht:

```bash
systemctl stop srvpanel-packages.timer
srvpanel diagnose          # erwartet: ein Befund unit.schedule / no_next
systemctl start srvpanel-packages.timer
srvpanel diagnose          # erwartet: der Befund ist fort
```

> **Eine Abwesenheit ist nur dann ein Befund, wenn die Anwesenheit im
> Erfolgsfall belegt ist.**

---

## §7 Punkt 8 — der Timer hat wirklich gefeuert *(darf nicht ausfallen)*

Dieser Punkt kostet Wartezeit und lässt sich nicht abkürzen. `OnCalendar=hourly`
mit `RandomizedDelaySec=5min` heisst: höchstens 65 Minuten nach der letzten
vollen Stunde.

**Vorher**, damit der spätere Beleg nicht der Lauf von Punkt 2 ist:

```bash
date -Is
systemctl show srvpanel-packages.service -p ActiveEnterTimestamp   # notieren
```

**Nach der nächsten vollen Stunde:**

```bash
systemctl list-timers srvpanel-packages.timer --all
systemctl show srvpanel-packages.service -p ActiveEnterTimestamp -p Result
journalctl -u srvpanel-packages.service --since '-90 min' --no-pager
srvpanel tinker --execute='printf("%s\n", app(\App\Support\Settings\Settings::class)->pendingUpdatesCheckedAt());'
```

**Erfüllt, wenn:**

- `LAST`/`PASSED` in `list-timers` nennen einen Zeitpunkt **nach** dem
  notierten, und `NEXT` steht wieder auf der folgenden Stunde.
- `ActiveEnterTimestamp` des Dienstes ist gewandert, `Result=success`.
- `pendingUpdatesCheckedAt()` nennt denselben Zeitpunkt.

Der dritte Haken ist der tragende: Er verbindet den Timer mit der Ablage. Die
ersten beiden allein belegen, dass etwas lief — nicht, dass es das war, was das
Abzeichen speist.

> **Ein Beleg für den Weg ist keiner für das Ziel.**

---

## §8 Was danach aufgeräumt wird

- `apt-mark unhold`, falls der freiwillige Griff aus §4 gefahren wurde — und
  danach **ein Lauf des Dienstes**, sonst bleibt die verkleinerte Zahl stehen.
- `systemctl start srvpanel-packages.timer`, falls §6 abgebrochen wurde,
  während er stand.
- Die 999 aus §3 braucht kein Aufräumen: Punkt 2 ist genau der Schritt, der sie
  loswird. Bleibt sie stehen, ist der Punkt nicht erfüllt.

---

## §9 Was dieser Lauf ausdrücklich **nicht** prüft

- **Ob der Timer einen Neustart übersteht.** `OnBootSec=10min` und
  `Persistent=true` sind gelesen und nicht gefahren; dafür bräuchte es einen
  Neustart von `cloudsrv24`.
- **Ob die stündliche Folge über viele Stunden trägt.** Ein Feuern belegt den
  Mechanismus, `NEXT` den Plan. Über den zwölften Lauf sagt keiner von beiden
  etwas.
- **Die Gestaltung des Abzeichens und des Punktes.** Kontrast, Überlauf, Lage
  zur Tinte des Zeichens — alles in `docs/907` gemessen, gegen echtes Chromium
  und die gebaute Seite. Hier wird nur die **Zahl** verglichen.
- **Den Fall „der Agent antwortet nicht".** `CollectPendingUpdates` lässt dann
  die alte Zahl stehen und meldet es; geprüft ist das im Container. Es hier
  herzustellen hiesse, den Agenten auf einem Server anzuhalten, auf dem Kunden
  liegen.

---

## §10 Wann er durch ist

Erfüllt sind **alle acht Punkte**. **Die Punkte 2, 3 und 8 dürfen nicht
ausfallen** — sie sind die beiden Dinge, für die es diesen Lauf gibt, und ihr
Beleg ist genau der Unterschied zwischen „gebaut" und „abgenommen".

Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist **nicht**
„nicht herstellbar". Punkt 8 kostet eine Stunde Wartezeit; das ist kein Grund,
ihn anders zu lesen.

> **Eine Ausbaustufe gilt erst als fertig, wenn ihr Abnahmekriterium
> nachweisbar erfüllt ist — gemessen auf einem echten Server, nicht geschätzt.**

Das Protokoll bekommt die nächste freie Nummer im 900er-Block. Sie steht
bewusst **nicht** hier: `docs/81` hat einmal eine genannt, die einem anderen
Dokument gehörte, und `DocLinkTest` konnte das nicht sehen.
