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

## §3 bis §12 — offen

Gefahren sind §1 (Ausgangszustand) und §2 (Referenzwert). Es fehlen die zehn
Punkte selbst; sie laufen in der Browserkonsole und nicht auf der
Kommandozeile.

**Wo weitergemacht wird:** `docs/905 §3`, Punkt 1 — die Hülle. Von dort der
Reihe nach. Die Grenzwerte für Punkt 1 stehen am Ende von §2 dieses
Protokolls, gerechnet aus den gemessenen 45 ms.

**Was dabei nicht vergessen werden darf**, weil es zweimal in diesem Lauf
schon gezählt hat:

- Navigiert wird mit `$inertia.visit()` und nicht über die Adresszeile — sonst
  nimmt der Seitenaufbau die Konsole mit, und das Messskript sieht den
  Platzhalterzustand nie von innen.
- Die lebende Ablage steht in
  `document.getElementById('app').__vue_app__.config.globalProperties.$page`;
  das `script[data-page]` trägt die Seite vom Laden und überlebt jede
  Navigation.
- Punkt 4 hält den Agenten an: die Unit heisst **`srvpanel-agentd`** (§1.1).
  Danach stehen Worker und Metrik ebenfalls still — zurück mit
  `systemctl start srvpanel.target` und nicht mit einem `start` des Agenten
  allein.
