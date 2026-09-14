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
