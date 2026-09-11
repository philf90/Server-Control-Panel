# Protokoll: der Abnahmelauf des Platzhalters

Gefahren ab dem **11. September 2026** auf `cloudsrv24` gegen **`0.7.4-rc.3`**.
Der Plan ist `docs/904`, der Lauf `docs/905`.

---

## §1 Der Ausgangszustand

```
srvpanel version
0.7.4-rc.3
```

<!-- abschrift: was wirklich getippt wurde, samt des falschen Unitnamens — genau darum geht es in §1.1 -->
```
systemctl is-active srvpanel-agent srvpanel-worker
inactive
active
```

**Und diese zwei Zeilen sind der erste Befund** — siehe §1.1. Der Agent lief;
die Frage war falsch gestellt.

### 1.1 Befund 1 — die Unit heisst nicht so

*(im Prüfmittel, und er hätte ein Ausschlusskriterium still bestehen lassen)*

`docs/905` nannte an drei Stellen `srvpanel-agent`. Die Unit heisst
**`srvpanel-agentd.service`**; unter `packaging/systemd/` gibt es keinen
anderen Namen, und ausser in dieser Vorschrift schreibt ihn im ganzen
Repositorium niemand falsch.

Eine der drei Stellen ist **§6 des Laufs — Punkt 4, ein
Ausschlusskriterium.** Dort steht `systemctl stop <name>`, um den Agenten
totzulegen. Auf einen unbekannten Namen angewandt hält das nichts an und
bricht nichts ab; `/updates` hätte danach ganz normal geladen, ohne
Platzhalter und ohne Streifen — **und genau diese Anzeige wertet Punkt 4 als
erfüllt.** Ein Kriterium, das den Zustand nie herstellt, den es prüfen soll,
und das dabei grün aussieht.

> **`systemctl is-active` meldet für eine Unit, die es nicht gibt, `inactive` —
> ununterscheidbar von einer, die angehalten ist.**

**Gefunden hat es nicht das Nachdenken, sondern dass §2 den Zustand
mitdruckt:** `inactive` für den Agenten, und zwei Zeilen darunter zwei
Agentenaufrufe, die in derselben Minute antworten. Ein Widerspruch, den nur
sieht, wer beide Zeilen nebeneinander hat.

> **Eine Messung, die ihren Zustand nicht mitdruckt, ist von einer, die ihn
> nicht hatte, nicht zu unterscheiden.**

Das ist die Fehlerklasse, die dieses Projekt am häufigsten trifft — eine
Zeichenkette, die auf etwas verweist, ohne dass ein Werkzeug den Bezug prüft.
**`UnitNameReachTest`** hält sie seitdem: Ein `srvpanel-*`-Unitname in einem
Codeblock eines Dokuments oder in einem Skript zeigt auf eine paketierte Unit
— oder auf eine transiente, deren Namen der Wächter **aus dem Agenten liest**
(`AptLock::UNIT_PREFIX`, `SystemReboot::UNIT`) und nicht aus einer Liste in
sich selbst.

Er liest dabei **nur Codeblöcke** und streift in Skripten die Kommentare ab:
Der Fliesstext dieses Protokolls erklärt gerade, dass es den Namen nicht gibt,
und zitiert ihn dabei.

> **Derselbe Kommentar, der einen Wächter fälschlich grün hält, macht eine
> Messung fälschlich rot.**

### 1.2 Und er hat sofort einen zweiten Fund gehabt

*(am Bestand, nicht an diesem Lauf)*

`docs/35 §10.5` — die Rückfall-Anweisung „Wenn etwas schiefgeht" — lautete
`systemctl stop srvpanel-worker srvpanel`. Ein `srvpanel.service` gibt es
nicht; paketiert ist `srvpanel.target`, und der Dienst der Oberfläche heisst
`srvpanel-web.service`. Die Zeile hält also den Worker an und schweigt zum
Panel — in einer Anweisung, die jemand unter Druck abtippt, nachdem eine
Migration schiefgegangen ist.

Berichtigt auf `srvpanel-worker srvpanel-web`.

> **Ein Wächter, der beim ersten Lauf einen zweiten Fund hat, war überfällig.**

---

## §2 Der Referenzwert für Punkt 1

Zweimal gefahren, wie der Lauf es verlangt.

| Aufruf | 1. Lauf | 2. Lauf | `docs/904 §1` (10. September) |
|---|---|---|---|
| `system.sources.list` | **45 ms** | **45 ms** | 36 / 35 ms |
| `system.packages.list` | **3644 ms** | **2954 ms** | 2998 / 3033 ms |

**Der zweite Lauf hat hier etwas anderes gezeigt als am 10. September, und
gerade das ist die Auskunft.** Damals war der teure Aufruf warm eine Spur
*langsamer* (2998 → 3033), heute deutlich *schneller* (3644 → 2954). Zwei
Richtungen, also keine Richtung: Der Wert streut um drei Sekunden, und die
Streuung ist mit rund 690 ms grösser als jeder Zwischenspeichereffekt, den man
ihm unterstellen könnte.

> **Eine Zahl, die zwischen zwei Läufen in beide Richtungen springt, hat keinen
> Zwischenspeicher — sie hat Streuung, und ein einzelner Lauf nennt sie nicht.**

Damit steht der Schluss aus `docs/904 §1` fester als vorher: `apt-get -s
upgrade` arbeitet bei jedem Aufruf, und zwar rund drei Sekunden lang. Für
Punkt 1 ist das reichlich Abstand zur Grenze von 2000 ms.

**`system.sources.list` ist dagegen auf die Stelle reproduzierbar** — zweimal
45 ms, am 10. September 36 und 35. Der Aufruf liest Dateien und fragt `gpg` je
Schlüsselbund; auf diesem Server sind das offenbar wenige.

**Die Schwelle aus `docs/904 §2` greift damit nicht.** 45 ms liegen weit unter
300, `sources` bleibt zu Recht synchron, und die eine Frage, die dieser Lauf an
den Plan statt an den Prüfling hatte, ist beantwortet. Im Container sind es
806–848 ms — dort hat jede Quelle einen Schlüsselbund; die Zahl gehört also zur
Maschine und nicht zum Panel.

**Die Grenze für Punkt 1 steht damit fest:** Hülle höchstens `3 × 45 + 200` =
**335 ms**, und in der nachgereichten Anfrage ein Wert über **2000 ms**.

---

## §3 Punkt 1 — die Hülle wartet nicht mehr auf apt

**Erfüllt.**

```
{ huelle: 117, nachgereicht: Array(0) }
```

Die Hülle liegt bei **117 ms** gegen die aus §2 gerechnete Grenze von 335
(`3 × 45 + 200`). Sie ist damit ungefähr so teuer wie `system.sources.list`
allein — der teure Aufruf steckt nicht mehr in ihr.

**`nachgereicht` kam beim ersten Griff leer zurück, und das war ein Befund am
Prüfkörper.** `performance.getEntriesByType('resource')` kennt nur Anfragen
**dieses Dokuments**; stellt Inertia die Seite aus seinem eigenen
Zwischenspeicher her, war die Eigenschaft schon da und es gab gar keine
nachgereichte Anfrage.

> **Eine leere Liste sagt „keine Anfrage in diesem Dokument" und nicht „keine
> Anfrage".**

Nachgemessen mit einem `PerformanceObserver`, der **vor** der Navigation steht:

```
"anfragen": [
 { "typ": "xmlhttprequest", "ms": 2949 },
 { "typ": "xmlhttprequest", "ms": 156 },
 { "typ": "xmlhttprequest", "ms": 3264 }
]
```

**Drei Anfragen an dieselbe Adresse, und ihre Verteilung ist der eigentliche
Beleg.** Die 2949 ms stammen aus dem Dokument, das beim Start des Skripts schon
geladen war (`buffered: true`). Die beiden anderen gehören zum erzwungenen
Besuch: **156 ms für die Hülle** und **3264 ms für das Nachreichen**.

> **Dieselbe Adresse, zweimal abgefragt, 156 gegen 3264 ms — die Trennung
> zwischen „die Seite" und „was sie teuer macht" ist damit nicht hergeleitet,
> sondern gemessen.**

Beide Werte über 2000 ms liegen weit über der Grenze; die Streuung aus §2
(2954–3644 ms auf der Kommandozeile) findet sich hier wieder.

---

## §4 Punkt 2 — die Seite ist da, ein Teil fehlt noch

**Erfüllt.**

```
"fenster": "1414–4601 ms",
"platzhalterMax": 11,
"kachelnImFenster": [ 5 ],
"quellenzeilenImFenster": [ 5 ]
```

| Erwartet | Gemessen |
|---|---|
| Fenster über 1000 ms | **3187 ms** (1414 → 4601) |
| `platzhalterMax` = 11 | **11** |
| `kachelnImFenster` = `[5]` | **`[5]`** |
| `quellenzeilenImFenster` > 0 | **`[5]`** |

Die elf sind genau die entworfenen: fünf Kachelwerte, vier Zeilen unter
„Pakete", zwei unter „Unbeaufsichtigte Updates".

**`quellenzeilenImFenster: [5]` ist der Punkt, um den es geht.** Während der
drei Sekunden stehen fünf Quellzeilen fertig da — mit Zustand, Adresse, Suiten
und Schlüssel. Die Seite ist da, ein Teil fehlt noch.

Eine Aufnahme bei 1440 px im dunklen Thema zeigt denselben Zustand: die
Kachelreihe mit ihren fünf Beschriftungen und grauen Blöcken, „Pakete" mit vier
Platzhalterzeilen, „Paketquellen" vollständig gefüllt.

Nebenbei belegt der geladene Zustand die Zahlen: 32 aktualisierbar, davon 1
Sicherheit und 18 neu, 0 zurückgehalten, 0 würde entfernt — dazu zwei
Konfigurationsdateien unter `/etc`, die auf eine Entscheidung warten.

---

## §5 Punkt 3 — kein Sprung *(Ausschlusskriterium)*

**Erfüllt, auf beiden Breiten.**

| Breite | `kachelreiheMit` | `kachelreiheOhne` | Sprung |
|---|---|---|---|
| 1440 px | `[95.95]` | `[95.95]` | **0 px** |
| 390 px | `[459.73]` | `[459.73]` | **0 px** |

**Derselbe eine Wert vor und nach dem Ersetzen** — gemessen in *einem*
Seitenaufbau und nicht aus zwei Läufen verglichen.

Das ist der Punkt, an dem der Bau einen Fehler hatte: Der erste Wurf trug
`margin: 2px 0` am Kachelplatzhalter — vier Pixel je Kachel. Bei 1440 px stehen
die fünf nebeneinander und die Seite sprang um 4 px, bei 390 px stapeln sie
sich und es waren **20**. Alles darunter zog mit. Die Behebung hatte bis heute
keinen Server gesehen; jetzt ist sie an der Breite belegt, an der der Fehler
fünfmal so schwer wog.

> **Ein Platzhalter, der nicht genau so hoch ist wie das, was er vertritt,
> verschiebt alles darunter — und auf dem Bild sieht das nach nichts aus.**

Gegen den Container gehalten: 95,94 gegen 95,95 und 459,69 gegen 459,73 —
dieselben Zahlen bis auf die Schriftmetrik. Der Messaufsatz des Containers
trifft die echte Seite damit zum wiederholten Mal aufs Pixel genau.

### 5.1 Und die Punkte 1 und 2 gelten auch bei 390 px

Derselbe Lauf hat sie mitgemessen:

```
"anfragen": [ 3057 ms, 141 ms, 2941 ms ],
"fenster": "1403–4301 ms",
"platzhalterMax": 11,
"kachelnImFenster": [ 5 ],
"quellenzeilenImFenster": [ 5 ]
```

**141 ms für die Hülle gegen 2941 ms für das Nachreichen** — dieselbe Trennung
wie bei 1440 px (156 gegen 3264). Das Fenster steht 2898 ms, die elf
Platzhalter und die fünf Kacheln sind dieselben.

> **Ein Wert, der auf zwei Breiten dasselbe sagt, sagt etwas über die Sache und
> nicht über die Breite.**

---

## §6 Punkt 4 — bei totem Agenten kein endloser Platzhalter *(Ausschlusskriterium)*

**Erfüllt.**

```
systemctl stop srvpanel-agentd
```

```
{
 "platzhalter": 0,
 "kacheln": 0,
 "kritisch": [
  "Der Paketstand liess sich nicht ermitteln: Der Agent läuft nicht: Socket ist nic",
  "Die Paketquellen liessen sich nicht ermitteln: Der Agent läuft nicht: Socket ist"
 ]
}
```

**`platzhalter: 0` ist der ganze Punkt.** Ein Platzhalter, der bei einem Fehler
stehenbleibt, sagt „gleich", und das wird nie wahr — er wäre schlimmer als der
Fehler, den er verdeckt.

`kacheln: 0` ist ebenfalls richtig und kein Mangel: Ohne jede Zahl ist die
Kachelreihe keine Auskunft, und der Streifen daneben erklärt es.

**Zwei Meldungen und nicht eine** — das belegt die Entscheidung im Controller,
die beiden Aufrufe **getrennt** zu fangen:

> **Zwei Fragen, die einander erklären, dürfen nicht an derselben Antwort
> scheitern.**

Hier fallen beide aus, weil der Agent ganz fehlt; gemeldet werden sie trotzdem
einzeln, und beim häufigeren Fall — nur der Paketstand fällt aus — bleibt die
Quellenliste als die Auskunft stehen, die weiterhilft.

### 6.1 Und die drei Zustände sind an der Anzeige unterscheidbar

Unter „Unbeaufsichtigte Updates" steht **„Ohne den Paketstand ist über die
Automatik nichts zu sagen"** — also der Zweig für `null` und nicht der für
`undefined`. Der Bereich zeigt damit nachweislich etwas anderes als während der
drei Sekunden, in denen dort ein Platzhalter stand (§4).

> **Eine Anzeige, die zwei verschiedene Zustände gleich aussehen lässt,
> behauptet etwas, das sie nicht weiss.** Hier tut sie es nicht: „noch
> unterwegs", „ausgefallen" und „da" sind drei Bilder.

Das ist die Dreiwertigkeit aus `docs/904`, an der echten Seite belegt — und
genau das, was `DeferredPropTest` im Quelltext hält.

### 6.2 Der Rückweg

```
systemctl start srvpanel.target
systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-metrics
active
active
active
```

**Über das Ziel und nicht über den Agenten allein.** `PartOf=` überträgt das
Anhalten und nicht das Starten (`docs/100 §9.10`); ein `start` des Agenten
liesse Worker und Metrik liegen, und dann bliebe jeder Vorgang wortlos auf
„wartet" stehen.

---

## §7 Punkt 5 — die Farbe des Fortschrittsbalkens

**Erfüllt** — beim zweiten Griff. Der erste hat nichts gemessen, und der Fehler
war meiner.

```
{ "gesehen": 0, "farben": [], "marke": "#ff7fec", "thema": "dark"  }
{ "gesehen": 0, "farben": [], "marke": "#3730a3", "thema": "light" }
```

Die Marken stimmen in beiden Themen. Der Balken war nie da — und zwar **zu
Recht**, was die Vorschrift nicht wusste.

### 7.1 Befund 2 — der Prüfkörper fragte nach dem Element

*(im Prüfmittel; er hat eine falsche Zusage in `docs/905 §0` erzeugt)*

`docs/905 §0` behauptete, eine nachgereichte Anfrage löse den Balken aus, und
nannte das **gemessen** — „erstes Bild bei t = 240 ms". Im Bündel nachgesehen
stimmt das nicht:

- `doReload()` setzt **`async: true`**, und `showProgress` ist
  `options.showProgress ?? (!options.async || !!options.optimistic)` — für jede
  nachgereichte Anfrage also **`false`**.
- `hide()` setzt `display: none` und **lässt das Element im DOM**.

Der Prüfkörper fragte `document.querySelector('#nprogress .bar')`, fand das
unsichtbare Element und las dessen Farbe. Er hat damit belegt, dass es die
Regel gibt — nicht, dass ein Balken erscheint.

> **Ein Prüfkörper, der nach dem Element fragt, hat nicht nach der Anzeige
> gefragt.**

**Aufgefallen ist es nur auf dem Server**, wo gar nichts gestartet war und
`gesehen` deshalb auf `0` stand. Im Container stand dort eine Zahl, und eine
Zahl sieht aus wie eine Messung.

> **Eine Zahl, die aus einem unsichtbaren Element kommt, ist von einer aus einem
> sichtbaren nicht zu unterscheiden — wenn man nicht nach der Sichtbarkeit
> fragt.**

### 7.2 Und das Schweigen ist richtig

Ein Nachladen im Hintergrund soll nicht blinken. Dass Inertia den Balken für
`async`-Anfragen ausdrücklich abschaltet, ist ein Entwurf und kein Versehen —
und es passt zu der Überlegung, aus der die 250 ms Verzögerung stehen bleiben:

> **Ein Ladezustand, der kürzer steht als der Blick braucht, ist kein Hinweis —
> er ist eine Bewegung ohne Aussage.**

Der Balken bedient damit alles **ausser** dem Nachreichen: jede gewöhnliche
Navigation und jedes abgesendete Formular. Dort gehört er gemessen, und damit
eine Navigation die 250 ms überschreitet, wird die Leitung gedrosselt. Die
Drosselung ändert am Prüfling nichts — sie stellt die Bedingung her, für die es
den Balken gibt.

`docs/905 §7` ist entsprechend neu gefasst; der Prüfkörper fragt seitdem die
**Sichtbarkeit** mit.

### 7.3 Die Messung

Mit gedrosselter Leitung („Slow 4G") an einer gewöhnlichen Navigation:

| Thema | `gesehen` | `regel` | `farben` | `marke` |
|---|---|---|---|---|
| dunkel | 13 | `var(--accent)` | `rgb(255, 127, 236)` | `#ff7fec` |
| hell | 13 | `var(--accent)` | `rgb(55, 48, 163)` | `#3730a3` |

**Beide Male dieselbe eingespritzte Regel, beide Male eine andere Farbe.** Das
ist der Beleg, und nicht die Farbe selbst: Ein gelesener und eingebackener Wert
stünde in beiden Themen gleich da.

> **Eine Farbe, die in beiden Themen dieselbe ist, wurde gelesen und nicht
> durchgereicht.**

Die Umrechnung stimmt auf die Stelle: `#ff7fec` ist `rgb(255, 127, 236)`,
`#3730a3` ist `rgb(55, 48, 163)`. `farben` trägt je genau **einen** Wert — der
Balken wechselt seine Farbe während des Laufs nicht.

Damit ist der Befund aus `docs/904 §10a` auf dem Server geschlossen: Der
Fortschrittsbalken trug seit jeher Inertias blaue Voreinstellung, und kein
Wächter über Quelltext konnte das sehen, weil die Bibliothek sie zur Laufzeit
ins Dokument schreibt.

---

## §8 Punkt 6 — die Bewegungsregel ist ausgeliefert

**Erfüllt.**

```
{ "bewegungsregeln": 1, "wichtig": [] }
```

**Genau ein** `prefers-reduced-motion`-Block im ausgelieferten Stylesheet, und
keine Animation stellt sich mit `!important` darüber.

Zwei wäre schlimmer als keiner: Zwei Blöcke laufen auseinander, und welcher
gilt, entscheidet die Reihenfolge in der Datei — wer den zweiten schreibt,
glaubt den ersten zu ersetzen. Genau deshalb hat der Platzhalter **keine**
eigene Ausnahme bekommen, obwohl `docs/904 §5` sie im ersten Wurf verlangte.

**Die leere Liste ist die stille Hälfte.** `!important` schlägt `!important`
über die Spezifität, und die einer Klasse ist höher als die von `*` — eine
einzige solche Zeile nähme die Regel für genau ihr Element zurück, und auf
einem Gerät ohne die Einstellung sähe das völlig richtig aus.

Was hier **nicht** gemessen wird, steht in `docs/905 §0`: ob die Einstellung auf
einem Gerät wirkt, ist eine Eigenschaft des Geräts und gegen echtes Chromium
gemessen (40 Bilder, 30 Werte ohne die Einstellung, einer mit ihr).

---

## §9 Punkt 7 — die Prüfmeldung kommt oben an

**Erfüllt**, und er belegt zwei Dinge auf einmal.

```
onError: { "stanza": "Das Feld Eintrag muss mindestens 1 sein." }

{
 "url": "/updates",
 "meldungen": [
  "Das Formular wurde nicht gespeichert.Das Feld Eintrag muss mindestens 1 sein."
 ]
}
```

**Die Behebung am Fehlerbeutel.** Bis zu dieser Fassung schickte `/updates`
eine eigene Eigenschaft `errors` und überschrieb damit die geteilte Ablage, in
der Laravels Prüfmeldungen stehen. Die Zusammenfassung oben auf der Seite — die
es genau dafür gibt — hat dort **nie** eine gezeigt. Jetzt steht sie da:

> **Das Formular wurde nicht gespeichert.**
> Das Feld Eintrag muss mindestens 1 sein.

**Und der Rückweg.** `url` steht auf `/updates`. `back()->withErrors()` landet
nur dann hier, wenn die nachgereichte Anfrage `_previous.url` nicht verdorben
hat — der Punkt, der im Plan noch „Der Weg zurück lebt" hiess und beim
Ausschreiben in diesen aufgegangen ist (`docs/905 §0`).

> **Zwei Fragen, von denen die eine die andere einschliesst, sind ein Punkt und
> nicht zwei — und der Punkt ist die engere.**

**Der Satz ist deutsch**, und das ist nicht selbstverständlich: `min` steht in
`lang/de/validation.php`, und am 10. September fehlten dort 98 von 138
Regelschlüsseln (`docs/903 §11.1`). Ein englischer Satz wäre ein eigener Befund
gewesen.

**Nichts wurde geschaltet.** `stanza: 0` verletzt `min:1`; die Prüfung weist ab,
bevor irgendetwas den Agenten erreicht, und `toggle()` schreibt sein
Protokoll erst nach dem Absetzen.

### 9.1 Und eine zusammengelaufene Zeile ist kein Befund

`meldungen` zeigt *„…nicht gespeichert.Das Feld…"* ohne Leerzeichen. Das ist
der `textContent` eines Elements mit zwei Kindern und sagt über die Anzeige
nichts — die Aufnahme zeigt zwei Zeilen, Überschrift und Satz.

> **Ein Rohdruck, der Kinder ohne Trenner aneinandersetzt, sieht kaputt aus und
> misst nichts über die Anzeige.**

Derselbe Satz wie in `docs/114` an einer Tabelle, hier an einem Streifen.

---

## §10 Punkt 8 — das Nachladen legt nichts an *(Ausschlusskriterium)*

**Erfüllt.**

| | Vorgänge | Protokoll |
|---|---|---|
| Ausgangsstand | 849 | 1302 |
| nach **fünf** Aufrufen von `/updates` | **849** | **1302** |
| nach **einem** Klick auf „Jetzt nachsehen" | **850** | **1303** |

Fünf Seitenaufrufe, jeder mit seiner nachgereichten Anfrage — und keine einzige
Zeile mehr. Eine zweite Anfrage je Seitenaufruf, die ins Protokoll schriebe,
verdoppelte den Bestand; sie schreibt nicht.

**Die Gegenprobe ist der Teil, der die Null zu einer Messung macht.** Ein Klick
auf „Jetzt nachsehen" bewegt **beide** Zahlen um genau 1 — der Vorgang und die
Zeile `packages.refreshed`, die `refresh()` dazu schreibt. Ohne sie wäre
„unverändert" von „die Abfrage zählt nichts" nicht zu unterscheiden.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Und `withoutGlobalScopes()` ist kein Zierrat:** `Operation` trägt
`BelongsToSubscription`, und `srvpanel tinker` läuft ohne angemeldetes Konto.
Ohne die Klammer käme wortlos immer 0 zurück, und die Differenz wäre in jedem
Fall 0 — eine Messung, die gar nicht scheitern kann.

Damit ist auch die Frage aus `docs/904 §7` beantwortet, die dort als
**hergeleitet** und nicht als gemessen stand: `Origin::current()` liest die
Kopfzeile aus der laufenden Anfrage und legt nichts ab; ein `GET` legt keinen
Vorgang an, und damit liest sie niemand. Jetzt ist es gemessen.

> **Eine Herleitung, die stimmt, ist keine Messung — und welche von beiden
> dasteht, sieht man ihr später nicht an.**

---

## §10a Befund 3 — die Begründung von Punkt 10 nannte eine Sperre, die es nicht gibt

**Gefunden vor dem Fahren, am Quelltext.** `docs/905 §12` begründete Punkt 10
mit der **Sitzungssperre**: Die nachgereichte Anfrage halte die Sitzung so
lange, wie der synchrone Aufruf sie vorher gehalten habe. Nachgesehen gibt es
diese Sperre in diesem Panel nicht.

| Quelle | gemessen |
|---|---|
| `Ops/PanelProvision.php:104` | schreibt `SESSION_DRIVER => 'database'` nach `/etc/srvpanel/panel.env` |
| `config/session.php:21` | `env('SESSION_DRIVER', 'database')` — dieselbe Vorgabe |
| `Illuminate\Session\DatabaseSessionHandler::read()` | kein `lockForUpdate`, keine Sperre |
| `packaging/etc/fpm.conf` | `pm = dynamic`, `pm.max_children = 12`, `pm.start_servers = 2` |

Gesperrt hätte der **Dateitreiber**, und den benutzt hier niemand.

**Der Punkt selbst bleibt und ist unverändert wertvoll** — er fragt die
**Wirkung** und nicht den Riegel. Was die falsche Begründung angerichtet hätte,
ist die Lesart: Ein schneller Wechsel wäre als „die Sperre ist kurz" ins
Protokoll gegangen, also als Messung eines Mechanismus, den es nicht gibt.

> **Ein Abnahmelauf, der eine ungeprüfte Annahme als Anweisung führt, prüft sie
> nicht — er führt sie aus.**

**Und der Prüfkörper hat dabei seine Gegenprobe bekommen.** Er druckte nur die
Dauer des Wechsels. Ob die Nachreichung im Augenblick des Klicks überhaupt noch
lief, stand nirgends — und ein Wechsel, der stattfindet, nachdem die drei
Sekunden vorbei sind, misst nichts. Er druckt jetzt `beim_klick` mit der Seite
und `nachreichung_offen` mit.

> **Eine Messung, die ihren Zustand nicht mitdruckt, ist von einer, die ihn
> nicht hatte, nicht zu unterscheiden.** Zum zweiten Mal in diesem Lauf nach
> Befund 1 (§1.1) — dort hat derselbe Satz den falschen Unitnamen überführt.

---

## §11 Punkt 9 — die Bilderrunde

Gefahren nach dem berichtigten Verfahren aus `docs/905 §11`: je Lage Breite und
Thema einstellen, auf `/services` neu laden, `tests/bilder-messen.js` einfügen,
dann aus der Konsole nach `/updates` navigieren und bei **400 ms** messen.

### 11.1 Die vier Lagen im Platzhalterzustand

| Lage | `dokument` | `gegenprobe` | `schiebt` | `rollt` | `versteckt` | `platzhalter` | `kacheln` |
|---|---|---|---|---|---|---|---|
| 1440 dunkel | **0** | **200** (soll 200) | 0 | 1 | 0 | `true` | **11** |
| 1440 hell | **0** | **200** (soll 200) | 0 | 1 | 0 | `true` | **11** |
| 390 dunkel | **0** | **200** (soll 200) | 0 | 0 | 2 | `true` | **11** |
| 390 hell | **0** | **200** (soll 200) | 0 | 0 | 2 | `true` | **11** |

Alle vier mit `stand=2026-09-06`, also dem Messmittel aus dem Repo und nicht
einer gekürzten Fassung aus der Zwischenablage.

**Der eine Roller bei 1440 px ist benannt und gewollt:**

```
div > div.frame > main.content > div.sections:3 > section.section:2 > div.scrolls:2 (1083)
```

`section.section:2` ist **„Paketquellen"** (`Index.vue` 1025, ihr `.scrolls` in
1059). Die Quellenliste ist **nicht** nachgereicht — sie kommt mit der Hülle
(§3) und rollt bei 400 ms deshalb zu Recht schon. Unter 720 px stapelt sie, und
genau dort steht `rollt=0`.

Dieselbe Grenze erklärt `versteckt`: Die geklippten Kästen für die
Vorlesesoftware gehören zu `.stacks thead`, den es nur in der gestapelten
Ansicht gibt — **0** bei 1440 px, **2** bei 390 px.

### 11.2 Und die vier Bilder zeigen nicht, was gemessen wurde

**Auf allen vier Aufnahmen steht die geladene Seite** — die Kacheln mit
`32 · 1 · 18 · 0 · 0` und die Pakettabelle. Der Platzhalter war zum Zeitpunkt
der Aufnahme längst ersetzt; gemessen wurde bei 400 ms, fotografiert wurde
danach.

> **Ein Bild nach einer Messung zeigt den Zustand danach und nicht den
> gemessenen.** Derselbe Satz wie in `docs/113 §12`, dort an einer Gegenprobe.

**Genau dafür stehen `platzhalter` und `kacheln` im Ausdruck.** Ohne sie wären
diese vier Lagen von vier Aufnahmen des geladenen Zustands nicht zu
unterscheiden — `dokument = 0` steht in beiden Fällen da, und die Bilder sagen
das Gegenteil dessen, was gemessen wurde. Die **11** ist dabei kein Nebenwert:
Sie ist ausgezählt (fünf Kacheln, vier Zeilen, zwei Zeilen) und trifft in allen
vier Lagen.

### 11.3 Die vier geladenen Lagen

Derselbe Weg, dieselbe Route, dasselbe Dokument — nur **5000 ms** statt 400.

| Lage | `dokument` | `gegenprobe` | `schiebt` | `rollt` | `versteckt` | `platzhalter` | `kacheln` |
|---|---|---|---|---|---|---|---|
| 1440 dunkel | **0** | **200** (soll 200) | 0 | 1 | 0 | `false` | **0** |
| 1440 hell | **0** | **200** (soll 200) | 0 | 1 | 0 | `false` | **0** |
| 390 dunkel | **0** | **200** (soll 200) | 0 | 0 | 6 | `false` | **0** |
| 390 hell | **0** | **200** (soll 200) | 0 | 0 | 6 | `false` | **0** |

**Damit ist Punkt 9 erfüllt** — acht Lagen, `dokument = 0` in allen acht,
Gegenprobe 200/200 in allen acht, `schiebt` überall leer.

### 11.4 Was das Paar sagt, das eine Reihe allein nicht sagen könnte

Die acht Lagen sind **vier Paare** und nicht acht Einzelmessungen — dieselbe
Breite, dasselbe Thema, derselbe Weg, und zwischen ihnen liegt nichts als die
Wartezeit. Drei Zahlen bewegen sich darin, und jede sagt etwas:

**`kacheln`: 11 → 0.** Der Platzhalter überlebt die Daten nicht. Das ist die
Frage, die `docs/905 §11` als Befund geführt hätte, wenn sie anders ausgefallen
wäre.

**`versteckt` bei 390 px: 2 → 6.** Die geklippten Kästen gehören zu
`.stacks thead`. Zwei gehören der Quellenliste, die mit der Hülle kommt; die
vier dazu kommen mit der Pakettabelle, die nachgereicht wird. Die Zahl folgt
also genau dem, was ankommt.

**`rollt` bei 1440 px: 1 → 1, und zwar mit demselben Wert.** Der Roller ist in
beiden Zuständen `section.section:2 > div.scrolls:2` mit **1083 px**, byteweise
dieselbe Zeile.

**Das ist die Gegenprobe zur Zuordnung aus §11.1, und sie kommt ohne ein
zweites Werkzeug aus.** Wäre der Roller die Pakettabelle, stünde er im
Platzhalterzustand gar nicht da — dort gibt es keine Tabelle. Dass er in beiden
Zuständen mit derselben Zahl steht, schliesst das aus: Er gehört zu einem
Bereich, den die Nachreichung nicht anfasst.

> **Zwei Messungen, die sich nur in einer Bedingung unterscheiden, beantworten
> eine Frage, die keine von beiden allein stellt.**

**Und die Pakettabelle rollt bei 1440 px nicht** — sie passt. Erst unter 720 px
stapelt sie, und dann rollt dort nichts mehr: `rollt = 0` in beiden
390-px-Lagen, im Platzhalterzustand wie geladen.

---

## §12 Punkt 10 — das Panel bleibt in den drei Sekunden bedienbar

Gefahren mit dem berichtigten Prüfkörper aus §10a, in einer Konsole auf
`cloudsrv24`, 1440 px.

| | gemessen |
|---|---|
| Seite beim Klick | `/updates` |
| Nachreichung offen beim Klick | **`true`** |
| Seite danach | `/services` |
| Wechsel | **384 ms** |

**Erfüllt** — unter 1500 ms, und die Gegenprobe sagt, dass der Wert etwas misst:
Die nachgereichte Anfrage lief noch, als der Klick kam. Ohne diese Zeile wäre
384 ms auch dann herausgekommen, wenn die drei Sekunden längst vorbei gewesen
wären.

**Gelesen wird der Wert nach Befund 3 und nicht nach der alten Begründung.** Er
belegt **nicht** „die Sitzungssperre ist kurz" — eine solche Sperre gibt es
hier nicht. Er belegt, dass **nichts** im Weg steht: weder ein Sperrmechanismus
der Sitzung noch die Arbeiterzahl von php-fpm.

**Damit ist der Gewinn des Platzhalters mehr als eine Beruhigung.** Vor dieser
Fassung hielt `/updates` seine Antwort drei Sekunden zurück — es gab keine
Seite, von der aus man hätte weggehen können. Jetzt steht sie da, und der
Betreiber kann sie verlassen, während der teure Aufruf noch läuft.

> **Ein Wartezustand, den man verlassen kann, ist ein anderer Zustand als
> derselbe Wartezustand ohne Ausgang.**

### 12.1 Eine Konsolenmeldung, die nicht dem Panel gehört

Neben dem Ergebnis stand auf `/services`:

```
Uncaught (in promise) Error: A listener indicated an asynchronous response by
returning true, but the message channel closed before a response was received
```

**Sie ist hier nicht gemessen und wird deshalb nicht erklärt.** Sie steht als
Beobachtung da, weil `docs/114 §12` bereits eine ungeklärte Konsolenmeldung
benannt offen führt — ob es dieselbe ist, sagt dieser Lauf nicht.

> **Ein Satz, den die Oberfläche behauptet und den niemand gemessen hat, ist
> eine Vermutung mit Fussnote.** Das gilt auch für einen Satz über eine
> Meldung.

---

## §13 Der Stand

| Punkt | Stand |
|---|---|
| 1 — die Hülle | **erfüllt**, 1440 und 390 px (§3, §5.1) |
| 2 — die Seite ist da | **erfüllt**, 1440 und 390 px (§4, §5.1) |
| 3 — kein Sprung *(Ausschluss)* | **erfüllt**, 1440 und 390 px (§5) |
| 4 — toter Agent *(Ausschluss)* | **erfüllt** (§6) |
| 5 — Farbe des Balkens | **erfüllt**, beide Themen (§7.3) |
| 6 — die Bewegungsregel ist ausgeliefert | **erfüllt** (§8) |
| 7 — die Prüfmeldung kommt oben an | **erfüllt** (§9) |
| 8 — das Nachladen legt nichts an *(Ausschluss)* | **erfüllt** (§10) |
| 9 — die Bilderrunde | **erfüllt**, acht Lagen (§11) |
| 10 — bedienbar in den drei Sekunden | **erfüllt**, 384 ms (§12) |

**Alle zehn Punkte sind gefahren und erfüllt**, die drei Ausschlusskriterien
(3, 4, 8) darunter, und keiner ist als „nicht herstellbar" ausgefallen.

**Was dabei nicht vergessen werden darf**, weil es in diesem Lauf schon
gezählt hat:

- Navigiert wird mit `$inertia.visit()` und nicht über die Adresszeile — sonst
  nimmt der Seitenaufbau die Konsole mit, und das Messskript sieht den
  Platzhalterzustand nie von innen.
- **Ausgegeben wird mit `JSON.stringify(…, null, 1)`.** Die Konsole zeigt fünf
  Schlüssel und klappt den Rest weg; beim ersten Lauf von §3 bis §5 standen
  genau die beiden Werte hinter dem `…`, die Punkt 3 entscheiden.
- Die lebende Ablage steht in
  `document.getElementById('app').__vue_app__.config.globalProperties.$page`;
  das `script[data-page]` trägt die Seite vom Laden und überlebt jede
  Navigation.
- Punkt 4 hält den Agenten an: die Unit heisst **`srvpanel-agentd`** (§1.1).
  Danach stehen Worker und Metrik ebenfalls still — zurück mit
  `systemctl start srvpanel.target` und nicht mit einem `start` des Agenten
  allein.

---

## §14 Die Bilanz — drei Befunde, keiner im Prüfling

| Befund | wo | was |
|---|---|---|
| 1 (§1.1) | **Vorschrift** | `docs/905` nannte dreimal `srvpanel-agent`; die Unit heisst `srvpanel-agentd` |
| 2 (§7.1) | **Prüfkörper** | fragte nach dem *Element* des Fortschrittsbalkens statt nach seiner Anzeige |
| 3 (§10a) | **Vorschrift** | begründete Punkt 10 mit einer Sitzungssperre, die es in diesem Panel nicht gibt |

Dazu ein Fund am Bestand, den **der Wächter aus Befund 1 sofort mitgebracht
hat** (§1.2): `docs/35` wies an, `srvpanel` anzuhalten — eine Unit dieses
Namens gibt es nicht.

**Und keiner der drei steckt im Prüfling.** Das ist dieselbe Lage wie in P7
(`docs/78`), und die Erklärung ist dieselbe wie dort und bei A10, A2, A14 und
A11: Die Vorschrift stand vor dem Lauf ausgeschrieben, und die Messmittel lagen
als geprüfte Werkzeuge im Repo — `tests/bilder-messen.js` hat in acht Lagen
keinen einzigen Prüfmittelbefund erzeugt.

**Was hier dazukommt, ist der Bau selbst.** `docs/904 §10a` zählt sieben Stellen
auf, an denen er anders lief als geplant — darunter der Rand von 2 px, der die
Seite springen liess, der Fehlerbeutel `errors`, der jede Prüfmeldung dieser
Seite verdeckte, und die Bilderrunde, die den vorigen Stand gemessen hat. Die
Befunde dieses Merkmals sind also nicht ausgeblieben; sie sind **früher**
gefunden worden.

> **Ein Abnahmelauf ohne Fund am Prüfling sagt nicht, dass keiner da war — er
> sagt, wo sie gefunden wurden.**

**Zwei der drei hätten still durchgehen können, und das ist der Ertrag des
Laufs.** Befund 1 hätte ein Ausschlusskriterium erfüllt gemeldet, ohne dessen
Zustand je hergestellt zu haben (`systemctl stop` auf einen unbekannten Namen
hält nichts an und meldet `inactive`). Befund 2 hätte eine Farbe gemessen, die
niemand sieht. Beide sind an derselben Stelle aufgefallen — daran, dass die
Messung ihren Zustand **mitdruckt**.

> **Eine Messung, die ihren Zustand nicht mitdruckt, ist von einer, die ihn
> nicht hatte, nicht zu unterscheiden.** Dreimal in diesem Lauf: bei Befund 1,
> bei Befund 3 und in der Bilderrunde, wo `platzhalter` und `kacheln` das
> Einzige sind, was den gemessenen Zustand festhält (§11.2).

---

## §15 Was benannt offen bleibt

**Aus diesem Lauf:**

- **Die Konsolenmeldung auf `/services` und `/updates`** (§12.1). Sie ist nicht
  gemessen und wird deshalb nicht erklärt. `docs/114 §12` führt bereits eine
  ungeklärte; ob es dieselbe ist, sagt dieser Lauf nicht.

**Was der Lauf ausdrücklich nicht geprüft hat**, steht in `docs/905 §13` und ist
unverändert: dass die drei Sekunden kürzer werden (sie bleiben — der
Platzhalter verdeckt sie nicht, er macht sie erträglich), die neun übrigen
Agent-Seiten, die beiden ungemessenen Aufrufe `system.logs.tail` und
`web.logs.tail`, die Wirkung von `prefers-reduced-motion` auf einem Gerät, und
der Wortlaut der übrigen Prüfmeldungen.

**Unberührt davon** stehen die benannten Reste früherer Läufe (`docs/113 §13`,
`docs/114 §12`). Dieser Lauf hat sie nicht gemessen und sagt über sie nichts.

---

## §16 Die Abnahme

**Der Platzhalter für `/updates` ist am 11. September 2026 abgenommen** — auf
`cloudsrv24` gegen `0.7.4-rc.3`, alle zehn Punkte aus `docs/905`, die drei
Ausschlusskriterien (3, 4 und 8) darunter, keiner als „nicht herstellbar"
ausgefallen.

Der Plan ist `docs/904`, der Lauf `docs/905`, dieses Protokoll ist `docs/906`.

