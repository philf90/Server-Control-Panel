import { createApp, h, type DefineComponent } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import '../css/app.css'

/**
 * Jede Anfrage sagt, von welcher Seite sie kommt.
 *
 * ## Der Fehler, den das behebt
 *
 * Bis zum 1. September 2026 hat der **Server** die Herkunft geführt:
 * `RememberPageUrl` schreibt bei jeder Inertia-GET-Anfrage `previousUrl`, und
 * `Origin::current()` las sie. Das veraltet bei jeder Navigation, die der
 * Server nicht sieht — und der Zurück-Knopf des Browsers ist genau eine
 * solche: Inertia stellt aus dem History-Zustand her, es kommt keine Anfrage.
 *
 * Gemessen auf `cloudsrv24` am 31. August (`docs/94 §5`): Vorgang 728 trug
 * `← /operations/727`, obwohl sein Knopf auf `/updates` steht.
 *
 * > **Eine Herkunft, die der Server führt, veraltet bei jeder Navigation, die
 * > der Server nicht sieht.**
 *
 * **Und die Ironie gehört zum Befund:** Der Weg, den der Brotkrümel ersetzen
 * soll — der Zurück-Knopf —, ist genau der, der ihn falsch macht.
 *
 * ## Warum hier und nicht an den Aufrufstellen
 *
 * Die Seite kennt ihre eigene Adresse, und sie weiss es an **einer** Stelle:
 * hier. Einundzwanzig Aufrufstellen wären einundzwanzig Gelegenheiten, es zu
 * vergessen — und die vergessene fiele niemandem auf, weil eine fehlende
 * Herkunft aussieht wie ein Vorgang der Automatik. Das ist derselbe Schluss wie
 * bei `Operation::booted()`, nur auf der anderen Seite der Leitung.
 *
 * ## Was der Server damit tut
 *
 * **Er glaubt ihr nicht.** `Origin::current()` prüft den Wert, und zwar
 * strenger als vorher: Ein Wert aus fremder Hand kann `/\evil.example/x`
 * lauten, und das ist im Browser eine **fremde** Adresse (gemessen mit dem
 * URL-Parser: `https://evil.example/x`), die jede Prüfung auf „fängt mit einem
 * Schrägstrich an" besteht.
 */
router.on('before', (ereignis) => {
  // `location` steht hier noch auf der Seite, von der die Anfrage ausgeht —
  // gewechselt wird erst nach der Antwort.
  ereignis.detail.visit.headers['X-Srvpanel-Origin'] = window.location.pathname + window.location.search
})

createInertiaApp({
  title: (titel) => (titel ? `${titel} · SrvPanel` : 'SrvPanel'),

  /**
   * Der Fortschrittsbalken trägt die Farbe dieses Panels.
   *
   * **Ohne diese Zeile war er blau, seit es diese Datei gibt.** Inertia hat
   * eine eigene Voreinstellung für `color`, und die stand damit auf jeder
   * Seite — in keinem Quelltext, sondern in einem `<style>`, das die
   * Bibliothek beim Start ins Dokument schreibt.
   *
   * > **Ein Wächter über den Quelltext sieht keine Farbe, die das Framework
   * > zur Laufzeit einsetzt.**
   *
   * **`var(--accent)` und kein gelesener Wert:** Die Marke wird in der
   * eingespritzten Regel **am Element** aufgelöst und folgt damit dem Thema;
   * ein über `getComputedStyle` gelesener Wert wäre der beim Start und bliebe
   * beim Umschalten stehen.
   *
   * **Die Verzögerung bleibt bei Inertias Vorgabe.** Sie ist der Grund, dass
   * auf einer schnellen Seite gar nichts blinkt — dieselbe Überlegung wie bei
   * der Schwelle des Platzhalters in `docs/904 §2`. Und der Balken bleibt
   * neben dem Platzhalter, weil er eine andere Frage beantwortet: Der sagt
   * „hier kommt noch etwas", er sagt „überhaupt ist etwas unterwegs".
   *
   * **Der gemessene Vorgabewert steht in `docs/904 §6` und nicht hier.** Die
   * CI prüft `resources/js` auf Farbwerte **ohne die Kommentare abzustreifen**
   * (`.github/workflows/ci.yml`, Schritt „Oberfläche") — ein zitierter Hexwert
   * macht sie rot, auch wenn er nur erklärt, welchen Wert diese Zeile ersetzt.
   */
  progress: { color: 'var(--accent)' },
  /*
   * Das Muster muss zum Verzeichnis passen — und nichts erzwingt das.
   *
   * `import.meta.glob` auf ein Verzeichnis, das es nicht gibt, ist kein
   * Fehler: Es liefert ein leeres Objekt, der Build läuft durch, das Bündel
   * ist um jede Seite leichter, und erst der Browser sagt „gibt es nicht".
   * Genau das war hier eine Zeit lang der Fall, weil das Verzeichnis von
   * `Seiten` auf `Pages` umbenannt wurde und diese Zeile stehen blieb. Weder
   * vue-tsc noch vite noch die Tests haben es bemerkt.
   *
   * Deshalb prüft tests/Feature/InertiaPagesTest.php jetzt beides: dass das
   * Muster hier auf ein Verzeichnis mit Seiten zeigt, und dass zu jedem
   * Inertia::render eine Datei gehört.
   */
  resolve: (name) => {
    const pages = import.meta.glob<{ default: DefineComponent }>('./Pages/**/*.vue', { eager: true })
    const page = pages[`./Pages/${name}.vue`]

    if (!page) {
      throw new Error(`Seite ${name} gibt es nicht.`)
    }

    return page
  },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },
})
