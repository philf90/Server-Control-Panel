# Project Asylum

Ein schlankes, ressourcenschonendes Control Panel für Linux-Server (primär Ubuntu & Debian).

**A**dministration · **S**ecurity · **Y**AML · **L**ogs · **U**pdates · **M**onitoring

*Asylum* im Sinne von Zuflucht: der Ort, an dem ein Server sicher, überschaubar und
beherrschbar bleibt.

> **Status: M1 — Anmeldung und Übersicht.** Installation, TLS, systemd-Integration,
> Release-Pfad, Zwei-Faktor-Anmeldung, Rollen, Audit-Log und das Live-Dashboard
> stehen. Was noch fehlt, sind die Verwaltungsmodule selbst — Dienste, Pakete,
> Firewall, Benutzer und Logs kommen mit M2.

## Zielbild

```bash
curl -fsSL --proto '=https' --tlsv1.2 https://repo.cloudsrv24.de/install.sh -o install.sh
sudo bash install.sh
```

Der Installer gibt am Ende einen einmaligen Setup-Link aus. Dort werden
Administrator-Konto und Zwei-Faktor-Anmeldung eingerichtet — es wird bewusst kein
Passwort vergeben, das im Terminal oder in der Shell-History stünde.

Gemessen am aktuellen Stand: 13 MB Binary, 15,9 MB RSS im Leerlauf, 39 ms für eine
Anmeldung, TLS 1.3 mit selbstsigniertem Zertifikat beim ersten Start.

Keine Runtime-Abhängigkeiten. Kein Docker-Zwang. Kein PHP-Stack. Kein Node auf dem
Zielserver.

## Entwicklung

```bash
make check     # formatieren, vet, Tests mit Race-Detector
make build     # Binary nach bin/asylumd
make run       # lokal auf https://127.0.0.1:8443, Daten unter ./.local
make dist      # Release-Artefakte lokal (goreleaser --snapshot)
```

Voraussetzung ist Go 1.24 oder neuer. Fünf direkte Abhängigkeiten
(YAML, SQLite in reinem Go, Argon2, QR-Code, Terminal-Eingabe), alles Weitere ist
Standardbibliothek. TOTP ist bewusst selbst implementiert — dreißig Zeilen über
`crypto/hmac`, geprüft gegen die Testvektoren aus RFC 6238.

```
cmd/asylumd/          serve | migrate | setup-token | reset-password | version
internal/config/      Konfiguration laden, Umgebung, Validierung
internal/certs/       selbstsigniertes TLS-Material, Fingerprint
internal/store/       SQLite, Migrationen, Nutzer, Sessions, Audit
internal/auth/        Argon2id, TOTP, Tokens, Ratenbegrenzung
internal/metrics/     /proc-Sampler und Ringpuffer
internal/httpd/       Router, Middleware, Handler, SSE
internal/systemd/     sd_notify und Watchdog ohne cgo
internal/ui/          Templates und Assets (embed)
packaging/            install.sh, systemd-Unit, .deb-Skripte
```

### Ersteinrichtung von Hand

```bash
sudo asylum setup-token          # einmaliger Link, 60 Minuten gültig
sudo asylum reset-password NAME  # Rettungsweg, wenn der Zugang verloren ist
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
