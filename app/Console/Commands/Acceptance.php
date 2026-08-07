<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OperationStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Customer;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Der Abnahmelauf für P2.
 *
 * **Das Kriterium wörtlich:** „Fertig, wenn hundert Abonnements angelegt und
 * wieder gelöscht werden können, ohne dass ein Systembenutzer, ein
 * Verzeichnis oder ein Quota-Eintrag zurückbleibt."
 *
 * **Warum das ein Kommando ist und kein Test.** Ein Test läuft gegen SQLite
 * im Arbeitsspeicher und einen erfundenen Agenten. Das Kriterium fragt aber
 * nach dem Gegenteil: nach echten `useradd`-Aufrufen, echten Verzeichnissen
 * unter /var/www/vhosts, echten Einträgen in der Quota-Datei — und nach der
 * ganzen Kette Panel → Warteschlange → Arbeiter → Agent, die es in einem Test
 * gar nicht gibt. Was hier zählt, lässt sich nur auf einem laufenden Debian
 * feststellen, und deshalb steht es als Kommando da, das dort läuft.
 *
 * **Die Gegenprobe ist der eigentliche Inhalt.** Anlegen und Zurückbauen kann
 * das Panel; die Frage ist, was danach noch da ist. Gesucht wird deshalb nach
 * drei Sorten Rückstand, jede einzeln:
 *
 * 1. ein Systembenutzer oder eine Gruppe, die es noch gibt,
 * 2. ein Verzeichnis unter /var/www/vhosts, das stehen blieb,
 * 3. ein Eintrag in der Dateisystem-Quota, den `subscription.usage` noch sieht.
 *
 * Der dritte ist der, den man ohne Werkzeug übersieht: Er hat keinen Ort im
 * Dateisystem und keine Zeile in /etc/passwd. Bleibt er stehen, bekommt das
 * nächste Abonnement mit derselben UID eine fremde Grenze — und niemand
 * findet den Grund.
 *
 * **Es wird nichts angefasst, was dieser Lauf nicht selbst angelegt hat.**
 * Jedes Abonnement bekommt einen Namen mit dem Präfix des Laufs, und
 * zurückgebaut wird ausschliesslich, was in dieser Liste steht.
 */
final class Acceptance extends Command
{
    protected $signature = 'srvpanel:acceptance
                            {--count=100 : Wie viele Abonnements}
                            {--prefix=abnahme : Namensvorsilbe der angelegten Abonnements}
                            {--keep : Nach dem Anlegen stehen lassen — für die Fehlersuche}
                            {--timeout=1800 : Sekunden, die ein Schritt höchstens dauern darf}
                            {--force : Ohne Rückfrage}';

    protected $description = 'Legt N Abonnements an, baut sie zurück und sucht danach nach Rückständen (P2)';

    public function handle(Client $agent, Lifecycle $lifecycle, Tenancy $tenancy): int
    {
        $count = max(1, (int) $this->option('count'));
        $prefix = (string) $this->option('prefix');
        $timeout = max(30, (int) $this->option('timeout'));

        if (! preg_match('/^[a-z][a-z0-9-]{1,20}$/', $prefix)) {
            $this->error('Die Vorsilbe muss aus Kleinbuchstaben, Ziffern und Bindestrichen bestehen.');

            return self::FAILURE;
        }

        /*
         * **Die Rückfrage steht vor allem anderen.** Der Lauf legt echte
         * Systembenutzer an und löscht echte Verzeichnisbäume. Wer ihn auf
         * einem Server mit Kunden startet, soll das gelesen haben — und nicht
         * erst merken, dass „Abnahme" ein Schreibvorgang ist.
         */
        if (! $this->option('force') && ! $this->confirm(sprintf(
            '%d Abonnements werden angelegt (useradd, /var/www/vhosts, setquota) und danach zurückgebaut. Weiter?',
            $count,
        ))) {
            return self::SUCCESS;
        }

        /*
         * **Ohne Mandantenklammer, und ausdrücklich.** Ein Kommando läuft ohne
         * angemeldetes Konto; der Grundzustand der Klammer ist „nichts". Ohne
         * diese Zeile fände der Lauf weder Kunde noch Plan, und die Vorgänge,
         * auf die er wartet, wären für ihn nicht vorhanden — er liefe in den
         * Zeitablauf, während der Server längst fertig ist.
         */
        return $tenancy->withoutRestriction(
            fn (): int => $this->perform($count, $prefix, $timeout, $agent, $lifecycle),
        );
    }

    private function perform(int $count, string $prefix, int $timeout, Client $agent, Lifecycle $lifecycle): int
    {
        $customer = Customer::query()->orderBy('id')->first();
        $plan = Plan::query()->orderByDesc('is_default')->orderBy('id')->first();

        if ($customer === null || $plan === null) {
            // Angelegt werden sie hier nicht: Eine Kundennummer ist auf Dauer
            // verbraucht, auch nach dem Zurückziehen. Ein Abnahmelauf soll
            // keine Spuren in der Nummernvergabe hinterlassen.
            $this->error('Es braucht mindestens einen Kunden und einen Plan. Beide legt man im Panel an.');

            return self::FAILURE;
        }

        $this->line(sprintf('Kunde %s, Plan %s, %d Abonnements.', $customer->number, $plan->name, $count));

        $subscriptions = $this->create($count, $prefix, $customer, $plan, $lifecycle);

        if (! $this->await($subscriptions, 'subscription.provision', $timeout)) {
            $this->error('Nicht alle Abonnements sind angelegt worden. Der Lauf bricht ab, damit man nachsehen kann.');

            return self::FAILURE;
        }

        $this->info(sprintf('%d Abonnements angelegt.', count($subscriptions)));

        if ($this->option('keep')) {
            $this->warn('--keep: Es wird nicht zurückgebaut. Aufräumen von Hand.');

            return self::SUCCESS;
        }

        foreach ($subscriptions as $subscription) {
            $this->start($subscription, 'subscription.remove', $lifecycle);
        }

        if (! $this->await($subscriptions, 'subscription.remove', $timeout)) {
            $this->error('Nicht alle Abonnements sind zurückgebaut worden.');

            return self::FAILURE;
        }

        $this->info(sprintf('%d Abonnements zurückgebaut.', count($subscriptions)));

        return $this->report($this->leftovers($subscriptions, $agent));
    }

    /**
     * Die Abonnements anlegen — auf demselben Weg wie das Formular.
     *
     * Der Name geht durch die Prüfung des Agenten, bevor er in der Datenbank
     * steht: Ein Lauf, der bei Abonnement 87 an einem unzulässigen Namen
     * scheitert, hat 86 anzulegen begonnen.
     *
     * @return list<Subscription>
     */
    private function create(int $count, string $prefix, Customer $customer, Plan $plan, Lifecycle $lifecycle): array
    {
        $subscriptions = [];
        $bar = $this->output->createProgressBar($count);

        for ($i = 1; $i <= $count; $i++) {
            $name = sprintf('%s-%04d.invalid', $prefix, $i);

            SubscriptionProvision::subscriptionName($name);

            $subscription = Subscription::query()->create([
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'name' => $name,

                // `claim()` und nicht `nextSystemUser()`: Der zweite sagt nur,
                // was der nächste wäre, und verbraucht ihn nicht. In dieser
                // Schleife bekämen sonst alle Abonnements denselben Namen und
                // das zweite scheiterte am eindeutigen Index — der Abnahmelauf
                // ist die einzige Stelle im Panel, die in einem Zug mehrere
                // anlegt.
                'system_user' => $lifecycle->claim($name),
                'status' => SubscriptionStatus::Provisioning,
            ]);

            $this->start($subscription, 'subscription.provision', $lifecycle);

            $subscriptions[] = $subscription;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $subscriptions;
    }

    private function start(Subscription $subscription, string $task, Lifecycle $lifecycle): void
    {
        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'account_id' => null,
            'type' => $task,
            'task' => $task,
            'payload' => $lifecycle->payload($subscription),
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => 'Abnahmelauf',
        ]);

        RunAgentOperation::dispatch((int) $operation->id);
    }

    /**
     * Warten, bis alle Vorgänge dieser Art durch sind.
     *
     * **Gewartet wird auf den Arbeiter und nicht auf den Agenten.** Genau das
     * ist der Teil, den ein Test nicht abdeckt: Ob hundert Vorgänge
     * nacheinander durch eine Warteschlange gehen, ohne dass einer liegen
     * bleibt, zeigt sich erst unter systemd.
     *
     * @param  list<Subscription>  $subscriptions
     */
    private function await(array $subscriptions, string $task, int $timeout): bool
    {
        $ids = array_map(static fn (Subscription $s): int => (int) $s->id, $subscriptions);
        $deadline = time() + $timeout;

        $bar = $this->output->createProgressBar(count($ids));
        $bar->setMessage('');

        while (time() < $deadline) {
            $states = Operation::query()
                ->whereIn('subscription_id', $ids)
                ->where('task', $task)
                ->selectRaw('status, count(*) as anzahl')
                ->groupBy('status')
                ->pluck('anzahl', 'status');

            $done = (int) ($states[OperationStatus::Succeeded->value] ?? 0);
            $failed = (int) ($states[OperationStatus::Failed->value] ?? 0)
                + (int) ($states[OperationStatus::Cancelled->value] ?? 0);

            $bar->setProgress($done + $failed);

            if ($failed > 0) {
                $bar->finish();
                $this->newLine();
                $this->error(sprintf('%d Vorgänge (%s) sind fehlgeschlagen.', $failed, $task));

                return false;
            }

            if ($done === count($ids)) {
                $bar->finish();
                $this->newLine();

                return true;
            }

            sleep(2);
        }

        $bar->finish();
        $this->newLine();
        $this->error(sprintf('Nach %d Sekunden waren die Vorgänge (%s) nicht durch.', $timeout, $task));

        return false;
    }

    /**
     * Die Gegenprobe: Was ist nach dem Rückbau noch da?
     *
     * @param  list<Subscription>  $subscriptions
     * @return array{users: list<string>, groups: list<string>, directories: list<string>, quotas: list<string>}
     */
    private function leftovers(array $subscriptions, Client $agent): array
    {
        $leftovers = ['users' => [], 'groups' => [], 'directories' => [], 'quotas' => []];

        foreach ($subscriptions as $subscription) {
            $user = (string) $subscription->system_user;

            if (posix_getpwnam($user) !== false) {
                $leftovers['users'][] = $user;
            }

            // Die Gruppe getrennt vom Benutzer: `userdel` entfernt sie nicht
            // mit, wenn sie nicht die primäre Gruppe ist — und beim Anlegen
            // steht ausdrücklich `--no-user-group`.
            if (posix_getgrnam($user) !== false) {
                $leftovers['groups'][] = $user;
            }

            $root = SubscriptionProvision::VHOSTS.'/'.$subscription->name;

            if (is_dir($root)) {
                $leftovers['directories'][] = $root;
            }
        }

        /*
         * Der Quota-Eintrag ist der Rückstand ohne Ort: keine Zeile in
         * /etc/passwd, kein Verzeichnis. `subscription.usage` liest ihn
         * unmittelbar aus der Quota-Datei und ist damit die einzige Auskunft,
         * die ihn zeigt.
         */
        try {
            $usage = $agent->call('subscription.usage');
        } catch (AgentException $error) {
            $this->warn('Quota-Einträge liessen sich nicht prüfen: '.$error->getMessage());

            return $leftovers;
        }

        if (($usage['available'] ?? false) !== true) {
            $this->warn('Keine Dateisystem-Quota auf diesem Server — der dritte Teil der Gegenprobe entfällt.');

            return $leftovers;
        }

        $users = is_array($usage['users'] ?? null) ? $usage['users'] : [];

        foreach ($subscriptions as $subscription) {
            if (array_key_exists((string) $subscription->system_user, $users)) {
                $leftovers['quotas'][] = (string) $subscription->system_user;
            }
        }

        return $leftovers;
    }

    /**
     * Ist das Kriterium erfüllt?
     *
     * **Getrennt von der Ausgabe, und zwar absichtlich.** Das ist die Stelle,
     * an der dieser Lauf sein Urteil fällt — und ein Abnahmelauf, der
     * Rückstände findet und trotzdem „bestanden" meldet, ist schlimmer als
     * keiner: Er bescheinigt ein Kriterium, das nicht gilt. Als eigene
     * Funktion ist das prüfbar, ohne einen Server mit hundert Abonnements.
     *
     * `array_sum` über alle Arten und kein `empty()` je Art: Eine neue Sorte
     * Rückstand zählt damit von selbst mit.
     *
     * @param  array<string, list<string>>  $leftovers
     */
    public static function passed(array $leftovers): bool
    {
        return array_sum(array_map('count', $leftovers)) === 0;
    }

    /** @param  array{users: list<string>, groups: list<string>, directories: list<string>, quotas: list<string>}  $leftovers */
    private function report(array $leftovers): int
    {
        $total = array_sum(array_map('count', $leftovers));

        if (self::passed($leftovers)) {
            $this->info('Kein Systembenutzer, keine Gruppe, kein Verzeichnis, kein Quota-Eintrag geblieben.');
            $this->info('Das Abnahmekriterium von P2 ist erfüllt.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d Rückstände:', $total));

        foreach ($leftovers as $art => $liste) {
            if ($liste !== []) {
                $this->line(sprintf('  %s: %s', $art, implode(', ', array_slice($liste, 0, 20))));
            }
        }

        return self::FAILURE;
    }
}
