<?php

declare(strict_types=1);

namespace App\Support\Passwords;

use Illuminate\Validation\Rules\Password as LaravelPassword;

/**
 * Die Passwortrichtlinie des Panels — an einer Stelle.
 *
 * **Warum das eine eigene Klasse ist.** Vorher stand die Regel als
 * `'min:12'` im CustomerController und als `mb_strlen($password) < 12` im
 * Kommando `srvpanel:admin`, und in der Oberfläche stand darunter der Satz
 * „Mindestens zwölf Zeichen." Drei Stellen, dieselbe Zahl, keine Verbindung.
 * Wer die Richtlinie verschärft, ändert zwei davon und übersieht die dritte —
 * und die dritte ist die, die der Benutzer liest.
 *
 * Jetzt kommt alles von hier: die Validierungsregeln, der Text der
 * Kommandozeile und — über Inertia — die Prüfliste im Browser. Was die
 * Oberfläche anzeigt, ist damit nicht mehr eine Behauptung über die
 * Validierung, sondern dieselbe Liste.
 *
 * **Was nicht geprüft wird.** Kein Abgleich gegen bekannte Leaks. Laravels
 * `uncompromised()` fragt dafür die API von haveibeenpwned an — ein Panel, das
 * beim Anlegen eines Kunden auf eine fremde Website wartet, ist ein Panel, das
 * ohne Internetzugang keine Kunden anlegt. Die Abwägung ist bewusst und
 * gehört in die Dokumentation, nicht in einen Kommentar: docs/22.
 */
final class Policy
{
    public const MINIMUM_LENGTH = 12;

    /**
     * Die Anforderungen, wie sie in der Oberfläche als Prüfliste erscheinen.
     *
     * Der Schlüssel ist der Vertrag zur Oberfläche: Die Vue-Komponente bildet
     * ihn auf eine Prüfung im Browser ab. Kommt hier einer dazu, den sie nicht
     * kennt, schlägt `PasswordPolicyTest` an — sonst stünde im Browser eine
     * Anforderung ohne Haken, die sich nie erfüllen ließe.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function requirements(): array
    {
        return [
            ['key' => 'length', 'label' => 'Mindestens '.self::MINIMUM_LENGTH.' Zeichen'],
            ['key' => 'lowercase', 'label' => 'Ein Kleinbuchstabe'],
            ['key' => 'uppercase', 'label' => 'Ein Großbuchstabe'],
            ['key' => 'digit', 'label' => 'Eine Ziffer'],
            ['key' => 'symbol', 'label' => 'Ein Sonderzeichen'],
        ];
    }

    /**
     * Die Regeln für `validate()`.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return [
            'string',
            // Die Obergrenze ist keine Schikane, sondern eine Schranke gegen
            // ein Passwort, dessen Hashen den Prozess minutenlang beschäftigt.
            'max:1024',
            LaravelPassword::min(self::MINIMUM_LENGTH)->letters()->mixedCase()->numbers()->symbols(),
        ];
    }

    /** Erfüllt eine Zeichenkette die Richtlinie? Für die Kommandozeile. */
    public static function satisfied(string $password): bool
    {
        return self::unmet($password) === [];
    }

    /**
     * Welche Anforderungen ein Passwort nicht erfüllt — als Beschriftungen.
     *
     * @return list<string>
     */
    public static function unmet(string $password): array
    {
        $checks = [
            'length' => mb_strlen($password) >= self::MINIMUM_LENGTH,
            'lowercase' => preg_match('/\p{Ll}/u', $password) === 1,
            'uppercase' => preg_match('/\p{Lu}/u', $password) === 1,
            'digit' => preg_match('/\p{Nd}/u', $password) === 1,
            'symbol' => preg_match('/[^\p{L}\p{Nd}]/u', $password) === 1,
        ];

        $unmet = [];

        foreach (self::requirements() as $requirement) {
            if (($checks[$requirement['key']] ?? false) === false) {
                $unmet[] = $requirement['label'];
            }
        }

        return $unmet;
    }

    /**
     * Ein Passwort, das die Richtlinie erfüllt.
     *
     * Für `srvpanel:admin --generate`. Der Knopf in der Oberfläche erzeugt
     * seines im Browser: Ein Passwort, das der Server erzeugt und über das
     * Netz schickt, steht in jedem Puffer dazwischen.
     */
    public static function generate(int $length = 24): string
    {
        $length = max(self::MINIMUM_LENGTH, $length);

        $groups = [
            'abcdefghijkmnopqrstuvwxyz',
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            '23456789',
            '!@#$%^&*-_=+?',
        ];

        // Aus jeder Gruppe eines, damit das Ergebnis die eigene Richtlinie
        // erfüllt — sonst erzeugt der Zufall gelegentlich ein Passwort, das
        // die Validierung des nächsten Schritts ablehnt.
        $characters = [];

        foreach ($groups as $group) {
            $characters[] = $group[random_int(0, strlen($group) - 1)];
        }

        $pool = implode('', $groups);

        while (count($characters) < $length) {
            $characters[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        // Ohne Mischen stünden die vier Pflichtzeichen immer vorn — und ein
        // Passwort, dessen erste vier Stellen ihre Zeichenklasse verraten,
        // ist um genau diese Information schwächer.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }
}
