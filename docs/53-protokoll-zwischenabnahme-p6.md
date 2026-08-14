# 53 — Das Protokoll der Zwischenabnahme von P6

**Gefahren auf `cloudsrv24` gegen `v0.6.0-rc.1`**, nach dem Lauf aus `docs/52`.
Dieses Dokument entsteht **während** des Laufs und nicht danach.

| Sache | Wert |
|---|---|
| Fassung | `v0.6.0-rc.1` |
| Server | `cloudsrv24` |
| Betriebssystem | Linux 6.8.0-137-generic (Ubuntu 24.04) |
| PHP | 8.4.24, SAPI `cli` |

---

## Punkt 1 — die Grenze, gemessen statt gelesen

**Rückgabewert `0`: Die Grenze hält, gemessen und gegengeprobt.**

| # | Abschnitt | Ergebnis |
|---|---|---|
| 1 | alle vierzehn Funktionen vorhanden | **ja** |
| 1 | läuft als root | ja |
| 2 | Wurzel `root:root 0755`, Inhalt dem Abo | ja |
| 3 | `uid` ist nicht 0 | ja — `uid=1005` |
| 3 | keine Gruppe 0 | ja — `gruppen=1005` |
| 3 | eine gültige Datei wird gelesen | ja — „innen" |
| 4 | Symlink auf `/etc/passwd` | hält |
| 4 | Symlink auf ein fremdes Verzeichnis | hält |
| 4 | `..`-Ausbruch | hält |
| 4 | absoluter Pfad | hält |
| 4 | `conf/` (`root:root 0640`) lesen | hält |
| 5 | Tausch während des Zugriffs | hält — **scharf 0, stumpf 2175 von 30 000** |
| 6 | Rückbau gegen den Tausch | hält — **scharf 0 von 60, stumpf 1 nach 4 Durchgängen** |
| 7 | ausserhalb der Vhost-Wurzel abgewiesen | ja |
| 7 | die Vhost-Wurzel selbst abgewiesen | ja |
| 7 | ein Systembenutzer, den es nicht gibt | abgewiesen |

### Der Befund, der in keiner Erwartung stand

**Das Zeitfenster ist auf dem echten Server fast dreimal so weit wie im
Container.** Gemessen mit demselben Skript, demselben Angreifer und derselben
Rundenzahl:

| Maschine | stumpf getroffen | Anteil |
|---|---|---|
| Entwicklungscontainer (Kernel 6.18) | 759 von 30 000 | 2,5 % |
| **`cloudsrv24` (Kernel 6.8)** | **2175 von 30 000** | **7,25 %** |

Dasselbe beim Rückbau: Im Container brauchte die Gegenprobe zwischen 5 und 68
Durchgängen, bis sie traf — hier **vier**.

Die Richtung war erwartet, die Grössenordnung nicht. `docs/52 §4` hatte
vorsorglich den umgekehrten Fall beschrieben (die Gegenprobe trifft dort
*seltener*, weil die Maschine langsamer ist) und dafür einen Ausweg vorgesehen.
Gebraucht wurde er nicht — im Gegenteil.

> **Eine Messung, die man vom Entwicklungsrechner auf den Zielserver überträgt,
> überträgt auch ihre Grössenordnung — und die stimmt nicht.** Der Fehler wäre
> hier zur harmlosen Seite gegangen; er hätte genauso gut andersherum liegen
> können.

Praktisch heisst das: Die Prüfung, die bis P6 im Rückbau stand, war auf dem
Produktivsystem **durchlässiger** als die 31 % aus `docs/50 §3` vermuten liessen
— nicht weniger.

### Was dieser Punkt nicht sagt

`cloudsrv24` ist **Ubuntu 24.04 mit PHP 8.4** — dieselbe Plattform wie der
Entwicklungscontainer. Die vierzehn Funktionen sind damit auf **einer** der vier
Zielplattformen belegt; Debian 12 (PHP 8.2), Debian 13 und Ubuntu 22.04
(PHP 8.1) bleiben ungemessen.

Das war der Hauptgrund, diese Zwischenabnahme vorzuziehen (`docs/52 §1`), und er
ist damit nur zu einem Viertel erledigt. Die vier „Installation auf …"-Läufe der
CI fahren auf allen vier Plattformen — dort gehört diese Prüfung hin.

---

## Punkt 2 — die Plattform selbst

**Nichts ist abgeschaltet.**

| Frage | Antwort |
|---|---|
| PHP | `8.4.24 (cli) (built: Jul 30 2026 15:23:13) (NTS)`, Built by Ubuntu |
| Zend Engine | `v4.4.24`, mit Zend OPcache `v8.4.24` |
| `disable_functions` | **leer** — `no value => no value` |

Damit steht die Grenze auf dieser Maschine nicht auf Wohlwollen: Die vierzehn
Funktionen sind nicht nur vorhanden (Punkt 1, Abschnitt 1), sie sind auch nicht
per `php.ini` gesperrt. Das ist die Hälfte, die Punkt 1 allein nicht beantwortet
— `function_exists()` sagt `false` für eine gesperrte Funktion genauso wie für
eine fehlende, und der Grund steht nur in der `php.ini`.

### Die Frage, die dieser Punkt offen lässt

**`php -v` in einer Root-Shell ist nicht zwangsläufig das PHP, unter dem der
Agent läuft.** Beides ist hier dieselbe CLI-Fassung der Distribution, aber
belegt ist das nicht — belegt ist, was `php` im `PATH` von root ist.

Der Beleg dafür wäre die Zeile, mit der der Dienst startet:

```bash
systemctl show srvpanel-agentd -p ExecStart | head -1
```

Sie ist **nicht gefahren**. Punkt 1 hat den Agenten selbst nicht gebraucht — das
Messskript bindet `agent/src/autoload.php` ein und läuft unter demselben `php`
wie `php -v`. Für die Grenze im Betrieb zählt aber das PHP des Dienstes.

> **Zwei Wege zu derselben Auskunft sind kein Beleg, solange nur einer gefahren
> ist.** Derselbe Satz wie bei der Gegenprobe über den Unix-Socket in `docs/44`.

Das bleibt als benannter Rest stehen und wird bei Punkt 3 mit beantwortet: Der
läuft über `srvpanel tinker`, also über das Panel und damit über den echten
Agenten. Gelingt dort eine Datei-Operation, ist die Frage praktisch beantwortet
— und zwar durch den Betrieb und nicht durch eine Auskunft über ihn.

---

## Befund 1 — der Lauf selbst, und zwar an einer Stelle, die zweimal aufgeschrieben war

**`srvpanel tinker` startet nicht.**

```
User Notice  Writing to directory .config/psysh is not allowed.
```

Der Wrapper setzt per `setpriv --reuid=srvpanel` um, `HOME` bleibt auf `/root`,
und psysh scheitert am Anlegen seines Einrichtungsverzeichnisses. Es ist eine
`User Notice` und kein Fehler — der Aufruf endet **mit Rückgabewert 0** und ohne
eine einzige Zeile Ergebnis.

Das ist nicht neu. Es steht in **`docs/48 §3.8`** als eigener Abschnitt, mit
demselben Satz:

> **Ein Werkzeug, das mit einer Warnung aufhört, sieht aus wie eins, das nichts
> zu sagen hatte.**

Und es steht in **`docs/47 §2`** als eine von zwei Fallen, die einen Lauf stumm
scheitern lassen. Die zweite dort ist die Mandantenklammer — und die stand in
`docs/52` genauso falsch: `Subscription::first()` ohne `allowAll()` ist `null`,
und der nächste Aufruf stirbt an einer Methode auf `null` statt an der Sache.
**Zwei bekannte Fallen, beide beim Schreiben von `docs/52` wieder hineingelegt.**

> **Eine Falle, die in zwei Protokollen steht, steht deshalb noch in keinem
> Lauf.** Das Aufschreiben und das nächste Schreiben sind zwei Handgriffe, und
> nur der erste hat stattgefunden.

Bemerkenswert ist die Bauart: Es ist derselbe Fehler wie der teuerste aus
`docs/45` — **eine Prüfung, die als Anweisung im Lauf steht und nie gefahren
wurde.** `docs/52` ist wie jeder Abnahmelauf hier Code, den niemand ausführt,
bis es darauf ankommt; die vier `tinker`-Blöcke darin waren zum Zeitpunkt des
Schreibens ungefahren, und drei von ihnen hätten dasselbe getan.

**Behoben**: Alle vier Blöcke in `docs/52` tragen jetzt `HOME=/tmp` und
`allowAll()`, und sie sind von `>>>`-Zeilen auf `--execute=` umgestellt — damit
gibt jeder Aufruf aus, was er getan hat, statt sich auf die Anzeige einer
interaktiven Sitzung zu verlassen.

Zwei Kleinigkeiten sind beim Nachsehen mitgegangen, beide aus derselben Familie
„eine Anweisung, die etwas anderes tut als gemeint":

- `ls -l` zeigt **Namen**; ein `uid=0` mit passend danebenstehendem Namen
  rutscht durch. Der Lauf misst jetzt mit `ls -ln`.
- `chown` ohne `-h` auf einem Symlink ändert das **Ziel**. Punkt 4 hätte
  `/etc/passwd` auf den Kunden umgeschrieben, und zwar auf dem Produktivserver.

---

## Punkt 3 — die Datei-Operationen an einem echten Abonnement

Gefahren gegen `test.invalid`, Systembenutzer `p1132`.

| Aufruf | Ergebnis |
|---|---|
| `list /` | `.ssh, conf, httpdocs, logs, mail, tmp` — die sechs aus §4.5 |
| `list /httpdocs` | `index.html` |
| `write /httpdocs/p6-probe.txt` | `created`, 6 Byte |
| `read` | `string(6) "Zeile\n"` — Byte für Byte das Geschriebene |
| `mkdir /httpdocs/p6-ordner` | angelegt, `mode` 488 = `0750` |
| `chmod 0600` | `vorher 644`, `nachher 600`, Rückbau meldet `mode => 384` |
| `copy` | `copied`, 6 Byte |
| `move` | `from: /httpdocs/p6-kopie.txt` |

**Alle acht gelingen, und keine der angelegten Dateien gehört root:** Jeder
Eintrag meldet `uid=1004 gid=1004`. Der Systembenutzer des Abonnements ist
`p1132`; dass das dieselbe Kennung ist, sagt die Auskunft des Agenten allein
nicht — sie wird ausserhalb der Sandbox nachgesehen.

### Was `read` nebenbei belegt

`var_dump` gibt `string(6)` — sechs Byte für `"Zeile\n"`. Das ist die
Gegenprobe zu einer Klasse von Fehlern, die dieses Projekt in P5c teuer bezahlt
hat: Ein Weg, der Inhalte über eine Textdarstellung schickt, verliert oder
verändert sie lautlos (`mysql --batch` und die Maskierung über der Maskierung,
`psql -A -t` und der Tabulator im Wert). Die Länge daneben ist der Unterschied
zwischen „es kam etwas an" und „es kam genau das an".

### Die Gegenprobe ausserhalb der Sandbox

Die Auskunft über `uid` und `gid` stammte vom Agenten, also vom Prüfling — und
beide Zahlen kämen sonst aus derselben Quelle.

> **Eine Gegenprobe über denselben Weg wie die Messung prüft den Weg und nicht
> die Sache.** (`docs/44`)

```
uid=1004(p1132) gid=1004(p1132) groups=1004(p1132)
-rw-r-----  1 1004   33  1109  index.html
drwxr-x---  2 1004 1004  4096  p6-ordner
-rw-r--r--  1 1004 1004     6  p6-probe.txt
-rw-r--r--  1 1004 1004     6  p6-verschoben.txt
```

`id` bindet die 1004 an `p1132`, `ls -ln` liest die Inode als root und ausserhalb
des Chroots. Damit ist belegt, was der Agent gesagt hat.

Und der Rückbau ist vollständig: `list /httpdocs` gibt danach nur noch
`index.html`.

**Und Punkt 2 ist damit praktisch beantwortet.** Diese Aufrufe sind über
`srvpanel tinker` durch das Panel und über den Unix-Socket in den **laufenden
Dienst** gegangen. Dass dort `pcntl_fork`, `chroot` und die Rechteabgabe
funktionieren, steht nicht mehr auf der Auskunft von `php -v` in einer
Root-Shell, sondern auf acht Vorgängen, die als `uid=1004` geschrieben haben.

## Befund 2 — ein `chmod`, das nichts gemessen hat

**`files.write` legt eine Datei mit `0644` an. Der Lauf hat danach `chmod 0644`
verlangt.**

Das ist im Ergebnis zu sehen und nur dort: Der Eintrag meldet `mode => 420`
**vor** und **nach** dem Aufruf — 420 dezimal ist `0644`. Der Aufruf hat den
Zustand hergestellt, in dem die Datei bereits war, Erfolg gemeldet und nichts
belegt. Wäre `files.chmod` eine leere Methode, sähe dieser Lauf genauso aus.

> **Ein Griff, der den Zustand herstellt, in dem die Sache schon ist, meldet
> Erfolg und misst nichts.**

Das ist dieselbe Familie wie der wiederkehrende Satz über die Null — nur ohne
Zahl, an der es auffiele: Hier steht neben der Messung nicht „0", sondern ein
gefüllter Ergebnisbaum, der nach Arbeit aussieht.

**Behoben**: `docs/52` verlangt jetzt `0600` — einen Wert, den vorher niemand
hatte — und die Rechteangabe vorher und nachher daneben. Nachgeholt wird die
Messung im Rückbauschritt.

Bemerkenswert ist, wie es hineingekommen ist: `0644` ist der Wert, den man für
eine Webdatei hinschreibt, ohne nachzudenken. Er war nicht falsch gewählt — er
war **gar nicht** gewählt.

## Befund 3 — der Dateimanager legt in einer anderen Gruppe ab als das Anlegen

**Gefunden im `ls -ln` zu Punkt 3, und zwar an der Zeile, die nicht von diesem
Lauf stammt.**

```
-rw-r-----  1 1004   33  index.html          ← beim Anlegen des Abos entstanden
-rw-r--r--  1 1004 1004  p6-probe.txt        ← über den Dateimanager entstanden
```

`33` ist `www-data`. `SubscriptionProvision::TREE` setzt `httpdocs` auf
`%u:www-data 0750` — der Webserver kommt über die **Gruppe** hinein, und
`index.html` mit `0640` ist für ihn lesbar, für alle anderen nicht.

Eine Datei aus dem Dateimanager trägt dagegen die Gruppe des Abonnements. Die
Sandbox setzt `posix_setgid($account['gid'])`, und keine der Datei-Operationen
fasst die Gruppe danach an. Lesbar ist so eine Datei für den Webserver nur über
das **Weltbit** in `0644`.

**Heute geht es gut, und genau das ist das Unangenehme daran.** Der Bruch tritt
in dem Moment ein, in dem jemand tut, wozu das Panel ausdrücklich einlädt:

> Ein Kunde setzt eine Datei auf `0640` — dieselben Rechte, die `index.html`
> trägt und die für sie funktionieren — und bekommt einen 403. Zwei Dateien
> nebeneinander, gleiche Rechteangabe, unterschiedliches Verhalten, und die
> Erklärung steht in einer Spalte, die die Rechteanzeige des Panels gar nicht
> zeigt.

> **Zwei Wege, die dieselbe Datei anlegen, müssen sie gleich anlegen — sonst ist
> die Rechteanzeige eine Auskunft über die Hälfte der Wahrheit.**

Es ist derselbe Bau wie der Fund aus `docs/38 §17` (`Hba::ensure()` und der
Fernzugriff): **ein zweiter Schreiber in demselben Verzeichnis, der die
Vereinbarung des ersten nicht kennt.** Dort war es eine Zeile in `pg_hba.conf`,
hier eine Gruppenkennung.

**Und SFTP hat dasselbe Problem** (`docs/51 §12`, Schritt 9): Auch dort schreibt
der Systembenutzer unter seiner eigenen Gruppe. Der Ort für die Entscheidung ist
deshalb nicht der Dateimanager, sondern die Frage, wie ein Abonnement seine
Dateien überhaupt ablegt — `setgid` auf `httpdocs` wäre die eine Antwort, ein
`chgrp` nach jedem Schreibvorgang die andere, und die zweite ist schlechter,
weil sie an jeder Stelle einzeln stehen müsste.

**Nicht behoben in diesem Lauf.** Er prüft die Grenze, und das hier ist keine:
Es kommt niemand irgendwohin, wo er nicht hindarf. Der Befund geht als eigener
Punkt in `docs/51` und wird vor dem Angriffsdurchgang entschieden.

## Punkt 4 — was scheitern muss

**Sechs von sechs abgewiesen**, jede mit ihrem Satz.

| Aufruf | Antwort |
|---|---|
| `read /../../../../etc/passwd` | `AgentException`: Die Datei gibt es nicht. |
| `read /etc/passwd` | dieselbe |
| `read /httpdocs/../../../../etc/passwd` | dieselbe |
| `remove /` | Die Wurzel des Abonnements wird über den Dateimanager nicht entfernt. |
| `remove /conf` | Das Verzeichnis ist nicht leer. — **siehe Befund 4** |
| `write /conf/gekapert.conf` | In dieses Verzeichnis darf das Abonnement nicht schreiben. |

**Dass die drei Lesezugriffe `not_found` sagen und nicht `denied`, ist Absicht
und die schärfere Antwort.** Ein `denied` bestätigt, dass es die Datei gibt;
`docs/48 §3.6` hat denselben Schnitt für die fremde Datenbank gemacht (404 statt
403). Im Chroot ist es ausserdem die *wahre* Antwort: `/etc/passwd` gibt es
innerhalb der Wurzel des Abonnements tatsächlich nicht.

### Der Symlink, und die Gegenprobe dazu

```
lrwxrwxrwx 1 1004 1004 11 … /httpdocs/raus -> /etc/passwd
root:x:0:0:root:/root:/bin/bash            ← cat, ausserhalb der Sandbox
daemon:x:1:1:daemon:/usr/sbin:/usr/sbin/nologin
```

`cat` liest über denselben Verweis `/etc/passwd` — der Verweis ist also
funktionsfähig, und die Abweisung darüber ist kein Tippfehler im Pfad.

```
[index.html] =>
[raus]       => /etc/passwd
abgewiesen — Nur eine Datei lässt sich öffnen.
```

**Die Auflistung nennt das Ziel.** Das ist gewollt: Ein Verweis, der als
gewöhnliche Datei angezeigt würde, wäre eine Anzeige, die etwas behauptet, das
sie nicht weiss — und der Kunde hat den Verweis selbst gelegt, es wird ihm also
nichts verraten.

## Befund 4 — eine Fehlermeldung, die den Grund behauptet, statt ihn zu erfragen

**`remove /conf` sagt „Das Verzeichnis ist nicht leer." Das ist geraten.**

```php
if (! @rmdir($path)) {
    throw AgentException::badRequest('Das Verzeichnis ist nicht leer.', …);
}
```

`rmdir` scheitert an `ENOTEMPTY`, `EACCES`, `EPERM` und `EBUSY`, und diese Zeile
sagt bei allen vieren dasselbe. Hier ist es `EACCES`: `conf/` gehört
`root:root 0755`, und zum Entfernen bräuchte man Schreibrecht auf dem
**Elternverzeichnis** — der Vhost-Wurzel, ebenfalls `root:root 0755`. Der Kunde
kommt an keines von beiden.

Der Satz ist also nicht nur ungenau, er ist **falsch und handlungsleitend
falsch**: Wer ihn liest, räumt `conf/` leer, um es danach löschen zu können.
Beides darf er nicht, und das zweite erfährt er erst nach dem ersten.

> **Eine Fehlermeldung, die einen von vier möglichen Gründen nennt, ist zu drei
> Vierteln eine Behauptung.**

Es ist dieselbe Familie wie `docs/44`: **Ein Wert, den nur die Dokumentation
kennt, ist eine Vermutung mit Fussnote** — hier kennt ihn nicht einmal die
Dokumentation, sondern nur die naheliegendste Erwartung des Schreibenden.

### Und deshalb hat dieser Aufruf die Wand nicht erreicht

**Der Lauf hat nicht belegt, dass `conf/` geschützt ist.** Er hat belegt, dass
ein nicht-leeres Verzeichnis ohne `recursive` nicht entfernt wird — eine ganz
andere Regel, die auch für `httpdocs/` gälte.

> **Ein Gegenfall, der eine Prüfung erreichen soll, muss an den davor
> vorbeikommen.** (`docs/48 §3.9`, wörtlich derselbe Fall)

Der Aufruf, der die Wand wirklich erreicht, ist `remove("/conf", true)`: Er geht
an der Leerheitsprüfung vorbei in `Filesystem::removeTree()` — und der läuft
innerhalb der Sandbox als `p1132`. **Er wird nachgeholt**, zusammen mit dem
Gegenstück, das die Leerheit ganz herausnimmt: ein leeres, root-eigenes
Verzeichnis in der Vhost-Wurzel.

### Punkt 4 nachgeholt — und Befund 4 ist damit gemessen statt gelesen

```
drwxr-xr-x 2 0 0 4096 … /var/www/vhosts/test.invalid/leer-und-root
abgewiesen: /leer-und-root rekursiv=0 — Das Verzeichnis ist nicht leer.
```

**Das Verzeichnis ist nachweislich leer** — frisch angelegt, Linkzahl 2 —, und
die Meldung sagt, es sei es nicht. Befund 4 stand bis hierher auf dem Lesen von
`FilesRemove::execute()`; jetzt steht er auf einer Ausgabe, die sich selbst
widerspricht.

> **Eine Fehlermeldung, die einen von vier möglichen Gründen nennt, ist zu drei
> Vierteln eine Behauptung** — und in diesem Lauf zu vier Vierteln falsch.

**Und die Wand hält, jetzt wo sie erreicht wurde:**

| Aufruf | Antwort |
|---|---|
| `remove /leer-und-root` rekursiv=0 | Das Verzeichnis ist nicht leer. (falsch, aber abgewiesen) |
| `remove /leer-und-root` rekursiv=1 | Das Verzeichnis liess sich nicht vollständig entfernen. |
| `remove /conf` rekursiv=1 | Das Verzeichnis liess sich nicht vollständig entfernen. |

Beide rekursiven Aufrufe gehen an der Leerheitsprüfung vorbei in
`Filesystem::removeTree()` und laufen dort als `p1132`. Der Kernel weist sie ab,
und die Nachprüfung (`Entry::of($path) !== null`) merkt es. Das Verzeichnisschema
steht danach unverändert:

```
drwxr-xr-x 2    0    0  conf
drwxr-x--- 2 1004   33  httpdocs
drwxr-xr-x 2    0    0  leer-und-root
drwxr-x--- 3 1004    4  logs
drwx------ 2 1004 1004  mail
drwx------ 2 1004 1004  tmp
```

**Das ist die Messung, um die es in diesem Punkt ging.** Sie war beim ersten
Anlauf nicht erreicht worden, und der Unterschied zwischen „abgewiesen" und
„abgewiesen aus dem richtigen Grund" ist genau der, den `docs/48 §3.9` benennt.

### Was dabei aufgefallen ist und nicht gemessen wurde

**Ein fehlgeschlagener rekursiver Rückbau ist nicht atomar.**
`Filesystem::removeTree()` läuft, bis der Kernel nein sagt — und das tut er erst
an dem Eintrag, der dem Kunden nicht gehört. Bei `/conf` und `/leer-und-root`
ist das der erste, es geschieht also nichts.

Bei **`/logs`** wäre es anders: Das Verzeichnis gehört `1004:4`, sein Inhalt dem
Kunden. Ein `remove("/logs", true)` räumte es leer und scheiterte danach am
`rmdir`, weil die Vhost-Wurzel root gehört. Der Kunde bekäme „liess sich nicht
vollständig entfernen" und hätte seine Protokolle verloren.

> **Ein Vorgang, der scheitert, nachdem er die Hälfte getan hat, meldet einen
> Fehlschlag und hinterlässt eine Wirkung.**

Das ist **keine Grenzverletzung** — der Kunde hat nur eigene Dateien verloren —
und deshalb hier nicht gemessen. Es gehört zu Schritt 6c dazu, wo ohnehin
entschieden wird, wie `httpdocs` und die Geschwister abgelegt werden.

---

## Punkt 5 — der Upload

**Die Prüfsumme stimmt, nicht nur die Grösse.**

| Sache | Wert |
|---|---|
| Quelle | `…/uploads/p6-gross.bin`, 64 MiB aus `/dev/urandom` |
| `sha256` Quelle | `1d34f23bfac627793c24e48ddea54344c8c5bf828de777eae8d68941a817116c` |
| `sha256` Ziel | **dieselbe** |
| Grösse am Ziel | 67 108 864 Byte |
| Eigentümer | `1004 1004` |

`/dev/urandom` statt `/dev/zero` war Absicht: Über einen abgebrochenen und mit
Nullen aufgefüllten Strom wäre die Prüfsumme sonst dieselbe wie über den ganzen.
64 MiB gehen dabei nicht durch den Arbeitsspeicher — der Agent öffnet die Quelle
vor dem `chroot` und schreibt den Strom im Kind weiter.

**Und die drei Quellen ausserhalb des Zwischenlagers sind abgewiesen**, `/etc/shadow`
eingeschlossen. Der dritte Fall ist der, der die Trennung belegt:
`…/storage/app/private/imports/` ist das Zwischenlager der Datenbanksicherungen
und nicht `Staging::ROOT`.

> **Zwei Positivlisten, die auf dasselbe Verzeichnis zeigen, sind eine
> Positivliste.** (`Files\Staging`)

## Befund 5 — die Abweisung verrät, ob es die Datei gibt

**Zwei Pfade, beide ausserhalb, zwei verschiedene Antworten:**

```
abgewiesen: /etc/shadow          — Pfad liegt außerhalb der erlaubten Verzeichnisse.
abgewiesen: /root/.ssh/id_rsa    — Pfad existiert nicht.
```

Der Unterschied ist nicht die Erlaubnis, sondern die **Existenz**.
`Guard::pathInside()` ruft zuerst `realpath()` und wirft `NOT_FOUND`, wenn das
fehlschlägt; die Prüfung gegen die erlaubten Wurzeln kommt erst danach. Damit
beantwortet die Fehlermeldung eine Frage, die der Aufrufer nicht stellen durfte:
*Gibt es diese Datei?*

**Das ist genau die Entscheidung aus Punkt 4, nur andersherum getroffen.** Dort
sagt der Dateimanager `not_found` für eine Datei, die es gibt, um ihre Existenz
nicht zu bestätigen — die schärfere Antwort. Hier sagt derselbe Quelltext
`denied` für die Datei, die es gibt, und `not_found` für die, die es nicht gibt.

> **Zwei Antworten auf dieselbe Frage in einem System, und sie widersprechen
> sich.** Beide wurden bewusst gewählt; nur nie nebeneinander.

**`Guard::pathInside()` ist nicht neu und nicht P6.** Die Bauform stammt aus P5
(`db.dump.import`), und der Reihenfolgefehler wird seitdem ausgeliefert.

**Erreichbar ist er heute nicht:** Die Quelle eines Uploads kommt im Panel aus
dem Zwischenlager und nicht vom Aufrufer — `FileController` reicht keinen
fremden Pfad durch. Es ist ein Orakel ohne Anschluss, und behoben gehört es
trotzdem, weil die nächste Operation, die `pathInside()` benutzt, es anschliessen
könnte, ohne es zu wissen.

**Und eine Kleinigkeit im Vorbeigehen**: Beide Meldungen dieses Laufs schreiben
„außerhalb" mit ß, während dieses Projekt sonst durchgehend „ss" setzt. Fünf
Stellen in `app/` und `agent/`, zwei davon in Texten, die der Kunde sieht
(`Guard.php`, `Acme\Dns\Rfc2136`). Ein Fall für `WordChoiceTest`, kein Fall für
diesen Lauf.

## Befund 6 — der Dateimanager ist gebaut und von nirgendwo aus erreichbar

**Gefunden durch eine Frage des Betreibers**, nicht durch einen Test: „Wo finde
ich den Dateimanager?"

Die elf Routen stehen in `routes/web.php`, jede mit ihrem `can:`, die drei
Seiten liegen unter `resources/js/Pages/Files/`, die Policy antwortet, der Agent
kann alles — und **kein einziges Vue-Template nennt `files.index` oder
`/files`.** Nachgesehen an drei Stellen:

| Ort | Enthält einen Weg zum Dateimanager |
|---|---|
| `PanelLayout.vue`, Navigation Kunde | nein (Übersicht, Abonnements, Domains, Datenbanken, Vorgänge, Protokoll, Mein Konto) |
| `PanelLayout.vue`, Navigation Betreiber | nein |
| `Subscriptions/Show.vue` | nein — dort stehen `dns`, `domains`, `edit`, `quota`, `suspend`, `resume` |

Erreichbar ist er ausschliesslich, indem man `/subscriptions/<id>/files` in die
Adresszeile tippt.

**Das ist der wiederkehrende Fehler dieses Projekts, nur andersherum.** Sonst
zeigt eine Zeichenkette auf etwas, das es nicht gibt — eine Policy ohne Route,
ein Kommando ohne Eintrag im Startskript. Hier gibt es die Sache, und es zeigt
nichts darauf.

> **Eine Seite, auf die nichts zeigt, ist nicht ausgeliefert — sie ist nur
> vorhanden.**

Und die vorhandenen Wächter konnten es nicht finden, weil jeder von ihnen die
andere Richtung prüft:

- `RouteAuthorizationTest` — jede Route trägt `can:`. **Trägt sie.**
- `PolicyReachTest` — jede Policy-Methode wird benutzt. **Wird sie.**
- `InertiaPagesTest` — jede gerenderte Seite existiert als Datei. **Existiert.**
- `AbilityReachTest` — ein Knopf, den der Betrachter nicht drücken darf, wird
  nicht gezeigt. **Wird er nicht — es wird gar keiner gezeigt.**

`AbilityReachTest` ist der, der am nächsten drankommt, und er sagt genau das
Falsche: Er sorgt dafür, dass nichts Unerlaubtes angeboten wird, und hat über
Erlaubtes, das nicht angeboten wird, nichts zu sagen.

> **Ein Wächter, der prüft, dass nichts Verbotenes gezeigt wird, hat über das
> Fehlende nichts gesagt.**

**Der Wächter dazu** gehört zu Schritt 5 nachgereicht und lautet: Jede Route mit
einer `can:`-Berechtigung, die eine Inertia-Seite rendert, wird von mindestens
einem Template verlinkt — oder steht mit Begründung auf einer Ausnahmeliste, wie
`RouteGuard` sie für den umgekehrten Fall führt. Die Ausnahmen sind echt (eine
Seite, die nur aus einer anderen heraus angesteuert wird, etwa `files.edit`),
und genau deshalb braucht es die Liste statt einer stillen Duldung.

**Nicht in diesem Lauf behoben**, weil dieser Lauf die Grenze prüft. Er geht als
Schritt 5b in `docs/51` und ist vor der Bilderrunde fällig — eine Seite, die
niemand aufrufen kann, lässt sich auch nicht fotografieren.

## Punkt 6 — der Rückbau, halb gemessen

Gefahren über den Browser und damit über den ganzen Weg: Route, Policy,
Warteschlange, Agent, Lebenslauf. Wegwerf-Abonnement `p6-weg.invalid`, über den
Dateimanager befüllt, danach zurückgebaut.

### Die Hälfte, die gemessen hat

```
37 /tmp/p6-benutzer-vorher.txt
diff /tmp/p6-benutzer-vorher.txt /tmp/p6-benutzer-nachher.txt   → leer
```

**Siebenunddreissig Systembenutzer vorher, siebenunddreissig nachher, und keine
Zeile verschoben.** Der Benutzer von `p6-weg.invalid` ist zwischendurch
entstanden und wieder verschwunden, und kein anderer hat sich dabei verändert.
Das ist eine Aussage, weil neben der Null etwas anderes als Null steht: 37
Zeilen, die gleich geblieben sind.

### Die Hälfte, die nichts gemessen hat

```
1 /tmp/p6-vorher.txt
diff /tmp/p6-vorher.txt /tmp/p6-nachher.txt   → leer
```

**Der Vorher-Stand hat eine einzige Zeile** — `/var/www/vhosts` selbst.
`find -maxdepth 2` hat darunter nichts gefunden; `test.invalid` war zu diesem
Zeitpunkt bereits fort, was das gleichzeitige `rmdir: failed to remove
'…/test.invalid/leer-und-root': No such file or directory` bestätigt.

Damit vergleicht der `diff` zwei Dateien, die beide nur den Namen des
Elternverzeichnisses enthalten. **Er ist leer, weil nichts da war, das hätte
verschwinden können** — nicht, weil der Rückbau sauber gearbeitet hat.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

Derselbe Satz wie bei `konsole = 0` in `docs/47` und bei der Entprellung in
`docs/48` — hier zum dritten Mal, und diesmal an der Stelle, an der der Fehler
Daten kostet. Die Frage dieses Punktes lautet: *Greift der Rückbau in etwas
hinein, das ihm nicht gehört?* Zum Beantworten braucht es etwas, in das
hineingegriffen werden könnte.

**Was dieser Lauf trotzdem belegt:** Das Verzeichnis des zurückgebauten
Abonnements ist weg, und der Systembenutzer ist weg. Das Kriterium „nichts
ausserhalb ist angefasst" ist **nicht** belegt.

**Nachzuholen** mit mindestens zwei Abonnements nebeneinander, von denen eines
stehenbleibt — und mit Inhalt in beiden, weil ein leeres Nachbarverzeichnis
denselben Fehler noch einmal machte.

## Punkt 8 (nebenbei) — der Dateimanager auf dem Telefon

Der Browserdurchgang zu Punkt 6 hat den Dateimanager bei 390 px gezeigt, und
zwei Dinge stehen damit fest, die bis dahin nur gebaut waren:

- **Der Weg vom Formular bis in die Sandbox trägt.** Ein Hochladen über die
  Fläche — mehrteilige Anfrage, Zwischenlager, Agent, `chroot`, Rechteabgabe —
  endet mit „Die Datei ist hochgeladen." und der Datei in der Liste. Bis hierher
  war dieser Weg nur über `tinker` gemessen.
- **Die Liste bricht bei 390 px in Kärtchen um**, mit Brotkrumen
  („Abo-Wurzel / httpdocs"), „… eine Ebene höher" und den drei Griffen je
  Eintrag. Ein Name von 34 Zeichen (`EA_3_Program_Terms-End_Users.pdf`) passt
  ohne Überlauf.

**Gemessen ist das nicht** — `scrollWidth - clientWidth` stand nicht daneben,
und ohne die Zahl ist ein Bild eine Vermutung mit Bild.

> **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite schiebt.
> Keines von beiden ersetzt das andere.** (`docs/46 §20.11`)

**Und Befund 3 ist auf dem Bild zu sehen**: Die Rechte stehen auf `rw-r--r--`.
Das Weltbit ist der einzige Grund, aus dem der Webserver diese Datei lesen kann
— die Gruppe ist die des Abonnements und nicht `www-data`.

### Das verschwundene Abonnement — nachgesehen und nicht geraten

Zwischen zwei Messungen war `test.invalid` fort, und `/var/www/vhosts/` stand
danach vollständig leer da. Drei Ausgänge waren denkbar, und der schlimmste wäre
gewesen, dass ein einziger Rückbau zwei Abonnements mitnimmt — genau der Fehler,
gegen den dieser Punkt misst.

**Es war Ausgang 1: ein eigener, regulärer Vorgang.**

```
602  test.invalid    db.database.remove   succeeded  18:58:51
603  test.invalid    pg.database.remove   succeeded  18:58:51
604  test.invalid    subscription.remove  succeeded  18:58:51
605  p6-weg.invalid  subscription.remove  succeeded  18:59:12
```

Zwei getrennte Rückbauten, einundzwanzig Sekunden auseinander, jeder mit eigenem
Protokolleintrag und eigenem Systembenutzer (`p1132` und `p1133`). Der Vorgang
für `test.invalid` hat vorher seine beiden Datenbanken abgeräumt — der reguläre
Weg, nicht der eines Baumlaufs, der sich verlaufen hat.

**Belegt hat das die Warteschlange und das Protokoll**, nicht eine Überlegung.
Beide überleben den Rückbau, und beide führen den Namen mit.

> **Ein Verdacht, der nach dem Beleg fragt, kostet eine Abfrage. Einer, der nach
> einer Erklärung fragt, kostet den Lauf.**

### Und der Verdacht stand auf einem Rechenfehler

**Zwei Uhren.** Die Shell zeigt Ortszeit (`ls` um 19:51, `/var/www/vhosts` mit
21:03), die Tabellen des Panels stehen in UTC. Aus `18:58:51` in der Tabelle und
`19:51` in der Shell wurde ein Fenster, in dem etwas fehlte — tatsächlich ist
`18:58:51 UTC` beim Betreiber `20:58:51`, also **nach** dem `ls` und **vor** der
Vorher-Aufnahme. Es fügt sich lückenlos.

> **Zwei Uhren in einem Protokoll sind eine Fehlerquelle und keine Auskunft.**

Das ist der Gegenstand von `docs/40`, und dieser Lauf ist mitten hineingelaufen
— beim Untersuchen eines vermuteten Datenverlusts, also an der Stelle, an der
ein Rechenfehler am teuersten ist. `Clock` löst das für die **Anzeige**; ein
Protokoll, das Ausgaben aus zwei Werkzeugen nebeneinanderlegt, hat davon nichts.

**Für den Lauf heisst das:** Zeiten aus der Shell und Zeiten aus dem Panel
gehören nicht nebeneinander in eine Zeile, ohne dass die Zone dabeisteht.

### Und was dabei mit abgeräumt wurde

`/var/www/vhosts/` ist leer. **Punkt 7 — der P5c-Bestand — entfällt damit**: Was
dort an Abonnements mit echtem Inhalt stand, ist fort. `test.invalid` trug
sowohl eine MariaDB- als auch eine PostgreSQL-Datenbank und war damit
vermutlich selbst dieser Bestand.

Das ist kein Verlust für diese Stufe — der Bestand war ein *Prüfling der
Bequemlichkeit*, kein Kriterium (`docs/52 §4`, Punkt 7). Es heisst aber, dass
Punkt 6 seinen Nachbarn selbst mitbringen muss und ihn nicht vorfindet.

## Punkt 6 nachgeholt — mit einem Nachbarn, und damit als Messung

Zwei Abonnements nebeneinander, beide befüllt: `p6-a.invalid` (`p1135`) und
`p6-b.invalid` (`p1136`). Zurückgebaut wurde **nur `p6-a.invalid`**.

**Der Bestand vor dem Rückbau, und diesmal steht neben der Null etwas anderes:**

```
31 /tmp/p6-vorher.txt
39 /tmp/p6-benutzer-vorher.txt
```

Einunddreissig Zeilen statt einer. Das ist der Unterschied zum ersten Anlauf und
der ganze Grund, warum der Punkt wiederholt wurde.

**Der Vergleich danach:**

```
2,16d1
< /var/www/vhosts/p6-a.invalid
< /var/www/vhosts/p6-a.invalid/conf
< /var/www/vhosts/p6-a.invalid/conf/p6-a.invalid.include
< /var/www/vhosts/p6-a.invalid/httpdocs
< /var/www/vhosts/p6-a.invalid/httpdocs/DHL-Paketmarke_PAKYEXH9DLYT_837770771.pdf
< /var/www/vhosts/p6-a.invalid/httpdocs/index.html
< /var/www/vhosts/p6-a.invalid/httpdocs/sn2info-import-vorlage.xlsx
< /var/www/vhosts/p6-a.invalid/httpdocs/test
< /var/www/vhosts/p6-a.invalid/logs
< /var/www/vhosts/p6-a.invalid/logs/p6-a.invalid
< /var/www/vhosts/p6-a.invalid/logs/p6-a.invalid/access.log
< /var/www/vhosts/p6-a.invalid/logs/p6-a.invalid/error.log
< /var/www/vhosts/p6-a.invalid/mail
< /var/www/vhosts/p6-a.invalid/.ssh
< /var/www/vhosts/p6-a.invalid/tmp
20d19
< p1135
```

**Fünfzehn Zeilen weg, alle unterhalb von `p6-a.invalid`. Kein `>` in beiden
Vergleichen. Genau ein Systembenutzer weniger, von 39.**

Die fünfzehn Zeilen von `p6-b.invalid` stehen unverändert da — nicht als
Behauptung, sondern als Rest: Von 31 Zeilen sind 16 geblieben, und der `diff`
nennt keine davon.

| Frage | Antwort |
|---|---|
| Ist das Verzeichnis weg? | ja, mit Inhalt |
| Ist der Systembenutzer weg? | ja, `p1135`, und nur er |
| **Ist der Nachbar unversehrt?** | **ja — keine Zeile aus `p6-b.invalid` im `diff`** |
| Ist etwas Neues entstanden? | nein, kein `>` in beiden Vergleichen |

**Das ist das Kriterium dieses Punktes**, und es ist das eine in P6, bei dem ein
Fehler Daten kostet statt eine Fehlermeldung zu erzeugen. Im Container hat
derselbe Baumlauf in 5 von 120 Durchgängen Dateien ausserhalb des Abonnements
zerstört (`docs/50 §3`); `Filesystem::purgeContents()` ist deshalb umgebaut
worden, und hier läuft der umgebaute Weg gegen einen echten Nachbarn.

### Was der Rückbau mitnimmt und mitnehmen soll

`conf/p6-a.invalid.include` gehört `root` und ist mit weg. Das ist richtig und
nicht dasselbe wie in Punkt 4: Dort scheitert der **Dateimanager** an `conf/`,
weil er als `p1135` in der Sandbox läuft. Der Rückbau ist ein anderer Vorgang
mit einer anderen Berechtigung und läuft als root ausserhalb der Sandbox.

> **Dieselbe Datei, zwei Vorgänge, zwei Antworten — und das ist keine
> Inkonsistenz, sondern der Unterschied zwischen „der Kunde räumt auf" und „der
> Betreiber baut zurück".**

---

*Punkt 8 folgt. Punkt 7 entfällt.*
