<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\Account;
use SrvPanel\Agent\Acme\Challenge;
use SrvPanel\Agent\Acme\CurlTransport;
use SrvPanel\Agent\Acme\Directories;
use SrvPanel\Agent\Acme\HttpChallenge;
use SrvPanel\Agent\Acme\Order;
use SrvPanel\Agent\Acme\Session;
use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\DomainName;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Ein Zertifikat bestellen und ablegen.
 *
 * **Der Schlüssel des Zertifikats entsteht hier und bleibt hier.** Zurück geht,
 * was auch jeder Browser sieht: Aussteller, Laufzeit, Seriennummer, die
 * gedeckten Namen — und die beiden Pfade, unter denen nginx sie findet.
 *
 * **Höchstens fünf Namen je Bestellung.** Nicht weil ACME das verlangt (es
 * lässt hundert zu), sondern weil ein Server-Block dieses Panels aus einer
 * Domain und ihren Aliassen besteht. Eine Bestellung über hundert Namen wäre
 * eine, die an einem einzigen nicht auflösbaren Namen scheitert und
 * neunundneunzig mitnimmt.
 *
 * **Platzhalter werden abgewiesen.** Sie verlangen DNS-01; über HTTP-01 gibt es
 * kein Wildcard, und die Zertifizierungsstelle antwortete darauf mit einer
 * Meldung, die das nicht sagt. Der zweite Wurf bringt DNS-01 mit, und dann
 * fällt diese Schranke — die Naht dafür steht in {@see Challenge}.
 */
final class AcmeCertificate implements Op
{
    /** Siehe die Klassenbeschreibung. */
    public const MAX_NAMES = 5;

    public function __construct(
        private readonly Store $store = new Store,
        private readonly HttpChallenge $challenge = new HttpChallenge,
    ) {}

    public static function name(): string
    {
        return 'acme.certificate.issue';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $names = $this->names($args['names'] ?? null);
        $contact = Guard::string($args['contact'] ?? null, 'contact');
        $url = Directories::url($args['directory'] ?? null);

        $context->progress(5, 'Konto');
        $session = Session::open(new CurlTransport, $url, new Account($url));
        $session->register($contact);

        $order = new Order($session, $this->challenge);

        $result = $order->issue(
            $names,
            fn (int $percent, string $step) => $context->progress($percent, $step),
        );

        $paths = $this->store->write($names[0], $result['certificate'], $result['key']);

        $context->progress(100, 'fertig');

        return $paths + ['names' => $names, 'directory' => $url] + $this->describe($result['certificate']);
    }

    /**
     * Die bestellten Namen — geprüft wie jeder Domainname im Agenten.
     *
     * @return list<string>
     */
    private function names(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            throw AgentException::badRequest('Ohne Namen keine Bestellung.', ['names' => 'leer']);
        }

        if (count($value) > self::MAX_NAMES) {
            throw AgentException::badRequest(
                sprintf('Höchstens %d Namen je Zertifikat.', self::MAX_NAMES),
                ['names' => count($value)],
            );
        }

        $names = [];

        foreach (array_values($value) as $index => $name) {
            if (is_string($name) && str_starts_with(trim($name), '*.')) {
                throw AgentException::badRequest(
                    'Ein Platzhalter braucht DNS-01; über HTTP-01 gibt es kein Wildcard.',
                    ['names['.$index.']' => $name],
                );
            }

            $clean = DomainName::normalize($name, 'names['.$index.']');

            if (! in_array($clean, $names, true)) {
                $names[] = $clean;
            }
        }

        return $names;
    }

    /**
     * Was in der ausgestellten Kette steht.
     *
     * Gelesen wird das **erste** Zertifikat der Kette — das ist das
     * ausgestellte; danach folgen die der Zertifizierungsstelle. Wer die Datei
     * am Stück durch `openssl_x509_parse` schickt, bekommt trotzdem eine
     * Antwort, nämlich die über das erste, und merkt den Unterschied nie. Hier
     * steht es trotzdem ausdrücklich, weil es beim Lesen sonst wie ein Zufall
     * aussieht.
     *
     * @return array<string, mixed>
     */
    private function describe(string $chain): array
    {
        $parsed = openssl_x509_parse($chain);

        if (! is_array($parsed)) {
            return ['issuer' => null, 'serial' => null, 'not_before' => null, 'not_after' => null];
        }

        $issuer = $parsed['issuer'] ?? null;

        return [
            'issuer' => is_array($issuer) ? (string) ($issuer['CN'] ?? '') : null,
            'serial' => isset($parsed['serialNumberHex']) ? (string) $parsed['serialNumberHex'] : null,
            'not_before' => (int) ($parsed['validFrom_time_t'] ?? 0),
            'not_after' => (int) ($parsed['validTo_time_t'] ?? 0),
        ];
    }
}
