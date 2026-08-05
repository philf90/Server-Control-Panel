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

if ! git diff --quiet -- resources/ app/; then
  echo "resources/ oder app/ hat ungesicherte Änderungen. Erst committen oder" >&2
  echo "verwerfen — dieses Skript ändert dort Dateien und stellt sie über git" >&2
  echo "wieder her." >&2
  exit 1
fi

wiederherstellen() { git checkout -- resources/ app/ 2>/dev/null; }
trap wiederherstellen EXIT INT TERM

fehler=0

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

griff() {
  local name="$1"

  if cmp -s resources/css/app.css /tmp/waechter-vorher.css; then
    printf '  FEHLT  %-56s Eingriff hat nichts geändert\n' "$name"
    fehler=$((fehler + 1))

    return 1
  fi

  return 0
}

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
git checkout -- app/ 2>/dev/null
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
git checkout -- app/ 2>/dev/null
pruefe "  … zurückgesetzt wieder grün" RedirectTargetTest passed

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
git checkout -- app/ 2>/dev/null
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
git checkout -- app/ 2>/dev/null
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
if [ "$fehler" -eq 0 ]; then
  echo "Alle Wächter beissen."
else
  echo "$fehler Prüfung(en) ohne Biss — diese Wächter halten ihre Regel nicht." >&2
fi

exit "$fehler"
