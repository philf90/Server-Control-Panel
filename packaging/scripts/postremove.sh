#!/bin/sh
#
# Beim vollständigen Entfernen die Include-Datei des Fernzugriffs mitnehmen.
#
# **Der Anlass ist eine Datei, die das Paket nicht kennt.** `srvpanel db
# --remote=on` schreibt über den Agenten `60-srvpanel.cnf` in das
# Include-Verzeichnis des Datenbankservers (docs/36 §12). Sie gehört nicht zum
# Paket — dpkg räumt sie also nicht weg —, und was sie bewirkt, ist ein
# Datenbankserver, der auf einer erreichbaren Adresse horcht. Ein entferntes
# Panel, das einen offenen Port hinterlässt, ist genau die Sorte Rest, die
# docs/35 an zwölf Zertifikatsverzeichnissen freigelegt hat, nur mit
# schlimmerer Folge.
#
# **Nur bei `purge`, nicht bei `remove`.** Dieselbe Trennung wie bei der
# PHP-Quelle: Ein `apt remove` soll das Paket loswerden. Wer dabei die
# Horchadresse eines laufenden Datenbankservers ändert, greift in einen Dienst
# ein, den er gerade nicht mehr verwaltet.
#
# **Und der Neustart passiert hier NICHT.** Die Datei ist weg, der laufende
# Server horcht bis zu seinem nächsten Start weiter — er trägt womöglich
# Anwendungen, die mit diesem Panel nichts zu tun haben, und ein Neustart
# mitten in einem `apt purge` ist eine Unterbrechung, die niemand angesagt hat.
# Deshalb steht der Befehl dafür in der Ausgabe, gut sichtbar: Ein Rest, den
# man kennt, ist etwas anderes als einer, den niemand nennt.
set -eu

if [ "${1:-}" != "purge" ]; then
    exit 0
fi

entfernt=""

for verzeichnis in /etc/mysql/mariadb.conf.d /etc/mysql/mysql.conf.d; do
    datei="${verzeichnis}/60-srvpanel.cnf"

    if [ -f "${datei}" ]; then
        rm -f "${datei}"
        entfernt="${entfernt} ${datei}"
    fi
done

if [ -n "${entfernt}" ]; then
    echo "srvpanel: Fernzugriff-Konfiguration entfernt:${entfernt}"
    echo "srvpanel: Der Datenbankserver horcht bis zu seinem nächsten Start weiter"
    echo "srvpanel: auf der bisherigen Adresse. Zum Übernehmen:"
    echo "srvpanel:     systemctl restart mariadb.service"
fi

exit 0
