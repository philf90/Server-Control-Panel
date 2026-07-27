# Project Asylum

Ein schlankes, ressourcenschonendes Control Panel für Linux-Server (primär Ubuntu & Debian).

**A**dministration · **S**ecurity · **Y**AML · **L**ogs · **U**pdates · **M**onitoring

*Asylum* im Sinne von Zuflucht: der Ort, an dem ein Server sicher, überschaubar und
beherrschbar bleibt.

> **Status: M3 — Update-Mechanik.** Installation, TLS, Release-Pfad,
> Zwei-Faktor-Anmeldung, Rollen, Audit-Log, Live-Dashboard, die Verwaltung von
> Diensten, Paketen, Firewall, Systembenutzern und Logs sowie das signierte
> Selbstupdate mit Healthcheck und selbsttätigem Rollback stehen. Als Nächstes
> folgt M4: Dokumentation, `SECURITY.md`, externer Review — die Public Beta.

## Zielbild

```bash
curl -fsSL --proto '=https' --tlsv1.2 https://repo.cloudsrv24.de/install.sh -o install.sh
sudo bash install.sh
```

Der Installer gibt am Ende einen einmaligen Setup-Link aus. Dort werden
Administrator-Konto und Zwei-Faktor-Anmeldung eingerichtet — es wird bewusst kein
Passwort vergeben, das im Terminal oder in der Shell-History stünde.

Gemessen am aktuellen Stand: 14 MB Binary, 15,9 MB RSS im Leerlauf, 39 ms für eine
Anmeldung, TLS 1.3 mit selbstsigniertem Zertifikat beim ersten Start.

Aktualisiert wird über das Panel oder mit `sudo asylum update`: Signatur gegen den
im Binary eingebauten Schlüssel, atomarer Tausch, Neustart, Bereitschaftsprüfung —
und ohne Antwort binnen einer Minute stellt der Server von allein die vorherige
Fassung wieder her.

Keine Runtime-Abhängigkeiten. Kein Docker-Zwang. Kein PHP-Stack. Kein Node auf dem
Zielserver.

## So sieht es aus

Die Übersicht: Auslastung, Dateisysteme, Netzwerk und die größten Prozesse, alle
zwei Sekunden über einen Live-Kanal aktualisiert.

![Übersicht](docs/bilder/uebersicht.png)

Die Update-Seite. Was hier steht, ist auch das Versprechen: geprüfte Signatur,
atomarer Tausch, und ohne Antwort binnen einer Minute stellt der Server von
allein die vorherige Fassung wieder her.

![Updates](docs/bilder/updates.png)

Die Kontoseite mit den eigenen offenen Sitzungen. Ein entwendetes Cookie
hinterlässt sonst keine Spur, die dem Betroffenen auffiele.

![Konto](docs/bilder/konto.png)

Auf dem Telefon wird aus jeder Tabellenzeile eine Karte — ein Server-Panel wird
genau dann gebraucht, wenn man nicht am Schreibtisch sitzt.

<img src="docs/bilder/schmal.png" alt="Systembenutzer auf einem Telefon" width="320">

Weitere Ansichten: [Dienste](docs/bilder/dienste.png) ·
[Firewall](docs/bilder/firewall.png) · [Audit-Log](docs/bilder/audit.png)

> Die Bilder stammen aus einem Container ohne systemd — Dienste, Pakete und
> Firewall zeigen dort ihre Fehlerbehandlung statt echter Daten. Auf einem
> Server sieht das entsprechend voller aus.

## Entwicklung

```bash
make check     # formatieren, vet, Tests mit Race-Detector
make build     # Binary nach bin/asylumd
make run       # lokal auf https://127.0.0.1:8443, Daten unter ./.local
make dist      # Release-Artefakte lokal (goreleaser --snapshot)
```

Die benötigte Go-Fassung steht in `go.mod` und gilt als Untergrenze. Fünf direkte
Abhängigkeiten (YAML, SQLite in reinem Go, `x/crypto` für Argon2 und BLAKE2b,
QR-Code, Terminal-Eingabe), alles Weitere ist Standardbibliothek. TOTP ist bewusst selbst implementiert — dreißig Zeilen über
`crypto/hmac`, geprüft gegen die Testvektoren aus RFC 6238.

```
cmd/asylumd/          serve | migrate | setup-token | reset-password
                      | update | rollback | version
internal/config/      Konfiguration laden, Umgebung, Validierung
internal/certs/       selbstsigniertes TLS-Material, Fingerprint
internal/store/       SQLite, Migrationen, Nutzer, Sessions, Audit
internal/auth/        Argon2id, TOTP, Tokens, Ratenbegrenzung
internal/metrics/     /proc-Sampler und Ringpuffer
internal/privops/     einzige Stelle mit Systemzugriff (systemd, apt, ufw, …)
internal/update/      Signaturprüfung, Download, Austausch, Rückweg
internal/httpd/       Router, Middleware, Handler, SSE
internal/systemd/     sd_notify und Watchdog ohne cgo
internal/ui/          Templates und Assets (embed)
packaging/            install.sh, systemd-Unit, .deb-Skripte
```

### Ersteinrichtung von Hand

```bash
sudo asylum setup-token          # einmaliger Link, 60 Minuten gültig
sudo asylum reset-password NAME  # Rettungsweg, wenn der Zugang verloren ist
sudo asylum update --check       # nachsehen, ob etwas anliegt
sudo asylum rollback             # zurück auf die vorherige Fassung
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
| [docs/09-sicherheitsbetrachtung.md](docs/09-sicherheitsbetrachtung.md) | Anmelde- und Updatepfade: Angreifermodell, Abwägungen, offene Punkte |

Dazu im Wurzelverzeichnis: [SECURITY.md](SECURITY.md) (Schwachstellen melden),
[CONTRIBUTING.md](CONTRIBUTING.md), [CHANGELOG.md](CHANGELOG.md) und
[UPGRADING.md](UPGRADING.md).

## Stand der Entscheidungen

| Punkt | Stand |
|---|---|
| Scope | **Server-Administrations-Panel** — kein Hosting-Panel (kein Mail, kein DNS, keine Kundenverwaltung) |
| Sprache | **Go**, statisches Single Binary |
| Lizenz | **Apache-2.0** |
| Name | **Project Asylum** — CLI `asylum`, Daemon `asylumd`, Debian-Paket `asylum-panel` (`asylum` ist dort an ein Spiel vergeben) |
| Domain | **`repo.cloudsrv24.de`** auf GitHub Pages — Installer, Update-Metadaten und APT-Repo unter einem Host |

## Mitarbeiten

Fehlerberichte und Beiträge sind willkommen — [CONTRIBUTING.md](CONTRIBUTING.md)
sagt, was ein Pull Request erfüllen muss, damit niemand Arbeit investiert, die an
einer ungeschriebenen Regel scheitert.

**Eine Sicherheitslücke gehört nicht in ein Issue**, sondern in den privaten
Kanal aus [SECURITY.md](SECURITY.md). Dieses Panel läuft mit root-Rechten; ein
offener Bericht ist eine Anleitung für alle, die noch nicht aktualisiert haben.

## Lizenz

[Apache-2.0](LICENSE). Beiträge per DCO (`git commit -s`); ein CLA ist nicht
vorgesehen.
