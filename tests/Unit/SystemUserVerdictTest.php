<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Diagnose\Checks\SystemUsers;
use App\Support\Diagnose\Host;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Der Systembenutzer eines Abonnements (A10 Schritt 5, `docs/98 §3 G`).
 *
 * ## Der Fund, für den dieser Wächter dasteht
 *
 * `docs/98 §3 G` sagt „gehört ihm seine **Wurzel** unter `/var/www/vhosts`" —
 * und die Wurzel gehört absichtlich `root:root`. `SubscriptionProvision::tree()`
 * legt sie so an, weil ihr Zugriffsbit der Schalter ist, mit dem
 * `subscription.suspend` das ganze Abonnement abschaltet. Dem Kunden gehört
 * `httpdocs`.
 *
 * > **Eine Erwartung, die man an ein Verzeichnis aufschreibt, liest vorher
 * > nach, wem die Vorlage es gibt.**
 *
 * Wäre der Plan wörtlich gebaut worden, meldete der Nachtlauf **jedes**
 * Abonnement als `wrong_owner` — jede Nacht, für jeden Kunden.
 *
 * Framework-frei; die Maschine antwortet über {@see Host}.
 */
final class SystemUserVerdictTest extends TestCase
{
    private function host(?int $uid, ?int $owner): Host
    {
        return new class($uid, $owner) implements Host
        {
            /** @var list<string> */
            public array $gefragt = [];

            public function __construct(private readonly ?int $uid, private readonly ?int $owner) {}

            public function uidOf(string $user): ?int
            {
                return $this->uid;
            }

            public function ownerOf(string $path): ?int
            {
                $this->gefragt[] = $path;

                return $this->owner;
            }

            public function cronFiles(): array
            {
                return [];
            }
        };
    }

    public function test_a_healthy_subscription_yields_nothing(): void
    {
        $this->assertNull(SystemUsers::judge('kunde.invalid', 'p1000', $this->host(1000, 1000)));
    }

    public function test_a_missing_account_is_named_before_anything_else(): void
    {
        $host = $this->host(null, 1000);

        $verdict = SystemUsers::judge('kunde.invalid', 'p1000', $host);

        $this->assertSame('missing', $verdict['reason'] ?? null);
        $this->assertSame('p1000', $verdict['detail'] ?? null);
        $this->assertSame([], $host->gefragt, 'Ohne Konto gibt es keine uid, mit der sich ein Eigentümer vergleichen liesse.');
    }

    public function test_a_missing_document_root_is_its_own_reason(): void
    {
        $verdict = SystemUsers::judge('kunde.invalid', 'p1000', $this->host(1000, null));

        $this->assertSame('root_missing', $verdict['reason'] ?? null);
        $this->assertStringContainsString('httpdocs', (string) ($verdict['detail'] ?? ''));
    }

    public function test_a_foreign_owner_names_both_sides(): void
    {
        $verdict = SystemUsers::judge('kunde.invalid', 'p1000', $this->host(1000, 33));

        $this->assertSame('wrong_owner', $verdict['reason'] ?? null);
        $this->assertStringContainsString('uid 33', (string) ($verdict['detail'] ?? ''));
        $this->assertStringContainsString('uid 1000', (string) ($verdict['detail'] ?? ''));
    }

    /**
     * Gefragt wird das Dokumentenverzeichnis und nicht die Wurzel.
     *
     * Der eigentliche Wächter dieses Falls: Die Wurzel gehört `root`, und wer
     * sie fragte, meldete jedes Abonnement.
     */
    public function test_the_question_goes_to_the_document_root(): void
    {
        $host = $this->host(1000, 1000);
        SystemUsers::judge('kunde.invalid', 'p1000', $host);

        $erwartet = SubscriptionProvision::VHOSTS.'/kunde.invalid/'.SubscriptionProvision::DOCUMENT_ROOT;

        $this->assertSame([$erwartet], $host->gefragt);
        $this->assertNotSame(SubscriptionProvision::VHOSTS.'/kunde.invalid', $host->gefragt[0], 'Die Wurzel gehört root:root — sie zu fragen meldete jedes Abonnement.');
    }
}
