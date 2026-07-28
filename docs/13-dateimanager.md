# 13 — Dateimanager

Ab 0.3.0 hat das Panel einen Dateimanager über das gesamte Dateisystem:
browsen, herunterladen, hochladen, anlegen, umbenennen, verschieben, kopieren,
löschen, Rechte und Eigentümer setzen, Verzeichnisse als `tar.gz` laden und
Textdateien in einem Editor bearbeiten.

Er ist das erste Modul, dessen Ziel aus der Anfrage kommt und nicht aus einer
Allowlist. Bei den Diensten steht ein Unit-Name zur Wahl, bei den Paketen ein
Paketname — hier steht jeder Pfad des Servers zur Wahl. Dieses Dokument
beschreibt, was daraus folgt.

## Die kurze Fassung

| Frage | Antwort |
|---|---|
| Wo darf gelesen werden? | überall (`files.readable_roots`, Vorgabe `/`) |
| Wo darf geschrieben werden? | `/etc`, `/home`, `/root`, `/srv`, `/opt`, `/var`, `/mnt`, `/media`, `/tmp` |
| Was ist tabu? | eingebaute Sperrliste (Passwort-Hashes, private Schlüssel, Panel-Datenbank) — für **jede** Rolle |
| Wer darf lesen? | jede angemeldete Rolle, auch `readonly` |
| Wer darf ändern? | `admin` und `owner` |
| Abschaltbar? | `files.enabled: false` entfernt Routen und Rechte, nicht nur den Menüpunkt |

## Die Pfadwache

Alles, was mit einem Pfad zu tun hat, geht durch eine Stelle:
`internal/privops/pfadwache.go`. Kein Handler baut je selbst einen Pfad
zusammen. Sie beantwortet vier Fragen in dieser Reihenfolge:

1. **Ist die Zeichenkette überhaupt ein Pfad?** Absolut, ohne Steuerzeichen,
   höchstens `PATH_MAX`. Ein NUL-Byte beendet die Zeichenkette in jedem
   `syscall` — alles dahinter wäre unsichtbar. Zeilenumbrüche zerlegen
   Protokolle, und die Schreibrichtungs-Umschalter (`U+202E` und Verwandte)
   sind der klassische Weg, einen Dateinamen in einer Liste anders aussehen zu
   lassen, als er ist.
2. **Liegt er in einem freigegebenen Baum — auch nach Auflösung der
   Verzeichnisverweise?** Ein Symlink `/tmp/x → /etc` wäre sonst ein Umweg um
   jede Prüfung, die nur die Zeichenkette ansieht. Geprüft werden beide
   Fassungen: die angefragte und die aufgelöste.
3. **Steht er auf der Sperrliste?** Geprüft wird auch jeder Vorfahre; ein
   Muster sperrt damit alles darunter.
4. **Ist die verlangte Art des Zugriffs dort erlaubt?** Metadaten, Inhalt oder
   Änderung sind drei verschiedene Fragen.

Aufgelöst wird über `os.Root` (Go 1.24/1.25), nicht über Zeichenketten: Die
Bibliothek prüft bei jeder Operation erneut, dass keine Komponente aus dem Baum
herausführt. Für die letzte Komponente kommt `O_NOFOLLOW` dazu — damit ist der
Austausch eines Namens gegen einen Verweis zwischen Prüfung und Öffnen
abgedeckt.

**Was die Pfadwache nicht leistet:** Schutz gegen einen Angreifer, der schon
lokal schreiben darf und ein Verzeichnis *mitten* im Pfad im richtigen
Augenblick durch einen Verweis ersetzt. Gegen die letzte Komponente hilft
`O_NOFOLLOW`, gegen die mittleren wäre ein Öffnen Komponente für Komponente
nötig. Wer lokal schreiben kann, braucht das Panel dafür allerdings nicht.

## Die Sperrliste

Diese Pfade werden **angezeigt**, ihr Inhalt aber nie gelesen, geschrieben,
heruntergeladen oder gelöscht — und zwar für jede Rolle, auch für `owner`:

```
/etc/shadow, /etc/shadow-, /etc/gshadow, /etc/gshadow-
/etc/ssh/ssh_host_*_key
/etc/asylum/tls/*.key
/var/lib/asylum/asylum.db*
/var/lib/asylum/acme
/var/lib/asylum/releases
/root/.ssh/id_*
/home/*/.ssh/id_*
```

Der Grund ist nicht Prüderie gegenüber root, sondern der Zweck des Panels: Es
ist über das Netz erreichbar. Eine übernommene Sitzung könnte sonst mit zwei
Klicks die Passwort-Hashes aller Panel-Zugänge, den privaten TLS-Schlüssel und
die SSH-Host-Schlüssel herunterladen — also genau das Material, mit dem sich
jede weitere Schutzschicht umgehen lässt. Wer diese Dateien braucht, hat SSH.

Zwei Feinheiten:

- **Öffentliche Schlüssel sind ausgenommen.** Ohne die Ausnahme fiele
  `id_ed25519.pub` unter das Muster `id_*` — eine Sperre, die nichts schützt
  und nur verwirrt.
- **Der Eintrag bleibt sichtbar, mit Begründung.** Ein verschwiegener Eintrag
  sähe wie eine Fehlfunktion aus. Die Liste zeigt ein Schloss und den Grund.

Ergänzen lässt sich die Liste über `files.denied_paths` (Muster nach
`filepath.Match`). Verkleinern nicht.

## Was nicht angefasst wird

- **`/proc`, `/sys` und `/dev`** werden nicht durchlaufen und nicht
  ausgeliefert. Sie sind keine Ablage: `/proc/kcore` behauptet 128 TiB,
  `/dev/zero` liefert unendlich viel, und `/proc/self/root` ist ein Verweis
  auf `/` und damit ein Sprungbrett aus jeder Pfadprüfung.
- **Nur reguläre Dateien haben Inhalt.** Eine FIFO würde beim Öffnen
  unbegrenzt blockieren, eine Gerätedatei endlos liefern. Verzeichnisse,
  Sockets, FIFOs und Geräte erscheinen mit Metadaten und ohne Inhalt.
- **Verweisen wird nicht gefolgt** (`files.follow_symlinks: false`).
  Angezeigt wird das Ziel; Umbenennen und Löschen gelten dem Verweis selbst.
  Rechte lassen sich auf einem Verweis nicht setzen — sie gehören immer dem
  Ziel, und das liegt möglicherweise dort, wo das Panel nichts zu ändern hat.
  Ein Eigentümerwechsel gilt über `lchown` dem Verweis.

## Rekursive Eingriffe

Löschen, Kopieren und rekursive Rechteänderungen werden **vorher gezählt** und
abgelehnt, wenn darunter etwas liegt, das nicht angefasst werden darf:

- **Gesperrtes darunter** → abgelehnt. Ein Löschen von `/etc` darf
  `/etc/shadow` nicht mitnehmen.
- **Eine Dateisystemgrenze darunter** → abgelehnt. Ein Löschen von `/mnt`
  würde sonst die eingehängte Platte leeren.
- **Mehr als 200 000 Einträge** → abgelehnt, mit Angabe der Grenze.

Der Abbruch kommt vor dem ersten `unlink`, nicht nach dem ersten Treffer.

Ab 500 Einträgen verlässt der Vorgang die Anfrage und läuft als
Hintergrundvorgang mit Live-Ausgabe weiter — dieselbe Mechanik wie beim
Paket-Update. Ein Browser, der nach dreißig Sekunden aufgibt, würde sonst ein
halb kopiertes Verzeichnis hinterlassen, um das sich niemand mehr kümmert. Es
läuft immer nur ein Dateivorgang: Zwei rekursive Läufe über denselben Baum
kämen sich in die Quere.

## Schreiben

Geschrieben wird über eine Nachbardatei und `rename(2)`. Ein Abbruch —
Stromausfall, vollgelaufene Platte, beendeter Dienst — hinterlässt entweder die
alte oder die neue Fassung, niemals eine halbe. Bei einer Konfigurationsdatei
ist das der Unterschied zwischen „unverändert" und „der Dienst startet nicht
mehr".

Dazu:

- **Sicherung vor jedem Überschreiben** nach
  `/var/lib/asylum/backups/<zeit>/<pfad>`, mit `0600` — die Sicherung kann
  Geheimnisse enthalten, die im Original strenger geschützt waren. Dieselbe
  Zusage wie für die verwalteten Konfigurationsdateien
  ([02-architektur.md](02-architektur.md), Regel 4).
- **Rechte und Eigentümer der Vorgängerdatei bleiben.** Eine
  Konfigurationsdatei, die nach dem Speichern plötzlich `root:root 0644`
  gehört, wäre für den Dienst, der sie liest, möglicherweise unbrauchbar.

## Upload

Der Upload ist der einzige Endpunkt des Panels, der einen großen Körper liest.
Drei Dinge sind deshalb anders als überall sonst:

1. **Gestreamt, nicht geparst.** `r.ParseMultipartForm` zöge eine Datei von
   zwei Gigabyte in Speicher und Temp-Dateien; bei `MemoryMax=256M` ist das
   kein Weg. Gelesen wird über `r.MultipartReader()` Teil für Teil. Gemessen:
   Bei einem Körper von 40 MiB wächst der Haufen um 0 Bytes.
2. **Der CSRF-Token kommt aus dem ersten Multipart-Teil oder aus einer
   Kopfzeile.** Die übliche Middleware holt ihn über `r.PostFormValue`, und das
   wäre genau das Puffern aus Punkt 1. Diese Route prüft deshalb selbst — vor
   dem ersten Byte Dateiinhalt. **Die Feldreihenfolge im Formular ist damit
   sicherheitsrelevant:** `_csrf` und `dir` stehen vor dem Dateifeld. Ein Test
   schickt die Datei bewusst zuerst und erwartet 403 samt leerer Platte.
3. **Die Lesefrist wird während des Empfangs verlängert.** Der globale
   `ReadTimeout` von 30 Sekunden gilt für alle anderen Routen weiter; ein
   Upload über eine langsame Leitung reißt sonst mitten im Körper ab.
   Verlängert wird die Pause zwischen zwei Blöcken, nicht die Gesamtdauer.

Eine bestehende Datei wird nur mit ausdrücklichem Kennzeichen ersetzt, und dann
mit Sicherung. Dateinamen vom Browser werden auf ihren letzten Bestandteil
gekürzt — manche schicken den vollständigen Pfad des Rechners, samt
Windows-Trennern.

## Editor

Zeilennummern, Hervorhebung für YAML, JSON, INI, Shell, nginx, Dockerfile und
TOML, `Strg+S` zum Speichern. Grundlage ist CodeMirror 6; der Bundle liegt
gebaut im Repository (`internal/ui/static/editor/cm.js`), damit ein Go-Build
ohne Node-Kette auskommt. Reproduzierbarkeit und Lizenzen:
[packaging/editor/THIRD-PARTY.md](../packaging/editor/THIRD-PARTY.md).

Der Editor ersetzt eine `<textarea>`, die im Formular bleibt. **Ohne JavaScript
funktioniert dieselbe Seite unverändert weiter** — nur ohne Zeilennummern und
Farben.

Drei Zusagen über „Textfeld mit Speicherknopf" hinaus:

- **Zeilenenden bleiben.** CRLF bleibt CRLF, eine fehlende Schlusszeile bleibt
  fehlend. Ein Editor, der aus 4000 CRLF-Zeilen stillschweigend LF macht, ist
  in einem Panel nicht tragbar: Der Unterschied wandert in ein Diff, das
  niemand mehr lesen kann.
- **Konflikte werden erkannt.** Der SHA-256 des Inhalts beim Laden steht im
  Formular. Weicht er beim Speichern ab, zeigt die Seite den Konflikt — mit der
  eigenen Fassung im Feld und dem neuen Hash, damit ein zweiter Versuch bewusst
  überschreibt (Regel 6).
- **Prüfung mit Rückweg.** Für Dateien, die sich prüfen lassen, läuft nach dem
  Schreiben das Prüfprogramm des Systems. Lehnt es ab, wird der Vorzustand
  zurückgeschrieben; war die Datei neu, wird sie entfernt (Regel 5).

| Pfad | Prüfprogramm |
|---|---|
| `/etc/ssh/sshd_config`, `/etc/ssh/sshd_config.d/*` | `sshd -t` |
| `/etc/nftables.conf`, `/etc/nftables.d/*` | `nft -c -f` |

Die Zuordnung ist eine feste Liste, keine Heuristik: Ein Prüfprogramm, das
gegen die falsche Datei läuft, wäre schlimmer als keines. Für alles andere
meldet die Seite „nicht geprüft" — nicht „in Ordnung".

Nicht bearbeitbar sind Dateien über `files.max_edit_size` (Vorgabe 2 MiB),
Dateien mit einem NUL-Byte (dann ist es kein Text) und Dateien, die nicht in
UTF-8 kodiert sind (der Browser würde sie beim Speichern verändern). In allen
drei Fällen bleibt der Download.

## Die Content-Security-Policy und der Editor

Die Richtlinie des Panels erlaubt keine inline-Stile. CodeMirror trägt seine
Regeln zur Laufzeit in ein eigenes `<style>`-Element ein — und Chromium verwirft
das: *„Refused to apply inline style because it violates the following Content
Security Policy directive: style-src 'self'"*. Der Editor blieb ungestylt.
Nachgemessen im Browser, nicht vermutet.

Der naheliegende Ausweg wäre `'unsafe-inline'` für diese Seite gewesen. Damit
wäre aber jeder eingeschleuste Stil erlaubt, und Stile können Inhalte verdecken
oder Eingaben verraten. Umgesetzt ist deshalb ein **Nonce**: Die Editor-Antwort
— und nur sie — trägt

```
style-src 'self' 'nonce-<je Antwort neu>'
```

Der Wert geht über ein `data`-Attribut an CodeMirror (`EditorView.cspNonce`).
Erlaubt ist damit genau das eine Element, das den Wert kennt. Geprüft wird das
in einem Browserdurchlauf (`TestFilesEditorBrowser`): Die Konsole muss frei von
Verstößen sein, Zeilennummern und Thema müssen ankommen, und ein im Editor
eingefügter Text muss auf der Platte landen, ohne den Rest der Datei zu
verlieren.

## systemd-Härtung

Der Dateimanager braucht Schreibzugriff dort, wo Konfiguration und Daten
liegen. Die Unit hat sich dafür geändert:

| Vorher | Jetzt | Folge |
|---|---|---|
| `ProtectSystem=full` | `ProtectSystem=true` | `/etc` wird schreibbar; `/usr`, `/boot`, `/efi` bleiben nur lesbar |
| `ProtectHome=read-only` | `ProtectHome=false` | `/home` und `/root` werden schreibbar |

`/usr` und `/boot` bleiben bewusst geschützt: Dort hat ein Panel nichts von Hand
zu ändern, und der Schutz gegen ein untergeschobenes Binary bleibt damit
erhalten. Wer den Dateimanager nicht braucht, kann beides wieder verschärfen und
`files.enabled: false` setzen.

**Wichtig für bestehende Installationen:** Das Selbstupdate tauscht das
Programm, nie die Unit. Eine Installation, die von einer Fassung vor 0.3.0
kommt, trägt deshalb noch die alte Härtung — und dann scheitert jeder
Schreibversuch unter `/etc` und `/home` mit `EROFS`, ohne dass die Rechtebits
etwas davon verraten. Das Panel prüft das beim ersten Aufruf der Dateiseite mit
einem echten Schreibversuch und zeigt den Weg zur Behebung.
[UPGRADING.md](../UPGRADING.md) beschreibt ihn.

## Konfiguration

Alles optional. Ohne Eintrag gilt die Vorgabe aus der Tabelle oben.

```yaml
files:
  enabled: true
  readable_roots: ["/"]
  writable_roots: ["/etc", "/home", "/root", "/srv", "/opt", "/var", "/mnt", "/media", "/tmp"]
  denied_paths: ["/srv/kundendaten"]
  follow_symlinks: false
  max_upload: 2GiB
  max_edit_size: 2MiB
```

Zwei Feinheiten:

- **`writable_roots: []` ist etwas anderes als „nicht gesetzt".** Eine
  ausdrücklich leere Liste macht den Dateimanager nur lesend; ein fehlender
  Eintrag nimmt die Vorgabe.
- **Größen nur in Zweierpotenzen** (`KiB`, `MiB`, `GiB`) oder als reine
  Bytezahl. Eine Grenze, die als `2GB` dasteht und 2 GiB bedeutet, wäre eine
  kleine Unwahrheit an einer Stelle, an der es auf Zahlen ankommt.

Eine widersprüchliche Politik — etwa eine Schreibwurzel außerhalb der lesbaren
Bäume — verhindert nicht den Start: Das Modul bleibt aus, mit Begründung im
Protokoll. Ein Panel, das wegen einer Einstellung des Dateimanagers nicht mehr
erreichbar ist, wäre die schlechtere Antwort — dann käme man an genau diese
Einstellung nicht mehr heran.

## Was im Audit-Log steht

Jede Änderung, und zusätzlich jeder Download:

```
files.download   files.archive    files.upload
files.mkdir      files.touch      files.rename
files.copy       files.move       files.delete
files.chmod      files.chown      files.edit
files.edit.rollback
```

Downloads verändern nichts, verlassen aber den Server — und bei einem
Dateimanager ist die interessantere Frage nicht, wer etwas geschrieben, sondern
wer etwas mitgenommen hat. Der Ausgang unterscheidet `denied` (die Politik sagt
nein) von `error` (das System sagt nein); ein Löschvorgang trägt seinen Umfang
im Detail.

## Was bewusst fehlt

- **Kein Entpacken von Archiven.** Zip-Slip, Speicherbedarf und Rechte in
  Archiven sind drei Fehlerquellen für einen Nutzen, den `tar -x` über SSH
  besser abdeckt. Der umgekehrte Weg — ein Verzeichnis als `tar.gz` laden —
  ist dabei.
- **Kein Papierkorb.** Gelöscht ist gelöscht; die Rückfrage nennt dafür Zahlen
  statt „wirklich löschen?".
- **Kein Bearbeiten als anderer Benutzer.** Das Panel arbeitet als root und
  setzt Eigentümer ausdrücklich, statt Rechte zu erraten.
