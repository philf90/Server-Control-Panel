# 918 — Protokoll zum Abnahmelauf der Reihenfolge

> Der Plan und der Lauf sind **`docs/917`**, die sechs Punkte stehen in dessen
> §6. Gefahren am 14. September 2026 auf `cloudsrv24` gegen **`0.7.4-rc.9`**.
> Dieses Protokoll wächst mit dem Lauf; was offen ist, steht offen da.

## §1 Punkt 1 — das Zeichen *(halb)*

Gefahren auf `/logs?source=agent&lines=200&order=oldest`.

| | gemessen |
|---|---|
| Fusszeile | „200 Zeilen von 500 Treffern · gelesen wurden die letzten 500 Zeilen" |
| Notiz | „Die Nummern zählen vom Ende: **↑1** ist die neueste Zeile." |
| Nummern | `↑200 ↑199 ↑198 …` |
| `−` auf der Seite | **keines** |

Die Notiz ist der Satz, den `docs/917 §6` Punkt 1 seit der Berichtigung verlangt
— sie bedeutet `complete = false`, und nur in diesem Zustand hat der Pfeil
überhaupt einen Gegenstand.

**Die Gegenprobe steht aus:** eine kurze Quelle, die „Das ist die ganze Quelle"
sagt und Nummern **ohne** Zeichen zeigt. Ohne sie ist belegt, dass der Pfeil
dasteht — nicht, dass er nur dort dasteht, wo er hingehört.

> **Eine Anzeige, die in einem Zustand stimmt, ist damit nicht auf den Zustand
> bezogen.**

## §2 Punkt 2 — die Umkehrung *(Ausschlusskriterium, erfüllt)*

Dieselbe Quelle, beide Reihenfolgen, je oben und unten abgelesen:

| Reihenfolge | erste | letzte | Zeitstempel oben | Zeitstempel unten |
|---|---|---|---|---|
| `oldest` | **↑200** | **↑1** | `11:39:48Z` | `12:01:44Z` |
| `newest` | **↑1** | **↑200** | `12:01:07Z` | `11:48:29Z` |

**Die beiden Nummern tauschen die Plätze und ändern sich nicht** — das ist der
Wortlaut des Punktes, und damit ist er erfüllt.

**Und die Zeitstempel tragen ihn doppelt.** Bei `oldest` laufen sie von oben
nach unten vorwärts, bei `newest` rückwärts. Damit ist nicht bloss die
Beschriftung gedreht, sondern der Inhalt — eine Umkehrung, die nur die Nummern
neu verteilte, sähe an den Nummern genauso aus.

> **Eine Beschriftung, die sich umdreht, belegt keine umgedrehte Liste — das tut
> erst ein Wert, den die Anzeige nicht vergibt.**

### Eine Beobachtung, kein Befund: zwei Fenster statt einem

Die Datei ist zwischen den Aufnahmen gewachsen — `1,9 MB · 13:51:39`, dann
`2 MB · 14:01:07`, dann `2 MB · 14:01:44`. Die beiden Reihenfolgen sind also an
**zwei verschiedenen Fenstern** abgelesen; `↑1` meint in beiden die neueste
Zeile des jeweiligen Fensters und damit nicht dieselbe Zeile.

Für den Wortlaut des Punktes ist das gleichgültig: Die Nummern sind
fensterrelativ und tauschen die Plätze so oder so. Was damit **nicht** gemessen
ist, ist der Satz „dieselbe Zeile heisst in beiden Ansichten gleich" — auf einem
wachsenden Protokoll lässt er sich nicht ablesen.

> **Ein Prüfkörper, der sich zwischen zwei Messungen verändert, trägt den
> Vergleich der Form und nicht den der Zeilen.**

Gemessen wird er von **Punkt 4b**: Der holt beide Reihenfolgen in einem Zug und
vergleicht erste gegen letzte Zeile — dasselbe Fenster, dieselbe Sekunde.

## §3 Punkt 3 — die Adresse trägt sie *(erfüllt)*

| | gemessen |
|---|---|
| nach dem Umschalten | `…/logs?filter=&lines=200&order=newest&source=agent` |
| Neuladen mit dieser Adresse | zeigt „Neueste zuerst", `↑1` oben |
| Gegenprobe `order=quatsch` | Auswahl steht auf **„Älteste zuerst"**, Nummern `↑200` abwärts |
| Fehlermeldung dabei | **keine** |

Der unbekannte Wert fällt also auf die Vorgabe zurück, statt die Seite
aufzuhalten. Das ist der Entwurf aus §3.3: Eine Ansicht ist keine Eingabe, die
man berichtigen muss.

## §4 Punkt 4a — der Knopf *(erfüllt in der Sache)*

Bei „Neueste zuerst" gedrückt. Die Datei heisst
`srvpanel-agent-20260914-120312.log` und läuft von `12:03:12Z` abwärts bis
`12:01:29Z` — **absteigend**, also in der Reihenfolge der Anzeige.

**Die Zeile für Zeile gleiche Deckung mit der Seite gibt es an dieser Quelle
nicht, und das ist kein Mangel des Prüflings.** Die Seite zeigte oben
`12:03:10Z`, die Datei beginnt bei `12:03:12Z` — dazwischen liegen genau die
beiden Zeilen, die das **Sichern selbst** erzeugt hat: `system.logs.tail` und
`system.logs.list`.

> **Ein Protokoll, das der Prüfling selbst beschreibt, verändert sich durch das
> Messen — und die gesicherte Datei kann der Anzeige dann nie Zeile für Zeile
> gleichen.**

`docs/917 §6` Punkt 4 nennt seitdem eine Quelle, die während der Messung nicht
wächst.

### Und meine eigene Berichtigung von heute früh war falsch

Vor dem Lauf hatte ich Punkt 4 geteilt mit der Begründung, zwei Sicherungen
derselben Quelle trügen denselben Dateinamen und der Browser hänge dem zweiten
ein `(1)` an. Der Dateiname aus dieser Messung widerlegt das:
`filename()` hängt `Ymd-His` an, zwei Sicherungen kollidieren also nur innerhalb
derselben Sekunde. Gelesen hatte ich den Aufruf `$this->filename($data['source'])`
und nicht die Methode dahinter.

> **Ein Wert, den nur der Aufruf nennt, ist eine Vermutung, bis jemand die
> Methode dahinter liest.**

Die Teilung bleibt trotzdem richtig — nur aus dem anderen Grund, der in §4
darüber steht. **Und der ist der teurere:** An `agent` hätte Punkt 4b gemeldet,
die Nummern tauschten **nicht**, weil zwischen den beiden Abrufen Zeilen
dazukommen. Das wäre ein Befund am Prüfling gewesen, den es nicht gibt.

> **Ein Prüfkörper, der seinen Gegenstand beim Messen verändert, meldet den
> Unterschied als Fehler des Gemessenen.**

## §5 Punkt 1 vollständig — die Gegenprobe *(erfüllt)*

`/logs?source=panel-update&lines=200&order=newest`, 36 Zeilen:

| | gemessen |
|---|---|
| Fusszeile | „36 Zeilen · gelesen wurden die letzten 36 Zeilen" |
| Notiz | „**Das ist die ganze Quelle**; die Nummern sind ihre Zeilen." |
| Nummern | `36 35 34 33 …` — **ohne Zeichen** |

Damit ist Punkt 1 ganz: Der Pfeil steht dort, wo die Lage nur vom Ende her
belegbar ist, und **nur** dort. Ohne diese Hälfte wäre gemessen, dass er
dasteht, nicht dass er auf einen Zustand bezogen ist.

**Und die Aufnahme trägt mehr, als der Punkt verlangt.** Sie steht auf „Neueste
zuerst", und die echten Dateizeilen laufen deshalb `36 … 1` — also abwärts. Das
ist der Beleg für „die Nummer wandert mit ihrer Zeile" im **zweiten** Zustand:
Punkt 2 hat ihn für das unvollständige Fenster gezeigt, hier steht er für die
ganz gelesene Quelle, wo die Nummer eine Dateizeile ist und keine Lage.

> **Eine Regel, die in zwei Zuständen gilt, ist in einem gemessen und im anderen
> behauptet — bis jemand auch dort hinsieht.**

## §6 Punkt 4b — beide Reihenfolgen in einem Zug *(Ausschlusskriterium, erfüllt)*

`source=panel-update&lines=20`, beide Abrufe über dieselbe Route wie der Knopf:

```
oldest  erste=SrvPanel: Rückweg bereitgestellt unter /opt/srvpanel/ro
oldest  letzte=apt-run: Fassung 0.7.4~rc.8 wurde zu 0.7.4~rc.9.
newest  erste=apt-run: Fassung 0.7.4~rc.8 wurde zu 0.7.4~rc.9.
newest  letzte=SrvPanel: Rückweg bereitgestellt unter /opt/srvpanel/ro
getauscht=true gleichLang=true
```

**`getauscht=true`** — erste und letzte Zeile tauschen die Plätze, und diesmal
sind es **dieselben** Zeilen: Die Quelle steht still, das Fenster ist in beiden
Abrufen dasselbe. Das ist der Vergleich, den Punkt 2 an `agent` nicht führen
konnte.

**`gleichLang=true` ist die Zugabe, nach der niemand gefragt hat** und die den
Punkt erst schliesst: Gleich lang heisst, dass beim Umdrehen keine Zeile
verlorengeht und keine doppelt steht. Ein Umkehren, das die erste Zeile
verschluckt, hätte `getauscht` trotzdem erfüllt.

> **Zwei Enden, die sich vertauschen, sagen über die Mitte nichts — die Länge
> schon.**

Das Fenster ist ablesbar richtig: 36 Zeilen, 20 abgerufen, also die Zeilen 17
bis 36. `oldest erste` ist Zeile 17 der Anzeige, `oldest letzte` Zeile 36.

## §7 Stand

| Punkt | Zustand |
|---|---|
| 1 — das Zeichen | **erfüllt** (beide Hälften) |
| 2 — die Umkehrung *(Ausschluss)* | **erfüllt** |
| 3 — die Adresse trägt sie | **erfüllt** |
| 4 — der Knopf *(Ausschluss)* | **erfüllt** (a und b) |
| 5 — die Tönung | offen |
| 6 — die Klebeprobe | offen |

**Beide Ausschlusskriterien stehen.** Was bleibt, sind die zwei Punkte, die eine
Konsole brauchen.
