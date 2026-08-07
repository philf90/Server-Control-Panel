<?php

declare(strict_types=1);

namespace App\Support\Tls;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SrvPanel\Agent\Acme\Dns\Providers;

/**
 * Was aus dem Formular für DNS-Zugangsdaten wird.
 *
 * **Eine Stelle für beide Formulare.** Der Betreiber trägt seine Zugangsdaten
 * in den Einstellungen ein, ein Abonnement mit `dns_edit` an seinem
 * Abonnement — dieselben Felder, dieselben Regeln. Zwei Fassungen davon wären
 * genau das Muster, an dem dieses Projekt am häufigsten verloren hat.
 *
 * **Geprüft wird hier nur die Form, nicht der Inhalt.** Ob der Nameserver
 * erreichbar ist, ob eine Zone ein Name sein kann, ob das Geheimnis Base64 ist
 * und ob das Verfahren eines der drei zugelassenen ist, entscheidet
 * `Rfc2136::configure()` im Agenten. Dieselbe Aufteilung wie auf der
 * Kommandozeile: Zwei Fassungen derselben Prüfung sind eine zu viel, und die
 * zweite ist die, die veraltet.
 *
 * **Der Anbieter dagegen gehört hierher.** Er entscheidet, welche Felder
 * überhaupt gelten, und die noch offenen aus Schritt 9 nimmt der Agent ohnehin
 * nicht an. Ihn hier abzuweisen heisst, die Meldung am Feld zu haben statt als
 * Abweisung von der anderen Seite des Sockets.
 */
final class DnsCredentialInput
{
    /**
     * Der gewählte Anbieter — und nur einer, den es auch gibt.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $usable
     *
     * @throws ValidationException
     */
    public static function provider(array $input, array $usable): string
    {
        $data = Validator::make($input, [
            'provider' => ['required', 'string', 'in:'.implode(',', $usable)],
        ], [
            'provider.in' => 'Diesen Anbieter gibt es noch nicht.',
        ])->validate();

        return (string) $data['provider'];
    }

    /**
     * Die Angaben des gewählten Anbieters — in der Form, die der Agent erwartet.
     *
     * **Jeder Anbieter hat sein eigenes Formular.** RFC 2136 braucht
     * Nameserver, Zonen, Schlüsselnamen und ein Base64-Geheimnis; IPv64.net und
     * Hetzner brauchen ein Token und sonst nichts — ihre Zonen kommen aus ihrer
     * eigenen Auskunft. Ein gemeinsames Formular mit lauter freiwilligen
     * Feldern hiesse, dass jeder Anbieter alles annimmt und die Hälfte
     * ignoriert; wer dann etwas Falsches einträgt, erfährt es nie.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function config(array $input, string $provider): array
    {
        return match ($provider) {
            Providers::RFC2136 => self::rfc2136($input),
            // **Zwei Anbieter, ein Zweig — und das ist kein Zusammenfassen aus
            // Bequemlichkeit.** Bei beiden sind die Zugangsdaten genau ein
            // Token; was sie unterscheidet, prüft der Agent. Zwei Methoden mit
            // demselben Rumpf wären zwei Orte für dieselbe Regel, und der
            // zweite ist der, der veraltet.
            Providers::IPV64, Providers::HETZNER, Providers::CLOUDFLARE => self::tokenOnly($input),
            // Unerreichbar, solange {@see self::provider()} davor steht — und
            // deshalb steht der Zweig hier: Ein `match` ohne ihn wirft einen
            // UnhandledMatchError, und der landet als „interner Fehler" im
            // Panel, ohne zu sagen, woran es liegt.
            default => throw ValidationException::withMessages([
                'provider' => 'Für diesen Anbieter gibt es kein Formular.',
            ]),
        };
    }

    /**
     * Ein Token, mehr nicht — IPv64.net, Hetzner und Cloudflare.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function tokenOnly(array $input): array
    {
        $data = Validator::make($input, [
            'token' => ['required', 'string', 'max:512'],
        ], [], ['token' => 'Token'])->validate();

        return ['token' => (string) $data['token']];
    }

    /**
     * RFC 2136 — Nameserver, Zonen, Schlüssel.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function rfc2136(array $input): array
    {
        $data = Validator::make($input, [
            'server' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer'],
            'zones' => ['required', 'string', 'max:4000'],
            'key_name' => ['required', 'string', 'max:255'],
            'algorithm' => ['nullable', 'string', 'max:64'],
            'secret' => ['required', 'string', 'max:4000'],
        ], [], [
            'server' => 'Nameserver',
            'zones' => 'Zonen',
            'key_name' => 'Schlüsselname',
            'algorithm' => 'Verfahren',
            'secret' => 'Geheimnis',
        ])->validate();

        $config = [
            'server' => (string) $data['server'],
            'zones' => self::zones($input['zones'] ?? null),
            'key_name' => (string) $data['key_name'],
            'secret' => (string) $data['secret'],
        ];

        // Beide sind Angaben mit einer Vorgabe im Agenten — Port 53,
        // hmac-sha256. Leer mitzuschicken hiesse, die Vorgabe hier ein zweites
        // Mal zu treffen; weglassen lässt sie dort, wo sie steht.
        if (isset($data['port']) && $data['port'] !== '') {
            $config['port'] = (int) $data['port'];
        }

        if (isset($data['algorithm']) && is_string($data['algorithm']) && $data['algorithm'] !== '') {
            $config['algorithm'] = $data['algorithm'];
        }

        return $config;
    }

    /**
     * Die Zonen aus dem Textfeld.
     *
     * **Ein Textfeld und keine Liste von Eingabefeldern.** Wer einen
     * TSIG-Schlüssel einrichtet, hat seine Zonen meistens schon irgendwo
     * stehen und fügt sie ein; ein Feld je Zone hiesse, sie einzeln
     * abzutippen. Getrennt wird an Leerraum, Kommas und Semikola, weil in der
     * Zwischenablage jede dieser Formen vorkommt.
     *
     * @return list<string>
     */
    public static function zones(mixed $raw): array
    {
        $parts = preg_split('/[\s,;]+/', is_string($raw) ? $raw : '') ?: [];

        $zones = [];

        foreach ($parts as $zone) {
            $zone = trim($zone);

            if ($zone !== '' && ! in_array($zone, $zones, true)) {
                $zones[] = $zone;
            }
        }

        return $zones;
    }
}
