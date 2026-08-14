# 50 — Die Messrunde vor P6

**Die sieben Fragen aus `docs/49 §4`, gemessen statt nachgeschlagen.** Gefahren
am 14. August 2026 im Entwicklungscontainer (Ubuntu 24.04, Kernel 6.18.5,
PHP 8.4.19, OpenSSH 9.6p1) — jede Messung mit ihrer Gegenprobe daneben.

Sie steht als eigenes Dokument da und nicht als Absatz im Plan, weil ihr
Ergebnis den Plan bestimmt und nicht umgekehrt. Bei P5b und P5c hat je eine
solche Runde das Abnahmekriterium umgeworfen, bevor eine Zeile Code entstand.

> **Wissen aus zweiter Hand sieht aus wie Wissen.**

**Was diese Runde nicht ist:** eine Messung auf dem Zielserver. Der Container
ist Ubuntu 24.04 mit einem Kernel, den kein Kunde fährt. Was hier gemessen ist,
gilt für *diese* Plattform; §8 nennt, was auf `cloudsrv24` und den drei anderen
Zielplattformen nachzumessen bleibt.

---

## 1. Der Befund in einem Absatz

**Das heutige Muster verliert das Rennen in 31 % der Fälle**, und zwar nicht
theoretisch, sondern gemessen: 11 081 von 36 056 bestandenen Prüfungen haben
eine Datei *ausserhalb* der Grenze gelesen. **Die Abwehr, die hält, ist
`fork` + `chroot` + Rechteabgabe** — 0 Ausbrüche unter demselben Angreifer.
**`openat2(RESOLVE_BENEATH)` hält den Systemaufruf, aber nicht den Vorgang**:
Es gibt einen sicheren Deskriptor heraus, und PHP kann ihn nur über einen Pfad
wieder einlesen — dabei entsteht das Rennen ein zweites Mal.

---

## 2. Frage 1 — Kommt PHP an `openat2` mit `RESOLVE_BENEATH` heran?

**Ja, über FFI, und die Semantik stimmt.** `ffi.enable` steht auf `preload`,
was den Web-SAPI sperrt; im CLI-SAPI — und der Agent ist CLI — ist `FFI::cdef`
davon unberührt. Das Modul kommt auf Ubuntu 24.04 aus `php8.4-common`, ist also
kein Zusatzpaket.

`__NR_openat2 = 437`, `struct open_how { u64 flags; u64 mode; u64 resolve; }`,
24 Byte:

| Pfad relativ zum Wurzel-Deskriptor | `resolve` | Ergebnis |
|---|---|---|
| reguläre Datei | `RESOLVE_BENEATH` | geöffnet |
| Symlink → `/etc/passwd` | `RESOLVE_BENEATH` | `EXDEV` |
| Symlink → `drin.txt` (relativ, innen) | `RESOLVE_BENEATH` | geöffnet |
| Symlink → `drin.txt` (relativ, innen) | `+ RESOLVE_NO_SYMLINKS` | `ELOOP` |
| `../etc/passwd` | `RESOLVE_BENEATH` | `EXDEV` |
| `/etc/passwd` (absolut) | `RESOLVE_BENEATH` | `EXDEV` |
| **Symlink → `/etc/passwd`** | **`0` (Gegenprobe)** | **geöffnet — gefolgt** |

Die letzte Zeile ist der Grund, warum die fünf darüber etwas bedeuten.

---

## 3. Frage 2 — Was leistet `realpath()` unter Nebenläufigkeit?

**Es verliert.** Der Angreifer ist ein Prozess des Abonnements, der
`renameat2(…, RENAME_EXCHANGE)` in einer Schleife aufruft und damit ein
Verzeichnis und einen Verweis **atomar** tauscht — es gibt keinen Augenblick,
in dem der Name nicht existiert, und deshalb nichts, woran die Prüfung sich
stossen könnte.

Der Prüfling ist die Prüfung aus `Filesystem::removeInside`, wortgleich:
`is_link($p)`, dann `realpath($p) === $p`, dann zugreifen.

| Angreifer | Fenster | Prüfung bestanden | davon innen | davon **ausserhalb** |
|---|---|---|---|---|
| 4 Prozesse | 1000 µs künstlich | 17 | 11 | **6** |
| 4 Prozesse | **keins** | 36 056 | 24 975 | **11 081 (31 %)** |

**Zwei eigene Fehlmessungen davor gehören dazu**, weil sie zeigen, wie leicht
diese Frage ein falsches Grün bekommt:

1. Ein Angreifer aus `unlink`/`symlink`/`mkdir` statt `RENAME_EXCHANGE` traf in
   20 000 Runden **null** Mal. Er lässt den Namen zwischendurch verschwinden;
   die Prüfung weist dann ab, und was gemessen wird, ist seine Ungeschicktheit.
2. Der Versuch, das Fenster über einen **Baumlauf** zu verbreitern, traf in 400
   Läufen ebenfalls null Mal — der Denkfehler war, dass der Lauf zwar lange
   dauert, das Fenster *je Knoten* aber genauso kurz bleibt.

> **Ein Angriff, der nicht trifft, misst den Angreifer und nicht die Abwehr.**

---

## 4. Die Abwehren, gegeneinander gemessen

Derselbe Angreifer, dieselbe Rundenzahl, dieselbe Maschine. Nur die Abwehr
wechselt.

| Abwehr | innen | **ausserhalb** | |
|---|---|---|---|
| `realpath()` + `is_link` — **heute** | 10 652 | **4 697** | durchlässig |
| `chroot` auf die Abo-Wurzel | 20 986 | **0** | hält |
| `openat2(RESOLVE_BENEATH)` + `/proc/self/fd/N` | 14 531 | **850** | durchlässig |
| `openat2(RESOLVE_BENEATH)` + `read(2)` auf dem fd | 19 530 | **0** | hält |

**Die dritte Zeile ist der teuerste Fund dieser Runde**, und sie hat mich
zuerst glauben lassen, `openat2` sei undicht. Es ist das Gegenteil: Der
Systemaufruf hat **kein einziges Mal** ausserhalb aufgelöst — alle 34 947
Abweisungen waren `EXDEV`, und kein Deskriptor zeigte auf einen Pfad ausserhalb
der Grenze. Undicht war der **zweite Schritt**. PHPs Dateifunktionen nehmen
Pfade, keine Deskriptoren; wer einen sicher geöffneten fd an `file_get_contents`
geben will, muss über `/proc/self/fd/N` gehen — und das ist eine **zweite
Pfadauflösung** und damit dasselbe Rennen noch einmal.

Die vierte Zeile isoliert das: identischer Deskriptor aus identischem Aufruf,
gelesen über `read(2)` statt über `/proc` — 0 Ausbrüche.

> **Ein sicher geöffneter Deskriptor, der über einen Pfad wieder eingelesen
> wird, ist kein sicher geöffneter Deskriptor.**

Daraus folgt für den Bau: `openat2` schützt genau so viel, wie über FFI gelesen
und geschrieben wird. Ein Dateimanager, der es benutzen wollte, müsste
Lesen, Schreiben, Auflisten, Kopieren und Umbenennen **als root in FFI**
nachbauen. Das ist die grösste denkbare Angriffsfläche für den kleinsten Gewinn.

---

## 5. Warum `chroot` allein nicht genügt — und was es zur Schranke macht

`chroot` ist für **root** keine Schranke. Gemessen, mit der Gegenprobe direkt
daneben:

| Vorgehen | Ergebnis |
|---|---|
| roher `chroot(2)` als root, dann `chroot`/`chdir("..")` | **ausgebrochen — `/etc/passwd` gelesen** |
| derselbe Code nach `setgid`/`setuid` auf den Abo-Benutzer | eingesperrt |

**PHPs `chroot()` verdeckt das**, weil es hinterher selbst `chdir("/")` macht
und dem klassischen Ausbruch damit den Hebel nimmt — der erste Messversuch
meldete deshalb fälschlich „eingesperrt" für root. Darauf darf sich nichts
stützen: Das ist eine Eigenheit der Implementierung und keine Zusage.

> **Was der Geprüfte selbst zurücknehmen kann, ist keine Schranke.** Root kann
> `chroot` zurücknehmen. Der Abo-Benutzer kann es nicht.

**Und die Rechteabgabe hat eine Lücke, die PHP nicht schliessen will.**
`posix_setgroups()` gibt es nicht. Ein Kind, das nur `setgid` und `setuid`
aufruft, behält die **Zusatzgruppen von root**:

| Rechteabgabe | Gruppen des Kindes | `root:root 0640` lesen |
|---|---|---|
| `setgid` + `setuid` | `[0, 4, 27]` — die von root | **gelesen** |
| `posix_initgroups()` davor | `[1002]` — die des Abos | verweigert |

`posix_initgroups()` gibt es, und es reicht. Ohne die erste Zeile wäre die
zweite kein Beleg — im Container hat root eine **leere** Zusatzgruppenliste,
und die erste Messung sah deshalb sauber aus, obwohl sie nichts geprüft hatte.

> **Ein Rechtewechsel ohne `initgroups` ist kein Rechtewechsel.**

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht** — hier hat die Null erst durch `setpriv --groups=0,4,27` am
> Elternprozess einen Nachbarn bekommen.

---

## 6. Frage 3 und 4 — Was OpenSSH wirklich verlangt

Gemessen gegen OpenSSH 9.6p1 mit dem Schema aus `docs/20 §4.5`, echter `sshd`
auf Port 2222, echte SFTP-Anmeldung.

| Zustand | Anmeldung |
|---|---|
| **Schema aus §4.5 unverändert** (Wurzel `root:root 0755`) | **verbunden** |
| Wurzel gehört dem eingesperrten Benutzer | abgewiesen |
| Wurzel `0775` (gruppenschreibbar) | abgewiesen |
| `/var/www/vhosts` auf `0777` | abgewiesen |
| `/var/www/vhosts` gehört dem Benutzer | abgewiesen |
| **zurückgesetzt (Gegenprobe)** | **verbunden** |

OpenSSH prüft also die **ganze Kette oberhalb** der Wurzel, nicht nur die
Wurzel. Das Schema aus §4.5 hält; es ist seit P2 richtig gebaut.

**Der Client erfährt den Grund nicht.** Er bekommt `Broken pipe`; die Auskunft
steht ausschliesslich im Serverprotokoll:

```
bad ownership or modes for chroot directory "/var/www/vhosts/beispiel.de"
```

Wer diesen Zugang baut, zeigt diese Zeile — sonst sucht der Betreiber im
falschen Ende.

**Frage 4, gemessen im selben leeren Chroot:**

| Zugang | Ergebnis |
|---|---|
| `internal-sftp` | **geht** — keine Binärdatei nötig |
| Shell (`/bin/bash`) | `/bin/bash: No such file or directory` |

Ein SSH-Zugang mit Shell verlangt also ein **bewohnbares** Chroot: Shell,
Bibliotheken, `/dev/null`, `/etc/passwd`. Das ist eine andere Baustelle als
`docs/20 §4.5` beschreibt und je Abonnement zu unterhalten.

---

## 7. Frage 5 und 6 — Cron und FTP im Archiv

Ubuntu 24.04, `apt-cache policy`:

| Paket | Kandidat |
|---|---|
| `cron` | 3.0pl1-184ubuntu2 |
| `cronie` | 1.7.1-1 |
| `systemd-cron` | 2.3.2-1build1 |
| `vsftpd` | 3.0.5-0ubuntu3.1 |
| `pure-ftpd` | 1.0.50-2.2build2 |
| **`proftpd-basic`** | **(none) — nicht im Archiv** |

`cron` liefert `/usr/bin/crontab` und `/etc/cron.d`. Dass `proftpd` fehlt, ist
der erste harte Punkt für Frage 6: Der Plan darf ihn nicht voraussetzen.

**Welcher Dienst tatsächlich läuft, ist damit nicht beantwortet** — im
Container läuft keiner. Das gehört auf den Zielserver (§8).

---

## 8. Was hier nicht messbar war und auf `cloudsrv24` gehört

Der Container ist Ubuntu 24.04. Vier Zielplattformen sind es nicht.

1. **Ist `openat2` auf den Zielkerneln nicht durch seccomp gesperrt?** Der
   Systemaufruf ist ab Linux 5.6 da, und alle vier Plattformen liegen darüber —
   aber das ist Wissen aus zweiter Hand und misst nicht, was ein Container oder
   ein Hardening-Profil davon durchlässt. *(Nachrangig, solange der Bau ihn
   nicht braucht — siehe §4.)*
2. **Liefert das PHP-Paket jeder Plattform FFI mit?** Hier kommt es aus
   `php8.4-common`. Für Debian 12 (8.2), Debian 13, Ubuntu 22.04 (8.1) ist das
   ungemessen. *(Ebenfalls nachrangig, wenn FFI nicht gebraucht wird.)*
3. **Welcher Cron-Dienst ist auf jeder Plattform installiert**, und läuft er?
4. **Wem gehört `/var/www/vhosts` auf dem laufenden Server**, und mit welchen
   Rechten? §6 zeigt, dass eine Abweichung den SFTP-Zugang wortlos kostet.
5. **Hält die Messung aus §3 und §4 auch dort?** Das Rennen hängt an Kernen,
   Last und Dateisystem. 31 % sind ein Wert dieser Maschine, keine Konstante.

---

## 9. Was daraus für den Plan folgt

**Die Grenze, die den weggefallenen Schutz ersetzt, ist ein Prozess ohne
Rechte in einem Chroot** — nicht eine bessere Pfadprüfung.

```
fork()                                  im Agenten, als root
  chroot(<abo-wurzel>)                  die Grenze, gesetzt von root
  posix_initgroups(<abo-benutzer>)      sonst bleiben root-Gruppen stehen
  posix_setgid() / posix_setuid()       ab hier ist chroot nicht rücknehmbar
  … gewöhnliche PHP-Dateifunktionen …   der Pfad kann nichts draussen bezeichnen
```

Fünf Eigenschaften, jede einzeln gemessen:

1. **Sie hält das Rennen aus** — 0 Ausbrüche gegen einen Angreifer, der die
   heutige Prüfung in 31 % der Fälle schlägt (§4).
2. **Sie braucht kein FFI**, also keine Datei-Ein- und -Ausgabe als root (§4).
3. **Sie braucht kein neues Programm auf der Positivliste.** `Runner.php`
   verbietet `setpriv`, `runuser`, `su` und `sudo` ausdrücklich und mit
   Begründung; `pcntl_fork` und `posix_*` laufen im Prozess und rühren die
   Liste nicht an.
4. **Sie läuft als der Kunde**, also gelten seine Rechte und seine Quota, ohne
   dass irgendetwas sie nachbauen müsste.
5. **Der Kunde kann sie nicht zurücknehmen** — das ist der Unterschied zu jeder
   Grenze, die in seinem eigenen Prozess durchgesetzt würde.

Der Dateimanager nimmt damit sehr wohl einen Pfad vom Kunden entgegen — aber er
deutet ihn **innerhalb eines Chroots**, und dort kann kein Pfad etwas
ausserhalb bezeichnen. Das ist die gleichwertige Ersetzung, nach der
`docs/49 §2` verlangt.

**Was das nicht löst und im Plan stehen muss:** Der Baumlauf in
`Filesystem::removeTree` läuft weiter als root und ausserhalb eines Chroots.
Solange dort Prozesse des Abonnements laufen können, gilt §3 auch für ihn.
