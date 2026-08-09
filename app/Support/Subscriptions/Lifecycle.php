<?php

declare(strict_types=1);

namespace App\Support\Subscriptions;

use App\Enums\OperationStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Subscription;
use App\Models\SystemUser;
use App\Support\Operations\AfterOperation;
use App\Support\Plans\Quota;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Der Lebenslauf eines Abonnements — und wer den Zustand setzt.
 *
 * **Der Zustand folgt dem System, nicht der Absicht.** Das ist die
 * Entscheidung, um die es in dieser Klasse geht. Der naheliegende Weg wäre,
 * beim Klick auf „Sperren" den Zustand sofort auf `suspended` zu setzen und
 * den Vorgang nebenher laufen zu lassen. Dann steht in der Liste „gesperrt",
 * während das Abonnement weiter ausliefert — und niemand sieht den
 * Unterschied, denn genau danach schaut man ja in der Liste.
 *
 * Deshalb setzt hier nichts einen Zustand, bevor der Agent geantwortet hat.
 * {@see self::afterSuccess()} läuft im Arbeiter, nachdem die Operation
 * durchgelaufen ist. Scheitert sie, bleibt der alte Zustand stehen, und der
 * Vorgang ist sichtbar fehlgeschlagen. Beides zusammen ist die Wahrheit.
 *
 * **Der Arbeiter hat keinen Mandanten.** Er läuft ohne angemeldetes Konto;
 * der Grundzustand der Klammer ist „nichts", und damit fände er das
 * Abonnement nicht, dessen Zustand er setzen soll. Deshalb steht auch hier
 * ein ausdrückliches `withoutRestriction` — an einer Stelle, mit einem Namen,
 * der beim Lesen auffällt.
 */
final class Lifecycle implements AfterOperation
{
    /** Die erste Nummer eines Systembenutzers. Vier Stellen, wie der Agent verlangt. */
    public const FIRST_USER = 1000;

    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Der nächste freie Systembenutzer — ohne Zuteilung.
     *
     * Für das Formular: Es zeigt, was der nächste wäre. Verbraucht wird der
     * Name erst mit {@see self::claim()}.
     *
     * **Gefragt wird das Verzeichnis und nicht der Bestand.** Bis August 2026
     * stand hier ein `withTrashed()` auf dem Abonnement, und daran hing der
     * ganze Grund, aus dem ein zurückgebautes als Zeile liegen blieb: Es
     * hatte seinen Namen verbraucht, und sähe die Vergabe ihn nicht, bekäme ein
     * neuer Kunde `p1000` ein zweites Mal — samt allem, was auf dem
     * Dateisystem noch der alten UID gehört. Der Grund war richtig, das Mittel
     * zu grob; 121 Zeilen auf dem Zielserver existierten für diese eine
     * Abfrage. Seit docs/35 steht die Reservierung als eigene Tabelle da.
     *
     * **Und hier steht deshalb kein `withoutRestriction` mehr.** Es war nötig,
     * weil `Subscription` die Mandantenklammer trägt: Ein Kunde — oder ein
     * Kommando ohne gesetzten Mandanten — sah kein einziges Abonnement und
     * bekam `p1000` zurück, den es längst gab. Aufgefallen war das im Test, der
     * nach dem Rückbau erneut vergibt. {@see SystemUser} trägt keine Klammer,
     * und damit fällt die Ausnahme weg statt vergessen zu werden.
     *
     * `MAX(number)` und nicht mehr die Suche in PHP: Ein `CAST(SUBSTRING(...))`
     * fiel auf MariaDB und SQLite verschieden aus, und dann prüften die Tests
     * etwas anderes als der Server. Über eine Zahlspalte ist die Frage auf
     * beiden dieselbe.
     */
    public function nextSystemUser(): string
    {
        return $this->name(max(self::FIRST_USER, ((int) SystemUser::query()->max('number')) + 1));
    }

    /**
     * Den nächsten Namen vergeben und verbrauchen.
     *
     * In einer Transaktion mit dem Anlegen des Abonnements — steht sie
     * ausserhalb, verbraucht ein fehlgeschlagenes Anlegen eine Nummer.
     *
     * **Der eindeutige Index auf `number` ist die Sicherung.** Zwei
     * gleichzeitige Anlagen sehen sonst dieselbe höchste Nummer und wollen
     * beide die nächste. Bis hierher scheiterte die zweite am eindeutigen Index
     * auf `subscriptions.system_user`, und zwar mit einer Meldung, die der
     * Betreiber nicht deuten kann. Jetzt läuft sie in die Kollision, holt sich
     * die nächste und ist fertig. Perfekt ist das nicht: Bei sehr hoher Last
     * bleibt eine Restwahrscheinlichkeit, dass fünf Versuche nicht reichen —
     * für ein Panel auf einem einzelnen Server ist das die richtige
     * Grössenordnung, und der Fehlschlag ist wenigstens lesbar.
     */
    public function claim(string $subscription): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = max(self::FIRST_USER, ((int) SystemUser::query()->max('number')) + 1);

            try {
                SystemUser::query()->create([
                    'number' => $number,
                    'subscription' => $subscription,
                    'claimed_at' => now(),
                ]);

                return $this->name($number);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new RuntimeException('Es liess sich kein Systembenutzer vergeben.');
    }

    /**
     * Aus der Zahl wird der Name — an dieser einen Stelle.
     *
     * Beides zu speichern wäre eine zweite Fassung derselben Wahrheit; das
     * Präfix zweimal zu schreiben wäre dieselbe Regel an zwei Orten.
     */
    private function name(int $number): string
    {
        return 'p'.$number;
    }

    /**
     * Die Argumente für eine Operation des Agenten.
     *
     * **Sie kommen aus der abgelegten Zeile und nicht aus der Anfrage.** Das
     * ist dieselbe Regel wie im Aufgabenkatalog (App\Support\Operations\Task),
     * nur an einem Objekt statt an einer festen Liste: Der Browser nennt ein
     * Abonnement, die Mandantenklammer entscheidet, ob er es überhaupt sehen
     * darf, und die Werte, die den Agenten erreichen, stehen in der Datenbank.
     * Ein Name aus dem Formular käme nie bis hierher.
     *
     * @return array<string, mixed>
     */
    public function payload(Subscription $subscription): array
    {
        $payload = [
            'name' => (string) $subscription->name,
            'user' => (string) $subscription->system_user,
        ];

        // Der Speicher steht am Plan und kann am Abonnement übersteuert sein.
        // `null` heisst unbegrenzt — nur darf er das hier nicht sein (siehe
        // App\Support\Plans\Quota), deshalb kommt er nie als `null` an.
        $disk = $subscription->quota(Quota::DiskMb->value);

        if (is_numeric($disk)) {
            $payload['quota_mb'] = (int) $disk;
        }

        return $payload;
    }

    /**
     * Einen Vorgang für ein Abonnement einreihen.
     *
     * **Hier und nicht im Controller, seit es zwei Auslöser gibt.** Bis August
     * 2026 stand das als private Methode in `SubscriptionController`; dann kam
     * die Kundensperre dazu, die dieselben Vorgänge für alle Abonnements eines
     * Kunden einreiht. Zwei Fassungen davon hiessen: zwei Stellen, an denen
     * die Argumente entstehen, und die eine, die beim nächsten Mal nachgezogen
     * wird, ist erfahrungsgemäss nicht beide.
     *
     * Die Argumente kommen aus der abgelegten Zeile und nicht aus einer
     * Anfrage — siehe {@see self::payload()}. Der Vorgang trägt das
     * Abonnement, damit ihn der Kunde in seiner eigenen Liste sieht.
     */
    public function dispatch(Subscription $subscription, string $task, string $message): Operation
    {
        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'account_id' => request()->user()?->getAuthIdentifier(),
            'type' => $task,
            'task' => $task,
            'payload' => $this->payload($subscription),
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => $message,
        ]);

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }

    /**
     * Die Aufgaben, nach denen sich am Abonnement etwas ändert.
     *
     * `subscription.usage` und `subscription.quota` stehen **nicht** darin:
     * Die Messung schreibt ihr Ergebnis selbst (siehe
     * {@see Usage}), und ein Kontingent ändert
     * nichts am Zustand des Abonnements.
     *
     * @return list<string>
     */
    public static function handles(): array
    {
        return [
            'subscription.provision',
            'subscription.suspend',
            'subscription.resume',
            'subscription.remove',
        ];
    }

    /**
     * Nach einem gescheiterten Vorgang bleibt der Zustand, wie er war.
     *
     * **Nichts zu tun, und das ist die Aussage.** Der Zustand eines
     * Abonnements folgt ausschliesslich der Antwort des Agenten
     * ({@see self::afterSuccess()}); ohne Antwort gibt es nichts nachzuziehen.
     * Ein Rückbau, der scheitert, lässt das Abonnement bestehen — genau
     * richtig, denn auf dem System steht es dann auch noch.
     */
    public function afterFailure(Operation $operation): void {}

    /**
     * Was ein erfolgreicher Vorgang am Abonnement ändert.
     *
     * Aufgerufen aus dem Arbeiter, nachdem der Agent geantwortet hat.
     */
    public function afterSuccess(Operation $operation): void
    {
        $task = (string) ($operation->task ?? '');

        if (! str_starts_with($task, 'subscription.')) {
            return;
        }

        $this->tenancy->withoutRestriction(function () use ($operation, $task): void {
            $subscription = Subscription::query()->find($operation->subscription_id);

            if ($subscription === null) {
                return;
            }

            match ($task) {
                'subscription.provision' => $subscription->forceFill([
                    'status' => SubscriptionStatus::Active,
                    'suspended_at' => null,
                ])->save(),

                'subscription.suspend' => $subscription->forceFill([
                    'status' => SubscriptionStatus::Suspended,
                    'suspended_at' => now(),
                ])->save(),

                'subscription.resume' => $subscription->forceFill([
                    'status' => SubscriptionStatus::Active,
                    'suspended_at' => null,
                ])->save(),

                // Der Rückbau ist durch: Verzeichnis weg, Konto weg. Die Zeile
                // geht mit — der Systembenutzer bleibt trotzdem verbraucht, er
                // steht seit docs/35 im Verzeichnis und nicht mehr in dieser
                // Zeile.
                'subscription.remove' => $this->withdraw($subscription),

                default => null,
            };
        });
    }

    /**
     * Das Abonnement zurückbauen — Domains hart, Vorgänge abgelöst, Zeile weg.
     *
     * **Die Reihenfolge ist der Punkt.** Erst die Domains, dann die Vorgänge,
     * dann die Zeile. Was danach über das Abonnement liefe, träfe nichts mehr.
     *
     * **Warum die Domains einzeln gehen und nicht über die Kaskade.** Mit dem
     * Abonnement ist ihr Verzeichnis fort, ihr vhost, ihr Protokoll — hart
     * löschen ist richtig. Nur stand hier ein Massenlöschen über den
     * Erbauer, und das feuert **keine Modellereignisse**. Genau an einem davon
     * hängt aber die Abschrift `subscriptions.main_domain`: Das Modell setzt
     * sie beim `deleted` auf null. Übersprungen hielt ein gekündigtes
     * Abonnement seine Hauptdomain für immer fest — und weil die Spalte in P1
     * als eindeutig angelegt worden war, scheiterte das nächste Abonnement mit
     * demselben Namen an einem Index statt an einer Regel:
     *
     *     Duplicate entry 'abnahme-web-2.invalid'
     *     for key 'subscriptions_main_domain_unique'
     *
     * Der Index ist inzwischen gefallen, weil er eine zweite Wahrheit war. Das
     * einzelne Löschen bleibt trotzdem: Im Modell steht der Kommentar, die
     * Abschrift werde „nicht von einem Dienst gepflegt, der daran denken muss,
     * sondern vom Modell selbst". Ein Löschweg, der am Modell vorbeigeht,
     * macht aus dieser Zusage eine Behauptung.
     *
     * **Warum die Vorgänge hier von Hand abgelöst werden.** Auf MariaDB steht
     * `operations.subscription_id` seit docs/35 auf `nullOnDelete` und täte das
     * von selbst. Auf SQLite steht er weiter auf `cascadeOnDelete`, weil sich
     * ein Fremdschlüssel dort überhaupt nicht ändern lässt — und dann nähme
     * `forceDelete()` das Vorgangsprotokoll mit. Ein Rückbau, der auf dem
     * Server etwas anderes tut als im Test, ist genau die Sorte Fehler, die
     * docs/35 §7 benennt. Der Name des Abonnements bleibt am Vorgang stehen; er
     * wird beim Anlegen abgeschrieben ({@see Operation::booted()}).
     *
     * `forceDelete()` und nicht `delete()`: Es soll auch dann hart löschen,
     * wenn das Modell den Trait wider Erwarten wieder trägt.
     */
    private function withdraw(Subscription $subscription): void
    {
        Domain::query()
            ->where('subscription_id', $subscription->id)
            ->get()
            ->each(static fn (Domain $domain): ?bool => $domain->delete());

        Operation::query()
            ->where('subscription_id', $subscription->id)
            ->update(['subscription_id' => null]);

        // **Und die Zertifikate genauso — aus einem schärferen Grund.** Ein
        // Zertifikatsverzeichnis liegt unter `/etc/srvpanel/tls/certs` und
        // damit ausserhalb des Abo-Verzeichnisses; `subscription.remove` räumt
        // es nicht mit weg. Kaskadierte die Zeile hier, bliebe die Datei
        // liegen — **samt privatem Schlüssel** — und nichts zeigte mehr auf
        // sie. Zwölf solcher Verzeichnisse lagen auf dem Zielserver, als die
        // Migration aus docs/35 danach fragte.
        //
        // Entfernt werden die Dateien nicht hier: Welcher Ablageort fort darf,
        // hängt daran, ob ihn noch ein anderes Zertifikat nennt, und das ist
        // eine Frage an den Bestand und nicht an diesen einen Rückbau. Das tut
        // `srvpanel tls prune`. Bis dahin bleibt die Zeile als Wegweiser.
        Certificate::query()
            ->where('subscription_id', $subscription->id)
            ->update(['subscription_id' => null]);

        $subscription->forceDelete();
    }
}
