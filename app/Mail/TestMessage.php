<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Die Testmail der Mail-Einstellungen.
 *
 * **Als Mailable und nicht als `Mail::raw`.** Zwei Gründe, und der zweite ist
 * der wichtigere: Der Text steht damit an einer Stelle statt in einem
 * Controller, und ein Test kann prüfen, dass überhaupt und an wen verschickt
 * wurde — `Mail::fake()` bekommt einen rohen Versand nicht zu fassen.
 *
 * **Reiner Text, keine Vorlage.** Diese Nachricht beantwortet genau eine
 * Frage: Kommt eine Mail über das eingetragene Relay durch? Alles darin, was
 * gestaltet wäre, macht die Antwort nur unsicherer — HTML kann auf dem Weg
 * verändert werden, Text nicht.
 */
final class TestMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $actor, private readonly string $when) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'SrvPanel — Testmail');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.test', with: [
            'actor' => $this->actor,
            'when' => $this->when,
        ]);
    }
}
