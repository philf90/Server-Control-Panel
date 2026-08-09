<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\Models\Database;
use App\Models\DbUser;
use App\Models\Operation;
use App\Support\Operations\AfterOperation;
use App\Support\Tenancy\Tenancy;

/**
 * Was nach einem PostgreSQL-Vorgang am Bestand nachzuziehen ist.
 *
 * **Ein eigener Lebenslauf und keine Erweiterung von {@see DbLifecycle}**, und
 * der Grund ist derselbe wie für `pg.*` neben `db.*` (`docs/38 §8`): Die
 * Antworten der beiden Operationen haben nicht dieselbe Form. `db.database.remove`
 * meldet `users_removed` als `name@host`, `pg.database.remove` meldet
 * `roles_removed` als blosse Rollennamen — ein gemeinsamer Lebenslauf müsste in
 * jeder Methode danach fragen, welches System gemeint ist, und das wären zwei
 * Fassungen derselben Regel in einer Datei.
 *
 * **Er ist deutlich kürzer, und das ist kein Zwischenstand.** `DbLifecycle`
 * beantwortet acht Aufgaben, weil P5 Sicherungen, Zurückspielen und die Sperre
 * mitbringt. Hier steht bis Schritt 5 und 6 nur der Rückbau; was dazukommt,
 * kommt mit seiner Operation.
 */
final class PgLifecycle implements AfterOperation
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * **Nur der Rückbau, und das ist die vollständige Liste.**
     *
     * Anlegen, Zugang, Passwort und Rechte laufen unmittelbar und nicht über
     * die Warteschlange — teils weil sie ein Passwort tragen (`docs/38 §4`),
     * teils weil sie Millisekunden dauern. Ihre Zeile schreibt {@see Databases}
     * selbst, nachdem der Agent geantwortet hat.
     *
     * @return list<string>
     */
    public static function handles(): array
    {
        return ['pg.database.remove'];
    }

    /**
     * Beim Fehlschlag bleibt alles stehen, wie es war.
     *
     * **Und das ist hier mehr als eine leere Methode.** `pg.database.remove`
     * wirft die Datenbank und danach die Rollen (gemessen, `docs/38 §24.3`);
     * bricht sie ab, ist der Zustand der von vorher — der Kunde hat seine Daten
     * und seinen Zugang. Die Zeile steht auf `Removing` und der Vorgang auf
     * „gescheitert"; beides ist wahr und beides gehört gesehen, bevor jemand
     * einen zweiten Anlauf nimmt.
     *
     * Ein automatischer Rückweg auf `Active` wäre eine Behauptung über den
     * Server, die dieser Lebenslauf nicht geprüft hat.
     */
    public function afterFailure(Operation $operation): void {}

    public function afterSuccess(Operation $operation): void
    {
        if ((string) ($operation->task ?? '') !== 'pg.database.remove') {
            return;
        }

        $this->removed($operation);
    }

    /**
     * Die Datenbank ist fort — jetzt erst geht die Zeile, und die Rollen mit.
     *
     * **Gelesen wird die Antwort und nicht die Absicht.** Was der Agent
     * tatsächlich entfernt hat, steht in `roles_removed`; zwischen Einreihen
     * und Antwort kann sich etwas geändert haben. Dieselbe Entscheidung wie in
     * {@see DbLifecycle::removed()} und in `WebLifecycle::appliedStatus()`.
     *
     * **Der Name allein genügt als Schlüssel.** In MariaDB gehört der Wirt
     * dazu — `'p1001_web'@'localhost'` und derselbe Name an einer anderen
     * Adresse sind zwei Benutzer. Eine PostgreSQL-Rolle ist clusterweit
     * eindeutig, und die Zeile im Panel trägt ihren Wirt nur, weil das
     * Datenmodell eines ist.
     */
    private function removed(Operation $operation): void
    {
        $this->tenancy->withoutRestriction(function () use ($operation): void {
            $database = Database::query()->find($operation->subject_id);

            if ($database === null) {
                return;
            }

            $removed = $operation->result['roles_removed'] ?? [];

            if (is_array($removed)) {
                foreach ($removed as $role) {
                    if (! is_string($role)) {
                        continue;
                    }

                    DbUser::query()->where('name', $role)->delete();
                }
            }

            $database->delete();
        });
    }
}
