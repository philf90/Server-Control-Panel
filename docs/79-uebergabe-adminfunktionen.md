# Übergabe: die Adminfunktionen vor P8

**Stand: 24. August 2026.** Dieses Dokument ist für eine Sitzung geschrieben, die
mit diesem Repo noch nichts zu tun hatte und die Adminfunktionen planen und
bauen soll, die vor P8 kommen.

**Was es nicht ist.** Es ist **kein Plan für die Adminfunktionen** — der ist in
einer eigenen Sitzung entstanden und liegt dem Betreiber vor. Dieses Dokument
trägt den Zustand des Projekts und die Regeln, unter denen hier gebaut wird.
Wer die beiden verwechselt, plant zweimal.

> **Was man zweimal braucht, gehört ins Repo — auch wenn es keine Zeile Code
> ist.** Deshalb steht das hier und nicht in einem Sitzungsverlauf.

---

## 1. Die drei Dokumente, die vor allem anderen kommen

| Datei | Wofür |
|---|---|
| **`docs/20-hostingpanel-neuplan.md`** | Der Plan. Quelle für Architektur (§4), Rechtemodell (§6), Gestaltung (§7.2) und die Ausbaustufen (§9). **Wo Plan und irgendein anderes Dokument sich widersprechen, gilt der Plan.** |
| **`CLAUDE.md`** | 1760 Zeilen, und sie sind keine Einleitung, sondern ein Fehlerprotokoll. Fast jeder Absatz ist die Beschreibung eines Fehlers, der teuer war. Wer die Datei überfliegt, macht drei davon noch einmal. |
| **`docs/19-sprache-der-oberflaeche.md`** | Bindend für jeden Text, den ein Mensch liest. Wird von `WordChoiceTest` geprüft. |

---

## 2. Der Stand in Zahlen

| | |
|---|---|
| Ausbaustufen | **P0 bis P7 abgenommen** — P7 am 24. August 2026 auf `cloudsrv24` gegen `0.7.0-rc.8`, alle acht Kriterien aus `docs/72 §3` |
| Letzte Fassung | `v0.7.0-rc.11`, Beta-Kanal |
| `main` | `fa63ef7a` |
| Wächter | 306 Dateien unter `tests/Unit` und `tests/Feature`, **1957 Testfälle** |
| Bruchskript | `tests/waechter-brechen.sh`, **726 Eingriffe** |
| Agent-Operationen | 95 unter `agent/src/Ops/` |
| Zielplattformen | Debian 12/13, Ubuntu 22.04/24.04 — alle vier laufen in der CI |

**Was in P7 entstand** (für den Fall, dass eine Adminfunktion daran rührt): der
DNS-Abgleich — Sollzustand, Istzustand von den autoritativen Servern, drei
Zustände, CAA, eine regelmässige Messung mit Timer. Der Plan ist `docs/72`, die
Protokolle `docs/74`, `docs/76`, `docs/78`.

---

## 3. Die drei Grenzen — nicht verhandelbar

Sie stehen ausführlich in `CLAUDE.md` und im Plan §4. Kurz:

1. **Der Agent ist die einzige Stelle mit Systemrechten.** `agent/` ist ein
   framework- und abhängigkeitsfreies PHP-CLI hinter einem Unix-Socket. Die
   Anwendung schickt **typisierte Operationen**, niemals Text, der zu einer
   Kommandozeile oder Konfigurationsdatei wird. Programme stehen auf einer
   Positivliste mit absoluten Pfaden. **Nichts Privilegiertes gehört in `app/`**
   — auch nicht „nur kurz".
2. **Zustände folgen dem Agenten, nicht dem Klick.** Ein Vorgang ändert den
   Zustand erst, *nachdem* der Agent geantwortet hat
   (`Lifecycle::afterSuccess()` aus `RunAgentOperation`).
3. **Die Mandantenklammer verweigert im Grundzustand alles.**
   `app/Support/Tenancy/Tenancy.php` klammert Abfragen auf `whereRaw('0 = 1')`.
   `withoutRestriction()` ist die ausdrückliche Ausnahme und will begründet
   sein.

**Für Adminfunktionen ist die dritte die wichtigste — und die missverstandene.**
Sie ist die Regel fürs *Durchsetzen* und war nie eine Erlaubnis, jedem alles
anzubieten:

> **Wer eine Aktion zeigt, fragt vorher dieselbe Policy, die sie später
> abweist.** Die Antwort kommt als `can`-Ablage im Inertia-Payload — **nicht**
> als `v-if` auf den Kontotyp, denn das wäre eine zweite Fassung der Policy, und
> die zweite ist die, die veraltet. `AbilityReachTest` prüft beide Richtungen.

Jede Route trägt `can:` oder steht mit Begründung in
`app/Support/Authorization/RouteGuard.php`. `RouteAuthorizationTest` und
`PolicyReachTest` bestehen darauf.

---

## 4. Die eine Gewohnheit, die dieses Projekt trägt

**Für jede Regel gibt es einen Wächter, und der Wächter wird gegengeprüft.**

Der Fehler, der hier immer wiederkehrt, ist derselbe: *eine Zeichenkette, die
auf etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug
prüft.* Eine Policy ohne Route. Ein Kommando, das im Startskript fehlt. Ein
Verzeichnisname, der umbenannt wurde. Er ist mindestens sechsmal aufgetreten und
jedes Mal teuer gewesen.

Deshalb: Wer eine Regel aufstellt, baut den Test dazu — und **bricht die Regel
danach absichtlich, um zu sehen, dass der Test zubeisst.**

> **Ein Wächter, der nie rot war, ist kein Wächter.**

Der Bruch gehört als Eingriff in `tests/waechter-brechen.sh`. `BreakScriptTest`
wacht darüber, dass jeder Eingriff seinen Text noch findet und jeder genannte
Zieltest existiert — er hat am 24. August zwei Eingriffe gefangen, die ein Umbau
stumpf gemacht hatte.

**Und die Falle, in die dieses Vorgehen selbst dreimal gelaufen ist:** Ein
Wächter zählt seine Treffer, damit er merkt, wenn sein Ausdruck ins Leere läuft
— und zählt sie dort, wo die Regel gerade eingehalten wird. Zieht die Regel um,
meldet er Rot für genau die Ordnung, die er durchsetzen soll. Die Untergrenze
zählt deshalb überall mit, wo die Regel stehen *darf*; der Befund kommt nur von
dort, wo sie stehen *soll*.

---

## 5. Diese Umgebung — was geht und was nicht

**Der Container ist nicht der Zielserver.** `CLAUDE.md` hat dazu einen langen
Abschnitt; hier das, was am meisten Zeit spart.

### Was fehlt

- **`vendor/` gibt es nicht.** Kein `composer install` — der Proxy sperrt
  `codeload.github.com`. Also kein `phpunit`, kein `artisan`, keine
  Feature-Tests.

  **`ls vendor` genügt für diese Frage nicht** — gefragt wird nach
  `vendor/autoload.php`.
- **Kein nginx, kein PHP-FPM, kein Agent, kein systemd.** Vorlagen werden
  deshalb **als Text** geprüft (`SiteTemplateTest`, `PhpIsolationTest`).

### Was trotzdem geht — und in dieser Reihenfolge probiert werden sollte

> **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
> braucht einen Versuch.** Dieser Satz hat hier MariaDB, OpenSSH, PowerDNS und
> PHPStan freigelegt, die alle jahrelang als unerreichbar galten.

| Werkzeug | Wie |
|---|---|
| **Pint** | `curl -sSL -o pint.phar https://github.com/laravel/pint/releases/latest/download/pint.phar` — dieselbe Fassung wie in der CI, gegengeprüft |
| **PHPStan** | `curl -sSL -o phpstan.phar https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar`. **Nicht** `phpstan.neon` benutzen (bindet larastan ein) — eine dreizeilige Wegwerfdatei mit `level: 6` und `treatPhpDocTypesAsCertain: false` |
| **npm** | funktioniert vollständig: `npm ci`, `npm run build`, `npm run types` |
| **Chromium** | vorinstalliert unter `/opt/pw-browsers/chromium`, **niemals** `playwright install` |
| **MariaDB, OpenSSH, PowerDNS** | im Ubuntu-Archiv, `apt-get install` holt sie; Wegwerf-Instanzen im Scratchpad |
| **PostgreSQL 16** | vollständig installiert |

**PHPStan taugt nur für framework-freien Code** (`agent/`, `tests/Support`).
Für `app/` fehlt larastan, und dann ist jede Spalte undefiniert. Zwei Regeln
dazu:

- **Die Dateiliste kommt aus dem Zweig und nicht aus dem Gedächtnis:**
  `git diff --name-only origin/main...HEAD`.
- **Die Schnittstellen, die eine Datei umsetzt, gehören in denselben Lauf** —
  sonst meldet PHPStan nicht „ich kenne sie nicht", sondern dass die Klasse sie
  nicht erfüllt.
- Für `app/` lohnt trotzdem ein Lauf mit einem Filter **nach Kennung** (nicht
  nach Wortlaut): `class.notFound`, `method.notFound`, `missingType.*` und
  Verwandte sind Rauschen, alles andere ist echt. **Mit Gegenprobe** — ein
  absichtlicher Typfehler muss eine Zeile erzeugen, sonst misst der Lauf nichts.

### Das Wegwerf-Gestell für die Wächter

**Die framework-freien Wächter laufen hier, ohne PHPUnit.** Wer nur von
`PHPUnit\Framework\TestCase` erbt, braucht davon nur eine Sammlung von
`assert…`-Methoden. Ein Skript im Scratchpad, das diese Klasse selbst definiert
und die Testdatei einbindet, fährt sie. **Das ist keine zweite Fassung der
Tests** — es steht darin keine einzige Behauptung, nur die Maschine, die die
echten ausführt.

Damit laufen hier rund **1635 Testfälle grün**; 118 sind „Löcher" (Klassen, die
Laravel brauchen), 11 sind rot und auf `main` genauso rot.

Fünf Dinge, die beim Bau des Gestells Zeit gekostet haben und die eine neue
Sitzung nicht noch einmal bezahlen sollte:

1. `tests/Support/` **nicht blind laden** — nur Traits und Klassen ohne
   `use App\`; sonst zieht es Framework-Interfaces nach.
2. `agent/src/autoload.php` gehört dazu.
3. **Die Attrappe muss die `final`-Methoden der echten Basisklasse tragen**
   (mindestens `run()`, `count()`, `matches()`, `toString()`). Ohne sie meldet
   das Gestell Grün für Code, den `php artisan test` mit Rückgabewert 255
   tötet, bevor ein Test läuft.

   > **Eine Attrappe, die weniger verbietet als das Original, sagt Ja zu Code,
   > den das Original ablehnt.**
4. Die Zahl der Werte eines Datenlieferanten gegen `getNumberOfParameters()`
   prüfen — PHPUnit endet sonst mit Rückgabewert 1, während „alle bestanden"
   dasteht.
5. **Was das Gestell nicht kann, wird gezählt und nicht „übersprungen" genannt.**
   Nach Art (fehlende Klasse, `setUp()`, Datenlieferant, `use App\`) und nicht
   nach dem Wortlaut der Meldung — eine Einteilung nach `not found` gegen
   `does not exist` hat einmal 104 Wächter in die falsche Richtung gekippt.

   > **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die
   > kleiner werden kann.**

**Eine offene Entscheidung für den Betreiber:** Dieses Gestell wird bisher in
jeder Sitzung neu gebaut, und die fünf Punkte oben sind teuer erlernt. Es *nicht*
im Repo zu haben, ist der Grund, warum sie jedes Mal neu bezahlt werden. Dagegen
steht die Sorge vor einem zweiten Testläufer, der von phpunit abdriftet. Der
Satz aus `CLAUDE.md` — *Ein Messmittel, das man aufhebt, macht die Fehler von
letztem Mal nicht noch einmal* — spricht dafür; entschieden ist es nicht.

### Einen einzelnen Eingriff des Bruchskripts fahren

`tests/waechter-brechen.sh` als Ganzes braucht `vendor/bin/phpunit` und läuft
hier nicht — **der einzelne Eingriff schon**: Datei sichern, den Python-Block
von Hand anwenden, den Wächter im Gestell fahren, Datei zurückholen.

> **„Das Bruchskript läuft hier nicht" ist keine Ausrede, sondern ein Handgriff
> mehr.**

**Welche Eingriffe man fährt, sagt der Zweig und nicht das Gedächtnis:** alle,
deren `vorher_datei` eine Datei nennt, die dieser Zweig geändert hat. Zwei
Fallen dabei, beide bezahlt: Ein Lauf über alle Eingriffe braucht mehr als zwei
Minuten und wird abgebrochen — ein Abbruch mitten im Eingriff lässt die Datei
kaputt liegen (`git status` vorher und nachher vergleichen). Und
`sort -u datei | tee datei` leert die Datei, bevor `sort` sie liest.

### Bilder und die 390-px-Messung

**Bei allem Sichtbaren gehört ein Screenshot dazu, in beiden Themes und bei
390 px.** Die Messvorschrift liegt als **`tests/bilder-messen.js`** im Repo,
`OverflowProbeTest` liest sie. Ohne `artisan serve` geht der Aufsatz mit dem
gebauten Stylesheet aus `public/build` plus dem Markup des Bausteins in einer
eigenen HTML-Datei — **das misst die echte Seite und nicht etwas Ähnliches**,
aufs Pixel gegengeprüft (`docs/56`, Punkt 5).

Vier Fallen:

- **`<style scoped>` gilt in diesem Aufsatz nicht.** Vite übersetzt zu
  `.usage[data-v-1ecda25a]`; handgeschriebenes Markup trifft das nie. 105
  Selektoren aus 19 Komponenten sind so gebaut.
- **Jede Messung braucht ihre Gegenprobe.** Ein Prüfkörper muss dort eine Zahl
  erzeugen — und zwar an das Fenster gebunden (`scrollWidth + 200`), sonst fällt
  er bei der grösseren Breite auf `0` zurück.
- **Kein `| head` über dem Messlauf** — `head` schliesst die Leitung, node
  stirbt am SIGPIPE, und die übrigen Aufnahmen sind die des *vorigen* Laufs.
- **Nach jeder Änderung an einer `.vue` erst `npm run build`**, sonst zeigt die
  Aufnahme den vorigen Stand.

> **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite schiebt.
> Keines von beiden ersetzt das andere.**

---

## 6. Was für Adminfunktionen im Besonderen gilt

Die Punkte, an denen dieses Projekt bei sichtbaren Merkmalen am häufigsten
gestolpert ist:

### Der Ort im Menü — dreimal derselbe Fehler

Der Dateimanager lag drei Klicks tief, der SFTP-Zugang danach genauso, und der
Bereich „Job anlegen" auf der Cronseite war der dritte von drei Bereichen mit
zehn Kärtchen dazwischen. Jedes Mal hat es der Betreiber gemeldet, keiner der
Wächter.

> **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
> dort?** Nicht „ist sie erreichbar" — erreichbar ist alles, was man findet,
> wenn man lange genug rollt.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**

### Autorisierung

- Route trägt `can:` oder steht begründet in `RouteGuard`.
- Ein Knopf, den der Betrachter nicht drücken darf, wird **nicht gezeigt** —
  und die Antwort darauf kommt aus der Policy, nicht aus dem Kontotyp
  (`AbilityReachTest`).
- **Admins sind über `forAccount()` unbeschränkt** — wer eine Adminansicht baut,
  prüft, dass sie nicht versehentlich die Mandantenklammer umgeht, wo sie gelten
  soll.

### Das Protokoll

`record()` nimmt `target:` **und** `context:`. Beides. In P6 wurden 18 von 19
Aufrufen ohne `context` geschrieben, und niemandem fiel es auf, weil keine
Oberfläche das Feld las.

> **Ein Protokoll, das die Art der Handlung nennt und nicht ihren Gegenstand,
> beantwortet die Frage, die niemand stellt.**

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt.**

### Rückmeldungen am Formular

`docs/19 §6`, geprüft von `FieldErrorTest`, alle drei Richtungen:

- Der Satz eines Fehlers steht **oben in der Zusammenfassung**.
- Das Feld trägt nur `aria-invalid`.
- **Erfolg wird nie am Feld gemeldet.**
- Und: **ein roter Rand behauptet, das Feld sei falsch.** Wer ihn für einen
  Zustand des Servers setzt, schickt den Leser dorthin, wo nichts zu ändern ist.

Die 85 deutschen Feldnamen für Fehlermeldungen müssen zur **sichtbaren
Beschriftung** derselben Seite passen — bei der letzten Messung wichen 15 von 68
ab.

> **Ein Wächter über die Vollständigkeit sagt nichts über die Richtigkeit.**

### Sprache und Gestaltung

- **Kommentare, Dokumentation, alle Texte der Oberfläche: deutsch. Bezeichner:
  englisch** — das schliesst CSS-Klassen, Datenattribute und Komponentennamen
  ein (`ClassNameTest`).
- **Keine Emoji.**
- **Jede Farbe kommt aus `resources/css/app.css`.** Ein Hexwert in einer
  Komponente ist ein Fehler, kein Sonderfall. Ebenso eine Komponente, die ihr
  eigenes `input` oder `table` gestaltet.
- Kontrast wird **gerechnet**: 4,5:1 für Text, **3:1 für die Grenze eines
  Bedienelements**.
- Das Gestaltungssystem heisst **„Kontor"** (Plan §7.2) — hell entworfen, keine
  Karten, Monospace nur für Kennungen.
- Kommentare erklären **warum**, nicht was. Der wertvollste hält fest, was
  schiefging und weshalb es jetzt anders ist.

### Wer etwas anlegt, baut den Weg zurück mit

Zweimal teuer geworden: Zertifikate liessen sich bestellen, aber nicht löschen
(zwölf private Schlüssel blieben liegen), und zuletzt am 24. August überlebte
ein Zertifikat den Rückbau seiner Domain. **Wer eine Adminfunktion baut, die
etwas anlegt, das auf der Platte oder im Bestand bleibt, baut das Entfernen im
selben Schritt.**

---

## 7. Der Ablauf

- Entwickelt wird auf dem zugewiesenen `claude/...`-Zweig, **nie direkt auf
  `main`**. Ist der zugehörige PR gemergt, wird der Zweig unter demselben Namen
  **frisch von `main` gestartet**, statt auf gemergter Historie zu stapeln.
- **`git commit -s`** (DCO) auf jedem Commit.
- **Einen Pull Request nur öffnen, wenn ausdrücklich danach gefragt wurde.**
  `.github/pull_request_template.md` gibt die Gliederung vor.
- **Kein Modellbezeichner** in Commit-Nachrichten, PR-Titeln oder -Rümpfen,
  Codekommentaren oder sonst etwas, das ins Repo geht.
- **Privates Schlüsselmaterial wird in diesem Container nie erzeugt.**
- Freigaben sind annotierte Tags `v<version>` auf `main`.
- Der `CHANGELOG.md` ist kein Protokoll der Commits, sondern der Ort, an dem
  steht, *warum* etwas so ist — und was vorher falsch war (`ChangelogTest`).

**Eine Ausbaustufe gilt erst als fertig, wenn ihr Abnahmekriterium nachweisbar
erfüllt ist — gemessen auf einem echten Server, nicht geschätzt** (Plan §8, §9).

Der Betreiber fährt die Serverbefehle selbst und schickt Ausgaben und Bilder
zurück; diese Sitzung hat keinen Zugriff auf `cloudsrv24`.

---

## 8. Die Sätze, die am häufigsten gebraucht werden

Aus `CLAUDE.md`, in der Reihenfolge, in der sie hier Geld gekostet haben:

> **Die Mehrheit der Fehler steckt nicht im Prüfling, sondern im Prüfmittel.**
> In vier Abnahmeläufen hintereinander war es die Mehrheit; in einem die
> Gesamtheit.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**

> **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass zu
> messen.**

> **Ein Fehler, den man an einer Stelle vermieden hat, ist an der nächsten
> wieder da, wenn die Vermeidung nicht die Regel wurde.** Viermal aufgetreten.

> **Zwei Fassungen derselben Regel laufen auseinander — und die falsche ist die,
> die man bekommt.**

> **Eine Frage an die Vereinigung hält auch dann, wenn eine der Quellen blind
> ist — die andere zahlt für sie mit.**

> **Ein Prüfkörper, der ohne seinen Gegenstand misst, misst etwas anderes und
> sieht dabei aus wie ein Ergebnis.**

> **Ein Wächter, der die eigene Änderung nicht im Blick hatte, wird nicht
> gefahren — man denkt an das Gebaute und nicht an das Berührte.**

---

## 9. Was benannt offen bleibt

Nichts davon ist Schuld einer abgenommenen Stufe; alles ist bewusst stehen
gelassen und steht am jeweiligen Ort ausgeschrieben.

**Aus P7** (`docs/72 §11`, `docs/78 §5`):

- Die DENIC-Frage aus `docs/72 §1.4` — ungemessen, und sie gehört beantwortet,
  *bevor* jemand die Entscheidung gegen einen eigenen Nameserver aufmacht.
- Die zwei Servermessungen aus `docs/70 §14`.
- Das Schreiben fremder Zonen über die Anbieter-Zugangsdaten — nach `docs/72 §4`
  ausdrücklich nicht diese Stufe.
- Kein Aufstieg zur CAA-Elternzone (eine Grenze, kein Mangel); „Nameserver
  uneinig" und „kein Sollzustand bekannt" als nicht herstellbare Zustände; die
  Grenze des Durchgangs.

**Aus P6** (`docs/69 §3`, `docs/67 §3`): Wand 2 aus Punkt 11, Befund 23, die
neunzehn ungeprüften Griffe in `RevealTest::UNEXAMINED`, die vollständige
Umkehrung der Abstandsregel, und die Entscheidung zu `packaging/testbed.sh`.

**Aus P5b** (`docs/42 §5`): der `template1`-Beleg und die Frage, ob ein
PostgreSQL-Zugang ohne jede Datenbank überhaupt entstehen kann — beide **nie
gemessen**. Wer sie anfasst, fängt dort an und nicht bei null.

---

## 10. Was der neuen Sitzung konkret mitzugeben ist

1. **Den Plan der Adminfunktionen** aus der eigenen Sitzung — dieses Dokument
   ersetzt ihn nicht.
2. **Den Zweignamen**, auf dem entwickelt werden soll.
3. Den Hinweis, **`CLAUDE.md` zu lesen und nicht zu überfliegen** — und
   `docs/20` für alles, was Architektur, Rechte oder Gestaltung berührt.
4. Die Ansage, dass **der Betreiber die Serverbefehle fährt** und Ausgaben und
   Bilder zurückschickt.
5. Falls die Adminfunktionen sichtbar sind: dass **Bilder in beiden Themes bei
   390 und 1440 px** dazugehören, und dass `tests/bilder-messen.js` die
   Vorschrift dafür ist.
6. Die Entscheidung aus §5, ob das Wegwerf-Gestell ins Repo soll.
