<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\PlainText;
use App\Mail\Notice\BackupSection;
use App\Mail\Notice\CertificateSection;
use App\Mail\Notice\QuotaSection;
use App\Mail\Notice\Section;
use App\Support\Brand\MailSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Die Meldung an den Kunden über eines seiner Abonnements — Kontingente (B5),
 * Zertifikate und Sicherung (B9, `docs/142`).
 *
 * **Bis zum 7. Oktober 2026 hiess sie `QuotaWarning`** und kannte nur die
 * Kontingente. Seit B9 trägt sie drei Arten von Befunden, und der Name sagte
 * sonst etwas, das nur noch für einen Abschnitt stimmt.
 *
 * ## Eine Mail je Abonnement, Empfänger und Nacht
 *
 * Ein Kunde, dessen Platz knapp wird und dessen Sicherung scheitert, bekommt
 * **eine** Mail mit zwei Abschnitten und nicht zwei Mails (`docs/142 §4`).
 * Zwei in derselben Minute sind für den Empfänger genau das, wogegen „genau
 * eine Mail" seit B5 geschrieben ist — und die Bündelung je Abonnement steht
 * deshalb im Mailkanal und nicht je Art.
 *
 * **Die Reihenfolge der Abschnitte ist fest**: Kontingente, Zertifikate,
 * Sicherung — {@see self::ORDER}. Wer zwei Mails nebeneinander liest, findet
 * dasselbe an derselben Stelle.
 *
 * **Reiner Text wie bei {@see TestMessage}, und aus demselben Grund.** Diese
 * Nachricht sagt, welches Abonnement, was los ist und was der Kunde davon
 * merkt; alles daran, was gestaltet wäre, macht sie nur unsicherer.
 *
 * **Der Betreff sagt, was los ist, und nennt das Abonnement.** Ein Kunde mit
 * drei Abonnements bekommt sonst drei Mails, die sich im Betreff nicht
 * unterscheiden.
 */
final class CustomerNotice extends Mailable
{
    use PlainText;
    use Queueable;
    use SerializesModels;

    /**
     * Die Abschnitte in der Reihenfolge, in der sie in der Mail stehen.
     *
     * @var list<class-string<Section>>
     */
    public const ORDER = [QuotaSection::class, CertificateSection::class, BackupSection::class];

    /** @var non-empty-list<Section> */
    private readonly array $sections;

    /**
     * @param  string  $subscription  der Name des Abonnements
     * @param  non-empty-list<Section>  $sections
     */
    public function __construct(
        private readonly string $subscription,
        array $sections,
    ) {
        usort($sections, static fn (Section $a, Section $b): int => array_search($a::class, self::ORDER, true) <=> array_search($b::class, self::ORDER, true));

        $this->sections = $sections;
    }

    public function envelope(): Envelope
    {
        $ueberschriften = [];

        foreach ($this->sections as $abschnitt) {
            array_push($ueberschriften, ...$abschnitt->headlines());
        }

        return new Envelope(subject: MailSubject::of(sprintf(
            '%s: %s',
            self::listing(array_values(array_unique($ueberschriften))),
            $this->subscription,
        )));
    }

    public function content(): Content
    {
        $bloecke = [];

        foreach ($this->sections as $abschnitt) {
            $bloecke[] = implode("\n", $abschnitt->lines());

            foreach ($abschnitt->paragraphs() as $absatz) {
                $bloecke[] = $absatz;
            }
        }

        /*
         * **Einmal je Mail und nicht je Abschnitt.** Er stand in der Mail über
         * die Kontingente und gilt für jeden Befund: Ein Zertifikat, das erst
         * demnächst und dann wirklich abläuft, sind zwei Zustände, und für
         * jeden kommt eine Mail.
         */
        $bloecke[] = implode("\n", self::wrap(
            'Sie bekommen diese Nachricht einmal je Zustand. Erst wenn er vorbei ist '
            .'und wieder eintritt, meldet sich das Panel erneut.',
            self::WIDTH,
        ));

        /*
         * **Fertig gebrochene Texte und keine Listen.** Die Vorlage gibt aus,
         * was hier steht; eine Schleife darin liesse vor der Unterschrift ein
         * `@endforeach` stehen, wo `MailSignatureTest` die Leerzeile sucht.
         */
        return new Content(text: 'mail.customer', with: [
            'intro' => implode("\n", self::wrap(sprintf(
                'für Ihr Abonnement %s hat das Panel Folgendes festgestellt:',
                $this->subscription,
            ), self::WIDTH)),
            'body' => implode("\n\n", $bloecke),
        ]);
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
}
