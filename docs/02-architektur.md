# 02 — Architektur

## Prozessmodell

Ein Control Panel muss privilegierte Aktionen ausführen (Pakete installieren,
Dienste steuern, Benutzer anlegen). Gleichzeitig exponiert es einen HTTP-Server ins
Netz. Das sind zwei Dinge, die man ungern im selben Prozess hat.

### Entscheidung für den MVP: ein Daemon, aber mit sauberer Trennlinie im Code

```
                        ┌──────────────────────────────┐
   Browser  ──TLS──▶    │ asylumd (root, systemd-härtet)│
                        │                              │
                        │  ┌────────────────────────┐  │
                        │  │ HTTP-Layer / Templates │  │
                        │  ├────────────────────────┤  │
                        │  │ Service-Layer          │  │
                        │  ├────────────────────────┤  │
                        │  │ privops.Executor  ◀────┼──┼─ einzige Stelle mit
                        │  └────────────────────────┘  │   Systemzugriff
                        └──────────────┬───────────────┘
                                       │
                     systemd (D-Bus) · apt · nftables · /etc · journald
```

Sämtliche privilegierten Operationen laufen über ein einziges Interface
(`privops.Executor`). Dieses Interface kennt **keine freien Shell-Kommandos**,
sondern nur typisierte Operationen:

```go
type Executor interface {
    ServiceAction(ctx context.Context, unit string, action Action) error
    PackageUpgrade(ctx context.Context, opts UpgradeOpts) (*Report, error)
    UserCreate(ctx context.Context, spec UserSpec) error
    FirewallApply(ctx context.Context, ruleset Ruleset) error
    // ...
}
```

Kein Aufrufer baut jemals einen Kommandostring aus Benutzereingaben. Argumente
werden als `[]string` an `exec.CommandContext` übergeben, niemals über eine Shell.
Unit-Namen, Paketnamen und Benutzernamen werden gegen strikte Allowlists/Regexes
validiert, bevor sie den Executor erreichen.

**Warum trotzdem ein Prozess?** Zwei Prozesse mit IPC verdoppeln die Komplexität im
MVP. Da der gesamte Systemzugriff hinter einem Interface liegt, lässt sich der
zweite Schritt später ohne Rewrite gehen:

### Ausbaustufe: Privilege Separation (ab v0.4 geplant)

```
asylumd-web  (User asylum, unprivilegiert)  ──unix socket──▶  asylumd-agent (root, minimal)
```

Der Web-Prozess verliert damit root komplett. Der Agent implementiert dasselbe
`privops.Executor`-Interface, nur über RPC — für den Rest des Codes ändert sich
nichts.

### systemd-Hardening für den MVP

```ini
[Service]
Type=notify
ExecStart=/usr/local/lib/asylum/asylumd serve
NoNewPrivileges=no          # apt/useradd brauchen setuid-Aufrufe
ProtectSystem=full
ProtectHome=read-only
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=no
RestrictNamespaces=yes
LockPersonality=yes
MemoryDenyWriteExecute=yes
RestrictRealtime=yes
SystemCallArchitectures=native
MemoryMax=256M
TasksMax=256
Restart=on-failure
WatchdogSec=30s
```

`MemoryMax` ist Absicherung *und* Selbstverpflichtung: das Panel darf nicht
unbemerkt fett werden.

## Umgang mit Konfigurationsdateien

Das häufigste Ärgernis bestehender Panels: sie überschreiben handgepflegte Configs.

**Regeln:**

1. Panel-verwaltete Abschnitte werden markiert:
   ```
   # >>> managed by asylum (id: firewall-base) >>>
   ...
   # <<< managed by asylum <<<
   ```
2. Alles außerhalb der Marker wird nie angefasst.
3. Wo möglich: eigene Drop-in-Dateien statt Änderungen an Distributionsdateien
   (`/etc/ssh/sshd_config.d/`, `/etc/systemd/system/<unit>.d/`, `/etc/sysctl.d/`).
4. Vor jeder Schreiboperation: Backup nach `/var/lib/asylum/backups/<ts>/<pfad>`.
5. Nach jeder Änderung: Validierung vor dem Reload (`sshd -t`, `nft -c -f`,
   `nginx -t`). Schlägt sie fehl → automatisches Rollback, Fehler im UI.
6. Wurde eine verwaltete Datei außerhalb des Panels geändert (Hash-Vergleich),
   zeigt das UI einen Konflikt an, statt die Änderung stillschweigend zu verwerfen.

## Datenhaltung

```
/usr/local/lib/asylum/asylumd    Binary (root:root 0755)
/usr/local/bin/asylum            Symlink auf das Binary (CLI-Modus)
/etc/asylum/config.yaml          Konfiguration (root:asylum 0640)
/etc/asylum/tls/                 Zertifikate
/var/lib/asylum/asylum.db        SQLite (0600)
/var/lib/asylum/backups/         Config-Backups
/var/log/asylum/audit.log        Audit-Log (append-only, logrotate)
/var/lib/asylum/releases/        vorheriges Binary für Rollback
```

**SQLite** genügt vollständig: Nutzer, Sessions, Rollen, Audit-Log, Einstellungen,
Job-Historie. WAL-Modus, `busy_timeout`. Kein externer DB-Server als Abhängigkeit.

**Metriken** werden *nicht* dauerhaft in SQLite geschrieben. Für Live-Ansichten
reicht ein Ringpuffer im RAM (z. B. 24 h in 30-s-Auflösung ≈ wenige MB). Wer echte
Langzeit-Metriken will, exportiert nach Prometheus — dafür gibt es bessere Tools als
ein Control Panel.

### Argon2 und das Speicherbudget

Die beiden Zusagen „Argon2id mit ordentlichen Parametern" und „unter 40 MB im
Leerlauf" stehen sich im Weg: Jede Passwortprüfung belegt kurzzeitig die volle
Argon2-Speichermenge, und Go gibt freigewordene Heap-Bereiche nur zögerlich an das
Betriebssystem zurück. Ohne Gegenmaßnahme bleibt die Grundlast nach der ersten
Anmeldung dauerhaft erhöht — gemessen 80 MB statt 16 MB.

Drei Entscheidungen lösen das, ohne die Passwortsicherheit anzutasten:

1. **32 MiB statt der oft zitierten 64 MiB.** Immer noch deutlich über der
   OWASP-Mindestempfehlung von 19 MiB.
2. **Berechnungen werden serialisiert.** Der Spitzenbedarf ist damit genau einmal
   die Argon2-Menge, unabhängig von der Zahl gleichzeitiger Anmeldeversuche. Ohne
   diese Schranke wäre die Anmeldeseite ein bequemer Speicher-Erschöpfungsangriff.
3. **Nach jeder Berechnung wird der Speicher aktiv zurückgegeben.** Als Lastangriff
   taugt das nicht: Die Argon2-Berechnung selbst kostet ein Vielfaches eines
   Sammellaufs, und die Ratenbegrenzung deckelt die Versuche zusätzlich.

Ergebnis: 16 MB Grundlast, die auch nach Anmeldungen dort bleibt.

## Sicherheitsgrundlagen

| Bereich | Umsetzung |
|---|---|
| Passwörter | Argon2id (`m=32MiB, t=3, p=2`), serialisiert, kein SHA-basierter Fallback |
| 2FA | TOTP, beim ersten Login erzwungen; Recovery-Codes einmalig anzeigbar |
| Sessions | HttpOnly, Secure, SameSite=Strict, serverseitig in SQLite, absolute + idle Expiry |
| CSRF | Double-Submit-Token für alle mutierenden Requests |
| Brute Force | Rate-Limit pro IP und pro Account, exponentielles Lockout |
| Transport | TLS erzwungen; self-signed beim Setup, ACME/Let's Encrypt per Klick |
| Exposure | Optionale Bindung auf `127.0.0.1` bzw. WireGuard-Interface, empfohlen für Produktivsysteme |
| Autorisierung | Rollen (Owner / Admin / Operator / ReadOnly), serverseitig geprüft |
| Audit | Jede mutierende Aktion mit Nutzer, IP, Ziel, Ergebnis, Zeitstempel |
| Supply Chain | Signierte Releases (cosign/minisign), SHA256SUMS, SBOM, reproduzierbare Builds |

## Repository-Layout

```
.
├── cmd/asylumd/                 main(): serve | install | update | version | reset-password
├── internal/
│   ├── httpd/                Router, Middleware, Handler
│   ├── ui/                   Templates + statische Assets (embed.FS)
│   ├── auth/                 Passwörter, Sessions, TOTP, RBAC
│   ├── privops/              privilegierte Operationen (einziger Systemzugriff)
│   ├── modules/
│   │   ├── metrics/  services/  packages/  firewall/
│   │   ├── users/    logs/      files/     cron/
│   ├── store/                SQLite, Migrationen (embedded)
│   ├── audit/
│   └── update/               Selbstupdate, Signaturprüfung, Rollback
├── packaging/
│   ├── systemd/  nfpm/  install.sh
├── docs/
└── .github/workflows/
```

Jedes Modul registriert seine Routen und Navigationseinträge selbst. Ein Modul
abzuschalten (Config-Flag) entfernt Routen *und* Rechte — nicht nur den Menüpunkt.
