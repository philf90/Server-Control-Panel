<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\DbUser;
use App\Support\Tenancy\Tenancy;

/**
 * Was ein misslungener Rückbau liegengelassen hat — und was davon fort darf.
 *
 * **Warum eine eigene Klasse und keine Methode im Kommando.** Wortgleich die
 * Begründung von `App\Support\Tls\CertificatePrune` — als Name und nicht als
 * `{@see}`, denn Pint zöge daraus einen `use`, und dieser Namensraum bräuchte
 * dann einen Import, den nur ein Kommentar benutzt. Die Auswahl ist die
 * ganze Sicherheit dieses Aufräumens. Stünde sie im Kommando, müsste ein Test
 * sie nachbauen — und dann gäbe es zwei Fassungen derselben Regel, von denen
 * die im Test grün bleibt, während die im Kommando abdriftet.
 *
 * **Die Regel, und sie ist enger als bei den Zertifikaten.** Ein Zertifikat
 * überlebt sein Abonnement als Wegweiser; eine Datenbank soll das gerade nicht
 * (docs/36 §5). Verwaist ist deshalb genau, was `subscription_id` auf `null`
 * stehen hat und die Abschrift `subscription_name` noch trägt: Der Rückbau des
 * Abonnements ist durchgelaufen, der Vorgang, der das Schema entfernen sollte,
 * nicht.
 *
 * **Ohne die Abschrift wäre die Zeile nicht auffindbar**, und genau deshalb
 * steht sie in der Migration. Eine Zeile ohne `subscription_name` und ohne
 * `subscription_id` gibt es nicht — sie käme aus einer Datenbank, an der jemand
 * von Hand war, und dann ist raten die falsche Antwort. Sie bleibt liegen und
 * wird gemeldet.
 *
 * **Drei Sorten und nicht eine.** P5 hinterlässt Schemata, Datenbankbenutzer
 * und Sicherungsdateien; jede hat ihren eigenen Weg zurück und ihre eigene
 * Operation. Sie hier zusammenzufassen wäre bequem und falsch: Ein Schema, das
 * nicht weggeht, darf die Sicherung nicht am Aufräumen hindern.
 *
 * **Ohne Mandantenklammer.** Aufgeräumt wird auf dem ganzen Server, und ein
 * Kommando ohne gesetzten Mandanten sähe sonst keine einzige Zeile — es meldete
 * „nichts zu tun" und liesse alles liegen.
 */
final class DatabasePrune
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Was zu tun ist — ohne dass etwas getan wird.
     *
     * Jede Sorte kommt als Liste aus Bezeichner und Abonnementname: Der Name
     * ist das, was ein Betreiber wiedererkennt, und der Bezeichner ist das, was
     * die Operation danach bekommt.
     *
     * @return array{
     *     databases: list<array{id: int, name: string, subscription: string, size_bytes: int|null}>,
     *     users: list<array{id: int, name: string, host: string, subscription: string}>,
     *     dumps: list<array{id: int, name: string, subscription: string, bytes: int|null}>,
     *     total: int,
     * }
     */
    public function plan(): array
    {
        return $this->tenancy->withoutRestriction(static function (): array {
            $databases = Database::query()
                ->whereNull('subscription_id')
                ->whereNotNull('subscription_name')
                ->orderBy('name')
                ->get(['id', 'name', 'subscription_name', 'size_bytes'])
                ->map(static fn (Database $row): array => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'subscription' => (string) $row->subscription_name,
                    'size_bytes' => $row->size_bytes === null ? null : (int) $row->size_bytes,
                ])
                ->all();

            $users = DbUser::query()
                ->whereNull('subscription_id')
                ->whereNotNull('subscription_name')
                ->orderBy('name')
                ->get(['id', 'name', 'host', 'subscription_name'])
                ->map(static fn (DbUser $row): array => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'host' => $row->host,
                    'subscription' => (string) $row->subscription_name,
                ])
                ->all();

            $dumps = DatabaseDump::query()
                ->whereNull('subscription_id')
                ->whereNotNull('subscription_name')
                ->orderBy('storage_name')
                ->get(['id', 'storage_name', 'subscription_name', 'bytes'])
                ->map(static fn (DatabaseDump $row): array => [
                    'id' => (int) $row->id,
                    'name' => $row->storage_name,
                    'subscription' => (string) $row->subscription_name,
                    'bytes' => $row->bytes === null ? null : (int) $row->bytes,
                ])
                ->all();

            return [
                'databases' => array_values($databases),
                'users' => array_values($users),
                'dumps' => array_values($dumps),
                'total' => count($databases) + count($users) + count($dumps),
            ];
        });
    }

    /**
     * Die Zeile einer aufgeräumten Datenbank löschen — und nur die.
     *
     * Aufgerufen, **nachdem** der Agent das Schema entfernt hat. Andersherum
     * wäre es unauffindbar: Die Zeile ist der einzige Ort, an dem der Name noch
     * steht. Dieselbe Reihenfolge wie bei `CertificatePrune::forget()`, und
     * derselbe Grund.
     */
    public function forgetDatabase(int $id): int
    {
        return $this->forget(Database::class, $id);
    }

    public function forgetUser(int $id): int
    {
        return $this->forget(DbUser::class, $id);
    }

    public function forgetDump(int $id): int
    {
        return $this->forget(DatabaseDump::class, $id);
    }

    /**
     * **Die Waisenbedingung steht auch beim Löschen dabei.**
     *
     * Nicht aus Vorsicht, sondern weil sich zwischen `plan()` und dem Löschen
     * etwas geändert haben kann: Ein Aufräumen, das eine Kennung von vorhin
     * blind löscht, träfe eine Zeile, die inzwischen wieder zu einem Abonnement
     * gehört. Mit der Bedingung geht sie schlicht nicht weg, und das Kommando
     * meldet null.
     *
     * @param  class-string<Database|DbUser|DatabaseDump>  $model
     */
    private function forget(string $model, int $id): int
    {
        return $this->tenancy->withoutRestriction(static fn (): int => $model::query()
            ->whereKey($id)
            ->whereNull('subscription_id')
            ->whereNotNull('subscription_name')
            ->delete());
    }
}
