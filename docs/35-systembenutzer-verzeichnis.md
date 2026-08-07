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
