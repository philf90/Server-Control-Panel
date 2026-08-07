<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;

/**
 * Eine Bestellung, von den Namen bis zum Zertifikat.
 *
 * Der Ablauf steht in RFC 8555 und ist immer derselbe: bestellen, für jeden
 * Namen eine Autorisierung holen, die Aufgabe hinlegen, prüfen lassen,
 * abwarten, die Anforderung unterschreiben lassen, abholen. **Er enthält keine
 * einzige Fallunterscheidung nach der Art der Prüfung** — das ist der Zweck von
 * {@see Challenge}, und es ist der Grund, warum DNS-01 im zweiten Wurf eine
 * Ergänzung ist und kein Umbau.
 *
 * **Abgeräumt wird in `finally`, nicht nach dem Erfolg.** Eine Bestellung
 * scheitert im Betrieb häufiger als sie gelingt — ein Name, der noch nicht
 * hierher zeigt, reicht. Was dann liegenbleibt, ist eine Prüfdatei, die niemand
 * mehr anfasst; beim zweiten Anlauf mit demselben Namen steht dort ein Wert von
 * gestern, und die Prüfung scheitert mit „unauthorized" an einer Ursache, die
 * nirgends steht.
 *
 * **Wartezeiten sind einstellbar, damit sie prüfbar sind.** Ein Ablauf, der
 * zwei Sekunden schläft, lässt sich gegen ein Drehbuch nicht messen — er wäre
 * der einzige Grund, warum ein Test Minuten braucht.
 */
final class Order
{
    /** Wie lange auf eine Prüfung oder eine Unterschrift gewartet wird. */
    public const TIMEOUT_SECONDS = 120;

    /** Abstand zwischen zwei Nachfragen. */
    public const POLL_SECONDS = 2;

    public function __construct(
        private readonly Session $session,
        private readonly Challenge $challenge,
        private readonly int $pollSeconds = self::POLL_SECONDS,
        private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS,
    ) {}

    /**
     * Ein Zertifikat für diese Namen holen.
     *
     * @param  list<string>  $names
     * @param  null|callable(int, string): void  $progress
     * @return array{certificate: string, key: string} Kette und Schlüssel als PEM
     */
    public function issue(array $names, ?callable $progress = null): array
    {
        if ($names === []) {
            throw AgentException::badRequest('Ohne Namen keine Bestellung.');
        }

        $this->report($progress, 10, 'Bestellung');

        $response = $this->session->post($this->session->directory()->newOrder, [
            'identifiers' => array_map(
                static fn (string $name): array => ['type' => 'dns', 'value' => $name],
                $names,
            ),
        ]);

        $orderUrl = $response->header('location');

        if ($orderUrl === null) {
            throw AgentException::execFailed('Die Zertifizierungsstelle hat die Bestellung nicht benannt.');
        }

        $order = $response->json();
        $finalize = self::text($order, 'finalize');

        if ($finalize === null) {
            throw AgentException::execFailed('Die Bestellung nennt keine Adresse zum Unterschreiben.');
        }

        /** @var list<array{domain: string, token: string}> $presented */
        $presented = [];

        try {
            foreach (self::urls($order, 'authorizations') as $authorization) {
                $this->report($progress, 30, 'Prüfung');
                $done = $this->authorize($authorization);

                if ($done !== null) {
                    $presented[] = $done;
                }
            }

            $this->report($progress, 60, 'Anforderung');
            $csr = Csr::create($names);
            $this->session->post($finalize, ['csr' => Jws::base64url($csr['der'])]);

            $this->report($progress, 80, 'Unterschrift');
            $certificateUrl = $this->awaitCertificate($orderUrl);

            $this->report($progress, 95, 'Abholen');
            $certificate = $this->session->postAsGet($certificateUrl)->body;

            if (! str_contains($certificate, '-----BEGIN CERTIFICATE-----')) {
                throw AgentException::execFailed('Was die Zertifizierungsstelle geschickt hat, ist kein Zertifikat.');
            }

            return ['certificate' => $certificate, 'key' => $csr['key']];
        } finally {
            foreach ($presented as $done) {
                $this->challenge->cleanup($done['domain'], $done['token']);
            }
        }
    }

    /**
     * Eine Autorisierung durchfechten.
     *
     * **Eine bereits gültige wird übersprungen, und das ist kein Sonderfall.**
     * Autorisierungen gelten bei Let's Encrypt dreissig Tage weiter; wer eine
     * zweite Domain zu einem Abonnement anlegt oder einen Fehlversuch
     * wiederholt, trifft sie regelmässig. Sie erneut prüfen zu lassen kostet
     * eine Anfrage und im Fehlerfall einen der fünf Versuche je Stunde.
     *
     * @return array{domain: string, token: string}|null `null`, wenn nichts hingelegt wurde
     */
    private function authorize(string $url): ?array
    {
        $authorization = $this->session->postAsGet($url)->json();
        $domain = self::identifier($authorization);

        if (self::text($authorization, 'status') === 'valid') {
            return null;
        }

        $challenge = null;

        foreach (self::objects($authorization, 'challenges') as $candidate) {
            if (self::text($candidate, 'type') === $this->challenge->type()) {
                $challenge = $candidate;

                break;
            }
        }

        if ($challenge === null) {
            throw AgentException::execFailed(sprintf(
                'Die Zertifizierungsstelle bietet für %s keine Prüfung der Art %s an.',
                $domain,
                $this->challenge->type(),
            ));
        }

        $token = self::text($challenge, 'token');
        $challengeUrl = self::text($challenge, 'url');

        if ($token === null || $challengeUrl === null) {
            throw AgentException::execFailed(sprintf('Die Prüfung für %s ist unvollständig beschrieben.', $domain));
        }

        $keyAuthorization = $token.'.'.$this->session->thumbprint();

        $this->challenge->present($domain, $token, $keyAuthorization);
        $this->awaitReady($domain, $token, $keyAuthorization);

        // Der leere Rumpf ist hier das leere Objekt — siehe Jws::sign().
        $this->session->post($challengeUrl, []);
        $this->awaitAuthorization($url, $domain);

        return ['domain' => $domain, 'token' => $token];
    }

    /**
     * Warten, bis die Aufgabe von aussen sichtbar ist.
     *
     * **Die Frist kommt von der Prüfung und nicht von hier.** Bis zum
     * 7. August 2026 galten für jede Bestellung dieselben 120 Sekunden. Das ist
     * kürzer, als lego für netcup und IONOS für nötig hält (900 Sekunden) und
     * für INWX (360) — und eine Bestellung, die zu früh aufgibt, verbrennt
     * einen der fünf Fehlversuche je Konto und Stunde, die für **jeden** Kunden
     * dieses Servers gelten. Umgekehrt hiesse eine Frist von fünfzehn Minuten
     * für alle, dass eine hängende Bestellung eine Operation des Agenten eine
     * Viertelstunde festhält.
     *
     * **Das Warten auf die Zertifizierungsstelle bleibt davon unberührt** —
     * dafür steht weiter {@see self::$timeoutSeconds}. Wie schnell ein
     * DNS-Anbieter seine Zone ausliefert und wie schnell Let's Encrypt eine
     * Autorisierung abschliesst, sind zwei verschiedene Fragen.
     */
    private function awaitReady(string $domain, string $token, string $keyAuthorization): void
    {
        $patience = $this->challenge->patience();
        $deadline = time() + $patience->seconds;

        while (! $this->challenge->ready($domain, $token, $keyAuthorization)) {
            if (time() >= $deadline) {
                throw new AgentException(
                    AgentException::TIMEOUT,
                    sprintf(
                        'Die Aufgabe für %s war nicht innerhalb von %d Sekunden von aussen sichtbar.',
                        $domain,
                        $patience->seconds,
                    ),
                );
            }

            sleep($patience->interval);
        }
    }

    private function awaitAuthorization(string $url, string $domain): void
    {
        $deadline = time() + $this->timeoutSeconds;

        while (true) {
            $authorization = $this->session->postAsGet($url)->json();
            $status = self::text($authorization, 'status');

            if ($status === 'valid') {
                return;
            }

            if ($status !== 'pending' && $status !== 'processing') {
                throw AgentException::execFailed(sprintf(
                    'Die Prüfung für %s ist fehlgeschlagen: %s',
                    $domain,
                    self::reason($authorization),
                ));
            }

            if (time() >= $deadline) {
                throw new AgentException(
                    AgentException::TIMEOUT,
                    sprintf('Die Prüfung für %s wurde nicht rechtzeitig beantwortet.', $domain),
                );
            }

            sleep($this->pollSeconds);
        }
    }

    private function awaitCertificate(string $orderUrl): string
    {
        $deadline = time() + $this->timeoutSeconds;

        while (true) {
            $order = $this->session->postAsGet($orderUrl)->json();
            $status = self::text($order, 'status');

            if ($status === 'valid') {
                $certificate = self::text($order, 'certificate');

                if ($certificate === null) {
                    throw AgentException::execFailed('Die Bestellung gilt, nennt aber kein Zertifikat.');
                }

                return $certificate;
            }

            if ($status !== 'ready' && $status !== 'processing') {
                throw AgentException::execFailed('Die Bestellung wurde nicht unterschrieben.');
            }

            if (time() >= $deadline) {
                throw new AgentException(
                    AgentException::TIMEOUT,
                    'Die Zertifizierungsstelle wurde mit der Bestellung nicht rechtzeitig fertig.',
                );
            }

            sleep($this->pollSeconds);
        }
    }

    /**
     * Der Name, um den es in dieser Autorisierung geht.
     *
     * @param  array<string, mixed>  $authorization
     */
    private static function identifier(array $authorization): string
    {
        $identifier = $authorization['identifier'] ?? null;
        $value = is_array($identifier) ? ($identifier['value'] ?? null) : null;

        if (! is_string($value)) {
            throw AgentException::execFailed('Die Autorisierung nennt keinen Namen.');
        }

        return $value;
    }

    /**
     * Warum eine Prüfung scheiterte — aus der Prüfung selbst, nicht geraten.
     *
     * @param  array<string, mixed>  $authorization
     */
    private static function reason(array $authorization): string
    {
        foreach (self::objects($authorization, 'challenges') as $challenge) {
            $error = $challenge['error'] ?? null;

            if (! is_array($error)) {
                continue;
            }

            $detail = $error['detail'] ?? null;

            if (is_string($detail) && $detail !== '') {
                return $detail;
            }
        }

        return 'Die Zertifizierungsstelle hat keinen Grund genannt.';
    }

    /** @param  array<string, mixed>  $fields */
    private static function text(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private static function urls(array $fields, string $key): array
    {
        $value = $fields[$key] ?? null;
        $urls = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item)) {
                    $urls[] = $item;
                }
            }
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private static function objects(array $fields, string $key): array
    {
        $value = $fields[$key] ?? null;
        $objects = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $entry = [];

                foreach ($item as $name => $inner) {
                    $entry[(string) $name] = $inner;
                }

                $objects[] = $entry;
            }
        }

        return $objects;
    }

    /** @param  null|callable(int, string): void  $progress */
    private function report(?callable $progress, int $percent, string $step): void
    {
        if ($progress !== null) {
            $progress($percent, $step);
        }
    }
}
