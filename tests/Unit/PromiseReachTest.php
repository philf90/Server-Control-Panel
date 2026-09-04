<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\Acme\Trust;
use SrvPanel\Agent\Diagnose\Statements;
use SrvPanel\Agent\PoolTemplate;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;

/**
 * Die Zusage einer Vorlage ist genau das, was jede ihrer Formen ausgibt.
 *
 * ## Warum in beide Richtungen
 *
 * **Zu gross**, und die Diagnose meldet jede Nacht jede heile Domain — die
 * Falle aus `docs/98 §4`, die den Lauf in zwei Wochen unlesbar macht. **Zu
 * klein**, und ein Verlust bleibt stumm, weil niemand ihn zugesagt hat. Deshalb
 * wird die Liste nicht gegen „enthält" gehalten, sondern gegen die
 * **Schnittmenge** aller Formen: Was in jeder Form steht, ist zugesagt — nicht
 * mehr und nicht weniger. Wer der Vorlage eine Anweisung gibt, die überall
 * steht, trägt sie in `PROMISED` nach oder erklärt, warum nicht.
 *
 * > **Eine Zusage, die kleiner ist als die Vorlage, meldet nichts; eine, die
 * > grösser ist, meldet alles.**
 *
 * Die Formen sind die aus `SiteTemplateTest`: ausliefernd mit PHP, ohne PHP,
 * gesperrt, weiterleitend — und mit Zertifikat, weil der 443er Block ein
 * eigener `server` ist.
 */
final class PromiseReachTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            foreach (glob($this->root.'/*/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->root.'/*') ?: [] as $directory) {
                @rmdir($directory);
            }
            @rmdir($this->root);
            $this->root = null;
        }

        parent::tearDown();
    }

    /**
     * Die Grundangaben einer Domain — alles ausser der Form.
     *
     * @return array<string, mixed>
     */
    private function basis(): array
    {
        return [
            'subscription' => 'beispiel.de',
            'user' => 'p1001',
            'domain' => 'beispiel.de',
            'document_root' => 'httpdocs',
            'php_version' => '8.4',
            'certificate' => null,
        ];
    }

    /**
     * Die Argumente je Form, mit den Schlüsseln der Vorlage.
     *
     * @return array<string, array<string, mixed>>
     */
    private function formArgs(): array
    {
        $basis = $this->basis();

        return [
            SiteTemplate::FORM_PHP => $basis,
            SiteTemplate::FORM_STATIC => ['php_version' => null] + $basis,
            SiteTemplate::FORM_SUSPENDED => ['suspended' => true] + $basis,
            SiteTemplate::FORM_REDIRECT => ['redirect_target' => 'https://ziel.de/'] + $basis,
        ];
    }

    /** Ein Ablageort mit einem selbstsignierten Zertifikat — HSTS bleibt damit aus. */
    private function store(string $pem = "-----BEGIN-----\n"): Store
    {
        $this->root ??= sys_get_temp_dir().'/srvpanel-promise-'.bin2hex(random_bytes(6));

        if (! is_dir($this->root.'/beispiel.de')) {
            mkdir($this->root.'/beispiel.de', 0o750, true);
        }

        file_put_contents($this->root.'/beispiel.de/fullchain.pem', $pem);
        file_put_contents($this->root.'/beispiel.de/privkey.pem', "-----BEGIN-----\n");

        return new Store($this->root);
    }

    /** @return array<string, string> je Form der gerenderte Text, ohne Zertifikat */
    private function forms(): array
    {
        $rendered = [];

        foreach ($this->formArgs() as $form => $args) {
            $rendered[$form] = SiteTemplate::render(Site::fromArgs($args));
        }

        $rendered['mit Zertifikat'] = SiteTemplate::render(
            Site::fromArgs(['certificate' => 'beispiel.de'] + $this->basis()),
            $this->store(),
        );

        return $rendered;
    }

    /** @return list<string> */
    private function heads(string $rendered): array
    {
        return Statements::heads($rendered);
    }

    public function test_the_site_promise_is_exactly_what_every_form_emits(): void
    {
        $forms = $this->forms();
        $this->assertGreaterThanOrEqual(5, count($forms));

        $common = null;

        foreach ($forms as $name => $rendered) {
            $heads = $this->heads($rendered);
            $this->assertNotSame([], $heads, $name.': keine Anweisung gefunden — der Schnitt misst nichts.');
            $common = $common === null ? $heads : array_values(array_intersect($common, $heads));
        }

        $promised = SiteTemplate::PROMISED;
        sort($promised);
        $common = $common ?? [];
        sort($common);

        $this->assertSame($common, $promised, sprintf(
            "SiteTemplate::PROMISED ist nicht die Schnittmenge aller Formen.\n  zugesagt, aber nicht überall: %s\n  überall, aber nicht zugesagt: %s",
            implode(', ', array_diff($promised, $common)) ?: '–',
            implode(', ', array_diff($common, $promised)) ?: '–',
        ));
    }

    /**
     * Jede Form sagt genau zu, was sie schreibt — beide Richtungen.
     *
     * ## Der Fehler, den es dafür gebraucht hat
     *
     * Bis zum 3. September 2026 gab es nur die Schnittmenge, und im
     * Abnahmelauf ist der Preis fällig geworden (`docs/99 §5`): Von den
     * fünfundzwanzig Anweisungen einer PHP-Domain deckte sie **elf**, und die
     * einzige Stelle, an der `nginx -t` ein fehlendes Semikolon still
     * durchlässt, kostete eine der vierzehn anderen.
     *
     * > **Eine Zusage über neun Anweisungen sagt über die siebzehn daneben
     * > nichts.**
     *
     * **Zu gross ist genauso falsch wie zu klein**, und das ist die Richtung,
     * die diesen Wächter überhaupt nötig macht: Eine Weiterleitungsdomain hat
     * kein `index` und kein `fastcgi_pass`. Stünden sie in ihrer Zusage,
     * meldete der Nachtlauf ab morgen jede heile Weiterleitung als kaputt.
     */
    public function test_every_form_promises_exactly_what_it_emits(): void
    {
        // Als Menge und nicht als Reihenfolge: Welche Form zuerst steht,
        // entscheidet `formOf()` und nicht diese Liste.
        $formen = array_keys($this->formArgs());
        $schluessel = array_keys(SiteTemplate::PROMISED_BY_FORM);
        sort($formen);
        sort($schluessel);

        $this->assertSame(
            $formen,
            $schluessel,
            'Die Formen der Vorlage und die Schlüssel der Zusage sind nicht dieselben.',
        );

        foreach ($this->formArgs() as $form => $args) {
            $heads = $this->heads(SiteTemplate::render(Site::fromArgs($args)));

            // **Die Untergrenze je Form.** Eine Form, die nichts rendert,
            // wäre mit einer leeren Zusage einig — und beides falsch.
            $this->assertGreaterThanOrEqual(9, count($heads), $form.': so wenige Anweisungen kann diese Form nicht haben.');

            $promised = SiteTemplate::PROMISED_BY_FORM[$form];
            sort($promised);

            $this->assertSame($heads, $promised, sprintf(
                "Die Zusage der Form %s stimmt nicht mit ihrem Rendering.\n  zugesagt, nicht geschrieben: %s\n  geschrieben, nicht zugesagt: %s",
                $form,
                implode(', ', array_diff($promised, $heads)) ?: '–',
                implode(', ', array_diff($heads, $promised)) ?: '–',
            ));
        }
    }

    /**
     * Ein Zertifikat legt vier Anweisungen dazu und nimmt keine weg.
     *
     * Gemessen an **jeder** Form: Port 80 gibt seinen Inhalt an den
     * gesicherten Block ab und bekommt {@see SiteTemplate::toHttps()}, dessen
     * `location` und `return` jede Form ohnehin führt. Der Zugewinn ist
     * deshalb in allen Formen derselbe — und wenn nicht, ist
     * `PROMISED_WITH_TLS` die falsche Form von Liste.
     */
    public function test_a_certificate_adds_exactly_the_ssl_directives(): void
    {
        $erwartet = SiteTemplate::PROMISED_WITH_TLS;
        sort($erwartet);

        foreach ($this->formArgs() as $form => $args) {
            $ohne = $this->heads(SiteTemplate::render(Site::fromArgs($args)));
            $mit = $this->heads(SiteTemplate::render(
                Site::fromArgs(['certificate' => 'beispiel.de'] + $args),
                $this->store(),
            ));

            $dazu = array_values(array_diff($mit, $ohne));
            sort($dazu);

            $this->assertSame($erwartet, $dazu, $form.': ein Zertifikat legt andere Anweisungen dazu als PROMISED_WITH_TLS nennt.');
            $this->assertSame([], array_values(array_diff($ohne, $mit)), $form.': ein Zertifikat nimmt eine Anweisung weg — dann ist die Zusage keine Obermenge mehr.');
        }
    }

    /**
     * HSTS ist eine Eigenschaft des Zertifikats und keine der Form.
     *
     * {@see Trust::hsts()} liest den **Inhalt** der Zertifikatsdatei — ein
     * selbstsigniertes bekommt kein HSTS. Die Bestandsdiagnose bekommt die
     * Form und nicht den Aussteller.
     *
     * > **Eine Anweisung, deren Anwesenheit von einem Wert und nicht von der
     * > Form abhängt, ist keine Zusage der Form.**
     *
     * ## Warum dieser Wächter am 4. September umgebaut wurde
     *
     * Er mass HSTS daran, dass es `add_header` **hinzufügt**, und die Wache des
     * Wartungsmodus (A12) fügt dieselbe Anweisung seitdem in jeder Form hinzu.
     * Der Unterschied zwischen „HSTS hat seinen Header geschrieben" und „HSTS
     * hat nichts getan" war damit auf Ebene der **Namen** nicht mehr sichtbar —
     * der Wächter wäre rot geworden, ohne dass eine Regel verletzt war, und
     * grün geblieben, wenn HSTS aufgehört hätte zu wirken.
     *
     * > **Ein Wächter, der misst, was ein Merkmal hinzufügt, wird stumpf,
     * > sobald ein anderes Merkmal dasselbe hinzufügt.**
     *
     * Gemessen wird deshalb die **Anweisung** und nicht ihr erstes Wort. Das
     * ist zugleich die schärfere Frage: Vorher hätte auch ein beliebiger
     * anderer Header den Test bestanden.
     *
     * `add_header` **darf** seit A12 in einer Zusage stehen, und muss es sogar:
     * Die Wache schreibt es unbedingt, eine Datei ohne es ist kaputt. Was nicht
     * zugesagt werden darf, ist der HSTS-Header selbst — und der ist keine
     * Anweisung, sondern ihr Argument.
     *
     * **Gemessen und nicht behauptet:** Der Prüfkörper ist ein Wegwerf-Blatt,
     * das eine Wegwerf-Autorität signiert hat — im Speicher erzeugt, wie in
     * `SiteTemplateTest`, und nie auf der Platte des Repos.
     */
    public function test_hsts_is_not_a_promise_of_the_form(): void
    {
        $args = ['certificate' => 'beispiel.de', 'hsts' => true] + $this->basis();

        $ausText = SiteTemplate::render(Site::fromArgs($args), $this->store());
        $anText = SiteTemplate::render(Site::fromArgs($args), $this->store($this->signedByAnAuthority()));

        // Die Gegenprobe zuerst: Ohne sie wäre nicht zu unterscheiden, ob HSTS
        // nichts ändert oder ob der Prüfkörper beide Male derselbe ist.
        $this->assertStringContainsString('Strict-Transport-Security', $anText, 'Der Prüfkörper mit vertrauenswürdigem Zertifikat trägt kein HSTS — dann misst dieser Test nichts.');
        $this->assertStringNotContainsString('Strict-Transport-Security', $ausText, 'Ein selbstsigniertes Zertifikat bekommt HSTS.');

        // Und am Namen der Anweisung ändert HSTS nichts: `add_header` steht
        // wegen der Wache ohnehin schon da.
        $aus = $this->heads($ausText);
        $an = $this->heads($anText);

        $this->assertSame([], array_values(array_diff($an, $aus)), 'HSTS legt eine Anweisung dazu, die keine Zusage kennt.');
        $this->assertSame([], array_values(array_diff($aus, $an)), 'HSTS nimmt eine Anweisung weg.');

        foreach ([SiteTemplate::PROMISED, SiteTemplate::PROMISED_WITH_TLS, ...array_values(SiteTemplate::PROMISED_BY_FORM)] as $liste) {
            $this->assertNotContains('Strict-Transport-Security', $liste, 'Der HSTS-Header steht in einer Zusage — er hängt am Aussteller und nicht an der Form.');
        }
    }

    /**
     * Der stille Schaden aus dem Abnahmelauf wird gefunden.
     *
     * ## Der Prüfkörper ist der vom Server
     *
     * Am 3. September 2026 wurde auf `cloudsrv24` **jede** Anweisung einer
     * PHP-Domain einzeln um ihr Semikolon gebracht und `nginx -t` daneben
     * gemessen: Von fünfundzwanzig lässt der Prüfer **eine** durch — die
     * `index`-Zeile. Verschluckt wird, was darauf folgt:
     * `client_max_body_size`.
     *
     * Gegen die Schnittmenge aller Formen schweigt die Prüfung dort, denn
     * `client_max_body_size` steht nicht darin. Gegen die Zusage **ihrer
     * Form** ist es ein Befund. Das ist der ganze Grund, aus dem die Zusage je
     * Form gefragt wird.
     *
     * > **Ein Prüfer, der die Datei für gültig hält, ist die Voraussetzung
     * > dieses Schadens — und die Zusage entscheidet, ob ihn jemand sieht.**
     *
     * **Beide Zusagen stehen in der Behauptung**, weil eine Null nur dann eine
     * Messung ist, wenn daneben etwas anderes als Null steht: Die alte
     * schweigt, die neue nennt die Anweisung.
     */
    public function test_the_silent_form_of_the_damage_is_found(): void
    {
        $heil = SiteTemplate::render(Site::fromArgs($this->basis()));

        $kaputt = preg_replace('/(index index\.php index\.html index\.htm);/', '$1', $heil, 1);

        $this->assertNotSame($heil, $kaputt, implode("\n", [
            'Der Eingriff hat die Vorlage nicht verändert — die index-Zeile sieht anders aus.',
            'Dann misst dieser Wächter nichts.',
        ]));

        $this->assertSame(
            [],
            Statements::lostInNginx((string) $kaputt, SiteTemplate::PROMISED),
            'Die Schnittmenge findet den Schaden — dann ist dieser Prüfkörper nicht der stille Fall.',
        );

        $this->assertSame(
            ['client_max_body_size fehlt als Anweisung'],
            Statements::lostInNginx((string) $kaputt, SiteTemplate::promised(SiteTemplate::FORM_PHP, false)),
            'Die Zusage der Form findet den Schaden nicht — genau dafür gibt es sie.',
        );
    }

    /** Ein Blatt, das eine andere Autorität signiert hat — nur im Speicher. */
    private function signedByAnAuthority(): string
    {
        $caKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($caKey);

        $caCsr = openssl_csr_new(['commonName' => 'Wegwerf-CA'], $caKey, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($caCsr);

        $ca = openssl_csr_sign($caCsr, null, $caKey, 1, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($ca);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(['commonName' => 'beispiel.de'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);

        $leaf = openssl_csr_sign($csr, $ca, $caKey, 1, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($leaf);

        openssl_x509_export($leaf, $pem);

        return (string) $pem;
    }

    /** Und jede Form verliert gegen **ihre** Zusage nichts — sonst meldete die Diagnose jede Nacht. */
    public function test_no_form_loses_its_own_promise(): void
    {
        foreach ($this->formArgs() as $form => $args) {
            foreach ([false, true] as $tls) {
                $rendered = $tls
                    ? SiteTemplate::render(Site::fromArgs(['certificate' => 'beispiel.de'] + $args), $this->store())
                    : SiteTemplate::render(Site::fromArgs($args));

                $this->assertSame(
                    [],
                    Statements::lostInNginx($rendered, SiteTemplate::promised($form, $tls)),
                    sprintf('%s (tls=%s): die Form verliert gegen ihre eigene Zusage.', $form, $tls ? 'ja' : 'nein'),
                );
            }
        }
    }

    public function test_the_pool_promise_is_in_the_template(): void
    {
        $rendered = PoolTemplate::render('beispiel.de', 'p1001', '8.4', 5);
        $keys = Statements::ini($rendered);

        $this->assertGreaterThanOrEqual(12, count($keys), 'Zu wenige Schlüssel — der Schnitt misst nichts.');

        foreach (PoolTemplate::PROMISED as $key) {
            $this->assertContains($key, $keys, sprintf('%s ist zugesagt und steht nicht in der Vorlage — die Diagnose meldete jeden Pool.', $key));
        }

        $this->assertSame([], Statements::lostInIni($rendered, PoolTemplate::PROMISED));
    }

    /** Die Abschottung ist zugesagt — nicht nur irgendwelche Schlüssel. */
    public function test_the_pool_promise_carries_the_isolation(): void
    {
        foreach (['php_admin_value[open_basedir]', 'php_admin_value[disable_functions]', 'security.limit_extensions', 'user', 'listen.mode'] as $key) {
            $this->assertContains($key, PoolTemplate::PROMISED, $key.' ist nicht zugesagt — ein Pool, der ihn verliert, fiele nicht auf.');
        }
    }
}
