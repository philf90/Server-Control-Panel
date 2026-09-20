# 126 · Die drei Issues auf `/backups/<id>/restore` — nachgesehen

**20. September 2026.** Gemessen im Container gegen Chromium 141.0.7390.37,
über den Kanal, aus dem die Registerkarte „Issues" der Entwicklerwerkzeuge ihre
Einträge bezieht: `Audits.issueAdded` über CDP. Ein Blick auf die Registerkarte
und dieser Lauf lesen dieselbe Quelle.

---

## 0 · Warum es dieses Dokument gibt

`docs/119 §8` hat am 17. September beim Bildlauf zu P8 notiert:

> **Nicht nachgesehen:** Auf `/backups/2/restore` meldet Chromium `3 issues`;
> auf den vier anderen Seiten `No issues`. Die Seite ist die einzige mit einem
> Formular. Was dort steht, ist offen.

Die Zeile stand danach wörtlich in `docs/121 §12`, `docs/123 §12` und
`docs/125 §9` — **vier Dokumente, vier Wochen, kein Blick.** Sie ist jetzt
gemessen und geschlossen.

---

## 1 · Was dort steht

Drei Einträge, einer je Bedienelement des Formulars (Kunde, Plan, Name):

| | |
|---|---|
| Code | `GenericIssue` |
| `errorType` | `FormEmptyIdAndNameAttributesForInputError` |
| Wortlaut | *„A form field element should have an id or name attribute"* |
| Ladebeleg | 3 Formularelemente im DOM |

Das ist Chromes **Ausfüllhilfe** und keine Aussage über die Zugänglichkeit. Die
drei Felder tragen weder `id` noch `name`; ihre Werte reisen über Inertia als
JSON und nicht über ein natives `POST`, ein Name trüge dort nichts.

---

## 2 · Die Gegenproben

Sechs Lagen, jede mit dem Ladebeleg daneben:

| Lage | Issues | was |
|---|---|---|
| **A** wie der Prüfling | **3** | `FormEmptyIdAndNameAttributesForInputError` |
| **B** `id` **und** `name` an allen dreien | 1 | `FormInputAssignedAutocompleteValueToIdOrNameAttributeError` / `id` |
| **C** nur `name` | 1 | dasselbe / `name` |
| **D** nur `id` | 1 | dasselbe / `id` |
| **E** dieselben drei **ohne** `<form>` darum | **3** | wie A |
| **F** ein einzelnes Feld im `<form>` | 1 | wie A |

**Drei Dinge entscheidet diese Tabelle.**

**Der naheliegende Handgriff tauscht einen Befund gegen einen anderen.** Wer den
drei Feldern `id` und `name` gibt, bekommt eine neue Meldung — und zwar am Feld
**Name**: `name` ist selbst ein Merkmal der Ausfüllhilfe, und ein Feld, das so
heisst und keine `autocomplete`-Angabe trägt, wird dafür gemeldet. Gemessen
verschwindet sie mit `name="subscription_name"` (0), mit
`autocomplete="off"` (0) und mit `autocomplete="organization"` (0).

> **Ein Handgriff, der einen Zähler auf null bringt, hat den Zähler bedient und
> nicht den Gegenstand.**

**Das `<form>` ist nicht die Ursache.** Lage E gibt dieselben drei Einträge ohne
jedes `<form>`. Der Satz aus `docs/119 §8` — „die Seite ist die einzige mit
einem Formular" — trägt also nicht als Erklärung; richtig ist, dass sie die
einzige der fünf ist, die überhaupt **Bedienelemente** hat.

**Und `autocomplete="off"` allein schweigt nicht.** Ein Feld ohne `id` und ohne
`name`, nur mit `autocomplete="off"`, gibt weiterhin **1**.

### Nach Art des Elements

Je ein Element im `<form>`, weder `id` noch `name`:

| Art | Issues |
|---|---|
| `text`, `email`, `password`, `date`, `number`, `url`, `search`, `checkbox`, `radio`, ohne `type` | je **1** |
| `select`, `textarea` | je **1** |
| `time`, `file` | **0** |
| `hidden` | 1, aber ein **anderer**: `FormLabelHasNeitherForNorNestedInput` |

**Die letzte Zeile ist der Beleg für die Zugänglichkeit**, und sie ist der
Grund, dass diese Messung etwas sagt: Chromium prüft die Beschriftung mit — bei
einem `<label>` um ein `type="hidden"` schlägt sie an, bei jeder geklammerten
Form der übrigen Lagen schweigt sie. Sie schweigt also nicht, weil die Prüfung
fehlt.

> **Eine Abwesenheit belegt eine Grenze erst, wenn daneben etwas anwesend ist,
> das dieselbe Prüfung auslöst.**

### Geparst gegen nachträglich eingefügt

Dieses Panel baut sein DOM nicht beim Parsen, sondern nach dem Laden durch Vue.
Gemessen, weil eine Prüfung, die nur beim Parsen läuft, hier nie anschlüge:

| | geparst | eingefügt |
|---|---|---|
| Restore-Formular | 3 | 3 |
| `/settings/backups` (zwei Kästchen) | 2 | 2 |
| ein Textfeld | 1 | 1 |

---

## 3 · Die Frage war schon einmal gestellt und beantwortet

**`docs/76`, 23. August 2026**, im Nachlauf zu `v0.7.0-rc.7`. Dort standen auf
`/domains` **fünfzehn** Einträge, alle derselbe Satz, und die Entscheidung
lautete: **kein Fund.** Mit zwei Gründen, beide nachgeprüft — die Ausfüllhilfe
statt der Zugänglichkeit, und die Werte reisen als JSON.

| | `docs/76`, 23. August | heute |
|---|---|---|
| Felder ohne `id` und ohne `name` | 124 von 138 | **155 von 169** |
| Urteil | kein Fund | unverändert |

Das Panel ist um 31 Felder gewachsen, alle in derselben Form, und die
Entscheidung von damals trägt. **Der Fehler ist nicht das Urteil, sondern dass
es niemanden erreicht hat:** Es stand im Protokoll des Laufs, der die Frage
gestellt hat, und vier Wochen später hat dieselbe Frage vier Dokumente lang neu
offengestanden.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde** — und eine **Antwort**
> ist darin nichts anderes als eine Behebung.

### Eine Zeile aus `docs/76` ist grösser als ihr Wächter

Dort steht, die Felder seien über die Klammer beschriftet, „und genau darauf
besteht `FormLabelTest`". Der Wächter hält das für `<select>` und für sonst
nichts — sein eigener Kopf sagt auch, warum:

> `input` steht bewusst **nicht** dabei: Ein Suchfeld trägt seinen Zweck im
> `placeholder`, und ein Kästchen hat seinen Text daneben statt darüber.

Die Einschränkung ist also eine Entscheidung und kein Versehen. Der **Satz** in
`docs/76` ist trotzdem weiter als der Wächter, und die Begründung hing an ihm.
Nachgemessen über alle Bedienelemente (ohne `type="hidden"`):

| | |
|---|---|
| Bedienelemente im Vorlagenblock | **166** |
| davon in einem `<label>` | **150** |
| über ein `<label for>` beschriftet | 10 (Cron 8, `CodeField`, `Plans/Form`) |
| über ein `aria-label` (Zeilenkästchen in Tabellen) | 4 |
| ohne Beschriftung | **2** — siehe §6 |

Die Begründung trägt damit, und jetzt gemessen statt zugeschrieben.

> **Ein Wächter, den ein Protokoll als Begründung nennt, trägt die Begründung
> nur so weit, wie er reicht.**

---

## 4 · Was daraus gebaut ist

**Ein Wächter, und keine Änderung am Markup.** Die 155 Felder bleiben, wie sie
sind: Ein Name trüge nichts, die Beschriftung steht, und der eine Handgriff
dagegen erzeugt eine neue Meldung (§2).

Gebaut ist die eine Regel dieser Gegend, die **stimmt und die nichts hält**.
`docs/76` nennt sie als tragenden Grund:

> Die eine Stelle, an der ein Browser ihn wirklich braucht, hat ihn.

Gemessen: **dreizehn von dreizehn** Feldern, die ein Passwort aufnehmen können,
führen ein `autocomplete` — zehnmal `new-password`, dreimal `current-password`.
Dreizehnmal von Hand hingeschrieben, und nichts hielt es.

> **Ein Zustand, der stimmt und den nichts hält, ist von einem, der nicht
> stimmt, nur durch Glück getrennt.**

`PasswordAutocompleteTest` hält es seit dem 20. September. Acht dieser Felder
nehmen **fremde** Geheimnisse auf — DNS-Token, API-Schlüssel, das Kennwort des
Mailversands —, fünf gehören dem eigenen Konto. Rät der Passwortspeicher
falsch, bietet er das Panelpasswort für einen DNS-Token an oder merkt sich einen
API-Schlüssel als Anmeldung; beides fällt niemandem auf. `off` gilt dabei nicht
als Angabe: Für Passwortfelder übergehen die Browser es.

**Drei Eingriffe, alle belegt** (`tests/waechter-brechen.sh`, vor der Bilanz
angehängt): das `autocomplete` an einem Feld entfernt, es durch `off` ersetzt,
und der Anführungszustand des Lesers ausgeschaltet.

### Der dritte Eingriff ist der eigentliche

**Die erste Messung dieses Tages hat einen Befund erfunden.** Sie las die Marke
mit `<input[^>]*>` und meldete das Wiederholungsfeld aus `PasswordFields.vue`
als Feld ohne `autocomplete`. Es trägt eines — davor steht nur
`:aria-invalid="… || (props.confirmation.length > 0 && !matches)"`, und der
Ausdruck hört am `>` im Attributwert auf.

> **Ein Ausdruck, der ein Tag bis zum nächsten `>` liest, liest ein halbes Tag,
> sobald eines im Attribut steht.**

Der Satz steht seit dem 5. September im Kopf von `NoticeChildrenTest`, dort an
einer Meldung — ausgezählt die **einzige** Stelle im Repo, die ihn hält. Die
Vermeidung war keine Regel geworden, und diesmal traf es nicht den Code, sondern
eine Messung.

Gemessen, wie weit es reicht: **79 von 3944** Marken in `resources/js` tragen
ein `>` in einem Attributwert (29 × `<p>`, 8 × `<template>`, 6 × `<tr>`, 5 ×
`<Section>`, 5 × `<form>`, dazu 26 einzelne). Mit dem naiven Leser meldet der
neue Wächter genau **eine** Stelle — das Feld, für das es ihn gibt. Ein falsches
Rot, und die Behebung dagegen hätte ein zweites `autocomplete` an eine Marke
geschrieben, die schon eines trägt.

**Die Richtung entscheidet, ob es auffällt.** Ein abgeschnittenes Tag lässt einen
Ausdruck **weniger** treffen; ob daraus ein falsches Rot oder ein falsches Grün
wird, hängt daran, ob der Befund am Treffer hängt oder an seinem Fehlen.

---

## 5 · Die Abweichung — und was der Versuch, sie zu messen, gefunden hat

`docs/119 §8` hat am 17. September auf `/settings/backups` **`No issues`**
gelesen. Die Seite trägt zwei `<input type="checkbox">` ohne `id` und ohne
`name`, und der Nachbau sagt dafür **2** — geparst wie eingefügt.

**Die dritte Messung ist am 20. September versucht worden und hat etwas anderes
gefunden als erwartet.** Abgelesen im Browser des Betreibers, beide Seiten mit
offenen Entwicklerwerkzeugen:

| Seite | 23. August | 17. September | 20. September |
|---|---|---|---|
| `/domains` | **15** (`docs/76`) | — | **0** |
| `/backups/2/restore` | — | **3** (`docs/119 §8`) | — |
| `/settings/backups` | — | **0** | **0** |

Die Null auf `/domains` ist der Punkt. Dieselbe Seite, derselbe Browser, dieselbe
Meldung — am 23. August fünfzehnmal, heute keinmal. Der Bestand der Seite hat
sich daran nicht geändert; die Felder tragen weiterhin weder `id` noch `name`.

> **Eine Null, die auch von einem nicht hinsehenden Werkzeug kommt, ist keine
> Messung.**

Damit ist `/settings/backups` als Prüfkörper untauglich: Seine Null ist von
einer Null, die das Werkzeug erzeugt, nicht zu unterscheiden. Die Abweichung
lässt sich an der Panelseite grundsätzlich nicht entscheiden.

### Der Prüfkörper, der anschlagen muss

Gebaut und gemessen als **`tests/issues-pruefblatt.html`** — zwei Eingabefelder
auf einer Seite, **A** ohne `id` und ohne `name`, **B** mit beidem. Gemessen im
Container gegen Chromium 141.0.7390.37:

```
Ladebeleg   Felder im DOM: 2   (erwartet 2)
Issues      1
  · GenericIssue / FormEmptyIdAndNameAttributesForInputError
```

**Beide Richtungen auf einer einzigen Seite**: Schlüge der Eintrag auch bei B an,
misse er etwas anderes; schlägt er bei keinem an, sieht das Werkzeug diese Art
nicht. Eine Ablesung von 1 macht jede Null auf einer Panelseite zu einer Aussage
über die Seite; eine Ablesung von 0 macht sie zu einem Schweigen.

Derselbe Prüfkörper über die Konsole, auf `about:blank`, in drei Schritten
gemessen — leer **0**, Feld ohne `id`/`name` **1**, Feld mit beidem **+0**. Der
Lieferweg ist dabei gleichgültig: beim Parsen vorhanden **1**, nach dem Laden
eingefügt **1**. Das ist der Grund, dass es überhaupt einen Weg über die Konsole
gibt — ein `data:`-URL sperrt Chrome seit Fassung 60 in der Adressleiste.

### Warum eine Fassungsdifferenz plausibel ist — gemessen und nicht vermutet

Die Registerkarte holt diese Einträge nicht allein aus `Audits.issueAdded`. Es
gibt dafür den Befehl **`Audits.checkFormsIssues`**, und der verhält sich anders,
als sein Name sagt:

> **Ein Befehl, der eine Liste zurückgeben müsste, gibt eine leere zurück und
> stösst seine Antwort stattdessen als Ereignis nach.** Gemessen: `formIssues`
> kommt mit **0** Einträgen zurück, und der Zähler der Ereignisse steigt im
> selben Zug von 1 auf 2.

Wie oft und wann die Oberfläche ihn ruft, ist damit eine Eigenschaft der
Browserfassung und nicht der Seite — und genau das ändert sich zwischen zwei
Chrome-Fassungen, ohne dass am Prüfling eine Zeile anders wäre.

### Was offen bleibt

Die Ablesung des Prüfblatts im Browser des Betreibers, samt der Fassung aus
`chrome://version`. Gibt es **1**, ist die Abweichung ein Befund und wird
nachgemessen; gibt es **0**, ist sie eine Grenze des Messmittels und bleibt als
solche stehen.

> **Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist nicht
> „nicht herstellbar" — und ihn so zu nennen wäre die bequemere von zwei
> falschen Auskünften.**

Für das Urteil dieses Dokuments ändert die Antwort nichts: Die drei Einträge auf
der Restore-Seite sind unabhängig davon gemessen, `docs/76` hat dieselbe Meldung
schon auf einer ganz anderen Seite gesehen, und `docs/119 §8` hat sie am
17. September in genau diesem Browser gezählt.

---

## 6 · Nebenbefunde — benannt und nicht gebaut

**Zwei Bedienelemente haben keine Beschriftung**, gefunden beim Nachmessen der
Zeile aus `docs/76` (§3). Keines ist ein Befund dieses Laufs, und beide sind
Entwurfsfragen und keine mechanischen Behebungen:

- **`Subscriptions/Edit.vue`, das Zahlenfeld eines übersteuerten Kontingents.**
  Es trägt `:id="`quota-${entry.key}`"`, und **nichts zeigt darauf** — der `id`
  ist aus `Plans/Form.vue` mitgewandert, wo ein `<label class="label" :for=…>`
  danebensteht. Hier steht der Name des Kontingents im `<label class="toggle">`
  des Kästchens, das die Übersteuerung *einschaltet*; sichtbar ist er also, und
  das Feld darunter hat keinen eigenen. Wie die Beschriftung lauten soll, ist
  eine Frage an den Entwurf.

- **Die Rückfalldarstellung von `CodeEditor.vue`** — das `<textarea
  v-show="!ready">`, das steht, bis CodeMirror geladen ist. Weder `<label>` noch
  `aria-label`.

---

## 7 · Der volle Bruchlauf — und was er an den drei Eingriffen fand

**2632 Prüfungen, eine ohne Biss**, und sie war meine. Einzeln hatten alle drei
gebissen; im Lauf nicht.

**Der Eingriff zeigte auf den falschen Fall.** `test_off_is_not_an_intention`
misst seinen **eigenen** Prüfkörper und liest den Baum gar nicht — ein
`autocomplete="off"` in `DnsCredentials.vue` kann ihn nicht rot machen. Rot
wird davon die Regel über den Baum,
`test_every_password_field_names_its_purpose`. Beim Einzelbeleg hatte ich über
die **Klasse** gefiltert und `Failures: 1` gelesen.

> **Ein Lauf über die ganze Klasse sagt, dass ein Fall rot war — nicht, welcher.**

Jeder der drei Eingriffe ist seitdem gegen **seinen** Fall belegt, ein Lauf je
Eingriff.

**Und der Lauf hat einen zweiten Fehler gefunden, der älter ist.** Neunmal stand
im Protokoll `tests/waechter-brechen.sh: line …: abschnitt: command not found`:
`abschnitt "…"` ist am 18. September als Kurzform für eine Abschnittsüberschrift
entstanden, **ohne dass es die Funktion gibt** — sechs Stellen von damals, drei
von heute. bash meldet, läuft weiter, und die Bilanz zählt ihre Prüfungen wie
sonst.

> **Ein Skript, das eine Zeile nicht ausführen kann, läuft weiter — und die
> Meldung darüber steht neben der Bilanz und nicht darin.**

**Kein Mittel konnte es sehen.** `bash -n` prüft die Form, shellcheck kann einen
Namen nicht nachschlagen, und
`BreakScriptTest::test_no_heading_swallows_the_intervention_below_it` liest
ausschliesslich Zeilen, die mit `echo "` beginnen — die neun waren für ihn gar
nicht da. Seine Untergrenze von 50 war durch die 1100 richtig geschriebenen
längst erfüllt.

> **Eine Untergrenze, die die Mehrheit erfüllt, sieht eine zweite Schreibweise
> nicht — sie zählt ja weiter genug.**

Behoben ist es, indem die zweite Schreibweise **verschwindet**: Die neun Zeilen
tragen jetzt dieselbe Form wie die 1109 anderen. Und
`BreakScriptTest::test_every_helper_the_script_calls_is_defined` hält es —
gebrochen von Hand, weil sein Bruch in der einen Datei stünde, die der Rückweg
zu Recht auslässt.

**Und eine zehnte Meldung war keine Kurzform, sondern ein Auftrag.** Eine
Überschrift erklärte ihren Gegenstand in Markdown:

```
echo "── LogSourceTest: `-- No entries --` kommt als Zeile durch ──"
```

In doppelten Anführungszeichen sind Backticks keine Auszeichnung, sondern
**Befehlsersetzung**: bash hat `-- No entries --` ausgeführt, `command not
found` gemeldet und das leere Ergebnis eingesetzt. Gedruckt stand da
*„── LogSourceTest:  kommt als Zeile durch ──"* — der Gegenstand war fort.

> **Ein Zitat in doppelten Anführungszeichen ist in einer Shell kein Zitat,
> sondern ein Auftrag.**

Die gedruckte Hälfte ist die harmlose. Hier stand zwischen den Backticks kein
Befehl; beim nächsten Mal steht dort einer, weil jemand einen Befund zitiert.
`test_no_line_runs_a_command_it_only_means_to_print` hält es seitdem, ebenfalls
von Hand gebrochen.

**Der zweite Lauf ist durch:** 2632 Prüfungen, **null ohne Biss**, „Alle Wächter
beissen." Die neun Überschriften stehen wieder da, und von zehn Meldungen
`command not found` ist keine übrig.

---

## 8 · Bilanz

| | |
|---|---|
| Punkt 1 aus vier Dokumenten | **geschlossen** |
| Befund am Prüfling | **keiner** |
| Befund am Prüfmittel | **vier** — der erste Leser dieses Tages (§4), der Eingriff auf dem falschen Fall und die undefinierte Kurzform (§7), und die Registerkarte des Betreibers selbst (§5) |
| gebaut | `PasswordAutocompleteTest` mit drei Eingriffen, `tests/issues-pruefblatt.html` |
| gemessen und offen | die Ablesung des Prüfblatts im Browser des Betreibers (§5) |
| benannt und nicht gebaut | zwei unbeschriftete Bedienelemente (§6) |

**Der teuerste Satz dieses Tages steht in §3** und hat mit Formularen nichts zu
tun: Eine Antwort, die nur im Protokoll ihres eigenen Laufs steht, ist von einer,
die es nie gab, vier Wochen später nicht zu unterscheiden. Deshalb steht sie
jetzt in `CLAUDE.md` und nicht nur hier.

**Und der zweitteuerste steht in §5 und ist derselbe Satz an einem Werkzeug:**
Zwei Ablesungen an derselben Stelle haben die Abweichung entschieden, und keine
Überlegung hätte es getan. Das Prüfblatt geht deshalb ins Repo und nicht in den
Scratchpad — nach demselben Grund, aus dem `tests/bilder-messen.js` dort liegt.

> **Ein Messmittel, das man aufhebt, macht die Fehler von letztem Mal nicht noch
> einmal.**
