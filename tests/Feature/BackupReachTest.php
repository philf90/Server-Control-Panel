<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Subscription;
use App\Support\Backups\Description;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SrvPanel\Agent\Acme\Store as CertificateStore;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use Tests\TestCase;

/**
 * Jede Art aus `docs/117 §4` hat einen Weg — oder steht mit ihrem Grund da.
 *
 * ## Warum es diesen Wächter gibt
 *
 * §4 zählt drei Arten von Inhalt auf, und die dritte ist die gefährliche:
 * *„weder noch"* — was sich weder beschreiben noch erzeugen lässt. Was davon
 * fehlt, fehlt **still**: Das Archiv ist heil, die Wiederherstellung läuft
 * durch, und der Kunde merkt es an dem Tag, an dem er es braucht.
 *
 * > **Was ein Archiv nicht enthält, muss es sagen.**
 *
 * Deshalb steht hier eine Positivliste und keine Suche. Ein neuer Inhalt macht
 * diesen Wächter rot, und dann entscheidet jemand, ob er hinein gehört —
 * dieselbe Bauform wie `BackupSecretTest` und `SourceKeyFilterTest`.
 */
final class BackupReachTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Was die **Beschreibung** trägt — §4, erste Art.
     *
     * Sie kommt aus {@see Description::of()} und nicht aus einer Aufzählung
     * hier: Gemessen wird die Wirkung an einem echten Abonnement.
     *
     * @var list<string>
     */
    private const BESCHRIEBEN = [
        'subscription', 'domains', 'databases', 'db_users', 'cron', 'ssh_keys',
    ];

    /**
     * Was eine Sicherung **nicht** trägt — mit Grund.
     *
     * Die zweite Richtung darunter nimmt einen Eintrag wieder heraus, sobald er
     * getragen wird. So entsteht ein toter Eintrag wirklich: Jemand baut es,
     * der Eintrag bleibt stehen, und die Lücke gilt für immer als bekannt.
     *
     * @var array<string, string>
     */
    private const NICHT_GETRAGEN = [
        /*
         * **Der benannte Rest von P8.** Ein hochgeladenes Zertifikat hat seinen
         * privaten Schlüssel nirgends sonst — `certificates` führt
         * `storage_name` und **kein** Schlüsselmaterial —, und er liegt unter
         * `/etc/srvpanel/tls/certs` und damit ausserhalb des Baums, den der
         * Packer läuft.
         *
         * Nach einer Wiederherstellung fehlt er also, und das Abonnement hat
         * ein Zertifikat ohne Schlüssel. Für ein **ACME**-Zertifikat ist das
         * kein Verlust — es wird neu bestellt, den Weg geht P4 ohnehin; für ein
         * hochgeladenes ist es einer, und der Kunde hat die Datei vielleicht
         * nicht mehr.
         *
         * > **Was weder beschrieben noch erzeugt werden kann, muss die
         * > Sicherung selbst tragen — oder die Wiederherstellung muss sagen,
         * > dass es fehlt.**
         *
         * Er gehört hinein (§4 sagt es), und damit trüge eine Sicherung
         * erstmals ein Geheimnis. Das ist keine Kleinigkeit: Die Datei geht
         * über `response()->download()` an den Kunden. Gebaut ist es nicht.
         */
        'certificate_key' => 'Der private Schlüssel eines hochgeladenen Zertifikats liegt unter /etc/srvpanel/tls/certs und damit ausserhalb des Baums. §4 will ihn in der Sicherung; damit trüge sie erstmals ein Geheimnis, und das ist nicht gebaut.',

        /*
         * **Und die Datenbankpasswörter, aber die sind kein Rest.** Dieses
         * Panel hält sie nicht — weder im Klartext noch verschlüsselt —, es
         * kann sie also gar nicht sichern. Die Wiederherstellung sagt es
         * stattdessen: Jeder Zugang steht wieder da und braucht einmal
         * „Passwort neu setzen".
         */
        'database_passwords' => 'Stehen nirgends — dieses Panel hält keine. Die Wiederherstellung benennt es statt es zu verschweigen (RestoreLifecycle::record()).',
    ];

    public function test_the_description_carries_every_described_kind(): void
    {
        $subscription = Subscription::factory()->create();

        $this->assertSame(
            self::BESCHRIEBEN,
            array_keys(app(Description::class)->of($subscription)),
            'Die Beschreibung trägt eine andere Auswahl als §4 nennt.',
        );
    }

    /**
     * **Der Schlüssel liegt wirklich draussen** — gemessen und nicht behauptet.
     *
     * Ohne diesen Fall wäre die Ausnahme oben eine Zeile, die man auch dann
     * noch läse, wenn längst jemand den Ablageort in den Kundenbaum gelegt
     * hätte. Dann wäre der Schlüssel plötzlich **in** jeder Sicherung, die ein
     * Kunde herunterlädt.
     *
     * > **Eine Zeile, die einen Zustand behauptet, veraltet ohne Vorwarnung —
     * > und nichts prüft sie.**
     */
    public function test_the_certificate_key_really_lies_outside_the_tree(): void
    {
        $this->assertStringStartsNotWith(
            SubscriptionProvision::VHOSTS.'/',
            CertificateStore::ROOT,
            implode("\n", [
                'Der Ablageort der Zertifikate liegt im Baum eines Abonnements.',
                'Dann packt ihn der Packer mit ein — und der private Schlüssel steht in jeder',
                'Sicherung, die ein Kunde herunterlädt.',
            ]),
        );

        // Die Gegenprobe zur Messung: Der Baum ist überhaupt ein Präfix von
        // irgendetwas. Ohne sie bestünde die Zeile darüber auch für zwei Pfade,
        // die nichts miteinander zu tun haben.
        $this->assertStringStartsWith(
            SubscriptionProvision::VHOSTS.'/',
            SubscriptionProvision::VHOSTS.'/shop',
            'Der Vergleich misst nichts.',
        );
    }

    /**
     * **Die Gegenrichtung.** Eine Ausnahme, die überholt ist, wird entfernt.
     *
     * Gemessen an der Beschreibung: Trägt sie einen Schlüssel, der hier als
     * „nicht getragen" steht, ist der Eintrag tot.
     */
    public function test_an_exemption_does_not_outlive_its_reason(): void
    {
        $getragen = array_keys(app(Description::class)->of(Subscription::factory()->create()));

        foreach (self::NICHT_GETRAGEN as $was => $grund) {
            $this->assertNotContains($was, $getragen, sprintf(
                '%s steht als „nicht getragen" und ist in der Beschreibung. Der Eintrag gehört entfernt.',
                $was,
            ));

            $this->assertNotSame('', trim($grund), 'Eine Ausnahme ohne Grund ist eine Lücke mit Überschrift.');
        }
    }

    /**
     * **Vor einem Rückbau steht eine Sicherung** — Schritt 10.
     *
     * Gemessen an der **Reihenfolge im Rumpf** und nicht am Vorkommen: Ein
     * `beforeRemoval()` hinter dem Rückbau sicherte einen leeren Baum und
     * meldete dabei Erfolg.
     *
     * > **Eine Sicherung, die nach dem Löschen läuft, sichert das Ergebnis des
     * > Löschens.**
     *
     * Was dieser Fall **nicht** hält: dass die Warteschlange sie auch wirklich
     * zuerst abarbeitet. Das hängt daran, dass `queue:work` einspurig ist und
     * die Datenbank-Warteschlange FIFO liefert — eine Eigenschaft der Umgebung,
     * und sie gehört auf den Server (`docs/117 §16`).
     */
    public function test_a_removal_is_preceded_by_a_backup(): void
    {
        $rumpf = $this->methodBody('app/Http/Controllers/SubscriptionController.php', 'destroy');

        $sicherung = strpos($rumpf, 'beforeRemoval(');
        $rueckbau = strpos($rumpf, "'subscription.remove'");

        $this->assertNotFalse($sicherung, implode("\n", [
            'Der Rückbau legt vorher keine Sicherung an.',
            'Er ist der eine Griff dieses Panels, der nichts zurücklässt —',
            'und die Sicherung davor ist der Unterschied zwischen „wiederherstellbar" und „fort".',
        ]));

        $this->assertNotFalse($rueckbau, 'Der Rumpf reiht keinen Rückbau ein — dann misst dieser Fall nichts.');
        $this->assertLessThan($rueckbau, $sicherung, 'Die Sicherung steht hinter dem Rückbau — sie sichert dann einen leeren Baum.');
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
