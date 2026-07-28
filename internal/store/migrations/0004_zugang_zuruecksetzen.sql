-- Wechselzwang nach einem zurückgesetzten Passwort.
--
-- Setzt der Owner das Passwort eines fremden Kontos zurück, kennt er es für
-- einen Moment. Das Einmalpasswort soll deshalb genau eine Anmeldung tragen und
-- danach ersetzt werden müssen. Dieses Flag ist der Zustand dazwischen: Ein
-- Konto mit 1 kommt bis zur Wechselseite und nicht weiter.
--
-- Voreinstellung 0 — bestehende Konten sind unberührt, und jede Passwortsetzung
-- durch den Inhaber selbst löscht das Flag wieder.
ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0;
