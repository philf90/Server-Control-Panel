<?php

declare(strict_types=1);

namespace App\Mail\Notice;

use App\Enums\FindingCheck;
use App\Mail\Concerns\PlainText;
use App\Support\Diagnose\Checks\Certificates;
use App\Support\Language\Counted;
use App\Support\Tls\CertificateRenewal;
use LogicException;

/**
 * Was die Mail an den Kunden über die Zertifikate seiner Domains sagt (B9,
 * `docs/142 §4`).
 *
 * ## Nur die Laufzeit, und je Domain der schwerere Grund
 *
 * **Der Kunde bekommt `tls.expiry` und nichts sonst.** Ob die Datei da ist und
 * die Namen deckt und ob der Server sie ausliefert, sind Zustände des Servers;
 * die bekommt der Betreiber auf „Diagnose" und über den Webhook (`docs/142 §7`).
 *
 * **Auf der Grenze nennt sie „abgelaufen" und nicht beides** — wie
 * {@see QuotaSection} beim Platz. Ein abgelaufenes Zertifikat hat beide
 * Befunde ({@see Certificates::expiry()}); „läuft demnächst ab" neben „ist
 * abgelaufen" widerspräche sich.
 *
 * ## Sie sagt, was der Kunde tun kann, und das hängt an der Herkunft
 *
 * - **Let's Encrypt:** Das Panel erneuert selbst. Gewarnt wird erst zwei
 *   Nächte nach der Fälligkeit; steht die Mail da, ist die Erneuerung nicht
 *   gelungen. Sie nennt, seit wann sie fällig ist und was der letzte Versuch
 *   gemeldet hat, und dass sich die Bestellung auf der Seite der Domain sofort
 *   neu anstossen lässt.
 * - **Hochgeladen:** Das erneuert niemand. Ob der Kunde ein neues selbst
 *   hochladen darf, entscheidet sein Plan, und der Satz sagt es danach.
 *
 * **Die Zeitpunkte stehen in der Anzeigezone und mit ihrer Zone** — der Satz
 * am Befund steht in UTC, weil die Prüfung ohne Framework rechnet; die Mail
 * liest den Zeitpunkt am Zertifikat.
 */
final class CertificateSection implements Section
{
    use PlainText;

    /** @var non-empty-list<array{domain: string, reason: string, valid_to: string, renewed: bool, due_since: null|string, last_attempt: null|string}> */
    private readonly array $certificates;

    /**
     * @param  non-empty-list<array{domain: string, reason: string, valid_to: string, renewed: bool, due_since: null|string, last_attempt: null|string}>  $certificates  je Befund eine Zeile
     * @param  bool  $mayUpload  ob der Plan des Abonnements eigene Zertifikate erlaubt
     */
    public function __construct(
        array $certificates,
        private readonly bool $mayUpload,
    ) {
        $this->certificates = self::shown($certificates);
    }

    /**
     * Was die Mail nennt: je Domain „abgelaufen" ohne „läuft demnächst ab".
     *
     * @param  non-empty-list<array{domain: string, reason: string, valid_to: string, renewed: bool, due_since: null|string, last_attempt: null|string}>  $certificates
     * @return non-empty-list<array{domain: string, reason: string, valid_to: string, renewed: bool, due_since: null|string, last_attempt: null|string}>
     */
    public static function shown(array $certificates): array
    {
        $abgelaufen = [];

        foreach ($certificates as $c) {
            if ($c['reason'] === 'expired') {
                $abgelaufen[$c['domain']] = true;
            }
        }

        $gezeigt = array_values(array_filter(
            $certificates,
            static fn (array $c): bool => ! ($c['reason'] === 'expiring' && isset($abgelaufen[$c['domain']])),
        ));

        return $gezeigt === [] ? $certificates : $gezeigt;
    }

    /**
     * Die Überschrift eines Grundes — für den Betreff.
     *
     * **Der Rückfall wirft**, aus demselben Grund wie bei
     * {@see QuotaSection::headline()}.
     */
    public static function headline(string $reason): string
    {
        return match ($reason) {
            'expiring' => 'Zertifikat läuft ab',
            'expired' => 'Zertifikat abgelaufen',
            default => throw new LogicException(sprintf('Für den Grund „%s" gibt es keine Überschrift.', $reason)),
        };
    }

    public function headlines(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $c): string => self::headline($c['reason']),
            $this->certificates,
        )));
    }

    public function lines(): array
    {
        $zeilen = [];

        foreach ($this->certificates as $c) {
            foreach (self::wrap(FindingCheck::TlsExpiry->sentence($c['reason']), self::WIDTH - 2) as $i => $teil) {
                $zeilen[] = ($i === 0 ? '- ' : '  ').$teil;
            }

            $angaben = [
                'Domain' => $c['domain'],
                'Gültig bis' => $c['valid_to'],
                'Erneuerung fällig seit' => $c['renewed'] ? $c['due_since'] : null,
                'Letzter Versuch' => $c['renewed'] ? $c['last_attempt'] : null,
            ];

            foreach ($angaben as $beschriftung => $wert) {
                if ($wert !== null && $wert !== '') {
                    array_push($zeilen, ...self::detailLines($beschriftung, $wert));
                }
            }
        }

        /** @var non-empty-list<string> $zeilen */
        return $zeilen;
    }

    public function paragraphs(): array
    {
        $absaetze = [];
        $erneuert = in_array(true, array_column($this->certificates, 'renewed'), true);
        $hochgeladen = in_array(false, array_column($this->certificates, 'renewed'), true);

        if (in_array('expired', array_column($this->certificates, 'reason'), true)) {
            $absaetze[] = 'Ein abgelaufenes Zertifikat lehnen Browser ab: Wer die Domain öffnet, '
                .'sieht eine Warnung statt Ihrer Website.';
        }

        if ($erneuert) {
            // **Kein „jede Nacht".** Ein Platzhalter, dessen Zugangsdaten fehlen,
            // wird gar nicht bestellt ({@see CertificateRenewal}); einen Versuch
            // nennt die Zeile darüber nur, wenn es einen gab.
            $absaetze[] = sprintf(
                'Zertifikate von Let’s Encrypt erneuert das Panel selbst, ab %s vor dem Ablauf. '
                .'Auf der Seite der Domain im Panel lässt sich die Bestellung sofort neu anstossen.',
                Counted::of(CertificateRenewal::LEAD_DAYS, 'Tag', 'Tagen'),
            );
        }

        if ($hochgeladen) {
            $absaetze[] = 'Ein hochgeladenes Zertifikat erneuert das Panel nicht; die Domain braucht '
                .'ein neues. '.($this->mayUpload
                    ? 'Sie können es auf der Seite der Domain im Panel hochladen.'
                    : 'Hochladen kann es Ihr Anbieter.');
        }

        return array_map(static fn (string $a): string => implode("\n", self::wrap($a, self::WIDTH)), $absaetze);
    }
}
