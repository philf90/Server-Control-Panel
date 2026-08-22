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
| 1 | Domain, DNS-Abgleich | 1440 | hell | — | — | — | offen |
| 1 | Domain, DNS-Abgleich | 1440 | dunkel | — | — | — | offen |
| 2 | Einstellungen → Allgemein | 390 | hell | — | — | — | offen |
| 2 | Einstellungen → Allgemein | 390 | dunkel | — | — | — | offen |
| 2 | Einstellungen → Allgemein | 1440 | hell | — | — | — | offen |
| 2 | Einstellungen → Allgemein | 1440 | dunkel | — | — | — | offen |
| 3 | Domainliste `/domains` | 390 | hell | — | — | — | offen |
| 3 | Domainliste `/domains` | 390 | dunkel | — | — | — | offen |
| 3 | Domainliste `/domains` | 1440 | hell | — | — | — | offen |
| 3 | Domainliste `/domains` | 1440 | dunkel | — | — | — | offen |
| 4 | Abonnement, „Domains" | 390 | hell | — | — | — | offen |
| 4 | Abonnement, „Domains" | 390 | dunkel | — | — | — | offen |
| 4 | Abonnement, „Domains" | 1440 | hell | — | — | — | offen |
| 4 | Abonnement, „Domains" | 1440 | dunkel | — | — | — | offen |

### Die Einträge in `schiebt` und `rollt`

`schiebt` ist ein Hinweis und kein Urteil (`docs/75 §5`); jeder Eintrag wird
einzeln benannt.

| Lage | Eintrag | gewollt oder Fund |
|---|---|---|
| 1 / 390 / hell | keiner | — |
| 1 / 390 / dunkel | keiner | — |

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

**Ein Fund ist vor dem Lauf entstanden** und steht deshalb nicht hier, sondern
in `docs/75 §1.1`: Die Meldung „nicht gefragt" stand als drei Flexkinder in
einer `.notice` und brach bei 390 px Zeichen für Zeichen. Gefunden hat ihn die
Vormessung im Container, behoben ist er seit `v0.7.0-rc.3`, und `NoticeChildrenTest`
hält die Regel.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

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

- **Vierzehn der sechzehn Lagen.**
- **Die Fuge unter „Als Platzhalter bestellen".** Die ganzseitige Aufnahme zu
  Lage 1/390/hell zeigt die komplette Seite und trägt in ihrer Struktur; im
  Massstab der Übermittlung ist der Abstand zwischen dem Kästchen und dem
  Bereich darunter aber nicht ablesbar. Es ist die Stelle, die am 22. August als
  Befund gemeldet und mit `.toggle + .button-row` behoben wurde — sie gehört im
  Original angesehen.

  > **Ein Bild, dessen Massstab die Frage nicht trägt, beantwortet sie nicht —
  > es sieht nur so aus, als hätte man hingesehen.**
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
