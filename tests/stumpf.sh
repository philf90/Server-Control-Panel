#!/bin/sh
#
# Die stumpfen Fassungen für den Angriffsdurchgang (docs/61 §2).
#
# **Ein Angriff, der nicht trifft, misst den Angreifer und nicht die Abwehr.**
# Der Durchgang läuft deshalb zweimal: einmal gegen das gebaute Panel, einmal
# gegen eines, dem die Schranke genommen wurde. Was hier entsteht, ist die
# zweite Hälfte.
#
# ## Warum drei und nicht eine
#
# Zwischen einem Pfad aus dem Formular und einer Datei stehen **zwei** Wände,
# und sie sind verschiedener Natur: die Normalisierung in `Workspace::path()`
# (eine Prüfung, in PHP geschrieben) und `chroot` plus `setuid` in der Sandbox
# (eine Schranke, vom Kernel gehalten). Eine stumpfe Fassung, die an beiden
# zugleich vorbeigreift, beantwortet „hätte der Angriff getroffen?" — und
# „welche Wand hat ihn gehalten?" gar nicht.
#
# > **Eine Gegenprobe, die zwei Wände zugleich wegnimmt, sagt über keine von
# > beiden etwas.**
#
#   a  Workspace::path() normalisiert nicht mehr — die Sandbox bleibt.
#   b  Workspace::run() ruft die Arbeit unmittelbar auf — die Prüfung bleibt.
#   c  Der Befehl des Kunden steht wieder in der Cron-Zeile statt in der .cmd.
#
# ## Und warum es ein Bau ist und kein Schalter
#
# Eine Umgebungsvariable, die die Schranke abschaltet, wäre ein dauerhaftes Loch
# im ausgelieferten Code — und der Abnahmelauf hätte es selbst hineingebaut. Was
# hier passiert, ist eine Änderung am Arbeitsbaum, die `--zurueck` wieder
# wegnimmt.
#
# **Auf einem losen HEAD arbeiten, nie auf einem Zweig.** Ein Zweig mit dieser
# Änderung ist einer, den ein Release-Lauf finden kann.
#
#     git switch --detach v0.6.0-rc.15
#     sh tests/stumpf.sh a
#     git diff > /tmp/stumpf-a.patch     # wörtlich ins Protokoll
#
# ## Jeder Eingriff weist nach, dass er gewirkt hat
#
# Ein stumpfer Bau, der die Wand gar nicht wegnimmt, liefert im Durchgang „kein
# Treffer" — dieselbe Ausgabe wie eine haltende Abwehr, und der ganze Lauf wäre
# wertlos. Deshalb prüft jeder Eingriff hier hinterher am laufenden Code nach,
# und `--pruefen` zeigt vorher, dass die Wand überhaupt steht.
#
# > **Eine Gegenprobe, die nicht treffen kann, ist keine.**
#
set -eu

cd "$(dirname "$0")/.."

WS=agent/src/Files/Workspace.php

# **stumpf-c gab es hier, und es ist am 18. August ersatzlos weggefallen.**
#
# Es nahm die Cron-Wand weg — zwei Stellen in `CronFile::render()` und
# `CronApply`. Nur geht dort kein Prüfkörper vorbei: `tests/cron-messen.sh`
# ruft kein PHP auf, sondern legt Cron-Dateien von Hand. Der Eingriff wäre
# angewendet worden, hätte nachgewiesen dass er wirkt, und nichts gemessen —
# dieselbe Falle, die am selben Tag die beiden anderen Läufe wertlos gemacht
# hat.
#
# > **Ein Eingriff ohne Prüfkörper, der ihn benutzt, ist eine Wand, die man
# > wegnimmt, ohne dass jemand hindurchgeht.**
#
# Die Cron-Wand ist ohne ihn beidseitig gemessen: `cron-messen.sh` legt eine
# rohe Zeile mit eingeschleustem `root` (läuft) neben eine im Entwurf des
# Panels (läuft nicht), und die scharfe Hälfte von Punkt 9 und 10 geht im
# Durchgang ohnehin durch das echte Panel.

# **Dieses Skript ist nicht für den Einzeldownload gebaut, und das sagt es.**
#
# Es arbeitet mit `git status` und `git checkout` in dem Baum, der über ihm
# liegt — aus `/root/stumpf.sh` heisst das `cd /`. Am 18. August ist genau das
# passiert: Ein `curl` holte die HTML-Seite statt des Rohtexts, und der Aufruf
# lief in `/`. Beides muss hier auffallen und nicht in einer Meldung von git,
# die von etwas anderem spricht.
#
# > **Ein Skript, das seinen Arbeitsbaum nicht prüft, arbeitet im falschen.**
#
# `cron-messen.sh` darf einzeln heruntergeladen werden — es fasst nur seine
# eigenen Wegwerf-Verzeichnisse an. Dieses hier fasst den Quelltext an.
for datei in "$WS"; do
  [ -f "$datei" ] || {
    echo "Hier ist kein Checkout dieses Projekts: $datei fehlt (Arbeitsverzeichnis: $(pwd))." >&2
    echo "Dieses Skript läuft nur aus einem git clone heraus, nicht als einzelne Datei." >&2
    exit 2
  }
done

git rev-parse --show-toplevel >/dev/null 2>&1 || {
  echo "Hier ist kein git-Baum (Arbeitsverzeichnis: $(pwd)) — der Rückweg dieses Skripts braucht einen." >&2
  exit 2
}

# **Der Rückweg wirft weg, was nicht eingecheckt ist.** `--zurueck` und der
# Trockenlauf setzen diese drei Dateien über `git checkout --` zurück; liegt
# darin noch nicht festgeschriebene Arbeit, ist sie danach fort. Dieselbe Falle
# steht in `CLAUDE.md` für `resources/`, und `tests/waechter-brechen.sh`
# verweigert aus demselben Grund den Dienst bei schmutzigem Baum.
#
# > **Ein Rückweg, der über `git checkout` führt, ist für alles ein Rückweg,
# > was dort steht — nicht nur für den eigenen Eingriff.**
sauber_oder_ende() {
  schmutz=$(git status --porcelain -- "$WS")

  if [ -n "$schmutz" ]; then
    echo "In diesen Dateien liegt nicht eingecheckte Arbeit:" >&2
    echo "$schmutz" >&2
    echo "Der Rückweg dieses Skripts würde sie wegwerfen. Erst festschreiben." >&2
    exit 2
  fi
}

rot()  { printf '  \033[31mNEIN\033[0m %s\n' "$1"; fehler=$((fehler + 1)); }
gut()  { printf '  \033[32mja\033[0m   %s\n' "$1"; }
fehler=0

# Eine Zeichenkette genau einmal ersetzen — sonst abbrechen. Ein Eingriff, der
# nichts ändert, prüft nichts und sieht dabei aus, als wäre er angekommen.
ersetze() {
  python3 - "$@" <<'PY'
import sys
datei, alt, neu = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(datei, encoding='utf-8').read()
n = s.count(alt)
if n != 1:
    sys.exit(f'  Die Zielstelle steht {n}-mal in {datei}, erwartet genau einmal:\n    {alt[:70]}')
open(datei, 'w', encoding='utf-8').write(s.replace(alt, neu, 1))
PY
}

# Ein Stück framework-freier PHP-Code gegen den Arbeitsbaum laufen lassen.
php_lauf() { php -r "require 'agent/src/autoload.php'; $1"; }

# ---------------------------------------------------------------------------
# Die Nachweise. Sie laufen gegen den Code, wie er gerade dasteht — scharf
# **und** stumpf —, und sagen, welche der beiden Lagen sie sehen.
# ---------------------------------------------------------------------------
pruefe_a() {
  ergebnis=$(php_lauf '
    echo SrvPanel\Agent\Files\Workspace::path("/../../../../etc/passwd");
  ')
  case "$ergebnis" in
    /etc/passwd)                 echo "scharf" ;;
    /../../../../etc/passwd)     echo "stumpf" ;;
    *)                           echo "unklar: $ergebnis" ;;
  esac
}

pruefe_b() {
  # Läuft die Arbeit in **diesem** Prozess, ist die Sandbox weg. Die scharfe
  # Lage lässt sich hier nicht messen — sie braucht root, chroot und einen
  # echten Systembenutzer —, deshalb wird sie am Quelltext abgelesen.
  if grep -q 'Sandbox::run(' "$WS"; then
    echo "scharf"
    return
  fi

  ergebnis=$(php_lauf '
    $j = new SrvPanel\Agent\Journal("/dev/null");
    $c = new SrvPanel\Agent\Context(new SrvPanel\Agent\Runner($j), $j, static fn (array $z): null => null);
    $w = SrvPanel\Agent\Files\Workspace::fromArgs(["subscription" => "pruef.invalid", "user" => "p1000"]);
    echo $w->run($c, static fn (): int => getmypid()) === getmypid() ? "stumpf" : "unklar";
  ')
  echo "$ergebnis"
}

lage() {
  printf '  %-9s %s\n' "stumpf-a" "$(pruefe_a)"
  printf '  %-9s %s\n' "stumpf-b" "$(pruefe_b)"
}

erwarte() {
  was=$1; soll=$2; ist=$(eval "pruefe_$was")
  [ "$ist" = "$soll" ] && gut "$was ist $soll" || rot "$was ist „$ist\", erwartet „$soll\""
}

# ---------------------------------------------------------------------------
case "${1:-}" in

a)
  # Die Normalisierung entfällt. Der Pfad geht so an die Sandbox, wie er
  # hereinkam.
  #
  # **Was dann passiert, ist die Frage des Laufs und nicht die Antwort.**
  # Erwartet wird, dass die Sandbox ihn trotzdem hält — im Chroot bezeichnet
  # auch `../../etc/passwd` nichts ausserhalb. Ob das stimmt, sagt Punkt 1 und 2
  # des Durchgangs auf einem echten Server, und nicht dieser Kommentar.
  #
  # > **Ein Kommentar, der eine Eigenschaft behauptet, prüft sie nicht — er
  # > macht nur unwahrscheinlicher, dass jemand nachsieht.**
  ersetze "$WS" '        $parts = [];' '        return $raw; // stumpf-a

        $parts = [];'
  erwarte a stumpf
  ;;

b)
  # Die Arbeit läuft ohne chroot und ohne setuid — als root, im echten
  # Dateisystem. Wand A bleibt stehen; was jetzt durchkommt, hat sie nicht
  # gehalten.
  ersetze "$WS" '        $value = Sandbox::run($this->root, $this->user, $work, $close, $withGroup, $ranAs);' \
                '        $value = $work(); $ranAs = null; // stumpf-b'
  erwarte b stumpf
  ;;

--trocken)
  sauber_oder_ende
  # **Prüft die Zielstellen, ohne einzugreifen.** `--pruefen` sieht sie nicht:
  # Es misst, ob die Wände stehen, und nicht, ob dieses Skript sie noch findet.
  # Zieht der Code um, meldete es weiter zweimal „scharf" — und der Eingriff
  # fiele erst im Lauf auf, wo er teuer ist.
  #
  # > **Ein Eintrag, den der Ausdruck nie erreicht, sieht aus wie eine Abdeckung
  # > und ist eine Lücke.**
  for v in a b; do
    if (ausgabe=$(sh "$0" "$v" 2>&1); git checkout -- "$WS"; [ -n "$ausgabe" ] && echo "$ausgabe" | grep -q 'ist stumpf'); then
      gut "$v: Zielstellen gefunden, Eingriff wirkt"
    else
      rot "$v: Zielstelle fehlt oder der Eingriff wirkt nicht"
    fi
  done
  git checkout -- "$WS"
  ;;

--pruefen|'')
  echo "Die Lage im Arbeitsbaum:"
  lage
  echo
  echo "Erwartet vor einem Eingriff: zweimal „scharf\"."
  erwarte a scharf
  erwarte b scharf
  ;;

--zurueck)
  # Hier **nicht** die Sauberkeitsprüfung: Der Zweck dieses Zweigs ist gerade,
  # einen Eingriff zurückzunehmen, und der macht den Baum schmutzig.
  git checkout -- "$WS"
  echo "Zurückgesetzt. Die Lage:"
  lage
  ;;

*)
  echo "sh tests/stumpf.sh a|b|c | --pruefen | --zurueck" >&2
  exit 2
  ;;
esac

exit $((fehler > 0))
