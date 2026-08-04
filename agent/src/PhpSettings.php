<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use Tests\Unit\AnchoredPatternTest;

/**
 * Die PHP-Einstellungen, die eine Domain übersteuern darf.
 *
 * **Die Trennung, um die es hier geht, ist die zwischen `php_admin_value` und
 * `PHP_VALUE`.** Was im Pool als `php_admin_value` steht, lässt sich von
 * nirgends mehr ändern — dort liegen `open_basedir`, `disable_functions` und
 * die Verzeichnisse für Hochladungen und Sitzungen, also alles, was die
 * Abschottung eines Abonnements ausmacht. Was hier steht, geht als `PHP_VALUE`
 * über FastCGI mit und gilt nur für diese eine Domain.
 *
 * Diese Reihenfolge ist keine Bequemlichkeit, sondern die Schranke: PHP-FPM
 * lässt einen `PHP_VALUE` einen `php_admin_value` **nicht** überschreiben. Ein
 * Kunde, der `open_basedir` in seinen Domaineinstellungen setzen wollte, käme
 * damit nicht an die Abschottung heran — und deshalb steht `open_basedir` hier
 * auch nicht auf der Liste.
 *
 * **Der Pool ist je Abonnement und Version, die Einstellung je Domain.** Das
 * ist der Grund, warum diese Werte nicht in den Pool können: Drei Domains
 * eines Abonnements teilen sich einen Pool, hätten aber drei verschiedene
 * `memory_limit`.
 *
 * Der Plan (§9 P3) deckelt die Werte über den Plan des Abonnements. Diese
 * Prüfung sitzt im Panel, wo der Plan bekannt ist; hier steht die Form —
 * welcher Schlüssel überhaupt gesetzt werden darf und wie sein Wert aussehen
 * muss.
 */
final class PhpSettings
{
    /**
     * Schlüssel => Muster für den Wert.
     *
     * Die Muster sind eng: eine Zahl mit Einheit, eine Zahl, an oder aus, eine
     * Zeitzone. Kein Muster lässt ein Anführungszeichen, einen Zeilenumbruch
     * oder ein Semikolon durch — die drei Zeichen, mit denen sich aus einem
     * `fastcgi_param` eine zweite Anweisung machen liesse.
     *
     * **Der Modifikator `D` ist keine Zierde.** Ohne ihn passt `$` auch vor
     * einem abschliessenden Zeilenumbruch, und `Europe/Berlin\n` ginge durch —
     * ein gültiger Wert und der Anfang einer zweiten Einstellung. Genau daran
     * ist der Angriffsdurchgang hängengeblieben; die ganze Fehlerklasse hat
     * seitdem einen eigenen Wächter ({@see AnchoredPatternTest}).
     *
     * @var array<string, string>
     */
    public const ALLOWED = [
        'memory_limit' => '/^[1-9][0-9]{0,4}M$/D',
        'upload_max_filesize' => '/^[1-9][0-9]{0,4}M$/D',
        'post_max_size' => '/^[1-9][0-9]{0,4}M$/D',
        'max_execution_time' => '/^[1-9][0-9]{0,3}$/D',
        'max_input_time' => '/^-?[1-9][0-9]{0,3}$/D',
        'max_input_vars' => '/^[1-9][0-9]{0,5}$/D',
        'display_errors' => '/^(on|off)$/D',
        'date.timezone' => '#^[A-Za-z][A-Za-z0-9_+/-]{1,63}$#D',
    ];

    /**
     * Prüft die Einstellungen einer Domain.
     *
     * @return array<string, string>
     */
    public static function check(mixed $value, string $field = 'php_settings'): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (! is_array($value)) {
            throw AgentException::badRequest('PHP-Einstellungen müssen eine Zuordnung sein.', [$field => 'kein Array']);
        }

        $checked = [];

        foreach ($value as $key => $raw) {
            if (! is_string($key) || ! array_key_exists($key, self::ALLOWED)) {
                throw AgentException::badRequest(
                    'Diese PHP-Einstellung lässt sich nicht je Domain setzen.',
                    [$field => is_string($key) ? $key : gettype($key), 'allowed' => array_keys(self::ALLOWED)],
                );
            }

            // Zahlen kommen aus JSON als int an; das ist kein Fehler des
            // Aufrufers, sondern die Kodierung.
            $setting = is_int($raw) ? (string) $raw : Guard::string($raw, $field.'.'.$key);

            if (preg_match(self::ALLOWED[$key], $setting) !== 1) {
                throw AgentException::badRequest(
                    sprintf('Unzulässiger Wert für %s.', $key),
                    [$field => substr($setting, 0, 40)],
                );
            }

            $checked[$key] = $setting;
        }

        return $checked;
    }
}
