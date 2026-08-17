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
