<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\FindingCheck;
use App\Enums\FindingState;
use PHPUnit\Framework\TestCase;

/**
 * Der Katalog der Prüfungen und ihrer Gründe (A10, `docs/98 §2`).
 *
 * **Beide Richtungen, und das ist der Punkt.** Eine Prüfung ohne Gründe wäre
 * eine, die nie einen Befund erzeugen kann; ein Grund ohne Prüfung entsteht bei
 * einer Umbenennung — man trägt den neuen Namen nach, die erste Richtung ist
 * wieder grün, und der alte bleibt liegen. Derselbe Grund wie bei
 * `PackagingTest` und `UnitCatalogTest`:
 *
 * > **Ein Wächter, der eine Richtung prüft, hat über die andere nichts gesagt —
 * > und welche der beiden fehlt, sieht man erst, wenn man sie braucht.**
 *
 * Framework-frei: Beide Aufzählungen hängen an nichts, und dieser Wächter läuft
 * damit auch dort, wo `vendor/` fehlt.
 */
final class DiagnoseCatalogTest extends TestCase
{
    /** Was ohne `unreachable` auskommt — und warum. */
    private const OHNE_UNREACHABLE = [
        // Fragt allein den eigenen Bestand. Es gibt keinen Agenten, der
        // schweigen könnte.
        'orphan.row',

        // Die einzige Prüfung, die über das Netz geht: Dass der Server nicht
        // antwortet, ist hier der gemessene Zustand und keine ausgefallene
        // Messung. Sie hat dafür `no_answer`.
        'tls.wire',
    ];

    public function test_every_check_knows_at_least_one_reason(): void
    {
        foreach (FindingCheck::cases() as $check) {
            $this->assertNotSame([], $check->reasons(), sprintf(
                'Die Prüfung %s kennt keinen Grund und kann damit keinen Befund erzeugen.',
                $check->value,
            ));
        }
    }

    /**
     * `unreachable` heisst überall `Unknown` und nie `Ok`.
     *
     * **Das ist die Regel, an der alles hängt.** Ein Diagnoselauf, der bei
     * totem Agenten Entwarnung gibt, ist schlimmer als keiner — der Fehler aus
     * `docs/44`, wo ein `catch (Throwable) { return []; }` aus „nicht
     * erreichbar" ein „der Betreiber bietet es nicht an" gemacht hat.
     */
    public function test_unreachable_always_means_unknown(): void
    {
        $gesehen = 0;

        foreach (FindingCheck::cases() as $check) {
            $reasons = $check->reasons();

            if (! isset($reasons[FindingCheck::UNREACHABLE])) {
                continue;
            }

            $gesehen++;

            $this->assertSame(
                FindingState::Unknown,
                $check->state(FindingCheck::UNREACHABLE),
                sprintf('%s macht aus einer ausgefallenen Messung ein Urteil über den Gegenstand.', $check->value),
            );
        }

        // Die Untergrenze: Ohne sie wäre dieser Wächter grün, sobald der Grund
        // überall verschwindet.
        $this->assertGreaterThanOrEqual(10, $gesehen, 'Zu wenige Prüfungen kennen „unreachable" — der Wächter misst hier nichts mehr.');
    }

    /**
     * Und die Gegenrichtung: Wer ihn nicht führt, steht mit Begründung da.
     *
     * Ohne diese Hälfte fiele eine neue Prüfung, die ihn zu tragen vergisst,
     * niemandem auf — sie meldete dann bei einem Ausfall gar nichts.
     */
    public function test_a_check_without_unreachable_is_named(): void
    {
        $ohne = [];

        foreach (FindingCheck::cases() as $check) {
            if (! isset($check->reasons()[FindingCheck::UNREACHABLE])) {
                $ohne[] = $check->value;
            }
        }

        sort($ohne);
        $erwartet = self::OHNE_UNREACHABLE;
        sort($erwartet);

        $this->assertSame($erwartet, $ohne, sprintf(
            "Eine Prüfung ohne „unreachable\" meldet bei einem Ausfall nichts.\n".
            'Ist das gewollt, gehört sie mit Begründung in %s::OHNE_UNREACHABLE.',
            self::class,
        ));
    }

    public function test_every_reason_has_a_state_and_a_sentence(): void
    {
        foreach (FindingCheck::cases() as $check) {
            foreach ($check->reasons() as $reason => $eintrag) {
                $ort = $check->value.'/'.$reason;

                $this->assertInstanceOf(FindingState::class, $eintrag['state'], $ort);
                $this->assertSame($eintrag['state'], $check->state($reason), $ort.': state() weicht von der Liste ab.');
                $this->assertSame($eintrag['text'], $check->sentence($reason), $ort.': sentence() weicht von der Liste ab.');
            }
        }
    }

    /**
     * Kein Grund erzeugt `Ok`.
     *
     * Ein Befund ist der Ort, an dem etwas **nicht** stimmt; ein `Ok` erzeugt
     * keine Zeile (`docs/98 §4`). Stünde er in der Liste, hätte jemand eine
     * Zeile gebaut, die auf der Seite steht und nichts meldet.
     */
    public function test_no_reason_judges_something_to_be_fine(): void
    {
        foreach (FindingCheck::cases() as $check) {
            foreach ($check->reasons() as $reason => $eintrag) {
                $this->assertNotSame(FindingState::Ok, $eintrag['state'], sprintf(
                    '%s/%s ergibt „in Ordnung" und erzeugte damit eine Zeile, die nichts meldet.',
                    $check->value,
                    $reason,
                ));
            }
        }
    }

    /**
     * Die Schlüssel passen in ihre Spalten und in den `unique`-Index.
     *
     * `findings` führt `check` als `varchar(32)` und `reason` als
     * `varchar(64)`. Ein zu langer Schlüssel schlüge beim Schreiben zu — und
     * `docs/45` hat gemessen, was das anrichtet, wenn es im Fehlerweg
     * passiert.
     */
    public function test_the_keys_fit_their_columns(): void
    {
        foreach (FindingCheck::cases() as $check) {
            $this->assertLessThanOrEqual(32, strlen($check->value), $check->value);
            $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z_]+$/D', $check->value, 'Ein Prüfungsschlüssel ist englisch, klein und getrennt mit einem Punkt.');

            foreach (array_keys($check->reasons()) as $reason) {
                $this->assertLessThanOrEqual(64, strlen((string) $reason), $check->value.'/'.$reason);
                $this->assertMatchesRegularExpression('/^[a-z][a-z_]*$/D', (string) $reason, 'Ein Grund ist englisch, klein und mit Unterstrich getrennt.');
            }
        }
    }

    /**
     * Die Sätze sind Sätze — sie liest der Administrator.
     *
     * `docs/98 §9` Frage 5: Der Administrator sieht `subject` und diesen Satz;
     * der Wortlaut des Werkzeugs bleibt dem Betreiber. Ein Satzfragment wäre
     * für ihn die ganze Auskunft.
     */
    public function test_every_sentence_reads_like_one(): void
    {
        foreach (FindingCheck::cases() as $check) {
            foreach ($check->reasons() as $reason => $eintrag) {
                $ort = $check->value.'/'.$reason;
                $text = $eintrag['text'];

                $this->assertNotSame('', trim($text), $ort);
                $this->assertMatchesRegularExpression('/^[A-ZÄÖÜ]/u', $text, $ort.': ein Satz fängt gross an.');
                $this->assertStringEndsWith('.', $text, $ort.': ein Satz hört mit einem Punkt auf.');
            }
        }
    }

    public function test_label_and_subject_label_exist_for_every_check(): void
    {
        foreach (FindingCheck::cases() as $check) {
            $this->assertNotSame('', trim($check->label()), $check->value);
            $this->assertNotSame('', trim($check->subjectLabel()), $check->value.': ein Befund ohne Ort erfüllt das Kriterium nicht.');
        }
    }

    public function test_an_unknown_reason_is_refused_by_both_ways(): void
    {
        foreach (['state', 'sentence'] as $weg) {
            try {
                FindingCheck::WebConfig->{$weg}('gibtsnicht');
                $this->fail(sprintf('%s() hat einen Grund durchgelassen, den die Prüfung nicht kennt.', $weg));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('gibtsnicht', $e->getMessage());
            }
        }
    }
}
