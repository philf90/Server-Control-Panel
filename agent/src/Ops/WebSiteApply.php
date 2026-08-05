<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Filesystem;
use SrvPanel\Agent\NginxApply;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\PhpVersions;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;
use SrvPanel\Agent\WelcomePage;

/**
 * Eine Website in den Zustand bringen, den das Panel beschreibt.
 *
 * **Es gibt keine zweite Operation für „sperren".** Der Plan sah `web.site.apply`
 * und `web.site.state` getrennt vor; beim Bauen ist daraus eine geworden, und
 * zwar aus einem Grund, der sich am fertigen Code besser sehen lässt als am
 * Entwurf: Beide hätten denselben Server-Block geschrieben, nur mit einem
 * anderen Rumpf. Zwei Wege zu einer Datei sind zwei Gelegenheiten, sie
 * unterschiedlich zu bauen — und die Sperre wäre der Weg, der seltener läuft
 * und deshalb später auffällt. Das Panel schickt den **gewünschten Zustand**,
 * nicht die Veränderung; `suspended` ist ein Feld darin.
 *
 * **Wiederholbar.** Ein zweiter Lauf mit denselben Werten schreibt dieselben
 * Dateien und lädt nginx neu. Das ist die Voraussetzung dafür, dass ein
 * abgebrochener Vorgang wiederholt werden kann, ohne dass jemand von Hand
 * aufräumt.
 *
 * **Ohne Handler kein Server-Block.** Verlangt die Domain eine PHP-Version,
 * die auf diesem Server nicht installiert ist, bricht die Operation ab, statt
 * einen `fastcgi_pass` auf einen Sockel zu schreiben, den es nicht gibt. Die
 * Alternative wäre eine Website, die mit „502 Bad Gateway" antwortet, während
 * im Panel alles grün aussieht.
 */
final class WebSiteApply implements Op
{
    public static function name(): string
    {
        return 'web.site.apply';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $site = Site::fromArgs($args);

        $context->progress(10, 'Prüfen');
        $this->preconditions($site);

        $context->progress(25, 'Verzeichnisse');
        $created = $this->directories($site);

        /*
         * **Eine Domain ohne Inhalt antwortet sonst mit „403 Forbidden".**
         *
         * Die Willkommensseite entstand in `subscription.provision` und nur
         * für das erste DocumentRoot. Jede weitere Domain bekam ein leeres
         * Verzeichnis — und nginx antwortet darauf mit „directory index is
         * forbidden". Das ist dieselbe falsche Auskunft wie bei der Sperre,
         * die zuerst 403 statt 503 gab: „du darfst nicht" statt „hier ist noch
         * nichts". Gefunden im Abnahmelauf für P4, an einer Domain, die gerade
         * ein gültiges Zertifikat bekommen hatte.
         *
         * Geschrieben wird nur in ein leeres Verzeichnis; die Begründung steht
         * in {@see WelcomePage}, und sie ist die Bedingung dafür, dass diese
         * Operation wiederholbar bleiben darf.
         */
        $documentRoot = $site->documentRootPath();
        $welcome = $documentRoot !== null && WelcomePage::into($documentRoot, $site->user);

        $context->progress(40, 'Server-Block erzeugen');
        $include = NginxApply::ensureInclude();

        NginxApply::commit($context, [
            $site->confFile() => SiteTemplate::render($site),
            $site->includeFile() => SiteTemplate::includeFile($site->directives),
        ]);

        $context->progress(100, 'fertig');

        return [
            'domain' => $site->domain,
            'conf' => $site->confFile(),
            'document_root' => $documentRoot,
            'log_dir' => $site->logDir(),
            'php_version' => $site->phpVersion,
            'socket' => $site->socket(),
            'suspended' => $site->suspended,
            'created' => $created,
            'welcome' => $welcome,
            'include_written' => $include,
        ];
    }

    /**
     * Was dasein muss, bevor geschrieben wird.
     *
     * Die Wurzel des Abonnements zuerst: Ohne sie gäbe es kein Verzeichnis,
     * in das die Protokolle könnten — und `mkdir -p` legte den ganzen Baum
     * an, ohne Systembenutzer, ohne Quota, ohne die Rechte aus §4.5. Ein
     * Abonnement, das so entstünde, sähe aus wie eines und wäre keines.
     */
    private function preconditions(Site $site): void
    {
        if (! is_dir($site->subscriptionRoot())) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                'Das Abonnement hat kein Verzeichnis — erst subscription.provision.',
                ['subscription' => $site->subscription],
            );
        }

        if ($site->phpVersion === null) {
            return;
        }

        if (! PhpVersions::installed($site->phpVersion)) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                sprintf('PHP %s ist auf diesem Server nicht installiert.', $site->phpVersion),
                ['php_version' => $site->phpVersion, 'available' => PhpVersions::available()],
            );
        }

        /*
         * **Und der Pool muss liegen.** Ohne ihn zeigt `fastcgi_pass` auf
         * einen Sockel, den niemand bedient — die Website antwortet mit „502
         * Bad Gateway", während im Panel alles grün aussieht. Die Reihenfolge
         * (erst `php.pool.apply`, dann `web.site.apply`) stellt der Dienst
         * her; diese Bedingung sorgt dafür, dass sie nicht bloss angenommen
         * wird.
         */
        if (! is_file(PhpVersions::poolFile($site->phpVersion, $site->user))) {
            throw new AgentException(
                AgentException::NOT_FOUND,
                sprintf('Für dieses Abonnement gibt es keinen FPM-Pool in PHP %s — erst php.pool.apply.', $site->phpVersion),
                ['php_version' => $site->phpVersion],
            );
        }
    }

    /**
     * DocumentRoot und Protokollverzeichnis.
     *
     * Die Rechte kommen aus §4.5 und sind dieselben wie beim Anlegen des
     * Abonnements: das ausgelieferte Verzeichnis `<benutzer>:www-data 0750`,
     * damit der Webserver hineinkommt und kein anderes Abonnement; die
     * Protokolle `<benutzer>:adm 0750`.
     *
     * @return list<string> Was in diesem Lauf entstanden ist
     */
    private function directories(Site $site): array
    {
        $created = [];

        $documentRoot = $site->documentRootPath();

        if ($documentRoot !== null && ! is_dir($documentRoot)) {
            Filesystem::directory($documentRoot, $site->user, 'www-data', 0o750);
            $created[] = $documentRoot;
        }

        if (! is_dir($site->logDir())) {
            Filesystem::directory($site->logDir(), $site->user, 'adm', 0o750);
            $created[] = $site->logDir();
        }

        return $created;
    }
}
