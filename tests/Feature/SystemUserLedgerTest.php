<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SystemUser;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\Support\ReadsMethodSource;
use Tests\TestCase;

/**
 * Ein Systembenutzer wird genau einmal vergeben — und das Verzeichnis sagt es.
 *
 * **Warum das eine eigene Tabelle ist.** `userdel` gibt die UID frei, das
 * nächste `useradd` vergibt sie wieder. Käme `p1000` ein zweites Mal, erbte ein
 * neuer Kunde alles, was auf dem Dateisystem noch der alten UID gehört —
 * Dateien in einem Verzeichnis, das der Rückbau nicht erwischt hat, Einträge in
 * `at`- oder `cron`-Warteschlangen, offene Sockets.
 *
 * Bis August 2026 hing das an einem `deleted_at`: Ein zurückgebautes Abonnement
 * blieb als Zeile liegen, und die Vergabe sah sie mit `withTrashed()`. Das war
 * richtig gedacht und zu grob gebaut — 121 Zeilen auf dem Zielserver für eine
 * einzige `MAX()`-Abfrage, und dabei hielten sie einen Fremdschlüssel auf
 * `plans` fest. docs/35 hat daraus eine Tabelle gemacht.
 */
final class SystemUserLedgerTest extends TestCase
{
    use ReadsMethodSource, RefreshDatabase;

    private function lifecycle(): Lifecycle
    {
        return app(Lifecycle::class);
    }

    /** Ein Abonnement, dessen Name auch wirklich aus dem Verzeichnis kommt. */
    private function subscribe(string $name = 'kunde.invalid'): Subscription
    {
        return Subscription::factory()->create([
            'name' => $name,
            'system_user' => $this->lifecycle()->claim($name),
        ]);
    }

    /** Den Rückbau durchlaufen lassen, so wie der Arbeiter es täte. */
    private function remove(Subscription $subscription): Operation
    {
        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'type' => 'subscription.remove',
            'task' => 'subscription.remove',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]);

        $this->lifecycle()->afterSuccess($operation);

        return $operation;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function unrestricted(callable $work): mixed
    {
        return app(Tenancy::class)->withoutRestriction($work);
    }

    public function test_the_first_name_is_p1000(): void
    {
        // Die Untergrenze aus `Lifecycle::FIRST_USER`. Vier Stellen, wie der
        // Agent sie verlangt — und nicht `p1`, was ein leeres `MAX()` sonst
        // ergäbe.
        $this->assertSame('p1000', $this->lifecycle()->nextSystemUser());
    }

    public function test_claiming_twice_gives_two_names(): void
    {
        $lifecycle = $this->lifecycle();

        $first = $lifecycle->claim('eins.invalid');
        $second = $lifecycle->claim('zwei.invalid');

        $this->assertSame('p1000', $first);
        $this->assertSame('p1001', $second);

        $this->assertSame([1000, 1001], SystemUser::query()->orderBy('number')->pluck('number')->all());
    }

    /**
     * Der Rückbau nimmt die Zeile mit — den Namen nicht.
     *
     * Das ist die ganze Absprache dieses Umbaus in einem Test: Das Abonnement
     * ist wirklich fort (keine weiche Löschung mehr, kein Grabstein), und sein
     * Name steht trotzdem noch da.
     */
    public function test_a_removed_subscription_leaves_its_name_behind(): void
    {
        $subscription = $this->subscribe();

        $this->remove($subscription);

        $this->assertNull(
            $this->unrestricted(fn (): ?Subscription => Subscription::query()->find($subscription->id)),
            'Das Abonnement soll hart gelöscht sein — auch für eine Abfrage ohne Mandantenklammer.',
        );

        $this->assertSame(0, $this->unrestricted(fn (): int => Subscription::query()->count()));

        $entry = SystemUser::query()->where('number', 1000)->first();

        $this->assertNotNull($entry, 'Der Name muss im Verzeichnis stehen bleiben, sonst kommt die UID zurück.');
        $this->assertSame('kunde.invalid', $entry->subscription, 'Die Abschrift sagt, wer den Namen hatte.');
    }

    /**
     * **Der Kern.** Der freigewordene Name kommt nicht zurück.
     *
     * Ohne das bekäme das nächste Abonnement `p1000` und damit alles, was auf
     * dem Dateisystem noch dieser UID gehört.
     */
    public function test_the_next_name_never_repeats_a_claimed_one(): void
    {
        $subscription = $this->subscribe();

        $this->assertSame('p1001', $this->lifecycle()->nextSystemUser());

        $this->remove($subscription);

        $this->assertSame('p1001', $this->lifecycle()->nextSystemUser());
        $this->assertSame('p1001', $this->lifecycle()->claim('naechster.invalid'));
    }

    /**
     * Ein fehlgeschlagenes Anlegen verbraucht keine Nummer.
     *
     * **Deshalb steht `claim()` innerhalb der Transaktion.** Eine Zeile im
     * Verzeichnis verschwindet nie wieder; stünde die Vergabe davor, frässe
     * jeder Fehlversuch eine Nummer, und die Lücke im Zähler wäre später nicht
     * mehr zu erklären.
     *
     * **Über den Controller und nicht über `DB::transaction()` von Hand.** Die
     * Frage ist ja gerade, ob `claim()` *dort* innerhalb der Transaktion steht;
     * ein Test, der sich die Transaktion selbst baut, beantwortet sie nicht und
     * bliebe grün, wenn der Aufruf im Controller nach oben rutscht.
     *
     * Gebrochen wird das Anlegen über ein Modellereignis und nicht über einen
     * ungültigen Wert: Die Prüfregeln des Controllers greifen vorher, und ein
     * Test, der schon an der Prüfung scheitert, käme nie bis zur Transaktion.
     */
    public function test_a_failed_creation_does_not_burn_a_name(): void
    {
        Subscription::creating(static function (): void {
            throw new RuntimeException('Das Anlegen scheitert — mit Absicht.');
        });

        $customer = Customer::factory()->create();
        $plan = Plan::factory()->default()->create(['name' => 'Standard']);

        $this->actingAs(Account::factory()->admin()->create())
            ->post('/subscriptions', [
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'name' => 'kunde-example.de',
            ])
            ->assertStatus(500);

        $this->assertSame(0, $this->unrestricted(fn (): int => Subscription::query()->count()));
        $this->assertSame(0, SystemUser::query()->count(), 'Eine zurückgerollte Anlage darf keine Nummer verbrauchen.');
        $this->assertSame('p1000', $this->lifecycle()->nextSystemUser());
    }

    /**
     * Die Vorgänge überleben den Rückbau — mit dem Namen, von dem sie handelten.
     *
     * **Und dieser Test ist auf SQLite der einzige Wächter dafür.** Auf MariaDB
     * steht `operations.subscription_id` seit docs/35 auf `nullOnDelete`; auf
     * SQLite lässt sich ein Fremdschlüssel überhaupt nicht ändern und bleibt
     * `cascadeOnDelete`. Löste `Lifecycle::withdraw()` die Vorgänge nicht selbst
     * ab, nähme das harte Löschen hier das ganze Protokoll mit — und auf dem
     * Server nicht. Genau die Sorte Unterschied, die docs/35 §7 benennt.
     */
    public function test_the_operations_survive_the_removal(): void
    {
        $subscription = $this->subscribe();

        $earlier = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'type' => 'subscription.provision',
            'task' => 'subscription.provision',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]);

        $this->remove($subscription);

        $survivor = $this->unrestricted(fn (): ?Operation => Operation::query()->find($earlier->id));

        $this->assertNotNull($survivor, 'Der Vorgang darf mit dem Abonnement nicht verschwinden.');
        $this->assertNull($survivor->subscription_id, 'Er hängt an keinem Abonnement mehr — es gibt keines.');
        $this->assertSame('kunde.invalid', $survivor->subscription_name, 'Ohne die Abschrift sagt er nicht mehr, wovon er handelte.');
    }

    /**
     * Ein verwaister Vorgang ist nur noch für den Admin da.
     *
     * Die Mandantenklammer fragt `subscription_id in (…)`, und `NULL` ist in
     * keiner Liste. Das ist richtig — der Kunde hat das Abonnement nicht mehr —,
     * aber es ist eine Verhaltensänderung und gehört festgehalten.
     */
    public function test_an_orphaned_operation_leaves_the_tenancy(): void
    {
        $subscription = $this->subscribe();

        $earlier = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'type' => 'subscription.provision',
            'task' => 'subscription.provision',
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]);

        $this->remove($subscription);

        app(Tenancy::class)->restrictTo([(int) $subscription->id]);

        $this->assertNull(Operation::query()->find($earlier->id));
    }

    /**
     * Der Name des Abonnements wird beim Anlegen des Vorgangs abgeschrieben.
     *
     * **Am Modell und nicht an den sechs Aufrufern.** Ohne diese Abschrift
     * bliebe die Rückfüllung der Migration die einzige Quelle, und jeder
     * Vorgang danach wäre nach dem Rückbau namenlos — ein Fehler, der erst beim
     * nächsten zurückgebauten Abonnement auffiele und dann nicht mehr zu heilen
     * wäre.
     */
    public function test_an_operation_copies_the_subscription_name(): void
    {
        $subscription = $this->subscribe('abschrift.invalid');

        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'type' => 'subscription.provision',
            'task' => 'subscription.provision',
            'status' => OperationStatus::Queued,
            'progress' => 0,
        ]);

        $this->assertSame('abschrift.invalid', $operation->refresh()->subscription_name);
    }

    /**
     * Ein Vorgang ohne Abonnement bekommt auch keinen Namen.
     *
     * Vorgänge des Betreibers — Paketinstallation, Dienstneustart — tragen
     * kein Abonnement. Ein Name daran wäre erfunden.
     */
    public function test_an_operation_without_a_subscription_has_no_name(): void
    {
        $operation = Operation::query()->create([
            'subscription_id' => null,
            'type' => 'service.status',
            'task' => 'agent.status',
            'status' => OperationStatus::Queued,
            'progress' => 0,
        ]);

        $this->assertNull($operation->refresh()->subscription_name);
    }

    /**
     * **Wer einen Namen in eine Zeile schreibt, hat ihn vorher verbraucht.**
     *
     * Dieser Wächter ist beim Bauen entstanden, weil genau das schiefging.
     * `nextSystemUser()` sagt nur, was der nächste *wäre*; verbraucht wird er
     * erst mit `claim()`. Bis August 2026 war der Unterschied folgenlos — die
     * Reservierung entstand aus der geschriebenen Zeile selbst. Seit docs/35
     * ist sie eine eigene Tabelle, und ein `'system_user' =>
     * $lifecycle->nextSystemUser()` gibt zweimal hintereinander denselben
     * Namen zurück.
     *
     * Aufgefallen ist es in `srvpanel acceptance`: Der legt in einer Schleife
     * mehrere Abonnements an, alle hätten `p1000` bekommen, und das zweite
     * wäre am eindeutigen Index gescheitert — auf dem Zielserver, im
     * Abnahmelauf, nicht hier.
     */
    public function test_every_written_name_was_claimed(): void
    {
        $offenders = [];
        $found = 0;

        foreach ($this->sources() as $path) {
            $source = (string) file_get_contents($path);

            preg_match_all("/'system_user' => ([^,\n]+)/", $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $found++;

                if (! str_contains($match[1], 'claim(')) {
                    $offenders[] = '  '.basename($path).': '.trim($match[1]);
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $found,
            'Es schreibt niemand mehr einen Systembenutzer — dann bewacht dieser Test nichts.',
        );

        $this->assertSame([], $offenders, implode("\n", [
            'Ein Systembenutzer, der in eine Zeile geschrieben wird, muss vorher',
            'im Verzeichnis stehen. `nextSystemUser()` schreibt dort nichts hin und',
            'gibt zweimal hintereinander denselben Namen zurück:',
            ...$offenders,
        ]));
    }

    /**
     * Alle PHP-Dateien unter `app/`.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /**
     * **Der statische Wächter: die Vergabe fragt das Verzeichnis.**
     *
     * Ein Test, der nur das Verhalten prüft, bliebe grün, wenn jemand später
     * „zur Sicherheit" wieder die Abonnements dazunimmt — und dann zählt eine
     * Quelle mit, die leer laufen kann. Genau das war der Grund, aus dem in
     * `nextSystemUser()` einmal ein `withoutRestriction` stehen musste: Ohne es
     * sah die Vergabe kein einziges Abonnement und gab `p1000` zurück, den es
     * längst gab.
     *
     * Gelesen wird der Rumpf der beiden Methoden und nicht die Datei: Ein
     * `Subscription::` weiter unten in `Lifecycle` — im Rückbau, im Payload —
     * ist völlig in Ordnung und beantwortet die Frage nicht.
     */
    public function test_the_allocation_reads_only_the_ledger(): void
    {
        $source = (string) $this->methodSource(Lifecycle::class, 'nextSystemUser')
            .(string) $this->methodSource(Lifecycle::class, 'claim');

        $this->assertNotSame('', $source, 'Der Rumpf der Vergabe ist nicht lesbar — dann bewacht dieser Test nichts.');

        $this->assertStringNotContainsString(
            'Subscription::',
            $source,
            'Die Vergabe darf die Abonnements nicht mehr fragen: Ihre Grabsteine gibt es nicht mehr, und die Mandantenklammer liesse die Quelle leer laufen.',
        );

        $this->assertStringContainsString(
            'SystemUser::',
            $source,
            'Die Vergabe muss das Verzeichnis fragen — sonst zählt sie gar nichts, was einen Namen verbraucht.',
        );
    }
}
