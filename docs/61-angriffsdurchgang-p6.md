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
ein falsches Grün erzeugen. **0a und 0d sind gebaut, 0b und 0c sind Hinweise an
den, der den Lauf fährt.**

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

### 0a. Punkt 13 und 14 waren von aussen gar nicht messbar — **gebaut am 18. August**

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

**Gebaut, und der erste Entwurf war falsch.** Naheliegend war, den Beleg in
`Files\Workspace::run()` an das Ergebnis der Sandbox zu hängen: eine Stelle für
alle dreizehn Datei-Operationen, und damit die Bauform, die dieses Projekt
überall verlangt. Gemessen trägt sie nicht — `files.list` und `files.extract`
bauen aus dem Ergebnis ein **frisches** Feld-Array und geben nur einzelne Werte
daraus weiter. Elf von dreizehn hätten gemeldet, zwei nicht, und keiner hätte es
gesagt.

> **Ein Beleg, den die Zwischenstelle weiterreichen muss, ist bei der ersten
> Zwischenstelle weg, die ihn nicht kennt.**

Der Beleg hängt deshalb nicht am Vorgang, sondern an der **Anfrage**:

| Stelle | Aufgabe |
|---|---|
| `Sandbox::parent()` | erhebt und **prüft** ihn wie bisher, und reicht ihn zusätzlich heraus |
| `Files\Workspace::run()` | nimmt den `Context` entgegen und meldet ihm, unter wem gelaufen wurde |
| `Connection` | hängt ihn an die Antwort **und** schreibt ihn ins Protokoll, nachdem die Operation fertig ist |

Damit kann keine Operation ihn verlieren, auch keine künftige. Im Ergebnis steht
er als `ran_as` — in der Antwort **und** im Protokoll des Agenten. Der Lauf
liest ihn dort, und nicht in der Datenbank:

```bash
# Ein Datei-Vorgang auslösen: im Panel den Dateimanager eines Abonnements
# öffnen. Danach — und **mit** dem Filter auf `files.`, siehe unten:
sudo grep '"op":"files\.' /var/log/srvpanel/agent.log | tail -8

# Und die Gegenprobe daneben: eine Operation, die nicht durch die Sandbox
# geht. Ohne sie steht die Zahl allein da.
sudo grep '"op":"system.info"' /var/log/srvpanel/agent.log | tail -1
```

Erwartet: bei jedem `files.*` ein `"ran_as":{"uid":…,"groups":[…]}` mit der
Kennung des Abonnements — und **nie** eine 0. In der Zeile der Gegenprobe fehlt
das Feld ganz.

**Die Gegenprobe gehört dazu und ist nicht Beifang.** Der Filter auf `files.`
nimmt genau die Zeilen weg, an denen man sieht, dass das Feld nicht überall
steht — und eine Angabe, die überall gleich aussieht, sagt nichts darüber, dass
sie gemessen wurde.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Das Protokoll und nicht die Datenbank — das ist ein Befund vom 18. August,
und es ist der zweite Anlauf.** Hier stand zuerst ein `dump(…)` über
`srvpanel tinker`, dann eine SQL-Abfrage auf `operations.result`. Beide waren
falsch, und jede aus einem eigenen Grund:

1. **`srvpanel tinker` schwieg.** Zwei Ursachen übereinander: `Operation` trägt
   `BelongsToSubscription`, und ohne angemeldetes Konto klammert die
   Mandantenklammer auf `whereRaw('0 = 1')` — und psysh führt seinen Code gar
   nicht aus, wenn es sein Verzeichnis unter `HOME` nicht anlegen darf. Der
   Wrapper setzt seit dem 18. August `HOME=/var/lib/srvpanel`; auf
   `cloudsrv24` gehört dieses Verzeichnis aber `root:root 0755` statt
   `srvpanel:srvpanel 0750`, und damit hilft das dort nicht.
2. **`operations.result` enthält für `files.*` nichts** — und zwar aus einem
   guten Grund: `App\Support\Files\Files` ruft den Agenten unmittelbar auf,
   ohne Vorgang und ohne Zeile in der Datenbank. Eine Verzeichnisliste wartet
   nicht auf einen Arbeiter. Gemessen: Nach dem Öffnen des Dateimanagers stand
   in `operations` **keine neue Zeile**.

Der Beleg entstand also, wurde weitergereicht — und war nirgends. Dieselbe
Lehre wie eine Ebene tiefer, wo die Sandbox ihn erhoben und verworfen hatte:

> **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
> keine.**

Er steht deshalb seit dem 18. August auch im Protokoll des Agenten, je Anfrage
und für jede Operation: dauerhaft, ohne Datenbank, ohne psysh und ohne
Mandantenklammer.

> **Zwischen der Frage und der Antwort gehören so wenige Schichten wie möglich
> — und keine, die bei einem Fehler schweigt.**

#### Gemessen auf `cloudsrv24` am 18. August, gegen `v0.6.0-rc.15`

Vier Datei-Vorgänge aus dem Dateimanager, dazu ein Rückbau und die Gegenprobe:

| Operation | `ran_as` |
|---|---|
| `files.tree`, `files.list` (Abo `p6-b.invalid`, Benutzer `p1136`) | `{"uid":1001,"groups":[1001]}` |
| `subscription.remove` (Benutzer `p1138`) | `{"uid":1002,"groups":[1002]}` |
| `system.info` | **kein Feld** |

**Damit sind Punkt 13 und 14 zum ersten Mal ablesbar.** Sie waren es seit P6
Schritt 1 nicht, weil die Sandbox die Zahlen erhob, prüfte und wieder verwarf.

Drei Dinge machen daraus eine Messung und nicht eine Zahl:

1. **Die Gegenprobe trägt das Feld gar nicht.** Eine Angabe, die überall gleich
   aussieht, sagt nichts darüber, dass sie erhoben wurde.
2. **Zwei Abonnements ergeben zwei Kennungen** — 1001 und 1002. Eine Konstante
   sähe in beiden Zeilen gleich aus.
3. **Der Rückbau meldet mit.** Er ist der Baumlauf, gegen den Punkt 6 antritt;
   ohne diesen Beleg wäre dessen Null später nicht zu deuten.

**Was hier noch offen ist:** dass `1001` die Kennung von `p1136` ist und `1002`
die von `p1138`, sagt `id <benutzer>` und nicht dieses Protokoll. „Nicht null"
ist die eine Hälfte von Punkt 13; „die des Abonnements" ist die andere.

> **Eine Zahl, die nicht null ist, belegt nur, dass sie nicht null ist.**

**Und der Filter ist nicht Bequemlichkeit, sondern Bedingung.** Der erste
Entwurf hier las `grep '"kind":"result"' … | tail -8`, und auf `cloudsrv24`
kamen acht Zeilen `system.info` zurück und sonst nichts: Der
Kennzahlen-Sammler fragt den Agenten **alle zehn Sekunden**, also decken acht
Ergebniszeilen achtzig Sekunden ab. Was der Dateimanager davor geschrieben
hatte, war längst darüber hinaus.

> **Ein `tail` über ein Protokoll mit Herzschlag misst den Herzschlag.**

Dasselbe gilt für jede andere Stelle dieses Laufs, die in dieses Protokoll
sieht: erst auf die Operation filtern, dann `tail`.

Der Wächter dazu ist `SandboxCredentialsTest`, mit fünf Brüchen in
`tests/waechter-brechen.sh`; der wichtigste davon ist der harmloseste: eine
Operation, die den Beleg *selbst* in ihr Ergebnis schreibt. Sie sieht richtig aus
und macht die Regel wieder zu einer, die dreizehnmal befolgt werden muss.

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

**Sie stehen als Skript da: `tests/stumpf.sh`** — gebaut am 18. August, gegen
`v0.6.0-rc.15`, und jeder Eingriff weist nach, dass er gewirkt hat.

| Bau | Eingriff | Datei |
|---|---|---|
| stumpf-A | `path()` gibt den Pfad unverändert zurück — Zerlegung und Normalisierung entfallen | `agent/src/Files/Workspace.php` |
| stumpf-B | `run()` ruft `$work()` unmittelbar auf statt `Sandbox::run(…)` | `agent/src/Files/Workspace.php` |
| stumpf-C | der Befehl des Kunden steht wieder in der Cron-Zeile | `agent/src/Ops/CronApply.php` **und** `agent/src/Cron/CronFile.php` |

**stumpf-C braucht zwei Stellen, und das ist beim Bauen aufgefallen.** Dieses
Dokument beschrieb ihn als einen Eingriff an `render()` — dorthin kommt der
Befehl aber nie: `CronApply` bildet die Jobs vorher auf `['id', 'schedule']` ab
und streift ihn ab. Ein Eingriff nur an `render()` hätte **nichts bewirkt** und
im Durchgang wie eine haltende Abwehr ausgesehen.

> **Ein Eingriff, der die Stelle nicht erreicht, sieht aus wie eine Wand, die
> hält.**

```bash
# Auf dem Bau-Rechner, nicht auf cloudsrv24 — und auf einem losen HEAD.
git switch --detach v0.6.0-rc.15
sh tests/stumpf.sh --pruefen      # erwartet: dreimal „scharf"
sh tests/stumpf.sh a              # eingreifen, und nachweisen dass es wirkt
git diff > /tmp/stumpf-a.patch    # in das Protokoll, wörtlich
# Bauen wie sonst, mit einer Fassung, die man nicht verwechseln kann:
#   Version: 0.6.0-rc.15+stumpf-a
sh tests/stumpf.sh --zurueck      # danach
```

**Jeder Eingriff prüft sich selbst.** `sh tests/stumpf.sh a` wendet ihn an und
misst danach am laufenden Code nach, dass die Wand weg ist — `path()` gibt dann
`/../../../../etc/passwd` zurück statt `/etc/passwd`. Ohne diesen Nachweis
lieferte ein wirkungsloser Eingriff im Durchgang „kein Treffer", also dieselbe
Ausgabe wie eine haltende Abwehr, und der ganze Lauf wäre wertlos.

> **Eine Gegenprobe, die nicht treffen kann, ist keine.**

**Und `--pruefen` gehört davor**, nicht bloss dazu: Es zeigt, dass die Wand
vorher überhaupt stand. Gemessen ist ausserdem, dass jeder Eingriff **die
anderen beiden scharf lässt** — sonst wäre die Trennung aus §1 nur behauptet.

Der Wächter dazu ist `BluntBuildTest`: Er fährt den Trockenlauf in der CI, damit
ein Eingriff nicht still verwaist, wenn der Code umzieht. Drei Brüche, jeder
gefahren.

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
echo 'brav' > beweis
# 7: relativer Ausbruch — zwölf Schritte hinauf, nicht vier (siehe unten)
hinauf=$(printf '../%.0s' $(seq 12))
tar --transform "s|^nutzlast\$|${hinauf}tmp/getroffen-relativ|" -cf raus-relativ.tar nutzlast beweis
# 8: absoluter Pfad
tar -P --transform 's|^nutzlast$|/tmp/getroffen-absolut|' -cf raus-absolut.tar nutzlast beweis
```

Beide hochladen und über `POST /subscriptions/<ABO>/files/extract` entpacken.

| # | erwartet scharf | erwartet stumpf-B |
|---|---|---|
| 7 | nichts ausserhalb der Wurzel; `/tmp/getroffen-relativ` gibt es nicht | Datei liegt da |
| 8 | nichts ausserhalb der Wurzel; `/tmp/getroffen-absolut` gibt es nicht | Datei liegt da |

**Die Gegenprobe je Punkt ist der Nachweis, dass das Archiv überhaupt etwas
enthält**, das ankommen kann: der Eintrag `beweis` muss nach dem scharfen Lauf
**innerhalb** der Wurzel liegen, und derselbe Tarball, von `tar -xPf` selbst
entpackt, muss die Nutzlast **ausserhalb** anlegen. Ein Archiv, dessen Eintrag
gar nicht entpackt wird, erzeugt dieselbe Abwesenheit wie eine gehaltene Grenze.

**Zwölf Schritte hinauf und nicht vier — das ist eine Berichtigung dieser
Vorschrift vom 18. August.** Der erste Wortlaut schrieb `../../../../`, und das
reicht nur von einem flachen Zielverzeichnis aus. Wird nach
`/var/www/vhosts/<ABO>/httpdocs/boes/ziel` entpackt, landen vier Schritte bei
`/var/www/vhosts/<ABO>/tmp/…` — **innerhalb** der Wurzel des Abonnements. Die
Gegenprobe legt dann nichts ausserhalb an, und ein Ausbruch wäre keiner.
Gemeldet hat es der Prüfstand selbst, mit „OHNE MESSUNG".

> **Ein Prüfkörper, dessen Ziel von der Tiefe des Ordners abhängt, misst den
> Ordner und nicht die Grenze.**

`..` an der Wurzel bleibt die Wurzel; zwölf Schritte reichen deshalb von jeder
Tiefe, die hier vorkommt.

**Und der Bau dieser beiden Punkte hat einen Fehler gefunden, der mit dem
Angriff nichts zu tun hat** — `Archive::names()` zählte ein Tar nur an der
Oberfläche auf, und jedes Archiv mit einem Unterverzeichnis verlor alles
darunter. Er steht in `docs/62 §2`, der Wächter dazu ist `ArchiveDepthTest`.

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

### 8.1 Die erste Hälfte: der Weg der Operation

`tests/quota-messen.php` misst sie, und zwar in vier Schritten:

```bash
sudo php tests/quota-messen.php
```

| Abschnitt | was er beantwortet |
|---|---|
| 1 | Läuft auf diesem Dateisystem überhaupt eine Quota? |
| 2 | Ein Wegwerf-Abonnement `quota-messung.probe` mit 1 MB Grenze |
| 3 | 2 MiB schreiben — der Vorgang muss scheitern |
| 4 | Gegenprobe: unter 64 MB gelingt derselbe Vorgang vollständig |

**Abschnitt 1 ist ein Riegel und kein Kriterium.** Läuft die Quota nicht, endet
der Lauf mit Rückgabewert **2** und ohne Befund. Das ist richtig so: Ein
Schreibvorgang, den nichts begrenzt, sagt über den Fehlerweg des Panels nichts.
Wer diesen Ausgang für ein „bestanden" nimmt, hat Punkt 12 nicht gemessen,
sondern übersprungen. Gefragt wird dabei der **Leseversuch** und nicht die
Optionszeile — `docs/41 §2.3`.

**Und Abschnitt 3 druckt eine Zahl, die es vorher nicht gab:** was
`file_put_contents` bei erschöpftem Kontingent wirklich zurückgibt. Der
Quelltext behauptet seit P6, es sei die Zahl der geschriebenen Bytes und nicht
`false`; darauf beruht die ganze Prüfung, und gemessen war es nie.

> **Wissen aus zweiter Hand sieht aus wie Wissen.**

Mitgemessen und nicht im Kriterium: dass **kein halb geschriebener Rest**
liegenbleibt. Die Nachbardatei heisst `.srvpanel-<hex>`, beginnt also mit einem
Punkt und taucht in keiner Auflistung auf — bliebe sie liegen, frässe jeder
Fehlversuch dauerhaft am Kontingent, und der Kunde sähe ein volles Konto ohne
Dateien.

### 8.2 Die zweite Hälfte: dass die Seite es sagt

Die braucht den Browser und ist durch kein Skript zu ersetzen.

**Der Prüfkörper ist am 18. August zweimal verfehlt worden**, und die
Berichtigung steht hier, damit es nicht ein drittes Mal passiert:

| Falle | warum |
|---|---|
| Grenze unter 64 MB | `Quota::minimum()` lässt das Formular nicht zu — der Bestand wird an die Grenze herangeführt, nicht die Grenze an den Bestand |
| Prüfkörper über 1 MiB | `Connection::REQUEST_MAX` weist die Anfrage ab, bevor das Kontingent sie sieht |
| deutscher Text im Prüfkörper | `json_encode` bläht ihn um bis zu 1,71 auf — 620 KB reichen schon für den Abbruch |

Ein Prüfkörper aus reinem ASCII von **700 KB** und **128 KB** Luft unter der
Grenze treffen zuverlässig.

Angemeldet als der Kunde des Wegwerf-Abonnements:

1. **Im Dateimanager** die 700-KB-Datei öffnen, ein Zeichen ändern und
   speichern.
2. Erwartet: **keine Erfolgsmeldung**, sondern der Satz „Die Datei wurde nur
   unvollständig geschrieben — vermutlich ist das Kontingent erschöpft."
3. **Wo er steht, gehört zum Kriterium** (`docs/19 §6`): oben in der
   Zusammenfassung, nicht als roter Rand am Feld. Ein roter Rand behauptet, die
   Eingabe sei falsch — sie ist es nicht.
4. **Bei 390 px messen**, mit dem Aufsatz aus `docs/58 §12`:
   `scrollWidth - clientWidth` am Dokument, und dazu die Gegenprobe, dass ein
   Prüfkörper an das Fenster gebunden ist und nicht 900 px fest. **Gemessen wird
   die Seite, auf der die Meldung steht** — also nach dem Speichern und ohne
   dazwischen neu zu laden. Der erste Anlauf mass die Seite davor und fand
   folgerichtig nichts.

   > **Ein Prüfkörper, der die Seite ohne den Gegenstand misst, misst die Seite
   > und nicht den Gegenstand.**

5. **Die Gegenprobe nach oben:** die Abweichung beim Kontingent wieder
   entfernen, dieselbe Datei noch einmal speichern — sie muss gelingen. Ein
   Fehlschlag, der mit der Grenze verschwindet, hing an ihr.

Der vierte Schritt steht hier, weil `docs/48` genau ihn gebraucht hätte: Dort
schob die endlich lesbare Begründung die Seite um 110 px aus dem Bild, und der
Fix am Rückbau erzeugte einen zweiten Rest.

> **Je wichtiger die Begründung, desto länger ist sie.** „Datei nicht gefunden"
> passte immer.

---

## 9. Die drei Belege (Punkte 13 bis 15)

Sie sind keine Angriffe. Sie fangen den Fall ab, dass alles abgewiesen wird,
weil gar nichts lief.

| # | Beleg | erwartet |
|---|---|---|
| 13 | Jeder Datei-Vorgang meldet die `uid`, unter der er lief | die des Abos, nie 0 |
| 14 | Jeder Datei-Vorgang meldet seine Zusatzgruppen | nur die des Abos, nie 0 |
| 15 | Ein **gültiger** Vorgang derselben Art gelingt | Datei gelesen und geschrieben, Inhalt stimmt |

13 und 14 sind seit dem 18. August ablesbar (0a): Das Ergebnis jeder
`files.*`-Operation trägt `ran_as`, und das Panel legt es unverändert in
`operations.result` ab. Abgelesen wird es je Vorgang und nicht einmal am Ende —
eine Grenze, die alles abweist, weil der Agent seit Punkt 4 gar nicht mehr
antwortet, meldet dieselben Nullen wie eine, die hält.

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
