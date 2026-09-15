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

## §5 Was doch skaliert — und wie lange welche Grenze trägt

**§4 sagt, woran die Laufzeit *nicht* hängt. Das ist die halbe Antwort.** Über
einen einzelnen Tag dominiert die Streuung; über einen Monat ist der Trend
eindeutig, und er hat eine Einheit: **nicht den Eingriff, sondern den Testlauf.**
1342 Eingriffe erzeugen 2479 `pruefe`-Aufrufe, also 1,85 je Eingriff.

| Stand | Testläufe | Dauer | je Testlauf |
|---|---|---|---|
| 14. August (laut Kommentar) | 623 | 4:40 | **0,45 s** |
| 15. September, Medianlauf | 2479 | 21:29 | **0,52 s** |
| 15. September, langsamster | 2479 | 28:00 | **0,68 s** |

Über einen Monat und das Vierfache an Testläufen bleibt der Wert in derselben
Grössenordnung. Die Streuung liegt als Faktor 1,8 darum herum.

> **Ein Trend, der kleiner ist als die Streuung, ist deshalb nicht falsch — er
> ist nur an einem einzelnen Lauf nicht ablesbar.**

**Das Skript wächst um rund 24 Eingriffe und damit 44 Testläufe am Tag** (983 →
1342 in fünfzehn Tagen, +36,5 %). Gegen den **langsamsten** gemessenen Wert
gerechnet:

| Grenze | Kapazität | Puffer | trägt noch |
|---|---|---|---|
| 30 min *(bis zum 15. September)* | 2647 | 168 | **4 Tage** |
| **60 min** *(seitdem)* | 5294 | 2815 | **64 Tage** |
| 90 min | 7941 | 5462 | 124 Tage |
| vier parallele Jobs, 30 min | 10 588 | 8109 | **184 Tage** |

**Der Puffer war vier Tage und nicht Wochen.** Das ist der Grund, warum die
Grenze am 15. September auf sechzig Minuten gesetzt wurde, bevor die Messung aus
§6 gemacht war: Eine Zeile, die zwei Monate kauft, wartet nicht auf eine
Messung, die eine Woche dauert.

**Und der Anteil des Rüstens ist der Grund, dass eine Teilung überhaupt lohnt:**
Setup (Checkout, PHP, `composer install`, Schlüssel) kostet **15 Sekunden**, das
Skript 21:29 — **1,2 Prozent**. Vier Jobs kosten also vier mal fünfzehn Sekunden
mehr und bringen die Wanduhr von 21,5 auf **5,6 Minuten**; die CI-Minuten
steigen um **fünf Prozent**.

**Der Nebeneffekt wiegt dabei schwerer als die Zeitgrenze.** Bei 5,6 Minuten
wäre der Taktgeber eines Pull Requests wieder `ci.yml` mit 3,0 Minuten statt
dieses Laufs — ein PR wäre in etwa sechs statt fünfundzwanzig Minuten beurteilt.
`.claude/skills/steward/SKILL.md §2` müsste dann neu geschrieben werden.

---

## §6 Was damit offen ist

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

## §7 Was der Betreiber entscheidet

**Es sind zwei Entscheidungen und nicht eine.** Die erste ist am
15. September 2026 getroffen, die zweite ausdrücklich nicht.

### Getroffen: die Grenze steht auf sechzig Minuten

Gerechnet und nicht gegriffen, nach §5: Gegen den langsamsten gemessenen Wert
trug die alte Grenze noch **vier Tage**, sechzig Minuten tragen rund **zwei**
**Monate**. Neunzig trügen vier Monate und wären keine Grenze mehr, die etwas
bedeutet; fünfundvierzig trügen einen Monat, also kaum länger, als das
Nachziehen selbst wieder kostet.

**Sie ist gesetzt worden, bevor die Messung aus §6 gemacht war**, und das ist
kein Widerspruch zur Sorgfalt dieses Repos, sondern ihre Anwendung: Eine Zeile,
die zwei Monate kauft, wartet nicht auf eine Messung, die eine Woche dauert.

> **Ein abgeschnittener Lauf liest sich als roter — und der Nächste sucht dann
> am Skript statt an der Grenze.**

### Nicht getroffen: die Teilung auf parallele Jobs

Die Zahlen sprechen dafür (§5): Wanduhr von 21,5 auf 5,6 Minuten, CI-Minuten
+5 %, und die Grenze trüge ein halbes Jahr statt zwei Monate. **Der Nebeneffekt
wiegt dabei schwerer als die Zeit** — bei 5,6 Minuten wäre der Taktgeber eines
Pull Requests wieder `ci.yml`, und ein PR wäre in sechs statt fünfundzwanzig
Minuten beurteilt.

**Trotzdem ist sie offen, und zwar aus einem Grund, der in `CLAUDE.md` steht:**

> **Ein Eingriff, der einzeln beisst, beisst nicht unbedingt im Lauf** — er
> steht dort neben anderen, und die verändern seinen Gegenstand.

Der Satz kam aus einem echten Fall (20. August 2026). Trägt er, dann ändert eine
Teilung die Nachbarschaft der Eingriffe und könnte Befunde verstecken. Gemessen
spricht dagegen — **1384 Wiederherstellungen auf 1342 Eingriffe**, kein `npm`,
kein `composer`, kein `migrate` im Skript, und an globalem Zustand nur die drei
Zähler —, aber „spricht dagegen" ist kein Beleg.

**Die Reihenfolge ist deshalb: erst messen, dann teilen.** Denselben Stand
einmal ganz und einmal in vier Teilen fahren und die Befundlisten vergleichen.
Kommen dieselben heraus, ist der Satz für die Teilung unschädlich; kommen sie
auseinander, ist die Teilung vom Tisch — und der Befund ist mehr wert als die
Zeitersparnis.

Zwei kleinere Punkte für den Fall, dass geteilt wird: Die Teilung muss **stabil**
sein — nach Zeilennummern wäre falsch, weil jeder neue Eingriff sie verschiebt;
nach den 990 Abschnitten ginge es. Und `BreakScriptTest` hält das Skript mit
zwölf Fällen, von denen mindestens `test_a_workflow_runs_the_script` und
`test_nothing_stands_behind_the_exit` mitziehen müssten.

### Was ausdrücklich nicht in Frage steht

**Die Grenze zu streichen.** Eine Hängepartie ohne sie blockiert den Runner bis
zum Maximum von sechs Stunden, und seit der Lauf an jedem Pull Request hängt,
blockiert sie nicht mehr nur eine Nacht, sondern jemandes Arbeit. Der Satz aus
der ersten Fassung gilt unverändert: Eine Grenze soll etwas bedeuten.

---

## §8 Was kein Wächter halten kann

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
