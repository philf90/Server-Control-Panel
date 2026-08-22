<?php

declare(strict_types=1);

namespace App\Support\Dns;

use SrvPanel\Agent\Acme\Dns\Resolver;
use SrvPanel\Agent\Ops\DnsCheck;

/**
 * Die Grenze eines Durchgangs — zwei Zahlen und die Reserve dazwischen.
 *
 * **Warum eine Zahl allein nicht reicht.** Der naheliegende Deckel ist „so
 * viele Domains je Lauf", und er wäre falsch: Eine Domain kostet nicht, was
 * eine andere kostet. Sie hat einen Namen oder zwölf (jeder Alias ist ein
 * eigener Aufruf, {@see Survey}), und ihre Nameserver antworten in
 * Millisekunden oder gar nicht. Zwischen dem billigsten und dem teuersten Fall
 * liegen drei Grössenordnungen.
 *
 * > **Eine Grenze über die Zahl der Vorgänge ist keine über ihre Dauer,
 * > solange der einzelne Vorgang unterschiedlich lange braucht.**
 *
 * Deshalb steht neben der Anzahl eine Frist, und die Frist ist die, die hält.
 *
 * **Und eine Frist braucht ihre Reserve.** „Noch Zeit übrig" vor einem Vorgang
 * unbekannter Dauer ist keine Aussage über sein Ende: Eine Domain, die mit
 * einer Sekunde Restfrist angefangen wird, läuft trotzdem, bis ihre
 * Nameserver geantwortet haben oder in ihr Zeitlimit gelaufen sind. Gerechnet
 * wird deshalb mit dem schlimmsten Fall **dieser** Domain, und der hängt an
 * der Zahl ihrer Namen.
 *
 * > **Eine Frist, die vor einem Vorgang unbekannter Dauer geprüft wird, ist
 * > eingehalten, solange niemand misst, wann er endet.**
 *
 * **Die Reserve gilt nicht für die erste Domain**, und das ist keine
 * Bequemlichkeit. Eine Domain mit zwölf Aliassen hat eine Reserve, die grösser
 * ist als die ganze Frist — sie käme nie an die Reihe, in keinem Lauf, und
 * niemand sähe warum. Aus der Grenze würde eine Sperre, und zwar eine stille.
 *
 * > **Eine Reserve, die den ersten Vorgang verhindert, macht aus einer Grenze
 * > eine Sperre.**
 *
 * **Die Zahlen der Reserve werden nicht abgeschrieben**, sondern dort gefragt,
 * wo sie gelten: {@see DnsCheck::MAX_SERVERS} und
 * {@see Resolver::TIMEOUT_SECONDS}. Eine zweite Fassung derselben Zahl ist die,
 * die veraltet — und hier fiele es niemandem auf, weil ein zu kleiner Deckel
 * nur bedeutet, dass der Lauf gelegentlich überzieht.
 */
final class Budget
{
    /**
     * So viele Domains höchstens je Lauf.
     *
     * Nicht wegen der Last hier, sondern wegen der Nameserver dort: Der Lauf
     * fragt fremde Server, und fünfhundert Domains in einer Minute sehen von
     * dort aus nicht wie ein Panel aus. Der Rest kommt beim nächsten Lauf dran
     * — die Reihenfolge sorgt dafür, dass es immer der älteste ist
     * ({@see Sweep}).
     */
    public const DOMAINS = 25;

    /**
     * Und so lange höchstens.
     *
     * Vier Minuten bei einem Takt von fünfzehn: Der Lauf ist lange fertig,
     * bevor der nächste fällig wird. Das ist die Bedingung, an der es hängt —
     * systemd startet einen Dienst nicht ein zweites Mal, solange der erste
     * noch läuft, und ein Timer, dessen Dienst dauernd hängt, feuert nie
     * wieder, ohne dass es eine Fehlermeldung dazu gäbe.
     */
    public const SECONDS = 240;

    public function __construct(
        private readonly int $domains = self::DOMAINS,
        private readonly int $seconds = self::SECONDS,
    ) {}

    /**
     * Was eine Domain mit so vielen Namen schlimmstenfalls kostet, in Sekunden.
     *
     * Je Name ein Aufruf von `dns.check`, und der fragt jeden Nameserver, bis
     * einer schweigt — ein stummer Server kostet dort einmal sein Zeitlimit
     * und wird danach übergangen. Mehr als `Server × Zeitlimit` je Aufruf kann
     * die Warterei also nicht werden.
     *
     * **Was hier nicht drinsteht:** die Suche nach den Nameservern selbst. Die
     * geht über den Systemauflöser, und dessen Frist gehört ihm. Die Reserve
     * ist damit eine Untergrenze des schlimmsten Falls und keine Obergrenze —
     * gesagt, statt sie für vollständig zu halten.
     */
    public static function reserve(int $names): int
    {
        return max(1, $names) * DnsCheck::MAX_SERVERS * Resolver::TIMEOUT_SECONDS;
    }

    /**
     * Darf noch eine Domain mit so vielen Namen angefangen werden?
     *
     * **Gefragt wird vorher und nicht nachher.** Eine Grenze, die erst nach dem
     * Überschreiten zuschlägt, ist keine Grenze, sondern eine Nachricht.
     *
     * @param  int  $done  Wieviele Domains dieser Lauf schon hinter sich hat
     * @param  float  $elapsed  Wie lange er dafür gebraucht hat, in Sekunden
     * @param  int  $names  Wieviele Namen die nächste Domain trägt
     */
    public function room(int $done, float $elapsed, int $names): bool
    {
        if ($done >= $this->domains) {
            return false;
        }

        // Die erste Domain kommt immer dran — auch wenn ihre Reserve die ganze
        // Frist übersteigt und auch, wenn die Frist schon abgelaufen ist. Sonst
        // hinge das Merkmal an einer Domain, die niemand als Ursache erkennt.
        if ($done === 0) {
            return true;
        }

        return $elapsed + (float) self::reserve($names) <= (float) $this->seconds;
    }
}
