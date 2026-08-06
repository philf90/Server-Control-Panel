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
 *
 * **Seit Schritt 8 gibt es zwei Formen derselben Bestellung.** Der Unterschied
 * sind die Namen und zwei Felder mehr im Auftrag — nicht ein zweiter Weg. Wer
 * hier eine `placeWildcard()` einzöge, hätte die Kontaktadresse an zwei Stellen
 * zu vergessen; **ob** ein Platzhalter erlaubt ist, entscheidet dagegen
 * {@see WildcardOrder} und nicht diese Klasse.
 */
final class CertificateOrder
{
    public function __construct(
        private readonly AcmeSettings $settings,
        private readonly WildcardOrder $wildcards,
    ) {}

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
     * @param  bool  $wildcard  Bestellt `*.example.de` **und** `example.de` über
     *                          DNS-01. Die Berechtigung dazu ist an diesem
     *                          Punkt schon geprüft.
     */
    public function place(Domain $domain, ?Operation $cause = null, bool $wildcard = false): ?Operation
    {
        $contact = $this->settings->contact();

        if ($contact === null) {
            return null;
        }

        // **Der Stern zuerst** — siehe {@see WildcardOrder::names()}. Der
        // Ablageort entsteht aus dem ersten Namen; stünde die Basisdomain
        // vorn, überschriebe der Platzhalter ein einfaches Zertifikat für
        // denselben Namen.
        $names = $wildcard ? WildcardOrder::names($domain) : $domain->serverNames();

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
                'names' => $names,
                'contact' => $contact,
                'directory' => $this->settings->directory(),
                // **Ohne Platzhalter steht hier nichts.** Eine Bestellung des
                // ersten Wurfs kommt ohne diese Felder an, und der Agent
                // bleibt dann bei HTTP-01 — ein Zeitplan aus der alten Fassung
                // soll nicht stehenbleiben.
            ] + ($wildcard ? [
                'challenge' => WildcardOrder::challenge(),
                'profile' => $this->wildcards->profile($domain),
            ] : []),
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => ($wildcard ? 'Platzhalter für ' : 'Zertifikat für ').$domain->name,
        ]);

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }
}
