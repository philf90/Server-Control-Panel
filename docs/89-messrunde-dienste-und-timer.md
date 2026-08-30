# 89 — Messrunde vor A2: Dienste und Timer

**30. August 2026**, im Container gemessen, gegen echtes systemd 255. Sie kommt
vor dem Plan von A2 und nicht danach.

**Warum.** Der Nachlauf zu `0.7.2-rc.5` (`docs/88`) hat elf Befunde gebracht, und
**vier davon entstanden dadurch, dass ich eine Erwartung aufgeschrieben habe,
statt sie zu messen.** A2 fängt deshalb mit der Messrunde an. Die Skizze in
`docs/81 §A2` nennt drei Dinge, die sie voraussetzt — den Neustartzähler, den
nächsten Termin eines Timers und die Unit-Liste —, und keines davon war je
gemessen.

---

## 1 · Die Umgebung — systemd gibt es hier auch

`CLAUDE.md` führte bis heute „kein systemd" und, seit dem 27. August, genauer
„es fehlt systemd als PID 1". Das erste ist falsch, das zweite die halbe
Wahrheit.

**Gemessen:** `systemctl`, `systemd`, `systemd-run`, `busctl` und `dbus-daemon`
sind alle installiert, **systemd 255 (255.4-1ubuntu8.14)**, und unter
`/lib/systemd/system` liegen **273 Units**. PID 1 ist `process_api`.

**Der Benutzer-Manager trägt nicht.** `systemd --user` endet mit Rückgabe 1 und
**null Byte Ausgabe** — auch mit `--log-target=console --log-level=debug`, also
vor dem Aufsetzen des Logs. Der naheliegende Verdacht war die cgroup-v1-
Hierarchie dieses Containers (`/sys/fs/cgroup/cgroup.controllers` fehlt); eine
`cgroup2`-Mount in einer eigenen Namespace hat den Abbruch **nicht** verändert.
Der Grund bleibt ungemessen, und das steht hier als Lücke und nicht als Erklärung.

> **Ein Verdacht, den die Gegenprobe widerlegt, ist keine Ursache — und die
> Lücke gehört benannt statt gefüllt.**

**Der Systemmanager trägt.** In einer eigenen PID- und Mount-Namespace läuft er
als PID 1:

    unshare -m -p -f --mount-proc bash -c '
      mount -t cgroup2 none /sys/fs/cgroup
      mkdir -p /run/systemd
      exec /usr/lib/systemd/systemd --system --log-target=console --unit=basic.target
    ' &

Gemessen danach: `systemctl is-system-running` meldet **`running`**, ein eigener
`systemd-journald` läuft, `list-timers` zeigt echte Timer mit echten Terminen
(`apt-daily`, `fstrim`, `e2scrub_all`). Gesprochen wird mit ihm über
`nsenter -t <pid> -m -p -- systemctl …`.

**Drei Handgriffe, die Zeit gekostet haben.** Der Manager schreibt sein Log in
**seine eigene** Namespace — draussen bleiben 107 Byte SELinux-Meldung stehen und
es sieht aus, als sei er gestorben; nachgesehen wird mit `ps`, nicht im Log. Die
Namespace hat **ihr eigenes `/run`**: Unit-Dateien, die man von draussen nach
`/run/systemd/system` schreibt, sieht sie nicht, und `systemctl start` meldet
„Unit not found" für eine Datei, die man gerade angelegt hat. Und das ist
zugleich der Vorteil — was drinnen liegt, verschwindet mit ihr.

> **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
> braucht einen Versuch.** Derselbe Satz wie bei MariaDB, beim `sshd`, bei
> PowerDNS, bei PHPStan, bei Composer und beim Bruchskript — diesmal an dem
> Werkzeug, dessen Fehlen A2 unmessbar gemacht hätte.

**Und der Prüfkörper, der beinahe liegengeblieben wäre.** Der erste Anlauf hat
`/run/systemd/system` **draussen** angelegt. An genau diesem Verzeichnis erkennt
`sd_booted()`, ob systemd läuft — der Container hätte sich danach für gebootet
gehalten, wortlos und über die Sitzung hinaus. Entfernt; die Gegenprobe
`systemctl is-system-running` sagt draussen wieder `offline`, und die übrigen
Unterverzeichnisse von `/run/systemd` gehörten dem Image.

> **Ein Prüfkörper, der zufällig eine Zusage des Systems ist, richtet mehr an
> als eine Datei zuviel.**

---

## 2 · Was `ServiceStatus` heute von einem Timer bekommt

`ServiceStatus::FIELDS` fragt neun Eigenschaften. Gemessen gegen vier Arten von
Unit:

| Feld | Dienst (läuft) | Timer | Unit gibt es nicht |
|---|---|---|---|
| `Id` | `mess-laeuft.service` | `mess-kalender.timer` | `nicht-vorhanden.service` |
| `Description` | gesetzt | gesetzt | **der Unit-Name selbst** |
| `LoadState` | `loaded` | `loaded` | `not-found` |
| `ActiveState` | `active` | `active` | `inactive` |
| `SubState` | `running` | `waiting` | `dead` |
| `UnitFileState` | `static` | `static` | leer |
| `MainPID` | `126` | **fehlt in der Ausgabe** | `0` |
| `ExecMainStartTimestamp` | gesetzt | **fehlt in der Ausgabe** | leer |
| `NRestarts` | `0` | **fehlt in der Ausgabe** | `0` |

**M1 — ein Timer beantwortet nur sechs der neun Felder.** Die drei anderen
stehen nicht als leerer Wert da, sie stehen **gar nicht** da. `ServiceStatus`
liest sie mit `?? 0` und `?? ''` und macht daraus `pid: 0`, `restarts: 0`,
`since: ''`.

> **Ein Timer sieht durch `service.status` heute aus wie ein Dienst, der nie
> gestartet ist — und nichts an der Antwort sagt, dass die Frage nicht passte.**

**M2 — eine unbekannte Unit beantwortet alle neun.** `LoadState=not-found`, und
die `Description` ist der Name, nach dem man gefragt hat. Der Kommentar im
Quelltext sagt das seit P0 und stimmt.

---

## 3 · Der nächste Termin — die Messung, die den Entwurf entscheidet

Das Abnahmekriterium von A2 lautet: *ein Timer ohne nächsten Termin ist auf der
Seite als kaputt erkennbar, ohne dass man die Zahl deuten muss.* Gemessen an vier
Prüfkörpern, jeder mit **eigenem** Dienst:

| Prüfkörper | Sockel | `ActiveState` | `SubState` | `NextElapseUSecRealtime` | `NextElapseUSecMonotonic` |
|---|---|---|---|---|---|
| **A** gesund, Kalender | `OnCalendar=*-*-* 04:00:00` | `active` | `waiting` | `Mon 2026-08-31 04:00:00 UTC` | `0` |
| **A2** gesund, monoton | `OnBootSec` + `OnUnitActiveSec` | `active` | `waiting` | **leer** | `1h 8min 10.136428s` |
| **B** kaputt, ohne Anker | `OnUnitActiveSec=1h` | `active` | `elapsed` | leer | **`infinity`** |
| **C** einmal gefeuert | `OnActiveSec=2s` | `active` | `elapsed` | leer | **`infinity`** |

**M3 — `ActiveState` ist bei allen vieren `active`.** Der gesunde und der kaputte
Timer sind daran nicht zu unterscheiden. Das ist der Satz vom 19. August,
gemessen statt behauptet:

> **Ein Dienst, der „active" meldet und keinen nächsten Termin hat, ist
> abgeschaltet und sieht aus wie eingeschaltet.**

**M4 — und „Realtime ist leer" heisst nicht kaputt.** Der gesunde **monotone**
Timer (Zeile A2 — die Bauart der Panel-Timer) hat die Realtime-Spalte ebenfalls
leer; sein Termin steht als **Dauer** in der Monotonic-Spalte. Wer nur die eine
Spalte liest, meldet jeden monotonen Timer als kaputt.

> **Zwei Felder, von denen jedes im gesunden Fall leer oder null sein darf, sagen
> einzeln nichts — erst das Paar sagt, ob ein Termin existiert.**

Die Unterscheidung ist `infinity`: `0` heisst „nicht dieses Feld", `infinity`
heisst „nie".

**M5 — die Monotonic-Spalte ist ein Versatz seit dem Kernel-Boot, kein Datum.**
Nachgerechnet statt geschlossen: `/proc/uptime` 638,92 s um 18:38:40 UTC ergibt
Boot 18:28:01; `list-timers` nennt für denselben Timer 19:36:11, und die Differenz
ist **1h 8min 10s** — auf die Nachkommastelle der gemessene Wert. Wer daraus ein
Datum machen will, braucht den Bootzeitpunkt dazu.

**M6 — `SubState` trennt in allen sieben gemessenen Fällen richtig**
(`waiting` = Termin da, `elapsed` = keiner). Das ist eine Korrelation über sieben
Fälle und keine Zusage; die belastbare Auskunft ist das Feldpaar. `SubState`
taugt als Beschriftung, nicht als Urteil.

---

## 4 · Zwei Messungen zur Umsetzung

**M7 — eine Eigenschaftsliste reicht für beide Unit-Arten.** Fragt man einen
**Dienst** nach `NextElapseUSecRealtime`, ist die Rückgabe `0` und die
Eigenschaft schlicht nicht in der Ausgabe. Kein Fehler, keine Meldung. Der Agent
kann also eine gemeinsame Liste schicken und die **Form der Antwort** entscheiden
lassen, was für eine Unit er vor sich hat — er braucht keine zweite Operation und
keine Fallunterscheidung vorher.

**M8 — `NRestarts` zählt wirklich.** Gegen einen Dienst mit `Restart=always` und
`ExecStart=/bin/sleep 1`: nach 3 s `1`, nach 6 s `2`, nach 9 s `4`. Das Feld, das
`ServiceStatus` seit P0 liest und niemand zeigt, trägt.

Dabei fiel ein Zustand heraus, den das Panel nie angezeigt hat: ein Dienst
zwischen zwei Neustarts meldet `ActiveState=activating` mit
`SubState=auto-restart`. Fünf Zustände statt der zwei, die die Übersicht kennt.

---

## 5 · Der Bestand im Code

| Stelle | Was sie heute kennt |
|---|---|
| `OverviewController` (Zeile 545) | drei feste Units: `srvpanel-agentd.service`, `nginx.service`, `mariadb.service` — dazu die Clusterunits aus `Clusters::unit()` |
| `ServiceAction::ALLOWED_UNITS` | vier Muster: `srvpanel-*`, `nginx.service`, `mariadb.service`, `php*-fpm.service` |
| `ServiceStatus::FIELDS` | neun Eigenschaften, keine davon zu einem Timer |
| Paketierung | **acht Dienste und vier Timer** — `agentd`, `web`, `worker`, `metrics`, `usage`, `tls`, `cron`, `dns` |

**Es fehlen also nicht „vier Timer", sondern neun der zwölf eigenen Units** —
und dazu `postgresql`, `ssh` und `cron`, die das Panel braucht und nicht betreibt.

**M9 — die vier Panel-Timer sind heute alle gesund gebaut.** Gemessen an den
Unit-Dateien: alle vier tragen `OnCalendar` **und** `Persistent=true`, drei davon
zusätzlich `OnBootSec`. Die Bauart, die am 19. August ihren Termin verlor —
ausschliesslich monotone Sockel —, steht nicht mehr im Repo. Was A2 baut, ist
deshalb keine zweite Fassung von `TimerRearmTest`:

> **Der Wächter fängt den Bau, die Anzeige fängt den Betrieb.** Ein Timer, den
> jemand von Hand stoppt, ist von aussen derselbe Zustand — und den fängt kein
> Wächter.

---

## 6 · Was diese Runde über sich selbst gelernt hat

**Der erste Durchgang hat ein falsches Ergebnis geliefert, und die Gegenprobe hat
es umgeworfen.** Gemessen war zuerst: Timer `mess-monoton.timer` steht auf
`elapsed`, also markiert `SubState` den Schaden. Beim Gegenprüfen stand derselbe
Timer auf `waiting` — weil der zweite Prüfkörper `mess-boot.timer` denselben
Dienst startete und `OnUnitActiveSec` an der letzten Aktivierung **des Dienstes**
hängt.

> **Zwei Prüfkörper, die sich einen Dienst teilen, messen einander.** Der Wert,
> den ich eine Minute vorher gemessen hatte, war danach ein anderer — und nichts
> daran sah nach einem Fehler aus.

Alle Zahlen in §3 stammen deshalb aus dem zweiten Durchgang, in dem jeder
Prüfkörper seinen eigenen Dienst hat.

**Und einer über die erste Schlussfolgerung.** Nach den ersten drei Zeilen der
Tabelle stand da: „leere Realtime-Spalte heisst kein Termin". Der Fall, der die
Regel entscheidet — der gesunde **monotone** Timer —, war zu dem Zeitpunkt nicht
gemessen, und er kehrt sie um.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.** Derselbe Satz wie in `docs/84` zu Punkt 8, hier an der anderen Hälfte:
> Es fehlte nicht der Fehlerfall, sondern der Erfolgsfall.

---

## 7 · Was offen bleibt

- **Warum `systemd --user` stumm mit 1 endet.** Die cgroup-Hierarchie ist es
  nicht. Für A2 belanglos, weil der Systemmanager trägt — hier benannt, damit es
  niemand ein zweites Mal vermutet.
- **`UnitFileState` der echten Panel-Units.** Hier stand bei allen Prüfkörpern
  `static`, weil sie in `/run` ohne `[Install]` lagen. Auf dem Server sind sie
  `enabled`; welche Werte sonst vorkommen (`disabled`, `masked`, `linked`), ist
  ungemessen und entscheidet, was die Spalte anbieten muss.
- **Wie ein von Hand gestoppter Timer aussieht** — `ActiveState=inactive` ist zu
  erwarten und nicht gemessen.
- **`postgresql`, `ssh` und `cron` auf dem Zielserver.** Ihre Unit-Namen sind
  distributionsabhängig (`ssh.service` gegen `sshd.service`), und geraten wird
  hier nicht.
