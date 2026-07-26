# 05 — Updates

## Versionierung und Kanäle

**SemVer**, mit `v`-Präfix im Git-Tag (`v0.3.1`). Bis v1.0 gilt: Minor-Releases
dürfen brechen, Patch-Releases nie.

| Kanal | Inhalt | Zielgruppe |
|---|---|---|
| `stable` | getestete Releases | Standard |
| `beta` | Vorabversionen | Tester |
| `nightly` | Builds von `main` | Entwicklung, ausdrücklich ohne Garantie |

Der Kanal steht in `/etc/asylum/config.yaml` und ist im UI umstellbar. Ein Wechsel von
`beta` zurück auf `stable` wartet, bis Stable die installierte Version eingeholt hat
— es wird nie automatisch downgraded.

## Update-Wege

Alle drei greifen auf dieselben Release-Artefakte zu, sind also austauschbar:

### 1. Im Panel (Standardweg)

Ein Hintergrund-Job prüft täglich (mit zufälligem Versatz, um Lastspitzen auf dem
Update-Server zu vermeiden) `https://repo.cloudsrv24.de/updates/<kanal>.json`:

```json
{
  "version": "0.4.2",
  "released_at": "2026-08-14T09:00:00Z",
  "min_upgradable_from": "0.2.0",
  "notes_url": "https://github.com/philf90/Server-Control-Panel/releases/tag/v0.4.2",
  "severity": "security",
  "artifacts": {
    "linux_amd64": { "url": "…/asylumd_0.4.2_linux_amd64.tar.gz", "sha256": "…" },
    "linux_arm64": { "url": "…/asylumd_0.4.2_linux_arm64.tar.gz", "sha256": "…" }
  },
  "signature": "…"
}
```

Das UI zeigt Version, Changelog und Schweregrad. Ein Klick startet das Update.

### 2. CLI

```bash
sudo asylum update              # auf neueste Version im konfigurierten Kanal
sudo asylum update --version 0.4.2
sudo asylum update --check      # nur prüfen, Exit-Code 0 = aktuell, 10 = Update verfügbar
sudo asylum rollback            # zurück auf die vorherige Version
```

### 3. APT

`sudo apt upgrade` für alle, die das Repository aus [04-setup.md](04-setup.md)
eingebunden haben. Das Post-Install-Skript des Pakets ruft dieselbe
Migrations- und Restart-Logik auf.

## Ablauf eines Selbstupdates

```
 1. Metadaten holen        Signatur der Metadaten prüfen
 2. Vorbedingungen         installierte Version >= min_upgradable_from?
                           freier Speicher? Läuft gerade ein Job?
 3. Download               nach /var/lib/asylum/staging/, SHA256 + Signatur prüfen
 4. Selbsttest             neues Binary mit `--version` und `--selftest` starten
 5. DB-Backup              SQLite-Snapshot nach /var/lib/asylum/backups/pre-<version>.db
 6. Binary tauschen        neues Binary daneben legen, dann rename(2) — atomar;
                           altes Binary nach /var/lib/asylum/releases/<alte-version>
 7. Migrationen            `asylumd migrate` — versioniert, forward-only, in einer
                           Transaktion je Migration
 8. Neustart               systemctl restart, Type=notify → systemd wartet auf Ready
 9. Healthcheck            30 s auf /healthz mit erwarteter Version
10. Ergebnis               OK → Audit-Log-Eintrag, Staging aufräumen
                           Fehler → automatischer Rollback (Binary zurücktauschen,
                           DB-Snapshot einspielen, Restart), Fehler im UI + Log
```

Das laufende Update wird über SSE im Browser mitgeschrieben. Ein Verbindungsabbruch
bricht das Update nicht ab — es läuft serverseitig als Job weiter und ist nach dem
Reconnect wieder sichtbar.

### Warum Binärtausch und nicht In-Place-Overwrite?

Unter Linux lässt sich die Datei eines laufenden Prozesses nicht sinnvoll
überschreiben (`ETXTBSY` bzw. korruptes Mapping). `rename(2)` innerhalb desselben
Dateisystems ist atomar: entweder die neue Datei liegt vollständig da oder die alte.
Ein Stromausfall mitten im Update hinterlässt damit kein halbes Binary.

## Automatische Updates

Per Default: **Sicherheitspatches automatisch, alles andere auf Bestätigung.**

```yaml
updates:
  channel: stable
  check: daily
  auto_apply: security      # none | security | patch | all
  window: "03:00-05:00"     # lokale Zeit, zufälliger Versatz innerhalb des Fensters
  reboot_if_required: false
```

Ein Control Panel, das sich nachts selbst kaputtaktualisiert, ist schlimmer als ein
veraltetes. Deshalb: Healthcheck mit automatischem Rollback ist *Voraussetzung* für
`auto_apply`, nicht Beiwerk. Und `all` ist nie der Default.

## Datenbank-Migrationen

- Eingebettet im Binary (`embed.FS`, `NNNN_beschreibung.sql`), nummeriert,
  forward-only.
- `schema_migrations`-Tabelle mit Version und Zeitstempel.
- Jede Migration läuft in einer eigenen Transaktion; Fehler bricht das Update ab und
  löst den Rollback aus.
- Keine Down-Migrationen — Rückwärtskompatibilität wird über den DB-Snapshot aus
  Schritt 5 hergestellt. Down-Migrationen sind in der Praxis selten getestet und
  erzeugen falsche Sicherheit.
- `min_upgradable_from` in den Metadaten verhindert zu große Sprünge; ein sehr alter
  Server aktualisiert dann über eine Zwischenversion.

## Release-Pipeline

GitHub Actions, ausgelöst durch ein `v*`-Tag:

```
lint (golangci-lint)  ─┐
test (go test ./...)  ─┤
govulncheck           ─┼─▶ goreleaser ─▶ Artefakte:
build-matrix          ─┘                  asylumd_<ver>_linux_{amd64,arm64}.tar.gz
   amd64, arm64                           asylum_<ver>_{amd64,arm64}.deb
                                          install.sh
                                          SHA256SUMS (+ .sig)
                                          SBOM (syft)
                                          → GitHub Release
                                          → APT-Repo-Job
                                          → repo.cloudsrv24.de/updates/<kanal>.json
```

Signiert wird mit **cosign** (keyless über OIDC, bindet die Signatur an
Repository und Workflow) und zusätzlich mit **minisign**, weil dessen Prüfung im
Installer eine einzelne kleine Binärdatei bzw. wenige Zeilen Go benötigt und keine
Rekor-Abfrage über das Netz voraussetzt.

Build-Flags: `CGO_ENABLED=0`, `-trimpath`, `-ldflags "-s -w -X main.version=…"` —
klein und reproduzierbar.

## Support-Zusage

- Sicherheitsupdates für die aktuelle Minor-Version und deren Vorgänger.
- Distributionen: alle Ubuntu-LTS und Debian-Stable, die noch Upstream-Support
  haben.
- Breaking Changes bekommen einen Eintrag in `UPGRADING.md` mit Migrationspfad —
  auch vor v1.0.
