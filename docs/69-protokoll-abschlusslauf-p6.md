# Protokoll des Abschlusslaufs für P6

*Angelegt am 21. August 2026, **vor** dem ersten Punkt. Der Lauf ist `docs/68`.*

> **Hier ist noch nichts gemessen.** Diese Datei ist das Gefäss, in das der Lauf
> schreibt — Punkt für Punkt, **während** er fährt und nicht danach. Wer sie
> heute liest, findet Erwartungen und keine Ergebnisse.

---

## 0. Rahmen

| | | |
|---|---|---|
| Lauf | **`docs/68`** | fünf Punkte plus die Rahmenprüfung |
| Fassung | **`0.6.0-rc.24`** | gemessen, Punkt 0 |
| Server | `cloudsrv24` | |
| Abonnement | 140, `p6-abnahme.invalid`, Systembenutzer `p1139` = **uid 1002** | |
| Messmittel | `tests/bilder-messen.js`, Stand 2026-08-21 | für Punkt 3 |

**Wofür es diesen Lauf gibt** steht in `docs/68 §0`: P6 ist gebaut und sein
Abnahmekriterium gemessen — offen sind vier benannte Reste und die
Abnahmeerklärung. Diese Datei wird die Erklärung, **wenn** die Punkte sie
tragen.

---

## 1. Die Punkte

| # | Punkt | erwartet | gemessen | |
|---|---|---|---|---|
| 0 | Die Fassung | `0.6.0-rc.24` | `0.6.0-rc.24` / `0.6.0~rc.24` | **erfüllt** |
| 1 | Die Archive durch die echte Route | nichts ausserhalb, `beweis` drinnen | nichts ausserhalb, `beweis` drinnen, Gegenprobe trifft | **erfüllt** |
| 2 | Durch einen Verweis hinaus schreiben | Ziel unverändert, Gegenprobe schreibt | Ziel unberührt, `beweis`=`DURCHGEKOMMEN`, Agent sah beide | **erfüllt** |
| 3 | Die Umbruchregel auf zwei weiteren Seiten | `dokument: 0`, `schiebt` leer | `schiebt: []` in allen vier Lagen, Bruch in der Zelle | **erfüllt** |
| 4 | `id` am Vorgang | `ran_as.uid` = `id -u p1139` | 1002 = 1002, groups 1002; ausserhalb kein `ran_as` | **erfüllt** |
| 5 | Die Rechte an `/var/lib/srvpanel` | `750 srvpanel:srvpanel`, auch nach dem Neustart | `750 srvpanel:srvpanel`, vor und nach dem Neustart gleich | **erfüllt** |

---

## 1a. Punkt 0 — welche Fassung läuft

**Gemessen am 21. August 2026 auf `cloudsrv24`:**

    srvpanel --version          0.6.0-rc.24
    dpkg -l srvpanel | tail -1  ii  srvpanel  0.6.0~rc.24  amd64

**Die beiden Schreibweisen sind dieselbe Fassung.** `dpkg` verlangt eine
Ordnung, in der `rc` **vor** der Freigabe steht, und `-` trennt dort schon die
Paketrevision ab; die Tilde ist das Zeichen, das kleiner sortiert als das
Nichts. `0.6.0~rc.24` liegt damit vor `0.6.0`, `0.6.0-rc.24` läge dahinter.

Und die Namen des Abonnements:

    /var/www/vhosts/p6-abnahme.invalid — uid=1002(p1139) gid=1002(p1139) groups=1002(p1139)

### Und eine Zahl, die schon hier etwas über Punkt 4 sagt

**`p1139` trägt `uid 1002` — und in `docs/62` trug `p1138` dieselbe 1002.**

Dort war sie der Beleg dafür, dass zwei Abonnements zwei Kennungen ergeben:
`p1136` → 1001, `p1138` → 1002. Heute gehört die 1002 einem anderen Namen. Ob
`p1138` weg ist und die Kennung nachrückte oder ob es sie doppelt gibt, ist
**nicht gemessen** und steht in Punkt 4.

Für den Lauf ändert das nichts und für seine Lesart einiges:

> **Eine Kennung, die einen Namen belegt hat, belegt ihn nicht auf Dauer** —
> ein `uid`, den man aus einem alten Protokoll mitnimmt, gehört einem
> Abonnement von damals.

Genau deshalb vergleicht Punkt 4 `id` und `ran_as` **im selben Augenblick** und
nicht gegen eine Zahl aus `docs/62`.

---

## 1b. Punkt 1 — die beiden Archive durch die echte Route

**Gemessen am 21. August 2026 auf `cloudsrv24` gegen `v0.6.0-rc.24`**, angemeldet
als Kunde des Abonnements 140. Der Weg war der echte: Panel → `can:editFiles` →
`FileController::extract()` → `Workspace::path()` → Sandbox. In `docs/62` ging
derselbe Angriff über den Weg der Operation; hier steht die **erste** der beiden
Wände aus `docs/61 §1` mit im Bild.

### Der Prüfkörper war scharf

Die Auflistung der Archive zeigte den Ausbruchspfad in den Mitgliedsnamen:

    raus-relativ.tar   ../../…/../tmp/getroffen-relativ   +  beweis
    raus-absolut.tar   /tmp/getroffen-absolut             +  beweis

`tar` warnte beim Bauen selbst mit `Removing leading '../' / '/' from member
names` — das ist der Beleg, dass die Namen aus dem Verzeichnis herausführen, und
nicht das Gegenteil.

### Die Messung

**Je Archiv im Panel „Aktionen → Entpacken", angemeldet als Kunde.** Zweimal
dieselbe Meldung, wörtlich:

> Das Archiv ist entpackt — 1 Einträge, 1 übergangen, weil sie aus dem
> Zielverzeichnis herausführen.

Danach auf dem Server:

| | erwartet | gemessen |
|---|---|---|
| `/tmp/getroffen-relativ` | nicht da | **No such file or directory** |
| `/tmp/getroffen-absolut` | nicht da | **No such file or directory** |
| `find $WURZEL -name 'getroffen*'` | keine Zeile | **keine Zeile** |
| in `ziel` | `beweis` liegt da | **`beweis`** + die zwei `.tar` |

### Die zwei Gegenproben

**Nach innen:** `beweis` ist in `ziel` gelandet — das Archiv hat also etwas
abgeliefert, und die Abwesenheit der `getroffen`-Dateien ist eine Wand und kein
leeres Archiv.

**Nach aussen:** Dieselben Tarballs von `tar -xPf` entpackt legten
`/tmp/getroffen-relativ` und `/tmp/getroffen-absolut` an (je 10 Byte,
`root:root`). Was das Panel abgewiesen hat, schreibt `tar` ohne die Sandbox —
der Angriff trifft also, sobald die Wand fehlt.

> **Ein Angriff, der nicht trifft, misst den Angreifer und nicht die Abwehr.**
> Dieser trifft — nur nicht durch das Panel.

Beide Treffer sind sofort wieder entfernt worden (`rm`, `ls` meldet danach
`No such file or directory`), damit sie in Punkt 2 nicht als Bestand mitmessen.

> **Ein Prüfkörper, den man stehen lässt, ist beim nächsten Lauf Bestand.**

### Ein Nebenbeleg, den kein Kriterium bestellt hat

`beweis` trägt `p1139 www-data`, die zwei `.tar` tragen `p1139 p1139`. Der
Unterschied ist keine Abweichung: Die Archive hat `install -g p1139` von Hand
hingelegt, **`beweis` hat die Sandbox geschrieben** — und die erbt die Gruppe
`www-data` vom setgid-Bit auf `httpdocs` (Schritt 6c, `docs/51 §8.4`).

> **Das Entpacken legt eine Datei an wie jeder andere Weg des Panels** — mit der
> Gruppe des Verzeichnisses, nicht der des Erzeugers. Genau das war der Sinn von
> Befund 3 der Zwischenabnahme.

---

## 1c. Punkt 2 — durch einen Verweis hinaus schreiben

**Gemessen am 21. August 2026 auf `cloudsrv24` gegen `v0.6.0-rc.24`.** Kriterium
5, in `docs/62` über den Weg der Operation geprüft, hier durch die **echte
Route** `PUT /subscriptions/140/files`.

### Der Aufbau

Ein Verweis `httpdocs/boes/durchgang → /tmp/ausserhalb-ziel`, angelegt als root,
Eigentümer `p1139`. Das Ziel trägt `0666` — **Absicht**: Wäre es für den Kunden
nicht schreibbar, scheiterte der Versuch an den Dateirechten und nicht an der
Grenze, und der Lauf hielte ein `EACCES` für eine Wand.

Prüfsumme vor dem Versuch: `bb499d4b…a51b`.

### Die erste Wand: der Editor öffnet den Verweis gar nicht

Der Aufruf `…/files/edit?path=/httpdocs/boes/durchgang` als Kunde ergab:

> Das Formular wurde nicht gespeichert. Nur eine Datei lässt sich öffnen.

`read()` nimmt nur reguläre Dateien — der Editor ist für einen Angreifer eine
Sackgasse. **Aber der Editor ist nur ein Formular; die Wand, die zählt, steht
hinter `PUT`.**

### Die Messung durch die Route

Zwei Schreibversuche aus der Konsole der Kundensitzung, `redirect: 'manual'`:

    /httpdocs/boes/durchgang    -> status 0 (opaqueredirect)
    /httpdocs/boes/ziel/beweis  -> status 0 (opaqueredirect)

**Der Status ist undurchsichtig und nicht das Urteil** — die Route antwortet auf
einen Schreibvorgang mit einer Weiterleitung, und die ist bei `manual` opak. Das
Urteil steht auf der Platte und im Protokoll des Agenten:

| | erwartet | gemessen |
|---|---|---|
| `/tmp/ausserhalb-ziel` | Prüfsumme wie vorher | **`bb499d…a51b`, unverändert** |
| `ziel/beweis` | `DURCHGEKOMMEN` | **`DURCHGEKOMMEN`** (überschrieb `brav`) |
| `agent.log`, `files.write` | erreicht den Agenten | **mehrere Zeilen, `ran_as.uid 1002`** |

**Die dritte Zeile trennt „abgewiesen" von „nie angekommen".** Der Schreibversuch
auf den Verweis stand als `files.write` im Protokoll des Agenten — er kam also
bis zur Sandbox und wurde **dort** gehalten, nicht von einer Hürde davor. Und die
Gegenprobe innen schrieb `DURCHGEKOMMEN`, also war die Abwesenheit aussen eine
Wand und kein toter Weg.

> **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.** Die
> Auskunft „aussen unverändert" ist erst dann eine Messung, wenn „innen
> geschrieben" daneben steht.

### Und ein Befund am Prüfmittel, kein Panelfehler

Der **erste** Anlauf benutzte `redirect: 'follow'`, und `fetch` geriet mit der
Weiterleitung der abgewiesenen Route in `net::ERR_TOO_MANY_REDIRECTS` — kein
Status, keine Messung. Der echte Kundenweg (Inertia-Router) behandelt dieselbe
Weiterleitung über das Inertia-Protokoll und läuft nicht hinein; der rohe
`fetch` war der Sonderfall.

> **Eine Sonde, die der Weiterleitung folgt, misst die Weiterleitung und nicht
> den Vorgang.** Für eine schreibende Route zählt die Platte, nicht der Status —
> dieselbe Lehre wie bei `mandant-messen.js`.

Dazu ein zweiter, kleinerer: `cat "$WURZEL/…"` lief zwischenzeitlich in einem
**frischen** Mac-Terminal, in dem `$WURZEL` leer war — der Pfad wurde ohne
Präfix falsch, und die Meldung „No such file" sah aus wie ein Fund. Sie war
keiner.

> **Eine Variable, die eine Sitzung nicht überlebt, macht aus einem richtigen
> Befehl einen falschen — lautlos.**

---

## 1d. Punkt 4 — `id` am Vorgang

**Gemessen am 21. August 2026 auf `cloudsrv24` gegen `v0.6.0-rc.24`**, teils schon
in Punkt 2 mitgelesen. Die halbe Hälfte von Kriterium 13: nicht „`uid` ist nicht
0", sondern „`uid` ist **die des Abonnements**".

| | erwartet | gemessen |
|---|---|---|
| `id -u p1139` / `id -G p1139` | eine Zahl > 0 | **1002 / 1002** |
| `ran_as` der `files.*`-Vorgänge | dieselbe Zahl | **`{"uid":1002,"groups":[1002]}`** |
| `system.`/`service.`-Vorgänge mit `ran_as` | keiner | **0** |

**Die dritte Zeile ist die Gegenprobe.** Wäre `ran_as` ein Feld, das jeder
Vorgang mitschreibt, sähe es aus wie eine Messung. Dass die Vorgänge **ausserhalb**
des Chroots es nicht tragen — `Connection` setzt es nur, wenn die Sandbox eine
Kennung gemeldet hat —, macht die 1002 zum Beleg.

> **Ein Feld, das überall gleich dasteht, belegt nicht, dass jemand es gefüllt
> hat.**

**Und die Zahl schliesst den Faden aus Punkt 0:** Dort war `p1139 = 1002`
dieselbe Kennung, die in `docs/62` `p1138` trug. Hier ist sie im selben Augenblick
gemessen — `id` und `ran_as` nebeneinander — und nicht gegen eine Zahl von
vorgestern gehalten. Ob `p1138` fort ist, ändert daran nichts: Der Vorgang meldet
die Kennung, die `id` **jetzt** für `p1139` nennt.

---

## 1e. Punkt 3 — die Umbruchregel auf zwei weiteren Seiten

**Gemessen am 21. August 2026 auf `cloudsrv24` gegen `v0.6.0-rc.24`**, `/files`
und `/cron` bei 390 px, `tests/bilder-messen.js` Stand `2026-08-21`, je Seite
beide Themes. Der Prüfkörper ist ein Name ohne Bruchstelle: die Datei
`SHA256-Sn3W6HvtgEGjDuvTnuZvc7Zys8zk1ndfoNv9EADbKjs.txt` in `boes` und ein
Cronjob mit derselben Zeichenkette als Beschriftung.

| Seite | Theme | `dokument` | `gegenprobe` | `schiebt` | `versteckt` |
|---|---|---|---|---|---|
| Dateien | dunkel | **0** | **200/200** | **`[]`** | 5 |
| Dateien | hell | **0** | **200/200** | **`[]`** | 5 |
| Cron | hell | **0** | **200/200** | **`[]`** | 3 |
| Cron | dunkel | **0** | **200/200** | **`[]`** | 3 |

**`schiebt` ist in allen vier Lagen leer** — nichts läuft über, obwohl auf beiden
Seiten ein Name aus 44 ununterbrochenen Zeichen steht. Die Regel an `.stacks td`
trägt also nicht nur auf `/audit`, sondern auf jeder gestapelten Tabelle.

**Und das Bild sagt, was die Zahl nicht sagt** (`docs/63 §5.3`): Auf der Cronseite
bricht `SHA256-Sn3W6…` **innerhalb seiner Zelle** über drei Zeilen, und die
Beschriftung „BESCHRIFTUNG" links bleibt ganz. Kein Bruch mitten in einem kurzen
Wort — der Preis der Regel wird nicht fällig.

> **Eine Regel, die auf einer Seite gemessen und auf sechzehn wirksam ist, ist
> auf fünfzehn eine Vermutung** — hier auf zweien davon nachgemessen und bestätigt.

`versteckt` (5 auf `/files`, 3 auf `/cron`) zählt die Kästen für die
Vorlesesoftware und ist kein Fund.

---

## 1f. Punkt 5 — die Rechte an `/var/lib/srvpanel`

**Gemessen am 21. August 2026 auf `cloudsrv24` gegen `v0.6.0-rc.24`**, bei
laufenden Diensten und über einen Neustart hinweg:

| | vor dem Neustart | nach dem Neustart |
|---|---|---|
| `/var/lib/srvpanel` | **`750 srvpanel:srvpanel`** | **`750 srvpanel:srvpanel`** |
| `/var/lib/srvpanel/storage` | `700 srvpanel:srvpanel` | `700 srvpanel:srvpanel` |
| `/var/log/srvpanel` | **`750 srvpanel:srvpanel`** | **`750 srvpanel:srvpanel`** |

Beide Dienste `active`, und der Modus stand **vor und nach** dem Neustart gleich.
Vorher zog systemd ihn bei jedem Start auf `755 root:root` (`StateDirectory=` in
einer root-Unit, Befund 1 aus `docs/67`); jetzt nicht mehr.

> **Eine Prüfung, die zum falschen Zeitpunkt misst, misst einen Zustand, den es
> im Betrieb nie gibt** — deshalb der Neustart und nicht ein blosses `stat`.

**Damit ist der Rest aus `docs/61 §0` und `docs/62 §3` auf dem Server
geschlossen** und nicht nur behoben.

---

## 1g. Der Lauf im Ganzen

**Fünf Punkte, alle fünf erfüllt** — und die Rahmenprüfung (Punkt 0) dazu.

| # | Punkt | |
|---|---|---|
| 0 | Die Fassung | **erfüllt** — `v0.6.0-rc.24` |
| 1 | Die Archive durch die echte Route | **erfüllt** — nichts ausserhalb, Gegenprobe trifft |
| 2 | Durch einen Verweis hinaus schreiben | **erfüllt** — Ziel unberührt, Agent sah beide |
| 3 | Die Umbruchregel auf zwei weiteren Seiten | **erfüllt** — `schiebt: []` in vier Lagen |
| 4 | `id` am Vorgang | **erfüllt** — 1002 = 1002, ausserhalb kein `ran_as` |
| 5 | Die Rechte an `/var/lib/srvpanel` | **erfüllt** — `750`, auch nach dem Neustart |

**Was dieser Lauf über sich selbst sagt:** Zwei seiner Befunde stecken im
Prüfmittel, nicht im Panel — der `fetch`, der der Weiterleitung folgte, und die
Variable, die eine Terminalsitzung nicht überlebte (beide in Punkt 2). Kein Panel-
Befund; alle fünf Wände hielten beim ersten scharfen Versuch.

**Damit ist das Abnahmekriterium von P6 durch die echte Route belegt**, und die
vier Reste aus `docs/68 §0` sind geschlossen.

---

## 2. Die Befunde

*(Je Befund: was gesehen wurde, welche Zahl dazugehört, und ob er am Panel, am
Prüfmittel oder am Kriterium liegt. Noch keiner.)*

### Befund 0 — der Wächter über die Dokumente hat diesen Lauf eröffnet

**Er gehört hierher, weil er entstanden ist, bevor der Lauf begann**, und weil
er dieselbe Form hat wie die Befunde, die dieser Lauf sucht.

`docs/68` nannte `docs/69`, und das gab es nicht. `DocLinkTest` hat die CI rot
gemacht — der Wächter für genau den Fehler, der in diesem Projekt am häufigsten
wiederkehrt: *eine Zeichenkette, die auf etwas verweist, ohne dass ein Typ, ein
Test oder ein Werkzeug den Bezug prüft.*

**Und die Behauptung daneben war falsch.** Der Text des Beitrags sagte „Kein
Wächter liest `docs/*.md` — nachgesehen mit `grep -rln "docs/" tests/`". Der
Befehl lief mit `head -20`; er fand **177** Dateien, und `DocLinkTest` steht
nicht unter den ersten zwanzig.

> **Eine abgeschnittene Liste, die man als vollständige liest, ist keine
> Messung, sondern ihre Verkleidung.**

Das ist die vertraute Falle aus einer neuen Richtung: Bisher hiess sie „eine
Null ist nur dann eine Messung, wenn daneben etwas anderes als Null steht". Hier
stand kein Null da, sondern **zwanzig Zeilen, die alle stimmten** — und die
einundzwanzigste war die, auf die es ankam.

> **Ein Ausschnitt, der nicht sagt, dass er einer ist, beantwortet die Frage,
> als wäre er alles.**

**Und die teuerste Hälfte: Der Wächter war hier fahrbar.** `DocLinkTest` erbt
von `PHPUnit\Framework\TestCase` und läuft damit im Gestell dieses Containers,
ohne `vendor/`. Gefahren worden ist er nicht — weil ich an Dokumente gedacht
habe und nicht an die Wächter über Dokumente.

> **Ein Wächter, der die eigene Änderung nicht im Blick hatte, wird nicht
> gefahren — man denkt an das Gebaute und nicht an das Berührte.**

Derselbe Satz steht seit dem 20. August in `CLAUDE.md`, dort über `app.css`.
Hier hat er ein zweites Feld: **Wer `docs/` anfasst, fährt `DocLinkTest`.**

**Behoben:** Diese Datei gibt es jetzt. Der echte Wächter ist im Gestell
gefahren — grün mit ihr, und mit derselben Meldung wie die CI rot, sobald sie
wieder verschwindet:

    ohne docs/69   ROT  docs/68-abschlusslauf-p6.md nennt docs/69, dieses
                        Dokument gibt es nicht.
    mit  docs/69   2 gruen, 0 rot

Der Wächter hat getan, wofür er da ist — an einem Beitrag, der ausdrücklich
behauptet hat, es gebe ihn nicht.

---

## 3. Die Abnahme — die fünfzehn Kriterien mit ihren gemessenen Werten

*(Wie `docs/42` für P5b: jedes Kriterium aus `docs/51 §4`, sein gemessener Wert,
und wo er steht. „echte Route" markiert, was **dieser** Lauf über den Weg der
Operation hinaus belegt hat.)*

| # | Angriff | scharf (gemessen) | stumpf (Gegenprobe) | Fundstelle |
|---|---|---|---|---|
| 1 | `..` in jedem Pfadfeld | dreimal `haelt` (normalisiert) | ohne Chroot: Ausbruch 3/3 | `docs/62 §1` |
| 2 | absoluter Pfad | wie 1 — `haelt` | ohne Chroot: Ausbruch 3/3 | `docs/62 §1` |
| 3 | Symlink lesen | leer, fünf Zeilen `haelt` | ohne Sandbox: Inhalt | `docs/62 §1` |
| 4 | Symlink auflisten | leer | ohne Sandbox: Verzeichnis | `docs/62 §1` |
| 5 | Symlink überschreiben | Ziel **unberührt** | ohne Sandbox: durchgekommen | `docs/62 §1` · **echte Route `docs/69 §1c`** |
| 6 | Der Tausch (Rennen) | **0 Treffer** / 30 000 Runden | stumpf: 6407 / 4409 / 5646 | `docs/62 §1` |
| 7 | Archiv `../` entpacken | nichts ausserhalb, `beweis` drinnen | `tar -xPf`: Datei ausserhalb | `docs/62 §1` · **echte Route `docs/69 §1b`** |
| 8 | Archiv absoluter Pfad | nichts ausserhalb, `beweis` drinnen | `tar -xPf`: Datei ausserhalb | `docs/62 §1` · **echte Route `docs/69 §1b`** |
| 9 | Cron: Zeilenumbruch | Panel-Bauart läuft **nicht** | rohe Zeile läuft | `docs/62 §1` |
| 10 | Cron: `%` im Befehl | bleibt stehen, nicht abgeschnitten | — | `docs/62 §1` |
| 11 | Mandantenübergriff | **404 in allen 22**, kein 2xx | eigene Kennung: 22/22 durch | `docs/62 §1` |
| 12 | Quota voll | keine Erfolgsmeldung | unter 64 MB: gelingt, 2097152/2097152 | `docs/62 §1` |
| 13 | `uid` je Vorgang | `p1136`→1001, `p1138`→1002, `p1139`→**1002** | ausserhalb kein `ran_as` | `docs/62 §1` · **`id` `docs/69 §1d`** |
| 14 | Zusatzgruppen | nur die des Abos (`[1001]`/`[1002]`) | ausserhalb kein `ran_as` | `docs/62 §1` |
| 15 | gültiger Vorgang gelingt | Datei innen unter `uid=1002` | — | `docs/62 §1` |

**Das inhaltliche Kriterium — jeder Angriff scharf abgewiesen, stumpf
durchgekommen — ist damit belegt, und die Punkte 5, 7, 8 und 13 zusätzlich durch
die echte Route.** Was `docs/62 §3` als Rest führte, ist geschlossen.

**Ausdrücklich offen und benannt** (kein Kriterium dieses Laufs, `docs/68 §9`):

- **Wand 2 aus Punkt 11** (`docs/59`) — ein Zusatzbenutzer ohne `ftp_accounts`.
- **Befund 23** (`docs/59`) — der Zeitstempel in der Messvorschrift, fällig beim
  nächsten Journalbeleg.
- **Die neunzehn Griffe** in `RevealTest::UNEXAMINED` und **die Umkehrung der
  Abstandsregel** — beide gehören nicht zu P6 (`docs/51 §13`, `docs/64 §3`).

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.** Diese sind
> benannt und bleiben offen.

---

## 4. P6 ist abgenommen

Alle fünf Punkte dieses Laufs sind erfüllt (§1g), jede Messung trägt ihre
Gegenprobe, jeder Fund liegt im Prüfmittel und nicht im Panel, und die fünfzehn
Kriterien aus `docs/51 §4` stehen oben mit ihren gemessenen Werten. **Damit ist
das Abnahmekriterium von P6 nachweisbar erfüllt** (Plan §8, §9) — gemessen auf
`cloudsrv24` gegen `v0.6.0-rc.24`, nicht geschätzt.

**Abgenommen am 21. August 2026.**

Die vier Stellen, die bis heute das Gegenteil sagten, sind nachgeführt:
`docs/62 §3`, `docs/64 §3`, der Kopf von `CLAUDE.md` und der `CHANGELOG.md`.
