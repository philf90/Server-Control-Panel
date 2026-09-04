<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\Diagnose\Statements;
use SrvPanel\Agent\Diagnose\Verdict;
use SrvPanel\Agent\Maintenance;
use SrvPanel\Agent\Ops\SystemDiagnose;
use SrvPanel\Agent\PoolTemplate;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;
use Tests\Support\MethodBody;
use Tests\Support\WithoutPhpComments;

/**
 * Die Zusagen der Vorlage werden am Anfang einer Anweisung geprüft — nicht als
 * Zeichenkette (A10 Schritt 4, `docs/98 §3 B`, Frage 4b).
 *
 * **Die Prüfkörper sind die aus der Messrunde**, Byte für Byte: die beiden
 * Formen, die `nginx -t` mit `rc=0` und ohne ein Byte Ausgabe durchlässt
 * (`docs/81 §2.3o` M3). In beiden steht die verschluckte Anweisung wörtlich
 * noch in der Datei; `grep` findet sie, und die Domain hat sie trotzdem
 * verloren. M21 hat gemessen, dass eine Textsuche genau hier grün bliebe.
 *
 * > **Eine Anweisung, die zum Argument der vorigen geworden ist, steht wörtlich
 * > noch da.** Wer nach der Zeichenkette sucht, findet sie; wer nach der
 * > Anweisung sucht, nicht.
 *
 * Framework-frei.
 */
final class SiteFileIntegrityTest extends TestCase
{
    use MethodBody;
    use WithoutPhpComments;

    /** M3, Fall 2: `index` ohne Semikolon verschluckt `access_log`. */
    private const M3_FALL_2 = "server {\n    listen 8081;\n    server_name a.mess.invalid;\n    index index.php index.html\n    access_log /var/log/nginx/mess.log;\n    error_log /var/log/nginx/mess-error.log;\n    location ^~ /.well-known/acme-challenge/ { root /var/spool/x; default_type text/plain; }\n    root /var/www/html;\n}\n";

    /** M3, Fall 1: `server_name` ohne Semikolon verschluckt ein zweites `server_name`. */
    private const M3_FALL_1 = "server {\n    listen 8081;\n    server_name a.mess.invalid\n    server_name b.mess.invalid;\n    access_log /var/log/nginx/mess.log;\n    error_log /var/log/nginx/mess-error.log;\n    location ^~ /.well-known/acme-challenge/ { root /var/spool/x; default_type text/plain; }\n    root /var/www/html;\n}\n";

    /** @return array<string, array{0: string, 1: string}> */
    public static function measured(): array
    {
        return [
            'M3 Fall 2: index verschluckt access_log' => [self::M3_FALL_2, 'access_log'],
            'M3 Fall 1: server_name verschluckt server_name' => [self::M3_FALL_1, 'server_name'],
        ];
    }

    #[DataProvider('measured')]
    public function test_a_swallowed_directive_is_lost_although_grep_finds_it(string $content, string $directive): void
    {
        // Die Gegenprobe aus M21: Die Zeichenkette steht da.
        $this->assertStringContainsString($directive, $content);

        $lost = Statements::lostInNginx($content, SiteTemplate::PROMISED);

        $this->assertNotSame([], $lost, 'Der Schnitt sieht den Verlust nicht — dann ist er eine Textsuche.');
        $this->assertStringContainsString($directive, implode("\n", $lost));
    }

    /**
     * Und die Gegenrichtung: Mit dem Semikolon an seinem Platz ist `access_log`
     * wieder da.
     *
     * Der Prüfkörper ist derselbe wie M3 Fall 2 — ein Wächter, der auch dort
     * `access_log` meldete, meldete jede Nacht jede Domain, und das ist die
     * Falle aus `docs/98 §4`. **Was die Messdatei trotzdem verliert, ist alles,
     * was sie nie hatte:** Sie ist eine Datei der Messrunde und keine des
     * Panels — sie trägt weder den Standardschutz (`return`) noch die Wache des
     * Wartungsmodus (`set`, `if`, `error_page`, `add_header`, seit A12). Dass
     * es **genau diese fünf** sind, gehört zur Behauptung; dass eine heile
     * Datei des Panels gar nichts verliert, hält `PromiseReachTest` an jeder
     * Form.
     *
     * **Die Messdatei selbst wird dabei nicht nachgezogen, und das ist die
     * Regel dahinter.** `M3_FALL_2` ist eine am 2. September gemessene Ausgabe
     * (`docs/81 §2.3o`) und kein Prüfkörper, den man pflegt. Wer sie ergänzt,
     * damit ein Test grün wird, verändert einen Beleg.
     *
     * > **Eine gemessene Datei ist ein Beleg und keine Vorlage — wer sie an
     * > eine neue Erwartung anpasst, hat die Messung verloren.**
     */
    public function test_the_same_file_with_its_semicolon_loses_only_what_it_never_had(): void
    {
        $heil = str_replace("index index.php index.html\n", "index index.php index.html;\n", self::M3_FALL_2);

        $this->assertNotSame(self::M3_FALL_2, $heil, 'Der Prüfkörper ist unverändert — die Gegenprobe misst nichts.');

        $verloren = Statements::lostInNginx($heil, SiteTemplate::PROMISED);
        sort($verloren);

        $this->assertSame([
            'add_header fehlt als Anweisung',
            'error_page fehlt als Anweisung',
            'if fehlt als Anweisung',
            'return fehlt als Anweisung',
            'set fehlt als Anweisung',
        ], $verloren);
    }

    /**
     * Ein Kommentar, der eine Anweisung nennt, ist keine Anweisung.
     *
     * Derselbe Fehler, den `OutcomeTest` am 1. September gemacht hat — nur
     * andersherum: Dort stellte ein Kommentar eine entfernte Zeile für den
     * Wächter wieder her; hier stellte er eine verlorene Anweisung für die
     * Diagnose wieder her.
     */
    public function test_a_comment_does_not_restore_a_lost_directive(): void
    {
        $heil = str_replace("index index.php index.html\n", "index index.php index.html;\n", self::M3_FALL_2);
        $ohne = str_replace("    access_log /var/log/nginx/mess.log;\n", '', $heil);

        $this->assertNotSame($heil, $ohne, 'Die Zeile stand gar nicht da — die Gegenprobe misst nichts.');
        $this->assertContains('access_log fehlt als Anweisung', Statements::lostInNginx($ohne, SiteTemplate::PROMISED));

        // **Der erste Wurf dieses Prüfkörpers hat den Bruch nicht gesehen.** Ein
        // Kommentar beginnt mit `#`, und ohne Abstreifen ist `#` das erste Wort —
        // die Anweisung dahinter wird zum Argument, nicht zur Anweisung. Wieder
        // hergestellt wird sie erst, wenn im Kommentar ein `;` vor ihr steht: ein
        // Kommentar, der zwei alte Zeilen zitiert. Genau der ist der Prüfkörper.
        $mitKommentar = "# vorher: index index.php; access_log /var/log/x.log;\n".$ohne;

        $this->assertContains('access_log fehlt als Anweisung', Statements::lostInNginx($mitKommentar, SiteTemplate::PROMISED), 'Ein Kommentar hat die verlorene Anweisung wiederhergestellt.');

        // Und andersherum: Ein Kommentar, der eine Anweisung nennt, verschluckt sie nicht.
        $lost = Statements::lostInNginx("# root liegt unter /var/www\n".$heil, SiteTemplate::PROMISED);

        $this->assertSame([], preg_grep('/^root /', $lost) ?: [], 'Ein Kommentar hat eine Anweisung verschluckt.');
    }

    /** Die Pool-Datei ist INI: Dort fehlt eine Zeile und kein Semikolon. */
    public function test_a_pool_without_open_basedir_has_lost_it(): void
    {
        $heil = PoolTemplate::render('beispiel.de', 'p1001', '8.4', 5);
        $this->assertSame([], Statements::lostInIni($heil, PoolTemplate::PROMISED), 'Die Vorlage selbst verliert etwas — dann ist die Zusage falsch.');

        $gekuerzt = preg_replace('/^php_admin_value\[open_basedir\].*$/m', '', $heil) ?? '';
        $this->assertNotSame($heil, $gekuerzt);

        $lost = Statements::lostInIni($gekuerzt, PoolTemplate::PROMISED);

        $this->assertSame(['php_admin_value[open_basedir] fehlt'], $lost);
    }

    /** Ein INI-Kommentar mit dem Schlüssel zählt nicht — und `[p1001]` ist kein Schlüssel. */
    /**
     * Keine Vorlage schreibt einen Trenner des Lesers in ein Anführungszeichen.
     *
     * ## Der Fund, der diese Prüfung ausgelöst hat
     *
     * {@see Statements::nginx()} zerlegt eine Datei an `;`, `{` und `}` — und
     * kennt Anführungszeichen **nicht**. Am 4. September 2026 sollte die Wache
     * des Wartungsmodus eine Bedingung mit `{16,128}` bekommen; nginx verlangt
     * für Klammern in einer Bedingung Anführungszeichen, und der Leser zerriss
     * die Zeile daran. Der Nachtlauf hätte für **jede heile Domain** erfundene
     * Anweisungen gemeldet.
     *
     * > **Ein Ausdruck, der in eine Datei geht, die ein anderer Leser zerlegt,
     * > meidet dessen Trennzeichen — oder der Leser muss sie verstehen.**
     *
     * Gehalten wird die billigere Hälfte: Die Vorlagen meiden die Zeichen. Dass
     * der Leser sie verstünde, wäre die andere und ist offen — sie steht als
     * Frage in `docs/102 §6` und nicht als Zusage hier.
     */
    public function test_no_template_hides_a_separator_in_a_quoted_string(): void
    {
        $basis = ['subscription' => 'p1000', 'user' => 'p1000', 'domain' => 'beispiel.de'];

        $formen = [
            SiteTemplate::FORM_SUSPENDED => $basis + ['document_root' => 'httpdocs', 'suspended' => true],
            SiteTemplate::FORM_REDIRECT => $basis + ['redirect_target' => 'https://ziel.example'],
            SiteTemplate::FORM_PHP => $basis + ['document_root' => 'httpdocs', 'php_version' => '8.4'],
            SiteTemplate::FORM_STATIC => $basis + ['document_root' => 'httpdocs'],
        ];

        foreach ($formen as $form => $args) {
            $conf = SiteTemplate::render(Site::fromArgs($args + [
                'maintenance_until' => '2026-09-04 23:35',
                'maintenance_zone' => 'CEST (UTC+02:00)',
            ]));

            foreach (explode("\n", $conf) as $nummer => $zeile) {
                $offen = null;
                $laenge = strlen($zeile);

                for ($i = 0; $i < $laenge; $i++) {
                    $zeichen = $zeile[$i];

                    if ($offen === null && ($zeichen === '"' || $zeichen === "'")) {
                        $offen = $zeichen;

                        continue;
                    }

                    if ($offen === $zeichen) {
                        $offen = null;

                        continue;
                    }

                    if ($offen !== null && str_contains(';{}', $zeichen)) {
                        self::fail(sprintf(
                            "%s, Zeile %d: %s steht in einem Anführungszeichen.\n\n%s\n\n".
                            'Statements::nginx() zerlegt daran und meldet erfundene Anweisungen.',
                            $form,
                            $nummer + 1,
                            $zeichen,
                            trim($zeile),
                        ));
                    }
                }
            }
        }

        self::assertTrue(true);
    }

    public function test_ini_comments_and_sections_are_not_keys(): void
    {
        $this->assertSame(['user'], Statements::ini("; user = p1\n[p1]\nuser = p1\n"));
    }

    /** Was aus den drei Zuständen einer Datei wird. */
    public function test_the_verdict_of_a_file(): void
    {
        $this->assertSame(['reason' => 'missing', 'detail' => null], Verdict::file(null, []));
        $this->assertSame(['reason' => 'empty', 'detail' => null], Verdict::file("  \n", []));
        $this->assertSame(['reason' => 'directive_lost', 'detail' => 'access_log fehlt als Anweisung'], Verdict::file('server {}', ['access_log fehlt als Anweisung']));
        $this->assertNull(Verdict::file("server {\n}", []));
    }

    /**
     * Die Operation fragt den Schnitt und keine Zeichenkette.
     *
     * Gehalten am Rumpf, ohne Kommentare — der Klassenkopf erklärt, warum
     * `str_contains` hier falsch wäre, und nennt es dabei.
     */
    public function test_the_operation_asks_the_statements_and_not_a_string(): void
    {
        // Über die Klasse und nicht über einen Pfad: So misst der Wächter die
        // Datei, die wirklich geladen ist — auch in einem Gestell, das den
        // Agenten von woanders lädt.
        $source = $this->withoutComments((string) file_get_contents((string) (new \ReflectionClass(SystemDiagnose::class))->getFileName()));

        $web = $this->methodBody($source, 'private function webFiles(');
        $php = $this->methodBody($source, 'private function phpFiles(');

        $this->assertStringContainsString('Statements::lostInNginx(', $web);
        $this->assertStringContainsString('SiteTemplate::promised(', $web);
        $this->assertStringContainsString('Statements::lostInIni(', $php);
        $this->assertStringContainsString('PoolTemplate::PROMISED', $php);

        foreach ([$web, $php] as $body) {
            $this->assertStringNotContainsString('str_contains(', $body, 'Eine Textsuche — genau die, die M21 grün gezeigt hat.');
            $this->assertStringNotContainsString('strpos(', $body);
        }
    }

    /**
     * Die Zusage kommt aus der Form und nicht aus dem Inhalt der Datei.
     *
     * ## Die Falle, gegen die es diesen Wächter gibt
     *
     * Seit dem 3. September 2026 fragt `web.file` je Form (`docs/99 §5`), und
     * der naheliegende Weg dorthin wäre gewesen, die Form aus der Datei
     * abzulesen: „steht `fastcgi_pass` drin, dann ist es eine PHP-Domain".
     * Das wäre genau der Schaden, der sich selbst verbirgt — die verschluckte
     * Anweisung fiele aus der Erwartung heraus, und der Befund bliebe aus.
     *
     * > **Eine Zusage, die aus dem Prüfling abgeleitet wird, schrumpft mit
     * > seinem Schaden.**
     *
     * ## Gemessen an der Wirkung
     *
     * Der Prüfkörper ist eine PHP-Domain, die ihr `fastcgi_pass` verloren hat.
     * Gegen die Zusage **ihrer Form** ist das ein Verlust; gegen eine Zusage,
     * die aus dem Inhalt entstünde, wäre es keiner — dort käme `fastcgi_pass`
     * gar nicht vor.
     *
     * Und die Gegenrichtung steht daneben, weil eine Zusage, die alles meldet,
     * genauso wertlos ist: Dieselbe Datei als **statische** Form gelesen
     * verliert nichts, denn eine statische Domain hat kein `fastcgi_pass`.
     */
    public function test_the_promise_comes_from_the_form_and_not_from_the_file(): void
    {
        $verstuemmelt = implode("\n", [
            'server {',
            '    listen 80;',
            '    server_name beispiel.de;',
            '    access_log /var/log/a.log;',
            '    error_log /var/log/e.log;',

            // **Die Wache kommt aus ihrer Quelle und wird nicht nachgebaut.**
            // Seit A12 gehören ihre vier Anweisungen zu jeder Zusage; stünden
            // sie hier abgeschrieben, prüfte dieser Prüfkörper die Abschrift.
            // Weggelassen ist genau eine Anweisung, und das ist `fastcgi_pass`.
            Maintenance::nginxGuard(null, null),

            '    location / { root /srv; default_type text/plain; return 404; }',
            '    index index.php;',
            '    client_max_body_size 64m;',
            '    location ~ /\\. { deny all; log_not_found off; }',
            '    location ~ \\.php$ {',
            '        try_files $uri =404;',
            '        fastcgi_split_path_info ^(.+\\.php)(/.*)$;',
            '        fastcgi_index index.php;',
            '        include fastcgi_params;',
            '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;',
            '        fastcgi_read_timeout 300s;',
            '    }',
            '}',
        ]);

        $alsPhp = Statements::lostInNginx($verstuemmelt, SiteTemplate::promised(SiteTemplate::FORM_PHP, false));
        $alsStatisch = Statements::lostInNginx($verstuemmelt, SiteTemplate::promised(SiteTemplate::FORM_STATIC, false));

        $this->assertSame(['fastcgi_pass fehlt als Anweisung'], $alsPhp, implode("\n", [
            'Die Zusage der PHP-Form nennt das fehlende fastcgi_pass nicht.',
            'Dann ist der Prüfkörper falsch oder die Zusage zu klein.',
        ]));

        $this->assertSame([], $alsStatisch, implode("\n", [
            'Dieselbe Datei verliert als statische Form etwas.',
            'Eine statische Domain hat kein fastcgi_pass — die Zusage ist dann zu gross,',
            'und der Nachtlauf meldete jede heile statische Domain.',
        ]));
    }

    /**
     * Und die Form kommt vom Panel, nicht aus dem Verzeichnis.
     *
     * Gehalten am Quelltext, weil es eine Aussage über die **Herkunft** ist
     * und nicht über einen Wert: Im Rumpf von `webFiles()` darf `$content`
     * nicht in die Wahl der Zusage eingehen.
     */
    public function test_the_body_does_not_read_the_content_to_choose_the_promise(): void
    {
        $source = $this->withoutComments((string) file_get_contents((string) (new \ReflectionClass(SystemDiagnose::class))->getFileName()));
        $web = $this->methodBody($source, 'private function webFiles(');

        $zeile = null;

        foreach (explode("\n", $web) as $line) {
            if (str_contains($line, 'SiteTemplate::promised(')) {
                $zeile = $line;
            }
        }

        $this->assertNotNull($zeile, 'Im Rumpf steht kein Aufruf von SiteTemplate::promised() mehr.');
        $this->assertStringNotContainsString('$content', (string) $zeile, implode("\n", [
            'Die Zusage wird aus dem Inhalt der geprüften Datei gewählt.',
            'Damit schrumpft sie mit dem Schaden, den sie finden soll.',
        ]));
    }
}
