<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Database;
use App\Models\DbUser;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Databases\Databases;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SrvPanel\Agent\Ops\DbDatabaseRemove;
use Tests\TestCase;

/**
 * Eine entfernte Datenbank lässt kein Recht auf sich zurück.
 *
 * **Der Anlass steht in einer Rechtezeile vom 8. August 2026.** Auf
 * `cloudsrv24` hielt `p1118_user` ein `GRANT ALL PRIVILEGES ON p1118\_demo.*`,
 * während es `p1118_demo` längst nicht mehr gab. `DROP DATABASE` nimmt in
 * MariaDB die auf das Schema vergebenen Rechte nicht mit — sie stehen in
 * `mysql.db` und bleiben dort —, und die Anwendung nannte dem Agenten nur die
 * Zugänge, die *mitgehen*. Wer an einer zweiten Datenbank hing und darum
 * überlebte, behielt sein Recht auf die entfernte (`docs/36 §22.3p`).
 *
 * **Warum das mehr ist als eine hässliche Zeile in `SHOW GRANTS`:** Entsteht
 * der Name später wieder, hat dieser Zugang sofort alle Rechte darauf, ohne
 * dass sie ihm jemand gegeben hat. Seit `docs/36 §22.3o` ist das Verbinden
 * eines Zugangs mit einer Datenbank eine ausdrückliche Handlung im Panel; hier
 * wich der Bestand des Panels von dem ab, was MariaDB erlaubt — und von zwei
 * Fassungen derselben Regel gilt am Ende die, die niemand liest.
 *
 * **Geprüft werden beide Hälften des Weges**, weil jede für sich vollständig
 * aussieht: dass die Anwendung den bleibenden Zugang überhaupt nennt, und dass
 * aus dieser Nennung eine Anweisung wird. Und dazu die Eigenschaft, die keine
 * der beiden Listen allein hat: **kein verbundener Zugang fällt aus beiden
 * heraus.**
 */
final class OrphanedGrantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ein Abonnement mit zwei Datenbanken.
     *
     * @return array{0: Subscription, 1: Database, 2: Database}
     */
    private function twoDatabases(): array
    {
        return app(Tenancy::class)->withoutRestriction(function (): array {
            $subscription = Subscription::factory()->create(['system_user' => 'p1000']);

            return [
                $subscription,
                Database::factory()->forSubscription($subscription, 'shop')->create(),
                Database::factory()->forSubscription($subscription, 'blog')->create(),
            ];
        });
    }

    /**
     * Der Auftrag, den `remove()` in die Warteschlange legt.
     *
     * @return array<string, mixed>
     */
    private function payloadOfRemoval(Database $database): array
    {
        Queue::fake();

        app(Databases::class)->remove($database);

        $operation = app(Tenancy::class)->withoutRestriction(
            fn (): Operation => Operation::query()->where('type', 'db.database.remove')->latest('id')->firstOrFail(),
        );

        return (array) $operation->payload;
    }

    /**
     * @param  list<array{name: string, host: string}>|mixed  $entries
     * @return list<string>
     */
    private function names(mixed $entries): array
    {
        $names = array_map(
            static fn (array $entry): string => (string) $entry['name'],
            is_array($entries) ? $entries : [],
        );

        // `sort()` legt die Schlüssel neu — ein `array_values()` danach wäre
        // ohne Wirkung.
        sort($names);

        return $names;
    }

    public function test_an_access_that_stays_is_named_for_the_revoke(): void
    {
        [$subscription, $shop, $blog] = $this->twoDatabases();

        $user = app(Tenancy::class)->withoutRestriction(function () use ($subscription, $shop, $blog): DbUser {
            $user = DbUser::factory()->forSubscription($subscription, 'web')->create();
            $user->databases()->attach([$shop->id, $blog->id]);

            return $user;
        });

        $payload = $this->payloadOfRemoval($shop);

        // Er geht nicht mit — er hängt noch an `blog`.
        $this->assertSame([], $this->names($payload['users'] ?? []));

        // Aber sein Recht auf `shop` wird ihm genommen.
        $this->assertSame([$user->name], $this->names($payload['revoke'] ?? []));
    }

    public function test_an_access_with_nothing_left_goes_along(): void
    {
        [$subscription, $shop] = $this->twoDatabases();

        $user = app(Tenancy::class)->withoutRestriction(function () use ($subscription, $shop): DbUser {
            $user = DbUser::factory()->forSubscription($subscription, 'web')->create();
            $user->databases()->attach($shop->id);

            return $user;
        });

        $payload = $this->payloadOfRemoval($shop);

        $this->assertSame([$user->name], $this->names($payload['users'] ?? []));
        $this->assertSame([], $this->names($payload['revoke'] ?? []));

        // Die Gegenrichtung: Wer mitgeht, braucht kein `REVOKE` — das Konto
        // gibt es danach nicht mehr. Zwei Listen, in denen derselbe Name steht,
        // wären keine Aufteilung, sondern eine doppelte Buchung.
    }

    /**
     * Die Eigenschaft, an der die Aufteilung hängt.
     *
     * Beide Listen einzeln zu prüfen genügt nicht: Eine Bedingung, die einen
     * Zugang aus der ersten herausfallen lässt, ohne ihn in die zweite zu
     * legen, sähe in beiden Tests oben richtig aus. Gezählt wird deshalb gegen
     * den Bestand — jeder verbundene Zugang steht in genau einer Liste.
     */
    public function test_no_connected_access_falls_out_of_both_lists(): void
    {
        [$subscription, $shop, $blog] = $this->twoDatabases();

        $connected = app(Tenancy::class)->withoutRestriction(function () use ($subscription, $shop, $blog): array {
            $names = [];

            foreach ([['web', [$shop->id]], ['app', [$shop->id, $blog->id]], ['job', [$shop->id]]] as [$label, $ids]) {
                $user = DbUser::factory()->forSubscription($subscription, $label)->create();
                $user->databases()->attach($ids);
                $names[] = $user->name;
            }

            // Und einer, der mit dieser Datenbank nichts zu tun hat: Er darf in
            // keiner der beiden Listen auftauchen.
            $foreign = DbUser::factory()->forSubscription($subscription, 'other')->create();
            $foreign->databases()->attach($blog->id);

            sort($names);

            return $names;
        });

        $payload = $this->payloadOfRemoval($shop);

        $mentioned = array_merge($this->names($payload['users'] ?? []), $this->names($payload['revoke'] ?? []));
        sort($mentioned);

        $this->assertSame($connected, $mentioned);
    }

    /**
     * Und aus der Nennung wird eine Anweisung.
     *
     * Ohne diese Hälfte bliebe der Fund halb behoben: Eine Liste im Auftrag,
     * die der Agent nicht liest, ist genau die Sorte Zeichenkette, die auf
     * etwas verweist, ohne dass es sie erreicht.
     */
    public function test_every_named_account_reaches_a_statement(): void
    {
        $statements = DbDatabaseRemove::statements(
            'p1000_shop',
            [['p1000_web', 'localhost']],
            [['p1000_app', 'localhost'], ['p1000_job', '127.0.0.1']],
        );

        $text = implode("\n", $statements);

        $this->assertStringContainsString(
            "REVOKE IF EXISTS ALL PRIVILEGES ON `p1000\\_shop`.* FROM 'p1000_app'@'localhost'",
            $text,
        );
        $this->assertStringContainsString(
            "REVOKE IF EXISTS ALL PRIVILEGES ON `p1000\\_shop`.* FROM 'p1000_job'@'127.0.0.1'",
            $text,
        );
        $this->assertStringContainsString("DROP USER IF EXISTS 'p1000_web'@'localhost'", $text);

        // Kein genannter Zugang bleibt ohne Anweisung — gezählt und nicht
        // aufgezählt: Wer eine der drei Zeilen oben streicht, fällt hier auch
        // dann auf, wenn er die Behauptung darüber mit streicht.
        foreach (['p1000_web', 'p1000_app', 'p1000_job'] as $account) {
            $this->assertNotEmpty(
                array_filter($statements, static fn (string $s): bool => str_contains($s, "'".$account."'")),
                'Für '.$account.' entsteht keine Anweisung.',
            );
        }
    }

    /**
     * Das Schema fällt zuletzt.
     *
     * `Session::execute()` bleibt beim ersten Fehler stehen. Ein Schema, dessen
     * Zugang noch offen ist, ist ein Weg zu Daten; ein Zugang auf ein Schema,
     * das schon fort ist, ist ein Zugang auf nichts — die Reihenfolge aus
     * `docs/36 §22.3e`, hier für den Abbruch mitten im Lauf.
     */
    public function test_the_schema_falls_last(): void
    {
        $statements = DbDatabaseRemove::statements(
            'p1000_shop',
            [['p1000_web', 'localhost']],
            [['p1000_app', 'localhost']],
        );

        $drop = array_keys(array_filter(
            $statements,
            static fn (string $s): bool => str_starts_with($s, 'DROP DATABASE'),
        ));

        $this->assertSame([count($statements) - 1], $drop);
    }

    /**
     * Und beim Rückbau geht jeder Zugang mit — auch der an zwei Datenbanken.
     *
     * **Der Fund aus dem Abnahmelauf von P5b, Punkt 9.** Nach dem Rückbau von
     * `cloudlab24.de` stand `x45c97683d84c369c_web` noch im Cluster, während
     * Datenbanken, Sicherungen und Eigentümerrolle fort waren. Der Vorgang
     * meldete „fertig"; gefunden hat es `srvpanel db`.
     *
     * Die Ursache ist ein Zeitpunkt: `removeAllFor()` reiht **alle**
     * Datenbanken auf einmal ein, und jeder Vorgang berechnet seine Listen
     * beim Einreihen — also während die anderen noch dastehen. Ein Zugang an
     * zwei Datenbanken zählt damit zweimal als „hängt noch woanders".
     *
     * > **Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt
     * > die anderen Vorgänge derselben Reihe nicht.**
     *
     * Geprüft wird an **beiden** Aufträgen: Die Rolle darf in keinem fehlen.
     * Dass sie zweimal genannt wird, ist kein Fehler — der Agent entfernt sie
     * mit `IF EXISTS`, und ein zweiter Anlauf trifft ohnehin auf denselben
     * Zustand.
     */
    public function test_withdrawing_takes_every_access_with_it(): void
    {
        [$subscription, $shop, $blog] = $this->twoDatabases();

        app(Tenancy::class)->withoutRestriction(function () use ($subscription, $shop, $blog): void {
            DbUser::factory()->forSubscription($subscription, 'web')->create()
                ->databases()->attach([$shop->id, $blog->id]);
        });

        Queue::fake();

        app(Databases::class)->removeAllFor($subscription);

        $operations = app(Tenancy::class)->withoutRestriction(
            fn (): array => Operation::query()->where('type', 'db.database.remove')->orderBy('id')->get()->all(),
        );

        $this->assertCount(2, $operations, 'Es wurden nicht beide Datenbanken eingereiht.');

        foreach ($operations as $operation) {
            $payload = (array) $operation->payload;

            $this->assertContains('p1000_web', $this->names($payload['users'] ?? []), sprintf(
                "Der Auftrag für %s nimmt den Zugang nicht mit.\n\n"
                .'Beim Rückbau verschwindet jede Datenbank dieses Abonnements — die Frage, ob der '
                .'Zugang noch an einer anderen hängt, hat dann keinen Gegenstand mehr. Wird sie '
                .'trotzdem gestellt, bleibt die Rolle im Cluster stehen, und der Vorgang meldet '
                .'„fertig".',
                (string) ($payload['name'] ?? '?'),
            ));
        }
    }
}
