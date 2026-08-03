<script setup lang="ts">
/*
 * Das Gerüst beider Flächen. Der Unterschied zwischen Admin- und Kundenfläche
 * ist die Dichte, nicht die Gestaltung — umgeschaltet wird über ein Attribut
 * am Wurzelelement, das die Werte aus app.css umstellt (§7.2 des Plans).
 */
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

defineProps<{ title: string; subline?: string }>()

const page = usePage()
const source = computed(() => page.props.source as { repository: string; version: string; commit: string })
const account = computed(() => page.props.account as { name: string; is_admin: boolean } | null)
const impersonation = computed(() => page.props.impersonation as { active: boolean; admin: string } | null)

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
      { group: null, items: [{ name: 'Übersicht', href: '/' }] },
      { group: 'Konto', items: [
        { name: 'Vorgänge', href: '/operations' },
        { name: 'Protokoll', href: '/audit' },
        { name: 'Mein Konto', href: '/settings/profile' },
      ] },
    ]
  }

  return [
    { group: null, items: [{ name: 'Übersicht', href: '/' }] },
    { group: 'Verwaltung', items: [
      { name: 'Kunden', href: '/customers' },
      { name: 'Pläne', href: '/plans' },
    ] },
    { group: 'Server', items: [{ name: 'Vorgänge', href: '/operations' }, { name: 'Protokoll', href: '/audit' }] },
    { group: 'Konto', items: [{ name: 'Mein Konto', href: '/settings/profile' }] },
  ]
})

function signOut(): void {
  router.post('/logout')
}

function stopImpersonation(): void {
  router.post('/impersonation/stop')
}
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
      <button type="button" @click="stopImpersonation">Zurück zur Verwaltung</button>
    </div>

    <aside class="nav">
      <div class="badge">
        <!-- „C" stand hier bis August 2026 — von CloudSrv, dem verworfenen Namen. -->
        <span class="glyph">S</span>
        <!--
          Die Version steht unter dem Schriftzug und nicht daneben.
          Daneben war der Wunsch, und daneben passt sie nicht: Die Seitenleiste
          ist 186px breit, abzüglich Innenabstand bleiben 158px, und Zeichen,
          Schriftzug und Marke brauchen zusammen 177px — bei einer Vorabfassung
          wie „0.10.0-rc.12" sogar 190px. Gemessen, nicht geschätzt. Unter dem
          Schriftzug steht sie bündig mit ihm und bleibt dezent.

          Sie steht hier und trotzdem weiter in der Fusszeile. Die Fusszeile
          erfüllt Abschnitt 13 der AGPL: ein Link auf den Quelltext *dieser*
          Fassung. Diese Marke erfüllt etwas anderes — sie beantwortet die
          Frage „welche läuft hier eigentlich?" ohne Scrollen, und das ist die
          erste Frage bei jedem Fehlerbericht.
        -->
        <span class="schrift">
          <b>SrvPanel</b>
          <span class="version">{{ source.version }}</span>
        </span>
      </div>

      <nav>
        <template v-for="block in navigation" :key="block.group ?? 'oben'">
          <p v-if="block.group" class="group">{{ block.group }}</p>
          <Link
            v-for="item in block.items"
            :key="item.name"
            :href="item.href"
            :class="['item', { active: current === item.href }]"
          >
            {{ item.name }}
          </Link>
        </template>
      </nav>

      <!--
        Abschnitt 13 der AGPL: Wer die Software über das Netz benutzt, muss an
        den Quelltext der laufenden Fassung kommen. Deshalb Version und Commit
        im Link und nicht bloß die Adresse des Repositorys.
      -->
      <div v-if="account" class="account">
        <span class="name">{{ account.name }}</span>
        <button type="button" class="signout" @click="signOut">Abmelden</button>
      </div>

      <footer class="source">
        <a :href="source.commit ? `${source.repository}/tree/${source.commit}` : source.repository">
          Quelltext · {{ source.version }}
        </a>
      </footer>
    </aside>

    <main class="content">
      <header class="header">
        <h1>{{ title }}</h1>
        <span v-if="subline" class="meta">{{ subline }}</span>
      </header>

      <slot />
    </main>
  </div>
</template>

<style scoped>
.frame {
  display: grid;
  grid-template-columns: 186px 1fr;
  grid-template-rows: auto 1fr;
  min-height: 100vh;
}

.band {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 8px var(--padding);
  font-size: var(--text-table);
  color: var(--warn);
  background: var(--warn-surface);
  border-bottom: 1px solid var(--warn);
}

.band button {
  padding: 4px 10px;
  font: inherit;
  font-size: var(--text-small);
  color: var(--warn);
  background: transparent;
  border: 1px solid var(--warn);
  border-radius: 5px;
  cursor: pointer;
}

.account {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 0;
  font-size: var(--text-small);
  color: var(--text-muted);
  border-top: 1px solid var(--nav-border);
}

.account .signout {
  padding: 0;
  font: inherit;
  font-size: var(--text-small);
  color: var(--text-faint);
  background: none;
  border: 0;
  cursor: pointer;
}

.account .signout:hover { color: var(--text); }

.nav {
  background: var(--nav-bg);
  border-right: 1px solid var(--nav-border);
  padding: 16px 12px;
  display: flex;
  flex-direction: column;
}

.badge {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 20px;
}

.badge b {
  font-size: var(--text-body);
  letter-spacing: -0.01em;
  color: var(--text-strong);
}

/*
 * Die Version: dezent, weil sie eine Auskunft ist und keine Meldung.
 *
 * Kein Akzent — Amber bedeutet in diesem System Signal, Zustand oder primäre
 * Aktion (§7.2), und eine Versionsnummer ist nichts davon. Sie sitzt in
 * Monospace, damit die Ziffern beim Vergleich zweier Server untereinander
 * stehen, und sie schrumpft nicht mit: `flex: none` verhindert, dass eine
 * lange Vorabfassung wie „0.2.0-rc.10" den Schriftzug daneben quetscht.
 */
.schrift {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
  min-width: 0;
}

.version {
  padding: 1px 5px;
  font-family: var(--font-mono);
  font-size: var(--text-label);
  color: var(--text-faint);
  background: var(--surface);
  border: 1px solid var(--surface-border);
  border-radius: 3px;
}

.glyph {
  /* `flex: none`, seit die Version daneben steht: Ohne das schrumpft das
     Quadrat zu einem Streifen, sobald die Zeile eng wird. Gesehen beim
     Rendern, nicht beim Lesen. */
  flex: none;
  width: 22px;
  height: 22px;
  border-radius: 3px;
  display: grid;
  place-items: center;
  background: var(--accent);
  color: var(--accent-on);
  font-size: var(--text-small);
  font-weight: 700;
}

nav {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.group {
  font-size: var(--text-label);
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--text-faint);
  margin: 16px 0 6px 9px;
}

.item {
  padding: 6px 9px;
  border-radius: 3px;
  text-decoration: none;
  color: var(--text-muted);
  font-size: var(--text-table);
}

.item:hover {
  color: var(--text);
}

.item.active {
  background: var(--surface);
  color: var(--accent);
  box-shadow: inset 2px 0 0 var(--accent);
  border-radius: 0 3px 3px 0;
}

.source {
  margin-top: auto;
  padding-top: 20px;
  font-size: var(--text-small);
}

.source a {
  color: var(--text-faint);
  text-decoration: none;
}

.source a:hover {
  color: var(--text-muted);
  text-decoration: underline;
}

.content {
  padding: 18px 22px 28px;
  min-width: 0;
}

.header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}

h1 {
  margin: 0;
  font-size: var(--text-heading);
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--text-strong);
}

.meta {
  font-family: var(--font-mono);
  font-size: var(--text-small);
  color: var(--text-muted);
}
</style>
