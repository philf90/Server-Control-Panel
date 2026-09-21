<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Der Klartext einer Zugangsmarke steht nirgends in der Ablage (B7).
 *
 * **Gemessen an der Zeile und nicht an der Absicht.** „Wir speichern nur den
 * Hash" ist ein Satz; dieser Wächter liest die Spalten und sucht den Klartext
 * darin. Die Gegenrichtung steht daneben, denn ein Hash, den niemand
 * wiederfindet, ist kein Schutz, sondern ein Ausfall.
 */
final class ApiTokenStorageTest extends TestCase
{
    use RefreshDatabase;

    private function konto(): Account
    {
        return Account::factory()->customer()->create();
    }

    public function test_the_plain_text_is_nowhere_in_the_row(): void
    {
        ['token' => $token, 'plain' => $plain] = ApiToken::mint($this->konto(), 'Abrechnung');

        /** @var object $zeile */
        $zeile = DB::table('api_tokens')->where('id', $token->id)->first();

        foreach ((array) $zeile as $spalte => $wert) {
            if (! is_string($wert)) {
                continue;
            }

            self::assertNotSame($plain, $wert, sprintf(
                'Der Klartext der Marke steht in `api_tokens.%s`.',
                $spalte,
            ));

            /*
             * **Und auch nicht als Teil.** Eine Spalte, die ihn enthält, gibt
             * ihn genauso her wie eine, die ihn ist — die Vorschau ist die
             * eine Ausnahme, und sie ist neun Zeichen lang.
             */
            if ($spalte !== 'preview') {
                self::assertStringNotContainsString(substr($plain, 0, 20), $wert, sprintf(
                    'In `api_tokens.%s` stehen die ersten zwanzig Zeichen des Klartexts.',
                    $spalte,
                ));
            }
        }

        self::assertSame(ApiToken::PREVIEW_LENGTH, strlen($zeile->preview));
    }

    public function test_the_same_plain_text_finds_its_row(): void
    {
        ['token' => $token, 'plain' => $plain] = ApiToken::mint($this->konto(), 'Abrechnung');

        $gefunden = ApiToken::query()->where('token_hash', ApiToken::hashOf($plain))->first();

        self::assertNotNull($gefunden, 'Der Hash findet seine Zeile nicht — dann kommt niemand mehr herein.');
        self::assertSame($token->id, $gefunden->id);

        /* Die Gegenprobe: ein anderer Klartext findet sie nicht. */
        self::assertNull(
            ApiToken::query()->where('token_hash', ApiToken::hashOf($plain.'x'))->first(),
        );
    }

    /**
     * Die Marke trägt ihr Präfix und ihre volle Länge.
     *
     * Das Präfix ist kein Schmuck: Es macht einen versehentlich
     * veröffentlichten Schlüssel für einen Menschen als solchen erkennbar.
     */
    public function test_a_minted_token_carries_prefix_and_length(): void
    {
        ['plain' => $plain] = ApiToken::mint($this->konto(), 'Abrechnung');

        self::assertStringStartsWith(ApiToken::PREFIX, $plain);
        self::assertSame(
            strlen(ApiToken::PREFIX) + ApiToken::RANDOM_BYTES * 2,
            strlen($plain),
        );

        /* Zwei Marken sind nicht dieselbe. */
        ['plain' => $zweite] = ApiToken::mint($this->konto(), 'Abrechnung');
        self::assertNotSame($plain, $zweite);
    }

    /**
     * `last_used_at` wird höchstens einmal je Minute geschrieben.
     *
     * **Beide Richtungen**, denn ein Feld, das nie geschrieben wird,
     * beantwortet „wird diese Marke noch benutzt?" genauso wenig wie eines,
     * das bei jeder Anfrage schreibt — nur fällt das erste nicht auf.
     */
    public function test_usage_is_noted_at_most_once_a_minute(): void
    {
        ['token' => $token] = ApiToken::mint($this->konto(), 'Abrechnung');

        $jetzt = Carbon::parse('2026-09-23 10:00:00');

        self::assertTrue($token->noteUsage($jetzt), 'Der erste Gebrauch wird nicht vermerkt.');
        self::assertFalse($token->noteUsage($jetzt->copy()->addSeconds(30)));
        self::assertTrue($token->noteUsage($jetzt->copy()->addSeconds(61)));
    }
}
