#!/bin/bash
#
# SFTP — gemessen statt gelesen (docs/57, P6 Schritt 8).
#
# **Warum das hier steht und nicht in tests/.** Kein Wächter dieses Projekts
# kann sagen, was OpenSSH auf dieser Maschine tut. Was `sshd -t` sieht, was ein
# `Match`-Block mit den Zeilen dahinter macht, was ein Neuladen mit einer
# kaputten Datei anrichtet — das beantwortet nur ein laufender sshd.
#
# > **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit
# > Fussnote.** — `docs/44`, und dort hat sie das Panel abgeschaltet.
#
# Nach der Regel dieses Projekts gehört das ins Repo:
#
# > **Was man zweimal braucht, gehört ins Repo — auch wenn es keine Zeile
# > Anwendungscode ist.**
#
# **Es fasst den laufenden sshd nicht an.** Eigener Port, eigene
# Konfigurationsdatei, eigene Wirtsschlüssel, eigene Benutzer (`p99xx`), eigener
# Verzeichnisbaum unter /var/lib. `/etc/ssh/sshd_config` wird gelesen und nie
# geschrieben; `/`, `/var/www` und `/var/www/vhosts` werden **nie** verändert —
# die Kette wird an einem eigenen Baum gemessen und am echten nur *beurteilt*.
#
# **Jede Messung trägt ihre Gegenprobe.** Ohne sie wäre jede Null ein Beleg für
# nichts:
#
# > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
# > steht.**
#
#     sudo bash tests/sftp-messen.sh
#
set -u

PORT=${PORT:-22222}
BASE=${BASE:-/var/lib/sftp-messen}
ABO=p9901
ROOT="$BASE/vhosts/$ABO.invalid"
KEYS="$BASE/keys"
CONF="$BASE/sshd_config"
LOG="$BASE/sshd.log"
PIDF="$BASE/sshd.pid"

ok=0; fehl=0

# Eine Messung mit erwartetem Ergebnis. Der Vergleich steht hier und nicht im
# Kopf des Lesers: Ein Skript, dessen Ausgabe man deuten muss, ist eine
# Messung, die beim zweiten Lauf anders gedeutet wird.
messung() {
  local name="$1" erwartet="$2" gemessen="$3"
  if [ "$gemessen" = "$erwartet" ]; then
    ok=$((ok + 1)); printf '  \033[32mja \033[0m %-52s %s\n' "$name" "$gemessen"
  else
    fehl=$((fehl + 1)); printf '  \033[31mNEIN\033[0m %-52s %s (erwartet: %s)\n' "$name" "$gemessen" "$erwartet"
  fi
}

titel() { printf '\n\033[1m%s\033[0m\n' "$1"; }

[ "$(id -u)" = 0 ] || { echo "Braucht root — es legt Benutzer an und fährt einen sshd."; exit 1; }
command -v sshd >/dev/null || command -v /usr/sbin/sshd >/dev/null || { echo "Kein sshd installiert."; exit 1; }
command -v ssh-keygen >/dev/null || { echo "Kein ssh-keygen — openssh-client fehlt."; exit 1; }
SSHD=$(command -v sshd || echo /usr/sbin/sshd)

aufbau() {
  mkdir -p "$BASE/vhosts" "$KEYS" /run/sshd
  # /var/lib ist root:root 0755 — die Kette darüber taugt für ein Chroot.
  chown root:root "$BASE" "$BASE/vhosts" "$KEYS"; chmod 0755 "$BASE" "$BASE/vhosts" "$KEYS"

  [ -f "$BASE/host_ed25519" ] || ssh-keygen -q -t ed25519 -f "$BASE/host_ed25519" -N ''
  [ -f "$BASE/kunde" ] || ssh-keygen -q -t ed25519 -f "$BASE/kunde" -N '' -C 'messung@srvpanel'
  [ -f "$BASE/fremd" ] || ssh-keygen -q -t rsa -b 3072 -f "$BASE/fremd" -N '' -C 'fremd@srvpanel'

  # Derselbe Zuschnitt wie SubscriptionProvision: eigene Gruppe, kein Passwort,
  # keine Login-Shell, Home ist die Chroot-Wurzel und gehört root.
  groupadd --force "$ABO" >/dev/null 2>&1
  id "$ABO" >/dev/null 2>&1 || useradd --gid "$ABO" --no-user-group \
    --home-dir "$ROOT" --no-create-home --shell /usr/sbin/nologin \
    --comment 'srvpanel-Messung' "$ABO"

  mkdir -p "$ROOT/httpdocs" "$ROOT/.ssh"
  chown root:root "$ROOT"; chmod 0755 "$ROOT"
  chown "$ABO:$ABO" "$ROOT/httpdocs" "$ROOT/.ssh"; chmod 2750 "$ROOT/httpdocs"; chmod 2700 "$ROOT/.ssh"

  cat "$BASE/kunde.pub" > "$KEYS/$ABO"
  chown root:root "$KEYS/$ABO"; chmod 0644 "$KEYS/$ABO"
}

abbau() {
  daemon_stop
  userdel "$ABO" 2>/dev/null
  groupdel "$ABO" 2>/dev/null
  rm -rf "$BASE"
}
trap abbau EXIT

kopf() {
  cat > "$CONF" <<EOF
Port $PORT
ListenAddress 127.0.0.1
HostKey $BASE/host_ed25519
PidFile $PIDF
LogLevel VERBOSE
UsePAM yes
PasswordAuthentication no
KbdInteractiveAuthentication no
Subsystem sftp internal-sftp
EOF
  chmod 0644 "$CONF"
}

# Der verwaltete Block, wie ihn der Plan vorsieht — je Abonnement einer.
block() {
  cat <<EOF
Match User $1
    ChrootDirectory $2
    ForceCommand internal-sftp -u 0027
    AuthorizedKeysFile $KEYS/$1
    PasswordAuthentication no
    PermitTTY no
    AllowTcpForwarding no
    X11Forwarding no
Match all
EOF
}

daemon_start() { : > "$LOG"; "$SSHD" -f "$CONF" -E "$LOG" >/dev/null 2>&1; sleep 0.5; }
daemon_stop()  { [ -f "$PIDF" ] && kill "$(cat "$PIDF")" 2>/dev/null; rm -f "$PIDF"; sleep 0.2; return 0; }

# Ein Anmeldeversuch. „an" oder „ab" — mehr braucht keine dieser Messungen.
anmeldung() {
  local key=${1:-$BASE/kunde}
  if echo 'pwd' | timeout 20 sftp -q -P "$PORT" -i "$key" \
      -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
      -o IdentitiesOnly=yes -o BatchMode=yes "$ABO@127.0.0.1" >/dev/null 2>&1
  then echo an; else echo ab; fi
}

# Was der Server zuletzt zur Ablehnung gesagt hat — die Zeile, die das Panel zeigt.
grund() { grep -oiE '(bad ownership or modes for [a-z ]*"?[^"]*"?|Authentication refused: [^\n]*)' "$LOG" | tail -1; }

# Die effektive Konfiguration für einen Benutzer, aus derselben Quelle, aus der
# sshd sie nimmt. Ein Block, den man geschrieben hat, ist keine Auskunft darüber,
# was gilt.
effektiv() { "$SSHD" -T -C "user=$1,host=127.0.0.1,addr=127.0.0.1" -f "${2:-$CONF}" 2>/dev/null | grep -i "^$3 " | cut -d' ' -f2-; }

wegwerf() { local f="$BASE/wegwerf.conf"; { echo "Port $PORT"; echo "HostKey $BASE/host_ed25519"; echo "Subsystem sftp internal-sftp"; cat; } > "$f"; echo "$f"; }

aufbau

printf '\033[1mSFTP-Messrunde — %s\033[0m\n' "$("$SSHD" -V 2>&1 | head -1)"

# ---------------------------------------------------------------- M9, M10, M6
titel "Der Zugang überhaupt (M6, M9, M10)"
kopf; block "$ABO" "$ROOT" >> "$CONF"; daemon_start
messung "Benutzer ohne Passwort (!), UsePAM yes, Schlüssel" an "$(anmeldung)"
messung "nologin als Shell + ForceCommand internal-sftp" an "$(anmeldung)"
messung "AuthorizedKeysFile absolut, ausserhalb des Chroots" an "$(anmeldung)"

# ------------------------------------------------------------------------- M7
titel "Der Kunde legt sich selbst einen Schlüssel hin (M7)"
install -o "$ABO" -g "$ABO" -m 0600 "$BASE/fremd.pub" "$ROOT/.ssh/authorized_keys"
messung "sein .ssh/authorized_keys wird nicht gelesen" ab "$(anmeldung "$BASE/fremd")"
cp "$BASE/fremd.pub" "$KEYS/$ABO"; chmod 0644 "$KEYS/$ABO"
messung "Gegenprobe: derselbe Schlüssel in der Datei des Agenten" an "$(anmeldung "$BASE/fremd")"
cat "$BASE/kunde.pub" > "$KEYS/$ABO"; chmod 0644 "$KEYS/$ABO"
rm -f "$ROOT/.ssh/authorized_keys"

# ------------------------------------------------------------------------- M8
titel "Die Kette oberhalb der Wurzel (M8)"
kette() { # name erwartet befehl rückweg
  eval "$3"; : > "$LOG"; local r; r=$(anmeldung); eval "$4"
  messung "$1" "$2" "$r"
}
kette "Gegenprobe: Schema unverändert" an "true" "true"
kette "Wurzel gehört dem Benutzer" ab "chown $ABO:$ABO $ROOT" "chown root:root $ROOT"
kette "Wurzel 0775 (gruppenschreibbar)" ab "chmod 0775 $ROOT" "chmod 0755 $ROOT"
kette "Wurzel 0757 (andere schreibbar)" ab "chmod 0757 $ROOT" "chmod 0755 $ROOT"
kette "ein Glied darüber gehört dem Benutzer" ab "chown $ABO:$ABO $BASE/vhosts" "chown root:root $BASE/vhosts"
kette "ein Glied darüber ist 0777" ab "chmod 0777 $BASE/vhosts" "chmod 0755 $BASE/vhosts"
kette "Gegenprobe zurückgesetzt" an "true" "true"

titel "Die Kette der Schlüsseldatei — sie ist eine zweite (M8)"
kette "Datei gruppenschreibbar" ab "chmod 0664 $KEYS/$ABO" "chmod 0644 $KEYS/$ABO"
kette "Verzeichnis 0777" ab "chmod 0777 $KEYS" "chmod 0755 $KEYS"
kette "Datei fehlt ganz (= Zugang aus)" ab "mv $KEYS/$ABO $BASE/weg" "mv $BASE/weg $KEYS/$ABO"
# Und der Befund, der die Regel „nie der Kunde" zu unserer macht:
kette "Datei gehört dem Kunden — OpenSSH nimmt sie AN" an "chown $ABO:$ABO $KEYS/$ABO" "chown root:root $KEYS/$ABO"
kette "Verzeichnis gehört dem Kunden — ebenfalls" an "chown $ABO:$ABO $KEYS" "chown root:root $KEYS"

# --------------------------------------------------------------------- M1, M2
titel "Wo der Block stehen darf (M1, M2)"
F=$(wegwerf <<EOF
Match User $ABO
    ChrootDirectory $ROOT
ClientAliveInterval 77
EOF
)
messung "sshd -t sieht eine verschluckte globale Zeile nicht" 0 "$("$SSHD" -t -f "$F" >/dev/null 2>&1; echo $?)"
messung "die Zeile HINTER dem Block gilt nur für ihn" 77 "$(effektiv "$ABO" "$F" clientaliveinterval)"
messung "  … und für jeden anderen gar nicht" 0 "$(effektiv root "$F" clientaliveinterval)"
F=$(wegwerf <<EOF
Match User $ABO
    ChrootDirectory $ROOT
Match all
ClientAliveInterval 77
EOF
)
messung "mit abschliessendem 'Match all' ist sie wieder global" 77 "$(effektiv root "$F" clientaliveinterval)"
F=$(wegwerf <<EOF
Match User $ABO
    ChrootDirectory /erster
Match User $ABO
    ChrootDirectory /zweiter
Match all
EOF
)
messung "zwei passende Blöcke: der erste gewinnt" /erster "$(effektiv "$ABO" "$F" chrootdirectory)"

# ------------------------------------------------------------------------- M3
titel "Drop-in gegen verwalteten Block (M3)"
mkdir -p "$BASE/dropin"
printf 'Match User %s\n    ChrootDirectory /aus-dem-dropin\n' "$ABO" > "$BASE/dropin/60-srvpanel.conf"
F=$(wegwerf <<EOF
Include $BASE/dropin/*.conf
ClientAliveInterval 77
Match User $ABO
    ChrootDirectory /aus-dem-bestand
Match all
EOF
)
messung "ein Match im Drop-in leckt nicht in die Hauptdatei" 77 "$(effektiv root "$F" clientaliveinterval)"
messung "aber es schlägt den Bestand — weil Include oben steht" /aus-dem-dropin "$(effektiv "$ABO" "$F" chrootdirectory)"

# --------------------------------------------------------------------- M4, M5
titel "Was 'sshd -t' sieht und was nicht (M4, M5)"
prüfe() { "$SSHD" -t -f "$(wegwerf)" >/dev/null 2>&1; echo $?; }
messung "unbekanntes Schlüsselwort" 255 "$(prüfe <<'EOF'
Klabautermann ja
EOF
)"
messung "unbekannte Match-Bedingung" 255 "$(prüfe <<'EOF'
Match Nutzer p9901
    ChrootDirectory /tmp
Match all
EOF
)"
messung "Match ohne Argument" 255 "$(prüfe <<'EOF'
Match
Match all
EOF
)"
messung "ChrootDirectory auf ein fehlendes Verzeichnis — NICHT" 0 "$(prüfe <<'EOF'
Match User p9901
    ChrootDirectory /gibt/es/nicht
Match all
EOF
)"
messung "ChrootDirectory mit falschen Rechten — NICHT" 0 "$(prüfe <<EOF
Match User $ABO
    ChrootDirectory /tmp
Match all
EOF
)"
messung "AuthorizedKeysFile auf eine fehlende Datei — NICHT" 0 "$(prüfe <<'EOF'
Match User p9901
    AuthorizedKeysFile /gibt/es/nicht
Match all
EOF
)"
mkdir -p "$BASE/dropin2"; echo 'Klabautermann ja' > "$BASE/dropin2/99.conf"
messung "-t zieht das Include mit" 255 "$("$SSHD" -t -f "$(wegwerf <<EOF
Include $BASE/dropin2/*.conf
EOF
)" >/dev/null 2>&1; echo $?)"

titel "Die Einschleusung: ein Zeilenumbruch in einem Namen"
F=$(wegwerf <<EOF
PermitRootLogin no
Match User $ABO
    ChrootDirectory $BASE/vhosts/$ABO.invalid
    PermitRootLogin yes
Match all
EOF
)
messung "sshd -t sieht sie nicht" 0 "$("$SSHD" -t -f "$F" >/dev/null 2>&1; echo $?)"
messung "und die untergeschobene Zeile gilt" yes "$(effektiv "$ABO" "$F" permitrootlogin)"

# ------------------------------------------------------------------- M11, M12
titel "Neuladen — die Landmine (M11, M12)"
kopf; block "$ABO" "$ROOT" >> "$CONF"; daemon_stop; daemon_start
PID=$(cat "$PIDF" 2>/dev/null)
messung "vorher erreichbar" an "$(anmeldung)"
echo 'Klabautermann ja' >> "$CONF"
kill -HUP "$PID" 2>/dev/null; sleep 1
messung "kaputte Datei + HUP: der Dienst ist WEG" ab "$(anmeldung)"
messung "  … und niemand horcht mehr" leer "$(ss -ltn 2>/dev/null | grep -c ":$PORT " | sed 's/^0$/leer/;s/^[1-9].*/besetzt/')"
kopf; block "$ABO" "$ROOT" >> "$CONF"; daemon_stop; daemon_start
PID=$(cat "$PIDF" 2>/dev/null)
printf 'Match User %s\n    ChrootDirectory %s\nMatch all\n' "$ABO" "$ROOT" >> "$CONF"
kill -HUP "$PID" 2>/dev/null; sleep 1
messung "Gegenprobe: heile Datei + HUP, Dienst lebt" an "$(anmeldung)"
messung "systemd prüft vor dem HUP (ExecReload)" 1 "$(grep -c 'ExecReload=.*sshd -t' /usr/lib/systemd/system/ssh.service 2>/dev/null || echo 0)"

# ------------------------------------------------------------------------ M13
titel "Der Schlüssel selbst (M13)"
{ printf 'restrict '; cat "$BASE/kunde.pub"; } > "$KEYS/$ABO"; chmod 0644 "$KEYS/$ABO"
messung "'restrict' vor dem Schlüssel — SFTP geht weiter" an "$(anmeldung)"
{ printf 'command="/usr/bin/id" '; cat "$BASE/kunde.pub"; } > "$KEYS/$ABO"; chmod 0644 "$KEYS/$ABO"
daemon_stop; daemon_start
# Erst anmelden, dann im Protokoll nachsehen: `daemon_start` leert es. Beim
# ersten Anlauf stand hier nur das Nachsehen, und die leere Antwort sah aus
# wie ein Befund — sie war die Abwesenheit einer Messung.
anmeldung > /dev/null
messung "ForceCommand schlägt ein untergeschobenes command=" \
  "internal-sftp -u 0027" "$(grep -o "forced-command (config) '[^']*'" "$LOG" | tail -1 | sed "s/.*'\(.*\)'/\1/")"
cat "$BASE/kunde.pub" > "$KEYS/$ABO"; chmod 0644 "$KEYS/$ABO"

for k in kunde fremd; do
  von_ssh=$(ssh-keygen -lf "$BASE/$k.pub" | awk '{print $2}')
  von_php=$(php -r '
    $teile = preg_split("/\s+/", trim(file_get_contents($argv[1])));
    $blob = base64_decode($teile[1] ?? "", true);
    if (! is_string($blob) || $blob === "") { echo "leer"; exit; }
    echo "SHA256:", rtrim(base64_encode(hash("sha256", $blob, true)), "=");
  ' "$BASE/$k.pub" 2>/dev/null)
  messung "Fingerabdruck in PHP == ssh-keygen ($k)" "$von_ssh" "$von_php"
done

titel "Der echte Baum dieser Maschine — nur gelesen, nie geändert"
beurteile() {
  local p=$1
  [ -e "$p" ] || { printf '  %-44s %s\n' "$p" "gibt es nicht"; return; }
  local o m; o=$(stat -c '%U' "$p"); m=$(stat -c '%a' "$p")
  local urteil="taugt"
  [ "$o" = root ] || urteil="Eigentümer $o"
  [ $(( 8#$m & 8#022 )) -eq 0 ] || urteil="$urteil, schreibbar für andere ($m)"
  printf '  %-44s %-6s %-8s %s\n' "$p" "$o" "$m" "$urteil"
}
for p in / /var /var/www /var/www/vhosts /etc/ssh /etc/ssh/sshd_config; do beurteile "$p"; done
for p in /var/www/vhosts/*/; do beurteile "${p%/}"; done 2>/dev/null

printf '\n\033[1m%d wie erwartet, %d abweichend\033[0m\n' "$ok" "$fehl"
[ "$fehl" = 0 ]
