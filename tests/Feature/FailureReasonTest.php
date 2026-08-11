<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Operations\OperationRecorder;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Die Begründung eines Fehlschlags kommt an — und sie wird nicht geraten.
 *
 * **Beides zusammen, weil beides zusammen versagt hat.** Im Abnahmelauf von
 * P5b (Punkt 8, `docs/38 §19`) wies der Agent einen hochgeladenen Dump ab, der
 * `ALTER ROLE … SUPERUSER` erzwingen wollte, und begründete es genau so, wie es
 * das Kriterium verlangt — mit der abgewiesenen Anweisung und ihrer
 * Zeilennummer. Im Panel stand davon nichts. Dort stand:
 *
 *     Der Vorgang wurde von der Warteschlange abgebrochen —
 *     vermutlich Zeitüberschreitung.
 *
 * an einem Vorgang, der **eine Sekunde** lief.
 *
 * Die Kette: `operations.message` war `varchar(255)`, die Begründung 260
 * Zeichen lang. `OperationRecorder::fail()` schrieb sie, MariaDB wies sie ab,
 * und die `PDOException` flog aus genau dem `catch`-Zweig heraus, der den
 * Fehlschlag festhalten sollte. Der Auftrag starb, und der letzte Halt —
 * {@see RunAgentOperation::failed()} — schrieb seine Vermutung darüber.
 *
 * > **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**
 *
 * > **Ein Fehlertext, der eine Ursache rät, ist schlimmer als einer, der keine
 * > nennt — er beendet die Suche.**
 *
 * Und die Pointe, die diesen Fehler so lange verborgen hat: **Je wichtiger die
 * Begründung, desto länger ist sie.** „Datei nicht gefunden" passte immer in
 * die Spalte. Die einzige Auskunft, an der ein Abnahmekriterium hing, passte
 * nie.
 */
final class FailureReasonTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Jeder Vorgang bekommt sein eigenes Abonnement.
     *
     * **Der Zähler ist kein Zierat.** `subscriptions.system_user` ist eindeutig
     * — ein zweiter Aufruf mit demselben Namen bricht mit einer
     * `UniqueConstraintViolationException` ab, und der Test daneben sieht aus
     * wie ein Fehler in der Sache. Beim ersten Lauf genau so passiert.
     */
    private int $nummer = 1088;

    private function operation(): Operation
    {
        $nummer = $this->nummer++;

        return app(Tenancy::class)->withoutRestriction(function () use ($nummer): Operation {
            $subscription = Subscription::factory()->create([
                'name' => sprintf('grund-%d.de', $nummer),
                'system_user' => 'p'.$nummer,
            ]);

            return Operation::query()->create([
                'subscription_id' => $subscription->id,
                'account_id' => null,
                'type' => 'pg.restore',
                'task' => 'pg.restore',
                'status' => OperationStatus::Running,
                'progress' => 45,
            ]);
        });
    }

    private function messageOf(Operation $operation): string
    {
        return (string) app(Tenancy::class)->withoutRestriction(
            fn (): ?string => Operation::query()->find($operation->id)?->message,
        );
    }

    /**
     * Die Spalte fasst mehr als eine Zeile Text — geprüft am Schema.
     *
     * **Und das ist keine Umständlichkeit, sondern der einzige Weg.** Diese
     * Tests laufen gegen SQLite im Speicher (`phpunit.xml`), der Server gegen
     * MariaDB. SQLite hält sich nicht an `varchar(255)`: Es legt jede Länge in
     * eine so deklarierte Spalte. Ein Verhaltenstest — schreiben, zurücklesen,
     * vergleichen — wäre hier grün gewesen, mit der alten Spalte genauso wie
     * mit der neuen.
     *
     * > **Ein Test, der gegen eine andere Datenbank läuft als der Server,
     * > prüft die Grenzen der falschen.**
     *
     * Genau daran ist dieser Fehler zwei Jahre lang vorbeigekommen: 1647 Tests,
     * alle grün, und keiner konnte die Länge der Spalte sehen. Gefunden hat ihn
     * ein Abnahmelauf auf einem echten Server.
     */
    public function test_the_column_is_wide_enough_for_a_real_reason(): void
    {
        $columns = collect(Schema::getColumns('operations'))
            ->firstWhere('name', 'message');

        $this->assertNotNull($columns, 'Die Spalte operations.message gibt es nicht mehr.');

        $this->assertStringContainsString('text', strtolower((string) ($columns['type_name'] ?? '')), sprintf(
            "operations.message ist wieder eine Spalte fester Länge (%s).\n\n"
            .'Auf MariaDB weist sie jede Begründung über 255 Zeichen ab — und die Ausnahme dafür '
            .'fliegt aus genau dem catch-Zweig heraus, der den Fehlschlag festhalten soll. Das '
            ."kostet nicht die Meldung, sondern den ganzen Fehlerweg.\n\n"
            .'Auf SQLite fällt das nicht auf: Dort passt in eine varchar(255) jede Länge. Deshalb '
            .'steht hier das Schema und nicht ein Schreibversuch.',
            (string) ($columns['type_name'] ?? '?'),
        ));
    }

    /**
     * Und die echte Begründung des Agenten kommt am Vorgang an.
     *
     * Die Meldung hier ist die aus dem Abnahmelauf, Wort für Wort. Der Test
     * prüft den Weg durch {@see OperationRecorder} — die Breite der Spalte
     * prüft der Test darüber, und beide zusammen sind die Kette.
     */
    public function test_the_reason_from_the_agent_reaches_the_operation(): void
    {
        $operation = $this->operation();

        $reason = 'Das Zurückspielen ist gescheitert: psql:/var/lib/srvpanel/dumps/cloudlab24.de/'
            .'.x45c97683d84c369c-shop-20260811-064504-0f9e1f99.restore.sql:74: ERROR:  permission '
            ."denied to alter role\nDETAIL:  Only roles with the SUPERUSER attribute may change the "
            .'SUPERUSER attribute.';

        $this->assertGreaterThan(255, strlen($reason), 'Der Fall dieses Tests ist die Länge.');

        (new OperationRecorder($operation))->fail($reason);

        $this->assertSame($reason, $this->messageOf($operation), sprintf(
            "Die Begründung des Agenten kommt nicht mehr an (%d Zeichen).\n\n"
            .'Sie ist die einzige Auskunft darüber, WAS abgewiesen wurde — ohne sie meldet das '
            .'Panel einen Fehlschlag und nichts über den Server.',
            strlen($reason),
        ));

        $this->assertSame(OperationStatus::Failed, Operation::query()
            ->withoutGlobalScopes()->find($operation->id)?->status);
    }

    /**
     * Und eine Begründung ohne Ende bringt den Fehlerweg nicht um.
     *
     * **Die zweite Sicherung, und sie ist nicht überflüssig.** Die Spalte fasst
     * jetzt 65535 Byte — was ein Agent an Ausgabe eines fehlgeschlagenen
     * Kommandos durchreicht, hat keine Grenze, die dieses Panel kennt. Ohne
     * eigene Grenze wäre der Fehler nur an eine grössere Zahl gewandert.
     */
    public function test_an_endless_reason_is_shortened_and_says_so(): void
    {
        $operation = $this->operation();

        (new OperationRecorder($operation))->fail('Der Anfang. '.str_repeat('x', 200_000));

        $stored = $this->messageOf($operation);

        $this->assertStringStartsWith('Der Anfang. ', $stored);
        $this->assertLessThanOrEqual(OperationRecorder::MESSAGE_MAX, strlen($stored));

        // Eine Begründung, die still endet, sieht aus wie eine vollständige.
        $this->assertStringContainsString('gekürzt', $stored);
    }

    /**
     * Und der letzte Halt rät keine Ursache.
     *
     * Geprüft wird beides: dass die Vermutung weg ist, und dass ein Zeitlimit
     * weiterhin als solches benannt wird — an der Klasse der Ausnahme, die
     * Laravel übergibt, und nicht an einer Annahme.
     */
    public function test_the_queue_handler_names_what_it_knows(): void
    {
        $unknown = $this->operation();

        (new RunAgentOperation((int) $unknown->id))->failed(new RuntimeException('irgendetwas'));

        $this->assertStringNotContainsString('vermutlich', $this->messageOf($unknown), sprintf(
            "Der Handler rät wieder eine Ursache.\n\n"
            .'Er weiss nicht, warum der Auftrag starb — er kennt nur die Ausnahme. Eine geratene '
            .'Ursache beendet die Suche: Am 11. August 2026 stand „vermutlich Zeitüberschreitung" '
            .'an einem Vorgang, der eine Sekunde lief.',
        ));

        $timeout = $this->operation();

        (new RunAgentOperation((int) $timeout->id))->failed(new TimeoutExceededException('zu lang'));

        $this->assertStringContainsString('Zeitlimit', $this->messageOf($timeout));
    }
}
