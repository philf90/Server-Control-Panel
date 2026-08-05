# SrvPanel — für Claude

Ein Hosting-Panel in der Art von Plesk: Laravel 13, Inertia, Vue 3,
AGPL-3.0-only. Zielplattformen sind Debian 12/13 und Ubuntu 22.04/24.04.

**Der Plan ist `docs/20-hostingpanel-neuplan.md`.** Er ist die Quelle für
Architektur (§4), Rechtemodell (§6), Gestaltung (§7.2) und die Ausbaustufen
(§9). Wo dieses Dokument und der Plan sich widersprechen, gilt der Plan.

Die Oberfläche folgt seit August 2026 dem Gestaltungssystem **„Kontor"**
(Plan §7.2) — hell entworfen, keine Karten, Monospace nur für Kennungen.

Stand: **P0 bis P3 abgenommen**. Der Abnahmelauf `srvpanel acceptance-web` ist
auf dem Zielserver aus `0.3.0~rc.5` durchgelaufen: sechs Domains, zwei
PHP-Versionen, zwei Systembenutzer, kein Zugriff über die Grenze. Als Nächstes
**P4 (TLS)**.

Ausgeliefert wird `v0.3.1-rc.3` — der Optik-Rework. **Sein Abnahmelauf ist am
5. August auf dem Zielserver durchgelaufen, vollständig grün** (`docs/33`):
beide Abnahmekommandos, die Schwellen der Verlaufskacheln unter echter Last,
Übersicht und Zertifikatsseite mit laufenden Diensten, Sitzung auf dem Telefon,
Kundensicht, Blättern. Er kam bewusst **vor** P4, weil P4 genau die Stellen
anfasst, an denen `rc.3` nie unter echten Bedingungen gelaufen war — ein
Fehlschlag ab jetzt hat nur noch eine mögliche Ursache.

---

## Die eine Gewohnheit, die dieses Projekt trägt

**Für jede Regel gibt es einen Wächter, und der Wächter wird gegengeprüft.**

Der Fehler, der hier immer wiederkehrt, ist derselbe: *eine Zeichenkette, die
auf etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug
prüft.* Eine Policy ohne Route. Ein Kommando, das im Startskript fehlt. Ein
Verzeichnisname, der umbenannt wurde. Ein Zertifikat mit dem falschen Namen.
Ein Favicon mit null Byte. Er ist mindestens sechsmal aufgetreten und jedes Mal
teuer gewesen, weil nichts ihn meldet.

Deshalb: Wer eine Regel aufstellt, baut den Test dazu — und **bricht die Regel
danach absichtlich, um zu sehen, dass der Test zubeißt.** Ein Wächter, der nie
rot war, ist kein Wächter.

Vorhandene Wächter, an denen man sich orientieren kann:
`RouteAuthorizationTest`, `PolicyReachTest`, `ButtonStyleTest`, `IconTest`,
`ThemeTest`, `InertiaPagesTest`, `PackagingTest`, `WordChoiceTest`,
`MobileLayoutTest`, `DesignTokensTest` — aus P3
`AgentOperationReachTest` (jeder Operationsname zeigt auf eine Operation, die
es gibt), `LifecycleReachTest`, `AnchoredPatternTest`, `PhpVersionCatalogTest`,
`DirectiveAllowlistTest`, `PhpIsolationTest` — und aus dem Optik-Rework
`ClassReachTest` (jede Klasse in einem Template zeigt auf eine Regel, die es
gibt), `TableStyleTest`, `ClassNameTest` (jeder Klassenname ist englisch, und
jede Regel in app.css wird von einem Template erreicht) und `PaginationTest`
(wer paginiert, lässt auch blättern) — dazu `RedirectTargetTest` (wer
weiterleitet, nennt das Ziel; `back()` kennt es hier nicht) und
`PairedSeriesTest` (zwei Kurven in einem Feld teilen sich die Achse) und
`HostnameSourceTest` (nur `Names` fragt den Kernel nach dem Rechnernamen) und
`AbilityReachTest` (ein Knopf, den der Betrachter nicht drücken darf, wird nicht
gezeigt), `NavIconTest` (jeder Menüpunkt trägt ein Zeichen, und jedes Zeichen ist
gezeichnet) und `SparklineShapeTest` (in einem ungleich gezogenen Feld wird
nichts Rundes in Nutzerkoordinaten gezeichnet). Der Bruch selbst steht als
`tests/waechter-brechen.sh` im Repo: Er bricht jede Regel der Reihe nach und
prüft, dass ihr Wächter zubeisst.

**Die Falle, in die dieses Vorgehen selbst dreimal gelaufen ist.** Ein Wächter
zählt seine Treffer, damit er merkt, wenn sein Ausdruck ins Leere läuft — und
zählt sie dort, wo die Regel gerade eingehalten wird. Zieht die Regel um, steht
der Zähler auf null, und der Wächter meldet Rot für genau die Ordnung, die er
durchsetzen soll: *Ein Wächter, der beim Aufräumen zubeisst, wird beim
Aufräumen abgeschaltet.* Passiert bei `MobileLayoutTest`, bei
`DesignTokensTest::test_every_step_of_the_scale_is_used` und bei drei Tests des
Optik-Reworks. Die Untergrenze zählt seitdem überall mit, wo die Regel stehen
*darf*; der Befund kommt weiter nur von dort, wo sie stehen *soll*.

**Drei Funde aus P3, die keiner Regel widersprachen, sondern eine fehlende
zeigten:** `$` passt in PCRE auch vor einem abschliessenden Zeilenumbruch (neun
Muster betroffen, vier davon aus P0–P2); zwei fertig gebaute Agent-Operationen
wurden von nichts aufgerufen; eine Knopfklasse zeigte auf eine CSS-Regel, die
es nicht gibt. Jeder Fund hat seinen Wächter bekommen.

**Und: im Browser nachsehen, nicht nur bauen.** Drei Fehler dieser Woche waren
grün getestet und trotzdem falsch — ein Knopfrand mit 1,04:1 Kontrast, ein
Umschalter, der sichtbar nichts tat, eine doppelte Erfolgsmeldung. Bei allem
Sichtbaren gehört ein Screenshot dazu, in beiden Themes und bei 390 px.

---

## Architektur — die drei Grenzen

**1. Der Agent ist die einzige Stelle mit Systemrechten.** `agent/` ist ein
framework- und abhängigkeitsfreies PHP-CLI hinter einem Unix-Socket; die
Anwendung schickt typisierte Operationen, niemals Text, der zu einer
Kommandozeile oder Konfigurationsdatei wird. Programme stehen auf einer
Positivliste mit absoluten Pfaden. **Nichts Privilegiertes gehört in
`app/`** — auch nicht „nur kurz". Siehe Plan §4.1 und §4.2.

**2. Zustände folgen dem Agenten, nicht dem Klick.** Ein Vorgang ändert den
Zustand erst, *nachdem* der Agent geantwortet hat (`Lifecycle::afterSuccess()`
aus `RunAgentOperation`). `Operation.type` geht an den Agenten,
`Operation.task` steuert den Lebenslauf.

**3. Die Mandantenklammer verweigert im Grundzustand alles.**
`app/Support/Tenancy/Tenancy.php` klammert Abfragen auf `whereRaw('0 = 1')`,
solange niemand etwas anderes sagt. `withoutRestriction()` ist die
ausdrückliche Ausnahme und will begründet sein; Admins sind über
`forAccount()` unbeschränkt. **Autorisierung sitzt an der Aktion**, nicht im
Menü — jede Route trägt `can:` oder steht mit Begründung in
`app/Support/Authorization/RouteGuard.php`.

Das ist die Regel fürs **Durchsetzen** und war nie eine Erlaubnis, jedem alles
anzubieten. Die Kehrseite: **Wer eine Aktion zeigt, fragt vorher dieselbe
Policy, die sie später abweist.** Die Antwort kommt als `can`-Ablage im
Inertia-Payload — nicht als `v-if` auf den Kontotyp, denn das wäre eine zweite
Fassung der Policy, und die zweite Fassung ist die, die veraltet.
`AbilityReachTest` prüft beide Richtungen.

Weiteres, das man wissen muss: Weiche Löschungen verbrauchen Bezeichner
(Kundennummern, `p1000`-Systembenutzer ↔ UID-Wiederverwendung).
Agent-Klassen sind aus der Anwendung autoladbar (`SrvPanel\Agent\` →
`agent/src/`), das Panel darf also `Names::fqdn()` direkt fragen.

---

## Sprache und Gestaltung

**`docs/19-sprache-der-oberflaeche.md` ist bindend** und wird von
`WordChoiceTest` geprüft. Kurz:

- **Kommentare, Dokumentation und alle Texte der Oberfläche: deutsch.
  Bezeichner: englisch** (§4a) — das schliesst CSS-Klassen, Datenattribute,
  Komponentennamen und ihre Eigenschaften ein und wird von `ClassNameTest`
  gegen eine Wortliste geprüft. Eine echte Schnittstelle, die eine Migration
  kosten würde, bleibt wie sie ist.
- Keine Emoji in der Oberfläche (§3a) — sie sehen auf jedem System anders aus
  und nehmen keine Textfarbe an.
- Kommentare erklären **warum**, nicht was. Der wertvollste Kommentar hält
  fest, was schiefging und weshalb es jetzt anders ist.

**Jede Farbe kommt aus `resources/css/app.css`** (Plan §7.2). Ein Hexwert in
einer Komponente ist ein Fehler und keine Ausnahme; die CI prüft das. Beide
Themes entstehen zusammen, nie eines nachträglich — **hell ist die
Ausgangsfassung**, seit „Kontor" das dunkle „Leitstand" abgelöst hat. Und die
Form jedes Bausteins steht ebenfalls dort: Tabelle, Feld, Marke, Balken,
Meldung, Schalter. Eine Komponente, die ihr eigenes `input` oder `table`
gestaltet, ist derselbe Fehler wie ein Hexwert. Kontrast wird gerechnet,
nicht geschätzt: 4,5:1 für Text, **3:1 für die Grenze eines Bedienelements**
(WCAG 1.4.11). Das Aussehen eines Knopfes steht ausschliesslich in `app.css`
— `ButtonStyleTest` besteht darauf.

Weitere Dokumente: `21` Signaturschlüssel · `22` Passwörter · `23` Pläne und
Kontingente · `24` mobile Ansicht · `25` Mailversand · `26` Abonnements ·
`27` Zertifikat · `28` Web und PHP · **`32` Übergabe an P4** — was für TLS
schon dasteht, was fehlt, und die Falle, die dabei aussperrt — und **`33` der
Abnahmelauf für 0.3.1**, der davor kommt. Die Entwürfe zum Gestaltungssystem stehen
unter `docs/entwuerfe/`: `20` die Wahl von 2026 („Leitstand"), `29` der erste
Rework-Plan, `30` die zwei neuen Richtungen, `31` das bediente Muster zu
„Kontor".

---

## Befehle

```bash
composer pruefe        # Pint --test, PHPStan, Tests — der volle Durchgang
./vendor/bin/pint      # formatieren (vor jedem Commit)
./vendor/bin/phpunit   # nur die Tests
npm run types          # vue-tsc --noEmit
npm run build          # Vite
php artisan migrate
```

Auf dem Zielserver:
`srvpanel setup|update|metrics|usage|tls|vhost|acceptance|acceptance-web|admin`.

---

## Diese Umgebung

Der Container ist **nicht** der Zielserver. Was hier fehlt, muss man beim
Testen berücksichtigen:

- **kein nginx, kein PHP-FPM, kein Agent, kein systemd.** Operationen laufen
  gegen Attrappen. Zwei Fehler sind nur aufgefallen, weil die CI nginx *hat*
  und dieser Container nicht — Tests, die Systemzustand annehmen, gehören
  abgesichert. Vorlagen werden deshalb **als Text** geprüft
  (`SiteTemplateTest`, `PhpIsolationTest`): Der Standardschutz ist eine
  Eigenschaft der erzeugten Zeichenkette.
- **Die Ratenbegrenzung greift beim Aufnehmen von Screenshots.** Drei
  Anmeldungen hintereinander sperren die Adresse (§6.4) — eine Anmeldung für
  alle Aufnahmen, dann `emulateMedia` und `setViewportSize` umschalten. Und
  Inertia schickt über XHR: `networkidle` kommt zurück, bevor die Antwort da
  ist; gewartet wird auf die Adresse.
- **PHPStan ist hier nicht lauffähig — und zwar, weil er nicht installiert
  ist.** `vendor/bin/phpstan` gibt es schlicht nicht; der Aufruf endet mit
  Rückgabewert 127. Nachinstallieren geht auch nicht: `composer install`
  scheitert an „Could not authenticate against github.com", der Proxy dieses
  Containers lässt die Paketquelle nicht durch. Er läuft in der CI;
  `composer pruefe` schlägt deshalb lokal fehl. Einzeln `pint` und
  `php artisan test` aufrufen.

  **Und manchmal geht auch das nicht.** In der Sitzung vom 5. August gab es
  `vendor/` überhaupt nicht — kein Pint, kein PHPUnit, `php artisan` bricht
  schon beim `require` des Autoloaders ab. Derselbe Proxy, dieselbe Meldung.
  Wer hier ankommt, sieht als Erstes nach, ob `vendor/` da ist: Ist es das
  nicht, prüft ausschliesslich die CI, und **jede** Änderung an `app/`,
  `agent/` oder `tests/` kostet eine Runde — nicht nur die, die PHPStan
  beträfe.

  **Für `agent/` gibt es einen Ausweg, und er hat in P4 eine Runde gespart.**
  `agent/src/autoload.php` ist framework- und abhängigkeitsfrei; Code unterhalb
  von `agent/` lässt sich damit aus einem Wegwerfskript im Scratchpad fahren,
  ganz ohne PHPUnit — `require agent/src/autoload.php`, Testdoppel von Hand
  dazuladen, Behauptungen als `if`. Das ersetzt den Wächter nicht und gehört
  nicht ins Repo (zwei Fassungen desselben Tests, und die zweite veraltet),
  aber es beantwortet vor dem Commit die Frage, ob der Code überhaupt läuft.

  **Was das für die Arbeit heisst:** Undefinierte Variablen, fehlende
  Typangaben und tote Zweige findet hier nichts. Wer `app/`, `agent/` oder
  `tests/` anfasst, rechnet mit einer Runde CI dafür — viermal an einem Tag
  passiert, und jedes Mal war es eine Typangabe oder eine Variable, die beim
  Aufräumen wegfiel.

  **Eine Falle, die dreimal davon ausgemacht hat:** Ein einzeiliger
  Dokumentationsblock trägt **keine Marke**. In
  `/** Die Namen. @return list<string> */` ist `@return` Fliesstext, und die
  Angabe ist damit weg — PHPStan meldet „no value type specified", und zwar
  erst in der CI. Marken stehen auf einer eigenen Zeile; `/** @return
  list<string> */` allein geht, mit Text davor nicht.

  **Und eine zweite, die an einem Tag zweimal zugeschlagen hat: ein Name, der
  der Basisklasse gehört.** `count()` in einem PHPUnit-Testfall (dort `final`)
  und `configure()` in einem Artisan-Kommando (dort `protected`) — beide
  brechen beim **Laden** der Klasse und nicht beim Ausführen. `php -l` sieht
  davon nichts, die Meldung kommt als fataler Fehler, und im zweiten Fall stand
  damit nicht ein Kommando still, sondern `artisan` mit allen. Wer in einer
  abgeleiteten Klasse eine private Hilfsmethode einzieht, sieht vorher in der
  Basisklasse nach.
- **Der Hostname ist kurz.** `php_uname('n')` liefert nicht den vollen Namen —
  dafür gibt es `SrvPanel\Agent\Names::fqdn()` (oder `host()`, wenn ein Name
  gebraucht wird und `null` nicht taugt), und die ist die *einzige* Stelle, die
  diese Frage beantworten darf. Sie ist **viermal** neu erfunden worden; seit
  dem vierten Mal gibt es `HostnameSourceTest` dafür.
- **Screenshots** über Playwright mit dem vorinstallierten Chromium
  (`/opt/pw-browsers/chromium`), niemals `playwright install`. Vier Dinge, die
  jedes Mal Zeit gekostet haben:
  - `Totp::codeAt($secret, $step)` nimmt den **Schritt**, nicht den
    Zeitstempel: `intdiv(time(), Totp::PERIOD)`. Und `two_factor_last_step`
    verhindert die Wiederverwendung — ein zweiter Lauf in derselben Minute
    muss auf den nächsten Schritt warten.
  - **Der SSE-Kanal eines offenen Vorgangs blockiert `artisan serve`.** Der
    bedient eine Anfrage gleichzeitig, und `PHP_CLI_SERVER_WORKERS` greift
    hier nicht. Im Aufnahmeskript `page.route('**/stream', r => r.abort())`.
  - **Der Entwicklungsserver liefert aus `public/build`.** Nach jeder Änderung
    an einer `.vue` erst `npm run build`, sonst zeigt die Aufnahme den
    vorigen Stand. Zweimal darauf hereingefallen.
  - Nach jeder Aufnahme `scrollWidth - clientWidth` messen. Ein waagerechter
    Überlauf bei 390px sieht auf dem Bild nach nichts aus und ist auf dem
    Telefon der ganze Unterschied.
- Vordergrund-`sleep` ist blockiert — Hintergrundlauf verwenden.
- **`git checkout -- resources/` wirft eigene Arbeit weg.** Beim Gegenprüfen
  eines Wächters ist das der Weg zurück — und wenn im selben Verzeichnis noch
  nicht Eingechecktes liegt, ist es danach fort. `tests/waechter-brechen.sh`
  weigert sich deshalb bei schmutzigem `resources/`; von Hand gilt dasselbe.

---

## Ablauf

- Entwickelt wird auf dem zugewiesenen `claude/...`-Zweig, **nie direkt auf
  `main`**. Ist der zugehörige PR gemergt, wird der Zweig unter demselben Namen
  frisch von `main` gestartet, statt auf gemergter Historie zu stapeln.
- **Einen Pull Request nur öffnen, wenn ausdrücklich danach gefragt wurde.**
  `.github/pull_request_template.md` gibt die Gliederung vor.
- Freigaben sind annotierte Tags `v<version>` auf `main`; ein Release-Lauf baut
  daraus die `.deb`-Pakete und signiert die Prüfsummen. **Privates
  Schlüsselmaterial wird in diesem Container nie erzeugt.**
- Der `CHANGELOG.md` ist kein Protokoll der Commits, sondern der Ort, an dem
  steht, *warum* etwas so ist — und was vorher falsch war. `ChangelogTest`
  prüft ihn.

Eine Ausbaustufe gilt erst als fertig, wenn ihr Abnahmekriterium **nachweisbar**
erfüllt ist (Plan §8 und §9) — gemessen auf einem echten Server, nicht
geschätzt.
