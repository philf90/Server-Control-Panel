<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Enums\CertificateSource;
use App\Enums\FindingCheck;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Mail\Notice\BackupSection;
use App\Mail\Notice\CertificateSection;
use App\Mail\Notice\QuotaSection;
use App\Mail\Notice\Section;
use App\Models\Domain;
use App\Models\Finding;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Diagnose\Checks\Certificates;
use App\Support\Diagnose\Checks\LatestBackups;
use App\Support\Plans\Feature;
use App\Support\Plans\Quota;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use App\Support\Time\Clock;
use App\Support\Tls\CertificateChoice;
use App\Support\Tls\CertificateRenewal;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Was die Mail an den Kunden über einen Befund weiss, das nicht im Befund
 * steht (B9, `docs/142 §4`).
 *
 * **Ein Befund trägt Prüfung, Gegenstand, Grund und einen Satz.** Die Mail
 * braucht mehr: zu welchem Abonnement eine Domain gehört, woher ihr
 * Zertifikat kommt, was der letzte Versuch gemeldet hat, welche Sicherung
 * noch da ist und ob die nächste von selbst kommt. Gelesen wird das hier, beim
 * Zustellen, und nicht im Nachtlauf an den Befund geschrieben — ein Befund,
 * der seine Mail mitbrächte, wäre für Seite und Webhook dasselbe Feld in einer
 * Form, die keiner von beiden braucht.
 *
 * **Ohne Mandantenklammer, begründet:** Der Meldelauf hat kein Konto und
 * fragt für jeden Kunden. Im Grundzustand stünde jede Abfrage auf
 * `whereRaw('0 = 1')`, und das sähe aus wie „dieses Zertifikat gibt es
 * nicht".
 *
 * > **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer
 * > leeren Liste und nicht mit einem Fehler.**
 */
final class CustomerFacts
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly CertificateChoice $choice,
        private readonly Settings $settings,
    ) {}

    /**
     * Zu welchem Abonnement ein Befund des Kunden gehört — `null`, wenn der
     * Gegenstand fort ist.
     *
     * Kontingent und Sicherung nennen das Abonnement selbst; ein Zertifikat
     * nennt seine Domain.
     */
    public function subscriptionOf(FindingCheck $check, string $subject): ?string
    {
        if ($check !== FindingCheck::TlsExpiry) {
            return $subject;
        }

        return $this->withoutClamp(static function () use ($subject): ?string {
            $name = Domain::query()->where('name', $subject)->first()?->subscription?->name;

            return $name === null ? null : (string) $name;
        });
    }

    /**
     * Die Abschnitte einer Mail — je Art von Befund einer.
     *
     * @param  non-empty-list<Finding>  $findings  die Befunde eines Abonnements
     * @return non-empty-list<Section>
     */
    public function sections(string $subscription, array $findings): array
    {
        $je = [];

        foreach ($findings as $finding) {
            $je[$finding->check->value][] = $finding;
        }

        return $this->withoutClamp(function () use ($subscription, $je): array {
            $abo = Subscription::query()->with('plan')->where('name', $subscription)->first();
            $abschnitte = [];

            foreach ($je as $check => $befunde) {
                $abschnitte[] = match (FindingCheck::from($check)) {
                    FindingCheck::QuotaExceeded => new QuotaSection(self::overruns($befunde)),
                    FindingCheck::TlsExpiry => $this->certificates($abo, $befunde),
                    FindingCheck::BackupLatest => $this->backup($abo, $befunde[0]),
                    default => throw new LogicException(sprintf('Für „%s" gibt es keinen Abschnitt in der Mail an den Kunden.', $check)),
                };
            }

            return $abschnitte;
        });
    }

    /**
     * Die Zeilen über die Kontingente — Beschriftung und gemessener Wert.
     *
     * Die Beschriftung kommt aus {@see FindingCheck::sentence()} und nicht aus
     * einer zweiten Liste hier: Was der Betreiber auf der Diagnoseseite liest,
     * liest der Kunde in seiner Mail.
     *
     * @param  non-empty-list<Finding>  $findings
     * @return non-empty-list<array{reason: string, label: string, detail: string}>
     */
    private static function overruns(array $findings): array
    {
        return array_map(static fn (Finding $f): array => [
            'reason' => $f->reason,
            'label' => $f->check->sentence($f->reason),
            'detail' => (string) ($f->detail ?? '—'),
        ], $findings);
    }

    /**
     * Der Abschnitt über die Zertifikate.
     *
     * **Der Zeitpunkt kommt aus dem Befund** ({@see Certificates::validUntil()})
     * und nicht aus der Zeile im Bestand: Er ist das, was die Prüfung an der
     * Datei beurteilt hat. Die Herkunft kommt aus dem Bestand, denn nur dort
     * steht sie.
     *
     * @param  non-empty-list<Finding>  $findings
     */
    private function certificates(?Subscription $abo, array $findings): CertificateSection
    {
        $zeilen = [];

        foreach ($findings as $finding) {
            $domain = Domain::query()->where('name', $finding->subject)->first();
            $zertifikat = $domain === null ? null : $this->choice->effective($domain);
            $bis = Certificates::validUntil($finding->detail);

            $zeilen[] = [
                'domain' => $finding->subject,
                'reason' => $finding->reason,
                'valid_to' => self::when($bis) ?? '—',
                'renewed' => $zertifikat?->source === CertificateSource::Acme,
                'due_since' => self::when(CertificateRenewal::due($bis)),
                'last_attempt' => $domain === null ? null : self::lastAttempt($domain),
            ];
        }

        return new CertificateSection($zeilen, $abo?->plan?->feature(Feature::CertificateUpload) === true);
    }

    /**
     * Was der letzte Versuch gemeldet hat — `null`, wenn er nicht gescheitert
     * ist oder es keinen gab.
     *
     * **Am Vorgang und nicht an der Zeile des Zertifikats.** Eine Bestellung
     * legt keine Zeile an, und `certificates.last_error` wird nur auf `null`
     * geschrieben (`docs/142 §2`, Beobachtung). Den Grund trägt der Vorgang
     * `acme.certificate.issue` an der Domain, und den sieht der Kunde auch unter
     * „Vorgänge".
     */
    private static function lastAttempt(Domain $domain): ?string
    {
        $vorgang = Operation::query()
            ->where('type', 'acme.certificate.issue')
            ->where('subject_type', OperationSubject::Domain->value)
            ->where('subject_id', $domain->id)
            ->orderByDesc('id')
            ->first();

        if ($vorgang === null || $vorgang->status !== OperationStatus::Failed) {
            return null;
        }

        $meldung = trim((string) $vorgang->message);
        $wann = self::when($vorgang->finished_at);

        return trim(($wann ?? '').($meldung === '' ? '' : ($wann === null ? '' : ': ').$meldung)) ?: null;
    }

    /** Der Abschnitt über die Sicherung — ein Befund je Abonnement. */
    private function backup(?Subscription $abo, Finding $finding): BackupSection
    {
        $gescheitert = $abo === null ? null : LatestBackups::latest($abo, LatestBackups::FINISHED);
        $gelungen = $abo === null ? null : LatestBackups::latest($abo, LatestBackups::SUCCEEDED);

        /*
         * **„Kommt von selbst" nur, wenn sie kommt.** Dieselben beiden Fragen,
         * die der nächtliche Lauf stellt: Die Automatik ist an, und der Plan
         * bewahrt einen Stand auf. Eine Zusage ohne beide hielte niemand.
         */
        $automatisch = $this->settings->backups()['automatic'] === true
            && (int) ($abo?->quota(Quota::Backups->value) ?? 0) > 0;

        return new BackupSection(
            $finding->reason,
            self::when($gescheitert?->created_at),
            $gescheitert?->last_error,
            self::when($gelungen?->created_at),
            $automatisch,
        );
    }

    /**
     * Ein Zeitpunkt in der Anzeigezone und mit ihrer Zone.
     *
     * **Mit der Zone dieses Zeitpunkts**, nicht der von jetzt: Berlin heisst
     * im Januar `CET` und im Juli `CEST`, und eine Mail im Oktober über einen
     * Ablauf im November nennt sonst die falsche.
     */
    private static function when(?Carbon $at): ?string
    {
        if ($at === null) {
            return null;
        }

        $utc = $at->copy()->utc()->format('Y-m-d H:i:s');

        return trim((Clock::minute($utc) ?? '').' '.(Clock::labelAt($utc) ?? '')) ?: null;
    }

    /**
     * Die Mandantenklammer für diese eine Frage lösen.
     *
     * @template T
     *
     * @param  callable(): T  $frage
     * @return T
     */
    private function withoutClamp(callable $frage): mixed
    {
        $antwort = null;

        $this->tenancy->withoutRestriction(static function () use ($frage, &$antwort): void {
            $antwort = $frage();
        });

        return $antwort;
    }
}
