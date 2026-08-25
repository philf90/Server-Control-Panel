import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [
    /*
     * **Zwei Eingänge, und der zweite ist für die Fehlerseiten.**
     *
     * `resources/js/app.ts` zieht `app.css` selbst herein — für jede Seite, die
     * Inertia trägt, genügt der eine Eingang. Die Fehlerseiten (403, 404, 500 …)
     * sind aber reines Blade: Sie haben kein `#app`, und das Bündel würde beim
     * Aufsetzen scheitern. Sie brauchen das Stylesheet ohne das Skript.
     *
     * > **Eine Seite, die es gerade dann geben muss, wenn etwas kaputt ist,
     * > lädt so wenig wie möglich.**
     */
    laravel({ input: ['resources/js/app.ts', 'resources/css/app.css'], refresh: true }),
    vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
    tailwindcss(),
  ],
})
