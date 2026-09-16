<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Subscription;
use App\Support\Plans\Quota;
use App\Support\Tenancy\Tenancy;

/**
 * Wie viele Sicherungen bleiben — Schritt 9 aus `docs/117 §6`.
 *
 * ## Die Zahl steht im Plan und nicht hier
 *
 * {@see Quota::Backups} ist ein Kontingent wie die anderen: je Plan gesetzt, je
 * Abonnement übersteuerbar. Eine Zahl an dieser Stelle wäre eine zweite Fassung
 * derselben Regel — und die zweite ist die, die veraltet.
 *
 * **Unbegrenzt darf sie nicht sein**, und das ist wörtlich die Begründung über
 * `disk_mb`: Eine Sicherung ist das Grösste, was dieses Panel je Abonnement auf
 * die Platte schreibt.
 *
 * > **Eine Aufbewahrung ohne Obergrenze ist keine Aufbewahrung, sondern ein
 * > Wachstum.**
 *
 * ## Eine Sicherung ohne Abonnement wird nicht abgeräumt
 *
 * `backups.subscription_id` steht auf `nullOnDelete`, und der Kopf der Migration
 * sagt warum: *„Die Sicherung überlebt ihr Abonnement."* Nach einem Rückbau gibt
 * es keinen Plan mehr, aus dem eine Zahl käme — und die Datei ist gerade das,
 * was man dann noch hat.
 *
 * > **Eine Aufbewahrungsregel, die auf einen Plan zeigt, den es nicht mehr gibt,
 * > darf nicht auf null fallen.**
 *
 * Sie verschwindet deshalb nur, wenn jemand sie entfernt. Das ist keine Lücke,
 * sondern Schritt 10: Vor dem Löschen eines Abonnements entsteht eine Sicherung,
 * und die soll den Rückbau überleben — sonst wäre sie sinnlos.
 *
 * ## Und die laufende bleibt in Ruhe
 *
 * Gezählt und abgeräumt wird nur, was {@see BackupStatus::Ready} ist. Eine, die
 * gerade geschrieben wird, ist eine halbe Datei; sie zu entfernen hiesse, dem
 * laufenden Vorgang das Ziel unter den Händen wegzunehmen. Eine gescheiterte
 * zählt nicht mit, weil sie nichts aufbewahrt.
 */
final class Retention
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Backups $backups,
    ) {}

    /**
     * Wie viele Stände dieses Abonnement behält — `null`, wenn es keinen Plan
     * mehr gibt.
     *
     * `null` heisst hier **nicht** „unbegrenzt", sondern „keine Regel, die
     * greifen kann". {@see Quota::Backups} lässt unbegrenzt gar nicht zu.
     */
    public function keeps(Subscription $subscription): ?int
    {
        $wert = $subscription->quota(Quota::Backups->value);

        return is_numeric($wert) ? max(0, (int) $wert) : null;
    }

    /**
     * Die ältesten Sicherungen entfernen, bis nur noch `keeps()` übrig sind.
     *
     * **Über {@see Backups::remove()} und nicht über ein eigenes Löschen**: Die
     * Datei liegt `root:srvpanel` und geht nur über den Agenten fort, und die
     * Zeile bleibt stehen, bis er geantwortet hat — die zweite Grenze. Ein
     * `delete()` hier hinterliesse eine Datei, die in keiner Liste steht.
     *
     * @return list<string> die Ablagenamen, deren Entfernung eingereiht wurde
     */
    public function prune(Subscription $subscription): array
    {
        $behalten = $this->keeps($subscription);

        if ($behalten === null) {
            return [];
        }

        /*
         * **Die Klammer steht um die Abfrage und nicht um die Schleife.**
         *
         * Der nächtliche Lauf hat kein angemeldetes Konto; ohne sie gäbe diese
         * Abfrage null Zeilen zurück, und zwar wortlos.
         *
         * Um den `remove()`-Aufruf steht sie **nicht**, und das ist Absicht:
         * Der erste Wurf zog die ganze Schleife hinein, weil `remove()` damals
         * das Abonnement ungeklammert las. Seit dieser Befund behoben ist,
         * klammert `remove()` selbst — und eine zweite Klammer darum machte
         * ausgerechnet den einen Aufrufer blind, an dem ein Rückfall auffiele.
         *
         * > **Eine Vorsichtsmassnahme, die den Fall verdeckt, für den sie
         * > gedacht war, ist keine mehr.**
         */
        $ueberzaehlig = $this->tenancy->withoutRestriction(
            fn (): array => Backup::query()
                ->where('subscription_id', $subscription->id)
                ->where('status', BackupStatus::Ready->value)
                // **Neueste zuerst**, damit `skip()` die jüngsten behält. Über
                // `id` und nicht über `created_at`: Zwei Sicherungen derselben
                // Sekunde hätten denselben Zeitstempel, und dann entschiede die
                // Reihenfolge der Datenbank, welche bleibt.
                ->orderByDesc('id')
                ->skip($behalten)
                ->take(PHP_INT_MAX)
                ->get()
                ->all(),
        );

        $fort = [];

        foreach ($ueberzaehlig as $backup) {
            // `remove()` nimmt den Weg über den Agenten und klammert selbst.
            $this->backups->remove($backup);
            $fort[] = (string) $backup->storage_name;
        }

        return $fort;
    }

    /**
     * Braucht dieses Abonnement heute noch eine Sicherung?
     *
     * **Gefragt wird der jüngste Stand und nicht ein Zeitplan je Abonnement.**
     * Ein eigener Zeitplan wäre eine zweite Uhr neben dem Timer; so entsteht
     * eine Sicherung, wenn die letzte älter ist als das Intervall — und ein Lauf,
     * der zweimal am Tag fährt, legt trotzdem nur eine an.
     *
     * > **Ein Zeitgeber, der fragt „wie alt ist der letzte Stand", ist
     * > wiederholbar. Einer, der fragt „welcher Tag ist heute", ist es nicht.**
     *
     * Eine **laufende** zählt mit: Sonst legte ein Lauf, der neben einer noch
     * nicht fertigen Sicherung startet, eine zweite an.
     */
    public function isDue(Subscription $subscription, int $stunden): bool
    {
        $juengste = $this->tenancy->withoutRestriction(
            fn (): ?Backup => Backup::query()
                ->where('subscription_id', $subscription->id)
                ->whereIn('status', [BackupStatus::Ready->value, BackupStatus::Pending->value])
                ->orderByDesc('id')
                ->first(),
        );

        if ($juengste === null || $juengste->created_at === null) {
            return true;
        }

        return $juengste->created_at->addHours($stunden)->isPast();
    }
}
