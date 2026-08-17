<?php

declare(strict_types=1);

namespace App\Support\Cron;

use App\Enums\CronRunStatus;
use App\Models\CronJob;
use App\Models\CronRun;
use App\Models\Subscription;
use App\Support\Files\Sftp;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Cron\Schedule;

/**
 * Die Cronjobs eines Abonnements — der Sollzustand, jedes Mal ganz.
 *
 * ## Warum das hier **ohne** unbeschränkten Blick geht
 *
 * {@see Sftp::accesses()} muss die Mandantenklammer öffnen, weil `sshd_config`
 * eine Datei des **Servers** ist: Wer sie je Mandant schriebe, schriebe beim
 * zweiten Kunden den Block des ersten weg. Für Cron gilt das nicht — jedes
 * Abonnement hat seine eigene Datei unter `/etc/cron.d/srvpanel-<benutzer>`, und
 * ihr Inhalt kommt ausschliesslich aus den Jobs dieses einen Abonnements.
 *
 * Also bleibt die Klammer zu. `withoutRestriction()` will begründet sein, und
 * hier gäbe es keine Begründung — es wäre eine Ausnahme aus Gewohnheit, und die
 * ist die teuerste Art, eine Regel zu verlieren.
 *
 * > **Eine Ausnahme, die man macht, weil die Nachbarstelle sie macht, ist keine
 * > Ausnahme mehr, sondern die neue Regel.**
 *
 * ## Sollzustand statt Fortschreibung
 *
 * Jede Änderung schreibt die ganze Datei neu, aus dem vollständigen Bestand —
 * dieselbe Bauart wie {@see Sftp} und aus demselben Grund:
 *
 * > **Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt die
 * > anderen Vorgänge derselben Reihe nicht.**
 *
 * Deshalb läuft der Aufruf des Agenten **unmittelbar** und nicht über die
 * Warteschlange, und deshalb steht er in derselben Transaktion wie die Zeile.
 *
 * ## Die Reihenfolge, und was sie kostet
 *
 * Erst die Zeile in der Datenbank, dann der Agent — und beides in einer
 * Transaktion. Scheitert der Agent, rollt die Zeile zurück, und auf der Platte
 * steht schlimmstenfalls eine Befehlsdatei ohne Cron-Zeile: ein Rest, der nichts
 * tut. Umgekehrt bliebe eine Zeile in `/etc/cron.d` stehen, die jede Minute
 * einen Befehl startet, den im Panel niemand mehr sieht.
 *
 * > **Von zwei unvollständigen Zuständen ist der stumme der bessere.**
 *
 * Was eine Transaktion **nicht** kann, steht in `docs/59` Befund 12 und gilt
 * hier genauso: Sie rollt die Datenbank zurück und nicht die Platte. Der nächste
 * erfolgreiche Aufruf räumt den Rest weg, weil er den ganzen Sollzustand
 * herstellt — auch das ein Vorzug dieser Bauart gegenüber einer Fortschreibung.
 */
final class Cron
{
    public function __construct(
        private readonly Client $agent,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * Einen Job anlegen und den Zeitplan des Abonnements nachziehen.
     *
     * @param  array{label: string, command: string, minute: string, hour: string, day_of_month: string, month: string, day_of_week: string, active?: bool}  $attributes
     *
     * @throws AgentException wenn der Zeitplan nicht taugt oder das Schreiben scheitert
     */
    public function create(Subscription $subscription, array $attributes): CronJob
    {
        /*
         * **Vor der Transaktion geprüft, und mit der Regel des Agenten.**
         * {@see Schedule::parse()} ist dieselbe Stelle, die die Zeile später
         * schreibt; eine eigene Formulierung im Formular wäre die zweite
         * Fassung, und die zweite ist die, die veraltet. Dieselbe Entscheidung
         * wie bei `PublicKey::parse()` in {@see Sftp::add()} und bei
         * `Hba::cidr()` in P5b.
         *
         * Ein untauglicher Zeitplan soll gar nicht erst eine Transaktion
         * aufmachen, und die Meldung ist in beiden Fällen dieselbe.
         */
        Schedule::parse($attributes);

        /** @var CronJob $job */
        $job = DB::transaction(function () use ($subscription, $attributes): CronJob {
            $job = new CronJob;
            $job->fill($attributes);
            $job->subscription_id = (int) $subscription->id;
            $job->active = (bool) ($attributes['active'] ?? true);
            $job->next_due = Occurrence::next($job->schedule());
            $job->save();

            $this->apply($subscription);

            return $job;
        });

        return $job;
    }

    /**
     * Einen Job ändern und den Zeitplan nachziehen.
     *
     * @param  array<string,mixed>  $attributes
     *
     * @throws AgentException
     */
    public function update(CronJob $job, array $attributes): CronJob
    {
        Schedule::parse(array_merge($job->schedule(), array_intersect_key(
            $attributes,
            array_flip(Schedule::FIELDS),
        )));

        /** @var CronJob $updated */
        $updated = DB::transaction(function () use ($job, $attributes): CronJob {
            $job->fill($attributes);
            $job->next_due = Occurrence::next($job->schedule());
            $job->save();

            $this->apply($job->subscription ?? Subscription::query()->findOrFail($job->subscription_id));

            return $job;
        });

        return $updated;
    }

    /**
     * Einen Job wegnehmen.
     *
     * Die Läufe gehen über `cascadeOnDelete` mit — sie sind die Geschichte
     * **dieses** Jobs und ohne ihn keine Auskunft mehr, sondern ein Rest, der
     * auf nichts zeigt.
     *
     * @throws AgentException
     */
    public function remove(CronJob $job): void
    {
        $subscription = $job->subscription ?? Subscription::query()->findOrFail($job->subscription_id);

        DB::transaction(function () use ($job, $subscription): void {
            $job->delete();

            $this->apply($subscription);
        });
    }

    /**
     * Den Zeitplan eines Abonnements auf den Stand des Bestands bringen.
     *
     * Das ist zugleich der Weg, den der Lebenslauf nimmt: Wird ein Abonnement
     * gesperrt oder fortgesetzt, ändert sich kein einziger Job — nur das
     * Ergebnis von {@see self::desired()}.
     *
     * @return array<string,mixed> die Antwort des Agenten, mit `effective_within_seconds`
     *
     * @throws AgentException
     */
    public function apply(Subscription $subscription): array
    {
        return $this->agent->call('cron.apply', [
            'user' => (string) $subscription->system_user,
            'jobs' => $this->desired($subscription),
        ]);
    }

    /**
     * Jeder Job, den es geben soll — und ob er laufen darf.
     *
     * ## Hier sitzt Entscheidung 3 des Betreibers, und zwar an genau einer Stelle
     *
     * Ein gesperrtes oder ruhendes Abonnement **pausiert** seine Jobs
     * (`docs/60 §12`). Das geschieht hier und nicht dadurch, dass irgendwo
     * `active` umgeschrieben wird: Die Spalte gehört dem Kunden und sagt, was
     * *er* pausiert hat. Würde die Sperre sie überschreiben, wüsste beim
     * Fortsetzen niemand mehr, welche Jobs vorher schon aus waren.
     *
     * > **Ein Zustand, der einen anderen überschreibt, um ihn auszudrücken,
     * > verliert den ersten.**
     *
     * Der Agent bekommt die pausierten Jobs trotzdem mitgeschickt — mit
     * `active: false`. Er legt ihre Befehlsdatei an und lässt sie aus der
     * Cron-Datei heraus; damit ist das Fortsetzen ein Aufruf und kein Wiederaufbau.
     *
     * @return list<array{id: int, schedule: array<string,string>, command: string, active: bool}>
     */
    public function desired(Subscription $subscription): array
    {
        $usable = $subscription->usable();

        return CronJob::query()
            ->where('subscription_id', (int) $subscription->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (CronJob $job): array => [
                'id' => (int) $job->id,
                'schedule' => $job->schedule(),
                'command' => (string) $job->command,
                'active' => $job->active && $usable,
            ])
            ->values()
            ->all();
    }

    /**
     * Die aufgezeichneten Läufe einsammeln und einpflegen.
     *
     * ## Die Prüfung, die der Agent nicht treffen kann
     *
     * Die Ablage unter `/var/spool/srvpanel/cron/<benutzer>` gehört dem
     * Abonnement — anders könnte `cron-run` als der Kunde nicht darin schreiben.
     * **Damit kann der Kunde darin auch alles andere schreiben**, insbesondere
     * eine Aufzeichnung mit einer *fremden* Jobnummer.
     *
     * > **Ein Verzeichnis, in das der Geprüfte schreiben darf, liefert keine
     * > Auskunft — es liefert eine Behauptung.**
     *
     * Der Agent kennt den Bestand des Panels nicht und kann die Nummer nicht
     * zuordnen; er prüft nur die Form. Hier wird sie zugeordnet: Ein Lauf wird
     * nur eingepflegt, wenn sein Job zu dem Abonnement gehört, unter dessen
     * Namen er ankam. Alles andere wird verworfen und nicht etwa einem fremden
     * Job zugeschrieben.
     *
     * ## Beschnitten wird beim Einpflegen
     *
     * `docs/51 §6`: Ein Job, der jede Minute läuft, soll die Tabelle nicht bis
     * zum nächsten Aufräumen füllen dürfen. Deshalb steht der Schnitt hier und
     * nicht in einem Tageslauf — und er läuft je Job, dessen Läufe sich gerade
     * geändert haben, statt über den ganzen Bestand.
     *
     * @param  list<Subscription>|null  $subscriptions  null heisst „alle mit Jobs"
     * @return array{taken: int, stored: int, remaining: int}
     *
     * @throws AgentException
     */
    public function ingest(?array $subscriptions = null): array
    {
        $subscriptions ??= $this->withJobs();

        if ($subscriptions === []) {
            return ['taken' => 0, 'stored' => 0, 'remaining' => 0];
        }

        /** @var array<string,Subscription> $byUser */
        $byUser = [];

        foreach ($subscriptions as $subscription) {
            $user = (string) $subscription->system_user;

            if ($user !== '') {
                $byUser[$user] = $subscription;
            }
        }

        if ($byUser === []) {
            return ['taken' => 0, 'stored' => 0, 'remaining' => 0];
        }

        $answer = $this->agent->call('cron.runs', ['users' => array_keys($byUser)]);

        $runs = $answer['runs'] ?? [];
        $stored = 0;

        if (is_array($runs) && $runs !== []) {
            $stored = $this->store($runs, $byUser);
        }

        return [
            'taken' => is_int($answer['taken'] ?? null) ? $answer['taken'] : 0,
            'stored' => $stored,
            'remaining' => is_int($answer['remaining'] ?? null) ? $answer['remaining'] : 0,
        ];
    }

    /**
     * Die gemeldeten Läufe einpflegen — jeder gegen seinen Job geprüft.
     *
     * @param  array<int,mixed>  $runs
     * @param  array<string,Subscription>  $byUser
     */
    private function store(array $runs, array $byUser): int
    {
        /*
         * Die Jobs einmal holen statt je Lauf: Ein Abonnement kann fünfhundert
         * Läufe auf einmal mitbringen, und fünfhundert Einzelabfragen für zehn
         * Jobs wären eine Abfrage je Zeile.
         */
        $jobs = CronJob::query()
            ->whereIn('subscription_id', array_map(
                static fn (Subscription $s): int => (int) $s->id,
                array_values($byUser),
            ))
            ->get()
            ->keyBy(static fn (CronJob $job): int => (int) $job->id);

        $stored = 0;
        $touched = [];

        DB::transaction(function () use ($runs, $byUser, $jobs, &$stored, &$touched): void {
            foreach ($runs as $run) {
                if (! is_array($run)) {
                    continue;
                }

                $user = is_string($run['user'] ?? null) ? $run['user'] : '';
                $subscription = $byUser[$user] ?? null;
                $job = $jobs->get((int) ($run['job'] ?? 0));

                // Der Kern der Prüfung: Die Nummer muss zu **diesem**
                // Abonnement gehören. Eine fremde wird verworfen, nicht
                // umgehängt.
                if (! $subscription instanceof Subscription
                    || ! $job instanceof CronJob
                    || (int) $job->subscription_id !== (int) $subscription->id) {
                    continue;
                }

                $status = CronRunStatus::tryFromStored($run['status'] ?? null);

                if (! $status instanceof CronRunStatus) {
                    continue;
                }

                $output = is_string($run['output'] ?? null) ? $run['output'] : '';
                $truncated = ($run['truncated'] ?? false) === true;

                /*
                 * **Die zweite Wand gegen zu lange Ausgabe.** `cron-run` kappt,
                 * der Agent kappt — und hier wird noch einmal gekappt, weil die
                 * Spalte auf dem Server eine Grenze hat und diese Tests gegen
                 * SQLite laufen, wo sie keine hat (`docs/48`).
                 */
                if (strlen($output) > CronRun::OUTPUT_MAX) {
                    $output = substr($output, 0, CronRun::OUTPUT_MAX);
                    $truncated = true;
                }

                CronRun::query()->create([
                    'cron_job_id' => (int) $job->id,
                    'subscription_id' => (int) $subscription->id,
                    'started_at' => $run['started'] ?? null,
                    'duration_ms' => max(0, (int) ($run['duration_ms'] ?? 0)),
                    'exit_code' => is_int($run['exit'] ?? null) ? $run['exit'] : null,
                    'status' => $status,
                    'output' => $output === '' ? null : $output,
                    'truncated' => $truncated,
                ]);

                $touched[(int) $job->id] = true;
                $stored++;
            }

            foreach (array_keys($touched) as $jobId) {
                $this->trim($jobId);
            }
        });

        return $stored;
    }

    /**
     * Die Läufe eines Jobs auf {@see CronRun::KEEP_PER_JOB} beschneiden.
     *
     * Gelöscht wird über die Nummern der zu **behaltenden** Zeilen und nicht
     * über einen Zeitpunkt: Zwei Läufe können auf dieselbe Sekunde fallen — ein
     * Minutenjob und ein übersprungener Lauf desselben Jobs tun das regelmässig —,
     * und ein Schnitt nach `started_at` nähme dann entweder beide mit oder
     * keinen.
     */
    private function trim(int $jobId): void
    {
        $keep = CronRun::query()
            ->where('cron_job_id', $jobId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(CronRun::KEEP_PER_JOB)
            ->pluck('id')
            ->all();

        if (count($keep) < CronRun::KEEP_PER_JOB) {
            return;
        }

        CronRun::query()
            ->where('cron_job_id', $jobId)
            ->whereNotIn('id', $keep)
            ->delete();
    }

    /**
     * Die Abonnements, für die es überhaupt Jobs gibt.
     *
     * **Hier wird die Mandantenklammer geöffnet, und diesmal mit Grund.** Der
     * Einsammler läuft aus einem Zeitgeber ohne angemeldetes Konto; ohne
     * Ausnahme sähe er nach der dritten Grenze *nichts* und pflegte nie etwas
     * ein. Das ist dieselbe Begründung wie bei {@see Sftp::accesses()} — nur
     * dass sie dort für das Schreiben gilt und hier für einen Lauf ohne
     * Betrachter.
     *
     * @return list<Subscription>
     */
    private function withJobs(): array
    {
        /** @var list<Subscription> $subscriptions */
        $subscriptions = [];

        $this->tenancy->withoutRestriction(function () use (&$subscriptions): void {
            $ids = CronJob::query()->distinct()->pluck('subscription_id')->all();

            if ($ids === []) {
                return;
            }

            $subscriptions = Subscription::query()
                ->whereIn('id', $ids)
                ->whereNotNull('system_user')
                ->orderBy('id')
                ->get()
                ->all();
        });

        return $subscriptions;
    }

    /**
     * Was von der Antwort des Agenten der Kunde lesen soll.
     *
     * **Gemessen (`docs/60 §4`): Zwischen dem Speichern und dem ersten
     * möglichen Lauf liegen bis zu 60 Sekunden.** cron liest `/etc/cron.d` ohne
     * inotify neu, einmal je Minute. Diese Zahl entsteht im Agenten, und
     * `docs/59` hat zweimal denselben Fehler an zwei Übergängen desselben Weges
     * vorgeführt:
     *
     * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
     * > keine.**
     *
     * Deshalb steht sie hier als reine Funktion — damit ein Wächter sie ohne
     * Agenten liest, dieselbe Bauart wie {@see Sftp::spokenNote()}.
     *
     * @param  array<string,mixed>  $answer
     */
    public static function spokenNote(array $answer): ?string
    {
        $seconds = $answer['effective_within_seconds'] ?? null;

        if (! is_int($seconds) || $seconds <= 0) {
            return null;
        }

        return 'Der Zeitplan ist gespeichert. Bis er gilt, kann es eine Minute dauern.';
    }
}
