# 01 — Sprachwahl

## Anforderungen an die Sprache

Aus den Projektzielen (schlank, ressourcenschonend, One-Click-Deployment, modern,
robust) ergeben sich harte Kriterien:

1. **Statisches Single-Binary-Deployment** — der Installer soll eine Datei
   herunterladen und starten, ohne Runtime, Paketmanager-Abhängigkeiten oder
   Interpreter auf dem Zielserver.
2. **Geringer Speicher-Footprint** — Zielgruppe sind auch 1-vCPU-/1-GB-VPS. Ein
   Panel, das 300 MB RAM belegt, disqualifiziert sich.
3. **Gute Standardbibliothek für Systemnähe** — HTTP-Server, TLS, Prozess-Handling,
   Unix-Sockets, Dateirechte, Signale.
4. **Speichersicherheit** — die Software läuft mit hohen Privilegien. Klassische
   Speicherfehler sind hier kein Bug, sondern eine lokale Rechteausweitung.
5. **Cross-Compilation für amd64 + arm64** — ARM-VPS sind Standard geworden.
6. **Realistischer Contributor-Pool** — ein Open-Source-Panel lebt von Beiträgen.

## Bewertung

### Go — **Empfehlung**

| Kriterium | Bewertung |
|---|---|
| Single Binary | Nativ, `CGO_ENABLED=0` erzeugt vollständig statische Binaries |
| Footprint | ~10–25 MB RSS für einen solchen Daemon realistisch |
| Systemnähe | `os/exec`, `net`, `crypto/tls`, `os/user`, `syscall` in der Stdlib |
| Speichersicherheit | GC-basiert, keine Use-after-free/Buffer-Overflows |
| Cross-Compile | `GOOS=linux GOARCH=arm64 go build` — ohne Toolchain-Gefrickel |
| Frontend-Bundling | `embed.FS` bettet das gesamte Web-UI ins Binary ein |
| Ökosystem | systemd via D-Bus (`godbus`), Metriken (`gopsutil`), SQLite ohne CGO (`modernc.org/sqlite`) |
| Contributor-Pool | Sehr groß, flache Lernkurve, homogener Stil durch `gofmt` |

**Nachteile:** GC-Pausen (für ein Control Panel irrelevant), etwas mehr
Speicherverbrauch als Rust, generisch-repetitiver Code bei Fehlerbehandlung.

Referenzpunkt: Grafana Agent, Portainer, Caddy, Netdata-Ökosystem und praktisch
alle modernen Infrastruktur-Tools sind aus denselben Gründen in Go geschrieben.

### Rust — starke Alternative

| Kriterium | Bewertung |
|---|---|
| Single Binary | Ja (musl-Target für vollständig statische Builds) |
| Footprint | Bester Wert, ~5–10 MB RSS, kein GC |
| Speichersicherheit | Am stärksten (Compile-Time-Garantien) |
| Ökosystem | `axum`/`tokio`, `sqlx`, `zbus` — ausgereift, aber jünger |
| Cross-Compile | Möglich, aber aufwendiger (`cross`, musl-Toolchain) |
| Entwicklungstempo | Deutlich langsamer, besonders bei async + Lifetimes |
| Contributor-Pool | Kleiner; höhere Einstiegshürde für Gelegenheitsbeiträge |

**Wähle Rust, wenn** maximale Effizienz und Sicherheit über Entwicklungstempo
stehen und das Kernteam bereits Rust-erfahren ist. Für ein Projekt, das schnell
einen nutzbaren MVP braucht und Beiträge anziehen soll, ist Go der pragmatischere
Weg.

### Nicht empfohlen

| Sprache | Ausschlussgrund |
|---|---|
| **PHP** (Laravel o. ä.) | Braucht PHP-FPM + Webserver + Composer auf dem Zielserver. Widerspricht One-Binary-Deployment und "ressourcenschonend" direkt. Der Grund, warum bestehende Panels schwergewichtig sind. |
| **Node.js / TypeScript** | Runtime-Abhängigkeit oder ~50–90 MB SEA-Binary, `node_modules`-Supply-Chain, höherer RAM-Bedarf. Gut fürs Frontend, ungeeignet für den Server-Daemon. |
| **Python** | Interpreter- und venv-Abhängigkeiten, langsamer Start, PyInstaller-Binaries sind groß und fragil. Ausnahme: kleine Helfer-Skripte. |
| **C / C++** | Speichersicherheit bei root-Prozess nicht vertretbar. |
| **Java / .NET** | Runtime-Footprint (JVM/CLR) steht dem Kernziel entgegen; AOT-Kompilierung mildert das, bringt aber eigene Komplexität. |

## Empfohlener Stack

```
Sprache        Go 1.23+  (CGO_ENABLED=0, statisch)
HTTP           net/http (Go 1.22+ Routing) oder chi — kein schweres Framework
Datenbank      SQLite via modernc.org/sqlite (pure Go, kein CGO)
Auth           Argon2id (golang.org/x/crypto), TOTP (pquerna/otp)
systemd        D-Bus über github.com/coreos/go-systemd/v22
Metriken       github.com/shirou/gopsutil/v4 + /proc direkt
Live-Updates   Server-Sent Events (leichter als WebSockets, reicht für Metriken)
Frontend       htmx + Alpine.js + Go-Templates, per embed.FS eingebettet
Build/Release  GoReleaser + GitHub Actions + nfpm (.deb) + cosign
```

### Warum htmx statt SPA?

Ein Control Panel ist überwiegend formular- und tabellengetrieben. htmx + Server-Side
Templates bedeutet:

- kein Node-Buildchain als Voraussetzung für Contributors,
- ~15 kB JS statt ~300 kB Framework-Bundle,
- Autorisierung findet ausschließlich serverseitig statt (kein API-Token im Browser),
- Live-Metriken über SSE-Fragmente ohne State-Management.

**Alternative,** falls das UI später deutlich interaktiver werden soll: Svelte oder
Preact, per Vite gebaut und ebenfalls in `embed.FS` eingebettet. Die Entscheidung ist
umkehrbar, solange das Backend saubere HTTP-Handler hat — sie sollte den MVP nicht
blockieren.
