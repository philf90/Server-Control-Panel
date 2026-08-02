import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import '../css/app.css'

createInertiaApp({
  title: (titel) => (titel ? `${titel} · CloudSrv` : 'CloudSrv'),
  resolve: (name) => {
    const seiten = import.meta.glob('./Seiten/**/*.vue', { eager: true })
    const seite = seiten[`./Seiten/${name}.vue`]

    if (!seite) {
      throw new Error(`Seite ${name} gibt es nicht.`)
    }

    return seite as object
  },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },
})
