# 921 — Das Protokoll des Abnahmelaufs für die Domain-Fusszeile

Gefahren am **15. September 2026** auf `cloudsrv24` gegen **`0.7.4-rc.10`**.
Der Plan ist `docs/919`, der Lauf `docs/920`.

**Alle neun Punkte erfüllt**, beide Ausschlusskriterien (3 und 7) darunter,
keiner als „nicht herstellbar" ausgefallen.

**Kein Befund am Prüfling.** Acht Befunde, alle an der Vorschrift, am Prüfmittel
oder am Prüfstand — und sieben davon haben dieselbe Wurzel (§12).

---

## §1 Ausgangszustand

`srvpanel version` → **0.7.4-rc.10**. `srvpanel-agentd`, `srvpanel-worker`,
`srvpanel-metrics` dreimal `active`.

Bestand: **6 Domains, 3 Abonnements, 5 Kundenkonten.** Die Prüfdomains liegen im
Abonnement `p6-b.invalid` (Systembenutzer `p1136`):

| Punkt | Domain | ID |
|---|---|---|
| 1 | `cloudlab24.ipv64.de` | 56 |
| 2 bis 6, 8, 9 | `neu.cloudlab24.ipv64.de` | 62 |
| 7 | dieselbe, als Kunde 5 | 62 |

**Kein Protokoll dieses Servers liegt von sich aus über der Bytedeckel-Schwelle
— die längste Zeile überhaupt misst 278 B.** Damit ist eine Zeile aus
`docs/919 §11` beantwortet: Punkt 3 muss den Zustand herstellen.

---

## §2 Punkt 1 — die kurze Datei *(erfüllt)*

`/domains/56/logs?kind=access&lines=100`

| | gemessen |
|---|---|
| Fusszeile | **„44 Zeilen · das ist die ganze Datei."** |
| Knopf | **keiner** |
| Satz über den Bytedeckel | **keiner** |
| Pfad | `…/cloudlab24.ipv64.de/access.log · 9 KB` |

---

## §3 Punkt 2 — der Knopf bewirkt etwas *(erfüllt)*

`/domains/62/logs?kind=access&lines=100`, dann gedrückt.

| | `lines=100` | `lines=200` |
|---|---|---|
| erste Zeile | `12:38:43` · **`?i=437`** | `12:38:27` · **`?i=337`** |
| Fusszeile | 100 Zeilen · die Datei ist länger. | 200 Zeilen · die Datei ist länger. |
| Knopf | Mehr Zeilen (100 → 200) | Mehr Zeilen (200 → 400) |

**Die Anfänge liegen genau 100 Aufrufe auseinander, während das Fenster um genau
100 Zeilen gewachsen ist.** Daraus folgt beides auf einmal: Angehängt hat die
Seite nicht (dann stünde oben weiter `?i=437`), von vorn gelesen auch nicht (dann
stünde dort `?i=1`) — und beide Fenster enden an derselben Zeile, denn
437 + 99 = 536 = 337 + 199.

> **Zwei Fenster, deren Anfänge sich um genau die Differenz ihrer Grössen
> unterscheiden, haben dasselbe Ende — das sagt die Rechnung, und dafür muss
> niemand ans untere Ende rollen.**

Das ist mehr, als der Punkt verlangt hat: `docs/920 §3` wollte die letzte Zeile
abgelesen haben. Die Rechnung aus zwei unabhängigen Lesungen ist der stärkere
Beleg, weil sie nicht am Rollen des Betrachters hängt.

---

## §4 Punkt 3 — der Bytedeckel *(Ausschlusskriterium, erfüllt)*

Hergestellt über die echte Route: 120 Aufrufe mit 6000 Zeichen
Abfragezeichenkette. **Kürzeste der letzten hundert Zeilen: 6088 B** — über der
Schwelle von 5243.

| | gemessen |
|---|---|
| geliefert | **86** von 100 angefragten |
| Fusszeile | **„86 Zeilen · weiter zurück wurde nicht gelesen; das Fenster ist auch in Bytes begrenzt."** |
| Knopf | **keiner** |
| Satz „das ist die ganze Datei" | **steht nicht da** |

Das ist der Fall, den es ohne die Messrunde in `docs/919 §1` nicht gäbe: Von
aussen sieht ein gedeckeltes Fenster Zeichen für Zeichen aus wie eine kurze
Datei.

---

## §5 Punkt 4 — eine grössere Anfrage ändert nichts *(erfüllt)*

**Der erste Lauf war keiner.** `lines=100` gab 86 Zeilen, `lines=500` gab **87** —
und dazwischen waren 220 Bytes in die Datei gelaufen, eine kurze Botzeile. Die
Abweichung liess sich erklären, aber „erklärbar" ist nicht „gemessen".

**Wiederholt mit Klammer und nummerierten Zeilen:**

| | gemessen |
|---|---|
| vor der Messung | 1 524 251 B |
| nach der Messung | **1 524 251 B** — byteweise identisch |
| `lines=100` | 86 Zeilen, oberste **`?i=35`**, 12:57:49 |
| `lines=500` | **86** Zeilen, oberste **`?i=35`**, 12:57:49 |

Fünffache Anfrage, identisches Ergebnis bis auf die Zeile genau. **Der
Fensteranfang kommt vom Bytedeckel und nicht von der Anfrage** — und damit ist
die Abwesenheit des Knopfes in Punkt 3 eine Messung und keine Entwurfsmeinung.

> **Zwei Läufe, die sich nur darin unterscheiden, ob der Gegenstand stillstand,
> trennen den Befund vom Rauschen.**

---

## §6 Punkt 5 — die Gegenprobe zum Deckel *(erfüllt)*

120 gewöhnliche Aufrufe, **längste der letzten hundert: 91 B**.

| | vorher (Punkt 3) | nachher |
|---|---|---|
| Zeilen | 86 von 100 | **100** |
| Fusszeile | Bytedeckel | **„die Datei ist länger."** |
| Knopf | keiner | **Mehr Zeilen (100 → 200)** |

Der Deckelsatz ist fort, der Knopf ist zurück. Ohne diese Richtung bliebe offen,
ob die Seite den Deckel gemessen oder nur behauptet hat.

---

## §7 Punkt 6 — die Grösse neben dem Pfad *(erfüllt)*

Die Frage war offen und nicht trivial: `formatBytes` rechnet im Quelltext mit
1024, und eine frühere Ablesung (85 KB bei gemessenen 85 419 B) passte besser zu
1000.

**Entschieden durch eine Klammer um das Laden:**

| | Bytes | 1024er | 1000er |
|---|---|---|---|
| vor dem Laden | 792 739 | **774 KB** | 793 KB |
| **die Seite zeigt** | | **774 KB** | |
| nach dem Laden | 792 959 | **774 KB** | 793 KB |

Die beiden Basen liegen 19 KB auseinander — das ist nicht zu verwechseln. **Die
Basis ist 1024, wie im Quelltext.** Die frühere Ablesung war das Wachstum einer
Datei, in die währenddessen ein Bot schrieb.

Alle drei Zweige von `formatBytes` sind dabei belegt worden: **925 B** (unter
1024, roh), **774 KB** (gerundet), **1,5 MB** (eine Nachkommastelle, deutsche
Schreibweise).

---

## §8 Punkt 7 — der Kunde sieht dieselbe Fusszeile *(Ausschlusskriterium, erfüllt)*

Gewechselt über `/customers/5` in das Konto **Philipp Foos**. Der Streifen sagt
es: *„Sie arbeiten in der Sicht dieses Kunden. Angemeldet als Philipp Foos,
gewechselt von Administrator."* Die Navigation ist die des Kunden —
Abonnements, Dateien, SFTP-Zugang, Mein Konto —, kein Adminmenü.

| | Betreiber | Kunde |
|---|---|---|
| Antwort | 200 | **200, kein 403** |
| Fusszeile | 100 Zeilen · die Datei ist länger. | **Wort für Wort dieselbe** |
| Pfad und Grösse | `…/access.log · 1,5 MB` | dieselbe |
| Knopf | Mehr Zeilen (100 → 200) | derselbe |
| oberste Zeile | `?k=21` | **`?k=21`** |

**Die letzte Zeile ist der Grund, warum der Vergleich etwas bedeutet:** Beide
Ansichten zeigen denselben Fensterinhalt, es sind also zwei Sichten auf
**denselben Zustand** und nicht zwei ähnliche Zustände.

---

## §9 Punkt 8 — der Agent antwortet nicht *(erfüllt)*

Der Weg, den `docs/919` umgebaut hat: Der Fehlschlag reist seitdem **neben** der
Antwort statt in ihr.

| | gemessen |
|---|---|
| `stop srvpanel-agentd` | `inactive` · `inactive` · `inactive` |
| die Seite | **„Der Agent antwortet nicht: Der Agent läuft nicht: Socket ist nicht vorhanden."** |
| Fusszeile, Knopf, Liste | **keine** — und keine leere Liste, die wie ein leeres Protokoll aussähe |
| `start srvpanel.target` | `active` · `active` · `active` |

`Requires=` überträgt das Anhalten auf Worker und Metrik; zurück geht es über das
Ziel und nicht über den Agenten allein (`docs/100 §9.10`).

---

## §10 Punkt 9 — die Bilderrunde *(erfüllt)*

Gemessen mit `tests/bilder-messen.js`, Stand **2026-09-06**, je Lage in einer
frisch geladenen Seite. Zustand: der gedeckelte aus Punkt 3 — 86 Zeilen, die
zweizeilige Fusszeile, 2,2 MB.

| Lage | `dokument` | Gegenprobe | `schiebt` | `rollt` | `versteckt` |
|---|---|---|---|---|---|
| hell · 1440 | **0** | **200** (soll 200) | 0 | 1 | 0 |
| hell · 390 | **0** | **200** (soll 200) | 0 | 1 | 0 |
| dunkel · 1440 | **0** | **200** (soll 200) | 0 | 1 | 0 |
| dunkel · 390 | **0** | **200** (soll 200) | 0 | 1 | 0 |

`rollt = 1` ist der Protokollrahmen — die Entscheidung aus `docs/24`, kein
Befund.

**Der Container hatte alle vier Werte vorhergesagt** (`docs/919 §13`); sie
stimmen. Wo eine Erwartung vor der Messung feststeht, ersetzt das
Nebeneinanderlegen das Beurteilen.

Am Bild, wofür die Zahl blind ist: Bei 390 px bricht der Pfad über drei Zeilen
um, ohne zu zerreissen, die zweizeilige Fusszeile liest sich, und nichts steht
nebeneinander, was untereinander gehört.

---

## §11 Die Befunde

**Acht, keiner am Prüfling.**

| | wo | was |
|---|---|---|
| 1 | Vorschrift | Die Datei war über Nacht gedreht — gestern 8393 Zeilen, heute 481 |
| 2 | Vorschrift | Der Unterschied zweier Fenster lag **ausserhalb des Sichtbaren** |
| 3 | Prüfstand | `domain-mit-richtig-langem-namen.invalid` bedient ihr Protokoll nicht |
| 4 | Vorschrift | 120 Aufrufe ohne Nummer — ununterscheidbare Zeilen |
| 5 | Prüfmittel | Die Mandantenklammer in der `tinker`-Abfrage |
| 6 | Prüfmittel | Ein `continue` im Prüfkörper verdeckte Befund 5 |
| 7 | Vorschrift | `docs/920 §0 (a)` war zu streng |
| 8 | Dokument | `docs/919 §9` zählt sieben Punkte, §13 sprach von acht |

### Befund 1 — die Vorbedingung von gestern

Die Stillstandsprobe und die ausgerechneten Erwartungen stammten vom Vortag;
dazwischen lief `logrotate`, und `cloudlab24.de` bekam Botverkehr.

> **Eine Vorbedingung, die man gestern gemessen hat, gilt heute nicht — und sie
> sieht genauso aus wie eine, die gilt.**

### Befund 2 — der Unterschied ausserhalb des Bildes

Die beiden Fenster trennten sich erst bei `"GET /servi` gegen `"GET /Gemfi`,
jenseits des rechten Randes. Sichtbar waren rund 45 Zeichen, und über hundert
Zeilen teilten sich dieselbe Sekunde.

> **Ein Prüfkörper, dessen Unterschied ausserhalb des Sichtbaren liegt,
> unterscheidet nicht.**

Behoben, indem die Aufrufe zeitlich gespreizt wurden — dann trennt die Uhrzeit,
und die steht im Bild.

### Befund 3 — die 200 kam von jemand anderem

300 Aufrufe an `domain-mit-richtig-langem-namen.invalid`, jeder mit `200`
beantwortet, und die Protokolldatei blieb bei **0 Bytes**. Entweder kennt nginx
den Namen nicht (dann antwortet der Vorgabeblock), oder die Domain hat kein
eigenes Verzeichnis.

> **Ein Rückgabewert von 200 sagt, dass jemand geantwortet hat — nicht, dass der
> Gemeinte geantwortet hat.**

**Der Prüfling hat dabei recht gehabt:** „Das Protokoll ist leer." ist für eine
0-Byte-Datei die richtige der fünf Auskünfte — nicht „gibt es nicht", nicht eine
leere Liste. Auch bei `lines=500`; die Auskunft hängt nicht an der Fensterbreite.

### Befund 4 — ununterscheidbare Zeilen, zum dritten Mal an einem Tag

Die 120 langen Aufrufe trugen alle dieselbe Sekunde und denselben Pfad.

> **Ein Prüfkörper aus lauter gleichen Zeilen unterscheidet nichts — und beim
> dritten Mal ist es keine Unachtsamkeit mehr, sondern eine fehlende Gewohnheit:
> Wer Zeilen erzeugt, nummeriert sie.**

### Befund 5 und 6 — die Klammer und das Überspringen

`Domain::withoutGlobalScopes()` löst die Mandantenklammer für die
**Domain**-Abfrage; `$d->subscription` ist eine nachgeladene Beziehung auf das
*Subscription*-Modell, und das steht auf der Kommandozeile ohne angemeldetes
Konto auf `whereRaw('0 = 1')`. Für alle sechs Domains kam `null`.

> **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer leeren
> Liste und nicht mit einem Fehler.** Die Klammer wird je Modell gelöst und nicht
> je Abfrage.

Und der Prüfkörper hat es verdeckt, weil er ein `continue` trug:

> **Ein Prüfkörper, der überspringt, meldet das Überspringen nicht.**

Beides steht seitdem in `docs/920 §1`. `Account::mayAccessSubscription()` und
`permissionsFor()` sind davon nicht betroffen — beide wickeln ihre Abfragen
selbst in `withoutRestriction()`.

### Befund 7 — die zu strenge Vorbedingung

`docs/920 §0 (a)` verlangte `Permission::FilesRead` für Punkt 7.
`Account::permissionsFor()` gibt jedem Konto, das **kein** Zusatzbenutzer ist,
`Permission::cases()` zurück — ein gewöhnlicher Kunde hat das Recht also immer.
Was wirklich beisst, ist ein **aktives Kundenkonto**, und genau das verlangt auch
`ImpersonationController::start`.

---

## §12 Was der Lauf über sich selbst gelernt hat

**Sieben der acht Befunde haben eine Wurzel**, und sie ist benennbar: Die
Vorschrift ist im Container entstanden, und dort hält der Gegenstand still. Ich
schreibe die Datei, ich kenne ihre Länge, niemand schreibt dazwischen, nichts
dreht sich über Nacht.

> **Eine Vorschrift, die im Container entstanden ist, setzt einen Gegenstand
> voraus, der stillhält — und auf einem echten Server hält nichts still.**

Ein Zugriffsprotokoll ist das Gegenteil eines Prüfkörpers: Es rotiert, es
wächst, und Fremde schreiben hinein. Jede Erwartung, die aus seiner Länge folgt,
ist ab dem Augenblick ihrer Berechnung am Altern.

Was getragen hat, waren drei Handgriffe, und sie gehören in jede künftige
Vorschrift über einen lebenden Gegenstand:

- **Die Erwartung unmittelbar vor der Messung ausrechnen**, nicht am Vortag.
- **Den Gegenstand klammern** — `stat` davor und danach. Sind beide Werte gleich,
  hat er stillgestanden; sind sie es nicht, sagt die Differenz, um wie viel.
- **Jede erzeugte Zeile nummerieren**, und die Nummer nach vorn.

Der dritte ist der billigste und hat am meisten gespart: `?i=35` hat Punkt 4
entschieden, `?k=21` Punkt 7, und `?i=437` gegen `?i=337` Punkt 2.

> **Eine Nummer am Anfang einer erzeugten Zeile kostet nichts und entscheidet
> drei Punkte.**

**Und zweimal hat die Messung mehr getragen, als ihr Punkt verlangt hat** — beide
Male, weil zwei unabhängige Lesungen nebeneinanderlagen: die Rechnung
437 + 99 = 337 + 199 in Punkt 2, und `?k=21` in beiden Sichten in Punkt 7.

---

## §13 Was benannt offen bleibt

- **Warum `domain-mit-richtig-langem-namen.invalid` ihr Protokoll nicht
  bedient** (Befund 3). Eingegrenzt, nicht gemessen — und für keinen Punkt
  tragend.
- **Der Tabellenüberlauf bei sehr langen Kontonamen** (`docs/901 §9`), unverändert
  aus dem Konten-Lauf.
- **Die Fusszeile von `/logs` nennt ohne Filter dieselbe Zahl zweimal**
  (`docs/914 §13`) — auf dem Server bestätigt, nicht entschieden. Die
  Domainseite hat diese Form nicht: Ihre drei Sätze nennen jede Zahl einmal.
- **Die Nummernspalte auf der Domainseite** ist entschieden und nicht offen
  (`docs/919 §15`): Sie kommt nicht.

---

## §14 Die Abnahme

**Der Bau aus `docs/919` ist am 15. September 2026 auf `cloudsrv24` gegen
`0.7.4-rc.10` abgenommen** — alle neun Punkte aus `docs/920`, beide
Ausschlusskriterien (3 und 7) darunter, keiner als „nicht herstellbar"
ausgefallen, **kein Befund am Prüfling**.

Befund 14 aus `docs/86` ist damit an **beiden** Orten gebaut und an beiden
gemessen.
