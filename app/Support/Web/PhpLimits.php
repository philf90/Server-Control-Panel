<?php

declare(strict_types=1);

namespace App\Support\Web;

use App\Models\Subscription;
use App\Support\Plans\Feature;
use App\Support\Plans\Quota;
use Illuminate\Validation\ValidationException;
use SrvPanel\Agent\PhpSettings;

/**
 * Die PHP-Einstellungen einer Domain gegen den Plan prüfen.
 *
 * §9 P3 verlangt sie „mit vom Plan gedeckelten Grenzen", und §6.2.3 sagt, wo
 * die Prüfung sitzt: **im Dienst, nicht im Formular.** Ein Kunde, der das
 * Formular umgeht, muss auf dieselbe Schranke treffen wie einer, der es
 * benutzt.
 *
 * **Zwei Prüfungen, und beide sind nötig.** Der Agent prüft die *Form* — ist
 * `memory_limit` eine Zahl mit `M`, ist `display_errors` an oder aus. Hier
 * wird die *Höhe* geprüft: Liegt der Wert unter dem Deckel des Plans. Der
 * Agent kann das nicht, denn er kennt keinen Plan; das Panel darf sich auf die
 * Formprüfung nicht verlassen, denn sie liefe erst, wenn der Wert schon
 * gespeichert ist.
 *
 * **Jeder Schlüssel des Agenten ist hier entweder gedeckelt oder ausdrücklich
 * ungedeckelt.** Ein Wächter hält beide Listen zusammen — sonst käme eines
 * Tages eine Einstellung dazu, die niemand begrenzt, und niemandem fiele es
 * auf, weil sie ja funktioniert.
 */
final class PhpLimits
{
    /**
     * Welcher Deckel für welchen Schlüssel gilt.
     *
     * `upload_max_filesize` und `post_max_size` teilen sich einen: Wer 100 MB
     * hochladen darf, braucht eine Anfrage, die mindestens so gross sein darf.
     * Zwei getrennte Kontingente wären zwei Zahlen, die fast immer gleich
     * stehen — und in dem Fall, in dem sie es nicht tun, schlägt der Upload
     * mit einer Meldung fehl, die auf keine der beiden zeigt.
     *
     * @var array<string, string> Einstellung => Kontingentschlüssel
     */
    private const CAPS = [
        'memory_limit' => 'php_memory_mb',
        'upload_max_filesize' => 'php_upload_mb',
        'post_max_size' => 'php_upload_mb',
        'max_execution_time' => 'php_execution_seconds',
    ];

    /**
     * Was ohne Deckel gesetzt werden darf — mit Begründung je Eintrag.
     *
     * Der Wert steht hier und nicht in einem Kommentar daneben, damit eine
     * Liste ohne Grund gar nicht erst entsteht.
     *
     * @var array<string, string>
     */
    private const UNCAPPED = [
        'max_input_time' => 'Wartezeit beim Einlesen einer Anfrage. Der Pool beendet sie ohnehin nach 300 Sekunden.',
        'max_input_vars' => 'Anzahl der Felder eines Formulars. Kostet Speicher innerhalb des ohnehin gedeckelten memory_limit.',
        'display_errors' => 'Ob Fehler im Browser erscheinen. Eine Anzeigefrage, keine Ressource.',
        'date.timezone' => 'Die Zeitzone der Anwendung. Kostet nichts.',
    ];

    /**
     * Prüft die Einstellungen einer Domain gegen Plan und Freigabe.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function check(Subscription $subscription, array $settings): array
    {
        if ($settings === []) {
            return [];
        }

        // **Die Freigabe zuerst.** Ohne sie ist die Frage nach der Höhe
        // gegenstandslos: Das Abonnement darf die Werte gar nicht setzen.
        if (! $subscription->feature(Feature::PhpSettings->value)) {
            throw ValidationException::withMessages([
                'php_settings' => 'Dieser Plan gibt eigene PHP-Einstellungen nicht frei.',
            ]);
        }

        $checked = [];

        foreach ($settings as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, PhpSettings::ALLOWED)) {
                throw ValidationException::withMessages([
                    'php_settings' => 'Diese PHP-Einstellung lässt sich nicht je Domain setzen.',
                ]);
            }

            $setting = is_int($value) ? (string) $value : (is_string($value) ? $value : '');

            $this->withinCap($subscription, $key, $setting);

            $checked[$key] = $setting;
        }

        return $checked;
    }

    /** Der Deckel für eine Einstellung — `null`, wenn sie keinen hat. */
    public function capFor(Subscription $subscription, string $key): ?int
    {
        $quota = self::CAPS[$key] ?? null;

        if ($quota === null) {
            return null;
        }

        $limit = $subscription->quota($quota);

        // `null` wäre „unbegrenzt", und genau das dürfen diese drei nicht
        // sein (siehe Quota::allowsUnlimited). Kommt es trotzdem vor — ein
        // Plan aus einer Version vor P3, den die Migration nicht erwischt hat —
        // gilt der Vorgabewert des Katalogs und nicht „alles erlaubt".
        return is_numeric($limit)
            ? (int) $limit
            : (int) Quota::from($quota)->default();
    }

    /**
     * Die Deckel, die für ein Abonnement gelten — für die Oberfläche.
     *
     * @return array<string, int|null>
     */
    public function capsFor(Subscription $subscription): array
    {
        $caps = [];

        foreach (array_keys(PhpSettings::ALLOWED) as $key) {
            $caps[$key] = $this->capFor($subscription, $key);
        }

        return $caps;
    }

    /** @return array<string, string> */
    public static function uncapped(): array
    {
        return self::UNCAPPED;
    }

    /** @return array<string, string> */
    public static function caps(): array
    {
        return self::CAPS;
    }

    private function withinCap(Subscription $subscription, string $key, string $value): void
    {
        $cap = $this->capFor($subscription, $key);

        if ($cap === null) {
            return;
        }

        // Die Zahl vor der Einheit. Die Form prüft der Agent; hier wird
        // gerechnet, und ein Wert ohne Zahl ist an dieser Stelle bereits
        // aussortiert.
        if (preg_match('/^(\d+)/', $value, $match) !== 1) {
            throw ValidationException::withMessages([
                'php_settings' => sprintf('%s braucht eine Zahl.', $key),
            ]);
        }

        if ((int) $match[1] > $cap) {
            throw ValidationException::withMessages([
                'php_settings' => sprintf(
                    '%s: Der Plan lässt höchstens %d %s zu.',
                    $key,
                    $cap,
                    $key === 'max_execution_time' ? 'Sekunden' : 'MB',
                ),
            ]);
        }
    }
}
