#!/bin/sh
# Die vier Fälle aus `docs/81 §2.3`, die im Container nicht von allein vorkamen.
#
# Aufruf:  sh tests/apt-faelle-messen.sh
#
# **Dieses Skript ist NICHT server-sicher — anders als sein Geschwister.**
# `tests/apt-messen.sh` sagt im Kopf zu, nichts zu installieren, nichts zu
# entfernen und nirgends nach /etc zu schreiben; genau deshalb darf es auf
# `cloudsrv24` laufen. Hier ist das Gegenteil der Zweck: Die vier Fälle kommen
# nicht von allein vor, sie müssen **hergestellt** werden. Das Skript setzt eine
# Sperrmarkierung, installiert ein Paket neu und schreibt damit in
# `/var/log/apt/history.log`. Es gehört in einen Wegwerf-Container und nirgends
# sonst hin.
#
# **Warum es die Fälle überhaupt braucht.** `docs/81 §2.3` führt sie als die
# Messungen, die auf einer Null ohne Nachbarn standen:
#
#   | Fall | Messung | ohne ihn misst … |
#   |------|---------|------------------|
#   | Inst ohne [alt] | M3 | … der Leser nie eine Neuinstallation |
#   | zurückgehaltenes Paket | M4 | … die Zahl, die in die Anzeige gehört, nie |
#   | Schlüssel mit Ablauf | M6 | … die Ablaufprüfung nichts |
#   | Requested-By | M11 | … A5 eine Auskunft, die es nie gesehen hat |
#
# > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
# > steht.**
#
# Jeder Fall gibt deshalb **zwei** Zahlen aus: den Zustand vorher und den
# hergestellten. Sind beide gleich, ist der Fall nicht hergestellt worden — und
# dann ist über die Messung nichts gesagt.
set -eu

PROBE="${TMPDIR:-/tmp}/apt-faelle.$$"
HALT=""
aufraeumen() {
    if [ -n "${HALT}" ]; then
        apt-mark unhold "${HALT}" >/dev/null 2>&1 || true
    fi
    rm -rf "${PROBE}"
}
trap aufraeumen EXIT INT TERM
mkdir -p "${PROBE}"

OFFEN=0

titel() { printf '\n=== %s\n' "$1"; }
wert()  { printf '  %-38s %s\n' "$1" "$2"; }

# **Der Zähler ist der Kern und nicht die Ausgabe.** Ein Fall, der nicht
# hergestellt wurde, druckt sonst zwei gleiche Zahlen, und niemand liest sie.
#
# > Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die kleiner
# > werden kann.
paar() {
    if [ "$2" = "$3" ]; then
        printf '  %-38s vorher %-6s hergestellt %-6s NICHT HERGESTELLT\n' "$1" "$2" "$3"
        OFFEN=$((OFFEN + 1))
    else
        printf '  %-38s vorher %-6s hergestellt %s\n' "$1" "$2" "$3"
    fi
}

ausgefallen() {
    printf '  %-38s %s\n' "$1" "FALL NICHT HERGESTELLT"
    OFFEN=$((OFFEN + 1))
}

. /etc/os-release
titel "F0 — Plattform"
wert "ID / VERSION_ID" "${ID} ${VERSION_ID}"

apt-get update -qq >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# F1 — Eine Inst-Zeile ohne [alte Fassung].
#
# Die Falle aus M3, Punkt 1: Bei einer Neuinstallation fehlt die eckige Klammer
# mit der alten Fassung — und die Architektur steht ebenfalls in eckigen
# Klammern, nur am Ende in der Rundklammer. Wer „die eckige Klammer" nimmt,
# verwechselt beide.
#
# Hergestellt wird die Zeile über `apt-get -s install` eines Pakets, das nicht
# installiert ist. **Das ist dieselbe Zeile wie aus einem dist-upgrade** — apt
# erzeugt sie im selben Code; was sich unterscheidet, ist der Anlass und nicht
# die Form. Ein echtes dist-upgrade, das eine Neuinstallation nach sich zieht,
# liesse sich nicht zuverlässig herbeiführen.
# ---------------------------------------------------------------------------
titel "F1 — Inst ohne [alte Fassung] (M3)"
apt-get -s dist-upgrade > "${PROBE}/du.txt" 2>/dev/null || true
OHNE_VORHER="$(grep -cE '^Inst [^ ]+ \(' "${PROBE}/du.txt" || true)"

KANDIDAT=""
for P in cowsay sl figlet toilet; do
    if apt-cache show "${P}" >/dev/null 2>&1 && ! dpkg -s "${P}" >/dev/null 2>&1; then
        KANDIDAT="${P}"; break
    fi
done

if [ -z "${KANDIDAT}" ]; then
    ausgefallen "kein Kandidat gefunden"
else
    apt-get -s install "${KANDIDAT}" > "${PROBE}/inst.txt" 2>/dev/null || true
    OHNE_NACHHER="$(grep -cE '^Inst [^ ]+ \(' "${PROBE}/inst.txt" || true)"
    paar "Inst ohne [alt] (${KANDIDAT})" "${OHNE_VORHER}" "${OHNE_NACHHER}"
    wert "Gegenprobe: Inst MIT [alt]" \
        "$(grep -cE '^Inst [^ ]+ \[' "${PROBE}/du.txt" || true)"
    printf '  --- der Rohtext:\n'
    grep -E '^Inst [^ ]+ \(' "${PROBE}/inst.txt" | head -2 | sed 's/^/      /'
fi

# ---------------------------------------------------------------------------
# F2 — Ein zurückgehaltenes Paket.
#
# `apt-get upgrade` lässt zurück, was etwas entfernen würde — und eine
# Sperrmarkierung erzeugt denselben Zustand willentlich. Die Zahl gehört in die
# Anzeige, sonst behauptet sie Vollständigkeit, die sie nicht hat.
# ---------------------------------------------------------------------------
titel "F2 — zurückgehaltenes Paket (M4)"
apt-get -s upgrade > "${PROBE}/up0.txt" 2>/dev/null || true
KEPT_VORHER="$(grep -ci 'kept back' "${PROBE}/up0.txt" || true)"

HALT="$(apt list --upgradable 2>/dev/null | sed -n 's#^\([a-z0-9][a-z0-9.+-]*\)/.*#\1#p' | head -1)"
if [ -z "${HALT}" ]; then
    ausgefallen "kein aktualisierbares Paket"
else
    apt-mark hold "${HALT}" >/dev/null 2>&1
    apt-get -s upgrade > "${PROBE}/up1.txt" 2>/dev/null || true
    KEPT_NACHHER="$(grep -ci 'kept back' "${PROBE}/up1.txt" || true)"
    paar "„kept back\" gemeldet (${HALT})" "${KEPT_VORHER}" "${KEPT_NACHHER}"
    wert "Inst bei upgrade vorher/nachher" \
        "$(grep -c '^Inst' "${PROBE}/up0.txt" || true) / $(grep -c '^Inst' "${PROBE}/up1.txt" || true)"
    printf '  --- der Rohtext:\n'
    grep -i -A2 'kept back' "${PROBE}/up1.txt" | head -3 | sed 's/^/      /'
    apt-mark unhold "${HALT}" >/dev/null 2>&1
    HALT=""
fi

# ---------------------------------------------------------------------------
# F3 — Ein Signaturschlüssel MIT Ablaufdatum.
#
# M6 liest Feld 7 der `pub`-Zeile; leer heisst „läuft nie ab". Ohne einen
# Schlüssel, der wirklich abläuft, misst diese Prüfung nichts — sie zählt dann
# nur Nullen.
#
# Hergestellt in einem eigenen GNUPGHOME: Der Bestand des Rechners wird nicht
# angefasst, und die Zahl bleibt trotzdem echt, weil dieselbe Abfrage läuft.
# ---------------------------------------------------------------------------
titel "F3 — Schlüssel mit Ablaufdatum (M6)"
if ! command -v gpg >/dev/null 2>&1; then
    ausgefallen "gpg fehlt"
else
    GNUPGHOME="${PROBE}/gnupg"; export GNUPGHOME
    mkdir -p "${GNUPGHOME}"; chmod 700 "${GNUPGHOME}"
    gpg --batch --quiet --passphrase '' --quick-generate-key 'Ohne Ablauf <nie@example.invalid>' default default never  >/dev/null 2>&1
    gpg --batch --quiet --passphrase '' --quick-generate-key 'Mit Ablauf <bald@example.invalid>'  default default 1y     >/dev/null 2>&1
    gpg --batch --quiet --export > "${PROBE}/bund.gpg" 2>/dev/null

    PUB="$(gpg --show-keys --with-colons "${PROBE}/bund.gpg" 2>/dev/null | grep -c '^pub' || true)"
    MITABLAUF="$(gpg --show-keys --with-colons "${PROBE}/bund.gpg" 2>/dev/null | awk -F: '$1=="pub" && $7!=""' | wc -l | tr -d ' ')"
    paar "Schlüssel mit Ablauf im Bund" "0" "${MITABLAUF}"
    wert "Gegenprobe: pub-Zeilen im Bund" "${PUB}"
    printf '  --- Feld 7 je pub-Zeile:\n'
    gpg --show-keys --with-colons "${PROBE}/bund.gpg" 2>/dev/null \
        | awk -F: '$1=="pub"{print "      Ablauf: " ($7=="" ? "(leer — laeuft nie ab)" : $7)}'
    unset GNUPGHOME
fi

# ---------------------------------------------------------------------------
# F4 — Requested-By in der Historie.
#
# Die Auskunft, für die A5 die Historie überhaupt führt: **wer** das Update
# angestossen hat. Sie steht nicht in jedem Block — nur, wenn apt nicht direkt
# als root gerufen wurde.
#
# **Gelesen wird `SUDO_UID` und nicht `SUDO_USER`.** Gemessen am 26. August
# 2026: Mit `SUDO_USER=messkonto` allein bleibt die Zeile aus, mit
# `SUDO_UID=1000` erscheint sie — und apt löst die Zahl selbst zum Namen auf
# (`Requested-By: ubuntu (1000)`).
#
# > **Eine Umgebungsvariable, die den Namen trägt, ist nicht die, die gelesen
# > wird.**
# ---------------------------------------------------------------------------
titel "F4 — Requested-By (M11)"
LOG=/var/log/apt/history.log
if [ ! -r "${LOG}" ]; then
    ausgefallen "history.log nicht lesbar"
else
    RB_VORHER="$(grep -c '^Requested-By:' "${LOG}" || true)"

    # Erst die Gegenprobe: ohne SUDO_UID darf die Zeile NICHT entstehen.
    apt-get install -y --reinstall -qq hostname >/dev/null 2>&1 || true
    RB_OHNE="$(grep -c '^Requested-By:' "${LOG}" || true)"

    SUDO_UID=1000 apt-get install -y --reinstall -qq hostname >/dev/null 2>&1 || true
    RB_MIT="$(grep -c '^Requested-By:' "${LOG}" || true)"

    paar "Requested-By-Zeilen" "${RB_VORHER}" "${RB_MIT}"
    wert "Gegenprobe: ohne SUDO_UID" "${RB_OHNE} (unverändert = richtig)"
    printf '  --- der letzte Block:\n'
    tail -6 "${LOG}" | sed 's/^/      /'
fi

printf '\n=== %s %s: %s von 4 Fällen nicht hergestellt.\n' \
    "${ID}" "${VERSION_ID}" "${OFFEN}"

# **Der Rückgabewert ist die eigentliche Zusage.** Ein Lauf, der nur druckt,
# meldet einen ausgefallenen Fall genauso grün wie einen hergestellten — und
# dann steht in der CI eine Messrunde, die nichts gemessen hat.
if [ "${OFFEN}" -ne 0 ]; then
    echo "Diese Plattform hat nicht alle vier Fälle hergestellt." >&2
    exit "${OFFEN}"
fi
