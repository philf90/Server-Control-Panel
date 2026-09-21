# B8 — Ein Vorgang ohne Weiterleitung

Geschrieben am **23. September 2026**, nach dem Blick an den Quelltext und nach
den vier Entscheidungen des Betreibers. Der Befund steht seit dem 31. August als
`docs/92`; dieser Plan beantwortet dessen §4.

---

## §0 · Was der Blick an den Quelltext berichtigt hat

**Drei Zahlen aus `docs/92` stimmen nicht mehr**, und eine davon ändert den
Umfang.

1. **Es sind 22 Weiterleitungen aus acht Controllern**, nicht 21 aus sieben.
   `BackupController` ist mit P8 dazugekommen. Die Zahl im Dokument war am
   31. August richtig.

   > **Eine Zahl im Kommentar altert mit dem Code, den sie zählt, und nichts
   > meldet es.**

2. **Die Bänderhülle gibt es schon.** `docs/92 §3` beschreibt einen Streifen
   oben auf der Seite, als wäre er neu. A14 hat `.bands` in `PanelLayout`
   gebaut, drei Bänder hängen darin, und der M2-Befund — drei Bänder liegen bei
   1440 px aufeinander, weil `.band` `grid-row: 1` nimmt — ist dort behoben.
   B8 fügt ein viertes hinzu und keine Form.

3. **Die Form „im Takt nachfragen" gibt es auch schon.**
   `Subscriptions/Backups.vue` trägt seit P8 `NACHFRAGE_MS = 3000`,
   `router.reload({ only: […] })`, den Takt an den Zustand gehängt und das
   Abräumen in `onUnmounted`, das `TeardownTest` hält. B8 benutzt dieselbe Form
   an einer anderen Stelle.

   > **Wer entscheidet, was als Nächstes gebaut wird, sieht vorher am Quelltext
   > nach, ob es das schon gibt.**

---

## §1 · Die vier Entscheidungen des Betreibers

| Frage aus `docs/92 §4` | |
|---|---|
| **1 · Der Log-Strom** | **Nachfragen im Takt**, und nur solange ein Vorgang läuft |
| **2 · Seitenwechsel** | Der Streifen **überlebt ihn** — aus einer geteilten Eigenschaft |
| **3 · Nachladen** | Der Streifen **bietet es an**, die Seite tut es nicht von selbst |
| **4 · Umfang** | **Alle 22** Weiterleitungen, eine Regel und kein Schwellenwert |

**Die erste ist halb gemessen und nicht entschieden worden.** `docs/128` M9:
Der Panel-Pool hat zwölf Arbeiter, und zwei belegte lassen die nächste Anfrage
**16 s** warten. Ein SSE-Strom auf jeder Seite hiesse, aus einer von 58 Seiten
alle 58 zu machen.

> **Zwölf offene Ströme machen das Panel für alle unerreichbar.**

**Und der Schwellenwert aus `docs/92 §6` fällt ausdrücklich weg.** Dort steht,
ein Vorgang von zwölf Sekunden brauche keine eigene Seite, und ob es eine
Grenze gebe, sei **nicht gemessen**. Eine ungemessene Grenze wären zwei
Verhaltensweisen für denselben Knopf, je nachdem, wie lange es heute dauert.

---

## §2 · Die Form

### Die geteilte Eigenschaft

`HandleInertiaRequests::share()` bekommt `runningOperations` — **als
Verschluss**, wie alle elf daneben, damit ein partielles Nachladen, das sie
nicht anfordert, sie auch nicht berechnet (`docs/103 §1` M5: voll zwei
Abfragen, partiell eine).

Enthalten ist, was **diesem Konto** gehört und

- `queued` oder `running` ist, **oder**
- innerhalb der letzten `FRESH_SECONDS` fertig geworden ist.

**Das zweite Stück ist der Grund, dass der Streifen etwas zu sagen hat.**
Verschwände ein Vorgang beim Fertigwerden einfach aus der Liste, wüsste der
Klient, dass er weg ist, und nicht, wie er ausgegangen ist. Nach der Frist
altert die Zeile von selbst aus — es braucht keine Ablage „gesehen" und keine
zweite Tabelle.

> **Ein Zustand, der von selbst vergeht, braucht kein Gedächtnis.**

### Was **nicht** drinsteht

**Vorgänge ohne Konto.** Die Zertifikatsautomatik und der Cron-Einsammler
setzen `account_id` auf `null` — dieselbe Null, die `docs/901` als „System"
liest. Sie erscheinen bei niemandem, und das ist die Antwort auf Frage 4 aus
`docs/92 §4`: Sie sind gemeint, und für sie ändert sich nichts.

> **Eine Null, die schon eine Bedeutung trägt, kann keine zweite bekommen.**

### Der Streifen

Ein viertes Band in der bestehenden `.bands`-Hülle:

    Vorgang 726 · Pakete einspielen — läuft · 40 %          ansehen
    Vorgang 726 · Pakete einspielen — fertig      Seite aktualisieren · ansehen

Der Rang kommt aus demselben Ausdruck wie auf der Vorgangsseite: `ok` bei
`succeeded`, `critical` bei `failed` und `cancelled`, sonst `info`. **Eine
zweite Zuordnung wäre die zweite Fassung**, und die veraltet.

### Der Takt

`NACHFRAGE_MS = 3000`, dieselbe Zahl und derselbe Grund wie bei den
Sicherungen. Er läuft **nur, solange mindestens ein Vorgang nicht fertig ist**,
hängt an diesem Zustand und wird in `onUnmounted` abgeräumt. Nachgeladen wird
`only: ['runningOperations']` — eine Eigenschaft und keine Seite.

### Die 22 Weiterleitungen

Jede wird zu der Seite, von der aus gedrückt wurde. Die drei in
`OperationController` bleiben: Wer auf der Vorgangsseite „noch einmal" drückt,
will dort bleiben.

---

## §3 · Die Wächter

| | hält |
|---|---|
| `OperationDetourTest` | **Kein Controller ausser `OperationController` leitet auf `operations.show`.** Gemessen am Router und am Quelltext, mit einer Untergrenze über die Zahl der absetzenden Stellen — sonst ist der Wächter grün, sobald sein Ausdruck ins Leere greift. |
| `StreamPageTest` | **Nur die Vorgangsseite öffnet eine `EventSource`.** Gemessen über `resources/js`; die Begründung ist eine Zahl: zwölf Arbeiter, 16 s Wartezeit bei zweien. |
| `RunningBandTest` | Die geteilte Eigenschaft ist ein Verschluss, trägt nur das eigene Konto, nimmt fertige Vorgänge für `FRESH_SECONDS` mit und lässt die des Systems weg — **jede Richtung einzeln**, denn ein Filter, der zu viel wegwirft, sieht aus wie einer, der richtig rechnet. |
| `TeardownTest` *(vorhanden)* | Der Takt wird abgeräumt. Er liest ganz `resources/js` und deckt das neue Band ohne Änderung. |

---

## §4 · Das Abnahmekriterium

`docs/129 §9` sagt: *„Ein Vorgang, der aus einer Liste heraus angestossen wird,
führt zurück in diese Liste — die vier Fragen aus `docs/92 §4`."* Das bleibt,
und **zwei Punkte kommen dazu**, weil der Lauf sonst den sichtbaren Fall misst
und den teuren nicht:

1. Ein Vorgang, aus einer Liste angestossen, lässt den Betrachter **dort** —
   und der Streifen nennt ihn.
2. **Der Streifen überlebt einen Seitenwechsel**, und der Takt läuft danach
   nicht doppelt (gemessen an der Zahl der Anfragen, nicht am Augenschein).
3. **Nach dem Ende fragt niemand mehr nach.** Ein Takt, der weiterläuft, ist
   von einem, der aufgehört hat, auf dem Bild nicht zu unterscheiden.

Punkt 1 und 3 dürfen nicht ausfallen.

---

## §5 · Was auf dem Server zu messen bleibt

- **Was die geteilte Eigenschaft je Anfrage kostet**, mit echten Beständen.
- **Ob der Takt den Pool belastet**: zwölf Arbeiter, ein Nachfragen alle drei
  Sekunden je offenem Reiter.
- **Die Laufzeiten von heute.** `docs/92 §6` nennt 12 s und 18 s vom
  31. August; wer den Schwellenwert später doch will, misst ihn an den
  Vorgängen, die es dann gibt.

---

## §6 · Was B8 ausdrücklich **nicht** wird

- **Keine Ablage „das hast du losgeschickt".** Der Streifen zeigt, was läuft
  und was gerade fertig wurde; eine Liste über Stunden ist ein eigenes Merkmal
  mit einer eigenen Aufbewahrungsfrage.
- **Kein automatisches Nachladen.** Wer gerade tippt, verliert sonst seinen
  Stand — Inertia stellt ihn nicht wieder her.
- **Kein Strom ausserhalb der Vorgangsseite.**
- **Keine Änderung an der Vorgangsseite selbst.** Sie bleibt, was sie ist; sie
  wird nur nicht mehr der Ort, an dem man unfreiwillig landet.
