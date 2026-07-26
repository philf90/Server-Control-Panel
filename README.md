# Server Control Panel

Ein schlankes, ressourcenschonendes Control Panel für Linux-Server (primär Ubuntu & Debian).

> **Status: Konzeptphase.** Dieses Repository enthält aktuell nur die technische
> Konzeption. Es gibt noch keinen lauffähigen Code. Ziel dieser Phase ist es,
> Sprachwahl, Funktionsumfang, Setup- und Update-Mechanik festzuzurren, bevor die
> erste Zeile Produktivcode entsteht.

## Zielbild

```bash
curl -fsSL https://get.example.org/install.sh -o install.sh
sudo bash install.sh
```

Nach ca. 20 Sekunden: ein einzelner, statisch gelinkter Daemon (~15–25 MB RSS im
Leerlauf), als systemd-Service eingerichtet, erreichbar unter
`https://<server-ip>:8443` mit generiertem Admin-Passwort und aktiviertem 2FA-Setup.

Keine Runtime-Abhängigkeiten. Kein Docker-Zwang. Kein PHP-Stack. Kein Node auf dem
Zielserver.

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

## Stand der Entscheidungen

| Punkt | Stand |
|---|---|
| Scope | **Server-Administrations-Panel** — kein Hosting-Panel (kein Mail, kein DNS, keine Kundenverwaltung) |
| Sprache | **Go**, statisches Single Binary |
| Lizenz | **Apache-2.0** |
| Name | offen — Empfehlung `Pult`, Kandidaten in [docs/07](docs/07-name-lizenz-domain.md#name). `scp`/`scpd` in der Doku ist ein Platzhalter |
| Domain | offen — Empfehlung `<name>.dev` mit `get.` / `updates.` / `apt.` |

## Lizenz

[Apache-2.0](LICENSE). Beiträge per DCO (`git commit -s`); ein CLA ist nicht
vorgesehen.
