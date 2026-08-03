<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Names;
use SrvPanel\Agent\Op;

/**
 * Was für ein Zertifikat liefert die Oberfläche gerade aus?
 *
 * **Getrennt vom Ausstellen, weil es etwas anderes ist.** `panel.tls.ensure`
 * ändert das System und gehört deshalb in einen Vorgang mit Protokolleintrag.
 * Nachsehen ändert nichts — und eine Seite, die bei jedem Aufruf einen
 * verändernden Vorgang auslöst, wäre eine Seite, die man nicht öffnen mag.
 *
 * **Der private Schlüssel kommt hier nicht vor.** Herausgegeben werden die
 * Angaben, die auch jeder Browser sieht, der die Seite aufruft: Name,
 * Aussteller, Laufzeit, abgedeckte Namen. Dazu die Frage, ob der Rechner heute
 * noch unter diesen Namen zu erreichen ist — das ist die Auskunft, die einem
 * Betreiber sagt, ob er neu ausstellen sollte.
 */
final class PanelTlsInfo implements Op
{
    public function __construct(private readonly string $directory = '/etc/srvpanel/tls') {}

    public static function name(): string
    {
        return 'panel.tls.info';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $path = $this->directory.'/panel.crt';

        if (! is_file($path)) {
            return ['present' => false, 'reason' => 'Es liegt kein Zertifikat unter '.$path.'.'];
        }

        $parsed = openssl_x509_parse((string) file_get_contents($path));

        if (! is_array($parsed)) {
            return ['present' => false, 'reason' => 'Das Zertifikat unter '.$path.' lässt sich nicht lesen.'];
        }

        $covered = Names::fromCertificate($parsed);
        $current = Names::forThisHost();

        return [
            'present' => true,
            'path' => $path,
            'subject' => (string) ($parsed['subject']['CN'] ?? ''),
            'issuer' => (string) ($parsed['issuer']['CN'] ?? ''),

            /*
             * Selbstsigniert heisst: Aussteller gleich Inhaber. Die Angabe
             * steht hier und nicht in der Oberfläche, weil sie ab P4 die
             * Antwort auf die eigentliche Frage ist — läuft hier noch die
             * Notlösung oder schon ein Zertifikat von Let's Encrypt?
             */
            'self_signed' => ($parsed['subject'] ?? null) === ($parsed['issuer'] ?? null),
            'valid_from' => (int) ($parsed['validFrom_time_t'] ?? 0),
            'valid_to' => (int) ($parsed['validTo_time_t'] ?? 0),
            'names' => $covered,

            /*
             * Die Namen, unter denen der Rechner erreichbar ist und die das
             * Zertifikat **nicht** abdeckt. Der häufigste Fall dahinter ist
             * eine neue IP-Adresse: Sie erneuert das Zertifikat nicht von
             * selbst (das gäbe auf einem Server mit Docker jede Woche ein
             * neues), aber sie gehört angezeigt — sonst sucht jemand den
             * Fehler im Browser.
             */
            'missing' => [
                'dns' => array_values(array_diff($current['dns'], $covered['dns'])),
                'ip' => array_values(array_diff($current['ip'], $covered['ip'])),
            ],
        ];
    }
}
