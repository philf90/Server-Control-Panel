<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Files\Scheme;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Das Gerüst des Abonnements gehört dem Panel, sein Inhalt dem Kunden.
 *
 * ## Der Anlass
 *
 * Gefunden bei der Frage des Betreibers nach einer Mehrfachauswahl: Was heute
 * passiert, wenn jemand `httpdocs` auswählt und „Entfernen" drückt, ist
 * schlimmer als eine Abweisung.
 *
 * 1. `Filesystem::removeTree()` räumt das Verzeichnis leer — der Inhalt gehört
 *    dem Kunden, jedes `unlink` gelingt.
 * 2. Das `rmdir` scheitert, weil die Vhost-Wurzel `root:root` gehört.
 * 3. Gemeldet wird „Das Verzeichnis liess sich nicht vollständig entfernen."
 *
 * **Die Webseite ist weg, und die Meldung sagt, es sei nichts passiert.**
 *
 * > **Ein Vorgang, der scheitert, nachdem er die Hälfte getan hat, meldet einen
 * > Fehlschlag und hinterlässt eine Wirkung.**
 *
 * ## Warum die Liste nicht abgetippt wird
 *
 * Sie kommt aus {@see SubscriptionProvision::reservedDirectories()} und wächst
 * damit mit dem Schema. Eine zweite Aufzählung — hier, im Panel oder in der
 * Oberfläche — wäre die Fassung, die beim nächsten Zuwachs veraltet.
 */
final class SchemeProtectionTest extends TestCase
{
    /**
     * Jedes Verzeichnis des Schemas ist geschützt, und die Liste wird nicht
     * abgetippt.
     *
     * Geprüft wird gegen die **Quelle** der Liste und nicht gegen eine
     * Aufzählung in diesem Test: Käme morgen `cache` dazu, wäre eine
     * abgetippte Liste hier grün und der Schutz unvollständig.
     */
    public function test_every_directory_of_the_scheme_is_protected(): void
    {
        $erwartet = [
            SubscriptionProvision::DOCUMENT_ROOT,
            ...SubscriptionProvision::reservedDirectories(),
        ];

        $this->assertGreaterThan(
            4,
            count($erwartet),
            'Es werden fast keine Verzeichnisse des Schemas gefunden — dann liest dieser '.
            'Wächter die Liste nicht mehr, und seine Zusage ist wertlos.',
        );

        foreach ($erwartet as $name) {
            $this->assertTrue(
                Scheme::isFixed('/'.$name),
                sprintf('`/%s` gehört zum Schema und ist nicht geschützt.', $name),
            );
        }
    }

    /**
     * Der Inhalt gehört dem Kunden.
     *
     * **Das ist die andere Hälfte der Regel**, und ohne sie wäre der Schutz
     * schlimmer als keiner: `httpdocs` leerzuräumen ist genau das, was jemand
     * vor einem neuen Deploy tut.
     */
    public function test_what_lies_inside_is_not(): void
    {
        foreach ([
            '/httpdocs/index.html',
            '/httpdocs/bilder',
            '/.ssh/authorized_keys',
            '/logs/access.log',

            // Ein Verzeichnis, das der Kunde selbst angelegt hat und das
            // zufällig heisst wie eines des Schemas. Es liegt eine Ebene
            // tiefer und gehört ihm.
            '/tmp/httpdocs',
            '/httpdocs/conf',
        ] as $pfad) {
            $this->assertFalse(
                Scheme::isFixed($pfad),
                sprintf('`%s` liegt im Schema und nicht darin — es gehört dem Kunden.', $pfad),
            );
        }
    }

    /**
     * Und die Abweisung nennt den Grund und was stattdessen geht.
     *
     * `docs/19 §6`: Eine Auskunft, die nur „darf nicht" sagt, lässt den Lesenden
     * mit der Frage zurück, was er denn tun soll. Hier ist die Antwort kurz und
     * gehört in denselben Satz.
     */
    public function test_the_refusal_says_what_is_still_possible(): void
    {
        try {
            Scheme::protect('/httpdocs', 'entfernt');

            $this->fail('`/httpdocs` liess sich entfernen.');
        } catch (AgentException $ausnahme) {
            $satz = $ausnahme->getMessage();

            $this->assertStringContainsString('/httpdocs', $satz, 'Die Abweisung nennt den Pfad nicht.');
            $this->assertStringContainsString('entfernt', $satz, 'Die Abweisung nennt nicht, was versucht wurde.');
            $this->assertStringContainsString(
                'Inhalt',
                $satz,
                'Die Abweisung sagt nicht, dass der Inhalt weiterhin änderbar ist. Ohne diesen '.
                'Halbsatz liest sich der Ordner wie gesperrt.',
            );
        }
    }

    /**
     * Die drei Operationen, die etwas zerstören können, fragen danach.
     *
     * **`chmod` ist die, die man vergisst**, und sie ist seit Schritt 6c die
     * gefährlichste von den dreien: `httpdocs` trägt das setgid-Bit, diese
     * Operation nimmt nur neun Bits entgegen — ein `chmod` des Kunden nähme das
     * zehnte lautlos weg, und die nächste hochgeladene Datei trüge wieder die
     * falsche Gruppe.
     */
    public function test_the_operations_that_can_destroy_ask_first(): void
    {
        foreach ([
            'FilesRemove' => 'entfernt',
            'FilesMove' => 'verschoben',
            'FilesChmod' => 'Rechten geändert',
        ] as $klasse => $verb) {
            $quelle = (string) file_get_contents(
                dirname(__DIR__, 2).'/agent/src/Ops/'.$klasse.'.php',
            );

            $this->assertStringContainsString(
                'Scheme::protect(',
                $quelle,
                sprintf('%s fragt das Schema nicht — der Kernel weist erst zu spät ab.', $klasse),
            );

            $this->assertStringContainsString(
                $verb,
                $quelle,
                sprintf('%s nennt in seiner Abweisung nicht, was versucht wurde.', $klasse),
            );
        }
    }

    /**
     * Und die Prüfung steht **vor** dem Eintritt in die Sandbox.
     *
     * Das ist der ganze Punkt. Innerhalb von `$workspace->run(...)` wäre sie
     * korrekt und wirkungslos: Der Kernel weist denselben Vorgang auch dort ab
     * — nur eben nach dem Leerräumen.
     */
    public function test_the_check_runs_before_anything_happens(): void
    {
        $quelle = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/FilesRemove.php',
        );

        $schutz = strpos($quelle, 'Scheme::protect(');
        $sandbox = strpos($quelle, '$workspace->run(');

        $this->assertNotFalse($schutz, 'FilesRemove fragt das Schema gar nicht.');
        $this->assertNotFalse($sandbox, 'FilesRemove betritt die Sandbox nicht mehr.');

        $this->assertLessThan(
            $sandbox,
            $schutz,
            'Die Prüfung steht innerhalb der Sandbox. Dann läuft sie, nachdem der Baumlauf '.
            'begonnen hat — und genau das ist der Fehler, gegen den sie gebaut wurde.',
        );
    }

    /**
     * Ein `chmod` nimmt das geerbte setgid-Bit eines Verzeichnisses nicht weg.
     *
     * ## Die Begründung stand da und galt nur für einen Fall
     *
     * `FilesChmod` erklärt über seinem `Scheme::protect()`, warum ein `chmod`
     * des Kunden auf `httpdocs` das setgid-Bit lautlos nähme. **Dieselbe
     * Überlegung gilt für jedes Verzeichnis darin** — und dort schützt `Scheme`
     * ausdrücklich nicht, denn `httpdocs/bilder` ist Inhalt und kein Gerüst.
     *
     * > **Eine Begründung, die für einen Fall aufgeschrieben ist, gilt oft für
     * > mehr — und wird trotzdem nur dort angewandt.**
     *
     * Der Rechte-Editor bietet neun Bits an (`docs/51 §8.2` nennt setgid
     * ausdrücklich als das, was **nicht** angeboten wird). Ein Griff, der neun
     * Bits anbietet, darf das zehnte nicht anfassen.
     *
     * ## Warum hier zuerst gemessen und dann gelesen wird
     *
     * Dieser Wächter hat zunächst **nur** den Quelltext gelesen, und die
     * Behauptung, gegen die er ihn las, stand auf einer Messung auf
     * `cloudsrv24`, die den falschen Gegenstand traf: `httpdocs/p6-bit` war
     * eine **Datei** (`-rwxr-xr-x`, 0 Byte) und kein Verzeichnis. Eine Datei
     * erbt in einem setgid-Verzeichnis nur die **Gruppe** und nie das Bit — ihr
     * `755` nach dem `chmod` belegt also nichts (`docs/55`, Befund 13).
     *
     * > **Ein Wächter, der Quelltext gegen eine ungemessene Behauptung liest,
     * > prüft die Schreibweise einer Vermutung.**
     *
     * Die Mechanik selbst braucht kein Chroot und keine Rechte, also wird sie
     * hier gemessen: `chmod(2)` setzt den Modus vollständig, GNU-`chmod`
     * bewahrt das Bit bei Verzeichnissen von sich aus, PHPs `chmod()` nicht.
     * Erst danach wird nachgesehen, ob `FilesChmod` die gemessene Rechnung auch
     * anstellt.
     */
    public function test_a_chmod_keeps_the_inherited_setgid_bit(): void
    {
        $eltern = sys_get_temp_dir().'/srvpanel-setgid-'.bin2hex(random_bytes(6));
        mkdir($eltern, 0o750);

        try {
            // Erst die Vererbung: Ohne sie prüfte der Rest einen Fall, den es
            // nicht gibt.
            chmod($eltern, 0o2750);
            $kind = $eltern.'/kind';
            mkdir($kind);
            $datei = $eltern.'/datei';
            touch($datei);
            clearstatcache();

            // Geerbt wird **das Bit**, nicht der Modus: Die neun Rechtebits
            // kommen aus `mkdir` und der umask. Ein Vergleich des ganzen Modus
            // prüft hier die umask der CI und nicht die Vererbung.
            $this->assertSame(
                0o2000,
                fileperms($kind) & 0o2000,
                'Ein Unterverzeichnis erbt das setgid-Bit nicht. Dann gibt es hier nichts zu bewahren, '.
                'und die Rechnung in `FilesChmod` wäre grundlos.',
            );

            $this->assertSame(
                0,
                fileperms($datei) & 0o2000,
                "Eine **Datei** trägt in einem setgid-Verzeichnis das Bit.\n\n".
                'Genau diese Annahme hat den Befund auf `cloudsrv24` am falschen Gegenstand gemessen: '.
                'Erbte eine Datei es, wäre `p6-bit` ein gültiger Beleg gewesen.',
            );

            // Und der Verlust, gegen den die Rechnung gebaut ist.
            chmod($kind, 0o755);
            clearstatcache();

            $this->assertSame(
                0o755,
                fileperms($kind) & 0o7777,
                'PHPs `chmod()` bewahrt das setgid-Bit von sich aus. Dann ist die Rechnung in '.
                '`FilesChmod` überflüssig — und diese Erwartung hier falsch.',
            );

            // Und dass die Rechnung des Griffs ihn tatsächlich aufhebt.
            chmod($kind, 0o755 | 0o2000);
            clearstatcache();

            $this->assertSame(
                0o2755,
                fileperms($kind) & 0o7777,
                'Das mitgeführte Bit kommt nicht an. Dann behebt `$mode | $geerbt` den Verlust nicht.',
            );
        } finally {
            @unlink($eltern.'/datei');
            @rmdir($eltern.'/kind');
            @rmdir($eltern);
        }

        $quelle = (string) file_get_contents(
            dirname(__DIR__, 2).'/agent/src/Ops/FilesChmod.php',
        );

        $this->assertMatchesRegularExpression(
            "/\\\$geerbt = \\\$entry\\['type'\\] === 'directory'/",
            $quelle,
            "`files.chmod` führt das geerbte setgid-Bit nicht mehr mit.\n\n".
            'Ein `chmod 755` auf ein Unterverzeichnis von `httpdocs` nimmt es dann weg, und jede '.
            'Datei darin trägt danach wieder die Gruppe des Abonnements.',
        );

        $this->assertStringContainsString(
            '@chmod($path, $mode | $geerbt)',
            $quelle,
            'Der Modus wird ohne das bewahrte Bit gesetzt.',
        );

        /*
         * **Nur bei Verzeichnissen**, und das ist die Gegenrichtung derselben
         * Regel: Auf einer Datei bedeutet dasselbe Bit die Ausführung unter
         * fremder Gruppe. Dieses Panel setzt es dort nirgends — was es nicht
         * setzt, muss es auch nicht bewahren.
         */
        $this->assertStringNotContainsString(
            '$geerbt = $entry[\'mode\'] & 0o2000;',
            $quelle,
            'Das Bit wird auch auf Dateien bewahrt. Dort heisst es etwas anderes.',
        );
    }

    /**
     * Und derselbe `chmod` ohne Rechte braucht die Gruppe der Datei.
     *
     * ## Warum dieser Fall überhaupt existiert
     *
     * Der Fall darüber misst die Mechanik — und er lief **als root**. Root hat
     * `CAP_FSETID`, und damit gelingt jedes `chmod` samt setgid. Auf
     * `cloudsrv24` stand danach trotzdem `drwxr-xr-x` (`docs/55`, Befund 17):
     * `chmod(2)` entfernt `S_ISGID` lautlos, wenn der Prozess weder diese
     * Fähigkeit hat noch der Gruppe der Datei angehört — und meldet `true`.
     *
     * > **Eine Messung als root prüft nicht, was ein unprivilegierter Prozess
     * > darf.**
     *
     * ## Warum er sich überspringt statt zu scheitern
     *
     * Er braucht beides: root (um Rechte abgeben zu können) **und** eine
     * Gruppe, der der Testbenutzer nicht angehört. In der CI läuft PHPUnit
     * unprivilegiert, dort geht das nicht. Ein Fehlschlag wäre dort eine
     * Meldung über die Umgebung und nicht über den Code — und ein Wächter, der
     * aus einem solchen Grund rot ist, wird abgeschaltet.
     *
     * **Übersprungen ist hier ehrlich und nicht bequem:**
     * {@see SandboxGroupTest} prüft ohne Rechte nach, dass die
     * Gruppe angefordert wird und bis `initgroups` durchkommt. Und `FilesChmod`
     * sieht zur Laufzeit selbst nach, ob das Bit angekommen ist — das ist die
     * Absicherung, die auf jedem System greift.
     *
     * ## Und warum sein Bruch nicht im Skript steht
     *
     * `tests/waechter-brechen.sh` läuft in der CI, also unprivilegiert. Ein
     * Eingriff, der die Erwartung hier umdreht, machte diesen Fall dort nicht
     * rot — er überspringt sich ja — und das Skript meldete „ohne Biss" für eine
     * Regel, die in Ordnung ist. Ein Bruch, der nur am falschen Ort nicht
     * greift, ist schlimmer als keiner: Er zieht die Aufmerksamkeit auf die
     * falsche Stelle.
     *
     * Gebrochen wurde er deshalb **von Hand als root**, am 15. August 2026: mit
     * `0o2755` statt `0o755` als Erwartung wird er rot, mit `0o755` grün. Damit
     * ist belegt, dass er wirklich misst und sich nicht still überspringt.
     *
     * > **Ein Fall, der sich überspringen darf, muss anderswo belegen, dass er
     * > es nicht immer tut.**
     */
    public function test_an_unprivileged_chmod_needs_the_group(): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0 || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped(
                'Rechte abgeben geht nur als root. Was diese Messung belegen soll, prüft '.
                'SandboxGroupTest ohne Rechte nach.',
            );
        }

        $fremd = posix_getgrnam('www-data');
        $niemand = posix_getpwnam('nobody');

        if ($fremd === false || $niemand === false) {
            $this->markTestSkipped('Es fehlt `www-data` oder `nobody` — ohne beides gibt es keinen Fall.');
        }

        if ($fremd['gid'] === 0 || $fremd['gid'] === $niemand['gid'] || in_array('nobody', $fremd['members'], true)) {
            $this->markTestSkipped('`nobody` gehört hier zu `www-data` — dann misst dieser Fall das Gegenteil.');
        }

        $eltern = '/tmp/srvpanel-fsetid-'.bin2hex(random_bytes(6));
        mkdir($eltern, 0o777);
        $kind = $eltern.'/kind';
        mkdir($kind);
        chgrp($kind, $fremd['gid']);
        chown($eltern, $niemand['uid']);
        chown($kind, $niemand['uid']);

        try {
            $messen = function (?int $mitGruppe) use ($kind, $niemand): int {
                chmod($kind, 0o2770);
                clearstatcache();

                $pid = pcntl_fork();

                if ($pid === 0) {
                    /*
                     * Genau die Reihenfolge der Sandbox: initgroups, setgid,
                     * setuid — und **jeder Schritt geprüft**. Misslänge die
                     * Abgabe still, liefe der `chmod` als root, behielte das
                     * Bit und liesse diese Messung grün aussehen: derselbe
                     * Fehler noch einmal, nur im Prüfmittel.
                     */
                    $gelungen = posix_initgroups('nobody', $mitGruppe ?? $niemand['gid'])
                        && posix_setgid($niemand['gid'])
                        && posix_setuid($niemand['uid'])
                        && posix_geteuid() !== 0;

                    if (! $gelungen) {
                        exit(1);
                    }

                    @chmod($kind, 0o755 | 0o2000);
                    exit(0);
                }

                pcntl_waitpid($pid, $status);
                clearstatcache();

                $this->assertSame(
                    0,
                    pcntl_wexitstatus($status),
                    'Das Kind konnte seine Rechte nicht abgeben. Dann misst dieser Fall nichts.',
                );

                return fileperms($kind) & 0o7777;
            };

            $this->assertSame(
                0o755,
                $messen(null),
                "Ein unprivilegierter `chmod` behält das setgid-Bit auch ohne die Gruppe der Datei.\n\n".
                'Dann ist die zusätzliche Gruppe in `Sandbox` überflüssig — und die Begründung dort '.
                'beschreibt einen Kernel, den es nicht gibt.',
            );

            $this->assertSame(
                0o2755,
                $messen($fremd['gid']),
                "Mit der Gruppe der Datei in den Zusatzgruppen kommt das setgid-Bit trotzdem nicht an.\n\n".
                'Dann hilft der Griff in `Sandbox` nicht, und `files.chmod` bricht auf diesem System '.
                'weiterhin die Gruppenvererbung.',
            );
        } finally {
            @rmdir($kind);
            @rmdir($eltern);
        }
    }
}
