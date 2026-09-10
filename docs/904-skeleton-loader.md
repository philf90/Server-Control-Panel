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

Gemessen an der Vorlage: `packages` speist **zwei** Bereiche — „Pakete" und
„Unbeaufsichtigte Updates". Der dritte, „Paketquellen", hängt an `sources` und
kostet 35 ms.

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

**Der Schimmer hat eine Ausnahme, und sie ist der Teil, der still verrottet:**

```css
@media (prefers-reduced-motion: reduce) {
  .skeleton { animation: none; }
}
```

Ohne sie bewegt sich auf dem Gerät eines Menschen, der Bewegung abgestellt hat,
trotzdem etwas — und niemand von uns bemerkt es, weil unser Gerät sie nicht
abgestellt hat.

> **Eine Ausnahme für eine Einstellung, die man selbst nicht benutzt, prüft
> niemand beim Ansehen.**

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

**`DeferredPropTest`** — jede Eigenschaft, die ein Controller über
`Inertia::defer()` schickt, hat auf ihrer Seite ein `<Deferred>`, das sie beim
Namen nennt, **und umgekehrt**. Die zweite Richtung ist die, an der ein toter
Eintrag wirklich entsteht: Wer das Deferieren zurücknimmt und das `<Deferred>`
stehenlässt, bekommt einen Platzhalter, der nie verschwindet.

**`SkeletonStyleTest`** — das Aussehen eines Skeletons steht ausschliesslich in
`app.css`, und **der Schimmer trägt seine `prefers-reduced-motion`-Ausnahme**.
Die zweite Hälfte ist der Grund für diesen Wächter; die erste hielte
`ClassReachTest` ohnehin halb.

**`ProgressColourTest`** — `app.ts` konfiguriert den Fortschrittsbalken, und der
Wert kommt nicht als Hexliteral im Quelltext vor. Was er **nicht** kann, steht
in seinem Kopf: Ob die Farbe im Browser wirklich ankommt, misst er nicht — das
tut Punkt 5 des Abnahmelaufs.

Jeder der drei bekommt seinen Eingriff in `tests/waechter-brechen.sh`, einzeln
gefahren und rot belegt.

---

## 10. Das Abnahmekriterium

Gefahren auf `cloudsrv24`, jeder Punkt mit seinem gemessenen Wert.

1. **Die Hülle kommt zuerst.** `/updates` liefert seine Antwort in **unter
   300 ms** statt in 3068. Gemessen an der Zeit bis zur ersten Antwort, nicht
   am Gefühl.
2. **Der Bereich „Paketquellen" ist sofort gefüllt**, während „Pakete" und
   „Unbeaufsichtigte Updates" als Skeleton stehen.
3. **Der Skeleton wird ersetzt**, und zwar nach den gemessenen drei Sekunden,
   ohne dass die Seite springt.
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
