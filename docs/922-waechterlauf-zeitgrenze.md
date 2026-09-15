# 922 — Die Zeitgrenze des Wächterlaufs, und warum ihre Begründung nicht trägt

Aufgeschrieben am **15. September 2026**, nachdem der Befund beim Bau des
Steward-Skills (`.claude/skills/steward/SKILL.md`, PR #245) nebenbei anfiel. Er
steht hier und nicht in einem Gespräch, weil ein Befund, der nur dort steht,
fort ist, sobald das Gespräch fort ist.

**Dieses Dokument behebt nichts.** Es hält den gemessenen Bestand fest, widerlegt
eine Rechnung, die dabei entstanden ist, und sagt, was eine belastbare Grenze
noch braucht.

---

## §1 Was der Kommentar behauptet

`.github/workflows/waechter.yml` begründet `timeout-minutes: 30` so:

> 376 Eingriffe, 623 Testläufe, **gemessen 4:40** am 14. August 2026. Die Zahlen
> stehen hier, weil sie die Grenze darunter begründen — und sie sind
> nachgezogen worden, als P5c zwölf Eingriffe dazugelegt hat. Die Vorgabe von
> sechs Stunden wäre kein Schutz, sondern eine Rechnung […] Dreissig ist das
> Sechsfache des Gemessenen und immer noch eine Grenze, die etwas bedeutet.

Die Begründung ist sorgfältig, und sie ist zum Zeitpunkt ihres Schreibens
richtig gewesen. Sie sagt nur nichts darüber, was seither geschehen ist.

> **Eine Zahl im Kommentar altert mit dem Code, den sie zählt, und nichts meldet
> es.**

---

## §2 Was gemessen ist

**50 Läufe** von `waechter.yml` mit Ergebnis, 4. bis 15. September 2026,
ausgelesen über die Workflow-Läufe des Repositorys:

| | |
|---|---|
| kürzester | **13,0 min** |
| Median | **22,5 min** |
| längster | **28,0 min** |
| Grenze | 30 min |


**Der Steward-Skill nennt für dieselbe Grösse 23,2 Minuten, und beide Zahlen
stimmen.** Er misst die letzten Läufe am Ereignis `pull_request`, dieses
Dokument alle 50 Läufe mit Ergebnis im genannten Zeitraum — die beiden
wöchentlichen `schedule`-Läufe also mit. Wer die Zahlen nebeneinanderlegt, liest
zwei Grundgesamtheiten und keinen Widerspruch; wer eine von beiden ohne ihre
Grundgesamtheit zitiert, erzeugt einen.

> **Zwei Messungen derselben Grösse über verschiedene Grundgesamtheiten sind
> kein Widerspruch — bis jemand eine davon ohne ihre Grundgesamtheit
> weiterschreibt.**

**Die Zahl der Eingriffe**, gezählt als `grep -c 'vorher_datei'` über
`tests/waechter-brechen.sh` am jeweiligen Stand von `main`:

| Datum | Eingriffe |
|---|---|
| 4. September | 1132 |
| 8. September | 1245 |
| 11. September | 1296 |
| 13. September | 1328 |
| 14. September | 1336 |
| 15. September | **1342** |

Aus „376 Eingriffe, gemessen 4:40, das Sechsfache" ist damit **1342 Eingriffe,
Median 22,5, das 1,07-fache des längsten gemessenen Laufs** geworden.

---

## §3 Die Rechnung, die dabei entstand — und widerlegt ist

Beim ersten Ausschreiben stand hier: *„1342 Eingriffe ergeben 28 min, also
reissen etwa 1431 Eingriffe die Grenze."* Das war eine Hochrechnung aus einem
Messwert und einer unausgesprochenen Annahme — dass die Laufzeit der
Eingriffszahl folgt.

**Der Lauf zu PR #245 hat sie widerlegt, bevor sie eine Woche alt war.** Er fuhr
mit 1342 Eingriffen, also sechs mehr als je zuvor, und brauchte **21,9 min** —
weniger als der Median mit 1336.

> **Eine Zahl, die aus einer Rechnung folgt, ist so gut wie die Annahme, auf der
> die Rechnung steht.**

---

## §4 Die Eingriffszahl erklärt die Laufzeit nicht — gemessen

Sechs Läufe vom 14. und 15. September, mit der Zahl der Eingriffe **in dem
Stand, den sie geprüft haben**:

| Lauf (UTC) | Dauer | Eingriffe |
|---|---|---|
| 14.09. 04:59 | 28,0 min | 1328 |
| 14.09. 09:35 | 27,3 min | 1328 |
| 14.09. 18:15 | 18,1 min | 1328 |
| 14.09. 19:36 | 15,3 min | 1336 |
| 15.09. 11:26 | 27,9 min | 1336 |
| 15.09. 14:07 | 21,9 min | 1342 |

**Drei Läufe über denselben Stand mit 1328 Eingriffen brauchten 28,0, 27,3 und
18,1 Minuten** — eine Spanne von 9,9 Minuten bei *unveränderter* Datei. Bei
1336 Eingriffen stehen 15,3 gegen 27,9 Minuten, also **82 % Unterschied am
selben Stand**.

> **Zwei Läufe desselben Prüfmittels über denselben Stand, die um das
> Achtzigfache eines Prozents auseinanderliegen, messen etwas, das nicht im
> Prüfling steht.**

Ein Trend über die Zeit ist daneben durchaus da — die ältere Hälfte der 50 Läufe
hat einen Median von 21,4 min, die jüngere von 24,1 —, aber er verschwindet
neben der Streuung: **2,7 Minuten Verschiebung gegen rund 12 Minuten Spanne
innerhalb jeder Hälfte.**

> **Ein Trend, der kleiner ist als die Streuung, lässt sich an einem einzelnen
> Lauf nicht ablesen — und eine Grenze, die aus einem einzelnen Lauf folgt, ist
> geraten.**

---

## §5 Was damit offen ist

**Was die Laufzeit treibt, ist ungemessen.** Drei Kandidaten, keiner geprüft:

1. **Die Last des Runners.** Die drei 1328er-Läufe verteilen sich über einen
   Tag; der schnellste lag abends. Ein Zusammenhang mit der Tageszeit wäre
   plausibel und ist nicht belegt — sechs Läufe sind dafür zu wenig, und
   „plausibel" ist in diesem Repo kein Befund.
2. **Der Zwischenspeicher.** `composer install --prefer-dist` trifft oder trifft
   nicht; ein Fehltreffer kostet Minuten, die mit den Eingriffen nichts zu tun
   haben.
3. **Welche Testklassen die Eingriffe treffen.** Das Skript fährt je Eingriff
   einen Testlauf, und die Klassen sind verschieden teuer. Zwanzig Eingriffe auf
   eine billige Klasse kosten weniger als fünf auf eine teure — die blosse Zahl
   sagt darüber nichts.

**Zu messen ist die Streuung und nicht ein Lauf.** Drei bis fünf Läufe desselben
Standes, damit „langsamster Lauf" eine Zahl mit Bedeutung wird. Zwei Wege stehen
offen, und sie messen Verschiedenes:

- **`workflow_dispatch` mehrfach auslösen** — misst die echte Umgebung samt
  Runner-Streuung, also das, woran die Grenze wirklich hängt.
- **Lokal im Container**, wo sich `vendor/` herstellen lässt (`CLAUDE.md` →
  „Diese Umgebung") — misst den Lauf ohne Runner-Streuung. **Die Differenz
  zwischen beiden ist selbst die interessante Zahl**, weil sie sagt, wie viel
  der Streuung überhaupt im Skript steckt.

**Während ein lokaler Lauf fährt, wird nicht am Repo gearbeitet.**
`tests/waechter-brechen.sh` stellt den Arbeitsbaum nach jedem Eingriff mit
`git checkout --` her und nimmt einem zweiten Schreiber seine Arbeit weg — am
26. August 2026 genau so geschehen.

---

## §6 Was der Betreiber entscheidet

**Die Zahl ist nicht die eigentliche Frage.** Ein Lauf, der mit dem Bestand
wächst, läuft irgendwann in jede feste Grenze; 1132 → 1342 Eingriffe in elf
Tagen sind rund neunzehn Prozent. Die Grenze höherzusetzen verschiebt den Tag,
an dem sie wieder zu eng ist.

Die Alternative wäre, den Lauf zu teilen — eine Matrix über Eingriffsgruppen,
die parallel fahren. Das ist ein Umbau am Werkzeug, das die Wächter dieses Repos
prüft, und **keine Entscheidung, die nebenbei fällt**. Sie gehört vorgelegt und
nicht getroffen.

**Und ein abgeschnittener Lauf sieht aus wie ein roter.** Das ist der Grund,
warum die Frage überhaupt drängt: Nicht die verlorene Zeit, sondern dass der
Ausfall sich als Befund liest — und der Nächste am Skript sucht statt an der
Grenze. Der Steward-Skill hält das seit dem 15. September in §5 fest.

---

## §7 Was kein Wächter halten kann

**Die Eingriffszahl im Kommentar ist prüfbar**, die Laufzeit nicht. Ein Wächter
könnte die genannte Zahl gegen
`grep -c 'vorher_datei' tests/waechter-brechen.sh` halten und den Tag melden, an
dem sie auseinanderlaufen. Nach der Gewohnheit dieses Repos bräuchte er einen
Eingriff in `tests/waechter-brechen.sh`, der ihn absichtlich bricht, und den
Beleg, dass er dabei zubeisst. Als Vorbild für eine solche Naht zwischen
Dokument und Workflow liegt `tests/Unit/StewardSkillTest.php` im Repo; er hält
Job- und Schrittnamen in beide Richtungen und läuft framework-frei.

**Gegen die Laufzeit hilft kein Wächter.** Sie hängt an einer Umgebung, die das
Repo nicht kennt. Was bleibt, ist das Datum neben der Zahl — und die Gewohnheit,
es mitzuschreiben.

> **Eine Zeile, die einen Zustand behauptet, veraltet ohne Vorwarnung — und
> nichts prüft sie.**
