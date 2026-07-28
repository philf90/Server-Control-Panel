# Aktualisieren

Der Normalfall braucht dieses Dokument nicht: Updates spielt das Panel selbst
ein, mit Signaturprüfung, Bereitschaftsprüfung und selbsttätigem Rückweg. Hier
steht nur, was von Hand zu tun ist — und das soll die Ausnahme bleiben.

```bash
sudo asylum update --check    # was liegt an
sudo asylum update            # einspielen
sudo asylum rollback          # zurück auf die vorherige Fassung
```

Einzelheiten: [docs/05-updates.md](docs/05-updates.md).

## Was Sie erwarten dürfen

- **Patch-Releases brechen nie.** Konfiguration, Datenbank und Kommandozeile
  bleiben gleich.
- **Minor-Releases dürfen bis v1.0 brechen.** Was bricht, steht hier — mit dem
  Weg hinüber, nicht nur mit dem Hinweis, dass es bricht.
- **Migrationen laufen nur vorwärts** und beim gewöhnlichen Start. Es gibt
  keinen eigenen Schritt dafür.
- **Vor jedem Austausch entsteht ein Datenbankabzug** unter
  `/var/lib/asylum/backups/vor-<fassung>.db`.

## Ein Rückweg von Hand

`asylum rollback` tauscht das Binary zurück und lässt die Datenbank in Ruhe.
Das ist Absicht: Liegt das Update Tage zurück, würde das Einspielen des Abzugs
alles verwerfen, was seither angefallen ist — Audit-Einträge, Konten,
Einstellungen.

Führte eine Migration zu einem Schema, mit dem die ältere Fassung nicht
zurechtkommt, hilft zusätzlich:

```bash
sudo asylum rollback --restore-db
```

Das spielt den jüngsten Abzug ein und **verwirft alles seit dem letzten
Update**. Der selbsttätige Rückweg direkt nach einem fehlgeschlagenen Update
macht das von allein — dort liegen Sekunden dazwischen, keine Tage.

## Von 0.x auf 0.x+1

### Auf 0.3.0: die systemd-Unit anpassen, wenn Sie den Dateimanager nutzen wollen

0.3.0 bringt einen Dateimanager. Er braucht Schreibzugriff dort, wo
Konfiguration und Daten liegen, und die mitgelieferte systemd-Unit erlaubt das
ab dieser Fassung:

| Vorher | Ab 0.3.0 |
|---|---|
| `ProtectSystem=full` | `ProtectSystem=true` |
| `ProtectHome=read-only` | `ProtectHome=false` |

`/usr`, `/boot` und `/efi` bleiben nur lesbar; schreibbar werden `/etc`, `/home`
und `/root`.

**Das Selbstupdate tauscht das Programm, nie die Unit.** Wer über
`asylum update` oder den Installer aktualisiert, hat danach also die neue
Fassung des Panels und die alte Härtung — und dann scheitert jeder
Schreibversuch unter `/etc` oder `/home` mit `EROFS`. Am Verzeichnis selbst ist
das nicht zu erkennen: Die Rechtebits sagen nichts über einen read-only
eingehängten Baum.

Das Panel prüft das beim ersten Aufruf der Dateiseite mit einem echten
Schreibversuch und zeigt die betroffenen Bereiche samt Behebung an. Von Hand:

```bash
sudo systemctl edit --full asylumd
#   ProtectSystem=full        →  ProtectSystem=true
#   ProtectHome=read-only     →  ProtectHome=false
sudo systemctl daemon-reload
sudo systemctl restart asylumd
```

Beim Debian-Paket geht es auch ohne Bearbeiten: Ein `apt upgrade` bringt die
neue Unit mit. Haben Sie sie von Hand geändert, fragt `dpkg` nach — dann ist
„die Fassung aus dem Paket übernehmen" die richtige Antwort.

**Wenn Sie den Dateimanager nicht wollen**, ist nichts zu tun. Sie können die
Härtung so lassen, wie sie ist, und das Modul abschalten:

```yaml
# /etc/asylum/config.yaml
files:
  enabled: false
```

Das entfernt Routen und Rechte, nicht nur den Menüpunkt.

Was Sie außerdem wissen sollten, wenn Sie ihn nutzen:

- **Manche Pfade sind für das Panel tabu** — Passwort-Hashes, private
  Schlüssel, die Datenbank des Panels. Sie erscheinen in der Liste mit
  Begründung, ihr Inhalt wird nie ausgeliefert. Auch nicht für die Rolle
  `owner`. Über SSH sind sie erreichbar wie immer.
- **Lesen darf jede angemeldete Rolle**, auch `readonly`. Ändern nur `admin`
  und `owner`.
- **Downloads stehen im Audit-Log.** Bei einem Dateimanager ist die
  interessantere Frage nicht, wer etwas geschrieben, sondern wer etwas
  mitgenommen hat.

Einzelheiten: [docs/13-dateimanager.md](docs/13-dateimanager.md).

### Auf die nächste Fassung nach 0.1.0

Nichts zu tun. Die Änderungen an `updates.channel` und der Umbenennung des
Debian-Pakets betreffen nur Installationen aus der Zeit vor 0.1.0, die es
öffentlich nicht gibt.

Zwei Dinge zur Kenntnis, falls Sie aus dem Repository gebaut haben:

- **`updates.channel: nightly` wird abgewiesen.** Erlaubt sind `stable` und
  `beta`. Der Kanal wurde von der Freigabepipeline nie bedient; ein Wert, den
  niemand einlöst, gehört nicht in eine Konfiguration.
- **Das Debian-Paket heißt `asylum-panel`.** Wer ein selbst gebautes `asylum`
  installiert hatte:

  ```bash
  sudo apt remove asylum && sudo apt install asylum-panel
  ```

  Der Befehl heißt weiterhin `asylum`, Konfiguration und Daten bleiben liegen.

## Wenn ein Update fehlschlägt

Es sollte sich selbst zurücknehmen. Meldet sich der Dienst nach dem Neustart
nicht binnen einer Minute mit der neuen Fassung, spielt der Server die vorherige
zurück und startet sie — auch dann, wenn der Browser längst geschlossen ist.

Nachsehen können Sie hier:

```bash
cat /var/log/asylum/update.log      # der Ablauf des letzten Vorgangs
journalctl -u asylumd -n 100        # was der Dienst dazu sagt
asylum version                      # was tatsächlich läuft
```

Bleibt das Panel unerreichbar, führt der Weg über SSH:

```bash
sudo asylum rollback                # zurück auf die vorherige Fassung
sudo asylum reset-password BENUTZER # wenn der Zugang das Problem ist
```

## Neu installieren statt aktualisieren

Der Installer ist idempotent. Ein erneuter Lauf über eine bestehende
Installation tauscht das Binary und lässt Konfiguration und Daten unangetastet:

```bash
curl -fsSL --proto '=https' --tlsv1.2 https://repo.cloudsrv24.de/install.sh -o install.sh
sudo bash install.sh
```

Was er dabei nicht kann: die Bereitschaft prüfen und im Fehlerfall zurückrollen.
Dafür ist `asylum update` da.
