# B4 — der Abnahmelauf für die Verläufe auf Abonnement- und Domainseite

Ausgeschrieben am 28. September 2026, **vor** dem Fahren. Das Kriterium steht in
`docs/129 §9`:

> Fünf Kacheln auf der Abo-Seite und drei auf der Domainseite, mit Bild in
> beiden Themes und bei 390 px, dazu die Zahl daneben.

Gebaut ist B4 seit dem 21. September und ausgeliefert seit `v0.9.0-rc.1`; die
Begründungen stehen im CHANGELOG unter „B4 — die Verläufe auf der Abonnement-
und der Domainseite". Zwei der fünf Kacheln, „Speicherplatz" und
„Datenbanken", schrieb bis `0.9.0-rc.7` kein Lauf (`docs/138 §0` Punkt 1).
**Auf `cloudsrv24` läuft `rc.7` seit dem Abend des 28. September**, und
`srvpanel:usage` hat dort am selben Abend zum ersten Mal abgelegt
(`docs/138 §7`, Block 0).

**Fahrbar ist der Lauf ab dem 29. September, 01:00 Uhr.** Eine Kachel zeigt
eine Kurve erst ab dem zweiten Tag
(`DailyHistoryTest::test_a_single_day_is_not_a_curve`). Platz und Datenbanken
haben ihren zweiten Tag mit der ersten Messung nach Mitternacht, und der
Nachtlauf unter `rc.7` legt dann die Nullen des 28. ab. Der Zeitgeber des
Nachtlaufs streut bis 01:00.

**Beim Ausschreiben sind zwei Befunde am Prüfling herausgefallen** (§0 Punkte 1
und 2). Keiner hält den Lauf auf, beide ändern, was er zeigt. §6 fragt, ob sie
vor dem Lauf behoben werden.

Neu ist dazu ein Messmittel: **`tests/kacheln-messen.js`** liest die
Kachelreihe einer Seite und die Ablesung an ihren Kurven, so wie
`tests/bilder-messen.js` den Überlauf liest. Es hält dieselben Regeln wie die
übrigen Messmittel: Jede Zeile nennt seinen Stand, das Urteil steht in einer
gedruckten Zeile, und ein zweiter Aufruf ohne Neuladen wirft.
`OverflowProbeTest` hält das an jedem `tests/*-messen.js`, das eine Seite
liest.

---

## §0 · Was beim Ausschreiben umgefallen ist

**1 · Die Zahlen tragen Stellen, die es nicht gibt, und dieselbe Grösse steht
zweimal verschieden auf der Seite.** `History` formatiert Zugriffe,
Speicherplatz, Datenbanken und Fehlerquote mit `Points::plainFormatter()`. Die
Regel darin ist für Raten gebaut und dort richtig: unter 1 zwei Stellen, unter
10 eine, darüber keine, damit eine Auslastung von 0,42 % nicht als `0 %`
dasteht. Für eine **Anzahl** und für einen ganzen Megabytewert ist sie falsch.
Gemessen im Container, mit dem Bestand aus §2a:

| Kachel | Wert in der Tabelle | die Kachel zeigt | darunter auf der Seite |
|---|---|---|---|
| Zugriffe, 5 Anfragen | 5 | `5,0` | — |
| Zugriffe, ruhiger Tag | 0 | `0,00` | — |
| Speicherplatz | 3 MB (`disk_used_mb`, ganzzahlig) | `3,0 MB` | `3 MB` |
| Datenbanken | 3 670 016 B | `3,5 MB` | `3 MB` |
| Datenbanken, keine Datenbank | 0 | `0,00 MB` | „Keine Datenbanken angelegt." |
| jede Kachel ohne Kurve | — | `— MB`, `— B`, `— %` | — |

Die Zeile „Datenbanken" ist der schwerere Teil. Der Bereich darunter rechnet
über `Subscription::databaseUsedMb()` mit `intdiv(…, 1024 * 1024)` und zeigt
**3 MB**; die Kachel darüber teilt dieselbe Summe durch 1 048 576 und zeigt
**3,5 MB**. Das ist dieselbe Messung zur selben Minute. Der Kopf von
`History::level()` sagt, die Kachel richte sich „nach der Zahl, die zwei Zeilen
über ihr steht". Für die Einheit stimmt das, für die Zahl nicht.

**Und der Wächter dafür ist grün, weil sein Prüfkörper der eine Fall ist, in
dem beide Rechnungen übereinstimmen.**
`DailyHistoryTest::test_the_database_tile_speaks_the_unit_of_its_page`
verlangt „dieselbe Rechnung wie im Bereich darunter" und prüft sie an genau
einem Gigabyte, also an 1024 ganzen MB. Dort geben `intdiv` und die Division
dasselbe; bei 3,5 MB nicht.

> **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte Auskunft,
> sondern eine widersprüchliche.** Der Satz steht seit A2 in CLAUDE.md, dort
> über den Zustand einer Unit.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.** Hier ist es eine glatte Zahl, an der zwei Rundungen gleich
> aussehen.

> **Ein Format, das für eine Rate reicht, reicht nicht für eine Anzahl.** Die
> Schwester des Satzes aus B4 über Rate und Menge.

**Sichtbar wird es auf `cloudsrv24` ab dem 29. September.** Seit `rc.7`
bekommt ein ruhiger Tag eine Null, und am 29. haben die drei ruhigen Domains
zwei davon. Damit steht auf ihren Seiten `Zugriffe 0,00`.

**Die Fehlerquote gehört nicht dazu, und das ist nachgelesen.** Sie ist eine
Rate, und dort sind die Stellen gewollt: `DailyHistoryTest` hält `5,0 %` und
für einen Tag ohne Anfrage ausdrücklich `0,00 %`
(`test_a_day_without_requests_has_no_rate`). Dass ein Tag ohne Anfrage eine
Quote von null und keine leere trägt, begründet der Kopf von
`History::errorRate()` mit der Kurve, die eine Zahl braucht. Beides ist
entschieden; es steht hier, damit es auf dem Bild nicht wie ein Befund
aussieht.

**2 · Die Reihe steht immer da, und beide Seiten sagen im Kommentar das
Gegenteil.** Die Abonnementseite schreibt über die Reihe: *„Die Reihe steht nur
da, wenn es etwas zu zeigen gibt … Eine Kachelreihe aus fünf Strichen ‚noch
nichts gemessen' wäre eine Überschrift ohne Inhalt."* Die Domainseite schreibt:
*„Die Reihe steht nur da, wenn es mehr als einen Tag zu zeigen gibt."* Die
Bedingung darunter ist auf beiden Seiten `props.history.length > 0`, und
`History` gibt **immer** fünf beziehungsweise drei Kacheln zurück. Die
Bedingung ist damit immer wahr.

Gemessen im Container an einem Abonnement, das erst einen Tag hat
(`gamma.test`): Die Reihe steht da, fünf Kacheln mit „noch keine Messwerte",
jede **genau so hoch** wie eine Kachel mit Kurve an derselben Stelle (§2a).
Kaputt sieht das nicht aus. Falsch ist, dass Code und Kommentar zwei
verschiedene Seiten beschreiben, und kein Wächter hält eine von beiden.

> **Ein `v-if` über eine Liste, die nie leer ist, ist keine Bedingung — und
> der Kommentar darüber beschreibt eine Seite, die es nicht gibt.**

Welche der beiden richtig ist, ist eine Frage (§6 Frage 2).

**3 · „Dazu die Zahl daneben" heisst zweierlei, und der Lauf misst beides.** In
diesem Repo steht neben einem Bild seit `docs/46` die Zahl des Überlaufs, und
seit `docs/59` misst sie `tests/bilder-messen.js`: `dokument`, Gegenprobe,
`schiebt` (CLAUDE.md, „Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die
Seite schiebt."). An einer Kachelreihe liegt die zweite Lesart ebenso nahe: die
Zahl **in** der Kachel. Punkt 4 misst die erste, Punkt 1 und 2 die zweite, und
zwar gegen das, was der Server für dieselbe Seite rechnet (Block 2).

**4 · Welche fünf Kacheln, und was fünf auf dem Telefon kosten.** Die fünf sind
nicht die fünf des Plans: Statt der FPM-Prozesse steht dort die Fehlerquote
(CHANGELOG, B4). Gemessen werden die fünf, die gebaut sind, in dieser
Reihenfolge: Speicherplatz, Traffic, Zugriffe, Fehlerquote, Datenbanken.

`docs/129 §6` hatte die Frage, ob die Abonnementseite alle fünf zeigt oder
drei und die übrigen auf einer eigenen Seite, an diese Bilderrunde verwiesen.
**Beantwortet hat sie der Wortlaut des Kriteriums**, das fünf auf der
Abonnementseite verlangt. Der Preis ist gemessen und wird auf dem Server noch
einmal gemessen: Bei 390 px ist die Reihe **886 px** hoch, so weit rollt ein
Kunde, bevor die Stammdaten kommen. Bei 1440 px sind es 198 px.

**5 · Eine Kurve braucht zwei Tage, und das Fenster gilt je Kennzahl.** Auf
derselben Seite endet die Kurve von Speicherplatz und Datenbanken **heute** und
die des Verkehrs **gestern** (`docs/138 §5`). Das ist gebaut und richtig: Ein
Stand ist ab der Messung eine Zahl, Verkehr erst am Ende des Tages. Die
Ablesung in Punkt 3 zeigt es.

---

## §1 · Die Vorbedingung

```bash
# 1 · Welche Fassung läuft, und hat der Nachtlauf unter ihr schon abgelegt?
srvpanel version
systemctl show srvpanel-traffic.timer -p LastTriggerUSec
journalctl -u srvpanel-traffic.service --since today --no-pager | grep -E 'Laufender Tag|zählbar|Abgelegt|Fertig'
journalctl -u srvpanel-usage.service --since today --no-pager | grep -E 'Verlauf für' | tail -1
```

**Erwartet:** `0.9.0-rc.7` oder die Freigabe, die die Behebung aus §6 Frage 1
trägt. Die letzte Auslösung des Nachtlaufs liegt heute zwischen 00:00 und
01:00. Im Journal stehen die vier Zeilen des Laufs, darin `ruhig (eine Null)`
und `Abgelegt:`. Dazu steht eine Zeile `Verlauf für <heute>` von
`srvpanel:usage`. **Fehlt die Zeile von heute**, haben Platz und Datenbanken nur
einen Tag, und ihre Kacheln stehen noch leer; dann bis zur nächsten
Viertelstunde warten.

---

## §2 · Was der Server für jede Seite rechnet

Block 2 ruft dieselbe Klasse, die die Seiten füllen (`History`), für jedes
Abonnement und jede seiner Domains. Ausserdem druckt er die beiden Zahlen, die
auf der Abonnementseite **unter** der Reihe stehen: Platz und Datenbanken, so
gerechnet wie der Controller sie rechnet. **Seine Ausgabe wird abgeschrieben:
Sie ist die Erwartung an Punkt 1 und Punkt 2.**

Gelesen wird in `withoutRestriction()`: `srvpanel tinker` läuft ohne
angemeldetes Konto, und über die Modelle kämen sonst **wortlos null Zeilen**
zurück (CLAUDE.md, „Eine Frage, die im Grundzustand alles verweigert").

```bash
# 2 · Die Kacheln jeder Seite, wie der Server sie rechnet
srvpanel tinker --execute='
$h = app(App\Support\Metrics\History::class);
$zeile = function (array $k): string {
    $p = $k["series"]["points"];
    $zweite = isset($k["second"]) ? sprintf("  (%s %s)", $k["second"]["label"], trim($k["second"]["value"] . " " . $k["second"]["unit"])) : "";
    return sprintf("%-14s %-12s %2d Punkte%s%s", $k["label"], trim($k["value"] . " " . $k["unit"]), count($p),
        $p === [] ? "" : sprintf(", %s bis %s", $p[0]["t"], $p[count($p) - 1]["t"]), $zweite);
};
$mb = fn ($v) => $v === null ? "—" : number_format((int) $v, 0, ",", ".") . " MB";
app(App\Support\Tenancy\Tenancy::class)->withoutRestriction(function () use ($h, $zeile, $mb) {
    foreach (App\Models\Subscription::query()->orderBy("id")->get() as $s) {
        printf("/subscriptions/%d  %s   (darunter auf der Seite: Platz %s, Datenbanken %s)\n", $s->id, $s->name,
            $mb($s->disk_used_mb), $mb($s->databaseUsedMb()));
        foreach ($h->forSubscription($s) as $k) { printf("  %s\n", $zeile($k)); }
        foreach (App\Models\Domain::query()->where("subscription_id", $s->id)->orderBy("id")->get() as $d) {
            printf("  /domains/%d  %s\n", $d->id, $d->name);
            foreach ($h->forDomain($d) as $k) { printf("    %s\n", $zeile($k)); }
        }
    }
});
'
```

**Erwartet** am 29. September auf `cloudsrv24`, drei Abonnements:

- Je Abonnement **fünf** Zeilen in der Reihenfolge Speicherplatz, Traffic,
  Zugriffe, Fehlerquote, Datenbanken; je Domain **drei**: Traffic, Zugriffe,
  Fehlerquote.
- Speicherplatz und Datenbanken mit **2 Punkten, 28.09. bis 29.09.**, bei jedem
  der drei Abonnements: `srvpanel:usage` hat am 28. für alle drei abgelegt
  (`docs/138 §7`, Block 0).
- Die Kacheln des Verkehrs enden am **28.09.** Beim Abonnement mit Verkehr
  sind es so viele Punkte, wie die Tabelle Tage hat, höchstens dreissig. Das
  Abonnement, dessen Domains ruhig waren, hat zwei Punkte, 27.09. und 28.09.,
  beide null, sofern es davor keine Zeile hatte. Das dritte hat keine Zeile
  des Verkehrs (`docs/138 §7`) und damit dort `—` und 0 Punkte.
- **Solange §0 Punkt 1 nicht behoben ist**, stehen die Zahlen in der Form aus
  der Tabelle dort: `Zugriffe 0,00` an einem ruhigen Tag, und bei den
  Datenbanken eine Nachkommastelle, wo darunter eine ganze Zahl steht.

### §2a · Was im Container gemessen ist

Am 28. September 2026 gegen MariaDB 10.11.14, Zone `Etc/UTC`. Der Bestand ist
über die **echten** Schreiber angelegt, `Daily::record()` für den Verkehr samt
ruhiger Tage und `Daily::levels()` für Platz und Datenbanken:

- `alpha.test`: zwei Domains. `alpha.test` hat Verkehr vom 26.08. bis zum
  27.09., `shop.alpha.test` vom 20.09. an, am letzten Tag 5 Anfragen. Zwei
  Datenbanken mit 3 MiB und 0,5 MiB, Platz und Datenbanken am 27. und 28.
- `beta.test`: zehn Tage zu 7 Anfragen, dann zwei ruhige Tage, 3 MB Platz, keine
  Datenbank.
- `gamma.test`: an diesem Tag angelegt, 0 MB Platz, kein Verkehr.

Die Seite ist ein Vite-Aufsatz im Scratchpad. Er bindet die **echte**
`Tile.vue` und das echte `app.css` ein, gefüttert mit dem, was `History` für
diesen Bestand zurückgibt. Die Reihe steht so da wie in den beiden Seiten;
nachgebaut ist nur der Rahmen um sie.

| Messung | Lage | gezeigt |
|---|---|---|
| Block 2 | wie gebaut | alpha: `Speicherplatz 1.250 MB 2 Punkte, 27.09. bis 28.09.`, `Traffic 76,8 MB 30 Punkte, 29.08. bis 27.09. (eingehend 3,1 MB)`, `Zugriffe 2.389`, `Fehlerquote 3,9 %`, `Datenbanken 3,5 MB`, darunter `Datenbanken 3 MB`; shop.alpha: `Zugriffe 5,0`, `Fehlerquote 20 %`; beta: `Speicherplatz 3,0 MB` bei `Platz 3 MB` darunter, `Zugriffe 0,00`, `Fehlerquote 0,00 %`, `Datenbanken 0,00 MB`; gamma: jede Kachel `—` mit 0 Punkten |
| `kachelnMessen()` | fünf Seiten × vier Lagen | `reihe=flex`, fünf beziehungsweise drei Kacheln; jede Zeile wie Block 2; bei 1440 px jede Kachel 196 px und die Reihe 198 px; bei 390 px 172, 193, 173, 173, 173 px und die Reihe 886 px, auf der Domainseite 540 px |
| dasselbe, leere Kacheln | gamma | jede Kachel `leer`, **dieselben Höhen** wie die Kacheln mit Kurve an derselben Stelle |
| `bilderMessen()` | dieselben zwanzig Lagen | jede `dokument=0 gegenprobe=200 (soll 200) schiebt=0 rollt=0 versteckt=0` |
| Gegenprobe Warnfarbe | Kontingent 1000 MB bei 1250 MB belegt | nur Speicherplatz `warnt` |
| Gegenprobe Stylesheet | alle Stylesheets entfernt | `reihe=block`, jede Kachel 503 px |
| Gegenprobe Kurve | das `.trend` einer Kachel entfernt | diese Kachel `UNKLAR` mit 0 Punkten |
| Gegenprobe Reihe | die Klasse `.tiles` entfernt | `keine Kachelreihe` |
| `ablesungMessen()` | alpha bei 1440 und 390 px | `Speicherplatz 27.09. · 1.234 MB … 28.09. · 1.250 MB`, `Traffic 29.08. · ausgehend 50,7 MB … 27.09. · ausgehend 76,8 MB`, `Zugriffe 29.08. · 1.311 … 27.09. · 2.389`; danach jede Unterzeile im Ruhezustand |
| dasselbe, leer | gamma | jede Kachel `keine Kurve` |
| Sperre | zweites `kachelnMessen()` ohne Neuladen | wirft: `Schon gemessen. Seite neu laden …` |
| Sperre | `kachelnMessen()` nach `ablesungMessen()`, danach ein zweites `ablesungMessen()` | beide werfen |
| Gegenrichtung | `ablesungMessen()` nach `kachelnMessen()` | läuft; beide geben ihr Ergebnis mit `stand: '2026-09-28'` auch als Objekt zurück |

**Die Ablesung zeigt die beiden Fenster nebeneinander**, ohne dass es jemand
herstellen musste: Die Kurve des Platzes endet am 28., dem Tag der Messung, die
des Verkehrs am 27. Und der erste Punkt des Verkehrs ist der 29.08. und nicht
der 26.08.: Von 33 Tagen zeigt die Seite die letzten dreissig.

---

## §3 · Die Punkte

Die Seiten werden als Betreiber geöffnet. In die Konsole kommt
**`tests/kacheln-messen.js`**, je Seite **frisch geladen**, dann:

```js
kachelnMessen()
```

Jede Ausgabe beginnt mit `stand=2026-09-28`, dem Stand des Messmittels, und mit
`reihe=flex`. Das ist der Beleg, dass das Stylesheet geladen ist: Ohne es steht
dort `block` (§2a).

**Ein zweiter Aufruf ohne Neuladen wirft**, und nach `ablesungMessen()` wirft
auch `kachelnMessen()`: Die Ablesung streicht mit dem Zeiger über jede Kurve,
und die Reihe danach wäre nicht mehr die geladene. Auf einer Seite wird deshalb
zuerst gemessen und dann abgelesen. Wer danach weitermessen will, lädt neu.

### Punkt 1 — Jede Abonnementseite zeigt, was der Server rechnet

Auf `/subscriptions/<id>` für jedes der drei Abonnements aus Block 2
`kachelnMessen()`.

**Erwartet:** `kacheln=5`. Jede Zeile trägt denselben Namen, dieselbe Zahl mit
derselben Einheit und dieselbe Zahl von Punkten wie Block 2, in derselben
Reihenfolge. Eine Kachel mit 0 Punkten heisst `leer`, eine mit zwei oder mehr
`Kurve`, und **keine** heisst `UNKLAR`. `warnt` steht nur, wo ein Kontingent
überschritten ist; auf `cloudsrv24` wird das keines sein.

### Punkt 2 — Jede Domainseite trägt drei Kacheln und den Satz darunter

Auf `/domains/<id>` für jede Domain aus Block 2 `kachelnMessen()`.

**Erwartet:** `kacheln=3`, jede Zeile wie Block 2. Darunter steht die Zeile
`darunter: Gezählt wird, was der Webserver protokolliert. Die Zahl des
Providers liegt höher …` — der Satz gehört neben die Zahl und nicht in eine
Fussnote (`docs/129 §11`). Auf der Abonnementseite steht diese Zeile
**nicht**.

### Punkt 3 — Die Ablesung

Auf der Abonnementseite mit Verkehr, in derselben geladenen Seite wie ihr
`kachelnMessen()` aus Punkt 1 und **danach**:

```js
ablesungMessen()
```

**Erwartet:** Je Kachel mit Kurve zwei Ablesungen, am ersten und am letzten
Punkt, jede mit Tag und Wert. Am letzten Punkt steht bei Speicherplatz und
Datenbanken der Tag des Laufs, bei den drei Kacheln des Verkehrs der Tag davor
— am 29. September also **29.09.** und **28.09.** Der erste Punkt ist der
älteste Tag der Tabelle und nicht älter als dreissig Tage.
Nach jeder Ablesung steht die Unterzeile wieder im Ruhezustand (`danach:
belegt`, `danach: Anfragen` …). Eine Kachel ohne Kurve heisst `keine Kurve`.

### Punkt 4 — Das Bild, in beiden Themen und bei 390 px, und die Zahl daneben

Vier Lagen (hell/dunkel × 390/1440 px) auf **zwei** Seiten: der
Abonnementseite aus Punkt 3 und einer Domainseite desselben Abonnements mit
Verkehr. Je Lage:

1. Thema und Breite einstellen, wie in den bisherigen Bilderrunden, und die
   Seite **neu laden**.
2. `tests/bilder-messen.js` und `tests/kacheln-messen.js` in die Konsole
   einfügen. Die beiden Skripte vertragen sich in einer Seite, weil sie
   verschiedene Namen tragen.
3. Zuerst `kachelnMessen()`, dann `bilderMessen()`. `kachelnMessen()` liest
   nur, also misst `bilderMessen()` danach dieselbe Seite wie allein. Die
   Ablesung aus Punkt 3 gehört **nicht** hierher: Sie bewegt die Unterzeilen.
4. Ein Bild der Seite, **nach** den Messungen.

**Erwartet:**

- `dokument=0 gegenprobe=200 (soll 200) schiebt=0` in allen acht Lagen.
  `bilderMessen()` und `kachelnMessen()` werfen beim zweiten Aufruf ohne
  Neuladen; dass jede Lage eine eigene geladene Seite hatte, steht damit in den
  Zahlen selbst.
- `kachelnMessen()` in jeder Lage wie in Punkt 1 und 2. Bei 1440 px sind alle
  Kacheln einer Reihe gleich hoch. Bei 390 px stehen sie untereinander, und
  `höhe=` nennt, wie lang die Reihe auf dem Telefon ist. Im Container waren es
  886 px für fünf Kacheln und 540 px für drei; auf dem Server entscheidet die
  Schrift über ein paar Pixel.
- Auf dem Bild: Zahl, Einheit und Unterzeile jeder Kachel lesbar, in beiden
  Themen. Die Kurve des Verkehrs trägt **zwei** Linien, die eingehende
  gestrichelt. Keine Kachel ist abgeschnitten.

### Punkt 5 — Eine leere Kachel ist so hoch wie eine mit Kurve

Auf einer Seite mit mindestens einer leeren Kachel, am wahrscheinlichsten der
des dritten Abonnements, bei 390 und bei 1440 px `kachelnMessen()`.

**Erwartet:** Die leeren Kacheln heissen `leer` und tragen `noch keine
Messwerte` im Bild. Bei 1440 px sind sie so hoch wie ihre Nachbarn. Bei 390 px
ist jede so hoch wie die Kachel mit Kurve an derselben Stelle auf einer anderen
Seite, auf ein Pixel genau (§2a: 172, 193, 173, 173, 173 px). **Die Reihe
selbst steht da**, auch wenn alle Kacheln leer sind; das ist §0 Punkt 2, und ob
es so bleibt, entscheidet §6 Frage 2.

---

## §4 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Die Warnfarbe am Kontingent.** Sie herzustellen hiesse, ein Abonnement über
  sein Kontingent zu bringen; bliebe es zwei Nächte dort, bekäme der Kunde über
  B5 eine Mail. Im Container ist sie gemessen, in beide Richtungen (§2a).
- **Ein Kundenkonto.** Gefahren wird als Betreiber. Dass ein fremder Kunde
  keine Zeile sieht, hält die Mandantenklammer, an der `History` nichts ändert;
  `DailyHistoryTest::test_a_foreign_customer_sees_nothing` misst es an der
  Wirkung, mit der Gegenrichtung daneben.
- **Das Fenster von dreissig Tagen.** Auf `cloudsrv24` hat die Tabelle weniger
  Tage; das Abschneiden hält `DailyHistoryTest`, und das Abräumen ist Teil 2 von
  B3 (`docs/138 §2`).
- **Die Ablesung mit dem Finger.** `ablesungMessen()` schickt ein
  `pointermove` aus der Konsole. Ob ein Telefon beim Streichen abliest statt zu
  rollen, sagt nur ein Telefon.
- **Die FPM-Prozesse.** Sie sind nicht gebaut (§0 Punkt 4).
- **Die Zahl des Providers.** Der Satz unter der Reihe sagt, dass sie
  abweicht; wie weit, misst niemand.
- **Die Übersichtsseite.** Sie trägt dieselbe Kachel aus dem Ringpuffer und
  ist nicht B4.

---

## §5 · Wann er durch ist

**Alle fünf Punkte erfüllt.** Punkt 1, Punkt 2 und Punkt 4 dürfen nicht
ausfallen: Sie sind das Kriterium, die fünf und die drei Kacheln, das Bild und
die Zahl. Punkt 5 darf als „nicht herstellbar" ausfallen, wenn auf dem Server
keine Seite eine leere Kachel trägt; dann steht die Messung aus §2a. Ein Punkt,
der am Werkzeug scheitert, ist nicht „nicht herstellbar" (`docs/108`).
`tests/bilder-messen.js` braucht eine Browserkonsole; vom Telefon aus wird der
Punkt nachgeholt und nicht weicher gelesen.

**Stehen die Befunde aus §0 Punkt 1 dann noch da, ist Punkt 1 trotzdem
erfüllt** — die Seite zeigt, was der Server rechnet —, und der Befund bleibt
offen. Abgenommen wird B4 dann nicht vor der Entscheidung zu §6 Frage 1; die
Abnahme spricht der Betreiber aus.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten,
> ist keines mehr — es ist eine Zusammenfassung.**

---

## §6 · Zwei Fragen an den Betreiber — vor dem Lauf

Beide betreffen, was ein Kunde auf seiner Seite sieht, und stehen damit unter
der Regel vom 27. September: *Macht es Sinn und gibt es einen spürbaren
Mehrwert für einen Nutzer im Panel?*

**Frage 1 · Die Zahlen vor dem Lauf richten?** Der Vorschlag: **ja**, als
`0.9.0-rc.8` vor dem Lauf. Sonst dokumentiert die Bilderrunde Zahlen, von denen
feststeht, dass sie falsch dastehen, und nach der Behebung braucht es eine
zweite. Gerichtet würde, was §0 Punkt 1 zeigt, und nicht mehr:

- **Eine Anzahl steht ganz da:** `5`, `0`, `2.389`.
- **Speicherplatz und Datenbanken stehen so da wie darunter:** in ganzen MB mit
  Tausenderpunkt, bei den Datenbanken mit derselben Rundung wie
  `databaseUsedMb()`. Gerundet wird **vor** der Kurve und nicht erst an der
  Zahl, sonst zeigt die Kurve Ausschläge, die keine Zahl benennt. Das ist der
  Fall, den der Kopf von `Points::plainFormatter()` für die CPU-Kachel
  beschreibt.
- **Ein leerer Wert trägt keine Einheit:** `—` statt `— MB`.

Gehalten würde das an der Wirkung, von einem Wächter über `History`: Dieselbe
Messung ergibt oben und unten dieselbe Zahl, geprüft an einem Wert, an dem die
beiden Rundungen auseinandergehen, und eine Anzahl hat kein Komma. **Die
Fehlerquote und die Übersichtsseite bleiben, wie sie sind**: Dort stehen
Raten, und für Raten sind die Stellen richtig.

**Frage 2 · Die Reihe ohne Messwerte: stehen lassen oder ausblenden?** Der
Vorschlag: **stehen lassen und die beiden Kommentare berichtigen.** Gemessen
steht eine leere Kachel genau so hoch da wie eine mit Kurve. Die Zeile „noch
keine Messwerte" sagt einem Kunden am ersten Tag, was kommt. Eine Reihe, die
erst am zweiten Tag erscheint, verschiebt die ganze Seite von einem Tag auf
den nächsten. Ausblenden hiesse, auf zwei Seiten eine zweite Bedingung zu
bauen, die fragt, ob irgendeine Kachel eine Kurve hat. Gewonnen wäre ein
leerer Tag weniger an einem neuen Abonnement. Nach der Regel vom 27. September
wird das nicht gebaut.

---

## §7 · Protokoll

Noch nicht gefahren.
