// Fährt die Detailseite des Dateimanagers in einem echten Browser.
//
// Zwei Dinge, die kein Go-Test beantwortet:
//
//  1. Wählt die Zielauswahl wirklich nur aus, was es gibt? Sie holt die Struktur
//     von /files/dirs und ersetzt damit ein freies Textfeld. Ob das Feld beim
//     Absenden den gewählten Pfad trägt — und nicht den der Auswahlliste, die
//     ohne Skript gilt —, sagt nur der Browser.
//  2. Läuft die Ziffer mit den Kästchen im Gleichschritt? Beides ist eine
//     Umrechnung, und beide Richtungen müssen sich treffen.
//
//  3. Sieht man das Zeilenmenü der Liste, wenn es aufgeklappt ist? Zwei
//     Vorfahren beschneiden — die Karte und der Scrollbehälter der Tabelle.
//
// Erwartete Umgebung: ASYLUM_E2E_URL, ASYLUM_E2E_COOKIE, ASYLUM_E2E_PATH,
// ASYLUM_E2E_DIR, ASYLUM_CHROMIUM, ASYLUM_NODE_PATH.

const { chromium } = require("playwright");

async function main() {
  const basis = process.env.ASYLUM_E2E_URL;
  const [name, wert] = process.env.ASYLUM_E2E_COOKIE.split("=");
  const url = new URL(basis);

  const browser = await chromium.launch({
    executablePath: process.env.ASYLUM_CHROMIUM,
    args: ["--no-sandbox"],
  });
  const kontext = await browser.newContext({ ignoreHTTPSErrors: true });
  await kontext.addCookies([
    { name, value: wert, domain: url.hostname, path: "/", httpOnly: true, secure: false, sameSite: "Strict" },
  ]);

  const seite = await kontext.newPage();
  const verstoesse = [];
  seite.on("console", (m) => {
    if (/Content Security Policy|Refused to/i.test(m.text())) verstoesse.push(m.text());
  });
  seite.on("pageerror", (err) => verstoesse.push("Skriptfehler: " + err.message));

  await seite.goto(basis + "/files/entry?path=" + encodeURIComponent(process.env.ASYLUM_E2E_PATH), {
    waitUntil: "load",
  });

  // --- 1. Zielauswahl -------------------------------------------------------
  await seite.waitForSelector(".zielwahl .ziel-liste", { timeout: 3000 });

  const auswahl = await seite.evaluate(() => {
    const box = document.querySelector(".zielwahl");
    return {
      // Die Liste ohne Skript darf nicht mitgesendet werden, sonst kämen zwei
      // Werte für "target" an.
      listeHatNamen: !!box.querySelector("select").getAttribute("name"),
      listeVersteckt: box.querySelector("select").hidden,
      feldWert: box.querySelector('input[name="target"]').value,
      freieEingaben: box.querySelectorAll('input[type="text"]').length,
      ordner: Array.from(box.querySelectorAll(".ziel-eintrag")).map((b) => b.textContent.trim()),
      marken: Array.from(box.querySelectorAll(".ziel-marke")).map((b) => b.textContent.trim()),
    };
  });

  // In einen Unterordner wechseln: Der gewählte Pfad muss mitgehen.
  const ersterOrdner = seite.locator(".ziel-liste .ziel-eintrag:not(.hoch)").first();
  let nachKlick = null;
  if ((await ersterOrdner.count()) > 0) {
    const beschriftung = (await ersterOrdner.textContent()).replace("▸", "").trim();
    await ersterOrdner.click();
    await seite.waitForTimeout(150);
    nachKlick = await seite.evaluate(() => ({
      feldWert: document.querySelector('.zielwahl input[name="target"]').value,
      gewaehlt: document.querySelector(".ziel-gewaehlt").textContent,
    }));
    nachKlick.beschriftung = beschriftung;
  }

  // --- 2. Rechteraster ------------------------------------------------------
  const vorher = await seite.inputValue("#mode");
  const kaestchenFrei = await seite.evaluate(
    () => !document.querySelector('[data-rechte-rolle="user"][data-rechte-recht="w"]').disabled,
  );

  // Kästchen → Ziffer: der Gruppe das Schreiben geben.
  await seite.check('[data-rechte-rolle="group"][data-rechte-recht="w"]');
  const nachKasten = {
    octal: await seite.inputValue("#mode"),
    satz: await seite.textContent('[data-rechte-satz="group"]'),
  };

  // Ziffer → Kästchen: 0600 tippen.
  await seite.fill("#mode", "0600");
  const nachZiffer = await seite.evaluate(() => {
    const an = (rolle, recht) =>
      document.querySelector(`[data-rechte-rolle="${rolle}"][data-rechte-recht="${recht}"]`).checked;
    return {
      userR: an("user", "r"),
      userW: an("user", "w"),
      userX: an("user", "x"),
      groupR: an("group", "r"),
      otherR: an("other", "r"),
      satzAlle: document.querySelector('[data-rechte-satz="other"]').textContent.trim(),
    };
  });

  // Sonderbit: das Sticky-Bit setzen, die Ziffer muss vorne springen.
  await seite.check('[data-rechte-sonder="sticky"]');
  const nachSonder = await seite.inputValue("#mode");

  // --- 3. Zeilenmenü in der Liste -------------------------------------------
  //
  // Die Aktionen einer Zeile stecken in einem <details>. Ob es aufgeht, sagt
  // das Markup; ob man das Aufgeklappte auch sieht, nicht: Zwei Vorfahren
  // beschneiden (die Karte und der Scrollbehälter der Tabelle), und
  // abgeschnitten war es zuletzt bis auf zehn Pixel.
  // Hohes Fenster, damit die letzte Zeile samt Menü hineinpasst: Scrollen wäre
  // hier kein Ausweg, sondern der Grund für ein falsches Ergebnis. Ein
  // "overflow: hidden" macht die Karte für Skripte scrollbar —
  // scrollIntoViewIfNeeded schiebt das Menü dann genau in die Beschneidung
  // hinein, und der Test sähe es frei liegen.
  await seite.setViewportSize({ width: 1280, height: 1500 });
  await seite.goto(basis + "/files?path=" + encodeURIComponent(process.env.ASYLUM_E2E_DIR), {
    waitUntil: "load",
  });
  const letztes = seite.locator("table.dateien .zeilenmenu").last();
  const menue = { zahl: await seite.locator("table.dateien .zeilenmenu").count() };
  menue.knoepfeFrei = await seite.locator("table.dateien td.actions > a.button").count();
  await letztes.locator("summary").click();
  await seite.waitForTimeout(80);
  Object.assign(
    menue,
    await seite.evaluate(() => {
      const liste = document.querySelector(".zeilenmenu[open] .menuliste");
      const eintraege = Array.from(liste.querySelectorAll("a"));
      const r = liste.getBoundingClientRect();
      // Der harte Test: Liegt der Mittelpunkt jedes Eintrags frei? Wird er von
      // einem Vorfahren beschnitten, antwortet elementFromPoint mit etwas
      // anderem — oder mit null, wenn er außerhalb des Fensters liegt.
      const frei = eintraege.every((a) => {
        const b = a.getBoundingClientRect();
        const o = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
        return o !== null && (o === a || a.contains(o));
      });
      return {
        eintraege: eintraege.map((a) => a.textContent.trim()),
        frei,
        hoehe: Math.round(r.height),
        // Bleibt die Liste innerhalb der Karte? Nach rechts hinaus wäre sie
        // teils unter dem Fensterrand.
        inDerKarte: r.right <= liste.closest(".card").getBoundingClientRect().right + 1,
      };
    }),
  );

  console.log(
    JSON.stringify({
      verstoesse,
      auswahl,
      nachKlick,
      rechte: { vorher, kaestchenFrei, nachKasten, nachZiffer, nachSonder },
      menue,
    }),
  );
  await browser.close();
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
