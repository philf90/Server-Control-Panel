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
| 1 · rc.5 | Domain, DNS-Abgleich | 390 | hell | 2026-08-21 | **0** | 200/200 | ✓ (1 und 2 behoben) |
| 1 · rc.5 | Domain, DNS-Abgleich | 390 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ |
| 1 · rc.5 | Domain, DNS-Abgleich | 1440 | hell | 2026-08-21 | **0** | 200/200 | ✓ (Befund 4) |
| 1 · rc.5 | Domain, DNS-Abgleich | 1440 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ (Befund 4) |
| 1 · rc.5 ohne Zugangsdaten | Domain, Zertifikat | 390 | hell | 2026-08-21 | **0** | 200/200 | ✓ (Befund 5) |
| 1 · rc.5 ohne Zugangsdaten | Domain, Zertifikat | 390 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ (Befund 5) |
| 1 · rc.5 ohne Zugangsdaten | Domain, Zertifikat | 1440 | hell | 2026-08-21 | **0** | 200/200 | ✓ |
| 1 · rc.5 ohne Zugangsdaten | Domain, Zertifikat | 1440 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ |
| 1 · rc.6 | Domain, ganze Seite | 390 | hell | 2026-08-21 | **0** | 200/200 | ✓ (4b, 5, 6, Marke) |
| 1 · rc.6 | Domain, ganze Seite | 390 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ |
| 1 · rc.6 | Domain, ganze Seite | 1440 | hell | 2026-08-21 | **0** | 200/200 | ✓ (4a, Befund 7) |
| 1 · rc.6 | Domain, ganze Seite | 1440 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ |
| 1 · rc.7 | Domain, ganze Seite | 1440 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ (Befund 7 erfüllt) |
| 1 · rc.7 | Domain, ganze Seite | 390 | hell | 2026-08-21 | **0** | 200/200 | ✓ (unverändert) |
| 3 · rc.7 | Domainliste `/domains` | 390 | hell | 2026-08-21 | **0** | 200/200 | ✓ (Befund 8) |
| 5 · rc.7 | Datenbankliste `/databases` | 390 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ (Befund 8) |
| 6 · rc.7 | Übersicht `/`, Seitenkopf | 390 | hell | 2026-08-21 | **0** | 200/200 | ✓ |
| 6 · rc.7 | Übersicht `/`, Seitenkopf | 390 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ |
| 3 · rc.8 | Domainliste `/domains` | 390 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ (Befund 8 erfüllt) |
| 5 · rc.8 | Datenbankliste `/databases` | 390 | dunkel | 2026-08-21 | **0** | 200/200 | ✓ (Befund 8 erfüllt) |
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

**Behoben am 22. August**, nach dem Lauf und nicht mittendrin: Eine Behebung
zwischen zwei Lagen kostet eine Fassung und macht jedes Bild davor zu einem
Andenken. `Idents.vue` ist die Stelle, `IdentListTest` der Wächter.

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

**Behoben**, an derselben Stelle wie Befund 1 — es ist dieselbe Ursache. Beim
Beheben kamen **vier weitere Fundstellen** dazu, die der erste Durchgang
übersehen hatte: `Settings/Php.vue` (zweimal) und `Settings/Tls.vue` (zweimal,
mit Domainnamen und IP-Adressen). Sie standen nicht in der Datei, in der der
Befund entdeckt worden war.

> **Ein Befund, den man an der Fundstelle behebt, ist an den anderen
> Fundstellen nicht behoben.**

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

**Behoben am 22. August.** `.toggle:has(input:disabled)` bekommt, was
`.field input:disabled` seit Monaten hat — gedämpfte Schrift und
`cursor: default`; der Hinderungsgrund steht als `.obstacle` in `--warn` und
damit als einziger nicht blasser Satz der Zeile. `DisabledStateTest` ist der
Wächter, und er zählt die Hüllen aus den Vorlagen auf, statt sie aufzuschreiben.

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


### Befund 4 — die Behebung von Befund 1 und 2 zerschneidet eine Adresse

Gefunden am 23. August auf `cloudsrv24` gegen **`v0.7.0-rc.5`**, beim Nachsehen
von Befund 1 und 2 — also an genau der Behebung, die dieser Lauf prüfen sollte.
Wieder ohne eine Zahl: `dokument: 0`, `schiebt: []`, Gegenprobe `200/200` in
allen vier Lagen.

Unter dem DNS-Abgleich stand bei 1440 px:

```
Zuletzt geprüft: 2026-08-23 06:45:47 · gefragt wurden 167.235.231.182, 159.69.
110.93
```

Die zweite Adresse ist mitten durchgebrochen. Vor der Behebung brach diese Zeile
am Leerzeichen hinter dem Komma, und beide Adressen blieben ganz.

**Der Mechanismus.** Eine Umbruchgelegenheit ist keine Empfehlung, sondern eine
Stelle wie jedes Leerzeichen. Der Zeilenumbruch nimmt die **letzte, die noch
passt** — und das ist die im Inneren des Wertes, sobald sie weiter rechts steht
als das Leerzeichen davor.

> **Eine Umbruchgelegenheit bricht, sobald es passt. `overflow-wrap: anywhere`
> bricht nur, wenn es sein muss.**

**Zwei Breiten beantworten das nicht.** Bei 390 px und bei 1440 px sah die
Fundstelle von Befund 2 in beiden Fassungen **gleich** aus; der Schaden liegt
dazwischen und an anderen Fundstellen. Gemessen wurde deshalb der ganze Bereich
von 320 bis 1600 px in Vierer-Schritten — 321 Breiten je Fall und Fassung, mit
`tests/umbruch-messen.mjs` und `tests/umbruch-faelle.json`. Gezählt ist, bei wie
vielen Breiten ein Wert über zwei Zeilen geht:

| Fundstelle | ohne `<wbr>` | mit `<wbr>` |
|---|---|---|
| 660 `.section-note` „gefragt wurden" | **0** | **291** |
| 517 `.section-note` + `.ident` „ungedeckt" | **0** | **289** |
| 717 `.notice warn` Adressen | **0** | 14 (320–372) |
| 695 `.notice warn` + `.ident` „nicht gefragt" | **0** | 11 (320–360) |
| 475 `.hint` + `.ident` Platzhalter | **0** | 4 (**380–392**) |
| 634 `td.ident` — Zelle, Wert allein | 6 (320–340) | 6 (320–340) |

Zwei Dinge stehen darin. **In jedem Satz** macht die Gelegenheit es schlechter,
und zwar von „nie getrennt" auf bis zu 291 von 321 Breiten. **In der Zelle**
ändert sie die Anzahl nicht — sie ändert nur den Ort, und genau dafür ist sie
gebaut.

Und die vierte Zeile ist die teuerste: Der Bereich 380–392 px enthält die
**390 px**, mit denen dieser Lauf misst. Der Satz unter „Als Platzhalter
bestellen" — die Fundstelle von Befund 2 — war durch seine eigene Behebung an
genau dieser Breite zerschnitten, und in den Aufnahmen von Lage 1/390 war er
nicht im Bild.

> **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass zu
> messen.**

> **Eine Frage, deren Antwort an der Breite hängt, ist mit zwei Breiten nicht
> beantwortet.**

**Behoben.** Neun Fundstellen in `Domains/Show.vue`, `Settings/Php.vue` und
`Settings/Tls.vue` stehen wieder als `join(', ')` — das Komma aus Befund 2
bleibt, die Umbruchgelegenheit geht. `<Idents>` bleibt an den acht Stellen, an
denen der Wert allein in seiner Zelle steht. `.section-note` bekommt
`overflow-wrap: anywhere`: Das bricht nur, wenn ein Wert auf keine Zeile passt,
und fängt damit den Fall ab, für den die Gelegenheit dort einmal gedacht war.

**Und der Wächter von gestern war zu breit gezogen.** `IdentListTest` verbot
*jedes* `join()` in einer `.ident` — nach dieser Messung stehen dort sechs
richtige. Er verbietet jetzt das blosse Leerzeichen, also das, was Befund 2
wirklich gekostet hat. Die Platzierung hält `IdentPlacementTest`.

> **Eine Regel, die mehr verbietet als ihr Befund hergibt, steht dem nächsten
> Befund im Weg.**

**Die Sonde hat sich zweimal selbst geprüft.** Ihre erste Fassung zählte die
Client-Rechtecke des Elements — ein `<wbr>` ist aber ein Element und zerteilt
diese Liste, also meldete sie für **jede** Breite einen Bruch, auch für 1600.

> **Eine Sonde, die für jede Eingabe dasselbe sagt, hat nichts gemessen.**

Gemessen wird seitdem an den Zeichen. Und `tests/umbruch-faelle.json` trägt
einen Fall `kontrolle` mit einem Wert, der auf keine Zeile passt: Bricht der
nicht, endet das Skript mit Rückgabewert 1, und keine Null daneben bedeutet
etwas.


### Befund 5 — die Behebung von Befund 3 gilt nicht

Gefunden am 23. August auf `cloudsrv24` gegen **`v0.7.0-rc.5`**, beim Nachsehen
von Befund 3 — und damit zum zweiten Mal an einer Behebung dieses Laufs. Wieder
ohne eine Zahl: `dokument: 0`, `schiebt: []`, Gegenprobe `200/200` in allen vier
Lagen.

Zwei der drei Teile von Befund 3 stimmen: Die Beschriftung „Als Platzhalter
bestellen" steht blass, und der Zeiger ist der normale Pfeil. Der dritte nicht —
der Hinderungsgrund stand in derselben Farbe wie die Erklärung darüber, also
genau so wie **vor** der Behebung.

Die Regel dafür gab es:

```
.obstacle { color: var(--warn); }        /* Zeile 3647 */
.hint     { color: var(--text-muted); }  /* Zeile 3784 */
```

Beide haben die Spezifität 0,1,0, das Markup lautet `class="hint obstacle"`, und
bei gleicher Spezifität entscheidet die **Reihenfolge in der Datei**. `.hint`
steht 137 Zeilen weiter unten und gewinnt.

> **Eine Klasse, die auf eine Regel zeigt, sagt nichts darüber, ob die Regel
> gilt.**

`ClassReachTest` war grün — er fragt, ob eine Klasse auf eine Regel zeigt, die
es gibt, und genau das war der Fall. Die Frage dahinter hat kein Wächter
gestellt.

**Und der Weg dorthin ist die eigentliche Lehre.** Der erste Wurf war `.toggle
.obstacle` (0,2,0) und hätte gewonnen. `StandaloneClassTest` hat ihn abgelehnt,
zu Recht: Eine Klasse, die es nur unter einem Vorfahren gibt, tut ausserhalb
davon nichts. Die Antwort darauf — `.obstacle` freistehend — hat die
Spezifität weggenommen, die sie brauchte.

> **Eine Behebung, die einem Wächter ausweicht, kann dabei genau das verlieren,
> wofür sie da war.**

**Wie oft steht das sonst noch da?** Ausgezählt über alle Klassenpaare, die in
einer Vorlage an einem Element stehen: **42 Paare, drei davon setzen dieselbe
Eigenschaft mit gleicher Spezifität.** Eines ist dieser Befund; die anderen
beiden sind ohne Folge und stehen benannt in `SpecificityTest::ORDERED`:

| Paar | Eigenschaft | gewinnt | Folge |
|---|---|---|---|
| `.hint` + `.obstacle` | `color` | `.hint` | **der Befund** |
| `.breadcrumb` + `.ident` | `font-size` | `.breadcrumb` | die Krume bleibt klein — gewollt |
| `.ident` + `.path-line` | `font-size` | `.path-line` | die Pfadzeile bleibt klein — gewollt |
| `.ident` + `.path-line` | `overflow-wrap` | `.path-line` | derselbe Wert, keine Wirkung |

**Behoben** als `.hint.obstacle` — zwei Klassen sind 0,2,0 und gewinnen gegen
`.hint`, gleich wo die beiden Regeln stehen. Die Regel eine Zeile tiefer zu
schieben hätte es auch getan, bis zum nächsten Aufräumen.

> **Eine Regel, die durch ihren Ort gewinnt, verliert beim nächsten Umzug — und
> sagt es nicht.**

**Nachgemessen im Aufsatz**, beide Themes, mit gerechnetem Kontrast:

| | hell | dunkel |
|---|---|---|
| Beschriftung | `rgb(92,100,112)` | `rgb(153,160,174)` |
| Erklärung | `rgb(92,100,112)` | `rgb(153,160,174)` |
| Hindernis | `rgb(132,83,6)` — 6,5:1 | `rgb(226,169,74)` — 9,0:1 |
| Zeiger | `default` | `default` |

Und die Gegenprobe mit dem Stand von rc.5: dort steht das Hindernis auf
`rgb(92,100,112)` — dieselbe Farbe wie die Erklärung, genau wie auf dem Bild vom
Server.

**Die erste Messung dazu war keine.** Sie las eine Zeichenkette ab, die beim
Laden der Seite entstanden war, und meldete nach dem Umschalten aufs dunkle Thema
dieselben Farben wie im hellen.

> **Ein Wert, der beim Laden entstanden ist, sagt nichts über den Zustand
> danach.**


### Befund 6 — Befund 3 ist behoben und wirkt trotzdem nicht

Gemeldet vom Betreiber am 23. August, nachdem Befund 3 und Befund 5 behoben
waren: „Das Kästchen bei Platzhalter bestellen liess sich zwar nicht klicken,
hat aber immer noch nicht wirklich deaktiviert gewirkt."

**Was behoben war, war die Umgebung.** Die Beschriftung ist gedämpft, der
Zeigefinger fort, der Hinderungsgrund steht in `--warn`. Das **Kästchen selbst**
blieb, wie der Browser es zeichnet: dasselbe Quadrat, nur mit blasserem Rand.
Bei vierfacher Vergrösserung nebeneinander gestellt ist der ganze Unterschied
zum bedienbaren Kästchen ein hellerer Strich — und bei 17 px trägt das fast
nichts.

> **Weniger Kontrast liest sich als „unwichtig", nicht als „gesperrt".**

Es ist ausserdem WCAG 1.4.1: Farbe darf nicht das einzige Mittel sein, mit dem
eine Auskunft transportiert wird. Ein blasserer Rand ist nur Farbe.

**Zum dritten Mal derselbe Satz.** Erst galt die Regel für das Feld
(`.field input:disabled`, seit Monaten, mit eigener Begründung), dann für die
Beschriftung des Schalters (Befund 3), jetzt für sein Kästchen:

> **Eine Regel, die für ein Feld gilt, gilt nicht für den Schalter daneben,
> bloss weil sie dieselbe ist.**

**Drei Entwürfe gebaut und angesehen**, in beiden Themes:

| | |
|---|---|
| **A** — gestrichelter Rand, wie das gesperrte Feld | trägt, auch angehakt |
| **B** — eine Marke „nicht möglich" neben der Beschriftung | bricht auf eine eigene Zeile, liest sich als eigener Block — und sagt zum dritten Mal, was der Satz in `--warn` darunter schon sagt |
| **C** — beides | erbt Bs Problem |

**Gebaut ist A.** `appearance: none` nimmt dem Kästchen die Zeichnung des
Browsers; der Haken steht deshalb als beschnittene Fläche in `--text-muted` und
nicht als Farbwert in einem eingebetteten Bild — sonst folgte er dem Thema
nicht. Umgezeichnet ist **nur** der gesperrte Zustand; in diesem Panel gibt es
genau ein `:disabled`-Kästchen, und sein Zustand entsteht auf dem Server.

**Und B ist doch gebaut worden** — auf Wunsch des Betreibers, dem die Form
allein nicht gereicht hat. Das war der richtige Einwand, und mein Grund, sie zu
verwerfen, war falsch gemessen: Die Marke brach nicht, **weil sie eine Marke
ist**, sondern weil `.toggle > span` stapelt. Sie war darin ein weiteres
Stapelkind.

> **Eine Marke, die neben etwas stehen soll, braucht eine Zeile, in der „neben"
> überhaupt vorkommt.**

> **Ein Entwurf, der am Behälter scheitert, ist nicht widerlegt.**

`.label-row` ist diese Zeile. Gemessen bei 390 und 1440 px, beide Themes:
`dokument: 0`, und die Marke steht in **beiden** Breiten neben der Beschriftung.

Damit sagen es jetzt drei Dinge, und jedes beantwortet eine andere Frage: die
**Form** des Kästchens (was ist das), die **Marke** (ob), der Satz in `--warn`
(warum).

> **Eine Form sagt es dem, der sie schon kennt. Ein Wort sagt es allen.**

**Und der Wächter war grün, während das Kästchen gleich aussah.**
`DisabledStateTest` fragte, ob es für die Hülle eine Regel gibt, die `disabled`
nennt. Die gab es — sie änderte die Farbe.

> **Ein Wächter, der fragt, ob es eine Regel gibt, sagt nichts darüber, ob man
> sie sieht.**

Er verlangt seitdem eine **Form**, und zwar am Wert: gestrichelt, gepunktet,
doppelt oder durchgestrichen.

**Der erste Wurf dieser Verschärfung war zu schwach, und der Bruch hat es
gezeigt.** Er zählte *Eigenschaften* — `border`, `outline`, `appearance` — und
blieb grün, als der Eingriff den gestrichelten Rand entfernte: `appearance:
none` stand noch da und galt als Form. Sie ist aber keine, sondern die
Erlaubnis, eine zu geben. Und `border: 1px solid` wäre durchgegangen, obwohl das
genau der Ein-Zustand ist.

> **Ein Eingriff, der eine Regel entfernt und einen Rest stehen lässt, prüft den
> Rest.**

**Und der Wochenlauf hat einen Tag später einen Eingriff von gestern gemeldet,
der stumpf geworden war.** Er bricht `.toggle:has(input:disabled)` — die Regel,
die die Beschriftung dämpft und den Zeigefinger zurücknimmt. Sein Zieltest
fragte „gibt es für diese Hülle **eine** Regel mit `disabled`?", und bis Befund 6
war das die einzige. Die Behebung gab `.toggle` eine zweite, die fürs Kästchen —
und die beantwortete die Frage mit.

> **Eine zweite Regel für dieselbe Hülle macht die Frage „gibt es eine?"
> stumpf.**

> **Ein Eingriff geht nicht nur kaputt, wenn seine Zielstelle umzieht — auch,
> wenn jemand neben seiner Regel eine zweite baut, die dieselbe Frage
> beantwortet.**

Damit war die erste Hälfte von Befund 3 unbewacht: ein Schalter, der den
Zeigefinger zeigt und nicht klickt. Sie steht jetzt als eigene Regel da —
**eine Hülle, die den Zeigefinger verspricht, nimmt ihn zurück** —, und der
Eingriff zeigt auf sie. Die Frage „gibt es überhaupt eine Regel?" hat einen
eigenen Eingriff über `.field` bekommen.

**Nachgeholt für den ganzen Zweig:** jeder Eingriff, dessen Datei dieser Zweig
angefasst hat, angewandt und im Gestell gefahren — **53, alle beissen.**


### Befund 7 — ein Zeilenmass über einer Ablesung

Gemeldet vom Betreiber am 23. August beim Nachsehen von Befund 4 gegen
`v0.7.0-rc.6`: „Bei 1440 px bricht es um nach dem Komma. Eigentlich unnötig. Der
Platz ist komplett da."

```
Zuletzt geprüft: 2026-08-23 18:01:56 · gefragt wurden 167.235.231.182,
159.69.110.93
```

**Befund 4 ist damit erfüllt** — der Umbruch fällt hinter das Komma, beide
Adressen sind ganz. Der Einwand gilt der Frage davor: Warum bricht die Zeile
überhaupt?

**Gemessen bei 1440 px:** Die Zeile braucht **576 px**, `.section-note` gibt ihr
mit `max-width: 64ch` **529** — sie verfehlt es um 47 px, und die Spalte daneben
ist 952 px breit.

**Die Grenze bleibt trotzdem**, denn sie tut echte Arbeit: **16 der 26 Notizen
dieses Panels sind länger als 64 Zeichen**, die längste 285. Ohne Grenze liefen
die über die ganze Spalte.

> **Ein Zeilenmass ist für einen Satz. Eine Ablesung ist keiner.**

Die Notiz ist **eine** Auskunft — ein Zeitpunkt und die Nameserver, die gefragt
wurden. Der Umbruch trennte eine Aufzählung von zwei Adressen in der Mitte, und
damit sah eine Auskunft nach zweien aus.

**Behoben** als `.section-note.wide`. Nachgemessen: bei 1440 px **eine** Zeile
statt zwei, bei 390 px unverändert zwei (dort bindet die Grenze ohnehin nicht,
die Spalte ist 358 px breit). Ein Satz daneben behält seine 529 px — bei 1440 px
drei Zeilen, bei 390 px fünf.

**Zwei Klassen und nicht eine**, und das ist die Lehre aus Befund 5: Eine
freistehende `.wide` hätte dieselbe Spezifität wie `.section-note`, und wer
gewinnt, entschiede die Reihenfolge der Datei. `SpecificityTest` fängt genau
diese falsche Fassung — gegengeprüft.

**Was kein Test hält:** ob eine Notiz ein Satz ist oder eine Ablesung. Das ist
eine Frage an den, der sie schreibt, und keine Eigenschaft des Quelltextes.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**

---

### Befund 8 — ein Knopf, so hoch wie die Beschriftung daneben

Gemeldet vom Betreiber am 23. August beim Nachsehen des Abonnementnamens gegen
`v0.7.0-rc.7`: „Der Knopf ‚Domain anlegen' ist zu gross im Verhältnis zum
Auswahlfeld bei 390 px. Gleiches beim Knopf ‚Datenbank anlegen'."

**Der Punkt selbst ist erfüllt** — `p6-abnahme.invalid` steht in beiden Feldern
vollständig, beide Lagen gültig. Der Einwand gilt dem, was daneben steht.

**Gemessen bei 390 px, Domainliste:** Knopf **133×72**, Auswahlfeld **206×44**.
`.page-head .button-row` richtet mit `stretch` aus — richtig für eine Reihe aus
lauter Knöpfen, die dann gleich hoch sind. Steht ein **beschriftetes** Feld
dabei, ist die Reihe so hoch wie Beschriftung plus Feld, und der Knopf wächst
mit.

> **Eine Höhe, die ein Nachbar vorgibt, ist keine Aussage über den Knopf.**

Drei Fassungen gemessen:

| | Knopf | Auswahlfeld | Unterkanten |
|---|---|---|---|
| `stretch` (vorher) | 133×**72** | 206×44 | bündig |
| `center` | 133×44 | 206×44 | 14 px auseinander |
| **`flex-end`** | 133×**44** | 206×44 | **bündig** |

`center` macht ihn gleich hoch und lässt ihn über der Feldkante schweben;
`flex-end` setzt ihn darauf. Die Kopfhöhe bleibt bei allen dreien 134 px.

**Und das ist ein Fehler, den derselbe Tag verursacht hat.** Die Ausrichtung gab
es schon, als `align-items: center` in der Regel, die den Seitenkopf der
Übersicht in eine Zeile bringt — und die traf zuerst *alle* Reihen mit einem
Feld. Die Verengung auf `:has(.field > select:only-child)` war richtig (sonst
wurde der Abonnementname beschnitten, siehe Befund davor), hat aber **zwei
Dinge zugleich** weggenommen: das Schrumpfen des Feldes, das schadete, und die
Ausrichtung, die half.

> **Wer eine Regel verengt, verengt alles, was in ihr steht — auch das, was mit
> dem Grund der Verengung nichts zu tun hatte.**

**Behoben** als eigene Regel am breiten `:has(.field)`, mit `flex-end`. Sie
beschneidet nichts und ist überall richtig, wo ein Feld neben einem Knopf steht.

**Kein Bild und keine Messung dieses Laufs hätte ihn gefunden.** Der Überlauf
war in allen Lagen `0`, `schiebt` und `rollt` leer, die Kopfhöhe unverändert.
Es ist ein Verhältnis und keine Zahl, die überläuft — gesehen hat es der
Betreiber.

> **Eine Messung, die auf Überlauf zeigt, sieht ein falsches Verhältnis nicht.**

**Und die Nachbildung im Aufsatz war vorher falsch.** Sie hatte **eine**
Knopfreihe; die echte Seite hat **zwei ineinander** — `PanelLayout.vue` legt
den Platz `#actions` in ein `.button-row`, und `Domains/Index.vue` legt sein
eigenes `form.button-row` hinein. Mit nur einer Ebene brach der Knopf in die
zweite Zeile und war 44 px hoch; erst mit beiden ergaben sich die 72.

> **Eine Nachbildung, der eine Ebene fehlt, misst eine andere Lage — und zwar
> eine, die harmloser aussieht.**

`ButtonFieldAlignTest` hält seitdem beides auseinander: dass die Ausrichtung
gesetzt ist, und dass sie am **breiten** Selektor hängt. Zwei Eingriffe, beide
gegengeprüft.

**Nachgesehen am 23. August gegen `v0.7.0-rc.8`, beide Seiten, beide Lagen
gültig:**

| | `/domains` | `/databases` |
|---|---|---|
| Knopf | 116×**44** | 137×**44** |
| Auswahlfeld | 190×44 | 190×44 |
| gleich hoch | **ja** | **ja** |
| Unterkanten bündig | **ja** | **ja** |
| Abonnementname | `p6-abnahme.invalid`, ganz | `p6-abnahme.invalid`, ganz |

Im Bild reicht der Knopf nicht mehr über die Beschriftung „Abonnement" hinauf.
Der Name steht ungekürzt — die Ausrichtung hat nichts beschnitten, was der
ganze Grund war, sie als eigene Regel zu bauen und nicht in die verengte
zurückzulegen.

---

## 2b. Was das Beheben gekostet hat

**Vier bestehende Wächter haben die Behebung abgefangen**, jeder zu Recht:

| Wächter | was er fand |
|---|---|
| `NoticeChildrenTest` | `<Idents>` neben Text in einer `.notice` — genau die Regel von heute Vormittag |
| `NoticeShapeTest` | dieselbe Meldung, zwei Flexkinder statt einem |
| `StandaloneClassTest` | `.obstacle` gab es nur als `.toggle .obstacle` |
| `ClassNameTest` | `obstacle` stand nicht im Vokabular |

> **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass zu
> messen.** Der Satz steht seit `docs/66` in `CLAUDE.md`; hier hat er sich
> viermal an einem Nachmittag bezahlt gemacht.

**Und der erste Wächter zu Befund 1 fing den Fall nicht, der ihn ausgelöst
hatte.** Er suchte `class="…ident…"` und `.join(` in **derselben** Zeile; die
Fundstelle stand über zwei. Der Bruch dazu änderte die Datei nachweislich — und
der Wächter blieb grün.

> **Ein Wächter, der den Fall nicht fängt, der ihn ausgelöst hat, ist keiner.**

Er liest seitdem den **Inhalt** des Elements, mit gezählter Tiefe.

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

**Kein Befund dieses Laufs ist mehr offen.** Die Befunde 1 bis 8 sind behoben
**und nachgesehen**, der letzte (Befund 8) am 23. August gegen `v0.7.0-rc.8`.
Was hier darunter steht, sind Zustände, Fragen und Messungen, die dieser Lauf
nicht hergestellt oder nicht ausgeschöpft hat — keine Mängel am Prüfling.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**

- **Befund 1 und 2 sind am 23. August auf dem Server nachgesehen** — gegen
  `v0.7.0-rc.5`, vier Lagen, alle vier gültig. Die IPv6 bricht hinter dem
  Doppelpunkt, `fe72` ist ganz; `*.cloudlab24.de, cloudlab24.de` steht mit
  Komma. **Befund 3 ist noch nicht nachgesehen** — dafür braucht es eine Domain
  ohne hinterlegte DNS-Zugangsdaten, und `cloudlab24.de` trägt sie seit dem Lauf.

  > **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
  > jemand ihn behoben hat.**
- **Befund 4, 5 und 6 sind am 23. August gegen `v0.7.0-rc.6` nachgesehen** —
  vier Lagen, alle vier gültig. Befund 4 an **beiden** Fundstellen: Die Zeile
  „gefragt wurden" bricht bei 1440 px hinter dem Komma, beide Adressen ganz; der
  Satz unter „Als Platzhalter bestellen" steht bei 390 px mit `*.cloudlab24.de,`
  am Zeilenende und `cloudlab24.de` in der nächsten. Befund 5: der
  Hinderungsgrund in `--warn`, abgesetzt. Befund 6: das Kästchen gestrichelt.
  Und die Marke „nicht möglich" steht in beiden Breiten **neben** der
  Beschriftung.
- **Dabei ist Befund 7 herausgefallen** — zum dritten Mal in diesem Lauf ein
  Befund am Nachsehen einer Behebung.
- **Der Takt der Übersicht ist am 23. August gegen `v0.7.0-rc.7` gemessen und
  erfüllt** — und das ist der einzige Punkt dieses Laufs, den kein Bild
  beantworten kann. Gemessen mit einer Probe an
  `XMLHttpRequest.prototype.send`, die jede Anfrage mit
  `X-Inertia-Partial-Data` mit ihrem Zeitstempel meldet.

  | Zeile | Abstand | |
  |---|---|---|
  | 1,9 s | — | **Gegenprobe** (Klick auf „Aktualisieren") |
  | 24,5 s | — | erste selbsttätige |
  | 54,5 s | **30,0** | |
  | 84,5 s | **30,0** | |
  | 114,5 s | **30,0** | |
  | 122,6 s | 8,1 | Umschalten auf 60 s — sofortiges Nachladen |
  | 182,6 s | **60,0** | |
  | 242,6 s | **60,0** | |
  | 302,6 s | **60,0** | |
  | — | | „nicht von allein": keine Zeile mehr |

  **Der Beleg steht in dem, was fehlt.** Hielte `stellen()` den alten Takt nicht
  an, liefe der 30-Sekunden-Takt in seiner Phase weiter — bei 144,5 · 174,5 ·
  204,5 · 234,5 · 264,5 · 294,5. Keine dieser Zeilen ist da; die drei nach dem
  Umschalten liegen exakt auf der 60-Sekunden-Phase ab 122,6. Es läuft genau ein
  Zeitgeber.

  > **Ein zweiter Takt ist nicht daran zu erkennen, dass etwas Falsches
  > passiert, sondern daran, dass etwas zu oft passiert.**

  Zwei Zahlen, die nach einem Fund aussehen und keiner sind: Die 8,1 s beim
  Umschalten sind gewollt (der Beobachter lädt bei einer Änderung sofort nach,
  damit niemand eine Minute auf frische Zahlen wartet), und die erste Zeile bei
  24,5 statt 30,0 ist die Phase eines Taktes, der schon lief, als die Probe
  eingesetzt wurde.

  **Und die Gegenprobe stand zuerst.** Ohne die Zeile bei 1,9 s wäre jede
  ausbleibende Zeile danach mehrdeutig gewesen — „der Takt ist aus" und „die
  Probe misst nichts" sehen gleich aus.

  > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
  > steht.**
- **Der Seitenkopf der Übersicht ist am 23. August gegen `v0.7.0-rc.7`
  nachgesehen und erfüllt.** Beide Themes bei 390 px, beide Lagen gültig und in
  jeder Zahl gleich:

  | | Wert |
  |---|---|
  | Kopfhöhe | **126 px** (vorher 140) |
  | Reihe | 44 px |
  | Knopf | 132×44 |
  | Auswahlfeld | 172×44 |
  | Knopf und Feld in einer Zeile | **ja** |
  | Zeichen neben dem Wort | **ja** |

  Die 126 px sind die Vorhersage aus dem Aufsatz, auf den Pixel getroffen.

  **Eine Zahl weicht ab, und sie schliesst eine offene Frage.** Das Feld ist auf
  dem Server **172 px** breit, im Aufsatz waren es 202 — und „alle 30 Sekunden"
  steht in beiden vollständig darin. Im Container war ausgerechnet worden, der
  Text *brauche* 201 px, und die verbleibenden 2 px Luft galten als knapp. Die
  201 waren nie eine Bedarfsangabe:

  > **Ein Wert, der immer dem entspricht, wogegen man ihn hält, ist keine
  > Messung.** `scrollWidth` eines `<select>` gibt seine eigene sichtbare Breite
  > zurück, auch wenn der Text abgeschnitten ist — gemessen an drei
  > Polsterungen, jedes Mal `Luft 0`, und auf dem Server an einer Breite, die
  > 30 px darunter liegt und trotzdem reicht.

  Was über die Abschneidegefahr entscheidet, bleibt damit allein das Bild.
- **Befund 7 ist am 23. August gegen `v0.7.0-rc.7` nachgesehen und erfüllt.**
  Zwei Lagen, beide gültig (`dokument: 0`, Gegenprobe `200/200`, `schiebt` und
  `rollt` leer):

  | Lage | Zeile „Zuletzt geprüft … · gefragt wurden …" |
  |---|---|
  | 1440 / dunkel | **eine** Zeile, beide Adressen ganz |
  | 390 / hell | zwei Zeilen, unverändert |

  Bei 390 px ist das kein Rest, sondern die Zusage: Die Grenze von 64ch bindet
  dort ohnehin nicht, die Spalte ist 358 px breit.

  **`versteckt: 4` bei 390 gegen `0` bei 1440 ist erwartet.** Die Verben der
  Knöpfe verlassen unterhalb von 720 px das Bild und bleiben im Dokument
  (`.page-head .verb`, wegen WCAG 2.5.3); sie laufen über, sind aber unsichtbar,
  und die Messung zählt sie deshalb getrennt statt sie zu melden.
- **Befund 3 ist am 23. August nachgesehen** — zwei seiner drei Teile stimmen,
  der dritte hat **Befund 5** ergeben, und der Betreiber hat danach **Befund 6**
  gemeldet: Die Umgebung des Kästchens war behoben, das Kästchen selbst nicht. Auch der ist behoben und **nicht auf dem
  Server nachgesehen**; im Aufsatz sind beide Themes mit gerechnetem Kontrast
  gemessen (§2, Befund 5).

  **Zweimal an einem Tag hat das Nachsehen einer Behebung einen Befund
  gebracht.** Das ist kein Zufall dieses Laufs, sondern der Satz aus `docs/66` in
  seiner schärfsten Form: Eine Behebung ist eine Änderung wie jede andere.
- **Die DNS-Zugangsdaten an `p6-b.invalid` sind entfernt** und damit nicht mehr
  offen. Wer den Platzhalter wieder prüfen will, hinterlegt sie neu.
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
- **Die Konsole ist gelesen, aber nicht ausgeschöpft.** In allen vier Lagen des
  Nachlaufs zu `v0.7.0-rc.6` steht im Protokoll unter `top` **kein** Eintrag des
  Panels — nur das eingefügte Messmittel und seine Antworten. Der Zähler der
  Entwicklerwerkzeuge meldet daneben einen Fehler und vierzehn Warnungen; beim
  Anklicken erscheint nichts im Protokoll, was dafür spricht, dass er einen
  anderen Kontext zählt (eine Erweiterung). **Belegt ist das nicht** — dafür
  müsste die Seite mit dem Filter „Errors only" neu geladen werden.

  > **Ein Zähler, der auf nichts zeigt, das man aufschlagen kann, ist noch keine
  > Auskunft über den Prüfling.**

  **Am 23. August ist der Zähler aufgeschlagen worden**, im Nachlauf zu
  `v0.7.0-rc.7`: Alle fünfzehn Einträge sind dieselbe Meldung — *„A form field
  element should have an id or name attribute"*. Sie betrifft den Prüfling
  (ausgezählt: **124 von 138 Feldern** ohne `id` und ohne `name`) und ist
  trotzdem **kein Fund**.

  Zwei Gründe, beide nachgeprüft. Erstens misst Chrome hier seine
  **Ausfüllhilfe** und nicht die Zugänglichkeit: Beschriftet sind diese Felder
  über die Klammer — jedes steht in seinem `<label>`, und genau darauf besteht
  `FormLabelTest`. Zweitens reisen sie über Inertia als JSON und nicht über ein
  natives `POST`; ein Name trüge nichts. Die eine Stelle, an der ein Browser ihn
  wirklich braucht, hat ihn: `Auth/Login.vue` führt `name` **und**
  `autocomplete` an Adresse und Passwort.

  > **Ein Zähler, der eine Heuristik zählt, sagt nichts über die Regel, die man
  > einhält.**

  Damit ist der Kanal für diese Seite ausgeschöpft — die Frage nach den
  **Fehlern** (der rote Zähler stand bei 390 px auf 1) bleibt offen und braucht
  weiterhin den Filter „Errors only" mit Neuladen.
- **Die Konsole war zuvor nicht gelesen worden.** In den Aufnahmen zu Ansicht 2
  zählten die Entwicklerwerkzeuge zwölf Fehler und fünfunddreissig Warnungen;
  ein Teil kommt sichtbar aus einer Browsererweiterung (`background.js`,
  `[AppIntegration]`). Auf Ansicht 3 steht dagegen **ein** Fehler und „No
  Issues" — die hohen Zahlen hingen also an jener Seite oder an dem, was sich
  bis dahin angesammelt hatte. Ob **einer** davon aus dem Panel stammt, ist
  ungeprüft.

  > **Ein Fehler in der Konsole erzeugt keinen Überlauf und steht auf keinem
  > Bild.** Er ist der dritte Kanal neben Zahl und Betrachter, und dieser Lauf
  > hat ihn bisher nicht benutzt.
- **Die Regel `.toggle + .button-row` ist am 24. August belegt worden** — im
  Abnahmelauf von P7, an `hier.cloudlab24.de` unmittelbar nach dem Anlegen:
  `.toggle` hat die Knopfreihe als direkten Nachbarn, und deren `margin-top`
  ist `24px` wie `--block-gap`. Das Protokoll ist `docs/78`. Was darunter steht,
  ist die Vorgeschichte und erklärt, warum es diesen einen Zustand brauchte.

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
