<?php

declare(strict_types=1);

namespace App\Support\Dns;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Der Durchgang im Hintergrund — wer drankommt und wann Schluss ist.
 *
 * **Warum es ihn gibt.** Der Knopf an der Domain misst, wenn jemand hinsieht.
 * Genau dann ist die Auskunft aber am wenigsten wert: Wer hinsieht, weiss
 * schon, dass er etwas umgestellt hat. Gebraucht wird sie in dem Fall, in dem
 * niemand hinsieht — die Domain zeigte gestern hierher und heute nicht mehr,
 * weil beim Anbieter jemand einen Eintrag angefasst hat.
 *
 * **Die Reihenfolge ist die halbe Grenze.** Ein Deckel ohne Reihenfolge ist
 * eine Auswahl, und eine Auswahl ohne Regel bevorzugt immer dieselben — die
 * mit der kleinsten Kennung. Gefragt wird deshalb nach dem Alter: erst, wer
 * noch nie gemessen wurde, dann der älteste Befund. Damit kommt jede Domain an
 * die Reihe, und zwar unabhängig davon, wie viele es sind.
 *
 * > **Eine Obergrenze ohne Reihenfolge ist keine Begrenzung, sondern eine
 * > Bevorzugung.**
 *
 * **Und was gemessen wird, misst auch der Knopf.** Der Lauf filtert nicht nach
 * Sorte: Ein Alias hat eine eigene Seite mit einem eigenen Abgleich, und wer
 * ihn hier überspränge, liesse genau diese Seite für immer auf „noch nie
 * geprüft" stehen. Der Preis steht daneben — die Namen eines Alias werden
 * zweimal gefragt, einmal unter seiner Elterndomain und einmal für ihn
 * selbst. Zwei Sätze Fragen sind billiger als eine Seite, die nie etwas sagt.
 *
 * **Ausgenommen ist allein, was gerade verschwindet.** Eine Domain im Rückbau
 * bekäme eine Zeile, die der Fremdschlüssel Sekunden später mitnimmt.
 */
final class Sweep
{
    /**
     * Wie lange ein Befund als frisch gilt.
     *
     * **Eine Untergrenze für den Abstand, keine Zusage über den Takt.** Auf
     * einem Server mit wenigen Domains hält sie den Lauf davon ab, dieselben
     * fremden Nameserver viermal in der Stunde zu fragen. Auf einem mit
     * fünfhundert wirkt sie gar nicht — dort entscheidet die Reihenfolge, und
     * eine Domain kommt seltener dran als stündlich. Beides ist richtig; falsch
     * wäre nur, das eine für das andere zu halten.
     */
    public const FRESH_MINUTES = 60;

    public function __construct(
        private readonly Dns $dns,
        private readonly Tenancy $tenancy,
        private readonly Budget $budget = new Budget,
    ) {}

    /**
     * Ein Lauf: nachsehen, messen, zählen.
     *
     * **`withoutRestriction` und nicht „läuft ja als Betreiber".** Dieser Lauf
     * hat überhaupt kein angemeldetes Konto — ein Timer startet ihn. Im
     * Grundzustand klammert {@see Tenancy} auf `0 = 1`, und die Kandidatenliste
     * wäre leer. Der Lauf meldete dann „0 fällig" und täte still nichts, was
     * genau so aussieht wie „alles frisch".
     *
     * Denselben Fehler hatte `Cron::store()` bis zum 19. August 2026: Der
     * Einsammler meldete „88 eingesammelt, 0 eingepflegt", und die 88 waren
     * fort.
     *
     * > **Zwei Stellen, die dieselbe Ausnahme brauchen, und nur eine hat sie:
     * > Die andere fällt nicht auf, weil sie leise das Richtige tut — nämlich
     * > nichts.**
     *
     * @return array{due: int, checked: int, silent: int, unasked: int, failed: int, left: int, seconds: float}
     */
    public function run(): array
    {
        $report = self::nothing();

        /*
         * **Über eine Referenz und nicht über den Rückgabewert**, und das ist
         * dieselbe Naht wie in `Cron::subscriptionsByUser()`. `withoutRestriction`
         * gibt zurück, was der Rückruf zurückgibt — bei einem `: array` also
         * „irgendein Feld" und nicht diese Form. Hier steht sie in der Variablen
         * und muss nicht aus dem Rückgabewert zurückgeraten werden.
         */
        $this->tenancy->withoutRestriction(function () use (&$report): void {
            $report = $this->go();
        });

        return $report;
    }

    /** @return array{due: int, checked: int, silent: int, unasked: int, failed: int, left: int, seconds: float} */
    private function go(): array
    {
        $started = microtime(true);
        $candidates = $this->candidates();

        $checked = 0;
        $silent = 0;
        $unasked = 0;
        $failed = 0;
        $done = 0;

        foreach ($candidates as $domain) {
            $names = $domain->serverNames();

            if (! $this->budget->room($done, microtime(true) - $started, count($names))) {
                break;
            }

            $done++;

            try {
                $findings = $this->dns->check($domain);
            } catch (Throwable) {
                /*
                 * **Ein Fehlschlag bleibt bei seiner Domain.** Derselbe Gedanke
                 * wie in {@see Survey}, eine Ebene höher: Wer hier durchreichte,
                 * liesse die eine kaputte Domain den Lauf für alle anderen
                 * beenden — und der Timer versuchte es in fünfzehn Minuten mit
                 * derselben, weil sie dann die älteste ist.
                 */
                $failed++;

                continue;
            }

            $checked++;

            /*
             * **Drei Ausgänge, und zwei davon sahen bis zum 22. August 2026
             * gleich aus.**
             *
             * „Nicht gefragt" heisst: Der Aufruf an den Agenten hat nicht
             * stattgefunden — er ist gescheitert, oder der Name liegt
             * ausserhalb seiner Zone. „Ohne Antwort" heisst: Er hat
             * stattgefunden, und kein Nameserver hat geantwortet. Das erste ist
             * ein Fehler bei uns, das zweite eine Auskunft über die Zone.
             *
             * Vorher zählte beides als „ohne Antwort", weil beides zu einer
             * leeren Nameserverliste führt. In der Zwischenabnahme hat das
             * einen Schritt gekostet (`docs/74`, Befund 1).
             *
             * > **Ein Fehlerweg, der sich vom Normalfall nicht unterscheiden
             * > lässt, ist keine Auskunft, sondern eine Vermutung.**
             *
             * **Die Reihenfolge ist Teil der Regel.** Wurde ein Name nicht
             * gefragt, ist die leere Liste damit erklärt — sie dann auch noch
             * als „ohne Antwort" zu zählen, hiesse denselben Vorgang zweimal
             * melden.
             */
            if ($this->wasUnasked($findings)) {
                $unasked++;
            } elseif ($this->wasSilent($findings)) {
                $silent++;
            }
        }

        return [
            'due' => $candidates->count(),
            'checked' => $checked,
            'silent' => $silent,
            'unasked' => $unasked,
            'failed' => $failed,
            'left' => max(0, $candidates->count() - $done),
            'seconds' => round(microtime(true) - $started, 1),
        ];
    }

    /**
     * Die Domains, die dran sind — die ältesten zuerst.
     *
     * **Die Sortierung steht ausgeschrieben und hängt nicht an der Datenbank.**
     * Ein blosses `order by checked_at` liefert die noch nie gemessenen zuerst,
     * weil `NULL` in beiden Systemen aufsteigend vorn steht — dieses Wissen ist
     * aus zweiter Hand und gälte für das dritte System vielleicht nicht mehr.
     * Der eigene Ausdruck sagt es, statt es sich zu wünschen.
     *
     * > **Ein Verhalten, das man nicht angibt, ist eine Vorgabe, die man nicht
     * > gemessen hat.**
     *
     * @return Collection<int, Domain>
     */
    private function candidates(): Collection
    {
        $stale = now()->subMinutes(self::FRESH_MINUTES);

        return Domain::query()
            ->leftJoin('domain_dns_checks', 'domain_dns_checks.domain_id', '=', 'domains.id')
            ->where('domains.status', '!=', DomainStatus::Removing)
            ->where(fn (Builder $query) => $query
                ->whereNull('domain_dns_checks.checked_at')
                ->orWhere('domain_dns_checks.checked_at', '<=', $stale))
            ->orderByRaw('case when domain_dns_checks.checked_at is null then 0 else 1 end')
            ->orderBy('domain_dns_checks.checked_at')
            ->orderBy('domains.id')
            ->select('domains.*')

            // Ohne diese Zeile fragt `serverNames()` je Domain einmal nach den
            // Aliassen — fünfundzwanzig Abfragen für eine Auskunft, die in
            // einer zu haben ist.
            ->with('children')
            ->get();
    }

    /**
     * Ist für diese Domain mindestens eine Frage gar nicht gestellt worden?
     *
     * @param  array<string, mixed>  $findings
     */
    private function wasUnasked(array $findings): bool
    {
        $inner = $findings['findings'] ?? null;

        return is_array($inner) && ($inner['unasked'] ?? []) !== [];
    }

    /**
     * Hat für diese Domain überhaupt jemand geantwortet?
     *
     * @param  array<string, mixed>  $findings
     */
    private function wasSilent(array $findings): bool
    {
        $inner = $findings['findings'] ?? null;

        return ! is_array($inner) || ($inner['nameservers'] ?? []) === [];
    }

    /** @return array{due: int, checked: int, silent: int, unasked: int, failed: int, left: int, seconds: float} */
    private static function nothing(): array
    {
        return ['due' => 0, 'checked' => 0, 'silent' => 0, 'unasked' => 0, 'failed' => 0, 'left' => 0, 'seconds' => 0.0];
    }
}
