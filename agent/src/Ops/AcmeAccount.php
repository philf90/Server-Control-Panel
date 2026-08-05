<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\Account;
use SrvPanel\Agent\Acme\CurlTransport;
use SrvPanel\Agent\Acme\Directories;
use SrvPanel\Agent\Acme\Session;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Das ACME-Konto anlegen oder wiederfinden.
 *
 * **Der Kontoschlüssel entsteht hier und überquert den Socket nie** — dieselbe
 * Regel wie beim Datenbankpasswort und beim `APP_KEY`. Was zurückgeht, ist die
 * Kontonummer: eine Adresse, die die Zertifizierungsstelle vergeben hat und
 * die für sich genommen nichts kann.
 *
 * **Wiederholbar.** `newAccount` mit einem schon bekannten Schlüssel liefert
 * dieselbe Nummer zurück, nur mit Status 200 statt 201. Ein zweiter Lauf legt
 * also kein zweites Konto an — und weil {@see Account} die Nummer festhält,
 * kostet er nicht einmal eine Anfrage.
 *
 * **Die Adresse der Bedingungen geht mit zurück.** Registriert wird mit
 * `termsOfServiceAgreed`, und das ist eine Zustimmung; damit sie eine ist,
 * muss der Betreiber vorher lesen können, wozu. Die Oberfläche zeigt die
 * Adresse an dem Knopf, der diese Operation auslöst.
 */
final class AcmeAccount implements Op
{
    public static function name(): string
    {
        return 'acme.account.ensure';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $contact = Guard::string($args['contact'] ?? null, 'contact');

        if (! filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            throw AgentException::badRequest('Die Kontaktadresse ist keine E-Mail-Adresse.');
        }

        // Ein Schlüssel, keine Adresse — siehe Directories.
        $url = Directories::url($args['directory'] ?? null);

        $context->progress(20, 'Verzeichnis');
        $session = Session::open(new CurlTransport, $url, new Account($url));

        $context->progress(60, 'Konto');
        $kid = $session->register($contact);

        $context->progress(100, 'fertig');

        return [
            'account' => $kid,
            'directory' => $url,
            'terms' => $session->directory()->termsOfService,
        ];
    }
}
