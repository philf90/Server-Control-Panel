# Protokoll: Die Bilderrunde (P7, Schritt 9)

Gefahren ab dem 22. August 2026 auf `cloudsrv24` gegen **`v0.7.0-rc.4`**, vom
MacBook aus. Die Vorschrift steht in `docs/75`; sie ist Schritt 9 aus
`docs/72 §8` und zugleich **Punkt 8 des Abnahmekriteriums** (`docs/72 §3`).

**Dieses Dokument entsteht während des Laufs und nicht danach.** Es ist angelegt
worden, als die erste Lage gemessen war — und nicht vorher: Solange keine
Messung darin steht, ist ein Protokoll eine Gliederung.

**Der Lauf ist nicht abgeschlossen.** Was hier steht, ist gemessen; was fehlt,
steht in §4 als offen und nicht als erledigt.

---

## 1. Die Lagen

Vier Ansichten, je zwei Breiten und zwei Themes. `dokument` ist das Kriterium
und muss `0` sein; `gegenprobe` muss `200/200` lauten, sonst ist die Zeile
ungültig und keine Messung.

| # | Ansicht | Breite | Thema | `stand` | `dokument` | Gegenprobe | Bild |
|---|---|---|---|---|---|---|---|
| 1 | Domain, DNS-Abgleich | 390 | hell | 2026-08-21 | **0** | 200/200 | ✓ (Befund 1) |
| 1 | Domain, DNS-Abgleich | 390 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ (Befund 1) |
| 1 | Domain, DNS-Abgleich | 1440 | hell | 2026-08-21 | **0** | 200/200 | ✓ (Befund 2) |
| 1 | Domain, DNS-Abgleich | 1440 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ (Befund 3) |
| 2 | Einstellungen → Allgemein | 390 | hell | 2026-08-21 | **0** | 200/200 | ✓ |
| 2 | Einstellungen → Allgemein | 390 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ |
| 2 | Einstellungen → Allgemein | 1440 | hell | 2026-08-21 | **0** | 200/200 | ✓ |
| 2 | Einstellungen → Allgemein | 1440 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ |
| 3 | Domainliste `/domains` | 390 | hell | 2026-08-21 | **0** | 200/200 | ✓ (Beobachtung) |
| 3 | Domainliste `/domains` | 390 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ (Beobachtung) |
| 3 | Domainliste `/domains` | 1440 | hell | 2026-08-21 | **0** | 200/200 | ✓ (Beobachtung) |
| 3 | Domainliste `/domains` | 1440 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ (Beobachtung) |
| 4 | Abonnement, „Domains" | 390 | hell | 2026-08-21 | **0** | 200/200 | ✓ |
| 4 | Abonnement, „Domains" | 390 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ |
| 4 | Abonnement, „Domains" | 1440 | hell | 2026-08-21 | **0** | 200/200 | ✓ |
| 4 | Abonnement, „Domains" | 1440 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ |

### Die Einträge in `schiebt` und `rollt`

`schiebt` ist ein Hinweis und kein Urteil (`docs/75 §5`); jeder Eintrag wird
einzeln benannt.

| Lage | Eintrag | gewollt oder Fund |
|---|---|---|
| 1 / 390 / hell | keiner | — |
| 1 / 390 / dunkel | keiner | — |
| 1 / 1440 / hell | keiner | — |
| 1 / 1440 / dunkel | keiner | — |
| 2 / alle vier | keiner | — |
| 3 / alle vier | keiner | — |
| 4 / alle vier | keiner | — |

**`rollt` ist bei 390 px leer, und das ist richtig.** Die Tabellen stehen dort
als Kärtchen; ein Rollbehälter ist gar nicht aktiv. `docs/63 §6` hält das als
Falle fest — eine leere Liste sieht dort nach einer kaputten Messung aus und
ist keine.

**`versteckt: 4`** in Lage 1/390/hell sind die vier `.stacks thead`. Sie tragen
`position: absolute; width: 1px; clip-path: inset(50%)`, damit der Screenreader
die Spaltenüberschriften behält — ein Mechanismus, kein Fehler. Das Messmittel
zählt sie getrennt, statt sie in `schiebt` zu führen.

---

## 2. Befunde

### Befund 1 — die IPv6 bricht mitten im Hextet

Gefunden in Lage 1/390/hell, **ohne dass eine Zahl darauf hinweist**:
`dokument: 0`, `schiebt: []`, Gegenprobe `200/200`. Die Zeile „Erwartet" der
`AAAA`-Karte steht so da:

```
2a0a:4cc0:c1:ebd1:b82d:51ff:f
e72:3083
```

Der Umbruch trennt **`fe72` in `f` und `e72`**. Das ist `overflow-wrap: anywhere`
an `.ident`, und die Regel ist an sich richtig: Ohne sie schöbe die Adresse die
Seite aus dem Bild — genau der Befund aus `docs/67`. Sie kennt nur keine
bevorzugte Trennstelle.

**Warum das mehr ist als hässlich.** Eine IPv6 wird an Doppelpunkten gelesen.
Eine Trennung *innerhalb* einer Gruppe erzeugt zwei Zeichenfolgen, die beide wie
gültige Gruppen aussehen (`f` und `e72`), und der Leser zählt acht Gruppen, wo
sieben stehen. Die Frage dieses Bereichs lautet „ist das dieselbe Adresse wie
erwartet?" — und sie wird zeichenweise beantwortet.

> **Ein Umbruch ohne bevorzugte Stelle bricht dort, wo es passt, und nicht dort,
> wo man liest.**

**Der Weg:** eine Umbruchgelegenheit nach jedem Doppelpunkt (`<wbr>`), damit der
Browser zuerst dort trennt und `anywhere` nur noch der Rückfall für den Fall
ist, dass auch das nicht reicht. Das ist keine neue Regel, sondern eine
Verfeinerung der bestehenden.

**Nicht behoben.** Er wird mit den übrigen Funden am Ende des Laufs behoben und
danach nachgemessen — eine Behebung mitten im Lauf kostet eine Fassung und macht
jedes Bild davor zu einem Andenken.

**In beiden Themes gleich** (390/hell und 390/dunkel, je `dokument: 0`). Damit
ist er eine Eigenschaft der Geometrie und nicht der Farbe — erwartet, aber jetzt
belegt statt angenommen. Genau dafür wird jede Lage einzeln gemessen: Ein Theme
wechselt Ränder und Schriftgrade mit, und das war in diesem Projekt schon
zweimal genau der Unterschied.

### Befund 2 — zwei Namen, getrennt durch ein Leerzeichen

Gefunden in Lage 1/1440/hell. Im Bereich „Zertifikat" steht unter dem Kästchen
„Als Platzhalter bestellen":

```
Ein Zertifikat für *.cloudlab24.de cloudlab24.de — es gilt für jede
Unterdomain dieser Zone …
```

`*.cloudlab24.de cloudlab24.de` sind **zwei** Namen. Getrennt sind sie nur durch
ein Leerzeichen, und beide enthalten Punkte — es gibt für den Leser kein
Zeichen, an dem der eine aufhört und der andere anfängt. Wer das liest, sieht
einen kaputten Namen, keine Liste.

**Dieselbe Datei kennt es besser.** In `Domains/Show.vue` stehen beide
Schreibweisen nebeneinander:

| Zeile | Gegenstand | Trennung |
|---|---|---|
| 420 | `certificate.names` | `, ` |
| 633 / 641 | `expected` / `found` | `, ` |
| 659 | `nameservers` | `, ` |
| 715 / 717 | `override` / `derived` | `, ` |
| **474** | **`wildcard.names`** | **Leerzeichen** |
| **516** | **`wildcard.uncovered`** | **Leerzeichen** |
| **694** | **`unasked`** | **Leerzeichen** |

> **Zwei Schreibweisen für dieselbe Sache in einer Datei sind keine Wahl,
> sondern ein Versehen — und die seltenere ist die, die niemand gegenprüft.**

**Bei 390 px wird es schlimmer, nicht besser.** `.ident` trägt dort
`overflow-wrap: anywhere` (siehe Befund 1). Der Umbruch darf dann **innerhalb**
eines Namens fallen, und das Leerzeichen ist nicht mehr die einzige, sondern
eine von vielen Trennstellen — der letzte Hinweis auf die Grenze verschwindet.

**Der Weg:** `join(', ')` überall dort, wo eine Liste von Namen oder Adressen
steht. Betroffen sind die drei Stellen oben, dazu `Settings/General.vue` (132,
136) und `Components/DnsCredentials.vue` (207). `Cron.vue` und `Tile.vue`
benutzen `join(' ')` für einen Cron-Ausdruck und einen SVG-Pfad — die bleiben.

**Nicht behoben**, aus demselben Grund wie Befund 1.

### Befund 3 — ein Kästchen, das nicht klickt und aussieht, als täte es das

**Gemeldet vom Betreiber während des Laufs**, in Lage 1/1440/dunkel: „Das
Kästchen ‚Als Platzhalter bestellen' lässt sich gerade gar nicht anklicken."

**Dass es nicht klickt, ist richtig.** Am Eingabefeld steht
`:disabled="!props.wildcard.possible"`, und `possible` ist falsch, weil für
diese Domain keine DNS-Zugangsdaten hinterlegt sind. Ein Platzhalter geht nur
über DNS-01, DNS-01 nur mit Zugangsdaten. Der Satz steht sogar daneben.

**Der Fund ist die Anzeige.** Der Betreiber, der dieses System gebaut hat, hat
den Zustand nicht erkannt. Drei Ursachen, alle in `app.css`:

1. **`.toggle` setzt `cursor: pointer` unbedingt.** Die Zeile zeigt den
   Zeigefinger auch dann, wenn nichts passiert.
2. **`.toggle` kennt gar keinen abgeschalteten Zustand.** Die Beschriftung
   bleibt in voller `--text-strong`-Farbe; blass ist nur das Kästchen selbst,
   und das rendert der Browser.
3. **Der Grund sieht nicht aus wie einer.** „Für diese Domain sind keine
   DNS-Zugangsdaten hinterlegt" ist der **dritte** `.hint` unter dem Kästchen,
   in derselben Grösse und Farbe wie die zwei erklärenden davor. Er liest sich
   als weitere Erklärung.

**Dasselbe Stylesheet kennt die Lösung — für Felder**, mit eigener Begründung
im Kommentar:

```css
.field input:disabled { color: var(--text-muted); background: var(--surface);
                        border-style: dashed; cursor: default; }
```

> **Eine Regel, die für ein Feld gilt, gilt nicht für den Schalter daneben,
> bloss weil sie dieselbe ist.**

Derselbe Satz wie bei `SettingsWriterReachTest` am selben Tag: Dort war die
Regel für den Agenten aufgeschrieben und für `Settings` nicht.

> **Ein Bedienelement, das nicht bedienbar ist und trotzdem den Zeigefinger
> zeigt, sagt dem Kunden, er habe falsch geklickt.**

**Der Weg:** `.toggle:has(input:disabled)` bekommt die Behandlung, die `.field`
schon hat — gedämpfte Schrift, `cursor: default`. Und der Hinderungsgrund
gehört von den erklärenden `.hint` abgesetzt, damit er als Sperre und nicht als
Fussnote liest. Ein Wächter darüber, dass ein abschaltbares Bedienelement einen
sichtbaren Aus-Zustand hat, gehört dazu.

**Der Betreiber hat entschieden, dass er gebaut wird** — am 22. August, im Lauf:
„Wir haben einen Befund, der nachträglich gebaut bzw. korrigiert werden muss,
damit die Darstellung deutlicher wird." Behoben wird er mit den übrigen am Ende,
aus demselben Grund wie Befund 1 und 2.

**Und er hat ihn gegengeprüft, indem er die Ursache wegnahm:** Zugangsdaten
hinterlegt, und das Kästchen klickt. Damit ist belegt, dass die Sperre an
`possible` hängt und nicht an einem kaputten Bedienelement — die Behebung
betrifft die Anzeige und nicht die Logik.

> **Eine Sperre, die man aufhebt, statt sie zu umgehen, sagt einem, ob man die
> richtige gefunden hat.**

**Und die Lehre über den Lauf hinaus.** `dokument: 0`, `schiebt: []`,
Gegenprobe `200/200` — vier Lagen lang. Kein Messmittel dieses Projekts hätte
das gefunden; gefunden hat es jemand, der klicken wollte.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.** Und diesmal nicht einmal einen Betrachter, sondern einen
> Benutzer: Ansehen genügte nicht, es musste jemand hingreifen.

### Was in dieser Lage richtig war

- Vier Zeilen des Bereichs, Zustand als Marke mit Wort und Punkt, „Erwartet"
  und „Gefunden" nebeneinander.
- Der Zeitpunkt steht dabei (`2026-08-22 18:01:29`) — und er rechnet in der
  **eingestellten** Anzeigezone: 18:01 CEST gegen 16:01 UTC zur Aufnahmezeit.
  Damit ist Befund 3 aus `docs/74` auf dem Server nachgesehen und nicht nur
  behoben.
- Die CAA-Meldung steht dreizeilig und lesbar in einem `.notice warn`, mit dem
  Namen als `.ident` **innerhalb** des Satzes.
- Die ganzseitige Aufnahme zeigt die komplette Seite — Banner, Stammdaten,
  Zertifikat, DNS-Abgleich, Eigenes Zertifikat, Vorgänge, Verzeichnis und
  Handler, PHP-Einstellungen, nginx-Direktiven. Die Bereiche stehen getrennt,
  keiner klebt am nächsten, nichts ragt heraus.

**Und bei 1440 px zusätzlich:**

- **Befund 1 tritt dort nicht auf.** Die IPv6 steht in einer Zeile:
  `2a0a:4cc0:c1:ebd1:b82d:51ff:fe72:3083`, in „Erwartet" wie in „Gefunden". Er
  ist damit ein Fall der schmalen Breite und nicht der Regel.
- **`versteckt: 0`**, wo bei 390 px vier standen. Richtig: Bei dieser Breite ist
  `.stacks` eine echte Tabelle, ihre Spaltenüberschriften sind sichtbar und
  müssen nicht für den Screenreader aus dem Bild genommen werden.
- Die Seite steht zweispaltig (Stammdaten neben Zertifikat, Eigenes Zertifikat
  neben Vorgängen, Verzeichnis neben PHP-Einstellungen), das Rail links, und die
  Fassung `0.7.0-rc.4` steht an zwei Stellen im Bild — oben am Rail und unten
  als „Quelltext".

**Ein Fund ist vor dem Lauf entstanden** und steht deshalb nicht hier, sondern
in `docs/75 §1.1`: Die Meldung „nicht gefragt" stand als drei Flexkinder in
einer `.notice` und brach bei 390 px Zeichen für Zeichen. Gefunden hat ihn die
Vormessung im Container, behoben ist er seit `v0.7.0-rc.3`, und `NoticeChildrenTest`
hält die Regel.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

### Ansicht 2 — kein Fund

Alle vier Lagen `dokument: 0`, Gegenprobe `200/200`, `schiebt` und `rollt` leer,
`versteckt: 0`. Der Bereich „Adressen dieses Servers" trägt in beiden Breiten
und beiden Themes.

- **Das Paar ist als Paar lesbar.** „Abgeleitet" bricht rechtsbündig auf zwei
  Zeilen (IPv4 über IPv6), „Verglichen wird gegen" steht darunter mit
  `203.0.113.10`. Der Unterschied springt ins Auge — das ist der ganze Zweck
  des Bereichs (`docs/72 §2.1a`).
- **Nebenbeleg für `docs/40`:** „Gespeichert `2026-08-22 18:20:43 UTC`" über
  „Angezeigt `2026-08-22 20:20:43 CEST (UTC+02:00)`". Dieselbe Sekunde, zweimal,
  mit der Zone dabei — die Anzeigezeit ist im Bild belegt und nicht nur im Code.
- **`versteckt: 0` auch bei 390 px**, anders als in Ansicht 1. Richtig:
  `.pairs` ist keine `.stacks`, es gibt hier keinen weggeklippten `thead`.

**Befund 1 tritt hier nicht auf.** Dieselbe IPv6 steht bei 390 px in **einer**
Zeile. Die `.pairs`-Zelle ist breiter als die Wertspalte einer `.stacks`-Karte —
der Befund hängt also an der Karte und nicht an der Adresse.

> **Derselbe Wert an zwei Stellen bricht verschieden, und nur eine davon ist
> ein Fund.**

**Befund 2 tritt auf, aber folgenlos.** „Abgeleitet" benutzt dasselbe
`join(' ')` (`General.vue:132`). Hier schadet es nicht: IPv4 und IPv6
unterscheiden sich auf den ersten Blick, und die Zeile bricht am Leerzeichen in
zwei. Die Inkonsistenz bleibt und wird mitbehoben; die *Verwechslungsgefahr*
gibt es nur dort, wo zwei gleichartige Namen nebeneinanderstehen.

### Ansicht 3 — kein Fund, aber eine Spalte ohne Auskunft

Alle vier Lagen `dokument: 0`, Gegenprobe `200/200`, `schiebt` und `rollt` leer.
`versteckt: 2` bei 390 px (die beiden `.stacks thead` der Liste), `0` bei 1440.

**Die neue Spalte trägt.** Sechs Spalten statt fünf bei 1440 px, ohne dass
etwas schiebt; bei 390 px sechs Zeilen je Kärtchen, DNS vor Zustand. Die beiden
Marken stehen nebeneinander und sind auseinanderzuhalten.

**Und trotzdem sagt sie an diesem Tag nichts.** Alle vier Domains tragen
„nachsehen":

| Domain | warum |
|---|---|
| `cloudlab24.de` | **allein wegen des CAA aus §2.2 dieser Vorschrift** |
| `cloudlab24.ipv64.de` | `A` zeigt woandershin, `AAAA` fehlt |
| `p6-abnahme.invalid` | `.invalid`, nie gefragt |
| `p6-b.invalid` | `.invalid`, nie gefragt |

Die Spalte ist damit **richtig und nutzlos zugleich**: Sie sagt viermal
dasselbe, und wer sie so zum ersten Mal sieht, hält sie für Zierde.

> **Eine Spalte, in der alle Zeilen dasselbe sagen, belegt nicht, dass sie
> unterscheiden kann.**

Der Prüfkörper, den `docs/75 §3` verlangt — „alle drei Marken nebeneinander" —,
ist damit **nicht** erfüllt. Er wird es, sobald das CAA weg ist: Dann fällt
`cloudlab24.de` auf „in Ordnung", und eine grüne Marke steht neben drei gelben.
„ungeprüft" braucht zusätzlich eine frisch angelegte Domain.

**Bemerkenswert daran ist der Grund.** Der Zustand, den §2.2 zum Prüfen
*hergestellt* hat, hat einen anderen Prüfkörper *verdeckt* — und beide stehen
in derselben Vorschrift.

> **Ein hergestellter Zustand ist auch ein weggenommener.**

**Und es waren zwei, nicht einer.** Beim Ausschreiben dieses Abschnitts stand
hier zuerst, das CAA sei der einzige Grund für das Gelb an `cloudlab24.de` — und
der nächste Schritt lautete „CAA entfernen, dann `Jetzt prüfen` drücken".

Der Betreiber hat den zweiten gefunden, bevor der Schritt gefahren wurde: Die
Übersteuerung `203.0.113.10` aus §2.3 stand noch. Mit ihr ist
`effective = [203.0.113.10]`, jeder `A`-Satz wird dagegen gehalten, und
`cloudlab24.de` wäre von „nachsehen (CAA)" auf „nachsehen (zeigt woandershin)"
gewechselt — dieselbe Farbe, anderer Grund, derselbe verdeckte Prüfkörper.

**Die Regel dazu stand bereits in `docs/75 §2.3`**, wörtlich: „Danach wieder
leeren. Solange der Eintrag steht, meldet der Abgleich jede Domain als ‚Zeigt
woandershin'." Sie war aufgeschrieben und wurde beim nächsten Schritt nicht
angewandt.

> **Eine Vorschrift, die man selbst geschrieben hat, liest man beim Ausführen
> genauso wenig wie jede andere.**

**Und die Folge wäre grösser gewesen als „eine Zeile wird gelb".**
`DesiredRecords` erzeugt ohne IPv6 des Servers **gar keinen `AAAA`-Eintrag**
(Kriterium 5 aus `docs/72 §3`). Bei einer reinen IPv4-Übersteuerung verschwindet
die `AAAA`-Zeile aus der Tabelle, statt auf „Fehlt" zu fallen — die Ansicht hätte
also nicht nur eine andere Farbe getragen, sondern eine Zeile weniger.

**Reihenfolge deshalb:** erst das Feld leeren, dann prüfen, dann aufnehmen. Wer
zuerst prüft, prüft gegen die Adresse, die er gerade wegnehmen wollte.

**Gefahren und belegt.** Nach dem Leeren des Feldes und einem Lauf über „Jetzt
prüfen" steht `cloudlab24.de` auf **„in Ordnung"** und `cloudlab24.ipv64.de` auf
„nachsehen" — grün neben gelb, in derselben Liste (390 px hell, `dokument: 0`,
Gegenprobe `200/200`, `versteckt: 2`). Die Spalte unterscheidet sichtbar.

**Die Aufnahme entstand in der Kundensicht** und nicht als Betreiber: „3
insgesamt" statt „4", dazu ein Knopf „Domain anlegen". Beides ist richtig — der
Betreiber bekommt den Knopf bewusst nicht (`creatable` ist für ihn leer, weil
eine Auswahl über Hunderte Abonnements kein kurzer Weg wäre), und
`p6-abnahme.invalid` gehört einem anderen Abonnement. Es steht hier, damit
niemand später die vierte Domain für verschwunden hält.

> **Zwei Aufnahmen derselben Adresse können verschiedene Seiten zeigen, wenn
> zwischen ihnen die Sicht gewechselt hat.**

### Ansicht 4 — kein Fund

Alle vier Lagen `dokument: 0`, Gegenprobe `200/200`, `schiebt` und `rollt` leer;
`versteckt: 4` bei 390 px, `0` bei 1440.

- **Fünf Spalten statt sechs** — ohne „Abonnement", weil man schon darin ist.
  Bei 390 px fünf Zeilen je Kärtchen.
- **Grün neben Gelb auch hier:** `cloudlab24.de` trägt „in Ordnung",
  `p6-b.invalid` und `cloudlab24.ipv64.de` tragen „nachsehen".
- **Die Reihenfolge stimmt.** `p6-b.invalid` als Hauptdomain steht oben, die
  beiden Zusatzdomains darunter — das ist
  `orderByRaw("case when type = \'main\' then 0 else 1 end")`, im Bild belegt
  statt im Test behauptet.

---

## 2a. Der Stand nach dem Lauf

**Sechzehn von sechzehn Lagen gemessen. Kein `dokument` ungleich `0`, keine
Gegenprobe ungleich `200/200`, kein einziger Eintrag in `schiebt`.**

**Punkt 8 des Abnahmekriteriums** (`docs/72 §3`) — „bei 390 px läuft nichts
über, in beiden Themes" — ist damit erfüllt und gemessen.

**Und drei Befunde, keinen davon hat eine Zahl gefunden.** Alle drei traten in
Lagen auf, die vollständig grün gemessen waren:

| # | Befund | gefunden von | gefunden in |
|---|---|---|---|
| 1 | IPv6 bricht mitten im Hextet | Betrachter | 1/390, beide Themes |
| 2 | Zwei Namen, getrennt durch ein Leerzeichen | Betrachter | 1/1440/hell |
| 3 | Kästchen ohne sichtbaren Aus-Zustand | **Benutzer** | 1/1440/dunkel |

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.** Und bei Befund 3 nicht einmal den: Ansehen genügte nicht, es
> musste jemand hingreifen.

**Alle drei sitzen in Ansicht 1.** Die Ansichten 2 bis 4 sind ohne Fund
geblieben — sie sind auch die jüngeren und die einfacheren. Ansicht 1 trägt
fünf Bereiche, drei Meldungssorten und eine Tabelle mit fünf Spalten; die
anderen tragen je eine Liste.

> **Die Ansicht mit den meisten Zuständen hat die meisten Fehler, und das ist
> keine Überraschung — es ist der Grund, sie zuerst zu messen.**

---

## 3. Beobachtungen ohne Fund

Was aufgefallen ist, ohne eine Regel zu verletzen. Es steht hier, damit es beim
nächsten Mal nicht neu auffällt und für eine Entdeckung gehalten wird.

**„gefragt wurden" nennt Adressen statt Namen.** Auf der Seite steht
`167.235.231.182, 159.69.110.93`, während `docs/74 §6` an derselben Stelle
`ns1/ns2.ipv64.net` gelesen hat. Beides ist wahr — der Auflöser fragt Adressen,
und die Delegation nennt Namen. Ob die Anzeige den Namen tragen sollte, ist eine
Frage an den Betreiber und kein Fehler.

---

## 4. Was offen ist

- **Die drei Befunde sind nicht behoben.** Sie kommen zusammen dran, mit je
  einem Wächter und einem Bruch, und werden danach nachgemessen.
- **Die Marke „ungeprüft" ist nicht aufgenommen**, und sie ist **flüchtig**:
  `srvpanel-dns.timer` läuft alle 15 Minuten, also ist jede Domain spätestens
  nach einer Viertelstunde geprüft. Den Zustand gibt es nur im Fenster zwischen
  Anlegen und erstem Lauf.

  **Ihn herzustellen ist nicht umsonst.** Die Automatik bestellt ein Zertifikat,
  sobald der Server-Block steht; für einen Namen, der nicht auf den Server
  zeigt, scheitert das und verbraucht einen der fünf Fehlversuche je Stunde, die
  für alle Kunden dieses Servers zusammen gelten. Er gehört deshalb in den
  Abnahmelauf, wo ohnehin eine Domain angelegt wird (Kriterium 1) — dort fällt
  er kostenlos ab.

  > **Ein Zustand, dessen Herstellung ein Kontingent kostet, wird dort geprüft,
  > wo er ohnehin entsteht.**
- **Kriterium 5 liesse sich nebenbei belegen** und ist es nicht: Mit einer
  reinen IPv4-Übersteuerung verschwindet die `AAAA`-Zeile, statt auf „Fehlt" zu
  fallen. Das ist genau die Zusage aus `docs/72 §3` — sie gehört in den
  Abnahmelauf, nicht in diesen.
- **Die Konsole ist nicht gelesen worden.** In den Aufnahmen zu Ansicht 2
  zählten die Entwicklerwerkzeuge zwölf Fehler und fünfunddreissig Warnungen;
  ein Teil kommt sichtbar aus einer Browsererweiterung (`background.js`,
  `[AppIntegration]`). Auf Ansicht 3 steht dagegen **ein** Fehler und „No
  Issues" — die hohen Zahlen hingen also an jener Seite oder an dem, was sich
  bis dahin angesammelt hatte. Ob **einer** davon aus dem Panel stammt, ist
  ungeprüft.

  > **Ein Fehler in der Konsole erzeugt keinen Überlauf und steht auf keinem
  > Bild.** Er ist der dritte Kanal neben Zahl und Betrachter, und dieser Lauf
  > hat ihn bisher nicht benutzt.
- **Die Regel `.toggle + .button-row` ist weiterhin unbelegt**, und der Versuch,
  sie zu belegen, hat gezeigt warum.

  Nachdem Zugangsdaten hinterlegt waren, liess sich das Kästchen ankreuzen und
  der Knopf „Platzhalter bestellen" erschien mit sichtbarem Abstand darüber
  (1440/hell, `dokument: 0`, Gegenprobe `200/200`). **Der Abstand stammt aber
  nicht aus der Regel.** Zwischen beiden steht ein dritter Baustein:

  ```
  <label class="toggle">…</label>
  <p class="section-note">Das bestehende Zertifikat bleibt liegen. …</p>
  <div class="button-row"><button>Platzhalter bestellen</button></div>
  ```

  `.toggle + .button-row` ist ein **direkter** Nachbarschaftsselektor; mit dem
  `.section-note` dazwischen greift er nicht. Was man sieht, ist der Rand dieses
  Absatzes — wörtlich der Zustand, den der Kommentar in `app.css` als Ursache
  des ursprünglichen Befundes benennt.

  > **Ein Abstand, der richtig aussieht, ist noch kein Beleg dafür, dass die
  > Regel greift, die ihn erzeugen sollte.**

  Der Zustand, in dem sie greift, ist eine Domain **ohne** Zertifikat: Dann
  entfällt der `.section-note` (`v-if="alsPlatzhalter && props.certificate"`),
  und der Knopf steht unmittelbar unter dem Kästchen. Er gehört in den
  Abnahmelauf.

- **Auf `cloudlab24.de` sind seit dem 22. August DNS-Zugangsdaten hinterlegt**,
  vom Betreiber im Lauf gesetzt, um Befund 3 gegenzuprüfen. Alle Aufnahmen
  danach zeigen die Seite **ohne** den Hinderungsgrund unter dem Kästchen. Wer
  spätere Bilder mit den früheren vergleicht, findet dort eine Zeile weniger und
  keinen Fehler.

  > **Ein Zustand, den man zum Prüfen herstellt, bleibt hergestellt, bis ihn
  > jemand zurücknimmt.**
- **Die Behebung aus `docs/75 §1.1` ist noch nicht nachgesehen.** Der Fund war
  die Meldung „nicht gefragt", und die erscheint nur an einer Domain, deren
  Nameserver niemand erreicht — `cloudlab24.de` hat welche. Sie gehört an
  `p6-abnahme.invalid` aufgenommen.

  > **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
  > jemand ihn behoben hat.** Dass die CAA-Meldung daneben richtig steht, sagt
  > über die andere nichts: Sie hatte die Form schon vorher.

- **Die erste Aufnahme deckte den Gegenstand nicht ab.** Sie zeigte den oberen
  Teil der Seite (Stammdaten, Zertifikat) und nicht den DNS-Abgleich; die
  zweite hat ihn. Die Messung war beide Male dieselbe und beide Male gültig.

  > **Ein Prüfkörper, der die Seite ohne den Gegenstand misst, misst die Seite
  > und nicht den Gegenstand.** Derselbe Satz wie in `docs/62` Punkt 12, dort
  > an einer Messung, hier an einer Aufnahme.

- **Die Zustände aus `docs/75 §3`** — noch keiner aufgenommen.
- **Die beiden Zustände aus `docs/75 §2.4`**, die sich nicht herstellen lassen:
  „Nameserver uneinig" und „kein Sollzustand bekannt". Sie bleiben ungemessen
  und wandern benannt in den Abnahmelauf.
- **Der CAA-Eintrag steht noch** (`cloudlab24.de. CAA 0 issue "digicert.com"`,
  gesetzt am 22. August, per `dig` gegen `ns1.ipv64.net` bestätigt). Er gehört
  nach dem Lauf entfernt: Solange er steht, scheitert jede echte Bestellung für
  diese Domain, und jeder Fehlversuch zählt gegen die fünf je Stunde, die für
  alle Kunden dieses Servers zusammen gelten.
