<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Enums\OperationStatus;
use App\Models\Account;
use App\Models\Operation;
use Illuminate\Support\Carbon;

/**
 * Was der Streifen oben auf jeder Seite zeigt (B8, `docs/132 §2`).
 *
 * ## Warum es diese Klasse gibt und nicht eine Abfrage in `share()`
 *
 * Die Regel ist nicht „alle laufenden Vorgänge", sondern **drei** Bedingungen,
 * von denen jede eine Begründung trägt. Stünden sie als Kette in der
 * Mittelschicht, läge die Begründung im Kommentar einer Zeile, die beim
 * nächsten Umbau umzieht — und ein Wächter über sie müsste den Quelltext der
 * Mittelschicht lesen statt eine Antwort zu messen.
 *
 * ## Erstens: nur das eigene Konto
 *
 * Ein Vorgang gehört dem Konto, das ihn abgesetzt hat. Zwei Kunden sehen
 * einander nicht, und der Betreiber sieht nicht, was ein Kunde losgeschickt
 * hat — **auch dann nicht, wenn die Mandantenklammer es zuliesse**. Die
 * Klammer entscheidet, welche *Abonnements* jemand sieht; wer einen Knopf
 * gedrückt hat, ist eine andere Frage.
 *
 * > **Wessen Vorgang das ist, sagt nicht die Mandantenklammer, sondern wer ihn
 * > abgesetzt hat.**
 *
 * ## Zweitens: die des Systems erscheinen bei niemandem
 *
 * Die Zertifikatsautomatik und der Cron-Einsammler setzen `account_id` auf
 * `null` — dieselbe Null, die `docs/901` als „System" liest. Damit ist Frage 4
 * aus `docs/92 §4` beantwortet: Sie sind gemeint, und für sie ändert sich
 * nichts. Sie stehen weiter unter `/operations`.
 *
 * > **Eine Null, die schon eine Bedeutung trägt, kann keine zweite
 * > bekommen.**
 *
 * ## Drittens: fertige Vorgänge bleiben eine Weile
 *
 * Verschwände ein Vorgang beim Fertigwerden aus der Liste, wüsste der Klient,
 * **dass** er weg ist, und nicht, **wie** er ausgegangen ist. Deshalb bleibt
 * er {@see self::FRESH_SECONDS} Sekunden stehen — lange genug, um den Ausgang
 * zu lesen, und kurz genug, dass er von selbst vergeht.
 *
 * > **Ein Zustand, der von selbst vergeht, braucht kein Gedächtnis.**
 *
 * Das erspart eine Ablage „gesehen" und damit eine zweite Tabelle, die bei
 * jedem Seitenaufbau geschrieben würde.
 */
final class RunningBand
{
    /**
     * Wie lange ein fertiger Vorgang im Streifen stehenbleibt.
     *
     * Zwei Minuten. Die Zahl entscheidet nichts Sicherheitsrelevantes, und
     * deshalb steht hier, woran sie hängt: Sie muss länger sein als der Takt,
     * mit dem der Klient nachfragt (drei Sekunden), und kurz genug, dass
     * niemand nach einer Pause einen Streifen über etwas liest, das er längst
     * vergessen hat.
     */
    public const FRESH_SECONDS = 120;

    /**
     * Die Vorgänge, die der Streifen zeigt — höchstens {@see self::LIMIT}.
     *
     * **Die Grenze ist keine Sparsamkeit, sondern eine über die Anzeige.**
     * Wer zwanzig Vorgänge absetzt, bekommt keinen Bildschirm voll Bänder:
     * Vier Zeilen sind bei 390 px schon die halbe Seite.
     */
    public const LIMIT = 4;

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(?Account $account, Carbon $now): array
    {
        if (! $account instanceof Account) {
            return [];
        }

        $frisch = $now->copy()->subSeconds(self::FRESH_SECONDS);

        /*
         * **Die Mandantenklammer bleibt stehen**, und das ist eine
         * Entscheidung und kein Vergessen. Sie nähme nichts weg, was
         * hierhergehört: Ein Kunde setzt nur Vorgänge an Abonnements ab, die
         * er ohnehin erreicht, und ein Admin steht auf `allowAll()`. Sie
         * abzuschalten hiesse, eine zweite Wand einzureissen, die hier nichts
         * kostet.
         *
         * > **Eine Ausnahme, die nichts freigibt, ist keine Ausnahme, sondern
         * > eine offene Tür für später.**
         */
        return Operation::query()
            ->where('account_id', $account->id)
            ->where(function ($abfrage) use ($frisch): void {
                $abfrage
                    ->whereIn('status', [OperationStatus::Queued->value, OperationStatus::Running->value])
                    ->orWhere(fn ($oder) => $oder->where('finished_at', '>=', $frisch));
            })
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Operation $vorgang): array => [
                'id' => $vorgang->id,
                'label' => $vorgang->task ?? $vorgang->type,
                'status' => $vorgang->status->value,
                'progress' => $vorgang->progress,
                'running' => $vorgang->status->open(),
            ])
            ->all();
    }
}
