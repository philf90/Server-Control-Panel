<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BackupStatus;
use App\Enums\OperationSubject;
use App\Models\Backup;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Backups\Backups;
use App\Support\Backups\Retention;
use App\Support\Plans\Quota;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die Aufbewahrung — Schritt 9 aus `docs/117 §6`.
 *
 * ## Was hier gehalten wird und was nicht
 *
 * Gemessen wird die **Auswahl**: welche Sicherungen abgeräumt werden und welche
 * stehenbleiben. Dass die Datei danach wirklich fort ist, entscheidet
 * `backup.remove` im Agenten — die Zeile bleibt bis dahin stehen, und das ist
 * die zweite Grenze.
 *
 * > **Ein Beleg für den Weg ist keiner für das Ziel.**
 */
final class BackupRetentionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ein Abonnement mit einer Aufbewahrungszahl — **und einem Systembenutzer**.
     *
     * `SubscriptionFactory` lässt ihn `null`, und ohne ihn hat das Abonnement
     * kein Verzeichnis: {@see Backups::beforeRemoval()} weist es dann zu Recht
     * ab. Der erste Wurf dieses Wächters hat genau das gemessen und für einen
     * Fehler am Prüfling gehalten.
     *
     * > **Ein Prüfkörper, der eine Vorbedingung nicht herstellt, misst die
     * > Vorbedingung.**
     */
    private function subscription(?int $keeps): Subscription
    {
        $plan = Plan::factory()->create([
            'quotas' => [Quota::Backups->value => $keeps],
        ]);

        $subscription = Subscription::factory()->create([
            'plan_id' => $plan->id,
            'system_user' => 'p'.random_int(2000, 9999),
        ]);

        $this->assertNotNull($subscription->system_user, 'Der Prüfkörper hat kein Verzeichnis.');

        return $subscription;
    }

    /** `$anzahl` fertige Sicherungen, älteste zuerst. */
    private function backups(Subscription $subscription, int $anzahl, BackupStatus $status = BackupStatus::Ready): void
    {
        for ($i = 0; $i < $anzahl; $i++) {
            $this->backup($subscription, sprintf('%s-stand-%d', $subscription->name, $i), $status, $anzahl - $i);
        }
    }

    /**
     * Eine Sicherung mit einem gewählten Alter.
     *
     * **`created_at` geht nicht durch `create()`**: Es steht nicht in
     * `Backup::$fillable`, und Eloquent lässt es wortlos fallen — jede Zeile
     * trüge `now()`, und ein Test über das Alter prüfte dann nichts.
     *
     * > **Ein Prüfkörper, der einen Wert setzt, den das Modell nicht annimmt,
     * > misst den Vorgabewert.**
     */
    private function backup(Subscription $subscription, string $name, BackupStatus $status, int $tageAlt = 0, int $stundenAlt = 0): Backup
    {
        $backup = Backup::query()->create([
            'subscription_id' => $subscription->id,
            'subscription_name' => (string) $subscription->name,
            'storage_name' => $name,
            'status' => $status,
        ]);

        $backup->created_at = Carbon::now()->subDays($tageAlt)->subHours($stundenAlt);
        $backup->save();

        return $backup;
    }

    /** Welche Ablagenamen noch eine Zeile haben, die nicht abgeräumt wird. */
    /** @return list<string> */
    private function verbleibend(Subscription $subscription): array
    {
        // **Ohne Mandantenklammer, und ohne sie misst dieser Helfer nichts.**
        // Ein Test hat kein angemeldetes Konto; der Grundzustand ist
        // `whereRaw('0 = 1')`, und die leere Liste sähe aus wie „alles
        // abgeräumt" — also genau wie das Gegenteil dessen, was geprüft wird.
        return app(Tenancy::class)->withoutRestriction(
            static fn (): array => Backup::query()
                ->where('subscription_id', $subscription->id)
                ->orderBy('id')
                ->pluck('storage_name')
                ->all(),
        );
    }

    /** Wie viele Zeilen es überhaupt gibt — ebenfalls ohne Klammer. */
    private function zeilen(): int
    {
        return app(Tenancy::class)->withoutRestriction(
            static fn (): int => Backup::query()->count(),
        );
    }

    /**
     * **Die ältesten gehen, die jüngsten bleiben.**
     *
     * Und die Zeilen bleiben zunächst alle stehen: `Backups::remove()` reiht
     * `backup.remove` ein und löscht nicht selbst — die Datei liegt
     * `root:srvpanel` und geht nur über den Agenten fort.
     */
    public function test_the_oldest_go_and_the_newest_stay(): void
    {
        $subscription = $this->subscription(2);
        $this->backups($subscription, 5);

        $fort = app(Retention::class)->prune($subscription);

        $this->assertCount(3, $fort, 'Es werden nicht drei von fünf abgeräumt.');

        // Die drei ältesten — `stand-0` ist der älteste.
        $this->assertSame(
            [$subscription->name.'-stand-0', $subscription->name.'-stand-1', $subscription->name.'-stand-2'],
            array_values(array_reverse($fort)),
            'Abgeräumt wird nicht von hinten.',
        );

        // **Die Gegenprobe zur Auswahl**: Für jede abgeräumte Zeile steht ein
        // Vorgang da, und für die beiden jüngsten nicht.
        $vorgaenge = app(Tenancy::class)->withoutRestriction(
            static fn (): array => Operation::query()->where('task', 'backup.remove')->get()->all(),
        );

        $this->assertCount(3, $vorgaenge);

        /*
         * **Und jeder trägt sein Abonnement.** Dieser Lauf hat kein
         * angemeldetes Konto — genau der Zustand des Nachtlaufs. Fragte
         * {@see Backups::remove()} die Beziehung ohne Klammer, käme dort `null`
         * heraus, und der Vorgang hinge an keinem Abonnement: Er stünde in
         * keiner Vorgangsliste des Kunden und in keiner Rechnung über sein
         * Abonnement, während der Agent die Datei zu Recht entfernt.
         *
         * > **Ein Vorgang, der seinen Gegenstand verliert, tut trotzdem das
         * > Richtige — und niemand findet ihn danach wieder.**
         */
        foreach ($vorgaenge as $vorgang) {
            $this->assertSame(
                $subscription->id,
                $vorgang->subscription_id,
                'Der Vorgang hängt an keinem Abonnement — dann hat die Mandantenklammer in remove() gefehlt.',
            );
        }
    }

    /**
     * Ein Abonnement ohne Aufbewahrungszahl wird **nicht** abgeräumt.
     *
     * `null` heisst hier nicht „unbegrenzt", sondern „keine Regel, die greifen
     * kann" — und dann darf nichts verschwinden.
     *
     * > **Eine Aufbewahrungsregel, die auf einen Plan zeigt, den es nicht mehr
     * > gibt, darf nicht auf null fallen.**
     */
    public function test_without_a_number_nothing_is_pruned(): void
    {
        $subscription = $this->subscription(null);
        $this->backups($subscription, 4);

        $this->assertSame([], app(Retention::class)->prune($subscription));
        $this->assertCount(4, $this->verbleibend($subscription));
    }

    /**
     * Eine laufende Sicherung wird nicht abgeräumt.
     *
     * Sie ist eine halbe Datei; sie zu entfernen hiesse, dem laufenden Vorgang
     * das Ziel unter den Händen wegzunehmen.
     */
    public function test_a_running_backup_is_left_alone(): void
    {
        $subscription = $this->subscription(1);
        $this->backups($subscription, 3, BackupStatus::Pending);

        $this->assertSame([], app(Retention::class)->prune($subscription));
        $this->assertCount(3, $this->verbleibend($subscription));
    }

    /**
     * **Fällig ist, was älter ist als das Fenster** — und sonst nichts.
     *
     * Eine laufende zählt mit: Sonst legte ein Lauf, der neben einer noch nicht
     * fertigen Sicherung startet, eine zweite an.
     */
    public function test_due_asks_the_age_of_the_newest(): void
    {
        $retention = app(Retention::class);

        $ohne = $this->subscription(3);
        $this->assertTrue($retention->isDue($ohne, 20), 'Ohne jede Sicherung ist eine fällig.');

        $frisch = $this->subscription(3);
        $this->backup($frisch, 'frisch', BackupStatus::Ready, stundenAlt: 3);
        $this->assertFalse($retention->isDue($frisch, 20), 'Drei Stunden alt und trotzdem fällig.');

        $alt = $this->subscription(3);
        $this->backup($alt, 'alt', BackupStatus::Ready, stundenAlt: 21);
        $this->assertTrue($retention->isDue($alt, 20), 'Einundzwanzig Stunden alt und nicht fällig.');

        // Und die laufende zählt mit.
        $laeuft = $this->subscription(3);
        $this->backup($laeuft, 'laeuft', BackupStatus::Pending, stundenAlt: 1);
        $this->assertFalse($retention->isDue($laeuft, 20), 'Neben einer laufenden Sicherung wird eine zweite fällig.');
    }

    /**
     * **Das Fälligkeitsfenster ist kleiner als der kürzeste Abstand zweier
     * Läufe.**
     *
     * Der Timer steht auf `OnCalendar=daily` mit zwei Stunden Streuung; zwei
     * Läufe liegen damit zwischen 22 und 26 Stunden auseinander. Ein Fenster
     * von 24 verlöre jeden Lauf, den die Streuung nach vorn zieht — still, denn
     * es entstünde einfach keine Sicherung.
     *
     * > **Ein Fälligkeitsfenster, das so gross ist wie der Takt, verliert jeden
     * > Lauf, den die Streuung nach vorn zieht.**
     *
     * **Gemessen an der Unit-Datei und nicht an einer Zahl hier.** Eine zweite
     * Aufzählung wäre die, die veraltet, sobald jemand die Streuung ändert.
     */
    public function test_the_due_window_survives_the_timer_jitter(): void
    {
        $timer = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/systemd/srvpanel-backups.timer');

        $this->assertMatchesRegularExpression('/^OnCalendar=daily$/m', $timer, 'Der Takt ist nicht mehr täglich — dann gilt die Rechnung nicht.');

        $this->assertSame(
            1,
            preg_match('/^RandomizedDelaySec=(\d+)h$/m', $timer, $treffer),
            'Die Streuung ist nicht in Stunden angegeben — dann lässt sich der kürzeste Abstand nicht rechnen.',
        );

        $streuung = (int) $treffer[1];
        $kuerzester = 24 - $streuung;

        $fenster = $this->constantOf('App\\Console\\Commands\\RunBackups', 'DUE_AFTER_HOURS');

        $this->assertLessThan($kuerzester, $fenster, sprintf(
            'Das Fälligkeitsfenster (%d h) ist nicht kleiner als der kürzeste Abstand zweier Läufe (%d h). '.
            'Jeder Lauf, den die Streuung nach vorn zieht, legt dann keine Sicherung an — und sagt es nicht.',
            $fenster,
            $kuerzester,
        ));
    }

    /** Eine private Konstante lesen, ohne sie zu veröffentlichen. */
    private function constantOf(string $klasse, string $name): int
    {
        $spiegel = new \ReflectionClass($klasse);

        $this->assertTrue($spiegel->hasConstant($name), $klasse.' kennt '.$name.' nicht.');

        return (int) $spiegel->getConstant($name);
    }

    /**
     * **Vor dem Rückbau entsteht eine Sicherung** — Schritt 10.
     *
     * Gemessen an der Wirkung: Die Route wird gerufen, und danach steht eine
     * Zeile da, die es vorher nicht gab.
     */
    public function test_a_removal_makes_a_backup_first(): void
    {
        $subscription = $this->subscription(3);

        $this->assertSame(0, $this->zeilen());

        app(Backups::class)->beforeRemoval($subscription);

        $this->assertSame(1, $this->zeilen(), 'Vor dem Rückbau entsteht keine Sicherung.');
    }

    /**
     * **Und der Betreiber kann sie abschalten.**
     *
     * Die Gegenprobe zum Fall darüber: Ohne sie belegte er nur, dass eine
     * Sicherung entsteht — nicht, dass der Schalter sie entscheidet.
     */
    public function test_the_operator_can_switch_it_off(): void
    {
        app(Settings::class)->saveBackups(automatic: false, beforeRemoval: false);

        app(Backups::class)->beforeRemoval($this->subscription(3));

        $this->assertSame(0, $this->zeilen(), 'Der Schalter entscheidet nichts.');
    }

    /**
     * **Eine Sicherung ohne Abonnement geht über den Agenten und nicht über
     * `delete()`.**
     *
     * Der Fund, der diesen Fall ausgelöst hat: `Backups::remove()` las
     * `$backup->subscription` — eine faul geladene Beziehung, und die nimmt die
     * Mandantenklammer. Aus dem nächtlichen Lauf der Aufbewahrung, der kein
     * angemeldetes Konto hat, kam deshalb **immer** `null`, und die Zeile ging
     * den Zweig „ohne Umweg über den Agenten": gelöscht, und die Datei
     * liegengeblieben. Jede Nacht eine mehr.
     *
     * Der Kopf der Migration sagt, warum das falsch ist:
     * `/var/lib/srvpanel/backups/<abo>` liegt ausserhalb von allem, was
     * `subscription.remove` anfasst — *„Die Sicherung überlebt ihr
     * Abonnement."*
     *
     * > **Ein Wächter, der vom Bestand des Panels ausgeht, sieht nur, was das
     * > Panel kennt — und ein Rest ist gerade das, was es nicht kennt.**
     */
    public function test_a_backup_without_a_subscription_still_goes_through_the_agent(): void
    {
        $subscription = $this->subscription(3);
        $name = (string) $subscription->name;

        $backup = $this->backup($subscription, 'verwaist', BackupStatus::Ready);

        // Das Abonnement fällt weg — `subscription_id` steht auf `nullOnDelete`,
        // die Abschrift des Namens bleibt.
        app(Tenancy::class)->withoutRestriction(static fn () => $subscription->delete());

        $verwaist = app(Tenancy::class)->withoutRestriction(
            static fn (): Backup => Backup::query()->findOrFail($backup->id),
        );

        $this->assertNull($verwaist->subscription_id, 'Die Zeile hängt noch an ihrem Abonnement — dann misst der Fall nichts.');
        $this->assertSame($name, $verwaist->subscription_name, 'Die Abschrift des Namens fehlt.');

        app(Backups::class)->remove($verwaist);

        $vorgang = app(Tenancy::class)->withoutRestriction(
            static fn (): ?Operation => Operation::query()->where('task', 'backup.remove')->first(),
        );

        $this->assertNotNull($vorgang, implode("\n", [
            'Die Zeile wurde ohne den Agenten gelöscht.',
            'Die Datei liegt dann weiter unter /var/lib/srvpanel/backups und steht in keiner Liste —',
            'genau der Rest, vor dem docs/117 §9 Punkt 7 warnt.',
        ]));

        $this->assertSame($name, $vorgang->payload['subscription'] ?? null, 'Der Vorgang nennt das Abonnement nicht beim abgeschriebenen Namen.');
        $this->assertSame('verwaist', $vorgang->payload['storage'] ?? null);
        $this->assertNull($vorgang->subscription_id, 'Der Vorgang hängt an einem Abonnement, das es nicht mehr gibt.');

        /*
         * **Und er trägt seinen Gegenstand.** Ohne Abonnement ist die Zeile der
         * Sicherung das Einzige, worüber ein Fehlschlag noch auffindbar ist —
         * und der Lebenslauf sucht sie über genau dieses Paar.
         */
        $this->assertSame(OperationSubject::Backup->value, $vorgang->subject_type, 'Der Vorgang nennt seinen Gegenstand nicht.');
        $this->assertSame($verwaist->id, $vorgang->subject_id);

        // Und die Zeile steht noch — sie geht erst, wenn der Agent geantwortet
        // hat. Das ist die zweite Grenze.
        $this->assertSame(1, $this->zeilen(), 'Die Zeile ist fort, bevor der Agent geantwortet hat.');
    }

    /**
     * Ein Abonnement ohne Systembenutzer wird nicht gesichert.
     *
     * Es hat kein Verzeichnis — die Sicherung wäre ein Vorgang, der an einem
     * fehlenden Pfad scheitert, und zwar mitten im Rückbau.
     */
    public function test_a_subscription_without_a_system_user_is_skipped(): void
    {
        $plan = Plan::factory()->create(['quotas' => [Quota::Backups->value => 3]]);
        $subscription = Subscription::factory()->create(['plan_id' => $plan->id, 'system_user' => '']);

        app(Backups::class)->beforeRemoval($subscription);

        $this->assertSame(0, $this->zeilen());
    }

    /**
     * **Und der nächtliche Lauf stellt dieselbe Frage.**
     *
     * Hier stand sie zuerst nur im Rückbau. `RunBackups::eligible()` fragte den
     * Zustand, die Funktion des Plans und das Kontingent — und nicht, ob es ein
     * Verzeichnis gibt. Für ein aktives Abonnement ohne Systembenutzer legte er
     * damit jede Nacht einen Vorgang an, der an einem fehlenden Pfad scheitert,
     * und meldete jede Nacht einen Fehlschlag.
     *
     * > **Ein Fehler, den man an einer Stelle vermieden hat, ist an der
     * > nächsten wieder da, wenn die Vermeidung nicht die Regel wurde.**
     *
     * **Der Fall misst beide Richtungen in einem Lauf**, und das ist der Punkt:
     * Ein Prüfkörper allein ohne Verzeichnis liefe auch dann grün durch, wenn
     * das Kommando überhaupt nichts anlegte. Das Abonnement daneben sagt, dass
     * es angelegt hätte.
     */
    public function test_the_nightly_run_skips_a_subscription_without_a_directory(): void
    {
        app(Settings::class)->saveBackups(automatic: true, beforeRemoval: false);

        $mitVerzeichnis = $this->subscription(3);

        $plan = Plan::factory()->create(['quotas' => [Quota::Backups->value => 3]]);
        $ohneVerzeichnis = Subscription::factory()->create([
            'plan_id' => $plan->id,
            'system_user' => '',
        ]);

        $this->artisan('srvpanel:backups')->assertExitCode(0);

        $this->assertSame(
            [$mitVerzeichnis->id],
            app(Tenancy::class)->withoutRestriction(
                static fn (): array => Backup::query()->pluck('subscription_id')->all(),
            ),
            'Der Lauf hat entweder für das Abonnement ohne Verzeichnis gesichert oder für keines.',
        );

        $this->assertSame(0, app(Tenancy::class)->withoutRestriction(
            static fn (): int => Backup::query()->where('subscription_id', $ohneVerzeichnis->id)->count(),
        ));
    }
}
