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
