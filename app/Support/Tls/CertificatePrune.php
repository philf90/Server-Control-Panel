<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Models\Certificate;
use App\Models\Domain;
use App\Support\Tenancy\Tenancy;

/**
 * Welche Zertifikate übrig sind — und welcher Ablageort davon fort darf.
 *
 * **Warum das eine eigene Klasse ist und keine Methode im Kommando.** Die
 * Auswahl ist die ganze Sicherheit dieses Aufräumens: Sie entscheidet, ob ein
 * privater Schlüssel gelöscht wird oder nicht. Stünde sie im Kommando, müsste
 * ein Test sie nachbauen, um sie zu prüfen — und dann gäbe es zwei Fassungen
 * derselben Regel, von denen die im Test grün bleibt, während die im Kommando
 * abdriftet. Genau dieses Muster hat dieses Projekt mehrfach eingeholt.
 *
 * **Die Regel.** Ein Ablageort darf fort, wenn ihn **keine** Zeile mehr nennt,
 * die noch in Gebrauch ist. Zwei Arten von Zeilen sind es nicht mehr:
 *
 * - **verwaist** — das Abonnement wurde zurückgebaut: `subscription_id` ist
 *   null, die Abschrift `subscription_name` steht noch da.
 * - **ohne Domain** — das Abonnement lebt, aber keine seiner Domains nennt
 *   dieses Zertifikat und keine wird von ihm gedeckt.
 *
 * **Der zweite Fall ist am 24. August 2026 dazugekommen**, und er ist derselbe
 * Fehler wie der erste, eine Ebene tiefer. Gemessen auf `cloudsrv24`, nachdem
 * eine einzelne Domain gelöscht war: Zeile und `privkey.pem` blieben liegen,
 * `--prune` führte sie nie auf — sie gehört ja einem lebenden Abonnement —, und
 * eine Route zum Entfernen eines einzelnen Zertifikats gibt es nicht.
 *
 * > **Wer etwas anlegt, das auf der Platte bleibt, baut den Weg zurück mit.**
 *
 * Zwei Fälle nennen einen Ablageort dagegen weiterhin:
 *
 * - ein lebendes Abonnement, **dessen Domains das Zertifikat noch brauchen** —
 *   auf dem Zielserver war das `cloudlab24.de`, einmal zurückgebaut und einmal
 *   in Betrieb. Wer dort je Zeile löscht, nimmt eine laufende Website mit.
 * - das **Zertifikat der Oberfläche**. Es trägt ebenfalls `subscription_id`
 *   null, aber keine Abschrift; ohne diese Unterscheidung hielte das Aufräumen
 *   es für einen Rest und entfernte den Schlüssel, mit dem das Panel antwortet.
 *
 * **Gefragt wird nach der Deckung und nicht nach der Zuordnung.** Ein
 * Zertifikat, das eine lebende Domain deckt, ohne ihr zugeordnet zu sein, wird
 * von {@see CertificateChoice} jederzeit gewählt — es nur an
 * `domains.certificate_id` zu messen, löschte den Schlüssel unter einer
 * laufenden Website weg. Im Zweifel gilt eine Zeile als gebraucht.
 *
 * **Ohne Mandantenklammer.** Aufgeräumt wird auf dem ganzen Server, und ein
 * Kommando ohne gesetzten Mandanten sähe sonst kein einziges Zertifikat — es
 * meldete „nichts zu tun" und liesse alles liegen.
 */
final class CertificatePrune
{
    /**
     * Die Domains je Abonnement, einmal geholt.
     *
     * @var array<int, list<Domain>>
     */
    private array $domains = [];

    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Was zu tun ist — ohne dass etwas getan wird.
     *
     * `nothing` beantwortet „ist überhaupt etwas zu tun?" — die Frage, die das
     * Kommando stellt, bevor es irgendetwas ausgibt.
     *
     * `reasons` nennt je Ablageort, warum er fort darf. Das Kommando schreibt
     * es hin: Bei einem Vorgang, der private Schlüssel von der Platte nimmt,
     * ist „warum" so wichtig wie „was".
     *
     * @return array{nothing: bool, orphans: int, abandoned: int, removable: list<string>, shared: list<string>, reasons: array<string,string>}
     */
    public function plan(): array
    {
        return $this->tenancy->withoutRestriction(function (): array {
            $verwaist = 0;
            $verlassen = 0;
            $gesprochen = [];
            $gruende = [];

            foreach ($this->rows() as $zeile) {
                $name = (string) ($zeile->storage_name ?? '');

                if ($name === '') {
                    continue;
                }

                if ($this->inUse($zeile)) {
                    $gesprochen[$name] = true;

                    continue;
                }

                if ($zeile->orphaned()) {
                    $verwaist++;
                    $gruende[$name]['verwaist'] = true;
                } else {
                    $verlassen++;
                    $gruende[$name]['ohne Domain'] = true;
                }
            }

            $removable = [];
            $shared = [];
            $reasons = [];

            foreach ($gruende as $name => $arten) {
                if (array_key_exists($name, $gesprochen)) {
                    $shared[] = $name;

                    continue;
                }

                $removable[] = $name;
                $reasons[$name] = implode(' und ', array_keys($arten));
            }

            return [
                // **„Es gibt nichts zu tun" gehört hierher und nicht ins
                // Kommando.** Dort stand es bis zum 24. August 2026 als
                // `orphans === 0` ausgeschrieben — eine zweite Fassung der
                // Regel, und sie ist beim zweiten Fall veraltet: Das Kommando
                // meldete „keine verwaisten Zertifikate" und liess den privaten
                // Schlüssel liegen.
                'nothing' => $verwaist === 0 && $verlassen === 0,
                'orphans' => $verwaist,
                'abandoned' => $verlassen,
                'removable' => $removable,
                'shared' => $shared,
                'reasons' => $reasons,
            ];
        });
    }

    /**
     * Ist diese Zeile noch in Gebrauch?
     *
     * **Die eine Stelle, an der die Sicherheit dieses Aufräumens hängt.** Sie
     * beantwortet dieselbe Frage für {@see self::plan()} und
     * {@see self::forget()}; zwei Fassungen davon wären zwei Antworten, und die
     * falsche fiele erst auf, wenn ein Schlüssel fehlt.
     *
     * Im Zweifel **ja**: Wer hier zu grosszügig ist, lässt ein Verzeichnis
     * liegen; wer zu streng ist, nimmt eine Website vom Netz.
     */
    private function inUse(Certificate $certificate): bool
    {
        if ($certificate->forPanel()) {
            return true;
        }

        // **Über alle Abonnements gefragt und nicht nur über das eigene.** Eine
        // Zuordnung, die über die Grenze zeigt, sollte es nicht geben — aber
        // wenn es sie gibt, ist sie ein Gebrauch und kein Grund zu löschen.
        if (Domain::query()->withoutGlobalScopes()->where('certificate_id', $certificate->id)->exists()) {
            return true;
        }

        if ($certificate->subscription_id === null) {
            return false;
        }

        foreach ($this->domainsOf((int) $certificate->subscription_id) as $domain) {
            foreach ($domain->serverNames() as $name) {
                if ($certificate->covers($name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Die Domains eines Abonnements — einmal geholt, nicht je Zeile.
     *
     * Eine Erneuerung legt für dasselbe Verzeichnis eine weitere Zeile an; ohne
     * diesen Zwischenspeicher fragte ein Server mit vielen Zertifikaten
     * dieselbe Liste dutzendfach ab.
     *
     * @return list<Domain>
     */
    private function domainsOf(int $subscription): array
    {
        if (! array_key_exists($subscription, $this->domains)) {
            $this->domains[$subscription] = Domain::query()
                ->withoutGlobalScopes()
                ->with('children')
                ->where('subscription_id', $subscription)
                ->get()
                ->values()
                ->all();
        }

        return $this->domains[$subscription];
    }

    /**
     * Jede Zeile mit einem Ablageort.
     *
     * @return list<Certificate>
     */
    private function rows(): array
    {
        return Certificate::query()
            ->withoutGlobalScopes()
            ->whereNotNull('storage_name')
            ->get()
            ->values()
            ->all();
    }

    /**
     * Die ungebrauchten Zeilen eines Ablageorts löschen — und nur die.
     *
     * Aufgerufen, **nachdem** der Agent die Datei entfernt hat. Andersherum
     * wäre sie nach einem Fehlschlag unauffindbar: Die Zeile ist der einzige
     * Ort, an dem der Ablageort noch steht.
     *
     * **Gefragt wird {@see self::inUse()} und nicht die Spalte.** Bis zum
     * 24. August 2026 stand hier die Bedingung eines Waisen ausgeschrieben —
     * `subscription_id` null bei gesetzter Abschrift. Mit dem zweiten Fall
     * („ohne Domain", das Abonnement lebt) hätte der Agent die Datei entfernt
     * und die Zeile wäre stehengeblieben: ein Wegweiser auf ein Verzeichnis,
     * das es nicht mehr gibt, und beim nächsten Lauf ein „war schon fort".
     */
    public function forget(string $storageName): int
    {
        return $this->tenancy->withoutRestriction(function () use ($storageName): int {
            $ids = [];

            foreach (Certificate::query()->withoutGlobalScopes()->where('storage_name', $storageName)->get() as $zeile) {
                if (! $this->inUse($zeile)) {
                    $ids[] = $zeile->id;
                }
            }

            return $ids === [] ? 0 : Certificate::query()->withoutGlobalScopes()->whereKey($ids)->delete();
        });
    }
}
