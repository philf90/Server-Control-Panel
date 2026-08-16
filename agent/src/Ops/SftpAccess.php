<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\ManagedBlock;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Result;
use SrvPanel\Agent\Ssh\SshdConfig;

/**
 * Den verwalteten Block in `sshd_config` auf den Sollzustand bringen.
 *
 * ## Der Ablauf ist umgekehrt zu dem für `pg_hba.conf`, und das ist gemessen
 *
 * `PgRemoteAccess` schreibt, lädt neu, sieht nach und rollt bei einem Fehler
 * zurück. Das trägt dort, weil ein Reload mit kaputter Datei folgenlos ist:
 * PostgreSQL bedient weiter und behält die alten Regeln (`docs/38`, M16).
 *
 * **Hier trägt es nicht.** Gemessen am 16. August 2026 (`docs/57 §5`):
 *
 *     Received SIGHUP; restarting.
 *     sshd_config: line 19: Bad configuration option: Klabautermann
 *     sshd_config: terminating, 1 bad configuration options
 *
 * Danach horcht niemand mehr. Ein Rückweg nach dem Neuladen griffe in eine Tür,
 * die er selbst zugezogen hat.
 *
 * > **Ein Rückweg, der voraussetzt, dass der Dienst noch läuft, ist keiner für
 * > den Fall, dass ihn genau dieser Vorgang beendet hat.**
 *
 * Deshalb: **erst prüfen, dann schreiben** ({@see ManagedBlock::validated()}).
 * Der Rückweg danach bleibt trotzdem stehen — für den Fall, dass das Neuladen
 * aus einem Grund scheitert, den `sshd -t` nicht kennt.
 *
 * ## Was `sshd -t` sieht und was nicht
 *
 * Syntax ja, Semantik nein (`docs/57 §8`): Ein `ChrootDirectory` auf ein
 * Verzeichnis, das es nicht gibt, ein `AuthorizedKeysFile` auf eine fehlende
 * Datei, falsche Rechte — alles `rc=0`. Das ist die Arbeit von
 * {@see SftpCheck}, und sie ist deshalb kein Zusatz, sondern die andere Hälfte.
 *
 * ## Und was gilt, sagt nicht unser Block
 *
 * Der **erste** passende `Match`-Block gewinnt (`docs/57 §7`, gegengeprüft an
 * einer echten Anmeldung). Ein Eintrag des Betreibers weiter oben schlägt
 * unseren — zu Recht, „der Bestand ist Gesetz". Nachgesehen wird deshalb mit
 * `sshd -T -C user=…`, also aus derselben Quelle, aus der sshd es nimmt.
 *
 * > **Ein Block, den man geschrieben hat, ist keine Auskunft darüber, was
 * > gilt.**
 *
 * Eine Abweichung ist hier **kein Fehler und kein Rückweg**, sondern ein
 * Befund: Sie steht in der Antwort, und das Panel zeigt sie.
 *
 * ## Warum diese Operation `systemctl` selbst ruft
 *
 * {@see ServiceAction} darf `ssh` nicht und soll es nie dürfen — damit liesse
 * sich der Zugang zum Server abschalten. Hier steht der Unitname als Konstante,
 * und die einzige Handlung ist `reload`. Dieselbe Bauart wie `pg_ctlcluster` in
 * {@see PgRemoteAccess}.
 */
final class SftpAccess implements Op
{
    /**
     * Die Unit, und die Reihenfolge, in der nachgesehen wird.
     *
     * Debian und Ubuntu liefern `ssh.service` mit `Alias=sshd.service`; andere
     * Systeme nennen sie umgekehrt. Gefragt wird nach beiden und genommen die,
     * die es gibt — geraten wird nicht.
     *
     * @var list<string>
     */
    public const UNITS = ['ssh.service', 'sshd.service'];

    public static function name(): string
    {
        return 'sftp.access';
    }

    public static function mutating(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(array $args, Context $context): array
    {
        $accesses = $this->accesses($args['accesses'] ?? []);

        $context->progress(10, 'Zugangsblock erzeugen');

        $written = self::apply(
            SshdConfig::FILE,
            $accesses,
            fn (string $candidate): Result => $context->runner->run('sshd', ['-t', '-f', $candidate], 30),
            fn (): array => $this->reload($context),
        );

        $context->progress(80, 'nachsehen, was gilt');

        $effective = [];

        foreach ($accesses as $access) {
            $effective[] = $this->effective($context, $access);
        }

        $context->progress(100, 'fertig');

        return [
            'path' => SshdConfig::FILE,
            'accesses' => count($accesses),
            'changed' => $written['changed'],
            'reload' => $written['reload'],
            'effective' => $effective,
            'overridden' => array_values(array_filter(
                $effective,
                static fn (array $eintrag): bool => ! $eintrag['ours'],
            )),
        ];
    }

    /**
     * Die fünf Schritte — unter der Sperre und ohne Dienst.
     *
     * `public static` aus demselben Grund wie {@see PgRemoteAccess::apply()}:
     * Ein Wächter fährt **diesen** Ablauf gegen eine echte Datei und lässt die
     * Prüfung fehlschlagen. Ohne den Einstieg bliebe nur eine zweite Fassung
     * des Rückwegs im Test — und die zweite ist die, die veraltet.
     *
     * @param  list<array{user: string, name: string}>  $accesses
     * @param  callable(string): Result  $validate
     * @param  callable(): array{reloaded: bool, unit: string|null, note: string}  $reload
     * @return array{changed: bool, reload: array{reloaded: bool, unit: string|null, note: string}}
     */
    public static function apply(string $path, array $accesses, callable $validate, callable $reload): array
    {
        return ManagedBlock::locked($path, static function () use ($path, $accesses, $validate, $reload): array {
            $before = ManagedBlock::read($path);
            $after = ManagedBlock::render($before, SshdConfig::lines($accesses), $path);

            if ($after === $before) {
                return ['changed' => false, 'reload' => ['reloaded' => false, 'unit' => null, 'note' => 'nichts zu ändern']];
            }

            /*
             * **Geprüft wird der Entwurf, nicht die Datei.** Bis hierher ist
             * `sshd_config` unberührt; ein Fehler kostet nichts als diesen
             * Aufruf. Nach dem `rename` unten kostete er den Zugang zum Server.
             */
            ManagedBlock::validated($path, $after, static function (string $candidate) use ($validate): void {
                $result = $validate($candidate);

                if (! $result->successful()) {
                    throw AgentException::execFailed(
                        'Der Zugangsblock ist von sshd abgewiesen worden; an der Datei wurde nichts '
                        .'geändert: '.$result->message(),
                        ['check' => $result->message()],
                    );
                }
            });

            ManagedBlock::put($path, $after);

            $note = $reload();

            if ($note['reloaded'] === false && $note['note'] === 'gescheitert') {
                /*
                 * **Der Rückweg für den Fall, den `sshd -t` nicht kennt.** Er
                 * ist nach der Messung aus `docs/57 §5` unwahrscheinlich
                 * geworden — nicht unmöglich: Ein Neuladen kann auch an etwas
                 * scheitern, das mit dieser Datei nichts zu tun hat.
                 */
                ManagedBlock::put($path, $before);
                $reload();

                throw AgentException::execFailed(
                    'Das Neuladen von sshd ist gescheitert; der vorherige Stand der Datei ist '
                    .'wiederhergestellt.',
                );
            }

            return ['changed' => true, 'reload' => $note];
        });
    }

    /**
     * Neu laden — und „läuft gerade nicht" ist kein Fehlschlag.
     *
     * Auf Ubuntu 24.04 ist `ssh.socket` eingeschaltet (`docs/57 §13`): Der
     * Dienst wird erst bei der ersten Verbindung gestartet. Ein `reload` auf
     * eine Unit, die nicht läuft, scheitert — und es gibt dabei nichts zu
     * beklagen, denn die nächste Verbindung liest die neue Datei ohnehin.
     *
     * > **Ein Dienst, der nicht läuft, hat keine alte Fassung im Gedächtnis,
     * > die man ihm austreiben müsste.**
     *
     * @return array{reloaded: bool, unit: string|null, note: string}
     */
    private function reload(Context $context): array
    {
        foreach (self::UNITS as $unit) {
            $status = $context->runner->run('systemctl', ['is-active', $unit], 20);
            $stand = trim($status->stdout);

            if ($stand === 'unknown' || $stand === '') {
                continue;
            }

            if ($stand !== 'active') {
                return [
                    'reloaded' => false,
                    'unit' => $unit,
                    'note' => sprintf('%s ist %s — die neue Datei gilt ab der nächsten Verbindung', $unit, $stand),
                ];
            }

            $result = $context->runner->run('systemctl', ['reload', $unit], 60);

            return $result->successful()
                ? ['reloaded' => true, 'unit' => $unit, 'note' => 'neu geladen']
                : ['reloaded' => false, 'unit' => $unit, 'note' => 'gescheitert'];
        }

        return ['reloaded' => false, 'unit' => null, 'note' => 'auf diesem System gibt es keine sshd-Unit'];
    }

    /**
     * Was für diesen Benutzer wirklich gilt — gefragt, nicht abgelesen.
     *
     * @param  array{user: string, name: string}  $access
     * @return array{user: string, chroot: string, wanted: string, ours: bool}
     */
    private function effective(Context $context, array $access): array
    {
        $wanted = SubscriptionProvision::VHOSTS.'/'.$access['name'];
        $result = $context->runner->run('sshd', [
            '-T', '-C', 'user='.$access['user'].',host=127.0.0.1,addr=127.0.0.1',
        ], 30);

        $chroot = '';

        foreach ($result->lines() as $line) {
            if (str_starts_with($line, 'chrootdirectory ')) {
                $chroot = trim(substr($line, strlen('chrootdirectory ')));
            }
        }

        return [
            'user' => $access['user'],
            'chroot' => $chroot,
            'wanted' => $wanted,
            'ours' => $chroot === $wanted,
        ];
    }

    /**
     * Der Sollzustand aus dem Aufruf — jedes Mal ganz.
     *
     * Ein „trag diesen einen nach" wäre eine Operation, deren Ergebnis von der
     * Reihenfolge früherer Aufrufe abhängt (`docs/42`). Was ankommt, ist die
     * Datei, wie sie hinterher aussehen soll.
     *
     * @return list<array{user: string, name: string}>
     */
    private function accesses(mixed $value): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest('accesses muss eine Liste sein.');
        }

        $accesses = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                throw AgentException::badRequest('Jeder Zugang ist ein Objekt aus user und name.');
            }

            $accesses[] = [
                'user' => SubscriptionProvision::systemUser($entry['user'] ?? null),
                'name' => SubscriptionProvision::subscriptionName($entry['name'] ?? null),
            ];
        }

        return $accesses;
    }
}
