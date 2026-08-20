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
BAEUME="resources/ app/ agent/ tests/ packaging/ .github/ database/ routes/ docs/ config/ bootstrap/ package.json"

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

    return 1
  fi

  return 0
}

griff() {
  local name="$1"

  if cmp -s resources/css/app.css /tmp/waechter-vorher.css; then
    printf '  FEHLT  %-56s Eingriff hat nichts geändert\n' "$name"
    fehler=$((fehler + 1))

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

echo "── ClassReachTest: eine Klasse, die es nicht gibt ──"
sed -i '0,/<template>/s//<template>\n  <span class="diese-klasse-gibt-es-nicht" \/>/' \
  resources/js/Pages/Customers/Index.vue
pruefe "erfundene Klasse im Template" ClassReachTest failed
wiederherstellen

echo
echo "── TableStyleTest: Dichtemarke fehlt in einer Stufe ──"
vorher
python3 - <<'PY'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace(":root[data-density='customer'] {\n  --row-height: 48px;", ":root[data-density='customer'] {")
open(p, 'w', encoding='utf-8').write(s)
PY
griff "--row-height fehlt in der Kundendichte" &&
pruefe "--row-height fehlt in der Kundendichte" \
  TableStyleTest::test_the_density_token_exists_in_both_steps failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  TableStyleTest::test_the_density_token_exists_in_both_steps passed

echo
echo "── TableStyleTest: Zeilenhöhe als Literal statt aus der Marke ──"
#
# **Hier stand einmal ein Bruch, der keiner war.** Er hängte eine *zusätzliche*
# Regel `td { height: 40px }` an app.css und erwartete Rot. Der Test fragt aber,
# ob *irgendeine* Tabellenregel die Marke liest — und die echte tat es weiter.
# Ein Bruch, der neben die Regel greift statt auf sie, kann nie zubeissen; er
# hat den Wächter zwei Ausbaustufen lang bestätigt, ohne ihn je zu prüfen.
# Gebrochen werden muss die eine Stelle, die es wirklich gibt.
vorher
sed -i 's/^  height: var(--row-height);$/  height: 40px;/' resources/css/app.css
griff "Literal statt Marke -> rot" &&
pruefe "Literal statt Marke -> rot" \
  TableStyleTest::test_the_row_height_comes_from_the_density_token failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  TableStyleTest::test_the_row_height_comes_from_the_density_token passed

echo
echo "── ButtonStyleTest: Knopfrand aus der Haarlinie ──"
vorher
sed -i 's/  border: 1px solid var(--control-line);/  border: 1px solid var(--line);/' resources/css/app.css
griff "unsichtbarer Rand am Knopf" &&
pruefe "unsichtbarer Rand am Knopf" ButtonStyleTest::test_every_control_border_stands_out failed
wiederherstellen

echo
echo "── ButtonStyleTest: Beschriftung auf dem Knopf unlesbar ──"
vorher
sed -i 's/^  --text: #3a3f49;/  --text: #e8eaef;/' resources/css/app.css
griff "--text auf der Knopffläche unter 4,5:1" &&
pruefe "--text auf der Knopffläche unter 4,5:1" \
  ButtonStyleTest::test_the_label_on_a_button_stays_readable failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ButtonStyleTest::test_the_label_on_a_button_stays_readable passed

echo
echo "── DesignTokensTest: eine Stufe, die niemand benutzt ──"
vorher
sed -i 's/^  --text-metric: 34px;/  --text-metric: 34px;\n  --text-riesig: 99px;/' resources/css/app.css
griff "Marke ohne Nutzer" &&
pruefe "Marke ohne Nutzer" DesignTokensTest::test_every_step_of_the_scale_is_used failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DesignTokensTest::test_every_step_of_the_scale_is_used passed

echo
echo "── MobileLayoutTest: Feld in app.css mit zoomender Größe ──"
vorher
printf '\ninput { font-size: var(--text-small); }\n' >> resources/css/app.css
griff "Feldregel unter 16px" &&
pruefe "Feldregel unter 16px" MobileLayoutTest::test_input_fields_use_the_zoom_safe_size failed
sed -i 's/^input { font-size: var(--text-small); }$/input { font-size: var(--text-input); }/' resources/css/app.css
pruefe "  … mit --text-input wieder grün" MobileLayoutTest::test_input_fields_use_the_zoom_safe_size passed
wiederherstellen

echo
echo "── DesignTokensTest: eine Marke, die es nicht gibt ──"
#
# Der Fund, der diesen Wächter seine Reichweite gekostet hat: Sieben Seiten
# nannten `--surface-border` und `--padding` weiter, nachdem beide mit den
# Karten weggefallen waren. Der Browser wirft eine Deklaration mit unbekannter
# Marke still weg — kein Rand, kein Abstand, keine Meldung.
printf '\n<style scoped>\n.x { color: var(--diese-marke-gibt-es-nicht); }\n</style>\n' \
  >> resources/js/Pages/Customers/Index.vue
pruefe "erfundene Marke in einer Komponente" \
  DesignTokensTest::test_every_token_a_component_uses_exists failed
wiederherstellen

echo
echo "── ButtonStyleTest: eine Seite gestaltet ihr eigenes Feld ──"
printf '\n<style scoped>\ninput { border: 1px solid var(--line); padding: 4px; }\n</style>\n' \
  >> resources/js/Pages/Customers/Index.vue
pruefe "eigenes Feldaussehen auf einer Seite" \
  ButtonStyleTest::test_no_page_styles_a_field_itself failed
wiederherstellen

echo
echo "── TableStyleTest: eine Seite gestaltet ihre eigene Tabelle ──"
printf '\n<style scoped>\ntd { padding: 3px; border-bottom: 1px solid var(--line); }\n</style>\n' \
  >> resources/js/Pages/Customers/Index.vue
pruefe "eigene Tabellenform auf einer Seite" \
  TableStyleTest::test_no_component_styles_a_table_itself failed
wiederherstellen

echo
echo "── FieldErrorTest: ein Serverfehler steht wieder am Feld ──"
#
# docs/45 §5: Bis August 2026 stand jede Meldung zweimal auf der Seite — oben in
# der Zusammenfassung und woertlich noch einmal unter dem Feld. Zwei gleiche
# Saetze uebereinander liest niemand als „Uebersicht und Ort".
vorher_datei resources/js/Pages/Settings/Tls.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Settings/Tls.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '        </label>\n        <p class="hint">\n          Sie wird nicht',
    '        </label>\n        <p v-if="form.errors.contact" class="error">{{ form.errors.contact }}</p>\n'
    '        <p class="hint">\n          Sie wird nicht',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Settings/Tls.vue "Serverfehler wieder am Feld" &&
pruefe "Serverfehler wieder am Feld" \
  FieldErrorTest::test_no_template_repeats_a_server_error_at_the_field failed
wiederherstellen

echo
echo "── FieldErrorTest: eine Seite markiert und sagt nicht warum ──"
#
# Die Richtung mit Folgen. Ohne sie waere der Umbau ein Tausch von „doppelt"
# gegen „gar nicht": roter Rand, kein Wort dazu.
vorher_datei resources/js/Pages/Settings/Tls.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Settings/Tls.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('<FormErrors', '<KeinBanner')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Settings/Tls.vue "Markierung ohne Zusammenfassung" &&
pruefe "Markierung ohne Zusammenfassung" \
  FieldErrorTest::test_every_page_that_can_mark_a_field_shows_the_summary failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FieldErrorTest passed

echo
echo "── FieldErrorTest: Erfolg wird am Feld gemeldet ──"
#
# docs/19 §6.3: Die Markierung zeigt, wo noch etwas zu tun ist. Erfolg hat keinen
# solchen Ort — und ein Formular voller gruener Raender entwertet das eine rote.
vorher_datei resources/js/Pages/Settings/Tls.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Settings/Tls.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '<form class="form" @submit.prevent="speichern">',
    '<form class="form" @submit.prevent="speichern">\n        <p class="notice ok">Gespeichert.</p>',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Settings/Tls.vue "gruene Meldung im Formular" &&
pruefe "gruene Meldung im Formular" \
  FieldErrorTest::test_success_is_never_reported_at_a_field failed
wiederherstellen

echo
echo "── ClassNameTest: ein deutscher Klassenname ──"
vorher
printf '\n.knopfreihe-neu { color: var(--text); }\n' >> resources/css/app.css
griff "deutsches Wort in einem Klassennamen" &&
pruefe "deutsches Wort in einem Klassennamen" \
  ClassNameTest::test_every_class_name_comes_from_the_vocabulary failed
wiederherstellen

echo
echo "── ClassNameTest: eine Regel, die kein Template erreicht ──"
vorher
printf '\n.item-grid-legacy { color: var(--text); }\n' >> resources/css/app.css
griff "Regel ohne Nutzer" &&
pruefe "Regel ohne Nutzer" \
  ClassNameTest::test_every_rule_in_app_css_is_reached_by_a_template failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ClassNameTest passed

echo
echo "── PaginationTest: eine Liste ohne Weg zur zweiten Seite ──"
#
# Der Zustand von vor diesem Wächter: Vier Controller paginierten, keine
# Seite zeigte einen Pager, und ab Zeile 51 war alles unerreichbar.
sed -i 's|<Pager :page="props.events|<span v-if="false" :page="props.events|' \
  resources/js/Pages/Audit/Index.vue
pruefe "Verzeichnis ohne Pager" \
  PaginationTest::test_every_paginated_page_renders_the_pager failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PaginationTest passed

echo
echo "── Schwelle: die Kurve warnt nicht mehr ──"
#
# Das Merkmal, wegen dem „Kontor" gewählt wurde — und das ein Jahr lang
# gefehlt hat, weil in dieser Umgebung kein Agent läuft und auf jeder Kachel
# „noch keine Messwerte" stand. Der Unit-Test allein genügt nicht: Er bliebe
# grün, wenn hier das letzte Argument wegfällt.
sed -i "s|\\\$store->series('cpu', 2, 0, 60, ' %', 0, 85.0)|\\\$store->series('cpu', 2, 0, 60, ' %', 0)|" \
  app/Http/Controllers/OverviewController.php
pruefe "Schwelle im Controller weggekürzt" \
  PanelWalkthroughTest::test_a_tile_over_its_threshold_says_so failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  PanelWalkthroughTest::test_a_tile_over_its_threshold_says_so passed

echo
echo '── RedirectTargetTest: das Ziel wieder `back()` überlassen ──'
#
# Der Zustand von vor diesem Wächter, und er war auf dem Zielserver zu sehen
# und hier nicht: Der Vhost schickt `Referrer-Policy: no-referrer`, Inertia
# navigiert über XHR — `back()` kennt damit kein Ziel und leitet auf `/`. Wer
# im Konto die Darstellung umstellte, stand danach auf der Übersicht.
sed -i "s|return to_route('profile')->with('success', 'Darstellung gespeichert.')|return back()->with('success', 'Darstellung gespeichert.')|" \
  app/Http/Controllers/ProfileController.php
pruefe "Weiterleitung ohne Ziel" \
  RedirectTargetTest::test_no_controller_leaves_the_target_to_back failed
pruefe "  … und man landet auf der Übersicht" \
  RedirectTargetTest::test_saving_the_theme_stays_on_the_account_page failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RedirectTargetTest passed

echo
echo "── SeriesReadingTest: die Ablesung rundet die Reihe weg ──"
#
# Die CPU-Kachel stand auf einem ruhigen Server dauerhaft auf 0 % — in der
# grossen Zahl und bei jeder Ablesung auf der Linie —, waehrend die Kurve
# daneben aus den Rohwerten ihre Ausschlaege zeichnete. Der Wert war nicht
# falsch, er war weggerundet: alles zwischen 0,1 und 0,9 bei null Stellen.
python3 - <<'PY2'
p = 'app/Support/Metrics/Store.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """        return static function (float $value) use ($unit, $decimals): string {
            $betrag = abs($value);
            $noetig = $betrag >= 10.0 ? 0 : ($betrag >= 1.0 ? 1 : 2);

            return number_format($value, max($decimals, $noetig), ',', '.').$unit;
        };""",
    """        return static fn (float $value): string => number_format($value, $decimals, ',', '.').$unit;""",
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
pruefe "Ablesung ohne Aufloesung" \
  SeriesReadingTest::test_a_moving_curve_below_one_percent_is_not_all_zeroes failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SeriesReadingTest passed

echo
echo "── PairedSeriesTest: jede Kurve gegen ihre eigene Spanne ──"
#
# Der Fehler, der auf einem Bildschirmfoto richtig aussieht: Zwei Kurven, jede
# auf ihr eigenes Kleinstes und Grösstes normiert, füllen beide die Kachel —
# bei tausendfachem Unterschied. Das Bild behauptet dann „etwa gleich viel in
# beide Richtungen".
sed -i 's|\$min = min(min(\$a), min(\$b));|\$min = min(\$a);|' app/Support/Metrics/Store.php
sed -i 's|\$max = max(max(\$a), max(\$b));|\$max = max(\$a);|' app/Support/Metrics/Store.php
pruefe "getrennte Achsen in einem Feld" \
  PairedSeriesTest::test_the_smaller_direction_stays_flat_at_the_bottom failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PairedSeriesTest passed

echo
echo "── Netzkachel: die zweite Richtung ist dieselbe wie die erste ──"
#
# Der naheliegende Kopierfehler beim Nachrüsten: zweimal Spalte 0. Auf dem
# Bildschirm lägen zwei Linien genau übereinander — und weil die zweite
# gestrichelt ist, sähe das nach Absicht aus.
sed -i "s|\\\$store->pair('network', 2, 0, 1, 60|\\\$store->pair('network', 2, 0, 0, 60|" \
  app/Http/Controllers/OverviewController.php
pruefe "zweite Richtung ist die erste" \
  PanelWalkthroughTest::test_the_network_tile_carries_both_directions failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  PanelWalkthroughTest::test_the_network_tile_carries_both_directions passed

echo
echo "── AbilityReachTest: ein Knopf ohne Rückfrage bei der Policy ──"
#
# Der Zustand von vor diesem Wächter: In der Sicht eines Kunden stand
# „Abonnement anlegen" auf der Seite, und der Klick endete mit einem nackten
# 403. Die Autorisierung war richtig, die Auskunft davor falsch.
sed -i 's|<Link v-if="props.can.create" href="/subscriptions/create"|<Link href="/subscriptions/create"|' \
  resources/js/Pages/Subscriptions/Index.vue
pruefe "Aktion ohne Rückfrage bei der Policy" \
  AbilityReachTest::test_every_ability_a_page_asks_for_is_sent failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AbilityReachTest passed

echo
echo "── NavIconTest: ein Menüpunkt ohne Zeichen ──"
#
# Ein Eintrag ohne `icon:` steht in der Spalte als einziger ohne Zeichen da —
# kein Fehler, keine Meldung, nur eine Lücke, die nach einem Fehler aussieht.
sed -i "s|{ name: 'Mailversand', href: '/settings/mail', icon: 'mail' }|{ name: 'Mailversand', href: '/settings/mail' }|" \
  resources/js/Layouts/PanelLayout.vue
pruefe "Menüpunkt ohne Zeichen" \
  NavIconTest::test_every_menu_entry_carries_an_icon failed
wiederherstellen

echo
echo "── NavIconTest: ein Zeichen, das es nicht gibt ──"
sed -i "s|icon: 'domains'|icon: 'domain'|g" resources/js/Layouts/PanelLayout.vue
pruefe "Zeichen ohne Zeichnung" \
  NavIconTest::test_every_requested_icon_is_drawn failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" NavIconTest passed

echo
echo "── SparklineShapeTest: der Punkt wieder als Kreis ──"
#
# Zweimal derselbe Fehler in derselben Kachel: Das Feld wird waagerecht gut
# zweieinhalbmal so stark gezogen wie senkrecht. Ein `<circle r="2">` ist darin
# 4,6px breit und 2,9px hoch — eine liegende Ellipse.
sed -i 's|<path v-if="last" :d="dot(last)" class="end" vector-effect="non-scaling-stroke" />|<circle v-if="last" :cx="last.x" :cy="last.y" r="2" class="end" />|' \
  resources/js/Components/Tile.vue
pruefe "Kreis in Nutzerkoordinaten" \
  SparklineShapeTest::test_nothing_round_is_drawn_in_user_coordinates failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SparklineShapeTest passed

echo
echo "── AcmeProtocolTest: der leere Rumpf wird wieder zur leeren Liste ──"
#
# `json_encode([])` schreibt `[]`. ACME will an der einen Stelle, an der ein
# leerer Rumpf vorkommt — beim Anstossen einer Prüfung — ein `{}`. Die Antwort
# auf `[]` ist „malformed", und zwar erst auf dem echten Server: Ein Drehbuch
# antwortet ja trotzdem.
vorher_datei agent/src/Acme/Jws.php
python3 - <<'PY'
p = 'agent/src/Acme/Jws.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            $payload === [] => self::base64url('{}'),\n", '')
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/Acme/Jws.php "leerer Rumpf als [] statt {}" &&
pruefe "leerer Rumpf als [] statt {}" \
  AcmeProtocolTest::test_an_empty_payload_is_an_object_and_not_a_list failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  AcmeProtocolTest::test_an_empty_payload_is_an_object_and_not_a_list passed

echo
echo "── AcmeProtocolTest: die Felder des JWK werden umgestellt ──"
#
# **Der Testvektor allein fängt das nicht.** Er prüft `thumbprintOf()` mit einem
# JWK aus dem RFC, bringt die Reihenfolge also selbst mit. Umgestellt wird sie
# in `jwk()` — und dort hat der Vektor nichts zu melden. Aufgefallen ist die
# Lücke genau hier, beim Brechen.
vorher_datei agent/src/Acme/Jws.php
python3 - <<'PY'
p = 'agent/src/Acme/Jws.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            'e' => self::base64url($exponent),\n            'kty' => 'RSA',",
    "            'kty' => 'RSA',\n            'e' => self::base64url($exponent),",
)
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/Acme/Jws.php "JWK-Felder nicht mehr lexikographisch" &&
pruefe "JWK-Felder nicht mehr lexikographisch" \
  AcmeProtocolTest::test_the_jwk_carries_its_fields_in_the_order_rfc_7638_demands failed
wiederherstellen

echo
echo "── AcmeProtocolTest: der Token ohne Positivliste ──"
#
# Der Token kommt von aussen und landet in einem file_put_contents, das als root
# läuft. Dass die Gegenstelle vertrauenswürdig ist, ist eine Annahme über heute.
vorher_datei agent/src/Acme/HttpChallenge.php
python3 - <<'PY'
p = 'agent/src/Acme/HttpChallenge.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $token) !== 1) {\n"
    "            throw AgentException::badRequest('Unzulässiger Token für die Prüfdatei.', ['token' => $token]);\n"
    "        }\n\n",
    '',
)
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/Acme/HttpChallenge.php "Token wird ungeprüft zum Dateinamen" &&
pruefe "Token wird ungeprüft zum Dateinamen" \
  AcmeProtocolTest::test_a_token_that_is_a_path_never_becomes_a_filename failed
wiederherstellen

echo
echo "── AcmeProtocolTest: keine Wiederholung bei verbrauchtem Einmalwert ──"
vorher_datei agent/src/Acme/Session.php
python3 - <<'PY'
p = 'agent/src/Acme/Session.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if ($problem !== null && $problem->isBadNonce() && ! $retried) {\n"
    "            return $this->send($url, $payload, $useJwk, true);\n"
    "        }\n\n",
    '',
)
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/Acme/Session.php "badNonce wird nicht wiederholt" &&
pruefe "badNonce wird nicht wiederholt" \
  AcmeProtocolTest::test_a_used_nonce_is_retried_exactly_once failed
wiederherstellen

echo
echo "── AcmeProtocolTest: die Prüfdatei bleibt liegen ──"
#
# Beim zweiten Anlauf mit demselben Namen stünde dort ein Wert von gestern, und
# die Prüfung scheiterte mit „unauthorized" an einer Ursache, die nirgends steht.
vorher_datei agent/src/Acme/Order.php
python3 - <<'PY'
p = 'agent/src/Acme/Order.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$this->challenge->cleanup($done['domain'], $done['token']);", '// nichts')
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/Acme/Order.php "kein Abräumen im finally" &&
pruefe "kein Abräumen im finally" \
  AcmeProtocolTest::test_the_challenge_file_is_cleared_after_a_failed_order failed
wiederherstellen

echo
echo "── AcmeProtocolTest: der Deckel auf der Antwort fällt weg ──"
#
# Die Regel stand zuerst als Bedingung mitten in der Konfigurationsablage von
# curl — dort war sie eine Zusage ohne Wächter, denn befragen liess sie sich nur
# mit einer Gegenstelle, die zuviel schickt. Seit sie in ResponseBuffer steht,
# gibt es etwas zu brechen.
vorher_datei agent/src/Acme/ResponseBuffer.php
python3 - <<'PY'
p = 'agent/src/Acme/ResponseBuffer.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (strlen($this->body) + strlen($chunk) > $this->limit) {\n"
    "            $this->truncated = true;\n\n"
    "            return 0;\n"
    "        }\n\n",
    '',
)
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/Acme/ResponseBuffer.php "Antwort ohne Deckel" &&
pruefe "Antwort ohne Deckel" \
  AcmeProtocolTest::test_the_response_buffer_stops_at_its_limit failed
wiederherstellen

echo
echo "── AcmeProtocolTest: die erste Prüfung statt der passenden ──"
#
# Die Zertifizierungsstelle bietet je Autorisierung mehrere Arten an. Wer die
# erste nimmt, legt eine Datei hin und beantwortet damit eine DNS-Prüfung.
vorher_datei agent/src/Acme/Order.php
python3 - <<'PY'
p = 'agent/src/Acme/Order.php'
s = open(p, encoding='utf-8').read()
s = s.replace("if (self::text($candidate, 'type') === $this->challenge->type()) {", 'if (true) {')
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/Acme/Order.php "Art der Prüfung wird nicht abgeglichen" &&
pruefe "Art der Prüfung wird nicht abgeglichen" \
  AcmeProtocolTest::test_the_order_runs_from_the_names_to_a_certificate failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AcmeProtocolTest passed

echo
echo "── CertificateCoverageTest: der Platzhalter deckt plötzlich zwei Ebenen ──"
#
# `*.example.de` deckt genau eine Beschriftung. Fällt die Bedingung weg, gilt
# das Zertifikat auch für `a.b.example.de` — und der Browser zeigt dort eine
# Namenswarnung, die niemand meldet, weil die Seite ja lädt.
vorher_datei app/Models/Certificate.php
python3 - <<'PY'
p = 'app/Models/Certificate.php'
s = open(p, encoding='utf-8').read()
s = s.replace("if ($label !== '' && ! str_contains($label, '.')) {", "if ($label !== '') {")
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei app/Models/Certificate.php "Platzhalter über zwei Ebenen" &&
pruefe "Platzhalter über zwei Ebenen" \
  CertificateCoverageTest::test_a_certificate_covers_exactly_what_it_names failed
wiederherstellen

echo
echo "── CertificateCoverageTest: die Zuordnung wird nicht mehr geprüft ──"
#
# Die Regel steht im Modell, weil es mehrere Aufrufer geben wird: Einspielen,
# Erneuern, später Hochladen. Fällt sie dort weg, fällt sie überall weg.
vorher_datei app/Models/Domain.php
python3 - <<'PY'
p = 'app/Models/Domain.php'
s = open(p, encoding='utf-8').read()
s = s.replace('if (! $certificate->covers($this->name)) {', 'if (false) {')
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei app/Models/Domain.php "Zuordnung ohne Deckungsprüfung" &&
pruefe "Zuordnung ohne Deckungsprüfung" \
  CertificateCoverageTest::test_a_domain_refuses_a_certificate_that_does_not_cover_it failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateCoverageTest passed

echo
echo "── ChallengeLocationTest: alias statt root ──"
#
# `root` hängt den ganzen Pfad aus der Adresse an — genau dorthin schreibt der
# Agent. Mit `alias` sucht nginx zwei Ebenen höher, in einem Verzeichnis, in
# das nie jemand schreibt, und die Prüfung scheitert mit „unauthorized".
vorher_datei agent/src/Acme/HttpChallenge.php
python3 - <<'PY'
p = 'agent/src/Acme/HttpChallenge.php'
s = open(p, encoding='utf-8').read()
s = s.replace('                root {$directory};', '                alias {$directory};')
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/Acme/HttpChallenge.php "alias statt root" &&
pruefe "alias statt root" \
  ChallengeLocationTest::test_nginx_looks_exactly_where_the_agent_writes failed
wiederherstellen

echo
echo "── ChallengeLocationTest: die Prüfadresse fällt aus der Kundenvorlage ──"
#
# Eine Weiterleitung beantwortet jede Anfrage selbst, ein gesperrtes Abonnement
# antwortet 503 — beide bekämen dauerhaft kein Zertifikat, ohne Fehlermeldung.
vorher_datei agent/src/SiteTemplate.php
python3 - <<'PY'
p = 'agent/src/SiteTemplate.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        {$challenge}\n\n', '')
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/SiteTemplate.php "Kundenvorlage ohne Prüfadresse" &&
pruefe "Kundenvorlage ohne Prüfadresse" \
  ChallengeLocationTest::test_every_kind_of_site_answers_the_challenge failed
wiederherstellen

echo
echo "── ChallengeLocationTest: der Panel-Block bekommt wieder den Unterstrich ──"
#
# `server_name _;` trifft keinen echten Host-Header. Der Block wirkte nur als
# Vorgabeserver, und der ist auf Port 80 längst vergeben.
vorher_datei agent/src/Ops/PanelVhost.php
python3 - <<'PY'
p = 'agent/src/Ops/PanelVhost.php'
s = open(p, encoding='utf-8').read()
s = s.replace('server_name {$hostname};', 'server_name _;')
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/Ops/PanelVhost.php "Panel-Block ohne Rechnernamen" &&
pruefe "Panel-Block ohne Rechnernamen" \
  ChallengeLocationTest::test_the_panel_answers_the_challenge_on_port_80 failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ChallengeLocationTest passed

echo
echo "── CertificateReapplyTest: das Zertifikat wird eingespielt, der Block bleibt alt ──"
#
# docs/32 §8: Der Block entsteht bei web.site.apply, und ob HSTS darin steht,
# entscheidet sich am Zertifikat, das dabei gelesen wird. Wer nicht neu
# schreibt, bekommt ein vertrautes Zertifikat ohne den Header — und es bricht
# nichts ab.
vorher_datei app/Support/Tls/CertificateLifecycle.php
python3 - <<'PY'
p = 'app/Support/Tls/CertificateLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $this->dispatch($domain, 'web.site.apply', 'Server-Block mit Zertifikat für '.$domain->name, $operation);",
    '        // nichts',
)
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei app/Support/Tls/CertificateLifecycle.php "kein neuer Server-Block nach dem Einspielen" &&
pruefe "kein neuer Server-Block nach dem Einspielen" \
  CertificateReapplyTest::test_an_installed_certificate_is_followed_by_a_new_server_block failed
wiederherstellen

echo
echo "── CertificateReapplyTest: die beiden Regeln jagen einander ──"
#
# Bestellung, Zuordnung, Block neu, Bestellung. Ohne die Bedingung läuft die
# Warteschlange, bis die Ratenbegrenzung sie anhält.
#
# **Dieser Eingriff war tot und hat es zwei Wuerfe lang nicht gesagt.** Er suchte
# `$domain->certificate_id !== null || ! $this->settings->configured()`; der
# zweite Wurf von P4 hat daraus `choice->satisfied()` gemacht. Gemeldet hat es
# niemand, weil `BreakScriptTest` nur Bloecke mit der Marke `PY2` las — dieser
# traegt `PY`. Beides ist am 10. August 2026 behoben.
vorher_datei app/Support/Tls/CertificateLifecycle.php
python3 - <<'PY'
p = 'app/Support/Tls/CertificateLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    'if ($this->choice->satisfied($domain) || $this->ordering($domain)) {',
    'if ($this->ordering($domain)) {',
)
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei app/Support/Tls/CertificateLifecycle.php "Bestellung ohne Blick auf das vorhandene Zertifikat" &&
pruefe "Bestellung ohne Blick auf das vorhandene Zertifikat" \
  CertificateReapplyTest::test_the_two_rules_do_not_chase_each_other failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateReapplyTest passed

echo
echo "── AcmeProtocolTest: die Zertifizierungsstelle wird zur freien Adresse ──"
#
# Die Adresse, zu der ein root-Prozess eine TLS-Verbindung aufbaut, darf nicht
# aus der Anwendung kommen. Eine Prüfung auf https wäre keine Schranke.
vorher_datei agent/src/Acme/Directories.php
python3 - <<'PY'
p = 'agent/src/Acme/Directories.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (! is_string($key) || ! isset(self::URLS[$key])) {",
    "        if (! is_string($key)) {",
)
s = s.replace('        return self::URLS[$key];', '        return self::URLS[$key] ?? $key;')
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/Acme/Directories.php "beliebige Adresse als Zertifizierungsstelle" &&
pruefe "beliebige Adresse als Zertifizierungsstelle" \
  AcmeProtocolTest::test_the_panel_names_a_key_and_never_an_address failed
wiederherstellen

echo
echo "── SiteTemplateTest: die Weiterleitung steht auch ohne Zertifikat ──"
#
# Der dritte Teil des Abnahmekriteriums: Ein Fehlschlag bei der Bestellung darf
# den laufenden Betrieb nicht unterbrechen. Eine Domain, die auf HTTPS
# weiterleitet, obwohl auf 443 niemand hört, ist nicht ungesichert — sie ist
# weg.
vorher_datei agent/src/SiteTemplate.php
python3 - <<'PY'
p = 'agent/src/SiteTemplate.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '        $plain = $tls === null ? $body : self::toHttps();',
    '        $plain = self::toHttps();',
)
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/SiteTemplate.php "Weiterleitung ohne Zertifikat" &&
pruefe "Weiterleitung ohne Zertifikat" \
  SiteTemplateTest::test_without_a_certificate_the_site_stays_on_port_80 failed
wiederherstellen

echo
echo "── SiteTemplateTest: ein halbes Zertifikat zählt wieder ──"
#
# Der Fall entsteht, wenn ein Lauf zwischen den beiden Schreibvorgängen
# abbricht. Ein ssl_certificate ohne ssl_certificate_key lässt nginx nicht
# starten — dann steht nicht eine Domain still, sondern der Webserver mit allen.
vorher_datei agent/src/Acme/Store.php
python3 - <<'PY'
p = 'agent/src/Acme/Store.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '        if (! is_file($certificate) || ! is_file($key)) {',
    '        if (! is_file($certificate)) {',
)
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei agent/src/Acme/Store.php "halbes Zertifikat gilt als Zertifikat" &&
pruefe "halbes Zertifikat gilt als Zertifikat" \
  SiteTemplateTest::test_half_a_certificate_is_none failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SiteTemplateTest passed

echo
echo "── AcmeSettingsTest: die halbe Ablage überschreibt die ganze ──"
#
# Beide Angaben liegen unter demselben Schlüssel. Wer nur eine setzt und dabei
# ersetzt statt zusammenzulegen, löscht die andere — lautlos, und danach
# bestellt das Panel nichts mehr.
vorher_datei app/Support/Tls/AcmeSettings.php
python3 - <<'PY'
p = 'app/Support/Tls/AcmeSettings.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            ['value' => array_merge($setting->value ?? [], $values)],",
    "            ['value' => $values],",
)
open(p, 'w', encoding='utf-8').write(s)
PY
griff_datei app/Support/Tls/AcmeSettings.php "Einstellung wird ersetzt statt zusammengelegt" &&
pruefe "Einstellung wird ersetzt statt zusammengelegt" \
  AcmeSettingsTest::test_setting_one_value_keeps_the_other failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AcmeSettingsTest passed

echo
echo "── CertificateRenewalTest: das abgelöste Zertifikat bleibt fällig ──"
#
# Beim Erneuern entsteht ein neues Zertifikat, die Domain zeigt danach darauf,
# und die alte Zeile bleibt als Beleg stehen. Ohne die Bedingung ist sie in alle
# Ewigkeit fällig — jeder Lauf bestellt sie neu, bis die Ratenbegrenzung
# zuschlägt. Das fällt nicht am ersten Tag auf, sondern am dreissigsten.
vorher_datei app/Support/Tls/CertificateRenewal.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateRenewal.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            ->whereHas('domains')", "            ->where('id', '>', 0)")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateRenewal.php "Erneuerung ohne Blick auf die Domain" &&
pruefe "Erneuerung ohne Blick auf die Domain" \
  CertificateRenewalTest::test_a_certificate_no_domain_points_at_is_never_renewed failed
wiederherstellen

echo
echo "── CertificateRenewalTest: der Fehlversuch wird zum Dauerversuch ──"
#
# Produktiv sind fünf Fehlversuche je Konto und Stunde die Grenze. Wer nach
# jedem Fehlschlag sofort wieder anklopft, sperrt sich selbst aus — samt aller
# Domains, die in dieser Stunde neu angelegt werden.
vorher_datei app/Support/Tls/CertificateRenewal.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateRenewal.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            ->where(fn (Builder $query) => $query\n"
    "                ->whereNull('last_attempt_at')\n"
    "                ->orWhere('last_attempt_at', '<=', now()->subHours(self::RETRY_HOURS)))\n",
    '',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateRenewal.php "Erneuerung ohne Abstand nach einem Versuch" &&
pruefe "Erneuerung ohne Abstand nach einem Versuch" \
  CertificateRenewalTest::test_after_an_attempt_the_next_run_waits failed
wiederherstellen

echo
echo "── CertificateRenewalTest: der Lauf kennt keine Grenze ──"
#
# Hundert am selben Tag fällige Domains laufen sonst in die Wochengrenze der
# Zertifizierungsstelle — und dahinter stehen dann auch die neuen.
vorher_datei app/Support/Tls/CertificateRenewal.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateRenewal.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            if ($ordered + $corrected >= self::PER_RUN) {\n                break;\n            }\n",
    '',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateRenewal.php "Erneuerungslauf ohne Grenze" &&
pruefe "Erneuerungslauf ohne Grenze" \
  CertificateRenewalTest::test_a_run_orders_at_most_its_limit_and_says_what_is_left failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateRenewalTest passed

echo
echo "── CertificateReapplyTest: das Zertifikat kommt ohne Termin in den Bestand ──"
#
# Ein Zertifikat ohne Frist findet der Erneuerungslauf nie. Auffallen würde das
# in neunzig Tagen, und zwar im Browser.
#
# **Der Eingriff ist in P5 nachgezogen worden.** Er suchte diese Zeile in
# `CertificateLifecycle` — dorthin gehörte sie bis P4, seitdem steht sie in
# `CertificateRecord`. Der Bruch griff ins Leere, und das fällt erst auf, wenn
# das Skript läuft: Genau der Fall, vor dem sein eigener Kopf warnt.
vorher_datei app/Support/Tls/CertificateRecord.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateRecord.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """            'renew_after' => $source === CertificateSource::Acme
                ? CertificateRenewal::due($notAfter)
                : null,""",
    "            'renew_after' => null,",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateRecord.php "Zertifikat ohne Erneuerungstermin" &&
pruefe "Zertifikat ohne Erneuerungstermin" \
  CertificateReapplyTest::test_an_installed_certificate_is_followed_by_a_new_server_block failed
wiederherstellen

echo
echo "── SiteTemplateTest: HSTS auf ein selbstsigniertes Zertifikat ──"
#
# docs/27 §7: Der Browser merkt sich ein Jahr, und danach lässt sich auf diesem
# Host kein Zertifikatsfehler mehr wegklicken. Bei einer Kundendomain trifft das
# jeden Besucher, und der kann nichts dagegen tun.
vorher_datei agent/src/Acme/Trust.php
python3 - <<'PY2'
p = 'agent/src/Acme/Trust.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '        return ! self::selfSigned((string) file_get_contents($certificate));',
    '        return true;',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Trust.php "HSTS ohne Blick auf den Aussteller" &&
pruefe "HSTS ohne Blick auf den Aussteller" \
  SiteTemplateTest::test_a_self_signed_certificate_never_gets_hsts failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SiteTemplateTest passed

echo
echo "── WelcomePageTest: die Seite überschreibt, was der Kunde abgelegt hat ──"
#
# Ein zweiter Lauf legt sonst eine index.html neben die Seite des Kunden, und
# die wird vor index.php gefunden. Der Kunde sieht wieder den Platzhalter und
# kommt nicht auf den Gedanken, dass das Panel das war.
vorher_datei agent/src/WelcomePage.php
python3 - <<'PY2'
p = 'agent/src/WelcomePage.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if ($entries === false || array_diff($entries, ['.', '..']) !== []) {",
    '        if ($entries === false) {',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/WelcomePage.php "Willkommensseite ohne Rücksicht auf den Inhalt" &&
pruefe "Willkommensseite ohne Rücksicht auf den Inhalt" \
  WelcomePageTest::test_the_welcome_page_is_written_only_into_an_empty_document_root failed
wiederherstellen

echo
echo "── WelcomePageTest: eine neue Domain bekommt wieder ein leeres Verzeichnis ──"
#
# Der Fund aus dem Abnahmelauf für P4: nginx antwortet auf ein leeres
# DocumentRoot mit 403 — „du darfst nicht" statt „hier ist noch nichts".
vorher_datei agent/src/Ops/WebSiteApply.php
python3 - <<'PY2'
p = 'agent/src/Ops/WebSiteApply.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '        $welcome = $documentRoot !== null && WelcomePage::into($documentRoot, $site->user);',
    '        $welcome = false;',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/WebSiteApply.php "Domain ohne Willkommensseite" &&
pruefe "Domain ohne Willkommensseite" \
  WelcomePageTest::test_every_operation_that_creates_a_document_root_writes_the_page failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" WelcomePageTest passed

echo
echo "── PanelCertificateTest: die Oberfläche liefert weiter das selbstsignierte ──"
#
# Ein bestelltes Zertifikat, das nginx nicht ausliefert, ist keins. Und die
# Zertifikatsseite zeigte dann eines an, das der Browser nie bekommt.
vorher_datei agent/src/Acme/PanelCertificate.php
python3 - <<'PY2'
p = 'agent/src/Acme/PanelCertificate.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $acme = self::fromStore($store ?? new Store, $host ?? Names::host());",
    '        $acme = null;',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/PanelCertificate.php "ACME-Zertifikat der Oberfläche wird nicht ausgeliefert" &&
pruefe "ACME-Zertifikat der Oberfläche wird nicht ausgeliefert" \
  PanelCertificateTest::test_a_certificate_from_an_authority_wins failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PanelCertificateTest passed

echo
echo "── PanelVhostTest: der Aufruf ohne Port verschiebt das Panel ──"
#
# Nach dem Ausstellen wird der Block neu geschrieben, ohne dass jemand einen
# Port nennt. Wer 9443 gewählt hat, fände sein Panel danach auf 8443 — die
# Meldung dazu wäre "Verbindung abgelehnt" und stünde in keinem Protokoll.
vorher_datei agent/src/Ops/PanelVhost.php
python3 - <<'PY2'
p = 'agent/src/Ops/PanelVhost.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (preg_match('/listen\\s+(\\d+)\\s+ssl/', $conf, $match) === 1) {\n"
    "            return (int) $match[1];\n"
    "        }\n\n",
    '',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PanelVhost.php "Port der Oberfläche wird nicht gelesen" &&
pruefe "Port der Oberfläche wird nicht gelesen" \
  PanelVhostTest::test_a_call_without_a_port_keeps_the_one_that_is_there failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PanelVhostTest passed

echo
echo "── AcmeSettingsTest: das Panel bestellt aus dem Testbetrieb ──"
#
# Ein Staging-Zertifikat ist von einer Zertifizierungsstelle ausgestellt — der
# Agent schreibt also HSTS in den Block. Kein Browser kennt die Wurzel dahinter,
# die Warnung bleibt, und wegklicken lässt sie sich nicht mehr. Der Betreiber
# ist aus seinem eigenen Panel ausgesperrt.
vorher_datei app/Support/Tls/AcmeSettings.php
python3 - <<'PY2'
p = 'app/Support/Tls/AcmeSettings.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '        return $this->configured() && ! $this->staging();',
    '        return $this->configured();',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/AcmeSettings.php "Panel-Zertifikat aus dem Testbetrieb" &&
pruefe "Panel-Zertifikat aus dem Testbetrieb" \
  AcmeSettingsTest::test_the_panel_never_orders_from_the_staging_directory failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AcmeSettingsTest passed

echo
echo "── PackagingTest: das Update schreibt den Server-Block nicht mehr neu ──"
#
# Die Vorlage lebt im Agenten, die Datei unter /etc/nginx ist eine Kopie. Ohne
# diesen Aufruf gilt nach einem Update weiter der Block von der
# Ersteinrichtung — jede Änderung an der Vorlage bliebe wirkungslos, und zwar
# ohne Meldung. Genau daran ist die erste ACME-Bestellung für die Oberfläche
# gescheitert: 404 vom Vorgabeserver.
vorher_datei packaging/scripts/postinstall.sh
python3 - <<'PY2'
p = 'packaging/scripts/postinstall.sh'
s = open(p, encoding='utf-8').read()
s = s.replace("if ! /usr/local/bin/srvpanel vhost --no-interaction; then", 'if false; then')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei packaging/scripts/postinstall.sh "Update ohne neuen Server-Block" &&
pruefe "Update ohne neuen Server-Block" \
  PackagingTest::test_the_update_writes_the_server_block_again failed
wiederherstellen

echo
echo "── PackagingTest: ein apt-get in der CI darf wieder fragen ──"
#
# Dieser Wächter hatte keinen Eingriff, seit es ihn gibt. Aufgefallen ist das,
# als er zubiss — an einem Kommentar, der einen Fehlschlag festhielt statt an
# einem Kommando. Beim Beheben stand die Frage im Raum, ob er die echte Sache
# ueberhaupt noch findet, und beantworten konnte sie niemand.
vorher_datei .github/workflows/ci.yml
python3 - <<'PY2'
p = '.github/workflows/ci.yml'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "docker exec target sh -c \\\n            'DEBIAN_FRONTEND=noninteractive apt-get install -y postgresql >/dev/null'",
    "docker exec target sh -c \\\n            'apt-get install -y postgresql >/dev/null'",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei .github/workflows/ci.yml "apt-get ohne DEBIAN_FRONTEND" &&
pruefe "apt-get ohne DEBIAN_FRONTEND" \
  PackagingTest::test_no_apt_install_in_the_ci_can_ask_a_question failed
wiederherstellen

echo
echo "── PackagingTest: das Warten auf systemd misst nicht mehr mit ──"
#
# Das Fenster stand auf 300 s, die einzige Messung daneben auf 255 s — und am
# 11. August riss es. Teuer war nicht das knappe Fenster, sondern dass der
# Abstand nur in einem Kommentar stand und lautlos veraltete. Seitdem schreibt
# jeder gruene Lauf seine Dauer selbst hin.
vorher_datei .github/workflows/ci.yml
python3 - <<'PY2'
p = '.github/workflows/ci.yml'
s = open(p, encoding='utf-8').read()
s = s.replace('              echo "::notice::systemd war nach $((i * 2)) s da (Fenster: $((versuche * 2)) s)."\n', '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei .github/workflows/ci.yml "Warten ohne gemessene Dauer" &&
pruefe "Warten ohne gemessene Dauer" \
  PackagingTest::test_every_wait_for_systemd_reports_how_long_it_took failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PackagingTest passed

echo
echo "── PackagingTest: der Wrapper kennt das neue Kommando nicht ──"
#
# Wer `srvpanel vhost` tippt, bekommt sonst „Command not defined" — und das
# postinstall-Skript ebenfalls, still und mit Rückgabewert 1.
vorher_datei packaging/bin/srvpanel
python3 - <<'PY2'
p = 'packaging/bin/srvpanel'
s = open(p, encoding='utf-8').read()
s = s.replace('|db|vhost|', '|db|')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei packaging/bin/srvpanel "Kommando fehlt im Wrapper" &&
pruefe "Kommando fehlt im Wrapper" \
  PackagingTest::test_the_wrapper_knows_every_command_of_the_panel failed
wiederherstellen

echo
echo "── PackagingTest: der Wrapper setzt kein HOME ──"
#
# `setpriv` wechselt die Kennung und lässt die Umgebung stehen: HOME zeigt
# danach auf /root, und der Benutzer srvpanel darf dort nicht schreiben. psysh
# legt sein Verzeichnis unter HOME an, darf es nicht — und führt den Code aus
# `srvpanel tinker --execute` **gar nicht mehr aus**, bei Rückgabewert 0 und
# ohne eine Zeile Ausgabe.
#
# Gemeldet vom Betreiber am 18. August 2026, und ausdrücklich als etwas, das in
# mehreren Sitzungen davor schon im Weg stand.
vorher_datei packaging/bin/srvpanel
python3 - <<'PY2'
p = 'packaging/bin/srvpanel'
s = open(p, encoding='utf-8').read()
alt = 'HOME=/var/lib/srvpanel\nexport HOME\n\n'
assert s.count(alt) == 1, 'Die Zielzeilen stehen nicht genau einmal da: %d' % s.count(alt)
s = s.replace(alt, '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei packaging/bin/srvpanel "Wrapper ohne HOME" &&
pruefe "Wrapper ohne HOME" \
  PackagingTest::test_the_wrapper_sets_a_home_the_service_user_may_write failed
wiederherstellen

echo
echo "── PackagingTest: HOME zeigt auf ein Verzeichnis, das root gehört ──"
#
# Der Eingriff, der am harmlosesten aussieht: HOME *ist* gesetzt, es gibt das
# Verzeichnis, und der Benutzer darf trotzdem nicht hinein. Ein HOME, das man
# nicht schreiben kann, ist keins.
vorher_datei packaging/bin/srvpanel
python3 - <<'PY2'
p = 'packaging/bin/srvpanel'
s = open(p, encoding='utf-8').read()
alt = 'HOME=/var/lib/srvpanel'
assert s.count(alt) == 1, 'Die Zielzeile steht nicht genau einmal da: %d' % s.count(alt)
s = s.replace(alt, 'HOME=/var/www/vhosts', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei packaging/bin/srvpanel "HOME gehoert root" &&
pruefe "HOME gehoert root" \
  PackagingTest::test_the_wrapper_sets_a_home_the_service_user_may_write failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PackagingTest passed

echo
echo "── PackagingTest: install.sh zeigt auf einen Kanal ohne Freigabe ──"
#
# Genau der Zustand, der die Erstinstallation kaputtgemacht hat: Vorgabe
# `stable`, während dort noch der Index des Vorgängers lag — fremd signiert,
# ohne eine einzige Datei im Pool. `apt-get update` endete im NO_PUBKEY.
vorher_datei packaging/install.sh
python3 - <<'PY2'
p = 'packaging/install.sh'
s = open(p, encoding='utf-8').read()
s = s.replace('CHANNEL="${SRVPANEL_CHANNEL:-beta}"', 'CHANNEL="${SRVPANEL_CHANNEL:-stable}"')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei packaging/install.sh "Vorgabe auf einen Kanal ohne Freigabe" &&
pruefe "Vorgabe auf einen Kanal ohne Freigabe" \
  PackagingTest::test_the_installer_offers_a_channel_that_is_actually_published failed
wiederherstellen

echo
echo "── PackagingTest: die stabile Freigabe kommt, install.sh bleibt auf beta ──"
#
# Die andere Richtung, und die zählt genauso: Steht in der Marke eine Fassung,
# bekäme sonst jeder Neuzugang weiter eine Vorabfassung angeboten. Ein Wächter,
# der nur beim Betreten der Beta-Phase zubeisst, verschwindet beim Verlassen.
vorher_datei packaging/stable-release
python3 - <<'PY2'
p = 'packaging/stable-release'
s = open(p, encoding='utf-8').read()
open(p, 'w', encoding='utf-8').write(s + '0.5.0\n')
PY2
griff_datei packaging/stable-release "Marke gesetzt, Vorgabe nicht nachgezogen" &&
pruefe "Marke gesetzt, Vorgabe nicht nachgezogen" \
  PackagingTest::test_the_installer_offers_a_channel_that_is_actually_published failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PackagingTest passed

echo
echo "── TlsSettingsTest: die Kontaktadresse wird nicht mehr geprüft ──"
#
# Eine Adresse, die keine ist, fiele sonst erst auf, wenn ein Kunde eine Domain
# anlegt — und dann als Vorgang, der ohne Zutun scheitert.
vorher_datei app/Http/Controllers/TlsSettingsController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/TlsSettingsController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'contact' => ['required', 'email', 'max:255'],", "'contact' => ['required', 'string'],")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/TlsSettingsController.php "Kontaktadresse ohne Prüfung" &&
pruefe "Kontaktadresse ohne Prüfung" \
  TlsSettingsTest::test_an_address_that_is_none_is_refused failed
wiederherstellen

echo
echo "── TlsSettingsTest: jede Zeichenkette als Zertifizierungsstelle ──"
#
# Der Wert geht an einen Prozess, der als root eine TLS-Verbindung aufbaut. Der
# Agent weist ihn ab — aber dann steht er im Bestand und nichts wird bestellt.
vorher_datei app/Http/Controllers/TlsSettingsController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/TlsSettingsController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'directory' => ['required', Rule::in(Directories::keys())],", "'directory' => ['required', 'string'],")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/TlsSettingsController.php "Zertifizierungsstelle ohne Positivliste" &&
pruefe "Zertifizierungsstelle ohne Positivliste" \
  TlsSettingsTest::test_only_the_known_certificate_authorities_are_accepted failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TlsSettingsTest passed

echo
echo "── DomainCertificateTest: der Knopf hinterlässt eine leere Vorgangsliste ──"
#
# Ohne Kontaktadresse bestellt CertificateOrder nichts. Ein Knopf, der das nicht
# sagt, sieht aus, als hätte er gewirkt — und der Betreiber wartet auf einen
# Vorgang, den es nie gab.
vorher_datei app/Http/Controllers/DomainController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DomainController.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if ($operation === null) {",
    "        if (false) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DomainController.php "Bestellung ohne Kontaktadresse schweigt" &&
pruefe "Bestellung ohne Kontaktadresse schweigt" \
  DomainCertificateTest::test_without_a_contact_address_it_says_why_nothing_happened failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DomainCertificateTest passed

echo
echo "── MobileLayoutTest: eine Kennung, die im Fliesstext nicht bricht ──"
#
# Der Bruch stellt den Zustand von vor P4 wieder her: `white-space: nowrap` an
# der Klasse selbst. Auf dem Zielserver lief damit die Liste der Namen in der
# Warnung der Zertifikatsseite aus der Meldung und die Seite aus dem Bildschirm.
vorher
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('\n  overflow-wrap: anywhere;\n}', '\n  white-space: nowrap;\n}', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff "Kennung ohne Umbruch im Fliesstext" &&
pruefe "Kennung ohne Umbruch im Fliesstext" \
  MobileLayoutTest::test_an_identifier_may_break_outside_a_table failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  MobileLayoutTest::test_an_identifier_may_break_outside_a_table passed

echo
echo "── SiteTemplateTest: der Agent leitet das Zertifikat wieder selbst ab ──"
#
# Der Zustand vor dem zweiten Wurf: Der Agent sieht unter dem Namen der Domain
# nach und nimmt, was dort liegt. Damit entscheidet das Dateisystem darüber,
# was nginx vorweist — ein Platzhalter liegt unter keinem der Namen, die er
# deckt, und die Zuordnung im Panel ist die zweite Wahrheit daneben.
vorher_datei agent/src/SiteTemplate.php
python3 - <<'PY2'
p = 'agent/src/SiteTemplate.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $tls = $site->certificate === null\n"
    "            ? null\n"
    "            : ($store ?? new Store)->existing($site->certificate);",
    "        $tls = ($store ?? new Store)->existing($site->domain);",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/SiteTemplate.php "Zertifikat aus dem Domainnamen abgeleitet" &&
pruefe "Zertifikat aus dem Domainnamen abgeleitet" \
  SiteTemplateTest::test_a_certificate_the_panel_does_not_name_is_not_delivered failed
wiederherstellen

echo
echo "── WebLifecycleTest: das Panel nennt kein Zertifikat mehr ──"
#
# Die Gegenrichtung desselben Fehlers: Der Agent fragt richtig, aber es kommt
# keine Antwort. Jede gesicherte Website fiele beim nächsten Anwenden auf
# Port 80 zurück — ohne Fehler und ohne Meldung.
vorher_datei app/Support/Web/WebLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Web/WebLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            'certificate' => $this->certificate($domain)?->storage_name,",
    "            'certificate' => null,",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Web/WebLifecycle.php "Payload ohne Zertifikatsnamen" &&
pruefe "Payload ohne Zertifikatsnamen" \
  WebLifecycleTest::test_the_payload_names_the_assigned_certificate failed
wiederherstellen

echo
echo "── CertificateCoverageTest: ein fremdes Zertifikat wird zugeordnet ──"
#
# Die Deckungsprüfung allein genügt ab dem Platzhalter nicht mehr:
# `*.example.de` deckt auch die Unterdomain eines anderen Kunden. Ohne die
# Eigentumsfrage wiese der Block des einen Kunden das Zertifikat des anderen
# vor.
vorher_datei app/Models/Domain.php
python3 - <<'PY2'
p = 'app/Models/Domain.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if ($certificate->subscription_id !== $this->subscription_id) {",
    "        if (false) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Models/Domain.php "Zertifikat eines fremden Abonnements" &&
pruefe "Zertifikat eines fremden Abonnements" \
  CertificateCoverageTest::test_a_covering_certificate_of_another_subscription_is_refused failed
wiederherstellen

echo
echo "── CertificateReapplyTest: der Verweis genügt wieder statt der Deckung ──"
#
# Der Alias, der nach der Ausstellung dazukam, steht im `server_name` und nicht
# im Zertifikat. Der Browser warnt bei ihm, und im Panel sieht alles grün aus —
# der Fall, den `covers_all` seit Schritt 6 anzeigt und den bis hierher niemand
# behob.
#
# **Auch dieser Eingriff ist in P5 nachgezogen worden** — dieselbe Umbenennung
# wie oben: Die Deckungsprüfung ist aus `CertificateLifecycle` nach
# `CertificateChoice::usable()` gezogen.
#
# **Und am 10. August 2026 ein zweites Mal**, weil er grün durchlief. Er traf
# nur das `usable()` am Ende der Datei — `candidates()` prüft dieselbe Deckung
# noch einmal, und über `satisfied()` läuft der Weg durch beide. Wer eine von
# zwei gleichlautenden Prüfungen entfernt, ändert nichts; der Bruch war keine
# Regression, sondern eine Umformung.
#
# > **Eine Regel, die an zwei Stellen steht, wird von einem Bruch an einer
# > Stelle nicht gebrochen.**
#
# Deshalb fällt hier jedes `coversAll` dieser Datei — das ist die Regel, und
# der Bruch nimmt sie ganz weg.
vorher_datei app/Support/Tls/CertificateChoice.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateChoice.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$certificate->coversAll($names)", "true")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateChoice.php "Verweis statt Deckung" &&
pruefe "Verweis statt Deckung" \
  CertificateReapplyTest::test_a_certificate_that_misses_a_name_is_ordered_again failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateReapplyTest passed

echo
echo "── CertificateStorageTest: der Stern landet im Pfad ──"
#
# Der Ablageort steht als `ssl_certificate` in einer nginx-Datei, die als root
# gelesen wird. Ein Stern ist für jede Shell, für `find` und für `rm` ein
# Muster — ein Name, der unterwegs expandiert, bezeichnet dann etwas anderes.
vorher_datei agent/src/Acme/CertificateName.php
python3 - <<'PY2'
p = 'agent/src/Acme/CertificateName.php'
s = open(p, encoding='utf-8').read()
s = s.replace("    public const WILDCARD = '_wildcard.';", "    public const WILDCARD = '*.';")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/CertificateName.php "Stern im Ablageort" &&
pruefe "Stern im Ablageort" \
  CertificateStorageTest::test_a_wildcard_is_stored_under_a_name_without_a_star failed
wiederherstellen

echo
echo "── CertificateStorageTest: Platzhalter und Basisdomain fallen zusammen ──"
#
# `example.de` und `*.example.de` sind zwei Zertifikate, nicht eines. Fielen
# sie in dasselbe Verzeichnis, überschriebe das eine das andere — und welches
# gerade dort liegt, hinge davon ab, welche Bestellung zuletzt lief.
vorher_datei agent/src/Acme/CertificateName.php
python3 - <<'PY2'
p = 'agent/src/Acme/CertificateName.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "                return $source.self::WILDCARD.DomainName::normalize(substr($name, strlen($prefix)), $field);",
    "                return $source.DomainName::normalize(substr($name, strlen($prefix)), $field);",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/CertificateName.php "Platzhalter ohne eigenen Ablageort" &&
pruefe "Platzhalter ohne eigenen Ablageort" \
  CertificateStorageTest::test_two_different_names_never_share_a_directory failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateStorageTest passed

echo
echo "── CertificateUploadTest: die Kette wird nicht auf Reihenfolge geprüft ──"
#
# Eine falsch sortierte Kette verzeihen Browser unterschiedlich: Firefox holt
# das fehlende Glied nach, ein Mobilgerät nicht. Der Betreiber sieht eine
# Seite, die bei ihm aufgeht, und der Kunde eine Warnung.
vorher_datei agent/src/Acme/Bundle.php
python3 - <<'PY2'
p = 'agent/src/Acme/Bundle.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        self::ordered($certificates);", "        // self::ordered($certificates);")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Bundle.php "Kette ohne Reihenfolgeprüfung" &&
pruefe "Kette ohne Reihenfolgeprüfung" \
  CertificateUploadTest::test_a_chain_in_the_wrong_order_is_refused failed
wiederherstellen

echo
echo "── CertificateUploadTest: hochgeladen und bestellt teilen sich den Ort ──"
#
# Der Schlüssel im Ablageort entsteht aus dem ersten Namen. Ohne die
# Kennzeichnung der Quelle überschriebe ein hochgeladenes Zertifikat für
# `example.de` das bestellte — und welches gerade dort liegt, hinge davon ab,
# was zuletzt lief.
vorher_datei agent/src/Acme/CertificateName.php
python3 - <<'PY2'
p = 'agent/src/Acme/CertificateName.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return str_starts_with($key, self::UPLOADED) ? $key : self::UPLOADED.$key;",
    "        return $key;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/CertificateName.php "Hochgeladenes ohne eigenen Ablageort" &&
pruefe "Hochgeladenes ohne eigenen Ablageort" \
  CertificateUploadTest::test_it_is_stored_apart_from_what_was_ordered failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateUploadTest passed

echo
echo "── DomainRouteTest: Hochladen ohne Planfreigabe ──"
#
# Wer hochlädt, legt einen privaten Schlüssel auf den Server, und was danach
# ausgeliefert wird, sieht jeder Besucher. Ob ein Kunde das darf, entscheidet
# der Betreiber über den Plan — nicht die Fähigkeit, eine Domain zu ändern.
vorher_datei app/Policies/DomainPolicy.php
python3 - <<'PY2'
p = 'app/Policies/DomainPolicy.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            && $subscription->feature(Feature::CertificateUpload->value)\n",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Policies/DomainPolicy.php "Hochladen ohne Planfreigabe" &&
pruefe "Hochladen ohne Planfreigabe" \
  DomainRouteTest::test_without_the_plan_feature_no_certificate_may_be_uploaded failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  DomainRouteTest::test_without_the_plan_feature_no_certificate_may_be_uploaded passed

echo
echo "── CertificateChoiceTest: die Bestellung nimmt die Wahl still zurück ──"
#
# Der Grund, aus dem es `certificate_pinned_at` überhaupt gibt. Ohne die
# Bedingung hängt die Domain nach der nächsten Erneuerung am neuen Zertifikat —
# ohne Fehler, ohne Meldung, und die Wahl von gestern ist weg.
vorher_datei app/Support/Tls/CertificateRecord.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateRecord.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if ($uploaded || $domain->certificate_pinned_at === null) {",
    "        if (true) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateRecord.php "Bestellung überschreibt die Wahl" &&
pruefe "Bestellung überschreibt die Wahl" \
  CertificateChoiceTest::test_a_choice_survives_a_new_order failed
wiederherstellen

echo
echo "── CertificateChoiceTest: die abgelaufene Wahl wird befolgt ──"
#
# Der stumme Ausgang desselben Falls: Ein hochgeladenes Zertifikat erneuert
# niemand. Wer stur daran festhält, liefert ein abgelaufenes aus, obwohl ein
# gültiges danebenliegt — und die Website ist für jeden Browser kaputt.
vorher_datei app/Support/Tls/CertificateChoice.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateChoice.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if ($domain->certificate_pinned_at !== null\n"
    "            && $assigned instanceof Certificate\n"
    "            && $this->usable($assigned, $names)) {",
    "        if ($domain->certificate_pinned_at !== null\n"
    "            && $assigned instanceof Certificate) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateChoice.php "Abgelaufene Wahl wird ausgeliefert" &&
pruefe "Abgelaufene Wahl wird ausgeliefert" \
  CertificateChoiceTest::test_an_expired_choice_is_overridden_loudly failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateChoiceTest passed

echo
echo "── DnsPacketTest: der Namenszeiger wird nicht erkannt ──"
#
# Ein Name in einer DNS-Antwort steht selten ausgeschrieben da; meistens sind es
# zwei Bytes, die auf eine frühere Stelle zeigen. Wer das nicht erkennt, liest
# die folgenden Felder verschoben — und bekommt Werte, die fast stimmen.
#
# **Und dieser dritte ist in P5 nachgezogen worden**: Das Lesen eines Namens ist
# aus `Packet` nach `Dns\Name` gezogen, samt der Marke als `POINTER_MASK`.
vorher_datei agent/src/Acme/Dns/Name.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Name.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            if (($marker & self::POINTER_MASK) === self::POINTER_MASK) {",
    "            if (false) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Name.php "Namenszeiger nicht erkannt" &&
pruefe "Namenszeiger nicht erkannt" \
  DnsPacketTest::test_a_compressed_name_is_read_correctly failed
wiederherstellen

echo
echo "── DnsChallengeTest: ein Nameserver genügt ──"
#
# Welchen die Zertifizierungsstelle fragt, weiss niemand — sie fragt sogar aus
# mehreren Netzen zugleich. Ein Wert, den nur die Hälfte der Server kennt, ist
# eine Prüfung, die manchmal gelingt, und das ist die unangenehmste Sorte
# Fehler: Jeder Fehlschlag kostet einen der fünf Versuche je Stunde.
vorher_datei agent/src/Acme/DnsChallenge.php
python3 - <<'PY2'
p = 'agent/src/Acme/DnsChallenge.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        foreach ($servers as $server) {\n"
    "            if (! in_array($wanted, $this->resolver->txt($server, $record), true)) {\n"
    "                return false;\n"
    "            }\n"
    "        }\n"
    "\n"
    "        return true;",
    "        foreach ($servers as $server) {\n"
    "            if (in_array($wanted, $this->resolver->txt($server, $record), true)) {\n"
    "                return true;\n"
    "            }\n"
    "        }\n"
    "\n"
    "        return false;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/DnsChallenge.php "Ein Nameserver genügt" &&
pruefe "Ein Nameserver genügt" \
  DnsChallengeTest::test_it_waits_until_every_nameserver_serves_the_value failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DnsChallengeTest passed

echo
echo "── DnsCredentialsTest: der Profilname wird geglaubt ──"
#
# Er wird zu einem Pfad in einem Prozess mit Systemrechten. Ohne die Prüfung
# läge `../../etc/irgendwas` auf der Platte — und zwar mit 0600 root, also
# genau da, wo es niemandem auffällt.
vorher_datei agent/src/Acme/Dns/Credentials.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Credentials.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (preg_match(self::NAME_PATTERN, $name) !== 1) {",
    "        if (false) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Credentials.php "Profilname ohne Prüfung" &&
pruefe "Profilname ohne Prüfung" \
  DnsCredentialsTest::test_a_name_that_is_no_file_name_is_refused failed
wiederherstellen

echo
echo "── DnsCredentialsTest: die Antwort trägt das Token mit ──"
#
# Der Weg vom Token in den Agenten ist einer, und zurück führt keiner. Gäbe die
# Auskunft es heraus, stünde es in jeder Antwort, die jemand mitschneidet — und
# in der Vorgangsliste des Panels.
vorher_datei agent/src/Ops/DnsCredentialStore.php
python3 - <<'PY2'
p = 'agent/src/Ops/DnsCredentialStore.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            'stored' => true,\n        ];",
    "            'stored' => true,\n            'config' => $config,\n        ];",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/DnsCredentialStore.php "Token in der Antwort" &&
pruefe "Token in der Antwort" \
  DnsCredentialsTest::test_no_operation_answers_with_the_token failed
wiederherstellen

echo
echo "── DnsProfileTest: die Planfreigabe entscheidet nicht mehr ──"
#
# Ohne sie bekäme jedes Abonnement sein eigenes Profil — auch eines, dessen Zone
# der Betreiber führt. Bestellt würde dann mit Zugangsdaten, die es nicht gibt.
vorher_datei app/Support/Tls/DnsProfile.php
python3 - <<'PY2'
p = 'app/Support/Tls/DnsProfile.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (! $subscription->feature(Feature::DnsEdit->value)) {",
    "        if (false) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/DnsProfile.php "Profil ohne Planfreigabe" &&
pruefe "Profil ohne Planfreigabe" \
  DnsProfileTest::test_without_the_feature_the_operator_profile_applies failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DnsProfileTest passed

echo
echo "── TsigTest: über den erhöhten Zähler unterschrieben ──"
#
# Gerechnet wird über die Nachricht, *bevor* der TSIG-Satz dazukommt — also mit
# dem alten ARCOUNT. Wer es andersherum macht, bekommt eine Unterschrift, die in
# sich stimmig ist, und einen Nameserver, der NOTAUTH antwortet, ohne zu sagen,
# an welcher der acht Grössen es lag.
vorher_datei agent/src/Acme/Dns/Tsig.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Tsig.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $mac = hash_hmac(self::ALGORITHMS[$this->algorithm], $message.$variables, $this->secret, true);",
    "        $mac = hash_hmac(self::ALGORITHMS[$this->algorithm], self::withOneMoreAdditional($message).$variables, $this->secret, true);",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Tsig.php "Unterschrift über den erhöhten Zähler" &&
pruefe "Unterschrift über den erhöhten Zähler" \
  TsigTest::test_the_mac_is_the_one_the_rfc_prescribes failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TsigTest passed

echo
echo "── Rfc2136Test: die Zone als Zeichenkette verglichen ──"
#
# `bösexample.de` endet auf `example.de`. Verglichen wird deshalb
# beschriftungsweise — ein Vergleich als Zeichenkette liesse eine fremde Domain
# in eine Zone hinein, die jemand anderem gehört, und das ist hier die Grenze
# zwischen zwei Kunden.
vorher_datei agent/src/Acme/Dns/Name.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Name.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return $name === $zone || ($zone !== '' && str_ends_with($name, '.'.$zone));",
    "        return $zone !== '' && str_ends_with($name, $zone);",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Name.php "Zone als Zeichenkette verglichen" &&
pruefe "Zone als Zeichenkette verglichen" \
  Rfc2136Test::test_a_name_outside_the_zones_is_refused failed
wiederherstellen

echo
echo "── ProvidersTest: ein Anbieter ohne Umsetzung gilt als angeboten ──"
#
# Ein Schlüssel, der weder gebaut ist noch zurückgehalten wird, ist genau die
# Zeichenkette, die auf nichts zeigt — und sie fiele erst beim ersten
# Zertifikat auf, mit einem Geheimnis, das längst auf der Platte liegt.
vorher_datei agent/src/Acme/Dns/Providers.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Providers.php'
s = open(p, encoding='utf-8').read()
# Ein neunter Schlüssel, den es nur als Etikett gibt: weder gebaut noch offen.
s = s.replace(
    "        self::DESEC => 'deSEC',\n",
    "        self::DESEC => 'deSEC',\n        'erfunden' => 'Erfunden',\n",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Providers.php "Anbieter ohne Umsetzung nicht als offen geführt" &&
pruefe "Anbieter ohne Umsetzung nicht als offen geführt" \
  ProvidersTest::test_every_provider_key_points_at_something failed
wiederherstellen

echo
echo "── DnsNameSourceTest: eine zweite Fassung des Drahtformats ──"
#
# Ein Name in einer Antwort steht selten ausgeschrieben da; meistens sind es
# zwei Bytes, die auf eine frühere Stelle zeigen. Eine zweite Fassung liest die
# folgenden Felder irgendwann um einige Bytes verschoben — und im Protokoll
# steht nichts, was darauf hindeutet.
vorher_datei agent/src/Acme/Dns/Resolver.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Resolver.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "    public const PORT = 53;",
    "    public const PORT = 53;\n\n"
    "    private static function skipName(string $answer, int &$offset): void\n"
    "    {\n"
    "        if ((ord($answer[$offset]) & 0xC0) === 0xC0) {\n"
    "            $offset += 2;\n"
    "        }\n"
    "    }",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Resolver.php "zweite Fassung des Drahtformats" &&
pruefe "zweite Fassung des Drahtformats" \
  DnsNameSourceTest::test_only_one_place_writes_a_dns_name failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DnsNameSourceTest passed

echo
echo "── InheritedNameTest: ein Name, der der Basisklasse gehört ──"
#
# `configure()` ist in einem Artisan-Kommando protected. Als private eingezogen
# bricht die Klasse beim Laden — und damit steht nicht ein Kommando still,
# sondern artisan mit allen. Dreimal passiert; `php -l` sieht davon nichts.
vorher_datei app/Console/Commands/DnsCredentials.php
python3 - <<'PY2'
p = 'app/Console/Commands/DnsCredentials.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "    /** @return array<string, string> */\n    private function actor(): array",
    "    private function configure(): void {}\n\n"
    "    /** @return array<string, string> */\n    private function actor(): array",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/DnsCredentials.php "Name der Basisklasse eingezogen" &&
pruefe "Name der Basisklasse eingezogen" \
  InheritedNameTest::test_no_class_redeclares_a_name_of_its_base_class failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" InheritedNameTest passed

echo
echo "── WildcardOrderTest: die Basisdomain steht vorn ──"
#
# Der Ablageort entsteht aus dem ersten Namen. Steht die Basisdomain vorn, liegt
# der Platzhalter unter example.de und überschreibt ein einfaches Zertifikat für
# denselben Namen — gemerkt hätte man es, wenn eine Domain plötzlich das falsche
# ausliefert.
vorher_datei app/Support/Tls/WildcardOrder.php
python3 - <<'PY2'
p = 'app/Support/Tls/WildcardOrder.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return ['*.'.$domain->name, $domain->name];",
    "        return [$domain->name, '*.'.$domain->name];",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/WildcardOrder.php "Basisdomain vor dem Stern" &&
pruefe "Basisdomain vor dem Stern" \
  WildcardOrderTest::test_the_star_comes_first_and_the_base_name_is_there failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" WildcardOrderTest passed

echo
echo "── WildcardOrderTest: ohne Zugangsdaten wird trotzdem angeboten ──"
#
# Eine Bestellung, die mangels Token scheitert, verbrennt einen der fünf
# Fehlversuche je Konto und Stunde — und die gelten für jeden Kunden dieses
# Servers, nicht nur für den, der geklickt hat.
vorher_datei app/Support/Tls/WildcardOrder.php
python3 - <<'PY2'
p = 'app/Support/Tls/WildcardOrder.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (! $this->hasCredentials($domain)) {",
    "        if (false) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/WildcardOrder.php "Platzhalter ohne Zugangsdaten" &&
pruefe "Platzhalter ohne Zugangsdaten" \
  WildcardOrderTest::test_without_credentials_it_says_so_instead_of_ordering failed
wiederherstellen

echo
echo "── WildcardOrderTest: eine Subdomain bekommt auch einen ──"
#
# `*.blog.example.de` ist zulässig und nicht das, was jemand meint, der auf
# einer Subdomainseite auf „Platzhalter" klickt. Geprüft wird der Typ, den das
# Panel ohnehin führt — nicht der eingetippte Name.
vorher_datei app/Support/Tls/WildcardOrder.php
python3 - <<'PY2'
p = 'app/Support/Tls/WildcardOrder.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return in_array($domain->type, self::BASE_TYPES, true);",
    "        return true;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/WildcardOrder.php "Platzhalter zu einer Subdomain" &&
pruefe "Platzhalter zu einer Subdomain" \
  WildcardOrderTest::test_only_a_base_domain_gets_one failed
wiederherstellen

echo
echo "── WildcardOrderTest: die gewöhnliche Bestellung nennt plötzlich DNS-01 ──"
#
# Ein Zeitplan aus der Fassung vor Schritt 8 schickt keine Challenge mit. Stünde
# sie hier immer, liefe jede Erneuerung über DNS-01 — auch für Domains, für die
# gar keine Zugangsdaten hinterlegt sind.
vorher_datei app/Support/Tls/CertificateOrder.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateOrder.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            ] + ($wildcard ? [",
    "            ] + (true ? [",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateOrder.php "DNS-01 auch ohne Platzhalter" &&
pruefe "DNS-01 auch ohne Platzhalter" \
  WildcardOrderTest::test_an_ordinary_order_says_nothing_about_dns failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" WildcardOrderTest passed

echo
echo "── AbilityReachTest: eine Fähigkeit der Domainseite kommt nicht an ──"
#
# Eine Fahne, die nie ankommt, ist in Vue `undefined` — der Knopf verschwindet
# dann für **alle**, ohne dass etwas meldet. Bis Schritt 8 hiess diese Ablage
# `may` und war von diesem Wächter gar nicht erfasst.
vorher_datei app/Http/Controllers/DomainController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DomainController.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "                'order_wildcard' => $request->user()?->can('orderWildcard', $domain) ?? false,\n",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DomainController.php "Fähigkeit fehlt im Payload" &&
pruefe "Fähigkeit fehlt im Payload" \
  AbilityReachTest::test_every_ability_a_page_asks_for_is_sent failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AbilityReachTest passed

echo
echo "── CustomerTest: die Anmeldeadresse bleibt beim Zurückziehen belegt ──"
#
# Der Fall aus dem Betrieb: Kunde zurückgezogen, neuer Kunde mit derselben
# Adresse — und scheinbar passiert nichts. `accounts.email` trägt einen
# Unique-Index, und der galt weiter für ein Konto, das sich nie wieder anmelden
# kann. Die Nummer soll gesperrt bleiben, die Adresse nicht.
vorher_datei app/Http/Controllers/CustomerController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/CustomerController.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            foreach ($customer->accounts()->whereNotNull('email')->get() as $account) {",
    "            foreach ([] as $account) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/CustomerController.php "Adresse bleibt belegt" &&
pruefe "Adresse bleibt belegt" \
  CustomerTest::test_the_address_is_free_again_after_a_withdrawal failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CustomerTest passed

echo
echo "── FormErrorTest: eine Seite verschweigt, dass das Formular abgewiesen wurde ──"
#
# Ohne die Zusammenfassung steht die einzige Meldung als rote Zeile am Feld —
# und nach einer Antwort springt die Seite nach oben, wo dann nichts steht.
# Genau so ist ein Fehlschlag einen halben Tag lang als „der Knopf tut nichts"
# gelesen worden.
vorher_datei resources/js/Pages/Customers/Create.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Customers/Create.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("    <FormErrors />\n\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Customers/Create.vue "Formular ohne Zusammenfassung" &&
pruefe "Formular ohne Zusammenfassung" \
  FormErrorTest::test_every_page_with_a_form_shows_what_went_wrong failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FormErrorTest passed

echo
echo "── FormErrorTest: eine Seite, die ihr Formular aus einer Komponente holt ──"
#
# **Die Lücke, aus der dieser Bruch entstand.** Der Wächter suchte in den Seiten
# nach `useForm`. `DnsCredentials.vue` trägt das Formular für die
# DNS-Zugangsdaten und steht unter `Components/`; die Abonnementseite bindet es
# ein und enthält das Wort nirgends — sie galt damit als formularlos und wäre
# ohne Zusammenfassung durchgegangen. Ein Wächter, der grün meldet, weil die
# Regel umgezogen ist, ist der Fehler, der in diesem Projekt am häufigsten
# wiederkehrt.
vorher_datei resources/js/Pages/Subscriptions/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("    <FormErrors />\n\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Show.vue "Formular aus einer Komponente, ohne Zusammenfassung" &&
pruefe "Formular aus einer Komponente, ohne Zusammenfassung" \
  FormErrorTest::test_every_page_with_a_form_shows_what_went_wrong failed
wiederherstellen

echo
echo "── DnsCredentialsTest: die Auskunft reicht die Konfiguration durch ──"
#
# Der Weg, auf dem ein DNS-Token die Oberfläche erreichen würde: nicht als
# `secret` — daran denkt jeder —, sondern weil `describe()` eines Tages die
# ganze Konfiguration durchreicht. Bei Hetzner, Cloudflare, Netcup und
# IPv64.net heisst das Geheimnis `token` oder `api_key`, und keiner dieser
# Namen stünde in einer Sperrliste. Deshalb prüft der Wächter den Schlüsselsatz
# und nicht die Abwesenheit eines Wortes.
vorher_datei agent/src/Acme/Dns/Credentials.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Credentials.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            'zones' => self::zonesOf(is_array($config) ? $config : []),\n        ];",
    "            'zones' => self::zonesOf(is_array($config) ? $config : []),\n            'config' => $config,\n        ];",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Credentials.php "Auskunft mit der ganzen Konfiguration" &&
pruefe "Auskunft mit der ganzen Konfiguration" \
  DnsCredentialsTest::test_the_description_says_these_four_things_and_no_more failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DnsCredentialsTest passed

echo
echo "── PackagingTest: der Freigabelauf legt an, ohne vorher zu fragen ──"
#
# Am 6. August hat GitHubs Warteschlange fünf Anläufe ohne Runner verhungern
# lassen; der sechste kam durch und legte das Release an, der siebte brach
# genau hier ab. Für sich richtig — nur galt damit `package` als gescheitert,
# und `repository` wurde übersprungen. Die Paketquelle blieb ohne die Fassung,
# die als Release längst dastand.
vorher_datei .github/workflows/release.yml
python3 - <<'PY2'
p = '.github/workflows/release.yml'
s = open(p, encoding='utf-8').read()
s = s.replace('gh release view', 'gh release ansehen')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei .github/workflows/release.yml "Release anlegen ohne Fallunterscheidung" &&
pruefe "Release anlegen ohne Fallunterscheidung" \
  PackagingTest::test_the_release_can_run_a_second_time failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PackagingTest passed

echo
echo "── OutboundSourceTest: eine Zusage der Aussenverbindung fällt weg ──"
#
# Der Agent läuft als root. Ohne `FOLLOWLOCATION => false` trägt eine Umleitung
# die signierte ACME-Anfrage — oder ein DNS-Token — an eine Adresse, die
# niemand hinterlegt hat. Die vier Zusagen standen bis Schritt 9 nur als
# Kommentar da.
vorher_datei agent/src/Acme/Curl.php
python3 - <<'PY2'
p = 'agent/src/Acme/Curl.php'
s = open(p, encoding='utf-8').read()
s = s.replace('CURLOPT_FOLLOWLOCATION => false,', 'CURLOPT_FOLLOWLOCATION => true,')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Curl.php "Aussenverbindung folgt Umleitungen" &&
pruefe "Aussenverbindung folgt Umleitungen" \
  OutboundSourceTest::test_the_one_place_keeps_its_promises failed
wiederherstellen

echo
echo "── OutboundSourceTest: eine zweite Stelle spricht nach draussen ──"
#
# Genau der Fall, der beim Bauen des ersten DNS-Anbieters gedroht hat: eine
# zweite Umsetzung derselben vier Optionen. Die zweite ist die, in der eine
# davon irgendwann fehlt — und nichts meldet es.
vorher_datei agent/src/Acme/CurlTransport.php
python3 - <<'PY2'
p = 'agent/src/Acme/CurlTransport.php'
s = open(p, encoding='utf-8').read()
s = s.replace("    public function get(string $url): Response\n    {",
              "    public function get(string $url): Response\n    {\n        $handle = curl_init();")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/CurlTransport.php "Zweite Stelle mit curl" &&
pruefe "Zweite Stelle mit curl" \
  OutboundSourceTest::test_only_one_place_reaches_out failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OutboundSourceTest passed

echo
echo "── DnsProviderReachTest: ein Anbieterschlüssel im Formular zeigt ins Leere ──"
#
# Das Formular schaltet seine Felder am Anbieter um. Ein Tippfehler in der
# Zeichenkette zeigt nie ein Feld — und niemand sieht, dass etwas fehlt.
vorher_datei resources/js/Components/DnsCredentials.vue
python3 - <<'PY2'
p = 'resources/js/Components/DnsCredentials.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("const IPV64 = 'ipv64'", "const IPV64 = 'ipvierundsechzig'")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Components/DnsCredentials.vue "Anbieterschlüssel zeigt ins Leere" &&
pruefe "Anbieterschlüssel zeigt ins Leere" \
  DnsProviderReachTest::test_every_provider_key_in_the_form_exists failed
wiederherstellen

echo
echo "── DnsProviderReachTest: ein benutzbarer Anbieter ohne Formular ──"
#
# Er stünde im Auswahlfeld, und wer ihn wählte, bekäme nichts zum Ausfüllen —
# abgeschickt endete er in einer Abweisung, die von Feldern spricht, die
# niemand sieht.
vorher_datei resources/js/Components/DnsCredentials.vue
python3 - <<'PY2'
p = 'resources/js/Components/DnsCredentials.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('form.provider === IPV64', 'form.provider === RFC2136')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Components/DnsCredentials.vue "Benutzbarer Anbieter ohne Formular" &&
pruefe "Benutzbarer Anbieter ohne Formular" \
  DnsProviderReachTest::test_every_usable_provider_has_a_form failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DnsProviderReachTest passed

echo
echo "── Ipv64Test: die Zone wird gerechnet statt gefragt ──"
#
# Der Fehler, an dem sich dieser Anbieter entscheidet. Bei IPv64.net ist die
# Zone häufig selbst eine Unterdomain; wer sie aus dem Namen ableitet, legt den
# Eintrag in der falschen Zone an — und das ist kein Fehler, er wird nur nie
# gefunden.
vorher_datei agent/src/Acme/Dns/Ipv64.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Ipv64.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $zone = Zones::pick($record, $this->knownZones());",
    "        $zone = implode('.', array_slice(explode('.', strtolower(trim($record))), -2));",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Ipv64.php "Zone aus dem Namen gerechnet" &&
pruefe "Zone aus dem Namen gerechnet" \
  Ipv64Test::test_the_zone_comes_from_the_account_and_not_from_the_name failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" Ipv64Test passed

echo
echo "── ZoneSourceTest: eine zweite Stelle entscheidet über die Zone ──"
#
# Die Regel „die längste passende Zone gewinnt" stand vor Hetzner zweimal als
# eigene Schleife da. Eine dritte daneben nimmt irgendwann die erste passende
# statt der längsten — und legt den Eintrag eine Ebene zu hoch an, ohne dass
# irgendetwas es meldet.
vorher_datei agent/src/Acme/Dns/Hetzner.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Hetzner.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $zone = Zones::pick($record, $this->knownZones());",
    "        $zone = null;\n\n        foreach ($this->knownZones() as $candidate) {\n            if (Name::within($record, $candidate)) {\n                $zone = $candidate;\n            }\n        }",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Hetzner.php "zweite Stelle entscheidet über die Zone" &&
pruefe "zweite Stelle entscheidet über die Zone" \
  ZoneSourceTest::test_only_one_place_decides_which_zone_a_name_belongs_to failed
wiederherstellen

echo
echo "── ZonesTest: die erste passende Zone statt der längsten ──"
#
# Die Regel selbst. Führt jemand `example.de` und `kunde.example.de` beim
# selben Anbieter, gehört der Eintrag in die engere; wer die erste nimmt, legt
# ihn eine Ebene zu hoch an.
vorher_datei agent/src/Acme/Dns/Zones.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Zones.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            if (Name::within($record, $zone) && ($found === null || strlen($zone) > strlen($found))) {",
    "            if (Name::within($record, $zone) && $found === null) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Zones.php "erste passende Zone statt der längsten" &&
pruefe "erste passende Zone statt der längsten" \
  ZonesTest::test_the_longest_matching_zone_wins failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ZonesTest passed

echo
echo "── HetznerTest: der Auftrag gilt als erledigt, sobald geantwortet wird ──"
#
# Die Cloud-API antwortet mit einer Action, die auf `error` stehen kann. Wer
# nur auf den HTTP-Code sieht, hält den Fehlschlag für einen Erfolg — und
# wartet danach zwei Minuten auf einen Eintrag, den niemand mehr anlegt.
vorher_datei agent/src/Acme/Dns/Hetzner.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Hetzner.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (($action['status'] ?? null) !== 'error') {",
    "        if (true) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Hetzner.php "Auftrag gilt ungelesen als erledigt" &&
pruefe "Auftrag gilt ungelesen als erledigt" \
  HetznerTest::test_a_failed_action_says_why failed
wiederherstellen

echo
echo "── HetznerTest: die Blätterschleife hört still auf ──"
#
# **Was hier absichtlich nicht gebrochen wird.** Der Deckel selbst — die
# Bedingung, die die Runden zählt — lässt sich nicht automatisiert brechen:
# Die erste Fassung verglich die Seitennummer mit der Obergrenze, und ein
# `next_page`, das auf die laufende Seite zurückzeigt, hielt sie damit für
# immer erfüllt. Ein Bruch dieser Art hinge, statt rot zu werden, und ein
# hängender Lauf ist schlimmer als ein fehlender. Gefunden hat den Fehler eine
# Wegwerfprobe, die nicht zurückkam.
#
# Gebrochen wird die Hälfte, die still zurückfallen kann: das Melden. Wer hier
# einfach aufhört, sagt gleich darauf „für diesen Namen keine Zone" — und nennt
# damit einen Grund, der nicht stimmt.
vorher_datei agent/src/Acme/Dns/Hetzner.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Hetzner.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """                throw AgentException::execFailed(
                    'Die Zonenliste von Hetzner hört nach '.self::MAX_PAGES.' Seiten nicht auf.',
                );""",
    "                break;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Hetzner.php "Blätterschleife hört still auf" &&
pruefe "Blätterschleife hört still auf" \
  HetznerTest::test_a_pagination_that_points_in_circles_is_reported failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" HetznerTest passed

echo
echo "── TxtValueSourceTest: eine zweite Stelle setzt die Anführungszeichen ──"
#
# Ein TXT-Wert steht in Anführungszeichen (RFC 1035 §3.3.14), und Hetzner wie
# Cloudflare nehmen ihn so entgegen. Die zweite Fassung vergisst die Abweisung:
# den Wert mit einem Anführungszeichen darin, oder den zu langen, den der
# Anbieter dann stillschweigend in zwei character-strings teilt.
vorher_datei agent/src/Acme/Dns/Cloudflare.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Cloudflare.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "                    'content' => TxtValue::quoted($value),",
    "                    'content' => '\"'.$value.'\"',",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Cloudflare.php "zweite Stelle mit Anführungszeichen" &&
pruefe "zweite Stelle mit Anführungszeichen" \
  TxtValueSourceTest::test_only_one_place_wraps_a_value_in_quotes failed
wiederherstellen

echo
echo "── TxtValueTest: ein zu langer Wert geht durch ──"
#
# Anbieter teilen einen Wert über 255 Zeichen stillschweigend in zwei
# character-strings auf, und ein TXT-Satz aus zwei Teilen ist für die Prüfung
# der Zertifizierungsstelle ein anderer Wert.
vorher_datei agent/src/Acme/Dns/TxtValue.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/TxtValue.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if ($value === '' || strlen($value) > self::MAX_LENGTH) {",
    "        if ($value === '') {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/TxtValue.php "zu langer TXT-Wert geht durch" &&
pruefe "zu langer TXT-Wert geht durch" \
  TxtValueTest failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TxtValueTest passed

echo
echo "── CloudflareTest: nach dem Namen allein gelöscht ──"
#
# Laufen zwei Bestellungen für dieselbe Zone, stehen zwei
# `_acme-challenge`-Einträge unter demselben Namen. Wer den Wert beim Suchen
# nicht mit vergleicht, räumt die Prüfung des anderen Vorgangs mit ab — und der
# scheitert dann an einer Ursache, die nirgends steht.
vorher_datei agent/src/Acme/Dns/Cloudflare.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Cloudflare.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            if (is_string($id) && $id !== '' && is_string($content) && TxtValue::matches($content, $value)) {",
    "            if (is_string($id) && $id !== '') {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Cloudflare.php "nach dem Namen allein gelöscht" &&
pruefe "nach dem Namen allein gelöscht" \
  CloudflareTest::test_removing_deletes_only_the_matching_record failed
wiederherstellen

echo
echo "── CloudflareTest: nur der HTTP-Code gelesen ──"
#
# Cloudflare antwortet auf einen abgelehnten Vorgang durchaus mit 200 und
# `"success": false`. Wer nur den Code liest, hält das für erledigt und wartet
# danach zwei Minuten auf einen Eintrag, den es nicht gibt.
vorher_datei agent/src/Acme/Dns/Cloudflare.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Cloudflare.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (! $response->successful() || ($data['success'] ?? null) !== true) {",
    "        if (! $response->successful()) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Cloudflare.php "nur der HTTP-Code gelesen" &&
pruefe "nur der HTTP-Code gelesen" \
  CloudflareTest::test_success_false_counts_even_with_http_200 failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CloudflareTest passed

echo
echo "── CloudflareTest: der globale API-Schlüssel wird angenommen ──"
#
# Er öffnet das ganze Cloudflare-Konto — alle Zonen, alle Einstellungen, den
# Zugriffsschutz. Ein Formularfeld dafür ist keines, dessen Fehlen jemand
# vermisst; ihn stillschweigend fallenzulassen wäre genauso falsch, denn dann
# käme die Abweisung von Cloudflare, mit einem Satz ohne Grund.
vorher_datei agent/src/Acme/Dns/Cloudflare.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Cloudflare.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (isset($config['email']) && $config['email'] !== '') {",
    "        if (false) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Cloudflare.php "globaler API-Schlüssel angenommen" &&
pruefe "globaler API-Schlüssel angenommen" \
  CloudflareTest::test_an_account_address_is_refused failed
wiederherstellen

echo
echo "── NetcupTest: die Sitzung bleibt nach einem Fehlschlag offen ──"
#
# Abgemeldet wird im `finally` — sonst bliebe eine Sitzung bei einem fremden
# Anbieter genau dann liegen, wenn der Zugriff dazwischen scheitert. Das ist der
# Fall, der sich häuft: Jeder Fehlversuch einer Bestellung liesse eine zurück.
vorher_datei agent/src/Acme/Dns/Netcup.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Netcup.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """        try {
            $work($session);
        } finally {
            try {""",
    """        $work($session);

        if (true) {
            try {""",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Netcup.php "Sitzung bleibt nach einem Fehlschlag offen" &&
pruefe "Sitzung bleibt nach einem Fehlschlag offen" \
  NetcupTest::test_the_session_is_closed_after_a_failure failed
wiederherstellen

echo
echo "── NetcupTest: ein gescheitertes Abmelden wird zum Fehlschlag ──"
#
# Die Gegenrichtung. Wer den Fehler des Abmeldens durchreicht, macht aus einem
# gesetzten Eintrag einen Fehlschlag — und der Vorgang wird wiederholt, obwohl
# er durchgelaufen ist. Bei Let's Encrypt zählt jeder Fehlversuch für alle
# Kunden dieses Servers.
vorher_datei agent/src/Acme/Dns/Netcup.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Netcup.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """            try {
                $this->call('logout', ['apisessionid' => $session], 'Das Abmelden bei netcup ist gescheitert');
            } catch (AgentException) {
                // Siehe oben: Die Sitzung läuft bei netcup ohnehin ab.
            }""",
    "            $this->call('logout', ['apisessionid' => $session], 'Das Abmelden bei netcup ist gescheitert');",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Netcup.php "gescheitertes Abmelden wird zum Fehlschlag" &&
pruefe "gescheitertes Abmelden wird zum Fehlschlag" \
  NetcupTest::test_a_failed_logout_does_not_fail_the_operation failed
wiederherstellen

echo
echo "── NetcupTest: die ganze Zone wird zurückgeschrieben ──"
#
# lego liest an dieser Stelle alle Einträge, hängt den neuen an und schickt
# alles zurück. Für ein Panel, das fremde Zonen anfasst, ist das der teure Weg:
# Geht beim Lesen etwas schief oder ändert jemand dazwischen etwas, steht der
# Bestand eines Kunden auf dem Spiel.
vorher_datei agent/src/Acme/Dns/Netcup.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Netcup.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """            $this->call('updateDnsRecords', [
                'domainname' => $zone,
                'apisessionid' => $session,
                'dnsrecordset' => ['dnsrecords' => [[
                    'hostname' => $host,""",
    """            $this->call('infoDnsRecords', [
                'domainname' => $zone,
                'apisessionid' => $session,
            ], 'Die Einträge von netcup ließen sich nicht abfragen');

            $this->call('updateDnsRecords', [
                'domainname' => $zone,
                'apisessionid' => $session,
                'dnsrecordset' => ['dnsrecords' => [[
                    'hostname' => $host,""",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Netcup.php "ganze Zone wird gelesen" &&
pruefe "ganze Zone wird gelesen" \
  NetcupTest::test_only_the_one_record_is_written failed
wiederherstellen

echo
echo "── NetcupTest: beim Löschen zählt nur der Wert ──"
#
# Stehen zwei Prüfeinträge mit demselben Wert unter verschiedenen Namen, ist
# das der falsche Satz — genau so vergleicht lego, und genau so löscht man die
# Prüfung eines anderen Vorgangs mit ab.
#
# **Dieser Bruch lief am 10. August 2026 grün durch, und schuld war das
# Beispiel im Test.** Der fremde Name stand dort hinter dem eigenen, und
# `find()` gibt den ersten Treffer zurück — ohne Namensabgleich kam derselbe
# Satz heraus. Behoben ist es im Test, nicht hier: Das Beispiel führt den
# fremden Namen jetzt zuerst.
vorher_datei agent/src/Acme/Dns/Netcup.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Netcup.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "                && strtolower($entry['hostname']) === $host\n",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Netcup.php "beim Löschen zählt nur der Wert" &&
pruefe "beim Löschen zählt nur der Wert" \
  NetcupTest::test_removing_matches_name_and_value failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" NetcupTest passed

echo
echo "── IonosTest: der halbe Schlüssel geht durch ──"
#
# Der Schlüssel von IONOS besteht aus Präfix und Geheimnis, verbunden mit einem
# Punkt; IONOS zeigt beide getrennt an, und der Präfix steht obenan. Wer nur ihn
# einträgt, bekommt ohne diese Prüfung erst nachts bei einer Erneuerung eine
# Abweisung — mit einem Satz, der von einem ungültigen Schlüssel spricht.
vorher_datei agent/src/Acme/Dns/Ionos.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Ionos.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {",
    "        if (false) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Ionos.php "halber IONOS-Schlüssel geht durch" &&
pruefe "halber IONOS-Schlüssel geht durch" \
  IonosTest::test_a_key_that_is_not_two_parts_is_refused failed
wiederherstellen

echo
echo "── IonosTest: der Filter wird für einen Namen gehalten ──"
#
# `suffix` von IONOS ist ein Suffix. Ohne den zweiten Abgleich wandert
# `x._acme-challenge.example.de` in die Liste, die zurückgeschickt wird — und
# beim Löschen in die Auswahl.
vorher_datei agent/src/Acme/Dns/Ionos.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Ionos.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            if (is_array($entry) && is_string($entry['name'] ?? null) && strtolower($entry['name']) === $name) {",
    "            if (is_array($entry)) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Ionos.php "IONOS-Filter als Name gelesen" &&
pruefe "IONOS-Filter als Name gelesen" \
  IonosTest::test_a_name_that_merely_ends_the_same_is_dropped failed
wiederherstellen

echo
echo "── IonosTest: nichts zu löschen gilt als Fehlschlag ──"
#
# lego wirft an dieser Stelle. `cleanup()` läuft aber auch nach einer
# gescheiterten Bestellung, und dann gibt es den Eintrag gar nicht — aus einem
# Fehlschlag würden zwei.
vorher_datei agent/src/Acme/Dns/Ionos.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Ionos.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """            if (! is_string($id) || $id === '' || ! is_string($content) || ! TxtValue::matches($content, $value)) {
                continue;
            }""",
    """            if (! is_string($id) || $id === '') {
                continue;
            }""",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Ionos.php "IONOS löscht nach dem Namen allein" &&
pruefe "IONOS löscht nach dem Namen allein" \
  IonosTest::test_removing_deletes_only_the_matching_record failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" IonosTest passed

echo
echo "── DesecTest: der RRset wird überschrieben statt ergänzt ──"
#
# deSEC führt RRsets: Alle TXT-Werte zu einem Namen sind ein Gegenstand mit
# einer Liste. Wer beim Anlegen die Liste ersetzt, nimmt einer gleichzeitig
# laufenden Bestellung ihre Prüfung weg — und die scheitert dann an einer
# Ursache, die nirgends steht.
vorher_datei agent/src/Acme/Dns/Desec.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Desec.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $this->patch($domain, $subname, [...$existing, $quoted], $record);",
    "        $this->patch($domain, $subname, [$quoted], $record);",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Desec.php "deSEC-RRset überschrieben" &&
pruefe "deSEC-RRset überschrieben" \
  DesecTest::test_an_existing_rrset_gets_the_value_appended failed
wiederherstellen

echo
echo "── DesecTest: beim Abräumen wird die ganze Liste geleert ──"
#
# Dieselbe Grenze aus der anderen Richtung. Die Liste zu leeren ist am Ende
# einer einzelnen Bestellung richtig und bei zwei gleichzeitigen falsch.
vorher_datei agent/src/Acme/Dns/Desec.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Desec.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            if (! TxtValue::matches($entry, $value)) {\n                $left[] = $entry;\n            }",
    "            unset($entry);",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Desec.php "deSEC-Liste geleert" &&
pruefe "deSEC-Liste geleert" \
  DesecTest::test_removing_takes_out_only_the_own_value failed
wiederherstellen

echo
echo "── DesecTest: 204 gilt als Fehlschlag ──"
#
# Nimmt man den letzten Wert heraus, verschwindet der RRset, und deSEC quittiert
# das mit 204. Wer nur 200 gelten lässt, macht aus dem Normalfall am Ende jeder
# Bestellung einen Fehlschlag — und der Vorgang wird wiederholt.
#
# Gebrochen wird `Response::successful()` und nicht der Anbieter: Die Regel
# „2xx ist Erfolg" steht dort für alle, und deSEC ist der erste, bei dem sie
# einen anderen Code als 200 tragen muss.
vorher_datei agent/src/Acme/Response.php
python3 - <<'PY2'
p = 'agent/src/Acme/Response.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return $this->status >= 200 && $this->status < 300;",
    "        return $this->status === 200 || $this->status === 201;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Response.php "204 gilt als Fehlschlag" &&
pruefe "204 gilt als Fehlschlag" \
  DesecTest::test_emptying_the_rrset_is_a_success failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DesecTest passed

echo
echo "── XmlRpcTest: der Parser darf Entitäten auflösen ──"
#
# Eine Antwort mit einer externen Entität holt sonst eine Datei vom Rechner —
# in einem Prozess, der als root läuft. Gemessen: Mit LIBXML_NOENT steht der
# Inhalt von /etc/hostname im Wert, mit den Marken der Klasse nicht.
#
# **Erwartet wird der Wächter, der die Marken liest, und nicht der, der die
# Wirkung misst.** Am 10. August 2026 lief dieser Bruch in der CI grün durch
# und im Container rot — der Unterschied ist die Fassung von libxml: 2.9.14
# holt die Datei auch heute, die neueren Fassungen der CI lassen externe
# Entitäten gar nicht erst zu. Der Fall, der die Wirkung misst, bleibt stehen
# (Debian 12 liefert 2.9.14, und dort gilt die Gefahr), taugt hier aber nicht:
# Ein Bruch, dessen Befund an der Maschine hängt, misst die Maschine.
vorher_datei agent/src/Acme/Dns/XmlRpc.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/XmlRpc.php'
s = open(p, encoding='utf-8').read()
s = s.replace("LIBXML_NONET | LIBXML_NOCDATA", "LIBXML_NOENT")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/XmlRpc.php "Parser löst Entitäten auf" &&
pruefe "Parser löst Entitäten auf" \
  XmlRpcTest::test_the_parser_is_told_to_leave_entities_alone failed
wiederherstellen

echo
echo "── XmlRpcTest: die Verschachtelung ist nicht gedeckelt ──"
#
# Eine Antwort, die sich tausendfach ineinander schachtelt, ist keine Antwort,
# sondern ein Weg, den Speicher dieses Prozesses zu füllen.
vorher_datei agent/src/Acme/Dns/XmlRpc.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/XmlRpc.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        if ($depth > self::MAX_DEPTH) {", "        if (false) {")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/XmlRpc.php "Verschachtelung nicht gedeckelt" &&
pruefe "Verschachtelung nicht gedeckelt" \
  XmlRpcTest::test_a_response_that_nests_too_deeply_is_refused failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" XmlRpcTest passed

echo
echo "── InwxTest: je Aufruf angemeldet statt je Bestellung ──"
#
# INWX nimmt denselben TAN kein zweites Mal. Zwei Anmeldungen im selben
# Zeitschritt hätten denselben — und die zweite würde abgewiesen. Anlegen und
# Abräumen teilen sich deshalb eine Sitzung.
vorher_datei agent/src/Acme/Dns/Inwx.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Inwx.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """        if ($this->session !== null) {
            return;
        }

        $response = $this->post(XmlRpc::request('account.login', [""",
    """        $response = $this->post(XmlRpc::request('account.login', [""",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Inwx.php "je Aufruf angemeldet" &&
pruefe "je Aufruf angemeldet" \
  InwxTest::test_one_login_carries_the_whole_order failed
wiederherstellen

echo
echo "── InwxTest: beim Löschen zählt nur der Wert ──"
#
# Der Fehler, den beim Bauen die Wegwerfprobe gefunden hat: Der Kommentar
# versprach den Namensabgleich, der Code machte ihn nicht. Gefiltert wurde
# allein über den Parameter, den INWX bekommt — und was ein Anbieter als Filter
# versteht, ist seine Sache; was gelöscht wird, ist unsere.
vorher_datei agent/src/Acme/Dns/Inwx.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Inwx.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                && strtolower($found) === $full\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Inwx.php "beim Löschen zählt nur der Wert" &&
pruefe "beim Löschen zählt nur der Wert" \
  InwxTest::test_removing_matches_the_name_as_well failed
wiederherstellen

echo
echo "── InwxTest: die abgeschnittene Zonenliste wird verschwiegen ──"
#
# Hat ein Konto mehr Zonen, als eine Seite trägt, fehlte still die gesuchte —
# und die Meldung spräche von einem Namen ausserhalb aller Zonen, also von
# einem Grund, der nicht stimmt.
vorher_datei agent/src/Acme/Dns/Inwx.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Inwx.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        if (is_int($count) && $count > count($zones)) {", "        if (false) {")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Inwx.php "abgeschnittene Zonenliste verschwiegen" &&
pruefe "abgeschnittene Zonenliste verschwiegen" \
  InwxTest::test_a_truncated_zone_list_is_reported failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" InwxTest passed

echo
echo "── ProvidersTest: ein Zurückgehaltener wird stumm abgewiesen ──"
#
# Seit dem 7. August gibt es zwei Gründe, warum ein Anbieter fehlt: „noch nicht
# gebaut" und „gebaut, aber nicht angeboten". Eine Abweisung ohne Grund lässt
# den Betreiber im zweiten Fall auf etwas warten, das nicht kommt.
vorher_datei agent/src/Acme/Dns/Providers.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Providers.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        self::INWX => 'Die Zugangsdaten sind dort Benutzername und Passwort des Registrarkontos '.\n            'und nicht ein Token für eine Zone.',",
    "        self::INWX => '',",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Providers.php "Zurückgehaltener ohne Grund" &&
pruefe "Zurückgehaltener ohne Grund" \
  ProvidersTest::test_every_provider_key_points_at_something failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ProvidersTest passed

echo
echo "── PatienceTest: eine Frist für alle Anbieter ──"
#
# Der Zustand bis zum 7. August: 120 Sekunden für jede Bestellung. Das ist
# kürzer, als lego für netcup und IONOS für nötig hält (900) und für INWX (360)
# — und eine Bestellung, die zu früh aufgibt, verbrennt einen der fünf
# Fehlversuche je Konto und Stunde, die für jeden Kunden dieses Servers gelten.
vorher_datei agent/src/Acme/Dns/Netcup.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Netcup.php'
s = open(p, encoding='utf-8').read()
s = s.replace("    public const PATIENCE_SECONDS = 900;", "    public const PATIENCE_SECONDS = 120;")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Netcup.php "eine Frist für alle Anbieter" &&
pruefe "eine Frist für alle Anbieter" \
  PatienceTest::test_every_provider_names_its_own_patience failed
wiederherstellen

echo
echo "── PatienceTest: ein Anbieter fehlt in der Liste ──"
#
# Ohne die Gegenrichtung bekäme ein neunter Anbieter seine Zahl nie geprüft: Der
# Wächter liefe über acht Einträge und meldete Grün.
vorher_datei agent/src/Acme/Dns/Providers.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Providers.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        self::DESEC => 'deSEC',\n",
    "        self::DESEC => 'deSEC',\n        'erfunden' => 'Erfunden',\n",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Providers.php "Anbieter fehlt in der Geduldsliste" &&
pruefe "Anbieter fehlt in der Geduldsliste" \
  PatienceTest::test_every_built_provider_is_listed failed
wiederherstellen

echo
echo "── PatienceTest: die Prüfung reicht die Frist nicht durch ──"
#
# Eine Zahl, die im Anbieter steht und bei der Prüfung nicht ankommt, ist keine.
#
# **Was hier absichtlich nicht gebrochen wird:** die Stelle in `Order`, die
# `patience()` fragt. Ein Bruch dort bliebe unbemerkt, weil der einzige Test,
# der eine Bestellung fährt, HTTP-01 benutzt — und dort liegt die Prüfdatei
# sofort, `awaitReady` wartet also nie. Ein Bruch, der nichts rot macht, sieht
# aus wie ein Wächter und ist keiner; er gehört benannt statt geschrieben.
vorher_datei agent/src/Acme/DnsChallenge.php
python3 - <<'PY2'
p = 'agent/src/Acme/DnsChallenge.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return $this->provider->patience();",
    "        return new Patience(120, 2);",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/DnsChallenge.php "Frist nicht durchgereicht" &&
pruefe "Frist nicht durchgereicht" \
  PatienceTest::test_every_provider_names_its_own_patience failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PatienceTest passed

echo
echo "── CertificateChoiceTest: der Rückfall bleibt still ──"
#
# Der laute Rückfall (`docs/34 §8`): Ein Block, der die Wahl übergeht, gehört
# ins Prüfprotokoll. Ohne den Eintrag bleibt nur der Hinweis auf der
# Domainseite — und der beantwortet nicht, seit wann.
vorher_datei app/Support/Web/WebLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Web/WebLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $this->recordOverride($domain);\n",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Web/WebLifecycle.php "übergangene Wahl nicht protokolliert" &&
pruefe "übergangene Wahl nicht protokolliert" \
  CertificateChoiceTest::test_an_overridden_choice_lands_in_the_audit_trail failed
wiederherstellen

echo
echo "── CertificateChoiceTest: der Rückfall meldet auch, wenn nichts war ──"
#
# **Die wichtigere Richtung.** Der naheliegende Fehler ist nicht der fehlende
# Eintrag, sondern der bei jedem angewandten Block — eine Meldung, die immer
# kommt, bedeutet nichts, und die Automatik hängt eine Domain regelmässig um,
# ohne dass jemand übergangen wird.
vorher_datei app/Support/Web/WebLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Web/WebLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (! $this->choice->overridden($domain)) {\n            return;\n        }\n",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Web/WebLifecycle.php "jeder Block meldet einen Rückfall" &&
pruefe "jeder Block meldet einen Rückfall" \
  CertificateChoiceTest::test_a_valid_choice_leaves_the_audit_trail_alone failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateChoiceTest passed

echo
echo "── WildcardOrderTest: Vorhandensein statt Deckung ──"
#
# **Der Fund vom Zielserver, 7. August 2026.** Das Kästchen „Als Platzhalter
# bestellen" hing an „es gibt noch kein Zertifikat". Die Automatik bestellt
# aber, sobald der Server-Block steht — die Seite stand also mit einem
# gültigen Einzelzertifikat da und bot den Platzhalter nie an. Der Bruch stellt
# genau diese Frage wieder her.
vorher_datei app/Support/Tls/WildcardOrder.php
python3 - <<'PY2'
p = 'app/Support/Tls/WildcardOrder.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return self::isBase($domain) && $this->choice->covers($domain, self::names($domain));",
    "        return self::isBase($domain) && $this->choice->effective($domain) !== null;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/WildcardOrder.php "Platzhalter gilt als gedeckt, sobald irgendetwas liegt" &&
pruefe "Platzhalter gilt als gedeckt, sobald irgendetwas liegt" \
  WildcardOrderTest::test_a_certificate_for_the_name_alone_is_not_a_wildcard failed
wiederherstellen

echo
echo "── WildcardOrderTest: ein abgelaufener Platzhalter zählt mit ──"
#
# Die Gegenrichtung: Wer die Laufzeit nicht prüft, lässt eine Domain mit
# abgelaufenem Platzhalter ohne Angebot stehen — und genau dann braucht sie eines.
vorher_datei app/Support/Tls/CertificateChoice.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateChoice.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return $this->best($this->owned($domain), $names) instanceof Certificate;",
    "        foreach ($this->owned($domain) as $c) { if ($c->coversAll($names)) { return true; } }\n\n        return false;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateChoice.php "Laufzeit bei der Deckung nicht gefragt" &&
pruefe "Laufzeit bei der Deckung nicht gefragt" \
  WildcardOrderTest::test_an_expired_wildcard_does_not_count failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" WildcardOrderTest passed

echo
echo "── TableStyleTest: eine Kennung bleibt auf dem Schreibtisch unteilbar ──"
#
# **Der Befund vom Zielserver, 7. August 2026.** Auf der Domainseite lief
# /var/www/vhosts/cloudlab24.ipv64.de/logs/… aus dem Bereich „Stammdaten" heraus
# und legte sich über den Nachbarbereich — 173px bei 1440px Fensterbreite. Der
# Seitenüberlauf war dabei 0: Die Seite rollt nicht, sie überlappt.
vorher
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "table.pairs td.ident {\n  white-space: normal;\n  overflow-wrap: anywhere;\n}",
    "table.pairs td.ident {\n  white-space: nowrap;\n}",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff "Kennung in der Bezeichnungstabelle wieder unteilbar" &&
pruefe "Kennung in der Bezeichnungstabelle wieder unteilbar" \
  TableStyleTest::test_an_identifier_in_a_pairs_table_may_break_on_the_desktop failed
wiederherstellen

echo
echo "── TableStyleTest: die Ausnahme nur im Haltepunkt ──"
#
# **Die zweite Richtung, und sie ist die Geschichte dieses Fundes.** Genau diese
# Ausnahme gab es schon einmal — im @media-Block für 390px, für den einen Ort,
# an dem der Überlauf auffiel, statt für die Regel. Eine Regel, die es nur dort
# gibt, wirkt genau dort nicht, wo dieser Fund entstanden ist.
vorher
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "table.pairs td.ident {\n  white-space: normal;\n  overflow-wrap: anywhere;\n}\n\n",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff "Ausnahme nur im Haltepunkt" &&
pruefe "Ausnahme nur im Haltepunkt" \
  TableStyleTest::test_an_identifier_in_a_pairs_table_may_break_on_the_desktop failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TableStyleTest passed

echo
echo "── DnsProviderReachTest: das Kommando kennt einen Anbieter nicht ──"
#
# **Der Fund vom 7. August 2026.** `srvpanel dns` baute die Angaben selbst
# zusammen — und zwar nur die von RFC 2136, weil das beim Schreiben der einzige
# Anbieter war. Schritt 9 hat sieben gebaut, das Formular verzweigt seither an
# ihnen, und in der Hilfe stand weiter „heute geht nur rfc2136". Der Bruch nimmt
# die Angabe wieder weg, die vier Anbieter brauchen.
vorher_datei app/Console/Commands/DnsCredentials.php
python3 - <<'PY2'
p = 'app/Console/Commands/DnsCredentials.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        {--token= : Das Token; ohne diese Angabe wird gefragt (IPv64.net, Hetzner, Cloudflare, deSEC)}\n",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/DnsCredentials.php "Anbieter ohne Angabe auf der Kommandozeile" &&
pruefe "Anbieter ohne Angabe auf der Kommandozeile" \
  DnsProviderReachTest::test_every_usable_provider_can_be_set_from_the_command_line failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DnsProviderReachTest passed

echo
echo "── CertificateReapplyTest: nur der Besteller bekommt seinen Block ──"
#
# **Der Fund aus dem Abnahmelauf, 7. August 2026.** Auf cloudlab24.ipv64.de war
# der Platzhalter ausgestellt, die Hauptdomain lieferte ihn aus — die drei
# Unterdomains behielten ihre einzelnen Zertifikate. CertificateChoice
# antwortete für sie längst richtig; nur fragte niemand, weil install() genau
# eine Domain anwandte.
vorher_datei app/Support/Tls/CertificateLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $this->spread($domain, $operation, $vorher);\n",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateLifecycle.php "Nachbarblöcke bleiben stehen" &&
pruefe "Nachbarblöcke bleiben stehen" \
  CertificateReapplyTest::test_a_wildcard_reaches_every_block_of_the_subscription failed
wiederherstellen

echo
echo "── CertificateReapplyTest: jede Erneuerung schreibt alle Blöcke neu ──"
#
# **Die Gegenrichtung, und sie ist die teurere.** Verglichen wird der Ablageort
# und nicht die Kennung: Eine Erneuerung legt eine neue Zeile an — andere
# Kennung, derselbe Ablageort. Wer über die Kennung vergleicht, reiht bei einem
# Abonnement mit vierzig Domains alle sechzig Tage vierzig Vorgänge ein.
vorher_datei app/Support/Tls/CertificateLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            $orte[(int) $nachbar->id] = $this->choice->effective($nachbar)?->storage_name;",
    "            $orte[(int) $nachbar->id] = (string) $this->choice->effective($nachbar)?->id;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateLifecycle.php "Kennung statt Ablageort verglichen" &&
pruefe "Kennung statt Ablageort verglichen" \
  CertificateReapplyTest::test_a_renewal_leaves_the_neighbours_alone failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateReapplyTest passed

echo
echo "── FormLabelTest: ein Auswahlfeld ohne sichtbare Beschriftung ──"
#
# **Vom Betreiber gemeldet, 7. August 2026.** Auf der Domainliste stand neben
# „Domain anlegen" eine Auswahl, in *welches* Abonnement die neue Domain kommt —
# mit `aria-label` und sonst nichts. Wer sie übersieht, legt die Domain im
# falschen Abonnement an, mit eigenem Verzeichnisbaum und eigenem Systembenutzer.
vorher_datei resources/js/Pages/Domains/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Domains/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '        <label class="field inline">\n          <span>Abonnement</span>\n',
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Domains/Index.vue "Auswahlfeld ohne Beschriftung" &&
pruefe "Auswahlfeld ohne Beschriftung" \
  FormLabelTest::test_every_select_sits_in_a_label failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FormLabelTest passed

echo
echo "── DomainCertificateTest: die Auskunft hängt wieder an der Absicht ──"
#
# Der Satz „eine Ebene tiefer deckt ein Platzhalter nicht" hing allein am
# Kästchen. Sobald der Platzhalter ausgestellt war, verschwand das Kästchen — und
# mit ihm die Auskunft, genau dann, wenn sie keine Vorhersage mehr ist.
vorher_datei resources/js/Pages/Domains/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Domains/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    'v-if="(alsPlatzhalter || props.wildcard.covered) && props.wildcard.uncovered.length > 0"',
    'v-if="alsPlatzhalter && props.wildcard.uncovered.length > 0"',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Domains/Show.vue "ungedeckte Namen nur bei der Absicht" &&
pruefe "ungedeckte Namen nur bei der Absicht" \
  DomainCertificateTest::test_the_uncovered_names_do_not_depend_on_the_checkbox_alone failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DomainCertificateTest passed

echo
echo "── CertificateRenewalTest: ein Platzhalter wird gewöhnlich erneuert ──"
#
# **Der teuerste Fund des Abnahmelaufs, 7. August 2026.** Die Erneuerung meldete
# „1 fällig, 1 bestellt" — die Zahl stimmte, das Bestellte nicht: Das neue
# Zertifikat trug nur den Namen der Hauptdomain, und die drei Unterdomains
# lieferten weiter das alte aus. Aufgefallen wäre es in neunzig Tagen, wenn das
# alte abläuft und der Browser bei jeder Unterdomain warnt.
vorher_datei app/Support/Tls/CertificateRenewal.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateRenewal.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            if ($this->order->place($domain, wildcard: $wildcard) === null) {",
    "            if ($this->order->place($domain) === null) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateRenewal.php "Platzhalter als gewöhnliches erneuert" &&
pruefe "Platzhalter als gewöhnliches erneuert" \
  CertificateRenewalTest::test_a_wildcard_is_renewed_as_a_wildcard failed
wiederherstellen

echo
echo "── CertificateRenewalTest: ohne Zugangsdaten still schrumpfen ──"
#
# Der naheliegende Ausweg, wenn die Zugangsdaten fort sind: den Platzhalter als
# gewöhnliches Zertifikat nachholen. Genau das ist der stille Rückschritt —
# danach warnt der Browser bei jeder Unterdomain, und im Panel sieht alles grün
# aus.
vorher_datei app/Support/Tls/CertificateRenewal.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateRenewal.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            if ($wildcard && ! $this->wildcards->possible($domain)) {\n                $blocked++;\n\n                continue;\n            }\n\n",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateRenewal.php "ohne Zugangsdaten trotzdem bestellt" &&
pruefe "ohne Zugangsdaten trotzdem bestellt" \
  CertificateRenewalTest::test_a_wildcard_without_credentials_is_not_renewed_as_a_plain_one failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateRenewalTest passed

echo
echo "── CertificateUploadCommandTest: zwei Meldungen für eine Ursache ──"
#
# **Aus dem Abnahmelauf, 7. August 2026.** Ein unlesbarer Schlüssel brachte zwei
# Sätze: den richtigen („nicht lesbar") und darunter „Es fehlt eine Angabe" —
# und der zweite ist der, den man glaubt. Er schickt zurück an die
# Kommandozeile, wo alles stimmt, statt zu den Dateirechten.
vorher_datei app/Console/Commands/EnsureTls.php
python3 - <<'PY2'
p = 'app/Console/Commands/EnsureTls.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        // `contents()` hat schon gesagt, woran es liegt.\n        if (! is_string($name) || $chain === null || $key === null) {\n            return self::FAILURE;\n        }",
    "        if (! is_string($name) || $chain === null || $key === null) {\n            $this->error('Es fehlt: eine Angabe.');\n\n            return self::FAILURE;\n        }",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/EnsureTls.php "Datei und Angabe in einem Topf" &&
pruefe "Datei und Angabe in einem Topf" \
  CertificateUploadCommandTest::test_a_missing_file_is_named_and_no_option_is_blamed failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateUploadCommandTest passed

echo
echo "── CertificateUploadTest: die verkehrte Kette meldet den Schlüssel ──"
#
# **Aus dem Abnahmelauf, 7. August 2026.** Eine verkehrt sortierte Kette wurde
# abgewiesen — mit „Der Schlüssel gehört nicht zu diesem Zertifikat". Der Satz
# ist buchstäblich wahr: Steht das ausstellende Zertifikat vorn, ist es „dieses
# Zertifikat", und der Schlüssel des Blattes passt nicht dazu. Er ist trotzdem
# die falsche Auskunft — sie schickt zum Schlüssel statt zur Datei.
vorher_datei agent/src/Acme/Bundle.php
python3 - <<'PY2'
p = 'agent/src/Acme/Bundle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        self::ordered($certificates);\n        self::keyBelongs($certificates[0], $key);",
    "        self::keyBelongs($certificates[0], $key);\n        self::ordered($certificates);",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Bundle.php "Schlüssel vor Reihenfolge geprüft" &&
pruefe "Schlüssel vor Reihenfolge geprüft" \
  CertificateUploadTest::test_a_wrong_order_is_named_even_with_the_leaf_key failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateUploadTest passed

echo
echo "── SectionSpacingTest: eine Seite verliert ihren Behälter ──"
#
# Ein Bereich hat in Kontor keinen eigenen Aussenabstand — der kommt aus dem
# `gap` des Behälters. Fällt der weg, stehen zwei Bereiche auf 0px aufeinander,
# und jede einzelne Regel stimmt weiter.
vorher_datei resources/js/Pages/Domains/Show.vue
sed -i '0,/class="sections"/s//class="unwrapped"/' resources/js/Pages/Domains/Show.vue
griff_datei resources/js/Pages/Domains/Show.vue "Bereiche ohne Behälter" &&
pruefe "Bereiche ohne Behälter" \
  SectionSpacingTest::test_every_section_stands_in_a_container_that_carries_the_gap failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SectionSpacingTest::test_every_section_stands_in_a_container_that_carries_the_gap passed

echo
echo "── SectionSpacingTest: die Komponente steht ohne Klammer ──"
#
# **Das ist der gemeldete Fehler selbst, 7. August 2026.** `DnsCredentials`
# bringt zwei Bereiche mit und keinen Behälter; am Abonnement stand die Klammer,
# auf „DNS-Zugang" fehlte sie. Dieser Bruch stellt genau den Stand her, der
# ausgeliefert war.
vorher_datei resources/js/Pages/Settings/Dns.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Settings/Dns.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('    <div class="sections">\n', '').replace('      />\n    </div>', '      />')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Settings/Dns.vue "Trägerkomponente ohne Behälter" &&
pruefe "Trägerkomponente ohne Behälter" \
  SectionSpacingTest::test_every_component_that_brings_sections_is_wrapped_where_it_is_used failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SectionSpacingTest passed

echo
echo "── SectionSpacingTest: der Behälter trägt den Abstand nicht mehr ──"
#
# Ohne diese Richtung wäre `CONTAINERS` eine Liste von Klassennamen, die
# behauptet, dass dort ein `gap` steht — und die beiden Prüfungen darüber
# blieben grün, während jeder Bereich seinen Abstand verliert.
vorher
sed -i '/^\.sections {$/,/^}$/s/^  gap: var(--bereich-gap);$/  gap: 0;/' resources/css/app.css
griff ".sections ohne gap" &&
pruefe ".sections ohne gap" \
  SectionSpacingTest::test_a_container_is_a_container_because_app_css_says_so failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SectionSpacingTest passed

echo
echo "── RestrictedDeleteTest: Subscription trägt wieder SoftDeletes ──"
#
# **Die Richtung, die der Umbau fast unbewacht gelassen hätte.** Seit docs/35
# filtert `Subscription` nur noch über die Mandantenklammer; der Zweig für die
# Grabsteine im Wächter wird von keinem Modell mehr ausgelöst. Ein Zweig, den
# nichts erreicht, ist kein Wächter. Kommt der Trait zurück, muss `destroy()`
# wieder ein `withTrashed()` zeigen — und tut es nicht.
vorher_datei app/Models/Subscription.php
python3 - <<'PY2'
p = 'app/Models/Subscription.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "use Illuminate\\Database\\Eloquent\\Relations\\HasOne;",
    "use Illuminate\\Database\\Eloquent\\Relations\\HasOne;\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;",
)
s = s.replace(
    "    /** @var list<string> */\n    protected $fillable = [\n        'customer_id', 'plan_id', 'name', 'system_user',",
    "    use SoftDeletes;\n\n    /** @var list<string> */\n    protected $fillable = [\n        'customer_id', 'plan_id', 'name', 'system_user',",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Models/Subscription.php "Subscription filtert wieder weich" &&
pruefe "Subscription filtert wieder weich" \
  RestrictedDeleteTest::test_a_destroy_counts_what_the_foreign_key_counts failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RestrictedDeleteTest passed

echo
echo "── RestrictedDeleteTest: die Mandantenklammer bleibt zu ──"
#
# Der zweite Filter, und er ist der stillere: Ein Kommando ohne gesetzten
# Mandanten sieht überhaupt kein Abonnement und hielte jeden Plan für löschbar.
vorher_datei app/Http/Controllers/PlanController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/PlanController.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $bound = $tenancy->withoutRestriction(static fn (): int => $plan->subscriptions()->count());\n\n        if ($bound > 0) {",
    "        $bound = $plan->subscriptions()->count();\n\n        if ($bound > 0) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/PlanController.php "Klammer bleibt beim Zählen zu" &&
pruefe "Klammer bleibt beim Zählen zu" \
  RestrictedDeleteTest::test_a_destroy_counts_what_the_foreign_key_counts failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RestrictedDeleteTest passed

echo
echo "── SystemUserLedgerTest: die Vergabe fragt wieder die Abonnements ──"
#
# **Der Kern des Umbaus aus docs/35.** Ein Test, der nur das Verhalten prüft,
# bliebe grün, wenn jemand später „zur Sicherheit" wieder `Subscription`
# dazunimmt — und dann zählt eine Quelle mit, die leer laufen kann: Die
# Mandantenklammer liegt auf dem Modell, und ohne gesetzten Mandanten sähe die
# Vergabe kein einziges Abonnement.
vorher_datei app/Support/Subscriptions/Lifecycle.php
python3 - <<'PY2'
p = 'app/Support/Subscriptions/Lifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return $this->name(max(self::FIRST_USER, ((int) SystemUser::query()->max('number')) + 1));",
    "        return $this->name(max(self::FIRST_USER, ((int) Subscription::query()->max('id')) + 1));",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Subscriptions/Lifecycle.php "Vergabe liest die Abonnements" &&
pruefe "Vergabe liest die Abonnements" \
  SystemUserLedgerTest::test_the_allocation_reads_only_the_ledger failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SystemUserLedgerTest passed

echo
echo "── SystemUserLedgerTest: claim() schreibt nichts ins Verzeichnis ──"
#
# Ohne die Zeile im Verzeichnis ist der Name nicht verbraucht: Der Rückbau
# nimmt die Zeile des Abonnements mit, und der nächste Kunde bekommt `p1000`
# ein zweites Mal — samt allem, was auf dem Dateisystem noch dieser UID gehört.
vorher_datei app/Support/Subscriptions/Lifecycle.php
python3 - <<'PY2'
p = 'app/Support/Subscriptions/Lifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """                SystemUser::query()->create([
                    'number' => $number,
                    'subscription' => $subscription,
                    'db_prefix' => Names::newPrefix(),
                    'claimed_at' => now(),
                ]);

                return $this->name($number);""",
    """                return $this->name($number);""",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Subscriptions/Lifecycle.php "claim() verbraucht nichts" &&
pruefe "claim() verbraucht nichts" \
  SystemUserLedgerTest::test_the_next_name_never_repeats_a_claimed_one failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SystemUserLedgerTest passed

echo
echo "── SystemUserLedgerTest: claim() steht ausserhalb der Transaktion ──"
#
# Eine Zeile im Verzeichnis verschwindet nie wieder. Steht die Vergabe vor der
# Transaktion, frisst jeder fehlgeschlagene Anlageversuch eine Nummer — und die
# Lücke im Zähler ist später nicht mehr zu erklären.
vorher_datei app/Http/Controllers/SubscriptionController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/SubscriptionController.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """        $subscription = DB::transaction(function () use ($data, $lifecycle): Subscription {
            return Subscription::query()->create([
                'customer_id' => (int) $data['customer_id'],
                'plan_id' => (int) $data['plan_id'],
                'name' => $data['name'],
                'system_user' => $lifecycle->claim($data['name']),""",
    """        $verbrannt = $lifecycle->claim($data['name']);

        $subscription = DB::transaction(function () use ($data, $verbrannt): Subscription {
            return Subscription::query()->create([
                'customer_id' => (int) $data['customer_id'],
                'plan_id' => (int) $data['plan_id'],
                'name' => $data['name'],
                'system_user' => $verbrannt,""",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/SubscriptionController.php "claim() vor der Transaktion" &&
pruefe "claim() vor der Transaktion" \
  SystemUserLedgerTest::test_a_failed_creation_does_not_burn_a_name failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SystemUserLedgerTest passed

echo
echo "── SystemUserLedgerTest: der Rückbau lässt die Vorgänge hängen ──"
#
# **Der Bruch, der auf SQLite etwas anderes tut als auf dem Server.** Auf
# MariaDB steht `operations.subscription_id` seit docs/35 auf `nullOnDelete`;
# auf SQLite lässt sich ein Fremdschlüssel überhaupt nicht ändern und bleibt
# `cascadeOnDelete`. Löst der Rückbau die Vorgänge nicht selbst ab, nimmt das
# harte Löschen im Test das ganze Protokoll mit — und auf dem Server nicht.
vorher_datei app/Support/Subscriptions/Lifecycle.php
python3 - <<'PY2'
p = 'app/Support/Subscriptions/Lifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """        Operation::query()
            ->where('subscription_id', $subscription->id)
            ->update(['subscription_id' => null]);

""",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Subscriptions/Lifecycle.php "Vorgänge bleiben am Abonnement" &&
pruefe "Vorgänge bleiben am Abonnement" \
  SystemUserLedgerTest::test_the_operations_survive_the_removal failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SystemUserLedgerTest passed

echo
echo "── SystemUserLedgerTest: der Vorgang schreibt den Namen nicht ab ──"
#
# Die Datenmigration hat die Namen des Bestands nachgetragen. Für alles, was
# danach entsteht, gibt es nur diese eine Stelle — und ohne sie wäre jeder
# Vorgang nach dem nächsten Rückbau namenlos. Ein Fehler, der erst beim
# zurückgebauten Abonnement auffiele und dann nicht mehr zu heilen wäre.
vorher_datei app/Models/Operation.php
python3 - <<'PY2'
p = 'app/Models/Operation.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            if ($operation->subscription_id === null || $operation->subscription_name !== null) {",
    "            if (true || $operation->subscription_id === null) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Models/Operation.php "Vorgang ohne Abschrift" &&
pruefe "Vorgang ohne Abschrift" \
  SystemUserLedgerTest::test_an_operation_copies_the_subscription_name failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SystemUserLedgerTest passed

echo
echo "── SystemUserLedgerTest: der Abnahmelauf vergibt ohne zu verbrauchen ──"
#
# **Der Fehler, den der Wächter beim Bauen gefunden hat.** `nextSystemUser()`
# sagt nur, was der nächste *wäre*. In der Schleife von `srvpanel acceptance`
# bekämen alle Abonnements denselben Namen, und das zweite scheiterte am
# eindeutigen Index — auf dem Zielserver, im Abnahmelauf, nicht hier.
vorher_datei app/Console/Commands/Acceptance.php
python3 - <<'PY2'
p = 'app/Console/Commands/Acceptance.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "                'system_user' => $lifecycle->claim($name),",
    "                'system_user' => $lifecycle->nextSystemUser(),",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/Acceptance.php "Abnahmelauf vergibt ohne claim()" &&
pruefe "Abnahmelauf vergibt ohne claim()" \
  SystemUserLedgerTest::test_every_written_name_was_claimed failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SystemUserLedgerTest passed

echo
echo "── CertificatePruneTest: der geteilte Ablageort wird mitgenommen ──"
#
# **Der Fehler, den der Zielserver am 7. August fast bekommen hätte.** Auf ihm
# teilte sich `cloudlab24.de` seinen Ablageort zwischen einem zurückgebauten und
# einem LEBENDEN Abonnement. Wer beim Aufräumen je Zeile entscheidet statt je
# Ablageort — oder die lebenden Zeilen nicht mitzählt —, löscht den privaten
# Schlüssel einer laufenden Website.
vorher_datei app/Support/Tls/CertificatePrune.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificatePrune.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """                if (array_key_exists($name, $spoken)) {
                    $shared[$name] = true;

                    continue;
                }

""",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificatePrune.php "geteilter Ablageort wird entfernt" &&
pruefe "geteilter Ablageort wird entfernt" \
  CertificatePruneTest::test_a_storage_name_shared_with_a_living_certificate_is_kept failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificatePruneTest passed

echo
echo "── CertificatePruneTest: das Zertifikat der Oberfläche gilt als Waise ──"
#
# Beide tragen `subscription_id = null`; unterschieden werden sie allein an der
# Abschrift. Fällt sie aus der Frage, hält das Aufräumen das Zertifikat der
# Oberfläche für einen Rest — und entfernt den Schlüssel, mit dem das Panel
# antwortet.
vorher_datei app/Models/Certificate.php
python3 - <<'PY2'
p = 'app/Models/Certificate.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return $this->subscription_id === null && $this->subscription_name === null;",
    "        return $this->subscription_id === null;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Models/Certificate.php "forPanel kennt die Abschrift nicht" &&
pruefe "forPanel kennt die Abschrift nicht" \
  CertificatePruneTest::test_the_panel_certificate_is_not_an_orphan failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificatePruneTest passed

echo
echo "── CertificatePruneTest: der Rückbau lässt die Zertifikate kaskadieren ──"
#
# Ohne das Ablösen nimmt der harte Löschvorgang die Zeilen mit — und die
# Dateien bleiben liegen, samt privatem Schlüssel und ohne irgendetwas, das
# noch auf sie zeigt. Genau der Zustand, in dem der Zielserver war.
vorher_datei app/Support/Subscriptions/Lifecycle.php
python3 - <<'PY2'
p = 'app/Support/Subscriptions/Lifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """        Certificate::query()
            ->where('subscription_id', $subscription->id)
            ->update(['subscription_id' => null]);

""",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Subscriptions/Lifecycle.php "Zertifikate bleiben am Abonnement" &&
pruefe "Zertifikate bleiben am Abonnement" \
  CertificatePruneTest::test_a_removed_subscription_leaves_its_certificate_findable failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificatePruneTest passed

echo
echo "── CertificateStoreTest: das Löschen folgt einer Verknüpfung ──"
#
# `is_dir()` folgt einem Symlink, `is_link()` nicht. Fällt die Prüfung weg,
# zeigt ein als root laufendes Löschen woandershin als das Verzeichnis, das
# gemeint war.
vorher_datei agent/src/Acme/Store.php
python3 - <<'PY2'
p = 'agent/src/Acme/Store.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """        if (is_link($directory)) {
            throw AgentException::execFailed('Ablageort ist eine Verknüpfung: '.$directory);
        }

""",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Store.php "Verknüpfung wird verfolgt" &&
pruefe "Verknüpfung wird verfolgt" \
  CertificateStoreTest::test_a_symlink_is_refused_and_its_target_survives failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CertificateStoreTest passed

echo
echo "── GrantPatternTest: der Unterstrich im GRANT-Ziel wird nicht maskiert ──"
#
# In `GRANT … ON <db>.*` ist `<db>` ein Muster. Ohne die Maskierung trifft
# `p1001_shop` auch `p1001Xshop` — und der naheliegende Weg, `p1001_%`, träfe
# die Datenbanken eines fremden Abonnements. Das ist genau der Zugriff, den das
# Abnahmekriterium von P5 ausschliesst.
vorher_datei agent/src/Db/Sql.php
python3 - <<'PY2'
p = 'agent/src/Db/Sql.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $escaped = str_replace(['\\\\', '_'], ['\\\\\\\\', '\\\\_'], $database);",
    "        $escaped = $database;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Sql.php "GRANT-Ziel ohne Maskierung" &&
pruefe "GRANT-Ziel ohne Maskierung" \
  GrantPatternTest::test_the_underscore_is_escaped failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" GrantPatternTest passed

echo
echo "── GrantPatternTest: auf ein Muster wird berechtigt ──"
#
# Die Maskierung allein reicht nicht: Sie macht aus einem Namen einen Namen,
# hindert aber niemanden daran, `p1001_%` zu schicken.
vorher_datei agent/src/Db/Sql.php
python3 - <<'PY2'
p = 'agent/src/Db/Sql.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """        if (str_contains($database, '%')) {
            throw AgentException::denied('Auf ein Muster wird nicht berechtigt.');
        }

""",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Sql.php "Muster als GRANT-Ziel erlaubt" &&
pruefe "Muster als GRANT-Ziel erlaubt" \
  GrantPatternTest::test_a_pattern_is_refused_outright failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" GrantPatternTest passed

echo
echo "── DbIsolationTest: die Rechtevergabe reicht das Weiterreichen mit ──"
#
# `WITH GRANT OPTION` lässt einen Kunden Rechte weiterreichen — und damit sich
# selbst welche geben. Dieser Container hat keine MariaDB; geprüft wird deshalb
# der erzeugte Text, wie bei den nginx-Vorlagen.
vorher_datei agent/src/Ops/DbUserCreate.php
python3 - <<'PY2'
p = 'agent/src/Ops/DbUserCreate.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "                'GRANT ALL PRIVILEGES ON %s TO %s',",
    "                'GRANT ALL PRIVILEGES ON %s TO %s WITH GRANT OPTION',",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/DbUserCreate.php "Rechte lassen sich weiterreichen" &&
pruefe "Rechte lassen sich weiterreichen" \
  DbIsolationTest::test_no_statement_hands_the_grant_option_on failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DbIsolationTest passed

echo
echo "── DbNameTest: die Form des befristeten Benutzers ist nicht reserviert ──"
#
# Ein Kunde dürfte seinen Zugang `r3f9a20c1` nennen. Das ist die Form, die das
# Zurückspielen einer Sicherung für ein paar Minuten anlegt; db.server.info
# meldet sie nach einer Stunde als Rest, und das Aufräumen wirft sie weg. Der
# Kunde verlöre seinen Zugang, ohne dass irgendetwas falsch programmiert wäre.
vorher_datei agent/src/Db/Names.php
python3 - <<'PY2'
p = 'agent/src/Db/Names.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """        if (preg_match(self::EPHEMERAL_SUFFIX, $suffix)) {
            throw AgentException::badRequest(
                'Dieser Name ist für die Zugänge reserviert, die das Zurückspielen einer Sicherung '
                .'für ein paar Minuten anlegt — er würde danach von selbst wieder verschwinden.',
                ['suffix' => $suffix],
            );
        }

""",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Names.php "befristete Form frei wählbar" &&
pruefe "befristete Form frei wählbar" \
  DbNameTest::test_the_ephemeral_shape_is_reserved failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DbNameTest passed

echo
echo "── DbNameTest: die Mandantengrenze verwechselt p1001 mit p10012 ──"
#
# Ohne den Unterstrich im Vergleich wäre `p1001` ein Präfix von `p10012_shop` —
# und das Abonnement p1001 dürfte die Datenbanken von p10012 entfernen.
vorher_datei agent/src/Db/Names.php
python3 - <<'PY2'
p = 'agent/src/Db/Names.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return str_starts_with($name, $systemUser.'_');",
    "        return str_starts_with($name, $systemUser);",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Names.php "Präfixvergleich ohne Unterstrich" &&
pruefe "Präfixvergleich ohne Unterstrich" \
  DbNameTest::test_a_foreign_prefix_is_not_a_prefix failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DbNameTest passed

echo
echo "── RemovalPathTest: eine Operation legt an, und nichts entfernt es ──"
#
# Der Wächter, der die Zertifikatslücke aus docs/35 ein Jahr früher gemeldet
# hätte. Gebrochen wird er, indem eine remove-Hälfte aus der Registratur fällt.
vorher_datei agent/src/Registry.php
python3 - <<'PY2'
p = 'agent/src/Registry.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $this->register(new DbDatabaseRemove);\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Registry.php "db.database.create ohne remove" &&
pruefe "db.database.create ohne remove" \
  RemovalPathTest::test_every_creating_operation_has_a_removing_one failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RemovalPathTest passed

echo
echo "── SecretsStayOutOfTheQueueTest: ein Passwort landet in der Warteschlange ──"
#
# Ein eingereihter Vorgang legt seine Argumente in `operations.payload` ab —
# dauerhaft und im Klartext in der Datenbank des Panels. Die Regel gilt seit P4
# für den privaten Schlüssel und das DNS-Token; P5 macht sie zum dritten Mal
# nötig und damit zum Wächter.
vorher_datei app/Support/Databases/DbLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Databases/DbLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            'type' => 'db.user.lock',",
    "            'type' => 'db.user.create',",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/DbLifecycle.php "db.user.create wird eingereiht" &&
pruefe "db.user.create wird eingereiht" \
  SecretsStayOutOfTheQueueTest::test_no_secret_carrying_operation_is_queued failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SecretsStayOutOfTheQueueTest passed

echo
echo "── SecretsStayOutOfTheQueueTest: eine Spalte für das Passwort ──"
#
# Die zweite Hälfte, am Schema statt am Weg: Eine Spalte, die es nicht gibt,
# lässt sich nicht versehentlich füllen.
vorher_datei database/migrations/2026_08_08_100000_create_databases_tables.php
python3 - <<'PY2'
p = 'database/migrations/2026_08_08_100000_create_databases_tables.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            $table->string('host', 64)->default('localhost');",
    "            $table->string('host', 64)->default('localhost');\n            $table->string('password')->nullable();",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei database/migrations/2026_08_08_100000_create_databases_tables.php "Spalte für ein Passwort" &&
pruefe "Spalte für ein Passwort" \
  SecretsStayOutOfTheQueueTest::test_the_database_tables_have_no_place_for_a_secret failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SecretsStayOutOfTheQueueTest passed

echo
echo "── DefinerStripTest: der Filter fasst auch Datenzeilen an ──"
#
# Ein blindes Suchen-und-Ersetzen über den ganzen Dump verändert Nutzdaten. Eine
# Tabelle mit dem Text `DEFINER=` in einer Spalte käme verstümmelt zurück, und
# das fiele erst auf, wenn ein Kunde seine Daten vermisst. Das ist die
# gefährlichere der beiden Richtungen: zu wenig streichen erzeugt einen
# Fehlschlag, zu viel streichen einen Erfolg mit falschen Daten.
vorher_datei agent/src/Db/Dump.php
python3 - <<'PY2'
p = 'agent/src/Db/Dump.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """        if (! str_starts_with($trimmed, '/*!5') && ! str_starts_with($trimmed, 'CREATE ')) {
            return $line;
        }

""",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Dump.php "Filter ohne Rücksicht auf die Zeilenart" &&
pruefe "Filter ohne Rücksicht auf die Zeilenart" \
  DefinerStripTest::test_a_data_line_is_returned_byte_for_byte failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DefinerStripTest passed

echo
echo "── DefinerStripTest: der Ablagename wird nicht geprüft ──"
#
# Aus dem Namen entsteht im Agenten ein Pfad. Ohne die Positivliste stünde
# `../../etc/passwd` darin.
vorher_datei agent/src/Db/Dump.php
python3 - <<'PY2'
p = 'agent/src/Db/Dump.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (! preg_match('/^[a-z0-9][a-z0-9_\\-]{0,95}$/D', $value)) {",
    "        if (false) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Dump.php "Ablagename ohne Positivliste" &&
pruefe "Ablagename ohne Positivliste" \
  DefinerStripTest::test_a_storage_name_is_checked failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DefinerStripTest passed

echo
echo "── RemovalPathTest: die Sicherung lässt sich nicht entfernen ──"
#
# Eine Sicherung ist das, was P5 auf dem System hinterlässt und was beliebig
# gross wird. Fällt db.dump.remove weg, füllt sie den Datenträger und nimmt
# jeden anderen Kunden mit.
vorher_datei agent/src/Registry.php
python3 - <<'PY2'
p = 'agent/src/Registry.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $this->register(new DbDumpRemove);\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Registry.php "db.dump.create ohne remove" &&
pruefe "db.dump.create ohne remove" \
  RemovalPathTest::test_every_creating_operation_has_a_removing_one failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RemovalPathTest passed

echo
echo "── MobileLayoutTest: das Kärtchen behält seine eigene Breite ──"
#
# Der Fund, mit dem dieser Wächter entstanden ist. `.scrolls > table` wiegt
# 0,1,1 und `.stacks` 0,1,0 — die gestapelte Tabelle war so breit wie ihr
# breitestes Kärtchen und stand seitlich aus dem Bildschirm. Gemessen 553px in
# 358px Behälter bei 390px Fenster.
vorher
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace("  .scrolls > table.stacks {\n    width: 100%;\n  }\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff "Kärtchen mit eigener Breite" &&
pruefe "Kärtchen mit eigener Breite" \
  MobileLayoutTest::test_a_stacked_table_has_no_width_of_its_own failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" MobileLayoutTest::test_a_stacked_table_has_no_width_of_its_own passed

echo
echo "── MobileLayoutTest: die Kennung im Kärtchen bricht nicht ──"
#
# Die zweite Hälfte desselben Fundes: Von 195px waagerecht blieben nach der
# Breite allein noch 180px stehen. **Dieser Bruch hat den Wächter selbst
# überführt** — beim ersten Anlauf blieb er grün, weil `table.pairs td.ident`
# als Treffer zählte. Eine Regel für eine ganz andere Tabelle stand in der
# Kaskade für ein Kärtchen.
vorher
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """  .stacks td .ident,
  .stacks td.ident {
    min-width: 0;
    white-space: normal;
    overflow-wrap: anywhere;
  }
""",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff "Kennung im Kärtchen ohne Umbruch" &&
pruefe "Kennung im Kärtchen ohne Umbruch" \
  MobileLayoutTest::test_an_identifier_in_a_stacked_card_may_break failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" MobileLayoutTest::test_an_identifier_in_a_stacked_card_may_break passed

echo
echo "── MobileLayoutTest: die Marke wird zur Fläche ──"
#
# Der leiseste der drei: Nichts läuft über, nichts wird abgeschnitten. Eine
# Zustandsmarke wird nur 328px breit statt 116px und sieht damit aus wie eine
# Meldung.
vorher
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace("  .stacks td.multiline .badge {\n    align-self: flex-start;\n  }\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff "Marke ohne Gegenwehr gegen die Dehnung" &&
pruefe "Marke ohne Gegenwehr gegen die Dehnung" \
  MobileLayoutTest::test_a_badge_in_a_stacked_cell_keeps_its_width failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" MobileLayoutTest::test_a_badge_in_a_stacked_cell_keeps_its_width passed

echo
echo "── WordChoiceTest: das verbrauchte Wort steht nur im <script> ──"
#
# Der Wächter für die Wortwahl las den `<template>`-Block und die
# PHP-Literale — nicht aber die Zeichenketten im `<script>` einer Seite. Seine
# eigene Begründung sagte, dort stehe kein Anzeigetext, „und sollte sich das
# ändern, ist diese Zeile die Stelle, an der es nachzuziehen ist". Mit der
# ersten Rückfrage per `confirm()` hat es sich geändert: Der Knopf mit
# demselben Wort fiel in der CI auf, der Satz daneben nicht.
#
# Der Bruch prüft genau diesen toten Winkel — die alte Hälfte muss grün
# bleiben, die neue zubeissen.
vorher_datei resources/js/Pages/Databases/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    'Die Sicherung ${dump.name} zurückspielen?',
    'Die Sicherung ${dump.name} einspielen?',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Show.vue "verbrauchtes Wort in einer Rückfrage" &&
pruefe "verbrauchtes Wort in einer Rückfrage" \
  WordChoiceTest::test_no_vue_script_string_uses_a_spent_word failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" WordChoiceTest passed

echo
echo "── DbUsageScopeTest: die Messung siebt fremde Schemata nicht aus ──"
#
# `information_schema.tables` kennt jedes Schema des Servers: `mysql` mit der
# Benutzertabelle, `sys`, `performance_schema` — und die Datenbank des Panels
# selbst, in der Konten, Sitzungen und Zertifikatszeilen liegen. Das Ergebnis
# von `db.usage` geht an die Anwendung zurück; eine Operation, die die
# Schemaliste des Servers ausliefert, ist eine Auskunft, die niemand bestellt
# hat.
vorher_datei agent/src/Ops/DbUsage.php
python3 - <<'PY2'
p = 'agent/src/Ops/DbUsage.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """            if (! Names::isPanelName($name)) {
                continue;
            }

""",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/DbUsage.php "Messung ohne Aussonderung fremder Schemata" &&
pruefe "Messung ohne Aussonderung fremder Schemata" \
  DbUsageScopeTest::test_only_the_schemas_of_this_panel_are_reported failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DbUsageScopeTest passed

echo
echo "── UsageReachTest: der Zeitgeber misst die Datenbanken nicht mehr ──"
#
# Zwei Messungen hängen am selben Zeitgeber, weil zwei Zeitgeber zwei Dinge
# wären, die jemand überwachen muss. Fällt eine aus dem Kommando, läuft der
# Zeitgeber weiter **grün** und die Oberfläche zeigt dauerhaft „noch nicht
# gemessen" — das sieht aus wie ein Server, auf dem nichts liegt.
vorher_datei app/Console/Commands/MeasureUsage.php
python3 - <<'PY2'
p = 'app/Console/Commands/MeasureUsage.php'
s = open(p, encoding='utf-8').read()
s = s.replace("use App\\Support\\Databases\\Usage as DatabaseUsage;\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/MeasureUsage.php "Messung, die niemand aufruft" &&
pruefe "Messung, die niemand aufruft" \
  UsageReachTest::test_the_timer_calls_every_measurement failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" UsageReachTest passed

echo
echo "── DatabasePruneTest: das Aufräumen greift nach jeder Zeile ──"
#
# Die Auswahl entscheidet, ob die Daten eines Kunden von der Platte gehen. Ohne
# die Waisenbedingung nähme `srvpanel db --prune` **jede** Datenbank des Servers
# mit — und ein Aufräumen, das zu viel wegnimmt, sieht genauso erfolgreich aus
# wie eines, das es richtig macht (docs/36 §17, Kriterium 7).
vorher_datei app/Support/Databases/DatabasePrune.php
python3 - <<'PY2'
p = 'app/Support/Databases/DatabasePrune.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """            $databases = Database::query()
                ->whereNull('subscription_id')
                ->whereNotNull('subscription_name')""",
    """            $databases = Database::query()""",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/DatabasePrune.php "Aufräumen ohne Waisenbedingung" &&
pruefe "Aufräumen ohne Waisenbedingung" \
  DatabasePruneTest::test_the_neighbour_is_left_alone failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DatabasePruneTest passed

echo
echo "── DatabasePruneTest: die Bedingung fehlt beim Löschen ──"
#
# Die Gegenrichtung: Zwischen `plan()` und dem Löschen kann eine Zeile wieder zu
# einem Abonnement gehören. Ohne die Bedingung löscht ein Aufräumen eine
# Kennung von vorhin blind — und trifft eine lebende Datenbank.
vorher_datei app/Support/Databases/DatabasePrune.php
python3 - <<'PY2'
p = 'app/Support/Databases/DatabasePrune.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """            ->whereKey($id)
            ->whereNull('subscription_id')
            ->whereNotNull('subscription_name')""",
    """            ->whereKey($id)""",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/DatabasePrune.php "Löschen ohne Waisenbedingung" &&
pruefe "Löschen ohne Waisenbedingung" \
  DatabasePruneTest::test_forgetting_removes_only_the_orphan failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DatabasePruneTest passed

echo
echo "── IsolationVerdictTest: die Probe zählt statt zu nennen ──"
#
# Der teuerste Fund des P4-Abnahmelaufs, eine Stufe weiter: „1 fällig, 1
# bestellt" war die richtige Zahl über der falschen Sache. Für P5 hiesse das:
# `count($visible) === 1` ist auch dann grün, wenn der Benutzer *eine fremde*
# Datenbank sieht und die eigene nicht.
vorher_datei agent/src/Ops/DbIsolationProbe.php
python3 - <<'PY2'
p = 'agent/src/Ops/DbIsolationProbe.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'visible' => $visible,", "'visible' => count($visible),")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/DbIsolationProbe.php "Probe zählt statt zu nennen" &&
pruefe "Probe zählt statt zu nennen" \
  IsolationVerdictTest::test_the_probe_returns_names failed
wiederherstellen

echo
echo "── IsolationVerdictTest: der Abnahmelauf vergleicht die Grösse ──"
#
# Die andere Hälfte derselben Regel: Zwei sichtbare Namen sind zwei sichtbare
# Namen, gleichgültig welche.
vorher_datei app/Console/Commands/AcceptanceDb.php
python3 - <<'PY2'
p = 'app/Console/Commands/AcceptanceDb.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    'if ($visible !== $expected) {',
    'if (count($visible) !== count($expected)) {',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/AcceptanceDb.php "Abnahmelauf vergleicht die Grösse" &&
pruefe "Abnahmelauf vergleicht die Grösse" \
  IsolationVerdictTest::test_the_acceptance_run_compares_the_set_and_not_its_size failed
wiederherstellen

echo
echo "── IsolationVerdictTest: nur noch die Anzeige wird geprüft ──"
#
# `SHOW DATABASES` ist eine Anzeige, `USE` der Wechsel, das `SELECT` der
# Zugriff. Ein Server kann die Anzeige filtern und den Zugriff zulassen — wer
# nur die Liste prüft, hat die Anzeige geprüft.
vorher_datei agent/src/Ops/DbIsolationProbe.php
python3 - <<'PY2'
p = 'agent/src/Ops/DbIsolationProbe.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'SELECT COUNT(*) FROM %s.%s',", "'SHOW TABLES FROM %s -- %s',")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/DbIsolationProbe.php "nur die Anzeige geprüft" &&
pruefe "nur die Anzeige geprüft" \
  IsolationVerdictTest::test_all_three_questions_are_asked failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" IsolationVerdictTest passed

echo
echo "── IsolationVerdictTest: eine fehlende Tabelle gilt als Abschottung ──"
#
# Der Fund des Abnahmelaufs vom 8. August 2026: Der Lauf prüfte, *dass* der
# Zugriff scheiterte, nicht *woran*. `ERROR 1146 Table doesn't exist` — ein
# Tippfehler im Tabellennamen — las sich damit wie eine funktionierende
# Abschottung. docs/36 §17 nennt die Nummern seit jeher.
vorher_datei app/Console/Commands/AcceptanceDb.php
python3 - <<'PY2'
p = 'app/Console/Commands/AcceptanceDb.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "'select_refused' => ['SELECT', [1142, 1044]],",
    "'select_refused' => ['SELECT', [1142, 1044, 1146]],",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/AcceptanceDb.php "fehlende Tabelle gilt als Abschottung" &&
pruefe "fehlende Tabelle gilt als Abschottung" \
  IsolationVerdictTest::test_the_acceptance_run_checks_which_error_it_was failed
wiederherstellen

echo
echo "── IsolationVerdictTest: die Meldung wird an einer Stelle vermutet ──"
#
# Am 8. August gab der `mysql`-Client die gescheiterte Anweisung zwischen
# Strichzeilen aus. Die erste Zeile lautete `--------------`, und genau das
# stand in der Meldung des Laufs — an der sicherheitsrelevantesten Stelle der
# ganzen Ausgabe.
vorher_datei app/Console/Commands/AcceptanceDb.php
python3 - <<'PY2'
p = 'app/Console/Commands/AcceptanceDb.php'
s = open(p, encoding='utf-8').read()
s = s.replace("if (str_contains($line, 'ERROR ')) {", "if (false) {")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/AcceptanceDb.php "Meldung an einer Stelle vermutet" &&
pruefe "Meldung an einer Stelle vermutet" \
  IsolationVerdictTest::test_the_error_line_is_searched_and_not_assumed failed
wiederherstellen

echo
echo "── DbErrorCodeTest: die Nummer wird in der ersten Zeile gesucht ──"
#
# Die Gegenrichtung im Agenten: Wo die `ERROR`-Zeile in der Ausgabe steht,
# entscheidet der Client. Der Test führt beide Ausgaben mit, die der Server am
# 8. August wirklich geliefert hat — eine mit Strichzeilen davor, eine ohne.
vorher_datei agent/src/Ops/DbIsolationProbe.php
python3 - <<'PY2'
p = 'agent/src/Ops/DbIsolationProbe.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "return preg_match('/\\bERROR\\s+(\\d{4})\\b/', $message, $match) === 1 ? (int) $match[1] : null;",
    "return preg_match('/^ERROR\\s+(\\d{4})\\b/', $message, $match) === 1 ? (int) $match[1] : null;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/DbIsolationProbe.php "Nummer nur in der ersten Zeile" &&
pruefe "Nummer nur in der ersten Zeile" \
  DbErrorCodeTest::test_the_code_is_found_behind_the_dashes failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DbErrorCodeTest passed

echo
echo "── UsageEvidenceTest: die Messung zählt, was sie geschrieben hat ──"
#
# Der Befund des Abnahmelaufs vom 8. August, als Bruch: `reported` bekommt
# dieselbe Zahl wie `measured`. Damit steht wieder eine Zahl über dem, was wir
# getan haben, statt über dem, was der Server geliefert hat — und der Lauf mit
# leerer Antwort sieht aus wie einer, der zwei Schemata gelesen hat.
vorher_datei app/Support/Databases/Usage.php
python3 - <<'PY2'
p = 'app/Support/Databases/Usage.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'reported' => count($sizes),", "'reported' => $measured,")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/Usage.php "gemeldet ist geschrieben" &&
pruefe "gemeldet ist geschrieben" \
  UsageEvidenceTest::test_a_database_measurement_that_read_nothing_says_so failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  UsageEvidenceTest::test_a_database_measurement_that_read_nothing_says_so passed

echo
echo "── UsageEvidenceTest: derselbe Griff am Zwilling ──"
#
# Die Lücke stand in beiden Messungen, gefunden wurde sie an einer. Ein Wächter,
# der nur die eine hält, ist der, den die nächste Abschrift umgeht — deshalb
# derselbe Bruch an der Quota-Messung.
vorher_datei app/Support/Subscriptions/Usage.php
python3 - <<'PY2'
p = 'app/Support/Subscriptions/Usage.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'reported' => count($users),", "'reported' => $measured,")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Subscriptions/Usage.php "gemeldet ist geschrieben, Platte" &&
pruefe "gemeldet ist geschrieben, Platte" \
  UsageEvidenceTest::test_a_disk_measurement_that_read_nothing_says_so failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  UsageEvidenceTest::test_a_disk_measurement_that_read_nothing_says_so passed

echo
echo "── UsageEvidenceTest: gerechnet, aber nicht gezeigt ──"
#
# **Dieser Bruch hat den Wächter schon einmal verbessert.** Sein erster Anlauf
# suchte die drei Zahlen im ganzen Methodenrumpf — und blieb grün, als sie aus
# der Erfolgsmeldung verschwanden: Sie stehen weiter in der Warnung darunter,
# die aber nur im Ausnahmefall kommt. Geprüft wird seitdem die Meldung selbst.
vorher_datei app/Console/Commands/MeasureUsage.php
python3 - <<'PY2'
p = 'app/Console/Commands/MeasureUsage.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            '%d Abonnement(s) geschrieben; die Quota-Datei nannte %d Systembenutzer, %d davon zugeordnet.',\n"
    "            $result['measured'],\n"
    "            $result['reported'],\n"
    "            $result['matched'],\n",
    "            '%d Abonnement(s) gemessen.',\n"
    "            $result['measured'],\n",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/MeasureUsage.php "Zahlen gerechnet, nicht gezeigt" &&
pruefe "Zahlen gerechnet, nicht gezeigt" \
  UsageEvidenceTest::test_both_measurements_print_all_three_numbers failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" UsageEvidenceTest passed

echo
echo "── SizeUnitTest: die Messung rundet wieder beim Ablegen ──"
#
# Die Division, gegen die `DbUsageScopeTest` eine Ebene tiefer argumentiert —
# an der Stelle, an der sie bis zum 8. August 2026 wirklich stand. Damit sind
# 300 KB und eine leere Datenbank wieder dasselbe.
vorher_datei app/Support/Databases/Usage.php
python3 - <<'PY2'
p = 'app/Support/Databases/Usage.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "'size_bytes' => max(0, (int) ($bytes ?? 0)),",
    "'size_bytes' => intdiv(max(0, (int) ($bytes ?? 0)), 1024 * 1024),",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/Usage.php "gerundet beim Ablegen" &&
pruefe "gerundet beim Ablegen" \
  SizeUnitTest::test_a_small_database_is_not_stored_as_zero failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SizeUnitTest::test_a_small_database_is_not_stored_as_zero passed

echo
echo "── SizeUnitTest: die Summe teilt je Zeile statt am Ende ──"
#
# Vier Datenbanken zu je 300 KB sind 1 MB. Wer je Zeile teilt, bekommt vier
# Nullen — und eine Null neben einem Kontingent von 2048 MB sieht plausibel aus.
vorher_datei app/Models/Subscription.php
python3 - <<'PY2'
p = 'app/Models/Subscription.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "return $databases->exists() ? intdiv((int) $databases->sum('size_bytes'), 1024 * 1024) : null;",
    "return $databases->exists() ? (int) $databases->get()"
    "->sum(static fn ($row): int => intdiv((int) $row->size_bytes, 1024 * 1024)) : null;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Models/Subscription.php "je Zeile geteilt" &&
pruefe "je Zeile geteilt" \
  SizeUnitTest::test_the_subscription_sums_before_it_divides failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SizeUnitTest::test_the_subscription_sums_before_it_divides passed

echo
echo "── SizeUnitTest: eine zweite Umrechnung in der Oberfläche ──"
#
# Vor dem Wächter gab es drei Fassungen davon, und die dritte war die beste.
# Der Bruch stellt eine vierte daneben.
vorher_datei resources/js/Pages/Databases/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "  return formatBytes(row.size_bytes)",
    "  return `${Math.round(row.size_bytes / 1024)} KB`",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Index.vue "zweite Umrechnung in der Liste" &&
pruefe "zweite Umrechnung in der Liste" \
  SizeUnitTest::test_only_one_place_in_the_interface_turns_bytes_into_a_unit failed
wiederherstellen

echo
echo "── SizeUnitTest: die Seite zeigt die rohe Zahl ──"
#
# Die Gegenrichtung, und sie ist der Grund für die zweite Behauptung: Ohne
# Faktor im Quelltext bleibt der Ausdruck oben grün, und die Seite zeigt
# „314572800" — richtig und unlesbar.
vorher_datei resources/js/Pages/Databases/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("import { formatBytes } from '../../bytes'\n", '')
s = s.replace("  return formatBytes(row.size_bytes)", "  return `${row.size_bytes} Bytes`")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Index.vue "rohe Zahl ohne Faktor" &&
pruefe "rohe Zahl ohne Faktor" \
  SizeUnitTest::test_every_page_that_shows_a_size_uses_that_one_place failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SizeUnitTest passed

echo
echo "── OverviewInventoryTest: die verwaiste Datenbank fällt aus der Zählung ──"
#
# Die Liste unter /databases führt sie als verwaist, die Übersicht liesse sie
# weg — und die Zahl wäre ausgerechnet dann zu klein, wenn ein Rückbau
# steckengeblieben ist und ein Schema mit Kundendaten liegt.
vorher_datei app/Http/Controllers/OverviewController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/OverviewController.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "$databases = $this->countByStatus(Database::query());",
    "$databases = $this->countByStatus(Database::query()->whereNotNull('subscription_id'));",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/OverviewController.php "verwaiste Datenbank nicht gezählt" &&
pruefe "verwaiste Datenbank nicht gezählt" \
  OverviewInventoryTest::test_an_orphaned_database_is_counted_too failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  OverviewInventoryTest::test_an_orphaned_database_is_counted_too passed

echo
echo "── OverviewInventoryTest: eine Zahl ohne Weg zur Liste ──"
#
# Die Zahl bleibt stehen, der Verweis fällt weg. Wer sie liest, will als
# Nächstes wissen, welche das sind — und müsste dann in die Navigation greifen.
vorher_datei resources/js/Pages/Overview.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Overview.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('<Link href="/databases" class="link">Datenbanken</Link>', 'Datenbanken')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Overview.vue "Bestandszahl ohne Verweis" &&
pruefe "Bestandszahl ohne Verweis" \
  OverviewInventoryTest::test_all_four_kinds_are_linked failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OverviewInventoryTest passed

echo
echo "── DumpAccessTest: das Verzeichnis gehört wieder root allein ──"
#
# Der Zustand, mit dem „Herunterladen" am 8. August 2026 mit 404 antwortete:
# Die Datei war für die Gruppe lesbar, ihr Verzeichnis für die Gruppe nicht
# durchsuchbar — und ohne x auf dem Weg nützt das r am Ziel nichts.
vorher_datei agent/src/Db/Dump.php
python3 - <<'PY2'
p = 'agent/src/Db/Dump.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            if ($group) {\n                chgrp($path, self::GROUP);\n            }\n\n",
    "",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Dump.php "Verzeichnis ohne Gruppe des Panels" &&
pruefe "Verzeichnis ohne Gruppe des Panels" \
  DumpAccessTest::test_every_directory_belongs_to_the_group_that_reads_the_files failed
wiederherstellen

echo
echo "── DumpAccessTest: das Verzeichnis wird auflistbar ──"
#
# Die andere Richtung, und sie ist der Grund, warum 0710 und nicht 0750 dasteht:
# Ein Verzeichnis mit r für die Gruppe verrät, welche Sicherungen es gibt.
vorher_datei agent/src/Db/Dump.php
python3 - <<'PY2'
p = 'agent/src/Db/Dump.php'
s = open(p, encoding='utf-8').read()
s = s.replace('public const DIRECTORY_MODE = 0710;', 'public const DIRECTORY_MODE = 0750;')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Dump.php "Verzeichnis auflistbar" &&
pruefe "Verzeichnis auflistbar" \
  DumpAccessTest::test_the_group_may_not_list_the_directory failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DumpAccessTest passed

echo
echo "── MobileLayoutTest: eine Paar-Tabelle beschriftet mit th ──"
#
# Die schmale Fläche setzt `table.pairs td` zurück und `th` nicht — das `th`
# behält seinen Rand aus der Tabellengestaltung, und der ist so breit wie die
# Beschriftung. Auf 390px stehen dann zwei Striche versetzt untereinander.
vorher_datei resources/js/Pages/Databases/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('<td class="quiet">Sortierung</td>', '<th>Sortierung</th>')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Show.vue "Paar-Tabelle mit th" &&
pruefe "Paar-Tabelle mit th" \
  MobileLayoutTest::test_a_pairs_table_labels_its_rows_the_same_way_everywhere failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  MobileLayoutTest::test_a_pairs_table_labels_its_rows_the_same_way_everywhere passed

echo
echo "── OperationStreamTest: die Zeiten fehlen im Ereignis ──"
#
# Der Zustand vom 8. August 2026: Der Kanal führt Zustand, Fortschritt und
# Meldung nach, die Zeitstempel nicht — und ein fertiger Vorgang zeigt
# „Begonnen —", weil die Erstantwort aus der Warteschlange stammt.
vorher_datei app/Http/Controllers/OperationStreamController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/OperationStreamController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                    'started_at' => Clock::display($operation->started_at),\n", '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/OperationStreamController.php "Zeitstempel nicht im Kanal" &&
pruefe "Zeitstempel nicht im Kanal" \
  OperationStreamTest::test_the_state_event_carries_the_times failed
wiederherstellen

echo
echo "── OperationStreamTest: die Vorlage druckt aus der Erstantwort ──"
#
# Die Gegenrichtung, und der eigentliche Fehler: Der Kanal darf schicken, was er
# will, solange die Vorlage den Wert der ersten Antwort ausgibt.
vorher_datei resources/js/Pages/Operations/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Operations/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("{{ startedAt ?? '—' }}", "{{ props.operation.started_at ?? '—' }}")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Operations/Show.vue "Zeit aus der Erstantwort gedruckt" &&
pruefe "Zeit aus der Erstantwort gedruckt" \
  OperationStreamTest::test_the_page_does_not_print_a_live_field_from_the_first_answer failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OperationStreamTest passed

echo
echo "── GuardReachTest: ein Kommentar nennt einen Wächter, den es nicht gibt ──"
#
# Der Zustand, den dieser Wächter am 8. August 2026 vorgefunden hat: In
# DatabaseController stand „die Gegenprobe steht in DatabaseFormTest", und die
# Datei gab es nicht. Ein toter Verweis auf eine Klasse fällt beim nächsten
# Aufruf auf, einer auf einen Test niemals.
vorher_datei app/Support/Databases/Databases.php
python3 - <<'PY2'
p = 'app/Support/Databases/Databases.php'
s = open(p, encoding='utf-8').read()
s = s.replace('(docs/36 §22.3n).', '(docs/36 §22.3n). `ErfundenerWaechterTest` prüft das.', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/Databases.php "Kommentar nennt einen Test ohne Datei" &&
pruefe "Kommentar nennt einen Test ohne Datei" \
  GuardReachTest::test_every_test_named_in_the_code_exists failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" GuardReachTest passed

echo
echo "── DatabaseFormTest: das Formular lässt durch, was der Agent abweist ──"
#
# Das `D` am Ende des Musters: Ohne es passt `$` auch vor einem abschliessenden
# Zeilenumbruch. Genau so stand es bis zum 8. August dreimal im Controller —
# gefunden hat es dieser Test bei seinem ersten Lauf.
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'regex:/^[a-z][a-z0-9_]{0,15}$/D'", "'regex:/^[a-z][a-z0-9_]{0,15}$/'", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "Formularmuster ohne D" &&
pruefe "Formularmuster ohne D" \
  DatabaseFormTest::test_the_form_allows_exactly_what_the_agent_allows failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  AnchoredPatternTest::test_every_anchored_rule_in_a_form_ends_only_at_the_end passed

echo
echo "── DatabaseFormTest: ein vergebener Zugangsname wird wieder überschrieben ──"
#
# Ohne die Prüfung baut der Agent `CREATE USER IF NOT EXISTS` plus `ALTER USER`
# — und der zweite Klick ersetzt das Passwort eines Zugangs, den ein Kunde schon
# in seiner Konfigurationsdatei stehen hat.
vorher_datei app/Support/Databases/Databases.php
python3 - <<'PY2'
p = 'app/Support/Databases/Databases.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $this->guardFreeName($driver, $prefix, $label, $host);\n\n", '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/Databases.php "Zugangsname ohne Prüfung" &&
pruefe "Zugangsname ohne Prüfung" \
  DatabaseFormTest::test_an_existing_access_name_is_refused_before_the_agent_is_asked failed
wiederherstellen

echo
echo "── DbTenancyTest: die Klammer am Datenbankmodell fällt weg ──"
#
# Die Hälfte des Abnahmekriteriums, die im Panel spielt. Der Plan sieht diesen
# Test seit §16.7 vor; geschrieben war er bis zum 8. August 2026 nicht.
vorher_datei app/Models/Database.php
python3 - <<'PY2'
p = 'app/Models/Database.php'
s = open(p, encoding='utf-8').read()
s = s.replace('    use BelongsToSubscription;\n', '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Models/Database.php "Datenbank ohne Mandantenklammer" &&
pruefe "Datenbank ohne Mandantenklammer" \
  DbTenancyTest::test_without_a_tenant_no_database_is_visible failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DbTenancyTest passed

echo
echo "── AgentOperationReachTest: eine Begründung ohne Aufrufer ──"
#
# Der Zustand von P5 bis zum 8. August 2026: `db.user.grant` hatte einen
# Eintrag in WITHOUT_LIFECYCLE, eine fertige Methode in `Databases` — und kein
# Controller, keine Route, kein Test rief sie auf. Der alte Wächter nahm den
# Eintrag als Beleg für Benutzung.
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace('$this->databases->grant($user, $database, $granted);', '$granted = $granted;')
open(p, 'w', encoding='utf-8').write(s)
PY2
python3 - <<'PY2'
# Seit Schritt 4 baut den Aufruf der Treiber und nicht mehr Databases.
p = 'app/Support/Databases/Engines/MariaDbDriver.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'db.user.grant'", "'db.user.grant.abgeschaltet'")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "Operation ohne Aufrufer" &&
pruefe "Operation ohne Aufrufer" \
  AgentOperationReachTest::test_every_operation_without_a_lifecycle_is_called_somewhere failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AgentOperationReachTest passed

echo
echo "── DbTenancyTest: ein fremder Zugang lässt sich verbinden ──"
#
# Die Klammer hält einen Kunden schon an der Modellbindung auf; ein Admin ist
# unbeschränkt. Ohne die ausdrückliche Prüfung entstünde ein Recht über
# Abonnementgrenzen hinweg — durch eine Zahl in der Adresse.
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        abort_unless(\n"
    "            $user->subscription_id !== null && $user->subscription_id === $database->subscription_id,\n"
    "            404,\n"
    "        );\n\n",
    '',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "fremder Zugang verbindbar" &&
pruefe "fremder Zugang verbindbar" \
  DbTenancyTest::test_not_even_the_operator_links_a_foreign_access failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DbTenancyTest passed

echo
echo "── OrphanedGrantTest: der bleibende Zugang wird nicht genannt ──"
#
# `DROP DATABASE` nimmt die Rechte auf das Schema nicht mit. Nennt die Anwendung
# nur die Zugänge, die mitgehen, behält jeder überlebende sein `GRANT ALL` auf
# eine Datenbank, die es nicht mehr gibt — auf cloudsrv24 gefunden (docs/36
# §22.3p).
# Seit Schritt 4 steht die Nutzlast im Treiber — und die zweite Liste gibt es
# nur dort: PostgreSQL braucht sie nicht, weil das Recht mit der Datenbank geht.
vorher_datei app/Support/Databases/Engines/MariaDbDriver.php
python3 - <<'PY2'
p = 'app/Support/Databases/Engines/MariaDbDriver.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            'revoke' => self::accounts($staying),\n",
    '',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/Engines/MariaDbDriver.php "bleibender Zugang nicht genannt" &&
pruefe "bleibender Zugang nicht genannt" \
  OrphanedGrantTest::test_an_access_that_stays_is_named_for_the_revoke failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OrphanedGrantTest passed

echo
echo "── OrphanedGrantTest: die Nennung erreicht keine Anweisung ──"
#
# Die andere Hälfte desselben Weges: Eine Liste im Auftrag, die der Agent nicht
# in ein REVOKE übersetzt, ist genau die Sorte Zeichenkette, die auf etwas
# verweist, ohne dass es sie erreicht.
vorher_datei agent/src/Ops/DbDatabaseRemove.php
python3 - <<'PY2'
p = 'agent/src/Ops/DbDatabaseRemove.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        foreach ($staying as [$user, $host]) {\n"
    "            $statements[] = DbUserGrant::statement($user, $host, $database, false);\n"
    "        }\n\n",
    '',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/DbDatabaseRemove.php "Nennung ohne Anweisung" &&
pruefe "Nennung ohne Anweisung" \
  OrphanedGrantTest::test_every_named_account_reaches_a_statement failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OrphanedGrantTest passed

echo
echo "── WordChoiceTest: „Noch\" an einem fertigen Vorgang ──"
#
# Das Wort verspricht, dass etwas kommt. An einem abgeschlossenen Vorgang kommt
# nichts mehr — Vorgang 449 stand am 8. August 2026 auf „fertig" und darunter
# „Noch keine Ausgabe.".
vorher_datei resources/js/Pages/Operations/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Operations/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "const emptyOutput = computed(() => (open.value ? 'Noch keine Ausgabe.' : 'Keine Ausgabe.'))",
    "const emptyOutput = computed(() => 'Noch keine Ausgabe.')",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Operations/Show.vue "Zusage an einem fertigen Vorgang" &&
pruefe "Zusage an einem fertigen Vorgang" \
  WordChoiceTest::test_the_operation_page_promises_nothing_after_the_end failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" WordChoiceTest passed

echo
echo "── DumpTeardownTest: die Zeile überlebt ihre Datei ──"
#
# Nach dem Rückbau eines Abonnements ist die Sicherungsdatei fort, und ohne
# diesen Zweig bleibt ihre Zeile stehen: srvpanel db meldet dann einen Rest nach
# einem Rückbau, der sauber gelaufen ist (docs/36 §22.3r).
vorher_datei app/Support/Databases/DumpLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Databases/DumpLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "                $this->removedAllDumps($operation, $task);\n\n",
    '',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/DumpLifecycle.php "Zeile überlebt ihre Datei" &&
pruefe "Zeile überlebt ihre Datei" \
  DumpTeardownTest::test_nothing_is_left_over_after_the_subscription_is_gone failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DumpTeardownTest passed

echo
echo "── ActionColumnTest: eine Aktionsspalte im Grundriss ──"
#
# Die Breite einer Tabelle mit Knöpfen ist die Summe ihrer Beschriftungen, und
# `.scrolls > table` hält sie auf max-content. Im Grundriss liegt der letzte
# Knopf ausserhalb des Bereichs — man muss schieben, um ihn zu treffen
# (docs/36 §22.3s).
vorher_datei resources/js/Pages/Databases/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '<Section title="Sicherungen" full>',
    '<Section title="Sicherungen">',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Show.vue "Aktionsspalte im Grundriss" &&
pruefe "Aktionsspalte im Grundriss" \
  ActionColumnTest::test_a_table_with_an_action_column_takes_the_whole_row failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ActionColumnTest passed

echo
echo "── RemoteAccessTest: das purge nimmt die Include-Datei nicht mit ──"
#
# Der Dateiname steht in DbRemoteAccess und im Entfernungsskript — zwei Stellen,
# und kein Compiler dazwischen. Ohne die Zeile im Skript hinterlässt ein
# entferntes Panel einen Datenbankserver, der auf einer erreichbaren Adresse
# horcht (docs/36 §22.3t).
vorher_datei packaging/scripts/postremove.sh
python3 - <<'PY2'
p = 'packaging/scripts/postremove.sh'
s = open(p, encoding='utf-8').read()
s = s.replace('60-srvpanel.cnf', '60-srvpanel.conf')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei packaging/scripts/postremove.sh "purge ohne Include-Datei" &&
pruefe "purge ohne Include-Datei" \
  RemoteAccessTest::test_the_purge_takes_the_include_file_along failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RemoteAccessTest passed

echo
echo "── RemoteAccessTest: ein fremder Wirt ohne Sperre ──"
#
# Das Feld für eine fremde Adresse erscheint nur, wenn der Server auch darauf
# horcht — aber ein Formular ist keine Sperre. Ohne die Prüfung entstünde ein
# Zugang, der nie zustande kommt und den niemand mehr zuordnet, sobald der
# Fernzugriff eingeschaltet wird.
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if ($this->remoteAccess()['possible'] !== true) {\n",
    "        if (false) {\n",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "fremder Wirt ohne Sperre" &&
pruefe "fremder Wirt ohne Sperre" \
  RemoteAccessTest::test_a_foreign_host_is_refused_while_the_server_listens_locally failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RemoteAccessTest passed

echo
echo "── RemovalPathTest: eine schreibende Operation ohne Weg zurück ──"
#
# Die Verb-Regel erkennt `create`, `apply`, `provision`. Eine Operation mit
# einem Schalter trägt keines davon — und schreibt trotzdem nach /etc. Genau
# diese Lücke hat der Fernzugriff freigelegt (docs/36 §22.3t).
vorher_datei agent/src/Ops/DbRemoteAccess.php
python3 - <<'PY2'
p = 'agent/src/Ops/DbRemoteAccess.php'
s = open(p, encoding='utf-8').read()
s = s.replace("return 'db.remote.access';", "return 'db.remote.switch';")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/DbRemoteAccess.php "schreibende Operation ohne Weg zurück" &&
pruefe "schreibende Operation ohne Weg zurück" \
  RemovalPathTest::test_every_operation_that_writes_a_file_says_how_it_goes_away failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RemovalPathTest passed

echo
echo "── UploadLimitTest: eine Datei, die keine gzip-Datei ist ──"
#
# Eine Datei heisst .sql.gz, weil jemand sie so genannt hat. Ohne die Prüfung
# der Magic Bytes läge sie im Verzeichnis der Sicherungen, sähe dort aus wie
# eine, und der Fehler käme beim Zurückspielen — an einer Datenbank, die dabei
# schon geleert ist (docs/36 §22.3u).
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        if (! $this->looksLikeGzip($file->getRealPath())) {\n",
    "        if (false) {\n",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "Datei ohne Magic Bytes" &&
pruefe "Datei ohne Magic Bytes" \
  UploadLimitTest::test_a_file_that_is_not_gzip_is_refused failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" UploadLimitTest passed

echo
echo "── UploadLimitTest: nginx nimmt weniger an als PHP ──"
#
# Drei Zahlen an drei Orten. Ist die des Webservers die engste, bricht der
# Upload bei 90 % ab — mit einer nginx-Fehlerseite, die von PHP nichts weiss
# (docs/36 §10.3).
vorher_datei agent/src/Ops/PanelVhost.php
python3 - <<'PY2'
p = 'agent/src/Ops/PanelVhost.php'
s = open(p, encoding='utf-8').read()
s = s.replace('client_max_body_size 544m;', 'client_max_body_size 64m;')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PanelVhost.php "nginx enger als PHP" &&
pruefe "nginx enger als PHP" \
  UploadLimitTest::test_the_three_numbers_fit_together failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" UploadLimitTest passed

echo
echo "── CommandReachTest: ein Wort, das nach einer Option aussieht ──"
#
# Der Anlass ist kein erfundener: Auf der Datenbankseite stand seit P5
# `srvpanel db prune`, und das Kommando nimmt kein Argument. Wer die Zeile
# abtippt, bekommt „Too many arguments" (docs/36 §22.3v).
vorher_datei resources/js/Pages/Databases/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('srvpanel db --prune', 'srvpanel db prune')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Show.vue "Wort statt Option" &&
pruefe "Wort statt Option" \
  CommandReachTest::test_a_command_printed_in_the_interface_consists_of_options_only failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CommandReachTest passed

echo
echo "── CommandReachTest: eine Option, die es nicht gibt ──"
#
# Dieselbe Familie, eine Ebene tiefer: Der Befehl auf „Einstellungen →
# Datenbankserver" steht als Konstante im Steuerungscode. Ein Buchstabe daneben,
# und die Seite druckt eine Zeile ab, die Symfony abweist.
vorher_datei app/Http/Controllers/DatabaseSettingsController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseSettingsController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'sudo srvpanel db --remote=on'", "'sudo srvpanel db --remotes=on'")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseSettingsController.php "erfundene Option" &&
pruefe "erfundene Option" CommandReachTest::test_every_printed_option_exists failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CommandReachTest passed

echo
echo "── RemoteAccessTest: ein Schalter auf der Einstellungsseite ──"
#
# Der Fernzugriff wird auf der Kommandozeile geschaltet, weil sein Neustart den
# Datenbankserver mitnimmt, auf dem dieses Panel arbeitet (docs/36 §22.3v). Eine
# schreibende Route unter /settings/database wäre genau der Klick, den es dort
# nicht geben soll.
#
# **Dieser Eingriff ist der Grund, warum `routes/` oben in beiden Listen
# steht.** Vorher stand es in keiner: `wiederherstellen` hätte die Datei nicht
# zurückgeholt, und der Bruch wäre keine Probe gewesen, sondern eine Änderung —
# genau die Falle, die im Kopf dieses Skripts für `packaging/` und `database/`
# schon beschrieben ist.
vorher_datei routes/web.php
python3 - <<'PY2'
p = 'routes/web.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "    Route::get('/settings/database', [DatabaseSettingsController::class, 'show'])",
    "    Route::put('/settings/database', [DatabaseSettingsController::class, 'show'])\n"
    "        ->middleware('can:manage-settings')\n"
    "        ->name('settings.database.switch');\n\n"
    "    Route::get('/settings/database', [DatabaseSettingsController::class, 'show'])",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei routes/web.php "Schalter auf der Einstellungsseite" &&
pruefe "Schalter auf der Einstellungsseite" \
  RemoteAccessTest::test_the_settings_page_only_reads failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RemoteAccessTest::test_the_settings_page_only_reads passed

echo
echo "── FrameContractTest: der Agent benennt ein Feld um ──"
#
# Der teuerste Fund des P5-Abnahmelaufs, nachgestellt: Der Agent schickte `pct`,
# das Panel las `percent`. Zehn Monate lang sprang jeder Fortschrittsbalken von
# 0 auf 100, und niemand hat es gemerkt (docs/36 §22.3w).
vorher_datei agent/src/Frame.php
python3 - <<'PY2'
p = 'agent/src/Frame.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'pct' => max(0, min(100, $percent)),", "            'percent' => max(0, min(100, $percent)),")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Frame.php "Feld umbenannt" &&
pruefe "Feld umbenannt" \
  FrameContractTest::test_a_progress_frame_reaches_the_record failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FrameContractTest passed

echo
echo "── ImportCleanupTest: ein gescheiterter Upload bleibt liegen ──"
#
# Am 9. August lagen so 109 MB einer abgewiesenen Zip-Bombe in der Übergabe, und
# nichts im System hätte sie je wieder angefasst — bis zu 512 MB je Versuch,
# ausgelöst von einem Kunden (docs/36 §22.3w).
vorher_datei app/Support/Databases/DumpLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Databases/DumpLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            Staging::forget($operation->payload['source'] ?? null);", "            // hier stand das Aufräumen")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/DumpLifecycle.php "Upload bleibt liegen" &&
pruefe "Upload bleibt liegen" \
  ImportCleanupTest::test_a_failed_import_removes_the_uploaded_file failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ImportCleanupTest passed

echo
echo "── DumpSizeTest: die Grösse wird nicht mehr verglichen ──"
#
# `bytes` ist die Zahl, die dem Kunden als „Grösse" angezeigt wird. Auf
# cloudsrv24 wich sie bei einer von vier Sicherungen ab, und nichts im System
# hielt die beiden je gegeneinander (docs/36 §22.3w).
vorher_datei app/Support/Databases/DumpIntegrity.php
python3 - <<'PY2'
p = 'app/Support/Databases/DumpIntegrity.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        if (! is_file($path)) {", "        if (false) {")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/DumpIntegrity.php "Grösse nicht verglichen" &&
pruefe "Grösse nicht verglichen" \
  DumpSizeTest::test_a_missing_file_is_reported failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DumpSizeTest passed

echo
echo "── DumpKindTest: die Oberfläche vergleicht die Herkunft wieder selbst ──"
#
# Das Template verglich die Herkunft bis zum 9. August als Zeichenkette — eine
# Grenze zwischen PHP und Browser, die kein Typ prüft. Dieselbe Bauart wie der
# Frame-Fehler daneben (docs/36 §22.3x).
vorher_datei resources/js/Pages/Databases/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('<Badge v-if="dump.kind_label" kind="neutral">{{ dump.kind_label }}</Badge>',
              '<Badge v-if="dump.kind === \'imported\'" kind="neutral">mitgebracht</Badge>')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Show.vue "Herkunft wieder als Wert" &&
pruefe "Herkunft wieder als Wert" \
  DumpKindTest::test_the_interface_gets_the_label_and_not_the_value failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DumpKindTest passed

echo
echo "── DocLinkTest: ein Verweis zeigt wieder ins Leere ──"
#
# Genau der Fehler, der diesen Wächter ausgelöst hat, zurückgedreht: docs/19
# verwies bis zum 9. August 2026 auf 14-bestaetigungen.md — ein Dokument des
# Vorgängers, das mit dem Repo-Übergang entfernt wurde. Der Verweis hat einen
# Lizenzwechsel und einen Neuanfang überlebt, weil niemand ihn geprüft hat.
vorher_datei docs/19-sprache-der-oberflaeche.md
python3 - <<'PY2'
p = 'docs/19-sprache-der-oberflaeche.md'
s = open(p, encoding='utf-8').read()
s = s.replace('[20 §7](20-hostingpanel-neuplan.md)', '[14](14-bestaetigungen.md)')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei docs/19-sprache-der-oberflaeche.md "Verweis auf ein entferntes Dokument" &&
pruefe "Verweis auf ein entferntes Dokument" \
  DocLinkTest::test_every_link_points_at_a_file_that_exists failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DocLinkTest passed

echo
echo "── DocLinkTest: eine Dokumentnummer, die es nicht gibt ──"
#
# Die andere Schreibweise, und die häufigere: `docs/36` ohne Verweisklammern.
# ChangelogTest prüft sie im CHANGELOG, aber nirgends sonst — und der Verweis,
# der P5b ausgelöst hat, stand in einem Dokument.
#
# **Die Nummer war einmal `docs/39`, und der Bruch hat aufgehört zu beissen,
# als es dieses Dokument gab** — geschrieben am 10. August 2026, im selben
# Wurf. Ein Bruch, der eine Lücke im Bestand ausnutzt, hält genau so lange wie
# die Lücke.
#
# > **Ein Bruch, dessen Gegenstand von aussen kommt, wird von aussen repariert
# > — und meldet das nicht.**
#
# `docs/99` ist keine Lücke, sondern ausserhalb: Diese Nummer wird es nicht
# geben, und wer sie doch vergibt, liest hier, warum das eine schlechte Idee
# ist.
vorher_datei docs/38-postgresql.md
python3 - <<'PY2'
p = 'docs/38-postgresql.md'
s = open(p, encoding='utf-8').read()
s = s.replace('im Zuschnitt von `docs/36 §12`', 'im Zuschnitt von `docs/99 §12`')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei docs/38-postgresql.md "Nummer eines Dokuments, das es nicht gibt" &&
pruefe "Nummer eines Dokuments, das es nicht gibt" \
  DocLinkTest::test_every_document_mentioned_by_number_exists failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DocLinkTest passed

echo
echo "── PgNameTest: das Präfix haengt wieder am Abonnement ──"
#
# Die Abschottung von P5b ist der Name selbst: PostgreSQL zeigt jedem Kunden
# die Namen aller Datenbanken (gemessen, docs/38 §2.2), und `p1002_shop`
# verriete damit, dass es ein Abonnement 1002 mit einem Shop gibt. Der Waechter
# prueft die Form statt des Ergebnisses — eine Methode ohne Parameter kann aus
# keinem Wert des Abonnements etwas ableiten.
vorher_datei agent/src/Pg/Names.php
python3 - <<'PY2'
p = 'agent/src/Pg/Names.php'
s = open(p, encoding='utf-8').read()
s = s.replace('    public static function newPrefix(): string', '    public static function newPrefix(string $systemUser = \'p1001\'): string')
s = s.replace("return 'x'.bin2hex(random_bytes(8));", "return 'x'.substr(hash('sha256', $systemUser), 0, 16);")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Names.php "Praefix aus dem Abonnement abgeleitet" &&
pruefe "Praefix aus dem Abonnement abgeleitet" \
  PgNameTest::test_a_prefix_cannot_be_derived_from_anything failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgNameTest passed

echo
echo "── PgShieldingTest: pg_database wird doch entzogen ──"
#
# Der Entzug schloesse das Aufzaehlen vollstaendig — und nimmt dem KUNDEN
# pg_dump, auch fuer den schlichten Export seiner eigenen Datenbank (gemessen,
# docs/38 §2.2 M6). Wer die Ausnahme streicht, tauscht einen Sicherheitsgewinn
# gegen einen Datenverlust.
vorher_datei agent/src/Pg/Shielding.php
python3 - <<'PY2'
p = 'agent/src/Pg/Shielding.php'
s = open(p, encoding='utf-8').read()
s = s.replace('            if (isset(self::EXEMPT[$channel])) {', '            if (false) {')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Shielding.php "pg_database doch entzogen" &&
pruefe "pg_database doch entzogen" \
  PgShieldingTest::test_pg_database_stays_readable_with_a_reason failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgShieldingTest passed

echo
echo "── PgShieldingTest: die Kanalliste wird wieder verdrahtet ──"
#
# Sie ist fassungsabhaengig — PG 17 hat mehr pg_stat_progress_* als PG 14, und
# die Zielplattformen spannen 14 bis 17. Eine feste Liste ist auf der naechsten
# Fassung unvollstaendig, und unvollstaendig heisst hier: ein offener Kanal.
vorher_datei agent/src/Pg/Shielding.php
python3 - <<'PY2'
p = 'agent/src/Pg/Shielding.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        foreach ($channels as $channel) {', "        foreach (array_merge($channels, ['pg_stat_replication']) as $channel) {")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Shielding.php "Kanalliste verdrahtet" &&
pruefe "Kanalliste verdrahtet" \
  PgShieldingTest::test_the_channels_are_asked_for_and_not_written_down failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgShieldingTest passed

echo
echo "── AgentIdentityTest: runuser auf die Positivliste ──"
#
# Der erste Entwurf von docs/38 §6 wollte einen Kennungswechsel, weil
# PostgreSQL Unix-Kennungen auf Rollen abbildet und root keine ist. Gemessen
# wurde beides und beides faellt: proc_open kennt keinen Wechsel, und der Weg
# ueber pcntl_fork legt die Ausgabe unzuverlaessig ab. Gebraucht wird er auch
# nicht — eine Rolle namens root reicht.
vorher_datei agent/src/Runner.php
python3 - <<'PY2'
p = 'agent/src/Runner.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        'psql' => '/usr/bin/psql',", "        'runuser' => '/usr/sbin/runuser',\n        'psql' => '/usr/bin/psql',")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Runner.php "runuser auf der Positivliste" &&
pruefe "runuser auf der Positivliste" \
  AgentIdentityTest::test_no_program_on_the_allowlist_switches_identity failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AgentIdentityTest passed

echo
echo "── PgSessionTest: der Lauf meldet wieder Erfolg, wenn SQL scheitert ──"
#
# psql -f gibt bei gescheitertem SQL 0 zurueck und arbeitet weiter — am
# 9. August nebeneinander gemessen. mysql bricht von selbst ab; genau darauf
# ruht der Beleg von Kriterium 6 in P5. Ohne den Schalter waere ein
# vollstaendig gescheitertes Zurueckspielen als „erledigt" gemeldet worden.
vorher_datei agent/src/Pg/Session.php
python3 - <<'PY2'
p = 'agent/src/Pg/Session.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'-v', 'ON_ERROR_STOP=1'", "'-v', 'AUTOCOMMIT=on'")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Session.php "Lauf ohne ON_ERROR_STOP" &&
pruefe "Lauf ohne ON_ERROR_STOP" \
  PgSessionTest::test_every_call_carries_on_error_stop failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgSessionTest passed

echo
echo "── PgGrantTest: die Rolle bekommt CREATEDB ──"
#
# Ein Kunde mit CREATEDB legt Datenbanken an, die im Bestand des Panels fehlen
# und deren Absperrung nie gelaufen ist — der Kanal, den docs/38 §3 schliesst,
# waere damit an jeder selbst angelegten Datenbank wieder offen.
vorher_datei agent/src/Ops/PgRoleCreate.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgRoleCreate.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'CREATE ROLE %s WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION '",
              "'CREATE ROLE %s WITH LOGIN NOSUPERUSER CREATEDB NOCREATEROLE NOINHERIT NOREPLICATION '")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgRoleCreate.php "Rolle mit CREATEDB" &&
pruefe "Rolle mit CREATEDB" \
  PgGrantTest::test_no_statement_grants_anything_cluster_wide failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgGrantTest passed

echo
echo "── PgGrantTest: die Freigabe vergisst die Zähler ──"
#
# Hier stand ein Bruch fuer `ALTER DEFAULT PRIVILEGES … GRANT ALL ON TABLES`,
# mit der Begruendung, ohne die Zeile saehe ein zweiter Zugang die Tabellen des
# ersten nicht. **Die Zeile hat das nie geleistet** — ohne `FOR ROLE` gilt sie
# nur fuer Objekte, die der Agent selbst anlegt (gemessen, 10. August 2026). Der
# Bruch hat also zwei Fassungen lang eine Wirkung geprueft, die es nicht gab.
# Was das Problem loest, bricht `PgOwnerTest` weiter unten.
#
# Geblieben ist die Ebene, die wirklich traegt: Ein Zugang ohne Recht an den
# Zaehlern bekommt `permission denied for sequence` beim ersten INSERT in eine
# Tabelle mit `serial` — und das ist die Haelfte aller Tabellen.
vorher_datei agent/src/Ops/PgRoleGrant.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgRoleGrant.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO '.$name,\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgRoleGrant.php "Freigabe ohne Zaehler" &&
pruefe "Freigabe ohne Zaehler" \
  PgGrantTest::test_a_grant_reaches_database_schema_and_objects failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgGrantTest passed

echo
echo "── PgGrantTest: der Rückbau wartet auf offene Verbindungen ──"
#
# Ohne WITH (FORCE) scheitert DROP DATABASE an jedem Kunden mit einem
# Verbindungspool — gemessen am 9. August: „database is being accessed by other
# users". MariaDB kennt das nicht.
vorher_datei agent/src/Ops/PgDatabaseRemove.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgDatabaseRemove.php'
s = open(p, encoding='utf-8').read()
s = s.replace(".' WITH (FORCE)'", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgDatabaseRemove.php "Rückbau ohne FORCE" &&
pruefe "Rückbau ohne FORCE" \
  PgGrantTest::test_dropping_a_database_does_not_wait_for_idle_connections failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgGrantTest passed

echo
echo "── PgClusterTest: das Datenverzeichnis um ein Feld verfehlt ──"
#
# Genau der Fehler, den es gab: Feld 4 ist der Eigentümer und nicht das
# Datenverzeichnis. Gefunden hat ihn kein Lesen, sondern ein Lauf gegen das
# echte Werkzeug — ein Cluster mit dem Datenverzeichnis `postgres` sieht in
# einer Ablage nicht falsch aus. Seit `parse()` eine Zeichenkette nimmt, ist er
# ohne PostgreSQL zu haben.
vorher_datei agent/src/Pg/Clusters.php
python3 - <<'PY2'
p = 'agent/src/Pg/Clusters.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'directory' => $fields[5],", "'directory' => $fields[4],")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Clusters.php "Datenverzeichnis ist Feld 4" &&
pruefe "Datenverzeichnis ist Feld 4" \
  PgClusterTest::test_the_data_directory_is_the_sixth_field failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgClusterTest passed

echo
echo "── PgClusterTest: fast-online gilt als laufend ──"
#
# Ein Cluster mitten im Start antwortet nicht. Wer `str_contains` statt `===`
# nimmt, hält ihn für erreichbar — und die nächste Operation läuft in eine
# Verbindung, die es nicht gibt.
vorher_datei agent/src/Pg/Clusters.php
python3 - <<'PY2'
p = 'agent/src/Pg/Clusters.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'running' => $fields[3] === 'online',", "'running' => str_contains($fields[3], 'online'),")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Clusters.php "online mit Beiwerk gilt als laufend" &&
pruefe "online mit Beiwerk gilt als laufend" \
  PgClusterTest::test_only_online_counts_as_running failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgClusterTest passed

echo
echo "── PgServerStateTest: ein achter Zustand, den niemand beantwortet ──"
#
# Der Fehlschlag, um den es geht, ist nicht der Tippfehler. Es ist der Zustand,
# den jemand hinzufügt und in PgServerInstall vergisst: Dort fällt er in den
# `default`-Zweig, und der heisst „PostgreSQL ist da, es ist nichts zu tun".
vorher_datei agent/src/Pg/Server.php
python3 - <<'PY2'
p = 'agent/src/Pg/Server.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'state' => 'no_cluster',", "'state' => 'cluster_missing',")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Server.php "unbekannter Zustand" &&
pruefe "unbekannter Zustand" \
  PgServerStateTest::test_the_vocabulary_is_the_documented_one failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgServerStateTest passed

echo
echo "── PgServerStateTest: der Installierer schweigt zu einem Zustand ──"
#
# Die Gegenrichtung desselben Wächters — hier bleibt die Aufzählung heil und
# der Handgriff verschwindet.
vorher_datei agent/src/Ops/PgServerInstall.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgServerInstall.php'
s = open(p, encoding='utf-8').read()
s = s.replace('not_handed_over', 'noch_nicht_uebergeben')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgServerInstall.php "Installierer kennt einen Zustand nicht" &&
pruefe "Installierer kennt einen Zustand nicht" \
  PgServerStateTest::test_every_state_is_answered_by_the_installer failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgServerStateTest passed

echo
echo "── PgServerStateTest: ein Knopf, der nur eine Fehlermeldung auslöst ──"
#
# ACTIONABLE ist die Liste, aus der die Oberfläche liest, ob sie den Knopf
# zeigt. Steht `no_cluster` darin, weist der Agent ab — und der Betreiber
# drückt auf etwas, dessen einzige Wirkung eine rote Zeile ist.
vorher_datei agent/src/Ops/PgServerInstall.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgServerInstall.php'
s = open(p, encoding='utf-8').read()
s = s.replace("public const ACTIONABLE = ['absent', 'stopped'];",
              "public const ACTIONABLE = ['absent', 'stopped', 'no_cluster'];")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgServerInstall.php "Knopf für einen abgewiesenen Zustand" &&
pruefe "Knopf für einen abgewiesenen Zustand" \
  PgServerStateTest::test_the_actionable_states_are_states_that_act failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgServerStateTest passed

echo
echo "── EngineExtensionTest: ein System ohne Weg vom PHP des Kunden ──"
#
# Genau der Zustand, den P5b drei Beiträge lang hatte: DatabaseEngine kennt
# `postgres`, die Erweiterungsliste des Agenten kennt kein `pgsql`. Der Kunde
# bekommt seine Datenbank und keine Verbindung dazu — im Panel sieht alles grün
# aus, und die Website antwortet „could not find driver".
vorher_datei agent/src/PhpVersions.php
python3 - <<'PY2'
p = 'agent/src/PhpVersions.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'fpm', 'mysql', 'pgsql', 'xml'", "'fpm', 'mysql', 'xml'")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/PhpVersions.php "Datenbanksystem ohne PHP-Erweiterung" &&
pruefe "Datenbanksystem ohne PHP-Erweiterung" \
  EngineExtensionTest::test_every_engine_has_its_php_extension_installed_with_every_version failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" EngineExtensionTest passed

echo
echo "── PhpExtensionTest: der Paketzustand wird nicht gelesen ──"
#
# Ein Paket mit `config-files` ist entfernt und seine Konfiguration liegt noch
# da; `half-installed` ist ein abgebrochener Lauf. Beides ist nicht benutzbar.
# Wer nur nach dem Namen sucht statt nach dem Zustand, hält beide für in
# Ordnung — und lässt den Kunden mit einer Erweiterung zurück, die es nicht gibt.
vorher_datei agent/src/PhpVersions.php
python3 - <<'PY2'
p = 'agent/src/PhpVersions.php'
s = open(p, encoding='utf-8').read()
s = s.replace("if (count($fields) < 2 || $fields[1] !== 'installed') {", "if (count($fields) < 1) {")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/PhpVersions.php "Paketzustand wird nicht gelesen" &&
pruefe "Paketzustand wird nicht gelesen" \
  PhpExtensionTest::test_only_installed_counts_as_present failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PhpExtensionTest passed

echo
echo "── PhpExtensionTest: die Frage passt nicht mehr zur Auswertung ──"
#
# Ein anderes -f ergibt einen Parser, der nichts findet — und „nichts gefunden"
# heisst hier „alles fehlt", also apt-get bei jedem Aufruf. Lautlos, weil ein
# Lauf, der zu viel installiert, wie ein erfolgreicher aussieht.
vorher_datei agent/src/PhpVersions.php
python3 - <<'PY2'
p = 'agent/src/PhpVersions.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'-f=${binary:Package} ${db:Status-Status}\\n'", "'-f=${db:Status-Status} ${binary:Package}\\n'")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/PhpVersions.php "Frage und Auswertung getrennt" &&
pruefe "Frage und Auswertung getrennt" \
  PhpExtensionTest::test_the_query_asks_for_the_two_fields_it_reads failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PhpExtensionTest passed

echo
echo "── PgClusterTest: die Sammelunit statt der Instanz ──"
#
# Genau der Fehler, den der Betreiber am 9. August auf cloudsrv24 gefunden hat:
# postgresql.service startet die Instanzen und bleibt mit RemainAfterExit auf
# active stehen, auch wenn kein Cluster mehr laeuft. Die Uebersicht meldete
# gruen, waehrend der Dienst stand — an der Stelle, an der jemand nach
# Stoerungen sucht.
vorher_datei agent/src/Pg/Clusters.php
python3 - <<'PY2'
p = 'agent/src/Pg/Clusters.php'
s = open(p, encoding='utf-8').read()
s = s.replace("return sprintf('postgresql@%d-%s.service', $version, $name);",
              "return 'postgresql.service';")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Clusters.php "Sammelunit statt Instanz" &&
pruefe "Sammelunit statt Instanz" \
  PgClusterTest::test_the_unit_is_the_instance_and_not_the_collective failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgClusterTest passed

echo
echo "── FactoryDefaultTest: eine Spalte, die die Factory nicht baut ──"
#
# Genau der Fehler aus Lauf 463. `engine` traegt `default('mariadb')` in der
# Migration und steht im Modell als `@property DatabaseEngine` — ohne null. Die
# Factory setzte sie nicht, und ein `default` gilt beim INSERT: Das Modell im
# Speicher trug null, und `Databases::remove()` gab es an einen `match` weiter.
# Vier rote Tests, und keiner davon zeigte auf die Factory.
vorher_datei database/factories/DatabaseFactory.php
python3 - <<'PY2'
p = 'database/factories/DatabaseFactory.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'engine' => DatabaseEngine::MariaDb,\n\n            'status' => DatabaseStatus::Active,",
              "            'status' => DatabaseStatus::Active,")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei database/factories/DatabaseFactory.php "Spalte fehlt in der Factory" &&
pruefe "Spalte fehlt in der Factory" \
  FactoryDefaultTest::test_every_required_enum_column_is_built_by_its_factory failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FactoryDefaultTest passed

echo
echo "── EngineScopeTest: ein Lebenslauf fasst das andere System an ──"
#
# Beide Lebenslaeufe hoeren auf `subscription.suspend`. Faellt die Einschraenkung
# auf `engine`, schickt PgLifecycle die MariaDB-Zugaenge desselben Abonnements
# als Rollennamen an `pg.role.lock` — und ueberschreibt danach einen Zustand, den
# ein anderer Vorgang gerade gesetzt hat. Sichtbar wird das erst, wenn einer der
# beiden scheitert.
vorher_datei app/Support/Databases/PgLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Databases/PgLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                ->where('engine', DatabaseEngine::Postgres)\n                ->orderBy('id')",
              "                ->orderBy('id')")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/PgLifecycle.php "Abfrage ohne System" &&
pruefe "Abfrage ohne System" \
  EngineScopeTest::test_every_subscription_wide_query_names_its_engine failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" EngineScopeTest passed

echo
echo "── PgRestoreTest: psql laeuft ohne ON_ERROR_STOP ──"
#
# Der teuerste Schalter in P5b. `psql -f` gibt bei gescheitertem SQL 0 zurueck
# und arbeitet weiter — gemessen an vier Anweisungen, deren dritte abgewiesen
# wurde: Rueckgabewert 0, und die vierte lief. Ein Zurueckspielen, das
# vollstaendig scheitert, meldete dann „erledigt". mysql macht es von selbst
# richtig; wer aus P5 abschreibt, schreibt eine Vorsicht ab, die dort in der
# Abwesenheit eines Schalters lag.
vorher_datei agent/src/Pg/Session.php
python3 - <<'PY2'
p = 'agent/src/Pg/Session.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                    '-v', 'ON_ERROR_STOP=1',\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Session.php "Zurueckspielen ohne Abbruch" &&
pruefe "Zurueckspielen ohne Abbruch" \
  PgRestoreTest::test_the_restore_stops_at_the_first_error failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgRestoreTest passed

echo
echo "── PgRestoreTest: der DEFINER-Filter laeuft ueber PostgreSQL-Daten ──"
#
# Die Gegenrichtung, und sie ist die wichtigere. pg_dump schreibt keine
# DEFINER-Angaben (gemessen: null Treffer). Ein Filter, der trotzdem ueber jede
# Zeile laeuft, kommt an alles, was ein Kunde gespeichert hat — docs/36 §10.1
# haelt fest, was ein zu breites Suchen-und-Ersetzen in einem Dump anrichtet.
vorher_datei agent/src/Ops/PgDumpCreate.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgDumpCreate.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$bytes = Dump::compress($raw, $target, fn (): bool => $context->abandoned());",
              "$bytes = Dump::compress($raw, $target, fn (): bool => $context->abandoned(), Dump::withoutDefiner(...));")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgDumpCreate.php "Filter ueber fremde Daten" &&
pruefe "Filter ueber fremde Daten" \
  PgRestoreTest::test_the_postgres_dump_is_written_through_unchanged failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgRestoreTest passed

echo
echo "── PgRestoreTest: die pg_hba-Zeile steht unter der peer-Zeile ──"
#
# pg_hba.conf wird von oben nach unten gelesen, und die erste passende Zeile
# entscheidet — auch wenn sie abweist. Unter `local all all peer` kaeme die neue
# nie zum Zug, und die befristete Rolle bliebe draussen. Dieselbe Falle wie eine
# Firewall-Regel hinter einem DROP.
vorher_datei agent/src/Pg/Hba.php
python3 - <<'PY2'
p = 'agent/src/Pg/Hba.php'
s = open(p, encoding='utf-8').read()
s = s.replace('return self::MARK."\\n".self::RULE."\\n\\n".$content;',
              'return $content."\\n".self::MARK."\\n".self::RULE."\\n";')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Hba.php "Zeile am Ende statt am Anfang" &&
pruefe "Zeile am Ende statt am Anfang" \
  PgRestoreTest::test_the_rule_goes_above_the_existing_ones failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgRestoreTest passed

echo
echo "── PgRestoreTest: eine zweite Umgebungsvariable ──"
#
# Der Runner hat mit P5b zum ersten Mal ueberhaupt eine Ergaenzung seiner festen
# Umgebung bekommen. Eine Umgebung ist dieselbe Angriffsflaeche wie eine
# Kommandozeile: LD_PRELOAD laedt fremden Code in einen Prozess, der als root
# laeuft. Die Liste bleibt kurz, oder sie ist keine.
vorher_datei agent/src/Runner.php
python3 - <<'PY2'
p = 'agent/src/Runner.php'
s = open(p, encoding='utf-8').read()
s = s.replace("public const ENVIRONMENT_ALLOWED = ['PGPASSFILE'];",
              "public const ENVIRONMENT_ALLOWED = ['PGPASSFILE', 'LD_PRELOAD'];")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Runner.php "zweite Umgebungsvariable" &&
pruefe "zweite Umgebungsvariable" \
  PgRestoreTest::test_the_environment_stays_an_allowlist failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgRestoreTest passed

echo
echo "── RemovalPathTest: eine Sicherung ohne Weg zurueck ──"
#
# Mit Schritt 6 gibt es pg.dump.create und kein pg.dump.remove — der Weg zurueck
# heisst db.dump.remove und gilt fuer beide Systeme, weil er eine Datei
# entfernt. Faellt die Begruendung aus der Liste, meldet der Waechter genau das,
# wofuer er seit docs/35 da ist: etwas laesst sich anlegen und nirgends loeschen.
vorher_datei tests/Feature/RemovalPathTest.php
python3 - <<'PY2'
p = 'tests/Feature/RemovalPathTest.php'
s = open(p, encoding='utf-8').read()
start = s.index("        'pg.dump.create' =>")
ende = s.index("        'pg.dump.import' =>")
s = s[:start] + s[ende:]
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Feature/RemovalPathTest.php "Sicherung ohne Weg zurueck" &&
pruefe "Sicherung ohne Weg zurueck" \
  RemovalPathTest::test_every_creating_operation_has_a_removing_one failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RemovalPathTest passed

echo
echo "── ReleaseVersionTest: die Fassung kommt wieder aus einer Umgebungsvariable ──"
#
# Der Zustand, der zwei Jahre ausgeliefert war. `SRVPANEL_VERSION` wird nirgends
# gesetzt — nicht im Paket, nicht in der Einrichtung, nicht in der `.env` —, und
# die Marke im Menue nannte deshalb den Vorgabewert. Die Zeile sieht in jeder
# Durchsicht harmlos aus; falsch wird sie erst dadurch, dass niemand die
# Variable setzt, und das sieht man ihr nicht an.
vorher_datei config/app.php
python3 - <<'PY2'
p = 'config/app.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'version' => Release::version(),",
              "'version' => env('SRVPANEL_VERSION', '0.1.0-dev'),")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei config/app.php "Fassung aus der Umgebung" &&
pruefe "Fassung aus der Umgebung" \
  ReleaseVersionTest::test_the_version_does_not_come_from_an_unset_variable failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ReleaseVersionTest passed

echo
echo "── ReleaseVersionTest: jedes Verzeichnis gilt als Fassung ──"
#
# Die Gegenrichtung, und sie ist die leisere. Ein zu weites Muster meldet keinen
# Fehler — es nennt den Namen des Verzeichnisses, in dem die Anwendung gerade
# liegt, und im Quellbaum heisst das `Server-Control-Panel`. Ein Fehlerbericht
# traegt dann eine Angabe, die aussieht wie eine Fassung und keine ist.
vorher_datei app/Support/Panel/Release.php
python3 - <<'PY2'
p = 'app/Support/Panel/Release.php'
s = open(p, encoding='utf-8').read()
s = s.replace("private const PATTERN = '/^\\d+\\.\\d+\\.\\d+(-[a-z]+(\\.\\d+)?)?$/D';",
              "private const PATTERN = '/./D';")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Panel/Release.php "Muster ohne Form" &&
pruefe "Muster ohne Form" \
  ReleaseVersionTest::test_a_release_directory_names_its_version failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ReleaseVersionTest passed

echo
echo "── SourceLinkTest: die Fusszeile faellt wieder auf das Repository zurueck ──"
#
# Der Zustand, den die AGPL nicht meint. Ohne den Rueckgriff auf den Tag der
# Freigabe haengt der Quelltext-Link allein an SRVPANEL_COMMIT — und die setzt
# niemand. Der Bruch nimmt die Zeile heraus; danach zeigt jede Fusszeile auf
# main statt auf den Stand, der laeuft.
vorher_datei app/Support/Panel/Source.php
python3 - <<'PY2'
p = 'app/Support/Panel/Source.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        return $repository.'/tree/v'.$version;", "        return $repository;")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Panel/Source.php "Fusszeile ohne Tag" &&
pruefe "Fusszeile ohne Tag" \
  SourceLinkTest::test_without_a_commit_the_release_tag_carries_it failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SourceLinkTest passed

echo
echo "── SourceLinkTest: die Vorlage baut die Adresse wieder selbst ──"
#
# Die Regel stand bis zum 10. August 2026 genau so im Template, als Bedingung
# ueber den Commit. Zwei Fassungen derselben Entscheidung, und die im Template
# ist die, die beim naechsten Umbau stehen bleibt — gemerkt hat es niemand,
# weil sie ja funktionierte, nur eben immer im selben Zweig.
vorher_datei resources/js/Layouts/PanelLayout.vue
python3 - <<'PY2'
p = 'resources/js/Layouts/PanelLayout.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(':href="source.url"',
              ':href="source.commit ? `${source.repository}/tree/${source.commit}` : source.repository"')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Layouts/PanelLayout.vue "Adresse in der Vorlage" &&
pruefe "Adresse in der Vorlage" \
  SourceLinkTest::test_the_template_only_shows_what_the_server_decided failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SourceLinkTest passed

echo
echo "── PgHandoverTest: der Vorgabewert wird wieder als Messung gelesen ──"
#
# Der Fund aus Punkt 2 der Zwischenabnahme (docs/39), gefunden auf einem Bild.
# `handed_over` stand im Grundzustand auf false, und drei der sieben Zustaende
# ueberschreiben es nie — sie kommen gar nicht dazu, sich anzumelden. Bei
# gestopptem Cluster zeigte die Seite daraufhin „Rolle anlegen" mit einem
# Befehl, der genau dort nicht laufen kann.
vorher_datei agent/src/Pg/Server.php
python3 - <<'PY2'
p = 'agent/src/Pg/Server.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'handed_over' => null,", "            'handed_over' => false,")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Server.php "Vorgabewert als Messung" &&
pruefe "Vorgabewert als Messung" \
  PgHandoverTest::test_the_default_is_not_an_answer failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgHandoverTest passed

echo
echo "── PgHandoverTest: die Seite prueft wieder auf Falschheit ──"
#
# Die leisere Haelfte, und die, die den Fehler zurueckbringt, ohne dass am
# Agenten etwas falsch waere: `!handed_over` ist in JavaScript fuer null UND
# fuer false wahr. Die Bedingung sieht richtig aus.
vorher_datei resources/js/Pages/Settings/Database.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Settings/Database.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('props.postgresql.handed_over === false',
              '!props.postgresql.handed_over && props.postgresql.reachable')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Settings/Database.vue "Seite prueft auf Falschheit" &&
pruefe "Seite prueft auf Falschheit" \
  PgHandoverTest::test_the_page_distinguishes_unknown_from_no failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgHandoverTest passed

echo
echo "── EngineDefaultTest: der Vorgabewert fuer das Datenbanksystem kehrt zurueck ──"
#
# Der teuerste Fund der Zwischenabnahme (docs/39, Punkt 3): Databases::createUser()
# hatte `= DatabaseEngine::MariaDb`, und der Steuerungscode liess das Argument
# weg — jeder Zugang zu einer PostgreSQL-Datenbank entstand damit in MariaDB.
# Der eigentliche Waechter ist der Uebersetzer; dieser Bruch prueft, dass die
# Vorgabe nicht zurueckkommt.
vorher_datei app/Support/Databases/Databases.php
python3 - <<'PY2'
p = 'app/Support/Databases/Databases.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""        DatabaseEngine $engine,
    ): array {""",
              """        DatabaseEngine $engine = DatabaseEngine::MariaDb,
    ): array {""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/Databases.php "Vorgabewert fuer das System" &&
pruefe "Vorgabewert fuer das System" \
  EngineDefaultTest::test_no_method_guesses_the_engine failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" EngineDefaultTest passed

echo
echo "── EngineDefaultTest: der Zugang bekommt einen festen Wert ──"
#
# Die Gegenrichtung. Ohne sie liesse sich derselbe Fehler wiederholen, indem
# der Steuerungscode `DatabaseEngine::MariaDb` einsetzt statt zu fragen, woran
# der Zugang haengt — die Signatur waere zufrieden, das Ergebnis dasselbe.
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                $database->engine,", "                DatabaseEngine::MariaDb,")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "fester Wert statt Frage" &&
pruefe "fester Wert statt Frage" \
  EngineDefaultTest::test_the_access_follows_its_database failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" EngineDefaultTest passed

echo
echo "── EngineCollationTest: der Ersatzwert fuer die Sortierung kehrt zurueck ──"
#
# Der Fehler, an dem P5b auf dem Server haengengeblieben ist (docs/39, Punkt 3):
# `?? $this->collations()[0]` schob PostgreSQL die erste MariaDB-Sortierung als
# LC_COLLATE unter, und keine PostgreSQL-Datenbank liess sich anlegen.
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                $data['collation'] ?? null,",
              "                (string) ($data['collation'] ?? $this->collations()[0]),")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "Ersatzwert fuer die Sortierung" &&
pruefe "Ersatzwert fuer die Sortierung" \
  EngineCollationTest::test_the_controller_invents_nothing failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" EngineCollationTest passed

echo
echo "── EngineCollationTest: Postgres schickt wieder ein Gebietsschema ──"
#
# Die Richtung, die zaehlt. Was in dieser Nutzlast landet, kann nur aus dem
# Formular stammen — und das Formular fragt fuer PostgreSQL nicht danach.
vorher_datei app/Support/Databases/Engines/PostgresDriver.php
python3 - <<'PY2'
p = 'app/Support/Databases/Engines/PostgresDriver.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'encoding' => 'UTF8',\n        ]);",
              "            'encoding' => 'UTF8',\n            'locale' => $collation,\n        ]);")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/Engines/PostgresDriver.php "Gebietsschema an Postgres" &&
pruefe "Gebietsschema an Postgres" \
  EngineCollationTest::test_postgres_sends_no_locale failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" EngineCollationTest passed

echo
echo "── StreamNotAPageTest: der Ereigniskanal wird wieder eine Seite ──"
#
# Der Fehler, der die Zwischenabnahme eine Stunde gekostet hat (docs/39): Laravel
# merkt sich jede GET-Anfrage als „vorige Seite", und ValidationException leitet
# dorthin zurueck. Ohne die Kennzeichnung landet jeder Formularfehler des Panels
# auf dem Vorgangskanal, sobald irgendwo ein Vorgang laeuft.
vorher_datei app/Http/Middleware/KeepPreviousUrl.php
python3 - <<'PY2'
p = 'app/Http/Middleware/KeepPreviousUrl.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $request->headers->set('X-Requested-With', 'XMLHttpRequest');\n\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Middleware/KeepPreviousUrl.php "Kanal wird wieder eine Seite" &&
pruefe "Kanal wird wieder eine Seite" \
  StreamNotAPageTest::test_the_stream_does_not_look_like_a_page failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" StreamNotAPageTest passed

echo
echo "── StreamNotAPageTest: die Kennzeichnung steht eine Zeile zu spaet ──"
#
# storeCurrentUrl() laeuft auch dann, wenn can: abweist. Steht die
# Kennzeichnung dahinter, kapert eine 403 auf dem Kanal weiterhin das „Zurueck"
# der naechsten Formularseite — dieselbe Sorte Fehler wie die Kettenreihenfolge
# im Abnahmelauf von P4.
vorher_datei routes/web.php
python3 - <<'PY2'
p = 'routes/web.php'
s = open(p, encoding='utf-8').read()
s = s.replace("->middleware([KeepPreviousUrl::class, 'can:view,operation'])",
              "->middleware(['can:view,operation', KeepPreviousUrl::class])")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei routes/web.php "Kennzeichnung zu spaet" &&
pruefe "Kennzeichnung zu spaet" \
  StreamNotAPageTest::test_the_route_carries_it_before_the_policy failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" StreamNotAPageTest passed

echo
echo "── PgLocaleTest: das Gebietsschema wird wieder verdrahtet ──"
#
# Der fuenfte Fehler derselben Bauform an einem Tag, und er stand in der
# Behebung des vierten: Seit das Panel kein Gebietsschema mehr schickt, griff
# `?? 'C.UTF-8'` — und C.UTF-8 sortiert nach Bytes. Auf cloudsrv24 bekam die
# erste Kundendatenbank so eine andere Sortierung als der ganze Rest des
# Servers.
vorher_datei agent/src/Ops/PgDatabaseCreate.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgDatabaseCreate.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$args['locale'] ?? $this->clusterLocale($context)", "$args['locale'] ?? 'C.UTF-8'")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgDatabaseCreate.php "Gebietsschema verdrahtet" &&
pruefe "Gebietsschema verdrahtet" \
  PgLocaleTest::test_the_locale_is_not_a_literal failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgLocaleTest passed

echo
echo "── PgLocaleTest: LC_COLLATE faellt aus der Anweisung ──"
#
# Die Untergrenze. Ohne sie waere der Waechter oben zufrieden, und die Datenbank
# bekaeme das Gebietsschema der Vorlage — hier zufaellig dasselbe, bis jemand
# template0 aendert.
vorher_datei agent/src/Ops/PgDatabaseCreate.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgDatabaseCreate.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'CREATE DATABASE %s TEMPLATE template0 ENCODING %s LC_COLLATE %s LC_CTYPE %s'",
              "'CREATE DATABASE %s TEMPLATE template0 ENCODING %s'")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgDatabaseCreate.php "Anweisung ohne LC_COLLATE" &&
pruefe "Anweisung ohne LC_COLLATE" \
  PgLocaleTest::test_the_statement_still_writes_it failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgLocaleTest passed

echo
echo "── EngineCollationTest: die Sortierung wird wieder nach System versteckt ──"
#
# Bis zum 10. August 2026 war das richtig: Fuer PostgreSQL haette der
# Vorgabewert aus P5 in der Zeile gestanden. Seit der Agent das Gebietsschema
# beim Cluster erfragt, ist der Wert gemessen — und Verschweigen ist dann
# schlechter als Zeigen.
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'collation' => ($database->collation ?? '') === '' ? null : $database->collation,",
              "            'collation' => $database->engine === DatabaseEngine::MariaDb ? $database->collation : null,")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "Sortierung nach System versteckt" &&
pruefe "Sortierung nach System versteckt" \
  EngineCollationTest::test_the_page_does_not_hide_the_collation_by_engine failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" EngineCollationTest passed

echo
echo "── InertiaPropsTest: eine Seite liest, was ihr niemand schickt ──"
#
# Der Fund aus Punkt 4 der Zwischenabnahme (docs/39): Databases/Index.vue las
# props.shows_engine seit Schritt 7, und der Steuerungscode hat es nie
# mitgeschickt. In JavaScript ist undefined falsch — die Spalte „System" blieb
# immer aus, und auf cloudsrv24 stand eine PostgreSQL-Datenbank in der Liste,
# ohne dass irgendwo stand, dass sie eine ist. vue-tsc sieht die Vorlage,
# PHPStan sieht das Feld; die Bruecke dazwischen sah niemand.
#
# Gebrochen wird an `creatable` und nicht mehr an `shows_engine`: Die Spalte
# steht seit rc.4 immer da, die Eigenschaft gibt es nicht mehr. Ein Eingriff,
# der auf eine geloeschte Zeile zeigt, ist genau das Muster, gegen das
# BreakScriptTest da ist.
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'creatable' => $this->creatable($request->user()),\n", '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "Seite ohne ihre Eigenschaft" &&
pruefe "Seite ohne ihre Eigenschaft" \
  InertiaPropsTest::test_every_page_gets_the_props_it_declares failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" InertiaPropsTest passed

echo
echo "── KernelStaleTest: ein unlesbares /boot behauptet „aktuell\" ──"
#
# `null` heisst „nicht nachgesehen" und nicht „nein". Derselbe Satz hat am
# 10. August 2026 dreimal Geld gekostet; hier steht er von Anfang an im Code.
# Faellt er, meldet das Panel „der Kernel ist aktuell" fuer einen Server, auf
# dem niemand nachgesehen hat.
vorher_datei agent/src/Ops/SystemInfo.php
python3 - <<'PY2'
p = 'agent/src/Ops/SystemInfo.php'
s = open(p, encoding='utf-8').read()
s = s.replace("if ($images === false || $images === []) {\n            return null;",
              "if ($images === false || $images === []) {\n            return false;")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/SystemInfo.php "unlesbares /boot behauptet etwas" &&
pruefe "unlesbares /boot behauptet etwas" \
  KernelStaleTest::test_an_unreadable_boot_says_nothing failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" KernelStaleTest passed

echo
echo "── KernelStaleTest: die Seite prueft auf Wahrheit statt auf das Ja ──"
#
# `!kernel_stale` waere fuer null UND fuer false wahr — die Bedingung sieht
# richtig aus und behauptet auf jedem Server ohne lesbares /boot einen
# ausstehenden Neustart.
vorher_datei resources/js/Pages/Overview.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Overview.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('props.server.kernel_stale === true', 'props.server.kernel_stale')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Overview.vue "Seite prueft auf Wahrheit" &&
pruefe "Seite prueft auf Wahrheit" \
  KernelStaleTest::test_the_page_distinguishes_unknown_from_current failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" KernelStaleTest passed

echo
echo "── AccountUnlockTest: entsperrt wieder ohne nachzusehen ──"
#
# Der Systembenutzer eines Abonnements hat kein Passwort. `usermod --unlock`
# weigert sich dann und schreibt eine Warnung — bei JEDER Freigabe JEDES
# Abonnements. Ein Hinweis, der immer erscheint, erzieht dazu, die Ausgabe nicht
# zu lesen; gemeldet vom Betreiber aus Vorgang 492 (docs/39, Punkt 6).
vorher_datei agent/src/Ops/SubscriptionResume.php
python3 - <<'PY2'
p = 'agent/src/Ops/SubscriptionResume.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            $secret = ltrim($fields[1] ?? '', '!');\n\n            return $secret !== '' && $secret !== '*';",
              '            return true;')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/SubscriptionResume.php "entsperrt ohne nachzusehen" &&
pruefe "entsperrt ohne nachzusehen" \
  AccountUnlockTest::test_an_account_without_a_password_is_left_alone failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AccountUnlockTest passed

echo
echo "── AccountUnlockTest: das Ablaufdatum rutscht in die Bedingung ──"
#
# Die Untergrenze. `--expiredate` ist die Schranke, die SSH und SFTP pruefen —
# haengt sie am Passwort, bleibt ein freigegebenes Abonnement abgelaufen, und
# zwar auf jedem Server. Aus einer stillen Warnung wuerde eine stille Sperre.
vorher_datei agent/src/Ops/SubscriptionResume.php
python3 - <<'PY2'
p = 'agent/src/Ops/SubscriptionResume.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $args = ['--expiredate', '', $user];", '        $args = [$user];')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/SubscriptionResume.php "Ablaufdatum an einer Bedingung" &&
pruefe "Ablaufdatum an einer Bedingung" \
  AccountUnlockTest::test_the_expiry_is_lifted_unconditionally failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AccountUnlockTest passed

echo
echo "── WebLifecycleTest: die Domain leitet ihre Sperre aus sich selbst ab ──"
#
# Der Zustand einer Domain ist das Ergebnis des letzten Laufs mit genau diesen
# Argumenten. Steht er wieder in den Argumenten, kommt eine einmal gesperrte
# Domain nie zurueck — das Abonnement war frei, die Domain blieb gesperrt.
# Gemeldet vom Betreiber am 10. August 2026.
vorher_datei app/Support/Web/WebLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Web/WebLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            'suspended' => $subscription?->status->usable() === false,",
    "            'suspended' => $domain->status === DomainStatus::Suspended\n"
    "                || $subscription?->status->usable() === false,",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Web/WebLifecycle.php "Sperre aus dem eigenen Zustand" &&
pruefe "Sperre aus dem eigenen Zustand" \
  WebLifecycleTest::test_a_suspended_site_of_an_active_subscription_comes_back failed
pruefe "  … und der ganze Rückweg auch" \
  WebLifecycleTest::test_resuming_a_subscription_frees_its_sites failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" WebLifecycleTest passed

echo
echo "── PgOwnerTest: eine Operation bildet den Namen, statt die Rolle sicherzustellen ──"
#
# Ein Abonnement, das vor dieser Fassung entstanden ist, hat die Eigentuemerrolle
# nicht. Wer nur ihren Namen bildet, schickt ihn an eine Rolle, die es nicht gibt
# — und das faellt erst auf, wenn ein Kunde vor seinen eigenen Daten steht.
vorher_datei agent/src/Ops/PgDatabaseCreate.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgDatabaseCreate.php'
s = open(p, encoding='utf-8').read()
s = s.replace('$owner = $this->owner->adopt($context, $prefix, $database);',
              '$owner = Names::owner($prefix);')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgDatabaseCreate.php "Name statt Rolle" &&
pruefe "Name statt Rolle" \
  PgOwnerTest::test_every_creating_operation_ensures_the_role failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgOwnerTest passed

echo
echo "── PgOwnerTest: das neue Schema wird vergeben, bevor es da ist ──"
#
# `CREATE SCHEMA public` legt ein Schema an, das dem Agenten gehoert. Steht das
# `ALTER SCHEMA … OWNER TO` davor, bezieht es sich auf das Schema, das eine
# Zeile spaeter weggeworfen wird — und nach dem Zurueckspielen gehoert wieder
# alles `root`. Genau der Fehler aus docs/39 Punkt 7, eine Zeile weiter.
vorher_datei agent/src/Pg/Owner.php
python3 - <<'PY2'
p = 'agent/src/Pg/Owner.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""            ['DROP SCHEMA public CASCADE', 'CREATE SCHEMA public'],
            self::schemaStatements($owner),""",
              """            self::schemaStatements($owner),
            ['DROP SCHEMA public CASCADE', 'CREATE SCHEMA public'],""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Owner.php "Eigentuemer vor dem Schema" &&
pruefe "Eigentuemer vor dem Schema" \
  PgOwnerTest::test_the_reset_hands_over_the_new_schema_and_not_the_old failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgOwnerTest passed

echo
echo "── PgOwnerTest: das Eigentum geht wieder an das Panel ──"
#
# `REASSIGN OWNED BY … TO <Eigentuemer der Datenbank>` war die Antwort auf die
# richtige Frage und hat die falsche gegeben: Die Datenbank gehoert dem Panel.
# Der Kunde stand danach vor seinen eigenen Zeilen, und der Vorgang war gruen.
vorher_datei agent/src/Pg/Ephemeral.php
python3 - <<'PY2'
p = 'agent/src/Pg/Ephemeral.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                    sprintf('DROP OWNED BY %s', Sql::identifier($role)),",
              "                    sprintf('REASSIGN OWNED BY %s TO %s', Sql::identifier($role), 'root'),\n"
              "                    sprintf('DROP OWNED BY %s', Sql::identifier($role)),")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Ephemeral.php "Eigentum zurueck ans Panel" &&
pruefe "Eigentum zurueck ans Panel" \
  PgOwnerTest::test_nothing_reassigns_ownership_to_the_panel failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgOwnerTest passed

echo
echo "── PgOwnerTest: erst eingespielt, dann geleert ──"
#
# Beide Aufrufe stehen da, und die Reihenfolge ist die ganze Aussage: So wirft
# das Zurueckspielen seine eigene Arbeit weg — und meldet Erfolg.
vorher_datei agent/src/Ops/PgRestore.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgRestore.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""            $context->progress(40, 'Datenbank leeren');
            $this->session->execute($context, Owner::reset($owner), $database);

""", '')
s = s.replace("""                },
            );
        } finally {""",
              """                },
            );

            $this->session->execute($context, Owner::reset($owner), $database);
        } finally {""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgRestore.php "geleert nach dem Einspielen" &&
pruefe "geleert nach dem Einspielen" \
  PgOwnerTest::test_the_restore_empties_before_it_fills failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgOwnerTest passed

echo
echo "── SubscriptionResumeReachTest: die Freigabe sperrt die Zugänge ──"
#
# `mode` kommt aus der Aufgabe und nie aus der Zeile, die sie aendert — das ist
# die Richtung, die der Web-Lebenslauf verloren hatte. Haengt sie an etwas
# anderem, kommt ein entsperrtes Abonnement mit gesperrten Zugaengen zurueck.
vorher_datei app/Support/Databases/DbLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Databases/DbLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                'mode' => $lock ? 'lock' : 'unlock',", "                'mode' => 'lock',")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/DbLifecycle.php "Freigabe sperrt die Zugaenge" &&
pruefe "Freigabe sperrt die Zugaenge" \
  SubscriptionResumeReachTest::test_resuming_reaches_everything_below_the_subscription failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SubscriptionResumeReachTest passed

echo
echo "── PgOwnerTest: die Sitzungsrolle wird gesetzt, die Datenbank nicht uebereignet ──"
#
# **Das ist der Zustand, den v0.5.1-rc.5 ausgeliefert hat.** `PgRoleCreate`
# setzte `SET role = <praefix>_owner` und liess das Schema, wie es war — der
# Kunde arbeitete fortan als eine Rolle ohne jedes Recht daran. Ausgeloest hat es
# ein Passwortwechsel: `current_schemas()` war `{pg_catalog}`, und jedes
# `CREATE TABLE` endete mit „no schema has been selected to create in".
vorher_datei agent/src/Ops/PgRoleCreate.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgRoleCreate.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            $this->owner->adopt($context, $prefix, $database);\n", '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgRoleCreate.php "Sitzungsrolle ohne Uebereignung" &&
pruefe "Sitzungsrolle ohne Uebereignung" \
  PgOwnerTest::test_whoever_sets_the_session_role_hands_over_the_database failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgOwnerTest passed

echo
echo "── PgOwnerTest: die Uebereignung gibt ein Recht statt des Eigentums ──"
#
# Gemessen: `GRANT ALL ON ALL TABLES` an die Eigentuemerrolle laesst den Kunden
# lesen und scheitert bei `ALTER TABLE` mit „must be owner of table". `ALTER` und
# `DROP` fragen nach dem Eigentuemer — ein Recht ersetzt kein Eigentum.
vorher_datei agent/src/Pg/Owner.php
python3 - <<'PY2'
p = 'agent/src/Pg/Owner.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                'REASSIGN OWNED BY %s TO %s',", "                'GRANT ALL ON ALL TABLES IN SCHEMA public TO %2$s -- %1$s',")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Owner.php "Recht statt Eigentum" &&
pruefe "Recht statt Eigentum" \
  PgOwnerTest::test_the_adoption_takes_ownership_and_not_only_a_privilege failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgOwnerTest passed

echo
echo "── DbCommandReachTest: srvpanel db fragt PostgreSQL nicht mehr ──"
#
# Am Ende des Abnahmelaufs von P5b meldete das Kommando „Nichts liegengeblieben."
# und konnte diese Frage fuer PostgreSQL gar nicht stellen. Punkt 7f musste seine
# Reste von Hand mit `SELECT … FROM pg_roles` zaehlen — neben einem Kommando, das
# genau dafuer da ist.
vorher_datei app/Console/Commands/Databases.php
python3 - <<'PY2'
p = 'app/Console/Commands/Databases.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        $this->reportPostgres($agent);\n', '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/Databases.php "Status ohne PostgreSQL" &&
pruefe "Status ohne PostgreSQL" \
  DbCommandReachTest::test_the_status_asks_both_servers failed
pruefe "  … und die Reste liest auch niemand" \
  DbCommandReachTest::test_both_stale_lists_are_read failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DbCommandReachTest passed

echo
echo "── DbCommandReachTest: der Rückbau schickt alles an MariaDB ──"
#
# Drei feste Operationsnamen, alle drei fuer MariaDB — der Zustand bis P5b. Eine
# liegengebliebene PostgreSQL-Rolle ginge an `db.user.remove`, der Agent wiese sie
# ab, und der ganze Lauf endete rot ueber etwas, das er nie konnte.
vorher_datei app/Console/Commands/Databases.php
python3 - <<'PY2'
p = 'app/Console/Commands/Databases.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                DatabaseEngine::Postgres => ['pg.role.remove', [", "                DatabaseEngine::Postgres => ['db.user.remove', [")
s = s.replace("                DatabaseEngine::Postgres => ['pg.database.remove', [", "                DatabaseEngine::Postgres => ['db.database.remove', [")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/Databases.php "Rückbau ohne PostgreSQL" &&
pruefe "Rückbau ohne PostgreSQL" \
  DbCommandReachTest::test_prune_names_an_operation_for_every_engine failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DbCommandReachTest passed

echo
echo "── DbCommandReachTest: der Plan verschweigt das System ──"
#
# Die Verzweigung liest `$user['engine']`. Fehlt der Schluessel im Plan, gibt es
# keinen Fehlschlag beim Lesen der Datei, sondern einen zur Laufzeit — an dem Tag,
# an dem tatsaechlich etwas liegengeblieben ist.
vorher_datei app/Support/Databases/DatabasePrune.php
python3 - <<'PY2'
p = 'app/Support/Databases/DatabasePrune.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                    'engine' => $row->engine,\n                ])\n                ->all();\n\n            $dumps", "                ])\n                ->all();\n\n            $dumps")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/DatabasePrune.php "Plan ohne System" &&
pruefe "Plan ohne System" \
  DbCommandReachTest::test_the_plan_carries_the_engine failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DbCommandReachTest passed

echo
echo "── BreakScriptTest: das Bruchskript faehrt nirgends von selbst ──"
#
# Es steht seit dem Optik-Rework im Repo und ist als Ganzes nie gelaufen — in der
# Entwicklungsumgebung fehlt `vendor/`. Von Hand und stueckweise gefahren war es
# dreimal in einer Woche fuendig. Ein Werkzeug, das man nur von Hand faehrt,
# faehrt irgendwann niemand mehr.
vorher_datei .github/workflows/waechter.yml
python3 - <<'PY2'
p = '.github/workflows/waechter.yml'
s = open(p, encoding='utf-8').read()
s = s.replace('        run: tests/waechter-brechen.sh\n', '        run: echo "spaeter"\n')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei .github/workflows/waechter.yml "Bruchskript ohne Aufruf" &&
pruefe "Bruchskript ohne Aufruf" \
  BreakScriptTest::test_a_workflow_runs_the_script failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BreakScriptTest passed

echo
echo "── AgentAnswerReachTest: die Speichergrenze meldet ihr Scheitern an niemanden ──"
#
# `DiskQuota::apply()` bricht bei einem gescheiterten `setquota` ausdruecklich
# nicht ab und gibt `enforced: false` mit Grund zurueck. Liest das niemand, steht
# im Panel eine Grenze, die nichts begrenzt — genau so am 10. August 2026 auf
# cloudsrv24: „fertig, 100 %" und daneben „Cannot find mountpoint for device".
vorher_datei app/Support/Subscriptions/Lifecycle.php
python3 - <<'PY2'
p = 'app/Support/Subscriptions/Lifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$quota['enforced']", "$quota['limit_mb']")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Subscriptions/Lifecycle.php "Quota-Antwort ungelesen" &&
pruefe "Quota-Antwort ungelesen" \
  AgentAnswerReachTest::test_every_answer_about_a_failure_is_read failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AgentAnswerReachTest passed

echo
echo "── AgentAnswerReachTest: der Messlauf behaelt fuer sich, dass es keine Quota gibt ──"
#
# `subscription.usage` meldet `available: false` samt Grund, wenn das
# Dateisystem keine Benutzerquota fuehrt. Bis zum 10. August 2026 stand das nur
# im Journal des Timers — die Uebersicht wusste nichts davon, und der Betreiber
# erfuhr es erst beim Anlegen eines Abonnements (docs/41).
vorher_datei app/Console/Commands/MeasureUsage.php
python3 - <<'PY2'
p = 'app/Console/Commands/MeasureUsage.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$result['available']", "$result['measured']")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/MeasureUsage.php "Quota-Zustand ungelesen" &&
pruefe "Quota-Zustand ungelesen" \
  AgentAnswerReachTest::test_every_answer_about_a_failure_is_read failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AgentAnswerReachTest passed

echo
echo "── QuotaActionReachTest: der Knopf haengt wieder allein am gemessenen Fehlschlag ──"
#
# Der Fehler, der diesen Waechter ausgeloest hat, zurueckgedreht: Der Knopf
# „Grenze anwenden" stand unter `enforced === false` — also unter einer
# Messung. `disk_quota_enforced` kam ohne Backfill dazu, und damit hatte jedes
# Abonnement von davor `null`. Auf cloudsrv24 fehlte der Knopf genau den beiden
# Abonnements, fuer die er gebaut worden war.
vorher_datei resources/js/Pages/Subscriptions/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    'const quotaActionable = computed(() => quotaBroken.value || quotaUnknown.value)',
    'const quotaActionable = computed(() => quotaBroken.value)',
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Show.vue "Knopf nur beim gemessenen Fehlschlag" &&
pruefe "Knopf nur beim gemessenen Fehlschlag" \
  QuotaActionReachTest::test_the_button_is_offered_in_every_state_but_yes failed
wiederherstellen

echo
echo "── QuotaActionReachTest: die Route weist ein Abo ohne Auskunft ab ──"
#
# Die andere Haelfte. Ein sichtbarer Knopf, den die Route abweist, ist dieselbe
# Falle wie ein Knopf ohne Recht — nur andersherum. Wer die Route „absichert",
# indem er einen unbekannten Zustand ausschliesst, nimmt genau den Abonnements
# den Weg, die ihn brauchen.
vorher_datei app/Http/Controllers/SubscriptionController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/SubscriptionController.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $audit->success('subscription.quota_reapplied', $subscription, [",
    """        if ($subscription->disk_quota_enforced === null) {
            throw ValidationException::withMessages([
                'subscription' => 'Ueber diese Grenze ist nichts bekannt.',
            ]);
        }

        $audit->success('subscription.quota_reapplied', $subscription, [""",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/SubscriptionController.php "Route weist unbekannten Zustand ab" &&
pruefe "Route weist unbekannten Zustand ab" \
  QuotaActionReachTest::test_a_subscription_without_an_answer_can_apply_its_limit failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" QuotaActionReachTest passed

echo
echo "── NoticeShapeTest: eine Meldung setzt ihre Teile nebeneinander ──"
#
# Der ausgelieferte Fehler aus v0.5.1-rc.7, zurueckgedreht: `.notice` ist eine
# Flexbox ohne `flex-wrap`, und vier direkte Kinder stehen darin in einer Reihe
# statt umzubrechen. Gemessen im Chromium bei 390px: 65px waagerechter
# Ueberlauf. Einzeln lief keine der drei Kennungen ueber — erst zusammen.
vorher_datei resources/js/Pages/Subscriptions/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("""          <span>
            Diese Grenze ist""",
              """            Diese Grenze ist""")
s = s.replace("""            </template>
          </span>
        </p>""",
              """            </template>
        </p>""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Show.vue "Meldung ohne Wickel" &&
pruefe "Meldung ohne Wickel" \
  NoticeShapeTest::test_a_notice_with_more_than_one_child_wraps_its_text failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" NoticeShapeTest passed

echo
echo "── FailureReasonTest: die Begruendung passt wieder in 255 Zeichen ──"
#
# Der Fund aus dem Abnahmelauf von P5b, zurueckgedreht. `operations.message`
# war varchar(255); die Begruendung des Agenten fuer einen abgewiesenen Dump
# ist 260 Zeichen lang. MariaDB wies sie ab, und die PDOException riss genau
# den catch-Zweig mit, der den Fehlschlag festhalten sollte.
#
# **Geprueft wird das Schema und nicht ein Schreibversuch.** Diese Tests laufen
# gegen SQLite, und SQLite legt jede Laenge in eine varchar(255) — ein
# Verhaltenstest waere mit beiden Spalten gruen.
vorher_datei database/migrations/2026_08_11_090000_the_reason_of_a_failure_no_longer_fits_in_255_characters.php
python3 - <<'PY2'
p = 'database/migrations/2026_08_11_090000_the_reason_of_a_failure_no_longer_fits_in_255_characters.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$table->text('message')->nullable()->change();",
              "$table->string('message')->nullable()->change();")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei database/migrations/2026_08_11_090000_the_reason_of_a_failure_no_longer_fits_in_255_characters.php "Spalte fester Laenge" &&
pruefe "Spalte fester Laenge" \
  FailureReasonTest::test_the_column_is_wide_enough_for_a_real_reason failed
wiederherstellen

echo
echo "── FailureReasonTest: die Begruendung waechst wieder ohne Grenze ──"
#
# Die zweite Sicherung. Die Ausgabe war seit dem ersten Tag gedeckelt, die
# Meldung nicht — weil ihre Spalte kurz war und niemand vorhatte, mehr
# hineinzuschreiben. Eine Spalte mit 65535 Byte verschiebt diesen Fehler nur.
vorher_datei app/Support/Operations/OperationRecorder.php
python3 - <<'PY2'
p = 'app/Support/Operations/OperationRecorder.php'
s = open(p, encoding='utf-8').read()
s = s.replace("self::shorten($message)", "$message")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Operations/OperationRecorder.php "Meldung ohne Grenze" &&
pruefe "Meldung ohne Grenze" \
  FailureReasonTest::test_an_endless_reason_is_shortened_and_says_so failed
wiederherstellen

echo
echo "── FailureReasonTest: der letzte Halt raet wieder eine Ursache ──"
#
# „vermutlich Zeitueberschreitung" stand am 11. August 2026 an einem Vorgang,
# der eine Sekunde lief. Ein Fehlertext, der eine Ursache raet, beendet die
# Suche — die echte Ursache stand in failed_jobs und sonst nirgends.
vorher_datei app/Jobs/RunAgentOperation.php
python3 - <<'PY2'
p = 'app/Jobs/RunAgentOperation.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    ": 'Der Vorgang ist in der Warteschlange gescheitert. Näheres im Protokoll des Panels.';",
    ": 'Der Vorgang wurde von der Warteschlange abgebrochen — vermutlich Zeitüberschreitung.';",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Jobs/RunAgentOperation.php "geratene Ursache" &&
pruefe "geratene Ursache" \
  FailureReasonTest::test_the_queue_handler_names_what_it_knows failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FailureReasonTest passed

echo
echo "── OrphanedGrantTest: der Rueckbau laesst einen Zugang stehen ──"
#
# Der Fund aus dem Abnahmelauf von P5b, Punkt 9. `removeAllFor()` reiht alle
# Datenbanken auf einmal ein, und jeder Vorgang berechnet seine Listen beim
# Einreihen — waehrend die anderen noch dastehen. Ein Zugang an zwei
# Datenbanken zaehlt damit zweimal als „haengt noch woanders" und geht mit
# keinem mit. Auf cloudsrv24 blieb genau so eine Rolle stehen, und der Vorgang
# meldete „fertig".
vorher_datei app/Support/Databases/Databases.php
python3 - <<'PY2'
p = 'app/Support/Databases/Databases.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$this->remove($database, $accountId, withdrawing: true)",
              "$this->remove($database, $accountId)")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/Databases.php "Rueckbau ohne die Zugaenge" &&
pruefe "Rueckbau ohne die Zugaenge" \
  OrphanedGrantTest::test_withdrawing_takes_every_access_with_it failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OrphanedGrantTest passed

echo
echo "── NoticeShapeTest: eine Meldung darf nicht mehr brechen ──"
#
# Der Fund vom 11. August 2026: Die Meldung eines fehlgeschlagenen Vorgangs
# traegt den Pfad des Dumps — hundert Zeichen ohne Leerzeichen — und schob die
# Vorgangsseite bei 390px um 110px aus dem Bild. Erst hatte diese Meldung
# keinen Weg ins Panel, dann keinen Platz darin.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace("  overflow-wrap: anywhere;\n  padding: 13px 16px;", "  padding: 13px 16px;")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Meldung ohne Umbrucherlaubnis" &&
pruefe "Meldung ohne Umbrucherlaubnis" \
  NoticeShapeTest::test_a_notice_may_break_where_there_is_no_space failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" NoticeShapeTest passed

echo
echo "── PgGrantTest: die Rolle faellt ohne Blick auf ihre Abhaengigkeiten ──"
#
# Der Fund aus Punkt 9, zweiter Anlauf: Beim Rueckbau nennt jeder Vorgang alle
# Zugaenge, und der erste lief in „role … cannot be dropped because some objects
# depend on it". Er scheiterte nach dem DROP DATABASE, und seine Zeile blieb im
# Panel stehen, waehrend der Cluster sauber war.
vorher_datei agent/src/Ops/PgDatabaseRemove.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgDatabaseRemove.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""            if ($this->stillNeeded($context, $role)) {
                continue;
            }

""", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgDatabaseRemove.php "DROP ROLE ohne Abhaengigkeitspruefung" &&
pruefe "DROP ROLE ohne Abhaengigkeitspruefung" \
  PgGrantTest::test_the_dependency_is_checked_before_the_drop failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgGrantTest passed

echo
echo "── DumpRowReachTest: der Zeitstempel einer Sicherung wird wieder verschwiegen ──"
#
# Der Fund des Betreibers vom 11. August 2026: `created_at` lag seit jeher in
# der Ablage, und die Tabelle „Sicherungen" zeigte ihn nicht. Wer zwei
# Sicherungen desselben Tages unterscheiden wollte, musste den Zeitstempel aus
# dem Dateinamen lesen — und der ist eine Kennung und kein Datum.
vorher_datei resources/js/Pages/Databases/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("""                <td data-column="Erstellt">{{ dump.created_at ?? '—' }}</td>
""", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Show.vue "Sicherung ohne Zeitstempel" &&
pruefe "Sicherung ohne Zeitstempel" \
  DumpRowReachTest::test_every_field_of_a_dump_row_is_read_by_the_page failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DumpRowReachTest passed

echo
echo "── TimeDisplayTest: eine Seite formatiert ihre Zeit wieder selbst ──"
#
# docs/40: Achtzehn Stellen gaben eine Zeit heraus, alle ueber
# toDateTimeString(), und alle in UTC. Sie umzustellen ist die eine Haelfte;
# die andere ist, dass die neunzehnte nicht wieder danebengeht. Names::fqdn()
# ist viermal neu erfunden worden, bevor es dafuer einen Waechter gab.
vorher_datei app/Http/Controllers/ProfileController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/ProfileController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("Clock::display($account->last_login_at)",
              "$account->last_login_at?->toDateTimeString()")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/ProfileController.php "Zeit ohne Clock" &&
pruefe "Zeit ohne Clock" \
  TimeDisplayTest::test_no_page_formats_a_time_of_its_own failed
wiederherstellen

echo
echo "── ClockTest: der Filter des Protokolls rechnet nicht mehr mit ──"
#
# Die Haelfte, die still bricht: Eine umgestellte Anzeige ohne mitrechnenden
# Filter zeigt eine Zeile und findet sie nicht. Der Eintrag im Test liegt auf
# 22:30 UTC — in Berlin bereits 00:30 des naechsten Tages.
vorher_datei app/Support/Audit/AuditQuery.php
python3 - <<'PY2'
p = 'app/Support/Audit/AuditQuery.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$from = Clock::boundaryToUtc($filters['from'], end: false);",
              "$from = $filters['from'].' 00:00:00';")
s = s.replace("$to = Clock::boundaryToUtc($filters['to'], end: true);",
              "$to = $filters['to'].' 23:59:59';")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Audit/AuditQuery.php "Filter ohne Zeitzone" &&
pruefe "Filter ohne Zeitzone" \
  ClockTest::test_the_audit_filter_uses_the_same_zone_as_the_display failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ClockTest passed

echo
echo "── PgHbaRollbackTest: der Rückweg wird eine Meldung ──"
#
# docs/38 §14.2: Eine kaputte pg_hba.conf ist bei einem Reload folgenlos und
# bei einem Neustart toedlich — gemessen am 11. August 2026 auf einem echten
# Debian-Cluster: „pg_ctl: could not start server", 16/main down. Eine
# Operation, die die kaputte Datei liegenlaesst und darueber berichtet, hat den
# Server scharf gemacht und ein Protokoll geschrieben.
vorher_datei agent/src/Ops/PgRemoteAccess.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgRemoteAccess.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""                ManagedBlock::put($path, $before);
                $reload();
""", """                $reload();
""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgRemoteAccess.php "Rückweg ohne Rückweg" &&
pruefe "Rückweg ohne Rückweg" \
  PgHbaRollbackTest::test_a_rejected_block_restores_the_file_byte_for_byte failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgHbaRollbackTest passed

echo
echo "── PgHbaRollbackTest: die Meldung nennt nur noch die Nummer ──"
#
# Gemessen im Abnahmelauf (docs/45 §5): 140 Zeilen vor dem Versuch, „Zeile 136"
# in der Meldung. Die Nummer zaehlt in der abgewiesenen Fassung, und die hat der
# Rueckweg zwei Zeilen weiter oben schon wieder ersetzt. Der Text der Zeile ist
# in beiden Staenden derselbe — danach laesst sich suchen, nach einer Nummer
# nicht.
vorher_datei agent/src/Ops/PgRemoteAccess.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgRemoteAccess.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """                        return sprintf('Zeile %d („%s"): %s', $row['line'], trim($text), $row['error']);""",
    """                        return sprintf('Zeile %d: %s', $row['line'], $row['error']);""",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgRemoteAccess.php "Zeilennummer ohne Text" &&
pruefe "Zeilennummer ohne Text" \
  PgHbaRollbackTest::test_the_message_quotes_the_offending_line failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgHbaRollbackTest passed

echo
echo "── PgHbaRollbackTest: die Sperre fällt weg ──"
#
# Der Agent gabelt je Verbindung; zwei Operationen sind zwei Prozesse. Ohne
# flock schreibt der Rueckweg einen Stand zurueck, in dem die Zeile fuers
# Zurueckspielen fehlt, die Hba::ensure() inzwischen ergaenzt hat — und
# auffallen wuerde das erst Wochen spaeter, an einer Meldung ueber
# peer-Authentifizierung.
vorher_datei agent/src/Ops/PgRemoteAccess.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgRemoteAccess.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "return ManagedBlock::locked($path, static function () use ($path, $rules, $reload, $errors): array {",
    "return (static function () use ($path, $rules, $reload, $errors): array {")
s = s.replace("""            return ['rules' => ManagedBlock::managed($after), 'changed' => true];
        });""", """            return ['rules' => ManagedBlock::managed($after), 'changed' => true];
        })();""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgRemoteAccess.php "pg_hba.conf ohne Sperre" &&
pruefe "pg_hba.conf ohne Sperre" \
  PgHbaRollbackTest::test_the_file_is_locked_while_the_block_is_written failed
wiederherstellen

echo
echo "── PgHbaRollbackTest: der Block stellt sich vor den Bestand ──"
#
# In pg_hba.conf entscheidet die erste passende Zeile. Steht unser Block ueber
# einem „reject" des Betreibers, gewinnt er — und „der Bestand ist Gesetz"
# waere eine Behauptung. Dieselbe Falle wie in docs/28 §6 fuer nginx.
#
# **Der Eingriff ist am 16. August umgezogen**, weil der Code es ist: Das
# Setzen des Bereichs steht seit `ManagedBlock` nicht mehr in `Pg\Hba`.
# Gemerkt hat es `BreakScriptTest` — vier Eingriffe fanden ihren Text nicht
# mehr, und ein Eingriff, der nichts ändert, prüft nichts.
vorher_datei agent/src/ManagedBlock.php
python3 - <<'PY2'
p = 'agent/src/ManagedBlock.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    'return $rest."\\n".self::BEGIN."\\n".implode("\\n", $lines)."\\n".self::END."\\n";',
    'return self::BEGIN."\\n".implode("\\n", $lines)."\\n".self::END."\\n\\n".$rest;')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/ManagedBlock.php "Block über dem Bestand" &&
pruefe "Block über dem Bestand" \
  PgHbaRollbackTest::test_the_block_goes_below_what_the_operator_wrote failed
wiederherstellen

echo
echo "── PgHbaReachTest: eine Rolle geht, ihre Zeilen bleiben ──"
#
# docs/38 §14.4 und M22: Eine pg_hba.conf-Zeile fuer eine Rolle, die es nicht
# mehr gibt, ist fuer PostgreSQL kein Fehler. Sie bleibt liegen, und niemand
# meldet es — deshalb muss der Abgleich es tun.
vorher_datei app/Support/Databases/RemoteAccess.php
python3 - <<'PY2'
p = 'app/Support/Databases/RemoteAccess.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""        return array_values(array_filter(
            $managed,
            static fn (string $line): bool => ! isset($wanted[$line]),
        ));""", """        return [];""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/RemoteAccess.php "verwaiste Zeile ohne Meldung" &&
pruefe "verwaiste Zeile ohne Meldung" \
  PgHbaReachTest::test_a_line_without_a_role_in_the_inventory_is_reported failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgHbaReachTest passed

echo
echo "── EngineReachTest: eine pg.*-Operation verschwindet ──"
#
# docs/38 §18: Zu jeder db.*-Operation mit einem Gegenstueck gibt es pg.*, oder
# ein begruendeter Eintrag sagt warum nicht. Was hier still schiefgeht, ist eine
# Flaeche, die es fuer das eine System gibt und fuer das andere nicht — und
# auffallen wuerde das dem Kunden, der es versucht.
vorher_datei agent/src/Registry.php
python3 - <<'PY2'
p = 'agent/src/Registry.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $this->register(new PgRemoteAccess);", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Registry.php "pg.remote.access fehlt" &&
pruefe "pg.remote.access fehlt" \
  EngineReachTest::test_every_mariadb_operation_has_a_postgresql_counterpart failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" EngineReachTest passed

echo
echo "── PgHbaFollowTest: eine Rechteänderung zieht den Block nicht mehr nach ──"
#
# docs/38 §14: Der Sollzustand ist Datenbank × Rolle × Netz. Wird eine zweite
# Datenbank mit einer Rolle verbunden, die schon ein Netz hat, braucht sie eine
# eigene Zeile — die Zeile nennt die Datenbank und nicht `all`. Fehlt sie, steht
# im Panel „erreichbar von …" und die Anwendung kommt nicht herein: ein Fehler,
# der wie ein kaputter Fernzugriff aussieht und keiner ist.
vorher_datei app/Support/Databases/Databases.php
python3 - <<'PY2'
p = 'app/Support/Databases/Databases.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $this->remote->follow($user->engine);", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/Databases.php "Verbinden ohne Nachziehung" &&
pruefe "Verbinden ohne Nachziehung" \
  PgHbaFollowTest::test_every_place_that_links_a_database_follows_up failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PgHbaFollowTest passed

echo
echo "── PgHbaFollowTest: der Rückbau schluckt seinen Fehlschlag ──"
#
# Ein `catch` ohne Meldung ist die Bauart, für die es in diesem Projekt die
# meisten Narben gibt. Der Rückbau darf nicht umfallen, wenn der Abgleich
# scheitert — aber still darf er dabei nicht sein.
vorher_datei app/Support/Databases/PgLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Databases/PgLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            report($error);", "            // geschluckt")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/PgLifecycle.php "Nachziehung ohne Meldung" &&
pruefe "Nachziehung ohne Meldung" \
  PgHbaFollowTest::test_a_failed_follow_up_is_reported_and_not_swallowed failed
wiederherstellen

echo
echo "── RemoteAccessTest: die Einstellungsseite fragt den falschen Server ──"
#
# Beide Antworten haben dieselbe Form — `remote` ist ein bool, die Adresse eine
# Zeichenkette. Auf einem Server, der nur eines der beiden von aussen erreichbar
# hat, steht dann das Gegenteil auf der Seite, und auffallen wuerde es niemandem.
# Genau dieser Fehler stand bis zum 11. August 2026 in
# DatabaseController::remoteAccess().
vorher_datei app/Http/Controllers/DatabaseSettingsController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseSettingsController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$info = $agent->call('pg.server.info', []);",
              "$info = $agent->call('db.server.info', []);")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseSettingsController.php "Seite fragt das falsche System" &&
pruefe "Seite fragt das falsche System" \
  RemoteAccessTest::test_each_system_is_asked_about_itself failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RemoteAccessTest passed

echo
echo "── RemoteAccessTest: der Rückweg zählt erst den Bestand ──"
#
# Der Zustand von vor diesem Wächter, gemessen am 11. August 2026 auf
# cloudsrv24: `--remote=on --bind=::` hatte das Panel von seiner Datenbank
# abgeschnitten, und `--remote=off` starb an dieser Zählung, bevor es zum
# Agenten kam. Der Griff gegen den Ausfall brauchte genau das, was der Ausfall
# weggenommen hatte.
vorher_datei app/Console/Commands/Databases.php
python3 - <<'PY2'
p = 'app/Console/Commands/Databases.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        $fern = $mode === 'off' ? $this->foreignAccess($tenancy) : null;",
    "        $fern = $tenancy->withoutRestriction(\n"
    "            static fn (): int => DbUser::query()->where('host', '!=', 'localhost')->count()\n"
    "                + DbUserNetwork::query()->count(),\n"
    "        );",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/Databases.php "Rückweg braucht den Bestand" &&
pruefe "Rückweg braucht den Bestand" \
  RemoteAccessTest::test_the_way_back_does_not_need_the_inventory failed
wiederherstellen

echo
echo "── RemoteAccessTest: der Schalter fragt nicht, ob das Panel noch hereinkommt ──"
#
# Der Agent meldete „Horcht auf :: — Fernzugriff möglich.", das Kommando
# verglich die Antwort mit der Absicht und war zufrieden — während das Panel
# schon auf jeder Seite einen 500er gab. Seine Gegenprobe läuft über den
# Unix-Socket und kann einen kaputten TCP-Weg gar nicht sehen.
vorher_datei app/Console/Commands/Databases.php
python3 - <<'PY2'
p = 'app/Console/Commands/Databases.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            $fehlt = $this->panelDatabaseUnreachable();",
    "            $fehlt = null;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/Databases.php "Umschalten ohne eigene Gegenprobe" &&
pruefe "Umschalten ohne eigene Gegenprobe" \
  RemoteAccessTest::test_the_switch_checks_that_the_panel_still_gets_in failed
wiederherstellen

echo
echo "── RemoteAccessTest: kein Wert für „von überall\", der das Panel verschont ──"
#
# `bind-address = ::` bindet in MariaDB ausschliesslich IPv6 — gemessen, nicht
# gelesen. Ohne `*` bleibt für „von überall" nur ein Wert übrig, der das Panel
# aussperrt, und der Abnahmelauf schreibt ihn dann vor.
vorher_datei agent/src/Ops/DbRemoteAccess.php
python3 - <<'PY2'
p = 'agent/src/Ops/DbRemoteAccess.php'
s = open(p, encoding='utf-8').read()
s = s.replace("public const ADDRESSES = ['*', '0.0.0.0', '::'];",
              "public const ADDRESSES = ['0.0.0.0', '::'];")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/DbRemoteAccess.php "Doppelstapel ohne eigenen Wert" &&
pruefe "Doppelstapel ohne eigenen Wert" \
  RemoteAccessTest::test_the_dual_stack_address_is_the_star failed
wiederherstellen

echo
echo "── RemoteAccessTest: die beiden Systeme nehmen verschiedene Adressen ──"
#
# Das Kommando reicht die Adresse unübersetzt an beide weiter. Ein Wert, den nur
# eines von beiden kennt, wird dort abgewiesen — nachdem das andere schon neu
# gestartet hat.
vorher_datei agent/src/Ops/PgRemoteAccess.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgRemoteAccess.php'
s = open(p, encoding='utf-8').read()
s = s.replace("public const ADDRESSES = ['*', '0.0.0.0', '::'];",
              "public const ADDRESSES = ['*', '0.0.0.0'];")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgRemoteAccess.php "zwei Listen von Horchadressen" &&
pruefe "zwei Listen von Horchadressen" \
  RemoteAccessTest::test_both_systems_take_the_same_addresses failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RemoteAccessTest passed

echo
echo "── SettingsProbeTest: ein gescheiterter Leseversuch heisst „nein\" ──"
#
# Der Zustand von vor diesem Wächter: `catch (Throwable) { return []; }` machte
# aus „der Datenbankserver antwortet nicht" eine leere Ablage, und das Kommando
# meldete daraufhin, der Betreiber biete PostgreSQL nicht an. Die Betreiberseite
# sagte zur selben Zeit das Gegenteil, und sie hatte recht.
vorher_datei app/Support/Settings/Settings.php
python3 - <<'PY2'
p = 'app/Support/Settings/Settings.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        } catch (Throwable) {\n            return null;\n        }",
              "        } catch (Throwable) {\n            return [];\n        }")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Settings/Settings.php "Leseversuch ohne dritten Wert" &&
pruefe "Leseversuch ohne dritten Wert" \
  SettingsProbeTest::test_a_failed_look_is_not_a_no failed
wiederherstellen

echo
echo "── SettingsProbeTest: der dritte Wert verschluckt den harmlosen Fall ──"
#
# Die fehlende Tabelle vor der ersten Migration ist wirklich „nichts abgelegt".
# Wer sie in dieselbe Antwort legt wie den nicht erreichbaren Datenbankserver,
# hat den Unterschied nur verschoben — und der erste Anlauf dieses Wächters ist
# genau darauf hereingefallen: Er nahm die Tabelle weg und erwartete „nicht
# nachgesehen".
vorher_datei app/Support/Settings/Settings.php
python3 - <<'PY2'
p = 'app/Support/Settings/Settings.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            if (! Schema::hasTable('settings')) {\n                return [];\n            }",
              "            if (! Schema::hasTable('settings')) {\n                return null;\n            }")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Settings/Settings.php "fehlende Tabelle als Unsicherheit" &&
pruefe "fehlende Tabelle als Unsicherheit" \
  SettingsProbeTest::test_a_missing_table_is_a_no failed
wiederherstellen

echo
echo "── SettingsProbeTest: die Kommandozeile fragt wieder zweiwertig ──"
#
# Der dritte Wert nützt nichts, wenn die Stelle, die den Fehler ausgegeben hat,
# ihn nicht liest.
vorher_datei app/Console/Commands/Databases.php
python3 - <<'PY2'
p = 'app/Console/Commands/Databases.php'
s = open(p, encoding='utf-8').read()
s = s.replace("app(Settings::class)->postgresOffered();", "app(Settings::class)->postgres();")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/Databases.php "Kommandozeile wieder zweiwertig" &&
pruefe "Kommandozeile wieder zweiwertig" \
  SettingsProbeTest::test_the_command_line_asks_the_three_valued_question failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SettingsProbeTest passed

echo
echo "── PreviousUrlTest: ein Formularfehler ohne Ziel ──"
#
# Laravel merkt sich die vorige Seite nur bei GET-Anfragen, die nicht als XHR
# gelten — und jede Inertia-Navigation ist XHR. Ohne diese Middleware steht
# `_previous.url` nach dem Anmelden dauerhaft auf /login, und JEDER
# Formularfehler dieses Panels landet dort statt am Formular. Gefunden am
# 11. August 2026 im Abnahmelauf des Fernzugriffs.
vorher_datei bootstrap/app.php
python3 - <<'PY2'
p = 'bootstrap/app.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                RememberPageUrl::class,\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei bootstrap/app.php "Formularfehler ohne Ziel" &&
pruefe "Formularfehler ohne Ziel" \
  PreviousUrlTest::test_a_form_error_returns_to_the_page_and_not_to_the_login failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PreviousUrlTest passed

echo
echo "── TableStyleTest: eine gestapelte Zelle ohne Ausrichtung ──"
#
# Ab dem zweiten Eintrag steht der Benutzername in der Mitte neben dem Stapel.
# Mit einem Eintrag sieht es normal aus, und genau deshalb hat es niemand
# gesehen — auch keine Aufnahme.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace("tr:has(td.multiline) > td {", "tr.gibt-es-nicht > td {")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "gestapelte Zelle ohne Ausrichtung" &&
pruefe "gestapelte Zelle ohne Ausrichtung" \
  TableStyleTest::test_a_stacked_cell_aligns_its_row_and_spaces_its_rows failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TableStyleTest passed

echo
echo "── TableStyleTest: oben ausgerichtet, aber ohne Polster ──"
#
# `td` setzt kein senkrechtes Polster; der Abstand zur Linie darüber kam allein
# daraus, dass eine Zeile hohe Zelle mittig sass. Wer nur die Ausrichtung
# umstellt, lässt die erste Zeile an der Trennlinie kleben — genau so gemeldet,
# eine Fassung nach der Ausrichtung.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace("  padding-top: calc((var(--row-height) - 1lh) / 2);\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Ausrichtung ohne Polster" &&
pruefe "Ausrichtung ohne Polster" \
  TableStyleTest::test_a_stacked_cell_aligns_its_row_and_spaces_its_rows failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TableStyleTest passed

echo
echo "── NetworkDriftTest: ein gescheitertes Schreiben laesst die Zeile stehen ──"
#
# Der Zustand von vor diesem Wächter, gemessen im Abnahmelauf: Der Vorgang
# scheiterte am Rückweg des Agenten, die Zeile blieb im Bestand, und im Panel
# stand „erreichbar von …" für ein Netz, das in pg_hba.conf nicht existierte.
vorher_datei app/Support/Databases/RemoteAccess.php
python3 - <<'PY2'
p = 'app/Support/Databases/RemoteAccess.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $network = DB::transaction(function () use ($user, $cidr): DbUserNetwork {",
              "        $network = (function () use ($user, $cidr): DbUserNetwork {")
s = s.replace("            return $network;\n        });\n\n        return $network;",
              "            return $network;\n        })();\n\n        return $network;")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/RemoteAccess.php "Eintrag ohne Klammer" &&
pruefe "Eintrag ohne Klammer" \
  NetworkDriftTest::test_a_failed_write_leaves_no_network_behind failed
wiederherstellen

echo
echo "── NetworkDriftTest: der Abgleich kennt nur eine Richtung ──"
#
# Eine Zeile ohne Bestand laesst jemanden herein, den niemand mehr kennt —
# sichtbar. Ein Bestand ohne Zeile sperrt aus, waehrend die Anzeige das
# Gegenteil verspricht. Die zweite Richtung hat vier Monate lang niemand
# gefragt.
vorher_datei app/Console/Commands/Databases.php
python3 - <<'PY2'
p = 'app/Console/Commands/Databases.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $missing = $remote->missing($managed);", "        $missing = [];")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Console/Commands/Databases.php "Abgleich ohne Gegenrichtung" &&
pruefe "Abgleich ohne Gegenrichtung" \
  NetworkDriftTest::test_the_reconciliation_asks_both_directions failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" NetworkDriftTest passed

echo
echo "── ResultEncodingTest: der Klient ohne Zeichensatz ──"
#
# Ohne `--default-character-set` handelt mysql unter LC_ALL=C latin1 aus. Der
# Server konvertiert JSON_OBJECT() am Ausgang, aus `ue` wird das einzelne Byte
# FC, und json_decode() gibt null zurueck — fuer die ganze Zeile, nicht nur die
# Zelle. Gemessen auf cloudsrv24 am 12. August 2026; gefunden hat es der
# Abnahmelauf und kein Test.
vorher_datei agent/src/Db/Session.php
python3 - <<'PY2'
p = 'agent/src/Db/Session.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        '--default-character-set=utf8mb4',\n", "", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Session.php "Klient ohne Zeichensatz" &&
pruefe "Klient ohne Zeichensatz" \
  ResultEncodingTest::test_the_client_always_speaks_utf8mb4 failed
wiederherstellen

echo
echo "── ResultEncodingTest: die Argumentliste steht wieder zweimal da ──"
#
# Die zweite Haelfte derselben Regel, und die wichtigere: Die Liste stand in
# run() und in linesAs(), und die Angabe fehlte in beiden. Ein Wert, den zwei
# Stellen fuehren, ist kein Wert — er ist zwei.
vorher_datei agent/src/Db/Session.php
python3 - <<'PY2'
p = 'agent/src/Db/Session.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $arguments = self::CLIENT;\n        $file = null;",
              "        $arguments = ['--protocol=socket', '--batch', '--skip-column-names'];\n        $file = null;", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Session.php "Argumentliste zum zweiten Mal" &&
pruefe "Argumentliste zum zweiten Mal" \
  ResultEncodingTest::test_the_client_always_speaks_utf8mb4 failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ResultEncodingTest passed

echo
echo "── MobileLayoutTest: eine Bereichsüberschrift, die nicht brechen darf ──"
#
# Ein Bereichstitel traegt hier Kundendaten — einen Tabellennamen, einen
# Abonnementnamen. Ohne overflow-wrap schob er die Seite bei 390px um 99px aus
# dem Bild, gemessen auf cloudsrv24 am 12. August 2026.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace("  min-width: 0;\n  overflow-wrap: anywhere;\n}", "  min-width: 0;\n}", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Überschrift ohne Umbruch" &&
pruefe "Überschrift ohne Umbruch" \
  MobileLayoutTest::test_a_section_heading_can_break failed
wiederherstellen

echo
echo "── MobileLayoutTest: … und die Haelfte, die nach einem Fix aussieht ──"
#
# Die Erlaubnis zu brechen nuetzt nichts, solange das Flexkind seine
# Inhaltsbreite behalten darf. Dritte Fassung derselben Ausnahme.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace("  min-width: 0;\n  overflow-wrap: anywhere;\n}", "  overflow-wrap: anywhere;\n}", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Überschrift ohne min-width" &&
pruefe "Überschrift ohne min-width" \
  MobileLayoutTest::test_a_section_heading_can_break failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" MobileLayoutTest passed

# ═══════════════════════════════════════════════════════════════════════
# P5c — das Datenbankmanagement (docs/46 §14)
#
# Die Brüche zu den Schritten 1 bis 7. Jeder ist beim Bauen einmal von Hand
# gefahren worden und war rot; hier stehen sie, damit das jemand wiederholen
# kann, ohne die Sitzung zu kennen, in der sie entstanden sind.
# ═══════════════════════════════════════════════════════════════════════

echo
echo "── ConsoleQueueTest: eine Konsolenoperation in der Warteschlange ──"
#
# Ein eingereihter Vorgang legt seine Argumente in `operations.payload` ab —
# bei `console.row.write` wäre das der Inhalt einer Kundenzeile. Der Fehler
# wäre unsichtbar: Die Zeile wird geändert, die Antwort kommt, die Seite sieht
# richtig aus.
vorher_datei app/Support/Operations/Task.php
python3 - <<'PY2'
p = 'app/Support/Operations/Task.php'
s = open(p, encoding='utf-8').read()
s = s.replace("    case AgentPing = 'agent.ping';",
              "    case AgentPing = 'agent.ping';\n    case DbConsoleRows = 'db.console.rows';", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Operations/Task.php "Konsolenaufgabe in der Reihe" &&
pruefe "Konsolenaufgabe in der Reihe" \
  ConsoleQueueTest::test_no_console_operation_has_a_task failed
wiederherstellen

echo
echo "── ConsoleQueueTest: der Panelgriff reiht ein, statt zu rufen ──"
vorher_datei app/Support/Databases/Console.php
python3 - <<'PY2'
p = 'app/Support/Databases/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("return $this->agent->call($driver->consoleOperation($handle)",
              "return $this->queue->dispatch($driver->consoleOperation($handle)", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Databases/Console.php "Konsolengriff über die Reihe" &&
pruefe "Konsolengriff über die Reihe" \
  ConsoleQueueTest::test_the_panel_side_console_calls_the_agent_directly failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ConsoleQueueTest passed

echo
echo "── ConsoleIdentityTest: eine Operation ohne befristeten Zugang ──"
#
# Die Regel, an der die Mandantentrennung dieser Stufe hängt (§5): Ohne
# `within()` liefe die Abfrage als root — und das Ergebnis sähe genau gleich
# aus. Eine Prüfung, die im Fehlerfall dasselbe sagt wie im Erfolgsfall, belegt
# nichts.
vorher_datei agent/src/Ops/PgConsoleRows.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgConsoleRows.php'
s = open(p, encoding='utf-8').read()
s = s.replace("return $this->console->within(", "return $this->console->unmittelbar(", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgConsoleRows.php "Operation ohne Zugangsrahmen" &&
pruefe "Operation ohne Zugangsrahmen" \
  ConsoleIdentityTest::test_every_console_operation_goes_through_the_ephemeral_frame failed
wiederherstellen

echo
echo "── ConsoleIdentityTest: eine Operation ruft die Sitzung selbst ──"
vorher_datei agent/src/Ops/PgConsoleRows.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgConsoleRows.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $filter = self::filter($args);",
              "        $filter = self::filter($args);\n\n        $this->console->session->query('SELECT 1');", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgConsoleRows.php "Sitzung am Rahmen vorbei" &&
pruefe "Sitzung am Rahmen vorbei" \
  ConsoleIdentityTest::test_no_console_operation_talks_to_a_session_itself failed
wiederherstellen

echo
echo "── ConsoleIdentityTest: der Rest sagt nicht, woher er stammt ──"
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            Names::KIND_CONSOLE,", "            Names::KIND_RESTORE,", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "Rest ohne Kennzeichnung" &&
pruefe "Rest ohne Kennzeichnung" \
  ConsoleIdentityTest::test_the_frame_marks_its_leftovers_as_console failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ConsoleIdentityTest passed

echo
echo "── ConsoleStatementTest: ein Bezeichner wird nur maskiert ──"
#
# §7: Ein Name aus einer Anfrage wird gegen den Katalog geprüft, bevor er
# maskiert wird. Ohne das Nachschlagen käme beim Kunden eine Meldung des
# Servers über eine Relation an, die es anderswo geben könnte.
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        throw AgentException::badRequest('Diese Spalte gibt es in dieser Tabelle nicht.', ['column' => $name]);",
              "        return ['name' => $name, 'type' => 'text', 'nullable' => true, 'default' => null, 'key' => false, 'binary' => false];", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "Bezeichner ohne Nachschlagen" &&
pruefe "Bezeichner ohne Nachschlagen" \
  ConsoleStatementTest::test_an_unknown_identifier_never_becomes_a_statement failed
wiederherstellen

echo
echo "── ConsoleStatementTest: die Kürzung fällt aus der Abfrage ──"
#
# Gemessen an der erzeugten Anweisung und nicht an einem Ergebnis: Die Grenze
# ist eine Eigenschaft der Zeichenkette (§9). Ohne sie holt eine Seite mit
# fünfzig Zeilen alles, was in den Zellen steht.
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                : sprintf('left(%s::text, %d) AS %s', $identifier, $limit + 1, $identifier);",
              "                : sprintf('%s::text AS %s', $identifier, $identifier);", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "Abfrage ohne Kürzung" &&
pruefe "Abfrage ohne Kürzung" \
  ConsoleStatementTest::test_every_other_column_is_cut_in_the_statement failed
wiederherstellen

echo
echo "── ConsoleStatementTest: eine gekürzte Zelle sagt es nicht ──"
#
# Die andere Hälfte derselben Regel: Gekürzt wird in der Abfrage, gemeldet beim
# Lesen. Ohne die Meldung ist ein abgeschnittener Wert von einem vollständigen
# nicht zu unterscheiden — und das Formular schriebe den Rest weg.
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                    $row[$name] = mb_substr($value, 0, self::CELL_LIMIT);",
              "                    $row[$name] = $value;", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "Kürzung ohne Kennzeichnung" &&
pruefe "Kürzung ohne Kennzeichnung" \
  ConsoleStatementTest::test_a_cut_cell_says_so failed
wiederherstellen

echo
echo "── ConsoleStatementTest: der Filter wird wieder ein Muster ──"
#
# `LIKE` macht aus einem Prozentzeichen im Wert des Kunden einen Platzhalter,
# und seine Maskierung bräuchte ein eigenes Fluchtzeichen — drei Ebenen für
# eine Suche nach einer Zeichenkette (§7).
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'contains' => sprintf('strpos(%s::text, %s) > 0', $identifier, Sql::text($value)),",
              "            'contains' => sprintf('%s::text LIKE %s', $identifier, Sql::text($value)),", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "Filter mit LIKE" &&
pruefe "Filter mit LIKE" \
  ConsoleStatementTest::test_a_filter_value_is_quoted_and_never_a_pattern failed
wiederherstellen

echo
echo "── ConsoleStatementTest: NULL wird zur leeren Zeichenkette ──"
#
# Der Fehler, den keine Zählung meldet: Ein `WHERE spalte IS NULL` der
# Kundenanwendung findet die Zeile danach einfach nicht mehr (§10.1).
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        return $value === null ? 'NULL' : Sql::text($value);",
              "        return Sql::text((string) $value);", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "NULL als leere Zeichenkette" &&
pruefe "NULL als leere Zeichenkette" \
  ConsoleStatementTest::test_null_and_the_empty_string_are_two_values_on_the_way_back failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ConsoleStatementTest passed

echo
echo "── RowKeyTest: das UPDATE für MariaDB ohne LIMIT 1 ──"
#
# MariaDB hat keinen anonymen Block, in dem sich nachzählen liesse — `LIMIT 1`
# ist dort der Riegel gegen „mehr als eine Zeile" (§10).
vorher_datei agent/src/Db/Console.php
python3 - <<'PY2'
p = 'agent/src/Db/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                'UPDATE %s SET %s WHERE %s LIMIT 1',",
              "                'UPDATE %s SET %s WHERE %s',", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Console.php "MariaDB ohne LIMIT 1" &&
pruefe "MariaDB ohne LIMIT 1" \
  RowKeyTest::test_mariadb_can_touch_at_most_one_row_and_checks_it_was_one failed
wiederherstellen

echo
echo "── RowKeyTest: … und ohne die Nachzählung ──"
#
# Der zweite Riegel, und er fängt die andere Richtung: null Zeilen, weil die
# Zeile zwischen Anzeige und Änderung verschwunden ist. Ohne ihn meldet der
# Vorgang Erfolg für einen Treffer, den niemand geprüft hat.
vorher_datei agent/src/Db/Console.php
python3 - <<'PY2'
p = 'agent/src/Db/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'SELECT ROW_COUNT()',", "            'SELECT 1',", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Console.php "MariaDB ohne Nachzählung" &&
pruefe "MariaDB ohne Nachzählung" \
  RowKeyTest::test_mariadb_can_touch_at_most_one_row_and_checks_it_was_one failed
wiederherstellen

echo
echo "── RowKeyTest: ein Schreibvorgang ohne Schlüssel ──"
#
# Der eine Fall dieser Stufe, bei dem ein fehlender Riegel nicht eine Zeile
# kostet, sondern alle: Ein `UPDATE` ohne `WHERE` trifft die ganze Tabelle, ein
# `DELETE` leert sie.
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""        if ($key === []) {
            throw AgentException::badRequest(
                'Ohne Primärschlüssel lässt sich eine einzelne Zeile nicht eindeutig ansprechen.',
            );
        }""", "        if ($key === []) {\n            return [];\n        }", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "Anweisung ohne Schlüssel" &&
pruefe "Anweisung ohne Schlüssel" \
  RowKeyTest::test_no_statement_is_built_without_a_key failed
wiederherstellen

echo
echo "── RowKeyTest: ein halber Schlüssel geht durch ──"
#
# Bei einem zusammengesetzten Schlüssel `(b, c)` trifft `WHERE b = '1'` jede
# Zeile mit diesem `b`. Die Nachzählung nimmt das zurück — sie meldet dann aber
# „hat 3 Zeilen getroffen", und das liest sich wie ein Nebenläufigkeitsproblem
# statt wie ein unvollständiger Aufruf.
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        if ($expected !== $given) {", "        if (false) {", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "halber Schlüssel" &&
pruefe "halber Schlüssel" RowKeyTest::test_half_a_key_is_refused failed
wiederherstellen

echo
echo "── RowKeyTest: der Satz steht wieder in der Anweisung ──"
#
# Befund 2 aus docs/47: Der Satz kam als Datenbankfehler verkleidet zurück, mit
# `CONTEXT: PL/pgSQL function inline_code_block line 7 at RAISE` — eine
# Zeilennummer auf eine Datei, die es nicht gibt.
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                RAISE EXCEPTION '%s=%%', getroffen;",
              "                RAISE EXCEPTION 'Der Vorgang hat %% Zeilen getroffen', getroffen;", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "Satz in der Anweisung" &&
pruefe "Satz in der Anweisung" \
  RowKeyTest::test_the_marker_is_one_constant_on_both_sides failed
wiederherstellen

echo
echo "── RowKeyTest: MariaDB baut ihren eigenen Satz ──"
vorher_datei agent/src/Db/Console.php
python3 - <<'PY2'
p = 'agent/src/Db/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            throw AgentException::execFailed(PgConsole::missed($affected));",
              "            throw AgentException::execFailed('Der Vorgang hat nicht genau eine Zeile getroffen.');", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Console.php "zweite Fassung der Meldung" &&
pruefe "zweite Fassung der Meldung" \
  RowKeyTest::test_the_marker_is_one_constant_on_both_sides failed
wiederherstellen

echo
echo "── RowKeyTest: die Tabellenliste kennt nur den Primärschlüssel ──"
#
# Der Fund aus docs/46 §20.46: Über einer Tabelle, die sich ändern liess, stand
# „ohne Schlüssel". `columns()` kannte §10 Regel 2, `tables()` nicht — beim Bau
# ist eine von zwei Stellen angefasst worden.
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                            WHERE i.indrelid = c.oid AND %s)",
              "                            WHERE i.indrelid = c.oid AND i.indisprimary AND %s)", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "PostgreSQL nur Primärschlüssel" &&
pruefe "PostgreSQL nur Primärschlüssel" \
  RowKeyTest::test_both_catalogue_questions_agree_on_what_a_key_is failed
wiederherstellen

echo
echo "── RowKeyTest: … und dieselbe Hälfte in MariaDB ──"
#
# MariaDB befördert einen eindeutigen Index über Spalten ohne NULL zum
# impliziten Primärschlüssel und meldet ihn in `COLUMNS.COLUMN_KEY` als `PRI` —
# den Index selbst benennt sie dabei nicht um. Wer nach `STATISTICS` fragt,
# findet ihn nicht.
vorher_datei agent/src/Db/Console.php
python3 - <<'PY2'
p = 'agent/src/Db/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                   EXISTS (SELECT 1 FROM information_schema.COLUMNS k",
              "                   EXISTS (SELECT 1 FROM information_schema.STATISTICS k", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Console.php "MariaDB fragt die falsche Sicht" &&
pruefe "MariaDB fragt die falsche Sicht" \
  RowKeyTest::test_both_catalogue_questions_agree_on_what_a_key_is failed
wiederherstellen

echo
echo "── RowKeyTest: „nur lesbar“ ohne Begründung ──"
#
# Ein fehlendes Bedienelement ist keine Auskunft (§4, Kriterium 5). Wer eine
# Zeile ändern will und keinen Knopf findet, sucht weiter.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("      + 'NULL; ohne einen von beiden lässt sich eine einzelne Zeile nicht eindeutig ansprechen.'",
              "      + 'NULL. Ändern ist hier nicht möglich.'", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "nur lesbar ohne Grund" &&
pruefe "nur lesbar ohne Grund" \
  RowKeyTest::test_the_interface_says_why_a_table_is_read_only failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RowKeyTest passed

echo
echo "── WriteBackTest: das UPDATE nimmt alle Spalten ──"
#
# Der einzige Punkt des Abnahmekriteriums, dessen Fehlschlag man an der
# geänderten Zeile nicht sieht (§4, Punkt 6). Die Zeile ist danach da, sie sieht
# richtig aus, und der Rest einer gekürzten Zelle ist fort.
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""                        .' = '.self::literal($values[$name]),
                    array_keys($values),""",
              """                        .' = '.self::literal($values[$name] ?? null),
                    array_column($columns, 'name'),""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "UPDATE über alle Spalten" &&
pruefe "UPDATE über alle Spalten" \
  WriteBackTest::test_only_the_given_columns_reach_the_statement failed
wiederherstellen

echo
echo "── WriteBackTest: NULL beim Anlegen als leere Zeichenkette ──"
#
# Der Zweig, den der erste Wurf dieses Wächters nicht geprüft hat: Ein
# `strval()` über die Werte traf das Anlegen und blieb grün.
vorher_datei agent/src/Pg/Console.php
python3 - <<'PY2'
p = 'agent/src/Pg/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                implode(', ', array_map(self::literal(...), $values)),",
              "                implode(', ', array_map(strval(...), $values)),", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Console.php "NULL beim Anlegen" &&
pruefe "NULL beim Anlegen" \
  WriteBackTest::test_null_and_the_empty_string_stay_two_values failed
wiederherstellen

echo
echo "── WriteBackTest: das Formular schickt alle Felder ──"
#
# Das obere Ende derselben Regel: Die Anweisung kann nur weglassen, was ihr
# nicht gegeben wird. Ein Formular, das alle Felder schickt, macht die Prüfung
# am Agenten wirkungslos, ohne sie zu verletzen.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("  for (const field of changedFields.value) {", "  for (const field of formular.fields) {", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Formular schickt alles" &&
pruefe "Formular schickt alles" \
  WriteBackTest::test_the_form_sends_only_what_was_touched failed
wiederherstellen

echo
echo "── WriteBackTest: ein gekürztes Feld ist nicht gesperrt ──"
#
# Was dort steht, ist nicht der Wert — es zurückzuschreiben wirft den Rest weg,
# für den, der die Zeile aus einem ganz anderen Grund geöffnet hat.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("""  if (typeof value === 'string' && isTruncated(column, value)) {
    return 'gekürzt — der ganze Wert steht in der Zelleinzelsicht'
  }

""", "", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "gekürztes Feld offen" &&
pruefe "gekürztes Feld offen" \
  WriteBackTest::test_a_truncated_or_binary_field_is_locked_with_a_reason failed
wiederherstellen

echo
echo "── WriteBackTest: NULL ist im Formular kein eigener Zustand ──"
#
# Ein Textfeld kann `NULL` nicht ausdrücken. Ohne das Kästchen wäre jede leere
# Eingabe ein `''`, und aus jedem `NULL` einer nullbaren Spalte würde lautlos
# eine leere Zeichenkette.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("  return field.isNull ? null : field.value", "  return field.value", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "NULL ohne eigenen Zustand" &&
pruefe "NULL ohne eigenen Zustand" \
  WriteBackTest::test_null_is_its_own_state_in_the_form failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" WriteBackTest passed

echo
echo "── NullDisplayTest: der Typ entscheidet vor dem NULL ──"
#
# Der Anlass ist ein Bildschirmfoto aus Schritt 5: In jeder Zeile der Spalte
# `anhang` stand „binär · 0 B". Die Spalte war leer — nicht null Byte lang,
# sondern NULL —, und `Number(null ?? 0)` ist `0`.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('<span v-if="row[column] === null" class="quiet">NULL</span>',
              '<span v-else-if="row[column] === null" class="quiet">NULL</span>', 1)
s = s.replace('<span v-else-if="isBinary(column)" class="quiet">',
              '<span v-if="isBinary(column)" class="quiet">', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Typ vor NULL" &&
pruefe "Typ vor NULL" NullDisplayTest::test_a_null_is_shown_before_any_type_decides failed
wiederherstellen

echo
echo "── NullDisplayTest: die Länge fällt auf 0 zurück ──"
#
# Die zweite Hälfte, und ohne sie wäre die erste zu umgehen: Wer die
# Reihenfolge richtig stellt und `?? 0` stehen lässt, hat den Fehler behoben und
# den Grund behalten.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("binär · {{ formatBytes(Number(row[column])) }}",
              "binär · {{ formatBytes(Number(row[column] ?? 0)) }}", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Länge mit Ersatzwert" &&
pruefe "Länge mit Ersatzwert" \
  NullDisplayTest::test_a_length_is_never_defaulted_to_zero failed
wiederherstellen

echo
echo "── NullDisplayTest: die Schätzung gibt sich als Zählung aus ──"
#
# Auf cloudsrv24 stand `16.008 Zeilen`; `SELECT COUNT(*)` sagte 16384. Fünf
# Stellen Genauigkeit für eine Angabe, die keine hat.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("`geschätzt ${counted(tabelle.rows, 'Zeile', 'Zeilen')}`",
              "`${counted(tabelle.rows, 'Zeile', 'Zeilen')}`", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Schätzung ohne das Wort" &&
pruefe "Schätzung ohne das Wort" \
  NullDisplayTest::test_an_estimated_row_count_says_so failed
wiederherstellen

echo
echo "── NullDisplayTest: eine Sicht bekommt eine Grösse ──"
#
# Der dritte Fall derselben Falle in dieser Stufe. Eine Sicht speichert nichts;
# der Katalog meldet dafür 0, und „0 B" liest sich wie „leer" statt wie „gibt es
# nicht".
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("""  if (tabelle.kind !== 'view') {
    angaben.push(formatBytes(tabelle.bytes))
  }""", "  angaben.push(formatBytes(tabelle.bytes))", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Sicht mit Grösse" &&
pruefe "Sicht mit Grösse" NullDisplayTest::test_a_view_is_shown_without_a_size failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" NullDisplayTest passed

echo
echo "── TreeSemanticsTest: ein Baum ohne Behälterrolle ──"
#
# Sichtbar besteht der Baum aus Knöpfen mit einem Dreieck davor; ohne die drei
# Rollen ist er für einen Screenreader eine Liste von Knöpfen ohne Zusammenhang
# — und das fällt niemandem auf, der ihn sieht.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('class="tree" role="tree" @keydown="navigate"', 'class="tree" @keydown="navigate"', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Baum ohne Behälterrolle" &&
pruefe "Baum ohne Behälterrolle" \
  TreeSemanticsTest::test_every_tree_carries_its_roles failed
wiederherstellen

echo
echo "── TreeSemanticsTest: ein Zweig sagt seinen Zustand nicht an ──"
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('                :aria-expanded="expanded.has(table.name)"\n', "", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Zweig ohne Zustand" &&
pruefe "Zweig ohne Zustand" \
  TreeSemanticsTest::test_every_expandable_node_announces_its_state failed
wiederherstellen

echo
echo "── TreeSemanticsTest: die Liste dazwischen behält ihre Rolle ──"
#
# Ein `<li>` bringt `listitem` mit, und damit stünde zwischen `tree` und
# `treeitem` eine Liste.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('<li v-for="table in tables" :key="`${table.schema}.${table.name}`" role="none">',
              '<li v-for="table in tables" :key="`${table.schema}.${table.name}`">', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Liste mit eigener Rolle" &&
pruefe "Liste mit eigener Rolle" \
  TreeSemanticsTest::test_the_list_between_them_carries_no_role_of_its_own failed
wiederherstellen

echo
echo "── TreeSemanticsTest: die Tabulatorstation steht fest ──"
#
# Der Mangel, den erst die Frage nach dem Beleg gefunden hat (§20.27): Der Baum
# war eine Station, aber wer ihn verliess und mit Tab zurückkam, stand wieder
# oben statt dort, wo er war.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(':tabindex="stops(table.name) ? 0 : -1"', 'tabindex="0"', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "feste Tabulatorstation" &&
pruefe "feste Tabulatorstation" \
  TreeSemanticsTest::test_a_tree_is_one_tab_stop_and_it_moves failed
wiederherstellen

echo
echo "── TreeSemanticsTest: … und der Baum merkt sich den Fokus nicht ──"
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(' @focusin="remember">', '>', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Fokus ohne Gedächtnis" &&
pruefe "Fokus ohne Gedächtnis" \
  TreeSemanticsTest::test_a_tree_is_one_tab_stop_and_it_moves failed
wiederherstellen

echo
echo "── TreeSemanticsTest: ein Baum ohne Pfeiltasten ──"
#
# Sonst ist er eine Liste von Knöpfen, durch die man tabbt — und genau das
# unterscheidet ihn von der Tabelle, die er ersetzt hat.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(' @keydown="navigate" @focusin="remember">', ' @focusin="remember">', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Baum ohne Pfeiltasten" &&
pruefe "Baum ohne Pfeiltasten" \
  TreeSemanticsTest::test_a_tree_is_operated_with_the_arrow_keys failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TreeSemanticsTest passed

echo
echo "── ConsoleFanoutTest: Aufklappen holt etwas ──"
#
# Jede Katalogfrage der Konsole legt eine Datenbankrolle an und räumt sie ab.
# Die drei Ziele unter einem Zweig sind Beschriftungen und keine Daten (§11.1);
# aus jedem Klick auf ein Dreieck würde sonst ein befristeter Zugang.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("""  expanded.value = offen
}""", """  expanded.value = offen

  void ask(props.database.id, 'columns', { table })
}""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Aufklappen fragt" &&
pruefe "Aufklappen fragt" ConsoleFanoutTest::test_expanding_a_branch_asks_nothing failed
wiederherstellen

echo
echo "── ConsoleFanoutTest: eine Anfrage in der Schleife über die Tabellen ──"
#
# Zwanzig Tabellen wären zwanzig befristete Datenbankzugänge — für eine Ansicht,
# die niemand angefordert hat.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("function toggle(table: string): void {",
              """function vorladen(): void {
  tables.value.map((t) => ask(props.database.id, 'columns', { table: t.name }))
}

function toggle(table: string): void {""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Anfrage in der Schleife" &&
pruefe "Anfrage in der Schleife" \
  ConsoleFanoutTest::test_no_request_stands_inside_a_loop_over_the_tables failed
wiederherstellen

echo
echo "── ConsoleFanoutTest: der Knopf, den man aus Freundlichkeit einbaut ──"
#
# „Alles aufklappen" ist die naheliegendste Ergänzung an einem Baum und in
# dieser Konsole die teuerste: Er legt so viele Datenbankrollen an, wie die
# Datenbank Tabellen hat — und sieht dabei aus wie ein Komfort.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('          <ul v-else ref="tree" class="tree"',
              '          <button type="button" class="button">Alles aufklappen</button>\n'
              '          <ul v-else ref="tree" class="tree"', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Knopf für alles" &&
pruefe "Knopf für alles" \
  ConsoleFanoutTest::test_there_is_no_control_that_opens_everything failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ConsoleFanoutTest passed

echo
echo "── AuditContentTest: der Eintrag trägt den geänderten Wert ──"
#
# Ein Protokoll, das den Inhalt einer geänderten Zeile führt, ist eine zweite
# Kopie der Kundendaten an einer Stelle, an der sie niemand vermutet — und sie
# überlebt das Löschen der Zeile.
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                    'key' => $key === [] ? null : $key,",
              "                    'key' => $key === [] ? null : $key,\n                    'values' => $values,", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "Wert im Protokoll" &&
pruefe "Wert im Protokoll" AuditContentTest::test_no_entry_carries_a_cell_value failed
wiederherstellen

echo
echo "── AuditContentTest: eine der drei Handlungen fehlt ──"
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        'delete' => 'database.console.row.removed',\n", "", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "Löschen ohne Protokoll" &&
pruefe "Löschen ohne Protokoll" \
  AuditContentTest::test_all_three_changing_actions_are_recorded failed
wiederherstellen

echo
echo "── AuditContentTest: der Eintrag beim Öffnen ist nicht entprellt ──"
#
# Eine fehlende Entprellung bemerkt niemand: Sie sieht beim ersten Mal genauso
# aus und fällt erst nach einer Woche auf, wenn das Protokoll nur noch aus
# Konsolenzeilen besteht — also genau dann, wenn es gebraucht wird.
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $this->audit->throttled(", "        $this->audit->record(", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "Öffnen ohne Entprellung" &&
pruefe "Öffnen ohne Entprellung" \
  AuditContentTest::test_the_entry_on_opening_is_debounced_to_one_per_hour failed
wiederherstellen

echo
echo "── AuditContentTest: … und die Spanne ist keine Stunde ──"
vorher_datei app/Http/Controllers/DatabaseController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/DatabaseController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("CONSOLE_AUDIT_SECONDS = 3600;", "CONSOLE_AUDIT_SECONDS = 60;", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/DatabaseController.php "Spanne unter einer Stunde" &&
pruefe "Spanne unter einer Stunde" \
  AuditContentTest::test_the_entry_on_opening_is_debounced_to_one_per_hour failed
wiederherstellen

echo
echo "── AuditContentTest: die Entprellung fragt nicht, wer gehandelt hat ──"
#
# Ohne die dritte Bedingung verschluckt sie den Fall, für den man das Protokoll
# liest: Sieht ein Admin über „Anmelden als" in dieselbe Datenbank, in der der
# Kunde gerade war, gehört das in einen eigenen Eintrag.
vorher_datei app/Support/Audit/Audit.php
python3 - <<'PY2'
p = 'app/Support/Audit/Audit.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            ->where('account_id', $acting)\n", "", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Audit/Audit.php "Entprellung ohne Person" &&
pruefe "Entprellung ohne Person" \
  AuditContentTest::test_the_debounce_asks_who_and_not_only_what failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AuditContentTest passed

echo
echo "── ResultEncodingTest: der JSON-Weg ohne --raw ──"
#
# `mysql --batch` maskiert die Maskierung einer JSON-Zeile: Aus einem Tabulator
# im Wert wird `\\t` im schon maskierten JSON — gültiges JSON mit einem falschen
# Wert. Eine Maskierung über einer Maskierung ist schlimmer als ein
# Parserfehler; der fiele auf.
vorher_datei agent/src/Db/Session.php
python3 - <<'PY2'
p = 'agent/src/Db/Session.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            $arguments[] = '--raw';", "            $arguments[] = '--batch';", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Session.php "JSON-Weg ohne --raw" &&
pruefe "JSON-Weg ohne --raw" \
  ResultEncodingTest::test_the_json_way_asks_for_raw_output failed
wiederherstellen

echo
echo "── ResultEncodingTest: … und die Gegenrichtung ──"
#
# Auf dem Textweg ist die Maskierung des Klienten gerade die Sicherung, die die
# Zeilentrennung trägt. Beide Fehler sind still, und deshalb steht hier beides.
vorher_datei agent/src/Db/Session.php
python3 - <<'PY2'
p = 'agent/src/Db/Session.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""        $arguments = self::CLIENT;
        $file = null;""", """        $arguments = self::CLIENT;
        $arguments[] = '--raw';
        $file = null;""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Session.php "Textweg mit --raw" &&
pruefe "Textweg mit --raw" \
  ResultEncodingTest::test_the_text_way_never_asks_for_raw_output failed
wiederherstellen

echo
echo "── ResultEncodingTest: eine binäre Spalte als Wert ──"
#
# Ein BLOB mit ungültigem UTF-8 macht die ganze Zeile über json_decode()
# unlesbar, während MariaDBs JSON_VALID() sie für gültig hält. Am Ergebnis sähe
# man es auch — aber erst, wenn jemand eine Tabelle mit einem BLOB öffnet, und
# dann als „Malformed UTF-8" ohne jeden Hinweis auf die Ursache.
vorher_datei agent/src/Db/Console.php
python3 - <<'PY2'
p = 'agent/src/Db/Console.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                ? sprintf('OCTET_LENGTH(%s)', $identifier)",
              "                ? sprintf('LEFT(CAST(%s AS CHAR), 512)', $identifier)", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Db/Console.php "binäre Spalte als Wert" &&
pruefe "binäre Spalte als Wert" \
  ResultEncodingTest::test_a_binary_column_is_a_length_in_both_engines failed
wiederherstellen

echo
echo "── BlockSpacingTest: die Fuge unter der Knopfreihe fehlt ──"
#
# Der Betreiber hat sie auf einem Bild gefunden: „Tabellen durchsehen" klebte an
# den Bereichen darunter. Gemessen 0px, daneben 26px zwischen Meldung und
# Bereichen — die zweite Zahl ist der Grund, dass die erste etwas heisst.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace(".button-row + .sections,", ".button-row + .sections-x,", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Fuge unter der Knopfreihe" &&
pruefe "Fuge unter der Knopfreihe" \
  BlockSpacingTest::test_the_rule_still_lists_what_it_used_to failed
wiederherstellen

echo
echo "── BlockSpacingTest: eine Meldung unter der Blätterleiste ──"
#
# Der fünfte Fall, und er hat die Frage dieses Wächters umgestellt: nicht mehr
# „wo steht eine Knopfreihe?", sondern „welche zwei bündigen Bausteine stehen
# aneinander?"
#
# **Der Eingriff ist am 20. August umgezogen, und zwar nicht freiwillig.** Die
# Bilderrunde hat der Liste `.section-note` hinzugefügt und drei Zeilen mit
# `.quiet` daruntergesetzt; damit endete der gegriffene Text nicht mehr auf
# ` {`, sondern auf `,` — und der Eingriff fand seinen Text nicht mehr. Gegriffen
# wird deshalb jetzt nur noch das Stück um `.pager`, das die Regel trägt, und
# nicht ihr Anfang und ihr Ende.
#
# > **Ein Eingriff, der die ganze Zeile greift, zieht mit jedem Eintrag um, der
# > dazukommt.**
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace(".pager, .cell-value, .button-row, .section-note) + .notice,",
              ".cell-value, .button-row, .section-note) + .notice,", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Meldung unter der Blätterleiste" &&
pruefe "Meldung unter der Blätterleiste" \
  BlockSpacingTest::test_every_seam_between_two_flush_blocks_is_covered failed
wiederherstellen

echo
echo "── BlockSpacingTest: die Begründung einer Ausnahme fällt weg ──"
#
# Der Wächter über den Wächter. Hier stand ein Bruch über eine umbenannte
# Klasse in einer Vorlage — er zeigte ins Leere, seit die beiden gepflegten
# Listen durch die Ableitung aus app.css ersetzt sind (P6, Schritt 5d).
#
# `.empty` steht in HAS_OWN_AIR mit der Begründung, seine Luft komme aus dem
# Padding. Bekommt es einen Rand, ist die Ausnahme ein Rest — und nimmt einen
# Baustein aus dem Blick, der gar keine mehr braucht.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('.empty {\n  margin: 0;\n  padding: 22px 0;',
              '.empty {\n  margin: 22px 0;\n  padding: 0;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Ausnahme ohne Begründung" &&
pruefe "Ausnahme ohne Begründung" \
  BlockSpacingTest::test_every_listed_block_really_exists failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BlockSpacingTest passed

echo
echo "── BlockSpacingTest: die sechste Fuge, und ein Baustein, den niemand einträgt ──"
#
# Der sechste Fall (docs/53, Befund 7): `.crumbs` kam in P6 dazu, und die Regel
# nannte `.sections`. Der zweite Bruch ist der wichtigere — er stellt den
# Zustand her, den die alten gepflegten Listen gar nicht sehen konnten: ein
# neuer bündiger Baustein, den niemand eingetragen hat.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('.button-row + .split {', '.button-row + .spalt {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Fuge unter dem Formular" &&
pruefe "Fuge unter dem Formular" \
  BlockSpacingTest::test_every_seam_between_two_flush_blocks_is_covered failed
wiederherstellen

vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace("""  gap: var(--gap);
  margin-bottom: var(--block-gap);
  font-size: var(--text-small);
}""", """  gap: var(--gap);
  font-size: var(--text-small);
}""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Baustein wird buendig" &&
pruefe "ein Baustein wird buendig, ohne dass jemand ihn eintraegt" \
  BlockSpacingTest::test_every_seam_between_two_flush_blocks_is_covered failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BlockSpacingTest passed

echo
echo "── BlockSpacingTest: <Link> gilt wieder als leeres Element ──"
#
# P6 Schritt 9. Die Tiefenzählung hielt Inertias `<Link>` für ein `<link>` —
# das öffnende Tag erhöhte die Tiefe nicht, das schliessende senkte sie, und
# alles dahinter bekam den falschen Elternteil.
#
# **Der Eingriff macht den Wächter nicht blind, sondern falschsichtig.** Er
# meldet danach `sections + scrolls` in `Domains/Show.vue` — zwei Bausteine, die
# dort ineinander liegen und nicht nebeneinander. Genau das ist der Biss: Die
# Sperrklinke über OPEN_SEAMS schlägt in beide Richtungen an, und eine Fuge, die
# es nicht gibt, ist so rot wie eine, die niemand eingetragen hat.
vorher_datei tests/Feature/BlockSpacingTest.php
python3 - <<'PY2'
p = 'tests/Feature/BlockSpacingTest.php'
s = open(p, encoding='utf-8').read()
alt = 'in_array($tag[2], $void, true)'
assert s.count(alt) == 3, 'Der Eingriff trifft nicht mehr drei Stellen: %d' % s.count(alt)
s = s.replace(alt, 'in_array(strtolower($tag[2]), $void, true)')
s = s.replace('in_array($folgend[2], $void, true)', 'in_array(strtolower($folgend[2]), $void, true)', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Feature/BlockSpacingTest.php "Link als leeres Element" &&
pruefe "Link als leeres Element" \
  BlockSpacingTest::test_every_seam_between_two_flush_blocks_is_covered failed
wiederherstellen

echo
echo "── BlockSpacingTest: die Regel der Vorlage fällt weg ──"
#
# Die zweite Korrektur aus Schritt 9: Die Kanten werden je **Element** gefragt
# und die `<style>`-Blöcke der Vorlage mitgelesen. `Domains/Show.vue` schliesst
# seine Fuge mit `.footer-row` an einem `class="button-row footer-row"` — zwei
# Klassen an einem Element, und nur die zweite bringt den Rand mit.
#
# Ohne diese Zeile klebt die Knopfreihe wieder an den Bereichen darüber, und
# der Wächter muss `sections + button-row` melden.
vorher_datei resources/js/Pages/Domains/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Domains/Show.vue'
s = open(p, encoding='utf-8').read()
alt = '.footer-row {\n  margin-top: var(--block-gap);\n}'
assert s.count(alt) == 1, 'Die Regel steht nicht mehr genau einmal da: %d' % s.count(alt)
s = s.replace(alt, '.footer-row {\n  padding-top: 0;\n}', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Domains/Show.vue "Regel der Vorlage faellt weg" &&
pruefe "Regel der Vorlage faellt weg" \
  BlockSpacingTest::test_every_seam_between_two_flush_blocks_is_covered failed
wiederherstellen

echo
echo "── BlockSpacingTest: eine Tabellenzelle wird wieder ein Block ──"
#
# Die dritte Korrektur aus Schritt 9. Zwei Zellen einer `.stacks`-Tabelle sind
# keine zwei Blöcke im Fluss — ihren Abstand macht `.stacks td`. Drei Fälle
# derselben Familie standen als Ausnahme in OPEN_SEAMS, bevor daraus eine Regel
# wurde.
#
# Nimmt man `td` aus der Liste, kommen sie alle drei zurück.
vorher_datei tests/Feature/BlockSpacingTest.php
python3 - <<'PY2'
p = 'tests/Feature/BlockSpacingTest.php'
s = open(p, encoding='utf-8').read()
alt = "TABLE_PARTS = ['td', 'th',"
assert s.count(alt) == 1, 'Die Liste steht nicht mehr da: %d' % s.count(alt)
s = s.replace(alt, "TABLE_PARTS = ['th',", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Feature/BlockSpacingTest.php "Tabellenzelle als Block" &&
pruefe "Tabellenzelle als Block" \
  BlockSpacingTest::test_every_seam_between_two_flush_blocks_is_covered failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BlockSpacingTest passed

echo
echo "── MobileLayoutTest: eine Wertzelle, die nicht brechen darf ──"
#
# Die Messung war grün und die Ansicht kaputt: Eine bei 512 Zeichen gekürzte
# Zelle machte den Inhalt der Zeilentabelle 5710px breit statt 1907 — bei 390px
# zehn Bildschirme Rollen durch eine einzige Zelle.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace(""".rows .cell {
  max-width: 48ch;""", """.rows .cell {
  max-width: 48ch;
  white-space: nowrap;""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Wertzelle mit nowrap" &&
pruefe "Wertzelle mit nowrap" \
  MobileLayoutTest::test_a_value_cell_of_the_rows_view_may_break failed
wiederherstellen

echo
echo "── MobileLayoutTest: … und ihr wird der Umbruch entzogen ──"
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace("""  overflow-wrap: anywhere;

  /*
   * **Weissraum bleibt stehen""", """
  /*
   * **Weissraum bleibt stehen""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Wertzelle ohne Umbruch" &&
pruefe "Wertzelle ohne Umbruch" \
  MobileLayoutTest::test_a_value_cell_of_the_rows_view_may_break failed
wiederherstellen

echo
echo "── MobileLayoutTest: die Angabe der Blätterleiste bricht nicht ──"
#
# Ein `nowrap` über einer Zahl, die wächst, ist keine Zusage über die Zeile — es
# ist eine über den Bestand. „Seite 2 von 5" passt immer, „1.001–1.050 von mehr
# als 1.050" schob 8px.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace(""".pager-state {
  margin: 0;""", """.pager-state {
  margin: 0;
  white-space: nowrap;""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Blätterangabe mit nowrap" &&
pruefe "Blätterangabe mit nowrap" \
  MobileLayoutTest::test_the_pager_state_may_break failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" MobileLayoutTest passed

echo
echo "── ViewStateTest: die Ladung meldet nicht mehr, ob sie durchkam ──"
#
# Ohne den Rückgabewert gibt es keinen Rückweg: Der Aufrufer kann nicht wissen,
# dass sein Zustand eine Anzeige beschreibt, die es nicht gibt.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('async function loadPage(): Promise<boolean> {', 'async function loadPage(): Promise<void> {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Ladung ohne Rückmeldung" &&
pruefe "Ladung ohne Rückmeldung" \
  ViewStateTest::test_loading_a_page_says_whether_it_worked failed
wiederherstellen

echo
echo "── ViewStateTest: eine gesicherte Angabe ohne Rückweg ──"
#
# Der Fall, der wiederkommt: Die Sicherung kennt sie, der Rückweg nicht. Alles
# andere steht danach richtig da, und diese eine nicht.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('  draft.value = zuvor.draft\n', '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Sicherung ohne Rückweg" &&
pruefe "Sicherung ohne Rückweg" \
  ViewStateTest::test_every_saved_field_is_restored failed
wiederherstellen

echo
echo "── ViewStateTest: ein Griff fasst an, was er nicht zurücknehmen kann ──"
#
# Eine neue Angabe der Sicht wird in einem Griff gesetzt, und die Sicherung
# erfährt davon nichts.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
alt = "    offset.value = 0\n    trail.value = []\n  })\n}\n\nasync function applyFilter"
neu = "    offset.value = 0\n    trail.value = []\n    openView.value = 'rows'\n  })\n}\n\nasync function applyFilter"
s = s.replace(alt, neu, 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Griff ohne Rückweg" &&
pruefe "Griff ohne Rückweg" \
  ViewStateTest::test_a_change_touches_nothing_it_cannot_take_back failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ViewStateTest passed

echo
echo "── AnnounceWithdrawalTest: der Rückweg nimmt nichts zurück ──"
#
# Ohne ihn baut die nächste Seite ihren eigenen — genau so ist useAnnounce
# selbst entstanden.
vorher_datei resources/js/Composables/useAnnounce.ts
python3 - <<'PY2'
p = 'resources/js/Composables/useAnnounce.ts'
s = open(p, encoding='utf-8').read()
s = s.replace('export function dismiss(): void {\n    message.value = null\n}',
              'export function dismiss(): void {\n    message.value = message.value\n}', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Composables/useAnnounce.ts "Rücknahme ohne Wirkung" &&
pruefe "Rücknahme ohne Wirkung" \
  AnnounceWithdrawalTest::test_there_is_a_way_to_take_it_back failed
wiederherstellen

echo
echo "── AnnounceWithdrawalTest: der Fehlersatz lässt die grüne Meldung stehen ──"
#
# Der Fund aus dem Abnahmelauf von P5c: Über der roten Meldung stand noch die
# grüne der Handlung davor, und der Kunde las beide über derselben Taste.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('  dismiss()\n\n  failure.value =\n', '  failure.value =\n', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Fehler ohne Rücknahme" &&
pruefe "Fehler ohne Rücknahme" \
  AnnounceWithdrawalTest::test_reporting_a_failure_withdraws_the_success failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" AnnounceWithdrawalTest passed

echo
echo "── CountedNounTest: eine Zahl klebt wieder an ihrem Plural ──"
#
# Der Fund aus dem Abnahmelauf von P5c, auf beiden Systemen: eine Tabelle mit
# genau einer Zeile, und darüber die Mehrzahl.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
alt = "`geschätzt ${counted(tabelle.rows, 'Zeile', 'Zeilen')}`"
neu = "`geschätzt ${tabelle.rows.toLocaleString('de-DE')} Zeilen`"
s = s.replace(alt, neu, 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "Plural fest angehängt" &&
pruefe "Plural fest angehängt" \
  CountedNounTest::test_no_count_is_glued_to_a_plural_noun failed
wiederherstellen

echo
echo "── CountedNounTest: das Muster findet nichts mehr ──"
#
# Eine leere Trefferliste liefert ein kaputtes Muster genauso zuverlässig wie
# eine saubere Oberfläche. Ohne die Gegenprobe ist die Prüfung darüber wertlos.
vorher_datei tests/Feature/CountedNounTest.php
python3 - <<'PY2'
p = 'tests/Feature/CountedNounTest.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'Zeilen', 'Tabellen',", "'Tabellen',", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Feature/CountedNounTest.php "Muster ohne Zeilen" &&
pruefe "Muster ohne Zeilen" \
  CountedNounTest::test_the_pattern_would_find_one failed
wiederherstellen

echo
echo "── CountedNounTest: eine Seite trifft die Entscheidung wieder selbst ──"
#
# Dreimal derselbe Fehler hiess, dass die Stelle fehlte, an der er einmal
# richtig gemacht wird. Wer sie wieder verlässt, macht ihn ein viertes Mal.
#
# **Dieser Eingriff hat am 15. August seinen Biss verloren, und zwar lautlos.**
# Er nimmt *einer* Vorlage die Einbindung weg; die Untergrenze im Waechter
# zaehlte *alle* und stand auf drei. Solange es genau drei Benutzer gab, fiel
# sie auf zwei und der Waechter biss — mit dem Dateimanager als viertem blieben
# drei uebrig, und der Bruchlauf meldete „ohne Biss".
#
# Der Waechter zaehlt seitdem nicht mehr gegen eine Zahl, und dieser Eingriff
# baut die Form nach, die counted() ersetzt: zwei einzelne Woerter hinter einer
# Eins. Die trifft test_no_page_decides_the_word_itself, egal wie viele andere
# Vorlagen die Entscheidung von der richtigen Stelle holen.
vorher_datei resources/js/Pages/Audit/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Audit/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("import { counted } from '../../Composables/useCounted'\n", '', 1)
alt = ":subline=\"counted(events.total, 'Eintrag', 'Eintraege')\""
s = s.replace(
    alt.replace('Eintraege', 'Einträge'),
    ':subline="`${events.total} ${events.total === 1 ? \'Eintrag\' : \'Eintraege\'}`"'.replace('Eintraege', 'Einträge'),
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Audit/Index.vue "Entscheidung wieder in der Seite" &&
pruefe "Entscheidung wieder in der Seite" \
  CountedNounTest::test_no_page_decides_the_word_itself failed
wiederherstellen

echo
echo "── CountedNounTest: eine Vorlage bindet ein und ruft nicht mehr auf ──"
#
# Die andere Richtung derselben Regel, und der Fall, den ein Umbau wirklich
# macht: Der Aufruf wird ersetzt, die Einbindung bleibt stehen. Sie sieht dann
# aus wie eine Zusage, dass die Entscheidung von der richtigen Stelle kommt —
# und die Seite trifft sie längst selbst.
vorher_datei resources/js/Pages/Plans/Form.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Plans/Form.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "${counted(props.subscriptions, 'Abonnement', 'Abonnements')} gebunden",
    '${props.subscriptions} mal gebunden',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Plans/Form.vue "Einbindung ohne Aufruf" &&
pruefe "eine Vorlage bindet ein und ruft nicht auf" \
  CountedNounTest::test_the_decision_lives_in_one_place failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CountedNounTest passed

echo
echo "── CellWhitespaceTest: die Zelle fasst Weissraum wieder zusammen ──"
#
# Der Fund aus dem Abnahmelauf von P5c: `a\tb`, `a  b` und `a b` ergaben exakt
# dieselben 25x16 Pixel. Drei gespeicherte Werte, eine Anzeige.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
alt = '  white-space: pre-wrap;\n\n  /*\n   * **Und der Tabulator'
neu = '  white-space: normal;\n\n  /*\n   * **Und der Tabulator'
s = s.replace(alt, neu, 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Zelle ohne Weissraum" &&
pruefe "Zelle ohne Weissraum" \
  CellWhitespaceTest::test_a_row_cell_keeps_the_whitespace_it_was_given failed
wiederherstellen

echo
echo "── CellWhitespaceTest: der Tabulator im Quelltextabstand ──"
#
# Tailwinds Reset setzt `tab-size: 4` auf `html`. Damit ist `a\tb` 29px breit und
# `a  b` 28px — verschieden, aber nicht sichtbar.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('  tab-size: 8;\n}', '  tab-size: 4;\n}', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Tabulator wie Quelltext" &&
pruefe "Tabulator wie Quelltext" \
  CellWhitespaceTest::test_a_tab_is_wide_enough_to_be_one failed
wiederherstellen

echo
echo "── CellWhitespaceTest: die Einzelsicht zeigt es anders als die Übersicht ──"
#
# Sie ist der einzige Ort, an dem ein gekürzter Wert vollständig steht. Stünde er
# dort anders da, hätte der Kunde zwei Darstellungen und keine Auskunft.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('  tab-size: 8;\n  overflow-wrap: anywhere;', '  overflow-wrap: anywhere;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Einzelsicht mit anderem Abstand" &&
pruefe "Einzelsicht mit anderem Abstand" \
  CellWhitespaceTest::test_the_single_cell_view_shows_it_the_same_way failed
wiederherstellen

echo
echo "── CellWhitespaceTest: der Leser findet den Selektor nicht mehr ──"
#
# Ohne diese Gegenprobe stünde die Zustimmung der drei Prüfungen darüber auf
# `null === null` — ein Selektor, den es nicht gibt, liefert für jede
# Eigenschaft `null`.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('.rows .cell {', '.rows .zelle {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Selektor umbenannt" &&
pruefe "Selektor umbenannt" \
  CellWhitespaceTest::test_the_reader_finds_a_known_declaration failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CellWhitespaceTest passed

echo
echo "── MobileLayoutTest: die Beizeile bricht nicht ──"
#
# Gemessen beim Einbau der Systemmarke: Ein Datenbankname von 61 Zeichen schob
# das Dokument bei 390px um 59px aus dem Bild. Die Marke war nicht die Ursache —
# mit und ohne sie waren es dieselben 59px.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
alt = """  color: var(--text-muted);
  overflow-wrap: anywhere;
}"""
neu = """  color: var(--text-muted);
}"""
s = s.replace(alt, neu, 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Beizeile ohne Umbruch" &&
pruefe "Beizeile ohne Umbruch" \
  MobileLayoutTest::test_the_subline_can_break failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" MobileLayoutTest passed

echo
echo "── EngineLabelTest: die Konsole verschweigt ihr System ──"
#
# Der Zustand, in dem die Konsole ihren ersten Tag verbracht hat: engine_label
# im Payload, nirgends gezeigt. Beide Systeme sehen gleich aus, und der Name
# verraet sein System nur dem, der die Praefixregeln kennt.
vorher_datei resources/js/Pages/Databases/Console.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Console.vue'
s = open(p, encoding='utf-8').read()
alt = """      {{ props.database.name }}
      <Badge kind="neutral">{{ props.database.engine_label }}</Badge>"""
neu = """      {{ props.database.name }}"""
s = s.replace(alt, neu, 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Console.vue "System verschwiegen" &&
pruefe "System verschwiegen" \
  EngineLabelTest::test_a_page_that_gets_the_engine_shows_it failed
wiederherstellen

echo
echo "── EngineLabelTest: das System als nackter Text ──"
#
# Dieselbe Angabe in zwei Formen ist eine Fassung zu viel — der Grund, aus dem
# es Badge.vue ueberhaupt gibt.
vorher_datei resources/js/Pages/Databases/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Databases/Index.vue'
s = open(p, encoding='utf-8').read()
alt = '<Badge kind="neutral">{{ row.engine_label }}</Badge>'
neu = '{{ row.engine_label }}'
s = s.replace(alt, neu, 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Databases/Index.vue "System ohne Marke" &&
pruefe "System ohne Marke" \
  EngineLabelTest::test_all_of_them_show_it_the_same_way failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" EngineLabelTest passed

echo
echo "── SandboxReachTest: das chroot aus der Sandbox nehmen ──"
#
# Die Grenze von P6 ist keine Pruefung, sondern ein Prozess ohne Rechte in
# einem chroot. docs/50 §3 hat gemessen, was die naheliegende Ersetzung taugt:
# realpath()+is_link liess 11081 von 36056 bestandenen Pruefungen ausserhalb
# der Grenze lesen. Wer das chroot herausnimmt, nimmt die Stufe heraus.
vorher_datei agent/src/Sandbox.php
python3 - <<'PY2'
p = 'agent/src/Sandbox.php'
s = open(p, encoding='utf-8').read()
s = s.replace('if (! @chroot($root)) {', 'if (! self::confine($root)) {')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Sandbox.php "chroot aus der Sandbox entfernt" &&
pruefe "chroot aus der Sandbox entfernt" \
  SandboxReachTest::test_the_sandbox_uses_all_of_them failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SandboxReachTest passed

echo
echo "── SandboxReachTest: einsperren an einer zweiten Stelle ──"
#
# Eine Grenze, die an zwei Stellen steht, ist keine. Die zweite Fassung ist
# die, die veraltet — und bei einer Schranke heisst „veraltet" offen.
vorher_datei agent/src/Filesystem.php
python3 - <<'PY2'
p = 'agent/src/Filesystem.php'
s = open(p, encoding='utf-8').read()
s = s.replace('    public static function removeTree(string $path): void\n    {\n',
              '    public static function removeTree(string $path): void\n    {\n        chroot($path);\n')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Filesystem.php "chroot ausserhalb der Sandbox" &&
pruefe "chroot ausserhalb der Sandbox" \
  SandboxReachTest::test_only_the_sandbox_confines failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SandboxReachTest passed

echo
echo "── SandboxReachTest: der Socket des Agenten bleibt im Kind offen ──"
#
# AgentIdentityTest hat diese Zeile schon einmal bezahlt: Einer der zwei
# Gruende, warum docs/38 §6 den Kennungswechsel im Runner verwarf, war, dass
# der geforkte Prozess den Socket des Agenten erbt. P6 forkt trotzdem — also
# muss der Socket weg, bevor fremder Code im Kind laeuft.
vorher_datei agent/src/Sandbox.php
python3 - <<'PY2'
p = 'agent/src/Sandbox.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        try {\n            self::closeInherited($close);\n', '        try {\n')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Sandbox.php "closeInherited nicht mehr die erste Zeile im Kind" &&
pruefe "closeInherited nicht mehr die erste Zeile im Kind" \
  SandboxReachTest::test_the_child_closes_what_it_inherited failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SandboxReachTest passed

echo
echo "── SandboxCredentialsTest: der Beleg fällt wieder unter den Tisch ──"
#
# docs/61 §0a. Das Kind der Sandbox erhebt uid und Gruppen und schickt beides
# zurück; parent() prüfte sie und warf sie dann weg. Punkt 13 und 14 des
# Abnahmekriteriums waren damit von aussen gar nicht messbar — gefunden hat es
# kein Test, sondern das Ausschreiben des Laufs.
vorher_datei agent/src/Sandbox.php
python3 - <<'PY2'
p = 'agent/src/Sandbox.php'
s = open(p, encoding='utf-8').read()
alt = 'return self::parent($pid, $parentSide, $ranAs);'
assert s.count(alt) == 1, 'Die Zielzeile steht nicht genau einmal da: %d' % s.count(alt)
s = s.replace(alt, 'return self::parent($pid, $parentSide, $vergessen);', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Sandbox.php "Beleg verlaesst die Sandbox nicht" &&
pruefe "Beleg verlaesst die Sandbox nicht" \
  SandboxCredentialsTest::test_the_sandbox_hands_the_credentials_out failed
wiederherstellen

echo
echo "── SandboxCredentialsTest: eine Operation reicht den Context nicht durch ──"
#
# Zwölf melden, eine nicht — und keine sagt es. Genau die Bauart, wegen der der
# Beleg nicht am Ergebnis der Operation hängt, sondern an der Anfrage.
vorher_datei agent/src/Ops/FilesRead.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesRead.php'
s = open(p, encoding='utf-8').read()
alt = '$workspace->run($context, '
assert s.count(alt) == 1, 'Die Zielzeile steht nicht genau einmal da: %d' % s.count(alt)
s = s.replace(alt, '$workspace->run(', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesRead.php "Operation ohne Context" &&
pruefe "Operation ohne Context" \
  SandboxCredentialsTest::test_every_file_operation_passes_the_request_along failed
wiederherstellen

echo
echo "── SandboxCredentialsTest: die Antwort trägt ihn nicht mehr ──"
vorher_datei agent/src/Connection.php
python3 - <<'PY2'
p = 'agent/src/Connection.php'
s = open(p, encoding='utf-8').read()
alt = '$data[Context::RAN_AS] = $ranAs;'
assert s.count(alt) == 1, 'Die Zielzeile steht nicht genau einmal da: %d' % s.count(alt)
s = s.replace(alt, '$data = $data;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Connection.php "Antwort ohne Beleg" &&
pruefe "Antwort ohne Beleg" \
  SandboxCredentialsTest::test_the_answer_carries_it_once_and_no_operation_builds_it failed
wiederherstellen

echo
echo "── SandboxCredentialsTest: eine Operation baut ihn selbst ──"
#
# Der Eingriff, der am harmlosesten aussieht. Eine Operation, die den Beleg
# selbst in ihr Ergebnis schreibt, ist richtig — und macht die Regel wieder zu
# einer, die dreizehnmal befolgt werden muss.
vorher_datei agent/src/Ops/FilesRead.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesRead.php'
s = open(p, encoding='utf-8').read()
alt = '    public function execute('
assert s.count(alt) == 1, 'Die Zielzeile steht nicht genau einmal da: %d' % s.count(alt)
s = s.replace(alt, "    public const RAN_AS = 'ran_as';\n\n" + alt, 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesRead.php "Operation baut den Beleg selbst" &&
pruefe "Operation baut den Beleg selbst" \
  SandboxCredentialsTest::test_the_answer_carries_it_once_and_no_operation_builds_it failed
wiederherstellen

echo
echo "── SandboxCredentialsTest: zwei Konten in einer Anfrage ──"
#
# Ein Vorgang gehört zu einem Abonnement. Liefe er zweimal unter verschiedenen
# Kennungen, wäre die Frage „unter wem lief er?" nicht mehr beantwortbar — und
# das ist die einzige Frage, die dieser Beleg hat.
vorher_datei agent/src/Context.php
python3 - <<'PY2'
p = 'agent/src/Context.php'
s = open(p, encoding='utf-8').read()
alt = 'if ($this->ranAs !== null && $this->ranAs !== $ranAs) {'
assert s.count(alt) == 1, 'Die Zielzeile steht nicht genau einmal da: %d' % s.count(alt)
s = s.replace(alt, 'if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Context.php "zwei Konten in einer Anfrage" &&
pruefe "zwei Konten in einer Anfrage" \
  SandboxCredentialsTest::test_two_accounts_in_one_request_are_refused failed
wiederherstellen

echo
echo "── SandboxCredentialsTest: der Rückbau meldet nicht mehr ──"
#
# Der Teil, den der erste Wurf von docs/61 §0a ausgelassen hat: removeInside und
# purgeContents gehen genauso durch die Sandbox wie die dreizehn files.*, und sie
# sind der Baumlauf, gegen den Punkt 6 des Abnahmekriteriums antritt. Aufgefallen
# auf cloudsrv24 an einem subscription.remove mit NULL in der Spalte ran_as.
vorher_datei agent/src/Filesystem.php
python3 - <<'PY2'
p = 'agent/src/Filesystem.php'
s = open(p, encoding='utf-8').read()
alt = "        if ($ranAs !== null) {\n            $context?->recordRanAs($ranAs);\n        }\n\n"
assert s.count(alt) == 2, 'Der Eingriff trifft nicht mehr zwei Stellen: %d' % s.count(alt)
s = s.replace(alt, '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Filesystem.php "Rueckbau meldet nicht" &&
pruefe "Rueckbau meldet nicht" \
  SandboxCredentialsTest::test_the_teardown_reports_too failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SandboxCredentialsTest passed
pruefe "  … zurückgesetzt wieder grün" SandboxCredentialsTest passed

echo
echo "── PrivilegeDropTest: initgroups faellt weg ──"
#
# posix_setgroups() gibt es in PHP nicht. Ein Kind, das nur setgid und setuid
# aufruft, behaelt die Zusatzgruppen von root und liest damit eine Datei mit
# root:root 0640 — gemessen in docs/50 §5, und im Container zuerst unsichtbar,
# weil root dort eine leere Zusatzgruppenliste hat.
vorher_datei agent/src/Sandbox.php
python3 - <<'PY2'
p = 'agent/src/Sandbox.php'
s = open(p, encoding='utf-8').read()
s = s.replace('posix_initgroups(', 'self::groups(')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Sandbox.php "initgroups aus der Rechteabgabe entfernt" &&
pruefe "initgroups aus der Rechteabgabe entfernt" \
  PrivilegeDropTest::test_the_drop_happens_in_the_only_order_that_works failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PrivilegeDropTest passed

echo
echo "── PrivilegeDropTest: setuid vor setgid ──"
#
# Nach setuid darf ein Prozess seine Gruppe nicht mehr wechseln. Die falsche
# Reihenfolge laesst das setgid fehlschlagen, und zwar leise.
vorher_datei agent/src/Sandbox.php
python3 - <<'PY2'
p = 'agent/src/Sandbox.php'
s = open(p, encoding='utf-8').read()
s = s.replace("! posix_setgid($account['gid']) || ! posix_setuid($account['uid'])",
              "! posix_setuid($account['uid']) || ! posix_setgid($account['gid'])")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Sandbox.php "setuid vor setgid" &&
pruefe "setuid vor setgid" \
  PrivilegeDropTest::test_the_drop_happens_in_the_only_order_that_works failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PrivilegeDropTest passed

echo
echo "── PrivilegeDropTest: der Elternprozess glaubt dem Beleg ──"
#
# Das Kind meldet uid und Gruppen mit. Ein Elternprozess, der sie nur
# durchreicht, hat einen Beleg eingesammelt und nicht gelesen — und ein
# Ergebnis, das behauptet als root gelaufen zu sein, ist ein Fehler und kein
# Ergebnis (docs/51 §4, Punkt 13).
vorher_datei agent/src/Sandbox.php
python3 - <<'PY2'
p = 'agent/src/Sandbox.php'
s = open(p, encoding='utf-8').read()
s = s.replace("if (($decoded['uid'] ?? 0) === 0 || in_array(0, $decoded['groups'] ?? [0], true)) {",
              "if (in_array(0, $decoded['groups'] ?? [0], true)) {")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Sandbox.php "Elternprozess prueft die gemeldete uid nicht" &&
pruefe "Elternprozess prueft die gemeldete uid nicht" \
  PrivilegeDropTest::test_the_parent_checks_the_proof_it_gets_back failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PrivilegeDropTest passed

echo
echo "── SandboxReachTest: der Baumlauf als root ueber Kundendaten ──"
#
# removeTree() lief bis P6 als root ueber Verzeichnisse, die dem Kunden
# gehoeren. Gegen einen Prozess mit renameat2(RENAME_EXCHANGE) hat der Rueckbau
# dabei in 5 von 120 Durchgaengen Dateien ausserhalb des Abonnements geloescht;
# ueber die Sandbox in denselben 120 Durchgaengen null Mal.
vorher_datei agent/src/Ops/WebSiteRemove.php
python3 - <<'PY2'
p = 'agent/src/Ops/WebSiteRemove.php'
s = open(p, encoding='utf-8').read()
s = s.replace('if (Filesystem::removeInside($site->logDir(), $site->subscriptionRoot(), $site->user, [], $context)) {',
              'if (Filesystem::removeTree($site->logDir()) || true) {')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/WebSiteRemove.php "Baumlauf als root an einer unbegruendeten Stelle" &&
pruefe "Baumlauf als root an einer unbegruendeten Stelle" \
  SandboxReachTest::test_the_raw_tree_walk_is_called_only_where_no_customer_writes failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SandboxReachTest passed

echo
echo "── SandboxReachTest: der Rueckbau raeumt nicht mehr vor ──"
#
# Ohne purgeContents() laeuft removeTree() als root wieder ueber httpdocs — und
# der Eintrag in MAY_WALK_AS_ROOT erlaubt es weiterhin. Erst dieser Test macht
# aus der Erlaubnis eine Bedingung.
vorher_datei agent/src/Ops/SubscriptionRemove.php
python3 - <<'PY2'
p = 'agent/src/Ops/SubscriptionRemove.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        Filesystem::purgeContents($root, $user, [], $context);\n\n', '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/SubscriptionRemove.php "purgeContents aus dem Rueckbau entfernt" &&
pruefe "purgeContents aus dem Rueckbau entfernt" \
  SandboxReachTest::test_the_teardown_purges_before_it_walks failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SandboxReachTest passed

echo
echo "── SandboxReachTest: aufgeraeumt wird nach dem Baumlauf ──"
#
# Die Reihenfolge ist die Sache: Wer zuerst als root abtraegt und danach die
# Sandbox ruft, hat die Kundendaten bereits durchlaufen.
vorher_datei agent/src/Ops/SubscriptionRemove.php
python3 - <<'PY2'
p = 'agent/src/Ops/SubscriptionRemove.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        Filesystem::purgeContents($root, $user, [], $context);\n\n        Filesystem::removeTree($root);',
              '        Filesystem::removeTree($root);\n\n        Filesystem::purgeContents($root, $user, [], $context);')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/SubscriptionRemove.php "Aufraeumen erst nach dem Baumlauf" &&
pruefe "Aufraeumen erst nach dem Baumlauf" \
  SandboxReachTest::test_the_teardown_purges_before_it_walks failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SandboxReachTest passed

echo
echo "── SandboxPreloadTest: die Aufzaehlung wird zur Liste ──"
#
# Nach dem chroot findet der Autoloader agent/src/ nicht mehr. Eine Klasse, die
# erst im Kind gebraucht wird, fehlt erst im Kind — also erst im Fehlerfall.
# Genau das ist beim Bau von Schritt 3 passiert: preload() lud nur
# AgentException, und jede Datei-Operation endete mit „Class Files\Entry not
# found", gemeldet als internal.
vorher_datei agent/src/Sandbox.php
python3 - <<'PY2'
p = 'agent/src/Sandbox.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""foreach (glob(__DIR__.'/Files/*.php') ?: [] as $file) {
            class_exists(__NAMESPACE__.'\\\\Files\\\\'.basename($file, '.php'));
        }""", 'class_exists(Files\\Entry::class);')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Sandbox.php "handgepflegte Liste statt Aufzaehlung" &&
pruefe "handgepflegte Liste statt Aufzaehlung" \
  SandboxPreloadTest::test_the_files_namespace_is_enumerated failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SandboxPreloadTest passed

echo
echo "── SandboxPreloadTest: der erklaerende Autoloader wird nicht gerufen ──"
#
# Der erste Entwurf dieses Waechters prueft nur, dass es die Methode gibt — und
# blieb gruen, als der Bruch den Aufruf entfernte und die Definition
# stehenliess. Ein Autoloader, den niemand registriert, erklaert nichts.
vorher_datei agent/src/Sandbox.php
python3 - <<'PY2'
p = 'agent/src/Sandbox.php'
s = open(p, encoding='utf-8').read()
s = s.replace('            self::explainMissingClasses();\n', '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Sandbox.php "Aufruf entfernt, Definition bleibt" &&
pruefe "Aufruf entfernt, Definition bleibt" \
  SandboxPreloadTest::test_a_missing_class_explains_itself failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SandboxPreloadTest passed

echo
echo "── SandboxPreloadTest: der Autoloader haengt erst nach dem chroot ──"
#
# Zu spaet fuer genau die Klassen, die beim Einsperren gebraucht werden.
vorher_datei agent/src/Sandbox.php
python3 - <<'PY2'
p = 'agent/src/Sandbox.php'
s = open(p, encoding='utf-8').read()
s = s.replace('            self::explainMissingClasses();\n', '')
s = s.replace("            chdir('/');", "            chdir('/');\n            self::explainMissingClasses();")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Sandbox.php "Autoloader erst nach dem chroot registriert" &&
pruefe "Autoloader erst nach dem chroot registriert" \
  SandboxPreloadTest::test_the_explanation_is_registered_before_the_chroot failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SandboxPreloadTest passed

echo
echo "── FileStagingTest: die Quelle des Uploads wird nicht geprueft ──"
#
# files.upload liest seine Quelle als root und ausserhalb jedes Chroots — das
# Ziel ist eingesperrt, die Quelle nicht. Ohne die Pruefung gegen das
# Zwischenlager waere „source: /etc/shadow" ein gueltiger Aufruf.
vorher_datei agent/src/Ops/FilesUpload.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesUpload.php'
s = open(p, encoding='utf-8').read()
s = s.replace("Guard::pathInside($args['source'] ?? null, [Staging::ROOT])",
              "Guard::string($args['source'] ?? null, 'source')")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesUpload.php "Quelle des Uploads ungeprueft" &&
pruefe "Quelle des Uploads ungeprueft" \
  FileStagingTest::test_the_upload_confines_its_source failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FileStagingTest passed

echo
echo "── FileStagingTest: die Quelle wird erst im Kind geoeffnet ──"
#
# Nach dem chroot gibt es ihren Pfad nicht mehr. Der Deskriptor muss vorher
# aufgemacht werden; die Closure nimmt ihn mit hinein.
vorher_datei agent/src/Ops/FilesUpload.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesUpload.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $handle = @fopen($source, 'rb');\n", '')
s = s.replace('static function () use ($handle, $path, $size): array {',
              "static function () use ($source, $path, $size): array {\n                $handle = @fopen($source, 'rb');")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesUpload.php "Quelle erst im Kind geoeffnet" &&
pruefe "Quelle erst im Kind geoeffnet" \
  FileStagingTest::test_the_source_is_opened_before_the_child_starts failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FileStagingTest passed

echo
echo "── FileStagingTest: beide Zwischenlager auf dasselbe Verzeichnis ──"
#
# Zwei Positivlisten, die auf dasselbe Verzeichnis zeigen, sind eine
# Positivliste: db.dump.import duerfte dann jede Kundendatei einspielen und
# files.upload jede Datenbanksicherung verteilen.
vorher_datei agent/src/Files/Staging.php
python3 - <<'PY2'
p = 'agent/src/Files/Staging.php'
s = open(p, encoding='utf-8').read()
s = s.replace('private/uploads', 'private/imports')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Files/Staging.php "Uploads und Sicherungen im selben Lager" &&
pruefe "Uploads und Sicherungen im selben Lager" \
  FileStagingTest::test_the_two_stores_stay_apart failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FileStagingTest passed

echo
echo "── FrontendDependencyTest: eine unbegruendete Abhaengigkeit ──"
#
# Dieses Projekt kam bis zum 14. August 2026 ohne jede Frontend-Abhaengigkeit
# aus, und das war nirgends geprueft — eine Selbstverstaendlichkeit. Sobald die
# erste Ausnahme da ist, entscheidet die Gewohnheit ueber die zweite.
vorher_datei package.json
python3 - <<'PY2'
import json
d = json.load(open('package.json', encoding='utf-8'))
d['devDependencies']['chart.js'] = '^4.0.0'
json.dump(d, open('package.json', 'w', encoding='utf-8'), indent=4, ensure_ascii=False)
PY2
griff_datei package.json "chart.js ohne Begruendung in package.json" &&
pruefe "chart.js ohne Begruendung in package.json" \
  FrontendDependencyTest::test_every_dependency_is_accounted_for failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FrontendDependencyTest passed

echo
echo "── FrontendDependencyTest: statischer Import von CodeMirror ──"
#
# Der ganze Unterschied zwischen 2,6 KB und 624 KB im gemeinsamen Buendel — und
# die Datei saehe dabei genauso aus, nur die Zeile stuende woanders.
vorher_datei resources/js/Components/CodeEditor.vue
python3 - <<'PY2'
p = 'resources/js/Components/CodeEditor.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("import { onBeforeUnmount", "import { EditorView } from '@codemirror/view'\nimport { onBeforeUnmount")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Components/CodeEditor.vue "CodeMirror statisch importiert" &&
pruefe "CodeMirror statisch importiert" \
  FrontendDependencyTest::test_codemirror_is_loaded_lazily failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FrontendDependencyTest passed

echo
echo "── FrontendDependencyTest: eine Farbe aus der Bibliothek ──"
#
# Ein Hexwert in einer Komponente ist in diesem Projekt ein Fehler und keine
# Ausnahme — und CodeMirrors eigene Themes waeren in einem der beiden Themes
# vermutlich unlesbar.
vorher_datei resources/js/Components/CodeEditor.vue
python3 - <<'PY2'
p = 'resources/js/Components/CodeEditor.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("{ tag: tags.keyword, class: 'tok-keyword' },", "{ tag: tags.keyword, color: '#c678dd' },")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Components/CodeEditor.vue "Hexwert im Editor" &&
pruefe "Hexwert im Editor" \
  FrontendDependencyTest::test_the_editor_brings_no_colours_of_its_own failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FrontendDependencyTest passed

echo
echo "── FrontendDependencyTest: der Rueckweg ohne die Bibliothek ──"
#
# Laedt das Buendel nicht, haengt sonst das Speichern einer .htaccess an einer
# Bibliothek.
vorher_datei resources/js/Components/CodeEditor.vue
python3 - <<'PY2'
import re
p = 'resources/js/Components/CodeEditor.vue'
s = open(p, encoding='utf-8').read()
s = re.sub(r'<textarea.*?</textarea>', '', s, flags=re.S)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Components/CodeEditor.vue "textarea-Rueckweg entfernt" &&
pruefe "textarea-Rueckweg entfernt" \
  FrontendDependencyTest::test_there_is_a_way_without_the_library failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FrontendDependencyTest passed

echo
echo "── ShortWriteTest: die Meldung hängt wieder an einer Bedingung ──"
#
# Genau die Lage vom 18. August: Zwei Meldungen, verzweigt nach `$written ===
# false` — und weil `file_put_contents` bei voller Quota `false` liefert, lief
# immer der Zweig, der das Kontingent nicht nennt. Der Satz stand im Quelltext
# und war unerreichbar.
vorher_datei agent/src/Ops/FilesWrite.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesWrite.php'
s = open(p, encoding='utf-8').read()
alt = "                    'Die Datei wurde nicht vollständig geschrieben — vermutlich ist das Kontingent erschöpft.',"
neu = """                    $written === false
                        ? 'Die Datei liess sich nicht schreiben.'
                        : 'Die Datei wurde nicht vollständig geschrieben — vermutlich ist das Kontingent erschöpft.',"""
s = s.replace(alt, neu, 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesWrite.php "zwei Meldungen für denselben Fall" &&
pruefe "zwei Meldungen für denselben Fall" \
  ShortWriteTest::test_there_is_one_message_and_not_two failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ShortWriteTest passed

echo
echo "── ShortWriteTest: die unbekannte Zahl wird als 0 gemeldet ──"
#
# `false` heisst „PHP kennt die Zahl und gibt sie nicht heraus". Eine 0 im
# Protokoll behauptet „nichts geschrieben" — eine Auskunft, die niemand hat.
vorher_datei agent/src/Ops/FilesWrite.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesWrite.php'
s = open(p, encoding='utf-8').read()
s = s.replace('$written === false ? null : $written', '$written === false ? 0 : $written', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesWrite.php "unbekannte Zahl als 0 gemeldet" &&
pruefe "unbekannte Zahl als 0 gemeldet" \
  ShortWriteTest::test_an_unknown_count_is_null failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ShortWriteTest passed

echo
echo "── TenancySweepTest: eine Route fehlt im Mandantenlauf ──"
#
# Eine Route, die der Lauf nicht kennt, wird nicht gemessen — und er meldet
# trotzdem „alle gehalten". Punkt 11 des Abnahmekriteriums zählt Routen.
vorher_datei tests/mandant-messen.js
python3 - <<'PY2'
p = 'tests/mandant-messen.js'
s = open(p, encoding='utf-8').read()
s = s.replace("    ['POST', '/files/chmod'],\n", '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/mandant-messen.js "Route fehlt im Mandantenlauf" &&
pruefe "Route fehlt im Mandantenlauf" \
  TenancySweepTest::test_the_sweep_covers_every_subscription_route failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TenancySweepTest passed

echo
echo "── TenancySweepTest: eine Route, die es nicht gibt ──"
#
# Ein Aufruf ins Leere antwortet mit 404 und sieht aus wie eine gehaltene
# Grenze.
vorher_datei tests/mandant-messen.js
python3 - <<'PY2'
p = 'tests/mandant-messen.js'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "    ['GET', '/files'],\n",
    "    ['GET', '/files'],\n    ['GET', '/files/gibtsnicht'],\n",
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/mandant-messen.js "Route im Lauf, die es nicht gibt" &&
pruefe "Route im Lauf, die es nicht gibt" \
  TenancySweepTest::test_the_sweep_covers_every_subscription_route failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TenancySweepTest passed

echo
echo "── TenancySweepTest: der Ausdruck über routes/web.php trifft nichts ──"
#
# Der Wächter gegen sich selbst: Läuft sein Ausdruck ins Leere, verglichen sich
# zwei leere Listen zu „gleich" — grün, ohne etwas gesehen zu haben.
vorher_datei tests/Unit/TenancySweepTest.php
python3 - <<'PY2'
p = 'tests/Unit/TenancySweepTest.php'
s = open(p, encoding='utf-8').read()
s = s.replace('/subscriptions/\\{subscription\\}', '/abos/\\{subscription\\}', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Unit/TenancySweepTest.php "Ausdruck trifft keine Route mehr" &&
pruefe "Ausdruck trifft keine Route mehr" \
  TenancySweepTest::test_the_sweep_covers_every_subscription_route failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TenancySweepTest passed

echo
echo "── BaseMethodClashTest: der Ausdruck verliert seinen Anker ──"
#
# **Die Regel selbst lässt sich nicht brechen, und das ist ihr Wesen.** Eine
# Methode, die einen final-Namen der Basisklasse trägt, tötet den ganzen Lauf
# beim Laden — der Prüfling käme gar nicht erst dazu, rot zu werden. Gebrochen
# werden deshalb die Teile des Wächters, und jeder einzeln.
#
# Ohne den Anker am Zeilenanfang trifft der Ausdruck auch eine Zeichenkette:
# SandboxCredentialsTest führt „public function run(Context …" als Behauptung
# über fremden Quelltext. Ein Wächter, der beim ersten Lauf einen Fehler
# erfindet, wird abgeschaltet und nicht befolgt.
vorher_datei tests/Unit/BaseMethodClashTest.php
python3 - <<'PY2'
p = 'tests/Unit/BaseMethodClashTest.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'/^\\s*(?:(?:final|", "'/(?:(?:final|", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Unit/BaseMethodClashTest.php "Ausdruck ohne Anker" &&
pruefe "Ausdruck ohne Anker" \
  BaseMethodClashTest::test_no_test_declares_a_name_the_base_class_owns failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BaseMethodClashTest passed

echo
echo "── BaseMethodClashTest: die Aufzählung der Dateien läuft ins Leere ──"
#
# Ohne Untergrenze verglichen sich zwei leere Listen zu „keine Kollision" — grün,
# ohne eine einzige Zeile gelesen zu haben.
vorher_datei tests/Unit/BaseMethodClashTest.php
python3 - <<'PY2'
p = 'tests/Unit/BaseMethodClashTest.php'
s = open(p, encoding='utf-8').read()
s = s.replace("foreach (['Unit', 'Feature', 'Support'] as $ordner)", "foreach (['Einheit'] as $ordner)", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Unit/BaseMethodClashTest.php "Aufzählung ins Leere" &&
pruefe "Aufzählung ins Leere" \
  BaseMethodClashTest::test_no_test_declares_a_name_the_base_class_owns failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BaseMethodClashTest passed

echo
echo "── BaseMethodClashTest: die Basisklasse hat angeblich nichts final ──"
#
# Findet die Spiegelung nichts, prüft der Fall darunter jede Datei gegen eine
# leere Liste.
vorher_datei tests/Unit/BaseMethodClashTest.php
python3 - <<'PY2'
p = 'tests/Unit/BaseMethodClashTest.php'
s = open(p, encoding='utf-8').read()
s = s.replace('if ($methode->isFinal() && ! $methode->isPrivate()) {', 'if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Unit/BaseMethodClashTest.php "Spiegelung ohne final-Methoden" &&
pruefe "Spiegelung ohne final-Methoden" \
  BaseMethodClashTest::test_the_base_class_really_has_final_methods failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BaseMethodClashTest passed

echo
echo "── BaseMethodClashTest: der Geltungsbereich nimmt wieder alles ──"
#
# **Genau der Fehler, mit dem dieser Wächter in die CI gegangen ist.** Der erste
# Wurf sammelte alles unter tests/Unit, tests/Feature und tests/Support ein und
# meldete daraufhin drei Attrappen, die einen eigenen Konstruktor haben —
# ScriptedDnsCredentials, ScriptedExchange, ScriptedLookup. TestCase::__construct()
# ist tatsächlich final, aber diese drei erben gar nicht davon.
vorher_datei tests/Unit/BaseMethodClashTest.php
python3 - <<'PY2'
p = 'tests/Unit/BaseMethodClashTest.php'
s = open(p, encoding='utf-8').read()
s = s.replace('if ($istTestfall || $istTrait) {', 'if (true) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Unit/BaseMethodClashTest.php "Geltungsbereich nimmt alles" &&
pruefe "Geltungsbereich nimmt alles" \
  BaseMethodClashTest::test_no_test_declares_a_name_the_base_class_owns failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BaseMethodClashTest passed

echo
echo "── CronIngestTest: das Einpflegen läuft wieder unter der Mandantenklammer ──"
#
# Der Befund vom 19. August: srvpanel-cron.service hat kein angemeldetes Konto,
# die Klammer verweigert dann alles, und CronJob::query() fand keinen einzigen
# Job. Der Einsammler meldete „88 eingesammelt, 0 eingepflegt" — und die 88
# waren fort, weil cron.runs löscht, was es herausgegeben hat.
vorher_datei app/Support/Cron/Cron.php
python3 - <<'PY2'
p = 'app/Support/Cron/Cron.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    'return $this->tenancy->withoutRestriction(fn (): int => $this->storeUnrestricted($runs, $byUser));',
    'return $this->storeUnrestricted($runs, $byUser);',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Cron/Cron.php "Einpflegen unter der Klammer" &&
pruefe "Einpflegen unter der Klammer" \
  CronIngestTest::test_a_run_arrives_without_an_authenticated_account failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CronIngestTest passed

echo
echo "── CronIngestTest: eine fremde Jobnummer wird geglaubt ──"
#
# Die Ablage gehört dem Abonnement — was darin steht, ist eine Behauptung des
# Kunden. Ohne diese Prüfung hängt ein selbst geschriebener Lauf an einem
# fremden Job.
vorher_datei app/Support/Cron/Cron.php
python3 - <<'PY2'
p = 'app/Support/Cron/Cron.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '|| (int) $job->subscription_id !== (int) $subscription->id) {',
    ') {',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Cron/Cron.php "fremde Jobnummer geglaubt" &&
pruefe "fremde Jobnummer geglaubt" \
  CronIngestTest::test_a_run_that_names_a_foreign_job_is_dropped failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CronIngestTest passed

echo
echo "── TimerRearmTest: der Cron-Timer verliert seinen Kalender ──"
#
# Gemessen auf cloudsrv24: „active", NEXT auf „-", letzter Lauf 22 Stunden her.
# Ein rein monotoner Timer kann seinen nächsten Termin verlieren und meldet
# dabei keinen Fehler.
vorher_datei packaging/systemd/srvpanel-cron.timer
python3 - <<'PY2'
p = 'packaging/systemd/srvpanel-cron.timer'
s = open(p, encoding='utf-8').read()
s = s.replace('OnCalendar=*:0/5', 'OnUnitActiveSec=5min', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei packaging/systemd/srvpanel-cron.timer "Timer ohne Kalender" &&
pruefe "Timer ohne Kalender" \
  TimerRearmTest::test_a_repeating_timer_is_bound_to_the_clock failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TimerRearmTest passed

echo
echo "── TimerRearmTest: Persistent ohne Kalender ──"
#
# Die Einstellung wirkt nur auf Kalender-Timer. Rein monoton steht sie da und
# tut nichts — eine Notiz, die sich wie eine Zusage liest.
vorher_datei packaging/systemd/srvpanel-usage.timer
python3 - <<'PY2'
p = 'packaging/systemd/srvpanel-usage.timer'
s = open(p, encoding='utf-8').read()
s = s.replace('OnCalendar=*:0/15', 'OnUnitActiveSec=15min', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei packaging/systemd/srvpanel-usage.timer "Persistent ohne Kalender" &&
pruefe "Persistent ohne Kalender" \
  TimerRearmTest::test_persistent_is_only_claimed_where_it_works failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TimerRearmTest passed

echo
echo "── LiteralTest: eine Einsetzung in einem Literal ──"
#
# Befund 5 aus docs/64: `.literal` haelt den Inhalt vom Umbruch ab. Was aus
# einer Eingabe kommt, kann beliebig lang werden und schiebt dann die Seite.
vorher_datei resources/js/Pages/Subscriptions/Cron.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Cron.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('<span class="ident">{{ ausdruck }}</span>', '<span class="ident literal">{{ ausdruck }}</span>', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Cron.vue "Einsetzung im Literal" &&
pruefe "Einsetzung im Literal" \
  LiteralTest::test_a_literal_is_short_and_written_out failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" LiteralTest passed

echo
echo "── LiteralTest: ein zu langes Literal ──"
vorher_datei resources/js/Pages/Subscriptions/Cron.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Cron.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('<span class="ident literal">9-17</span>', '<span class="ident literal">/usr/local/bin:/usr/bin:/bin</span>', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Cron.vue "zu langes Literal" &&
pruefe "zu langes Literal" \
  LiteralTest::test_a_literal_is_short_and_written_out failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" LiteralTest passed

echo
echo "── LiteralTest: das Ankreuzfeld trägt wieder die Regel eines Textfeldes ──"
#
# Befund 1 aus docs/64: Bei 390px wurde daraus ein Kasten von 390x44 px, und der
# Dokumentueberlauf blieb dabei 0.
vorher_datei resources/js/Pages/Files/Search.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Search.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '<label class="toggle">\n        <input v-model="imInhalt" type="checkbox" />\n        <span>auch im Inhalt</span>\n      </label>',
    '<label class="field inline">\n        <span>auch im Inhalt</span>\n        <input v-model="imInhalt" type="checkbox" />\n      </label>',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Search.vue "Ankreuzfeld als Textfeld" &&
pruefe "Ankreuzfeld als Textfeld" \
  LiteralTest::test_a_checkbox_is_not_dressed_as_a_field failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" LiteralTest passed

echo
echo "── BlockSpacingTest: der Erklärsatz verliert seinen Rand nach unten ──"
#
# Befund 3 aus docs/64: Der Knopf „Eintragen" klebte am Satz darueber.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('  margin: 10px 0 var(--block-gap);', '  margin: 10px 0 0;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Erklärsatz ohne Rand" &&
pruefe "Erklärsatz ohne Rand" \
  BlockSpacingTest::test_every_seam_between_two_flush_blocks_is_covered failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BlockSpacingTest passed

echo
echo "── BlockSpacingTest: die drei Fugen um den leisen Satz fallen weg ──"
#
# Befunde 2 und 4 aus docs/64: „Gesucht unter …" klebte an der Knopfreihe
# darueber und die gelbe Meldung an ihm.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('.button-row + .quiet,\n.quiet + .notice,\n.quiet + .scrolls {', '.nichts-davon {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Fugen um den leisen Satz" &&
pruefe "Fugen um den leisen Satz" \
  BlockSpacingTest::test_every_seam_between_two_flush_blocks_is_covered failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BlockSpacingTest passed

echo
echo "── BlockSpacingTest: der Absatz ohne Klasse verliert seinen Rand ──"
#
# Befund 4 auf der Cronseite. Diesen Baustein sieht der Wächter darunter nicht —
# er kennt Bausteine an ihrer Klasse, und ein Absatz ohne Klasse hat keine.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('p:not([class]) {\n  margin-bottom: var(--block-gap);\n}', 'p:not([class]) {\n  margin-bottom: 0;\n}', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Absatz ohne Rand" &&
pruefe "Absatz ohne Rand" \
  BlockSpacingTest::test_a_paragraph_without_a_class_leaves_air_below failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BlockSpacingTest passed

echo
echo "── RevealTest: ein Griff an einer Zeile holt seinen Bereich nicht ins Bild ──"
#
# Befund 10 aus docs/64: „Rechte" an einer Zeile weit unten oeffnet einen
# Bereich am Kopf der Seite. Bei 390px sieht man nichts geschehen.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    'watch(chmodFor, (offen) => {\n  if (offen !== null) {\n    void nextTick(() => bringIntoView(chmodBlock.value))\n  }\n})\n\n',
    '',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "Griff ohne Sprung" &&
pruefe "Griff ohne Sprung" \
  RevealTest::test_every_per_item_handle_reveals_its_block failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RevealTest passed

echo
echo "── RevealTest: der Bereich kann den Fokus nicht annehmen ──"
#
# `bringIntoView()` setzt am Ende den Fokus. Ohne `tabindex="-1"` tut das an
# einer `section` nichts — die Seite springt, der Tastaturweg bleibt zu.
vorher_datei resources/js/Pages/Subscriptions/Cron.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Cron.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('<section ref="formBlock" class="section" tabindex="-1">', '<section ref="formBlock" class="section">', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Cron.vue "Bereich ohne Fokus" &&
pruefe "Bereich ohne Fokus" \
  RevealTest::test_a_revealed_block_can_take_the_focus failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RevealTest passed

echo
echo "── RevealTest: ein veralteter Eintrag in der Liste der Ungeprüften ──"
vorher_datei tests/Unit/RevealTest.php
python3 - <<'PY2'
p = 'tests/Unit/RevealTest.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        'Pages/Databases/Console.vue toggle expanded',",
    "        'Pages/Databases/Console.vue toggle expanded',\n        'Pages/Files/Index.vue gibtEsNicht wasAuchImmer',",
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Unit/RevealTest.php "veralteter Eintrag bei den Ungeprüften" &&
pruefe "veralteter Eintrag bei den Ungeprüften" \
  RevealTest::test_every_per_item_handle_reveals_its_block failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" RevealTest passed

echo
echo "── TableStyleTest: die Zelle verliert ihre senkrechte Polsterung ──"
#
# Befunde 7 und 8 aus docs/64: Ohne sie kommt der senkrechte Rhythmus allein aus
# `height`, und der wirkt nur, solange der Inhalt hineinpasst.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('  padding: 6px 14px;\n  padding-left: 0;', '  padding: 0 14px 0 0;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Zelle ohne senkrechte Polsterung" &&
pruefe "Zelle ohne senkrechte Polsterung" \
  TableStyleTest::test_a_cell_has_vertical_padding_below_the_measured_ceiling failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TableStyleTest passed

echo
echo "── TableStyleTest: die Polsterung überschreitet die gemessene Grenze ──"
#
# Ab 7px waechst eine einzeilige Zeile in der Dichtestufe `admin` ueber ihre
# 40px hinaus — dann bestimmt die Polsterung die Zeilenhoehe statt der Stufe.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('  padding: 6px 14px;', '  padding: 8px 14px;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Polsterung über der Grenze" &&
pruefe "Polsterung über der Grenze" \
  TableStyleTest::test_a_cell_has_vertical_padding_below_the_measured_ceiling failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TableStyleTest passed

echo
echo "── StandaloneClassTest: leiser Text gilt wieder nur in einer Tabelle ──"
#
# Befund 11 aus docs/64: `.quiet` stand als `td.quiet` und `td .quiet` da, und
# fuenf Stellen ausserhalb einer Zelle baten vergeblich darum.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('\n.quiet {\n  color: var(--text-muted);\n}', '\ntd.quiet {\n  color: var(--text-muted);\n}', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "leiser Text nur in der Zelle" &&
pruefe "leiser Text nur in der Zelle" \
  StandaloneClassTest::test_quiet_is_not_bound_to_a_table failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" StandaloneClassTest passed

echo
echo "── StandaloneClassTest: eine gebundene Klasse ohne Begründung ──"
#
# `.right` gibt es nur als `td.right`. Fehlt der Eintrag, ist es ein Fund.
vorher_datei tests/Unit/StandaloneClassTest.php
python3 - <<'PY2'
p = 'tests/Unit/StandaloneClassTest.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        'right' => 'eine rechtsbündige Zelle',\n", '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Unit/StandaloneClassTest.php "gebundene Klasse ohne Begründung" &&
pruefe "gebundene Klasse ohne Begründung" \
  StandaloneClassTest::test_every_used_class_has_a_standalone_rule failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" StandaloneClassTest passed

echo
echo "── StandaloneClassTest: ein veralteter Eintrag in der Liste ──"
#
# Die Sperrklinke: Ein Eintrag, der eine freistehende Regel hat, sieht aus wie
# eine bekannte Einschränkung und ist keine mehr.
vorher_datei tests/Unit/StandaloneClassTest.php
python3 - <<'PY2'
p = 'tests/Unit/StandaloneClassTest.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        'right' => 'eine rechtsbündige Zelle',",
    "        'right' => 'eine rechtsbündige Zelle',\n        'notice' => 'nur zum Ausprobieren',",
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Unit/StandaloneClassTest.php "veralteter Eintrag" &&
pruefe "veralteter Eintrag" \
  StandaloneClassTest::test_every_used_class_has_a_standalone_rule failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" StandaloneClassTest passed

echo
echo "── CronScheduleFormTest: der Ausdruck wird ein eigener Wert ──"
#
# Wunsch 1 aus docs/64: Die Expertenansicht gibt es nur, weil sie zurückschreibt.
# Ein eigenes Feld daneben wäre eine zweite Fassung desselben Zeitplans.
vorher_datei resources/js/Pages/Subscriptions/Cron.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Cron.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("  active: true,\n})", "  active: true,\n  expression: '',\n})", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Cron.vue "Ausdruck als eigener Wert" &&
pruefe "Ausdruck als eigener Wert" \
  CronScheduleFormTest::test_the_free_expression_is_a_view_on_the_five_fields failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CronScheduleFormTest passed

echo
echo "── CronScheduleFormTest: der Setzer lässt ein Feld aus ──"
#
# Ein Feld, das beim Umschalten seinen alten Wert behält, macht aus dem
# Ausdruck im Feld eine Behauptung über etwas anderes als das Gespeicherte.
vorher_datei resources/js/Pages/Subscriptions/Cron.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Cron.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("    form.month = teile[3] ?? ''\n", '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Cron.vue "Setzer ohne Monat" &&
pruefe "Setzer ohne Monat" \
  CronScheduleFormTest::test_the_free_expression_is_a_view_on_the_five_fields failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CronScheduleFormTest passed

echo
echo "── CronScheduleFormTest: der Ausdruck ist keine berechnete Sicht mehr ──"
#
# Ohne `computed` mit Setzer ist er ein gespeicherter Wert, und die fünf Felder
# erfahren von einer Eingabe nichts.
vorher_datei resources/js/Pages/Subscriptions/Cron.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Cron.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('const freierAusdruck = computed({', 'const freierAusdruck = ref({', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Cron.vue "Ausdruck ohne Sicht" &&
pruefe "Ausdruck ohne Sicht" \
  CronScheduleFormTest::test_the_free_expression_is_a_view_on_the_five_fields failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CronScheduleFormTest passed

echo
echo "── OverflowProbeTest: der Prüfkörper bekommt wieder eine feste Breite ──"
#
# Befund 22 aus docs/59: Ein Block von 900px schlägt bei 390px aus und bei
# 1440px nicht — dort steht dann dieselbe Null, die auch eine kaputte Messung
# liefert.
vorher_datei tests/bilder-messen.js
python3 - <<'PY2'
p = 'tests/bilder-messen.js'
s = open(p, encoding='utf-8').read()
s = s.replace('width:${wurzel.scrollWidth + 200}px', 'width:900px', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/bilder-messen.js "Prüfkörper mit fester Breite" &&
pruefe "Prüfkörper mit fester Breite" \
  OverflowProbeTest::test_the_probe_has_no_fixed_width failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OverflowProbeTest passed

echo
echo "── OverflowProbeTest: die Gegenprobe fällt aus dem Ergebnis ──"
#
# Eine Messung, die auch ohne Gegenprobe ein Ergebnis liefert, wird irgendwann
# ohne sie gefahren — und „dokument: 0" ohne sie ist keine Aussage.
vorher_datei tests/bilder-messen.js
python3 - <<'PY2'
p = 'tests/bilder-messen.js'
s = open(p, encoding='utf-8').read()
s = s.replace('    gegenprobe: gegenprobe(),\n', '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/bilder-messen.js "Messung ohne Gegenprobe" &&
pruefe "Messung ohne Gegenprobe" \
  OverflowProbeTest::test_the_counter_check_is_part_of_the_result failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OverflowProbeTest passed

echo
echo "── OverflowProbeTest: der erwartete Ausschlag steht nicht daneben ──"
#
# Ohne die erwartete Zahl ist jedes Ergebnis plausibel — auch eine 0.
vorher_datei tests/bilder-messen.js
python3 - <<'PY2'
p = 'tests/bilder-messen.js'
s = open(p, encoding='utf-8').read()
s = s.replace('return { ausschlag: nachher - vorher, erwartet: 200 }', 'return { ausschlag: nachher - vorher }', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/bilder-messen.js "Gegenprobe ohne Erwartung" &&
pruefe "Gegenprobe ohne Erwartung" \
  OverflowProbeTest::test_the_counter_check_is_part_of_the_result failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OverflowProbeTest passed

echo
echo "── OverflowProbeTest: gemessen wird nur noch eine Liste von Selektoren ──"
#
# Eine Liste nennt, woran man beim Schreiben gerade dachte. Der Fund von P5c
# Schritt 5 steckte in einer Textzelle, der von Schritt 4 in einem Bereichstitel.
vorher_datei tests/bilder-messen.js
python3 - <<'PY2'
p = 'tests/bilder-messen.js'
s = open(p, encoding='utf-8').read()
s = s.replace("document.querySelectorAll('*')", "document.querySelectorAll('.scrolls')", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/bilder-messen.js "Messung nur an genannten Stellen" &&
pruefe "Messung nur an genannten Stellen" \
  OverflowProbeTest::test_every_element_is_measured failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OverflowProbeTest passed

echo
echo "── OverflowProbeTest: ein Fund nennt nur noch seine Bauart ──"
#
# „div" stand in der Bilderrunde viermal in vier Ansichten und zeigte nirgendwo
# hin. Eine Zahl, die nicht sagt, welche, zwingt zum Suchen.
vorher_datei tests/bilder-messen.js
python3 - <<'PY2'
p = 'tests/bilder-messen.js'
s = open(p, encoding='utf-8').read()
s = s.replace('      pfad: pfad(element),\n', '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/bilder-messen.js "Fund ohne Ort" &&
pruefe "Fund ohne Ort" \
  OverflowProbeTest::test_a_finding_names_where_it_is failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OverflowProbeTest passed

echo
echo "── OverflowProbeTest: ein Fund zeigt sein Markup nicht mehr ──"
#
# Der Weg allein zeigt zwar auf ein Element, sagt aber nicht, was drinsteht.
vorher_datei tests/bilder-messen.js
python3 - <<'PY2'
p = 'tests/bilder-messen.js'
s = open(p, encoding='utf-8').read()
s = s.replace('      anfang: element.outerHTML.slice(0, 120),\n', '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/bilder-messen.js "Fund ohne Markup" &&
pruefe "Fund ohne Markup" \
  OverflowProbeTest::test_a_finding_names_where_it_is failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OverflowProbeTest passed

echo
echo "── OverflowProbeTest: die Messung nennt ihren Stand nicht mehr ──"
#
# Das Skript lebt in der Konsole und kommt nach jedem Neuladen aus der
# Zwischenablage zurück. Ohne Stand sieht eine alte Messung aus wie eine neue.
vorher_datei tests/bilder-messen.js
python3 - <<'PY2'
p = 'tests/bilder-messen.js'
s = open(p, encoding='utf-8').read()
s = s.replace('    stand: STAND,\n', '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/bilder-messen.js "Messung ohne Stand" &&
pruefe "Messung ohne Stand" \
  OverflowProbeTest::test_a_reading_names_the_instrument failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OverflowProbeTest passed

echo
echo "── OverflowProbeTest: der Stand ist kein Datum mehr ──"
#
# „neu" altert nicht. Ein Stand, der kein Datum ist, sagt nichts über das Alter.
vorher_datei tests/bilder-messen.js
python3 - <<'PY2'
p = 'tests/bilder-messen.js'
s = open(p, encoding='utf-8').read()
s = s.replace("const STAND = '2026-08-19'", "const STAND = 'neu'", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/bilder-messen.js "Stand ohne Datum" &&
pruefe "Stand ohne Datum" \
  OverflowProbeTest::test_a_reading_names_the_instrument failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" OverflowProbeTest passed

echo
echo "── TransportLimitTest: files.write erklärt wieder mehr als die Leitung trägt ──"
#
# Befund 12b: 2 MiB erklärt, 1 MiB Anfragegrenze. Eine Datei dazwischen öffnete
# sich im Editor und liess sich nie speichern.
vorher_datei agent/src/Ops/FilesWrite.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesWrite.php'
s = open(p, encoding='utf-8').read()
s = s.replace('public const MAX_BYTES = Connection::CONTENT_MAX;', 'public const MAX_BYTES = 2 * 1024 * 1024;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesWrite.php "Schreibgrenze über der Leitung" &&
pruefe "Schreibgrenze über der Leitung" \
  TransportLimitTest::test_no_declared_limit_exceeds_the_transport failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TransportLimitTest passed

echo
echo "── TransportLimitTest: der Editor öffnet mehr, als sich speichern lässt ──"
#
# Eine Falle mit Speicherknopf: Die Datei erscheint, die Änderung entsteht, und
# erst das Speichern sagt nein — nach der Arbeit statt davor.
vorher_datei agent/src/Ops/FilesRead.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesRead.php'
s = open(p, encoding='utf-8').read()
s = s.replace('public const MAX_BYTES = Connection::CONTENT_MAX;', 'public const MAX_BYTES = 2 * 1024 * 1024;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesRead.php "Lesegrenze über der Schreibgrenze" &&
pruefe "Lesegrenze über der Schreibgrenze" \
  TransportLimitTest::test_what_opens_can_be_written_back failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TransportLimitTest passed

echo
echo "── TransportLimitTest: für die Hülle der Anfrage bleibt nichts ──"
#
# Der Abzug in CONTENT_MAX ist eine hingeschriebene Zahl. Der Wächter baut die
# Zeile, die daraus entsteht, und misst nach — sonst glaubt er sie nur.
vorher_datei agent/src/Connection.php
python3 - <<'PY2'
p = 'agent/src/Connection.php'
s = open(p, encoding='utf-8').read()
s = s.replace('public const CONTENT_MAX = self::REQUEST_MAX - 65536;', 'public const CONTENT_MAX = self::REQUEST_MAX - 1;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Connection.php "Hülle ohne Platz" &&
pruefe "Hülle ohne Platz" \
  TransportLimitTest::test_a_full_payload_still_fits_into_one_request failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TransportLimitTest passed

echo
echo "── TransportLimitTest: der Klient misst die Zeile nicht, bevor er sie schickt ──"
#
# Ohne diese Prüfung meldet der Agent den Fehlschlag — und spricht dabei vom
# Protokoll statt von der Datei des Kunden.
vorher_datei agent/src/Client.php
python3 - <<'PY2'
p = 'agent/src/Client.php'
s = open(p, encoding='utf-8').read()
s = s.replace('if (strlen($json) > Connection::REQUEST_MAX) {', 'if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Client.php "Klient misst die Zeile nicht" &&
pruefe "Klient misst die Zeile nicht" \
  TransportLimitTest::test_the_client_measures_the_encoded_line failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TransportLimitTest passed

echo
echo "── TenancySweepTest: der Vorflug prüft die eigene Kennung nicht ──"
#
# Der Fehler vom 19. August: Der Lauf bekam `eigenJob: 4`, eine Kennung vom
# *fremden* Abonnement. Drei Zeilen meldeten „BLIEB HÄNGEN" — und das liest sich
# wie ein Befund am Panel statt wie einer am Messmittel.
vorher_datei tests/mandant-messen.js
python3 - <<'PY2'
p = 'tests/mandant-messen.js'
s = open(p, encoding='utf-8').read()
s = s.replace("  await vorflug('/cron', 'jobs', eigenJob, 'eigenJob')\n", '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/mandant-messen.js "Vorflug ohne die eigene Kennung" &&
pruefe "Vorflug ohne die eigene Kennung" \
  TenancySweepTest::test_the_sweep_checks_its_own_identifiers_first failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TenancySweepTest passed

echo
echo "── TenancySweepTest: der Vorflug fasst die fremde Seite an ──"
#
# Ein Vorflug über das fremde Abonnement umgeht genau die Wand, die dieser Lauf
# messen soll — und käme er durch, wäre er selbst der Übergriff.
vorher_datei tests/mandant-messen.js
python3 - <<'PY2'
p = 'tests/mandant-messen.js'
s = open(p, encoding='utf-8').read()
alt = "  await vorflug('/cron', 'jobs', eigenJob, 'eigenJob')\n"
s = s.replace(alt, alt + "  await vorflug('/cron', 'jobs', fremdJob, 'fremdJob')\n", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/mandant-messen.js "Vorflug über die fremde Seite" &&
pruefe "Vorflug über die fremde Seite" \
  TenancySweepTest::test_the_sweep_checks_its_own_identifiers_first failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" TenancySweepTest passed

echo
echo "── ShortWriteTest: files.write prüft nur auf false ──"
#
# Bei voller Quota meldet der Aufruf die Zahl der geschriebenen Bytes und nicht
# false. Wer nur auf false prüft, meldet dem Kunden „gespeichert" für eine
# Datei, von der die Hälfte fehlt — Punkt 12 des Abnahmekriteriums.
vorher_datei agent/src/Ops/FilesWrite.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesWrite.php'
s = open(p, encoding='utf-8').read()
s = s.replace('if ($written !== strlen($content)) {', 'if ($written === false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesWrite.php "kurzer Schreibvorgang gilt als Erfolg" &&
pruefe "kurzer Schreibvorgang gilt als Erfolg" \
  ShortWriteTest::test_a_short_write_is_a_failure failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ShortWriteTest passed

echo
echo "── ShortWriteTest: die Begründung nennt das Kontingent nicht ──"
#
# „Die Datei liess sich nicht übernehmen" klingt nach einem Defekt des Servers.
# Der Kunde meldet einen Ausfall, wo er Platz schaffen müsste.
vorher_datei agent/src/Ops/FilesUpload.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesUpload.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    'Die Datei wurde nicht vollständig übernommen — vermutlich ist das Kontingent erschöpft.',
    'Die Datei liess sich nicht übernehmen.',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesUpload.php "Begründung ohne das Kontingent" &&
pruefe "Begründung ohne das Kontingent" \
  ShortWriteTest::test_the_reason_names_the_allowance failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ShortWriteTest passed

echo
echo "── ShortWriteTest: der halbe Rest bleibt liegen ──"
#
# Wird erst geworfen und dann aufgeräumt, läuft die zweite Zeile nie: Jeder
# Fehlversuch frisst dauerhaft am Kontingent, unter einem Namen mit Punkt davor,
# den keine Auflistung zeigt.
vorher_datei agent/src/Ops/FilesWrite.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesWrite.php'
s = open(p, encoding='utf-8').read()
alt = """                @unlink($temporary);

                throw AgentException::execFailed("""
s = s.replace(alt, """                throw AgentException::execFailed(""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesWrite.php "Rest wird nach dem Wurf weggeräumt" &&
pruefe "Rest wird nach dem Wurf weggeräumt" \
  ShortWriteTest::test_the_half_written_file_is_removed failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ShortWriteTest passed

echo
echo "── ArchiveDepthTest: ein Tar wird nur an der Oberfläche aufgezählt ──"
#
# Der Fehler, aus dem dieser Wächter entstand: `foreach (new PharData(…))` läuft
# über die oberste Ebene. Ein Tar mit `dir/sub/tief.txt` verlor alles darunter,
# ohne dass es jemand sagte — gefunden hat es der Bau von docs/62, kein Test.
vorher_datei agent/src/Files/Archive.php
python3 - <<'PY2'
p = 'agent/src/Files/Archive.php'
s = open(p, encoding='utf-8').read()
alt = """        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $file) {"""
s = s.replace(alt, """        foreach (new PharData($archive) as $file) {""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Files/Archive.php "Tar nur an der Oberfläche aufgezählt" &&
pruefe "Tar nur an der Oberfläche aufgezählt" \
  ArchiveDepthTest::test_a_nested_tar_arrives_completely failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ArchiveDepthTest passed

echo
echo "── ArchiveDepthTest: die unbenennbaren Einträge werden verschwiegen ──"
#
# `count($phar)` kennt einen Eintrag mit `..` am Anfang, der Iterator nicht.
# Ohne die Differenz meldet der Vorgang „0 übersprungen" für ein Archiv, dem
# etwas fehlt.
vorher_datei agent/src/Files/Archive.php
python3 - <<'PY2'
p = 'agent/src/Files/Archive.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "return ['names' => $names, 'unnamed' => max(0, count($phar) - count($names))];",
    "return ['names' => $names, 'unnamed' => 0];",
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Files/Archive.php "fehlende Einträge nicht gezählt" &&
pruefe "fehlende Einträge nicht gezählt" \
  ArchiveDepthTest::test_an_entry_that_cannot_be_named_is_counted failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ArchiveDepthTest passed

echo
echo "── ArchiveDepthTest: ein Verzeichnis im Tar wird als Datei behandelt ──"
#
# Solange die Aufzählung nur die oberste Ebene sah, gab es in einem Tar für
# diese Datei keine Verzeichnisse. Ein `fopen` darauf schlägt fehl, und der
# Eintrag landet unter „verlegt" statt angelegt zu werden.
vorher_datei agent/src/Files/Archive.php
python3 - <<'PY2'
p = 'agent/src/Files/Archive.php'
s = open(p, encoding='utf-8').read()
alt = """            $directory = str_ends_with((string) $name, '/');
            $stream = $directory ? null : @fopen($phar[(string) $name]->getPathname(), 'rb');

            if (self::place($stream ?: null, $target, $relative, $directory)) {"""
s = s.replace(alt, """            $stream = @fopen($phar[(string) $name]->getPathname(), 'rb');

            if (self::place($stream ?: null, $target, $relative, false)) {""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Files/Archive.php "Verzeichnis im Tar als Datei behandelt" &&
pruefe "Verzeichnis im Tar als Datei behandelt" \
  ArchiveDepthTest::test_a_nested_tar_arrives_completely failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ArchiveDepthTest passed

echo
echo "── ArchiveEntryTest: array_pop statt verwerfen ──"
#
# Der naheliegende Weg und der falsche: array_pop macht aus `a/../../b` ein `b`
# — also einen Eintrag, den das Archiv so nie benannt hat.
vorher_datei agent/src/Files/Archive.php
python3 - <<'PY2'
p = 'agent/src/Files/Archive.php'
s = open(p, encoding='utf-8').read()
alt = """            if ($part === '..') {"""
i = s.index(alt)
j = s.index('return null;', i) + len('return null;')
s = s[:i] + """            if ($part === '..') {
                array_pop($parts);

                continue;""" + s[j:]
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Files/Archive.php "Zip-Slip wird zurechtgebogen statt verworfen" &&
pruefe "Zip-Slip wird zurechtgebogen statt verworfen" \
  ArchiveEntryTest::test_a_way_out_is_not_bent_into_a_way_in failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ArchiveEntryTest passed

echo
echo "── ArchiveEntryTest: der Backslash bleibt ein Namenszeichen ──"
#
# Ein Eintrag `..\..\x` aus einem Archiv, das auf einem anderen System
# entstanden ist, waere sonst ein gueltiger Dateiname mit Punkten.
vorher_datei agent/src/Files/Archive.php
python3 - <<'PY2'
p = 'agent/src/Files/Archive.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $name = str_replace('\\\\', '/', $name);\n", '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Files/Archive.php "Backslash nicht als Trenner behandelt" &&
pruefe "Backslash nicht als Trenner behandelt" \
  ArchiveEntryTest::test_entries_that_lead_out_are_dropped failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ArchiveEntryTest passed

echo
echo "── ArchiveEntryTest: die Suche nimmt ein Muster entgegen ──"
#
# Ein `(a+)+b` gegen eine lange Zeile bringt den Vorgang zum Stillstand, und es
# gibt kein Zeitlimit, das den Prozess rechtzeitig einholt.
vorher_datei agent/src/Ops/FilesSearch.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesSearch.php'
s = open(p, encoding='utf-8').read()
s = s.replace('if (! str_contains($content, $needle)) {', 'if (preg_match("/".$needle."/", $content) !== 1) {')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesSearch.php "Suche mit regulaerem Ausdruck des Kunden" &&
pruefe "Suche mit regulaerem Ausdruck des Kunden" \
  ArchiveEntryTest::test_the_search_matches_literally failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ArchiveEntryTest passed

echo
echo "── ArchiveEntryTest: ein abgebrochener Suchlauf schweigt ──"
#
# Eine leere Ergebnisliste, die einen Abbruch verschweigt, behauptet etwas, das
# sie nicht weiss.
vorher_datei agent/src/Ops/FilesSearch.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesSearch.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'truncated' => $abgebrochen,", '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesSearch.php "Abbruch des Suchlaufs verschwiegen" &&
pruefe "Abbruch des Suchlaufs verschwiegen" \
  ArchiveEntryTest::test_a_truncated_search_says_so failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ArchiveEntryTest passed

echo
echo "── LinkReachTest: **beide** Wege zu einer Seite fallen weg ──"
#
# Der Anlass ist Befund 6 aus `docs/53`: Der Dateimanager war vollstaendig
# gebaut, und kein Template zeigte darauf. Erreichbar war er nur ueber die
# Adresszeile.
#
# **Dieser Eingriff hat am 15. August seinen Biss verloren, und schuld war der
# Fix von Befund 8 desselben Tages.** Er nahm dem Abonnement-Bildschirm den
# Verweis weg — und seit der Dateimanager ausserdem im Menue steht (`/files`),
# findet die Suche ihn weiter. Der Waechter war gruen, und zwar zu Recht: Die
# Seite *war* erreichbar.
#
# > **Ein Bruch, der einen von zwei Wegen entfernt, prueft nicht die
# > Erreichbarkeit — er prueft, dass es den zweiten Weg gibt.**
#
# Und die Lehre darueber: **Die Behebung eines Befundes kann den Waechter eines
# aelteren entwaffnen.** LinkReachTest ist in Schritt 5b genau fuer Befund 6
# gebaut worden; der Fix fuer Befund 8 hat seinen Bruch stumm gemacht, ohne eine
# Zeile an ihm zu aendern.
vorher_datei resources/js/Pages/Subscriptions/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('/files`"', '/dateien`"', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
python3 - <<'PY2'
p = 'resources/js/Layouts/PanelLayout.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("{ name: 'Dateien', href: '/files', icon: 'files' },",
              "{ name: 'Dateien', href: '/dateien', icon: 'files' },", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Show.vue "Weg zum Dateimanager entfernt" &&
pruefe "Weg zum Dateimanager entfernt" \
  LinkReachTest::test_every_page_is_reachable_from_a_template failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" LinkReachTest passed

echo
echo "── LinkReachTest: eine Route zieht um, der Link bleibt stehen ──"
#
# Der wiederkehrende Fehler dieses Projekts: eine Zeichenkette, die auf etwas
# verweist, das umgezogen ist. Hier faellt er auf, statt eine Seite still
# unerreichbar zu machen.
vorher_datei routes/web.php
python3 - <<'PY2'
p = 'routes/web.php'
s = open(p, encoding='utf-8').read()
s = s.replace("Route::get('/audit'", "Route::get('/protokoll'", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei routes/web.php "Route umgezogen" &&
pruefe "Route umgezogen, Link steht still" \
  LinkReachTest::test_every_page_is_reachable_from_a_template failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" LinkReachTest passed

echo
echo "── InheritedGroupTest: httpdocs ohne setgid ──"
#
# Befund 3 aus docs/53: Zwei Dateien nebeneinander im selben httpdocs, die eine
# 1004:33 (www-data) und die andere 1004:1004. Der Webserver kam an die zweite
# nur ueber das Weltbit — wer sie auf 0640 setzt, bekommt einen 403.
vorher_datei agent/src/Ops/SubscriptionProvision.php
sed -i 's/DOCUMENT_ROOT_MODE = 0o2750/DOCUMENT_ROOT_MODE = 0o750/' agent/src/Ops/SubscriptionProvision.php
griff_datei agent/src/Ops/SubscriptionProvision.php "httpdocs ohne setgid" &&
pruefe "httpdocs ohne setgid" \
  InheritedGroupTest::test_every_directory_of_the_customer_inherits_its_group failed
wiederherstellen

echo
echo "── InheritedGroupTest: ein Verzeichnis des Schemas ohne setgid ──"
#
# Der zweite Bruch trifft eines, bei dem das Bit heute nichts aendert. Genau
# deshalb steht es dort: Die Regel heisst „alle Verzeichnisse des Kunden" und
# nicht „die mit einer fremden Gruppe" — sonst muesste sie bei jedem Zuwachs
# des Schemas neu beurteilt werden.
vorher_datei agent/src/Ops/SubscriptionProvision.php
sed -i "s/'mail' => \['%u', '%g', 0o2700\]/'mail' => ['%u', '%g', 0o700]/" agent/src/Ops/SubscriptionProvision.php
griff_datei agent/src/Ops/SubscriptionProvision.php "mail ohne setgid" &&
pruefe "mail ohne setgid" \
  InheritedGroupTest::test_every_directory_of_the_customer_inherits_its_group failed
wiederherstellen

echo
echo "── InheritedGroupTest: die Angabe steht wieder zweimal da ──"
#
# Bis zum 14. August standen 'www-data' und 0750 in SubscriptionProvision::TREE
# und noch einmal als Literal in WebSiteApply. Das setgid-Bit waere an der
# zweiten Stelle nicht angekommen.
vorher_datei agent/src/Ops/WebSiteApply.php
python3 - <<'PY2'
p = 'agent/src/Ops/WebSiteApply.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                SubscriptionProvision::DOCUMENT_ROOT_GROUP,\n"
              "                SubscriptionProvision::DOCUMENT_ROOT_MODE,",
              "                'www-data',\n                0o750,")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/WebSiteApply.php "Angabe zweimal" &&
pruefe "Angabe des DocumentRoots zweimal" \
  InheritedGroupTest::test_the_document_root_is_described_in_one_place failed
wiederherstellen

echo
echo "── InheritedGroupTest: die Angabe gilt nur beim Anlegen ──"
#
# Der Fehler, der beinahe passiert waere: directories() setzte Rechte nur, wenn
# das Verzeichnis neu entstand. Das setgid-Bit haette damit kein einziges
# bestehendes Abonnement erreicht.
vorher_datei agent/src/Ops/WebSiteApply.php
python3 - <<'PY2'
p = 'agent/src/Ops/WebSiteApply.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""        $entstanden = ! is_dir($site->logDir());

        Filesystem::directory($site->logDir(), $site->user, 'adm', 0o2750);

        if ($entstanden) {
            $created[] = $site->logDir();
        }""",
              """        if (! is_dir($site->logDir())) {
            Filesystem::directory($site->logDir(), $site->user, 'adm', 0o2750);
            $created[] = $site->logDir();
        }""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/WebSiteApply.php "Angabe nur beim Anlegen" &&
pruefe "Rechte nur beim Anlegen gesetzt" \
  InheritedGroupTest::test_the_rule_reaches_existing_subscriptions failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" InheritedGroupTest passed

echo
echo "── PermissionFormTest: die Rechte wieder als Zahl im Systemdialog ──"
#
# Befund 8 aus docs/53: ein window.prompt, das eine Oktalzahl verlangt, nichts
# erklaert und einen schwarzen Systemkasten in ein helles Panel stellt.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('<PermissionEditor', '<div v-if="false" data-x', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "Rechte ohne gefuehrten Baustein" &&
pruefe "Rechte ohne gefuehrten Baustein" \
  PermissionFormTest::test_the_mode_is_not_asked_for_in_a_browser_dialog failed
wiederherstellen

echo
echo "── PermissionFormTest: die Erklaerung kennt den Ordner nicht mehr ──"
#
# Dasselbe Bit heisst bei einer Datei „ausfuehrbar" und bei einem Verzeichnis
# „betretbar". Ein Ordner ohne x sperrt seinen Eigentuemer aus, und das sieht
# aus wie ein Serverfehler.
vorher_datei resources/js/Components/PermissionEditor.vue
python3 - <<'PY2'
p = 'resources/js/Components/PermissionEditor.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("sätze.push('Achtung: Ohne „Ausführen\" lässt sich der Ordner nicht öffnen — auch nicht vom Eigentümer.')",
              "sätze.push('Achtung.')")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Components/PermissionEditor.vue "Warnsatz zum Ordner" &&
pruefe "Warnsatz zum Ordner entfernt" \
  PermissionFormTest::test_the_explanation_knows_what_it_is_talking_about failed
wiederherstellen

echo
echo "── PermissionFormTest: eine Vorlage mit setuid ──"
#
# setuid, setgid und Sticky werden nicht angeboten: Ihre Wirkung laesst sich in
# einer Zeile nicht ehrlich erklaeren.
vorher_datei resources/js/Components/PermissionEditor.vue
sed -i "s/{ mode: 0o644, label: 'Übliche Datei'/{ mode: 0o4644, label: 'Übliche Datei'/" resources/js/Components/PermissionEditor.vue
griff_datei resources/js/Components/PermissionEditor.vue "Vorlage mit setuid" &&
pruefe "Vorlage mit setuid" \
  PermissionFormTest::test_the_presets_stay_within_nine_bits failed
wiederherstellen

echo
echo "── PermissionFormTest: der Webserver-Satz haengt wieder am Weltbit ──"
#
# Seit httpdocs setgid traegt, liest der Webserver ueber die Gruppe. Am Weltbit
# waere der Satz die Auskunft von vor Schritt 6c — genau die Halbwahrheit aus
# Befund 3.
vorher_datei resources/js/Components/PermissionEditor.vue
sed -i 's/has(3, 4)$/has(0, 4)/' resources/js/Components/PermissionEditor.vue
griff_datei resources/js/Components/PermissionEditor.vue "Webserver-Satz am Weltbit" &&
pruefe "Webserver-Satz am Weltbit" \
  PermissionFormTest::test_the_sentence_about_the_webserver_follows_the_group failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PermissionFormTest passed

echo
echo "── FrontendDependencyTest: eine Marke ohne Farbe ──"
#
# Schritt 6d hat drei Marken dazugebracht (tok-property, tok-variable,
# tok-punctuation). Jede muss in app.css eine Farbe bekommen — eine Marke, die
# CodeMirror vergibt und die niemand einfaerbt, sieht aus wie gewoehnlicher
# Text und behauptet damit, nichts erkannt zu haben.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('.editor .tok-variable {', '.editor .tok-variable-x {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Marke ohne Farbe" &&
pruefe "Marke ohne Farbe" \
  FrontendDependencyTest::test_the_editor_brings_no_colours_of_its_own failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FrontendDependencyTest passed

echo
echo "── FileCreationTest: der Weg zum Anlegen einer Datei faellt weg ──"
#
# Die Fortsetzung von Befund 6, eine Ebene tiefer: files.write legt seit
# Schritt 3 an, was es nicht gibt — es fehlte nur der Knopf.
vorher_datei resources/js/Pages/Files/Index.vue
sed -i 's/          Datei anlegen/          Datei erzeugen/' resources/js/Pages/Files/Index.vue
griff_datei resources/js/Pages/Files/Index.vue "Weg zum Anlegen" &&
pruefe "Weg zum Anlegen einer Datei" \
  FileCreationTest::test_a_file_can_be_created_from_the_listing failed
wiederherstellen

echo
echo "── FileCreationTest: angelegt oder gespeichert wieder an einer Absicht ──"
#
# Der Fehler aus P4: eine Bedingung, die an einer Absicht haengt statt an einem
# Zustand. Der Agent sagt in seiner Antwort, ob die Datei neu entstanden ist.
vorher_datei app/Http/Controllers/FileController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/FileController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$created = ($result['created'] ?? false) === true;",
              "$created = ($data['neu'] ?? false) === true;")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/FileController.php "Absicht statt Zustand" &&
pruefe "angelegt oder gespeichert an einer Absicht" \
  FileCreationTest::test_creating_and_saving_are_told_apart_by_the_answer failed
wiederherstellen

echo
echo "── FileCreationTest: ein Zielpfad fuer alle hochgeladenen Dateien ──"
#
# Zwanzig Dateien unter demselben Namen, neunzehnmal ueberschrieben — und der
# Vorgang meldet zwanzig Erfolge.
vorher_datei app/Http/Controllers/FileController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/FileController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$target = rtrim($data['path'], '/').'/'.$leaf;", "$target = $data['path'];")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/FileController.php "ein Zielpfad fuer alle" &&
pruefe "ein Zielpfad fuer alle Dateien" \
  FileCreationTest::test_every_uploaded_file_keeps_its_own_name failed
wiederherstellen

echo
echo "── FileCreationTest: ein halb gelungener Upload meldet Erfolg ──"
#
# Der Gegenstand dieses Schritts. Neunzehn von zwanzig Dateien im Verzeichnis
# und darueber eine Erfolgsmeldung.
vorher_datei app/Http/Controllers/FileController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/FileController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'Von %d Dateien %s %d hochgeladen.'", "'Einige Dateien sind nicht hochgeladen.%s%s%s'")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/FileController.php "Zahl der gelungenen fehlt" &&
pruefe "Zahl der gelungenen Dateien fehlt" \
  FileCreationTest::test_a_partly_failed_upload_does_not_report_success failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" FileCreationTest passed

echo
echo "── SchemeProtectionTest: die Liste des Schemas wird abgetippt ──"
#
# Sie kommt aus SubscriptionProvision::reservedDirectories() und waechst mit dem
# Schema. Eine zweite Aufzaehlung veraltet beim naechsten Zuwachs.
vorher_datei agent/src/Files/Scheme.php
python3 - <<'PY2'
p = 'agent/src/Files/Scheme.php'
s = open(p, encoding='utf-8').read()
s = s.replace('[SubscriptionProvision::DOCUMENT_ROOT, ...SubscriptionProvision::reservedDirectories()]',
              "['httpdocs', 'logs', 'conf']")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Files/Scheme.php "Schema abgetippt" &&
pruefe "Liste des Schemas abgetippt" \
  SchemeProtectionTest::test_every_directory_of_the_scheme_is_protected failed
wiederherstellen

echo
echo "── SchemeProtectionTest: der Schutz greift auch fuer den Inhalt ──"
#
# Die andere Haelfte der Regel, und ohne sie waere der Schutz schlimmer als
# keiner: httpdocs leerzuraeumen ist genau das, was jemand vor einem neuen
# Deploy tut.
vorher_datei agent/src/Files/Scheme.php
python3 - <<'PY2'
p = 'agent/src/Files/Scheme.php'
s = open(p, encoding='utf-8').read()
s = s.replace("return in_array(rtrim($path, '/'), self::fixed(), true);",
              "foreach (self::fixed() as $f) { if (str_starts_with($path, $f)) { return true; } } return false;")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Files/Scheme.php "Schutz auch fuer den Inhalt" &&
pruefe "Schutz greift auch fuer den Inhalt" \
  SchemeProtectionTest::test_what_lies_inside_is_not failed
wiederherstellen

echo
echo "── SchemeProtectionTest: chmod fragt das Schema nicht ──"
#
# Die Operation, die man vergisst, und seit Schritt 6c die gefaehrlichste:
# httpdocs traegt das setgid-Bit, chmod nimmt nur neun Bits entgegen — das
# zehnte faellt lautlos weg.
vorher_datei agent/src/Ops/FilesChmod.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesChmod.php'
s = open(p, encoding='utf-8').read()
s = s.replace("Scheme::protect($path, 'in seinen Rechten geändert');", '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesChmod.php "chmod ohne Schema" &&
pruefe "chmod fragt das Schema nicht" \
  SchemeProtectionTest::test_the_operations_that_can_destroy_ask_first failed
wiederherstellen

echo
echo "── SchemeProtectionTest: die Pruefung rutscht in die Sandbox ──"
#
# Dort ist sie korrekt und wirkungslos: Der Kernel weist denselben Vorgang auch
# ab, nur nach dem Leerraeumen.
vorher_datei agent/src/Ops/FilesRemove.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesRemove.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        Scheme::protect($path, 'entfernt');\n", '')
s = s.replace("""        return $workspace->run($context, static function () use ($path, $recursive): array {
            $entry = Entry::of($path);""",
              """        return $workspace->run($context, static function () use ($path, $recursive): array {
            Scheme::protect($path, 'entfernt');
            $entry = Entry::of($path);""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesRemove.php "Pruefung in der Sandbox" &&
pruefe "Pruefung steht in der Sandbox" \
  SchemeProtectionTest::test_the_check_runs_before_anything_happens failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SchemeProtectionTest passed

echo
echo "── PanelRequestTest: eine zweite Stelle ruft fetch ──"
#
# Der Satz stand seit P5c als Kommentar in useConsole.ts und war von nichts
# geprueft. Als P6 den Baum bekam, brauchte der denselben Weg — der zweite
# Aufrufer ist genau der Fall, vor dem der Kommentar warnte.
vorher_datei resources/js/Composables/useConsole.ts
python3 - <<'PY2'
p = 'resources/js/Composables/useConsole.ts'
s = open(p, encoding='utf-8').read()
s = s.replace('return askPanel<T>(', 'void fetch("/x"); return askPanel<T>(', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Composables/useConsole.ts "zweiter fetch" &&
pruefe "eine zweite Stelle ruft fetch" \
  PanelRequestTest::test_only_one_place_calls_fetch failed
wiederherstellen

echo
echo "── PanelRequestTest: eine Kopfzeile faellt weg ──"
#
# Ohne X-Requested-With erkennt Laravel die Anfrage nicht als eine, die keine
# Umleitung vertraegt. Der erste Wurf dieses Waechters blieb dabei gruen — er
# fand die Kopfzeile im eigenen Klassenkopf, wo sie erklaert wird.
vorher_datei resources/js/Composables/usePanelRequest.ts
python3 - <<'PY2'
p = 'resources/js/Composables/usePanelRequest.ts'
s = open(p, encoding='utf-8').read()
s = s.replace("      'X-Requested-With': 'XMLHttpRequest',\n", '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Composables/usePanelRequest.ts "Kopfzeile fehlt" &&
pruefe "eine Kopfzeile fehlt" \
  PanelRequestTest::test_the_one_place_sends_all_three_headers failed
wiederherstellen

echo
echo "── PanelRequestTest: der Status entscheidet vor dem Rumpf ──"
#
# Ein 422 traegt die Begruendung, an der zwei Abnahmekriterien aus docs/46 §4
# hingen. Wer beim Status abbricht, wirft genau sie weg.
vorher_datei resources/js/Composables/usePanelRequest.ts
python3 - <<'PY2'
p = 'resources/js/Composables/usePanelRequest.ts'
s = open(p, encoding='utf-8').read()
s = s.replace('  const text = await antwort.text()',
              '  if (antwort.ok) { return (await antwort.json()) as T }\n  const text = await antwort.text()', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Composables/usePanelRequest.ts "Status vor Rumpf" &&
pruefe "der Status entscheidet vor dem Rumpf" \
  PanelRequestTest::test_the_body_is_read_before_the_status_decides failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" PanelRequestTest passed

echo
echo "── FilesTree: der Baum laeuft nicht in der Sandbox ──"
#
# Zwoelf Operationen betraten die Grenze, und nichts hielt fest, dass die
# dreizehnte es auch tut. Ein files.tree ohne Workspace::run() liest als root im
# ganzen Dateisystem — und zwar die Operation, die am haeufigsten laeuft.
vorher_datei agent/src/Ops/FilesTree.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesTree.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""return $workspace->run($context, static function () use ($path): array {""",
              """$lauf = static function () use ($path): array {""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesTree.php "Baum ohne Sandbox" &&
pruefe "Baum ohne Sandbox" \
  SandboxReachTest::test_every_file_operation_goes_through_the_sandbox failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SandboxReachTest passed

echo
echo "── BulkActionTest: ein Griff liest die Auswahl selbst ──"
#
# Die Obergrenze, das min:1 und das array_unique stehen in selection(). Viermal
# abgeschrieben waere es viermal die Gelegenheit, eines davon zu vergessen.
vorher_datei app/Http/Controllers/FileController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/FileController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""        $paths = $this->selection($request, ['recursive' => ['boolean']]);""",
              """        $data = $request->validate([
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['required', 'string', 'max:4096'],
            'recursive' => ['boolean'],
        ]);
        $paths = $data['paths'];""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/FileController.php "Auswahl selbst gelesen" &&
pruefe "ein Griff liest die Auswahl selbst" \
  BulkActionTest::test_every_handler_reads_the_selection_in_one_place failed
wiederherstellen

echo
echo "── BulkActionTest: ein Griff meldet nicht, was schiefging ──"
#
# Der Gegenstand dieses Schritts. Neunzehn von zwanzig Eintraegen verschoben und
# darueber eine Erfolgsmeldung.
vorher_datei app/Http/Controllers/FileController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/FileController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $this->report($paths, $result, 'verschoben');\n", '')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/FileController.php "Rueckmeldung fehlt" &&
pruefe "ein Griff meldet Fehlschlaege nicht" \
  BulkActionTest::test_every_looping_handler_reports_what_failed failed
wiederherstellen

echo
echo "── BulkActionTest: die Zahl steht hinter den Gruenden ──"
#
# Wer drei Meldungen liest und die Gesamtzahl erst darunter findet, hat die Frage
# „sind die anderen durch?" schon dreimal falsch beantwortet.
vorher_datei app/Http/Controllers/FileController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/FileController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""        $messages = [sprintf(
            'Von %d Einträgen %s %d %s.',
            count($paths),
            $result['done'] === 1 ? 'ist' : 'sind',
            $result['done'],
            $verb,
        )];

        foreach ($result['failed'] as $path => $reason) {
            $messages[] = sprintf('%s: %s', $path, $reason);
        }""",
              """        $messages = [];

        foreach ($result['failed'] as $path => $reason) {
            $messages[] = sprintf('%s: %s', $path, $reason);
        }

        $messages[] = sprintf(
            'Von %d Einträgen %s %d %s.',
            count($paths),
            $result['done'] === 1 ? 'ist' : 'sind',
            $result['done'],
            $verb,
        );""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/FileController.php "Zahl hinter den Gruenden" &&
pruefe "die Zahl steht hinter den Gruenden" \
  BulkActionTest::test_the_tally_stands_before_the_reasons failed
wiederherstellen

echo
echo "── BulkActionTest: der einzelne Eintrag bekommt eine Mengenangabe ──"
#
# „Von 1 Eintraegen ist 0 entfernt." ist die Zahl ohne die Auskunft — und die
# Auskunft ist bei einem Eintrag alles, was es zu sagen gibt.
vorher_datei app/Http/Controllers/FileController.php
sed -i 's/if (count($paths) === 1) {/if (count($paths) === 0) {/' app/Http/Controllers/FileController.php
griff_datei app/Http/Controllers/FileController.php "Einzahl faellt weg" &&
pruefe "der einzelne Eintrag bekommt eine Mengenangabe" \
  BulkActionTest::test_a_single_entry_gets_its_reason_without_a_tally failed
wiederherstellen

echo
echo "── BulkActionTest: das Ziel wird als vollstaendiger Pfad benutzt ──"
#
# Derselbe Fehler wie beim Mehrfach-Upload: ein Pfad fuer alle. Der letzte
# Eintrag gewinnt, die anderen sind fort, und der Vorgang meldet Erfolg.
vorher_datei app/Http/Controllers/FileController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/FileController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""            $to = $this->into($ziel, $path);

            $this->files->copy($subscription, $path, $to);""",
              """            $to = $ziel;

            $this->files->copy($subscription, $path, $to);""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/FileController.php "ein Zielpfad fuer alle Eintraege" &&
pruefe "das Ziel wird als vollstaendiger Pfad benutzt" \
  BulkActionTest::test_the_target_of_a_batch_is_a_directory failed
wiederherstellen

echo
echo "── BulkActionTest: umbenennen nimmt wieder einen Pfad ──"
#
# Ein Feld mit zwei Bedeutungen hat keine.
vorher_datei app/Http/Controllers/FileController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/FileController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'name' => ['required', 'string', 'max:255'],",
              "            'to' => ['required', 'string', 'max:4096'],")
s = s.replace("""        $leaf = basename(str_replace('\\\\', '/', $data['name']));""",
              """        $leaf = $data['to'];""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/FileController.php "umbenennen nimmt einen Pfad" &&
pruefe "umbenennen nimmt wieder einen Pfad" \
  BulkActionTest::test_renaming_asks_for_a_name_and_not_for_a_path failed
wiederherstellen

echo
echo "── BulkActionTest: die Seite setzt den Zielpfad wieder selbst zusammen ──"
#
# Die andere Haelfte derselben Regel — und die Stelle, an der der Fehler aus §8.3
# entstanden ist.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('    rename.name = entry.name', '    rename.to = here(entry.name)')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "Zielpfad in der Seite" &&
pruefe "die Seite setzt den Zielpfad selbst zusammen" \
  BulkActionTest::test_renaming_asks_for_a_name_and_not_for_a_path failed
wiederherstellen

echo
echo "── BulkActionTest: die Auswahl ueberlebt die Navigation ──"
#
# Dann steht ueber der Tabelle eine Zahl, zu der kein einziger Haken gehoert —
# und der naechste Klick auf „Entfernen" trifft Eintraege aus einem anderen
# Ordner.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
# Nur die eine Zeile, nicht der ganze Rumpf: Der Beobachter raeumt inzwischen
# vier Zustaende auf, und ein Eingriff, der den Rumpf woertlich kennt, faellt bei
# jedem fuenften wieder aus.
s = s.replace("\n  selected.value = []\n", "\n", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "Auswahl ueberlebt" &&
pruefe "die Auswahl ueberlebt die Navigation" \
  BulkActionTest::test_the_selection_falls_away_when_the_directory_changes failed
wiederherstellen

echo
echo "── BulkActionTest: ein Knopf zeigt auf eine Adresse, die es nicht gibt ──"
#
# vue-tsc faengt einen fehlenden Namen. Eine Adresse, die auf keine Route mehr
# zeigt, faengt es nicht — der Kunde bekommt dort eine 404.
vorher_datei resources/js/Pages/Files/Index.vue
sed -i 's|/files/compress`|/files/archive`|' resources/js/Pages/Files/Index.vue
griff_datei resources/js/Pages/Files/Index.vue "Adresse ohne Route" &&
pruefe "ein Knopf zeigt auf keine Route" \
  BulkActionTest::test_every_action_of_the_selection_reaches_a_route failed
wiederherstellen

echo
echo "── BulkActionTest: die Route zum Kopieren heisst anders ──"
#
# Kopieren und Verschieben setzt die Seite zur Laufzeit zusammen; ohne die zwei
# Zeilen im Waechter waere ausgerechnet der neue Teil der einzige ungeprueften.
vorher_datei routes/web.php
sed -i "s|/files/copy'|/files/duplicate'|" routes/web.php
griff_datei routes/web.php "Route umbenannt" &&
pruefe "die Route zum Kopieren heisst anders" \
  BulkActionTest::test_every_action_of_the_selection_reaches_a_route failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BulkActionTest passed

echo
echo "── SelectionTest: zwei Schreibweisen desselben Pfades zaehlen zweimal ──"
#
# Beim zweiten Mal meldet der Agent „gibt es nicht" — mitten in einer
# Rueckmeldung ueber siebzehn Erfolge.
vorher_datei agent/src/Files/Workspace.php
python3 - <<'PY2'
p = 'agent/src/Files/Workspace.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""            if (! in_array($pfad, $paths, true)) {
                $paths[] = $pfad;
            }""",
              """            $paths[] = $pfad;""")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Files/Workspace.php "keine Entdopplung" &&
pruefe "zwei Schreibweisen zaehlen zweimal" \
  SelectionTest::test_two_spellings_of_the_same_path_count_once failed
wiederherstellen

echo
echo "── SelectionTest: die Namen werden erst nach dem open geprueft ──"
#
# Dann bekommt der Kunde „liess sich nicht anlegen", raeumt das Ziel weg und
# laeuft in denselben Fehler. Von zwei Gruenden gehoert der genannt, den der
# naechste Versuch nicht von selbst behebt.
vorher_datei agent/src/Files/Packer.php
python3 - <<'PY2'
p = 'agent/src/Files/Packer.php'
s = open(p, encoding='utf-8').read()
oeffnen = """        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw AgentException::denied('Das Archiv liess sich nicht anlegen.');
        }

"""
s = s.replace(oeffnen, '', 1)
s = s.replace("        $vergeben = [];\n", "        $vergeben = [];\n\n" + oeffnen, 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Files/Packer.php "Namen nach dem open" &&
pruefe "die Namen werden nach dem open geprueft" \
  SelectionTest::test_the_names_are_checked_before_the_archive_is_opened failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SelectionTest passed

echo
echo "── BrowserDialogTest: eine Seite fragt wieder ueber einen Systemdialog ──"
#
# Der Fall vom iPhone: Safari darf die Dialoge einer Seite abschalten, und
# `prompt()` gibt danach ohne ein Zeichen `null` zurueck. Der Knopf tut dann
# nichts — und sieht dabei aus wie ein Knopf.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("  archiveName.value = 'auswahl.zip'",
              "  archiveName.value = window.prompt('Wie soll das Archiv heissen?', 'auswahl.zip')", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "prompt im Packen" &&
pruefe "eine Seite fragt ueber einen Systemdialog" \
  BrowserDialogTest::test_no_page_asks_for_input_through_a_browser_dialog failed
wiederherstellen

echo
echo "── BrowserDialogTest: eine Rueckfrage steht wieder in window.confirm ──"
#
# Die andere Haelfte derselben Regel. Sie sah lange wie die harmlose aus — ein
# ausgefallenes confirm() gibt false, es geschieht also nichts. Auf dem iPhone
# hiess das: achtzehn Aktionen ohne jede Wirkung.
vorher_datei resources/js/Pages/Subscriptions/Show.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Show.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("""  ask(
    `${props.subscription.name} sperren? Webseiten und Zugänge sind danach aus, die Daten bleiben.`,
    'Sperren',
    () => { router.post(`/subscriptions/${props.subscription.id}/suspend`) },
    // Umkehrbar — der Satz der Frage sagt es selbst: „die Daten bleiben".
    false,
  )""",
"""  if (!window.confirm(`${props.subscription.name} sperren?`)) return
  router.post(`/subscriptions/${props.subscription.id}/suspend`)""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Show.vue "confirm beim Sperren" &&
pruefe "eine Rueckfrage steht wieder im Systemdialog" \
  BrowserDialogTest::test_no_page_asks_for_input_through_a_browser_dialog failed
wiederherstellen

echo
echo "── ComponentReachTest: die Rueckfrage wird nirgends gezeichnet ──"
#
# useConfirmation kann fragen, so viel es will — steht Confirmation.vue nicht im
# Layout, sieht die Frage niemand, und der Knopf tut wieder nichts. Genau der
# Zustand, den dieser ganze Umbau beseitigen sollte.
vorher_datei resources/js/Layouts/PanelLayout.vue
python3 - <<'PY2'
p = 'resources/js/Layouts/PanelLayout.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("import Confirmation from '../Components/Confirmation.vue'\n", "", 1)
s = s.replace("      <Confirmation />\n\n", "", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Layouts/PanelLayout.vue "Rueckfrage aus dem Layout" &&
pruefe "die Rueckfrage wird nirgends gezeichnet" \
  ComponentReachTest::test_every_component_is_used_somewhere failed
wiederherstellen

echo
echo "── BrowserDialogTest: der Ausdruck liest die Seiten gar nicht ──"
#
# Die Gegenprobe zum Waechter selbst. Faende er keine Datei, waere seine leere
# Trefferliste kein Befund, sondern ein Ausdruck, der ins Leere laeuft — und
# genau so gruen.
vorher_datei tests/Feature/BrowserDialogTest.php
python3 - <<'PY2'
p = 'tests/Feature/BrowserDialogTest.php'
s = open(p, encoding='utf-8').read()
s = s.replace("$file->getExtension() === 'vue'", "$file->getExtension() === 'vuu'", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Feature/BrowserDialogTest.php "Endung verdreht" &&
pruefe "der Waechter liest keine Seite mehr" \
  BrowserDialogTest::test_the_search_really_reads_the_pages failed
wiederherstellen

echo
echo "── BrowserDialogTest: der Kommentarschnitt frisst den Code ──"
#
# Ein zu gieriger Ausdruck nimmt alles zwischen dem ersten und dem letzten
# Blockkommentar mit. Der Waechter waere danach gruen, weil er fast nichts mehr
# liest — die schlimmste Art, gruen zu sein.
vorher_datei tests/Feature/BrowserDialogTest.php
python3 - <<'PY2'
p = 'tests/Feature/BrowserDialogTest.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'#/\\*.*?\\*/#s'", "'#/\\*.*\\*/#s'", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei tests/Feature/BrowserDialogTest.php "gieriger Kommentarschnitt" &&
pruefe "der Kommentarschnitt frisst den Code" \
  BrowserDialogTest::test_stripping_comments_keeps_the_code failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" BrowserDialogTest passed

echo
echo "── SandboxGroupTest: files.chmod verlangt die Gruppe nicht mehr ──"
#
# Ohne sie raeumt der Kernel das setgid-Bit lautlos wieder ab, und chmod() gibt
# dabei true zurueck. Der Fix war einmal ausgeliefert und hat nichts getan.
vorher_datei agent/src/Ops/FilesChmod.php
python3 - <<'PY2'
p = 'agent/src/Ops/FilesChmod.php'
s = open(p, encoding='utf-8').read()
s = s.replace("}, [], SubscriptionProvision::DOCUMENT_ROOT_GROUP);", "});", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/FilesChmod.php "Gruppe beim chmod" &&
pruefe "files.chmod verlangt die Gruppe nicht mehr" \
  SandboxGroupTest::test_the_search_finds_the_one_that_does failed
wiederherstellen

echo
echo "── SandboxGroupTest: die Gruppe kommt bei initgroups nicht an ──"
#
# Zwischen dem Griff und dem Systemaufruf liegen zwei Weitergaben. Faellt das
# Argument unterwegs weg, laeuft alles weiter und tut wieder nichts.
vorher_datei agent/src/Sandbox.php
python3 - <<'PY2'
p = 'agent/src/Sandbox.php'
s = open(p, encoding='utf-8').read()
s = s.replace("posix_initgroups($account['name'], $account['extra_gid'] ?? $account['gid'])",
              "posix_initgroups($account['name'], $account['gid'])", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Sandbox.php "Gruppe vor initgroups" &&
pruefe "die Gruppe kommt bei initgroups nicht an" \
  SandboxGroupTest::test_the_group_reaches_initgroups failed
wiederherstellen

echo
echo "── SchemeHandleTest: die Antwort geht an der Markierung vorbei ──"
#
# Der Griff bleibt stehen, nur ruft ihn niemand mehr. Genau so ist der erste
# Bruch gegen diesen Waechter durchgekommen -- der Ausdruck fand die Zeile
# weiter, in totem Code.
vorher_datei app/Http/Controllers/FileController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/FileController.php'
s = open(p, encoding='utf-8').read()
s = s.replace("'entries' => $this->marked($listing['entries'] ?? []),",
              "'entries' => $listing['entries'] ?? [],", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/FileController.php "Markierung umgangen" &&
pruefe "die Antwort geht an der Markierung vorbei" \
  SchemeHandleTest::test_the_listing_marks_the_scheme_directories failed
wiederherstellen

echo
echo "── SchemeHandleTest: die Liste zeigt die Griffe wieder ──"
#
# Umbenennen, Rechte und Entfernen weist Scheme fuer die sechs Verzeichnisse
# immer ab. Ein Knopf, der nie funktioniert, ist keine Auskunft.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("entry.writable && !entry.fixed", "entry.writable", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "Griffe am Geruest" &&
pruefe "die Liste zeigt die Griffe wieder" \
  SchemeHandleTest::test_the_page_hides_the_handles_for_them failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SchemeHandleTest passed

echo
echo "── InViewTest: die Rueckfrage holt sich nicht mehr ins Bild ──"
#
# Gedrueckt wird unten, gefragt wird oben, und mit preserveScroll springt die
# Seite nicht mit. Auf dem Telefon sah das aus wie ein kaputter Knopf.
vorher_datei resources/js/Components/Confirmation.vue
python3 - <<'PY2'
p = 'resources/js/Components/Confirmation.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("void nextTick(() => bringIntoView(block.value))", "void nextTick()", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Components/Confirmation.vue "Sprung ins Bild" &&
pruefe "die Rueckfrage holt sich nicht ins Bild" \
  InViewTest::test_every_block_that_speaks_brings_itself_into_view failed
wiederherstellen

echo
echo "── InViewTest: gescrollt wird ohne Bedingung ──"
#
# Die Gegenrichtung. Ohne die Pruefung reisst jede Meldung die Seite herum,
# auch die, die laengst im Bild steht.
vorher_datei resources/js/scroll.ts
python3 - <<'PY2'
p = 'resources/js/scroll.ts'
s = open(p, encoding='utf-8').read()
s = s.replace("    if (! fullyVisible(element)) {", "    if (true) {", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/scroll.ts "Sprung ohne Bedingung" &&
pruefe "gescrollt wird ohne Bedingung" \
  InViewTest::test_it_only_scrolls_when_something_is_out_of_view failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" InViewTest passed

echo
echo "── ThemeTest: das Theme gilt nicht fuer die Bedienelemente ──"
#
# Ohne color-scheme zeichnet der Browser Ankreuzfelder nach dem System und
# nicht nach der Seite. Auf einem iPhone mit dunklem System und hellem Panel
# stand ein leeres Kaestchen als schwarz gefuelltes Quadrat da.
vorher
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace("  color-scheme: light;\n", "", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff "color-scheme im hellen Theme" &&
pruefe "das helle Theme sagt nichts ueber die Bedienelemente" \
  ThemeTest::test_both_themes_declare_their_color_scheme failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ThemeTest passed

echo
echo "── SchemeHandleTest: das Geruest laesst sich wieder anhaken ──"
#
# Ueber den Haken fuehrt der Weg in die Mehrfachauswahl, und deren rote Knoepfe
# weist Scheme genauso ab -- nur erfaehrt der Kunde es erst hinterher.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('                v-if="! entry.fixed"\n', '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "Haken am Geruest" &&
pruefe "das Geruest laesst sich wieder anhaken" \
  SchemeHandleTest::test_they_cannot_be_ticked_either failed
wiederherstellen

echo
echo "── SchemeHandleTest: nur der Spaltenkopf ist umbenannt ──"
#
# Der Kopf gilt fuer die breite Ansicht, data-column fuer die Kaertchen unter
# 720px. Wer nur eines aendert, bekommt zwei Woerter auf einer Seite -- je
# nachdem, wie breit sie gerade ist.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('data-column="Aktion"', 'data-column="Griffe"', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "halb umbenannt" &&
pruefe "nur der Spaltenkopf ist umbenannt" \
  SchemeHandleTest::test_the_action_column_is_called_what_the_panel_calls_it failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SchemeHandleTest passed

echo
echo "── ClassBlockTest: zwei Bausteine unter einem Namen ──"
#
# Genau die Lage, aus der die Linie neben der Wurzel des Dateibaums entstanden
# ist: `.tree ul` gehoert der Datenbankkonsole, und der Dateibaum hiess
# ebenfalls `tree`. Wer den einen Block liest, sieht den anderen nicht.
vorher
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('.file-tree {\n  min-width: 0;', '.tree {\n  min-width: 0;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff "der Dateibaum heisst wieder tree" &&
pruefe "zwei Bausteine unter einem Namen" \
  ClassBlockTest::test_no_class_is_styled_in_two_places failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" ClassBlockTest passed

echo
echo "── MobileLayoutTest: die zugeklappte Reihe ist ueberall zugeklappt ──"
#
# Der teure Fall: Verlaesst `.button-row.folded` seinen Medienblock, ist auf der
# breiten Flaeche jede Knopfreihe fort -- und zugeklappt sind dort alle.
# Umbenennen, Rechte, Entpacken und Entfernen waeren unerreichbar, und die Seite
# saehe dabei aus wie immer.
vorher
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace("""  .button-row.folded {
    display: none;
  }
""", '', 1)
s = s.replace('.button.fold {\n  display: none;\n}', '.button.fold {\n  display: none;\n}\n\n.button-row.folded {\n  display: none;\n}', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff "folded ohne Medienblock" &&
pruefe "die zugeklappte Reihe ist ueberall zugeklappt" \
  MobileLayoutTest::test_a_folded_row_is_only_folded_on_a_phone failed
wiederherstellen

echo
echo "── MobileLayoutTest: der Umschalter steht auch am Arbeitsplatz ──"
#
# Der Umschalter ist im Grundzustand fort und wird unter 720px eingeschaltet,
# nicht umgekehrt: Was ohne Bedingung dasteht, gilt auch dort, wo niemand
# nachgesehen hat.
vorher
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace('.button.fold {\n  display: none;\n}', '.button.fold {\n  display: inline-flex;\n}', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff "Umschalter ohne Bedingung sichtbar" &&
pruefe "der Umschalter steht auch am Arbeitsplatz" \
  MobileLayoutTest::test_a_folded_row_is_only_folded_on_a_phone failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" MobileLayoutTest passed

echo
echo "── LabelReachTest: die Beschriftung zeigt ins Leere ──"
#
# Fuer das Auge aendert das nichts. Der Block hat fuer jemanden, der die Seite
# hoert, dann einfach keinen Namen mehr -- und nichts meldet es.
vorher_datei resources/js/Components/FileTree.vue
python3 - <<'PY2'
p = 'resources/js/Components/FileTree.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('<p id="file-tree-title"', '<p id="file-tree-heading"', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Components/FileTree.vue "Kennung umbenannt" &&
pruefe "die Beschriftung zeigt ins Leere" \
  LabelReachTest::test_every_labelled_by_names_an_id_that_exists failed
wiederherstellen

echo
echo "── LabelReachTest: zwei Namen an einem Element ──"
#
# Wo beides steht, gewinnt aria-label, und der Vorleser sagt etwas anderes als
# das, was auf dem Schirm steht.
vorher_datei resources/js/Components/FileTree.vue
python3 - <<'PY2'
p = 'resources/js/Components/FileTree.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '<nav class="file-tree" aria-labelledby="file-tree-title">',
    '<nav class="file-tree" aria-labelledby="file-tree-title" aria-label="Verzeichnisse">',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Components/FileTree.vue "zweiter Name" &&
pruefe "zwei Namen an einem Element" \
  LabelReachTest::test_nothing_carries_two_names_at_once failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" LabelReachTest passed

echo
echo "── DangerRankTest: der Zeilenknopf wird wieder grau ──"
#
# Genau der Fund vom 16. August: In der Dateiliste war „Entfernen" in der
# Auswahlleiste rot und dasselbe „Entfernen" in der Zeile darunter grau --
# gleiche Handlung, gleiche Seite, zwei Erscheinungen.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    'class="button small danger" @click="remove(entry)"',
    'class="button small" @click="remove(entry)"',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "Zeilenknopf grau" &&
pruefe "der Zeilenknopf wird wieder grau" \
  DangerRankTest::test_the_button_and_its_confirmation_agree failed
wiederherstellen

echo "── DangerRankTest: die Rueckfrage zum roten Knopf wird nicht rot ──"
#
# Die andere Richtung. Ein `false` am vierten Argument macht aus einer roten
# Rueckfrage eine gewoehnliche -- der Knopf darueber bleibt rot, und die beiden
# sagen Verschiedenes ueber dieselbe Handlung.
#
# **Der erste Anlauf dieses Bruchs hat den Code zerstoert statt die Regel zu
# verletzen:** Das `, false` landete hinter der schliessenden Klammer von
# `ask(...)` statt darin. Der Waechter blieb gruen, und zwar zu Recht.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    """      onSuccess: () => { selected.value = [] },
    })
  })
}""",
    """      onSuccess: () => { selected.value = [] },
    })
  }, false)
}""",
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "viertes Argument false" &&
pruefe "die Rueckfrage zum roten Knopf wird nicht rot" \
  DangerRankTest::test_the_button_and_its_confirmation_agree failed
wiederherstellen

echo "── DangerRankTest: ein roter Formularknopf verschwindet ungezaehlt ──"
#
# Was dieser Waechter nicht sehen kann, zaehlt er. Ohne die Zahl stuende ein
# neuer `type="submit"` mit roter Marke lautlos ausserhalb jeder Pruefung.
vorher_datei resources/js/Pages/Auth/TwoFactorSetup.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Auth/TwoFactorSetup.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('class="button danger"', 'class="button"', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Auth/TwoFactorSetup.vue "roter Formularknopf fort" &&
pruefe "ein roter Formularknopf verschwindet ungezaehlt" \
  DangerRankTest::test_the_uncovered_buttons_are_counted failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DangerRankTest passed

echo
echo "── SchemeHandleTest: der Kopfhaken steht wieder ueber dem Geruest ──"
#
# Dieselbe Halbheit wie Befund 21, eine Zeile hoeher: In der Abo-Wurzel gibt es
# nichts auszuwaehlen, und der Haken blieb nach dem Klick trotzdem angehakt --
# der Setzer schreibt eine leere Auswahl, der Leser rechnet daraus denselben
# Wert wie vorher, und Vue schreibt das DOM deshalb nicht zurueck.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace('                v-if="selectable.length > 0"\n', '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "Kopfhaken ohne Bedingung" &&
pruefe "der Kopfhaken steht wieder ueber dem Geruest" \
  SchemeHandleTest::test_the_header_tick_is_gone_when_nothing_can_be_ticked failed
wiederherstellen

echo "── SchemeHandleTest: die Auswahlspalte faellt aus der Kopfzeile ──"
#
# Der naheliegende Fix waere, das v-if an das <th> zu haengen. Dann hat die
# Kopfzeile in der Abo-Wurzel fuenf Spalten und der Rumpf sechs, denn jede Zeile
# traegt ihr <td data-column="Auswahl"> auch leer.
vorher_datei resources/js/Pages/Files/Index.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Files/Index.vue'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '<th v-if="props.can.edit">',
    '<th v-if="props.can.edit && selectable.length > 0">',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Files/Index.vue "Spalte im Kopf fort" &&
pruefe "die Auswahlspalte faellt aus der Kopfzeile" \
  SchemeHandleTest::test_the_header_tick_is_gone_when_nothing_can_be_ticked failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SchemeHandleTest passed

echo
echo "── ManagedBlockTest: der Verwalter einer fremden Datei schreibt selbst ──"
#
# Die Regel ist nicht „schreib vorsichtig", sondern „schreib gar nicht": Eine
# zweite Schreibstelle waere eine zweite Fassung von Sperre, Ersetzung und
# Rechteuebernahme — und die zweite ist die, die veraltet.
vorher_datei agent/src/Pg/Hba.php
python3 - <<'PY2'
p = 'agent/src/Pg/Hba.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '    public static function prepend(',
    '    public static function schnell(string $p): void { file_put_contents($p, "\\n"); }\n\n    public static function prepend(',
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Pg/Hba.php "Hba schreibt selbst" &&
pruefe "der Verwalter einer fremden Datei schreibt selbst" \
  ManagedBlockTest::test_a_manager_of_a_foreign_file_writes_nothing_itself failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ManagedBlockTest::test_a_manager_of_a_foreign_file_writes_nothing_itself passed

echo
echo "── ManagedBlockTest: ein put() einen Schritt neben der Sperre ──"
#
# **Das ist der Fehler aus docs/45 in seiner allgemeinen Form.** Er sieht beim
# Lesen richtig aus: Das Schreiben steht noch in derselben Methode, nur eben
# hinter der schliessenden Klammer von locked(). Genau dazwischen kann sich ein
# zweiter Prozess schieben.
vorher_datei agent/src/Ops/PgRoleRemove.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgRoleRemove.php'
s = open(p, encoding='utf-8').read()
alt = '            ManagedBlock::put($path, ManagedBlock::render($content, $keep, $path));'
s = s.replace(alt, '            $fertig = ManagedBlock::render($content, $keep, $path);')
s = s.replace(
    '        return $dropped;',
    "        ManagedBlock::put($path, $fertig ?? '');\n\n        return $dropped;",
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgRoleRemove.php "put() aus der Sperre gezogen" &&
pruefe "ein put() steht ausserhalb der Sperre" \
  ManagedBlockTest::test_every_read_and_write_sits_under_the_lock failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ManagedBlockTest::test_every_read_and_write_sits_under_the_lock passed

echo
echo "── ManagedBlockTest: die Sperre liegt auf der Datei statt daneben ──"
vorher_datei agent/src/ManagedBlock.php
sed -i 's|\$lock = \$path\.self::LOCK_SUFFIX;|$lock = $path;|' agent/src/ManagedBlock.php
griff_datei agent/src/ManagedBlock.php "Sperre auf der Datei" &&
pruefe "die Sperre liegt auf der verwalteten Datei" \
  ManagedBlockTest::test_the_lock_lies_beside_the_file failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ManagedBlockTest::test_the_lock_lies_beside_the_file passed

echo
echo "── ManagedBlockTest: der Zähler fehlt, und die Sperre wartet auf sich selbst ──"
#
# **Dieser Eingriff ist der Grund für die Frist im Wächter.** Ohne den Zähler
# hängt der verschachtelte Aufruf — er schlägt nicht fehl, er steht. Ein
# Wächter ohne Kindprozess und Frist meldete hier nichts, sondern hielte diesen
# ganzen Lauf an.
vorher_datei agent/src/ManagedBlock.php
python3 - <<'PY2'
p = 'agent/src/ManagedBlock.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        if ((self::$held[$path] ?? 0) > 0) {', '        if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/ManagedBlock.php "Zähler der Sperre entfernt" &&
pruefe "die Sperre wartet auf sich selbst" \
  ManagedBlockTest::test_the_lock_is_reentrant failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ManagedBlockTest::test_the_lock_is_reentrant passed

echo
echo "── ManagedBlockTest: geschrieben wird in die Datei statt daneben ──"
#
# `file_put_contents` kürzt zuerst und schreibt dann. Ein Abbruch dazwischen
# lässt eine leere pg_hba.conf zurück — die ist syntaktisch fehlerfrei und
# weist jeden ab.
vorher_datei agent/src/ManagedBlock.php
python3 - <<'PY2'
p = 'agent/src/ManagedBlock.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $temporary = $path.'.srvpanel.'.getmypid();", '        $temporary = $path;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/ManagedBlock.php "kein Umweg über die Nachbardatei" &&
pruefe "die Datei wird gekürzt statt ersetzt" \
  ManagedBlockTest::test_the_file_is_replaced_and_never_truncated failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ManagedBlockTest::test_the_file_is_replaced_and_never_truncated passed

echo
echo "── ManagedBlockTest: der Bestand ausserhalb der Marken fällt weg ──"
vorher_datei agent/src/ManagedBlock.php
python3 - <<'PY2'
p = 'agent/src/ManagedBlock.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    '        $rest = self::without($content, $path);',
    "        $rest = self::without($content, $path);\n        $rest = '';",
    1,
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/ManagedBlock.php "Bestand fällt weg" &&
pruefe "alles ausserhalb der Marken fällt weg" \
  ManagedBlockTest::test_everything_outside_the_markers_stays failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ManagedBlockTest::test_everything_outside_the_markers_stays passed

echo
echo "── ManagedBlockTest: ein BEGIN ohne END wird geraten statt gemeldet ──"
vorher_datei agent/src/ManagedBlock.php
python3 - <<'PY2'
p = 'agent/src/ManagedBlock.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        if ($end === null) {', '        if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/ManagedBlock.php "BEGIN ohne END geht durch" &&
pruefe "ein halb geschriebener Bereich wird geraten" \
  ManagedBlockTest::test_a_block_without_an_end_stops_instead_of_guessing failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ManagedBlockTest::test_a_block_without_an_end_stops_instead_of_guessing passed

echo
echo "── PublicKeyTest: die Typprüfung fällt weg ──"
#
# **Ohne sie kommt eine Zeile mit Optionen herein.** Gemessen (docs/57 §11):
# Ohne ForceCommand in der Konfiguration wird ein `command="…"` aus
# authorized_keys ausgeführt. Die zweite Wand steht — und ist kein Grund für
# ein Loch in der ersten.
vorher_datei agent/src/Ssh/PublicKey.php
python3 - <<'PY2'
p = 'agent/src/Ssh/PublicKey.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        if (! array_key_exists($type, self::TYPES)) {', '        if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/PublicKey.php "Typprüfung entfernt" &&
pruefe "eine Zeile mit Optionen kommt herein" \
  PublicKeyTest::test_a_line_with_options_in_front_is_refused failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  PublicKeyTest::test_a_line_with_options_in_front_is_refused passed

echo
echo "── PublicKeyTest: Steuerzeichen gehen durch ──"
#
# Ein Zeilenumbruch macht aus einer Zeile zwei — und die zweite wäre ein
# Zugang, den das Panel nicht anzeigt. Dieselbe Einschleusung wie in
# docs/51 §10.1 für /etc/cron.d, nur mit einem anderen Ziel.
vorher_datei agent/src/Ssh/PublicKey.php
python3 - <<'PY2'
p = 'agent/src/Ssh/PublicKey.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        if (preg_match('/[\\x00-\\x1F\\x7F]/', $raw) === 1) {", '        if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/PublicKey.php "Steuerzeichenprüfung entfernt" &&
pruefe "ein Zeilenumbruch macht aus einer Zeile zwei" \
  PublicKeyTest::test_a_control_character_is_refused failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  PublicKeyTest::test_a_control_character_is_refused passed

echo
echo "── PublicKeyTest: die RSA-Untergrenze fällt weg ──"
#
# `ssh-keygen -t rsa -b 1024` legt so einen Schlüssel anstandslos an, und
# OpenSSH nimmt ihn. Ohne die Grenze nähme ihn dieses Panel auch.
vorher_datei agent/src/Ssh/PublicKey.php
python3 - <<'PY2'
p = 'agent/src/Ssh/PublicKey.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        if ($bits < self::RSA_MINIMUM) {', '        if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/PublicKey.php "RSA-Untergrenze entfernt" &&
pruefe "ein RSA mit 1024 Bit kommt herein" \
  PublicKeyTest::test_dsa_and_short_rsa_are_refused failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  PublicKeyTest::test_dsa_and_short_rsa_are_refused passed

echo
echo "── SshdConfigTest: der Block hört auf, ohne aufzuhören ──"
#
# **Gemessen (docs/57 §6):** Eine nicht eingerückte Zeile hinter einem
# Match-Block gehört noch zu ihm, und sshd -t meldet rc=0. Ohne den
# Abschluss fällt die nächste Zeile, die der Betreiber an SEINE Datei
# hängt, in UNSEREN letzten Block.
vorher_datei agent/src/Ssh/SshdConfig.php
python3 - <<'PY2'
p = 'agent/src/Ssh/SshdConfig.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        $lines[] = self::TERMINATOR;', '        // der Abschluss faellt weg', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/SshdConfig.php "der Abschluss Match all fehlt" &&
pruefe "der Abschluss Match all fehlt" \
  SshdConfigTest::test_the_block_ends_with_a_terminator failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SshdConfigTest::test_the_block_ends_with_a_terminator passed

echo
echo "── SshdConfigTest: die Schlüsseldatei liegt im Chroot ──"
#
# Dort schreibt der Kunde — und die Fingerabdrücke im Panel wären eine
# Auskunft über die Hälfte der Wahrheit.
vorher_datei agent/src/Ssh/SshdConfig.php
python3 - <<'PY2'
p = 'agent/src/Ssh/SshdConfig.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        return self::KEYS.'/'.$user;", "        return '/var/www/vhosts/'.$user.'/.ssh/authorized_keys';", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/SshdConfig.php "die Schlüsseldatei liegt in Reichweite des Kunden" &&
pruefe "die Schlüsseldatei liegt in Reichweite des Kunden" \
  SshdConfigTest::test_the_key_file_is_out_of_the_customers_reach failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SshdConfigTest::test_the_key_file_is_out_of_the_customers_reach passed

echo
echo "── SshdConfigTest: der Name geht ungeprüft in die Datei ──"
#
# Gemessen (docs/57 §11): Ein Zeilenumbruch schiebt PermitRootLogin yes
# unter und ein ChrootDirectory / für einen Benutzer, den der Aufruf nicht
# nannte. sshd -t meldet dazu rc=0.
vorher_datei agent/src/Ssh/SshdConfig.php
python3 - <<'PY2'
p = 'agent/src/Ssh/SshdConfig.php'
s = open(p, encoding='utf-8').read()
s = s.replace("SubscriptionProvision::subscriptionName($access['name'] ?? null)", "(string) ($access['name'] ?? '')", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/SshdConfig.php "ein Zeilenumbruch im Namen macht zwei Blöcke" &&
pruefe "ein Zeilenumbruch im Namen macht zwei Blöcke" \
  SshdConfigTest::test_a_newline_in_a_name_never_becomes_a_second_block failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SshdConfigTest::test_a_newline_in_a_name_never_becomes_a_second_block passed

echo
echo "── ChainTest: der Eigentümer ist egal ──"
vorher_datei agent/src/Ssh/Chain.php
python3 - <<'PY2'
p = 'agent/src/Ssh/Chain.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        if ($stat['uid'] !== 0) {", '        if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/Chain.php "ein fremder Eigentümer fällt nicht auf" &&
pruefe "ein fremder Eigentümer fällt nicht auf" \
  ChainTest::test_a_foreign_owner_is_named failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ChainTest::test_a_foreign_owner_is_named passed

echo
echo "── ChainTest: das Schreibrecht der Gruppe ist egal ──"
vorher_datei agent/src/Ssh/Chain.php
python3 - <<'PY2'
p = 'agent/src/Ssh/Chain.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        if (($mode & 0o020) !== 0) {', '        if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/Chain.php "ein gruppenschreibbares Glied fällt nicht auf" &&
pruefe "ein gruppenschreibbares Glied fällt nicht auf" \
  ChainTest::test_a_writable_bit_is_enough_to_fail failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ChainTest::test_a_writable_bit_is_enough_to_fail passed

echo
echo "── ChainTest: die Kette fängt erst unterhalb von / an ──"
#
# Gemessen (docs/57 §9): Ein gruppenschreibbares / weist die Anmeldung ab,
# und der Server meldet dabei nichts über das Chroot.
vorher_datei agent/src/Ssh/Chain.php
python3 - <<'PY2'
p = 'agent/src/Ssh/Chain.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        $glieder = ['/'];", '        $glieder = [];', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/Chain.php "die Kette lässt / aus" &&
pruefe "die Kette lässt / aus" \
  ChainTest::test_the_chain_starts_at_the_root failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ChainTest::test_the_chain_starts_at_the_root passed

echo
echo "── TemplateSpacingTest: ein Zeilenumbruch als Leerzeichen ──"
#
# Der Fund aus dem Abnahmelauf des SFTP-Zugangs (docs/59, Befund 4). Vues
# Vorgabe `whitespace: condense` entfernt einen Textknoten zwischen zwei
# Elementen, wenn er nur aus Weissraum mit Zeilenumbruch besteht — auf der
# Seite stand daraufhin `zustande./etc/srvpanel/ssh` ohne Trennung.
vorher_datei resources/js/Pages/Subscriptions/Sftp.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Sftp.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("zustande.</b>{{ ' ' }}", "zustande.</b>", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Pages/Subscriptions/Sftp.vue "das Leerzeichen bleibt dem Umbruch überlassen" &&
pruefe "das Leerzeichen bleibt dem Umbruch überlassen" \
  TemplateSpacingTest::test_no_prose_relies_on_a_line_break_for_a_space failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  TemplateSpacingTest::test_no_prose_relies_on_a_line_break_for_a_space passed

echo
echo "── TemplateSpacingTest: die Voraussetzung zieht still um ──"
#
# Der Wächter fragt nur dort, wo der Behälter Fliesstext ist. Wird `.hint` zu
# einer Flexbox, ist sein Inhalt keiner mehr — und der Wächter prüfte ab da
# eine andere Anwendung, ohne es zu melden.
vorher_datei resources/css/app.css
python3 - <<'PY2'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace(".hint {\n  margin: 6px 0 0;", ".hint {\n  display: flex;\n  margin: 6px 0 0;", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/css/app.css "Fliesstext, der keiner mehr ist" &&
pruefe "Fliesstext, der keiner mehr ist" \
  TemplateSpacingTest::test_the_premise_of_this_guard_holds failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  TemplateSpacingTest::test_the_premise_of_this_guard_holds passed

echo
echo "── SshdConfigTest: nur PasswordAuthentication no im Block ──"
#
# Der Fund aus dem Abnahmelauf (docs/59, Befund 6). Diese Zeile war da, und der
# Betreiber bekam trotzdem eine Passwortabfrage: KbdInteractiveAuthentication
# blieb auf yes, und PAM fragt dahinter nach demselben Passwort. Gemessen gegen
# OpenSSH 9.6p1 — angeboten wurde publickey,keyboard-interactive.
vorher_datei agent/src/Ssh/SshdConfig.php
python3 - <<'PY2'
p = 'agent/src/Ssh/SshdConfig.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            '    AuthenticationMethods publickey',\n", '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/SshdConfig.php "eine zweite Tür bleibt offen" &&
pruefe "eine zweite Tür bleibt offen" \
  SshdConfigTest::test_only_a_public_key_gets_in failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SshdConfigTest::test_only_a_public_key_gets_in passed

echo
echo "── PublicKeyTest: der Fingerabdruck als unbekannter Typ ──"
#
# Der Fund aus dem Abnahmelauf (docs/59, Befund 7): Der Betreiber hat die
# Ausgabe von `ssh-keygen -lf` eingetragen. Der allgemeine Satz nannte
# daraufhin „256" als das, womit die Zeile anfängt — richtig und unbrauchbar.
vorher_datei agent/src/Ssh/PublicKey.php
python3 - <<'PY2'
p = 'agent/src/Ssh/PublicKey.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        if (ctype_digit($type) && str_contains($raw, 'SHA256:')) {",
              '        if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/PublicKey.php "der Fingerabdruck heisst unbekannter Typ" &&
pruefe "der Fingerabdruck heisst unbekannter Typ" \
  PublicKeyTest::test_a_fingerprint_is_named_as_such failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  PublicKeyTest::test_a_fingerprint_is_named_as_such passed

echo
echo "── ChainTest: zwei Gruende, aneinandergehaengt zu zwei Saetzen ──"
#
# Der Fund aus dem Abnahmelauf (docs/59, Befund 9): Fuer ein 0777 stand auf der
# Seite "ist fuer die Gruppe schreibbar und ist fuer alle schreibbar" — zweimal
# dasselbe Praedikat. Die Pruefung darueber war dabei gruen, weil "schreibbar"
# vorkam.
vorher_datei agent/src/Ssh/Chain.php
python3 - <<'PY2'
p = 'agent/src/Ssh/Chain.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            $wer[] = 'für die Gruppe';", "            $gruende[] = 'ist für die Gruppe schreibbar';", 1)
s = s.replace("            $wer[] = 'für alle';", "            $gruende[] = 'ist für alle schreibbar';", 1)
s = s.replace("            'reason' => implode(', ', $gruende),", "            'reason' => implode(' und ', $gruende),", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/Chain.php "zwei Gründe werden zwei Sätze" &&
pruefe "zwei Gründe werden zwei Sätze" \
  ChainTest::test_a_reason_reads_as_one_sentence failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ChainTest::test_a_reason_reads_as_one_sentence passed

echo
echo "── SftpCheckTest: die Kette haengt am Sollzustand ──"
#
# Der Fund aus Punkt 7 des Abnahmelaufs (docs/59, Befund 10): Der Betreiber
# setzte ChrootDirectory /var/www oberhalb des Bereichs, sshd -T sagte /var/www,
# und die Seite schrieb "Verzeichnis und Rechte in Ordnung" — wahr ueber die
# Wurzel des Abonnements, gelesen ueber das Verzeichnis, das gilt.
vorher_datei agent/src/Ops/SftpCheck.php
python3 - <<'PY2'
p = 'agent/src/Ops/SftpCheck.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        return $wirksam;', '        return $root;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/SftpCheck.php "geprüft wird der Sollzustand" &&
pruefe "geprüft wird der Sollzustand" \
  SftpCheckTest::test_an_override_is_the_directory_that_gets_judged failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SftpCheckTest::test_an_override_is_the_directory_that_gets_judged passed

echo
echo "── SftpCheckTest: eine Marke wird fuer einen Pfad gehalten ──"
#
# sshd -T gibt %h und %u unaufgeloest aus. Ein Chain::of('%h/sftp') meldet
# "gibt es nicht" — eine falsche Aussage statt einer fehlenden.
vorher_datei agent/src/Ops/SftpCheck.php
python3 - <<'PY2'
p = 'agent/src/Ops/SftpCheck.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        if (! str_starts_with($wirksam, '/') || str_contains($wirksam, '%')) {",
              '        if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/SftpCheck.php "eine Marke gilt als Pfad" &&
pruefe "eine Marke gilt als Pfad" \
  SftpCheckTest::test_a_token_is_not_a_path failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SftpCheckTest::test_a_token_is_not_a_path passed

echo
echo "── AgentErrorRoutingTest: ein Serverfehler macht ein Feld rot ──"
#
# Der Fund aus Phase B von Punkt 8 (docs/59, Befund 11): Bei kaputter
# sshd_config brach der Vorgang richtig ab, und die Meldung landete am
# Schlüsselfeld — rot, obwohl der Schlüssel einwandfrei war.
vorher_datei app/Http/Controllers/SftpController.php
python3 - <<'PY2'
p = 'app/Http/Controllers/SftpController.php'
s = open(p, encoding='utf-8').read()
s = s.replace('            if ($error->errorCode === AgentException::BAD_REQUEST) {',
              '            if (true) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Controllers/SftpController.php "jeder Agentenfehler geht ans Feld" &&
pruefe "jeder Agentenfehler geht ans Feld" \
  AgentErrorRoutingTest::test_only_a_rejected_input_becomes_a_field_error failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  AgentErrorRoutingTest::test_only_a_rejected_input_becomes_a_field_error passed

echo
echo "── SftpWriteOrderTest: die Schluesseldatei vor dem Block ──"
#
# Der Fund aus Phase D von Punkt 8 (docs/59, Befund 12): Bei kaputter
# sshd_config brach der Vorgang richtig ab — und die Schluesseldatei war da
# schon geloescht. Eine Transaktion rollt die Datenbank zurueck und nicht die
# Platte. Vorhergesagt aus dem Quelltext, dann auf cloudsrv24 gemessen.
vorher_datei app/Support/Files/Sftp.php
python3 - <<'PY2'
p = 'app/Support/Files/Sftp.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""            $note = self::spokenNote($this->sync());
            $this->write($subscription);

            return $note;""", """            $this->write($subscription);
            $note = self::spokenNote($this->sync());

            return $note;""", 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Files/Sftp.php "die Platte vor der Pruefung" &&
pruefe "die Platte vor der Pruefung" \
  SftpWriteOrderTest::test_the_block_goes_before_the_key_file failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SftpWriteOrderTest::test_the_block_goes_before_the_key_file passed

echo
echo "── FlashChannelTest: der Kanal fehlt ──"
#
# Der Fund aus Phase D (docs/59, Befund 13): SftpController schickte
# with('error', …), die Mittelschicht gab den Schluessel nicht weiter, und die
# Meldung war fort. Sieben Aufrufe aus vier Controllern, seit P4.
vorher_datei app/Http/Middleware/HandleInertiaRequests.php
python3 - <<'PY2'
p = 'app/Http/Middleware/HandleInertiaRequests.php'
s = open(p, encoding='utf-8').read()
s = s.replace("                'error' => fn () => $request->session()->get('error'),\n", '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Http/Middleware/HandleInertiaRequests.php "ein Schluessel ohne Traeger" &&
pruefe "ein Schluessel ohne Traeger" \
  FlashChannelTest::test_every_written_flash_key_is_carried failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  FlashChannelTest::test_every_written_flash_key_is_carried passed

echo
echo "── FlashChannelTest: getragen und nicht gelesen ──"
vorher_datei resources/js/Layouts/PanelLayout.vue
python3 - <<'PY2'
p = 'resources/js/Layouts/PanelLayout.vue'
s = open(p, encoding='utf-8').read()
s = s.replace("const fehler = computed(() => (page.props.flash as Record<string, string> | undefined)?.error)",
              'const fehler = computed(() => undefined)', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei resources/js/Layouts/PanelLayout.vue "ein Traeger ohne Leser" &&
pruefe "ein Traeger ohne Leser" \
  FlashChannelTest::test_every_carried_flash_key_has_a_reader failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  FlashChannelTest::test_every_carried_flash_key_has_a_reader passed

echo
echo "── SftpRuntimeDirTest: sshd -t ohne /run/sshd ──"
#
# Der Fund aus Punkt 9 (docs/59, Befund 16): Bei angehaltenem Dienst raeumt
# systemd /run/sshd weg, und sshd -t bricht mit rc=255 ab — an der Umgebung des
# Pruefers statt am Prueflings. Das Panel meldete "von sshd abgewiesen".
vorher_datei agent/src/Ops/SftpAccess.php
python3 - <<'PY2'
p = 'agent/src/Ops/SftpAccess.php'
s = open(p, encoding='utf-8').read()
s = s.replace('        self::ensureRuntime();\n\n', '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/SftpAccess.php "die Pruefung laeuft ohne ihre Umgebung" &&
pruefe "die Pruefung laeuft ohne ihre Umgebung" \
  SftpRuntimeDirTest::test_the_directory_is_ensured_before_the_check failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SftpRuntimeDirTest::test_the_directory_is_ensured_before_the_check passed

echo
echo "── SftpRuntimeDirTest: ein weltschreibbares /run/sshd bleibt ──"
#
# Gemessen: 0777 laesst sshd -t ebenfalls mit rc=255 scheitern, mit anderem
# Wortlaut.
vorher_datei agent/src/Ops/SftpAccess.php
python3 - <<'PY2'
p = 'agent/src/Ops/SftpAccess.php'
s = open(p, encoding='utf-8').read()
s = s.replace("        if (is_array($stat) && ($stat['mode'] & 0o022) !== 0) {", '        if (false) {', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/SftpAccess.php "die Rechte bleiben, wie sie sind" &&
pruefe "die Rechte bleiben, wie sie sind" \
  SftpRuntimeDirTest::test_a_writable_directory_is_corrected failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SftpRuntimeDirTest::test_a_writable_directory_is_corrected passed

echo
echo "── PublicKeyTest: der private Schluessel hinter der Steuerzeichenpruefung ──"
#
# Der Fund aus Punkt 11 (docs/59, Befund 18): Ein eingefuegter privater
# Schluessel hat immer Zeilenumbrueche, also fing ihn die Steuerzeichenpruefung
# ab — und der Satz, der genau seinen Fall benennt, war unerreichbar.
vorher_datei agent/src/Ssh/PublicKey.php
python3 - <<'PY2'
p = 'agent/src/Ssh/PublicKey.php'
s = open(p, encoding='utf-8').read()
s = s.replace("""        if (str_starts_with($raw, '-----BEGIN')) {
            throw AgentException::badRequest(self::whyNot('-----BEGIN', $raw));
        }
""", '', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/PublicKey.php "die engere Erkennung steht hinten" &&
pruefe "die engere Erkennung steht hinten" \
  PublicKeyTest::test_a_private_key_is_named_as_such failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  PublicKeyTest::test_a_private_key_is_named_as_such passed

echo
echo "── MessageMarkupTest: eine Meldung in Markdown ──"
#
# Der Fund aus dem zweiten Durchgang (docs/59, Befund 20): Auf der Seite standen
# die Sternchen und die Schraegstrichzeichen als Zeichen im Satz. Niemand
# uebersetzt sie, und das Panel soll sie auch nicht uebersetzen — eine Meldung,
# die HTML erzeugt, ist eine Meldung, in der Kundeneingaben stehen.
vorher_datei agent/src/Ssh/PublicKey.php
python3 - <<'PY2'
p = 'agent/src/Ssh/PublicKey.php'
s = open(p, encoding='utf-8').read()
s = s.replace('Das ist ein privater Schlüssel', 'Das ist ein **privater** Schlüssel', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ssh/PublicKey.php "eine Meldung in Markdown" &&
pruefe "eine Meldung in Markdown" \
  MessageMarkupTest::test_no_german_message_carries_markup failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  MessageMarkupTest::test_no_german_message_carries_markup passed

echo
echo "── SftpNoteTest: die Auskunft des Agenten wird weggeworfen ──"
#
# Der Fund aus Punkt 9 (docs/59, Befund 21): SftpAccess baut den Satz ueber den
# ruhenden Dienst, sync() gibt ihn zurueck, und add()/remove() warfen ihn weg.
# docs/58 Punkt 9 verlangt ihn als eine der vier Zeilen.
vorher_datei app/Support/Files/Sftp.php
python3 - <<'PY2'
p = 'app/Support/Files/Sftp.php'
s = open(p, encoding='utf-8').read()
s = s.replace('            $note = self::spokenNote($this->sync());',
              '            $this->sync();\n            $note = null;', 1)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Files/Sftp.php "die Auskunft wird weggeworfen" &&
pruefe "die Auskunft wird weggeworfen" \
  SftpNoteTest::test_the_answer_is_carried_from_the_agent_to_the_page failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  SftpNoteTest::test_the_answer_is_carried_from_the_agent_to_the_page passed

echo "── CronOccurrenceTest: Monatstag UND Wochentag statt ODER ──"
#
# crontab(5): Sind beide Felder gesetzt, gilt ein Tag, wenn *eines von beiden*
# passt. Das ist die einzige Stelle der Syntax, an der die Verknuepfung wechselt
# — eine Rechnung mit UND ist an elf Zwoelfteln aller Zeitplaene richtig und an
# diesem einen still falsch.
vorher_datei app/Support/Cron/Occurrence.php
python3 - <<'PY2'
p = 'app/Support/Cron/Occurrence.php'
s = open(p, encoding='utf-8').read()
alt = '            return $dom || $dow;'
assert s.count(alt) == 1, 'Zielzeile nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, '            return $dom && $dow;'))
PY2
griff_datei app/Support/Cron/Occurrence.php "Monatstag UND Wochentag" &&
pruefe "Monatstag UND Wochentag" \
  CronOccurrenceTest::test_day_of_month_and_weekday_are_joined_with_or failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  CronOccurrenceTest::test_day_of_month_and_weekday_are_joined_with_or passed

echo
echo "── CronOccurrenceTest: Sonntag als 7 wird nicht angeglichen ──"
#
# cron nimmt fuer Sonntag 0 und 7. Faellt die Angleichung weg, ist ein Zeitplan
# mit 7 nie faellig — und das sieht in der Liste aus wie "laeuft nicht mehr".
vorher_datei app/Support/Cron/Occurrence.php
python3 - <<'PY2'
p = 'app/Support/Cron/Occurrence.php'
s = open(p, encoding='utf-8').read()
alt = "        if (in_array(7, $dows, true)) {\n            $dows[] = 0;\n        }\n"
assert s.count(alt) == 1, 'Zielblock nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, ''))
PY2
griff_datei app/Support/Cron/Occurrence.php "Sonntag als 7" &&
pruefe "Sonntag als 7" CronOccurrenceTest failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CronOccurrenceTest passed

echo
echo "── ServerZoneSourceTest: eine zweite Stelle liest /etc/localtime ──"
#
# Es gibt drei Zeitzonen in diesem Panel — UTC beim Speichern, die Anzeigezone
# in Clock, die Zone der Maschine in ServerZone. Eine vierte Antwort ist die
# Verwechslung, und sie faellt in diesem Container nie auf: Hier sagen
# /etc/localtime und die Vorgabe von PHP beide UTC.
vorher_datei app/Support/Cron/Cron.php
python3 - <<'PY2'
p = 'app/Support/Cron/Cron.php'
s = open(p, encoding='utf-8').read()
alt = '        private readonly Client $agent,'
assert s.count(alt) == 1, 'Zielzeile nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, alt + ' // liest /etc/localtime selbst'))
PY2
griff_datei app/Support/Cron/Cron.php "zweite Stelle liest die Zone" &&
pruefe "zweite Stelle liest die Zone" \
  ServerZoneSourceTest::test_only_one_class_reads_the_machine_timezone failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ServerZoneSourceTest::test_only_one_class_reads_the_machine_timezone passed

echo
echo "── ServerZoneSourceTest: der Zeitplan rechnet in der Anzeigezone ──"
#
# Der Fehler aus docs/60 §11, eine Ebene hoeher: Wer die fuenf Felder in der
# Anzeigezone deutet, zeigt eine Zeile und findet sie nicht.
vorher_datei app/Support/Cron/Occurrence.php
python3 - <<'PY2'
p = 'app/Support/Cron/Occurrence.php'
s = open(p, encoding='utf-8').read()
alt = '$zone = ServerZone::current();'
assert s.count(alt) == 1, 'Zielzeile nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, '$zone = Clock::timezone();'))
PY2
griff_datei app/Support/Cron/Occurrence.php "Zeitplan in der Anzeigezone" &&
pruefe "Zeitplan in der Anzeigezone" \
  ServerZoneSourceTest::test_the_display_timezone_does_not_drive_the_schedule failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ServerZoneSourceTest::test_the_display_timezone_does_not_drive_the_schedule passed

echo "── CronOutputEncodingTest: die Ausgabe geht ungeprueft in die Antwort ──"
#
# Connection::send() kodiert ohne JSON_INVALID_UTF8_SUBSTITUTE. Gemessen am
# 17. August 2026: Ein einziges ungueltiges Byte laesst json_encode false
# zurueckgeben — dann ist nicht das Feld unlesbar, sondern die ganze Antwort.
# Die Ausgabe eines Cronjobs sind beliebige Bytes; ohne die Bereinigung ist das
# kein Randfall, sondern der Normalfall in Wartestellung.
vorher_datei agent/src/Ops/CronRuns.php
python3 - <<'PY2'
p = 'agent/src/Ops/CronRuns.php'
s = open(p, encoding='utf-8').read()
alt = """        if (mb_check_encoding($bytes, 'UTF-8')) {
            return [$bytes, false];
        }

        return [mb_convert_encoding($bytes, 'UTF-8', 'UTF-8'), true];"""
assert s.count(alt) == 1, 'Zielblock nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, '        return [$bytes, false];'))
PY2
griff_datei agent/src/Ops/CronRuns.php "Ausgabe ungeprueft in die Antwort" &&
pruefe "Ausgabe ungeprueft in die Antwort" CronOutputEncodingTest failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CronOutputEncodingTest passed

echo "── SubscriptionCleanupTest: die Cron-Datei bleibt beim Rueckbau liegen ──"
#
# docs/35: Wer etwas anlegt, das auf der Platte bleibt, baut den Weg zurueck mit.
# Die Zeitsteuerung hinterlaesst drei Dinge ausserhalb der Abo-Wurzel — Datei,
# Befehle, Ablage —, und keines davon nimmt das Loeschen des Verzeichnisses mit.
# Bliebe die Datei liegen, schriebe cron fuer immer "Syntax error" ins
# Protokoll: Den Benutzer, den sie nennt, gibt es dann nicht mehr.
vorher_datei agent/src/Ops/SubscriptionRemove.php
python3 - <<'PY2'
p = 'agent/src/Ops/SubscriptionRemove.php'
s = open(p, encoding='utf-8').read()
alt = """        $cronFile = $this->cronDir.'/'.CronFile::name($user);

        if (is_file($cronFile) && @unlink($cronFile)) {
            $entfernt['cron'][] = $cronFile;
        }
"""
assert s.count(alt) == 1, 'Zielblock nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, ''))
PY2
griff_datei agent/src/Ops/SubscriptionRemove.php "Cron-Datei bleibt liegen" &&
pruefe "Cron-Datei bleibt liegen" SubscriptionCleanupTest failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" SubscriptionCleanupTest passed

echo "── CronScheduleFormTest: die Schnellwahl sagt etwas anderes, als sie tut ──"
#
# Auf dem Knopf steht der Satz, und derselbe Knopf stellt die fuenf Felder ein.
# Das ist eine Behauptung ueber zwei Dinge, die auseinanderlaufen koennen —
# dieser Waechter haelt die Beschriftung gegen Spoken. Beim ersten Lauf hat er
# drei echte Abweichungen gefunden.
vorher_datei resources/js/Pages/Subscriptions/Cron.vue
python3 - <<'PY2'
p = 'resources/js/Pages/Subscriptions/Cron.vue'
s = open(p, encoding='utf-8').read()
alt = "{ name: 'jeden Tag um 03:15',"
assert s.count(alt) == 1, 'Zielzeile nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, "{ name: 'jeden Tag um 3 Uhr 15',"))
PY2
griff_datei resources/js/Pages/Subscriptions/Cron.vue "Schnellwahl sagt etwas anderes" &&
pruefe "Schnellwahl sagt etwas anderes" \
  CronScheduleFormTest::test_every_quick_choice_says_what_it_sets failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  CronScheduleFormTest::test_every_quick_choice_says_what_it_sets passed

echo
echo "── CronPageReachTest: der Menuepunkt verschwindet ──"
#
# Er hat beim Dateimanager (docs/55 Befund 8) und bei SFTP (docs/59 Befund 19)
# je einen Abnahmelauf gekostet, und beide Male fand ihn kein einziger Waechter.
vorher_datei resources/js/Layouts/PanelLayout.vue
python3 - <<'PY2'
p = 'resources/js/Layouts/PanelLayout.vue'
s = open(p, encoding='utf-8').read()
alt = "              { name: 'Cronjobs', href: '/cron', icon: 'cron' },\n"
assert s.count(alt) == 1, 'Zielzeile nicht eindeutig — der Bruch waere blind'
open(p, 'w', encoding='utf-8').write(s.replace(alt, ''))
PY2
griff_datei resources/js/Layouts/PanelLayout.vue "Menuepunkt verschwindet" &&
pruefe "Menuepunkt verschwindet" CronPageReachTest failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" CronPageReachTest passed

echo
echo "── CronPageReachTest: eine Route verliert ihr can: ──"
vorher_datei routes/web.php
python3 - <<'PY2'
p = 'routes/web.php'
s = open(p, encoding='utf-8').read()
alt = """    Route::delete('/subscriptions/{subscription}/cron/{job}', [CronController::class, 'destroy'])
        ->middleware('can:manageCron,subscription')
        ->name('cron.destroy');"""
assert s.count(alt) == 1, 'Zielblock nicht eindeutig — der Bruch waere blind'
neu = """    Route::delete('/subscriptions/{subscription}/cron/{job}', [CronController::class, 'destroy'])
        ->name('cron.destroy');"""
open(p, 'w', encoding='utf-8').write(s.replace(alt, neu))
PY2
griff_datei routes/web.php "Route ohne can:" &&
pruefe "Route ohne can:" CronPageReachTest::test_every_cron_route_is_guarded failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  CronPageReachTest::test_every_cron_route_is_guarded passed

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
