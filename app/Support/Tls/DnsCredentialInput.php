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
 * überhaupt gelten, und einen, der nicht angeboten wird, nimmt der Agent
 * ohnehin nicht an. Ihn hier abzuweisen heisst, die Meldung am Feld zu haben
 * statt als Abweisung von der anderen Seite des Sockets.
 */
final class DnsCredentialInput
{
    /**
     * Der gewählte Anbieter — und nur einer, der auch angeboten wird.
     *
     * **Die Meldung sagt nicht mehr „noch nicht".** Bis zum 7. August 2026 gab
     * es nur einen Grund zu fehlen: noch nicht gebaut. Seit INWX gebaut ist und
     * trotzdem nicht angeboten wird (`docs/34 §11`), wäre „noch nicht" bei ihm
     * eine Zusage, die niemand einlöst. Warum ein einzelner fehlt, steht im
     * Formular neben seinem Namen — hier fehlt der Platz dafür, denn diese
     * Meldung gilt für jeden abgewiesenen Wert.
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
            'provider.in' => 'Diesen Anbieter gibt es hier nicht.',
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
            Providers::IPV64, Providers::HETZNER, Providers::CLOUDFLARE, Providers::DESEC => self::tokenOnly($input),
            Providers::NETCUP => self::netcup($input),
            Providers::IONOS => self::ionos($input),
            // INWX steht hier, obwohl `self::provider()` ihn davor abweist —
            // dieselbe Entscheidung wie im Agenten (`Providers::configure()`).
            // Der Zweig zu löschen hiesse, ihn beim nächsten Sinneswandel neu
            // zu schreiben, und dann fehlte genau die Prüfung, die hier steht.
            Providers::INWX => self::inwx($input),
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
     * Ein Token, mehr nicht — IPv64.net, Hetzner, Cloudflare und deSEC.
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
     * INWX — Benutzername, Passwort und wahlweise das gemeinsame Geheimnis.
     *
     * **Der einzige Anbieter mit einem Kontopasswort.** Was hier hinterlegt
     * wird, öffnet ein Registrarkonto und nicht eine Zone; das steht als Satz
     * im Formular, damit es niemand beiläufig einträgt.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function inwx(array $input): array
    {
        $data = Validator::make($input, [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:512'],
            'shared_secret' => ['nullable', 'string', 'max:512'],
        ], [], [
            'username' => 'Benutzername',
            'password' => 'Passwort',
            'shared_secret' => 'Gemeinsames Geheimnis',
        ])->validate();

        $config = [
            'username' => (string) $data['username'],
            'password' => (string) $data['password'],
        ];

        // Leer weglassen statt leer mitschicken: Ein Konto ohne zweiten Faktor
        // braucht kein Geheimnis, und der Agent unterscheidet „keines" von
        // „eines, das nicht taugt".
        if (isset($data['shared_secret']) && is_string($data['shared_secret']) && $data['shared_secret'] !== '') {
            $config['shared_secret'] = $data['shared_secret'];
        }

        return $config;
    }

    /**
     * IONOS — ein Feld, das in Wahrheit zwei ist.
     *
     * Der Schlüssel hat die Form `<präfix>.<geheimnis>`; geprüft wird das im
     * Agenten, weil die Form eine Eigenschaft des Anbieters ist und nicht die
     * eines Formulars.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function ionos(array $input): array
    {
        $data = Validator::make($input, [
            'api_key' => ['required', 'string', 'max:512'],
        ], [], ['api_key' => 'API-Schlüssel'])->validate();

        return ['api_key' => (string) $data['api_key']];
    }

    /**
     * netcup — Kundennummer, zwei Geheimnisse und die Zonen.
     *
     * **Die Zonen stehen hier, weil die Schnittstelle sie nicht nennt.** netcup
     * kennt keine Auskunft, die die Domains eines Kontos aufzählt; lego fragt
     * dafür die autoritativen Nameserver. Das wäre eine dritte Quelle für
     * dieselbe Frage — stattdessen gilt dieselbe Antwort wie bei RFC 2136: eine
     * Positivliste, die der Betreiber aufschreibt.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function netcup(array $input): array
    {
        $data = Validator::make($input, [
            'customer_number' => ['required', 'string', 'max:20'],
            'api_key' => ['required', 'string', 'max:512'],
            'api_password' => ['required', 'string', 'max:512'],
            'zones' => ['required', 'string', 'max:4000'],
        ], [], [
            'customer_number' => 'Kundennummer',
            'api_key' => 'API-Schlüssel',
            'api_password' => 'API-Passwort',
            'zones' => 'Zonen',
        ])->validate();

        return [
            'customer_number' => (string) $data['customer_number'],
            'api_key' => (string) $data['api_key'],
            'api_password' => (string) $data['api_password'],
            'zones' => self::zones($input['zones'] ?? null),
        ];
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
