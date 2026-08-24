#!/bin/sh
# Die Messrunde vor A1 — Paketquellen und Systemupdates.
#
# **Nur lesen.** Dieses Skript installiert nichts, entfernt nichts und schreibt
# nirgends nach /etc. Die einzige Ausnahme ist die Gegenprobe zu M5, und die
# legt ihre Quelle, ihre Listen und ihren Zwischenspeicher in ein eigenes
# Verzeichnis unter /tmp — apt fasst den Bestand dabei nicht an. Damit ist es
# auf einem echten Server fahrbar, und genau dafür ist es da: Was hier steht,
# ist gegen Ubuntu 24.04 gemessen, und die drei anderen Zielplattformen fehlen.
#
# **Jede Messung hat eine Gegenprobe.** Der Grund steht in CLAUDE.md und ist
# dreimal bezahlt: Eine Null ist nur dann eine Messung, wenn daneben etwas
# anderes als Null steht. Wo eine Messung „nichts gefunden" meldet, muss die
# Gegenprobe zeigen, dass sie überhaupt hinsieht.
#
# Aufruf:  sh tests/apt-messen.sh
# Ausgabe: eine Zeile je Messung, dazu der Rohtext für die, die geparst werden.
set -u

PROBE="${TMPDIR:-/tmp}/apt-messen.$$"
trap 'rm -rf "${PROBE}"' EXIT INT TERM
mkdir -p "${PROBE}/lists/partial" "${PROBE}/parts"

titel() { printf '\n=== %s\n' "$1"; }
wert()  { printf '  %-34s %s\n' "$1" "$2"; }

. /etc/os-release
titel "M0 — Plattform"
wert "ID / VERSION_ID" "${ID} ${VERSION_ID}"
wert "VERSION_CODENAME" "${VERSION_CODENAME:-—}"
wert "apt" "$(apt-get --version 2>/dev/null | head -1)"
wert "dpkg" "$(dpkg --version 2>/dev/null | head -1)"

# ---------------------------------------------------------------------------
# M1 — Die Form der Quellen. Einzeilig, deb822 oder beides nebeneinander?
#
# Die Frage entscheidet, ob das Panel eine Datei überhaupt lesen darf: Eine
# deb822-Datei trägt mehrere Stanzas, `Suites:` kann mehrere Suiten in einer
# Zeile führen, und `Signed-By:` kann ein ganzer PGP-Block sein, gefaltet über
# vierzig Zeilen mit führendem Leerzeichen.
# ---------------------------------------------------------------------------
titel "M1 — Form der Quellen"
wert ".list-Dateien" "$(ls /etc/apt/sources.list.d/*.list 2>/dev/null | wc -l | tr -d ' ')"
wert ".sources-Dateien" "$(ls /etc/apt/sources.list.d/*.sources 2>/dev/null | wc -l | tr -d ' ')"
wert "/etc/apt/sources.list mit Inhalt" \
    "$(grep -cE '^[[:space:]]*(deb|deb-src)[[:space:]]' /etc/apt/sources.list 2>/dev/null)"
wert "Stanzas gesamt (deb822)" \
    "$(cat /etc/apt/sources.list.d/*.sources 2>/dev/null | grep -cE '^Types:')"
wert "Signed-By inline (PGP-Block)" \
    "$(grep -lE '^Signed-By:[[:space:]]*-----BEGIN' /etc/apt/sources.list.d/*.sources 2>/dev/null | wc -l | tr -d ' ')"
wert "Suites-Zeile mit mehreren Suiten" \
    "$(awk '/^Suites:/ && NF>2' /etc/apt/sources.list.d/*.sources 2>/dev/null | wc -l | tr -d ' ')"

# ---------------------------------------------------------------------------
# M2 — apt-get indextargets: die aufgelöste Sicht von apt selbst.
#
# Das ist die Antwort auf M1. Statt deb822 nachzubauen, fragt man apt, was es
# tatsächlich benutzt — mit Origin, Suite, Komponente und `Trusted`.
#
# Die Gegenprobe: Die Zahl der Ziele muss grösser sein als die Zahl der
# Dateien. Ist sie gleich, wurde nicht aufgelöst, sondern gezählt.
# ---------------------------------------------------------------------------
titel "M2 — apt-get indextargets"
ZIELE="$(apt-get indextargets 2>/dev/null | grep -c '^Created-By')"
DATEIEN="$(ls /etc/apt/sources.list.d/ 2>/dev/null | wc -l | tr -d ' ')"
wert "Ziele" "${ZIELE}"
wert "Gegenprobe: Dateien" "${DATEIEN} (Ziele müssen mehr sein)"
wert "nicht vertraut (Trusted != yes)" \
    "$(apt-get indextargets --format '$(TRUSTED)' 2>/dev/null | grep -cv '^yes$')"
printf '  --- Repositorien (Site | Suite | Komponente):\n'
apt-get indextargets --format '$(SITE)|$(RELEASE)|$(COMPONENT)' 2>/dev/null \
    | sort -u | sed 's/^/      /' | head -20

# ---------------------------------------------------------------------------
# M3 — Die Form einer Inst-Zeile.
#
# `apt-get -s dist-upgrade` ist die Quelle für „was ist aktualisierbar". Zwei
# Dinge daran sind Fallen, und beide stehen unten als eigene Zeile:
#
#   Inst coreutils [9.4-3ubuntu6.1] (9.4-3ubuntu6.2 Ubuntu:24.04/noble-updates [amd64])
#   Inst cowsay (3.03+dfsg2-8 Ubuntu:24.04/noble [all])
#
# 1. Die eckige Klammer mit der alten Fassung **fehlt bei einer Neuinstallation**
#    — und die Architektur steht ebenfalls in eckigen Klammern, am Ende, in der
#    Rundklammer. Wer „die eckige Klammer" nimmt, verwechselt beide.
# 2. Die Herkunft ist eine **Liste**: `Ubuntu:24.04/noble-updates,
#    Ubuntu:24.04/noble-security`. Ein Sicherheitsupdate erkennt man daran,
#    dass irgendeine davon auf `-security` endet — nicht an der ersten.
# ---------------------------------------------------------------------------
titel "M3 — Form der Inst-Zeilen"
SIM="${PROBE}/sim.txt"
apt-get -s dist-upgrade > "${SIM}" 2>/dev/null
wert "Inst gesamt" "$(grep -c '^Inst' "${SIM}")"
wert "davon mit [alte Fassung]" "$(grep -cE '^Inst [^ ]+ \[' "${SIM}")"
wert "davon ohne (Neuinstallation)" "$(grep -cE '^Inst [^ ]+ \(' "${SIM}")"
wert "davon Sicherheit (-security)" "$(grep '^Inst' "${SIM}" | grep -c -- '-security')"
wert "Remv-Zeilen" "$(grep -c '^Remv' "${SIM}")"
wert "mehrfache Herkunft (Komma)" "$(grep '^Inst' "${SIM}" | grep -cE '\(.*,.*\)')"
printf '  --- die ersten drei Inst-Zeilen im Rohtext:\n'
grep '^Inst' "${SIM}" | head -3 | sed 's/^/      /'

# ---------------------------------------------------------------------------
# M4 — upgrade gegen dist-upgrade: was wird zurückgehalten?
#
# `upgrade` lässt Pakete stehen, die etwas entfernen würden. Diese Zahl gehört
# in die Anzeige, sonst behauptet sie Vollständigkeit, die sie nicht hat.
# ---------------------------------------------------------------------------
titel "M4 — zurückgehalten"
apt-get -s upgrade > "${PROBE}/up.txt" 2>/dev/null
wert "Inst bei upgrade" "$(grep -c '^Inst' "${PROBE}/up.txt")"
wert "Inst bei dist-upgrade" "$(grep -c '^Inst' "${SIM}")"
wert "kept back gemeldet" \
    "$(grep -c 'kept back' "${PROBE}/up.txt")"

# ---------------------------------------------------------------------------
# M5 — Der Rückgabewert von `apt-get update`.  DIE WICHTIGSTE MESSUNG.
#
# Gemessen: Er ist **0**, auch wenn jede einzelne Quelle unerreichbar war. Die
# Fehlschläge stehen als `W:`-Zeilen auf stderr, und apt arbeitet mit den alten
# Listen weiter.
#
# Das trifft bestehenden Code: PhpVersionInstall, PgServerInstall und
# PanelUpdate rufen `apt-get update -qq` und prüfen `successful()`. Diese
# Prüfung kann für eine kaputte Quelle nicht rot werden.
#
# Die Gegenprobe läuft gegen eine Quelle auf 127.0.0.1:1 — die gibt es
# garantiert nicht, und sie fasst den Bestand nicht an.
# ---------------------------------------------------------------------------
titel "M5 — Rückgabewert von apt-get update"
printf 'deb http://127.0.0.1:1/gibtsnicht %s main\n' "${VERSION_CODENAME:-stable}" > "${PROBE}/kaputt.list"
UOPT="-o Dir::Etc::sourcelist=${PROBE}/kaputt.list -o Dir::Etc::sourceparts=${PROBE}/parts -o Dir::State::Lists=${PROBE}/lists"
# shellcheck disable=SC2086
apt-get update -qq ${UOPT} >"${PROBE}/u1.txt" 2>&1
wert "rc bei kaputter Quelle" "$?  (erwartet 0 — das ist der Befund)"
wert "W:-Zeilen dabei" "$(grep -c '^W:' "${PROBE}/u1.txt")"
# shellcheck disable=SC2086
apt-get update -qq --error-on=any ${UOPT} >"${PROBE}/u2.txt" 2>&1
wert "rc mit --error-on=any" "$?  (erwartet 100)"
wert "Gegenprobe: E:-Zeilen" "$(grep -c '^E:' "${PROBE}/u2.txt")"

# ---------------------------------------------------------------------------
# M6 — Signaturschlüssel und ihr Ablauf.
#
# Feld 7 der `pub`-Zeile ist der Ablauf als Unixzeit; leer heisst „läuft nie
# ab". Ein abgelaufener Schlüssel bricht `apt-get update` — und weil M5 dabei
# 0 zurückgibt, merkt es niemand.
# ---------------------------------------------------------------------------
titel "M6 — Signaturschlüssel"
GEFUNDEN=0
MITABLAUF=0
for KEY in /usr/share/keyrings/*.gpg /etc/apt/keyrings/* /etc/apt/trusted.gpg.d/*; do
    [ -f "${KEY}" ] || continue
    GEFUNDEN=$((GEFUNDEN + 1))
    N="$(gpg --show-keys --with-colons "${KEY}" 2>/dev/null | awk -F: '$1=="pub" && $7!=""' | wc -l | tr -d ' ')"
    MITABLAUF=$((MITABLAUF + N))
done
wert "Schlüsselbunde gelesen" "${GEFUNDEN}"
wert "Schlüssel mit Ablaufdatum" "${MITABLAUF}"
wert "Gegenprobe: pub-Zeilen gesamt" \
    "$(for K in /usr/share/keyrings/*.gpg /etc/apt/keyrings/*; do [ -f "$K" ] && gpg --show-keys --with-colons "$K" 2>/dev/null; done | grep -c '^pub')"

# ---------------------------------------------------------------------------
# M7 — Neustart nötig?
#
# /run/reboot-required kommt von update-notifier-common und ist auf einem
# Server oft nicht installiert. Fehlt die Datei, heisst das **nicht** „kein
# Neustart nötig" — es heisst „nicht nachgesehen". Genau die Lehre, die
# SystemInfo::kernelStale() schon trägt.
# ---------------------------------------------------------------------------
titel "M7 — Neustart nötig"
wert "update-notifier-common" \
    "$(dpkg-query -W -f='${db:Status-Status}' update-notifier-common 2>/dev/null || echo 'nicht installiert')"
if [ -f /run/reboot-required ]; then
    wert "/run/reboot-required" "vorhanden"
    wert "Pakete dahinter" "$(wc -l < /run/reboot-required.pkgs 2>/dev/null || echo '—')"
else
    wert "/run/reboot-required" "fehlt (heisst: nicht nachgesehen, nicht: nein)"
fi
wert "laufender Kernel" "$(uname -r)"
wert "Abbilder in /boot" "$(ls /boot/vmlinuz-* 2>/dev/null | wc -l | tr -d ' ')"

# ---------------------------------------------------------------------------
# M8 — unattended-upgrades: der wirksame Zustand, nicht die eigene Datei.
#
# `apt-config dump` löst alle Dateien unter apt.conf.d in lexikalischer
# Reihenfolge auf, letzte gewinnt. Ein fremdes Paket kann die eigene Einstellung
# damit still überschreiben — hier tut es genau das (docker-disable-periodic-update
# setzt APT::Periodic::Enable auf 0).
#
# Eine Auskunft aus der eigenen Datei ist deshalb keine über den Zustand.
# ---------------------------------------------------------------------------
titel "M8 — unbeaufsichtigte Updates"
wert "unattended-upgrades" \
    "$(dpkg-query -W -f='${db:Status-Status}' unattended-upgrades 2>/dev/null || echo 'nicht installiert')"
wert "Dateien in apt.conf.d" "$(ls /etc/apt/apt.conf.d/ 2>/dev/null | wc -l | tr -d ' ')"
printf '  --- wirksame Werte laut apt-config dump:\n'
apt-config dump 2>/dev/null | grep -iE 'APT::Periodic|Unattended-Upgrade' | sed 's/^/      /' | head -8
[ -n "$(apt-config dump 2>/dev/null | grep -iE 'APT::Periodic|Unattended')" ] || printf '      (nichts gesetzt)\n'

# ---------------------------------------------------------------------------
# M9 — Conffiles, die ein Lauf zurücklässt.
#
# Mit --force-confold bleibt der Bestand und daneben liegt eine .dpkg-dist.
# Sie anzuzeigen ist der Unterschied zwischen „das Panel hat entschieden" und
# „das Panel hat entschieden und es gesagt".
# ---------------------------------------------------------------------------
titel "M9 — zurückgelassene Conffiles"
wert ".dpkg-dist unter /etc" "$(find /etc -name '*.dpkg-dist' 2>/dev/null | wc -l | tr -d ' ')"
wert ".dpkg-new unter /etc" "$(find /etc -name '*.dpkg-new' 2>/dev/null | wc -l | tr -d ' ')"
wert ".ucf-dist unter /etc" "$(find /etc -name '*.ucf-dist' 2>/dev/null | wc -l | tr -d ' ')"

# ---------------------------------------------------------------------------
# M10 — Die Sperren, an denen ein zweiter Lauf scheitert.
# ---------------------------------------------------------------------------
titel "M10 — Sperren"
for L in /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock; do
    if [ -e "${L}" ]; then wert "${L}" "vorhanden"; else wert "${L}" "fehlt"; fi
done

# ---------------------------------------------------------------------------
# M11 — Die Historie, aus der A5 liest.
# ---------------------------------------------------------------------------
titel "M11 — /var/log/apt/history.log"
if [ -r /var/log/apt/history.log ]; then
    wert "Blöcke" "$(grep -c '^Start-Date:' /var/log/apt/history.log)"
    wert "mit Commandline" "$(grep -c '^Commandline:' /var/log/apt/history.log)"
    wert "mit Requested-By" "$(grep -c '^Requested-By:' /var/log/apt/history.log)"
    wert "rotierte Fassungen" "$(ls /var/log/apt/history.log.*.gz 2>/dev/null | wc -l | tr -d ' ')"
else
    wert "history.log" "nicht lesbar"
fi

printf '\n=== fertig. Was hier steht, gilt für %s %s und für keine andere Plattform.\n' \
    "${ID}" "${VERSION_ID}"
