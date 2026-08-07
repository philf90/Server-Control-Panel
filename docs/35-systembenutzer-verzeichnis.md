# 35 — Ein Verzeichnis für verbrauchte Systembenutzer

**Stand: 7. August 2026. Beschlossen, nicht umgesetzt.** Dieses Dokument ist die
Anleitung für die Umsetzung in einer eigenen Sitzung. Es nennt jede Datei, die
angefasst wird, die Reihenfolge der Schritte, die Wächter, die dazugehören, und
die Abnahmekriterien, an denen sich messen lässt, ob es gelungen ist.

Der Betreiber hat den offenen Punkt entschieden: **Weg 1** — `operations`
verliert seinen kaskadierenden Fremdschlüssel und bekommt eine Abschrift des
Abonnementnamens. Die Vorgänge überleben den harten Löschvorgang.

---

## 1. Worum es geht

Ein zurückgebautes Abonnement bleibt heute als Zeile liegen (`deleted_at`
gesetzt, `status = cancelled`). Der Grund steht in
`database/migrations/2026_08_03_190000_add_lifecycle_to_subscriptions.php`: Der
Systembenutzer `p1000` darf nie ein zweites Mal vergeben werden, sonst erbt ein
neuer Kunde alles, was auf dem Dateisystem noch der alten UID gehört.

Der Grund ist richtig. Das Mittel ist zu grob.

**Der Befund, der diesen Plan trägt:** `withTrashed()` auf `Subscription` kommt
in `app/` **genau einmal** vor — in `Lifecycle::nextSystemUser()`
(`app/Support/Subscriptions/Lifecycle.php:68`). Sonst liest nichts im ganzen
Panel ein zurückgebautes Abonnement. Das `status = cancelled`, das
`Lifecycle::withdraw()` vorher noch setzt
(`app/Support/Subscriptions/Lifecycle.php:251`), wird von keiner Stelle je
wieder gelesen; es steht auf einer Zeile, die im selben Atemzug unsichtbar wird.

**121 Zeilen auf dem Zielserver existieren für eine einzige `MAX()`-Abfrage.**

Was sie dabei anrichten:

- Sie halten einen Fremdschlüssel auf `plans` fest. Das war der 500er vom
  7. August 2026 (siehe CHANGELOG und `RestrictedDeleteTest`), und die Behebung
  kostete zwei Commits plus eine Rückfrage beim Löschen, die es sonst nicht
  bräuchte.
- Sie zwingen jede Zählung, zwei Filter abzuziehen, die die Datenbank nicht
  kennt (`SoftDeletes` und die Mandantenklammer). Wer das vergisst, zählt
  weniger als der Fremdschlüssel — genau der Fehler, der passiert ist.
- Sie machen die Reservierung unsichtbar. Sie ist ein Nebeneffekt von
  `deleted_at`, den man nur versteht, wenn man den Kommentar in
  `nextSystemUser()` gelesen hat.

**Das Ziel:** Die Reservierung bekommt eine eigene Tabelle. Abonnements werden
hart gelöscht. Der Zähler bleibt lückenlos und monoton — genau wie heute.

---

## 2. Was ausdrücklich **nicht** geändert wird

**Kunden behalten ihre weiche Löschung.** Das ist keine Inkonsequenz, sondern
der Unterschied zwischen einer Reservierung und einem Geschäftsvorfall:

| | Abonnement | Kunde |
|---|---|---|
| Wer liest den Grabstein? | nur `nextSystemUser()` | `nextNumber()` **und** `LoginController` (`app/Http/Controllers/Auth/LoginController.php:95`) |
| Was hängt daran? | nichts | die Konten des Kunden — sie bleiben stehen und werden bei der Anmeldung abgewiesen |
| Warum bleibt es? | eine UID darf nicht zurückkommen | die Kundennummer steht in Rechnungen |

Der Kundengrabstein trägt Verhalten und einen Vertragsbezug. Der
Abonnementgrabstein trägt eine Zahl. Nur der zweite lässt sich durch ein
Verzeichnis ersetzen, ohne etwas zu verlieren.

**Wer diesen Plan umsetzt, schreibt diesen Absatz sinngemäß in den Kommentar
der neuen Migration.** Sonst steht dort in einem halben Jahr die Frage „warum
Abonnements so und Kunden anders", und die Antwort ist verloren.

---

## 3. Der Zielzustand

### 3.1 Die neue Tabelle

```
system_users
  id          bigint, PK
  number      unsigned integer, unique, not null   -- 1000, 1001, …
  subscription  string(255), nullable              -- Abschrift des Namens bei der Vergabe
  claimed_at  timestamp, not null
```

**Warum `number` als Zahl und nicht `name` als Zeichenkette.** Heute wird die
höchste Nummer in PHP gesucht, weil `CAST(SUBSTRING(...))` auf MariaDB und
SQLite verschieden ausfällt (siehe den Kommentar in `nextSystemUser()`). Mit
einer Zahlspalte ist `MAX(number)` auf beiden dasselbe. Der PHP-Umweg entfällt
— und mit ihm der Grund, aus dem er da war.

Der Name entsteht aus der Zahl (`'p'.$number`) an genau einer Stelle, in
`Lifecycle`. Beides zu speichern wäre eine zweite Fassung derselben Wahrheit.

**Warum `subscription` als Abschrift.** Für die Nachschau: „welcher Kunde hatte
`p1043`". Nullable, weil eine Zeile aus der Datenmigration auch dann entstehen
muss, wenn der Name fehlt. Sie ist eine Abschrift und keine Beziehung — genau
wie `subscriptions.main_domain`, und aus demselben Grund kein Fremdschlüssel.

**Kein `released_at`, kein `uid`.** Eine Nummer wird nie freigegeben, sonst
wäre das ganze Verzeichnis sinnlos. Die echte UID vergibt das Betriebssystem;
sie hier zu führen hiesse, eine Tatsache des Systems in der Datenbank zu
behaupten.

### 3.2 Was aus `subscriptions` verschwindet

- `deleted_at` (die Spalte und `use SoftDeletes` im Modell)
- die Zeilen aller zurückgebauten Abonnements

### 3.3 Was aus `operations` wird

`operations.subscription_id` steht heute auf `cascadeOnDelete`
(`database/migrations/2026_08_02_120100_create_operations_and_audit_tables.php:29`).
Ein harter Löschvorgang nähme das Vorgangsprotokoll mit. Beschlossen ist
**Weg 1**:

- Der Fremdschlüssel wird auf `nullOnDelete` umgestellt.
- `operations` bekommt `subscription_name` (string, nullable) — die Abschrift,
  die nach dem Löschen noch sagt, wovon der Vorgang handelte.

**Folge, die im Plan stehen muss:** Ein Vorgang ohne `subscription_id` fällt aus
der Mandantenklammer heraus (`subscription_id in (…)`, und `NULL` ist in keiner
Liste). Verwaiste Vorgänge sind damit **nur noch für den Admin sichtbar**. Das
ist richtig — der Kunde hat das Abonnement nicht mehr —, aber es ist eine
Verhaltensänderung und gehört in den CHANGELOG.

---

## 4. Die Schritte, in dieser Reihenfolge

Die Reihenfolge ist nicht beliebig. Schritt 3 löscht Zeilen; läuft Schritt 2
nicht vorher, nimmt die Kaskade die Vorgänge mit.

### Schritt 0 — Vorflug auf dem Zielserver

**Vor jeder Migration.** Diese Zahlen werden gebraucht, um hinterher zu prüfen,
ob nichts verloren ging. Sie gehören in den Abnahmebericht.

```bash
mariadb srvpanel -e "
SELECT COUNT(*) AS grabsteine,
       MIN(CAST(SUBSTRING(system_user,2) AS UNSIGNED)) AS kleinste,
       MAX(CAST(SUBSTRING(system_user,2) AS UNSIGNED)) AS groesste
FROM subscriptions WHERE deleted_at IS NOT NULL;

SELECT COUNT(*) AS lebend FROM subscriptions WHERE deleted_at IS NULL;

SELECT COUNT(*) AS vorgaenge_an_grabsteinen
FROM operations o JOIN subscriptions s ON s.id = o.subscription_id
WHERE s.deleted_at IS NOT NULL;

SELECT COUNT(*) AS zertifikate_an_grabsteinen
FROM certificates c JOIN subscriptions s ON s.id = c.subscription_id
WHERE s.deleted_at IS NOT NULL;
"
HOME=/tmp srvpanel tinker --execute="
  echo app(\App\Support\Subscriptions\Lifecycle::class)->nextSystemUser();
"
```

**Die letzte Zahl ist die wichtigste Invariante des ganzen Umbaus: Sie muss
nach der Migration unverändert sein.** Am 7. August 2026 war sie `p1121` bei
121 Grabsteinen und `MAX = 1120`.

**Wenn `zertifikate_an_grabsteinen > 0`:** anhalten und entscheiden.
`certificates.subscription_id` kaskadiert
(`database/migrations/2026_08_05_120000_create_certificates_table.php:48`), die
Zeilen gingen beim Purge mit. Für ein zurückgebautes Abonnement ist das
richtig, aber die Dateien auf der Platte gehören dem Agenten und verschwinden
dadurch **nicht**. Das gehört dann als eigener Punkt in den Rückbau und nicht
nebenbei in diese Migration.

**Und eine Sicherung.** `mariadb-dump srvpanel > /root/vor-35.sql`. Der Purge
ist nicht rückgängig zu machen; die `down()`-Methode kann die Zeilen nicht
wiederherstellen.

### Schritt 1 — Die Tabelle und ihre Füllung

Neue Migration `..._create_system_users_table.php`:

```php
Schema::create('system_users', function (Blueprint $table) {
    $table->id();
    $table->unsignedInteger('number')->unique();
    $table->string('subscription')->nullable();
    $table->timestamp('claimed_at')->useCurrent();
});
```

Danach, **in derselben Migration**, die Füllung aus dem Bestand — mit
`withTrashed`-Semantik, also über `DB::table()` und nicht über das Modell:

```php
foreach (DB::table('subscriptions')
    ->whereNotNull('system_user')
    ->where('system_user', 'like', 'p%')
    ->orderBy('id')
    ->get(['system_user', 'name', 'created_at']) as $row) {

    $number = (int) mb_substr($row->system_user, 1);

    if ($number <= 0) {
        continue;   // ein Name, der nicht dem Muster folgt — nicht raten
    }

    DB::table('system_users')->insertOrIgnore([
        'number' => $number,
        'subscription' => $row->name,
        'claimed_at' => $row->created_at,
    ]);
}
```

**`insertOrIgnore` und nicht `insert`:** Der eindeutige Index ist die Sicherung,
und ein doppelter Name im Bestand darf die Migration nicht abbrechen lassen,
nachdem sie schon die Hälfte geschrieben hat.

**Die Migration prüft ihre eigene Arbeit, bevor sie fertig ist.** Ohne diese
Zeilen ist sie eine Behauptung:

```php
$erwartet = DB::table('subscriptions')
    ->whereNotNull('system_user')->where('system_user', 'like', 'p%')->count();
$geschrieben = DB::table('system_users')->count();

if ($geschrieben !== $erwartet) {
    throw new RuntimeException(
        "Verzeichnis unvollständig: {$geschrieben} von {$erwartet} Namen übernommen."
    );
}
```

`down()`: `Schema::dropIfExists('system_users')`.

### Schritt 2 — `operations` bekommt seine Abschrift

Neue Migration `..._operations_survive_a_deleted_subscription.php`:

```php
Schema::table('operations', function (Blueprint $table) {
    $table->string('subscription_name')->nullable()->after('subscription_id');
});

// Rückwirkend füllen, solange die Zeilen noch da sind — danach nie wieder.
DB::statement('
    UPDATE operations o JOIN subscriptions s ON s.id = o.subscription_id
    SET o.subscription_name = s.name
    WHERE o.subscription_id IS NOT NULL
');

Schema::table('operations', function (Blueprint $table) {
    $table->dropForeign(['subscription_id']);
    $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
});
```

**Achtung, zwei Stolpersteine:**

1. **`UPDATE … JOIN` gibt es in SQLite nicht.** Die Tests laufen auf SQLite. Die
   Rückfüllung gehört deshalb in PHP (`DB::table('operations')->…->chunkById()`)
   oder hinter eine Weiche auf den Treiber. Eine Migration, die nur auf MariaDB
   läuft, bricht `php artisan test`.
2. **`dropForeign` braucht `doctrine/dbal` nicht mehr** (Laravel 11+), aber den
   *Indexnamen* muss man richtig treffen. `['subscription_id']` leitet ihn ab;
   wenn das fehlschlägt, ist er `operations_subscription_id_foreign`.

### Schritt 3 — Der Purge

Eigene Migration `..._withdrawn_subscriptions_are_gone.php`, **nach** Schritt 1
und 2:

```php
$offen = DB::table('subscriptions')->whereNotNull('deleted_at')
    ->whereNotIn(DB::raw('CAST(SUBSTRING(system_user,2) AS UNSIGNED)'), …)
```

— nein. **Einfacher und ohne SQL-Dialekt:** Erst prüfen, dass jeder Grabstein
im Verzeichnis steht, dann löschen.

```php
$fehlend = [];

foreach (DB::table('subscriptions')->whereNotNull('deleted_at')
    ->get(['id', 'system_user']) as $row) {

    if ($row->system_user === null) {
        continue;
    }

    $number = (int) mb_substr($row->system_user, 1);

    if (! DB::table('system_users')->where('number', $number)->exists()) {
        $fehlend[] = $row->system_user;
    }
}

if ($fehlend !== []) {
    throw new RuntimeException(
        'Diese Namen stehen nicht im Verzeichnis: '.implode(', ', $fehlend)
    );
}

DB::table('subscriptions')->whereNotNull('deleted_at')->delete();
```

Danach die Spalte weg, in derselben Migration:

```php
Schema::table('subscriptions', function (Blueprint $table) {
    $table->dropSoftDeletes();
});
```

`down()` legt `deleted_at` wieder an und **stellt die Zeilen nicht wieder her**
— das gehört als Kommentar hinein, sonst hält es jemand für einen Rückweg.

### Schritt 4 — `Lifecycle`

`app/Support/Subscriptions/Lifecycle.php`:

```php
/**
 * Der nächste freie Systembenutzer — ohne Zuteilung.
 *
 * Für das Formular: Es zeigt, was der nächste wäre. Verbraucht wird der Name
 * erst mit {@see self::claim()}.
 */
public function nextSystemUser(): string
{
    return 'p'.max(self::FIRST_USER, ((int) SystemUser::query()->max('number')) + 1);
}

/**
 * Den nächsten Namen vergeben und verbrauchen.
 *
 * In einer Transaktion mit dem Anlegen des Abonnements. Der eindeutige Index
 * auf `number` ist die Sicherung: Zwei gleichzeitige Anlagen bekommen nicht
 * denselben Namen, sondern die zweite läuft in eine Kollision und holt sich
 * die nächste.
 */
public function claim(string $subscription): string
{
    for ($versuch = 0; $versuch < 5; $versuch++) {
        $number = max(self::FIRST_USER, ((int) SystemUser::query()->max('number')) + 1);

        try {
            SystemUser::query()->create([
                'number' => $number,
                'subscription' => $subscription,
                'claimed_at' => now(),
            ]);

            return 'p'.$number;
        } catch (UniqueConstraintViolationException) {
            continue;
        }
    }

    throw new RuntimeException('Es liess sich kein Systembenutzer vergeben.');
}
```

**Kein `withoutRestriction` mehr.** Das war nötig, weil `Subscription` die
Mandantenklammer trägt; `SystemUser` trägt keine. Der Kommentar, der erklärt,
warum es dort stand, wird durch einen ersetzt, der erklärt, warum es hier nicht
mehr steht — **er wird nicht gelöscht.** Er hält fest, was schiefging.

`withdraw()` (Zeile 244–257) wird zu:

```php
private function withdraw(Subscription $subscription): void
{
    Domain::query()
        ->where('subscription_id', $subscription->id)
        ->get()
        ->each(static fn (Domain $domain): ?bool => $domain->delete());

    $subscription->forceDelete();
}
```

Das einzelne Löschen der Domains **bleibt** — der Grund dafür (das Modell pflegt
`main_domain` an seinem `deleted`-Ereignis) gilt unverändert. Der lange
Kommentar darüber bleibt ebenfalls stehen; er ist die Geschichte von zwei
Fehlern.

`$subscription->forceDelete()` statt `delete()`, damit es auch dann hart löscht,
wenn das Modell den Trait wider Erwarten noch trägt.

### Schritt 5 — Das Modell

`app/Models/Subscription.php`:

- `use SoftDeletes;` (Zeile 73) und der Import entfallen
- `@property Carbon|null $deleted_at` aus dem Klassenblock entfernen
- Der Kommentarblock über dem Trait wird zu einer Notiz, die auf `SystemUser`
  zeigt

Neues Modell `app/Models/SystemUser.php` — schmal, mit `$fillable` für
`number`, `subscription`, `claimed_at`, Cast `claimed_at => datetime`, **ohne**
Mandantenklammer (der Name ist eine Eigenschaft des Servers, nicht eines
Kunden) und **ohne** Beziehung zu `Subscription`.

### Schritt 6 — Die Aufrufer

- `app/Http/Controllers/SubscriptionController.php:120` — `nextSystemUser()`
  bleibt (Anzeige im Formular).
- `app/Http/Controllers/SubscriptionController.php:182` — wird zu
  `$lifecycle->claim($name)`, **innerhalb** der Transaktion, die das Abonnement
  anlegt. Steht sie ausserhalb, verbraucht ein fehlgeschlagenes Anlegen eine
  Nummer.

### Schritt 7 — `PlanController` schrumpft

Mit dem Grabstein entfällt sein Anlass. Zurückzubauen ist, was am 7. August
2026 dafür entstand:

- `destroy()`: die Zählung `onlyTrashed()`, die zweite Abweisung, `$target`,
  `transferTarget()`, `carryOver()` und der Zusatz in der Erfolgsmeldung
- `edit()`: `withdrawn` und `targets`
- `create()`: `withdrawn`, `targets`
- `resources/js/Pages/Plans/Form.vue`: die Eigenschaften `withdrawn` und
  `targets`, `transferTo`, `target`, der Hinweis und die Auswahl neben dem Knopf
- `tests/Feature/PlanTest.php`: die vier Tests um die Grabsteine
- `tests/waechter-brechen.sh`: die vier zugehörigen Brüche

**Was bleibt:** `$tenancy->withoutRestriction()` um die Zählung. Die
Mandantenklammer liegt weiter auf `Subscription`, und ein Kommando ohne
gesetzten Mandanten zählte sonst null.

**Was in den Kommentar gehört:** dass hier einmal eine Übertragung stand und
warum sie wegfallen konnte. Ein Rückbau ohne diese Zeile sieht später aus wie
eine vergessene Funktion.

### Schritt 8 — `RestrictedDeleteTest` wird geschärft

**Hier steckt ein Fehler, den dieser Umbau erst sichtbar macht.** Der Wächter
(`tests/Feature/RestrictedDeleteTest.php`) prüft heute: Ist das Kindmodell
gefiltert — durch `SoftDeletes` **oder** eine Mandantenklammer —, muss
`destroy()` **beide** Filter abschalten. Nach diesem Umbau trägt `Subscription`
nur noch die Klammer, und der Wächter verlangte weiter ein `withTrashed()`, das
es nicht mehr geben kann. **Er würde beim Aufräumen zubeissen** — genau die
Falle, vor der CLAUDE.md warnt.

Die Prüfung wird deshalb aufgeteilt:

```php
if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
    // verlangt withTrashed() / onlyTrashed()
}

if ($this->hasGlobalScope($model)) {
    // verlangt withoutRestriction()
}
```

Diese Änderung gehört **in denselben Commit wie Schritt 5**, sonst ist die CI
zwischendurch rot.

### Schritt 9 — Toter Ballast (getrennt bewerten)

Nach dem Purge kann kein Abonnement mehr `status = cancelled` oder ein
`cancelled_at` tragen. Beides ist damit tote Spalte und toter Aufzählungswert.

**Empfehlung: in diesem Umbau stehen lassen, in einem eigenen Commit danach
entfernen.** Eine Aufzählung zu verkleinern, während eine Datenmigration läuft,
mischt zwei Risiken, die man einzeln beurteilen können will. Wer es angeht:
`SubscriptionStatus::Cancelled`, `subscriptions.cancelled_at`, die Labels in
`app/Enums/SubscriptionStatus.php:74` und `:92`.

---

## 5. Tests und Wächter

### 5.1 Neu: `tests/Feature/SystemUserLedgerTest.php`

| Test | Was er festhält |
|---|---|
| `test_a_removed_subscription_leaves_its_name_behind` | Abonnement anlegen, zurückbauen, hart weg — der Name steht weiter im Verzeichnis |
| `test_the_next_name_never_repeats_a_claimed_one` | nach dem Rückbau ist der nächste Name max+1 und **nicht** der freigewordene |
| `test_the_first_name_is_p1000` | leeres Verzeichnis → `p1000` (die Untergrenze aus `FIRST_USER`) |
| `test_claiming_twice_gives_two_names` | zweimal `claim()` → zwei verschiedene Nummern, beide im Verzeichnis |
| `test_a_failed_creation_does_not_burn_a_name` | Anlegen scheitert in der Transaktion → keine Zeile im Verzeichnis |

### 5.2 Neu: der statische Wächter

Der Kern der Regel ist: **die Vergabe fragt das Verzeichnis und nicht die
Abonnements.** Ein Test, der nur das Verhalten prüft, bleibt grün, wenn jemand
später „zur Sicherheit" wieder `Subscription` dazunimmt — und dann zählt eine
Quelle mit, die leer laufen kann.

```php
public function test_the_allocation_reads_only_the_ledger(): void
{
    $quelle = $this->methodSource(Lifecycle::class, 'nextSystemUser')
        .$this->methodSource(Lifecycle::class, 'claim');

    $this->assertStringNotContainsString('Subscription::', $quelle);
    $this->assertStringContainsString('SystemUser::', $quelle);
}
```

`methodSource()` gibt es schon als `destroySource()` in
`tests/Feature/RestrictedDeleteTest.php` — dort herauslösen oder abschauen, aber
**nicht** zweimal ausformulieren.

### 5.3 Brüche in `tests/waechter-brechen.sh`

| Bruch | Erwartet rot |
|---|---|
| `nextSystemUser()` liest wieder `Subscription::query()` | der statische Wächter |
| `claim()` schreibt keine Zeile ins Verzeichnis | `test_the_next_name_never_repeats_a_claimed_one` |
| `withdraw()` benutzt `delete()` statt `forceDelete()` | ein Test, der prüft, dass keine Zeile bleibt |
| `claim()` steht ausserhalb der Transaktion | `test_a_failed_creation_does_not_burn_a_name` |

**Jeder Eingriff wird mit `griff_datei` abgesichert.** Ein `sed`, dessen Muster
nicht passt, meldet sonst einen Wächter als „beisst nicht", der in Ordnung ist —
das ist im Skript schon einmal passiert und steht in seinem Kopfkommentar.

### 5.4 Angepasst

- `tests/Feature/PlanTest.php` — die vier Grabsteintests fallen weg
- `tests/Feature/RestrictedDeleteTest.php` — Schritt 8
- Jeder Test, der `Subscription::withTrashed()` oder `->delete()` auf einem
  Abonnement benutzt, muss durchgesehen werden: `grep -rn "withTrashed\|onlyTrashed" tests/`

---

## 6. Abnahme — auf dem Server, nicht geschätzt

Nach `srvpanel update` auf die Fassung mit diesem Umbau:

| # | Prüfung | Erwartet |
|---|---|---|
| 1 | `SELECT COUNT(*) FROM system_users;` | die Zahl aus Schritt 0 (`grabsteine` + `lebend`) |
| 2 | `SELECT MAX(number) FROM system_users;` | der Wert `groesste` aus Schritt 0 |
| 3 | `nextSystemUser()` über `tinker` | **derselbe Wert wie in Schritt 0** — die zentrale Invariante |
| 4 | `SELECT COUNT(*) FROM subscriptions;` | nur noch `lebend` |
| 5 | `SHOW COLUMNS FROM subscriptions LIKE 'deleted_at';` | leer |
| 6 | `SELECT COUNT(*) FROM operations WHERE subscription_id IS NULL AND subscription_name IS NOT NULL;` | `vorgaenge_an_grabsteinen` aus Schritt 0 |
| 7 | Abonnement anlegen → Systembenutzer | `nextSystemUser()` aus Schritt 0 |
| 8 | dieses Abonnement zurückbauen, dann ein neues anlegen | der neue Name ist **wieder** eins höher, nicht der freigewordene |
| 9 | einen Plan löschen, an dem vorher Grabsteine hingen | geht durch, **ohne** Rückfrage nach einem Ziel |
| 10 | Vorgangsliste als Admin | die Vorgänge des zurückgebauten Abonnements stehen noch da, mit Namen |
| 11 | Vorgangsliste als Kunde | die verwaisten Vorgänge sind **nicht** dabei |

Kriterium 3 ist das eigentliche: Wenn die Zahl vor und nach der Migration
dieselbe ist, hat der Umbau die Reservierung nicht angefasst.

Kriterium 8 ist das, was der ganze Mechanismus schützt. Es lässt sich zusätzlich
gegen das System prüfen: `getent passwd | grep '^p1'` auf dem Server — kein Name
aus dem Verzeichnis darf einer neuen UID gehören.

---

## 7. Risiken, ehrlich benannt

**Der Purge ist unumkehrbar.** `down()` kann die Zeilen nicht zurückholen. Die
Sicherung aus Schritt 0 ist der einzige Rückweg. Wer das ohne Sicherung fährt,
hat keinen.

**Die Rückfüllung von `operations.subscription_name` gibt es nur einmal.** Läuft
Schritt 3 vor Schritt 2, sind die Namen fort, und keine spätere Migration kann
sie rekonstruieren.

**SQLite und MariaDB.** Zwei Stellen im Plan sind dialektempfindlich: die
Rückfüllung (Schritt 2) und jede `CAST(SUBSTRING(...))`-Abfrage. Die Tests
laufen auf SQLite, der Server auf MariaDB — was hier auseinanderläuft, fällt
erst auf dem Server auf. Deshalb steht im Plan überall die PHP-Fassung.

**Der Container kann das nicht prüfen.** Ohne `vendor/` gibt es weder PHPUnit
noch PHPStan noch `artisan migrate`. Wer diesen Umbau baut, rechnet mit
mehreren CI-Runden und sollte das früh einplanen — die Datenmigrationen sind
genau die Sorte Code, die lokal nicht läuft.

**Gleichzeitigkeit.** Heute können zwei parallele Anlagen beide `p1121` sehen;
eine scheitert am eindeutigen Index mit einer unverständlichen Meldung. `claim()`
mit Wiederholung macht das besser, aber nicht perfekt: Bei sehr hoher Last
bleibt eine Restwahrscheinlichkeit, dass fünf Versuche nicht reichen. Für ein
Panel auf einem einzelnen Server ist das die richtige Grössenordnung.

---

## 8. Umfang

Zwei bis drei Migrationen, ein neues Modell, `Lifecycle`, zwei Aufrufer, der
Rückbau in `PlanController` samt Vue, ein neuer Wächter, ein geschärfter alter,
vier Brüche. Kein Riesenumbau, aber er fasst den Rückbauweg an — und der läuft
mit Systemrechten.

**Was er zurückgibt:** `subscriptions` enthält nur noch, was es gibt. Die
Reservierung steht als Tabelle da, die man lesen und gegen `/etc/passwd`
abgleichen kann. Und die Klasse von Fehlern, die am 7. August 2026 einen 500er
verursacht hat — eine Zählung, die weniger sieht als der Fremdschlüssel —
verschwindet an dieser Stelle vollständig, statt umschifft zu werden.

---

## 9. Umsetzung

**Stand: 7. August 2026, umgesetzt.** Zwei Commits auf
`claude/systembenutzer-verzeichnis-migration-jkfff5` — der Umbau und, getrennt
davon, Schritt 9. Die Schrittreihenfolge des Plans ist eingehalten; die drei
Migrationen tragen `2026_08_07_1000{00,10,20}` und laufen damit zwingend in der
Reihenfolge Verzeichnis → Abschrift → Purge.

### 9.1 Was gebaut wurde

| Schritt | Ergebnis |
|---|---|
| 1 | `..._create_system_users_table.php` — Tabelle und Füllung aus dem Bestand |
| 2 | `..._operations_survive_a_deleted_subscription.php` — `subscription_name`, Rückfüllung in PHP, Fremdschlüssel gelockert |
| 3 | `..._withdrawn_subscriptions_are_gone.php` — Purge und `dropSoftDeletes()` |
| 4 | `Lifecycle::claim()` neu, `nextSystemUser()` liest das Verzeichnis, `withdraw()` löscht hart |
| 5 | `app/Models/SystemUser.php` neu, `Subscription` ohne `SoftDeletes` |
| 6 | `SubscriptionController::store()` ruft `claim()` **in** der Transaktion |
| 7 | `PlanController` und `Plans/Form.vue` zurückgebaut |
| 8 | `RestrictedDeleteTest` fragt die beiden Filter getrennt (im selben Commit wie 5) |
| 9 | `SubscriptionStatus::Cancelled` und `cancelled_at` — **eigener Commit danach** |

Neue Wächter: `SystemUserLedgerTest` mit zehn Prüfungen, darunter der statische
`test_the_allocation_reads_only_the_ledger` und
`test_every_written_name_was_claimed`. Sieben neue Brüche in
`tests/waechter-brechen.sh`; die Reflexion auf einen Methodenrumpf ist als
`tests/Support/ReadsMethodSource.php` aus `RestrictedDeleteTest` herausgelöst.

### 9.2 Drei Stellen, an denen der Plan nicht trug

Das ist der wertvollste Teil dieses Abschnitts. Alle drei hätten erst auf dem
Server gefehlt.

**1. SQLite kann einen Fremdschlüssel überhaupt nicht ändern.** §4 Schritt 2
nennt zwei Stolpersteine (`UPDATE … JOIN`, den Indexnamen) und diesen dritten
nicht. Es gibt dort kein `ALTER TABLE … DROP FOREIGN KEY`; die Umstellung auf
`nullOnDelete` läuft nur auf MariaDB und ist im Test wirkungslos. Damit das
Verhalten auf beiden Treibern dasselbe ist, löst `Lifecycle::withdraw()` seine
Vorgänge **selbst** ab, und die Migration tut vor dem Purge dasselbe. Die
Umstellung des Fremdschlüssels bleibt als Sicherung darunter — für ein `DELETE`
von Hand, das am Panel vorbeigeht.

**2. Niemand schreibt `operations.subscription_name` für neue Vorgänge.** §3.3
und Schritt 2 beschreiben nur die Rückfüllung. Jeder Vorgang danach wäre nach
dem nächsten Rückbau namenlos gewesen — Kriterium 10 hätte für den Bestand
gehalten und für alles Neue nicht, und aufgefallen wäre es erst, wenn nichts
mehr zu heilen ist. Geschrieben wird der Name jetzt in `Operation::booted()`
beim Anlegen; die sechs Stellen, an denen Vorgänge entstehen, wissen davon
nichts.

**3. `srvpanel acceptance` und `acceptance-web` vergeben Namen.** Schritt 6
nennt zwei Zeilen im `SubscriptionController` und keines der beiden
Abnahmekommandos. Beide riefen `nextSystemUser()`, das seit diesem Umbau nichts
mehr verbraucht: `Acceptance::create()` legt in einer Schleife an, alle
Abonnements hätten `p1000` bekommen, und das zweite wäre am eindeutigen Index
gescheitert. Der Wächter dazu prüft die Regel und nicht den Fall — jeder
Systembenutzer, der in eine Zeile geschrieben wird, kommt aus einem `claim()`.

Dazu drei kleinere Funde: `Rule::unique('subscriptions', 'name')
->withoutTrashed()` in `SubscriptionController` hängt eine Bedingung auf
`deleted_at` an und wäre ab der Migration ein SQL-Fehler auf jedem Anlegen; und
zwei Tests behaupteten, nach dem Rückbau stehe die Zeile noch da —
`WebLifecycleTest::test_the_teardown_of_a_subscription_frees_its_domain_names`
und `DomainTest::test_withdrawing_a_subscription_clears_the_copy`.

**Der zweite davon ist der lehrreiche: Er war mit keinem Suchmuster zu
finden.** §5.4 nennt `withTrashed` und `onlyTrashed`; dieser Test benutzte
weder das eine noch das andere, sondern ein
`DB::table('subscriptions')->where('id', …)->first()` und ein
`assertNotNull` — die Annahme „die Zeile bleibt liegen" stand nur im
Meldungstext der Behauptung. Kein `grep` über Vokabeln der weichen Löschung
holt so etwas heraus. Gefunden hat ihn die CI, und zwar als einzigen
Fehlschlag von 1292 Tests.

### 9.3 Ein Bruch aus §5.3, der nicht beisst

> `withdraw()` benutzt `delete()` statt `forceDelete()` → ein Test, der prüft,
> dass keine Zeile bleibt

Ohne `SoftDeletes` sind `delete()` und `forceDelete()` dasselbe. Der Eingriff
ändert die Datei, der Test bleibt grün, und `waechter-brechen.sh` meldete einen
gesunden Wächter als „beisst nicht". `forceDelete()` steht trotzdem im Code —
aus dem Grund, den §4 Schritt 4 nennt: falls das Modell den Trait wider Erwarten
wieder trägt. Nur ist das keine Regel, die sich einzeln brechen lässt.

An seiner Stelle stehen zwei Brüche, die es gibt:

- **die Vorgänge bleiben am Abonnement hängen** → `test_the_operations_survive_the_removal`
- **`Subscription` bekommt seinen Trait zurück** → `RestrictedDeleteTest`

Der zweite ist der wichtigere: Nach diesem Umbau löst kein Modell mehr den
Zweig „die Grabsteine" in `RestrictedDeleteTest` aus. Ein Zweig, den nichts
erreicht, ist kein Wächter — deshalb der Bruch, der ihn erreicht, und deshalb
die Untergrenze `$checked > 0` im Test selbst.

### 9.4 Zwei Entscheidungen, die vom Plan abweichen

**Die Prüfung in Migration 1 läuft, bevor das Schema angefasst wird.** §4
Schritt 1 stellt sie hinter die Füllung. DDL ist auf MariaDB nicht
transaktional: Ein Abbruch nach dem `CREATE` liesse eine halb gefüllte Tabelle
zurück, und der zweite Lauf scheiterte an ihr statt am eigentlichen Grund.
Gelesen und geprüft wird deshalb zuerst; erst danach entsteht die Tabelle.

**Ein Name, der nicht dem Muster `p<Zahl>` folgt, hält die Migration an.** §4
Schritt 1 lässt sie `continue`en („nicht raten") — richtig, nur würde die
Reservierung damit still verloren gehen, und für ein *lebendes* Abonnement fängt
das auch Schritt 3 nicht ab. Geraten wird weiterhin nichts; die Migration nennt
die Namen und bricht ab. Auf dem Zielserver tritt der Fall nicht ein, alle Namen
stammen aus `nextSystemUser()`.

Ausserdem: Migration 3 hält an, wenn an einem Grabstein noch ein Zertifikat
hängt. §4 Schritt 0 lässt den Betreiber das im Vorflug prüfen; die Prüfung im
Code sorgt dafür, dass er es auch dann tut, wenn er den Vorflug übersprungen hat.

### 9.5 Was hier **nicht** geprüft werden konnte

Ehrlich benannt, weil §7 es verlangt:

- **Im Container lief weder `php artisan migrate` noch `php artisan test`.**
  `vendor/` fehlt, und `composer install` scheitert nicht an einer Laune,
  sondern an einer Regel: Der Proxy dieses Containers gibt GitHub nur für die
  Repositorys frei, die der Sitzung zugeordnet sind. Jedes Paket eines Dritten
  antwortet mit `403 GitHub access to this repository is not enabled for this
  session`. Es gibt keinen Weg daran vorbei, der nicht hiesse, die
  Abhängigkeiten aus einer fremden Quelle zu ziehen — und dieses Projekt prüft
  seine Lieferkette in der CI.

- **Gelaufen ist die Testsuite trotzdem, und zwar dort, wo sie hingehört.** Die
  CI kennt `workflow_dispatch`; ein Lauf auf dem Zweig bringt dasselbe Ergebnis
  wie ein PR, ohne einen zu öffnen. Ergebnis des zweiten Laufs: **1292 Tests
  grün**, Pint grün, PHPStan Stufe 6 grün, Typen und Bündel grün, `composer
  audit`, `npm audit` und die Lizenzprüfung grün. Der erste Lauf hatte genau
  einen Fehlschlag, und das war der `DomainTest` aus §9.2 — der Fund, den kein
  Suchmuster hergab.

- **Die Datenmigrationen sind damit noch immer nicht auf MariaDB gelaufen.**
  Grün ist SQLite. Was auf dem Server anders ist — `dropForeign`, die
  Fremdschlüsselumstellung, `dropSoftDeletes` auf einer Tabelle mit Daten —,
  steht erst nach §10 fest. Die zentrale Invariante aus Kriterium 3 ist
  zusätzlich gegen eine SQLite-Datenbank mit der Form der Serverdaten
  nachgerechnet worden (121 Namen, höchste 1120, die höchsten davon
  Grabsteine): `p1121` vor und nach der Migration, und ohne das Verzeichnis
  wäre es `p1100` gewesen. Das ist eine Probe der Rechnung, kein Ersatz für den
  Lauf auf dem Server.
- **`waechter-brechen.sh` ist nur zur Hälfte gelaufen.** Jeder der acht neuen
  Eingriffe ist gegen eine Kopie der Zieldatei gefahren worden und ändert sie
  auch wirklich — die `griff_datei`-Hälfte ist damit belegt, und für
  `test_every_written_name_was_claimed` ist zusätzlich die Testlogik nachgebaut
  und gezeigt worden, dass sie mit dem Bruch genau einen Befund meldet. Ob die
  übrigen sieben Wächter danach rot werden, braucht ein lokales PHPUnit und
  steht aus; das Skript ändert Dateien und lässt sich nicht über die CI fahren.
  **Wer als Nächstes an diesem Repo mit `vendor/` sitzt, holt das nach.**
- **Was lief:** `php -l` über alle geänderten Dateien, `pint --test` (grün),
  PHPStan Stufe 6 über `agent/src` und `tests/Support` sowie über die neuen
  Dateien einzeln, `npm run types` (grün) und `npm run build`.
- **Screenshots** des zurückgebauten Formulars: über den Weg aus CLAUDE.md —
  gebautes Stylesheet aus `public/build`, Markup der Knopfreihe in einer eigenen
  HTML-Datei, gerendert im vorinstallierten Chromium. Beide Themes, 1440px und
  390px, `scrollWidth - clientWidth` je **0**. Das ersetzt den Blick auf die
  echte Seite nicht: Die entfallene Auswahl neben dem Löschknopf war genau die
  Stelle, an der die Knopfreihe im letzten Abnahmelauf auseinanderging, und mit
  ihr ist der Anlass fort — aber gesehen ist das an einem Nachbau und nicht an
  der Seite.

### 9.6 Beobachtung ohne Handlung

Die `rang()`-Helfer in `Subscriptions/Index.vue`, `Subscriptions/Show.vue` und
`CustomerOverview.vue` prüfen weiter auf `'cancelled'`. Sie tragen auch
`'removing'` und `'failed'`, die es als Zustand eines Abonnements nie gab — vier
Fassungen derselben Zuordnung auf vier Seiten, was der Kommentar in
`Customers/Index.vue` selbst schon anmerkt. Das ist eine eigene Aufräumfrage
(ein Wächter „jede Zeichenkette in einem `rang()` zeigt auf einen Fall der
Aufzählung" wäre der passende) und gehört nicht in diesen Umbau.

---

## 10. Die Abnahme auf dem Server — als Befehlsfolge

Diese Befehle laufen auf dem Zielserver, nicht im Container. Sie sind so
geschnitten, dass sich ihre Ausgabe in den Abnahmebericht kopieren lässt.

### 10.1 Vorflug — **vor** `srvpanel update`

Ohne diese Zahlen gibt es hinterher nichts zu vergleichen, und ohne die
Sicherung gibt es keinen Rückweg.

```bash
# 1. Die Sicherung. Der Purge ist nicht rückgängig zu machen.
mariadb-dump srvpanel > /root/vor-35.sql
ls -lh /root/vor-35.sql

# 2. Die Zahlen.
mariadb srvpanel -e "
SELECT COUNT(*) AS grabsteine,
       MIN(CAST(SUBSTRING(system_user,2) AS UNSIGNED)) AS kleinste,
       MAX(CAST(SUBSTRING(system_user,2) AS UNSIGNED)) AS groesste
FROM subscriptions WHERE deleted_at IS NOT NULL;

SELECT COUNT(*) AS lebend FROM subscriptions WHERE deleted_at IS NULL;

SELECT COUNT(*) AS namen_gesamt FROM subscriptions
WHERE system_user IS NOT NULL AND system_user LIKE 'p%';

SELECT COUNT(*) AS vorgaenge_an_grabsteinen
FROM operations o JOIN subscriptions s ON s.id = o.subscription_id
WHERE s.deleted_at IS NOT NULL;

SELECT COUNT(*) AS zertifikate_an_grabsteinen
FROM certificates c JOIN subscriptions s ON s.id = c.subscription_id
WHERE s.deleted_at IS NOT NULL;
"

# 3. Die zentrale Invariante.
HOME=/tmp srvpanel tinker --execute="
  echo app(\App\Support\Subscriptions\Lifecycle::class)->nextSystemUser();
"
```

**`namen_gesamt` ist neu gegenüber §4 Schritt 0** und die Zahl, die Kriterium 1
wirklich meint: `grabsteine + lebend` stimmt nur, solange jedes Abonnement einen
Namen hat. Ein Abonnement, das im Zustand `provisioning` steckengeblieben ist,
hat keinen — und dann zählt das Verzeichnis weniger, ohne dass etwas fehlt.

**Wenn `zertifikate_an_grabsteinen > 0` ist: anhalten.** Die Migration bricht
dann von selbst ab und nennt die Zahl; entschieden wird das nach §4 Schritt 0
und nicht nebenbei.

Am 7. August 2026 waren die Zahlen: `grabsteine 121`, `groesste 1120`,
`nextSystemUser() = p1121`.

### 10.2 Das Update

```bash
srvpanel update
journalctl -u srvpanel-update -n 50 --no-pager
```

Bricht eine der Migrationen ab, steht ihr Grund im Klartext im Protokoll
(„Diese Namen stehen nicht im Verzeichnis: …", „Verzeichnis unvollständig: …",
„An zurückgebauten Abonnements hängen noch N Zertifikate."). In dem Fall: nichts
weiter tun, den Text mitschicken. Die erste Migration prüft, bevor sie das
Schema anfasst; die dritte, bevor sie löscht.

### 10.3 Die elf Kriterien

```bash
mariadb srvpanel -e "
-- 1  erwartet: namen_gesamt aus 10.1
SELECT COUNT(*) AS k1_verzeichnis FROM system_users;

-- 2  erwartet: groesste aus 10.1  (1120)
SELECT MAX(number) AS k2_hoechste FROM system_users;

-- 4  erwartet: lebend aus 10.1
SELECT COUNT(*) AS k4_abonnements FROM subscriptions;

-- 5  erwartet: leer
SHOW COLUMNS FROM subscriptions LIKE 'deleted_at';

-- 6  erwartet: vorgaenge_an_grabsteinen aus 10.1
SELECT COUNT(*) AS k6_verwaiste FROM operations
WHERE subscription_id IS NULL AND subscription_name IS NOT NULL;
"

# 3  DIE ZENTRALE INVARIANTE — erwartet: derselbe Wert wie in 10.1 (p1121)
HOME=/tmp srvpanel tinker --execute="
  echo app(\App\Support\Subscriptions\Lifecycle::class)->nextSystemUser();
"
```

**Kriterium 3 ist das eigentliche.** Ist die Zahl vor und nach der Migration
dieselbe, hat der Umbau die Reservierung nicht angefasst. Und es ist ein
Kriterium, das nach einem *Wert* fragt und nicht nach einer Anzahl — die Lehre
aus dem TLS-Abnahmelauf.

Kriterien 7 bis 11 im Panel, mit der Datenbank daneben:

```bash
# 7  Abonnement anlegen (Panel: /subscriptions/create) und danach:
mariadb srvpanel -e "
SELECT name, system_user FROM subscriptions ORDER BY id DESC LIMIT 1;
SELECT number, subscription, claimed_at FROM system_users ORDER BY number DESC LIMIT 3;
"
#    erwartet: system_user = nextSystemUser() aus 10.1, und die Zeile steht im
#    Verzeichnis. Nicht nur die Anzahl vergleichen — den Namen.

# 8  dasselbe Abonnement zurückbauen, warten bis der Vorgang durch ist, dann
#    ein neues anlegen:
mariadb srvpanel -e "
SELECT number, subscription FROM system_users ORDER BY number DESC LIMIT 4;
SELECT COUNT(*) AS abonnements FROM subscriptions;
"
#    erwartet: der neue Name ist WIEDER eins höher — nicht der freigewordene.
#    Und gegen das System geprüft:
getent passwd | grep '^p1' | tail -5
#    Kein Name aus dem Verzeichnis darf einer neuen UID gehören.

# 9  einen Plan löschen, an dem vorher Grabsteine hingen (Panel: /plans)
#    erwartet: geht durch, OHNE Rückfrage nach einem Ziel. Es gibt keine
#    Auswahl neben dem Löschknopf mehr.

# 10 Vorgangsliste als Admin (Panel: /operations)
#    erwartet: die Vorgänge des zurückgebauten Abonnements stehen noch da.
mariadb srvpanel -e "
SELECT id, type, subscription_id, subscription_name
FROM operations WHERE subscription_id IS NULL AND subscription_name IS NOT NULL
ORDER BY id DESC LIMIT 5;
"
#    Der Name muss dastehen. Ein leeres subscription_name bei einem Vorgang,
#    der NACH dem Update entstanden ist, ist ein Fehler.

# 11 Vorgangsliste als Kunde („Anmelden als", Panel)
#    erwartet: die verwaisten Vorgänge sind NICHT dabei.
```

### 10.4 Und der Abnahmelauf selbst

Er vergibt Namen in einer Schleife und ist damit die schärfste Probe auf
`claim()`:

```bash
srvpanel acceptance --count=3
mariadb srvpanel -e "SELECT number, subscription FROM system_users ORDER BY number DESC LIMIT 5;"
```

Erwartet: drei aufeinanderfolgende Nummern, drei verschiedene Namen. Vor der
Behebung des dritten Fundes aus §9.2 hätten alle drei `p1000` bekommen.

### 10.5 Wenn etwas schiefgeht

```bash
systemctl stop srvpanel-worker srvpanel
mariadb srvpanel < /root/vor-35.sql
# und dann die vorige Fassung zurückrollen — der Symlink in
# /opt/srvpanel/releases zeigt auf die Fassung, das postinstall-Skript kennt
# den Weg.
```

Die `down()`-Methoden stellen die Spalten wieder her und **keine einzige Zeile**.
Der Dump aus 10.1 ist der Rückweg.
