<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Support\Str;

/**
 * Wiederherstellungscodes für den Fall, dass das Telefon weg ist.
 *
 * **Sie werden gehasht abgelegt, nicht verschlüsselt.** Verschlüsselt ließen
 * sie sich wieder anzeigen — und damit wäre die Zusage „einmal notieren, dann
 * nie wieder sichtbar" gebrochen. Wer die Datenbank liest, soll sie nicht
 * benutzen können.
 *
 * **Mit SHA-256, nicht mit Argon2id.** Das klingt nach dem falschen Verfahren
 * und ist hier das richtige: Der Grund für langsames Hashen sind Passwörter
 * mit wenig Entropie, die sich durchprobieren lassen. Diese Codes sind
 * zufällig und tragen rund fünfzig Bit — durchprobieren ist aussichtslos,
 * gleich wie schnell der Hash ist. Argon2id würde stattdessen jeden
 * Anmeldeversuch mit acht Vergleichen zu je 64 MiB belasten; bei acht Codes
 * sind das mehrere Sekunden und ein bequemer Weg, den Server lahmzulegen.
 */
final class RecoveryCodes
{
    public const COUNT = 8;

    /** Ohne I, O, 0 und 1 — die verwechselt man beim Abschreiben. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Acht neue Codes im Klartext. Sie werden genau einmal angezeigt.
     *
     * @return list<string>
     */
    public static function generate(): array
    {
        $codes = [];

        for ($i = 0; $i < self::COUNT; $i++) {
            $codes[] = self::format(self::randomCode());
        }

        return $codes;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function hashAll(array $codes): array
    {
        return array_map(self::hash(...), $codes);
    }

    /**
     * Einen Code einlösen.
     *
     * Gibt die verbleibenden Hashes zurück oder `null`, wenn der Code nicht
     * passte. **Ein eingelöster Code ist weg** — das ist der ganze Sinn: Wer
     * einen mitliest, kann ihn nicht ein zweites Mal verwenden.
     *
     * @param  list<string>  $stored
     * @return list<string>|null
     */
    public static function consume(array $stored, string $input): ?array
    {
        $needle = self::hash($input);
        $remaining = [];
        $found = false;

        foreach ($stored as $hash) {
            // Alle durchlaufen, auch nach einem Treffer: Ein vorzeitiger
            // Abbruch verrät über die Laufzeit, an welcher Stelle der Code
            // stand.
            if (! $found && hash_equals($hash, $needle)) {
                $found = true;

                continue;
            }

            $remaining[] = $hash;
        }

        return $found ? $remaining : null;
    }

    public static function hash(string $code): string
    {
        return hash('sha256', self::normalise($code));
    }

    /** Bindestriche, Leerzeichen und Kleinschreibung sollen nicht stören. */
    private static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private static function randomCode(): string
    {
        $code = '';

        for ($i = 0; $i < 10; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** Gruppiert abgedruckt tippt es sich zuverlässiger ab. */
    private static function format(string $code): string
    {
        return implode('-', str_split($code, 5));
    }

    public static function looksLikeRecoveryCode(string $input): bool
    {
        return strlen(self::normalise($input)) === 10 && ! Str::of($input)->test('/^\d{6}$/');
    }
}
