<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\DiskQuota;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;

/**
 * Legt die Systemseite eines Abonnements an: Gruppe, Systembenutzer,
 * Verzeichnisschema nach §4.5 und die Dateisystem-Quota.
 *
 * **Die Operation nimmt keinen Pfad entgegen — sie baut ihn.** Das ist die
 * wichtigste Entscheidung in dieser Datei. Übergeben wird der *Name* des
 * Abonnements, geprüft gegen eine Positivliste; der Pfad entsteht hier als
 * `/var/www/vhosts/<name>`. Damit gibt es kein `..`, keinen Symlink und keinen
 * absoluten Pfad, den ein Aufrufer unterschieben könnte. Eine Operation, die
 * einen Pfad annimmt und ihn danach prüft, ist eine Operation, deren Prüfung
 * irgendwann eine Lücke hat.
 *
 * **Der Benutzername wird nicht frei gewählt.** Er muss `p` gefolgt von
 * Ziffern sein. Ein Aufrufer, der sich `root`, `www-data` oder `srvpanel`
 * wünschen könnte, hätte über `useradd` einen Weg, ein bestehendes Konto zu
 * berühren — und über `setquota` einen Weg, ihm eine Quota zu setzen.
 *
 * **Warum die Wurzel root gehört und der Inhalt dem Kunden.** OpenSSH
 * verweigert ein Chroot, dessen Wurzel dem eingesperrten Benutzer gehört oder
 * für andere schreibbar ist — und zwar wortlos beim Verbindungsaufbau. Die
 * Zweiteilung aus §4.5 ist deshalb keine Vorsicht, sondern eine Vorgabe:
 * Wurzel `root:root 0755`, Inhalt `<benutzer>:<gruppe>`.
 *
 * **Der Lauf ist wiederholbar.** Ein zweiter Aufruf mit denselben Werten legt
 * nichts doppelt an und wirft nichts weg. Das ist die Voraussetzung dafür,
 * dass ein abgebrochener Vorgang wiederholt werden kann, ohne dass jemand
 * vorher von Hand aufräumt.
 */
final class SubscriptionProvision implements Op
{
    /** Die Wurzel aller Abonnements. Steht hier und kommt nicht von aussen. */
    public const VHOSTS = '/var/www/vhosts';

    /**
     * Das Verzeichnis, das später ausgeliefert wird (§4.5).
     *
     * Es steht als Konstante da, weil ab P3 der vhost darauf zeigt — und ein
     * Verzeichnisname, der an zwei Stellen getippt wird, ist ein
     * Verzeichnisname, der irgendwann an einer davon anders lautet.
     */
    public const DOCUMENT_ROOT = 'httpdocs';

    /**
     * Das Verzeichnisschema aus §4.5.
     *
     * Eigentümer `null` heisst root. `%u` ist der Systembenutzer des
     * Abonnements, `%g` seine Gruppe.
     *
     * @var array<string, array{0: string, 1: string, 2: int}> Pfad => [Eigentümer, Gruppe, Rechte]
     */
    private const TREE = [
        self::DOCUMENT_ROOT => ['%u', 'www-data', 0750],
        'logs' => ['%u', 'adm', 0750],
        'tmp' => ['%u', '%g', 0700],
        'conf' => ['root', 'root', 0755],
        '.ssh' => ['%u', '%g', 0700],

        // §5.5: Das Schema hält den Platz für Postfix/Dovecot frei, damit die
        // Mail-Stufe später nicht das Verzeichnis eines laufenden Abonnements
        // umbauen muss.
        'mail' => ['%u', '%g', 0700],
    ];

    /**
     * Die Verzeichnisse des Schemas, die **kein** DocumentRoot sein dürfen.
     *
     * Sie stehen hier und nicht im Panel, weil sie aus derselben Tabelle
     * kommen wie das Schema selbst: Wächst {@see self::TREE}, wächst diese
     * Liste mit. Ein Panel, das `logs`, `conf`, `tmp`, `.ssh` und `mail`
     * abgetippt hätte, wäre bei der ersten Erweiterung des Schemas falsch
     * geworden — und was daraus folgt, ist keine Kleinigkeit: Ein
     * DocumentRoot auf `logs` liefert die Zugriffsprotokolle des Kunden über
     * HTTP aus, eines auf `.ssh` seine Schlüssel.
     *
     * @return list<string>
     */
    public static function reservedDirectories(): array
    {
        return array_values(array_diff(array_keys(self::TREE), [self::DOCUMENT_ROOT]));
    }

    public static function name(): string
    {
        return 'subscription.provision';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $name = self::subscriptionName($args['name'] ?? null);
        $user = self::systemUser($args['user'] ?? null);
        $quotaMb = DiskQuota::limit($args['quota_mb'] ?? null);

        $root = self::VHOSTS.'/'.$name;

        $context->progress(10, 'Gruppe und Systembenutzer');
        $created = $this->account($context, $user, $root);

        $context->progress(45, 'Verzeichnisse');
        $this->tree($root, $user);

        $context->progress(60, 'Willkommensseite');
        $welcome = $this->welcome($root.'/'.self::DOCUMENT_ROOT, $user);

        $context->progress(75, 'Quota');
        $quota = $this->quotaApply($context, $user, $quotaMb);

        $context->progress(100, 'fertig');

        return [
            'name' => $name,
            'user' => $user,
            'root' => $root,
            'document_root' => $root.'/'.self::DOCUMENT_ROOT,
            'created' => $created,
            'welcome' => $welcome,
            'quota' => $quota,
        ];
    }

    /**
     * Der Name des Abonnements — und damit der Verzeichnisname.
     *
     * Erlaubt sind Kleinbuchstaben, Ziffern, Punkt und Bindestrich, beginnend
     * und endend mit einem alphanumerischen Zeichen. Das deckt Domainnamen ab
     * und schliesst alles aus, was ein Pfad sein könnte: kein Schrägstrich,
     * kein Punkt am Anfang, kein `..`.
     */
    public static function subscriptionName(mixed $value): string
    {
        $name = Guard::string($value, 'name');

        if (! preg_match('/^[a-z0-9]([a-z0-9.\-]{0,61}[a-z0-9])?$/D', $name)) {
            throw AgentException::badRequest('Unzulässiger Name für ein Abonnement.', ['name' => $name]);
        }

        if (str_contains($name, '..')) {
            throw AgentException::badRequest('Unzulässiger Name für ein Abonnement.', ['name' => $name]);
        }

        return $name;
    }

    /**
     * Der Systembenutzer: `p` und Ziffern, sonst nichts.
     *
     * Die enge Form ist Absicht. Ein frei gewählter Name wäre ein Weg, über
     * `useradd`/`usermod` ein bestehendes Konto zu berühren — `root`,
     * `www-data`, `srvpanel` oder das Konto eines anderen Abonnements.
     * Ausserdem bleibt die Länge unter der Grenze, ab der `useradd` auf
     * manchen Systemen abweist.
     */
    public static function systemUser(mixed $value): string
    {
        $user = Guard::string($value, 'user');

        if (! preg_match('/^p[0-9]{4,9}$/D', $user)) {
            throw AgentException::badRequest(
                'Unzulässiger Systembenutzer — erwartet wird „p" und vier bis neun Ziffern.',
                ['user' => $user],
            );
        }

        return $user;
    }

    /**
     * Gruppe und Benutzer anlegen.
     *
     * `--no-user-group` und die eigene Gruppe zuerst: `useradd` legt sonst je
     * nach Distribution eine Gruppe mit demselben Namen an oder steckt den
     * Benutzer in `users` — und dann sähe jedes Abonnement die Dateien jedes
     * anderen, weil `0750` auf einer geteilten Gruppe nichts ausschliesst.
     *
     * Keine Login-Shell und kein Passwort: Der Zugang läuft über SFTP mit
     * Chroot (P6), nicht über eine Shell.
     *
     * @return bool Wurde das Konto in diesem Lauf angelegt?
     */
    private function account(Context $context, string $user, string $root): bool
    {
        if ($this->exists($user)) {
            return false;
        }

        $group = $context->stream('groupadd', ['--force', $user]);

        if (! $group->successful()) {
            throw AgentException::execFailed(
                'Gruppe konnte nicht angelegt werden.',
                ['user' => $user, 'stderr' => $group->stderr],
            );
        }

        // `--no-create-home`: Das Home ist die Chroot-Wurzel, und die muss
        // root gehören. Liesse man useradd sie anlegen, gehörte sie dem
        // Benutzer — und OpenSSH verweigerte das Chroot wortlos.
        $result = $context->stream('useradd', [
            '--gid', $user,
            '--no-user-group',
            '--home-dir', $root,
            '--no-create-home',
            '--shell', '/usr/sbin/nologin',
            '--comment', 'SrvPanel-Abonnement',
            $user,
        ]);

        if (! $result->successful()) {
            throw AgentException::execFailed(
                'Systembenutzer konnte nicht angelegt werden.',
                ['user' => $user, 'stderr' => $result->stderr],
            );
        }

        return true;
    }

    private function exists(string $user): bool
    {
        return posix_getpwnam($user) !== false;
    }

    /**
     * Das Verzeichnisschema anlegen — und bei jedem Lauf die Rechte
     * geraderücken.
     *
     * Auch für bestehende Verzeichnisse: Ein Abonnement, an dessen Rechten
     * jemand von Hand gedreht hat, kommt so beim nächsten Lauf zurück auf den
     * Stand aus §4.5. Das ist der Unterschied zwischen einem Schema, das
     * gilt, und einem, das einmal galt.
     */
    private function tree(string $root, string $user): void
    {
        $this->directory($root, 'root', 'root', 0755);

        foreach (self::TREE as $part => [$owner, $group, $mode]) {
            $this->directory(
                $root.'/'.$part,
                $owner === '%u' ? $user : $owner,
                $group === '%g' ? $user : $group,
                $mode,
            );
        }
    }

    /**
     * Die Willkommensseite — und die Bedingung, unter der sie entsteht.
     *
     * **Sie wird nur geschrieben, wenn das Verzeichnis leer ist.** Das ist
     * keine Vorsicht, sondern die Bedingung dafür, dass diese Operation
     * wiederholbar bleiben darf: Ein zweiter Lauf — nach einem abgebrochenen
     * Vorgang, nach einer Kontingentänderung, nach einem Umzug — träfe sonst
     * auf eine fertige Webseite und legte eine `index.html` daneben, die vor
     * `index.php` gefunden wird. Der Kunde sähe statt seiner Seite wieder den
     * Platzhalter, und niemand käme auf den Gedanken, dass das Panel das war.
     *
     * Geprüft wird das ganze Verzeichnis und nicht nur die Datei: Wer seine
     * `index.html` gelöscht hat und mit `index.php` arbeitet, hat damit eine
     * Entscheidung getroffen.
     *
     * @return bool Wurde sie in diesem Lauf geschrieben?
     */
    private function welcome(string $documentRoot, string $user): bool
    {
        $entries = @scandir($documentRoot);

        if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
            return false;
        }

        $path = $documentRoot.'/index.html';

        if (@file_put_contents($path, self::welcomePage()) === false) {
            return false;
        }

        // Lesbar für den Webserver, schreibbar für den Kunden — dieselbe
        // Aufteilung wie beim Verzeichnis darüber.
        chown($path, $user);
        chgrp($path, posix_getgrnam('www-data') !== false ? 'www-data' : $user);
        chmod($path, 0o640);

        return true;
    }

    /**
     * Der Inhalt der Willkommensseite.
     *
     * **Sie nennt weder den Abonnementnamen noch den Systembenutzer noch das
     * Panel.** Sobald eine Domain hierher zeigt, ist sie öffentlich, und was
     * öffentlich ist, sollte über den Server nichts erzählen: Ein
     * Platzhalter, auf dem „Abonnement kunde-example.de, Systembenutzer
     * p1003" steht, ist eine Einladung, in der Suchmaschine nach weiteren zu
     * suchen. Wer die Seite sieht, weiss ohnehin, wessen Domain er aufgerufen
     * hat.
     *
     * Alles in einer Datei: keine Schrift, kein Bild, kein Stylesheet von
     * aussen. Ein Platzhalter, der beim ersten Aufruf eine fremde Adresse
     * kontaktiert, ist ein Platzhalter, der etwas verrät.
     *
     * Public, damit ein Test den Inhalt lesen kann, ohne ein Verzeichnis
     * anzulegen — dieselbe Zuschnittsentscheidung wie bei der nginx-Vorlage.
     */
    public static function welcomePage(): string
    {
        return <<<'HTML'
            <!doctype html>
            <html lang="de">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex">
            <title>Diese Domain ist eingerichtet</title>
            <style>
            :root { color-scheme: light dark; }
            body {
              margin: 0; min-height: 100vh;
              display: grid; place-items: center;
              padding: 24px;
              font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
              line-height: 1.55;
            }
            main { max-width: 34rem; }
            h1 { margin: 0 0 12px; font-size: 1.35rem; font-weight: 600; }
            p { margin: 0 0 10px; }
            .leise { opacity: .7; font-size: .9rem; }
            </style>
            </head>
            <body>
            <main>
            <h1>Diese Domain ist eingerichtet</h1>
            <p>
              Der Webspace steht bereit und liefert aus — es liegen nur noch
              keine Inhalte darin.
            </p>
            <p>
              Wer hier Inhalte ablegen möchte, findet den Zugang in seinen
              Vertragsunterlagen. Die Dateien gehören in das Verzeichnis
              <code>httpdocs</code>; sobald dort eine eigene Startseite liegt,
              verschwindet diese Seite.
            </p>
            <p class="leise">
              Diese Seite wurde beim Einrichten des Webspace erzeugt.
            </p>
            </main>
            </body>
            </html>
            HTML;
    }

    private function directory(string $path, string $owner, string $group, int $mode): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw AgentException::execFailed(
                'Verzeichnis konnte nicht angelegt werden.',
                ['path' => $path],
            );
        }

        // Eine Gruppe, die es nicht gibt, ist kein Grund zum Abbruch: `adm`
        // fehlt auf schlanken Installationen. Das Verzeichnis gehört dann dem
        // Benutzer allein — enger als vorgesehen, nicht weiter.
        $groupExists = posix_getgrnam($group) !== false;

        chown($path, $owner);
        chgrp($path, $groupExists ? $group : $owner);
        chmod($path, $mode);
    }

    /**
     * Die Dateisystem-Quota setzen.
     *
     * Die Mechanik steht in {@see DiskQuota} — dieselbe, die
     * `subscription.quota` benutzt, wenn ein Kontingent nachträglich geändert
     * wird. Eine zweite Fassung hier hiesse, dass zwei Wege dieselbe Grenze
     * setzen und einer davon irgendwann anders rechnet.
     *
     * @return array{enforced: bool, limit_mb: int, reason?: string}
     */
    private function quotaApply(Context $context, string $user, int $quotaMb): array
    {
        return DiskQuota::apply($context, $user, $quotaMb);
    }
}
