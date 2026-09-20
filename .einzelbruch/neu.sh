#!/usr/bin/env bash
#
# Jeden Wächter der Gestaltung absichtlich brechen und nachsehen, ob er zubeisst.
#
#     tests/waechter-brechen.sh
#
# **Warum das ein Skript ist und keine Notiz.** CLAUDE.md sagt: „Wer eine Regel
# aufstellt, baut den Test dazu — und bricht die Regel danach absichtlich, um zu
# sehen, dass der Test zubeisst. Ein Wächter, der nie rot war, ist kein
# Wächter." Solange das eine Handbewegung beim Schreiben ist, geschieht es beim
# nächsten Wächter nicht mehr.
#
# **Es hat sich beim ersten Lauf sofort gelohnt.**
# `TableStyleTest::test_the_density_token_exists_in_both_steps` blieb grün,
# obwohl `--row-height` aus der Kundendichte entfernt war: In app.css steht
# `[data-density='customer']` ein zweites Mal im `@media`-Block der schmalen
# Fläche, und der Ausdruck fand diese Fundstelle. Der Wächter sah richtig aus
# und war es nicht — gemerkt hat es nur der Bruch.
#
# **Wer einen Eingriff schreibt, zielt nicht auf einen blossen Namen.** Dieses
# Repo hält in jeder Behebung ihren Vorzustand im Kommentar fest, und jeder
# Dokumentblock nennt seine Nachbarn als `{@see …}`. Ein Eingriff auf
# `Announcement::onLoginPage()` findet die Zeichenkette deshalb zweimal, bricht
# an seinem eigenen `assert` ab und meldet „Eingriff hat nichts geändert" — am
# 5. September 2026 dreimal an einem Tag passiert. Gezielt wird auf den ganzen
# Ausdruck (`: Announcement::onLoginPage();`), nicht auf den Namen darin.
#
# > **Derselbe Kommentar, der einen Wächter fälschlich grün hält, macht einen
# > Bruch blind.**
#
# **Und ein Eingriff zeigt auf einen Wächter, der die Frage beantworten kann.**
# Am selben Tag nahm einer der Hülle ihr `display: flex` und erwartete einen
# Fund von `BlockSpacingTest` — der liest benachbarte Tags im Vorlagentext, und
# ein `v-for` erzeugt kein Paar. Er lief durch und liess seinen Wächter grün.
#
# Das Skript ändert Dateien unter resources/, app/, agent/ und packaging/ und
# stellt sie wieder her. Es verweigert den Start, wenn dort schon etwas geändert
# ist, und räumt auch nach einem Abbruch auf.
#
# `packaging/` steht seit P4 in dieser Liste: Dort liegt das Installationsskript,
# und die Regel „nach einem Update entspricht die nginx-Konfiguration wieder der
# Vorlage" wohnt in ihm. Ein Bruch in einem Verzeichnis, das `wiederherstellen`
# nicht kennt, ist keine Probe, sondern eine Änderung.
#
# `.github/` kam mit dem Wächter dazu, der prüft, dass ein Freigabelauf ein
# zweites Mal laufen darf. Auch dort steht eine Regel als Text in einer Datei,
# und auch dort gilt: Wer sie zum Prüfen bricht, muss sie zurückbekommen.
#
# `routes/` kam mit dem Wächter dazu, der prüft, dass „Einstellungen →
# Datenbankserver" nur liest (`RemoteAccessTest::test_the_settings_page_only_reads`).
# Sein Bruch legt eine schreibende Route an, und die steht in `routes/web.php` —
# in keiner der beiden Listen wäre sie danach stehengeblieben.
#
# `bootstrap/` kam am 11. August 2026 dazu, und der Anlass ist derselbe wie bei
# `routes/`: Dort steht die Liste der Middleware, und ob eine davon eingetragen
# ist, ist eine Regel wie jede andere. Ihr Bruch nimmt einen Eintrag heraus — in
# keiner der bisherigen Listen wäre er danach zurückgekommen.
#
# `docs/` kam mit P5b dazu, und der Anlass ist derselbe wie bei `routes/`: Ein
# Wächter prüft dort, dass jeder Verweis eines Dokuments auf eine Datei zeigt,
# die es gibt (`DocLinkTest`), und sein Bruch macht aus einem Verweis einen
# toten. Ohne die Zeile bliebe er stehen — und der nächste Lauf fände ein
# schmutziges Verzeichnis vor, das er sich selbst gemacht hat.
#
# `config/` kam mit dem Fassungsbefehl dazu, und der Anlass ist der teuerste
# Bruch dieses Skripts: Er dreht `config/app.php` auf `env('SRVPANEL_VERSION',
# '0.1.0-dev')` zurück — auf genau die Zeile, die zwei Jahre lang ausgeliefert
# war. Stünde das Verzeichnis nicht in der Liste, bliebe sie stehen, und der
# Bruch hätte den Fehler nicht geprüft, sondern wieder eingebaut.
#
# `database/` kam mit P5 dazu, und der Anlass ist genau der Satz darüber: Ein
# Wächter prüft dort am **Schema**, dass es keine Spalte für ein Passwort gibt
# (`SecretsStayOutOfTheQueueTest`), und der Bruch dazu fügt eine ein. Ohne diese
# Zeile wäre er keine Probe, sondern eine Änderung — die Migration bliebe mit
# der Spalte stehen, und der nächste Lauf des Skripts fände ein schmutziges
# Verzeichnis vor, das er sich selbst gemacht hat.
#
# **`git checkout` stellt nur wieder her, was git kennt.** Ein Wächter für Code,
# der noch nicht eingecheckt ist, wird hier nicht gebrochen, sondern gelöscht.
# Deshalb der Abbruch oben — und deshalb kommt ein neuer Bruch erst nach dem
# Commit dazu, den er prüft.
#
# **Und `tests/` steht in keiner der beiden Listen, mit Absicht.** Ein Bruch,
# der eine Testdatei ändert, liesse sie hier geändert stehen — er wäre keine
# Probe, sondern eine Änderung. Das Verzeichnis nachzutragen ginge nicht:
# Dieses Skript liegt selbst darin, und ein `git checkout -- tests/` würde
# irgendwann die Datei zurückschreiben, die bash gerade liest.
#
# Betroffen sind die Wächter, deren Regel *im Test* steht statt im Code —
# `BreakScriptTest` und `ChangelogTest::REMOVED`. Ihre Brüche werden von Hand
# gefahren; die Befehlsfolge steht jeweils im Kopf des Tests.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

# **Die feste Umgebung dieses Skripts — aus demselben Grund wie im Agenten.**
#
# `pruefe()` liest die Ausgabe von PHPUnit als Text: `OK (`, `FAILURES!`. In
# einer Agentensitzung schreibt derselbe Aufruf statt dessen eine Zeile JSON
# (`{"tool":"phpunit","result":"passed",…}`), und dann faellt **jede** Pruefung
# in den Zweig „unlesbar".
#
# Gemessen am 26. August 2026 durch Aussieben der ganzen Umgebung, Variable fuer
# Variable: `AI_AGENT` und `CLAUDECODE` schalten die Verpackung ein, `env -i`
# gibt gewoehnlichen Text. Beides einzeln nachgeprueft.
#
# **Der Leser ist deswegen schon zweimal umgebaut worden** — einmal auf JSON,
# weil er in einer Agentensitzung entstand, und danach zurueck auf Text, weil er
# in der CI nichts fand. Keiner der beiden Umbauten hat gefragt, WARUM dieselbe
# Zeile zwei Ausgaben hat.
#
#   Ein Parser, der zwischen zwei Umgebungen hin- und hergebaut wird, ist
#   nicht falsch geschrieben — er misst eine Umgebung, die niemand festgelegt
#   hat.
#
# Deshalb steht die Umgebung jetzt hier, so wie `Runner::ENVIRONMENT` sie fuer
# den Agenten festlegt: Wer eine Ausgabe parst, setzt die Umgebung, die sie
# erzeugt. Die Vorpruefung unten bleibt als Rueckfall.
export -n AI_AGENT CLAUDECODE 2>/dev/null || true
unset AI_AGENT CLAUDECODE

# **Dieses Skript stellt den Arbeitsbaum her und duldet deshalb keinen zweiten
# Schreiber.** `wiederherstellen()` faehrt nach *jedem* Eingriff ein
# `git checkout --` ueber die Baeume unten. Wer daneben arbeitet, verliert seine
# Aenderungen zwischen zwei Eingriffen — und ein `git add -A`, das in ein
# offenes Bruchfenster faellt, nimmt den Eingriff mit ins Repo.
#
# Am 26. August 2026 beides in einem Commit passiert: Ergaenzungen an `docs/81`
# waren fort, und `app/Console/Commands/Databases.php` stand mit `$fehlt = null;`
# statt seiner Pruefung im Repo — committet und gepusht. Gefunden hat es kein
# Waechter, sondern ein Blick auf `git show --stat`.
#
#   Ein Werkzeug, das den Arbeitsbaum herstellt, duldet keinen zweiten
#   Schreiber — es nimmt ihm seine Arbeit weg und schiebt ihm seine eigene
#   unter.
#
# **Die Sperre haelt davon genau eine Haelfte**, und das ist ehrlich gesagt die
# kleinere: Sie weist einen zweiten *Lauf* ab. Einen Menschen, der nebenher eine
# Datei schreibt, kann sie nicht abweisen — das ist eine Regel und kein
# Mechanismus, und sie steht in `CLAUDE.md`.
#
#   Was ein Test nicht halten kann, gehoert als Frage aufgeschrieben und nicht
#   als Zusage.
#
# Die Marke ist zugleich das, woran ein Zweiter den laufenden Lauf *sieht* —
# ohne sie ist „laeuft gerade einer?" nur an der Prozessliste zu beantworten.
LAUFMARKE="${TMPDIR:-/tmp}/srvpanel-waechter-brechen.lock"

exec 9>"$LAUFMARKE" || {
  echo "Die Laufmarke $LAUFMARKE laesst sich nicht anlegen." >&2
  exit 1
}

# `flock` sperrt je *offener Datei*; dieser eine Deskriptor wird genau einmal
# genommen und mit dem Prozess wieder frei — die verschachtelte Sperre aus P5b
# kann hier nicht entstehen.
if ! flock -n 9; then
  echo "Es laeuft bereits ein Lauf dieses Skripts ($LAUFMARKE ist gesperrt)." >&2
  echo "Zwei Laeufe zugleich stellen einander den Arbeitsbaum um; der zweite" >&2
  echo "misst dann einen Zustand, den niemand hergestellt hat." >&2
  exit 1
fi

# **Die Bäume, in denen dieses Skript arbeitet — einmal aufgeschrieben.**
#
# Sie standen zweimal da, für die Sauberkeitsprüfung und für den Rückweg, und
# die beiden Listen waren nicht dieselbe: `tests/` fehlte im Rückweg. Ein
# Eingriff aus P5b hat sich deshalb mit einem eigenen `git checkout --`
# beholfen — und der nächste, der einen Wächter bricht, um dessen Gegenprobe
# zu prüfen, hat den Fehler geerbt: Die Änderung blieb stehen, und **alles
# danach mass einen Baum, den niemand hergestellt hat.** Gefunden am
# 14. August 2026 im ersten Lauf an einem Pull Request.
#
# > **Ein Rückweg, der eine Datei nicht kennt, die ein Eingriff ändert, ist
# > keiner — und was danach kommt, misst etwas anderes als es glaubt.**
# **`package.json` steht hier, weil es seit P6 eine Regel trägt.**
# `FrontendDependencyTest` liest die Abhängigkeitsliste, und der Bruch dazu
# schreibt eine erfundene hinein. Ohne die Datei im Rückweg blieb sie stehen —
# und damit war der Wächter für **jede** folgende Prüfung rot, obwohl mit ihm
# nichts war. Genau vier Prüfungen meldeten deshalb „ohne Biss", und alle vier
# gehörten zu diesem einen Wächter.
#
# > **Ein Bruch, der eine Datei ausserhalb des Rückwegs anfasst, wird nicht
# > zurückgenommen — und vergiftet jeden Lauf danach.**
#
# `lang/` kam am 20. August dazu, und der Anlass ist genau dieser Satz. Zwei
# Eingriffe zu `AttributeNameTest` brechen `lang/de/validation.php` — die Liste
# der deutschen Feldnamen ist eine Regel wie jede andere. Sie standen ausserhalb
# des Rückwegs, blieben nach dem Bruch stehen, und beide Gegenproben meldeten
# „nicht wieder grün". Der Wächter darüber war dabei **grün**: Er liest die
# Zeichenkette aus dem `s.replace(...)`-Aufruf, und diese beiden Blöcke halten
# sie in einer Variablen. Zweiundfünfzig Blöcke tun das.
# `vite.config.js` kam am 25. August dazu, und wieder aus demselben Grund: Ein
# Eingriff zu `ErrorPageTest` nimmt dort den Stylesheet-Eingang heraus. Der
# Wächter darüber hat es beim ersten Lauf gemeldet — er zählt inzwischen die
# angefassten Dateien und nicht nur die genannten.
#
# **`.claude/` stand hier vom 15. September 2026 bis zum selben Abend** und ist
# mit dem Steward-Skill wieder gegangen. Der Eintrag ist kein Vorrat fuer
# spaeter, sondern eine Falle: Ohne eine git-bekannte Datei darunter faellt
# `git checkout -- $BAEUME` mit `pathspec ... did not match` aus und stellt
# **keinen** der uebrigen Baeume wieder her — gemessen am 15. September in einem
# Wegwerf-Repo, und `wiederherstellen()` schluckt den Fehler mit `2>/dev/null`.
# Die Sauberkeitspruefung unten sieht ihn nicht: `git status --porcelain` gibt
# fuer einen toten Pfad rc=0 und keine Ausgabe.
#
#   Ein toter Pfad in dieser Liste schaltet den Rueckweg des ganzen Skripts
#   still ab — wer hier ein Verzeichnis eintraegt, legt zuerst die Datei an.
BAEUME="resources/ app/ agent/ tests/ packaging/ .github/ database/ routes/ docs/ config/ bootstrap/ lang/ package.json vite.config.js"

# **Dieses Skript liegt selbst unter `tests/` und nimmt sich aus.**
#
# Zwei Gründe, und beide sind handfest. Bash liest ein Skript während der
# Ausführung weiter; eine Datei, die sich dabei unter ihm ändert, ist eine
# Fehlerquelle, die niemand debuggen will. Und wer hier einen neuen Eingriff
# schreibt, muss ihn fahren können, bevor er ihn committet — genau dafür
# steht das Skript ausserhalb der Sauberkeitsprüfung. Beim Bauen dieser Zeile
# ist mir die eigene Änderung einmal weggeflogen; die Warnung in CLAUDE.md
# über `git checkout -- resources/` gilt wörtlich auch hier.
SELBST=":(exclude)tests/waechter-brechen.sh"

# **`git status` und nicht `git diff` — der Unterschied hat sechs Prüfungen
# gekostet.** `git diff` sieht nur, was git schon kennt; eine **neue** Datei ist
# ihm gleichgültig. Am 16. August 2026 lief das Skript deshalb über zwei
# nagelneue Klassen an, brach sie sechsmal — und `wiederherstellen` konnte
# keine davon zurückholen, weil `git checkout` nichts über eine unversionierte
# Datei weiss. Die Brüche bissen alle; die Gegenproben dahinter meldeten
# geschlossen „ohne Biss", und der Arbeitsbaum trug am Ende sechs Eingriffe
# übereinander.
#
# Der Kopf dieses Skripts sagt genau das seit P4 („git checkout stellt nur
# wieder her, was git kennt") — und die Prüfung, die es durchsetzen soll, hat
# den Fall nicht abgedeckt.
#
# > **Ein Wächter über den Zustand, der eine ganze Sorte Zustand nicht sieht,
# > gibt Entwarnung über eine Fläche, die er nicht angesehen hat.**
#
# shellcheck disable=SC2086
if [ -n "$(git status --porcelain -- $BAEUME "$SELBST")" ]; then
  echo "Ungesicherte oder neue Dateien in: $BAEUME" >&2
  echo "Erst committen oder verwerfen — dieses Skript ändert dort Dateien und" >&2
  echo "stellt sie über git wieder her. Eine Datei, die git nicht kennt, bekommt" >&2
  echo "es nicht zurück:" >&2
  # shellcheck disable=SC2086
  git status --porcelain -- $BAEUME "$SELBST" >&2
  exit 1
fi

# shellcheck disable=SC2086
wiederherstellen() { git checkout -- $BAEUME "$SELBST" 2>/dev/null; }
trap wiederherstellen EXIT INT TERM

fehler=0

# **Getrennt gezaehlt, weil sie etwas anderes bedeuten.** Ein Waechter ohne Biss
# ist ein Befund ueber eine Regel; ein vertippter Filter und eine unlesbare
# Ausgabe sind Befunde ueber dieses Skript. Am 10. August 2026 hat die
# Vermischung 473 gesunde Waechter als kaputt gemeldet.
stumm=0

# **Die Namen der Fehlschläge, nicht nur ihre Zahl.** Am 19. August meldete
# dieser Lauf „5 Prüfung(en) ohne Biss" und nannte keine davon; die fünf Zeilen
# standen irgendwo zwischen sechshundert anderen, und das Suchen hat mehr
# gekostet als das Beheben.
#
# > **Eine Zahl, die nicht sagt, welche, zwingt zum Suchen.**
gefallen=""


# Vor jedem Eingriff merken, wie die Datei aussah — danach prüfen, dass sie
# anders aussieht.
#
# **Das ist derselbe Fehler wie überall in diesem Projekt, nur im Werkzeug.**
# Die Eingriffe unten nennen wörtliche Werte: `--row-height: 42px`,
# `--button-line`, `--text-metric: 22px`. Beim Umbau auf „Kontor" hiessen alle
# fünf plötzlich anders — und `sed` schweigt, wenn sein Muster nicht passt.
# Das Skript patchte also nichts, liess den Test laufen, sah ihn grün und
# meldete fünf Wächter als „hält seine Regel nicht". Ein Werkzeug, das die
# Wächter prüft, hat selbst keinen gehabt.
vorher() { cp resources/css/app.css /tmp/waechter-vorher.css; }

# Dasselbe für eine beliebige Datei. Die beiden oben sind fest auf app.css
# verdrahtet, weil es zur Zeit des Optik-Reworks nur diese eine gab; ab P4
# werden auch Dateien unter agent/ gebrochen.
vorher_datei() { cp "$1" /tmp/waechter-vorher-datei; }

griff_datei() {
  local datei="$1" name="$2"

  if cmp -s "$datei" /tmp/waechter-vorher-datei; then
    printf '  FEHLT  %-56s Eingriff hat nichts geändert\n' "$name"
    fehler=$((fehler + 1))

    # **Auch hier, und das fehlte bis zum 22. August 2026.** Der Zähler stieg,
    # die Bilanz am Ende blieb still — sie führte nur auf, was `pruefe`
    # gemeldet hatte. Wer sie las, suchte einen Fehlschlag, den es dort nicht
    # gab, und übersah den, der wirklich zählt: Ein Eingriff, der nichts
    # ändert, hat nichts gemessen.
    #
    #   Eine Bilanz, die eine Art von Fehlschlag nicht aufführt, ist eine
    #   Liste und keine Bilanz.
    gefallen="$gefallen  $name — Eingriff hat nichts geändert
"

    # **`stumm` bleibt unberührt**, obwohl ein wirkungsloser Eingriff nichts
    # gemessen hat. Der Zähler trägt die Meldung „dieses Skript hat nichts
    # gemessen", und die gilt für den Fall, dass **kein** Ergebnis lesbar war.
    # Ein einzelner wirkungsloser Eingriff unter 626 wirksamen löste sie sonst
    # aus und stellte 625 gültige Messungen in Frage.
    return 1
  fi

  return 0
}

griff() {
  local name="$1"

  if cmp -s resources/css/app.css /tmp/waechter-vorher.css; then
    printf '  FEHLT  %-56s Eingriff hat nichts geändert\n' "$name"
    fehler=$((fehler + 1))

    # **Auch hier, und das fehlte bis zum 22. August 2026.** Der Zähler stieg,
    # die Bilanz am Ende blieb still — sie führte nur auf, was `pruefe`
    # gemeldet hatte. Wer sie las, suchte einen Fehlschlag, den es dort nicht
    # gab, und übersah den, der wirklich zählt: Ein Eingriff, der nichts
    # ändert, hat nichts gemessen.
    #
    #   Eine Bilanz, die eine Art von Fehlschlag nicht aufführt, ist eine
    #   Liste und keine Bilanz.
    gefallen="$gefallen  $name — Eingriff hat nichts geändert
"

    # **`stumm` bleibt unberührt**, obwohl ein wirkungsloser Eingriff nichts
    # gemessen hat. Der Zähler trägt die Meldung „dieses Skript hat nichts
    # gemessen", und die gilt für den Fall, dass **kein** Ergebnis lesbar war.
    # Ein einzelner wirkungsloser Eingriff unter 626 wirksamen löste sie sonst
    # aus und stellte 625 gültige Messungen in Frage.
    return 1
  fi

  return 0
}

# Bevor irgendetwas gebrochen wird: laeuft der Testaufruf ueberhaupt?
#
# **Das ist der Fund des ersten vollstaendigen Laufs, und er trifft dieses
# Skript selbst.** Am 10. August 2026 meldeten in der CI alle 473 Pruefungen
# „kein Ergebnis", und die Schlusszeile las sich als „473 Waechter halten ihre
# Regel nicht". Keiner davon war kaputt — `pruefe()` konnte die Ausgabe von
# PHPUnit nicht lesen (siehe dort).
#
# > **Ein Werkzeug, das ueber Waechter urteilt, muss zuerst beweisen, dass es
# > messen kann.**
#
# Deshalb laeuft hier ein Test, von dem feststeht, dass er gruen ist. Kommt
# nichts Lesbares zurueck, bricht das Skript ab — mit der Ausgabe von PHPUnit
# und nicht mit einem Urteil ueber zweihundert fremde Regeln.
vorpruefung() {
  local roh

  roh=$(./vendor/bin/phpunit --filter BreakScriptTest --do-not-cache-result 2>&1)

  case "$roh" in
    *'OK ('*|*'OK, but'*) return 0 ;;
  esac

  echo "Der Testaufruf liefert nichts Lesbares — dieses Skript kann nichts messen." >&2
  echo "Kommt eine Zeile JSON zurueck, steht AI_AGENT oder CLAUDECODE in der Umgebung;" >&2
  echo "der Kopf dieses Skripts nimmt beide heraus. Kommt etwas anderes, liegt es an PHPUnit." >&2
  echo "Gepruefte Zeile: ./vendor/bin/phpunit --filter BreakScriptTest" >&2
  echo >&2
  printf '%s\n' "$roh" | tail -20 >&2

  exit 2
}

vorpruefung

# name | filter | erwartetes Ergebnis
pruefe() {
  local name="$1" filter="$2" erwartung="$3" ergebnis roh

  # **Hier stand ein JSON-Leser, und PHPUnit schreibt kein JSON.**
  #
  # Ein `python3 -c` mit `json.load` auf die Standardeingabe — das hat nie zu
  # `vendor/bin/phpunit` gepasst; die Fassung entstand gegen eine Umgebung, die
  # Werkzeugaufrufe in `{"tool":…,"result":…}` verpackt. In der CI fiel jede
  # einzelne Pruefung in den Zweig „kein Ergebnis", und das Skript meldete
  # daraufhin 473 gebrochene Waechter.
  #
  # > **Ein Parser, der nie zum Ziel passt, meldet nicht „ich kann das nicht"
  # > — er meldet, was er stattdessen findet.**
  #
  # Gelesen wird jetzt, was PHPUnit wirklich schreibt. Vier Faelle, und jeder
  # bedeutet etwas anderes: `kein Test` faengt einen vertippten Filter, der
  # sonst als Biss durchginge, und `unlesbar` faellt auf, statt still zu sein.
  roh=$(./vendor/bin/phpunit --filter "$filter" --do-not-cache-result 2>&1)

  case "$roh" in
    *'No tests executed'*|*'No tests found'*) ergebnis='kein Test' ;;
    *'FAILURES!'*|*'ERRORS!'*)               ergebnis='failed' ;;
    *'OK ('*|*'OK, but'*)                    ergebnis='passed' ;;
    *)                                       ergebnis='unlesbar' ;;
  esac

  if [ "$ergebnis" = "$erwartung" ]; then
    printf '  ok     %-56s %s\n' "$name" "$ergebnis"

    return 0
  fi

  printf '  FEHLT  %-56s %s (erwartet: %s)\n' "$name" "$ergebnis" "$erwartung"
  fehler=$((fehler + 1))
  gefallen="$gefallen  $name — $ergebnis (erwartet: $erwartung)
"

  case "$ergebnis" in
    'kein Test'|'unlesbar') stumm=$((stumm + 1)) ;;
  esac
}

echo
echo "── TrafficEraTest: eine Schwelle statt "eine einzige Zeile" ──"
#
# docs/129 §5 sagt: vollstaendig im neuen Format, nicht ueberwiegend. Eine
# Schwelle ist eine Zahl, die spaeter jemand anders setzt — und dann steht
# in der Tabelle ein Tag, den niemand nachrechnen kann.
vorher_datei app/Support/Web/AccessCounts.php
python3 - <<'PY2'
p = 'app/Support/Web/AccessCounts.php'
s = open(p, encoding='utf-8').read()
alt = 'if ($alt > 0) {'
assert s.count(alt) == 1, 'Zielstelle nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, 'if ($alt > 100) {', 1))
PY2
griff_datei app/Support/Web/AccessCounts.php "Schwelle statt einer Zeile" &&
pruefe "Schwelle statt einer Zeile" \
  TrafficEraTest::test_one_legacy_line_skips_the_whole_day failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TrafficEraTest passed

echo
echo "── TrafficEraTest: der laufende Tag wird als falsch formatiert gemeldet ──"
#
# Die Reihenfolge der beiden Gruende traegt mit: Ein laufender Tag mit alten
# Zeilen ist "noch offen". Andersherum meldete der Lauf jede Nacht eine
# Domain als falsch formatiert, deren heutiger Tag schlicht noch laeuft.
vorher_datei app/Support/Web/AccessCounts.php
python3 - <<'PY2'
p = 'app/Support/Web/AccessCounts.php'
s = open(p, encoding='utf-8').read()
alt = "                if ($tag >= $today) {\n                    $offen[] = ['subscription' => $abonnement, 'domain' => $domain, 'day' => $tag];\n\n                    continue;\n                }\n\n                $alt = (int) ($werte['legacy'] ?? 0);\n\n                if ($alt > 0) {"
assert s.count(alt) == 1, 'Zielstelle nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, "                $alt = (int) ($werte['legacy'] ?? 0);\n\n                if ($tag >= $today && $alt === 0) {\n                    $offen[] = ['subscription' => $abonnement, 'domain' => $domain, 'day' => $tag];\n\n                    continue;\n                }\n\n                if ($alt > 0) {", 1))
PY2
griff_datei app/Support/Web/AccessCounts.php "laufender Tag als Formatfehler" &&
pruefe "laufender Tag als Formatfehler" \
  TrafficEraTest::test_a_running_day_with_legacy_lines_is_still_only_open failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TrafficEraTest passed

echo
echo "── TrafficEraTest: der laufende Tag wird mitgezaehlt ──"
#
# Ein angefangener Tag ist nicht falsch, er ist noch nicht fertig. Gezaehlt
# waere er eine halbe Zahl, die wie eine ganze aussieht.
vorher_datei app/Support/Web/AccessCounts.php
python3 - <<'PY2'
p = 'app/Support/Web/AccessCounts.php'
s = open(p, encoding='utf-8').read()
alt = 'if ($tag >= $today) {'
assert s.count(alt) == 1, 'Zielstelle nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, 'if ($tag > $today) {', 1))
PY2
griff_datei app/Support/Web/AccessCounts.php "laufender Tag mitgezaehlt" &&
pruefe "laufender Tag mitgezaehlt" \
  TrafficEraTest::test_the_running_day_is_open_and_not_counted failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TrafficEraTest passed

echo
echo "── TrafficEraTest: das Liegengebliebene wird nicht gezaehlt ──"
#
# Ohne diese Zahl bleibt die Unit gruen, obwohl der Lauf seinen Tag nicht
# fertig gezaehlt hat — und der naechste wird es auch nicht.
vorher_datei app/Support/Web/AccessCounts.php
python3 - <<'PY2'
p = 'app/Support/Web/AccessCounts.php'
s = open(p, encoding='utf-8').read()
alt = '$unvollstaendig = is_array($pending) ? count($pending) : 0;'
assert s.count(alt) == 1, 'Zielstelle nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, '$unvollstaendig = 0;', 1))
PY2
griff_datei app/Support/Web/AccessCounts.php "Liegengebliebenes nicht gezaehlt" &&
pruefe "Liegengebliebenes nicht gezaehlt" \
  TrafficEraTest::test_pending_domains_make_the_run_incomplete failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TrafficEraTest passed

echo
echo "── AccessCountTest: legacy faellt bei der Zusammenfuehrung heraus ──"
#
# Genau der Fehler, der am 21. September einmal durchging: AccessLog liefert
# fuenf Schluessel, die Zusammenfuehrung legte vier an. Der Prueckstand blieb
# gruen, weil er dieselbe verkuerzte Form erwartete.
vorher_datei agent/src/Ops/WebAccessCount.php
python3 - <<'PY2'
p = 'agent/src/Ops/WebAccessCount.php'
s = open(p, encoding='utf-8').read()
alt = "foreach (['requests', 'sent', 'received', 'errors', 'legacy'] as $feld) {"
assert s.count(alt) == 1, 'Zielstelle nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, "foreach (['requests', 'sent', 'received', 'errors'] as $feld) {", 1))
PY2
griff_datei agent/src/Ops/WebAccessCount.php "legacy faellt heraus" &&
pruefe "legacy faellt heraus" \
  AccessCountTest::test_a_legacy_line_is_tallied_and_not_added failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AccessCountTest passed

echo
echo "── LogEraTest: ein reiner Alt-Tag verschwindet wieder ──"
#
# Vor dem 21. September gab eine Datei aus dem alten Zeitalter days => [].
# "Nicht zaehlbar" und "nicht vorhanden" sind zwei Antworten, und ein leeres
# Feld gibt beide.
vorher_datei agent/src/Web/AccessLog.php
python3 - <<'PY2'
p = 'agent/src/Web/AccessLog.php'
s = open(p, encoding='utf-8').read()
alt = "                $tag = $satz['day'];\n                $tage[$tag] ??= ['requests' => 0, 'sent' => 0, 'received' => 0, 'errors' => 0, 'legacy' => 0];\n\n                if ($satz['sent'] === null) {"
assert s.count(alt) == 1, 'Zielstelle nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, "                $tag = $satz['day'];\n\n                if ($satz['sent'] === null) {", 1))
PY2
griff_datei agent/src/Web/AccessLog.php "reiner Alt-Tag verschwindet" &&
pruefe "reiner Alt-Tag verschwindet" \
  LogEraTest::test_a_legacy_file_is_read_and_not_counted failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" LogEraTest passed

echo
echo "── TrafficEraTest: der Nachtlauf bleibt bei unvollstaendigem Lauf gruen ──"
#
# Die Regel steht in AccessCounts und wird dort geprueft. Ob das Kommando sie
# auch auswertet, sagt dort niemand — und eine stille Unit ist genau das,
# wogegen es diese Zahl gibt.
vorher_datei app/Console/Commands/CollectTraffic.php
python3 - <<'PY2'
p = 'app/Console/Commands/CollectTraffic.php'
s = open(p, encoding='utf-8').read()
alt = "        if ($split['incomplete'] > 0) {"
assert s.count(alt) == 1, 'Zielstelle nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, '        if (false) {', 1))
PY2
griff_datei app/Console/Commands/CollectTraffic.php "Nachtlauf bleibt gruen" &&
pruefe "Nachtlauf bleibt gruen" \
  TrafficEraTest::test_the_nightly_run_fails_on_an_incomplete_report failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TrafficEraTest passed
echo
if [ "$fehler" -eq 0 ]; then
  echo "Alle Wächter beissen."
elif [ "$stumm" -eq "$fehler" ]; then
  # **Die Unterscheidung, die dem ersten vollstaendigen Lauf gefehlt hat.**
  # Steht hinter jedem Fehlschlag ein vertippter Filter oder eine unlesbare
  # Ausgabe, ist nicht eine einzige Regel gebrochen — dieses Skript hat nur
  # nichts gemessen. Wer das verwechselt, sucht den Fehler an 473 Stellen, an
  # denen keiner ist.
  echo "$fehler Prüfung(en) ohne Messung — dieses Skript hat nichts gemessen," >&2
  echo "und über die Wächter ist damit nichts gesagt." >&2
  printf '%s' "$gefallen" >&2
else
  echo "$fehler Prüfung(en) ohne Biss, davon $stumm ohne Messung." >&2
  printf '%s' "$gefallen" >&2
  echo "Die übrigen sind Wächter, die ihre Regel nicht halten." >&2
fi

exit "$fehler"
