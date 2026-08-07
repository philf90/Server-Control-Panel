<?php

declare(strict_types=1);

namespace App\Support\Tls;

use App\Models\Certificate;
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
 * **Die Regel.** Ein Zertifikat ist verwaist, wenn sein Abonnement
 * zurückgebaut wurde: `subscription_id` ist null, die Abschrift
 * `subscription_name` steht noch da. Sein Ablageort darf entfernt werden, wenn
 * ihn **keine** Zeile mehr nennt, die kein Waise ist.
 *
 * Zwei Fälle nennen ihn sonst noch:
 *
 * - ein lebendes Abonnement — auf dem Zielserver war das `cloudlab24.de`,
 *   einmal zurückgebaut und einmal in Betrieb. Wer dort je Zeile löscht, nimmt
 *   eine laufende Website mit.
 * - das **Zertifikat der Oberfläche**. Es trägt ebenfalls `subscription_id`
 *   null, aber keine Abschrift; ohne diese Unterscheidung hielte das Aufräumen
 *   es für einen Rest und entfernte den Schlüssel, mit dem das Panel antwortet.
 *
 * **Ohne Mandantenklammer.** Aufgeräumt wird auf dem ganzen Server, und ein
 * Kommando ohne gesetzten Mandanten sähe sonst kein einziges Zertifikat — es
 * meldete „nichts zu tun" und liesse alles liegen.
 */
final class CertificatePrune
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Was zu tun ist — ohne dass etwas getan wird.
     *
     * @return array{orphans: int, removable: list<string>, shared: list<string>}
     */
    public function plan(): array
    {
        return $this->tenancy->withoutRestriction(static function (): array {
            $orphans = Certificate::query()
                ->whereNull('subscription_id')
                ->whereNotNull('subscription_name')
                ->get(['id', 'storage_name']);

            // Jede Zeile, die kein Waise ist: ein lebendes Abonnement
            // (`subscription_id` gesetzt) oder die Oberfläche (keine Abschrift).
            $spoken = array_flip(Certificate::query()
                ->where(static function ($query): void {
                    $query->whereNotNull('subscription_id')->orWhereNull('subscription_name');
                })
                ->whereNotNull('storage_name')
                ->pluck('storage_name')
                ->all());

            $removable = [];
            $shared = [];

            foreach ($orphans as $orphan) {
                $name = (string) ($orphan->storage_name ?? '');

                if ($name === '') {
                    continue;
                }

                // Je Ablageort und nicht je Zeile: Eine Erneuerung legt eine
                // zweite Zeile auf dasselbe Verzeichnis, und der zweite
                // Durchgang löschte sonst etwas, das der erste schon weg hat.
                if (array_key_exists($name, $spoken)) {
                    $shared[$name] = true;

                    continue;
                }

                $removable[$name] = true;
            }

            return [
                'orphans' => $orphans->count(),
                'removable' => array_keys($removable),
                'shared' => array_keys($shared),
            ];
        });
    }

    /**
     * Die verwaisten Zeilen eines Ablageorts löschen — und nur die.
     *
     * Aufgerufen, **nachdem** der Agent die Datei entfernt hat. Andersherum
     * wäre sie nach einem Fehlschlag unauffindbar: Die Zeile ist der einzige
     * Ort, an dem der Ablageort noch steht.
     */
    public function forget(string $storageName): int
    {
        return $this->tenancy->withoutRestriction(static fn (): int => Certificate::query()
            ->whereNull('subscription_id')
            ->whereNotNull('subscription_name')
            ->where('storage_name', $storageName)
            ->delete());
    }
}
