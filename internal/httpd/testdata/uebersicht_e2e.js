// Fährt die Übersicht in einem echten Browser.
//
// Drei Fragen, die kein Go-Test beantworten kann:
//
//  1. Ist der Strich der Sparklines gleichmäßig? Der viewBox wird waagerecht
//     stärker gestreckt als senkrecht. Ohne "vector-effect: non-scaling-stroke"
//     zieht der Browser die Strichstärke mit — messbar am Endpunkt, der dann
//     breiter als hoch ist. Genau das sah aus, als würde der Verlauf auslaufen.
//  2. Zeigt der Mouseover den Messwert? Die Stelle des Kastens setzt spark.js
//     über das CSSOM. Ob die Content-Security-Policy des Panels das durchlässt
//     (sie tut es) oder verwirft, sagt nur der Browser.
//  3. Klappen die weiteren Einhängepunkte einer Platte auf? Der Umschalter ist
//     eine Checkbox ohne JavaScript; sichtbar werden die Zeilen über :has() auf
//     dem gemeinsamen <tbody>.
//
// Aufruf über TestUebersichtBrowser. Erwartete Umgebung:
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
  seite.on("console", (m) => {
    const text = m.text();
    gesammelt.push(`${m.type()}: ${text}`);
    if (/Content Security Policy|Refused to/i.test(text)) {
      verstoesse.push(text);
    }
  });
  seite.on("pageerror", (err) => {
    gesammelt.push(`pageerror: ${err.message}`);
    verstoesse.push(`Skriptfehler: ${err.message}`);
  });

  await seite.setViewportSize({ width: 1280, height: 1000 });
  await seite.goto(basis + "/alt/", { waitUntil: "load" });

  // --- 1. Der Strich ---------------------------------------------------------
  const strich = await seite.evaluate(() => {
    const svg = document.querySelector("svg.spark");
    const linie = svg.querySelector("path.line");
    return {
      svgBreite: svg.getBoundingClientRect().width,
      svgHoehe: svg.getBoundingClientRect().height,
      effekt: getComputedStyle(linie).vectorEffect,
      staerke: getComputedStyle(linie).strokeWidth,
      stuetzstellen: linie.getAttribute("d").split("L").length,
    };
  });

  // Der Endpunkt wird gemessen, nicht abgefragt: getBoundingClientRect liefert
  // für ein Segment der Länge null 0 × 0, ganz gleich was gemalt wird. Gezählt
  // werden deshalb die gefärbten Pixel eines Bildschirmfotos, während der Strich
  // kurz ausgeblendet ist.
  //
  // Ein runder Punkt ist so breit wie hoch. Wird die Strichstärke waagerecht
  // mitgezogen — der Zustand vor dieser Änderung, damals ein <circle> —, ist er
  // es nicht: gemessen 16 × 10 statt 8 × 8.
  await seite.evaluate(() => {
    document.querySelector("svg.spark path.line").style.display = "none";
  });
  strich.punkt = await messePunkt(seite);
  await seite.evaluate(() => {
    document.querySelector("svg.spark path.line").style.removeProperty("display");
  });

  // --- 2. Der Messwert unter dem Zeiger --------------------------------------
  const kasten = await seite.locator(".sparkbox").first().boundingBox();
  await seite.mouse.move(kasten.x + kasten.width * 0.5, kasten.y + kasten.height / 2);
  await seite.waitForSelector(".sparktip:not(.aus)", { timeout: 2000 });
  const mitte = await messeKasten(seite);

  // Am linken Rand darf der Kasten nicht aus der Kachel ragen.
  await seite.mouse.move(kasten.x + 1, kasten.y + kasten.height / 2);
  await seite.waitForTimeout(50);
  const links = await messeKasten(seite);

  // Und er verschwindet wieder, wenn der Zeiger die Kachel verlässt.
  await seite.mouse.move(kasten.x + kasten.width / 2, kasten.y - 80);
  await seite.waitForTimeout(50);
  const nachher = await seite.evaluate(
    () => document.querySelector(".sparktip").classList.contains("aus"),
  );

  // --- 3. Die Dateisystemliste ----------------------------------------------
  const zuGeklappt = await seite.locator("tr.fs-sub").first().isVisible();
  await seite.locator("label.fs-mehr").first().click();
  await seite.waitForTimeout(50);
  const aufGeklappt = await seite.locator("tr.fs-sub").first().isVisible();
  const unterzeile = (await seite.locator("tr.fs-sub").first().innerText())
    .replace(/\s+/g, " ")
    .trim();
  const anzahl = await seite.locator("tr.fs-sub:visible").count();

  // Die Netzwerkkachel nennt die Schnittstelle mit der Standardroute.
  const netz = (
    await seite
      .locator(".tele", { has: seite.locator(".k", { hasText: "Netzwerk" }) })
      .innerText()
  )
    .replace(/\s+/g, " ")
    .trim();

  // Mit ASYLUM_E2E_SHOTS liegen die Bilder danach zum Ansehen da: Messwerte
  // sagen, dass es stimmt, ein Bild sagt, wie es aussieht.
  if (process.env.ASYLUM_E2E_SHOTS) {
    const ziel = process.env.ASYLUM_E2E_SHOTS;
    await seite.locator("section.telemetrie").screenshot({ path: ziel + "/telemetrie.png" });
    await seite.locator("section.fs").screenshot({ path: ziel + "/dateisysteme.png" });
    await seite.mouse.move(kasten.x + kasten.width * 0.62, kasten.y + kasten.height / 2);
    await seite.waitForTimeout(50);
    await seite.locator("div.tele").first().screenshot({ path: ziel + "/messwert.png" });
    await seite.setViewportSize({ width: 414, height: 900 });
    await seite.waitForTimeout(100);
    await seite.locator("section.fs").screenshot({ path: ziel + "/dateisysteme-schmal.png" });
  }

  console.log(
    JSON.stringify({
      verstoesse,
      strich,
      tip: { mitte, links, nachherVersteckt: nachher },
      dateisysteme: { zuGeklappt, aufGeklappt, anzahl, unterzeile },
      netz,
    }),
  );

  await browser.close();
}

// messePunkt fotografiert die Sparkline und vermisst den gefärbten Bereich.
async function messePunkt(seite) {
  const bild = (await seite.locator("svg.spark").first().screenshot()).toString("base64");
  return await seite.evaluate(async (b64) => {
    const bild = new Image();
    bild.src = "data:image/png;base64," + b64;
    await bild.decode();
    const c = document.createElement("canvas");
    c.width = bild.width;
    c.height = bild.height;
    const ctx = c.getContext("2d");
    ctx.drawImage(bild, 0, 0);
    const d = ctx.getImageData(0, 0, c.width, c.height).data;

    // Der Grund der Kachel ist hell (oder im Dunkelmodus dunkel); gesucht ist
    // alles, was sich davon deutlich abhebt. Bezug ist die linke obere Ecke.
    const grund = [d[0], d[1], d[2]];
    let anzahl = 0,
      minX = 1e9,
      maxX = -1,
      minY = 1e9,
      maxY = -1;
    for (let y = 0; y < c.height; y++) {
      for (let x = 0; x < c.width; x++) {
        const i = (y * c.width + x) * 4;
        const abstand =
          Math.abs(d[i] - grund[0]) + Math.abs(d[i + 1] - grund[1]) + Math.abs(d[i + 2] - grund[2]);
        if (abstand > 90) {
          anzahl++;
          minX = Math.min(minX, x);
          maxX = Math.max(maxX, x);
          minY = Math.min(minY, y);
          maxY = Math.max(maxY, y);
        }
      }
    }
    if (anzahl === 0) {
      return { anzahl: 0, breite: 0, hoehe: 0 };
    }
    return { anzahl, breite: maxX - minX + 1, hoehe: maxY - minY + 1 };
  }, bild);
}

async function messeKasten(seite) {
  return await seite.evaluate(() => {
    const box = document.querySelector(".sparkbox");
    const tip = box.querySelector(".sparktip");
    const r = tip.getBoundingClientRect();
    const b = box.getBoundingClientRect();
    return {
      text: tip.innerText.replace(/\s+/g, " ").trim(),
      wert: tip.querySelector("b").textContent,
      zeit: tip.querySelector("span").textContent,
      sichtbar: !tip.classList.contains("aus"),
      ueberstandLinks: Math.round(b.left - r.left),
      ueberstandRechts: Math.round(r.right - b.right),
      fuehrung: box.querySelector("path.fuehrung").getAttribute("d") || "",
    };
  });
}

main().catch((err) => {
  console.error(err);
  console.error("Konsole:\n" + gesammelt.join("\n"));
  process.exit(1);
});
