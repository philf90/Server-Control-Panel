<script setup lang="ts">
/*
 * Eine Liste von Kennungen — Adressen, Namen — als Text.
 *
 * **Warum es diese Komponente gibt.** Zwei Befunde der Bilderrunde
 * (`docs/76`), und beide betreffen dieselbe Frage: wie eine Liste von
 * Kennungen dasteht.
 *
 * **Befund 1 — der Umbruch fällt an der falschen Stelle.** `.ident` trägt
 * `overflow-wrap: anywhere`, weil eine IPv6 die Seite sonst bei 390 px aus dem
 * Bild schiebt (`docs/67`). Die Regel ist richtig und kennt keine bevorzugte
 * Trennstelle: Gemessen brach `2a0a:4cc0:c1:ebd1:b82d:51ff:fe72:3083` nach
 * `51ff:f`, also **mitten im Hextet**. Wer das liest, sieht `f` und `e72` als
 * zwei gültige Gruppen und zählt acht, wo sieben stehen.
 *
 * > **Ein Umbruch ohne bevorzugte Stelle bricht dort, wo es passt, und nicht
 * > dort, wo man liest.**
 *
 * **Befund 2 — zwei Namen, getrennt durch ein Leerzeichen.** Unter dem
 * Kästchen „Als Platzhalter bestellen" stand `*.cloudlab24.de cloudlab24.de`.
 * Beide Namen enthalten Punkte; es gibt für den Leser kein Zeichen, an dem der
 * eine aufhört. Dieselbe Datei schrieb an sechs anderen Stellen `, ` und an
 * drei ein Leerzeichen.
 *
 * > **Zwei Schreibweisen für dieselbe Sache in einer Datei sind keine Wahl,
 * > sondern ein Versehen — und die seltenere ist die, die niemand
 * > gegenprüft.**
 *
 * ## Was sie tut
 *
 * Ein Komma zwischen den Werten, und **innerhalb** eines Wertes eine
 * Umbruchgelegenheit nach jedem Zeichen, an dem das Format selbst trennt:
 * `:` bei einer IPv6, `.` bei einem Namen. Der Browser bricht dann zuerst
 * dort; `anywhere` bleibt der Rückfall für den Fall, dass auch das nicht
 * reicht.
 *
 * **`<wbr>` und nicht `&shy;`.** Ein weiches Trennzeichen setzt beim Umbruch
 * einen Bindestrich — in einer Adresse wäre das ein Zeichen, das nicht
 * dazugehört, und wer die Zeile abschreibt, tippt ihn mit.
 *
 * **Und die Gelegenheit steht nur *zwischen* den Stücken, nie hinter dem
 * letzten.** Der erste Wurf setzte sie überall, und im Bild fiel damit die
 * schliessende Klammer eines Satzes allein auf die nächste Zeile:
 *
 *     (159.195.56.255, 2a0a:4cc0:2000:1a4::3083
 *     ). Das kann hinter NAT richtig sein …
 *
 * Ein `<wbr>` am Ende eines Wertes erlaubt den Umbruch nicht *im* Wert,
 * sondern *hinter* ihm — also genau dort, wo der Satz weitergeht.
 *
 * > **Eine Umbruchgelegenheit am Ende eines Wertes gehört nicht mehr dem
 * > Wert.**
 *
 * ## Was sie nicht tut
 *
 * Sie gestaltet nicht. `.ident` und `.quiet` bleiben Sache der Stelle, an der
 * sie steht — eine Komponente, die ihr eigenes Aussehen mitbringt, ist
 * derselbe Fehler wie ein Hexwert in einer Komponente.
 */
withDefaults(defineProps<{
  /** Die Werte. Leer heisst: `empty` wird gezeigt. */
  values: string[]

  /** Was bei einer leeren Liste dasteht. */
  empty?: string
}>(), { empty: '—' })

/**
 * Ein Wert, zerlegt an seinen eigenen Trennzeichen.
 *
 * **Der Ausdruck schaut zurück und verbraucht nichts** (`(?<=[.:])`): Das
 * Trennzeichen bleibt am Ende seines Stücks stehen, wo es hingehört. Ein
 * `split(/[.:]/)` gäbe die Punkte und Doppelpunkte verloren, und aus
 * `a.b` würde `ab`.
 */
const stuecke = (wert: string): string[] => wert.split(/(?<=[.:])/)
</script>

<template>
  <!--
    **Kein umschliessendes Element.** Diese Komponente steht in einer Zelle,
    in einem Satz, in einer Meldung — überall dort trägt schon etwas anderes
    die Klasse. Ein eigenes `<span>` wäre ein zusätzliches Flexkind, und genau
    daran ist die Meldung „nicht gefragt" zerbrochen (`NoticeChildrenTest`).
  -->
  <template v-if="values.length === 0">{{ empty }}</template>
  <template
    v-for="(wert, index) in values"
    v-else
    :key="wert"
  ><template v-if="index > 0">, </template><template
    v-for="(stueck, stelle) in stuecke(wert)"
    :key="stelle"
  ><wbr v-if="stelle > 0">{{ stueck }}</template></template>
</template>
