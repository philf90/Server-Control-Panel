<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\BackupStatus;
use App\Enums\FindingCheck;
use App\Models\Backup;
use App\Support\Diagnose\Catalog;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Backup\Store;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Ops\BackupVerify;

/**
 * Sind die Sicherungen noch das, was sie waren? — `backup.file`
 * (`docs/117 §6` Schritt 6).
 *
 * ## Warum es diese Prüfung gibt
 *
 * Eine Sicherung hat genau eine Aufgabe, und sie stellt sich erst an dem Tag,
 * an dem jemand sie braucht. Bis dahin sieht eine kaputte aus wie eine heile:
 * Die Seite zeigt Name, Datum und Grösse, und alle drei stimmen auch dann noch,
 * wenn im Archiv ein Byte gekippt ist.
 *
 * > **Ein Schaden, der erst auffällt, wenn man den Gegenstand braucht, hat
 * > keine Vorstufe.**
 *
 * ## Warum sie in einer eigenen Unit läuft
 *
 * Sie steht in {@see Catalog::BACKUP_CHECKS} und nicht in
 * `CHECKS`. Der Nachtlauf der Bestandsdiagnose kostet gemessen **391 ms**
 * (`docs/100` M19); diese hier liest jedes Archiv des Servers von der Platte
 * und rechnet über jedes Byte. Das ist derselbe Schnitt, mit dem `docs/81 §11`
 * A13 aus A10 gelöst hat:
 *
 * > **Ein `check`, der den Bestand des Kunden liest, gehört nicht in denselben
 * > Lauf wie einer, der eine Konfigurationsdatei prüft — auch wenn beide
 * > dieselbe Form von Befund erzeugen.**
 *
 * Die **Befunde** liegen trotzdem in derselben Liste. Wer sie liest, fragt „was
 * ist auf diesem Server nicht in Ordnung" und nicht „welcher Zeitgeber hat das
 * gemessen".
 *
 * ## Welche Sicherungen sie ansieht
 *
 * Nur die mit {@see BackupStatus::Ready}, und beide Auslassungen haben einen
 * Grund, der nichts mit Sparsamkeit zu tun hat:
 *
 * - Eine **laufende** wird in diesem Augenblick geschrieben. Sie zu lesen gäbe
 *   `unreadable` — jede Nacht, in der ein Lauf sich mit einer Sicherung
 *   überschneidet, ein Befund über etwas, das in Ordnung ist.
 * - Eine **gescheiterte** ist bekanntermassen keine, und die Seite sagt es
 *   bereits. Ein Befund daneben wäre eine zweite Fassung derselben Auskunft.
 *
 * > **Ein Befund über einen Zustand, den die Seite schon nennt, ist keine
 * > Auskunft — er ist eine zweite Stimme, die irgendwann anders klingt.**
 *
 * **Eine verwaiste Sicherung wird mitgeprüft.** Ihr Abonnement ist
 * zurückgebaut, ihre Datei liegt weiter — und eine Sicherung ist gerade das,
 * was man nach einem Rückbau noch hat. Der Name kommt dann aus der Abschrift
 * auf der Zeile, wie in {@see Backup::path()}.
 *
 * ## Was sie nicht kann
 *
 * Sie sagt **nicht**, ob sich eine Sicherung zurückspielen lässt — siehe den
 * Kopf von {@see BackupVerify}. Und sie sagt nicht, ob eine Datei im Archiv
 * dasselbe enthält wie am Tag der Sicherung: Geprüft wird gegen die Prüfsumme,
 * die im Archiv steht. Wer beides ändert, kommt durch.
 *
 * > **Eine Prüfsumme, die neben ihrem Gegenstand liegt, belegt die Übertragung
 * > und nicht die Herkunft.**
 */
final class Backups implements Check
{
    /**
     * **`orphan` kommt nicht aus `BackupVerify`, und das ist richtig so.** Die
     * Prüfung dort urteilt über ein Archiv, das jemand nennt; dieser Grund
     * urteilt über eines, das **niemand** nennt. Er entsteht hier und nirgends
     * sonst.
     */
    public const ORPHAN = 'orphan';

    /**
     * Der Gegenstand, unter dem ein Ausfall **der Auflistung** steht.
     *
     * **Kein Ablagename, und das ist Absicht.** Die übrigen `unreachable`-Zeilen
     * nennen die Sicherung, an der der Agent gescheitert ist; hier ist keine
     * einzelne gescheitert, sondern die Frage „was liegt überhaupt da". Ein
     * Ablagename hier hiesse, es gebe eine Sicherung dieses Namens.
     */
    public const LISTING = 'Ablageort';

    /**
     * Die Gründe, die diese Prüfung ausspricht — je Schlüssel.
     *
     * **Sie kommen aus dem Agenten und nicht aus einer Liste hier.**
     * `BackupVerify::REASONS` ist die Quelle; stünde hier eine zweite
     * Aufzählung, wäre sie die, die veraltet. `DiagnoseSeamTest` hält beide
     * Richtungen gegen `FindingCheck`.
     *
     * @var array<string, list<string>>
     */
    public const REASONS = [
        'backup.file' => [...BackupVerify::REASONS, self::ORPHAN, FindingCheck::UNREACHABLE],
    ];

    public function __construct(
        private readonly Client $agent,
        private readonly Tenancy $tenancy,
    ) {}

    public function writes(): array
    {
        return [FindingCheck::BackupFile];
    }

    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        $backups = $this->ready();

        /*
         * **Kein früher Ausstieg bei null Zeilen, und das ist eine
         * Berichtigung vom 16. September 2026.**
         *
         * Hier stand `if ($backups === []) { replace([]); return; }` mit der
         * Begründung, ein Server ohne Sicherungen habe nichts, was kaputt sein
         * könnte. Das stimmt für die Zeilen und nicht für die Dateien: **Null
         * Zeilen und eine Datei auf der Platte ist genau der Zustand, den
         * `docs/117 §9` Punkt 7 meint** — und der Ausstieg hätte ihn als
         * Erstes übersprungen.
         *
         * > **Ein Ausstieg, der aus dem Bestand des Panels folgt, überspringt
         * > gerade das, was das Panel nicht kennt.**
         */
        $findings = [];
        $ungeprueft = [];

        foreach ($backups as $backup) {
            $name = $backup->subscription->name ?? $backup->subscription_name;

            if (! is_string($name) || $name === '') {
                /*
                 * Ohne Namen gibt es keinen Pfad — und ohne Pfad keine Frage,
                 * die der Agent beantworten könnte.
                 *
                 * **Gemeldet und nicht übersprungen**, und das ist eine
                 * Berichtigung: Hier stand ein stilles `continue` mit dem
                 * Hinweis, `orphan.row` fange den Fall. Nachgesehen fängt es
                 * ihn nicht — {@see Orphans} kennt Zertifikate, Systembenutzer
                 * und Cron-Dateien und keine Sicherungen.
                 *
                 * > **Ein Prüfkörper, der überspringt, meldet das Überspringen
                 * > nicht.**
                 *
                 * `backups.subscription_name` ist `NOT NULL` und wird beim
                 * Anlegen aus dem Abonnement geschrieben; dieser Zweig sollte
                 * also nie an die Reihe kommen. Genau deshalb steht hier ein
                 * Befund und kein `continue`: Was nicht vorkommen kann und doch
                 * vorkommt, ist das, worüber man es erfahren will.
                 */
                $findings[] = [
                    'subject' => $backup->storage_name,
                    'reason' => BackupVerify::MISSING,
                    'detail' => 'Diese Zeile nennt kein Abonnement — zu ihr lässt sich keine Datei finden.',
                ];

                continue;
            }

            try {
                $antwort = $this->agent->call('backup.verify', [
                    'subscription' => $name,
                    'storage' => $backup->storage_name,
                ]);
            } catch (AgentException $e) {
                /*
                 * **Je Sicherung entschieden und nicht für den ganzen Lauf.**
                 * Ein einzelnes Archiv, an dem der Agent scheitert, darf die
                 * Urteile über die übrigen nicht mitnehmen — sonst stünde nach
                 * einer kaputten Sicherung für alle „nicht nachgesehen".
                 *
                 * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus
                 * > wie „nichts zu tun".**
                 */
                $ungeprueft[$backup->storage_name] = $e->getMessage();

                continue;
            }

            foreach ($this->findingsOf($antwort, $backup->storage_name) as $finding) {
                $findings[] = $finding;
            }
        }

        /*
         * **Erst `replace()`, dann `unreachable()`, und die Reihenfolge ist
         * tragend.** `FindingLog::replace()` ruft `forgetMissing()` und löscht
         * damit jede Zeile dieser Prüfung, die nicht in seiner eigenen Liste
         * steht — ein `unreachable()` davor wäre danach fort, wortlos.
         *
         * Das ist **kein** Vorbild aus {@see Certificates}: Dort steht das
         * `unreachable()` in einem Zweig, der danach zurückkehrt, weil dort der
         * ganze Lauf ausfällt. Hier fällt er je Sicherung aus, und deshalb
         * müssen beide Arten von Zeile nebeneinander stehen können.
         *
         * > **Eine Reihenfolge, die aus einer Eigenschaft des Werkzeugs folgt,
         * > gehört neben den Aufruf und nicht in die Erinnerung.**
         */
        /*
         * **Und die Gegenrichtung** (`docs/117 §9` Punkt 7): eine Datei, zu der
         * es keine Zeile gibt. Sie entsteht, wenn ein `backup.remove`
         * scheitert, nachdem die Zeile fort ist — und sie kostet Platz, den
         * niemand zuordnet.
         */
        try {
            foreach ($this->orphans() as $finding) {
                $findings[] = $finding;
            }
        } catch (AgentException $e) {
            $ungeprueft[self::LISTING] = $e->getMessage();
        }

        $log->replace(FindingCheck::BackupFile, $findings, $measuredAt);

        foreach ($ungeprueft as $storage => $meldung) {
            $log->unreachable(FindingCheck::BackupFile, [(string) $storage], $measuredAt, $meldung);
        }
    }

    /**
     * Dateien, zu denen es keine Zeile gibt.
     *
     * **Gefragt wird der Agent und nicht `glob()`.** Der Ablageort ist `0710
     * root:srvpanel` — durchsuchbar für die Gruppe, nicht auflistbar. Das Panel
     * kommt an eine Datei heran, deren Namen es kennt; welche es gibt, weiss
     * nur der Agent.
     *
     * **Verglichen wird gegen *alle* Zeilen und nicht nur die fertigen.** Eine
     * Sicherung, die gerade geschrieben wird, steht auf `pending` und hat ihre
     * Datei schon — sie als Rest zu melden hiesse, jeden laufenden Vorgang
     * anzuzeigen.
     *
     * > **Ein Rest ist, was niemand mehr nennt — nicht, was noch niemand
     * > fertig genannt hat.**
     *
     * **Und der Name kommt aus der Abschrift.** `subscription_name` steht auch
     * dann noch da, wenn das Abonnement fort ist — und genau dann liegen die
     * Dateien noch, um die es hier geht.
     *
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    private function orphans(): array
    {
        $antwort = $this->agent->call('backup.list');
        $dateien = is_array($antwort['files'] ?? null) ? $antwort['files'] : [];

        return self::orphansOf($dateien, $this->known());
    }

    /**
     * `<abo>/<ablage>` je Zeile, die das Panel führt.
     *
     * **Alle Zeilen und nicht nur die fertigen**, und das ist die Stelle, an
     * der sich der Fehler machen liesse: Eine Sicherung auf `pending` hat ihre
     * Datei schon, und ein Filter auf `ready` machte aus jedem laufenden
     * Vorgang einen gemeldeten Rest.
     *
     * > **Ein Rest ist, was niemand mehr nennt — nicht, was noch niemand fertig
     * > genannt hat.**
     *
     * **Sie steht als eigene Methode da, damit ein Wächter sie fahren kann.**
     * Der erste Wurf hatte die Abfrage im Rumpf von {@see self::orphans()}, und
     * der Prüfkörper baute sie daneben nach — ein Filter auf `ready` blieb dann
     * grün, weil der Test seine eigene Menge mass.
     *
     * > **Ein Prüfkörper, der die Stelle nachbaut, an der der Fehler entstehen
     * > würde, misst sie nicht.**
     *
     * **Der Name kommt aus der Abschrift.** `subscription_name` steht auch dann
     * noch da, wenn das Abonnement fort ist — und genau dann liegen die
     * Dateien, um die es hier geht.
     *
     * @return list<string>
     */
    public function known(): array
    {
        return $this->tenancy->withoutRestriction(static fn (): array => Backup::query()
            ->get(['subscription_name', 'storage_name'])
            ->map(static fn (Backup $backup): string => $backup->subscription_name.'/'.$backup->storage_name)
            ->all());
    }

    /**
     * Die Regel selbst — was ist ein Rest, und was nicht.
     *
     * **Sie steht getrennt, weil {@see Client} `final` ist.** Der Weg dahinter
     * liesse sich sonst nicht messen; dieselbe Naht wie bei
     * {@see self::findingsOf()}, und aus demselben Grund.
     *
     * > **Eine Klasse, die sich nicht ersetzen lässt, hat keinen Test — und der
     * > Weg dahinter auch nicht.**
     *
     * @param  list<mixed>  $dateien  was der Agent aufgelistet hat
     * @param  list<string>  $bekannt  `<abo>/<ablage>` je Zeile des Panels
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    public static function orphansOf(array $dateien, array $bekannt): array
    {
        $gesucht = array_fill_keys($bekannt, true);
        $findings = [];

        foreach ($dateien as $datei) {
            if (! is_array($datei)) {
                continue;
            }

            $abonnement = is_string($datei['subscription'] ?? null) ? $datei['subscription'] : '';
            $ablage = is_string($datei['storage'] ?? null) ? $datei['storage'] : '';

            /*
             * **Ein Eintrag ohne Namen wird übersprungen und nicht gemeldet.**
             * Er sagt nichts über eine Datei; ihn als Rest zu führen hiesse,
             * einen Befund über einen Gegenstand anzulegen, den niemand
             * aufsuchen kann.
             */
            if ($abonnement === '' || $ablage === '' || isset($gesucht[$abonnement.'/'.$ablage])) {
                continue;
            }

            $findings[] = [
                'subject' => $abonnement.'/'.$ablage,
                'reason' => self::ORPHAN,
                'detail' => sprintf(
                    'Zu dieser Datei gibt es keine Zeile: %s/%s/%s.zip. Sie bleibt liegen, bis '
                    .'jemand sie entfernt — der nächtliche Lauf räumt sie nicht ab.',
                    Store::ROOT,
                    $abonnement,
                    $ablage,
                ),
            ];
        }

        return $findings;
    }

    /**
     * Die Sicherungen, die geprüft werden — ohne Mandantenklammer.
     *
     * Ein Nachtlauf hat kein angemeldetes Konto; die Klammer stünde auf
     * `whereRaw('0 = 1')` und gäbe eine leere Liste zurück. Wortlos, und der
     * Lauf meldete „keine Befunde".
     *
     * > **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer
     * > leeren Liste und nicht mit einem Fehler.** (`docs/78`)
     *
     * @return list<Backup>
     */
    private function ready(): array
    {
        return $this->tenancy->withoutRestriction(fn (): array => Backup::query()
            ->where('status', BackupStatus::Ready->value)
            ->with('subscription')
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Die Befunde aus einer Antwort — geprüft und nicht durchgereicht.
     *
     * **Der Grund wird gegen {@see BackupVerify::REASONS} gehalten.** Käme aus
     * dem Agenten ein Wort, das der Katalog nicht kennt, würfe
     * {@see FindingCheck::state()} — nachts, in einem Lauf, den niemand sieht,
     * und der ganze Lauf wäre fort. Hier wird daraus eine Zeile, die sagt, dass
     * der Agent etwas sagt, das dieses Panel nicht versteht.
     *
     * @param  array<string, mixed>  $antwort
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    public static function findingsOf(array $antwort, string $storage): array
    {
        $roh = $antwort['findings'] ?? null;

        if (! is_array($roh)) {
            return [[
                'subject' => $storage,
                'reason' => BackupVerify::UNREADABLE,
                'detail' => 'Der Agent hat auf die Prüfung dieser Sicherung keine Befundliste geschickt.',
            ]];
        }

        $findings = [];

        foreach ($roh as $eintrag) {
            if (! is_array($eintrag)) {
                continue;
            }

            $reason = $eintrag['reason'] ?? null;

            if (! is_string($reason) || ! in_array($reason, BackupVerify::REASONS, true)) {
                $findings[] = [
                    'subject' => $storage,
                    'reason' => BackupVerify::UNREADABLE,
                    'detail' => 'Der Agent nennt einen Grund, den dieses Panel nicht kennt — Panel und Agent haben verschiedene Versionen.',
                ];

                continue;
            }

            $subject = $eintrag['subject'] ?? null;
            $detail = $eintrag['detail'] ?? null;

            $findings[] = [
                // Der Gegenstand ist der Ablagename. Nennt die Antwort keinen,
                // trägt der, nach dem gefragt wurde — ein Befund ohne Ort
                // erfüllt `docs/98 §7` Punkt 2 nicht.
                'subject' => is_string($subject) && $subject !== '' ? $subject : $storage,
                'reason' => $reason,
                'detail' => is_string($detail) && $detail !== '' ? $detail : null,
            ];
        }

        return $findings;
    }
}
