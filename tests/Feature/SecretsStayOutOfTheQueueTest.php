<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Operations\Lifecycles;
use App\Support\Operations\Task;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SrvPanel\Agent\Config;
use SrvPanel\Agent\Registry;

/**
 * Ein Geheimnis überquert den Socket, es liegt nicht in der Warteschlange.
 *
 * **Die Regel gilt seit P4 und war bis P5 eine Gewohnheit.** Ein eingereihter
 * Vorgang legt seine Argumente in `operations.payload` ab — dauerhaft, im
 * Klartext, in der Datenbank des Panels. Für `tls.certificate.upload` (privater
 * Schlüssel) und `dns.credential.store` (DNS-Token) steht der Grund seit dem
 * zweiten Wurf von P4 als Begründung in
 * {@see AgentOperationReachTest::WITHOUT_LIFECYCLE}. Durchgesetzt hat ihn
 * nichts: Wer eine dieser Operationen versehentlich über
 * `Operation::query()->create(['type' => …])` einreihte, bekam kein Rot, sondern
 * ein Passwort in einer Tabelle.
 *
 * P5 macht die Regel zum dritten und vierten Mal nötig (`db.user.create`,
 * `db.user.password`). Beim dritten Mal wird aus einer Gewohnheit ein Wächter.
 *
 * **Zwei Hälften.** Die eine prüft den Weg: Keine dieser Operationen wird
 * eingereiht. Die andere prüft die Ablage: Keine Tabelle des Panels hat eine
 * Spalte, in die ein solches Geheimnis passte.
 */
final class SecretsStayOutOfTheQueueTest extends TestCase
{
    /**
     * Operationen, deren Argumente ein Geheimnis tragen — mit Angabe, welches.
     *
     * Der Wert ist die Begründung und nicht ein Kommentar daneben: Eine Liste
     * ohne Grund je Eintrag wächst, bis sie alles enthält, und dann prüft sie
     * nichts mehr.
     *
     * @var array<string, string>
     */
    private const CARRIES_A_SECRET = [
        'tls.certificate.upload' => 'der private Schlüssel des hochgeladenen Zertifikats',
        'dns.credential.store' => 'das API-Token des DNS-Anbieters',
        'db.user.create' => 'das Passwort des Datenbankbenutzers',
        'db.user.password' => 'dasselbe Passwort beim Zurücksetzen',
        'pg.role.create' => 'das Passwort der PostgreSQL-Rolle — auch beim Zurücksetzen, denn setPassword() ruft dieselbe Operation',
        'db.isolation.probe' => 'das Passwort, mit dem die Selbstprobe sich absichtlich abweisen lässt',
    ];

    /**
     * Operationen, deren Argumentname nach einem Geheimnis klingt und keines ist
     * — mit dem Grund je Eintrag.
     *
     * **Das ist die Gegenrichtung zu {@see self::CARRIES_A_SECRET}, und ohne
     * sie ist die Liste darüber ein Gedächtnis.** Bis zum 20. September 2026
     * stand sie allein da: Wer eine neue Operation mit einem Token baute,
     * bekam kein Rot, sondern einen Eintrag, an den niemand dachte. Gemessen
     * waren damals **acht** Operationen mit einem solchen Argument und
     * **vier** in der Liste.
     *
     * Zwei der vier Fehlenden trugen wirklich ein Geheimnis und stehen jetzt
     * oben; die drei hier sind Namensgleichheit und sonst nichts.
     *
     * @var array<string, string>
     */
    private const ARGUMENT_ONLY_LOOKS_LIKE_A_SECRET = [
        'sftp.key.apply' => '`keys` sind die **öffentlichen** SSH-Schlüssel des Kunden; ihr Zweck ist, verteilt zu werden',
        'system.run.outcome' => '`key` ist eine Aufzählung über `Guard::enum()`, die sagt, welcher abgesetzte Lauf gemeint ist',
        'pg.console.row.write' => '`key` ist der Primärschlüssel der Zeile, die geschrieben wird',
    ];

    /**
     * Spalten, deren Name nach einem Geheimnis klingt und die es geben darf —
     * mit dem Grund je Eintrag.
     *
     * **Hier stand einmal eine einzige Migration.** Die Schema-Hälfte las
     * `…create_databases_tables` und sicherte mit `assertSame(1, $read)` zu,
     * dass sie sie gefunden hatte — und blieb damit an jedem anderen Ort ein
     * Freispruch. Gemessen am 20. September 2026: `webhook_secret` in jener
     * einen Datei war rot, dieselbe Spalte in einer neuen Migration grün.
     *
     * > **Ein Wächter, der einen Ort prüft statt einer Regel, ist an jedem
     * > anderen Ort ein Freispruch.**
     *
     * Gelesen werden deshalb **alle** Migrationen. Was hier nicht steht, ist
     * rot — und ein neues `webhook_secret` bleibt rot, bis jemand aufschreibt,
     * warum es da sein darf.
     *
     * @var array<string, string> `<tabelle>.<spalte>` => Grund
     */
    private const COLUMNS_WITH_A_REASON = [
        'accounts.password' => 'der Hash des Panelkontos, nicht das Passwort',
        'accounts.two_factor_secret' => 'das TOTP-Geheimnis des Kontos — verschlüsselt abgelegt, und ohne Ablage gäbe es keinen zweiten Faktor',
        'cache.key' => 'der Schlüssel eines Zwischenspeichereintrags; Laravels eigene Tabelle',
        'cache_locks.key' => 'dasselbe für die Sperren daneben',
        'password_reset_tokens.token' => 'der kurzlebige Rücksetzcode; Laravels eigene Tabelle',
        'settings.key' => 'der Name einer Einstellung, nicht ihr Wert',
        'ssh_keys.public_key' => 'der **öffentliche** Schlüssel — sein Zweck ist, verteilt zu werden',
        'users.password' => 'der Hash aus Laravels Vorlage; die Tabelle bleibt neben `accounts` stehen',
    ];

    /**
     * Woran ein Name nach einem Geheimnis klingt.
     *
     * **Und hier steht zugleich, was dieser Wächter nicht kann.** Er erkennt
     * einen geheimnisförmigen **Namen** und nicht ein Geheimnis:
     * `dns.credential.store` trägt ihr API-Token in `$args['config']`, und
     * `accounts.two_factor_recovery_codes` steht neben dem Geheimnis, auf das
     * dieses Muster passt. Beide stehen oben — aber gefunden hätte dieses
     * Muster keines von beiden.
     *
     * > **Ein Wächter über die Form eines Namens findet, was sich verrät, und
     * > nicht, was gefährlich ist.** Was er hält, ist die untere Schranke.
     *
     * Die obere bekäme er erst, wenn jede Operation selbst erklärt, ob sie ein
     * Geheimnis entgegennimmt — eine vierte Methode an {@see Op} neben
     * `name()` und `mutating()`. Das sind 117 Operationen und gehört
     * entschieden und nicht nebenbei gebaut.
     */
    private const LOOKS_LIKE_A_SECRET = '/(password|secret|token|key)/i';

    /**
     * Jede Operation, die **eingereiht werden kann**, durchgesehen — mit dem
     * Grund je Eintrag.
     *
     * ## Warum diese Frage und nicht die andere
     *
     * {@see self::CARRIES_A_SECRET} fragt „welche Operation trägt ein
     * Geheimnis". Das ist ein Urteil über **117** Operationen, und die
     * Gegenrichtung dazu kann nur die untere Schranke sein: Ein Muster über
     * Argumentnamen findet `dns.credential.store` nicht, weil ihr Token in
     * `$args['config']` steht.
     *
     * Hier steht die Frage, die den Schaden trifft: **Ein Geheimnis landet nur
     * dann in `operations.payload`, wenn die Operation eingereiht wird.**
     * Gemessen am 20. September 2026 sind das **31 von 117** — die übrigen 86
     * können den Schaden gar nicht anrichten.
     *
     * > **Eine Erklärung, die fast immer dasselbe sagt, wird abgeschrieben
     * > statt beantwortet.** Deshalb keine vierte Methode an `Op`, die 111 Mal
     * > `false` hiesse, sondern 31 Durchsichten.
     *
     * ## Die Menge wird abgeleitet und nicht gepflegt
     *
     * Sie kommt aus {@see Lifecycles::handled()} und {@see Task::cases()} und
     * nicht aus einer Liste hier. Gegengeprüft am 20. September: **alle fünf**
     * Operationsnamen, die im Quelltext von `app/` an einer `'type' => …`
     * -Zeile stehen, liegen in dieser Menge. Wird eine zweiunddreissigste
     * einreihbar, ist dieser Wächter rot — und zwar in dem Augenblick, in dem
     * das Risiko entsteht.
     *
     * ## Was er nicht kann
     *
     * **Gegen eine falsche Durchsicht hilft er nicht.** Wer bei einer der 31
     * hinsieht und das Geheimnis übersieht, bekommt Grün. Und die Argumente
     * lassen sich nicht zuverlässig ableiten: `web.site.apply` reicht `$args`
     * an `Site::fromArgs()`, `subscription.suspend` liest sie in seiner
     * Basisklasse. Der Wert unten ist deshalb ein **Urteil** und keine
     * Aufzählung.
     *
     * @var array<string, string>
     */
    private const QUEUEABLE_REVIEWED = [
        'acme.certificate.issue' => '`contact` ist eine geprüfte Mailadresse, `profile` der **Name** einer Zugangsdatenablage, `directory` eine URL — der Kontoschlüssel bleibt im Agenten',
        'agent.ping' => 'nimmt nichts entgegen',
        'backup.create' => '`certificates` ist eine Liste von **Namen** (`CertificateName::normalize`), `dumps` trägt Maschine und Datenbank; das Schlüsselmaterial liest der Agent aus dem Ablageort',
        'backup.remove' => 'Abonnement und Ablageort',
        'backup.restore' => 'Abonnement, Quelle, Benutzer, Ablageort — Bezeichner',
        'config.validate' => 'Art, Pfad und Zone einer zu prüfenden Datei',
        'db.database.remove' => 'Benutzer- und Datenbanknamen',
        'db.dump.create' => 'Benutzer, Name, Abonnement, Ablageort',
        'db.dump.import' => '`source` ist ein Pfad im Ablageort und nicht sein Inhalt',
        'db.dump.remove' => 'Abonnement und Ablageort',
        'db.restore' => 'Benutzer, Name, Abonnement, Ablageort',
        'db.user.lock' => 'Benutzername und Sperrmodus — Sperren braucht kein Passwort',
        'pg.database.remove' => 'Präfix, Name, Rollen',
        'pg.dump.create' => 'Präfix, Name, Abonnement, Ablageort',
        'pg.dump.import' => '`source` ist ein Pfad im Ablageort',
        'pg.restore' => 'Präfix, Name, Abonnement, Ablageort',
        'pg.role.lock' => 'Präfix und Sperrmodus — anders als `pg.role.create` ohne Passwort, und deshalb darf sie eingereiht werden',
        'pg.server.install' => 'nimmt nichts entgegen',
        'php.version.install' => 'eine Fassungsnummer aus dem Katalog',
        'php.version.remove' => 'dieselbe Fassungsnummer',
        'php.versions' => 'nimmt nichts entgegen',
        'service.action' => 'Unit und Aktion aus fester Liste',
        'service.status' => 'eine Unit',
        'subscription.provision' => 'Name, Benutzer, Kontingent — das Datenbankpasswort entsteht später und geht über `db.user.create`, die nicht eingereiht wird',
        'subscription.quota' => 'Benutzer und Kontingent in MB',
        'subscription.remove' => 'Name und Benutzer',
        'subscription.resume' => 'Name und Benutzer, gelesen in `SubscriptionState::execute()`',
        'subscription.suspend' => 'dieselben beiden, dieselbe Basisklasse',
        'web.site.apply' => '`$args` geht an `Site::fromArgs()`: Domain, Wurzel, PHP-Fassung, Direktiven — das Zertifikat kommt über seinen Namen und nicht als Material',
        'web.site.remove' => 'Abonnement, Benutzer, Domain, Wurzel',
        'webserver.detect' => 'nimmt nichts entgegen',
    ];

    /**
     * Die Namen aller Operationen des Agenten.
     *
     * @return list<string>
     */
    private function names(): array
    {
        return (new Registry(new Config))->names();
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$directory, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Keine dieser Operationen wird eingereiht.
     *
     * Gesucht wird an den beiden Stellen, an denen ein Vorgang entsteht: die
     * Zeile `'type' => '…'` beim Anlegen und der Aufruf
     * `->dispatch…($objekt, '…')`. Beide Formen kennt schon
     * {@see AgentOperationReachTest::dispatched()} — hier stehen sie noch
     * einmal, weil dieser Test eine andere Frage stellt und sie ohne die
     * Fundstelle nicht beantworten kann.
     */
    public function test_no_secret_carrying_operation_is_queued(): void
    {
        $found = [];
        $searched = 0;

        foreach ($this->phpFiles('app') as $path) {
            $source = (string) file_get_contents($path);
            $relative = str_replace(dirname(__DIR__, 2).'/', '', $path);
            $searched++;

            foreach ([
                '/\'type\'\s*=>\s*\'([a-z][a-z0-9.]*)\'/',
                '/->dispatch[A-Za-z]*\(\s*\$[a-zA-Z]+,\s*\'([a-z][a-z0-9.]*)\'/',
            ] as $pattern) {
                preg_match_all($pattern, $source, $matches);

                foreach ($matches[1] as $name) {
                    if (array_key_exists($name, self::CARRIES_A_SECRET)) {
                        $found[] = sprintf('%s in %s', $name, $relative);
                    }
                }
            }
        }

        // Ein Ausdruck, der keine Datei liest, ist kein bestandener Test.
        $this->assertGreaterThan(20, $searched, 'Es werden kaum Dateien gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], $found, sprintf(
            "Diese Operationen tragen ein Geheimnis und werden über die Warteschlange eingereiht:\n  %s\n\n"
            .'Ein eingereihter Vorgang legt seine Argumente in `operations.payload` ab — dauerhaft und im '
            .'Klartext. Sie gehören als unmittelbarer Aufruf (`Client::call`) an den Agenten, und die Zeile '
            .'schreibt der Dienst danach selbst.',
            implode("\n  ", $found),
        ));
    }

    /**
     * Und keine von ihnen wird von einem Lebenslauf erwartet.
     *
     * Ein Lebenslauf beantwortet ausschliesslich Aufgaben aus der
     * Warteschlange. Stünde eine dieser Operationen in
     * {@see Lifecycles::handled()}, wäre das entweder ein Lebenslauf, der ewig
     * wartet — oder der Beleg, dass sie doch eingereiht wird.
     */
    public function test_no_secret_carrying_operation_expects_a_lifecycle(): void
    {
        $handled = Lifecycles::handled();

        foreach (array_keys(self::CARRIES_A_SECRET) as $name) {
            $this->assertNotContains($name, $handled, sprintf(
                '%s trägt ein Geheimnis und darf nicht über die Warteschlange laufen; ein Lebenslauf dafür wäre der Beleg, dass sie es doch tut.',
                $name,
            ));
        }
    }

    /** Und sie steht auch nicht im Aufgabenkatalog, den der Browser auslösen darf. */
    public function test_no_secret_carrying_operation_is_in_the_task_catalogue(): void
    {
        foreach (Task::cases() as $task) {
            $this->assertArrayNotHasKey($task->operation(), self::CARRIES_A_SECRET, sprintf(
                'Die Aufgabe %s schickt %s ab; der Katalog läuft über die Warteschlange.',
                $task->value,
                $task->operation(),
            ));
        }
    }

    /**
     * Die Begründungen zeigen auf etwas Vorhandenes.
     *
     * Dieselbe Gegenrichtung wie in {@see RemovalPathTest} und in `RouteGuard`:
     * Eine Ausnahme für eine Operation, die es nicht mehr gibt, fällt sonst nie
     * auf.
     */
    public function test_every_declared_operation_still_exists(): void
    {
        $names = $this->names();

        foreach (array_keys(self::CARRIES_A_SECRET) as $name) {
            $this->assertContains($name, $names, sprintf(
                'CARRIES_A_SECRET nennt %s; diese Operation gibt es im Agenten nicht mehr.',
                $name,
            ));
        }
    }

    /**
     * Jede geheimnisförmige Spalte des Schemas hat einen Grund.
     *
     * **Am Schema und nicht an einer Absicht** — eine Spalte, die es nicht
     * gibt, lässt sich nicht versehentlich füllen. Gelesen wird die Migration
     * als Text, weil dieser Test ohne Datenbank läuft; das ist dieselbe Bauart
     * wie bei den Vorlagen, die `SiteTemplateTest` als Zeichenkette prüft.
     *
     * Die Tabelle kommt aus dem `Schema::create()` darüber und nicht aus dem
     * Dateinamen: Eine Migration legt mehrere Tabellen an, und `key` in
     * `cache` ist etwas anderes als `key` in einer Tabelle, die es morgen
     * gibt.
     */
    public function test_every_secret_shaped_column_has_a_reason(): void
    {
        $found = [];
        $read = 0;

        foreach ($this->phpFiles('database/migrations') as $path) {
            $read++;
            $table = '?';

            foreach (file($path) ?: [] as $line) {
                if (preg_match('/Schema::(?:create|table)\(\s*.([a-z_]+)./', $line, $m) === 1) {
                    $table = $m[1];
                }

                if (preg_match('/\$table->[a-zA-Z]+\(\s*.([a-z_]+)./', $line, $m) !== 1) {
                    continue;
                }

                if (preg_match(self::LOOKS_LIKE_A_SECRET, $m[1]) !== 1) {
                    continue;
                }

                $found[$table.'.'.$m[1]] = str_replace(dirname(__DIR__, 2).'/', '', $path);
            }
        }

        // Ein Lauf, der keine Migration liest, ist kein bestandener Test.
        $this->assertGreaterThan(20, $read, 'Es werden kaum Migrationen gelesen — dann prüft dieser Test nichts.');

        // Und ein Lauf, der keine einzige Spalte findet, hat sein Muster
        // kaputtgemacht: Acht davon gibt es zu Recht, und sie stehen unten.
        $this->assertNotSame([], $found, 'Keine einzige geheimnisförmige Spalte gefunden — dann greift das Muster nicht mehr.');

        $ohneGrund = array_diff_key($found, self::COLUMNS_WITH_A_REASON);

        $this->assertSame([], $ohneGrund, sprintf(
            "In diesen Spalten liesse sich ein Geheimnis ablegen, und keine trägt einen Grund:\n  %s\n\n"
            .'Wer sie braucht, trägt sie in COLUMNS_WITH_A_REASON ein — mit dem Grund und nicht mit einem '
            .'Kommentar daneben. Das Datenbankpasswort des Kunden wird erzeugt, einmal angezeigt und '
            .'vergessen (docs/36 §4, Entscheidung 3).',
            implode("\n  ", array_map(
                static fn (string $spalte, string $datei): string => $spalte.' in '.$datei,
                array_keys($ohneGrund),
                $ohneGrund,
            )),
        ));
    }

    /**
     * Und die Gegenrichtung: kein Grund steht für eine Spalte, die es nicht gibt.
     *
     * Dieselbe Frage wie bei {@see self::test_every_declared_operation_still_exists}.
     * Eine Begründung, deren Spalte längst umbenannt ist, liest der Nächste als
     * Aussage über den Bestand.
     */
    public function test_every_reasoned_column_still_exists(): void
    {
        $vorhanden = [];

        foreach ($this->phpFiles('database/migrations') as $path) {
            $table = '?';

            foreach (file($path) ?: [] as $line) {
                if (preg_match('/Schema::(?:create|table)\(\s*.([a-z_]+)./', $line, $m) === 1) {
                    $table = $m[1];
                }

                if (preg_match('/\$table->[a-zA-Z]+\(\s*.([a-z_]+)./', $line, $m) === 1) {
                    $vorhanden[] = $table.'.'.$m[1];
                }
            }
        }

        foreach (array_keys(self::COLUMNS_WITH_A_REASON) as $spalte) {
            $this->assertContains($spalte, $vorhanden, sprintf(
                'COLUMNS_WITH_A_REASON begründet %s; diese Spalte legt keine Migration mehr an.',
                $spalte,
            ));
        }
    }

    /**
     * Jede Operation mit einem geheimnisförmigen Argument ist entschieden.
     *
     * **Das ist die Gegenrichtung, die bis zum 20. September 2026 gefehlt
     * hat.** `CARRIES_A_SECRET` allein sagt nur etwas über die Einträge, die
     * darin stehen — nicht darüber, ob einer fehlt. Gemessen waren acht
     * Operationen mit einem solchen Argument und vier in der Liste; zwei der
     * vier Fehlenden trugen wirklich ein Geheimnis.
     *
     * Gefragt wird am Quelltext der Operation und nicht an einer Liste hier:
     * jedes `$args['…']`, dessen Name auf {@see self::LOOKS_LIKE_A_SECRET}
     * passt.
     */
    public function test_every_secret_shaped_argument_is_decided(): void
    {
        $offen = [];
        $gelesen = 0;

        foreach ($this->phpFiles('agent/src/Ops') as $path) {
            $source = (string) file_get_contents($path);
            $gelesen++;

            if (preg_match('/function name\(\).*?return\s+.([a-z][a-z0-9.]*)./s', $source, $m) !== 1) {
                continue;
            }

            $name = $m[1];

            if (preg_match_all('/\$args\[.([a-z_]+).\]/', $source, $treffer) === 0) {
                continue;
            }

            foreach (array_unique($treffer[1]) as $argument) {
                if (preg_match(self::LOOKS_LIKE_A_SECRET, $argument) !== 1) {
                    continue;
                }

                if (array_key_exists($name, self::CARRIES_A_SECRET)
                    || array_key_exists($name, self::ARGUMENT_ONLY_LOOKS_LIKE_A_SECRET)) {
                    continue;
                }

                $offen[] = sprintf('%s liest $args[\'%s\']', $name, $argument);
            }
        }

        $this->assertGreaterThan(100, $gelesen, 'Es werden kaum Operationen gelesen — dann prüft dieser Test nichts.');

        $this->assertSame([], array_unique($offen), sprintf(
            'Diese Operationen lesen ein Argument, dessen Name nach einem Geheimnis klingt, '
            ."und niemand hat entschieden, ob es eines ist:\n  %s\n\n"
            .'Trägt es eines, gehört sie nach CARRIES_A_SECRET und darf nicht eingereiht werden. '
            .'Trägt es keines, gehört sie nach ARGUMENT_ONLY_LOOKS_LIKE_A_SECRET — mit dem Grund.',
            implode("\n  ", array_unique($offen)),
        ));
    }

    /**
     * Und keine Ausnahme steht für eine Operation, die keine mehr braucht.
     *
     * Verliert eine Operation ihr geheimnisförmiges Argument, ist ihre
     * Ausnahme ab da eine Aussage über etwas, das es nicht gibt.
     */
    public function test_every_exemption_still_has_its_argument(): void
    {
        $mitArgument = [];

        foreach ($this->phpFiles('agent/src/Ops') as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/function name\(\).*?return\s+.([a-z][a-z0-9.]*)./s', $source, $m) !== 1) {
                continue;
            }

            if (preg_match_all('/\$args\[.([a-z_]+).\]/', $source, $treffer) === 0) {
                continue;
            }

            foreach ($treffer[1] as $argument) {
                if (preg_match(self::LOOKS_LIKE_A_SECRET, $argument) === 1) {
                    $mitArgument[] = $m[1];
                }
            }
        }

        foreach (array_keys(self::ARGUMENT_ONLY_LOOKS_LIKE_A_SECRET) as $name) {
            $this->assertContains($name, $mitArgument, sprintf(
                'ARGUMENT_ONLY_LOOKS_LIKE_A_SECRET nimmt %s aus; diese Operation liest kein solches Argument mehr.',
                $name,
            ));
        }
    }

    /**
     * Die einreihbare Menge, abgeleitet und nicht gepflegt.
     *
     * @return list<string>
     */
    private function queueable(): array
    {
        $namen = array_merge(
            Lifecycles::handled(),
            array_map(static fn (Task $task): string => $task->operation(), Task::cases()),
        );

        $namen = array_values(array_unique($namen));
        sort($namen);

        return $namen;
    }

    /**
     * Jede einreihbare Operation ist durchgesehen.
     *
     * Rot wird dieser Fall in dem Augenblick, in dem eine Operation
     * **einreihbar** wird — also dann, wenn sie anfangen kann, Argumente in
     * `operations.payload` abzulegen.
     */
    public function test_every_queueable_operation_has_been_reviewed(): void
    {
        $queueable = $this->queueable();

        // Eine leere Menge ist keine Messung: Fiele die Ableitung aus, wäre
        // jede Durchsicht vollständig und dieser Fall grün.
        $this->assertGreaterThan(20, count($queueable), 'Die einreihbare Menge ist fast leer — dann prüft dieser Test nichts.');

        $offen = array_values(array_diff($queueable, array_keys(self::QUEUEABLE_REVIEWED)));

        $this->assertSame([], $offen, sprintf(
            "Diese Operationen lassen sich einreihen, und niemand hat sie auf Geheimnisse durchgesehen:\n  %s\n\n"
            .'Ein eingereihter Vorgang legt seine Argumente in `operations.payload` ab — dauerhaft, im '
            .'Klartext, und `Operations/Show.vue` zeigt sie jedem Admin und dem Kunden des Abonnements. '
            .'Wer eine Operation einreihbar macht, trägt sie in QUEUEABLE_REVIEWED ein — mit dem Grund.',
            implode("\n  ", $offen),
        ));
    }

    /**
     * Und die Gegenrichtung: keine Durchsicht steht für etwas, das nicht mehr
     * eingereiht werden kann.
     *
     * Ohne sie wüchse die Liste mit jedem Umbau, und ihre Länge sagte nichts
     * mehr über den Bestand.
     */
    public function test_every_review_is_still_queueable(): void
    {
        $queueable = $this->queueable();

        foreach (array_keys(self::QUEUEABLE_REVIEWED) as $name) {
            $this->assertContains($name, $queueable, sprintf(
                'QUEUEABLE_REVIEWED führt %s; diese Operation lässt sich nicht mehr einreihen.',
                $name,
            ));
        }
    }

    /**
     * Und keine durchgesehene Operation trägt zugleich ein Geheimnis.
     *
     * Das ist die Naht zwischen beiden Listen. Stünde ein Name in beiden, wäre
     * entweder die Durchsicht falsch oder die Operation gehört nicht in die
     * Warteschlange — und welches von beidem, muss ein Mensch entscheiden.
     */
    public function test_no_reviewed_operation_carries_a_secret(): void
    {
        $beides = array_intersect(
            array_keys(self::QUEUEABLE_REVIEWED),
            array_keys(self::CARRIES_A_SECRET),
        );

        $this->assertSame([], array_values($beides), sprintf(
            "Diese Operationen stehen in beiden Listen:\n  %s\n\n"
            .'Entweder ist die Durchsicht falsch, oder sie gehört nicht in die Warteschlange.',
            implode("\n  ", $beides),
        ));
    }
}
