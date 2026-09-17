<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BackupStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Models\Account;
use App\Models\Backup;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Backups\BackupLifecycle;
use App\Support\Plans\Quota;
use App\Support\Settings\Settings;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WithoutMarkupComments;
use Tests\TestCase;

/**
 * Der Rückbau nimmt die Sicherungen **nicht** mit.
 *
 * ## Warum es diesen Wächter gibt
 *
 * `Backups::removeAll()` stand von Schritt 3 bis zum 16. September 2026 da und
 * hatte **nie einen Aufrufer**. Sein Dokumentblock nannte den Rückbau als
 * seinen Ort — und genau dort darf er nicht laufen:
 * `backups.subscription_id` steht auf `nullOnDelete`, und der Kopf der
 * Migration sagt warum. *„Die Sicherung überlebt ihr Abonnement."*
 *
 * Seit Schritt 10 hängt ein Merkmal daran: Die Sicherung **vor** dem Rückbau
 * wäre die erste, die der Rückbau mitnähme. Ein Griff, der sichert und die
 * Sicherung im selben Zug löscht, ist schlimmer als keiner — er sieht aus wie
 * Vorsicht.
 *
 * > **Eine Methode, die niemand ruft, ist von aussen nicht von einer zu
 * > unterscheiden, die es nicht gibt — und eine, deren einziger denkbarer Ort
 * > ihr widerspricht, ist schlimmer als keine.**
 *
 * ## Gemessen an der Wirkung
 *
 * Nicht daran, dass die Methode fort ist — das wäre eine Zusage über den
 * Quelltext. Gefahren wird die **echte Route**, und danach zweierlei: dass
 * kein `backup.remove` über das ganze Verzeichnis eingereiht wurde, und dass
 * die Zeilen den `forceDelete()` überleben, mit dem `Lifecycle::withdraw()`
 * endet — ohne Abonnement und mit der Abschrift ihres Namens.
 *
 * ## Was er nicht hält
 *
 * Ob die **Datei** danach noch auf der Platte liegt, sagt nur ein Server:
 * `subscription.remove` läuft im Agenten, und dieser Test hat keinen. Gehalten
 * ist, dass das Panel nichts einreiht, was sie entfernen würde.
 */
final class BackupTeardownTest extends TestCase
{
    use RefreshDatabase;
    use WithoutMarkupComments;

    /**
     * Die Sicherung **vor** dem Rückbau überlebt den Rückbau.
     *
     * **Gemessen über den ganzen Weg und nicht über die Route allein.**
     * `destroy()` löscht nichts — es reiht `subscription.remove` ein, und
     * gelöscht wird erst, wenn der Agent geantwortet hat
     * ({@see Lifecycle}, zweite Grenze). Der
     * erste Wurf dieses Falls prüfte unmittelbar nach der Route und fand das
     * Abonnement noch vor.
     *
     * > **Ein Prüfkörper, der vor der Wirkung misst, misst den Klick und nicht
     * > den Zustand.**
     *
     * Der Rückbau selbst steht hier deshalb als `forceDelete()` da — genau die
     * Zeile, mit der `Lifecycle::withdraw()` endet.
     */
    public function test_the_backup_taken_before_a_teardown_survives_it(): void
    {
        $subscription = $this->subscription();
        $name = (string) $subscription->name;

        $alt = $this->backup($subscription, 'von-hand');

        $this->actingAs($this->admin())
            ->delete("/subscriptions/{$subscription->id}")
            ->assertRedirect();

        $sicherheit = app(Tenancy::class)->withoutRestriction(
            static fn (): ?Backup => Backup::query()
                ->where('subscription_id', $subscription->id)
                ->where('storage_name', '!=', 'von-hand')
                ->first(),
        );

        $this->assertNotNull($sicherheit, implode("\n", [
            'Der Rückbau hat nicht vorher gesichert — dann misst dieser Fall nichts.',
            'Er hängt an Settings::backups()[before_removal], und der Prüfkörper schaltet es ein.',
        ]));

        // Was `Lifecycle::withdraw()` tut, nachdem der Agent geantwortet hat.
        app(Tenancy::class)->withoutRestriction(static fn () => $subscription->forceDelete());

        foreach ([$alt->id => 'die von Hand angelegte', $sicherheit->id => 'die vor dem Rückbau angelegte'] as $id => $was) {
            $zeile = app(Tenancy::class)->withoutRestriction(
                static fn (): ?Backup => Backup::query()->find($id),
            );

            $this->assertNotNull($zeile, sprintf(
                "Der Rückbau hat %s Sicherung mitgenommen.\n".
                "backups.subscription_id steht auf nullOnDelete, damit sie ihn überlebt — und seit\n".
                'Schritt 10 legt der Rückbau selbst eine an, die es sonst als Erste träfe.',
                $was,
            ));

            $this->assertNull($zeile->subscription_id, 'Die Zeile hängt noch an ihrem Abonnement.');
            $this->assertSame($name, $zeile->subscription_name, 'Die Abschrift des Namens fehlt.');
        }
    }

    /**
     * Und kein Vorgang räumt das ganze Verzeichnis ab.
     *
     * **`backup.remove` ohne `storage` ist der Griff, der es täte.** Er bleibt
     * im Agenten — er ist der Weg zurück, den `docs/35` verlangt —, und
     * automatisch geht ihn niemand. Was liegenbleibt, meldet die
     * Bestandsdiagnose, statt es zu löschen.
     *
     * > **Ein Rest wird gemeldet und nicht gelöscht.**
     */
    public function test_no_operation_wipes_the_whole_directory(): void
    {
        $subscription = $this->subscription();
        $this->backup($subscription, 'eine');
        $this->backup($subscription, 'zwei');

        $this->actingAs($this->admin())->delete("/subscriptions/{$subscription->id}");

        $vorgaenge = app(Tenancy::class)->withoutRestriction(
            static fn (): array => Operation::query()->where('task', 'backup.remove')->get()->all(),
        );

        foreach ($vorgaenge as $vorgang) {
            $this->assertNotSame(
                '',
                (string) ($vorgang->payload['storage'] ?? ''),
                'Ein backup.remove ohne `storage` räumt das ganze Verzeichnis ab — samt der '.
                'Sicherung, die der Rückbau gerade erst angelegt hat.',
            );
        }

        /*
         * **Die Gegenprobe zur Behauptung darüber.** Ohne sie wäre die Schleife
         * auch dann grün, wenn gar kein `backup.remove` entstünde — und dann
         * misst sie nichts. Der Rückbau legt eine Sicherung an; abgeräumt wird
         * dabei nichts, also steht hier null.
         */
        $this->assertSame(0, count($vorgaenge), 'Der Rückbau räumt Sicherungen ab, statt sie zu behalten.');

        $angelegt = app(Tenancy::class)->withoutRestriction(
            static fn (): int => Operation::query()->where('task', 'backup.create')->count(),
        );

        $this->assertSame(1, $angelegt, 'Der Rückbau hat nicht vorher gesichert — dann misst dieser Fall nichts.');
    }

    /**
     * **Und sie ist danach auffindbar.**
     *
     * Der Befund, der diesen Fall ausgelöst hat, kam nicht aus einer Messung,
     * sondern aus dem **Ausschreiben des Abnahmelaufs**: Jede Liste dieses
     * Panels führt über ein Abonnement — `/backups` wählt eines,
     * `/subscriptions/{id}/backups` braucht eines. Eine Sicherung, die ihren
     * Rückbau überlebt hat, stand damit in **keiner** Liste und war nur über
     * eine Adresse erreichbar, deren Kennung niemand kennt.
     *
     * Das ist ausgerechnet der Fall, für den es die Stufe gibt.
     *
     * > **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht
     * > sie dort?**
     *
     * **Was dieser Fall nicht halten kann**, ist genau diese Frage: Ob jemand
     * dort sucht, hängt an einer Erwartung und nicht an einer Eigenschaft des
     * Quelltextes. Gehalten ist, dass es überhaupt einen Weg gibt.
     */
    public function test_a_backup_without_a_subscription_is_findable(): void
    {
        $subscription = $this->subscription();
        $name = (string) $subscription->name;

        $backup = $this->backup($subscription, 'ueberlebt');

        app(Tenancy::class)->withoutRestriction(static fn () => $subscription->forceDelete());

        /*
         * **Ein lebendes Abonnement daneben, und genau eines.** Das ist die
         * Bedingung, unter der die Abkürzung der Seite greift — ohne sie misst
         * dieser Fall den kurzen Weg gar nicht, und ein Bruch daran bliebe
         * grün.
         *
         * > **Ein Prüfkörper, der die Bedingung nicht herstellt, unter der der
         * > Fehler entsteht, misst ihn nicht.**
         */
        $lebend = $this->subscription();

        // **Ohne Mandantenklammer gezählt** — hier ist noch niemand angemeldet,
        // und der Grundzustand ist `whereRaw('0 = 1')`.
        $this->assertSame(1, app(Tenancy::class)->withoutRestriction(
            static fn (): int => Subscription::query()->count(),
        ), 'Der Prüfkörper stellt den kurzen Weg nicht her.');
        $this->assertNotNull($lebend->id);

        $antwort = $this->actingAs($this->admin())->get('/backups');

        $antwort->assertSuccessful();

        /*
         * **Und zwar auch dann, wenn es genau ein Abonnement gibt.** Der kurze
         * Weg der Seite sprang bei einem einzigen weiter — und übersprang damit
         * den Sonderfall, der sonst nirgends steht.
         *
         * > **Eine Weiterleitung, die den Sonderfall überspringt, macht ihn
         * > unerreichbar und sieht dabei aus wie Bequemlichkeit.**
         */
        $antwort->assertInertia(fn ($page) => $page
            ->component('Subscriptions/BackupPick')
            ->where('orphaned.0.id', $backup->id)
            ->where('orphaned.0.subscription_name', $name));
    }

    /**
     * **Und der kurze Weg bleibt, solange es nichts zu übersehen gibt.**
     *
     * Ohne verwaiste Sicherung springt die Seite bei genau einem Abonnement
     * weiter — die Auswahlseite beantwortete dort eine Frage mit einer
     * einzigen möglichen Antwort.
     *
     * > **Eine Frage, die nur eine mögliche Antwort hat, ist keine Frage.**
     */
    public function test_a_single_subscription_still_skips_the_picker(): void
    {
        $this->subscription();

        $this->actingAs($this->admin())
            ->get('/backups')
            ->assertRedirect();
    }

    /**
     * **Und sie lässt sich auch wieder entfernen** — der Befund des Betreibers
     * vom 17. September 2026.
     *
     * Der Bereich „Ohne Abonnement" trug keine Handlung, und die Adresse von
     * `backups.destroy` steht unter `/subscriptions/{subscription}/…`: Bei
     * einer verwaisten Zeile ist `subscription_id` `null`, und
     * `abort_unless($backup->subscription_id === $subscription->id)` kann nie
     * zutreffen. Der Griff dahinter war gebaut — `Backups::remove()` hat seit
     * dem 16. September einen eigenen Zweig für genau diesen Fall — und von
     * niemandem erreichbar.
     *
     * > **Ein Griff, den es gibt und zu dem kein Weg führt, ist von einem, den
     * > es nicht gibt, nicht zu unterscheiden.**
     *
     * **Gemessen wird der Weg über den Agenten und nicht ein Verschwinden.**
     * Die Datei liegt `root:srvpanel`; die Zeile bleibt stehen, bis er
     * geantwortet hat. Ein `delete()` hier hinterliesse genau den Rest, den
     * `backup.verify` als `orphan` meldet — und der Fall wäre grün, weil die
     * Zeile ja fort ist.
     */
    public function test_a_backup_without_a_subscription_can_be_removed(): void
    {
        $subscription = $this->subscription();
        $backup = $this->backup($subscription, 'verwaist');

        app(Tenancy::class)->withoutRestriction(static fn () => $subscription->forceDelete());

        $this->actingAs($this->admin())
            ->delete('/backups/'.$backup->id)
            ->assertRedirect('/backups');

        $vorgang = app(Tenancy::class)->withoutRestriction(
            static fn (): ?Operation => Operation::query()->where('task', 'backup.remove')->first(),
        );

        $this->assertNotNull(
            $vorgang,
            'Es ist kein Vorgang entstanden — dann ist die Zeile ohne den Agenten gelöscht worden, und die Datei liegt noch da.',
        );

        $this->assertNotNull(
            app(Tenancy::class)->withoutRestriction(
                static fn (): ?Backup => Backup::query()->find($backup->id),
            ),
            'Die Zeile ist schon fort, bevor der Agent geantwortet hat — dann beschreibt sie nichts mehr und die Datei ist unauffindbar.',
        );
    }

    /**
     * **Die Gegenrichtung, und sie ist die wichtigere.**
     *
     * `/backups/{backup}` trägt kein `{subscription}` und damit auch kein
     * `can:manageBackups,subscription`. Nähme sie eine Zeile mit Abonnement an,
     * wäre sie eine zweite Tür an derselben Sache — eine, die die Frage „darf
     * dieser Aufrufer *dieses* Abonnement verwalten?" gar nicht erst stellt.
     *
     * > **Zwei Adressen für dieselbe Zeile sind zwei Türen — und die zweite
     * > muss dieselbe Frage stellen oder eine engere.**
     */
    public function test_the_door_for_orphans_refuses_a_backup_that_still_has_one(): void
    {
        $subscription = $this->subscription();
        $backup = $this->backup($subscription, 'gehoert-noch');

        $this->actingAs($this->admin())
            ->delete('/backups/'.$backup->id)
            ->assertNotFound();

        $this->assertNull(
            app(Tenancy::class)->withoutRestriction(
                static fn (): ?Operation => Operation::query()->where('task', 'backup.remove')->first(),
            ),
            'Die Tür für Verwaiste hat eine Sicherung mit Abonnement entfernt — unter Umgehung von manageBackups.',
        );
    }

    /**
     * Und die andere Tür weist eine verwaiste Zeile ab.
     *
     * Sie tut es von Bauart wegen — die Adresse verlangt ein Abonnement —, und
     * genau deshalb steht der Fall hier: Ohne ihn stünde die eine Hälfte des
     * Paares gemessen da und die andere als Selbstverständlichkeit.
     */
    public function test_the_door_with_a_subscription_refuses_an_orphan(): void
    {
        $subscription = $this->subscription();
        $backup = $this->backup($subscription, 'verwaist-zwei');
        $id = (int) $subscription->id;

        app(Tenancy::class)->withoutRestriction(static fn () => $subscription->forceDelete());

        $this->actingAs($this->admin())
            ->delete(sprintf('/subscriptions/%d/backups/%d', $id, $backup->id))
            ->assertNotFound();
    }

    /**
     * Und die Seite bietet den Griff wirklich an.
     *
     * **Was dieser Fall nicht kann:** Er liest die Vorlage und sagt damit, dass
     * dort ein Aufruf steht — nicht, dass ein Betrachter ihn findet. Die Frage
     * „wo sucht jemand diese Handlung" hängt an einer Erwartung und nicht am
     * Quelltext; sie steht in `CLAUDE.md` als Frage.
     *
     * Die Kommentare fallen vorher weg: Der Absatz, der diesen Befund erklärt,
     * schreibt die Adresse wörtlich hin.
     *
     * > **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie
     * > irgendwo steht — und ein Kommentar, der die entfernte Zeile zitiert,
     * > stellt sie für ihn wieder her.**
     */
    public function test_the_picker_offers_the_removal(): void
    {
        $quelle = $this->withoutMarkupComments(
            (string) file_get_contents(base_path('resources/js/Pages/Subscriptions/BackupPick.vue')),
        );

        $this->assertStringContainsString(
            'router.delete(`/backups/${sicherung.id}`)',
            $quelle,
            'Die Auswahlseite ruft die Tür für verwaiste Sicherungen nicht — dann gibt es sie und niemand kommt hin.',
        );

        /*
         * **Und ein Bedienelement, das sie ruft.** Ohne diese Zeile bliebe der
         * Fall grün, wenn jemand die Zelle aus der Tabelle nimmt und die
         * Funktion stehenlässt — also genau im Zustand, der diesen Befund
         * ausgelöst hat: der Griff da, der Weg fort.
         */
        $this->assertStringContainsString(
            '@click="entfernen(sicherung)"',
            $quelle,
            'Die Zeile trägt kein Bedienelement, das die Tür ruft — die Funktion allein erreicht niemand.',
        );
    }

    /**
     * **Die letzte Zeile eines zurückgebauten Abonnements räumt sein
     * Verzeichnis ab.**
     *
     * Befund 10 aus `docs/119`, zweite Hälfte. Die Bestandsdiagnose meldet ein
     * leeres Verzeichnis seit dem 17. September; der Griff dafür lag im
     * Agenten und hatte keinen Aufrufer.
     *
     * > **Ein Griff, den es gibt und zu dem kein Weg führt, ist von einem, den
     * > es nicht gibt, nicht zu unterscheiden.**
     *
     * Gemessen am **Vorgang** und nicht am Verschwinden des Verzeichnisses:
     * Dieser Prüfstand hat keinen Agenten, und was auf der Platte geschieht,
     * ist dessen Sache. Gefragt ist, ob das Panel ihn überhaupt beauftragt —
     * mit dem Namen aus der Abschrift und **ohne** `storage`, denn genau daran
     * unterscheidet `BackupRemove` seine beiden Gegenstände.
     */
    public function test_the_last_backup_of_a_dead_subscription_clears_its_directory(): void
    {
        $name = 'p8-fort.invalid';

        $zeile = Backup::query()->create([
            'subscription_id' => null,
            'subscription_name' => $name,
            'storage_name' => 'letzte-20260917-120000',
            'status' => BackupStatus::Ready,
        ]);

        $this->lebenslauf($zeile);

        $aufraeumen = $this->aufraeumvorgang();

        $this->assertNotNull(
            $aufraeumen,
            'Die letzte Zeile ist fort, und das Verzeichnis bleibt — der Griff im Agenten hat weiter keinen Aufrufer.',
        );

        $payload = is_array($aufraeumen->payload) ? $aufraeumen->payload : [];

        $this->assertSame($name, $payload['subscription'] ?? null, 'Der Vorgang nennt ein anderes Abonnement.');

        // **Ohne `storage`, und daran hängt alles.** Mit dem Schlüssel nähme
        // `BackupRemove` den Zweig für eine einzelne Ablage und räumte nichts
        // ab — der Vorgang liefe durch und meldete Erfolg.
        $this->assertArrayNotHasKey('storage', $payload, 'Der Vorgang nennt eine Ablage — dann räumt er kein Verzeichnis ab.');
    }

    /**
     * **Und in den drei Lagen, in denen jemand das Verzeichnis noch braucht,
     * geschieht es nicht.**
     *
     * Das ist die Hälfte, die wehtut: Ein Abräumen, das nicht hätte laufen
     * dürfen, trifft ein Verzeichnis, das gleich wieder gefüllt wird — oder
     * eines, in dem noch eine Sicherung liegt.
     *
     * `rmdir(2)` fängt die letzte Lage auf der Platte auf; hier wird gemessen,
     * dass das Panel gar nicht erst fragt.
     *
     * @param  'lebt'|'zweite'|'pending'  $lage
     */
    #[DataProvider('lagen')]
    public function test_the_directory_stays_when_someone_still_needs_it(string $lage): void
    {
        $abo = $this->subscription();
        $name = (string) $abo->name;

        $zeile = $this->backup($abo, 'geht-fort');

        if ($lage === 'lebt') {
            // Das Abonnement lebt: Die nächste Sicherung füllt das Verzeichnis
            // wieder. Es hier abzuräumen wäre Arbeit gegen den Normalfall.
            $zeile->forceFill(['subscription_id' => $abo->id])->save();
        }

        if ($lage !== 'lebt') {
            // Zurückgebaut — aber es steht noch eine Zeile daneben.
            $zeile->forceFill(['subscription_id' => null])->save();

            Backup::query()->create([
                'subscription_id' => null,
                'subscription_name' => $name,
                'storage_name' => 'bleibt-20260917-120000',
                'status' => $lage === 'pending' ? BackupStatus::Pending : BackupStatus::Ready,
            ]);
        }

        $this->lebenslauf($zeile);

        $this->assertNull(
            $this->aufraeumvorgang(),
            sprintf('Lage „%s": Das Panel räumt ein Verzeichnis ab, das noch gebraucht wird.', $lage),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function lagen(): array
    {
        return [
            'das Abonnement lebt noch' => ['lebt'],
            'eine zweite Sicherung liegt daneben' => ['zweite'],
            'eine Sicherung wird gerade geschrieben' => ['pending'],
        ];
    }

    /**
     * Der Abräumvorgang — oder `null`, wenn es keinen gibt.
     *
     * **Gelesen mit gelöster Mandantenklammer, und das ist keine
     * Bequemlichkeit.** `Operation` trägt `BelongsToSubscription`, und ein
     * Abräumvorgang hat **kein** Abonnement — das ist ja sein Fall. Geklammert
     * gäbe diese Abfrage immer `null` zurück: Der Fall darüber wäre rot,
     * obwohl der Vorgang dasteht, und die drei Fälle darunter wären grün, ohne
     * je etwas angesehen zu haben.
     *
     * Genau so ist es beim ersten Wurf gewesen — drei grüne Fälle aus dem
     * falschen Grund, und nur der vierte hat sich gemeldet.
     *
     * > **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall,
     * > misst nicht** — und eine Null, die „nicht nachgesehen" bedeutet, sieht
     * > aus wie „nichts geschehen".
     */
    private function aufraeumvorgang(): ?Operation
    {
        return app(Tenancy::class)->withoutRestriction(static fn (): ?Operation => Operation::query()
            ->where('task', 'backup.remove')
            ->whereNull('subject_id')
            ->latest('id')
            ->first());
    }

    /**
     * Den Lebenslauf über eine gelungene `backup.remove` fahren.
     *
     * Über die echte Klasse aus dem Container und nicht über einen Nachbau:
     * Gemessen werden soll die Entscheidung, die dort steht.
     */
    private function lebenslauf(Backup $backup): void
    {
        $vorgang = Operation::query()->create([
            'subscription_id' => $backup->subscription_id,
            'subject_type' => OperationSubject::Backup->value,
            'subject_id' => $backup->id,
            'type' => 'backup.remove',
            'task' => 'backup.remove',
            'payload' => ['subscription' => (string) $backup->subscription_name, 'storage' => (string) $backup->storage_name],
            'status' => OperationStatus::Succeeded,
            'progress' => 100,
        ]);

        app(BackupLifecycle::class)->afterSuccess($vorgang);
    }

    private function admin(): Account
    {
        return Account::factory()->admin()->create();
    }

    /** Ein Abonnement mit Verzeichnis — ohne Systembenutzer sichert der Rückbau zu Recht nicht. */
    private function subscription(): Subscription
    {
        app(Settings::class)->saveBackups(automatic: false, beforeRemoval: true);

        $plan = Plan::factory()->create(['quotas' => [Quota::Backups->value => 3]]);

        $subscription = Subscription::factory()->create([
            'plan_id' => $plan->id,
            'system_user' => 'p'.random_int(2000, 9999),
        ]);

        $this->assertNotNull($subscription->system_user, 'Der Prüfkörper hat kein Verzeichnis.');

        return $subscription;
    }

    private function backup(Subscription $subscription, string $name): Backup
    {
        return Backup::query()->create([
            'subscription_id' => $subscription->id,
            'subscription_name' => (string) $subscription->name,
            'storage_name' => $name,
            'status' => BackupStatus::Ready,
        ]);
    }
}
