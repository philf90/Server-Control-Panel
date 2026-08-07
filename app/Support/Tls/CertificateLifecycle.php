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
        private readonly CertificateChoice $choice,
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
     * Das ausgestellte Zertifikat in den Bestand nehmen — und die Blöcke neu.
     *
     * Die Reihenfolge ist die Aussage: erst zuordnen, dann anwenden. Andersherum
     * schriebe der Agent einen Block für ein Zertifikat, das das Panel noch
     * nicht kennt.
     *
     * **Blöcke, nicht Block.** Bis zum 7. August 2026 wurde genau eine Domain
     * angewandt: die, die bestellt hat. Dahinter stand die Annahme, ein
     * Zertifikat betreffe die Domain, die es bestellt — und beim Platzhalter
     * stimmt sie nicht mehr. Im Abnahmelauf auf `cloudlab24.ipv64.de` gesehen:
     * Die Hauptdomain lieferte den Platzhalter aus, die drei Unterdomains
     * behielten ihre einzelnen Zertifikate. `CertificateChoice` antwortete für
     * sie längst richtig — nur fragte niemand, weil ihre Blöcke nicht neu
     * geschrieben wurden. Der Betreiber musste jede Unterdomain von Hand
     * „übernehmen".
     */
    private function install(Operation $operation, Domain $domain): void
    {
        // **Vorher gemerkt, was ausgeliefert wird**, denn nachher ist es
        // womöglich etwas anderes — und genau diese Differenz entscheidet, wer
        // neu geschrieben werden muss. Siehe {@see self::spread()}.
        $vorher = $this->delivered($domain);

        $this->record->store($domain, $operation->result ?? [], CertificateSource::Acme);

        $this->dispatch($domain, 'web.site.apply', 'Server-Block mit Zertifikat für '.$domain->name, $operation);

        $this->spread($domain, $operation, $vorher);
    }

    /**
     * Was die Blöcke dieses Abonnements gerade ausliefern — je Domain der
     * Ablageort.
     *
     * **Der Ablageort und nicht die Kennung.** Er ist das, was im Server-Block
     * steht; die Kennung ist es nicht. Eine Erneuerung legt eine neue Zeile an
     * — andere Kennung, **derselbe** Ablageort —, und ein Vergleich über die
     * Kennung hielte jeden Nachbarblock für veraltet. Bei einem Abonnement mit
     * vierzig Domains wären das vierzig Vorgänge alle sechzig Tage für eine
     * Datei, die genauso heisst wie vorher.
     *
     * @return array<int, string|null>
     */
    private function delivered(Domain $domain): array
    {
        $orte = [];

        foreach ($this->siblings($domain) as $nachbar) {
            $orte[(int) $nachbar->id] = $this->choice->effective($nachbar)?->storage_name;
        }

        return $orte;
    }

    /**
     * Und die Blöcke nachziehen, für die jetzt etwas anderes gilt.
     *
     * **Nur wo sich die Antwort geändert hat.** Eine gewöhnliche Bestellung
     * betrifft eine Domain, und dann tut diese Schleife nichts; ein Platzhalter
     * betrifft jede Domain der Zone, und dann tut sie genau das, was sonst
     * jemand von Hand tun müsste.
     *
     * **Eine Wahl wird dabei nicht angefasst.** Wer für eine Domain ein
     * Zertifikat ausgewählt hat, behält es — die Zuordnung bleibt stehen. Der
     * Block wird trotzdem geschrieben, wenn sich der Ablageort geändert hat:
     * Genau dann ist die Wahl abgelaufen und der laute Rückfall greift, und der
     * greift nur, wenn ihn jemand aufschreibt.
     *
     * @param  array<int, string|null>  $vorher
     */
    private function spread(Domain $ordered, Operation $cause, array $vorher): void
    {
        foreach ($this->siblings($ordered) as $domain) {
            $jetzt = $this->choice->effective($domain);

            if ($jetzt?->storage_name === ($vorher[(int) $domain->id] ?? null)) {
                continue;
            }

            if ($domain->certificate_pinned_at === null && $jetzt instanceof Certificate) {
                $domain->certificate_id = (int) $jetzt->id;
                $domain->save();
            }

            $this->dispatch(
                $domain,
                'web.site.apply',
                'Server-Block mit Zertifikat für '.$domain->name,
                $cause,
            );
        }
    }

    /**
     * Die übrigen Domains desselben Abonnements.
     *
     * Ohne die bestellende: Für die ist der Vorgang schon eingereiht, und ein
     * zweiter schriebe denselben Block ein zweites Mal.
     *
     * @return list<Domain>
     */
    private function siblings(Domain $domain): array
    {
        return Domain::query()
            ->where('subscription_id', $domain->subscription_id)
            ->whereKeyNot($domain->getKey())
            ->get()
            ->values()
            ->all();
    }

    /**
     * Für diese Domain ein Zertifikat bestellen — wenn keines ihre Namen deckt.
     *
     * **Gefragt wird nach der Deckung und nicht nach dem Verweis.** Bis zum
     * zweiten Wurf von P4 genügte hier ein zugeordnetes Zertifikat, egal
     * welches. Das reichte, solange jedes für genau eine Domain bestellt wurde;
     * es reicht nicht mehr, sobald ein Alias nachträglich dazukommt — dann
     * steht er im `server_name` und nicht im Zertifikat, der Browser warnt bei
     * ihm, und im Panel sieht alles grün aus.
     *
     * **Seit der Auswahl fragt die Bedingung nicht einmal mehr das zugeordnete**
     * ({@see CertificateChoice::satisfied()}), sondern ob es überhaupt eines
     * gibt, das gilt und alles deckt. Sonst bestellte eine Domain mit
     * abgelaufener Wahl bei jedem Anwenden erneut: Die Zuordnung ändert sich ja
     * nicht.
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
        if ($this->choice->satisfied($domain) || $this->ordering($domain)) {
            return;
        }

        $this->order->place($domain, $operation);
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
