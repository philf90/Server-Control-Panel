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
