<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CertificateSource;
use App\Enums\FindingCheck;
use App\Enums\FindingState;
use App\Enums\OperationStatus;
use App\Mail\CustomerNotice;
use App\Mail\Notice\BackupSection;
use App\Mail\Notice\CertificateSection;
use App\Mail\Notice\QuotaSection;
use App\Models\Backup;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Diagnose\Checks\Certificates;
use App\Support\Diagnose\Checks\LatestBackups;
use App\Support\Notify\CustomerFacts;
use App\Support\Notify\MailChannel;
use App\Support\Plans\Feature;
use App\Support\Plans\Quota;
use App\Support\Plans\Quotas;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use App\Support\Time\Clock;
use Database\Factories\FindingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

/**
 * Was die Mail an den Kunden über Zertifikate und Sicherung sagt — und dass es
 * eine Mail bleibt (B9, `docs/142 §8`).
 *
 * Die Kontingente hält {@see QuotaNoticeTest}; dieser Wächter hält die beiden
 * Abschnitte, die B9 dazubringt, und die Mail, die alle drei zusammensetzt.
 *
 * ## Was er hält
 *
 * Jeder Grund, der beim Kunden ankommen kann, hat eine Überschrift, und jede
 * Prüfung des Kunden einen Abschnitt. Je Domain steht der schwerere Grund.
 * Was der Kunde tun kann, folgt der Herkunft des Zertifikats; was von den
 * Sicherungen noch da ist, steht da, und „kommt von selbst" nur, wenn sie
 * kommt; wo der Grund steht, nennt die Mail so wie das Menü des Kunden.
 * Keine Zeile ist länger als 77 Zeichen, die Abschnitte stehen in
 * fester Reihenfolge, und der Satz über „einmal je Zustand" steht einmal.
 *
 * **Und die Angaben kommen aus dem Befund und dem Bestand**, gemessen durch
 * {@see CustomerFacts} gegen eine Datenbank — in einer Zone mit Versatz, denn
 * in UTC sähe eine fehlende Umrechnung aus wie eine gelungene.
 */
final class CustomerNoticeTest extends TestCase
{
    use RefreshDatabase;

    /** Ein Name, der die Einleitung bricht — gewöhnliche sind kürzer. */
    private const LANG = 'kunde-mit-einem-ziemlich-langen-namen.example.invalid';

    protected function tearDown(): void
    {
        // Die gemerkte Zone überlebt `RefreshDatabase`.
        Clock::forget();

        parent::tearDown();
    }

    /**
     * Eine Zeile über ein Zertifikat, wie `CustomerFacts` sie baut.
     *
     * **Fälligkeit und Versuch stehen auch an einem hochgeladenen**, wie
     * `CustomerFacts` sie für jedes Zertifikat ausrechnet: Ob sie in die Mail
     * gehören, entscheidet der Abschnitt und nicht der Aufrufer.
     *
     * @return array{domain: string, reason: string, valid_to: string, renewed: bool, due_since: null|string, last_attempt: null|string}
     */
    private static function zertifikat(string $domain, string $grund, bool $erneuert = true): array
    {
        return [
            'domain' => $domain,
            'reason' => $grund,
            'valid_to' => '2026-11-22 12:00 CET (UTC+01:00)',
            'renewed' => $erneuert,
            'due_since' => '2026-10-23 13:00 CEST (UTC+02:00)',
            'last_attempt' => '2026-10-25 01:12 CEST (UTC+02:00): Die Prüfdatei war nicht erreichbar.',
        ];
    }

    private static function sicherung(?string $gelungen = '2026-10-06 03:31 CEST (UTC+02:00)', bool $automatisch = true): BackupSection
    {
        return new BackupSection('failed', '2026-10-07 03:31 CEST (UTC+02:00)', 'Zu wenig Platz für die Sicherung', $gelungen, $automatisch);
    }

    /** Der Rumpf ohne Zeilenumbrüche — die Absätze sind gebrochen. */
    private static function fliesstext(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Die Gründe einer Prüfung, die beim Kunden ankommen können — aus der
     * Prüfung und dem Katalog und nicht aus einer Liste hier.
     *
     * @param  array<string, list<string>>  $reasons
     * @return non-empty-list<string>
     */
    private static function meldbar(FindingCheck $check, array $reasons): array
    {
        $gruende = array_values(array_filter(
            $reasons[$check->value],
            static fn (string $grund): bool => $check->state($grund) !== FindingState::Unknown,
        ));

        self::assertNotSame([], $gruende, $check->value.': kein Grund gefunden — dann prüft dieser Fall nichts.');

        return $gruende;
    }

    /**
     * Jeder Grund, der ankommen kann, hat eine Überschrift — und der Rückfall
     * wirft, statt still etwas zu schreiben.
     */
    public function test_every_reason_a_customer_can_get_has_a_headline(): void
    {
        foreach (self::meldbar(FindingCheck::TlsExpiry, Certificates::REASONS) as $grund) {
            $betreff = (string) (new CustomerNotice('p1000', [new CertificateSection([self::zertifikat('a.example', $grund)], false)]))->envelope()->subject;

            self::assertStringEndsWith(CertificateSection::headline($grund).': p1000', $betreff, $grund);
        }

        foreach (self::meldbar(FindingCheck::BackupLatest, LatestBackups::REASONS) as $grund) {
            self::assertSame(['Sicherung fehlgeschlagen'], (new BackupSection($grund, null, null, null, false))->headlines());
        }

        foreach ([static fn () => CertificateSection::headline('erfunden'), static fn () => BackupSection::headline('erfunden')] as $rueckfall) {
            try {
                $rueckfall();
                self::fail('Ein Grund ohne Überschrift hat eine bekommen.');
            } catch (LogicException) {
                // So soll es sein.
            }
        }
    }

    /**
     * Jede Prüfung des Kunden hat einen Abschnitt.
     *
     * **Gefragt wird der Mailkanal und nicht eine Liste hier.** Wer eine
     * Prüfung dem Kunden zuschlägt und keinen Abschnitt baut, liesse den
     * Meldelauf erst im Nachtlauf werfen.
     */
    public function test_every_check_of_the_customer_has_a_section(): void
    {
        $abo = $this->abonnement();
        $facts = app(CustomerFacts::class);

        foreach (MailChannel::CUSTOMER as $check) {
            $befund = FindingFactory::new()->make([
                'check' => $check,
                'subject' => $check === FindingCheck::TlsExpiry ? 'p1000.example' : 'p1000',
                'reason' => self::meldbar($check, [
                    'quota.exceeded' => ['disk_over'],
                    ...Certificates::REASONS,
                    ...LatestBackups::REASONS,
                ])[0],
                'detail' => 'gültig bis 2026-11-22 11:00 UTC',
            ]);

            $abschnitte = $facts->sections((string) $abo->name, [$befund]);

            self::assertCount(1, $abschnitte, $check->value);
        }
    }

    /**
     * Je Domain nennt die Mail „abgelaufen" und nicht beides — und eine zweite
     * Domain, die erst abläuft, bleibt daneben stehen.
     */
    public function test_an_expired_certificate_is_named_alone(): void
    {
        $mail = new CustomerNotice('p1000', [new CertificateSection([
            self::zertifikat('a.example', 'expiring'),
            self::zertifikat('a.example', 'expired'),
            self::zertifikat('b.example', 'expiring'),
        ], false)]);

        $text = $mail->render();

        self::assertSame(1, substr_count($text, FindingCheck::TlsExpiry->sentence('expired')));
        self::assertSame(1, substr_count($text, FindingCheck::TlsExpiry->sentence('expiring')), 'Die zweite Domain läuft erst ab — sie bleibt.');
        self::assertStringEndsWith('Zertifikat abgelaufen und Zertifikat läuft ab: p1000', (string) $mail->envelope()->subject);
        self::assertStringContainsString('lehnen Browser ab', self::fliesstext($text));
    }

    /**
     * Was der Kunde tun kann, folgt der Herkunft — in beide Richtungen.
     *
     * Let's Encrypt nennt Fälligkeit und letzten Versuch und lässt neu
     * anstossen; ein hochgeladenes erneuert niemand, und ob der Kunde selbst
     * hochladen darf, entscheidet sein Plan.
     */
    public function test_what_the_customer_can_do_follows_the_source(): void
    {
        $le = self::fliesstext((new CustomerNotice('p1000', [new CertificateSection([self::zertifikat('a.example', 'expiring')], false)]))->render());

        self::assertStringContainsString('Erneuerung fällig seit: 2026-10-23 13:00 CEST (UTC+02:00)', $le);
        self::assertStringContainsString('Letzter Versuch: 2026-10-25 01:12 CEST (UTC+02:00): Die Prüfdatei war nicht erreichbar.', $le);
        self::assertStringContainsString('die Bestellung sofort neu anstossen', $le);
        self::assertStringNotContainsString('hochgeladen', $le);

        $selbst = self::fliesstext((new CustomerNotice('p1000', [new CertificateSection([self::zertifikat('a.example', 'expiring', erneuert: false)], true)]))->render());

        self::assertStringContainsString('Ein hochgeladenes Zertifikat erneuert das Panel nicht', $selbst);
        self::assertStringContainsString('Sie können es auf der Seite der Domain im Panel hochladen.', $selbst);
        self::assertStringNotContainsString('Erneuerung fällig seit', $selbst, 'Ein hochgeladenes wird nicht erneuert — eine Fälligkeit wäre erfunden.');
        self::assertStringNotContainsString('Letzter Versuch', $selbst);
        self::assertStringNotContainsString('neu anstossen', $selbst);

        $anbieter = self::fliesstext((new CustomerNotice('p1000', [new CertificateSection([self::zertifikat('a.example', 'expiring', erneuert: false)], false)]))->render());

        self::assertStringContainsString('Hochladen kann es Ihr Anbieter.', $anbieter);
        self::assertStringNotContainsString('Sie können es', $anbieter, 'Der Plan erlaubt das Hochladen nicht.');
    }

    /** Die Sicherung nennt, was noch da ist, und „von selbst" nur, wenn es kommt. */
    public function test_the_backup_section_names_what_is_left(): void
    {
        $mit = self::fliesstext((new CustomerNotice('p1000', [self::sicherung()]))->render());

        self::assertStringContainsString('Erstellt: 2026-10-07 03:31 CEST (UTC+02:00)', $mit);
        self::assertStringContainsString('Meldung: Zu wenig Platz für die Sicherung', $mit);
        self::assertStringContainsString('Die jüngste gelungene Sicherung dieses Abonnements ist vom 2026-10-06 03:31 CEST (UTC+02:00).', $mit);
        self::assertStringContainsString('in der kommenden Nacht von selbst', $mit);

        $ohne = self::fliesstext((new CustomerNotice('p1000', [self::sicherung(gelungen: null, automatisch: false)]))->render());

        self::assertStringContainsString('Eine gelungene Sicherung dieses Abonnements gibt es nicht.', $ohne);
        self::assertStringNotContainsString('von selbst', $ohne, 'Ohne Automatik kommt keine — die Zusage hielte niemand.');
    }

    /**
     * Die Sicherung sagt, wo der Grund steht — unter „Vorgänge", so wie das
     * Menü des Kunden die Liste nennt, und in jeder Lage.
     *
     * **Den Grund eines gescheiterten Dumps trägt der Vorgang davor** und
     * nicht die Mail (`docs/143 §7`). Entschieden hat der Betreiber am
     * 10. Oktober 2026, dass ein Satz dorthin zeigt.
     *
     * **Der Name kommt aus `PanelLayout.vue`** und nicht aus diesem Test, und
     * zwar aus dem Zweig des Kunden. Der Betreiber hat einen Menüpunkt mit
     * derselben Adresse; über die ganze Datei gelesen, bliebe ein umbenannter
     * Punkt beim Kunden neben dem alten beim Betreiber unbemerkt.
     */
    public function test_the_backup_section_points_to_the_operations_as_the_menu_names_them(): void
    {
        $layout = (string) file_get_contents(resource_path('js/Layouts/PanelLayout.vue'));

        $anfang = strpos($layout, 'is_admin === false');
        self::assertNotFalse($anfang, 'Der Zweig des Kunden ist in PanelLayout.vue nicht gefunden worden.');

        $ende = strpos($layout, "\n  return [", $anfang);
        self::assertNotFalse($ende, 'Das Ende des Zweigs des Kunden ist in PanelLayout.vue nicht gefunden worden.');

        self::assertSame(
            1,
            preg_match_all("/\\{\\s*name:\\s*'([^']+)',\\s*href:\\s*'\\/operations'/", substr($layout, $anfang, $ende - $anfang), $eintraege),
            'Im Menü des Kunden steht für /operations kein Punkt oder mehr als einer.',
        );

        foreach ([self::sicherung(), self::sicherung(gelungen: null, automatisch: false)] as $abschnitt) {
            $text = self::fliesstext((new CustomerNotice('p1000', [$abschnitt]))->render());

            self::assertSame(1, preg_match_all('/unter\s+„([^"]+)"/u', $text, $genannt), 'Die Mail sagt nicht, wo der Grund steht.');
            self::assertSame($eintraege[1][0], $genannt[1][0], 'Die Mail nennt die Liste anders als das Menü des Kunden.');
        }
    }

    /**
     * Keine Zeile ist länger als 77 Zeichen — mit allen drei Abschnitten und
     * einem langen Namen, weil eine Mail mit einem Grund und `p1000` jede
     * Grenze einhält.
     */
    public function test_no_line_is_longer_than_77_characters(): void
    {
        $text = (new CustomerNotice(self::LANG, [
            new QuotaSection([['reason' => 'traffic_over', 'label' => FindingCheck::QuotaExceeded->sentence('traffic_over'), 'detail' => '12 GB von 10 GB in diesem Monat']]),
            new CertificateSection([self::zertifikat('www.'.self::LANG, 'expired')], true),
            self::sicherung(),
        ]))->render();

        $zuLang = [];

        foreach (explode("\n", $text) as $zeile) {
            if (mb_strlen($zeile) > 77) {
                $zuLang[] = sprintf('%d: %s', mb_strlen($zeile), $zeile);
            }
        }

        self::assertStringContainsString(self::LANG, $text, 'Der lange Name steht nicht in der Mail — dann misst dieser Fall nichts.');
        self::assertSame([], $zuLang, "Diese Zeilen bricht ein Mailprogramm, wo es will:\n".implode("\n", $zuLang));
    }

    /**
     * Die Abschnitte stehen in fester Reihenfolge, und der Satz über „einmal je
     * Zustand" steht einmal — gleich wie viele Abschnitte.
     */
    public function test_the_sections_stand_in_order_and_the_closing_once(): void
    {
        // Fliesstext, weil ein Satz in der Mail bricht und `strpos()` über
        // einen Umbruch hinweg nichts fände.
        $text = self::fliesstext((new CustomerNotice('p1000', [
            self::sicherung(),
            new CertificateSection([self::zertifikat('a.example', 'expiring')], false),
            new QuotaSection([['reason' => 'disk_over', 'label' => FindingCheck::QuotaExceeded->sentence('disk_over'), 'detail' => '500 MB von 500 MB']]),
        ]))->render());

        $platz = strpos($text, FindingCheck::QuotaExceeded->sentence('disk_over'));
        $zertifikat = strpos($text, FindingCheck::TlsExpiry->sentence('expiring'));
        $sicherung = strpos($text, FindingCheck::BackupLatest->sentence('failed'));

        self::assertNotFalse($platz);
        self::assertNotFalse($zertifikat);
        self::assertNotFalse($sicherung);
        self::assertTrue($platz < $zertifikat && $zertifikat < $sicherung, 'Kontingente, Zertifikate, Sicherung — in dieser Reihenfolge.');
        self::assertSame(1, substr_count($text, 'Sie bekommen diese Nachricht einmal je Zustand.'));
    }

    /**
     * Die Angaben kommen aus Befund und Bestand — und stehen in der
     * Anzeigezone.
     *
     * **Der Zeitpunkt des Zertifikats kommt aus dem Befund**, nicht aus
     * `not_after` im Bestand: Der Prüfkörper trägt dort absichtlich einen
     * anderen Tag. Die Herkunft kommt aus dem Bestand, der letzte Versuch aus
     * dem Vorgang an der Domain.
     */
    public function test_the_facts_come_from_the_finding_and_the_inventory(): void
    {
        Clock::store('Europe/Berlin');
        app(Settings::class)->saveBackups(automatic: true, beforeRemoval: true);

        $abo = $this->abonnement(hochladen: true, sicherungen: 3);

        app(Tenancy::class)->withoutRestriction(function () use ($abo): void {
            $domain = Domain::query()->where('name', 'p1000.example')->sole();

            $zertifikat = Certificate::factory()->covering(['p1000.example'])->create([
                'subscription_id' => $abo->id,
                'source' => CertificateSource::Acme,
                'not_after' => Carbon::parse('2027-01-01 00:00:00', 'UTC'),
            ]);
            $domain->forceFill(['certificate_id' => $zertifikat->id])->save();

            Operation::factory()->create([
                'type' => 'acme.certificate.issue',
                'subject_type' => 'domain',
                'subject_id' => $domain->id,
                'status' => OperationStatus::Failed,
                'message' => 'Die Prüfdatei war nicht erreichbar.',
                'finished_at' => Carbon::parse('2026-10-25 00:12:00', 'UTC'),
            ]);

            $gelungen = Backup::factory()->forSubscription($abo)->create();
            $gelungen->forceFill(['created_at' => Carbon::parse('2026-10-06 01:31:00', 'UTC')])->save();
            $gescheitert = Backup::factory()->forSubscription($abo)->failed('Zu wenig Platz für die Sicherung')->create();
            $gescheitert->forceFill(['created_at' => Carbon::parse('2026-10-07 01:31:00', 'UTC')])->save();
        });

        $befunde = [
            FindingFactory::new()->make(['check' => FindingCheck::TlsExpiry, 'subject' => 'p1000.example', 'reason' => 'expiring', 'detail' => 'gültig bis 2026-11-22 11:00 UTC']),
            FindingFactory::new()->make(['check' => FindingCheck::BackupLatest, 'subject' => 'p1000', 'reason' => 'failed', 'detail' => 'egal']),
        ];

        $facts = app(CustomerFacts::class);

        self::assertSame('p1000', $facts->subscriptionOf(FindingCheck::TlsExpiry, 'p1000.example'));
        self::assertNull($facts->subscriptionOf(FindingCheck::TlsExpiry, 'fort.example'), 'Eine Domain, die es nicht gibt, hat kein Abonnement.');

        $text = self::fliesstext((new CustomerNotice('p1000', $facts->sections('p1000', $befunde)))->render());

        self::assertStringContainsString('Gültig bis: 2026-11-22 12:00 CET (UTC+01:00)', $text, 'Der Tag aus dem Befund, im November mit CET.');
        self::assertStringNotContainsString('2027-01-01', $text, 'Der Tag aus dem Bestand stand in der Mail.');
        self::assertStringContainsString('Erneuerung fällig seit: 2026-10-23 13:00 CEST (UTC+02:00)', $text);
        self::assertStringContainsString('Letzter Versuch: 2026-10-25 02:12 CEST (UTC+02:00): Die Prüfdatei war nicht erreichbar.', $text);
        self::assertStringContainsString('Erstellt: 2026-10-07 03:31 CEST (UTC+02:00)', $text);
        self::assertStringContainsString('Die jüngste gelungene Sicherung dieses Abonnements ist vom 2026-10-06 03:31 CEST (UTC+02:00).', $text);
        self::assertStringContainsString('in der kommenden Nacht von selbst', $text);
    }

    /**
     * Genannt wird nur ein gescheiterter letzter Versuch.
     *
     * **Der jüngste Vorgang an der Domain zählt**, und ist er gelungen, steht
     * kein Versuch in der Mail — auch wenn davor einer gescheitert ist. Ein
     * gelungener Satz unter „Letzter Versuch" läse sich wie der Grund, und der
     * ältere gescheiterte wäre eine Auskunft von gestern.
     *
     * **Das Zertifikat ist von Let's Encrypt, sonst misst der Fall nichts.**
     * „Letzter Versuch" steht nur bei einem Zertifikat, das das Panel selbst
     * erneuert. Im ersten Wurf hatte die Domain gar keines; die Zeile fehlte
     * dann in jeder Fassung, und beide Eingriffe an der Abfrage blieben grün.
     * Deshalb steht die Gegenprobe am Ende: Scheitert der nächste Versuch,
     * steht er da.
     */
    public function test_only_a_failed_last_attempt_is_named(): void
    {
        $abo = $this->abonnement();

        $domain = app(Tenancy::class)->withoutRestriction(static function () use ($abo): Domain {
            $domain = Domain::query()->where('name', 'p1000.example')->sole();

            $zertifikat = Certificate::factory()->covering(['p1000.example'])->create([
                'subscription_id' => $abo->id,
                'source' => CertificateSource::Acme,
            ]);
            $domain->forceFill(['certificate_id' => $zertifikat->id])->save();

            foreach ([[OperationStatus::Failed, 'Die Prüfdatei war nicht erreichbar.'], [OperationStatus::Succeeded, 'Das Zertifikat ist ausgestellt.']] as [$zustand, $meldung]) {
                Operation::factory()->create([
                    'type' => 'acme.certificate.issue',
                    'subject_type' => 'domain',
                    'subject_id' => $domain->id,
                    'status' => $zustand,
                    'message' => $meldung,
                    'finished_at' => Carbon::parse('2026-10-25 00:12:00', 'UTC'),
                ]);
            }

            return $domain;
        });

        $befund = FindingFactory::new()->make(['check' => FindingCheck::TlsExpiry, 'subject' => 'p1000.example', 'reason' => 'expiring', 'detail' => 'gültig bis 2026-11-22 11:00 UTC']);
        $text = self::fliesstext((new CustomerNotice('p1000', app(CustomerFacts::class)->sections('p1000', [$befund])))->render());

        self::assertStringContainsString('Erneuerung fällig seit', $text,
            'Die Zeilen der Erneuerung fehlen — dann kann „Letzter Versuch" gar nicht dastehen, und dieser Fall misst nichts.');
        self::assertStringNotContainsString('Letzter Versuch', $text);
        self::assertStringNotContainsString('ausgestellt', $text);
        self::assertStringNotContainsString('nicht erreichbar', $text, 'Der ältere Fehlschlag stand statt des letzten Vorgangs da.');

        app(Tenancy::class)->withoutRestriction(static function () use ($domain): void {
            Operation::factory()->create([
                'type' => 'acme.certificate.issue',
                'subject_type' => 'domain',
                'subject_id' => $domain->id,
                'status' => OperationStatus::Failed,
                'message' => 'Die Bestellung wurde abgewiesen.',
                'finished_at' => Carbon::parse('2026-10-26 00:12:00', 'UTC'),
            ]);
        });

        $text = self::fliesstext((new CustomerNotice('p1000', app(CustomerFacts::class)->sections('p1000', [$befund])))->render());

        self::assertStringContainsString('Letzter Versuch', $text,
            'Dieselbe Domain mit einem gescheiterten letzten Versuch: Fehlt die Zeile hier, sagt ihr Fehlen oben nichts.');
        self::assertStringContainsString('Die Bestellung wurde abgewiesen.', $text);
        self::assertStringNotContainsString('nicht erreichbar', $text, 'Der ältere Fehlschlag stand statt des letzten Vorgangs da.');
    }

    /**
     * „Kommt von selbst" nur, wenn der Plan einen Stand aufbewahrt — die
     * Automatik allein reicht nicht.
     *
     * Dieselbe Bedingung, die der nächtliche Lauf stellt: Ein Abonnement, das
     * keinen Stand behalten darf, bekommt keine automatische Sicherung, auch
     * wenn sie eingeschaltet ist.
     */
    public function test_no_promise_without_a_kept_backup(): void
    {
        app(Settings::class)->saveBackups(automatic: true, beforeRemoval: true);
        $abo = $this->abonnement(sicherungen: 0);

        app(Tenancy::class)->withoutRestriction(static function () use ($abo): void {
            Backup::factory()->forSubscription($abo)->failed('Zu wenig Platz für die Sicherung')->create();
        });

        $befund = FindingFactory::new()->make(['check' => FindingCheck::BackupLatest, 'subject' => 'p1000', 'reason' => 'failed', 'detail' => 'egal']);
        $text = self::fliesstext((new CustomerNotice('p1000', app(CustomerFacts::class)->sections('p1000', [$befund])))->render());

        self::assertStringContainsString('Zu wenig Platz für die Sicherung', $text, 'Der Abschnitt fehlt — dann misst dieser Fall nichts.');
        self::assertStringNotContainsString('von selbst', $text, 'Die Automatik ist an, aber der Plan bewahrt nichts auf — die Zusage hielte niemand.');
    }

    /**
     * Ein Abonnement `p1000` mit Domain `p1000.example`.
     */
    private function abonnement(bool $hochladen = false, int $sicherungen = 0): Subscription
    {
        return app(Tenancy::class)->withoutRestriction(static function () use ($hochladen, $sicherungen): Subscription {
            $features = Quotas::featureDefaults();
            $features[Feature::CertificateUpload->value] = $hochladen;
            $features[Feature::Backups->value] = true;

            $abo = Subscription::factory()->create([
                'name' => 'p1000',
                'plan_id' => Plan::factory()->create(['features' => $features])->id,
                'quota_overrides' => [Quota::Backups->value => $sicherungen],
            ]);
            Domain::factory()->create(['subscription_id' => $abo->id, 'name' => 'p1000.example']);

            return $abo;
        });
    }
}
