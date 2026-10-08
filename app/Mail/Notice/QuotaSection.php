<?php

declare(strict_types=1);

namespace App\Mail\Notice;

use App\Mail\Concerns\PlainText;
use App\Support\Diagnose\Checks\QuotaOverrun;
use App\Support\Plans\Quota;
use LogicException;

/**
 * Was die Mail an den Kunden über die Kontingente eines Abonnements sagt (B5,
 * `docs/129 §9`).
 *
 * **Bis B9 war das die ganze Mail** und hiess `QuotaWarning`. Seit Zertifikat
 * und Sicherung dazugekommen sind (`docs/142`), ist es einer von drei
 * Abschnitten; Betreff, Anrede und Unterschrift setzt die Mail zusammen.
 *
 * ## Was sie über die Folgen sagt, hängt am Kontingent
 *
 * **Bis zum 5. Oktober 2026 sagte sie für jedes dasselbe:** „gemessen und
 * nicht erzwungen — es wird nichts abgeschaltet und nichts gesperrt". Für
 * Datenbanken und Traffic stimmt das ({@see Quota::hint()}). Den Platz
 * erzwingt die Dateisystem-Quota, und in genau der Lage, über die diese Mail
 * schreibt, scheitern die Schreibzugriffe der Website (`docs/141 §0`). Die
 * Absätze stehen deshalb je Kontingent da und nur, wenn es in der Mail
 * vorkommt; der über die Abrechnung des Traffics stand auch in Mails, in
 * denen es um Traffic gar nicht ging.
 *
 * > **Eine Mail, die einen Satz für alle Fälle hat, hat ihn für einen davon
 * > falsch.**
 *
 * **Sie fordert nicht zum Handeln auf und droht nicht.** Sie sagt, was der
 * Kunde merkt, und nicht, was er tun soll.
 *
 * ## Auf der Grenze nennt sie „ausgeschöpft" und nicht beides
 *
 * Dort stehen zwei Befunde, die Vorwarnung und „ausgeschöpft"
 * ({@see QuotaOverrun::disk()}). Kommen beide in derselben Mail an — der Platz
 * ist zwischen zwei Nachtläufen von unter 95 % bis an die Grenze gewachsen —,
 * nennt sie nur den schwereren: „fast ausgeschöpft" neben „ausgeschöpft"
 * widerspräche sich, und wer liest, dass sein Platz voll ist, braucht die
 * Vorwarnung nicht. Gebucht werden trotzdem beide; die Vorwarnung ist in der
 * Meldung enthalten, und später kommt für sie keine zweite.
 *
 * ## Und die Zeilen bleiben unter 78 Zeichen
 *
 * Der Satz eines Befundes stand in derselben Zeile wie sein Wert, mit einem
 * Doppelpunkt hinter seinem Punkt („Kontingent.: 3 MB von 1 MB"), und kam auf
 * 90 Zeichen. Gebrochen wird in {@see PlainText} und nicht in der Vorlage.
 */
final class QuotaSection implements Section
{
    use PlainText;

    /** @var non-empty-list<array{reason: string, label: string, detail: string}> */
    private readonly array $overruns;

    /**
     * @param  non-empty-list<array{reason: string, label: string, detail: string}>  $overruns
     */
    public function __construct(array $overruns)
    {
        $this->overruns = self::shown($overruns);
    }

    /**
     * Was die Mail nennt: „ausgeschöpft" ohne „fast ausgeschöpft" daneben.
     *
     * Der Grund steht im Kopf dieser Klasse. Die Liste ist danach nie leer —
     * gestrichen wird die Vorwarnung nur, wenn „ausgeschöpft" bleibt.
     *
     * @param  non-empty-list<array{reason: string, label: string, detail: string}>  $overruns
     * @return non-empty-list<array{reason: string, label: string, detail: string}>
     */
    public static function shown(array $overruns): array
    {
        if (! in_array('disk_over', array_column($overruns, 'reason'), true)) {
            return $overruns;
        }

        $gezeigt = array_values(array_filter(
            $overruns,
            static fn (array $o): bool => $o['reason'] !== 'disk_near_limit',
        ));

        return $gezeigt === [] ? $overruns : $gezeigt;
    }

    /**
     * Die Überschrift eines Grundes — für den Betreff.
     *
     * **Der Rückfall wirft, mit Absicht.** Ein neuer Grund in
     * {@see QuotaOverrun} ohne Überschrift fällt hier auf, statt still
     * „Kontingent" zu schreiben; `QuotaNoticeTest` fährt jeden Grund aus
     * `QuotaOverrun::REASONS` durch, damit es nicht erst im Nachtlauf wirft.
     */
    public static function headline(string $reason): string
    {
        return match ($reason) {
            'disk_near_limit' => 'Speicherplatz fast ausgeschöpft',
            'disk_over' => 'Speicherplatz ausgeschöpft',
            'databases_over' => 'Datenbankgröße überschritten',
            'traffic_over' => 'Traffic überschritten',
            default => throw new LogicException(sprintf('Für den Grund „%s" gibt es keine Überschrift.', $reason)),
        };
    }

    public function headlines(): array
    {
        return array_map(static fn (array $o): string => self::headline($o['reason']), $this->overruns);
    }

    /**
     * Die Zeilen der Befunde: der Satz, darunter der gemessene Wert.
     *
     * @return non-empty-list<string>
     */
    public function lines(): array
    {
        $zeilen = [];

        foreach ($this->overruns as $overrun) {
            foreach (self::wrap($overrun['label'], self::WIDTH - 2) as $i => $teil) {
                $zeilen[] = ($i === 0 ? '- ' : '  ').$teil;
            }

            foreach (self::detailLines('Gemessen', $overrun['detail']) as $zeile) {
                $zeilen[] = $zeile;
            }
        }

        /** @var non-empty-list<string> $zeilen */
        return $zeilen;
    }

    public function paragraphs(): array
    {
        return self::consequences(array_column($this->overruns, 'reason'));
    }

    /**
     * Die Absätze über die Folgen — nur zu den Kontingenten, die in der Mail
     * stehen.
     *
     * @param  list<string>  $gruende
     * @return list<string> je Absatz ein fertig gebrochener Text
     */
    public static function consequences(array $gruende): array
    {
        $absaetze = [];

        $platz = array_intersect($gruende, ['disk_near_limit', 'disk_over']) !== [];
        $datenbanken = in_array('databases_over', $gruende, true);
        $traffic = in_array('traffic_over', $gruende, true);

        if ($platz) {
            $satz = 'Den Speicherplatz begrenzt das Dateisystem: Ist er ausgeschöpft, '
                .'scheitert jeder Schreibzugriff Ihrer Website, bis Dateien gelöscht '
                .'werden oder das Kontingent steigt.';

            if (in_array('disk_near_limit', $gruende, true)) {
                $satz .= sprintf(
                    ' Die Warnung endet, wenn der belegte Platz unter %d %% des Kontingents fällt.',
                    QuotaOverrun::DISK_RELEASE_PERCENT,
                );
            }

            $absaetze[] = $satz;
        }

        if ($datenbanken || $traffic) {
            $was = match (true) {
                $datenbanken && $traffic => 'Datenbankgröße und Traffic werden',
                $datenbanken => 'Die Datenbankgröße wird',
                default => 'Der Traffic wird',
            };

            $absaetze[] = $was.' gemessen und nicht erzwungen — dafür wird nichts '
                .'abgeschaltet und nichts gesperrt.';
        }

        if ($traffic) {
            $absaetze[] = 'Beim Traffic zählt diese Zahl, was der Webserver protokolliert hat. '
                .'Die Abrechnung Ihres Anbieters kann höher liegen: Er zählt TCP, TLS und '
                .'Wiederholungen mit.';
        }

        $absaetze[] = 'Die Zahlen stehen mit ihrem Verlauf der letzten dreissig Tage auf der '
            .'Seite Ihres Abonnements im Panel.';

        return array_map(static fn (string $a): string => implode("\n", self::wrap($a, self::WIDTH)), $absaetze);
    }
}
