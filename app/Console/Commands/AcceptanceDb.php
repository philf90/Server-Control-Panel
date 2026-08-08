<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Database;
use App\Models\DbUser;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Databases\Databases;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use RuntimeException;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Der Abnahmelauf für P5 — die Mandantentrennung, an einer echten Verbindung.
 *
 * **Das Kriterium wörtlich:** „Fertig, wenn ein Kunde eine Datenbank anlegt,
 * benutzt, sichert und zurückspielt, und ein Datenbankbenutzer nachweislich
 * keine fremde Datenbank sieht."
 *
 * **Warum das ein Kommando ist und kein Test.** Wortgleich die Begründung von
 * `srvpanel acceptance-web`: Ein Test läuft gegen SQLite im Arbeitsspeicher und
 * einen erfundenen Agenten. Das Kriterium fragt nach dem Gegenteil — nach einer
 * Verbindung, die MariaDB abweist. `GrantPatternTest` prüft, dass die erzeugte
 * Zeichenkette richtig maskiert ist; erst dieser Lauf prüft, dass der Server sie
 * so anwendet, wie sie gemeint war.
 *
 * **Er legt keine Abonnements an.** Das ist der Unterschied zu
 * `acceptance-web`, und er ist eine Lehre aus `docs/35`: Eine Kundennummer ist
 * auf Dauer verbraucht, ein Systembenutzer erst recht — `system_users` gibt eine
 * Nummer nie wieder her. Ein Abnahmelauf, den man zehnmal fährt, verbrauchte
 * zwanzig. Er bekommt deshalb **zwei bestehende** Abonnements genannt und legt
 * darin nur an, was sich rückstandsfrei entfernen lässt: zwei Datenbanken, zwei
 * Zugänge, zwei Tabellen.
 *
 * **Und er räumt in jedem Fall auf** — auch wenn ein Kriterium scheitert.
 * Ein Lauf, der bei Kriterium 3 abbricht und die Datenbanken stehenlässt,
 * hinterlässt genau die Reste, die `srvpanel db --prune` danach wegräumen muss.
 */
final class AcceptanceDb extends Command
{
    protected $signature = 'srvpanel:acceptance-db
                            {--a= : Name des ersten Abonnements — es muss bereits bestehen}
                            {--b= : Name des zweiten Abonnements — es muss bereits bestehen}
                            {--keep : Nach dem Lauf stehen lassen — für die Fehlersuche}
                            {--force : Ohne Rückfrage}';

    protected $description = 'Weist die Mandantentrennung der Datenbanken an einer echten Verbindung nach (P5)';

    /** Die Bezeichnung, unter der Datenbank und Zugang angelegt werden. */
    private const LABEL = 'abnahme';

    public function handle(Client $agent, Databases $databases, Tenancy $tenancy): int
    {
        $a = (string) $this->option('a');
        $b = (string) $this->option('b');

        if ($a === '' || $b === '' || $a === $b) {
            $this->error('Es braucht zwei verschiedene, bereits bestehende Abonnements: --a= und --b=.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf(
            'In %s und %s wird je eine Datenbank „%s" mit einem Zugang angelegt und danach entfernt. Weiter?',
            $a,
            $b,
            self::LABEL,
        ))) {
            return self::SUCCESS;
        }

        // Ohne Mandantenklammer: Auf der Kommandozeile ist niemand angemeldet,
        // und der Grundzustand der Klammer ist „nichts".
        return $tenancy->withoutRestriction(fn (): int => $this->perform($a, $b, $agent, $databases));
    }

    private function perform(string $a, string $b, Client $agent, Databases $databases): int
    {
        $first = $this->subscription($a);
        $second = $this->subscription($b);

        if ($first === null || $second === null) {
            return self::FAILURE;
        }

        $created = [];
        $failures = [];

        try {
            $this->line('');
            $this->line('1  ANLEGEN');

            foreach ([$first, $second] as $subscription) {
                $set = $this->provision($subscription, $databases);

                if ($set === null) {
                    return self::FAILURE;
                }

                $created[] = $set;
            }

            $failures = array_merge($failures, $this->checkSecretsStayedOut($created));

            $this->line('');
            $this->line('2  BENUTZEN');
            $failures = array_merge($failures, $this->useEach($created, $agent));

            $this->line('');
            $this->line('3  KEINE FREMDE DATENBANK');
            $failures = array_merge($failures, $this->isolation($created, $agent));
        } finally {
            if ($this->option('keep') === true) {
                $this->warn('--keep: Datenbanken und Zugänge bleiben stehen.');
            } else {
                $this->teardown($created, $databases);
            }
        }

        return $this->report($failures);
    }

    /**
     * Ein Abonnement, das es geben muss.
     *
     * **Es wird nicht angelegt, wenn es fehlt.** Ein Abnahmelauf, der bei einem
     * Tippfehler im Namen ein Abonnement erzeugt, verbraucht einen
     * Systembenutzer für einen Tippfehler.
     */
    private function subscription(string $name): ?Subscription
    {
        $subscription = Subscription::query()->where('name', $name)->first();

        if (! $subscription instanceof Subscription) {
            $this->error(sprintf('Das Abonnement %s gibt es nicht. Der Lauf legt keines an.', $name));

            return null;
        }

        if ($subscription->system_user === null) {
            $this->error(sprintf('%s hat keinen Systembenutzer — es ist nicht fertig eingerichtet.', $name));

            return null;
        }

        return $subscription;
    }

    /**
     * Kriterium 1: anlegen.
     *
     * @return array{subscription: Subscription, database: Database, user: DbUser, password: string}|null
     */
    private function provision(Subscription $subscription, Databases $databases): ?array
    {
        try {
            $database = $databases->create($subscription, self::LABEL, 'utf8mb4_unicode_ci');
            [$user, $password] = $databases->createUser($subscription, self::LABEL, [$database]);
        } catch (AgentException $error) {
            $this->error(sprintf('  %s: %s', $subscription->name, $error->getMessage()));

            return null;
        }

        $this->line(sprintf(
            '  ok  %s: Schema %s, Zugang %s@%s',
            $subscription->name,
            $database->name,
            $user->name,
            $user->host,
        ));

        return [
            'subscription' => $subscription,
            'database' => $database,
            'user' => $user,
            'password' => $password,
        ];
    }

    /**
     * Die Gegenprobe zu Kriterium 1: Das Passwort steht in keiner Nutzlast.
     *
     * **Gesucht wird nach dem Passwort und nicht nach der Zahl der Vorgänge.**
     * docs/36 §17 sagt das ausdrücklich, und der Grund steht in CLAUDE.md: Der
     * teuerste Fund des P4-Abnahmelaufs war eine Meldung mit der richtigen Zahl
     * über der falschen Sache.
     *
     * @param  list<array{subscription: Subscription, database: Database, user: DbUser, password: string}>  $sets
     * @return list<string>
     */
    private function checkSecretsStayedOut(array $sets): array
    {
        $failures = [];

        foreach ($sets as $set) {
            $hits = Operation::query()
                ->where('payload', 'like', '%'.$set['password'].'%')
                ->pluck('id')
                ->all();

            if ($hits === []) {
                $this->line(sprintf('  ok  %s: das Passwort steht in keiner Vorgangsnutzlast', $set['subscription']->name));

                continue;
            }

            $failures[] = sprintf(
                '%s: das Passwort steht in operations.payload — Vorgang %s',
                $set['subscription']->name,
                implode(', ', array_map('strval', $hits)),
            );
        }

        return $failures;
    }

    /**
     * Kriterium 2: benutzen — anlegen, schreiben, lesen unter dem Kundenzugang.
     *
     * @param  list<array{subscription: Subscription, database: Database, user: DbUser, password: string}>  $sets
     * @return list<string>
     */
    private function useEach(array $sets, Client $agent): array
    {
        $failures = [];

        foreach ($sets as $set) {
            try {
                $result = $agent->call('db.isolation.probe', [
                    'action' => 'seed',
                    'user' => (string) $set['subscription']->system_user,
                    'account' => $set['user']->name,
                    'password' => $set['password'],
                    'host' => $set['user']->host,
                    'database' => $set['database']->name,
                ], $this->actor());
            } catch (AgentException $error) {
                $failures[] = sprintf('%s: konnte die eigene Datenbank nicht benutzen — %s', $set['database']->name, $error->getMessage());

                continue;
            }

            if (($result['value'] ?? '') !== 'abnahme') {
                $failures[] = sprintf('%s: die geschriebene Zeile kam nicht zurück.', $set['database']->name);

                continue;
            }

            $this->line(sprintf('  ok  %s: Tabelle angelegt, geschrieben, gelesen', $set['database']->name));
        }

        return $failures;
    }

    /**
     * Kriterium 3: keine fremde Datenbank — und das ist das eigentliche.
     *
     * **Erwartet werden Namen und keine Anzahl.** Die Probe gibt die Liste
     * zurück, die `SHOW DATABASES` liefert, und hier wird sie mit der
     * erwarteten Menge verglichen. `information_schema` steht immer darin — es
     * ist für jeden Benutzer sichtbar und zeigt nur, worauf er Rechte hat.
     *
     * @param  list<array{subscription: Subscription, database: Database, user: DbUser, password: string}>  $sets
     * @return list<string>
     */
    private function isolation(array $sets, Client $agent): array
    {
        $failures = [];

        foreach ($sets as $index => $set) {
            $foreign = $sets[1 - $index];

            try {
                $result = $agent->call('db.isolation.probe', [
                    'action' => 'probe',
                    'user' => (string) $set['subscription']->system_user,
                    'account' => $set['user']->name,
                    'password' => $set['password'],
                    'host' => $set['user']->host,
                    'database' => $set['database']->name,
                    'foreign' => $foreign['database']->name,
                ], $this->actor());
            } catch (AgentException $error) {
                $failures[] = sprintf('%s: die Probe lief nicht — %s', $set['user']->name, $error->getMessage());

                continue;
            }

            $visible = is_array($result['visible'] ?? null) ? array_map('strval', $result['visible']) : [];
            $expected = [(string) $set['database']->name, 'information_schema'];

            sort($expected);
            sort($visible);

            if ($visible !== $expected) {
                $failures[] = sprintf(
                    '%s sieht {%s}, erwartet war {%s}',
                    $set['user']->name,
                    implode(', ', $visible),
                    implode(', ', $expected),
                );
            } else {
                $this->line(sprintf('  ok  %s sieht genau {%s}', $set['user']->name, implode(', ', $visible)));
            }

            foreach (['use_refused' => 'USE', 'select_refused' => 'SELECT'] as $key => $label) {
                $entry = is_array($result[$key] ?? null) ? $result[$key] : [];

                if (($entry['refused'] ?? false) !== true) {
                    $failures[] = sprintf(
                        '%s: %s auf %s wurde NICHT abgewiesen.',
                        $set['user']->name,
                        $label,
                        $foreign['database']->name,
                    );

                    continue;
                }

                $this->line(sprintf(
                    '  ok  %s: %s auf %s abgewiesen — %s',
                    $set['user']->name,
                    $label,
                    $foreign['database']->name,
                    $this->firstLine((string) ($entry['error'] ?? '')),
                ));
            }
        }

        return $failures;
    }

    /**
     * Aufräumen — die Datenbank entfernen, der Zugang geht mit.
     *
     * `Databases::remove()` reiht einen Vorgang ein und nimmt die Zugänge mit,
     * die **nur** an dieser Datenbank hängen. Der Lauf wartet nicht darauf: Was
     * er hinterlässt, ist ein eingereihter Rückbau, und `srvpanel db` zeigt
     * danach, ob er durchlief.
     *
     * @param  list<array{subscription: Subscription, database: Database, user: DbUser, password: string}>  $sets
     */
    private function teardown(array $sets, Databases $databases): void
    {
        foreach ($sets as $set) {
            try {
                $databases->remove($set['database']);
                $this->line(sprintf('  Rückbau von %s eingereiht.', $set['database']->name));
            } catch (RuntimeException $error) {
                $this->error(sprintf('  %s liess sich nicht zurückbauen: %s', $set['database']->name, $error->getMessage()));
            }
        }

        $this->line('Mit `srvpanel db` nachsehen, ob die Vorgänge durchgelaufen sind.');
    }

    /** @param list<string> $failures */
    private function report(array $failures): int
    {
        $this->line('');

        if ($failures === []) {
            $this->info('Alle geprüften Kriterien erfüllt.');
            $this->line('Kriterium 4 bis 7 (sichern, zurückspielen, Rechte im Dump, Rückbau) laufen von Hand — docs/36 §17.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d Kriterium/Kriterien nicht erfüllt:', count($failures)));

        foreach ($failures as $failure) {
            $this->line('  '.$failure);
        }

        return self::FAILURE;
    }

    /** Die Meldung von MariaDB ist mehrzeilig; für die Liste genügt die erste. */
    private function firstLine(string $message): string
    {
        return trim(explode("\n", $message)[0]);
    }

    /** @return array<string, string> */
    private function actor(): array
    {
        return ['source' => 'cli', 'command' => 'srvpanel:acceptance-db'];
    }
}
