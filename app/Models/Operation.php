<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperationStatus;
use App\Models\Concerns\BelongsToSubscription;
use App\Support\Tenancy\Tenancy;
use Database\Factories\OperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein Vorgang: jede Systemänderung, die länger als eine Sekunde dauern kann.
 *
 * Mandantengebunden — und damit der erste echte Anwender der Klammer aus
 * §6.2.1. Vorgänge des Betreibers tragen kein Abonnement und sind für Kunden
 * deshalb unsichtbar, ohne dass irgendwo eine zusätzliche Bedingung stehen
 * müsste.
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property string|null $subscription_name
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $account_id
 * @property string $type
 * @property string|null $task
 * @property OperationStatus $status
 * @property int $progress
 * @property string|null $message
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $result
 * @property string|null $output
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $cancel_requested_at
 * @property int|null $cancelled_by
 * @property-read Account|null $account
 * @property-read Subscription|null $subscription
 */
class Operation extends Model
{
    /** @use HasFactory<OperationFactory> */
    use BelongsToSubscription, HasFactory;

    /*
     * `subscription_name` steht mit Absicht **nicht** darin.
     *
     * Es ist eine Abschrift und keine Eingabe: Wäre die Spalte füllbar, gäbe es
     * einen zweiten Weg, sie zu setzen — einen Vorgang, der einen Namen trägt,
     * den es nie gab, und nichts, das den Widerspruch meldet. Geschrieben wird
     * sie ausschliesslich in {@see self::booted()}.
     */

    /** @var list<string> */
    protected $fillable = [
        'subscription_id', 'subject_type', 'subject_id', 'origin', 'account_id', 'type',
        'task', 'status', 'progress', 'message', 'payload', 'result', 'output',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OperationStatus::class,
            'progress' => 'integer',
            'payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    /**
     * Der Name des Abonnements wird beim Anlegen abgeschrieben.
     *
     * **Weil der Vorgang das Abonnement überleben soll.** Seit docs/35 wird ein
     * zurückgebautes Abonnement hart gelöscht; `subscription_id` fällt dabei
     * auf `null`, und ohne diese Abschrift stünde im Protokoll ein Vorgang, von
     * dem niemand mehr sagen kann, wovon er handelte. Die Datenmigration hat
     * die Namen des Bestands nachgetragen — für alles, was danach entsteht,
     * gibt es nur diese Stelle.
     *
     * **Und zwar am Modell und nicht an den Aufrufern.** Vorgänge entstehen an
     * sechs Stellen (`Operations`, `Lifecycle`, `WebLifecycle` zweimal,
     * `CertificateOrder`, `CertificateLifecycle`). Den Namen dort je einzeln zu
     * setzen wäre dieselbe Regel an sechs Orten, und die eine, die beim
     * nächsten Mal nachgezogen wird, ist erfahrungsgemäss nicht die siebte. Es
     * ist dasselbe Muster wie bei `subscriptions.main_domain`: „nicht von einem
     * Dienst gepflegt, der daran denken muss, sondern vom Modell selbst".
     *
     * In `booted()` und nicht im Trait: Der Filter aus
     * {@see BelongsToSubscription} setzt `subscription_id` selbst, wenn genau
     * ein Mandant aktiv ist, und das muss vorher geschehen sein. Boot-Traits
     * laufen vor `booted()`, die Reihenfolge stimmt also.
     *
     * Ohne Mandantenklammer nachgeschlagen: Ein Vorgang des Betreibers entsteht
     * ohne gesetzten Mandanten, und die Klammer fände das Abonnement nicht,
     * dessen Namen sie abschreiben soll.
     */
    protected static function booted(): void
    {
        static::creating(function (Operation $operation): void {
            if ($operation->subscription_id === null || $operation->subscription_name !== null) {
                return;
            }

            $name = app(Tenancy::class)->withoutRestriction(
                static fn (): mixed => Subscription::query()
                    ->whereKey($operation->subscription_id)
                    ->value('name'),
            );

            // `is_string` und nicht `(string)`: Findet die Abfrage nichts —
            // ein Vorgang, dessen Abonnement zwischen zwei Anfragen
            // verschwunden ist —, käme aus der Umwandlung ein leerer Name
            // heraus. Der sieht aus wie eine Abschrift und ist keine.
            $operation->subscription_name = is_string($name) ? $name : null;
        });
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function open(): bool
    {
        return $this->status->open();
    }

    /**
     * Ist ein Abbruch verlangt worden?
     *
     * Frisch aus der Datenbank und an den Filtern vorbei. Beides mit Grund:
     * Gefragt wird aus dem Arbeiter, während der Vorgang läuft — die Instanz
     * im Speicher stammt vom Beginn und wüsste von einem Wunsch nichts, der
     * seither in einer Anfrage gestellt wurde. Und der Arbeiter hat keinen
     * Mandanten, weshalb der globale Filter hier nichts zu suchen hat.
     */
    public function cancelRequested(): bool
    {
        return $this->newQueryWithoutScopes()
            ->whereKey($this->getKey())
            ->whereNotNull('cancel_requested_at')
            ->exists();
    }
}
