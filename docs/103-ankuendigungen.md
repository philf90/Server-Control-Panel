# A14 — Ankündigungen im Panel

**Geschrieben am 5. September 2026, nach der Messrunde** (`docs/81 §2.3q`) und
nach vier Entscheidungen des Betreibers. Die Stufe ist P7b, der Platz hinter
A12 (`docs/81 §11`).

Der Betreiber schreibt eine Ankündigung, und sie erscheint im Panel — als
farbiger Streifen ganz oben, eingefärbt nach ihrer Kategorie. Mehrere
gleichzeitig sind möglich. Kein eigenes Fenster, keine Seite, die man aufsuchen
muss.

---

## 1 · Was die Messrunde schon entschieden hat

Sechs Dinge stehen nicht mehr zur Wahl, weil sie gemessen sind. Sie stehen hier,
damit niemand sie im Bauen noch einmal aufmacht.

| | gemessen | Folge für den Bau |
|---|---|---|
| M1 | ein Layout, 48 von 50 Seiten | der Streifen ist **eine** Stelle |
| M2 | drei `grid-row: 1` liegen aufeinander | **eine Hülle** über allem, kein zweites Geschwister |
| M3 | 42 px einzeln, 252 px zu dritt bei 390 px | die Höhe ist der Preis, nicht die Breite |
| M4 | alle zwölf Kontraste 5,40:1 bis 9,21:1 | **keine neue Farbe** |
| M5 | ein fertiger Wert in `share()` läuft immer | als **Verschluss**, nie als Wert |
| M6 | der Klient hält, was der Server nicht schickt | **kein** Mechanismus gegen partielles Nachladen |
| M7 | UTC-Vergleich ja, Ortszeit-Vergleich nein | der Filter rechnet in **UTC** |
| M8 | 40 Zeichen je Zeile bei 390, 160 bei 1440 | gedeckelt wird über **Zeilen**, nicht Zeichen |
| M9 | Warnung ./. Störung nur ΔE 3,8 | der **Rand** und das **Wort** tragen den Rang |

---

## 2 · Die Entscheidungen des Betreibers

Vier am 5. September 2026, nach der Messrunde und mit ihren Zahlen daneben.

**1 · Mehrere gleichzeitig, alle sichtbar.** Nicht „nur die dringendste". Die
Messung sagt, was das kostet — drei Streifen 252 px roh, mit der Klammer aus
Entscheidung 2 nur noch 189 px.

**2 · 500 Zeichen in der Ablage, im Streifen gekürzt.** Die Kürzung geschieht
über eine **Zeilenklammer** und nicht über eine Zeichenzahl; M8 hat gemessen,
dass eine Zeichengrenze auf zwei Breiten zwei verschiedene Grenzen ist. Der
volle Text braucht deshalb einen Ort — siehe §4.

**3 · Das Publikum ist je Ankündigung wählbar**: Betreiber · Administrator ·
Kunde, mehrfach ankreuzbar. Eine Wartungsmeldung geht an alle, ein Hinweis zur
Verwaltung nur an Admins.

**4 · Störungen erscheinen auch auf der Anmeldeseite.** Login und Zweitfaktor
tragen `PanelLayout` nicht (M1), das ist also eine zweite Stelle — und
ausdrücklich nur für die Kategorie **Störung**.

> **Was auf der Anmeldeseite steht, steht vor jedem, der die Adresse kennt.**
> Der Entwurf in `docs/81 §11` hat genau dieses Argument benutzt, um
> Ankündigungen von der 503-Seite fernzuhalten. Die Beschränkung auf Störungen
> begrenzt den Kreis, hebt ihn nicht auf: „Der Mailversand ist gestört" ist eine
> Auskunft über den Betrieb. Der Betreiber hat das entschieden; es steht hier,
> damit es keine Überraschung wird.

---

## 3 · Die drei Kategorien

`Info` · `Warnung` · `Störung`. Sie teilen sich die **Farbmarken** aus
`app.css` — `--ok`, `--warn`, `--critical` samt ihren Flächen —, aber
**nicht die Wörter** mit dem Nachtlauf von A10, der seit dem 3. September
`In Ordnung · Auffällig · Kaputt · Nicht gemessen` sagt.

> **Zwei Skalen nebeneinander, die verschiedene Wörter für dasselbe benutzen,
> sind eine Fehlerquelle.** Deshalb teilen sie die Farben und nicht die
> Benennung: Die eine sagt, wie der Server steht, die andere, was der Betreiber
> mitteilt.

**Der Rang steht als Wort im Streifen und nicht nur als Farbe.** M9 hat
gemessen, warum: Zwischen Warnung und Störung liegen im hellen Thema ΔE 3,8 —
unterscheidbar, aber das schwächste der drei Paare, und ausgerechnet das, auf
das es ankommt. Für jemanden mit Rot-Grün-Schwäche trägt die Farbe dort gar
nichts (WCAG 1.4.1).

---

## 4 · Wie es aussieht

### 4.1 Die Hülle

`PanelLayout.vue` bekommt **eine** Hülle über allem, die die Rasterzeile nimmt.
Darin liegen, in dieser Reihenfolge:

1. das Band des Sichtwechsels (`impersonation`, seit P6)
2. die Ankündigungen, dringendste zuerst

Das ist keine Verschönerung, sondern der Befund aus M2: Zwei Geschwister mit
`grid-row: 1` liegen aufeinander, und die Überlaufmessung sieht davon nichts.

> **Ein `grid-row`, das ein Element ausdrücklich nimmt, nimmt jedes Geschwister
> in dieselbe Zelle — und „mehrere gleichzeitig" heisst dann „übereinander".**

### 4.2 Der Streifen

`.band` zieht aus dem `<style scoped>` des Layouts nach **`app.css`** und
bekommt dort die Ränge, die `.notice` seit P2 führt. Ein Baustein, den zwei
Stellen benutzen, gehört nicht in eine Komponente.

**Mitsamt dem Rand.** `.notice` trägt `border-left: 3px solid`, `.band` heute
`border-bottom: 1px solid`. Erbt der Streifen nur die Fläche, bleibt vom
Rangsignal die Lasur mit ΔE 3,8 übrig (M9).

> **Ein Rang, der aus drei Trägern besteht — Fläche, Rand, Textfarbe —, verliert
> beim Umzug den, den niemand aufgeschrieben hat.**

Der Streifen trägt: das Wort der Kategorie, den gekürzten Text, und einen
Verweis auf den vollen Text, wenn gekürzt wurde.

### 4.3 Die Kürzung

Eine **Zweizeilen-Klammer** auf dem Text. Gemessen (M8): Bei 390 px ergibt
damit jede Länge von 60 bis 500 Zeichen dieselben 63 px; drei Ankündigungen
kosten 189 px statt bis zu 819 px.

> **Eine Klammer über Zeilen ist auf jeder Breite dieselbe Regel. Eine über
> Zeichen ist es nicht** — 40 Zeichen je Zeile bei 390 px, 160 bei 1440 px.

**Der volle Text steht auf der Verwaltungsseite** (§5), und der Verweis im
Streifen führt dorthin. Kein Aufklapper im Streifen: Das wäre ein Zustand je
Betrachter, und der Betreiber hat das Wegklicken ausdrücklich nicht bestellt.

### 4.4 Die Anmeldeseite

`Auth/Login.vue` und `Auth/TwoFactorChallenge.vue` bekommen denselben Streifen,
gefiltert auf `Störung`. Sie tragen `PanelLayout` nicht, also ist es eine zweite
Einbindung — und genau deshalb steht sie hier und nicht als Nebensatz.

---

## 5 · Die Verwaltung

Eine Seite unter `/announcements`, hinter **`manage-settings`**. Nicht
`operate-server`: Eine Ankündigung dreht nichts am Server, sie ist Text in einer
Tabelle — dieselbe Art Griff wie die Anzeigezeitzone.

Sie zeigt die Ankündigungen mit Kategorie, Fenster, Publikum und Zustand, und
sie ist der **Ort des vollen Textes**, auf den der gekürzte Streifen zeigt.

Felder je Ankündigung:

| Feld | Form |
|---|---|
| Kategorie | Info · Warnung · Störung |
| Text | bis 500 Zeichen |
| sichtbar von | Datum **und** Uhrzeit, zwei Felder |
| sichtbar bis | Datum **und** Uhrzeit, zwei Felder, beide freilassbar |
| Publikum | Betreiber · Administrator · Kunde, mehrfach |

**Zwei Felder und nicht eines, und das ist bezahlt.** `docs/102` §2: Ein
Textfeld für `Y-m-d H:i` mit `inputmode="numeric"` war auf dem iPhone nicht
ausfüllbar, weil die Zifferntastatur weder Bindestrich noch Doppelpunkt noch
Leerzeichen hergibt. `DateInputTest` hält die Regel seitdem.

> **Ein Format, das kein Eingabetyp hergibt, ist auf dem Telefon nicht tippbar.**

---

## 6 · Wo der Zustand steht

Eine Tabelle `announcements`. Kein Agent, keine Datei, kein Neuladen von nginx —
A14 fasst nichts an, was Systemrechte braucht.

| Spalte | |
|---|---|
| `category` | `info` · `warning` · `incident` |
| `body` | Text, bis 500 Zeichen |
| `visible_from`, `visible_until` | UTC, beide `nullable` |
| `audiences` | die drei Publika |
| `created_at`, `updated_at` | |

**Die Zeitpunkte liegen in UTC**, die Eingabe geht über `Clock::minuteToUtc()`
und die Anzeige über `Clock::minute()` samt `Clock::labelAt()` für die Zone.
M7 hat gemessen, was ein Filter in der Anzeigezone anrichtet:

> **Ein Fenster, dessen Filter in der Anzeigezone rechnet, ist genau während
> seiner eigenen Laufzeit unsichtbar und erscheint um den Versatz zu früh.**

**Das Fenster ist ein Filter beim Lesen und kein Zeitgeber** — der Unterschied
zu A12, und der Grund, warum es hier gefahrlos ist (`docs/81 §11`).

---

## 7 · Wie es auf die Seite kommt

`HandleInertiaRequests::share()` bekommt einen Schlüssel — **als Verschluss**:

```php
'announcements' => fn () => Announcement::visibleTo($request->user()),
```

**`fn () =>` und nicht der fertige Wert, und das ist gemessen** (M5): Ein
fertiger Wert wird auch bei einem partiellen Nachladen berechnet, das ihn gar
nicht mitschickt. Die Übersichtsseite fragt mit ihrem Selbstlauf alle dreissig
Sekunden nach; ohne den Verschluss liefe die Abfrage jedes Mal für nichts.

> **Ein fertiger Wert in `share()` läuft bei jeder Anfrage, auch bei einer, die
> ihn gar nicht mitschickt. Ein Verschluss läuft nur, wenn er gesendet wird.**

**Und gegen das partielle Nachladen braucht es nichts** (M6): Der Server
schickt beim `only: ['tiles']` von sechzehn Eigenschaften zwei, der Klient hält
die übrigen. Ein Streifen verschwindet dabei nicht.

---

## 8 · Das Abnahmekriterium

Acht Punkte, auf einem echten Server. **3 und 6 dürfen nicht ausfallen** — sie
sind die beiden, für die es diese Stufe gibt.

| | | |
|---|---|---|
| 1 | Eine Ankündigung anlegen und sie erscheint | Streifen oben, Kategorie als Wort, Farbe der Marke |
| 2 | Drei gleichzeitig | **drei Streifen untereinander**, keiner verdeckt — der Befund aus M2 |
| 3 | **Drei bei 390 px** *(Ausschluss)* | ≤ 189 px zusammen, `schiebt = 0`, Gegenprobe schlägt an |
| 4 | 500 Zeichen | im Streifen zwei Zeilen, Verweis auf den vollen Text, der Text vollständig auf `/announcements` |
| 5 | Publikum | ein Kundenkonto sieht die Kundenankündigung und nicht die für Admins |
| 6 | **Das Fenster** *(Ausschluss)* | mit Anzeigezone auf `Europe/Berlin` gesetzt: sichtbar **während** der eingetippten Ortszeit, davor und danach nicht |
| 7 | Störung auf der Anmeldeseite | abgemeldet sichtbar; eine Ankündigung der Kategorie Info dort **nicht** |
| 8 | Das partielle Nachladen | Streifen steht nach dem Selbstlauf der Übersicht unverändert da |

**Punkt 6 misst mit einem Versatz und nicht in UTC.** In UTC sind der richtige
und der falsche Vergleich gleich; eine fehlende Umrechnung sähe aus wie eine
gelungene (M7).

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

**Punkt 2 braucht die breite Ansicht.** Der Fehler aus M2 zeigt sich bei
1440 px und **nicht** bei 390 px — dort stapeln dieselben drei korrekt. Ein
Punkt, der nur schmal misst, geht daran vorbei.

---

## 9 · Die Wächter

Für jede Regel einer, und jeder wird gegen den Fehler gebrochen, den er fangen
soll.

| Wächter | hält |
|---|---|
| `AnnouncementRowTest` | genau **eine** Hülle nimmt `grid-row: 1`; kein Geschwister daneben — die Regel aus M2, gemessen am Layout und nicht an einer Liste im Test |
| `AnnouncementRankTest` | jede Kategorie trägt Fläche **und** Rand **und** Wort; ein Rang, dem einer der drei fehlt, ist rot (M9) |
| `AnnouncementClampTest` | die Kürzung steht als Zeilenklammer in `app.css` und nicht als Zeichenzahl im Controller (M8) |
| `AnnouncementShareTest` | der Schlüssel in `share()` ist ein Verschluss — gemessen an der **Wirkung** (Abfragezähler unter beiden Anfragearten), nicht am Wort `fn` im Quelltext (M5) |
| `AnnouncementWindowTest` | der Filter rechnet in UTC, gemessen mit einem **Versatz** und einer zweiten Zone daneben (M7) |
| `AnnouncementAudienceTest` | jedes Publikum trennt an derselben Grenze wie die Rollen aus A9, in beide Richtungen |
| `AnnouncementLoginTest` | auf den zwei Auth-Seiten erscheint **nur** `incident` — beide Richtungen, denn die stille Hälfte ist die, die zuviel zeigt |

**`AnnouncementShareTest` misst die Wirkung und nicht das Wort.** Ein Wächter,
der `fn () =>` als Zeichenkette sucht, ist grün, sobald es irgendwo in der Datei
steht — dieses Repo hat diesen Fehler oft genug bezahlt.

---

## 10 · Was A14 ausdrücklich **nicht** wird

- **Kein Wegklicken.** Das wäre ein Lesezustand je Konto, also eine zweite
  Tabelle. Eine Ankündigung mit Fenster verschwindet von selbst.
- **Keine Adressierung je Abonnement.** Global; je Abonnement ist ein zweites
  Merkmal und gehört eher zu A7.
- **Keine Ankündigung auf der 503-Seite.** Die sieht ein Website-Besucher, die
  Ankündigung ein Panel-Nutzer — zwei Publika, und eine Vermischung brächte
  Betreibertext vor fremde Augen.
- **Kein Zeitgeber.** Das Fenster wird beim Lesen ausgewertet. Was A12 einen
  Zeitgeber gekostet hätte, kostet hier eine `where`-Bedingung.
- **Keine Benachrichtigung nach draussen.** Den Kanal baut A7.
- **Keine Verbindung von A12 nach A14 in beide Richtungen.** Ein Wartungsmodus
  **kann** seine Ankündigung erzeugen; eine Ankündigung allein bewirkt am
  Webserver nichts.

---

## 11 · Wann er durch ist

Wenn alle acht Punkte aus §8 auf einem echten Server gemessen sind, **3 und 6
darunter**, und das Protokoll je Punkt seinen gemessenen Wert trägt — nicht ein
Häkchen. Das Protokoll bekommt die nächste freie Nummer; sie steht bewusst nicht
hier, weil `docs/81` einmal eine genannt hat, die einem anderen Dokument
gehörte, und `DocLinkTest` das nicht sehen konnte.
