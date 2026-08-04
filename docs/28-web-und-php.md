# Web und PHP

Was in P3 entstanden ist und **warum es so aussieht**. Der Plan
([20](20-hostingpanel-neuplan.md) §9 P3) sagt, was gebaut werden soll; hier
steht, was beim Bauen entschieden wurde und was dabei schiefging.

---

## 1. Der Agent besitzt die Vorlagen — auch die der Kunden

§4.2 des Plans: „Die Anwendung liefert Struktur (Domain, DocumentRoot,
PHP-Version, Zertifikatspfade), nicht Text." Für den Server-Block des Panels
galt das seit P0; ab P3 gilt es für jede Kundendomain.

Wer eine nginx-Konfiguration schreiben darf, darf über `root` jedes Verzeichnis
des Servers ausliefern. Deshalb entstehen Server-Block und FPM-Pool im Agenten
aus `SiteTemplate` und `PoolTemplate`, und das Panel schickt Werte.

**Eine Klasse baut alle Pfade.** Zu einer Domain gehören sechs: Server-Block,
Include-Datei, DocumentRoot, Protokollverzeichnis, FPM-Sockel und die Wurzel des
Abonnements. Sie stehen in `SrvPanel\Agent\Site` und nirgends sonst. Verteilt
auf `apply`, `remove` und die Sperre wären es drei Gelegenheiten, einen davon
anders zu bilden — und die Operation, die **entfernt**, wäre die schlechteste
Stelle für eine Abweichung.

Übergeben wird ein **relatives** DocumentRoot. Der absolute Pfad entsteht im
Agenten aus dem geprüften Namen des Abonnements; damit gibt es kein `..`, keinen
Symlink und keinen absoluten Pfad, den ein Aufrufer unterschieben könnte. Das
ist dieselbe Entscheidung wie in `subscription.provision`, wo sie im
Klassenkommentar als die wichtigste der Datei steht.

## 2. `web.site.state` gibt es nicht

Der Plan sah zwei Operationen vor: eine, die anwendet, und eine, die sperrt.
Beim Bauen ist daraus eine geworden.

Beide hätten denselben Server-Block geschrieben, nur mit einem anderen Rumpf.
Zwei Wege zu einer Datei sind zwei Gelegenheiten, sie unterschiedlich zu bauen —
und die Sperre wäre der Weg, der seltener läuft und deshalb später auffällt.
Das Panel schickt deshalb den **gewünschten Zustand**, nicht die Veränderung;
`suspended` ist ein Feld darin.

Eine gesperrte Website antwortet mit **503** und einer Erklärung. Bis P3 setzte
`subscription.suspend` nur die Rechte des Verzeichnisses auf `0750`, und ein
Besucher bekam einen nackten „403 Forbidden" — die Antwort auf „du darfst
nicht" statt auf „diese Website ist gerade nicht in Betrieb". 503 sagt
zusätzlich jeder Suchmaschine, dass sie es später wieder versuchen soll.

## 3. Ohne PHP-Version wird `.php` verweigert

Der Fehler, der bei jeder statischen Website teuer wird: Ohne Handler liefert
nginx eine PHP-Datei als Text aus — mit Datenbankpasswort, Schlüsseln und allem,
was darin steht. Der Server-Block einer Domain ohne PHP-Version enthält deshalb
`location ~ \.php$ { return 404; }`.

## 4. Der Standardschutz ist kein Häkchen

§9 P3, letzter Spiegelstrich. In der Vorlage und nicht abschaltbar:

- Punktdateien in einem Ausdruck — `.git`, `.env`, `.htaccess`, `.svn`.
  **Ausgenommen ist `.well-known`**, sonst bekäme ab P4 keine Domain je ein
  Zertifikat: Dort legt die ACME-Prüfung ihre Datei ab.
- Kein PHP in Verzeichnissen, in die hochgeladen wird. Ein Bild mit der Endung
  `.php` ist der kürzeste Weg von einem Formular zu einer Shell.
- `try_files $uri =404` **vor** dem Handler. Ohne diese Zeile führt eine
  Anfrage auf `/bild.jpg/schad.php` dazu, dass nginx die hochgeladene Datei an
  PHP übergibt.

Ein Schutz, den man vergessen kann, ist bei tausend Abonnements ein Schutz, den
jemand vergessen hat.

## 5. Die Abschottung liegt im Pool, die Einstellungen in der Domain

Das ist die Trennung, an der das Abnahmekriterium hängt.

| Wo | Was | Warum dort |
|---|---|---|
| Pool, `php_admin_value` | `open_basedir`, `disable_functions`, `upload_tmp_dir`, `sys_temp_dir`, `session.save_path` | Nicht überschreibbar — weder durch `ini_set()` im Skript noch durch `PHP_VALUE` aus dem Server-Block |
| Server-Block, `fastcgi_param PHP_VALUE` | `memory_limit`, `upload_max_filesize`, `max_execution_time`, `display_errors` … | Ein Pool bedient drei Domains und kann nicht drei `memory_limit` haben |

Stünde `open_basedir` als `php_value` im Pool, wäre es eine Empfehlung. Der
Unterschied ist eine Zeichenkette in der Vorlage und die ganze Abschottung.

**Kein geteiltes `/tmp`.** Dort begegnen sich sonst die hochgeladenen Dateien
und die Sitzungskennungen zweier Abonnements — der klassische Weg, die Sitzung
eines fremden Kunden zu übernehmen. Jedes Abonnement hat sein eigenes `tmp` aus
§4.5.

**`security.limit_extensions = .php`.** Die Voreinstellung erlaubt zusätzlich
`.phar` — ein Archiv, das PHP ausführt, und damit ein zweiter Weg an jeder
Prüfung vorbei, die auf die Endung `.php` sieht.

**Der Standard-Pool der Distribution wird abgeschaltet.** `phpX.Y-fpm` bringt
`www.conf` mit: geteilt, als `www-data`, ohne `open_basedir`. Genau das Loch,
das P3 zumacht. `php.version.install` benennt ihn um.

## 6. Eigene nginx-Direktiven

Die einzige Stelle in P3, an der Text eines Kunden in einer Datei landet, die
als root gelesen wird. §4.2 lässt sie ausdrücklich zu — „gegen eine Positivliste
erlaubter Direktiven geprüft" —, und `SrvPanel\Agent\Directives` ist die
Einlösung dieses Halbsatzes.

Fünfzehn Namen sind erlaubt. Geprüft wird der **Name gegen eine Liste**, nicht
der Wert gegen Verbotenes: Wer „gefährliche Zeichen" herausfiltert, hat immer
eines vergessen.

**Keine Blöcke.** `{` und `}` sind ausgeschlossen und damit auch `location`.
Das ist eine echte Einschränkung; der Grund ist die Reichweite: Ein eigener
Block kann `root`, `alias` oder `fastcgi_pass` enthalten, und damit liefert die
Domain eines Kunden jedes Verzeichnis des Servers aus oder schickt Anfragen an
den Pool eines anderen Abonnements.

Was einen Pfad oder einen Empfänger bestimmt, kommt nicht auf die Liste —
`DirectiveAllowlistTest` prüft **die Liste** und nicht nur die Prüfung.

## 7. Drei Mengen von PHP-Versionen

| Menge | Wo | Wer sie ändert |
|---|---|---|
| Katalog | `SrvPanel\Agent\PhpVersions::CATALOG` | eine neue Version des Panels |
| installiert | auf dem Server, gemessen über `php.versions` | der Betreiber, über einen Vorgang |
| vom Plan erlaubt | `Quota::PhpVersions` | der Betreiber, im Plan |

Wählbar ist der **Schnitt aus allen dreien**, und diese Rechnung steht in
`App\Support\Web\PhpSelection` — an einer Stelle. Stünde sie zusätzlich im
Formular, gäbe es zwei Antworten auf dieselbe Frage, und die im Formular wäre
die freundlichere.

**Der Kunde fordert nichts an.** Installiert wird von `/settings/php`, und das
ist Betreibersache. Was der Kunde sieht, ist ein Zustand: seine wählbaren
Versionen, und daneben die, die sein Plan hergibt und die es auf dem Server
nicht gibt — abgeblendet, mit dem Grund. Er sieht damit, dass die Lücke am
Server liegt und nicht an seinem Vertrag. Ein Knopf „anfordern" wäre ein halber
Ticketkanal: Er drückt, sichtbar passiert nichts, und niemand ist zuständig.

**Ein leerer Zwischenspeicher heisst „nichts installiert".** Vor dem ersten Lauf
von `php.versions` weiss das Panel es nicht, und eine Domain mit einer Version
anzulegen, die es vielleicht nicht gibt, endet in einem Server-Block, den der
Agent zurückweist. „Nichts" ist die sichere Richtung.

**Die PHP-Version einer Domain wird gespeichert und nicht ausgerechnet.** Eine
Vorgabe, die sich aus „der neuesten installierten Version" ergäbe, würde jede
Website ohne eigene Wahl in dem Moment umstellen, in dem der Betreiber eine
neue Version installiert — eine Systemänderung ohne Handelnden, und die
Anwendung des Kunden merkt es als Erste.

## 8. Zwei Vorgänge je Domain, in dieser Reihenfolge

Erst `php.pool.apply`, dann `web.site.apply`. Der Agent weist einen
Server-Block zurück, dessen FPM-Pool fehlt — sonst zeigte `fastcgi_pass` auf
einen Sockel, den niemand bedient, und die Website antwortete mit „502 Bad
Gateway", während im Panel alles grün aussieht.

Beides in eine Operation zu packen hiesse, dass der Agent zwei Dinge auf einmal
tut und bei einem Fehlschlag die Hälfte davon getan hat. Die Reihenfolge trägt
die Warteschlange; sie hat einen Arbeiter, und der arbeitet der Reihe nach.

## 9. Der Rückbau reicht seit P3 über das Abo-Verzeichnis hinaus

Bis P2 lag alles zu einem Abonnement unter `/var/www/vhosts/<abo>`, und der
Baumlauf nahm es mit. Mit den Websites liegen drei Dinge ausserhalb:

- der Server-Block in `/etc/nginx/srvpanel.d`,
- der FPM-Pool in `/etc/php/<version>/fpm/pool.d`,
- die Rotation in `/etc/logrotate.d`.

`subscription.remove` räumt sie mit ab, **bevor** das Verzeichnis fällt: Ein
nginx, das zwischen beiden Schritten neu lädt, fände sonst ein `root`, das es
nicht mehr gibt.

**Die Server-Blöcke werden gesucht und nicht übergeben.** Das Panel wüsste,
welche Domains es gab — nur ist genau das die Liste, die nach einem
abgebrochenen Lauf unvollständig ist. Gesucht wird in einem Verzeichnis, das
ausschliesslich srvpanel gehört, nach dem Pfad des Abonnements: Jeder erzeugte
Block trägt ihn in `access_log`. Das findet auch die Reste, die niemand mehr auf
der Rechnung hat.

**Eine gelöschte Domain gibt ihren Namen frei.** Anders als beim Abonnement,
dessen Systembenutzer verbraucht bleiben muss: Mit einer Domain gehen ihr
Verzeichnis, ihr vhost und ihr Protokoll, danach ist der Name auf dem Server
nirgends mehr belegt. Ihn trotzdem zu sperren hiesse, dass ein versehentlich
gelöschter Eintrag nie wieder anlegbar wäre — auch nicht für den Kunden, dem
die Domain gehört.

Der Rückbau eines **Abonnements** löscht seine Domainzeilen deshalb hart. Der
Fremdschlüssel hilft dabei nicht: `cascadeOnDelete` greift beim harten Löschen
und nicht bei `deleted_at`.

## 10. Was der Abnahmelauf beweist

`srvpanel acceptance-web` legt zwei Abonnements mit je drei Domains auf zwei
PHP-Versionen an und fragt jede über HTTP — durch nginx, durch den Pool, als
der Systembenutzer des Abonnements. Geprüft werden vier Dinge:

1. Jede Domain antwortet.
2. Mit ihrer PHP-Version — der, die der Prozess meldet, nicht der aus dem Panel.
3. Unter ihrem eigenen Systembenutzer. Ein Pool, der als `www-data` liefe, sähe
   von aussen genauso aus.
4. Und sie kommt **nicht** an die Dateien des anderen Abonnements.

Der vierte Punkt ist das Kriterium. Man kann in die Pool-Vorlage sehen und
feststellen, dass `open_basedir` dasteht; das zeigt nicht, dass PHP es anwendet,
dass nginx den richtigen Sockel trifft und dass die Rechte des Verzeichnisses
stimmen. Das zeigt nur ein Skript, das es versucht.

**Die Selbstprobe verrät nichts.** Sie antwortet mit „lesbar: ja/nein" und
niemals mit dem Inhalt einer Datei. Ein Selbsttest, der bei einem Fehlschlag
die Datei ausgibt, an die er nicht hätte kommen dürfen, hat aus einem Beleg ein
Leck gemacht. Ihr Inhalt steht im Agenten und kommt nicht als Argument —
dieselbe Regel wie bei der Willkommensseite.

Die Domains des Laufs enden auf `.invalid` (RFC 2606) und stehen in keinem DNS.
Gefragt wird über `127.0.0.1` mit dem Hostnamen im Header; damit trifft ein
Abnahmelauf niemals eine echte Domain.

### Der Lauf ist gelaufen

Auf dem Server des Betreibers, aus dem Paket `0.3.0~rc.5`:

```
  abnahme-web-1.invalid: Selbstprobe in httpdocs, eins-abnahme-web-1.invalid, zwei-abnahme-web-1.invalid
  abnahme-web-2.invalid: Selbstprobe in httpdocs, eins-abnahme-web-2.invalid, zwei-abnahme-web-2.invalid

Das Abnahmekriterium von P3 ist erfüllt.
Sechs Domains, zwei PHP-Versionen, zwei Systembenutzer — und kein Zugriff über die Grenze.
```

Damit ist das Kriterium aus §9 keine Zusage mehr, sondern eine Feststellung.
Geprüft ist nicht die Vorlage, sondern die Kette: nginx nimmt die Anfrage an,
trifft den richtigen Sockel, der Pool läuft unter dem Systembenutzer des
Abonnements, PHP wendet `open_basedir` an, und die Rechte auf dem Dateisystem
stimmen. Der Rückbau danach hat acht Pool-Dateien über vier Katalogversionen
hinterlassen — keine einzige.

**Vier Anläufe hat es gebraucht, und keiner davon scheiterte an der
Abschottung.** Der Reihe nach: ein Abonnement, dessen Zustand im Speicher
veraltet war; ein Rückbau, der zu spät begann und Systembenutzer stehenliess;
eine Abschrift der Hauptdomain, die einen Namen festhielt, den das Original
längst freigegeben hatte; und die Selbstprobe, die für vier von sechs Domains
im falschen Verzeichnis lag. Drei davon waren Fehler im Prüfwerkzeug und nicht
im Geprüften — was die Sache nicht besser macht: Ein Werkzeug, das falsch
misst, ist genauso teuer wie ein Fehler im Gemessenen, und es kostet zusätzlich
das Vertrauen in das Ergebnis.

Die Lehre steht in `AcceptanceWebCommandTest`: Was sich am Abnahmelauf **ohne**
Server prüfen lässt, ist mehr, als es aussieht — das Auffrischen der Modelle,
das Fenster zwischen Vorgang und Zustand, der Rückbau im `finally`, die
Ableitung der Verzeichnisse und jede einzelne Fehlermeldung.

## 11. Was noch fehlt

- **Traffic messen.** Das Kontingent steht im Katalog und ist als „gemessen,
  nicht erzwungen" beschrieben. Die Zugriffsprotokolle je Domain gibt es seit
  P3; ausgewertet werden sie in P9, wo die Statistik entsteht.
- **Umlautdomains** müssen als Punycode eingegeben werden. Der Agent hat kein
  `intl` — §4.1 zählt seine Erweiterungen abschliessend auf, und das ist eine
  Zusage. Die Umwandlung gehört ins Panel und ist offen.
- **Eigene `location`-Blöcke.** Siehe §6: Sie sind bewusst draussen. Wer sie
  braucht, braucht dafür einen Entwurf, der nicht auf einer Positivliste
  innerhalb eines fremden Blocks beruht.
- **HTTPS.** Alle Server-Blöcke hören auf Port 80. Das Zertifikat kommt in P4;
  bis dahin ist eine Kundenwebsite unverschlüsselt erreichbar, und das steht
  hier, damit es niemand für ein Versehen hält.
