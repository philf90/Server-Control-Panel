# 58 — Die Zwischenabnahme des SFTP-Zugangs

Der Lauf für `cloudsrv24` nach Schritt 8 von P6. Er kommt **vor** Schritt 9
(Cron) und nicht danach, aus zwei Gründen: Von diesem Schritt ist noch keine
Zeile je durch den Agenten gelaufen, und `sshd_config` ist die Datei, deren
Beschädigung aussperrt.

> **Ein Lauf, dessen Rückweg durch die Tür führt, die er zusperren kann, hat
> keinen.**

Das Protokoll entsteht **während** des Laufs und nicht danach; es bekommt seine
Nummer, wenn es angelegt wird. Ein Verweis auf ein Dokument, das es noch nicht
gibt, ist ein toter Verweis — `DocLinkTest` besteht darauf.

---

## 0. Vor allem anderen: der zweite Weg hinein

**Dieser Punkt ist kein Formalismus, und er wird belegt statt angenommen.**

`docs/44` hat vorgeführt, was ein Abnahmelauf anrichtet, der eine ungeprüfte
Annahme als Anweisung führt: Punkt 3 lautete `--bind=::`, und danach gab jede
Seite einen 500er. Hier steht mehr auf dem Spiel — der Prüfling *ist* der
Zugang zum Server.

| | |
|---|---|
| 0a | Eine Konsole beim Anbieter ist offen und **benutzt** worden — nicht „ist verfügbar", sondern: es ist ein Zeichen darüber eingegeben und eine Antwort gesehen worden. |
| 0b | Eine zweite root-Sitzung über SSH ist offen und bleibt es. Eine bestehende Verbindung überlebt einen kaputten `sshd`; eine neue nicht. |
| 0c | `cp /etc/ssh/sshd_config /root/sshd_config.vor-p6` und `sha256sum` davon notiert. |

Erst wenn 0a **gesehen** ist, fängt der Lauf an.

---

## 1. Die Messrunde, vor dem Update

`tests/sftp-messen.sh` fährt gegen einen **eigenen** `sshd` auf Port 22222 und
rührt den laufenden nicht an. Er gehört hierher, weil `docs/57` im Container
gemessen hat und drei Punkte dort nicht messbar waren.

```bash
sudo bash tests/sftp-messen.sh
```

Erwartet: **42 wie erwartet, 0 abweichend.** Jede Abweichung ist ein Befund über
*diese* Maschine und hält den Lauf an.

Und die Zeilen am Ende sind der eigentliche Grund: Sie beurteilen die **echte**
Kette. `docs/50 §8` Punkt 4 ist bis heute offen — wem `/var/www/vhosts` auf
diesem Server gehört, ist nie gemessen worden, und eine Abweichung kostet den
Zugang wortlos.

| erwartet | |
|---|---|
| `/`, `/var`, `/var/www`, `/var/www/vhosts` | `root`, `0755`, „taugt" |

---

## 2. Fassungen

| | Wert |
|---|---|
| `srvpanel --version` vor dem Update | |
| `sshd -V` | |
| `systemctl is-active ssh.service` / `sshd.service` | |
| `systemctl is-enabled ssh.socket` | |
| Prüfsumme `sshd_config` vor dem Update | |

Dann Update einspielen und `php artisan migrate`.

---

## 3. Ohne Schlüssel ist der Zugang aus — und die Datei unberührt

Abonnement `p6-b.invalid` (`p1136`), Seite „SFTP-Zugang" aufrufen.

| erwartet | |
|---|---|
| Die Seite sagt „Es ist kein Schlüssel eingetragen — damit ist der Zugang aus." | |
| `sha256sum /etc/ssh/sshd_config` **unverändert** gegenüber 0c | |
| `/etc/srvpanel/ssh/p1136` gibt es nicht | |

**Der zweite Punkt ist der eigentliche.** Eine Seite, die beim blossen Ansehen
in `sshd_config` schreibt, ist ein Risiko ohne Anlass.

---

## 4. Der erste Schlüssel

Auf dem eigenen Rechner `ssh-keygen -t ed25519 -f p6-sftp -N ''`, den
öffentlichen Teil im Panel eintragen.

| erwartet | |
|---|---|
| Fingerabdruck im Panel == `ssh-keygen -lf p6-sftp.pub` | |
| Der verwaltete Block steht **am Ende** von `sshd_config` und endet mit `Match all` | |
| `sshd -t` sagt nichts | |
| `sftp -i p6-sftp p1136@cloudsrv24` verbindet, `pwd` ist `/` | |
| `ls` zeigt `httpdocs`, `logs`, `conf`, `tmp`, `mail`, `.ssh` | |
| Hochladen einer Datei nach `httpdocs/` gelingt, und sie trägt `p1136:www-data` — das setgid-Bit und `-u 0027` zusammen | |
| **Gegenprobe:** ein *anderer* Schlüssel wird abgewiesen | |

Der letzte Punkt ist die Null neben etwas anderem als Null.

---

## 5. Der Kunde legt sich selbst einen Schlüssel hin

Über den gerade gewonnenen SFTP-Zugang eine `authorized_keys` nach `.ssh/`
laden, die einen **zweiten** Schlüssel enthält.

| erwartet | |
|---|---|
| Die Anmeldung mit diesem zweiten Schlüssel **scheitert** | |
| Im Panel steht er nicht — und das ist die ganze Aussage | |

Im Container gemessen (`docs/57 §4`, mit Gegenprobe); hier auf dem echten
Server, weil davon abhängt, ob die Fingerabdruckliste die Wahrheit ist oder die
Hälfte davon.

---

## 6. Die Ablehnung wird sichtbar — das Kriterium aus `docs/51 §9`

```bash
chown p1136:p1136 /var/www/vhosts/p6-b.invalid
```

| erwartet | |
|---|---|
| Die Anmeldung scheitert; der Klient sieht nur `Broken pipe` o. ä. | |
| **Das Panel nennt genau dieses Verzeichnis**, seinen Eigentümer und seine Rechte | |
| `journalctl -u ssh` trägt `bad ownership or modes for chroot directory "…"` | |

Zurücksetzen (`chown root:root`), Anmeldung geht wieder — die Gegenprobe.

Und derselbe Griff eine Station weiter oben (`chmod 0777 /var/www/vhosts`, sofort
zurück): Die Meldung heisst dann `… component "/var/www/vhosts/"`, **mit
Schrägstrich am Ende**, und das Panel muss auch dieses Glied benennen.

---

## 7. Der Bestand ist Gesetz

Von Hand, **oberhalb** des verwalteten Bereichs, in `sshd_config`:

```
Match User p1136
    ChrootDirectory /var/www
Match all
```

| erwartet | |
|---|---|
| `sshd -T -C user=p1136,…` zeigt `/var/www` — der erste passende Block gewinnt | |
| **Das Panel meldet die Abweichung** und rollt nichts zurück | |
| Nach dem Entfernen der Zeilen ist der Befund weg | |

> **Ein Block, den man geschrieben hat, ist keine Auskunft darüber, was gilt.**

**Und der Eingriff steht ausdrücklich *ausserhalb* des verwalteten Bereichs.**
`docs/45` hat den teuersten Fehler seines Laufs genau hier gemacht: Die kaputte
Zeile stand *innerhalb* des Blocks, den das Panel bei jedem Lauf neu schreibt.

> **Ein Eingriff, den der Prüfling selbst überschreibt, prüft nichts.**

---

## 8. Die kaputte Datei — der wichtigste Punkt des Laufs

Eine unsinnige Zeile in `sshd_config` eintragen, **ausserhalb** des Bereichs:

```
Klabautermann ja
```

Dann im Panel einen Schlüssel eintragen oder entfernen.

| erwartet | |
|---|---|
| Der Vorgang **bricht ab** mit der Meldung von `sshd -t` | |
| `sha256sum /etc/ssh/sshd_config` ist **unverändert** — die Datei wurde nicht angefasst | |
| Es liegt keine `sshd_config.srvpanel.candidate` herum | |
| Der laufende `sshd` bedient weiter; eine **neue** Verbindung kommt zustande | |

Das ist der Beleg dafür, dass geprüft wird, **bevor** geschrieben wird — und
dass der Ablauf aus `docs/38 §14.2` hier nicht kopiert wurde. Gemessen
(`docs/57 §5`): Ein Neuladen mit kaputter Datei beendet den `sshd`.

> **Ein Rückweg, der voraussetzt, dass der Dienst noch läuft, ist keiner für den
> Fall, dass ihn genau dieser Vorgang beendet hat.**

Danach die Zeile entfernen, und eine Kundenaktion muss wieder durchlaufen.

---

## 9. Was `reload` tut, wenn nichts läuft

Auf Ubuntu 24.04 ist `ssh.socket` eingeschaltet — im Container nicht messbar
(`docs/57 §13`).

| | erwartet |
|---|---|
| `systemctl is-active ssh.service` bei ruhendem Dienst | `inactive` |
| Eine Kundenaktion in diesem Zustand | läuft durch; die Antwort sagt „gilt ab der nächsten Verbindung" |
| Eine Anmeldung danach | benutzt die neue Datei |
| Der Unitname, den die Operation gefunden hat | `ssh.service` |

---

## 10. Der Rückbau — und die Reste, die keine sein dürfen

| | erwartet |
|---|---|
| Letzten Schlüssel eines Abonnements entfernen | `/etc/srvpanel/ssh/p1136` ist **weg**, nicht leer; der Block ist aus `sshd_config` verschwunden |
| Ein Abonnement mit Schlüsseln zurückbauen | Schlüsseldatei weg **und** Block weg |
| `ls /etc/srvpanel/ssh` danach | keine Datei ohne Abonnement |
| `sshd -t` nach jedem Rückbau | still |

Der zweite Punkt ist der, den `docs/35` teuer gemacht hat: Die Schlüsseldatei
liegt ausserhalb der Abo-Wurzel — genau deshalb kommt der Kunde nicht an sie
heran — und das Löschen des Verzeichnisses nimmt sie darum nicht mit.

---

## 11. Die Wände

| | erwartet |
|---|---|
| Fremde Abo-Kennung in `/subscriptions/<fremd>/sftp` | 403 |
| Ein privater Schlüssel im Formular | Feldmeldung „Das ist ein **privater** Schlüssel" |
| `command="/usr/bin/id" ssh-ed25519 …` | Feldmeldung, und nichts landet in der Datei |
| Ein Zeilenumbruch mit einem zweiten Schlüssel dahinter | Feldmeldung; die Datei bekommt **eine** Zeile |
| RSA mit 1024 Bit | Feldmeldung mit der Zahl |

---

## 12. Die Bilder

390 px und 1440 px, beide Themes, mit `scrollWidth - clientWidth` und
Gegenprobe. Im Container gemessen (16. August, gegen das gebaute Stylesheet):
**dokument 0 px bei 390 px, Gegenprobe 510 px.**

`docs/56` Punkt 5 hat belegt, dass dieser Aufsatz aufs Pixel stimmt — hier wird
geprüft, ob er es auch für **diese** Seite tut, und zwar mit echten Daten.

**Und die Zahl ersetzt den Blick nicht.** Bei genau dieser Seite stand die
Meldung unter „Lage" bei 390 px als Spalten aus fünf Zeichen da, bei einem
Dokumentüberlauf von 0 px.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

---

## 13. Was dieser Lauf ausdrücklich **nicht** prüft

- **Cron.** Das ist Schritt 9 und hat mit diesem Schritt keine Berührung.
- **Den Angriffsdurchgang** aus `docs/51 §4`. Er ist Schritt 11, läuft scharf
  *und* stumpf und braucht seine eigene Vorbereitung.
- **Die drei anderen Zielplattformen.** `Match all` gibt es seit OpenSSH 6.5,
  `restrict` seit 7.2 — aber das ist Wissen aus zweiter Hand, und eine Messung
  in der CI wäre eine Zusage.
- **Einen Schlüssel von einem Hardware-Token** (`sk-…`). Der Parser weist ihn
  ausdrücklich ab, weil er nie gemessen wurde.

---

## 14. Der Abbruch

Der Lauf hält an, sobald einer der folgenden Punkte eintritt — und zwar mit dem
Rückweg aus 0c (`cp /root/sshd_config.vor-p6 /etc/ssh/sshd_config`, `sshd -t`,
`systemctl reload ssh`):

- Punkt 1 meldet eine Abweichung an der echten Kette.
- Punkt 8 fasst die Datei **doch** an.
- Eine Anmeldung, die vorher ging, geht nach einem Panel-Vorgang nicht mehr.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**
