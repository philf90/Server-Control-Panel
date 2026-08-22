<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie ein erwarteter Eintrag im wirklichen DNS dasteht.
 *
 * **Fünf Zustände und nicht drei.** `docs/72 §2.3` nennt drei — zeigt hierher,
 * zeigt woandershin, fehlt. Beim Bauen von Schritt 2 sind zwei dazugekommen,
 * und beide bezeichnen etwas, das der Kunde anders behandeln muss:
 *
 * - **{@see Unreachable}** ist kein Zustand der Zone, sondern einer der
 *   Messung. Ohne ihn meldete die Anzeige „der Eintrag fehlt", wenn in
 *   Wahrheit der Nameserver schweigt — und schickte den Kunden dorthin, wo
 *   nichts zu ändern ist.
 * - **{@see Inconsistent}** heisst, dass die Nameserver derselben Zone
 *   verschiedene Antworten geben. Die Domain funktioniert dann für einen Teil
 *   der Welt und für den anderen nicht, und die Abhilfe liegt beim Anbieter
 *   und nicht am Eintrag.
 *
 * > **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von
 * > beiden.**
 *
 * **„Zeigt woandershin" ist ausdrücklich kein Fehler.** Ein Kunde, der seine
 * Domain absichtlich über ein CDN oder einen fremden Dienst führt, hat genau
 * diesen Zustand und will keine rote Meldung. Die Anzeige sagt, was ist, und
 * nicht, was falsch ist; die Wertung gehört dorthin, wo sie eine Folge hat.
 */
enum DnsRecordState: string
{
    /** Jeder ausgelieferte Wert gehört diesem Server. */
    case Here = 'here';

    /** Es steht etwas da, und mindestens eines davon ist nicht unseres. */
    case Elsewhere = 'elsewhere';

    /** Die Nameserver haben geantwortet, und es gibt keinen solchen Satz. */
    case Missing = 'missing';

    /** Die Nameserver derselben Zone sagen Verschiedenes. */
    case Inconsistent = 'inconsistent';

    /** Es kam keine brauchbare Antwort — über die Zone ist damit nichts gesagt. */
    case Unreachable = 'unreachable';

    public function label(): string
    {
        return match ($this) {
            self::Here => 'Zeigt hierher',
            self::Elsewhere => 'Zeigt woandershin',
            self::Missing => 'Fehlt',
            self::Inconsistent => 'Nameserver uneinig',
            self::Unreachable => 'Nicht erreichbar',
        };
    }

    /**
     * Was der Kunde daraufhin tun kann — oder dass er nichts tun kann.
     *
     * **Der letzte Satz ist der wichtigste.** Ein Hinweis, der bei „nicht
     * erreichbar" zum Ändern eines Eintrags auffordert, schickt jemanden
     * dorthin, wo nichts zu ändern ist.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Here => 'Der Eintrag steht richtig.',
            self::Elsewhere => 'Der Eintrag zeigt auf eine andere Adresse. Das kann so gewollt sein — etwa hinter einem Dienst, der die Anfragen weiterreicht.',
            self::Missing => 'Es gibt keinen solchen Eintrag. Er wird beim Anbieter der Zone angelegt.',
            self::Inconsistent => 'Die Nameserver dieser Zone liefern Verschiedenes aus. Das ist beim Anbieter der Zone zu klären.',
            self::Unreachable => 'Die Nameserver der Zone haben nicht geantwortet. Über den Eintrag ist damit nichts gesagt.',
        };
    }

    /** Steht dieser Zustand einer Website im Weg? */
    public function blocking(): bool
    {
        return match ($this) {
            self::Here => false,
            // **Nicht blockierend, und das ist die Entscheidung aus §2.3.**
            // Wer über ein CDN fährt, hat diesen Zustand absichtlich.
            self::Elsewhere => false,
            self::Missing, self::Inconsistent => true,
            // Über die Zone ist nichts gesagt — also auch nicht, dass etwas
            // im Weg steht.
            self::Unreachable => false,
        };
    }
}
