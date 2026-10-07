<?php

declare(strict_types=1);

namespace App\Mail\Notice;

use App\Enums\FindingCheck;
use App\Mail\Concerns\PlainText;
use App\Support\Diagnose\Checks\LatestBackups;
use LogicException;

/**
 * Was die Mail an den Kunden über eine gescheiterte Sicherung sagt (B9,
 * `docs/142 §4`).
 *
 * **Sie nennt, was noch da ist:** die jüngste gelungene Sicherung mit ihrem
 * Zeitpunkt, oder dass es keine gibt. Wer liest, dass die Sicherung der Nacht
 * gescheitert ist, fragt als Erstes, was er im Fall der Fälle hätte.
 *
 * **Dass die nächste von selbst kommt, sagt sie nur, wenn sie kommt** — wenn
 * der Betreiber die Automatik eingeschaltet hat und der Plan einen Stand
 * aufbewahrt. Sonst wäre es eine Zusage, die niemand hält.
 *
 * Welche Sicherung zählt und wann gemeldet wird, steht an
 * {@see LatestBackups}.
 */
final class BackupSection implements Section
{
    use PlainText;

    /**
     * @param  string  $reason  der Grund des Befundes — heute nur `failed`
     * @param  null|string  $created  wann die gescheiterte Sicherung angelegt wurde, in der Anzeigezone
     * @param  null|string  $error  was ihr Vorgang gemeldet hat
     * @param  null|string  $lastGood  wann die jüngste gelungene angelegt wurde, `null` ohne eine
     * @param  bool  $automatic  ob die nächste von selbst kommt
     */
    public function __construct(
        private readonly string $reason,
        private readonly ?string $created,
        private readonly ?string $error,
        private readonly ?string $lastGood,
        private readonly bool $automatic,
    ) {
        // Früh und nicht erst beim Rendern: Ein Grund ohne Überschrift soll
        // dort auffallen, wo die Mail gebaut wird.
        self::headline($reason);
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
            'failed' => 'Sicherung fehlgeschlagen',
            default => throw new LogicException(sprintf('Für den Grund „%s" gibt es keine Überschrift.', $reason)),
        };
    }

    public function headlines(): array
    {
        return [self::headline($this->reason)];
    }

    public function lines(): array
    {
        $zeilen = [];

        foreach (self::wrap(FindingCheck::BackupLatest->sentence($this->reason), self::WIDTH - 2) as $i => $teil) {
            $zeilen[] = ($i === 0 ? '- ' : '  ').$teil;
        }

        foreach (['Erstellt' => $this->created, 'Meldung' => $this->error] as $beschriftung => $wert) {
            if ($wert !== null && trim($wert) !== '') {
                array_push($zeilen, ...self::detailLines($beschriftung, $wert));
            }
        }

        /** @var non-empty-list<string> $zeilen */
        return $zeilen;
    }

    public function paragraphs(): array
    {
        $absaetze = [
            $this->lastGood === null
                ? 'Eine gelungene Sicherung dieses Abonnements gibt es nicht.'
                : sprintf('Die jüngste gelungene Sicherung dieses Abonnements ist vom %s.', $this->lastGood),
        ];

        if ($this->automatic) {
            $absaetze[] = 'Die nächste Sicherung legt das Panel in der kommenden Nacht von selbst an.';
        }

        return array_map(static fn (string $a): string => implode("\n", self::wrap($a, self::WIDTH)), $absaetze);
    }
}
