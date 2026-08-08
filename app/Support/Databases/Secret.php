<?php

declare(strict_types=1);

namespace App\Support\Databases;

/**
 * Das Passwort eines Datenbankbenutzers — erzeugt, einmal gezeigt, vergessen.
 *
 * **Entscheidung 3 des Betreibers vom 7. August 2026** (`docs/36 §4`): Es liegt
 * nirgends. Nicht im Panel, nicht im Agenten. Der Massstab dafür ist das
 * siebte Kriterium aus dem Abnahmelauf von P4 — *„und das DNS-Token steht
 * nirgends"*. Ein Geheimnis, das man nicht aufbewahrt, kann man nicht
 * verlieren.
 *
 * Zur Wahl standen zwei bequemere Wege, und beide bezahlen ihre Bequemlichkeit
 * mit einer Ablage, die es vorher nicht gab: eine `encrypted`-Spalte im Panel
 * (dann enthält jede Sicherung der Panel-Datenbank die Datenbankpasswörter
 * aller Kunden, und der `APP_KEY` liegt auf demselben Server) oder eine Datei
 * im Agenten wie bei den DNS-Zugangsdaten (kauft im Wesentlichen nur Adminer,
 * und der ist aufgeschoben).
 *
 * **Der Preis ist ehrlich zu nennen:** Wer sein Passwort verliert, setzt es
 * zurück und trägt es in seine Anwendung ein. Das ist eine Handlung mehr als
 * bei Plesk.
 *
 * **Der Kunde wählt es nicht.** Das ist keine Bevormundung, sondern die
 * Abkürzung um eine ganze Klasse von Fragen herum: ein selbst gewähltes
 * Passwort müsste durch eine Prüfregel, durch eine Maskierung in der
 * SQL-Anweisung und durch die Syntax einer Optionsdatei. 32 Zeichen aus 62 sind
 * rund 190 Bit — mehr, als eine Richtlinie erzwingen würde.
 */
final class Secret
{
    /**
     * Wie lang.
     *
     * Dieselbe Länge wie `PanelProvision::secret()`, und derselbe Grund für das
     * Alphabet: Das Passwort steht später in einer SQL-Anweisung und in einer
     * Optionsdatei von MariaDB. Zeichen, die in einer der beiden Bedeutung
     * haben — `'`, `\`, `#`, ein führendes Leerzeichen —, sind hier kein Gewinn
     * an Stärke, sondern eine Fehlerquelle.
     */
    public const LENGTH = 32;

    public function generate(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($alphabet) - 1;
        $secret = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            // `random_int` und nicht `mt_rand`: Der Unterschied ist ein
            // Zufallsgenerator, dessen Zustand sich aus wenigen Ausgaben
            // rekonstruieren lässt, und einer, dessen nicht.
            $secret .= $alphabet[random_int(0, $max)];
        }

        return $secret;
    }
}
