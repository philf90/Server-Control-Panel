<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Domain;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Was abläuft, wird erneuert — von selbst und ohne Ausfall.
 *
 * **Der Takt kommt vom Timer, nicht von einem Dauerlauf.** `srvpanel:tls`
 * läuft täglich und sieht nach; ein Zertifikat von Let's Encrypt gilt 90 Tage,
 * erneuert wird ab {@see self::LEAD_DAYS} Tagen Restlaufzeit. In dieser Frist
 * muss ein Server einmal gelaufen sein — das ist die Zahl, an der der tägliche
 * Takt hängt, und nicht die Laufzeit.
 *
 * **Ein Zertifikat, an dem keine Domain hängt, wird nicht erneuert.** Das ist
 * die Zeile, die diesen Lauf endlich macht: Beim Erneuern entsteht ein *neues*
 * Zertifikat, und die Domain zeigt danach darauf. Die alte Zeile bleibt als
 * Beleg stehen — ohne diese Bedingung wäre sie in alle Ewigkeit fällig, und
 * jeder Lauf bestellte sie neu, bis die Ratenbegrenzung zuschlägt.
 *
 * **Erst nachsehen, dann bestellen.** Ein Fehlschlag zwischen dem Ablegen der
 * Dateien und dem Eintrag im Bestand hinterlässt ein erneuertes Zertifikat,
 * von dem das Panel nichts weiss — es bestellte dann jeden Tag ein weiteres
 * und liefe in die Wochengrenze. `acme.certificate.info` beantwortet das,
 * ändert nichts und ist deshalb kein Vorgang.
 *
 * **Und nach einem Fehlversuch wird gewartet.** Produktiv sind fünf
 * Fehlversuche je Konto und Stunde die Grenze; wer es stündlich wieder
 * versucht, sperrt sich selbst aus. {@see self::RETRY_HOURS} steht deshalb
 * zwischen zwei Versuchen für dieselbe Zeile.
 */
final class CertificateRenewal
{
    /** Ab wieviel Restlaufzeit erneuert wird. */
    public const LEAD_DAYS = 30;

    /** Wie lange nach einem Versuch nicht wieder derselbe drankommt. */
    public const RETRY_HOURS = 6;

    /**
     * Wieviele Bestellungen ein Lauf höchstens abschickt.
     *
     * Nicht wegen der Last, sondern wegen der Zertifizierungsstelle: Let's
     * Encrypt zählt Ausstellungen je registrierbarer Domain und Woche. Ein
     * Server, auf dem hundert Domains am selben Tag fällig werden — nach einer
     * Übernahme etwa —, holt das über mehrere Tage auf, statt an einer Grenze
     * hängenzubleiben, hinter der auch die *neuen* Domains stehen.
     */
    public const PER_RUN = 10;

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly CertificateOrder $order,
        private readonly Client $agent,
        private readonly WildcardOrder $wildcards,
    ) {}

    /**
     * Wann ein Zertifikat mit dieser Laufzeit zur Erneuerung ansteht.
     *
     * Die eine Stelle, an der die Frist gerechnet wird — sie steht beim
     * Einspielen im Bestand ({@see CertificateLifecycle}) und wird hier
     * abgefragt. Zwei Rechnungen wären zwei Zahlen.
     */
    public static function due(?Carbon $notAfter): ?Carbon
    {
        return $notAfter?->copy()->subDays(self::LEAD_DAYS);
    }

    /** Ein Lauf: nachsehen, nachtragen, bestellen. */
    public function run(): RenewalReport
    {
        $report = $this->tenancy->withoutRestriction(fn (): RenewalReport => $this->sweep());

        // `withoutRestriction` gibt `mixed` zurück. Der Gegenstand ist genau
        // deshalb einer: So bleibt die Antwort benennbar.
        return $report instanceof RenewalReport ? $report : new RenewalReport;
    }

    private function sweep(): RenewalReport
    {
        $candidates = $this->candidates();
        $due = $candidates->count();
        $ordered = 0;
        $corrected = 0;
        $blocked = 0;

        foreach ($candidates as $certificate) {
            if ($ordered + $corrected >= self::PER_RUN) {
                break;
            }

            $wildcard = $certificate->isWildcard();
            $domain = $this->renewalDomain($certificate, $wildcard);

            if (! $domain instanceof Domain) {
                continue;
            }

            if ($this->alreadyRenewed($certificate, $domain)) {
                $corrected++;

                continue;
            }

            // **Ein Platzhalter, der jetzt nicht als Platzhalter bestellt werden
            // kann, wird gar nicht bestellt.** Die Zugangsdaten können seit der
            // Ausstellung fort sein; ihn dann als gewöhnliches Zertifikat
            // nachzuholen hiesse, eine Zone stillschweigend auf einen Namen zu
            // verkleinern.
            if ($wildcard && ! $this->wildcards->possible($domain)) {
                $blocked++;

                continue;
            }

            if ($this->order->place($domain, wildcard: $wildcard) === null) {
                // Ohne Kontaktadresse bestellt das Panel nichts. Ein Versuch,
                // der nie stattfand, darf auch keine Frist verbrauchen.
                continue;
            }

            $certificate->last_attempt_at = now();
            $certificate->save();

            $ordered++;
        }

        return new RenewalReport(
            due: $due,
            ordered: $ordered,
            corrected: $corrected,
            left: max(0, $due - $ordered - $corrected - $blocked),
            blocked: $blocked,
        );
    }

    /**
     * Welche Domain diese Erneuerung bestellt — und ob als Platzhalter.
     *
     * **Der Fund aus dem Abnahmelauf, 7. August 2026.** Bis dahin stand hier
     * `$certificate->domains()->orderBy('id')->first()` und die Bestellung ging
     * ohne `wildcard:` hinaus. Ein Platzhalter wurde damit als **gewöhnliches**
     * Zertifikat erneuert: Auf `cloudlab24.ipv64.de` trug das erneuerte nur noch
     * den Namen der Hauptdomain, und die drei Unterdomains lieferten weiter das
     * alte aus — bis es abläuft und der Browser bei jeder von ihnen warnt.
     *
     * **Der Fehler wäre still und käme mit neunzig Tagen Verzögerung.** Im
     * Panel sieht ein erneuertes Zertifikat aus wie ein erneuertes Zertifikat;
     * dass es eine Zone weniger deckt als vorher, steht nirgends.
     *
     * **Die Basisdomain kommt aus dem Namen und nicht aus der Zuordnung.**
     * `*.example.de` gehört zu `example.de`, und das gilt auch dann, wenn an
     * dem Zertifikat gerade nur Unterdomains hängen — nach einer Wahl etwa.
     * Über die Zuordnung zu gehen hiesse, `*.a.example.de` zu bestellen, weil
     * `a.example.de` die kleinste Kennung hatte.
     */
    private function renewalDomain(Certificate $certificate, bool $wildcard): ?Domain
    {
        if (! $wildcard) {
            return $certificate->domains()->orderBy('id')->first();
        }

        foreach ($certificate->coveredNames() as $name) {
            if (! str_starts_with($name, '*.')) {
                continue;
            }

            $base = Domain::query()
                ->where('subscription_id', $certificate->subscription_id)
                ->where('name', substr($name, 2))
                ->first();

            if ($base instanceof Domain && WildcardOrder::isBase($base)) {
                return $base;
            }
        }

        return null;
    }

    /**
     * Die fälligen Zertifikate.
     *
     * @return Collection<int, Certificate>
     */
    private function candidates(): Collection
    {
        return Certificate::query()
            ->where('source', CertificateSource::Acme)
            ->where('status', CertificateStatus::Active)

            // Siehe die Klassenbeschreibung: Ohne Domain kein Server-Block,
            // der es ausliefert — und ohne diese Zeile kein Ende.
            ->whereHas('domains')
            ->where(fn (Builder $query) => $query
                ->whereNull('renew_after')
                ->orWhere('renew_after', '<=', now()))
            ->where(fn (Builder $query) => $query
                ->whereNull('last_attempt_at')
                ->orWhere('last_attempt_at', '<=', now()->subHours(self::RETRY_HOURS)))
            ->orderBy('not_after')
            ->get();
    }

    /**
     * Liegt für diese Domain längst ein neueres Zertifikat?
     *
     * Dann wird der Bestand nachgetragen statt bestellt. Der Fall entsteht,
     * wenn ein Lauf zwischen dem Ablegen der Dateien und dem Eintrag abbricht
     * — selten, aber teuer: Ohne diese Frage bestellte das Panel jeden Tag
     * eines und liefe in die Wochengrenze der Zertifizierungsstelle.
     */
    private function alreadyRenewed(Certificate $certificate, Domain $domain): bool
    {
        try {
            $info = $this->agent->call(
                'acme.certificate.info',
                // **Gefragt wird nach dem Ablageort und nicht nach der Domain.**
                // Bis zum zweiten Wurf von P4 war beides dasselbe; seit ein
                // Platzhalter unter `_wildcard.example.de` liegt, ist es das
                // nicht mehr — und die Antwort wäre „liegt nichts da", worauf
                // dieser Lauf jeden Tag neu bestellte.
                ['name' => $certificate->storage_name ?? $domain->name],
                ['source' => 'cli', 'command' => 'srvpanel:tls'],
            );
        } catch (AgentException) {
            // Keine Auskunft ist kein Grund, nicht zu erneuern — im
            // Zweifelsfall lieber ein Zertifikat zuviel als eine abgelaufene
            // Domain. Die Ratenbegrenzung fängt der Abstand oben ab.
            return false;
        }

        return $this->adopt($certificate, $info);
    }

    /**
     * Die Auskunft des Agenten in den Bestand übernehmen — wenn sie neuer ist.
     *
     * **Getrennt vom Aufruf, damit die Regel prüfbar ist.** Was hier
     * entschieden wird, hängt an Zahlen aus einer Antwort; der Weg dorthin
     * hängt an einem Socket, den dieser Container nicht hat. Geprüft wird
     * deshalb die Entscheidung und nicht die Leitung — dieselbe
     * Zuschnittsentscheidung wie bei den nginx-Vorlagen, die als Text geprüft
     * werden.
     *
     * **Nur neuer, nie älter.** Ein Zertifikat, das im Ablageort *kürzer*
     * gilt als im Bestand, ist kein Grund, die Frist vorzuziehen: Dann liegt
     * dort etwas anderes, und was zählt, ist die Erneuerung.
     *
     * @param  array<string, mixed>  $info
     */
    public function adopt(Certificate $certificate, array $info): bool
    {
        if (($info['present'] ?? false) !== true || $certificate->not_after === null) {
            return false;
        }

        $validTo = is_int($info['valid_to'] ?? null) ? $info['valid_to'] : 0;

        if ($validTo <= $certificate->not_after->getTimestamp()) {
            return false;
        }

        $notAfter = Carbon::createFromTimestamp($validTo);
        $validFrom = is_int($info['valid_from'] ?? null) ? $info['valid_from'] : 0;
        $issuer = $info['issuer'] ?? null;

        if ($validFrom > 0) {
            $certificate->not_before = Carbon::createFromTimestamp($validFrom);
        }

        if (is_string($issuer) && $issuer !== '') {
            $certificate->issuer = $issuer;
        }

        $certificate->not_after = $notAfter;
        $certificate->renew_after = self::due($notAfter);
        $certificate->last_error = null;
        $certificate->save();

        return true;
    }
}
