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

**Bei 1440 px erfüllt. Bei 390 px steht er noch aus.**

```
"kachelreiheMit":  [ 95.95 ],
"kachelreiheOhne": [ 95.95 ]
```

**Derselbe eine Wert vor und nach dem Ersetzen** — der Sprung ist **0 px**, und
zwar in *einem* Seitenaufbau gemessen, nicht aus zwei Läufen verglichen.

Das ist der Punkt, an dem der Bau einen Fehler hatte: Der erste Wurf trug
`margin: 2px 0` am Kachelplatzhalter, und die Seite sprang um 4 px bei 1440 und
**20 px bei 390** — alles darunter zog mit. Die Behebung hatte bis heute keinen
Server gesehen.

Der Containerwert war 95,94 px, hier sind es 95,95 — dieselbe Zahl bis auf die
Schriftmetrik.

**Bei 390 px ist zu wiederholen**, und das ist kein Formalismus: Dort stapeln
sich die fünf Kacheln, und derselbe Fehler wog fünfmal so viel.

---

## §6 bis §12 — offen

| Punkt | Stand |
|---|---|
| 1 — die Hülle | **erfüllt** (§3) |
| 2 — die Seite ist da | **erfüllt** (§4) |
| 3 — kein Sprung *(Ausschluss)* | **bei 1440 erfüllt**, bei 390 offen (§5) |
| 4 — toter Agent *(Ausschluss)* | offen |
| 5 — Farbe des Balkens | offen |
| 6 — die Bewegungsregel ist ausgeliefert | offen |
| 7 — die Prüfmeldung kommt oben an | offen |
| 8 — das Nachladen legt nichts an *(Ausschluss)* | offen |
| 9 — die Bilderrunde | offen |
| 10 — bedienbar in den drei Sekunden | offen |

**Als Nächstes:** Punkt 3 bei 390 px wiederholen — dort stapeln sich die
Kacheln, und derselbe Fehler wog beim Bauen fünfmal so viel. Danach `docs/905
§6`, Punkt 4.

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
