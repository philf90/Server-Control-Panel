<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\AuditResult;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Support\Time\Clock;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die eine Abfrage hinter dem Protokoll.
 *
 * **Ansicht und Export müssen dieselbe Sichtbarkeit haben.** Das ist kein
 * Ordnungswunsch, sondern der Grund, warum diese Klasse existiert: Der
 * naheliegende Fehler bei einer Export-Funktion ist eine sorgfältig gefilterte
 * Liste und ein Export, der „schnell noch" mit einer eigenen Abfrage gebaut
 * wurde. Er fällt niemandem auf, weil beide für den Betreiber gleich aussehen
 * — und liefert einem Kunden die Datei mit allem darin.
 *
 * Deshalb gibt es hier genau eine Methode, die die Sichtbarkeit herstellt, und
 * beide Wege gehen durch sie hindurch. Ein Test vergleicht das Ergebnis
 * zusätzlich Zeile für Zeile mit der Policy — zwei Formulierungen derselben
 * Regel, die auseinanderlaufen können, tun das sonst irgendwann.
 */
final class AuditQuery
{
    /**
     * Was dieses Konto sehen darf.
     *
     * `AuditEvent` trägt keine Mandantenklammer — ein Protokoll muss auch
     * Ereignisse aufnehmen, die zu keinem Abonnement gehören. Die Sichtbarkeit
     * steht deshalb hier, an einer Stelle, statt unsichtbar in einem globalen
     * Filter.
     *
     * @return Builder<AuditEvent>
     */
    public static function visibleTo(Account $account): Builder
    {
        $query = AuditEvent::query();

        if ($account->isAdmin()) {
            return $query;
        }

        $subscriptionIds = $account->accessibleSubscriptionIds();

        return $query->where(function (Builder $outer) use ($account, $subscriptionIds): void {
            // Die eigenen Ereignisse — Anmeldungen etwa, die zu keinem
            // Abonnement gehören.
            $outer->where('account_id', $account->id);

            if ($subscriptionIds !== []) {
                $outer->orWhereIn('subscription_id', $subscriptionIds);
            }
        });
    }

    /**
     * Die Filter anwenden.
     *
     * Jeder Wert kommt aus der Adresszeile und wird entsprechend behandelt:
     * Unbekannte Ergebnisse fallen weg, Zeitangaben ohne Sinn ebenfalls, und
     * die Freitextsuche geht über gebundene Parameter.
     *
     * @param  Builder<AuditEvent>  $query
     * @param  array<string,mixed>  $filters
     * @return Builder<AuditEvent>
     */
    public static function filter(Builder $query, array $filters): Builder
    {
        /*
         * **Die Grenzen kommen in der Anzeigezone herein und gehen als UTC
         * hinaus** (`docs/40 §3.2`). Hier stand `$filters['from'].' 00:00:00'`
         * — ein Datum aus dem Formular, roh gegen eine Spalte in UTC gehalten.
         * Solange die Anzeige ebenfalls UTC war, stimmte das; sobald sie
         * umrechnet, sucht der Filter in einem anderen Tag als dem, den die
         * Seite zeigt.
         *
         * > **Ein Filter, der eine andere Zeitrechnung benutzt als die Anzeige
         * > daneben, findet die Zeile nicht, die er selbst anzeigt.**
         *
         * `null` heisst „unlesbares Datum": Dann fällt der Filter weg, statt
         * gegen eine erfundene Grenze zu suchen — dieselbe Wahl wie bei den
         * übrigen Filtern hier.
         */
        if (is_string($filters['from'] ?? null) && $filters['from'] !== '') {
            $from = Clock::boundaryToUtc($filters['from'], end: false);

            if ($from !== null) {
                $query->where('created_at', '>=', $from);
            }
        }

        if (is_string($filters['to'] ?? null) && $filters['to'] !== '') {
            $to = Clock::boundaryToUtc($filters['to'], end: true);

            if ($to !== null) {
                $query->where('created_at', '<=', $to);
            }
        }

        if (is_string($filters['action'] ?? null) && $filters['action'] !== '') {
            // Präfixsuche: „auth." findet alles zur Anmeldung. Der Platzhalter
            // steht nur hinten, damit die Abfrage den Index benutzen kann.
            $query->where('action', 'like', self::escapeLike($filters['action']).'%');
        }

        if (is_string($filters['result'] ?? null) && $filters['result'] !== '') {
            $result = AuditResult::tryFrom($filters['result']);

            if ($result !== null) {
                $query->where('result', $result->value);
            }
        }

        if (is_string($filters['ip'] ?? null) && $filters['ip'] !== '') {
            $query->where('ip_address', $filters['ip']);
        }

        if (is_numeric($filters['subscription_id'] ?? null)) {
            // Kein Sicherheitsproblem: Die Sichtbarkeit steht schon davor. Wer
            // hier ein fremdes Abonnement einträgt, bekommt eine leere Liste.
            $query->where('subscription_id', (int) $filters['subscription_id']);
        }

        return $query;
    }

    /**
     * Sonderzeichen in einer LIKE-Suche entschärfen.
     *
     * Ohne das wäre ein `%` in der Eingabe ein Platzhalter — aus der
     * Präfixsuche „auth." würde mit „%" eine Suche über alles, und die
     * Einschränkung wäre wirkungslos.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Die Sortierung: neueste zuerst, mit der ID als Stichentscheid.
     *
     * Ohne den zweiten Schlüssel ist die Reihenfolge bei gleichem Zeitstempel
     * unbestimmt — und weil Einträge im selben Sekundenbruchteil entstehen,
     * kommt das vor. Beim Blättern sähe man dann Zeilen doppelt und andere gar
     * nicht.
     *
     * @param  Builder<AuditEvent>  $query
     * @return Builder<AuditEvent>
     */
    public static function newestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Ein Ereignis für die Oberfläche — und für den Export.
     *
     * Dieselbe Abbildung für beide Wege, aus demselben Grund wie die
     * Sichtbarkeit: Zwei Formulierungen laufen auseinander.
     *
     * @return array<string, mixed>
     */
    public static function toArrayRow(AuditEvent $event): array
    {
        return [
            'id' => (int) $event->id,
            /*
             * **In der Anzeigezone, und das CSV nimmt einen anderen Weg.**
             * Diese Ablage geht auf die Seite; der Export baut seine Zeile in
             * {@see \App\Http\Controllers\AuditController} aus derselben
             * Zeile, ersetzt aber diesen Wert durch UTC. Ein Zeitstempel ohne
             * Zone in einer Datei, die drei Jahre liegt, ist eine Falle
             * (`docs/40 §3.3`).
             */
            'created_at' => Clock::display($event->created_at),
            'action' => $event->action,
            'result' => $event->result->value,
            'result_label' => $event->result->label(),
            'account_id' => $event->account_id,
            'acting_as_account_id' => $event->acting_as_account_id,
            'subscription_id' => $event->subscription_id,
            'target' => $event->target_type !== null
                ? class_basename($event->target_type).'#'.$event->target_id
                : null,
            'ip_address' => $event->ip_address,
        ];
    }
}
