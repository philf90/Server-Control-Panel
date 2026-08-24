<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WithoutPhpComments;

/**
 * Die Gates lösen über die Rolle auf — und jedes Adminkonto bekommt eine.
 *
 * ## Zwei Regeln, und die zweite ist die gefährlichere
 *
 * **Die erste** ist die Absicht von A9 Schritt 2: Aus
 * `$account->isAdmin()` wird `$account->fulfils($declaration['role'])`. Fällt
 * sie zurück, ist die Trennung wieder wirkungslos — und zwar **still**, weil
 * beide Rollen dann wieder alles dürfen und kein Test über eine Ablehnung
 * stolpert, den es nicht gibt.
 *
 * **Die zweite** ist die, die einen laufenden Server anhält: Seit die Gates
 * über die Rolle auflösen, ist ein Adminkonto **ohne** Rolle wirkungslos.
 * `Account::fulfils()` weist es ab — das ist die sichere Richtung, aber wer ein
 * Konto anlegt und die Rolle vergisst, hat einen Menschen ausgesperrt.
 *
 * > **Eine Änderung, die eine Angabe zur Pflicht macht, muss jede Stelle
 * > mitnehmen, die sie erzeugt — sonst ist der erste neue Datensatz kaputt.**
 *
 * ## Warum das hier steht und nicht im Feature-Test
 *
 * Die **Wirkung** misst `RoleGateTest` an der Tür: Administrator 403, Betreiber
 * 200, Konto ohne Rolle 403. Der braucht Laravel und läuft in der CI.
 *
 * Hier steht, was ohne Framework prüfbar ist — und das ist genau der Teil, der
 * dort, wo `vendor/` fehlt, sonst gar nicht auffiele.
 *
 * > **Zwei Wächter für eine Regel sind keine Verdopplung, wenn der eine die
 * > Wirkung misst und der andere sie dort hält, wo die Wirkung nicht messbar
 * > ist.**
 */
final class RoleResolutionTest extends TestCase
{
    use WithoutPhpComments;

    /**
     * Wo ein Adminkonto entsteht — und jede Stelle setzt eine Rolle.
     *
     * **Die Liste ist die Regel und nicht ihre Abkürzung.** Kommt eine vierte
     * Stelle dazu, ohne hier zu stehen, meldet der Test unten, dass er sie
     * nicht kennt — statt sie stillschweigend zu übergehen.
     *
     * @var list<string>
     */
    private const CREATION_SITES = [
        'app/Console/Commands/CreateAdmin.php',
        'database/factories/AccountFactory.php',
    ];

    /** Die Gates fragen die Rolle und nicht mehr die Ebene. */
    public function test_the_gates_resolve_over_the_role(): void
    {
        $source = $this->withoutComments($this->read('app/Providers/SrvPanelServiceProvider.php'));

        $this->assertStringContainsString('Gate::define(', $source,
            'Es wird kein Gate mehr definiert — dann prüft dieser Test nichts.');

        $this->assertMatchesRegularExpression(
            '/Gate::define\(\s*\$ability,\s*static fn \(Account \$account\): bool => \$account->fulfils\(/',
            $source,
            'Die Gates lösen nicht mehr über fulfils() auf. Mit $account->isAdmin() dürfen beide '
            .'Rollen wieder alles — und zwar still, weil kein Test über eine Ablehnung stolpert, '
            .'die es nicht gibt (docs/82 §2.2).',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/Gate::define\([^;]*\$account->isAdmin\(\)/s',
            $source,
            'Ein Gate löst wieder über die Mandantenachse auf.',
        );
    }

    /**
     * Jede Stelle, die ein Adminkonto anlegt, setzt eine Rolle.
     *
     * Ohne sie entsteht ein Konto, das sich anmelden kann und **nichts darf** —
     * und die Meldung dazu sagt nichts über eine fehlende Rolle.
     */
    public function test_every_place_that_creates_an_admin_sets_a_role(): void
    {
        foreach (self::CREATION_SITES as $relative) {
            $source = $this->withoutComments($this->read($relative));

            $this->assertStringContainsString('AccountType::Admin', $source, sprintf(
                '%s legt kein Adminkonto mehr an — entweder ist die Stelle umgezogen (dann gehört '
                .'die Liste hier berichtigt) oder dieser Test misst dort nichts.',
                $relative,
            ));

            $this->assertStringContainsString('AdminRole::', $source, sprintf(
                '%s legt ein Adminkonto ohne Rolle an. Seit A9 Schritt 2 erfüllt ein solches Konto '
                .'keine Fähigkeit: Es kann sich anmelden und darf nichts.',
                $relative,
            ));
        }
    }

    /**
     * Und keine vierte Stelle legt heimlich ein Adminkonto an.
     *
     * **Der Prüfkörper der Liste oben.** Ohne diese Frage wäre sie eine
     * Aufzählung der Stellen, an die jemand gerade gedacht hat.
     *
     * > **Eine Liste von Fällen ist keine Regel — sie ist eine Aufzählung
     * > dessen, was schon jemand gesehen hat.**
     */
    public function test_no_other_place_creates_an_admin_account(): void
    {
        $root = dirname(__DIR__, 2);
        $strays = [];
        $found = 0;

        foreach ([$root.'/app', $root.'/database'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);
                $source = $this->withoutComments((string) file_get_contents($file->getPathname()));

                /*
                 * **Gesucht ist die Attributzuweisung, nicht der Aufruf.**
                 * Der erste Wurf suchte `create([… AccountType::Admin`, und
                 * die Factory schreibt `return [` — sie legt die Attribute
                 * eines Adminkontos fest, ohne `create` zu heissen. Der
                 * Prüfkörper unten hat das gemeldet.
                 *
                 * > **Ein Ausdruck, der die eine Schreibweise kennt, prüft die
                 * > andere nicht — und die Untergrenze ist das Einzige, was
                 * > das sagt.**
                 *
                 * `where('type', AccountType::Admin)` in `Setup.php` ist eine
                 * Frage und keine Erzeugung; die Form mit `=>` trennt beide.
                 *
                 * **Und der Namensraum darf davorstehen.** Der zweite Wurf
                 * suchte `=> AccountType::Admin` unmittelbar und übersah
                 * `=> \App\Enums\AccountType::Admin` — der Bruch, der eine
                 * vierte Anlegestelle einbaut, kam damit durch. Gefunden hat es
                 * nicht das Nachdenken, sondern der Eingriff.
                 *
                 * > **Ein Wächter, der nur die gewohnte Schreibweise kennt,
                 * > prüft die Gewohnheit und nicht die Regel.**
                 */
                if (preg_match("/'type'\s*=>\s*[\\\\\w]*AccountType::Admin/", $source) !== 1) {
                    continue;
                }

                $found++;

                if (! in_array($relative, self::CREATION_SITES, true)) {
                    $strays[] = $relative;
                }
            }
        }

        $this->assertGreaterThan(1, $found, 'Es wird kaum eine Anlegestelle gefunden — dann prüft dieser Test nichts.');

        $this->assertSame([], $strays, sprintf(
            "Diese Stellen legen ein Adminkonto an und stehen nicht in der Liste:\n\n  %s\n\n"
            .'Jede von ihnen muss eine Rolle setzen — sonst entsteht ein Konto, das sich anmelden '
            .'kann und nichts darf.',
            implode("\n  ", $strays),
        ));
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2).'/'.$relative;

        $this->assertFileExists($path, $relative.' gibt es nicht mehr.');

        return (string) file_get_contents($path);
    }
}
