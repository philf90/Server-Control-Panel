# A11 — Zeit des Servers, NTP und der Rechnername

Der Rest von A11. Der Neustart-Teil ist am 26. August 2026 gebaut (A1
Schritt 7); was bleibt, nennt `docs/80`: **Zeitzone des Servers und NTP-Zustand
neben der Anzeigezeitzone aus `docs/40`, Rechnername nur anzeigen.**

**Die Messrunde steht als `docs/81 §2.3r`**, gefahren am 6. September 2026 vor
der ersten Zeile dieses Plans. Sie hat sechzehn Messungen gebracht; vier tragen
diesen Entwurf, und eine hat ihn halbiert.

---

## 1 · Was die Messrunde schon entschieden hat

**Die Seite gibt es.** `/settings/general` („Allgemein") trägt heute
**„Anzeigezeit"** und **„Adressen dieses Servers"**. A11 braucht keine neue
Seite — und der Ort ist zugleich der, den `docs/80` verlangt: *neben* der
Anzeigezeitzone.

> **Ein Merkmal, dessen Ort schon steht, ist kleiner, als seine Zeile im Plan
> vermuten lässt.**

**`CanNTP` ist ein eigenes Feld.** Aus zwei Wahrheitswerten werden drei
Zustände des Dienstes, und `NTPSynchronized` ist der vierte und unabhängige:

| Zustand | `CanNTP` | `NTP` | was das heisst |
|---|---|---|---|
| kein Zeitdienst installiert | `no` | `no` | niemand stellt die Uhr |
| installiert, ausgeschaltet | `yes` | `no` | jemand hat ihn abgeschaltet |
| eingeschaltet | `yes` | `yes` | er läuft |

> **Zwei Wahrheitswerte, die vier Zustände tragen, verlieren beim Zusammenziehen
> genau den Fall, der eine Meldung verdient.**

**`/etc/timezone` gilt nicht.** Mit der Datei auf `Europe/Berlin` und dem
Symlink auf `Etc/UTC` sagt `timedatectl` `Etc/UTC` — es folgt dem Symlink.

> **Zwei Dateien, die dieselbe Frage beantworten, und nur eine ist die, der das
> System folgt.**

**Ohne systemd sagt `timedatectl` nichts, und zwar auf `stderr`:** `rc=1`, null
Bytes auf stdout, 118 Bytes Auskunft daneben. Wer nur stdout liest, hält eine
leere Ausgabe für einen Zustand.

**Die Beschriftung gibt es, aber nicht für eine fremde Zone.**
`Clock::describe()` erzeugt „CEST (UTC+02:00)", ist aber privat, und beide
öffentlichen Wege — `label()`, `labelAt()` — nageln auf `Clock::zone()`.

**Kosten:** 10 bis 12 ms je Lauf, kein kalter Erstlauf. **Rechte:** Lesen
braucht kein root; in den Agenten kommt es wegen der Architekturgrenze.

---

## 2 · Die drei Entscheidungen des Betreibers

Vom 6. September 2026, auf die drei Fragen, die `§2.3r` offen liess:

1. **Nur der Betreiber.** `/settings/general` bleibt ganz an `manage-settings`.
   Eine Teilung wie bei „Updates" und „Dienste" wäre neue Arbeit ohne eigene
   Frage: Was hier steht, dreht nichts und sagt nichts, was ein Administrator
   für seine Arbeit braucht.
2. **„Nicht feststellbar" statt zweitem Leser.** Antwortet `timedatectl` nicht,
   steht das da — und nicht ein aus dem Symlink geratener Wert.

   > **Ein zweiter Leser, der nur im Fehlerfall greift, ist der, der veraltet —
   > und er veraltet unbemerkt, weil der Fehlerfall selten ist.**
3. **Nur anzeigen.** Kein `set-timezone`. Die Zone des Servers zu drehen
   verschiebt jede Cron-Zeit dieses Servers, jeden Logstempel und jede
   `logrotate`-Grenze; das ist kein Knopf neben einer Auswahl.

---

## 3 · Was gelesen wird und wie

**Eine Quelle: `timedatectl show`.** Ein Aufruf, Schlüssel-Wert-Zeilen,
`rc=0` und stderr leer im Erfolgsfall.

    Timezone=Etc/UTC
    LocalRTC=no
    CanNTP=no
    NTP=no
    NTPSynchronized=no
    TimeUSec=Sun 2026-09-06 19:50:28 UTC

**Gelesen wird nach Schlüssel und nicht nach Position.** Die Reihenfolge ist
nirgends zugesagt, und ein Leser, der die dritte Zeile nimmt, bricht beim
nächsten systemd.

**`TimeUSec` wird nicht gelesen.** Die Uhr des Servers ist die Uhr, unter der
das Panel selbst läuft — `now()` gibt sie. Ein zweiter Weg zur selben Zahl wäre
die zweite Fassung derselben Regel, und die veraltet.

**Der Fehlerfall ist ein eigener Zustand und kein Wert.** Bei `rc != 0` sind
alle Felder unbekannt; der Grund kommt aus einer geschlossenen Menge, die das
Panel kennt — dieselbe Naht, die `DiagnoseSeamTest` für A10 hält.

---

## 4 · Wie es aussieht

Ein neuer Bereich **„Zeit des Servers"** unmittelbar hinter „Anzeigezeit", als
`pairs`-Tabelle:

| | |
|---|---|
| Zeitzone des Servers | `Etc/UTC` — UTC |
| Zeitabgleich | kein Zeitdienst installiert · ausgeschaltet · eingeschaltet · **nicht feststellbar** |
| Uhr abgeglichen | ja · nein · **nicht feststellbar** |
| Hardware-Uhr | UTC · Ortszeit · **nicht feststellbar** |
| Jetzt auf dem Server | 2026-09-06 19:56 |
| Dasselbe in der Anzeigezeit | 2026-09-06 21:56 — CEST (UTC+02:00) |

**Die Zeile „Hardware-Uhr" ist beim Bauen dazugekommen.** §5 zählt `local_rtc`
in der Antwort des Agenten auf, diese Tabelle hatte sie vergessen — und ein
Feld, das der Agent liest und keine Seite zeigt, ist von aussen nicht von einem
zu unterscheiden, das es nicht gibt. `LocalRTC=yes` ist dabei kein Kuriosum,
sondern eine Fehleinstellung mit Folgen: Die Uhr springt bei jedem
Zonenwechsel.

**Die letzte Zeile ist der Grund für den ganzen Bereich.** `docs/80` verlangt
die Serverzone *neben* der Anzeigezone, „weil die beiden sonst verwechselt
werden" — und zwei Zahlen nebeneinander, die denselben Augenblick meinen,
beantworten die Frage besser als jeder Hinweissatz.

> **Zwei Angaben, die verwechselt werden können, werden nicht durch eine
> Erklärung unterschieden, sondern dadurch, dass man sie nebeneinander zeigt.**

**Name und Beschriftung stehen beide da.** `Etc/UTC` heisst beschriftet
schlicht `UTC`; wer nur die Beschriftung zeigt, nennt die Zone nicht, und wer
nur den Namen zeigt, verschweigt den Versatz.

**Der Rechnername kommt in „Adressen dieses Servers"** und nicht hierher: Der
Bereich beantwortet, wie dieser Server heisst und wo er erreichbar ist, und der
Name gehört zu dieser Frage und nicht zur Zeit. Gezeigt wird `Names::fqdn()`;
fehlt der vollständige Name — in der Messrunde war er `NULL` —, steht
`Names::host()` da, und fehlt auch der, „nicht feststellbar".

**Ändern ist kein Knopf**, und der Bereich sagt es: Der Name steckt in
Zertifikaten, vhosts und dem DNS-Abgleich.

---

## 5 · Der Agent

Eine neue Operation **`system.time`**, lesend, ohne Argumente.

**Antwort im Erfolgsfall:**

    {
      "readable": true,
      "can_ntp": false,
      "ntp": false,
      "synchronized": false,
      "local_rtc": false
    }

**`timezone` stand hier und ist beim Bauen herausgefallen** — die Begründung
steht in §8 unter „Berichtigt am 6. September 2026". Die Zone kommt aus
`ServerZone`, das denselben Symlink liest wie `timedatectl`.

**Im Fehlerfall:**

    { "readable": false, "reason": "unreadable" }

**`readable` und nicht `null` je Feld.** Ein `null` in `ntp` liesse sich als
„aus" lesen; ein Feld, das den ganzen Zustand trägt, kann man nicht übersehen.

> **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
> tun".**

**Der Grund ist eine geschlossene Menge** und keine durchgereichte
Fehlermeldung. Der Wortlaut von `timedatectl` gehört ins Protokoll des Agenten,
nicht in die Seite: `docs/59` hat bezahlt, was passiert, wenn ein Serverzustand
als Feldfehler beim Leser ankommt.

**Das Programm steht auf der Positivliste mit absolutem Pfad**, wie jedes
andere.

---

## 6 · Die eine neue Methode in `Clock`

    public static function describeZone(string $zone, CarbonInterface $at): ?string

Geht durch dasselbe private `describe()` wie `label()` und `labelAt()`.
`null`, wenn die Zone unbekannt ist.

**Der Zeitpunkt ist ein Argument und keine Bequemlichkeit.** `docs/102 §3b` hat
gelernt, dass Berlin im Januar anders heisst als im Juli; eine Methode, die
`now()` einbaut, ist die dritte Fassung derselben Falle.

Gemessen (`§2.3r` M14), dieselbe Formel für fremde Zonen:

| Zone | September | Januar |
|---|---|---|
| `Etc/UTC` | `UTC` | `UTC` |
| `Europe/Berlin` | `CEST (UTC+02:00)` | `CET (UTC+01:00)` |
| `America/Sao_Paulo` | `-03 (UTC-03:00)` | `-03 (UTC-03:00)` |
| `Australia/Eucla` | `+0845 (UTC+08:45)` | `+0845 (UTC+08:45)` |

Alle drei Abkürzungsformen aus `docs/102 §3b` kommen vor.

---

## 7 · Das Abnahmekriterium

Acht Punkte, auf einem echten Server. **3 und 4 dürfen nicht ausfallen** — sie
sind die beiden, die die Messrunde gefunden hat.

| | | |
|---|---|---|
| 1 | Der Bereich steht da | Zone des Servers mit Name **und** Beschriftung; sie stimmt mit der überein, die die Cronseite an einen Zeitplan schreibt |
| 2 | Die Brücke | „Jetzt auf dem Server" und „dasselbe in der Anzeigezeit" unterscheiden sich um genau den Versatz |
| 3 | **NTP in seinen Zuständen** *(Ausschluss)* | „ausgeschaltet" und „eingeschaltet" sind verschiedene Sätze; „kein Zeitdienst installiert" ist ein dritter |
| 4 | **Nicht feststellbar** *(Ausschluss)* | antwortet `timedatectl` nicht, steht das da — und **nicht** „aus" |
| 5 | Der Rechnername | steht da; ohne vollständigen Namen der kurze, ohne beide „nicht feststellbar" |
| 6 | Die Tür | ein Administrator bekommt 403, der Betreiber nicht |
| 7 | 390 px | `schiebt = 0`, Gegenprobe **200** |
| 8 | Kosten | die Seite lädt nicht spürbar langsamer; der Griff kostet 10–12 ms |

### Wie Punkt 3 und 4 hergestellt werden

**Punkt 3, zwei der drei Zustände sind einfach:** `timedatectl set-ntp false`
und wieder `true`. Der dritte — „kein Zeitdienst installiert" — hiesse
`systemd-timesyncd` zu entfernen; das ist auf einem laufenden Server keine
Messung, sondern ein Eingriff. **Er darf als „nicht herstellbar" ausfallen und
wird dann benannt**, nicht stillschweigend übergangen.

**Punkt 4 wird über `systemctl mask systemd-timedated` hergestellt**, danach
`unmask`. Der Dienst wird über D-Bus aktiviert; maskiert antwortet er nicht,
und genau das ist der Zustand, den Entscheidung 2 abbilden soll.

> **Ein Vorschlag, der nicht gemessen ist, steht als Vorschlag da.** Dieser Weg
> ist im Container nicht bestätigt worden — dort scheiterte `systemctl` an
> seinem Bus, während `timedatectl` trug. Wer den Lauf fährt, prüft ihn zuerst
> und schreibt auf, was herauskam.

---

## 8 · Die Wächter

| Wächter | hält |
|---|---|
| `TimeStateTest` | der Leser liest **nach Schlüssel** und nicht nach Position; `yes`/`no` werden zu Wahrheitswerten; ein `rc != 0` gibt `readable: false` und keinen geratenen Wert — gemessen an den **gemessenen** Ausgaben aus `§2.3r` als Prüfkörper und nicht an erfundenen |
| `NtpVerdictTest` | die drei Zustände des Dienstes ergeben drei **verschiedene** Sätze, und „nicht feststellbar" ist ein vierter; gemessen an der Wirkung und nicht an der Anwesenheit von `CanNTP` im Quelltext |
| `ZoneLabelTest` | `describeZone()` geht durch dasselbe `describe()` wie `label()` — eine Fassung der Beschriftung; und der Zeitpunkt ist ein Argument, gemessen an Januar **und** Juli |
| `TimezoneFileTest` | `/etc/timezone` kommt in `app/` und `agent/` nirgends vor; `timedatectl` hat genau einen Aufrufer und einen Pfad auf der Positivliste; **und die Zone reist nicht durch den Agenten** |
| `AgentOperationReachTest` | (vorhanden) `system.time` zeigt auf eine Operation, die es gibt |
| `RouteAuthorizationTest` | (vorhanden) die Seite bleibt an `manage-settings` |

**`TimezoneFileTest` ist der billigste und der wichtigste.** Der Fund aus
M11 ist nicht, dass jemand `/etc/timezone` liest — es liest niemand. Er ist,
dass es **naheliegt**: Die Datei ist da, sie ist einzeilig, und sie beantwortet
scheinbar dieselbe Frage.

> **Eine Regel gegen eine Quelle, die niemand benutzt, hält den Tag auf, an dem
> jemand sie naheliegend findet.**

---

### Berichtigt am 6. September 2026 — die Zone kommt nicht vom Agenten

**§5 oben sah `timezone` in der Antwort von `system.time` vor. Das ist beim
Bauen umgeworfen worden, und gefangen hat es ein Wächter aus P6.**

Die Frage „in welcher Zone steht dieser Server" ist seit P6 beantwortet:
`App\Support\Cron\ServerZone` liest den Symlink, dem cron folgt — und M11 hat
gemessen, dass `timedatectl` **demselben** Symlink folgt. Es sind also nicht
zwei Quellen, sondern zwei Leser einer Quelle, und die Cronseite und diese Seite
hätten verschiedene Serverzonen nennen können.

> **Eine Messung, die nach dem Werkzeug sucht, findet die Frage nicht — sie war
> schon beantwortet, nur mit einem anderen Werkzeug.**

Die Messrunde hat `timedatectl` in `agent/` und `app/` gesucht und nichts
gefunden; nach `/etc/localtime` hat sie nicht gesucht. `ServerZoneSourceTest`
hat den zweiten Leser gemeldet, bevor er im Repo war.

**Was sich dadurch ändert:**

- `system.time` beantwortet nur noch, was **nur** es beantwortet: ob ein
  Zeitdienst da ist, ob er läuft, ob die Uhr stimmt, wie die Hardware-Uhr steht.
- `ServerZone::known()` ist dazugekommen — derselbe Leser, zwei Aufrufer:
  `current()` braucht eine Zone zum Rechnen und nimmt im Zweifel UTC,
  `known()` gibt `null` und lässt die Seite „nicht feststellbar" sagen. Das ist
  die Bauart von `Apt` aus A1 Schritt 1.
- `ServerTime::rows()` bekommt die Zone als **Argument** und beschafft sie
  nicht. Sonst liesse sich „Zone nicht ablesbar" nur auf einem Rechner messen,
  dessen Symlink kaputt ist.
- Die Tabelle in §4 hat eine Zeile mehr: **Hardware-Uhr**. §5 zählte
  `local_rtc` in der Antwort auf und §4 zeigte es nicht — ein Feld, das
  geschrieben und nie gelesen wird, ist von aussen nicht von einem zu
  unterscheiden, das es nicht gibt.

> **Eine Aufzählung dessen, was ein Merkmal beantwortet, und eine Tabelle
> dessen, was es zeigt, laufen auseinander — und die Lücke sieht in keiner von
> beiden nach einer aus.**

---

## 8b · Die Bilderrunde im Container — 6. September 2026

**Gefahren gegen die echte Seite**, nicht gegen einen Aufsatz: `artisan serve`,
der Agent in einer eigenen Mount-Namespace mit einer `timedatectl`-Attrappe, die
die **gemessene** Ausgabe aus `§2.3r` M6 druckt, und `/etc/localtime` für die
Dauer der Messung auf `Europe/Berlin` — damit steht in der Zeile der Zone die
längste Form, die es gibt.

Gemessen mit `tests/bilder-messen.js`, je Lage in einer frisch geladenen Seite:

| Breite | Thema | `dokument` | Gegenprobe | `schiebt` | `rollt` |
|---|---|---|---|---|---|
| 390 | hell | **0** | 200 (soll 200) | 0 | 0 |
| 390 | dunkel | **0** | 200 | 0 | 0 |
| 1440 | hell | **0** | 200 | 0 | 0 |
| 1440 | dunkel | **0** | 200 | 0 | 0 |

Die gemessenen Zeilen, mit einer Anzeigezone, die **nicht** die des Servers ist
(`Asia/Kolkata` gegen `Europe/Berlin`) — sonst zeigten beide Zeitzeilen
dieselbe Zahl und die Brücke wäre nicht gemessen:

| | |
|---|---|
| Zeitzone des Servers | `Europe/Berlin — CEST (UTC+02:00)` |
| Zeitabgleich | `kein Zeitdienst installiert` |
| Uhr abgeglichen | `nein` |
| Hardware-Uhr | `UTC` |
| Jetzt auf dem Server | `2026-09-06 22:42` |
| Dasselbe in der Anzeigezeit | `2026-09-07 02:12 IST (UTC+05:30)` |
| Rechnername | `vm` |

Der Rechnername ist dabei M15 in der Wirkung: `Names::fqdn()` gibt hier `NULL`,
und `host()` trägt.

### Der Befund der Runde

**Die beiden Zeitzeilen standen in zwei Formen da** — oben `H:i`, unten
`H:i:s`. Die Zahl war beide Male richtig, und `schiebt` war 0.

> **Zwei Angaben, die man nebeneinander stellt, damit man sie vergleicht,
> brauchen dieselbe Form — sonst vergleicht der Leser die Form.**

Behoben über `Clock::minute()`; die Beschriftung kommt seitdem aus `labelAt()`
und nicht aus `label()`, weil Berlin im Januar anders heisst als im Juli.
`NtpVerdictTest` hält beides, das zweite an einem festen „jetzt" — sonst wäre
der Fall ein halbes Jahr grün und ein halbes Jahr rot.

**Der Prüfstand ist abgeräumt und das ist belegt:** `/etc/localtime` wieder auf
`Etc/UTC`, `.env` wieder auf den Vorgabesocket, die Attrappe war ein Bind-Mount
in einer Namespace und `/usr/bin/timedatectl` ist unberührt (ELF), Socket und
Prozesse fort.

**Was diese Runde nicht ersetzt:** den Lauf auf `cloudsrv24`. Der Zustand
„eingeschaltet" und „Uhr abgeglichen: ja" ist hier nicht herstellbar — der
Container erreicht keinen Zeitserver (`§2.3r`), und ein Zeitdienst ist nicht
installiert. Punkt 3 und 4 des Abnahmekriteriums bleiben dem Server.

---

## 9 · Was A11 ausdrücklich **nicht** wird

- **Kein `set-timezone`.** Entscheidung 3.
- **Kein Ändern des Rechnernamens.** `docs/80`: Es nimmt Zertifikate, vhosts
  und den DNS-Abgleich mit.
- **Keine NTP-Server-Verwaltung.** Welche Server befragt werden, steht in
  `timesyncd.conf` oder `chrony.conf`; das ist eine Konfigurationsdatei mit
  einem verwalteten Bereich und damit eine eigene Stufe.
- **Kein Einschalten von NTP.** `timedatectl set-ntp` gäbe es; es ist ein
  Eingriff und A11 ist eine Anzeige. Wer den Zustand liest und schlecht findet,
  bekommt ihn gesagt.
- **Keine Prüfung in der Bestandsdiagnose.** A10 ist abgenommen; ein neuer
  `check` dort ist eine Änderung an einer abgenommenen Stufe und braucht
  ihren eigenen Nachlauf. **Vorgemerkt und nicht gebaut.**
- **Kein `chrony` oder `ntpd` als eigener Weg.** `timedatectl` beantwortet für
  beide, ob ein Zeitdienst da ist und ob er läuft; `show-timesync` täte es
  nicht (`§2.3r` M7).

---

## 10 · Wann er durch ist

**Alle acht Punkte erfüllt**, und **3 und 4 dürfen nicht ausfallen**. Fällt
einer der übrigen als „nicht herstellbar" aus, wird er benannt.

Ins Protokoll gehören:

- die **Fassung**, gegen die gemessen wurde,
- je Punkt der **gemessene** Wert und nicht „erfüllt",
- die Bilderrunde mit ihrer **Gegenprobe** (200),
- ob der Weg aus §7 für Punkt 4 getragen hat,
- und was danach **offen bleibt**.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**

Der Prüfstand wird abgeräumt und das wird belegt: `set-ntp` auf den Wert von
vorher, `systemd-timedated` entmaskiert, und `timedatectl show` zeigt danach
denselben Zustand wie vor dem Lauf.
