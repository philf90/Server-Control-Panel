<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Backups\Description;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Ops\BackupRestore;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use Tests\TestCase;

/**
 * Die Wiederherstellung **erzeugt** und spielt nicht zurück (`docs/117 §7`).
 *
 * ## Warum das der Unterschied ist, um den es in §4 geht
 *
 * Gemessen (`docs/116` M6, an der echten Vorlage und am echten Leser): Eine
 * wörtlich zurückgespielte Vhost-Datei aus einer älteren Fassung meldet der
 * Nachtlauf in der Nacht darauf als `directive_lost`.
 *
 * > **Eine Sicherung, die erzeugte Dateien aufhebt, sichert den Ausgang einer
 * > Rechnung und nicht ihre Eingaben — und die Rechnung ändert sich mit der
 * > nächsten Fassung.**
 *
 * Deshalb steht in der Sicherung die **Beschreibung**, und dieser Wächter hält
 * die drei Stellen, an denen sie tragen muss: Sie darf nicht überschrieben
 * werden, sie muss genug für eine Subdomain enthalten, und das
 * Verzeichnisschema muss den Eigentümerwechsel überleben.
 *
 * ## Was er nicht kann
 *
 * Er sagt **nicht**, dass eine wiederhergestellte Vhost-Datei die
 * Bestandsdiagnose besteht — das ist Punkt 5 des Abnahmekriteriums und braucht
 * nginx, eine echte Vorlage und einen Nachtlauf. Hier steht, dass die
 * **Eingaben** vollständig sind, aus denen sie entsteht.
 *
 * > **Ein Beleg für den Weg ist keiner für das Ziel.**
 */
final class BackupFormTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().'/backup-form-'.bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->scratch));

        parent::tearDown();
    }

    /**
     * **Eine Subdomain lässt sich überhaupt wiederherstellen.**
     *
     * `App\Support\Web\Domains::create()` verlangt für `subdomain` und `alias`
     * die Zeile, unter der sie hängen, und weist sonst mit *„Diese Sorte
     * braucht eine Domain, unter der sie hängt"* ab. Die Beschreibung trägt
     * keine Kennungen — also muss sie den **Namen** nennen.
     *
     * Gefunden beim Ausschreiben von Schritt 8 und nicht beim Bauen von
     * Schritt 5: Dort sah die Beschreibung vollständig aus, weil niemand sie
     * gelesen hat.
     */
    public function test_a_subdomain_can_be_restored_at_all(): void
    {
        $subscription = Subscription::factory()->create(['name' => 'shop']);

        $eltern = Domain::factory()->create([
            'subscription_id' => $subscription->id,
            'name' => 'example.test',
            'type' => DomainType::Main,
        ]);

        Domain::factory()->create([
            'subscription_id' => $subscription->id,
            'parent_domain_id' => $eltern->id,
            'name' => 'shop.example.test',
            'type' => DomainType::Subdomain,
        ]);

        $domains = app(Description::class)->of($subscription)['domains'];

        $kind = null;

        foreach ($domains as $eintrag) {
            if (($eintrag['name'] ?? null) === 'shop.example.test') {
                $kind = $eintrag;
            }
        }

        $this->assertNotNull($kind, 'Die Subdomain steht nicht in der Beschreibung.');

        $this->assertSame('example.test', $kind['parent'] ?? null, implode("\n", [
            'Die Beschreibung nennt den Elternteil der Subdomain nicht.',
            'Ohne ihn weist Domains::create() sie ab, und die Wiederherstellung verliert sie —',
            'still, denn das Archiv ist heil und alles andere kommt zurück.',
        ]));

        // Und die Gegenrichtung: Eine Hauptdomain trägt `null` und nicht den
        // eigenen Namen. Ein Elternteil, der auf sich selbst zeigt, wäre eine
        // Schleife, die erst beim Anlegen auffiele.
        foreach ($domains as $eintrag) {
            if (($eintrag['name'] ?? null) === 'example.test') {
                $this->assertNull($eintrag['parent'], 'Eine Hauptdomain nennt einen Elternteil.');
            }
        }
    }

    /**
     * **Die Dumpliste überschreibt die Struktur der Datenbanken nicht.**
     *
     * `BackupCreate::addManifest()` legt die Dumpliste in die Beschreibung, und
     * bis zum 16. September 2026 tat sie das unter dem Schlüssel `databases` —
     * demselben, unter dem {@see Description} die Struktur ablegt. Beschriftung,
     * Zeichensatz und Sortierung waren damit in jeder geschriebenen Sicherung
     * fort, und eine Wiederherstellung legte jede Datenbank mit der Vorgabe des
     * Servers an.
     *
     * > **Ein geteilter Schlüssel, den eine Seite auch benutzt, ist auf genau
     * > dieser Seite fort.** (`docs/82` Schritt 5, dort an `can` gegen
     * > `abilities`.)
     *
     * **Gemessen am Quelltext des Agenten und nicht an einer Zeichenkette
     * daneben**: Gefragt wird, welchen Schlüssel er beschreibt — und dass es
     * keiner ist, den die Beschreibung schon führt.
     */
    public function test_the_dump_list_never_overwrites_the_structure(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2).'/agent/src/Ops/BackupCreate.php');
        $quelle = $this->withoutPhpComments($quelle);

        preg_match_all('/\$description\[\'([a-z_]+)\'\]\s*=/', $quelle, $treffer);

        $geschrieben = array_values(array_unique($treffer[1]));

        // Die Untergrenze: Findet der Ausdruck nichts, wäre die Prüfung
        // darunter grün für eine Datei, die er gar nicht gelesen hat.
        $this->assertNotSame([], $geschrieben, 'Es wird kein Schlüssel der Beschreibung geschrieben — dann misst dieser Fall nichts.');

        $belegt = array_keys(app(Description::class)->of(Subscription::factory()->create()));

        $kollision = array_values(array_intersect($geschrieben, $belegt));

        $this->assertSame([], $kollision, sprintf(
            "BackupCreate schreibt einen Schlüssel, den die Beschreibung schon führt:\n  %s\n\n".
            'Er überschreibt sie wortlos — und was dort stand, fehlt in jeder Sicherung.',
            implode("\n  ", $kollision),
        ));
    }

    /**
     * **Das Verzeichnisschema überlebt den Eigentümerwechsel.**
     *
     * Zwei Hälften, und beide sind nötig:
     *
     * 1. `applyTree()` **stellt es her** — gemessen an einem echten Baum, dem
     *    ein rekursiver `chown` seine Gruppen genommen hat.
     * 2. `execute()` ruft es **nach** dem Eigentümerwechsel — gemessen an der
     *    Reihenfolge im Rumpf.
     *
     * Ohne die zweite wäre die erste eine Zusage über eine Methode, die zum
     * falschen Zeitpunkt läuft; ohne die erste eine über einen Aufruf, der
     * nichts tut.
     */
    public function test_the_restore_puts_the_directory_scheme_back(): void
    {
        $root = $this->scratch.'/shop';
        mkdir($root.'/httpdocs', 0700, true);
        mkdir($root.'/logs', 0700, true);

        // Der Schaden, den ein rekursiver Griff anrichtet.
        BackupRestore::own($root, 'daemon');

        $this->assertSame(
            posix_getpwnam('daemon')['gid'],
            stat($root.'/httpdocs')['gid'],
            'Der Prüfkörper stellt den Schaden gar nicht her — dann misst die Zeile darunter nichts.',
        );

        SubscriptionProvision::applyTree($root, 'daemon');

        $this->assertSame(
            posix_getgrnam('www-data')['gid'],
            stat($root.'/httpdocs')['gid'],
            'httpdocs gehört nach der Wiederherstellung nicht www-data — der Webserver käme nicht heran.',
        );

        $this->assertSame(
            posix_getgrnam('adm')['gid'],
            stat($root.'/logs')['gid'],
            'logs gehört nach der Wiederherstellung nicht adm — der Betreiber käme ohne root nicht an die Protokolle.',
        );

        // Und die Reihenfolge, gemessen im Rumpf von `execute()`.
        $rumpf = $this->methodBody('agent/src/Ops/BackupRestore.php', 'execute');

        $chown = strpos($rumpf, 'self::own(');
        $schema = strpos($rumpf, 'SubscriptionProvision::applyTree(');

        $this->assertNotFalse($chown, 'execute() setzt keinen Eigentümer.');
        $this->assertNotFalse($schema, implode("\n", [
            'execute() stellt das Verzeichnisschema nicht wieder her.',
            'Ein rekursiver Eigentümerwechsel ebnet es ein: httpdocs gehört danach dem Kunden',
            'statt www-data, und der Webserver kommt an das Dokumentenverzeichnis nicht mehr heran.',
        ]));

        $this->assertGreaterThan($chown, $schema, 'Das Schema wird vor dem Eigentümerwechsel gesetzt — danach ist es fort.');
    }

    /** Der Rumpf einer Methode, ohne Kommentare. */
    private function methodBody(string $pfad, string $methode): string
    {
        $quelle = $this->withoutPhpComments((string) file_get_contents(dirname(__DIR__, 2).'/'.$pfad));

        $start = strpos($quelle, 'function '.$methode.'(');

        $this->assertNotFalse($start, sprintf('%s hat keine Methode %s.', $pfad, $methode));

        return substr($quelle, $start);
    }

    /**
     * Kommentare abstreifen — über den Parser und nicht über einen Ausdruck.
     *
     * Jede Behebung in diesem Repo hält ihren Vorzustand im Kommentar fest; ein
     * Kommentar, der die entfernte Zeile zitiert, stellte sie für einen
     * Ausdruck wieder her.
     */
    private function withoutPhpComments(string $quelltext): string
    {
        $ohne = '';

        foreach (token_get_all($quelltext) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $ohne .= is_array($token) ? $token[1] : $token;
        }

        return $ohne;
    }
}
