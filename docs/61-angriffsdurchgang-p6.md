# 61 — Der Angriffsdurchgang (P6, Schritt 11)

Das **Abnahmekriterium von P6**, wörtlich in `docs/51 §4`: zwölf Angriffe und
drei Belege, gefahren auf `cloudsrv24` gegen ein echtes Abonnement, und zwar
**zweimal** — einmal gegen das gebaute Panel, einmal gegen eines, dem die
Schranke absichtlich genommen wurde.

> **Ein Angriff, der nicht trifft, misst den Angreifer und nicht die Abwehr.**
> `docs/50 §3` hat das zweimal am eigenen Leib gemessen: Zwei Angreifer meldeten
> null Treffer, und beide waren zu ungeschickt.

Er kommt **vor** der Freigabe und nicht danach. Das Protokoll entsteht
**während** des Laufs und bekommt seine Nummer, wenn es angelegt wird — ein
Verweis auf ein Dokument, das es noch nicht gibt, ist ein toter Verweis, und
`DocLinkTest` besteht darauf.

---

## 0. Vier Vorarbeiten, ohne die dieser Lauf nicht fahrbar ist

Sie sind beim Ausschreiben dieses Dokuments gefunden worden, am Quelltext und
nicht am Plan. Jede einzelne würde den Lauf entweder anhalten oder — schlimmer —
ein falsches Grün erzeugen.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

### 0a. Punkt 13 und 14 sind von aussen gar nicht messbar

`docs/51 §4` verlangt: „Jeder Datei-Vorgang meldet die `uid`, unter der er lief"
und „meldet seine Zusatzgruppen". Das Kind der Sandbox **erhebt** beides und
schickt es durch das Socketpaar zurück; `Sandbox::parent()` prüft es auch —

```php
if (($decoded['uid'] ?? 0) === 0 || in_array(0, $decoded['groups'] ?? [0], true)) {
    throw AgentException::execFailed('Die Sandbox meldet Rechte, die sie nicht haben darf.');
}

return $decoded['value'] ?? null;
```

— und wirft die Zahlen dann weg. Weder die Operation noch das Protokoll noch das
Panel sehen sie je. **Was da ist, ist eine Prüfung, kein Beleg.**

> **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
> keine.** (`docs/59`)

Der Unterschied ist nicht formal: Eine Prüfung, die im Agenten sitzt, kann nur
zeigen, dass sie **nicht angeschlagen hat**. Der Lauf soll aber die Zahl sehen,
und zwar je Vorgang, weil Punkt 15 gerade davon lebt, dass neben den Nullen
etwas anderes als Null steht.

**Zu tun, vor dem Lauf:** Die beiden Werte gehören in das Ergebnis jeder
`files.*`-Operation — nicht als Zierde, sondern weil ein Kriterium sie verlangt.
Der Ort ist `Sandbox::parent()` (die Stelle, die sie heute verwirft) und
`Files\Workspace::run()`, das sie durchreicht.

### 0b. Punkt 1 und 2 werden nicht „abgewiesen", sondern normalisiert

`docs/51 §4` schreibt für `..` und für den absoluten Pfad „abgewiesen". Der Bau
tut etwas anderes, und zwar mit Absicht: `Files\Workspace::path()` zerlegt den
Pfad, wirft `.` weg, **popt** bei `..` das vorige Glied und setzt aus dem Rest
einen Pfad ab `/` zusammen. Aus `../../etc/passwd` wird `/etc/passwd`, und das
bezeichnet **im Chroot** die Datei des Abonnements — nicht die des Systems.

Erwartet ist deshalb **kein Fehler**, sondern: der Vorgang gelingt, und er
trifft etwas Harmloses innerhalb der Wurzel. Ein Lauf, der hier auf eine
Fehlermeldung wartet, meldet einen Fehlbefund über eine Abwehr, die tut, was sie
soll.

> **Ein Abnahmeschritt, der einen Wortlaut prüft statt eines Zustands, misst die
> Formulierung.**

Die Punkte 1 und 2 stehen unten deshalb mit dem gemessenen Verhalten da und
nicht mit dem des Plans.

### 0c. Der Angreifer zu Punkt 6 hängt an FFI — und ohne FFI ist er wertlos

`tests/sandbox-messen.php` tauscht Verzeichnis und Verweis atomar über
`renameat2(…, RENAME_EXCHANGE)`, erreicht per `FFI` und `syscall(316, …)`. Fehlt
`FFI`, fällt er auf `unlink`/`symlink` zurück — und genau dieser schwache
Angreifer traf in `docs/50 §3` in **20 000 Runden null Mal**. Er lässt den Namen
zwischendurch verschwinden; die Prüfung weist dann ab, und gemessen wird seine
Ungeschicktheit.

Das Skript sagt selbst, womit es arbeitet:

```
  Tauschverfahren: renameat2(RENAME_EXCHANGE), atomar
```

**Steht dort etwas anderes, hält der Lauf an.** Die Null aus Punkt 6 ist sonst
keine Messung.

### 0d. Eine Rechteangabe im Quelltext war der Wert des Plans, nicht der der Platte

`CronFile::COMMAND_DIR` trug den Kommentar „je Job eine Datei, `root:root 0640`"
— das ist die Vorgabe aus `docs/51 §10`. Gebaut ist `root:<gruppe des abos>
0640`, und zwar aus einem gemessenen Grund: `cron-run` läuft als der Kunde und
kommt an eine Datei `root:root 0640` nicht heran. Der Kommentar ist am
18. August berichtigt worden, **bevor** dieser Lauf ihn abliest.

> **Ein Kommentar, der eine Rechteangabe nennt, ist eine Behauptung über die
> Platte und keine über die Absicht.**

---

## 1. Die zwei Wände — und warum die stumpfe Fassung sie einzeln nehmen muss

**Das ist der Kern dieses Laufs**, und ohne ihn belegt er weniger, als er
behauptet.

Zwischen einem Pfad aus dem Formular und einer Datei stehen **zwei** Wände, und
sie sind verschiedener Natur:

| | Wand | Wo | Art |
|---|---|---|---|
| A | `Workspace::path()` normalisiert | im Agenten, vor dem `fork` | eine **Prüfung**, in PHP geschrieben |
| B | `chroot` + `setuid` | im Kind der Sandbox | eine **Schranke**, vom Kernel gehalten |

Wird die stumpfe Fassung so gebaut, dass sie **an beiden vorbei** greift — also
schlicht `file_get_contents($pfad)` als root —, dann beantwortet der Lauf die
Frage „hätte der Angriff getroffen?" mit Ja und die Frage „**welche** Wand hat
ihn gehalten?" gar nicht. Genau diese Bauart hat `tests/sandbox-messen.php` in
§4: Sie ist als Gegenprobe **für die Sandbox** richtig und als Gegenprobe für
den Angriffsdurchgang zu grob.

> **Eine Gegenprobe, die zwei Wände zugleich wegnimmt, sagt über keine von
> beiden etwas.**

Deshalb wird die stumpfe Fassung in **zwei** Spielarten gebaut, und jeder Punkt
unten nennt, welche für ihn gilt:

- **stumpf-A** — `Workspace::path()` gibt den Pfad unverändert zurück, die
  Sandbox bleibt. Erwartet: der Angriff kommt weiter, bleibt aber **innerhalb**
  der Wurzel. Das ist der Beleg, dass A allein nichts hält und B trägt.
- **stumpf-B** — `Workspace::run()` ruft die Arbeit **ohne** `Sandbox::run()`
  auf, also als root und ohne Chroot; A bleibt. Erwartet: der Angriff kommt
  durch, wenn A ihn nicht schon unschädlich gemacht hat.

Erst beide zusammen sagen, welche Wand welchen Punkt hält. Und sie sagen
zusätzlich etwas, das keine der beiden allein sagen kann: ob eine der Wände
**überflüssig** ist.

---

## 2. Wie die stumpfe Fassung gebaut wird — und wie sie nie ausgeliefert wird

**Kein Schalter.** Eine Umgebungsvariable oder ein Eintrag in `panel.env`, der
die Schranke abschaltet, wäre ein dauerhaftes Loch im ausgelieferten Code, und
der Lauf hätte es selbst hineingebaut. Die stumpfe Fassung ist ein **Bau**, kein
Zustand.

**Die drei Eingriffe stehen hier als Beschreibung und nicht als Patchdatei.**
Ein Patch gegen eine Fassung, die es noch nicht gibt, veraltet zwischen dem
Schreiben dieses Dokuments und dem Lauf — und ein Patch, der nicht mehr
anwendbar ist, wird von Hand nachgebessert, und dann weiss niemand mehr, was
wirklich entfernt wurde. Jeder Eingriff ist **eine** Änderung an **einer**
Stelle:

| Bau | Eingriff | Datei |
|---|---|---|
| stumpf-A | `path()` gibt `Guard::string($value, $field)` unverändert zurück — Zerlegung und Normalisierung entfallen | `agent/src/Files/Workspace.php` |
| stumpf-B | `run()` ruft `$work()` direkt auf statt `Sandbox::run(…)` | `agent/src/Files/Workspace.php` |
| stumpf-C | `render()` setzt den Befehl in die Zeile statt `RUNNER` mit der Kennung | `agent/src/Cron/CronFile.php` |

```bash
# Auf dem Bau-Rechner, nicht auf cloudsrv24.
git switch --detach v0.6.0-rc.NN          # die Fassung, die scharf geprüft wird
$EDITOR agent/src/Files/Workspace.php     # der eine Eingriff aus der Tabelle
git diff > /tmp/stumpf-a.patch            # in das Protokoll, wörtlich
# Bauen wie sonst, aber mit einer Fassung, die man nicht verwechseln kann:
#   Version: 0.6.0-rc.NN+stumpf-a
```

**Der Diff gehört wörtlich ins Protokoll.** Ein Lauf, der „die Schranke war
entfernt" behauptet, ohne zu zeigen was entfernt wurde, ist eine Behauptung über
etwas, das es nach dem Lauf nicht mehr gibt.

Drei Auflagen, jede aus einem eigenen Grund:

1. **Die Fassungsnummer trägt `+stumpf-a` bzw. `+stumpf-b`.** `srvpanel version`
   sagt sie, und jeder Punkt unten wird mit der abgelesenen Nummer protokolliert.
   Ohne das ist am Ende nicht mehr zu unterscheiden, welcher Wert zu welchem Bau
   gehört — `docs/48` hat genau daran einen Vergleich verloren.
2. **Kein Tag, kein Push, keine Freigabe.** Gearbeitet wird auf einem losen
   `HEAD` und nicht auf einem Zweig: Ein Zweig mit dieser Änderung ist einer,
   den ein Release-Lauf finden kann.
3. **Nach dem Lauf wird die scharfe Fassung wieder installiert und das
   Wegwerf-Abonnement zurückgebaut**, und beides wird belegt statt angenommen
   (§9).

**Und der Server, auf dem das läuft, ist ein Wegwerf-Server oder `cloudsrv24`
mit einem Wegwerf-Abonnement — niemals eines mit Kundendaten.** Die stumpfe
Fassung ist per Bauart ein Panel ohne Mandantengrenze.

---

## 3. Vorbereitung auf `cloudsrv24`

| | |
|---|---|
| 3a | `srvpanel version` abgelesen und notiert. |
| 3b | Ein **Wegwerf-Abonnement** angelegt, seine Kennung und sein Systembenutzer notiert. |
| 3c | Ein **zweites** Wegwerf-Abonnement für Punkt 11 — der Übergriff braucht ein fremdes Ziel, das kein Kunde ist. |
| 3d | `php -r 'var_dump(class_exists("FFI"));'` → `true`. Sonst siehe 0c. |
| 3e | `sudo php tests/sandbox-messen.php` einmal gefahren, Zeile „Tauschverfahren" gelesen. |

`tests/cron-messen.sh` gehört **davor** und nicht hierher: Welcher Cron-Dienst
auf dieser Maschine aktiv ist, ist der offene Punkt aus Schritt 9, und die
Punkte 9 und 10 unten stehen darauf.

---

## 4. Die Pfad-Angriffe (Punkte 1 bis 5)

Alle fünf laufen **durch die echte Route** und nicht gegen den Agenten direkt.
Das ist der Unterschied zwischen diesem Lauf und `tests/sandbox-messen.php`:

> **Eine Wand, die man nur erreicht, indem man die davor abschaltet, wird durch
> das Abschalten nicht erreicht — sie wird umgangen.** (`docs/47 §15`)

Angemeldet als der Kunde des Wegwerf-Abonnements, `<ABO>` ist seine Kennung.

| # | Angriff | scharf | stumpf-A | stumpf-B |
|---|---|---|---|---|
| 1 | `path=../../../../etc/passwd` an `GET /subscriptions/<ABO>/files/edit` | gelingt, liest `/etc/passwd` **der Wurzel** (also nichts Fremdes) — kein Fehler, siehe 0b | gelingt, liest dasselbe: A hält hier nichts | liest `/etc/passwd` **des Servers** — `root:x:0:0` |
| 2 | `path=/etc/passwd`, dieselbe Route | wie 1 | wie 1 | wie 1 |
| 3 | Als Kunde per SFTP `ln -s /etc/passwd ~/httpdocs/raus`, dann `path=/httpdocs/raus` lesen | leer oder Fehler | leer oder Fehler | `root:x:0:0` |
| 4 | `ln -s /etc ~/httpdocs/raus-dir`, dann `GET …/files?path=/httpdocs/raus-dir` | leer oder Fehler | leer oder Fehler | Verzeichnis von `/etc` |
| 5 | `ln -s /etc/shadow ~/httpdocs/raus-shadow`, dann `PUT …/files` mit Inhalt | abgewiesen, `/etc/shadow` unverändert (`sha256sum` davor und danach) | abgewiesen, unverändert | Datei verändert |

**Punkt 1 und 2 sind die interessanten.** Ihre drei Spalten sind nicht dreimal
dasselbe: Dass scharf und stumpf-A gleich ausgehen, ist der Beleg dafür, dass
die Normalisierung in `Workspace::path()` **keine Schranke ist** — sie ist eine
Bequemlichkeit, und die Sicherheit kommt aus B. Wer das nicht misst, hält A für
die Abwehr und baut beim nächsten Merkmal darauf.

---

## 5. Das Rennen (Punkt 6)

Der Angreifer aus `docs/50 §3`: vier Prozesse des Abonnements, die
`renameat2(…, RENAME_EXCHANGE)` in einer Schleife aufrufen, gegen einen
laufenden Baumlauf.

```bash
sudo php tests/sandbox-messen.php          # Abschnitt 5 und 6
```

| erwartet | scharf | stumpf-B |
|---|---|---|
| Treffer **ausserhalb** der Wurzel | **0** | > 0 |
| Tauschverfahren | `renameat2(RENAME_EXCHANGE), atomar` | dasselbe |

**Die Null gilt nur zusammen mit der Zeile darüber.** Ein Angreifer ohne FFI
liefert dieselbe Null und hat nichts gemessen (0c).

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Und der Baumlauf des Rückbaus gehört dazu** (`docs/51 §5.1 d`):
`Filesystem::removeTree` läuft als root; die Frage ist, ob er beim Zurückbauen
eines Abonnements etwas ausserhalb trifft. Abschnitt 6 des Skripts misst das.

---

## 6. Die Archive (Punkte 7 und 8)

Gebaut werden zwei bösartige Archive, **ausserhalb** des Panels:

```bash
mkdir -p /tmp/boes && cd /tmp/boes
echo 'getroffen' > nutzlast
# 7: relativer Ausbruch
tar --transform 's|nutzlast|../../../../tmp/getroffen-relativ|' -cf raus-relativ.tar nutzlast
# 8: absoluter Pfad
tar -P -cf raus-absolut.tar --transform 's|nutzlast|/tmp/getroffen-absolut|' nutzlast
```

Beide hochladen und über `POST /subscriptions/<ABO>/files/extract` entpacken.

| # | erwartet scharf | erwartet stumpf-B |
|---|---|---|
| 7 | nichts ausserhalb der Wurzel; `/tmp/getroffen-relativ` gibt es nicht | Datei liegt da |
| 8 | nichts ausserhalb der Wurzel; `/tmp/getroffen-absolut` gibt es nicht | Datei liegt da |

**Die Gegenprobe je Punkt ist der Nachweis, dass das Archiv überhaupt etwas
enthält**, das ankommen kann: `tar -tvf` davor, und nach dem scharfen Lauf muss
die Nutzlast **innerhalb** der Wurzel liegen. Ein Archiv, dessen Eintrag gar
nicht entpackt wird, erzeugt dieselbe Abwesenheit wie eine gehaltene Grenze.

---

## 7. Cron (Punkte 9 und 10)

Beide zielen auf `/etc/cron.d`, wo eine Zeile ein **Benutzerfeld** hat.

| # | Angriff | erwartet scharf |
|---|---|---|
| 9 | Als Befehl eines Jobs: `echo eins\n* * * * * root touch /tmp/uebernommen` | Die Cron-Datei enthält **eine** Zeile für dieses Abo, ihr Benutzerfeld ist der Abo-Benutzer; `/tmp/uebernommen` entsteht nie |
| 10 | Als Befehl: `date +%%Y > /tmp/prozent.txt` — und derselbe ohne Maskierung | Der Befehl läuft **vollständig**; `/tmp/prozent.txt` enthält die Jahreszahl |

Abgelesen wird nicht die Oberfläche, sondern die Platte:

```bash
sudo cat /etc/cron.d/srvpanel-<BENUTZER>
sudo ls -l /etc/srvpanel/cron/srvpanel-<BENUTZER>-*.cmd
sudo cat  /etc/srvpanel/cron/srvpanel-<BENUTZER>-<ID>.cmd
```

Erwartet in der Cron-Datei: `MAILTO=""`, ein `PATH`, und je Job **genau eine**
Zeile der Form

```
<fünf felder>  <BENUTZER>  /usr/lib/srvpanel/cron-run <ID>
```

— **kein einziges Zeichen aus dem Befehl des Kunden.** Die Befehlsdatei trägt
`root:<gruppe des abos> 0640` (siehe 0d), das Verzeichnis `root:root 0751`.

**Punkt 10 wird gemessen und nicht angenommen.** Dass kein Kundentext in der
Datei landet, macht das `%`-Verhalten von crontab gegenstandslos — aber genau
diese Art Schluss hat `docs/44` teuer gemacht.

> **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit Fussnote.**

Die **stumpfe** Fassung für diese beiden ist eine dritte und eigene: `CronFile`
schreibt den Befehl **in die Zeile** statt in die `.cmd`-Datei (`stumpf-C`).
Erwartet dann: Punkt 9 legt eine zweite Zeile mit `root` an, Punkt 10 schneidet
den Befehl am `%` ab.

---

## 8. Mandant und Quota (Punkte 11 und 12)

**Punkt 11 — der Übergriff.** Angemeldet als Kunde von Abonnement 1, in **jeder**
der 22 Routen mit `{subscription}` die Kennung von Abonnement 2 einsetzen:

```
/subscriptions/<FREMD>/files            …/files/tree   …/files/edit
…/files (PUT, DELETE)                   …/files/directory
…/files/rename  …/files/move  …/files/copy
…/files/search  …/files/extract  …/files/compress  …/files/upload  …/files/chmod
/subscriptions/<FREMD>/sftp             …/sftp/keys (POST, DELETE)
/subscriptions/<FREMD>/cron             …/cron (POST)   …/cron/<JOB> (PUT, DELETE)
…/cron/<JOB>/runs
```

Erwartet scharf: **403 in allen 22**, und zwar aus der Policy und nicht aus einem
404, das zufällig dasselbe verbirgt. Erwartet stumpf-B: unverändert 403 — die
Mandantenklammer ist **nicht** eine der beiden Wände aus §1, und dass sie in
beiden Bauten hält, ist der Beleg dafür, dass hier nichts vermischt wurde.

Die eigene Kennung in derselben Route muss **gelingen**; sonst misst die Reihe
403er, die es auch ohne Klammer gäbe.

**Punkt 12 — die volle Quota.** Das Kontingent des Wegwerf-Abonnements klein
setzen, dann eine Datei schreiben, die nicht mehr hineinpasst.

| erwartet scharf | erwartet stumpf-B |
|---|---|
| Der Vorgang schlägt fehl, und die Seite sagt es | Erfolgsmeldung, obwohl nichts geschrieben wurde |

**Der Fehlerweg wird dabei selbst angesehen** und nicht nur sein Vorhandensein:
Die Begründung muss vollständig ankommen, in der Oberfläche lesbar sein und die
Seite bei 390 px nicht schieben. `docs/48` hat an genau dieser Stelle drei
Fehler gehabt, die aneinanderhingen.

> **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**

---

## 9. Die drei Belege (Punkte 13 bis 15)

Sie sind keine Angriffe. Sie fangen den Fall ab, dass alles abgewiesen wird,
weil gar nichts lief.

| # | Beleg | erwartet |
|---|---|---|
| 13 | Jeder Datei-Vorgang meldet die `uid`, unter der er lief | die des Abos, nie 0 |
| 14 | Jeder Datei-Vorgang meldet seine Zusatzgruppen | nur die des Abos, nie 0 |
| 15 | Ein **gültiger** Vorgang derselben Art gelingt | Datei gelesen und geschrieben, Inhalt stimmt |

13 und 14 sind heute nicht ablesbar — siehe 0a. **Ohne die Vorarbeit dort ist
dieser Abschnitt nicht fahrbar**, und der Lauf darf ihn nicht als erfüllt
abhaken.

Punkt 15 ist der Nachbar der Nullen darüber und wird **nach jedem** Abschnitt
wiederholt, nicht einmal am Ende: Eine Grenze, die alles abweist, weil der Agent
seit Punkt 4 gar nicht mehr antwortet, sieht bis dahin aus wie eine, die hält.

---

## 10. Der Rückbau, und die Reste, die keine sein dürfen

Nach dem Lauf, in dieser Reihenfolge:

1. Die **scharfe** Fassung wieder installiert, `srvpanel version` abgelesen.
2. Beide Wegwerf-Abonnements zurückgebaut.
3. Belegt statt angenommen: kein Systembenutzer, kein Verzeichnis unter
   `/var/www/vhosts`, kein Quota-Eintrag, **keine Datei unter `/etc/cron.d`,
   `/etc/srvpanel/cron` und `/var/spool/srvpanel/cron`**.
4. Die Dateien, die die Angriffe ausserhalb erzeugt haben sollten, sind fort:
   `/tmp/getroffen-*`, `/tmp/uebernommen`, `/tmp/prozent.txt`, `/tmp/boes`.
5. `sha256sum /etc/shadow` gegen den Wert von vor Punkt 5.

---

## 11. Was dieser Lauf ausdrücklich **nicht** prüft

- **Keine Lastmessung.** Wie lange ein Baumlauf über hunderttausend Dateien
  braucht, ist eine andere Frage.
- **Kein Angriff über den Browser selbst** (XSS, CSRF) — die Grenze dieses
  Schrittes ist das Dateisystem, nicht die Sitzung.
- **Kein Angriff mit einem Konto des Betreibers.** Ein Admin darf, was hier
  abgewiesen wird; das ist die Funktion und nicht die Lücke.
- **Nicht, ob `Workspace::path()` überflüssig ist.** Der Lauf misst, dass sie
  keine Schranke ist (§4). Ob sie deshalb weg soll, ist eine Entscheidung und
  keine Messung — sie fängt Unfug ab, bevor ein Prozess entsteht.

---

## 12. Der Abbruch

Der Lauf hält an, wenn:

- die Zeile „Tauschverfahren" nicht `renameat2` sagt (0c),
- Punkt 15 an irgendeiner Stelle **nicht** gelingt,
- ein Punkt in der stumpfen Fassung **nicht** durchkommt — dann ist der Angriff
  zu ungeschickt und die Null darüber wertlos,
- oder `srvpanel version` eine Fassung nennt, die nicht die des Abschnitts ist.

Was dabei gefunden wird, steht **im Protokoll und nicht im Gedächtnis** — und
das Protokoll entsteht während des Laufs.
