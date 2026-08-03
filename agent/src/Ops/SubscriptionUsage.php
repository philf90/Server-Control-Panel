<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Mounts;
use SrvPanel\Agent\Op;

/**
 * Der belegte Speicher aller Abonnements — in einem Aufruf.
 *
 * **Warum ein Aufruf für alle und nicht einer je Abonnement.** `repquota`
 * liest die Quota-Datei des Dateisystems einmal und kennt danach jeden
 * Benutzer darin. Ein Aufruf je Abonnement wäre bei hundert Abonnements
 * hundertmal derselbe Lauf über dieselbe Datei — und weil er über die
 * Warteschlange ginge, hundert Vorgänge im Protokoll für eine Messung, die
 * niemand ausgelöst hat. Diese Operation nimmt deshalb **keine Argumente**:
 * Es gibt nichts auszuwählen.
 *
 * **Sie meldet nur die Benutzer des Panels.** `repquota` gibt jeden Benutzer
 * des Dateisystems aus, auch `root`, `www-data` und was der Betreiber sonst
 * angelegt hat. Herausgegeben wird nur, was der Form `p` plus vier bis neun
 * Ziffern entspricht — dieselbe Regel wie in {@see SubscriptionProvision}.
 * Alles andere geht das Panel nichts an; eine Operation, die die Benutzerliste
 * des Servers ausliefert, wäre eine Auskunft, die niemand bestellt hat.
 *
 * **Ein fehlendes Quota-System ist kein Fehler.** Quota braucht einen Mount
 * mit `usrquota` und ein gelaufenes `quotacheck`. Fehlt das, kommt
 * `available: false` samt Grund zurück und nicht eine Ausnahme: Das Panel
 * soll „nicht gemessen" anzeigen können, und ein Vorgang, der jede Viertelstunde
 * rot wird, weil der Betreiber keine Quota eingerichtet hat, ist eine Meldung,
 * die man nach zwei Tagen wegsieht.
 *
 * Nicht verändernd — sie liest.
 */
final class SubscriptionUsage implements Op
{
    /** Dieselbe Wurzel wie beim Anlegen. Sie steht hier und kommt nicht von aussen. */
    public const VHOSTS = SubscriptionProvision::VHOSTS;

    public static function name(): string
    {
        return 'subscription.usage';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $device = Mounts::deviceFor(self::VHOSTS);

        if ($device === null) {
            return $this->unavailable('kein Mount für '.self::VHOSTS.' gefunden');
        }

        // `-u` Benutzerquota, `-O csv` die maschinenlesbare Form. Ohne `-O`
        // gibt repquota eine Tabelle für Menschen aus, in der ein langer
        // Benutzername die Spalten verschiebt — und dann liest der Parser die
        // Blockzahl aus der falschen Spalte.
        $result = $context->stream('repquota', ['-u', '-O', 'csv', $device], 120);

        if (! $result->successful()) {
            $reason = trim($result->stderr);

            return $this->unavailable($reason !== '' ? $reason : 'repquota fehlgeschlagen');
        }

        return [
            'available' => true,
            'device' => $device,
            'users' => $this->parse($result->stdout),
        ];
    }

    /**
     * @return array{available: false, device: null, users: array<string, mixed>, reason: string}
     */
    private function unavailable(string $reason): array
    {
        return ['available' => false, 'device' => null, 'users' => [], 'reason' => $reason];
    }

    /**
     * Die CSV-Ausgabe von `repquota -O csv` in Werte je Systembenutzer.
     *
     * Das Format:
     *
     *     Benutzer,BlockStatus,DateiStatus,Blöcke,BlockSoft,BlockHart,BlockGrace,Dateien,…
     *     p1000,ok,ok,102400,5242880,5242880,,131,0,0,
     *
     * Blöcke sind KiB — repquota rechnet grundsätzlich in 1-KiB-Blöcken,
     * unabhängig von der Blockgrösse des Dateisystems. Ausgegeben wird MB, weil
     * das Panel Kontingente in MB führt (`disk_mb`); abgerundet, damit ein
     * Verbrauch von 0,4 MB nicht als 1 MB erscheint.
     *
     * Die Kopfzeile wird nicht anhand ihres Wortlauts erkannt, sondern daran,
     * dass das erste Feld kein Systembenutzer des Panels ist. `repquota`
     * übersetzt seine Kopfzeile, und ein Test auf „User" liefe auf einem
     * deutschen System ins Leere.
     *
     * @return array<string, array{used_mb: int, limit_mb: int}>
     */
    private function parse(string $csv): array
    {
        $users = [];

        foreach (preg_split('/\R/', $csv) ?: [] as $line) {
            $fields = str_getcsv(trim($line), ',', '"', '\\');

            if (count($fields) < 6) {
                continue;
            }

            $user = (string) ($fields[0] ?? '');

            if (preg_match('/^p[0-9]{4,9}$/', $user) !== 1) {
                continue;
            }

            $users[$user] = [
                'used_mb' => intdiv(max(0, (int) $fields[3]), 1024),
                'limit_mb' => intdiv(max(0, (int) $fields[5]), 1024),
            ];
        }

        return $users;
    }
}
