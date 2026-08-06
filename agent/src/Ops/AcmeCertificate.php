<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\Account;
use SrvPanel\Agent\Acme\Challenge;
use SrvPanel\Agent\Acme\CurlTransport;
use SrvPanel\Agent\Acme\Directories;
use SrvPanel\Agent\Acme\Dns\Credentials;
use SrvPanel\Agent\Acme\Dns\Providers;
use SrvPanel\Agent\Acme\DnsChallenge;
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
 * **Platzhalter werden über HTTP-01 abgewiesen — und nur dort.** Über HTTP-01
 * gibt es kein Wildcard, und die Zertifizierungsstelle antwortet darauf mit
 * einer Meldung, die das nicht sagt. Seit Schritt 7 gibt es DNS-01, und die
 * Schranke hängt jetzt an der Art der Prüfung statt am Namen: Wer `dns-01`
 * nennt und ein Profil dazu, darf einen Stern bestellen.
 *
 * **Die Art kommt als Wort und der Anbieter als Profilname** — nicht als
 * Adresse und nicht als Token. Dieselbe Entscheidung wie bei den
 * Zertifizierungsstellen ({@see Directories}) und bei den Anbietern
 * ({@see Providers}): Was der Agent entgegennimmt, ist ein Schlüssel aus einer
 * Positivliste. Das Geheimnis dazu liegt schon hier und überquert den Socket
 * nicht noch einmal.
 */
final class AcmeCertificate implements Op
{
    /** Siehe die Klassenbeschreibung. */
    public const MAX_NAMES = 5;

    /** Die Arten, die dieser Agent fahren kann. */
    public const CHALLENGES = [HttpChallenge::TYPE, DnsChallenge::TYPE];

    public function __construct(
        private readonly Store $store = new Store,
        private readonly HttpChallenge $challenge = new HttpChallenge,
        private readonly Credentials $credentials = new Credentials,
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
        $challenge = $this->challenge($args['challenge'] ?? null, $args['profile'] ?? null);
        $names = $this->names($args['names'] ?? null, $challenge->type());
        $contact = Guard::string($args['contact'] ?? null, 'contact');
        $url = Directories::url($args['directory'] ?? null);

        $context->progress(5, 'Konto');
        $session = Session::open(new CurlTransport, $url, new Account($url));
        $session->register($contact);

        $order = new Order($session, $challenge);

        $result = $order->issue(
            $names,
            fn (int $percent, string $step) => $context->progress($percent, $step),
        );

        $paths = $this->store->write($names[0], $result['certificate'], $result['key']);

        $context->progress(100, 'fertig');

        return $paths + ['names' => $names, 'directory' => $url] + $this->describe($result['certificate']);
    }

    /**
     * Womit bewiesen wird, dass die Domain hierher gehört.
     *
     * **Ohne Angabe bleibt es HTTP-01.** Jede Bestellung des ersten Wurfs kommt
     * ohne dieses Feld an, und die soll weiterlaufen wie bisher — eine
     * Voreinstellung ist hier keine Bequemlichkeit, sondern die Zusicherung,
     * dass ein Zeitplan aus der alten Fassung nicht stehenbleibt.
     */
    private function challenge(mixed $type, mixed $profile): Challenge
    {
        $name = is_string($type) && trim($type) !== '' ? strtolower(trim($type)) : HttpChallenge::TYPE;

        if (! in_array($name, self::CHALLENGES, true)) {
            throw AgentException::badRequest(
                'Unbekannte Art der Prüfung.',
                ['challenge' => $name, 'known' => self::CHALLENGES],
            );
        }

        if ($name === HttpChallenge::TYPE) {
            return $this->challenge;
        }

        // Der Profilname ist alles, was die Anwendung schickt. Das Token dazu
        // liegt seit Schritt 6 hier und geht nicht über den Socket.
        $stored = $this->credentials->read($profile);

        return new DnsChallenge(Providers::make($stored['provider'], $stored['config']));
    }

    /**
     * Die bestellten Namen — geprüft wie jeder Domainname im Agenten.
     *
     * @return list<string>
     */
    private function names(mixed $value, string $challenge): array
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
            $field = 'names['.$index.']';
            $star = is_string($name) && str_starts_with(trim($name), '*.');

            if ($star && $challenge !== DnsChallenge::TYPE) {
                throw AgentException::badRequest(
                    'Ein Platzhalter braucht DNS-01; über HTTP-01 gibt es kein Wildcard.',
                    [$field => $name],
                );
            }

            // Der Stern gehört nicht in `DomainName::normalize()` — dort ist
            // eine Beschriftung `[a-z0-9]`, und das soll sie bleiben. Geprüft
            // wird der Rest, und der Stern kommt danach wieder davor.
            $clean = $star
                ? '*.'.DomainName::normalize(substr(trim((string) $name), 2), $field)
                : DomainName::normalize($name, $field);

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
