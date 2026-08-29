<?php
/**
 * Fullservice DJ Homepage – Backend-API (Supabase-Ersatz für Shared Hosting)
 * ---------------------------------------------------------------------------
 * Eine Datei, SQLite-Datenbank, keine Abhängigkeiten. Läuft auf jedem
 * PHP-8-Hosting (z. B. All-Inkl). Die Datenbank inkl. Startdaten legt sich
 * beim ersten Aufruf selbst an (Verzeichnis ./data, per .htaccess gesperrt).
 *
 * Endpunkte:
 *   POST api.php/auth/login          {email,password} -> {access_token}
 *                                    (erster Login legt den Admin-Account an)
 *   GET/POST/PATCH/DELETE api.php/rest/{tabelle}?col=eq.X&order=col.desc…
 *                                    (PostgREST-Teilmenge, wie vom Frontend genutzt)
 *   POST api.php/storage/{dateiname} (Bild-Upload, nur mit Login) -> {url}
 *
 * Öffentlich ohne Login: Website-Inhalte lesen, Anfragen einreichen.
 * Alles andere nur mit gültigem Token.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

const DATA_DIR   = __DIR__ . '/data';
const UPLOAD_DIR = __DIR__ . '/uploads';
const DB_FILE    = DATA_DIR . '/dj.sqlite';
const TOKEN_TTL  = 60 * 60 * 12; // 12 h
const MAX_UPLOAD = 8 * 1024 * 1024;
const SCHEMA_VERSION = 47;   // frisches Schema in migrate() muss diesem Stand entsprechen

/* Spalten, die als JSON bzw. Bool behandelt werden */
const JSON_COLS = [
  'settings' => ['value'], 'site_content' => ['value'], 'content_versions' => ['value'],
  'packages' => ['features'],
  'form_templates' => ['fields'], 'forms' => ['fields','answers'],
  'products' => ['bundle'], 'bookings' => ['rider', 'customer_notes'], 'rental_contracts' => ['snapshot'],
  'customers' => ['tags', 'tech_check'],
  'equipment' => ['addon_ids', 'images', 'fits_ids'],
];
const BOOL_COLS = [
  'packages' => ['public'], 'faq' => ['public'], 'locations' => ['public','image_approved','highlight'], 'friends' => ['public'],
  'workshop_events' => ['public'],
  'upsells' => ['active','show_portal'], 'reviews' => ['public'], 'products' => ['active'],
  'bookings' => ['review_requested','open_ended'],
  'equipment' => ['public','rentable','own_rig','on_request'],
  'equipment_sets' => ['public'],
  'booking_equipment' => ['out_done','back_done'],
  'communications' => ['followup_done'],
  'documents' => ['is_small_business'],
];
const TABLES = ['settings','site_content','packages','faq','equipment','locations','inquiries',
  'customers','communications','bookings','booking_equipment','documents','document_items','email_templates',
  'doc_events','form_templates','forms','upsells','reviews','products','partners','rental_contracts','friends',
  'workshop_events','workshop_signups','doc_audit','customer_files','newsletter','equipment_sets','equipment_set_items',
  'calendar_blocks','content_versions'];
const PK = ['settings' => 'key', 'site_content' => 'key'];   // sonst: id

/* Öffentliche Zugriffe (ohne Login) */
const PUBLIC_READ   = ['site_content','packages','faq','equipment','locations','reviews','friends','equipment_sets','equipment_set_items'];
const INQUIRY_FIELDS = ['name','email','phone','event_type','event_date','location','guests','message'];

header('Content-Type: application/json; charset=utf-8');

function out($data, int $code = 200): never {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }
function uuid(): string {
  $b = random_bytes(16);
  $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

/* ---------- DB & Migration ---------- */
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $init = !file_exists(DB_FILE);
  if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
  $ht = DATA_DIR . '/.htaccess';
  if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");
  $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
  if ($init) { migrate($pdo); $pdo->exec('PRAGMA user_version=' . SCHEMA_VERSION); }
  else upgrade($pdo);
  return $pdo;
}

/* Schema-Upgrades für bereits vorhandene Datenbanken (idempotent) */
function upgrade(PDO $p): void {
  $v = (int)$p->query('PRAGMA user_version')->fetchColumn();
  if ($v >= SCHEMA_VERSION) return;
  if ($v < 2) foreach ([
    "alter table documents add column share_token text",
    "alter table document_items add column note text",
    "create table if not exists doc_events (id text primary key,
      document_id text not null references documents(id) on delete cascade,
      kind text not null, message text, phone text, created_at text, seen integer default 0)",
    "create table if not exists form_templates (id text primary key, sort integer default 0,
      name text not null, intro text, fields text default '[]')",
    "create table if not exists forms (id text primary key, token text unique not null, title text not null,
      intro text, fields text default '[]', answers text, status text default 'offen',
      inquiry_id text, customer_id text, created_at text, submitted_at text)",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) { /* Spalte existiert bereits */ } }
  if ($v < 2 && !(int)$p->query("select count(*) from form_templates")->fetchColumn()) seedFormTemplates($p);
  if ($v < 3) {
    try { $p->exec("create table if not exists upsells (id text primary key, sort integer default 0,
      title text not null, description text, price_net real default 0, occasions text,
      active integer default 1, show_portal integer default 1, created_at text)"); } catch (PDOException $e) {}
    if (!(int)$p->query("select count(*) from upsells")->fetchColumn()) seedUpsells($p);
  }
  if ($v < 4) foreach ([
    "create table if not exists reviews (id text primary key, sort integer default 0, author text not null,
      event_type text, text text not null, rating integer default 5,
      source text default 'google', review_date text, public integer default 1, created_at text)",
    "alter table bookings add column review_requested integer default 0",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
  if ($v < 4) try { $p->prepare("insert into site_content (key,value,updated_at) values ('reviews',?,?)")
    ->execute(['{"google_url":"","djbande_url":"","tagline":""}', now()]); } catch (PDOException $e) {}
  if ($v < 5) {
    foreach ([
      "create table if not exists products (id text primary key, sku text unique, sort integer default 0,
        category text, name text not null, description text, unit text default 'Stk.',
        price_net real, bundle text default '[]', active integer default 1, created_at text)",
      "create table if not exists partners (id text primary key, code text unique, name text not null,
        company text, kind text default 'dj', email text, phone text, status text default 'beantragt',
        notes text, created_at text)",
      "alter table equipment add column partner_rate real",
      "alter table equipment add column addon_id text",
    ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
    if (!(int)$p->query("select count(*) from products")->fetchColumn()) seedProducts($p);
  }
  if ($v < 6) {
    foreach ([
      "alter table documents add column price_mode text default 'netto'",
      "alter table documents add column discount_value real default 0",
      "alter table documents add column discount_type text default 'pct'",
      "alter table document_items add column discount_value real default 0",
      "alter table document_items add column discount_type text default 'pct'",
    ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
    try { $p->prepare("insert into settings (key,value,updated_at) values ('rental_contract',?,?)")
      ->execute([json_encode(['text' => rentalContractDefault()], JSON_UNESCAPED_UNICODE), now()]); } catch (PDOException $e) {}
  }
  if ($v < 7) {
    try { $p->exec("alter table bookings add column rider text"); } catch (PDOException $e) {}
  }
  if ($v < 8) {
    try { $p->exec(rentalContractsDdl()); } catch (PDOException $e) {}
  }
  if ($v < 9) {
    try { $p->exec(friendsDdl()); } catch (PDOException $e) {}
  }
  if ($v < 10) {
    foreach (workshopsDdl() as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
  }
  if ($v < 11) foreach ([
    "alter table workshop_signups add column q_music text",
    "alter table workshop_signups add column q_challenge text",
    "alter table workshop_signups add column q_goal text",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
  if ($v < 12) {
    try { $p->exec("alter table workshop_events add column audience text default ''"); } catch (PDOException $e) {}
  }
  if ($v < 13) foreach ([
    "alter table workshop_signups add column street text",
    "alter table workshop_signups add column zip text",
    "alter table workshop_signups add column city text",
    "alter table workshop_signups add column invoice_id text",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
  if ($v < 14) {
    try { $p->exec(docAuditDdl()); } catch (PDOException $e) {}
    seedExtraTemplates($p);
  }
  if ($v < 15) seedServiceProducts($p);
  if ($v < 16) {
    try { $p->exec("alter table customers add column tech_check text"); } catch (PDOException $e) {}
    seedTechCheckForm($p);
  }
  if ($v < 17) seedServiceProducts($p);
  if ($v < 18) foreach (array_merge([
    "alter table customers add column portal_hash text",
    "alter table customers add column portal_invite text",
    "alter table customers add column portal_invite_expires integer",
    "alter table bookings add column customer_notes text",
  ], portalAccountDdl()) as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
  if ($v < 19) foreach ([
    "alter table documents add column accepted_name text",
    "alter table documents add column accept_signature text",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
  if ($v < 21) upgradeBandeFlow($p);
  if ($v < 22) try {
    $st = $p->query("select id, fields from form_templates where name like 'DJ-Vorauswahl%' limit 1");
    if ($row = $st->fetch()) {
      $fields = json_decode((string)$row['fields'], true) ?: [];
      foreach ($fields as &$f) {
        $l = (string)($f['label'] ?? '');
        if (str_contains($l, 'auf jeden Fall laufen') && !str_contains($l, 'besonders gern'))
          $f['label'] = 'Welche Musik hört ihr besonders gern? (Richtungen, Künstler, Lieblingslieder – was auf jeden Fall laufen soll)';
        if (str_contains($l, 'KEINEN Fall'))
          $f['label'] = 'Und was mögt ihr überhaupt nicht? (darf auf keinen Fall laufen)';
      }
      unset($f);
      $p->prepare('update form_templates set fields = ? where id = ?')
        ->execute([json_encode($fields, JSON_UNESCAPED_UNICODE), $row['id']]);
    }
  } catch (PDOException $e) {}
  if ($v < 24) foreach (statsNewsletterDdl() as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
  if ($v < 25) try {
    /* Datenschutztext um Cookies/Schriftarten/Statistik/Newsletter ergänzen – nur solange er noch der alte Seed ist */
    $st = $p->query("select value from site_content where key='legal'");
    $legal = json_decode((string)$st->fetchColumn(), true);
    if (is_array($legal) && !str_contains((string)($legal['datenschutz'] ?? ''), 'Schriftarten')) {
      $legal['datenschutz'] = datenschutzText();
      $p->prepare("update site_content set value=?, updated_at=? where key='legal'")
        ->execute([json_encode($legal, JSON_UNESCAPED_UNICODE), now()]);
    }
    /* alten Schrift-Wert auf neuen Schlüssel normalisieren */
    $th = json_decode((string)$p->query("select value from site_content where key='theme'")->fetchColumn(), true);
    if (is_array($th) && !in_array($th['font'] ?? '', ['grotesk','outfit','playfair'])) {
      $th['font'] = 'grotesk';
      $p->prepare("update site_content set value=?, updated_at=? where key='theme'")
        ->execute([json_encode($th, JSON_UNESCAPED_UNICODE), now()]);
    }
  } catch (PDOException $e) {}
  if ($v < 26) try {
    /* Technik nicht mehr als "zweites Standbein"/"eigenes Gewerk" framen, sondern als Ergänzung */
    $p->prepare("update site_content set value=?, updated_at=? where key='tech_teaser'")
      ->execute(['{"title":"Lauschgift Veranstaltungstechnik","text":"Ton und Licht gehören für mich untrennbar zum DJ-Sein dazu – deshalb biete ich beides auch unabhängig voneinander an: Technik zum Mieten direkt aus meinem Lager in Hemer, oder mich als Techniker inklusive Equipment, ganz ohne Auflegen. Alle Details dazu auf der Technik-Seite."}', now()]);
  } catch (PDOException $e) {}
  if ($v < 27) try {
    /* Erfundene Technik-/Ausstattungsdetails aus den Location-Texten entfernen (waren teils schlicht falsch,
       z.B. "Holzdecke" bei Neuhaus oder Licht "am Wasser" bei Ufer 39, das 100m entfernt liegt) */
    $safe = [
      'Romantikhotel Neuhaus' => 'Vier-Sterne-Haus mit einem der schönsten Ballsäle der Region. Bis 150 Gäste, meist Hochzeiten und runde Geburtstage.',
      'Ufer 39' => 'Restaurant direkt am Bodensee mit offener Seeterrasse. Bis 130 Gäste, vor allem Hochzeiten und Firmenfeiern.',
      'Wirtshaus Krämer' => 'Rustikale Location mit viel Charakter. Bis 120 Gäste, Hochzeiten und Geburtstage.',
      'Waldenburger Hafen am Biggesee' => 'Naturkulisse direkt am Biggesee, variabel indoor und outdoor. Vor allem Hochzeiten und Sommerfeste.',
      'Gut Kump' => 'Historischer Gutshof mit drei unterschiedlichen Räumen: Festscheune, Saal und Gewölbekeller. Bis 150 Gäste, Hochzeiten und Geburtstage.',
      'Danzturm' => 'Bekannte Eventlocation direkt in meiner Heimatstadt. Hochzeiten und Firmenfeiern.',
      'Gut Bardenhagen' => 'Ehemaliges Trabergestüt mit hellem Arkadensaal für bis zu 200 Gäste und Außentrauungen auf weitläufigem Gelände. Vor allem Hochzeiten.',
      'Stapelskotten' => 'Restaurant an der Aa mit gemütlichem Innenbereich und offener Wasserlage draußen. Hochzeiten, Geburtstage und Firmenfeiern.',
      'Remise by Haus Delecke' => 'Modernisierte Remise, samstags exklusiv für eine Feier buchbar. Hochzeiten und Firmenfeiern.',
      'Speisekammer' => 'Gemütliche Location mit warmer Atmosphäre, Platz für bis zu 80 Gäste. Hochzeiten, Geburtstage und Familienfeiern.',
    ];
    $upd = $p->prepare("update locations set description=? where name=?");
    foreach ($safe as $name => $desc) $upd->execute([$desc, $name]);
  } catch (PDOException $e) {}
  if ($v < 28) try {
    /* Überschrift/Einleitung der Location-Sektion neu als eigener CMS-Eintrag, damit sie im Backoffice pflegbar ist */
    if (!(int)$p->query("select count(*) from site_content where key='loc_section'")->fetchColumn()) {
      $p->prepare("insert into site_content (key,value,updated_at) values ('loc_section',?,?)")
        ->execute(['{"title":"Orte, an denen ich besonders gerne auflege","text":"Deutschlandweit gibt es Locations, mit denen die Zusammenarbeit einfach herausragend läuft – eingespielte Teams, gute Technik-Bedingungen, tolle Räume. Diese Häuser empfehle ich aus voller Überzeugung."}', now()]);
    }
  } catch (PDOException $e) {}
  if ($v < 29) foreach ([
    "alter table locations add column address text",
    "alter table locations add column phone text",
    "alter table locations add column image_source text default 'eigen'",
    "alter table locations add column image_approved integer default 0",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) { /* Spalte existiert bereits */ } }
  if ($v < 29) try {
    /* Bestehende Locations ohne Foto haben nichts zu verstecken - erst neu gesetzte externe Fotos brauchen die Freigabe */
    $p->exec("update locations set image_source='eigen' where image_source is null");
  } catch (PDOException $e) {}
  if ($v < 30) foreach ([
    "alter table equipment add column tier_week_pct real",
    "alter table equipment add column tier_2week_pct real",
    "alter table equipment add column tier_month_pct real",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) { /* Spalte existiert bereits */ } }
  if ($v < 30) try {
    /* Globale Rabattstaffel-Standardwerte in die bestehenden Einstellungen einmischen, ohne Vorhandenes zu überschreiben */
    $st = $p->query("select value from settings where key='defaults'");
    $defs = json_decode((string)$st->fetchColumn() ?: '{}', true) ?: [];
    $defs += ['tier_week_pct' => 30, 'tier_2week_pct' => 20, 'tier_month_pct' => 12];
    $p->prepare("insert into settings (key,value) values ('defaults',?)
      on conflict(key) do update set value=excluded.value")
      ->execute([json_encode($defs, JSON_UNESCAPED_UNICODE)]);
  } catch (PDOException $e) {}
  if ($v < 34) foreach ([
    "alter table equipment add column thomann_url text",
    "alter table equipment add column own_rig integer default 0",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) { /* Spalte existiert bereits */ } }
  if ($v < 35) seedServiceProducts($p);
  if ($v < 36) try { $p->exec("alter table equipment add column day_rate_suggested real"); } catch (PDOException $e) {}
  if ($v < 37) foreach ([
    "alter table equipment add column invoice_file text",
    "alter table equipment add column invoice_name text",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) { /* Spalte existiert bereits */ } }
  if ($v < 38) seedEquipmentCatalog($p);
  if ($v < 39) seedEquipmentCatalog($p);   // neue Artikel nachziehen (idempotent per SKU)
  if ($v < 40) try { $p->exec("create table if not exists calendar_blocks (id text primary key,
    title text not null, start_date text not null, end_date text, note text, created_at text)"); } catch (PDOException $e) {}
  if ($v < 41) foreach ([
    "alter table locations add column highlight integer default 0",
    "create table if not exists content_versions (id text primary key, key text not null,
      label text, value text not null default '{}', created_at text)",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) { /* Spalte existiert bereits */ } }
  if ($v < 42) {
    /* „Lokvogel"-Konzept wird zu „auf Anfrage verfügbar": solche Artikel dürfen in den Tourcase,
       die Bestätigung erfolgt manuell - sie werden nicht durch die Verfügbarkeitsprüfung geblockt.
       own_rig bleibt als interne Altdaten erhalten, wird aber nicht mehr für Anzeige/Logik genutzt. */
    try { $p->exec("alter table equipment add column on_request integer default 0"); } catch (PDOException $e) { /* Spalte existiert bereits */ }
    try { $p->exec("update equipment set on_request = 1 where own_rig = 1"); } catch (PDOException $e) {}
  }
  if ($v < 43) {
    /* Zubehör-Empfehlung von einem auf bis zu fünf Artikel: JSON-Liste addon_ids,
       Altwert addon_id übernehmen. */
    try { $p->exec("alter table equipment add column addon_ids text"); } catch (PDOException $e) {}
    try { $p->exec("update equipment set addon_ids = '[\"' || addon_id || '\"]'
      where addon_ids is null and addon_id is not null and addon_id != ''"); } catch (PDOException $e) {}
  }
  if ($v < 44) {
    /* Mehrere Bilder je Artikel (JSON-Liste), plus Seeburg A2 -> X2 mit neuen Specs. */
    try { $p->exec("alter table equipment add column images text"); } catch (PDOException $e) {}
    try { $p->prepare("update equipment set name = ?, sku = ?, description = ?, thomann_url = ? where sku = ?")
      ->execute(['Seeburg Acoustic Line X2', 'MANUAL-SEEBURG-X2',
        'Kompaktes Koaxial-Topteil (passiv): 8" Tieftöner + 1" Hochtöner koaxial – gleichmäßige Abstrahlung auch außerhalb der Achse. Belastbarkeit 250 W AES / 750 W Peak, 8 Ω, max. SPL 124 dB. Frequenzbereich 70 Hz–20 kHz, Abstrahlwinkel 90°×60°. Multiplex-Gehäuse 375×250×250 mm, 8 kg, 35-mm-Stativflansch, flugfähig.',
        'https://www.thomann.de/de/seeburg_x_2.htm', 'MANUAL-SEEBURG-A2']); } catch (PDOException $e) {}
  }
  if ($v < 45) {
    /* Umgekehrte Zubehör-Beziehung „passend für": z. B. ein Kabel/Stativ gibt an,
       zu welchen Hauptartikeln es passt. Beide Richtungen werden im Frontend gespiegelt. */
    try { $p->exec("alter table equipment add column fits_ids text"); } catch (PDOException $e) {}
  }
  if ($v < 46) {
    /* Mindestabnahmemenge: manche Artikel gibt es nur im Set (z. B. 6er), Preis pro Stück. */
    try { $p->exec("alter table equipment add column min_qty integer default 1"); } catch (PDOException $e) {}
    try { $p->exec("update equipment set min_qty = 6 where name like '%Maxi V2%' or name like '%Neon Tube%'"); } catch (PDOException $e) {}
  }
  if ($v < 47) {
    /* Auswählbares Platzhalter-Icon je Artikel (falls noch kein Foto hinterlegt ist). */
    try { $p->exec("alter table equipment add column placeholder text"); } catch (PDOException $e) {}
  }
  if ($v < 31) try {
    $p->exec("alter table bookings add column billable_days integer");
  } catch (PDOException $e) {}
  if ($v < 32) foreach ([
    "alter table equipment add column image_focal text default '50% 50%'",
    "alter table locations add column image_focal text default '50% 50%'",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) { /* Spalte existiert bereits */ } }
  if ($v < 33) foreach ([
    "alter table equipment add column sku text",
    "alter table documents add column total_override real",
    "alter table bookings add column open_ended integer default 0",
    "alter table products add column addon_sku text",
    "create table if not exists equipment_sets (id text primary key, sort integer default 0,
      name text not null, description text, image_url text, image_focal text default '50% 50%',
      discount_pct real default 5, fixed_price real, public integer default 1, created_at text)",
    "create table if not exists equipment_set_items (id text primary key,
      set_id text not null references equipment_sets(id) on delete cascade,
      equipment_id text not null references equipment(id) on delete restrict, qty integer default 1)",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) { /* Spalte/Tabelle existiert bereits */ } }
  if ($v < 33) try {
    /* Fahrtkosten/Übernachtung: Markus nutzt real 0,50 statt der bisherigen 0,70 sowie eine
       harte Freigrenze (0 € bis 30 km, nicht abgezogen) - Standardwerte entsprechend gesetzt,
       aber nur ergänzt, nicht bereits individuell gesetzte Werte überschrieben. */
    $st = $p->query("select value from settings where key='defaults'");
    $defs = json_decode((string)$st->fetchColumn() ?: '{}', true) ?: [];
    $defs['travel_rate_km'] = 0.50;
    $defs += [
      'travel_free_mode' => 'schwelle', 'travel_roundtrip' => true,
      'overnight_enabled' => true, 'overnight_price' => 0,
      'overnight_threshold_type' => 'zeit', 'overnight_threshold_value' => 60,
    ];
    $p->prepare("insert into settings (key,value) values ('defaults',?)
      on conflict(key) do update set value=excluded.value")
      ->execute([json_encode($defs, JSON_UNESCAPED_UNICODE)]);
  } catch (PDOException $e) {}
  if ($v < 23) try {
    $st = $p->query("select id, fields from form_templates where name like 'DJ-Vorauswahl%' limit 1");
    if ($row = $st->fetch()) {
      $fields = array_values(array_filter(json_decode((string)$row['fields'], true) ?: [], function ($f) {
        $l = (string)($f['label'] ?? '');
        return !str_contains($l, 'Budget') && !str_contains($l, 'freie Trauung');
      }));
      $p->prepare('update form_templates set fields = ?, intro = ? where id = ?')
        ->execute([json_encode($fields, JSON_UNESCAPED_UNICODE),
          'Damit ich euch nicht irgendwelche, sondern wirklich passende DJs vorschlagen kann, beantwortet mir bitte kurz diese Fragen – dauert keine 5 Minuten. Die Vorschläge bekommt ihr danach direkt von mir. Und keine Sorge: Ihr bucht hier noch nichts. Vor einer Buchung führt ihr mit eurem Wunsch-DJ in Ruhe ein persönliches Infogespräch – das solltet ihr auch unbedingt tun. Dort klärt ihr alle Details wie Preis, Ablauf und Technik direkt miteinander.',
          $row['id']]);
    }
  } catch (PDOException $e) {}
  $p->exec('PRAGMA user_version=' . SCHEMA_VERSION);
}

/* Anonyme Reichweiten-Statistik (nur Tag/Seite/Referrer-Domain, keine IPs) + Newsletter mit Double-Opt-in */
function statsNewsletterDdl(): array {
  return [
    "create table if not exists stats_daily (day text not null, page text not null,
      ref text not null default '', views integer not null default 0, primary key (day, page, ref))",
    "create table if not exists newsletter (id text primary key, email text unique not null, name text,
      token text unique, source text, confirmed_at text, unsubscribed_at text, created_at text)",
  ];
}

/* Vermittlungs-Mail „Termin belegt" – eine Quelle für Seed und Migration */
function bandeMailSubject(): string {
  return 'Euer Termin am {datum} – ich habe trotzdem eine Lösung für euch';
}
function bandeMailBody(): string {
  return "Hallo {vorname},

danke für eure Anfrage! Die weniger gute Nachricht zuerst: An eurem Termin am {datum} bin ich leider schon fest gebucht.

Aber ich lasse euch nicht allein suchen. Wenn ihr mögt, empfehle ich euch drei bis fünf Kollegen, die an eurem Termin noch frei sind und richtig gute Arbeit machen – handverlesen, passend zu eurer Feier und komplett kostenlos. Die Vermittlung läuft über meine Partner-Agentur DJ Bande (Münster), bei der ich selbst als DJ im Einsatz bin – ich kenne die Kollegen von echten Veranstaltungen, nicht vom Papier.

Damit meine Vorauswahl passt, füllt kurz diesen Bogen aus (keine 5 Minuten – er fragt auch eure Anschrift und euer Einverständnis zur Weitergabe ab):
{fragebogen}

Zur Transparenz: Für eine Vermittlung erhalte ich eine Aufwandsentschädigung von der Agentur. Für euch kostet das nichts – eure Preise vereinbart ihr direkt mit dem DJ.

Viele Grüße
Markus";
}

/* v20/v21: konsolidierte Vermittlungs-Mail + Anschrift-Feld im Vorauswahl-Bogen */
function upgradeBandeFlow(PDO $p): void {
  seedExtraTemplates($p);
  try { $p->prepare('delete from email_templates where name = ?')
    ->execute(['Termin belegt – DJ-Empfehlung (Partner-Netzwerk)']); } catch (PDOException $e) {}
  try {
    $p->prepare("update email_templates set subject = ?, body = ? where name = 'Termin belegt – DJ-Vermittlung'")
      ->execute([bandeMailSubject(), bandeMailBody()]);
  } catch (PDOException $e) {}
  try {
    $st = $p->query("select id, fields from form_templates where name like 'DJ-Vorauswahl%' limit 1");
    $row = $st->fetch();
    if ($row) {
      $fields = json_decode((string)$row['fields'], true) ?: [];
      $has = false;
      foreach ($fields as $f) if (str_contains((string)($f['label'] ?? ''), 'Anschrift')) { $has = true; break; }
      if (!$has) {
        $pos = 3;
        foreach ($fields as $i => $f) if (str_contains((string)($f['label'] ?? ''), 'Location')) { $pos = $i + 1; break; }
        array_splice($fields, $pos, 0, [['label' => 'Eure vollständige Anschrift (Straße, PLZ, Ort) – wird für die Vermittlung benötigt', 'type' => 'text']]);
        $p->prepare('update form_templates set fields = ? where id = ?')
          ->execute([json_encode($fields, JSON_UNESCAPED_UNICODE), $row['id']]);
      }
    }
  } catch (PDOException $e) {}
}

/* Vorab-Fragebogen für den Technik-Check, nur wenn noch nicht vorhanden */
function seedTechCheckForm(PDO $p): void {
  $name = 'Technik-Check – Vorab-Fragen';
  $c = $p->prepare('select count(*) from form_templates where name = ?');
  $c->execute([$name]);
  if ((int)$c->fetchColumn()) return;
  $fields = [
    ['label' => 'Wofür nutzt ihr die Tontechnik hauptsächlich?', 'type' => 'select',
     'options' => ['Hintergrundmusik', 'Reden & Durchsagen', 'Livemusik', 'Partys/DJ', 'Gemischt']],
    ['label' => 'Wie oft ist die Anlage im Einsatz?', 'type' => 'select',
     'options' => ['Täglich', 'Mehrmals pro Woche', 'Wöchentlich', 'Nur zu Veranstaltungen']],
    ['label' => 'Was muss die Anlage können? (eure Anforderungen)', 'type' => 'textarea'],
    ['label' => 'Wo liegen aktuell die Probleme? (Brummen, Pfeifen, zu leise, unverständlich …)', 'type' => 'textarea'],
    ['label' => 'Was wünscht ihr euch am Ende? (z. B. „Reden versteht man bis hinten", „einfacher bedienbar")', 'type' => 'textarea'],
    ['label' => 'Was ist an Technik vorhanden? (Hersteller/Modelle, so gut ihr es wisst – Fotos gern per Mail)', 'type' => 'textarea'],
    ['label' => 'Wie alt ist die Anlage ungefähr?', 'type' => 'select',
     'options' => ['unter 5 Jahre', '5–10 Jahre', '10–20 Jahre', 'älter/unbekannt']],
    ['label' => 'Wer bedient die Technik normalerweise?', 'type' => 'select',
     'options' => ['Immer dieselbe Person', 'Wechselnde Ehrenamtliche/Mitarbeiter', 'Jeder, der gerade da ist']],
    ['label' => 'Gibt es einen Budgetrahmen für Verbesserungen?', 'type' => 'select',
     'options' => ['bis 500 €', '500–1.500 €', '1.500–5.000 €', 'über 5.000 €', 'noch offen']],
  ];
  $p->prepare('insert into form_templates (id, sort, name, intro, fields) values (?,?,?,?,?)')
    ->execute([uuid(), 10, $name,
      'Damit ich beim Termin direkt loslegen kann, beantwortet mir vorab kurz diese Fragen – dauert keine 5 Minuten. Beim Check vor Ort prüfe ich dann alles durch und ihr bekommt einen schriftlichen Bericht mit klarer Empfehlung.',
      json_encode($fields, JSON_UNESCAPED_UNICODE)]);
}

/* Service-Produkte für Festinstallation & Wartung, nur wenn SKU noch fehlt */
function seedServiceProducts(PDO $p): void {
  $rows = [
    ['WARTUNG-01', 20, 'Service', 'Wartungsvertrag Beschallungsanlage (jährlich)',
     'Jährlicher Funktions-Check, Reinigung, Firmware-Updates und Nachmessen einer fest installierten Anlage – inkl. Kurzbericht für Träger/Vorstand. Verschleiß wird früh erkannt statt am Veranstaltungstag.', 'Jahr', 249.0],
    ['INST-CHECK', 21, 'Service', 'Bestandsaufnahme & Beratung vor Ort',
     'Raum, Nutzung und vorhandene Technik aufnehmen; Empfehlung mit Festpreis-Angebot für die Installation. Wird bei Beauftragung verrechnet.', 'pausch.', 89.0],
    ['TECH-CHECK', 22, 'Service', 'Technik-Check bestehende Anlage',
     'Kompletter Check eurer vorhandenen Tontechnik vor Ort: Funktionsprüfung aller Komponenten, Klang-Bewertung, Einstellungs- und Ergänzungs-Potenzial. Schriftlicher Bericht mit klarer Empfehlung: neu einstellen, ergänzen oder ersetzen. Wird bei Folgeauftrag verrechnet. Gilt für eine Anlage/einen Raum.', 'pausch.', 149.0],
    ['TECH-CHECK-PLUS', 23, 'Service', 'Technik-Check: weitere Anlage / weiterer Raum',
     'Zusätzliche Anlage oder zusätzlicher Raum im selben Termin – inkl. Aufnahme im selben Bericht.', 'Stk.', 79.0],
    ['FLUID-01', 30, 'Verbrauchsmaterial', 'Nebelfluid-Nachfüllung (pro Liter)',
     'Nebelmaschinen sind serienmäßig mit 1 Liter Fluid inklusive. Bei höherem Verbrauch wird die tatsächlich genutzte Menge hierüber nachberechnet.', 'Liter', 5.0],
  ];
  foreach ($rows as [$sku, $s, $cat, $n, $d, $u, $pr]) {
    $c = $p->prepare('select count(*) from products where sku = ?');
    $c->execute([$sku]);
    if (!(int)$c->fetchColumn())
      $p->prepare('insert into products (id,sku,sort,category,name,description,unit,price_net,bundle,active,created_at)
          values (?,?,?,?,?,?,?,?,?,1,?)')
        ->execute([uuid(), $sku, $s, $cat, $n, $d, $u, $pr, '[]', now()]);
  }
}

/* Technik-Bestand, den Markus aus seinen Thomann-Rechnungen durchgibt – SKU = Thomann-Artikelnummer,
   damit ein Artikel bei mehrfacher Migration nicht doppelt angelegt wird. day_rate bleibt 0, bis
   Markus die Empfehlung (day_rate_suggested) im Backoffice bestätigt oder selbst einen Preis einträgt. */
function seedEquipmentCatalog(PDO $p): void {
  $rows = [
    ['363817', 'Mischpult', 'Behringer X AIR XR12',
     '12-Kanal-Digitalmischpult: 4 Mikrofon- (Midas-Preamps) + 8 Line-Eingänge (davon 2 Hi-Z), 2 symmetrische Aux-Ausgänge, Main L/R auf XLR, Kopfhörerausgang. Steuerung komplett per App über integriertes WLAN-Modul, USB für Stereo-WAV-Aufnahme. Maße 333×149×95 mm, Höhe 2 HE, Gewicht 2,4 kg.',
     45.0, 'https://www.thomann.de/de/behringer_x_air_xr12.htm'],
    ['606393', 'DJ-Controller', 'Hercules DJ Control Mix Ultra',
     'Mobiler DJ-Controller mit integriertem Akku (bis zu 10 Std. Laufzeit), drahtlose Anbindung ans Smartphone/Tablet per Bluetooth Low Energy (Android/iOS). 2 virtuelle Decks + Mixer, 2 berührungsgesteuerte Jogwheels, 8 Pads (Hot Cue/Loop/FX/Sampler), EQ/Tempo/Loop/Sample-Bedienelemente. Inkl. Staubschutz-Cover, Audio-Split-Kabel, USB-A/USB-C-Kabel. Maße (BxTxH) 315×174×40 mm, Gewicht 0,85 kg.',
     20.0, 'https://www.thomann.de/de/hercules_dj_control_mix_ultra.htm'],
    ['522044', 'Licht', 'Ape Labs Connect (Grey)',
     'Bluetooth-Interface zur Steuerung von Ape Labs Leuchten per Smartphone-App, Wireless DMX und KNX. Bis zu 4 Connect parallel für 4 DMX-Universen. Integrierter Akku mit bis zu 50 Std. Laufzeit, DMX Ein-/Ausgang XLR 3-Pin, inkl. Netzteil (27 W) und 2× 2,4-GHz-Antenne. Maße 12,5×12,5×4,8 cm, Gewicht 0,72 kg.',
     15.0, 'https://www.thomann.de/de/ape_labs_connect_grey.htm'],
    ['573744', 'Mischpult', 'Allen & Heath CQ20B',
     'Ultrakompakter Bluetooth-Digitalmixer, 96 kHz Signalverarbeitung, Steuerung per App (CQ-MixPad/CQ4You) über integrierten Dualband-Router (2,4/5 GHz). 16 Mikrofon/Line-Vorverstärker (8× XLR + 8× XLR/TRS-Kombibuchse), 2× Stereo-Line-Eingang. Ausgänge: 2× XLR Main, 1× Stereo-Line, 6× Monitor (XLR), 1× Kopfhörer. USB-Soundkarte 24×24, SD-Aufnahme/Wiedergabe, Bluetooth-Stereo-Wiedergabe. Maße (BxHxT) 372×153×133 mm, Gewicht 2,6 kg. Optionales Rackmount-Kit separat erhältlich (Art. 573745).',
     85.0, 'https://www.thomann.de/de/allen_heath_cq20b.htm'],
    ['568064', 'Piano', 'Yamaha P-225 B',
     'Stagepiano mit 88 Tasten Graded Hammer Compact (GHC), gewichtet. Klangerzeugung Yamaha CFX VRM Lite mit Key-Off-Samples, 192-stimmig polyphon, 24 Instrument-Presets. Integrierte Lautsprecher 2×7 Watt, Bluetooth-Audio-Wiedergabe über die internen Lautsprecher. Inkl. Sustain-Pedal (M-Audio SP-2), Netzteil und Notenhalter. Maße 1.326×129×272 mm (BxHxT), Gewicht 11,5 kg.',
     35.0, 'https://www.thomann.de/de/yamaha_p_225_b.htm'],
    ['625358', 'DJ-Controller', 'Rane One MKII',
     'B-Stock, professioneller motorisierter DJ-Controller. 7,2" motorisierte Plattenteller, 29 interne Hardwareeffekte, dedizierte Stems Control, High/Low-Pass Filter + 3-Band-EQ, 8 Performance-Pads pro Deck. Eingänge: 2× Line/Phono, 2× Mikrofon (TRS/XLR); Ausgänge: 2× XLR Main, 2× XLR Booth, Kopfhörer. Serato DJ Pro enthalten. Maße 647×345×124 mm, Gewicht 10,68 kg.',
     60.0, 'https://www.thomann.de/de/rane_one_mkii.htm'],
    ['617135', 'Rigging', 'Gravity WB 123 T B',
     'Quadratischer Standfuß für 3× M20-Distanzrohre, Aufnahme für Traversen-Systeme F21–F24 und F31–F34. Maximallast 50 kg, pulverbeschichteter Stahl, Abmessungen (HxBxT) 15×563×563 mm, Gewicht 14 kg.',
     15.0, null],
    ['454369', 'Licht', 'Eurolite QuickDMX USB Wireless Mini-DMX-Transceiver',
     'Kabelloser DMX-Sender/Empfänger 2,4 GHz (phantomgespeist), Reichweite bis 400 m, bis zu 6 Sender parallel für 6 DMX-Universen. Plug & Play über USB Typ A, schwenkbare Antenne, Maße mit Antenne 86×40×11 mm, Gewicht 15 g.',
     10.0, 'https://www.thomann.de/de/eurolite_quickdmx_usb_wireless_t_r.htm'],
    ['600085', 'Licht', 'Ape Labs Neon Tube Set (6× RGBWW + Softbag)',
     'B-Stock, Set aus 6× Neon Tube LED-Lichtröhren (je 100 cm, IP65, CRI 90) inkl. Ständer, Fernbedienung und Softbag-Tasche. Steuerung per Fernbedienung, Smartphone-App, Wireless DMX oder Smarthome KNX, Farbtemperatur 2700 K warmweiß, Abstrahlwinkel 180°. Akkubetrieb ca. 15 Std. oder Dauerbetrieb am Netz (Netzteil 108 W inklusive), Gewicht je Röhre 1,2 kg.',
     80.0, 'https://www.thomann.de/de/ape_labs_neon_tube_softbag_6.htm'],
    ['518026', 'Licht', 'Eurolite LED Theatre COB 100 RGB+WW',
     'LED-Theater-Scheinwerfer mit 100-W-RGB+WW-COB-LED, ansteuerbar Stand-alone, DMX (4/5/6/9-Kanal) oder QuickDMX über USB. Musiksteuerung über Mikrofon, Dimmer/Strobe, CRI >90, Abstrahlwinkel 40°. Inkl. Netzkabel und Flügeltore. Anschluss Power Twist + XLR DMX In/Out. Maße 25×32×29 cm, Gewicht 2,75 kg.',
     20.0, 'https://www.thomann.de/de/eurolite_led_theatre_cob_100_rgbww.htm', 2],
    // Rechnung 84734246 – Lokvogel-Bestand (own_rig): normalerweise im eigenen Setup im Einsatz,
    // bei Bedarf aber auch für größere Vermiet-/DJ-Aufträge verfügbar
    ['160142', 'Mikrofon', 'Audix D6', 'Spezialmikrofon für Bass Drum (auch E-Bass geeignet), dynamisches Großmembran-Mikrofon, Nierencharakteristik, Frequenzgang 30–15.000 Hz, max. SPL sehr hoch (bassdrumtauglich), Gewicht 254 g.', 25.0, 'https://www.thomann.de/de/audix_d6_bassdrummikro.htm', 1, true],
    ['479553', 'Signal', 'IMG Stageline FGA-202', '2-Kanal-Line-Übertrager zur Reduktion von Signalstörungen/Brummschleifen. 2 Eingänge (XLR/6,3-mm-Kombibuchse, 600 Ω), 2 galvanisch getrennte XLR-Ausgänge mit Groundlift-Schalter. Frequenzbereich 20–20.000 Hz, Maße 125×55×75 mm, Gewicht 650 g.', 10.0, 'https://www.thomann.de/de/img_stageline_fga_202.htm', 1, true],
    ['436138', 'Mikrofon', 'the t.bone BD 500 Beta', 'Kondensator-Grenzflächenmikrofon (halbe Niere) für Bass-Drum oder Piano/Sprache, schaltbarer Frequenzgang, 30–20.000 Hz, robustes Metallgehäuse, 3/8"-Gewinde, Gewicht 480 g.', 15.0, 'https://www.thomann.de/de/the_t.bone_bd_500_beta.htm', 1, true],
    ['129171', 'Mikrofon', 'Sennheiser E609 Silver', 'Dynamisches Instrumentenmikrofon (Superniere) für E-Gitarre, Percussion, Bläser, Drums. Frequenzgang 40–15.000 Hz, Gewicht 140 g. Inkl. MZQ-100-Klemme und Tasche.', 15.0, 'https://www.thomann.de/de/sennheiser_e609_evolution.htm', 1, true],
    ['326853', 'Mikrofon', 'Rode M5 MP', 'Stereo-Set Kleinmembran-Kondensatormikrofone (matched pair), Nierencharakteristik, Frequenzbereich 20 Hz–20 kHz, max. 140 dB SPL, benötigt Phantomspeisung 24/48 V, Metallgehäuse.', 20.0, 'https://www.thomann.de/de/rode_m5_mp.htm', 2, true],
    ['395760', 'Stativ', 'Gravity MS 3122 HDB', 'Kurzes Dreibein-Mikrofonstativ, ausziehbarer Galgen (max. 880 mm), Höhe 320 mm, Zinkdruckguss-Sockel, Gewicht 2,8 kg.', 8.0, 'https://www.thomann.de/de/gravity_ms_3122_hdb_microphone_stand.htm', 2, true],
    ['426274', 'Stativ', 'Gravity MS 4322 HDB', 'Extra schweres, langes Dreibein-Mikrofonstativ, ausziehbarer Galgen (max. 880 mm), höhenverstellbar 1030–1690 mm, Gewicht 4,26 kg.', 9.0, 'https://www.thomann.de/de/gravity_ms_4322_hdb_microphone_stand.htm', 2, true],
    ['370954', 'Stativ', 'Gravity MS 4322 B', 'Langes Dreibein-Mikrofonstativ, ausziehbarer Galgen (max. 880 mm), höhenverstellbar 1030–1690 mm, Gewicht 2,7 kg.', 8.0, 'https://www.thomann.de/de/gravity_ms_4322_b_microphone_stand.htm', 2, true],
    ['370937', 'Stativ', 'Gravity MS 4222 B', 'Kurzes Dreibein-Mikrofonstativ, ausziehbarer Galgen (max. 880 mm), höhenverstellbar 510–740 mm, Gewicht 2,2 kg.', 7.0, 'https://www.thomann.de/de/gravity_ms_4222_b_microphone_stand.htm', 1, true],
    ['435574', 'Zubehör', 'Gravity MS CAB CL 01', 'Cab-Clamp-Mikrofonhalterung für Gitarrenboxen, schwenkbarer Arm, justierbare Klemmen (Klemmbereich 300–400 mm), Gewindeanschluss 3/8", Gewicht 0,6 kg.', 6.0, 'https://www.thomann.de/de/gravity_ms_cab_cl_01.htm', 2, true],
    ['160358', 'Signal', 'Behringer DI20 Ultra-DI', 'Aktive 2-Kanal-DI-Box, XLR-Out, -20/40 dB PAD (bis 3000 W), Batterie (9 V) oder Phantomspeisung (15–52 V), Groundlift, auch als 1-auf-2-Splitter nutzbar, Gewicht 0,65 kg.', 8.0, 'https://www.thomann.de/de/behringer_di20_di_box.htm', 1, true],
    // Ohne Rechnung von Markus durchgegeben – ebenfalls Lokvogel-Bestand
    ['MANUAL-PLAUDIO-B215', 'Lautsprecher', 'PL Audio B215 Aktiv', 'Aktiver 2×15"-Subwoofer (Bus) mit eingebauter 3-Kanal-Endstufe: 2.500 W im Bassbereich + 2×800 W für den Betrieb von Topteilen. Eingebauter DSP mit 80 Presets, Faital-Chassis, Pascal-Endstufen. Kombiniert Sub + Endstufe für die angeschlossenen Topteile in einem Gerät.', 60.0, 'https://pl-audio.de/en/products/speaker/subwoofer/b-215-sub/', 2, true],
    ['MANUAL-SEEBURG-A3', 'Lautsprecher', 'Seeburg Acoustic Line A3', 'Passives Mittelhochton-Topteil, 2×8" Neodym-Tieftöner + 1" Hochtontreiber. Belastbarkeit 500 W AES / 1500 W Peak, 4 Ω, max. SPL 132 dB. Frequenzbereich 80 Hz–20 kHz, Abstrahlwinkel 90°×60° (drehbar). Anschluss 2× Speakon NL4MP, 35-mm-Stativhalterung. Maße 59×25×25 cm, Gewicht 12,5 kg.', 35.0, 'https://www.thomann.de/de/seeburg_acoustic_line_a3.htm', 2, true],
    // Von Markus mündlich durchgegeben, ohne Rechnung (Bestand für die normale Vermietung)
    ['MANUAL-SEEBURG-X2', 'Lautsprecher', 'Seeburg Acoustic Line X2', 'Kompaktes Koaxial-Topteil (passiv): 8" Tieftöner + 1" Hochtöner koaxial – gleichmäßige Abstrahlung auch außerhalb der Achse. Belastbarkeit 250 W AES / 750 W Peak, 8 Ω, max. SPL 124 dB. Frequenzbereich 70 Hz–20 kHz, Abstrahlwinkel 90°×60°. Multiplex-Gehäuse 375×250×250 mm, 8 kg, 35-mm-Stativflansch, flugfähig.', 30.0, 'https://www.thomann.de/de/seeburg_x_2.htm', 2],
    ['MANUAL-SEEBURG-A6', 'Lautsprecher', 'Seeburg Acoustic Line A6', 'Multifunktions-Topteil 12"+1", drehbares DCP-Horn mit konstant 90°×60° Abstrahlung. Auch als Monitor einsetzbar. Belastbarkeit 500 W AES / 1500 W Peak, 8 Ω.', 40.0, 'https://www.thomann.de/de/acoustic_line_a6.htm', 2],
    ['MANUAL-SEEBURG-GSUB1201DPPP', 'Lautsprecher', 'Seeburg G-Sub 1201 dp++', 'Aktiver 12"-Subwoofer, flache Bauform (Höhe nur 33 cm), 3-Kanal-DSP-Endstufe mit 2 Signaleingängen – die zusätzlichen Endstufenkanäle können ein HiMid-/Fullrange-System oder einen passiven Sub mitversorgen. 500 W AES (Single) / 1000 W AES (Dual Mode). Menge laut Markus noch unklar, vorerst mit 1 angelegt – bitte im Backoffice korrigieren.', 90.0, 'https://www.thomann.de/de/seeburg_acoustic_line_g_sub_1201dp_480776.htm'],
    ['MANUAL-SEEBURG-GSUB1201-PASSIV', 'Lautsprecher', 'Seeburg G-Sub 1201 passiv', 'Passiver 12"-Subwoofer, Bassreflex, Neodym-Chassis, 500 W AES / 1500 W Peak, 8 Ω. Menge laut Markus noch unklar, vorerst mit 1 angelegt – bitte im Backoffice korrigieren.', 45.0, 'https://www.thomann.de/de/seeburg_acoustic_line_g_sub_1201.htm'],
    ['MANUAL-WOLFMIX-W1MK2', 'Licht', 'Wolfmix W1 Mk2', 'Standalone-DMX-Lichtsteuerung, komplett ohne Computer bedienbar. 37 beleuchtete Silikontasten, 4,3"-Touch-Display, bis zu 4 DMX-Universen, USB-A-Anschluss für Stick/MIDI. Menge laut Markus noch unklar, vorerst mit 1 angelegt.', 25.0, 'https://www.thomann.de/de/wolfmix_w1_mk2.htm'],
    ['MANUAL-APELABS-MAXIV2-GRAU15', 'Licht', 'Ape Labs Maxi V2+ (Grau, 15°)', 'Akku-Uplight, RGBWW-LED, IP65, ca. 14 Std. Akkulaufzeit, Steuerung per App/Fernbedienung/Wireless-DMX, Edelstahlgehäuse. 15°-Abstrahloptik, Farbe Grau/Anthrazit. Genaue Thomann-Setbezeichnung noch zu prüfen (Link deshalb leer gelassen).', 20.0, null, 6],
    ['MANUAL-APELABS-MAXIV2-CREME45', 'Licht', 'Ape Labs Maxi V2+ (Creme, 45°)', 'Akku-Uplight, RGBWW-LED, IP65, ca. 14 Std. Akkulaufzeit, Steuerung per App/Fernbedienung/Wireless-DMX, Edelstahlgehäuse. 45°-Abstrahloptik, Farbe Creme. Genaue Thomann-Setbezeichnung noch zu prüfen (Link deshalb leer gelassen).', 20.0, null, 6],
    ['MANUAL-SENNHEISER-E835', 'Mikrofon', 'Sennheiser e835', 'Dynamisches Gesangsmikrofon, Nierencharakteristik, Frequenzgang 40 Hz–16 kHz mit Präsenzanhebung 3–10 kHz für druckvollen, präsenten Klang. Menge laut Markus noch unklar, vorerst mit 1 angelegt.', 8.0, 'https://www.thomann.de/de/sennheiser_e835.htm'],
    ['MANUAL-SENNHEISER-EWDP835', 'Mikrofon', 'Sennheiser EW-DP 835 Set', 'Digitales UHF-Funkmikrofon-Set: Handsender mit dynamischer e835-Niere-Kapsel, magnetisches Empfänger-Stacking, Akkulaufzeit Sender ca. 12 Std. / Empfänger ca. 7 Std. Frequenzband noch mit Markus abzustimmen. Menge vorerst mit 1 angelegt.', 25.0, 'https://www.thomann.de/de/sennheiser_ew_dp_835_set_r1_6.htm'],
    ['MANUAL-STAIRVILLE-AF40', 'Effekt', 'Stairville AF-40', 'Kompakte Nebelmaschine mit DMX-Schnittstelle, Nebelausstoß ca. 85 m³/min, Leistung 370 W, Tank 0,25 l, Gewicht 2,1 kg. Wie bei allen Nebelmaschinen: 1 Liter Fluid inklusive, Mehrverbrauch wird über „Nebelfluid-Nachfüllung" nachberechnet. Menge vorerst mit 1 angelegt.', 25.0, 'https://www.thomann.de/de/stairville_af_40_dmx_mini_fog_machine.htm'],
    ['MANUAL-EUROLITE-NH20', 'Effekt', 'Eurolite NH-20 Tour Fazer', 'DMX-Dunstnebelmaschine (Hazer) im Flightcase, Tank 1,7 l, Verbrauch ca. 2,2 ml/min, Aufheizzeit ca. 1,5 Min., Wurfweite ca. 2 m. Steuerung DMX, Stand-alone, QuickDMX/W-DMX/CRMX über USB. Menge vorerst mit 1 angelegt.', 35.0, 'https://www.thomann.de/de/eurolite_nh_20_tour_fazer.htm'],
    ['MANUAL-ADJ-ENTOURFAZE', 'Effekt', 'ADJ Entour Faze', 'Fazer/Hazer mit 450-W-Heizelement, Nebelausstoß ca. 113 m³/min, Verbrauch ca. 4,5 ml/min, Aufheizzeit ca. 45 Sek. Tank 3 l, verwendet normale Wasser-Nebelfluid. Steuerung über Menütasten/Bar-Display, Trigger-Schalter, optional DMX oder mitgeliefertes Kabel-Fernbedienung. Maße 414×202×303 mm, Gewicht 4,5 kg. Menge vorerst mit 1 angelegt.', 30.0, 'https://www.thomann.de/de/adj_entour_faze.htm'],
    ['MANUAL-APELABS-MARVELFLOOD', 'Licht', 'Ape Labs Marvel Flood', 'Akku-Flutlicht, 9 Pixel-LEDs à 18 W, Dual-Drive-Technologie (flimmerfrei), IP64. Akkulaufzeit ca. 2 Std. bei 100 % / ca. 8 Std. bei 75 % Helligkeit. Steuerung per App/Fernbedienung/Wireless-DMX (Reichweite ca. 1200 m, automatischer Signal-Repeater von Lampe zu Lampe) oder klassisch per DMX-Kabel im Dauerbetrieb. Maße 28,5×26,5×8,0 cm, Gewicht 3,7 kg.', 25.0, 'https://www.thomann.de/de/ape_labs_marvel_flood.htm', 2],
    // Preis von Markus fest vorgegeben (Index 8 = day_rate), daher keine reine Empfehlung
    ['MANUAL-JBL-PARTYBOX', 'Lautsprecher', 'JBL PartyBox 100 / 110', 'Mobiler Akku-Partylautsprecher mit Lichteffekten, 160 W, bis zu 12 Std. Akkulaufzeit, Bluetooth und AUX/USB, Mikrofon-/Gitarreneingang, spritzwassergeschützt (110er: IPX4). Maße ca. 29,5×57×30 cm, Gewicht ca. 11 kg. Bestand gemischt aus PartyBox 100 und 110 – praktisch baugleich in Klang und Bedienung.', 25.0, 'https://www.thomann.de/de/jbl_partybox_110.htm', 3, false, 25.0],
    ['MANUAL-LEINWAND-80', 'Video', 'Mobile Beamer-Leinwand 80"', 'Mobile Leinwand für Beamer, 80 Zoll Bilddiagonale. Wird gerollt transportiert und passt so in jeden Kombi – schnell aufgebaut, für den Innenbereich.', 10.0, null],
    ['MANUAL-LEINWAND-100', 'Video', 'Mobile Beamer-Leinwand 100"', 'Mobile Leinwand für Beamer, 100 Zoll Bilddiagonale. Wird gerollt transportiert und passt so in jeden Kombi – schnell aufgebaut, für den Innenbereich.', 15.0, null],
  ];
  foreach ($rows as $row) {
    [$sku, $cat, $n, $d, $suggested, $thomann] = $row;
    $qty = $row[6] ?? 1;
    $ownRig = $row[7] ?? false;
    $rate = $row[8] ?? 0;   // fester Mietpreis, falls Markus ihn schon vorgegeben hat
    $c = $p->prepare('select count(*) from equipment where sku = ?');
    $c->execute([$sku]);
    if (!(int)$c->fetchColumn())
      $p->prepare('insert into equipment (id,sort,name,slug,sku,category,description,day_rate,day_rate_suggested,
          followup_pct,qty_total,rentable,public,status,thomann_url,own_rig,created_at) values (?,?,?,?,?,?,?,?,?,50,?,1,1,?,?,?,?)')
        ->execute([uuid(), 0, $n,
          strtolower(preg_replace('/[^a-z0-9äöüß]+/iu', '-', $n)), $sku, $cat, $d, $rate, $suggested, $qty, 'aktiv', $thomann, $ownRig ? 1 : 0, now()]);
  }
}

function portalAccountDdl(): array {
  return [
    "create table if not exists cust_tokens (token text primary key,
      customer_id text not null references customers(id) on delete cascade, expires integer)",
    "create table if not exists customer_files (id text primary key,
      customer_id text not null references customers(id) on delete cascade,
      booking_id text, kind text default 'dokument', name text, file text, size integer, created_at text)",
  ];
}

function docAuditDdl(): string {
  return "create table if not exists doc_audit (id text primary key, document_id text,
    user_email text, action text, detail text, created_at text)";
}

/* Änderungsprotokoll für Dokumente (GoBD) */
function docAudit(PDO $p, ?string $docId, string $action, string $detail = ''): void {
  $u = currentUser();
  try {
    $p->prepare('insert into doc_audit (id, document_id, user_email, action, detail, created_at) values (?,?,?,?,?,?)')
      ->execute([uuid(), $docId, $u['email'] ?? 'system/portal', $action, mb_substr($detail, 0, 2000), now()]);
  } catch (PDOException $e) {}
}

/* Festgeschrieben = Rechnungsartige Dokumente, die den Entwurfsstatus verlassen haben */
function docLockedRow(array $d): bool {
  return !in_array($d['doc_type'] ?? '', ['angebot', 'lieferschein'])
    && ($d['status'] ?? 'entwurf') !== 'entwurf';
}
/* Positionen dürfen nur geändert werden, solange das zugehörige Dokument nicht festgeschrieben ist */
function assertItemsUnlocked(PDO $p, string $wsql, array $args): void {
  $st = $p->prepare("select distinct d.number, d.doc_type, d.status from documents d
    where d.id in (select document_id from document_items" . ($wsql ?: '') . ")");
  $st->execute($args);
  foreach ($st->fetchAll() as $d) if (docLockedRow($d))
    fail('Rechnung ' . $d['number'] . ' ist festgeschrieben (GoBD): Positionen können nicht mehr geändert werden.', 409);
}

/* Nachträgliche E-Mail-Vorlagen, nur wenn noch nicht vorhanden */
function seedExtraTemplates(PDO $p): void {
  $extra = [
    [90, 'Zahlungserinnerung (freundlich)', 'Kleine Erinnerung: Rechnung {nr}',
      "Hallo {vorname},\n\nich hoffe, es ist alles gut angekommen! Mir ist aufgefallen, dass die Rechnung {nr} über {betrag} (fällig am {faellig}) noch offen ist.\n\nBestimmt ist sie nur untergegangen – hier ist der Link zum Ansehen und als PDF:\n{link}\n\nFalls die Zahlung schon unterwegs ist: einfach ignorieren, dann hat sich das überschnitten.\n\nViele Grüße\nMarkus"],
    [91, 'Angebots-Begleitmail', 'Euer Angebot ist fertig',
      "Hallo {vorname},\n\ndanke für das gute Gespräch! Euer Angebot ist fertig und wartet hier auf euch:\n{link}\n\nIhr könnt es direkt online ansehen, Fragen zu einzelnen Positionen stellen oder mit einem Klick annehmen. Login ist eure Postleitzahl.\n\nWenn euch etwas nicht passt: sagt es mir einfach – wir biegen das hin.\n\nViele Grüße\nMarkus"],
    [92, 'Workshop-Bestätigung (Zahlung eingegangen)', 'Dein Platz ist fix!',
      "Hallo {vorname},\n\ndeine Zahlung ist da – damit ist dein Workshop-Platz verbindlich reserviert!\n\nWann: {datum}\nWo: Lager Hemer, Büttmecker Weg 35c\n\nBring gern dein eigenes Equipment-Problem mit – wir schauen uns echte Fälle an. Getränke gehen auf mich.\n\nBis bald!\nMarkus"],
  ];
  foreach ($extra as [$s, $n, $sub, $b]) {
    $c = $p->prepare('select count(*) from email_templates where name = ?');
    $c->execute([$n]);
    if (!(int)$c->fetchColumn())
      $p->prepare('insert into email_templates (id, sort, name, subject, body) values (?,?,?,?,?)')
        ->execute([uuid(), $s, $n, $sub, $b]);
  }
}

function workshopsDdl(): array {
  return [
    "create table if not exists workshop_events (id text primary key, sort integer default 0,
      title text not null, description text, audience text default '', event_date text not null, start_time text, end_time text,
      location text, price_net real, capacity integer default 8, public integer default 0, created_at text)",
    "create table if not exists workshop_signups (id text primary key,
      workshop_id text not null references workshop_events(id) on delete cascade,
      name text not null, email text, phone text, seats integer default 1, message text,
      q_music text, q_challenge text, q_goal text,
      street text, zip text, city text, invoice_id text,
      status text default 'angemeldet', created_at text)",
  ];
}

function friendsDdl(): string {
  return "create table if not exists friends (id text primary key, sort integer default 0,
    name text not null, category text, description text, website text,
    public integer default 1, created_at text)";
}

function rentalContractsDdl(): string {
  return "create table if not exists rental_contracts (id text primary key,
    booking_id text not null references bookings(id) on delete cascade,
    token text unique, status text default 'offen', snapshot text,
    signed_name text, signature text, id_front text, id_back text,
    signed_at text, created_at text)";
}

function rentalContractDefault(): string {
  return "§ 1 Mietgegenstand und Mietzeit\nVermietet werden die im Vertrag aufgeführten Geräte für den genannten Zeitraum. Ein Miettag entspricht 24 Stunden ab Übergabe; jeder weitere Tag wird mit 50 % des Tagespreises berechnet. Übergabe und Rückgabe erfolgen, sofern nicht anders vereinbart, am Lager des Vermieters in Hemer.\n\n§ 2 Zustand, Einweisung und Nutzung\nDie Geräte werden in geprüftem, funktionsfähigem Zustand übergeben; der Mieter erhält eine kurze Einweisung. Die Nutzung erfolgt sachgemäß und nur durch den Mieter bzw. von ihm beauftragte, eingewiesene Personen.\n\n§ 3 Haftung des Mieters\nDer Mieter haftet ab Übergabe bis zur Rückgabe für Verlust, Diebstahl und Beschädigung der Mietsachen in Höhe des Wiederbeschaffungswerts bzw. der Reparaturkosten. Mängel und Schäden sind unverzüglich zu melden.\n\n§ 4 Rückgabe\nDie Rückgabe erfolgt vollständig, gereinigt und ordnungsgemäß verpackt zum vereinbarten Zeitpunkt. Bei verspäteter Rückgabe wird je angefangenem Tag der Folgetagespreis berechnet.\n\n§ 5 Kaution\nEine vereinbarte Kaution wird bei vollständiger, unbeschädigter Rückgabe erstattet.\n\n§ 6 Schlussbestimmungen\nEs gelten ergänzend die AGB des Vermieters. Es gilt deutsches Recht.";
}

/* Datenschutzerklärung – eine Quelle für Seed und Migration (v25) */
function datenschutzText(): string {
  return "Datenschutzerklärung\n\n1. Verantwortlicher\nMarkus Jankowski, Büttmecker Weg 35c, 58675 Hemer, Telefon 01523 6439373.\n\n2. Hosting\nDiese Website wird bei der ALL-INKL.COM – Neue Medien Münnich (Deutschland) gehostet. Beim Aufruf der Seiten verarbeitet der Hoster technisch notwendige Daten (z. B. IP-Adresse, Zeitpunkt des Abrufs) in Server-Logfiles auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO (sicherer Betrieb der Website).\n\n3. Cookies und lokale Speicherung\nDiese Website verwendet keine Cookies zu Werbe- oder Tracking-Zwecken und bindet keine Dienste ein, die solche Cookies setzen. Ein Cookie-Banner ist deshalb nicht erforderlich. Nur im Kundenportal und im Partner-Bereich wird nach eurer aktiven Anmeldung ein technisch notwendiges Sitzungsmerkmal im Browser gespeichert (Local/Session Storage), damit ihr angemeldet bleibt (§ 25 Abs. 2 TDDDG).\n\n4. Schriftarten\nAlle Schriftarten liegen lokal auf dem Server dieser Website. Beim Seitenaufruf wird keine Verbindung zu Google Fonts oder anderen Drittanbietern aufgebaut.\n\n5. Reichweitenmessung\nZur Verbesserung des Angebots wird anonym gezählt, wie oft die einzelnen Seiten aufgerufen werden (nur Datum, Seitenname und ggf. die Domain der verweisenden Website). Dabei werden weder IP-Adressen noch Cookies oder sonstige Kennungen gespeichert – ein Bezug zu einzelnen Personen ist nicht möglich (Art. 6 Abs. 1 lit. f DSGVO).\n\n6. Anfrageformular\nWenn ihr das Anfrageformular nutzt, verarbeite ich die dort eingegebenen Daten (Name, E-Mail, Telefon, Angaben zur Feier, Nachricht) zur Bearbeitung eurer Anfrage und für die Vertragsanbahnung (Art. 6 Abs. 1 lit. b DSGVO). Die Daten werden auf dem eigenen Server dieser Website gespeichert und nicht an Dritte weitergegeben, sofern ihr nicht ausdrücklich eine Vermittlung an Partner-DJs wünscht.\n\n7. Newsletter\nFür den Workshop-Newsletter speichere ich eure E-Mail-Adresse erst nach Bestätigung über den zugesandten Link (Double-Opt-in) auf Grundlage eurer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO). Jede Mail enthält einen Abmeldelink; nach der Abmeldung erhaltet ihr keine weiteren Mails. Es wird kein Versanddienstleister eingesetzt – der Versand erfolgt über den eigenen Server.\n\n8. DJ-Vermittlung\nWünscht ihr eine Vermittlung an andere DJs, gebe ich die dafür erforderlichen Kontakt- und Veranstaltungsdaten an meine Partner-Agentur DJ Bande (Münster) weiter – ausschließlich mit eurer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO).\n\n9. Digitaler Mietvertrag und Ausweiskopie\nBei der Vermietung von Veranstaltungstechnik könnt ihr den Mietvertrag digital abschließen. Dabei werden eure Unterschrift sowie – mit eurer ausdrücklichen Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 20 PAuswG) – Fotos der Vorder- und Rückseite eures Personalausweises verarbeitet und in einem zugriffsgeschützten Bereich des eigenen Servers gespeichert. Nicht benötigte Angaben dürft ihr vor dem Fotografieren schwärzen. Die Ausweiskopien dienen ausschließlich der Absicherung des Mietverhältnisses und werden nach vollständiger Rückgabe der Mietsachen gelöscht.\n\n10. Kundenportal\nIm Kundenportal könnt ihr euch mit E-Mail-Adresse und Passwort anmelden, um eure Unterlagen einzusehen und Angaben zu eurer Feier zu pflegen. Das Passwort wird ausschließlich verschlüsselt (als Hash) gespeichert; alle Inhalte liegen auf dem eigenen Server dieser Website (Art. 6 Abs. 1 lit. b DSGVO).\n\n11. Eure Rechte\nIhr habt das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit sowie Beschwerde bei einer Aufsichtsbehörde. Meldet euch dafür einfach unter den oben genannten Kontaktdaten.\n\nStand: bitte nach juristischer Prüfung ergänzen.";
}

function migrate(PDO $p): void {
  $p->exec(<<<SQL
create table users (id text primary key, email text unique not null, pass_hash text not null, created_at text);
create table tokens (token text primary key, user_id text not null, expires integer not null);
create table settings (key text primary key, value text not null default '{}', updated_at text);
create table site_content (key text primary key, value text not null default '{}', updated_at text);
create table packages (id text primary key, sort integer default 0, title text not null, subtitle text,
  description text, price_from real, price_note text, features text default '[]',
  public integer default 1, created_at text);
create table faq (id text primary key, sort integer default 0, question text not null,
  answer text not null, public integer default 1);
create table equipment (id text primary key, sort integer default 0, name text not null, slug text, sku text,
  category text, description text, image_url text, image_focal text default '50% 50%',
  day_rate real default 0, followup_pct integer default 50,
  tier_week_pct real, tier_2week_pct real, tier_month_pct real,
  qty_total integer default 1, rentable integer default 1, public integer default 1,
  status text default 'aktiv', notes text, partner_rate real, addon_id text, addon_ids text, images text, fits_ids text, min_qty integer default 1, placeholder text,
  thomann_url text, own_rig integer default 0, day_rate_suggested real,
  invoice_file text, invoice_name text, on_request integer default 0, created_at text);
create table equipment_sets (id text primary key, sort integer default 0,
  name text not null, description text, image_url text, image_focal text default '50% 50%',
  discount_pct real default 5, fixed_price real, public integer default 1, created_at text);
create table calendar_blocks (id text primary key, title text not null,
  start_date text not null, end_date text, note text, created_at text);
create table equipment_set_items (id text primary key,
  set_id text not null references equipment_sets(id) on delete cascade,
  equipment_id text not null references equipment(id) on delete restrict, qty integer default 1);
create table locations (id text primary key, sort integer default 0, name text not null,
  city text, region text, address text, phone text, description text, image_url text,
  image_focal text default '50% 50%', website text,
  image_source text default 'eigen', image_approved integer default 0,
  highlight integer default 0, public integer default 1, created_at text);
create table content_versions (id text primary key, key text not null,
  label text, value text not null default '{}', created_at text);
create table inquiries (id text primary key, name text not null, email text, phone text,
  event_type text, event_date text, location text, guests text, message text,
  status text default 'neu', customer_id text, created_at text);
create table customers (id text primary key, kind text default 'privat', status text default 'lead',
  first_name text, last_name text, company text, email text, phone text, whatsapp text,
  street text, zip text, city text, source text, tags text default '[]', notes text, tech_check text,
  portal_hash text, portal_invite text, portal_invite_expires integer,
  created_at text, updated_at text);
create table communications (id text primary key,
  customer_id text not null references customers(id) on delete cascade,
  booking_id text, channel text not null, direction text default 'out', subject text, content text,
  occurred_at text, followup_at text, followup_done integer default 0, created_at text);
create table bookings (id text primary key,
  customer_id text not null references customers(id) on delete cascade,
  status text default 'anfrage', kind text default 'dj', event_type text, title text,
  event_date text not null, end_date text, start_time text, end_time text,
  venue_name text, venue_address text, guests integer, fee_net real, notes text, rider text, customer_notes text,
  billable_days integer, open_ended integer default 0,
  review_requested integer default 0, created_at text, updated_at text);
create table booking_equipment (id text primary key,
  booking_id text not null references bookings(id) on delete cascade,
  equipment_id text not null references equipment(id) on delete restrict,
  qty integer default 1, price_override real, out_done integer default 0,
  back_done integer default 0, notes text);
create table documents (id text primary key, share_token text, doc_type text not null, number text unique not null,
  price_mode text default 'netto', discount_value real default 0, discount_type text default 'pct',
  customer_id text not null references customers(id) on delete restrict,
  booking_id text references bookings(id) on delete set null,
  parent_id text, status text default 'entwurf', doc_date text, valid_until text, due_date text,
  tax_rate real default 19, is_small_business integer default 0, intro_text text, outro_text text,
  total_net real default 0, total_tax real default 0, total_gross real default 0,
  deposit_deducted real default 0, total_override real, sent_at text, paid_at text,
  accepted_name text, accept_signature text, created_at text, updated_at text);
create table email_templates (id text primary key, sort integer default 0, name text not null,
  subject text, body text, created_at text);
create table products (id text primary key, sku text unique, sort integer default 0,
  category text, name text not null, description text, unit text default 'Stk.',
  price_net real, bundle text default '[]', addon_sku text, active integer default 1, created_at text);
create table partners (id text primary key, code text unique, name text not null, company text,
  kind text default 'dj', email text, phone text, status text default 'beantragt',
  notes text, created_at text);
create table reviews (id text primary key, sort integer default 0, author text not null,
  event_type text, text text not null, rating integer default 5,
  source text default 'google', review_date text, public integer default 1, created_at text);
create table upsells (id text primary key, sort integer default 0, title text not null,
  description text, price_net real default 0, occasions text,
  active integer default 1, show_portal integer default 1, created_at text);
create table doc_events (id text primary key,
  document_id text not null references documents(id) on delete cascade,
  kind text not null, message text, phone text, created_at text, seen integer default 0);
create table form_templates (id text primary key, sort integer default 0, name text not null,
  intro text, fields text default '[]');
create table forms (id text primary key, token text unique not null, title text not null,
  intro text, fields text default '[]', answers text,
  status text default 'offen', inquiry_id text, customer_id text,
  created_at text, submitted_at text);
create table document_items (id text primary key,
  document_id text not null references documents(id) on delete cascade,
  pos integer default 1, description text not null, note text, qty real default 1, unit text, unit_price real default 0,
  discount_value real default 0, discount_type text default 'pct');
SQL);
  $p->exec(rentalContractsDdl());
  $p->exec(friendsDdl());
  foreach (workshopsDdl() as $sql) $p->exec($sql);
  $p->exec(docAuditDdl());
  foreach (portalAccountDdl() as $sql) $p->exec($sql);
  foreach (statsNewsletterDdl() as $sql) $p->exec($sql);
  seed($p);
  seedExtraTemplates($p);
  seedServiceProducts($p);
  seedTechCheckForm($p);
  seedEquipmentCatalog($p);
  /* Frühere Lokvogel-Artikel (own_rig) sind jetzt „auf Anfrage verfügbar". */
  $p->exec("update equipment set on_request = 1 where own_rig = 1");
  /* Set-Artikel (z. B. 6er) mit passender Mindestabnahme. */
  $p->exec("update equipment set min_qty = 6 where name like '%Maxi V2%' or name like '%Neon Tube%'");
}

function seed(PDO $p): void {
  $ins = function (string $t, array $row) use ($p) {
    $row['id'] ??= uuid();
    if (in_array($t, ['packages','equipment','inquiries','customers'])) $row['created_at'] ??= now();
    $cols = array_keys($row);
    $p->prepare("insert into $t (" . implode(',', $cols) . ") values (" .
      implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($row));
  };
  foreach ([
    ['company', '{"name":"DJ Lauschgift","owner":"Markus Jankowski","street":"Büttmecker Weg 35c","zip_city":"58675 Hemer","phone":"01523 6439373","email":"lauschgiftmarkus@gmail.com","website":"https://lauschgift.net","tax_id":"","vat_id":"","iban":"","bic":"","bank":"","small_business":false}'],
    ['numbering', '{"angebot":{"prefix":"AN-","next":1},"rechnung":{"prefix":"RE-","next":1},"lieferschein":{"prefix":"LS-","next":1},"year_in_number":true}'],
    ['rental_contract', json_encode(['text' => rentalContractDefault()], JSON_UNESCAPED_UNICODE)],
    ['defaults', '{"tax_rate":19,"payment_days":14,"quote_valid_days":30,"quote_intro":"vielen Dank für Ihre Anfrage. Gerne biete ich Ihnen an:","invoice_outro":"Bitte überweisen Sie den Betrag unter Angabe der Rechnungsnummer auf das unten genannte Konto."}'],
  ] as [$k, $v]) $p->prepare('insert into settings (key,value,updated_at) values (?,?,?)')->execute([$k, $v, now()]);

  foreach ([
    ['hero', '{"title":"DJ Lauschgift","subtitle":"DJ für Hochzeiten, Geburtstage & Firmenfeiern · deutschlandweit","text":"Ich bin Markus – seit 23 Jahren DJ, quer durch Deutschland unterwegs. Keine Show um meine Person, kein Programm von der Stange: Ich lese den Raum und spiele das, was eure Gäste auf die Tanzfläche bringt. Ihr müsst euch um nichts kümmern – dafür bin ich da.","cta":"Unverbindlich anfragen","image":""}'],
    ['about', '{"title":"Einfach Markus. Und trotzdem kein Standard-DJ.","gear":["Seeburg Acoustic Line","ApeLabs","Sennheiser","Rane","Allen & Heath"],"text":"Angefangen hat alles mit zwei Plattenspielern und einem alten Mischpult zum 18. Geburtstag. Ein Jahr lang habe ich in der heimischen Garage geübt, bis ich für bekannte DJs das Warm-up in angesagten Clubs übernehmen durfte. Den eigentlichen Wendepunkt gab es aber bei einer ganz anderen Feier: Als meine Tante mich zu ihrem runden Geburtstag fragte, ob ich auch gemischte Musik auflegen könnte, war ich skeptisch – bis Jung und Alt gemeinsam auf der Tanzfläche standen und weitersangen, als ich den Regler runterzog. Seitdem ist mir in 23 Jahren kein einziger Abend langweilig geworden.\\n\\nWas mich von vielen anderen unterscheidet: Ich bin ein echter Technik- und Menschenfreund. Ich nehme euch und eure Gäste bewusst wahr und setze auf Licht- und Tontechnik, die man sonst eher von deutlich größeren Produktionen kennt – weil auch eine Feier mit 40 Gästen großartige Technik verdient. Mein Sound kommt von Seeburg Acoustic Line, einem der deutschen Top-Hersteller für mobile PA-Systeme – das hört man sofort. Dazu passe ich mich flexibel an jede Location an, ob Scheune, Schloss, Industriehalle oder Gartenparty: Ich kenne mein Equipment in- und auswendig und weiß, wie ich jeden Raum klanglich und optisch in Szene setze.","image":"img/markus_1.jpg"}'],
    ['services', '{"title":"Das bekommt ihr","text":"Vom Sektempfang bis zum letzten Song: Musik, Ton für die freie Trauung, dezentes Licht passend zur Location – und ein Plan B für alle Fälle. Ihr feiert, ich kümmere mich um den Rest.","image":""}'],
    ['guarantee', '{"title":"Schon ausgebucht? Ihr steht trotzdem nicht ohne DJ da.","text":"Wenn ich an eurem Termin keine Zeit habe – oder merke, dass ich nicht der richtige DJ für eure Feier bin – wähle ich persönlich bis zu fünf Kollegen aus meinem Partner-Netzwerk aus, die wirklich zu euch passen. Keine anonyme Liste: Ich kenne die Kollegen und ihre Stärken, und ihr bekommt die Vorschläge direkt von mir – auch günstigere Optionen sind dabei, falls euer Budget das erfordert. Und Transparenz gehört dazu: Für eine erfolgreiche Vermittlung erhalte ich eine kleine Provision (Details in den AGB)."}'],
    ['rental', '{"title":"Technik mieten","text":"Von der Anlage für Redenbeiträge bis zu LED-Spots für die Raumdeko – alles gewartet, geprüft und mit kurzer Einweisung bei der Abholung."}'],
    ['tech_hero', '{"subtitle":"Lauschgift Veranstaltungstechnik · Hemer","text":"Große Bühnen mit viel Platz kann jeder beschallen. Die Kunst ist die kleine Location: niedrige Decke, harte Wände, Publikum direkt vor der Box. Genau darauf bin ich spezialisiert – Ton und Licht für Veranstaltungen von 30 bis 200 Gästen, mit hochwertiger Technik, die dafür gebaut ist."}'],
    ['tech_teaser', '{"title":"Lauschgift Veranstaltungstechnik","text":"Ton und Licht gehören für mich untrennbar zum DJ-Sein dazu – deshalb biete ich beides auch unabhängig voneinander an: Technik zum Mieten direkt aus meinem Lager in Hemer, oder mich als Techniker inklusive Equipment, ganz ohne Auflegen. Alle Details dazu auf der Technik-Seite."}'],
    ['contact', '{"title":"Kontakt","phone":"01523 6439373","email":"lauschgiftmarkus@gmail.com","address":"Büttmecker Weg 35c, 58675 Hemer","instagram":"https://www.instagram.com/dj_lauschgift/","whatsapp":""}'],
    ['theme', '{"preset":"koralle","primary":"#ff6f5b","bg":"#0f1012","font":"grotesk"}'],
    ['reviews', '{"google_url":"","djbande_url":"","tagline":""}'],
    ['loc_section', '{"title":"Orte, an denen ich besonders gerne auflege","text":"Deutschlandweit gibt es Locations, mit denen die Zusammenarbeit einfach herausragend läuft – eingespielte Teams, gute Technik-Bedingungen, tolle Räume. Diese Häuser empfehle ich aus voller Überzeugung."}'],
    ['gallery', '{"title":"So sieht\'s bei mir aus","images":["img/IMG_4061.png","img/IMG_4086.png","img/IMG_3296.png","img/IMG_9059.png","img/IMG_3591.png","img/spiegelkugel mittig.jpg","img/IMG_0850.png"]}'],
    ['seo', '{"title":"DJ Lauschgift – Hochzeits-DJ & Event-DJ | Deutschlandweit","description":"DJ Lauschgift – Markus Jankowski. 23 Jahre Erfahrung für Hochzeiten, Geburtstage & Firmenfeiern. Deutschlandweit buchbar. Technikverleih in Hemer."}'],
    ['legal', json_encode([
      'impressum' => "Angaben gemäß § 5 DDG\n\nMarkus Jankowski\nDJ Lauschgift\nBüttmecker Weg 35c\n58675 Hemer\n\nTelefon: 01523 6439373\nE-Mail: (bitte im Backoffice ergänzen)\n\nUmsatzsteuer: (Steuernummer / USt-IdNr. bitte im Backoffice ergänzen)\n\nVerantwortlich für den Inhalt: Markus Jankowski (Anschrift wie oben)",
      'datenschutz' => datenschutzText(),
      'agb' => "Allgemeine Geschäftsbedingungen (AGB)\n\n1. Geltungsbereich\nDiese AGB gelten ausschließlich für Verträge über DJ-Leistungen und Technikvermietung, die unmittelbar mit Markus Jankowski (DJ Lauschgift), Büttmecker Weg 35c, 58675 Hemer, geschlossen werden.\n\nSie gelten nicht für Verträge, die der Auftraggeber mit anderen DJs schließt – etwa nach einer Empfehlung bzw. Vermittlung über die Partner-Agentur (vgl. Ziffer 6) oder direkt mit dem jeweiligen DJ. Für solche Verträge gelten allein die Bedingungen des jeweiligen DJs bzw. der Agentur; der Auftragnehmer ist an diesen Verträgen nicht beteiligt und übernimmt für deren Inhalt und Erfüllung keine Haftung.\n\n2. Angebot und Vertragsschluss\nAngebote sind freibleibend. Der Vertrag kommt mit schriftlicher Bestätigung (auch per E-Mail) zustande. Erst mit der Bestätigung ist der Termin verbindlich reserviert.\n\n3. Preise\nDie Vergütung richtet sich nach Auslastung, Arbeitsstunden und technischem Aufwand der jeweiligen Veranstaltung; eine Unterscheidung nach Anlass (z. B. Hochzeit, Geburtstag, Firmenfeier) findet nicht statt. Alle Posten werden im Angebot ausgewiesen.\n\n4. Ausfall des Auftragnehmers und Ersatz (Plan B)\nFällt der Auftragnehmer aus (z. B. durch Krankheit), verpflichtet er sich, sich im Rahmen seiner Möglichkeiten um einen geeigneten Ersatz-DJ aus seinem Kollegen-Netzwerk zu bemühen und diesen dem Auftraggeber unverzüglich vorzuschlagen.\n\nDer Vorschlag ist für den Auftraggeber unverbindlich: Er kann frei entscheiden, ob er den vorgeschlagenen Ersatz-DJ beauftragt oder vom Vertrag zurücktritt. Bei Rücktritt werden bereits geleistete Zahlungen vollständig erstattet; weitergehende Ansprüche bestehen nur bei Vorsatz oder grober Fahrlässigkeit.\n\nEntscheidet sich der Auftraggeber für den Ersatz-DJ, kommt der Vertrag über dessen Leistung direkt mit dem Ersatz-DJ zustande. Wichtig: Der Ersatz-DJ rechnet zu seinen eigenen Preisen ab – der Endpreis kann daher vom ursprünglich vereinbarten Preis abweichen. Auch der Leistungsumfang, insbesondere die mitgeführte Ton- und Lichttechnik, kann vom Angebot des Auftragnehmers abweichen. Bereits an den Auftragnehmer geleistete Zahlungen werden in diesem Fall erstattet bzw. verrechnet.\n\n5. Stornierung durch den Auftraggeber\nSagt der Auftraggeber die Veranstaltung ab, kann kurzfristig in der Regel kein Ersatzauftrag mehr angenommen werden – insbesondere innerhalb von sechs Wochen vor dem Termin ist eine Neubelegung praktisch ausgeschlossen. Daher gilt folgende pauschale Ausfallvergütung (jeweils bezogen auf die vereinbarte Nettovergütung):\n– Absage bis 6 Monate vor dem Termin: 20 %\n– Absage bis 3 Monate vor dem Termin: 40 %\n– Absage bis 6 Wochen vor dem Termin: 60 %\n– Absage weniger als 6 Wochen vor dem Termin: 80 %\n– Absage weniger als 7 Tage vor dem Termin oder Nichtabnahme: 90 %\nErsparte Aufwendungen (z. B. nicht anfallende Fahrtkosten sowie stornierbare Übernachtungskosten) werden angerechnet und von der Ausfallvergütung abgezogen. Dem Auftraggeber bleibt der Nachweis unbenommen, dass kein oder ein wesentlich geringerer Schaden entstanden ist. Gelingt es dem Auftragnehmer, für den Termin einen gleichwertigen Ersatzauftrag anzunehmen, entfällt die Ausfallvergütung bis auf bereits entstandene Kosten. Maßgeblich für die Staffel ist der Zugang der Absage in Textform.\n\nUmbuchung auf einen Ersatztermin: Einigen sich beide Seiten auf einen Ersatztermin, kann der Auftragnehmer anstelle der Ausfallvergütung eine reduzierte Umbuchungspauschale ansetzen; bereits entstandene Kosten (z. B. nicht stornierbare Auslagen) werden zusätzlich berechnet. Die Umbuchung ist eine reine Kulanzregelung des Auftragnehmers: Ein Anspruch auf einen Ersatztermin oder auf eine reduzierte Pauschale besteht nicht. Ob und zu welchen Konditionen umgebucht wird, entscheidet der Auftragnehmer frei im Einzelfall – insbesondere abhängig von seiner Verfügbarkeit am Wunschtermin, davon, ob der ursprüngliche Termin anderweitig belegt werden kann, und vom Buchungswert des Ersatztermins.\n\n6. DJ-Vermittlung über Partner-Agentur\nIst der Auftragnehmer am gewünschten Termin verhindert oder kommt eine Zusammenarbeit aus anderen Gründen nicht zustande, kann er dem Interessenten auf Wunsch bis zu fünf passende DJs vorschlagen. Diese Empfehlung ist eine reine Vermittlungsleistung des Auftragnehmers und für den Interessenten kostenlos – sie wird ihm nicht in Rechnung gestellt.\n\nDie Vermittlung erfolgt über die Partner-Agentur DJ Bande (Münster). Der Vertrag über die DJ-Leistung kommt ausschließlich zwischen dem Interessenten und dem vermittelten DJ bzw. der Agentur zustande; die Abrechnung der DJ-Leistung erfolgt nicht über den Auftragnehmer. Die Vermittlungsleistung finanziert sich dadurch, dass der Auftragnehmer für eine erfolgreich zustande gekommene Vermittlung eine Aufwandsentschädigung (Provision) von der Agentur bzw. dem vermittelten DJ erhält. Für den Interessenten entstehen dadurch keine zusätzlichen Kosten. Die auf dieser Website genannten Preise und Preisbeispiele gelten ausschließlich für Leistungen des Auftragnehmers selbst; vermittelte DJs kalkulieren ihre Vergütung eigenständig, deren Konditionen können abweichen.\n\n7. Widerrufsrecht\nBei der Buchung von DJ- und Veranstaltungstechnik-Leistungen für einen bestimmten Termin besteht kein Widerrufsrecht. Gemäß § 312g Abs. 2 Nr. 9 BGB ist das Widerrufsrecht ausgeschlossen bei Verträgen zur Erbringung von Dienstleistungen im Zusammenhang mit Freizeitbetätigungen, wenn der Vertrag für die Erbringung einen spezifischen Termin oder Zeitraum vorsieht. Jede Buchung ist daher rechtsverbindlich und verpflichtet zur Abnahme und Bezahlung der Leistung.\n\nSofern eine Buchung im Einzelfall nicht unter § 312g Abs. 2 Nr. 9 BGB fallen sollte, gilt für Verbraucher: Sie haben das Recht, binnen vierzehn Tagen ab Vertragsschluss diesen Vertrag ohne Angabe von Gründen zu widerrufen. Der Widerruf ist zu richten an: Markus Jankowski, Büttmecker Weg 35c, 58675 Hemer (oder per E-Mail an die im Impressum genannte Adresse).\n\n8. Technikvermietung\nMietpreise gelten pro Miettag (24 Stunden); jeder Folgetag wird mit 50 % des Grundpreises berechnet. Der Mieter haftet für Verlust und Beschädigung der Mietsachen ab Übergabe bis zur Rückgabe.\n\n9. Zahlungsbedingungen\nRechnungen sind, sofern nicht anders vereinbart, innerhalb von 14 Tagen ohne Abzug zahlbar. Bei Buchungen kann eine Abschlagszahlung vereinbart werden.\n\n10. Schlussbestimmungen\nEs gilt deutsches Recht. Sollten einzelne Bestimmungen unwirksam sein, bleibt der Vertrag im Übrigen wirksam.\n\nStand: bitte nach juristischer Prüfung ergänzen.",
    ], JSON_UNESCAPED_UNICODE)],
  ] as [$k, $v]) $p->prepare('insert into site_content (key,value,updated_at) values (?,?,?)')->execute([$k, $v, now()]);

  $faqs = [
    [1,'Spielst du Musikwünsche?','Ja, klar – Wünsche von euch und euren Gästen gehören dazu. Vorab besprechen wir, was auf jeden Fall laufen soll und was gar nicht.'],
    [2,'Wie läuft die Buchung ab?','Anfrage über das Formular oder telefonisch, dann ein kurzes Kennenlerngespräch, ein klares Angebot – und mit eurer Bestätigung ist der Termin fest reserviert.'],
    [3,'Was passiert, wenn du krank wirst?','Dafür gibt es den Plan B, zu dem ich mich auch in meinen AGB verpflichte: Ich kümmere mich im Rahmen meiner Möglichkeiten um einen passenden Ersatz-DJ aus meinem Kollegen-Netzwerk und schlage ihn euch unverbindlich vor. Ihr entscheidet dann frei: den Kollegen buchen oder vom Vertrag zurücktreten – bereits gezahltes Geld bekommt ihr zurück. Fair und transparent: Der Ersatz-DJ rechnet zu seinen eigenen Preisen ab, Endpreis und Technikumfang können daher abweichen.'],
    [4,'Wie lange brauchst du für den Aufbau?','Je nach Technikumfang 60 bis 120 Minuten. Aufgebaut wird, bevor eure Gäste kommen – versprochen.'],
    [5,'Was ist, wenn du an unserem Termin schon ausgebucht bist?','Dann lasse ich euch nicht hängen: Ich wähle persönlich bis zu fünf Kollegen aus meinem Partner-Netzwerk aus, die zu eurer Feier passen – und ihr bekommt die Vorschläge direkt von mir. Für eine erfolgreiche Vermittlung erhalte ich eine kleine Provision, das steht so auch transparent in den AGB.'],
  ];
  foreach ($faqs as [$s,$q,$a]) $ins('faq', ['sort'=>$s,'question'=>$q,'answer'=>$a,'public'=>1]);

  $packs = [
    [1,'Hochzeit','Vom Sektempfang bis zum letzten Song','["Kennenlerngespräch & Musikplanung","Ton für die freie Trauung","Dezentes Licht passend zur Location","Plan B & Backup-Technik inklusive"]'],
    [2,'Geburtstag & private Feier','Eure Party, euer Sound','["Musik nach euren Wünschen","Profi-PA (Seeburg Acoustic Line)","Licht, das zur Stimmung passt"]'],
    [3,'Firmenfeier','Vom Empfang bis zur Party souverän','["Hintergrundmusik & Partyprogramm","Mikrofon & Ton für Reden","Enge Abstimmung mit eurer Planung"]'],
  ];
  foreach ($packs as [$s,$t,$sub,$f])
    $ins('packages', ['sort'=>$s,'title'=>$t,'subtitle'=>$sub,'features'=>$f,'public'=>1]);

  $ins('equipment', ['sort'=>1,'name'=>'Nebelmaschine klein','slug'=>'nebelmaschine-klein','category'=>'Effekt',
    'description'=>'Kompakte Nebelmaschine inkl. Fluid – ideal für Partykeller und kleine Räume.','day_rate'=>25,'qty_total'=>1]);

  /* Echte Kundenstimmen (Original-Zitate von der bisherigen Website) */
  $reviews = [
    [1,'Bastian D.','Hochzeit','Für uns fühlte es sich am Ende so an, als würde ein guter Freund auflegen. Markus hat es geschafft, alle abzuholen – von Death Metal über Pop bis Hardstyle. Die Tanzfläche war nie leer. Was ihn wirklich besonders macht, ist seine ruhige, unaufgeregte Art. Er stellt sich nicht selbst in den Mittelpunkt, sondern liest den Raum und spielt genau den richtigen Song.','Juni 2025'],
    [2,'Roman & Kathi D.','Hochzeit','Egal ob Rock, Schlager oder regionale Lieder – perfekte musikalische Begleitung. Du hast unseren Musikgeschmack sofort erkannt und jeden Wunsch mit tollem Gespür umgesetzt. Besonders die regionalen Songs haben uns begeistert und dem Abend eine ganz persönliche Note gegeben.','Mai 2025'],
    [3,'Kerstin & Tiago R.','Hochzeit','From the first call until the end of the evening, everything went smoothly. We had an international crowd and it surely wasn\'t easy to please everyone. Markus did very well, got everyone dancing and we had an incredible evening. Thank you so much!','Juli 2024'],
    [4,'Gesine F.','60. Geburtstag','Aus meinen umfangreichen Playlists eine schöne, abwechslungsreiche Auswahl – danke! Coole Übergänge, dezent im Hintergrund ohne nervige Ansprachen, genau so wie gewünscht. Ein ganz großartiger Abend.','September 2025'],
    [5,'Jan G.-K.','Hochzeit & Hoffest','Bis 5 Uhr durchgetanzt – einfach top! Er hat die Leute perfekt dort abgeholt, wo wir nach einer schon sehr starken Liveband waren. Nahtloser Übergang und dann nochmal Vollgas bis zum Morgen.','Juni 2023'],
    [6,'Nicole R.','Firmenfeier','Musik und Beleuchtung haben enorm zum Gelingen beigetragen. Alles hat super funktioniert, besonders die Beleuchtung unserer Produktionsstätten war ein echtes Highlight. Klare Weiterempfehlung!','November 2023'],
  ];
  foreach ($reviews as [$s,$author,$type,$text,$date])
    $ins('reviews', ['sort'=>$s,'author'=>$author,'event_type'=>$type,'text'=>$text,'rating'=>5,'source'=>'direkt','review_date'=>$date,'public'=>1]);

  /* Lieblingslocations (nur Fakten, die sicher stimmen – keine erfundenen Technik-/Ausstattungsdetails) */
  $locs = [
    [1,'Romantikhotel Neuhaus','Iserlohn','Sauerland / NRW',
      'Vier-Sterne-Haus mit einem der schönsten Ballsäle der Region. Bis 150 Gäste, meist Hochzeiten und runde Geburtstage.', 'https://www.hotel-neuhaus.de'],
    [2,'Ufer 39','Konstanz','Bodensee / Baden-Württemberg',
      'Restaurant direkt am Bodensee mit offener Seeterrasse. Bis 130 Gäste, vor allem Hochzeiten und Firmenfeiern.', 'https://ufer39.de'],
    [3,'Wirtshaus Krämer','Dortmund','Ruhrgebiet / NRW',
      'Rustikale Location mit viel Charakter. Bis 120 Gäste, Hochzeiten und Geburtstage.', ''],
    [4,'Waldenburger Hafen am Biggesee','Attendorn','Sauerland / NRW',
      'Naturkulisse direkt am Biggesee, variabel indoor und outdoor. Vor allem Hochzeiten und Sommerfeste.', ''],
    [5,'Gut Kump','Hamm','Westfalen / NRW',
      'Historischer Gutshof mit drei unterschiedlichen Räumen: Festscheune, Saal und Gewölbekeller. Bis 150 Gäste, Hochzeiten und Geburtstage.', 'https://www.gut-kump.de'],
    [6,'Danzturm','Iserlohn','Sauerland / NRW',
      'Bekannte Eventlocation direkt in meiner Heimatstadt. Hochzeiten und Firmenfeiern.', ''],
    [7,'Gut Bardenhagen','Bienenbüttel','Lüneburger Heide / Niedersachsen',
      'Ehemaliges Trabergestüt mit hellem Arkadensaal für bis zu 200 Gäste und Außentrauungen auf weitläufigem Gelände. Vor allem Hochzeiten.', 'https://www.gut-bardenhagen.de'],
    [8,'Stapelskotten','Münster','Münsterland / NRW',
      'Restaurant an der Aa mit gemütlichem Innenbereich und offener Wasserlage draußen. Hochzeiten, Geburtstage und Firmenfeiern.', 'https://www.stapelskotten.de'],
    [9,'Remise by Haus Delecke','Möhnesee','Sauerland / NRW',
      'Modernisierte Remise, samstags exklusiv für eine Feier buchbar. Hochzeiten und Firmenfeiern.', 'https://www.haus-delecke.de/remise/'],
    [10,'Speisekammer','Dortmund','Ruhrgebiet / NRW',
      'Gemütliche Location mit warmer Atmosphäre, Platz für bis zu 80 Gäste. Hochzeiten, Geburtstage und Familienfeiern.', 'https://www.speisekammer-dortmund.com'],
  ];
  foreach ($locs as [$s,$name,$city,$region,$desc,$url])
    $ins('locations', ['sort'=>$s,'name'=>$name,'city'=>$city,'region'=>$region,'description'=>$desc,'website'=>$url,'public'=>1]);

  /* E-Mail-Antwortvorlagen – Platzhalter: {vorname} {name} {datum} {anlass} {ort} */
  $tpls = [
    [1, 'Hochzeit – Erstantwort', 'Eure Hochzeit am {datum} – Rückmeldung von DJ Lauschgift',
"Hallo {vorname},

vielen Dank für eure Anfrage – wie schön, dass ihr heiratet!

Die gute Nachricht zuerst: Euer Termin am {datum} ist bei mir aktuell noch frei, und ich halte ihn euch die nächsten Tage unverbindlich fest.

Damit ich euch ein passendes Angebot machen kann, würde ich euch gerne kurz kennenlernen – am einfachsten telefonisch (15–20 Minuten reichen völlig). Dabei klären wir:
– den groben Ablauf eures Tages (freie Trauung? Sektempfang? Party bis wann?)
– eure Location und die Gästezahl
– eure Musikrichtung – und was auf keinen Fall laufen darf

Danach bekommt ihr von mir ein Angebot mit klaren Posten für Dauer und Technik. Keine versteckten Kosten, versprochen.

Wann erreiche ich euch am besten? Oder ruft mich einfach direkt an.

Viele Grüße
Markus Jankowski – DJ Lauschgift

PS: Was andere Paare über ihre Feier mit mir sagen, lest ihr hier: {bewertungen}"],
    [2, 'Geburtstag / private Feier – Erstantwort', 'Eure Feier am {datum} – Rückmeldung von DJ Lauschgift',
"Hallo {vorname},

danke für eure Anfrage – klingt nach einer richtig guten Party!

Euer Wunschtermin am {datum} ist bei mir aktuell noch frei. Kleiner Tipp vorab: Falls eure Feier tagsüber oder unter der Woche stattfindet, kann ich deutlich günstiger kalkulieren – das besprechen wir gerne im Detail.

Am einfachsten telefonieren wir einmal kurz (15 Minuten reichen), dann klären wir Location, Gästezahl, Uhrzeiten und eure Musikrichtung – und ihr bekommt direkt danach ein klares Angebot.

Wann passt es euch am besten?

Viele Grüße
Markus Jankowski – DJ Lauschgift

PS: Was andere über ihre Feier mit mir sagen, lest ihr hier: {bewertungen}"],
    [3, 'Firmenfeier – Erstantwort', 'Ihre Veranstaltung am {datum} – Rückmeldung von DJ Lauschgift',
"Guten Tag {name},

vielen Dank für Ihre Anfrage zu Ihrer Firmenveranstaltung am {datum}.

Der Termin ist bei mir aktuell noch verfügbar. Gerne stimme ich mich kurz mit Ihnen (oder Ihrer Eventplanung) zum Ablauf ab – vom dezenten Empfang über Ton für Redebeiträge bis zum Partyprogramm. Auf dieser Basis erhalten Sie ein transparentes Angebot mit klar ausgewiesenen Posten für Dauer und Technik.

Für Veranstaltungen unter der Woche oder tagsüber kalkuliere ich übrigens spürbar günstiger.

Wann darf ich Sie am besten anrufen?

Mit freundlichen Grüßen
Markus Jankowski – DJ Lauschgift

PS: Stimmen bisheriger Kunden finden Sie hier: {bewertungen}"],
    [4, 'Technik-Anfrage – Erstantwort', 'Eure Technik-Anfrage – Lauschgift Veranstaltungstechnik',
"Hallo {vorname},

danke für eure Anfrage!

Kurz zu den Konditionen: Ein Miettag entspricht 24 Stunden ab Übergabe, jeder weitere Tag kostet 50 % des Grundpreises. Abholung nach Terminabsprache an meinem Lager in Hemer (mit kurzer Einweisung) – auf Wunsch liefere ich auch, baue auf und wieder ab.

Damit ich euch Verfügbarkeit und Preis nennen kann, brauche ich nur noch:
– den genauen Zeitraum (Abholung/Rückgabe bzw. Veranstaltungsdatum)
– welche Geräte ihr braucht – oder was ihr vorhabt, dann berate ich euch
– ob ihr Lieferung/Aufbau wünscht (dann bitte Ort angeben)

Viele Grüße
Markus Jankowski – Lauschgift Veranstaltungstechnik"],
    [6, 'Nach der Feier – Danke & Bewertung',
     'Danke für eure Feier am {datum}!',
"Hallo {vorname},

was für ein Abend! Vielen Dank, dass ich eure Feier begleiten durfte – ihr wart ein großartiges Publikum, und ich hoffe, ihr habt genauso viel Spaß gehabt wie ich.

Eine kleine Bitte zum Schluss: Bewertungen sind für mich als selbstständigen DJ das Wichtigste überhaupt – sie entscheiden darüber, ob andere Paare und Gastgeber mich finden. Wenn ihr zwei Minuten Zeit habt, würde ich mich riesig über ein paar ehrliche Zeilen freuen:

{bewertungen}

Und falls euch später noch etwas einfällt (Fotos, Fragen oder die nächste Feier): Meldet euch jederzeit.

Viele Grüße und alles Gute
Markus Jankowski – DJ Lauschgift"],
    [5, 'Termin belegt – DJ-Vermittlung', bandeMailSubject(), bandeMailBody()],
  ];
  foreach ($tpls as [$s,$n,$sub,$b])
    $ins('email_templates', ['sort'=>$s,'name'=>$n,'subject'=>$sub,'body'=>$b]);

  seedFormTemplates($p);
  seedUpsells($p);
  seedProducts($p);

  /* Beispiel-Location als Vorlage – erst nach Bearbeitung auf 'öffentlich' stellen */
  $ins('locations', ['sort'=>1,'name'=>'Beispiel-Location (bitte ersetzen)','city'=>'Musterstadt','region'=>'NRW',
    'description'=>'Kurz beschreiben, warum du dort so gerne auflegst und was das Team besonders gut macht.',
    'website'=>'','public'=>0]);
}

function seedFormTemplates(PDO $p): void {
  $tpls = [
    [1, 'DJ-Vorauswahl für eure Feier',
     "Damit ich euch nicht irgendwelche, sondern wirklich passende DJs vorschlagen kann, beantwortet mir bitte kurz diese Fragen – dauert keine 5 Minuten. Die Vorschläge bekommt ihr danach direkt von mir. Und keine Sorge: Ihr bucht hier noch nichts. Vor einer Buchung führt ihr mit eurem Wunsch-DJ in Ruhe ein persönliches Infogespräch – das solltet ihr auch unbedingt tun. Dort klärt ihr alle Details wie Preis, Ablauf und Technik direkt miteinander.",
     [
       ['label'=>'Anlass eurer Feier','type'=>'select','options'=>['Hochzeit','Geburtstag','Firmenfeier','Sonstiges']],
       ['label'=>'Datum der Feier','type'=>'text'],
       ['label'=>'Location & Ort (Name reicht)','type'=>'text'],
       ['label'=>'Eure vollständige Anschrift (Straße, PLZ, Ort) – wird für die Vermittlung benötigt','type'=>'text'],
       ['label'=>'Ungefähre Gästezahl','type'=>'text'],
       ['label'=>'Welche Musik hört ihr besonders gern? (Richtungen, Künstler, Lieblingslieder – was auf jeden Fall laufen soll)','type'=>'textarea'],
       ['label'=>'Und was mögt ihr überhaupt nicht? (darf auf keinen Fall laufen)','type'=>'textarea'],
       ['label'=>'Wie soll euer DJ auftreten?','type'=>'select','options'=>['Zurückhaltend im Hintergrund','Moderiert & animiert aktiv','Mischung aus beidem','Egal, Hauptsache gute Musik']],
       ['label'=>'Sonst noch etwas, das der DJ wissen sollte?','type'=>'textarea'],
       ['label'=>'Ich bin einverstanden, dass meine Angaben zur DJ-Vermittlung an die Partner-Agentur DJ Bande (Münster) weitergegeben werden.','type'=>'checkbox'],
     ]],
    [2, 'Hochzeits-Planungsbogen',
     "Je besser ich eure Feier kenne, desto besser wird der Abend. Nehmt euch ein paar Minuten – es lohnt sich.",
     [
       ['label'=>'Wie läuft euer Tag grob ab? (Trauung, Empfang, Essen, Party …)','type'=>'textarea'],
       ['label'=>'Gibt es eine freie Trauung, die Ton braucht?','type'=>'select','options'=>['Ja','Nein']],
       ['label'=>'Euer Lied für den ersten Tanz','type'=>'text'],
       ['label'=>'Musikwünsche – was soll unbedingt laufen?','type'=>'textarea'],
       ['label'=>'No-Gos – was darf auf keinen Fall laufen?','type'=>'textarea'],
       ['label'=>'Gibt es Programmpunkte, die ich musikalisch begleiten soll? (Einzug, Tortenanschnitt …)','type'=>'textarea'],
       ['label'=>'Ansprechpartner am Abend (Name & Handynummer)','type'=>'text'],
     ]],
  ];
  foreach ($tpls as [$s,$n,$i,$f]) {
    $p->prepare('insert into form_templates (id,sort,name,intro,fields) values (?,?,?,?,?)')
      ->execute([uuid(), $s, $n, $i, json_encode($f, JSON_UNESCAPED_UNICODE)]);
  }
}

function seedProducts(PDO $p): void {
  $rows = [
    ['DJ-100','DJ-Leistung','DJ-Abend Basis','DJ-Leistung bis 6 Stunden inkl. Musikplanung, Kennenlerngespräch, kompakter Ton- und Lichttechnik, Auf- und Abbau.','pausch.',1200],
    ['DJ-110','DJ-Leistung','Zusätzliche DJ-Stunde','Verlängerung über den vereinbarten Zeitraum hinaus, je angefangene Stunde.','Std.',100],
    ['TON-200','Ton','Ton für freie Trauung','Funkmikrofon und Lautsprecher für Trauredner sowie Musik-Einspielungen im Außenbereich, inkl. Backup-Akku.','pausch.',200],
    ['LICHT-300','Licht','Ambiente-Licht Basis','Dezentes Grundlicht passend zur Location (Uplights, Tanzflächenlicht).','pausch.',150],
    ['FAHRT-900','Nebenkosten','Anfahrt','Anfahrtspauschale je gefahrenem Kilometer (Hin- und Rückweg).','km',0.70],
  ];
  $ids = [];
  foreach ($rows as [$sku,$cat,$n,$d,$u,$pr]) {
    $id = uuid(); $ids[$sku] = $id;
    $p->prepare('insert into products (id,sku,sort,category,name,description,unit,price_net,bundle,created_at) values (?,?,?,?,?,?,?,?,?,?)')
      ->execute([$id,$sku,count($ids),$cat,$n,$d,$u,$pr,'[]',now()]);
  }
  /* Beispiel-Bundle: eine Position aus mehreren Artikeln */
  $bundle = json_encode([
    ['sku'=>'DJ-100','qty'=>1],['sku'=>'TON-200','qty'=>1],['sku'=>'LICHT-300','qty'=>1],
  ]);
  $p->prepare('insert into products (id,sku,sort,category,name,description,unit,price_net,bundle,created_at) values (?,?,?,?,?,?,?,?,?,?)')
    ->execute([uuid(),'PAKET-500',9,'Pakete','Hochzeit Komplett','Rundum-sorglos: DJ-Abend Basis, Ton für die freie Trauung und Ambiente-Licht – als eine Position im Angebot.','pausch.',1450,$bundle,now()]);
}

function seedUpsells(PDO $p): void {
  $ups = [
    [1,'Spiegelkugel-Paket','Die echte Spiegelkugel über der Tanzfläche, angestrahlt von Spots – Gänsehaut-Garantie beim ersten Tanz und auf jedem Partyfoto.',249,'Hochzeit, runder Geburtstag'],
    [2,'Tonanlagen-Upgrade XL','Mehr Druck und satter Klang: die größere PA-Stufe für über 100 Gäste, große Säle oder Open-Air.',149,'Firmenfeier, große Hochzeit'],
    [3,'Ambiente-Licht XL','Der ganze Raum in eurer Wunschfarbe: zusätzliche Uplights setzen Wände und Details in Szene – dezent, nicht Disco.',129,'Hochzeit, Firmenfeier'],
    [4,'Auf Wolken tanzen','Bodennebel-Effekt für den ersten Tanz – ihr schwebt auf einer Wolke, eure Gäste zücken die Handys.',99,'Hochzeit'],
  ];
  foreach ($ups as [$s,$t,$d,$pr,$o])
    $p->prepare('insert into upsells (id,sort,title,description,price_net,occasions,created_at) values (?,?,?,?,?,?,?)')
      ->execute([uuid(),$s,$t,$d,$pr,$o,now()]);
}

/* ---------- Auth ---------- */
function currentUser(): ?array {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+([a-f0-9]{64})/i', $h, $m)) return null;
  $st = db()->prepare('select u.* from tokens t join users u on u.id=t.user_id where t.token=? and t.expires>?');
  $st->execute([$m[1], time()]);
  return $st->fetch() ?: null;
}
function handleLogin(array $body): never {
  $email = strtolower(trim($body['email'] ?? ''));
  $pass  = (string)($body['password'] ?? '');
  if (!$email || !$pass) fail('E-Mail und Passwort erforderlich.', 400);
  $p = db();
  $count = (int)$p->query('select count(*) from users')->fetchColumn();
  if ($count === 0) {
    /* Erstinstallation: erster Login legt den Admin-Account an */
    if (strlen($pass) < 8) fail('Erst-Setup: Passwort muss mindestens 8 Zeichen haben.', 400);
    $p->prepare('insert into users (id,email,pass_hash,created_at) values (?,?,?,?)')
      ->execute([uuid(), $email, password_hash($pass, PASSWORD_DEFAULT), now()]);
  }
  $st = $p->prepare('select * from users where email=?'); $st->execute([$email]);
  $u = $st->fetch();
  if (!$u || !password_verify($pass, $u['pass_hash'])) {
    usleep(400000); fail('Anmeldung fehlgeschlagen.', 401);
  }
  $tok = bin2hex(random_bytes(32));
  $p->prepare('delete from tokens where expires<?')->execute([time()]);
  $p->prepare('insert into tokens (token,user_id,expires) values (?,?,?)')
    ->execute([$tok, $u['id'], time() + TOKEN_TTL]);
  out(['access_token' => $tok, 'email' => $u['email'], 'first_run' => $count === 0]);
}

/* ---------- Wert-Konvertierung ---------- */
function decodeRow(string $t, array $r): array {
  foreach (JSON_COLS[$t] ?? [] as $c)
    if (isset($r[$c]) && is_string($r[$c])) $r[$c] = json_decode($r[$c], true);
  foreach (BOOL_COLS[$t] ?? [] as $c)
    if (array_key_exists($c, $r)) $r[$c] = (bool)$r[$c];
  return $r;
}
function encodeVal(string $t, string $c, $v) {
  if (in_array($c, JSON_COLS[$t] ?? [])) return json_encode($v, JSON_UNESCAPED_UNICODE);
  if (is_bool($v)) return $v ? 1 : 0;
  return $v;
}
function tableCols(string $t): array {
  static $cache = [];
  return $cache[$t] ??= array_column(db()->query("pragma table_info($t)")->fetchAll(), 'name');
}

/* ---------- PostgREST-Teilmenge ---------- */
function parseFilters(string $t, array $q): array {
  $where = []; $args = []; $order = ''; $embed = [];
  foreach ($q as $k => $v) {
    if ($k === 'select') {
      if (preg_match_all('/(\w+)\(\*\)/', $v, $m)) $embed = $m[1];
      continue;
    }
    if ($k === 'order') {
      $parts = [];
      foreach (explode(',', $v) as $o) {
        $seg = explode('.', $o);
        $col = preg_replace('/\W/', '', $seg[0]);
        if (!in_array($col, tableCols($t))) continue;
        $parts[] = $col . (in_array('desc', $seg) ? ' desc' : ' asc');
      }
      if ($parts) $order = ' order by ' . implode(',', $parts);
      continue;
    }
    if (!in_array($k, tableCols($t))) continue;
    if (preg_match('/^eq\.(.*)$/s', (string)$v, $m)) {
      $val = $m[1];
      if ($val === 'true') $val = 1; elseif ($val === 'false') $val = 0;
      $where[] = "\"$k\"=?"; $args[] = $val;
    }
  }
  return [$where, $args, $order, $embed];
}

function attachEmbeds(string $t, array $rows, array $embeds): array {
  foreach ($embeds as $e) {
    if ($e === 'customers' && in_array('customer_id', tableCols($t))) {
      $ids = array_values(array_unique(array_filter(array_column($rows, 'customer_id'))));
      $map = [];
      if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("select * from customers where id in ($in)"); $st->execute($ids);
        foreach ($st->fetchAll() as $c) $map[$c['id']] = decodeRow('customers', $c);
      }
      foreach ($rows as &$r) $r['customers'] = $map[$r['customer_id']] ?? null;
      unset($r);
    }
  }
  return $rows;
}

function handleRest(string $t, string $method, array $q, $body, array $prefer): never {
  if (!in_array($t, TABLES)) fail('Unbekannte Tabelle.', 404);
  $auth = currentUser() !== null;
  $p = db();

  /* Zugriffsregeln für nicht eingeloggte Aufrufer */
  if (!$auth) {
    if ($method === 'GET' && in_array($t, PUBLIC_READ)) {
      if ($t !== 'site_content') { $q['public'] = 'eq.true'; }
      if ($t === 'equipment') { $q['status'] = 'eq.aktiv'; }
    } elseif ($method === 'POST' && $t === 'inquiries') {
      $row = array_intersect_key(is_array($body) ? $body : [], array_flip(INQUIRY_FIELDS));
      if (empty($row['name'])) fail('Name erforderlich.', 400);
      /* E-Mail serverseitig validieren: verhindert, dass krude Zeichenketten gespeichert und
         später im Backoffice weiterverarbeitet werden (Defense-in-Depth gegen Attribut-Ausbruch). */
      if (!empty($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL))
        fail('Bitte eine gültige E-Mail-Adresse angeben.', 400);
      $row['id'] = uuid(); $row['status'] = 'neu'; $row['created_at'] = now();
      $cols = array_keys($row);
      $p->prepare("insert into inquiries (" . implode(',', $cols) . ") values (" .
        implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($row));
      notifyOwner('Neue Anfrage: ' . $row['name'] . ($row['event_type'] ?? '' ? ' – ' . $row['event_type'] : ''),
        "Name: {$row['name']}\nE-Mail: " . ($row['email'] ?? '–') . "\nTelefon: " . ($row['phone'] ?? '–') .
        "\nAnlass: " . ($row['event_type'] ?? '–') . "\nDatum: " . ($row['event_date'] ?? '–') .
        "\nOrt: " . ($row['location'] ?? '–') . "\n\n" . ($row['message'] ?? ''));
      /* Warme Eingangsbestätigung an den Interessenten – jeder soll sich sofort gut aufgehoben fühlen */
      if (!empty($row['email'])) {
        $comp = json_decode($p->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true);
        $vn = preg_split('/\s+/', trim($row['name']), 2)[0] ?? $row['name'];
        $waDigits = preg_replace('/\D/', '', (string)($comp['phone'] ?? ''));
        if ($waDigits !== '' && $waDigits[0] === '0') $waDigits = '49' . substr($waDigits, 1);
        sendMailSafe((string)$row['email'], 'Deine Anfrage ist angekommen',
          "Hallo $vn,\n\ndanke für deine Anfrage – sie ist sicher bei mir gelandet!\n\n" .
          "Ich melde mich persönlich bei dir, in der Regel innerhalb von 24 Stunden. " .
          "Das hier ist die einzige automatische Mail, die du von mir bekommst – ab jetzt schreibst du direkt mit mir.\n\n" .
          (($comp['phone'] ?? '') !== '' ?
            "Wenn es eilig ist, erreichst du mich unter " . $comp['phone'] . " – am schnellsten per WhatsApp:\n" .
            "https://wa.me/" . $waDigits . "\n\n" : '') .
          "Bis gleich!\n" . ($comp['owner'] ?? 'Markus'));
      }
      out(null, 201);
    } else fail('Nicht angemeldet.', 401);
  }

  [$where, $args, $order, $embeds] = parseFilters($t, $q);
  $wsql = $where ? ' where ' . implode(' and ', $where) : '';

  switch ($method) {
    case 'GET':
      $st = $p->prepare("select * from \"$t\"$wsql$order"); $st->execute($args);
      $rows = array_map(fn($r) => decodeRow($t, $r), $st->fetchAll());
      /* Ohne Login nur die Felder ausliefern, die die öffentliche Seite wirklich braucht -
         interne Vermerke, Partner-/Empfehlungspreise, Einkaufsbelege bleiben drin. */
      if (!$auth && $t === 'equipment') {
        $pubCols = ['id','sort','name','slug','category','description','image_url','image_focal',
          'day_rate','followup_pct','tier_week_pct','tier_2week_pct','tier_month_pct',
          'qty_total','rentable','public','status','addon_id','addon_ids','images','fits_ids','on_request','min_qty','placeholder'];
        foreach ($rows as &$r) $r = array_intersect_key($r, array_flip($pubCols));
        unset($r);
      }
      out(attachEmbeds($t, $rows, $embeds));

    case 'POST':
      $items = is_array($body) && array_is_list($body) ? $body : [$body];
      $merge = in_array('resolution=merge-duplicates', $prefer);
      $pk = PK[$t] ?? 'id';
      if ($t === 'document_items') {
        $docIds = array_values(array_unique(array_filter(array_map(fn($r) => is_array($r) ? ($r['document_id'] ?? null) : null, $items))));
        if ($docIds) {
          $in = implode(',', array_fill(0, count($docIds), '?'));
          $chk = $p->prepare("select number, doc_type, status from documents where id in ($in)");
          $chk->execute($docIds);
          foreach ($chk->fetchAll() as $d) if (docLockedRow($d))
            fail('Rechnung ' . $d['number'] . ' ist festgeschrieben (GoBD): Positionen können nicht mehr geändert werden.', 409);
        }
      }
      $result = [];
      foreach ($items as $row) {
        if (!is_array($row)) fail('Ungültiger Body.');
        $row = array_intersect_key($row, array_flip(tableCols($t)));
        if ($pk === 'id') $row['id'] ??= uuid();
        if (in_array('created_at', tableCols($t))) $row['created_at'] ??= now();
        if (in_array('updated_at', tableCols($t))) $row['updated_at'] = now();
        foreach ($row as $c => $v) $row[$c] = encodeVal($t, $c, $v);
        $cols = array_keys($row);
        $sql = "insert into \"$t\" (" . implode(',', array_map(fn($c) => "\"$c\"", $cols)) . ") values (" .
          implode(',', array_fill(0, count($cols), '?')) . ")";
        if ($merge) {
          $upd = implode(',', array_map(fn($c) => "\"$c\"=excluded.\"$c\"", array_diff($cols, [$pk])));
          $sql .= " on conflict(\"$pk\") do update set $upd";
        }
        try { $p->prepare($sql)->execute(array_values($row)); }
        catch (PDOException $e) { fail('Konflikt: ' . $e->getMessage(), 409); }
        if ($t === 'documents') docAudit($p, $row['id'] ?? null, 'erstellt', ($row['number'] ?? '') . ' (' . ($row['doc_type'] ?? '') . ')');
        $result[] = decodeRow($t, array_map(fn($v) => $v, $row));
      }
      if (in_array('return=representation', $prefer)) {
        /* frisch lesen, damit Defaults enthalten sind */
        $ids = array_column($result, $pk);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $p->prepare("select * from \"$t\" where \"$pk\" in ($in)"); $st->execute($ids);
        out(array_map(fn($r) => decodeRow($t, $r), $st->fetchAll()), 201);
      }
      out(null, 201);

    case 'PATCH':
      if (!$where) fail('PATCH ohne Filter verweigert.', 400);
      $row = array_intersect_key(is_array($body) ? $body : [], array_flip(tableCols($t)));
      if (!$row) fail('Nichts zu ändern.');
      /* GoBD: festgeschriebene Rechnungen – nur Status-/Versandfelder änderbar, Inhalte nie */
      if ($t === 'documents') {
        $chk = $p->prepare("select * from documents$wsql"); $chk->execute($args);
        $before = $chk->fetchAll();
        $allowed = ['status','paid_at','sent_at','share_token'];
        foreach ($before as $b) {
          if (docLockedRow($b) && array_diff(array_keys($row), $allowed))
            fail('Rechnung ' . $b['number'] . ' ist festgeschrieben (GoBD): Inhalte können nach dem Versand nicht mehr geändert werden. Erstelle eine Korrekturrechnung oder storniere sie.', 409);
        }
      }
      if ($t === 'document_items') assertItemsUnlocked($p, $wsql, $args);
      if (in_array('updated_at', tableCols($t))) $row['updated_at'] = now();
      foreach ($row as $c => $v) $row[$c] = encodeVal($t, $c, $v);
      $set = implode(',', array_map(fn($c) => "\"$c\"=?", array_keys($row)));
      $st = $p->prepare("update \"$t\" set $set$wsql");
      $st->execute(array_merge(array_values($row), $args));
      if ($t === 'documents' && !empty($before)) {
        foreach ($before as $b) {
          $changes = [];
          foreach ($row as $c => $vNew) {
            $old = $b[$c] ?? null;
            if ((string)$old !== (string)$vNew && $c !== 'updated_at') $changes[] = "$c: " . ($old ?? '–') . ' → ' . ($vNew ?? '–');
          }
          if ($changes) docAudit($p, $b['id'], 'geändert', $b['number'] . ' · ' . implode(', ', $changes));
        }
      }
      if (in_array('return=representation', $prefer)) {
        $st = $p->prepare("select * from \"$t\"$wsql"); $st->execute($args);
        out(array_map(fn($r) => decodeRow($t, $r), $st->fetchAll()));
      }
      out(null, 204);

    case 'DELETE':
      if (!$where) fail('DELETE ohne Filter verweigert.', 400);
      if ($t === 'documents') {
        $chk = $p->prepare("select * from documents$wsql"); $chk->execute($args);
        foreach ($chk->fetchAll() as $b) {
          if (docLockedRow($b))
            fail('Rechnung ' . $b['number'] . ' ist festgeschrieben (GoBD) und kann nicht gelöscht werden – bitte stornieren.', 409);
          docAudit($p, $b['id'], 'gelöscht', $b['number'] . ' (' . $b['doc_type'] . ', Entwurf)');
        }
      }
      if ($t === 'document_items') assertItemsUnlocked($p, $wsql, $args);
      try { $st = $p->prepare("delete from \"$t\"$wsql"); $st->execute($args); }
      catch (PDOException $e) { fail('Löschen nicht möglich (verknüpfte Daten): ' . $e->getMessage(), 409); }
      out(null, 204);
  }
  fail('Methode nicht unterstützt.', 405);
}

/* ---------- Kundenportal (öffentlich, Token-geschützt) ---------- */
function portalDoc(string $token, string $plz): array {
  $p = db();
  if (!preg_match('/^[a-f0-9]{24,64}$/', $token)) fail('Ungültiger Link.', 404);
  $st = $p->prepare('select d.*, c.first_name, c.last_name, c.company, c.zip
    from documents d join customers c on c.id = d.customer_id where d.share_token = ?');
  $st->execute([$token]);
  $d = $st->fetch();
  if (!$d) fail('Dieses Angebot wurde nicht gefunden oder der Link ist abgelaufen.', 404);
  if (trim($plz) === '' || trim($plz) !== trim((string)$d['zip'])) {
    usleep(500000);
    out(['need' => 'plz'], 401);
  }
  return $d;
}

function portalRental(string $token, string $plz): array {
  $p = db();
  if (!preg_match('/^[a-f0-9]{24,64}$/', $token)) fail('Ungültiger Link.', 404);
  $st = $p->prepare('select r.*, b.event_date, b.end_date, b.title,
      c.id as cust_id, c.first_name, c.last_name, c.company, c.street, c.zip, c.city
    from rental_contracts r join bookings b on b.id = r.booking_id
    join customers c on c.id = b.customer_id where r.token = ?');
  $st->execute([$token]);
  $r = $st->fetch();
  if (!$r) fail('Dieser Mietvertrag wurde nicht gefunden oder der Link ist abgelaufen.', 404);
  if (trim($plz) === '' || trim($plz) !== trim((string)$r['zip'])) {
    usleep(500000);
    out(['need' => 'plz'], 401);
  }
  return $r;
}
/* Abholung/Rückgabe (event_date/end_date) sind der Verleihzeitraum für die Verfügbarkeitsprüfung -
   das sind nicht zwangsläufig die abgerechneten Miettage (z.B. Freund holt Freitag ab, bringt erst
   Mittwoch zurück, berechnet wird trotzdem nur ein Miettag). billable_days überschreibt das, wenn gesetzt. */
function rentalDays(array $b): int {
  if (!empty($b['billable_days'])) return max(1, (int)$b['billable_days']);
  if (empty($b['end_date']) || $b['end_date'] <= $b['event_date']) return 1;
  return (int)round((strtotime($b['end_date']) - strtotime($b['event_date'])) / 86400) + 1;
}
/* Gestaffelter Mietpreis: Tag 1 voll, Tag 2-7 zu $followupPct, Tag 8-14 zu $weekPct,
   Tag 15-30 zu $twoWeekPct, ab Tag 31 zu $monthPct (jeweils % vom Tagespreis $rate). */
function rentalPrice(float $rate, int $days, float $followupPct, float $weekPct, float $twoWeekPct, float $monthPct): float {
  if ($days <= 1) return $rate;
  $total = $rate;
  $total += (min($days, 7) - 1) * $rate * $followupPct / 100;
  if ($days >= 8)  $total += (min($days, 14) - 7)  * $rate * $weekPct / 100;
  if ($days >= 15) $total += (min($days, 30) - 14) * $rate * $twoWeekPct / 100;
  if ($days >= 31) $total += ($days - 30) * $rate * $monthPct / 100;
  return $total;
}
/* Verfügbarkeit eines Artikels im Zeitraum (gegen alle nicht stornierten Buchungen). null = Artikel nicht gefunden/nicht mietbar. */
function equipmentAvailability(PDO $p, string $eq, string $from, string $to): ?array {
  $st = $p->prepare("select qty_total, on_request from equipment where id=? and public=1 and status='aktiv'");
  $st->execute([$eq]);
  $row = $st->fetch();
  if (!$row) return null;
  $total = (int)$row['qty_total'];
  $onRequest = (int)$row['on_request'] === 1;
  /* Anfrage-Artikel sind immer anfragbar (manuelle Bestätigung) - nicht als belegt melden. */
  if ($onRequest) return ['total' => $total, 'available' => $total, 'on_request' => true];
  $st = $p->prepare("select coalesce(sum(be.qty),0) from booking_equipment be
    join bookings b on b.id = be.booking_id
    where be.equipment_id = ? and b.status != 'storniert'
      and b.event_date <= ?
      and (b.open_ended = 1 or coalesce(b.end_date, b.event_date) >= ?)");
  $st->execute([$eq, $to, $from]);
  $used = (int)$st->fetchColumn();
  return ['total' => $total, 'available' => max(0, $total - $used), 'on_request' => false];
}
function rentalTierDefaults(PDO $p): array {
  $defs = json_decode((string)$p->query("select value from settings where key='defaults'")->fetchColumn() ?: '{}', true) ?: [];
  return [
    'week' => (float)($defs['tier_week_pct'] ?? 30),
    'twoweek' => (float)($defs['tier_2week_pct'] ?? 20),
    'month' => (float)($defs['tier_month_pct'] ?? 12),
  ];
}
function rentalItems(PDO $p, array $b): array {
  $days = rentalDays($b);
  $tiers = rentalTierDefaults($p);
  $st = $p->prepare('select be.qty, be.price_override, e.name, e.day_rate, e.followup_pct,
      e.tier_week_pct, e.tier_2week_pct, e.tier_month_pct
    from booking_equipment be join equipment e on e.id = be.equipment_id where be.booking_id = ?');
  $st->execute([$b['booking_id']]);
  $items = [];
  foreach ($st->fetchAll() as $x) {
    $base = (float)($x['day_rate'] ?? 0);
    $price = $x['price_override'] !== null ? (float)$x['price_override']
      : rentalPrice($base, $days, (float)($x['followup_pct'] ?? 50),
          (float)($x['tier_week_pct'] ?? $tiers['week']), (float)($x['tier_2week_pct'] ?? $tiers['twoweek']), (float)($x['tier_month_pct'] ?? $tiers['month'])
        ) * (int)$x['qty'];
    $items[] = ['name' => $x['name'], 'qty' => (int)$x['qty'], 'price' => round($price, 2)];
  }
  return $items;
}
/* data:image-URL prüfen und dekodieren (Unterschrift, Ausweisfotos) */
function decodeDataUrl(string $s, array $allowed, int $max): ?array {
  if (!preg_match('#^data:image/(png|jpeg|webp);base64,#', $s, $m)) return null;
  if (!in_array($m[1], $allowed)) return null;
  $bin = base64_decode(substr($s, strpos($s, ',') + 1), true);
  if ($bin === false || strlen($bin) > $max || strlen($bin) < 100) return null;
  if (@getimagesizefromstring($bin) === false) return null;
  return ['bin' => $bin, 'ext' => $m[1] === 'jpeg' ? 'jpg' : $m[1]];
}

/* Mail über den eigenen Server (All-Inkl: PHP mail() nutzt den Domain-Mailserver).
   Absender = Firmen-E-Mail aus den Einstellungen; ohne die wird nicht versendet. */
function sendMailSafe(string $to, string $subject, string $bodyText): bool {
  $comp = json_decode(db()->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true);
  $from = trim((string)($comp['email'] ?? ''));
  if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  $fromName = preg_replace('/[\r\n"]+/', '', (string)($comp['name'] ?? 'Lauschgift'));
  $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>\r\n" .
             "Reply-To: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
  return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $bodyText, $headers);
}

function baseUrl(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
  return ($https ? 'https' : 'http') . "://$host$dir";
}

/* Erstellt (einmalig) die Rechnung zu einer Workshop-Anmeldung und mailt den Portal-Link. */
function workshopInvoice(PDO $p, string $signupId): array {
  $st = $p->prepare('select s.*, w.title as w_title, w.event_date as w_date, w.price_net as w_price
    from workshop_signups s join workshop_events w on w.id = s.workshop_id where s.id = ?');
  $st->execute([$signupId]);
  $s = $st->fetch();
  if (!$s) return ['ok' => false, 'reason' => 'Anmeldung nicht gefunden.'];
  if ($s['invoice_id']) {
    $n = $p->prepare('select number from documents where id = ?'); $n->execute([$s['invoice_id']]);
    return ['ok' => true, 'number' => (string)$n->fetchColumn(), 'mailed' => false, 'existing' => true];
  }
  $price = (float)($s['w_price'] ?? 0);
  $seats = max(1, (int)$s['seats']);
  if ($price <= 0) return ['ok' => false, 'reason' => 'Kein Preis am Termin hinterlegt.'];
  $get = fn($k) => json_decode($p->query("select value from settings where key='" . $k . "'")->fetchColumn() ?: '{}', true);
  $comp = $get('company'); $defs = $get('defaults');

  /* Kunde finden oder anlegen */
  $cst = $p->prepare('select id, zip from customers where email = ? limit 1');
  $cst->execute([$s['email']]);
  $cust = $cst->fetch();
  $parts = preg_split('/\s+/', trim($s['name']), 2);
  if (!$cust) {
    $cid = uuid();
    $p->prepare('insert into customers (id, kind, status, first_name, last_name, email, phone, street, zip, city, source, created_at)
        values (?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$cid, 'privat', 'kunde', $parts[0] ?? '', $parts[1] ?? '', $s['email'], $s['phone'],
        $s['street'], $s['zip'], $s['city'], 'workshop', now()]);
    $custZip = (string)$s['zip'];
  } else {
    $cid = $cust['id'];
    $custZip = trim((string)$cust['zip']);
    if ($custZip === '' && trim((string)$s['zip']) !== '') {
      $p->prepare('update customers set zip = ?, street = coalesce(street, ?), city = coalesce(city, ?) where id = ?')
        ->execute([$s['zip'], $s['street'], $s['city'], $cid]);
      $custZip = (string)$s['zip'];
    }
  }

  /* Nummernkreis fortschreiben + Rechnung anlegen (atomar) */
  $p->beginTransaction();
  try {
    $numRow = $p->query("select value from settings where key='numbering'")->fetchColumn() ?: '{}';
    $num = json_decode($numRow, true) ?: [];
    $cfg = $num['rechnung'] ?? ['prefix' => 'RE-', 'next' => 1];
    $number = $cfg['prefix'] . (($num['year_in_number'] ?? true) ? gmdate('Y') . '-' : '') . str_pad((string)$cfg['next'], 4, '0', STR_PAD_LEFT);
    $cfg['next'] = (int)$cfg['next'] + 1;
    $num['rechnung'] = $cfg;
    $p->prepare("update settings set value = ?, updated_at = ? where key='numbering'")
      ->execute([json_encode($num, JSON_UNESCAPED_UNICODE), now()]);

    $small = !empty($comp['small_business']);
    $rate = $small ? 0.0 : (float)($defs['tax_rate'] ?? 19);
    $net = round($price * $seats, 2);
    $tax = round($net * $rate / 100, 2);
    $payDays = (int)($defs['payment_days'] ?? 14);
    $due = gmdate('Y-m-d', time() + $payDays * 86400);
    if ($s['w_date'] && $s['w_date'] > gmdate('Y-m-d') && $s['w_date'] < $due) $due = $s['w_date'];
    $dTitle = $s['w_title'] . ' am ' . $s['w_date'];
    $docId = uuid(); $token = bin2hex(random_bytes(24));
    $p->prepare('insert into documents (id, share_token, doc_type, number, customer_id, status, doc_date, due_date,
        tax_rate, is_small_business, intro_text, outro_text, total_net, total_tax, total_gross, created_at)
        values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$docId, $token, 'rechnung', $number, $cid, 'entwurf', gmdate('Y-m-d'), $due,
        $rate, $small ? 1 : 0,
        'vielen Dank für deine Anmeldung zum Workshop „' . $s['w_title'] . '“. Mit Zahlungseingang ist dein Platz verbindlich reserviert.',
        (string)($defs['invoice_outro'] ?? ''), $net, $tax, $net + $tax, now()]);
    $p->prepare('insert into document_items (id, document_id, pos, description, qty, unit, unit_price)
        values (?,?,?,?,?,?,?)')
      ->execute([uuid(), $docId, 1, 'Workshop: ' . $dTitle . ' – Teilnahme', $seats, $seats > 1 ? 'Plätze' : 'Platz', $price]);
    $p->prepare('update workshop_signups set invoice_id = ? where id = ?')->execute([$docId, $signupId]);
    docAudit($p, $docId, 'erstellt', $number . ' (rechnung, automatisch aus Workshop-Buchung)');
    $p->commit();
  } catch (Throwable $e) {
    $p->rollBack();
    return ['ok' => false, 'reason' => 'Rechnung konnte nicht erstellt werden.'];
  }

  /* Mail mit Portal-Link */
  $portal = baseUrl() . '/portal.html?a=' . $token;
  $bodyTxt = "Hallo " . ($parts[0] ?? $s['name']) . ",\n\n" .
    "danke für deine Anmeldung zum Workshop „" . $s['w_title'] . "“ am " . $s['w_date'] . "!\n\n" .
    "Hier ist deine Rechnung $number (" . number_format($net + $tax, 2, ',', '.') . " €):\n$portal\n" .
    "Login: deine Postleitzahl ($custZip). Dort kannst du die Rechnung ansehen und als PDF speichern.\n\n" .
    "Mit Zahlungseingang ist dein Platz verbindlich reserviert. Zahlbar bis $due per Überweisung – die Bankverbindung steht auf der Rechnung.\n\n" .
    "Bis bald im Workshop!\n" . ($comp['owner'] ?? '') . "\n" . ($comp['name'] ?? '') .
    ($comp['phone'] ?? '' ? "\n" . $comp['phone'] : '');
  $mailed = sendMailSafe((string)$s['email'], "Rechnung $number – dein Workshop-Platz am " . $s['w_date'], $bodyTxt);
  $p->prepare('update documents set status = ?, sent_at = ? where id = ?')
    ->execute([$mailed ? 'versendet' : 'entwurf', $mailed ? now() : null, $docId]);
  $p->prepare('insert into communications (id, customer_id, channel, direction, subject, content, occurred_at, created_at)
      values (?,?,?,?,?,?,?,?)')
    ->execute([uuid(), $cid, $mailed ? 'email' : 'note', 'out',
      'Workshop-Rechnung ' . $number . ($mailed ? ' automatisch versendet' : ' erstellt (Mailversand fehlgeschlagen – bitte manuell senden)'),
      'Workshop: ' . $dTitle . ' · ' . $seats . ' Platz/Plätze · ' . number_format($net + $tax, 2, ',', '.') . " €\nPortal-Link: $portal", now(), now()]);
  return ['ok' => true, 'number' => $number, 'mailed' => $mailed, 'portal' => $portal];
}

/* Benachrichtigung an den Inhaber (Firmen-E-Mail aus den Einstellungen) */
function notifyOwner(string $subject, string $body): bool {
  $comp = json_decode(db()->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true);
  $to = trim((string)($comp['email'] ?? ''));
  if ($to === '') return false;
  return sendMailSafe($to, $subject, $body . "\n\n– automatische Benachrichtigung deines Backoffice\n" . baseUrl() . "/admin.html");
}

/* ---------- Kalender-Feeds (iCal) ---------- */
function icalKey(): string {
  $p = db();
  $row = $p->query("select value from settings where key='ical'")->fetchColumn();
  $cfg = $row ? (json_decode($row, true) ?: []) : [];
  if (empty($cfg['key'])) {
    $cfg['key'] = bin2hex(random_bytes(16));
    $p->prepare("insert into settings (key, value, updated_at) values ('ical', ?, ?)
        on conflict(key) do update set value = excluded.value, updated_at = excluded.updated_at")
      ->execute([json_encode($cfg), now()]);
  }
  return $cfg['key'];
}
function icsEsc(string $s): string {
  return str_replace(["\\", "\n", ",", ";"], ["\\\\", "\\n", "\\,", "\\;"], $s);
}
function icsEvent(string $uid, string $summary, string $dateStart, ?string $dateEnd, ?string $t1, ?string $t2, string $desc, string $loc, bool $tentative): string {
  $out = "BEGIN:VEVENT\r\nUID:$uid@lauschgift\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
  if ($t1) {
    $d1 = str_replace('-', '', $dateStart) . 'T' . str_replace(':', '', substr($t1, 0, 5)) . '00';
    $endDate = $dateStart;
    if ($t2 && $t2 < $t1) $endDate = gmdate('Y-m-d', strtotime($dateStart) + 86400);   // über Mitternacht
    $d2 = str_replace('-', '', $endDate) . 'T' . str_replace(':', '', substr($t2 ?: $t1, 0, 5)) . '00';
    $out .= "DTSTART:$d1\r\nDTEND:$d2\r\n";
  } else {
    $end = gmdate('Ymd', strtotime(($dateEnd && $dateEnd >= $dateStart ? $dateEnd : $dateStart)) + 86400);
    $out .= 'DTSTART;VALUE=DATE:' . str_replace('-', '', $dateStart) . "\r\nDTEND;VALUE=DATE:$end\r\n";
  }
  $out .= 'SUMMARY:' . icsEsc($summary) . "\r\n";
  if ($loc !== '') $out .= 'LOCATION:' . icsEsc($loc) . "\r\n";
  if ($desc !== '') $out .= 'DESCRIPTION:' . icsEsc($desc) . "\r\n";
  if ($tentative) $out .= "STATUS:TENTATIVE\r\n";
  return $out . "END:VEVENT\r\n";
}
function serveIcal(string $typ): never {
  $p = db();
  $ev = '';
  $custName = fn($b) => trim((string)($b['company'] ?: trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))));
  if ($typ === 'anfragen') {
    $q = $p->query("select b.*, c.first_name, c.last_name, c.company, c.phone from bookings b
      join customers c on c.id = b.customer_id where b.status in ('anfrage','angebot')");
    foreach ($q->fetchAll() as $b)
      $ev .= icsEvent($b['id'], 'Anfrage: ' . ($b['title'] ?: $b['event_type'] ?: 'Feier') . ' (' . $b['status'] . ')',
        $b['event_date'], $b['end_date'], $b['start_time'], $b['end_time'],
        $custName($b) . ($b['phone'] ? ' · ' . $b['phone'] : '') . ($b['guests'] ? ' · ' . $b['guests'] . ' Gäste' : ''),
        trim(($b['venue_name'] ?? '') . ' ' . ($b['venue_address'] ?? '')), true);
    $qi = $p->query("select * from inquiries where status = 'neu' and event_date is not null and event_date != ''");
    foreach ($qi->fetchAll() as $i)
      $ev .= icsEvent('inq-' . $i['id'], 'Anfrage: ' . ($i['event_type'] ?: 'Feier') . ' – ' . $i['name'],
        $i['event_date'], null, null, null,
        trim(($i['email'] ?? '') . ' ' . ($i['phone'] ?? '')) . ($i['message'] ? ' · ' . mb_substr($i['message'], 0, 150) : ''),
        (string)($i['location'] ?? ''), true);
  } elseif ($typ === 'buchungen') {
    $q = $p->query("select b.*, c.first_name, c.last_name, c.company, c.phone from bookings b
      join customers c on c.id = b.customer_id
      where b.status in ('gebucht','abgeschlossen') and b.kind in ('dj','dj_technik')");
    foreach ($q->fetchAll() as $b) {
      $r = json_decode((string)($b['rider'] ?? ''), true) ?: [];
      $desc = $custName($b) . ($b['phone'] ? ' · ' . $b['phone'] : '') . ($b['guests'] ? ' · ' . $b['guests'] . ' Gäste' : '');
      if (!empty($r['setup_from'])) $desc .= ' · Aufbau ab ' . substr($r['setup_from'], 0, 5);
      if (!empty($r['contact_name'])) $desc .= ' · vor Ort: ' . $r['contact_name'] . (!empty($r['contact_phone']) ? ' ' . $r['contact_phone'] : '');
      $ev .= icsEvent($b['id'], 'DJ: ' . ($b['title'] ?: $b['event_type'] ?: 'Auftrag'),
        $b['event_date'], $b['end_date'], $b['start_time'], $b['end_time'], $desc,
        trim(($b['venue_name'] ?? '') . ' ' . ($b['venue_address'] ?? '')), false);
    }
    /* Private Blocker (Urlaub etc.) als Ganztagestermine mit in den Buchungs-Feed */
    foreach ($p->query('select * from calendar_blocks')->fetchAll() as $bl)
      $ev .= icsEvent('block-' . $bl['id'], 'Privat: ' . $bl['title'],
        $bl['start_date'], $bl['end_date'], null, null, (string)($bl['note'] ?? ''), '', false);
  } else {   // technik
    $q = $p->query("select b.*, c.first_name, c.last_name, c.company, c.phone from bookings b
      join customers c on c.id = b.customer_id
      where b.status in ('gebucht','abgeschlossen') and b.kind = 'technik'");
    foreach ($q->fetchAll() as $b) {
      $eq = $p->prepare('select be.qty, e.name from booking_equipment be join equipment e on e.id = be.equipment_id where be.booking_id = ?');
      $eq->execute([$b['id']]);
      $list = implode(', ', array_map(fn($x) => $x['qty'] . '× ' . $x['name'], $eq->fetchAll()));
      $ev .= icsEvent($b['id'], 'Vermietung: ' . ($b['title'] ?: 'Technik'),
        $b['event_date'], $b['end_date'], $b['start_time'], $b['end_time'],
        $custName($b) . ($b['phone'] ? ' · ' . $b['phone'] : '') . ($list ? ' · ' . $list : ''),
        trim(($b['venue_name'] ?? '') . ' ' . ($b['venue_address'] ?? '')), false);
    }
  }
  $names = ['anfragen' => 'Lauschgift · Anfragen', 'buchungen' => 'Lauschgift · Buchungen', 'technik' => 'Lauschgift · Vermietung'];
  header('Content-Type: text/calendar; charset=utf-8');
  header('Content-Disposition: inline; filename="' . $typ . '.ics"');
  echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Lauschgift//Backoffice//DE\r\n" .
    'X-WR-CALNAME:' . icsEsc($names[$typ]) . "\r\nX-PUBLISHED-TTL:PT30M\r\n" . $ev . "END:VCALENDAR\r\n";
  exit;
}

/* Täglicher Check (läuft mit dem Backup-Cron): Digest an den Inhaber */
function dailyDigest(): array {
  $p = db();
  $row = $p->query("select value from settings where key='digest'")->fetchColumn();
  $cfg = $row ? (json_decode($row, true) ?: []) : [];
  $today = gmdate('Y-m-d');
  if (($cfg['last'] ?? '') === $today) return ['skipped' => 'heute schon gelaufen'];
  $cfg['last'] = $today;
  $p->prepare("insert into settings (key, value, updated_at) values ('digest', ?, ?)
      on conflict(key) do update set value = excluded.value, updated_at = excluded.updated_at")
    ->execute([json_encode($cfg), now()]);
  $parts = [];
  $iq = $p->query("select name, event_type, created_at from inquiries where status = 'neu'
    and created_at < '" . gmdate('Y-m-d\TH:i:s\Z', time() - 86400) . "' order by created_at")->fetchAll();
  foreach ($iq as $i)
    $parts[] = 'Anfrage wartet seit ' . max(1, (int)floor((time() - strtotime((string)$i['created_at'])) / 86400)) .
      ' Tag(en) auf Antwort: ' . $i['name'] . ($i['event_type'] ? ' (' . $i['event_type'] . ')' : '');
  $od = $p->query("select count(*) c, coalesce(sum(total_gross - coalesce(deposit_deducted,0)),0) s from documents
    where doc_type not in ('angebot','lieferschein') and status = 'versendet' and due_date < '$today'")->fetch();
  if ((int)$od['c']) $parts[] = $od['c'] . ' überfällige Rechnung(en), zusammen ' . number_format((float)$od['s'], 2, ',', '.') . ' € – Zahlungserinnerung im Backoffice.';
  $wt = $p->query("select c.first_name, c.last_name, c.company, max(d.paid_at) lastpaid from document_items i
    join documents d on d.id = i.document_id join customers c on c.id = d.customer_id
    where i.description like '%Wartungsvertrag%' and d.status = 'bezahlt'
    group by d.customer_id having max(d.paid_at) < '" . gmdate('Y-m-d', time() - 330 * 86400) . "'")->fetchAll();
  foreach ($wt as $w)
    $parts[] = 'Wartung fällig: ' . trim(($w['company'] ?: trim($w['first_name'] . ' ' . $w['last_name']))) . ' (letzte bezahlte Wartung: ' . substr((string)$w['lastpaid'], 0, 10) .')';
  if (!$parts) return ['sent' => false, 'reason' => 'nichts zu melden'];
  $ok = notifyOwner('Dein Tages-Update: ' . count($parts) . ' Punkt(e)', implode("\n", $parts));
  return ['sent' => $ok, 'items' => count($parts)];
}

/* ---------- Backup ---------- */
function backupKey(): string {
  $p = db();
  $row = $p->query("select value from settings where key='backup'")->fetchColumn();
  $cfg = $row ? (json_decode($row, true) ?: []) : [];
  if (empty($cfg['key'])) {
    $cfg['key'] = bin2hex(random_bytes(16));
    $p->prepare("insert into settings (key, value, updated_at) values ('backup', ?, ?)
        on conflict(key) do update set value = excluded.value, updated_at = excluded.updated_at")
      ->execute([json_encode($cfg), now()]);
  }
  return $cfg['key'];
}
function runBackup(): array {
  $p = db();
  $dir = DATA_DIR . '/backups';
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  try { $p->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (PDOException $e) {}
  $name = 'dj-' . gmdate('Ymd-Hi') . '.sqlite.gz';
  $raw = file_get_contents(DB_FILE);
  if ($raw === false) return ['ok' => false, 'error' => 'Datenbank nicht lesbar.'];
  file_put_contents("$dir/$name", gzencode($raw, 6));
  /* Rotation: die letzten 14 Snapshots behalten */
  $files = glob("$dir/dj-*.sqlite.gz") ?: [];
  sort($files);
  $deleted = 0;
  while (count($files) > 14) { @unlink(array_shift($files)); $deleted++; }
  return ['ok' => true, 'file' => $name, 'size' => filesize("$dir/$name"), 'kept' => count($files), 'pruned' => $deleted];
}

/* Kundenkonto: eingeloggter Kunde aus Bearer-Token */
function custAuth(): ?array {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+([a-f0-9]{64})/i', $h, $m)) return null;
  $st = db()->prepare('select c.* from cust_tokens t join customers c on c.id = t.customer_id
    where t.token = ? and t.expires > ?');
  $st->execute([$m[1], time()]);
  return $st->fetch() ?: null;
}
function custToken(PDO $p, string $custId): string {
  $tok = bin2hex(random_bytes(32));
  $p->prepare('insert into cust_tokens (token, customer_id, expires) values (?,?,?)')
    ->execute([$tok, $custId, time() + 30 * 86400]);
  $p->prepare('delete from cust_tokens where expires < ?')->execute([time()]);
  return $tok;
}

function handlePortal(string $path, string $method, $body): never {
  $p = db();
  /* ---- Kundenkonto ---- */
  if ($path === 'portal/account/login' && $method === 'POST') {
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $pass = (string)($body['password'] ?? '');
    $st = $p->prepare('select * from customers where lower(email) = ? and portal_hash is not null');
    $st->execute([$email]);
    $c = $st->fetch();
    if (!$c || !password_verify($pass, (string)$c['portal_hash'])) { usleep(500000); fail('E-Mail oder Passwort falsch.', 401); }
    out(['token' => custToken($p, $c['id']), 'name' => trim(($c['company'] ?: trim($c['first_name'] . ' ' . $c['last_name'])))]);
  }
  /* Selbstregistrierung für den Mietpark-Warenkorb – Konto ist sofort nutzbar,
     eine Partner-Anmeldung (optional) durchläuft weiter die manuelle Freischaltung. */
  if ($path === 'portal/account/register' && $method === 'POST') {
    $name = trim((string)($body['name'] ?? ''));
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $pass = (string)($body['password'] ?? '');
    if ($name === '' || $email === '') fail('Name und E-Mail erforderlich.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Bitte eine gültige E-Mail-Adresse angeben.');
    if (strlen($pass) < 8) fail('Passwort bitte mit mindestens 8 Zeichen.');
    $st = $p->prepare('select id, portal_hash from customers where lower(email) = ?');
    $st->execute([$email]);
    $existing = $st->fetch();
    if ($existing && $existing['portal_hash'] !== null) fail('Für diese E-Mail existiert bereits ein Konto – bitte einloggen.', 409);
    $parts = preg_split('/\s+/', $name, 2);
    $first = $parts[0]; $last = $parts[1] ?? '';
    $phone = mb_substr(trim((string)($body['phone'] ?? '')), 0, 60);
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    if ($existing) {
      $custId = $existing['id'];
      $p->prepare('update customers set first_name=?, last_name=?, phone=?, portal_hash=?, updated_at=? where id=?')
        ->execute([$first, $last, $phone, $hash, now(), $custId]);
    } else {
      $custId = uuid();
      $p->prepare('insert into customers (id, kind, status, first_name, last_name, email, phone, portal_hash, source, created_at, updated_at)
        values (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$custId, 'privat', 'kunde', $first, $last, mb_substr($email,0,160), $phone, $hash, 'mietpark', now(), now()]);
    }
    if (!empty($body['partner_interest'])) {
      $kind = in_array($body['partner_kind'] ?? '', ['dj','band','musiker']) ? $body['partner_kind'] : 'dj';
      $p->prepare('insert into partners (id,name,company,kind,email,phone,status,created_at) values (?,?,?,?,?,?,?,?)')
        ->execute([uuid(), mb_substr($name,0,120), '', $kind, mb_substr($email,0,160), $phone, 'beantragt', now()]);
    }
    out(['token' => custToken($p, $custId), 'name' => $name], 201);
  }
  if ($path === 'portal/account/set_password' && $method === 'POST') {
    $inv = (string)($body['invite'] ?? '');
    $pass = (string)($body['password'] ?? '');
    if (!preg_match('/^[a-f0-9]{24,64}$/', $inv)) fail('Ungültiger Link.', 404);
    if (strlen($pass) < 8) fail('Passwort bitte mit mindestens 8 Zeichen.');
    $st = $p->prepare('select * from customers where portal_invite = ? and portal_invite_expires > ?');
    $st->execute([$inv, time()]);
    $c = $st->fetch();
    if (!$c) fail('Dieser Einladungslink ist abgelaufen – bitte einen neuen anfordern.', 404);
    $p->prepare('update customers set portal_hash = ?, portal_invite = null, portal_invite_expires = null where id = ?')
      ->execute([password_hash($pass, PASSWORD_DEFAULT), $c['id']]);
    out(['token' => custToken($p, $c['id']), 'email' => $c['email'],
      'name' => trim(($c['company'] ?: trim($c['first_name'] . ' ' . $c['last_name'])))], 201);
  }
  if ($path === 'portal/account/forgot' && $method === 'POST') {
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $st = $p->prepare('select * from customers where lower(email) = ? and portal_hash is not null');
    $st->execute([$email]);
    $c = $st->fetch();
    if ($c) {
      $inv = bin2hex(random_bytes(24));
      $p->prepare('update customers set portal_invite = ?, portal_invite_expires = ? where id = ?')
        ->execute([$inv, time() + 2 * 86400, $c['id']]);
      sendMailSafe((string)$c['email'], 'Neues Passwort für dein Kunden-Backoffice',
        "Hallo,\n\nüber diesen Link kannst du ein neues Passwort für dein Kunden-Backoffice setzen (48 Stunden gültig):\n" .
        baseUrl() . "/portal.html?einladung=$inv\n\nFalls du das nicht warst, kannst du diese Mail ignorieren.\n");
    }
    out(['ok' => true]);   // keine Auskunft, ob die Adresse existiert
  }
  if (str_starts_with($path, 'portal/account/')) {
    $me = custAuth();
    if (!$me) fail('Bitte einloggen.', 401);
    if ($path === 'portal/account/me' && $method === 'GET') {
      $bk = $p->prepare("select id, title, event_type, event_date, end_date, status, kind, customer_notes
        from bookings where customer_id = ? and status != 'storniert' order by event_date desc");
      $bk->execute([$me['id']]);
      $bookings = array_map(fn($b) => ['id' => $b['id'], 'title' => $b['title'] ?: $b['event_type'],
        'event_date' => $b['event_date'], 'end_date' => $b['end_date'], 'status' => $b['status'], 'kind' => $b['kind'],
        'notes' => json_decode((string)($b['customer_notes'] ?? ''), true) ?: (object)[]], $bk->fetchAll());
      $dq = $p->prepare("select id, share_token, doc_type, number, status, doc_date, total_gross
        from documents where customer_id = ? and status != 'entwurf' order by doc_date desc, created_at desc");
      $dq->execute([$me['id']]);
      $docs = [];
      foreach ($dq->fetchAll() as $d) {
        if (empty($d['share_token'])) {
          $d['share_token'] = bin2hex(random_bytes(24));
          $p->prepare('update documents set share_token = ? where id = ?')->execute([$d['share_token'], $d['id']]);
        }
        $docs[] = ['number' => $d['number'], 'doc_type' => $d['doc_type'], 'status' => $d['status'],
          'doc_date' => $d['doc_date'], 'total_gross' => $d['total_gross'], 'token' => $d['share_token']];
      }
      $rc = $p->prepare("select r.token, r.status, r.signed_at, b.event_date from rental_contracts r
        join bookings b on b.id = r.booking_id where b.customer_id = ?");
      $rc->execute([$me['id']]);
      $ff = $p->prepare('select id, booking_id, kind, name, size, created_at from customer_files where customer_id = ? order by created_at desc');
      $ff->execute([$me['id']]);
      out(['customer' => ['name' => trim(($me['company'] ?: trim($me['first_name'] . ' ' . $me['last_name']))), 'email' => $me['email']],
        'bookings' => $bookings, 'documents' => $docs, 'rentals' => $rc->fetchAll(), 'files' => $ff->fetchAll()]);
    }
    if (preg_match('#^portal/account/booking/([a-f0-9-]{30,40})/notes$#', $path, $m) && $method === 'POST') {
      $chk = $p->prepare('select id, title, event_type, event_date from bookings where id = ? and customer_id = ?');
      $chk->execute([$m[1], $me['id']]);
      $b = $chk->fetch();
      if (!$b) fail('Termin nicht gefunden.', 404);
      $notes = [
        'schedule' => mb_substr(trim((string)($body['schedule'] ?? '')), 0, 6000),
        'music' => mb_substr(trim((string)($body['music'] ?? '')), 0, 6000),
        'agreements' => mb_substr(trim((string)($body['agreements'] ?? '')), 0, 6000),
        'updated_at' => now(),
      ];
      $p->prepare('update bookings set customer_notes = ? where id = ?')
        ->execute([json_encode($notes, JSON_UNESCAPED_UNICODE), $m[1]]);
      $p->prepare('insert into communications (id, customer_id, booking_id, channel, direction, subject, content, occurred_at, created_at)
          values (?,?,?,?,?,?,?,?,?)')
        ->execute([uuid(), $me['id'], $m[1], 'note', 'in', 'Kunde hat Termindetails aktualisiert',
          'Termin ' . ($b['title'] ?: $b['event_type']) . ' am ' . $b['event_date'] . ' – Programmablauf/Musikwünsche/Vereinbarungen im Portal gepflegt.', now(), now()]);
      out(['ok' => true], 201);
    }
    if ($path === 'portal/account/upload' && $method === 'POST') {
      $raw = file_get_contents('php://input');
      if (!$raw || strlen($raw) > MAX_UPLOAD) fail('Datei fehlt oder ist zu groß (max. 8 MB).');
      $orig = mb_substr(preg_replace('/[^\w.\-() äöüÄÖÜß]/u', '_', (string)($_GET['name'] ?? 'datei')), 0, 120);
      $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      $isImg = @getimagesizefromstring($raw) !== false;
      $allowedDocs = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'];
      if (!$isImg && !in_array($ext, $allowedDocs)) fail('Erlaubt: Bilder sowie PDF/Office/Text-Dateien.');
      if (!$isImg && $ext === 'pdf' && !str_starts_with($raw, '%PDF')) fail('Keine gültige PDF-Datei.');
      $kind = $isImg ? 'foto' : 'dokument';
      if ($isImg) $raw = processImage($raw);
      $bookingId = (string)($_GET['booking'] ?? '');
      if ($bookingId !== '') {
        $chk = $p->prepare('select count(*) from bookings where id = ? and customer_id = ?');
        $chk->execute([$bookingId, $me['id']]);
        if (!(int)$chk->fetchColumn()) $bookingId = '';
      }
      $cnt = $p->prepare('select count(*) from customer_files where customer_id = ?');
      $cnt->execute([$me['id']]);
      if ((int)$cnt->fetchColumn() >= 60) fail('Maximal 60 Dateien pro Kunde – bitte alte Dateien löschen.');
      $dir = DATA_DIR . '/custfiles';
      if (!is_dir($dir)) mkdir($dir, 0755, true);
      $id = uuid();
      $file = $id . ($ext ? ".$ext" : ($isImg ? '.jpg' : ''));
      file_put_contents("$dir/$file", $raw);
      $p->prepare('insert into customer_files (id, customer_id, booking_id, kind, name, file, size, created_at) values (?,?,?,?,?,?,?,?)')
        ->execute([$id, $me['id'], $bookingId ?: null, $kind, $orig, $file, strlen($raw), now()]);
      out(['ok' => true, 'id' => $id, 'kind' => $kind, 'name' => $orig], 201);
    }
    if (preg_match('#^portal/account/file/([a-f0-9-]{30,40})$#', $path, $m)) {
      $st = $p->prepare('select * from customer_files where id = ? and customer_id = ?');
      $st->execute([$m[1], $me['id']]);
      $f = $st->fetch();
      if (!$f) fail('Datei nicht gefunden.', 404);
      $full = DATA_DIR . '/custfiles/' . $f['file'];
      if ($method === 'DELETE') {
        @unlink($full);
        $p->prepare('delete from customer_files where id = ?')->execute([$m[1]]);
        out(['ok' => true]);
      }
      if (!is_file($full)) fail('Datei nicht gefunden.', 404);
      $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
      $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv'][$ext] ?? 'application/octet-stream';
      header('Content-Type: ' . $mime);
      header('Content-Disposition: inline; filename="' . rawurlencode((string)$f['name']) . '"');
      readfile($full); exit;
    }
    fail('Unbekannter Konto-Endpunkt.', 404);
  }
  if (preg_match('#^portal/offer/([a-f0-9]+)$#', $path, $m) && $method === 'GET') {
    $d = portalDoc($m[1], (string)($_GET['plz'] ?? ''));
    $it = $p->prepare('select pos, description, note, qty, unit, unit_price, discount_value, discount_type from document_items where document_id = ? order by pos');
    $it->execute([$d['id']]);
    $comp = json_decode($p->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true);
    $ups = [];
    if ($d['doc_type'] === 'angebot' && !in_array($d['status'], ['angenommen','abgelehnt','storniert']))
      $ups = $p->query('select id, title, description, price_net from upsells
        where active=1 and show_portal=1 order by sort')->fetchAll();
    out([
      'doc' => array_intersect_key($d, array_flip(['doc_type','number','status','doc_date','valid_until','due_date',
        'tax_rate','is_small_business','intro_text','outro_text','total_net','total_tax','total_gross','deposit_deducted',
        'price_mode','discount_value','discount_type'])),
      'customer' => trim(($d['company'] ? $d['company'] : ($d['first_name'].' '.$d['last_name']))),
      'items' => $it->fetchAll(),
      'company' => array_intersect_key($comp, array_flip(['name','owner','phone','email','street','zip_city','iban','bic','bank','tax_id'])),
      'upsells' => $ups,
      'reviews' => json_decode($p->query("select value from site_content where key='reviews'")->fetchColumn() ?: '{}', true),
    ]);
  }
  if (preg_match('#^portal/offer/([a-f0-9]+)/action$#', $path, $m) && $method === 'POST') {
    $d = portalDoc($m[1], (string)($body['plz'] ?? ''));
    $kind = (string)($body['action'] ?? '');
    if (!in_array($kind, ['accept','decline','comment','callback','bande'])) fail('Unbekannte Aktion.');
    $msg = mb_substr(trim((string)($body['message'] ?? '')), 0, 4000);
    $phone = mb_substr(trim((string)($body['phone'] ?? '')), 0, 60);
    if ($kind === 'accept' && $d['status'] !== 'storniert') {
      $accName = mb_substr(trim((string)($body['name'] ?? '')), 0, 120);
      $sigRaw = (string)($body['signature'] ?? '');
      $sig = ($sigRaw !== '' && decodeDataUrl($sigRaw, ['png'], 400 * 1024)) ? $sigRaw : null;
      $p->prepare("update documents set status='angenommen', accepted_name=?, accept_signature=?, updated_at=? where id=?")
        ->execute([$accName ?: null, $sig, now(), $d['id']]);
      docAudit($p, $d['id'], 'angenommen', $d['number'] . ' – vom Kunden angenommen' . ($accName ? ' und unterschrieben: ' . $accName : '') . ' (Portal)');
    }
    if ($kind === 'decline' && $d['status'] !== 'storniert')
      $p->prepare("update documents set status='abgelehnt', updated_at=? where id=?")->execute([now(), $d['id']]);
    $p->prepare('insert into doc_events (id,document_id,kind,message,phone,created_at) values (?,?,?,?,?,?)')
      ->execute([uuid(), $d['id'], $kind, $msg, $phone, now()]);
    $labels = ['accept' => 'Angebot ANGENOMMEN', 'decline' => 'Angebot abgelehnt', 'comment' => 'Frage zum Angebot',
      'callback' => 'Rückruf gewünscht', 'bande' => 'DJ-Vermittlung gewünscht'];
    notifyOwner($labels[$kind] . ': ' . $d['number'],
      'Kunde: ' . trim(($d['company'] ?: $d['first_name'] . ' ' . $d['last_name'])) .
      "\nDokument: " . $d['number'] . ' über ' . number_format((float)$d['total_gross'], 2, ',', '.') . ' €' .
      ($msg !== '' ? "\n\nNachricht:\n$msg" : '') . ($phone !== '' ? "\nTelefon: $phone" : ''));
    out(['ok' => true], 201);
  }
  if (preg_match('#^portal/form/([a-f0-9]+)$#', $path, $m)) {
    if (!preg_match('/^[a-f0-9]{24,64}$/', $m[1])) fail('Ungültiger Link.', 404);
    $st = $p->prepare('select * from forms where token=?'); $st->execute([$m[1]]);
    $f = $st->fetch();
    if (!$f) fail('Dieser Fragebogen wurde nicht gefunden.', 404);
    if ($method === 'GET')
      out(['title'=>$f['title'],'intro'=>$f['intro'],'fields'=>json_decode($f['fields'],true),'done'=>$f['status']==='beantwortet']);
    if ($method === 'POST') {
      if ($f['status'] === 'beantwortet') fail('Dieser Fragebogen wurde bereits beantwortet.', 409);
      $answers = $body['answers'] ?? null;
      if (!is_array($answers)) fail('Antworten fehlen.');
      $answers = array_map(fn($a) => mb_substr(trim((string)$a), 0, 4000), $answers);
      $p->prepare("update forms set answers=?, status='beantwortet', submitted_at=? where id=?")
        ->execute([json_encode($answers, JSON_UNESCAPED_UNICODE), now(), $f['id']]);
      if ($f['customer_id']) {
        $fields = json_decode($f['fields'], true) ?: [];
        $sum = '';
        foreach ($fields as $i => $fl) $sum .= ($fl['label'] ?? ('Frage '.($i+1))).":\n".($answers[$i] ?? '–')."\n\n";
        $p->prepare('insert into communications (id,customer_id,channel,direction,subject,content,occurred_at,created_at)
          values (?,?,?,?,?,?,?,?)')
          ->execute([uuid(), $f['customer_id'], 'note', 'in', 'Fragebogen beantwortet: '.$f['title'], trim($sum), now(), now()]);
      }
      notifyOwner('Fragebogen beantwortet: ' . $f['title'], 'Die Antworten stehen in der Kunden-Timeline im Backoffice.');
      out(['ok' => true], 201);
    }
  }
  /* Digitaler Mietvertrag: ansehen, Ausweis hochladen, unterschreiben */
  if (preg_match('#^portal/rental/([a-f0-9]+)$#', $path, $m) && $method === 'GET') {
    $r = portalRental($m[1], (string)($_GET['plz'] ?? ''));
    $comp = json_decode($p->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true);
    $terms = json_decode($p->query("select value from settings where key='rental_contract'")->fetchColumn() ?: '{}', true);
    out([
      'status' => $r['status'], 'signed_at' => $r['signed_at'], 'signed_name' => $r['signed_name'],
      'customer' => ['name' => trim($r['company'] ?: trim($r['first_name'].' '.$r['last_name'])),
        'street' => $r['street'], 'zip_city' => trim($r['zip'].' '.$r['city'])],
      'company' => array_intersect_key($comp, array_flip(['name','owner','phone','email','street','zip_city'])),
      'booking' => ['title' => $r['title'], 'event_date' => $r['event_date'], 'end_date' => $r['end_date'],
        'days' => rentalDays($r)],
      'items' => rentalItems($p, $r),
      'terms' => (string)($terms['text'] ?? ''),
    ]);
  }
  if (preg_match('#^portal/rental/([a-f0-9]+)/sign$#', $path, $m) && $method === 'POST') {
    $r = portalRental($m[1], (string)($body['plz'] ?? ''));
    if ($r['status'] === 'unterschrieben') fail('Dieser Mietvertrag wurde bereits unterschrieben.', 409);
    if (empty($body['consent'])) fail('Bitte der Ausweiskopie zustimmen.');
    $name = mb_substr(trim((string)($body['signed_name'] ?? '')), 0, 120);
    if ($name === '') fail('Bitte den vollständigen Namen eintragen.');
    $sig = decodeDataUrl((string)($body['signature'] ?? ''), ['png'], 400 * 1024);
    if (!$sig) fail('Die Unterschrift fehlt oder ist ungültig.');
    $front = decodeDataUrl((string)($body['id_front'] ?? ''), ['jpeg','png','webp'], 3 * 1024 * 1024);
    $back  = decodeDataUrl((string)($body['id_back'] ?? ''),  ['jpeg','png','webp'], 3 * 1024 * 1024);
    if (!$front || !$back) fail('Bitte Vorder- und Rückseite des Ausweises fotografieren.');
    $dir = DATA_DIR . '/ids';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ff = $r['id'] . '-front.' . $front['ext'];
    $fb = $r['id'] . '-back.' . $back['ext'];
    file_put_contents("$dir/$ff", processImage($front['bin'], 1600, 82));
    file_put_contents("$dir/$fb", processImage($back['bin'], 1600, 82));
    $terms = json_decode($p->query("select value from settings where key='rental_contract'")->fetchColumn() ?: '{}', true);
    $snapshot = json_encode(['items' => rentalItems($p, $r), 'days' => rentalDays($r),
      'event_date' => $r['event_date'], 'end_date' => $r['end_date'],
      'terms' => (string)($terms['text'] ?? '')], JSON_UNESCAPED_UNICODE);
    $p->prepare("update rental_contracts set status='unterschrieben', signed_name=?, signature=?,
        id_front=?, id_back=?, signed_at=?, snapshot=? where id=?")
      ->execute([$name, (string)$body['signature'], $ff, $fb, now(), $snapshot, $r['id']]);
    $p->prepare('insert into communications (id,customer_id,booking_id,channel,direction,subject,content,occurred_at,created_at)
        values (?,?,?,?,?,?,?,?,?)')
      ->execute([uuid(), $r['cust_id'], $r['booking_id'], 'note', 'in', 'Mietvertrag digital unterschrieben',
        'Mietvertrag zur Buchung am '.$r['event_date'].' wurde online unterschrieben von: '.$name.'. Ausweiskopien (Vorder-/Rückseite) liegen geschützt im System.', now(), now()]);
    notifyOwner('Mietvertrag unterschrieben: ' . ($r['title'] ?: $r['event_date']),
      "Unterschrieben von: $name\nTermin: " . $r['event_date'] . "\nAusweiskopien liegen geschützt im System.");
    out(['ok' => true], 201);
  }
  /* Workshops: öffentliche Termine mit freien Plätzen, Anmeldung mit Kapazitätsprüfung */
  if ($path === 'portal/workshops' && $method === 'GET') {
    $rows = $p->query("select w.*, coalesce((select sum(s.seats) from workshop_signups s
        where s.workshop_id = w.id and s.status = 'angemeldet'), 0) as booked
      from workshop_events w where w.public = 1 and w.event_date >= date('now')
      order by w.event_date, w.start_time")->fetchAll();
    out(array_map(fn($w) => [
      'id' => $w['id'], 'title' => $w['title'], 'description' => $w['description'], 'audience' => $w['audience'] ?? '',
      'event_date' => $w['event_date'], 'start_time' => $w['start_time'], 'end_time' => $w['end_time'],
      'location' => $w['location'], 'price_net' => $w['price_net'],
      'free' => max(0, (int)$w['capacity'] - (int)$w['booked']),
    ], $rows));
  }
  if (preg_match('#^portal/workshops/([a-f0-9-]{30,40})/signup$#', $path, $m) && $method === 'POST') {
    $st = $p->prepare("select w.*, coalesce((select sum(s.seats) from workshop_signups s
        where s.workshop_id = w.id and s.status = 'angemeldet'), 0) as booked
      from workshop_events w where w.id = ? and w.public = 1");
    $st->execute([$m[1]]);
    $w = $st->fetch();
    if (!$w) fail('Dieser Workshop-Termin wurde nicht gefunden.', 404);
    $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 120);
    $email = mb_substr(trim((string)($body['email'] ?? '')), 0, 160);
    if ($name === '' || $email === '') fail('Name und E-Mail erforderlich.');
    $seats = max(1, min(5, (int)($body['seats'] ?? 1)));
    $free = max(0, (int)$w['capacity'] - (int)$w['booked']);
    $wantWaitlist = !empty($body['waitlist']);
    if ($seats > $free && !$wantWaitlist)
      fail($free ? ($free === 1 ? 'Für diesen Termin ist nur noch 1 Platz frei.' : "Für diesen Termin sind nur noch $free Plätze frei.") : 'Dieser Termin ist leider ausgebucht.', 409);
    $status = ($seats > $free) ? 'warteliste' : 'angemeldet';
    $street = mb_substr(trim((string)($body['street'] ?? '')), 0, 160);
    $zip = mb_substr(trim((string)($body['zip'] ?? '')), 0, 10);
    $city = mb_substr(trim((string)($body['city'] ?? '')), 0, 80);
    if ((float)($w['price_net'] ?? 0) > 0 && ($street === '' || $zip === '' || $city === ''))
      fail('Bitte Anschrift angeben (Straße, PLZ, Ort) – sie wird für die Rechnung benötigt.');
    $dup = $p->prepare("select count(*) from workshop_signups where workshop_id = ? and email = ? and status in ('angemeldet','warteliste')");
    $dup->execute([$w['id'], $email]);
    if ((int)$dup->fetchColumn()) fail('Mit dieser E-Mail-Adresse bist du für diesen Termin schon angemeldet bzw. auf der Warteliste.', 409);
    $sid = uuid();
    $p->prepare('insert into workshop_signups (id, workshop_id, name, email, phone, seats, message,
        q_music, q_challenge, q_goal, street, zip, city, status, created_at) values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$sid, $w['id'], $name, $email,
        mb_substr(trim((string)($body['phone'] ?? '')), 0, 60), $seats,
        mb_substr(trim((string)($body['message'] ?? '')), 0, 2000),
        mb_substr(trim((string)($body['q_music'] ?? '')), 0, 1000),
        mb_substr(trim((string)($body['q_challenge'] ?? '')), 0, 1000),
        mb_substr(trim((string)($body['q_goal'] ?? '')), 0, 1000),
        $street, $zip, $city, $status, now()]);
    $inv = null;
    if ($status === 'angemeldet') {
      $r = workshopInvoice($p, $sid);
      if (!empty($r['ok'])) $inv = ['number' => $r['number'], 'mailed' => !empty($r['mailed'])];
    }
    if ($status === 'warteliste')
      sendMailSafe($email, 'Du stehst auf der Warteliste – ich melde mich!',
        "Hallo " . (preg_split('/\s+/', $name, 2)[0] ?? $name) . ",\n\ndanke für dein Interesse am Workshop „" . $w['title'] . "“ am " . $w['event_date'] . "!\n\nDer Termin ist aktuell voll – du stehst jetzt auf der Warteliste. Sobald ein Platz frei wird, melde ich mich sofort persönlich bei dir. Bezahlt wird erst, wenn du wirklich einen Platz hast.\n\nBis hoffentlich bald!\nMarkus");
    notifyOwner(($status === 'warteliste' ? 'Warteliste' : 'Workshop-Buchung') . ': ' . $w['title'],
      "Name: $name ($seats Platz/Plätze)\nE-Mail: $email\nTermin: " . $w['event_date'] .
      ($inv ? "\nRechnung: " . $inv['number'] . ($inv['mailed'] ? ' (automatisch gemailt)' : ' (Mailversand prüfen!)') : ''));
    out(['ok' => true, 'status' => $status, 'invoice' => $inv,
      'free' => max(0, $free - ($status === 'angemeldet' ? $seats : 0))], 201);
  }
  /* Partner-Registrierung (DJs, Bands, Musiker, Techniker) */
  if ($path === 'portal/partner' && $method === 'POST') {
    $name = trim((string)($body['name'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    if ($name === '' || $email === '') fail('Name und E-Mail erforderlich.');
    $kind = in_array($body['kind'] ?? '', ['dj','band','musiker','techniker']) ? $body['kind'] : 'dj';
    $p->prepare('insert into partners (id,name,company,kind,email,phone,status,created_at) values (?,?,?,?,?,?,?,?)')
      ->execute([uuid(), mb_substr($name,0,120), mb_substr(trim((string)($body['company'] ?? '')),0,120),
        $kind, mb_substr($email,0,160), mb_substr(trim((string)($body['phone'] ?? '')),0,60), 'beantragt', now()]);
    out(['ok' => true], 201);
  }
  /* Partner-Code prüfen → Rabatt fürs Anzeigen der Partnerpreise */
  if (preg_match('#^portal/partner/([a-zA-Z0-9]{6,32})$#', $path, $m) && $method === 'GET') {
    $st = $p->prepare("select name, kind from partners where code=? and status='freigeschaltet'");
    $st->execute([strtoupper($m[1])]);
    $pt = $st->fetch();
    if (!$pt) { usleep(400000); fail('Partner-Code ungültig oder noch nicht freigeschaltet.', 404); }
    $defs = json_decode($p->query("select value from settings where key='defaults'")->fetchColumn() ?: '{}', true);
    out(['ok' => true, 'name' => $pt['name'], 'discount_pct' => (float)($defs['partner_discount_pct'] ?? 20)]);
  }
  /* Verfügbarkeit eines Artikels im Zeitraum (gegen alle nicht stornierten Aufträge) */
  if ($path === 'portal/availability' && $method === 'GET') {
    $eq = (string)($_GET['eq'] ?? ''); $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) fail('Zeitraum fehlt.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $from;
    $av = equipmentAvailability($p, $eq, $from, $to);
    if ($av === null) fail('Artikel nicht gefunden.', 404);
    out($av);
  }
  /* Warenkorb: eingeloggter Kunde bucht mehrere Artikel für einen Zeitraum als Miet-Anfrage.
     Legt eine normale bookings-Zeile (kind='miete') + booking_equipment-Zeilen an, damit sie
     in der bestehenden Buchungs-Ansicht in admin.html ohne neue Oberfläche auftaucht. */
  if ($path === 'portal/cart/submit' && $method === 'POST') {
    $me = custAuth();
    if (!$me) fail('Bitte zuerst einloggen oder registrieren.', 401);
    $from = (string)($body['from'] ?? ''); $to = (string)($body['to'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) fail('Zeitraum fehlt.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $from;
    $cart = is_array($body['items'] ?? null) ? $body['items'] : [];
    if (!$cart) fail('Der Warenkorb ist leer.');
    /* Partnerpreis gilt nur für freigeschaltete DJ-/Band-/Musiker-Partner, nicht für Techniker-Partner */
    $st = $p->prepare("select 1 from partners where lower(email)=lower(?) and status='freigeschaltet' and kind in ('dj','band','musiker') limit 1");
    $st->execute([(string)$me['email']]);
    $isPartner = (bool)$st->fetchColumn();
    $defs = json_decode((string)$p->query("select value from settings where key='defaults'")->fetchColumn() ?: '{}', true) ?: [];
    $partnerPct = (float)($defs['partner_discount_pct'] ?? 20);
    $tiers = rentalTierDefaults($p);
    $days = rentalDays(['event_date' => $from, 'end_date' => $to]);
    $lines = []; $total = 0.0; $claimed = [];   // je Artikel schon im selben Warenkorb beanspruchte Menge
    foreach ($cart as $it) {
      $qty = min(50, max(1, (int)($it['qty'] ?? 1)));   // sinnvolle Obergrenze gegen Ausreißer/Spam
      if (($it['type'] ?? 'equipment') === 'set') {
        $setId = (string)($it['set_id'] ?? '');
        $st = $p->prepare("select * from equipment_sets where id=? and public=1");
        $st->execute([$setId]);
        $set = $st->fetch();
        if (!$set) fail('Ein Set im Warenkorb ist nicht mehr verfügbar.', 404);
        $st = $p->prepare("select si.qty as set_qty, e.* from equipment_set_items si
          join equipment e on e.id = si.equipment_id where si.set_id = ?");
        $st->execute([$setId]);
        $comps = $st->fetchAll();
        if (!$comps) fail('Das Set „' . $set['name'] . '" enthält keine Artikel.', 500);
        $rawSum = 0.0; $compRates = [];
        foreach ($comps as $c) {
          $compQty = (int)$c['set_qty'] * $qty;
          $av = equipmentAvailability($p, $c['id'], $from, $to);
          /* Anfrage-Artikel passieren die Schranke - Bestätigung erfolgt manuell. */
          $free = (int)($av['available'] ?? 0) - ($claimed[$c['id']] ?? 0);
          if ($av === null || (empty($av['on_request']) && $free < $compQty)) fail('„' . $c['name'] . '" (Teil des Sets „' . $set['name'] . '") ist im gewünschten Zeitraum nicht in ausreichender Stückzahl verfügbar.', 409);
          $claimed[$c['id']] = ($claimed[$c['id']] ?? 0) + $compQty;
          $rate = $isPartner ? (float)($c['partner_rate'] ?? ((float)$c['day_rate'] * (1 - $partnerPct / 100))) : (float)$c['day_rate'];
          $rawSum += $rate * $compQty;
          $compRates[] = ['equipment_id' => $c['id'], 'name' => $c['name'], 'qty' => $compQty, 'rate' => $rate];
        }
        $setDayRate = $set['fixed_price'] !== null ? (float)$set['fixed_price'] : $rawSum * (1 - (float)$set['discount_pct'] / 100);
        $setTotal = round(rentalPrice($setDayRate, $days, 50, $tiers['week'], $tiers['twoweek'], $tiers['month']), 2);
        $total += $setTotal;
        $remaining = $setTotal;
        foreach ($compRates as $i => $c) {
          $share = $rawSum > 0 ? $c['rate'] * $c['qty'] / $rawSum : 1 / count($compRates);
          $compPrice = $i === count($compRates) - 1 ? $remaining : round($setTotal * $share, 2);
          $remaining -= $compPrice;
          $lines[] = ['equipment_id' => $c['equipment_id'], 'name' => $set['name'] . ' – ' . $c['name'], 'qty' => $c['qty'], 'price' => $compPrice];
        }
        continue;
      }
      $eqId = (string)($it['equipment_id'] ?? '');
      $st = $p->prepare("select * from equipment where id=? and public=1 and status='aktiv'");
      $st->execute([$eqId]);
      $eq = $st->fetch();
      if (!$eq) fail('Ein Artikel im Warenkorb ist nicht mehr verfügbar.', 404);
      $av = equipmentAvailability($p, $eqId, $from, $to);
      /* Anfrage-Artikel passieren die Schranke - Bestätigung erfolgt manuell. */
      $free = (int)($av['available'] ?? 0) - ($claimed[$eqId] ?? 0);
      if ($av === null || (empty($av['on_request']) && $free < $qty)) fail('„' . $eq['name'] . '" ist im gewünschten Zeitraum nicht in ausreichender Stückzahl verfügbar.', 409);
      $claimed[$eqId] = ($claimed[$eqId] ?? 0) + $qty;
      $rate = $isPartner ? (float)($eq['partner_rate'] ?? ((float)$eq['day_rate'] * (1 - $partnerPct / 100))) : (float)$eq['day_rate'];
      $price = rentalPrice($rate, $days, (float)($eq['followup_pct'] ?? 50),
        (float)($eq['tier_week_pct'] ?? $tiers['week']), (float)($eq['tier_2week_pct'] ?? $tiers['twoweek']), (float)($eq['tier_month_pct'] ?? $tiers['month'])
      ) * $qty;
      $price = round($price, 2);
      $total += $price;
      $lines[] = ['equipment_id' => $eqId, 'name' => $eq['name'], 'qty' => $qty, 'price' => $price];
    }
    $bookingId = uuid();
    $p->prepare("insert into bookings (id, customer_id, status, kind, event_type, title, event_date, end_date, notes, created_at, updated_at)
      values (?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$bookingId, $me['id'], 'anfrage', 'technik', 'Technik-Miete', null, $from, $to,
        mb_substr(trim((string)($body['notes'] ?? '')), 0, 2000), now(), now()]);
    $insLine = $p->prepare('insert into booking_equipment (id, booking_id, equipment_id, qty, price_override) values (?,?,?,?,?)');
    foreach ($lines as $l) $insLine->execute([uuid(), $bookingId, $l['equipment_id'], $l['qty'], $l['price']]);
    $custName = trim(($me['company'] ?: trim($me['first_name'] . ' ' . $me['last_name'])));
    notifyOwner('Neue Miet-Anfrage: ' . $custName,
      "Zeitraum: $from" . ($to !== $from ? " bis $to" : '') . "\n\n" .
      implode("\n", array_map(fn($l) => '- ' . $l['name'] . ' × ' . $l['qty'] . ' = ' . number_format($l['price'], 2, ',', '.') . ' €', $lines)) .
      "\n\nGesamt (netto): " . number_format($total, 2, ',', '.') . " €" . ($isPartner ? "\n(Partnerpreis angewendet)" : ''));
    out(['ok' => true, 'booking_id' => $bookingId, 'items' => $lines, 'total' => round($total, 2), 'partner' => $isPartner], 201);
  }
  /* Newsletter: Anmeldung mit Double-Opt-in, Bestätigung und Ein-Klick-Abmeldung */
  if ($path === 'portal/newsletter' && $method === 'POST') {
    $email = mb_substr(trim((string)($body['email'] ?? '')), 0, 160);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Bitte eine gültige E-Mail-Adresse angeben.');
    $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 120);
    $source = mb_substr(trim((string)($body['source'] ?? 'workshops')), 0, 60);
    $st = $p->prepare('select * from newsletter where email = ?');
    $st->execute([$email]);
    $row = $st->fetch();
    if ($row && $row['confirmed_at'] && !$row['unsubscribed_at'])
      out(['ok' => true, 'already' => true]);
    $token = bin2hex(random_bytes(16));
    if ($row) {
      $p->prepare('update newsletter set token=?, name=coalesce(nullif(?,\'\'), name), source=?, unsubscribed_at=null where id=?')
        ->execute([$token, $name, $source, $row['id']]);
    } else {
      $p->prepare('insert into newsletter (id, email, name, token, source, created_at) values (?,?,?,?,?,?)')
        ->execute([uuid(), $email, $name, $token, $source, now()]);
    }
    $vn = $name !== '' ? (preg_split('/\s+/', $name, 2)[0] ?? $name) : 'du';
    $link = baseUrl() . '/api.php/portal/newsletter/confirm/' . $token;
    $mailed = sendMailSafe($email, 'Nur noch ein Klick – dann bist du dabei',
      ($vn === 'du' ? "Hallo,\n\n" : "Hallo $vn,\n\n") .
      "schön, dass du bei neuen Workshop-Terminen als Erstes Bescheid wissen willst! " .
      "Bestätige kurz deine Anmeldung, damit ich sicher weiß, dass die Adresse dir gehört:\n\n$link\n\n" .
      "Du bekommst danach nur Post, wenn es wirklich etwas gibt: neue Termine, neue Themen, freie Plätze. " .
      "Abmelden geht jederzeit mit einem Klick.\n\n" .
      "Falls du das nicht warst, ignoriere diese Mail einfach – dann passiert nichts.\n\nBis bald!\nMarkus");
    out(['ok' => true, 'mailed' => $mailed], 201);
  }
  if (preg_match('#^portal/newsletter/(confirm|unsubscribe)/([a-f0-9]{32})$#', $path, $m) && $method === 'GET') {
    $st = $p->prepare('select * from newsletter where token = ?');
    $st->execute([$m[2]]);
    $row = $st->fetch();
    $ok = false; $title = 'Link ungültig'; $text = 'Dieser Link ist nicht mehr gültig. Melde dich einfach neu an – oder schreib mir kurz.';
    if ($row && $m[1] === 'confirm') {
      if (!$row['confirmed_at']) {
        $p->prepare('update newsletter set confirmed_at=?, unsubscribed_at=null where id=?')->execute([now(), $row['id']]);
        notifyOwner('Neuer Newsletter-Abonnent', 'E-Mail: ' . $row['email'] . ($row['name'] ? "\nName: " . $row['name'] : '') . "\nQuelle: " . ($row['source'] ?: '–'));
      }
      $ok = true; $title = 'Das hat geklappt ✓';
      $text = 'Du bist dabei! Sobald es neue Workshop-Termine oder Themen gibt, bekommst du als Erstes Bescheid.';
    } elseif ($row && $m[1] === 'unsubscribe') {
      $p->prepare('update newsletter set unsubscribed_at=? where id=?')->execute([now(), $row['id']]);
      $ok = true; $title = 'Du bist abgemeldet';
      $text = 'Alles klar – du bekommst keine weiteren Mails von mir. Danke, dass du dabei warst!';
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
      '<title>' . htmlspecialchars($title) . '</title></head>' .
      '<body style="font-family:system-ui,sans-serif;background:#0e1213;color:#eef2f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px">' .
      '<div style="max-width:440px;background:#181e20;border:1px solid #26302f;border-radius:14px;padding:36px;text-align:center">' .
      '<h1 style="font-size:22px;margin:0 0 12px">' . htmlspecialchars($title) . '</h1>' .
      '<p style="color:#9aa8a3;line-height:1.6;margin:0 0 22px">' . htmlspecialchars($text) . '</p>' .
      '<a href="technik.html#workshops" style="color:#3cc8b4;text-decoration:none;font-weight:600">' .
      ($ok && $m[1] === 'confirm' ? 'Zu den Workshop-Terminen →' : 'Zur Technik-Seite →') . '</a></div></body></html>';
    exit;
  }
  fail('Unbekannter Portal-Endpunkt.', 404);
}

/* ---------- Upload ---------- */
/* Große Fotos automatisch auf eine vernünftige Webgröße verkleinern und neu komprimieren
   (ein 10-MB-Handyfoto soll nie 1:1 auf dem Server landen). GIF (Animation) und AVIF (Server-
   Unterstützung uneinheitlich) bleiben unangetastet. Bei JPEG wird die EXIF-Drehung fest ins
   Bild gerechnet, damit die Ausrichtung stimmt, auch wenn die Metadaten beim Speichern wegfallen. */
function processImage(string $raw, int $maxDim = 2000, int $quality = 85): string {
  if (!extension_loaded('gd')) return $raw;
  $info = @getimagesizefromstring($raw);
  if (!$info) return $raw;
  $mime = $info['mime'] ?? '';
  if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) return $raw;
  $img = @imagecreatefromstring($raw);
  if (!$img) return $raw;
  if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
    $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($raw));
    switch ($exif['Orientation'] ?? 1) {
      case 3: $img = imagerotate($img, 180, 0); break;
      case 6: $img = imagerotate($img, 270, 0); break;
      case 8: $img = imagerotate($img, 90, 0); break;
    }
  }
  $w = imagesx($img); $h = imagesy($img);
  $scale = min(1, $maxDim / max($w, $h));
  if ($scale < 1) {
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    $resized = imagecreatetruecolor($nw, $nh);
    if ($mime === 'image/png') { imagealphablending($resized, false); imagesavealpha($resized, true); }
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);
    $img = $resized;
  }
  ob_start();
  if ($mime === 'image/png') imagepng($img, null, 6);
  elseif ($mime === 'image/webp') imagewebp($img, null, $quality);
  else imagejpeg($img, null, $quality);
  $out = ob_get_clean();
  imagedestroy($img);
  return ($out !== false && strlen($out) > 0) ? $out : $raw;
}
/* Schützt uploads/ als Defense-in-Depth: keine Skript-Ausführung, egal was hochgeladen wurde
   (Apache-Produktion; php -S ignoriert .htaccess). */
function ensureUploadDir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $ht = UPLOAD_DIR . '/.htaccess';
  if (!file_exists($ht)) file_put_contents($ht,
    "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar .cgi .pl\n" .
    "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phar|cgi|pl|py|svg|htm|html)\$\">\n  Require all denied\n</FilesMatch>\n");
}
function handleUpload(string $name): never {
  if (!currentUser()) fail('Nicht angemeldet.', 401);
  ensureUploadDir(UPLOAD_DIR);
  $raw = file_get_contents('php://input');
  if (!$raw || strlen($raw) > MAX_UPLOAD) fail('Datei fehlt oder ist zu groß (max. 8 MB).');
  $name = strtolower(preg_replace('/[^a-z0-9._-]+/i', '-', basename($name)));
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg','jpeg','png','webp','gif','avif'])) fail('Nur Bilddateien erlaubt.');
  $info = @getimagesizefromstring($raw);
  if ($info === false) fail('Keine gültige Bilddatei.');
  $raw = processImage($raw);
  file_put_contents(UPLOAD_DIR . '/' . $name, $raw);
  out(['url' => 'uploads/' . $name], 201);
}

/* Instagram-Feed spiegeln: Bilder nach uploads/instagram laden und die Liste in site_content
   ablegen. Der Zugriffstoken bleibt in settings (nicht öffentlich) und landet nie im Feed-JSON. */
function instagramSync(PDO $p): never {
  $cfg = json_decode((string)$p->query("select value from settings where key='instagram'")->fetchColumn() ?: '{}', true) ?: [];
  $token = trim((string)($cfg['token'] ?? ''));
  if ($token === '') fail('Kein Instagram-Token hinterlegt – bitte zuerst oben auf dieser Karte („Inhalte → Instagram-Galerie") den Zugriffstoken eintragen und speichern.');
  $max = max(1, min(50, (int)($cfg['max'] ?? 12)));
  $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
  $resp = @file_get_contents('https://graph.instagram.com/me/media'
    . '?fields=id,caption,media_type,media_url,thumbnail_url,permalink,timestamp'
    . '&limit=' . $max . '&access_token=' . rawurlencode($token), false, $ctx);
  if ($resp === false) fail('Instagram ist gerade nicht erreichbar – bitte später noch einmal versuchen.');
  $j = json_decode($resp, true);
  if (!is_array($j) || isset($j['error']))
    fail('Instagram-Abruf fehlgeschlagen: ' . ($j['error']['message'] ?? 'unerwartete Antwort')
      . ' – ist der Token noch gültig?');
  $dir = UPLOAD_DIR . '/instagram';
  ensureUploadDir($dir);
  $images = []; $keep = [];
  foreach (($j['data'] ?? []) as $m) {
    $type = (string)($m['media_type'] ?? '');
    $src = $type === 'VIDEO' ? (string)($m['thumbnail_url'] ?? '') : (string)($m['media_url'] ?? '');
    $id = preg_replace('/\D/', '', (string)($m['id'] ?? ''));
    if ($id === '' || $src === '' || !in_array($type, ['IMAGE', 'CAROUSEL_ALBUM', 'VIDEO'])) continue;
    $file = "ig-$id.jpg";
    $bin = @file_get_contents($src, false, $ctx);
    if ($bin !== false && @getimagesizefromstring($bin) !== false)
      file_put_contents("$dir/$file", processImage($bin));
    if (!is_file("$dir/$file")) continue;   // Download fehlgeschlagen und kein alter Stand vorhanden
    $keep[] = $file;
    $images[] = ['file' => "uploads/instagram/$file",
      'permalink' => (string)($m['permalink'] ?? ''),
      'caption' => mb_substr(trim((string)($m['caption'] ?? '')), 0, 200)];
  }
  /* Bilder aufräumen, die nicht mehr im Feed sind */
  foreach (glob("$dir/ig-*.jpg") ?: [] as $f)
    if (!in_array(basename($f), $keep)) @unlink($f);
  $p->prepare("insert into site_content (key,value,updated_at) values ('instagram_feed',?,?)
      on conflict(key) do update set value=excluded.value, updated_at=excluded.updated_at")
    ->execute([json_encode(['images' => $images, 'synced_at' => now(), 'count' => count($images)],
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now()]);
  out(['ok' => true, 'count' => count($images)], 201);
}

/* ---------- Router ---------- */
$path = trim($_SERVER['PATH_INFO'] ?? ($_GET['_p'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'];
$prefer = array_map('trim', explode(',', $_SERVER['HTTP_PREFER'] ?? ''));
$body = null;
if (in_array($method, ['POST','PATCH']) &&
    str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
  $body = json_decode(file_get_contents('php://input'), true);
}

try {
  if ($path === 'auth/login' && $method === 'POST') handleLogin($body ?? []);
  if (str_starts_with($path, 'portal/')) handlePortal($path, $method, $body ?? []);
  if (preg_match('#^rest/(\w+)$#', $path, $m)) {
    $q = $_GET; unset($q['_p']);
    handleRest($m[1], $method, $q, $body, $prefer);
  }
  if (preg_match('#^storage/(.+)$#', $path, $m) && $method === 'POST') handleUpload($m[1]);
  /* Medienpool: alle Bilder im uploads-Ordner (inkl. gespiegelter Instagram-Bilder) – nur angemeldet */
  if ($path === 'media/list' && $method === 'GET') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $files = [];
    $scan = function (string $dir, string $prefix, string $source) use (&$files) {
      foreach (glob($dir . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [] as $f)
        if (is_file($f)) $files[] = ['name' => basename($f), 'url' => $prefix . basename($f),
          'size' => filesize($f), 'mtime' => filemtime($f), 'source' => $source];
    };
    $scan(UPLOAD_DIR, 'uploads/', 'upload');
    $scan(UPLOAD_DIR . '/instagram', 'uploads/instagram/', 'instagram');
    usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    out($files);
  }
  if ($path === 'instagram/sync' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    instagramSync(db());
  }
  /* Einkaufsbeleg zu einem Technik-Artikel hinterlegen (Garantiefall) – nur angemeldet, nie öffentlich */
  if (preg_match('#^equipment/([a-f0-9-]{30,40})/invoice$#', $path, $m) && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $p = db();
    $chk = $p->prepare('select id from equipment where id = ?'); $chk->execute([$m[1]]);
    if (!$chk->fetch()) fail('Artikel nicht gefunden.', 404);
    $raw = file_get_contents('php://input');
    if (!$raw || strlen($raw) > MAX_UPLOAD) fail('Datei fehlt oder ist zu groß (max. 8 MB).');
    $orig = mb_substr(preg_replace('/[^\w.\-() äöüÄÖÜß]/u', '_', (string)($_GET['name'] ?? 'rechnung.pdf')), 0, 120);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $isImg = @getimagesizefromstring($raw) !== false;
    if (!$isImg && !($ext === 'pdf' && str_starts_with($raw, '%PDF'))) fail('Erlaubt: PDF oder Foto der Rechnung.');
    if ($isImg) $raw = processImage($raw);
    $dir = DATA_DIR . '/eqfiles';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = uuid() . '.' . ($isImg ? ($ext ?: 'jpg') : 'pdf');
    file_put_contents("$dir/$file", $raw);
    $p->prepare('update equipment set invoice_file = ?, invoice_name = ? where id = ?')
      ->execute([$file, $orig, $m[1]]);
    out(['ok' => true, 'name' => $orig], 201);
  }
  if (preg_match('#^eqfile/([a-f0-9-]{30,40})$#', $path, $m) && $method === 'GET') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $p = db();
    $st = $p->prepare('select invoice_file, invoice_name from equipment where id = ?'); $st->execute([$m[1]]);
    $f = $st->fetch();
    if (!$f || !$f['invoice_file'] || !is_file(DATA_DIR . '/eqfiles/' . $f['invoice_file'])) fail('Datei nicht gefunden.', 404);
    $ext = strtolower(pathinfo((string)$f['invoice_file'], PATHINFO_EXTENSION));
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
      'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode((string)$f['invoice_name']) . '"');
    readfile(DATA_DIR . '/eqfiles/' . $f['invoice_file']); exit;
  }
  /* Rechnung zu einer Workshop-Anmeldung erzeugen + mailen (z. B. beim Nachrücken) – nur angemeldet */
  if (preg_match('#^workshop/([a-f0-9-]{30,40})/invoice$#', $path, $m) && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    out(workshopInvoice(db(), $m[1]), 201);
  }
  /* Backups: Snapshot der SQLite-Datenbank nach data/backups (durch .htaccess geschützt).
     cron/backup?key=… ist für den All-Inkl-Cronjob (Schlüssel aus den Einstellungen),
     backup/run|list|get nur angemeldet. */
  if ($path === 'cron/backup' && $method === 'GET') {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals(backupKey(), $key)) { usleep(500000); fail('Ungültiger Schlüssel.', 401); }
    $r = runBackup();
    $r['digest'] = dailyDigest();
    out($r);
  }
  /* Kalender-Abos (iCal): drei Feeds – Anfragen, feste DJ-Buchungen, Technikvermietung */
  if (preg_match('#^ical/([a-f0-9]{32})/(anfragen|buchungen|technik)\.ics$#', $path, $m) && $method === 'GET') {
    if (!hash_equals(icalKey(), $m[1])) { usleep(500000); fail('Ungültiger Schlüssel.', 401); }
    serveIcal($m[2]);
  }
  if ($path === 'ical/urls' && $method === 'GET') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $k = icalKey(); $b = baseUrl();
    out(['anfragen' => "$b/api.php/ical/$k/anfragen.ics",
      'buchungen' => "$b/api.php/ical/$k/buchungen.ics",
      'technik' => "$b/api.php/ical/$k/technik.ics"]);
  }
  /* Anonyme Reichweiten-Zählung: nur Tag, Seitenname und Referrer-Domain – keine IPs, keine Cookies.
     sendBeacon schickt text/plain, daher wird der Body hier selbst gelesen. */
  if ($path === 'track' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $page = strtolower((string)($b['p'] ?? ''));
    if (!preg_match('/^[a-z0-9_.-]{1,60}$/', $page)) $page = 'index.html';
    $ref = '';
    $host = strtolower((string)(parse_url((string)($b['r'] ?? ''), PHP_URL_HOST) ?: ''));
    $own = strtolower((string)(parse_url(baseUrl(), PHP_URL_HOST) ?: ''));
    if ($host !== '' && $host !== $own && $host !== ($_SERVER['HTTP_HOST'] ?? ''))
      $ref = mb_substr(preg_replace('/^www\./', '', $host), 0, 80);
    $day = gmdate('Y-m-d');
    $p = db();
    $u = $p->prepare('update stats_daily set views = views + 1 where day=? and page=? and ref=?');
    $u->execute([$day, $page, $ref]);
    if (!$u->rowCount()) {
      try { $p->prepare('insert into stats_daily (day, page, ref, views) values (?,?,?,1)')->execute([$day, $page, $ref]); }
      catch (PDOException $e) { $u->execute([$day, $page, $ref]); }
    }
    out(['ok' => true]);
  }
  /* Statistik-Übersicht fürs Backoffice */
  if ($path === 'stats/overview' && $method === 'GET') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $days = max(7, min(365, (int)($_GET['days'] ?? 30)));
    $from = gmdate('Y-m-d', time() - $days * 86400);
    $p = db();
    $q = function (string $sql) use ($p, $from) { $st = $p->prepare($sql); $st->execute([$from]); return $st->fetchAll(); };
    $daily = $q("select day, sum(views) as views from stats_daily where day >= ? group by day order by day");
    $inqDaily = $q("select substr(created_at,1,10) as day, count(*) as n from inquiries where created_at >= ? group by 1 order by 1");
    out([
      'days' => $days,
      'daily' => $daily,
      'pages' => $q("select page, sum(views) as views from stats_daily where day >= ? group by page order by views desc limit 12"),
      'refs' => $q("select ref, sum(views) as views from stats_daily where day >= ? and ref != '' group by ref order by views desc limit 12"),
      'inq_daily' => $inqDaily,
      'views_total' => array_sum(array_column($daily, 'views')),
      'inquiries_total' => array_sum(array_column($inqDaily, 'n')),
      'signups_total' => (int)$p->query("select count(*) from workshop_signups where created_at >= '$from'")->fetchColumn(),
      'newsletter_total' => (int)$p->query("select count(*) from newsletter where confirmed_at is not null and unsubscribed_at is null")->fetchColumn(),
    ]);
  }
  /* Newsletter-Versand an alle bestätigten Abonnenten (oder Testmail an die eigene Adresse) */
  if ($path === 'newsletter/send' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $subject = trim((string)($body['subject'] ?? ''));
    $text = (string)($body['body'] ?? '');
    if ($subject === '' || trim($text) === '') fail('Betreff und Text erforderlich.');
    $p = db();
    if (!empty($body['test'])) {
      $comp = json_decode($p->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true);
      $to = (string)($comp['email'] ?? '');
      if ($to === '') fail('Für die Testmail muss in den Einstellungen eine Firmen-E-Mail hinterlegt sein.');
      out(['mailed' => sendMailSafe($to, '[TEST] ' . $subject, $text . "\n\n–\nAbmelden: (Link wird beim echten Versand je Empfänger eingefügt)")]);
    }
    $subs = $p->query("select * from newsletter where confirmed_at is not null and unsubscribed_at is null")->fetchAll();
    $sent = 0; $failed = 0;
    foreach ($subs as $s) {
      $unsub = baseUrl() . '/api.php/portal/newsletter/unsubscribe/' . $s['token'];
      $personal = str_replace('{vorname}',
        $s['name'] ? (preg_split('/\s+/', (string)$s['name'], 2)[0] ?? $s['name']) : 'Musikfreund', $text);
      if (sendMailSafe((string)$s['email'], $subject, $personal . "\n\n–\nDu bekommst diese Mail, weil du dich für Workshop-News angemeldet hast.\nAbmelden mit einem Klick: $unsub"))
        $sent++; else $failed++;
    }
    out(['sent' => $sent, 'failed' => $failed, 'total' => count($subs)]);
  }
  /* Direktversand aus dem Backoffice */
  if ($path === 'sendmail' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $to = trim((string)($body['to'] ?? ''));
    $subject = trim((string)($body['subject'] ?? ''));
    $text = (string)($body['body'] ?? '');
    if ($to === '' || $subject === '' || trim($text) === '') fail('Empfänger, Betreff und Text erforderlich.');
    $mailed = sendMailSafe($to, $subject, $text);
    if ($mailed && !empty($body['customer_id'])) {
      db()->prepare('insert into communications (id, customer_id, channel, direction, subject, content, occurred_at, created_at)
          values (?,?,?,?,?,?,?,?)')
        ->execute([uuid(), (string)$body['customer_id'], 'email', 'out', $subject, $text, now(), now()]);
    }
    out(['mailed' => $mailed]);
  }
  if ($path === 'backup/run' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    out(runBackup());
  }
  if ($path === 'backup/list' && $method === 'GET') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $files = [];
    foreach (glob(DATA_DIR . '/backups/dj-*.sqlite.gz') ?: [] as $f)
      $files[] = ['name' => basename($f), 'size' => filesize($f), 'time' => gmdate('c', filemtime($f))];
    usort($files, fn($a, $b) => strcmp($b['name'], $a['name']));
    out(['key' => backupKey(), 'files' => $files]);
  }
  if (preg_match('#^backup/get/(dj-[0-9-]+\.sqlite\.gz)$#', $path, $m) && $method === 'GET') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $f = DATA_DIR . '/backups/' . $m[1];
    if (!is_file($f)) fail('Backup nicht gefunden.', 404);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $m[1] . '"');
    readfile($f); exit;
  }
  /* Kundenportal-Verwaltung (nur angemeldet): Einladungslink + Dateizugriff */
  if (preg_match('#^custportal/invite/([a-f0-9-]{30,40})$#', $path, $m) && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $p = db();
    $st = $p->prepare('select * from customers where id = ?');
    $st->execute([$m[1]]);
    $c = $st->fetch();
    if (!$c) fail('Kunde nicht gefunden.', 404);
    $inv = bin2hex(random_bytes(24));
    $p->prepare('update customers set portal_invite = ?, portal_invite_expires = ? where id = ?')
      ->execute([$inv, time() + 7 * 86400, $c['id']]);
    $url = baseUrl() . '/portal.html?einladung=' . $inv;
    $mailed = false;
    if (!empty($body['mail']) && $c['email']) {
      $mailed = sendMailSafe((string)$c['email'], 'Dein Zugang zum Kunden-Backoffice',
        "Hallo " . trim((string)$c['first_name']) . ",\n\nhier ist dein persönlicher Zugang zu deinem Kunden-Backoffice – dort findest du alle Unterlagen (Angebote, Rechnungen, Verträge) und kannst Programmablauf, Musikwünsche und Fotos eurer Location hinterlegen:\n\n$url\n\nEinfach öffnen und ein Passwort setzen (Link ist 7 Tage gültig).\n\nViele Grüße\nMarkus");
    }
    out(['ok' => true, 'url' => $url, 'mailed' => $mailed, 'has_account' => !empty($c['portal_hash'])], 201);
  }
  if (preg_match('#^custfile/([a-f0-9-]{30,40})$#', $path, $m) && $method === 'GET') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $p = db();
    $st = $p->prepare('select * from customer_files where id = ?');
    $st->execute([$m[1]]);
    $f = $st->fetch();
    if (!$f || !is_file(DATA_DIR . '/custfiles/' . $f['file'])) fail('Datei nicht gefunden.', 404);
    $ext = strtolower(pathinfo((string)$f['file'], PATHINFO_EXTENSION));
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
      'gif' => 'image/gif', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode((string)$f['name']) . '"');
    readfile(DATA_DIR . '/custfiles/' . $f['file']); exit;
  }
  /* Deployment-Konfiguration (data/deploy.json) – nur angemeldet; Token wird nie zurückgegeben */
  if ($path === 'deploy/config' && in_array($method, ['GET', 'POST'])) {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $file = DATA_DIR . '/deploy.json';
    $cfg = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    if ($method === 'POST') {
      $cfg['repo'] = trim((string)($body['repo'] ?? $cfg['repo'] ?? ''));
      $cfg['branch'] = trim((string)($body['branch'] ?? $cfg['branch'] ?? 'live')) ?: 'live';
      $cfg['subdir'] = trim((string)($body['subdir'] ?? $cfg['subdir'] ?? 'fullservice-dj-homepage/webroot'));
      if (!empty($body['token'])) $cfg['token'] = trim((string)$body['token']);
      if (empty($cfg['key'])) $cfg['key'] = bin2hex(random_bytes(16));
      if ($cfg['repo'] === '' || !preg_match('#^[\w.-]+/[\w.-]+$#', $cfg['repo'])) fail('Repository bitte als owner/name angeben.');
      file_put_contents($file, json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    out(['repo' => $cfg['repo'] ?? '', 'branch' => $cfg['branch'] ?? 'live',
      'subdir' => $cfg['subdir'] ?? '', 'key' => $cfg['key'] ?? '',
      'has_token' => !empty($cfg['token']),
      'last_sha' => $cfg['last_sha'] ?? null, 'last_time' => $cfg['last_time'] ?? null]);
  }
  /* Technik-Check-Fotos: geschützt in data/checkpics, Zugriff nur angemeldet */
  if (preg_match('#^checkpic/([a-f0-9-]{30,40})$#', $path, $m) && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $raw = file_get_contents('php://input');
    if (!$raw || strlen($raw) > 4 * 1024 * 1024) fail('Foto fehlt oder ist zu groß (max. 4 MB).');
    if (@getimagesizefromstring($raw) === false) fail('Keine gültige Bilddatei.');
    $dir = DATA_DIR . '/checkpics';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = $m[1] . '-' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents("$dir/$name", $raw);
    out(['name' => $name], 201);
  }
  if (preg_match('#^checkpic/get/([a-f0-9-]{30,40}-[a-f0-9]{8}\.jpg)$#', $path, $m)) {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $f = DATA_DIR . '/checkpics/' . $m[1];
    if ($method === 'DELETE') { @unlink($f); out(['ok' => true]); }
    if (!is_file($f)) fail('Foto nicht gefunden.', 404);
    header('Content-Type: image/jpeg');
    readfile($f); exit;
  }
  /* Ausweisfotos: liegen geschützt in data/ids, Abruf/Löschung nur angemeldet */
  if (preg_match('#^idfile/([a-f0-9-]{30,50}-(?:front|back)\.(?:jpg|png|webp))$#', $path, $m)) {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $f = DATA_DIR . '/ids/' . $m[1];
    if ($method === 'DELETE') { @unlink($f); out(['ok' => true]); }
    if ($method === 'GET') {
      if (!is_file($f)) fail('Datei nicht gefunden.', 404);
      $ext = pathinfo($f, PATHINFO_EXTENSION);
      header('Content-Type: ' . ($ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext));
      readfile($f); exit;
    }
  }
  fail('Unbekannter Endpunkt.', 404);
} catch (PDOException $e) {
  fail('Datenbankfehler: ' . $e->getMessage(), 500);
}
