<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Site;

/**
 * Die letzten Zeilen eines Protokolls einer Domain.
 *
 * **Auch hier kommt kein Pfad von aussen.** Übergeben werden Abonnement,
 * Domain und eine Sorte aus einer festen Liste; der Pfad entsteht in
 * {@see Site}. Eine Operation „lies diese Datei" mit einem Pfad als Argument
 * wäre der kürzeste Weg von einem angemeldeten Kunden zu `/etc/shadow` — und
 * jede nachträgliche Prüfung des Pfades hätte irgendwann eine Lücke.
 *
 * **Von hinten gelesen.** Ein Zugriffsprotokoll wird hunderte Megabyte gross.
 * `file()` läse es ganz in den Speicher, und der Agent hat, anders als das
 * Panel, keinen Grund für ein grosszügiges Limit. Gelesen wird deshalb
 * rückwärts in Blöcken, bis genug Zeilenumbrüche beisammen sind.
 */
final class WebLogsTail implements Op
{
    public const MAX_LINES = 500;

    /** Ein Block, wie er rückwärts gelesen wird. */
    private const CHUNK = 8192;

    /** Mehr als das wird nicht zurückgegeben, egal wie lang die Zeilen sind. */
    private const MAX_BYTES = 512 * 1024;

    public static function name(): string
    {
        return 'web.logs.tail';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $site = Site::fromArgs([
            'subscription' => $args['subscription'] ?? null,
            'user' => $args['user'] ?? null,
            'domain' => $args['domain'] ?? null,
            'document_root' => SubscriptionProvision::DOCUMENT_ROOT,
        ]);

        $kind = Guard::enum($args['kind'] ?? 'access', ['access', 'error'], 'kind');
        $lines = $this->lines($args['lines'] ?? 100);

        $path = $kind === 'access' ? $site->accessLog() : $site->errorLog();

        if (! is_file($path)) {
            // Kein Protokoll ist kein Fehler: Eine Domain, die noch niemand
            // aufgerufen hat, hat keines. Eine Ausnahme hier führte im Panel
            // zu einer roten Meldung für den Normalfall am ersten Tag.
            return ['path' => $path, 'kind' => $kind, 'lines' => [], 'exists' => false, 'size' => 0];
        }

        return [
            'path' => $path,
            'kind' => $kind,
            'lines' => self::tail($path, $lines),
            'exists' => true,
            'size' => (int) filesize($path),
        ];
    }

    /**
     * Die letzten `$count` Zeilen einer Datei.
     *
     * @return list<string>
     */
    public static function tail(string $path, int $count): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw AgentException::execFailed('Das Protokoll liess sich nicht öffnen.', ['path' => $path]);
        }

        try {
            fseek($handle, 0, SEEK_END);
            $position = ftell($handle);

            if ($position === false || $position === 0) {
                return [];
            }

            $text = '';

            while ($position > 0 && strlen($text) < self::MAX_BYTES) {
                $step = (int) min(self::CHUNK, $position);
                $position -= $step;

                fseek($handle, $position, SEEK_SET);
                $text = (string) fread($handle, $step).$text;

                // Ein Umbruch mehr als Zeilen gewünscht: Der erste Block endet
                // in aller Regel mitten in einer Zeile, und die gehört nicht
                // angeschnitten zurückgegeben.
                if (substr_count($text, "\n") > $count) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        $all = explode("\n", rtrim($text, "\n"));

        return array_values(array_slice($all, -$count));
    }

    private function lines(mixed $value): int
    {
        $lines = Guard::int($value, 'lines');

        if ($lines < 1 || $lines > self::MAX_LINES) {
            throw AgentException::badRequest(
                sprintf('lines liegt zwischen 1 und %d.', self::MAX_LINES),
                ['lines' => $lines],
            );
        }

        return $lines;
    }
}
