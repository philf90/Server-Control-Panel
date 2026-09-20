#!/bin/bash
# Was eine Platte über einen Tag wirklich tut — die offene Messung 2 aus
# `docs/129 §10`. Sie hält B1 auf, und geraten werden darf sie nicht.
#
#     sudo bash tests/plattenkurve-messen.sh [stunden] [abstand_sekunden]
#
# Über den Abmeldevorgang hinaus, damit ein geschlossenes Terminal die Messung
# nicht mitnimmt:
#
#     systemd-run --unit=plattenkurve --collect \
#         bash /pfad/zu/tests/plattenkurve-messen.sh 24 300
#     journalctl -u plattenkurve -f        # zusehen
#     systemctl stop plattenkurve          # vorzeitig beenden, mit Auswertung
#
# **Wofür die Kurve gebraucht wird.** Entscheidung 4 hält Rohdateien 14 Tage
# und verdichtete Zahlen 30. Beide Zahlen sind gesetzt, keine ist gemessen:
# Was sie kosten, hängt daran, wie viele Bytes ein Tag an Protokollen
# hinterlässt und wie viel die Rotation davon zurückgibt. Ohne diese Kurve ist
# jede Haltezeit geraten — und geraten wird sie auf einer Platte, die voll
# läuft, ohne dass jemand dabei zusieht.
#
# **Lesend, mit einer einzigen Ausnahme.** Der Prüfkörper aus P1 entsteht in
# einem eigenen Verzeichnis neben der Protokollwurzel und wird sofort wieder
# entfernt; sonst fasst dieses Skript nichts an. Es läuft auf einem Server im
# Betrieb, und ein Messmittel, das dort etwas hinterlässt, ist keines.
#
# **Die Gegenprobe kommt zuerst und ist keine Formsache.** Eine flache Kurve
# ist nur dann eine Messung, wenn daneben steht, dass dieses Mittel einen
# Anstieg überhaupt sehen würde. P1 legt deshalb einen Prüfkörper bekannter
# Grösse an und verlangt, dass `df` **und** die Summe der Dateigrössen ihn
# bemerken. Sieht ihn eine der beiden nicht, bricht die Messung ab, statt einen
# ganzen Tag lang Nullen zu sammeln.
#
# **Der Gegenstand hält nicht still.** Ein Zugriffsprotokoll rotiert über
# Nacht: Die Datei wird umbenannt, eine neue entsteht unter demselben Namen,
# und die Grösse springt auf Null zurück. Wer nur Grössen vergleicht, liest
# daraus eine Platte, die sich selbst leert. Gemerkt wird deshalb die
# **Inode** jeder Datei; wechselt sie, ist das eine Rotation und kein
# Schrumpfen. Die Uhrzeit, zu der das geschieht, ist zugleich die Gegenprobe
# zu `systemctl list-timers` — die Liste sagt, wann es vorgesehen ist, die
# Kurve sagt, wann es wirklich passiert ist.
set -u

STUNDEN=${1:-24}
ABSTAND=${2:-300}
VHOSTS=${VHOSTS:-/var/www/vhosts}
AUSGABE=${AUSGABE:-/var/tmp/srvpanel-plattenkurve-$(date +%Y%m%d-%H%M%S).tsv}
PRUEFKOERPER_MIB=10

titel() { printf '\n=== %s\n' "$1"; }
wert()  { printf '  %-46s %s\n' "$1" "$2"; }
satz()  { printf '  %s\n' "$1"; }

# ---------------------------------------------------------------------------
# P0 — Plattform. Fehlt etwas, ist das kein Befund, sondern ein Abbruch.
# ---------------------------------------------------------------------------
titel "P0 — Plattform"
for w in df du stat find date; do
    command -v "$w" >/dev/null || { echo "  FEHLT: $w — Messung abgebrochen."; exit 2; }
done

[ -d "$VHOSTS" ] || { echo "  FEHLT: $VHOSTS — dies ist kein Panel-Server."; exit 2; }

wert "Server"            "$(hostname)"
wert "Protokollwurzel"   "$VHOSTS"
wert "Dateisystem"       "$(df --output=source,fstype "$VHOSTS" | tail -1 | tr -s ' ')"
wert "srvpanel"          "$(srvpanel version 2>/dev/null || echo 'nicht im Pfad')"
wert "Dauer"             "${STUNDEN} h, alle ${ABSTAND} s"
wert "Ausgabe"           "$AUSGABE"

# Die Zahlen dieses Laufs an einer Stelle. `du -sb` zählt belegte Bytes über
# den ganzen Baum, `stat -c %s` nur die offenen Zugriffsprotokolle — die
# Differenz ist alles Rotierte und Komprimierte, und genau die ist die Frage.
protokolle_bytes() { du -sb "$VHOSTS" 2>/dev/null | awk '{print $1}'; }

zugriff_bytes() {
    local summe=0 groesse
    while IFS= read -r -d '' datei; do
        groesse=$(stat -c %s "$datei" 2>/dev/null) || continue
        summe=$((summe + groesse))
    done < <(find "$VHOSTS" -mindepth 4 -maxdepth 4 -name access.log -type f -print0 2>/dev/null)
    printf '%s' "$summe"
}

zugriff_inodes() {
    find "$VHOSTS" -mindepth 4 -maxdepth 4 -name access.log -type f \
        -printf '%p\t%i\n' 2>/dev/null | sort
}

df_used()  { df -B1 --output=used  "$VHOSTS" | tail -1 | tr -d ' '; }
df_avail() { df -B1 --output=avail "$VHOSTS" | tail -1 | tr -d ' '; }

# ---------------------------------------------------------------------------
# P1 — Die Gegenprobe. Sieht dieses Mittel einen Anstieg, den es selbst
# verursacht hat? Erst wenn ja, bedeutet eine flache Kurve etwas.
# ---------------------------------------------------------------------------
titel "P1 — Gegenprobe: sieht das Mittel überhaupt einen Anstieg?"

frei=$(df_avail)
if [ "$frei" -lt $((2 * 1024 * 1024 * 1024)) ]; then
    echo "  ABBRUCH: unter 2 GiB frei — auf dieser Platte legt dieses Skript nichts an."
    exit 2
fi

PROBE_DIR="$VHOSTS/.plattenkurve-probe.$$"
mkdir -p "$PROBE_DIR" || { echo "  ABBRUCH: $PROBE_DIR nicht anlegbar."; exit 2; }
trap 'rm -rf "$PROBE_DIR"' EXIT

used_vorher=$(df_used)
baum_vorher=$(protokolle_bytes)

dd if=/dev/zero of="$PROBE_DIR/koerper" bs=1M count=$PRUEFKOERPER_MIB status=none
sync

used_nachher=$(df_used)
baum_nachher=$(protokolle_bytes)

d_used=$((used_nachher - used_vorher))
d_baum=$((baum_nachher - baum_vorher))
erwartet=$((PRUEFKOERPER_MIB * 1024 * 1024))

wert "Prüfkörper"                 "$erwartet Bytes"
wert "df hat bemerkt"             "$d_used Bytes"
wert "die Baumsumme hat bemerkt"  "$d_baum Bytes"

rm -rf "$PROBE_DIR"
trap - EXIT
sync

# Beide müssen ihn sehen. `df` darf daneben liegen — ein Server im Betrieb
# schreibt währenddessen anderes —, aber nicht um Grössenordnungen; die
# Baumsumme dagegen zählt Bytes und muss den Körper genau treffen.
if [ "$d_baum" -lt "$erwartet" ]; then
    echo "  ABBRUCH: die Baumsumme hat den Prüfkörper nicht gesehen. Ihre Nullen sagten nichts."
    exit 2
fi
if [ "$d_used" -lt $((erwartet / 2)) ]; then
    echo "  ABBRUCH: df hat den Prüfkörper nicht gesehen. Seine Nullen sagten nichts."
    exit 2
fi
satz "Beide sehen ihn — ab hier bedeutet eine flache Kurve eine flache Platte."

# ---------------------------------------------------------------------------
# P2 — Der Bestand vor dem ersten Abtasten. Ohne ihn ist die erste Zeile der
# Kurve ein Anfang ohne Herkunft.
# ---------------------------------------------------------------------------
titel "P2 — Bestand"
anzahl=$(zugriff_inodes | wc -l)
wert "Zugriffsprotokolle"   "$anzahl"
wert "davon offen (Bytes)"  "$(zugriff_bytes)"
wert "ganzer Baum (Bytes)"  "$(protokolle_bytes)"
wert "belegt (Bytes)"       "$(df_used)"
wert "frei (Bytes)"         "$(df_avail)"

if [ "$anzahl" -eq 0 ]; then
    satz "KEINE Zugriffsprotokolle gefunden — die Kurve misst dann nur die Platte."
fi

# ---------------------------------------------------------------------------
# P3 — Die Kurve. Eine Zeile je Abtastung, und jede trägt ihren Zeitstempel
# selbst: Ein Lauf, der zwischendurch hängt, soll das in den Daten zeigen und
# nicht in einer gleichmässigen Reihe verstecken.
# ---------------------------------------------------------------------------
titel "P3 — Kurve"
printf 'zeit\tepoch\tdf_belegt\tdf_frei\tbaum_bytes\tzugriff_bytes\tn_logs\tn_rotationen\n' > "$AUSGABE"

INODES_VORHER=$(zugriff_inodes)
ROTATIONEN=0
# Ganzzahlarithmetik der Shell könnte „0,25 h" nicht — und ein Messmittel,
# das sich nur in Stundenschritten prüfen lässt, wird nicht geprüft.
ENDE=$(awk -v j="$(date +%s)" -v h="$STUNDEN" 'BEGIN { printf "%d", j + h * 3600 }')
N=0

auswerten() {
    titel "P4 — Was die Kurve sagt"
    if [ "$N" -lt 2 ]; then
        satz "Weniger als zwei Abtastungen — daraus wird keine Kurve."
        return
    fi

    awk -F'\t' 'NR==2 { e0=$2; f0=$4; b0=$5; z0=$6 }
        NR>1 { eN=$2; fN=$4; bN=$5; zN=$6; n++ }
        END {
            stunden = (eN - e0) / 3600
            printf "  %-46s %s\n", "Abtastungen", n
            if (stunden <= 0) { print "  Kein Zeitraum — daraus wird keine Rate."; exit }
            printf "  %-46s %.2f\n", "gemessener Zeitraum (h)", stunden
            printf "  %-46s %d\n",   "Baum gewachsen (Bytes)",  bN - b0
            printf "  %-46s %d\n",   "davon offene Protokolle", zN - z0
            printf "  %-46s %d\n",   "frei verloren (Bytes)",   f0 - fN
            printf "  %-46s %.0f\n", "hochgerechnet je Tag (Bytes)", (bN - b0) * 24 / stunden
        }' "$AUSGABE"
    wert "Rotationen gesehen" "$ROTATIONEN"
    wert "Daten" "$AUSGABE"

    titel "Was diese Messung NICHT sagt"
    satz "— Sie sagt nichts über einen anderen Tag. Ein Werktag und ein Sonntag"
    satz "  sind zwei Messungen, und diese ist eine."
    satz "— Sie sagt nichts über den Takt von A7. Das ist die andere Hälfte von"
    satz "  docs/129 §10 Punkt 2 und steht in der Diagnose, nicht auf der Platte."
    satz "— Sie sagt nichts darüber, wie sich der Baum auf Abonnements verteilt."
    satz "  Gezählt ist die Summe; eine einzelne laute Domain sieht genauso aus"
    satz "  wie vierzig leise."
    satz "— Sie sagt nichts über Bytes, die nie auf der Platte ankommen. Was"
    satz "  nginx verwirft, bevor es protokolliert wird, fehlt hier und im"
    satz "  Protokoll gleichermassen."
    satz "— Die erste Abtastung ist kalt, alle weiteren nicht. Für die Kurve ist"
    satz "  das gleichgültig, für eine Laufzeitmessung wäre es der ganze Befund."
}
trap 'auswerten; exit 0' INT TERM

while [ "$(date +%s)" -lt "$ENDE" ]; do
    jetzt=$(date +%s)

    inodes_jetzt=$(zugriff_inodes)
    if [ "$inodes_jetzt" != "$INODES_VORHER" ]; then
        gewechselt=$(comm -13 <(printf '%s\n' "$INODES_VORHER") <(printf '%s\n' "$inodes_jetzt") | wc -l)
        ROTATIONEN=$((ROTATIONEN + gewechselt))
        printf '  %s  Rotation/Neuanlage bemerkt: %s Datei(en)\n' "$(date --iso-8601=seconds)" "$gewechselt"
        INODES_VORHER=$inodes_jetzt
    fi

    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
        "$(date --iso-8601=seconds)" "$jetzt" \
        "$(df_used)" "$(df_avail)" \
        "$(protokolle_bytes)" "$(zugriff_bytes)" \
        "$(printf '%s\n' "$inodes_jetzt" | grep -c .)" "$ROTATIONEN" >> "$AUSGABE"

    N=$((N + 1))
    [ $((N % 12)) -eq 0 ] && printf '  %s  %s Abtastungen\n' "$(date --iso-8601=seconds)" "$N"

    sleep "$ABSTAND"
done

auswerten
