<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Enums\CertificateSource;
use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Jobs\RunAgentOperation;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Operation;
use App\Support\Operations\AfterOperation;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\WebLifecycle;

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
 * Sie hört auf, weil {@see self::request()} nur bestellt, wenn kein zugeordnetes
 * Zertifikat die Namen des Blocks deckt, und die Zuordnung **vor** dem neuen
 * Block passiert. Ein eigener Testdurchgang steht dafür ein.
 */
final class CertificateLifecycle implements AfterOperation
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly CertificateOrder $order,
        private readonly CertificateRecord $record,
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
        $this->record->store($domain, $operation->result ?? [], CertificateSource::Acme);

        $this->dispatch($domain, 'web.site.apply', 'Server-Block mit Zertifikat für '.$domain->name, $operation);
    }

    /**
     * Für diese Domain ein Zertifikat bestellen — wenn keines ihre Namen deckt.
     *
     * **Gefragt wird nach der Deckung und nicht mehr nach dem Verweis.** Bis
     * zum zweiten Wurf von P4 genügte hier ein zugeordnetes Zertifikat, egal
     * welches. Das reichte, solange jedes für genau eine Domain bestellt wurde;
     * es reicht nicht mehr, sobald ein Alias nachträglich dazukommt — dann
     * steht er im `server_name` und nicht im Zertifikat, der Browser warnt bei
     * ihm, und im Panel sieht alles grün aus. Dieselbe Bedingung trägt später
     * den Platzhalter und das hochgeladene Zertifikat (`docs/34 §7`).
     *
     * **Mit deckendem Zertifikat wäre es die Schleife** aus der
     * Klassenbeschreibung: Bestellung, Zuordnung, Block neu, Bestellung. Sie
     * hört auf, weil eine erfolgreiche Bestellung genau die Namen deckt, für
     * die sie lief.
     *
     * **Und läuft schon eine, kommt keine zweite dazu.** Ohne diese Frage
     * bestellte jedes erneute Anwenden noch einmal — bei `srvpanel vhost
     * --sites` über viele Domains wären das ebenso viele Prüfungen, und fünf
     * Fehlversuche je Stunde sind die Grenze der Zertifizierungsstelle.
     *
     * Die dritte Bedingung — ohne Kontaktadresse passiert nichts — steht in
     * {@see CertificateOrder} und damit an der Stelle, die auch die Erneuerung
     * benutzt.
     */
    private function request(Domain $domain, Operation $operation): void
    {
        if ($this->covered($domain) || $this->ordering($domain)) {
            return;
        }

        $this->order->place($domain, $operation);
    }

    /**
     * Deckt das zugeordnete Zertifikat alle Namen dieses Server-Blocks?
     *
     * Ohne Mandantenklammer gelesen, aus demselben Grund wie in
     * {@see WebLifecycle}: Im Arbeiter steht sie im Grundzustand auf „nichts",
     * und ein gewöhnliches `find()` lieferte dort `null` — die Domain bekäme
     * bei jedem Anwenden eine neue Bestellung, obwohl ihr Zertifikat daliegt.
     */
    private function covered(Domain $domain): bool
    {
        if ($domain->certificate_id === null) {
            return false;
        }

        $certificate = Certificate::query()->withoutGlobalScopes()->find($domain->certificate_id);

        return $certificate instanceof Certificate && $certificate->coversAll($domain->serverNames());
    }

    /** Läuft für diese Domain schon eine Bestellung? */
    private function ordering(Domain $domain): bool
    {
        return Operation::query()
            ->withoutGlobalScopes()
            ->where('subject_type', OperationSubject::Domain->value)
            ->where('subject_id', $domain->id)
            ->where('task', 'acme.certificate.issue')
            ->whereIn('status', [OperationStatus::Queued->value, OperationStatus::Running->value])
            ->exists();
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



}
