# SrvPanel — für Claude

Ein Hosting-Panel in der Art von Plesk: Laravel 13, Inertia, Vue 3,
AGPL-3.0-only. Zielplattformen sind Debian 12/13 und Ubuntu 22.04/24.04.

**Der Plan ist `docs/20-hostingpanel-neuplan.md`.** Er ist die Quelle für
Architektur (§4), Rechtemodell (§6), Gestaltung (§7.2) und die Ausbaustufen
(§9). Wo dieses Dokument und der Plan sich widersprechen, gilt der Plan.

Die Oberfläche folgt seit August 2026 dem Gestaltungssystem **„Kontor"**
(Plan §7.2) — hell entworfen, keine Karten, Monospace nur für Kennungen.

Stand: **P0 bis P5 abgenommen.** P5 brachte Datenbanken, Zugänge, Sicherungen,
Zurückspielen, Fernzugriff und das Hochladen mitgebrachter Sicherungen.
Nachgewiesen auf `cloudsrv24` gegen MariaDB 10.11.14: **alle sieben Kriterien
aus `docs/36 §17`**, dazu Schritt 10 und 11 am 9. August in zwölf Schritten
(`docs/36 §22.3w` und `§22.3x`).

**Als Nächstes kommt P5b — PostgreSQL**, als eigene Stufe mit eigenem Plan und
eigener Abnahme. Die Übergabe dafür ist **`docs/37`**; sie nennt auch die eine
Frage, die vor dem Planen beantwortet sein muss.

Ausgeliefert wird `v0.5.0-rc.10`.

**Der Abnahmelauf hat sechs Fehler gefunden, und keinen davon ein Test.** Drei
betrafen ein Kriterium, drei die Bedienung. Der teuerste sah aus wie ein Erfolg:
Die Erneuerung meldete `1 fällig, 1 bestellt` — genau die Zahl, die das
Kriterium verlangt — und bestellte ein **gewöhnliches** Zertifikat statt eines
Platzhalters. Gefunden hat es der Betreiber, weil er nach der grünen Meldung die
Seriennummern verglichen hat. Daraus die Lehre, die über TLS hinausgeht:

> **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt wurde.**

Die anderen fünf folgen demselben Muster wie schon der Wurf davor: eine
Bedingung, die an einer *Absicht* hängt statt an einem *Zustand* (das
Platzhalter-Kästchen, der Hinweis auf ungedeckte Namen), eine Prüfung, die eine
Zeile zu spät läuft (die Kettenreihenfolge), eine zweite Fassung derselben Regel
(`srvpanel dns` kannte nur RFC 2136) und ein Bedienelement ohne sichtbare
Beschriftung.

**Die Abnahme davor hing an den Screenshots**, und sie haben sich gelohnt: drei
Fehler auf einer Seite, die vollständig grün getestet war — eine Kennung, die im
Fliesstext nicht brach und die Seite um 83px aus dem Bildschirm schob, ein
Abstand, der aus der Reihenfolge der Seite abgeleitet war und mit der nächsten
Ergänzung fiel, und ein `<select>`, das abschneidet statt umzubrechen. Das ist
der Grund für die Regel weiter unten, und `v0.4.0-rc.4` ist mit dem
Umbruchfehler ausgeliefert worden, weil sie einen Tag zu früh kam.

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

**Und ein zweites Muster, das der Umbau aus `docs/35` freigelegt hat: eine
Ressource, die sich anlegen, aber nirgends löschen lässt.** Zertifikate konnte
dieses System bestellen, hochladen und erneuern — ein `remove` gab es weder im
Panel noch im Agenten. Jedes zurückgebaute Abonnement liess seinen privaten
Schlüssel unter `/etc/srvpanel/tls/certs` liegen, und zwar seit es
Kundenzertifikate gibt. Gemerkt hat es niemand, weil ein Grabstein die Zeile am
Leben hielt: Solange etwas auf den Rest zeigt, sieht der Rest nicht aus wie
einer. Aufgefallen ist es erst, als eine Migration danach *fragte* — und dann
gleich zwölfmal. Wer etwas anlegt, das auf der Platte bleibt, baut den Weg
zurück mit; sonst findet ihn Jahre später eine Datenmigration.

**Drei Funde aus P3, die keiner Regel widersprachen, sondern eine fehlende
zeigten:** `$` passt in PCRE auch vor einem abschliessenden Zeilenumbruch (neun
Muster betroffen, vier davon aus P0–P2); zwei fertig gebaute Agent-Operationen
wurden von nichts aufgerufen; eine Knopfklasse zeigte auf eine CSS-Regel, die
es nicht gibt. Jeder Fund hat seinen Wächter bekommen.

**Und: im Browser nachsehen, nicht nur bauen.** Drei Fehler dieser Woche waren
grün getestet und trotzdem falsch — ein Knopfrand mit 1,04:1 Kontrast, ein
Umschalter, der sichtbar nichts tat, eine doppelte Erfolgsmeldung. Bei allem
Sichtbaren gehört ein Screenshot dazu, in beiden Themes und bei 390 px.

**Und wenn es gerade nicht geht, wird es nachgeholt und nicht abgehakt.** P4
Schritt 6 ging ohne Screenshots in die Auslieferung, weil `vendor/` fehlte —
und die nachgeholte Runde fand auf einer einzigen Seite drei Fehler, jeden
davon vollständig grün getestet. Der teuerste war eine Kennung im Fliesstext,
die 83px aus dem Bildschirm schob; er war ausgeliefert. **Für den Fall, dass
`artisan serve` nicht läuft, gibt es einen Weg, der eine Fassung früher
gereicht hätte:** das gebaute Stylesheet aus `public/build` mit dem Markup des
fraglichen Bausteins in einer eigenen HTML-Datei, gerendert im vorinstallierten
Chromium bei 390px, `scrollWidth - clientWidth` per `<script>` als Text auf die
Seite geschrieben. Das ersetzt den Blick auf die echte Seite nicht — aber es
beantwortet die Frage, ob etwas überläuft, ohne Datenbank und ohne PHP.

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

Weiteres, das man wissen muss: Weiche Löschungen verbrauchen Bezeichner —
**für Kunden.** Ihre Nummer steht in Rechnungen, an ihrem Grabstein hängen die
Konten, und die Anmeldung liest ihn. Für Abonnements galt dasselbe bis August
2026; seit `docs/35` steht die Reservierung des Systembenutzers in
`system_users`, und ein zurückgebautes Abonnement wird hart gelöscht.
`Lifecycle::claim()` verbraucht einen Namen, `nextSystemUser()` zeigt ihn nur
an — wer die beiden verwechselt, vergibt `p1000` zweimal.
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
schon dasteht, was fehlt, und die Falle, die dabei aussperrt — · **`33` der
Abnahmelauf für 0.3.1**, der davor kommt · und **`34` der zweite Wurf von P4**:
DNS-01, Platzhalter, eigene Zertifikate — mit den drei Stellen, an denen der
erste Wurf der Erweiterung nicht standhält, und den vier Fragen, die der
Betreiber vorher beantwortet · und **`35` das Verzeichnis der Systembenutzer** —
**abgenommen am 7. August 2026**: Abonnements werden hart gelöscht, die
verbrauchten Namen stehen in `system_users`. **§9 nennt drei Stellen, an denen
der Plan nicht trug**, §10 die Befehlsfolge, **§11 den Abbruch des ersten
Anlaufs** — ein Zertifikat liess sich in diesem System nicht löschen — und §12
die Messwerte. Die Entwürfe zum Gestaltungssystem stehen
unter `docs/entwuerfe/`: `20` die Wahl von 2026 („Leitstand"), `29` der erste
Rework-Plan, `30` die zwei neuen Richtungen, `31` das bediente Muster zu
„Kontor".

Und aus P5: **`36` Datenbanken** — der Plan, die sieben Abnahmekriterien als
Befehlsfolge (§17), die Entscheidungen des Betreibers (§19) und ein langes
Protokoll dessen, was beim Bauen anders war als im Plan (§22.3a–§22.3x) — sowie
**`37` die Übergabe an P5b**.

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

  **Und `ls vendor` genügt für diese Frage nicht.** Am 6. August lagen dort
  39 Pakete und trotzdem weder `vendor/bin` noch `vendor/autoload.php`: ein
  abgebrochenes `composer install`, das aussieht wie ein fertiges. Gefragt wird
  nach `vendor/autoload.php`, nicht nach dem Verzeichnis.

  **Und es gibt Werkzeuge, die der Proxy durchlässt.** Was scheitert, ist
  `composer install`, nicht jedes HTTPS — und die Trennlinie ist in P5 genau
  vermessen worden: Die Metadaten von packagist antworten mit **200**,
  `codeload.github.com` mit **403**. Composer löst also auf und scheitert beim
  Herunterladen.

  - **`pint.phar` gibt es von den GitHub-Releases, und es *ist* Pint.** In P4
    stand hier, man müsse sich mit `php-cs-fixer.phar` behelfen und dessen
    Regeln von Hand nachbauen; das war ein Umweg um etwas herum, das es gibt.
    `curl -sSL -o pint.phar
    https://github.com/laravel/pint/releases/latest/download/pint.phar` holt
    dieselbe Fassung, die `composer.json` verlangt (`^1.27`), und
    `php pint.phar --test` über das ganze Repo sagt dasselbe wie die CI —
    am 7. August 2026 gegengeprüft, beide grün. **Damit fällt eine ganze
    Klasse von CI-Runden weg:** Formatierung wird hier gemessen, nicht
    geraten.
  - **php-cs-fixer ist nicht Pint** — die Notiz bleibt für den Fall, dass
    `pint.phar` einmal nicht zu holen ist. Pints Laravel-Voreinstellung ist
    ein eigener Regelsatz; wer dort alles anschaltet, bekommt Meldungen zu
    Stellen, die Pint in Ruhe lässt (`) {}` an einem leeren Konstruktor zum
    Beispiel).
  - **PHPStan taugt nur für `agent/`.** Ohne `vendor/` fehlt larastan, und
    jedes `Model::query()` und jede Spalte gilt als undefiniert — hunderte
    Meldungen, die nichts bedeuten. Unterhalb von `agent/` gibt es kein
    Framework, und dort läuft Stufe 6 sauber durch. Genau so gefunden:
    `array_values()` auf einer Liste, die schon eine ist.

    **Für eine einzelne neue Datei geht trotzdem mehr, als es aussieht.** Ein
    Lauf über *nur* die geänderte Datei bringt zwar ein Dutzend
    `method.notFound` für jede `assert…` — aber alles, was PHPStan aus dem
    Code selbst schliesst, steht dazwischen und ist echt. Gefiltert nach
    Kennungen, die nichts mit fehlenden Klassen zu tun haben
    (`--error-format=raw`, dann ohne `notFound`), fällt zum Beispiel ein
    `function.impossibleType` heraus: ein `in_array($x, self::LEER, true)`
    gegen eine leere Konstante. Genau der hat eine CI-Runde gekostet, und die
    Gegenprobe zeigt, dass er lokal auffindbar gewesen wäre.

    **Und `tests/Support/` gehört in denselben Lauf wie `agent/src`.** Die
    Testdoppel dort hängen am Agenten und nicht am Framework, also läuft Stufe 6
    auch über sie sauber durch. Wer das trennt, sieht die teuerste Meldung
    nicht: Bekommt eine Schnittstelle unter `agent/` eine Methode und das Doppel
    nicht, ist das `method.abstract` — und in den Tests kein Fehlschlag, sondern
    ein Abbruch („Premature end of PHP process"), der alles Folgende verschluckt.
    Am 7. August genau so passiert, mit `DnsProvider::patience()`.

  **Für `agent/` gibt es ausserdem einen Ausweg, und er hat in P4 eine Runde
  gespart.**
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

  **Und eine zweite, die inzwischen viermal zugeschlagen hat: ein Name, der
  der Basisklasse gehört.** `count()` in einem PHPUnit-Testfall (dort `final`),
  `configure()` in einem Artisan-Kommando (dort `protected`), und in P5 gleich
  zweimal: `for()` in einer `Factory` und `matches()` wieder in einem Testfall
  (`PHPUnit\Framework\Assert::matches()`, `final` **und** `static`). Alle
  brechen beim **Laden** der Klasse und nicht beim Ausführen. `php -l` sieht
  davon nichts, die Meldung kommt als fataler Fehler — bei `matches()` endete
  `php artisan test` mit Rückgabewert 255, bevor ein einziger Test lief: Nicht
  eine Datei stand still, sondern alle vierundsiebzig.

  Bemerkenswert ist, wie P5 sie erlebt hat: Beim `for()` in der Factory hat der
  Blick in die Basisklasse sie gefangen, beim `matches()` im Testfall nicht —
  **die Regel zu kennen genügt nicht, wenn man nicht daran denkt, dass ein
  Testfall auch eine abgeleitete Klasse ist.** Wer in einer abgeleiteten Klasse
  eine private Hilfsmethode einzieht, sieht vorher in der Basisklasse nach; ein
  Testfall zählt dazu.
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
