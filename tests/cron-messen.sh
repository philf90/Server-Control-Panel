#!/bin/bash
#
# Cron — gemessen statt gelesen (docs/60, P6 Schritt 9).
#
# **Warum das hier steht und nicht in tests/.** Kein Wächter dieses Projekts
# kann sagen, was cron auf dieser Maschine tut. Ob eine kaputte Zeile nur sich
# selbst oder die ganze Datei mitnimmt, was ein `%` im Befehlsteil anrichtet,
# wie lange es dauert, bis eine neue Datei in `/etc/cron.d` gilt — das
# beantwortet nur ein laufender cron.
#
# > **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit
# > Fussnote.** — `docs/44`, und dort hat sie das Panel abgeschaltet.
#
# Nach der Regel dieses Projekts gehört das ins Repo:
#
# > **Was man zweimal braucht, gehört ins Repo — auch wenn es keine Zeile
# > Anwendungscode ist.**
#
# **Es fasst das laufende System nicht an.** Das Skript startet sich selbst in
# einem eigenen Mount-Namensraum neu und legt Wegwerf-Verzeichnisse über
# `/etc/cron.d`, `/etc/crontab`, `/var/spool/cron/crontabs` und
# `/etc/localtime`. Ausserhalb des Namensraums ist davon nichts zu sehen; ein
# etwaiger echter cron-Dienst wird weder gelesen noch angehalten. Angelegt
# werden zwei Benutzer (`p99xx`), und die räumt {@see abbau} wieder weg.
#
# **Jede Messung trägt ihre Gegenprobe.** Ohne sie wäre jede Null ein Beleg für
# nichts:
#
# > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
# > steht.**
#
# Die Gegenprobe steht hier nie als Kommentar, sondern immer als eigene Zeile in
# der Ausgabe: Zu „die Datei wurde ignoriert" gehört sichtbar ein „und diese
# hier nicht".
#
# **Laufzeit rund 20 Minuten.** Cron feuert zur vollen Minute; jede Messung, die
# auf einen Lauf wartet, kostet eine. Das Skript wartet deshalb gebündelt: Es
# legt alle Prüfkörper einer Runde auf einmal aus und sieht danach einmal nach.
# In diesem Entwicklungscontainer ist Vordergrund-`sleep` blockiert — hier
# gehört es in einen Hintergrundlauf.
#
#     sudo bash tests/cron-messen.sh
#
set -u
# Eine gesetzte TZ schlüge jede /etc/localtime, die Runde 3 und 4 unterlegen —
# und die beiden Runden mässen dann die Umgebung dieser Sitzung.
unset TZ

BASE=${BASE:-/var/lib/cron-messen}
ABO=p9901
ABO2=p9902

CD="$BASE/cron.d"                 # wird über /etc/cron.d gelegt
SPOOL="$BASE/crontabs"            # über /var/spool/cron/crontabs
M="$BASE/marker"                  # hierhin schreiben die Jobs
CMD="$BASE/cmd"                   # die .cmd-Dateien des Entwurfs
LOG="$BASE/cron.log"
ZONE="$BASE/zoneinfo"

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

# Eine Zahl, die niemand vorhersagen kann — sie wird berichtet, nicht bewertet.
# Ohne diese Form stünde eine Laufzeit als „NEIN" da, nur weil sie 3 statt 2
# Sekunden war.
notiz() { printf '  \033[36m--\033[0m   %-52s %s\n' "$1" "$2"; }

titel() { printf '\n\033[1m%s\033[0m\n' "$1"; }

marker() { [ -e "$M/$1" ] && echo ja || echo nein; }
inhalt() { [ -s "$M/$1" ] && tr -d '\n' < "$M/$1" || echo '(leer)'; }

[ "$(id -u)" = 0 ] || { echo "Braucht root — es legt Benutzer an und fährt einen cron."; exit 1; }
command -v /usr/sbin/cron >/dev/null || { echo "Kein cron installiert: apt-get install cron"; exit 1; }

# ---------------------------------------------------------------------------
# Der eigene Namensraum. Ohne ihn schriebe dieses Skript in das /etc/cron.d der
# Maschine — und ein Messskript, das den Prüfling verändert, misst sich selbst.
# ---------------------------------------------------------------------------
if [ "${CRON_MESSEN_NS:-}" != "1" ]; then
  export CRON_MESSEN_NS=1
  exec unshare -m "$0" "$@"
fi

CRONPID=

daemon_stop() {
  [ -n "$CRONPID" ] && kill "$CRONPID" 2>/dev/null
  CRONPID=
  return 0
}

# `-f` hält cron im Vordergrund, `-x` schreibt seine Diagnose auf die
# Standardfehlerausgabe. Das ist der einzige Weg an cron-Meldungen heran: Ohne
# `-x` gehen sie an syslog, und einen syslog gibt es in diesem Container nicht.
# Genau diese Meldungen braucht das Panel später, um zu sagen, *warum* etwas
# nicht läuft.
daemon_start() {
  : > "$LOG"
  /usr/sbin/cron -f -x sch,pars,load,misc >> "$LOG" 2>&1 &
  CRONPID=$!
  sleep 1

  # **Ein Dienst, der nicht läuft, macht aus jeder Erwartung „nein" ein Grün.**
  #
  # Am 18. August auf `cloudsrv24` genau so passiert: cron starb beim Start an
  # der Sperrdatei, das Skript wartete danach zwanzig Minuten und meldete am
  # Ende „15 Messungen wie erwartet, 17 abweichend". Die fünfzehn waren
  # sämtlich Fälle, in denen *nichts* laufen sollte — erfüllt von einem cron,
  # der gar nicht lief.
  #
  # > **Fünfzehn Nullen sind keine fünfzehn Messungen.**
  #
  # Der Lauf hält deshalb hier an und nicht am Ende. Die Meldung des Dienstes
  # steht dabei, denn sie sagt schon, was fehlt.
  if ! kill -0 "$CRONPID" 2>/dev/null; then
    echo
    echo "cron ist nach dem Start sofort gestorben. Was er dazu gesagt hat:"
    sed 's/^/  /' "$LOG"
    exit 1
  fi
}

daemon_lebt() { [ -n "$CRONPID" ] && kill -0 "$CRONPID" 2>/dev/null && echo ja || echo nein; }

abbau() {
  daemon_stop
  userdel "$ABO" 2>/dev/null
  userdel "$ABO2" 2>/dev/null
  groupdel "$ABO" 2>/dev/null
  groupdel "$ABO2" 2>/dev/null
  rm -rf "$BASE"
}
trap abbau EXIT

aufbau() {
  rm -rf "$BASE"
  mkdir -p "$CD" "$SPOOL" "$M" "$CMD" "$ZONE"
  chmod 0755 "$CD"; chmod 1730 "$SPOOL"
  # Die Jobs laufen als p9901 und müssen ihre Spuren ablegen können.
  chmod 0777 "$M"
  chmod 0755 "$CMD"

  # Derselbe Zuschnitt wie SubscriptionProvision: eigene Gruppe, kein Passwort,
  # keine Login-Shell. Die Shell ist hier die Messung (Frage 5) und nicht
  # Beiwerk: Ein Abo-Benutzer dieses Panels hat /usr/sbin/nologin.
  for u in "$ABO" "$ABO2"; do
    groupadd --force "$u" >/dev/null 2>&1
    id "$u" >/dev/null 2>&1 || useradd --gid "$u" --no-user-group \
      --home-dir "$BASE/home-$u" --create-home --shell /usr/sbin/nologin \
      --comment 'srvpanel-Messung' "$u"
  done

  mount --bind "$CD" /etc/cron.d
  mount --bind "$SPOOL" /var/spool/cron/crontabs
  : > "$BASE/crontab-leer"
  mount --bind "$BASE/crontab-leer" /etc/crontab

  # **Und die Sperrdatei, ohne die auf einem echten Server nichts läuft.**
  #
  # Vixie-cron nimmt ein `flock` auf `/run/crond.pid` und stirbt sofort, wenn
  # es die Sperre nicht bekommt: „can't lock /var/run/crond.pid, otherpid may
  # be N". In diesem Entwicklungscontainer läuft kein cron, also war die
  # Sperre frei und das Skript lief — auf `cloudsrv24` hält `cron.service` sie,
  # und der Wegwerf-Dienst starb nach einer Sekunde.
  #
  # > **Ein Messmittel, das nur dort läuft, wo der Prüfling fehlt, misst nicht
  # > den Prüfling.**
  #
  # Gemessen am 18. August 2026: Mit dieser Zeile startet der eigene cron auch
  # neben einem laufenden, und der laufende merkt nichts davon — die Bindung
  # gilt nur in diesem Namensraum.
  #
  # `-p` oder ein Schalter für einen anderen Pfad gibt es nicht; die
  # Aufrufhilfe kennt nur `-x`.
  : > "$BASE/crond.pid"
  [ -e /run/crond.pid ] || : > /run/crond.pid
  mount --bind "$BASE/crond.pid" /run/crond.pid
}

# Eine Zeile, wie sie der Plan vorsieht: fünf Felder, Benutzer, Befehl.
zeile() { printf '%s\t%s\t%s\n' "$1" "$2" "$3"; }

# Eine Datei in /etc/cron.d, mit Kopf und abschliessendem Zeilenumbruch.
# Der Zeilenumbruch ist kein Stil, sondern Pflicht — das misst Runde 1.
datei() {
  local name="$1"; shift
  { printf 'MAILTO=""\nPATH=/usr/local/bin:/usr/bin:/bin\n'; cat; } > "$CD/$name"
  chown root:root "$CD/$name"; chmod 0644 "$CD/$name"
}

# ---------------------------------------------------------------------------
# Der Entwurf aus docs/51 §10, als Prüfkörper: Die Cron-Zeile nennt nur eine
# Nummer, der Befehl liegt daneben in einer Datei und geht als *Argument* an
# die Shell. Hier ist er auf das reduziert, was die Messung braucht.
# ---------------------------------------------------------------------------
#
# Der Ablageort ist hier $CMD und nicht /etc/srvpanel/cron: Ein `mkdir` unter
# /etc wirkte über den Namensraum hinaus — er trennt Einhängepunkte und nicht
# Dateien. Gemessen wird die Bauart, und der Pfad ist an ihr das Beliebige.
cron_run_bauen() {
  cat > "$BASE/cron-run" <<RUN
#!/bin/sh
# Der Entwurf: Die Nummer kommt aus der Cron-Zeile, der Befehl aus der Datei.
# Er wird als *ein* Argument übergeben — ein Zeilenumbruch darin ist damit ein
# Zeichen wie jedes andere und kann keine zweite Cron-Zeile werden.
set -u
befehl=\$(cat "$CMD/\$1.cmd")
exec /bin/sh -c "\$befehl"
RUN
  chmod 0755 "$BASE/cron-run"
}

# ===========================================================================
titel "Aufbau"
# ===========================================================================
aufbau
cron_run_bauen
notiz "cron" "$(dpkg-query -W -f='${Version}' cron 2>/dev/null)"
notiz "Systemzeit" "$(date '+%Y-%m-%d %H:%M:%S %Z')"
notiz "Zeitzone der Maschine" "$(readlink -f /etc/localtime)"

# ===========================================================================
titel "Runde 1 — Aufnahme: was cron liest und was es liegen lässt"
# ===========================================================================
#
# Alle Prüfkörper liegen aus, *bevor* cron startet. Sie feuern jede Minute; nach
# zwei Minuten steht fest, was gelaufen ist und was nicht.

# (1) Die Gegenprobe zu allem, was folgt. Ohne sie wäre jedes „nein" darunter
#     der Beleg für ein kaputtes Messgestell und nicht für cron.
datei srvpanel-$ABO <<EOF
$(zeile '* * * * *' "$ABO" "touch $M/grund")
EOF

# (2) Frage 2 — der Dateiname. Ein Punkt darin ist der Klassiker (`.dpkg-new`,
#     `.bak`); gemessen wird er, weil der Plan Dateien `srvpanel-<benutzer>`
#     nennt und ein Benutzername mit Punkt die Datei lautlos abschalten würde.
cp "$CD/srvpanel-$ABO" "$CD/srvpanel.punkt"
sed -i "s|$M/grund|$M/name_punkt|" "$CD/srvpanel.punkt"
cp "$CD/srvpanel-$ABO" "$CD/srvpanel_unterstrich"
sed -i "s|$M/grund|$M/name_unterstrich|" "$CD/srvpanel_unterstrich"
cp "$CD/srvpanel-$ABO" "$CD/srvpanel+plus"
sed -i "s|$M/grund|$M/name_plus|" "$CD/srvpanel+plus"

# (3) Frage 2 — die Rechte. Eine Datei in /etc/cron.d ist eine Erlaubnis, unter
#     fremdem Namen zu laufen; wer sie schreiben darf, darf alles.
cp "$CD/srvpanel-$ABO" "$CD/srvpanel-gruppenschreibbar"
sed -i "s|$M/grund|$M/recht_gruppe|" "$CD/srvpanel-gruppenschreibbar"
chmod 0664 "$CD/srvpanel-gruppenschreibbar"
cp "$CD/srvpanel-$ABO" "$CD/srvpanel-weltschreibbar"
sed -i "s|$M/grund|$M/recht_welt|" "$CD/srvpanel-weltschreibbar"
chmod 0666 "$CD/srvpanel-weltschreibbar"
cp "$CD/srvpanel-$ABO" "$CD/srvpanel-fremderbesitzer"
sed -i "s|$M/grund|$M/recht_fremd|" "$CD/srvpanel-fremderbesitzer"
chown "$ABO:$ABO" "$CD/srvpanel-fremderbesitzer"

# (4) Frage 6 — die kaputte Zeile. Nimmt cron nur sie mit, die ganze Datei oder
#     den Dienst? Beim sshd war die Antwort tödlich (`docs/59`), und der ganze
#     Rückweg des Panels hängt daran.
datei srvpanel-kaputtezeile <<EOF
$(zeile '* * * * ZZZ' "$ABO" "touch $M/kaputt_erste")
$(zeile '* * * * *' "$ABO" "touch $M/kaputt_zweite")
EOF

# (5) Frage 6 — der fehlende Zeilenumbruch am Ende. Steht als Zeichenkette im
#     Binary; eine Zeichenkette ist keine Messung.
printf 'MAILTO=""\n%s' "$(zeile '* * * * *' "$ABO" "touch $M/kein_zeilenende")" \
  > "$CD/srvpanel-ohnezeilenende"
chmod 0644 "$CD/srvpanel-ohnezeilenende"

# (6) Frage 6 — ein unbekannter Benutzer. Er kommt vor: ein Abonnement wird
#     zurückgebaut, die Cron-Datei bleibt liegen.
datei srvpanel-unbekannterbenutzer <<EOF
$(zeile '* * * * *' "p9999nichtda" "touch $M/benutzer_unbekannt")
$(zeile '* * * * *' "$ABO" "touch $M/benutzer_zweite")
EOF

# (7) Die Zeilenzahl. Das Binary nennt 10000 als Grenze — das ist die Zahl, an
#     der ein Kontingent je Abonnement seine Obergrenze findet. Die Füllzeilen
#     sind Kommentare: Ein Prüfkörper, der bei Erfolg 10000 Jobs je Minute
#     startet, misst den Container und nicht cron.
{ printf 'MAILTO=""\n'
  for i in $(seq 1 10000); do printf '# fuellzeile %s\n' "$i"; done
  zeile '* * * * *' "$ABO" "touch $M/zeilenzahl"
} > "$CD/srvpanel-zeilenzahl"
chmod 0644 "$CD/srvpanel-zeilenzahl"

# (8) Frage 3 — das Prozentzeichen. crontab(5) sagt: Das erste unmaskierte `%`
#     beendet den Befehl, der Rest geht an die Standardeingabe. Der Prüfkörper
#     macht *beide* Hälften sichtbar in einer Datei: Was ankommt, schreibt der
#     Befehl selbst auf.
datei srvpanel-prozent <<EOF
$(zeile '* * * * *' "$ABO" "cat > $M/prozent_stdin%hallo-welt")
$(zeile '* * * * *' "$ABO" "/bin/sh -c 'echo A\\%B > $M/prozent_maskiert'")
EOF

# (9) Frage 4 / Abnahmepunkt 9 — die Einschleusung. Genau das erzeugt eine
#     naive Umsetzung, die den Kundenbefehl in die Zeile schreibt und der Kunde
#     schickt einen Zeilenumbruch mit. Erwartet wird hier der **Treffer**: Das
#     ist die stumpfe Seite des Angriffsdurchgangs, und ohne sie wäre die
#     scharfe Seite ein Beleg für nichts.
datei srvpanel-einschleusung <<EOF
$(zeile '* * * * *' "$ABO" "touch $M/einschleusung_harmlos")
$(zeile '* * * * *' "root" "touch $M/einschleusung_root")
EOF

# (10) Der Entwurf dagegen. Dieselbe Nutzlast — Zeilenumbruch, `%`, eine zweite
#      Cron-Zeile mit `root` — steht diesmal in der .cmd-Datei und nicht in der
#      Cron-Zeile. Erwartet: alles wird als Text ausgeführt, nichts als Zeitplan.
cat > "$CMD/1234.cmd" <<EOF
touch $M/wrapper_ok
/bin/sh -c 'echo A%B' > $M/wrapper_prozent
* * * * * root touch $M/wrapper_root
EOF
chmod 0644 "$CMD/1234.cmd"
datei srvpanel-wrapper <<EOF
$(zeile '* * * * *' "$ABO" "$BASE/cron-run 1234")
EOF

# (11) Frage 5 — Shell, Umgebung, Kennungen. Ein Abo-Benutzer hat
#      /usr/sbin/nologin; ob cron darüber stolpert, ist die Frage, an der das
#      ganze Merkmal hängt.
cat > "$BASE/umgebung.sh" <<EOF
#!/bin/sh
id -u > $M/umg_uid
id -un > $M/umg_name
id -G > $M/umg_gruppen
readlink /proc/\$PPID/exe > $M/umg_shell
pwd > $M/umg_pwd
env | sort > $M/umg_env
EOF
chmod 0755 "$BASE/umgebung.sh"
datei srvpanel-umgebung <<EOF
$(zeile '* * * * *' "$ABO" "$BASE/umgebung.sh")
EOF

# (12) Frage 7 — die Post. Ohne MTA kann cron nichts verschicken; die Frage ist,
#      ob der Job trotzdem läuft und was cron dazu sagt. `MAILTO=""` gegen keine
#      Angabe, sonst gleich — sonst misst man zwei Unterschiede auf einmal.
{ printf 'MAILTO=""\n'; zeile '* * * * *' "$ABO" "echo ausgabe-mit-mailto; touch $M/mailto_leer"; } \
  > "$CD/srvpanel-mailtoleer"
{ zeile '* * * * *' "$ABO" "echo ausgabe-ohne-mailto; touch $M/mailto_ohne"; } \
  > "$CD/srvpanel-mailtoohne"
chmod 0644 "$CD/srvpanel-mailtoleer" "$CD/srvpanel-mailtoohne"

daemon_start
notiz "cron gestartet, warte 135 s (zwei Minutenwechsel)" "$(date '+%H:%M:%S')"
sleep 135

titel "Runde 1 — Ergebnis"
messung "Gegenprobe: eine gültige Datei läuft" ja "$(marker grund)"
messung "Dateiname mit Punkt wird gelesen" nein "$(marker name_punkt)"
messung "Dateiname mit Unterstrich wird gelesen" ja "$(marker name_unterstrich)"
messung "Dateiname mit Plus wird gelesen" nein "$(marker name_plus)"
messung "gruppenschreibbar (0664) wird gelesen" nein "$(marker recht_gruppe)"
messung "weltschreibbar (0666) wird gelesen" nein "$(marker recht_welt)"
messung "fremder Besitzer wird gelesen" nein "$(marker recht_fremd)"
messung "kaputte Zeile: sie selbst läuft" nein "$(marker kaputt_erste)"
messung "kaputte Zeile: die gute Zeile daneben läuft" nein "$(marker kaputt_zweite)"
messung "ohne Zeilenumbruch am Ende: läuft" nein "$(marker kein_zeilenende)"
messung "unbekannter Benutzer: seine Zeile läuft" nein "$(marker benutzer_unbekannt)"
messung "unbekannter Benutzer: die gute Zeile daneben läuft" nein "$(marker benutzer_zweite)"
# **Erwartet wird `ja`, und das ist der Fund.** Im Binary steht „crontab must
# not be longer than 10000 lines, this crontab file will be ignored"; die Grenze
# gilt dem, was `crontab(1)` entgegennimmt, und **nicht** dem, was in
# `/etc/cron.d` liegt. Eine Datei mit 10001 Zeilen wird dort gelesen und
# ausgeführt (`docs/60 §5`).
#
# **Hier stand `nein`, und zwar mit Absicht.** `docs/60` hat die Erwartung
# stehen lassen, damit der Fund bei jedem Lauf auffällt — „eine Erwartung, die
# nicht eintrifft, soll auffallen". Der Preis dafür ist am 18. August auf
# `cloudsrv24` sichtbar geworden: Ein Lauf, der **immer** mit zwei Abweichungen
# und Rückgabewert 1 endet, lässt sich von einem kaputten nicht unterscheiden.
# Dort war cron gar nicht gestartet, und die Meldung sah aus wie die gewohnte.
#
# > **Ein Rot, das immer dasteht, ist keins mehr.**
#
# Die Erwartung bildet deshalb ab, was **gemessen** ist: Eine Abweichung heisst
# jetzt „diese Maschine verhält sich anders als die vermessene". Damit der Fund
# trotzdem nicht verschwindet, steht er unten als eigene Zeile in der Ausgabe.
messung "10001 Zeilen: die eine Jobzeile läuft (Grenze gilt hier nicht)" ja "$(marker zeilenzahl)"
messung "Prozent: der Rest kommt als Standardeingabe an" "hallo-welt" "$(inhalt prozent_stdin)"
messung "Prozent maskiert (\\%): bleibt im Befehl" "A%B" "$(inhalt prozent_maskiert)"
messung "Einschleusung: die harmlose Zeile läuft" ja "$(marker einschleusung_harmlos)"
messung "Einschleusung: die zweite Zeile läuft als root" ja "$(marker einschleusung_root)"
notiz  "Einschleusung: Besitzer der erzeugten Datei" "$(stat -c '%U' "$M/einschleusung_root" 2>/dev/null || echo '(nicht da)')"
messung "Entwurf: der Befehl aus der .cmd läuft" ja "$(marker wrapper_ok)"
messung "Entwurf: das Prozentzeichen bleibt stehen" "A%B" "$(inhalt wrapper_prozent)"
messung "Entwurf: die zweite Zeile wird NICHT zum Zeitplan" nein "$(marker wrapper_root)"
messung "nologin-Benutzer: der Job läuft" ja "$(marker umg_name)"
notiz  "  als Benutzer" "$(inhalt umg_name)"
notiz  "  uid / Gruppen" "$(inhalt umg_uid) / $(inhalt umg_gruppen)"
notiz  "  Shell, die cron benutzt" "$(inhalt umg_shell)"
notiz  "  Arbeitsverzeichnis" "$(inhalt umg_pwd)"
notiz  "  PATH" "$(grep '^PATH=' "$M/umg_env" 2>/dev/null | head -1)"
notiz  "  SHELL" "$(grep '^SHELL=' "$M/umg_env" 2>/dev/null | head -1)"
notiz  "  Anzahl Umgebungsvariablen" "$(wc -l < "$M/umg_env" 2>/dev/null || echo 0)"
messung "MAILTO=\"\": der Job läuft" ja "$(marker mailto_leer)"
messung "ohne MAILTO: der Job läuft auch" ja "$(marker mailto_ohne)"
notiz  "was cron zur Post sagt" "$(grep -icE 'mail|sendmail' "$LOG" || echo 0) Zeilen im Log"
messung "cron lebt nach alldem noch" ja "$(daemon_lebt)"

titel "Runde 1 — was cron dazu gesagt hat"
# **`DEATH` und `can't lock` stehen hier, weil sie am 18. August gefehlt haben.**
# Der Grund, warum der Lauf auf `cloudsrv24` nichts mass, stand wörtlich in
# `$LOG` — und dieser Filter zeigte ihn nicht, weil sein Muster ihn nicht kannte.
#
# > **Ein Filter über die Ausgabe des Prüflings zeigt die Fehler, an die sein
# > Erbauer gedacht hat.**
grep -E "ERROR|error|Syntax|Missing|orphan|bad |DEATH|can't lock" "$LOG" \
  | sed 's/^/  /' | sort -u | head -20

# ===========================================================================
titel "Runde 2 — Frage 1: wann gilt eine neue Datei?"
# ===========================================================================
#
# cron ist ohne inotify gebaut (`ldd` zeigt keine, `strings` kennt kein IN_*);
# es liest die Verzeichnisse neu, wenn sich ihr Zeitstempel geändert hat. Wie
# lange das dauert, entscheidet, ob das Panel „gespeichert" sagen darf oder
# „gilt ab der nächsten Minute" sagen muss.

# Die Gegenprobe zuerst und ohne Wartezeit: dieselbe Datei bei angehaltenem
# cron. Läuft sie auch dann, misst die Uhr darunter nichts.
daemon_stop
rm -f "$M/spaet"
datei srvpanel-spaet <<EOF
$(zeile '* * * * *' "$ABO" "touch $M/spaet")
EOF
sleep 70
messung "Gegenprobe: ohne laufenden cron läuft nichts" nein "$(marker spaet)"

rm -f "$CD/srvpanel-spaet" "$M/spaet"
daemon_start
beginn=$(date +%s)
datei srvpanel-spaet <<EOF
$(zeile '* * * * *' "$ABO" "touch $M/spaet")
EOF
gewartet=0
while [ ! -e "$M/spaet" ] && [ "$gewartet" -lt 150 ]; do sleep 1; gewartet=$(( $(date +%s) - beginn )); done
messung "neue Datei kommt an" ja "$(marker spaet)"
notiz "  Sekunden bis zum ersten Lauf" "$gewartet"

# Und dieselbe Frage für eine *geänderte* Datei — der häufigere Fall im Panel.
rm -f "$M/geaendert"
beginn=$(date +%s)
datei srvpanel-spaet <<EOF
$(zeile '* * * * *' "$ABO" "touch $M/geaendert")
EOF
gewartet=0
while [ ! -e "$M/geaendert" ] && [ "$gewartet" -lt 150 ]; do sleep 1; gewartet=$(( $(date +%s) - beginn )); done
messung "geänderte Datei kommt an" ja "$(marker geaendert)"
notiz "  Sekunden bis zum ersten Lauf" "$gewartet"

# Und die entfernte: Ein zurückgebautes Abonnement darf nicht weiterlaufen.
rm -f "$M/spaet" "$M/geaendert"
rm -f "$CD/srvpanel-spaet"
sleep 75
messung "entfernte Datei läuft nicht mehr" nein "$(marker geaendert)"

# ===========================================================================
titel "Runde 3 — Frage 8: in welcher Zeit rechnet cron?"
# ===========================================================================
#
# Das Panel zeigt Zeiten in der eingestellten Anzeigezeitzone (`docs/40`), cron
# rechnet in der Zeit der Maschine. Wer das verwechselt, zeigt eine Zeile und
# findet sie nicht. Gemessen wird mit einer *anderen* /etc/localtime im
# Namensraum — die Uhr der Maschine wird dabei nicht angefasst.
daemon_stop
mount --bind /usr/share/zoneinfo/Europe/Berlin /etc/localtime
notiz "Zeitzone im Namensraum" "$(date '+%Z, Versatz %z')"

ziel=$(date -d '+3 minutes' '+%M %H')
ziel_m=${ziel% *}; ziel_h=${ziel#* }
ziel_utc=$(date -u -d '+3 minutes' '+%M %H')
zutc_m=${ziel_utc% *}; zutc_h=${ziel_utc#* }

rm -f "$M/tz_lokal" "$M/tz_utc" "$M/tz_crontz"
datei srvpanel-zeitzone <<EOF
$(zeile "$ziel_m $ziel_h * * *" "$ABO" "touch $M/tz_lokal")
$(zeile "$zutc_m $zutc_h * * *" "$ABO" "touch $M/tz_utc")
EOF
# CRON_TZ gilt je Datei — die Frage ist, ob das Panel die Zone mitgeben könnte.
{ printf 'MAILTO=""\nCRON_TZ=UTC\n'; zeile "$zutc_m $zutc_h * * *" "$ABO" "touch $M/tz_crontz"; } \
  > "$CD/srvpanel-crontz"
chmod 0644 "$CD/srvpanel-crontz"

daemon_start
notiz "warte auf $ziel_h:$ziel_m Ortszeit (= $zutc_h:$zutc_m UTC)" "$(date '+%H:%M:%S %Z')"
sleep 230
messung "Zeitplan gilt in der Zeit der Maschine" ja "$(marker tz_lokal)"
messung "Gegenprobe: dieselbe Stunde in UTC gelesen läuft nicht" nein "$(marker tz_utc)"
# **Erwartet wird `nein`, aus demselben Grund wie bei der Zeilenzahl oben.**
# `CRON_TZ` gibt es in diesem cron nicht: Weder das Binary noch `crontab(5)`
# kennen die Zeichenkette (gemessen am 18. August), sie stammt aus cronie. Sie
# wird wie `MAILTO` als gewöhnliche Umgebungsvariable gelesen — und landet damit
# in der Umgebung des Jobs, ohne den Zeitplan zu verschieben.
#
# **Und die Datei bleibt gültig**, auch mit dieser Zeile darin: gemessen, 0
# Fehlerzeilen, beide Prüfdateien geladen. Das ist die Hälfte, die für das Panel
# zählt — eine Zeile, die nichts bewirkt, wäre eine Sache; eine, die die Datei
# mitnimmt, eine andere.
messung "CRON_TZ=UTC verschiebt den Zeitplan (gibt es hier nicht)" nein "$(marker tz_crontz)"
umount /etc/localtime

# ===========================================================================
titel "Runde 4 — Frage 8: Zeitumstellung"
# ===========================================================================
#
# Nicht die Uhr wird gestellt, sondern die Zone: `zic` baut eine Zeitzone, deren
# Umstellung in drei Minuten liegt. Damit ist die Umstellung messbar, ohne die
# Systemzeit dieses Containers anzufassen — die teilt er sich mit anderem.
#
# Warum das zählt: Eine Sicherung, die in der Nacht der Rückstellung **zweimal**
# läuft, ist ein Kundenschaden, den niemand meldet, weil er einmal im Jahr
# passiert und wie ein Zufall aussieht.
#
# **Der Sprung ist fünf Minuten gross und nicht eine Stunde**, und das ist keine
# Abkürzung: Vixie-cron unterscheidet Sprünge *unter* drei Stunden von grösseren
# und behandelt nur die ersteren als Zeitumstellung. Fünf Minuten liegen in
# derselben Klasse wie die echte Stunde und nehmen denselben Weg durch den Code
# — kosten aber fünf Minuten Wartezeit statt fünfundsechzig.
if command -v /usr/sbin/zic >/dev/null; then
  # --- Vorstellen: die Ortszeit springt vor, die Spanne danach fällt aus.
  T=$(date -u -d '+3 minutes' '+%Y %b %-d %-H:%M')
  daemon_stop
  cat > "$BASE/vor.zi" <<EOF
Zone Messzone/Vor	0:00	-	MZA	$T
			0:05	-	MZB
EOF
  /usr/sbin/zic -d "$ZONE" "$BASE/vor.zi" 2>/dev/null

  vorher=$(date -u -d '+2 minutes' '+%M %-H')    # gibt es — die Gegenprobe
  sprung=$(date -u -d '+5 minutes' '+%M %-H')    # liegt in der ausgefallenen Spanne

  rm -f "$M/dst_vorher" "$M/dst_uebersprungen"
  datei srvpanel-umstellung <<EOF
$(zeile "${vorher% *} ${vorher#* } * * *" "$ABO" "touch $M/dst_vorher")
$(zeile "${sprung% *} ${sprung#* } * * *" "$ABO" "touch $M/dst_uebersprungen")
EOF
  mount --bind "$ZONE/Messzone/Vor" /etc/localtime
  daemon_start
  notiz "Vorstellen um $T UTC, Ortszeit springt +5 min" "$(date '+%H:%M:%S %Z')"
  sleep 300
  messung "Vorstellen: der Job davor läuft (Gegenprobe)" ja "$(marker dst_vorher)"
  notiz  "Vorstellen: Job in der ausgefallenen Spanne gelaufen?" "$(marker dst_uebersprungen)"
  notiz  "  Ortszeit jetzt" "$(date '+%H:%M:%S %Z')"
  daemon_stop
  umount /etc/localtime

  # --- Zurückstellen: die Ortszeit springt zurück, die Spanne davor kommt
  #     zweimal vorbei. Läuft ein Job darin zweimal? Das ist die Frage, an der
  #     eine Sicherung hängt: zweimal laufen ist ein Kundenschaden, den niemand
  #     meldet, weil er einmal im Jahr passiert und wie ein Zufall aussieht.
  #     Die Ortszeit liegt vor dem Sprung fünf Minuten vor UTC.
  T_lokal=$(date -u -d '+8 minutes' '+%Y %b %-d %-H:%M')
  cat > "$BASE/zurueck.zi" <<EOF
Zone Messzone/Zurueck	0:05	-	MZA	$T_lokal
			0:00	-	MZB
EOF
  /usr/sbin/zic -d "$ZONE" "$BASE/zurueck.zi" 2>/dev/null

  # Diese Wanduhrzeit kommt zweimal: einmal bei UTC+2 min (Ortszeit = UTC+5 min)
  # und einmal bei UTC+7 min (Ortszeit = UTC).
  doppelt=$(date -u -d '+7 minutes' '+%M %-H')
  rm -f "$M/dst_doppelt"
  datei srvpanel-umstellung <<EOF
$(zeile "${doppelt% *} ${doppelt#* } * * *" "$ABO" "echo x >> $M/dst_doppelt")
EOF
  mount --bind "$ZONE/Messzone/Zurueck" /etc/localtime
  daemon_start
  notiz "Zurückstellen um $T_lokal Ortszeit, springt -5 min" "$(date '+%H:%M:%S %Z')"
  sleep 560
  notiz "Zurückstellen: Läufe des Jobs in der doppelten Spanne" \
        "$(wc -l < "$M/dst_doppelt" 2>/dev/null || echo 0)"
  notiz "  Ortszeit jetzt" "$(date '+%H:%M:%S %Z')"
  daemon_stop
  umount /etc/localtime
else
  notiz "zic fehlt — Zeitumstellung nicht gemessen" "uebersprungen"
fi

# ===========================================================================
titel "Ergebnis"
# ===========================================================================
printf '  %s Messungen wie erwartet, %s abweichend\n' "$ok" "$fehl"
printf '  Was mit "--" beginnt, ist eine Zahl ohne Erwartung und kein Fehlschlag.\n'
printf '\n'
printf '  Die beiden Funde dieser Messrunde stehen in den Erwartungen und fallen\n'
printf '  deshalb nicht mehr als Abweichung auf — sie gelten weiter:\n'
printf '    * Die 10000-Zeilen-Grenze schützt /etc/cron.d nicht.\n'
printf '    * CRON_TZ gibt es in diesem cron nicht; es wird als gewöhnliche\n'
printf '      Umgebungsvariable gelesen und verschiebt den Zeitplan nicht.\n'
printf '  Eine Abweichung oben heisst: diese Maschine verhaelt sich anders als die\n'
printf '  in docs/60 vermessene.\n'
[ "$fehl" = 0 ] || exit 1
