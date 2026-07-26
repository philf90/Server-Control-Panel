# 06 — Roadmap & offene Entscheidungen

## Offene Entscheidungen

Diese vier Punkte sollten vor dem ersten Produktivcode geklärt sein, weil sie
schwer nachträglich zu ändern sind.

### 1. Scope

Systemadministrations-Panel (Empfehlung) oder Hosting-Panel mit vHosts, PHP, Mail
und DNS? Siehe [03-funktionsumfang.md](03-funktionsumfang.md#scope-entscheidung-zuerst).
Die Entscheidung bestimmt Zielgruppe, Aufwand und die Frage, ob "schlank" als
Versprechen haltbar bleibt.

### 2. Name

`scp` als Binary-Name kollidiert mit `scp(1)` aus OpenSSH — das ist ein echtes
Problem, kein kosmetisches. Der Platzhalter in dieser Dokumentation muss ersetzt
werden. Kriterien: nicht belegt in Debian/Ubuntu, freier GitHub-Org-Name, freie
`.org`- oder `.dev`-Domain, 3–8 Zeichen.

### 3. Lizenz

| Option | Wirkung |
|---|---|
| **Apache-2.0** (Empfehlung) | Maximale Verbreitung, Patentklausel, unproblematisch für Firmen |
| **AGPL-3.0** | Schützt vor SaaS-Weiterverwertung ohne Rückfluss, schreckt aber Firmennutzer und damit Contributors ab |
| **BSL / Fair-Source** | Nur sinnvoll bei geplanter Kommerzialisierung; kostet Community-Vertrauen |

Bei einem Infrastruktur-Tool, das von Verbreitung lebt, wiegt der Verbreitungs­vorteil
von Apache-2.0 schwerer als der Schutz der AGPL.

### 4. Domain

Gebraucht werden drei Hostnamen (können auf demselben statischen Hosting liegen):
`get.<domain>` (Installer), `updates.<domain>` (Kanal-Metadaten),
`apt.<domain>` (Paket-Repository).

---

## Meilensteine

### M0 — Grundgerüst (1 Woche)
Go-Modul, Repo-Layout, CI (lint/test/build), GoReleaser mit Tarball und `.deb`,
`install.sh` mit Signaturprüfung, systemd-Unit, `scpd serve` liefert eine leere
Seite über TLS aus. **Ergebnis: der One-Line-Install funktioniert — ohne Features.**

Diese Reihenfolge ist bewusst gewählt: Deployment und Update-Pfad sind die
schwierigsten Teile eines Panels und werden am häufigsten zu spät gebaut. Wer sie
zuerst hat, kann jedes Feature ab Tag eins real ausliefern.

### M1 — Auth & Dashboard (2 Wochen)
SQLite mit Migrationen, Argon2id, TOTP, Sessions, CSRF, RBAC, Audit-Log,
Setup-Token-Flow, Metriken-Sampler mit Ringpuffer, Dashboard mit SSE.

### M2 — Systemmodule (3 Wochen)
`privops.Executor`, Dienste (systemd/D-Bus), Pakete (apt), Firewall (nftables)
inklusive Lockout-Schutz, Benutzer & SSH-Keys, journald-Logs.

### M3 — Update-Mechanik (1 Woche)
Kanäle, Metadatenformat, Selbstupdate mit Healthcheck und Rollback,
`scp update` / `scp rollback`, APT-Repository-Job in der Pipeline.

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
