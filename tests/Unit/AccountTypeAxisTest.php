<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AccountType;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * `AccountType` beantwortet die Mandantenfrage und trägt keine Verwaltungsrolle.
 *
 * **Ein Stolperdraht, kein Prüfer.** Er belegt nichts über das Verhalten des
 * Enums — er wird rot, wenn jemand einen Fall hinzufügt, und schickt ihn zum
 * Lesen. Das ist hier die richtige Form, weil der Schaden sonst still einträte.
 *
 * **Der Schaden.** `isAdmin()` und `belongsToCustomer()` sind als *Gleichheit
 * mit einem Fall* geschrieben und nicht als Zugehörigkeit zu einer Menge. Ein
 * vierter Fall — etwa `Superadmin` für die zweite Verwaltungsrolle aus
 * `docs/74 §11` — wäre damit augenblicklich `isAdmin() === false` und
 * `belongsToCustomer() === true`, an über fünfzig Stellen in `app/` und
 * `routes/`. Die Mandantenklammer setzte ihn auf `whereRaw('0 = 1')`, weil er
 * keinen Kunden hat.
 *
 * Es fiele zur sicheren Seite, und genau das macht es teuer:
 *
 * > **Ein Fehler, der zur sicheren Seite fällt, fällt trotzdem — und er fällt
 * > leise.** Der neue Betreiber sähe eine leere Kundenliste, und niemand käme
 * > auf einen Enum-Fall als Ursache.
 *
 * **Warum die Form der Methoden mitgeprüft wird und nicht nur die Zahl der
 * Fälle.** Ein Wächter über die Vollständigkeit sagt nichts über die
 * Richtigkeit — der Satz steht seit `docs/66` in `CLAUDE.md`. Würde jemand
 * `isAdmin()` auf `in_array($this, [self::Admin, self::Superadmin], true)`
 * umstellen, wäre der vierte Fall unbedenklich, und der Stolperdraht dürfte
 * ihn nicht länger verbieten. Deshalb hängt die Regel an **beidem**: solange
 * die Methoden gegen genau einen Fall vergleichen, bleibt die Zahl der Fälle
 * bei drei.
 */
final class AccountTypeAxisTest extends TestCase
{
    /**
     * Die drei Ebenen aus §6.1 des Plans. Wer hier etwas ergänzt, liest
     * vorher `docs/74 §11` — und trägt die neue Rolle woanders ein.
     */
    private const KNOWN = ['admin', 'customer', 'additional'];

    public function test_the_enum_carries_exactly_the_three_tenancy_levels(): void
    {
        $cases = array_map(
            static fn (AccountType $case): string => $case->value,
            AccountType::cases(),
        );

        sort($cases);
        $known = self::KNOWN;
        sort($known);

        $this->assertSame(
            $known,
            $cases,
            'AccountType hat einen Fall bekommen. Das ist die Mandantenachse — '
            .'eine Verwaltungsrolle gehört nicht hierher, weil isAdmin() und '
            .'belongsToCustomer() gegen genau einen Fall vergleichen und der '
            .'neue Fall damit still zum Kunden würde. Siehe docs/74 §11.',
        );
    }

    /**
     * Die Begründung des Stolperdrahts, nachgerechnet statt geglaubt.
     *
     * Steht in einer der beiden Methoden kein Vergleich gegen genau einen Fall
     * mehr, ist die Regel oben hinfällig — dann meldet dieser Wächter, dass er
     * selbst überholt ist, statt eine Ordnung durchzusetzen, die es nicht mehr
     * braucht.
     */
    public function test_both_methods_still_compare_against_a_single_case(): void
    {
        $datei = (new ReflectionEnum(AccountType::class))->getFileName();

        $this->assertIsString($datei);

        $quelle = file_get_contents($datei);

        $this->assertIsString($quelle);

        foreach (['isAdmin' => '===', 'belongsToCustomer' => '!=='] as $methode => $operator) {
            $this->assertStringContainsString(
                sprintf('return $this %s self::Admin;', $operator),
                $quelle,
                sprintf(
                    '%s() vergleicht nicht mehr gegen genau einen Fall. Wenn das '
                    .'Absicht war, ist der Stolperdraht über die Zahl der Fälle '
                    .'überholt und gehört mit derselben Änderung entfernt.',
                    $methode,
                ),
            );
        }
    }
}
