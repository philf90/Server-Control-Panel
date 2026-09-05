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

**Mitsamt dem Rand — und zwar demselben.** `.notice` trägt
`border-left: 3px solid`, `.band` trug als Sichtwechsel-Streifen
`border-bottom: 1px solid`. Erbt der Streifen nur die Fläche, bleibt vom
Rangsignal die Lasur mit ΔE 3,8 übrig (M9).

**Am 5. September ist daraus dieselbe Kante geworden**, entschieden vom
Betreiber, nachdem fünf Formen nebeneinander standen. Die Hülle rückt die
Bänder ein (`display: flex`, `gap: 8px`, `padding: 12px 16px`), und der Rand
sitzt links mit drei Pixeln und einer Rundung — wie bei `.notice`.

> **Zwei Formen für dieselbe Aussage sind keine Vielfalt, sondern eine Regel,
> die an einer Stelle vergessen wurde.**

**Gemessen, was es kostet** (390 × 844, echtes Panel, beide Themes): Die Hülle
wächst von 195 px auf **214 px**, der Inhalt beginnt bei 279 statt 260 px. Und
die Fassung nimmt 32 px Breite, also rund **acht Zeichen je Zeile** — bei zwei
Zeilen sechzehn weniger Auskunft. Das trifft nur Texte, die ohnehin geklammert
werden.

**Die Fuge ist damit erklärt statt geduldet:** Vorher stand `band + band` als
Ausnahme in `BlockSpacingTest::OPEN_SEAMS`, weil der Abstand aus dem Rand kam.
Mit `flex` und `gap` greift der reguläre Zweig des Wächters.

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

**Der volle Text steht auf einer eigenen Leseseite** — `/announcements/{id}` —,
und **das ganze Band** ist der Verweis dorthin. Kein Aufklapper im Streifen: Das
wäre ein Zustand je Betrachter, und der Betreiber hat das Wegklicken
ausdrücklich nicht bestellt.

**Hier stand bis zum 5. September: „Der volle Text steht auf der
Verwaltungsseite (§5), und der Verweis im Streifen führt dorthin."** Das war
zweimal falsch, und gefunden hat es der Vorflug zum Abnahmelauf. Der Verweis war
nie gebaut — und sein Ziel steht hinter `operate-server`: Kunde, Administrator
und der Unangemeldete auf der Anmeldeseite hätten dort einen 403 bekommen, also
genau die drei Gruppen, für die er da war.

> **Ein Verweis auf einen Ort, den der Leser nicht betreten darf, ist kein Weg
> zum Text — er ist eine zweite Sackgasse.**

**Die Leseseite fragt die Sichtbarkeit nicht selbst.** Angemeldet gilt
`Announcement::visibleTo()`, unangemeldet `Announcement::onLoginPage()` — beides
die Mengen, die der Leser als Streifen ohnehin vor sich hat. Sie liegt deshalb
**ausserhalb der `auth`-Klammer** und gibt für alles andere **404 und nicht
403**: Ein 403 bestätigte die Existenz.

> **Eine öffentliche Route ist dann keine Preisgabe, wenn sie genau das
> ungekürzt zeigt, was anderswo schon gekürzt öffentlich steht.**

**Und der Verweis sitzt an der Fläche und nicht im Text.** Der erste Wurf setzte
ein „mehr" ans Textende, innerhalb der Zeilenklammer — die schnitt es genau dann
weg, wenn der Text lang ist, also im einzigen Fall, für den es das „mehr" gibt.
Ein eigenes Flexkind daneben kostete bei 390 px eine ganze Zeile je Band (an
derselben Stelle für das Rangwort gemessen, M9). Die Fläche kostet **nichts**:
Gemessen am 5. September in acht Lagen — `div` und `a` ergeben Zeile für Zeile
dieselbe Höhe.

> **Ein Weiterlesen, das mit dem Text abgeschnitten wird, fehlt genau dann, wenn
> man es braucht.**

### 4.4 Die Anmeldeseite

`Auth/Login.vue` und `Auth/TwoFactorChallenge.vue` bekommen denselben Streifen,
gefiltert auf `Störung`. Sie tragen `PanelLayout` nicht, also ist es eine zweite
Einbindung — und genau deshalb steht sie hier und nicht als Nebensatz.

---

## 5 · Die Verwaltung

Eine Seite unter `/announcements`, hinter **`operate-server`**.

**Hier stand `manage-settings`, und das war falsch.** Die Begründung lautete
„eine Ankündigung dreht nichts am Server, sie ist Text in einer Tabelle —
dieselbe Art Griff wie die Anzeigezeitzone". Sie ordnet nach dem, was der Griff
**anfasst**, und `docs/20 §6.1` ordnet nach dem, was er **bewirkt**: kritisch
ist unter anderem, was „alle Kunden mitnimmt".

Eine Ankündigung mit dem Publikum „Kunde" erscheint bei **jedem** Kunden, im
Namen des Betreibers. Und Entscheidung 4 setzt Störungen auf die Anmeldeseite —
also vor jeden, der die Adresse kennt, ohne Anmeldung. Das ist ein
Veröffentlichungsrecht und keine Einstellung.

> **Eine Fähigkeit bemisst sich nicht daran, was ein Griff anfasst, sondern
> daran, wen er erreicht.**

**Keine Teilung wie bei „Updates" und „Dienste".** Dort sieht der Administrator
zu und der Betreiber dreht, weil das Zusehen für sich einen Wert hat. Hier hat
es keinen: Was angekündigt ist, sieht ein Administrator ohnehin als Streifen —
sein Publikum steht in der Ankündigung. Eine zweite Ansicht derselben Sache
wäre eine Seite ohne eigene Frage.

Wenn der Betreiber Administratoren das Ankündigen geben will, ist das eine Zeile
in `routes/web.php` und ein Eintrag mit Begründung in
`AdminAbility::administratorRoutes()`.

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
| 3 | **Drei bei 390 px** *(Ausschluss)* | **jedes Band gleich hoch, gleich wie lang sein Text ist** — gemessen 62 px je Band, 214 px mit der Hülle; `schiebt = 0`, Gegenprobe schlägt an |
| 4 | 500 Zeichen | im Streifen zwei Zeilen; **als Kunde** das Band anklicken und den vollen Text auf `/announcements/{id}` lesen — ohne 403 |
| 5 | Publikum | ein Kundenkonto sieht die Kundenankündigung und nicht die für Admins |
| 6 | **Das Fenster** *(Ausschluss)* | mit Anzeigezone auf `Europe/Berlin` gesetzt: sichtbar **während** der eingetippten Ortszeit, davor und danach nicht |
| 7 | Störung auf der Anmeldeseite | abgemeldet sichtbar; eine Ankündigung der Kategorie Info dort **nicht** |
| 8 | Das partielle Nachladen | Streifen steht nach dem Selbstlauf der Übersicht unverändert da |

**Punkt 6 misst mit einem Versatz und nicht in UTC.** In UTC sind der richtige
und der falsche Vergleich gleich; eine fehlende Umrechnung sähe aus wie eine
gelungene (M7).

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

**Punkt 3 misst eine Eigenschaft und nicht eine Zahl.** Die Zahl allein wäre
willkürlich; was die Zeilenklammer zusagt, ist die **Unabhängigkeit von der
Textlänge** — ein Band mit 500 Zeichen ist so hoch wie eines mit 60. Die Zahl
steht daneben, damit ein Ausreisser auffällt.

**Und sie ist beim Bauen einmal berichtigt worden.** Sie lautete „≤ 189 px",
gerechnet aus M8 — dort trugen die Prüfkörper aber noch **kein Rangwort**; das
verlangt M9, und M9 kam nach M8. Der erste Wurf gab dem Wort ein eigenes
Flexkind, es brach bei 390 px in eine eigene Zeile, und drei Bänder kosteten
**264 px**. Im Textfluss kostet dasselbe Wort nichts.

> **Ein Wort, das als eigenes Flexkind steht, nimmt auf der schmalen Fläche eine
> ganze Zeile — auch wenn es vier Zeichen hat.**

> **Ein Budget, das aus einer Messung ohne das spätere Merkmal stammt, ist kein
> Budget mehr — und ein Kriterium, das der Prüfling nicht erfüllen kann, prüft
> den Verfasser.**

**Und die Zahl von Punkt 3 ist am 5. September nachgemessen worden — die
Eigenschaft hält, die Hüllenzahl nicht.** Gegen das gebaute Stylesheet bei
390 px, drei Bänder: 60, 120, 250 und 500 Zeichen ergeben **alle 62 px je Band**
und 226 px für die Hülle. Der Streifen als Verweis kostet dabei **nichts** —
`div` und `a` sind in acht Lagen Zeile für Zeile gleich.

Die 214 px im Kriterium stammen aus einer Messung an der **echten Seite**, meine
226 aus einem Wegwerf-Aufsatz; welche für den Server gilt, entscheidet der Lauf.
Getragen ist davon die **Differenz** und nicht der absolute Wert:

> **Eine Differenz zweier Messungen unter denselben Bedingungen trägt, auch wenn
> die absoluten Werte an der Umgebung hängen.**

Gemessen wird im Lauf deshalb zuerst die Eigenschaft — 60 gegen 500 Zeichen,
dieselbe Höhe — und die Zahl daneben, damit ein Ausreisser auffällt.

**Punkt 2 braucht die breite Ansicht.** Der Fehler aus M2 zeigt sich bei
1440 px und **nicht** bei 390 px — dort stapeln dieselben drei korrekt. Ein
Punkt, der nur schmal misst, geht daran vorbei.

---

## 9 · Die Wächter

Für jede Regel einer, und jeder wird gegen den Fehler gebrochen, den er fangen
soll.

**Gebaut sind fünf und nicht die sieben, die hier zuerst standen** — nicht
weil Regeln weggefallen wären, sondern weil drei von ihnen **denselben
Baustein** beschreiben und einzeln je zwei Zeilen gewesen wären. Die Liste
steht so, wie sie im Repo liegt; eine Planliste, die nicht stimmt, ist
schlimmer als keine.

| Wächter | Fälle | hält |
|---|---|---|
| `AnnouncementBandTest` | 5 | genau **eine** Hülle nimmt `grid-row: 1` (M2) · die Kürzung zählt Zeilen und nicht Zeichen (M8) · jeder Rang trägt Fläche, Rand **und** Textfarbe, und die Kategorie steht als Wort (M9) — die drei, die zuerst `AnnouncementRowTest`, `AnnouncementClampTest` und `AnnouncementRankTest` heissen sollten |
| `AnnouncementShareTest` | 3 | der Schlüssel in `share()` ist ein Verschluss — gemessen an der **Wirkung** (Abfragezähler unter beiden Anfragearten), nicht am Wort `fn` im Quelltext (M5); und die Eigenschaft fehlt beim partiellen Nachladen, statt als leere Liste dazustehen (M6) |
| `AnnouncementWindowTest` | 7 | der Filter rechnet in UTC, gemessen mit einem **Versatz** und einer zweiten Zone daneben (M7) |
| `AnnouncementAudienceTest` | 12 | jedes Publikum trennt an derselben Grenze wie die Rollen aus A9, in beide Richtungen **in einem Fall** — ein eigener Fall für die Gegenrichtung wird beim nächsten Publikum vergessen |
| `AnnouncementPageTest` | 12 | die Tür der Verwaltungsseite (Administrator und Kunde bekommen 403, der Betreiber nicht) und der Streifen der Anmeldeseite — beide Richtungen, denn die stille Hälfte ist die, die zuviel zeigt |

**`AnnouncementShareTest` misst die Wirkung und nicht das Wort.** Ein Wächter,
der `fn () =>` als Zeichenkette sucht, ist grün, sobald es irgendwo in der Datei
steht — dieses Repo hat diesen Fehler oft genug bezahlt.

**Und `AnnouncementPageTest` hat seinen Prüfkörper einmal an der falschen Hürde
verloren.** Er rief `DELETE /announcements/1`; die Modellbindung läuft **vor**
`can:`, also gab eine Kennung, die es nicht gibt, **404** statt 403 — beim
Betreiber wurde daraus ein falsches Grün, weil 404 nicht 403 ist.

> **Eine Gegenprobe, die an einer anderen Hürde scheitert als der gemeinten, hat
> die gemeinte nicht geprüft.**

Er legt die Zeile jetzt an und prüft ausdrücklich, dass **keine** 404 kommt.

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
