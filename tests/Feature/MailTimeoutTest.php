<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Settings\MailConfiguration;
use App\Support\Settings\MailSettings;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Mailweg hat eine Zeitgrenze, und sie gilt auch am Ende — B5,
 * `docs/129 §8`.
 *
 * ## Warum es diese Regel gibt
 *
 * Gemessen (`docs/128` M6): Ohne `timeout` kostet ein toter Empfänger
 * **60,02 s** je Versand. Bei 400 fälligen Meldungen sind das 6,7 Stunden, in
 * denen ein Nachtlauf an einem Relay hängt, das nicht antwortet — und der
 * Zeitgeber feuert derweil den nächsten.
 *
 * > **Eine Grenze, die auf `null` steht, ist keine Voreinstellung — sie ist die
 * > Abwesenheit einer Entscheidung.**
 *
 * ## Gemessen wird der aufgelöste Wert und nicht die Zeile
 *
 * `config/mail.php` ist nur die Voreinstellung.
 * {@see MailConfiguration::apply()} schreibt bei jedem Versand die
 * eingetragenen Einstellungen über den Mailer — und was **nicht** dort steht,
 * bleibt stehen. Ein Wächter über die Zeile in `config/mail.php` sagte
 * deshalb nichts darüber, was am Ende gilt: Er bliebe grün, wenn jemand
 * `apply()` um ein `timeout => null` ergänzte.
 *
 * > **Ein Wächter über den Quelltext sagt, dass die Teile zusammenpassen,
 * > nicht dass sie zusammen etwas tun.**
 */
final class MailTimeoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die Zahl steht hier **nicht** — sie wird gegen die Voreinstellung
     * gemessen. Zwei Fassungen derselben Zahl liefen sonst auseinander, und
     * die im Test wäre die, die niemand nachzieht.
     */
    private function wanted(): int
    {
        $wert = config('mail.mailers.smtp.timeout');

        self::assertIsInt($wert, 'Ohne eine Zahl in der Voreinstellung misst dieser Wächter nichts.');
        self::assertGreaterThan(0, $wert);

        return $wert;
    }

    public function test_the_default_carries_a_timeout(): void
    {
        self::assertSame(10, $this->wanted(),
            'Zehn Sekunden, dieselbe Zahl wie `Acme\\Curl::CONNECT_TIMEOUT`. Wer sie ändert, '
            .'ändert sie hier mit — und liest dabei, woher sie kommt.');
    }

    /** Und sie überlebt, was das Panel beim Versand darüberschreibt. */
    public function test_the_applied_configuration_keeps_it(): void
    {
        $erwartet = $this->wanted();

        app(Settings::class)->saveMail(new MailSettings(
            host: 'relay.example.org',
            port: 587,
            encryption: 'tls',
            username: '',
            password: '',
            from_address: 'panel@example.org',
            from_name: 'SrvPanel',
        ));

        self::assertTrue(
            MailConfiguration::apply(app(Settings::class), config()),
            'Ohne ein benutzbares Relay schreibt `apply()` gar nichts — dann misst der Fall daneben '
            .'die Voreinstellung ein zweites Mal.',
        );

        self::assertSame($erwartet, config('mail.mailers.smtp.timeout'),
            'Was `apply()` nicht anfasst, bleibt stehen. Setzte es die Zeitgrenze zurück, hinge jeder '
            .'Versand wieder 60 s an einem toten Relay.');
    }
}
