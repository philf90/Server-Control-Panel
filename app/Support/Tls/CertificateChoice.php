<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Domain;

/**
 * Welches Zertifikat liefert dieser Server-Block gerade aus?
 *
 * **Die Frage hat ab jetzt genau eine Antwort, und sie steht hier.** Seit dem
 * zweiten Wurf von P4 kann eine Domain mehrere zur Auswahl haben: das für
 * ihren Namen bestellte, den Platzhalter ihres Abonnements, ein hochgeladenes.
 * Wer die Antwort an drei Stellen zusammensetzt — beim Schreiben des Blocks,
 * beim Entscheiden über eine Bestellung, in der Oberfläche —, bekommt drei
 * Antworten, und die beiden falschen fallen erst im Browser auf.
 *
 * **Wahl und Zuweisung sind zweierlei.** `domains.certificate_id` trägt beides;
 * `domains.certificate_pinned_at` sagt, welches von beiden es gerade ist. Ohne
 * diesen Unterschied nähme die nächste Bestellung die Wahl still zurück —
 * genau der Fehlertyp, der in diesem Projekt am teuersten war.
 *
 * **Der laute Rückfall.** Läuft die Wahl ab, wird sie übergangen und nicht
 * befolgt: Ein hochgeladenes Zertifikat erneuert niemand, und stur daran
 * festzuhalten nähme die Website vom Netz. Übergangen heisst aber nicht
 * verschwiegen — die Wahl bleibt vermerkt, die Domainseite sagt, dass sie
 * gerade nicht gilt, und der Zeitplan warnt dreissig Tage vorher. Die
 * Entscheidung dazu steht in `docs/34 §8`.
 */
final class CertificateChoice
{
    /**
     * Was ausgeliefert wird — und zwar wirklich.
     *
     * Die Reihenfolge ist die Regel:
     *
     * 1. Die Wahl, wenn sie gilt und alle Namen deckt.
     * 2. Sonst das beste gültige, das alle Namen deckt — der laute Rückfall.
     * 3. Sonst das Zugeordnete, auch wenn es abgelaufen ist. **Das ist besser
     *    als nichts:** Ohne Zertifikat fällt der Block auf Port 80 zurück, und
     *    eine Adresse, die vorher HTTPS war, ist dann nicht mehr erreichbar,
     *    sondern still unverschlüsselt. Ein abgelaufenes Zertifikat warnt
     *    wenigstens.
     */
    public function effective(Domain $domain): ?Certificate
    {
        $assigned = $this->assigned($domain);
        $names = $domain->serverNames();

        if ($domain->certificate_pinned_at !== null
            && $assigned instanceof Certificate
            && $this->usable($assigned, $names)) {
            return $assigned;
        }

        $best = $this->best($this->candidates($domain), $names);

        if ($best instanceof Certificate) {
            return $best;
        }

        return $assigned;
    }

    /**
     * Wird die Wahl gerade übergangen?
     *
     * Die Frage, die die Domainseite stellt. Sie ist nicht „ist etwas
     * abgelaufen" — es geht darum, ob jemand etwas anderes bekommt, als er
     * eingestellt hat.
     */
    public function overridden(Domain $domain): bool
    {
        if ($domain->certificate_pinned_at === null || $domain->certificate_id === null) {
            return false;
        }

        return $this->effective($domain)?->id !== $domain->certificate_id;
    }

    /**
     * Gibt es überhaupt eines, das gilt und alles deckt?
     *
     * **Das ist die Frage, an der eine Bestellung hängt** — und sie fragt
     * bewusst nicht nach der Wahl. Ist die Wahl abgelaufen und liegt ein
     * gültiges daneben, springt es ein, und es gibt nichts zu bestellen. Ist
     * auch das abgelaufen, wird bestellt: Das neue steht danach als Kandidat da
     * und übernimmt, ohne die Wahl anzutasten.
     *
     * **Ohne diese Trennung liefe es im Kreis.** Fragte die Bedingung nach dem
     * *zugeordneten* Zertifikat, bestellte eine Domain mit abgelaufener Wahl
     * bei jedem Anwenden erneut — die Zuordnung ändert sich ja nicht.
     */
    public function satisfied(Domain $domain): bool
    {
        return $this->best($this->candidates($domain), $domain->serverNames()) instanceof Certificate;
    }

    /**
     * Wovon jemand wählen kann.
     *
     * **Angeboten wird, was deckt.** Was nur die Hauptdomain deckt und nicht
     * ihre Aliasse, steht nicht zur Wahl: Es erzeugte eine Warnung im Browser,
     * die im Panel grün aussieht. Die Prüfung dafür gibt es am Modell.
     *
     * **Und was dem Abonnement gehört.** Die Abfrage läuft ohne
     * Mandantenklammer — sie muss auch im Arbeiter funktionieren —, filtert
     * dafür aber ausdrücklich auf das Abonnement der Domain. Das Zertifikat der
     * Oberfläche (`subscription_id` null) fällt damit heraus, und das ist
     * richtig: Es gehört keinem Kunden.
     *
     * @return list<Certificate>
     */
    public function candidates(Domain $domain): array
    {
        $names = $domain->serverNames();
        $found = [];

        $rows = Certificate::query()
            ->withoutGlobalScopes()
            ->where('subscription_id', $domain->subscription_id)
            ->where('status', CertificateStatus::Active)
            ->orderByDesc('not_after')
            ->orderByDesc('id')
            ->get();

        foreach ($rows as $certificate) {
            if ($certificate->coversAll($names)) {
                $found[] = $certificate;
            }
        }

        return $found;
    }

    /**
     * Das zugeordnete Zertifikat — ohne Mandantenklammer gelesen.
     *
     * Im Arbeiter steht sie im Grundzustand auf „nichts", und ein gewöhnliches
     * `find()` lieferte dort `null`: Der Server-Block verspräche kein HSTS und
     * bekäme kein Zertifikat, obwohl eines daliegt.
     */
    private function assigned(Domain $domain): ?Certificate
    {
        if ($domain->certificate_id === null) {
            return null;
        }

        $certificate = Certificate::query()->withoutGlobalScopes()->find($domain->certificate_id);

        return $certificate instanceof Certificate ? $certificate : null;
    }

    /**
     * Das beste unter den Kandidaten — gültig und deckend.
     *
     * `candidates()` ist schon nach Laufzeit sortiert; hier fällt nur noch
     * heraus, was abgelaufen ist.
     *
     * @param  list<Certificate>  $candidates
     * @param  list<string>  $names
     */
    private function best(array $candidates, array $names): ?Certificate
    {
        foreach ($candidates as $certificate) {
            if ($this->usable($certificate, $names)) {
                return $certificate;
            }
        }

        return null;
    }

    /**
     * Gilt es gerade, und deckt es alles?
     *
     * **Ohne Ablaufdatum gilt es.** Ein Zertifikat, dessen Laufzeit das Panel
     * nicht kennt, ist eines, über das es nichts weiss — es deswegen als
     * abgelaufen zu behandeln hiesse, eine laufende Website wegen einer
     * fehlenden Angabe vom Netz zu nehmen.
     *
     * @param  list<string>  $names
     */
    private function usable(Certificate $certificate, array $names): bool
    {
        if ($certificate->not_after !== null && $certificate->not_after->isPast()) {
            return false;
        }

        return $certificate->coversAll($names);
    }
}
