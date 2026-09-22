<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Notify\Providers;
use SrvPanel\Agent\Notify\Target;
use SrvPanel\Agent\Ops\NotifyTargetStore;
use SrvPanel\Agent\Runner;

/**
 * Die Naht zwischen Panel und Ablage — gemessen durch die Operation.
 *
 * ## Der Fund, für den es diesen Wächter gibt
 *
 * `NotifyTargetStore::execute()` rief `Target::store($url, $secret)` — **ohne
 * den Empfänger**. Er reiste vom Formular über den Socket bis hierher und
 * wurde verworfen; {@see Target::store()} fiel auf seinen Vorgabewert zurück.
 * Wer Slack wählte, bekam die JSON-Form und von Slack ein `400`. Gemessen am
 * 21. September 2026: `provider: slack` hinein, `provider: generic` abgelegt.
 *
 * > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
 * > keine.**
 *
 * ## Warum kein bestehender Wächter das sehen konnte
 *
 * {@see WebhookTransportTest} ruft `Target::store()` unmittelbar und kommt an
 * dieser Operation nie vorbei; die Seite prüft, dass sie den Empfänger
 * mitschickt. Beide Seiten der Naht waren in Ordnung.
 *
 * > **Zwei Prüfungen, die je eine Seite einer Naht mit einem selbst
 * > geschriebenen Wert füttern, prüfen die Naht nicht — sie prüfen zweimal
 * > denselben Prüfkörper.**
 */
final class NotifyTargetStoreTest extends TestCase
{
    private string $verzeichnis = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->verzeichnis = sys_get_temp_dir().'/srvpanel-notify-op-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->verzeichnis.'/*') ?: [] as $datei) {
            @unlink($datei);
        }

        @rmdir($this->verzeichnis);

        parent::tearDown();
    }

    /**
     * Der gewählte Empfänger kommt in der Ablage an.
     *
     * Gemessen in beide Richtungen: Ein genannter Empfänger wird abgelegt, und
     * ohne Angabe bleibt es bei dem, der keine Form voraussetzt.
     */
    public function test_the_chosen_receiver_reaches_the_file(): void
    {
        $target = new Target($this->verzeichnis);

        $this->fahren($target, [
            'url' => 'https://hooks.slack.com/services/x',
            'secret' => null,
            'provider' => Providers::SLACK,
        ]);

        self::assertSame(Providers::SLACK, $target->describe()['provider'] ?? null);

        $this->fahren($target, ['url' => 'https://hooks.example.org/x', 'secret' => null]);

        self::assertSame(Providers::GENERIC, $target->describe()['provider'] ?? null);
    }

    /**
     * Und die Angaben des Empfängers ebenso.
     *
     * **Gemessen am Rumpf und nicht an der Datei.** Dass der Chat abgelegt
     * wurde, sagt noch nicht, dass die Meldung ihn trägt — und genau der Weg
     * dazwischen ist der, an dem der Empfänger verlorenging.
     */
    public function test_the_settings_reach_the_message(): void
    {
        $target = new Target($this->verzeichnis);

        $this->fahren($target, [
            'url' => 'https://api.telegram.org/bot123:ABC/sendMessage',
            'secret' => null,
            'provider' => Providers::TELEGRAM,
            'config' => ['chat_id' => '-1001234567890'],
        ]);

        $gelesen = $target->read();

        self::assertSame(['chat_id' => '-1001234567890'], $gelesen['config']);

        $rumpf = json_decode(Providers::body(
            $gelesen['provider'],
            'cloudsrv24',
            date(DATE_ATOM),
            ['kind' => 'findings', 'subject' => 'x', 'findings' => [['label' => 'Ein Befund.', 'detail' => null]]],
            $gelesen['config'],
        ), true);

        self::assertIsArray($rumpf);
        self::assertSame('-1001234567890', $rumpf['chat_id'] ?? null);
    }

    /**
     * Zurück geht nur, was auch auf der Seite stehen darf.
     *
     * **Eine Positivliste und keine Aufzählung des Verbotenen.** Was hier
     * herauskommt, landet in einer Antwort der Oberfläche; ein Feld, das
     * jemand der Ablage hinzufügt, fällt so auf, statt mitzureisen.
     *
     * > **Eine Liste dessen, was nicht hinaus darf, ist beim nächsten Feld
     * > unvollständig, und niemandem fällt es auf.**
     */
    public function test_the_answer_carries_nothing_the_page_may_not_see(): void
    {
        $target = new Target($this->verzeichnis);

        $antwort = $this->fahren($target, [
            'url' => 'https://api.telegram.org/bot123:ABC/sendMessage',
            'secret' => null,
            'provider' => Providers::TELEGRAM,
            'config' => ['chat_id' => '-1001234567890'],
        ]);

        self::assertSame(['stored', 'host', 'provider', 'stored_at', 'signed'], array_keys($antwort));
        self::assertSame('api.telegram.org', $antwort['host']);

        // Und die Gegenprobe an der Zeichenkette: Weder Marke noch Chat
        // stehen irgendwo in der Antwort, auch nicht als Teil eines Feldes.
        $flach = json_encode($antwort);

        self::assertIsString($flach);
        self::assertStringNotContainsString('bot123:ABC', $flach);
        self::assertStringNotContainsString('-1001234567890', $flach);
    }

    /**
     * Die Operation fahren — mit dem Gestell, das der Agent ihr gibt.
     *
     * **Sie heisst nicht `run()`.** Der Name gehört `PHPUnit\\Framework\\TestCase`
     * und ist dort `final`; eine Kollision bricht beim **Laden** der Klasse,
     * also bevor ein einziger Test läuft. `BaseMethodClashTest` hält es, und
     * dieser Fall hat es beim ersten Aufruf vorgeführt.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function fahren(Target $target, array $args): array
    {
        $journal = new Journal('/dev/null');
        $context = new Context(new Runner($journal), $journal, static function (array $line): void {});

        return (new NotifyTargetStore($target))->execute($args, $context);
    }
}
