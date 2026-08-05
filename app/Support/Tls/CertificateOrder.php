<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Jobs\RunAgentOperation;
use App\Models\Domain;
use App\Models\Operation;

/**
 * Der eine Weg, ein Zertifikat zu bestellen.
 *
 * **Es gibt ab P4 zwei Anlässe und ab dem zweiten Wurf drei.** Eine Domain
 * ohne Zertifikat bekommt eines, sobald ihr Server-Block steht
 * ({@see CertificateLifecycle}); ein ablaufendes wird erneuert
 * ({@see CertificateRenewal}); später kommt der Knopf in der Oberfläche dazu.
 * Drei Stellen, die eine Bestellung zusammenbauen, sind zwei Gelegenheiten,
 * die Kontaktadresse zu vergessen oder die Namen aus der Anfrage statt aus dem
 * Bestand zu nehmen.
 *
 * **Ohne Kontaktadresse wird nichts bestellt**, und das ist keine Vorsicht,
 * sondern die Zusage aus {@see AcmeSettings}: Die Adresse gehört gesetzt und
 * nicht aus dem ersten Adminkonto geraten.
 */
final class CertificateOrder
{
    public function __construct(private readonly AcmeSettings $settings) {}

    /**
     * Für diese Domain ein Zertifikat bestellen.
     *
     * Der Aufrufer entscheidet, *ob* bestellt wird — diese Klasse entscheidet,
     * *wie*. Zurück kommt `null`, wenn keine Kontaktadresse eingetragen ist.
     *
     * @param  Operation|null  $cause  Der auslösende Vorgang, dessen Konto der
     *                                 neue erbt: Im Arbeiter gibt es keine
     *                                 Anfrage, und ohne diese Zeile stünde in
     *                                 der Liste „—" neben einem Vorgang, den
     *                                 jemand ausgelöst hat.
     */
    public function place(Domain $domain, ?Operation $cause = null): ?Operation
    {
        $contact = $this->settings->contact();

        if ($contact === null) {
            return null;
        }

        $operation = Operation::query()->create([
            'subscription_id' => $domain->subscription_id,
            'subject_type' => OperationSubject::Domain->value,
            'subject_id' => $domain->id,
            'account_id' => $cause?->account_id,
            'type' => 'acme.certificate.issue',
            'task' => 'acme.certificate.issue',
            'payload' => [
                // Die Namen kommen aus dem Bestand und nicht aus einer
                // Anfrage: Ein Zertifikat, das nur den ersten Namen deckt,
                // warnt bei jedem Alias.
                'names' => $domain->serverNames(),
                'contact' => $contact,
                'directory' => $this->settings->directory(),
            ],
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => 'Zertifikat für '.$domain->name,
        ]);

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }
}
