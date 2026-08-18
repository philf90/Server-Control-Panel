# 62 — Das Protokoll des Angriffsdurchgangs (P6, Schritt 11)

Der Lauf steht in `docs/61`. Dieses Protokoll entsteht **während** er gefahren
wird, und es ist am 18. August 2026 angelegt worden, als die ersten Punkte
gemessen waren — nicht vorher, denn ein Verweis auf ein Dokument ohne Inhalt ist
ein toter Verweis.

**Es ist unvollständig, und das steht in §3.** Von den zwölf Angriffen und drei
Belegen aus `docs/51 §4` sind zwölf gemessen; drei sind offen. Ein Protokoll,
das seine Lücken nicht nennt, liest sich wie eine Abnahme.

| | |
|---|---|
| Maschine | `cloudsrv24` |
| Panel | `v0.6.0-rc.15` |
| Prüfstand | `main` ab `9cbf55f`, für die Punkte 5, 7 und 8 ab `4fe2e10` (Klon unter `/root/srvpanel-abnahme`) |
| PHP | 8.4.24, alle vierzehn Funktionen der Sandbox vorhanden |
| Kernel | Linux 6.8.0-138-generic |

---

## 1. Was gemessen ist

### Punkt 1 und 2 — `..` und der absolute Pfad

**Erfüllt, und zwar anders als der Plan es schrieb.** `docs/51 §4` verlangte
„abgewiesen"; gemessen wird ein harmloser Erfolg. `Files\Workspace::path()`
normalisiert, statt zurückzuweisen — die Begründung steht in `docs/61 §0b`.

Der Weg der Operation (Abschnitt 4b des Prüfstands), in drei Bauten:

| Bau | Normalisierung / eigener Prozess | Ergebnis |
|---|---|---|
| scharf | ja / ja | dreimal `haelt` |
| stumpf-A (ohne Normalisierung) | nein / ja | dreimal `haelt` |
| stumpf-B (ohne Chroot) | ja / nein | **AUSBRUCH, 3 von 3** |

**Die mittlere Zeile ist die Aussage dieses Punktes.** Dass der Angriff auch
ohne die Normalisierung nichts erreicht, ist der Beleg dafür, dass nicht sie ihn
hält, sondern das Chroot. Wer nur scharf misst, kann die beiden Wände nicht
auseinanderhalten.

> **Eine Gegenprobe, die zwei Wände zugleich wegnimmt, sagt über keine von
> beiden etwas.**

### Punkt 3 und 4 — Symlink lesen, Symlink auflisten

**Erfüllt.** Abschnitt 4 des Prüfstands, jeder Fall mit seiner Gegenprobe: Der
scharfe Zugriff liefert nichts, der stumpfe (derselbe Zugriff ohne Sandbox, als
root) liefert den Inhalt. Fünf Zeilen, alle `haelt`.

Mitgemessen und nicht im Kriterium: `conf/` (`root:root 0640`) bleibt für den
Kunden unlesbar — dort hält nicht das Chroot, sondern das Dateisystem.

### Punkt 5 — durch einen Verweis hinaus schreiben

**Erfüllt**, seit Abschnitt 4c des Prüfstands. Er stand bis zum 18. August offen
mit der Begründung „der Prüfstand liest nur, er schreibt nicht" — Lesen und
Schreiben sind zwei Fragen, und gemessen war nur die erste.

| | |
|---|---|
| `/etc/shadow` davor / danach | `225a48073778…` / `225a48073778…` — unverändert |
| Wegwerfziel ausserhalb, scharf | `unberührt` |
| dasselbe Ziel, ohne Sandbox | `stumpf durchgekommen` |

**Die Gegenprobe geht nie auf `/etc/shadow`**, und das ist eine Entscheidung und
kein Versehen: Sie müsste treffen, und das hiesse, die Kennwortdatei der
Maschine zu überschreiben, auf der der Lauf fährt. Sie nimmt deshalb einen
zweiten Verweis auf eine Wegwerfdatei ausserhalb der Wurzel — dieselbe Form des
Angriffs, ein anderes Ziel. `/etc/shadow` selbst wird nur gemessen.

### Punkt 7 und 8 — die bösartigen Archive

**Erfüllt**, seit Abschnitt 4d. Je Archiv drei Zahlen:

| Archiv | ausserhalb | `beweis` drinnen | entpackt | `unnamed` | ohne Sandbox (`tar -xPf`) |
|---|---|---|---|---|---|
| `../` davor | nichts | ja | 1 | 1 | Datei liegt ausserhalb |
| absoluter Pfad | nichts | ja | 1 | 1 | Datei liegt ausserhalb |

Die dritte Spalte ist die Gegenprobe nach innen: Ein Archiv, das gar nicht
entpackt wird, erzeugt dieselbe Abwesenheit wie eine gehaltene Grenze. Die
letzte ist die nach aussen.

**Die Vorschrift aus `docs/61 §6` war dabei nicht fahrbar** und ist berichtigt.
Sie schrieb `../../../../` — vier Schritte hinauf, was nur von einem flachen
Zielverzeichnis aus reicht. Vom Zielverzeichnis des Prüfstands
(`/var/www/vhosts/<ABO>/httpdocs/boes/ziel`) landen vier Schritte bei
`/var/www/vhosts/<ABO>/tmp/…`, also **innerhalb** der Wurzel des Abonnements.

> **Ein Prüfkörper, dessen Ziel von der Tiefe des Ordners abhängt, misst den
> Ordner und nicht die Grenze.**

Gemeldet hat es der Prüfstand selbst, mit „OHNE MESSUNG".

### Punkt 6 — der Tausch während des Vorgangs

**Erfüllt.** Der Angreifer aus `docs/50 §3`, vier Prozesse, `renameat2` mit
`RENAME_EXCHANGE` — die Zeile „Tauschverfahren: renameat2(RENAME_EXCHANGE),
atomar" steht in allen drei Läufen, also ist FFI da und der Angreifer der
scharfe.

| | scharf | stumpf |
|---|---|---|
| Treffer ausserhalb, 30 000 Runden | **0** | 6407 / 4409 / 5646 |

Drei Läufe, drei verschiedene stumpfe Zahlen — und die Null bleibt Null.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Und der Rückbau dazu** (Abschnitt 6): 0 von 60 Durchgängen treffen etwas
ausserhalb; die stumpfe Fassung trifft nach 12, 1 und 25 Durchgängen.

### Punkt 9 und 10 — die Einschleusung in die Cron-Zeile

**Erfüllt, gemessen im Cron-Lauf** (`docs/60 §13`, 18. August, 32 zu 0):

| | |
|---|---|
| rohe Zeile mit eingeschleustem `root` | **läuft** — der Angriff ist gut gezielt |
| dieselbe Einschleusung in der Bauart des Panels (`.cmd`) | **läuft nicht** |
| `%` im Befehl | bleibt stehen, der Befehl wird nicht abgeschnitten |

Die erste Zeile ist die Gegenprobe: Ohne sie wäre die zweite kein Beleg.

**Offen bleibt die scharfe Hälfte durch das echte Panel** — siehe §3.

### Punkt 13 und 14 — unter wem der Vorgang lief

**Erfüllt, seit `v0.6.0-rc.15`.** Abgelesen im Protokoll des Agenten:

| Operation | `ran_as` |
|---|---|
| `files.tree`, `files.list` (Benutzer `p1136`) | `{"uid":1001,"groups":[1001]}` |
| `subscription.remove` (Benutzer `p1138`) | `{"uid":1002,"groups":[1002]}` |
| `system.info` (Gegenprobe) | **kein Feld** |

Zwei Abonnements ergeben zwei Kennungen — eine Konstante sähe gleich aus.

**Halb offen:** Dass `1001` die Kennung von `p1136` ist, sagt `id` und nicht das
Protokoll. „Nicht null" ist die eine Hälfte von Punkt 13.

### Punkt 15 — ein gültiger Vorgang gelingt

**Erfüllt**, in jedem Lauf und vor allen Nullen: Abschnitt 3 liest eine gültige
Datei („innen") unter `uid=1002`, Gruppe 1002.

### Der Lauf vom 18. August, 14:34 — und was er nebenbei bestätigt hat

Die Punkte 5, 7 und 8 sind auf `cloudsrv24` gegen `4fe2e10` gemessen worden,
und derselbe Aufruf fährt **alle** Abschnitte. Damit sind die früher gemessenen
Punkte ein zweites Mal belegt, mit anderen Zahlen an denselben Stellen:

| | erster Lauf | 18. August, 14:34 |
|---|---|---|
| Rennen, Treffer ausserhalb (30 000 Runden) | scharf 0, stumpf 6407 / 4409 / 5646 | scharf **0**, stumpf **5114** |
| Rückbau | scharf 0 von 60, stumpf nach 12 / 1 / 25 | scharf **0 von 60**, stumpf **1 nach 5** |
| Bau | scharf | scharf (`ja / ja`) |

**Die stumpfen Zahlen sind jedes Mal andere, und die Null bleibt Null** — genau
das macht sie zu einer Messung und nicht zu einer Abwesenheit.

Vor dem Lauf hat `stumpf.sh --zurueck` gemeldet `stumpf-a scharf`,
`stumpf-b scharf`: Es lag nichts von einem früheren Durchgang herum. Rückgabewert
des Prüfstands: **0**, keine Zeile „OHNE MESSUNG".

**Und der erste Anlauf hat nichts gemessen** — er brach mit
`pathspec 'main' did not match any file(s)` ab, weil der Klon vom August keinen
lokalen Zweig `main` hatte und die Befehlsfolge einen erwartete. Die Prüfung, ob
der Prüfkörper überhaupt vorbeikommt, stand eine Zeile zu spät: **nach** dem
Umschalten statt davor.

> **Eine Vorprüfung, die hinter dem Schritt steht, den sie absichern soll, ist
> keine.**

---

## 2. Was der Lauf über sich selbst gelernt hat

**Die ersten drei Läufe haben nichts gemessen, und sie sahen aus wie eine
Messung.** `stumpf.sh a` und `b` patchen `Files\Workspace`; Abschnitt 4 des
Prüfstands ruft `Sandbox::run()` unmittelbar auf und kommt dort nie vorbei. Die
drei Logs waren Zeile für Zeile identisch.

`stumpf.sh --pruefen` meldete dabei zu Recht „ist stumpf" — es hatte die Wand
geöffnet und nachgewiesen, dass sie offen ist. Nur ging niemand hindurch.

> **Ein Nachweis, dass der Eingriff wirkt, sagt nichts darüber, dass der
> Prüfkörper dort vorbeikommt.**

Daraus sind vier Änderungen entstanden, alle in `9cbf55f`:

1. Abschnitt **4b** nimmt den Weg einer echten Operation.
2. Der Prüfstand **nennt den Bau**, aus dem er kommt — erkannt am Verhalten.
   Drei Läufe, die das nicht tun, sind ein Log dreimal.
3. **`stumpf-c` ist weggefallen**: Durch die Cron-Wand geht kein Prüfkörper.
4. Ein Wächter hält den Bezug fest, damit das nicht wiederkommt.

**Und der Bau der Punkte 7 und 8 hat einen Fehler gefunden, der mit dem Angriff
nichts zu tun hat.** `Archive::names()` zählte ein Tar mit `foreach (new
PharData($archive) as $file)` auf, und diese Schleife läuft über die **oberste
Ebene**. Ein gewöhnliches Archiv mit `oben.txt`, `dir/mitte.txt` und
`dir/sub/tief.txt` ergab `entries: 2` statt 5: `oben.txt` wurde geschrieben,
`dir` landete unter „verlegt", und die beiden Dateien darunter verschwanden
spurlos. Kein Ausbruch — ein Merkmal, das für jedes Tar mit einem
Unterverzeichnis das Falsche tat, seit es das Merkmal gibt.

> **Eine Aufzählung, die Ebenen hat, zählt nicht dasselbe wie eine, die keine
> hat.**

Zip war nie betroffen (`ZipArchive` zählt über den Index auf). Der Wächter dazu
ist `ArchiveDepthTest`; er baut seine Archive Byte für Byte selbst, weil
`PharData` keinen Eintrag mit `..` schreiben kann und weil ein Archiv aus dem
Prüfling den Prüfling gegen sich prüfte.

**Und kein Wächter dieses Repos hätte ihn finden können**, weil keiner je ein
Archiv gebaut hat. Gefunden hat ihn ein Prüfstand für eine andere Frage — zum
dritten Mal in diesem Lauf ist der Befund dort entstanden, wo niemand gesucht
hat.

**Zum vierten Mal dieselbe Falle mit dem Pfad im Chroot.** Der erste Wurf von 4d
reichte den Pfad der Maschine in die Sandbox hinein, wo dasselbe Verzeichnis
anders heisst. Diesmal war es ein Absturz und kein falsches Grün — die drei Male
davor (Abschnitte 4, 4b, 4c) sahen aus wie „hält".

---

## 3. Was offen ist

**Drei von fünfzehn Punkten sind ungemessen.** Sie stehen hier einzeln, weil
ein Protokoll ohne seine Lücken sich wie eine Abnahme liest.

| # | offen, weil |
|---|---|
| 11 | Mandantenübergriff über alle 22 Routen — braucht zwei Wegwerf-Abonnements und das echte Panel |
| 12 | volle Quota — Fehlerweg des Vorgangs |
| 9, 10 (scharf) | die Einschleusung durch das **echte Panel**; gemessen ist bisher der Prüfkörper daneben |

**Die Punkte 5, 7 und 8 sind am 18. August dazugekommen** und stehen oben in §1.
Was an ihnen offen bleibt, ist dasselbe wie bei 9 und 10: Der Prüfstand geht den
Weg der Operation, nicht den durch die Route. Für 7 und 8 heisst das den Weg
über `POST /subscriptions/<ABO>/files/extract` mit einem hochgeladenen Archiv.

Dazu die halbe Hälfte von Punkt 13 (`id p1136`), und aus `docs/61 §0`:
`/var/lib/srvpanel` gehört auf dieser Maschine `root:root 0755` statt
`srvpanel:srvpanel 0750`, wie `nfpm.yaml` es deklariert.

**Damit ist P6 nicht abgenommen**, und `v0.6.0` kommt danach und nicht davor.
