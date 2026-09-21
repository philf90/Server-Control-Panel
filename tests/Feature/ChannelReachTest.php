<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Notify\Channel;
use App\Support\Notify\Channels;
use App\Support\Notify\NotifyTarget;
use Tests\Support\ScriptedNotifyTarget;
use Tests\TestCase;

/**
 * Jeder Kanal, den die Seite anbietet, hat eine Umsetzung — und umgekehrt (B1).
 *
 * ## Warum in beide Richtungen
 *
 * Die erste Richtung fängt den Kanal, den jemand auf die Seite schreibt, ohne
 * ihn zu bauen — der fällt beim ersten Blick auf. Die zweite fängt den, der
 * gebaut ist und den die Seite nicht anbietet, und **so entsteht ein toter
 * Eintrag wirklich**: Jemand baut einen Kanal, der Nachtlauf bedient ihn, und
 * der Betreiber erfährt nie, dass es ihn gibt — die Seite, auf der
 * „zuletzt erfolgreich zugestellt" steht, kennt ihn nicht.
 *
 * > **Ein Wächter, der eine Richtung prüft, hat über die andere nichts gesagt —
 * > und welche der beiden fehlt, sieht man erst, wenn man sie braucht.**
 *
 * ## Was er nicht kann
 *
 * Ob die **Beschriftung** stimmt — ob „Meldeziel (Webhook)" das Wort ist, unter
 * dem jemand diesen Kanal sucht — hängt daran, was ein Betrachter erwartet, und
 * keine Eigenschaft des Quelltextes bildet es ab. Das steht als Frage hier und
 * nicht als Zusage.
 */
final class ChannelReachTest extends TestCase
{
    private const SEITE = 'resources/js/Pages/Settings/Notices.vue';

    /** Ohne Doppel fragte der Webhook-Kanal beim Bauen den echten Agenten. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app?->instance(NotifyTarget::class, new ScriptedNotifyTarget);
    }

    public function test_every_channel_on_the_page_has_an_implementation(): void
    {
        $gebaut = $this->gebaut();
        $fehlend = [];

        foreach ($this->aufDerSeite() as $key) {
            if (! in_array($key, $gebaut, true)) {
                $fehlend[] = $key;
            }
        }

        self::assertSame([], $fehlend, sprintf(
            "Diese Kanäle stehen auf %s und es gibt sie nicht:\n  %s",
            self::SEITE,
            implode("\n  ", $fehlend),
        ));
    }

    public function test_every_implementation_stands_on_the_page(): void
    {
        $aufDerSeite = $this->aufDerSeite();
        $fehlend = [];

        foreach ($this->gebaut() as $key) {
            if (! in_array($key, $aufDerSeite, true)) {
                $fehlend[] = $key;
            }
        }

        self::assertSame([], $fehlend, sprintf(
            "Diese Kanäle gibt es, und %s bietet sie nicht an:\n  %s\n\n".
            'Ein Kanal, den die Seite nicht kennt, meldet — und niemand sieht, ob er ankommt.',
            self::SEITE,
            implode("\n  ", $fehlend),
        ));
    }

    /**
     * Ohne diese Zahlen wären beide Richtungen auch dann grün, wenn keiner der
     * beiden Ausdrücke etwas fände.
     *
     * > **Eine Untergrenze ist kein Formalismus — sie ist die einzige Stelle,
     * > an der ein Wächter merkt, dass sein Ausdruck ins Leere greift.**
     */
    public function test_the_comparison_has_something_to_compare(): void
    {
        self::assertGreaterThanOrEqual(2, count($this->gebaut()), 'Es gibt seit B1 zwei Kanäle.');
        self::assertGreaterThanOrEqual(2, count($this->aufDerSeite()));
        self::assertContains('mail', $this->gebaut());
        self::assertContains('webhook', $this->gebaut());
    }

    /**
     * Ein Schlüssel ist ein Bezeichner und damit englisch (`docs/19 §4a`).
     *
     * Er steht in `finding_notifications.channel` und in den Einstellungen;
     * ein deutsches Wort dort wäre eine Beschriftung in einer Ablage, und die
     * Beschriftung steht auf der Seite.
     */
    public function test_a_key_is_an_identifier_and_not_a_label(): void
    {
        foreach ($this->gebaut() as $key) {
            self::assertSame(1, preg_match('/\A[a-z][a-z0-9_]*\z/D', $key), $key.' ist kein Bezeichner.');
        }
    }

    /**
     * Die gebauten Kanäle — aus {@see Channels} und nicht aus einer Liste hier.
     *
     * @return list<string>
     */
    private function gebaut(): array
    {
        return array_map(
            static fn (Channel $c): string => $c->key(),
            app(Channels::class)->all(),
        );
    }

    /**
     * Die Kanäle, die die Seite anbietet.
     *
     * **Gelesen wird der Ablageblock und nicht die ganze Datei.** Ein Ausdruck
     * über `mail:` fände auch `autocomplete` oder einen Kommentar; hier wird
     * der Block `KANAELE = { … }` ausgeschnitten und darin je Zeile der
     * Schlüssel gelesen.
     *
     * @return list<string>
     */
    private function aufDerSeite(): array
    {
        $quelle = file_get_contents(dirname(__DIR__, 2).'/'.self::SEITE);

        self::assertIsString($quelle, self::SEITE.' gibt es nicht.');

        $anfang = strpos($quelle, 'const KANAELE');

        self::assertIsInt($anfang, 'Die Ablage der Kanäle heisst nicht mehr KANAELE — dann prüft dieser Wächter nichts.');

        $ende = strpos($quelle, "\n}", $anfang);

        self::assertIsInt($ende);

        preg_match_all('/^  ([a-z][a-z0-9_]*): \{/m', substr($quelle, $anfang, $ende - $anfang), $treffer);

        return $treffer[1];
    }
}
