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
| `chmod 0644` | gemeldet — **siehe Befund 2** |
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

### Was dieser Punkt offen lässt

Die Auskunft über `uid` und `gid` stammt vom Agenten, also vom Prüfling. Sie
wird mit `id p1132` und `ls -ln` gegengeprüft — nicht, weil ein Betrug
unterstellt wird, sondern weil beide Zahlen sonst aus derselben Quelle kämen.

> **Eine Gegenprobe über denselben Weg wie die Messung prüft den Weg und nicht
> die Sache.** (`docs/44`)

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

---

*Die Punkte 4 bis 8 folgen, während sie gefahren werden.*
