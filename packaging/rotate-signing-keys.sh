#!/usr/bin/env bash
#
# Neue Signaturschlüssel erzeugen.
#
# Zwei Schlüssel hängen an einer Freigabe, und sie tun Verschiedenes:
#
#   OpenPGP   signiert die Release-Datei des apt-Repositories. Ohne ihn nimmt
#             kein apt-Client das Repository an.
#   minisign  signiert SHA256SUMS neben den .deb-Dateien. Das ist die Prüfung
#             für alles, was an apt vorbei heruntergeladen wird.
#
# **Dieses Skript läuft auf dem Rechner des Projektinhabers, nicht in der CI
# und nicht in einer Wegwerf-Umgebung.** Es erzeugt privates Schlüsselmaterial.
# Wo das entsteht, entscheidet, wer es sehen kann.
#
# Ins Repository schreibt das Skript ausschließlich die öffentlichen Teile. Das
# private Material landet in einem frisch angelegten Verzeichnis mit 0700
# außerhalb des Arbeitsbaums — damit es nicht versehentlich in einen Commit
# gerät. Von dort gehört es in einen Passwortspeicher und in die vier
# GitHub-Secrets, danach gelöscht.
#
# Aufruf:
#   packaging/rotate-signing-keys.sh [--email <adresse>] [--name <bezeichnung>] [--yes]

set -euo pipefail

NAME="SrvPanel Archive Signing Key"
EMAIL="philipp@netzhost24.de"
ASSUME_YES=0

while [ $# -gt 0 ]; do
    case "$1" in
        --name)  NAME="$2"; shift 2 ;;
        --email) EMAIL="$2"; shift 2 ;;
        --yes)   ASSUME_YES=1; shift ;;
        -h|--help)
            sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Unbekannte Option: $1" >&2
            exit 2
            ;;
    esac
done

REPO="$(cd "$(dirname "$0")/.." && pwd)"
KEYRING="${REPO}/packaging/srvpanel-archive-keyring.gpg"
KEYRING_ASC="${REPO}/packaging/srvpanel-archive-keyring.asc"
MINISIGN_PUB="${REPO}/packaging/minisign.pub"

for tool in gpg minisign; do
    command -v "$tool" >/dev/null 2>&1 || {
        echo "Fehlt: ${tool}. Unter Debian/Ubuntu: apt-get install gnupg minisign" >&2
        exit 1
    }
done

# Der Schlüsseltausch ist nicht rückgängig zu machen, sobald etwas damit
# veröffentlicht wurde: Bestehende Installationen kennen nur den alten
# öffentlichen Schlüssel und bekommen den neuen nicht von selbst.
if [ -f "$KEYRING" ]; then
    echo "Im Arbeitsbaum liegen bereits Schlüssel:"
    gpg --show-keys --with-colons "$KEYRING" 2>/dev/null \
        | awk -F: '/^fpr/{print "  OpenPGP  " $10; exit}'
    [ -f "$MINISIGN_PUB" ] && echo "  minisign $(tail -n1 "$MINISIGN_PUB")"
    echo
    echo "Sie werden überschrieben. Wer den alten Schlüssel schon benutzt hat,"
    echo "kann mit dem neuen Repository nichts mehr anfangen, bis er den neuen"
    echo "öffentlichen Schlüssel bekommt — siehe docs/21-signaturschluessel.md."
    echo
    if [ "$ASSUME_YES" -ne 1 ]; then
        printf 'Weiter? [ja/NEIN] '
        read -r answer
        [ "$answer" = "ja" ] || { echo "Abgebrochen."; exit 1; }
    fi
fi

# Alles, was ab hier entsteht, ist privat, bis es ausdrücklich freigegeben
# wird. Die öffentlichen Teile bekommen beim Kopieren in den Arbeitsbaum
# wieder 0644.
umask 077

WORK="$(mktemp -d "${TMPDIR:-/tmp}/srvpanel-keys.XXXXXXXX")"
chmod 700 "$WORK"

export GNUPGHOME="${WORK}/gnupg"
mkdir -p "$GNUPGHOME"
chmod 700 "$GNUPGHOME"

# Zwei getrennte Passphrasen, beide zufällig. Selbstgewählte sind bei einem
# Schlüssel, der von einem CI-Runner benutzt wird, kein Gewinn: Er tippt sie
# nicht ein, sie steht ohnehin als Secret daneben.
random_passphrase() {
    head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-32
}

GPG_PASSPHRASE="$(random_passphrase)"
MINISIGN_PASSPHRASE="$(random_passphrase)"

echo "==> OpenPGP-Schlüssel erzeugen (ed25519, nur Signatur, ohne Ablauf)"

# Ohne Ablaufdatum, und das ist eine bewusste Entscheidung: Der öffentliche
# Schlüssel liegt bei den Nutzern in /usr/share/keyrings und wird von dort
# durch nichts aktualisiert. Ein ablaufender Schlüssel bräche „apt update" auf
# jedem installierten Server an einem Stichtag, ohne Zutun und ohne Vorwarnung.
# Der Preis dafür ist, dass ein verlorener Schlüssel nur durch einen Tausch
# aus der Welt kommt — dokumentiert in docs/21-signaturschluessel.md.
if ! gpg --batch --quiet --gen-key >"${WORK}/gen-gpg.log" 2>&1 <<EOF
Key-Type: eddsa
Key-Curve: Ed25519
Key-Usage: sign
Name-Real: ${NAME}
Name-Email: ${EMAIL}
Expire-Date: 0
Passphrase: ${GPG_PASSPHRASE}
%commit
EOF
then
    cat "${WORK}/gen-gpg.log" >&2
    exit 1
fi

FINGERPRINT="$(gpg --list-secret-keys --with-colons | awk -F: '/^fpr/{print $10; exit}')"
[ -n "$FINGERPRINT" ] || { echo "Kein Schlüssel entstanden." >&2; exit 1; }

gpg --export "$FINGERPRINT" > "${WORK}/archive-keyring.gpg"
gpg --armor --export "$FINGERPRINT" > "${WORK}/archive-keyring.asc"
gpg --armor --export-secret-keys \
    --pinentry-mode loopback --passphrase "$GPG_PASSPHRASE" \
    "$FINGERPRINT" > "${WORK}/APT_GPG_KEY.asc"

echo "==> minisign-Schlüssel erzeugen"

# minisign fragt die Passphrase auf dem Terminal ab und schreibt „Password:"
# nach stderr, auch wenn sie über die Standardeingabe kommt. Hier tippt
# niemand — die Aufforderungen wären nur verwirrend, also weg damit. Geht
# etwas schief, meldet sich set -e mit dem Rückgabecode.
printf '%s\n%s\n' "$MINISIGN_PASSPHRASE" "$MINISIGN_PASSPHRASE" \
    | minisign -G -f -p "${WORK}/minisign.pub" -s "${WORK}/MINISIGN_KEY.key" \
      >/dev/null 2>"${WORK}/minisign-gen.log" \
    || { cat "${WORK}/minisign-gen.log" >&2; exit 1; }

# Gegenprobe, bevor irgendetwas kopiert oder eingefügt wird.
#
# Geprüft wird aus der Sicht eines Nutzers: leerer Schlüsselring, nur der
# exportierte öffentliche Schlüssel darin. Ein Exportfehler fiele sonst erst
# beim ersten Release auf — nach dem Bauen, kurz vor dem Punkt, an dem die
# Artefakte sichtbar werden.
echo "==> Beide Schlüssel gegen ihren öffentlichen Teil prüfen"
echo "probe" > "${WORK}/probe.txt"

gpg --batch --yes --pinentry-mode loopback --passphrase "$GPG_PASSPHRASE" \
    --local-user "$FINGERPRINT" --armor --detach-sign \
    -o "${WORK}/probe.sig" "${WORK}/probe.txt"

VERIFY_HOME="${WORK}/gnupg-verify"
mkdir -p "$VERIFY_HOME"
chmod 700 "$VERIFY_HOME"

# Still bei Erfolg, vollständig bei Fehlschlag. gpg schreibt beim Prüfen eines
# frischen Schlüssels immer „not certified with a trusted signature" — das ist
# hier richtig so (ihn hat noch niemand beglaubigt) und nur dann eine Meldung
# wert, wenn ohnehin etwas schiefging.
{
    GNUPGHOME="$VERIFY_HOME" gpg --quiet --import "${WORK}/archive-keyring.gpg" &&
    GNUPGHOME="$VERIFY_HOME" gpg --quiet --verify "${WORK}/probe.sig" "${WORK}/probe.txt"
} >"${WORK}/verify-gpg.log" 2>&1 || {
    cat "${WORK}/verify-gpg.log" >&2
    echo "OpenPGP: Die Probe wurde nicht angenommen — der Export taugt nicht." >&2
    exit 1
}
echo "    OpenPGP: Signatur wird vom exportierten Schlüssel angenommen."

{
    printf '%s\n' "$MINISIGN_PASSPHRASE" \
        | minisign -s "${WORK}/MINISIGN_KEY.key" -Sm "${WORK}/probe.txt" &&
    minisign -Vm "${WORK}/probe.txt" -P "$(tail -n1 "${WORK}/minisign.pub")"
} >"${WORK}/verify-minisign.log" 2>&1 || {
    cat "${WORK}/verify-minisign.log" >&2
    echo "minisign: Die Probe wurde nicht angenommen." >&2
    exit 1
}
echo "    minisign: Signatur wird vom erzeugten Public Key angenommen."

# Erst jetzt in den Arbeitsbaum — nur die öffentlichen Teile.
cp "${WORK}/archive-keyring.gpg" "$KEYRING"
cp "${WORK}/archive-keyring.asc" "$KEYRING_ASC"
cp "${WORK}/minisign.pub"        "$MINISIGN_PUB"
chmod 0644 "$KEYRING" "$KEYRING_ASC" "$MINISIGN_PUB"

cat <<REPORT

────────────────────────────────────────────────────────────────────────
Fertig. Im Repository geändert (öffentlich, gehört in den Commit):

  packaging/srvpanel-archive-keyring.gpg   ${FINGERPRINT}
  packaging/srvpanel-archive-keyring.asc
  packaging/minisign.pub                   $(tail -n1 "$MINISIGN_PUB")

Privates Material liegt in (0700, außerhalb des Arbeitsbaums):

  ${WORK}

Vier Repository-Secrets setzen — Settings → Secrets and variables →
Actions. Die Werte stehen in den genannten Dateien:

  APT_GPG_KEY          <  ${WORK}/APT_GPG_KEY.asc   (vollständig, mit BEGIN/END)
  APT_GPG_PASSPHRASE      ${GPG_PASSPHRASE}
  MINISIGN_KEY         <  ${WORK}/MINISIGN_KEY.key  (beide Zeilen)
  MINISIGN_PASSWORD       ${MINISIGN_PASSPHRASE}

Danach in dieser Reihenfolge — die Reihenfolge ist nicht beliebig:

  1. Passphrasen und beide privaten Dateien in den Passwortspeicher.
  2. Die drei geänderten Dateien committen und pushen. **Vor der Prüfung**:
     Sie checkt den Keyring aus dem Repository aus. Läge dort noch der
     alte, meldete sie einen Fingerprint-Fehler — der wie ein falsch
     eingefügtes Secret aussieht und keiner ist.
  3. Die vier Secrets setzen.
  4. Actions → „Signatur-Secrets prüfen" von Hand auslösen, auf dem
     Branch, auf dem die neuen Schlüssel liegen. Der Lauf signiert
     wirklich und veröffentlicht nichts. Erst wenn er grün ist, passt
     alles zusammen.
  5. rm -rf ${WORK}

Ein Pull Request ist dafür nicht nötig; die Prüfung läuft auf jedem Branch.
Vor dem ersten Tag müssen die neuen Schlüssel aber auf main liegen — die
Freigabe wird vom Tag ausgelöst und liest sie von dort.

Nichts taggen, solange die Prüfung nicht grün war: Ein Release mit einem
Secret, das nicht zum veröffentlichten Schlüssel passt, bricht mitten im
Veröffentlichen ab.
────────────────────────────────────────────────────────────────────────
REPORT
