<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Acme;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Names;
use SrvPanel\Agent\Ops\PanelTls;

/**
 * Welches Zertifikat liefert die Oberfläche aus?
 *
 * **Die Frage hat ab P4 zwei mögliche Antworten, und sie muss überall dieselbe
 * geben.** Der Server-Block wählt die Datei, die Zertifikatsseite zeigt sie an,
 * und die Erneuerung entscheidet danach, ob sie etwas tun muss. Drei Stellen,
 * die je selbst nachsehen, sind zwei Gelegenheiten für eine Seite, die ein
 * anderes Zertifikat anzeigt als das, was der Browser bekommt — und genau diese
 * Sorte Fehler meldet niemand, weil beides plausibel aussieht.
 *
 * **Das ACME-Zertifikat gewinnt, das selbstsignierte bleibt liegen.** Es ist
 * die Notlösung für den ersten Start und der Rückweg, wenn unter dem Namen
 * dieses Servers nichts mehr steht: `panel.tls.ensure` hält es weiter gültig,
 * auch wenn es gerade niemand ausliefert. Ein Rückweg, den man erst wieder
 * herstellen muss, ist keiner.
 *
 * **Ein kurzer Rechnername ist kein Fehler.** `Store` nimmt nur Domainnamen an
 * — auf einem Server, der schlicht `cloudsrv24` heisst, fliegt die Frage nach
 * dem Ablageort. Hier wird sie zu „es gibt keines" und nicht zu einem Abbruch
 * beim Schreiben des Server-Blocks.
 */
final class PanelCertificate
{
    /**
     * Die beiden Pfade — und ob sie von einer Zertifizierungsstelle stammen.
     *
     * @return array{certificate: string, key: string, acme: bool}
     */
    public static function current(
        string $directory = PanelTls::DIRECTORY,
        ?Store $store = null,
        ?string $host = null,
    ): array {
        $acme = self::fromStore($store ?? new Store, $host ?? Names::host());

        if ($acme !== null) {
            return ['certificate' => $acme['certificate'], 'key' => $acme['key'], 'acme' => true];
        }

        return [
            'certificate' => $directory.'/panel.crt',
            'key' => $directory.'/panel.key',
            'acme' => false,
        ];
    }

    /** @return array{certificate: string, key: string}|null */
    private static function fromStore(Store $store, string $host): ?array
    {
        try {
            return $store->existing($host);
        } catch (AgentException) {
            // Kein Domainname, also auch kein Ablageort dafür.
            return null;
        }
    }
}
