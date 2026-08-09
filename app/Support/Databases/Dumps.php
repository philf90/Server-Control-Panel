<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\Enums\DumpKind;
use App\Enums\DumpStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Jobs\RunAgentOperation;
use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use RuntimeException;

/**
 * Sichern, zurückspielen, aufräumen — der Dienst dazu.
 *
 * **Alle drei laufen über die Warteschlange**, und zwar aus dem Grund, den Plan
 * §5.3 nennt: Sie dauern länger als eine HTTP-Anfrage. Ein `mysqldump` über
 * vierzig Gigabyte ist kein hängender Prozess, sondern ein arbeitender, und er
 * gehört in die Vorgangsliste, wo man ihm zusehen kann.
 *
 * Das unterscheidet sie von allem in {@see Databases}: Dort geht nichts über
 * die Warteschlange, was ein Passwort trägt. Hier trägt nichts ein Passwort —
 * der befristete Zugang zum Zurückspielen entsteht im Agenten und überquert den
 * Socket nie.
 *
 * **Der Ablagename entsteht hier und nicht im Agenten.** Er muss eindeutig
 * sein, und eindeutig ist er gegen den Bestand des Panels — der Agent kennt ihn
 * nicht. Was der Agent prüft, ist die *Form* (`Dump::storageName()`); was das
 * Panel sicherstellt, ist die Einmaligkeit.
 */
final class Dumps
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Eine Sicherung anlegen.
     *
     * Die Zeile entsteht **vor** dem Vorgang und steht auf `Pending`: Ohne sie
     * gäbe es nichts, worauf `subject_id` zeigen könnte, und der Lebenslauf
     * wüsste nachher nicht, welche Sicherung fertig ist.
     */
    public function export(Database $database, ?int $accountId = null): Operation
    {
        $subscription = $this->subscriptionOf($database);

        if ($subscription === null) {
            throw new RuntimeException('Zu dieser Datenbank gibt es kein Abonnement mehr.');
        }

        $dump = $this->record($database, DumpKind::Export);

        return $this->dispatch($dump, $subscription, DumpLifecycle::task($database->engine, 'create'), sprintf(
            'Sicherung von %s wird erstellt',
            $database->name,
        ), $accountId);
    }

    /**
     * Eine Sicherung zurückspielen.
     *
     * **Die Datei muss fertig sein.** Eine, die noch läuft oder gescheitert
     * ist, wäre halb — und eine halbe Sicherung einzuspielen ist die eine
     * Handlung, die aus einem Versehen einen Datenverlust macht.
     */
    public function restore(DatabaseDump $dump, Database $database, ?int $accountId = null): Operation
    {
        if (! $dump->status->usable()) {
            throw new RuntimeException('Diese Sicherung ist nicht fertig — sie lässt sich nicht zurückspielen.');
        }

        $subscription = $this->subscriptionOf($database);

        if ($subscription === null) {
            throw new RuntimeException('Zu dieser Datenbank gibt es kein Abonnement mehr.');
        }

        return $this->dispatch($dump, $subscription, DumpLifecycle::task($database->engine, 'restore'), sprintf(
            'Sicherung %s wird in %s zurückgespielt',
            $dump->storage_name,
            $database->name,
        ), $accountId, ['name' => $database->name]);
    }

    /**
     * Eine mitgebrachte Sicherung übernehmen.
     *
     * **Die Datei liegt schon im Schreibbereich des Panels**; hier wird nur der
     * Pfad weitergereicht. Über den Socket geht ein halbes Gigabyte nicht —
     * wortgleich der Grund, aus dem eine Sicherung beim Herunterladen nicht
     * zurückgereicht wird.
     *
     * **`kind` ist `imported` und nicht `export`, und das ist keine Kosmetik.**
     * Eine Sicherung, die dieser Server geschrieben hat, ist etwas anderes als
     * eine, die jemand mitgebracht hat: Beim Zurückspielen wird die Datenbank
     * geleert, und wer dabei zwischen den beiden nicht unterscheiden kann,
     * trifft die Wahl blind.
     */
    public function import(Database $database, string $source, ?int $accountId = null): Operation
    {
        $subscription = $this->subscriptionOf($database);

        if ($subscription === null) {
            throw new RuntimeException('Zu dieser Datenbank gibt es kein Abonnement mehr.');
        }

        $dump = $this->record($database, DumpKind::Imported);

        return $this->dispatch($dump, $subscription, DumpLifecycle::task($database->engine, 'import'), sprintf(
            'Sicherung für %s wird übernommen',
            $database->name,
        ), $accountId, ['source' => $source]);
    }

    /** Eine Sicherung entfernen — die Datei zuerst, die Zeile danach. */
    public function remove(DatabaseDump $dump, ?int $accountId = null): Operation
    {
        $subscription = $this->tenancy->withoutRestriction(
            fn (): ?Subscription => Subscription::query()->find($dump->subscription_id)
        );

        if ($subscription === null) {
            throw new RuntimeException('Zu dieser Sicherung gibt es kein Abonnement mehr.');
        }

        return $this->dispatch($dump, $subscription, DumpLifecycle::task($dump->engine, 'remove'), sprintf(
            'Sicherung %s wird entfernt',
            $dump->storage_name,
        ), $accountId);
    }

    /**
     * Das ganze Verzeichnis eines Abonnements — beim Rückbau.
     *
     * **Ein Aufruf und nicht einer je Datei.** Was hier weggeht, ist ein
     * Verzeichnis unter `/var/lib/srvpanel/dumps`, und `subscription.remove`
     * fasst es nicht an: Es liegt ausserhalb des Abo-Verzeichnisses — dieselbe
     * Lage wie bei den Zertifikatsverzeichnissen, die `docs/35` zutage gebracht
     * hat.
     */
    public function removeAllFor(Subscription $subscription, ?int $accountId = null): ?Operation
    {
        $any = $this->tenancy->withoutRestriction(
            fn (): bool => DatabaseDump::query()->where('subscription_id', $subscription->id)->exists()
        );

        // Kein Vorgang für ein Abonnement, das nie gesichert hat — sonst stünde
        // bei jedem Rückbau einer in der Liste, der nichts getan hat.
        if (! $any) {
            return null;
        }

        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'account_id' => $accountId ?? request()->user()?->getAuthIdentifier(),
            'type' => 'db.dump.remove',
            'task' => 'db.dump.remove',
            'payload' => ['subscription' => (string) $subscription->name],
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => 'Sicherungen von '.$subscription->name.' werden entfernt',
        ]);

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }

    /**
     * Die Zeile zur Sicherung — mit einem Namen, den es noch nicht gibt.
     *
     * Der Name trägt Datenbank und Zeitpunkt, damit ein Mensch ihn im
     * Verzeichnis wiedererkennt, und acht Hexziffern, damit zwei Sicherungen
     * derselben Sekunde sich nicht überschreiben. Die Form ist die, die
     * `Dump::storageName()` im Agenten annimmt.
     */
    private function record(Database $database, DumpKind $kind): DatabaseDump
    {
        $name = sprintf(
            '%s-%s-%s',
            str_replace('_', '-', $database->name),
            now()->format('Ymd-His'),
            bin2hex(random_bytes(4)),
        );

        $dump = new DatabaseDump([
            'database_id' => (int) $database->id,
            'database_name' => $database->name,
            'storage_name' => $name,

            // **Das System der Datenbank und nicht der Vorgabewert der Spalte.**
            // Eine Sicherung gehört zu dem System, das sie geschrieben hat —
            // beim Zurückspielen entscheidet es, welche Operation läuft, und
            // beim Rückbau, welche Zeilen mitgehen.
            'engine' => $database->engine,

            'kind' => $kind,
            'status' => DumpStatus::Pending,
        ]);

        $dump->subscription_id = $database->subscription_id;
        $dump->save();

        return $dump;
    }

    /**
     * Einen Vorgang für eine Sicherung einreihen.
     *
     * **Kein Wert aus der Anfrage erreicht den Agenten.** Abonnementname und
     * Systembenutzer kommen aus der abgelegten Zeile, der Ablagename aus der
     * Sicherung — dieselbe Regel wie in `Lifecycle::payload()` und
     * `WebLifecycle::payload()`.
     *
     * @param  array<string, mixed>  $extra
     */
    private function dispatch(
        DatabaseDump $dump,
        Subscription $subscription,
        string $task,
        string $message,
        ?int $accountId,
        array $extra = [],
    ): Operation {
        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'subject_type' => OperationSubject::Dump->value,
            'subject_id' => $dump->id,
            'account_id' => $accountId ?? request()->user()?->getAuthIdentifier(),
            'type' => $task,
            'task' => $task,
            'payload' => array_merge([
                /*
                 * **Hier fehlt `prefix`, und das ist der offene Rest von
                 * Schritt 6.** `db.*` prüft gegen den Systembenutzer
                 * (`p1001`), `pg.*` gegen das Präfix (`x7f3a…`) — dieselbe
                 * Frage, zwei Antworten (`docs/38 §4`). Woher das Präfix kommt,
                 * weiss heute nur {@see Engines\PostgresDriver::prefix()}, und
                 * es hier ein zweites Mal aus `system_users` zu holen wäre die
                 * zweite Fassung, die veraltet.
                 *
                 * Solange das nicht aufgelöst ist, stehen `pg.dump.create`,
                 * `pg.restore` und `pg.dump.import` **nicht** in der
                 * Registratur — es gibt also keinen Weg, auf dem ein
                 * unvollständiges Payload den Agenten erreichte.
                 */
                'user' => (string) $subscription->system_user,
                'subscription' => (string) $subscription->name,
                'name' => $dump->database_name,
                'storage' => $dump->storage_name,
            ], $extra),
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => $message,
        ]);

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }

    /**
     * Das Abonnement einer Datenbank — **ohne Mandantenklammer**.
     *
     * Dieselbe Begründung wie in {@see Databases::subscriptionOf()}: Die
     * Datenbank ist bereits durch die Klammer gekommen, und im Arbeiter ist
     * niemand angemeldet.
     */
    private function subscriptionOf(Database $database): ?Subscription
    {
        return $this->tenancy->withoutRestriction(
            fn (): ?Subscription => Subscription::query()->find($database->subscription_id)
        );
    }
}
