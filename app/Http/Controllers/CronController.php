<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CronJob;
use App\Models\CronRun;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Cron\Cron;
use App\Support\Cron\Occurrence;
use App\Support\Cron\ServerZone;
use App\Support\Cron\Spoken;
use App\Support\Plans\Quota;
use App\Support\Time\Clock;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Cron\Schedule;

/**
 * Die Zeitsteuerung eines Abonnements.
 *
 * **Hier steht keine Zeitplanprüfung.** Sie steht in {@see Schedule::parse()},
 * also an der Stelle, die die Zeile später auch schreibt; eine zweite hier sähe
 * aus wie die Schranke, wäre keine, und liefe beim nächsten Umbau auseinander.
 * Dieselbe Aufteilung wie bei {@see SftpController} und dem Schlüssel.
 * Validiert wird, was eine Validierung ist: dass etwas geschickt wurde, wie lang
 * es sein darf, und ob das Kontingent noch etwas hergibt.
 *
 * **Die Fehlerwege sind die aus `docs/59`.** Was der Agent als `BAD_REQUEST`
 * abweist, ist eine Sache des Feldes und bekommt eine Feldmeldung. Alles andere
 * — `exec_failed`, `timeout`, `internal` — ist ein Zustand des **Servers** und
 * gehört an die Zusammenfassung unter `server`:
 *
 * > **Ein roter Rand am Feld behauptet, das Feld sei falsch. Wer ihn für einen
 * > Zustand des Servers setzt, schickt den Leser dorthin, wo nichts zu ändern
 * > ist.**
 *
 * **Und die Auskunft des Agenten wird weitergegeben.** `cron.apply` sagt, dass
 * bis zu 60 Sekunden vergehen, bevor cron die Datei liest (`docs/60 §4`) —
 * gemessen, nicht geschätzt. `docs/59` hat zweimal denselben Fehler an zwei
 * Übergängen desselben Weges vorgeführt:
 *
 * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
 * > keine.**
 */
final class CronController extends Controller
{
    /** Wie lang eine Beschriftung werden darf. */
    private const LABEL_MAX = 120;

    /**
     * Wie viele Fälligkeiten die Vorschau nennt.
     *
     * **Drei, weil zwei den Abstand nicht zeigen.** „Am 21. um 03:15, am 22. um
     * 03:15" liest sich wie täglich und wie alle 24 Stunden gleichermassen; die
     * dritte Zeile entscheidet die Frage. Mehr ist Fliesstext: Bei einem Plan,
     * der alle fünf Minuten läuft, stünden zehn Zeilen da, die alle dasselbe
     * sagen.
     */
    private const PREVIEW_RUNS = 3;

    /**
     * Namen, die auf dieser Seite anders heissen als in der allgemeinen Liste.
     *
     * **`docs/66`, Befund 3.** `label` heisst auf zwei anderen Seiten
     * „Bezeichnung" und hier „Beschriftung"; die Liste in
     * `lang/de/validation.php` trägt den häufigeren Fall. Stünde er auch hier,
     * läse der Kunde „Das Feld Bezeichnung ist erforderlich" und suchte auf
     * dieser Seite ein Feld, das es nicht gibt.
     *
     * > **Ein Wächter über die Vollständigkeit sagt nichts über die
     * > Richtigkeit.**
     *
     * **Einmal und für beide Wege.** Die Regeln stehen schon aus diesem Grund
     * nur einmal da; zwei Namenslisten liefen genauso auseinander.
     *
     * @var array<string,string>
     */
    private const NAMEN = ['label' => 'Beschriftung'];

    public function __construct(
        private readonly Cron $cron,
        private readonly Audit $audit,
    ) {}

    /**
     * Der Weg hinein, ohne dass der Kunde eine Abo-Kennung kennen muss.
     *
     * **Die Bauart ist wörtlich die von {@see SftpController::pick()}**, und das
     * ist Absicht: Es ist dieselbe Frage. Ein Merkmal, das an *einem* Abonnement
     * hängt, braucht einen Menüpunkt ohne Kennung darin — sonst liegt es drei
     * Klicks tief, und das war `docs/55` Befund 8 und `docs/59` Befund 19.
     *
     * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
     * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
     *
     * Dies ist das dritte Merkmal mit dieser Frage, und deshalb ist es hier
     * keine Entdeckung mehr, sondern eine Abschrift.
     */
    public function pick(Request $request): RedirectResponse|Response
    {
        $account = $request->user();

        $erreichbar = Subscription::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (Subscription $s): bool => $account?->can('manageCron', $s) ?? false)
            ->values();

        if ($erreichbar->count() === 1) {
            return to_route('cron.show', ['subscription' => $erreichbar->first()?->id]);
        }

        return Inertia::render('Subscriptions/CronPick', [
            'subscriptions' => $erreichbar
                ->map(static fn (Subscription $s): array => ['id' => $s->id, 'name' => $s->name])
                ->all(),
        ]);
    }

    public function show(Subscription $subscription): Response
    {
        $jobs = CronJob::query()
            ->where('subscription_id', (int) $subscription->id)
            ->orderBy('label')
            ->get();

        $limit = $subscription->quota(Quota::CronJobs->value);

        return Inertia::render('Subscriptions/Cron', [
            'subscription' => [
                'id' => (int) $subscription->id,
                'name' => $subscription->name,
                'system_user' => $subscription->system_user,
                'usable' => $subscription->usable(),
            ],
            'jobs' => $jobs->map(fn (CronJob $job): array => $this->job($job))->all(),
            'quota' => ['used' => $jobs->count(), 'limit' => $limit],

            /*
             * **Die Zone der Maschine steht auf der Seite**, und zwar als Wert
             * und nicht als Satz im Template. cron rechnet in ihr, das Panel
             * zeigt sonst alles in der Anzeigezone (`docs/40`) — gemessen
             * (`docs/60 §11`), und `CRON_TZ` gibt es nicht, mit dem man das
             * angleichen könnte.
             *
             * > **Zwei Zeiten auf einer Seite, von denen nur eine beschriftet
             * > ist, sind eine Falle mit Erklärung daneben.**
             */
            'server_zone' => ServerZone::name(),
            'display_zone' => Clock::zone(),

            'can' => ['manage' => true],
        ]);
    }

    /**
     * Die Umrechnung während des Tippens — Wunsch 4 des Betreibers (`docs/66 §4`).
     *
     * ## Warum der Server das rechnet und nicht der Browser
     *
     * > Die reine Cron-Schreibweise kann für unerfahrene Nutzer mehr Hindernis
     * > als Hilfsmittel sein.
     *
     * Den Satz dazu baut {@see Spoken::schedule()}, die Fälligkeiten
     * {@see Occurrence::next()}. Beides in TypeScript nachzubauen hiesse,
     * dieselbe Regel in zwei Sprachen zu pflegen — und die zweite ist die, die
     * von der ersten abweicht.
     *
     * > **Eine Zusammenfügung darf doppelt stehen, eine Regel nicht.**
     *
     * `CronScheduleFormTest::test_the_page_does_not_translate_on_its_own` hält
     * die Seite darauf fest; diese Route ist der Weg, den sie stattdessen
     * nimmt.
     *
     * ## Und warum hier nichts geprüft wird
     *
     * {@see Schedule::parse()} ist die Schranke, dieselbe wie beim Speichern.
     * Taugt eine Eingabe nicht, ist die Antwort schlicht „noch kein gültiger
     * Zeitplan" und **keine Fehlermeldung**: Wer beim dritten Zeichen einer
     * Spanne rot wird, wird bei jeder Spanne rot. Der Satz zum Fehler steht
     * beim Absenden, an der Stelle, an der er hingehört (`docs/19 §6`).
     *
     * **Sie ändert nichts.** Kein Agent, kein Vorgang, keine Zeile im
     * Protokoll — sie rechnet. Deshalb steht sie auch nicht im Protokoll: Ein
     * Eintrag je Tastendruck wäre eine Datenhaltung über die Bedienung.
     *
     * **Dass sie trotzdem ein `POST` ist**, hat denselben Grund wie bei den
     * Griffen der Konsole: Der Zeitplan ist eine Eingabe des Kunden, und eine
     * Eingabe des Kunden gehört nicht in eine Adresse.
     *
     * **Die Zeitpunkte gehen durch {@see Clock}.** Der Zeitplan gilt in
     * Serverzeit, die Anzeige in der Zone des Lesers — genau der Unterschied,
     * den der Kasten oben auf der Seite erklärt. Wer ihn hier vergisst, zeigt
     * zwei Wahrheiten auf derselben Seite.
     */
    public function preview(Request $request, Subscription $subscription): JsonResponse
    {
        $felder = [];

        foreach (Schedule::FIELDS as $feld) {
            $wert = $request->input($feld);
            $felder[$feld] = is_string($wert) ? $wert : '';
        }

        try {
            $schedule = Schedule::parse($felder);
        } catch (AgentException) {
            return response()->json(['spoken' => null, 'next' => []]);
        }

        /*
         * **Nacheinander und nicht in einem Rutsch.** `Occurrence::next()`
         * beantwortet „was kommt nach diesem Zeitpunkt"; die Kette entsteht,
         * indem man die Antwort wieder hineingibt. Bricht sie ab — `0 0 30 2 *`
         * gibt es —, ist die Liste kürzer und nicht falsch.
         */
        $naechste = [];
        $nach = null;

        for ($i = 0; $i < self::PREVIEW_RUNS; $i++) {
            $zeit = Occurrence::next($schedule, $nach);

            if ($zeit === null) {
                break;
            }

            $naechste[] = Clock::display(Carbon::instance($zeit));
            $nach = $zeit;
        }

        return response()->json([
            'spoken' => Spoken::schedule($schedule),
            'next' => $naechste,
        ]);
    }

    /**
     * Die Läufe eines Jobs — eine eigene Seite, weil die Ausgabe lang ist.
     *
     * **Und sie sammelt nicht selbst ein.** Das tut `srvpanel:cron-runs` über
     * seinen Zeitgeber. Eine Seite, die beim Aufruf einsammelte, zeigte nur dann
     * etwas, wenn jemand hinsieht — und die Ablage liefe voll, solange niemand
     * hinsieht.
     */
    public function runs(Subscription $subscription, CronJob $job): Response
    {
        $this->belongsTo($job, $subscription);

        $runs = CronRun::query()
            ->where('cron_job_id', (int) $job->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(CronRun::KEEP_PER_JOB)
            ->get();

        return Inertia::render('Subscriptions/CronRuns', [
            'subscription' => ['id' => (int) $subscription->id, 'name' => $subscription->name],
            'job' => $this->job($job),
            'runs' => $runs->map(static fn (CronRun $run): array => [
                'id' => (int) $run->id,
                'started_at' => Clock::display($run->started_at),
                'duration_ms' => (int) $run->duration_ms,
                'exit_code' => $run->exit_code,
                'status' => $run->status->value,
                'status_label' => $run->status->label(),
                'tone' => $run->status->tone(),
                'output' => $run->output,
                'truncated' => (bool) $run->truncated,
            ])->all(),
            'keep' => CronRun::KEEP_PER_JOB,
        ]);
    }

    public function store(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $this->validated($request);

        $this->withinQuota($subscription);

        try {
            $job = $this->cron->create($subscription, $data);
        } catch (AgentException $error) {
            throw $this->asValidation($error);
        }

        $this->audit->record('cron.job.add', target: $job, subscriptionId: (int) $subscription->id, context: [
            'job' => $job->label,
            'schedule' => Schedule::line($job->schedule()),
        ]);

        return to_route('cron.show', ['subscription' => $subscription->id])
            ->with('success', $this->saved());
    }

    public function update(Request $request, Subscription $subscription, CronJob $job): RedirectResponse
    {
        $this->belongsTo($job, $subscription);

        $data = $this->validated($request);

        try {
            $this->cron->update($job, $data);
        } catch (AgentException $error) {
            throw $this->asValidation($error);
        }

        $this->audit->record('cron.job.change', target: $job, subscriptionId: (int) $subscription->id, context: [
            'job' => $job->label,
            'schedule' => Schedule::line($job->schedule()),
        ]);

        return to_route('cron.show', ['subscription' => $subscription->id])
            ->with('success', $this->saved());
    }

    public function destroy(Subscription $subscription, CronJob $job): RedirectResponse
    {
        $this->belongsTo($job, $subscription);

        $label = (string) $job->label;

        try {
            $this->cron->remove($job);
        } catch (AgentException $error) {
            throw $this->asValidation($error);
        }

        /*
         * **Das Ziel steht auch dann drin, wenn es die Zeile nicht mehr gibt**
         * (`docs/66`, Befund 7). `$job` ist nach dem Entfernen noch im
         * Speicher, und seine Kennung ist genau das, wonach jemand später
         * sucht: „welcher Job war das".
         */
        $this->audit->record('cron.job.remove', target: $job, subscriptionId: (int) $subscription->id, context: [
            'job' => $label,
        ]);

        return to_route('cron.show', ['subscription' => $subscription->id])
            ->with('success', 'Der Cronjob ist entfernt.');
    }

    /**
     * Ein Job, wie ihn die Seite braucht.
     *
     * `next_due` geht über {@see Clock} wie jeder Zeitstempel; der **Zeitplan**
     * geht nicht darüber, denn er ist Serverzeit. Die beiden dürfen nicht durch
     * dieselbe Umrechnung — wer das verwechselt, zeigt eine Zeile und findet sie
     * nicht.
     *
     * @return array<string,mixed>
     */
    private function job(CronJob $job): array
    {
        $schedule = $job->schedule();

        return [
            'id' => (int) $job->id,
            'label' => $job->label,
            'command' => $job->command,
            'active' => (bool) $job->active,
            'schedule' => $schedule,
            'expression' => Schedule::line($schedule),
            'spoken' => Spoken::schedule($schedule),
            'next_due' => $job->next_due === null ? null : Clock::display($job->next_due),
        ];
    }

    /**
     * Was validiert wird — und was ausdrücklich nicht.
     *
     * Die fünf Felder bekommen hier nur eine Längengrenze und die Auskunft, dass
     * sie da sein müssen. Ihre **Form** prüft {@see Schedule::parse()} im
     * Agenten; käme sie hier noch einmal, gäbe es zwei Regeln für dieselbe Sache.
     *
     * **Zwei Wege, eine Regel.** Der zweite Weg unten prüft nichts anderes — er
     * benennt nur anders, weil in der Expertenansicht kein einziges der fünf
     * Felder zu sehen ist. Die Regeln stehen deshalb einmal in `$regeln` und
     * werden beiden Wegen übergeben; zwei Listen liefen auseinander.
     *
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        $regeln = [
            'label' => ['required', 'string', 'max:'.self::LABEL_MAX],
            'command' => ['required', 'string', 'max:8192'],
            'active' => ['sometimes', 'boolean'],
            ...array_fill_keys(Schedule::FIELDS, ['required', 'string', 'max:192']),
        ];

        if (! $request->boolean('experte')) {
            /** @var array<string,mixed> $data */
            $data = $request->validate($regeln, [], self::NAMEN);

            return $data;
        }

        /*
         * **In der Experteneingabe sind die fünf Felder eingeklappt.**
         *
         * Geprüft wird trotzdem dasselbe — der Server urteilt über die fünf
         * Felder und nicht über eine Zeichenkette. Nur seine Auskunft darf
         * nicht auf Felder zeigen, die in dieser Ansicht niemand sieht: Wer
         * `* * *` eintippt und „Das Feld Monat ist erforderlich" liest, sucht
         * etwas, das nicht da ist (`docs/64`, Befund 16).
         *
         * > **Eine Meldung, die ein Feld nennt, das gerade nicht zu sehen ist,
         * > ist keine Auskunft — sie ist eine Suchaufgabe.**
         *
         * Die Meldung nennt deshalb die **Stelle im Ausdruck**. Sie geht an
         * `expression`, weil das der Name des Feldes ist, in dem sie steht.
         */
        $pruefung = Validator::make($request->all(), $regeln, [], self::NAMEN);

        if ($pruefung->fails()) {
            $fehler = $pruefung->errors();
            $ausdruck = [];
            $stelle = 0;

            foreach (Schedule::FIELDS as $feld) {
                $stelle++;

                if (! $fehler->has($feld)) {
                    continue;
                }

                /*
                 * **Der Name kommt aus derselben Liste wie sonst auch.** Eine
                 * eigene hier wäre eine zweite Fassung derselben fünf Wörter,
                 * und die zweite ist die, die beim nächsten Umbenennen
                 * stehenbleibt. Fehlt der Eintrag, steht der Schlüssel im
                 * Satz — sichtbar und nicht still; `AttributeNameTest` hält
                 * die Liste vollständig.
                 */
                $name = (string) trans('validation.attributes.'.$feld);

                $ausdruck[] = trim((string) $request->input($feld, '')) === ''
                    ? sprintf('Im Ausdruck fehlt der %d. Teil (%s).', $stelle, $name)
                    : sprintf(
                        'Der %d. Teil des Ausdrucks (%s): %s',
                        $stelle,
                        $name,
                        (string) $fehler->first($feld),
                    );
            }

            $andere = array_diff_key($fehler->toArray(), array_flip(Schedule::FIELDS));

            throw ValidationException::withMessages(
                $ausdruck === [] ? $andere : ['expression' => $ausdruck] + $andere,
            );
        }

        /** @var array<string,mixed> $data */
        $data = $pruefung->validated();

        return $data;
    }

    /**
     * Das Kontingent des Plans — geprüft, bevor der Agent etwas tut.
     *
     * Gemessen (`docs/60 §5`): Die 10000-Zeilen-Grenze von cron greift für
     * `/etc/cron.d` **nicht**. Es gibt also ausserhalb dieses Kontingents keine
     * Obergrenze, und die Wand im Agenten (`CronApply::MAX_JOBS`) ist eine
     * Notbremse und keine Regel für Kunden.
     */
    private function withinQuota(Subscription $subscription): void
    {
        $limit = $subscription->quota(Quota::CronJobs->value);

        if ($limit === null) {
            return;
        }

        $vorhanden = CronJob::query()->where('subscription_id', (int) $subscription->id)->count();

        if ($vorhanden < $limit) {
            return;
        }

        throw ValidationException::withMessages([
            'label' => sprintf(
                'Dieser Plan erlaubt %d Cronjob%s. Entfernen Sie einen, um einen neuen anzulegen.',
                $limit,
                $limit === 1 ? '' : 's',
            ),
        ]);
    }

    /**
     * Ein Job gehört zu diesem Abonnement — oder es gibt ihn hier nicht.
     *
     * **Die Mandantenklammer hat schon gefiltert**, bevor diese Zeile läuft;
     * dies fängt den Fall, dass ein Admin — für den die Klammer offen ist — eine
     * fremde Jobnummer in eine Adresse dieses Abonnements schreibt.
     */
    private function belongsTo(CronJob $job, Subscription $subscription): void
    {
        abort_unless((int) $job->subscription_id === (int) $subscription->id, 404);
    }

    /**
     * Aus einem Fehlschlag des Agenten die Meldung, die der Kunde lesen soll.
     *
     * `BAD_REQUEST` ist eine Sache der Eingabe und bekommt das Feld, das der
     * Agent nennt; alles andere ist ein Zustand des Servers und steht unter
     * `server` in der Zusammenfassung — ohne dass ein Feld rot wird.
     */
    private function asValidation(AgentException $error): ValidationException
    {
        if ($error->errorCode !== AgentException::BAD_REQUEST) {
            return ValidationException::withMessages([
                'server' => 'Der Zeitplan ist in Ordnung; der Server hat die Änderung nicht '
                    .'angenommen. '.$error->getMessage(),
            ]);
        }

        /*
         * Der Agent nennt das Feld, an dem es lag — dann wird genau dieses rot
         * und nicht das erstbeste. Nennt er keines, ist der Befehl die einzige
         * Eingabe, die übrig bleibt.
         */
        $field = $error->details['field'] ?? null;

        return ValidationException::withMessages([
            is_string($field) && in_array($field, Schedule::FIELDS, true) ? $field : 'command' => $error->getMessage(),
        ]);
    }

    /**
     * Der Satz, der nach dem Speichern oben steht.
     *
     * **Er nennt die Wartezeit**, weil sie gemessen ist und der Kunde sie sonst
     * für einen Fehler hält: Wer einen Minutenjob anlegt und nach zwanzig
     * Sekunden nachsieht, findet nichts.
     *
     * > **„Gespeichert" ist nicht „gilt".**
     */
    private function saved(): string
    {
        return 'Der Cronjob ist gespeichert. Bis er gilt, kann es eine Minute dauern.';
    }
}
