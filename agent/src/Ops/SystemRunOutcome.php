<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\AptLock;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Logs;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Outcome;

/**
 * Wie ein abgesetzter Lauf ausgegangen ist — oder ob er noch läuft.
 *
 * ## Die Frage, die bis zum 28. August 2026 niemand gestellt hat
 *
 * {@see SystemPackagesUpgrade} setzt seinen Lauf über `systemd-run` ab und
 * kehrt zurück, bevor er fertig ist. Der Vorgang stand danach auf `fertig`,
 * während der Lauf noch acht Sekunden lief (`docs/86 §5`, Vorgang 704) — und
 * einmal, obwohl `apt-run` mit 3 endete, also nichts bewirkt hatte.
 *
 * > **Ein Vorgang, der nur meldet, dass er abgesetzt wurde, sagt über den
 * > Ausgang dessen, was er abgesetzt hat, nichts — und `fertig` liest sich wie
 * > das Gegenteil.**
 *
 * ## Zwei Quellen, und jede beantwortet genau eine Frage
 *
 * **Läuft noch etwas?** Das sagt `systemctl`. Und nur das: `--collect` räumt
 * die Unit auch dann ab, wenn sie gescheitert ist, ihr Zustand kann „fertig"
 * von „gescheitert" also nicht unterscheiden.
 *
 * **Wie ist es ausgegangen?** Das sagt die Zeile, die `apt-run` selbst
 * schreibt ({@see Outcome}).
 *
 * > **Ein Zustand, der nach dem Ende verschwindet, ist kein Urteil über das
 * > Ende.**
 *
 * ## Kein Pfad von aussen
 *
 * Übergeben wird ein **Schlüssel**, und der Pfad steht hier. Dieselbe Regel
 * wie bei {@see Logs}: Wer einen Pfad entgegennimmt, nimmt
 * jeden entgegen. Der Versatz kommt von aussen und ist eine Zahl — was er
 * anrichten kann, ist, zu wenig oder zu viel zu lesen, und beides fällt auf
 * die sichere Seite: kein Urteil heisst „läuft noch".
 */
final class SystemRunOutcome implements Op
{
    /**
     * Die Läufe, nach deren Ausgang gefragt werden darf.
     *
     * Schlüssel => [Logdatei, Präfix der Unit].
     *
     * **Der Unitpräfix steht dabei, weil die zweite Frage ihn braucht.** Ein
     * Aufrufer, der eine beliebige Unit nennen dürfte, fragte über diesen Weg
     * den Zustand jeder Unit des Systems ab.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const RUNS = [
        'upgrade' => [SystemPackagesUpgrade::LOG, 'srvpanel-update-'],
        'panel' => [PanelUpdate::LOG, 'srvpanel-update-'],
    ];

    public static function name(): string
    {
        return 'system.run.outcome';
    }

    public static function mutating(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::RUNS);
    }

    public function execute(array $args, Context $context): array
    {
        $key = Guard::enum($args['key'] ?? null, self::keys(), 'key');
        $offset = Guard::int($args['offset'] ?? null, 'offset');
        [$log, $prefix] = self::RUNS[$key];

        $unit = Guard::unitName($args['unit'] ?? null);

        if (! str_starts_with($unit, $prefix)) {
            throw AgentException::badRequest(
                'Diese Unit gehört nicht zu diesem Lauf.',
            );
        }

        $verdict = Outcome::verdict(Outcome::lines($log, $offset));

        return [
            'running' => $this->running($context, $unit),
            'verdict' => $verdict,

            /*
             * **`null` und nicht `false`, solange kein Urteil dasteht.** Ein
             * `false` läse sich wie „nicht gescheitert", also wie ein halbes
             * Urteil — und der Aufrufer entscheidet an genau dieser Stelle,
             * ob er weiter wartet.
             */
            'failed' => $verdict === null ? null : Outcome::failed($verdict),
        ];
    }

    /**
     * Läuft die Unit noch?
     *
     * **Ein `systemctl`, das nicht antwortet, ist hier kein Fehlschlag.**
     * Anders als in {@see AptLock}, wo die unbeantwortete
     * Frage einen kollidierenden Lauf losgehen liesse, fällt sie hier zur
     * sicheren Seite: „läuft noch" bedeutet, dass der Aufrufer weiter wartet
     * und am Ende an seiner Frist scheitert — und eine Frist, die abläuft,
     * meldet keinen Erfolg.
     *
     * > **Wenn eine Frage schiefgehen kann, entscheidet die Richtung, in die
     * > sie schiefgeht.**
     */
    private function running(Context $context, string $unit): bool
    {
        $lauf = $context->runner->run('systemctl', ['is-active', $unit], 15);

        if (! $lauf->successful()) {
            /*
             * `is-active` gibt für eine Unit, die es nicht gibt, ebenfalls
             * ungleich 0 zurück — mit `inactive` auf stdout. Das ist der
             * Normalfall nach `--collect` und keine Störung.
             */
            return trim($lauf->stdout) === 'activating';
        }

        return in_array(trim($lauf->stdout), ['active', 'activating', 'reloading'], true);
    }
}
