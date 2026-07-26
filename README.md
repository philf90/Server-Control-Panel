# Project Asylum

Ein schlankes, ressourcenschonendes Control Panel für Linux-Server (primär Ubuntu & Debian).

**A**dministration · **S**ecurity · **Y**AML · **L**ogs · **U**pdates · **M**onitoring

*Asylum* im Sinne von Zuflucht: der Ort, an dem ein Server sicher, überschaubar und
beherrschbar bleibt.

> **Status: M0 — Grundgerüst.** Installation, TLS, systemd-Integration und der
> Release-Pfad stehen und sind lauffähig. Verwaltungsfunktionen gibt es noch
> keine: Anmeldung, Rollen und das Dashboard kommen mit M1. Diese Reihenfolge ist
> Absicht — Deployment und Update sind die schwierigsten Teile eines Panels und
> werden sonst zu spät gebaut.

## Zielbild

```bash
curl -fsSL --proto '=https' --tlsv1.2 https://repo.cloudsrv24.de/install.sh -o install.sh
sudo bash install.sh
```

Danach: ein einzelner, statisch gelinkter Daemon, als systemd-Service eingerichtet
und über HTTPS erreichbar. Gemessen am aktuellen Stand — 8,4 MB Binary, 10,2 MB RSS
im Leerlauf, TLS 1.3 mit selbstsigniertem Zertifikat beim ersten Start.

Keine Runtime-Abhängigkeiten. Kein Docker-Zwang. Kein PHP-Stack. Kein Node auf dem
Zielserver.

## Entwicklung

```bash
make check     # formatieren, vet, Tests mit Race-Detector
make build     # Binary nach bin/asylumd
make run       # lokal auf https://127.0.0.1:8443, Daten unter ./.local
make dist      # Release-Artefakte lokal (goreleaser --snapshot)
```

Voraussetzung ist Go 1.24 oder neuer. Eine einzige direkte Abhängigkeit
(`gopkg.in/yaml.v3`), alles Weitere ist Standardbibliothek.

```
cmd/asylumd/          Einstiegspunkt: serve | migrate | version
internal/config/      Konfiguration laden, Umgebung, Validierung
internal/certs/       selbstsigniertes TLS-Material, Fingerprint
internal/httpd/       Router, Middleware, Handler
internal/systemd/     sd_notify und Watchdog ohne cgo
internal/ui/          Templates und Assets (embed)
packaging/            install.sh, systemd-Unit, .deb-Skripte
```

## Leitplanken

| Prinzip | Bedeutung |
|---|---|
| **Ein Binary** | Alles (Backend, Frontend-Assets, Migrationen, CLI) in einer Datei. |
| **Additiv, nicht besitzergreifend** | Das Panel übernimmt den Server nicht. Es schreibt in klar markierte, eigene Config-Blöcke und respektiert manuelle Änderungen. |
| **Nichts verstecken** | Jede Aktion des Panels ist eine nachvollziehbare Systemaktion (systemd, apt, nftables) — kein proprietäres Parallel-Universum. |
| **Sicher per Default** | Argon2id, TOTP-2FA, CSRF, Rate-Limiting, Audit-Log, signierte Releases. |
| **Klein bleiben** | Feature-Wünsche gehören in Module/Plugins, nicht in den Kern. |

## Dokumentation

| Dokument | Inhalt |
|---|---|
| [docs/01-sprachwahl.md](docs/01-sprachwahl.md) | Sprachvergleich und Begründung der Empfehlung |
| [docs/02-architektur.md](docs/02-architektur.md) | Prozessmodell, Rechtetrennung, Datenhaltung, Repo-Layout |
| [docs/03-funktionsumfang.md](docs/03-funktionsumfang.md) | MVP-Scope, Ausbaustufen, bewusste Nicht-Ziele |
| [docs/04-setup.md](docs/04-setup.md) | One-Line-Installer, APT-Repository, Deinstallation |
| [docs/05-updates.md](docs/05-updates.md) | Release-Kanäle, Update-Wege, Migrationen, Rollback |
| [docs/06-roadmap.md](docs/06-roadmap.md) | Meilensteine und offene Entscheidungen |
| [docs/07-name-lizenz-domain.md](docs/07-name-lizenz-domain.md) | Namensfindung, Lizenzfolgen, Projekt-Domain |
| [docs/08-runbook-domain.md](docs/08-runbook-domain.md) | Runbook: DNS-Verifizierung und GitHub Pages einrichten |

## Stand der Entscheidungen

| Punkt | Stand |
|---|---|
| Scope | **Server-Administrations-Panel** — kein Hosting-Panel (kein Mail, kein DNS, keine Kundenverwaltung) |
| Sprache | **Go**, statisches Single Binary |
| Lizenz | **Apache-2.0** |
| Name | **Project Asylum** — CLI `asylum`, Daemon `asylumd`, Paket `asylum` |
| Domain | **`repo.cloudsrv24.de`** auf GitHub Pages — Installer, Update-Metadaten und APT-Repo unter einem Host |

## Lizenz

[Apache-2.0](LICENSE). Beiträge per DCO (`git commit -s`); ein CLA ist nicht
vorgesehen.
