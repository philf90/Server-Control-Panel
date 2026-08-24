<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AdminRole;
use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Zwei Rollen, eine Rangfolge — und die Rolle allein gewährt nichts.
 *
 * ## Was hier gehalten wird
 *
 * `docs/82 §2.1` und `docs/20 §6.1`: Die Admin-Ebene hat genau **zwei** Rollen,
 * der Betreiber deckt den Administrator, und beide Achsen bleiben getrennt.
 *
 * Die Rangfolge steht in {@see AdminRole::covers()} und nicht in jedem Gate.
 * Sie ist als Fallunterscheidung geschrieben und nicht als Zahl: Ein Rang wie
 * `level >= 2` verführt zum dritten Wert, und der wäre eine dritte Rolle ohne
 * Entscheidung darüber, was sie darf.
 *
 * ## Und die Regel, die dieser Wächter nur zur Hälfte prüfen kann
 *
 * `Account::isOperator()` und `Account::fulfils()` fragen **zwei** Achsen: die
 * Ebene (`type->isAdmin()`) **und** die Rolle. Ein Kundenkonto, das durch einen
 * Fehler `operator` trüge, ist damit trotzdem keiner.
 *
 * Diese Wirkung braucht ein Model und damit Laravel — sie steht als
 * `RoleAxisTest` unter `tests/Feature` und läuft in der CI. Was hier geprüft
 * wird, ist die **Erreichbarkeit** der Ebenenfrage im Quelltext: Genau ihr
 * Wegfall ist der Fehler, der eine stille Vollmacht erzeugt, und er wäre sonst
 * dort, wo `vendor/` fehlt, gar nicht zu bemerken.
 *
 * > **Zwei Wächter für eine Regel sind keine Verdopplung, wenn der eine die
 * > Wirkung misst und der andere sie dort hält, wo die Wirkung nicht messbar
 * > ist.**
 */
final class AdminRoleTest extends TestCase
{
    use WithoutPhpComments;

    /** Genau zwei Rollen — nicht drei, und keine Matrix. */
    public function test_the_enum_carries_exactly_two_roles(): void
    {
        $this->assertSame(
            ['operator', 'administrator'],
            array_map(static fn (AdminRole $case): string => $case->value, AdminRole::cases()),
            'AdminRole hat einen Fall bekommen oder verloren. Zwei feste Rollen sind eine '
            .'Entscheidung mit Begründung (docs/82 §4): Die Trennlinie ist eine '
            .'Sicherheitszusage, und ein Baukasten müsste in jeder Kombination stimmen. '
            .'Wer eine dritte will, entscheidet vorher, was sie darf.',
        );
    }

    /**
     * Der Betreiber deckt den Administrator — und nicht umgekehrt.
     *
     * **Beide Richtungen, und das ist der Punkt.** Eine Prüfung, die nur
     * „Betreiber darf alles" belegt, bestünde auch für eine Rangfolge, in der
     * jeder alles darf.
     */
    public function test_the_operator_covers_the_administrator_and_not_the_other_way(): void
    {
        $this->assertTrue(AdminRole::Operator->covers(AdminRole::Operator));
        $this->assertTrue(AdminRole::Operator->covers(AdminRole::Administrator));

        $this->assertTrue(AdminRole::Administrator->covers(AdminRole::Administrator));
        $this->assertFalse(
            AdminRole::Administrator->covers(AdminRole::Operator),
            'Der Administrator deckt den Betreiber — dann ist die Trennung eine Zierde.',
        );
    }

    /** Jede Rolle trägt ihr Wort für die Oberfläche, und keines ist leer. */
    public function test_every_role_carries_a_german_label(): void
    {
        foreach (AdminRole::cases() as $case) {
            $this->assertNotSame('', trim($case->label()), $case->value.' hat keine Beschriftung.');
            $this->assertNotSame($case->value, $case->label(), $case->value.' zeigt seinen Bezeichner.');
        }
    }

    /**
     * `isOperator()` und `fulfils()` fragen die Ebene mit.
     *
     * **Die Richtung entscheidet.** Fiele die Rollenfrage weg, wäre jeder Admin
     * Betreiber — das fiele sofort auf. Fällt die **Ebenenfrage** weg, genügt
     * ein Kundenkonto mit `role = 'operator'` in der Datenbank, und das fällt
     * niemandem auf, weil es dort normalerweise nicht steht.
     *
     * > **Ein Fehler, der eine Vollmacht erzeugt, muss nicht wahrscheinlich
     * > sein, um teuer zu werden.**
     */
    public function test_both_account_methods_ask_the_tenancy_axis(): void
    {
        $source = $this->withoutComments(
            (string) file_get_contents(dirname(__DIR__, 2).'/app/Models/Account.php'),
        );

        foreach (['isOperator', 'fulfils'] as $method) {
            $this->assertMatchesRegularExpression(
                '/function '.$method.'\([^)]*\): bool\s*\{[^}]*type->isAdmin\(\)/',
                $source,
                sprintf(
                    'Account::%s() fragt die Mandantenachse nicht mehr. Damit genügte ein '
                    .'Kundenkonto mit role = operator — und die Rolle allein soll nichts '
                    .'gewähren (docs/82 §2.1).',
                    $method,
                ),
            );
        }
    }

    /**
     * Die Migration macht bestehende Adminkonten zu Betreibern.
     *
     * **`docs/82 §5.1`, und es ist die eine Zeile, die einen laufenden Server
     * betrifft.** Ein Adminkonto als Administrator zu migrieren wäre eine
     * stille Rechteentziehung: Der Betreiber käme am Montag nicht mehr an seine
     * Mailkonfiguration, und die Meldung dazu sagte nichts über eine Migration.
     *
     * > **Eine Migration, die Rechte wegnimmt, sperrt jemanden aus, der gestern
     * > noch hineinkam.**
     */
    public function test_the_migration_makes_existing_admins_operators(): void
    {
        $files = glob(dirname(__DIR__, 2).'/database/migrations/*add_admin_role_to_accounts.php') ?: [];

        $this->assertCount(1, $files, 'Die Migration der Rolle gibt es nicht mehr — oder zweimal.');

        $source = $this->withoutComments((string) file_get_contents($files[0]));

        $this->assertStringContainsString("->where('type', 'admin')", $source,
            'Die Migration setzt die Rolle nicht mehr nur an Adminkonten.');
        $this->assertStringContainsString('AdminRole::Operator->value', $source,
            'Die Migration setzt bestehende Adminkonten nicht mehr auf Betreiber.');

        // Und die Spalte darf keine Vorgabe tragen: `null` bedeutet an einem
        // Kundenkonto „kein Admin", und eine Vorgabe nähme dieser Null ihre
        // Bedeutung.
        $this->assertStringNotContainsString("->default('operator')", $source,
            'Die Spalte trägt eine Vorgabe — dann bedeutet null nichts mehr.');
    }
}
