# 06 — Roadmap & offene Entscheidungen

## Getroffene Entscheidungen

| Punkt | Entscheidung | Detail |
|---|---|---|
| **Scope** | **A — Server-Administrations-Panel.** Kein Hosting-Panel; vHosts, PHP, Mail und DNS bleiben Nicht-Ziele bzw. spätere optionale Module. | [03-funktionsumfang.md](03-funktionsumfang.md) |
| **Sprache** | Go, statisches Single Binary | [01-sprachwahl.md](01-sprachwahl.md) |
| **Lizenz** | Apache-2.0 (`LICENSE` im Root) | [07-name-lizenz-domain.md](07-name-lizenz-domain.md#lizenz-apache-20) |
| **Name** | **Project Asylum** — CLI `asylum`, Daemon `asylumd` | [07-name-lizenz-domain.md](07-name-lizenz-domain.md#name-project-asylum) |
| **Domain** | `repo.cloudsrv24.de` auf GitHub Pages, ein Host für Installer, Update-Metadaten und APT-Repo | [07-name-lizenz-domain.md](07-name-lizenz-domain.md#domain-repocloudsrv24de) |

## Offene Punkte

Keiner davon blockiert M0.

- **Namensprüfungen abschließen:** Debian-Kommandokollision, Paket-Namensräume,
  Marken in Klasse 9/42 — Befehle in
  [07-name-lizenz-domain.md](07-name-lizenz-domain.md#verbleibende-prüfschritte).
- **DNS setzen und GitHub Pages aktivieren:** CNAME, Domain-Verifizierung,
  „Enforce HTTPS" — siehe
  [07-name-lizenz-domain.md](07-name-lizenz-domain.md#dns-konfiguration).
- **Copyright-Zeile in `LICENSE`** auf den endgültigen Rechtsträger anpassen.

---

## Meilensteine

### M0 — Grundgerüst (1 Woche)
Go-Modul, Repo-Layout, CI (lint/test/build), GoReleaser mit Tarball und `.deb`,
`install.sh` mit Signaturprüfung, systemd-Unit, `asylumd serve` liefert eine leere
Seite über TLS aus. **Ergebnis: der One-Line-Install funktioniert — ohne Features.**

Diese Reihenfolge ist bewusst gewählt: Deployment und Update-Pfad sind die
schwierigsten Teile eines Panels und werden am häufigsten zu spät gebaut. Wer sie
zuerst hat, kann jedes Feature ab Tag eins real ausliefern.

**Stand: umgesetzt.** Gemessen am gebauten Binary:

| Kennzahl | Grenze | Ist |
|---|---|---|
| Binärgröße | < 30 MB | 8,4 MB |
| RSS im Leerlauf | < 40 MB | 10,2 MB |
| Direkte Abhängigkeiten | < 25 | 1 (`gopkg.in/yaml.v3`) |

Verifiziert wurde außerdem: HTTP/2 über TLS 1.3, Erzeugung und Wiederverwendung des
selbstsignierten Zertifikats, Sicherheitsheader, `/healthz` mit Versionsangabe,
geordnetes Herunterfahren auf SIGTERM. Offen bis zum ersten Tag: der
minisign-Schlüssel muss erzeugt, als `MINISIGN_KEY`-Secret hinterlegt und der
Public Key in `packaging/install.sh` eingesetzt werden — bis dahin verweigert der
Installer die Installation, sofern nicht ausdrücklich
`ASYLUM_SKIP_SIGNATURE=1` gesetzt wird.

### M1 — Auth & Dashboard (2 Wochen)
SQLite mit Migrationen, Argon2id, TOTP, Sessions, CSRF, RBAC, Audit-Log,
Setup-Token-Flow, Metriken-Sampler mit Ringpuffer, Dashboard mit SSE.

**Stand: umgesetzt.**

| Kennzahl | Grenze | Ist |
|---|---|---|
| Binärgröße | < 30 MB | 13 MB |
| RSS im Leerlauf | < 40 MB | 15,9 MB |
| RSS nach Anmeldungen | < 40 MB | 15,9 MB (siehe [02](02-architektur.md#argon2-und-das-speicherbudget)) |
| Anmeldedauer | — | 39 ms |
| Direkte Abhängigkeiten | < 25 | 5 |

Der vollständige Ablauf wurde gegen die laufende Instanz geprüft: Setup-Token →
Kontoanlage → TOTP-Einrichtung mit QR-Code → Wiederherstellungscodes → Abmelden →
Anmelden mit zweitem Faktor → Live-Metriken über SSE. Der TOTP-Code für die Prüfung
stammte aus einer unabhängigen Python-Implementierung, die Go-Seite hat ihn
akzeptiert; zusätzlich laufen die Testvektoren aus RFC 6238 in der Testsuite.

Zwei Dinge wurden dabei gefunden und behoben:

- **Der Live-Kanal war tot.** Die Logging-Middleware umhüllt den `ResponseWriter`,
  und die Hülle war kein `http.Flusher` — Server-Sent Events liefen deshalb in einen
  Fehler. Behoben über `http.NewResponseController` und eine `Unwrap`-Methode; ein
  Regressionstest läuft nun bewusst durch die vollständige Middleware-Kette, weil
  ein Test direkt gegen den Handler die Lücke nicht gesehen hätte.
- **Die Grundlast lag bei 80 MB** statt der zugesagten 40. Ursache und Lösung stehen
  in [02-architektur.md](02-architektur.md#argon2-und-das-speicherbudget).

Offen aus M1: Wechsel des zweiten Faktors im laufenden Betrieb (aktuell nur über
`asylum reset-password`), und eine Ansicht der eigenen aktiven Sitzungen.

### M2 — Systemmodule (3 Wochen)
`privops.Executor`, Dienste (systemd/D-Bus), Pakete (apt), Firewall (nftables)
inklusive Lockout-Schutz, Benutzer & SSH-Keys, journald-Logs.

### M3 — Update-Mechanik (1 Woche)
Kanäle, Metadatenformat, Selbstupdate mit Healthcheck und Rollback,
`asylum update` / `asylum rollback`, APT-Repository-Job in der Pipeline.

### M4 — v0.1.0 Public Beta (1 Woche)
Dokumentation, Screenshots, Landingpage, `SECURITY.md`, Issue-Templates,
Contribution-Guide, externer Sicherheits-Review der Auth- und Update-Pfade.

**Summe bis zur nutzbaren Beta: ~8 Wochen** für eine Vollzeit-Person, entsprechend
länger nebenberuflich. Danach v0.2 (Dateimanager, Cron, Terminal,
Benachrichtigungen) und v0.3 (Module).

## Qualitätsziele als harte Grenzen

Diese Werte gehören in die CI, nicht in ein Wiki:

| Metrik | Grenze |
|---|---|
| RSS im Leerlauf | < 40 MB |
| CPU im Leerlauf | < 0,5 % auf 1 vCPU |
| Binärgröße | < 30 MB |
| Kaltstart bis Ready | < 1 s |
| Installationsdauer | < 30 s auf einem 1-vCPU-VPS |
| Direkte Go-Abhängigkeiten | < 25 |
| Testabdeckung `auth`, `privops`, `update` | > 80 % |

Ein Benchmark-Job misst RSS und Binärgröße bei jedem Release und lässt den Build
fehlschlagen, wenn eine Grenze gerissen wird. Ohne diesen Zwang wird aus "schlank"
innerhalb eines Jahres ein Marketingbegriff.

## Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Aussperrung durch Firewall-/SSH-Änderung | 60-Sekunden-Bestätigung mit automatischem Rollback, `scp` CLI als lokaler Rettungsweg |
| Panel als Angriffsziel im offenen Netz | 2FA-Pflicht, Rate-Limiting, Empfehlung zu Bind auf localhost/WireGuard, signierte Updates, schlanke Angriffsfläche |
| Feature-Creep Richtung Hosting-Panel | Nicht-Ziele dokumentiert, Modulgrenze, harte Ressourcenbudgets in der CI |
| Konflikt mit manueller Serverkonfiguration | Managed-Marker, Drop-in-Dateien, Konflikterkennung per Hash, Backups vor jedem Schreibvorgang |
| Ein-Personen-Projekt versandet | Früh Beta veröffentlichen, kleiner Kern, saubere Modulschnittstelle für Beiträge |
