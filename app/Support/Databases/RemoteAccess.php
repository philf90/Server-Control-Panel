<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\Enums\DatabaseEngine;
use App\Enums\DbUserStatus;
use App\Models\DbUser;
use App\Models\DbUserNetwork;
use App\Support\Tenancy\Tenancy;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Pg\Hba;

/**
 * Der Fernzugriff auf PostgreSQL — der Sollzustand, jedes Mal ganz.
 *
 * **Was an den Agenten geht, ist die Datei und nicht die Änderung.** Ein „trage
 * dieses eine Netz nach" wäre eine Operation, deren Ergebnis von der
 * Reihenfolge früherer Aufrufe abhängt — und `docs/42` hält für P5b zwei
 * Fehler genau dieser Bauart fest:
 *
 * > Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt die
 * > anderen Vorgänge derselben Reihe nicht.
 *
 * Deshalb liest {@see self::rules()} bei jedem Aufruf den ganzen Bestand und
 * schickt ihn. Der verwaltete Block ist damit immer das Abbild der Tabellen,
 * und zwei Vorgänge, die sich überholen, enden trotzdem beim selben Stand.
 *
 * **Das kostet einen unbeschränkten Blick auf den Bestand**, und das ist die
 * ausdrückliche Ausnahme aus der dritten Grenze: `pg_hba.conf` ist eine Datei
 * des *Servers*, nicht eines Abonnements. Wer sie je Mandant schriebe, schriebe
 * beim zweiten Kunden die Zeilen des ersten weg. Die Klammer bleibt für die
 * Frage „darf dieser Benutzer dieses Netz eintragen" zuständig — die
 * beantwortet der Controller, bevor er hier hereinkommt.
 *
 * **Unmittelbar und nicht über die Warteschlange.** Der Vorgang dauert
 * Millisekunden (ein Schreiben und ein `pg_reload_conf`), und der Kunde soll
 * die Fehlermeldung von PostgreSQL an seinem Formular lesen und nicht in einem
 * Protokoll suchen. Der Neustart, der lange dauern *kann*, hängt an `mode` —
 * und den setzt nur der Betreiber.
 */
final class RemoteAccess
{
    /**
     * Der Schalter für die Horchadresse steht dem Betreiber zu — sonst niemandem.
     *
     * `keep` ist das, was das Panel schickt: Netze schreiben, `listen_addresses`
     * nicht anfassen, nicht neu starten. `on` und `off` kommen aus
     * `srvpanel db --remote` und aus keinem Formular.
     */
    public const KEEP = 'keep';

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Client $agent,
    ) {}

    /**
     * Den verwalteten Block auf den Stand des Bestands bringen.
     *
     * @return array<string,mixed> die Antwort des Agenten
     */
    public function sync(string $mode = self::KEEP, ?string $address = null): array
    {
        $arguments = ['mode' => $mode, 'rules' => $this->rules()];

        if ($address !== null) {
            $arguments['address'] = $address;
        }

        return $this->agent->call('pg.remote.access', $arguments);
    }

    /**
     * Jede Zeile, die im Block stehen soll — Datenbank × Rolle × Netz.
     *
     * **Die Datenbank steht drin und nicht `all`** (`docs/38 §14.1`, M23): Eine
     * Rolle mit zwei Datenbanken und einem Netz bekommt zwei Zeilen. Das ist
     * die zweite Wand hinter dem `REVOKE CONNECT` — selbst wenn jemand das
     * Recht wieder aufmachte, käme sie über `pg_hba.conf` in keine andere.
     *
     * **Ein gesperrter Zugang bekommt keine Zeile.** `pg.role.lock` setzt
     * `NOLOGIN`, und das hält ihn schon draussen; die Zeile stehenzulassen wäre
     * eine zweite Fassung derselben Sperre, und die zweite ist die, die
     * veraltet. Beim Entsperren kommt sie mit dem nächsten Abgleich zurück.
     *
     * **Ein Zugang ohne Datenbank bekommt ebenfalls keine** — nicht weil es
     * verboten wäre, sondern weil es keine Zeile *gibt*, die man schreiben
     * könnte: Das erste Feld nach `host` ist eine Datenbank, und `all` ist hier
     * ausgeschlossen. Das ist ein Zustand, den das Panel erlaubt (`docs/42 §5`),
     * und er hat hier genau diese Wirkung.
     *
     * @return list<array{database: string, role: string, cidr: string}>
     */
    public function rules(): array
    {
        /** @var list<array{database: string, role: string, cidr: string}> $rules */
        $rules = [];

        $this->tenancy->withoutRestriction(function () use (&$rules): void {
            $users = DbUser::query()
                ->where('engine', DatabaseEngine::Postgres->value)
                ->whereNot('status', DbUserStatus::Locked->value)
                ->with(['networks', 'databases'])
                ->orderBy('name')
                ->get();

            foreach ($users as $user) {
                foreach ($user->databases as $database) {
                    foreach ($user->networks as $network) {
                        $rules[] = [
                            'database' => $database->name,
                            'role' => $user->name,
                            'cidr' => $network->cidr,
                        ];
                    }
                }
            }
        });

        return $rules;
    }

    /**
     * Den Block nachziehen, nachdem sich der Bestand geändert hat.
     *
     * ## Warum es diese Methode gibt
     *
     * Der Sollzustand ist **Datenbank × Rolle × Netz** ({@see self::rules()}),
     * und bis zum 11. August 2026 schrieb ihn nur, wer ein *Netz* anfasste.
     * Die Datenbanken einer Rolle ändern sich aber auch anderswo:
     *
     * | Weg | ohne diese Methode |
     * |---|---|
     * | „Vorhandenen Zugang verbinden" | die Zeile für die neue Datenbank fehlt |
     * | Zugriff entziehen | die Zeile bleibt stehen |
     * | Datenbank entfernt, Rolle überlebt | Zeile für eine Datenbank, die es nicht mehr gibt |
     * | Abonnement gesperrt | Datei und Bestand laufen auseinander |
     *
     * **Der erste ist der ernste**, und er ist kein Sicherheitsloch, sondern
     * das Gegenteil: Die fehlende Zeile sperrt aus. Im Panel steht „erreichbar
     * von 203.0.113.5", und die Anwendung kommt nicht herein — ein Fehler, der
     * wie ein kaputter Fernzugriff aussieht und keiner ist.
     *
     * ## Unmittelbar und nicht über die Warteschlange
     *
     * Das ist hier keine Bequemlichkeit, sondern die Lehre aus `docs/42`:
     *
     * > **Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt
     * > die anderen Vorgänge derselben Reihe nicht.**
     *
     * Ein eingereihter Vorgang trüge den Sollzustand von *jetzt* in seiner
     * Nutzlast; bis er liefe, hätten zwei weitere Änderungen stattgefunden, und
     * er schriebe einen Stand zurück, den niemand mehr wollte. Berechnet wird
     * er deshalb beim Ausführen — und das heisst: im selben Aufruf.
     *
     * ## Ohne ein einziges Netz passiert gar nichts
     *
     * Auf den allermeisten Servern gibt es keinen Fernzugriff. Ohne eine Zeile
     * in `db_user_networks` ist der Block leer und bleibt leer, und dieser
     * Aufruf spart sich den Weg zum Agenten. Ein `pg.remote.access` bei jedem
     * Verbinden einer MariaDB-Datenbank wäre ein Gang zum Datenbankserver für
     * nichts — auf einem Server, der PostgreSQL vielleicht gar nicht hat.
     *
     * **Der Vorbehalt dazu, damit ihn niemand für einen Fehler hält:** Bleibt
     * nach dem Entfernen des letzten Netzes eine Zeile liegen, räumt dieser
     * Weg sie nicht mehr weg. Das kann nicht passieren — {@see self::remove()}
     * schreibt den Block, *bevor* die letzte Zeile fehlt — und falls doch,
     * meldet `srvpanel db` sie als verwaist ({@see self::orphans()}).
     */
    public function follow(DatabaseEngine $engine): void
    {
        if ($engine !== DatabaseEngine::Postgres) {
            return;
        }

        if (! DbUserNetwork::query()->exists()) {
            return;
        }

        $this->sync();
    }

    /**
     * Was im Block steht und im Bestand nicht — **gemeldet und nicht gelöscht**.
     *
     * `docs/36 §5`, wörtlich: Wer löscht, weiss, was er löscht. Eine Zeile für
     * eine Rolle, die es nicht mehr gibt, ist für PostgreSQL kein Fehler (M22)
     * — sie bleibt liegen, und ohne diese Frage meldete es niemand.
     *
     * **Gefragt wird gegen die Zeilen und nicht gegen die Rollen des Servers.**
     * Was hier auffallen soll, ist der Unterschied zwischen der Datei und dem
     * Bestand des Panels; ob es die Rolle im Cluster noch gibt, ist eine andere
     * Frage, und `pg.server.info` beantwortet sie schon.
     *
     * @param  list<string>  $managed  die Zeilen, wie sie in der Datei stehen
     * @return list<string>
     */
    public function orphans(array $managed): array
    {
        $wanted = [];

        foreach ($this->rules() as $rule) {
            $wanted[Hba::rule($rule['database'], $rule['role'], $rule['cidr'])] = true;
        }

        return array_values(array_filter(
            $managed,
            static fn (string $line): bool => ! isset($wanted[$line]),
        ));
    }

    /**
     * Ein Netz eintragen — geprüft mit der Regel des Agenten.
     *
     * **Und die Prüfung ist die des Agenten und keine zweite hier.**
     * {@see Hba::cidr()} weist `0.0.0.0/0` ab, ergänzt eine fehlende
     * Präfixlänge und erklärt gesetzte Wirtsbits; eine eigene Formulierung im
     * Formular wäre die, die beim nächsten Mal auseinanderläuft. Dieselbe
     * Entscheidung wie bei `Names::host()` in P5.
     *
     * **Normalisiert gespeichert.** Was in die Tabelle geht, ist die
     * Schreibweise, die auch in der Datei steht — sonst fände {@see
     * self::orphans()} jede Zeile fremd, deren Kunde `203.0.113.5` statt
     * `203.0.113.5/32` getippt hat.
     *
     * @throws AgentException wenn das Netz nicht taugt
     */
    public function add(DbUser $user, string $cidr): DbUserNetwork
    {
        $network = DbUserNetwork::query()->firstOrCreate([
            'db_user_id' => (int) $user->id,
            'cidr' => Hba::cidr($cidr),
        ]);

        $this->sync();

        return $network;
    }

    /** Ein Netz zurücknehmen — und den Block sofort nachziehen. */
    public function remove(DbUserNetwork $network): void
    {
        $network->delete();

        $this->sync();
    }
}
