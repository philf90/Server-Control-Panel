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

```
srvpanel tinker --execute='…'
system.sources.list      45 ms
system.packages.list   3644 ms
```

| Aufruf | hier | `docs/904 §1` (10. September) |
|---|---|---|
| `system.sources.list` | **45 ms** | 36 / 35 ms |
| `system.packages.list` | **3644 ms** | 2998 / 3033 ms |

Beide in derselben Grössenordnung wie bei der Messrunde. Der teure Aufruf ist
hier eine halbe Sekunde langsamer als am 10. September — dieselbe Streuung,
die schon zwischen kalt und warm lag.

**Und die Schwelle aus `docs/904 §2` greift nicht:** 45 ms liegen weit unter
300, `system.sources.list` bleibt also zu Recht synchron. Damit ist die eine
Frage beantwortet, die dieser Lauf an den Plan hatte und nicht an den
Prüfling.

*(Der zweite Lauf der Messung steht noch aus; der Lauf verlangt ihn, weil eine
einmalige Messung den Zwischenspeicher mitmisst.)*

---

## §3 bis §12

*(folgen)*
