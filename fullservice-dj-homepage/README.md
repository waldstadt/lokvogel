# Fullservice DJ Homepage — lauschgift.net

Neuaufbau der DJ-Lauschgift-Webseite mit umfangreichem Backoffice:
CRM, Kommunikations-Timeline, Buchungen, Angebote, Rechnungen
(inkl. Abschlags-/Schlussrechnungen), Technik-Verleih und Statistiken —
komplett selbst gehostet, ohne laufende Software-Abokosten (Orientierung: bookitup,
aber mit Homepage-Integration und Technik-Logistik).

## Struktur

```
fullservice-dj-homepage/
├── public/index.html     Öffentliche Homepage (Hero, Über mich, Pakete,
│                         Preise, Technik mieten, FAQ, Anfrage-Formular)
├── admin/admin.html      Backoffice (Login, Dashboard, Anfragen, CRM,
│                         Buchungen+Kalender, Angebote, Rechnungen,
│                         Technik, Statistik, Website-CMS, Einstellungen)
└── supabase/schema.sql   Datenbankschema inkl. RLS-Policies & Startdaten
```

Beide HTML-Dateien sind eigenständig — kein Build-Prozess, kein Framework
(gleiche Philosophie wie lokvogel.de).

## Setup (einmalig, ~15 Minuten)

1. **Supabase-Projekt anlegen** auf [supabase.com](https://supabase.com)
   — Region **EU (Frankfurt)** wählen (DSGVO).
2. **Schema einspielen:** SQL Editor öffnen → Inhalt von
   `supabase/schema.sql` einfügen → Run. Legt alle Tabellen, Policies,
   den Storage-Bucket `media` und die Start-Inhalte an.
3. **Admin-Nutzer anlegen:** Dashboard → Authentication → Users →
   "Add user" (E-Mail + Passwort). Unter Authentication → Providers →
   Email die Registrierung ("Enable sign ups") **deaktivieren**, damit
   sich niemand sonst registrieren kann.
4. **Keys eintragen:** Dashboard → Settings → API. `Project URL` und
   `anon/publishable key` jeweils oben in `public/index.html` und
   `admin/admin.html` bei `SUPA_URL` / `SUPA_KEY` eintragen.
5. **Hosting:** Beide Dateien auf beliebigen Static-Host legen
   (z. B. bestehendes Webhosting, Netlify, Cloudflare Pages).
   `public/index.html` → lauschgift.net, `admin/admin.html` z. B. unter
   `/admin/` (die URL genügt als Schutz nicht — der Login schon, da alle
   Daten serverseitig per Row Level Security nur mit Login lesbar sind).

## Zugriffsmodell / Datenschutz

- Die Homepage (anon key) kann **nur** Website-Inhalte lesen und neue
  Anfragen **einfügen** — Kundendaten, Buchungen und Rechnungen sind ohne
  Login serverseitig nicht abrufbar (Row Level Security).
- Das Backoffice arbeitet nur mit gültigem Supabase-Auth-Login.
- Fotos liegen im öffentlichen Storage-Bucket `media` (nur für
  Website-Bilder gedacht — keine Kundendokumente dort ablegen).

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
- **Website-CMS:** Alle Texte, Fotos (Upload in Supabase Storage),
  Leistungspakete, FAQ, Verleih-Artikel, Kontaktdaten, SEO und
  Farbschema (Presets + freie Farbwahl) — Änderungen sind sofort live.

## Bewusst noch nicht in v1 (Ausbau-Ideen)

- E-Mail-/WhatsApp-API-Anbindung (Kommunikation wird v1 manuell protokolliert)
- GoBD-Feinschliff (Unveränderbarkeit versendeter Belege, Audit-Log)
- Online-Angebotsannahme durch Kunden per Link
- iCal-/Google-Kalender-Sync
- Multi-Tenant-Fähigkeit für den späteren Verkauf an andere DJs
