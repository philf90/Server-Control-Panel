import { createApp, h, type DefineComponent } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import '../css/app.css'

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
