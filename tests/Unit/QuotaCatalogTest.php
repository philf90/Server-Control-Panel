<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Permission;
use App\Support\Plans\Feature;
use App\Support\Plans\Quota;
use App\Support\Plans\Quotas;
use PHPUnit\Framework\TestCase;

/**
 * Der Katalog gegen sich selbst und gegen alles, was ihn beim Namen nennt.
 *
 * Das wiederkehrende Muster in diesem Projekt ist die Zeichenkette, die auf
 * etwas zeigt, ohne dass irgendwer die Verbindung prüft — ein Dateiname in
 * einem Test, ein Kommandoname in einem Wrapper, ein Kontingentschlüssel in
 * einer Factory. Sie fällt nie beim Übersetzen auf und fast nie im Betrieb;
 * sie liefert einfach nichts, und „nichts" sieht bei einem Kontingent aus wie
 * „unbegrenzt".
 *
 * Dieser Test ist die Gegenprobe in beide Richtungen: Jeder Eintrag im Katalog
 * ist vollständig, und jede Stelle, die einen Schlüssel nennt, nennt einen, den
 * es gibt.
 */
final class QuotaCatalogTest extends TestCase
{
    public function test_every_quota_is_described(): void
    {
        foreach (Quota::cases() as $quota) {
            $this->assertNotSame('', $quota->label(), $quota->value.' braucht eine Beschriftung.');
            $this->assertNotSame('', $quota->hint(), $quota->value.' braucht einen Hinweis, was es bewirkt.');
            $this->assertLessThan(
                $quota->maximum(),
                $quota->minimum(),
                $quota->value.': das Minimum liegt nicht unter dem Maximum.',
            );
        }
    }

    public function test_every_feature_is_described(): void
    {
        foreach (Feature::cases() as $feature) {
            $this->assertNotSame('', $feature->label());
            $this->assertNotSame('', $feature->hint());

            // Die Kurzform steht in der Planliste. Ist sie so lang wie die
            // Beschriftung, hat sie ihren Zweck verfehlt und die Spalte läuft
            // wieder über zwei Zeilen.
            $this->assertLessThan(16, mb_strlen($feature->short()), $feature->value.': die Kurzform ist keine.');
        }
    }

    public function test_every_default_is_a_value_the_form_would_accept(): void
    {
        // Ein Vorgabewert, den die eigene Prüfung ablehnt, ist der Fehler, den
        // niemand sucht: Das Formular öffnet sich, sieht richtig aus und lässt
        // sich nicht speichern.
        foreach (Quota::cases() as $quota) {
            $default = $quota->default();

            if ($quota->isSelection()) {
                $this->assertIsArray($default);
                $this->assertNotSame([], $default, $quota->value.': die Vorauswahl ist leer.');

                foreach ($default as $version) {
                    $this->assertContains($version, Quota::PHP_VERSIONS, $quota->value.": {$version} steht nicht im Katalog.");
                }

                continue;
            }

            $this->assertIsInt($default);
            $this->assertGreaterThanOrEqual($quota->minimum(), $default, $quota->value.': der Vorgabewert liegt unter dem Minimum.');
            $this->assertLessThanOrEqual($quota->maximum(), $default, $quota->value.': der Vorgabewert liegt über dem Maximum.');
        }
    }

    public function test_the_two_shared_resources_have_no_unlimited(): void
    {
        // Nicht „irgendwelche zwei", sondern genau diese: Speicherplatz und
        // FPM-Prozesse teilen sich eine Ressource, die der ganze Server teilt.
        // Kommt eine dritte dazu, soll dieser Test dazu zwingen, den Grund
        // aufzuschreiben.
        $bounded = array_values(array_filter(
            Quota::cases(),
            static fn (Quota $quota): bool => ! $quota->allowsUnlimited(),
        ));

        $this->assertSame([Quota::DiskMb, Quota::FpmProcesses], $bounded);
    }

    public function test_the_defaults_carry_exactly_the_catalog_keys(): void
    {
        $this->assertSame(Quota::keys(), array_keys(Quotas::defaults()));
        $this->assertSame(Feature::keys(), array_keys(Quotas::featureDefaults()));
    }

    public function test_normalize_fills_what_is_missing(): void
    {
        $normalized = Quotas::normalize([]);

        $this->assertSame(Quota::keys(), array_keys($normalized));

        // Was unbegrenzt sein darf, ist es; was nicht, bekommt den Vorgabewert
        // und nicht `null`.
        $this->assertNull($normalized[Quota::Databases->value]);
        $this->assertSame(Quota::DiskMb->default(), $normalized[Quota::DiskMb->value]);
    }

    public function test_normalize_drops_what_does_not_belong(): void
    {
        $normalized = Quotas::normalize(['mailboxes' => 25, Quota::Domains->value => 3]);

        $this->assertArrayNotHasKey('mailboxes', $normalized);
        $this->assertSame(3, $normalized[Quota::Domains->value]);
    }

    public function test_normalize_holds_a_value_inside_its_bounds(): void
    {
        $normalized = Quotas::normalize([
            Quota::DiskMb->value => 1,
            Quota::Domains->value => 999_999,
        ]);

        $this->assertSame(Quota::DiskMb->minimum(), $normalized[Quota::DiskMb->value]);
        $this->assertSame(Quota::Domains->maximum(), $normalized[Quota::Domains->value]);
    }

    public function test_php_versions_come_back_sorted_and_clean(): void
    {
        $normalized = Quotas::normalize([Quota::PhpVersions->value => ['8.4', '5.6', '8.1']]);

        $this->assertSame(['8.1', '8.4'], $normalized[Quota::PhpVersions->value]);
    }

    public function test_the_rules_cover_every_key(): void
    {
        $rules = Quotas::rules();

        foreach (Quota::cases() as $quota) {
            $this->assertArrayHasKey('quotas.'.$quota->value, $rules, $quota->value.' hat keine Prüfregel.');
        }

        foreach (Feature::cases() as $feature) {
            $this->assertArrayHasKey('features.'.$feature->value, $rules, $feature->value.' hat keine Prüfregel.');
        }
    }

    public function test_unlimited_is_only_nullable_where_it_is_allowed(): void
    {
        $rules = Quotas::rules();

        foreach (Quota::cases() as $quota) {
            if ($quota->isSelection()) {
                continue;
            }

            $rule = $rules['quotas.'.$quota->value];

            $this->assertSame(
                $quota->allowsUnlimited(),
                in_array('nullable', $rule, true),
                $quota->value.': Prüfregel und Katalog widersprechen sich bei „unbegrenzt".',
            );
        }
    }

    public function test_every_feature_names_a_permission_that_exists(): void
    {
        // Die Zuordnung stand bis August 2026 als `match` mit vier
        // Zeichenketten in der SubscriptionPolicy. Sie steht jetzt hier — und
        // ein Recht, das es nicht mehr gibt, scheitert beim Übersetzen statt
        // eine Funktion stillschweigend für alle freizugeben.
        foreach (Feature::cases() as $feature) {
            $permission = $feature->permission();

            $this->assertNotNull(Permission::tryFrom($permission->value));
            $this->assertSame($feature, Feature::forPermission($permission));
        }
    }

    public function test_a_permission_without_a_feature_has_none(): void
    {
        // Die Gegenrichtung: Was keiner Freigabe zugeordnet ist, ist keine
        // planabhängige Funktion. Dateien lesen gehört zu jedem Abonnement.
        $this->assertNull(Feature::forPermission(Permission::FilesRead));
    }

    public function test_format_says_unlimited_only_once(): void
    {
        $this->assertSame('unbegrenzt', Quotas::format(Quota::Databases, null));
        $this->assertSame('5.120 MB', Quotas::format(Quota::DiskMb, 5120));
        $this->assertSame('0', Quotas::format(Quota::Databases, 0));
        $this->assertSame('8.3, 8.4', Quotas::format(Quota::PhpVersions, ['8.3', '8.4']));
        $this->assertSame('keine', Quotas::format(Quota::PhpVersions, []));
    }
}
