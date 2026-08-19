# 63 — Die Bilderrunde (P6, Schritt 12)

Der letzte Schritt von P6 (`docs/51 §12`). Er beantwortet **eine** Frage je
Ansicht: Schiebt die Seite bei 390 px aus dem Bild, und sieht sie in beiden
Themes so aus, wie sie gemeint ist?

Dieses Dokument ist die Vorschrift. Das Protokoll entsteht **während** des
Laufs und nicht danach — es wird `docs/64`.

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
| `breite` / `thema` | die Lage, an der gemessen wurde — damit keine Zeile ihre Herkunft verliert |
| `dokument` | `scrollWidth − clientWidth` der Seite. **Das ist die Zahl, um die es geht: sie muss 0 sein.** |
| `gegenprobe` | `{ ausschlag, erwartet: 200 }` — beide Zahlen müssen gleich sein |
| `schiebt` | jedes Element, das überläuft **ohne** `overflow-x` zu haben. Der Fund. |
| `rollt` | jedes Element, das überläuft **und** rollen darf. Erwartet und in Ordnung. |

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

**B einmal von Hand über „Läuft" auslösen** — sonst gibt es die fehlgeschlagene
Zeile nicht. **Und die Ansicht „ohne Läufe" vorher aufnehmen**, gleich nach dem
Anlegen von B: Danach ist sie nicht mehr herzustellen, ohne den Job zu löschen.

Zuletzt **A auf inaktiv** stellen, damit die Liste beide Zustände nebeneinander
zeigt.

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
| Dateimanager | die vier Formulare | Verzeichnis, Datei, Hochladen, Rechte, Umbenennen |
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
| Cron | Abonnement nicht benutzbar | — nur wenn es zutrifft |
| Läufe | Lauf mit Ausgabe, aufgeklappt | Job A |
| Läufe | Lauf mit Rückgabewert ≠ 0 | Job B |
| Läufe | ohne Läufe | Job B vor dem ersten Auslösen |

---

## 4. Der Ablauf je Aufnahme

1. Adresse aufrufen, **neu laden** (⌘R) — nach einer Inertia-Navigation trägt
   die Seite Zustand aus der vorigen.
2. Breite einstellen. In den Entwicklerwerkzeugen die Geräteansicht auf
   **390 × 844** und **1440 × 900**.
3. `bilderMessen()` in der Konsole, Ergebnis notieren.
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

- `dokument` grösser als 0 — die Seite schiebt.
- ein Eintrag in `schiebt` — irgendetwas läuft über, ohne rollen zu dürfen.
- `gegenprobe.ausschlag` ungleich `erwartet` — **dann ist die ganze Zeile
  ungültig** und wird nicht als Messung notiert.
- **und alles, was auf dem Bild falsch aussieht, ohne eine Zahl zu erzeugen.**

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

---

## 7. Wann der Schritt fertig ist

Wenn für **alle neun Ansichten** in **allen vier Lagen** eine Zeile mit
`dokument: 0` und gültiger Gegenprobe vorliegt, jede Aufnahme dazu abgelegt ist,
jeder Zustand aus §3 einmal gemessen wurde — und jeder Fund entweder behoben und
nachgemessen oder im Protokoll benannt ist.

**Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
