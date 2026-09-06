<script setup lang="ts">
/*
 * Die Felder einer Ankündigung — einmal, für Anlegen und für Ändern.
 *
 * **Warum eine Komponente und keine zweite Seite.** Bis zum 6. September 2026
 * gab es nur das Anlegen, und es stand als Formular unten auf `/announcements`.
 * Das Ändern ist ein Befund des Abnahmelaufs (`docs/105 §10.4`); es hätte sich
 * mit einem zweiten Formular bauen lassen, und das wäre die Fassung gewesen,
 * die beim nächsten Feld stehenbleibt.
 *
 * > **Was überall dasselbe ist, gehört an eine Stelle — und die muss eine sein,
 * > an der niemand vorbeikommt.**
 *
 * **Und die Anlegeseite bleibt, wo sie ist.** Der naheliegende Weg wäre gewesen,
 * es wie bei den Konten zu machen: `/announcements/create` und
 * `/announcements/{id}/edit` als eine Seite. Das hätte `/announcements`
 * umgebaut — die Ansicht, die der Abnahmelauf am selben Tag in vier Lagen
 * gemessen hat.
 *
 * > **Eine Änderung, die eine gerade gemessene Ansicht umbaut, macht die
 * > Messung wertlos.**
 */
import { useForm } from '@inertiajs/vue3'
import FormErrors from './FormErrors.vue'

const props = defineProps<{
  /** Die bestehende Ankündigung — oder `null`, wenn eine neue entsteht. */
  announcement: { id: number } | null

  values: {
    category: string
    body: string
    visible_from_date: string
    visible_from_time: string
    visible_until_date: string
    visible_until_time: string
    audiences: string[]
  }

  zone: string
  categories: { value: string; label: string }[]
  audiences: { value: string; label: string }[]
}>()

const form = useForm({ ...props.values })

/*
 * **`defaults()` vor `reset()`, und das ist keine Zeremonie.**
 *
 * `form.reset()` allein stellt den Stand vom **Seitenaufbau** her — `docs/84`
 * hat das teuer gelernt: Auf der Zugangsseite kam eine gelöschte Zeile zurück,
 * der Betreiber drückte noch einmal Speichern und legte die Beschränkung wieder
 * an, die er gerade aufgehoben hatte. Beide Vorgänge meldeten Erfolg.
 *
 * > **Eine Anzeige, die den Zustand vor der Änderung zeigt, verleitet zu der
 * > Handlung, die die Änderung zurücknimmt.**
 *
 * Zurückgesetzt wird deshalb **nur beim Anlegen**. Beim Ändern trägt die
 * Weiterleitung auf die Liste den neuen Stand; das Formular wäre dann fort.
 */
function absenden(): void {
  if (props.announcement === null) {
    form.post('/announcements', {
      preserveScroll: true,
      onSuccess: () => {
        form.defaults({ ...props.values })
        form.reset()
      },
    })

    return
  }

  form.patch(`/announcements/${props.announcement.id}`)
}
</script>

<template>
  <!--
    **Die Zusammenfassung steht oben und der Satz nur hier** (`docs/19 §6`):
    Das Feld trägt `aria-invalid` und sonst nichts. Ein roter Rand ohne Wort
    behauptet, das Feld sei falsch, und sagt nicht warum.

    Sie steht **in** der Komponente, weil sie zum Formular gehört — läge sie auf
    der Seite, hätte die zweite Seite sie vergessen.
  -->
  <FormErrors />

  <form @submit.prevent="absenden">
    <label class="field">
      <span>Kategorie</span>
      <select v-model="form.category" :aria-invalid="Boolean(form.errors.category)">
        <option v-for="k in categories" :key="k.value" :value="k.value">{{ k.label }}</option>
      </select>
    </label>

    <label class="field">
      <span>Text</span>
      <textarea
        v-model="form.body"
        rows="3"
        :maxlength="500"
        :aria-invalid="Boolean(form.errors.body)"
      />
    </label>

    <!--
      **Zwei Felder je Zeitpunkt, und das ist bezahlt** (`docs/102 §2`):
      Ein Textfeld für `Y-m-d H:i` mit `inputmode="numeric"` war auf dem
      iPhone nicht ausfüllbar — die Zifferntastatur gibt weder Bindestrich
      noch Doppelpunkt noch Leerzeichen her.
    -->
    <div class="field-row">
      <label class="field narrow">
        <span>Sichtbar ab</span>
        <input v-model="form.visible_from_date" type="date" :aria-invalid="Boolean(form.errors.visible_from_date)">
      </label>
      <label class="field narrow">
        <span>Uhrzeit</span>
        <input v-model="form.visible_from_time" type="time" :aria-invalid="Boolean(form.errors.visible_from_time)">
      </label>
    </div>

    <div class="field-row">
      <label class="field narrow">
        <span>Sichtbar bis</span>
        <input v-model="form.visible_until_date" type="date" :aria-invalid="Boolean(form.errors.visible_until_date)">
      </label>
      <label class="field narrow">
        <span>Uhrzeit</span>
        <input v-model="form.visible_until_time" type="time" :aria-invalid="Boolean(form.errors.visible_until_time)">
      </label>
    </div>

    <p class="hint">
      Beide Enden dürfen leer bleiben. Die Zeiten gelten in der Anzeigezeitzone ({{ zone }}).
    </p>

    <!--
      **`.choices` mit `.toggle` und keine eigene Klasse.** Der erste Wurf
      schrieb `.choice`, und die gibt es in `app.css` nicht — die Kästchen
      streckten sich dann über die ganze Breite, weil `.field` eine Flexspalte
      ist. Ein Baustein, den man erfindet, statt nachzusehen, ist derselbe
      Fehler wie ein Hexwert in einer Komponente.
    -->
    <div class="field">
      <span>Publikum</span>
      <div class="choices">
        <label v-for="p in audiences" :key="p.value" class="toggle">
          <input v-model="form.audiences" type="checkbox" :value="p.value">
          <span>{{ p.label }}</span>
        </label>
      </div>
    </div>

    <div class="button-row">
      <button type="submit" class="button primary" :disabled="form.processing">
        {{ announcement === null ? 'Ankündigen' : 'Änderung speichern' }}
      </button>
    </div>
  </form>
</template>
