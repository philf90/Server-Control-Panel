# 04 — One-Click-Setup

## Der Befehl

```bash
curl -fsSL https://repo.cloudsrv24.de/install.sh -o install.sh
sudo bash install.sh
```

Die Kurzform `curl … | sudo bash` funktioniert ebenfalls und wird von den meisten
Projekten beworben. Sie sollte im README aber **nicht** die primär dokumentierte
Variante sein: Ein abgebrochener Download kann bei Pipe-to-Shell ein halbes Skript
ausführen, und für ein Tool, das root-Rechte übernimmt, ist "erst herunterladen,
dann ausführen" die ehrlichere Empfehlung. Beide Wege dokumentieren, den sicheren
zuerst.

## Was der Installer tut

```
 1. Vorbedingungen prüfen   root? Ubuntu 22.04+/Debian 12+? x86_64 oder aarch64?
                            systemd vorhanden? curl/tar vorhanden?
 2. Version auflösen        GitHub Releases API → neuestes Tag im gewählten Kanal
 3. Herunterladen           asylumd_<version>_linux_<arch>.tar.gz + SHA256SUMS + .sig
 4. Verifizieren            Signatur (minisign/cosign) gegen im Skript eingebetteten
                            Public Key, dann Prüfsumme
 5. Systembenutzer          Gruppe/User `asylum` (--system, kein Login, kein Home)
 6. Dateien platzieren      /usr/local/lib/asylum/asylumd, Symlink /usr/local/bin/asylum,
                            Verzeichnisse mit korrekten Rechten
 7. Erstkonfiguration       /etc/asylum/config.yaml, self-signed TLS-Zertifikat
 8. Datenbank               asylumd migrate  (legt SQLite an, spielt Migrationen ein)
 9. systemd                 Unit installieren, daemon-reload, enable --now
10. Firewall                Panel-Port in ufw/nftables freigeben (falls aktiv)
11. Healthcheck             bis zu 30 s auf HTTP 200 an /healthz warten
12. Ausgabe                 URL, Setup-Token, Fingerprint des TLS-Zertifikats
```

Schritt 12 gibt **kein Passwort** aus, sondern einen einmaligen, zeitlich begrenzten
Setup-Token. Der erste Aufruf der URL mit diesem Token legt den Admin-Account
inklusive 2FA an. So landet nie ein Klartext-Passwort in der Shell-History oder im
Terminal-Log.

## Signaturprüfung von Hand

Der Installer prüft die Signatur selbst, gegen den in ihm eingebetteten Public Key.
Wer das nicht dem Skript überlassen will, prüft vorher selbst:

```bash
# Public Key des Projekts (identisch mit packaging/minisign.pub im Repository)
KEY="RWQj/sAQQiq7Aa8sPaBSb21Wcbp9n165J+s6z8qqq0GUmB2ZXzDNoNXf"

V=0.1.0
base="https://github.com/philf90/Server-Control-Panel/releases/download/v${V}"
curl -fsSLO "${base}/SHA256SUMS"
curl -fsSLO "${base}/SHA256SUMS.minisig"
curl -fsSLO "${base}/asylumd_${V}_linux_amd64.tar.gz"

minisign -Vm SHA256SUMS -P "$KEY"          # Signatur der Prüfsummendatei
sha256sum -c --ignore-missing SHA256SUMS   # Prüfsumme des Archivs
```

Die Kette ist bewusst zweistufig: Signiert wird nur die Prüfsummendatei, die
Archive hängen über ihre Prüfsumme daran. Das kommt ohne Netzabfrage aus — anders
als bei einer Transparenzprotokoll-Prüfung genügt der eine eingebettete Schlüssel.

Der Schlüssel steht zusätzlich unter
<https://repo.cloudsrv24.de/minisign.pub> und im Repository unter
`packaging/minisign.pub`. Stimmen die drei Fundstellen überein, ist ein
untergeschobener Installer mit fremdem Schlüssel ausgeschlossen.

## Eigenschaften des Installers

| Eigenschaft | Umsetzung |
|---|---|
| **Idempotent** | Zweiter Aufruf aktualisiert, statt zu zerstören; bestehende Config und DB bleiben unangetastet |
| **Unattended** | Alle Eingaben über Umgebungsvariablen (`ASYLUM_PORT`, `ASYLUM_BIND`, `ASYLUM_CHANNEL`, `ASYLUM_VERSION`, `ASYLUM_NO_FIREWALL=1`) |
| **Fehlertolerant** | `set -euo pipefail`, `trap` mit Cleanup und Rollback bei Abbruch |
| **Gesprächig** | Jeder Schritt mit Status, Fehler mit konkretem Hinweis statt Stacktrace |
| **Prüfbar** | Das Skript ist im Repo versioniert, nicht generiert; die Download-URL zeigt auf ein Release-Artefakt, nicht auf `main` |
| **Deinstallierbar** | `sudo bash install.sh --uninstall` bzw. `sudo asylum uninstall [--purge]` |

## Skelett

```bash
#!/usr/bin/env bash
# install.sh — Project Asylum
set -euo pipefail

REPO="philf90/Server-Control-Panel"     # ggf. auf project-asylum umziehen
CHANNEL="${ASYLUM_CHANNEL:-stable}"
VERSION="${ASYLUM_VERSION:-latest}"
PORT="${ASYLUM_PORT:-8443}"
PREFIX="/usr/local/lib/asylum"
MINISIGN_PUBKEY="RWQ...."          # im Skript eingebettet

log()  { printf '\033[1;34m::\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mxx\033[0m %s\n' "$*" >&2; exit 1; }

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

require_root()  { [ "$(id -u)" -eq 0 ] || die "Bitte mit sudo ausführen."; }

detect_os() {
  [ -r /etc/os-release ] || die "Nicht unterstütztes System (kein /etc/os-release)."
  . /etc/os-release
  case "$ID" in
    ubuntu|debian) : ;;
    *) case "${ID_LIKE:-}" in
         *debian*) warn "Nicht offiziell getestet: $PRETTY_NAME" ;;
         *) die "Nur Ubuntu und Debian werden unterstützt (gefunden: $PRETTY_NAME)." ;;
       esac ;;
  esac
  command -v systemctl >/dev/null || die "systemd wird benötigt."
}

detect_arch() {
  case "$(uname -m)" in
    x86_64)  ARCH=amd64 ;;
    aarch64) ARCH=arm64 ;;
    *) die "Nicht unterstützte Architektur: $(uname -m)" ;;
  esac
}

# … resolve_version, download, verify, install_files, setup_systemd,
#    open_firewall, health_check, print_summary
```

Der vollständige Installer gehört nach `packaging/install.sh` und wird bei jedem
Release unverändert als Artefakt mit veröffentlicht. `https://repo.cloudsrv24.de/install.sh`
ist lediglich eine Weiterleitung auf das Artefakt des jeweils aktuellen
Stable-Releases (statisches Hosting oder GitHub Pages genügt).

## Zweiter Weg: APT-Repository

Der Installer ist der bequeme Weg. Für Nutzer mit Konfigurationsmanagement oder
Compliance-Anforderungen gibt es zusätzlich ein signiertes APT-Repository:

```bash
sudo curl -fsSL --proto '=https' --tlsv1.2 \
  https://repo.cloudsrv24.de/apt/asylum-archive-keyring.gpg \
  -o /usr/share/keyrings/asylum-archive-keyring.gpg

sudo tee /etc/apt/sources.list.d/asylum.sources > /dev/null <<'EOF'
Types: deb
URIs: https://repo.cloudsrv24.de/apt
Suites: stable
Components: main
Signed-By: /usr/share/keyrings/asylum-archive-keyring.gpg
EOF

sudo apt update && sudo apt install asylum-panel
```

**Das Paket heißt `asylum-panel`, nicht `asylum`.** Der Name `asylum` ist in
Debian und Ubuntu seit Jahren an ein Spiel vergeben (`universe/games`), dessen
Fassung über unserer liegt — `apt install asylum` brächte also das Spiel. Der
*Befehl* heißt weiterhin `asylum`: `/usr/bin/asylum` steht im `PATH` vor
`/usr/games/asylum`.

Die `.deb`-Pakete entstehen im Release-Prozess (nfpm über GoReleaser); das
Repository baut ein GitHub-Actions-Job mit `apt-ftparchive` und veröffentlicht es
über GitHub Pages. Aufbau und Signaturschlüssel:
[05-updates.md](05-updates.md#apt-repository).

Ein Hinweis zur Wahl des Weges: Über apt aktualisiert, entfällt der
Bereitschaftscheck mit selbsttätigem Rollback — apt kennt so etwas nicht. Wer
beides will, nutzt apt für die Erstinstallation und danach `asylum update`.

## Alternative Deployments

- **Docker:** möglich (`docker run --privileged --pid=host -v /:/host`), aber ein
  Panel, das den Host verwalten soll, braucht so viele Ausnahmen vom
  Container-Modell, dass der Gewinn gering ist. Sinnvoll höchstens für die spätere
  Multi-Server-Variante, wo die Web-Komponente wirklich isoliert laufen kann.
- **cloud-init:** ein Ein-Zeilen-`runcmd` mit dem Installer und gesetzten
  `ASYLUM_*`-Variablen — deckt automatisierte VPS-Provisionierung ohne Zusatzaufwand ab.
- **Ansible-Rolle:** dünner Wrapper um das `.deb`, gehört in ein eigenes Repository.

## Deinstallation

```bash
sudo asylum uninstall           # Dienst stoppen, Binary + Unit entfernen, Daten behalten
sudo asylum uninstall --purge   # zusätzlich /etc/asylum und /var/lib/asylum entfernen
```

Der Uninstaller entfernt außerdem alle vom Panel gesetzten Konfigurationsblöcke
(erkennbar an den Managed-Markern) und stellt die letzten Backups wieder her. Ein
Panel, das sich nicht sauber wieder entfernen lässt, wird zu Recht nicht installiert.
