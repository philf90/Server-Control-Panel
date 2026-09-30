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

**Fahrbar ist der Lauf ab dem 29. September, 01:00 Uhr, gegen
`0.9.0-rc.8`.** Eine Kachel zeigt eine Kurve erst ab dem zweiten Tag
(`DailyHistoryTest::test_a_single_day_is_not_a_curve`). Platz und Datenbanken
haben ihren zweiten Tag mit der ersten Messung nach Mitternacht, und der
Nachtlauf legt dann die Nullen des 28. ab. Der Zeitgeber des Nachtlaufs streut
bis 01:00.

**Beim Ausschreiben sind zwei Befunde am Prüfling herausgefallen** (§0 Punkte 1
und 2). Keiner hält den Lauf auf, beide ändern, was er zeigt. §6 hat gefragt,
ob sie vor dem Lauf behoben werden. **Der Betreiber hat am selben Tag beide wie
vorgeschlagen entschieden**, und behoben sind sie für `0.9.0-rc.8` (§6). Gegen
diese Freigabe wird der Lauf gefahren.

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

**Sichtbar würde es auf `cloudsrv24` ab dem 29. September.** Seit `rc.7`
bekommt ein ruhiger Tag eine Null, und am 29. haben die drei ruhigen Domains
zwei davon. Unter `rc.7` stünde damit auf ihren Seiten `Zugriffe 0,00`.

**Behoben am 28. September für `0.9.0-rc.8`** (§6 Frage 1). Nachgemessen am
selben Bestand (§2a): `Zugriffe 5` und `0`, `Speicherplatz 3 MB` über `3 MB`,
`Datenbanken 3 MB` über `3 MB`, und jede leere Kachel zeigt `—` ohne Einheit.
Die Fehlerquote steht weiter mit `0,00 %` da.

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

Welche der beiden richtig ist, war eine Frage (§6 Frage 2). **Entschieden am
28. September: Die Reihe bleibt.** Das `v-if` ist auf beiden Seiten fort, und
die Kommentare beschreiben die Seite, die es gibt.

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

**Erwartet:** `0.9.0-rc.8`, die Freigabe mit der Behebung aus §6. Unter
`rc.7` stünden die Zahlen in der Form aus §0 Punkt 1, und die Bilderrunde
hielte sie fest. Die letzte Auslösung des Nachtlaufs liegt heute zwischen 00:00 und
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
$mb = fn ($v) => $v === null ? "noch nicht gemessen" : number_format((int) $v, 0, ",", ".") . " MB";
$db = fn ($s) => $s->databases()->count() === 0 ? "keine angelegt" : $mb($s->databaseUsedMb());
app(App\Support\Tenancy\Tenancy::class)->withoutRestriction(function () use ($h, $zeile, $mb, $db) {
    foreach (App\Models\Subscription::query()->orderBy("id")->get() as $s) {
        printf("/subscriptions/%d  %s   (darunter auf der Seite: Platz %s, Datenbanken %s)\n", $s->id, $s->name,
            $mb($s->disk_used_mb), $db($s));
        foreach ($h->forSubscription($s) as $k) { printf("  %s\n", $zeile($k)); }
        foreach (App\Models\Domain::query()->where("subscription_id", $s->id)->orderBy("id")->get() as $d) {
            printf("  /domains/%d  %s\n", $d->id, $d->name);
            foreach ($h->forDomain($d) as $k) { printf("    %s\n", $zeile($k)); }
        }
    }
});
'
```

**Berichtigt am 30. September, nach dem Fahren** (§7). Die Klammer druckte
`—` für zwei Zustände, die die Seite verschieden zeigt: „Keine Datenbanken
angelegt." und „Noch nicht gemessen.". Sie druckt jetzt die Wörter der Seite,
`keine angelegt` und `noch nicht gemessen`, beim Platz ebenso. Im Container
gegengeprüft, alle drei Zustände: an den Abonnements des Bestands aus §2a und
in einer Transaktion, die beide Messungen auf `null` setzt und danach
zurückgerollt wird.

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
- **Mit `rc.8`** stehen die Zugriffe ganz da, an einem ruhigen Tag `0`.
  Speicherplatz und Datenbanken stehen in ganzen MB und gleichen der Zahl in
  der Klammer dahinter (`darunter auf der Seite`). Eine Kachel ohne Punkte
  zeigt `—` ohne Einheit. Die Fehlerquote behält ihre Stellen.
- **Ein Abonnement ohne Datenbank** zeigt in der Klammer `keine angelegt` und
  in der Kachel `0 MB`: Der Messlauf legt für ein Abonnement ohne Datenbank
  eine Null ab (`docs/138 §5`), und die Seite sagt darunter „Keine Datenbanken
  angelegt.". Verglichen wird dort keine Zahl (§3 Punkt 1).

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

**Nachgemessen am Abend des 28. September gegen die Behebung aus §6**, am
selben Bestand und mit denselben Läufen. Die Tabelle zeigt den behobenen
Stand; was vorher dastand, zeigt §0 Punkt 1. In allen zwanzig Lagen blieb jede
Höhe aufs Pixel dieselbe, verändert haben sich nur die Werte. Nebenbei steht
beim Verkehr eines ruhigen Tages `0,0 kB`: Die Grössenordnung richtet sich nach
dem höchsten Wert der Reihe. Das gehört zur Verkehrskachel und nicht zu §6 und
bleibt.

| Messung | Lage | gezeigt |
|---|---|---|
| Block 2 | wie gebaut | alpha: `Speicherplatz 1.250 MB 2 Punkte, 27.09. bis 28.09.`, `Traffic 76,8 MB 30 Punkte, 29.08. bis 27.09. (eingehend 3,1 MB)`, `Zugriffe 2.389`, `Fehlerquote 3,9 %`, `Datenbanken 3 MB`, darunter `Datenbanken 3 MB`; shop.alpha: `Zugriffe 5`, `Fehlerquote 20 %`; beta: `Speicherplatz 3 MB` bei `Platz 3 MB` darunter, `Zugriffe 0`, `Fehlerquote 0,00 %`, `Datenbanken 0 MB`; gamma: jede Kachel `—` ohne Einheit und mit 0 Punkten |
| `kachelnMessen()` | fünf Seiten × vier Lagen | `reihe=flex`, fünf beziehungsweise drei Kacheln; jede Zeile wie Block 2; bei 1440 px jede Kachel 196 px und die Reihe 198 px; bei 390 px 172, 193, 173, 173, 173 px und die Reihe 886 px, auf der Domainseite 540 px |
| dasselbe, leere Kacheln | gamma | jede Kachel `leer`, **dieselben Höhen** wie die Kacheln mit Kurve an derselben Stelle |
| `bilderMessen()` | dieselben zwanzig Lagen | jede `dokument=0 gegenprobe=200 (soll 200) schiebt=0 rollt=0 versteckt=0` |
| Gegenprobe Warnfarbe | Kontingent 1000 MB bei 1250 MB belegt | nur Speicherplatz `warnt` |
| Gegenprobe Stylesheet | alle Stylesheets entfernt | `reihe=block`, jede Kachel 503 px |
| Gegenprobe Kurve | das `.trend` einer Kachel entfernt | diese Kachel `UNKLAR` mit 0 Punkten |
| Gegenprobe Reihe | die Klasse `.tiles` entfernt | `keine Kachelreihe` |
| `ablesungMessen()` | alpha bei 1440 und 390 px | `Speicherplatz 27.09. · 1.234 MB … 28.09. · 1.250 MB`, `Traffic 29.08. · ausgehend 50,7 MB … 27.09. · ausgehend 76,8 MB`, `Zugriffe 29.08. · 1.311 … 27.09. · 2.389`, `Datenbanken 27.09. · 3 MB … 28.09. · 3 MB`; danach jede Unterzeile im Ruhezustand |
| dasselbe, leer | gamma | jede Kachel `keine Kurve` |
| Sperre | zweites `kachelnMessen()` ohne Neuladen | wirft: `Schon gemessen. Seite neu laden …` |
| Sperre | `kachelnMessen()` nach `ablesungMessen()`, danach ein zweites `ablesungMessen()` | beide werfen |
| Gegenrichtung | `ablesungMessen()` nach `kachelnMessen()` | läuft; beide geben ihr Ergebnis mit `stand: '2026-09-28'` auch als Objekt zurück |

**Nachgetragen am 30. September:** `beta` und `gamma` haben keine Datenbank,
und die Klammer von Block 2 druckte bei beiden `—`, gegen denselben Bestand
mit der Fassung vor der Berichtigung nachgefahren. Die Tabelle hält bei `beta`
nur den Platz gegen die Klammer. Dieselbe blinde Stelle hatte der Lauf auf dem
Server (§7).

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

**Und Speicherplatz und Datenbanken zeigen dieselbe Zahl wie der Bereich
darunter**, die Block 2 in der Klammer druckt. Das ist die Behebung aus §6
Frage 1 auf dem Server.

**Ein Abonnement ohne Datenbank hat darunter keine Zahl**, sondern den Satz
„Keine Datenbanken angelegt.", und die Kachel zeigt `0 MB`. Dort wird nichts
verglichen. Auf `cloudsrv24` hatte keines der drei Abonnements eine Datenbank;
für diesen Lauf trägt `p6-abnahme.invalid` deshalb den Prüfkörper aus §7,
3.801.036 B. An ihm muss oben und unten `3 MB` stehen: `rc.7` hätte oben
`3,6 MB` gezeigt, und Runden ergäbe `4 MB`.

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
Seite, auf ein Pixel genau (§2a: 172, 193, 173, 173, 173 px). Ihr Wert ist `—`
ohne Einheit. **Die Reihe selbst steht da**, auch wenn alle Kacheln leer sind;
so ist es entschieden (§6 Frage 2).

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

**Gefahren wird gegen `rc.8`.** Steht eine Zahl dann noch in der Form aus §0
Punkt 1, ist Punkt 1 nicht erfüllt: Die Behebung ist nicht angekommen, und das
ist ein Befund an ihr. Die Abnahme spricht der Betreiber aus.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten,
> ist keines mehr — es ist eine Zusammenfassung.**

---

## §6 · Zwei Fragen an den Betreiber — vor dem Lauf

Beide betreffen, was ein Kunde auf seiner Seite sieht, und stehen damit unter
der Regel vom 27. September: *Macht es Sinn und gibt es einen spürbaren
Mehrwert für einen Nutzer im Panel?*

**Entschieden am 28. September 2026: beide wie vorgeschlagen.** Gebaut ist es
am selben Abend für `0.9.0-rc.8` (§6a).

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

### §6a · Gebaut am 28. September 2026

- **Eine Anzahl und ein Stand in ganzen MB** laufen durch
  `Points::wholeFormatter()`. Die Fehlerquote behält `plainFormatter()`.
- **Die Datenbanken runden an einer Stelle:** `Subscription::wholeMegabytes()`
  rechnet für `databaseUsedMb()` und für die Kachel. Abgerundet wird vor der
  Kurve.
- **Eine leere Kachel trägt keine Einheit.** Gefragt wird dieselbe Bedingung
  wie dort, wo der Strich entsteht. Die Übersichtsseite ruft das nicht.
- **Beide Seiten zeigen die Reihe ohne Bedingung.** Auf der Domainseite ist
  dafür der Rahmen gefallen: Ein `<template>` ohne Direktive übersetzt Vue zu
  einem echten `template`-Element, und dessen Inhalt zeigt der Browser nicht
  an (gemessen am Übersetzer).

Gehalten wird das von sechs neuen Fällen in `DailyHistoryTest`. Der wichtigste
liest beide Zahlen aus der Antwort der echten Route, an 3,75 MiB: abgerundet 3,
gerundet 4, geteilt `3,8`. Im Bruchskript stehen dreizehn neue Eingriffe, und
zwei alte zielen jetzt auf die neue Stelle. Die Auswahl aller Eingriffe in die
berührten Dateien, 42 Abschnitte mit 111 Prüfungen, beisst vollständig. Dass
die Vorlage keine Bedingung trägt, hält kein Wächter.

---

## §7 · Protokoll

### Teil 1 — der Server, am 29. und 30. September 2026 gegen `0.9.0-rc.8`

Gefahren auf `cloudsrv24` nach dem Update auf `0.9.0-rc.8` am 29. September.
Der Nachtlauf dieses Tages lief noch unter `rc.7`, der vom 30. als erster
unter `rc.8`; `rc.8` ändert keinen Schreiber (`docs/138 §7`). Block 2 lief
einen Tag später als ausgeschrieben, am 30., und alles steht deshalb einen Tag
weiter als in §2: Platz und Datenbanken reichen bis zum 30.09., der Verkehr bis
zum 29.09.

#### Die Vorbedingung, am 29. September gegen 17 Uhr

```
0.9.0-rc.8
LastTriggerUSec=Tue 2026-09-29 00:20:01 CEST
Sep 29 00:20:01 cloudsrv24 php[324521]:   Laufender Tag auf dem Server: 2026-09-29 (Europe/Berlin).
Sep 29 00:20:01 cloudsrv24 php[324521]:   3 Tageswert(e) vom Vortag zählbar, 3 ruhig (eine Null), 0 übersprungen (gemischtes Format), 0 nicht ganz gelesen, 1 noch offen (laufender Tag), 5 älter und nicht erneut abgelegt.
Sep 29 00:20:01 cloudsrv24 php[324521]:   Abgelegt: 24 Zeile(n) je Domain, 8 je Abonnement.
Sep 29 00:20:01 cloudsrv24 php[324521]:   Fertig in 62 ms.
Sep 29 17:01:04 cloudsrv24 php[370710]: Verlauf für 2026-09-29 (Europe/Berlin): 3 Abonnement(s) mit Platz, 3 mit Datenbanken.
```

Erfüllt. Die Fassung ist `rc.8`, der Nachtlauf hat um 00:20:01 abgelegt,
darunter drei ruhige Domains mit ihren Nullen, und `srvpanel:usage` hat für
den 29. alle drei Abonnements abgelegt. Platz und Datenbanken haben damit ihren
zweiten Tag.

#### Block 2, am 30. September um 11:01

```
/subscriptions/137  p6-b.invalid   (darunter auf der Seite: Platz 68 MB, Datenbanken —)
  Speicherplatz  68 MB        3 Punkte, 28.09. bis 30.09.
  Traffic        6,0 MB       9 Punkte, 21.09. bis 29.09.  (eingehend 3,6 MB)
  Zugriffe       14.088       9 Punkte, 21.09. bis 29.09.
  Fehlerquote    97 %         9 Punkte, 21.09. bis 29.09.
  Datenbanken    0 MB         3 Punkte, 28.09. bis 30.09.
  /domains/51  p6-b.invalid
    Traffic        0 B          3 Punkte, 27.09. bis 29.09.  (eingehend 0 B)
    Zugriffe       0            3 Punkte, 27.09. bis 29.09.
    Fehlerquote    0,00 %       3 Punkte, 27.09. bis 29.09.
  /domains/55  cloudlab24.de
    Traffic        5,8 MB       9 Punkte, 21.09. bis 29.09.  (eingehend 3,6 MB)
    Zugriffe       13.917       9 Punkte, 21.09. bis 29.09.
    Fehlerquote    97 %         9 Punkte, 21.09. bis 29.09.
  /domains/56  cloudlab24.ipv64.de
    Traffic        108,4 kB     9 Punkte, 21.09. bis 29.09.  (eingehend 47,4 kB)
    Zugriffe       160          9 Punkte, 21.09. bis 29.09.
    Fehlerquote    64 %         9 Punkte, 21.09. bis 29.09.
  /domains/61  domain-mit-richtig-langem-namen.invalid
    Traffic        0 B          3 Punkte, 27.09. bis 29.09.  (eingehend 0 B)
    Zugriffe       0            3 Punkte, 27.09. bis 29.09.
    Fehlerquote    0,00 %       3 Punkte, 27.09. bis 29.09.
  /domains/62  neu.cloudlab24.ipv64.de
    Traffic        7,2 kB       9 Punkte, 21.09. bis 29.09.  (eingehend 3,3 kB)
    Zugriffe       11           9 Punkte, 21.09. bis 29.09.
    Fehlerquote    36 %         9 Punkte, 21.09. bis 29.09.
/subscriptions/140  p6-abnahme.invalid   (darunter auf der Seite: Platz 3 MB, Datenbanken —)
  Speicherplatz  3 MB         3 Punkte, 28.09. bis 30.09.
  Traffic        0 B          3 Punkte, 27.09. bis 29.09.  (eingehend 0 B)
  Zugriffe       0            3 Punkte, 27.09. bis 29.09.
  Fehlerquote    0,00 %       3 Punkte, 27.09. bis 29.09.
  Datenbanken    0 MB         3 Punkte, 28.09. bis 30.09.
  /domains/54  p6-abnahme.invalid
    Traffic        0 B          3 Punkte, 27.09. bis 29.09.  (eingehend 0 B)
    Zugriffe       0            3 Punkte, 27.09. bis 29.09.
    Fehlerquote    0,00 %       3 Punkte, 27.09. bis 29.09.
/subscriptions/141  p6-nochmaltest   (darunter auf der Seite: Platz 0 MB, Datenbanken —)
  Speicherplatz  0 MB         3 Punkte, 28.09. bis 30.09.
  Traffic        —            0 Punkte  (eingehend —)
  Zugriffe       —            0 Punkte
  Fehlerquote    —            0 Punkte
  Datenbanken    0 MB         3 Punkte, 28.09. bis 30.09.
```

- **Die Reihen stehen so da, wie §2 sie verlangt**: fünf Kacheln je
  Abonnement, drei je Domain, in derselben Reihenfolge. Platz und Datenbanken
  haben drei Punkte, 28.09. bis 30.09., der Verkehr endet am 29.09.
- **Sechs Domains.** Drei haben Verkehr und neun Punkte ab dem 21.09., drei
  sind ruhig und haben drei Punkte ab dem 27.09. Das sind die `3 zählbar` und
  `3 ruhig` des Nachtlaufs.
- **Die Zugriffe der Domains ergeben die des Abonnements genau**:
  0 + 13.917 + 160 + 0 + 11 = 14.088. Verkehr und Fehlerquote lassen sich nur
  im Rahmen der Rundung nachrechnen, und dort passen sie.
- **`p6-nochmaltest` hat keine Domain.** Das beantwortet die Frage aus
  `docs/138 §7`, warum es keine Zeile des Verkehrs bekam.
- **Der Server rechnet die Form von `rc.8`**: Zugriffe als ganze Zahl, auch
  `0`; eine Kachel ohne Punkte `—` ohne Einheit, auch `(eingehend —)`; die
  Fehlerquote mit Stellen, `0,00 %`. Speicherplatz gleicht der Klammer, 68, 3
  und 0 MB.
- **Die Klammer der Datenbanken stand bei allen drei auf `—`, die Kachel auf
  `0 MB`.** Angehalten und nachgesehen: Kein Abonnement hat eine Datenbank.
  `databaseUsedMb()` gibt dann `null`, und die Seite zeigt darunter keine
  Zahl, sondern „Keine Datenbanken angelegt.". Die Kachel zeigt `0 MB`, weil
  der Messlauf für ein Abonnement ohne Datenbank eine Null ablegt
  (`docs/138 §5`). „0 MB" über „Keine Datenbanken angelegt." widerspricht sich
  nicht; der Befund steckt in Block 2 und in §3 Punkt 1 (unten).
- Nebenbei: `cloudlab24.de` hatte am 29. 13.917 Zugriffe, 97 % davon mit
  Fehlerstatus. Die Kachel zeigt, was in der Tabelle steht; woher die Anfragen
  kommen, ist nicht Gegenstand dieses Laufs.

#### Der Prüfkörper für Punkt 1, am 30. September um 13:21

Punkt 1 verlangt, dass die Datenbanken oben und unten dieselbe Zahl zeigen, und
er darf nicht ausfallen (§5). Ohne Datenbank steht darunter keine Zahl.
Hergestellt hat den Zustand eine Datenbank `rundung` in `p6-abnahme.invalid`,
im Panel ohne Zugang angelegt und als root gefüllt:

```bash
DB=$(mariadb -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '%\_rundung'")
printf 'Datenbank: %s\n' "${DB:-KEINE GEFUNDEN}"
mariadb "$DB" -e "CREATE TABLE fuellung (b LONGBLOB) ENGINE=MyISAM; INSERT INTO fuellung VALUES (REPEAT('x', 3800000));"
mariadb -N -e "SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = '$DB'"
systemctl start srvpanel-usage.service
journalctl -u srvpanel-usage.service --since '-5min' --no-pager | grep -E 'Verlauf für|scheiterte|nicht lesbar' | tail -1
```

```
Datenbank: p1139_rundung
+---------+
| 3801036 |
+---------+
Sep 30 13:21:54 cloudsrv24 php[428526]: Verlauf für 2026-09-30 (Europe/Berlin): 3 Abonnement(s) mit Platz, 3 mit Datenbanken.
```

- **3.801.036 B sind 3,625 MiB, und die Zahl war vorher gemessen**, im
  Container gegen MariaDB 10.11.14 mit demselben Block. An diesem Wert trennen
  sich die drei Rechnungen: abgerundet 3, gerundet 4, geteilt `3,6`, so wie
  `rc.7` ihn gezeigt hätte, nachgerechnet am alten Rechenweg. Die `0` der drei
  Abonnements trennt keine davon.
- **MyISAM und nicht InnoDB, und auch das ist gemessen.** InnoDB meldete in
  `information_schema` direkt nach dem Einfügen und zwölf Sekunden später
  16.384 B und erst nach `ANALYZE TABLE` 4.734.976 B. MyISAM meldet sofort,
  was in der Datei steht.
- Der Block findet die Datenbank über ihren Namen und nicht über eine
  abgeschriebene Zeile; im Container gegengeprüft ohne Datenbank
  (`KEINE GEFUNDEN`) und neben einem Namen ohne Unterstrich, den er nicht
  trifft. Die Rahmen um die Zahl zeichnet der Klient, wenn er in ein Terminal
  schreibt.
- `srvpanel-usage.service` ist `Type=oneshot`: `systemctl start` wartet, bis der
  Lauf fertig ist, und seine Zeile steht danach im Journal.

Block 2 danach, um 13:22, der Teil von `p6-abnahme.invalid`:

```
/subscriptions/140  p6-abnahme.invalid   (darunter auf der Seite: Platz 3 MB, Datenbanken 3 MB)
  Speicherplatz  3 MB         3 Punkte, 28.09. bis 30.09.
  Traffic        0 B          3 Punkte, 27.09. bis 29.09.  (eingehend 0 B)
  Zugriffe       0            3 Punkte, 27.09. bis 29.09.
  Fehlerquote    0,00 %       3 Punkte, 27.09. bis 29.09.
  Datenbanken    3 MB         3 Punkte, 28.09. bis 30.09.
```

- **Oben und unten `3 MB`.** Das ist die Behebung aus §6 Frage 1, auf dem
  Server gerechnet, an einem Wert, an dem die alte Fassung oben `3,6 MB`
  gezeigt hätte.
- In derselben Ausgabe standen bei `p6-b.invalid` `Platz 69 MB` und
  `Speicherplatz 69 MB`, um 11:01 waren es 68. Ein Messlauf dazwischen hat
  beide zusammen bewegt.
- Alles andere gleicht der Ausgabe von 11:01. Die Klammer der beiden anderen
  Abonnements steht weiter auf `—`, gedruckt mit der Fassung vor der
  Berichtigung.

#### Ein Befund am Prüfmittel und einer an der Erwartung

**Block 2 druckte `—` für zwei Zustände, die die Seite verschieden zeigt.**
„Keine Datenbanken angelegt." und „Noch nicht gemessen." sind auf der Seite
zwei Sätze mit zwei Bedeutungen, ein fertiger Befund und ein ausstehender Lauf,
und in der Klammer derselbe Strich. Berichtigt ist der Block in §2.

> **Eine Anzeige, die zwei verschiedene Zustände gleich aussehen lässt,
> behauptet etwas, das sie nicht weiss.** Der Satz steht seit P5c über die
> Oberfläche in `CLAUDE.md`; hier traf er das Messmittel, das die Oberfläche
> nachrechnen soll.

**Und §3 Punkt 1 hatte den Fall ohne Datenbank nicht bedacht.** „Dieselbe
Zahl wie der Bereich darunter" setzt eine Zahl darunter voraus. Auf
`cloudsrv24` hatte keines der drei Abonnements eine Datenbank. Die Hälfte von
Punkt 1 wäre so nicht gemessen worden, und die Kachel `0 MB` hätte sich wie
ein erfülltes Kriterium gelesen. Die Erwartung in §2 stand ebenso da:
Speicherplatz und Datenbanken „gleichen der Zahl in der Klammer dahinter".
Nachgesehen, was `databaseUsedMb()` ohne Datenbank liefert, hatte beim
Ausschreiben niemand.

**Die Messung im Container hatte dieselbe blinde Stelle** (§2a, Nachtrag):
`beta` und `gamma` haben dort keine Datenbank, und die Tabelle hielt bei `beta`
nur den Platz gegen die Klammer.

> **Ein Kriterium, das eine Zahl darunter verlangt, prüft nichts, wo darunter
> ein Satz steht — und die Kachel darüber liest sich trotzdem wie erfüllt.**

#### Was noch aussteht

- **Teil 2 im Browser**, die Punkte 1 bis 5 aus §3. Erwartung ist Block 2 von
  11:01 und für `p6-abnahme.invalid` die Ausgabe von 13:22. Ändert ein
  Messlauf dazwischen den Platz, ändern sich Kachel und Bereich zusammen.
- **Danach kommt der Prüfkörper wieder weg**: `p1139_rundung` im Panel
  entfernen.
