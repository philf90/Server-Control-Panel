<?php

declare(strict_types=1);

namespace App\Support\Brand;

use App\Support\Settings\Settings;

/**
 * Die Betreffzeile einer Mail dieses Panels, mit dem Namen der Marke vorn.
 *
 * **Eine Stelle und nicht drei.** Bis zum 1. Oktober 2026 schrieb jede der drei
 * Mails „SrvPanel —" als Wort in ihren Betreff, auch die an die Kunden des
 * Betreibers (`docs/140 §0` Punkt 6). Den Namen kann der Betreiber einstellen,
 * und wer ihn als Wort hinschreibt, schreibt ihn falsch, sobald er das tut.
 *
 * > **Ein Name, den der Betreiber einstellen kann, steht überall dort falsch,
 * > wo ihn jemand als Wort hingeschrieben hat.**
 *
 * Gelesen wird über {@see Settings::brand()} beim Bauen der Mail, also in dem
 * Prozess, der sie verschickt. Keiner davon lebt lange: Die Meldungen
 * verschickt `srvpanel:notices`, je Lauf ein neuer Prozess, und die Testmail
 * kommt aus der Anfrage.
 */
final class MailSubject
{
    public static function of(string $rest): string
    {
        return app(Settings::class)->brand()->name.' — '.$rest;
    }
}
