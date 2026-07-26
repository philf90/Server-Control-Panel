# Was ändert sich

<!-- Kurz: was tut dieser Beitrag, und warum. Der Diff steht schon daneben —
interessant ist die Überlegung dahinter. -->

Behebt #

## Warum so und nicht anders

<!-- Falls es Alternativen gab: welche, und warum diese hier. Wenn es
offensichtlich war, kann der Abschnitt weg. -->

## Wie geprüft

<!-- Was tatsächlich gelaufen ist. "Tests grün" reicht, wenn es Tests gibt;
bei allem, was Systemzugriff braucht, bitte dazuschreiben, wogegen geprüft
wurde — echtes systemd, Container, aufgezeichnete Ausgabe. -->

## Abhaken

- [ ] `make test` und `make lint` laufen durch
- [ ] Neue oder geänderte Logik hat Tests, auch für die Abweisungsfälle
- [ ] Tests laufen auch als unprivilegiertes Konto (als root scheitern
      Dateirechte nicht)
- [ ] Kommentare und Fehlermeldungen sind deutsch, Bezeichner englisch
- [ ] Commits sind mit `git commit -s` signiert (DCO)
- [ ] Keine neue direkte Abhängigkeit — oder im Text begründet
- [ ] Berührt `internal/auth`, `internal/privops` oder `internal/update`:
      <!-- ja/nein — falls ja, rechnen Sie mit einer genaueren Durchsicht -->

<!-- Eine Sicherheitslücke gehört nicht in einen Pull Request, sondern in
einen privaten Kanal: siehe SECURITY.md. -->
