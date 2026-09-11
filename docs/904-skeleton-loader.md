# Der Skeleton Loader — Plan

**Geschrieben am 10. September 2026, nach der Messrunde und nicht davor.** Der
Anlass ist eine Beobachtung des Betreibers: „Manche Seiten haben etwas längere
Ladezeiten, wie z. B. `/updates`."

Die Nummer kommt aus dem 900er-Block (`docs/900`), weil die laufende Zählung in
derselben Woche an die Bestandsdiagnose und den A10-Nachlauf gegangen ist.

---

## 1. Die Messrunde

Gemessen am 10. September 2026 auf `cloudsrv24` gegen `0.7.4-rc.2`, **je Aufruf
zweimal** — der A10-Lauf hat einen Schluss daran verloren, dass eine einmalige
Messung den kalten Zwischenspeicher mitmisst.

| Aufruf | kalt | warm |
|---|---|---|
| **`system.packages.list`** | **2998 ms** | **3033 ms** |
| `pg.server.info` | 103 ms | 104 ms |
| `php.versions` | 99 ms | 93 ms |
| `system.ports` | 70 ms | 69 ms |
| `system.units.list` | 46 ms | 33 ms |
| `system.time` | 40 ms | 8 ms |
| `system.sources.list` | 36 ms | 35 ms |
| `db.server.info` | 16 ms | 18 ms |
| `system.info` | 7 ms | 7 ms |
| `system.cron` | 7 ms | 6 ms |
| `panel.tls.info` | 5 ms | 5 ms |
| `system.logs.list` | 3 ms | 3 ms |

**Der zweite Lauf hat hier das Gegenteil dessen belegt, wofür es ihn gibt.**
`system.packages.list` ist warm nicht schneller, sondern eine Spur langsamer. Es
ist kein kalter Zwischenspeicher — es ist `apt-run simulate` über `systemd-run`,
also ein echtes `apt-get -s upgrade`, und das kostet jedes Mal drei Sekunden.

> **Zwei Läufe entscheiden nicht nur, ob eine hohe Zahl ein Zwischenspeicher war
> — sie entscheiden auch, dass sie bleibt.**

`system.time` ist der einzige Aufruf mit einem sichtbaren Unterschied (40 → 8).
Alle übrigen sind flach.

### 1.1 Je Seite gerechnet

Zehn Inertia-Seiten fragen beim Rendern den Agenten. Ihre Kosten sind die Summe
ihrer Aufrufe:

| Seite | Aufrufe | warm |
|---|---|---|
| **`/updates`** | `system.packages.list` + `system.sources.list` | **≈ 3068 ms** |
| Datenbank-Einstellungen | `db.server.info` + `pg.server.info` | ≈ 122 ms |
| Übersicht | `system.info` + `pg.server.info` (mindestens) | ≥ 111 ms |
| `/services` | `system.units.list` + `system.ports` | ≈ 102 ms |
| PHP-Einstellungen | `php.versions` | ≈ 93 ms |
| Allgemein | `system.time` | ≈ 8 ms |
| Zeitpläne | `system.cron` | ≈ 6 ms |
| TLS-Einstellungen | `panel.tls.info` | ≈ 5 ms |
| `/logs` | `system.logs.list` + `system.logs.tail` | ≥ 3 ms |
| Domain-Logs | `web.logs.tail` | ungemessen |

**Zwei Aufrufe sind nicht gemessen**, und zwar weil sie Argumente brauchen:
`system.logs.tail` (ein Schlüssel) und `web.logs.tail` (eine Domain). Beide
lesen das Ende einer Datei; erwartet sind Millisekunden, **belegt ist es
nicht**.

**Und die Zählung je Seite ist eine Untergrenze.** Der Ausdruck, der die
Aufrufe den Seiten zugeordnet hat, löst Hilfsmethoden **eine Ebene tief** auf.
Für die Übersicht fand er `system.info`; `OverviewController::postgresUnits()`
ruft daneben `pg.server.info` und lag eine Ebene zu tief.

> **Ein Ausdruck, der Hilfsmethoden eine Ebene tief auflöst, zählt die zweite
> Ebene nicht — und meldet trotzdem eine Zahl.**

---

## 2. Was daraus folgt: eine Seite, ein Aufruf

**Der teuerste Aufruf ist 3033 ms, der zweitteuerste 104.** Ein Faktor von
dreissig, und dazwischen liegt nichts. Damit ist die Frage nach dem Umfang
nicht eine Frage nach der Schwelle, sondern eine Ablesung.

Die Schwelle steht trotzdem im Plan, und zwar bei **300 ms** — nicht um heute
etwas zu trennen, sondern damit die nächste langsame Stelle nicht wieder
diskutiert werden muss. Ihre Begründung ist die Wahrnehmung und nicht die
Bequemlichkeit: Ein Platzhalter, der nach 100 ms verschwindet, ist ein
Flackern. Er macht die Seite unruhiger, nicht schneller.

> **Ein Ladezustand, der kürzer steht als der Blick braucht, ist kein Hinweis —
> er ist eine Bewegung ohne Aussage.**

**Gebaut wird also genau eine Stelle:** die Eigenschaft `packages` auf
`/updates`. Alles andere bleibt, wie es ist.

### 2.1 Und `/updates` wird dabei nicht leer

Gemessen an der Vorlage: `packages` speist **drei** Stellen — die Kachelreihe
oben, „Pakete" und „Unbeaufsichtigte Updates". Der Bereich „Paketquellen" hängt
an `sources` und kostet 35 ms.

> **Hier stand „zwei Bereiche", und das war beim Bauen falsch.** Gezählt hatte
> ich die `<Section>`-Blöcke; die Kachelreihe ist keiner und hängt trotzdem an
> demselben Wert. Ohne sie stünde `.tiles` mit seinen zwei Haarlinien als 2 px
> hohe Leerzeile da und spränge nach drei Sekunden auf 96.
>
> **Eine Aufzählung, die nach der Form der Bausteine zählt statt nach dem, was
> sie speist, lässt genau den weg, der anders gebaut ist.**

`sources` bleibt deshalb **synchron**. Die Seite kommt mit ihrer Überschrift,
ihrer Navigation und einem fertigen Bereich, und nur die beiden teuren stehen
als Skeleton da. Das ist der Unterschied zwischen „die Seite lädt" und „die
Seite ist da, ein Teil fehlt noch".

---

## 3. Die drei Entscheidungen des Betreibers

Entschieden am 10. September 2026, vor dem Bau:

1. **Umfang:** nur die gemessen langsamen Stellen, mit Schwelle. Nicht alle zehn
   Agent-Seiten und erst recht nicht alle Seiten.
2. **Der Fortschrittsbalken bleibt** und bekommt die Farbe des
   Gestaltungssystems.
3. **Der Skeleton bekommt eine Schimmer-Animation** — mit ihrer Ausnahme für
   `prefers-reduced-motion`, die zur Entscheidung gehörte.

---

## 4. Warum der Skeleton „deferred props" ist und nichts anderes

**Ein Platzhalter während der Navigation ist unmöglich, solange die langsame
Frage im Renderweg steht.** Inertia schickt die Antwort erst, wenn der
Controller fertig ist; bis dahin steht der Browser auf der **alten** Seite. Ein
Skeleton hätte dort nichts, worauf er sich malen liesse.

> **Ein Ladezustand setzt voraus, dass die Seite schon da ist. Wer ihn ohne das
> bauen will, baut eine zweite Seite.**

Gemessen ist, dass die eingebaute Fassung es auf **beiden** Seiten kann:

- **Server:** `Inertia::defer(callable $callback, string $group = 'default',
  bool $rescue = false)` in `ResponseFactory`.
- **Klient:** `<Deferred>` und `<WhenVisible>` in `@inertiajs/vue3`.

`<Deferred data="packages">` verlangt einen `#fallback`-Slot — **ohne ihn wirft
die Komponente**, gemessen im Bündel. Sie kennt daneben `#default` und, wenn der
Server die Eigenschaft als gerettet markiert, `#rescue`; die Slot-Eigenschaft
heisst `reloading`.

**Gebaut ist sie trotzdem nicht, und das ist eine Messung.** Wer das Nachladen
auslöst, war die Frage — und es ist **nicht** die Komponente. Im Bündel von
`@inertiajs/core` tut es der Router selbst:
`page.set()` → `fireInternalEvent('loadDeferredProps')` →
`doReload({ only: … })`, und zwar **eine** Anfrage je Gruppe. `<Deferred>`
registriert nur Listener für sein `reloading` und wählt zwischen zwei Slots.

Damit ist sie hier Zucker, und sie kostet etwas: Ihr Rumpf müsste den ganzen
Bereich „Pakete" umschliessen — 290 Zeilen zwei Stellen tiefer eingerückt, für
eine Änderung von sechs. Ein Umbruch ohne Inhalt begräbt den Inhalt.

Gebaut ist stattdessen ein `v-if` auf `props.packages === undefined` — dieselbe
Unterscheidung, die die Kachelreihe und der Prop-Typ ohnehin treffen:
**`undefined` heisst unterwegs, `null` heisst ausgefallen.** Eine Seite mit zwei
Sprachen für denselben Zustand hätte eine zu viel.

> **Ein Wächter, der ein Werkzeug verlangt, prüft das Werkzeug. Der Zustand
> darunter ist die Regel** — und `DeferredPropTest` liest deshalb beide Formen,
> auch die, die es hier heute nirgends gibt.

**`rescue` wird hier nicht gebraucht**, und das ist eine Messung und keine
Vorliebe: `UpdatesController::read()` fängt die `AgentException` schon selbst
und legt den Satz nach `errors['packages']`. Dieser `try`/`catch` zieht mit in
den Rückruf um, und der bestehende `notice critical` steht dann im
`#default`-Slot. Der Fehlerweg ändert sich damit nicht — er kommt nur später.

---

## 5. Die Form des Skeletons

**Er wohnt in `app.css` und nirgends sonst.** Eine Komponente, die ihren eigenen
Platzhalter gestaltet, ist derselbe Fehler wie ein Hexwert in einer Komponente.

Vorgesehen ist ein Baustein `.skeleton` mit den Formen, die diese Seite braucht
— eine Zeile, ein Block, eine gestapelte Zeile. Seine Fläche kommt aus den
bestehenden Marken; **eine neue Farbe gibt es nicht**, und ob eine gebraucht
wird, entscheidet die Kontrastrechnung und nicht der Eindruck.

**Der Schimmer bekommt seine eigene `prefers-reduced-motion`-Ausnahme nicht —
sie gibt es schon, und zwar für alle.** Ganz unten in `app.css` steht seit
langem eine `*`-Regel, die jede Animation anhält. Eine zweite wäre ihre zweite
Fassung.

> **Eine Ausnahme für eine Einstellung, die man selbst nicht benutzt, prüft
> niemand beim Ansehen** — und deshalb gehört sie an genau eine Stelle.

**Ob sie wirklich anhält, war eine Vermutung und ist jetzt gemessen.** Die
Regel setzt `animation-duration: 0.01ms !important`, und eine Dauer von
0,01 ms bei `infinite` könnte auch heissen: jedes Bild eine andere Phase, also
Flackern statt Stillstand. Gemessen im Container gegen echtes Chromium, je
Lauf 40 Bilder:

| | verschiedene Werte über 40 Bilder |
|---|---|
| ohne die Einstellung | **30** (jedes Bild ein anderer) |
| mit `prefers-reduced-motion: reduce` | **1** ab dem zweiten Bild |

Kein Flackern, sondern Stillstand — die Regel trägt.

**Die Ruhelage ist dabei beliebig:** Zwei Läufe endeten bei `40%` und bei
`-60%`. Deshalb ist der Verlauf flach und breit gehalten, damit ein
eingefrorenes Bild an jeder Stelle wie derselbe graue Balken aussieht.

> **Eine Regel, die die Bewegung anhält, sagt nichts darüber, in welchem Bild
> sie stehenbleibt.**

Und eine Beobachtung über das Messmittel: `emulateMedia({ reducedMotion })`
**erreicht** den Prüfling — anders als `emulateMedia({ colorScheme })` in der
A5-Bildrunde, das ins Leere lief, weil `app.css` das Thema an `data-theme`
hängt und nicht an `prefers-color-scheme`. Dasselbe Werkzeug, zwei Ausgänge,
und unterschieden hat sie nur die Messung.

**Der Skeleton bildet nach, was kommt, und nicht irgendetwas.** Eine Tabelle
wird zu Zeilen, ein Kärtchen zu einem Kärtchen. Er trägt dabei **keine**
Tabellenform aus `MobileTableTest` (`stacks`, `pairs`, `rows`) — er ist keine
Tabelle, und eine Form zu nennen, die er nicht hat, wäre eine Zusage über eine
Zelle, die es nicht gibt.

---

## 6. Der Fortschrittsbalken

**Es gibt ihn längst, und er ist blau.** `resources/js/app.ts` konfiguriert ihn
nirgends, also gilt Inertias Voreinstellung — gemessen im Bündel:

```
delay = 250, color = "#29d", includeCSS = true, showSpinner = false
```

Damit steht auf jeder Seite dieses Panels eine Farbe, die `app.css` nicht kennt
und die **kein Wächter sehen kann**: Sie kommt nicht aus dem Quelltext, sondern
wird zur Laufzeit von der Bibliothek ins Dokument geschrieben.

> **Ein Wächter über den Quelltext sieht keine Farbe, die das Framework zur
> Laufzeit einsetzt.** Derselbe Satz wie bei den englischen Prüfmeldungen aus
> `docs/903 §11.1`, nur an einer Farbe statt an einem Satz.

Der Balken bleibt — er beantwortet eine andere Frage als der Skeleton: Er sagt,
dass überhaupt etwas unterwegs ist, auch auf den neun Seiten ohne Skeleton und
bei jedem Absenden eines Formulars. Er bekommt die Akzentfarbe, und zwar aus
**einer** Quelle: dem Wert, den `app.css` ohnehin führt.

**Die 250 ms bleiben ebenfalls.** Sie sind der Grund, aus dem auf einer schnellen
Seite gar nichts blinkt — dieselbe Überlegung wie bei der Schwelle in §2.

---

## 7. Die Nähte — gemessen und nicht angenommen

Ein `Inertia::defer()` löst eine **zweite GET-Anfrage** auf dieselbe Adresse
aus. Sie läuft durch dieselbe Mittelschicht wie jede Navigation, und dieses
Panel hat dort zwei Dinge stehen, die davon betroffen sein könnten. Beide sind
am Quelltext nachgesehen:

**`RememberPageUrl` schreibt bei jeder Inertia-GET-Anfrage unter 300**, ohne
zwischen ganzer Seite und Teil-Nachladen zu unterscheiden. Das Nachladen setzt
`_previous.url` also noch einmal — auf **dieselbe** Adresse, denn die Angaben
eines Teil-Nachladens reisen in Kopfzeilen (`X-Inertia-Partial-Data`) und nicht
in der Adresse. Der Wert ändert sich damit nicht.

**`Origin::current()` liest die Kopfzeile aus der laufenden Anfrage** und legt
nichts ab. Das Nachladen trägt zwar `X-Srvpanel-Origin` — der `before`-Hook
läuft für jeden Besuch —, aber ein `GET` legt keinen Vorgang an, und damit liest
sie niemand.

**Beides ist hergeleitet und gehört gemessen.** Der Abnahmelauf misst es (§10,
Punkte 7 und 8): Eine Herleitung, die stimmt, ist keine Messung, und welche von
beiden dasteht, sieht man ihr später nicht an.

---

## 8. Die Schritte

1. **Der Baustein.** `.skeleton` in `app.css`, mit seinen Formen, seinem
   Schimmer und der Ausnahme für `prefers-reduced-motion`. Keine neue Farbe ohne
   gerechneten Kontrast.
2. **Der Fortschrittsbalken.** `app.ts` konfiguriert `progress` mit der
   Akzentfarbe aus dem Gestaltungssystem, gelesen aus der einen Quelle.
3. **`/updates` stellt um.** `packages` wandert in `Inertia::defer()`, `sources`
   bleibt synchron. Die beiden Bereiche bekommen `<Deferred data="packages">`
   mit ihrem `#fallback`.
4. **Die Wächter** (§9), jeder mit seinem Bruch im Bruchskript.
5. **Die Bilderrunde** über den **neuen** Zustand — vier Lagen auf `/updates`
   im Skeleton und vier im geladenen Zustand.
6. **Der Abnahmelauf** (§10) auf `cloudsrv24`, ausgeschrieben **vor** dem
   Fahren.

---

## 9. Die Wächter

**`DeferredPropTest`** — drei Regeln. Jede **Gruppe**, die ein Controller über
`Inertia::defer()` schickt, hat auf ihrer Seite einen Zweig für „noch
unterwegs"; jede nachgereichte Eigenschaft ist als `name?:` deklariert; und
**umgekehrt** gibt es keinen solchen Zweig ohne nachgereichte Eigenschaft.

Je Gruppe und nicht je Eigenschaft, und das ist gemessen: Inertia lädt eine
Gruppe in **einer** Anfrage nach, `packages` und `packagesError` kommen also
zusammen an. Ein Wächter je Eigenschaft verlangte einen zweiten Zweig, der nie
eine andere Antwort gäbe als der erste.

Die zweite Richtung ist die, an der ein toter Eintrag wirklich entsteht: Wer das
Nachreichen zurücknimmt, ändert den Controller — und der Zweig auf der Seite
bleibt stehen, wird nie gezeigt und sieht im Quelltext aus wie ein abgedeckter
Zustand.

**`SkeletonStyleTest`** — das Aussehen eines Skeletons steht ausschliesslich in
`app.css`, es gibt **genau einen** `prefers-reduced-motion`-Block, er trifft
`*`, und **keine Animation stellt sich mit `!important` über ihn**. Die
Ausnahme, die dieser Absatz beim Schreiben des Plans noch verlangt hat, ist
damit ersetzt durch die Bedingung, unter der die vorhandene Regel für den
Platzhalter gilt (§5). Die letzte Hälfte ist die stille: `!important` schlägt
`!important` über die Spezifität, und die einer Klasse ist höher als die von
`*` — eine solche Zeile sieht auf einem Gerät ohne die Einstellung völlig
richtig aus.

**`ProgressColourTest`** — `app.ts` konfiguriert den Fortschrittsbalken
überhaupt (**das Fehlen ist der Fehler**, denn ohne Angabe gilt `#29d`), die
Farbe ist eine Marke, die `app.css` wirklich führt, im Einstieg steht kein
Hexwert, und die Verzögerung bleibt bei Inertias 250 ms. Er streift die
Kommentare ab, bevor er sucht — **vorsorglich**, seit der Einstieg den
Vorgabewert nicht mehr zitiert (§10a).

Was er **nicht** kann, steht in seinem Kopf: Ob die Farbe im Browser wirklich
ankommt, misst er nicht — das tut Punkt 5 des Abnahmelaufs.

Jeder der drei bekommt seinen Eingriff in `tests/waechter-brechen.sh`, einzeln
gefahren und rot belegt.

---

## 10. Das Abnahmekriterium

Gefahren auf `cloudsrv24`, jeder Punkt mit seinem gemessenen Wert.

1. **Die Hülle kommt zuerst.** `/updates` liefert seine Antwort in **unter
   300 ms** statt in 3068. Gemessen an der Zeit bis zur ersten Antwort, nicht
   am Gefühl.
2. **Der Bereich „Paketquellen" ist sofort gefüllt**, während „Pakete" und
   „Unbeaufsichtigte Updates" als Skeleton stehen — und **die Kachelreihe steht
   mit ihren fünf Beschriftungen da**, nur ohne Zahlen (§2.1).
3. **Der Skeleton wird ersetzt**, und zwar nach den gemessenen drei Sekunden,
   ohne dass die Seite springt. Gemessen in **einem** Seitenaufbau — Höhe
   vorher, Anfrage durchlassen, Höhe nachher —, denn zwei getrennte Läufe
   verglichen zwei Seiten und nicht zwei Zustände derselben. Im Container ist
   der Sprung 0 px bei 390 und bei 1440 (§10a); auf dem Server ist er zu
   wiederholen, weil dort echte Zahlen in den Kacheln stehen.
4. **Bei totem Agenten** steht dort der bestehende `notice critical` und **kein
   endloser Skeleton**. *(Dieser Punkt darf nicht ausfallen — ein Platzhalter,
   der bei einem Fehler stehenbleibt, ist schlimmer als der Fehler.)*
5. **Die Farbe des Fortschrittsbalkens** ist im Browser die Akzentfarbe,
   gemessen über `getComputedStyle` und nicht am Eindruck.
6. **`prefers-reduced-motion: reduce`** hält den Schimmer an. Gemessen mit der
   Gegenprobe, dass er ohne die Einstellung läuft — eine Messung ohne sie sagt
   nur, dass sich nichts bewegt.
7. **Der Weg zurück lebt.** Nach einem Besuch auf `/updates` führt „Zurück" noch
   dorthin, wo es vorher hinführte (§7).
8. **Das Nachladen legt nichts an** — kein Vorgang, keine Protokollzeile.
   *(Dieser Punkt darf nicht ausfallen: Eine zweite Anfrage je Seitenaufruf, die
   ins Protokoll schreibt, verdoppelte den Bestand.)*
9. **Die Bilderrunde**, vier Lagen je Zustand, `dokument = 0`, Gegenprobe 200.
   **Der Skeleton-Zustand ist ein Zustand, den noch nie jemand gemessen hat.**

---

## 10a. Was beim Bauen anders war als im Plan

Gebaut am 10. September 2026. **Sieben Befunde, und vier davon hat kein
Nachdenken gefunden, sondern ein Wächter oder eine Messung.**

### Der grösste: die Seite ist gesprungen

Punkt 3 verlangt „ohne dass die Seite springt", und der erste Wurf tat genau
das. Gemessen in **einem** Seitenaufbau — Höhe vorher, Anfrage durchlassen,
Höhe nachher:

| Breite | Kachelreihe vorher | nachher | Sprung |
|---|---|---|---|
| 390 px | 479,69 px | 459,69 px | **−20 px** |
| 1440 px | 99,94 px | 95,94 px | **−4 px** |

Alles darunter zog mit, gemessen an der Oberkante des Bereichs „Pakete": exakt
derselbe Versatz. Die Ursache war ein `margin: 2px 0` am Kachelplatzhalter —
aus Gefühl gesetzt und gegen nichts gemessen. Vier Pixel je Kachel; bei 1440 px
stehen die fünf nebeneinander, bei 390 px stapeln sie sich, daher 4 gegen 20.

**Die Höhe selbst stimmte von Anfang an:** `1.05em` ergibt 35,69 px, und genau
so hoch ist der Textkasten der geladenen Kachel. Der Rand war der ganze Fehler.

> **Ein Platzhalter, der nicht genau so hoch ist wie das, was er vertritt,
> verschiebt alles darunter — und auf dem Bild sieht das nach nichts aus.**

Nach dem Entfernen: **0 px** an beiden Breiten, für die Kachelreihe wie für den
Bereich darunter.

### Der teuerste: die Bilderrunde hat den vorigen Stand gemessen

Die erste vollständige Runde lief gegen ein `public/build`, das vor der letzten
CSS-Änderung entstanden war. `artisan serve` liefert von dort, und der Fehler
ist in CLAUDE.md als „zweimal darauf hereingefallen" vermerkt — dies war das
dritte Mal.

**Das Bild hat es nicht verraten.** Es zeigte vier Balken verschiedener Länge,
also genau das, was der Entwurf will. Gefunden hat es erst der Blick auf die
**Klassennamen im DOM**: Dort standen `skeleton line medium` und
`skeleton line short` — zwei Klassen, die es im Quelltext nicht mehr gab.

> **Ein Bild, das plausibel aussieht, belegt nicht, dass es den gebauten Stand
> zeigt. Die Klassennamen im DOM sagen es, die Pixel nicht.**

### Ein Befund am Bestand: `errors` hat die Prüfmeldungen verdeckt

`/updates` schickte einen eigenen Fehlerbeutel `errors`, und Inertia lässt
Seitenwerte geteilte überschreiben. Damit war
`HandleInertiaRequests::resolveValidationErrors()` auf dieser einen Seite fort,
und die Zusammenfassung oben — die genau dafür dasteht — hat nie eine
Prüfmeldung gezeigt.

> **Ein geteilter Schlüssel, den eine Seite auch benutzt, ist auf genau dieser
> Seite fort — und der Ausfall liest sich wie ein Rechteproblem.**

Derselbe Satz steht seit `docs/82` Schritt 5 über `can` gegen `abilities`; hier
war es `errors`. Behoben nebenbei, weil die Umstellung den Beutel ohnehin
auftrennen musste: `packagesError` reist nachgereicht, `sourcesError` synchron.

### Drei Wächter haben zugebissen, und zwei waren blind

**`StandaloneClassTest`** hat einen Klassennamen abgefangen, den es schon gibt:
Der erste Wurf hiess `.skeleton.line.short`, und `.short` bedeutet in diesem
Stylesheet „ein `.code`, dessen Inhalt eine bekannte Länge hat".

> **Ein Klassenname mit zwei Bedeutungen ist global — und die zweite Bedeutung
> trifft jede Stelle, die die erste meint.**

Die Breiten stehen seitdem am Stapel (`:nth-child(2n)`, `:last-child`) statt als
Klassen an den Zeilen. Das ist ohnehin richtiger: „unterschiedlich lang" ist
eine Eigenschaft des Absatzes und nicht der Zeile.

**`AgentMessageTest`** war blind, und zwar nicht erst seit heute. Sein Filter
lautete `\berrors?\b` — zwischen `s` und `E` in `packagesError` steht keine
Wortgrenze, beide sind Wortzeichen. Gemeldet hat es die **Untergrenze**: zehn
Einbettungen erwartet, acht gefunden.

Nachgemessen erreicht `/errors?\b/i` **zwölf**, und nur zwei davon sind an
diesem Tag entstanden. `dump.last_error` in `Databases/Show.vue` und
`fieldError('plan')` in `Plans/Form.vue` standen längst da und waren nie
im Blick des Wächters. Beide halten die Regel — gemessen, nicht angenommen.

> **Eine Untergrenze ist kein Formalismus — sie ist die einzige Stelle, an der
> ein Wächter merkt, dass sein Ausdruck ins Leere greift.**

**`InertiaPropsTest`** hat den Payload als unvollständig gemeldet, weil die
Schlüssel als `...`-Streuung ins Feld kamen. Das ist die freundliche Richtung:
Er hat sie als **fehlend** gemeldet und nicht als „nicht nachgesehen".

> **Ein Wächter, der einen Ausdruck nicht auflösen kann, meldet im besten Fall
> zu viel — und der beste Fall ist der, den man sich aussucht.**

Die Schlüssel stehen seitdem ausgeschrieben, und das ist auch für einen Leser
besser: Wer im Controller steht, will sehen, was die Seite bekommt.

### Und ein Befund kam aus der CI, nicht von hier

Der Schritt **„Oberfläche"** prüft `resources/js` auf Farbwerte — mit einem
`grep` und **ohne die Kommentare abzustreifen**. Der Dokumentblock über
`progress:` zitierte Inertias Vorgabewert wörtlich, um zu erklären, welchen Wert
die Zeile darunter ersetzt, und damit war die CI rot für einen Hexwert, den es
im Code nicht gibt.

> **Derselbe Kommentar, der einen Wächter fälschlich grün hält, macht eine
> Messung fälschlich rot.**

Die Ironie gehört zum Befund: `ProgressColourTest` streift die Kommentare ab —
genau dafür, und in beide Richtungen gemessen. Blind war nicht der frisch
gebaute Wächter, sondern der blosse `grep` daneben, an den niemand gedacht hat.

> **Zwei Prüfungen derselben Regel, von denen eine die Kommentare kennt und die
> andere nicht, widersprechen sich beim ersten Zitat.**

**Behoben ist es an der Prosa und nicht am `grep`.** Der blosse Ausdruck schützt
jede Komponente dieses Panels; ihn für einen Satz in einem Dokumentblock
aufzuweichen wäre die teurere Seite des Tauschs. Der gemessene Vorgabewert steht
seitdem in §6 dieses Dokuments, und im Einstieg steht ein Satz, der vor der
Falle warnt.

### Und zwei Fehler des eigenen Prüfmittels

Der Läufer für einzelne Eingriffe des Bruchskripts kannte **einen**
Python-Block je Eingriff; zwei Eingriffe haben zwei. Er führte beide als eine
Quelle aus, was kein gültiges Python ist, und meldete „der Eingriff selbst
scheitert" — also *stumm* statt *ohne Biss*. Berichtigt beissen beide.

> **Ein Prüfkörper, der eine andere Form misst als die des Prüflings, misst die
> falsche.**

Und derselbe Läufer sicherte nur die eine Datei, die `vorher_datei` nennt.
Dieselben zwei Eingriffe fassen drei weitere an; die blieben verändert liegen
und sind nur aufgefallen, weil `git status` danach gelesen wurde.

> **Ein Rückweg, der eine Datei kennt, ist keiner für einen Eingriff, der drei
> anfasst.**

### Die Messung selbst

Gefahren mit einem Agenten im Container: `system.packages.list` braucht
`/usr/lib/srvpanel/apt-run` und `systemd-run`, und das zweite braucht systemd
als PID 1. Der Ausweg ist die Attrappe in einer eigenen Mount-Namespace
(CLAUDE.md). Gemessen kostet der Aufruf hier **3529 ms** — dieselbe
Grössenordnung wie die 3033 ms auf `cloudsrv24`.

Der Platzhalterzustand wurde photographiert, indem die **nachgereichte
Anfrage im Browser angehalten** wurde (sie trägt `X-Inertia-Partial-Data`).
Das ändert am Prüfling keine Zeile und hält ihn beliebig lange in genau dem
Zustand, um den es geht; das Durchlassen ist die Gegenprobe.

**Acht Lagen, alle mit `dokument = 0` und Gegenprobe 200:**

| | Kachelreihe 390 px | 1440 px | Platzhalter |
|---|---|---|---|
| unterwegs | 459,69 px | 95,94 px | 11 |
| geladen | 459,69 px | 95,94 px | 0 |

---

## 11. Was der Skeleton ausdrücklich nicht wird

- **Kein Zwischenspeicher für `apt`.** Die drei Sekunden bleiben drei Sekunden;
  der Skeleton verdeckt sie nicht, er macht sie erträglich. Ein Zwischenspeicher
  wäre eine Aussage über die Frische der Zahlen, und das ist eine andere
  Entscheidung.
- **Kein Vorausladen.** `/updates` beim Überfahren des Menüpunkts anzustossen
  hiesse, alle drei Sekunden apt zu fahren, weil jemand die Maus bewegt hat.
- **Keine Umstellung der neun übrigen Agent-Seiten.** Gemessen sind sie unter
  123 ms; dort ist nichts zu gewinnen (§2).
- **Keine Änderung am Agenten.** Was `system.packages.list` tut und wie lange es
  braucht, bleibt, wie es ist.
- **Nichts an den beiden ungemessenen Aufrufen** (`system.logs.tail`,
  `web.logs.tail`). Sie stehen in §1 als ungemessen da und nicht als schnell.
