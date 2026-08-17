# 59 — Protokoll der Zwischenabnahme des SFTP-Zugangs

Der Lauf nach `docs/58`, auf `cloudsrv24`, gegen `v0.6.0-rc.10`.

**Dieses Dokument entsteht während des Laufs.** Was hier steht, ist gemessen;
was noch nicht gemessen ist, steht als offen da und nicht als erwartet. Ein
Protokoll, das im Voraus geschrieben wird, hält fest, was jemand erwartet hat.

| | |
|---|---|
| Datum | 16. August 2026 |
| Fassung | `v0.6.0-rc.10` |
| Stand auf `main` | `7ff3096` (PR #138) |
| Gefahren von | Betreiber auf `cloudsrv24`; Auswertung hier |

---

## 0. Der zweite Weg hinein

| | Zustand |
|---|---|
| 0a Konsole beim Anbieter offen **und benutzt** | **erledigt** |
| 0b zweite root-Sitzung offen | **erledigt** |
| 0c `sshd_config` gesichert, Prüfsumme notiert | **erledigt** |

**0a:** netcup-Konsole („Bildschirm"), `cloudsrv24 tty1`, als root angemeldet,
`asd` getippt, `-bash: asd: command not found` gesehen. Ein lokaler Bildschirm
und keine SSH-Sitzung — also ein Weg hinein, der nicht durch die Tür führt, die
dieser Lauf zusperren kann. Ubuntu 24.04.4 LTS, Kernel 6.8.0-137-generic,
Konsolenzeit Mo 17. Aug 09:00 CEST 2026.

**0b:** Eine zweite root-Sitzung über SSH ist offen und bleibt es (Angabe des
Betreibers).

**0c, gemessen am 16. August 2026 auf `cloudsrv24`:**

```
sha256sum /root/sshd_config.vor-p6 /etc/ssh/sshd_config
2b5a070ed8513f847086e21be1eb50fa1fca79c65782b2b85ef2b0dbcdf56852  /root/sshd_config.vor-p6
2b5a070ed8513f847086e21be1eb50fa1fca79c65782b2b85ef2b0dbcdf56852  /etc/ssh/sshd_config
```

Beide gleich — die Sicherung ist treu, und der Wert ist der Bezug für Punkt 3
und Punkt 8. **Er ist nach dem Einspielen des Pakets genommen und nicht davor**
(siehe Abweichung unten); für die Frage, die Punkt 3 stellt — schreibt das
blosse Ansehen der Seite in die Datei? — ist genau das der richtige Zeitpunkt.

**0a und 0b sind nicht belegt.** Die Aufnahme zeigt eine Sitzung mit einer
root-Eingabeaufforderung; ob es die Konsole des Anbieters oder eine
SSH-Sitzung ist, geht daraus nicht hervor, und ob eine **zweite** Sitzung offen
bleibt, ebenfalls nicht. Nachgefragt statt angenommen — der ganze Punkt 0
besteht daraus, das nicht zu tun.

### Ein Befund aus Punkt 0, den niemand gesucht hat

Die Konsolenaufnahme zeigt im Kundenbereich den Hinweis **„Neustart
erforderlich"**. Das ist für diesen Lauf keine Nebensache:

| | |
|---|---|
| kaputte Datei + **Reload** | der sshd terminiert (`docs/57 §5`) |
| kaputte Datei + **Neustart** | der Dienst kommt gar nicht hoch (`docs/38`, M17) |

Punkt 8 stellt absichtlich für einen Augenblick einen Zustand her, in dem
`sshd_config` eine unsinnige Zeile trägt. Ein Neustart in genau diesem Fenster
— ausgelöst von einem Paketlauf, einem Wartungsfenster des Anbieters oder
versehentlich — macht aus einem geprüften Zustand einen Ausschluss.

> **Ein ausstehender Neustart ist eine geladene Waffe, die auf den Zeitpunkt
> wartet, an dem jemand anders abdrückt.**

**Auflage für den Rest des Laufs:** kein Neustart, und vor jedem Neustart, der
sich nicht vermeiden lässt, muss `sshd -t` still sein.

---

## Abweichung vom geschriebenen Lauf

`docs/58` Punkt 1 heisst „vor dem Update"; das Paket `v0.6.0-rc.10` war beim
Beginn dieses Laufs bereits eingespielt. Für die Messrunde ist das folgenlos —
sie misst OpenSSH auf dieser Maschine und nicht das Panel. Für 0c heisst es:
Der Bezugswert ist „nach dem Einspielen, **vor der ersten Benutzung**".

Festgehalten statt geradegebogen.

## 1. Die Messrunde vor dem Update

**Befund 1 — der Lauf verlangt ein Werkzeug, das die Auslieferung nicht
mitbringt.** `/opt/srvpanel/current` enthält `agent`, `app`, `artisan`,
`bootstrap`, `config`, `database`, `lang`, `public`, `resources`, `routes`,
`storage`, `vendor` — **kein `tests/`**. Das Paket liefert die Anwendung aus
und nicht die Testsuite, und das ist richtig so.

`docs/58` Punkt 1 lautet `sudo bash tests/sftp-messen.sh`, ausgeführt im
Installationsverzeichnis. Der Schritt war damit **nie fahrbar** — nicht
„gescheitert", sondern von Anfang an unausführbar, und niemandem ist es
aufgefallen, weil ihn bis heute niemand ausgeführt hat.

> **Ein Abnahmelauf, der ein Werkzeug voraussetzt, das die Auslieferung nicht
> enthält, ist an dieser Stelle nicht gefahren worden — er war nie fahrbar.**

Dasselbe Verhältnis wie in `docs/45`, `docs/47` und `docs/48`: Die Mehrheit der
Fehler steckt im Prüfmittel und nicht im Prüfling.

**Behoben für diesen Lauf** durch Holen des Skripts aus dem öffentlichen Repo;
das Skript hängt an keinem Pfad des Repos und läuft von überall.
`docs/58` bekommt den Schritt nachgetragen.

### Die Messung selbst: **42 wie erwartet, 0 abweichend**

Gefahren am 17. August 2026 auf `cloudsrv24`, gegen das Skript aus `main`
(`sha256 876aa368…c134`, gegengeprüft). Alle zehn Gruppen grün — der Zugang
überhaupt (M6/M9/M10), das eigene `authorized_keys` des Kunden (M7), beide
Ketten (M8), wo der Block stehen darf (M1/M2), das Drop-in (M3), was `sshd -t`
sieht und was nicht (M4/M5), die Einschleusung, das Neuladen (M11/M12) und der
Schlüssel selbst (M13).

**Und die Fassung ist zeichengleich mit der im Container gemessenen:**

```
OpenSSH_9.6p1 Ubuntu-3ubuntu13.18, OpenSSL 3.0.13 30 Jan 2024
```

`docs/57` misst damit nicht eine ähnliche Fassung, sondern **dieselbe**. Die
42 Messungen übertragen sich exakt statt nur sinngemäss — insbesondere die
drei, die diesen Schritt tragen: Ein Neuladen mit kaputter Datei tötet den
Dienst; ein `Match`-Block hat kein Ende, nur einen Nachfolger; die
Schlüsseldatei hat eine zweite Kette.

### `docs/50 §8` Punkt 4 — beantwortet

Seit der Messrunde vor P6 offen: wem `/var/www/vhosts` auf dem laufenden
Server gehört. Gemessen, nur gelesen und nie geändert:

| Pfad | Eigentümer | Rechte | Urteil |
|---|---|---|---|
| `/` | root | 755 | taugt |
| `/var` | root | 755 | taugt |
| `/var/www` | root | 755 | taugt |
| `/var/www/vhosts` | root | 755 | taugt |
| `/etc/ssh` | root | 755 | taugt |
| `/etc/ssh/sshd_config` | root | 644 | taugt |
| `/var/www/vhosts/p6-b.invalid` | root | 755 | taugt |

Die Kette trägt. Eine Abweichung hätte jeden SFTP-Zugang gekostet, und zwar
wortlos.

---

## 2. Fassungen

| | Wert |
|---|---|
| `srvpanel --version` | **0.6.0-rc.10** — wir prüfen, was wir zu prüfen glauben |
| `sshd -V` | OpenSSH_9.6p1 Ubuntu-3ubuntu13.18, OpenSSL 3.0.13 |
| `systemctl is-active ssh.service` | active |
| `systemctl is-active sshd.service` | active (Alias derselben Unit) |
| `systemctl is-enabled ssh.socket` | **enabled** |
| `/etc/srvpanel/ssh` | gibt es noch nicht |
| Prüfsumme `sshd_config` | `2b5a070e…6852`, unverändert seit 0c |

**Beide Unitnamen melden `active`**, weil `sshd.service` ein Alias von
`ssh.service` ist. `SftpAccess::reload()` geht seine Liste der Reihe nach durch
und nimmt die erste, die nicht `unknown` sagt — also `ssh.service`. Das ist die
gewollte Wahl, und sie ist hiermit gemessen statt angenommen.

**Und `ssh.socket` ist eingeschaltet, während der Dienst gleichzeitig läuft.**
Für Punkt 9 heisst das: Der Zustand „Dienst ruht" muss erst hergestellt werden,
und das ist bei offenen Sitzungen nicht folgenlos — der Punkt bekommt seine
eigene Vorsicht, wenn er dran ist.

---

## 3. Ohne Schlüssel ist der Zugang aus — und die Datei unberührt

**Die Hauptaussage hält.**

| | gemessen |
|---|---|
| `sha256sum /etc/ssh/sshd_config` | `2b5a070e…6852` — **unverändert gegenüber 0c** |
| `/etc/srvpanel/ssh` | gibt es nicht |

Das blosse Ansehen der Seite schreibt nicht in `sshd_config`. Das war die
eigentliche Frage dieses Punktes, und sie ist beantwortet.

**Der Wortlaut der Seite hält nicht.** Sie meldet den Zustand „noch nichts
eingerichtet" als Defekt — drei Befunde, alle in derselben Fläche, alle
derselbe Denkfehler.

---

## 4. Der erste Schlüssel

**Der Block, gemessen auf `cloudsrv24` gegen `v0.6.0-rc.10`:**

```
# BEGIN srvpanel — verwaltet, nicht von Hand ändern
Match User p1136
    ChrootDirectory /var/www/vhosts/p6-b.invalid
    ForceCommand internal-sftp -u 0027
    AuthorizedKeysFile /etc/srvpanel/ssh/p1136
    PasswordAuthentication no
    PermitTTY no
    AllowTcpForwarding no
    X11Forwarding no
Match all
# END srvpanel
```

| erwartet | gemessen |
|---|---|
| Block **am Ende** der Datei | erfüllt — nichts steht dahinter |
| letzte Zeile des Bereichs ist `Match all` | erfüllt, **innerhalb** des Bereichs und vor `# END` |
| `sshd -t` sagt nichts | `rc=0` |
| Schlüsseldatei `root:root 0644` | `-rw-r--r-- 1 root root 311 Aug 17 10:35 p1136` |
| `chrootdirectory` (effektiv) | `/var/www/vhosts/p6-b.invalid` |
| `forcecommand` (effektiv) | `internal-sftp -u 0027` |
| `authorizedkeysfile` (effektiv) | `/etc/srvpanel/ssh/p1136` |
| `authenticationmethods` (effektiv) | **`any`** — Befund 6, in dieser Fassung erwartet |

Die 311 Byte sind die drei Kopfzeilen von `SftpKeyApply` (rund 210) plus die
eine Schlüsselzeile mit `restrict` davor. Eine Zeile mehr, als das Panel zeigt,
wäre hier sichtbar.

**Und die Zeilen unmittelbar über dem Bereich belegen die Voraussetzung von
Befund 6 auf dem echten Server:**

```
PasswordAuthentication yes
PermitRootLogin yes
```

Der Kopfteil, gegen den `docs/57 §13b` gemessen hat, ist damit nicht
hypothetisch — er ist der Bestand dieser Maschine. Genau darum bot der Server
`keyboard-interactive` neben dem Schlüssel an.

### Die Anmeldung, gemessen von einem Windows-Rechner

| erwartet | gemessen |
|---|---|
| Anmeldung gelingt | `Connected to cloudsrv24.de.` |
| `pwd` ist `/` | `Remote working directory: /` |
| `ls -a` zeigt die sechs Verzeichnisse | `. .. .ssh conf httpdocs logs mail tmp` |
| **Gegenprobe:** ein fremder Schlüssel wird abgewiesen | `Permission denied (publickey).` |
| Fingerabdruck der Datei == der aus `ssh-keygen` | `SHA256:PBMiXFViiL…RGfT8`, zeichengleich |

**Die Gegenprobe scheitert an `publickey` und nicht an einem Passwort.** Damit
ist Befund 5 in derselben Ausgabe mitbelegt: Der Weg, den sie prüft, ist der
Weg, der geprüft werden soll.

**Und `pwd` ist `/` und nicht `/var/www/vhosts/p6-b.invalid`** — das Chroot
steht, und zwar aus der Sicht des Kunden. Die zweite Kette dazu, gemessen:

| Pfad | Eigentümer | Rechte |
|---|---|---|
| `/` | root | 0755 |
| `/etc` | root | 0755 |
| `/etc/srvpanel` | root | 0755 |
| `/etc/srvpanel/ssh` | root | 0755 |

**Ein Nebenbeleg, der nicht auf der Liste stand:** Die Schlüsselzeile trägt die
Beschriftung `TestWin11` aus dem Panel und nicht das `abnahme`, das
`ssh-keygen -C` in die `.pub` geschrieben hat. `PublicKey::comment()` ersetzt
den Kommentar statt ihn zu übernehmen — zwei Auskünfte über dasselbe wären eine
zu viel —, und das ist hiermit an einer echten Datei gesehen.

### Das Hochladen — setgid und umask zusammen

```
Uploading .ssh/p6-sftp.pub to /httpdocs/probe.txt
-rw-r----- 1 p1136 www-data 90 Aug 17 10:52 …/httpdocs/probe.txt
```

| erwartet | gemessen |
|---|---|
| Eigentümer `p1136` | erfüllt |
| Gruppe `www-data` | erfüllt — das setgid-Bit an `httpdocs` |
| Rechte `0640` | erfüllt — `-u 0027` am `internal-sftp` |

**Das ist die einzige Zeile des Laufs, die beide Mechanismen zusammen prüft.**
Jeder allein ergibt etwas, das „irgendwie richtig" aussieht: Ohne das setgid-Bit
gehörte die Datei der Gruppe `p1136`, und der Webserver käme nicht an sie heran.
Ohne `-u 0027` wäre sie `0644` und für **jeden** Systembenutzer lesbar — die
Rechnung aus `docs/53` Befund 3, hier am zweiten Weg hinein.

Und der Zielpfad in der Meldung des Klienten heisst `/httpdocs/probe.txt` und
nicht `/var/www/vhosts/p6-b.invalid/httpdocs/probe.txt`: dasselbe Chroot noch
einmal, aus der Sicht des Programms statt aus der des Benutzers.

**Punkt 4 ist damit erfüllt.** Offen bleibt allein ein Bild der gefüllten
Schlüsseltabelle — Art, Bitzahl und Fingerabdruck in der Anzeige.

---

## 5. Der Kunde legt sich selbst einen Schlüssel hin

Über den gewonnenen Zugang hochgeladen, mit dem fremden Schlüssel darin:

```
Uploading .ssh/p6-fremd.pub to /.ssh/authorized_keys
-rw-r----- 1 p1136 p1136 93 Aug 17 10:55 …/.ssh/authorized_keys
```

| erwartet | gemessen |
|---|---|
| Die Anmeldung mit diesem zweiten Schlüssel scheitert | `Permission denied (publickey).` |
| Die Datei ist wirklich da | 93 Byte, `p1136:p1136` — **die Null neben etwas anderem als Null** |
| Im Panel steht er nicht | die Tabelle zeigt weiterhin nur `TestWin11` |

**Ohne die zweite Zeile hätte der Punkt nichts geprüft.** Ein fehlender
Schlüssel funktioniert auch nicht; die Aussage entsteht erst daraus, dass die
Datei existiert, lesbar ist und trotzdem nicht gilt. `AuthorizedKeysFile`
**ersetzt** die Vorgabe, es ergänzt sie nicht (`docs/57 §4`) — hier auf einem
echten Server statt im Container.

### Zwei Belege, die nicht auf der Liste standen

**1. Im Chroot gibt es keine Namen.** `ls -l .ssh` über SFTP zeigt

```
-rw-****** ? 1001 1001 93 Aug 17 10:55 .ssh/authorized_keys
```

Eigentümer und Gruppe stehen als Zahlen da, weil es im Chroot kein
`/etc/passwd` gibt, aus dem `p1136` aufzulösen wäre. Das ist ein Beleg für das
Chroot, den kein Kriterium verlangt hat: Der Kunde sieht nicht nur einen
beschnittenen Baum, er sieht auch die Namensauflösung nicht mehr.

**2. Das Protokoll des Servers nennt denselben Fingerabdruck wie das Panel.**

```
Accepted publickey for p1136 … ED25519 SHA256:PBMiXFViiL6KV95VXw7J2Kz6hRcCuhXPDzMXrmRGfT8
Connection reset by authenticating user p1136 … [preauth]
```

Damit steht die Fingerabdruckliste des Panels gegen eine **dritte** Quelle:
`ssh-keygen -lf` auf dem Klienten, `ssh-keygen -lf` auf der Schlüsseldatei, und
der `sshd` selbst im Augenblick der Anmeldung. Die abgewiesene Anmeldung steht
zwei Zeilen darunter als `[preauth]` — kein `Accepted` mit einem anderen
Fingerabdruck, sondern gar keines.

---

## 6. Die Ablehnung wird sichtbar — das Kriterium aus `docs/51 §9`

**Der Defekt gesetzt:**

```
chown p1136:p1136 /var/www/vhosts/p6-b.invalid
drwxr-xr-x 8 p1136 p1136 4096 Aug 14 21:49 /var/www/vhosts/p6-b.invalid
```

**Was der Kunde sieht:**

```
client_loop: send disconnect: Connection reset
Connection closed
```

**Kein Grund. Das ist der ganze Anlass für dieses Kriterium** — und es ist hier
zum ersten Mal am echten Server gesehen statt aus `docs/50 §6` zitiert.

**Was das Panel sagt:**

> **Der Zugang kommt so nicht zustande.** `/var/www/vhosts/p6-b.invalid` gehört
> p1136 und nicht root (Eigentümer `p1136`, Rechte `0755`). OpenSSH weist die
> Anmeldung dann ab, ohne dem Programm des Kunden einen Grund zu nennen.

| erwartet | gemessen |
|---|---|
| Die Anmeldung scheitert, der Klient nennt keinen Grund | erfüllt |
| Das Panel nennt **genau dieses Verzeichnis** | erfüllt, mit Pfad, Eigentümer und Rechten |
| `journalctl` trägt `bad ownership or modes …` | **nicht belegt**, siehe unten |

**Und ein Nebenbeleg:** Die orange Meldung aus Punkt 3 („in `sshd_config` gilt
ein anderes Verzeichnis: `none`") ist **weg**, ohne dass jemand sie
weggeschaltet hat. Sie hing am Zustand und nicht an einer Absicht — genau die
Unterscheidung, an der `docs/45` gescheitert war.

### Die eine Zeile, die fehlt

```
journalctl -u ssh --since '2 min ago' | grep -i 'bad ownership' | tail -3
(keine Ausgabe)
```

Die Meldung des Servers ist damit **nicht belegt**, und das ist keine Kleinigkeit:
Sie ist die Quelle, aus der die Auskunft des Panels stammen soll. Die
wahrscheinlichste Erklärung ist das Zeitfenster — zwischen dem Anmeldeversuch
und diesem Aufruf lagen die Aufnahme der Seite und ein Wechsel des Rechners,
und `--since '2 min ago'` ist dafür zu eng.

> **Ein Zeitfenster, das an der Messung hängt statt am Ereignis, misst die
> Geschwindigkeit des Messenden.**

Nachzuholen ist es ohne den Defekt wiederherzustellen: Das Protokoll ist
historisch, der Eintrag steht noch da. `docs/58` bekommt statt `--since` einen
Aufruf, der das Fenster nicht raten muss.

### Ein Glied höher — und das Panel nennt das richtige

```
chmod 0777 /var/www/vhosts   →   drwxrwxrwx 3 root root
```

Der Klient wieder ohne Grund (`Connection reset`), und die Seite:

> **Der Zugang kommt so nicht zustande.** `/var/www/vhosts` ist für die Gruppe
> schreibbar und ist für alle schreibbar (Eigentümer root, Rechte `0777`).

| erwartet | gemessen |
|---|---|
| Die Anmeldung scheitert ohne Grund für den Klienten | erfüllt |
| Das Panel nennt **dieses** Glied und nicht das Abonnement darunter | erfüllt |
| `0755` zurück | `drwxr-xr-x 3 root root` |

**Das ist der Kern des Kriteriums, und er ist damit zweimal erfüllt** — einmal
an der Wurzel des Abonnements und einmal eine Station darüber. `Chain` fängt bei
`/` an und nennt das *erste* klemmende Glied, nicht das nächstliegende.

Der Wortlaut der Meldung war dabei falsch — siehe Befund 9.

### Die Zeile des Serverprotokolls, zweiter Anlauf

```
journalctl -u ssh --no-pager | grep -i 'bad ownership' | tail -5
^C
```

**Abgebrochen.** Ohne Zeitfenster liest `journalctl` das ganze Journal, und
`grep` gibt vor dem Ende nichts aus, weil `tail` puffert. Der Rat von hier war
„kein `--since`", und das war die Überkorrektur des Fehlers davor: Erst war das
Fenster zu eng, dann gab es keines mehr.

> **Ein Rat, der einen Fehler nur umdreht, hat ihn nicht behoben.**

Die Zeile ist damit **weiter nicht belegt** und bleibt der einzige offene Punkt
aus 6. Sie ist ohne Eingriff nachholbar — das Journal ist historisch.

---

## 7. Der Bestand ist Gesetz

Eingefügt **oberhalb** von `# BEGIN srvpanel` (Zeilen 134–136, die Marke steht
auf 138), damit der Eingriff nicht dort steht, wo das Panel selbst schreibt:

```
Match User p1136
    ChrootDirectory /var/www
Match all

# BEGIN srvpanel — verwaltet, nicht von Hand ändern
```

| erwartet | gemessen |
|---|---|
| `sshd -t` | `rc=0` |
| `sshd -T -C user=p1136` zeigt `/var/www` | erfüllt — der erste passende Block gewinnt |
| Das Panel **meldet** die Abweichung | erfüllt, orange, mit `/var/www` im Text |
| Es rollt nichts zurück | erfüllt |
| Nach dem Entfernen ist der Befund weg | erfüllt |
| Die Datei ist zeichengleich zurück | `4a141234…9018e` == `4a141234…9018e` |

**Die letzte Zeile ist die, die den Punkt zu einem Beleg macht.** „Sieht wieder
richtig aus" wäre keiner; zwei gleiche Prüfsummen sind einer.

**Und Punkt 7 braucht keinen Reload — das ist der Grund, warum er gefahrlos
ist.** `sshd -T` liest die Datei jedes Mal frisch, das Panel fragt genauso; ein
laufender `sshd` hat seine Konfiguration beim Start geparst und gibt sie an jede
Verbindung weiter. Eine Anmeldung als Prüfung wäre hier also die falsche Messung
gewesen — sie hätte noch den alten Stand getroffen.

> **Was `sshd -T` sagt, ist der Inhalt der Datei. Was gilt, ist der Inhalt des
> Speichers.**

---

## 8. Die kaputte Datei

**Der Punkt hat zwei Hälften bekommen, und das ist eine Änderung am Lauf.** Beim
Vorbereiten stand die Frage, welcher Vorgang überhaupt in `sshd_config`
schreibt — und das ist nicht jeder: `sftp.access` schreibt nur, wenn sich der
**Block** ändert, und der nennt Benutzer und Wurzel, nicht die Schlüssel. Ein
*zweiter* Schlüssel an einem Abonnement, das schon einen hat, ändert ihn nicht;
`sshd -t` läuft dann nie, und der Vorgang geht durch — zu Recht, denn die Datei
wurde nicht angefasst.

> **Ein Kriterium, das einen Abbruch erwartet, muss sagen, was sich ändern muss,
> damit überhaupt geschrieben wird.**

Der Block ändert sich bei genau zwei Anlässen: **erster Schlüssel** eines
Abonnements und **letzter Schlüssel weg**. Auf `cloudsrv24` gibt es nur ein
Abonnement, also werden beide Zustände der Reihe nach hergestellt statt an zwei
Abonnements gemessen.

### Phase A — der Rückbau, und er ist zeichengleich

Im Panel den Schlüssel entfernt, bei heiler Datei:

```
sshd -t                 rc=0
ls -l /etc/srvpanel/ssh/    total 0
tail -6 sshd_config     … PermitRootLogin yes          (kein Block mehr)
sha256sum               2b5a070ed8513f847086e21be1eb50fa1fca79c65782b2b85ef2b0dbcdf56852
```

| erwartet | gemessen |
|---|---|
| Schlüsseldatei **weg**, nicht leer | `total 0` |
| Block aus `sshd_config` verschwunden | erfüllt, samt `# BEGIN` und `# END` |
| `sshd -t` still | `rc=0` |
| Prüfsumme == der Wert aus 0c | **`2b5a070e…6852`, zeichengleich** |

**Die letzte Zeile war nicht bestellt.** Sie ist der Wert von *vor der ersten
Benutzung*: Was das Panel geschrieben hat, hat es restlos zurückgenommen — nicht
„sieht wieder aus wie vorher", sondern Byte für Byte dasselbe. Damit ist die
erste Hälfte von **Punkt 10** vorweg belegt, und zwar besser als der Punkt
verlangt.

> **Ein Rückbau, der eine Prüfsumme trifft, ist einer. Alles andere ist eine
> Ähnlichkeit.**

Das Verzeichnis `/etc/srvpanel/ssh` bleibt dabei stehen, und das ist richtig: Es
gehört dem Panel und nicht einem Abonnement. Ein Rest ist die *Datei* darin, und
die ist weg.

### Phase B — geprüft wird, bevor geschrieben wird

```
sshd -t
/etc/ssh/sshd_config: line 134: Bad configuration option: Klabautermann
/etc/ssh/sshd_config: terminating, 1 bad configuration options
rc=255
sha256sum   51cf4ccced92000619455dcba9d84800a875415e2c7bbd1e5d8f428e70f55f60   (REF-B)
```

Dann im Panel den Schlüssel eingetragen:

> **Das Formular wurde nicht gespeichert.**
> Der Zugangsblock ist von sshd abgewiesen worden; an der Datei wurde nichts
> geändert: `/etc/ssh/sshd_config.srvpanel.candidate: line 134: Bad
> configuration option: Klabautermann`

| erwartet | gemessen |
|---|---|
| Der Vorgang bricht ab, mit der Meldung von `sshd -t` | erfüllt, wörtlich |
| Prüfsumme == REF-B | `51cf4ccc…5f60`, unverändert |
| Kein `.candidate` | nur `sshd_config.srvpanel.lock`, 0 Byte |
| `/etc/srvpanel/ssh/` bleibt leer | `total 0` |
| Das Panel zeigt weiter „kein Schlüssel" | erfüllt |

**Das ist der Kern des Punktes und er hält:** Es wird geprüft, *bevor*
geschrieben wird. Der Ablauf aus `docs/38 §14.2` — schreiben, neu laden, bei
einem Fehler zurückrollen — ist hier nicht kopiert worden, und das war richtig:
Gemessen (`docs/57 §5`) beendet ein Neuladen mit kaputter Datei den `sshd`.

> **Ein Rückweg, der voraussetzt, dass der Dienst noch läuft, ist keiner für den
> Fall, dass ihn genau dieser Vorgang beendet hat.**

Die Datei nennt in der Meldung den **Kandidaten** und nicht `sshd_config` —
also genau die Datei, die geprüft wurde. Die Nachbardatei ist danach weg, die
Sperre daneben bleibt liegen; sie sitzt bewusst neben der Datei und nicht auf
ihr, und ist damit ein erwartetes Artefakt und kein Rest.

### Phase C — und der Block ist eine reine Funktion des Sollzustands

Zeile entfernt, Schlüssel eingetragen (Bezeichnung `Win11TestNeu`):

| | gemessen |
|---|---|
| `sshd -t` nach dem Entfernen | `rc=0` |
| Prüfsumme direkt danach | `2b5a070e…6852` — **REF-A** |
| Der Vorgang im Panel | „Der Schlüssel ist eingetragen." |
| `sshd -t` danach | `rc=0` |
| Schlüsseldatei | `-rw-r--r-- 1 root root 314 … p1136` |
| Prüfsumme mit Block | `4a141234…9018e` — **REF-C** |

**Zwei Zahlen sind dabei mehr als eine Bestätigung.**

**REF-C ist zeichengleich mit der Sicherung von vor Punkt 7** (`4a141234…9018e`).
Der Weg dorthin führte über einen entfernten Block, eine kaputte Datei, einen
abgebrochenen Vorgang und einen neuen Schlüssel mit anderer Bezeichnung — und die
Datei ist Byte für Byte dieselbe wie Stunden vorher.

> **Ein verwalteter Bereich, der aus demselben Sollzustand zweimal dieselbe Datei
> erzeugt, ist eine Funktion. Alles andere ist ein Verlauf.**

Zusammen mit REF-A aus Phase A stehen damit **zwei** Zustände als exakte Werte
da: ohne Zugang `2b5a070e…6852`, mit einem Zugang `4a141234…9018e`, jeder zweimal
getroffen.

**Und die 314 Byte gegen die 311 aus Punkt 4** sind die dritte Bestätigung,
diesmal für den Inhalt: Die Bezeichnung hiess vorher `TestWin11` (neun Zeichen)
und jetzt `Win11TestNeu` (zwölf) — genau drei Byte mehr. Die Datei trägt also die
Bezeichnung aus dem Panel und nichts sonst, und zwar nachrechenbar statt
angesehen.

### Phase D — die Vorhersage ist eingetroffen

Vorher die Gegenprobe: Anmeldung mit dem gültigen Schlüssel — `Connected to
cloudsrv24.de.` Dann die Zeile angehängt, `rc=255`, **REF-D**
`5e7dfac2…c06ea`. Im Panel `Win11TestNeu` entfernt.

| erwartet | gemessen |
|---|---|
| Der Vorgang bricht ab | erfüllt |
| Prüfsumme == REF-D | `5e7dfac2…c06ea`, unverändert |
| Kein `.candidate` | nur die Sperre, 0 Byte |
| **Schlüsseldatei noch da** | **`total 0` — sie ist weg** |
| Das Panel zeigt den Schlüssel weiter | erfüllt |

**Vier von fünf, und die fünfte war vorhergesagt.** Sie steht als Befund 12
unten. Zwei Dinge sind daran bemerkenswert:

**Die Seite hat nicht gelogen.** Sie führte den Schlüssel weiter auf *und*
meldete rot: „Der Zugang kommt so nicht zustande. `/etc/srvpanel/ssh/p1136` gibt
es nicht." Die Kettenprüfung aus Punkt 6 hat den Rest gefunden, für den sie nicht
gebaut wurde — der Bereich „Lage" ist das Netz unter dem Zustand, und er hat
gehalten.

**Und der Betreiber hat einen zweiten Fehler gemeldet, der nicht auf der Liste
stand:** „Die Bestätigung von Entfernen hat keinen Erfolg danach." Es stand
**keine** Meldung auf der Seite — kein Grün, kein Rot. Das ist Befund 13, und er
ist der grössere von beiden.

---

## Befunde

### Befund 1 — die Messrunde war nie fahrbar

Siehe Punkt 1. `docs/58` verlangt ein Werkzeug, das die Auslieferung nicht
mitbringt.

### Befund 2 — „kein Schlüssel" wird als Defekt gemeldet

Die Seite schrieb rot: *„Der Zugang kommt so nicht zustande. /etc/srvpanel/ssh
gibt es nicht (Eigentümer ?, Rechte ?)"* — für ein Abonnement, an dem nur noch
niemand etwas eingerichtet hatte.

Die Ursache ist die Reihenfolge zweier Zweige: Der Defekt wurde zuerst geprüft,
und ohne Schlüssel gibt es die Schlüsseldatei nicht, also meldet `Chain` „gibt
es nicht". Erwartet war „Es ist kein Schlüssel eingetragen — damit ist der
Zugang aus."

> **Ein Zustand, in dem noch nichts eingerichtet ist, sieht für eine Prüfung
> genauso aus wie einer, in dem etwas kaputt ist — und nur der Code kennt den
> Unterschied.**

**Der Satz stand schon da.** `SftpCheck` trägt ihn im Kopf: *„Und ‚keine
Schlüsseldatei' ist ein Zustand und kein Defekt."* Er stand in der Klasse, die
die Auskunft *erzeugt*, und nicht in der, die sie *anzeigt*.

**Behoben:** Ohne Schlüssel wird der Zustand gemeldet; die Ketten werden ab dem
ersten Schlüssel beurteilt und nicht davor.

### Befund 3 — `none` wird für eine fremde Angabe gehalten

Daneben stand: *„In sshd_config gilt für diesen Benutzer ein anderes
Verzeichnis als das des Abonnements: **none**. Eine Regel des Betreibers steht
über der des Panels."* Auf einem Server, auf dem schlicht noch kein Block
existierte.

`sshd -T` schreibt `chrootdirectory none`, wenn nichts gesetzt ist. Die Prüfung
sah nur auf „ungleich der Abo-Wurzel".

> **Ein Platzhalter für „nichts" ist ein Wert, und eine Prüfung, die nur auf
> „ungleich" sieht, hält ihn für eine Aussage.**

**Behoben:** `none` zählt nicht als fremde Angabe, und die Meldung gilt erst ab
dem ersten Schlüssel — ohne Block gibt es nichts, was überschrieben sein könnte.

### Befund 4 — ein fehlendes Leerzeichen, und Vue ist schuld

Auf der Seite stand `zustande./etc/srvpanel/ssh` ohne Trennung. Vues Vorgabe
`whitespace: 'condense'` **entfernt** einen Textknoten zwischen zwei Elementen,
wenn er einen Zeilenumbruch enthält — und im Quelltext stand `</b>` und
`<span class="ident">` auf zwei Zeilen.

> **Ein Leerzeichen, das im Quelltext als Zeilenumbruch dasteht, ist für den
> Übersetzer keines.**

**Behoben** mit einem ausdrücklichen `{{ ' ' }}`, und **der Wächter steht**:
`TemplateSpacingTest`, mit beiden Brüchen in `tests/waechter-brechen.sh`.

Gemessen wurde dafür am Übersetzer selbst (`@vue/compiler-dom`, 17. August
2026) statt geschlossen: `</b>\n<span>` erzeugt **keinen** Textknoten zwischen
den beiden Elementen, `</b>{{ ' ' }}\n<span>` einen.

**Und der Wächter fragt nicht überall — das ist seine eigentliche Arbeit.** Im
ganzen Baum stehen **22** Stellen, an denen zwei Elemente nur ein Zeilenumbruch
trennt; **21 davon sind folgenlos**, weil der Behälter eine Flexbox ist und der
Abstand aus `gap` kommt — Beschriftung und Feld in `.field`, die zwei Zweige
eines `v-if`/`v-else`, gestapelte Hinweise in `.toggle > span`.

> **Eine Regel, die einundzwanzigmal Fehlalarm gibt, wird beim ersten Aufräumen
> abgeschaltet.**

Gefragt wird deshalb in den Meldungsklassen — `.hint`, `.empty`,
`.section-note`, `.error` und dem `span` in einem `.notice` —, und der Wächter
**misst diese Voraussetzung nach**: Bekäme eine davon `display: flex`, wird er
rot statt still.

### Ein Fund über das Messmittel, nicht über das Panel

Beim Bau des Wächters sah `<p class="usage">` auf der Abonnementseite nach
demselben Fehler aus: `<strong>1.024 MB</strong>` und `<span>von 10.240 MB</span>`
ohne Textknoten dazwischen. Der Aufsatz aus CLAUDE.md — echtes Markup plus
`public/build/assets/*.css` im Chromium — hat es **bestätigt**: `1.024 MBvon
10.240 MB`, gemessen und fotografiert.

Es war trotzdem falsch. `.usage` steht in einem `<style scoped>`-Block der
Komponente und ist dort `display: flex`. Vite übersetzt das zu
`.usage[data-v-1ecda25a]`, und handgeschriebenes Markup ohne dieses Attribut
trifft die Regel nie. **105 solcher Selektoren aus 19 Komponenten** stehen im
gebauten Stylesheet und gelten in diesem Aufsatz allesamt nicht.

> **Ein Aufsatz, der das gebaute Stylesheet benutzt, hat noch nicht die Regeln,
> die an ein Attribut gebunden sind, das nur der Übersetzer setzt.**

CLAUDE.md sagt über diesen Aufsatz: „misst die echte Seite — und nicht etwas
Ähnliches." Für Bausteine, deren Gestaltung ganz in `app.css` steht, gilt der
Satz weiter — `docs/56` Punkt 5 hat ihn aufs Pixel belegt. Für eine Komponente
mit eigenem `scoped`-Block gilt er nicht.

---

### Befund 5 — die Gegenprobe darf auf einen anderen Weg zurückfallen

**Gefunden am 17. August 2026, im Bild eines fehlgeschlagenen Versuchs.** Der
Betreiber fuhr Punkt 4 unter PowerShell und hat dabei die Schreibweise der
Eingabeaufforderung benutzt: `%USERPROFILE%` expandiert dort nicht. `sftp`
meldete es als **Warnung** —

```
Warning: Identity file %USERPROFILE%\.ssh\p6-sftp not accessible: No such file or directory.
```

— und machte weiter. Der Server bot Passwort an, `sftp` fragte danach, und heraus
kam `Permission denied, please try again.`

**Das ist genau die Ausgabe, die Punkt 4 für die Gegenprobe erwartet.** Ein
falscher Pfad und ein falscher Schlüssel sind im Ergebnis nicht zu
unterscheiden — beide enden mit „Permission denied". Wer den Haken nach dem
Ergebnis setzt, hakt einen Tippfehler als Beleg ab.

> **Eine Gegenprobe, die auf einen anderen Weg zurückfallen darf, prüft den
> falschen Weg.**

Wortgleich der Satz aus `docs/44`, dort über eine Gegenprobe über den
Unix-Socket, die einen kaputten TCP-Weg nicht sehen konnte. Hier ist es
derselbe Fehler in der anderen Richtung: nicht ein anderer Weg zum Ziel,
sondern ein anderes Verfahren am selben Ziel.

**Behoben** in `docs/58` Punkt 4: Jeder Aufruf trägt
`-o PreferredAuthentications=publickey -o PasswordAuthentication=no
-o BatchMode=yes`. Damit ist ein fehlender Schlüssel ein Fehler und keine
Warnung, und „Permission denied" kann nur noch eine Ursache haben.

---

### Befund 6 — `PasswordAuthentication no` schliesst eine von zwei Türen

**Und dasselbe Bild trägt einen Fund über das Panel.** Der Server hat nach einem
Passwort gefragt:

```
p1136@cloudsrv24.de's password:
```

Der Auftrag für Schritt 8 lautet: **kein Passwort, der Systembenutzer hat keines
und bekommt keines.** Der verwaltete Block trug dazu `PasswordAuthentication no`
— und das genügt nicht. Gemessen am 17. August 2026 gegen OpenSSH 9.6p1, mit
einem Kopfteil in der Vorgabe der Distribution:

| Block | `sshd -T` | angeboten wird |
|---|---|---|
| `PasswordAuthentication no` | `kbdinteractiveauthentication yes` | `publickey,keyboard-interactive` |
| dazu `AuthenticationMethods publickey` | `authenticationmethods publickey` | `publickey` |
| oder `KbdInteractiveAuthentication no` | `kbdinteractiveauthentication no` | `publickey` |

`KbdInteractiveAuthentication` ist die zweite Tür, und über PAM fragt sie nach
demselben Passwort. Gelingen kann es nicht — der Systembenutzer trägt `!` im
Schattenfeld —, aber gefragt wird, und ein Wörterbuchangriff nimmt den Weg durch
PAM statt an ihm vorbei.

> **Ein Riegel, der eine von zwei Türen schliesst, ist eine Auskunft über die
> Tür und keine über das Haus.**

**Behoben** mit `AuthenticationMethods publickey` im Block — der Positivliste
und nicht dem zweiten Riegel, aus demselben Grund, aus dem der Agent eine
Programmliste führt und keine Sperrliste: Sie nennt, was gilt, und hält damit
auch für eine Tür, die OpenSSH später dazubekommt. Der Wächter ist
`SshdConfigTest::test_only_a_public_key_gets_in`, der Bruch steht im Bruchskript.

**Und der eigentliche Befund liegt in der Messrunde.** `tests/sftp-messen.sh`
setzte `PasswordAuthentication no` **und** `KbdInteractiveAuthentication no` im
globalen Kopfteil. Damit war die Frage in 42 Messungen nie gestellt: Der Block
konnte die Tür nicht offen lassen, weil sie schon zu war. Dazu kommt, dass
`anmeldung()` mit `BatchMode=yes` fährt — eine Passwortabfrage wäre dort
unsichtbar geblieben, selbst wenn sie erschienen wäre.

> **Eine Messumgebung, die eine zweite Tür global zuhält, sagt nichts darüber,
> ob der Block sie zuhält.**

Die Messrunde hat dafür den Abschnitt **M6b** bekommen: zwei Fassungen des
Blocks gegen einen Kopfteil ohne Riegel, gelesen wird, was der Server dem
Klienten anbietet — plus die Gegenprobe, dass der gültige Schlüssel weiter
hereinkommt. **45 Messungen, 0 abweichend.**

---

### Befund 7 — der Fingerabdruck sieht aus wie ein Schlüssel

**Punkt 4, erster Versuch.** Eingetragen wurde die Ausgabe von `ssh-keygen -lf`:

```
256 SHA256:PBMiXFViiL6KV95VXw7J2Kz6hRcCuhXPDzMXrmRGfT8 abnahme (ED25519)
```

**Die Abweisung hat funktioniert, und das ist der eigentliche Beleg dieses
Punktes.** Nichts wurde geschrieben, die Meldung stand oben in der
Zusammenfassung, das Feld war rot, und der Satz benannte die Stelle: „Die Zeile
fängt nicht mit einem Schlüsseltyp an, sondern mit „256"."

**Geholfen hat er trotzdem nicht.** Die beiden Zeilen stehen im Terminal
direkt untereinander — `ssh-keygen -lf` gibt den Fingerabdruck aus, `cat`
den Schlüssel —, und die falsche ist die kürzere. Ein Satz, der beschreibt,
*was* dasteht, richtet den nicht, der aus zwei ähnlichen Zeilen die falsche
erwischt hat; er braucht den Satz, der sagt, *welche* er hat.

> **Ein Satz, der beschreibt, was dasteht, hilft dem nicht, der die falsche von
> zwei ähnlichen Zeilen kopiert hat.**

`whyNot()` hatte drei solche Fälle — privater Schlüssel, `ssh-dss`,
Hardware-Token — und dieser ist der vierte. Er ist der wahrscheinlichste von
allen vier, weil ihn der Ablauf des Punktes selbst herbeiführt: Wer den
Fingerabdruck notieren soll, hat ihn eine Zeile später vor sich.

**Behoben**: Eine Zeile, die mit einer Zahl anfängt und `SHA256:` enthält, wird
als Fingerabdruck benannt. Der Wächter ist
`PublicKeyTest::test_a_fingerprint_is_named_as_such`, mit der Gegenprobe, dass
eine Zahl **ohne** `SHA256:` beim allgemeinen Satz bleibt — ohne die zweite
Hälfte wäre die Regel „jede Zahl ist ein Fingerabdruck", und das ist sie nicht.

**Und die Anleitung trägt mit Schuld.** Block C nannte `ssh-keygen -lf` und
`cat` in derselben Folge. `docs/58` Punkt 4 sagt jetzt, welche der beiden
Ausgaben ins Panel gehört.

---

### Befund 8 — eine Befehlsfolge, die ihre eigene Eingabeaufforderung mitbringt

Der einzige Schritt aus Block D, der nicht gemessen ist, ist am Kopieren
gescheitert:

```
sftp> sftp> put .ssh/p6-sftp.pub httpdocs/probe.txt
Invalid command.
```

Die Anleitung hatte die Befehle **mit** `sftp> ` davor aufgeschrieben — so, wie
sie in einer Sitzung aussehen. Mitkopiert wird die Eingabeaufforderung dann
gleich mit, und `sftp` liest sie als Teil des Befehls.

> **Eine Befehlsfolge, die zeigt, wie eine Sitzung aussieht, ist keine, die man
> in eine Sitzung einfügen kann.**

Das ist dieselbe Sorte Fehler wie Befund 7, eine Ebene höher: Dort standen zwei
ähnliche Ausgaben untereinander und die falsche war leichter zu greifen, hier
ist die falsche Form der Anleitung die, die richtig aussieht. Beide Male hat
nicht der Prüfling versagt, sondern das Prüfmittel.

**Behoben** in `docs/58` Punkt 4: Befehle für eine `sftp`-Sitzung stehen ohne
Eingabeaufforderung davor, und der Hinweis dazu steht dabei.

---

### Befund 9 — zwei Gründe, aneinandergehängt zu zwei Sätzen

Für ein `0777` stand auf der Seite:

> `/var/www/vhosts` **ist für die Gruppe schreibbar und ist für alle
> schreibbar** (Eigentümer root, Rechte 0777).

Zweimal dasselbe Prädikat. `Chain::judge()` sammelt fertige Sätze in einer Liste
und hängt sie mit `implode(' und ', …)` aneinander — ein Verfahren, das nichts
über Sprache weiss.

> **Eine Aufzählung, die aus zwei fertigen Sätzen entsteht, ist keiner.**

**Und die vorhandene Prüfung war dabei grün.**
`ChainTest::test_a_writable_bit_is_enough_to_fail` sucht `schreibbar` im Grund —
das kommt in der kaputten Fassung zweimal vor. Derselbe Schnitt wie bei
`docs/48`: Eine Prüfung auf *Vorkommen* sagt nichts über *Wortlaut*.

**Behoben**: Die beiden Schreibrechte sind ein Grund und nicht zwei
(`ist für die Gruppe und für alle schreibbar`), und mehrere Gründe werden mit
Komma verbunden statt mit `und` — jeder trägt schon eines („gehört p1136 **und**
nicht root"), und ein drittes dazwischen macht aus der Aufzählung eine Kette.
Der Wortlaut der Meldung aus dem ersten Teil von Punkt 6 bleibt unverändert; bei
einem einzigen Grund ändert sich nichts.

Der Wächter ist `ChainTest::test_a_reason_reads_as_one_sentence` — er prüft den
**Wortlaut** und dazu, dass kein zweites `und ist` vorkommt. Der Bruch steht im
Bruchskript und ist von Hand gegengeprüft: `bei 0777 (fehlt: ist für die Gruppe
und für alle schreibbar)`.

---

### Befund 10 — die Zusage galt für ein Verzeichnis, das niemand benutzt

**Gefunden von Punkt 7, der etwas anderes prüfen wollte.** Während
`sshd -T -C user=p1136` `/var/www` sagte, stand auf der Seite:

> Der Zugang steht: 1 Schlüssel, **Verzeichnis und Rechte in Ordnung**.

Der Satz war wahr — über `/var/www/vhosts/p6-b.invalid`. `SftpCheck` liess die
Kette über die Wurzel des Abonnements laufen und fragte danach getrennt, was
gilt; die beiden Antworten standen nebeneinander, ohne dass die erste von der
zweiten wusste.

> **Eine Kette, die am Sollzustand hängt, sagt nichts über den Zugang, der
> gerade nicht ihm folgt.**

Gefährlich ist der umgekehrte Fall: Ein Eintrag des Betreibers auf ein
Verzeichnis mit falschen Rechten, und die Seite hätte „in Ordnung" gemeldet,
während niemand hereinkommt — wörtlich die Lage, für die es diese Seite gibt.

**Behoben in zwei Schritten.** Der Agent fragt jetzt **zuerst**, was gilt, und
beurteilt dann *dieses* Verzeichnis; die Antwort nennt es als `checked_root`.
Und die Seite nennt es im Satz, wenn es nicht das des Abonnements ist:
„Geprüft ist `/var/www` — das Verzeichnis, das gilt."

**Ein Fall dabei, der beim Beheben aufgefallen ist und nie im Lauf vorkam:**
OpenSSH lässt in `ChrootDirectory` die Marken `%h`, `%u` und `%%` zu, und
`sshd -T` gibt sie **unaufgelöst** aus. Ein `Chain::of('%h/sftp')` hätte „gibt
es nicht" gemeldet — eine falsche Aussage statt einer fehlenden, und das ist die
schlechtere der beiden.

> **Ein Pfad mit einer Marke darin ist kein Pfad, und ein Urteil darüber ist
> keines.**

Der Wächter ist `SftpCheckTest` mit drei Regeln — was gilt, wird beurteilt;
`none` und leer bleiben beim Abonnement; eine Marke wird kein Pfad, **mit** der
Gegenprobe, dass ein gewöhnlicher absoluter Pfad weiterhin beurteilt wird. Ohne
sie hiesse „alles fällt zurück" auch, dass nie etwas geprüft wird. Beide Brüche
stehen im Bruchskript und sind von Hand gegengeprüft.

**Gemessen ist die neue Meldung auch**, mit dem längsten Pfad, den sie tragen
kann (`/srv/sftp-chroots/` plus 63 Zeichen): Dokumentüberlauf **0px** bei 390px
und 1440px, Gegenprobe 510px — und auf dem Bild bricht die Kennung mitten im
Namen, statt die Seite zu schieben.

**Und die Form der Meldung hat der Wächter entschieden, nicht ich.** Der erste
Entwurf hatte zwei `span` mit `v-if`/`v-else`; `NoticeShapeTest` verlangt ein
*attributfreies* `span` als einziges Kind und wurde rot. Zu Recht: Zwei `span`
sind für einen Leser des Quelltexts zwei Flexkinder, und eine Regel, die
„die schliessen sich ja aus" gelten liesse, wäre eine, die man von Fall zu Fall
auslegt. Die Verzweigung steht jetzt **innerhalb** des Wickels.

> **Eine Regel, die eine Ausnahme für den eigenen Fall bekommt, ist ab da
> Auslegung.**

---

### Befund 11 — der rote Rand sass am falschen Ort

**Phase B hat alles erfüllt und dabei einen Fehler gezeigt, der nicht auf der
Liste stand.** Die Meldung landete am **Schlüsselfeld**: Das Textfeld war rot
umrandet, während der Schlüssel einwandfrei war — `PublicKey::parse()` hatte ihn
eine Zeile vorher gelesen, sonst wäre der Vorgang gar nicht bis zum Schreiben
gekommen.

> **Ein roter Rand am Feld behauptet, das Feld sei falsch. Wer ihn für einen
> Zustand des Servers setzt, schickt den Leser dorthin, wo nichts zu ändern
> ist.**

Der Text der Zusammenfassung war dabei richtig und sogar beruhigend („an der
Datei wurde nichts geändert"). Falsch war allein, **wohin** er zeigte —
`docs/19 §6`, dieselbe Frage wie beim Ort einer Rückmeldung, nur eine Ebene
tiefer: nicht *ob* am Feld, sondern *welcher* Fehler.

`SftpController` fing jede `AgentException` und schrieb sie an `key`. Dabei
trägt sie den Grund mit: `badRequest` kommt aus der Prüfung der Eingabe,
`exec_failed`, `timeout` und `internal` sind Zustände des Servers.

> **Eine Auskunft, die man hat und nicht benutzt, ist so gut wie keine.**

**Behoben**: Nur `BAD_REQUEST` geht ans Feld; alles andere an die
Zusammenfassung unter dem Schlüssel `server`, der keiner eines Feldes ist — mit
dem Satz davor, der die Frage beantwortet, die der Leser als erste hat: „Der
Schlüssel ist in Ordnung; der Server hat die Änderung nicht angenommen."

Der Wächter ist `AgentErrorRoutingTest`, und er schneidet die **Kommentare weg**,
bevor er liest. Ohne das liesse er sich von der Begründung überzeugen, die
neben der Verzweigung steht und dieselbe Marke nennt — `FieldErrorTest` ist
genau in diese Falle gelaufen, nur in die andere Richtung.

> **Ein Wächter, der Text liest, liest auch die Begründung dafür, warum er recht
> hat.**

Beide Brüche sind gegengeprüft: Verzweigung entfernt → rot; Marke nur noch im
Kommentar → rot.

#### Und die offene Arbeit dazu, mit Namen

**Dieselbe Form steht an vier weiteren Stellen**, und sie sind hier
**nicht** angefasst:

| Datei | Feld |
|---|---|
| `app/Http/Controllers/DatabaseController.php` | `cidr` (zweimal) |
| `app/Http/Controllers/DatabaseController.php` | `host` |
| `app/Http/Controllers/FileController.php` | `path` |

Sie gehören zu P5b und zum Dateimanager, haben ihre eigenen Abnahmeläufe, und
keine davon ist in diesem Lauf gemessen worden.

> **Ein Fehler, den man an fünf Stellen gleichzeitig behebt, ist an vier davon
> ungemessen behoben.**

Der allgemeine Wächter kommt, wenn die vier gemessen sind. Bis dahin steht das
hier — wie `docs/42 §5` — als benannte offene Arbeit und nicht als Absicht.

---

### Befund 12 — eine Transaktion rollt die Platte nicht zurück

**Vorhergesagt aus dem Quelltext, dann gemessen.** `Sftp::remove()` schrieb die
Schlüsseldatei **vor** dem Block:

```php
$key->delete();
$this->write($subscription);   // die Datei ist weg
$this->sync();                 // hier bricht sshd -t ab
```

Die Datenbank rollte die Zeile zurück, die Platte nicht. Übrig blieb ein
Abonnement mit einem Schlüssel im Panel und keinem darunter.

> **Eine Transaktion rollt die Datenbank zurück und nicht die Platte.**

`Sftp::add()` hatte die richtige Reihenfolge von Anfang an und **die Begründung
dazu im Kommentar** — „erst der Block, dann die Schlüssel". `remove()` hatte
beides nicht.

> **Zwei Wege durch dieselbe Sache, und die Begründung steht nur an einem.**

**Behoben**: `sync()` vor `write()`, in beiden Richtungen. Der Grund, in einem
Satz: `sync()` prüft mit `sshd -t` und lädt neu — es gibt viele Gründe, aus denen
es scheitert; `write()` schreibt eine Datei. Wer den unwahrscheinlichen Schritt
zuerst macht, hat im wahrscheinlichen Fall schon etwas angefasst.

Der Wächter ist `SftpWriteOrderTest`, und er prüft **beide** Methoden — der
Fehler war nicht die falsche Reihenfolge an einer Stelle, sondern zwei
Reihenfolgen für eine Sache. Er schneidet die Kommentare weg, aus demselben Grund
wie `AgentErrorRoutingTest`.

---

### Befund 13 — sieben Meldungen, die seit P4 niemand gesehen hat

**Der Betreiber hat es gemeldet, nicht gesucht:** Nach dem gescheiterten
Entfernen stand **keine** Meldung auf der Seite. `SftpController::destroy()`
schickt `->with('error', $error->getMessage())` — und `HandleInertiaRequests` gab
den Schlüssel `error` nicht weiter. Getragen wurden `notice`, `success`,
`recoveryCodes`. Die Meldung war fort, bevor sie jemand sehen konnte.

Betroffen sind **sieben** Aufrufe aus vier Controllern, und zwei davon zählen:

| Datei | Meldung |
|---|---|
| `DomainController` | „Zertifikat abgewiesen: …" |
| `MailSettingsController` | „Der Versand ist gescheitert: …" |

**Und das Bitterste steht in `Settings/Mail.vue`:** Die Seite **las**
`flash.error` und renderte ihn in einer eigenen `notice critical`. Schreiber und
Leser waren beide da, seit P4 — nur dazwischen trug niemand.

> **Ein Schreiber und ein Leser machen keinen Kanal. Dazwischen muss jemand
> tragen.**

Das ist wörtlich das Muster aus `CLAUDE.md`, zum siebten Mal: eine Zeichenkette,
die auf etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug
prüft. Eine Policy ohne Route, ein Kommando, das im Startskript fehlt, ein
Favicon mit null Byte — und jetzt ein `flash`-Schlüssel ohne Träger.

**Behoben**: Die Mittelschicht trägt `error`, und `PanelLayout` rendert ihn an
demselben Ort wie die grüne Meldung, mit `role="alert"` statt `status` — hier ist
etwas *nicht* geschehen, was geschehen sollte. Die eigene Fassung in
`Settings/Mail.vue` ist damit weggefallen: zwei Orte für dieselbe Auskunft
heissen, dass einer veraltet.

Der Wächter ist `FlashChannelTest` mit **drei** Regeln und beiden Richtungen —
was ein Controller schreibt, wird getragen; was getragen wird, liest jemand.
Alle drei Brüche sind gegengeprüft.

#### Und zehn weitere, die hier nicht behoben sind

Der Wächter hat beim ersten Lauf mehr gefunden als den Anlass:

| Schlüssel | wo | wie oft |
|---|---|---|
| `status` | `DatabaseController`, `GeneralSettingsController` | 9 |
| `operation` | `DomainController` | 1 |

`status` trägt Sätze wie „Datenbank … angelegt.", „Die Sicherung wird erstellt."
und „Die Anzeigezone ist jetzt …" — **keiner davon ist je auf einer Seite
angekommen.** `operation` trägt eine Kennung statt eines Satzes und ist damit
eine andere Frage.

Sie gehören zu P5b, P5c und `docs/40`, haben ihre eigenen Abnahmeläufe, und
keiner davon ist in diesem Lauf gemessen worden.

> **Ein Fehler, den man an zehn Stellen gleichzeitig behebt, ist an neun davon
> ungemessen behoben.**

Sie stehen deshalb als **begründete Ausnahme im Wächter selbst** — wie eine Route
im `RouteGuard` —, und die Liste kann nur kleiner werden: Ein Eintrag, der
nirgends mehr geschrieben wird, ist ein Rest und macht den Wächter rot
(`test_no_exception_outlives_its_reason`, gegengeprüft). Wer einen davon behebt,
**muss** ihn streichen, und niemand kann einen neuen dazuschreiben, ohne die
Begründung mitzuschreiben.
