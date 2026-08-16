<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Ssh\PublicKey;
use SrvPanel\Agent\Ssh\SshdConfig;

/**
 * `authorized_keys` eines Abonnements neu schreiben — oder wegnehmen.
 *
 * ## Warum die Datei nicht im Abonnement liegt
 *
 * `.ssh/authorized_keys` unter der Abo-Wurzel gehört dem Kunden; er kann sie
 * über genau den Zugang ändern, den sie gewährt. Die Fingerabdrücke im Panel
 * wären dann eine Auskunft über die Hälfte der Wahrheit — und „`authorized_keys`
 * schreibt der Agent, nie der Kunde" (`docs/51 §9`) eine Behauptung.
 *
 * Gemessen (`docs/57 §4`), mit Gegenprobe: Eine `AuthorizedKeysFile`-Angabe im
 * `Match`-Block **ersetzt** die Vorgabe, sie ergänzt sie nicht. Der Schlüssel,
 * den der Kunde sich selbst nach `.ssh/` legt, kommt nicht herein; derselbe
 * Schlüssel in der Datei des Agenten kommt herein.
 *
 * ## Und die Datei gehört root, weil **wir** das verlangen
 *
 * `StrictModes` fragt „gehört sie root **oder** dem Benutzer" — gemessen
 * (`docs/57 §10`) nimmt OpenSSH eine Schlüsseldatei an, die dem Kunden gehört.
 * Für diesen Zugang ist das zu wenig.
 *
 * > **Was der Prüfling durchgehen lässt, ist keine Zusage darüber, wie es sein
 * > soll.**
 *
 * ## Kein Neuladen
 *
 * `authorized_keys` wird je Verbindung gelesen. Eine Änderung hier rührt
 * `sshd_config` nicht an und braucht kein Signal an den Dienst — was bedeutet,
 * dass eine Kundenaktion nie in die Nähe der Datei kommt, deren Beschädigung
 * aussperrt (Risiko 5 aus `docs/51 §14`).
 *
 * ## Der Weg zurück ist derselbe Aufruf
 *
 * Eine leere Liste **entfernt** die Datei, statt sie zu leeren. Eine leere
 * `authorized_keys` sähe aus wie „Zugang eingerichtet, keine Schlüssel" und ist
 * dasselbe wie „kein Zugang"; zwei Zustände für eine Sache sind einer zu viel.
 * `docs/35` ist der Grund, warum das hier ausdrücklich dasteht: Wer etwas
 * anlegt, das auf der Platte bleibt, baut den Weg zurück mit.
 */
final class SftpKeyApply implements Op
{
    /** Wie viele Schlüssel ein Abonnement haben darf. */
    public const MAX_KEYS = 64;

    public static function name(): string
    {
        return 'sftp.key.apply';
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
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);
        $keys = $this->keys($args['keys'] ?? []);
        $file = SshdConfig::keyFile($user);

        $context->progress(20, 'Schlüssel prüfen');

        if ($keys === []) {
            $entfernt = is_file($file);

            if ($entfernt && ! @unlink($file)) {
                throw AgentException::execFailed('Die Schlüsseldatei liess sich nicht entfernen.', ['path' => $file]);
            }

            $context->progress(100, 'fertig');

            return ['user' => $user, 'path' => $file, 'keys' => 0, 'fingerprints' => [], 'removed' => $entfernt];
        }

        $this->directory();

        $context->progress(60, 'Schlüsseldatei schreiben');
        $this->write($file, $this->content($user, $keys));

        $context->progress(100, 'fertig');

        return [
            'user' => $user,
            'path' => $file,
            'keys' => count($keys),
            'fingerprints' => array_map(static fn (array $k): string => $k['key']['fingerprint'], $keys),
            'removed' => false,
        ];
    }

    /**
     * Die Datei, wie sie aussieht — als reine Funktion, damit ein Test sie ohne Platte liest.
     *
     * @param  list<array{key: array{type: string, material: string, fingerprint: string, bits: int, comment: string}, label: string}>  $keys
     */
    public static function content(string $user, array $keys): string
    {
        $text = "# Von srvpanel verwaltet — Änderungen hier werden beim nächsten Lauf\n"
            ."# überschrieben. Die Schlüssel stehen im Panel unter „Zugang\" des\n"
            .'# Abonnements von '.$user."; dort werden sie hinzugefügt und entfernt.\n";

        foreach ($keys as $eintrag) {
            $text .= PublicKey::line($eintrag['key'], $eintrag['label'])."\n";
        }

        return $text;
    }

    /**
     * Die Schlüssel aus dem Aufruf — geprüft, bevor einer in die Datei kommt.
     *
     * **Und einmalig je Fingerabdruck.** Zwei gleiche Zeilen sind für OpenSSH
     * kein Fehler, aber sie machen aus jeder Anzeige „drei Schlüssel" bei zwei
     * Zugängen.
     *
     * @return list<array{key: array{type: string, material: string, fingerprint: string, bits: int, comment: string}, label: string}>
     */
    private function keys(mixed $value): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest('keys muss eine Liste sein.');
        }

        if (count($value) > self::MAX_KEYS) {
            throw AgentException::badRequest(sprintf(
                'Zu viele Schlüssel: %d, erlaubt sind %d.',
                count($value),
                self::MAX_KEYS,
            ));
        }

        $keys = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                throw AgentException::badRequest('Jeder Schlüssel ist ein Objekt aus key und label.');
            }

            $key = PublicKey::parse($entry['key'] ?? null);
            $keys[$key['fingerprint']] = [
                'key' => $key,
                'label' => Guard::string($entry['label'] ?? '', 'label'),
            ];
        }

        return array_values($keys);
    }

    /** Das Verzeichnis, in dem die Schlüsseldateien liegen — root:root, für alle lesbar. */
    private function directory(): void
    {
        if (! is_dir(SshdConfig::KEYS) && ! @mkdir(SshdConfig::KEYS, 0o755, true) && ! is_dir(SshdConfig::KEYS)) {
            throw AgentException::execFailed(
                'Das Verzeichnis für die Schlüsseldateien liess sich nicht anlegen.',
                ['path' => SshdConfig::KEYS],
            );
        }

        @chown(SshdConfig::KEYS, 'root');
        @chgrp(SshdConfig::KEYS, 'root');
        @chmod(SshdConfig::KEYS, 0o755);
    }

    /**
     * Ganz oder gar nicht, und mit den Rechten **vor** dem Umbenennen.
     *
     * Eine halb geschriebene `authorized_keys` weist eine Anmeldung ab, die
     * gerade läuft; und eine, die einen Augenblick lang zu weite Rechte trägt,
     * weist sie ebenfalls ab (`docs/57 §9`: `bad ownership or modes for file`).
     * Deshalb wird die Nachbardatei fertig gemacht, bevor sie das Original wird.
     */
    private function write(string $file, string $content): void
    {
        $temporary = $file.'.srvpanel.'.getmypid();

        if (@file_put_contents($temporary, $content) === false) {
            throw AgentException::execFailed('Die Schlüsseldatei liess sich nicht schreiben.', ['path' => $temporary]);
        }

        @chown($temporary, 'root');
        @chgrp($temporary, 'root');
        @chmod($temporary, 0o644);

        if (! @rename($temporary, $file)) {
            @unlink($temporary);

            throw AgentException::execFailed('Die Schlüsseldatei liess sich nicht ersetzen.', ['path' => $file]);
        }
    }
}
