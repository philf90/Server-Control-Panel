<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Databases\DbLifecycle;
use App\Support\Databases\PgLifecycle;
use PHPUnit\Framework\TestCase;

/**
 * Kein Lebenslauf fasst die Zeilen des anderen Systems an.
 *
 * **Die Regel entstand mit Schritt 5, und sie ist genau die Sorte, die still
 * bricht.** Beide Lebensläufe hören auf `subscription.suspend` und
 * `subscription.resume` — ein gesperrtes Abonnement soll seine Zugänge in
 * *jedem* System verlieren. Die Trennung liegt deshalb nicht in der Aufgabe,
 * sondern in `engine`.
 *
 * **Was ohne sie geschähe, ist zweierlei und beides unangenehm.** Beim
 * Einreihen gingen PostgreSQL-Rollen als `name@host` an `db.user.lock`, und der
 * Agent wiese den ganzen Vorgang ab — ein gesperrtes Abonnement behielte seine
 * MariaDB-Zugänge. Beim Nachziehen schrieben zwei Vorgänge denselben Zustand,
 * und der zweite gewänne; sichtbar würde es erst, wenn einer scheitert, und
 * dann stünde ein Zugang als gesperrt da, den niemand gesperrt hat.
 *
 * `DbLifecycle` hat die Zeile bis Schritt 5 nicht gebraucht, weil es nur ein
 * System gab. Das ist der Grund, warum dieser Wächter nicht früher entstehen
 * konnte — und der, warum er jetzt gebraucht wird.
 */
final class EngineScopeTest extends TestCase
{
    /**
     * Die beiden Dateien, um die es geht.
     *
     * @return array<string, string>
     */
    public static function lifecycles(): array
    {
        return [
            'DbLifecycle' => 'app/Support/Databases/DbLifecycle.php',
            'PgLifecycle' => 'app/Support/Databases/PgLifecycle.php',
        ];
    }

    /**
     * Jede Abfrage über ein Abonnement nennt auch das System.
     *
     * **Gesucht wird `subscription_id` und nicht `DbUser`**, weil es um die
     * Richtung geht: Wer alle Zeilen eines Abonnements holt, holt seit Schritt 4
     * beide Systeme. Eine Abfrage über einen einzelnen Gegenstand — `find()` auf
     * eine Datenbank, die der Vorgang nennt — braucht die Einschränkung nicht,
     * weil sie schon eine Zeile meint.
     */
    public function test_every_subscription_wide_query_names_its_engine(): void
    {
        $befunde = [];
        $gefunden = 0;

        foreach (self::lifecycles() as $name => $pfad) {
            $zeilen = file(dirname(__DIR__, 2).'/'.$pfad) ?: [];

            foreach ($zeilen as $nummer => $zeile) {
                if (! str_contains($zeile, "->where('subscription_id'")) {
                    continue;
                }

                $gefunden++;

                // Die Einschränkung steht in einer der nächsten drei Zeilen —
                // dazwischen liegt höchstens ein `orderBy`.
                $fenster = implode('', array_slice($zeilen, $nummer, 3));

                if (str_contains($fenster, "->where('engine'")) {
                    continue;
                }

                $befunde[] = sprintf('%s:%d', $pfad, $nummer + 1);
            }
        }

        // Die Untergrenze zählt, wo die Regel stehen *darf*: Wer Abfragen
        // zusammenlegt, soll hier kein Rot bekommen — ein Ausdruck, der nichts
        // findet, aber schon.
        $this->assertGreaterThanOrEqual(
            4,
            $gefunden,
            'Es werden kaum Abfragen gefunden — dann prüft dieser Test nichts.',
        );

        $this->assertSame([], $befunde, sprintf(
            "Diese Abfragen holen alle Zugänge eines Abonnements, ohne das System zu nennen:\n  %s\n\n".
            'Seit Schritt 4 sind darunter beide. Ein Lebenslauf, der die Zeilen des anderen Systems '.
            'anfasst, schickt entweder Rollennamen an MariaDB oder überschreibt einen Zustand, den '.
            'ein anderer Vorgang gerade gesetzt hat.',
            implode("\n  ", $befunde),
        ));
    }

    /**
     * Und beide beantworten die Sperre — sonst gilt sie nur halb.
     *
     * Die Gegenrichtung: Fiele einer der beiden aus der Liste, bliebe ein
     * gesperrtes Abonnement in seinem System offen. Das fiele niemandem auf,
     * weil der Vorgang grün ist.
     */
    public function test_both_lifecycles_answer_the_suspension(): void
    {
        foreach ([DbLifecycle::class, PgLifecycle::class] as $lifecycle) {
            $this->assertContains('subscription.suspend', $lifecycle::handles(), $lifecycle.' sperrt nicht mit.');
            $this->assertContains('subscription.resume', $lifecycle::handles(), $lifecycle.' gibt nicht mit frei.');
        }
    }
}
