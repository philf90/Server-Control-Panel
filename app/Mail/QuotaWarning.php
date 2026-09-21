<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Plans\Quota;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Die Meldung an den Kunden, dass eines seiner Kontingente überschritten ist
 * (B5, `docs/129 §9`).
 *
 * **Reiner Text wie bei {@see TestMessage}, und aus demselben Grund.** Diese
 * Nachricht sagt drei Dinge — welches Abonnement, welches Kontingent, wie
 * viel —, und alles daran, was gestaltet wäre, macht sie nur unsicherer.
 * Branding ist B6 und nicht dieses Merkmal.
 *
 * **Sie fordert nicht zum Handeln auf und droht nicht.** Die Kontingente, um
 * die es geht, sind **gemessen und nicht erzwungen**
 * ({@see Quota::TrafficGb}): Es passiert nichts, und eine
 * Mail, die etwas anderes nahelegt, wäre eine Behauptung über den Betrieb
 * dieses Servers, die dieses Panel nicht decken kann.
 *
 * > **Eine Meldung, die eine Folge androht, die niemand herbeiführt, wird beim
 * > zweiten Mal nicht mehr gelesen.**
 */
final class QuotaWarning extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $subscription  der Name des Abonnements
     * @param  list<array{label: string, detail: string}>  $overruns
     */
    public function __construct(
        private readonly string $subscription,
        private readonly array $overruns,
    ) {}

    public function envelope(): Envelope
    {
        /*
         * Der Name steht im Betreff, und das ist keine Verzierung: Ein Kunde
         * mit drei Abonnements bekommt sonst drei Mails, die sich im Betreff
         * nicht unterscheiden.
         */
        return new Envelope(subject: sprintf('SrvPanel — Kontingent überschritten: %s', $this->subscription));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.quota', with: [
            'subscription' => $this->subscription,
            'overruns' => $this->overruns,
        ]);
    }
}
