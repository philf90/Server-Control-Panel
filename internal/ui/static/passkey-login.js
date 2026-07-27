// passkey-login.js — die Anmeldung mit einem Passkey auf der Login-Seite.
//
// Der Knopf steht neben dem gewöhnlichen „Anmelden": Benutzername und Passwort
// stammen aus demselben Formular. Der erste Schritt (/login/passkey/begin)
// prüft das Passwort und liefert die Assertion-Optionen, der zweite
// (/login/passkey/finish) die Antwort des Authenticators. Ohne WebAuthn bleibt
// der Knopf verborgen und der Code-Weg der einzige.
(function () {
  "use strict";

  var btn = document.getElementById("passkey-login");
  if (!btn || !window.PublicKeyCredential || !navigator.credentials) {
    return;
  }
  btn.hidden = false;

  var status = document.getElementById("pk-login-status");

  function b64urlToBuf(s) {
    s = s.replace(/-/g, "+").replace(/_/g, "/");
    while (s.length % 4) {
      s += "=";
    }
    var bin = atob(s);
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) {
      buf[i] = bin.charCodeAt(i);
    }
    return buf.buffer;
  }

  function bufToB64url(buf) {
    var bytes = new Uint8Array(buf);
    var s = "";
    for (var i = 0; i < bytes.length; i++) {
      s += String.fromCharCode(bytes[i]);
    }
    return btoa(s).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  }

  function say(msg, kind) {
    status.hidden = false;
    status.textContent = msg;
    status.className = kind === "error" ? "hint warn-text" : "hint";
  }

  function form_encode(obj) {
    return Object.keys(obj)
      .map(function (k) {
        return encodeURIComponent(k) + "=" + encodeURIComponent(obj[k]);
      })
      .join("&");
  }

  function post(url, fields) {
    return fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: form_encode(fields),
    });
  }

  btn.addEventListener("click", function () {
    var username = document.getElementById("username").value;
    var password = document.getElementById("password").value;
    say("Bestätige die Anmeldung auf deinem Gerät …");

    post("/login/passkey/begin", { username: username, password: password })
      .then(function (r) {
        return r.json().then(function (body) {
          if (!r.ok) {
            throw new Error(body.error || "Die Anmeldung ließ sich nicht beginnen.");
          }
          return body;
        });
      })
      .then(function (body) {
        var opt = body.publicKey;
        opt.challenge = b64urlToBuf(opt.challenge);
        if (opt.allowCredentials) {
          opt.allowCredentials = opt.allowCredentials.map(function (c) {
            return { id: b64urlToBuf(c.id), type: c.type, transports: c.transports };
          });
        }
        return navigator.credentials.get({ publicKey: opt }).then(function (cred) {
          var payload = {
            id: cred.id,
            rawId: bufToB64url(cred.rawId),
            type: cred.type,
            clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {},
            response: {
              authenticatorData: bufToB64url(cred.response.authenticatorData),
              clientDataJSON: bufToB64url(cred.response.clientDataJSON),
              signature: bufToB64url(cred.response.signature),
              userHandle: cred.response.userHandle ? bufToB64url(cred.response.userHandle) : null,
            },
          };
          return post("/login/passkey/finish", { credential: JSON.stringify(payload) });
        });
      })
      .then(function (r) {
        return r.json().then(function (body) {
          if (!r.ok) {
            throw new Error(body.error || "Die Anmeldung wurde nicht angenommen.");
          }
          window.location = body.redirect || "/";
        });
      })
      .catch(function (err) {
        if (err && err.name === "NotAllowedError") {
          say("Abgebrochen.", "error");
        } else {
          say((err && err.message) || "Es ist ein Fehler aufgetreten.", "error");
        }
      });
  });
})();
