<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\Acme\Dns\DnsProvider;
use SrvPanel\Agent\Acme\Dns\Lookup;
use SrvPanel\Agent\Acme\Dns\Resolver;
use SrvPanel\Agent\DomainName;

/**
 * DNS-01: ein TXT-Eintrag unter `_acme-challenge`.
 *
 * **Der einzige Weg zu einem Platzhalter.** Über HTTP-01 gibt es kein
 * Wildcard — die Zertifizierungsstelle lässt es nicht zu, und ihre Meldung
 * dazu nennt den Grund nicht.
 *
 * **Der Unterschied zu HTTP-01 sind zwei Schritte, nicht der Ablauf.**
 * Bestellen, Autorisierungen holen, Aufgabe erfüllen, prüfen lassen, abwarten,
 * unterschreiben lassen, abholen — das steht in {@see Order} und ohne
 * Fallunterscheidung da. Verschieden sind hinlegen und abräumen, und
 * verschieden ist vor allem {@see self::ready()}.
 *
 * **`ready()` ist hier keine Formalie, sondern der Grund für die
 * Schnittstelle.** Bei HTTP-01 liegt die Datei, sobald sie geschrieben ist. Ein
 * TXT-Eintrag ist dagegen nicht da, weil die API des Anbieters „ok" gesagt hat,
 * sondern erst, wenn die autoritativen Nameserver ihn ausliefern — Sekunden
 * bis Minuten später. Wer der Prüfstelle zu früh sagt „prüf jetzt", verbrennt
 * einen der fünf Fehlversuche je Konto und Stunde, und die gelten für jeden
 * Kunden dieses Servers.
 *
 * **Der Stern fällt weg.** Für `*.example.de` heisst der Eintrag
 * `_acme-challenge.example.de` — derselbe wie für `example.de`. Das ist keine
 * Eigenheit dieses Panels, sondern RFC 8555: Der Platzhalter steht in der
 * Bestellung, nicht im Namen der Prüfung. Beide Namen in einer Bestellung
 * ergeben deshalb zwei Werte unter demselben Namen, und beide müssen
 * dastehen — deshalb legt {@see DnsProvider::add()} an und ersetzt nicht.
 */
final class DnsChallenge implements Challenge
{
    public const TYPE = 'dns-01';

    /** Unter diesem Namen sucht die Zertifizierungsstelle. */
    public const PREFIX = '_acme-challenge';

    /**
     * Was hingelegt wurde — je Domain und Token.
     *
     * **Weil `cleanup()` den Wert braucht und ihn nicht bekommt.** Die
     * Schnittstelle reicht Domain und Token durch; der Wert entsteht aus der
     * Schlüsselautorisierung, und die steht dort nicht mehr zur Verfügung. Ihn
     * beim Abräumen wegzulassen hiesse, alle `_acme-challenge`-Einträge dieser
     * Zone zu löschen — auch den einer Bestellung, die gerade parallel läuft.
     *
     * @var array<string, string>
     */
    private array $presented = [];

    public function __construct(
        private readonly DnsProvider $provider,
        private readonly Lookup $resolver = new Resolver,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * Die Geduld kommt vom Anbieter.
     *
     * **Es gibt sie hier nicht als eigene Zahl**, weil sie keine Eigenschaft
     * von DNS-01 ist, sondern eine des Anbieters: netcup braucht bis zu
     * fünfzehn Minuten, IPv64.net eine. Eine Zahl an dieser Stelle wäre die,
     * die für einen von beiden falsch ist.
     */
    public function patience(): Patience
    {
        return $this->provider->patience();
    }

    /**
     * Der Wert, den die Zertifizierungsstelle sehen will.
     *
     * Base64url über den SHA-256 der Schlüsselautorisierung, ohne die
     * Auffüllzeichen — so steht es in RFC 8555 §8.4.
     */
    public static function value(string $keyAuthorization): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $keyAuthorization, true)), '+/', '-_'), '=');
    }

    /**
     * Der Name des Eintrags zu einer Domain.
     *
     * Der Stern eines Platzhalters fällt weg: Gefragt wird die Zone, nicht der
     * Name mit dem Stern.
     */
    public static function record(string $domain): string
    {
        $name = strtolower(trim($domain));

        if (str_starts_with($name, '*.')) {
            $name = substr($name, 2);
        }

        return self::PREFIX.'.'.DomainName::normalize($name, 'domain');
    }

    public function present(string $domain, string $token, string $keyAuthorization): void
    {
        $value = self::value($keyAuthorization);

        $this->presented[$this->key($domain, $token)] = $value;

        $this->provider->add(self::record($domain), $value);
    }

    /**
     * Liefern **alle** autoritativen Nameserver den Wert aus?
     *
     * **Alle und nicht einer.** Welchen die Zertifizierungsstelle fragt, weiss
     * niemand; sie fragt sogar aus mehreren Netzen zugleich. Ein Wert, den nur
     * die Hälfte der Server kennt, ist eine Prüfung, die manchmal gelingt —
     * und das ist die unangenehmste Sorte Fehler.
     *
     * **Kein Nameserver bedeutet „noch nicht" und nicht „nie".** Auch die
     * NS-Auskunft kann gerade fehlen; eine Ausnahme daraus zu machen hiesse,
     * eine Bestellung an einem Schluckauf des Auflösers scheitern zu lassen.
     * Der Aufrufer wartet weiter, bis sein Zeitlimit greift.
     */
    public function ready(string $domain, string $token, string $keyAuthorization): bool
    {
        $record = self::record($domain);
        $wanted = self::value($keyAuthorization);
        $servers = $this->resolver->nameservers($record);

        if ($servers === []) {
            return false;
        }

        foreach ($servers as $server) {
            if (! in_array($wanted, $this->resolver->txt($server, $record), true)) {
                return false;
            }
        }

        return true;
    }

    public function cleanup(string $domain, string $token): void
    {
        $key = $this->key($domain, $token);
        $value = $this->presented[$key] ?? null;

        if ($value === null) {
            return;
        }

        unset($this->presented[$key]);

        $this->provider->remove(self::record($domain), $value);
    }

    private function key(string $domain, string $token): string
    {
        return strtolower(trim($domain)).'|'.$token;
    }
}
