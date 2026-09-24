<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use SrvPanel\Agent\Names;

/**
 * Die Meldung an den **Betreiber** über das, was der Nachtlauf gefunden hat
 * (B1, `docs/129 §4`).
 *
 * **Warum eine zweite Vorlage und nicht {@see QuotaWarning} mit anderem Text.**
 * Die beiden haben nicht denselben Empfänger und nicht denselben Gegenstand:
 * Die eine sagt einem Kunden, dass **sein** Kontingent überschritten ist, die
 * andere einem Betreiber, dass an **seinem Server** etwas nicht stimmt. Eine
 * Vorlage mit einem Schalter darin wäre zwei Nachrichten in einer Datei, und
 * die seltenere ist die, die beim nächsten Umbau falsch wird.
 *
 * **Reiner Text, wie jede Mail dieses Panels.** Der Grund steht in
 * `mail.signature`: HTML kann auf dem Weg verändert werden, Text nicht — und
 * eine Meldung, deren Ankunft man nicht mehr beurteilen kann, ist als Meldung
 * über einen kaputten Server wenig wert.
 *
 * **Sie nennt den Rechner, und zwar im Betreff.** Ein Betreiber mit drei
 * Servern bekommt sonst drei Nachrichten, die sich im Betreff nicht
 * unterscheiden — derselbe Grund, aus dem der Abonnementname im Betreff der
 * Kundenmail steht. Gefragt wird {@see Names}, die einzige Stelle, die den
 * Rechnernamen kennt.
 */
final class DiagnoseReport extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  non-empty-list<array{label: string, subject: string, detail: string, since: string}>  $findings
     */
    public function __construct(private readonly array $findings) {}

    public function envelope(): Envelope
    {
        /*
         * **Die Entscheidung über das Wort gehört an den Wert.** „1 Befunde"
         * ist der Fehler, gegen den `CountedNounTest` auf den Seiten dieses
         * Panels geschrieben ist; er gilt in einer Betreffzeile genauso.
         *
         * Hier stand bis zum 24. September 2026 „dort hält ihn kein Wächter",
         * und das stimmte: Die Muster dort suchen eine Zahl direkt vor dem
         * Mehrzahlwort, und in dieser Zeile steht „neue" dazwischen. Seitdem
         * baut `CountedNounTest::test_the_subject_of_the_operator_mail_fits_its_count`
         * diese Mail mit einem und mit zwei Befunden und liest den Betreff ab —
         * wer die Zeile zu `sprintf('%d neue Befunde', …)` vereinfacht, sieht
         * dort Rot und nicht erst im Postfach „1 neue Befunde".
         */
        $anzahl = count($this->findings);

        return new Envelope(subject: sprintf(
            'SrvPanel — %s auf %s',
            $anzahl === 1 ? 'ein neuer Befund' : $anzahl.' neue Befunde',
            Names::host(),
        ));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.diagnose', with: [
            'findings' => $this->findings,
            'host' => Names::host(),
        ]);
    }
}
