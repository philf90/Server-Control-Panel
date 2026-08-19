# 63 — Die Bilderrunde (P6, Schritt 12)

Der letzte Schritt von P6 (`docs/51 §12`). Er beantwortet **eine** Frage je
Ansicht: Schiebt die Seite bei 390 px aus dem Bild, und sieht sie in beiden
Themes so aus, wie sie gemeint ist?

Dieses Dokument ist die Vorschrift. Das Protokoll entsteht **während** des
Laufs und nicht danach; es ist `docs/64` und angelegt worden, als die ersten
beiden Ansichten gemessen waren. Vorher stand hier keine Nummer — ein Verweis
auf ein Dokument, das es nicht gibt, ist ein toter Verweis, und `DocLinkTest`
besteht zu Recht darauf.

> **Schritt 12 wird nicht abgehakt, wenn er gerade nicht geht.** `docs/49 §6`
> hält fest, was das einmal gekostet hat: `v0.4.0-rc.4` ist mit einem
> Umbruchfehler ausgeliefert worden, weil die Bilderrunde einen Tag zu spät kam,
> und die nachgeholte Runde fand auf einer einzigen Seite drei Fehler — jeden
> davon vollständig grün getestet.

---

## 1. Das Messmittel

`tests/bilder-messen.js` — in die Konsole einfügen, dann je Lage `bilderMessen()`.

Es gibt zurück:

| Feld | was es bedeutet |
|---|---|
| `stand` | der Tag, an dem das Messmittel zuletzt geändert wurde |
| `breite` / `thema` | die Lage, an der gemessen wurde — damit keine Zeile ihre Herkunft verliert |
| `dokument` | `scrollWidth − clientWidth` der Seite. **Das ist die Zahl, um die es geht: sie muss 0 sein.** |
| `gegenprobe` | `{ ausschlag, erwartet: 200 }` — beide Zahlen müssen gleich sein |
| `schiebt` | jedes Element, das überläuft **ohne** `overflow-x` zu haben. Der Fund. |
| `rollt` | jedes Element, das überläuft **und** rollen darf. Erwartet und in Ordnung. |

Jeder Eintrag in `schiebt` und `rollt` nennt vier Dinge: `wo` (Marke und
Klassen), `pfad` (der Weg von `body` herab), `anfang` (die ersten 120 Zeichen
des Markups) und `ueberlauf`.

**`pfad` und `anfang` gibt es seit dem 19. August**, und zwar wegen dieses
Laufs: In den Ansichten 2 bis 4 stand viermal die Zeile `div` mit 468 px, und
sie zeigte nirgendwo hin — ein Element ohne Klasse heisst unter `wo` eben nur
`div`. Vier Messungen, und keine sagte, welches Element gemeint war.

> **Eine Zahl, die nicht sagt, welche, zwingt zum Suchen.**

`OverflowProbeTest::test_a_finding_names_where_it_is` hält beides fest.

**Und `stand` steht seit demselben Tag daneben, aus einem Grund, der diesen
Lauf schon gekostet hat.** Das Skript lebt in der Konsole und verschwindet bei
jedem Neuladen — es kommt also nach jeder Aufnahme aus der Zwischenablage
zurück. Am 19. August kam es mit den Feldern von vorgestern wieder, während
die Frage, die es beantworten sollte, gerade an den neuen hing; der Ausdruck
sah dabei aus wie ein Ergebnis.

> **Ein Werkzeug, das nach jedem Neuladen aus der Zwischenablage kommt, ist so
> alt wie die Zwischenablage und sagt es nicht.**

**Das Skript wird deshalb nach jedem Neuladen frisch aus dem Zweig geholt**,
nicht aus der Zwischenablage der vorigen Aufnahme, und `stand` gehört in jede
Zeile des Protokolls.

**Warum die Gegenprobe an `scrollWidth` hängt und nicht an einer Zahl.** Befund
22 aus `docs/59`: Ein fester Block von 900 px schlägt bei 390 px aus und bei
1440 px nicht — dort steht dann dieselbe `0` wie bei einer kaputten Messung.
`docs/58 §12` hat das auf `clientWidth + 200` berichtigt, und **auch das reichte
nicht**: Am 19. August 2026 im Container gemessen, fällt der Ausschlag auf einer
Seite, die *schon* schiebt, wieder auf `0` — der Prüfkörper ist dann nicht mehr
das Breiteste. Gegen `scrollWidth + 200` gemessen ergibt er in allen vier Lagen
`200/200`, heil wie kaputt:

| | brave Seite | Seite mit einem 2500-px-Block |
|---|---|---|
| 390 px | `dokument 0`, Gegenprobe `200/200` | `dokument 2110`, Gegenprobe `200/200` |
| 1440 px | `dokument 0`, Gegenprobe `200/200` | `dokument 1060`, Gegenprobe `200/200` |

> **Ein Prüfkörper, der nur auf der heilen Seite ausschlägt, belegt die Messung
> dort, wo sie niemand braucht.**

`OverflowProbeTest` hält alle vier Zusagen fest; die Brüche stehen im
Bruchskript. **Damit hat Befund 22 seinen Wächter** — bis heute stand die
Vorschrift in einem Dokument, und kein Test liest ein Dokument.

---

## 2. Vorbereitung auf `cloudsrv24`

Ohne diese Daten zeigen die halben Ansichten ihren leeren Zustand, und der ist
nicht der, um den es geht. Gemessen wird auf Abonnement **140**
(`p6-abnahme.invalid`, Systembenutzer `p1139`,
Wurzel `/var/www/vhosts/p6-abnahme.invalid`).

**Fast alles entsteht durch das Panel** — das ist kein Umweg, sondern der Weg,
den der Kunde geht: Wer die Dateien als root hinlegt, prüft nebenbei nicht, ob
sie auf dem echten Weg überhaupt entstehen können, und vergisst dabei zweimal
von drei Malen den Eigentümer. Nur eine Datei braucht root, und die ist
ausdrücklich gekennzeichnet.

### 2.0 — Welche Fassung läuft?

**Vorher klären, denn eine Zahl unten hängt daran.** `FilesRead::MAX_BYTES` —
die Schwelle für den Zustand „zu gross" — stand bis `v0.6.0-rc.17` auf **2 MiB**
und liegt seit der Behebung von Befund 12b auf **960 KiB**
(`Connection::CONTENT_MAX`). Die Prüfkörper unten sind so gewählt, dass sie unter
**beiden** Fassungen dasselbe auslösen; wer sie ändert, prüft das nach.

**Und die Bilder gehören auf eine Fassung mit der Behebung.** Auf `rc.17` öffnet
der Editor eine Datei von 1,5 MB und kann sie nie speichern — das ist genau der
Fehler, den die Runde zeigen soll, und er wäre auf den Bildern als Zustand
verewigt statt behoben.

### 2.1 — Vier Dateien, auf dem Mac erzeugt

```
mkdir -p ~/srvpanel-bilder/paket/unterordner && cd ~/srvpanel-bilder
```

**`gross.bin` — löst „Die Datei ist zu gross" im Editor aus.** 3 MiB: über
beiden Schwellen, und klein genug, dass die Quota es nicht merkt.

```
dd if=/dev/urandom of=~/srvpanel-bilder/gross.bin bs=1m count=3
```

**`binaer.dat` — löst „Die Datei ist nicht lesbar" (binär) aus.** Nicht die
*Grösse* entscheidet das, sondern `mb_check_encoding($content, 'UTF-8')` in
`FilesRead`. `\377` und `\376` sind Bytes, die in UTF-8 **nie** vorkommen; 31
Byte genügen, eine grosse Zufallsdatei ist dafür nicht nötig.

```
printf 'Dies ist keine Textdatei: \377\376\000\001\n' > ~/srvpanel-bilder/binaer.dat
```

**`klein.txt` — die Datei, an der der Editor in seinem gewöhnlichen Zustand
gezeigt wird.**

```
printf 'eins\nzwei\ndrei\n# eine Zeile mit Umlauten: Grüße aus Köln\n' > ~/srvpanel-bilder/klein.txt
```

**`paket.tar` — bringt den Knopf „Entpacken" in seine Zeile.** Er erscheint an
der **Endung** (`isArchive()` in `Index.vue` prüft `.zip|.tar|.tar.gz|.tgz`); der
Agent erkennt das Archiv später am Inhalt. Das Unterverzeichnis ist Absicht: Ein
Tar mit Ebenen ist genau der Fall, an dem `Archive::names()` bis zum 18. August
alles unterhalb der obersten Ebene verloren hat.

```
printf 'oben\n' > ~/srvpanel-bilder/paket/oben.txt && printf 'tief\n' > ~/srvpanel-bilder/paket/unterordner/tief.txt && tar -cf ~/srvpanel-bilder/paket.tar -C ~/srvpanel-bilder/paket .
```

### 2.1b — Dieselben vier Dateien auf einem Windows-Rechner

**Die Fassung für PowerShell, und sie ist nicht bloss eine Übersetzung.** Auf
Windows gibt es eine Falle, die es auf dem Mac nicht gibt und die genau diese
Vorbereitung trifft: *PowerShell rät die Kodierung.* `Out-File` schreibt in
Windows PowerShell 5.1 **UTF-16LE mit BOM**, `Set-Content` schreibt **ANSI**
(Windows-1252). Gemessen am 19. August 2026:

| womit geschrieben | die Bytes von „Grüße" | gültiges UTF-8? |
|---|---|---|
| UTF-8 ohne BOM (gewollt) | `47 72 c3 bc c3 9f 65` | **ja** |
| `Out-File` (PS 5.1) | `ff fe 47 00 72 00 fc 00 …` | nein |
| `Set-Content` (PS 5.1) | `47 72 fc df 65` | nein |

Beide gescheiterten Fassungen sind für `FilesRead` **binär** — die eine beginnt
sogar mit denselben `ff fe`, mit denen `binaer.dat` absichtlich anfängt. Wer
`klein.txt` so erzeugt, bekommt zweimal denselben Zustand und merkt es erst auf
dem Bild.

> **Ein Werkzeug, das eine Kodierung errät, erzeugt einen Prüfkörper für einen
> anderen Zustand.**

Deshalb steht unten überall `[IO.File]`: Diese .NET-Aufrufe schreiben genau die
Bytes, die dastehen, und fragen keine Voreinstellung.

```
New-Item -ItemType Directory -Force -Path "$HOME\srvpanel-bilder\paket\unterordner" | Out-Null
```

**`gross.bin`** — 3 MiB. Der Inhalt ist gleichgültig, allein die Grösse zählt:

```
$b = New-Object byte[] 3MB; (New-Object Random).NextBytes($b); [IO.File]::WriteAllBytes("$HOME\srvpanel-bilder\gross.bin", $b)
```

**`binaer.dat`** — 31 Byte, davon vier, die es in UTF-8 nie gibt:

```
$kopf = [Text.Encoding]::ASCII.GetBytes("Dies ist keine Textdatei: "); $roh = $kopf + [byte[]](0xFF,0xFE,0x00,0x01,0x0A); [IO.File]::WriteAllBytes("$HOME\srvpanel-bilder\binaer.dat", [byte[]]$roh)
```

**`klein.txt`** — UTF-8 ohne BOM, mit Zeilenvorschub statt CRLF. Die Umlaute
stehen als Zeichencode und nicht als Buchstabe: Ein `ü`, das durch eine Konsole
mit der falschen Codepage gelaufen ist, sieht im Skript richtig aus und liegt
falsch in der Datei.

```
$text = "eins`nzwei`ndrei`n# eine Zeile mit Umlauten: Gr" + [char]0xFC + [char]0xDF + "e aus K" + [char]0xF6 + "ln`n"; [IO.File]::WriteAllText("$HOME\srvpanel-bilder\klein.txt", $text, (New-Object Text.UTF8Encoding $false))
```

**`paket.tar`** — `tar.exe` liegt seit Windows 10 (1803) bei und ist bsdtar:

```
[IO.File]::WriteAllText("$HOME\srvpanel-bilder\paket\oben.txt", "oben`n", (New-Object Text.UTF8Encoding $false)); [IO.File]::WriteAllText("$HOME\srvpanel-bilder\paket\unterordner\tief.txt", "tief`n", (New-Object Text.UTF8Encoding $false)); tar -cf "$HOME\srvpanel-bilder\paket.tar" -C "$HOME\srvpanel-bilder\paket" .
```

**Die Gegenprobe, bevor hochgeladen wird.** Zwei Zeilen, und sie beantworten
genau die Frage, an der die Falle oben hängt — welche Datei ist gültiges UTF-8
und welche nicht.

**Gelesen wird über `[IO.File]`, aus demselben Grund wie geschrieben.** Der
erste Entwurf griff hier zu `Get-Content -AsByteStream`; den Schalter gibt es
erst ab PowerShell 7, und auf Windows PowerShell 5.1 heisst er `-Encoding Byte`.
Der Unterschied stand als Fussnote **hinter** dem Befehl, und der Befehl davor
war der falsche — genau die Bauart, an der `docs/59` Befund 7 schon einmal
gescheitert ist.

> **Ein Hinweis hinter dem Befehl hilft dem nicht, der den Befehl schon kopiert
> hat.**

`[IO.File]::ReadAllBytes` gibt es in beiden Fassungen, und es ist derselbe Weg,
über den die Dateien entstanden sind.

```
(([IO.File]::ReadAllBytes("$HOME\srvpanel-bilder\klein.txt"))[0..7] | ForEach-Object { "{0:X2}" -f $_ }) -join ' '
```

Erwartet — `eins` gefolgt von einem Zeilenvorschub, **kein** `FF FE` davor:

```
65 69 6E 73 0A 7A 77 65
```

```
([IO.File]::ReadAllBytes("$HOME\srvpanel-bilder\binaer.dat") | ForEach-Object { "{0:X2}" -f $_ }) -join ' '
```

Erwartet — 31 Byte, die letzten fünf sind der Kern der Sache:

```
44 69 65 73 20 69 73 74 20 6B 65 69 6E 65 20 54 65 78 74 64 61 74 65 69 3A 20 FF FE 00 01 0A
```

**Für den SFTP-Schlüssel** (§2.5) liegt `ssh-keygen` seit Windows 10 bei. Die
Passphrase-Abfrage bleibt leer — zweimal Eingabe drücken; `-N` mit leerer
Zeichenkette ist auf PowerShell je nach Fassung anders zu maskieren und lohnt
den Ärger nicht:

```
ssh-keygen -t ed25519 -C punkt11-gegenprobe-140 -f "$HOME\srvpanel-bilder\gegen140"
```

```
Get-Content "$HOME\srvpanel-bilder\gegen140.pub" | Set-Clipboard
```

**§2.4 bleibt unverändert.** Der Befehl läuft über SSH auf dem Server und ist
damit derselbe; `ssh` bringt Windows seit Fassung 1809 mit.

### 2.2 — Die vier Dateien hochladen

Im Dateimanager von 140 nach `/httpdocs`, über **Hochladen**. Alle vier auf
einmal — das zeigt nebenbei den Fortschrittsbalken.

Der Weg über das Panel ist hier auch der sichere: `httpdocs` gehört
`p1139:www-data` mit gesetztem setgid-Bit. Eine Datei, die root dort hinlegt,
gehört danach `root:www-data`, und der Kunde kann sie nicht bearbeiten — der
Editor stünde dann für **alle vier** auf „nur lesbar".

### 2.3 — Ein Verzeichnis mit dem längsten erlaubten Namen

Über **Verzeichnis anlegen**, in `/httpdocs`. Das Panel lässt `max:255` zu, also
wird auch 255 genommen: Halten die Krümel und die Liste das aus, halten sie
alles.

```
sehr-langer-verzeichnisname-zum-messen-der-umbrueche-sehr-langer-verzeichnisname-zum-messen-der-umbrueche-sehr-langer-verzeichnisname-zum-messen-der-umbrueche-sehr-langer-verzeichnisname-zum-messen-der-umbrueche-sehr-langer-verzeichnisname-zum-messen-der-
```

Danach **hineinklicken** — die Krümelleiste trägt den Namen dann in voller
Länge, und das ist die Stelle, an der `docs/46 §20.11` schon einmal 99 px aus
dem Bild geschoben hat.

### 2.4 — Eine Datei, die dem Kunden nicht gehört (root nötig)

Für den Zustand „nur lesbar" im Editor. `conf/` gehört `root:root 0755` — der
Kunde darf hinein sehen und nichts ändern. Per SSH auf `cloudsrv24`:

```
printf 'Diese Datei gehört root. Der Kunde darf sie lesen und nicht ändern.\n' > /var/www/vhosts/p6-abnahme.invalid/conf/hinweis.txt
```

Nichts weiter — **kein `chown`.** Dass sie root gehört, ist der Zweck.

### 2.5 — SFTP

Ein Schlüssel auf 140. Das Paar von Punkt 11 liegt noch auf dem Mac:

```
pbcopy < ~/srvpanel-punkt11/gegen140.pub
```

Auf `/subscriptions/140/sftp` einfügen, Bezeichnung **Punkt 11 Gegenprobe**.

### 2.6 — Cron

Zwei Jobs auf `/subscriptions/140/cron`:

| | Zeitplan | Befehl | wofür |
|---|---|---|---|
| A | `* * * * *` | `echo eins; date` | nach zwei Minuten hat er Läufe mit Ausgabe |
| B | `0 3 * * *` | `exit 3` | die Zeile mit Rückgabewert ≠ 0 |

**Es gibt keinen Auslöser — und das ist der Grund für die Reihenfolge unten.**
Unter `/cron` liegen sechs Routen, und keine führt einen Job aus; „Läufe" in der
Aktionsspalte ist ein Link auf die Aufzeichnung. Der fehlgeschlagene Lauf
entsteht deshalb über den Zeitplan:

1. **Zuerst das Bild „ohne Läufe" aufnehmen** — solange B noch keinen hat.
   Danach ist der Zustand nicht mehr herzustellen, ohne den Job zu löschen.
2. Bei B auf **Ändern**, Zeitplan auf `* * * * *`, speichern.
3. **Sechs Minuten warten.** Zwei Fristen liegen hintereinander, und nur die
   erste steht auf der Cron-Seite: cron liest seine Dateien einmal je Minute
   neu, und der Lauf erscheint erst, wenn `srvpanel-cron.timer` ihn eingesammelt
   hat — alle fünf Minuten, mit bis zu 30 Sekunden Streuung. Die Läufe-Seite
   sagt es selbst: „Läufe werden alle fünf Minuten eingesammelt."

   > **Zwei Fristen hintereinander sind nicht die längere von beiden, sondern
   > ihre Summe.**
4. Bei B auf **Läufe** — dort steht jetzt der Lauf mit Rückgabewert 3.
5. Bei B auf **Ändern**, Zeitplan zurück auf `0 3 * * *`, speichern.

Schritt 5 kostet die Aufzeichnung nichts: `Cron::update()` macht `fill()` und
`save()` auf derselben Zeile, die Kennung des Jobs bleibt, und die Läufe hängen
an ihr.

Zuletzt **A auf inaktiv** stellen, damit die Liste beide Zustände nebeneinander
zeigt.

**Notiert, gehört aber nicht zu diesem Schritt:** Dass ein Kunde seinen frisch
angelegten Cronjob nur prüfen kann, indem er den Zeitplan verstellt und wieder
zurückstellt, ist eine Lücke. Der Plan von P6 verlangt „Jetzt ausführen" nicht,
und dieser Schritt baut es nicht — es steht hier, weil es die Stelle ist, an der
ein Kunde als Erstes danach greift.

### 2.7 — Zwei Blicke vor dem Start

- **Der Platz.** Die Übersicht des Abonnements zeigt die Belegung. 3 MiB müssen
  hineinpassen; ist das Kontingent knapp, schlägt das Hochladen fehl und sieht
  aus wie ein Fehler des Dateimanagers.
- **Abonnement 137.** Für Punkt 12 ist dort das Kontingent auf 64 MB gesetzt und
  mit Fülldaten bis auf 128 KB ausgereizt worden (`docs/62`). Die Grenze ist
  danach zurückgestellt; ob die Fülldaten noch liegen, ist nicht festgehalten.
  137 wird hier nur auf den drei Auswahlseiten sichtbar und nirgends
  beschrieben — es schadet also nicht, gehört aber gesehen, bevor es als Befund
  gemeldet wird.

---

## 3. Wovon Bilder gemacht werden

**Neun Ansichten.** Jede in **beiden Themes** und bei **beiden Breiten** —
390 px und 1440 px. Das sind 36 Aufnahmen, und jede trägt ihre Messung daneben.

| # | Ansicht | Adresse |
|---|---|---|
| 1 | Dateien, Auswahl | `/files` |
| 2 | Dateimanager | `/subscriptions/<abo>/files` |
| 3 | Editor | `/subscriptions/<abo>/files/edit?path=/httpdocs/klein.txt` |
| 4 | Suche | `/subscriptions/<abo>/files/search?q=eins` |
| 5 | SFTP, Auswahl | `/sftp` |
| 6 | SFTP-Zugang | `/subscriptions/<abo>/sftp` |
| 7 | Cron, Auswahl | `/cron` |
| 8 | Cronjobs | `/subscriptions/<abo>/cron` |
| 9 | Läufe | `/subscriptions/<abo>/cron/<job>/runs` |

**Die drei Auswahlseiten (1, 5, 7) erscheinen nur, wenn mehr als ein Abonnement
erreichbar ist** — bei genau einem leitet der Controller durch. Als
Administrator in der Kundensicht eines Kunden mit zwei Abonnements sind sie zu
sehen.

### Und dazu die Zustände, die das Layout ändern

Diese **einmal bei 390 px** in einem Theme, mit Messung. Das Thema wechselt die
Farbe und nicht die Geometrie; die Überlauffrage ist eine Frage der Geometrie.
Wo ein Zustand einen eigenen *Kontrast* mitbringt — eine Meldung in Rot, Gelb
oder Grau —, kommt er zusätzlich im zweiten Theme dazu.

| Ansicht | Zustand | wie er entsteht |
|---|---|---|
| Dateimanager | Baum links, Liste rechts | bei 1440 px sichtbar, bei 390 px gestapelt |
| Dateimanager | Mehrfachauswahl mit Auswahlleiste | zwei Einträge ankreuzen |
| Dateimanager | Ziel im Baum wählen | „Verschieben" drücken |
| Dateimanager | Packen, mit Namensfeld | „Packen" drücken |
| Dateimanager | die vier Formulare der Kopfleiste | Verzeichnis anlegen, Datei anlegen, Hochladen, Suchen |
| Dateimanager | die zwei Formulare an einer Zeile | „Rechte" und „Umbenennen" in der Aktionsspalte eines Eintrags |
| Dateimanager | der lange Verzeichnisname in den Krümeln | in 2.1 angelegt — **hier ist `docs/46 §20.11` gebrochen** |
| Editor | „zu gross" | `gross.bin` öffnen |
| Editor | „binär" | `binaer.dat` öffnen |
| Editor | nur lesbar | eine Datei unter `conf/` öffnen |
| Suche | kein Treffer | `?q=zeichenkettediees nichtgibt` |
| Suche | gekürzt | ein Wort, das oft vorkommt |
| SFTP | ohne Schlüssel | den Schlüssel entfernen, Bild, wieder eintragen |
| SFTP | Zugang gestört | — nur wenn er es gerade ist; **nicht herstellen** |
| Cron | ohne Jobs | vor 2.3 aufnehmen |
| Cron | Kontingent voll | zehn Jobs anlegen |
| Cron | Formular „Ändern" offen | „Ändern" drücken |
| Cron | Zeitplan als Ausdruck (Experte) | das Kästchen „Den Zeitplan als Ausdruck eingeben" ankreuzen |
| Cron | Abonnement nicht benutzbar | — nur wenn es zutrifft |
| Läufe | Lauf mit Ausgabe, aufgeklappt | Job A |
| Läufe | Lauf mit Rückgabewert ≠ 0 | Job B |
| Läufe | ohne Läufe | Job B vor dem ersten Auslösen |

---

## 4. Der Ablauf je Aufnahme

1. Breite einstellen. In den Entwicklerwerkzeugen die Geräteansicht auf
   **390 × 844** oder **1440 × 900**.
2. Adresse aufrufen und **neu laden** (⌘R) — **nach jedem Wechsel der Breite,
   nicht nur einmal je Ansicht.**

   Am 19. August gemessen: Dieselbe Seite bei 1440 meldet nach einem Wechsel
   von 390 einen Überlauf von 468 px in einem Element, den sie frisch geladen
   nicht hat. Was davon übrig bleibt, ist nicht geklärt — geklärt ist, dass es
   übrig bleibt.

   > **Eine Messung nach einem Wechsel der Breite misst auch, was von vorher
   > übrig ist.**

3. In der Konsole — **flach ausgegeben und nicht als Objekt**:

   ```
   JSON.stringify(bilderMessen())
   ```

   Die Konsole klappt ein Objekt ein, und `gegenprobe: {…}` sieht neben einem
   `dokument: 0` aus wie eine Messung. Am 19. August sind so für eine ganze
   Ansicht vier Gegenproben ins Protokoll geraten, die niemand gesehen hatte.

   > **Eine Zahl, die man aus einem eingeklappten Objekt abschreibt, hat man
   > nicht gemessen.**
4. Bild aufnehmen — **die ganze Seite**, nicht nur den sichtbaren Ausschnitt.
5. Thema umschalten und ab 3 wiederholen:
   ```
   window.srvpanelTheme('dark')
   ```
   ```
   window.srvpanelTheme('light')
   ```

**Nach jedem Umschalten neu messen.** Ein Theme wechselt Ränder und Schriftgrade
mit; das ist kein grosser Unterschied und war schon zweimal genau der.

---

## 5. Was ein Fund ist

- `dokument` grösser als 0 — die Seite schiebt. **Das ist das Kriterium.**
- `gegenprobe.ausschlag` ungleich `erwartet` — **dann ist die ganze Zeile
  ungültig** und wird nicht als Messung notiert.
- **und alles, was auf dem Bild falsch aussieht, ohne eine Zahl zu erzeugen.**

**`schiebt` ist ein Hinweis und kein Urteil.** Der erste Entwurf zählte jeden
Eintrag als Fund; die erste Messung hat gezeigt, dass das nicht trägt. Bei
390 px steht dort regelmässig `thead` mit rund 350 px — das ist `.stacks thead`
aus `app.css`, absichtlich mit `position: absolute; width: 1px; overflow:
hidden; clip-path: inset(50%)` aus dem Bild genommen, damit der Screenreader die
Spaltenüberschriften behält. Ein Mechanismus, kein Fehler.

> **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein Urteil.**

Jeder Eintrag wird deshalb einzeln beurteilt und im Protokoll benannt: gewollt
oder Fund. Ungeprüft stehen lassen gilt nicht — dann wäre die Spalte Zierde.

Der letzte Punkt ist der wichtigste und hat in P5c zwei Fehler gebracht, die
vollständig grün waren:

> **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite schiebt.
> Keines von beiden ersetzt das andere.**

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

Und die Falle aus `docs/59`, die vier Aufnahmen überlebt hat:

> **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
> verdeckt alles, was daneben steht.** Jedes Bild wird einmal *ohne* Frage
> angesehen, bevor die nächste Aufnahme kommt.

---

## 6. Fallen, die diesen Lauf schon gekostet haben

- **`rollt` darf bei 390 px leer sein.** Tabellen stehen dort als Stapel
  Kärtchen (`.stacks`, `docs/24 §5`) und sind so breit wie ihre Spalte; gerollt
  wird erst bei 1440 px. Eine Erwartung „grösser als 0" an beiden Breiten misst
  zwei verschiedene Bausteine mit einem Maß (`docs/59`, Block F).
- **Der Editor bringt seinen eigenen Roller mit** (`.cm-scroller`). Er steht
  unter `rollt` und ist dort richtig.
- **Drei Anmeldungen hintereinander sperren die Adresse** (§6.4). Einmal
  anmelden, dann nur noch Thema und Breite umschalten.
- **Die Läufe-Seite braucht Läufe.** Ein Job im Minutentakt liefert sie nach
  zwei Minuten; vorher zeigt sie ihren leeren Zustand, und der ist ein eigenes
  Bild und kein Ersatz.
- **`thema` ist eine Abschrift und keine Prüfung.** Das Feld liest
  `data-theme` wörtlich; die gültigen Werte sind `light` und `dark`. Steht dort
  etwas anderes, greift keine Themeregel — und die Messung meldet den falschen
  Wert brav zurück, als hätte sie ihn festgestellt. Am 19. August im
  Prüfaufbau des Containers genau so passiert: eine helle Seite mit dem Etikett
  „dunkel".

  > **Ein Feld, das die Lage aus dem Dokument abschreibt, bestätigt die Lage
  > nicht — es wiederholt sie.**

  Im Browser schaltet `window.srvpanelTheme('light' | 'dark')`.
- **Ein Eingabefeld mit langem Inhalt steht unter `schiebt` und ist keiner.**
  Ein Textfeld rollt seinen Inhalt von sich aus, ohne `overflow-x: auto` zu
  tragen — der berechnete Wert ist `clip`, und damit fällt es auf die falsche
  Seite der Einteilung. Am 19. August auf der Suchseite bei 1440 px gemessen:
  `input` mit 24 px, `dokument` 0.

  > **Ein Behälter, der von sich aus rollt, sagt es der Messung nicht — sie
  > kennt nur `overflow-x`.**
- **Gemessen wird in einem Fenster ohne Erweiterungen.** Eine Erweiterung
  schreibt in *jedes* Dokument, und was sie hineinschreibt, misst diese Messung
  mit. In diesem Lauf war es LastPass: eine verborgene Meldezeile
  (`lp-menu-live-region`, 1 px breit, `clip: rect(…)`), die in `schiebt` als
  `div` mit 468 px steht — auf jeder Seite gleich breit, weil sie zu keiner
  gehört. Sie hat vier Ansichten lang wie ein Fund am Panel ausgesehen.

  > **Eine Messung am Dokument misst auch, was der Browser hineingeschrieben
  > hat.**

  > **Ein Kasten, der auf jeder Seite gleich breit ist, gehört zu keiner von
  > ihnen.**

  Ein privates Fenster mit abgeschalteten Erweiterungen oder ein frisches Profil
  genügt. Wo das nicht geht, wird jeder Eintrag an seinem `anfang` beurteilt —
  ein Element, dessen Markup keine Klasse dieses Projekts trägt, ist keines
  davon.

---

## 6b. Die Experteneingabe — was ausser dem Bild zu prüfen ist

**Gebaut am 19. August 2026 auf Bestellung des Betreibers** (`docs/64`,
Wunsch 1). Sie ändert die Ansicht, die dieser Schritt prüft, und ist deshalb
Teil der **zweiten** Runde — nicht der ersten.

Das Bild allein genügt hier nicht: Der Ausdruck ist eine Sicht auf die fünf
Felder, und ob er das wirklich ist, sieht man ihm nicht an. Fünf Griffe, alle in
der Kundensicht auf Abonnement 140, alle ohne Speichern ausser dem letzten:

| | Griff | erwartet |
|---|---|---|
| 1 | Kästchen ankreuzen | Im Feld steht `* * * * *` — was vorher in den fünf Feldern stand |
| 2 | `*/15 9-17 * * 1-5` eintragen, Kästchen abwählen | Die fünf Felder tragen `*/15`, `9-17`, `*`, `*`, `1-5` |
| 3 | Bei angekreuztem Kästchen auf „montags bis freitags um 09:00" drücken | Im Feld steht `0 9 * * 1-5` — die Schnellwahl wirkt auch hier |
| 4 | `* * *` eintragen und anlegen | Abweisung, und der Satz steht **oben** in der Zusammenfassung; das Feld ist nur `aria-invalid` |
| 5 | `*/15 * * * *` eintragen und anlegen | Der Job steht in der Liste mit genau diesem Ausdruck |

**Griff 2 ist der eigentliche Punkt.** Schriebe der Ausdruck nicht zurück, hätte
die Seite zwei Wahrheiten über denselben Zeitplan — und gespeichert würde die
alte. Griff 4 belegt die andere Hälfte: Über die Form eines Zeitplans urteilt
nur der Server, und zwar an derselben Stelle wie beim geführten Weg.

Und ein Blick, den keine Zahl liefert: **Nach Griff 5 muss in der Liste stehen,
was eingetippt wurde** — nicht etwas, das ihm ähnlich sieht.

> **Ein Feld, das den Wert eines anderen anzeigt, ist erst dann eine Sicht, wenn
> es ihn auch setzt — sonst ist es eine Kopie mit Verspätung.**

---

## 7. Wann der Schritt fertig ist

Wenn für **alle neun Ansichten** in **allen vier Lagen** eine Zeile mit
`dokument: 0` und gültiger Gegenprobe vorliegt, jede Aufnahme dazu abgelegt ist,
jeder Zustand aus §3 einmal gemessen wurde, die fünf Griffe aus §6b gefahren
sind — und jeder Fund entweder behoben und nachgemessen oder im Protokoll
benannt ist.

**Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
