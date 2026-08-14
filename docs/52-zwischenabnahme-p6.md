# 52 — Die Zwischenabnahme von P6

**Der Lauf für `cloudsrv24`, nach Schritt 6 und vor Schritt 7.** Geschrieben am
14. August 2026. Er prüft die Grenze aus `docs/51 §5` auf einem echten Server —
und nicht die Oberfläche, die es dafür noch nicht vollständig gibt.

Er steht wie `docs/39`, `docs/43` und `docs/47` als eigenes Dokument da, weil er
zweimal gebraucht wird: einmal beim Fahren und einmal beim Nachlesen, warum
etwas so entschieden wurde.

---

## 1. Warum er vorgezogen ist

`docs/51 §12` sieht die Zwischenabnahme als Schritt 10 vor, also nach Entpacken,
SFTP und Cron. **Das ist zu spät**, und der Grund ist keine Vorsicht, sondern
eine benannte Lücke:

**Die Sandbox steht auf vierzehn PHP-Funktionen, deren Vorhandensein auf den
Zielplattformen ungemessen ist.** `pcntl_fork`, `posix_initgroups`,
`posix_setgid`, `posix_setuid`, `chroot` und neun weitere. Gemessen sind sie auf
**Ubuntu 24.04 mit PHP 8.4** — dem Entwicklungscontainer. Debian 12 fährt PHP
8.2, Ubuntu 22.04 PHP 8.1, und ein `disable_functions` in einer distro-`php.ini`
ist keine exotische Annahme. `docs/50 §8` führt das als offen.

Fällt eine davon aus, fällt **die Grenze** aus und nicht ein Detail. Die
Schritte 7 bis 9 stapeln sich alle darauf.

> **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit Fussnote.**
> (`docs/44`)

**Dazu kommt der Rückbau.** `subscription.remove` und `web.site.remove` sind
verändert (`Filesystem::purgeContents`), und sie laufen auf `cloudsrv24` gegen
echte Abonnements. Das ist der eine Weg in P6, bei dem ein Fehler Daten kostet
statt eine Fehlermeldung zu erzeugen.

---

## 2. Was er ausdrücklich nicht prüft

- **Die Oberfläche.** Der Dateimanager ist gebaut, die Bilderrunde dazu steht
  aus; sie braucht `artisan serve` und damit `vendor/`, und das sperrt der Proxy
  im Entwicklungscontainer. Auf dem Zielserver ist das Panel installiert, also
  ist ein Blick möglich — er ist hier als Punkt 8 aufgeführt und bleibt
  ausdrücklich **kein Abnahmekriterium** dieses Laufs.
- **Den Angriffsdurchgang.** Der ist Schritt 11 und braucht Entpacken und Cron,
  die es noch nicht gibt. Was hier läuft, ist seine Grundlage.
- **SFTP und Cron.** Nicht gebaut.

`docs/47` ist mit sechzehn Punkten **ohne ein einziges Bild** gefahren, weil die
Oberfläche noch nicht existierte. Das ist vertretbar, solange es benannt offen
bleibt und nicht als erledigt gilt.

---

## 3. Die Fassung, gegen die gefahren wird

| Sache | Wert |
|---|---|
| Zweig | `claude/p6-dateien-zugaenge-cron-efbuvy` |
| Stand | nach Schritt 6 (Editor) |
| Server | `cloudsrv24` |
| Erwartet | Debian/Ubuntu wie installiert, PHP der Distribution |

**Vor dem Lauf**: `srvpanel update` auf diesen Stand, und `srvpanel --version`
zeigt ihn. Eine Fassungsprüfung, die in der falschen Datei sucht, hat in
`docs/47` einen halben Lauf gekostet.

---

## 4. Der Lauf

### Punkt 1 — die Grenze, gemessen statt gelesen

**Das Skript liegt nicht auf dem Server, und das soll so bleiben.**
`packaging/build.sh` führt eine Positivliste — `agent app bootstrap config
database public resources/views routes vendor …` —, und `tests/` steht nicht
darin. Ein Skript, das Benutzer anlegt, chrootet und das Dateisystem im Rennen
belastet, gehört nicht auf jeden Kundenserver.

Geholt wird es für den Lauf, und zwar **neben** den Agenten: Es bindet
`../agent/src/autoload.php` ein, und das ist der einzige Pfad, auf den es
angewiesen ist.

```bash
sudo mkdir -p /opt/srvpanel/current/tests
sudo curl -fsSL -o /opt/srvpanel/current/tests/sandbox-messen.php \
  https://raw.githubusercontent.com/philf90/Server-Control-Panel/<tag>/tests/sandbox-messen.php

sudo php /opt/srvpanel/current/tests/sandbox-messen.php
```

`/opt/srvpanel/current` ist ein Verweis auf das Fassungsverzeichnis; die Datei
liegt danach dort und geht beim nächsten Update mit. Wer sie vorher los sein
will, löscht das Verzeichnis wieder.

**Das ist der Kern dieses Laufs.** Das Skript ist framework- und PHPUnit-frei;
es braucht root, weil `chroot` und `setuid` root brauchen, und läuft deshalb in
der CI nicht.

Es misst in sieben Abschnitten, **jeder mit seiner Gegenprobe**:

| # | Abschnitt | Erwartet |
|---|---|---|
| 1 | Vorbedingung: vierzehn Funktionen, root | alle da |
| 2 | Wegwerf-Abonnement nach §4.5 | angelegt |
| 3 | Der Vorgang läuft ohne Rechte | `uid ≠ 0`, keine Gruppe 0, gültige Datei gelesen |
| 4 | Symlink, `..`, absoluter Pfad, `conf/` | scharf hält, stumpf trifft |
| 5 | Der Tausch während des Zugriffs | scharf 0, stumpf > 0 |
| 6 | Der Rückbau gegen den Tausch | scharf 0, stumpf > 0 |
| 7 | Die Wurzel selbst | dreimal abgewiesen |

**Rückgabewerte, und sie sind nicht dasselbe:**

| Wert | Bedeutung |
|---|---|
| `0` | Die Grenze hält — gemessen und gegengeprobt. |
| `1` | **Befund.** Die Grenze hält auf dieser Maschine nicht. |
| `2` | Vorbedingung fehlt (Funktion oder root). Der Rest misst nichts. |
| `3` | Kein Befund, aber mindestens eine Messung **ohne** Gegenprobe. |

**`3` ist kein Erfolg**, und das ist der Punkt, an dem dieses Skript sich von
den meisten unterscheidet. Trifft die stumpfe Fassung nicht, ist nicht die
Abwehr belegt — dann war der Angreifer zu langsam.

> **Ein Angriff, der nicht trifft, misst den Angreifer und nicht die Abwehr.**

Im Container gemessen (Ubuntu 24.04, PHP 8.4.19, Kernel 6.18): scharf 0, stumpf
**759 von 30 000** beim Zugriff und **1 nach 68 Durchgängen** beim Rückbau.
Erwartet wird auf `cloudsrv24` dieselbe Richtung und **nicht dieselbe Zahl** —
das Rennen hängt an Kernen, Last und Dateisystem.

**Trifft die Gegenprobe dort gar nicht**, ist das ein eigener Befund und kein
Erfolg: Dann wird das Fenster künstlich geweitet, bis sie trifft, und erst
danach zählt die scharfe Fassung.

### Punkt 2 — die Plattform selbst

Was `docs/50 §8` offen gelassen hat, und was Punkt 1 nur für *diesen* Server
beantwortet:

```bash
php -v; php -i | grep -iE '^disable_functions'
php -r 'foreach (["pcntl_fork","posix_initgroups","posix_setgid","posix_setuid","chroot"] as $f) printf("%-20s %s\n", $f, function_exists($f) ? "da" : "FEHLT");'
```

**Gefragt wird die PHP-Fassung des Agenten**, nicht irgendeine — der Agent läuft
unter der CLI-PHP der Distribution.

### Zwei Fallen in `tinker`, die diesen Lauf stumm scheitern lassen

**Beide sind bekannt, und beide standen hier trotzdem falsch** — gefunden beim
Fahren von Punkt 3 am 14. August 2026. Sie stehen wörtlich in `docs/47 §2` und
in `docs/48 §3.8`:

1. **`HOME=/tmp` davor.** Der Wrapper setzt per `setpriv` auf den Benutzer
   `srvpanel` um, `HOME` bleibt auf `/root`, und psysh scheitert am Anlegen von
   `.config/psysh` mit einer blossen `User Notice` — dann läuft der Code gar
   nicht erst.
2. **`Tenancy::allowAll()` als erste Zeile.** `Subscription` trägt die Klammer
   auf den eigenen Schlüssel; ohne sie ist `Subscription::first()` **`null`**,
   und der nächste Aufruf stirbt an einer Methode auf `null` statt an der Sache.

> **Eine Falle, die in zwei Protokollen steht, steht deshalb noch in keinem
> Lauf.** Beide Sätze waren aufgeschrieben, beide waren gelesen, und beide sind
> hier wieder passiert — weil das Aufschreiben in `docs/47` und das Schreiben
> von `docs/52` zwei verschiedene Handgriffe sind.

### Punkt 3 — die acht Datei-Operationen an einem echten Abonnement

Gegen ein bestehendes Abo (nicht gegen ein neues: der Bestand ist der Prüfling).

```bash
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->allowAll();
  $abo = App\Models\Subscription::first();
  echo "abo=", $abo->name, " user=", $abo->system_user, "\n";
  $f = app(App\Support\Files\Files::class);
  print_r(array_column($f->list($abo, "/")["entries"], "name"));
  print_r(array_column($f->list($abo, "/httpdocs")["entries"], "name"));
  print_r($f->write($abo, "/httpdocs/p6-probe.txt", "Zeile\n"));
  var_dump($f->read($abo, "/httpdocs/p6-probe.txt")["content"]);
  print_r($f->makeDirectory($abo, "/httpdocs/p6-ordner"));
  print_r($f->chmod($abo, "/httpdocs/p6-probe.txt", 0600));
  print_r($f->copy($abo, "/httpdocs/p6-probe.txt", "/httpdocs/p6-kopie.txt"));
  print_r($f->move($abo, "/httpdocs/p6-kopie.txt", "/httpdocs/p6-verschoben.txt"));
'
```

**Hier anhalten.** Dann in einer zweiten Shell `ls -ln` (siehe unten), und erst
danach der Rückbau — mit der Auflistung als Gegenprobe:

```bash
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->allowAll();
  $abo = App\Models\Subscription::first();
  $f = app(App\Support\Files\Files::class);
  print_r($f->remove($abo, "/httpdocs/p6-verschoben.txt"));
  print_r($f->remove($abo, "/httpdocs/p6-ordner", true));
  print_r($f->remove($abo, "/httpdocs/p6-probe.txt"));
  print_r(array_column($f->list($abo, "/httpdocs")["entries"], "name"));
'
```

**Die letzte Zeile ist die Gegenprobe und nicht Zierde.** `remove` meldet Erfolg
auch dann glaubhaft, wenn nichts geschehen ist — genau der Fehler, den
`purgeContents` in dieser Stufe schon gemacht hat (meldete vier entfernt, alle
vier lagen noch da).

**Erwartet**: alle acht gelingen, und die angelegte Datei gehört dem
Systembenutzer des Abonnements — nicht root.

```bash
ls -ln /var/www/vhosts/<abo>/httpdocs/            # zwischen write und remove
```

**Das `-n` ist Absicht**: gefragt sind die Zahlen und nicht die Namen. Ein
`uid=0`, dessen Name zufällig danebensteht, rutscht sonst durch. Und ein
`id <benutzer>` daneben, weil eine Zahl allein niemandem gehört:

```bash
id <benutzer>; ls -ln /var/www/vhosts/<abo>/httpdocs/
```

> Ein Vorgang, der als root schreibt, meldet Erfolg genauso.

**Und `0600` beim `chmod` ist Absicht.** Hier stand `0644` — genau die Rechte,
die `files.write` beim Anlegen ohnehin setzt. Der Aufruf hätte den Zustand
gesetzt, in dem die Datei schon war, hätte Erfolg gemeldet und **nichts
belegt**. Gemessen wird ein `chmod` nur an einem Wert, den vorher niemand
hatte, und mit der Rechteangabe vorher und nachher daneben.

> **Ein Griff, der den Zustand herstellt, in dem die Sache schon ist, meldet
> Erfolg und misst nichts.**

### Punkt 4 — was scheitern muss

```bash
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->allowAll();
  $abo = App\Models\Subscription::first();
  $f = app(App\Support\Files\Files::class);
  foreach ([
    ["read",   "/../../../../etc/passwd"],
    ["read",   "/etc/passwd"],
    ["remove", "/"],
  ] as [$m, $p]) {
    try { print_r($f->$m($abo, $p)); echo "DURCHGELASSEN: $m $p\n"; }
    catch (Throwable $e) { echo "abgewiesen: $m $p — ", $e->getMessage(), "\n"; }
  }
  try { print_r($f->write($abo, "/conf/gekapert.conf", "x")); echo "DURCHGELASSEN: write /conf\n"; }
  catch (Throwable $e) { echo "abgewiesen: write /conf — ", $e->getMessage(), "\n"; }
'
```

**Jede Zeile nennt den Grund.** Ein `denied` ohne Satz wäre von einem `denied`
aus einem ganz anderen Anlass nicht zu unterscheiden.

Und der Symlink, von Hand gelegt, weil der Kunde ihn per SFTP legen könnte:

```bash
ln -s /etc/passwd /var/www/vhosts/<abo>/httpdocs/raus
chown -h <benutzer>:<benutzer> /var/www/vhosts/<abo>/httpdocs/raus

HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->allowAll();
  $abo = App\Models\Subscription::first();
  $f = app(App\Support\Files\Files::class);
  try { print_r($f->read($abo, "/httpdocs/raus")); echo "DURCHGELASSEN\n"; }
  catch (Throwable $e) { echo "abgewiesen — ", $e->getMessage(), "\n"; }
'
```

Das `-h` an `chown` ist nötig: Ohne es ändert `chown` das **Ziel** des Verweises
— also `/etc/passwd`.

**Gegenprobe dazu, und sie gehört dazu**: Dieselbe Datei ausserhalb der Sandbox
lesen — `cat /var/www/vhosts/<abo>/httpdocs/raus` zeigt `/etc/passwd`. Ohne sie
wäre die Abweisung darüber kein Beleg, sondern vielleicht ein Tippfehler.

### Punkt 5 — der Upload

```bash
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->allowAll();
  $abo = App\Models\Subscription::first();
  $f = app(App\Support\Files\Files::class);
  print_r($f->upload($abo, "/var/lib/srvpanel/storage/app/private/uploads/<datei>", "/httpdocs/hoch.bin"));
  try { print_r($f->upload($abo, "/etc/shadow", "/httpdocs/geklaut")); echo "DURCHGELASSEN\n"; }
  catch (Throwable $e) { echo "abgewiesen — ", $e->getMessage(), "\n"; }
'
```

Eine Datei von mindestens 50 MB, damit der Strom wirklich ein Strom ist. Die
Grösse am Ziel muss stimmen — nicht nur die Existenz.

### Punkt 6 — der Rückbau an einem echten Abonnement

**Der Punkt, bei dem ein Fehler Daten kostet.** Ein Wegwerf-Abo anlegen, füllen,
zurückbauen:

```bash
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->allowAll();
  # Abo anlegen, ein paar Verzeichnisse und Dateien hineinlegen
  # dann: zurückbauen
'
```

**Erwartet**: `/var/www/vhosts/<abo>` ist weg, der Systembenutzer ist weg, die
Gruppe ist weg — und **nichts ausserhalb ist angefasst**. Vorher und nachher:

```bash
find /var/www/vhosts -maxdepth 1 | sort > /tmp/vorher.txt
# … Rückbau …
find /var/www/vhosts -maxdepth 1 | sort > /tmp/nachher.txt
diff /tmp/vorher.txt /tmp/nachher.txt      # genau eine Zeile weniger
```

### Punkt 7 — der P5c-Bestand

`docs/49 §8`: Abo 1130 mit `gross` (60 Mio. Zeilen) steht noch auf dem Server.
Er ist hier **kein Aufräumauftrag, sondern ein Prüfling**: ein Abonnement mit
echtem Inhalt, an dem sich Punkt 3 und 4 messen lassen. Zurückgebaut wird er,
wenn die Systemmarke in der Konsole gegen `rc.15` gesehen wurde — und nicht als
Nebenwirkung dieses Laufs.

### Punkt 8 — ein Blick, kein Kriterium

Das Panel ist auf dem Server installiert, also ist die Fläche erreichbar.
Angesehen wird der Dateimanager in beiden Themes und bei 390 px, mit
`scrollWidth - clientWidth` daneben.

**Das ersetzt die Bilderrunde aus Schritt 12 nicht** und zählt hier nicht als
erfüllt oder nicht erfüllt. Was auffällt, wird notiert; was nicht, wird nicht
behauptet.

Der Editor ist dabei der interessante Teil: **CodeMirror ist bis heute in keinem
Browser gelaufen.** Gemessen und gesehen wurde bisher nur der Rückfall auf das
`textarea`.

---

## 5. Wie das Protokoll entsteht

**Während des Laufs und nicht danach.** Jeder Punkt bekommt seine Zeile,
sobald er gefahren ist — mit dem gemessenen Wert und nicht mit „ok".

Und die Erwartung aus vier Läufen: **Die Mehrheit der Befunde wird diesen Lauf
selbst betreffen und nicht den Prüfling.** In `docs/45`, `47` und `48` war es
jedes Mal etwa die Hälfte bis zwei Drittel.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

Zwei Fehler in genau dieser Bauart sind schon beim Schreiben von
`tests/sandbox-messen.php` aufgetreten und stehen dort als Kommentar:

- Eine Gegenprobe, die den **falschen Pfad** benutzte und deshalb von Bauart
  wegen nicht treffen konnte — gemeldet hat es das Skript selbst, weil es eine
  fehlende Gegenprobe nicht als Erfolg zählt.
- Eine feste Rundenzahl für einen **seltenen** Treffer: mal vier Treffer, mal
  keiner. Die Gegenprobe läuft jetzt, bis sie trifft, und die scharfe Fassung
  bekommt danach mindestens ebenso viele Durchgänge.

---

## 6. Was danach kommt

Hält die Grenze auf `cloudsrv24`, gehen die Schritte 7 bis 9 weiter
(Entpacken/Suche, SFTP, Cron) und danach der Angriffsdurchgang aus
`docs/51 §4`.

Hält sie nicht, ist das kein Detailfehler: Dann ist die Bauform aus `docs/50 §9`
auf dieser Plattform nicht tragfähig, und der Plan geht zurück auf die Frage,
die `docs/49 §2` gestellt hat — womit der weggefallene Schutz ersetzt wird.
