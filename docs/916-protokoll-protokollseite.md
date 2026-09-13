# 916 — Protokoll zum Abnahmelauf der Protokollseite

> Der Plan ist `docs/914`, der Lauf **`docs/915`**. Gefahren am 13. September
> 2026 auf `cloudsrv24` gegen **`0.7.4-rc.8`**. Dieses Protokoll wächst mit dem
> Lauf; was offen ist, steht offen da.

## §1 Vorbedingung — halb gemessen, und das ist ein Befund an der Vorschrift

**Befund 1 — `| head -20` schneidet genau vor den Feldern ab, nach denen der
Punkt fragt.** Die Antwort des Agenten ist hübsch gedruckt, und zwanzig Zeilen
sind hier exakt: die Hülle, fünf Protokollzeilen, `offsets` mit fünf Zahlen.
`read`, `complete` und `capped` stehen dahinter.

> **Ein Griff, der genau vor dem Feld abschneidet, nach dem er fragt, misst die
> Hälfte — und die andere sieht aus, als wäre sie geprüft.**

**Was der Lauf trotzdem belegt hat:**

- `offsets` steht da: `[495, 496, 497, 498, 499]`. Bei `lines: 5` sind das die
  letzten fünf eines **500 Zeilen** grossen Fensters — die Lage wird also
  gesendet und nicht bei null neu gezählt.
- **`origin` ist fort.** Es stünde unmittelbar hinter `label`, und dort steht
  `exists`. Das Feld ohne Leser aus `docs/914 §12` ist damit auf dem Server
  belegt.

**Ungemessen blieben** `read`, `complete`, `capped` und die Abwesenheit von
`window`. `docs/915 §1` fragt seitdem mit einem Ausdruck nach genau diesen
Feldern statt mit einer Zeilenzahl.

## §2 Bestandsaufnahme — und sie hat einen Punkt umgestellt

| Datei | Zeilen | B/Zeile | Marke |
|---|---|---|---|
| `/var/lib/srvpanel/storage/logs/laravel.log` | 0 | 0 | kurz (leer) |
| `/var/log/srvpanel/update.log` | 36 | 43 | **kurz** |
| `/var/log/srvpanel/upgrade.log` | 0 | 0 | kurz (leer) |
| `/var/log/srvpanel/agent.log` | 500 | 189 | **lang** |
| `/var/log/srvpanel/panel-error.log` | 81 | 244 | kurz |
| `/var/log/srvpanel/panel-access.log` | 500 | 153 | lang |
| `/var/log/apt/history.log` | 251 | 52 | kurz |
| `/var/log/auth.log` | 500 | 130 | lang |

**Keine einzige Quelle trägt `DECKEL`.** Die dickste ist `panel-error.log` mit
**244 B/Zeile**, und die Schwelle liegt bei 1048. Punkt 3 hat auf dieser
Maschine keinen Gegenstand und wird über den Prüfkörper aus `docs/915 §5`
gefahren — umkehrbar, mit `cp -a` davor.

> **Ein Prüfkörper, der einen Zustand braucht, den es auf dieser Maschine
> vielleicht nicht gibt, wird gesucht und nicht vorausgesetzt.** Gesucht, nicht
> gefunden — und der Plan hatte den Fall vorgesehen.

**Die Journale, gezählt mit `wc -l`:**

| Unit | Zeilen | trägt |
|---|---|---|
| `srvpanel-web` | 503 | Punkt 5, Form „vom Ende" |
| `srvpanel-worker` | 501 | |
| `srvpanel-agentd` | 502 | |
| `srvpanel-cron` | 501 | |
| `srvpanel-metrics` | 502 | |
| **`srvpanel-tls`** | **359** | Punkt 5, Form „echte Zeilen" |
| `srvpanel-dns` | 501 | |
| `srvpanel-usage` | 501 | |

**`srvpanel-tls` ist die einzige Unit unter 500** und damit die einzige, an der
sich die zweite Form von Punkt 5 überhaupt zeigen lässt. Ohne die
Bestandsaufnahme wäre der Punkt an einer der sieben anderen gefahren worden und
hätte die Hälfte gemessen.

**Eine Zahl daran ist eine Frage und keine Messung.** `journalctl --lines=500`
liefert höchstens 500 Einträge, gezählt wurden 501 bis 503. Die Differenz sind
Zeilen, die `journalctl` selbst schreibt — Bootmarken oder eine Kopfzeile. Ob
der Leser des Agenten sie als Protokollzeilen durchreicht, sagt erst Punkt 5:
`readJournal()` wirft leere Zeilen und `-- No entries --` weg und sonst nichts.

> **Eine Zahl, die um zwei über ihrer Obergrenze liegt, zählt etwas mit, das
> niemand bestellt hat.**

## Zuordnung für die weiteren Punkte

| Punkt | Quelle |
|---|---|
| 1 — kurze Quelle, zwei Zustände | `update.log` (36 Zeilen) |
| 2 — lange Quelle | `agent.log` (500 Zeilen) |
| 3 — Bytedeckel *(Ausschluss)* | Prüfkörper nach `docs/915 §5` |
| 4 — Filter | `agent.log` |
| 5 — Journal *(Ausschluss)* | `journal-web` und `journal-tls` |
| 6 · 7 · 8 — Bilder, Kleben, Kopieren | `agent.log` |

---

## §1b Vorbedingung nachgeholt — erfüllt

```
"read": 500   "complete": false   "capped": false   "matched": 500   "truncated": true
```

Fünf Felder da, **kein `window`**, **kein `origin`**. Dass die fünf dastehen,
ist der Beleg, dass der Ausdruck greift — die Abwesenheit der beiden anderen
bedeutet damit etwas.

## §3 Punkt 3 — der Bytedeckel *(Ausschlusskriterium, erfüllt)*

Prüfkörper nach `docs/915 §5`: 600 Zeilen à rund 4 KiB an `laravel.log`
angehängt, die Datei war vorher leer.

| gemessen an | Ergebnis |
|---|---|
| Agent, mit Prüfkörper | `read: 130` · `complete: false` · **`capped: true`** · `matched: 130` · `truncated: false` |
| Seite | 130 nummerierte Zeilen, „130 Zeilen · gelesen wurden die letzten 130 Zeilen" |
| Notiz | „Die Nummern zählen vom Ende: −1 ist die letzte Zeile. **Weiter zurück wurde nicht gelesen — das Fenster ist auch in Bytes begrenzt.**" |
| Gegenprobe nach dem Rückbau | `read: 0` · **`capped: false`** |

**Die Containermessung war auf die Zeile genau.** Derselbe Prüfkörper ergab
dort ebenfalls **130** (`docs/914 §13`).

> **Ein Aufsatz, der das echte Markup und das gebaute Stylesheet benutzt, misst
> die echte Seite** — und hier auch die echte Zahl.

### Befund 2 — beim Bytedeckel ist die erste Zeile ein Bruchstück

Auf dem Bild trägt Zeile **−130** kein `[2026-09-13 …]`, sondern nur `xxxx…`.
Der Leser liest rückwärts in Blöcken; endet er am Bytedeckel, fängt sein Text
**mitten in einer Zeile** an, und `explode("\n", …)` macht daraus einen ersten
Eintrag, der keine Zeile ist.

**Der Leser kennt das Problem und schützt nur den einen Ausstieg.** Sein
Kommentar sagt die Absicht: *„Der erste Block endet in aller Regel mitten in
einer Zeile, und die gehört nicht angeschnitten zurückgegeben."* Der Schutz ist
`substr_count($text, "\n") > $count` — ein Umbruch mehr als gewünscht, damit
`array_slice($all, -$count)` das Bruchstück wegschneidet. Beim Bytedeckel
greift diese Bedingung nie, und dann bleibt es stehen.

> **Ein Schutz, der an einer von drei Abbruchbedingungen hängt, schützt die
> beiden anderen nicht — und welche greift, entscheidet der Inhalt der Datei.**

Kein Kriterium fragt danach; der Befund fiel aus dem **Bild** heraus, nicht aus
einer Zahl. Er ist **nicht während des Laufs behoben** — eine Behebung ist eine
Änderung am Prüfling.

### Eine Beobachtung, kein Befund

Die Fusszeile sagt „130 Zeilen · gelesen wurden die letzten 130 Zeilen" —
dieselbe Zahl zweimal, weil ohne Filter `matched` und `read` gleich sind. Sie
steht schon als Beobachtung in `docs/914 §13`; der Blick auf dem Server
bestätigt sie und entscheidet sie nicht.

---

## §4 Punkt 1 — die kurze Quelle, beide Zustände *(erfüllt)*

`panel-update`, 36 Zeilen.

| | Fusszeile | Knopf | erste Nummer | Notiz |
|---|---|---|---|---|
| `lines=500` | „36 Zeilen · gelesen wurden die letzten **36** Zeilen" | **keiner** | **1** | „Das ist die ganze Quelle; die Nummern sind ihre Zeilen." |
| `lines=10` | „10 Zeilen von 36 Treffern · … letzten **36** Zeilen" | „Mehr Zeilen (10 → 20)" | 27 | dieselbe |

**Das ist genau der Fall, den `docs/915 §0` auseinandergelegt hat.** Die
Fusszeile nennt 36 und nicht 500 — der Befund aus `docs/86` ist damit auf dem
Server behoben —, und der Knopf steht bei `lines=10` **zu Recht** da, weil
zehn von sechsunddreissig gezeigt werden. Bei `lines=500` verschwindet er.

## §5 Punkt 2 — die lange Quelle *(halb)*

`agent`, 500 Zeilen im Fenster: Nummern **−100 … −81**, Fusszeile „100 Zeilen
von 500 Treffern · gelesen wurden die letzten 500 Zeilen", Notiz „Die Nummern
zählen vom Ende: −1 ist die letzte Zeile.", Knopf „Mehr Zeilen (100 → 200)".

**Der Druck auf den Knopf steht aus** — die zweite Hälfte des Punktes (erste
Nummer danach **−200**) ist nicht gemessen.

## §6 Punkt 4 — mit Filter *(erfüllt)*

`agent` mit Filter, „100 Zeilen von **235** Treffern · gelesen wurden die
letzten 500 Zeilen". Die Nummern:

    −220  −215  −213  −211  −209  −207  −205  −203  −201  −199 …

**Lücken von fünf und von zwei.** Eine lückenlose Folge wäre der Befund
gewesen; sie hätte behauptet, die Treffer stünden in der Datei
nebeneinander.

## §7 Befund 3 — beim Rollen scheint der Text neben der Nummer durch

**Gefunden hat es das Bild zu Punkt 4**, auf dem die Seite waagerecht gerollt
war: Vor jeder Nummer stand `'ts` — der Anfang der Protokollzeile.

**Erst für ein Artefakt des Telefons gehalten** (Sticky-Elemente hinken beim
Schwungrollen nach) und deshalb im Container gegen echtes Chromium nachgemessen,
im **gesetzten** Zustand. Es reproduziert:

| Zustand | Nummer beginnt bei | `elementFromPoint` im Streifen davor |
|---|---|---|
| wie gebaut (`padding-left: 16px` am Rahmen) | x = **17** | **`log-text`** |
| Polster am Rahmen entfernt | x = **1** | **nichts** |

Die Ursache ist das Polster des Rollbehälters. Ein klebendes Element klebt am
**Inhaltsrand** und nicht am Rahmen; die sechzehn Pixel davor gehören dem
Rollbereich, und beim Rollen wandert der Text sichtbar hinein.

> **Ein Element, das klebt, deckt seinen eigenen Kasten — nicht den Streifen,
> den das Polster davor freilässt.**

**Die Klebeprobe aus `docs/915 §8` konnte das nicht sehen.** Sie fragt, ob die
Nummer **stehenbleibt** — gemessen `17 → 17`, und das stimmt. Sie fragt nicht,
ob links von ihr etwas durchscheint.

> **Eine Probe, die fragt, ob ein Element stehenbleibt, fragt nicht, ob daneben
> etwas durchscheint.**

**Und die Bilderrunde aus `docs/914 §13` konnte es erst recht nicht:** Sie hat
nach dem Rollen `scrollLeft` zurückgesetzt und **danach** fotografiert.

> **Ein Bild nach einer Messung zeigt den Zustand danach und nicht den
> gemessenen.** Der Satz steht seit `docs/906` im Repo und hat hier zum zweiten
> Mal zugeschlagen.

Behoben wird nach dem Lauf.

---

## §8 Punkt 5 — das Journal *(Ausschlusskriterium, erfüllt)*

Beide Formen, an den beiden Units, die §2 dafür ausgesucht hat:

| Quelle | Nummern | Fusszeile | Notiz |
|---|---|---|---|
| `journal-tls` (359) | **1 … n** aufsteigend | „359 Zeilen · gelesen wurden die letzten **359** Zeilen" | „Das ist die ganze Quelle" |
| `journal-web` (503) | **−100 … −1** | „100 Zeilen von **503** Treffern · … letzten **503** Zeilen" | „zählen vom Ende" |

**Das ist der Punkt, den der Container grundsätzlich nicht messen kann** — er
hat kein Journal. Ohne die Bestandsaufnahme aus §2 wäre er an einer der sieben
Units über 500 gefahren worden und hätte nur die eine Form gezeigt.

**Und die Frage aus §2 ist für diesen Fall beantwortet:** Die erste Zeile von
`journal-tls` ist ein echter Eintrag
(`2026-08-05T00:35:10+02:00 cloudsrv24 sys…`) und keine Kopfzeile von
`journalctl`. Der Leser zeigt also keine Meldung des Werkzeugs als Inhalt.

### Beobachtung — `read` ist beim Journal grösser als das erklärte Fenster

`journal-web` meldet **503**, und `SystemLogsTail::MAX_LINES` ist **500**.
`journalctl --lines=500` liefert fünfhundert **Einträge** plus seine eigenen
Trennzeilen — Bootmarken —, und `readJournal()` streicht nur leere Zeilen und
`-- No entries --`. Drei davon sind durchgekommen.

**Gelogen ist nichts:** Die Seite sagt „gelesen wurden die letzten 503 Zeilen",
und 503 ist, was der Agent bekommen hat. Und `complete` fällt zur sicheren
Seite, weil `503 < 500` falsch ist.

**Der Randfall, den es benannt gibt:** Eine Unit mit 498 Einträgen und drei
Bootmarken ergibt 501 und damit `complete = false` — die Seite sagt dann „zählen
vom Ende", obwohl es die ganze Quelle ist. Das ist die harmlose Richtung.

> **Eine Zahl, die über ihrer erklärten Obergrenze liegt, zählt etwas mit, das
> die Grenze nicht meint — und ob das schadet, entscheidet die Richtung, in die
> sie irrt.**

**Nachgemessen am selben Abend: es sind Bootmarken.** `grep -c '^--'` über
dieselbe Ausgabe gibt **3**, und die drei lauten:

    -- Boot 767bc82d8cca435e820efd7d86e727a0 --
    -- Boot 7ec24c5f0e8848279809b0a67468e0dd --
    -- Boot e19b19b9743e4a37a522b9705f1c441a --

Die letzte ist dieselbe Boot-ID, die im Journal von `srvpanel-diagnose` steht
(`docs/913 §17`). Aus der naheliegenden Erklärung ist damit eine Messung
geworden.

> **Eine naheliegende Erklärung wird nicht dadurch zur Messung, dass sie
> stimmt.**

**Was daraus als Frage bleibt:** Eine Bootmarke bekommt auf der Seite eine
Zeilennummer wie ein Eintrag. Das ist dieselbe Art von Sache wie das Bruchstück
aus Befund 2 — etwas, das keine Protokollzeile ist, wird als eine
durchgezählt. `readJournal()` streicht `-- No entries --` mit der Begründung,
eine Meldung des Werkzeugs gehöre nicht als Inhalt gezeigt; für die Bootmarke
gilt derselbe Satz und die entgegengesetzte Überlegung, denn sie sagt etwas
über das Protokoll. **Das Verhalten gibt es seit A5 und nicht erst seit dieser
Änderung** — es steht hier als Frage und nicht als Befund.

## §9 Stand nach dem ersten Abend

| Punkt | Zustand |
|---|---|
| 1 — kurze Quelle, zwei Zustände | **erfüllt** |
| 2 — lange Quelle | **erfüllt** |
| 3 — Bytedeckel *(Ausschluss)* | **erfüllt** |
| 4 — Filter | **erfüllt** |
| 5 — Journal *(Ausschluss)* | **erfüllt** |
| 6 — 390 px und die Klebeprobe | offen — braucht eine Browserkonsole |
| 7 — Kopieren | offen — braucht eine Maus |
| 8 — beide Themen, beide Breiten | offen |

**Punkt 2 ist am selben Abend geschlossen worden:** Nach dem Druck auf „Mehr
Zeilen" steht die erste Nummer auf **−200**, die Fusszeile auf „200 Zeilen von
500 Treffern". Damit sind **sechs von acht** erfüllt.

**Beide Ausschlusskriterien stehen.** Drei Befunde sind offen und keiner davon
ein Kriterienausfall: Befund 1 (die Vorschrift, behoben), Befund 2 (das
Bruchstück am Bytedeckel) und Befund 3 (der Streifen neben der Nummer).

---

## §10 Die Klebeprobe ist erweitert — und in vier Richtungen gemessen

**Kein Eingriff am Prüfling, sondern am Messmittel** — das darf während des
Laufs, und es muss: Die Punkte 6 bis 8 stehen noch aus, und die alte Probe
hätte Befund 3 wieder nicht gesehen.

Sie steht jetzt als **`tests/kleben-messen.js`** im Repo statt inline im Lauf.
Sie fragt zwei Dinge statt einem — bleibt die Nummer stehen, **und** scheint
links neben ihr etwas durch — und trägt eine Selbstprüfung: Findet die
Abtastung den Text nicht einmal dort, wo er mit Sicherheit liegt, ist ein
leerer Streifen keine Auskunft.

| Richtung | Ergebnis |
|---|---|
| wie gebaut | `deckt=false`, `imStreifen=[log-text]`, `misst=true` |
| mit dem Polster an der Spalte | `deckt=true`, `imStreifen=[—]`, `misst=true` |
| Text auf `visibility: hidden` | `misst=false` samt Warnung |
| zweiter Aufruf ohne Neuladen | wirft |

**Sie lässt die Seite gerollt stehen**, damit ein Bild danach den gemessenen
Zustand zeigt und nicht den aufgeräumten.

### Befund 4 — der Wächter über die Messmittel kennt diese Art nicht

`OverflowProbeTest` blieb grün, als drei seiner Regeln in der neuen Probe
gebrochen wurden: fehlender Stand, keine gedruckte Zeile, keine Sperre gegen
den zweiten Lauf.

Der Grund steht in seinem eigenen Kopf: Er erkennt ein Messmittel daran, dass
es **etwas in die Seite einsetzt** (`document.body.append(`), und begründet das
ausdrücklich — Werkzeuge, die nur Routen abfragen, haben weder Prüfkörper noch
Gegenprobe. `kleben-messen.js` ist eine dritte Art: Sie misst die Seite und
setzt nichts ein.

> **Ein Merkmal, das zwei Arten trennt, ordnet die dritte einer von beiden zu —
> und welcher, entscheidet der Zufall.**

Drei seiner Regeln gelten für jedes Messmittel, das eine Seite misst — Stand,
eine gedruckte Zeile, Sperre gegen den zweiten Lauf —, und zwei nur für die mit
Prüfkörper. **Der Wächter trennt das nicht.** Behoben wird nach dem Lauf,
zusammen mit den Befunden 2 und 3.

---

## §11 Punkt 6 und 8 — die Bilderrunde *(erfüllt)*

Vier Lagen auf `cloudsrv24`, Quelle `agent`, je eine frisch geladene Seite:

| Lage | | |
|---|---|---|
| 1440 dunkel · 1440 hell · 390 dunkel · 390 hell | `dokument=0` | `gegenprobe=200 (soll 200)` · `schiebt=0` · `rollt=1` · `versteckt=0` |

## §12 Befund 5 — die Klebeprobe mass zweimal nichts und sagte es zweimal

**Beim ersten Lauf auf dem Server:**

    rollweg=2164 nummer=17->17 klebt=true imStreifen=[log-line] deckt=false misst=false

**Die Selbstprüfung hat zugeschlagen, und das ist der Grund, dass sie da ist.**
Die Probe mass die **vierte** Zeile und rollte bis ans Ende; auf dem Server sind
die Zeilen verschieden lang, und die vierte endet lange vor `rollweg=2164`.
Unter dem Streifen lag dann nichts — und ohne die Selbstprüfung wäre daraus
`deckt=false` als Befund geworden oder, schlimmer, `deckt=true` als Freispruch.

**Der Prüfstand im Container hatte lauter gleich lange Zeilen** und konnte den
Fehler deshalb nicht zeigen.

> **Ein Prüfstand, dessen Zeilen alle gleich lang sind, versteckt jeden Fehler,
> der an der Länge hängt.**

**Der zweite Anlauf war auch keiner.** Umgestellt auf die **längste** Zeile,
gegen verschieden lange Zeilen gemessen: `deckt=true` bei `misst=false` — also
ein Freispruch aus einer Messung, die nicht stattgefunden hat. `.log` rollt auch
senkrecht, und die längste von hundert Zeilen steht in aller Regel nicht im
sichtbaren Ausschnitt.

> **Ein Prüfkörper, der seinen Gegenstand nicht im Blick hat, misst den leeren
> Raum — und der besteht jede Prüfung.**

Die Probe holt die gemessene Zeile seitdem erst senkrecht in den Blick. Gegen
verschieden lange Zeilen stimmen alle Richtungen:

| Richtung | Ergebnis |
|---|---|
| wie gebaut | `deckt=false` · `misst=true` |
| mit dem Polster an der Spalte | `deckt=true` · `misst=true` |
| Text auf `visibility: hidden` | `misst=false` samt Warnung |
| zweiter Aufruf ohne Neuladen | wirft |

**Was daraus über Messmittel gilt, und nicht nur über dieses:**

> **Ein Messmittel, das sagt, dass es nichts gemessen hat, ist mehr wert als
> eines, das eine Null liefert.** Zwei falsche Urteile in Folge hat allein die
> Selbstprüfung abgefangen — die Probe selbst lag beide Male daneben.

## §13 Was noch aussteht

- **Punkt 6, zweite Hälfte** — die Klebeprobe auf dem Server, mit der
  berichtigten Fassung. **Erwartet ist `deckt=false`** mit `log-text` im
  Streifen: Das ist Befund 3, und er soll dort erscheinen. Käme `deckt=true`,
  wäre die Containermessung falsch gewesen.
- **Punkt 7** — das Kopieren mit der Maus. Nicht belegt.

---

## §14 Punkt 6 vollständig und Punkt 7 — beide erfüllt

**Die Klebeprobe, berichtigte Fassung, auf `cloudsrv24`:**

    stand=2026-09-13c breite=1440 thema=dark rollweg=7932 zeileBreit=9001
    nummer=17->17 klebt=true imStreifen=[log-text] deckt=false misst=true

`misst=true` — die Messung hat stattgefunden, und erst damit bedeutet `deckt`
etwas. `klebt=true` bei einem Rollweg von **7932 px**: Die Nummer bleibt stehen,
und **Punkt 6 ist damit erfüllt**, denn danach fragt er.

**`deckt=false` ist Befund 3 auf dem echten Server**, Zeichen für Zeichen wie im
Container. Er ist kein Kriterienausfall: `docs/915 §8` fragt, ob die Nummer
stehenbleibt, und nicht, ob sie deckt — **das ist genau die Lücke, die Befund 3
benannt hat**, und sie steht jetzt zweimal da, in der Vorschrift und im
Ergebnis.

**Punkt 7 ist erfüllt:** Drei Zeilen mit der Maus überstrichen und kopiert —
**keine Zeilennummern im eingefügten Text.** Der erzeugte Inhalt aus
`content: attr(data-nummer)` hält also auch auf einem echten Rechner.

### Befund 6 — mein Prüfkörper für Punkt 7 war schlecht gewählt

`docs/915 §9` sagt, der kopierte Text solle **in das Filterfeld** eingefügt
werden. Der Agent begrenzt den Filter auf **200 Zeichen**
(`SystemLogsTail::filter()`, gemessen); drei Protokollzeilen sind länger,
und die Seite filtert dann nichts.

> **Ein Prüfkörper, der den kopierten Text in ein Feld mit einer Längengrenze
> einfügt, prüft die Grenze und nicht den Text.**

Der Punkt war trotzdem entscheidbar, weil der eingefügte Text sichtbar bleibt —
aber das war Glück und nicht Entwurf. Ein Feld ohne Grenze oder ein Blick in die
Zwischenablage wäre der richtige Ort.

## §15 Bilanz — alle acht Punkte erfüllt

| Punkt | Zustand |
|---|---|
| 1 — kurze Quelle, zwei Zustände | **erfüllt** |
| 2 — lange Quelle | **erfüllt** |
| 3 — Bytedeckel *(Ausschluss)* | **erfüllt** |
| 4 — Filter | **erfüllt** |
| 5 — Journal *(Ausschluss)* | **erfüllt** |
| 6 — 390 px, Klebeprobe | **erfüllt** |
| 7 — Kopieren | **erfüllt** |
| 8 — beide Themen, beide Breiten | **erfüllt** |

**Beide Ausschlusskriterien stehen**, und kein Punkt ist als „nicht
herstellbar" ausgefallen.

**Sechs Befunde, und nur zwei stecken im Prüfling.**

| # | wo | Zustand |
|---|---|---|
| 1 | Vorschrift — `head -20` schnitt vor den gefragten Feldern ab | behoben |
| 2 | **Prüfling** — beim Bytedeckel ist die erste Zeile ein Bruchstück | offen |
| 3 | **Prüfling** — beim Rollen scheint Text neben der Nummer durch | offen |
| 4 | Wächter — `OverflowProbeTest` kennt diese Art Messmittel nicht | offen |
| 5 | Messmittel — die Klebeprobe mass zweimal nichts | behoben |
| 6 | Vorschrift — das Filterfeld als Ort für den kopierten Text | offen |

**Das Verhältnis ist dasselbe wie in `docs/45`, `docs/48`, `docs/59` und
`docs/84`:** Die Mehrheit der Fehler steckt nicht im Prüfling. Und beide
Prüflingsbefunde hat **das Bild** gefunden und keine Zahl — der eine im Bild zu
Punkt 3, der andere im Bild zu Punkt 4.

> **Eine Zahl sagt, ob die Seite schiebt. Ein Bild sagt, was darauf steht.
> Keines von beiden ersetzt das andere.**

### Was benannt offen bleibt

- **Die Beobachtung zur Fusszeile** (`docs/914 §13`): Ohne Filter und bei
  vollständig gezeigter Quelle steht dieselbe Zahl zweimal. Auf dem Server
  bestätigt, nicht entschieden.
- **Die Bootmarke bekommt eine Zeilennummer wie ein Eintrag** (§8). Seit A5 so,
  nicht durch diese Änderung.
- **Die Domainseite** (`web.logs.tail`) bekommt die neuen Felder mitgesendet und
  zeigt sie nicht (`docs/914 §12`).

---

## §16 Die vier offenen Befunde sind gebaut — 13. September 2026

**Nach dem Lauf und nicht in ihm**, aus dem Grund, der in diesem Repo seit
`docs/903 §16.1` steht: Eine Behebung ist eine Änderung am Prüfling. Dazu die
drei Entscheidungen des Betreibers zur Form, gestellt als Fragen zu den
Befunden — sie stehen mit ihrer Begründung in **`docs/917`** und nicht hier.

| # | gebaut als |
|---|---|
| 2 | `WebLogsTail::tail()` wirft die angebrochene erste Zeile weg, wenn der Bytedeckel gegriffen hat |
| 3 | `.log` hat kein `padding-inline` mehr; die sechzehn Pixel liegen an Nummer und Text |
| 4 | `OverflowProbeTest` hat zwei Mengen statt einer — dazu §16.1 |
| 6 | `docs/915 §9` fügt nicht mehr ins Filterfeld ein |

### §16.1 Befund 4 hat beim Beheben zwei weitere gefunden

**Der Wächter wählte seine Prüflinge an `document.body.append(`** — also an
einer Eigenschaft, die nur die Messmittel **mit** Prüfkörper haben. Geteilt ist
er jetzt in `instruments()` (jedes, das eine gerenderte Seite ausliest, erkannt
an `querySelector`) und `probes()` (die Teilmenge, die etwas einsetzt). Stand,
gedruckte Zeile und Wiederholungssperre gelten für alle; Prüfkörper und
Gegenprobe nur für die zweite Menge.

**Der erweiterte Zugriff war beim ersten Lauf sofort rot, zweimal.**

**Erstens am Stand.** Der Ausdruck verlangte `'\d{4}-\d{2}-\d{2}'`, und
`kleben-messen.js` trägt `2026-09-13c` — an einem Tag hat es drei Fassungen
gebraucht, und genau das soll das Feld ja unterscheiden können. Falsch war der
Ausdruck und nicht der Stand.

> **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die Gewohnheit und
> nicht die Regel.**

**Zweitens am Ergebnisobjekt.** Drei Prüfungen suchten es mit
`strstr($quelltext, '  return {')`. In `bilder-messen.js` traf das den `return`
der **Gegenprobe** — vier Leerzeichen, und die zwei gesuchten stecken darin —
und damit den ganzen Rest der Datei; „steht das im Ergebnis" hiess dort in
Wahrheit „steht das irgendwo danach". `kleben-messen.js` hat kein `return {`
und lieferte eine leere Zeichenkette, also ehrliches Rot.

> **Eine Marke, die auch etwas anderes trifft, ist keine — und solange sie zu
> viel trifft, fällt es niemandem auf.**

Beides ist behoben; das Ergebnisobjekt holt jetzt ein Helfer, der beide
Schreibweisen kennt. Er heisst `returnedObject()` und nicht `result()`:
Diesen Namen hat `PHPUnit\Framework\TestCase` als `final` vergeben, die Klasse
stirbt beim Laden, und `BaseMethodClashTest` hat beim ersten Lauf zugebissen —
zum sechsten Mal in diesem Repo.

### §16.2 Ein Eingriff des Bruchskripts war nach der Teilung stumm

`nur noch ein Messmittel` nimmt `document.body.append(` aus
`baender-messen.js` und war gegen `test_a_second_run_is_refused` gemessen.
Nach der Teilung geht dieser Fall über `instruments()`, und die enthält
`baender-messen.js` weiterhin — der Eingriff veränderte seine Datei und störte
niemanden mehr. Er zielt jetzt auf `test_the_probe_is_bound_to_the_page`.

> **Ein Eingriff geht nicht nur kaputt, wenn seine Zielstelle umzieht — auch,
> wenn die Regel, die er brechen soll, unter ihm eine andere Menge bekommt.**

### §16.3 Und ein Prüfkörper, den erst der Eingriff als Attrappe entlarvt hat

`LogWindowTest::test_the_cap_never_yields_a_half_line` war für Befund 2 gebaut
und **hat ihn nie gemessen.** Seine Zeilenbreite war 4096 Bytes, und der Leser
holt seine Blöcke in Zweierpotenzen: Eine Zeile, die eine davon teilt, endet
immer genau an einer Blockgrenze. Der Deckel fiel nie mitten in eine Zeile —
der Fall war grün, ob der Schutz dastand oder nicht.

Gefunden hat es nicht das Nachdenken, sondern der Eingriff, der ihn nicht rot
bekam.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht** — und ein Wächter, den man nie bricht, sagt es einem nie.

Die Breite ist jetzt **3001**: Eine ungerade Zahl kann mit einer Zweierpotenz
nicht zusammenfallen, gleich wie der Leser seine Blockgrösse wählt. Zwei
Prämissen stehen als Zusicherung im Fall selbst — die Breite ist ungerade, und
das Fenster ist kleiner als die Anfrage, weil `array_slice` das Bruchstück
sonst ohnehin abschnitte.

### §16.4 Und ein bestehender Wächter hat das neue Feld beim Namen genommen

`AttributeLabelTest` — gebaut nach `docs/66` Befund 3 — meldete beim ersten
vollen Lauf:

    Logs/Index.vue — Feld `order`: auf der Seite „Reihenfolge",
    der Server sagt „Sortierung"

Der Name gehört der Datenbankkonsole: Sie prüft `order` und meint damit die
Spalte, nach der sortiert wird. Auf `/logs` wird das Feld **nicht** geprüft, es
könnte also gar keine Meldung geben — der Wächter fragt trotzdem zu Recht, denn
sein Gegenstand ist der Wortlaut und nicht der Weg dorthin. Die Seite heisst
seitdem „Sortierung".

> **Ein Feld, das zwei Seiten teilen, teilen sie auch im Wortlaut — oder eine
> von beiden nennt es anders, als die Meldung es tut.**

### §16.5 Im Container gemessen — Befund 3 ist zu

Gegen den gebauten Stand, echter Agent, echte Seite, 13. September 2026:

    A   stand=2026-09-13c breite=1440 thema=dark rollweg=3432 zeileBreit=4516
        nummer=1->1 klebt=true imStreifen=[—] deckt=true misst=true
    A2  … imStreifen=[log-text] deckt=false misst=true

**`deckt=true` bei `misst=true`** — der Streifen links neben der Nummer ist
leer, und die Selbstprüfung sagt, dass die Abtastung den Text dort findet, wo
er liegt. **A2 ist die Gegenprobe und der Grund, dass A etwas bedeutet:** das
Polster per Stylesheet zurück an den Rahmen gehängt, und schon steht `log-text`
wieder im Streifen. Ohne sie bliebe offen, ob die Abtastung überhaupt je
etwas findet.

**Die Tönung, gemessen und nicht angesehen** — vier Lagen, Spalte gegen Rahmen:

| Thema | Spalte | Rahmen |
|---|---|---|
| hell | `rgb(236, 238, 242)` | `rgb(250, 250, 251)` |
| dunkel | `rgb(30, 34, 43)` | `rgb(20, 23, 29)` |

**Das Zeichen, beide Zustände hergestellt:** bei unvollständigem Fenster
`↑20 ↑19 ↑18` und kein `−` irgendwo auf der Seite, bei vollständig gelesener
Quelle `1 2 3` ohne Zeichen.

**Und der erste Lauf hat diesen Punkt nicht gemessen.** Die Prüfdatei hatte
300 Zeilen, der Leser kam bis zum Anfang, und `complete` stand auf `true` —
die Nummern waren echte Dateizeilen, der Pfeil kam gar nicht vor. Gesehen hat
es niemand ausser der Messung selbst, weil sie ihren Zustand mitdruckt.

> **Eine Messung, die ihren Zustand nicht mitdruckt, ist von einer, die ihn
> nicht hatte, nicht zu unterscheiden.**

Mit 900 Zeilen ist der Zustand hergestellt.

**Die Umkehrung, an einem Paar abgelesen:**

| | erste | letzte | erste Zeile |
|---|---|---|---|
| `oldest` | `↑20` | `↑1` | `[… 11:42:47]` |
| `newest` | `↑1` | `↑20` | `[… 11:45:00]` |

Die Nummern tauschen die Plätze und ändern sich nicht — und die Textzeile
daneben belegt, dass wirklich der Inhalt gedreht wurde und nicht nur die
Beschriftung.

**Bilderrunde, vier Lagen:** `dokument=0`, Gegenprobe `200/200`, `schiebt=0`,
`rollt=1` (der Protokollrahmen, der rollen darf).

### §16.6 Was danach zu messen bleibt

**Auf einem Server gesehen hat davon nichts.** Die Abnahme der Umkehrung und
der Form steht als sechs Punkte in `docs/917 §6`; Punkt 6 ist die Klebeprobe
mit `deckt=true`, und die Containermessung sagt, was dort herauskommen muss.
