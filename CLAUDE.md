# SrvPanel — für Claude

Ein Hosting-Panel in der Art von Plesk: Laravel 13, Inertia, Vue 3,
AGPL-3.0-only. Zielplattformen sind Debian 12/13 und Ubuntu 22.04/24.04.

**Der Plan ist `docs/20-hostingpanel-neuplan.md`.** Er ist die Quelle für
Architektur (§4), Rechtemodell (§6), Gestaltung (§7.2) und die Ausbaustufen
(§9). Wo dieses Dokument und der Plan sich widersprechen, gilt der Plan.

Stand: **P0, P1 und P2 abgenommen**, ausgeliefert als `v0.2.0-rc.x`. Als
Nächstes P3 (Web und PHP).

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
`MobileLayoutTest`, `DesignTokensTest`.

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

Weiteres, das man wissen muss: Weiche Löschungen verbrauchen Bezeichner
(Kundennummern, `p1000`-Systembenutzer ↔ UID-Wiederverwendung).
Agent-Klassen sind aus der Anwendung autoladbar (`SrvPanel\Agent\` →
`agent/src/`), das Panel darf also `Names::fqdn()` direkt fragen.

---

## Sprache und Gestaltung

**`docs/19-sprache-der-oberflaeche.md` ist bindend** und wird von
`WordChoiceTest` geprüft. Kurz:

- **Kommentare, Dokumentation und alle Texte der Oberfläche: deutsch.
  Bezeichner: englisch.**
- Keine Emoji in der Oberfläche (§3a) — sie sehen auf jedem System anders aus
  und nehmen keine Textfarbe an.
- Kommentare erklären **warum**, nicht was. Der wertvollste Kommentar hält
  fest, was schiefging und weshalb es jetzt anders ist.

**Jede Farbe kommt aus `resources/css/app.css`** (Plan §7.2). Ein Hexwert in
einer Komponente ist ein Fehler und keine Ausnahme; die CI prüft das. Beide
Themes entstehen zusammen, nie eines nachträglich. Kontrast wird gerechnet,
nicht geschätzt: 4,5:1 für Text, **3:1 für die Grenze eines Bedienelements**
(WCAG 1.4.11). Das Aussehen eines Knopfes steht ausschliesslich in `app.css`
— `ButtonStyleTest` besteht darauf.

Weitere Dokumente: `21` Signaturschlüssel · `22` Passwörter · `23` Pläne und
Kontingente · `24` mobile Ansicht · `25` Mailversand · `26` Abonnements ·
`27` Zertifikat.

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

Auf dem Zielserver: `srvpanel setup|update|metrics|usage|tls|acceptance|admin`.

---

## Diese Umgebung

Der Container ist **nicht** der Zielserver. Was hier fehlt, muss man beim
Testen berücksichtigen:

- **kein nginx, kein Agent, kein systemd.** Operationen laufen gegen Attrappen.
  Zwei Fehler sind nur aufgefallen, weil die CI nginx *hat* und dieser Container
  nicht — Tests, die Systemzustand annehmen, gehören abgesichert.
- **PHPStan ist hier nicht lauffähig** (bricht ohne Ausgabe mit Rückgabewert 1
  ab). Er läuft in der CI; `composer pruefe` schlägt deshalb lokal fehl. Einzeln
  `pint` und `phpunit` aufrufen.
- **Der Hostname ist kurz.** `php_uname('n')` liefert nicht den vollen Namen —
  dafür gibt es `SrvPanel\Agent\Names::fqdn()`, und die ist die *einzige*
  Stelle, die diese Frage beantworten darf. Sie ist schon zweimal neu erfunden
  worden.
- **Screenshots** über Playwright mit dem vorinstallierten Chromium
  (`/opt/pw-browsers/chromium`), niemals `playwright install`. Anmeldung
  braucht den zweiten Faktor; der Code kommt aus
  `App\Support\Auth\Totp::codeAt()`.
- Vordergrund-`sleep` ist blockiert — Hintergrundlauf verwenden.

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
