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

## Stand — 17. August 2026, nach Punkt 11

| Punkt | Zustand |
|---|---|
| 0 der zweite Weg hinein | **erfüllt**, 0a und 0b nachgefragt statt angenommen |
| 1 die Messrunde | **erfüllt** — 42/0, dieselbe OpenSSH-Fassung wie `docs/57` |
| 2 Fassungen | **erfüllt**, `ssh.socket` als Messung statt als Vermutung |
| 3 ohne Schlüssel ist der Zugang aus | **erfüllt** in der Hauptaussage; die Wortlaute nachzuprüfen |
| 4 der erste Schlüssel | **erfüllt**, alle acht Zeilen |
| 5 der Kunde legt sich selbst einen hin | **erfüllt**, mit drei Quellen für einen Fingerabdruck |
| 6 die Ablehnung wird sichtbar | **erfüllt**, alle drei Zeilen, beide Glieder |
| 7 der Bestand ist Gesetz | **erfüllt**, Rückbau zeichengleich |
| 8 die kaputte Datei | **erfüllt**, in beiden Richtungen des Blocks |
| 9 `reload` bei ruhendem Dienst | **zwei von vier Zeilen**; der Rest hängt an Befund 16 |
| 10 der Rückbau | **erfüllt**, alle vier Zeilen |
| 11 die Wände | **erfüllt bis auf Wand 2** (bewusst offen) |
| 12 die Bilder | offen — gehört gegen die nächste Fassung |

**Neunzehn Befunde, und keinen davon hat ein Test gefunden.**

| woher | Anzahl | welche |
|---|---|---|
| am Panel | 12 | 2, 3, 4, 6, 7, 9, 10, 11, 12, 13, 16, 18, 19 |
| am Prüfmittel | 4 | 5, 8, 14, 15 |
| am Kriterium | 3 | 1, 17 — und die Hälften von Punkt 8 |

**Befund 19 stammt nicht aus einem Punkt des Laufs**, sondern von der Bedienung
während seiner Durchführung — und das ist der Fund, den kein Kriterium bestellt
hat.

Dasselbe Verhältnis wie in `docs/45`, `docs/47` und `docs/48`: Die Mehrheit steckt
nicht im Prüfling.

**Elf Korrekturen liegen im Zweig und keine davon auf dem Server.** Gegen die
nächste Fassung nachzuprüfen sind deshalb in einem Durchgang:

1. **Punkt 12**, die Bilder — 390 px und 1440 px, beide Themes, mit Messung und
   Gegenprobe
2. die **Wortlaute aus Punkt 3** (Befunde 2, 3, 4) — „kein Schlüssel" als Zustand,
   `none` nicht als fremde Angabe, das Leerzeichen
3. **Punkt 9** zu Ende (Befund 16) — jetzt kann die Kundenaktion bei ruhendem
   Dienst durchlaufen
4. **die Fehlermeldung selbst** (Befund 13) — sie hat es in `rc.10` nicht gegeben,
   und ohne sie sah in Phase 2b ein Fehlschlag wie ein Erfolg aus
5. `sshd -T -C user=… | grep authenticationmethods` → `publickey` (Befund 6)

**Neue Wächter aus diesem Lauf:** `TemplateSpacingTest`, `AgentErrorRoutingTest`,
`FlashChannelTest`, `SftpWriteOrderTest`, `SftpRuntimeDirTest`, `SftpCheckTest` —
dazu neue Regeln in `SshdConfigTest`, `ChainTest` und `PublicKeyTest`. Jeder Bruch
steht in `tests/waechter-brechen.sh` und ist einzeln gefahren.

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

### Phase E — der Rückbau, und ein alter Befund als Bestätigung

Zeile entfernt (`rc=0`, Prüfsumme **REF-C** `4a141234…9018e`), dann im Panel den
Schlüssel entfernt — und diesmal ging es durch:

| | gemessen |
|---|---|
| Der Vorgang | „Der Schlüssel ist entfernt." |
| `sshd -t` | `rc=0` |
| `/etc/srvpanel/ssh/` | `total 0` |
| Prüfsumme | `2b5a070e…6852` — **REF-A** |

**Der Zustand „kein Zugang" ist damit viermal derselbe Wert**: bei 0c, in Phase A,
vor dem Eintragen in Phase C und jetzt. Zwischen dem ersten und dem letzten liegen
ein Block, zwei kaputte Dateien, zwei abgebrochene Vorgänge und ein Schlüssel mit
zwei verschiedenen Bezeichnungen.

**Und die Seite hat dabei Befund 2 und 3 noch einmal vorgeführt** — beide sind im
Zweig behoben, aber nicht in `rc.10`: Neben der grünen Meldung „Der Schlüssel ist
entfernt." stand rot „Der Zugang kommt so nicht zustande.
`/etc/srvpanel/ssh/p1136` gibt es nicht" und orange „gilt ein anderes Verzeichnis
… `none`".

Das ist kein neuer Fund, sondern die beste Begründung für die alten: **Der
Erfolgsfall und die Fehlermeldung standen gleichzeitig auf derselben Seite.** Ein
Kunde, der gerade aufgeräumt hat, liest, dass er etwas kaputt gemacht hat.

> **Ein Zustand, in dem noch nichts eingerichtet ist, sieht für eine Prüfung
> genauso aus wie einer, in dem etwas kaputt ist — und nur der Code kennt den
> Unterschied.**

Nach der Korrektur steht dort „Es ist kein Schlüssel eingetragen — damit ist der
Zugang aus." Nachzuprüfen gegen die nächste Fassung, zusammen mit Punkt 3.

### Phase E, zweite Hälfte — und Punkt 8 ist erfüllt

Schlüssel wieder eingetragen (Bezeichnung `Win11TestNeu2`):

| | gemessen |
|---|---|
| Der Vorgang | „Der Schlüssel ist eingetragen." |
| Die Lage | „Der Zugang steht: 1 Schlüssel, Verzeichnis und Rechte in Ordnung." |
| `sshd -t` | `rc=0` |
| Schlüsseldatei | `315` Byte |
| Der Block | am Ende, `Match all` als letzte Zeile des Bereichs |
| Prüfsumme | `4a141234…9018e` — **REF-C zum dritten Mal** |
| Anmeldung | `Connected to cloudsrv24.de.` |

**Zwei Zahlen, die sich unabhängig voneinander bewegen, und beide stimmen.** Die
Bezeichnung hiess `Win11TestNeu2` statt `Win11TestNeu` — ein Zeichen mehr, und
die Schlüsseldatei wuchs von 314 auf **315** Byte. Die Prüfsumme von
`sshd_config` blieb dabei **gleich**, weil der Block die Bezeichnung nicht kennt:
Er nennt Benutzer und Wurzel.

> **Zwei Grössen, die sich verschieden verhalten müssen, und genau so tun — das
> ist mehr als eine Bestätigung, das ist die Bauart.**

**Damit sind alle vier Zeilen von Punkt 8 aus `docs/58` erfüllt**, in beiden
Richtungen des Blocks, mit fünf Prüfsummen unterwegs und je einem Vorgänger, gegen
den jede steht. Dazu die zwei Befunde 12 und 13, die keiner davon verlangt hat.

### Die Zeile aus Punkt 6, dritter Anlauf — sie ist nicht da

```
journalctl -u ssh --since today --no-pager -g 'bad ownership'
-- No entries --
```

**Das ist keine Zeitfensterfrage mehr.** Der erste Anlauf war zu eng (`2 min`),
der zweite hatte kein Fenster und musste abgebrochen werden; dieser ist bemessen,
läuft durch und sagt: heute keine solche Zeile.

`tests/sftp-messen.sh` findet dieselbe Meldung dagegen zuverlässig — dort liest
sie aus der Datei, die `sshd -E` schreibt, und nicht aus dem Journal.

> **Eine Meldung, die in einer Protokolldatei steht, muss deswegen nicht im
> Journal stehen.**

**Gemessen, vierter Anlauf — und die Zeilen sind da.** Ohne den Unit-Filter:

```
Aug 17 10:57:50 sshd[248448]: fatal: bad ownership or modes for chroot directory "/var/www/vhosts/p6-b.invalid"
Aug 17 11:02:12 sshd[248609]: fatal: bad ownership or modes for chroot directory component "/var/www/vhosts/"
```

**Beide Wortlaute, und der zweite mit dem Schrägstrich am Ende** — genau wie
`docs/58` es für das höhere Glied vorhergesagt hat. Dieselben zwei Zeilen stehen
in `/var/log/auth.log`. **Punkt 6 ist damit vollständig erfüllt**, in allen drei
Zeilen und für beide Glieder.

Und die Begründung des Schritts hält: Der Grund steht im Serverprotokoll und
nirgends beim Klienten. `docs/50 §6` und `SftpCheck` stehen weiter auf dem, worauf
sie gebaut sind.

Was nicht hielt, war **mein Aufruf** — siehe Befund 14.

---

## 9. Was `reload` tut, wenn nichts läuft

### Stufe 0 — die Anordnung, gemessen statt aus `docs/57 §13` zitiert

```
ss -ltnp | grep ':22'
LISTEN 0 4096 0.0.0.0:22 users:(("sshd",pid=194168,fd=3),("systemd",pid=1,fd=246))
LISTEN 0 4096    [::]:22 users:(("sshd",pid=194168,fd=4),("systemd",pid=1,fd=250))

systemctl is-active ssh.service ssh.socket   →  active / active
systemctl show -p KillMode --value           →  process
```

**Beide halten denselben Horchsocket.** Das ist Socket-Aktivierung mit
`Accept=no`: systemd hat ihn angelegt, `ssh.service` hat ihn übernommen und
bedient alle Verbindungen — ein `sshd` und nicht einer je Verbindung. `docs/58`
Punkt 9 hat das als Frage geführt; hier steht es als Messung.

### Stufe 1 — anhalten, und der Port bleibt offen

```
systemctl stop ssh.service
Stopping 'ssh.service', but its triggering units are still active: ssh.socket

systemctl is-active ssh.service ssh.socket   →  inactive / active
ss -ltnp | grep ':22'   →  nur noch users:(("systemd",pid=1,…))
```

Der gewollte Zustand, und systemd sagt selbst, warum er nicht folgenlos ist:
**die auslösende Unit läuft weiter.**

### Stufe 2 — und hier hat der Lauf sich selbst überholt

| gemessen | |
|---|---|
| `systemctl is-active ssh.service` | **`active`** |
| Journal | **vier** `Reloading ssh.service` (12:12:47, 12:13:23) |
| Prüfsumme, Schlüsseldatei, `sshd -t` | `4a141234…9018e`, 315 Byte, `rc=0` |

**Der Zustand war weg, bevor die Panelaktion lief.** Gemessen wurde damit der
Zweig „Dienst läuft" — also die Gegenprobe aus Stufe 4, ohne dass jemand sie
angefordert hätte. Der Zweig „Dienst läuft nicht", um den es in diesem Punkt
geht, ist **nicht** gefahren. Das ist Befund 15.

Die Werte selbst sind dabei in Ordnung, und zwei davon sind Wiederholungen mit
Aussagekraft: `4a141234…9018e` ist REF-C zum **vierten** Mal, und die
Schlüsseldatei steht wieder auf 315 Byte, weil die Bezeichnung `Win11TestNeu3`
genauso lang ist wie `Win11TestNeu2`.

### Stufe 2b — der Zweig, diesmal haltbar, und der eigentliche Fund

```
systemctl stop ssh.socket ssh.service   →  inactive / inactive
ss -ltnp | grep ':22'                   →  nichts. Port 22 ist zu.
```

Die bestehende root-Sitzung lebte weiter (`KillMode=process`). Dann die
Panelaktionen — und die erste Prüfung danach sagte:

```
systemctl is-active ssh.service   →  inactive        (der Zustand hält jetzt)
sshd -t                           →  Missing privilege separation directory: /run/sshd
                                     rc=255
sha256sum                         →  4a141234…9018e   (REF-C, also MIT Block)
ls -l /etc/srvpanel/ssh/          →  total 0          (Schlüsseldatei WEG)
journalctl … -g 'Reload'          →  -- No entries --
```

**Der Zustand hält, und das Entfernen ist gescheitert** — Prüfsumme und `ls`
widersprechen sich: Block da, Datei weg. Das ist genau Befund 12 noch einmal
(`rc.10` trägt die alte Reihenfolge), diesmal mit einer anderen Ursache für den
Abbruch: `sshd -t` selbst.

**Und niemand hat es gesehen.** Auf der Seite stand keine Meldung — Befund 13.
Der anschliessende Eintrag gelang dann („Der Schlüssel ist eingetragen.",
315 Byte, REF-C), weil der Block ja noch stand: `sftp.access` fand nichts zu
ändern, sprang vor `sshd -t` ab und schrieb nur die Schlüsseldatei.

> **Ein Vorgang, dessen Fehlschlag unsichtbar ist, sieht in jedem Protokoll wie
> ein Erfolg aus.**

Gerettet hat diese Messung nicht die Seite, sondern die zwei Zeilen daneben.

### Stufe 3 — die Anmeldung weckt den Dienst

```
systemctl start ssh.socket   →  :22 horcht wieder, nur systemd
systemctl is-active …        →  inactive / active
```

Dann von aussen: `Connected to cloudsrv24.de.` — die Anmeldung gelingt bei
ruhendem Dienst, der Socket startet ihn, und **niemand hat neu geladen.** Damit
ist die dritte Zeile aus `docs/58` Punkt 9 belegt.

---

## 10. Der Rückbau — und die Reste, die keine sind

Gefahren mit einem **zweiten** Abonnement (`p6-c.invalid`, `p1137`), damit
`p6-b.invalid` für Punkt 11 stehenbleibt. Damit hat der Lauf zum ersten Mal
**zwei Blöcke in einer Datei** gesehen.

### Stufe 1 — zwei Blöcke, ein Abschluss

```
Match User p1136 … (p6-b.invalid)
Match User p1137 … (p6-c.invalid)
Match all
# END srvpanel
```

| erwartet | gemessen |
|---|---|
| Zwei `Match User`-Blöcke im Bereich | erfüllt |
| Nach Benutzernamen sortiert | `p1136` vor `p1137` |
| **Ein einziges** `Match all`, am Ende des Bereichs | erfüllt |
| Zwei Schlüsseldateien, `root:root 0644` | 315 und 321 Byte |
| `sshd -t` | `rc=0` |

Prüfsumme mit zwei Blöcken: `257e0479…65f94` (**REF-2**).

**Und die Byte-Arithmetik stimmt zum dritten Mal:** `Win11TestGegenprobe` ist
sechs Zeichen länger als `Win11TestNeu4`, die Datei sechs Byte grösser.

### Stufe 2 — jeder Schlüssel gilt nur für seinen Zugang

| | gemessen |
|---|---|
| `p6-fremd` gegen `p1137` | `Connected`, `pwd` ist `/` |
| **derselbe Schlüssel gegen `p1136`** | `Permission denied (publickey).` |

Das ist die Mandantentrennung auf der Ebene von OpenSSH, und sie ist hier zum
ersten Mal mit zwei echten Zugängen gemessen statt mit einem und einer Absage.

### Stufe 3 — der Rückbau lässt nichts liegen

| erwartet | gemessen |
|---|---|
| Der zweite Block ist fort | nur noch `p1136`, dann `Match all` |
| Prüfsumme wieder **REF-C** | `4a141234…9018e`, zeichengleich |
| Verzeichnis unter `/var/www/vhosts` fort | nur `p6-b.invalid` |
| Systembenutzer fort | `id: 'p1137': no such user`, `grep -c` = 0 |
| `/etc/srvpanel/ssh/` ohne Rest | **`total 4`, nur `p1136`** |
| `sshd -t` | `rc=0` |

**Punkt 10 ist damit vollständig erfüllt**, alle vier Zeilen aus `docs/58`.

### Und die Vorhersage war falsch

Ich hatte vorhergesagt, dass die Schlüsseldatei `p1137` liegenbleibt: Der Rückbau
rufe nur `Sftp::sync()`, und das schreibe den Block, nicht die Datei.

**Sie ist weg, und zwar weil es gebaut wurde.** `SubscriptionRemove` entfernt sie
— mit einem Kommentar, der `docs/35` wörtlich zitiert: „Sie liegt ausserhalb der
Abo-Wurzel … und damit nimmt sie das Löschen des Verzeichnisses **nicht** mit."

**Der Grund für den Fehlschluss ist der Lehrsatz.** Ich habe an drei Stellen
gesucht — `Lifecycle::withdraw()`, `SftpAccess`, `SftpKeyApply` — und alle drei
gehören zum *Merkmal* SFTP. Der Rückbau von Dateien gehört aber zum *Handelnden*,
und der ist der Agent: `SubscriptionRemove`. Die erste Grenze aus `CLAUDE.md` sagt
genau das, und ich habe an ihr vorbeigesucht.

> **Wer einen Rückbau beim Merkmal sucht, findet ihn nicht — er steht beim
> Handelnden.**

> **Eine Vorhersage aus dem Quelltext ist so gut wie die Suche, auf der sie
> beruht — und eine falsche Vorhersage, die man vorher ausspricht, kostet nichts
> und belegt etwas.**

Bei Befund 12 hat dasselbe Verfahren einen echten Fehler gefunden; hier hat es
einen Beleg erzeugt. Beides ist mehr als eine Messung ohne Erwartung.

---

## 11. Die Wände

### Wand 1 — die Mandantenklammer, und sie antwortet vor der Policy

Dieselbe Adresse zweimal: `/subscriptions/137/sftp`.

| als | gemessen |
|---|---|
| Admin | die Seite (unbeschränkt über `forAccount()`) |
| zweiter Kunde, ohne Abonnement | **`404 Not Found`** |

**Und die Antwort ist 404 und nicht 403** — das ist Befund 17. Die 404-Seite nennt
das Abonnement nicht, nennt keinen Grund und bestätigt damit nicht einmal, dass
die Kennung existiert.

### Wand 2 — offen, mit Namen

Die Policy kommt nur zum Zug, wenn das Abonnement **sichtbar** ist und das Recht
fehlt: ein Zusatzbenutzer desselben Kunden ohne `ftp_accounts`. Der Betreiber hat
den Umweg abgewählt, und das ist eine Entscheidung und kein Versehen.

**Offen bleibt damit:**

- `403` statt `404`, wenn das Abonnement sichtbar ist und `manageSftp` verneint
- die Kehrseite aus `AbilityReachTest`: dass ein solcher Benutzer den Weg dorthin
  **nicht gezeigt** bekommt

Beides trägt bis dahin der Wächter allein — geprüft ist die Regel, nicht der
laufende Server.

### Eine Beobachtung, die keinen Fix in diesem Wurf bekommt

Die 404-Seite ist Laravels eigene: weisse Fläche, `404 | Not Found`, kein Menü,
kein Weg zurück. Für einen **angemeldeten** Kunden, der sich vertippt hat, ist das
eine Sackgasse ohne Ausgang.

Das gilt für jede 404 dieses Panels und nicht für den SFTP-Zugang; es ist in
diesem Lauf nicht gemessen worden und gehört nicht in diesen Zweig. Festgehalten,
damit es nicht aus Gewohnheit übersehen wird.

### Die vier Eingaben — alle abgewiesen, und keine hat geschrieben

| Eingabe | Meldung |
|---|---|
| privater Schlüssel (mehrzeilig) | „In dem Schlüssel steht ein Steuerzeichen…" |
| `command="/usr/bin/id" ssh-ed25519 …` | „…fängt nicht mit einem Schlüsseltyp an, sondern mit „command="/usr/bin/id""" |
| zwei Schlüssel, durch Umbruch getrennt | „In dem Schlüssel steht ein Steuerzeichen…" |
| RSA mit 1024 Bit | „Der RSA-Schlüssel hat **1024 Bit**; angenommen werden ab 2048…" |

**Und die zwei Zeilen, die mehr sagen als die vier Meldungen:**

```
sha256sum /etc/ssh/sshd_config   →  4a141234…9018e   (unverändert, REF-C)
wc -l /etc/srvpanel/ssh/p1136    →  4                (drei Kopfzeilen, ein Schlüssel)
```

> **Eine abgewiesene Eingabe, die nichts geschrieben hat, ist eine Wand. Eine,
> die mit einer Meldung antwortet und trotzdem schreibt, ist eine Tür mit
> Aufschrift.**

Vier Wände. Und die vierte nennt die Zahl, wie das Kriterium es verlangt.

**Punkt 11 ist damit erfüllt, bis auf Wand 2** — und der erste Eintrag der Tabelle
ist Befund 18.

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

---

### Befund 14 — ein Unit-Filter ist eine Annahme darüber, wer geschrieben hat

Dreimal `-- No entries --` bei `journalctl -u ssh`, und im vierten Anlauf ohne
`-u` standen die Zeilen sofort da. Der Filter war der Fehler, nicht das
Zeitfenster und nicht das Journal.

**Und das Verwirrende daran ist, warum es so lange plausibel blieb:** Derselbe
Filter hat in Punkt 5 funktioniert. `journalctl -u ssh` zeigte dort `Accepted
publickey`, `session opened` und `Connection reset` — Zeilen **derselben
Verbindungen**, deren `fatal: bad ownership` er nicht zeigt.

```
systemctl is-active ssh.socket   →  active
```

Damit laufen Verbindungen über Socket-Aktivierung, und die dabei entstehenden
`sshd`-Prozesse gehören nicht alle zu `ssh.service`. Welcher Prozess in welche
Unit fällt, ist die Frage von **Punkt 9** — er ist genau dafür da.

> **Ein Unit-Filter im Journal ist eine Annahme darüber, wer die Zeile
> geschrieben hat.**

> **Ein Filter, der einmal das Richtige zeigt, ist damit noch keine Zusage über
> die Zeile, die man sucht.**

Das ist der dritte Anlauf an derselben Zeile und der dritte Fehler daran: zu
enges Fenster, kein Fenster, falscher Filter. Jeder davon sah nach „die Meldung
gibt es nicht" aus.

> **Drei verschiedene Fehler am Messmittel ergeben dreimal dieselbe falsche
> Antwort — und die sieht jedes Mal wie ein Befund über den Prüfling aus.**

**Behoben** in `docs/58`: Der Aufruf fragt ohne `-u` und mit bemessenem Fenster.

---

### Befund 15 — ein Zustand, den ein öffentlicher Server nicht hält

`ssh.service` war `inactive`, als Stufe 1 endete, und `active`, als die
Panelaktion in Stufe 2 lief. Dazwischen lagen ein paar Klicks im Browser.

**Wer ihn geweckt hat, ist nicht belegt** — und es ist auch nicht die
interessante Frage. Auf einem Server mit öffentlicher Adresse genügt *irgendeine*
Verbindung auf Port 22: die Überwachung des Anbieters, ein Wörterbuchangriff, ein
Scanner. Der Socket horcht weiter, und die erste Verbindung startet den Dienst.

> **Ein Zustand, den ein Fremder von aussen beenden kann, lässt sich nicht
> messen, indem man ihn herstellt und dann arbeitet.**

Damit ist die Anleitung zu Punkt 9 falsch gebaut, und zwar zweimal: Sie hat den
Zustand hergestellt und danach eine Handlung im Browser verlangt (Sekunden bis
Minuten), und sie hat aus `is-active` einmal gelesen, statt bei jeder Messung
mitzulesen. Die zweite Hälfte hat sie gerettet — ohne das `is-active` neben der
Prüfsumme hätten die vier `Reload`-Zeilen wie ein Fehler des Panels ausgesehen.

> **Eine Messung, die den Zustand nicht neben dem Ergebnis mitschreibt, kann
> nicht unterscheiden, ob der Prüfling falsch war oder die Lage anders.**

**Der Weg, der bleibt:** den Socket mit anhalten. Dann ist Port 22 zu, niemand
kann den Dienst wecken, und der Zustand hält, solange man ihn braucht. Der Preis
ist ein Fenster ohne neue SSH-Verbindungen — genau das, wofür Punkt 0 die zweite
Sitzung und die Anbieterkonsole verlangt.

**Und was Punkt 9 damit ohnehin schon belegt hat:** Die Gegenprobe aus Stufe 4 —
mit laufendem Dienst wird neu geladen, und das Journal sagt es — ist gefahren und
grün. Es fehlt die Null daneben, nicht die Zahl.

---

### Befund 16 — `sshd -t` braucht eine Umgebung, die der Dienst mitbringt

**Der grösste Fund von Punkt 9, und er stand in keinem Plan.** Bei angehaltenem
Dienst:

```
sshd -t
Missing privilege separation directory: /run/sshd
rc=255
```

`/run/sshd` legt die Unit an (`RuntimeDirectory=sshd`), und systemd räumt es beim
Anhalten weg. Damit scheitert die Prüfung des Kandidaten an der **Umgebung des
Prüfers** statt am Prüfling — und das Panel meldet „Der Zugangsblock ist von sshd
abgewiesen worden", was nicht stimmt: Der Block ist einwandfrei.

> **Eine Prüfung, die die Umgebung des Prüfers braucht, prüft nicht nur den
> Prüfling.**

**Es trifft genau den Zustand, für den `SftpAccess::reload()` gebaut ist.** Der
Zweig „läuft nicht ist kein Fehlschlag" kam nie zum Zug, weil die Prüfung *davor*
liegt und abbricht. Und es trifft auch einen frisch aufgesetzten Server, auf dem
`sshd` noch nie lief.

Nachgemessen im Container gegen dieselbe Fassung (OpenSSH 9.6p1), mit Gegenprobe:

| `/run/sshd` | `sshd -t` | `sshd -T` |
|---|---|---|
| `0755 root:root` | `rc=0`, still | `rc=0` |
| fehlt | **`rc=255`** | `rc=0`, mit Warnzeile |
| `0777` | **`rc=255`**, anderer Wortlaut | `rc=0`, mit Warnzeile |

**`sshd -T` ist nicht betroffen** — es gibt `rc=0` und schreibt die Warnung als
Zeile dazu; `SftpCheck` liest zeilenweise nach Schlüsselwörtern und übergeht sie.
Betroffen ist die Prüfung, nicht die Auskunft. Ohne diese Gegenprobe hätte der
Fund doppelt so gross ausgesehen, wie er ist.

**Behoben**: `SftpAccess::ensureRuntime()` legt das Verzeichnis an, wenn es fehlt,
und rückt es zurecht, wenn es für Gruppe oder Andere schreibbar ist — mit
derselben Regel wie die Kettenprüfung. Ein taugliches Verzeichnis wird **nicht**
angefasst; ein Verzeichnis des Systems bekommt keine neuen Rechte, weil wir
vorbeikommen.

Der Wächter ist `SftpRuntimeDirTest` mit vier Regeln — anlegen, zurechtrücken,
in Ruhe lassen, und **die Reihenfolge**: Der Aufruf steht vor `sshd -t`. Der
Fehler war nicht, dass das Verzeichnis fehlte, sondern dass niemand danach sah,
bevor geprüft wurde. Beide Brüche sind gegengeprüft.

---

### Befund 17 — die vordere Wand antwortet zuerst, und der Lauf kannte nur die hintere

`docs/58` Punkt 11 verlangt für eine fremde Abo-Kennung **403**. Gemessen wurde
**404**, und das ist richtig so.

`Subscription` trägt einen globalen Mandantenscope
({@see App\Models\Subscription}, `booted()`): Ohne Berechtigung klammert er die
Abfrage auf `whereRaw('0 = 1')`. Die Route-Bindung findet damit nichts und wirft
404 — **bevor** `can:manageSftp` überhaupt läuft.

> **Eine Wand, die vor der anderen steht, antwortet zuerst — und eine Prüfung,
> die nur die hintere kennt, prüft die falsche.**

Und die Reihenfolge ist die bessere: Ein `403` sagt „das gibt es, du darfst
nicht"; ein `404` sagt nichts. Für eine Kennung, die durchprobiert werden kann,
ist das der Unterschied zwischen einer Auskunft und keiner.

**Der Fehler war also nicht im Code, sondern im Kriterium** — und er hätte eine
richtige Antwort als Abweichung protokolliert. `docs/58` nennt jetzt beide Wände
getrennt, mit der Antwort, die zu jeder gehört.

---

### Befund 18 — eine Meldung, die nie erscheint, weil eine allgemeinere davor steht

Die vier Eingaben aus Punkt 11 haben **zwei identische Meldungen** erzeugt: der
private Schlüssel und die zwei aneinandergehängten Schlüssel. Beide bekamen „In
dem Schlüssel steht ein Steuerzeichen."

Für den zweiten Fall ist das der richtige Satz. Für den ersten gibt es einen
eigenen, sorgfältig geschriebenen:

> Das ist ein **privater** Schlüssel. Hierher gehört die Datei mit der Endung
> `.pub` — der private bleibt auf Ihrem Rechner und wird nirgends hochgeladen.

**Er war unerreichbar.** Die Prüfung auf Steuerzeichen stand davor, und ein
eingefügter privater Schlüssel hat *immer* Zeilenumbrüche. Erreichbar war der Satz
nur für eine **einzige Zeile**, die mit `-----BEGIN` anfängt — und die tippt
niemand von Hand.

> **Eine Meldung, die hinter einer allgemeineren Prüfung steht, ist keine Meldung
> — sie ist ein Kommentar.**

Bemerkenswert ist, wie lange das gehalten hat: Der Wächter dazu gab es seit dem
ersten Tag, und er war **grün** — er prüfte genau die eine Fassung, die den Zweig
erreicht. Ein Testdatensatz aus einer Zeile hat einen Fall geprüft, den es in der
Praxis nicht gibt.

> **Ein Prüfdatum, das der Code mag, prüft den Code nicht.**

**Behoben** durch Umstellen der Reihenfolge: von der **engsten** Erkennung zur
weitesten. Abgewiesen wird der private Schlüssel in beiden Fassungen — verschieden
ist nur, was der Kunde erfährt. Der Wächter trägt jetzt den mehrzeiligen Fall
dazu; der Bruch (Reihenfolge zurückdrehen) macht ihn rot, gegengeprüft.

**Was ausdrücklich nicht geändert wurde:** die Meldung für `command="…"`. Sie
nennt den störenden Anfang wörtlich, und das ist genau, was der Leser braucht. Ein
eigener Zweig je Form wäre eine Liste, die wächst.

---

### Befund 19 — derselbe Umweg, ein Merkmal später

**Gemeldet vom Betreiber am Ende des Laufs**, nicht von einem seiner Punkte: Der
SFTP-Zugang war nur über das Abonnement erreichbar — Abonnements, Name, Bereich.
Drei Klicks, und keiner davon beantwortet eine Frage.

Das ist wörtlich die Lage, in der der **Dateimanager** vor `docs/55` Befund 8
stand. Damals bekam er `/files`: einen Menüpunkt ohne Kennung darin, der bei
genau einem erreichbaren Abonnement hineinführt und bei mehreren zur Auswahl.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.**

Und er ist zwölf Tage später wieder aufgetreten, im Lauf, der das nächste Merkmal
abnimmt. Bemerkenswert daran: **Nichts hat es gemeldet.** Es gibt einen Wächter,
der prüft, dass jeder Menüpunkt ein Zeichen trägt (`NavIconTest`), und einen, der
prüft, dass kein Knopf ohne Recht dasteht (`AbilityReachTest`) — aber keinen für
die Frage, ob ein Merkmal überhaupt einen Weg hat, der nicht durch eine Kennung
führt. Die Frage ist auch schwer zu stellen: Sie hängt daran, was ein Kunde
*sucht*, und nicht daran, was im Quelltext steht.

**Gebaut**: `/sftp` → `SftpController::pick()`, wortgleich die Bauart von
`FileController::pick()`, mit `Subscriptions/SftpPick.vue` als Zwillingsseite von
`Files/Pick.vue`. Der Menüpunkt steht **hinter** „Dateien": Beide führen in
dasselbe Verzeichnis, der eine im Browser und der andere von aussen, und wer an
seine Dateien will, findet den kürzeren Weg zuerst.

**Das Zeichen ist kein Schlüssel**, obwohl der Zugang an einem hängt: `dns` ist
schon einer. Zwei Schlüssel in derselben Spalte unterscheidet im Vorbeigehen
niemand — dasselbe Argument, das dort gegen einen zweiten Globus stand. Es sind
zwei Pfeile gegeneinander: die Übertragung, und das Einzige, was die Richtung auf
17 px noch zeigt.

**Die Route trägt kein `can:`** und dafür eine Begründung im `RouteGuard` — sie
hat kein Objekt, an dem eine Policy ansetzen könnte, und sucht aus den
Abonnements, die die Mandantenklammer ohnehin sichtbar macht, diejenigen mit
`manageSftp` heraus. Wortgleich die Lage von `GET files`.

**Nachzuprüfen im nächsten Durchgang** (er kommt zu den fünf Punkten oben dazu):
der Menüpunkt bei einem Abonnement — er muss **hineinführen** und nicht zur
Auswahl —, die Auswahlseite bei zwei, und beide bei 390 px.

---

# Der zweite Durchgang — gegen `v0.6.0-rc.11`

Elf Korrekturen lagen im Zweig, als der erste Durchgang sie fand; hier werden sie
am Server nachgeprüft. `main` steht auf `7434f01` (PR #139), das Paket ist am
17. August 2026 eingespielt.

## A — Fassung und Ausgangszustand

| | gemessen |
|---|---|
| `srvpanel --version` | **0.6.0-rc.11** |
| Prüfsumme `sshd_config` | `8e5c38ed…27aed` — **REF-C′** |
| Schlüsseldatei | 315 Byte |
| `sshd -t` | `rc=0` |

**REF-C′ ersetzt REF-C.** Die Datei hat sich beim ersten Schlüsselvorgang nach
dem Update geändert, weil der Block jetzt `AuthenticationMethods publickey`
trägt — und `sshd -T -C user=p1136` sagt es auch: **Befund 6 ist am Server
bestätigt.**

### Eine Nebenbeobachtung, die den Lauf beinahe stört

```
sh: 0: getcwd() failed: No such file or directory
```

Zweimal, vor jeder Ausgabe. Die Sitzung steht in `/opt/srvpanel/current`, und das
Update hat den Verweis auf ein neues Fassungsverzeichnis gelegt: Das
Arbeitsverzeichnis der Shell zeigt auf ein Verzeichnis, das es nicht mehr gibt.

Für die Messungen hier folgenlos — aber nicht für alles: Ein Programm, das sein
Arbeitsverzeichnis braucht, scheitert daran mit einer Meldung, die nach etwas
anderem aussieht.

> **Ein Update, das das Verzeichnis unter einer offenen Sitzung austauscht,
> hinterlässt eine Shell, die auf nichts steht.**

Gehört nicht in diesen Zweig; festgehalten, weil es die nächste Fehlersuche
kosten kann. Der Griff dagegen ist `cd /opt/srvpanel/current`.

## B — die Wortlaute (Befunde 2 und 3)

Schlüssel entfernt, Seite angesehen:

> Es ist kein Schlüssel eingetragen — damit ist der Zugang aus. Tragen Sie unten
> einen ein.

| erwartet | gemessen |
|---|---|
| grau, und der Satz benennt den **Zustand** | erfüllt |
| keine rote Meldung über die fehlende Schlüsseldatei | erfüllt |
| keine orange Meldung über „ein anderes Verzeichnis … `none`" | erfüllt |

**Befunde 2 und 3 sind behoben und am Server bestätigt.** Im ersten Durchgang
stand hier rot „Der Zugang kommt so nicht zustande" für ein Abonnement, an dem
nur noch niemand etwas eingerichtet hatte — und daneben, nach einem Rückbau, die
grüne Erfolgsmeldung.

Befund 4 — das Leerzeichen — ist hier **nicht** zu sehen: Er braucht die rote
Meldung, und die gibt es in diesem Zustand zu Recht nicht. Er kommt in Block C.

## C — der Fehlerweg (Befunde 12 und 13 bestätigt)

Kaputte Zeile angehängt (`rc=255`, **REF-D′** `1fcf2ef5…42a2d`), dann im Panel
den Schlüssel entfernt:

> Der Zugangsblock ist von sshd abgewiesen worden; an der Datei wurde nichts
> geändert: `/etc/ssh/sshd_config.srvpanel.candidate: line 134: Bad configuration
> option: Klabautermann`

| erwartet | gemessen |
|---|---|
| Eine rote Meldung erscheint überhaupt | **erfüllt** — Befund 13 |
| Prüfsumme == REF-D′ | `1fcf2ef5…42a2d`, unverändert |
| Kein `.candidate` | nur die Sperre, 0 Byte |
| **Die Schlüsseldatei ist noch da** | **`total 4`, 315 Byte — Befund 12** |
| Die Tabelle zeigt den Schlüssel weiter | erfüllt |

**Die vierte Zeile ist der Beleg, auf den es hier ankommt.** Im ersten Durchgang
stand an genau dieser Stelle `total 0`: Die Datei war gelöscht, die Datenbankzeile
zurückgerollt, und der Zugang tot, während die Seite den Schlüssel weiter
aufführte. Jetzt geht `sync()` vor `write()`, der Abbruch kommt vor dem ersten
Schreibzugriff, und nichts ist geschehen.

**Und die beiden Befunde hatten sich gegenseitig gedeckt:** Der Rest war
unsichtbar, weil die Meldung fehlte, und die fehlende Meldung fiel nicht auf, weil
der Rest keinen Lärm machte. Beide sind jetzt einzeln belegt.

### Zwei Erwartungen dieses Blocks waren falsch gestellt

Die Anleitung verlangte auf **dieser** Meldung zwei Dinge, die dort nicht
hingehören:

- **den Satz „Der Schlüssel ist in Ordnung; der Server hat die Änderung nicht
  angenommen."** Er steht in `store()` — beim *Eintragen*. Hier wurde *entfernt*,
  und dabei trägt niemand einen Schlüssel ein, über dessen Zustand man etwas sagen
  könnte. `destroy()` gibt die Meldung des Agenten unverändert weiter, und das ist
  richtig.
- **das Leerzeichen aus Befund 4.** Das betrifft die rote Meldung unter „Lage",
  die aus mehreren Elementen zusammengesetzt ist — nicht diese hier, die eine
  einzige Einsetzung ist und gar keine Lücke haben kann.

> **Eine Erwartung, die einen Satz an einer Stelle verlangt, an der er nicht
> entstehen kann, prüft nicht den Code — sie prüft, ob der Prüfer weiss, welchen
> Weg er gerade geht.**

Dasselbe Muster wie bei Befund 8 und 15: eine Anweisung, die ein Ergebnis
erwartet, das der Code in diesem Zustand nicht erzeugen kann. **Befund 11 und
Befund 4 sind damit weiter ungeprüft** und bekommen ihren eigenen Block.

## C2 — Befund 4 bestätigt, Befund 11 übersprungen

Zeile entfernt (Prüfsumme wieder **REF-C′**), Schlüssel entfernt, wieder
eingetragen, dann die Wurzel dem Benutzer gegeben:

> **Der Zugang kommt so nicht zustande.** `/var/www/vhosts/p6-b.invalid` gehört
> p1136 und nicht root (Eigentümer `p1136`, Rechte `0755`).

**Zwischen `zustande.` und dem Pfad steht ein Leerzeichen.** Im ersten Durchgang
stand dort `zustande./etc/srvpanel/ssh` — **Befund 4 ist behoben und am Server
bestätigt.** Danach `chown root:root` zurück, Prüfsumme unverändert `8e5c38ed…27aed`.

**Befund 11 ist dabei übersprungen worden.** Der Schlüssel wurde bei *heiler*
Datei wieder eingetragen; der Weg über `store()` mit kaputter `sshd_config` — der
einzige, auf dem die Feldmeldung entsteht — ist nicht gegangen worden. Er bleibt
als einzelne Messung offen.

> **Eine Anleitung mit zwei Schritten in einem Block verliert den, der weniger
> sichtbar ist.**

## C3 — Befunde 11 und 9 bestätigt

**Befund 11**, Schlüssel eintragen bei kaputter `sshd_config`:

> **Das Formular wurde nicht gespeichert.**
> Der Schlüssel ist in Ordnung; der Server hat die Änderung nicht angenommen.
> Der Zugangsblock ist von sshd abgewiesen worden; an der Datei wurde nichts
> geändert: …

| erwartet | gemessen |
|---|---|
| der Satz über den Schlüssel steht davor | erfüllt |
| dahinter die Meldung von `sshd -t` | erfüllt |
| **das Textfeld ohne roten Rand** | erfüllt |

Der Rand ist der eigentliche Beleg: Im ersten Durchgang war er da, obwohl der
Schlüssel einwandfrei war. Danach Zeile entfernt, Schlüssel eingetragen — grün.

**Befund 9**, `chmod 0777 /var/www/vhosts`:

> `/var/www/vhosts` **ist für die Gruppe und für alle schreibbar** (Eigentümer
> root, Rechte `0777`).

Ein Satz. Im ersten Durchgang stand dort „ist für die Gruppe schreibbar **und
ist** für alle schreibbar".

### Stand des zweiten Durchgangs

| Befund | am Server bestätigt |
|---|---|
| 2, 3 („kein Schlüssel" als Zustand, `none`) | **ja** |
| 4 (das Leerzeichen) | **ja** |
| 6 (`AuthenticationMethods publickey`) | **ja** |
| 9 (ein Satz statt zwei) | **ja** |
| 11 (der rote Rand am richtigen Ort) | **ja** |
| 12 (die Schlüsseldatei überlebt) | **ja** |
| 13 (die Meldung erscheint überhaupt) | **ja** |
| 7, 18 (die beiden Formularmeldungen) | **ja** — und mit Befund 20 |
| 10 (das Verzeichnis, das gilt) | **ja** |
| 16 (`/run/sshd`) | **ja** |
| 19 (der Menüpunkt) | Block E |

## C4 — Befunde 7 und 18 bestätigt, und ein neuer daneben

**Befund 7**, die Ausgabe von `ssh-keygen -lf` eingetragen:

> Das ist der Fingerabdruck eines Schlüssels, wie ihn `ssh-keygen -l` ausgibt —
> nicht der Schlüssel selbst.

**Befund 18**, der mehrzeilige private Schlüssel:

> Das ist ein privater Schlüssel. Hierher gehört die Datei mit der Endung `.pub`.

Beide erscheinen, beide benennen den Fall. Im ersten Durchgang hiess der erste
„unbekannter Typ" und der zweite „In dem Schlüssel steht ein Steuerzeichen".

---

### Befund 20 — die Auszeichnung stand als Zeichen im Satz

**Auf beiden Bildern von C4 steht die Markdown-Syntax da**, wörtlich:

```
Das ist der **Fingerabdruck** eines Schlüssels, wie ihn `ssh-keygen -l` ausgibt
Das ist ein **privater** Schlüssel. Hierher gehört die Datei mit der Endung `.pub`
```

Die Meldungen des Agenten sind in Markdown geschrieben, und niemand übersetzt sie.
Das Panel zeigt sie als Text, und **das ist richtig so**: Eine Meldung, die HTML
erzeugt, ist eine Meldung, in der Kundeneingaben stehen — der Typname kommt aus
dem Formular.

> **Eine Auszeichnung, die niemand übersetzt, ist ein Zeichen im Satz.**

**Der teuerste Teil ist nicht der Fehler, sondern wie er überlebt hat.** Er stand
in **vier** Aufnahmen dieses Laufs — zum ersten Mal in Punkt 11 des ersten
Durchgangs, bei der Meldung über `command="…"` —, und ich habe ihn viermal
gelesen, ohne ihn zu sehen. Jedes Mal war die Frage „steht der richtige Satz
da?", und die Antwort war ja.

> **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
> verdeckt alles, was daneben steht.**

Das ist die genaue Umkehrung der Lehre aus `docs/46 §20.11`. Dort hiess es: Ein
Bild zeigt, dass etwas fehlt, und die Zahl sagt, ob die Seite schiebt. Hier war
das Bild da, vollständig und scharf — und die Frage war die falsche.

**Behoben**: Neun Meldungen in `PublicKey` und eine in `PgServerInstall` tragen
jetzt deutsche Anführungszeichen statt Sternchen und Schrägstrichzeichen — die
Form, die die Meldungen dieses Panels ohnehin für Bezeichner benutzen
(„…fängt nicht mit einem Schlüsseltyp an, sondern mit „%s"").

Der Wächter ist `MessageMarkupTest`: Jede Zeichenkette unterhalb von `agent/src`
mit einem Umlaut darin trägt keine Auszeichnung. **Der Umlaut ist absichtlich ein
grober Marker** — er trifft deutsche Sätze und weder SQL noch einen Ausdruck. Ein
Bruchstück ohne Umlaut kann durchfallen; dafür hat der Wächter keine Fehlalarme,
und die kosten mehr.

> **Ein Wächter, der bei jedem SQL-Bezeichner meckert, wird abgeschaltet.**

Kommentare sind ausgenommen: Dort **gehört** die Auszeichnung hin — sie ist für
den Leser des Quelltexts geschrieben und erreicht niemanden sonst.

## C5 — Befund 10 bestätigt

Der Eingriff aus Punkt 7 noch einmal, oberhalb des verwalteten Bereichs:

> Der Zugang steht: 1 Schlüssel. **Geprüft ist `/var/www`** — das Verzeichnis,
> das gilt.

Dazu die orange Meldung über die Abweichung. Im ersten Durchgang stand hier
„Verzeichnis und Rechte in Ordnung" — wahr über `/var/www/vhosts/p6-b.invalid`,
also über ein Verzeichnis, das in diesem Augenblick niemand benutzt.

Die Kette hängt jetzt an dem, was gilt, und der Satz nennt es. **Zehn von zwölf
Korrekturen sind damit am Server bestätigt**; offen sind 16 (Block D) und 19
(Block E) — und Befund 20, dessen Behebung erst mit der nächsten Fassung kommt.

## D — Punkt 9 ist vollständig (Befund 16 bestätigt)

```
systemctl stop ssh.socket ssh.service   →  inactive / inactive
ss -ltnp | grep ':22'                   →  nichts
sshd -t                                 →  rc=255   (erwartet: die Shell hat kein /run/sshd)
```

Dann die Panelaktionen, bei ruhendem Dienst und geschlossenem Port:

| erwartet | gemessen |
|---|---|
| Der Vorgang **läuft durch** | erfüllt — Prüfsumme `8e5c38ed…27aed`, Datei 316 Byte |
| `ssh.service` bleibt `inactive` | erfüllt |
| **`/run/sshd` existiert** | `drwxr-xr-x 2 root root 40 Aug 17 14:09` |
| keine `Reload`-Zeile | `-- No entries --` |
| danach horcht der Socket wieder | nur `systemd`, `ssh.service` weiter `inactive` |
| die Anmeldung gelingt und weckt den Dienst | `Connected`, danach `active` |

**Befund 16 ist behoben und am Server bestätigt.** Das Verzeichnis, das systemd
beim Anhalten wegräumt, legt der Agent wieder an — und `sshd -t` prüft damit den
Kandidaten statt an seiner eigenen Umgebung zu scheitern. Im ersten Durchgang
brach das Entfernen hier ab, mit „von sshd abgewiesen" für einen einwandfreien
Block.

**Und `sshd -t` in der Shell sagt weiter `rc=255`.** Das ist kein Widerspruch,
sondern die Trennung: Der Agent stellt seine Umgebung her, die Shell nicht.

> **Damit sind alle vier Zeilen von `docs/58` Punkt 9 belegt** — bis auf den
> Satz, den die vierte verlangt. Der ist Befund 21.

---

### Befund 21 — der Satz über den ruhenden Dienst wurde gebaut und weggeworfen

`docs/58` Punkt 9 verlangt vier Dinge, und das vierte ist: **die Antwort sagt
„gilt ab der nächsten Verbindung"**. `SftpAccess::reload()` baut diesen Satz
wörtlich —

```
ssh.service ist inactive — die neue Datei gilt ab der nächsten Verbindung
```

— `Sftp::sync()` gibt ihn zurück, und `add()` und `remove()` warfen den
Rückgabewert weg. Auf der Seite stand „Der Schlüssel ist eingetragen." und sonst
nichts.

> **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
> keine.**

**Dieselbe Form wie Befund 13, eine Ebene weiter:** Dort fehlte der Träger
zwischen Controller und Seite, hier zwischen Agent und Controller. Zweimal
derselbe Fehler in einem Lauf, an zwei Übergängen desselben Weges.

Vorhergesagt war er — in der Anleitung zu Punkt 9 stand er als Erwartung, bevor
der Punkt lief. **Gemessen ist er nicht**: Block D hat keine Aufnahme der Seite
verlangt, und ohne sie ist „der Satz stand nicht da" eine Aussage über meine
Anleitung und nicht über den Server.

> **Ein Befund aus dem Quelltext ist ein Befund. Er ist nur kein Messwert.**

**Behoben**: `sync()`s Antwort geht durch `Sftp::spokenNote()` — eine reine
Funktion, die von drei Fällen genau einen für eine Auskunft hält: „neu geladen"
ist das Erwartete und sagt nichts, „nichts zu ändern" beschreibt einen Vorgang,
den niemand angefordert hat, und der ruhende Dienst ist der Unterschied, den der
Kunde merkt. Der Controller hängt den Satz an die Erfolgsmeldung.

Der Wächter ist `SftpNoteTest` mit drei Regeln — die Textprüfung („der
Rückgabewert wird nicht weggeworfen", beide Wege) läuft ohne Framework, die
beiden Verhaltensprüfungen in der CI.

### Und der Wächter über die Brüche hat zugebissen

Die Änderung an `Sftp::remove()` hat dem **Bruch zu Befund 12** die Textstelle
unter den Füssen weggezogen: Er suchte `$this->sync();` als eigene Zeile, und die
gibt es nicht mehr.

`BreakScriptTest::test_every_intervention_still_grips_its_file` hat es gemeldet,
bevor irgendetwas gepusht war. **Genau dieser Fall war beim PR vorhergesagt** — im
vorigen Wurf waren es vier veraltete Eingriffe, die auf Code zeigten, der nach
`ManagedBlock` gezogen war.

> **Ein Eingriff, der nichts ändert, prüft nichts — und sieht dabei aus, als wäre
> die Regel abgesichert.**
