<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Jobs\RunAgentOperation;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Support\Operations\AfterOperation;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\WebLifecycle;
use Illuminate\Support\Carbon;

/**
 * Der Lebenslauf eines Zertifikats — und die Regel, die sonst niemand bemerkt.
 *
 * **Wer ein Zertifikat einspielt, schreibt danach den Server-Block neu.**
 * `docs/32 §8` nennt das die Falle, die man übersieht: Der Block entsteht bei
 * `web.site.apply`, und ob `Strict-Transport-Security` darin steht, entscheidet
 * sich am Zertifikat, das dabei gelesen wird. Wer also ein vertrautes
 * Zertifikat ablegt und die Operation nicht ruft, bekommt ein vertrautes
 * Zertifikat **ohne** den Header — und nichts bricht ab. Das ist der harmlose
 * der beiden Ausgänge, und genau deshalb bemerkt ihn niemand.
 *
 * **Und die Gegenrichtung: bestellt wird von selbst.** Das Abnahmekriterium
 * der Stufe verlangt, dass ein Kunde ohne Zutun des Admins ein Zertifikat
 * bekommt. Der Auslöser ist deshalb kein Knopf, sondern der Server-Block: Steht
 * er, ist die Domain über Port 80 erreichbar, und erst dann kann die Prüfung
 * gelingen.
 *
 * **Die beiden zusammen wären eine Schleife**, und das ist der Teil, den man
 * beim Schreiben nicht sieht: Bestellung → Zuordnung → Block neu → Bestellung.
 * Sie hört auf, weil {@see self::request()} nur bestellt, wenn die Domain noch
 * kein Zertifikat hat, und die Zuordnung **vor** dem neuen Block passiert. Ein
 * eigener Testdurchgang steht dafür ein.
 */
final class CertificateLifecycle implements AfterOperation
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly CertificateOrder $order,
    ) {}

    /** @return list<string> */
    public static function handles(): array
    {
        return ['acme.certificate.issue', 'web.site.apply'];
    }

    public function afterSuccess(Operation $operation): void
    {
        if ($operation->subject_type !== OperationSubject::Domain->value || $operation->subject_id === null) {
            return;
        }

        $this->tenancy->withoutRestriction(function () use ($operation): void {
            $domain = Domain::query()->find($operation->subject_id);

            if (! $domain instanceof Domain) {
                return;
            }

            match ($operation->task) {
                'acme.certificate.issue' => $this->install($operation, $domain),
                'web.site.apply' => $this->request($domain, $operation),
                default => null,
            };
        });
    }

    /**
     * Das ausgestellte Zertifikat in den Bestand nehmen — und den Block neu.
     *
     * Die Reihenfolge ist die Aussage: erst zuordnen, dann anwenden. Andersherum
     * schriebe der Agent einen Block für ein Zertifikat, das das Panel noch
     * nicht kennt.
     */
    private function install(Operation $operation, Domain $domain): void
    {
        $result = $operation->result ?? [];
        $names = $this->names($result, $domain);

        if ($names === []) {
            return;
        }

        $notAfter = $this->moment($result, 'not_after');

        $certificate = new Certificate([
            'names' => $names,
            'status' => CertificateStatus::Active,
            'source' => CertificateSource::Acme,
            'issuer' => $this->text($result, 'issuer'),
            'serial' => $this->text($result, 'serial'),
            'not_before' => $this->moment($result, 'not_before'),
            'not_after' => $notAfter,
            'last_error' => null,
            'last_attempt_at' => now(),

            // Die Frist wird hier eingetragen und nicht beim Nachsehen
            // gerechnet: Ein Zertifikat ohne Termin wäre eines, das erst
            // auffällt, wenn ein Browser es meldet.
            'renew_after' => CertificateRenewal::due($notAfter),
        ]);

        $certificate->subscription_id = $domain->subscription_id;
        $certificate->save();

        // Die Deckungsprüfung sitzt am Modell und weist eine Zuordnung ohne
        // Deckung ab — hier ist sie erfüllt, weil die Namen aus derselben
        // Bestellung stammen.
        $domain->certificate_id = (int) $certificate->id;
        $domain->save();

        $this->dispatch($domain, 'web.site.apply', 'Server-Block mit Zertifikat für '.$domain->name, $operation);
    }

    /**
     * Für diese Domain ein Zertifikat bestellen — wenn eines fehlt.
     *
     * **Mit vorhandenem Zertifikat wäre es die Schleife** aus der
     * Klassenbeschreibung: Bestellung, Zuordnung, Block neu, Bestellung. Die
     * zweite Bedingung — ohne Kontaktadresse passiert nichts — steht in
     * {@see CertificateOrder} und damit an der Stelle, die auch die Erneuerung
     * benutzt.
     */
    private function request(Domain $domain, Operation $operation): void
    {
        if ($domain->certificate_id !== null) {
            return;
        }

        // Wie bestellt wird, steht an einer Stelle — ohne Kontaktadresse
        // passiert dort nichts, und das ist die Antwort auf die zweite
        // Bedingung von früher.
        $this->order->place($domain, $operation);
    }

    /**
     * Den Server-Block neu schreiben lassen.
     *
     * Er trägt das Konto dessen, der den auslösenden Vorgang angestossen hat —
     * im Arbeiter gibt es keine Anfrage, und ohne diese Zeile stünde in der
     * Liste „—" neben einem Vorgang, den jemand ausgelöst hat.
     */
    private function dispatch(Domain $domain, string $task, string $message, Operation $cause): void
    {
        $operation = Operation::query()->create([
            'subscription_id' => $domain->subscription_id,
            'subject_type' => OperationSubject::Domain->value,
            'subject_id' => $domain->id,
            'account_id' => $cause->account_id,
            'type' => $task,
            'task' => $task,
            'payload' => app(WebLifecycle::class)->payload($domain),
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => $message,
        ]);

        RunAgentOperation::dispatch((int) $operation->id);
    }

    /**
     * Die Namen, die das Zertifikat deckt.
     *
     * Sie kommen aus der Antwort des Agenten und nicht aus dem, was das Panel
     * bestellt hat: Was gilt, steht im ausgestellten Zertifikat.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function names(array $result, Domain $domain): array
    {
        $value = $result['names'] ?? null;
        $names = [];

        if (is_array($value)) {
            foreach ($value as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names === [] ? [$domain->name] : $names;
    }

    /** @param  array<string, mixed>  $result */
    private function text(array $result, string $key): ?string
    {
        $value = $result[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Ein Zeitstempel aus der Antwort des Agenten.
     *
     * Der Agent schickt Sekunden seit 1970 — das ist, was `openssl_x509_parse`
     * liefert, und es ist die Form, die keine Zeitzone mitschleppt.
     *
     * @param  array<string, mixed>  $result
     */
    private function moment(array $result, string $key): ?Carbon
    {
        $value = $result[$key] ?? null;

        return is_int($value) && $value > 0 ? Carbon::createFromTimestamp($value) : null;
    }
}
