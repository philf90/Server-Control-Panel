<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Enums\BackupStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Jobs\RunAgentOperation;
use App\Models\Backup;
use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\Operation;
use App\Models\Subscription;
use App\Models\SystemUser;
use App\Support\Databases\Dumps;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SrvPanel\Agent\Backup\Store;

/**
 * Der Weg vom Panel zu `backup.create` und `backup.remove`.
 *
 * ## Warum das Panel die Reihenfolge herstellt und nicht der Agent
 *
 * Eine Sicherung braucht zuerst je Datenbank einen Dump (`db.dump.create`
 * beziehungsweise `pg.dump.create`, beide seit P5/P5b) und dann `backup.create`,
 * das deren Ausgabe hineinlegt. Keine Operation dieses Agenten ruft eine
 * andere — die Kette gehört hierher.
 *
 * > **Kein neuer Weg** (`docs/117 §6` Schritt 4).
 *
 * ## Der Name der Ablage entsteht hier und nicht im Browser
 *
 * `<abo>-<datum>-<zeit>` mit einem Zeitstempel in **UTC**. Der Kunde sieht ihn
 * in seiner Anzeigezone (`App\Support\Time\Clock`); der Dateiname ist ein
 * Bezeichner und trägt deshalb die Zone nicht, in der gerade jemand liest.
 *
 * > **Ein Format, das für Bezeichner reicht, reicht nicht für Werte** — und
 * > andersherum genauso.
 */
final class Backups
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Dumps $dumps,
        private readonly Description $description,
    ) {}

    /**
     * Eine Sicherung einreihen — erst die Datenbanken, dann das Archiv.
     *
     * ## Die Reihenfolge stellt das Panel her, und sie ist gemessen
     *
     * `backup.create` legt fertige Dumps in das Archiv und erzeugt sie nicht
     * (`docs/117 §6` Schritt 4) — keine Operation dieses Agenten ruft eine
     * andere. Die Kette gehört hierher, und dass sie trägt, hängt an drei
     * gemessenen Eigenschaften:
     *
     * - **Ein Worker, eine Spur.** `srvpanel-worker.service` fährt
     *   `queue:work --queue=operations,default` ohne `--max-processes`.
     * - **Der Datenbanktreiber gibt FIFO.** Wer zuerst eingereiht wird, läuft
     *   zuerst.
     * - **Die Warteschlange teilt sich die Verbindung mit den Vorgängen.**
     *   `config/queue.php` lässt `DB_QUEUE_CONNECTION` ungesetzt, also
     *   committen Zeile und Job zusammen. Ohne das wäre `after_commit => false`
     *   ein Rennen: Der Worker sähe den Job vor der Zeile.
     *
     * **Und ein Dump, der scheitert, bricht die Sicherung laut ab** statt sie
     * ohne Datenbanken auszugeben — `BackupCreate` weist einen benannten Dump
     * ab, der nicht liegt.
     *
     * > **Ein Archiv, das stillschweigend weniger enthält, ist schlimmer als
     * > keines: Es sieht aus wie eines.**
     *
     * ## Zeile und Vorgang in einer Transaktion
     *
     * **Beides oder keines.** Eine Zeile ohne Vorgang stünde für immer auf
     * „wird erstellt"; ein Vorgang ohne Zeile schriebe eine Datei, die in
     * keiner Liste steht und die deshalb niemand entfernt.
     */
    public function create(Subscription $subscription): Backup
    {
        $storage = $this->storageName($subscription);

        // **Vor der Transaktion**, und das ist Absicht: Jeder Dump ist ein
        // eigener Vorgang mit eigener Zeile, und die sollen stehen, auch wenn
        // das Anlegen der Sicherung danach scheitert. Eine Datenbank, die
        // gesichert wurde, ist gesichert.
        $dumps = $this->dumpEveryDatabase($subscription);
        $description = $this->description->of($subscription);

        return DB::transaction(function () use ($subscription, $storage, $dumps, $description): Backup {
            $backup = Backup::query()->create([
                'subscription_id' => $subscription->id,
                'subscription_name' => (string) $subscription->name,
                'storage_name' => $storage,
                'status' => BackupStatus::Pending,

                // Der Zustand **zur Zeit der Sicherung**. Nach Form A wechseln
                // beide bei einer Wiederherstellung, und dann ist das hier die
                // einzige Stelle, an der der alte Stand noch steht — neben dem
                // Verzeichnis im Archiv.
                'system_user' => $this->numberOf($subscription),
                'db_prefix' => $this->prefixOf($subscription),
            ]);

            $this->dispatch('backup.create', $subscription, [
                'storage' => $storage,
                'panel' => (string) config('app.version'),
                'system_user' => $this->numberOf($subscription),
                'db_prefix' => $this->prefixOf($subscription),
                'dumps' => $dumps,
                'description' => $description,
            ], 'Sicherung wird erstellt', $backup);

            return $backup;
        });
    }

    /**
     * Je Datenbank einen Dump einreihen und die Liste für das Archiv bauen.
     *
     * **Die Zeile des Dumps wird über `subject_id` zurückgelesen**, und das ist
     * der erklärte Vertrag von {@see Dumps::export()}: „Die Zeile entsteht
     * **vor** dem Vorgang […]; ohne sie gäbe es nichts, worauf `subject_id`
     * zeigen könnte." Ein zweiter Weg zu derselben Zeile wäre eine zweite
     * Fassung derselben Frage.
     *
     * **Ohne Mandantenklammer**, weil eine Sicherung auch aus einem
     * nächtlichen Lauf kommen kann — dort ist niemand angemeldet, die Klammer
     * stünde auf `whereRaw('0 = 1')`, und die Sicherung enthielte wortlos keine
     * Datenbank.
     *
     * @return list<array{storage: string, engine: string, database: string}>
     */
    private function dumpEveryDatabase(Subscription $subscription): array
    {
        $databases = $this->tenancy->withoutRestriction(
            fn (): array => Database::query()
                ->where('subscription_id', $subscription->id)
                ->orderBy('name')
                ->get()
                ->all(),
        );

        $dumps = [];

        foreach ($databases as $database) {
            $operation = $this->dumps->export($database);

            $dump = $this->tenancy->withoutRestriction(
                fn (): ?DatabaseDump => DatabaseDump::query()->find($operation->subject_id),
            );

            if ($dump === null) {
                // Unerreichbar, solange `Dumps::export()` seine Zeile vor dem
                // Vorgang anlegt — und deshalb laut statt übersprungen: Ein
                // stilles `continue` gäbe eine Sicherung ohne diese Datenbank.
                throw new RuntimeException(sprintf(
                    'Zur Sicherung der Datenbank %s gibt es keine Zeile — die Sicherung bliebe ohne sie.',
                    $database->name,
                ));
            }

            $dumps[] = [
                'storage' => (string) $dump->storage_name,
                'engine' => $database->engine->value,
                'database' => (string) $database->name,
            ];
        }

        return $dumps;
    }

    /**
     * Eine Sicherung entfernen.
     *
     * **Die Zeile bleibt, bis der Agent geantwortet hat** — die zweite Grenze.
     * Sie hier zu löschen und die Datei später nicht wegzubekommen hiesse, eine
     * Datei zu hinterlassen, die in keiner Liste steht.
     */
    public function remove(Backup $backup): void
    {
        $subscription = $backup->subscription;

        if ($subscription === null) {
            // Ein zurückgebautes Abonnement hat sein ganzes Verzeichnis
            // verloren; die Zeile beschreibt eine Datei, die es nicht mehr
            // gibt. Sie geht ohne Umweg über den Agenten.
            $backup->delete();

            return;
        }

        $this->dispatch('backup.remove', $subscription, [
            'storage' => $backup->storage_name,
        ], 'Sicherung wird entfernt', $backup);
    }

    /**
     * Alle Sicherungen eines Abonnements — beim Rückbau.
     *
     * **Ohne `storage`, und das ist der Fall, für den es die Operation gibt.**
     * `subscription.remove` räumt auf, was zum Abo-Verzeichnis gehört, und
     * `/var/lib/srvpanel/backups/<abo>` gehört nicht dazu — dieselbe Lage wie
     * bei den Dumps und den Zertifikaten (`docs/35`).
     */
    public function removeAll(Subscription $subscription): void
    {
        $this->dispatch('backup.remove', $subscription, [], 'Sicherungen werden entfernt');
    }

    /**
     * Der Vorgang dazu.
     *
     * **Ohne `subject_type`, und das ist keine Auslassung.** `OperationSubject`
     * verlangt je Fall einen Ort, und `OperationOriginTest` hält, dass der eine
     * angemeldete GET-Route ist. Die Seite der Sicherungen ist `docs/117 §6`
     * Schritt 5; bis dahin wäre jeder genannte Ort erfunden.
     *
     * **Die Herkunft trägt der Vorgang trotzdem** — `Operation::booted()` setzt
     * sie seit `docs/94` an **einer** Stelle, aus der Sitzung. Sechzehn
     * anlegende Stellen wären sechzehn Gelegenheiten, sie zu vergessen.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(
        string $task,
        Subscription $subscription,
        array $payload,
        string $message,
        ?Backup $backup = null,
    ): Operation {
        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,

            /*
             * **Der Gegenstand, seit die Seite existiert.** Bis Schritt 5 stand
             * hier nichts, und der Lebenslauf suchte seine Zeile über
             * `storage_name` — ein Fall in `OperationSubject` verlangt einen
             * Ort, und den gab es noch nicht. Jetzt gibt es ihn, und die Suche
             * ist fort statt daneben: Zwei Wege von einem Vorgang zu seiner
             * Zeile wären zwei Fassungen derselben Frage.
             */
            'subject_type' => $backup === null ? null : OperationSubject::Backup->value,
            'subject_id' => $backup?->id,

            'type' => $task,
            'task' => $task,
            'payload' => array_merge([
                'subscription' => (string) $subscription->name,
            ], $payload),
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => $message,
        ]);

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }

    /**
     * Die Nummer des Systembenutzers als Zahl.
     *
     * `subscriptions.system_user` trägt den **Namen** (`p1001`); die Nummer ist
     * das, was `system_users` führt und was eine Wiederherstellung neu vergibt.
     */
    private function numberOf(Subscription $subscription): ?int
    {
        $user = (string) $subscription->system_user;

        if ($user === '') {
            return null;
        }

        $number = ltrim($user, 'p');

        return ctype_digit($number) ? (int) $number : null;
    }

    /**
     * Das Datenbankpräfix — aus `system_users` und **nicht** vom Abonnement.
     *
     * **PHPStan hat das gefunden, und es wäre still durchgegangen.** Der erste
     * Wurf las `$subscription->db_prefix`; die Spalte gibt es dort nicht
     * (`docs/38`, die Migration vom 9. August sagt es wörtlich: „`db_prefix`
     * gehört zu `system_users` und nicht zu `subscriptions`"). Eloquent hätte
     * `null` geliefert, der Agent hätte es angenommen, und im Verzeichnis
     * stünde kein Präfix — genau die Angabe, aus der die Wiederherstellung
     * nach Form A die Zuordnung alt → neu baut.
     *
     * > **Ein Wert, den ein Modell nicht hat, ist `null` und kein Fehler — und
     * > `null` sieht aus wie „gibt es nicht".**
     *
     * Anders als {@see PostgresDriver::prefixOf()} wird hier **nicht**
     * geworfen: Ein Abonnement ohne PostgreSQL hat keines, und eine Sicherung
     * seiner Dateien daran scheitern zu lassen wäre die Antwort auf eine Frage,
     * die niemand gestellt hat.
     */
    /**
     * Das Datenbankpräfix eines Abonnements — **die eine Stelle, die es liest**.
     *
     * `subscriptions` führt keine solche Spalte; der Wert steht seit P5b in
     * `system_users`, vergeben von `Lifecycle::claim()` in derselben Zeile wie
     * die Nummer. Ein `$subscription->db_prefix` wäre wortlos `null` — genau
     * das hat beim Bau von Schritt 3+4 eine PHPStan-Runde gekostet.
     *
     * **Öffentlich seit Schritt 8**, weil die Wiederherstellung dieselbe Frage
     * stellt: Sie schreibt die Zuordnung alt → neu, und das Neue ist dieser
     * Wert. Ein zweiter Leser dort wäre die Fassung, die veraltet.
     */
    public function prefixOf(Subscription $subscription): ?string
    {
        $number = $this->numberOf($subscription);

        if ($number === null) {
            return null;
        }

        $prefix = $this->tenancy->withoutRestriction(
            static fn (): mixed => SystemUser::query()->where('number', $number)->value('db_prefix'),
        );

        return is_string($prefix) && $prefix !== '' ? $prefix : null;
    }

    /**
     * `<abo>-<jjjjmmtt>-<hhmmss>-<8 hex>`, in der Form, die der Agent nimmt.
     *
     * `Store::storageName()` lässt `[a-z0-9][a-z0-9_-]{0,95}` zu. Der Name
     * eines Abonnements ist enger als das, aber gekürzt wird trotzdem hier:
     * Eine Zeichenkette, die der Agent abweist, brächte den Vorgang erst dort
     * zu Fall — also nachdem die Zeile schon steht. Der Punkt ist der Fall, um
     * den es hier geht: Ein Abonnement darf einen tragen, ein Ablagename nicht.
     *
     * **Und die acht Hexziffern sind nicht Zierat.** Der erste Wurf endete auf
     * `<hhmmss>`, und `BackupSeamTest` hat ihn beim ersten Lauf umgeworfen: Zwei
     * Sicherungen desselben Abonnements in **derselben Sekunde** bekamen
     * denselben Namen, die `unique`-Bedingung schlug zu, und der Kunde, der
     * zweimal klickt, bekam einen 500er.
     *
     * `Dumps::record()` löst das seit P5 mit genau diesen acht Ziffern und
     * schreibt den Grund daneben. Ich habe den Fehler noch einmal gemacht.
     *
     * > **Ein Fehler, den man an einer Stelle vermieden hat, ist an der
     * > nächsten wieder da, wenn die Vermeidung nicht die Regel wurde.**
     */
    private function storageName(Subscription $subscription): string
    {
        $name = strtolower((string) $subscription->name);
        $name = (string) preg_replace('/[^a-z0-9_-]+/', '-', $name);
        $name = trim($name, '-_');

        if ($name === '' || ! preg_match('/^[a-z0-9]/D', $name)) {
            // Ein Name, der mit einem Bindestrich anfinge, wäre kein gültiger —
            // und ein Abonnement ohne brauchbare Zeichen im Namen trotzdem
            // sicherbar. Die Nummer trägt dann.
            $name = 'abo'.$subscription->id;
        }

        $anhang = '-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));

        /*
         * **Der Platz wird gerechnet und nicht gegriffen.**
         *
         * Hier stand `mb_substr($name, 0, 60)`, und die 60 hat nie etwas
         * begrenzt: Ein Abonnementname ist im Formular auf 63 Zeichen
         * begrenzt, der Anhang misst 25, zusammen 88 — und
         * {@see Store::MAX_NAME} lässt 96 zu. Der Eingriff des Bruchskripts,
         * der die Kürzung entfernte, blieb deshalb grün.
         *
         * > **Eine Zahl in einer Erwartung, die man nicht gezählt hat, ist
         * > eine Vermutung mit Anspruch.**
         *
         * **Tragend ist sie trotzdem**, und zwar genau dort, wo der Prüfer des
         * Formulars nicht hinkommt: `subscriptions.name` ist ein
         * `varchar(255)`, die Regel `max:63` gilt nur für den Weg über das
         * Formular. Ein Name aus einer Migration, einem Kommando oder einem
         * künftigen Aufrufer ergäbe sonst einen Ablagenamen, den der Agent
         * abweist — und die Zeile bliebe für immer auf „wird erstellt".
         *
         * > **Eine Prüfung am Formular ist keine über die Spalte.**
         */
        return mb_substr($name, 0, Store::MAX_NAME - mb_strlen($anhang)).$anhang;
    }
}
