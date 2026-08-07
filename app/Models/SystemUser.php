<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein verbrauchter Systembenutzer — das Verzeichnis hinter `p1000`.
 *
 * Eine Nummer, die hier steht, ist vergeben und kommt nie zurück. Das ist der
 * ganze Zweck: `userdel` gibt die UID frei, das nächste `useradd` vergibt sie
 * wieder, und dann erbte ein neuer Kunde alles, was auf dem Dateisystem noch
 * der alten UID gehört — Dateien in einem Verzeichnis, das der Rückbau nicht
 * erwischt hat, Einträge in `at`- oder `cron`-Warteschlangen, offene Sockets.
 *
 * **Ohne Mandantenklammer, und das ist eine Aussage.** Ein Systembenutzer ist
 * eine Eigenschaft des Servers und nicht eines Kunden. Die Klammer würde hier
 * eine Frage beantworten, die niemand stellt („welche Namen darf dieses Konto
 * sehen"), und im Grundzustand — ein Kommando ohne gesetzten Mandanten — ein
 * leeres Verzeichnis melden. Genau daran hing der Kommentar in
 * `Lifecycle::nextSystemUser()`, solange dort noch `Subscription` gefragt wurde.
 *
 * **Ohne Beziehung zu `Subscription`.** Das Abonnement darf verschwinden, der
 * Name bleibt vergeben; eine Beziehung würde behaupten, das eine hinge am
 * anderen. `subscription` ist eine Abschrift für die Nachschau — „welcher Kunde
 * hatte `p1043`" —, genau wie `subscriptions.main_domain` und aus demselben
 * Grund kein Fremdschlüssel.
 *
 * **Kein `released_at`.** Es gibt keine Freigabe. Ein Feld dafür wäre eine
 * Einladung, sie doch einzubauen.
 *
 * @property int $id
 * @property int $number
 * @property string|null $subscription
 * @property Carbon $claimed_at
 */
class SystemUser extends Model
{
    /**
     * Ohne `created_at`/`updated_at`.
     *
     * `claimed_at` ist der Zeitpunkt, um den es geht, und ein zweiter
     * Zeitstempel daneben wäre eine zweite Fassung derselben Tatsache. Ein
     * `updated_at` behauptete ausserdem, an einer Zeile liesse sich etwas
     * ändern.
     *
     * @var bool
     */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['number', 'subscription', 'claimed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'claimed_at' => 'datetime',
        ];
    }

    /*
     * **Hier steht mit Absicht kein `name()`.** Der Name entsteht aus der Zahl
     * an genau einer Stelle, in {@see \App\Support\Subscriptions\Lifecycle}.
     * Eine zweite Fassung des Präfixes wäre dieselbe Wahrheit an zwei Orten —
     * und die zweite ist erfahrungsgemäss die, die beim nächsten Mal nicht
     * nachgezogen wird.
     */
}
