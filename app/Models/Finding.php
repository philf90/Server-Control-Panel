<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FindingCheck;
use App\Enums\FindingState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein Befund der Bestandsdiagnose (A10, `docs/98 §2`).
 *
 * ## Die Kennung ist `check` + `subject` + `reason`
 *
 * **Und ausdrücklich nicht `detail`.** Der Wortlaut der Werkzeuge wechselt bei
 * jedem Lauf: Jede `[emerg]`-Zeile von nginx trägt Datum **und
 * Prozessnummer**, jede Zeile von php-fpm ein Datum (`docs/81 §2.3o` M9). Wäre
 * er Teil der Kennung, ergäbe derselbe Schaden über zwei Nächte zwei Zeilen,
 * `first_seen_at` stünde immer auf heute, und die Falle aus `docs/98 §4` wäre
 * eingebaut statt vermieden.
 *
 * > **Ein Befund braucht eine Kennung, die nicht sein Text ist.**
 *
 * Die Datenbank hält das über einen `unique`-Index und nicht der Code, der
 * schreibt: Sonst hinge Punkt 8 des Abnahmekriteriums daran, dass niemand eine
 * zweite Schreibstelle baut.
 *
 * ## Der Zustand steht nicht in der Tabelle
 *
 * Er folgt aus `check` und `reason` ({@see FindingCheck::state()}), und eine
 * Spalte daneben wäre die zweite Fassung derselben Regel. Wer einen Fall
 * findet, in dem derselbe Grund verschieden schwer wiegt, gibt ihm einen
 * eigenen Grund und keine Spalte.
 *
 * ## Ein Befund gehört dem Server und keinem Kunden
 *
 * Deshalb **kein** {@see Concerns\BelongsToSubscription}: Es gibt keine Spalte,
 * über die die Mandantenklammer greifen könnte, und es soll auch keine geben.
 * Die Grenze sitzt an der Route (`can:inspect-server`) und der ungekürzte
 * `detail` beim Betreiber — so hat es `docs/98 §9` Frage 5 entschieden.
 *
 * @property int $id
 * @property FindingCheck $check
 * @property string $subject
 * @property string $reason
 * @property string|null $detail
 * @property Carbon $first_seen_at
 * @property Carbon $measured_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Finding extends Model
{
    /**
     * Wieviel vom Wortlaut des Werkzeugs aufgehoben wird.
     *
     * **Gekürzt wird beim Schreiben und nicht von der Spalte.** `docs/45` hat
     * gemessen, was eine Spalte anrichtet, die kürzen müsste und stattdessen
     * wirft: Die `PDOException` riss den `catch`-Zweig mit, der den Fehlschlag
     * festhalten sollte, und der Vorgang meldete „vermutlich
     * Zeitüberschreitung" nach einer Sekunde Laufzeit.
     *
     * > **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**
     *
     * 8 KiB, weil die längste gemessene Ausgabe eines Prüfers 453 Byte hatte
     * (`docs/81 §2.3o` M5) und weil eine Grenze, die nie greift, denselben
     * Dienst tut wie keine — nur ohne die Überraschung.
     */
    public const DETAIL_MAX = 8192;

    /** @var list<string> */
    protected $fillable = ['check', 'subject', 'reason', 'detail', 'first_seen_at', 'measured_at'];

    /** Das Urteil — gefragt wird die Prüfung und nicht die Zeile. */
    public function state(): FindingState
    {
        return $this->check->state($this->reason);
    }

    /** Der Satz in unserer Formulierung, den auch der Administrator sieht. */
    public function sentence(): string
    {
        return $this->check->sentence($this->reason);
    }

    /**
     * Den Wortlaut eines Werkzeugs auf das Erlaubte bringen.
     *
     * Gibt `null` zurück, wo nichts zu berichten ist — eine leere Zeichenkette
     * wäre ein `detail`, das es gibt und das nichts sagt.
     */
    public static function trimDetail(?string $detail): ?string
    {
        if ($detail === null || trim($detail) === '') {
            return null;
        }

        return mb_strimwidth(trim($detail), 0, self::DETAIL_MAX, '…');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'check' => FindingCheck::class,
            'first_seen_at' => 'datetime',
            'measured_at' => 'datetime',
        ];
    }
}
