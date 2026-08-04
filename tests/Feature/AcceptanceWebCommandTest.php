<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\AcceptanceWeb;
use App\Enums\OperationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Der Abnahmelauf für P3 — die Teile, die sich ohne Server prüfen lassen.
 *
 * **Warum es diesen Test gibt.** Der erste Lauf auf dem Zielserver ist an
 * einer Stelle gescheitert, die kein Test berührt hat, mit einer Meldung, die
 * von etwas ganz anderem sprach:
 *
 *     Das Abonnement ist wird angelegt — daran lässt sich nichts ändern.
 *
 * Zwei Fehler in einem Satz. Der sichtbare war das Deutsch — den prüft jetzt
 * `WordChoiceTest`. Der teure stand dahinter: Das Warten auf die Vorgänge
 * hielt nur die Vorgangszeilen im Blick und fasste die übergebenen Modelle
 * nie an. Sie trugen weiter `Provisioning` aus dem `create()`, und
 * `Domains::create()` prüft am übergebenen Objekt. Der Lauf konnte damit nie
 * gelingen — kein Zufall, kein Wettlauf, sondern jedes Mal.
 *
 * Dass er trotzdem geschrieben, gelesen und abgenommen wurde, liegt daran,
 * dass ihn nichts fahren konnte: Er braucht nginx, PHP-FPM und einen Agenten.
 * Was sich **ohne** all das prüfen lässt, steht ab hier.
 */
final class AcceptanceWebCommandTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(SubscriptionStatus $status): Subscription
    {
        $customer = Customer::factory()->create();
        $plan = Plan::query()->first() ?? Plan::factory()->create();

        return Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => $status,
        ]);
    }

    /**
     * `await()` ist privat — geprüft wird seine Wirkung.
     *
     * Dieselbe Machart wie in `SubscriptionCleanupTest`: Was der Lauf tut,
     * hängt nicht an der Sichtbarkeit der Methode.
     *
     * @param  list<Subscription>  $subscriptions
     */
    private function await(array $subscriptions, int $timeout = 5): bool
    {
        $method = new ReflectionMethod(AcceptanceWeb::class, 'await');

        // **Ohne Klammer, wie im Kommando.** `handle()` ruft `perform()` in
        // `withoutRestriction()`; ohne das hier sähe die Abfrage keine
        // einzige Vorgangszeile, `await()` fände nichts Offenes und meldete
        // Erfolg — der Test bestünde, ohne etwas geprüft zu haben. Beim
        // ersten Lauf ist genau das passiert.
        return (bool) app(Tenancy::class)->withoutRestriction(
            fn (): bool => (bool) $method->invoke(app(AcceptanceWeb::class), $subscriptions, $timeout),
        );
    }

    /**
     * Der Fehler vom Server, als Test.
     *
     * Das Modell trägt `Provisioning`, die Datenbank sagt längst `Active` —
     * genau die Lage nach einem durchgelaufenen `subscription.provision`.
     * Wer danach nicht auffrischt, reicht ein Abonnement weiter, das es so
     * nicht mehr gibt.
     */
    public function test_waiting_brings_the_model_up_to_date(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Provisioning);

        Operation::factory()->create([
            'subscription_id' => $subscription->id,
            'status' => OperationStatus::Succeeded,
        ]);

        // Hinter dem Rücken des Modells — so wie der Arbeiter es tut.
        Subscription::query()->withoutGlobalScopes()
            ->whereKey($subscription->id)
            ->update(['status' => SubscriptionStatus::Active->value]);

        $this->assertTrue($this->await([$subscription]));

        $this->assertSame(SubscriptionStatus::Active, $subscription->status, implode(' ', [
            'Nach dem Warten trägt das Modell noch den Zustand von vorher.',
            'Domains::create() prüft am übergebenen Objekt und weist ab —',
            'genau daran ist der erste Lauf auf dem Server gescheitert.',
        ]));
    }

    /**
     * Und das Fenster zwischen „Vorgang erledigt" und „Abonnement aktiv".
     *
     * `RunAgentOperation` schreibt erst den Vorgang fort und ruft **danach**
     * `afterSuccess()`. Dazwischen ist kein Vorgang mehr offen, während das
     * Abonnement noch angelegt wird. Ein einmaliges Nachsehen fiele hier
     * hinein; gewartet wird deshalb, bis der Zustand da ist.
     */
    public function test_waiting_does_not_end_while_the_subscription_is_still_being_created(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Provisioning);

        Operation::factory()->create([
            'subscription_id' => $subscription->id,
            'status' => OperationStatus::Succeeded,
        ]);

        // Kein offener Vorgang, und trotzdem ist das Abonnement nicht fertig.
        $this->assertFalse($this->await([$subscription], 1), implode(' ', [
            'Das Warten endet, obwohl das Abonnement noch angelegt wird.',
            'Der Vorgang wird vor dem Zustand fortgeschrieben; dieses Fenster',
            'ist der Grund, warum hier gewartet und nicht nachgesehen wird.',
        ]));
    }

    /** Ein fehlgeschlagener Vorgang bleibt ein Fehlschlag. */
    public function test_a_failed_operation_ends_the_wait(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Active);

        Operation::factory()->create([
            'subscription_id' => $subscription->id,
            'status' => OperationStatus::Failed,
        ]);

        $this->assertFalse($this->await([$subscription]));
    }

    /** Ist alles durch und der Zustand gesetzt, endet das Warten sofort. */
    public function test_a_finished_subscription_ends_the_wait(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Active);

        Operation::factory()->create([
            'subscription_id' => $subscription->id,
            'status' => OperationStatus::Succeeded,
        ]);

        $this->assertTrue($this->await([$subscription]));
    }

    /**
     * Der Rückbau läuft auch dann, wenn etwas dazwischen scheitert.
     *
     * **Eine Prüfung am Quelltext, und das ist Absicht** — dieselbe Machart
     * wie in `WebIsolationProbeTest`. `perform()` legt Systembenutzer an und
     * ruft den Agenten; in diesem Container läuft es nicht.
     *
     * Was sich ohne all das feststellen lässt, ist die Frage, an der der erste
     * Serverlauf hängen blieb: Der Rückbau stand hinter der Probe, in gerader
     * Linie. Die Ausnahme aus `Domains::create()` sprang darüber hinweg, und
     * auf dem Server blieben zwei Abonnements samt `useradd`,
     * Verzeichnisbaum, Server-Blöcken und FPM-Pools liegen — nach einem
     * Kommando, dessen ganze Zusage lautet, hinterher aufzuräumen.
     */
    public function test_the_teardown_runs_even_when_something_fails(): void
    {
        $method = new ReflectionMethod(AcceptanceWeb::class, 'perform');
        $file = (string) $method->getFileName();

        $source = implode("\n", array_slice(
            file($file) ?: [],
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringContainsString('} finally {', $source, implode(' ', [
            'Der Abnahmelauf hat kein finally.',
            'Ein Fehlschlag zwischen Anlegen und Probe lässt zwei Abonnements',
            'samt Systembenutzern und Verzeichnissen auf dem Server stehen.',
        ]));

        $finally = substr($source, (int) strpos($source, '} finally {'));

        $this->assertStringContainsString('$this->teardown(', $finally, implode(' ', [
            'Das finally räumt nicht auf.',
            'Der Rückbau muss darin stehen, nicht dahinter.',
        ]));

        /*
         * **Und es beginnt früh genug.**
         *
         * Das `finally` stand zuerst hinter dem Warten. Ein Abonnement, das
         * nicht fertig wird, ist aber gerade der Fall, in dem etwas halb
         * dasteht — `subscription.provision` kann den Systembenutzer angelegt
         * und danach abgebrochen haben. Der zweite Lauf auf dem Zielserver ist
         * genau hier ausgestiegen und hat wieder zwei Abonnements
         * hinterlassen.
         */
        $this->assertLessThan(
            (int) strpos($source, '$this->await('),
            (int) strpos($source, 'try {'),
            implode(' ', [
                'Das Warten steht ausserhalb des try.',
                'Werden die Abonnements nicht fertig, räumt niemand auf —',
                'und gerade dann steht auf dem Server etwas halb da.',
            ]),
        );
    }

    /**
     * Ein Fehlschlag sagt, woran er lag.
     *
     * „Die Abonnements sind nicht fertig geworden" stand auf dem Zielserver
     * allein da. Der Betreiber hatte damit nichts in der Hand: Ein
     * gescheiterter Vorgang trägt seine Begründung in der Datenbank, ein
     * hängender trägt seinen Zustand, und beides sagt etwas völlig anderes
     * über die Ursache.
     */
    public function test_the_failure_says_what_went_wrong(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Provisioning);

        Operation::factory()->create([
            'subscription_id' => $subscription->id,
            'type' => 'subscription.provision',
            'status' => OperationStatus::Failed,
            'message' => 'useradd: UID 1000 wird bereits benutzt',
        ]);

        $command = app(AcceptanceWeb::class);
        $buffer = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $method = new ReflectionMethod(AcceptanceWeb::class, 'explainWhyNothingFinished');

        app(Tenancy::class)->withoutRestriction(fn () => $method->invoke(
            $command,
            [['subscription' => $subscription, 'version' => '8.4']],
        ));

        $ausgabe = $buffer->fetch();

        $this->assertStringContainsString('subscription.provision', $ausgabe, 'Der gescheiterte Vorgang wird nicht genannt.');
        $this->assertStringContainsString('UID 1000 wird bereits benutzt', $ausgabe, implode(' ', [
            'Die Begründung des Agenten steht in der Datenbank und nicht in der Ausgabe.',
            'Genau sie ist das, was der Betreiber braucht.',
        ]));
        $this->assertStringContainsString($subscription->name, $ausgabe);
    }

    /**
     * Die Selbstprobe geht in **jedes** Verzeichnis, aus dem ausgeliefert wird.
     *
     * **Der Fehler auf dem Zielserver.** Der Lauf legte eine einzige Probe ab,
     * und der Agent schrieb sie nach `httpdocs` — dem DocumentRoot der
     * Hauptdomain. Jede Zusatzdomain liefert aus einem Verzeichnis mit ihrem
     * eigenen Namen aus. Für vier von sechs Domains lag die Probe damit am
     * falschen Ort; nginx antwortete mit 404.
     *
     * Beide Hauptdomains bestanden, alle vier Zusatzdomains fielen durch — und
     * die Meldung lautete „antwortet nicht", als ginge es um die Abschottung.
     */
    public function test_the_probe_goes_into_every_document_root(): void
    {
        $subscription = $this->subscription(SubscriptionStatus::Active);

        $haupt = Domain::factory()->main()->for($subscription)->create([
            'name' => 'beispiel.de',
            'document_root' => 'httpdocs',
        ]);

        $zusatz = Domain::factory()->for($subscription)->create([
            'name' => 'eins-beispiel.de',
            'document_root' => 'eins-beispiel.de',
        ]);

        // Ein Alias liefert nichts aus und hat kein Verzeichnis.
        $alias = Domain::factory()->alias($haupt)->create();

        $method = new ReflectionMethod(AcceptanceWeb::class, 'documentRootsOf');

        /** @var list<string> $roots */
        $roots = $method->invoke(app(AcceptanceWeb::class), [$haupt, $zusatz, $alias]);

        sort($roots);

        $this->assertSame(['eins-beispiel.de', 'httpdocs'], $roots, implode(' ', [
            'Die Verzeichnisse stimmen nicht.',
            'Fehlt das der Zusatzdomain, liegt die Selbstprobe für sie am falschen Ort',
            'und nginx antwortet mit 404 — genau der Fehlschlag vom Zielserver.',
        ]));
    }

    /**
     * „Antwortet nicht" war keine Diagnose.
     *
     * Der Satz warf vier Lagen in einen Topf: keine Verbindung, ein
     * HTTP-Fehler, eine fremde Seite, ungültiges JSON. Jede hat eine andere
     * Ursache und einen anderen nächsten Schritt. Beim Lauf auf dem Zielserver
     * war es ein 404 — und die Meldung las sich wie ein Befund über die
     * Abschottung.
     */
    public function test_the_status_line_is_read_exactly(): void
    {
        $method = new ReflectionMethod(AcceptanceWeb::class, 'statusFrom');
        $command = app(AcceptanceWeb::class);

        $this->assertSame(200, $method->invoke($command, ['HTTP/1.1 200 OK', 'Content-Type: application/json']));
        $this->assertSame(404, $method->invoke($command, ['HTTP/1.1 404 Not Found']));
        $this->assertSame(502, $method->invoke($command, ['HTTP/2 502 Bad Gateway']));

        // Ohne Antwort gibt es keine Statuszeile — das muss unterscheidbar
        // bleiben von einem echten Status.
        $this->assertSame(0, $method->invoke($command, []));
        $this->assertSame(0, $method->invoke($command, ['Content-Type: text/html']));

        // Bei einer Weiterleitung zählt die letzte Zeile.
        $this->assertSame(200, $method->invoke($command, ['HTTP/1.1 301 Moved', 'HTTP/1.1 200 OK']));
    }

    /**
     * Und der Ausschnitt zeigt, *was* stattdessen kam.
     *
     * Eine nginx-Fehlerseite sieht anders aus als ein PHP-Skript, das im
     * Klartext ausgeliefert wird — das eine heisst „falscher Ort", das andere
     * „PHP läuft hier nicht". Ohne den Ausschnitt sind beide „kein JSON".
     */
    public function test_the_excerpt_shows_what_came_instead(): void
    {
        $method = new ReflectionMethod(AcceptanceWeb::class, 'excerpt');
        $command = app(AcceptanceWeb::class);

        $nginx = (string) $method->invoke($command, "<html>\n<head><title>404 Not Found</title></head>\n<body>…</body>\n</html>");

        $this->assertStringContainsString('404 Not Found', $nginx);
        $this->assertStringNotContainsString("\n", $nginx, 'Der Ausschnitt muss eine Zeile bleiben.');

        $this->assertStringContainsString('(leerer Körper)', (string) $method->invoke($command, ''));

        // Lang genug, um gekürzt zu werden — die Meldung darf nicht fluten.
        $lang = (string) $method->invoke($command, str_repeat('abcdefghij', 30));

        $this->assertLessThan(120, mb_strlen($lang));
        $this->assertStringContainsString('…', $lang);
    }

    /**
     * Und die Meldung, mit der alles anfing, liest sich als Satz.
     *
     * Für jeden Zustand, nicht nur für den einen, an dem es aufgefallen ist.
     */
    public function test_every_status_reads_as_a_sentence(): void
    {
        $erwartet = [
            'Provisioning' => 'Das Abonnement wird gerade angelegt.',
            'Active' => 'Das Abonnement ist aktiv.',
            'Suspended' => 'Das Abonnement ist gesperrt.',
            'Cancelled' => 'Das Abonnement ist gekündigt.',
        ];

        foreach (SubscriptionStatus::cases() as $status) {
            $satz = sprintf('Das Abonnement %s.', $status->sentence());

            $this->assertSame($erwartet[$status->name], $satz);

            // Und die Gegenprobe gegen die doppelten Verben, die aus einer
            // Beschriftung im Satzrahmen entstehen.
            $this->assertDoesNotMatchRegularExpression(
                '/\b(ist|wird|war)\s+(ist|wird|war)\b/',
                $satz,
                sprintf('„%s" hat zwei Verben hintereinander.', $satz),
            );
        }
    }
}
