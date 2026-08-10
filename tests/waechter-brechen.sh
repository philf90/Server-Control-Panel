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

if ! git diff --quiet -- resources/ app/ agent/ packaging/ .github/ database/ routes/ docs/ config/; then
  echo "resources/, app/, agent/, packaging/, .github/, database/, routes/, docs/ oder config/ hat ungesicherte" >&2
  echo "Änderungen. Erst committen" >&2
  echo "oder verwerfen — dieses Skript ändert dort Dateien und stellt sie über" >&2
  echo "git wieder her." >&2
  exit 1
fi

wiederherstellen() { git checkout -- resources/ app/ agent/ packaging/ .github/ database/ routes/ docs/ config/ 2>/dev/null; }
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
vorher_datei app/Support/Tls/CertificateChoice.php
python3 - <<'PY2'
p = 'app/Support/Tls/CertificateChoice.php'
s = open(p, encoding='utf-8').read()
s = s.replace(
    "        return $certificate->coversAll($names);",
    "        return true;",
)
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
s = s.replace("                    'started_at' => $operation->started_at?->toDateTimeString(),\n", '')
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
echo "── WordChoiceTest: „Noch" an einem fertigen Vorgang ──"
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
vorher_datei docs/38-postgresql.md
python3 - <<'PY2'
p = 'docs/38-postgresql.md'
s = open(p, encoding='utf-8').read()
s = s.replace('im Zuschnitt von `docs/36 §12`', 'im Zuschnitt von `docs/39 §12`')
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
echo "── PgGrantTest: die Freigabe vergisst die Standardrechte ──"
#
# ALTER DEFAULT PRIVILEGES gibt es in MariaDB nicht: Dort gilt ein Schemarecht
# fuer alles, was im Schema entsteht. In PostgreSQL gehoert jede Tabelle dem,
# der sie angelegt hat — ohne diese Zeile saehe ein zweiter Zugang desselben
# Abonnements die Tabellen des ersten nicht.
vorher_datei agent/src/Ops/PgRoleGrant.php
python3 - <<'PY2'
p = 'agent/src/Ops/PgRoleGrant.php'
s = open(p, encoding='utf-8').read()
s = s.replace("            'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO '.$name,\n", "")
open(p, 'w', encoding='utf-8').write(s)
PY2
griff_datei agent/src/Ops/PgRoleGrant.php "Freigabe ohne Standardrechte" &&
pruefe "Freigabe ohne Standardrechte" \
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
git checkout -- tests/Feature/RemovalPathTest.php
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
s = s.replace("        DatabaseEngine $engine,
    ): array {",
              "        DatabaseEngine $engine = DatabaseEngine::MariaDb,
    ): array {")
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
if [ "$fehler" -eq 0 ]; then
  echo "Alle Wächter beissen."
else
  echo "$fehler Prüfung(en) ohne Biss — diese Wächter halten ihre Regel nicht." >&2
fi

exit "$fehler"
