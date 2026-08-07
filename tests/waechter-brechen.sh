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
# **`git checkout` stellt nur wieder her, was git kennt.** Ein Wächter für Code,
# der noch nicht eingecheckt ist, wird hier nicht gebrochen, sondern gelöscht.
# Deshalb der Abbruch oben — und deshalb kommt ein neuer Bruch erst nach dem
# Commit dazu, den er prüft.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

if ! git diff --quiet -- resources/ app/ agent/ packaging/ .github/; then
  echo "resources/, app/, agent/, packaging/ oder .github/ hat ungesicherte Änderungen. Erst committen" >&2
  echo "oder verwerfen — dieses Skript ändert dort Dateien und stellt sie über" >&2
  echo "git wieder her." >&2
  exit 1
fi

wiederherstellen() { git checkout -- resources/ app/ agent/ packaging/ .github/ 2>/dev/null; }
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
vorher_datei app/Support/Tls/CertificateLifecycle.php
python3 - <<'PY'
p = 'app/Support/Tls/CertificateLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    'if ($domain->certificate_id !== null || ! $this->settings->configured()) {',
    'if (! $this->settings->configured()) {',
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
vorher_datei app/Support/Tls/CertificateLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'renew_after' => CertificateRenewal::due($notAfter),", "            'renew_after' => null,")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateLifecycle.php "Zertifikat ohne Erneuerungstermin" &&
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
echo "── PackagingTest: der Wrapper kennt das neue Kommando nicht ──"
#
# Wer `srvpanel vhost` tippt, bekommt sonst „Command not defined" — und das
# postinstall-Skript ebenfalls, still und mit Rückgabewert 1.
vorher_datei packaging/bin/srvpanel
python3 - <<'PY2'
p = 'packaging/bin/srvpanel'
s = open(p, encoding='utf-8').read()
s = s.replace('|tls|vhost|', '|tls|')
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei packaging/bin/srvpanel "Kommando fehlt im Wrapper" &&
pruefe "Kommando fehlt im Wrapper" \
  PackagingTest::test_the_wrapper_knows_every_command_of_the_panel failed
wiederherstellen

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
vorher_datei app/Support/Tls/CertificateLifecycle.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateLifecycle.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return $certificate instanceof Certificate && $certificate->coversAll($domain->serverNames());",
    "        return $certificate instanceof Certificate;",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei app/Support/Tls/CertificateLifecycle.php "Verweis statt Deckung" &&
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
vorher_datei agent/src/Acme/Dns/Packet.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/Packet.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "            if (($marker & 0xC0) === 0xC0) {",
    "            if (false) {",
)
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/Packet.php "Namenszeiger nicht erkannt" &&
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
vorher_datei agent/src/Acme/Dns/XmlRpc.php
python3 - <<'PY2'
p = 'agent/src/Acme/Dns/XmlRpc.php'
s = open(p, encoding='utf-8').read()
s = s.replace("LIBXML_NONET | LIBXML_NOCDATA", "LIBXML_NOENT")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Acme/Dns/XmlRpc.php "Parser löst Entitäten auf" &&
pruefe "Parser löst Entitäten auf" \
  XmlRpcTest::test_an_external_entity_fetches_nothing failed
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
if [ "$fehler" -eq 0 ]; then
  echo "Alle Wächter beissen."
else
  echo "$fehler Prüfung(en) ohne Biss — diese Wächter halten ihre Regel nicht." >&2
fi

exit "$fehler"
