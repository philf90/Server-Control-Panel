# Das Protokoll des Nachlaufs zu `0.7.4-rc.16`

**Gefahren am 18. September 2026 auf `cloudsrv24` gegen `0.7.4-rc.16`.** Der
Lauf ist `docs/122`, ausgeschrieben vor dem Fahren. Der Plan der Stufe ist
`docs/117`, der Abnahmelauf `docs/118` mit dem Protokoll `docs/119`, der erste
Nachlauf `docs/120` mit dem Protokoll `docs/121`.

**Alle sechs Punkte erfüllt**, beide Ausschlusskriterien (1 und 3) darunter.
Ausgefallen ist ein Teilpunkt — **2b**, mit dem Grund, den `docs/122 §9` dafür
vorsieht. Teilpunkt **3c** durfte ausfallen und ist trotzdem gefahren.

**Vier Befunde, und keiner davon aus den fünf Behebungen.** Zwei stecken im
Prüfling und stammen aus P8s eigenem Bau, einer ist ein ungeklärter Abbruch am
Formular, einer eine Anzeige, die eine Angabe erfindet.

---

## 0 · Was vor dem Lauf stand

`srvpanel version` → **`0.7.4-rc.16`**. `/var/lib/srvpanel/backups/` leer.

```
srvpanel diagnose    8 Prüfung(en) gefahren, 2026-09-18 17:03:56.  Kaputt: 1

137  p6-b.invalid          active  p1136
140  p6-abnahme.invalid    active  p1139
141  p6-nochmaltest        active  p1140
3 Abos · 0 Sicherungen · 1 Befund

fail  tls.file  expired  p6-b.invalid
```

Der eine Befund gehört dem Prüfstand (`docs/913 §15`) und nicht dem Prüfling.

**Die vier Zeilen, die `docs/122 §0` beim Ausschreiben umgeworfen hat, haben
sich im Lauf alle vier bestätigt** — keine davon ist im Fahren noch einmal
aufgefallen, weil sie vorher am Quelltext gefunden worden waren.

---

## 0b · Der Prüfkörper

Abonnement **146 `p8-rc16.invalid`**, Systembenutzer `p1145`, Plan `Standard`,
Merkmal *Sicherungen* `true`, **Kontingent `NULL`**, eine Domain, zwei
Sicherungen:

```
13  p8-rc16-invalid-20260918-150727-38c063bc   2026-09-18 17:07:27
14  p8-rc16-invalid-20260918-150739-1440d2ee   2026-09-18 17:07:39
```

**Das Kontingent ist der Grund für Befund D** und für den Ausfall von 2b; §9
führt beides.

---

## 1 · Der Weg von der Abonnementseite *(Ausschluss)* — erfüllt

Auf `/subscriptions/146` stehen in der Kopfzeile **acht** Elemente, darunter
beide bestellten:

```
● aktiv · Bearbeiten · Dateien · SFTP-Zugang · Sicherungen · Cronjobs · Sperren · Zurückbauen
```

Beide führen an ihr Ziel — `/subscriptions/146/backups` und
`/subscriptions/146/cron`, je 200.

**Der Cronjob-Knopf zählt mit.** Er ist der, den `SubscriptionReachTest` bei
seinem ersten Lauf selbst gemeldet hat, bevor er jemandem im Weg war — die
sechste Stelle derselben Familie.

**Teilpunkt 1c ist nicht gefahren** und bleibt benannt offen; der Grund steht in
§9 als Befund am Lauf und nicht am Prüfling.

---

## 2 · Der Handelnde

**a) Mit angemeldetem Konto — erfüllt, in Ablage und Anzeige.**

```
975  backup.create  konto=1  p8-rc16.invalid
974  backup.create  konto=1  p8-rc16.invalid
973  backup.create  konto=1  p8-rc16.invalid
```

Auf der Vorgangsseite von 975: **„Ausgelöst von: Administrator"**. Damit ist die
Kennung nicht nur abgelegt, sondern gelesen — die Hälfte, an der Befund 3 hing.

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt.** (`docs/66`)

Die Brotkrume derselben Seite trug `← /subscriptions/146/backups` — die
Herkunft aus `docs/94`, nicht bestellt und trotzdem gemessen.

**b) Der nächtliche Lauf — ausgefallen.** `RunBackups::eligible()` verlangt ein
Kontingent grösser null; der Plan `Standard` führt den Schlüssel `backups`
nicht, und `(int) (null ?? 0)` ist 0. Der Prüfkörper wird abgewiesen, bevor
etwas angelegt würde. Das ist genau der Ausfallgrund aus `docs/122 §9`.

**c) Der durchgereichte Fall — erfüllt.** Vorgang **984** räumt das Verzeichnis
ab, läuft im Arbeiter ohne Request und trägt trotzdem **`konto=1`**: die Kennung
seines Anlasses. Die Gegenprobe dazu steht in §4 — dieselbe Operation, fünfmal
über `tinker` abgesetzt, jedes Mal `konto=NULL`.

> **Dieselbe Operation, zweimal, und der Unterschied ist genau der Handelnde.**

---

## 3 · Das Entfernen hat einen Zustand *(Ausschluss)* — erfüllt

**a) Die erste Liste.** Klick auf *Entfernen*, Rückfrage, bestätigt — und auf
der Seite geblieben:

```
sofort        Zeile auf „wird entfernt", Herunterladen und Zurückspielen fort
danach        Zeile von selbst verschwunden, ohne Neuladen
Vorgang 976   succeeded  konto=1  storage=ja  „entfernt"
```

**b) Die zweite Liste.** Dasselbe auf `/backups`: Die Zeile verliert ihren Knopf,
in der Spalte *Aktion* steht **`wird entfernt`**, und danach ist sie fort. Beide
Listen fragen `running` und nicht zwei Zustandsnamen.

**c) Der Fehlschlag — und `Removing` ist keine Sackgasse.** Hergestellt über
einen unzulässigen `storage_name`, den `Store::storageName()` abweist:

```
Vorgang 978  failed  konto=1
meldung      Unzulässiger Name für eine Sicherung.
Sicherung    ready
last_error   Unzulässiger Name für eine Sicherung.
```

Auf der Seite steht die Zeile wieder auf **`vorhanden`**, trägt den Grund als
graue Zeile darunter und hat **alle drei Knöpfe zurück**. Der Name ist danach
zurückgesetzt, die Dateien sind unberührt (1587 und 1588 Bytes, Zeitstempel
unverändert) — der Vorgang bricht ab, bevor er sie anfasst.

**Dass die Zeile zwischendurch auf `Removing` stand, ist hergeleitet und nicht
gemessen:** `Backups::remove()` setzt den Zustand vor dem Einreihen, und
`last_error` schreibt ausschliesslich `afterFailure()`. Die Anzeige selbst hat
§3a gemessen.

**d) Das Abräumen mit Kennung.** Drei Entfernungen, dann die vierte:

```
981  succeeded  konto=1  storage=ja    entfernt
982  succeeded  konto=1  storage=ja    entfernt
983  succeeded  konto=1  storage=ja    entfernt
984  succeeded  konto=1  storage=NEIN  entfernt
```

Danach ist `/var/lib/srvpanel/backups/` leer. **Von aussen unterscheidet allein
der fehlende `storage` das Abräumen des Verzeichnisses vom Entfernen einer
Datei** — deshalb steht er in der Messung und nicht die Zahl der Vorgänge.

---

## 3e · Der Rückbau, und er ist ein Prüfkörper

`before_removal` stand auf `1` — **gelesen und nicht gesetzt**. Die angesagte
zusätzliche Sicherung ist da:

```
13  abo=NULL  …-150727-38c063bc  ready
14  abo=NULL  …-150739-1440d2ee  ready
16  abo=NULL  …-175616-f678cb08  ready      ← vor dem Rückbau angelegt

Abo 146  fort        p1145  no such user
Dateien  1587 / 1588 / 1588 Bytes
```

**2 + 1 − 1 + 1 = 3**, und Nummer 15 fehlt — genau die, die §3a entfernt hat.
Die Rechnung geht auf, also ist dazwischen nichts passiert, was niemand bemerkt
hätte.

*„Die Sicherung überlebt ihr Abonnement."* — gemessen und nicht zitiert.

Und nebenbei noch einmal §5c: Der Ablagename sagt `175616`, der Zeitstempel der
Datei `19:56`. Dieselben zwei Stunden.

---

## 4 · Drei Ausgänge und zwei Weigerungen — erfüllt

Fünf Lagen, jede einzeln hergestellt, abgesetzt und gelesen:

| Lage | Vorgang | Zustand | `reason` | Meldung |
|---|---|---|---|---|
| **a** fehlt | 985 | succeeded | `absent` | das Verzeichnis gibt es nicht |
| **d** leer | 986 | succeeded | `removed` | entfernt |
| **b** nicht leer | 987 | succeeded | `not_empty` | es liegt noch etwas darin — das Verzeichnis bleibt |
| **c** Verweis → Verzeichnis | 988 | **failed** | `denied` | Der Ablageort ist ein Verweis — es wird nichts entfernt. |
| **c2** Verweis → Datei | 989 | **failed** | `denied` | dieselbe |

**Drei Ausgänge sind `succeeded`, zwei sind Abbrüche** — das ist die
kategoriale Trennung, die Befund 5 gefehlt hat. Eine Weigerung, die wie ein
Ausgang aussieht, liest sich als „da war nichts".

**c2 ist die Gegenprobe zur Reihenfolge und nicht ihre Wiederholung.** `is_dir()`
folgt Verweisen: Bei c wäre auch mit umgekehrter Reihenfolge abgebrochen worden,
nur zwei Zeilen später am `realpath`-Abgleich und mit einer anderen Meldung. Ein
Verweis auf eine **Datei** ist der Fall, den das nicht auffängt — dort sagt
`is_dir()` `false`, und heraus käme `absent`. Dass c2 dieselbe Meldung gibt wie
c, belegt `is_link()` **vor** `is_dir()`.

**Alle fünf mit `konto=NULL`.** `tinker` hat keinen Request, und `NULL` ist dort
die richtige Antwort und nicht die fehlende.

---

## 5 · Der Satz unter beiden Listen — erfüllt

**a) Wörtlich, in beiden Listen:**

> Der Ablagename trägt den Zeitpunkt in UTC; die Spalte Erstellt zeigt ihn in
> der eingestellten Zone.

**b) Die vier Kopfzeilen sind zwei.** `SICHERUNG` und `ERSTELLT` in beiden — und
bei 390 px tragen die gestapelten Zellen dieselben Wörter als Beschriftung. Das
ist die Hälfte, die eine Prüfung an der Kopfzeile nicht sehen könnte.

**c) Und die Behauptung stimmt gegen die Uhr:**

| | Sicherung 1 | Sicherung 2 |
|---|---|---|
| Ablagename | `…-20260918-150739-…` | `…-20260918-150727-…` |
| Spalte *Erstellt* | 2026-09-18 **17:07:39** | 2026-09-18 **17:07:27** |
| Differenz | **+02:00** | **+02:00** |

**An beiden Zeilen und mit den Sekunden** — das schliesst aus, dass hier
zufällig zwei Werte um zwei Stunden auseinanderliegen.

---

## 6 · Acht Lagen — erfüllt

| Seite | Breite | Thema | dokument | gegenprobe | schiebt | rollt | versteckt |
|---|---|---|---|---|---|---|---|
| `/subscriptions/146` | 1440 | dark | 0 | 200 | 0 | 0 | 0 |
| | 390 | dark | 0 | 200 | 0 | 0 | 4 |
| | 390 | light | 0 | 200 | 0 | 0 | 4 |
| | 1440 | light | 0 | 200 | 0 | 0 | 0 |
| `…/backups` | 1440 | dark | 0 | 200 | 0 | 0 | 0 |
| | 1440 | light | 0 | 200 | 0 | 0 | 0 |
| | 390 | dark | 0 | 200 | 0 | 0 | 4 |
| | 390 | light | 0 | 200 | 0 | 0 | 4 |

`stand=2026-09-06` in allen acht — die Vorschrift war die aktuelle und keine
liegengebliebene. Acht Durchläufe heissen acht frische Seitenladungen, weil
`bilderMessen()` beim zweiten Aufruf ohne Neuladen wirft.

**Die zwei neuen Knöpfe kosten nichts.**

**Und eine Erwartung aus dem Nachbau war falsch.** Ich hatte angesagt, bei
390 px stehe „Zurückbauen" allein in der letzten Zeile. Auf dem Server
umbrechen die acht Elemente **4 + 4**; `Zurückbauen` ist das vierte seiner
Zeile. Erledigt als Fehlvorhersage und nicht als Befund. Die zweite Zahl aus dem
Nachbau — 200 → 254 px für die Knopfzeile — **ist nicht gemessen** und bleibt
eine Vermutung.

> **Eine Erwartung aus einer Messung unter anderen Bedingungen ist eine
> Vermutung, auch wenn sie aus einer Messung stammt.**

---

## 7 · Der Abbau als Messung — erfüllt

**Die Erwartung war ausgerechnet und nicht geschätzt:** Der Vorflug sagte 3 / 0 / 1,
angelegt wurden ein Abonnement und vier Sicherungen, entfernt alle.

```
srvpanel backup-verify   1 Prüfung gefahren, 2026-09-18 20:15:43.
                         Keine Befunde an den Sicherungen.
srvpanel diagnose        8 Prüfung(en) gefahren, 20:15:43.  Kaputt: 1

Abos        : 3  (soll 3)
Sicherungen : 0  (soll 0)
Befunde     : 1  (soll 1)      fail  tls.file  expired  p6-b.invalid
```

Dieselbe eine Zeile wie am Anfang.

> **Ein Befund, der verschwindet, wenn sein Grund verschwindet, ist gemessen.
> Ein Befund, der nur entsteht, ist abgelegt.**

---

## 8 · Die Reservierungen — Bestand, nicht Rest

```
p1141  p8-abnahme.invalid   x9405b6af92eaa985   17.09. 11:15:21
p1142  p8-abnahme.invalid   xc3b82b2c371424d2   17.09. 13:28:36
p1143  p8-nachlauf.invalid  x021a34c02ffb11fb   18.09. 08:56:49
p1144  p8-nachlauf.invalid  xef2518aa0e9c98a8   18.09. 10:31:57
p1145  p8-rc16.invalid      x084850b51bff2041   18.09. 15:05:20

146 Reservierungen insgesamt
```

**`docs/121 §12` kannte ein Paar; es sind zwei.** `p8-abnahme.invalid` trägt
zwei Reservierungen, `p8-nachlauf.invalid` ebenfalls — die Abschrift verdoppelt
sich bei **jeder** Wiederherstellung und nicht nur bei einer.

> **Eine Abschrift, die zweimal denselben Namen trägt, kann nicht sagen, welche
> der beiden Zeilen gemeint ist.** Wer den alten Namen zurückgeben will, braucht
> ein Merkmal, das die tote von der lebenden Reservierung trennt — der Name ist
> es nicht, und er wird es mit jedem Lauf weniger.

Abgeräumt wird hier nichts: Das ist der Bestand, an dem `docs/117 §3` hängt.

*(Am 20. September berichtigt: „die offene Entscheidung" stand hier falsch — sie
war am 16. September gefallen, Form A. Was dieser Bestand trägt, ist nicht die
Entscheidung, sondern ihr nachgereichter Grund: Bedingung 1 von Form B zeigt auf
ein Merkmal, das sich bei jeder Wiederherstellung verdoppelt.)*

---

## 9 · Die vier Befunde

**Keiner stammt aus den fünf Behebungen.** Zwei stecken in P8s eigenem Bau, einer
ist ein ungeklärter Abbruch, einer eine ältere Anzeige.

### Befund A — der Knopf kennt den Zustand nicht

Während `wird entfernt` verliert die Zeile in `Backups.vue` ihre Knöpfe
`Herunterladen` und `Zurückspielen` — **`Entfernen` bleibt stehen**. Die beiden
ersten hängen an `usable`, der dritte trägt kein `v-if`.

**Und die zweite Liste macht es richtig:**

```html
<!-- BackupPick.vue -->
<button v-if="!sicherung.running" …>Entfernen</button>
<span v-else>{{ sicherung.status_label }}</span>
```

Es ist damit keine Entwurfsfrage — eine der beiden zeigt, wie es gemeint war.
Die Begründung, mit der `Removing` gebaut wurde, setzt es ausserdem voraus:
*„sonst wäre ‚wird entfernt' eine Sackgasse ohne Knopf und ohne zweiten
Versuch."*

> **Ein Zustand, den eine Zeile anzeigt, ist erst vollständig, wenn ihre
> Bedienelemente ihn auch kennen.**

Gefährlich ist es nicht — ein zweites `backup.remove` fände die Datei nicht mehr.
Aber das Fenster ist nicht immer drei Sekunden lang.

### Befund B — die Rückfrage beschriftet ihren Knopf mit der Meldung

`useConfirmation::ask()` nimmt als zweites Argument das **Verb** des
zustimmenden Knopfes — *„Entfernen", „Sperren"*, sagt sein eigener Kopf. Beide
Sicherungsseiten übergeben dort den ganzen Satz:

```js
ask(
  'Sicherung entfernen',
  `Die Sicherung ${backup.storage_name} wird vom Datenträger gelöscht. Das lässt sich nicht zurücknehmen.`,
  …
)
```

**Gemessen bei 390 px mit offener Rückfrage:**

```
dokument=248  gegenprobe=200 (soll 200)  schiebt=8

248 · html            248 · div.frame
248 · body            248 · main.content
248 · div             264 · div.confirmation
                      280 · div.button-row
                      281 · button.button.danger
```

Der Überlauf wächst nach innen — der innerste Kasten ist der Verursacher. Die
gleiche Seite **ohne** Rückfrage: `dokument = 0` (§6).

Die Wirkung ist nicht nur Überlauf. Die Rückfrage sagt oben nur *„Sicherung
entfernen"*; **was geschieht, steht auf dem Knopf**, und dort abgeschnitten. Der
Kunde liest weder, dass die Datei vom Datenträger verschwindet, noch dass es
sich nicht zurücknehmen lässt.

**Zwei Stellen von 25.** Ausgezählt über alle `ask()`-Aufrufe mit balancierter
Argumentliste: 22 übergeben ein Verb, eine ein Ternär aus zwei Verben, **zwei**
einen Satz — `Backups.vue` und `BackupPick.vue`, beide aus
`30cae6f2 P8 Schritt 5`.

### Befund C — der Kontingent-Override wird nicht gespeichert

Zweimal über `/subscriptions/146/edit` gesetzt, zweimal blieb
`quota_overrides` auf `null` und `keeps()` auf `NULL`. Die Kette ist gelesen und
verliert nichts: `overrideRules()` führt `overrides.backups`, `update()` reicht
`$data['overrides']` durch, `Quotas::overrides()` legt ab.

**Entschieden hat das Protokoll und nicht der Quelltext.**
`subscription.updated` führt in der ganzen Tabelle **vier** Zeilen, alle vom
18. und 24. August, alle zu `p6-b.invalid` — **keine** zu `p8-rc16.invalid`. Und
die Zeile wird *nach* dem Speichern geschrieben.

Zwei dieser vier Zeilen tragen `"overrides":["domains"]` und
`"overrides":["disk_mb"]` — der Weg hat also schon funktioniert.

**Was den Abbruch verursacht hat, ist ungemessen geblieben.** Es ist damit kein
belegter Fehler im Panel, sondern ein Zustand, den niemand hergestellt hat und
niemand erklären kann.

### Befund D — „unbegrenzt" für ein Kontingent, das es nicht sein darf

Auf `/subscriptions/146` stand **„Aufbewahrte Sicherungen — unbegrenzt"**.
Gemessen: `Quota::Backups->allowsUnlimited()` ist **`false`**, `minimum` 1,
`default` 3. Der Zustand ist im Entwurf gar nicht vorgesehen — `Quotas::normalize()`
füllt jeden Schlüssel, ein über das Formular gespeicherter Plan hat ihn also
immer. `Standard` ist älter als P8 und seitdem nie gespeichert worden.

`Quotas::format()` fragt `allowsUnlimited()` nicht:

```php
if ($value === null) {
    return 'unbegrenzt';
}
```

**Und dieselbe Unterscheidung steht zweihundert Zeilen darüber schon
geschrieben**, im Kopf von `overrideRules()`: *„der Wert `null` bedeutet an
dieser Stelle etwas anderes als dort. Er heisst nicht ‚unbegrenzt', sondern
‚keine Übersteuerung'."* Für den Plan ist sie nie gezogen worden.

> **Ein Fehler, den man an einer Stelle vermieden hat, ist an der nächsten
> wieder da, wenn die Vermeidung nicht die Regel wurde.**

**Drei Leser desselben fehlenden Schlüssels, drei Antworten:**

| | liest `null` als |
|---|---|
| `Quotas::format()` (die Seite) | „unbegrenzt" |
| `Retention::keeps()` | `null` → räumt **nie** ab |
| `RunBackups::eligible()` | `(int) (null ?? 0)` = 0 → sichert **nie** automatisch |

Auf diesem Server heisst das für jedes Abonnement auf `Standard`: Knopf da,
automatische Sicherung nie, Zahl der Handsicherungen unbegrenzt. Niemand erfährt
es.

---

## 10 · Was der Lauf über sich selbst gelernt hat

**Die Bilderrunde konnte Befund B nicht finden.** Acht saubere Lagen, und der
Fehler stand die ganze Zeit einen Klick daneben.

> **Eine Bilderrunde misst die Seite, wie sie lädt — ein Zustand, den erst ein
> Klick herstellt, kommt darin nicht vor.**

**Zwei Anweisungen von mir haben ihren eigenen Schritt verschluckt.** Bei §3c
stand das Entfernen als Nebensatz zwischen Verfälschen und Zurücksetzen, und es
ist nicht gefahren worden. Bei §4 standen vier Vorbereitungen in **einem** Block
mit Kommentaren dazwischen; am Stück eingefügt hat die Shell alle vier
nacheinander ausgeführt, und der letzte Zustand überschrieb die drei davor.

> **Ein Block, der vier Zustände nacheinander herstellt, misst keinen.**

Beide Male hat die Messung es gemeldet, nicht das Nachdenken: einmal blieb 976
der jüngste Vorgang, einmal stand das Verzeichnis leer da, wo ein Verweis sein
sollte.

**Ein Endzustand nach dem Ereignis trennt die Wege nicht.** Ein `ready`, nach dem
Vorgang abgelesen, passt zu „auf `Removing` gegangen und zurückgekehrt", zu „nie
losgelaufen" und zu „gar nicht eingereiht". Entschieden hat `last_error` — eine
Spur, die **nur** `afterFailure()` hinterlässt.

> **Ein Endzustand, den man nach dem Ereignis abliest, sagt über den Weg dorthin
> nichts — dafür braucht es eine Spur, die nur dieser eine Weg hinterlässt.**

**Meine erste Zählung der Rückfragen war um vierzehn zu hoch.** Ein Ausdruck, der
blind die zweite *Zeile* nahm, hielt die Fortsetzung der Frage für das zweite
Argument. Erst ein Leser, der die Klammern balanciert, zählt Argumente: 25
Aufrufe, 22 mit Verb, 2 mit Satz.

> **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die Gewohnheit und
> nicht die Regel.**

**Und dreimal habe ich Spaltennamen aus dem Gedächtnis geschrieben statt sie zu
lesen** — `db_prefix` an der falschen Tabelle, `subscription_id` statt
`target_id` am Protokoll, und `name`/`subscription_name`/`created_at` an
`system_users`, wo `number`/`subscription`/`claimed_at` stehen. Die dritte hat
146 leere Zeilen gedruckt. Keine hat ein Ergebnis verfälscht, weil jedes Mal
etwas anderes danebenstand — verlassen sollte sich darauf niemand.

---

## 11 · Die Bilanz

**Sechs Punkte, sechs erfüllt.** Beide Ausschlusskriterien darunter.

| Punkt | |
|---|---|
| §1a/b Weg von der Abonnementseite *(Ausschluss)* | erfüllt |
| §1c Gegenprobe | **nicht gefahren**, §12 |
| §2a Handelnder — Ablage und Anzeige | erfüllt |
| §2b nächtlicher Lauf | **ausgefallen**, Grund benannt |
| §2c durchgereicht | erfüllt |
| §3a–d Zustand beim Entfernen *(Ausschluss)* | erfüllt |
| §4 fünf Lagen | erfüllt |
| §5 Satz, Kopfzeilen, Uhr | erfüllt |
| §6 acht Lagen | erfüllt |
| §7 Abbau | erfüllt |

**Die fünf Behebungen aus `rc.16` haben damit einen Server gesehen.**

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**

**Vier Befunde, und die Lage ist eine andere als bei `docs/121`:** Dort steckten
alle sechs im Prüfling und stammten aus dem frisch gebauten Code. Hier stammt
**keiner** aus den fünf Behebungen — zwei aus P8s eigenem Bau, die Abnahmelauf
und Nachlauf beide überlebt haben, zwei von ausserhalb der Stufe.

---

## 12 · Was offen bleibt

- **Die vier Befunde A bis D sind nicht gebaut.** Eine Behebung ist eine Änderung
  am Prüfling und gehört nicht in den Lauf (`docs/903 §16.1`).
- **§1c ist nicht gefahren.** Die Gegenprobe zu Punkt 1 ist als Betreiber
  wirkungslos (`useFeature()` beginnt mit `isAdmin() => true`), und für einen
  **Kunden** gibt `permissionsFor()` immer alle Rechte zurück — nur ein
  Zusatzbenutzer hat einen beschnittenen Satz. Es bleiben drei Hebel, jeder mit
  eigenem Preis; der Betreiber hat entschieden, den Punkt offenzulassen. Das
  `v-if` bleibt damit durch `OperatorControlTest` und `AbilityReachTest` gehalten
  und auf dem Server ungemessen.
- **§2b ist ausgefallen**, weil `eligible()` den Prüfkörper abweist. Die
  `NULL`-Gegenprobe kam aus §4.
- **`fail · tls.file · expired · p6-b.invalid`** bleibt und gehört dem Prüfstand
  (`docs/913 §15`).
- **Die `3 issues` auf `/backups/<id>/restore`** aus `docs/119 §12` sind
  weiterhin nicht nachgesehen.
  **Am 20. September 2026 nachgesehen** — `docs/126`. Drei Einträge, einer je Bedienelement, alle `FormEmptyIdAndNameAttributesForInputError`; die Ausfüllhilfe und kein Fund, entschieden schon am 23. August in `docs/76`.
- ~~**Die Frage aus `docs/117 §3`** — ob eine Wiederherstellung ihre eigene
  Reservierung zurückholen darf — bleibt offen~~ — **am 20. September
  berichtigt:** entschieden war sie am 16. September (Form A). Der schärfere
  Bestand aus §8 — **zwei** doppelte Abschriften statt einer — bleibt richtig
  und ist ihr nachgereichter Grund.
