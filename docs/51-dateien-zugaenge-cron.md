# 51 — P6: Dateien, Zugänge, Cron

**Der Plan.** Geschrieben am 14. August 2026, nach der Messrunde in `docs/50`
und den fünf Entscheidungen des Betreibers aus §3. Er steht zu `docs/20 §9 P6`
wie `docs/46` zu P5c: Der Neuplan nennt das Ziel, dieses Dokument den Weg.

> **Fertig, wenn der Angriffsdurchgang für Pfadausbruch, Symlink-Tricks und
> Cron-Befehlseinschleusung durchläuft.**

Das ist das erste Abnahmekriterium dieses Projekts, das nicht fragt
„funktioniert es", sondern „hält es". §4 schreibt es aus.

---

## 1. Die eine Stelle, an der P6 mit dem bisherigen Muster bricht

Bis heute nimmt **keine** Agent-Operation einen Pfad entgegen — sie baut ihn aus
einem Namen gegen eine Positivliste. Der Satz steht in
`agent/src/Ops/SubscriptionProvision.php`:

> Eine Operation, die einen Pfad annimmt und ihn danach prüft, ist eine
> Operation, deren Prüfung irgendwann eine Lücke hat.

**Ein Dateimanager kann das nicht.** Er bekommt den Pfad vom Kunden; das ist
seine Aufgabe. Damit fällt der Schutz weg, der P0 bis P5c getragen hat.

`docs/50` hat gemessen, was ihn ersetzt — und was ihn *nicht* ersetzt. Die
naheliegende Antwort, eine bessere Pfadprüfung, ist gemessen falsch: Das heutige
`realpath($x) === $x` plus `is_link` liess **11 081 von 36 056 bestandenen
Prüfungen** ausserhalb der Grenze lesen, sobald ein Prozess des Abonnements
`renameat2(RENAME_EXCHANGE)` in einer Schleife fährt. Einunddreissig Prozent.

Die Antwort ist keine Prüfung, sondern ein **Prozess ohne Rechte in einem
Chroot** (§5).

---

## 2. Was aus der Messrunde in den Plan eingeht

Vollständig in `docs/50`. Die vier Zeilen, die hier tragen:

1. **Die heutige Prüfung verliert das Rennen** — 31 %, gemessen, mit zwei
   eigenen Fehlmessungen daneben, die beide null Treffer meldeten und den
   Angreifer gemessen haben statt die Abwehr.
2. **`openat2(RESOLVE_BENEATH)` hält den Systemaufruf, aber nicht den Vorgang.**
   Es hat kein einziges Mal ausserhalb aufgelöst; undicht war der Weg zurück
   nach PHP. `/proc/self/fd/N` ist eine zweite Pfadauflösung — 8 106 Ausbrüche.
   Derselbe Deskriptor über `read(2)` gelesen: null. **P6 benutzt kein FFI.**
3. **`fork` + `chroot` + Rechteabgabe hält** — 0 Ausbrüche unter demselben
   Angreifer, ohne FFI und ohne neuen Eintrag auf der Positivliste.
4. **Zwei Messungen machen daraus erst eine Schranke:** Roher `chroot(2)` als
   root bricht aus, nach der Rechteabgabe nicht — und `posix_setuid` allein
   nimmt die **Zusatzgruppen von root** mit, bis `posix_initgroups` davorsteht.
   `posix_setgroups` gibt es in PHP nicht.

Dazu: OpenSSH 9.6 nimmt das Schema aus `docs/20 §4.5` unverändert an und weist
jede Abweichung **oberhalb** der Wurzel ab; `internal-sftp` läuft im leeren
Chroot, eine Shell nicht; `proftpd-basic` gibt es in Ubuntu 24.04 nicht mehr.

---

## 3. Die fünf Entscheidungen des Betreibers

Eingeholt am 14. August 2026, nach der Messrunde und mit ihren Zahlen unterlegt.

| # | Frage | Entscheidung |
|---|---|---|
| 1 | Editor mit Syntaxhervorhebung | **CodeMirror 6 als npm-Abhängigkeit** |
| 2 | FTP | **kein FTP** |
| 3 | SSH mit Shell | **nur SFTP** |
| 4 | Aufbewahrung der Cron-Ausgabe | **letzte 50 Läufe je Job, je 64 KB gekappt** |
| 5 | Sichtbereich des Dateimanagers | **ganze Abo-Wurzel, `conf/` nur lesbar** |

**Entscheidung 1 bricht eine Regel dieses Projekts bewusst.** `docs/20 §4.6`
hält fest, dass es keine Diagramm-Bibliothek gibt und warum; das Panel kommt
bisher **ohne jede** Frontend-Abhängigkeit aus. Der Betreiber hat sie für den
Editor zugelassen — an dem Gegenstand, an dem die Regel am meisten kostet. Was
daraus folgt, steht in §11.2: Die Abhängigkeit bleibt auf **eine** Seite
begrenzt, wird nachgeladen und nicht in das gemeinsame Bündel gezogen, und
`resources/css/app.css` behält die Farbhoheit — CodeMirrors eigenes Theme wird
nicht mitgeliefert, sondern aus den vorhandenen Marken gesetzt.

**Entscheidung 2 streicht einen Punkt aus `docs/20 §9`.** FTP-Konten mit eigenem
Startverzeichnis entfallen; der Bedarf geht an SFTP (§9). Der Neuplan bekommt
dazu eine Fussnote, damit die Streichung nicht als Vergessen gelesen wird.

**Entscheidung 3 hält das Chroot leer**, und das ist die Voraussetzung dafür,
dass `docs/20 §4.5` unverändert bleibt. Ein bewohnbares Chroot je Abonnement
wäre Shell, Bibliotheken, `/dev/null` und `/etc/passwd` — je Abo zu unterhalten
und bei jedem Sicherheitsupdate nachzuziehen.

**Entscheidung 5 sagt: zwei Flächen zeigen denselben Ausschnitt.** Was der Kunde
per SFTP ohnehin sieht, sieht er auch im Dateimanager. Ein Panel, das weniger
zeigt als der Zugang daneben, wäre eine zweite Fassung derselben Regel — und die
zweite ist die, die veraltet. Was der Kunde nicht ändern darf, verhindern die
**Dateirechte** (`conf/` ist `root:root 0755`) und nicht eine Liste im Panel.

---

## 4. Das Abnahmekriterium

**Der Angriffsdurchgang.** Er läuft auf `cloudsrv24` gegen ein echtes
Abonnement, und er läuft **zweimal**: einmal gegen das gebaute Panel, und einmal
gegen ein Panel, dem die Schranke absichtlich genommen wurde.

> **Ein Angriff, der nicht trifft, misst den Angreifer und nicht die Abwehr.**
> `docs/50 §3` hat das zweimal am eigenen Leib gemessen: Zwei Angreifer meldeten
> null Treffer, und beide waren zu ungeschickt. Ein Angriffsdurchgang ohne
> Gegenprobe ist deshalb kein Beleg, sondern eine Vermutung mit Zahlen.

Erfüllt, wenn **alle zwölf** Punkte in der scharfen Fassung abgewiesen werden
**und** in der stumpfen Fassung durchkommen.

| # | Angriff | Erwartet scharf | Erwartet stumpf |
|---|---|---|---|
| 1 | `..` in jedem Pfadfeld jeder Datei-Operation | abgewiesen | Ausbruch |
| 2 | absoluter Pfad (`/etc/passwd`) in jedem Pfadfeld | abgewiesen | Ausbruch |
| 3 | Symlink auf `/etc/passwd` — lesen | leer/Fehler | `root:x:0:0` |
| 4 | Symlink auf `/etc` — auflisten | leer/Fehler | Verzeichnis von `/etc` |
| 5 | Symlink auf `/etc/shadow` — überschreiben | abgewiesen | Datei verändert |
| 6 | **Der Tausch während des Vorgangs** — der Angreifer aus `docs/50 §3`, 4 Prozesse, `RENAME_EXCHANGE`, gegen einen laufenden Baumlauf | **0 Treffer** | Treffer > 0 |
| 7 | Archiv mit `../`-Einträgen entpacken | nichts ausserhalb | Ausbruch |
| 8 | Archiv mit absolutem Pfad entpacken | nichts ausserhalb | Ausbruch |
| 9 | Cron: Zeilenumbruch im Befehl → zweite Zeile mit `root` | abgewiesen | Zeile in `/etc/cron.d` |
| 10 | Cron: `%` im Befehl (crontab macht daraus einen Zeilenumbruch) | unverändert ausgeführt | Befehl abgeschnitten |
| 11 | Mandantenübergriff: fremde Abo-Kennung in jeder Route | 403 | fremde Dateien |
| 12 | Quota voll: Schreiben schlägt fehl | Fehler am Vorgang | Erfolgsmeldung |

**Dazu drei Belege, die keine Angriffe sind, sondern Nachweise über den Vorgang
selbst** — sie fangen den Fall ab, dass alles abgewiesen wird, weil gar nichts
lief:

| # | Beleg | Erwartet |
|---|---|---|
| 13 | Jeder Datei-Vorgang meldet die `uid`, unter der er lief | die des Abos, nie 0 |
| 14 | Jeder Datei-Vorgang meldet seine Zusatzgruppen | nur die des Abos, nie 0 |
| 15 | Ein **gültiger** Vorgang derselben Art gelingt | Datei gelesen/geschrieben |

Punkt 15 ist der Nachbar der Nullen darüber.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## 5. Die Grenze

Das Herzstück. Eine Klasse im Agenten, `SrvPanel\Agent\Sandbox`, und **jede**
Datei-Operation von P6 geht durch sie.

```
Sandbox::run(string $root, string $user, callable $arbeit): mixed
    socketpair()                         der Rückweg, vor dem fork
    fork()                               Kind, als root
      chroot($root)                      die Grenze, gesetzt von root
      chdir('/')
      posix_initgroups($user, $gid)      sonst bleiben root-Gruppen stehen
      posix_setgid($gid)
      posix_setuid($uid)                 ab hier ist chroot nicht rücknehmbar
      $arbeit()                          gewöhnliche PHP-Dateifunktionen
      → Ergebnis durch das Socketpaar
    Elternprozess: warten, Zeitlimit, Ergebnis lesen
```

**Warum das die Ersetzung ist, nach der `docs/49 §2` verlangt.** Der
Dateimanager nimmt sehr wohl einen Pfad vom Kunden entgegen — aber er deutet ihn
**innerhalb eines Chroots**, und dort kann kein Pfad etwas ausserhalb
bezeichnen. Nicht `..`, nicht `/etc/passwd`, nicht ein Symlink dorthin, und auch
nicht ein Verzeichnis, das mitten im Vorgang durch einen Symlink ersetzt wird.
Die Grenze wird nicht *geprüft*, sie wird vom Kernel *gehalten*.

**Die sechs Eigenschaften, jede in `docs/50` einzeln gemessen:**

1. Sie hält das Rennen aus — 0 Ausbrüche gegen einen Angreifer, der die heutige
   Prüfung in 31 % der Fälle schlägt.
2. Sie braucht kein FFI, also keine Datei-Ein- und -Ausgabe als root.
3. Sie braucht **kein neues Programm auf der Positivliste**. `Runner.php`
   verbietet `setpriv`, `runuser`, `su` und `sudo` ausdrücklich und mit
   Begründung — `pcntl_fork` und `posix_*` laufen im Prozess und rühren die
   Liste nicht an.
4. Sie läuft als der Kunde, also gelten seine Rechte und seine Quota, ohne dass
   irgendetwas sie nachbauen müsste.
5. Der Kunde kann sie nicht zurücknehmen.
6. `conf/` ist damit ohne Zutun geschützt: `root:root 0755` heisst für den
   Abo-Benutzer lesen und nicht schreiben — Entscheidung 5 braucht keine Liste.

### 5.1 Die vier Fallen, die dabei zu erwarten sind

**a) Nach dem `chroot` findet der Autoloader nichts mehr.** `agent/src/…` liegt
ausserhalb; ein `require` im Kind schlägt fehl, und zwar **erst dann, wenn eine
Klasse zum ersten Mal gebraucht wird** — also nicht im Normalfall, sondern im
Fehlerfall. Deshalb: Alles, was das Kind braucht, wird **vor** dem `fork`
geladen. `SandboxPreloadTest` prüft, dass die Arbeitsfunktionen keine Klasse
anfassen, die dann nicht geladen ist.

**b) Der Rückweg muss vor dem `fork` stehen.** Das Kind kann nach dem `chroot`
keine Datei ausserhalb öffnen — auch keine für das Ergebnis. Ein Socketpaar,
angelegt vor dem `fork`, ist der einzige Weg zurück. Ein Ergebnis, das nicht
hineinpasst, blockiert; deshalb Grösse begrenzen und im Elternprozess mit
Zeitlimit lesen.

**c) Ein Kind, das stirbt, darf nicht als Erfolg gelten.** `pcntl_waitpid`
liefert den Status; ein Signal ist ein Fehlschlag und kein leeres Ergebnis.
Genau dieser Fehler steckte in `docs/48` — *„vermutlich Zeitüberschreitung"*
nach einer Sekunde Laufzeit, weil der Fehlerweg selbst fehlschlug.

> **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**

**d) `Filesystem::removeTree` bleibt aussen vor.** Es läuft als root und ohne
Chroot; `docs/50 §3` gilt für den Baumlauf weiter. P6 zieht ihn nach: Der
Rückbau eines Abonnements bekommt dieselbe Sandbox, und der Kommentar zum
Zeitfenster in `agent/src/Filesystem.php` wird durch die Messung ersetzt.

---

## 6. Datenmodell

| Tabelle | Inhalt |
|---|---|
| `ssh_keys` | Schlüssel je Abonnement: Typ, Fingerabdruck, Kommentar, angelegt von, zuletzt benutzt |
| `cron_jobs` | Abo, Zeitplan (fünf Felder einzeln), Befehl, aktiv, Kommentar, nächste Fälligkeit |
| `cron_runs` | Job, Beginn, Dauer, Rückgabewert, Ausgabe (gekappt), Kennzeichen „gekappt" |

`cron_runs` wird je Job auf **50** Zeilen beschnitten (Entscheidung 4), die
Ausgabe je Lauf auf **64 KB**. Beschnitten wird beim Einpflegen und nicht in
einem Tageslauf: Ein Job, der jede Minute läuft, soll die Tabelle nicht bis zum
nächsten Lauf des Aufräumers füllen dürfen.

**Die Spalte für die Ausgabe wird `mediumtext` und nicht `varchar`.** `docs/48`
hat gelernt, was eine zu kurze Spalte kostet: Die `PDOException` riss den
`catch`-Zweig mit, der den Fehlschlag festhalten sollte.

> **Ein Test, der gegen eine andere Datenbank läuft als der Server, prüft die
> Grenzen der falschen.** Diese Tests laufen gegen SQLite; die Kappung auf 64 KB
> gehört deshalb in den **Code** und nicht an die Spaltenbreite.

---

## 7. Die Agent-Operationen

Alle Datei-Operationen laufen durch `Sandbox` (§5). Sie nehmen einen Pfad — und
das ist ab hier zulässig, weil er im Chroot gedeutet wird.

| Operation | Tut |
|---|---|
| `files.list` | Verzeichnis auflisten: Name, Art, Grösse, Rechte, Zeit |
| `files.read` | Datei lesen, mit Obergrenze und Kodierungsprüfung |
| `files.write` | Datei schreiben |
| `files.mkdir` | Verzeichnis anlegen |
| `files.remove` | Datei oder Baum entfernen |
| `files.move` | Umbenennen und Verschieben |
| `files.copy` | Kopieren |
| `files.chmod` | Rechte setzen |
| `files.search` | Nach Name und Inhalt suchen |
| `files.extract` | Archiv entpacken |
| `files.compress` | Archiv erzeugen |
| `sftp.key.apply` | `authorized_keys` neu schreiben |
| `cron.apply` | Zeitplan eines Abos neu schreiben |
| `cron.runs` | Aufgezeichnete Läufe einsammeln |

**`files.read` prüft die Kodierung, bevor etwas zurückgeht.** `docs/46 §8` hat
gemessen, was eine ungültige UTF-8-Folge anrichtet: Sie macht über
`json_decode()` die **ganze Antwort** unlesbar, nicht nur die eine Zelle. Eine
Datei, die kein gültiges UTF-8 ist, wird als binär gemeldet und nicht im Editor
geöffnet.

**Was P6 der Positivliste hinzufügt: nichts.** Entpacken und Packen laufen über
PHPs `ZipArchive` und `PharData` **im Kind**, also ohne Rechte und im Chroot —
kein `unzip`, kein `tar`, keine neue Zeile in `Runner.php`.

---

## 8. Der Dateimanager

Sichtbereich ist die **Abo-Wurzel** (Entscheidung 5), also genau das Chroot.

- **Baum links, Liste rechts**, mobil untereinander. Die Liste ist eine Tabelle
  nach `docs/24 §5`, kein Kärtchenstapel: Man sucht *einen* Namen ab.
- **`docs/46 §20.13` gilt hier wörtlich.** Ein Dateiname darf 255 Zeichen lang
  sein. `td .ident { white-space: nowrap }` würde die Zeilentabelle beliebig
  breit machen — bei 390 px zehn Bildschirme Rollen durch eine Zelle, ohne dass
  die Überlaufmessung eine Zahl liefert.
  > **Eine Zelle, die rollen darf, hat keine Obergrenze — sie hat nur keine
  > Zahl, die sich beschwert.**
- **Und `docs/46 §20.11`:** Ein Bereichstitel, der einen Dateinamen trägt,
  braucht `overflow-wrap: anywhere` **mit** `min-width: 0`. Das ist die vierte
  Fassung derselben Ausnahme; `MobileLayoutTest` rechnet sie nach.
- **Hochladen** geht an eine Adresse, die den Strom direkt ins Kind schreibt —
  nicht erst nach `/tmp` und dann hinein. Eine volle Quota ist ein Fehler am
  Vorgang und keine Erfolgsmeldung (Punkt 12).
- **Entpacken** läuft als Vorgang über die Warteschlange, weil es dauert.
  Zip-Slip (`../` im Archiv) kann die Abo-Wurzel nicht verlassen — das Chroot
  hält —, wird aber trotzdem normalisiert: Innerhalb des Abos soll ein Archiv
  nicht in `.ssh/` schreiben, nur weil es das darf.

### 8.1 Der Editor

CodeMirror 6 (Entscheidung 1), und drei Auflagen dazu:

1. **Auf eine Seite begrenzt und nachgeladen.** Ein dynamischer Import, kein
   Eintrag im gemeinsamen Bündel — wer nie eine Datei bearbeitet, lädt nichts.
2. **Die Farben kommen aus `resources/css/app.css`.** Kein mitgeliefertes Theme.
   Das ist keine Förmlichkeit: Ein Hexwert in einer Komponente ist in diesem
   Projekt ein Fehler, und die CI prüft es.
3. **Ein Wächter über die Abhängigkeitsliste.** Bisher galt „keine
   Frontend-Abhängigkeit" als Selbstverständlichkeit und war deshalb von nichts
   geprüft. Sobald es **eine** gibt, ist die Liste eine Regel und braucht ihren
   Wächter: `FrontendDependencyTest` hält fest, welche zugelassen sind und mit
   welcher Begründung — sonst ist die zweite Abhängigkeit eine Frage der
   Gewohnheit und keine Entscheidung.

---

## 9. SFTP

`internal-sftp` mit `ChrootDirectory` je Abonnement, gemessen gegen OpenSSH 9.6
(`docs/50 §6`). Das Schema aus `docs/20 §4.5` bleibt unverändert.

- **Ein verwalteter Block in `sshd_config`**, nach dem Muster, das `docs/45`
  für `pg_hba.conf` gebaut hat — mit der Lehre daraus:
  > **Ein zweiter Schreiber in derselben Datei ist kein zweiter Schreiber,
  > solange nur einer die Sperre nimmt.**
  Und der zweiten:
  > **Eine Sperre, die man zweimal nimmt, ist ein Stillstand ohne
  > Fehlermeldung.** `flock` sperrt je *offener Datei*, nicht je Prozess.
- **Vor dem Neuladen wird geprüft** (`sshd -t`). Ein `sshd`, das nicht mehr
  startet, ist der Fehler, der einen Server unerreichbar macht — `docs/44` hat
  das für MariaDB erlebt und das Panel dabei abgeschaltet.
- **Die Ablehnung wird sichtbar gemacht.** Der Client bekommt nur `Broken pipe`;
  der Grund steht ausschliesslich im Serverprotokoll als `bad ownership or modes
  for chroot directory`. Das Panel prüft Besitz und Rechte der ganzen Kette
  oberhalb der Wurzel selbst und sagt, welches Verzeichnis klemmt.
- **Schlüsselverwaltung**: `authorized_keys` wird vom Agenten geschrieben, nie
  vom Kunden zusammengesetzt. Der öffentliche Schlüssel wird geprüft, bevor er
  in die Datei kommt; Fingerabdruck und Typ zeigt das Panel.
- **Kein Passwort.** Der Systembenutzer hat keins und bekommt keins.

---

## 10. Cron

**`/etc/cron.d/srvpanel-<benutzer>`, eine Datei je Abonnement, `root:root
0644`** — nicht `crontab -u`. Der Agent besitzt die ganze Datei und schreibt sie
bei jeder Änderung neu; sie ist damit dasselbe wie ein verwalteter Block, nur
ohne fremden Inhalt drumherum.

### 10.1 Die Einschleusung, gegen die Punkt 9 und 10 antreten

Ein Cronjob **ist** die Erlaubnis, einen Befehl auszuführen. Der Angriff ist
also nicht „der Kunde führt einen Befehl aus" — das ist die Funktion. Der
Angriff ist, dass er ihn **als jemand anderes** ausführt. Eine Zeile in
`/etc/cron.d` hat ein Benutzerfeld:

```
* * * * *  p1001  <befehl>
```

Ein Zeilenumbruch im Befehl macht daraus zwei Zeilen, und die zweite darf sich
ihr Benutzerfeld selbst aussuchen — `root`. Das ist Punkt 9.

**Deshalb steht in der Cron-Datei kein einziges Zeichen vom Kunden:**

```
* * * * *  p1001  /usr/lib/srvpanel/cron-run 1234
```

Der Befehl liegt unter `/etc/srvpanel/cron/1234.cmd` (`root:root 0640`), und
`cron-run` übergibt ihn als **Argument** an die Shell, nicht als Text in einer
Zeile. Ein Zeilenumbruch darin ist dann ein Zeichen wie jedes andere.

**Punkt 10 ist der Fund, den man nur beim Nachlesen von `crontab(5)` macht:**
Ein nicht maskiertes `%` wird im Befehlsteil zu einem Zeilenumbruch, und alles
dahinter wird der Standardeingabe zugeschoben. Ein Kunde mit `date +%Y` im
Cronjob bekäme sonst wortlos einen abgeschnittenen Befehl. Da kein Kundentext in
der Datei landet, ist auch das erledigt — aber es wird **gemessen** und nicht
angenommen, weil das genau die Art Annahme ist, die `docs/44` teuer gemacht hat.

> **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit Fussnote.**

### 10.2 Die Aufzeichnung

`cron-run` läuft als der Abo-Benutzer, nimmt Rückgabewert, Dauer, Standardausgabe
und Standardfehler auf, kappt bei **64 KB** und legt das Ergebnis in ein
Spool-Verzeichnis, das dem Abonnement gehört. Der Agent sammelt es ein und
beschneidet auf **50** Läufe je Job (Entscheidung 4). `MAILTO=""` steht im Kopf
der Datei, damit cron nichts zu verschicken versucht.

**Gekappte Ausgabe wird als gekappt gekennzeichnet.** Eine Anzeige, die eine
abgeschnittene Ausgabe wie eine vollständige aussehen lässt, behauptet etwas,
das sie nicht weiss — derselbe Satz wie in `docs/48` über `a\tb` und `a b`.

### 10.3 Der Zeitplan

Fünf Felder, einzeln erfasst, mit **lesbarer Übersetzung** darunter („jeden Tag
um 03:15"). Die Übersetzung geht über `App\Support\Time\Clock` — das Panel zeigt
Zeiten in der eingestellten Anzeigezeitzone (`docs/40`), cron rechnet in der
Systemzeit. Wer das verwechselt, zeigt eine Zeile und findet sie nicht.

---

## 11. Die Wächter

Für jede Regel einer, und jeder wird gegengeprüft. Neu in P6:

| Wächter | Regel |
|---|---|
| `SandboxReachTest` | Jede Datei-Operation geht durch `Sandbox` — keine fasst `file_*` direkt an |
| `SandboxPreloadTest` | Das Kind fasst keine Klasse an, die nach dem `chroot` nicht geladen ist |
| `PrivilegeDropTest` | Vor jedem `setuid` steht ein `initgroups`; die uid ist nie 0 |
| `CronLineTest` | In eine Cron-Zeile kommt kein Kundentext — geprüft an der erzeugten Zeichenkette |
| `FrontendDependencyTest` | Welche Frontend-Abhängigkeit zugelassen ist, steht mit Begründung |
| `ManagedBlockTest` | Wer in eine fremde Datei schreibt, nimmt die Sperre — und nur einmal |

Dazu wachsen mit: `AgentOperationReachTest`, `RouteAuthorizationTest`,
`AbilityReachTest`, `MobileLayoutTest`, `ClassReachTest`, `WordChoiceTest`,
`FieldErrorTest`, `PaginationTest`.

**Und die Brüche gehören in `tests/waechter-brechen.sh`**, mit der Lehre vom
13. August:

> **Ein Bruch muss die Regel verletzen und nicht den Code zerstören.** Ein
> Testfall, der abbricht, meldet „übersprungen" statt „rot" — und der Wächter
> war nie rot, er ist gar nicht erst dazu gekommen.

---

## 12. Die Schritte

| # | Schritt | Ergebnis |
|---|---|---|
| 0 | Messrunde | **gefahren**, `docs/50` |
| 1 | `Sandbox` im Agenten, mit Wächtern und Brüchen | die Grenze steht |
| 2 | `Filesystem::removeTree` zieht nach | der Altbestand hängt nicht mehr am Zeitfenster |
| 3 | Datei-Operationen (`files.*`) | der Agent kann, was P6 braucht |
| 4 | Datenmodell, Dienstschicht, Policies | Panel-Seite, alle drei Ebenen |
| 5 | Dateimanager ohne Editor | Baum, Liste, Hochladen, Rechte |
| 6 | Editor (CodeMirror 6) | Entscheidung 1, mit ihren drei Auflagen |
| 7 | Entpacken, Packen, Suche | über die Warteschlange |
| 8 | SFTP: Block, Schlüssel, Prüfung der Kette | Zugang steht |
| 9 | Cron: Datei, Wrapper, Aufzeichnung, Zeitplan | Zeitsteuerung steht |
| **6b** | **Zwischenabnahme auf `cloudsrv24`** | **vorgezogen — `docs/52`** |
| 10 | ~~Zwischenabnahme~~ | vorgezogen auf 6b |
| 11 | **Der Angriffsdurchgang**, scharf und stumpf | §4 |
| 12 | Bilderrunde: beide Themes, 390 px, mit Messung | `docs/49 §6` Punkt 2 |

**Die Zwischenabnahme ist am 14. August von Schritt 10 auf 6b vorgezogen
worden**, und zwar aus einem benannten Grund: Die Sandbox steht auf vierzehn
PHP-Funktionen, deren Vorhandensein auf den Zielplattformen ungemessen ist
(`docs/50 §8`) — gemessen sind sie auf Ubuntu 24.04 mit PHP 8.4, dem
Entwicklungscontainer. Fällt eine davon aus, fällt die Grenze aus und nicht ein
Detail; die Schritte 7 bis 9 stapeln sich alle darauf. Der Lauf steht in
`docs/52`, sein Kern ist `tests/sandbox-messen.php`.

> **Drei Schritte auf einer ungeprüften Annahme zu bauen ist teurer, als sie
> einmal zu prüfen.**

Schritt 11 ist das Abnahmekriterium und kommt **vor** der Freigabe, nicht
danach. Schritt 12 wird nicht abgehakt, wenn er gerade nicht geht — `docs/49 §6`
hält fest, was das einmal gekostet hat.

---

## 13. Was P6 ausdrücklich nicht wird

- **Kein FTP** (Entscheidung 2) — der Punkt aus `docs/20 §9` entfällt.
- **Kein SSH mit Shell** (Entscheidung 3) — das Chroot bleibt leer.
- **Kein FFI.** `docs/50 §4` hat gemessen, dass es den Vorgang nicht schützt,
  sondern nur den Systemaufruf.
- **Kein neues Programm auf der Positivliste.** Weder `setpriv` noch `unzip`
  noch `tar`.
- **Keine Versionierung von Dateien**, kein Papierkorb, keine Freigabe-Links.
- **Kein Zugriff auf fremde Abonnements für Admins über diese Fläche.** Ein
  Admin, der die Dateien eines Kunden sehen will, meldet sich als Kunde an
  (`docs/20 §6.3`) — ein zweiter Weg wäre eine zweite Policy.

---

## 14. Die Risiken

1. **Die Sandbox trifft jede Datei-Operation.** Ein Fehler darin ist kein Fehler
   an einer Stelle. Deshalb steht sie als Schritt 1 allein und bekommt ihre
   Wächter, bevor die erste Operation sie benutzt.
2. **`pcntl_fork` im Agenten ist neu.** Der Agent bedient einen Socket; ein Kind,
   das den geerbten Deskriptor offen hält, hängt die Verbindung. Vor dem
   `chroot` wird geschlossen, was das Kind nicht braucht.
3. **Die 31 % aus `docs/50` sind ein Wert dieser Maschine.** Auf `cloudsrv24`
   kann Punkt 6 des Angriffsdurchgangs in der stumpfen Fassung seltener treffen.
   Trifft er dort **gar nicht**, ist nicht die Abwehr belegt, sondern der
   Angreifer zu langsam — dann wird das Fenster künstlich geweitet, bis die
   stumpfe Fassung trifft, und erst dann zählt die scharfe.
4. **CodeMirror ist die erste Frontend-Abhängigkeit.** Sie bringt eine
   Lieferkette mit, die es hier bisher nicht gab. Der CI-Lauf „Lieferkette" aus
   P0 muss sie erfassen.
5. **`sshd_config` ist die Datei, deren Beschädigung aussperrt.** `docs/32`
   nennt die Falle für TLS; hier ist sie schärfer, weil der Weg zurück über
   dieselbe Tür führt. `sshd -t` vor jedem Neuladen, und der verwaltete Block
   bleibt am Ende der Datei.
6. **Die Anzeigezeitzone und cron rechnen verschieden** (§10.3).

---

## 15. Was offen bleibt und nicht zu P6 gehört

- Zwei Punkte aus `docs/42 §5`, nie gemessen: der `template1`-Beleg und die
  Frage, ob ein Datenbankzugang ohne jede Datenbank entstehen kann.
- Der P5c-Bestand auf `cloudsrv24`: Abo 1130 mit `gross` (60 Mio. Zeilen) —
  zurückbauen, sobald die Systemmarke in der Konsole gegen `rc.15` gesehen wurde.
- Zwei Ergänzungen an der P5c-Fixture, die im Bestand stehen und nicht im Plan:
  `probe.luecke` und `lang.notiz`.
- Ob `openat2` auf den vier Zielplattformen durchgelassen wird (`docs/50 §8`).
  Nachrangig, solange P6 es nicht benutzt — aber die Frage ist gestellt und
  nicht beantwortet.
