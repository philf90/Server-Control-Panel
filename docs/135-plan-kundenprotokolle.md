# Der Kunde bekommt seine gedrehten Protokolle zurück — der Plan

Geschrieben am 27. September 2026, **nach** der Messrunde und auf Entscheidung
des Betreibers, Weg 1 zu prüfen. Der Anlass ist **Befund 13 aus `docs/134 §7`**:
Der Kunde kann sein laufendes Zugriffsprotokoll lesen, seine gedrehten
(`access.log.1`, `.2.gz` …) aber nicht.

## §1 · Der Befund, noch einmal gemessen

Auf `cloudsrv24` am 27. September, als der Kunde `p1136`:

```
access.log     p1136:adm     lesbar
access.log.1   www-data:adm  Permission denied
```

`p1136` gehört allein seiner eigenen Gruppe, nicht `adm`. Die Rechte sind
`0640`, es gibt kein Weltlesebit — also entscheidet die Gruppe, und die ist bei
der gedrehten Datei `adm` unter einem fremden Eigentümer.

**Die Ursache steht in der Reihenfolge einer einzigen Nacht.** logrotate fährt
alle Konfigurationen unter `/etc/logrotate.d` in der Reihenfolge der Dateinamen;
`nginx` (aus `nginx-common`) kommt vor `srvpanel-*`. Dessen `postrotate`
schickt dem Master ein `USR1`, und der lässt alle Arbeiter **alle** offenen
Protokolle neu öffnen — auch die des Abonnements, die zu diesem Zeitpunkt noch
`access.log` heissen und noch nicht gedreht sind. Der Master (root) chownt sie
dabei auf `www-data`. Erst danach benennt unsere Strophe `access.log` zu
`access.log.1` um. Die gedrehte Datei trägt deshalb `www-data:adm`, und
`create 0640 <benutzer> adm` gilt nur für die **neue** Datei.

Gemessen im Nachbau unter der echten `logrotate.service`:

| | die gedrehte `.1` |
|---|---|
| mit der `nginx`-Strophe davor | `www-data:adm` |
| Gegenprobe: ohne sie | behält ihren Eigentümer |

## §2 · Die Entscheidung des Betreibers (25. September, bekräftigt am 27.)

Zwei andere Wege sind ausdrücklich **nicht** gangbar:

- **Die Protokollverzeichnisse für `www-data` öffnen** — verworfen am
  25. September: Die Arbeiter von nginx läsen dann die Protokolle jedes
  Abonnements.
- **Das `USR1` der `nginx`-Strophe durch ein Neuladen ersetzen** — das hiesse,
  die Konfigurationsdatei eines fremden Pakets (`/etc/logrotate.d/nginx`,
  `nginx-common`) zu ändern. dpkg fragt dann bei jedem nginx-Update nach, und
  ein `dpkg-divert` auf eine fremde Conffile ist ein eigener Wartungsfall.

**Weg 1 ist die Rückgabe:** Unsere Strophe läuft ohnehin nach der von nginx.
Ihr `postrotate` gibt die frisch gedrehte `.1` an den Eigentümer ihres
Verzeichnisses zurück — genau das, was das `USR1` gerade umgeschrieben hat.

## §3 · Der Kern, im Container gemessen

Die Rückgabe ist ein Shell-Fragment im `postrotate`-Abschnitt der Vorlage. Vier
Messungen haben seine Form entschieden.

**1 · Der `postrotate`-Abschnitt läuft unter `/bin/sh`, nicht bash.** Der erste
Entwurf mit `find … | while read -r -d ''` starb an `read: Illegal option -d`,
und die Rückgabe lief gar nicht — die `.1` blieb `www-data`. Das ist die
teuerste Erkenntnis dieser Runde, weil sie still gescheitert wäre.

> **Ein `postrotate`-Abschnitt ist ein `sh`-Skript — jeder Bashismus darin
> scheitert erst in der Nacht, in der er läuft.**

**2 · `chown -h` ist zwingend, und `-type`/`[ ! -L ]` schliesst den Verweis
aus.** Der Kunde besitzt sein `logs`-Verzeichnis (`2750 %u:adm`) und darf darin
Verweise anlegen. Gemessen:

- `chown -h` auf einen vom Kunden gelegten Symlink `boese.log.1 → /root/…`:
  das Ziel bleibt unberührt.
- **Ohne `-h` folgt `chown` dem Verweis und schreibt das Ziel um — auch bei
  `fs.protected_symlinks=1`** (gemessen: das Ziel wurde `nobody:adm`).
  `protected_symlinks` greift nur in einem für alle beschreibbaren
  Klebeverzeichnis wie `/tmp`, nicht in einem Kundenverzeichnis.
- Ein Hardlink auf ein Ziel, das der Kunde nicht lesen darf, lässt sich gar
  nicht erst anlegen (`fs.protected_hardlinks=1`, „Operation not permitted").

**3 · Der `www-data`-Vorbehalt macht es chirurgisch.** Zurückgegeben wird nur,
was **jetzt** `www-data` gehört — also genau das, was das `USR1` umgeschrieben
hat. Ein vom Kunden angelegter Köder gehört nie `www-data` (er kann nicht nach
`www-data` chownen), und die genuine gedrehte Datei tut es immer. Gemessen: ein
Köder `koeder.log.1`, dem Kunden selbst gehörend, bleibt unberührt; die echte
`www-data:adm`-Datei wird zurückgegeben.

**4 · Die dash-taugliche Fassung trägt**, `rc=0`, `.1` von `www-data:adm` auf
den Kunden, danach für ihn lesbar:

```sh
for f in "$ROOT"/logs/*.log.1 "$ROOT"/logs/*/*.log.1; do
    [ -f "$f" ] && [ ! -L "$f" ] || continue
    [ "$(stat -c %U "$f")" = "www-data" ] || continue
    chown -h "$(stat -c %U "$(dirname "$f")"):adm" "$f"
done
```

`$ROOT` ist die Wurzel des Abonnements, wie sie schon in der Vorlage steht;
`www-data` und `adm` kommen aus `SubscriptionProvision::DOCUMENT_ROOT_GROUP`
und `LOG_GROUP` und werden nicht ein zweites Mal hingeschrieben. Trifft der Glob
nichts, steht das Muster wörtlich da, und `[ -f ]` fängt es ab.

## §4 · Was gebaut wird

- **`WebLogrotate::template()`** erweitert den `postrotate`-Abschnitt: nach
  `self::RELOAD` folgt die Schleife oben, aus den beiden Konstanten gebaut. Die
  Zeile bleibt dieselbe wie `WebLogrotate::RELOAD`; die Schleife ist neu.
- **`packaging/etc/logrotate`** (die Protokolle des Panels selbst) bekommt
  dieselbe Rückgabe — `panel-access.log.1` trägt heute ebenfalls `www-data`
  (`docs/134 §7`), und die Datei gehört `srvpanel`, nicht einem Kunden. Hier
  gibt die Rückgabe an `srvpanel:srvpanel` zurück, den Eigentümer des
  Verzeichnisses `/var/log/srvpanel`. Der Betreiber liest die Panel-Protokolle
  ohnehin über die Protokollseite; der Nutzen ist geringer, aber die
  Ungleichheit „mal www-data, mal srvpanel" verschwindet.
- **Die schon gedrehten Dateien** (`.2.gz` …) aus früheren Nächten bleiben
  `www-data`; die Schleife fasst nur `.log.1` an. Sie altern in vierzehn Tagen
  heraus. Ein einmaliger Griff im `postinstall`, der bestehende `*.log.[0-9]*`
  unter `/var/www/vhosts/*/logs` von `www-data` an den Verzeichniseigentümer
  zurückgibt, ist möglich und **zu entscheiden** — er berührt Bestand und
  gehört gemessen, bevor er gebaut wird.

## §5 · Die Wächter

- **`LogRotationTest`** bekommt einen Fall: Der `postrotate`-Abschnitt der
  Vorlage gibt die gedrehte Datei an den Eigentümer zurück — gehalten an der
  **Wirkung** eines echten `chown -h` gegen eine `www-data`-Datei und einen
  Köder, nicht an einer Zeichenkette. Die Falle (Symlink) steht als Gegenprobe
  daneben.
- **`tests/wiederoeffnen-nachbauen.sh`** bekommt einen Fall mit der Rückgabe:
  nach der Rotation gehört die `.1` dem Verzeichniseigentümer und nicht
  `www-data`. Das ist die Messung gegen echtes nginx und echtes logrotate.
- **Ein Bruch je Regel** in `tests/waechter-brechen.sh`: die Rückgabe entfernt
  (die `.1` bleibt `www-data`), das `-h` entfernt (die Gegenprobe am Symlink
  schlägt an), den `www-data`-Vorbehalt entfernt (der Köder wird mitgefasst).
- Kein Wächter kann „läuft unter dash" halten; das misst der Nachbau, indem er
  die Vorlage durch das echte logrotate fährt. Der Satz gehört als Frage in den
  Kopf von `WebLogrotate`.

## §6 · Wann es abgenommen ist

**Auf `cloudsrv24`, nach einer Nacht mit der neuen Fassung:**

- `access.log.1` einer Domain mit Verkehr gehört ihrem Kunden, nicht
  `www-data`.
- Der Kunde liest sie — gemessen als der Kunde (`setpriv`) **und** durch den
  echten Weg: der Dateimanager des Panels und eine SFTP-Sitzung. Der Nachbau
  kann das nicht belegen (seine Dateien sind `644` und entstehen als root); der
  Lesebeleg gehört auf den Server.
- Die 84 `[emerg]`-Zeilen bleiben — sie sind nicht Gegenstand dieses Plans.

**Ausschlusskriterium:** Ein vom Kunden in sein `logs`-Verzeichnis gelegter
Symlink oder ein Köder wird durch die Rückgabe **nicht** angetastet. Auf dem
Server hergestellt und gemessen, bevor die Fassung als abgenommen gilt.

## §7 · Was offen bleibt

- **Der einmalige Griff für den Bestand** (§4) — Entscheidung des Betreibers,
  und vor dem Bau zu messen.
- **Der Web-Benutzer ist `www-data` auf Debian und Ubuntu.** Das Panel führt
  ihn als Konstante; ob eine Zielplattform ihn anders nennt, ist hier nicht
  gemessen und wäre eine eigene Frage.
- **Ob die Panel-Protokolle die Rückgabe brauchen** (§4) — der Betreiber liest
  sie über die Seite; der Gewinn ist die Gleichheit, nicht der Zugang.
