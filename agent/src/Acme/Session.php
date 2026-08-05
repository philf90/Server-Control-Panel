<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;

/**
 * Ein Gespräch mit der Zertifizierungsstelle.
 *
 * Sie hält die drei Dinge zusammen, die eine ACME-Anfrage braucht und die
 * einzeln nichts taugen: das Verzeichnis mit den Adressen, den Kontoschlüssel
 * samt Kontonummer, und den Einmalwert für genau die nächste Anfrage.
 *
 * **Der Einmalwert ist der Grund, warum das ein Objekt ist und keine Sammlung
 * von Funktionen.** Jede Antwort bringt den Wert für die nächste Anfrage mit;
 * wer ihn wegwirft, holt ihn einzeln nach und verdoppelt damit die Anzahl der
 * Anfragen. Wer ihn zweimal benutzt, bekommt „badNonce".
 */
final class Session
{
    private ?string $nonce = null;

    private ?string $kid = null;

    public function __construct(
        private readonly Transport $transport,
        private readonly Directory $directory,
        private readonly Account $account,
        private readonly Jws $jws,
    ) {}

    public static function open(Transport $transport, string $directoryUrl, Account $account): self
    {
        return new self(
            $transport,
            Directory::fetch($transport, $directoryUrl),
            $account,
            new Jws($account->key()),
        );
    }

    public function directory(): Directory
    {
        return $this->directory;
    }

    public function thumbprint(): string
    {
        return $this->jws->thumbprint();
    }

    /**
     * Das Konto anlegen oder wiederfinden.
     *
     * **ACME kennt den Unterschied nicht, und das ist bequem:** `newAccount`
     * mit einem schon bekannten Schlüssel liefert dieselbe Kontonummer zurück,
     * nur mit Status 200 statt 201. Es gibt hier deshalb kein „gibt es schon" —
     * es gibt nur „registriert".
     *
     * **Zur Zustimmung.** `termsOfServiceAgreed` steht auf `true`, weil diese
     * Operation nur läuft, wenn ein Betreiber sie ausgelöst hat. Damit er
     * weiss, wozu er zustimmt, führt {@see Directory} die Adresse der
     * Bedingungen mit — sie gehört in die Oberfläche, bevor der Knopf gedrückt
     * wird, und nicht in eine Zeile, die niemand sieht.
     */
    public function register(string $contact): string
    {
        $known = $this->account->kid();

        if ($known !== null) {
            $this->kid = $known;

            return $known;
        }

        $response = $this->send($this->directory->newAccount, [
            'termsOfServiceAgreed' => true,
            'contact' => ['mailto:'.$contact],
        ], useJwk: true);

        $kid = $response->header('location');

        if ($kid === null) {
            throw AgentException::execFailed('Die Zertifizierungsstelle hat keine Kontonummer geschickt.');
        }

        $this->kid = $kid;
        $this->account->remember($kid, $contact);

        return $kid;
    }

    /** @param  array<string, mixed>  $payload */
    public function post(string $url, array $payload): Response
    {
        return $this->send($url, $payload);
    }

    /** Eine geschützte Ressource lesen — in ACME ein signiertes POST mit leerem Rumpf. */
    public function postAsGet(string $url): Response
    {
        return $this->send($url, null);
    }

    /**
     * Signiert schicken — und bei einem verbrauchten Einmalwert genau einmal neu.
     *
     * **Warum genau einmal.** `badNonce` heisst, dass die Gegenseite den Wert
     * schon gesehen hat; ein frischer behebt das. Wiederholt sich der Fehler,
     * liegt er nicht am Einmalwert, und eine Schleife wäre der kürzeste Weg in
     * die Ratenbegrenzung — fünf Fehlversuche je Konto und Stunde, und die
     * Sperre danach hält länger als jeder Zeitgewinn.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function send(string $url, ?array $payload, bool $useJwk = false, bool $retried = false): Response
    {
        $protected = [
            'alg' => 'RS256',
            'nonce' => $this->nonce(),
            'url' => $url,
        ];

        // Beim Anlegen des Kontos kennt die Gegenseite den Schlüssel noch
        // nicht — dann geht er als JWK mit. Ab da genügt die Kontonummer, und
        // ACME besteht darauf: beides zusammen ist ein Formfehler.
        if ($useJwk) {
            $protected['jwk'] = $this->jws->jwk();
        } else {
            $protected['kid'] = $this->kid();
        }

        $response = $this->transport->post($url, $this->jws->sign($protected, $payload));

        // Auch aus einer Fehlerantwort: Gerade sie bringt den frischen Wert
        // mit, den der zweite Versuch braucht.
        $this->nonce = $response->header('replay-nonce');

        if ($response->successful()) {
            return $response;
        }

        $problem = Problem::from($response);

        if ($problem !== null && $problem->isBadNonce() && ! $retried) {
            return $this->send($url, $payload, $useJwk, true);
        }

        throw AgentException::execFailed(
            $problem?->message() ?? sprintf(
                'Die Zertifizierungsstelle antwortete auf %s mit %d.',
                $url,
                $response->status,
            ),
        );
    }

    /**
     * Der Einmalwert für die nächste Anfrage.
     *
     * Er wird beim Herausgeben verbraucht — `null` danach ist keine
     * Nachlässigkeit, sondern die Zusage, dass derselbe Wert nie zweimal
     * signiert wird.
     */
    private function nonce(): string
    {
        $held = $this->nonce;

        if ($held !== null) {
            $this->nonce = null;

            return $held;
        }

        $fresh = $this->transport->get($this->directory->newNonce)->header('replay-nonce');

        if ($fresh === null) {
            throw AgentException::execFailed('Die Zertifizierungsstelle hat keinen Einmalwert geschickt.');
        }

        return $fresh;
    }

    private function kid(): string
    {
        $kid = $this->kid ?? $this->account->kid();

        if ($kid === null) {
            throw AgentException::execFailed('Ohne Konto keine Bestellung — erst registrieren.');
        }

        $this->kid = $kid;

        return $kid;
    }
}
