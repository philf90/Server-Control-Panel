<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { counted } from '../../Composables/useCounted'
import { rang, zustand } from '../../Composables/useUnitState'

/**
 * Eine Zeile, wie `system.units.list` sie liefert.
 *
 * `pid`, `restarts` und `since` sind `null`, wenn die Unit das Feld gar nicht
 * kennt — ein Timer hat keine PID. Das ist etwas anderes als eine gemessene
 * Null, und die Anzeige darf beides nicht gleich aussehen lassen.
 */
type Unit = {
  unit: string
  kind: string
  role: string
  own: boolean
  controlled: boolean
  present: boolean
  description: string
  active_state: string
  sub_state: string
  unit_file_state: string
  pid: number | null
  restarts: number | null
  since: string | null
  triggers: string | null
  has_next: boolean | null

  /**
   * Ob ein Timer diesen Dienst startet — `null` bei allem, was kein Dienst ist.
   *
   * Vier der eigenen zwölf sind `Type=oneshot` und stehen zwischen ihren Läufen
   * auf `inactive`. Ohne dieses Feld sähe der gesunde Server aus wie ein
   * kaputter.
   */
  scheduled: boolean | null

  /**
   * Der nächste Termin, **fertig formatiert vom Server**.
   *
   * Nicht als Zahl: `toLocaleString` im Browser nimmt die Zone des Betrachters,
   * und die Anzeigezone dieses Panels steht in den Einstellungen (`docs/40`).
   * Wer hier rechnet, hat eine zweite Fassung dieser Entscheidung gebaut, und
   * die zweite ist die, die auseinanderläuft.
   */
  next_elapse: string | null
}

/** Ein lauschender TCP-Sockel, so wie `system.ports` ihn beschreibt (A3). */
interface Listener {
  address: string
  port: number
  family: string

  /** `any` · `loopback` · `specific` — drei Reichweiten und keine Wahrheitswerte. */
  scope: string

  /**
   * Der Eigentümer — oder `null`.
   *
   * `null` heisst hier **zweierlei**, und `privileged` unterscheidet die
   * beiden: „niemand sichtbar" (der Agent durfte nachsehen und fand keinen)
   * und „nicht nachgesehen". Ohne diese Unterscheidung wäre jeder Port eines
   * unprivilegierten Laufs eine Aussage über den Server.
   */
  process: string | null
  pid: number | null
}

interface Ports {
  readable: boolean
  reason?: string

  /** `null`, wenn der Betrachter die Prozessspalte gar nicht bekommt. */
  privileged?: boolean | null
  listeners?: Listener[]
  filter?: {
    readable: boolean
    nft: { installed: boolean; readable: boolean; configured: boolean; tables: string[] }
    legacy: { installed: boolean; readable: boolean; configured: boolean }
    legacy_only: boolean
    manager: string
  }
}

const props = defineProps<{
  services: Unit[]
  timers: Unit[]
  live: boolean
  error: string | null
  ports: Ports
}>()

/** Wie weit eine Bindung reicht — in Worten, die gemessen sind. */
function reichweite(l: Listener): string {
  if (l.scope === 'any') {
    return 'lauscht auf allen Adressen'
  }

  return l.scope === 'loopback' ? 'lauscht nur lokal' : `lauscht auf ${l.address}`
}

/**
 * Der Eigentümer als Satz.
 *
 * Drei Fälle und drei Sätze: ein Name, „nicht feststellbar" (niemand hat
 * nachgesehen) und „keiner sichtbar" (nachgesehen und keiner gefunden). Der
 * mittlere ist der, den ein `null` allein verschluckt.
 */
function eigentuemer(l: Listener): string {
  if (l.process) {
    return l.process
  }

  return props.ports.privileged === true ? 'keiner sichtbar' : 'nicht feststellbar'
}

/** Der erkannte Verwalter in Worten. Eine geschlossene Grundmenge, wie im Agenten. */
const VERWALTER: Record<string, string> = {
  nftables: 'nftables',
  iptables: 'iptables',
  ufw: 'ufw',
  firewalld: 'firewalld',
  none: 'keiner',
  unknown: 'nicht feststellbar',
}

function verwalter(name: string): string {
  return VERWALTER[name] ?? 'nicht feststellbar'
}

/*
 * **`rang` und `zustand` stehen seit dem 31. August 2026 in
 * `useUnitState`.** Sie standen hier, und die Übersicht hatte eine zweite,
 * ärmere Fassung — Befund 5 aus `docs/91 §13`. Eine Stufe, die eine zweite
 * Anzeige für dieselbe Sache baut, erzeugt die Abweichung, die sie danach
 * halten muss.
 */

/**
 * Der nächste Termin.
 *
 * `—` heisst „es gibt keinen", `unbekannt` heisst „es gibt einen, und das Datum
 * hat niemand geliefert". Der Unterschied ist der zwischen einem Schaden und
 * einer Lücke im Messmittel, und er darf nicht dieselbe Zelle füllen.
 */
function termin(zeile: Unit): string {
  if (zeile.has_next === false) return '—'

  return zeile.next_elapse ?? 'unbekannt'
}

const kaputt = computed(() => props.timers.filter((t) => t.present && t.has_next === false).length)
/**
 * Wie viele Dienste nicht tun, was sie sollen.
 *
 * **Gezählt wird über `rang` und nicht über `active_state`.** Eine zweite
 * Fassung derselben Regel ist die, die veraltet: Die erste Fassung dieser Zeile
 * fragte `active_state !== 'active'` und meldete damit auf einem gesunden
 * Server „4 Dienste laufen nicht", während dieselben vier Zeilen daneben
 * längst grün waren.
 */
const gestoppt = computed(() => props.services.filter((s) => rang(s) === 'critical').length)
</script>

<template>
  <Head title="Dienste" />

  <PanelLayout title="Dienste" subline="Was auf diesem Server läuft — und welcher Timer keinen Termin mehr hat">
    <p v-if="!live" class="notice critical">
      <!--
        **Der Schlusspunkt gehört der eingebetteten Meldung** (`docs/114 §9.2`).
        Alle acht Meldungen von {@see Client} sind ganze Sätze und enden mit
        einem Punkt; wer hier einen zweiten setzt, druckt „vorhanden.." — auf
        `cloudsrv24` am 8. September gemessen.

        > **Ein Satz, der einen fremden Satz einbettet und selbst schliesst,
        > schliesst ihn zweimal.**
      -->
      Der Agent antwortet nicht{{ error ? `: ${error}` : '.' }} Die Zustände unten fehlen
      deshalb — nicht, weil nichts läuft, sondern weil niemand geantwortet hat.
    </p>

    <p v-else-if="kaputt > 0" class="notice warn">
      {{ counted(kaputt, 'Timer hat', 'Timer haben') }} keinen nächsten Termin und meldet
      trotzdem „active".
    </p>

    <p v-else-if="gestoppt > 0" class="notice warn">
      {{ counted(gestoppt, 'Dienst läuft', 'Dienste laufen') }} nicht.
    </p>

    <!--
      **Keine grüne Meldung.** Die grüne Marke gehört dem Layout: Erfolg ist eine
      Aussage über einen Vorgang, und hier steht ein Zustand. Ein Satz ohne Farbe
      sagt dasselbe und nimmt der grünen Meldung ihre Bedeutung nicht weg.

      Der Klassenname steht hier bewusst nicht ausgeschrieben: `FieldErrorTest`
      sucht ihn als Zeichenkette, und ein Kommentar, der die Regel zitiert,
      verletzt sie für einen Wächter, der Wörter liest.
    -->
    <!--
      **„In Ordnung" und „läuft" sind seit dem 31. August zweierlei.** Hier stand
      „Alle Dienste laufen", und auf `cloudsrv24` stand der Satz drei Zeilen über
      vier Diensten, die gerade nicht liefen — weil ihr Timer sie startet und sie
      dazwischen warten.

      Eine Behebung ändert, was ein Wort bedeutet; die Sätze, die es benutzen,
      ändert sie nicht mit.
    -->
    <p v-else class="notice">Jeder Dienst ist in Ordnung, und jeder Timer hat einen Termin.</p>

    <div class="sections">
      <Section title="Dienste" full>
        <div class="scrolls">
          <!--
            **`stacks`, und das war eine Auslassung und keine Entscheidung.**
            Fünfundzwanzig Tabellen dieses Panels tragen es, diese und die der
            Timer trugen es als einzige nicht — und der Kommentar in `app.css`
            nennt „Dienste" ausdrücklich als `scrolls`-Fall, während die
            Übersicht ihre Dienstetabelle seit jeher stapelt.

            Gemessen auf `cloudsrv24` bei 390 px: Die Tabelle ist 1005 px breit
            bei 358 px sichtbar, und „kein nächster Termin" — der Satz, an dem
            das Abnahmekriterium von A2 hängt — ragte zehn Pixel über den Rand.
            Das Dokument schob dabei nicht; ein Rollbehälter hat keine
            Obergrenze, er hat nur keine Zahl, die sich beschwert.
          -->
          <table class="stacks">
            <thead>
              <tr>
                <th>Unit</th>
                <th>Zustand</th>
                <th>PID</th>
                <th>Neustarts</th>
                <th>Beschreibung</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="zeile in services" :key="zeile.unit">
                <td data-column="Unit"><span class="ident">{{ zeile.unit }}</span></td>
                <td data-column="Zustand"><span class="badge" :class="rang(zeile)">{{ zustand(zeile) }}</span></td>
                <td data-column="PID">{{ zeile.pid === null || zeile.pid === 0 ? '—' : zeile.pid }}</td>
                <td data-column="Neustarts">{{ zeile.restarts === null ? '—' : zeile.restarts }}</td>
                <td data-column="Beschreibung" class="quiet">{{ zeile.description || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section
        title="Timer"
        full
        note="Ein Timer ohne nächsten Termin ist abgeschaltet und meldet trotzdem „active“. Deshalb steht hier der Termin und nicht der Zustand von systemd."
      >
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Unit</th>
                <th>Zustand</th>
                <th>Nächster Termin</th>
                <th>Startet</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="zeile in timers" :key="zeile.unit">
                <td data-column="Unit"><span class="ident">{{ zeile.unit }}</span></td>
                <td data-column="Zustand"><span class="badge" :class="rang(zeile)">{{ zustand(zeile) }}</span></td>
                <td data-column="Nächster Termin">{{ termin(zeile) }}</td>
                <td data-column="Startet"><span class="ident">{{ zeile.triggers || '—' }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <!--
        **A3, erster Wurf.** Der Bereich liegt hier und nicht auf einer eigenen
        Seite: „Was läuft auf diesem Server" und „worauf horcht es" ist eine
        Frage, und sie hat schon eine Seite (`docs/109 §4`).
      -->
      <Section title="Ports und Regelwerk" full>
        <!--
          **Der Satz, den M20 verlangt, und er steht genau einmal.** Gemessen
          ist der Blick von innen Feld für Feld derselbe, ob eine Sperre
          davorsteht oder nicht — deshalb kommen die Wörter „offen",
          „erreichbar" und „geschlossen" in diesem Bereich nicht vor.
        -->
        <p class="hint">
          Ob diese Ports von aussen zu benutzen sind, weiss dieser Server nicht.
          Steht eine Firewall des Anbieters davor, sieht er sie nicht. Zu sehen
          ist hier, <strong>worauf dieser Rechner horcht</strong> und was in
          seinem eigenen Regelwerk steht.
        </p>

        <p v-if="!props.ports.readable" class="notice warn">
          Die lauschenden Ports sind nicht feststellbar — der Agent hat nicht
          geantwortet.
        </p>

        <div v-else class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Port</th>
                <th>Reichweite</th>
                <th>Eigentümer</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in props.ports.listeners" :key="`${l.family}-${l.address}-${l.port}`">
                <td data-column="Port"><span class="ident">{{ l.port }}</span></td>
                <td data-column="Reichweite">{{ reichweite(l) }}</td>
                <td data-column="Eigentümer"><span class="ident">{{ eigentuemer(l) }}</span></td>
              </tr>
              <tr v-if="!props.ports.listeners?.length">
                <td colspan="3">Kein Dienst horcht auf einem TCP-Port.</td>
              </tr>
            </tbody>
          </table>
        </div>

      </Section>

      <!--
        **Ein eigener Bereich, und das ist eine Berichtigung.** `docs/109 §3.3`
        sah ihn so vor; beim Bauen ist er in den Bereich darüber gerutscht. Im
        Bild bei 1440 px lief die Zeile „Verwaltet von" unmittelbar unter dem
        letzten Lauscher weiter, und die beiden Tabellen lasen sich als eine —
        derselbe Befund wie bei Spalten und Indizes in `docs/46 §20.11`.

        > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
        > Betrachter.**
      -->
      <Section title="Regelwerk" full>
        <table v-if="props.ports.filter" class="pairs">
          <tbody>
            <tr>
              <td>Verwaltet von</td>
              <td class="right ident">{{ verwalter(props.ports.filter.manager) }}</td>
            </tr>
            <tr>
              <td>nftables</td>
              <td class="right ident">
                {{ props.ports.filter.nft.readable
                  ? (props.ports.filter.nft.configured ? 'führt Regeln' : 'führt nichts')
                  : 'nicht feststellbar' }}
              </td>
            </tr>
            <tr>
              <td>iptables (alte Bauart)</td>
              <td class="right ident">
                {{ props.ports.filter.legacy.readable
                  ? (props.ports.filter.legacy.configured ? 'führt Regeln' : 'führt nichts')
                  : 'nicht feststellbar' }}
              </td>
            </tr>
          </tbody>
        </table>

        <!--
          **Wo nichts feststeht, steht der Satz — und kein leerer Bereich.**
          Bei angehaltenem Agenten gibt der Controller `readable: false` ohne
          den Schlüssel `filter`; ohne diesen Zweig stünde hier die Überschrift
          „Regelwerk" über nichts. Gemessen auf `cloudsrv24`
          (`docs/114 §6`): `unter Regelwerk: "Regelwerk"` und sonst nichts.

          > **Eine Anzeige, die zwei verschiedene Zustände gleich aussehen
          > lässt, behauptet etwas, das sie nicht weiss.**

          **Es ist ein `v-else` und keine zweite Bedingung.** Eine zweite wäre
          die, die veraltet — genau daran hing derselbe Befund auf
          `/schedules` einen Tag zuvor.
        -->
        <p v-else class="notice warn">
          Das Regelwerk ist nicht feststellbar — der Agent hat nicht geantwortet.
          Das heisst nicht, dass keine Regeln gelten.
        </p>

        <!--
          **Die Zeile, die es ohne M10 nicht gäbe.** Ein Regelwerk über
          `iptables-legacy` ist für `nft list ruleset` unsichtbar — dort steht
          `rc=0` und nichts, also die Antwort für „keine Regeln". Wer nur die
          verbreitete Frage kennt, hielte diesen Server für ungeschützt.
        -->
        <p v-if="props.ports.filter?.legacy_only" class="notice warn">
          <span>
            Die Regeln dieser Maschine liegen in der <strong>alten
            iptables-Bauart</strong>. <span class="ident">nft list ruleset</span>
            zeigt sie nicht an — wer dort nachsieht, bekommt eine leere Antwort.
          </span>
        </p>
      </Section>
    </div>
  </PanelLayout>
</template>
