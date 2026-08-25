<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Http\Middleware\EnforceAdminNetwork;
use App\Models\Account;
use App\Support\Settings\Settings;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Net\Cidr;
use SrvPanel\Agent\Pg\Hba;

/**
 * Aus welchen Netzen sich ein Adminkonto anmelden darf.
 *
 * ## Warum die Beschränkung nur für Adminkonten gilt
 *
 * `docs/82 §2.5` entscheidet das, und der Grund steht in einem Satz: Ein Kunde,
 * der sich aus dem Urlaub nicht anmelden kann, ist ein Ausfall. Ein Betreiber,
 * der sein Panel auf sein Büronetz beschränkt, weiss, was er tut.
 *
 * ## Eine leere Liste heisst „von überall"
 *
 * Das ist der Zustand jedes Servers, der die Einstellung nie angefasst hat. Die
 * andere Lesart wäre bei der nächsten Auslieferung eine Aussperrung für jeden.
 *
 * > **Eine leere Liste, die alles verbietet, sperrt beim Einschalten aus — eine,
 * > die alles erlaubt, ändert nichts.**
 *
 * ## Eine Stelle entscheidet, mehrere fragen
 *
 * Dieselbe Bauart wie {@see LastOperator}. Gefragt wird an **zwei** Stellen,
 * und beide sind nötig:
 *
 * 1. **Bei der Anmeldung**, damit das Protokoll die Wahrheit sagt. Ohne sie
 *    stünde dort ein erfolgreicher `auth.login` und daneben ein Rauswurf.
 * 2. **Bei jeder Anfrage** ({@see EnforceAdminNetwork}),
 *    weil eine Sitzung sonst die Beschränkung überlebt. Wer im Büro angemeldet
 *    war und den Rechner mitnimmt, arbeitet sonst weiter — und genau das soll
 *    eine Netzbeschränkung verhindern.
 *
 * > **Eine Schranke, die nur an der Tür steht, gilt für niemanden, der schon
 * > drin ist.**
 *
 * ## Der Aussperrschutz
 *
 * {@see self::covers()} beantwortet vor dem Speichern, ob die neue Liste den
 * Urheber selbst noch trägt. Ohne ihn ist ein Tippfehler in einem Netz ein
 * Serverbesuch über SSH — und für einen Administrator, der kein SSH hat, das
 * Ende seines Zugangs.
 *
 * Das Abnahmekriterium von Schritt 7 lautet wörtlich: **eine IP-Beschränkung,
 * die ihren eigenen Urheber nicht aussperrt.**
 */
final class AdminNetwork
{
    /**
     * Darf sich dieses Konto von dieser Adresse aus anmelden?
     *
     * **Ein Kundenkonto immer.** Gefragt wird die Mandantenachse und nicht die
     * Rolle: Die Beschränkung gilt der Admin-Ebene als ganzer, und ein
     * Administrator ohne Betreiberrolle steht genauso darunter.
     *
     * **Eine fehlende Adresse zählt als nicht gedeckt**, sobald es überhaupt
     * eine Liste gibt. Das ist die sichere Richtung: Wer nicht sagen kann,
     * woher er kommt, kommt nicht durch eine Schranke, die nach der Herkunft
     * fragt.
     */
    public static function permits(Account $account, ?string $ip): bool
    {
        if (! $account->isAdmin()) {
            return true;
        }

        $networks = app(Settings::class)->adminNetworks();

        if ($networks === []) {
            return true;
        }

        return self::covers($networks, $ip);
    }

    /**
     * Trägt diese Liste diese Adresse?
     *
     * **Ohne Nebenwirkung und ohne Bestand** — deshalb steht sie getrennt von
     * {@see self::permits()}: Das Formular fragt sie mit der Liste, die
     * *gespeichert werden soll*, und nicht mit der, die gespeichert ist.
     *
     * Eine leere Liste trägt hier **nichts**, und das ist kein Widerspruch zu
     * „leer heisst von überall": Diese Frage lautet „deckt diese Aufzählung
     * jene Adresse", und eine leere Aufzählung deckt keine. Die Bedeutung von
     * „leer" entscheidet der Aufrufer — `permits()` oben, und das Formular, das
     * eine leere Liste als Abschalten liest.
     *
     * @param  list<string>  $networks
     */
    public static function covers(array $networks, ?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        foreach ($networks as $network) {
            if (Cidr::contains($network, $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ein Netz prüfen und in seine kanonische Schreibweise bringen.
     *
     * **Die Rechnung steht in {@see Cidr}, die Politik hier** — dieselbe
     * Teilung wie bei {@see Hba::cidr()}, nur mit der
     * anderen Entscheidung: Dort ist `0.0.0.0/0` eine Ablehnung, weil ein
     * Datenbankzugang für das ganze Internet ein Fehler ist. Hier beschränkt es
     * schlicht nichts.
     *
     * **Und sie steht hier und nicht im Controller**, weil zwei Wege dieselbe
     * Frage stellen: das Formular und `srvpanel access --add`. Stünde die Regel
     * an beiden, liefen sie auseinander — und welche der beiden dann die
     * strengere ist, wüsste niemand.
     *
     * > **Zwei Eingänge zu derselben Einstellung teilen ihre Prüfung, oder die
     * > Einstellung hat zwei Bedeutungen.**
     *
     * @throws AgentException wenn die Angabe kein brauchbares Netz ist
     */
    public static function normalize(mixed $entry, string $field = 'Netz'): string
    {
        $normalized = Cidr::normalize($entry, $field);

        if (str_ends_with($normalized, '/0')) {
            throw AgentException::badRequest(sprintf(
                '%s deckt das ganze Internet ab und beschränkt damit nichts. Lassen Sie die Liste '
                .'leer, wenn keine Beschränkung gelten soll.',
                is_string($entry) ? trim($entry) : $normalized,
            ));
        }

        return $normalized;
    }

    /**
     * Der Satz, den der Betreiber beim Speichern liest.
     *
     * Er nennt die Adresse, von der aus gerade gearbeitet wird — ohne sie ist
     * die Meldung eine Ablehnung ohne Ausweg, und der Betreiber rät, welches
     * Netz gemeint war.
     */
    public static function refusal(?string $ip): string
    {
        return sprintf(
            'Diese Netze schliessen Ihre eigene Adresse %s aus. Sie wären nach dem Speichern '
            .'ausgesperrt und kämen nur noch über die Kommandozeile des Servers zurück. '
            .'Nehmen Sie ein Netz auf, das Ihre Adresse enthält — oder lassen Sie die Liste leer, '
            .'dann gilt keine Beschränkung.',
            $ip ?? '(unbekannt)',
        );
    }
}
