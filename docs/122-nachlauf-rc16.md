# P8 — der Nachlauf zu den fünf Behebungen aus `0.7.4-rc.16`

**Ausgeschrieben am 18. September 2026, vor dem Fahren.** Der Plan ist
`docs/117`, der Abnahmelauf `docs/118` mit dem Protokoll `docs/119`, der erste
Nachlauf `docs/120` mit dem Protokoll `docs/121`.

**Warum es ihn gibt:** P8 ist am 18. September abgenommen — und die sechs
Befunde des Nachlaufs sind es nicht. Befund 1 steckt in `rc.15` und ist in
`docs/121` an zwei Fassungen gemessen. **Die übrigen fünf haben keinen Server
gesehen**, und das steht wörtlich in der Freigabenotiz von `rc.16`.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**

> **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass zu
> messen.**

**Er nimmt P8 nicht noch einmal ab.** Was hier gemessen wird, sind die fünf
Behebungen und sonst nichts.

---

## 0 · Was beim Ausschreiben umgefallen ist

**Vier Zeilen**, und jede hätte etwas gekostet. Alle vier sind am Quelltext
nachgesehen und nicht überlegt worden.

**Die erste — der Fehlschlag lässt sich nicht durch Anhalten des Agenten
herstellen.** Punkt 3 braucht einen *fehlgeschlagenen* `backup.remove`, und der
naheliegende Griff wäre `systemctl stop srvpanel-agentd`. Gemessen an der
Unit-Datei: `srvpanel-worker.service` trägt `Requires=srvpanel-agentd.service`.
Wer den Agenten anhält, hält den Worker mit an — der Vorgang läuft dann gar
nicht, er steht auf „wartet". Und den Worker allein zu starten holt den Agenten
zurück, weil `Requires=` in dieser Richtung zieht.

> **Ein Prüfkörper, der den Dienst anhält, von dem der Prüfling abhängt, misst
> nicht den Fehlschlag, sondern das Ausbleiben — und die beiden sehen in der
> Zeile verschieden aus, aber keiner von beiden ist der gemeinte.**

**Die zweite — das Entfernen einer Datei kann überhaupt nicht fehlschlagen.**
`BackupRemove` rechnet im Dateizweig `is_file($path) && @unlink($path)` und gibt
**beide** Ausgänge als Erfolg zurück; „nichts zu entfernen" ist kein Fehlschlag.
Die Rückkehr auf `Ready` hängt aber am Fehlschlag des **Vorgangs**. Erreichbar
ist er eine Stufe früher, am Namen: `Store::storageName()` prüft gegen
`/^[a-z0-9][a-z0-9_\-]{0,…}$/D` und wirft sonst `badRequest`. Der Prüfkörper ist
deshalb ein verfälschter `storage_name` in der Zeile — **Daten und nicht Code**
—, und er wird danach zurückgesetzt. Die Datei bleibt dabei unberührt, weil der
Vorgang abbricht, bevor er sie anfasst.

**Die dritte — `backup.verify` legt keinen Vorgang an.** Punkt 2 stand zuerst
mit `srvpanel backup-verify` als Gegenprobe für „kein Handelnder" da. Die
Prüfung ruft den Agenten über `Agent::call()` und reiht nichts ein; es gäbe
keine Zeile zu messen, und die Gegenprobe hätte eine leere Liste als Beleg
gelesen. Sie geht stattdessen über den nächtlichen Lauf `srvpanel:backups`
(`RunBackups`), der `Backups::create()` ohne Request ruft.

> **Eine Gegenprobe an einem Weg, der den gemessenen Gegenstand gar nicht
> erzeugt, misst nicht — und ihre leere Antwort liest sich wie ein Ergebnis.**

**Die vierte — die Gegenprobe zu Punkt 1 ist als Betreiber wirkungslos.**
Sie war zuerst ohne Konto geschrieben: Merkmal abschalten, Knopf muss fort sein.
`SubscriptionPolicy::useFeature()` beginnt aber mit `if ($account->isAdmin())
{ return true; }` — ein Administrator umgeht die Merkmalsfrage ganz. Als
Betreiber gefahren bliebe der Knopf stehen, und das hätte wie ein Befund
ausgesehen.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht** — und beim ersten Mal sieht sein Ergebnis wie der erwartete Befund
> aus.

**Ausserdem gelesen, bevor gemessen wird:**

- `srvpanel version` muss **`0.7.4-rc.16`** sein. Eine Messung gegen die
  installierte Fassung von gestern misst den Befund und nicht die Behebung.
- `docs/121 §12` — was offen bleibt und was dieser Lauf **nicht** klärt.

---

## 0b · Der Prüfkörper, und der Vorflug davor

**Zuerst der Ausgangszustand, und zwar aufgeschrieben.** Ohne ihn ist am Ende
nicht zu trennen, was dieser Lauf angerichtet hat und was schon dastand.

```bash
srvpanel version
ls -la /var/lib/srvpanel/backups/
srvpanel diagnose --json | head -40
```

```php
// srvpanel tinker — je Zeile eine Anweisung
App\Models\Subscription::withoutGlobalScopes()->get(['id','name','status'])->each(fn($s)=>print("$s->id  $s->name  {$s->status->value}\n"));
App\Models\Backup::withoutGlobalScopes()->get(['id','subscription_id','storage_name','status'])->each(fn($b)=>print("$b->id  abo=".($b->subscription_id ?? 'null')."  $b->storage_name  {$b->status->value}\n"));
```

**Dann der Prüfkörper:** ein Abonnement `p8-rc16.invalid` mit dem Merkmal
*Sicherungen*, einem Kontingent grösser null und **zwei** Sicherungen. Zwei,
nicht eine — Punkt 3 verbraucht eine im Fehlschlag und eine im Gelingen, und ein
zweiter Anlauf am selben Gegenstand misst den Zustand von eben mit.

`RunBackups::eligible()` verlangt für Punkt 2 dieselben drei Dinge (Merkmal,
Kontingent, `hasDirectory()`); der Prüfkörper erfüllt sie damit ohne Zutun.

> **Ein Prüfkörper, der einen Zustand braucht, den der Lauf davor verbraucht,
> gehört an einen Gegenstand, der nachwächst.**

---

## 1 · Befund 2 — von der Abonnementseite führt ein Weg *(Ausschluss)*

Die **fünfte** Wiederholung derselben Familie, und die erste, für die es eine
Regel gibt statt einer Zeile: `SubscriptionReachTest` liest die Segmente aus
`Route::getRoutes()`. Gemessen wird hier, dass die Regel auf der echten Seite
ankommt.

**a) Als Betreiber, auf `/subscriptions/<id>`:** Beide Knöpfe stehen da,
**„Sicherungen"** und **„Cronjobs"**, und jeder führt an sein Ziel.

```
Knopf „Sicherungen" → /subscriptions/<id>/backups   → Seite lädt, 200
Knopf „Cronjobs"    → /subscriptions/<id>/cron      → Seite lädt, 200
```

Der zweite ist der, den der Wächter beim **ersten Lauf** gefunden hat, bevor er
jemandem im Weg war. Er zählt hier genauso wie der bestellte.

**b) Als Kunde des Abonnements:** dieselben zwei Knöpfe. Die Seite gehört ihm,
und der Befund war, dass sie unter *Freigaben* „Sicherungen anlegen — frei"
anzeigte und keinen Weg dorthin hatte.

**c) Die Gegenprobe, und sie läuft im Konto des Kunden.** Das Merkmal
*Sicherungen* am Abonnement abschalten, Seite als **Kunde** neu laden: Der Knopf
**„Sicherungen" ist fort**, „Cronjobs" steht weiter. Wieder einschalten: Er ist
zurück.

**Als Betreiber gefahren misst sie nichts** — siehe §0, vierte Zeile.

> **Eine Anzeige, die immer dasteht, belegt die Bedingung nicht, an der sie
> hängt.** Ohne diese Gegenprobe wäre nicht gemessen, ob `v-if` auf
> `can.manageBackups` überhaupt etwas tut — und ein Knopf, der einem Kunden
> ohne das Merkmal einen 403 gibt, wäre schlimmer als gar keiner.

**Aufgeschrieben wird je Lage:** Knopf da (ja/nein), Ziel, Rückgabewert.

---

## 2 · Befund 3 — jeder Vorgang nennt den Handelnden

**a) Mit angemeldetem Konto.** Auf der Sicherungsseite „Jetzt sichern" drücken,
danach:

```php
// srvpanel tinker
App\Models\Operation::withoutGlobalScopes()->latest('id')->take(5)->get(['id','task','account_id','subscription_name'])->each(fn($o)=>print("$o->id  $o->task  konto=".($o->account_id ?? 'NULL')."  $o->subscription_name\n"));
```

Erwartet: die Zeile `backup.create` trägt **die Kennung des angemeldeten
Kontos** und nicht `NULL`.

Dazu die Anzeige: Auf der Vorgangsseite steht in der Spalte **„Wer"** der Name
des Kontos. Eine Kennung in der Datenbank, die keine Oberfläche liest, ist von
aussen nicht von einer zu unterscheiden, die es nicht gibt — derselbe Fall wie
`context` in `docs/66` und `subject_type` in `docs/94`.

**b) Die Gegenprobe: derselbe Vorgangstyp ohne Konto.**

```bash
systemctl start srvpanel-backups.service
journalctl -u srvpanel-backups.service -n 30 --no-pager
```

Erwartet: ein `backup.create` mit `konto=NULL`. **`NULL` ist hier die richtige
Antwort und nicht die fehlende** — auf der Kommandozeile und in der Automatik
ist niemand angemeldet, und ein Eintrag, der sich einen Handelnden ausdenkt,
wäre schlechter als einer ohne. In der Spalte „Wer" steht dafür `System`.

**Die beiden Hälften beantworten zusammen eine Frage, die keine von beiden
allein stellt:** dass die Kennung aus der Sitzung kommt und nicht aus einem
Rückfall, der immer etwas liefert. Stünde in (b) dieselbe Kennung wie in (a),
wäre (a) kein Beleg.

**c) Der durchgereichte Fall.** Punkt 4 setzt das Abräumen des Verzeichnisses
über `tinker` ab, also ohne Request; auch dort steht `NULL`. Der Fall *mit*
Konto — das Abräumen als Folge eines Klicks, im Arbeiter — ist am Ende von
Punkt 3 mitzumessen: Dort verschwindet die letzte Sicherung eines toten
Abonnements, und der Folgevorgang muss **dieselbe** Kennung tragen wie der, der
ihn ausgelöst hat.

---

## 3 · Befund 4 — das Entfernen hat einen Zustand *(Ausschluss)*

Gemeldet vom Betreiber beim Benutzen: *„/backups aktualisiert sich nicht
automatisch wenn das Backup entfernt wurde."*

**a) Der gelingende Weg, und er wird ohne Neuladen gemessen.** Auf
`/subscriptions/<id>/backups` eine Sicherung entfernen und **auf der Seite
stehenbleiben**:

```
unmittelbar danach : die Zeile sagt „wird entfernt"
                     ihr Knopf ist fort
nach ≤ 3 Sekunden  : die Zeile ist fort
ohne einen Neuladevorgang
```

Die drei Sekunden sind `NACHFRAGE_MS` und keine Schätzung.

> **Wer den Zustand sehen will, bleibt stehen.** In `docs/120 §9` steht dieselbe
> Zeile für Befund 2, und sie ist dreimal verpasst worden, weil die
> Vorgangsseite dazwischenkam.

**b) Dasselbe in der zweiten Liste.** Auf `/backups` (die Liste ohne
Abonnement, dem Betreiber) muss eine verwaiste Sicherung denselben Takt zeigen.
Beide Listen fragen `running` und nicht zwei Zustandsnamen; wenn nur eine
taktet, ist es die zweite Fassung derselben Regel und die veraltet.

**c) Der Fehlschlag, und er ist der eigentliche Punkt.** `Removing` ist **kein
Endzustand** — beim Fehlschlag geht die Zeile auf `Ready` zurück, sonst wäre
„wird entfernt" eine Sackgasse ohne Knopf und ohne zweiten Versuch.

Hergestellt nach §0, zweite Zeile:

```php
// srvpanel tinker — Namen merken, dann verfälschen
$b = App\Models\Backup::withoutGlobalScopes()->find(<id>); print $b->storage_name."\n";
$b->forceFill(['storage_name' => 'NICHT-ERLAUBT'])->save();
```

Dann auf der Seite entfernen. Erwartet:

```
Vorgang            : failed, Meldung „Unzulässiger Name für eine Sicherung."
Zeile danach       : „vorhanden" — nicht „wird entfernt", nicht fort
last_error         : gesetzt
Knopf              : wieder da
```

Danach **zurücksetzen** und belegen, dass es zurückgesetzt ist:

```php
$b = App\Models\Backup::withoutGlobalScopes()->find(<id>); $b->forceFill(['storage_name' => '<gemerkter name>'])->save(); print $b->fresh()->storage_name."\n";
```

Die Datei ist dabei unberührt geblieben; die Gegenprobe dafür ist ein `ls` auf
das Verzeichnis vor und nach dem Fehlschlag mit **gleicher Byte-Zahl**.

**d) Und danach der Weg aus Punkt 2c.** Ist die Sicherung die **letzte** eines
Abonnements, das es nicht mehr gibt, folgt ein zweiter `backup.remove` **ohne**
`storage` — der räumt das Verzeichnis ab und trägt dieselbe Kennung.

---

## 4 · Befund 5 — drei Ausgänge, drei Sätze

Die drei Gründe werden einzeln hergestellt und einzeln gemessen. Abgesetzt wird
über `tinker`, weil der **Auslöser** in `docs/121 §4` schon gemessen ist und
hier der **Grund** gemessen wird.

```php
// srvpanel tinker — einmal je Lage
app(App\Support\Backups\Backups::class)->removeDirectory('p8-rc16.invalid');
```

Der Ablageort ist `/var/lib/srvpanel/backups/p8-rc16.invalid`.

| Lage | vorher herstellen | erwartet im Ergebnis | Meldung |
|---|---|---|---|
| **a** fehlt | `rmdir` | `reason=absent`, `removed=false` | „das Verzeichnis gibt es nicht" |
| **b** nicht leer | `mkdir`, eine Datei hinein | `reason=not_empty`, `removed=false` | „es liegt noch etwas darin — das Verzeichnis bleibt" |
| **c** Verweis | `ln -s /tmp …` | **Vorgang `failed`** | „Der Ablageort ist ein Verweis — es wird nichts entfernt." |
| **d** leer | `mkdir` | `reason=removed`, `removed=true` | „entfernt" |

**c ist kein Ausgang, sondern ein Abbruch** — eine Sicherheitsweigerung, und
genau die las sich vorher wie „da war nichts". Gemessen wird deshalb der
**Zustand des Vorgangs** und nicht nur sein Text.

**Und der Verweis wird vor dem Verzeichnis gefragt:** `is_link()` steht vor
`is_dir()`. Die Gegenprobe dafür ist ein Verweis auf eine **Datei** statt auf
ein Verzeichnis — stünde die Reihenfolge andersherum, käme `absent` heraus.

**Nach jeder Lage aufräumen**, und zwar belegt: `ls -la` auf die Wurzel, bevor
die nächste hergestellt wird. Lage **d** steht zuletzt, damit der Ablageort am
Ende fort ist.

**Was hier nicht gemessen wird:** dasselbe Paar in `Db\Dump::removeDirectory()`.
Es trägt wörtlich dieselben drei Wörter aus `Filesystem`, und
`RemovalReasonTest` führt **beide** Paare — ein Wächter über eines allein bliebe
grün, während der Befund eine Datei weiter offensteht. Auf dem Server ist der
Weg dorthin ein anderer Gegenstand und gehört nicht in diesen Lauf.

---

## 5 · Befund 6 — der Satz steht unter beiden Listen

Die Zeile zeigte denselben Zeitpunkt zweimal in zwei Zonen: im Ablagenamen in
UTC, in der Spalte daneben in der Anzeigezone. Beide bleiben.

**a) Der Satz, wörtlich, unter *beiden* Listen** — der eines Abonnements und der
verwaisten:

> Der Ablagename trägt den Zeitpunkt in UTC; die Spalte Erstellt zeigt ihn in
> der eingestellten Zone.

**b) Die vier Kopfzeilen sind zwei.** Dieselben zwei Spalten hiessen in den
beiden Listen vier verschiedene Dinge. Gemessen an der **Zelle**, die den Wert
zeigt, und nicht an der Kopfzeile — die bliebe auch nach einem Vertauschen der
Spalten grün:

```
beide Listen:  <th>Sicherung</th>  <th>Erstellt</th>
die Zellen  :  data-column="Sicherung"  data-column="Erstellt"
```

**c) Und die Behauptung wird gegen die Uhr geprüft.** Aus einem Ablagenamen den
Zeitstempel lesen, aus der Spalte daneben den angezeigten, und die Differenz
gegen den Versatz der eingestellten Zone halten:

```
Ablagename …-20260918-103020-…   → 10:30:20 UTC
Spalte „Erstellt"                → 12:30:20
Versatz laut /settings/general   → +02:00
```

Stimmt die Differenz nicht, sagt der Satz etwas Falsches — und ein Satz, der
eine falsche Zuordnung erklärt, ist schlimmer als keiner.

> **Eine Beschriftung, die eine Zuordnung behauptet, ist eine Messung wert und
> keine Lesung.**

---

## 6 · Die Zahlen bei 390 px und 1440 px

`tests/bilder-messen.js` in der Browserkonsole, **je Aufnahme eine frisch
geladene Seite** — `bilderMessen()` wirft beim zweiten Aufruf ohne Neuladen, und
das ist der Beleg, dass die Bedingung eingehalten wurde.

Vier Lagen je Seite (hell/dunkel × 390/1440), auf `/subscriptions/<id>` und auf
`/subscriptions/<id>/backups`:

```
dokument   : 0
Gegenprobe : 200/200
rollt      : nur gewollte
stand      : mitdrucken
```

**Die beiden neuen Knöpfe sind der Gegenstand.** Im Nachbau kosten sie bei
1440 px nichts und bei 390 px eine Knopfzeile (200 → 254 px); auf dem Server
gemessen wird die Zahl, nicht die Erwartung.

**Und eine Beobachtung aus dem Nachbau gehört nachgesehen, nicht abgehakt:** Bei
390 px steht **„Zurückbauen" allein in der letzten Zeile** und damit über die
volle Breite — die gefährlichste Handlung der Seite ist ihr breitester Knopf.
Das ist kein Kriterium dieses Laufs; wenn es auf dem Server genauso aussieht,
gehört es als Befund ins Protokoll.

> **Eine Erwartung aus einer Messung unter anderen Bedingungen ist eine
> Vermutung, auch wenn sie aus einer Messung stammt.**

---

## 7 · Abbau, und er ist selbst eine Messung

Den Prüfkörper abräumen: die Sicherungen entfernen, das Abonnement zurückbauen,
den Ablageort abräumen lassen.

Danach:

```bash
srvpanel backup-verify
srvpanel diagnose --json | head -40
ls -la /var/lib/srvpanel/backups/
```

Erwartet: **„Keine Befunde an den Sicherungen."**, und in der Diagnose steht
keine Zeile mehr, die diesen Lauf nennt.

> **Ein Befund, der verschwindet, wenn sein Grund verschwindet, ist gemessen.
> Ein Befund, der nur entsteht, ist abgelegt.**

**Die Erwartung wird ausgerechnet und nicht geschätzt** — vor dem Abbau die
Zeilen zählen, die dieser Lauf angelegt hat, und die Differenz hinschreiben.
Eine geschätzte Erwartung erfindet hier einen Befund (`docs/913 §15`).

**Nicht abgeräumt** werden die zwei Zeilen in `system_users` aus `docs/121 §12`
— sie sind der Gegenstand einer offenen Entscheidung und kein Rest.

---

## 8 · Was dieser Lauf ausdrücklich nicht prüft

- **P8 als Stufe.** Sie ist am 18. September abgenommen (`docs/121`). Hier
  stehen fünf Behebungen und sonst nichts.
- **Befund 1** — er steckt in `rc.15` und ist in `docs/121` an zwei Fassungen
  gemessen, mit der Gegenprobe in der Zeit.
- **Den Rest des Prüfstands** (`tls.file / expired / p6-b.invalid`). `.invalid`
  ist nach RFC 2606 nicht ausstellbar; der Befund gehört dem Prüfstand und nicht
  dem Prüfling (`docs/913 §15`).
- **Die `3 issues` auf `/backups/<id>/restore`** aus `docs/119 §12` — weiterhin
  nicht nachgesehen.
- **Die Frage aus `docs/117 §3`**, ob eine Wiederherstellung ihre eigene
  Reservierung zurückholen darf. Ihre Vorfrage ist gemessen und die Entscheidung
  offen.
- **Das Paar in `Db\Dump::removeDirectory()`** — siehe §4, letzter Absatz.

---

## 9 · Wann er durch ist

**Alle Punkte 1 bis 6 erfüllt.** **Punkt 1 und Punkt 3 sind
Ausschlusskriterien**: Der eine ist die fünfte Wiederholung einer Familie, der
andere ist der Befund, den der Betreiber beim Benutzen gemeldet hat.

**Was ausfallen darf, und nur das:**

- **Punkt 3c**, wenn der Betreiber den `storage_name` nicht verfälschen will.
  Dann bleibt die Rückkehr auf `Ready` durch `BackupRemovalStateTest` gehalten
  und auf dem Server ungemessen — und das gehört so ins Protokoll und nicht als
  „erfüllt".
- **Punkt 2b**, wenn `RunBackups::eligible()` den Prüfkörper abweist. Dann ist
  die Gegenprobe über `tinker` aus Punkt 4 zu nehmen, und im Protokoll steht,
  dass sie nicht über den nächtlichen Lauf gegangen ist.

Kein anderer.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten, ist
> keines mehr — es ist eine Zusammenfassung.**

> **Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist nicht
> „nicht herstellbar".**

**Was dieser Lauf nicht abnimmt**, steht in §8 und bleibt benannt offen.
