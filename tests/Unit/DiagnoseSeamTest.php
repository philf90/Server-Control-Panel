<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\FindingCheck;
use App\Support\Diagnose\Checks\Certificates;
use App\Support\Diagnose\Checks\ManagedBlocks;
use App\Support\Diagnose\Checks\Orphans;
use App\Support\Diagnose\Checks\SystemUsers;
use App\Support\Diagnose\Checks\Units;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Diagnose\Verdict;
use SrvPanel\Agent\Ops\SystemDiagnose;

/**
 * Die Naht zwischen dem, was der Agent ausspricht, und dem, was das Panel kennt.
 *
 * `FindingLog::replace()` wirft für einen Grund, den die Prüfung nicht kennt —
 * absichtlich, denn er kommt aus dem Code und nie von aussen. Aber der Code,
 * aus dem er kommt, ist hier der **Agent**, und der kennt den Katalog des
 * Panels nicht. Läuft das auseinander, wirft der Nachtlauf, und zwar nachts.
 *
 * Dieselbe Bauart wie `PhpSourceUriTest`: Eine Naht, die man nicht hält,
 * reisst still.
 *
 * Framework-frei — beide Seiten sind reine Aufzählungen.
 */
final class DiagnoseSeamTest extends TestCase
{
    public function test_every_reason_the_agent_speaks_is_known_to_the_panel(): void
    {
        $geprueft = 0;

        foreach (Verdict::REASONS as $key => $reasons) {
            $check = FindingCheck::from($key);

            foreach ($reasons as $reason) {
                $this->assertArrayHasKey($reason, $check->reasons(), sprintf(
                    'Der Agent spricht %s/%s aus, und das Panel kennt den Grund nicht — FindingLog würde nachts werfen.',
                    $key,
                    $reason,
                ));
                $geprueft++;
            }
        }

        $this->assertGreaterThanOrEqual(15, $geprueft);
    }

    /** Die Schlüssel, die die Operation annimmt, sind genau die, für die es Urteile gibt. */
    public function test_the_operation_accepts_exactly_the_keys_with_verdicts(): void
    {
        $a = SystemDiagnose::CHECKS;
        $b = array_keys(Verdict::REASONS);
        sort($a);
        sort($b);

        $this->assertSame($b, $a);
    }

    /** Und `unreachable` heisst auf beiden Seiten dasselbe. */
    public function test_unreachable_is_the_same_word_on_both_sides(): void
    {
        $this->assertSame(FindingCheck::UNREACHABLE, Verdict::UNREACHABLE);
    }

    /**
     * Und dieselbe Frage für die Prüfungen, die im Panel laufen (Schritt 5).
     *
     * Sie gehen nicht über den Agenten und damit auch nicht über
     * {@see Verdict::REASONS}; ihre Gründe stehen je Klasse als `REASONS`. Ohne
     * diese Richtung wäre ein vertippter Grund erst nachts aufgefallen — als
     * Ausnahme aus {@see FindingCheck::state()}, in einem Lauf, den niemand
     * sieht.
     */
    public function test_every_reason_a_panel_check_speaks_is_known(): void
    {
        $geprueft = 0;

        foreach (self::PANEL_REASONS as $klasse => $reasons) {
            foreach ($reasons as $key => $liste) {
                $check = FindingCheck::from($key);

                foreach ($liste as $reason) {
                    $this->assertArrayHasKey($reason, $check->reasons(), sprintf(
                        '%s spricht %s/%s aus, und der Katalog kennt den Grund nicht.',
                        $klasse,
                        $key,
                        $reason,
                    ));
                    $geprueft++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(9, $geprueft);
    }

    /**
     * Die Gegenrichtung: Jeder Grund im Katalog hat einen Sprecher.
     *
     * **So entsteht ein toter Eintrag wirklich** — bei einer Umbenennung trägt
     * man den neuen Grund nach, die erste Richtung ist wieder grün, und der
     * alte bleibt liegen. Auf der Oberfläche wird daraus eine Zeile, die nie
     * jemand zu sehen bekommt, und in `FindingCheck` ein Satz, den niemand
     * pflegt.
     */
    public function test_every_reason_in_the_catalogue_has_a_speaker(): void
    {
        $gesprochen = [];

        foreach (Verdict::REASONS as $key => $reasons) {
            foreach ($reasons as $reason) {
                $gesprochen[$key.'/'.$reason] = true;
            }
        }

        foreach (self::PANEL_REASONS as $reasons) {
            foreach ($reasons as $key => $liste) {
                foreach ($liste as $reason) {
                    $gesprochen[$key.'/'.$reason] = true;
                }
            }
        }

        foreach (FindingCheck::cases() as $check) {
            foreach (array_keys($check->reasons()) as $reason) {
                $schluessel = $check->value.'/'.$reason;

                if (isset(self::SPRACHLOS[$schluessel])) {
                    $this->assertArrayNotHasKey($schluessel, $gesprochen, sprintf(
                        '%s hat einen Sprecher bekommen — dann gehört der Eintrag aus SPRACHLOS heraus.',
                        $schluessel,
                    ));

                    continue;
                }

                $this->assertArrayHasKey($schluessel, $gesprochen, sprintf(
                    'Den Grund %s spricht niemand aus. Entweder fehlt die Prüfung, oder der Eintrag ist tot.',
                    $schluessel,
                ));
            }
        }
    }

    /**
     * Die Gründe der Prüfungen, die im Panel laufen.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const PANEL_REASONS = [
        'Units' => Units::REASONS,
        'Certificates' => Certificates::REASONS,
        'ManagedBlocks' => ManagedBlocks::REASONS,
        'SystemUsers' => SystemUsers::REASONS,
        'Orphans' => Orphans::REASONS,
    ];

    /**
     * Gründe, die es gibt und die noch niemand ausspricht — mit Datum und Grund.
     *
     * **Leer, und das ist eine Aussage.** Hier standen bis zum 2. September 2026
     * die drei Gründe von `docs/98 §3 C` Frage 3; Schritt 5b hat sie
     * ausgesprochen. Die Liste bleibt stehen, weil der nächste Grund ohne
     * Sprecher wieder einen Ort braucht — und weil sie zeigt, dass ein leerer
     * Eintrag hier eine Entscheidung ist und kein Vergessen.
     *
     * > **Ein Eintrag, der auf einen Schritt zeigt, wird falsch, sobald der
     * > Schritt die Voraussetzung nicht mitbringt.** Der Eintrag nannte einmal
     * > Schritt 5 — eine Verortung an der halben Frage: Den Sollzustand kennt
     * > das Panel, die Zeilen der Datei kennt nur der Agent.
     *
     * @var array<string, string>
     */
    private const SPRACHLOS = [];
}
