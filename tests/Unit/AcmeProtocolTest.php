<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Account;
use SrvPanel\Agent\Acme\HttpChallenge;
use SrvPanel\Agent\Acme\Jws;
use SrvPanel\Agent\Acme\Order;
use SrvPanel\Agent\Acme\Problem;
use SrvPanel\Agent\Acme\ResponseBuffer;
use SrvPanel\Agent\Acme\Session;
use SrvPanel\Agent\AgentException;
use Tests\Support\ScriptedTransport;

/**
 * Der ACME-Client — gegen den Standard geprüft, nicht gegen sich selbst.
 *
 * **Warum dieser Wächter so aussieht.** Der Client ist der erste Code in diesem
 * Projekt, der ein fremdes Protokoll spricht. Ein Fehler darin meldet sich
 * nicht als Ausnahme, sondern als „unauthorized" oder „malformed" von der
 * Gegenseite — auf einem echten Server, nach einer Ratenbegrenzung, die fünf
 * Fehlversuche je Konto und Stunde zulässt. Genau die Sorte Rückmeldung, die
 * eine Fehlersuche teuer macht.
 *
 * Deshalb zwei Arten von Prüfung:
 *
 * 1. **Gegen den Testvektor aus RFC 7638.** Der Fingerabdruck des
 *    Kontoschlüssels ist die halbe Schlüsselautorisierung; stimmt er nicht,
 *    scheitert jede Prüfung mit einer Meldung, in der nichts davon steht. Der
 *    RFC liefert ein Beispiel, und daran wird gemessen — dieselbe Überlegung
 *    wie bei TOTP, wo die Umsetzung ohne Bibliothek nur deshalb zu
 *    verantworten ist.
 * 2. **Gegen ein Drehbuch** ({@see ScriptedTransport}) für alles, was Ablauf
 *    ist: die Reihenfolge der Anfragen, der verbrauchte Einmalwert, der leere
 *    Rumpf, das Abräumen nach einem Fehlschlag.
 *
 * Was hier **nicht** geprüft wird, und das mit Absicht: ob Let's Encrypt sich
 * so verhält wie das Drehbuch. Das beantwortet nur ein Lauf gegen den
 * Testbetrieb auf einem echten Server, und der steht im Abnahmekriterium.
 */
final class AcmeProtocolTest extends TestCase
{
    private const DIRECTORY_URL = 'https://acme.example/directory';

    private const NONCE_URL = 'https://acme.example/nonce';

    private const ACCOUNT_URL = 'https://acme.example/new-account';

    private const ORDER_URL = 'https://acme.example/new-order';

    private const AUTHZ_URL = 'https://acme.example/authz/1';

    private const CHALLENGE_URL = 'https://acme.example/challenge/1';

    private const FINALIZE_URL = 'https://acme.example/finalize/1';

    private const PLACED_URL = 'https://acme.example/order/1';

    private const CERTIFICATE_URL = 'https://acme.example/cert/1';

    private const TOKEN = 'GhFq1x8LwUvTnZ2mQ7cRb0Ay';

    private string $accountRoot;

    private string $challengeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountRoot = sys_get_temp_dir().'/srvpanel-acme-'.bin2hex(random_bytes(6));
        $this->challengeRoot = sys_get_temp_dir().'/srvpanel-challenge-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ([$this->accountRoot, $this->challengeRoot] as $directory) {
            $this->removeTree($directory);
        }

        parent::tearDown();
    }

    /**
     * Der Testvektor aus RFC 7638, Abschnitt 3.1.
     *
     * Ändert jemand die Reihenfolge der drei Felder im JWK oder lässt ein
     * Leerzeichen ins JSON, ist dieser Wert ein anderer — und die
     * Schlüsselautorisierung damit falsch, ohne dass irgendetwas abbricht.
     */
    public function test_the_thumbprint_matches_the_vector_from_rfc_7638(): void
    {
        $jwk = [
            'e' => 'AQAB',
            'kty' => 'RSA',
            'n' => '0vx7agoebGcQSuuPiLJXZptN9nndrQmbXEps2aiAFbWhM78LhWx4'.
                   'cbbfAAtVT86zwu1RK7aPFFxuhDR1L6tSoc_BJECPebWKRXjBZCiF'.
                   'V4n3oknjhMstn64tZ_2W-5JsGY4Hc5n9yBXArwl93lqt7_RN5w6C'.
                   'f0h4QyQ5v-65YGjQR0_FDW2QvzqY368QQMicAtaSqzs8KJZgnYb9'.
                   'c7d0zgdAZHzu6qMQvRL5hajrn1n91CbOpbISD08qNLyrdkt-bFTW'.
                   'hAI4vMQFh6WeZu0fM4lFd2NcRwr3XPksINHaQ-G_xBniIqbw0Ls1'.
                   'jF44-csFCur-kEgU8awapJzKnqDKgw',
        ];

        $this->assertSame('NzbLsXh8uDCcd-6MNwXF4W_7noWXFZAfHkxZsRGC9Xs', Jws::thumbprintOf($jwk));
    }

    /**
     * Und der Vektor allein genügt nicht.
     *
     * Er prüft {@see Jws::thumbprintOf()} mit einem JWK aus dem RFC — also mit
     * einer Reihenfolge, die der Test selbst mitbringt. Wer die Felder in
     * {@see Jws::jwk()} umstellt, kommt daran vorbei: Der Fingerabdruck wäre
     * dann falsch, und der Vektor bliebe grün. Deshalb diese zweite Prüfung, an
     * dem Ort, an dem die Reihenfolge tatsächlich entsteht.
     */
    public function test_the_jwk_carries_its_fields_in_the_order_rfc_7638_demands(): void
    {
        $jws = new Jws((new Account(self::DIRECTORY_URL, $this->accountRoot))->key());

        $this->assertSame(['e', 'kty', 'n'], array_keys($jws->jwk()));
    }

    public function test_base64url_leaves_no_padding_and_no_slash(): void
    {
        // Bytes, die in base64 sowohl `+` als auch `/` erzeugen.
        $encoded = Jws::base64url("\xfb\xff\xfe\x00\x01");

        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertSame("\xfb\xff\xfe\x00\x01", base64_decode(strtr($encoded, '-_', '+/'), true));
    }

    /**
     * Der leere Rumpf ist `{}` und nicht `[]`.
     *
     * Er kommt bei jeder angestossenen Prüfung vor. `json_encode([])` schreibt
     * `[]`, und die Antwort darauf ist „malformed" — auf dem echten Server, denn
     * ein Drehbuch antwortet ja trotzdem.
     */
    public function test_an_empty_payload_is_an_object_and_not_a_list(): void
    {
        $transport = $this->script();
        $session = $this->session($transport);

        $session->post(self::CHALLENGE_URL, []);

        $bodies = $transport->bodiesFor(self::CHALLENGE_URL);

        $this->assertCount(1, $bodies);
        $this->assertSame('{}', ScriptedTransport::payloadOf($bodies[0]));
    }

    public function test_post_as_get_carries_an_empty_payload(): void
    {
        $transport = $this->script();
        $session = $this->session($transport);

        $session->postAsGet(self::AUTHZ_URL);

        $bodies = $transport->bodiesFor(self::AUTHZ_URL);

        $this->assertSame('', ScriptedTransport::payloadOf($bodies[0]));
    }

    /**
     * Ein verbrauchter Einmalwert wird genau einmal wiederholt.
     *
     * Und die Wiederholung nimmt den frischen Wert aus der Fehlerantwort — ihn
     * dort wegzuwerfen und neu zu holen wäre eine Anfrage mehr je Fehlschlag.
     */
    public function test_a_used_nonce_is_retried_exactly_once(): void
    {
        $transport = $this->script();
        $transport->on(
            self::ORDER_URL,
            ScriptedTransport::problem(Problem::PREFIX.'badNonce'),
            ScriptedTransport::json(['status' => 'pending'], 201, self::PLACED_URL),
        );

        $session = $this->session($transport);
        $session->post(self::ORDER_URL, ['identifiers' => []]);

        $bodies = $transport->bodiesFor(self::ORDER_URL);

        $this->assertCount(2, $bodies, 'Es wurde nicht genau einmal wiederholt.');
        $this->assertSame('nonce-frisch', ScriptedTransport::headerOf($bodies[1])['nonce']);
    }

    /**
     * Bleibt der Fehler, wird nicht weiter wiederholt.
     *
     * Eine Schleife wäre hier der kürzeste Weg in die Ratenbegrenzung, und die
     * hält Stunden.
     */
    public function test_a_nonce_that_stays_bad_is_not_retried_forever(): void
    {
        $transport = $this->script();
        $transport->on(self::ORDER_URL, ScriptedTransport::problem(Problem::PREFIX.'badNonce'));

        $session = $this->session($transport);

        $this->expectException(AgentException::class);

        try {
            $session->post(self::ORDER_URL, []);
        } finally {
            $this->assertCount(2, $transport->bodiesFor(self::ORDER_URL));
        }
    }

    /** Der ganze Ablauf, von den Namen bis zur Kette. */
    public function test_the_order_runs_from_the_names_to_a_certificate(): void
    {
        $transport = $this->scriptForOrder();
        $order = new Order($this->session($transport), $this->challenge(), pollSeconds: 0, timeoutSeconds: 5);

        $result = $order->issue(['example.de']);

        $this->assertStringContainsString('-----BEGIN CERTIFICATE-----', $result['certificate']);
        $this->assertStringContainsString('PRIVATE KEY-----', $result['key']);

        // Die Anforderung geht als base64url über DER hinaus — nicht als PEM.
        // Geprüft wird der Rumpf im Wortlaut: Ein zweites Feld darin wäre
        // ebenso ein Fehler wie eine Kopfzeile im Wert.
        $finalize = ScriptedTransport::payloadOf($transport->bodiesFor(self::FINALIZE_URL)[0]);

        $this->assertMatchesRegularExpression('/^\{"csr":"[A-Za-z0-9_-]+"\}$/D', $finalize);
    }

    /**
     * Nach dem Erfolg liegt keine Prüfdatei mehr herum.
     *
     * Beim zweiten Anlauf mit demselben Namen stünde dort sonst ein Wert von
     * gestern, und die Prüfung scheiterte an einer Ursache, die nirgends steht.
     */
    public function test_the_challenge_file_is_cleared_after_a_successful_order(): void
    {
        $transport = $this->scriptForOrder();
        $order = new Order($this->session($transport), $this->challenge(), pollSeconds: 0, timeoutSeconds: 5);

        $order->issue(['example.de']);

        $this->assertFileDoesNotExist($this->challengeFile());
    }

    /** Und nach einem Fehlschlag erst recht — dafür steht das `finally`. */
    public function test_the_challenge_file_is_cleared_after_a_failed_order(): void
    {
        $transport = $this->scriptForOrder();
        $transport->on(self::FINALIZE_URL, ScriptedTransport::problem(Problem::PREFIX.'orderNotReady'));

        $order = new Order($this->session($transport), $this->challenge(), pollSeconds: 0, timeoutSeconds: 5);

        try {
            $order->issue(['example.de']);
            $this->fail('Die Bestellung hätte scheitern müssen.');
        } catch (AgentException) {
            $this->assertFileDoesNotExist($this->challengeFile());
        }
    }

    /** Die Prüfdatei liegt dort, wo der Server-Block sie sucht. */
    public function test_the_challenge_file_lands_where_nginx_looks_for_it(): void
    {
        $challenge = $this->challenge();
        $challenge->present('example.de', self::TOKEN, self::TOKEN.'.fingerabdruck');

        $this->assertFileExists($this->challengeFile());
        $this->assertSame(self::TOKEN.'.fingerabdruck', file_get_contents($this->challengeFile()));

        // `root` hängt den ganzen Pfad aus der Adresse an — deshalb steht
        // `.well-known/acme-challenge` im Ablageort und nicht nur in der URL.
        $this->assertStringEndsWith('/.well-known/acme-challenge/'.self::TOKEN, $this->challengeFile());
    }

    /**
     * Ein Token, der ein Pfad wäre, wird nie zu einem Dateinamen.
     *
     * Der Token kommt von aussen und landet in einem `file_put_contents`, das
     * als root läuft. Dass die Gegenstelle vertrauenswürdig ist, ist eine
     * Annahme über heute.
     */
    public function test_a_token_that_is_a_path_never_becomes_a_filename(): void
    {
        $challenge = $this->challenge();

        foreach (['../../etc/passwd', 'mit/schrägstrich', 'kurz', 'punkt.punkt'] as $token) {
            try {
                $challenge->present('example.de', $token, 'egal');
                $this->fail(sprintf('Der Token „%s" wurde angenommen.', $token));
            } catch (AgentException $error) {
                $this->assertSame(AgentException::BAD_REQUEST, $error->errorCode);
            }
        }
    }

    /**
     * Der Deckel greift beim Schreiben und nicht danach.
     *
     * **Er war bis eben eine Zusage ohne Wächter.** Die Regel stand als
     * Bedingung mitten in der Konfigurationsablage von curl und liess sich nur
     * mit einer Gegenstelle befragen, die zuviel schickt — also gar nicht. Erst
     * seit sie in {@see ResponseBuffer} steht, gibt es etwas zu prüfen.
     */
    public function test_the_response_buffer_stops_at_its_limit(): void
    {
        $buffer = new ResponseBuffer(10);

        $this->assertSame(6, $buffer->write('123456'));
        $this->assertFalse($buffer->truncated());

        // Eine andere Zahl als die übergebene Länge bricht die Übertragung ab.
        $this->assertSame(0, $buffer->write('789012'));
        $this->assertTrue($buffer->truncated());
        $this->assertSame('123456', $buffer->response(200)->body);
    }

    /** Ein Replay-Nonce, den man unter `replay-nonce` sucht, ist einer, den man nicht findet. */
    public function test_the_response_buffer_lowercases_header_names(): void
    {
        $buffer = new ResponseBuffer(100);
        $buffer->header("Replay-Nonce: abc\r\n");
        $buffer->header("HTTP/2 200\r\n");

        $response = $buffer->response(200);

        $this->assertSame('abc', $response->header('Replay-Nonce'));

        // Die Statuszeile trägt keinen Namen mit Doppelpunkt und fällt heraus.
        $this->assertSame(['replay-nonce' => 'abc'], $response->headers);
    }

    private function challenge(): HttpChallenge
    {
        return new HttpChallenge($this->challengeRoot);
    }

    private function challengeFile(): string
    {
        return $this->challengeRoot.HttpChallenge::PREFIX.'/'.self::TOKEN;
    }

    /** Verzeichnis, Einmalwert und Konto — das Gerüst, das jeder Test braucht. */
    private function script(): ScriptedTransport
    {
        $transport = new ScriptedTransport;

        return $transport
            ->on(self::DIRECTORY_URL, ScriptedTransport::json([
                'newNonce' => self::NONCE_URL,
                'newAccount' => self::ACCOUNT_URL,
                'newOrder' => self::ORDER_URL,
                'meta' => ['termsOfService' => 'https://acme.example/bedingungen'],
            ]))
            ->on(self::NONCE_URL, ScriptedTransport::json([], 204))
            ->on(self::ACCOUNT_URL, ScriptedTransport::json(['status' => 'valid'], 201, 'https://acme.example/acct/1'))
            ->on(self::CHALLENGE_URL, ScriptedTransport::json([]))
            ->on(self::AUTHZ_URL, ScriptedTransport::json(['status' => 'valid']));
    }

    private function scriptForOrder(): ScriptedTransport
    {
        $pending = ScriptedTransport::json([
            'status' => 'pending',
            'identifier' => ['type' => 'dns', 'value' => 'example.de'],
            'challenges' => [
                ['type' => 'dns-01', 'token' => self::TOKEN, 'url' => 'https://acme.example/challenge/dns'],
                ['type' => 'http-01', 'token' => self::TOKEN, 'url' => self::CHALLENGE_URL],
            ],
        ]);

        return $this->script()
            ->on(self::ORDER_URL, ScriptedTransport::json([
                'status' => 'pending',
                'authorizations' => [self::AUTHZ_URL],
                'finalize' => self::FINALIZE_URL,
            ], 201, self::PLACED_URL))
            ->on(self::AUTHZ_URL, $pending, ScriptedTransport::json([
                'status' => 'valid',
                'identifier' => ['type' => 'dns', 'value' => 'example.de'],
            ]))
            ->on(self::FINALIZE_URL, ScriptedTransport::json(['status' => 'processing']))
            ->on(self::PLACED_URL, ScriptedTransport::json([
                'status' => 'valid',
                'certificate' => self::CERTIFICATE_URL,
            ]))
            ->on(self::CERTIFICATE_URL, ScriptedTransport::text(
                "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
            ));
    }

    private function session(ScriptedTransport $transport): Session
    {
        $session = Session::open($transport, self::DIRECTORY_URL, new Account(self::DIRECTORY_URL, $this->accountRoot));
        $session->register('post@example.de');

        return $session;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;

            if (is_dir($child)) {
                $this->removeTree($child);

                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
