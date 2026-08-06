<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Domain;
use Illuminate\Support\Carbon;

/**
 * Der eine Weg, ein abgelegtes Zertifikat in den Bestand zu nehmen.
 *
 * **Es gibt zwei Anlässe, und sie kommen aus verschiedenen Richtungen.** Eine
 * Bestellung läuft über die Warteschlange und wird von
 * {@see CertificateLifecycle} beantwortet; ein Hochladen ruft den Agenten
 * unmittelbar auf. Beide erzeugen dieselbe Zeile, und zwei Stellen, die sie
 * zusammenbauen, sind zwei Gelegenheiten, die Namen aus der Anfrage statt aus
 * der Antwort zu nehmen oder den Ablageort zu vergessen.
 *
 * **Was gilt, steht in der Antwort des Agenten** — nicht in dem, was das Panel
 * bestellt oder jemand hochgeladen hat. Der Agent hat die Datei gelesen; das
 * Panel hat sie nie gesehen.
 */
final class CertificateRecord
{
    /**
     * Die Zeile schreiben und der Domain zuordnen.
     *
     * **Die Reihenfolge ist die Aussage: erst zuordnen, dann anwenden.**
     * Andersherum schriebe der Agent einen Server-Block für ein Zertifikat,
     * das das Panel noch nicht kennt — und die Zuordnung ist seit dem zweiten
     * Wurf von P4 genau das, was er dafür braucht.
     *
     * @param  array<string, mixed>  $result  Die Antwort des Agenten
     */
    public function store(Domain $domain, array $result, CertificateSource $source): Certificate
    {
        $names = $this->names($result, $domain);
        $notAfter = $this->moment($result, 'not_after');

        $certificate = new Certificate([
            'names' => $names,
            'storage_name' => $this->storageName($result, $names),
            'status' => CertificateStatus::Active,
            'source' => $source,
            'issuer' => $this->text($result, 'issuer'),
            'serial' => $this->text($result, 'serial'),
            'not_before' => $this->moment($result, 'not_before'),
            'not_after' => $notAfter,
            'last_error' => null,
            'last_attempt_at' => now(),

            /*
             * **Ein Termin nur für das, was auch erneuert wird.**
             *
             * Die Frist wird beim Ablegen eingetragen und nicht beim Nachsehen
             * gerechnet: Ein bestelltes Zertifikat ohne Termin wäre eines, das
             * der Erneuerungslauf nie findet. Ein hochgeladenes erneuert
             * dagegen niemand — ein Termin daran wäre eine Zusage, die dieses
             * Panel nicht einlösen kann. Dass es abläuft, sagt die Domainseite
             * über `not_after`, und zwar rechtzeitig.
             */
            'renew_after' => $source === CertificateSource::Acme
                ? CertificateRenewal::due($notAfter)
                : null,
        ]);

        $certificate->subscription_id = $domain->subscription_id;
        $certificate->save();

        /*
         * **Eine Wahl wird nicht von einer Bestellung überschrieben.**
         *
         * Hat jemand für diese Domain ein Zertifikat ausgewählt, bleibt die
         * Zuordnung stehen; das neue steht danach als Kandidat daneben und
         * springt ein, wenn die Wahl abläuft ({@see CertificateChoice}). Ohne
         * diese Bedingung nähme die nächste Erneuerung die Wahl still zurück —
         * und still ist hier das Problem, nicht die Erneuerung.
         *
         * **Ein Hochladen ist dagegen selbst eine Wahl.** Wer die Datei
         * einfügt und absendet, hat entschieden; es danach nicht auszuliefern,
         * weil eine ältere Wahl dasteht, wäre ein Formular, das nichts tut.
         */
        $uploaded = $source === CertificateSource::Uploaded;

        if ($uploaded || $domain->certificate_pinned_at === null) {
            // Die Deckungs- und Eigentumsprüfung sitzt am Modell und weist eine
            // Zuordnung ab, die nicht passt.
            $domain->certificate_id = (int) $certificate->id;

            if ($uploaded) {
                $domain->certificate_pinned_at = now();
            }

            $domain->save();
        }

        return $certificate;
    }

    /**
     * Die Namen, die das Zertifikat deckt.
     *
     * Sie kommen aus der Antwort des Agenten und nicht aus dem, was das Panel
     * bestellt hat: Was gilt, steht im abgelegten Zertifikat.
     *
     * @param  array<string, mixed>  $result
     * @return non-empty-list<string>
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

    /**
     * Der Ablageort, den der Agent gemeldet hat.
     *
     * **Der Rückfall auf den ersten Namen ist die Regel, die der Agent bis zum
     * zweiten Wurf angewandt hat**, und er steht hier nur für den Fall, dass
     * eine Antwort ohne die Angabe ankommt. Ohne ihn stünde in der Spalte
     * `null`, und eine Domain mit gültigem Zertifikat fiele beim nächsten
     * Anwenden auf Port 80 zurück — ohne Fehler und ohne Meldung. Von zwei
     * Ausgängen ist das der teurere.
     *
     * @param  array<string, mixed>  $result
     * @param  non-empty-list<string>  $names
     */
    private function storageName(array $result, array $names): string
    {
        return $this->text($result, 'storage_name') ?? strtolower(trim($names[0]));
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
