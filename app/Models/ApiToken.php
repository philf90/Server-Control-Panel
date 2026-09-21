<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToSubscription;
use App\Support\Tenancy\Tenancy;
use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Zugangsmarke für `api/v1` (B7).
 *
 * ## Der Klartext steht genau einmal auf dem Bildschirm
 *
 * Er entsteht in {@see self::mint()}, wird an den Aufrufer zurückgegeben und
 * danach vergessen — abgelegt ist nur sein `sha256`. Dieselbe Regel wie bei
 * den Wiederherstellungscodes des zweiten Faktors, und aus demselben Grund:
 * Was man wiederzeigen kann, kann man auch mitlesen.
 *
 * > **Ein Geheimnis, das sich ein zweites Mal anzeigen lässt, ist keines
 * > mehr — die Frage ist nur, wer beim zweiten Mal zusieht.**
 *
 * ## Warum hier keine Mandantenklammer steht
 *
 * {@see BelongsToSubscription} klammert auf Abonnements.
 * Eine Marke gehört einem **Konto** und keinem Abonnement — was sie erreicht,
 * entscheidet die Klammer, die {@see Tenancy::forAccount()}
 * aus diesem Konto ableitet, und nicht die Zeile hier. Eine eigene Klammer
 * wäre die zweite Fassung derselben Entscheidung.
 *
 * @property int $id
 * @property int $account_id
 * @property string $name
 * @property string $token_hash
 * @property string $preview
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account|null $account
 */
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    /**
     * Woran man eine Marke dieses Panels erkennt.
     *
     * **Ein Präfix ist kein Schmuck.** Es macht einen versehentlich
     * veröffentlichten Schlüssel für einen Menschen als solchen erkennbar —
     * und für einen Suchlauf über eigene Quelltexte auffindbar, bevor es
     * jemand anderes tut.
     */
    public const PREFIX = 'srvp_';

    /**
     * Wie viele Zufallsbytes hinter dem Präfix stehen.
     *
     * Vierundzwanzig Bytes sind 48 Hexzeichen und 192 Bit. Das ist weit
     * jenseits dessen, was jemand erraten kann, und genau der Grund, warum
     * {@see self::hashOf()} ohne Arbeitsfaktor auskommt (`docs/130` A5).
     */
    public const RANDOM_BYTES = 24;

    /**
     * Wie viele Zeichen des Klartexts die Liste später zeigt.
     *
     * Präfix plus vier Zeichen. Wer drei Marken hat, erkennt daran, welche in
     * welchem Skript steckt; was fehlt, sind vierundvierzig Hexzeichen, also
     * 176 Bit.
     */
    public const PREVIEW_LENGTH = 9;

    /**
     * Ab welchem Abstand `last_used_at` neu geschrieben wird.
     *
     * **Nicht bei jeder Anfrage**, und das ist kein Geiz: `CACHE_STORE` und
     * `SESSION_DRIVER` stehen auf dem Server auf `database`
     * (`agent/src/Ops/PanelProvision.php`), und ein Schreibvorgang je
     * API-Anfrage wäre eine Zeile, die niemand liest, in einer Tabelle, die
     * jede Anfrage sperrt. Eine Minute Auflösung beantwortet die Frage, für
     * die es das Feld gibt — „wird diese Marke noch benutzt?".
     */
    public const USAGE_RESOLUTION_SECONDS = 60;

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Der Hash, unter dem eine Marke abgelegt ist.
     *
     * **Eine Stelle und nicht zwei.** Das Anlegen und die Wache müssen
     * dieselbe Rechnung machen; zwei Aufrufe von `hash()` an zwei Orten wären
     * zwei Fassungen derselben Regel, und die zweite ist die, die veraltet —
     * hier hiesse „veraltet" *„niemand kommt mehr herein"*.
     */
    public static function hashOf(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Eine neue Marke anlegen — und ihren Klartext **einmal** herausgeben.
     *
     * @return array{token: ApiToken, plain: string}
     */
    public static function mint(Account $account, string $name): array
    {
        $plain = self::PREFIX.bin2hex(random_bytes(self::RANDOM_BYTES));

        $token = new self;
        $token->account_id = $account->id;
        $token->name = $name;
        $token->token_hash = self::hashOf($plain);
        $token->preview = substr($plain, 0, self::PREVIEW_LENGTH);
        $token->save();

        return ['token' => $token, 'plain' => $plain];
    }

    /**
     * Festhalten, dass die Marke benutzt wurde — höchstens einmal je Minute.
     *
     * Gibt zurück, ob wirklich geschrieben wurde. Das ist kein Beiwerk: Ein
     * Wächter über „schreibt nicht bei jeder Anfrage" braucht eine Antwort,
     * die er lesen kann, und nicht die Abwesenheit einer Abfrage.
     */
    public function noteUsage(Carbon $now): bool
    {
        if ($this->last_used_at !== null
            && $this->last_used_at->diffInSeconds($now) < self::USAGE_RESOLUTION_SECONDS) {
            return false;
        }

        $this->last_used_at = $now;
        $this->save();

        return true;
    }
}
