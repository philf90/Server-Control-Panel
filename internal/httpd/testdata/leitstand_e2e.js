// Fährt die neue Oberfläche in einem echten Browser.
//
// Vier Fragen, die kein Go-Test beantworten kann:
//
//  1. Montiert die Anwendung überhaupt? Ein Go-Test sieht die Hülle mit einem
//     leeren <div id="app">; ob Svelte darin etwas erzeugt, sagt nur der
//     Browser. Ein Laufzeitfehler im Bundle bliebe sonst unsichtbar.
//  2. Verwirft die Content-Security-Policy etwas? Das Bundle ist eine externe
//     Datei und ein Modulskript, das Stylesheet ebenso — aber genau an dieser
//     Stelle ist das Projekt schon zweimal gescheitert (Auslastungsbalken in
//     rc.5, CodeMirror im Dateimanager).
//  3. Ist der Strich der Verläufe gleichmäßig? Die Kachel ist neu gebaut, die
//     Falle dieselbe: 100 viewBox-Einheiten werden waagerecht stärker gestreckt
//     als senkrecht. Ohne vector-effect zieht der Browser die Strichstärke mit.
//  4. Trägt der Live-Kanal? Die große Zahl kommt beim Aufbau aus /api/v1/overview
//     und wird danach aus dem SSE-Strom fortgeschrieben.
//  5. Werden Tabellen unter 600 Pixeln zu Karten? `overflow-x: auto` allein
//     genügt nicht — eine seitlich scrollende Tabelle ist bedienbar, aber man
//     sieht ihr nicht an, dass rechts noch etwas steht. Gemessen wird, ob der
//     Seitenkörper waagerecht scrollt und ob die Spaltennamen als Beschriftung
//     erscheinen.
//  6. Klappen die weiteren Einhängepunkte einer Platte auf?
//
// Aufruf über TestLeitstandBrowser. Erwartete Umgebung:
//   ASYLUM_E2E_URL, ASYLUM_E2E_COOKIE, ASYLUM_CHROMIUM
//   ASYLUM_NODE_PATH (Verzeichnis mit playwright)

const { chromium } = require("playwright");

const gesammelt = [];

async function main() {
  const basis = process.env.ASYLUM_E2E_URL;
  const cookie = process.env.ASYLUM_E2E_COOKIE;

  const browser = await chromium.launch({
    executablePath: process.env.ASYLUM_CHROMIUM,
    args: ["--no-sandbox"],
  });
  const kontext = await browser.newContext({ ignoreHTTPSErrors: true });

  const [name, wert] = cookie.split("=");
  const url = new URL(basis);
  await kontext.addCookies([
    {
      name,
      value: wert,
      domain: url.hostname,
      path: "/",
      httpOnly: true,
      secure: false,
      sameSite: "Strict",
    },
  ]);

  const seite = await kontext.newPage();
  const verstoesse = [];
  const fehler = [];

  seite.on("console", (m) => {
    const text = m.text();
    gesammelt.push(`${m.type()}: ${text}`);
    if (/Content Security Policy|Refused to/i.test(text)) {
      verstoesse.push(text);
    }
  });
  // Ein Laufzeitfehler im Bundle erscheint nicht als Konsolenmeldung, sondern
  // als pageerror. Ohne diesen Mitleser wäre eine leere Seite ein grüner Test.
  seite.on("pageerror", (e) => {
    fehler.push(String(e));
    gesammelt.push(`pageerror: ${e}`);
  });
  // Fehlende Antworten mit Adresse festhalten: "404" allein sagt nicht, ob ein
  // Asset fehlt (schwer) oder der Browser nach einem Favicon fragt (harmlos).
  // Alle angefragten Adressen mitschreiben. Gebraucht wird das für eine Frage,
  // die man dem fertigen Bild nicht ansehen kann: Hat die Seite den
  // Ereignisstrom eines Vorgangs überhaupt geöffnet? Die Zeilen stünden auch da,
  // wenn sie nur die Ressource abgefragt hätte — bei einem Vorgang, der eine
  // Viertelstunde läuft, wäre das der Unterschied zwischen einer Quittung und
  // einem Standbild.
  const angefragt = [];
  seite.on("request", (r) => angefragt.push(r.url()));

  const fehlend = [];
  seite.on("response", (r) => {
    // 409 ist keine fehlende Antwort, sondern eine Rückfrage: Der Handler führt
    // nichts aus, solange die Bestätigung fehlt, und schickt stattdessen deren
    // Text (siehe api_v1_bestaetigung.go). Sie hier mitzuzählen wäre Rauschen,
    // das eine echte 404 später verdeckt.
    if (r.status() >= 400 && r.status() !== 409) {
      fehlend.push(`${r.status()} ${r.url()}`);
      gesammelt.push(`response: ${r.status()} ${r.url()}`);
    }
  });

  // Bewusst nicht "networkidle": Die Anwendung hält den Live-Kanal dauerhaft
  // offen, das Netz wird also nie ruhig. Gewartet wird stattdessen unten auf
  // die Kachel — auf das Ergebnis, nicht auf einen Zustand des Netzes.
  await seite.goto(`${basis}/v2/`, { waitUntil: "domcontentloaded" });

  // 1. Montiert die Anwendung? Gewartet wird auf die Kachel und nicht auf einen
  //    Zeitraum: Ein fester Schlaf wäre auf einer langsamen Maschine zu kurz
  //    und auf einer schnellen Zeitverschwendung.
  await seite.waitForSelector(".karte", { timeout: 10000 });

  const montiert = await seite.evaluate(() => {
    const app = document.getElementById("app");
    return {
      kinder: app ? app.children.length : 0,
      kacheln: document.querySelectorAll(".karte").length,
      schale: document.querySelectorAll(".schale").length,
      statusband: document.querySelectorAll(".statusband").length,
      seitenleiste: document.querySelectorAll(".seitenleiste").length,
      protokoll: document.querySelectorAll(".protokollzeile").length,
      // Der Stil muss angekommen sein: Kommt die Datei nicht durch die
      // Richtlinie, ist die Kachel unbeholfen weiß statt dunkel.
      kartenFarbe: getComputedStyle(document.querySelector(".karte")).backgroundColor,
    };
  });

  // Urteil und Handlungsbedarf: Beide kommen aus einem eigenen Aufruf, der
  // systemctl anfasst — sie erscheinen also nach den Kacheln.
  await seite.waitForSelector(".urteil b", { timeout: 10000 });
  const uebersicht = await seite.evaluate(() => {
    const urteil = document.querySelector(".urteil");
    const punkte = [...document.querySelectorAll(".handlungsbedarf li")];
    return {
      urteilText: urteil ? urteil.textContent.trim().replace(/\s+/g, " ") : "",
      urteilUnbekannt: urteil ? urteil.classList.contains("unbekannt") : null,
      punkte: punkte.length,
      // Jeder Punkt trägt einen Weg dorthin, wo man ihn behebt.
      punkteMitGriff: punkte.filter((li) => li.querySelector("a.griff")).length,
      tabellen: document.querySelectorAll("table.tabelle").length,
      dateisystemZeilen: document.querySelectorAll("table.tabelle tbody tr").length,
    };
  });

  // Sitzt jeder Tabellentitel ÜBER seiner Tabelle und nicht daneben?
  //
  // Diese Messung gibt es, weil der Fehler passiert ist: Jede Tabellen-
  // komponente gab zwei Wurzelelemente aus (Titel und Rahmen), und im Gitter
  // der Übersicht ist jedes Wurzelelement eine eigene Zelle — der Titel landete
  // in der linken Spalte, die Tabelle in der rechten. Der DOM-Test war grün,
  // weil beide Elemente vorhanden waren. Gesehen hat es erst ein Bildschirmfoto,
  // und danach war klar, was zu messen ist: dieselbe linke Kante, Titel oben.
  const titelSitz = await seite.evaluate(() => {
    return [...document.querySelectorAll(".tabelle-titel")].map((titel) => {
      const rahmen = titel.parentElement.querySelector(".tabelle-rahmen");
      if (!rahmen) return { name: titel.textContent.trim(), gefunden: false };
      const t = titel.getBoundingClientRect();
      const r = rahmen.getBoundingClientRect();
      return {
        name: titel.textContent.trim(),
        gefunden: true,
        gleicheKante: Math.abs(t.left - r.left) <= 1,
        titelDarueber: t.bottom <= r.top + 1,
      };
    });
  });

  // Wird eine Tabelle beschnitten? Der Rahmen hatte overflow: hidden, und die
  // letzte Spalte war halb abgeschnitten — ohne Balken, also ohne Hinweis. Gemessen
  // wird deshalb, ob der Inhalt in den Rahmen passt; passt er nicht, muss der
  // Rahmen wenigstens scrollen können.
  const rahmenSitz = await seite.evaluate(() => {
    return [...document.querySelectorAll(".tabelle-rahmen")].map((r) => ({
      inhaltBreite: r.scrollWidth,
      rahmenBreite: r.clientWidth,
      scrollbar: getComputedStyle(r).overflowX,
    }));
  });

  // 6. Die weiteren Einhängepunkte aufklappen.
  let zweige = { vorher: 0, nachher: 0 };
  const mehr = await seite.$("button.mehr");
  if (mehr) {
    zweige.vorher = await seite.evaluate(() => document.querySelectorAll("tr.zweig").length);
    await mehr.click();
    await seite.waitForSelector("tr.zweig", { timeout: 3000 }).catch(() => {});
    zweige.nachher = await seite.evaluate(() => document.querySelectorAll("tr.zweig").length);
  }

  // 3. Strich und Endpunkt vermessen — dieselbe Messung wie bei der alten
  //    Übersicht, weil die Kachel neu gebaut ist.
  const strich = await seite.evaluate(() => {
    const svg = document.querySelector("svg.verlauf");
    if (!svg) return null;
    const kasten = svg.getBoundingClientRect();
    const linie = svg.querySelector("path.linie");
    const punkt = svg.querySelector("path.punkt");
    return {
      svgBreite: kasten.width,
      effekt: getComputedStyle(linie).getPropertyValue("vector-effect").trim(),
      punktEffekt: getComputedStyle(punkt).getPropertyValue("vector-effect").trim(),
      strichbreite: getComputedStyle(linie).getPropertyValue("stroke-width").trim(),
    };
  });

  // 4. Der Live-Kanal: Der Punkt im Statusband wird grün, sobald EventSource
  //    offen ist.
  let live = false;
  try {
    await seite.waitForFunction(
      () => document.querySelector(".statusband .live.an") !== null,
      { timeout: 10000 },
    );
    live = true;
  } catch {
    live = false;
  }

  // Die Ablesung am Verlauf: Zeiger über die Kachel, Kasten muss erscheinen.
  let ablesung = "";
  if (strich && strich.svgBreite > 0) {
    const svg = await seite.$("svg.verlauf");
    const kasten = await svg.boundingBox();
    await seite.mouse.move(kasten.x + kasten.width * 0.6, kasten.y + kasten.height / 2);
    try {
      await seite.waitForSelector(".ablesung", { timeout: 3000 });
      ablesung = (await seite.textContent(".ablesung")).trim();
    } catch {
      ablesung = "";
    }
  }

  // Die Befehlspalette. Geprüft wird der Weg, den jemand mit der Tastatur nimmt:
  // öffnen, tippen, mit dem Pfeil wählen, Enter. Ein DOM-Test sieht davon
  // nichts — ob ⌘K überhaupt ankommt und ob der Fokus im Feld landet, sagt nur
  // der Browser.
  const palette = { schritte: [] };
  await seite.keyboard.press("Control+k");
  await seite.waitForSelector('[role="dialog"]', { timeout: 3000 });
  palette.schritte.push("mit Strg+K geöffnet");

  palette.fokusImFeld = await seite.evaluate(
    () => document.activeElement?.classList.contains("feld") ?? false,
  );
  palette.zieleGesamt = await seite.evaluate(
    () => document.querySelectorAll('[role="option"]').length,
  );

  // Ein Suchwort, das NICHT im Namen steht: "nginx" muss den Webserver finden.
  // Genau daran entscheidet sich, ob die Palette eine Suche ist oder eine Liste.
  await seite.fill("input.feld", "nginx");
  await seite.waitForTimeout(120);
  palette.trefferNginx = await seite.evaluate(() =>
    [...document.querySelectorAll('[role="option"]')].map((o) =>
      o.querySelector(".label")?.textContent.trim(),
    ),
  );

  // Umlaut weggelassen: "ubersicht" muss die Übersicht finden.
  await seite.fill("input.feld", "ubersicht");
  await seite.waitForTimeout(120);
  palette.trefferOhneUmlaut = await seite.evaluate(() =>
    [...document.querySelectorAll('[role="option"]')].map((o) =>
      o.querySelector(".label")?.textContent.trim(),
    ),
  );

  // Nichts gefunden ist ein Zustand, kein Fehler.
  await seite.fill("input.feld", "xyzquatsch");
  await seite.waitForTimeout(120);
  palette.leerZustand = await seite.evaluate(
    () => document.querySelector(".leer")?.textContent.trim() ?? "",
  );

  // Escape schließt.
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(150);
  palette.nachEscape = await seite.evaluate(
    () => document.querySelector('[role="dialog"]') === null,
  );

  // Der Knopf im Statusband öffnet sie ebenfalls, und der Pfeil wandert.
  await seite.click(".statusband .kbd");
  await seite.waitForSelector('[role="dialog"]', { timeout: 3000 });
  palette.schritte.push("über den Knopf im Statusband geöffnet");
  await seite.keyboard.press("ArrowDown");
  await seite.waitForTimeout(80);
  palette.zweiteGewaehlt = await seite.evaluate(() => {
    const gewaehlt = document.querySelectorAll('[role="option"]');
    return gewaehlt[1]?.getAttribute("aria-selected") === "true";
  });

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-palette.png`,
    });
  }

  // Ein Klick in die Palette darf sie nicht schließen, ein Klick daneben schon.
  // Das hängt am Ziel des Klicks: Der Schleier horcht, die Palette liegt darin.
  // Ohne diese zwei Messungen wäre der Unterschied unbemerkt — die Tastatur
  // nimmt beide Wege nicht.
  await seite.click(".feldzeile .lupe");
  await seite.waitForTimeout(120);
  palette.klickInnenHaelt = await seite.evaluate(
    () => document.querySelector('[role="dialog"]') !== null,
  );
  await seite.mouse.click(20, 20);
  await seite.waitForTimeout(150);
  palette.klickDanebenSchliesst = await seite.evaluate(
    () => document.querySelector('[role="dialog"]') === null,
  );

  // 5. Schmalmodus: Der Seitenkörper darf nicht waagerecht scrollen, und die
  //    Zellen müssen ihre Spaltenbeschriftung zeigen.
  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(200);
  const schmal = await seite.evaluate(() => {
    const zelle = document.querySelector("table.tabelle td[data-spalte]");
    return {
      koerperBreite: document.body.scrollWidth,
      fensterBreite: window.innerWidth,
      // ::before mit content: attr(data-spalte) — sichtbar nur im Schmalmodus.
      beschriftung: zelle
        ? getComputedStyle(zelle, "::before").content.replace(/"/g, "")
        : "",
    };
  });
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-schmal.png`,
      fullPage: true,
    });
  }
  await seite.setViewportSize({ width: 1280, height: 720 });
  await seite.waitForTimeout(200);

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.mouse.move(0, 0);
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-uebersicht.png`,
      fullPage: true,
    });
  }

  // 8. Das Modul Dienste. Hier hängt mehr am Browser als bei der Übersicht: der
  //    Wechsel ohne Neuladen, die Auswahl in der Adresse, der Zurück-Knopf und
  //    die Rückfrage vor dem Stoppen. Nichts davon sieht ein Go-Test.
  const dienste = {};

  // Eine Marke am Fenster: Sie überlebt einen Wechsel ohne Neuladen und stirbt
  // mit jedem echten Seitenaufruf. Das ist der Beweis, dass der Router greift —
  // und dass das Statusband mitsamt Live-Kanal stehen bleibt.
  await seite.evaluate(() => {
    window.__marke = "haelt";
  });
  await seite.click('.seitenleiste a[href="/v2/dienste"]');
  await seite.waitForSelector("table.tabelle .zeile", { timeout: 5000 });
  dienste.ohneNeuladen = await seite.evaluate(() => window.__marke === "haelt");
  dienste.pfad = await seite.evaluate(() => location.pathname);
  dienste.navAktiv = await seite.evaluate(
    () => document.querySelector('.seitenleiste a[aria-current="page"] span')?.textContent ?? "",
  );

  // Gescheitertes zuerst — der Grund, warum jemand diese Seite öffnet.
  dienste.reihen = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody tr")].map((tr) => ({
      name: tr.querySelector(".zeile")?.textContent.trim() ?? "",
      zustand: tr.querySelector(".zustand")?.textContent.trim() ?? "",
    })),
  );

  // Auswahl: Klick auf die Zeile öffnet den Inspektor, und die Adresse trägt sie.
  await seite.click("table.tabelle tbody tr:first-child .zeile");
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  dienste.nachKlick = await seite.evaluate(() => ({
    suche: location.search,
    titel: document.querySelector(".inspektor h2")?.textContent.trim() ?? "",
    // Die Wertepaare im Inspektor — kommen aus dem zweiten Aufruf, nicht aus
    // der Liste. Steht hier nichts, hat das Detail nicht geladen.
    paare: document.querySelectorAll(".inspektor .kv dt").length,
  }));

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-dienste.png`,
      fullPage: true,
    });
  }

  // Der Zurück-Knopf schließt den Inspektor, statt die Seite zu verlassen. Das
  // hängt daran, dass die ERSTE Auswahl ein Schritt im Verlauf ist.
  await seite.goBack();
  await seite.waitForTimeout(200);
  dienste.nachZurueck = await seite.evaluate(() => ({
    inspektor: document.querySelector(".inspektor") !== null,
    pfad: location.pathname,
    suche: location.search,
  }));

  // Ein Neuladen auf der tiefen Adresse muss denselben Zustand zeigen — sonst
  // ist der Verweis nicht teilbar, und genau das war der Zweck der Übung.
  await seite.goto(`${basis}/v2/dienste?unit=nginx.service`, {
    waitUntil: "domcontentloaded",
  });
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  dienste.nachNeuladen = await seite.evaluate(
    () => document.querySelector(".inspektor h2")?.textContent.trim() ?? "",
  );

  // Die Suche filtert im Browser. Ein Begriff, der nur in der Beschreibung
  // steht: Wer „Webserver" tippt, sucht nginx, und der Unitname sagt das nicht.
  await seite.fill(".suche input", "Webserver");
  await seite.waitForTimeout(150);
  dienste.gefiltert = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody .zeile")].map((b) =>
      b.textContent.trim(),
    ),
  );
  await seite.fill(".suche input", "");
  await seite.waitForTimeout(150);

  // Die Zähler sind Filter — Grundsatz II: jede Zahl ist ein Griff.
  await seite.click(".stufen button:nth-child(2)");
  await seite.waitForTimeout(150);
  dienste.nurGescheitert = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody tr")].map(
      (tr) => tr.querySelector(".zustand")?.textContent.trim() ?? "",
    ),
  );
  await seite.click(".stufen button:nth-child(2)");
  await seite.waitForTimeout(150);

  // Die Rückfrage. Der Kern: Vor der Bestätigung darf NICHTS passieren, und der
  // gefährliche Knopf darf den Fokus nicht haben — sonst zerstört Enter sofort.
  const stoppKnopf = ".aktionen .knopf.gefahr";
  await seite.waitForSelector(stoppKnopf, { timeout: 5000 });
  await seite.click(stoppKnopf);
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  dienste.rueckfrage = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    return {
      frage: d?.querySelector(".frage")?.textContent.trim() ?? "",
      punkte: d?.querySelectorAll(".punkte li").length ?? 0,
      // Der gefährliche Knopf ist der letzte; den Fokus hat er nicht.
      fokusAufGefahr: document.activeElement?.classList.contains("gefahr") ?? false,
      // Stufe 2 hat kein Eingabefeld. Stünde hier eines, wäre die Stufe falsch.
      tippfeld: d?.querySelector(".tippen") !== null,
    };
  });

  // Escape ist ein Abbruch — und der Dialog muss danach wieder zu öffnen sein.
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(200);
  dienste.nachAbbruch = await seite.evaluate(
    () => document.querySelector("dialog.rueckfrage") === null,
  );
  await seite.click(stoppKnopf);
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  await seite.click("dialog.rueckfrage .knopf.gefahr");
  await seite.waitForTimeout(400);
  dienste.nachBestaetigung = await seite.evaluate(() => ({
    dialogZu: document.querySelector("dialog.rueckfrage") === null,
    meldung: document.querySelector(".inspektor .meldung")?.textContent.trim() ?? "",
  }));

  // Escape schließt den Inspektor.
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(200);
  dienste.escapeSchliesst = await seite.evaluate(
    () => document.querySelector(".inspektor") === null,
  );

  // Schmal: Auch diese Seite darf nicht waagerecht scrollen, und der Inspektor
  // steht dann über der Liste statt daneben.
  await seite.goto(`${basis}/v2/dienste?unit=nginx.service`, {
    waitUntil: "domcontentloaded",
  });
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  dienste.schmal = await seite.evaluate(() => {
    const insp = document.querySelector(".inspektor");
    const tab = document.querySelector(".tabelle-rahmen");
    return {
      koerperBreite: document.body.scrollWidth,
      fensterBreite: window.innerWidth,
      // Der Inspektor steht oben: kleinerer y-Wert als die Tabelle.
      inspektorOben: insp.getBoundingClientRect().top < tab.getBoundingClientRect().top,
    };
  });
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-dienste-schmal.png`,
      fullPage: true,
    });
  }
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 9. Das Modul Pakete und die Vorgangsplatte. Hier hängt am Browser, was kein
  //    Go-Test sehen kann: Kommen die Zeilen über den Ereignisstrom an, und
  //    findet jemand, der die Seite neu lädt, den Vorgang vor?
  const pakete = {};
  await seite.goto(`${basis}/v2/pakete`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle .pfad", { timeout: 5000 });

  pakete.reihen = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody tr")].map((tr) => ({
      name: tr.querySelector(".pfad")?.textContent.trim() ?? "",
      // Sicherheitszeilen sind zusätzlich zur Farbe an einem Wort erkennbar.
      art: tr.querySelector(".zustand")?.textContent.trim() ?? "",
    })),
  );
  // Der Neustart-Hinweis erscheint nur, wenn einer aussteht — ein Knopf, der
  // immer da ist, wird irgendwann versehentlich gedrückt. Die Attrappe sagt in
  // diesem Lauf ja, und der Hinweis nennt das Paket, das ihn verlangt.
  pakete.neustart = await seite.evaluate(() => {
    const n = document.querySelector(".neustart");
    return {
      da: n !== null,
      text: n?.textContent.trim() ?? "",
      knopf: n?.querySelector(".knopf") !== null,
    };
  });

  // Listen holen: Der Knopf löst einen Vorgang aus, die Platte erscheint, und
  // die Zeilen von apt stehen darin. Ohne die wäre die Quittung eine Behauptung.
  await seite.click(".aktionen .knopf.leise");
  await seite.waitForSelector(".vorgang", { timeout: 5000 });
  await seite.waitForFunction(
    () => (document.querySelectorAll(".vorgang .auszug .zeile").length ?? 0) > 0,
    null,
    { timeout: 5000 },
  );
  pakete.vorgang = await seite.evaluate(() => {
    const v = document.querySelector(".vorgang");
    return {
      titel: v?.querySelector("b")?.textContent.trim() ?? "",
      zustand: v?.querySelector(".zustand")?.textContent.trim() ?? "",
      zeilen: [...v.querySelectorAll(".auszug .zeile")].map((d) => d.textContent),
      // Die Fußzeile nennt, wer den Vorgang angestoßen hat, und die Laufzeit.
      kopf: v?.querySelector(".mut")?.textContent.trim() ?? "",
    };
  });

  pakete.stromGeoeffnet = angefragt.some((u) => u.includes("/api/v1/jobs/packages/events"));

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-pakete.png`,
      fullPage: true,
    });
  }

  // Neu laden: Der Vorgang ist auf dem Server, nicht in der Seite. Wer
  // zurückkommt, findet ihn vor — mit denselben Zeilen.
  await seite.goto(`${basis}/v2/pakete`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector(".vorgang", { timeout: 5000 });
  pakete.nachNeuladen = await seite.evaluate(() => ({
    zeilen: document.querySelectorAll(".vorgang .auszug .zeile").length,
    zustand: document.querySelector(".vorgang .zustand")?.textContent.trim() ?? "",
  }));

  // Alle einspielen fragt zurück, und die Frage nennt die Zahl.
  const alleKnopf = ".aktionen .knopf:not(.leise)";
  await seite.click(alleKnopf);
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  pakete.rueckfrage = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    return {
      frage: d?.querySelector(".frage")?.textContent.trim() ?? "",
      punkte: d?.querySelectorAll(".punkte li").length ?? 0,
      tippfeld: d?.querySelector(".tippen") !== null,
    };
  });
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(200);
  pakete.nachAbbruch = await seite.evaluate(
    () => document.querySelector("dialog.rueckfrage") === null,
  );

  // Stufe 3 am Neustart: Der bestätigende Knopf bleibt gesperrt, bis der
  // Hostname stimmt. Das ist die einzige Stelle in der neuen Oberfläche, an der
  // die dritte Stufe zu prüfen ist — und die Sperre ist der ganze Unterschied
  // zwischen Stufe 2 und Stufe 3.
  await seite.click(".neustart .knopf");
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  pakete.stufeDrei = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    const hinweis = d?.querySelector(".tippen span")?.textContent ?? "";
    return {
      tippfeld: d?.querySelector(".tippen input") !== null,
      hinweis,
      // Der Hostname steht im Hinweis — abzulesen, nicht zu erraten.
      wort: hinweis.split(": ").pop() ?? "",
      gesperrt: d?.querySelector(".knopf.gefahr")?.disabled ?? false,
      // Der gefährliche Knopf darf den Fokus nicht haben; bei Stufe 3 liegt er
      // im Eingabefeld.
      fokusImFeld: document.activeElement?.closest(".tippen") !== null,
    };
  });

  // Ein falsches Wort lässt den Knopf gesperrt, das richtige gibt ihn frei.
  await seite.fill("dialog.rueckfrage .tippen input", "falsch");
  await seite.waitForTimeout(120);
  pakete.stufeDrei.nachFalschem = await seite.evaluate(
    () => document.querySelector("dialog.rueckfrage .knopf.gefahr")?.disabled ?? false,
  );
  await seite.fill("dialog.rueckfrage .tippen input", pakete.stufeDrei.wort.toUpperCase());
  await seite.waitForTimeout(120);
  // Großschreibung darf genügen: Auf einem Telefon macht die Tastatur aus "vm"
  // gern "Vm". Der Server prüft ebenso.
  pakete.stufeDrei.nachRichtigem = await seite.evaluate(
    () => document.querySelector("dialog.rueckfrage .knopf.gefahr")?.disabled ?? true,
  );
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(200);

  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  pakete.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
    beschriftung: (() => {
      const zelle = document.querySelector("table.tabelle td[data-spalte]");
      return zelle
        ? getComputedStyle(zelle, "::before").content.replace(/"/g, "")
        : "";
    })(),
  }));
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-pakete-schmal.png`,
      fullPage: true,
    });
  }
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 10. Das Modul Logs. Der zweite Strom des Panels — und ein anderer als beim
  //     Vorgang: Er hat kein Ende, das der Server bestimmt. Geprüft wird genau
  //     das: Bleibt er offen, kommen Zeilen nach, gelten die Filter aus der
  //     Adresse auch für ihn, und wird er beim Verlassen der Seite angehalten?
  const logs = {};
  await seite.goto(`${basis}/v2/logs`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });

  logs.zeilenAnfangs = await seite.evaluate(
    () => document.querySelectorAll("table.tabelle tbody tr").length,
  );
  logs.spalten = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle thead th")].map((th) => th.textContent.trim()),
  );
  // Vor dem Umschalten ist kein Strom offen: Wer die Seite öffnet, will meist
  // lesen, was war. Ein Journal, das ungefragt einen Prozess auf dem Server
  // aufmacht, ist eine Zumutung an den Betrieb.
  logs.stromVorherOffen = angefragt.some((u) => u.includes("/api/v1/logs/follow"));

  // Filter setzen: Sie stehen in der Adresse, damit ein Verweis auf „nur Fehler"
  // teilbar ist.
  await seite.selectOption(".filter select >> nth=1", "3");
  await seite.waitForTimeout(250);
  logs.nachStufenfilter = await seite.evaluate(() => location.search);

  await seite.fill(".suchform input", "publickey");
  await seite.press(".suchform input", "Enter");
  await seite.waitForTimeout(250);
  logs.nachSuche = await seite.evaluate(() => location.search);

  // Zurück-Knopf: Er nimmt den Filter zurück, und das Suchfeld folgt.
  await seite.goBack();
  await seite.waitForTimeout(250);
  logs.nachZurueck = await seite.evaluate(() => ({
    suche: location.search,
    feld: document.querySelector(".suchform input")?.value ?? "",
  }));

  // Verfolgen einschalten. Die Attrappe schiebt eine Zeile nach — die muss
  // ankommen, ohne dass jemand neu lädt.
  await seite.goto(`${basis}/v2/logs`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });
  await seite.click(".filter .verfolgen");
  await seite.waitForSelector(".filter .verfolgen .puls", { timeout: 5000 });
  logs.stromGeoeffnet = angefragt.some((u) => u.includes("/api/v1/logs/follow"));

  // Auf eine bestimmte Zeile warten und nicht auf eine größere Zahl: Der Strom
  // bringt seinen eigenen Rückblick mit, und eine gewachsene Zeilenzahl könnte
  // auch der wieder eingespielte Rückblick sein. Diese eine Zeile gibt es nur im
  // Nachschub der Attrappe — sie kommt herein, während zugesehen wird.
  try {
    await seite.waitForFunction(
      () => document.body.textContent.includes("waehrend-des-verfolgens"),
      null,
      { timeout: 5000 },
    );
    logs.zeileNachgekommen = true;
  } catch {
    logs.zeileNachgekommen = false;
  }
  // Und keine Zeile steht doppelt da: Der Rückblick des Stroms ersetzt die
  // Liste, statt sie zu verdoppeln.
  logs.doppelt = await seite.evaluate(() => {
    const zeilen = [...document.querySelectorAll("table.tabelle tbody tr")].map((tr) =>
      tr.textContent.replace(/\s+/g, " ").trim(),
    );
    return zeilen.length - new Set(zeilen).size;
  });
  logs.knopfText = await seite.evaluate(
    () => document.querySelector(".filter .verfolgen")?.textContent.trim() ?? "",
  );

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-logs.png`,
      fullPage: true,
    });
  }

  // Anhalten schließt den Strom.
  await seite.click(".filter .verfolgen");
  await seite.waitForTimeout(300);
  logs.nachAnhalten = await seite.evaluate(
    () => document.querySelector(".filter .verfolgen .puls") === null,
  );

  // Und der Wechsel auf eine andere Seite hält ihn ebenfalls an — sonst läuft auf
  // dem Server ein journalctl weiter, dem niemand mehr zusieht. Geprüft am
  // Zähler des Servers, den die Abfrage mitbringt.
  await seite.click(".filter .verfolgen");
  await seite.waitForSelector(".filter .verfolgen .puls", { timeout: 5000 });
  await seite.click('.seitenleiste a[href="/v2/dienste"]');
  await seite.waitForSelector("table.tabelle .zeile", { timeout: 5000 });
  await seite.waitForTimeout(400);
  logs.folgerNachWechsel = await seite.evaluate(async () => {
    const r = await fetch("/api/v1/logs", {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    const d = await r.json();
    return d.folger_frei;
  });

  await seite.goto(`${basis}/v2/logs`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });
  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  logs.schmal = await seite.evaluate(() => {
    const zelle = document.querySelector("table.tabelle td[data-spalte]");
    return {
      koerperBreite: document.body.scrollWidth,
      fensterBreite: window.innerWidth,
      beschriftung: zelle
        ? getComputedStyle(zelle, "::before").content.replace(/"/g, "")
        : "",
    };
  });
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-logs-schmal.png`,
      fullPage: true,
    });
  }
  await seite.setViewportSize({ width: 1280, height: 720 });

  await browser.close();

  console.log(
    JSON.stringify({
      verstoesse,
      fehler,
      fehlend,
      montiert,
      uebersicht,
      titelSitz,
      rahmenSitz,
      palette,
      dienste,
      pakete,
      logs,
      zweige,
      schmal,
      strich,
      live,
      ablesung,
    }),
  );
}

main().catch((e) => {
  console.error(e);
  console.error(gesammelt.join("\n"));
  process.exit(1);
});
