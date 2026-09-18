<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Plans\Quota;
use App\Support\Plans\Quotas;
use PHPUnit\Framework\TestCase;

/**
 * Die Anzeige sagt „unbegrenzt" nur, wo ein Kontingent das sein darf.
 *
 * ## Warum es diesen Wächter gibt
 *
 * **Befund D des Nachlaufs zu `0.7.4-rc.16`** (`docs/123 §9`), gesehen am
 * 18. September 2026 auf `cloudsrv24`: Auf der Abonnementseite stand
 * **„Aufbewahrte Sicherungen — unbegrenzt"**. {@see Quota::Backups} darf das
 * nicht sein — `allowsUnlimited()` ist `false`, `minimum` 1, `default` 3.
 *
 * {@see Quotas::format()} machte aus **jedem** `null` ein „unbegrenzt", ohne zu
 * fragen. Der Zustand entsteht bei einem Plan, der älter ist als das Kontingent
 * und seitdem nie gespeichert wurde — {@see Quotas::normalize()} füllt sonst
 * jeden Schlüssel.
 *
 * **Und die Unterscheidung stand zweihundert Zeilen darüber schon
 * geschrieben**, im Kopf von {@see Quotas::overrideRules()}.
 *
 * > **Ein Fehler, den man an einer Stelle vermieden hat, ist an der nächsten
 * > wieder da, wenn die Vermeidung nicht die Regel wurde.**
 *
 * ## Warum an der Wirkung und nicht am Quelltext
 *
 * Gefragt wird {@see Quotas::format()} selbst, je Kontingent, mit `null`. Ein
 * Ausdruck über die Zeile `return 'unbegrenzt'` bliebe grün, sobald jemand das
 * Wort in eine Konstante zieht oder die Verzweigung umbaut.
 */
final class QuotaDisplayTest extends TestCase
{
    /**
     * Die Untergrenze — und sie ist hier der Prüfkörper.
     *
     * **Gäbe es kein Kontingent, das nicht unbegrenzt sein darf**, liefe die
     * Regel durch jede Schleife, ohne je etwas zu prüfen, und ein grüner Lauf
     * sagte nichts. Gemessen sind es zwei.
     */
    private const MINDESTENS_BESCHRAENKT = 1;

    public function test_unlimited_is_only_said_where_it_is_allowed(): void
    {
        $beschraenkt = 0;
        $falsch = [];

        foreach (Quota::cases() as $quota) {
            if ($quota->isSelection() || $quota->allowsUnlimited()) {
                continue;
            }

            $beschraenkt++;

            if (Quotas::format($quota, null) === 'unbegrenzt') {
                $falsch[] = $quota->value;
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINDESTENS_BESCHRAENKT,
            $beschraenkt,
            'Kein Kontingent ist beschränkt — die Regel hat nichts zu prüfen.'
        );

        $this->assertSame(
            [],
            $falsch,
            "Diese Kontingente dürfen nicht unbegrenzt sein, und die Anzeige sagt es trotzdem:\n  "
            .implode("\n  ", $falsch)
        );
    }

    /**
     * Und die Gegenrichtung: Wo „unbegrenzt" erlaubt ist, steht es auch.
     *
     * **Ohne sie wäre der Wächter mit einer Anzeige zufrieden, die das Wort
     * nirgends mehr sagt** — also mit einer Behebung, die den Fehler durch
     * einen zweiten ersetzt.
     */
    public function test_unlimited_is_still_said_where_it_is_allowed(): void
    {
        $erlaubt = 0;
        $fehlt = [];

        foreach (Quota::cases() as $quota) {
            if ($quota->isSelection() || ! $quota->allowsUnlimited()) {
                continue;
            }

            $erlaubt++;

            if (Quotas::format($quota, null) !== 'unbegrenzt') {
                $fehlt[] = $quota->value;
            }
        }

        $this->assertGreaterThanOrEqual(
            1,
            $erlaubt,
            'Kein Kontingent darf unbegrenzt sein — die Gegenrichtung hat nichts zu prüfen.'
        );

        $this->assertSame([], $fehlt, 'Hier fehlt das Wort „unbegrenzt", obwohl es erlaubt ist.');
    }
}
