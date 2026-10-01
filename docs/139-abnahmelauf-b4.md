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

**Gefahren am 29. und 30. September 2026** (§7): Teil 1 auf dem Server,
Teil 2 im Browser, **alle fünf Punkte erfüllt**. Ein Befund am Bild ist mit
`0.9.0-rc.9` behoben (§6b). Der Nachlauf ist am 1. Oktober gefahren (§8), und
**am selben Tag hat der Betreiber B4 abgenommen.** Ein Befund ausserhalb von
B4, der Schriftzug der Leiste im hellen Thema, ist am selben Tag behoben, mit
`0.9.0-rc.10` ausgeliefert und auf dem Server nachgesehen (§9).

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
  ist nicht B4. *Nachgetragen am 30. September:* Die Behebung aus §6b gilt
  dort mit, und der Nachlauf sieht sie sich an, als Beobachtung und nicht als
  Punkt (§8 N2).

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

### §6b · Ein Befund aus Teil 2 — entschieden und gebaut am 30. September 2026

**Der Befund** steht in §7 unter Teil 2: Auf der Abonnementseite brach die
Beizeile der Traffic-Kachel bei 1440 px zwischen Zahl und Einheit, im
Ruhezustand und in beiden Ablesungen. Punkt 4 verlangt „lesbar", und lesbar
war sie. Eine Einheit allein am Anfang einer Zeile liest sich trotzdem wie
eine eigene Angabe.

**Entschieden am 30. September 2026:** beheben, mit `0.9.0-rc.9`. Die Abnahme
kommt danach, nach einem kurzen Nachlauf (§8).

**Gebaut ist es in der Kachel und nicht am Server.** `Tile.vue` fasst jeden
Betrag der Beizeile in ein `<span class="amount">`: Zahl und Einheit der
zweiten Richtung im Ruhezustand, die Ablesung einer Richtung und die zweier
Richtungen. `app.css` hält `.tile-sub .amount` mit `white-space: nowrap` in
einer Zeile, und zwischen den Wörtern davor bricht die Zeile weiter. Ein
geschütztes Leerzeichen hätte der Server in jede Stützstelle schreiben müssen,
auch in die für die Vorlesesoftware. Die Kachel formatiert nichts
(`SeriesSourceTest`), sie fasst nur ein.

**Gemessen im Container gegen das gebaute Stylesheet**, mit dem Raster aus
`PanelLayout` und den Zahlen des Servers, alter Stand gegen neuen und mit
derselben Messvorschrift. Ein Schrägstrich trennt die Zeilen:

| Seite bei 1440 px | Zustand | vorher | nachher |
|---|---|---|---|
| Abonnement | Ruhe | `ausgehend · eingehend 3,6` / `MB` | `ausgehend · eingehend` / `3,6 MB` |
| Abonnement | oben | `29.09. · ausgehend 6,0` / `MB` | `29.09. · ausgehend` / `6,0 MB` |
| Abonnement | unten | `29.09. · eingehend 3,6` / `MB` | `29.09. · eingehend` / `3,6 MB` |
| Übersicht, gebaute Werte | Ruhe | `eingehend · ausgehend 1,2` / `MB/s` | `eingehend · ausgehend` / `1,2 MB/s` |
| Übersicht, gebaute Werte | oben | `09:26 · ausgehend 1,2` / `MB/s` | `09:26 · ausgehend` / `1,2 MB/s` |
| Übersicht, gebaute Werte | unten | `09:26 · eingehend 12,9` / `kB/s` | `09:26 · eingehend` / `12,9 kB/s` |

- **Die Kacheln bleiben gleich hoch**, vorher wie nachher: auf der
  Abonnementseite 228 × 196 px bei 1440 und 358 × 193 bei 390, auf der
  Domainseite 380 × 196 und 358 × 192. Dort stand die Zeile schon vorher in
  einer Zeile, und so bleibt es.
- **Die Übersicht ist nicht B4** (§4), trägt aber dieselbe Kachel. Gemessen ist
  sie mit gebauten Werten in der Form, die `OverviewController` schreibt; auf
  dem Server hat sie bei 1440 px niemand angesehen.
- **Die erste Fassung der Messvorschrift las nur die Textknoten direkt unter
  der Beizeile.** Den Betrag im `<span>` hätte sie nicht gesehen. Die zweite
  liest alle, und gegen den alten Stand gefahren gab sie Zeile für Zeile
  dasselbe wie die erste.

**Und das `nowrap` passt in die schmalste Kachel.** Oberhalb von 720 px ist
eine Kachel mindestens `--kachel-min` breit, in der Verwaltung 200 px. Bei
1301 px Fensterbreite stehen fünf davon zu je 200 px in der Reihe, und die
Beizeile hat 151 px. Eingesetzt in die Einfassung der Traffic-Kachel:

| Betrag | breit | Kachel vorher | Kachel nachher |
|---|---|---|---|
| `3,6 MB` | 45 px | 196 px | 196 px |
| `999,9 GB/s` | 71,5 px | 196 px | 196 px |
| `1.048.576 MB` | 90,4 px | 216 px, Betrag getrennt | 216 px |
| `1.000.000.000` | 95,1 px | 216 px | 216 px |
| `1.000.000,0 GB` | 101,7 px | 216 px | 216 px |
| Gegenprobe, 40 Zeichen | 257,6 px | 236 px, 72 px über | 216 px, 106 px über |

Die drei langen Beträge brauchen neben „ausgehend · eingehend" eine dritte
Zeile, vorher wie nachher; das `nowrap` entscheidet nur, wo sie bricht. Einen
Betrag über 1000 GB schreibt `Points` nicht, und `1.000.000,0 GB` wäre ein
Petabyte an einem Tag. Die Gegenprobe läuft über und belegt, dass die Messung
einen Überstand sieht. `dokument` bliebe dabei 0, denn der Überstand landet in
der Nachbarkachel.

**Gehalten wird es von `TileAmountTest`.** Jede Einbettung mit einem Wert
steht in einer Einfassung, Zahl und Einheit der zweiten Richtung stehen in
derselben, und `app.css` hält den Betrag in einer Zeile, ohne dass eine zweite
Regel es zurücknimmt, auch nicht im Stilblock der Kachel. Im Bruchskript
stehen acht neue Eingriffe; einer davon versteckt die Einfassung in einem
Kommentar. Die Auswahl aller Eingriffe in die berührten Dateien, 75 Abschnitte
mit 172 Prüfungen, beisst vollständig, und alle 1802 Python-Eingriffe laufen
trocken durch.

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

#### Was nach Teil 1 noch ausstand

- **Teil 2 im Browser**, die Punkte 1 bis 5 aus §3. Erwartung ist Block 2 von
  11:01 und für `p6-abnahme.invalid` die Ausgabe von 13:22. Ändert ein
  Messlauf dazwischen den Platz, ändern sich Kachel und Bereich zusammen.
- **Danach kommt der Prüfkörper wieder weg**: `p1139_rundung` im Panel
  entfernen.

Teil 2 ist am selben Abend gefahren, gleich unten. Den Prüfkörper braucht auch
der Nachlauf nicht mehr (§8).

### Teil 2 — der Browser, am 30. September 2026 gegen `0.9.0-rc.8`

Gefahren in Chrome auf dem Rechner des Betreibers, ab 19:46. Die Seiten
zeigten im Bereich Speicher „Gemessen am 2026-09-30 19:45:52", bei Punkt 4
„20:01:12". Punkt 1 bis 3 liefen bei 1440 px im dunklen Thema. Die Ausgaben
stehen wörtlich da.

#### Punkt 1 — die drei Abonnementseiten

`/subscriptions/137`, darunter auf der Seite: Speicher „70 MB von 5.120 MB",
Datenbanken „Keine Datenbanken angelegt.".

```
stand=2026-09-28 /subscriptions/137  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=5
  Speicherplatz  70 MB        3 Punkte  Kurve  -      196 px  | belegt
  Traffic        6,0 MB       9 Punkte  Kurve  -      196 px  | ausgehend · eingehend 3,6 MB
  Zugriffe       14.088       9 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    97 %         9 Punkte  Kurve  -      196 px  | 4xx und 5xx
  Datenbanken    0 MB         3 Punkte  Kurve  -      196 px  | belegt
```

`/subscriptions/140`, darunter: Speicher „3 MB von 5.120 MB", Datenbanken
„3 MB ohne Grenze" mit dem Hinweis, dass die Grenze gemessen und nicht
erzwungen wird.

```
stand=2026-09-28 /subscriptions/140  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=5
  Speicherplatz  3 MB         3 Punkte  Kurve  -      196 px  | belegt
  Traffic        0 B          3 Punkte  Kurve  -      196 px  | ausgehend · eingehend 0 B
  Zugriffe       0            3 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    0,00 %       3 Punkte  Kurve  -      196 px  | 4xx und 5xx
  Datenbanken    3 MB         3 Punkte  Kurve  -      196 px  | belegt
```

`/subscriptions/141`, darunter: Speicher „0 MB von 5.120 MB", Datenbanken
„Keine Datenbanken angelegt."; im Bild „noch keine Messwerte" in den drei
leeren Kacheln.

```
stand=2026-09-28 /subscriptions/141  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=5
  Speicherplatz  0 MB         3 Punkte  Kurve  -      196 px  | belegt
  Traffic        —            0 Punkte  leer   -      196 px  | ausgehend · eingehend —
  Zugriffe       —            0 Punkte  leer   -      196 px  | Anfragen
  Fehlerquote    —            0 Punkte  leer   -      196 px  | 4xx und 5xx
  Datenbanken    0 MB         3 Punkte  Kurve  -      196 px  | belegt
```

**Erfüllt.** `kacheln=5` und `reihe=flex` auf allen drei Seiten. Jede Zeile
trägt Namen, Zahl, Einheit und Punkte von Block 2, in derselben Reihenfolge.
Drei und neun Punkte heissen `Kurve`, null Punkte `leer`, keine Kachel heisst
`UNKLAR`, keine warnt.

- **Speicherplatz gleicht dem Bereich darunter**: 70 und 70, 3 und 3, 0 und 0.
  Auf `/137` sind es seit 13:22 ein MB mehr, oben und unten zusammen.
- **Die Datenbanken des Prüfkörpers stehen oben und unten mit `3 MB` da.** Das
  ist die Behebung aus §6 Frage 1 im Browser; `rc.7` hätte oben `3,6 MB`
  gezeigt. Auf `/137` und `/141` steht `0 MB` über „Keine Datenbanken
  angelegt.", und dort wird nichts verglichen (§3 Punkt 1).
- **Die Form von `rc.8`**: `14.088`, `0`, `0,00 %`, `97 %`, `—` ohne Einheit
  und „eingehend —".
- Nebenbei zu Punkt 5: Bei 1440 px sind die drei leeren Kacheln auf `/141`
  196 px hoch wie ihre Nachbarn, die Reihe 197 px.

#### Punkt 3 — die Ablesung auf `/subscriptions/137`

In derselben geladenen Seite nach `kachelnMessen()`:

```
stand=2026-09-28 /subscriptions/137  Ablesung am ersten und am letzten Punkt
  Speicherplatz  28.09. · 69 MB  …  30.09. · 70 MB  | danach: belegt
  Traffic        21.09. · ausgehend 0,6 MB  …  29.09. · ausgehend 6,0 MB  | danach: ausgehend · eingehend 3,6 MB
  Zugriffe       21.09. · 1.367  …  29.09. · 14.088  | danach: Anfragen
  Fehlerquote    21.09. · 96 %  …  29.09. · 97 %  | danach: 4xx und 5xx
  Datenbanken    28.09. · 0 MB  …  30.09. · 0 MB  | danach: belegt
```

**Erfüllt.** Zwei Ablesungen je Kurve, jede mit Tag und Wert. Der letzte Punkt
ist bei Platz und Datenbanken der 30.09., beim Verkehr der 29.09. Der erste ist
der älteste Tag der Tabelle, 28.09. und 21.09. Danach steht jede Beizeile
wieder im Ruhezustand. Auch die Ablesung trägt die Form von `rc.8`: `69 MB`,
`1.367`, `96 %`.

#### Punkt 2 — die sechs Domainseiten

Jede der sechs Ausgaben endet mit derselben Zeile:

```
  darunter: Gezählt wird, was der Webserver protokolliert. Die Zahl des Providers liegt höher — er zählt TCP, TLS und Wiederholungen mit.
```

```
stand=2026-09-28 /domains/51  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=3
  Traffic        0 B          3 Punkte  Kurve  -      196 px  | ausgehend · eingehend 0 B
  Zugriffe       0            3 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    0,00 %       3 Punkte  Kurve  -      196 px  | 4xx und 5xx
stand=2026-09-28 /domains/54  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=3
  Traffic        0 B          3 Punkte  Kurve  -      196 px  | ausgehend · eingehend 0 B
  Zugriffe       0            3 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    0,00 %       3 Punkte  Kurve  -      196 px  | 4xx und 5xx
stand=2026-09-28 /domains/55  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=3
  Traffic        5,8 MB       9 Punkte  Kurve  -      196 px  | ausgehend · eingehend 3,6 MB
  Zugriffe       13.917       9 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    97 %         9 Punkte  Kurve  -      196 px  | 4xx und 5xx
stand=2026-09-28 /domains/56  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=3
  Traffic        108,4 kB     9 Punkte  Kurve  -      196 px  | ausgehend · eingehend 47,4 kB
  Zugriffe       160          9 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    64 %         9 Punkte  Kurve  -      196 px  | 4xx und 5xx
stand=2026-09-28 /domains/61  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=3
  Traffic        0 B          3 Punkte  Kurve  -      196 px  | ausgehend · eingehend 0 B
  Zugriffe       0            3 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    0,00 %       3 Punkte  Kurve  -      196 px  | 4xx und 5xx
stand=2026-09-28 /domains/62  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=3
  Traffic        7,2 kB       9 Punkte  Kurve  -      196 px  | ausgehend · eingehend 3,3 kB
  Zugriffe       11           9 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    36 %         9 Punkte  Kurve  -      196 px  | 4xx und 5xx
```

**Erfüllt.** `kacheln=3` und `reihe=flex` auf allen sechs, jede Zeile wie
Block 2 von 11:01. Die Zeile „darunter" steht auf jeder Domainseite und auf
keiner Abonnementseite. Jede Kachel ist 196 px hoch, die Reihe 197 px.

#### Punkt 4 — acht Lagen auf zwei Seiten

`/subscriptions/137`, gegen 20:01:

```
stand=2026-09-28 /subscriptions/137  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=5
  Speicherplatz  70 MB        3 Punkte  Kurve  -      196 px  | belegt
  Traffic        6,0 MB       9 Punkte  Kurve  -      196 px  | ausgehend · eingehend 3,6 MB
  Zugriffe       14.088       9 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    97 %         9 Punkte  Kurve  -      196 px  | 4xx und 5xx
  Datenbanken    0 MB         3 Punkte  Kurve  -      196 px  | belegt
stand=2026-09-06 breite=1440 thema=dark dokument=0 gegenprobe=200 (soll 200) schiebt=0 rollt=0 versteckt=0

stand=2026-09-28 /subscriptions/137  breite=390  thema=dark  reihe=flex  höhe=885 px  kacheln=5
  Speicherplatz  70 MB        3 Punkte  Kurve  -      172 px  | belegt
  Traffic        6,0 MB       9 Punkte  Kurve  -      193 px  | ausgehend · eingehend 3,6 MB
  Zugriffe       14.088       9 Punkte  Kurve  -      173 px  | Anfragen
  Fehlerquote    97 %         9 Punkte  Kurve  -      173 px  | 4xx und 5xx
  Datenbanken    0 MB         3 Punkte  Kurve  -      173 px  | belegt
stand=2026-09-06 breite=390 thema=dark dokument=0 gegenprobe=200 (soll 200) schiebt=0 rollt=0 versteckt=4

stand=2026-09-28 /subscriptions/137  breite=390  thema=light  reihe=flex  höhe=885 px  kacheln=5
  Speicherplatz  70 MB        3 Punkte  Kurve  -      172 px  | belegt
  Traffic        6,0 MB       9 Punkte  Kurve  -      193 px  | ausgehend · eingehend 3,6 MB
  Zugriffe       14.088       9 Punkte  Kurve  -      173 px  | Anfragen
  Fehlerquote    97 %         9 Punkte  Kurve  -      173 px  | 4xx und 5xx
  Datenbanken    0 MB         3 Punkte  Kurve  -      173 px  | belegt
stand=2026-09-06 breite=390 thema=light dokument=0 gegenprobe=200 (soll 200) schiebt=0 rollt=0 versteckt=4

stand=2026-09-28 /subscriptions/137  breite=1440  thema=light  reihe=flex  höhe=197 px  kacheln=5
  Speicherplatz  70 MB        3 Punkte  Kurve  -      196 px  | belegt
  Traffic        6,0 MB       9 Punkte  Kurve  -      196 px  | ausgehend · eingehend 3,6 MB
  Zugriffe       14.088       9 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    97 %         9 Punkte  Kurve  -      196 px  | 4xx und 5xx
  Datenbanken    0 MB         3 Punkte  Kurve  -      196 px  | belegt
stand=2026-09-06 breite=1440 thema=light dokument=0 gegenprobe=200 (soll 200) schiebt=0 rollt=0 versteckt=0
```

`/domains/55`; jede der vier Ausgaben von `kachelnMessen()` endet mit der
Zeile „darunter" aus Punkt 2:

```
stand=2026-09-28 /domains/55  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=3
  Traffic        5,8 MB       9 Punkte  Kurve  -      196 px  | ausgehend · eingehend 3,6 MB
  Zugriffe       13.917       9 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    97 %         9 Punkte  Kurve  -      196 px  | 4xx und 5xx
stand=2026-09-06 breite=1440 thema=dark dokument=0 gegenprobe=200 (soll 200) schiebt=0 rollt=0 versteckt=0

stand=2026-09-28 /domains/55  breite=390  thema=dark  reihe=flex  höhe=539 px  kacheln=3
  Traffic        5,8 MB       9 Punkte  Kurve  -      192 px  | ausgehend · eingehend 3,6 MB
  Zugriffe       13.917       9 Punkte  Kurve  -      173 px  | Anfragen
  Fehlerquote    97 %         9 Punkte  Kurve  -      173 px  | 4xx und 5xx
stand=2026-09-06 breite=390 thema=dark dokument=0 gegenprobe=200 (soll 200) schiebt=0 rollt=0 versteckt=4

stand=2026-09-28 /domains/55  breite=390  thema=light  reihe=flex  höhe=539 px  kacheln=3
  Traffic        5,8 MB       9 Punkte  Kurve  -      192 px  | ausgehend · eingehend 3,6 MB
  Zugriffe       13.917       9 Punkte  Kurve  -      173 px  | Anfragen
  Fehlerquote    97 %         9 Punkte  Kurve  -      173 px  | 4xx und 5xx
stand=2026-09-06 breite=390 thema=light dokument=0 gegenprobe=200 (soll 200) schiebt=0 rollt=0 versteckt=4

stand=2026-09-28 /domains/55  breite=1440  thema=light  reihe=flex  höhe=197 px  kacheln=3
  Traffic        5,8 MB       9 Punkte  Kurve  -      196 px  | ausgehend · eingehend 3,6 MB
  Zugriffe       13.917       9 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote    97 %         9 Punkte  Kurve  -      196 px  | 4xx und 5xx
stand=2026-09-06 breite=1440 thema=light dokument=0 gegenprobe=200 (soll 200) schiebt=0 rollt=0 versteckt=0
```

**Erfüllt.** In allen acht Lagen `dokument=0`, `gegenprobe=200 (soll 200)`,
`schiebt=0`. Dass jede Lage ihre eigene geladene Seite hatte, steht in den
Zahlen: Beide Messmittel werfen beim zweiten Aufruf.

- **Die Höhen treffen den Container.** Bei 390 px sind die Kacheln auf `/137`
  172, 193, 173, 173 und 173 px hoch, aufs Pixel die Werte aus §2a. Die Reihe
  misst 885 statt 886 px, auf `/domains/55` 539 statt 540. Bei 1440 px ist
  jede Kachel 196 px hoch.
- **`versteckt=4` bei 390 px und 0 bei 1440** sind Kästen für die
  Vorlesesoftware, geklippt auf einen Bildpunkt. Gezählt und kein Fund;
  `docs/903` hat bei 390 px dasselbe Muster.
- **Die Bilder:** Zahl, Einheit und Beizeile jeder Kachel sind in beiden Themen
  lesbar, der Verkehr trägt zwei Linien, die eingehende gestrichelt. Keine
  Kachel ist abgeschnitten. Bei 390 px lag die Reihe zuerst nur halb im Bild;
  nachgeliefert sind ganze Seiten in beiden Themen, und darin stehen alle fünf
  Kacheln ganz.
- **Auf dem Bild der Abonnementseite bei 1440 px** stand ein Befund; er folgt
  unten.

#### Punkt 5 — die leeren Kacheln bei 390 px

```
stand=2026-09-28 /subscriptions/141  breite=390  thema=dark  reihe=flex  höhe=885 px  kacheln=5
  Speicherplatz  0 MB         3 Punkte  Kurve  -      172 px  | belegt
  Traffic        —            0 Punkte  leer   -      193 px  | ausgehend · eingehend —
  Zugriffe       —            0 Punkte  leer   -      173 px  | Anfragen
  Fehlerquote    —            0 Punkte  leer   -      173 px  | 4xx und 5xx
  Datenbanken    0 MB         3 Punkte  Kurve  -      173 px  | belegt
stand=2026-09-06 breite=390 thema=dark dokument=0 gegenprobe=200 (soll 200) schiebt=0 rollt=0 versteckt=4
```

**Erfüllt.** Die drei leeren Kacheln heissen `leer`, zeigen `—` ohne Einheit
und im Bild „noch keine Messwerte". Jede ist so hoch wie die Kachel an
derselben Stelle auf `/137`, 172, 193, 173, 173 und 173 px, die Reihe 885 px
wie dort. Bei 1440 px waren es 196 px wie die Nachbarn (Punkt 1). Die Reihe
steht, auch hier unter „Noch keine Domain.".

#### Der Befund am Bild

**Auf der Abonnementseite brach bei 1440 px die Beizeile der Traffic-Kachel
zwischen Zahl und Einheit**: oben „ausgehend · eingehend 3,6", darunter „MB".
So stand es im Ruhezustand in beiden Themen und schon bei Punkt 1. Auf der
Domainseite mit ihren drei breiteren Kacheln und bei 390 px stand die Zeile in
einer Zeile.

- **Zweizeilig ist sie gewollt.** `.tile-sub.paired` hält bei 20 px
  Zeilenhöhe zwei Zeilen frei; deshalb ist die Traffic-Kachel bei 390 px
  193 px hoch und die anderen 173.
- **Falsch war die Stelle.** Zwischen `{{ second.value }}` und
  `{{ second.unit }}` stand ein gewöhnliches Leerzeichen, und die Zeile brach
  am letzten, das noch passte.
- **Im Container war das vorher nicht zu sehen.** Der Aufsatz hatte keine
  Seitenleiste, und die Kacheln waren bei 1440 px 280 px breit statt 228. Mit
  dem Raster aus `PanelLayout` misst er seitdem 228 × 196 px wie der Server
  und zeigt den Umbruch an genau dieser Stelle (§6b).

> **Ein Prüfkörper, der eine andere Form misst als die des Prüflings, misst
> die falsche — und sein Grün liest sich wie ein Freispruch.** Bei Kacheln
> gehört der Rahmen zur Form, denn er entscheidet ihre Breite.

Punkt 4 ist damit trotzdem erfüllt, die Zeile war lesbar. Der Betreiber hat
entschieden, dass es behoben wird und die Abnahme danach kommt (§6b).

#### Eine Meldung in der Konsole, ungeklärt

In den beiden dunklen Aufnahmen von `/subscriptions/137` stand oben in der
Konsole „⊗1", ein Fehler, dessen Text nicht im Bild war. In den übrigen sechs
Aufnahmen stand keiner, und im Container liess er sich nicht herstellen. Er
bleibt ungeklärt.

#### Was noch aussteht

- **Der Nachlauf gegen `0.9.0-rc.9`** (§8). Danach spricht der Betreiber die
  Abnahme aus.
- **Der Prüfkörper kommt weg**: `p1139_rundung` in `p6-abnahme.invalid` im
  Panel entfernen. Der Nachlauf braucht ihn nicht.

**Beides ist am 1. Oktober 2026 erledigt** (§8).

---

## §8 · Der Nachlauf gegen `0.9.0-rc.9`

Ausgeschrieben am 30. September 2026, vor dem Fahren. Er misst die Behebung
aus §6b auf dem Server und ist klein, weil sie klein ist: Geändert hat sich die
Beizeile einer Kachel und sonst nichts.

**Vorbedingung:** `srvpanel version` meldet `0.9.0-rc.9`.

### N1 — `/subscriptions/137` bei 1440 px, dunkel und hell

Je Thema die Seite frisch laden, `tests/kacheln-messen.js` einfügen und
`kachelnMessen()` rufen. Danach diesen Block einfügen; er ruft sich selbst:

```js
async function betragMessen () {
  const warten = () => new Promise((fertig) => setTimeout(fertig, 60))
  const zeilen = (sub) => {
    const reihen = new Map()
    const gang = document.createTreeWalker(sub, NodeFilter.SHOW_TEXT)
    for (let n = gang.nextNode(); n; n = gang.nextNode()) {
      for (let i = 0; i < n.textContent.length; i++) {
        const r = document.createRange(); r.setStart(n, i); r.setEnd(n, i + 1)
        const k = [...r.getClientRects()].find((x) => x.width > 0)
        if (k) reihen.set(Math.round(k.top), (reihen.get(Math.round(k.top)) ?? '') + n.textContent[i])
      }
    }
    return [...reihen.entries()].sort((a, b) => a[0] - b[0]).map(([, t]) => t.replace(/\s+/g, ' ').trim()).join(' / ')
  }
  const aus = [`${location.pathname}  breite=${document.documentElement.clientWidth}  thema=${document.documentElement.dataset.theme}`]
  for (const kachel of document.querySelectorAll('.tiles > .tile')) {
    const sub = kachel.querySelector('.tile-sub.paired')
    const feld = kachel.querySelector('.trend svg')
    if (!sub) continue
    const name = kachel.querySelector('.tile-label').textContent.trim()
    const lesen = (wo) => {
      const betrag = sub.querySelector('.amount')
      const reihen = betrag ? new Set([...betrag.getClientRects()].map((x) => Math.round(x.top))).size : '-'
      aus.push(`  ${name.padEnd(8)} ${wo.padEnd(6)} einfassung=${sub.querySelectorAll('.amount').length}  betrag in ${reihen} Zeile(n)  kachel ${Math.round(kachel.getBoundingClientRect().height)} px  | ${zeilen(sub)}`)
    }
    lesen('ruhe')
    if (!feld) continue
    for (const wo of ['oben', 'unten']) {
      const r = feld.getBoundingClientRect()
      feld.dispatchEvent(new PointerEvent('pointermove', { clientX: r.right - 1, clientY: wo === 'oben' ? r.top + 1 : r.bottom - 1, bubbles: true }))
      await warten()
      lesen(wo)
    }
    feld.dispatchEvent(new PointerEvent('pointerleave'))
    await warten()
    lesen('danach')
  }
  console.log(aus.join('\n'))
}
betragMessen()
```

**Erwartet:**

- `kachelnMessen()` wie in Teil 2: fünf Kacheln, jede 196 px hoch. Die Zahlen
  dürfen sich seit dem 30. September bewegt haben.
- `betragMessen()` gibt für `Traffic` vier Zeilen aus: `ruhe`, `oben`,
  `unten` und `danach`. In jeder steht `einfassung=1`, `betrag in 1 Zeile(n)`
  und `kachel 196 px`. Ein Schrägstrich trennt die Zeilen der Beizeile; nach
  dem letzten steht der ganze Betrag, Zahl und Einheit. Eine Zeile aus einer
  Einheit allein gibt es nicht.
- **`einfassung=0` heisst: Im Browser läuft noch `rc.8`.** Dann mit geleertem
  Zwischenspeicher neu laden und von vorn.
- Ein Bild der Kachelreihe im Ruhezustand und eines mit dem Zeiger auf der
  Traffic-Kurve.

So sah es im Container aus, mit den Zahlen vom 30. September (§6b). Der Kopf
jeder Ausgabe nennt Pfad, Breite und Thema:

```
  Traffic  ruhe   einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  | ausgehend · eingehend / 3,6 MB
  Traffic  oben   einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  | 29.09. · ausgehend / 6,0 MB
  Traffic  unten  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  | 29.09. · eingehend / 3,6 MB
  Traffic  danach einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  | ausgehend · eingehend / 3,6 MB
```

Und gegen den Stand von `rc.8` gefahren, dieselbe Messung:

```
  Traffic  ruhe   einfassung=0  betrag in - Zeile(n)  kachel 196 px  | ausgehend · eingehend 3,6 / MB
  Traffic  oben   einfassung=0  betrag in - Zeile(n)  kachel 196 px  | 29.09. · ausgehend 6,0 / MB
  Traffic  unten  einfassung=0  betrag in - Zeile(n)  kachel 196 px  | 29.09. · eingehend 3,6 / MB
  Traffic  danach einfassung=0  betrag in - Zeile(n)  kachel 196 px  | ausgehend · eingehend 3,6 / MB
```

### N2 — die Übersicht bei 1440 px, eine Beobachtung

Nicht B4 (§4), aber dieselbe Kachel. Auf `/` denselben Block einfügen; er
misst dort die Netzkachel. Erwartet ist dasselbe Bild: `einfassung=1` und der
Betrag in einer Zeile. Ein Ausfall hier hält die Abnahme von B4 nicht auf, er
wäre ein Befund an der Übersicht.

### Wann er durch ist

N1 ist in beiden Themen erfüllt. Die Abnahme von B4 spricht danach der
Betreiber aus.

### N1a — die Bedingung herstellen, ergänzt beim Fahren am 1. Oktober 2026

N1 lief am 1. Oktober zuerst so, wie es dasteht, im dunklen Thema bei 1440 px.
Jede Zeile der Erwartung traf zu bis auf eine, und das war die tragende: **Es
stand kein Schrägstrich da.** Die ganze Beizeile passte in eine Zeile, und
dann sagt `betrag in 1 Zeile(n)` nichts über die Behebung. Eine Zeile, die gar
nicht bricht, bricht auch nicht an der falschen Stelle.

**Die Erwartung setzte die Schrift von Teil 2 voraus.** Die Beizeile steht in
`--font-sans`, also in `system-ui`, und welche Schrift das ist, entscheidet der
Rechner, der die Seite anzeigt. Teil 2 lief nach den Bildern auf einem Mac,
der Nachlauf auf Windows mit Segoe UI; gemessen ist nur das zweite. Die Kachel
ist dieselbe, die Beizeile 179 px breit wie im Container. Schmaler ist der
Text: Die Ruhezeile misst auf Windows 177,1 px, im Container 202,3 px.

N1a druckt deshalb mit, wovon das Ergebnis abhängt:

- **Die Breite der Beizeile und die Schrift.** Bestimmt wird die Schrift über
  eine Leinwand: Sie misst denselben Satz in `system-ui` und in einer Reihe
  benannter Schriften, und eine Schrift, die es nicht gibt, fällt auf die
  Vorgabe zurück und misst anders. `navigator.userAgentData` taugt dafür nicht,
  denn die Geräteleiste gibt sich als Telefon aus und meldet `Android`.
- **Je Zustand die Breite der Zeile**, ganz und bis zur Zahl.
- **Eine Gegenprobe auf derselben Seite:** dieselbe Messung mit
  `white-space: normal` am Betrag, also so, wie `rc.8` bricht. Danach nimmt das
  Snippet die Regel zurück und misst die Ruhezeile noch einmal.
- **Die Fensterbreiten, bei denen ohne die Regel Zahl und Einheit getrennt
  würden**, für die Ruhezeile, für die beiden Ablesungen und für alle drei.
  Jede Kachel der Reihe nimmt ein Fünftel einer Änderung der Fensterbreite,
  bis eine bei ihrer Grundbreite von 200 px ankommt.

Gegengeprüft im Container, bevor der Betreiber es fuhr: Vorhergesagt waren für
die Ruhezeile 1435 bis 1556 px. Ohne die Regel brach sie bei 1430 px vor der
Zahl, bei 1500 px zwischen Zahl und Einheit und bei 1560 px gar nicht.

```js
async function umbruchMessen () {
  const warten = () => new Promise((fertig) => setTimeout(fertig, 60))
  const zeilen = (sub) => {
    const reihen = new Map()
    const gang = document.createTreeWalker(sub, NodeFilter.SHOW_TEXT)
    for (let n = gang.nextNode(); n; n = gang.nextNode()) {
      for (let i = 0; i < n.textContent.length; i++) {
        const r = document.createRange(); r.setStart(n, i); r.setEnd(n, i + 1)
        const k = [...r.getClientRects()].find((x) => x.width > 0)
        if (k) reihen.set(Math.round(k.top), (reihen.get(Math.round(k.top)) ?? '') + n.textContent[i])
      }
    }
    return [...reihen.entries()].sort((a, b) => a[0] - b[0]).map(([, t]) => t.replace(/\s+/g, ' ').trim()).join(' / ')
  }
  const kachel = [...document.querySelectorAll('.tiles > .tile')].find((k) => k.querySelector('.tile-sub.paired .amount'))
  if (!kachel) throw new Error('Keine Kachel mit Einfassung in der Beizeile.')
  const sub = kachel.querySelector('.tile-sub.paired')
  const feld = kachel.querySelector('.trend svg')
  const name = kachel.querySelector('.tile-label').textContent.trim()
  // Wie breit ein Text in der Schrift der Beizeile ohne Umbruch ist: ein unsichtbarer Klon derselben Zeile.
  const breit = (text) => {
    const klon = sub.cloneNode(false)
    klon.textContent = text
    Object.assign(klon.style, { position: 'absolute', visibility: 'hidden', whiteSpace: 'nowrap', width: 'max-content', minHeight: '0' })
    kachel.appendChild(klon)
    const b = klon.getBoundingClientRect().width
    klon.remove()
    return b
  }
  const fenster = document.documentElement.clientWidth
  const beizeile = sub.getBoundingClientRect().width
  // Welche Schrift `system-ui` hier ist: Die Geräteleiste gibt sich als Telefon aus, die Schrift kommt
  // trotzdem vom Rechner. Eine Familie, die es nicht gibt, fällt auf die Vorgabe zurück und misst anders.
  const leinwand = document.createElement('canvas').getContext('2d')
  const probe = (familie) => { leinwand.font = `13px ${familie}`; return leinwand.measureText('ausgehend · eingehend 3,4 MB').width }
  const kandidaten = ['Segoe UI', 'SF Pro Text', 'Helvetica Neue', 'Roboto', 'Noto Sans', 'DejaVu Sans', 'Ubuntu', 'Arial']
  const schrift = kandidaten.filter((k) => Math.abs(probe(`"${k}"`) - probe('system-ui')) < 0.01).join('/') || '?'
  const aus = [`${location.pathname}  breite=${fenster}  thema=${document.documentElement.dataset.theme}  schrift=${schrift} (${probe('system-ui').toFixed(1)}, Vorgabe ${probe('serif').toFixed(1)})  beizeile=${beizeile.toFixed(1)} px`]
  const lesen = (wo) => {
    const text = sub.textContent.replace(/\s+/g, ' ').trim()
    const voll = breit(text)
    const zahl = breit(text.slice(0, text.lastIndexOf(' ')))
    const betrag = sub.querySelector('.amount')
    const reihen = new Set([...betrag.getClientRects()].map((x) => Math.round(x.top))).size
    aus.push(`  ${name.padEnd(8)} ${wo.padEnd(6)} ${getComputedStyle(betrag).whiteSpace.padEnd(6)}  einfassung=${sub.querySelectorAll('.amount').length}  betrag in ${reihen} Zeile(n)  kachel ${Math.round(kachel.getBoundingClientRect().height)} px  ganz ${voll.toFixed(1)}  bis zur Zahl ${zahl.toFixed(1)}  | ${zeilen(sub)}`)
    return { voll, zahl }
  }
  const runde = async () => {
    const masse = [lesen('ruhe')]
    if (!feld) return masse
    for (const wo of ['oben', 'unten']) {
      const r = feld.getBoundingClientRect()
      feld.dispatchEvent(new PointerEvent('pointermove', { clientX: r.right - 1, clientY: wo === 'oben' ? r.top + 1 : r.bottom - 1, bubbles: true }))
      await warten()
      masse.push(lesen(wo))
    }
    feld.dispatchEvent(new PointerEvent('pointerleave'))
    await warten()
    lesen('danach')
    return masse
  }
  const masse = await runde()
  // Gegenprobe: dieselbe Seite ohne das nowrap am Betrag, danach wieder mit.
  const gegen = document.createElement('style')
  gegen.textContent = '.tile-sub .amount { white-space: normal }'
  document.head.appendChild(gegen)
  aus.push('  Gegenprobe ohne nowrap:')
  await runde()
  gegen.remove()
  await warten()
  aus.push('  wieder mit nowrap:')
  lesen('ruhe')
  // Ohne nowrap bricht eine Zeile zwischen Zahl und Einheit, wenn die Beizeile breiter ist als der
  // Text bis zur Zahl und schmaler als der ganze. Jede Kachel der Reihe nimmt denselben Teil einer
  // Änderung der Fensterbreite, bis eine bei ihrer Grundbreite ankommt und die Reihe umbricht.
  const reihe = [...kachel.parentElement.children].filter((k) => k.offsetTop === kachel.offsetTop).length
  const rand = kachel.getBoundingClientRect().width - beizeile
  const unten = parseFloat(getComputedStyle(kachel).flexBasis) - rand
  const w = (s) => fenster + reihe * (s - beizeile)
  const spanne = (liste) => {
    const von = Math.max(unten, ...liste.map((m) => m.zahl))
    const bis = Math.min(...liste.map((m) => m.voll))
    return bis > von ? `${Math.ceil(w(von))} bis ${Math.ceil(w(bis)) - 1} px, Mitte ${Math.round(w((von + bis) / 2))} px` : 'keine'
  }
  aus.push(`  Bruch zwischen Zahl und Einheit ohne nowrap — Ruhe: ${spanne(masse.slice(0, 1))} · Ablesung: ${spanne(masse.slice(1))} · alle drei: ${spanne(masse)}`)
  console.log(aus.join('\n'))
}
umbruchMessen()
```

Auf `/subscriptions/137` lief eine frühere Fassung. Sie druckte statt der
Schrift `plattform=`, rundete die Mitte auf zehn Pixel und kannte die Spanne
der Ablesungen nicht; die Messwerte sind dieselben. Auf `/` lief die Fassung,
die hier steht.

### Protokoll — gefahren am 1. Oktober 2026 gegen `0.9.0-rc.9`

**Vorbedingung:** Das Update am Abend des 30. September endete mit `rc=0`, und
`srvpanel version` meldet `0.9.0-rc.9`. Gefahren in Chrome auf Windows, in der
Geräteleiste mit „Responsive", 900 px Höhe und „Fit to window". Im Bereich
Speicher stand „Gemessen am 2026-10-01 12:45:19", im hellen Thema „13:01:09"
und bei den Breiten aus N1a „13:15:53". Die Ausgaben stehen wörtlich da.

#### N1 im dunklen Thema bei 1440 px

```
stand=2026-09-28 /subscriptions/137  breite=1440  thema=dark  reihe=flex  höhe=197 px  kacheln=5
  Speicherplatz   68 MB          4 Punkte  Kurve  -      196 px  | belegt
  Traffic         4,1 MB        10 Punkte  Kurve  -      196 px  | ausgehend · eingehend 3,4 MB
  Zugriffe        10.504        10 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote     98 %          10 Punkte  Kurve  -      196 px  | 4xx und 5xx
  Datenbanken     0 MB           4 Punkte  Kurve  -      196 px  | belegt
/subscriptions/137  breite=1440  thema=dark
  Traffic  ruhe   einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  | ausgehend · eingehend 3,4 MB
  Traffic  oben   einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  | 30.09. · ausgehend 4,1 MB
  Traffic  unten  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  | 30.09. · eingehend 3,4 MB
  Traffic  danach einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  | ausgehend · eingehend 3,4 MB
```

Bis auf den Schrägstrich ist die Erwartung erfüllt: fünf Kacheln mit je
196 px und `einfassung=1` in allen vier Zuständen, also läuft `rc.9` im
Browser. Oben stehen 68 MB, darunter „68 MB von 5.120 MB". Seit Teil 2 ist ein
Tag dazugekommen; aus 3 und 9 Punkten sind 4 und 10 geworden. Ein drittes Bild
bei voller Fensterbreite zeigt die Ablesung „27.09. · ausgehend 6,8 MB" in
einer Zeile.

#### N1a bei 1440 px, beide Themen

```
/subscriptions/137  breite=1440  thema=dark  plattform=Android  beizeile=179.0 px
  Traffic  ruhe   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend 3,4 MB
  Traffic  oben   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 154.6  bis zur Zahl 131.2  | 30.09. · ausgehend 4,1 MB
  Traffic  unten  nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 152.5  bis zur Zahl 129.1  | 30.09. · eingehend 3,4 MB
  Traffic  danach nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend 3,4 MB
  Gegenprobe ohne nowrap:
  Traffic  ruhe   normal  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend 3,4 MB
  Traffic  oben   normal  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 154.6  bis zur Zahl 131.2  | 30.09. · ausgehend 4,1 MB
  Traffic  unten  normal  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 152.5  bis zur Zahl 129.1  | 30.09. · eingehend 3,4 MB
  Traffic  danach normal  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend 3,4 MB
  wieder mit nowrap:
  Traffic  ruhe   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend 3,4 MB
  Bruch zwischen Zahl und Einheit ohne nowrap — Ruhe: 1317 bis 1430 px, Mitte 1370 px · alle drei: keine
```

Im hellen Thema Zeile für Zeile dasselbe, im Kopf `thema=light`. Davor lief
dort `kachelnMessen()`, mit denselben fünf Kacheln wie im dunklen Thema:

```
stand=2026-09-28 /subscriptions/137  breite=1440  thema=light  reihe=flex  höhe=197 px  kacheln=5
  Speicherplatz   68 MB          4 Punkte  Kurve  -      196 px  | belegt
  Traffic         4,1 MB        10 Punkte  Kurve  -      196 px  | ausgehend · eingehend 3,4 MB
  Zugriffe        10.504        10 Punkte  Kurve  -      196 px  | Anfragen
  Fehlerquote     98 %          10 Punkte  Kurve  -      196 px  | 4xx und 5xx
  Datenbanken     0 MB           4 Punkte  Kurve  -      196 px  | belegt
```

**Bei 1440 px gibt es auf diesem Rechner nichts zu messen.** Mit und ohne die
Regel stehen alle vier Zustände gleich da. Die Ruhezeile passt mit 1,9 px
Luft, und die Spanne in der letzten Zeile liegt unterhalb von 1440.

#### N1a bei 1374 px, beide Themen — die Ruhezeile

Aus den Zahlen bei 1440 px ausgerechnet: Die Beizeile wird 165,8 px breit. Die
Ruhezeile bricht, ohne die Regel zwischen Zahl und Einheit. Die Ablesungen
passen in eine Zeile.

```
/subscriptions/137  breite=1374  thema=dark  plattform=Android  beizeile=165.8 px
  Traffic  ruhe   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend / 3,4 MB
  Traffic  oben   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 154.6  bis zur Zahl 131.2  | 30.09. · ausgehend 4,1 MB
  Traffic  unten  nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 152.5  bis zur Zahl 129.1  | 30.09. · eingehend 3,4 MB
  Traffic  danach nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend / 3,4 MB
  Gegenprobe ohne nowrap:
  Traffic  ruhe   normal  einfassung=1  betrag in 2 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend 3,4 / MB
  Traffic  oben   normal  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 154.6  bis zur Zahl 131.2  | 30.09. · ausgehend 4,1 MB
  Traffic  unten  normal  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 152.5  bis zur Zahl 129.1  | 30.09. · eingehend 3,4 MB
  Traffic  danach normal  einfassung=1  betrag in 2 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend 3,4 / MB
  wieder mit nowrap:
  Traffic  ruhe   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend / 3,4 MB
  Bruch zwischen Zahl und Einheit ohne nowrap — Ruhe: 1317 bis 1430 px, Mitte 1370 px · alle drei: keine
```

Im hellen Thema Zeile für Zeile dasselbe.

#### N1a bei 1304 px, beide Themen — die Ablesungen

Die Beizeile wird 151,8 px breit. Jetzt brechen beide Ablesungen, ohne die
Regel zwischen Zahl und Einheit. Die Ruhezeile bricht mit und ohne Regel vor
der Zahl, weil schon der Text bis zur Zahl mit 154,4 px nicht mehr passt.

```
/subscriptions/137  breite=1304  thema=dark  plattform=Android  beizeile=151.8 px
  Traffic  ruhe   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend / 3,4 MB
  Traffic  oben   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 154.6  bis zur Zahl 131.2  | 30.09. · ausgehend / 4,1 MB
  Traffic  unten  nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 152.5  bis zur Zahl 129.1  | 30.09. · eingehend / 3,4 MB
  Traffic  danach nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend / 3,4 MB
  Gegenprobe ohne nowrap:
  Traffic  ruhe   normal  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend / 3,4 MB
  Traffic  oben   normal  einfassung=1  betrag in 2 Zeile(n)  kachel 196 px  ganz 154.6  bis zur Zahl 131.2  | 30.09. · ausgehend 4,1 / MB
  Traffic  unten  normal  einfassung=1  betrag in 2 Zeile(n)  kachel 196 px  ganz 152.5  bis zur Zahl 129.1  | 30.09. · eingehend 3,4 / MB
  Traffic  danach normal  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend / 3,4 MB
  wieder mit nowrap:
  Traffic  ruhe   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 177.1  bis zur Zahl 154.4  | ausgehend · eingehend / 3,4 MB
  Bruch zwischen Zahl und Einheit ohne nowrap — Ruhe: 1317 bis 1430 px, Mitte 1370 px · alle drei: keine
```

Im hellen Thema Zeile für Zeile dasselbe.

#### N2 — die Übersicht bei 1440 px

```
/  breite=1440  thema=dark  schrift=Segoe UI (177.1, Vorgabe 162.1)  beizeile=179.0 px
  Netz     ruhe   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 182.4  bis zur Zahl 154.4  | eingehend · ausgehend / 1,0 kB/s
  Netz     oben   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 157.0  bis zur Zahl 127.8  | 11:27 · ausgehend 1,0 kB/s
  Netz     unten  nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 154.9  bis zur Zahl 125.7  | 11:27 · eingehend 1,0 kB/s
  Netz     danach nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 182.4  bis zur Zahl 154.4  | eingehend · ausgehend / 1,0 kB/s
  Gegenprobe ohne nowrap:
  Netz     ruhe   normal  einfassung=1  betrag in 2 Zeile(n)  kachel 196 px  ganz 182.4  bis zur Zahl 154.4  | eingehend · ausgehend 1,0 / kB/s
  Netz     oben   normal  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 157.0  bis zur Zahl 127.8  | 11:27 · ausgehend 1,0 kB/s
  Netz     unten  normal  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 154.9  bis zur Zahl 125.7  | 11:27 · eingehend 1,0 kB/s
  Netz     danach normal  einfassung=1  betrag in 2 Zeile(n)  kachel 196 px  ganz 182.4  bis zur Zahl 154.4  | eingehend · ausgehend 1,0 / kB/s
  wieder mit nowrap:
  Netz     ruhe   nowrap  einfassung=1  betrag in 1 Zeile(n)  kachel 196 px  ganz 182.4  bis zur Zahl 154.4  | eingehend · ausgehend / 1,0 kB/s
  Bruch zwischen Zahl und Einheit ohne nowrap — Ruhe: 1317 bis 1457 px, Mitte 1387 px · Ablesung: 1300 bis 1319 px, Mitte 1310 px · alle drei: 1317 bis 1319 px, Mitte 1318 px
```

**Hier entsteht die Bedingung schon bei 1440 px**, auch in Segoe UI: Mit
„kB/s" ist die Netzzeile 182,4 px breit. Mit der Regel bricht sie vor dem
Betrag, ohne sie zwischen „1,0" und „kB/s". Das ist derselbe Befund, den der
Container mit gebauten Werten gezeigt hat (§6b).

#### Was der Nachlauf zeigt

- **Die Behebung wirkt auf dem Server.** Dieselbe Seite in derselben Schrift
  trennt Zahl und Einheit, sobald die eine Regel fehlt, und hält sie zusammen,
  sobald sie wieder gilt. Das gilt für die Ruhezeile bei 1374 px, für beide
  Ablesungen bei 1304 px und für die Übersicht bei 1440 px.
- **Die Gegenprobe braucht keine alte Fassung.** Gegen `rc.8` hätte sie
  denselben Rechner gebraucht wie Teil 2. Auf derselben Seite nimmt sie nur die
  eine Regel weg.
- **Beide Breiten und jeder Bruch waren vorher ausgerechnet**, aus den Zahlen
  bei 1440 px. Alle vier Läufe bei 1374 und 1304 px haben getroffen, auch die
  Ablesung „unten" bei 1304 px mit 0,7 px Luft.
- **Die Kacheln bleiben 196 px hoch**, auch wenn die Beizeile zweizeilig wird.
  Dafür hält `.tile-sub.paired` zwei Zeilen frei.
- **Bilder:** die Kachelreihe in Ruhe bei 1374 und 1304 px in beiden Themen und
  die Übersicht bei 1440 px. Bilder mit dem Zeiger auf der Kurve gibt es
  nicht; die Ablesungen sind Zeile für Zeile gemessen, mit Gegenprobe.
- **In keiner der zehn Aufnahmen steht ein Fehler in der Konsole.** Das „⊗1"
  aus Teil 2 kam nicht wieder und bleibt ungeklärt.

#### Was der Lauf über sich selbst gelernt hat

> **Eine Erwartung, die einen Umbruch voraussetzt, setzt die Schrift voraus,
> in der er entstand.** `system-ui` ist auf jedem Rechner eine andere Schrift,
> und die Bedingung aus Teil 2 gab es auf Windows bei 1440 px nicht.

> **Eine Zeile, die gar nicht bricht, bricht auch nicht an der falschen Stelle
> — und `betrag in 1 Zeile(n)` liest sich trotzdem wie ein Beleg.** Erst die
> Breiten daneben sagen, ob die Messung ihren Fall hatte.

> **Die Geräteleiste gibt sich als Telefon aus, und `navigator.userAgentData`
> glaubt ihr.** Die Schrift kommt trotzdem vom Rechner; gefragt wird deshalb
> die Schrift und nicht die Plattform.

> **Eine Gegenprobe, die nur die eine Regel wegnimmt, stellt die alte Fassung
> auf derselben Seite her** — in derselben Schrift, bei derselben Breite und
> mit denselben Zahlen.

Und einer über den Container: Er misst Kästen auf das Pixel, Text aber in
seiner eigenen Schrift, DejaVu Sans. Den Umbruch aus Teil 2 hat er
nachgestellt, weil der Text in beiden Schriften breiter war als die Beizeile,
und nicht, weil es dieselbe Schrift war. In Segoe UI ist die Ruhezeile 12 %
schmaler. Die Zusage aus §6b, dass der längste Betrag in die schmalste
Beizeile passt, ist in DejaVu Sans gemessen.

> **Ein Zwilling misst Kästen auf das Pixel und Text in seiner eigenen
> Schrift.**

#### Ein Befund am Bild, nicht B4

Im hellen Thema ist der Schriftzug „SrvPanel" oben in der Leiste kaum zu
lesen, auf den Bildern dieses Laufs wie schon auf denen von Teil 2. Im
Container ist das mit der echten Komponente gemessen, gegen das Markup vor B6:

| | Schriftzug | gegen die Leiste `#1a0b2e` |
|---|---|---|
| hell, heute | `#3a3f49`, 15 px | 1,76:1 |
| hell, Markup vor B6 | `#ffffff`, 17 px | 18,56:1 |
| dunkel, heute | `#c4c9d4`, 15 px | 11,18:1 |

Seit B6 steht der Schriftzug in `BrandMark.vue`, und die Regel `.row b` aus
dem Stilblock von `PanelLayout` erreicht ihn dort nicht mehr. Das Zeichen
daneben ist im hellen Thema schon vor B6 schwach, mit 1,87:1 und 1,76:1.
**Der Betreiber hat am 1. Oktober entschieden, das nach dem Nachlauf zu
beheben**, mit `0.9.0-rc.10`. Behoben ist es am selben Tag, und es war mehr als
der Schriftzug (§9).

#### Der Prüfkörper ist fort

`p1139_rundung` ist im Panel entfernt. Danach auf dem Server die Abfrage aus
§7:

```
+-----------------+
| mit _rundung: 0 |
+-----------------+
+---------------------+
| alle Datenbanken: 5 |
+---------------------+
```

Die zweite Zeile ist die Gegenprobe; sie zeigt, dass die Abfrage gelaufen ist.

#### Die Abnahme

**B4 ist am 1. Oktober 2026 abgenommen**, ausgesprochen vom Betreiber auf Grund
von Teil 1, Teil 2 und diesem Nachlauf. N1 ist in beiden Themen erfüllt, N2
auch.

## §9 · Schriftzug, Zeichen und Anmeldeseite — behoben für `0.9.0-rc.10`

Der Befund aus §8 ist am selben Tag behoben, wie vom Betreiber entschieden. Beim
Nachsehen war er grösser als der Schriftzug: Auf der Leiste lasen vier Dinge
Marken, die die Leiste nicht setzte, und auf der Anmeldeseite stand dieselbe
Familie im Formular. Die Anmeldeseite hat der Betreiber auf Rückfrage
mitbeheben lassen („Ja, ganz").

### Was falsch war, im hellen Thema

Gegen den Grund, auf dem es wirklich steht, gemessen im Container mit den
echten Komponenten des Stands `0.9.0-rc.9`. Eine Meldung hat eine getönte
Fläche, und der dritte Balken des Zeichens hat halbe Deckkraft; beides ist
übereinandergelegt. Die erste Fassung dieser Tabellen hat das nicht getan
(weiter unten, „Die erste Fassung dieser Zahlen").

| Leiste | vorher | |
|---|---|---|
| Schriftzug | `#3a3f49`, 15 px, Gewicht 700 | 1,76:1 |
| Zeichen, Balken 1 · 2 · 3 | `#3730a3` · `#3a3f49` · `#3a3f49` mit halber Deckkraft | 1,87 · 1,76 · 1,26:1 |
| Versionsmarke | `#9b8fb0` auf einer weissen Pille | 2,90:1 |

| Anmeldeseite | vorher |
|---|---|
| Fehlermeldung „Diese Zugangsdaten passen nicht zu einem aktiven Konto." | 1,75:1 |
| Hinweis zur Sitzung (`.notice.warn`) | 1,65:1 |
| Fusszeile des Betreibers | 1,83:1 |
| Ränder der Fehlermeldung · des Hinweises · eines ungültigen Feldes | 2,84 · 2,85 · 2,73:1 |
| Zeichen, Balken 1 | 1,87:1 |

Im dunklen Thema war alles lesbar. Der Schriftzug trug dort die Schrift der
Seite (`#c4c9d4`, 15 px, 11,18:1) statt seiner Gestalt aus der Zeit vor B6.

### Drei Ursachen

1. **Eine gescopte Regel, deren Ziel in eine Kindkomponente umgezogen ist.**
   `.row b` stand im Stilblock von `PanelLayout`. Vue übersetzt die Regel zu
   `.row b[data-v-…]`, und das Attribut tragen nur die Elemente der eigenen
   Vorlage und die eine Wurzel einer eingesetzten Komponente. `BrandMark` hat
   zwei Wurzeln. Seit B6 traf die Regel nichts mehr.
2. **Eine Fläche, die nicht jede Marke setzt, die auf ihr gelesen wird.** Das
   Zeichen liest `--mark-accent`, die Versionsmarke `--surface` und `--line`,
   die Meldungen der Anmeldeseite `--warn` und `--critical`. Gesetzt waren sie
   nur in `:root`, für den hellen Grund.
3. **`color` erbt als fertiger Wert.** Text ohne eigene Regel nahm die Farbe,
   die der `body` aus dem `--text` der Seite gerechnet hat. Die Marke `--text`
   der Fläche kam dabei nie zum Zug.

Dazu stand in `app.css` seit dem 9. September, auf beiden Markenflächen stehe
keine Zustandsfarbe. Für die Anmeldeseite war das nie richtig.

### Was geändert ist

- Der Name steht in `BrandMark.vue` als `.brand-name`, gestaltet in `app.css`
  unter `.rail .brand-name`. Die tote Regel aus `PanelLayout` ist fort.
- `.rail, .topbar` setzt `--surface`, `--line`, `--mark-accent` und `color`.
- `.signin` setzt `--mark-accent`, für `--warn` und `--critical` die Werte des
  dunklen Themas und `color`.
- `MarkIcon` setzt die Farbe seiner unteren Balken selbst, `--text-strong`.

### Gemessen im Container, nachher

In beiden Themen dieselben Werte:

| Leiste | nachher | |
|---|---|---|
| Schriftzug | `#ffffff`, 17 px, Gewicht 660 | 18,56:1 |
| Zeichen, Balken 1 · 2 · 3 | `#ff7fec` · `#ffffff` · `#ffffff` mit halber Deckkraft | 8,40 · 18,56 · 5,24:1 |
| Versionsmarke | `#9b8fb0` auf dem Grund der Leiste, Rand `#3a2954` | 6,14:1 |

| Anmeldeseite | nachher |
|---|---|
| Schriftzug | `#ffb7a5`, 26 px, Gewicht 660, 11,11:1 |
| Fehlermeldung, Titel · Text | 9,46 · 13,25:1 |
| Hinweis zur Sitzung | 12,39:1 |
| Fusszeile des Betreibers | 16,17:1 |
| Ränder der Fehlermeldung · des Hinweises · eines ungültigen Feldes | 7,88 · 8,85 · 7,59:1 |
| Zeichen, Balken 1 · 2 · 3 | 8,40 · 11,11 · 3,57:1 |

Der Rand der Versionsmarke steht bei 1,44:1. Er ist eine Haarlinie wie
`--nav-border` und kein Bedienelement, und die Schrift darin trägt die Marke.
Der Schriftzug der Anmeldeseite stand vorher mit Gewicht 900 da: Das `<b>` gab
`bolder` auf die Überschrift.

#### Die erste Fassung dieser Zahlen

**Sie war an drei Stellen zu hoch** und stand so im Commit der Behebung, im
CHANGELOG und in CLAUDE.md. Der Prüfkörper rechnete eine Schrift gegen den
ersten Vorfahren mit mehr als halber Deckkraft und warf dabei die getönte
Fläche einer Meldung weg. Den dritten Balken des Zeichens las er ohne seine
halbe Deckkraft. So standen die Fehlermeldung mit 16,17:1 statt 13,25 da, der
Hinweis mit 15,57 statt 12,39 und der dritte Balken mit 18,56 statt 5,24. Vor
der Behebung standen dort 1,83 und 1,76 statt 1,75 und 1,65. Gefunden hat es
das Snippet für den Nachlauf beim Vorabmessen im Container, denn es legt die
Flächen übereinander. Kein Urteil kippt dabei: Vorher lag jeder Wert unter
seiner Grenze, nachher jeder darüber.

> **Ein Prüfkörper, der die Deckkraft wegwirft, misst die Farbe vor dem
> Überblenden — und die sieht niemand.** Der Satz steht seit A14 in CLAUDE.md,
> und dieser Prüfkörper ist trotzdem hineingelaufen.

**Und zwei Zitate kamen aus dem Prüfkörper und nicht von der Seite.** Die erste
Fassung zitierte „Diese Anmeldedaten sind ungültig." und „Die Sitzung ist
abgelaufen." — beide Sätze standen nur in den Stubs des Aufsatzes. Die Seite
sagt „Diese Zugangsdaten passen nicht zu einem aktiven Konto."
(`LoginController`). Einen Hinweis zur Sitzung gibt es in mehreren Fassungen,
etwa „Die Sitzung hat ihre Höchstdauer erreicht. Bitte erneut anmelden.".
Aufgefallen ist es am Bild des Nachlaufs, auf dem der echte Satz steht.

> **Ein Text aus dem eigenen Prüfkörper, zitiert als Text der Seite, ist
> erfunden — auch wenn die Zahl daneben stimmt.**

### Die Wächter

- **`ScopedReachTest`**: Jede gescopte Regel trifft ein Element ihrer eigenen
  Vorlage. Gegen den Stand vor der Behebung meldet er `.row b`.
- **`SurfaceTokenTest`** fragt seitdem in beide Richtungen. Was auf einer
  Markenfläche steht, liest nur Marken, die sie setzt oder die beide Themen
  gleich setzen. Dafür werden die Vorlagen der Komponenten unter der Fläche
  eingesetzt, und jede Regel wird gegen diesen Baum gefragt. Gegen den Stand
  vor der Behebung meldet er zehn Stellen, alle aus der Tabelle oben. Dazu
  rechnet er Zustandsfarbe und Zeichen auf der Fläche nach und verlangt, dass
  jede Fläche `color` aus einer eigenen Schriftmarke setzt.
- **`IconTest`**: Das Zeichen setzt die Farbe seiner unteren Balken selbst.
  Ob `--mark-accent` in beiden Themen steht, fragt er seitdem je Thema. Vorher
  zählte er zwei Zeilen in der Datei, und seit Leiste und Anmeldeseite die
  Marke setzen, sind es vier.

### Der Nachlauf gegen `0.9.0-rc.10` — gefahren am 1. Oktober 2026

`v0.9.0-rc.10` steht auf dem Merge-Commit `f7c6d595`, gesetzt vom Betreiber;
auf `cloudsrv24` meldet `srvpanel version` danach `0.9.0-rc.10`.

**Die Vorschrift** ist ein Snippet für die Konsole, einmal auf der frisch
geladenen Seite eingefügt. Es misst beide Themen auf derselben Seite, indem es
`data-theme` am `<html>` umstellt, und stellt danach das Thema der Seite wieder
her. Es legt die Flächen der Vorfahren übereinander, rechnet die Deckkraft
eines Balkens mit und wertet einen Rand nur, wenn er einen Zustand trägt.
Daneben druckt es einen Ladebeleg und eine Gegenprobe: die Schrift der Seite
auf derselben Fläche, also den Wert, den der Schriftzug vor `rc.10` hatte. Ein
zweiter Aufruf ohne Neuladen misst nicht. Vorab im Container gegen `rc.9` und
`rc.10` gefahren; daraus stammen die Tabellen oben.

```js
// Nachlauf docs/139 §9: Leiste und Anmeldeseite in beiden Themen.
// Einmal auf der frisch geladenen Seite in die Konsole einfügen.
(() => {
  const STAND = 'markeMessen · 1. Oktober 2026'
  if (window.__markeGemessen) {
    console.log('Schon gemessen — Seite neu laden (Strg+F5) und dann erneut einfügen.')
    return
  }
  window.__markeGemessen = true

  const rgba = (s) => {
    const m = (String(s).match(/[\d.]+/g) || []).map(Number)
    return m.length < 3 ? null : [m[0], m[1], m[2], m.length > 3 ? m[3] : 1]
  }
  // Eine Farbe mit Deckkraft über einen deckenden Grund gelegt.
  const ueber = (o, u) => [0, 1, 2].map((i) => o[i] * o[3] + u[i] * (1 - o[3])).concat(1)
  const lum = (c) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4 }
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2])
  }
  const kon = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05) }
  // Der Grund hinter einem Element: die Flächen der Vorfahren, von der ersten
  // deckenden an übereinandergelegt — eine Meldung hat eine getönte Fläche.
  const grund = (el) => {
    const s = []
    for (let e = el; e; e = e.parentElement) {
      const c = rgba(getComputedStyle(e).backgroundColor)
      if (c && c[3] > 0) { s.push(c); if (c[3] >= 1) break }
    }
    let g = [255, 255, 255, 1]
    for (const c of s.reverse()) g = ueber(c, g)
    return g
  }
  const hex = (c) => '#' + c.slice(0, 3).map((v) => Math.round(v).toString(16).padStart(2, '0')).join('')
  const z = (k) => k.toFixed(2).replace('.', ',')

  const html = document.documentElement
  const vorher = html.getAttribute('data-theme')
  const zeilen = [`${STAND} · ${location.pathname} · Breite ${innerWidth} px`]
  const zuWenig = []

  // Eine Zeile: was es ist, seine sichtbare Farbe, sein Grund, das Verhältnis.
  const zeile = (name, farbe, g, grenze, extra = '') => {
    const k = kon(farbe, g)
    if (k < grenze) zuWenig.push(`${name} ${z(k)}:1 < ${grenze}`)
    zeilen.push(`  ${name.padEnd(30)} ${hex(farbe)} auf ${hex(g)}  ${z(k).padStart(5)}:1${extra}`)
  }
  const text = (name, el) => {
    if (!el) { zeilen.push(`  ${name.padEnd(30)} (nicht da)`); return }
    const s = getComputedStyle(el)
    const g = grund(el)
    zeile(name, ueber(rgba(s.color), g), g, 4.5, `  ${s.fontSize}/${s.fontWeight}`)
  }
  // Ein Rand, der einen Zustand trägt, braucht 3:1. Eine Haarlinie nicht — sie
  // wird gedruckt und nicht gewertet (siehe --nav-border in app.css).
  const rand = (name, el, haarlinie = false) => {
    if (!el) { zeilen.push(`  ${name.padEnd(30)} (nicht da)`); return }
    const g = grund(el.parentElement)
    zeile(name, ueber(rgba(getComputedStyle(el).borderLeftColor), g), g, haarlinie ? 0 : 3, haarlinie ? '  Haarlinie, ohne Grenze' : '')
  }
  const zeichen = (wo, svg) => {
    if (!svg) { zeilen.push(`  ${('Zeichen ' + wo).padEnd(30)} (nicht da)`); return }
    const g = grund(svg)
    svg.querySelectorAll('rect').forEach((r, i) => {
      const s = getComputedStyle(r)
      const f = rgba(s.fill)
      const deck = parseFloat(s.opacity) * f[3]
      zeile(`Zeichen ${wo}, Balken ${i + 1}`, ueber([f[0], f[1], f[2], deck], g), g, 3, deck < 1 ? `  Deckkraft ${deck}` : '')
    })
  }

  const messen = (thema) => {
    html.setAttribute('data-theme', thema)
    zeilen.push(`thema=${thema}`)
    const rail = document.querySelector('aside.rail')
    const signin = document.querySelector('main.signin')
    if (rail) {
      const name = rail.querySelector('.brand-name, .row b')
      const s = name ? getComputedStyle(name) : null
      zeilen.push(`  Ladebeleg Leiste: Grund ${hex(rgba(getComputedStyle(rail).backgroundColor))}, Schriftzug ${s ? s.fontSize + '/' + s.fontWeight : 'fehlt'} (neu: 17px/660)`)
      text('Schriftzug', name)
      zeichen('Leiste', rail.querySelector('svg.mark'))
      const v = rail.querySelector('.version')
      text('Versionsmarke, Schrift', v)
      rand('Versionsmarke, Rand', v, true)
      if (v) zeilen.push(`  Versionsmarke, Fläche: ${hex(grund(v))} · Leiste ${hex(grund(rail))}`)
      const b = grund(name || rail)
      zeilen.push(`  Gegenprobe: Schrift der Seite ${hex(rgba(getComputedStyle(document.body).color))} auf der Leiste ${z(kon(rgba(getComputedStyle(document.body).color), b))}:1`)
    }
    if (signin) {
      const sheet = signin.querySelector('.sheet') || signin
      zeilen.push(`  Ladebeleg Anmeldeseite: color der Fläche ${hex(rgba(getComputedStyle(signin).color))} (neu: #efe9f2), Grund der Maske ${hex(grund(sheet))}`)
      text('Schriftzug', signin.querySelector('.brand-name, h1 b'))
      zeichen('Anmeldung', signin.querySelector('svg.mark'))
      const kritisch = signin.querySelector('.notice.critical')
      text('Fehlermeldung, Titel', kritisch && kritisch.querySelector('b'))
      text('Fehlermeldung, Text', kritisch && kritisch.querySelector('span'))
      rand('Fehlermeldung, Rand', kritisch)
      const warn = signin.querySelector('.notice.warn')
      text('Hinweis der Sitzung', warn && warn.querySelector('span'))
      rand('Hinweis der Sitzung, Rand', warn)
      rand('Feld, ungültig, Rand', signin.querySelector('input[aria-invalid="true"]'))
      text('Fusszeile', signin.querySelector('p.release:not(:has(.version))'))
      text('Versionsnummer', signin.querySelector('.release .version'))
      zeilen.push(`  Gegenprobe: Schrift der Seite ${hex(rgba(getComputedStyle(document.body).color))} auf der Maske ${z(kon(rgba(getComputedStyle(document.body).color), grund(sheet)))}:1`)
    }
    if (!rail && !signin) zeilen.push('  weder Leiste noch Anmeldeseite auf dieser Seite')
  }

  try {
    messen('light')
    messen('dark')
  } finally {
    if (vorher === null) html.removeAttribute('data-theme'); else html.setAttribute('data-theme', vorher)
  }
  zeilen.push(zuWenig.length === 0
    ? 'Urteil: jede Schrift über 4,5:1, jedes Zeichen und jeder Rand eines Zustands über 3:1'
    : `Urteil: zu wenig Kontrast — ${[...new Set(zuWenig)].join(' · ')}`)
  zeilen.push(`Thema der Seite zurück auf: ${vorher ?? '(keins)'}`)
  console.log(zeilen.join('\n'))
})()
```

#### Schritt 1 — die Leiste, angemeldet

Auf `cloudsrv24.de:8443`, Übersicht, Geräteleiste 1440 × 900, die Seite im
dunklen Thema:

```
markeMessen · 1. Oktober 2026 · / · Breite 1440 px
thema=light
  Ladebeleg Leiste: Grund #1a0b2e, Schriftzug 17px/660 (neu: 17px/660)
  Schriftzug                     #ffffff auf #1a0b2e  18,56:1  17px/660
  Zeichen Leiste, Balken 1       #ff7fec auf #1a0b2e   8,40:1
  Zeichen Leiste, Balken 2       #ffffff auf #1a0b2e  18,56:1
  Zeichen Leiste, Balken 3       #8d8597 auf #1a0b2e   5,24:1  Deckkraft 0.5
  Versionsmarke, Schrift         #9b8fb0 auf #1a0b2e   6,14:1  11.5px/400
  Versionsmarke, Rand            #3a2954 auf #1a0b2e   1,44:1  Haarlinie, ohne Grenze
  Versionsmarke, Fläche: #1a0b2e · Leiste #1a0b2e
  Gegenprobe: Schrift der Seite #3a3f49 auf der Leiste 1,76:1
thema=dark
  Ladebeleg Leiste: Grund #1a0b2e, Schriftzug 17px/660 (neu: 17px/660)
  Schriftzug                     #ffffff auf #1a0b2e  18,56:1  17px/660
  Zeichen Leiste, Balken 1       #ff7fec auf #1a0b2e   8,40:1
  Zeichen Leiste, Balken 2       #ffffff auf #1a0b2e  18,56:1
  Zeichen Leiste, Balken 3       #8d8597 auf #1a0b2e   5,24:1  Deckkraft 0.5
  Versionsmarke, Schrift         #9b8fb0 auf #1a0b2e   6,14:1  11.5px/400
  Versionsmarke, Rand            #3a2954 auf #1a0b2e   1,44:1  Haarlinie, ohne Grenze
  Versionsmarke, Fläche: #1a0b2e · Leiste #1a0b2e
  Gegenprobe: Schrift der Seite #c4c9d4 auf der Leiste 11,18:1
Urteil: jede Schrift über 4,5:1, jedes Zeichen und jeder Rand eines Zustands über 3:1
Thema der Seite zurück auf: dark
```

Jede Zeile wie vorhergesagt, in beiden Themen. Der Ladebeleg zeigt das neue
Stylesheet (`17px/660`), und die Gegenprobe steht im hellen Thema bei genau den
1,76:1, mit denen der Schriftzug vorher auf der Leiste stand.

Dazu zwei Bilder, eines je Thema. Das erste zeigt die Leiste im dunklen Thema:
Schriftzug weiss, Zeichen pink und weiss, die Versionsmarke `0.9.0-rc.10` als
Rahmen auf der Leiste. Das zweite ist nach `window.srvpanelTheme('light')`
aufgenommen, der Aufruf steht in der Konsole daneben. Die Übersicht ist hell,
und die Leiste sieht aus wie im ersten Bild: Schriftzug weiss, Zeichen pink und
weiss, die Versionsmarke als Rahmen. Vor der Behebung stand dort ein
dunkelgrauer Schriftzug, ein Zeichen in Indigo und Dunkelgrau und eine weisse
Pille (oben, „Was falsch war, im hellen Thema").

#### Schritt 2 — die Anmeldeseite, im privaten Fenster

Ein Fehlversuch mit `nachlauf@example.invalid` und einem falschen Passwort, dann
das Snippet:

```
markeMessen · 1. Oktober 2026 · /login · Breite 1440 px
thema=light
  Ladebeleg Anmeldeseite: color der Fläche #efe9f2 (neu: #efe9f2), Grund der Maske #1a0b2e
  Schriftzug                     #ffb7a5 auf #1a0b2e  11,11:1  26px/660
  Zeichen Anmeldung, Balken 1    #ff7fec auf #1a0b2e   8,40:1
  Zeichen Anmeldung, Balken 2    #ffb7a5 auf #1a0b2e  11,11:1
  Zeichen Anmeldung, Balken 3    #8d616a auf #1a0b2e   3,57:1  Deckkraft 0.5
  Fehlermeldung, Titel           #ffb7a5 auf #331a2e   9,46:1  14px/700
  Fehlermeldung, Text            #efe9f2 auf #331a2e  13,25:1  14px/400
  Fehlermeldung, Rand            #f08a72 auf #140823   7,88:1
  Hinweis der Sitzung            (nicht da)
  Hinweis der Sitzung, Rand      (nicht da)
  Feld, ungültig, Rand           #f08a72 auf #1a0b2e   7,59:1
  Fusszeile                      (nicht da)
  Versionsnummer                 #a99cbe auf #1a0b2e   7,24:1  11.5px/400
  Gegenprobe: Schrift der Seite #3a3f49 auf der Maske 1,76:1
thema=dark
  Ladebeleg Anmeldeseite: color der Fläche #efe9f2 (neu: #efe9f2), Grund der Maske #1a0b2e
  Schriftzug                     #ffb7a5 auf #1a0b2e  11,11:1  26px/660
  Zeichen Anmeldung, Balken 1    #ff7fec auf #1a0b2e   8,40:1
  Zeichen Anmeldung, Balken 2    #ffb7a5 auf #1a0b2e  11,11:1
  Zeichen Anmeldung, Balken 3    #8d616a auf #1a0b2e   3,57:1  Deckkraft 0.5
  Fehlermeldung, Titel           #ffb7a5 auf #331a2e   9,46:1  14px/700
  Fehlermeldung, Text            #efe9f2 auf #331a2e  13,25:1  14px/400
  Fehlermeldung, Rand            #f08a72 auf #140823   7,88:1
  Hinweis der Sitzung            (nicht da)
  Hinweis der Sitzung, Rand      (nicht da)
  Feld, ungültig, Rand           #f08a72 auf #1a0b2e   7,59:1
  Fusszeile                      (nicht da)
  Versionsnummer                 #a99cbe auf #1a0b2e   7,24:1  11.5px/400
  Gegenprobe: Schrift der Seite #c4c9d4 auf der Maske 11,18:1
Urteil: jede Schrift über 4,5:1, jedes Zeichen und jeder Rand eines Zustands über 3:1
Thema der Seite zurück auf: light
```

Jede vorhandene Zeile wie vorhergesagt. Nicht da sind der Hinweis zur Sitzung,
den es nur nach einer beendeten Sitzung gibt, und die Fusszeile, die auf
`cloudsrv24` nicht eingerichtet ist; für beide gilt die Messung im Container.
Gäste sehen auf `cloudsrv24` das helle Thema (`SRVPANEL_THEME`), daher
„zurück auf: light". Die Bilder zeigen „Das Formular wurde nicht gespeichert.
Diese Zugangsdaten passen nicht zu einem aktiven Konto." hell auf der getönten
Fläche und das Adressfeld mit dem Rand der Störung. In beiden Themen sieht die
Seite gleich aus, und das ist die Absicht einer Markenfläche.

#### Was der Nachlauf zeigt

Die Behebung wirkt auf dem Server, auf beiden Flächen und in beiden Themen, und
der Container hat jede Zahl auf die zweite Stelle vorhergesagt. Das ist die
Gegenseite zu §8: Dort lag der Zwilling bei einem Umbruch daneben, weil Text in
seiner eigenen Schrift steht.

> **Farben trifft der Zwilling auf die zweite Stelle — sie hängen an Stylesheet
> und Markup und an keiner Schrift.**
