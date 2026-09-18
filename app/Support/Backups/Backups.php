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
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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
        private readonly Settings $settings,
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

                /*
                 * **Nur die Namen, und nur die hochgeladenen.** Wo ein
                 * Zertifikat liegt, weiss der Agent; welches Material er
                 * mitnehmen darf, weiss nur das Panel, weil nur es `source`
                 * kennt. Ein ACME-Zertifikat wird nach der Wiederherstellung
                 * neu bestellt (`docs/117 §4`).
                 *
                 * Sie kommen aus derselben Beschreibung, aus der die
                 * Wiederherstellung später die Zeilen baut — **eine Quelle und
                 * nicht zwei**: Eine zweite Abfrage hier liefe irgendwann
                 * auseinander, und dann trüge die Sicherung Dateien ohne Zeile
                 * oder Zeilen ohne Datei.
                 */
                'certificates' => array_map(
                    static fn (array $eintrag): string => (string) $eintrag['storage_name'],
                    is_array($description['certificates'] ?? null) ? $description['certificates'] : [],
                ),

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
     * Die Sicherung vor einem Rückbau (`docs/117 §6` Schritt 10).
     *
     * ## Warum ausgerechnet vor dem Löschen und nicht vor jedem Griff
     *
     * `docs/20 §9` nennt drei riskante Handlungen — Löschen, PHP-Wechsel,
     * Wiederherstellung. Gebaut ist die **erste**, und die beiden anderen
     * stehen mit ihrem Grund in `docs/117 §16` statt stillschweigend zu fehlen:
     * Ein PHP-Wechsel ist durch einen zweiten Wechsel zurückzunehmen, und eine
     * Wiederherstellung legt in Form A ein **neues** Abonnement an und
     * überschreibt nichts. Ein Rückbau ist der eine Griff dieses Panels, der
     * nichts zurücklässt.
     *
     * > **Eine Vorsichtsmassnahme vor jedem Griff ist keine Vorsicht, sondern
     * > eine Gewohnheit — und sie wird als Erstes abgeschaltet, wenn sie
     * > stört.**
     *
     * ## Sie trägt nur, weil die Zeile den Rückbau überlebt
     *
     * `backups.subscription_id` steht auf `nullOnDelete`, und der Kopf der
     * Migration sagt es wörtlich: *„Die Sicherung überlebt ihr Abonnement."*
     * Ohne das wäre diese Sicherung in derselben Sekunde fort, in der sie
     * gebraucht würde.
     *
     * ## Und kein zweiter Weg
     *
     * Es ist ein Aufruf von {@see self::create()} und keine eigene Mechanik —
     * dieselbe Kette, dieselbe Reihenfolge, dieselbe Warteschlange. Weil
     * `queue:work` einspurig ist und die Datenbank-Warteschlange FIFO liefert,
     * läuft sie fertig, **bevor** `subscription.remove` das Verzeichnis
     * abräumt.
     *
     * `null`, wenn der Betreiber sie abgeschaltet hat oder das Abonnement
     * nichts hat, was zu sichern wäre.
     */
    /**
     * Die Sicherungen, deren Abonnement es nicht mehr gibt.
     *
     * **Sie stehen sonst in keiner Liste dieses Panels.** Jede andere führt über
     * ein Abonnement — `/backups` wählt eines, `/subscriptions/{id}/backups`
     * braucht eines. Eine Sicherung, die ihren Rückbau überlebt hat, wäre damit
     * nur über eine Adresse erreichbar, deren Kennung niemand kennt.
     *
     * Und das ist ausgerechnet der Fall, für den es die Stufe gibt: Schritt 10
     * legt **vor** dem Rückbau eine an, und `backups.subscription_id` steht auf
     * `nullOnDelete`, damit sie ihn überlebt.
     *
     * > **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht
     * > sie dort?**
     *
     * **Ohne Mandantenklammer**, und das ist hier keine Bequemlichkeit: Eine
     * Zeile ohne Abonnement kann keiner Klammer genügen. Gezeigt wird sie
     * trotzdem nur dem Betreiber — das entscheidet die Aufrufstelle.
     *
     * @return Collection<int, Backup>
     */
    public function orphaned(): Collection
    {
        return $this->tenancy->withoutRestriction(static fn (): Collection => Backup::query()
            ->whereNull('subscription_id')
            ->where('status', BackupStatus::Ready->value)
            ->orderByDesc('id')
            ->get());
    }

    public function beforeRemoval(Subscription $subscription): ?Backup
    {
        if ($this->settings->backups()['before_removal'] !== true) {
            return null;
        }

        /*
         * **Ohne die Funktion des Plans, und das ist Absicht.**
         * {@see Feature::Backups} entscheidet, ob der **Kunde** sichern darf.
         * Hier sichert der Betreiber, bevor er etwas unwiederbringlich
         * entfernt — eine Frage an den Plan wäre die falsche.
         *
         * > **Eine Vorsichtsmassnahme, die der Tarif abschalten kann, schützt
         * > den Betreiber nicht vor seinem eigenen Griff.**
         *
         * Was zählt, ist, ob es überhaupt ein Verzeichnis gibt: Ein Abonnement,
         * das noch angelegt wird, hat keines, und eine Sicherung davon wäre ein
         * Vorgang, der an einem fehlenden Pfad scheitert.
         */
        if (! $this->hasDirectory($subscription)) {
            return null;
        }

        return $this->create($subscription);
    }

    /**
     * Gibt es überhaupt ein Verzeichnis, das sich sichern liesse?
     *
     * **Eine Frage an einer Stelle und nicht an zweien.** Sie stand zuerst nur
     * hier im Rückbau; der nächtliche Lauf aus Schritt 9 hat sie nicht gestellt
     * und hätte für ein Abonnement ohne Systembenutzer jede Nacht einen Vorgang
     * angelegt, der an einem fehlenden Pfad scheitert — und das Kommando
     * meldete dafür jede Nacht einen Fehlschlag.
     *
     * > **Ein Fehler, den man an einer Stelle vermieden hat, ist an der
     * > nächsten wieder da, wenn die Vermeidung nicht die Regel wurde.**
     *
     * Der Name des Systembenutzers ist die Frage und nicht sein Dasein auf der
     * Platte: Das Verzeichnis gehört dem Agenten, und das Panel kennt nur den
     * Namen, unter dem es angelegt wurde.
     */
    public function hasDirectory(Subscription $subscription): bool
    {
        return $subscription->system_user !== null && $subscription->system_user !== '';
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
        /*
         * **Ohne Mandantenklammer gefragt, und das ist eine Berichtigung vom
         * 16. September 2026.**
         *
         * Hier stand `$backup->subscription` — eine faul geladene Beziehung,
         * und die nimmt die Klammer. Aus einem Aufruf **ohne angemeldetes
         * Konto** (der nächtliche Lauf der Aufbewahrung, Schritt 9) kam
         * deshalb immer `null` zurück, und die Zeile ging den Zweig darunter:
         * gelöscht, ohne den Agenten zu fragen. Die **Datei** wäre
         * liegengeblieben, jede Nacht eine mehr.
         *
         * > **Eine Frage, die im Grundzustand alles verweigert, antwortet mit
         * > einer leeren Liste und nicht mit einem Fehler.** (`docs/78`)
         */
        $subscription = $this->tenancy->withoutRestriction(
            static fn (): ?Subscription => $backup->subscription()->first(),
        );

        if ($subscription !== null) {
            $this->dispatch('backup.remove', $subscription, [
                'storage' => $backup->storage_name,
            ], 'Sicherung wird entfernt', $backup);

            return;
        }

        /*
         * **Und ohne Abonnement geht sie trotzdem über den Agenten.**
         *
         * Hier stand, ein zurückgebautes Abonnement habe „sein ganzes
         * Verzeichnis verloren" und die Zeile beschreibe eine Datei, die es
         * nicht mehr gibt. Das ist falsch, und der Kopf der Migration sagt es:
         * `/var/lib/srvpanel/backups/<abo>` liegt ausserhalb von allem, was
         * `subscription.remove` anfasst — *„Die Sicherung überlebt ihr
         * Abonnement."* Ein `delete()` hier hinterliesse genau den Rest, vor
         * dem `docs/117 §9` Punkt 7 warnt: eine Datei, die in keiner Liste
         * steht.
         *
         * Der Name kommt aus der **Abschrift** auf der Zeile — er ist ja gerade
         * das, was man nach einem Rückbau noch hat.
         */
        $name = (string) $backup->subscription_name;

        if ($name === '') {
            // Ohne Namen gibt es keinen Pfad und damit keine Frage, die der
            // Agent beantworten könnte. Die Zeile beschreibt nichts Auffindbares.
            $backup->delete();

            return;
        }

        $this->dispatch('backup.remove', null, [
            'storage' => $backup->storage_name,
        ], 'Sicherung wird entfernt', $backup, $name);
    }

    /**
     * Das leere Verzeichnis eines zurückgebauten Abonnements abräumen.
     *
     * **Der Aufrufer ist {@see BackupLifecycle::afterSuccess()}**, und zwar der
     * eine Augenblick, in dem die Frage entschieden ist: Die letzte Zeile eines
     * Abonnements, das es nicht mehr gibt, ist gerade verschwunden.
     *
     * ## Warum das nicht gegen „melden statt löschen" verstösst
     *
     * Die Regel seit A10 schützt davor, dass ein **Nachtlauf** von sich aus
     * etwas wegnimmt: Niemand sieht hin, und ein Fehlurteil ist unumkehrbar.
     * Hier ist es das Gegenteil — jemand hat gerade auf „Entfernen" gedrückt,
     * und was bleibt, ist die Hülle dessen, was er entfernt hat.
     *
     * > **Ein Rückweg, der die Hälfte zurücknimmt, ist keiner.**
     * > {@see Store::prepare()} legt Datei **und**
     * > Verzeichnis an; wer das eine entfernt, entfernt auch das andere.
     *
     * **Und der Griff kann nichts zerstören**: `Store::removeDirectory()` ruft
     * `rmdir(2)` und scheitert an einem Verzeichnis, in dem noch etwas liegt.
     * Eine Datei ohne Zeile bleibt damit liegen und wird weiter als `orphan`
     * gemeldet — das ist der Befund, für den es die Diagnose gibt.
     *
     * **Kein `$backup`**: Der Vorgang trägt keinen Gegenstand, denn die Zeile
     * ist fort. Ohne ihn geht {@see BackupLifecycle::afterSuccess()} bei der
     * Antwort früh zurück, und das ist richtig — es gibt nichts mehr zu ändern.
     *
     * **Aber einen Handelnden trägt er.** Hier läuft kein Request — der
     * Aufrufer sitzt im Arbeiter —, und ohne `$accountId` stünde der Vorgang
     * als Automatik da. Sein Anlass ist aber ein Klick, und dessen Kennung
     * steht auf dem auslösenden Vorgang.
     */
    public function removeDirectory(string $subscriptionName, ?int $accountId = null): Operation
    {
        return $this->dispatch(
            'backup.remove',
            null,
            [],
            'Leeres Verzeichnis der Sicherungen wird entfernt',
            null,
            $subscriptionName,
            $accountId,
        );
    }

    /*
     * **Hier stand `removeAll()`, und es hatte nie einen Aufrufer.**
     *
     * Gedacht war es für den Rückbau — und genau dort darf es nicht laufen:
     * `backups.subscription_id` steht auf `nullOnDelete`, und der Kopf der
     * Migration sagt warum. *„Die Sicherung überlebt ihr Abonnement."* Seit
     * Schritt 10 hängt daran ein Merkmal: Die Sicherung **vor** dem Rückbau
     * wäre sonst die erste, die der Rückbau mitnimmt.
     *
     * > **Eine Methode, die niemand ruft, ist von aussen nicht von einer zu
     * > unterscheiden, die es nicht gibt — und eine, deren einziger denkbarer
     * > Ort ihr widerspricht, ist schlimmer als keine.**
     *
     * **Der Weg zurück bleibt trotzdem da, im Agenten** — und seit dem
     * 17. September 2026 hat er einen Aufrufer: {@see self::removeDirectory()},
     * gerufen aus dem Lebenslauf, wenn die letzte Zeile eines zurückgebauten
     * Abonnements verschwindet. Nicht beim Rückbau, wo er das Merkmal aus
     * Schritt 10 zerstörte.
     *
     * **Und er räumt nur ein leeres Verzeichnis ab** (`rmdir`, nicht
     * `removeTree`). Was darin liegenbleibt, **meldet** die Bestandsdiagnose,
     * statt es zu löschen — dieselbe Regel wie bei jedem anderen Rest seit A10.
     *
     * `BackupTeardownTest` hält beides.
     */

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
        ?Subscription $subscription,
        array $payload,
        string $message,
        ?Backup $backup = null,
        ?string $name = null,
        ?int $accountId = null,
    ): Operation {
        /*
         * **Ohne Abonnement muss der Name da sein — und zwar laut.**
         *
         * Ein Rückfall auf die leere Zeichenkette wäre der Fehler aus
         * `docs/108`: Der Agent bekäme einen Pfad, der auf das Wurzelverzeichnis
         * der Sicherungen zeigt, und die Meldung spräche von einem Abonnement
         * ohne Namen.
         *
         * > **Ein Rückfall, der immer etwas liefert, macht aus „unbekannt" eine
         * > falsche Auskunft.**
         */
        $abonnement = $subscription !== null ? (string) $subscription->name : (string) $name;

        if ($abonnement === '') {
            throw new InvalidArgumentException('Ein Vorgang der Sicherungen braucht den Namen seines Abonnements.');
        }

        $operation = Operation::query()->create([
            /*
             * **`null` ist zulässig, und es ist der Fall, für den `docs/35` die
             * Spalte nullable gemacht hat.** Eine Sicherung überlebt ihr
             * Abonnement; ihr Entfernen ist danach ein Vorgang ohne Abonnement
             * und keiner ohne Gegenstand.
             */
            'subscription_id' => $subscription?->id,

            /*
             * **Wer gehandelt hat — und `null` heisst hier schon etwas.**
             *
             * Bis zum 18. September 2026 stand diese Zeile nicht da, und der
             * Befund war nicht die leere Spalte, sondern ihre Bedeutung: Seit
             * `docs/901` heisst `account_id = NULL` **Kommandozeile oder
             * Automatik**. Der nächtliche Sicherungslauf ist genau dieser Fall
             * — und eine Sicherung, die jemand gedrückt hat, war von ihm nicht
             * mehr zu unterscheiden. Gemessen auf `cloudsrv24`: „Ausgelöst von
             * System" für einen Klick, während `backup.restore` in derselben
             * Stunde den Administrator nannte (`docs/121 §9`, Befund 3).
             *
             * > **Eine Null, die schon eine Bedeutung trägt, kann keine zweite
             * > bekommen — die beiden Fälle sehen danach gleich aus.**
             *
             * **Zwei Leser hängen daran**, und der zweite ist der teurere: die
             * Vorgangsseite über `ActorLabel`, und `RunAgentOperation::actor()`,
             * das bei `null` gar nichts an das Protokoll des **Agenten**
             * weitergibt. Dort stand für jede Sicherung niemand.
             *
             * `$accountId` ist die Antwort für den Fall, in dem es keinen
             * Request gibt und trotzdem jemand gehandelt hat: Das Abräumen des
             * Verzeichnisses läuft im Arbeiter, und sein Anlass ist der Klick,
             * der die letzte Zeile entfernt hat. Dasselbe Muster wie
             * `CertificateLifecycle`, das die Kennung seines Anlasses
             * weiterreicht.
             */
            'account_id' => $accountId ?? request()->user()?->getAuthIdentifier(),

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
            /*
             * **Der Name kommt aus dem Abonnement, solange es eines gibt, und
             * sonst aus der Abschrift auf der Zeile.** Beides ist derselbe
             * Name; die Abschrift ist das, was nach einem Rückbau davon bleibt
             * (`subscription_name`, seit `docs/35`). Der Agent bekommt hier
             * ohnehin nur eine Zeichenkette und keine Zeile.
             */
            'payload' => array_merge([
                'subscription' => $abonnement,
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
