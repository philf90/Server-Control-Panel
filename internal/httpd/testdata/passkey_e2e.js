// passkey_e2e.js — treibt einen echten Browser mit virtuellem Authenticator
// durch den vollen Passkey-Durchlauf: erst einen Passkey im Konto registrieren,
// dann ausloggen und sich damit anmelden. Aufgerufen aus TestPasskeyBrowserFlow.
//
// argv: baseURL username password sessionCookieValue chromiumPath
const { chromium } = require("playwright");

const [, , baseURL, username, password, sessionCookie, chromiumPath] = process.argv;

(async () => {
  const browser = await chromium.launch({ executablePath: chromiumPath });
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await ctx.newPage();

  // Virtueller Authenticator, der Anwesenheit und Nutzerprüfung selbst bestätigt.
  const cdp = await ctx.newCDPSession(page);
  await cdp.send("WebAuthn.enable");
  await cdp.send("WebAuthn.addVirtualAuthenticator", {
    options: {
      protocol: "ctap2",
      transport: "internal",
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });

  // Angemeldet starten (Sitzungscookie aus dem Go-Test), um im Konto einen
  // Passkey zu registrieren.
  await ctx.addCookies([
    {
      name: "asylum_session",
      value: sessionCookie,
      domain: "localhost",
      path: "/",
      secure: true,
      httpOnly: true,
      sameSite: "Strict",
    },
  ]);

  await page.goto(baseURL + "/account");
  await page.fill("#pk-label", "E2E-Key");
  await page.fill("#pk-pass", password);
  await page.click("#passkey-add button");
  // Bei Erfolg lädt die Seite neu und zeigt den neuen Schlüssel.
  await page.waitForSelector("text=E2E-Key", { timeout: 10000 });

  // Abmelden (Cookie weg) und mit dem Passkey anmelden.
  await ctx.clearCookies();
  await page.goto(baseURL + "/login");
  await page.fill("#username", username);
  await page.fill("#password", password);
  await page.click("#passkey-login");
  await page.waitForURL(baseURL + "/", { timeout: 10000 });

  console.log("E2E-OK");
  await browser.close();
})().catch((e) => {
  console.error("E2E-ERR " + (e && e.message ? e.message : e));
  process.exit(1);
});
