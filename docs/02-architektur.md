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

Drei Umsetzungsdetails, die in der Praxis den Unterschied machen:

- **Kommando-Allowlist mit absoluten Pfaden.** Nur eine feste Liste von
  Programmen ist aufrufbar, und der Pfad wird nicht über `$PATH` gesucht — ein
  manipuliertes PATH-Element wäre sonst ein direkter Weg zu Codeausführung als
  root.
- **Feste Umgebung.** `LC_ALL=C` hält die Ausgabe in der Sprache, deren Format
  die Parser kennen; auf einem deutsch eingestellten Server scheiterte das
  Parsen sonst.
- **Gedeckelte Ausgabe.** Ein Kommando mit endloser Ausgabe kann den Speicher
  des Panels nicht füllen; jenseits von 4 MiB wird gekürzt und das kenntlich
  gemacht.

### Abweichung von der ursprünglichen Planung: systemctl statt D-Bus

Die Konzeption sah den Zugriff auf systemd über D-Bus vor. Umgesetzt ist der
Aufruf von `systemctl` mit `--output=json`. Gründe: keine zusätzliche
Abhängigkeit, dieselben Daten, und jede Aktion des Panels bleibt eine
nachvollziehbare Kommandozeile — was zum Grundsatz „nichts verstecken" besser
passt als ein Methodenaufruf auf einem Bus. Die Spaltenansicht von `systemctl`
wird bewusst nicht geparst: Unit-Beschreibungen enthalten Leerzeichen, und ein
Spaltenparser bricht an der ersten Beschreibung, die nach einem Statuswort
aussieht.

**Warum trotzdem ein Prozess?** Zwei Prozesse mit IPC verdoppeln die Komplexität im
MVP. Da der gesamte Systemzugriff hinter einem Interface liegt, lässt sich der
zweite Schritt später ohne Rewrite gehen:

### Der Update-Vorgang läuft außerhalb des Dienstes

Das Selbstupdate ist die einzige Operation, die den eigenen Prozess beendet.
systemd beendet beim Stop einer Unit deren **gesamte Kontrollgruppe** — ein
Update, das darin liefe, würde genau zwischen dem Austausch des Binaries und der
Bereitschaftsprüfung abgeschnitten. Zurück bliebe eine ungeprüfte neue Fassung
ohne jemanden, der sie im Zweifel zurücknimmt.

Deshalb setzt das Panel den Lauf über `systemd-run --unit=asylum-update-… --collect`
als eigene Transient-Unit ab, und `asylum update` prüft vor dem ersten
Schreibzugriff selbst nach, ob es in der Kontrollgruppe des Dienstes läuft
(`/proc/self/cgroup` gegen `systemctl show asylumd --property=ControlGroup`).
Trifft das zu, weigert es sich mit einer erklärenden Meldung.

Die Folge für die Oberfläche: Ein offener SSE-Kanal überlebt den Neustart nicht.
Der Update-Lauf schreibt darum in `/var/log/asylum/update.log`, und die
Update-Seite fragt nach dem Neustart wieder ab, statt auf eine bestehende
Verbindung zu bauen. Einzelheiten in [05-updates.md](05-updates.md).

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
ProtectSystem=no            # apt schreibt nach /usr — siehe unten
ReadOnlyPaths=-/boot -/efi  # was davon übrig bleibt und wirklich gilt
ProtectHome=false           # der Dateimanager arbeitet in /home und /root
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
MemoryMax=768M
TasksMax=256
Restart=on-failure
WatchdogSec=30s
```

`MemoryMax` ist Absicherung *und* Selbstverpflichtung: das Panel darf nicht
unbemerkt fett werden — gemessen liegt es bei rund 20 MB RSS. Die Zahl steht
trotzdem auf 768M, weil das Limit für die ganze Kontrollgruppe gilt und apt und
dpkg darin laufen (seit 0.6.1, siehe unten).

**Warum `ProtectSystem` nicht auf `full` steht (seit 0.3.0).** `full` stellt
neben `/usr` und `/boot` auch `/etc` auf read-only, `ProtectHome=read-only`
zusätzlich `/home` und `/root`. Ein Dateimanager, der Konfigurationsdateien
bearbeiten soll, scheitert damit an jedem Schreibversuch — und zwar mit `EROFS`,
was am Verzeichnis selbst nicht zu erkennen ist.

**Warum `ProtectSystem` seit 0.6.1 ganz aus ist.** Bis dahin stand hier `true`,
also `/usr` read-only, mit der Begründung: Dort habe ein Panel nichts von Hand
zu ändern. Der Satz stimmt und übersieht das Entscheidende — **die
Einschränkung gilt für jeden Kindprozess des Dienstes, und apt ist einer.**
Jede Paketinstallation über das Panel brach beim Auspacken ab:

```
dpkg: error processing archive …/nginx_…_amd64.deb (--unpack):
 unable to create '/usr/sbin/nginx.dpkg-new': Read-only file system
```

Gefunden hat das kein Test, sondern der erste Lauf auf einem echten Server unter
systemd. Die Attrappe führt kein apt aus, und in keiner Testumgebung dieses
Projekts steht ein apt-Lauf unter dieser Unit — der Fehler war für die
vorhandene Prüfmechanik unsichtbar.

Ein Panel, dessen Aufgabe unter anderem das Installieren von Paketen ist, kann
`/usr` nicht schreibgeschützt halten. Was von der Zusage bleibt, steht in
`ReadOnlyPaths`: `/boot` und `/efi` rührt das Panel nie an. Wer den
Dateimanager nicht braucht, kann `ProtectHome` wieder verschärfen und
`files.enabled: false` setzen; `ProtectSystem` lässt sich nicht verschärfen,
ohne die Paketverwaltung mit abzuschalten.

Das Selbstupdate tauscht das Programm, nie die Unit. Eine Installation, die von
einer älteren Fassung kommt, trägt deshalb weiter die alte Härtung; das Panel
erkennt das mit einem echten Schreibversuch und sagt es
([13-dateimanager.md](13-dateimanager.md#systemd-härtung)).

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
/var/lib/asylum/backups/         Config-Backups und Sicherungen des Dateimanagers
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

Ergebnis: eine Grundlast, die auch nach Anmeldungen dort bleibt, wo sie vorher
war. Die Zahl selbst ist seither gewachsen — 16 MB bei der Messung (M1),
20,5 MB mit 0.4.1 —, aber sie wächst mit dem Funktionsumfang und nicht mit der
Zahl der Anmeldungen. Genau das war der Punkt.

## Sicherheitsgrundlagen

| Bereich | Umsetzung |
|---|---|
| Passwörter | Argon2id (`m=32MiB, t=3, p=2`), serialisiert, kein SHA-basierter Fallback |
| 2FA | TOTP, beim ersten Login erzwungen; Recovery-Codes einmalig anzeigbar |
| Sessions | HttpOnly, Secure, SameSite=Strict, serverseitig in SQLite (gespeichert wird nur der SHA-256 des Cookie-Werts), absolut 12 h ab Anmeldung, gleitendes Leerlauf-Fenster 2 h — alle Laufzeiten in [16-neukonzeption.md](16-neukonzeption.md#78-sitzungen-und-zugangsdauer) §7.8 |
| CSRF | Double-Submit-Token für alle mutierenden Requests |
| Brute Force | Rate-Limit pro IP und pro Account, exponentielles Lockout |
| Transport | TLS erzwungen; self-signed beim Setup, ACME/Let's Encrypt per Klick |
| Exposure | Optionale Bindung auf `127.0.0.1` bzw. WireGuard-Interface, empfohlen für Produktivsysteme |
| Autorisierung | Rollen (Owner / Admin / Operator / ReadOnly), serverseitig geprüft |
| Audit | Jede mutierende Aktion mit Nutzer, IP, Ziel, Ergebnis, Zeitstempel |
| Supply Chain | minisign-signierte Releases, SHA256SUMS, SBOM, reproduzierbare Builds; Public Key im Binary eingebaut |
| Selbstupdate | Signaturprüfung in Go (kein externes Programm), atomarer `rename(2)`, Bereitschaftsprüfung mit selbsttätigem Rollback, Datenbankabzug vor dem Tausch |

## Repository-Layout

Gewachsen ist es anders als hier zuerst geplant: Es gibt **kein
`internal/modules/`** und kein eigenes `internal/audit/`. Die Systemmodule sind
keine eigenen Pakete geworden, sondern typisierte Operationen in `privops` mit
je einem Handler-Satz in `httpd` — der Grund steht unten. Der Stand:

```
.
├── cmd/asylumd/              main(): serve | migrate | setup-token | reset-password
│                             | update | rollback | cert | passkey | version
├── web/                      Oberfläche (Svelte, Vite) — gebaut nach internal/ui,
│                             auf dem Zielserver läuft kein Node
├── internal/
│   ├── httpd/                Router, Middleware, /api/v1, Jobs, SSE, die
│   │                         verbliebenen server-gerenderten Seiten (vor Auth)
│   ├── ui/                   Vorlagen + gebaute Assets (embed.FS)
│   ├── auth/                 Argon2id, TOTP, Tokens, Ratenbegrenzung
│   ├── passkeys/             WebAuthn-Adapter, Challenge-Speicher
│   ├── privops/              privilegierte Operationen (einziger Systemzugriff):
│   │                         Dienste, Pakete, Firewall, Benutzer, Journal,
│   │                         Cron/Timer, Dateien samt pfadwache.go
│   ├── store/                SQLite, Migrationen (embedded), Sitzungen, Audit
│   ├── metrics/              /proc-Sampler und Ringpuffer
│   ├── certs/  acme/         selbstsigniertes Material, Let's Encrypt
│   ├── netinfo/              FQDN, Standardroute, Schnittstellen
│   ├── config/  systemd/  version/  update/
├── packaging/                install.sh, systemd/, nfpm/, Signaturmaterial
├── docs/
└── .github/workflows/
```

**Sitzungen liegen nicht in `auth`.** Die Tabelle und ihre Abfragen stehen in
`internal/store`, das Cookie samt Ablauf- und Erneuerungslogik in
`internal/httpd/session.go`. `auth` hält Passwörter, TOTP, Tokens und die
Ratenbegrenzung. Die frühere Zeile „auth/ — Passwörter, Sessions, TOTP, RBAC"
hat drei Pakete in eines gefasst.

**Warum keine Modulpakete.** Die Trennung, die trägt, verläuft zwischen
*privilegiert* und *nicht privilegiert* — nicht zwischen Fachthemen. Ein
eigenes Paket je Modul hätte die Systemgrenze vervielfacht, ohne etwas zu
gewinnen: Jedes hätte doch wieder durch `privops` gemusst.

Mit dieser Entscheidung fällt auch der Satz, der hier stand: *„Jedes Modul
registriert seine Routen und Navigationseinträge selbst. Ein Modul abzuschalten
(Config-Flag) entfernt Routen und Rechte."* **Ein solches Flag gibt es nicht.**
Alle Module sind registriert, und die Sichtbarkeit im Menü hängt allein an der
Rolle. Ein Abschalter bliebe möglich — er müsste Routen *und* Rollenprüfung
weglassen, nicht bloß den Menüpunkt —, ist aber nicht gebaut und für keine
Stufe eingeplant.
