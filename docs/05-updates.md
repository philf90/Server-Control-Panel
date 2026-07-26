# 05 — Updates

## Versionierung und Kanäle

**SemVer**, mit `v`-Präfix im Git-Tag (`v0.3.1`). Bis v1.0 gilt: Minor-Releases
dürfen brechen, Patch-Releases nie.

| Kanal | Inhalt | Zielgruppe |
|---|---|---|
| `stable` | getestete Releases | Standard |
| `beta` | Vorabversionen (`v0.4.0-rc.1`) | Tester |

Es gibt **zwei** Kanäle, nicht drei. Ein `nightly` stand ursprünglich im Plan
und ist gestrichen: Die Freigabepipeline bedient ihn nicht, und ein Kanalname in
der Konfiguration, den niemand befüllt, ist eine Zusage, die erst beim ersten
Update auffällt. `updates.channel` lässt deshalb nur `stable` und `beta` zu.

Der Kanal steht in `/etc/asylum/config.yaml`. Eine Vorabversion gilt nach SemVer
als *älter* als die zugehörige Freigabe — wer von `beta` zurück auf `stable`
stellt, bekommt daher kein Downgrade, sondern wartet, bis Stable aufgeholt hat.
Automatisch heruntergestuft wird nie.

## Woher das Vertrauen kommt

Der Vertrauensanker ist ein einziger Wert: der öffentliche minisign-Schlüssel,
der als Konstante im Binary steht (`internal/update/key.go`). Weder die
Metadatendatei noch der Downloadserver noch ein Programm im `PATH` kann ihn
ersetzen.

Die Prüfung ist in Go umgesetzt und ruft nicht das Programm `minisign` auf.
Zwei Gründe: Ein Update, das erst die Installation eines Pakets voraussetzt, ist
genau dann unbrauchbar, wenn man es am nötigsten braucht. Und ein
untergeschobenes `minisign` im `PATH` könnte jede Signatur für gültig erklären.

Die Kette:

```
eingebauter Public Key
   └─ prüft ─▶ SHA256SUMS.minisig
                  └─ deckt ab ─▶ SHA256SUMS
                                    └─ enthält ─▶ SHA-256 des Archivs
                                                     └─ enthält ─▶ asylumd
```

Drei Punkte daran sind nicht offensichtlich:

- **Die Metadatendatei ist nicht signiert — und muss es nicht sein.** Sie ist
  ein Wegweiser. Wer sie fälscht, kann höchstens auf eine andere, ebenfalls echt
  signierte Fassung zeigen oder das Update verhindern.
- **Der beglaubigte Kommentar wird gegengeprüft.** minisign signiert neben der
  Datei auch einen „trusted comment", hier `Project Asylum 0.4.2`. Stimmt die
  darin genannte Fassung nicht mit der aus den Metadaten überein, bricht das
  Update ab. Ohne diese Prüfung ließe sich mit einer gefälschten Metadatendatei
  die echte Signatur einer **älteren** Fassung vorlegen — ein Downgrade auf eine
  Version mit bekannter Lücke.
- **Auch die globale Signatur wird geprüft.** Sie deckt Signatur und Kommentar
  gemeinsam ab; ohne sie ließe sich der Kommentar beliebig austauschen.

Zusätzlich: Nur `https` ist zulässig — auch nach einer Weiterleitung, und auch
für Adressen, die aus der Metadatendatei stammen. Das Archiv muss ein
ELF-Programm enthalten, sonst wird es gar nicht erst abgelegt.

## Update-Wege

### 1. Im Panel (Standardweg)

Unter **Updates** zeigt das Panel die installierte und die im Kanal verfügbare
Fassung, Schweregrad und Änderungsnotizen. Suchen darf jede schreibberechtigte
Rolle; **einspielen nur Owner** — wer das Update auslöst, bestimmt, welcher Code
als root läuft, und das ist keine gewöhnliche Schreiboperation.

Das Panel führt das Update **nicht selbst aus**. Es setzt über `systemd-run`
eine eigene Transient-Unit ab, die `asylum update` startet. Der Grund steht
unten unter „Warum ein eigener Prozess".

### 2. CLI

```bash
sudo asylum update --check          # nur nachsehen
sudo asylum update                  # mit Rückfrage
sudo asylum update --assume-yes     # ohne Rückfrage (für Skripte)
sudo asylum update --channel beta
sudo asylum update --version 0.4.2  # genau diese Fassung erwarten
sudo asylum rollback                # zurück auf die vorherige Fassung
sudo asylum rollback --restore-db   # zusätzlich den Datenbankabzug einspielen
```

`--version` installiert keine beliebige alte Fassung — es ist eine Absicherung:
Der Lauf bricht ab, wenn im Kanal inzwischen etwas anderes steht als das, was
der Auslöser gesehen hat. Genau so gibt das Panel den Auftrag weiter.

Ein selbst gebautes Binary meldet die Fassung `dev` und bekommt **kein** Update
angeboten. Was dort läge, wüsste niemand.

### 3. APT

```bash
sudo apt install asylum-panel
```

Das Paket heißt `asylum-panel`, nicht `asylum`. Der Name `asylum` ist in Debian
und Ubuntu seit Jahren an ein Spiel vergeben (`universe/games`), dessen Fassung
über unserer liegt — `apt install asylum` brächte also das Spiel. Der *Befehl*
heißt weiterhin `asylum`: `/usr/bin/asylum` steht im `PATH` vor
`/usr/games/asylum`, und weil sich die Pfade unterscheiden, streiten sich die
beiden Pakete auch nicht um eine Datei.

Der apt-Weg ist ein Zusatzangebot für alle, die ihre Server ohnehin darüber
fahren. **Empfohlen bleibt das eingebaute Update:** Nur dieses prüft nach dem
Neustart, ob das Panel wieder antwortet, und nimmt einen Fehlschlag selbsttätig
zurück. apt kennt keinen Healthcheck.

## Ablauf eines Selbstupdates

```
 1. Metadaten holen      updates/<kanal>.json, nur https, Größe begrenzt
 2. Vorbedingungen       neuer als die laufende Fassung?
                         installierte Fassung >= min_upgradable_from?
 3. Prüfkette            SHA256SUMS.minisig gegen den eingebauten Schlüssel,
                         beglaubigte Fassung gegen die Metadaten,
                         SHA-256 des Archivs gegen die signierte Liste
 4. Entpacken            asylumd aus dem Archiv, im Speicher, ELF-Kennung prüfen
 5. Ablegen              als asylumd.neu daneben, fsync, ausführbar
 6. Selbsttest           `asylumd.neu version` — meldet es die erwartete Fassung?
 7. Datenbankabzug       VACUUM INTO /var/lib/asylum/backups/vor-<version>.db
 8. Tauschen             asylumd → asylumd.vorher (Kopie),
                         rename(2) asylumd.neu → asylumd, fsync des Verzeichnisses
 9. Neustart             systemctl restart asylumd
10. Bereitschaft         bis zu 60 s auf /healthz, muss die neue Fassung melden
11. Ergebnis             OK  → Audit-Eintrag, asylumd.neu aufräumen
                         Fehler → Datenbankabzug zurück, asylumd.vorher zurück,
                                  Neustart, Fehler im Protokoll und im Panel
```

Migrationen laufen beim regulären Start (`asylumd serve` migriert selbst), es
gibt also keinen eigenen Schritt dafür.

### Warum ein eigener Prozess

Ein Vorgang, der den eigenen Dienst neu startet, überlebt seinen eigenen
Neustart nicht — und könnte einen Fehlschlag dann nicht mehr zurücknehmen.
Genauer: systemd beendet beim Stop die gesamte Kontrollgruppe der Unit. Liefe
das Update darin, würde es in Schritt 9 abgeschnitten — zwischen Tausch und
Bereitschaftsprüfung, also im denkbar schlechtesten Moment.

Deshalb:

- Das Panel setzt den Lauf über `systemd-run --unit=asylum-update-… --collect`
  als eigene Transient-Unit ab. Die hängt an keiner anderen Unit.
- `asylum update` prüft vor dem ersten Schreibzugriff, ob es in der
  Kontrollgruppe des Dienstes läuft, und **weigert sich** in diesem Fall.
- Für die Anzeige heißt das: Die Verbindung zum Browser reißt mitten im Vorgang
  ab. Der Lauf schreibt deshalb in `/var/log/asylum/update.log`, und die
  Update-Seite liest diese Datei nach dem Neustart wieder aus — ein offener
  SSE-Kanal würde den Neustart naturgemäß nicht überstehen.

### Warum Binärtausch und nicht In-Place-Overwrite

Unter Linux lässt sich die Datei eines laufenden Prozesses nicht sinnvoll
überschreiben (`ETXTBSY` bzw. korruptes Mapping). `rename(2)` innerhalb
desselben Dateisystems ist atomar: Entweder liegt die neue Datei vollständig da
oder die alte. Ein Stromausfall mitten im Update hinterlässt kein halbes Binary.
Deshalb wird `asylumd.neu` bewusst **neben** dem laufenden Programm abgelegt und
nicht in `/tmp` — ein `rename` über Dateisystemgrenzen hinweg gibt es nicht.

### Warum ein Datenbankabzug

Migrationen laufen nur vorwärts; es gibt keine Down-Migration. Ein Rollback des
Binaries allein ließe also eine ältere Fassung auf ein neueres Schema treffen.
Solange Migrationen nur ergänzen, geht das meist gut — darauf zu bauen wäre
trotzdem leichtsinnig.

Der Abzug entsteht über `VACUUM INTO` und nicht durch Kopieren der Datei: Bei
eingeschaltetem WAL liegt ein Teil der Daten im Write-Ahead-Log, eine
Dateikopie wäre je nach Zeitpunkt unvollständig.

Eingespielt wird er **nur vom selbsttätigen Rückweg**, der Sekunden nach dem
Tausch greift. Ein von Hand ausgelöster `asylum rollback` lässt die Datenbank in
Ruhe: Liegt das Update Tage zurück, stünde der Verlust aller seither
angefallenen Daten gegen ein Schemaproblem, das meist keines ist. Wer den Abzug
trotzdem will, sagt es mit `--restore-db`.

## Automatische Updates

Vorgabe: **Sicherheitspatches automatisch, alles andere auf Bestätigung.**

```yaml
updates:
  channel: stable
  check: daily
  auto_apply: security      # none | security | patch | all
  window: "03:00-05:00"     # lokale Zeit, zufälliger Versatz im Fenster
  base_url: https://repo.cloudsrv24.de
```

Ein Control Panel, das sich nachts selbst kaputtaktualisiert, ist schlimmer als
ein veraltetes. Deshalb ist der Healthcheck mit selbsttätigem Rollback
*Voraussetzung* für `auto_apply` und nicht Beiwerk. Und `all` ist nie die
Vorgabe.

`base_url` ist einstellbar, damit ein Betreiber einen eigenen Spiegel setzen
kann. Die Signaturprüfung bleibt davon unberührt — der Schlüssel steckt im
Binary, nicht auf dem Server.

> **Stand:** `check`, `auto_apply` und `window` werden eingelesen und
> validiert; der Zeitplaner, der sie auswertet, kommt in M4. Bis dahin wird
> jedes Update von Hand ausgelöst — über das Panel oder die CLI.

## Datenbank-Migrationen

- Eingebettet im Binary (`embed.FS`, `NNNN_beschreibung.sql`), nummeriert,
  vorwärtsgerichtet.
- `schema_migrations`-Tabelle mit Version und Zeitstempel.
- Jede Migration läuft in einer eigenen Transaktion.
- Keine Down-Migrationen — der Rückweg führt über den Abzug aus Schritt 7.
  Down-Migrationen sind in der Praxis selten getestet und erzeugen falsche
  Sicherheit.
- `min_upgradable_from` verhindert zu große Sprünge; ein sehr alter Server
  aktualisiert dann über eine Zwischenversion.

## Metadatenformat

`https://repo.cloudsrv24.de/updates/<kanal>.json`:

```json
{
  "version": "0.4.2",
  "released_at": "2026-08-14T09:00:00Z",
  "min_upgradable_from": "0.1.0",
  "notes_url": "https://github.com/philf90/Server-Control-Panel/releases/tag/v0.4.2",
  "severity": "security",
  "checksums_url": "…/releases/download/v0.4.2/SHA256SUMS",
  "signature_url": "…/releases/download/v0.4.2/SHA256SUMS.minisig",
  "artifacts": {
    "linux_amd64": { "url": "…/asylumd_0.4.2_linux_amd64.tar.gz", "sha256": "…" },
    "linux_arm64": { "url": "…/asylumd_0.4.2_linux_arm64.tar.gz", "sha256": "…" }
  }
}
```

`min_upgradable_from` kommt aus `packaging/min-upgradable-from` und ist im
Regelfall **leer** — also keine Grenze. Ein Wert gehört nur hinein, wenn eine
Migration einen direkten Sprung tatsächlich unmöglich macht.

Dabei ist eine Falle eingebaut, in die dieses Projekt schon einmal getreten
ist: Der Wert darf niemals neuer sein als die veröffentlichte Fassung. Stand
dort `0.1.0`, während `0.1.0-rc.2` erschien, konnte **kein** Beta-Tester mehr
aktualisieren — nach SemVer ist `0.1.0` neuer als `0.1.0-rc.1`, die Sperre
gegen zu große Sprünge griff also bei jedem, mit dem Rat, „zuerst auf 0.1.0 zu
aktualisieren", das es noch gar nicht gab. Ein Test in `internal/update` prüft
das seither bei jedem Release gegen den Tag.

Unbekannte Felder werden überlesen: Ein neuerer Server darf mehr schreiben, als
ein älteres Binary kennt. Fehlen `checksums_url` und `signature_url`, werden sie
aus der Archivadresse abgeleitet — so sehen ältere Metadaten aus.

Die Prüfsumme unter `artifacts` ist bequem, aber nicht maßgeblich. Weicht sie
von der signierten Liste ab, bricht das Update ab: Dann stimmt etwas an der
Veröffentlichung nicht, und das ist keine Kleinigkeit, die man übergeht.

## Release-Pipeline

GitHub Actions, ausgelöst durch ein `v*`-Tag:

```
Tests ─▶ Schlüsselprobe ─▶ goreleaser ─▶ Artefakte:
                                          asylumd_<ver>_linux_{amd64,arm64}.tar.gz
                                          asylum-panel_<ver>_{amd64,arm64}.deb
                                          install.sh
                                          SHA256SUMS + SHA256SUMS.minisig
                                          SBOM (syft)
                                          → GitHub Release
        ─▶ Pages-Job ─▶ repo.cloudsrv24.de/updates/<kanal>.json
                        repo.cloudsrv24.de/install.sh, minisign.pub, index.html
                        repo.cloudsrv24.de/apt/…
```

Vor dem Bauen prüft der Workflow, dass der hinterlegte private Schlüssel zum
veröffentlichten `packaging/minisign.pub` passt — ein vertauschtes Secret fällt
damit im CI auf und nicht erst beim Nutzer. Ein Test im Paket `update` wacht
zusätzlich darüber, dass der im Binary eingebaute Schlüssel, `minisign.pub` und
der im Installer eingebettete Schlüssel nicht auseinanderlaufen.

Build-Flags: `CGO_ENABLED=0`, `-trimpath`, `-ldflags "-s -w -X …version.Version=…"`.
Zeitstempel aus dem Commit statt aus der Uhr — reproduzierbar.

**cosign ist nicht dabei.** Ursprünglich war es zusätzlich zu minisign geplant.
Es kam nicht hinein, weil es für den Update-Weg nichts beiträgt, was minisign
nicht schon leistet, dafür aber eine Rekor-Abfrage über das Netz voraussetzt —
und der Update-Pfad soll gerade nicht von einem weiteren erreichbaren Dienst
abhängen. Für die Herkunftsbindung an Repository und Workflow bleibt es eine
sinnvolle Ergänzung; das gehört dann in M4 zusammen mit dem Sicherheits-Review.

## APT-Repository

Aufbau auf `repo.cloudsrv24.de`:

```
apt/dists/<kanal>/Release, Release.gpg, InRelease
apt/dists/<kanal>/main/binary-{amd64,arm64}/Packages[.gz]
apt/pool/main/a/asylum-panel/asylum-panel_<ver>_<arch>.deb
apt/asylum-archive-keyring.gpg   (öffentlicher Schlüssel, binär)
apt/KEY.asc                      (derselbe, ASCII)
```

`<kanal>` ist derselbe Kanal wie oben — `stable` oder `beta` — und in apt-Sprache
die *Suite*. Welcher Kanal bedient wird, entscheidet der Tag: Ein Tag mit
Bindestrich (`v0.1.0-rc.2`) ist nach SemVer eine Vorabversion und geht nach
`beta`, alles andere nach `stable`.

**Ein Verzeichnis entsteht erst mit der ersten passenden Veröffentlichung.**
Solange es nur Vorabversionen gibt, liegt unter `apt/dists/` allein `beta/`. Wer
dann `Suites: stable` einträgt, bekommt von apt:

```
Fehl: https://repo.cloudsrv24.de/apt stable Release  404  Not Found
E: Das Depot »… stable Release« enthält keine Release-Datei.
```

Das ist die korrekte Antwort auf die Frage nach einem leeren Kanal, kein Defekt.
Der Pool ist gemeinsam: Wird eine Version aus `beta` später als Freigabe
veröffentlicht, taucht dieselbe `.deb` unter `stable` auf, ohne neu hochgeladen
zu werden.

Einrichten (während der Beta-Phase `beta`, nach der ersten Freigabe `stable`):

```bash
sudo curl -fsSL --proto '=https' --tlsv1.2 \
  https://repo.cloudsrv24.de/apt/asylum-archive-keyring.gpg \
  -o /usr/share/keyrings/asylum-archive-keyring.gpg

sudo tee /etc/apt/sources.list.d/asylum.sources > /dev/null <<'EOF'
Types: deb
URIs: https://repo.cloudsrv24.de/apt
Suites: beta
Components: main
Signed-By: /usr/share/keyrings/asylum-archive-keyring.gpg
EOF

sudo apt update && sudo apt install asylum-panel
```

Ein Kanalwechsel ist ein Ändern dieser einen Zeile plus `sudo apt update`. Was
gerade vorhanden ist, lässt sich vorher nachsehen:

```bash
curl -fsI https://repo.cloudsrv24.de/apt/dists/stable/Release >/dev/null && echo stable
curl -fsI https://repo.cloudsrv24.de/apt/dists/beta/Release   >/dev/null && echo beta
```

Wer dem Download nicht traut, vergleicht den Fingerprint mit dem unten
genannten:

```bash
gpg --show-keys /usr/share/keyrings/asylum-archive-keyring.gpg
# FC79D6FB2CA8321BBA071E05AC66AF2CEEB9AA1A
```

Ältere Fassungen bleiben im Pool liegen, ein gezieltes Downgrade über apt bleibt
also möglich.

### Signaturschlüssel des Repositories

apt braucht OpenPGP; minisign kann es nicht. Das ist deshalb ein **zweiter**
Schlüssel, unabhängig vom Signaturschlüssel der Artefakte.

```
Project Asylum Archive Signing Key <noreply@users.noreply.github.com>
ed25519, ohne Ablaufdatum
Fingerprint: FC79D6FB2CA8321BBA071E05AC66AF2CEEB9AA1A
```

Er liegt im Repository unter `packaging/asylum-archive-keyring.gpg` (binär, das
Format, das apt erwartet) und `packaging/asylum-archive-keyring.asc` (Textform
zum Nachlesen). Der private Teil liegt ausschließlich in den
Repository-Secrets `APT_GPG_KEY` (ASCII-armiert) und `APT_GPG_PASSPHRASE`.

Der Release-Workflow prüft vor dem Signieren, dass der Fingerprint des Secrets
zum veröffentlichten Schlüsselbund passt, und verifiziert die erzeugte Signatur
anschließend noch einmal aus Nutzersicht — mit einem leeren Schlüsselring, in
den nur der ausgelieferte öffentliche Schlüssel importiert wurde. Genau so
arbeitet apt. Ein vertauschtes Secret fällt damit im CI auf und nicht als
`NO_PUBKEY` bei allen Nutzern gleichzeitig.

Ein Job in der CI hält zusätzlich die drei Orte zusammen, an denen der
Schlüssel auftaucht: Schlüsselbund, Textfassung und der Fingerprint in diesem
Dokument.

#### Warum kein Ablaufdatum

Ein abgelaufener Archivschlüssel bricht `apt update` bei jedem Nutzer — und
zwar an dem Tag, an dem niemand damit rechnet. Für ein Projekt mit einem
Betreuer ist die Wahrscheinlichkeit, eine Verlängerung zu verschlafen, höher
als das Risiko, das ein Ablaufdatum abfedern soll. Der Schlüssel gilt deshalb
unbegrenzt.

Der Weg für den Ernstfall ist stattdessen eingebaut: Das Repository liefert ein
eigenes Paket **`asylum-archive-keyring`**, das den Schlüsselbund unter
`/usr/share/keyrings/asylum-archive-keyring.gpg` ablegt — nach dem Vorbild von
`debian-archive-keyring`. `asylum-panel` empfiehlt es (`Recommends`), aus dem
Repository heraus wird es also mitinstalliert. Ein Schlüsselwechsel lässt sich
damit über ein gewöhnliches `apt upgrade` ausliefern, statt jeden Nutzer von
Hand eine Datei herunterladen zu lassen.

Dass es ein **eigenes** Paket ist und nicht Teil von `asylum-panel`, hat einen
Grund, der erst beim Deinstallieren sichtbar wird: Läge die Datei im
Panel-Paket, nähme `apt remove asylum-panel` sie mit — und die Paketquelle
stünde ohne Schlüssel da, mit `NO_PUBKEY` bei jedem weiteren `apt update`.
Getrennt bleibt der Schlüssel liegen.

`Recommends` statt `Depends`, weil das `.deb` auch einzeln aus einem
GitHub-Release installiert wird; eine harte Abhängigkeit auf ein Paket, das dann
in keiner Quelle steht, ließe genau diese Installation scheitern.

Wer den Schlüssel über den `curl`-Aufruf oben eingerichtet hat, muss ihn bei
einem Wechsel selbst erneuern — sofern er nicht ohnehin `asylum-archive-keyring`
installiert hat, das dieselbe Datei verwaltet. Ein Widerrufszertifikat liegt
beim privaten Schlüssel.

Fehlt `APT_GPG_KEY`, überspringt der Workflow den Schritt mit einer Warnung,
statt ein unsigniertes Repository zu veröffentlichen — ein unsigniertes
apt-Repository ist schlimmer als gar keines. Alles andere am Release
funktioniert ohne diesen Schlüssel.

## Support-Zusage

- Sicherheitsupdates für die aktuelle Minor-Version und deren Vorgänger.
- Distributionen: alle Ubuntu-LTS und Debian-Stable mit Upstream-Support.
- Breaking Changes bekommen einen Eintrag in `UPGRADING.md` mit Migrationspfad —
  auch vor v1.0.
