# Arbeitsregeln für dieses Repo (und Vorlage für alle Projekte von Markus)

Diese Regeln gelten dauerhaft — bei neuen Projekten diese Datei als Erstes
mit übernehmen.

## Design & Grafik

- **Keine Emojis** in Kundentexten, Oberflächen, Mails oder Dokumenten.
- Symbole immer als **schlichte Inline-SVG-Icons im Linienstil** (Feather-
  Art: `stroke="currentColor"`, `fill="none"`, `stroke-width="2"`, runde
  Kappen, 24er-viewBox, Klasse `.ic`). Sie erben die Textfarbe, brauchen
  keine externen Dateien und dienen der schnellen Wiedererkennung.
- Icon-Stil projektweit einheitlich halten; ein Icon pro Bedeutung.

## Schriften

- **Schriften immer fest einbetten (self-hosted)** — nie von Google Fonts
  oder anderen Fremd-Hosts laden (DSGVO, Abmahnrisiko, Performance).
- Bevorzugt **Variable Fonts** (eine woff2-Datei je Familie, `font-weight`
  als Bereich in `@font-face`), abgelegt unter `fonts/` mit zentraler
  `fonts.css`.
- Schriften müssen **immer gut lesbar** sein: klare Sans für Fließtext,
  ausreichende Größen und Zeilenabstand; Zierschriften höchstens für
  Überschriften und dann als wählbarer Stil.

## Texte

- Ton: persönlich, locker, professionell — Prüffrage: „Würde Markus das
  am Telefon so sagen?"
- Keine wortgleichen Textbausteine auf mehreren Seiten, keine gehäuften
  Dreier-Aufzählungen/Parallelismen, Gedankenstriche sparsam und immer
  als Halbgeviertstrich („–", nie „—").

## Datenschutz

- Keine externen Dienste ohne ausdrückliche Entscheidung: keine CDNs,
  kein Tracking Dritter, keine Cookies für Werbung/Tracking.
- Statistik nur anonym und self-hosted (kein Cookie-Banner nötig, solange
  das so bleibt).

## Projekt-Spezifisches (fullservice-dj-homepage)

- Deployment: Push auf Branch `live` = Live-Stellung (Server zieht selbst
  über `deploy.php`). Auf `live` nur pushen, wenn Markus es ausdrücklich
  sagt.
- Vor `git add`: `webroot/data` und `webroot/uploads` löschen (lokale
  Testdaten gehören nicht ins Repo).
- Fachliche Regeln und Backlog stehen in
  `fullservice-dj-homepage/CONTENT-BACKLOG.md`.
