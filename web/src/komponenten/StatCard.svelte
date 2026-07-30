<script lang="ts">
  // Die Telemetrie-Kachel. Sie ist der Keim des Gestaltungssystems und der
  // einzige Bestandteil der alten Oberfläche, der bleibt.
  //
  // Bewusst OHNE Diagramm-Bibliothek: Die Feinheiten dieser Kachel sind teuer
  // gelernt (siehe docs/06-roadmap.md zu 0.2.0) und stecken alle in wenigen
  // Zeilen — eine Bibliothek würde sie nachbauen und dabei schlechter machen.
  // Gerechnet wird ohnehin auf dem Server; hier wird nur gezeichnet.
  import type { Punkt, Verlauf } from "../lib/typen";
  import { t } from "../lib/texte";

  let {
    label,
    wert,
    einheit = "",
    unterzeile = "",
    verlauf = null,
  }: {
    label: string;
    wert: string;
    einheit?: string;
    unterzeile?: string;
    verlauf?: Verlauf | null;
  } = $props();

  let feld: SVGSVGElement | undefined = $state();
  let gezeigt: Punkt | null = $state(null);

  /** naechster sucht die Stützstelle unter dem Zeiger. Die Texte stehen fertig
   *  in den Daten — das Skript rechnet nicht, es sucht nur. */
  function naechster(ereignis: PointerEvent) {
    if (!verlauf?.has || !feld) return;
    const kasten = feld.getBoundingClientRect();
    if (kasten.width === 0) return;
    // Der viewBox ist 100 Einheiten breit und wird auf die Kachelbreite
    // gezogen; die Zeigerposition muss denselben Weg zurück.
    const x = ((ereignis.clientX - kasten.left) / kasten.width) * 100;
    let treffer: Punkt | null = null;
    let abstand = Infinity;
    for (const p of verlauf.points) {
      const d = Math.abs(p.x - x);
      if (d < abstand) {
        abstand = d;
        treffer = p;
      }
    }
    gezeigt = treffer;
  }
</script>

<div class="karte">
  <div class="lbl">{label}</div>
  <div class="wert zahl">
    {wert}{#if einheit}<small>{einheit}</small>{/if}
  </div>

  {#if verlauf?.has}
    <div class="verlaufbox">
      {#if gezeigt}
        <div class="ablesung zahl">{gezeigt.t} <span>·</span> {gezeigt.v}</div>
      {/if}
      <!-- preserveAspectRatio="none" zieht die 100 Einheiten auf die
           Kachelbreite. Ohne vector-effect="non-scaling-stroke" (in der Regel
           unten) würde die Strichstärke mitgezogen: steile Stücke über vier
           Pixel breit, flache bei 1,6. -->
      <svg
        bind:this={feld}
        class="verlauf"
        viewBox="0 0 100 34"
        preserveAspectRatio="none"
        role="img"
        aria-label="{label} — Verlauf der letzten 24 Stunden"
        onpointermove={naechster}
        onpointerleave={() => (gezeigt = null)}
      >
        <path class="linie" d={verlauf.path} />
        {#if gezeigt}
          <path class="fuehrung" d="M{gezeigt.x},2 L{gezeigt.x},32" />
        {/if}
        <!-- Der Endpunkt ist ein Segment der Länge null mit runder Kappe. Ein
             <circle> träfe dieselbe waagerechte Streckung und käme als
             liegende Ellipse heraus. -->
        <path class="punkt" d={verlauf.dot} />
        {#if gezeigt}
          <path class="punkt" d="M{gezeigt.x},{gezeigt.y} L{gezeigt.x},{gezeigt.y}" />
        {/if}
      </svg>
    </div>
  {:else}
    <p class="kein-verlauf">{t.uebersicht.keinVerlauf}</p>
  {/if}

  {#if unterzeile}<div class="unterzeile zahl">{unterzeile}</div>{/if}
</div>

<style>
  .karte {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 0.85rem 0.95rem 0.7rem;
    min-width: 0;
  }

  .lbl {
    font-size: 0.66rem;
    font-weight: 650;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--tx-mut);
  }

  .wert {
    font-size: 2.3rem;
    font-weight: 600;
    line-height: 1.15;
    letter-spacing: -0.02em;
    margin: 0.15rem 0 0.3rem;
  }

  .wert small {
    font-size: 1rem;
    font-weight: 500;
    color: var(--tx-mut);
    margin-left: 0.15rem;
  }

  .verlaufbox {
    position: relative;
  }

  .verlauf {
    display: block;
    width: 100%;
    height: 44px;
    touch-action: none;
  }

  .linie {
    fill: none;
    stroke: var(--accent);
    stroke-width: 1.6;
    stroke-linejoin: round;
    stroke-linecap: round;
    vector-effect: non-scaling-stroke;
  }

  .punkt {
    fill: none;
    stroke: var(--accent);
    stroke-width: 5;
    stroke-linecap: round;
    vector-effect: non-scaling-stroke;
  }

  .fuehrung {
    stroke: var(--tx-mut);
    stroke-width: 1;
    stroke-dasharray: 3 3;
    vector-effect: non-scaling-stroke;
  }

  .ablesung {
    position: absolute;
    top: -0.4rem;
    left: 50%;
    transform: translate(-50%, -100%);
    background: var(--surface3);
    border: 1px solid var(--line2);
    border-radius: 7px;
    font: 0.68rem var(--mono);
    padding: 0.25rem 0.55rem;
    white-space: nowrap;
    pointer-events: none;
  }

  .ablesung span {
    color: var(--tx-mut);
  }

  .kein-verlauf,
  .unterzeile {
    font: 0.72rem var(--mono);
    color: var(--tx-mut);
    margin-top: 0.35rem;
  }

  .kein-verlauf {
    min-height: 44px;
  }
</style>
