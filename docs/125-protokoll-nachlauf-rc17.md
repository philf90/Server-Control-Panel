# Das Protokoll zum Nachlauf `0.7.4-rc.17`

**Gefahren am 19. September 2026 auf `cloudsrv24` gegen `0.7.4-rc.17`.** Die
Vorschrift ist `docs/124`, ausgeschrieben vor dem Fahren. Sie prüft die drei
Behebungen aus `393e8f93` und sonst nichts.

**Alle fünf Punkte erfüllt**, **alle drei Ausschlusskriterien (1, 2 und 3)
darunter**, keiner als „nicht herstellbar" ausgefallen. Ein Teilpunkt — **1d** —
ist eine Lage, die es auf diesem Server nicht gibt; das steht unten so da und
nicht als „erfüllt".

**Ein Befund, und er steckt im Prüfmittel.** Am Prüfling **keiner** — dieselbe
Lage wie in `docs/78`, `docs/906`, `docs/909`, `docs/913` und `docs/921`, und
aus demselben Grund: Vorschrift vor dem Fahren ausgeschrieben, Messmittel als
geprüfte Werkzeuge im Repo.

---

## 0 · Der Vorflug

```
srvpanel version        0.7.4-rc.17
Sicherungsverzeichnis   leer (total 8 — nur . und ..)
backup-verify           Keine Befunde an den Sicherungen.
srvpanel diagnose       8 Prüfungen, 2026-09-19 07:50:35.  Kaputt: 1
Befunde                 1   fail  tls.file  expired  p6-b.invalid

Abonnements   137 p6-b.invalid · 140 p6-abnahme.invalid · 141 p6-nochmaltest
              alle plan=1 (Standard), active
Sicherungen   0
```

**Und der Vorflug hat Punkt 1 seinen Prüfkörper geschenkt.** Beide Pläne führen
**zwölf** der vierzehn Kontingente; es fehlen genau zwei, und sie liegen auf
verschiedenen Seiten der Regel:

| fehlender Schlüssel | darf unbegrenzt sein | erwartet |
|---|---|---|
| `backups` | **nein** | **nicht festgelegt** — die Behebung |
| `database_mb` | **ja** | **unbegrenzt** — die Gegenrichtung |

Damit stehen beide Richtungen auf **einer** Seite und in **einer** Liste, ohne
dass etwas hergestellt werden müsste.

---

## 1 · Befund D — „unbegrenzt" nur, wo es das sein darf *(Ausschluss)* — erfüllt

**a) Die Erwartung, vor dem Blick auf die Seite ausgerechnet.** Vierzehn Zeilen,
`soll` aus `allowsUnlimited()`, `ist` aus `format()` — **zwei Quellen und nicht
eine**:

```
database_mb   wert=NULL   soll=unbegrenzt        ist=unbegrenzt
backups       wert=NULL   soll=nicht festgelegt  ist=nicht festgelegt
php_versions  wert=array  soll=(Auswahl)         ist=8.3, 8.4
… elf weitere  wert=<Zahl>  soll=(Zahl)  ist=<formatiert>
```

Alle vierzehn stimmen überein.

**b) Die Seite.** `/subscriptions/137` zeigt alle vierzehn in der Reihenfolge von
`Quota::cases()`, Zeile für Zeile identisch mit `ist`. **Aufbewahrte Sicherungen:
„nicht festgelegt"**, **Datenbankgröße: „unbegrenzt"** — sechs Zeilen
auseinander in derselben Liste.

> **Eine Behebung, die den Fehler durch seinen Spiegel ersetzt, ist an der
> Stelle, an der er sass, nicht mehr zu sehen.** Beide Richtungen nebeneinander
> zu sehen ist deshalb der Punkt und nicht die eine behobene Zeile.

**c) Die zweite Oberfläche.** `/subscriptions/137/edit` zeigt je Kontingent den
**Planwert** über denselben Weg (`plan_value`) — dieselben vierzehn, dasselbe
Wortpaar. Keine zweite Fassung der Regel.

**d) Die Planliste — eine Lage, die es hier nicht gibt.** Kein Plan lässt eines
der sechs gedeckelten Kontingente ausser `backups` aus, und `/plans` zeigt
`backups` nicht. Die fünfte Zeile aus `docs/124 §0` gilt für den Code und kommt
auf diesem Server nicht zum Tragen. **Nicht gemessen, nicht erfüllt — nicht
vorgekommen.**

**Und die Zahl zur Beobachtung.** Die Behebung hat einen angezeigten Wert um
60 % verlängert („unbegrenzt" 10 Zeichen, „nicht festgelegt" 16) und ihn damit
zum längsten Wert dieser Spalte gemacht. Bei 390 px gemessen: `dokument = 0`,
Gegenprobe 200, `schiebt = 0`, `rollt = 0`. Er schiebt nichts.

> **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass zu
> messen.** Diese Seite steht in `docs/124` gar nicht als Messstelle — die eine
> Lage, in der der längere Wert auftritt, ist trotzdem gemessen.

---

## 2 · Befund B — auf dem Knopf steht sein Verb *(Ausschluss)* — erfüllt

**Gemessen bei 390 px mit offener Rückfrage**, also im selben Zustand und auf
derselben Breite wie der Befund:

| | `docs/123`, rc.16 | jetzt, rc.17 |
|---|---|---|
| `dokument` | **248** | **0** |
| `schiebt` | 8 | **0** |
| `button.button.danger` über seinem Kasten | 281 px | — |
| Gegenprobe | 200 | **200** |

> **Ein Wert, den man nur einmal misst, belegt einen Zustand. Zwei Messungen an
> derselben Stelle belegen eine Änderung.** Dieselbe Maschine, dasselbe
> Werkzeug, eine Fassung später — die Gegenprobe steckt in der Zeit und
> brauchte keinen Eingriff.

**Der Wortlaut, und er ist die eigentliche Wirkung:**

```
Die Sicherung p8-rc17-invalid-20260919-084629-50dc6978 entfernen?
Sie wird vom Datenträger gelöscht. Das lässt sich nicht zurücknehmen.
[ Entfernen ]  [ Abbrechen ]
```

**Der 40 Zeichen lange Ablagename bricht jetzt innerhalb der Frage um**, über
zwei Zeilen. Genau dafür trägt `.confirmation` seit P5b `overflow-wrap:
anywhere`, mit der Begründung daneben: *„Was in einer Frage steht, kommt von
aussen — ein Datenbankname, ein Pfad, der Name eines Kunden."* Vorher stand
derselbe Name auf dem Knopf, und dort hat die Regel ihn nie erreicht.

> **Eine Regel, die für die Frage geschrieben ist, gilt nicht für den Knopf —
> und beide sehen im Markup gleich aus.**

---

## 3 · Befund A — der Knopf kennt den Zustand *(Ausschluss)* — erfüllt

**a) Der Zustand, der stillhält** (`status` von Hand auf `removing` — Daten und
nicht Code):

```
1440 px   zustand=wird entfernt   bedienelemente=0
 390 px   dokument=0  gegenprobe=200  schiebt=0  rollt=0
```

Alle **drei** Bedienelemente sind fort, nicht nur das eine: `usable()` ist nur
`Ready`, und der dritte hängt seit der Behebung an `!running`.

**b) Die Gegenrichtung.** Zurück auf `Ready`, Seite neu geladen:
`zustand=vorhanden bedienelemente=3`.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Ohne diese Richtung bliebe offen, ob die Zelle **immer** leer ist —
> und das wäre schlimmer als der Befund.

**c) Der echte Weg, aufgezeichnet** — je 200 ms ein Paar aus Zustandstext und
Zahl der Bedienelemente:

```
12:24:10.475   vorhanden / 3
12:24:20.274   wird entfernt / 0
12:24:23.291   Zeile fort
```

**Das Fenster war genau ein Takt lang, und es war der Takt, der es beendet
hat.** `watch(laeuft, stellen)` startet das Intervall in dem Augenblick, in dem
die Zeile auf `wird entfernt` geht — also um `20.274`. Der erste Abruf danach
ist bei `NACHFRAGE_MS = 3000` um `23.274` fällig; gemessen ist er um
**`23.291`**, **siebzehn Millisekunden später**.

`docs/124 §0` hatte das hergeleitet. Jetzt steht die Zahl daneben.

> **Eine Abwesenheit, die man in einem Fenster von drei Sekunden nicht gesehen
> hat, ist nicht gemessen — sie ist ungesehen.**

Ein Blick, der drei Sekunden woanders war, hätte von `wird entfernt / 0` nichts
gesehen. Der Aufzeichner war nicht Vorsicht, sondern die Bedingung dafür, dass
3c überhaupt eine Messung ist.

Die Seite sagt danach **„Noch keine Sicherung."** — die Zeile hat sich selbst
abgeräumt, ohne Neuladen.

**d) Der Rückbau, und er ist ein Prüfkörper.**

```
 997  backup.create        konto=1   …122455-73fc6f24
 998  backup.create        konto=1   …160333-043cf201
 999  backup.create        konto=1   …160447-cf55433e   ← „vor dem Rückbau sichern"
1000  subscription.remove  konto=1   p8-rc17.invalid / p1146

18  abo=null  …122455-73fc6f24  ready
19  abo=null  …160333-043cf201  ready
20  abo=null  …160447-cf55433e  ready
```

**Vorgang 999 läuft eine Minute vor 1000 und ist fertig, bevor der Rückbau
beginnt** — das Merkmal, das kein Kriterium bestellt hat, noch einmal belegt.
Alle drei Zeilen stehen danach auf `abo=null`: *„Die Sicherung überlebt ihr
Abonnement"*, gemessen und nicht zitiert.

**`konto=1` auch auf 999** — der automatische Lauf vor dem Rückbau nennt den
Handelnden. Das ist Befund 3 aus `docs/121` an der Stelle, an der man ihn am
ehesten verlöre.

Und zum dritten Mal die zwei Zonen aus `docs/123 §5c`: Ablagename `160447`
(UTC), Spalte *Erstellt* `18:04:47`.

---

## 4 · Die zweite Liste — derselbe Mechanismus, ein anderer Text — erfüllt

**a) Die Rückfrage dort trägt einen eigenen Satz**, und er ist länger als der in
Punkt 2:

```
Die Sicherung p8-rc17-invalid-20260919-160447-cf55433e entfernen?
Sie wird vom Datenträger gelöscht. Ihr Abonnement gibt es nicht mehr —
danach ist dieser Stand fort.
[ Entfernen ]  [ Abbrechen ]
```

Bei 390 px, frisch geladen, mit offener Rückfrage: `dokument = 0`,
Gegenprobe 200, `schiebt = 0`.

> **Ein Mechanismus, der an einer Stelle belegt ist, trägt die anderen Stellen —
> ihre Texte trägt er nicht.** (`docs/903`)

**b) Der Zustand, und hier trägt ihn ein `v-else`:**

```
Removing   aktion="wird entfernt"  knoepfe=0  abonnement=ohne Verweis
Ready      aktion="Entfernen"      knoepfe=1  abonnement=Verweis
```

**Eine** Bedingung hat beide Bedienelemente genommen und beide zurückgegeben —
den Knopf und den Verweis auf das Zurückspielen. Zwei Bedingungen über denselben
Zustand liefen auseinander.

**Und das gestapelte Kärtchen zeigt den Unterschied zur ersten Liste sauber:**
Dort bleibt die Zeile *AKTION* leer, hier steht *AKTION — wird entfernt*. Zwei
Tabellen, zwei richtige Anzeigen, **eine** Regel: Die erste hat eine Spalte
*Zustand*, die zweite nicht.

---

## 5 · Der Abbau als Messung — erfüllt

**Sechs Vorhersagen, sechs getroffen.** Die Erwartung war vor dem Abbau
ausgerechnet und nicht geschätzt:

| | erwartet | gemessen |
|---|---|---|
| Abonnements | 3 | **3** |
| Sicherungen | 0 | **0** |
| höchster Vorgang | 1004 | **1004** |
| drei mit `storage`, einer ohne | 1001–1003 ja, 1004 NEIN | **genau so** |
| Befunde | 1 | **1** — `fail tls.file expired p6-b.invalid` |
| `/var/lib/srvpanel/backups/` | leer | **total 8** — nur `.` und `..` |

```
1004  backup.remove   succeeded  konto=1  storage=NEIN
1003  backup.remove   succeeded  konto=1  storage=ja
1002  backup.remove   succeeded  konto=1  storage=ja
1001  backup.remove   succeeded  konto=1  storage=ja
backup-verify         Keine Befunde an den Sicherungen.
```

**Der fehlende `storage` auf 1004 ist der Punkt und nicht die Zahl der
Vorgänge:** Von aussen unterscheidet allein er das Abräumen des Verzeichnisses
vom Entfernen einer Datei. Die Reihenfolge ist wörtlich die aus `docs/121 §4` —
je eine Entfernung ein Vorgang mit `storage`, und erst bei der **letzten** ein
zweiter ohne.

> **Eine Erwartung, die man aus den Zahlen ausrechnet statt sie zu schätzen,
> macht aus dem Ergebnis einen Beleg.** (`docs/913`)

**Die Reservierungen werden gelesen und nicht abgeräumt:**

```
res 1146  p8-rc17.invalid
res 1145  p8-rc16.invalid
res 1144  p8-nachlauf.invalid
```

**Eine Zeile mehr — und keine neue Dublette.** `docs/123 §8` hat zwei Paare
gezählt (`p8-abnahme.invalid` und `p8-nachlauf.invalid` je zweimal) und die
Ursache benannt: *„die Abschrift verdoppelt sich bei jeder Wiederherstellung"*.
Dieser Lauf hat **nicht** wiederhergestellt, und er hinterlässt genau **eine**
Zeile. Die Ursache ist damit von der anderen Seite bestätigt.

> **Eine Behauptung über die Ursache wird von dem Fall bestätigt, in dem die
> Ursache fehlt und die Wirkung ausbleibt.**

---

## 6 · Der eine Befund, und er steckt im Prüfmittel

**`srvpanel diagnose --json` gibt es nicht — zum zweiten Mal.** Die Vorschrift
rief es an **zwei** Stellen so auf; das Kommando heisst `srvpanel:diagnose` und
trägt keine Optionen. Gemessen: *„The `--json` option does not exist."*

**`docs/118 §0.6` hat genau das am 17. September gefunden** — an drei Stellen,
vor dem ersten Befehl, mitsamt der Korrektur (die Befunde stehen in `findings`
und werden über `srvpanel tinker` gelesen). Zwei Tage später stand es in
`docs/122 §0b` wieder da, und von dort ist es nach `docs/124` kopiert worden.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Dokument
> wieder da, wenn die Behebung nicht die Regel wurde.**

**Überlebt hat es den Lauf von `docs/122`, weil niemand es gemeldet hat.**
`docs/123 §7` führt die Ausgabe von `srvpanel diagnose` **ohne** Option — der
Fahrende hat die Zeile stillschweigend berichtigt, und damit hat jener Lauf
etwas anderes gemessen, als seine Vorschrift verlangte.

> **Eine Vorschrift, die der Fahrende beim Fahren berichtigt, steht danach
> weiter falsch da — und das Protokoll daneben sieht aus, als habe sie
> gestimmt.**

Mit derselben Zeile fiel das `| head -40` weg, gegen das CLAUDE.md seit dem
23. August steht. Beide Dokumente sind berichtigt (`b4350c4a`) und lesen die
Befunde über `srvpanel tinker --execute=` in der Form, die `docs/118 §0b`
belegt hat.

---

## 7 · Eine Beobachtung, bewusst nicht behoben

**Bei 390 px steht im gestapelten Kärtchen der ersten Liste während `Removing`
eine Beschriftung ohne Wert:**

```
ZUSTAND    ● wird entfernt
AKTION
```

**Den Anblick gab es vorher nicht** — vorher stand dort der Entfernen-Knopf. Die
Behebung hat ihn erzeugt.

Verloren geht nichts: Was los ist, sagt die Zeile darüber, und `dokument` bleibt
0. Es ist die Sorte Fehler, die keine Zahl hat.

**Gelassen, und mit Grund.** `.stacks` hat für Aktionszellen ein eigenes Muster
— **ohne** `data-column`, volle Breite, links —, aber `Backups.vue` gibt der
Zelle im Normalfall (drei Knöpfe) zu Recht eine Beschriftung; sie zu streichen
nähme dem häufigen Fall seine Überschrift, um den seltenen zu schönen.
`table.pairs td:empty { display: none }` gibt es, greift hier aber nicht: Die
Zelle enthält eine leere `.button-row` und ist im DOM nicht leer. Eine Regel auf
`:has(.button)` in genau dieser einen Spalte wäre die zweite Fassung, die
altert.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.** Und einer, der nichts verbirgt, ist keine Behebung wert, die
> teurer ist als er.

---

## 8 · Die Bilanz

| Punkt | | |
|---|---|---|
| 1 | Kontingentanzeige, beide Richtungen | **erfüllt** *(Ausschluss)* |
| 2 | Verb auf dem Rückfrageknopf | **erfüllt** *(Ausschluss)* |
| 3 | Knopf kennt den Zustand, a–c | **erfüllt** *(Ausschluss)* |
| 4 | zweite Liste, Text und Zustand | **erfüllt** |
| 5 | Abbau als Messung | **erfüllt** |
| 1d | Planliste | **nicht vorgekommen** |

**Befunde: einer, im Prüfmittel. Am Prüfling keiner.** Was `docs/123` an
Befunden gebracht hat, ist damit an drei Stellen auf einem echten Server
gemessen.

**Und wo die Fehler gefunden wurden, sagt dieser Lauf mit:** Die drei Behebungen
selbst hat `docs/123` gefunden — beim Benutzen und nicht durch eine Messung. Der
eine Befund dieses Laufs kam aus dem Vorflug, also aus dem Schritt, der den
Zustand aufschreibt, bevor jemand etwas misst.

> **Ein Abnahmelauf ohne Fund am Prüfling sagt nicht, dass keiner da war — er
> sagt, wo sie gefunden wurden.**

---

## 9 · Was benannt offen bleibt

- **Der Rest des Prüfstands** — `fail tls.file / expired / p6-b.invalid`.
  `.invalid` ist nach RFC 2606 nicht ausstellbar; der Befund gehört dem
  Prüfstand und nicht dem Prüfling (`docs/913 §15`).
- **Befund C aus `docs/123 §9`** — der Kontingent-Override, der zweimal gesetzt
  und nie gespeichert wurde. Ungemessen, ungebaut, und dieser Lauf hat ihn nicht
  berührt.
- **Die `3 issues` auf `/backups/<id>/restore`** aus `docs/119 §12` — weiterhin
  nicht nachgesehen.
  **Am 20. September 2026 nachgesehen** — `docs/126`. Drei Einträge, einer je Bedienelement, alle `FormEmptyIdAndNameAttributesForInputError`; die Ausfüllhilfe und kein Fund, entschieden schon am 23. August in `docs/76`.
- **Die Frage aus `docs/117 §3`**, ob eine Wiederherstellung ihre eigene
  Reservierung zurückholen darf. Der Bestand ist um eine Zeile schärfer und um
  keine Dublette gewachsen.
- **Die leere Aktionszelle bei 390 px** (§7) — bewusst gelassen, nicht
  vergessen.
- **`Retention::keeps()` und `RunBackups::eligible()`** bei fehlendem Schlüssel.
  Sie bleiben auf `null`, und das ist eine Entscheidung (`docs/123 §9`).
