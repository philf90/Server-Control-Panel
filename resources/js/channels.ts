/*
 * Wie ein Kanal heisst — und was er tut.
 *
 * **Die Schlüssel sind die der Umsetzungen** (`App\Support\Notify\Channels`),
 * und `ChannelReachTest` hält beide Richtungen aneinander: Jeder Kanal, der
 * hier steht, hat eine Umsetzung, und jede Umsetzung steht hier. Ohne die
 * zweite Richtung entstünde der tote Eintrag, der wirklich vorkommt — jemand
 * baut einen Kanal, der Nachtlauf bedient ihn, und die Seite bietet ihn nie
 * an.
 *
 * **Die Beschriftung steht hier und nicht im Controller**, weil sie ein Text
 * der Oberfläche ist: Der Schlüssel ist ein Bezeichner und englisch, der Name
 * ist deutsch (`docs/19 §4a`).
 *
 * **Eine Datei und nicht zwei Seiten.** Bis zum 5. Oktober 2026 stand diese
 * Ablage in der Einstellungsseite der Meldungen. Seitdem nennt auch die Seite
 * „Diagnose" neben jedem Befund den Kanal, über den er gemeldet wurde
 * (`docs/141 §0` Befund 5), und zwei Fassungen derselben Namen liefen beim
 * nächsten Kanal auseinander.
 */
export const KANAELE: Record<string, { name: string, satz: string }> = {
  mail: {
    name: 'Mailversand',
    satz: 'Kontingente an den Kunden, alles Übrige an den Betreiber.',
  },
  webhook: {
    name: 'Meldeziel (Webhook)',
    satz: 'Jeder Befund an eine Adresse dieses Servers, je Gegenstand einer.',
  },
}

/** Der Name eines Kanals — oder sein Schlüssel, wenn es ihn hier nicht gibt. */
export function channelName(key: string): string {
  return KANAELE[key]?.name ?? key
}
