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
# Das Skript ändert Dateien unter resources/ und stellt sie wieder her. Es
# verweigert den Start, wenn dort schon etwas geändert ist, und räumt auch nach
# einem Abbruch auf.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

if ! git diff --quiet -- resources/; then
  echo "resources/ hat ungesicherte Änderungen. Erst committen oder verwerfen —" >&2
  echo "dieses Skript ändert dort Dateien und stellt sie über git wieder her." >&2
  exit 1
fi

wiederherstellen() { git checkout -- resources/ 2>/dev/null; }
trap wiederherstellen EXIT INT TERM

fehler=0

# name | filter | erwartetes Ergebnis
pruefe() {
  local name="$1" filter="$2" erwartung="$3" ergebnis roh

  # Erst laufen lassen, dann auswerten. Ein rot laufender Test ist hier der
  # Normalfall, und mit `pipefail` reisst sein Rückgabewert die ganze Pipeline
  # hoch — die Auswertung käme dann nie zum Zug und jede Prüfung meldete
  # „kein Ergebnis". Genau so ist es beim ersten Lauf passiert.
  roh=$(./vendor/bin/phpunit --filter "$filter" 2>/dev/null) || true

  ergebnis=$(printf '%s' "$roh" \
    | python3 -c "import json,sys; print(json.load(sys.stdin)['result'])" 2>/dev/null) \
    || ergebnis="kein Ergebnis"

  if [ "$ergebnis" = "$erwartung" ]; then
    printf '  ok     %-56s %s\n' "$name" "$ergebnis"
  else
    printf '  FEHLT  %-56s %s (erwartet: %s)\n' "$name" "$ergebnis" "$erwartung"
    fehler=$((fehler + 1))
  fi
}

echo "── ClassReachTest: eine Klasse, die es nicht gibt ──"
sed -i '0,/<template>/s//<template>\n  <span class="diese-klasse-gibt-es-nicht" \/>/' \
  resources/js/Pages/Customers/Index.vue
pruefe "erfundene Klasse im Template" ClassReachTest failed
wiederherstellen

echo
echo "── TableStyleTest: Dichtemarke fehlt in einer Stufe ──"
python3 - <<'PY'
p = 'resources/css/app.css'
s = open(p, encoding='utf-8').read()
s = s.replace(":root[data-density='customer'] {\n  --row-height: 42px;", ":root[data-density='customer'] {")
open(p, 'w', encoding='utf-8').write(s)
PY
pruefe "--row-height fehlt in der Kundendichte" \
  TableStyleTest::test_the_density_token_exists_in_both_steps failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  TableStyleTest::test_the_density_token_exists_in_both_steps passed

echo
echo "── TableStyleTest: Zeilenhöhe als Literal statt aus der Marke ──"
printf '\ntd { height: var(--row-height); }\n' >> resources/css/app.css
pruefe "Marke gesetzt -> grün" \
  TableStyleTest::test_the_row_height_comes_from_the_density_token passed
sed -i 's/^td { height: var(--row-height); }$/td { height: 34px; }/' resources/css/app.css
pruefe "Literal statt Marke -> rot" \
  TableStyleTest::test_the_row_height_comes_from_the_density_token failed
wiederherstellen

echo
echo "── ButtonStyleTest: Knopfrand aus der Haarlinie ──"
sed -i 's/  border: 1px solid var(--button-line);/  border: 1px solid var(--line);/' resources/css/app.css
pruefe "unsichtbarer Rand am Knopf" ButtonStyleTest::test_every_control_border_stands_out failed
wiederherstellen

echo
echo "── ButtonStyleTest: Beschriftung auf dem Knopf unlesbar ──"
sed -i 's/^  --text: #b9c7d4;/  --text: #1e2833;/' resources/css/app.css
pruefe "--text auf der Knopffläche unter 4,5:1" \
  ButtonStyleTest::test_the_label_on_a_button_stays_readable failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" \
  ButtonStyleTest::test_the_label_on_a_button_stays_readable passed

echo
echo "── DesignTokensTest: eine Stufe, die niemand benutzt ──"
sed -i 's/^  --text-metric: 22px;/  --text-metric: 22px;\n  --text-riesig: 99px;/' resources/css/app.css
pruefe "Marke ohne Nutzer" DesignTokensTest::test_every_step_of_the_scale_is_used failed
wiederherstellen
pruefe "  … zurückgesetzt wieder grün" DesignTokensTest::test_every_step_of_the_scale_is_used passed

echo
echo "── MobileLayoutTest: Feld in app.css mit zoomender Größe ──"
printf '\ninput { font-size: var(--text-small); }\n' >> resources/css/app.css
pruefe "Feldregel unter 16px" MobileLayoutTest::test_input_fields_use_the_zoom_safe_size failed
sed -i 's/^input { font-size: var(--text-small); }$/input { font-size: var(--text-input); }/' resources/css/app.css
pruefe "  … mit --text-input wieder grün" MobileLayoutTest::test_input_fields_use_the_zoom_safe_size passed
wiederherstellen

echo
if [ "$fehler" -eq 0 ]; then
  echo "Alle Wächter beissen."
else
  echo "$fehler Prüfung(en) ohne Biss — diese Wächter halten ihre Regel nicht." >&2
fi

exit "$fehler"
