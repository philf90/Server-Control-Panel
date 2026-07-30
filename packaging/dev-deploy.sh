#!/usr/bin/env bash
#
# Tauscht das Binary einer laufenden Installation gegen einen Eigenbau.
#
# Wofür das da ist: Während des Umbaus zum Leitstand entstehen Stände, die man
# auf einem echten Server sehen will, bevor es ein Release gibt. Der reguläre
# Weg trägt sie nicht — install.sh lädt immer aus dem Release und prüft die
# Signatur, und `asylum update` braucht signierte Metadaten. Beides ist richtig
# so und wird hier NICHT umgangen, sondern beiseitegelassen: Dieses Skript lädt
# nichts herunter und prüft keine Signatur, weil es eine Datei einsetzt, die der
# Betreiber selbst gebaut hat.
#
# Was es dafür tut: den Pfad aus der laufenden Unit lesen statt zu raten, das
# alte Binary sichern, tauschen, starten, die Bereitschaft prüfen — und bei
# jedem Fehlschlag von allein zurückrollen.
#
# Aufruf auf dem Zielserver, als root:
#
#   ./dev-deploy.sh /pfad/zum/neuen/asylumd
#   ./dev-deploy.sh --rollback
#
# NICHT für Produktivsysteme mit Daten, die wehtun. Ein Eigenbau ist ein
# Eigenbau; wer einen Rückweg braucht, den er nicht selbst gebaut hat, nimmt ein
# signiertes Release.

set -euo pipefail

DIENST="asylumd"
SICHERUNG_SUFFIX=".vor-dev-deploy"

log() { printf '  %s\n' "$*"; }
warn() { printf '  ! %s\n' "$*" >&2; }
die() {
  printf '\n  Abbruch: %s\n\n' "$*" >&2
  exit 1
}

[ "$(id -u)" -eq 0 ] || die "als root ausführen — der Tausch braucht Schreibrecht am Binary und systemctl."
command -v systemctl >/dev/null 2>&1 || die "kein systemctl gefunden. Dieses Skript verwaltet eine systemd-Unit."

# Den Pfad aus der Unit lesen und nicht raten: Die curl-Installation legt das
# Binary unter /usr/local/lib/asylum, das .deb unter /usr/lib/asylum. Wer den
# falschen Pfad überschreibt, hat danach zwei Fassungen und keine Ahnung, welche
# läuft.
pfad_aus_unit() {
  local zeile
  zeile="$(systemctl cat "${DIENST}" 2>/dev/null | grep -m1 '^ExecStart=' || true)"
  [ -n "${zeile}" ] || die "Unit ${DIENST} nicht gefunden. Läuft das Panel auf diesem Rechner?"
  # ExecStart=/usr/local/lib/asylum/asylumd serve --config …
  zeile="${zeile#ExecStart=}"
  # Ein führendes - oder @ ist in systemd erlaubt.
  zeile="${zeile#[-@]}"
  printf '%s' "${zeile%% *}"
}

ZIEL="$(pfad_aus_unit)"
[ -f "${ZIEL}" ] || die "die Unit nennt ${ZIEL}, aber dort liegt keine Datei."
SICHERUNG="${ZIEL}${SICHERUNG_SUFFIX}"

# Der Port aus der Konfiguration, damit die Bereitschaftsprüfung die richtige
# Adresse fragt. Ohne Angabe die Vorgabe.
gesundheitsadresse() {
  local port bind
  port="$(grep -shoE '^[[:space:]]*port:[[:space:]]*[0-9]+' /etc/asylum/config.yaml /etc/asylum/conf.d/*.yaml 2>/dev/null |
    tail -1 | grep -oE '[0-9]+' || true)"
  bind="$(grep -shoE '^[[:space:]]*bind:[[:space:]]*[^[:space:]]+' /etc/asylum/config.yaml /etc/asylum/conf.d/*.yaml 2>/dev/null |
    tail -1 | awk '{print $2}' || true)"
  [ -n "${port}" ] || port=8443
  # Auf 0.0.0.0 gebunden heißt: über localhost erreichbar.
  case "${bind}" in
  "" | "0.0.0.0" | "::" | "*") bind="127.0.0.1" ;;
  esac
  printf 'https://%s:%s/healthz' "${bind}" "${port}"
}

bereit() {
  local adresse="$1" versuch
  # Die Zählvariable wird gebraucht, damit die Schleife endet — nicht im Rumpf.
  for versuch in $(seq 1 30); do
    : "${versuch}"
    # --insecure, weil hier auch ein selbstsigniertes Zertifikat gilt: Geprüft
    # wird die Bereitschaft des eigenen Dienstes über localhost, nicht die
    # Echtheit eines fremden Gegenübers.
    if curl -fsS --insecure --max-time 2 "${adresse}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  return 1
}

zurueck() {
  warn "stelle die vorherige Fassung wieder her"
  install -m 0755 "${SICHERUNG}" "${ZIEL}"
  systemctl restart "${DIENST}" || true
}

if [ "${1:-}" = "--rollback" ]; then
  [ -f "${SICHERUNG}" ] || die "keine Sicherung unter ${SICHERUNG}."
  log "Ziel:      ${ZIEL}"
  log "Sicherung: ${SICHERUNG}"
  systemctl stop "${DIENST}"
  install -m 0755 "${SICHERUNG}" "${ZIEL}"
  systemctl start "${DIENST}"
  if bereit "$(gesundheitsadresse)"; then
    log "zurückgerollt: $("${ZIEL}" version 2>/dev/null | head -1)"
    exit 0
  fi
  die "auch die vorherige Fassung antwortet nicht — journalctl -u ${DIENST} ansehen."
fi

NEU="${1:-}"
[ -n "${NEU}" ] || die "Pfad zum neuen Binary fehlt. Aufruf: $0 /pfad/zu/asylumd"
[ -f "${NEU}" ] || die "${NEU} existiert nicht."
[ -x "${NEU}" ] || die "${NEU} ist nicht ausführbar."

# Ein Binary, das nicht einmal seine Fassung nennen kann, wird nicht eingesetzt.
FASSUNG_NEU="$("${NEU}" version 2>/dev/null | head -1 || true)"
[ -n "${FASSUNG_NEU}" ] || die "${NEU} antwortet nicht auf 'version' — ist das ein asylumd?"
FASSUNG_ALT="$("${ZIEL}" version 2>/dev/null | head -1 || echo 'unbekannt')"

printf '\n  Binärtausch für %s\n\n' "${DIENST}"
log "Ziel:   ${ZIEL}"
log "vorher: ${FASSUNG_ALT}"
log "nachher: ${FASSUNG_NEU}"
printf '\n'

# Migrationen laufen nur vorwärts. Bringt der neue Stand eine mit, trifft die
# vorherige Fassung nach einem Rückweg ein neueres Schema — deshalb der Hinweis
# und nicht nur ein stiller Tausch.
if [ -f /var/lib/asylum/asylum.db ]; then
  warn "Vor einem Stand mit neuer Migration die Datenbank sichern:"
  warn "  systemctl stop ${DIENST} && cp -a /var/lib/asylum/asylum.db{,-wal,-shm} /wohin/auch/immer/"
  printf '\n'
fi

ADRESSE="$(gesundheitsadresse)"
log "Bereitschaft wird geprüft über ${ADRESSE}"

systemctl stop "${DIENST}"
cp -a "${ZIEL}" "${SICHERUNG}"
log "gesichert nach ${SICHERUNG}"

install -m 0755 "${NEU}" "${ZIEL}"

if ! systemctl start "${DIENST}"; then
  zurueck
  die "der Dienst startet mit dem neuen Binary nicht."
fi

if ! bereit "${ADRESSE}"; then
  zurueck
  die "der neue Stand antwortet nicht auf ${ADRESSE} — zurückgerollt. journalctl -u ${DIENST} ansehen."
fi

printf '\n  Fertig. %s läuft.\n' "$("${ZIEL}" version 2>/dev/null | head -1)"
printf '  Neue Oberfläche: /v2/ — die bisherige bleibt unter /\n'
printf '  Zurück mit: %s --rollback\n\n' "$0"
