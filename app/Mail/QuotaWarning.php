<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Brand\MailSubject;
use App\Support\Diagnose\Checks\QuotaOverrun;
use App\Support\Plans\Quota;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Die Meldung an den Kunden über die Kontingente eines Abonnements (B5,
 * `docs/129 §9`).
 *
 * **Reiner Text wie bei {@see TestMessage}, und aus demselben Grund.** Diese
 * Nachricht sagt drei Dinge — welches Abonnement, welches Kontingent, wie
 * viel —, und alles daran, was gestaltet wäre, macht sie nur unsicherer.
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
 * ## Und die Zeilen bleiben unter 78 Zeichen
 *
 * Der Satz eines Befundes stand in derselben Zeile wie sein Wert, mit einem
 * Doppelpunkt hinter seinem Punkt („Kontingent.: 3 MB von 1 MB"), und kam auf
 * 89 Zeichen. Gebrochen wird hier und nicht in der Vorlage, nach Zeichen und
 * nicht nach Bytes: `wordwrap()` zählt Bytes, und ein Umlaut ist zwei davon.
 */
final class QuotaWarning extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** Wie lang eine Zeile höchstens wird — unter 78, damit kein Klient umbricht. */
    public const WIDTH = 76;

    /**
     * @param  string  $subscription  der Name des Abonnements
     * @param  non-empty-list<array{reason: string, label: string, detail: string}>  $overruns
     */
    public function __construct(
        private readonly string $subscription,
        private readonly array $overruns,
    ) {}

    public function envelope(): Envelope
    {
        /*
         * **Der Betreff sagt, was los ist, und nennt das Abonnement.** Ein
         * Kunde mit drei Abonnements bekommt sonst drei Mails, die sich im
         * Betreff nicht unterscheiden — und „Kontingent überschritten" war für
         * einen Platz, der nur fast voll ist, falsch.
         */
        return new Envelope(subject: MailSubject::of(sprintf(
            '%s: %s',
            self::listing(array_map(static fn (array $o): string => self::headline($o['reason']), $this->overruns)),
            $this->subscription,
        )));
    }

    public function content(): Content
    {
        $gruende = array_column($this->overruns, 'reason');

        /*
         * **Fertig gebrochene Texte und keine Listen.** Die Vorlage gibt aus,
         * was hier steht; eine Schleife darin liesse vor der Unterschrift ein
         * `@endforeach` stehen, wo `MailSignatureTest` die Leerzeile sucht.
         */
        return new Content(text: 'mail.quota', with: [
            'intro' => implode("\n", self::wrap(sprintf(
                'für Ihr Abonnement %s hat das Panel Folgendes gemessen:',
                $this->subscription,
            ), self::WIDTH)),
            'lines' => implode("\n", $this->lines()),
            'paragraphs' => implode("\n\n", self::paragraphs($gruende)),
        ]);
    }

    /**
     * Die Überschrift eines Grundes — für den Betreff.
     *
     * **Ohne `default`, mit Absicht.** Ein neuer Grund in {@see QuotaOverrun}
     * ohne Überschrift wirft hier, statt still „Kontingent" zu schreiben;
     * `QuotaWarningTest` fährt jeden Grund aus `QuotaOverrun::REASONS` durch.
     */
    public static function headline(string $reason): string
    {
        return match ($reason) {
            'disk_near_limit' => 'Speicherplatz fast ausgeschöpft',
            'disk_over' => 'Speicherplatz ausgeschöpft',
            'databases_over' => 'Datenbankgröße überschritten',
            'traffic_over' => 'Traffic überschritten',
        };
    }

    /**
     * Die Zeilen der Befunde: der Satz, darunter der gemessene Wert.
     *
     * @return list<string>
     */
    private function lines(): array
    {
        $zeilen = [];

        foreach ($this->overruns as $overrun) {
            foreach (self::wrap($overrun['label'], self::WIDTH - 2) as $i => $teil) {
                $zeilen[] = ($i === 0 ? '- ' : '  ').$teil;
            }

            foreach (self::wrap('Gemessen: '.$overrun['detail'], self::WIDTH - 2) as $teil) {
                $zeilen[] = '  '.$teil;
            }
        }

        return $zeilen;
    }

    /**
     * Die Absätze über die Folgen — nur zu den Kontingenten, die in der Mail
     * stehen.
     *
     * @param  list<string>  $gruende
     * @return list<string> je Absatz ein fertig gebrochener Text
     */
    public static function paragraphs(array $gruende): array
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

        $absaetze[] = 'Sie bekommen diese Nachricht einmal je Zustand. Erst wenn er vorbei ist '
            .'und wieder eintritt, meldet sich das Panel erneut.';

        return array_map(static fn (string $a): string => implode("\n", self::wrap($a, self::WIDTH)), $absaetze);
    }

    /**
     * „A", „A und B", „A, B und C".
     *
     * @param  list<string>  $teile
     */
    private static function listing(array $teile): string
    {
        $letzter = array_pop($teile);

        return $teile === [] ? (string) $letzter : implode(', ', $teile).' und '.$letzter;
    }

    /**
     * Einen Text an Wortgrenzen brechen, nach Zeichen gezählt.
     *
     * Ein Wort, das allein länger ist als die Zeile — ein langer
     * Abonnementname —, bleibt ganz: Mitten in einem Namen zu brechen, hiesse,
     * ihn unkenntlich zu machen.
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $width): array
    {
        $zeilen = [];
        $zeile = '';

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $wort) {
            if ($zeile === '') {
                $zeile = $wort;
            } elseif (mb_strlen($zeile.' '.$wort) <= $width) {
                $zeile .= ' '.$wort;
            } else {
                $zeilen[] = $zeile;
                $zeile = $wort;
            }
        }

        if ($zeile !== '') {
            $zeilen[] = $zeile;
        }

        return $zeilen;
    }
}
