# Platte voll — der Plan

Geschrieben am 27. September 2026, **nach** der Messrunde und nach den
Entscheidungen des Betreibers vom selben Tag. Die Messvorschrift ist
`tests/platte-voll-messen.sh`; jede Zahl in §3 stammt aus ihrem zweiten Lauf.
Der erste hatte drei Fehler im Prüfmittel (am Ende von §3).

## §1 · Warum es diesen Plan gibt

`docs/129 §4` führt drei Auslöser für B1, die „seit P1" in
`app/Support/Metrics/` stünden: Platte voll, RAM, Load. **Gebaut war keiner.**
`FindingCheck` hat achtzehn Schlüssel und keinen für den Server selbst —
`quota.exceeded / disk_over` ist das Kontingent eines Abonnements (B5) —, und es
gibt weder Zeitgeber noch Kommando. Abgenommen ist B1 an seinem Kriterium, einem
angehaltenen Dienst (`docs/133 §7`); nach den drei Schwellen fragt es nicht.

**Und die Quelle stimmt nicht.** Der Ringpuffer hinter `Store` führt CPU, RAM,
Load, Netz und Plattendurchsatz — **keine Belegung**. Die steht in `system.info`
des Agenten (`filesystems`), und die Übersicht zeigt sie von dort, live.

**Entschieden am 27. September 2026**, nach der Regel des Betreibers:
*„Macht es Sinn und gibt es einen spürbaren Mehrwert für einen Nutzer im Panel?
Wenn das nicht erfüllt ist, wird es nicht gebaut."*

| | |
|---|---|
| Platte voll | **wird gebaut.** Heute sieht der Betreiber eine volle Platte nur, wenn er selbst nachsieht — und der Schaden hat einen Namen (§3 M5). |
| RAM, Load | **zurückgestellt.** Für keine der beiden gibt es eine gemessene Kurve; ohne sie wäre jede Schwelle geraten. |

## §2 · Was es schon gibt

| | wo | |
|---|---|---|
| Belegung je Einhängepunkt | `SystemInfo::filesystems()` im Agenten | gefiltert auf Arten, auf denen Daten liegen; `percent = (total − free) / total` mit `free = disk_free_space()` |
| „eng" | `OverviewController::filesystems()` | `tight` ab **85 %** — die einzige Schwelle im Bestand, und sie färbt nur den Balken |
| Befunde | `FindingLog`, `FindingCheck`, `Catalog` | zwei Läufe: die Nacht (`CHECKS`) und die Sicherungen (`BACKUP_CHECKS`) in eigener Unit |
| Meldungen | `Notices` | `HOLD_HOURS = 20`, zugeschnitten auf den Nachtlauf, gerufen als zweite Zeile der Diagnose-Unit |
| Platz vor dem Schreiben | `BackupCreate`, `DbDumpCreate`, `PgDumpCreate` | je `RESERVE_BYTES = 512 MiB` — dreimal dieselbe Zahl |

## §3 · Die Messrunde

**M1 · `disk_free_space()` liefert `f_bavail`** — den Platz ohne die Reserve
von root. Auf einer leeren Platte von 64 MiB (ext4, 5 % Reserve):

| | Agent | `df` |
|---|---|---|
| mit Reserve | 8,0 % | 1 % |
| Gegenprobe ohne Reserve (`tune2fs -m 0`) | 2,3 % | 1 % |

`disk_free_space()` ist `bavail × Blockgrösse`, aufs Byte (53 956 608 B);
`bfree × Blockgrösse` wären 58 650 624. Der Agent zählt die Reserve also als
belegt, `df` lässt sie auf beiden Seiten weg. **Auch ohne Reserve bleibt
`bavail` unter `bfree`** (13 992 gegen 14 319 Blöcke) — was ext4 dort
zurückhält, ist hier nicht gemessen.

Auf diesem Container zeigt der Agent `/` mit **91,6 %** und `df` mit **43 %**:
Die Sitzung hat ein Schreibkontingent, das als Reserve erscheint. Ein Server
ist das nicht; es zeigt nur, wie weit die beiden Zahlen auseinanderliegen
können.

**M2 · Voll heisst `bavail = 0`, und da sind sich beide einig.** nobody schreibt
bis „No space left on device": Agent **100,0 %**, `df` **100 %**. Gegenprobe:
root schreibt danach noch 1 MiB, in die Reserve, und nobody scheitert an einer
einzigen Zeile.

> **Zwei Zahlen, die sich in der Mitte um die Reserve unterscheiden, sind sich
> am Rand einig — und nur am Rand entscheidet sich, ob ein Kunde noch
> schreiben kann.**

**M3 · Voll an Inodes ist eine zweite Art, voll zu sein — und in Prozent
unsichtbar.** Auf einer Platte mit 128 Inodes scheitert die 118. Datei mit
**„No space left on device"**, während der Agent **7,5 %** zeigt und `df` für
den Platz **1 %**. Nur `df -i` sagt **100 %**. Gegenprobe: An eine bestehende
Datei anzuhängen geht weiter.

> **Dieselbe Meldung aus zwei Ursachen: Wer nur den Platz misst, sieht die
> zweite nie.**

PHP hat für Inodes keine Funktion. `stat -f -c '%c %d %n'` liefert sie für
mehrere Pfade in einem Aufruf; weder `stat` noch `df` steht heute auf der
Positivliste des Runners.

**M4 · Die Sandbox der Agenten-Unit macht aus einer Platte drei.** Der Agent
läuft mit `PrivateTmp=yes`. Liegt `/tmp` auf der Wurzel und ist kein tmpfs,
liefert `filesystems()` in einer Unit mit dieser Einstellung:

```
/          /dev/vda   ext4  91.6 %
/tmp       /dev/vda   ext4  91.6 %
/var/tmp   /dev/vda   ext4  91.6 %
```

Gegenprobe mit `PrivateTmp=no`: nur `/`. `PrivateTmp` hängt zwei private
Verzeichnisse der Wurzel über `/tmp` und `/var/tmp`, und `filesystems()`
unterscheidet nach dem **Einhängepunkt**, nicht nach dem **Gerät**. Eine volle
Platte ergäbe drei Befunde. Nach demselben Mechanismus zeigt die Übersicht auf
einem solchen Server heute schon drei Zeilen für eine Platte — **gesehen ist das
auf `cloudsrv24` nicht** (§8).

> **Ein Nachbau, der das Werkzeug direkt ruft, misst ohne die Sandbox der
> Einheit, unter der es auf dem Server läuft.** Derselbe Satz wie in B2
> (`docs/134 §7`); hier hat er einen Befund am Bestand gefunden.

**M5 · Bei voller Platte stürzt MariaDB ab — und das Panel mit ihr.** MariaDB
10.11.14, dieselbe Fassung wie auf `cloudsrv24`, mit dem Datenverzeichnis auf
einer Platte, die nobody vollgeschrieben hat:

| | rc | Dauer | |
|---|---|---|---|
| eine Zeile, Platz frei | 0 | 10 ms | |
| eine Zeile, Platte voll | 0 | 10 ms | InnoDB hat noch vorbelegten Platz |
| 20 000 Zeilen, Platte voll | 1 | 5 881 ms | `Lost connection to server during query` — der Server ist fort |
| Gegenprobe: Platz frei, neu gestartet, dieselbe Anweisung | 0 | 219 ms | keine Fehlerzeile; die beiden Zeilen von vorher sind da |

Das Protokoll des Servers sagt in drei Zeilen, warum:

```
[ERROR] mariadbd: The table 'findings' is full
[ERROR] [FATAL] InnoDB: Error (Out of disk space) in rollback.
[ERROR] mariadbd got signal 6 ;
```

**Die Anweisung scheitert, und das Zurückrollen braucht selbst Platz.** Der
erste Lauf ergab dieselbe Folge nach 5 716 ms. Eine volle Platte ist damit kein
langsames Verschlechtern: Die erste Anweisung, die eine Tabelle wachsen lässt,
beendet den Datenbankserver — und mit ihm jede Kundenseite mit Datenbank und das
Panel selbst.

> **Eine volle Platte ist kein Zustand, in dem man weitermacht, bis jemand
> aufräumt. Sie ist ein Absturz, der auf eine Anweisung wartet.**

**Und daraus folgt, wann gemeldet wird.** Befunde stehen in derselben Datenbank;
bei voller Platte schreibt sie nicht mehr oder antwortet gar nicht.

> **Ein Alarm, der erst bei voller Platte anschlägt, kann ihn nicht mehr
> ablegen.**

**M6 · Was die Griffe kosten:** `disk_*_space()` 0,001 ms je Einhängepunkt,
`stat -f` über drei Pfade 1,9 ms je Aufruf. Fehlt ein Pfad, endet `stat -f` mit
rc=1 und druckt die übrigen Zeilen trotzdem.

**Was diese Runde nicht sagt:** wie schnell eine Platte auf `cloudsrv24`
wirklich vollläuft — gemessen ist ein leerlaufender Tag mit +23,70 MiB
(`docs/129 §10` Punkt 2). Eine Sicherung schreibt in Minuten Gigabyte und hält
dabei 512 MiB frei (§2). Und ob der Mailweg bei voller Platte trägt, hängt am
Relay; ein lokales Postfix braucht selbst Platz.

**Drei Fehler im Prüfmittel, alle im ersten Lauf:**

- `tune2fs -q` gibt es nicht. Die Gegenprobe zu M1 lief nicht — und druckte
  dieselben Zahlen wie vorher.
- `:` ist in dash ein Spezial-Builtin; scheitert seine Umleitung, endet die
  ganze Shell. Die Zählzeile von M3 kam nie.
- Ein Hilfs-Mount über `/tmp` machte `/tmp` selbst zu einer Einhängung, und die
  Gegenprobe zu M4 zeigte zwei Zeilen statt einer. Der Unterschied war der
  Prüfstand.

> **Eine Gegenprobe, die nicht gelaufen ist, sieht aus wie eine, die nichts
> geändert hat.**

## §4 · Die Entscheidungen des Betreibers (27. September 2026)

| | entschieden |
|---|---|
| Schwellen | **Warnung ab 85 %, Störung ab 95 %.** 85 % ist die Grenze, ab der die Übersicht heute färbt — eine Zahl für beides. |
| Rückweg | **5 Punkte darunter** — eine Warnung geht erst unter 80 % weg, eine Störung unter 90 %. |
| Takt | **alle 5 Minuten**, in einer eigenen Unit |
| Haltezeit | **nach 10 Minuten** — gemeldet wird, was drei Läufe hintereinander dasteht; gebaut als Haltezeit von acht Minuten, damit genau der dritte meldet (§9) |
| Inodes | **ja, mit denselben Schwellen** |

**Der Rückweg ist keine Kosmetik.** Ohne ihn verschwindet ein Befund, sobald die
Platte einmal unter 85 % fällt, und `first_seen_at` beginnt beim nächsten
Überschreiten neu. Eine Platte, die um die Schwelle pendelt, stünde dann nie drei
Läufe am Stück da — und meldete gar nicht, obwohl sie dauernd an der Grenze
steht.

> **Eine Haltezeit ohne Rückweg macht aus einer Platte, die an der Grenze
> pendelt, eine, die nie meldet.**

**Warnung und Störung sind zwei Befunde und nicht einer.** Jeder hat seine
Schwelle und seinen Rückweg. Wird aus einer Warnung eine Störung, bleibt die
Warnung stehen, und die Störung kommt dazu. Andersherum hiesse der Aufstieg
„Warnung behoben" — genau in dem Augenblick, in dem es schlimmer wird.

**Die Zahl bleibt die des Agenten** und nicht die von `df`: Sie zählt die
Reserve von root als belegt und erreicht 100 % genau dann, wenn kein Kunde, keine
Datenbank und kein Pool mehr schreiben kann (M2). Unter 85 % liegen die beiden um
höchstens die Reserve auseinander; darüber sind sie sich nahe.

## §5 · Was gebaut wird

**Im Agenten**

- **Ein Leser für die Einhängepunkte**, den `system.info` und die neue Operation
  teilen. Er unterscheidet nach dem **Gerät**: Tragen zwei Einhängepunkte
  dasselbe, bleibt der kürzeste Pfad. Damit zeigt auch die Übersicht jede Platte
  einmal (M4).
- **Eine lesende Operation mit Platz und Inodes je Platte.** Die Inodes kommen
  aus einem Aufruf von `stat -f` über alle Pfade. Fehlt einer, sind seine Inodes
  „nicht gemessen" (`null`) und nicht „0 %".
- **`/usr/bin/stat` auf der Positivliste.** `system.info` ruft es nicht: Der
  Kennzahlensammler fragt `system.info` alle zehn Sekunden, und dort gilt „kein
  Programmaufruf".

**Im Panel**

- **Ein Schlüssel `disk.space`** mit vier Gründen — Platz eng, Platz voll,
  Inodes eng, Inodes voll — und `unreachable`. Der Gegenstand ist der
  Einhängepunkt.
- **Eine Prüfung** mit den Schwellen aus §4. Den Rückweg entscheidet sie an
  ihren **eigenen** Befunden vom vorigen Lauf. Die Schwellen stehen an **einer**
  Stelle; `tight` in der Übersicht liest dieselbe Warnschwelle.
- **Ein dritter Lauf im `Catalog`** neben Nacht und Sicherungen, mit eigenem
  Kommando, eigener kontextueller Bindung und eigenem Zeitpunkt für „zuletzt
  gemessen" — dasselbe Muster wie `BACKUP_CHECKS` (`docs/117 §13`).
- **Die Haltezeit hängt am Schlüssel.** `HOLD_HOURS` bleibt für alles, was die
  Nacht misst; `disk.space` meldet beim dritten Lauf (§9).
- **Der Meldelauf lässt sich auf Schlüssel beschränken.** Die neue Unit ruft ihn
  nur für `disk.space`. Riefe sie ihn für alles, ginge ein Befund der Nacht nach
  zwanzig Stunden hinaus, bevor die zweite Nacht ihn bestätigt hat — die
  Zusage „zwei Nächte" aus B1 wäre fort, ohne dass jemand sie angefasst hat.

**In der Paketierung**

- **Eine Unit samt Zeitgeber, `srvpanel-disk`**: `Type=oneshot`, eine Frist
  unter dem Takt, zwei Zeilen wie die Diagnose — erst messen, dann melden —, im
  Ziel `srvpanel.target`, im Katalog der Units und im Aufruf `srvpanel disk`.

## §6 · Die Wächter

- **Die Schwellen an der Wirkung:** 84,9 % ergibt nichts, 85 % eine Warnung,
  95 % Warnung und Störung. Der Rückweg in beide Richtungen: 82 % mit einer
  Warnung vom vorigen Lauf bleibt, 79,9 % geht; 82 % ohne sie ergibt nichts.
- **Inodes:** `null` ergibt keinen Befund über Inodes, und eine Platte ohne
  Inodezahl (btrfs meldet 0) auch nicht.
- **Ein Gerät, eine Zeile** — am Leser, mit zwei Einhängepunkten auf demselben
  Gerät.
- **Die Haltezeit je Schlüssel:** ein Befund `disk.space` meldet beim dritten
  Lauf und nicht beim zweiten, auch wenn der dritte eine halbe Minute zu früh
  kommt; ein Befund der Nacht wird vom beschränkten Meldelauf **nicht**
  angefasst, auch nach zwanzig Stunden nicht.
- **Eine Warnschwelle:** Übersicht und Prüfung lesen dieselbe.
- **Die bestehenden Wächter decken den Rest:** jeder Schlüssel mit genau einem
  Schreiber, jede Unit paketiert und im Katalog, jede Frist unter ihrem Takt,
  jeder Grund dem Panel bekannt.
- **Ein Bruch je Regel** in `tests/waechter-brechen.sh`.

## §7 · Wann es abgenommen ist

**Auf `cloudsrv24`, an einer eigenen Wegwerf-Platte im Loop und nie an der
Wurzel:**

1. Die Übersicht zeigt jede Platte einmal.
2. Die Wegwerf-Platte über 85 % — beim dritten Lauf, der sie so sieht,
   **eine** Meldung an den Betreiber, über beide Kanäle. Gerechnet sind das
   höchstens gut sechzehn Minuten nach dem Überschreiten: bis zu 331 Sekunden
   bis zum ersten Lauf, bis zu 631 bis zum dritten.
3. Über 95 % — eine zweite, als Störung; die Warnung bleibt stehen.
4. Unter 80 % — die Entwarnung für beide, und der nächste Lauf meldet nichts.
5. Inodes: dieselbe Platte ohne freie Inodes ergibt „Inodes voll", während der
   Platz frei ist.
6. „Zuletzt gemessen" für den neuen Lauf steht auf der Diagnoseseite, und der
   Zeitgeber hat einen nächsten Termin.

**Ausschlusskriterium:** Die Wurzel des Servers wird für den Lauf nicht
gefüllt. Ob der Agent eine Einhängung sieht, die nach seinem Start entstanden
ist, wird vor dem Lauf gemessen und nicht angenommen.

## §8 · Was offen bleibt

- **RAM und Load** — zurückgestellt (§1).
- **Ob `cloudsrv24` heute drei Zeilen für eine Platte zeigt** (M4). Ein Blick auf
  die Übersicht beantwortet es.
- **Der Mailweg bei voller Platte** — ungemessen, hängt am Relay.
- **Eine Vorhersage** („in drei Tagen voll") — nicht gebaut; sie bräuchte eine
  Kurve der Belegung, und der Ringpuffer führt keine.
- **Eine absolute Untergrenze.** Auf einer Platte von 10 GB lässt 95 % noch
  500 MiB frei — weniger, als eine Sicherung vor dem Schreiben verlangt. Die
  Warnung bei 85 % kommt dort trotzdem vorher.

## §9 · Gebaut am 27. September 2026 — und was dabei anders war

Gebaut ist §5 vollständig, mit den Zahlen aus §4. Die Wächter aus §6 heissen
`DiskVerdictTest`, `DiskReaderTest`, `DiskNoticeTest` und `DiskCadenceTest`;
`DiagnoseWiringTest` hält den dritten Lauf an der Wirkung. Jede Regel hat ihren
Bruch in `tests/waechter-brechen.sh`. **Abgenommen ist nichts** — das ist §7, auf
`cloudsrv24` gegen die nächste Freigabe.

**Fünf Dinge liefen anders als geplant.**

- **Die Prüfung auf Überschneidung der Läufe kam nie zu Wort.**
  `DiagnoseRunTest` verglich zuerst den Katalog mit dem Verzeichnis und danach,
  ob eine Prüfung in zwei Läufen steht. Eine Prüfung in zwei Läufen steht in
  `every()` aber doppelt — der Vergleich schlug an, bevor die Frage gestellt
  war, mit einer Meldung, die den Grund nicht nennt. Sie steht jetzt vorn und
  fragt paarweise über alle drei Läufe; vorher kannte sie zwei.

  > **Eine Prüfung hinter einer anderen, die im selben Fall zuerst anschlägt,
  > ist keine — sie ist ein Kommentar mit Assertion.**

- **Die Vorfilterung der Meldungen war eine zweite Fassung der Haltezeit.** Der
  erste Wurf rechnete die kürzeste Haltezeit aus zwei Konstanten; ein neuer
  Schlüssel mit noch kürzerer wäre still herausgefiltert worden. Sie kommt jetzt
  aus `Notices::holdMinutes()` über alle Schlüssel.
- **Zwei Eingriffe des Bruchskripts hatten ihren Anker verloren**, beide in
  `Notices`: die Haltezeit in `due()` und das Verbrauchen der Entwarnungen.
  Gefunden hat es keine Erinnerung, sondern ein Abgleich jedes Ankers gegen die
  geänderten Dateien — 64 geprüft, zwei ohne Treffer.
- **Die Haltezeit lag genau auf zwei Takten — und damit auf einem Würfel.**
  Der erste Wurf setzte zehn Minuten, die Zahl der Entscheidung. Der dritte
  Lauf kommt aber nicht auf die Sekunde zehn Minuten nach dem ersten: Der
  Zeitgeber streut um bis zu dreissig Sekunden, und ohne `AccuracySec` legt
  systemd jeden Termin in ein Fenster von einer Minute (gemessen:
  `AccuracyUSec=1min`). Die Meldung wäre mal beim dritten, mal beim vierten
  Lauf gekommen. Dieselbe Frage hatte `HOLD_HOURS` längst beantwortet —
  zwanzig Stunden und nicht vierundzwanzig, weil die Nächte 23 bis 25 Stunden
  auseinanderliegen. Gebaut ist jetzt `AccuracySec=1s` und eine Haltezeit von
  acht Minuten; der zweite Lauf kommt höchstens 331 Sekunden nach dem ersten,
  der dritte frühestens nach 569. `DiskCadenceTest` rechnet beides aus der
  Unit, und gemeldet wird, wie entschieden, beim dritten.

  > **Eine Haltezeit, die genau auf einen Takt fällt, zählt die Läufe nicht,
  > sondern würfelt sie.**

- **„Platte" ist in der Oberfläche verbraucht** (`docs/19 §3`); `WordChoiceTest`
  meldete eine Variable in einer Vorlagenzeichenkette. Die Oberfläche sagt
  „Dateisystem" — dieses Dokument bleibt beim Wort, unter dem der Betreiber die
  Frage gestellt hat.
