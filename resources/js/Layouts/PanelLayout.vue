<script setup lang="ts">
/*
 * Das Gerüst beider Flächen — Rail, Seitenkopf, Schublade.
 *
 * Der Unterschied zwischen Admin- und Kundenfläche ist die Dichte, nicht die
 * Gestaltung; umgeschaltet wird über ein Attribut am Wurzelelement, das die
 * Werte aus app.css umstellt.
 *
 * **Was „Kontor" hier geändert hat.** Das Rail ist kein dunkler Block mehr
 * neben dem Inhalt, sondern eine ruhige Fläche in der Farbe der Seite — und es
 * ist von 186px auf 236px gewachsen. Der Grund steht in der alten Fassung als
 * Kommentar: Zeichen, Schriftzug und Version brauchten zusammen 177 bis 190px,
 * und da waren 158px.
 *
 * **Der Unterschied ist nicht, dass jetzt alles in eine Zeile passt.** Bei
 * „0.3.0-rc.5" tut es das nicht, gemessen im Browser: 209px gegen 203px
 * verfügbare Breite. Der Unterschied ist, dass der Umbruch nicht mehr
 * festgeschrieben ist. Die alte Fassung hat die Version *immer* unter den
 * Schriftzug gesetzt, weil die längste denkbare nicht danebenpasste — also den
 * Normalfall nach dem Ausnahmefall gestaltet. Hier steht sie daneben, solange
 * sie danebenpasst, und rutscht sonst in die zweite Zeile.
 */
import { Link, router, usePage } from '@inertiajs/vue3'
import MarkIcon from '../Components/MarkIcon.vue'
import NavIcon from '../Components/NavIcon.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { announcement } from '../Composables/useAnnounce'

defineProps<{ title: string; subline?: string }>()

const page = usePage()
const source = computed(() => page.props.source as { url: string; version: string })
const account = computed(
  () => page.props.account as { name: string; is_admin: boolean; has_active_subscription: boolean } | null,
)
const impersonation = computed(() => page.props.impersonation as { active: boolean; admin: string } | null)

/*
 * Die Erfolgsmeldung steht hier und nicht auf jeder Seite.
 *
 * Bis August 2026 brachte sie jede Seite selbst mit — drei Seiten taten es,
 * der Rest nicht. Wer einen Kunden sperrte, bekam als einzige Rückmeldung
 * einen Knopf, der jetzt anders beschriftet war; die Meldung „Ein Abonnement
 * wird gesperrt — der Vorgang läuft" schickte der Controller, und die Seite
 * warf sie weg. Dasselbe Muster wie bei den Knöpfen: eine Sache, die jede
 * Seite einzeln richtig machen musste, und die meisten machten sie gar nicht.
 *
 * `role="status"` und nicht `alert`: Es ist eine Bestätigung und keine
 * Warnung — ein Screenreader liest sie vor, ohne die Arbeit zu unterbrechen.
 */
const gemeldet = announcement()

/*
 * **Zwei Quellen, ein Ort.** `flash.success` kommt aus einer Inertia-Antwort;
 * `announcement()` aus einer Seite, die über XHR ändert und dabei stehen bleibt
 * — die Konsole aus P5c ist die erste davon, und ein `flash` erreicht sie nicht.
 *
 * Gerendert wird weiter nur hier. Die Alternative wäre eine eigene grüne Meldung
 * auf der Konsolenseite gewesen, und `FieldErrorTest` hat genau die abgewiesen:
 * Zwei Orte für dieselbe Auskunft heissen, dass einer veraltet.
 *
 * Die Seite hat Vorrang vor dem `flash`: Wer gerade etwas getan hat, soll das
 * lesen und nicht, was beim Aufbau der Seite galt.
 */
const erfolg = computed(
  () => gemeldet.value ?? (page.props.flash as Record<string, string> | undefined)?.success,
)

/*
 * Die Navigation kommt aus dem Kontotyp, nicht aus einer Rechteprüfung im
 * Menü. Das ist ausdrücklich keine Autorisierung — die sitzt an der Aktion
 * (§6.2.2). Ein Kunde, der eine Adminadresse von Hand einträgt, wird von der
 * Policy abgewiesen; hier geht es nur darum, ihm keinen Weg zu zeigen, der
 * ohnehin nicht seiner ist.
 */
const current = computed(() => page.url.split('?')[0])

const navigation = computed(() => {
  if (account.value?.is_admin === false) {
    return [
      { group: null, items: [{ name: 'Übersicht', href: '/', icon: 'overview' }] },
      { group: 'Konto', items: [
        { name: 'Abonnements', href: '/subscriptions', icon: 'subscriptions' },

        /*
         * **Domains stehen erst da, wenn es etwas zu benennen gibt.**
         *
         * Der Betreiber hat die Liste seit P3; ein Kunde kam an seine Domains
         * nur über Abonnements → Name des Abonnements → ein kleiner Knopf
         * rechts im Bereich „Domains". Drei Klicks für die Sache, wegen der er
         * das Panel überhaupt öffnet.
         *
         * Die Bedingung ist ein **aktives** Abonnement und nicht bloss eines:
         * Ohne ein benutzbares gibt es keinen Ort, an dem eine Domain
         * entstehen könnte — der Menüpunkt führte auf eine leere Liste ohne
         * Knopf, und das ist eine Sackgasse mit Einladung.
         */
        ...(account.value?.has_active_subscription
          ? [
              { name: 'Domains', href: '/domains', icon: 'domains' },

              /*
               * **Datenbanken an derselben Bedingung wie Domains** (P5): Ohne
               * ein benutzbares Abonnement gibt es keinen Ort, an dem eine
               * Datenbank entstehen könnte, und der Menüpunkt führte auf eine
               * leere Liste ohne Knopf — eine Sackgasse mit Einladung.
               *
               * Ob der Plan überhaupt Datenbanken vorsieht, steht hier
               * bewusst **nicht**: Das Kontingent kann `0` sein, und dann ist
               * die Liste leer und der Knopf weist ab. Ein Menüpunkt, der je
               * nach Plan verschwindet, wäre eine zweite Fassung der Regel aus
               * `Databases::guardQuota()` — und die zweite ist die, die
               * veraltet.
               */
              { name: 'Datenbanken', href: '/databases', icon: 'databases' },
            ]
          : []),

        { name: 'Vorgänge', href: '/operations', icon: 'operations' },
        { name: 'Protokoll', href: '/audit', icon: 'log' },
        { name: 'Mein Konto', href: '/settings/profile', icon: 'account' },
      ] },
    ]
  }

  return [
    { group: null, items: [{ name: 'Übersicht', href: '/', icon: 'overview' }] },
    { group: 'Verwaltung', items: [
      { name: 'Kunden', href: '/customers', icon: 'customers' },
      { name: 'Pläne', href: '/plans', icon: 'plans' },
      { name: 'Abonnements', href: '/subscriptions', icon: 'subscriptions' },

      // Serverweit und deshalb hier: „Welche Domain liegt in welchem
      // Abonnement" ist eine Frage des Betreibers. Ein Kunde findet seine
      // Domains an seinem Abonnement — für ihn wäre eine zweite Liste
      // derselben drei Zeilen nur ein zweiter Weg zum selben Ort.
      { name: 'Domains', href: '/domains', icon: 'domains' },

      // Serverweit und aus demselben Grund wie die Domains: „Welche Datenbank
      // liegt in welchem Abonnement" ist eine Frage des Betreibers.
      { name: 'Datenbanken', href: '/databases', icon: 'databases' },
    ] },
    { group: 'Server', items: [
      { name: 'Vorgänge', href: '/operations', icon: 'operations' },
      { name: 'Protokoll', href: '/audit', icon: 'log' },
      { name: 'Allgemein', href: '/settings/general', icon: 'general' },
      { name: 'PHP-Versionen', href: '/settings/php', icon: 'php' },

      /*
       * **Dasselbe Zeichen wie „Datenbanken", und mit Absicht.** Die beiden
       * Einträge stehen in verschiedenen Gruppen — „Verwaltung" führt zu den
       * Datenbanken der Kunden, „Server" zu dem Dienst, auf dem sie liegen.
       * Ein zweites, ähnliches Zeichen zu zeichnen hiesse, einen Unterschied
       * anzudeuten, den die Gruppe schon macht.
       */
      { name: 'Datenbankserver', href: '/settings/database', icon: 'databases' },
      { name: 'Mailversand', href: '/settings/mail', icon: 'mail' },
      { name: 'Zertifikat', href: '/settings/tls', icon: 'tls' },
      { name: 'DNS-Zugang', href: '/settings/dns', icon: 'dns' },
    ] },
    { group: 'Konto', items: [{ name: 'Mein Konto', href: '/settings/profile', icon: 'account' }] },
  ]
})

function signOut(): void {
  router.post('/logout')
}

function stopImpersonation(): void {
  router.post('/impersonation/stop')
}

/*
 * Die Navigation auf einer schmalen Fläche.
 *
 * Unter 720px liegt das Rail nicht mehr daneben, sondern als Schublade
 * darüber. Es war zuvor eine feste Spalte — auf einem Telefon mit 390px ist
 * das die halbe Breite für ein Menü, das man einmal benutzt und dann nicht
 * mehr ansieht.
 *
 * Drei Dinge, die eine Schublade haben muss, damit sie sich wie eine anfühlt:
 * Sie schliesst beim Seitenwechsel (sonst steht sie über der Seite, die man
 * gerade geöffnet hat), sie schliesst mit Escape, und solange sie offen ist,
 * rollt die Seite darunter nicht mit.
 */
const menuOpen = ref(false)

watch(() => page.url, () => {
  menuOpen.value = false
})

watch(menuOpen, (open) => {
  document.documentElement.classList.toggle('menu-open', open)
})

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') menuOpen.value = false
}

onMounted(() => document.addEventListener('keydown', onKey))

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)

  // Ohne das bliebe die Seite gesperrt, wenn die Schublade offen war und die
  // Anwendung das Gerüst wechselt — etwa beim Abmelden.
  document.documentElement.classList.remove('menu-open')
})
</script>

<template>
  <div class="frame">
    <!--
      Das Band aus §6.3. Es steht über allem, ist nicht wegklickbar und trägt
      den Rückweg bei sich — ein Wechsel, aus dem man suchen muss, ist einer,
      den jemand vergisst.
    -->
    <div v-if="impersonation?.active" class="band">
      <span>
        Sie arbeiten in der Sicht dieses Kunden.
        Angemeldet als <b>{{ account?.name }}</b>, gewechselt von <b>{{ impersonation.admin }}</b>.
      </span>
      <button type="button" class="button small" @click="stopImpersonation">Zurück zur Verwaltung</button>
    </div>

    <!--
      Die Kopfzeile der schmalen Fläche.

      **Ohne das Zeichen, und das ist kein Versehen.** Es sind drei gestackse
      Balken — dasselbe Bild wie der Menüknopf daneben. In der ersten Aufnahme
      des Entwurfs stand hier „≡ ≡ SrvPanel", und man sieht zwei Menüknöpfe und
      drückt auf den falschen. Bei „Leitstand" fiel das nie auf, weil das
      Zeichen in der Seitenleiste sass und der Menüknopf in der Kopfzeile — sie
      standen nie zusammen. Im Reiter des Browsers bleibt das Zeichen, und
      darum geht es dort.
    -->
    <header class="topbar">
      <button
        type="button"
        class="nav-toggle"
        :aria-expanded="menuOpen"
        aria-controls="hauptnavigation"
        aria-label="Navigation"
        @click="menuOpen = !menuOpen"
      >
        <!-- Drei Striche als SVG und nicht als „☰": Das Zeichen ist ein Emoji
             mit eigener Zeichnung je Betriebssystem (docs/19 §3a). -->
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M4 7h16M4 12h16M4 17h16" />
        </svg>
      </button>

      <span class="title">{{ title }}</span>
    </header>

    <!-- Der Schleier liegt zwischen Seite und Schublade und schliesst sie. -->
    <div v-if="menuOpen" class="scrim" @click="menuOpen = false" />

    <aside id="hauptnavigation" class="rail" :class="{ open: menuOpen }">
      <div class="row">
        <!--
          Hier stand ein Buchstabe in einem farbigen Quadrat: erst „C" von
          CloudSrv, dem verworfenen Namen, dann „S". Ein Platzhalter, solange
          es kein Zeichen gab — und es gab keines, bis August 2026 auch keinen
          Favicon: `public/favicon.ico` lag mit null Byte da.

          Jetzt steht dort dasselbe Zeichen wie im Reiter des Browsers. Das ist
          der Punkt: Wer mehrere Panels offen hat, erkennt sie am Reiter, und
          erkennt sie nur dann, wenn Reiter und Rail dasselbe zeigen.
        -->
        <MarkIcon :size="24" />
        <b>SrvPanel</b>

        <!--
          Die Version steht neben dem Schriftzug — und das ging vorher nicht.
          Bei 186px Rail blieben 158px, und Zeichen, Schriftzug und Marke
          brauchten zusammen 177px, bei einer Vorabfassung wie „0.10.0-rc.12"
          sogar 190px; gemessen, nicht geschätzt. Die Antwort damals war, sie
          umzubrechen. Bei 236px passt sie daneben.

          Sie steht hier und trotzdem weiter in der Fusszeile. Die Fusszeile
          erfüllt Abschnitt 13 der AGPL: ein Link auf den Quelltext *dieser*
          Fassung. Diese Marke erfüllt etwas anderes — sie beantwortet die
          Frage „welche läuft hier eigentlich?" ohne Scrollen, und das ist die
          erste Frage bei jedem Fehlerbericht.
        -->
        <span class="version">{{ source.version }}</span>
      </div>

      <nav class="nav-list">
        <template v-for="block in navigation" :key="block.group ?? 'oben'">
          <p v-if="block.group" class="nav-group">{{ block.group }}</p>
          <Link
            v-for="item in block.items"
            :key="item.name"
            :href="item.href"
            class="nav-item"
            :class="{ active: current === item.href }"
            :aria-current="current === item.href ? 'page' : undefined"
          >
            <NavIcon :name="item.icon" />
            {{ item.name }}
          </Link>
        </template>
      </nav>

      <!--
        Abschnitt 13 der AGPL: Wer die Software über das Netz benutzt, muss an
        den Quelltext der laufenden Version kommen — nicht bloß an die Adresse
        des Repositorys.

        Wohin genau, entscheidet der Server (App\Support\Panel\Source). Hier
        stand die Wahl als Bedingung über den Commit, und die war zweierlei
        falsch: Sie war eine zweite Fassung derselben Regel an der Stelle, an
        der man sie am wenigsten sucht — und der Commit wird nirgends gesetzt,
        also fiel sie seit jeher auf die Adresse des Repositorys zurück.
      -->
      <div class="rail-foot">
        <div v-if="account" class="account">
          <b>{{ account.name }}</b>
          <button type="button" class="signout" @click="signOut">Abmelden</button>
        </div>

        <a class="source" :href="source.url">Quelltext · {{ source.version }}</a>
      </div>
    </aside>

    <main class="content">
      <div class="page-head">
        <div class="title-block">
          <p v-if="$slots.breadcrumb" class="breadcrumb"><slot name="breadcrumb" /></p>
          <h1>{{ title }}</h1>
          <!--
            **Die Beizeile nimmt auch Bausteine und nicht nur Text.**
            `subline` als Zeichenkette trägt jede Seite dieses Panels; die
            Konsole aus P5c braucht daneben die Marke ihres Datenbanksystems
            (`docs/48 §6`), und eine zweite Auskunft in einer Zeichenkette wäre
            ein `MariaDB` ohne Marke — dieselbe Angabe in zwei Formen, je
            nachdem, welche Fläche man gerade ansieht.

            **Das Prop bleibt und wird nicht ersetzt.** Sechzehn Seiten geben
            hier Text, und ein Slot für alle wäre sechzehnmal dieselbe
            Zeremonie für eine Zeile. Der Slot gewinnt nur, wo es ihn gibt.
          -->
          <p v-if="$slots.subline || subline" class="subline">
            <slot name="subline">{{ subline }}</slot>
          </p>
        </div>

        <!--
          Rechts am Seitenkopf steht die Hauptaktion der Seite. Vorher stand
          sie am Ende des ersten Bereichs, also dort, wo man sie erst findet,
          nachdem man an ihr vorbeigelesen hat.
        -->
        <div v-if="$slots.actions" class="button-row">
          <slot name="actions" />
        </div>
      </div>

      <p v-if="erfolg" class="notice ok" role="status">{{ erfolg }}</p>

      <slot />
    </main>
  </div>
</template>

<style scoped>
.frame {
  display: grid;
  grid-template-columns: 236px 1fr;
  grid-template-rows: auto 1fr;

  /*
   * `dvh` und nicht `vh`: Auf einem Telefon zählt `vh` die Adressleiste mit,
   * die beim Rollen verschwindet. Eine Seite mit `100vh` steht deshalb im
   * Ausgangszustand um die Höhe dieser Leiste zu hoch — man rollt, obwohl
   * nichts zu rollen wäre.
   */
  min-height: 100dvh;
}

/* Kopfzeile und Schleier gibt es nur auf der schmalen Fläche. */
.topbar,
.scrim {
  display: none;
}

/*
 * **Band, Rail und Inhalt bekommen ihre Zeile ausdrücklich.**
 *
 * Ohne das verteilt das Raster sie der Reihe nach — und ohne Band landen Rail
 * und Inhalt zusammen in Zeile eins, die `auto` ist. Das Rail wird dann nur so
 * hoch wie seine Einträge, und seine Kante endet auf halber Bildschirmhöhe;
 * die `1fr`-Zeile darunter bleibt leer. Sobald jemand in die Sicht eines
 * Kunden wechselt, rutscht alles um eine Zeile weiter und es sieht richtig
 * aus — der Fehler hängt also davon ab, wer gerade zusieht.
 *
 * Dasselbe Muster wie auf der schmalen Fläche, wo das Zählen von Kindern
 * schon einmal 591px hohe Kopfzeilen erzeugt hat. Dort war die Antwort, das
 * Raster aufzugeben; hier reicht es, nicht zählen zu lassen.
 */
.band {
  grid-column: 1 / -1;
  grid-row: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 10px 24px;
  font-size: var(--text-table);
  color: var(--warn);
  background: var(--warn-surface);
  border-bottom: 1px solid var(--warn);
}

/* ── Das Rail ─────────────────────────────────────────────────────────── */
.rail {
  grid-row: 2;
  display: flex;
  flex-direction: column;
  padding: 22px 16px;
  background: var(--nav-bg);
  border-right: 1px solid var(--nav-border);
}

/*
 * `flex-wrap`, und der Grund ist eine Messung mit dem ungünstigen Fall.
 *
 * Gemessen im Browser, bei 203px verfügbarer Breite im Rail:
 *
 *   0.1.0-dev       201px   eine Zeile
 *   1.0.0           unter 203px   eine Zeile
 *   0.3.0-rc.5      209px   zwei Zeilen
 *   0.10.0-rc.12    226px   zwei Zeilen
 *
 * In keinem Fall ein Überlauf. Ohne `flex-wrap` müsste hier eine Entscheidung
 * für einen der beiden Fälle stehen, und die alte Seitenleiste hat sie für den
 * schlechteren getroffen.
 */
.row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px 9px;
  margin-bottom: 24px;
}

/*
 * Der Schriftzug in `--text-section` und nicht in `--text-heading`.
 *
 * **Gemessen, nicht geschätzt — und beim ersten Anlauf falsch.** Mit
 * `--text-heading` (26px) war der Schriftzug 126px breit; zusammen mit dem
 * Zeichen (24px), der Versionsmarke (76px) und zwei Lücken sind das 244px, und
 * im Rail stehen nach Innenabstand 203px zur Verfügung. Die Zeile brach um —
 * also genau der Fehler, wegen dem das Rail von 186px auf 236px gewachsen ist.
 *
 * Bei 17px sind es 200px und die Zeile hält. Eine eigene Stufe für den
 * Schriftzug wäre die achte Rolle für einen Sonderfall gewesen, und genau so
 * ist die alte Skala mit ihren zehn rem-Werten entstanden.
 */
.row b {
  font-size: var(--text-section);
  font-weight: 660;
  letter-spacing: -0.015em;
  color: var(--text-strong);
}

.row :deep(.version) {
  flex: none;
}

.nav-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

/*
 * Die Trennüberschrift — eine Linie und ein Wort, nicht nur ein Wort.
 *
 * **Der Befund kam vom Betreiber:** „Verwaltung", „Server" und „Konto" hoben
 * sich zu wenig von den Einträgen ab. Sie waren kleiner und blasser, sonst
 * nichts — und in einer Spalte aus lauter kurzen Wörtern reicht ein
 * Größenunterschied nicht, um „Überschrift" von „Menüpunkt" zu trennen.
 *
 * Drei Änderungen, und zwei davon sind keine Farbe:
 *
 *   1. Eine Haarlinie darüber. Kontor trennt mit Linien und nicht mit Kästen;
 *      das ist der Baustein, den die Seite ohnehin überall benutzt.
 *   2. Die Einträge haben jetzt ein Zeichen, die Überschriften nicht. Der
 *      Unterschied trägt schon im Umriss, bevor man liest.
 *   3. `--text-faint` statt `--text-muted`: Eine Überschrift, die *lauter*
 *      wird, zieht den Blick von dem weg, worum es geht. Sie soll gliedern,
 *      nicht rufen — die Linie leistet das Abheben, die Farbe nimmt sich
 *      zurück. Gerechnet gegen `--nav-bg`: 4,63:1 im hellen und 5,31:1 im
 *      dunklen Theme. Das ist über den 4,5:1 aus WCAG 1.4.3 und im hellen
 *      Theme knapp — eine Stufe blasser gäbe es hier nicht mehr, und deshalb
 *      trägt die Linie den Unterschied und nicht die Farbe.
 */
.nav-group {
  margin: 20px 0 6px;
  padding: 14px 12px 0;
  border-top: 1px solid var(--nav-border);
  font-size: var(--text-label);
  font-weight: 700;
  letter-spacing: 0.13em;
  text-transform: uppercase;
  color: var(--text-faint);
}

/* Die erste Gruppe braucht ihre Linie nicht: Darüber steht der Eintrag
   „Übersicht" und davor schon die Marke. Zwei Linien in Folge sind keine
   Gliederung, sondern ein Rahmen. */
.nav-group:first-of-type {
  margin-top: 14px;
}

.nav-item {
  padding: 9px 12px;
  min-height: var(--tap);
  display: flex;
  align-items: center;

  /* Der Abstand zwischen Zeichen und Wort. Ohne ihn klebt beides aneinander;
     mit mehr zerfällt der Eintrag in zwei Dinge. */
  gap: 10px;
  font-size: var(--text-table);
  color: var(--text);
  text-decoration: none;
  border-radius: var(--radius);
}

.nav-item:hover {
  background: var(--accent-surface);
}

/*
 * Der aktive Eintrag ist eine gefüllte Pille und kein Balken am Rand.
 *
 * „Leitstand" markierte ihn mit `box-shadow: inset 2px 0 0` — einem Strich,
 * der links an der Kante klebte. In einem Rail, das die Farbe der Seite hat,
 * ist eine Fläche die klarere Auskunft: Sie sagt „hier bist du", und der
 * Strich sagte „hier ist eine Kante".
 */
.nav-item.active {
  color: var(--accent);
  background: var(--accent-surface);
  font-weight: 660;
}

.rail-foot {
  margin-top: auto;
  padding-top: 22px;
  font-size: var(--text-small);
  color: var(--text-muted);
}

.account {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 4px;
}

.account b {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 580;
  color: var(--text);
}

/*
 * Abmelden ist kein `.button` und soll keiner sein: Ein Knopf auf einer Seite
 * ist eine Aktion, für die man dorthin gegangen ist. Das Abmelden steht im
 * Rail, weil es von überall erreichbar sein muss — nicht, weil es die
 * Hauptsache wäre.
 */
.signout {
  flex: none;
  padding: 0;
  font: inherit;
  font-size: var(--text-small);
  color: var(--text-muted);
  background: none;
  border: 0;
  cursor: pointer;
}

.signout:hover {
  color: var(--accent);
  text-decoration: underline;
}

.source {
  color: var(--text-muted);
  text-decoration: none;
}

.source:hover {
  color: var(--accent);
  text-decoration: underline;
}

/* ── Der Inhalt ───────────────────────────────────────────────────────── */
.content {
  grid-row: 2;
  padding: 26px 32px 36px;
  min-width: 0;
}

.title-block {
  min-width: 0;
}

/*
 * Die schmale Fläche (docs/24).
 *
 * Aus zwei Spalten wird eine, aus dem Rail eine Schublade. Das Rail bleibt
 * dabei dieselbe Komponente mit denselben Einträgen — eine zweite Navigation
 * nur fürs Telefon wäre eine zweite Stelle, an der jemand einen neuen
 * Menüpunkt vergisst.
 */
@media (max-width: 720px) {
  /*
   * **Hier stand `grid-template-columns: 1fr`, und das Gerüst blieb ein
   * Raster mit zwei Zeilen.** Das ging genau so lange gut, wie es zwei Kinder
   * im Fluss gab: Kopfzeile in die `auto`-Zeile, Inhalt in die `1fr`-Zeile.
   *
   * Beim Wechsel in die Sicht eines Kunden kommt das Band dazu, und damit
   * sind es drei. Sie verteilen sich der Reihe nach: Band in Zeile eins,
   * **Kopfzeile in die `1fr`-Zeile** — und die nimmt sich allen übrigen Platz.
   * Auf einem Telefon mit 844px Höhe war die Kopfzeile damit 591px hoch, und
   * der Inhalt begann darunter in einer Zeile, die es im Raster gar nicht
   * gibt. Zu sehen war eine leere Fläche zwischen Band und „Übersicht".
   *
   * Die Antwort ist nicht eine dritte Zeile — dann zählt man Kinder, und beim
   * nächsten Band zählt jemand falsch. Auf der schmalen Fläche gibt es nur
   * eine Spalte, und die Schublade steht ohnehin `fixed`: Was hier gebraucht
   * wird, ist eine Spalte von oben nach unten. Das ist ein Flexcontainer, und
   * der hat keine Zeilen, die man verzählen könnte.
   */
  .frame {
    display: flex;
    flex-direction: column;
  }

  .topbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    padding-top: calc(10px + env(safe-area-inset-top));
    background: var(--nav-bg);
    border-bottom: 1px solid var(--nav-border);
    position: sticky;
    top: 0;
    z-index: 20;
  }

  .topbar .title {
    font-size: var(--text-section);
    font-weight: 660;
    color: var(--text-strong);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .nav-toggle {
    display: grid;
    place-items: center;
    flex: none;
    width: var(--tap);
    height: var(--tap);
    padding: 0;
    color: var(--text-muted);
    background: transparent;
    border: 0;
    border-radius: var(--radius);
    cursor: pointer;
  }

  .nav-toggle svg {
    width: 24px;
    height: 24px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.7;
    stroke-linecap: round;
  }

  .scrim {
    display: block;
    position: fixed;
    inset: 0;
    z-index: 30;
    background: var(--scrim);
  }

  .rail {
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    z-index: 40;
    width: min(272px, 82vw);
    padding-top: calc(22px + env(safe-area-inset-top));
    padding-bottom: calc(22px + env(safe-area-inset-bottom));
    overflow-y: auto;
    transform: translateX(-100%);
    transition: transform 180ms ease;
  }

  .rail.open {
    transform: none;
  }

  .band {
    flex-wrap: wrap;
    padding-top: calc(10px + env(safe-area-inset-top));
  }

  .content {
    /* Nimmt, was übrig ist — vorher tat das die `1fr`-Zeile des Rasters. */
    flex: 1;
    min-width: 0;
    padding: 18px 16px 28px;
    padding-bottom: calc(28px + env(safe-area-inset-bottom));
  }

  /*
   * Die Seitenüberschrift steht auf der schmalen Fläche schon in der
   * Kopfzeile. Ein zweites Mal darunter wäre dieselbe Angabe zweimal auf
   * einem Bildschirm, der ohnehin knapp ist — Pfad und Beizeile bleiben, sie
   * sagen etwas anderes.
   */
  .page-head h1 {
    display: none;
  }
}

/*
 * Solange die Schublade offen ist, rollt die Seite darunter nicht mit.
 * `:global`, weil die Klasse am Wurzelelement hängt und nicht in dieser
 * Komponente — ohne das schriebe Vue die Regel auf ein Element um, das es
 * hier gar nicht gibt.
 */
:global(html.menu-open) {
  overflow: hidden;
}
</style>
