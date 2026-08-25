<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Account;
use App\Support\Time\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Die offenen Sitzungen eines Kontos — ansehen und beenden.
 *
 * ## Warum das über den Query Builder geht und nicht über ein Model
 *
 * `sessions` ist Laravels eigene Tabelle. Ein Model darüber wäre eine zweite
 * Fassung ihres Aufbaus: Ändert das Framework die Spalten, hätte dieses Repo
 * eine Klasse, die weiter die alten verspricht. Gelesen werden vier Spalten,
 * und alle vier stehen hier.
 *
 * ## Was **nicht** gelesen wird
 *
 * `payload`. Dort liegt die serialisierte Sitzung — der CSRF-Schlüssel, was das
 * Konto zuletzt getan hat, und was künftig jemand hineinschreibt. Für die Frage
 * „welche Sitzungen gibt es" braucht es davon nichts.
 *
 * > **Eine Spalte, die man nicht liest, kann nichts verraten.**
 *
 * ## Die eigene Sitzung ist erkennbar, aber nicht gesperrt
 *
 * Wer seine laufende Sitzung beendet, meldet sich ab — das ist eine sinnvolle
 * Handlung („ich sitze an einem fremden Rechner") und keine Falle, solange
 * dransteht, welche es ist. Ein Knopf, der sie verschweigt, wäre die Falle.
 */
final class Sessions
{
    /**
     * Die offenen Sitzungen eines Kontos, die jüngste zuerst.
     *
     * @return list<array{id: string, ip: string|null, agent: string|null, last: string|null, current: bool}>
     */
    public static function of(Account $account, ?string $currentId): array
    {
        $rows = DB::table('sessions')
            ->select(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->where('user_id', $account->id)
            ->orderByDesc('last_activity')
            ->get();

        $sessions = [];

        foreach ($rows as $row) {
            $last = is_numeric($row->last_activity ?? null)
                ? Carbon::createFromTimestampUTC((int) $row->last_activity)
                : null;

            $sessions[] = [
                'id' => (string) $row->id,
                'ip' => is_string($row->ip_address ?? null) ? $row->ip_address : null,
                'agent' => self::agent($row->user_agent ?? null),
                'last' => Clock::display($last),
                'current' => $currentId !== null && (string) $row->id === $currentId,
            ];
        }

        return $sessions;
    }

    /** Eine Sitzung beenden. Gibt zurück, ob es sie überhaupt gab. */
    public static function forget(Account $account, string $id): bool
    {
        /*
         * **Mit `user_id` in der Bedingung und nicht nur mit der Kennung.**
         * Sonst beendete eine geratene oder abgeschriebene Sitzungskennung die
         * Sitzung eines fremden Kontos — und die Kennung steht im Cookie des
         * Betroffenen, ist also nicht geheim gegenüber ihm selbst.
         */
        return DB::table('sessions')
            ->where('user_id', $account->id)
            ->where('id', $id)
            ->delete() > 0;
    }

    /**
     * Das Gerät hinter einer Sitzung — kurz und lesbar.
     *
     * **Gekürzt und nicht ausgewertet.** Eine Kennung wie „Mozilla/5.0
     * (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 …" ist 120 Zeichen
     * lang und sagt dem Leser nichts, was er nicht schon weiss. Was er sucht,
     * ist „war das mein Rechner" — und dafür genügt der Anfang.
     *
     * Eine Auswertung nach Browser und System wäre eine Bibliothek mit einer
     * Tabelle, die veraltet: Ein neuer Browser hiesse dann „unbekannt", und das
     * ist die schlechtere Auskunft als die rohe Zeile.
     *
     * > **Eine Auswertung, die einen Fall nicht kennt, sagt weniger als der
     * > ungedeutete Wert.**
     */
    private static function agent(mixed $agent): ?string
    {
        if (! is_string($agent) || $agent === '') {
            return null;
        }

        return mb_strlen($agent) > 120 ? mb_substr($agent, 0, 119).'…' : $agent;
    }
}
