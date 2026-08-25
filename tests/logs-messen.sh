#!/bin/sh
# Was die Logquellen wirklich sind — die Messrunde vor A5 (docs/81 §11).
#
#     sh tests/logs-messen.sh
#
# **Rein lesend.** Diese Messung schreibt nirgends hin und legt keine Datei an;
# sie ist damit auf einem echten Server fahrbar, und genau dafür ist sie da:
# Was hier steht, ist gegen einen Container ohne systemd gemessen, und die
# interessanteste Quelle — das Journal — gibt es dort gar nicht.
#
# **Jede Messung hat eine Gegenprobe.** Eine Null ist nur dann eine Messung,
# wenn daneben etwas anderes als Null steht.
set -u

titel() { printf '\n=== %s\n' "$1"; }
wert()  { printf '  %-40s %s\n' "$1" "$2"; }

titel "L0 — Plattform"
. /etc/os-release 2>/dev/null || true
wert "ID / VERSION_ID" "${ID:-?} ${VERSION_ID:-?}"
wert "systemd gebootet" "$([ -d /run/systemd/system ] && echo ja || echo nein)"

# ---------------------------------------------------------------------------
# L1 — Die Dateien der Positivliste.
#
# Gefragt wird nach Rechten und Grösse, nicht nur nach „vorhanden": Der Agent
# läuft als root und kommt überall hin, aber die Grösse entscheidet, ob ein
# Tail rückwärts nötig ist (WebLogsTail liest deshalb in Blöcken von hinten).
#
# **Fehlt eine Datei, ist das kein Fehler.** Ein Server, der noch nichts
# protokolliert hat, hat sie nicht — und das ist etwas anderes als „nicht
# nachgesehen".
# ---------------------------------------------------------------------------
titel "L1 — Die Dateien"
GEFUNDEN=0
for L in /var/lib/srvpanel/storage/logs/laravel.log \
         /var/log/srvpanel/update.log \
         /var/log/srvpanel/agent.log \
         /var/log/srvpanel/panel-error.log \
         /var/log/srvpanel/panel-access.log \
         /var/log/apt/history.log \
         /var/log/auth.log; do
    if [ -e "${L}" ]; then
        GEFUNDEN=$((GEFUNDEN + 1))
        wert "${L}" "$(stat -c '%A %U:%G %s Bytes' "${L}")"
    else
        wert "${L}" "fehlt"
    fi
done
wert "davon vorhanden" "${GEFUNDEN} von 7"

# ---------------------------------------------------------------------------
# L2 — journalctl, und der Fund, der den Entwurf entschieden hat.
#
# **Der Rückgabewert unterscheidet nicht.** Gemessen am 24. August 2026 gegen
# systemd 255 in einem Container ohne Journal:
#
#     journalctl -u <unit>            rc=0, stdout „-- No entries --",
#                                     stderr „No journal files were found."
#     journalctl -u <gibt-es-nicht>   dasselbe, Zeichen für Zeichen
#
# Ein unbekannter Unitname, ein fehlendes Journal und eine Unit ohne Ausgabe
# sehen also gleich aus — und `-- No entries --` steht dabei auf **stdout**,
# also genau dort, wo ein Leser die Zeilen erwartet.
#
#   Ein Leser, der `-- No entries --` als Zeile nimmt, zeigt eine Meldung des
#   Werkzeugs als Inhalt des Protokolls.
#
# Die Gegenprobe zeigt, dass der Rückgabewert sehr wohl etwas tragen kann: Ein
# unbekanntes Ausgabeformat endet mit 1.
# ---------------------------------------------------------------------------
titel "L2 — journalctl"
if command -v journalctl >/dev/null 2>&1; then
    wert "Fassung" "$(journalctl --version 2>/dev/null | head -1)"

    journalctl -u srvpanel-web -n 5 --no-pager >/tmp/l2a.out 2>/tmp/l2a.err
    wert "rc bei echter Unit" "$?"
    wert "  stdout" "$(wc -c </tmp/l2a.out | tr -d ' ') Bytes: $(head -1 /tmp/l2a.out)"
    wert "  stderr" "$(head -1 /tmp/l2a.err)"

    journalctl -u gibt-es-nicht-xyz -n 5 --no-pager >/tmp/l2b.out 2>/dev/null
    wert "rc bei erfundener Unit" "$?  (erwartet 0 — das ist der Befund)"
    wert "  stdout" "$(head -1 /tmp/l2b.out)"

    # Gegenprobe: Der Rückgabewert kann einen Fehler tragen — nur diesen nicht.
    journalctl -o gibt-es-nicht -n 1 >/dev/null 2>/tmp/l2c.err
    wert "Gegenprobe: unbekanntes Format" "rc=$?  $(head -1 /tmp/l2c.err)"

    rm -f /tmp/l2a.out /tmp/l2a.err /tmp/l2b.out /tmp/l2c.err
else
    wert "journalctl" "fehlt — dann gibt es auf diesem System kein Journal"
fi

# ---------------------------------------------------------------------------
# L3 — Die Form von /var/log/apt/history.log.
#
# Das ist die Auskunft darüber, **wer** ein Paket eingespielt hat — auch an der
# Kommandozeile, also auch an diesem Panel vorbei. `Requested-By` steht nur
# da, wenn der Lauf nicht als root lief; im Container fehlt es deshalb
# vollständig (docs/81 §2.1, M11).
# ---------------------------------------------------------------------------
titel "L3 — apt-Historie"
if [ -r /var/log/apt/history.log ]; then
    wert "Blöcke (Start-Date)" "$(grep -c '^Start-Date:' /var/log/apt/history.log)"
    wert "mit Commandline" "$(grep -c '^Commandline:' /var/log/apt/history.log)"
    wert "mit Requested-By" "$(grep -c '^Requested-By:' /var/log/apt/history.log)  (0 heisst: alles lief als root)"
    # Gegenprobe: Der Leser findet in dieser Datei überhaupt Zeilen. Steht hier
    # 0, bedeuten die Zahlen darüber nichts.
    wert "Gegenprobe: Zeilen gesamt" "$(wc -l < /var/log/apt/history.log | tr -d ' ')"
else
    wert "/var/log/apt/history.log" "nicht lesbar"
fi

# ---------------------------------------------------------------------------
# L4 — Wie gross wird das? Die Frage hinter dem Rückwärtslesen.
#
# `WebLogsTail::tail()` liest von hinten in Blöcken, weil ein Zugriffsprotokoll
# hunderte Megabyte gross wird und `file()` es ganz in den Speicher läse. Hier
# wird gemessen, ob diese Sorge auf diesem Server begründet ist.
# ---------------------------------------------------------------------------
titel "L4 — Grösse und Rotation"
for D in /var/log/srvpanel /var/log/apt /var/log/nginx; do
    if [ -d "${D}" ]; then
        wert "${D}" "$(du -sh "${D}" 2>/dev/null | cut -f1) · $(find "${D}" -type f | wc -l | tr -d ' ') Dateien"
    else
        wert "${D}" "fehlt"
    fi
done
wert "rotierte Fassungen (.1/.gz/.xz)" "$(find /var/log -maxdepth 2 -name '*.log.*' 2>/dev/null | wc -l | tr -d ' ')"

printf '\n'
