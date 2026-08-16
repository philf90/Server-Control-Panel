# 57 — Die Messrunde vor Schritt 8 (SFTP)

Gefahren am 16. August 2026 im Entwicklungscontainer, gegen **OpenSSH_9.6p1
Ubuntu-3ubuntu13.18** — dieselbe Fassungsreihe wie in `docs/50 §6`. Echter
`sshd` auf einem eigenen Port, echter `sftp`-Klient, echte Systembenutzer mit
dem Zuschnitt aus `SubscriptionProvision`.

Das Gestell steht als **`tests/sftp-messen.sh`** im Repo und fährt alle
Messungen dieses Dokuments in einem Lauf: **42 wie erwartet, 0 abweichend.**

> **Was man zweimal braucht, gehört ins Repo — auch wenn es keine Zeile
> Anwendungscode ist.**

---

## 1. Warum es diese Runde gab

`docs/50 §6` hat die **Kette** gemessen: Besitz und Rechte oberhalb der
Chroot-Wurzel, jedes Glied einzeln. Es hat die **Konfigurationssemantik** nicht
gemessen — und daran hängt Schritt 8 vollständig. Alles, was der Plan über
`sshd_config` sagte, stand aus zweiter Hand da.

> **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit Fussnote.**

`docs/44` ist, was passiert, wenn man so eine Vermutung als Anweisung führt: Ein
Abnahmelauf schrieb `--bind=::`, und danach gab jede Seite einen 500er.

**Und der Container hatte den `sshd` nicht** — er war nur nicht installiert.
`openssh-server` steht im Ubuntu-Archiv, `apt-get install` holt ihn, der Proxy
sperrt ihn nicht. Derselbe Satz wie bei MariaDB im August:

> **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
> braucht einen Versuch.**

---

## 2. Der Befund in einem Absatz

Der Entwurf aus dem Plan trägt: Ein Benutzer ohne Passwort, mit `nologin` als
Shell und einer Schlüsseldatei ausserhalb seines Chroots kommt über
`internal-sftp` herein, und sein eigenes `.ssh/authorized_keys` wird dabei nicht
gelesen. **Drei Dinge daran waren anders als angenommen, und jedes einzelne
hätte den Schritt gekostet:** Ein Neuladen mit einer kaputten Datei **tötet den
sshd** (bei PostgreSQL ist es folgenlos); ein `Match`-Block hat kein Ende,
sondern nur einen Nachfolger, und verschluckt alles Nachfolgende, ohne dass
`sshd -t` etwas merkt; und die Schlüsseldatei hat eine **zweite** Kette, die
früher und mit anderem Wortlaut scheitert als die des Chroots.

---

## 3. Der Zugang selbst (M6, M9, M10)

| Frage | Ergebnis |
|---|---|
| Systembenutzer mit `!` im Passwortfeld, `UsePAM yes`, Schlüsselanmeldung | **kommt herein** |
| `--shell /usr/sbin/nologin` + `ForceCommand internal-sftp` | **kommt herein** |
| `AuthorizedKeysFile` absolut, ausserhalb des Chroots, `root:root 0644` | **wird gelesen** |

Damit steht „**kein Passwort**" (§9) nicht auf einer Annahme, und
`SubscriptionProvision` muss für SFTP an keiner Stelle geändert werden: Der
Benutzer, den es seit P2 anlegt, ist genau der richtige.

Das war die Messung mit dem grössten Risiko — `UsePAM yes` und ein gesperrtes
Passwortfeld weisen in mancher PAM-Aufstellung auch die Schlüsselanmeldung ab.
Hier nicht.

---

## 4. M7 — das eigene `authorized_keys` des Kunden

| | |
|---|---|
| fremder Schlüssel in `<abo>/.ssh/authorized_keys` (`p9901:p9901 0600`) | **abgewiesen** |
| **Gegenprobe:** derselbe Schlüssel in `/etc/srvpanel/ssh/<benutzer>` | **angenommen** |

Eine `AuthorizedKeysFile`-Angabe im `Match`-Block **ersetzt** die Vorgabe, sie
ergänzt sie nicht. Damit ist „`authorized_keys` schreibt der Agent, nie der
Kunde" durchsetzbar und nicht nur behauptet — und die Fingerabdrücke im Panel
sind die Wahrheit und keine Teilmenge davon.

Die Gegenprobe steht daneben, weil die Ablehnung sonst auch am Schlüssel liegen
könnte:

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Was daraus für die Oberfläche folgt:** `.ssh/` bleibt im Schema stehen
(`docs/20 §4.5`, unverändert), wird aber nicht mehr gelesen. Das Panel sagt das
hin. Wer es nicht hinschreibt, lässt jemanden einen Nachmittag lang suchen,
warum der Schlüssel, den er selbst hingelegt hat, nicht wirkt.

---

## 5. Befund 1 — Ein Neuladen tötet den sshd

**Die teuerste Messung der Runde**, und sie kehrt das Vorbild aus P5b um.

| Zustand | PostgreSQL (`docs/38`, M16/M17) | OpenSSH (hier gemessen) |
|---|---|---|
| kaputte Datei + **Reload** | Server bedient weiter, behält die alten Regeln | **Prozess terminiert — niemand horcht mehr** |
| kaputte Datei + **Neustart** | Cluster kommt nicht hoch | Dienst kommt nicht hoch |

Der Protokollauszug:

```
Received SIGHUP; restarting.
sshd_config: line 19: Bad configuration option: Klabautermann
sshd_config: terminating, 1 bad configuration options
```

Danach: PID-Datei leer, `ss -ltn` ohne Eintrag, Anmeldung `Connection refused`.
Die Gegenprobe mit einer heilen Datei hält den Dienst am Leben und zieht den
neuen Block, also misst die Messung etwas.

> **Ein Neuladen ist bei PostgreSQL folgenlos und bei sshd tödlich — und dieselbe
> Vorsicht hätte beide Male anders ausgesehen.**

**Der Ablauf aus `docs/38 §14.2` überträgt sich damit nicht.** Dort heisst er:
schreiben, neu laden, nachsehen, **bei einem Fehler zurückrollen**. Hier ist
nach dem gescheiterten Neuladen kein Server mehr da, in den man zurückrollen
könnte — der Rückweg wäre ein Griff in eine Tür, die er selbst zugezogen hat.

> **Ein Rückweg, der voraussetzt, dass der Dienst noch läuft, ist keiner für den
> Fall, dass ihn genau dieser Vorgang beendet hat.**

Also: **erst prüfen, dann schreiben.** Die Prüfung ist hier kein Zusatz zum
Rückweg, sie ist sein Ersatz. Der Ablauf für `sftp.access`:

1. Den vollen Text erzeugen (`ManagedBlock::render()`), unter der Sperre.
2. In eine **Nachbardatei** schreiben — nie über die echte.
3. `sshd -t -f <nachbardatei>`. Rot ⇒ Abbruch, die echte Datei ist unberührt.
4. Erst jetzt `rename` (atomar, derselbe Griff wie `Hba::put()`).
5. `systemctl reload ssh`.
6. Nachsehen mit `sshd -T -C user=…` je Abonnement (§7).
7. Weicht etwas ab: den alten Text zurücklegen, **wieder prüfen**, neu laden.

---

## 6. Befund 2 — Ein `Match`-Block hat kein Ende, nur einen Nachfolger

```
Match User p9901
    ChrootDirectory /var/www/vhosts/p9901.invalid
ClientAliveInterval 77          ← sieht global aus
```

| effektiv für | `clientaliveinterval` |
|---|---|
| `p9901` | **77** |
| jeden anderen | 0 |

Die Zeile *hinter* dem Block gehört ihm, obwohl sie nicht eingerückt ist und
obwohl der Block „zu Ende" aussieht. **`sshd -t` sagt dazu `rc=0`** — es ist ja
nichts falsch.

> **Ein Fehler, den der Prüfer für richtig hält, ist teurer als einer, den er
> meldet.**

Das trifft dieses Panel doppelt, denn unser verwalteter Block trägt eine
Endmarke:

> **`# END srvpanel` ist ein Kommentar für Menschen und kein Ende für sshd.**

Steht der Block am Dateiende und der Betreiber hängt später eine Zeile an, fällt
sie in **unseren** letzten `Match`-Block — eine Änderung, die er an *seiner*
Datei macht und die still nur noch für einen Kunden gilt. Das ist genau der
Schaden, den der verwaltete Block verhindern soll, mit umgekehrtem Vorzeichen.

**Die Abhilfe ist gemessen:** ein abschliessendes `Match all`. Danach gilt die
Zeile wieder für alle (77 für jeden Benutzer). Der Block endet ab jetzt so — für
sshd und nicht nur für den Leser.

---

## 7. Befund 3 — Der erste passende Block gewinnt, und darum steht unserer unten

Zwei passende `Match`-Blöcke, derselbe Schlüssel: Es gilt der **erste**
(`/erster`). Gegengeprüft an einer echten Anmeldung und nicht nur an `sshd -T`:
Ein Block, der auf das Verzeichnis eines *anderen* Abonnements zeigte, brachte
den Klienten auch dorthin.

Daraus zwei Dinge:

**1. Der Block gehört ans Dateiende, und das ist keine Ordnungsfrage.** Von dort
gewinnt ein Eintrag des Betreibers über unseren — „der Bestand ist Gesetz"
(Leitbild 1) gilt dann wörtlich statt dem Sinne nach.

**2. Ein Drop-in wäre das Gegenteil, und der Grund ist nicht der, den ich
erwartet hatte.** Gemessen:

| | |
|---|---|
| Leckt ein `Match` aus einer Drop-in-Datei in die Hauptdatei? | **nein** — der Kontext endet mit der Datei |
| Schlägt ein Drop-in den Block des Betreibers weiter unten? | **ja** — weil `Include` auf 24.04 in Zeile 12 steht |

Ich hatte gegen das Drop-in argumentiert, weil ein `Match` daraus den Rest der
Hauptdatei vergiften würde. Das tut es nicht. Ausgeschlossen ist es aus dem
anderen Grund: Was oben eingebunden wird, **gewinnt** — und damit stünde srvpanel
über dem, was der Betreiber selbst eingetragen hat.

> **Eine richtige Entscheidung mit der falschen Begründung ist beim nächsten Mal
> eine falsche.**

**Und die Folge für die Prüfung:** Was gilt, sagt nicht unser Block, sondern
`sshd -T -C user=…` — dieselbe Quelle, aus der sshd es nimmt.

> **Ein Block, den man geschrieben hat, ist keine Auskunft darüber, was gilt.**

---

## 8. Befund 4 — Was `sshd -t` sieht, und was nicht

| Fall | `sshd -t` |
|---|---|
| unbekanntes Schlüsselwort | **255** |
| unbekannte `Match`-Bedingung (`Match Nutzer …`) | **255** |
| `Match` ohne Argument | **255** |
| `Include` auf eine kaputte Datei | **255** (es zieht sie mit) |
| `ChrootDirectory` auf ein Verzeichnis, das es nicht gibt | 0 |
| `ChrootDirectory` mit falschen Rechten | 0 |
| `AuthorizedKeysFile` auf eine Datei, die es nicht gibt | 0 |
| eine per Zeilenumbruch untergeschobene Zeile | 0 |

`sshd -t -f <datei>` prüft die **genannte** Datei (belegt an einer heilen und
einer kaputten Kopie) und zieht deren `Include` mit — eine Nachbardatei vor dem
`rename` zu prüfen ist damit eine echte Prüfung und keine Geste.

**Die untere Hälfte dieser Tabelle ist das Pflichtenheft der Kettenprüfung.**
Was der Prüfer des Dienstes nicht sieht, muss das Panel sehen — sonst sieht es
niemand, bis ein Kunde anruft.

---

## 9. Befund 5 — Die Schlüsseldatei hat eine zweite Kette

Sie scheitert **früher** (bei der Anmeldung, nicht beim Chroot) und mit anderem
Wortlaut. Der Klient erfährt in beiden Fällen dasselbe: nichts.

| Zustand | Serverprotokoll |
|---|---|
| Wurzel gehört dem Benutzer / `0775` / `0757` | `bad ownership or modes for chroot directory "/var/www/vhosts/…"` |
| ein Glied **darüber** gehört dem Benutzer oder ist `0777` | `bad ownership or modes for chroot directory component "/var/www/"` |
| Schlüsseldatei gruppenschreibbar | `Authentication refused: bad ownership or modes for file /etc/srvpanel/ssh/p9901` |
| ihr Verzeichnis `0777` | `Authentication refused: bad ownership or modes for directory /etc/srvpanel/ssh` |
| `/` gruppenschreibbar | `Authentication refused: bad ownership or modes for directory /` — **nicht** die Chroot-Meldung |
| Schlüsseldatei fehlt | nur `Failed publickey`, ohne jede Begründung |

`docs/50 §6` kannte davon **eine** Zeile. Zwei Dinge fallen auf:

- Die Meldung für ein Glied oberhalb heisst `component` und trägt einen
  **abschliessenden Schrägstrich**. Wer nach dem ganzen Pfad greppt, findet sie
  nicht.
- Bei einem gruppenschreibbaren `/` meldet der Server gar nichts über das
  Chroot. Die Anmeldung scheitert eine Station früher — an der Kette der
  Schlüsseldatei. **Eine Prüfung, die nur die Chroot-Kette abläuft, meldet in
  diesem Fall „alles in Ordnung", während niemand hereinkommt.**

**Und der Fall ohne Schlüsseldatei sieht für den Kunden aus wie ein Defekt.** Das
ist der Zustand „Zugang aus" (§1.3 des Plans), und das Panel muss ihn benennen,
statt ihn zu einem Rätsel zu machen.

---

## 10. Befund 6 — `StrictModes` erlaubt, was wir verbieten

| Zustand der Schlüsseldatei | OpenSSH |
|---|---|
| `root:root 0644` | angenommen |
| **`p9901:p9901 0644`** (gehört dem Kunden) | **angenommen** |
| **Verzeichnis gehört dem Kunden** | **angenommen** |
| gruppenschreibbar | abgewiesen |

`StrictModes` fragt „gehört sie root **oder** dem Benutzer" — nicht „gehört sie
root". Die Regel „nie der Kunde" ist damit **unsere** und nicht seine, und
niemand ausser uns setzt sie durch.

> **Was der Prüfling durchgehen lässt, ist keine Zusage darüber, wie es sein
> soll.** — dieselbe Form wie `docs/46`: *Was der Geprüfte selbst zurücknehmen
> kann, ist keine Schranke, sondern eine Voreinstellung.*

`sftp.check` prüft deshalb auf `root:root` und nicht auf „was OpenSSH akzeptiert".

---

## 11. Befund 7 — Die Einschleusung, eine Datei weiter

`docs/51 §10.1` beschreibt sie für `/etc/cron.d`. Sie gilt hier wörtlich.
Gemessen mit einem Zeilenumbruch in dem, was ein Abo-Name wäre:

```
Match User p9901
    ChrootDirectory /var/www/vhosts/p9901.invalid
    PermitRootLogin yes          ← untergeschoben
Match all
```

Ergebnis: `permitrootlogin yes` — und in der längeren Fassung ein
`ChrootDirectory /` für einen Benutzer, der im Aufruf gar nicht vorkam.
**`sshd -t`: rc=0.**

Deshalb gilt für `sftp.access` dasselbe wie für die Cron-Datei: Es geht **kein
Zeichen** vom Kunden in diese Datei. Systembenutzer und Abo-Name kommen durch
`SubscriptionProvision::systemUser()` und `::subscriptionName()` — durch dieselben
Prüfungen, die den Pfad bauen, und nicht durch eine zweite Fassung davon.

**Und eine zweite Wand steht schon:**

| Konfiguration | ausgeführt |
|---|---|
| `ForceCommand internal-sftp` im Block, `command="/usr/bin/id"` im Schlüssel | `forced-command (config) 'internal-sftp -u 0027'` |
| **ohne** `ForceCommand` (Gegenprobe) | `forced-command (key-option) '/usr/bin/id'` |

`ForceCommand` schlägt ein untergeschobenes `command=`. Der Schlüsselparser weist
Optionszeilen trotzdem ab — eine zweite Wand ist keine Erlaubnis für ein Loch in
der ersten.

---

## 12. Befund 8 — Der Fingerabdruck braucht kein neues Programm

| Schlüssel | `ssh-keygen -lf` | in PHP gerechnet |
|---|---|---|
| ed25519 | `SHA256:+JT3OUNV2NSqiA6JnI7Y84ebCEMW9t+KcY/hpTJ4ZI4` | identisch |
| rsa 3072 | `SHA256:JuH2QX0gnLqgj0BfsWkKQwJaXMGxagrpapZWjlM58/s` | identisch |

`base64_encode(hash('sha256', base64_decode($blob), true))` ohne Auffüllzeichen.
Der **Typ steht im Schlüsselmaterial selbst** (erstes längenpräfixiertes Feld)
und lässt sich gegen das Wort davor halten — ein Schlüssel, dessen Aufschrift
nicht zu seinem Inhalt passt, fällt damit auf.

`§13` des Plans bleibt eingehalten: **kein neues Programm auf der Positivliste.**

**Und die Falle, die dabei sichtbar wurde:** `hash('sha256', '')` liefert
`SHA256:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU` — einen Fingerabdruck, der
gültig aussieht und über nichts gerechnet ist. Ein Parser, der bei einem
kaputten Blob still weitermacht, zeigt ihn im Panel an.

> **Ein Prüfwert über nichts sieht aus wie ein Prüfwert.**

---

## 13. Befund 9 — systemd nimmt uns die halbe Arbeit ab, und die andere nicht

Aus den Unit-Dateien des Pakets (Ubuntu 24.04):

```
ExecStartPre=/usr/sbin/sshd -t
ExecStart=/usr/sbin/sshd -D $SSHD_OPTS
ExecReload=/usr/sbin/sshd -t
ExecReload=/bin/kill -HUP $MAINPID
Alias=sshd.service
```

`systemctl reload ssh` prüft also **vor** dem HUP. Damit ist der Weg über
systemd deutlich sicherer als ein blankes Signal — aber er prüft nur, was
`sshd -t` sieht (§8), und das ist die kleinere Hälfte. Die Prüfung an der
Nachbardatei bleibt.

`ssh.socket` ist auf 24.04 **eingeschaltet** (`ListenStream=0.0.0.0:22`,
`Accept=no`); `ssh.service` ist ein Alias auf `sshd.service`. Daraus zwei
Punkte, die hier nicht messbar sind, weil kein systemd läuft:

- Was `systemctl reload ssh` tut, wenn der Dienst wegen Socket-Aktivierung
  gerade **nicht läuft** — vermutlich ein Fehler, den die Operation als „nichts
  zu tun" lesen muss und nicht als Fehlschlag.
- Ob auf allen vier Zielplattformen `ssh` oder `sshd` der Unitname ist.

Beides gehört auf `cloudsrv24`, und bis dahin ist es keine Zusage.

---

## 14. Was diese Runde am Plan ändert

1. **`sftp.access` prüft vor dem Schreiben** (§5), an einer Nachbardatei, und
   `rename` erst danach. Der Rückweg aus `PgRemoteAccess` wird nicht kopiert —
   er trägt hier nicht.
2. **Der verwaltete Block endet mit `Match all`** (§6), und `ManagedBlock`
   bekommt dafür eine Stelle, an der ein Block seinen Abschluss mitbringt.
3. **`sftp.check` fragt `sshd -T -C user=…`** und nicht den eigenen Block (§7).
4. **Die Kettenprüfung ist zweiteilig** (§9): die des Chroots und die der
   Schlüsseldatei, mit ihren getrennten Wortlauten — und `/` gehört zu beiden.
5. **Sie prüft auf `root:root` und nicht auf „akzeptiert OpenSSH"** (§10).
6. **Der Zustand „keine Schlüsseldatei" wird benannt** (§9) statt als Defekt
   dazustehen.
7. `SubscriptionProvision` bleibt **unverändert** (§3) — die Entscheidung für
   einen Block je Abonnement braucht keine Sammelgruppe.

## 15. Was offen bleibt

- **§13**: Verhalten von `systemctl reload ssh` bei Socket-Aktivierung und der
  Unitname je Plattform — gehört in den Lauf auf `cloudsrv24`.
- **`docs/50 §8` Punkt 4**: Wem `/var/www/vhosts` auf `cloudsrv24` gehört. Hier
  taugt die ganze Kette (`/`, `/var`, `/var/www`, `/var/www/vhosts` je
  `root 0755`); dort ist es ungemessen, und eine Abweichung kostet den Zugang
  wortlos. `tests/sftp-messen.sh` liest es am Ende jedes Laufs.
- Die drei anderen Zielplattformen. `Match all` gibt es seit OpenSSH 6.5 und
  `restrict` seit 7.2, also überall — aber das ist Wissen aus zweiter Hand, und
  eine Messung in der CI wäre eine Zusage.

  > **Eine Messung, die einmal jemand von Hand macht, ist ein Datum. Eine, die
  > die CI macht, ist eine Zusage.**
