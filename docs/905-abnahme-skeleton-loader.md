# Abnahmelauf: der Platzhalter für `/updates`

**Ausgeschrieben am 11. September 2026, vor dem Fahren.** Gegen die Fassung,
die `docs/904` gebaut hat, auf `cloudsrv24`. Der Plan ist `docs/904`, das
Protokoll bekommt die nächste freie Nummer.

Er liegt im 900er-Block neben seinem Plan und nicht in der laufenden Zählung —
ein Lauf, den achtzig Nummern von seinem Plan trennen, wird nicht neben ihm
gelesen (`docs/900`).

---

## §0 Was beim Ausschreiben umgefallen ist

**Drei Punkte aus `docs/904 §10` haben sich beim Hinschreiben als nicht fahrbar
erwiesen, einer ist ersetzt und einer neu — und ein vierter ist beim Fahren
umgefallen.** Das ist der übliche Ertrag dieses
Schritts; er ist billiger als ein falsches Rot im Lauf.

### 1. Die 300 ms von Punkt 1 waren nie gemessen

Der Punkt lautete „`/updates` liefert seine Antwort in **unter 300 ms** statt in
3068". **Die Zahl stammt aus `docs/904 §2` und bedeutet dort etwas anderes:**
Sie ist die Schwelle, ab der ein Platzhalter überhaupt gezeigt wird — eine
Aussage über die *Wahrnehmung*, nicht über ein Antwortbudget. Zwei verschiedene
Grössen, dieselbe Zahl.

> **Eine Zahl in einer Erwartung, die man nicht gezählt hat, ist eine Vermutung
> mit Anspruch.**

Gemessen im Container ist die Hülle **820–867 ms**, und das ist kein Mangel des
Panels: `system.sources.list` kostet hier **806–848 ms** (fünf Quelleinträge mit
Schlüsselbunden, je ein `gpg --show-keys`), auf `cloudsrv24` dagegen 35 ms. Die
Hülle ist also fast genau dieser eine Aufruf.

Punkt 1 misst deshalb **das Verhältnis und nicht eine absolute Zahl**: Die Hülle
muss ungefähr so teuer sein wie `system.sources.list` allein, und der teure
Aufruf muss nachweislich *neben* ihr laufen. Das hält auf jeder Maschine.

### 2. `prefers-reduced-motion` ist keine Eigenschaft dieses Servers

Punkt 6 verlangte, dass die Einstellung den Schimmer anhält. Das ist eine
Eigenschaft des **Browsers und des Geräts** und auf `cloudsrv24` nicht anders
als anderswo; gemessen ist es bereits gegen echtes Chromium (`docs/904 §5`:
40 Bilder, 30 Werte ohne die Einstellung, einer mit ihr).

Was der Server beantworten kann, ist etwas anderes: **ob die ausgelieferte
Fassung die Regel überhaupt mitbringt** und ob ihr keine zweite widerspricht.
Punkt 6 fragt das.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

### 3. Punkt 5 lässt sich auf `/updates` gar nicht messen

Er misst die Farbe des Fortschrittsbalkens — und die Hülle kommt so schnell,
dass Inertias Verzögerung von 250 ms ihn dort nicht zeigt. Ob eine
**nachgereichte** Anfrage ihn auslöst, stand nirgends.

**Hier stand „gemessen: ja, erstes Bild bei t = 240 ms". Das war falsch, und
der Fehler steckte im Prüfkörper.** Er fragte
`document.querySelector('#nprogress .bar')` — also nach dem **Element**. Im
Bündel gemessen:

- `doReload()` setzt **`async: true`**, und `showProgress` ist
  `options.showProgress ?? (!options.async || !!options.optimistic)` — für jede
  nachgereichte Anfrage also **`false`**.
- `hide()` setzt `display: none` und **lässt das Element im DOM**.

Der Prüfkörper fand damit ein unsichtbares Element und las seine Farbe.

> **Ein Prüfkörper, der nach dem Element fragt, hat nicht nach der Anzeige
> gefragt.**

Auf dem Server fiel es auf, weil dort gar nichts gestartet war: `gesehen: 0`,
`farben: []`, in beiden Themen — bei korrekten Marken (`#3730a3` hell,
`#ff7fec` dunkel).

**Dass der Balken beim Nachreichen schweigt, ist richtig** und kein Mangel: Ein
Nachladen im Hintergrund soll nicht blinken. Gemessen wird er deshalb an einer
**gewöhnlichen** Navigation, und damit die über 250 ms dauert, wird die Leitung
gedrosselt. Die Drosselung ändert am Prüfling nichts — sie stellt die
Bedingung her, für die es den Balken gibt.

### 4. Der Agent heisst nicht, wie hier dreimal stand

**Gefunden beim Fahren, nicht beim Ausschreiben.** Die Unit heisst
`srvpanel-agentd.service`; dieses Dokument nannte an drei Stellen
`srvpanel-agent`, darunter **§6 — ein Ausschlusskriterium**.

Der Schaden wäre still gewesen: `systemctl stop srvpanel-agent` hält nichts an
und gibt keinen Fehler, der auffällt. `/updates` hätte danach ganz normal
geladen, ohne Platzhalter und ohne Streifen — und genau das ist die Anzeige,
die Punkt 4 als „erfüllt" wertet. Ein Kriterium, das den Zustand nie
hergestellt hat, den es prüfen soll.

> **`systemctl is-active` meldet für eine Unit, die es nicht gibt, `inactive` —
> ununterscheidbar von einer, die angehalten ist.**

Aufgefallen ist es nur, weil §2 den Zustand **mitdruckt**: Dort stand
`inactive` für den Agenten, während zwei Agentenaufruf in derselben Minute
antworteten. Ein Widerspruch in zwei nebeneinanderstehenden Zeilen.

> **Eine Messung, die ihren Zustand nicht mitdruckt, ist von einer, die ihn
> nicht hatte, nicht zu unterscheiden.**

`UnitNameReachTest` hält seitdem, dass ein `srvpanel-*`-Unitname in einer
Vorschrift oder einem Skript auf eine paketierte Unit zeigt.

### Ein Punkt ist ersetzt, einer ist neu

**Punkt 7 hiess im Plan „Der Weg zurück lebt"** und sollte die Naht zu
`RememberPageUrl` messen. Er misst jetzt, ob eine **Prüfmeldung oben ankommt** —
und das ist nicht weniger, sondern beides: `back()->withErrors()` landet nur
dann auf `/updates`, wenn die nachgereichte Anfrage `_previous.url` nicht
verdorben hat. Die Meldung zu sehen belegt den Rückweg mit; ihn allein zu
belegen sagt über die Meldung nichts.

Dazu misst er die Behebung, die beim Bauen nebenbei herausfiel: `/updates`
schickte einen eigenen Fehlerbeutel `errors` und verdeckte damit Laravels
Prüfmeldungen auf genau dieser Seite (`docs/904 §10a`). Ohne diesen Punkt wäre
sie ungemessen ausgeliefert.

> **Zwei Fragen, von denen die eine die andere einschliesst, sind ein Punkt und
> nicht zwei — und der Punkt ist die engere.**

**Punkt 10** fragt, ob das Panel während der drei Sekunden bedienbar bleibt.
**Der Container kann das grundsätzlich nicht beantworten:** `artisan serve`
bedient eine Anfrage gleichzeitig, dort steht jede Navigation ohnehin an. Auf
php-fpm entscheidet die Sitzungssperre, und die ist eine Eigenschaft des
Servers.

> **Eine Frage, die das Prüfmittel selbst beantwortet, ist an ihm nicht
> messbar.**

---

## §1 Was vorher zu lesen ist

`docs/904` ganz, besonders §1 (die Messrunde), §4 (warum nachgereicht) und
**§10a** (was beim Bauen anders war als im Plan).

Aus `CLAUDE.md`: der Abschnitt „Ein Platzhalter für `/updates`" und, weil dieser
Lauf in der Konsole des Browsers stattfindet, die beiden Sätze über Inertia 3
aus dem Abschnitt „Adminkonten lassen sich löschen":

- Die **lebende** Ablage steht in
  `document.getElementById('app').__vue_app__.config.globalProperties.$page` —
  nicht in `dataset.page` und nicht im `script[data-page]`, das die Seite vom
  Laden trägt und jede Navigation überlebt.
- Geschrieben wird über **denselben Klienten**
  (`…globalProperties.$inertia`) und niemals über `fetch`: Eine
  `ValidationException` nimmt hier den HTML-Weg, `fetch` folgt der 302 mit
  derselben Methode, und heraus kommt ein Fehler an einer Adresse, die niemand
  gerufen hat.

**Und der Griff, der diesen Lauf trägt:** Navigiert wird mit
`$inertia.visit('/updates')` und **nicht** über die Adresszeile. Ein
Seitenaufbau nähme die Konsole mit; so überlebt das Messskript die Navigation
und sieht den Platzhalterzustand von innen. Gemessen im Container: 90 Proben
über 9 Sekunden, Platzhalterfenster 900–4900 ms.

---

## §2 Der Ausgangszustand

```
srvpanel version
systemctl is-active srvpanel-agentd srvpanel-worker
```

Notiert wird die Fassung. Angemeldet wird als **Betreiber** — die Seite gehört
seit dem 27. August beiden Rollen, aber die Punkte 4 und 8 fassen Dienste und
Protokoll an.

Und der Referenzwert, an dem Punkt 1 hängt:

```
srvpanel tinker --execute='
$c = app(\SrvPanel\Agent\Client::class);
foreach (["system.sources.list", "system.packages.list"] as $op) {
  $t = microtime(true); $c->call($op, []);
  printf("%-22s %5.0f ms%s", $op, (microtime(true)-$t)*1000, PHP_EOL);
}'
```

**Zweimal fahren.** Eine Messung, die man nur einmal macht, misst den
Zwischenspeicher mit — und `system.packages.list` ist der Fall, an dem das
letzte Mal das Gegenteil herauskam (warm eine Spur *langsamer*).

Erwartet nach `docs/904 §1`: `sources` ≈ 35 ms, `packages` ≈ 3000 ms.

---

## §3 Punkt 1 — die Hülle wartet nicht mehr auf apt

*(darf ausfallen)*

In der Konsole auf `/updates`, nach einem **zweiten** Aufruf, damit nicht der
kalte gemessen wird:

```js
const n = performance.getEntriesByType('navigation')[0]
const nach = performance.getEntriesByType('resource')
  .filter((r) => r.name.includes('/updates')).map((r) => Math.round(r.duration))
console.log({ huelle: Math.round(n.responseEnd - n.requestStart), nachgereicht: nach })
```

**Erfüllt, wenn beides gilt:**

1. `huelle` liegt in der Grössenordnung von `system.sources.list` aus §2 — als
   Grenze: **höchstens dessen Dreifaches plus 200 ms**. Der Zuschlag ist das
   Framework; der Faktor lässt Streuung zu, ohne die Grössenordnung
   freizugeben.
2. `nachgereicht` enthält einen Wert über **2000 ms**. Er ist der Beleg, dass
   der teure Aufruf *neben* der Hülle läuft und nicht in ihr.

> **Eine Hülle, die schnell ist, weil die Maschine schnell ist, belegt nichts.
> Eine, die so teuer ist wie der billige Aufruf allein, belegt es.**

**Und eine Messung, die kein Kriterium ist, gehört trotzdem hierher:** Liegt
`system.sources.list` auf diesem Server über **300 ms**, dann nennt die
Schwelle aus `docs/904 §2` die nächste Stelle, die nachgereicht gehört. Das ist
ein Befund für den Plan und kein Ausfall dieses Laufs — die Schwelle steht dort
ausdrücklich, „damit die nächste langsame Stelle nicht wieder diskutiert werden
muss".

---

## §4 Punkt 2 — die Seite ist da, ein Teil fehlt noch

*(darf ausfallen)*

Von einer **anderen** Seite aus (damit die Navigation wirklich stattfindet),
zum Beispiel `/services`:

```js
const g = document.getElementById('app').__vue_app__.config.globalProperties
const probe = () => ({
  t: Math.round(performance.now() - t0),
  url: g.$page.url,
  platzhalter: document.querySelectorAll('.skeleton').length,
  kacheln: document.querySelectorAll('.tile').length,
  kachelreihe: Math.round((document.querySelector('.tiles')?.getBoundingClientRect().height ?? 0) * 100) / 100,
  quellenzeilen: document.querySelectorAll('.stacks tbody tr, table.rows tbody tr').length,
})
const proben = []; const t0 = performance.now()
const takt = setInterval(() => proben.push(probe()), 100)
g.$inertia.visit('/updates')
setTimeout(() => {
  clearInterval(takt)
  const mit = proben.filter((x) => x.platzhalter > 0)
  console.log({
    fenster: mit.length ? `${mit[0].t}–${mit.at(-1).t} ms` : 'nie',
    platzhalterMax: Math.max(...proben.map((x) => x.platzhalter)),
    kachelnImFenster: [...new Set(mit.map((x) => x.kacheln))],
    quellenzeilenImFenster: [...new Set(mit.map((x) => x.quellenzeilen))],
  })
}, 9000)
```

**Erfüllt, wenn:**

- `fenster` ist nicht `nie` und dauert **über 1000 ms** — sonst gibt es den
  Zustand, um den es geht, auf diesem Server gar nicht.
- `platzhalterMax` ist **11**: fünf Kachelwerte, vier Zeilen unter „Pakete",
  zwei unter „Unbeaufsichtigte Updates".
- `kachelnImFenster` ist `[5]` — die Kachelreihe steht **mit ihren fünf
  Beschriftungen** da und nicht als 2-px-Leerzeile.
- `quellenzeilenImFenster` ist grösser als 0 — „Paketquellen" ist **gefüllt**,
  während die anderen beiden warten. Das ist der Unterschied zwischen „die Seite
  lädt" und „die Seite ist da, ein Teil fehlt noch".

Gemessen im Container: Fenster 900–4900 ms, `platzhalterMax` 11, Kacheln 5.

---

## §5 Punkt 3 — und die Seite springt dabei nicht

*(**darf nicht ausfallen**)*

Dies ist der Punkt, der beim Bauen einen Fehler gefunden hat: Der erste Wurf
sprang um 20 px bei 390 und 4 px bei 1440, und alles darunter zog mit
(`docs/904 §10a`). Die Behebung hat **keinen Server gesehen**.

Gemessen wird in **einem** Seitenaufbau — Höhe im Platzhalterzustand, Höhe
danach. Zwei getrennte Läufe verglichen zwei Seiten und nicht zwei Zustände
derselben.

Dasselbe Skript wie in §4, nur die Auswertung:

```js
const mit = proben.filter((x) => x.platzhalter > 0)
const ohne = proben.filter((x) => x.platzhalter === 0 && x.url.startsWith('/updates'))
console.log({
  kachelreiheMit: [...new Set(mit.map((x) => x.kachelreihe))],
  kachelreiheOhne: [...new Set(ohne.map((x) => x.kachelreihe))],
})
```

**Erfüllt, wenn beide Listen denselben einen Wert enthalten** — bei 1440 px und
**noch einmal bei 390 px**, denn dort stapeln sich die Kacheln und derselbe
Fehler wog fünfmal so viel.

Gemessen im Container: `[95.94]` und `[95.94]` bei 1440 px.

---

## §6 Punkt 4 — bei totem Agenten kein endloser Platzhalter

*(**darf nicht ausfallen**)*

Ein Platzhalter, der bei einem Fehler stehenbleibt, ist schlimmer als der
Fehler: Er sagt „gleich", und das wird nie wahr.

```
systemctl stop srvpanel-agentd
```

**Danach stehen `srvpanel-worker` und `srvpanel-metrics` ebenfalls still** —
`PartOf=` überträgt das Anhalten (`docs/100 §9.10`). Zurückgeholt wird am Ende
mit `systemctl start srvpanel.target`, nicht mit einem `start` des Agenten
allein.

Auf `/updates` (neu laden) und dann in der Konsole:

```js
console.log({
  platzhalter: document.querySelectorAll('.skeleton').length,
  kritisch: [...document.querySelectorAll('.notice.critical')].map((e) => e.textContent.trim().slice(0, 80)),
  kacheln: document.querySelectorAll('.tile').length,
})
```

**Erfüllt, wenn** nach dem Ende der Anfrage `platzhalter` **0** ist und
`kritisch` **zwei** Meldungen trägt — eine für den Paketstand, eine für die
Quellen. `kacheln` ist dann `0`, und das ist richtig: Ohne jede Zahl ist die
Kachelreihe keine Auskunft, und der Streifen daneben erklärt es.

Danach:

```
systemctl start srvpanel.target
systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-metrics
```

---

## §7 Punkt 5 — die Farbe des Fortschrittsbalkens

*(darf ausfallen)*

Bis zu dieser Fassung war der Balken blau, und kein Wächter über Quelltext
konnte das sehen: Die Bibliothek schreibt die Farbe zur Laufzeit ins Dokument.

**Gemessen wird an einer gewöhnlichen Navigation und nicht am Nachreichen**
(§0, Punkt 3). Damit sie die 250 ms überschreitet, wird in den Entwicklerwerkzeugen
die Leitung gedrosselt — „Slow 4G" genügt.

```js
const g = document.getElementById('app').__vue_app__.config.globalProperties
const proben = []
const takt = setInterval(() => {
  const bar = document.querySelector('#nprogress .bar')
  if (bar && getComputedStyle(bar.parentElement).display !== 'none') {
    proben.push(getComputedStyle(bar).backgroundColor)
  }
}, 60)
g.$inertia.visit('/services')
setTimeout(() => {
  clearInterval(takt)
  console.log(JSON.stringify({
    gesehen: proben.length,
    farben: [...new Set(proben)],
    marke: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(),
    thema: document.documentElement.getAttribute('data-theme'),
    regel: (() => {
      for (const sheet of document.styleSheets) {
        let regeln; try { regeln = sheet.cssRules } catch { continue }
        for (const r of regeln ?? []) {
          if (r.selectorText?.includes('nprogress') && r.selectorText.includes('.bar')) {
            return r.style.background || r.style.backgroundColor
          }
        }
      }
      return null
    })(),
  }, null, 1))
}, 8000)
```

**Die Sichtbarkeit wird mitgefragt** (`display !== 'none'`) — das ist der
Unterschied, an dem die erste Fassung dieses Punktes gescheitert ist.

**Erfüllt, wenn** `gesehen` grösser als 0 ist und `farben` **genau einen** Wert
enthält, der die Marke `--accent` ist.

**Und beide Themen**, denn der Beleg ist nicht die Farbe, sondern dass sie
*folgt*: `regel` muss in beiden Fällen wörtlich `var(--accent)` lauten und
`farben` zwei verschiedene Werte ergeben. Gemessen im Container:
`rgb(55, 48, 163)` hell, `rgb(255, 127, 236)` dunkel.

> **Eine Farbe, die in beiden Themen dieselbe ist, wurde gelesen und nicht
> durchgereicht.**

Danach die Drosselung wieder abschalten.

## §8 Punkt 6 — die Regel, die jede Bewegung anhält, ist ausgeliefert

*(darf ausfallen)*

Nicht, ob `prefers-reduced-motion` wirkt — das ist eine Eigenschaft des Geräts
und gemessen (§0). Hier wird gefragt, ob die **ausgelieferte** Fassung die eine
Regel mitbringt und ihr keine zweite widerspricht:

```js
let bewegungsregeln = 0, wichtig = []
for (const sheet of document.styleSheets) {
  let regeln; try { regeln = sheet.cssRules } catch { continue }
  for (const r of regeln ?? []) {
    if (r.media && String(r.media).includes('prefers-reduced-motion')) bewegungsregeln++
    if (r.style && r.style.getPropertyPriority?.('animation-duration') === 'important' && !(r.parentRule?.media)) {
      wichtig.push(r.selectorText)
    }
  }
}
console.log({ bewegungsregeln, wichtig, platzhalterRegel: !!document.querySelector('.skeleton') })
```

**Erfüllt, wenn** `bewegungsregeln` **1** ist und `wichtig` leer.

---

## §9 Punkt 7 — die Prüfmeldung kommt oben an

*(darf ausfallen)*

**Er misst zwei Dinge in einem** (§0): die Behebung am Fehlerbeutel — und den
Rückweg, den der Plan als eigenen Punkt führte. `back()->withErrors()` landet
nur dann auf `/updates`, wenn die nachgereichte Anfrage `_previous.url` nicht
verdorben hat; steht die Meldung dort, ist beides belegt.

Die Seite schickte bis zu dieser Fassung einen eigenen Fehlerbeutel `errors` und
verdeckte damit die geteilte Ablage, in der Laravels Prüfmeldungen stehen. Die
Zusammenfassung oben auf `/updates` — die es genau dafür gibt — hat nie eine
gezeigt.

Auf `/updates`, mit **dem Klienten der Seite** und nicht mit `fetch`:

```js
const g = document.getElementById('app').__vue_app__.config.globalProperties
g.$inertia.put('/updates/sources',
  { path: '/etc/apt/sources.list.d/srvpanel.sources', stanza: 0, enabled: true },
  { onError: (e) => console.log('Fehler:', e),
    onSuccess: () => console.log('durchgelassen — das wäre der Befund') })
```

`stanza: 0` verletzt `min:1`, also weist die Prüfung ab, **bevor** irgendetwas
den Agenten erreicht. Es wird nichts geschaltet.

**Erfüllt, wenn** `onError` feuert **und** der Satz sichtbar oben auf der Seite
steht:

```js
console.log([...document.querySelectorAll('.notice')].map((e) => e.textContent.trim().slice(0, 90)))
```

Die Meldung muss auf Deutsch erscheinen — `min` steht in
`lang/de/validation.php`. Ein englischer Satz wäre ein eigener Befund und
gehört zu `docs/903 §16.1`, nicht hierher.

---

## §10 Punkt 8 — das Nachladen legt nichts an

*(**darf nicht ausfallen**)*

Eine zweite Anfrage je Seitenaufruf, die ins Protokoll schreibt, verdoppelte den
Bestand. Gefragt ist beides: kein Vorgang und keine Protokollzeile.

**Vor** fünf Aufrufen von `/updates`:

```
srvpanel tinker --execute='
echo "Vorgänge: ", \App\Models\Operation::withoutGlobalScopes()->count(),
     "  Protokoll: ", \App\Models\AuditEvent::withoutGlobalScopes()->count(), PHP_EOL;'
```

**`withoutGlobalScopes()` ist nicht Zierrat:** `Operation` trägt
`BelongsToSubscription`, und `srvpanel tinker` läuft ohne angemeldetes Konto —
ohne die Klammer kämen wortlos null Zeilen zurück, und die Differenz wäre in
jedem Fall 0.

> **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer leeren
> Liste und nicht mit einem Fehler.**

Dann fünfmal `/updates` aufrufen, jedes Mal die drei Sekunden abwarten, und
dieselbe Zeile noch einmal.

**Erfüllt, wenn beide Zahlen unverändert sind.**

Die Gegenprobe gehört dazu, sonst misst die Null nichts: Ein Klick auf „Jetzt
nachsehen" muss die erste Zahl um **1** erhöhen. Ohne sie wäre „unverändert"
von „die Abfrage zählt nichts" nicht zu unterscheiden.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## §11 Punkt 9 — die Bilderrunde

*(darf ausfallen)*

**Acht Lagen: vier im Platzhalterzustand, vier geladen** — 390 und 1440 px, je
hell und dunkel. Der Platzhalterzustand ist einer, den auf einem Server noch nie
jemand gemessen hat.

Gemessen wird mit `tests/bilder-messen.js` aus dem Repo, **je Aufnahme in einer
frisch geladenen Seite** — der Prüfkörper bemisst sich am gegenwärtigen Zustand
und misst beim zweiten Lauf sich selbst.

Für die vier Aufnahmen im Platzhalterzustand bleiben drei Sekunden. Reicht das
auf dem Telefon nicht, gilt die Regel aus `docs/108`: **Ein Punkt, der am
Werkzeug scheitert und nicht am Gegenstand, ist nicht „nicht herstellbar"** — er
wird am Rechner nachgeholt.

**Erfüllt, wenn** in allen acht Lagen `dokument = 0` steht und die Gegenprobe
mit **200** ausschlägt. Ein `rollt` auf einem Rollbehälter ist erlaubt und
nennt ihn beim Namen; `schiebt` darf `.stacks thead` nennen — das ist das
Gewollte und ein Hinweis, kein Urteil.

---

## §12 Punkt 10 — das Panel bleibt in den drei Sekunden bedienbar

*(darf ausfallen)*

**Der Container kann das nicht beantworten** (§0), und deshalb steht es hier.
Die nachgereichte Anfrage hält die Sitzung so lange, wie der synchrone Aufruf
sie vorher gehalten hat — das ist die Erwartung und keine Messung.

Auf `/updates` navigieren und **während** der drei Sekunden auf einen anderen
Menüpunkt klicken:

```js
const g = document.getElementById('app').__vue_app__.config.globalProperties
const t0 = performance.now()
g.$inertia.visit('/updates')
setTimeout(() => {
  const t1 = performance.now()
  g.$inertia.visit('/services', {
    onSuccess: () => console.log('Wechsel nach', Math.round(performance.now() - t1), 'ms'),
  })
}, 800)
```

**Erfüllt, wenn** der Wechsel in unter **1500 ms** durchkommt. Dauert er so
lange wie der Rest der nachgereichten Anfrage, hält die Sitzungssperre den
Betreiber fest — dann ist es ein Befund und gehört ins Protokoll, samt der
Frage, ob dieselbe Sperre vorher genauso lange stand.

---

## §13 Was dieser Lauf ausdrücklich nicht prüft

- **Ob die drei Sekunden kürzer werden.** Sie bleiben; der Platzhalter verdeckt
  sie nicht, er macht sie erträglich (`docs/904 §11`).
- **Die neun übrigen Agent-Seiten.** Sie liegen gemessen unter 123 ms; dort ist
  nichts zu gewinnen.
- **Die beiden ungemessenen Aufrufe** `system.logs.tail` und `web.logs.tail`.
  Sie stehen in `docs/904 §1` als ungemessen da und nicht als schnell.
- **Ob `prefers-reduced-motion` auf einem Gerät wirkt** (§0, Punkt 2).
- **Den Wortlaut der übrigen Prüfmeldungen.** Punkt 7 misst einen; die anderen
  kommen über denselben Weg, ihr Text ist damit nicht gemessen
  (`docs/903 §17.4`).

---

## §14 Wann er durch ist

**Alle zehn Punkte gefahren**, und **die Punkte 3, 4 und 8 dürfen nicht
ausfallen** (§5, §6 und §10 — die Punktnummern sind nicht die
Paragraphennummern, und diese Zeile stand beim Ausschreiben einmal falsch da):

- **Punkt 3** (§5), der kein Sprung: weil er beim Bauen einen echten Fehler
  gefunden hat und die Behebung keinen Server gesehen hat.
- **Punkt 4** (§6), der tote Agent: Ein Platzhalter, der bei einem Fehler
  stehenbleibt, ist schlimmer als der Fehler.
- **Punkt 8** (§10), das Nachladen ohne Nebenwirkung.

Ein Punkt, der am Werkzeug scheitert, ist **nicht** „nicht herstellbar" und wird
nachgeholt. Ein Punkt, dessen Zustand sich herstellen lässt und den der Prüfling
falsch beantwortet, ist ein Ausfall — auch der letzte.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten, ist
> keines mehr — es ist eine Zusammenfassung.**
