<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\FindingCheck;
use App\Enums\SubscriptionStatus;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\WebLifecycle;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Ops\SystemDiagnose;

/**
 * Die Prüfungen, die Systemrechte brauchen — in **einem** Aufruf
 * (A10 Schritt 6, `docs/98 §3` A, B, F, I).
 *
 * ## Warum einer und nicht sieben
 *
 * `system.diagnose` nimmt eine Liste von Schlüsseln entgegen und beantwortet
 * sie alle. Sieben Aufrufe wären sieben Socket-Verbindungen für eine Antwort,
 * die in einer Zeile passt — dieselbe Überlegung wie bei
 * `system.units.list`, das neunzehn Units in einem `systemctl show` fragt.
 *
 * **`block.integrity` fehlt hier, und das ist kein Versehen.** Ihn schreibt
 * {@see ManagedBlocks}, weil er neben der Form auch den Sollzustand braucht —
 * und weil `FindingLog::replace()` alle Zeilen einer Prüfung ersetzt: Zwei
 * Schreiber löschten einander die Befunde weg.
 *
 * ## Die Gegenstände kommen vom Panel
 *
 * `web.file` bekommt die Domains, `php.file` die Paare aus Version und
 * Benutzer. Der Agent baut daraus die Pfade, die er selbst geschrieben hat
 * (`Site::CONF_DIR`, `PhpVersions::poolFile()`) — nur so lässt sich „die Datei
 * fehlt" überhaupt melden. Wer das Verzeichnis abliest, sieht eine fehlende
 * Datei nicht.
 *
 * ## Was ein schweigender Agent bedeutet
 *
 * Jede Prüfung dieses Aufrufs steht dann auf `unknown` — Punkt 7 des
 * Abnahmekriteriums. Nicht auf `ok`: Ein Diagnoselauf, der bei totem Agenten
 * Entwarnung gibt, ist schlimmer als keiner (`docs/44`).
 */
final class Agent implements Check
{
    /**
     * Welche Schlüssel dieser Aufruf holt — alle ausser `block.integrity`.
     *
     * Abgeleitet und nicht abgeschrieben: Eine Liste hier liefe von
     * {@see SystemDiagnose::CHECKS} weg, sobald der Agent eine Prüfung
     * dazubekommt, und die neue fehlte dann still.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_values(array_filter(
            SystemDiagnose::CHECKS,
            static fn (string $key): bool => $key !== FindingCheck::BlockIntegrity->value,
        ));
    }

    public function __construct(
        private readonly Client $agent,
        private readonly Tenancy $tenancy,
        private readonly WebLifecycle $web,
    ) {}

    public function writes(): array
    {
        return array_map(
            static fn (string $key): FindingCheck => FindingCheck::from($key),
            self::keys(),
        );
    }

    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        $subjects = $this->subjects();

        try {
            $answer = $this->agent->call('system.diagnose', [
                'checks' => self::keys(),
                'domains' => $subjects['domains'],
                'pools' => $subjects['pools'],
            ]);
        } catch (AgentException $e) {
            foreach ($this->writes() as $check) {
                $log->unreachable($check, self::subjectsOf($check, $subjects), $measuredAt, $e->getMessage());
            }

            return;
        }

        $checks = is_array($answer['checks'] ?? null) ? $answer['checks'] : [];

        foreach ($this->writes() as $check) {
            $findings = $checks[$check->value] ?? null;

            if (! is_array($findings)) {
                // Ein Schlüssel, den der Agent nicht beantwortet hat: Er ist
                // älter als das Panel. Nichts zu schreiben ist hier richtig —
                // `replace()` mit einer leeren Liste hiesse „geprüft, nichts
                // gefunden", und geprüft wurde nichts.
                continue;
            }

            $log->replace($check, array_values($findings), $measuredAt);
        }
    }

    /**
     * Woran eine ausgefallene Prüfung hängt.
     *
     * **Ein `unreachable` braucht einen Ort** ({@see FindingLog::unreachable()}).
     * Für die drei Prüfer und die Quota ist es die Datei beziehungsweise das
     * Verzeichnis, für die beiden Dateiprüfungen sind es die Gegenstände, die
     * gefragt worden wären.
     *
     * @param  array{domains: list<array{name: string, form: string, certificate: null|string}>, pools: list<array{version: string, user: string}>}  $subjects
     * @return list<string>
     */
    private static function subjectsOf(FindingCheck $check, array $subjects): array
    {
        return match ($check) {
            // **Der Gegenstand bleibt der Name**, auch wenn der Aufruf mehr
            // mitschickt: Eine Zeile „nicht gemessen" nennt die Domain und
            // nicht ihre Form.
            FindingCheck::WebFile => array_map(
                static fn (array $domain): string => $domain['name'],
                $subjects['domains'],
            ),
            FindingCheck::PhpFile => array_map(
                static fn (array $pool): string => $pool['user'].' / PHP '.$pool['version'],
                $subjects['pools'],
            ),
            default => [$check->label()],
        };
    }

    /**
     * Die Gegenstände, die der Agent prüfen soll.
     *
     * Ein Pool gehört zum **Abonnement** und nicht zur Domain: Zwei Domains
     * desselben Kunden in derselben PHP-Fassung teilen ihn. Deshalb wird das
     * Paar aus Benutzer und Version entdoppelt, wie es `php.pool.apply` beim
     * Schreiben ohnehin tut.
     *
     * @return array{domains: list<array{name: string, form: string, certificate: null|string}>, pools: list<array{version: string, user: string}>}
     */
    private function subjects(): array
    {
        /** @var list<array{name: string, form: string, certificate: null|string}> $domains */
        $domains = [];

        /** @var array<string, array{version: string, user: string}> $pools */
        $pools = [];

        $web = $this->web;

        $this->tenancy->withoutRestriction(static function () use (&$domains, &$pools, $web): void {
            $usable = array_fill_keys(
                Subscription::query()->whereIn('status', SubscriptionStatus::usableValues())->pluck('id')->all(),
                true,
            );
            $users = Subscription::query()->pluck('system_user', 'id')->all();

            $rows = Domain::query()
                ->withoutGlobalScopes()
                ->with('children')
                ->where('status', DomainStatus::Active->value)
                ->where('type', '!=', DomainType::Alias->value)
                ->orderBy('id')
                ->get();

            /** @var Domain $domain */
            foreach ($rows as $domain) {
                $subscription = (int) $domain->subscription_id;

                if (! isset($usable[$subscription])) {
                    continue;
                }

                /*
                 * **Die Form geht mit, und sie kommt von der Stelle, die
                 * schreibt** ({@see WebLifecycle::form()}). Bis zum
                 * 3. September schickte diese Zeile nur den Namen, und der
                 * Agent prüfte gegen die Schnittmenge aller Formen — neun
                 * Anweisungen von fünfundzwanzig (`docs/99 §5`).
                 */
                $domains[] = $web->form($domain);

                $user = (string) ($users[$subscription] ?? '');
                $version = (string) ($domain->php_version ?? '');

                if (! $domain->servesPhp() || $user === '' || $version === '') {
                    continue;
                }

                $pools[$user.'|'.$version] = ['version' => $version, 'user' => $user];
            }
        });

        return ['domains' => $domains, 'pools' => array_values($pools)];
    }
}
