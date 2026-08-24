<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Context;
use SrvPanel\Agent\Logs;
use SrvPanel\Agent\Op;

/**
 * Welche Protokolle es gibt — und welche davon etwas enthalten.
 *
 * **Die Liste ist die Positivliste selbst** ({@see Logs}), nicht das, was im
 * Verzeichnis liegt. Ein Protokoll, das es noch nicht gibt, wird trotzdem
 * genannt: Es ist ein gültiger Zustand, und der Unterschied zwischen „gibt es
 * nicht" und „gibt es und ist leer" ist eine Auskunft, keine Lücke.
 *
 * > **„Nicht nachgesehen" ist nicht dasselbe wie „nichts da".** Der Satz steht
 * > seit P7 über `kernelStale()` in diesem Repo, und er gilt hier für jede
 * > Zeile dieser Antwort.
 *
 * **Das Journal wird hier nicht abgefragt.** Acht Units einzeln zu fragen
 * kostet acht Aufrufe von `journalctl` für eine Seite, die nur eine Liste
 * zeigt — und die Antwort wäre ohnehin nicht „leer" oder „voll", sondern „so
 * viele Zeilen, wie du sehen willst". Gemeldet wird nur, ob es auf diesem
 * System überhaupt ein Journal gibt.
 */
final class SystemLogsList implements Op
{
    public static function name(): string
    {
        return 'system.logs.list';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $sources = [];

        foreach (Logs::sources() as $key => $source) {
            $sources[] = $source['kind'] === Logs::FILE
                ? $this->file($key, $source)
                : $this->journal($key, $source);
        }

        return ['sources' => $sources, 'journal' => is_dir('/run/systemd/system')];
    }

    /**
     * @param  array{kind: string, label: string, path: null|string, unit: null|string}  $source
     * @return array<string, mixed>
     */
    private function file(string $key, array $source): array
    {
        $path = (string) $source['path'];
        $stat = @stat($path);

        return [
            'key' => $key,
            'kind' => $source['kind'],
            'label' => $source['label'],
            'origin' => $path,
            'exists' => $stat !== false,
            'size' => $stat === false ? 0 : $stat['size'],

            // Der Zeitpunkt reist als Unixzeit und nicht als fertiger Text:
            // Die Anzeigezone kennt nur das Panel (`App\Support\Time\Clock`),
            // und ein hier gebauter Text wäre die zweite Fassung davon.
            'modified' => $stat === false ? null : $stat['mtime'],
        ];
    }

    /**
     * @param  array{kind: string, label: string, path: null|string, unit: null|string}  $source
     * @return array<string, mixed>
     */
    private function journal(string $key, array $source): array
    {
        return [
            'key' => $key,
            'kind' => $source['kind'],
            'label' => $source['label'],
            'origin' => (string) $source['unit'],

            // **Kein `exists` und keine Grösse.** Beides wäre geraten: Ob eine
            // Unit Einträge hat, weiss erst der Abruf, und ein Journal hat
            // keine Grösse je Unit. Eine 0 hier hiesse „leer" und bedeutete
            // „nicht gefragt".
            'exists' => null,
            'size' => null,
            'modified' => null,
        ];
    }
}
