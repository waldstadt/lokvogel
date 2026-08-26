# Fullservice DJ Homepage — lauschgift.net

Neuaufbau der DJ-Lauschgift-Webseite mit umfangreichem Backoffice:
CRM, Kommunikations-Timeline, Buchungen, Angebote, Rechnungen
(inkl. Abschlags-/Schlussrechnungen), Technik-Verleih und Statistiken —
**komplett self-hosted auf dem eigenen Webspace** (läuft auf All-Inkl &
jedem PHP-8-Hosting), keine externen Dienste, keine laufenden Abokosten.
Orientierung: bookitup, aber mit Homepage-Integration und Technik-Logistik.

## Struktur

```
fullservice-dj-homepage/
└── webroot/              ← kompletten Inhalt per FTP hochladen
    ├── index.html        Öffentliche Homepage (Hero, Über mich, Pakete,
    │                     Preise, Technik mieten, FAQ, Anfrage-Formular)
    ├── admin.html        Backoffice (Login, Dashboard, Anfragen, CRM,
    │                     Buchungen+Kalender, Angebote, Rechnungen,
    │                     Technik, Statistik, Website-CMS, Einstellungen)
    └── api.php           Backend: eine PHP-Datei mit SQLite-Datenbank,
                          Login, REST-API und Bild-Upload
```

Beim ersten Aufruf legt `api.php` selbstständig an:
- `data/dj.sqlite` — die Datenbank inkl. Startinhalten (per `.htaccess` gegen
  Direktzugriff gesperrt)
- `uploads/` — hochgeladene Website-Fotos

## Installation (auf All-Inkl o. ä., ~5 Minuten)

1. **Hochladen:** Inhalt von `webroot/` per FTP in das Zielverzeichnis der
   Domain legen (z. B. `/lauschgift.net/`). PHP 8.x im KAS aktivieren,
   falls nicht ohnehin Standard.
2. **Admin-Konto anlegen:** `https://deine-domain/admin.html` aufrufen und
   mit Wunsch-E-Mail + Passwort (min. 8 Zeichen) anmelden — **die erste
   Anmeldung legt das Konto an.** Deshalb direkt nach dem Upload machen.
3. Fertig. Homepage läuft unter `index.html`, alle Inhalte pflegst du im
   Backoffice unter „Inhalte“.

**Backup:** Zwei Wege:
1. **Automatisch (empfohlen):** Im Backoffice unter Einstellungen →
   Datensicherung steht eine Cron-URL. Bei All-Inkl (KAS → Tools →
   Cronjobs) einen täglichen Cronjob auf diese URL anlegen — dann landet
   jede Nacht ein komprimierter Datenbank-Snapshot in `data/backups/`
   (die letzten 14 werden behalten, ältere automatisch gelöscht).
   Regelmäßig ein Backup per Klick herunterladen (externe Kopie!).
2. **Manuell:** `data/dj.sqlite` und den `uploads/`-Ordner per FTP
   herunterladen — das ist der komplette Datenbestand.

**GoBD:** Rechnungen sind ab dem Status „versendet" festgeschrieben —
Inhalte lassen sich dann weder ändern noch löschen (serverseitig
erzwungen). Änderungen laufen über Korrekturrechnung oder Storno; jede
Anlage und jeder Statuswechsel steht im Änderungsprotokoll der Rechnung.

## Gemeinsame Weiterentwicklung & Live-Stellen (ohne FTP)

Der Server aktualisiert sich selbst vom GitHub-Branch **`live`**:

1. Änderungswunsch an Claude geben (neue Session auf diesem Repo genügt —
   CONTENT-BACKLOG.md und README halten den Kontext).
2. Claude baut und testet auf dem Entwicklungs-Branch.
3. Live stellen = auf den Branch `live` pushen/mergen.
4. Der Server holt sich den Stand selbst: automatisch per All-Inkl-Cronjob
   auf die `deploy.php?key=…&action=run`-URL (z. B. alle 15 Minuten) oder
   sofort per Klick im Backoffice (Einstellungen → Website-Updates).

`deploy.php` ersetzt nur Code-Dateien — `data/` (Datenbank, Backups,
Ausweis-/Check-Fotos) und `uploads/` bleiben unberührt. Vor jedem Update:
Datenbank-Snapshot + Kopie der ersetzten Dateien nach `data/deploy-backup/`
(die letzten 5 bleiben). Datenbank-Migrationen laufen beim ersten Aufruf der
neuen Version automatisch (PRAGMA user_version).

Einrichtung einmalig im Backoffice: Repository (`waldstadt/lokvogel`),
Branch `live` und ein Fine-grained GitHub-Token (nur dieses Repo, nur
„Contents: Read"). Bei öffentlichem Repository geht es auch ohne Token.

## Zugriffsmodell / Datenschutz

- Ohne Login liefert die API **nur** Website-Inhalte (Texte, Pakete, FAQ,
  öffentliche Verleih-Artikel) und nimmt neue Anfragen entgegen.
  Kundendaten, Buchungen und Rechnungen sind ohne Login nicht abrufbar.
- Backoffice-Login: Passwort-Hash (bcrypt) serverseitig, Token 12 h gültig.
- Alle Daten liegen ausschließlich auf deinem eigenen Webspace (DSGVO:
  kein Drittlands-Transfer, kein externer Auftragsverarbeiter außer dem
  Hoster, mit dem ohnehin ein AV-Vertrag besteht).

## Funktionsumfang v1

- **Anfragen-Inbox:** Homepage-Formular → Backoffice; ein Klick übernimmt
  die Anfrage als Kunde + Buchung + Timeline-Eintrag ins CRM.
- **CRM:** Kunden/Leads mit Tags, Quellen, Suche; pro Kunde eine
  Kommunikations-Timeline (E-Mail / WhatsApp / Telefonat / Treffen /
  Notiz, manuell protokolliert) mit Wiedervorlagen ("nachfassen am"),
  die im Dashboard erscheinen.
- **Buchungen:** Liste + Monatskalender (DJ- und Technik-Aufträge farblich
  getrennt), mehrtägige Vermietungen, Gage, Location, Notizen.
- **Technik-Verleih:** Artikelstamm mit Bestand, Tagespreis und
  Folgetage-Prozentsatz; Zuordnung zu Aufträgen mit
  **Verfügbarkeitsprüfung über den Zeitraum** (Konflikt-Warnung),
  Raus/Zurück-Checkliste, "heute unterwegs"-Anzeige.
- **Angebote & Rechnungen:** Nummernkreise (konfigurierbares Präfix +
  Jahr + laufende Nummer), Positionen, USt. oder §19-Kleinunternehmer,
  Statusfluss (Entwurf → versendet → angenommen/bezahlt …),
  Abschlagsrechnung per Klick (50 % vorbelegt), Schlussrechnung mit
  Verrechnung bezahlter Abschläge, Druck-/PDF-Ansicht mit Briefkopf und
  Bankverbindung. Aus einer Buchung werden Angebot/Rechnung inklusive
  DJ-Gage und zugeordneter Technik automatisch vorbefüllt.
- **Statistik:** Umsatz pro Monat/Jahr, Angebots-Annahmequote,
  Ø-Rechnungswert, Aufträge nach Anlass, Anfrage-Quellen.
- **Website-CMS:** Alle Texte, Fotos (Upload auf den eigenen Server),
  Leistungspakete, FAQ, Verleih-Artikel, Kontaktdaten, SEO und
  Farbschema (Presets + freie Farbwahl) — Änderungen sind sofort live.

## Bewusst noch nicht in v1 (Ausbau-Ideen)

- E-Mail-/WhatsApp-API-Anbindung (Kommunikation wird v1 manuell protokolliert)
- GoBD-Feinschliff (Unveränderbarkeit versendeter Belege, Audit-Log)
- Online-Angebotsannahme durch Kunden per Link
- iCal-/Google-Kalender-Sync
- Multi-Tenant-Fähigkeit für den späteren Verkauf an andere DJs
