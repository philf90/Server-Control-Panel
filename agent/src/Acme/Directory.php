<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;

/**
 * Das Verzeichnis der Zertifizierungsstelle — ihre Adressen, von ihr selbst.
 *
 * ACME hat genau eine Adresse, die ein Client kennen muss; alle anderen holt er
 * von dort. Das ist der Grund, warum ein Wechsel zwischen Testbetrieb und
 * Produktion eine Einstellung ist und keine Tabelle mit Adressen.
 *
 * **Die drei Adressen stehen als Eigenschaften da und nicht in einer Ablage.**
 * Eine Ablage hätte drei Schlüssel, die niemand prüft, und beim Vertippen
 * stünde `null` in einer Anfrage — genau die Sorte Zeichenkette ohne Bezug, die
 * dieses Projekt teuer bezahlt hat. Was fehlt, fällt hier beim Einlesen auf und
 * nicht bei der Anfrage, die es braucht.
 *
 * `revokeCert` und `keyChange` sind nicht dabei: Beide werden im ersten Wurf
 * von nichts aufgerufen, und eine fertig gebaute Adresse, die niemand benutzt,
 * gab es hier schon zweimal.
 */
final class Directory
{
    private function __construct(
        public readonly string $newNonce,
        public readonly string $newAccount,
        public readonly string $newOrder,
        public readonly ?string $termsOfService,
    ) {}

    public static function fetch(Transport $transport, string $url): self
    {
        $response = $transport->get($url);

        if (! $response->successful()) {
            throw AgentException::execFailed(sprintf(
                'Das ACME-Verzeichnis unter %s antwortete mit %d.',
                $url,
                $response->status,
            ));
        }

        $fields = $response->json();

        return new self(
            self::url($fields, 'newNonce'),
            self::url($fields, 'newAccount'),
            self::url($fields, 'newOrder'),
            self::terms($fields),
        );
    }

    /**
     * Eine Adresse aus dem Verzeichnis — und sie muss eine sein.
     *
     * **`https://` wird hier geprüft und nicht erst im Transport.** Der
     * Transport lehnt alles andere ohnehin ab; die Meldung von dort nennt aber
     * nur die Adresse, nicht die Stelle, an der sie herkam. Ein Verzeichnis,
     * das eine Adresse ohne TLS nennt, ist keines — das gehört beim Einlesen
     * gesagt.
     *
     * @param  array<string, mixed>  $fields
     */
    private static function url(array $fields, string $key): string
    {
        $value = $fields[$key] ?? null;

        if (! is_string($value) || ! str_starts_with($value, 'https://')) {
            throw AgentException::execFailed(sprintf('Das ACME-Verzeichnis nennt %s nicht.', $key));
        }

        return $value;
    }

    /** @param  array<string, mixed>  $fields */
    private static function terms(array $fields): ?string
    {
        $meta = $fields['meta'] ?? null;

        if (! is_array($meta)) {
            return null;
        }

        $terms = $meta['termsOfService'] ?? null;

        return is_string($terms) ? $terms : null;
    }
}
