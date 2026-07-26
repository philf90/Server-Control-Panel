#!/usr/bin/env bash
#
# install.sh — Project Asylum
#
#   curl -fsSL --proto '=https' --tlsv1.2 https://repo.cloudsrv24.de/install.sh -o install.sh
#   sudo bash install.sh
#
# Eigenschaften: idempotent, unattended über ASYLUM_*-Variablen, prüft
# Signatur und Prüfsumme jedes Artefakts, stellt bei einem Fehler den vorherigen
# Stand wieder her und lässt sich mit --uninstall vollständig entfernen.

set -euo pipefail
umask 022

REPO="${ASYLUM_REPO:-philf90/Server-Control-Panel}"
CHANNEL="${ASYLUM_CHANNEL:-stable}"
VERSION="${ASYLUM_VERSION:-latest}"
PORT="${ASYLUM_PORT:-8443}"
BIND="${ASYLUM_BIND:-0.0.0.0}"

PREFIX="/usr/local/lib/asylum"
BINARY="${PREFIX}/asylumd"
SYMLINK="/usr/local/bin/asylum"
CONFIG_DIR="/etc/asylum"
CONFIG_FILE="${CONFIG_DIR}/config.yaml"
TLS_DIR="${CONFIG_DIR}/tls"
DATA_DIR="/var/lib/asylum"
LOG_DIR="/var/log/asylum"
UNIT_FILE="/etc/systemd/system/asylumd.service"
SERVICE="asylumd"
RUN_USER="asylum"

# Wird beim Release durch den echten Schlüssel ersetzt.
MINISIGN_PUBKEY="${ASYLUM_MINISIGN_PUBKEY:-RWQPLACEHOLDER0000000000000000000000000000000}"

TMP=""
BACKUP_BINARY=""
SERVICE_WAS_ACTIVE="no"
INSTALL_DONE="no"

# ---------------------------------------------------------------- Ausgabe ---

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
  C_INFO=$'\033[1;34m'; C_WARN=$'\033[1;33m'; C_ERR=$'\033[1;31m'
  C_OK=$'\033[1;32m';   C_OFF=$'\033[0m'
else
  C_INFO=""; C_WARN=""; C_ERR=""; C_OK=""; C_OFF=""
fi

log()  { printf '%s::%s %s\n' "$C_INFO" "$C_OFF" "$*"; }
ok()   { printf '%s ok%s %s\n' "$C_OK" "$C_OFF" "$*"; }
warn() { printf '%s !!%s %s\n' "$C_WARN" "$C_OFF" "$*" >&2; }
die()  { printf '%s xx%s %s\n' "$C_ERR" "$C_OFF" "$*" >&2; exit 1; }

cleanup() {
  local rc=$?
  if [ "$rc" -ne 0 ] && [ "$INSTALL_DONE" = "no" ] && [ -n "$BACKUP_BINARY" ] && [ -f "$BACKUP_BINARY" ]; then
    warn "Installation fehlgeschlagen — vorherige Version wird wiederhergestellt"
    install -m 0755 "$BACKUP_BINARY" "$BINARY" || true
    if [ "$SERVICE_WAS_ACTIVE" = "yes" ]; then
      systemctl start "$SERVICE" >/dev/null 2>&1 || true
    fi
  fi
  [ -n "$TMP" ] && rm -rf "$TMP"
  exit "$rc"
}
trap cleanup EXIT

# ------------------------------------------------------------ Vorbedingungen ---

require_root() {
  [ "$(id -u)" -eq 0 ] || die "Bitte mit sudo bzw. als root ausführen."
}

require_cmds() {
  local missing=()
  for c in curl tar install systemctl openssl; do
    command -v "$c" >/dev/null 2>&1 || missing+=("$c")
  done
  if [ ${#missing[@]} -gt 0 ]; then
    die "Es fehlen folgende Programme: ${missing[*]}"
  fi
}

detect_os() {
  [ -r /etc/os-release ] || die "Nicht unterstütztes System (kein /etc/os-release)."
  # shellcheck disable=SC1091
  . /etc/os-release

  local major="${VERSION_ID%%.*}"
  case "${ID:-}" in
    ubuntu)
      if [ "${major:-0}" -lt 22 ] 2>/dev/null; then
        die "Ubuntu ${VERSION_ID} ist zu alt — benötigt wird 22.04 oder neuer."
      fi
      ;;
    debian)
      if [ "${major:-0}" -lt 12 ] 2>/dev/null; then
        die "Debian ${VERSION_ID} ist zu alt — benötigt wird 12 oder neuer."
      fi
      ;;
    *)
      case "${ID_LIKE:-}" in
        *debian*) warn "Nicht offiziell getestet: ${PRETTY_NAME:-$ID}" ;;
        *)        die "Nur Ubuntu und Debian werden unterstützt (gefunden: ${PRETTY_NAME:-$ID})." ;;
      esac
      ;;
  esac

  [ -d /run/systemd/system ] || die "systemd wird benötigt, ist hier aber nicht aktiv."
  ok "System: ${PRETTY_NAME:-$ID $VERSION_ID}"
}

detect_arch() {
  case "$(uname -m)" in
    x86_64)  ARCH="amd64" ;;
    aarch64) ARCH="arm64" ;;
    *)       die "Nicht unterstützte Architektur: $(uname -m) (unterstützt: x86_64, aarch64)" ;;
  esac
  ok "Architektur: ${ARCH}"
}

# ------------------------------------------------------------------ Version ---

resolve_version() {
  if [ "$VERSION" != "latest" ]; then
    VERSION="${VERSION#v}"
    log "Angeforderte Version: ${VERSION}"
    return
  fi

  local api tag
  if [ "$CHANNEL" = "stable" ]; then
    api="https://api.github.com/repos/${REPO}/releases/latest"
  else
    api="https://api.github.com/repos/${REPO}/releases"
  fi

  log "Ermittle neueste Version im Kanal ${CHANNEL} …"
  tag="$(curl -fsSL --proto '=https' --tlsv1.2 \
           -H 'Accept: application/vnd.github+json' "$api" 2>/dev/null \
         | grep -m1 '"tag_name"' \
         | sed -E 's/.*"tag_name"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/')" || true

  [ -n "$tag" ] || die "Konnte keine Version ermitteln. Alternativ ASYLUM_VERSION=x.y.z setzen."
  VERSION="${tag#v}"
  ok "Neueste Version: ${VERSION}"
}

installed_version() {
  [ -x "$BINARY" ] || return 1
  "$BINARY" version 2>/dev/null | head -n1 | awk '{print $3}'
}

# --------------------------------------------------------- Download + Prüfung ---

fetch() {
  local url="$1" dest="$2"
  curl -fsSL --proto '=https' --tlsv1.2 --retry 3 --retry-delay 2 -o "$dest" "$url" \
    || die "Download fehlgeschlagen: ${url}"
}

ensure_minisign() {
  if command -v minisign >/dev/null 2>&1; then
    return 0
  fi
  log "minisign fehlt — wird nachinstalliert"
  if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq minisign >/dev/null 2>&1; then
    return 0
  fi
  if DEBIAN_FRONTEND=noninteractive apt-get update -qq >/dev/null 2>&1 &&
     DEBIAN_FRONTEND=noninteractive apt-get install -y -qq minisign >/dev/null 2>&1; then
    return 0
  fi
  return 1
}

verify_artifacts() {
  local dir="$1" tarball="$2"

  if [ "${ASYLUM_SKIP_SIGNATURE:-0}" = "1" ]; then
    warn "Signaturprüfung übersprungen (ASYLUM_SKIP_SIGNATURE=1)."
  elif [ "$MINISIGN_PUBKEY" = "RWQPLACEHOLDER0000000000000000000000000000000" ]; then
    die "In diesem Skript steht noch kein Signaturschlüssel. Mit ASYLUM_SKIP_SIGNATURE=1 lässt sich die Prüfung bewusst überspringen — auf Produktivsystemen nicht empfohlen."
  elif ensure_minisign; then
    log "Prüfe Signatur der Prüfsummendatei …"
    minisign -Vm "${dir}/SHA256SUMS" -P "$MINISIGN_PUBKEY" >/dev/null \
      || die "Signatur der Prüfsummendatei ist ungültig. Installation abgebrochen."
    ok "Signatur gültig"
  else
    die "minisign ist nicht verfügbar und konnte nicht installiert werden. Mit ASYLUM_SKIP_SIGNATURE=1 lässt sich die Prüfung bewusst überspringen."
  fi

  log "Prüfe SHA-256 des Archivs …"
  ( cd "$dir" && grep " ${tarball}\$" SHA256SUMS | sha256sum -c --status - ) \
    || die "Prüfsumme des Archivs stimmt nicht. Installation abgebrochen."
  ok "Prüfsumme stimmt"
}

download_release() {
  local base="https://github.com/${REPO}/releases/download/v${VERSION}"
  local tarball="asylumd_${VERSION}_linux_${ARCH}.tar.gz"

  log "Lade ${tarball} …"
  fetch "${base}/${tarball}" "${TMP}/${tarball}"
  fetch "${base}/SHA256SUMS"  "${TMP}/SHA256SUMS"
  if [ "${ASYLUM_SKIP_SIGNATURE:-0}" != "1" ]; then
    fetch "${base}/SHA256SUMS.minisig" "${TMP}/SHA256SUMS.minisig"
  fi

  verify_artifacts "$TMP" "$tarball"

  tar -xzf "${TMP}/${tarball}" -C "$TMP"
  [ -f "${TMP}/asylumd" ] || die "Im Archiv fehlt die Datei asylumd."
}

# ---------------------------------------------------------------- Installation ---

create_user() {
  if id -u "$RUN_USER" >/dev/null 2>&1; then
    return
  fi
  log "Lege Systembenutzer ${RUN_USER} an"
  useradd --system --no-create-home --shell /usr/sbin/nologin "$RUN_USER"
}

create_dirs() {
  install -d -m 0755 "$PREFIX"
  install -d -m 0750 -o root -g "$RUN_USER" "$CONFIG_DIR" "$TLS_DIR"
  install -d -m 0750 -o root -g "$RUN_USER" "$DATA_DIR" "$LOG_DIR"
}

install_binary() {
  if [ -x "$BINARY" ]; then
    BACKUP_BINARY="${TMP}/asylumd.previous"
    cp -a "$BINARY" "$BACKUP_BINARY"
  fi
  # Erst danebenlegen, dann umbenennen: rename(2) ist atomar, ein Abbruch
  # hinterlässt also nie ein halbes Binary.
  install -m 0755 "${TMP}/asylumd" "${BINARY}.new"
  mv -f "${BINARY}.new" "$BINARY"
  ln -sf "$BINARY" "$SYMLINK"
  ok "Binary installiert: ${BINARY}"
}

write_config() {
  if [ -f "$CONFIG_FILE" ]; then
    log "Bestehende Konfiguration bleibt unverändert: ${CONFIG_FILE}"
    return
  fi
  log "Schreibe Grundkonfiguration"
  cat > "$CONFIG_FILE" <<YAML
# Project Asylum — Konfiguration
# Vollständige Referenz: https://github.com/${REPO}/blob/main/docs

server:
  bind: "${BIND}"
  port: ${PORT}
  tls:
    cert: ${TLS_DIR}/server.crt
    key: ${TLS_DIR}/server.key

paths:
  data: ${DATA_DIR}
  log: ${LOG_DIR}

log:
  level: info
  format: text

updates:
  channel: ${CHANNEL}
  check: daily
  auto_apply: security
  window: "03:00-05:00"
YAML
  chown root:"$RUN_USER" "$CONFIG_FILE"
  chmod 0640 "$CONFIG_FILE"
}

run_migrations() {
  log "Spiele Datenbankmigrationen ein"
  "$BINARY" migrate --config "$CONFIG_FILE" >/dev/null
}

install_unit() {
  log "Richte systemd-Unit ein"
  cat > "$UNIT_FILE" <<UNIT
[Unit]
Description=Project Asylum — Control Panel
Documentation=https://github.com/${REPO}
After=network-online.target
Wants=network-online.target

[Service]
Type=notify
ExecStart=${BINARY} serve --config ${CONFIG_FILE}
Restart=on-failure
RestartSec=5s
WatchdogSec=30s
TimeoutStartSec=60s
TimeoutStopSec=30s

NoNewPrivileges=no
ProtectSystem=full
ProtectHome=read-only
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
ProtectClock=yes
RestrictNamespaces=yes
RestrictRealtime=yes
LockPersonality=yes
MemoryDenyWriteExecute=yes
SystemCallArchitectures=native

MemoryMax=256M
TasksMax=256

ReadWritePaths=${CONFIG_DIR} ${DATA_DIR} ${LOG_DIR}

StandardOutput=journal
StandardError=journal
SyslogIdentifier=asylumd

[Install]
WantedBy=multi-user.target
UNIT
  chmod 0644 "$UNIT_FILE"
  systemctl daemon-reload
}

open_firewall() {
  if [ "${ASYLUM_NO_FIREWALL:-0}" = "1" ]; then
    log "Firewall-Anpassung übersprungen (ASYLUM_NO_FIREWALL=1)"
    return
  fi

  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
    log "Gebe Port ${PORT}/tcp in ufw frei"
    ufw allow "${PORT}/tcp" comment 'Project Asylum' >/dev/null
    ok "ufw-Regel gesetzt"
    return
  fi

  if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
    log "Gebe Port ${PORT}/tcp in firewalld frei"
    firewall-cmd --permanent --add-port="${PORT}/tcp" >/dev/null
    firewall-cmd --reload >/dev/null
    ok "firewalld-Regel gesetzt"
    return
  fi

  # Rohe nftables-Regelwerke rührt der Installer bewusst nicht an: Ein
  # blindes Einfügen kann bestehende Ketten unbrauchbar machen und im
  # schlimmsten Fall die SSH-Sitzung kappen. Das Firewall-Modul des Panels
  # übernimmt das später mit Rückroll-Bestätigung.
  warn "Kein verwalteter Firewall-Dienst gefunden — Port ${PORT}/tcp ggf. manuell freigeben."
}

start_service() {
  log "Starte ${SERVICE}"
  systemctl enable "$SERVICE" >/dev/null 2>&1
  systemctl restart "$SERVICE"
}

health_check() {
  local url="https://127.0.0.1:${PORT}/healthz"
  log "Warte auf Bereitschaft …"
  for _ in $(seq 1 30); do
    if curl -fsS -k --max-time 2 "$url" >/dev/null 2>&1; then
      ok "Dienst antwortet"
      return 0
    fi
    if ! systemctl is-active --quiet "$SERVICE"; then
      break
    fi
    sleep 1
  done

  warn "Der Dienst wurde nicht rechtzeitig bereit. Diagnose:"
  systemctl status "$SERVICE" --no-pager --lines=20 >&2 || true
  die "Installation abgebrochen."
}

summary() {
  local fingerprint host setup_url
  fingerprint="$(openssl x509 -in "${TLS_DIR}/server.crt" -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2)"
  host="$(hostname -I 2>/dev/null | awk '{print $1}')"
  [ -n "$host" ] || host="$(hostname -f 2>/dev/null || hostname)"

  printf '\n  %sProject Asylum %s ist installiert.%s\n\n' "$C_OK" "$VERSION" "$C_OFF"

  # Der Token wird nur bei einer Erstinstallation erzeugt; bei einem Update
  # scheitert der Aufruf absichtlich, weil bereits ein Konto existiert.
  if setup_url="$("$BINARY" setup-token --config "$CONFIG_FILE" --url-only 2>/dev/null)"; then
    # Die URL zeigt auf den Hostnamen des Servers; für den Zugriff von außen
    # ist die tatsächliche Adresse oft die brauchbarere Angabe.
    setup_url="${setup_url/https:\/\/$(hostname):/https://${host}:}"
    cat <<SETUP
  ${C_OK}Ersteinrichtung${C_OFF} — dieser Link gilt 60 Minuten und nur einmal:

  ${setup_url}

  Dort werden Administrator-Konto und Zwei-Faktor-Anmeldung eingerichtet.
  Es wird bewusst kein Passwort vergeben, das hier im Terminal stünde.

SETUP
  else
    cat <<EXISTING
  Adresse      https://${host}:${PORT}/

EXISTING
  fi

  cat <<SUMMARY
  Zertifikat   selbstsigniert, SHA-256:
               ${fingerprint}

  Beim ersten Aufruf warnt der Browser vor dem Zertifikat. Vergleiche den
  Fingerprint oben mit dem, den der Browser anzeigt — dann ist die Verbindung
  belegbar echt.

  Dienst       systemctl status ${SERVICE}
  Logs         journalctl -u ${SERVICE} -f
  Version      asylum version
  Ausgesperrt  sudo asylum reset-password BENUTZER
  Entfernen    sudo bash install.sh --uninstall

SUMMARY
}

# ---------------------------------------------------------------- Deinstall ---

uninstall() {
  local purge="${1:-no}"

  log "Stoppe und deaktiviere ${SERVICE}"
  systemctl disable --now "$SERVICE" >/dev/null 2>&1 || true

  rm -f "$UNIT_FILE"
  systemctl daemon-reload || true

  rm -f "$SYMLINK"
  rm -rf "$PREFIX"

  if [ "$purge" = "purge" ]; then
    log "Entferne Konfiguration und Daten"
    rm -rf "$CONFIG_DIR" "$DATA_DIR" "$LOG_DIR"
    if id -u "$RUN_USER" >/dev/null 2>&1; then
      userdel "$RUN_USER" >/dev/null 2>&1 || true
    fi
    ok "Project Asylum wurde vollständig entfernt."
  else
    ok "Project Asylum wurde entfernt. Konfiguration und Daten bleiben erhalten:"
    printf '     %s\n     %s\n' "$CONFIG_DIR" "$DATA_DIR"
    printf '     Vollständig entfernen: sudo bash install.sh --uninstall --purge\n'
  fi

  INSTALL_DONE="yes"
}

usage() {
  cat <<USAGE
install.sh — Project Asylum

  sudo bash install.sh                    installieren oder aktualisieren
  sudo bash install.sh --uninstall         entfernen, Daten behalten
  sudo bash install.sh --uninstall --purge entfernen samt Konfiguration und Daten
  bash install.sh --help                   diese Hilfe

Umgebungsvariablen:
  ASYLUM_VERSION=0.1.0     bestimmte Version statt der neuesten
  ASYLUM_CHANNEL=beta      Release-Kanal (stable|beta)
  ASYLUM_PORT=8443         Port des Panels
  ASYLUM_BIND=127.0.0.1    Bind-Adresse (empfohlen hinter WireGuard/SSH-Tunnel)
  ASYLUM_NO_FIREWALL=1     Firewall unangetastet lassen
  ASYLUM_SKIP_SIGNATURE=1  Signaturprüfung überspringen (nicht empfohlen)
USAGE
}

# ------------------------------------------------------------------- Ablauf ---

main() {
  local do_uninstall="no" purge="no"
  while [ $# -gt 0 ]; do
    case "$1" in
      --uninstall) do_uninstall="yes" ;;
      --purge)     purge="purge" ;;
      --help|-h)   usage; INSTALL_DONE="yes"; return 0 ;;
      *)           usage >&2; die "Unbekannte Option: $1" ;;
    esac
    shift
  done

  require_root

  if [ "$do_uninstall" = "yes" ]; then
    uninstall "$purge"
    return 0
  fi

  require_cmds
  detect_os
  detect_arch
  resolve_version

  local current
  if current="$(installed_version)" && [ "$current" = "$VERSION" ]; then
    ok "Version ${VERSION} ist bereits installiert."
    systemctl is-active --quiet "$SERVICE" || systemctl start "$SERVICE"
    INSTALL_DONE="yes"
    return 0
  fi

  systemctl is-active --quiet "$SERVICE" && SERVICE_WAS_ACTIVE="yes"

  TMP="$(mktemp -d)"
  download_release
  create_user
  create_dirs
  install_binary
  write_config
  run_migrations
  install_unit
  open_firewall
  start_service
  health_check

  INSTALL_DONE="yes"
  summary
}

main "$@"
