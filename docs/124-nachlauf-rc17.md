# P8 — der Nachlauf zu den drei Befunden aus `0.7.4-rc.17`

**Ausgeschrieben am 18. September 2026, vor dem Fahren.** Der Plan ist
`docs/117`, der Abnahmelauf `docs/118` mit dem Protokoll `docs/119`, der erste
Nachlauf `docs/120` mit `docs/121`, der zweite `docs/122` mit **`docs/123`**.

**Warum es ihn gibt:** `docs/123` hat vier Befunde gebracht, und **keiner davon
stammt aus den fünf Behebungen, die jener Lauf prüfen sollte**. Drei sind gebaut
(`393e8f93`), einer bleibt als unerklärter Zustand stehen. Die drei gebauten
haben **keinen Server gesehen** — dieselbe Lage wie vor `rc.16`, eine Runde
später.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**

> **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass zu
> messen.**

**Er nimmt P8 nicht noch einmal ab** und er wiederholt `docs/122` nicht. Was
hier gemessen wird, sind drei Behebungen und sonst nichts.

| | Befund aus `docs/123 §9` | geändert |
|---|---|---|
| **A** | der Entfernen-Knopf kennt „wird entfernt" nicht | `Backups.vue` |
| **B** | die Rückfrage beschriftet ihren Knopf mit der Meldung | `Backups.vue`, `BackupPick.vue` |
| **D** | „unbegrenzt" für ein Kontingent, das es nicht sein darf | `Quotas::format()` |

**Befund C ist nicht dabei.** Der Kontingent-Override wurde zweimal gesetzt und
nie gespeichert, die Kette ist gelesen und verliert nichts, und was den Abbruch
verursacht hat, ist ungemessen geblieben. Es gibt nichts zu prüfen, weil nichts
gebaut wurde.

> **Ein Zustand, den niemand herstellen und niemand erklären kann, ist kein
> Befund — und ihn als einen zu führen wäre schlimmer, als ihn zu benennen.**

---

## 0 · Was beim Ausschreiben umgefallen ist

**Fünf Zeilen**, alle fünf am Quelltext nachgesehen und nicht überlegt.

**Die erste — das Fenster von `Removing` gehört nicht dem, der misst.**
`Backups::remove()` setzt den Zustand **vor** dem Einreihen, und `router.delete`
folgt der Weiterleitung zurück auf dieselbe Seite: Die Anzeige steht also
*sofort* da, und genau so hat `docs/123 §3a` sie gelesen. Wie lange sie steht,
entscheidet aber der Vorgang — ist er vor dem nächsten Takt fertig
(`NACHFRAGE_MS = 3000`), ist die Zeile fort. Ein Blick, der den Knopf nicht
gesehen hat, hat ihn vielleicht nur verpasst.

> **Eine Abwesenheit, die man in einem Fenster von drei Sekunden nicht gesehen
> hat, ist nicht gemessen — sie ist ungesehen.**

Punkt 3 bekommt deshalb **beides**: einen Zustand, der stillhält (`status` von
Hand auf `removing`, danach zurück — Daten und nicht Code, derselbe Griff wie
beim verfälschten `storage_name` in `docs/122 §3c`), und einen **Aufzeichner**
über den echten Weg.

**Die zweite — die leere Zelle ist die Erwartung, und eine leere Zelle sieht aus
wie eine kaputte Seite.** Während `Removing` sind **alle drei** Bedienelemente
fort: `BackupStatus::usable()` ist nur `Ready` (gemessen), und der dritte hängt
seit der Behebung an `!running`. Gemessen wird deshalb das **Paar** aus
Zustandstext und Zahl der Bedienelemente und nicht die Abwesenheit allein —
`vorhanden / 3` davor, `wird entfernt / 0` dazwischen, `vorhanden / 3` danach.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Die dritte — Befund B misst nur gegen sich selbst, wenn Breite *und* Zustand
dieselben sind.** `docs/123 §9` hat `dokument = 248` bei **390 px mit offener
Rückfrage** gemessen; dieselbe Seite **ohne** Rückfrage stand schon damals auf
`0` (`docs/123 §6`). Eine Messung ohne den Dialog wäre grün und bewiese nichts.
Und `bilderMessen()` wirft beim zweiten Aufruf ohne Neuladen. Die Reihenfolge
lautet deshalb: **frisch laden → klicken → einfügen → einmal messen**.

> **Eine Messung, die den Zustand nicht herstellt, in dem der Befund stand,
> misst eine andere Seite.**

**Die vierte — Befund D braucht keinen neuen Prüfkörper, aber seine
Gegenrichtung.** Sechs der vierzehn Kontingente dürfen nicht unbegrenzt sein
(`disk_mb`, `fpm_processes`, `backups`, `php_memory_mb`, `php_upload_mb`,
`php_execution_seconds`), acht dürfen es — eines davon (`php_versions`) ist eine
Auswahl und geht in `format()` einen anderen Weg. Ein Fix, der aus **jedem**
`null` „nicht festgelegt" machte, wäre so falsch wie vorher, nur andersherum;
auf einer Ansicht, die nur die sechs zeigt, sähe er dabei richtig aus. Die
Abonnementseite zeigt **alle vierzehn** in *einer* Liste — dort werden beide
Richtungen nebeneinander gelesen.

> **Eine Behebung, die den Fehler durch seinen Spiegel ersetzt, ist an der
> Stelle, an der er sass, nicht mehr zu sehen.**

**Die fünfte — die Behebung ändert auch die Planseiten, und das darf nicht wie
ein Befund aussehen.** `Quotas::format()` hat sieben Aufrufstellen: **fünf** in
`PlanController` (die Kennzahlen der Planliste und das „von → auf" einer
Änderung) und **zwei** in `SubscriptionController` (die Liste am Abonnement und
`plan_value` im Kontingentformular). Ein Plan ohne `disk_mb` liest sich ab
dieser Fassung überall anders. Das ist gewollt und steht deshalb hier, bevor
jemand es auf `/plans` bemerkt.

**Ausserdem gelesen, bevor gemessen wird:**

- `srvpanel version` muss **`0.7.4-rc.17`** sein. Eine Messung gegen die
  installierte Fassung von gestern misst den Befund und nicht die Behebung.
- `docs/123 §12` — was offen bleibt und was dieser Lauf **nicht** klärt.

---

## 0b · Der Vorflug und der Prüfkörper

**Zuerst der Ausgangszustand, und zwar aufgeschrieben.** Ohne ihn ist am Ende
nicht zu trennen, was dieser Lauf angerichtet hat und was schon dastand.

```bash
srvpanel version
ls -la /var/lib/srvpanel/backups/
srvpanel backup-verify
srvpanel diagnose --json | head -40
```

```php
// srvpanel tinker — je Zeile eine Anweisung
App\Models\Subscription::withoutGlobalScopes()->get(['id','name','plan_id','status'])->each(fn($s)=>print("$s->id  $s->name  plan=$s->plan_id  {$s->status->value}\n"));
App\Models\Backup::withoutGlobalScopes()->get(['id','subscription_id','storage_name','status'])->each(fn($b)=>print("$b->id  abo=".($b->subscription_id ?? 'null')."  $b->storage_name  {$b->status->value}\n"));
```

**Der Prüfkörper: ein Abonnement `p8-rc17.invalid`** auf dem Plan `Standard`,
mit dem Merkmal *Sicherungen*, einem Kontingent von **mindestens 1** und
**einer** Sicherung.

**Eine, nicht zwei — und das ist nachgerechnet und nicht gespart.** `docs/122`
brauchte zwei, weil sein Punkt 3 eine im Fehlschlag verbrauchte. Hier verbraucht
kein Punkt eine: Punkt 1 fasst nichts an, Punkt 2 bricht die Rückfrage ab, und
Punkt 3 setzt einen Zustand von Hand und wieder zurück. Dieselbe eine Sicherung
trägt danach Punkt 4 als Verwaiste.

> **Ein Prüfkörper, den ein späterer Punkt desselben Laufs verbraucht, fehlt
> nicht am Anfang — er fehlt, nachdem er dagewesen ist.** (`docs/122 §0b`)

**Und `Standard` ist der Plan, an dem Befund D entstanden ist** (`docs/123 §9`):
Er führt den Schlüssel `backups` gar nicht, weil er älter ist als P8. Welche
Schlüssel er sonst nicht führt, sagt der Vorflug — und daraus folgt die
Erwartung von Punkt 1, Zeile für Zeile.

---

## 0c · Die Reihenfolge

| Reihe | Punkt | braucht |
|---|---|---|
| 1 | §1 das Kontingent | Abonnement, **noch keine** Sicherung nötig |
| 2 | §2 das Verb auf dem Knopf | Abonnement, 1 Sicherung auf `vorhanden` |
| 3 | §3 der Knopf kennt den Zustand | dieselbe Sicherung |
| — | **Rückbau** des Abonnements, die Sicherung bleibt stehen | |
| 4 | §4 die zweite Liste | **verwaiste** Sicherung |
| 5 | §5 Abbau | |

Punkt 1 kann vor dem Anlegen der Sicherung gefahren werden — er liest
Kontingente und keine Sicherungen. Punkt 3 kommt **nach** Punkt 2, weil er den
Zustand der Zeile verändert.

---

## 1 · Befund D — „unbegrenzt" nur, wo es das sein darf *(Ausschluss)*

Auf `/subscriptions/<id>` steht der Bereich mit den Kontingenten. Gemessen wird
er gegen eine Erwartung, die **vorher ausgerechnet** wird.

**a) Die Erwartung, vor dem Blick auf die Seite:**

```php
// srvpanel tinker — eine Zeile
$s = App\Models\Subscription::withoutGlobalScopes()->find(<id>); foreach (App\Support\Plans\Quota::cases() as $q) { $v = $s->quota($q->value); $soll = $q->isSelection() ? '(Auswahl)' : ($v === null ? ($q->allowsUnlimited() ? 'unbegrenzt' : 'nicht festgelegt') : '(Zahl)'); printf("%-22s wert=%-8s soll=%-16s ist=%s\n", $q->value, var_export($v, true), $soll, App\Support\Plans\Quotas::format($q, $v)); }
```

`soll` kommt aus `allowsUnlimited()`, `ist` aus `format()` — **zwei Quellen und
nicht eine**. Verglichen wird zweimal: `soll` gegen `ist`, und danach `ist`
gegen die Seite.

> **Ein Prüfkörper, der seine Erwartung aus dem Prüfling ableitet, misst dessen
> Selbstbeschreibung.**

**b) Die Seite, als Betreiber.** Aufgeschrieben wird **jede Zeile mit `null`**,
beide Sorten:

```
Aufbewahrte Sicherungen        soll: nicht festgelegt
Speicherplatz                  soll: nicht festgelegt   (wenn der Plan ihn nicht führt)
…                              soll: unbegrenzt         (jedes Kontingent, das es sein darf)
```

**Beide Richtungen in einer Ansicht.** Steht nirgends mehr „unbegrenzt", ist der
Fehler durch seinen Spiegel ersetzt; steht bei `backups` weiter „unbegrenzt",
ist er gar nicht behoben.

**c) Die Gegenprobe am Kontingentformular.** `/subscriptions/<id>/quotas` zeigt
je Zeile den **Planwert** über denselben Weg (`plan_value`). Dort muss dasselbe
Wortpaar stehen. Ein Wert, der auf zwei Seiten verschieden gelesen wird, wäre
eine zweite Fassung derselben Regel.

**d) Und die Planliste.** `/plans` zeigt Speicherplatz, Domains und Datenbanken.
Führt ein Plan `disk_mb` nicht, steht dort ab dieser Fassung **„nicht
festgelegt"** statt „unbegrenzt". Das ist §0, fünfte Zeile — aufgeschrieben
wird, was dasteht, und es ist kein Befund.

**Was dieser Punkt nicht misst:** `Retention::keeps()` und
`RunBackups::eligible()` lesen denselben fehlenden Schlüssel weiterhin als
`null` und tun daraufhin **nichts** — kein Abräumen, keine automatische
Sicherung. Das ist eine Entscheidung und kein Rest (`docs/123 §9`), und sie
steht so im Kopf von `Quotas::format()`.

> **Eine Behebung, die aus einer falschen Anzeige ein Löschen macht, ist teurer
> als der Fehler.**

---

## 2 · Befund B — auf dem Knopf steht sein Verb *(Ausschluss)*

**Gemessen bei 390 px mit offener Rückfrage** — und nur so, siehe §0, dritte
Zeile.

**a) Der Ablauf, in dieser Reihenfolge:**

1. Fenster auf **390 px**, `/subscriptions/<id>/backups` **frisch laden**.
2. *Entfernen* drücken. Die Rückfrage steht, **nicht bestätigen**.
3. `tests/bilder-messen.js` einfügen, **einmal** `bilderMessen()` aufrufen.

```
erwartet:  dokument = 0     gegenprobe = 200 (soll 200)
```

`docs/123 §9` hat an derselben Stelle `dokument = 248`, `schiebt = 8` und den
Knopf mit **281 px** über seinem Kasten gemessen. Die Zahl von damals ist die
Gegenprobe in der Zeit; ein Eingriff ist nicht nötig.

> **Ein Wert, den man nur einmal misst, belegt einen Zustand. Zwei Messungen an
> derselben Stelle belegen eine Änderung.** (`docs/918`)

**b) Der Wortlaut, und er ist die eigentliche Wirkung.** Aufgeschrieben wird,
was die Rückfrage zeigt:

```
Frage, Zeile 1   Die Sicherung <name> entfernen?
Frage, Zeile 2   Sie wird vom Datenträger gelöscht. Das lässt sich nicht zurücknehmen.
Knopf            Entfernen
```

Der Befund war nicht in erster Linie der Überlauf: Die Rückfrage sagte oben nur
*„Sicherung entfernen"*, und **was geschieht, stand auf dem Knopf** — dort
abgeschnitten.

**c) Abbrechen.** Die Rückfrage wird verworfen, die Sicherung bleibt. Punkt 3
braucht sie.

---

## 3 · Befund A — der Knopf kennt den Zustand *(Ausschluss)*

**a) Der Zustand, der stillhält.** Gesetzt wird er an den Daten, nicht am Code:

```php
// srvpanel tinker — setzen
$b = App\Models\Backup::withoutGlobalScopes()->find(<id>); $b->forceFill(['status' => App\Enums\BackupStatus::Removing])->save(); print($b->status->value."\n");
```

Seite neu laden und **drei Dinge** ablesen — zwei Breiten, 1440 px und 390 px:

```
Spalte Zustand        wird entfernt
Spalte Aktion         0 Bedienelemente   (weder Herunterladen noch Zurückspielen noch Entfernen)
dokument              0                  (Gegenprobe 200)
```

Die Aktionszelle ist **leer** und trägt kein Ersatzwort — anders als in der
zweiten Liste, und das mit Grund: Diese Tabelle hat eine Spalte *Zustand*, und
derselbe Satz stünde sonst zweimal in einer Zeile.

**b) Zurück, und das ist die Gegenprobe:**

```php
// srvpanel tinker — zurück
$b = App\Models\Backup::withoutGlobalScopes()->find(<id>); $b->forceFill(['status' => App\Enums\BackupStatus::Ready])->save(); print($b->status->value."\n");
```

Nach dem Neuladen stehen **drei** Bedienelemente da und die Spalte sagt
*vorhanden*. Ohne diese Richtung bliebe offen, ob die Zelle **immer** leer ist —
und das wäre schlimmer als der Befund.

**c) Der echte Weg, mit einem Aufzeichner.** Er misst dasselbe am wirklichen
Entfernen, und weil das Fenster ihm nicht gehört, wird es aufgezeichnet statt
angesehen. **Vor** dem Klick in die Konsole einfügen:

```js
// Zeichnet je 200 ms (Zustand / Zahl der Bedienelemente) der Zeile auf.
;(() => {
  const NAME = '<storage_name>'
  const folge = []
  const lies = () => {
    const zelle = [...document.querySelectorAll('td[data-column="Sicherung"]')]
      .find(td => td.textContent.includes(NAME))
    if (!zelle) return 'Zeile fort'
    const tr = zelle.closest('tr')
    const zustand = (tr.querySelector('td[data-column="Zustand"]')?.textContent ?? '?')
      .trim().split('\n')[0].trim()
    const n = tr.querySelectorAll('td[data-column="Aktion"] a.button, td[data-column="Aktion"] button').length
    return zustand + ' / ' + n
  }
  const takt = setInterval(() => {
    const jetzt = lies()
    if (folge[folge.length - 1] !== jetzt) {
      folge.push(jetzt)
      console.log(new Date().toISOString().slice(11, 23), jetzt)
    }
  }, 200)
  setTimeout(() => { clearInterval(takt); console.log('FOLGE', folge) }, 30000)
})()
```

Dann *Entfernen* drücken und bestätigen. Erwartet ist eine Folge von drei
Zuständen:

```
vorhanden / 3   →   wird entfernt / 0   →   Zeile fort
```

**Der mittlere ist der Punkt.** Fehlt er, sagt die Folge nicht „der Knopf war
da", sondern „das Fenster war kürzer als 200 ms" — und dann trägt a) die
Messung, während c) als nicht herstellbar ins Protokoll geht.

> **Eine Folge, die einen Zustand nicht enthält, sagt nicht, dass es ihn nicht
> gab.**

**d) Danach:** Der Vorgang steht auf `succeeded`, die Zeile ist fort. Für Punkt 4
wird **eine neue Sicherung angelegt** — der Rückbau braucht sie.

```php
// srvpanel tinker
App\Models\Operation::withoutGlobalScopes()->latest('id')->take(3)->get(['id','task','account_id'])->each(fn($o)=>print("$o->id  $o->task  konto=".($o->account_id ?? 'NULL')."\n"));
```

---

## 4 · Die zweite Liste — derselbe Mechanismus, ein anderer Text

**Hergestellt durch den Rückbau des Abonnements.** Steht *„vor dem Rückbau
sichern"* an, legt er zusätzlich eine Sicherung an — willkommen und mitgezählt.
Danach steht die Sicherung ohne Abonnement auf `/backups`.

**a) Die Rückfrage dort trägt einen anderen Satz**, und der ist nicht durch
Punkt 2 mitgemessen:

```
Frage, Zeile 1   Die Sicherung <name> entfernen?
Frage, Zeile 2   Sie wird vom Datenträger gelöscht. Ihr Abonnement gibt es nicht
                 mehr — danach ist dieser Stand fort.
Knopf            Entfernen
```

> **Ein Mechanismus, der an einer Stelle belegt ist, trägt die anderen Stellen —
> ihre Texte trägt er nicht.** (`docs/903`)

Bei 390 px, frisch geladen, mit offener Rückfrage: `dokument = 0`,
`gegenprobe = 200`. Der Satz ist **länger** als der in Punkt 2 — wenn eine der
beiden Stellen schiebt, dann diese.

**b) Der Zustand in der Aktionsspalte.** Hier steht ein `v-else` mit dem
Zustandswort, weil diese Tabelle **keine** Spalte *Zustand* hat. Gemessen mit
demselben Griff wie in Punkt 3a (`status` auf `removing`, danach zurück):

```
Spalte Aktion, während Removing    wird entfernt      (kein Knopf)
Spalte Abonnement, während         <name> ohne Verweis
danach                             Knopf „Entfernen" und Verweis zurück
```

`BackupPick.vue` hat das seit P8 Schritt 5 richtig gemacht — hier wird belegt,
dass es das auf dem Server auch tut, und dass die eine Bedingung beide
Bedienelemente trägt.

---

## 5 · Abbau als Messung

Die verwaiste Sicherung wird über den Knopf entfernt, und danach steht der
Bestand da, der vorher dastand.

```bash
srvpanel backup-verify
srvpanel diagnose --json | head -40
ls -la /var/lib/srvpanel/backups/
```

**Die Erwartung wird ausgerechnet und nicht geschätzt:** Abonnements wieder auf
dem Wert des Vorflugs, Sicherungen auf **0**, und derselbe eine Befund wie am
Anfang (`tls.file / expired / p6-b.invalid`).

> **Eine Erwartung, die man aus den Zahlen ausrechnet statt sie zu schätzen,
> macht aus dem Ergebnis einen Beleg — eine geschätzte hätte hier einen Befund
> erfunden.** (`docs/913`)

**Die Reservierungen werden gelesen und nicht abgeräumt.** Nach diesem Lauf
steht eine weitere Abschrift in `system_users`; das ist der Bestand, an dem die
offene Entscheidung aus `docs/117 §3` hängt.

---

## 6 · Was dieser Lauf ausdrücklich nicht prüft

- **P8 als Stufe.** Sie ist am 18. September abgenommen (`docs/121`).
- **Die fünf Behebungen aus `rc.16`.** Sie sind in `docs/123` gemessen.
- **Befund C** — der Kontingent-Override. Ungemessen und ungebaut; siehe oben.
- **`Retention::keeps()` und `RunBackups::eligible()`** bei fehlendem Schlüssel.
  Sie bleiben bewusst auf `null` (`docs/123 §9`, Befund D).
- **Den Rest des Prüfstands** (`tls.file / expired / p6-b.invalid`). `.invalid`
  ist nach RFC 2606 nicht ausstellbar; der Befund gehört dem Prüfstand und nicht
  dem Prüfling (`docs/913 §15`).
- **Die `3 issues` auf `/backups/<id>/restore`** aus `docs/119 §12` — weiterhin
  nicht nachgesehen.
- **Die Frage aus `docs/117 §3`**, ob eine Wiederherstellung ihre eigene
  Reservierung zurückholen darf.
- **Die übrigen 23 `ask()`-Aufrufe.** `ConfirmationVerbTest` hält sie; auf dem
  Server gemessen werden die zwei, die den Befund trugen.

---

## 7 · Wann er durch ist

**Die Punkte 1 bis 3 erfüllt, und alle drei sind Ausschlusskriterien.** Jeder
von ihnen ist ein Befund, den dieser Lauf zu prüfen da ist; fällt einer aus, ist
er nicht gemessen, und das gehört so ins Protokoll und nicht als „erfüllt".

**Was ausfallen darf, und nur das:**

- **Punkt 3c**, wenn die Folge den mittleren Zustand nicht enthält. Dann trägt
  3a die Messung, und im Protokoll steht, dass der echte Weg nicht gelesen
  werden konnte — nicht, dass er gelesen wurde.
- **Punkt 4**, wenn der Betreiber das Abonnement nicht zurückbauen will. Dann
  bleibt der Text der zweiten Rückfrage auf dem Server ungemessen und durch
  `ConfirmationVerbTest` gehalten.
- **Punkt 1c und 1d**, wenn kein Plan den betreffenden Schlüssel auslässt. Dann
  steht im Protokoll, dass die Lage nicht vorkam.

Kein anderer.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten, ist
> keines mehr — es ist eine Zusammenfassung.**

> **Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist nicht
> „nicht herstellbar".**
