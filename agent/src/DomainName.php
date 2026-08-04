<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Was ein Domainname ist — die eine Stelle, die das entscheidet.
 *
 * **Warum das im Agenten steht und nicht im Panel.** Der Name wird zum
 * `server_name` in einem nginx-Block, zum Verzeichnisnamen unterhalb des
 * Abonnements und zum Namen einer Protokolldatei. Alle drei entstehen im
 * Agenten, und der Agent glaubt seinem Aufrufer nichts. Das Panel prüft
 * denselben Namen im Formular — aber mit *dieser* Regel, nicht mit einer
 * zweiten Formulierung davon. Dieselbe Entscheidung liegt schon hinter
 * {@see Ops\SubscriptionProvision::subscriptionName()}, und der Kommentar im
 * SubscriptionController sagt, warum: Ein Name, der im Panel durchginge und
 * hier scheiterte, ergäbe eine Domain, die ewig „wird angelegt" bliebe.
 *
 * **Nur ASCII.** Umlautdomains kommen in Punycode an (`xn--…`), umgewandelt
 * wird im Panel. Der Agent hat `intl` nicht — er hat nach §4.1 nur `json`,
 * `posix`, `sockets` und `pcntl`, und diese Liste ist eine Zusage. Ein
 * Umlautname, der hier ankommt, ist deshalb ein Fehler des Aufrufers und
 * keine Bequemlichkeit, die nachzurüsten wäre.
 *
 * **Kein Stern.** `*.beispiel.de` ist als `server_name` gültig und wäre für
 * ein Wildcard-Zertifikat in P4 verlockend. Als Domain im Bestand eines
 * Abonnements ist es das nicht: Der Name wird zum Verzeichnis, und ein
 * Verzeichnis `*.beispiel.de` ist ein Name, den jede Shell anders liest.
 */
final class DomainName
{
    /** Ein Name im DNS ist nie länger. */
    public const MAX_LENGTH = 253;

    /** Ein Bestandteil zwischen zwei Punkten ist nie länger. */
    public const MAX_LABEL_LENGTH = 63;

    /**
     * Den Namen prüfen und in seine Normalform bringen.
     *
     * Normalform heißt: kleingeschrieben, ohne den abschließenden Punkt der
     * absoluten DNS-Schreibweise, ohne Leerraum. Zwei Namen, die sich nur
     * darin unterscheiden, sind derselbe Name — und wenn das Panel sie als
     * zwei führte, stünden zwei `server_name` gleichen Inhalts in der
     * Konfiguration und nginx lieferte wortlos den ersten aus.
     */
    public static function normalize(mixed $value, string $field = 'domain'): string
    {
        $name = strtolower(trim(Guard::string($value, $field)));

        // Der Punkt am Ende ist DNS-Schreibweise und keine Abweichung.
        if (str_ends_with($name, '.')) {
            $name = substr($name, 0, -1);
        }

        if ($name === '' || strlen($name) > self::MAX_LENGTH) {
            throw self::rejected($field, $name);
        }

        $labels = explode('.', $name);

        // **Mindestens zwei Bestandteile.** Ein Name ohne Punkt ist ein
        // Rechnername im lokalen Netz, keine Domain, die jemand aufruft.
        // Ohne diese Bedingung wäre `localhost` ein zulässiger vhost — und
        // der beantwortete auf dem Server jede Anfrage, die keinen anderen
        // Namen trifft.
        if (count($labels) < 2) {
            throw self::rejected($field, $name);
        }

        foreach ($labels as $label) {
            if (! preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/D', $label)) {
                throw self::rejected($field, $name);
            }

            if (strlen($label) > self::MAX_LABEL_LENGTH) {
                throw self::rejected($field, $name);
            }
        }

        // **Die letzte Stelle trägt Buchstaben.** Damit ist `192.168.0.1`
        // keine Domain — sonst stünde eine IP-Adresse als `server_name` da
        // und das Abonnement bekäme ein Verzeichnis, das wie eine Adresse
        // aussieht. Punycode-TLDs (`xn--vermögensberatung`) sind erfasst:
        // sie beginnen mit Buchstaben.
        $tld = $labels[count($labels) - 1];

        if (! preg_match('/^[a-z][a-z0-9\-]{1,}$/D', $tld)) {
            throw self::rejected($field, $name);
        }

        return $name;
    }

    /**
     * Ist dieser Name ein Name unterhalb jenes Namens?
     *
     * Gebraucht wird das an zwei Stellen mit demselben Anspruch: Eine
     * Subdomain muss unterhalb ihrer Hauptdomain liegen, und ein Alias darf
     * nicht auf eine Domain zeigen, die einem anderen gehört.
     *
     * **Der Vergleich läuft über die Bestandteile und nicht über
     * `str_ends_with`.** `bösebeispiel.de` endet auf `beispiel.de`, ist aber
     * eine völlig andere Domain — mit einem Zeichenkettenvergleich hätte sich
     * ein fremder Name als Subdomain eines eigenen ausgeben lassen.
     */
    public static function isBelow(string $name, string $parent): bool
    {
        if ($name === $parent) {
            return false;
        }

        return str_ends_with($name, '.'.$parent);
    }

    private static function rejected(string $field, string $name): AgentException
    {
        return AgentException::badRequest(
            'Unzulässiger Domainname.',
            [$field => $name],
        );
    }
}
