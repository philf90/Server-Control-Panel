# 919 — Die Domainseite weiss nicht, wie viel sie zeigt

Befund 14 an seinem zweiten Ort. Geschrieben am 14. September 2026 **nach** der
Messrunde in §1.

---

## §0 Warum das kein neues Vorhaben ist

`docs/914` hat Befund 14 aus `docs/86` für `/logs` gebaut und abgenommen
(`docs/916`, alle acht Punkte). Sein §12 nennt beim Namen, was dabei
liegengeblieben ist:

> Und `web.logs.tail` sendet `complete` und `capped` jetzt mit, **ohne dass die
> Domainseite sie zeigt** — dieselbe Fusszeile hat dort dasselbe Problem. Das
> ist benannt und nicht gebaut; wer es anfasst, fängt hier an und nicht bei
> null.

Das ist die Familie, die dieses Repo am häufigsten trifft, und es wäre ihr
sechstes Mal:

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.**

Zweimal war es der Menüpunkt, der zu tief lag (`docs/59`, `docs/64`), einmal die
ACME-Prüfdatei gegen `CronApply::SPOOL_DIR` (`docs/78`), einmal der Streifen
ohne Tabellen darunter (`docs/113`, `docs/114`, dort zweimal in zwei Tagen).
**Hier ist der Ort der Wiederholung schon bekannt, bevor sie passiert** — und
deshalb geht es in diesem Vorhaben nicht nur um die Domainseite, sondern um den
Wächter, der die Regel halten soll und heute genau eine Seite kennt (§8).

---

## §1 Die Messrunde

Elf Messungen gegen `WebLogsTail::tail()` mit selbstgebauten Dateien, gefahren
am 14. September 2026 im Container. Der Leser ist derselbe wie auf `/logs`;
gemessen wird, was er **dieser** Seite mit **ihren** Anfragewerten liefert.

### M1 — die Naht: sieben gesendet, drei davon fallen

`web.logs.tail` gibt heute **sieben** Felder zurück:

    path  kind  lines  exists  size  complete  capped

`DomainController::logs()` behält **drei** davon (`lines`, `exists`, `path`) und
legt `error` dazu. **`size`, `complete` und `capped` fallen im Controller.**

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt.**

### M2 — der Bytedeckel greift auch bei `lines=100`

`MAX_BYTES` ist 512 KiB. Gemessen an einer Datei mit 300 Zeilen, `lines=100`:

| Zeichen je Zeile | geliefert | `capped` |
|---|---|---|
| 200 | 100 | nein |
| 1048 | 100 | nein |
| 2000 | 100 | nein |
| **5242** | **100** | **ja** |
| 6000 | 87 | ja |
| 12000 | 43 | ja |

**Die Zeile mit 5242 ist die lehrreiche:** Der Deckel hat gegriffen, und
trotzdem sind alle hundert angefragten Zeilen da.

> **Ein Deckel, der greift, heisst nicht, dass weniger geliefert wurde — er
> heisst, dass nicht weiter zurück gelesen wurde.**

### M2b — und bei `lines=500` schon ab 1048 Bytes

600 Zeilen, `lines=500`: bei 1048 Zeichen kommen 500 (capped), bei 1100 nur
**476**, bei 2000 nur **262**. Die gerechnete Schwelle `512 KiB ÷ 500 = 1048 B`
ist damit gemessen.

Das trifft genau die Protokolle einer Domain, die man liest, wenn etwas kaputt
ist: Ein nginx-`error.log` mit Stacktraces liegt regelmässig darüber.

### M3 — eine kurze Datei, und der Knopf steht trotzdem da

36 Zeilen, `lines=100`: 36 geliefert, `complete = ja`, `capped = nein`. Die
Seite zeigt **trotzdem** „Mehr Zeilen (100 → 200)", weil ihre Bedingung
`props.lines < 500` lautet. Dreimal drücken, dreimal nichts — derselbe Satz, den
`docs/914 §2` für `/logs` aufgeschrieben hat.

### M9 — mit Deckel bringt eine grössere Anfrage **nichts**

Dieselbe Datei, dreimal gefragt:

| Zeichen je Zeile | `lines` | geliefert | erste Zeile |
|---|---|---|---|
| 6000 | 100 / 200 / 500 | 87 / 87 / 87 | 000314 / 000314 / 000314 |
| 12000 | 100 / 200 / 500 | 43 / 43 / 43 | 000358 / 000358 / 000358 |

Byteweise dasselbe Ergebnis. **Das entscheidet den Knopf** (§4).

### M10 — ohne Deckel bringt sie sehr wohl mehr

400 Zeilen à 200 Zeichen:

| `lines` | geliefert | erste Zeile | `complete` |
|---|---|---|---|
| 100 | 100 | 000301 | nein |
| 200 | 200 | 000201 | nein |
| 500 | 400 | 000001 | **ja** |

Anders als auf `/logs` liest der Knopf hier wirklich weiter zurück: Dort ist das
Fenster immer 500 Zeilen gross und `lines` schneidet nur; hier **ist** `lines`
das Fenster.

### M11 — die Gegenprobe

Kurze Datei, 36 Zeilen: `lines=100` und `lines=500` geben beide 36, `complete`
beide Male wahr. Ohne diese Zeile sagte M10 nur, dass sich etwas ändert, und
nicht, dass es sich bei erreichtem Anfang **nicht** ändert.

### M6 — die Fusszeile gibt es gar nicht

`grep -c 'gelesen wurden'`: `Domains/Logs.vue` **0**, `Logs/Index.vue` **2**. Die
Domainseite behauptet nichts Falsches über den Umfang — sie sagt dazu nichts.

> **Eine Seite, die nichts über ihre Grenze sagt, lässt den Leser annehmen,
> dass es keine gibt.**

### M12 — der Wächter kennt genau ein Paar, und sein Ausdruck misst zufällig richtig

`LogFooterTest` bindet `SystemLogsTail` und `Logs/Index.vue` über zwei
Konstanten. Sein Ausdruck für „was sendet der Agent" liest **den ganzen Rumpf**
von `execute()` und sucht `'feld' =>`. Gemessen:

| Operation | heutiger Ausdruck | nur die `return`-Blöcke |
|---|---|---|
| `SystemLogsTail` | 12 | 12 |
| `WebLogsTail` | **11** | **7** |

Die vier zuviel sind `subscription`, `user`, `domain` und `document_root` — die
Argumente von `Site::fromArgs()`. Bei `SystemLogsTail` gibt es nichts
dergleichen, und deshalb ist das Ergebnis dort richtig.

> **Ein Ausdruck, der über den ganzen Rumpf liest, misst die Rückgabe nur so
> lange, wie der Rumpf sonst nichts Ähnliches enthält.**

Gefunden hat das nicht das Nachdenken, sondern der Versuch, den Wächter auf das
zweite Paar zu richten — **vor** dem Bauen.

---

## §2 Was die Seite heute behauptet

Die Domainseite zeigt einen Pfad, einen Textblock und einen Knopf. Drei
Auskünfte fehlen, und jede fehlt auf eine andere Art:

1. **Wie viel von der Datei das ist.** Nicht falsch gesagt, sondern gar nicht
   (M6).
2. **Dass der Bytedeckel gegriffen hat.** `capped` kommt an und fällt im
   Controller (M1). Nach aussen sieht der gedeckelte Fall Zeichen für Zeichen
   aus wie eine kurze Datei (M2).
3. **Ob der Knopf etwas bewirkt.** Er steht unter `props.lines < 500` und damit
   auch, wenn alles zu sehen ist (M3) oder wenn eine grössere Anfrage nichts
   ändert (M9).

---

## §3 Die Naht — kein neues Feld

**Der Agent bekommt keine Zeile.** Alles, was die Seite braucht, sendet er seit
`docs/914`: `complete`, `capped` — und `size`, das er schon vorher sendet. Der
Controller hört auf, sie wegzuwerfen.

**`read` braucht diese Seite nicht, und das ist der Unterschied zu `/logs`.**
Dort ist `read` die Fenstergrösse, aus der ein **Filter** auswählt; die Zahl
sagt, woraus die Treffer stammen. Hier gibt es keinen Filter: Die Seite fragt
hundert Zeilen und bekommt hundert. `read` wäre hier die Zahl der Zeilen, die
der blockweise Leser zufällig mitgelesen hat — eine Eigenschaft des Verfahrens
und keine Auskunft über die Datei.

> **Eine Zahl, die an einer Stelle eine Auskunft ist, ist an der anderen ein
> Blick in die Maschine.**

---

## §4 Der Knopf hängt an einer anderen Frage als auf `/logs`

Auf `/logs` lautet sie `truncated` — es gibt mehr Treffer als gezeigte Zeilen.
Diesen Begriff gibt es hier nicht. Gemessen ist die Frage dreiteilig, und jeder
Teil hat seine Messung:

| Teil | warum | Messung |
|---|---|---|
| `! complete` | der Anfang der Datei ist schon erreicht | M11 |
| `! capped` | eine grössere Anfrage liefert byteweise dasselbe | **M9** |
| `lines < 500` | `MAX_LINES` ist die Grenze der Operation | — |

`truncated` hierher zu kopieren wäre die zweite Fassung einer Regel, die hier
etwas anderes bedeutet.

---

## §5 Die Form auf der Seite

Eine Fusszeile mit **drei** Zuständen, die einander ausschliessen — `capped`
setzt `$position > 0` voraus und ist mit `complete` nie zugleich wahr:

| Zustand | Satz | Knopf |
|---|---|---|
| `complete` | „36 Zeilen · das ist die ganze Datei." | nein |
| weder noch | „100 Zeilen · die Datei ist länger." | ja |
| `capped` | „87 Zeilen · weiter zurück wurde nicht gelesen; das Fenster ist auch in Bytes begrenzt." | nein |

**Drei Sätze und nicht einer mit Einschüben.** Der gedeckelte Fall ist der, den
es ohne die Messrunde nicht gäbe, und er ist der einzige, in dem ein Betreiber
etwas anderes tun muss als klicken — er greift zur Datei.

**Die Grösse steht neben dem Pfad**, wie auf `/logs`: Sie wird gesendet, und ein
gesendetes Feld, das niemand zeigt, ist der Befund aus M1.

**Keine Zahl ohne Zähler:** `counted()` wie überall, damit „1 Zeile" nicht „1
Zeilen" heisst.

---

## §6 Entscheidungen für den Betreiber

1. **Bekommt die Domainseite die Nummernspalte?** — **Nein, entschieden am
   14. September 2026.** Die Begründung des Betreibers: Auf dieser Seite
   erschliesst sich ihr Sinn nicht. Das ist eine Entscheidung und keine
   Vertagung; was dafür gemessen wurde, steht in §15.
2. **Nennt der Satz beim Deckel die Bytegrenze?** Vorgeschlagen ist der Grund
   ohne die Zahl („auch in Bytes begrenzt"), weil 512 KiB dem Kunden nichts
   sagt und der Betreiber sie in `docs/914` findet.
3. **Bleibt „Mehr Zeilen" beim Deckel als anderer Knopf stehen** — etwa
   „Herunterladen"? Dieser Wurf lässt ihn weg; ein Knopf, der mehr verspricht,
   als der Weg dahinter trägt, ist eine Zusage und keine Bequemlichkeit.

---

## §7 Der Bau

1. `DomainController::logs()` reicht `complete`, `capped` und `size` durch.
2. `Domains/Logs.vue` bekommt die Fusszeile aus §5 und den Knopf aus §4.
3. `LogFooterTest` hält **Paare** statt eines Paares, und seine Feldliste kommt
   aus den `return`-Blöcken statt aus dem Rumpf (§8).
4. Je neue Regel ein Eingriff in `tests/waechter-brechen.sh`, **vor** dem
   abschliessenden `exit "$fehler"`.
5. Bilderrunde im Container gegen die echte Seite, vier Lagen, alle drei
   Zustände aus §5 hergestellt.

---

## §8 Die Wächter

**`LogFooterTest` wird von einem Paar auf eine Liste von Paaren umgestellt.**
Das ist der eigentliche Gegenstand dieses Vorhabens: Solange er eine Seite
kennt, ist die Behebung eine Behebung und keine Regel — und das dritte Protokoll
hätte den Befund wieder.

Zwei Änderungen, beide gemessen (M12):

- **Die gesendeten Felder kommen aus den `return`-Blöcken** und nicht aus dem
  ganzen Rumpf. Gegengeprüft in beide Richtungen: `SystemLogsTail` bleibt bei
  zwölf, `WebLogsTail` fällt von elf auf sieben, und ein Prüfkörper mit einem
  Argumentfeld neben einer verschachtelten Rückgabe liefert `aussen tief innen`
  und **nicht** `subscription user`.
- **Je Paar steht der Name der Ablage dabei** — `props.result` auf `/logs`,
  `props.log` auf der Domainseite. Ein Wächter, der `props\.result\.` fest
  einbaut, misst auf der zweiten Seite **null** gelesene Felder und meldete
  jedes gesendete als ungelesen.

Dazu die Untergrenze je Paar: Unter fünf gefundenen Feldern greift der Ausdruck
ins Leere, statt zu messen.

**`LogButtonTest` gibt es nicht als zweiten Wächter.** Die Knopfbedingung
gehört zu derselben Naht und steht in `LogFooterTest` — je Seite mit **ihrer**
Bedingung, denn die beiden sind verschieden (§4), und ein Wächter, der beide
über einen Ausdruck prüfte, hielte die falsche Zusage.

---

## §9 Abnahmekriterium

Auf einem echten Server, an einer echten Domain:

1. Ein Protokoll, das **kürzer** ist als das Fenster: Die Fusszeile sagt „das
   ist die ganze Datei", und „Mehr Zeilen" steht **nicht** da.
2. Ein Protokoll, das **länger** ist: Die Fusszeile sagt, dass die Datei länger
   ist, der Knopf steht da, und beim Drücken kommen mehr Zeilen — belegt an der
   **ersten** Zeile, nicht an der Zahl.
3. Ein Protokoll mit Zeilen über 5242 B (M2 auf dem Server): Die Seite sagt,
   dass sie am Bytedeckel abgebrochen hat, und der Knopf steht **nicht** da.
   **Darf nicht ausfallen** — das ist der Fall, den es ohne die Messrunde nicht
   gäbe.
4. Die Dateigrösse steht neben dem Pfad und stimmt mit `ls -l` überein.
5. Der Kunde sieht dieselbe Fusszeile wie der Betreiber — die Seite gehört ihm.
   **Darf nicht ausfallen.**
6. Bei 390 px: `dokument = 0`, Gegenprobe 200/200, in beiden Themen.
7. Gegenprobe zu Punkt 3: Dieselbe Domain mit einem Protokoll unter der
   Schwelle zeigt den Satz **nicht**.

---

## §10 Was dieses Vorhaben ausdrücklich nicht wird

- **Keine Nummernspalte auf der Domainseite** — entschieden und nicht vertagt
  (§6 Punkt 1, die Messungen dazu in §15).
- **Kein Filter.** `/logs` hat einen, diese Seite nicht; ein Filter über das
  Fenster wäre ein eigenes Merkmal mit eigener Fusszeile.
- **Kein zweiter Leser.** `WebLogsTail::tail()` bleibt die eine Stelle — sie
  bekommt hier nicht einmal mehr Auskunft, sie wird nur endlich gelesen.
- **Kein Erhöhen von `MAX_BYTES`.** Entschieden in `docs/914 §6`; die Messung
  hier ändert daran nichts.
- **Kein Anfassen von `/logs`.** Die Seite ist am 14. September gegen
  `0.7.4-rc.9` abgenommen; was sich an ihr ändert, ist der Wächter darüber und
  keine Zeile ihres Quelltextes.

---

## §11 Was nicht gemessen ist

- **Was der Bytedeckel auf `cloudsrv24` wirklich trifft.** Die Schwelle ist
  gemessen, die Zeilenlängen der dortigen `error.log` sind es nicht. Punkt 3
  des Abnahmekriteriums stellt den Zustand deshalb her, statt ihn zu suchen.
- **Ob ein Kunde die Fusszeile so liest, wie sie gemeint ist.** Das hängt an
  einer Erwartung und nicht an einer Eigenschaft des Quelltextes — kein Wächter
  kann es halten, und deshalb steht es hier als Frage.
- ~~**Die Nummernspalte für den Kunden.**~~ **Gemessen und entschieden** am
  14. September 2026 — §15. Sie wird nicht gebaut.

---

## §12 Was beim Bauen anders war als im Plan

Gebaut am 14. September 2026. **Fünf Funde, vier davon am Wächter** — und keinen
hat das Nachdenken gemacht: drei kamen von einem Eingriff oder von einem Lauf,
einer vom Versuch, den Wächter auf das zweite Paar zu richten.

### Fund 1 — `kind` wird gesendet und von niemandem gelesen

Beim Eintragen des zweiten Paares fiel auf, dass `web.logs.tail` seinen
Argumentwert `kind` zurückspiegelt. Ausgezählt liest ihn niemand: Der Umschalter
der Seite nimmt `props.kind`, und das setzt der Controller selbst.

Er steht deshalb **nicht** in `ohne_anzeige`, sondern ist aus der Operation
entfernt — nach dem Vorbild von `origin` bei `system.logs.tail` (`docs/914 §12`,
Fund 3). Eine Ausnahme hätte den Befund zugedeckt, den der Wächter machen soll.

### Fund 2 — der Fehlschlag lag **in** der Antwort

Der erste Lauf des erweiterten Wächters war sofort rot: *„Die Seite zu
`web.logs.tail` liest `error`, und der Agent sendet es nicht."* Er stimmte.
`$result['error']` kam vom Controller und stand zwischen den Feldern des
Agenten; `/logs` hält ihn seit jeher daneben.

> **Eine Ablage, die zwei Herkünfte mischt, lässt sich nicht gegen ihre Quelle
> halten.**

Die zweite Seite ist nach der ersten gerichtet worden und nicht die Regel nach
der zweiten Seite.

### Fund 3 — der Controller war von keiner Regel erfasst

Der Wächter hielt Agent und Seite aneinander. **Dazwischen sitzt der
Controller, und genau dort ist dieser Befund entstanden.** Ein Feld, das er
fallen lässt, kommt auf der Seite nicht an — beide Enden passen weiter
zusammen.

> **Zwei Enden, die zusammenpassen, sagen über die Strecke dazwischen nichts.**

Die Regel lautet jetzt: **Wer Felder einzeln nennt, nennt alle — wer keines
nennt, reicht die Antwort im Ganzen durch.** Gemessen sind beide Zweige:
`LogsController` nennt **null** Felder und weist `$result = $answer` zu,
`DomainController` nennt **sechs**. Kein Zweig wird übersprungen.

### Fund 4 — ein Eingriff, der nicht biss, hat eine fehlende Regel gezeigt

Der `capped`-Zweig der Fusszeile durch `false` ersetzt liess den Wächter
**grün**: Das Feld blieb im `computed` des Knopfes stehen, wurde also gelesen.
Die Seite hätte den Knopf richtig versteckt und nie gesagt, warum — also genau
den Zustand hergestellt, den Befund 14 beschreibt.

> **Ein Eingriff, der nicht beisst, ist entweder schlecht gewählt — oder er
> zeigt eine Regel, die es nicht gibt.**

Gefragt wird seitdem der **Vorlagenblock** und nicht die Datei: Ein Feld, das
nur im Skript vorkommt, steuert etwas und sagt nichts. Gegengeprüft an **beiden**
Paaren, auch an `/logs`, das schon abgenommen ist.

### Fund 5 — die Knopfregel war zuerst zu weit, und ein Wächter hat es gemeldet

`LogFooterTest::test_the_button_hangs_on_there_being_more` verlangte für `/logs`
wörtlich `props.result.truncated`. Beim Umbau ist daraus „die Bedingung nennt
**ein** gesendetes Feld" geworden — und `props.result.read > 0` hätte das
erfüllt.

Gemeldet hat es nicht das Nachdenken, sondern
`BreakScriptTest::test_every_check_names_a_test_that_exists`: Ein bestehender
Eingriff nannte den alten Fallnamen, und beim Nachziehen war nachzulesen, was
der alte Fall zugesagt hatte.

> **Ein Wächter, der eine Regel verallgemeinert, verliert die Zusage der
> besonderen — und merkt es nur, wenn etwas den alten Namen festhält.**

Verlangt wird jetzt je Paar das **Urteil** (`truncated` dort, `complete` und
`capped` hier), und die Liste wird gegen die Operation gehalten: Jedes genannte
Feld muss auch gesendet werden, sonst prüfte ein Tippfehler darin nichts mehr.

---

## §13 Die Bilderrunde und die Knopfprobe

Gefahren am 14. September 2026 im Container gegen die **echte Seite** mit echten
Dateien: Agent auf einem eigenen Socket, `artisan serve`, Playwright mit dem
vorinstallierten Chromium, gemessen mit `tests/bilder-messen.js`.

### Der Prüfstand

Ein Abonnement `p9001`, zwei Domains, drei Dateien an den Pfaden, die `Site`
kennt — durch die **echte Operation** gemessen und nicht durch den Leser allein:

| Quelle | Zeilen | B/Zeile | geliefert | stellt her |
|---|---|---|---|---|
| `kurz` / Zugriffe | 36 | 139 | 36 | `complete` |
| `lang` / Zugriffe | 2000 | 140 | 100 | weder noch |
| `lang` / Fehler | 300 | **10 348** | **50** | `capped` |

Die dritte ist der Prüfkörper aus §1 (M2) auf dem echten Weg: ein
nginx-`error.log` mit Stacktraces, also die Form, die den Deckel im Betrieb
wirklich auslöst.

**Die Erwartung stand vor der Messung fest** — die drei Sätze aus §5 — und alle
drei sind Wort für Wort so gekommen.

> **Wo eine Erwartung vor der Messung feststeht, ersetzt das Nebeneinanderlegen
> das Beurteilen.**

### Die zwölf Lagen

Drei Zustände × zwei Themen × zwei Breiten, jede in einer frisch geladenen
Seite:

- `dokument = 0` — in allen zwölf
- Gegenprobe **200/200** — in allen zwölf
- `schiebt = 0` — in allen zwölf
- `geladen = flex` — der Ladebeleg: Unter 720 px wäre `.footer-row` ohne
  Stylesheet ein Block. Er gehört in die Messung und nicht in die Erinnerung.

Der Rollbehälter rollt wie gewollt (21 px bei der kurzen Datei bis 80 666 px
beim `error.log` auf 390 px) — das ist die Entscheidung aus `docs/24`, keine
Zahl, die sich beschwert.

### Die Knopfprobe — was zwölf Lagen nicht belegen

Zwölf Aufnahmen zeigen, dass der Knopf **dasteht**. Ob er etwas **bewirkt**, ist
eine andere Frage, und sie ist an der ersten Zeile gemessen und nicht an der
Zahl:

| | Zeilen | erste | letzte |
|---|---|---|---|
| `lines=100` | 100 | `203.0.113.151` | `203.0.113.0` |
| Knopf gedrückt | 200 | **`203.0.113.51`** | `203.0.113.0` |

Die erste Zeile wandert zurück, die letzte bleibt stehen. Eine Anfrage, die
bloss mehr Zeilen **anhinge**, sähe an der Zahl genauso aus.

> **Zwei Enden, von denen eines wandert und eines steht, sagen mehr als die
> Zahl dazwischen.**

**Und der Deckel ist durch den ganzen Weg belegt, nicht nur am Leser.** M9 ist
im Browser wiederholt, indem `lines=500` von Hand in die Adresse getippt wurde —
also genau das, was der Knopf täte, wenn er dastünde:

| | Zeilen | erste | letzte |
|---|---|---|---|
| Deckel, `lines=100` | 50 | `2026/09/14 09:12:11` | `09:12:00` |
| Deckel, `lines=500` | **50** | **dieselbe** | **dieselbe** |

Damit ist die Abwesenheit des Knopfes keine Entwurfsmeinung, sondern eine
Messung — durch Agent, Controller und Seite.

> **Eine Abwesenheit ist begründet, wenn das Vorhandene gemessen nichts
> geändert hätte.**

### Was der Container nicht beantwortet

Die **sieben** Punkte aus §9 auf einem echten Server — ausgeschrieben als
neun in `docs/920`, weil beim Ausschreiben zwei dazugekommen sind. Die Zustände sind hier mit
selbstgeschriebenen Dateien hergestellt; welche `error.log` auf `cloudsrv24`
über der Schwelle liegt, sagt erst ein Blick dorthin (§11). Punkt 5 — dass der
**Kunde** dieselbe Fusszeile sieht — ist hier gar nicht gemessen: Gefahren wurde
als Betreiber.

---

## §14 Eine Beobachtung neben der Sache

Der volle Lauf meldet **vier „risky" Fälle** — `Tests: 3373, Skipped: 1,
Risky: 4`, Rückgabewert 0. Keiner davon gehört zu diesem Vorhaben; kein Commit
dieses Zweiges fasst ihre Dateien an. Nachgesehen sind es **zwei** Arten:

- `ComponentReachTest::test_every_exemption_carries_a_reason` und
  `BrowserDialogTest::…` gehen über eine **leere** Ausnahmeliste. Das ist hier
  der gewollte Zustand — es gibt keine Ausnahme —, und dann hat der Fall nichts
  zu behaupten.
- `PublicKeyTest::test_a_broken_key_never_yields_a_fingerprint` und
  `SshdConfigTest::test_a_newline_in_a_name_never_becomes_a_second_block` messen
  sehr wohl; sie tun es über einen Helfer (`assertRefused`), dessen
  Behauptungen PHPUnit nicht als solche zählt.

Steht hier, damit der Nächste es nicht noch einmal untersucht.

> **Eine Zeile, die eine Abwesenheit behauptet, lässt den Nächsten dasselbe noch
> einmal bauen.**

---

## §15 Die Nummernspalte wird nicht gebaut — entschieden am 14. September 2026

**Der Betreiber hat entschieden: Auf der Domainseite erschliesst sich ihr Sinn
nicht.** Das ist der Schluss, und er ist eine Entscheidung und keine Vertagung.

> **Ein Punkt, der als Frage offen steht, und einer, der als Entscheidung
> geschlossen ist, sehen im Bestand gleich aus — der Unterschied steht nur
> daneben.**

Gemessen wurde davor trotzdem, und die Messungen bleiben hier stehen: Sie sind
über die Frage hinaus gültig, und wer sie später noch einmal stellt, fängt bei
ihnen an und nicht bei null.

### N1 — `complete` heisst nicht, dass die erste Zeile die erste ist

Das ist der Fund dieser Runde und gilt für **jede** künftige Zeilennummer,
gleich auf welcher Seite. Passt eine Datei in einen Block von 8192 Bytes, liest
der Agent sie ganz — `complete` ist wahr — und gibt trotzdem nur die letzten
`lines` Zeilen heraus:

| Datei | Bytes | `complete` | geliefert | erste Zeile ist in Wahrheit |
|---|---|---|---|---|
| 300 Zeilen à 20 B | 6 000 | **ja** | 100 | **201** |
| 300 Zeilen à 27 B | 8 100 | **ja** | 100 | **201** |
| 300 Zeilen à 40 B | 12 000 | nein | 100 | — |

Eine Seite, die bei `complete` einfach `i + 1` schriebe, druckte hier `1`, wo
`201` steht — und sie sähe dabei völlig richtig aus. Der Kopf von
`WebLogsTail::tail()` beschreibt diesen Ausstieg seit `docs/914`; **dass er die
Zeilennummer verschiebt, stand nirgends.**

> **Eine Auskunft, die stimmt, trägt eine zweite nicht mit — `complete` sagt,
> dass alles gelesen wurde, und nicht, dass alles gezeigt wird.**

Daraus folgt der Preis, den die Spalte an der Naht gehabt hätte: Die echte
Zeilennummer braucht `read`, also genau das Feld, das §3 mit Begründung
ausschliesst. Die Begründung dort bleibt richtig — als **Anzeige** ist `read`
hier ein Blick in die Maschine —, sie trägt diese Frage nur nicht mit.

> **Ein Feld, das als Anzeige nichts sagt, kann als Grundlage einer anderen
> Anzeige unentbehrlich sein.**

### N2 — die Pfeilnummer hätte nichts gekostet

Ohne Filter sind die Lagen lückenlos, und `↑n` ist schlicht `gezeigt − i`:
gemessen an 2000 Zeilen, `i = 0 → ↑100`, `i = 99 → ↑1`. Kein Feld, keine Zeile
im Agenten.

**Eine Spalte mit ausschliesslich Pfeilnummern wäre trotzdem die schlechtere
Hälfte gewesen.** Der häufige Fall bei einer Kundendomain ist die kleine Datei
— also gerade der, in dem die echte Zeilennummer verfügbar und nützlich ist. Sie
hätte `↑36` gezeigt, wo `36` die Wahrheit ist.

### N3 — was sie auf dem Telefon gekostet hätte

Gemessen an der echten Regel mit dem gebauten Stylesheet, mit Ladebeleg
(`display: flex` an `.log-line`):

| Breite | Rinnstein | Anteil der Sichtbreite | sichtbare Zeichen der Protokollzeile |
|---|---|---|---|
| 390 px | 59,3 px | **16,7 %** | **31** statt 37 |
| 1440 px | 61,3 px | 4,4 % | 140 statt 147 |

Die Spalte ist auf beiden Breiten gleich breit; ihr Preis ist ganz und gar ein
Telefonproblem.

### N4 — und was sie im Code gekostet hätte

Die Gestaltung liegt als `<style scoped>` in `Logs/Index.vue`: **115 Zeilen** mit
ihren Begründungen über sechs Regeln, dazu acht Zeilen Markup. Alle fünf Klassen
(`log-body`, `log-line`, `log-number`, `log-text`, `log-note`) kommen in genau
**einer** `.vue` vor.

Sie zu kopieren wäre die zweite Fassung derselben Regel gewesen. Der ehrliche
Weg wäre ein Herauslösen nach `app.css` gewesen — die Form eines Bausteins
gehört dorthin —, und das hätte `/logs` angefasst, das am selben Tag abgenommen
worden ist.

**Dieses Herauslösen bleibt als Frage bestehen und hängt nicht an der Spalte:**
Eine Protokollansicht, die in einer Seite wohnt, ist heute nur deshalb kein
Widerspruch zu `CLAUDE.md`, weil es sie genau einmal gibt. Käme je eine dritte
Protokollseite, fiele die Frage von selbst an. Sie steht hier und wird nicht
gebaut.
