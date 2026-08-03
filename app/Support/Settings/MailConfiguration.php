<?php

declare(strict_types=1);

namespace App\Support\Settings;

use Illuminate\Contracts\Config\Repository;

/**
 * Die hinterlegten Zugangsdaten in die Mailkonfiguration heben.
 *
 * **Warum das nicht in der `.env` steht.** Dort stehen die Vorgaben, und dort
 * kommt der Betreiber nicht hin: Er bedient ein Panel und keinen Editor auf
 * einem Server. Die Einstellungen aus der Oberfläche überschreiben deshalb zur
 * Laufzeit, was die Konfiguration mitbringt — und nur dann, wenn tatsächlich
 * etwas hinterlegt ist. Ohne Eintrag bleibt es bei dem, was in der
 * Konfiguration steht; auf einem frisch installierten Server ist das `log`,
 * und eine Mail landet in der Datei statt im Nichts.
 *
 * **Aufgerufen wird das erst, wenn wirklich eine Mail entsteht** (siehe
 * SrvPanelServiceProvider). Eine Abfrage der Einstellungen bei jedem
 * Seitenaufruf wäre eine Datenbankabfrage für etwas, das ein Panel ein paar
 * Mal am Tag braucht.
 */
final class MailConfiguration
{
    public static function apply(Settings $settings, Repository $config): bool
    {
        $mail = $settings->mail();

        if (! $mail->usable()) {
            return false;
        }

        $config->set('mail.mailers.smtp.transport', 'smtp');
        $config->set('mail.mailers.smtp.host', $mail->host);
        $config->set('mail.mailers.smtp.port', $mail->port);

        // Laravel erwartet hier `tls`, `ssl` — oder gar nichts. Die Zeichenkette
        // „none" wäre ein Verschlüsselungsverfahren dieses Namens, und das
        // gibt es nicht: Der Transport fiele mit einer Meldung aus, die nach
        // einem Fehler des Servers aussieht.
        $config->set('mail.mailers.smtp.encryption', $mail->encryption === 'none' ? null : $mail->encryption);

        $config->set('mail.mailers.smtp.username', $mail->username !== '' ? $mail->username : null);
        $config->set('mail.mailers.smtp.password', $mail->password !== '' ? $mail->password : null);

        $config->set('mail.default', 'smtp');
        $config->set('mail.from.address', $mail->from_address);
        $config->set('mail.from.name', $mail->from_name);

        return true;
    }
}
