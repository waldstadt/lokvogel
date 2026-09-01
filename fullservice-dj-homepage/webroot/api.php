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
 *   POST api.php/storage/{dateiname} (Bild- oder Video-Upload, nur mit Login) -> {url}
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
/* Videos duerfen groesser sein als Bilder - ein kurzer Header-Clip liegt sonst schon
   ueber der Grenze. Trotzdem gedeckelt: was hier hochgeht, muss jeder Besucher laden. */
const MAX_UPLOAD_VIDEO = 24 * 1024 * 1024;
const SCHEMA_VERSION = 82;   // frisches Schema in migrate() muss diesem Stand entsprechen

/* KI-Textassistent: Vorgabe-Basis-URL/Modell je Anbieter. Nur "claude" spricht die native
   Anthropic-Messages-API (anderer Header/Antwortformat) - alle anderen sind OpenAI-kompatibel
   und laufen über denselben Chat-Completions-Codepfad. */
const AI_PROVIDER_DEFAULTS = [
  'claude'   => ['base_url' => 'https://api.anthropic.com/v1', 'model' => 'claude-opus-5'],
  'openai'   => ['base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini'],
  'gemini'   => ['base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-2.5-flash'],
  'mistral'  => ['base_url' => 'https://api.mistral.ai/v1', 'model' => 'mistral-small-latest'],
  'deepseek' => ['base_url' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat'],
];

/* Miet-Partner-Rabatt für eine E-Mail: gilt bereits ab dem Antrag ("beantragt") vorläufig,
   nicht erst nach Freischaltung - der Admin kann jederzeit einen individuellen Rabatt statt
   des allgemeinen hinterlegen. Kein Code-Verfahren mehr, Zuordnung läuft rein über das
   Kundenkonto (E-Mail). */
function partnerInfoForEmail(PDO $p, string $email): ?array {
  $st = $p->prepare("select status, discount_pct from partners where lower(email)=? and kind in ('dj','band','musiker') order by created_at desc limit 1");
  $st->execute([strtolower($email)]);
  $pt = $st->fetch();
  if (!$pt) return null;
  $defs = json_decode((string)$p->query("select value from settings where key='defaults'")->fetchColumn() ?: '{}', true) ?: [];
  $pct = $pt['discount_pct'] !== null ? (float)$pt['discount_pct'] : (float)($defs['partner_discount_pct'] ?? 20);
  return ['status' => $pt['status'], 'discount_pct' => $pct, 'provisional' => $pt['status'] !== 'freigeschaltet'];
}

/* Gemeinsamer Aufruf-Code für den KI-Textassistenten UND die FAQ-Vorschläge: baut die
   Anfrage je nach Anbieter (native Anthropic-Messages-API vs. OpenAI-kompatible
   Chat-Completions) und liefert den reinen generierten Text zurück. Bricht bei Fehlern
   über fail() mit einer möglichst konkreten Meldung ab (HTTP-Status + Antwort-Ausschnitt),
   statt nur "unerwartete Antwort" zu zeigen. */
function aiCallLLM(string $provider, string $apiKey, string $baseUrl, string $model,
                    string $workspaceId, string $system, string $userText, int $maxTokens): string {
  $baseUrl = rtrim($baseUrl, '/');
  if ($provider === 'claude') {
    $reqBody = json_encode([
      'model' => $model, 'max_tokens' => $maxTokens, 'system' => $system,
      'messages' => [['role' => 'user', 'content' => $userText]],
    ], JSON_UNESCAPED_UNICODE);
    $header = "x-api-key: $apiKey\r\nanthropic-version: 2023-06-01\r\nContent-Type: application/json\r\nUser-Agent: Mozilla/5.0 (compatible; LauschgiftBackoffice/1.0)\r\nAccept: application/json\r\n";
    if ($workspaceId !== '') $header .= "anthropic-workspace-id: $workspaceId\r\n";
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => $header, 'content' => $reqBody, 'timeout' => 40, 'ignore_errors' => true]]);
    $resp = @file_get_contents($baseUrl . '/messages', false, $ctx);
    if ($resp === false) fail('Der KI-Dienst ist gerade nicht erreichbar – bitte später erneut versuchen.', 502);
    $j = json_decode($resp, true);
    if (!is_array($j) || isset($j['error']) || ($j['type'] ?? '') === 'error') {
      $msg = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
      if ($msg === '') {
        $status = '';
        foreach ((array)($http_response_header ?? []) as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $sm)) $status = $sm[1]; }
        $msg = 'unerwartete Antwort vom KI-Dienst' . ($status !== '' ? " (HTTP $status)" : '')
          . (trim((string)$resp) !== '' ? ': ' . mb_substr(trim(strip_tags((string)$resp)), 0, 200) : '.');
      }
      fail('KI-Anfrage fehlgeschlagen: ' . $msg, 502);
    }
    $generated = '';
    foreach ((array)($j['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $generated .= (string)($block['text'] ?? ''); }
    return trim($generated);
  }
  $reqBody = json_encode([
    'model' => $model,
    'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $userText]],
    'temperature' => 0.6, 'max_tokens' => $maxTokens,
  ], JSON_UNESCAPED_UNICODE);
  $ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Authorization: Bearer $apiKey\r\nContent-Type: application/json\r\nUser-Agent: Mozilla/5.0 (compatible; LauschgiftBackoffice/1.0)\r\nAccept: application/json\r\n",
    'content' => $reqBody, 'timeout' => 40, 'ignore_errors' => true,
  ]]);
  $resp = @file_get_contents($baseUrl . '/chat/completions', false, $ctx);
  if ($resp === false) fail('Der KI-Dienst ist gerade nicht erreichbar – bitte später erneut versuchen.', 502);
  $j = json_decode($resp, true);
  if (!is_array($j) || isset($j['error'])) {
    $msg = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
    if ($msg === '') {
      $status = '';
      foreach ((array)($http_response_header ?? []) as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $sm)) $status = $sm[1]; }
      $msg = 'unerwartete Antwort vom KI-Dienst' . ($status !== '' ? " (HTTP $status)" : '')
        . (trim((string)$resp) !== '' ? ': ' . mb_substr(trim(strip_tags((string)$resp)), 0, 200) : '.');
    }
    fail('KI-Anfrage fehlgeschlagen: ' . $msg, 502);
  }
  return trim((string)($j['choices'][0]['message']['content'] ?? ''));
}
/* Liefert {provider,apiKey,baseUrl,model,workspaceId} aus settings.ai oder bricht mit
   verständlicher Meldung ab, wenn kein Zugang eingerichtet ist. */
function aiConfigOrFail(): array {
  $p = db();
  $cfg = json_decode((string)$p->query("select value from settings where key='ai'")->fetchColumn() ?: '{}', true) ?: [];
  $apiKey = trim((string)($cfg['api_key'] ?? ''));
  if ($apiKey === '') fail('Kein KI-Zugang eingerichtet – bitte in den Einstellungen unter „KI-Textassistent" einen API-Schlüssel hinterlegen.', 400);
  $provider = (string)($cfg['provider'] ?? 'openai');
  $defaults = AI_PROVIDER_DEFAULTS[$provider] ?? AI_PROVIDER_DEFAULTS['openai'];
  return [
    'provider' => $provider, 'apiKey' => $apiKey,
    'baseUrl' => (string)($cfg['base_url'] ?: $defaults['base_url']),
    'model' => (string)($cfg['model'] ?: $defaults['model']),
    'workspaceId' => trim((string)($cfg['workspace_id'] ?? '')),
  ];
}

/* Automatische Fahrtstrecke Lager -> Location über OpenRouteService (EU-Anbieter, freier
   API-Schlüssel). Geokodiert Adresse in Koordinaten, dann Routenberechnung Auto. Ergebnis
   bleibt im Rider frei überschreibbar - reine Rechenhilfe, kein Zwang. */
function orsGet(string $url, string $apiKey): array {
  $ctx = stream_context_create(['http' => [
    'method' => 'GET',
    'header' => "Authorization: $apiKey\r\nAccept: application/json, application/geo+json\r\nUser-Agent: Mozilla/5.0 (compatible; LauschgiftBackoffice/1.0)\r\n",
    'timeout' => 20, 'ignore_errors' => true,
  ]]);
  $resp = @file_get_contents($url, false, $ctx);
  if ($resp === false) fail('Routendienst (OpenRouteService) ist gerade nicht erreichbar – bitte später erneut versuchen oder die Strecke manuell eintragen.', 502);
  $j = json_decode($resp, true);
  if (!is_array($j)) fail('Unerwartete Antwort vom Routendienst.', 502);
  return $j;
}
function orsGeocode(string $address, string $apiKey): ?array {
  $url = 'https://api.openrouteservice.org/geocode/search?text=' . urlencode($address) . '&size=1';
  $j = orsGet($url, $apiKey);
  $coords = $j['features'][0]['geometry']['coordinates'] ?? null;
  return (is_array($coords) && count($coords) === 2) ? $coords : null;
}
function orsDrivingRoute(array $from, array $to, string $apiKey): array {
  $url = 'https://api.openrouteservice.org/v2/directions/driving-car?start=' . $from[0] . ',' . $from[1] . '&end=' . $to[0] . ',' . $to[1];
  $j = orsGet($url, $apiKey);
  $seg = $j['features'][0]['properties']['segments'][0] ?? null;
  if (!$seg) {
    $msg = '';
    if (isset($j['error'])) $msg = is_string($j['error']) ? $j['error'] : (string)($j['error']['message'] ?? json_encode($j['error']));
    fail('Route konnte nicht berechnet werden' . ($msg !== '' ? ': ' . $msg : '.') . ' Bitte Kilometer/Fahrzeit manuell eintragen.', 502);
  }
  return ['km' => round(((float)$seg['distance']) / 1000, 1), 'minutes' => (int)round(((float)$seg['duration']) / 60)];
}

/* Spalten, die als JSON bzw. Bool behandelt werden */
const JSON_COLS = [
  'settings' => ['value'], 'site_content' => ['value'], 'content_versions' => ['value'],
  'packages' => ['features'],
  'form_templates' => ['fields'], 'forms' => ['fields','answers'],
  'products' => ['bundle'], 'quote_templates' => ['items'], 'documents' => ['event_info'], 'bookings' => ['rider', 'customer_notes', 'event_plan', 'event_plan_internal'], 'rental_contracts' => ['snapshot'],
  'customers' => ['tags', 'tech_check'],
  'equipment' => ['addon_ids', 'images', 'fits_ids'],
  'campaign_pages' => ['cards', 'features', 'form_cfg'],
  'blocks' => ['media'],
  'event_reports' => ['media'],
];
const BOOL_COLS = [
  'packages' => ['public'], 'faq' => ['public'], 'locations' => ['public','image_approved','highlight'], 'friends' => ['public'], 'badges' => ['public','light_bg'], 'blocks' => ['public'], 'event_reports' => ['public'],
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
  'calendar_blocks','content_versions','quote_templates','event_plan_changes','campaign_pages','badges','blocks','event_reports','tech_checks'];
const PK = ['settings' => 'key', 'site_content' => 'key'];   // sonst: id

/* Öffentliche Zugriffe (ohne Login) */
const PUBLIC_READ   = ['site_content','packages','faq','equipment','locations','reviews','friends','equipment_sets','equipment_set_items','campaign_pages','badges','blocks','event_reports'];
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
  /* Neue Vorlagen fuer bestehende Datenbanken nachziehen (seedExtraTemplates legt nur
     an, was namentlich noch fehlt): v81 "Angebot angenommen – Bestaetigung", v82 die
     beiden Sonderfaelle bei der Annahme (Termin inzwischen belegt / Angebot abgelaufen). */
  if ($v < 82) seedExtraTemplates($p);
  if ($v < 80) {
    /* Technik-Check war bisher ein einzelnes JSON-Feld direkt am Kunden - ein Kunde
       konnte also nie mehr als einen Check je gehabt haben. Jetzt eine eigene Tabelle,
       damit beliebig viele Checks/Wartungen je Kunde moeglich sind, jeweils optional
       verknuepft mit dem Angebot, das den Check ausgeloest hat. */
    $p->exec("create table if not exists tech_checks (id text primary key,
      customer_id text not null references customers(id) on delete cascade,
      document_id text references documents(id) on delete set null,
      data text default '{}', created_at text, updated_at text)");
    /* Bestehende Checks vom Kunden in die neue Tabelle uebernehmen, damit keine
       bisherige Arbeit verloren geht. */
    try {
      $old = $p->query("select id, tech_check from customers where tech_check is not null and tech_check != ''")->fetchAll();
      foreach ($old as $c) {
        $p->prepare('insert into tech_checks (id, customer_id, document_id, data, created_at, updated_at) values (?,?,?,?,?,?)')
          ->execute([uuid(), $c['id'], null, $c['tech_check'], now(), now()]);
      }
    } catch (PDOException $e) {}
  }
  if ($v < 79) foreach ([
    /* Standort-Stammdaten: bestehende "Lieblingslocations"-Tabelle um interne, nie an den
       Kunden ausgelieferte Felder erweitern, statt eine eigene Tabelle einzufuehren -
       public=0 blendet einen Eintrag schon jetzt von der Webseite aus. */
    "alter table locations add column contact_name text",
    "alter table locations add column contact_phone text",
    "alter table locations add column technik_notes text",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
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
  if ($v < 48) foreach ([
    "alter table workshop_events add column long_description text",
    "alter table workshop_events add column image_url text",
    "alter table workshop_events add column image_focal text default '50% 50%'",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) { /* Spalte existiert bereits */ } }
  if ($v < 49) {
    /* Set-Preise waren zu niedrig, wenn ein enthaltener Artikel eine Mindestabnahme hat
       (z. B. 6er-Set) und im Set mit weniger als der Mindestmenge eingetragen war -
       bestehende Set-Positionen auf ein Vielfaches der jeweiligen Mindestabnahme aufrunden. */
    try {
      $rows = $p->query("select si.id, si.qty, e.min_qty from equipment_set_items si
        join equipment e on e.id = si.equipment_id where coalesce(e.min_qty,1) > 1")->fetchAll();
      $upd = $p->prepare("update equipment_set_items set qty = ? where id = ?");
      foreach ($rows as $r) {
        $mq = max(1, (int)$r['min_qty']); $q = max(1, (int)$r['qty']);
        $fixed = (int)(ceil($q / $mq) * $mq);
        if ($fixed !== $q) $upd->execute([$fixed, $r['id']]);
      }
    } catch (PDOException $e) {}
  }
  if ($v < 50) foreach ([
    "alter table friends add column image_url text",
    "alter table friends add column image_focal text default '50% 50%'",
  ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) { /* Spalte existiert bereits */ } }
  if ($v < 51) try {
    $p->exec("alter table partners add column discount_pct real");
  } catch (PDOException $e) {}
  if ($v < 52) try {
    $p->exec("alter table customers add column partner_name text");
  } catch (PDOException $e) {}
  if ($v < 53) {
    try { $p->exec("alter table products add column kind text default 'artikel'"); } catch (PDOException $e) {}
    try { $p->exec(quoteTemplatesDdl()); } catch (PDOException $e) {}
  }
  if ($v < 54) mergeOldCatalogPdf($p);
  if ($v < 55) removePlaceholderProducts($p);
  if ($v < 56) reAddCorePositions($p);
  if ($v < 57) fixSieToDuTexts($p);
  if ($v < 58) {
    foreach ([
      "alter table documents add column rental_from text",
      "alter table documents add column rental_to text",
    ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
  }
  if ($v < 59) {
    /* Haupt-/Unterpositionen bei Miettage-Rabattstaffeln: is_header markiert die
       informative Kopfzeile (Artikel, Stückzahl, Stückpreis - zählt nicht zur Summe),
       group_pos verlinkt die Rabattstaffel-Unterzeilen (1.A, 1.B, ...) auf deren pos. */
    foreach ([
      "alter table document_items add column is_header integer default 0",
      "alter table document_items add column group_pos integer",
    ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
  }
  if ($v < 60) renameEquipmentCategories($p);
  if ($v < 61) {
    /* Veranstaltungsplaner: event_plan ist der mit dem Kunden geteilte Teil (Grunddaten,
       Rechnungsadresse, Musik/Playlist, Ablaufplan, Freitext), event_plan_internal bleibt
       immer admin-only (Handover-Notizen, Technik-Zuordnung). event_plan_changes ist die
       Vorschlags-Warteschlange für Änderungen an bereits gesetzten event_plan-Feldern. */
    foreach ([
      "alter table bookings add column event_plan text",
      "alter table bookings add column event_plan_internal text",
    ] as $sql) { try { $p->exec($sql); } catch (PDOException $e) {} }
    try { $p->exec("create table if not exists event_plan_changes (id text primary key,
      booking_id text not null references bookings(id) on delete cascade,
      field_path text not null, field_label text, old_value text, new_value text,
      status text default 'offen', created_at text, reviewed_at text)"); } catch (PDOException $e) {}
  }
  if ($v < 62) legalComplianceUpdate($p);
  if ($v < 63) {
    try { $p->exec("alter table rental_contracts add column deposit_amount real"); } catch (PDOException $e) {}
  }
  if ($v < 64) {
    /* Aktionsseiten: Inhalte der Kampagnen-Minipages in die DB, damit Markus sie
       im Backoffice komplett bearbeiten und einzeln ein-/ausschalten kann. */
    try { $p->exec(campaignPagesDdl()); } catch (PDOException $e) {}
    try { seedCampaignPages($p); } catch (PDOException $e) {}
  }
  if ($v < 65) { try { campaignBackgroundMusicUpdate($p); } catch (PDOException $e) {} }
  if ($v < 66) seedExtraTemplates($p);   // neue Absage-Vorlagen nachziehen (idempotent per Name)
  if ($v < 67) { try { campaignTechCheckPriceUpdate($p); } catch (PDOException $e) {} }
  if ($v < 50) {
    /* Platzhaltertexte ("bitte ... ergänzen") aus den Rechtstexten entfernen und stattdessen
       einen "geprüft"-Status einführen, den das Dashboard abfragen kann. */
    try {
      $row = $p->query("select value from site_content where key='legal'")->fetchColumn();
      $legal = $row ? json_decode($row, true) : null;
      if (is_array($legal)) {
        $legal['impressum'] = str_replace(
          ['E-Mail: (bitte im Backoffice ergänzen)', "\n\nUmsatzsteuer: (Steuernummer / USt-IdNr. bitte im Backoffice ergänzen)"],
          ['E-Mail: lauschgiftmarkus@gmail.com', ''],
          (string)($legal['impressum'] ?? '')
        );
        foreach (['datenschutz', 'agb'] as $lk) {
          if (isset($legal[$lk])) {
            $legal[$lk] = str_replace('Stand: bitte nach juristischer Prüfung ergänzen.', 'Stand: August 2026.', $legal[$lk]);
          }
        }
        if (!isset($legal['reviewed'])) $legal['reviewed'] = false;
        $p->prepare("update site_content set value = ? where key = 'legal'")
          ->execute([json_encode($legal, JSON_UNESCAPED_UNICODE)]);
      }
    } catch (PDOException $e) {}
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
  if ($v < 68) try {
    /* Automatisch erzeugte Workshop-Rechnungen wurden ohne price_mode angelegt und lagen
       damit auf dem alten Spalten-Standard 'netto'. Nur genau diese Zeilen umstellen -
       bewusst netto gesetzte Belege bleiben unangetastet. */
    $p->exec("update documents set price_mode = 'brutto'
      where price_mode = 'netto'
        and intro_text like 'vielen Dank für deine Anmeldung zum Workshop%'");
  } catch (PDOException $e) {}
  if ($v < 69) try { campaignTechCheckBruttoUpdate($p); } catch (PDOException $e) {}
  if ($v < 70) try {
    $p->exec(badgesDdl());
    /* Abschnittstexte nur anlegen, wenn es sie noch nicht gibt - eigene Formulierungen
       von Markus dürfen dabei nicht überschrieben werden. */
    $st = $p->query("select count(*) from site_content where key='badges_sec'");
    if (!(int)$st->fetchColumn())
      $p->prepare('insert into site_content (key,value,updated_at) values (?,?,?)')
        ->execute(['badges_sec', '{"mitglied": {"enabled": true, "show_tech": true, "title": "Wo ich mitmache", "text": "Netzwerke und Portale, in denen ich gelistet bin – wer mag, schaut dort nach, was andere über meine Arbeit schreiben."}, "technik": {"enabled": true, "show_tech": true, "title": "Womit ich arbeite", "text": "Die Technik, die bei mir im Wagen liegt. Keine Werbung, sondern eine ehrliche Auskunft darüber, was ich mitbringe."}}', now()]);
  } catch (PDOException $e) {}
  if ($v < 71) try {
    $p->exec(blocksDdl());
    /* Instagram war bisher fest in die Galerie eingebaut. Damit die Seite danach genauso
       aussieht wie vorher, wird daraus ein eingeschaltetes Modul direkt hinter der Galerie -
       aber nur, wenn ueberhaupt Instagram-Bilder gespiegelt sind. */
    $st = $p->query("select count(*) from blocks where type='instagram'");
    if (!(int)$st->fetchColumn()) {
      $feed = json_decode((string)$p->query("select value from site_content where key='instagram_feed'")->fetchColumn() ?: '{}', true) ?: [];
      $hat = !empty($feed['images']);
      $p->prepare('insert into blocks (id,page,anchor,sort,type,kicker,title,media,layout,public,created_at)
        values (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([uuid(), 'start', 'galerie', 0, 'instagram', 'Frisch aus Instagram', '', '[]', '4',
          $hat ? 1 : 0, now()]);
    }
  } catch (PDOException $e) {}
  if ($v < 72) try {
    /* Bild je Leistungspaket - die Kacheln waren der einzige Bereich, in dem die
       Kaufentscheidung faellt und trotzdem nur Text stand. */
    $p->exec("alter table packages add column image_url text");
    $p->exec("alter table packages add column image_focal text default '50% 50%'");
  } catch (PDOException $e) {}
  if ($v < 72) try {
    $st = $p->query("select count(*) from site_content where key='pack_sec'");
    if (!(int)$st->fetchColumn())
      $p->prepare('insert into site_content (key,value,updated_at) values (?,?,?)')
        ->execute(['pack_sec', '{"images":true}', now()]);
  } catch (PDOException $e) {}
  if ($v < 73) try { $p->exec(eventReportsDdl()); } catch (PDOException $e) {}
  if ($v < 74) try {
    /* Einleitung fuer die neue Auftragsbestaetigung - nur ergaenzen, nie ueberschreiben. */
    $st = $p->query("select value from settings where key='defaults'");
    $defs = json_decode((string)$st->fetchColumn() ?: '{}', true) ?: [];
    if (!isset($defs['confirm_intro'])) {
      $defs['confirm_intro'] = 'schön, dass ihr euch entschieden habt. Hiermit bestätige ich euch den Auftrag verbindlich – der Termin ist ab jetzt für euch reserviert.';
      $p->prepare("update settings set value = ? where key = 'defaults'")
        ->execute([json_encode($defs, JSON_UNESCAPED_UNICODE)]);
    }
  } catch (PDOException $e) {}
  if ($v < 78) try {
    /* Die Merkmale unter dem Hero standen fest im HTML - einmalig mit dem bisherigen
       Wortlaut befuellen, damit im Backoffice keine leeren Felder stehen. */
    foreach ([['hero', '[{"value": "23", "label": "Jahre hinter den Decks"}, {"value": "Plan B", "label": "immer inklusive"}, {"value": "Seeburg", "label": "Premium-Sound"}]'], ['tech_hero', '[{"value": "24 h", "label": "= 1 Miettag"}, {"value": "50 %", "label": "jeder Folgetag"}, {"value": "Hemer", "label": "Lager & Abholung"}]']] as [$key, $json]) {
      $st = $p->prepare('select value from site_content where key = ?');
      $st->execute([$key]);
      $val = $st->fetchColumn();
      if ($val === false) continue;
      $arr = json_decode((string)$val, true);
      if (!is_array($arr) || isset($arr['badges'])) continue;
      $arr['badges'] = json_decode($json, true);
      $p->prepare('update site_content set value = ? where key = ?')
        ->execute([json_encode($arr, JSON_UNESCAPED_UNICODE), $key]);
    }
  } catch (PDOException $e) {}
  if ($v < 77) try {
    /* Die beiden Hero-Ueberschriften standen bisher fest im HTML. Damit sie im Backoffice
       nicht als leeres Feld auftauchen, einmalig mit dem bisherigen Wortlaut befuellen. */
    foreach ([['hero', "Volle Tanzfläche.\n*Ohne Schnickschnack.*"],
              ['tech_hero', "Jedes Wort verständlich.\n*Auch in der schwierigsten Location.*"]] as [$key, $txt]) {
      $st = $p->prepare('select value from site_content where key = ?');
      $st->execute([$key]);
      $val = $st->fetchColumn();
      if ($val === false) continue;
      $arr = json_decode((string)$val, true);
      if (!is_array($arr) || isset($arr['headline'])) continue;
      $arr['headline'] = $txt;
      $p->prepare('update site_content set value = ? where key = ?')
        ->execute([json_encode($arr, JSON_UNESCAPED_UNICODE), $key]);
    }
  } catch (PDOException $e) {}
  if ($v < 76) try {
    /* Merkt sich, dass diese Veranstaltung aus einem Angebot entstanden ist - nur solche
       werden automatisch nachgezogen, von Hand gepflegte bleiben unangetastet. */
    $p->exec('alter table bookings add column auto_from_doc text');
  } catch (PDOException $e) {}
  if ($v < 75) try {
    /* Eckdaten der Veranstaltung direkt am Beleg: Was auf dem Angebot steht, muss auch
       dann noch stimmen, wenn die Buchung spaeter geaendert wird. */
    $p->exec('alter table documents add column event_info text');
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
    ['label' => 'Was wünscht ihr euch am Ende? (z. B. „Reden versteht man bis hinten“, „einfacher bedienbar“)', 'type' => 'textarea'],
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
    ['568064', 'Instrumente', 'Yamaha P-225 B',
     'Stagepiano mit 88 Tasten Graded Hammer Compact (GHC), gewichtet. Klangerzeugung Yamaha CFX VRM Lite mit Key-Off-Samples, 192-stimmig polyphon, 24 Instrument-Presets. Integrierte Lautsprecher 2×7 Watt, Bluetooth-Audio-Wiedergabe über die internen Lautsprecher. Inkl. Sustain-Pedal (M-Audio SP-2), Netzteil und Notenhalter. Maße 1.326×129×272 mm (BxHxT), Gewicht 11,5 kg.',
     35.0, 'https://www.thomann.de/de/yamaha_p_225_b.htm'],
    ['625358', 'DJ-Controller', 'Rane One MKII',
     'B-Stock, professioneller motorisierter DJ-Controller. 7,2" motorisierte Plattenteller, 29 interne Hardwareeffekte, dedizierte Stems Control, High/Low-Pass Filter + 3-Band-EQ, 8 Performance-Pads pro Deck. Eingänge: 2× Line/Phono, 2× Mikrofon (TRS/XLR); Ausgänge: 2× XLR Main, 2× XLR Booth, Kopfhörer. Serato DJ Pro enthalten. Maße 647×345×124 mm, Gewicht 10,68 kg.',
     60.0, 'https://www.thomann.de/de/rane_one_mkii.htm'],
    ['617135', 'Zubehör', 'Gravity WB 123 T B',
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
    ['479553', 'Mischpult', 'IMG Stageline FGA-202', '2-Kanal-Line-Übertrager zur Reduktion von Signalstörungen/Brummschleifen. 2 Eingänge (XLR/6,3-mm-Kombibuchse, 600 Ω), 2 galvanisch getrennte XLR-Ausgänge mit Groundlift-Schalter. Frequenzbereich 20–20.000 Hz, Maße 125×55×75 mm, Gewicht 650 g.', 10.0, 'https://www.thomann.de/de/img_stageline_fga_202.htm', 1, true],
    ['436138', 'Mikrofon', 'the t.bone BD 500 Beta', 'Kondensator-Grenzflächenmikrofon (halbe Niere) für Bass-Drum oder Piano/Sprache, schaltbarer Frequenzgang, 30–20.000 Hz, robustes Metallgehäuse, 3/8"-Gewinde, Gewicht 480 g.', 15.0, 'https://www.thomann.de/de/the_t.bone_bd_500_beta.htm', 1, true],
    ['129171', 'Mikrofon', 'Sennheiser E609 Silver', 'Dynamisches Instrumentenmikrofon (Superniere) für E-Gitarre, Percussion, Bläser, Drums. Frequenzgang 40–15.000 Hz, Gewicht 140 g. Inkl. MZQ-100-Klemme und Tasche.', 15.0, 'https://www.thomann.de/de/sennheiser_e609_evolution.htm', 1, true],
    ['326853', 'Mikrofon', 'Rode M5 MP', 'Stereo-Set Kleinmembran-Kondensatormikrofone (matched pair), Nierencharakteristik, Frequenzbereich 20 Hz–20 kHz, max. 140 dB SPL, benötigt Phantomspeisung 24/48 V, Metallgehäuse.', 20.0, 'https://www.thomann.de/de/rode_m5_mp.htm', 2, true],
    ['395760', 'Zubehör', 'Gravity MS 3122 HDB', 'Kurzes Dreibein-Mikrofonstativ, ausziehbarer Galgen (max. 880 mm), Höhe 320 mm, Zinkdruckguss-Sockel, Gewicht 2,8 kg.', 8.0, 'https://www.thomann.de/de/gravity_ms_3122_hdb_microphone_stand.htm', 2, true],
    ['426274', 'Zubehör', 'Gravity MS 4322 HDB', 'Extra schweres, langes Dreibein-Mikrofonstativ, ausziehbarer Galgen (max. 880 mm), höhenverstellbar 1030–1690 mm, Gewicht 4,26 kg.', 9.0, 'https://www.thomann.de/de/gravity_ms_4322_hdb_microphone_stand.htm', 2, true],
    ['370954', 'Zubehör', 'Gravity MS 4322 B', 'Langes Dreibein-Mikrofonstativ, ausziehbarer Galgen (max. 880 mm), höhenverstellbar 1030–1690 mm, Gewicht 2,7 kg.', 8.0, 'https://www.thomann.de/de/gravity_ms_4322_b_microphone_stand.htm', 2, true],
    ['370937', 'Zubehör', 'Gravity MS 4222 B', 'Kurzes Dreibein-Mikrofonstativ, ausziehbarer Galgen (max. 880 mm), höhenverstellbar 510–740 mm, Gewicht 2,2 kg.', 7.0, 'https://www.thomann.de/de/gravity_ms_4222_b_microphone_stand.htm', 1, true],
    ['435574', 'Zubehör', 'Gravity MS CAB CL 01', 'Cab-Clamp-Mikrofonhalterung für Gitarrenboxen, schwenkbarer Arm, justierbare Klemmen (Klemmbereich 300–400 mm), Gewindeanschluss 3/8", Gewicht 0,6 kg.', 6.0, 'https://www.thomann.de/de/gravity_ms_cab_cl_01.htm', 2, true],
    ['160358', 'Mischpult', 'Behringer DI20 Ultra-DI', 'Aktive 2-Kanal-DI-Box, XLR-Out, -20/40 dB PAD (bis 3000 W), Batterie (9 V) oder Phantomspeisung (15–52 V), Groundlift, auch als 1-auf-2-Splitter nutzbar, Gewicht 0,65 kg.', 8.0, 'https://www.thomann.de/de/behringer_di20_di_box.htm', 1, true],
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
    ['MANUAL-STAIRVILLE-AF40', 'Nebel/Haze', 'Stairville AF-40', 'Kompakte Nebelmaschine mit DMX-Schnittstelle, Nebelausstoß ca. 85 m³/min, Leistung 370 W, Tank 0,25 l, Gewicht 2,1 kg. Wie bei allen Nebelmaschinen: 1 Liter Fluid inklusive, Mehrverbrauch wird über „Nebelfluid-Nachfüllung" nachberechnet. Menge vorerst mit 1 angelegt.', 25.0, 'https://www.thomann.de/de/stairville_af_40_dmx_mini_fog_machine.htm'],
    ['MANUAL-EUROLITE-NH20', 'Nebel/Haze', 'Eurolite NH-20 Tour Fazer', 'DMX-Dunstnebelmaschine (Hazer) im Flightcase, Tank 1,7 l, Verbrauch ca. 2,2 ml/min, Aufheizzeit ca. 1,5 Min., Wurfweite ca. 2 m. Steuerung DMX, Stand-alone, QuickDMX/W-DMX/CRMX über USB. Menge vorerst mit 1 angelegt.', 35.0, 'https://www.thomann.de/de/eurolite_nh_20_tour_fazer.htm'],
    ['MANUAL-ADJ-ENTOURFAZE', 'Nebel/Haze', 'ADJ Entour Faze', 'Fazer/Hazer mit 450-W-Heizelement, Nebelausstoß ca. 113 m³/min, Verbrauch ca. 4,5 ml/min, Aufheizzeit ca. 45 Sek. Tank 3 l, verwendet normale Wasser-Nebelfluid. Steuerung über Menütasten/Bar-Display, Trigger-Schalter, optional DMX oder mitgeliefertes Kabel-Fernbedienung. Maße 414×202×303 mm, Gewicht 4,5 kg. Menge vorerst mit 1 angelegt.', 30.0, 'https://www.thomann.de/de/adj_entour_faze.htm'],
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

/* Status eines Dokuments nachschlagen (mit Cache) - entscheidet, ob eine Positions-
   Aenderung protokollierenswert ist (siehe document_items PATCH/DELETE): waehrend der
   ganz normalen Ersterfassung (entwurf) soll das Protokoll nicht vollgeschrieben werden. */
function docStatusFor(PDO $p, ?string $docId): string {
  static $cache = [];
  if ($docId === null) return 'entwurf';
  if (!array_key_exists($docId, $cache)) {
    $st = $p->prepare('select status from documents where id = ?');
    $st->execute([$docId]);
    $cache[$docId] = $st->fetchColumn() ?: 'entwurf';
  }
  return $cache[$docId];
}

/* Festgeschrieben = Rechnungsartige Dokumente, die den Entwurfsstatus verlassen haben */
function docLockedRow(array $d): bool {
  return !in_array($d['doc_type'] ?? '', ['angebot', 'bestaetigung', 'lieferschein'])
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
    /* Geht automatisch an den Kunden, sobald er ein Angebot im Portal annimmt - das Portal
       verspricht ihm an der Stelle ausdruecklich eine Bestaetigung. */
    [96, 'Angebot angenommen – Bestätigung', 'Angebot {nummer} angenommen – danke!',
      "Hallo {vorname},\n\ndanke für euer Vertrauen – ihr habt das Angebot {nummer} angenommen, damit ist {termin} fest bei mir reserviert.\n\nWie es weitergeht: Ihr bekommt von mir noch die Auftragsbestätigung und ggf. eine Abschlagsrechnung. Alles Weitere (Musikwünsche, Ablauf, Fragen) klären wir dann ganz entspannt bis zum Termin.\n\nEuer Angebot findet ihr jederzeit hier – Login ist eure Postleitzahl:\n{link}\n\nWenn euch vorher etwas einfällt: einfach anrufen ({telefon}) oder auf diese Mail antworten.\n\nViele Grüße\nMarkus"],
    /* Sonderfaelle bei der Annahme im Portal: Der Termin ist seit dem Angebot anderweitig
       fest gebucht worden - dann kann Markus nicht liefern, will den Kunden aber nicht
       ohne Alternative stehen lassen. */
    [97, 'Termin nicht mehr verfügbar – DJ-Vermittlung', 'Euer Termin am {datum} – leider inzwischen vergeben',
      "Hallo {vorname},\n\nihr wolltet gerade das Angebot {nummer} annehmen – und genau das tut mir jetzt richtig leid: euer Termin am {datum} ist bei mir in der Zwischenzeit fest gebucht worden. Das Angebot kann ich deshalb nicht mehr erfüllen, so ehrlich muss ich sein.\n\nWas ich euch aber anbieten kann: Ich kenne über meine Partner-Agentur DJ Bande (Münster) richtig gute Kollegen und suche euch gern persönlich einen passenden DJ raus – kostenlos, ihr müsst nur kurz zustimmen. Das geht direkt hier:\n{link}\n\nWenn ihr lieber erst sprechen wollt: ruft mich einfach an ({telefon}) oder antwortet auf diese Mail.\n\nViele Grüße\nMarkus"],
    /* Der Kunde nimmt nach Ablauf der Gueltigkeit an: Annahme wird festgehalten, aber
       der Termin ist noch nicht fest - Markus prueft erst Verfuegbarkeit und Preis. */
    [98, 'Angebot angenommen – abgelaufen, wird geprüft', 'Angebot {nummer} angenommen – ich prüfe das kurz',
      "Hallo {vorname},\n\ndanke für euer Vertrauen – ihr habt das Angebot {nummer} angenommen. Eine Kleinigkeit muss ich dazu sagen: Das Angebot war schon abgelaufen (gültig bis {gueltig}). Ich schaue mir deshalb kurz an, ob {termin} bei mir noch frei ist und ob die Preise noch so passen, und melde mich dann ganz schnell bei euch.\n\nBis dahin gilt: Der Termin ist noch nicht fest zugesagt – ich will euch nichts versprechen, was ich dann nicht halten kann.\n\nEuer Angebot findet ihr weiterhin hier – Login ist eure Postleitzahl:\n{link}\n\nFragen? Einfach anrufen ({telefon}) oder auf diese Mail antworten.\n\nViele Grüße\nMarkus"],
    [92, 'Workshop-Bestätigung (Zahlung eingegangen)', 'Dein Platz ist fix!',
      "Hallo {vorname},\n\ndeine Zahlung ist da – damit ist dein Workshop-Platz verbindlich reserviert!\n\nWann: {datum}\nWo: Lager Hemer, Büttmecker Weg 35c\n\nBring gern dein eigenes Equipment-Problem mit – wir schauen uns echte Fälle an. Getränke gehen auf mich.\n\nBis bald!\nMarkus"],
    /* Absage-Bausteine: Jede Absage soll persönlich klingen und möglichst in eine
       Vermittlung münden - eine unpassende Anfrage ist trotzdem ein Mensch, der
       gerade Musik für seine Feier sucht. */
    [93, 'Absage: Budget passt nicht', 'Zu eurer Anfrage für den {datum}',
      "Hallo {vorname},\n\ndanke, dass ihr an mich gedacht habt – und danke, dass ihr euer Budget offen dazugeschrieben habt. Das macht es für uns beide einfacher.\n\nEhrlich gesagt komme ich in dem Rahmen nicht raus: Bei mir hängen an einem Abend Anfahrt, Auf- und Abbau, die Technik und der Abend selbst dran, deshalb liege ich deutlich darüber. Ich will euch nichts verkaufen, das sich für euch nicht richtig anfühlt.\n\nWas ich euch aber anbieten kann:\n– Feiert ihr unter der Woche oder tagsüber, sieht die Rechnung ganz anders aus. Sagt mir einfach Bescheid, dann rechne ich das durch.\n– Wenn der Termin feststeht: Ich kenne Kollegen, die günstiger einsteigen und trotzdem gut sind. Sagt kurz Bescheid, dann frage ich für euch herum.\n\nSo oder so: Ich drücke euch die Daumen, dass es ein schöner Abend wird.\n\nViele Grüße\nMarkus"],
    [94, 'Absage: Anfahrt zu weit', 'Eure Feier am {datum} in {ort}',
      "Hallo {vorname},\n\nschön, dass ihr euch gemeldet habt! Leider muss ich ehrlich sein: {ort} ist von Hemer aus so weit weg, dass Anfahrt und Übernachtung euren Preis deutlich nach oben treiben würden – und dafür bekommt ihr vor Ort jemanden, der genauso gut ist, ohne dass ihr meine Fahrerei mitbezahlt.\n\nWenn ihr mögt, frage ich in meinem Kollegen-Netzwerk nach jemandem in eurer Ecke. Schreibt mir dafür kurz, was für eine Feier es wird und was euch musikalisch wichtig ist – dann melde ich mich mit Vorschlägen.\n\nUnd falls ihr doch unbedingt mich wollt: Sagt es, dann rechne ich es euch einmal ehrlich durch, damit ihr die Zahl kennt.\n\nViele Grüße\nMarkus"],
    [95, 'Absage: musikalisch nicht mein Ding', 'Zu eurer Anfrage für den {datum}',
      "Hallo {vorname},\n\ndanke für eure Anfrage und dafür, dass ihr so klar geschrieben habt, was ihr musikalisch wollt. Genau deshalb sage ich euch offen: Das ist nicht mein Zuhause. Ich könnte den Abend irgendwie über die Bühne bringen, aber ihr hättet nicht den DJ, den diese Feier verdient – und ich wäre nicht der, der ich sonst bin.\n\nIhr habt euch etwas Bestimmtes vorgestellt, und dafür gibt es Leute, die genau dafür brennen. Wenn ihr wollt, frage ich in meinem Netzwerk nach jemandem, der das wirklich draufhat.\n\nSchreibt mir einfach kurz, ob ich das machen soll.\n\nViele Grüße\nMarkus"],
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
      title text not null, description text, long_description text, audience text default '', event_date text not null, start_time text, end_time text,
      location text, price_net real, capacity integer default 8, public integer default 0,
      image_url text, image_focal text default '50% 50%', created_at text)",
    "create table if not exists workshop_signups (id text primary key,
      workshop_id text not null references workshop_events(id) on delete cascade,
      name text not null, email text, phone text, seats integer default 1, message text,
      q_music text, q_challenge text, q_goal text,
      street text, zip text, city text, invoice_id text,
      status text default 'angemeldet', created_at text)",
  ];
}

function quoteTemplatesDdl(): string {
  return "create table if not exists quote_templates (id text primary key, sort integer default 0,
    name text not null, intro_text text, outro_text text, items text default '[]', created_at text)";
}

/* Einmaliger Daten-Merge: Positionen aus Markus' altem Angebot (PDF aus der Vorgänger-Software)
   in den Produktkatalog übernehmen. Nichts wird gelöscht oder überschrieben - existiert schon ein
   Produkt mit demselben Namen, wird es unangetastet gelassen; weicht dessen Preis vom PDF-Preis ab,
   landet der Konflikt in settings.catalog_merge_conflicts zur manuellen Prüfung im Backoffice. */
function mergeOldCatalogPdf(PDO $p): void {
  $items = [
    // sku, name, category, kind, unit, price_net, description
    ['DJ-ZUSATZSTD', 'Zusatzstunde', 'DJ-Leistung', 'dienstleistung', 'Std.', 120.00, 'Abrechnung im 15-Minuten-Takt.'],
    ['DJ-INKL-TECH', 'DJ inkl DJ-Technik', 'DJ-Leistung', 'dienstleistung', 'pauschal', 150.00, 'DJ-Controller und Laptop, Funkmikrofon. Mindestbuchungsdauer 8 Stunden.'],
    ['DJ-TRAUUNG-TECH', 'Technik für freie Trauung', 'DJ-Leistung', 'dienstleistung', 'Stk.', 179.00, 'Für freie Trauung und Sektempfang: Tonanlage für Sprache und Gesang, Funkmikrofon, nutzbar von Musikern und Traurednern, lockere Hintergrundmusik zum Sektempfang. Zusätzlich müssen DJ- bzw. Technikerstunden gebucht werden.'],
    ['DJ-UEBERNACHT', 'Übernachtungspauschale', 'DJ-Leistung', 'dienstleistung', 'pauschal', 130.00, 'Ab 1 Stunde Anfahrt eine Übernachtung, ab 3,5 Stunden Anfahrt zwei Übernachtungen.'],
    ['DJ-AUFABBAU', 'Auf- und Abbau', 'DJ-Leistung', 'dienstleistung', 'pauschal', 99.00, 'Nur berechnet, wenn zusätzlich zur Location gefahren werden muss - Auf-/Abbau direkt vor bzw. nach dem Auflegen ist kostenlos.'],
    ['LI-MAXIV2', 'Ape Labs Maxi V2 grey/creme', 'Licht', 'artikel', 'Stk.', 15.00, 'Lichtstarker, kompakter Akku-LED-Spot für hohe Wände/Objekte, auch als Tanzflächen-Unterstützung und im Außenbereich.'],
    ['LI-NEONTUBE', 'Ape Labs Neon Tube', 'Licht', 'artikel', 'Stk.', 20.00, 'Hochwertiger Akku-LED-Effekt für schöne Lichtakzente.'],
    ['LI-THEATERSPOT', 'RGB WW Theater Spot', 'Licht', 'artikel', 'Stk.', 45.00, 'Theater-Spot in klassischer Optik mit Torblenden und gleichmäßiger Ausleuchtung.'],
    ['LI-MOVINGHEAD', 'Multi Moving Head', 'Licht', 'artikel', 'Stk.', 45.00, 'Vom scharfen Beam über Wash- bis Derby-Effekt.'],
    ['LI-NEBEL-GROSS', 'Nebelmaschine groß', 'Licht', 'artikel', 'Stk.', 75.00, 'Große 1,6 kW starke Nebelmaschine mit viel Output.'],
    ['LI-NEBEL-KLEIN', 'Nebelmaschine klein', 'Licht', 'artikel', 'Stk.', 25.00, 'Kompakte Nebelmaschine mit geringem Stromverbrauch.'],
    ['LI-HAZER-ENTOUR', 'Tour Hazer Entour', 'Licht', 'artikel', 'Stk.', 35.00, 'Feiner Dunst statt dichtem Nebel, macht Strahleneffekte sichtbar.'],
    ['LI-HAZER-NH20', 'Tour Hazer NH-20 im Case', 'Licht', 'artikel', 'Stk.', 50.00, 'Unauffälliger Hazer für kleine bis mittlere Veranstaltungen, Hochzeiten und Gala.'],
    ['TO-SEEBURG-A3', 'Seeburg Acoustic Line A3', 'Ton', 'artikel', 'Stk.', 35.00, 'Weit tragender, glasklarer High/Mid-Lautsprecher, 2x8"/1".'],
    ['TO-SEEBURG-X2', 'Seeburg Acoustic Line X2', 'Ton', 'artikel', 'Stk.', 35.00, 'Kompakter, fein auflösender Coax-Lautsprecher - Hauptlautsprecher, Monitor oder Delay-Line.'],
    ['TO-SEEBURG-A6', 'Seeburg Acoustic Line A6', 'Ton', 'artikel', 'Stk.', 40.00, 'Ausgewogener, klangstarker Lautsprecher in klassischer Bauform mit Monitorschräge.'],
    ['TO-EV-EVERSE8', 'EV Everse 8', 'Ton', 'artikel', 'Stk.', 69.00, 'Akkulautsprecher für Reden, freie Trauung, Singer/Songwriter und Hintergrundmusik.'],
    ['TO-JBL-PARTYBOX110', 'JBL Partybox 110', 'Ton', 'artikel', 'Stk.', 30.00, 'Bluetooth-Akku-Lautsprecher für die kleine Feier im Garten oder in kleiner Location.'],
    ['TO-SEEBURG-SUB1201DP', 'Seeburg Acoustic Line G Sub 1201dp++', 'Ton', 'artikel', 'Stk.', 60.00, 'Aktiver 12"-Subwoofer, steuert 2 kleine Topteile und einen weiteren 12" an.'],
    ['TO-SEEBURG-SUB1201PASSIV', 'Seeburg Acoustic Line G Sub 1201 (passiv)', 'Ton', 'artikel', 'Stk.', 40.00, 'Passiver 12"-Subwoofer.'],
    ['TO-PLAUDIO-B215', 'PL Audio B215 aktiver Subwoofer', 'Ton', 'artikel', 'Stk.', 100.00, 'Aktiver Doppel-15"-Subwoofer, steuert bis zu 2 Topteile an.'],
    ['DE-SPIEGELKUGEL50', '50cm Spiegelkugel', 'Deko', 'artikel', 'Stk.', 100.00, 'Inkl. Motor, Distanzstange und Bodenplatte.'],
    ['TO-ALLENHEATH-CQ20B', 'Allen & Heath CQ20B', 'Ton', 'artikel', 'Stk.', 45.00, 'Digitales 20-Kanal-Bühnenmischpult.'],
    ['TO-BEHRINGER-FLOW8', 'Behringer Flow 8', 'Ton', 'artikel', 'Stk.', 20.00, 'Kleiner digitaler Bühnenmischer mit Bluetooth und App-Steuerung.'],
    ['ZU-DEZIBELMESSER', 'Dezibel Messgerät', 'Zubehör', 'artikel', 'Stk.', 5.00, 'Messgerät zur Messung der Lautstärke in Echtzeit.'],
    ['TO-SENNHEISER-EW835', 'Sennheiser EW 835 Funkstrecke', 'Ton', 'artikel', 'Stk.', 40.00, 'Hochwertige Funkstrecke mit großer Reichweite und sauberem Ton.'],
    ['TO-SENNHEISER-E835S', 'Sennheiser E835S', 'Ton', 'artikel', 'Stk.', 10.00, 'Kabelgebundenes Mikrofon.'],
    ['TO-SHURE-SM58', 'Shure SM58', 'Ton', 'artikel', 'Stk.', 10.00, 'Der Klassiker unter den Gesangsmikrofonen.'],
    ['TO-SHURE-BETA57A', 'Shure Beta 57a', 'Ton', 'artikel', 'Stk.', 10.00, 'Mikrofon.'],
    ['TO-SHURE-SM57', 'Shure SM57', 'Ton', 'artikel', 'Stk.', 10.00, 'Mikrofon.'],
    ['ZU-BODENPLATTE60ECKIG', 'Bodenplatte 60cm eckig', 'Zubehör', 'artikel', 'Stk.', 7.50, 'Bodenplatte 14 kg, 60x60 cm, mit 3x M20-Gewinde.'],
    ['ZU-BODENPLATTE-TOURING', 'Bodenplatte Touring, eckig', 'Zubehör', 'artikel', 'Stk.', 10.00, '60x60 cm Bodenplatte mit 3x M20-Gewinde sowie Bohrungen für Traversenaufnahme.'],
    ['ZU-DISTANZ-KURZ', 'Distanzstange kurz', 'Zubehör', 'artikel', 'Stk.', 5.00, 'M20 auf 35mm.'],
    ['ZU-DISTANZ-LANG', 'Distanzstange lang', 'Zubehör', 'artikel', 'Stk.', 5.00, 'M20 auf 35mm.'],
    ['ZU-BODENPLATTE-RUND-GRAVITY', 'Bodenplatte rund Gravity', 'Zubehör', 'artikel', 'Stk.', 7.50, 'Schwere Rundplatte.'],
    ['ZU-BODENPLATTE-RUND-KM', 'Bodenplatte rund K&M', 'Zubehör', 'artikel', 'Stk.', 7.50, 'Mittelschwere Bodenplatte rund.'],
    ['ZU-SPEAKON-10M', 'Speakon Kabel 4-polig, 10m', 'Zubehör', 'artikel', 'Stk.', 5.00, 'Lautsprecherkabel mit Speakon-Stecker 4-polig, 2,5mm².'],
    ['ZU-SPEAKON-3M', 'Speakon Kabel 4-polig, 3m', 'Zubehör', 'artikel', 'Stk.', 2.00, 'Lautsprecherkabel mit Speakon-Stecker 4-polig, 2,5mm².'],
    ['LI-WOLFMIX-W1', 'Wolfmix W1', 'Licht', 'artikel', 'Stk.', 40.00, 'Lichtsteuerung.'],
    ['DJ-BASISPAKET', 'DJ Basispaket', 'DJ-Leistung', 'dienstleistung', 'Stk.', 875.00, '7 Stunden DJ (18-1 Uhr), inkl. DJ-Pult (schwarz oder Holzoptik), DJ-Mischpult und Laptop. Nur in Verbindung mit Licht- und Tontechnik buchbar.'],
    ['DJ-PULT-SCHWARZ', 'Optik DJ Pult schwarz', 'DJ-Zubehör', 'artikel', 'Stk.', 0.00, ''],
    ['DJ-PULT-HOLZ', 'Optik DJ Pult Holz', 'DJ-Zubehör', 'artikel', 'Stk.', 0.00, ''],
    ['LI-APELABS-CONNECT', 'Ape Labs Connect', 'Licht', 'artikel', 'Stk.', 15.00, ''],
    ['TO-SENNHEISER-E609', 'Sennheiser e609', 'Ton', 'artikel', 'Stk.', 10.00, 'Mikrofon zur Abnahme von Gitarrenamps.'],
    ['TO-AUDIX-D6', 'Audix D6', 'Ton', 'artikel', 'Stk.', 12.00, 'Kickdrum-Mikrofon.'],
  ];
  $conflicts = [];
  foreach ($items as [$sku, $name, $category, $kind, $unit, $price, $desc]) {
    $st = $p->prepare("select id, sku, price_net from products where lower(name)=lower(?) or sku=?");
    $st->execute([$name, $sku]);
    $existing = $st->fetch();
    if ($existing) {
      $exPrice = $existing['price_net'] !== null ? (float)$existing['price_net'] : null;
      if ($exPrice !== null && abs($exPrice - $price) > 0.001) {
        $conflicts[] = ['name' => $name, 'existing_price' => $exPrice, 'pdf_price' => $price, 'sku' => $existing['sku']];
      }
      continue;
    }
    $useSku = $sku; $n = 1;
    while (true) {
      $chk = $p->prepare("select 1 from products where sku=?"); $chk->execute([$useSku]);
      if (!$chk->fetchColumn()) break;
      $n++; $useSku = $sku . '-' . $n;
    }
    $p->prepare("insert into products (id,sku,sort,category,name,description,unit,kind,price_net,bundle,addon_sku,active,created_at)
      values (?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([uuid(), $useSku, 0, $category, $name, $desc, $unit, $kind, $price, '[]', null, 1, now()]);
  }
  if ($conflicts) {
    $p->prepare("insert into settings (key,value,updated_at) values ('catalog_merge_conflicts', ?, ?)
        on conflict(key) do update set value = excluded.value, updated_at = excluded.updated_at")
      ->execute([json_encode($conflicts, JSON_UNESCAPED_UNICODE), now()]);
  }
}

/* Die fünf Beispiel-Positionen (+ Beispiel-Set) aus der allerersten Katalog-Seed sind
   inzwischen durch den echten Altkatalog (mergeOldCatalogPdf) ersetzt - werden entfernt. */
function removePlaceholderProducts(PDO $p): void {
  $skus = ['DJ-100', 'DJ-110', 'TON-200', 'LICHT-300', 'FAHRT-900', 'PAKET-500'];
  $in = implode(',', array_fill(0, count($skus), '?'));
  $p->prepare("delete from products where sku in ($in)")->execute($skus);
}

/* DJ-100/DJ-110/TON-200/LICHT-300 waren keine reinen Fake-Platzhalter, sondern echte
   Standard-Leistungspositionen - werden hier mit den korrekten Werten neu angelegt.
   FAHRT-900 kommt bewusst nicht zurück: Anfahrt läuft jetzt über die automatische
   Streckenberechnung im Rider (siehe routing/distance), nicht mehr über einen
   statischen Katalog-Artikel. */
function reAddCorePositions(PDO $p): void {
  $rows = [
    ['DJ-100', 'DJ-Leistung', 'DJ-Abend Basis', 'DJ-Leistung bis 6 Stunden inkl. Musikplanung, Kennenlerngespräch, kompakter Ton- und Lichttechnik, Auf- und Abbau.', 'pausch.', 'dienstleistung', 1200],
    ['DJ-110', 'DJ-Leistung', 'Zusätzliche DJ-Stunde', 'Verlängerung über den vereinbarten Zeitraum hinaus, je angefangene Stunde.', 'Std.', 'dienstleistung', 100],
    ['TON-200', 'Ton', 'Ton für freie Trauung', 'Funkmikrofon und Lautsprecher für Trauredner sowie Musik-Einspielungen im Außenbereich, inkl. Backup-Akku.', 'pausch.', 'dienstleistung', 200],
    ['LICHT-300', 'Licht', 'Ambiente-Licht Basis', 'Dezentes Grundlicht passend zur Location (Uplights, Tanzflächenlicht).', 'pausch.', 'dienstleistung', 150],
  ];
  foreach ($rows as [$sku, $cat, $n, $d, $u, $kind, $pr]) {
    $chk = $p->prepare("select 1 from products where sku=?"); $chk->execute([$sku]);
    if ($chk->fetchColumn()) continue;
    $p->prepare("insert into products (id,sku,sort,category,name,description,unit,kind,price_net,bundle,active,created_at)
      values (?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([uuid(), $sku, 0, $cat, $n, $d, $u, $kind, $pr, '[]', 1, now()]);
  }
}

/* Technikkategorien neu zugeschnitten: Piano -> Instrumente, Rigging/Stativ -> Zubehör
   (zusammengelegt), Signal -> Mischpult (zusammengelegt), Effekt -> Nebel/Haze. Nur die
   Kategorie-Beschriftung ändert sich, die Artikel selbst bleiben unverändert. Idempotent
   (wirkt nur auf Artikel, die noch die alte Kategorie tragen). */
function renameEquipmentCategories(PDO $p): void {
  $map = ['Piano' => 'Instrumente', 'Rigging' => 'Zubehör', 'Stativ' => 'Zubehör',
    'Signal' => 'Mischpult', 'Effekt' => 'Nebel/Haze'];
  $st = $p->prepare('update equipment set category = ? where category = ?');
  foreach ($map as $old => $new) $st->execute([$new, $old]);
}

/* Rechtstexte nachschärfen (August 2026): §36 VSBG-Hinweis im Impressum ergänzen (die
   EU-OS-Streitbeilegungsplattform selbst wurde zum 20.07.2025 abgeschaltet - ein Link darauf
   wäre jetzt sogar irreführend/abmahnfähig, daher bewusst NICHT ergänzt), ein eigenständiges
   Muster-Widerrufsformular als Anlage für den Ausnahmefall aus AGB Ziffer 7 bereitstellen, und
   der AGB-Volltext bekommt eine kurze, warme Zusammenfassung vorangestellt statt direkt mit
   Paragraphen zu starten. Idempotent: ändert nur Texte, die noch dem alten Seed-Stand
   entsprechen - eigene Anpassungen von Markus im Backoffice bleiben unangetastet. */
function legalComplianceUpdate(PDO $p): void {
  $row = $p->query("select value from site_content where key='legal'")->fetchColumn();
  $legal = $row ? json_decode($row, true) : null;
  if (!is_array($legal)) return;
  $changed = false;
  $oldImpressum = "Angaben gemäß § 5 DDG\n\nMarkus Jankowski\nDJ Lauschgift\nBüttmecker Weg 35c\n58675 Hemer\n\nTelefon: 01523 6439373\nE-Mail: lauschgiftmarkus@gmail.com\n\nVerantwortlich für den Inhalt: Markus Jankowski (Anschrift wie oben)";
  if (($legal['impressum'] ?? '') === $oldImpressum) {
    $legal['impressum'] = $oldImpressum . "\n\nVerbraucherstreitbeilegung: Ich bin nicht verpflichtet und nicht bereit, an einem Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen (§ 36 VSBG).";
    $changed = true;
  }
  $oldAgbStart = "Allgemeine Geschäftsbedingungen (AGB)\n\n1. Geltungsbereich";
  if (str_starts_with((string)($legal['agb'] ?? ''), $oldAgbStart)) {
    $legal['agb'] = agbIntro() . (string)$legal['agb'];
    $changed = true;
  }
  if (empty($legal['widerrufsformular'])) {
    $legal['widerrufsformular'] = widerrufsformularText();
    $changed = true;
  }
  if ($changed) {
    $p->prepare("update site_content set value=?, updated_at=? where key='legal'")
      ->execute([json_encode($legal, JSON_UNESCAPED_UNICODE), now()]);
  }
}
function agbIntro(): string {
  return "Kurz und ehrlich, bevor es juristisch wird: Ich will, dass ihr genau wisst, woran ihr seid – ohne Kleingedrucktes-Schreck erst am Ende. Falls ich mal ausfalle, bekommt ihr einen passenden Ersatz-DJ vorgeschlagen oder euer Geld zurück. Sagt ihr die Veranstaltung ab, gilt eine faire, gestaffelte Regelung je nachdem wie kurzfristig das passiert (steht weiter unten genau drin) – so kann ich meine Zeit auch für andere Paare freihalten. Bei Fragen zu irgendeinem Punkt: einfach anrufen oder schreiben, das klären wir persönlich statt über Anwälte.\n\nUnd jetzt der vollständige, rechtlich verbindliche Text:\n\n";
}
function widerrufsformularText(): string {
  return "Muster-Widerrufsformular\n\nDieses Formular ist nur relevant, falls im Einzelfall ausnahmsweise ein Widerrufsrecht besteht (siehe Ziffer 7 der AGB) – für die allermeisten Buchungen mit festem Termin gilt: kein Widerrufsrecht.\n\nWenn ihr den Vertrag trotzdem widerrufen wollt, füllt dieses Formular aus und schickt es an:\n\nMarkus Jankowski\nDJ Lauschgift\nBüttmecker Weg 35c\n58675 Hemer\nE-Mail: lauschgiftmarkus@gmail.com\n\n– Hiermit widerrufe(n) ich/wir den von mir/uns abgeschlossenen Vertrag über die Erbringung folgender Dienstleistung:\n– Bestellt am:\n– Name des/der Verbraucher(s):\n– Anschrift des/der Verbraucher(s):\n– Unterschrift des/der Verbraucher(s) (nur bei Mitteilung auf Papier):\n– Datum:";
}

/* Bei jeder eingehenden Anfrage direkt Kunde + Buchung + Veranstaltungsplaner anlegen, damit
   Markus sofort im Backoffice weiterarbeiten kann, ohne erst manuell "In CRM übernehmen" zu
   klicken. Sucht den Kunden per E-Mail (case-insensitive) und legt ihn nur an, wenn noch keiner
   existiert - dieselbe Zuordnungslogik wie bei portal/account/register, damit eine spätere
   Registrierung mit derselben Adresse automatisch an denselben Datensatz andockt. Ohne Termin
   wird keine Buchung angelegt (analog zur bisherigen manuellen inqToCust()-Übernahme). */
/* Liest/schreibt einen Wert per Punkt-Pfad (z.B. "basics.venue_name") in einem verschachtelten
   Array - genutzt für den Veranstaltungsplaner, dessen event_plan-Struktur frei bleibt (kein
   festes Schema), damit plan-suggest jedes Feld darin ansprechen kann. */
function planPathGet(array $data, string $path) {
  $cur = $data;
  foreach (explode('.', $path) as $k) {
    if (!is_array($cur) || !array_key_exists($k, $cur)) return null;
    $cur = $cur[$k];
  }
  return $cur;
}
function planPathSet(array &$data, string $path, $value): void {
  $keys = explode('.', $path);
  $last = array_pop($keys);
  $cur = &$data;
  foreach ($keys as $k) {
    if (!isset($cur[$k]) || !is_array($cur[$k])) $cur[$k] = [];
    $cur = &$cur[$k];
  }
  $cur[$last] = $value;
}

/* Zerlegt den eingegebenen Namen in Vor-/Nachname. Anfragen kommen oft als Paar oder
   Familie herein - "Familie Brinkmann" darf nicht zu einer Anrede "Hallo Familie,"
   führen und "Lena und Tobias Vogt" nicht zum Nachnamen "und Tobias Vogt". */
function splitPersonName(string $name): array {
  $name = trim(preg_replace('/\s+/u', ' ', $name));
  if ($name === '') return ['', ''];
  $words = explode(' ', $name);
  $last = end($words);
  if (preg_match('/^(Familie|Fam\.|Eheleute|Herr|Herrn|Frau)\b/iu', $name))
    return [$name, ''];   // Anrede "Hallo Familie Brinkmann," - der Nachname steckt schon drin
  if (preg_match('/\s(und|&|\+)\s/iu', $name) && count($words) > 2)
    return [implode(' ', array_slice($words, 0, -1)), $last];  // "Lena und Tobias" / "Vogt"
  if (count($words) === 1) return [$name, ''];
  return [$words[0], implode(' ', array_slice($words, 1))];
}

function autoInquiryPlanner(PDO $p, array $row): void {
  if (empty($row['email'])) return;
  $email = mb_substr(strtolower(trim((string)$row['email'])), 0, 160);
  /* Zwei Kunden mit derselben Adresse sind moeglich (von Hand doppelt angelegt). Ohne
     feste Reihenfolge entscheidet der Zufall, an welchem die Anfrage haengt - deshalb
     gewinnt der mit Portal-Konto, sonst der aeltere. */
  $st = $p->prepare("select id from customers where lower(email) = ?
    order by (portal_hash is not null) desc, coalesce(created_at,'') asc limit 1");
  $st->execute([$email]);
  $custId = $st->fetchColumn();
  if (!$custId) {
    [$vorname, $nachname] = splitPersonName((string)$row['name']);
    /* Vereine, Gemeinden und Schulen sind die Kernzielgruppe der Technik-Seite - als
       "privat" mit zerlegtem Namen ("Schuetzenverein" / "Testdorf") wären sie im
       Backoffice falsch einsortiert und in Anschreiben falsch angeredet. */
    /* Zusammengesetzte Wörter mitnehmen ("Schützenverein", "Musikverein", "Realschule"),
       kurze Rechtsformen dagegen nur als eigenständiges Wort, damit z.B. "Aga" nicht
       als AG durchgeht. */
    $name = (string)$row['name'];
    $istOrg = (bool)preg_match('/(verein|gemeinde|schule|gymnasium|kollegium|verband|feuerwehr|stiftung|kirche|pfarrei|kita|kindergarten|jugendzentrum|förderkreis|foerderkreis|freundeskreis)/iu', $name)
      || (bool)preg_match('/(^|\s)(e\.?\s?v\.?|gmbh|mbh|ug|ag|kg|ohg|gbr|firma|stadt|klub|club)(\s|$|\.|,)/iu', $name);
    $custId = uuid();
    $p->prepare('insert into customers (id, kind, status, first_name, last_name, company, email, phone, source, created_at, updated_at)
      values (?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$custId, $istOrg ? 'firma' : 'privat', 'lead',
        $istOrg ? '' : ($vorname ?: $row['name']), $istOrg ? '' : $nachname,
        $istOrg ? mb_substr(trim((string)$row['name']), 0, 160) : null,
        $email, $row['phone'] ?? null, 'Homepage', now(), now()]);
  }
  /* Den Anfragetext als Timeline-Eintrag sichern - sonst steht er nur in der
     Anfragen-Liste und fehlt später im Kundendatensatz (der manuelle Weg über
     "In CRM übernehmen" legt diesen Eintrag ebenfalls an). */
  if (trim((string)($row['message'] ?? '')) !== '' || !empty($row['event_type'])) {
    $inhalt = trim(
      (!empty($row['event_type']) ? 'Anlass: ' . $row['event_type'] . "\n" : '') .
      (!empty($row['event_date']) ? 'Termin: ' . $row['event_date'] . "\n" : '') .
      (!empty($row['location']) ? 'Ort: ' . $row['location'] . "\n" : '') .
      (!empty($row['guests']) ? 'Gäste: ' . $row['guests'] . "\n" : '') .
      "\n" . (string)($row['message'] ?? ''));
    $p->prepare('insert into communications (id,customer_id,channel,direction,subject,content,occurred_at,created_at)
      values (?,?,?,?,?,?,?,?)')
      ->execute([uuid(), $custId, 'note', 'in', 'Anfrage über die Website', $inhalt, now(), now()]);
  }
  $p->prepare('update inquiries set customer_id = ? where id = ?')->execute([$custId, $row['id']]);
  if (($row['event_type'] ?? '') === 'Technik-Check bestehende Anlage') {
    $formLink = autoTechCheckInvite($p, $custId, $row);
    if ($formLink) $GLOBALS['_techCheckFormLink'] = $formLink;
  }
  if (empty($row['event_date'])) return;
  /* Termine in der Vergangenheit (Tippfehler im Datumsfeld) erzeugen keine Buchung -
     sonst hängt ein Geisterauftrag dauerhaft in der Liste. Die Anfrage selbst bleibt. */
  if ($row['event_date'] < gmdate('Y-m-d')) return;
  /* Schickt jemand dieselbe Anfrage mehrfach ab (Ungeduld, Zurück-Taste), darf daraus
     nicht jedes Mal eine weitere Buchung samt Planer entstehen. */
  $dupe = $p->prepare("select count(*) from bookings where customer_id = ? and event_date = ? and status = 'anfrage'");
  $dupe->execute([$custId, $row['event_date']]);
  if ((int)$dupe->fetchColumn()) return;
  $guests = is_numeric($row['guests'] ?? null) ? (int)$row['guests'] : null;
  $basics = array_filter([
    'venue_name' => $row['location'] ?? null, 'venue_address' => $row['location'] ?? null,
    'guest_count' => $guests, 'occasion' => $row['event_type'] ?? null,
  ], fn($v) => $v !== null && $v !== '');
  $bookingId = uuid();
  $kind = stripos((string)($row['event_type'] ?? ''), 'technik') !== false ? 'technik' : 'dj';
  $p->prepare('insert into bookings (id, customer_id, status, kind, event_type, title, event_date,
      venue_name, venue_address, guests, event_plan, created_at, updated_at)
    values (?,?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$bookingId, $custId, 'anfrage', $kind, $row['event_type'] ?? null,
      trim((($row['event_type'] ?? '') ?: 'Anfrage') . ' ' . $row['name']), $row['event_date'],
      $row['location'] ?? null, null, $guests,   /* Adresse bleibt leer statt den Ortsnamen zu doppeln */
      json_encode(['basics' => $basics], JSON_UNESCAPED_UNICODE), now(), now()]);
}

/* Spiegelt die Entscheidung zum Angebot auf den Termin - dieselben Regeln wie
   syncVeranstaltungStatus() im Backoffice, nur serverseitig, damit Portal und
   Status-Buttons das Gleiche tun. Nur Termine in "anfrage"/"angebot" wandern weiter;
   was schon fest, abgeschlossen oder von Hand storniert ist, bleibt unangetastet. */
function syncBookingFromDoc(PDO $p, array $doc, string $newStatus): void {
  if (empty($doc['booking_id'])) return;
  $st = $p->prepare('select status from bookings where id = ?');
  $st->execute([$doc['booking_id']]);
  $cur = $st->fetchColumn();
  if ($cur === false || !in_array($cur, ['anfrage', 'angebot'], true)) return;
  $type = $doc['doc_type'] ?? '';
  $neu = null;
  if ($type === 'angebot' && $newStatus === 'angenommen') $neu = 'gebucht';
  if ($type === 'angebot' && $newStatus === 'abgelehnt' && $cur === 'angebot') $neu = 'storniert';
  if ($type === 'bestaetigung' && $newStatus === 'versendet') $neu = 'gebucht';
  if (!$neu || $neu === $cur) return;
  $p->prepare('update bookings set status=?, updated_at=? where id=?')->execute([$neu, now(), $doc['booking_id']]);
}

/* Ist Markus an dem Termin schon anderweitig unterwegs? Serverseitiges Gegenstueck zu
   konflikteAm() im Backoffice - dort nur als Warnung beim Tippen, hier als harte Pruefung,
   bevor eine Annahme im Portal einen Termin fest macht. Zaehlt: eine andere feste oder
   abgeschlossene Veranstaltung mit DJ-Einsatz (reine Technikvermietung nicht - da ist
   Markus nicht vor Ort) und jeder Kalender-Blocker, jeweils mit Zeitraum-Ueberschneidung.
   Rueckgabe: Liste kurzer Beschreibungen, leer = frei. */
function bookingConflicts(PDO $p, array $booking): array {
  $von = (string)($booking['event_date'] ?? '');
  if ($von === '') return [];
  $bis = (string)($booking['end_date'] ?? '') ?: $von;
  if ($bis < $von) $bis = $von;
  $out = [];
  try {
    $st = $p->prepare("select id, title, event_date, end_date, kind, status from bookings
      where id != ? and status in ('gebucht','abgeschlossen') and kind in ('dj','dj_technik')
        and event_date is not null and event_date != ''
        and event_date <= ? and coalesce(nullif(end_date,''), event_date) >= ?");
    $st->execute([(string)($booking['id'] ?? ''), $bis, $von]);
    foreach ($st->fetchAll() as $b)
      $out[] = ($b['title'] ?: 'Veranstaltung') . ' am ' . date('d.m.Y', strtotime((string)$b['event_date'])) . ' (' . $b['status'] . ')';
    $st = $p->prepare("select title, start_date, end_date from calendar_blocks
      where start_date <= ? and coalesce(nullif(end_date,''), start_date) >= ?");
    $st->execute([$bis, $von]);
    foreach ($st->fetchAll() as $c)
      $out[] = ($c['title'] ?: 'Kalender-Blocker') . ' am ' . date('d.m.Y', strtotime((string)$c['start_date']));
  } catch (PDOException $e) {}
  return $out;
}

/* Legt automatisch einen Technik-Check an, sobald ein Angebot mit dem Produkt
   TECH-CHECK oder TECH-CHECK-PLUS angenommen wird - Technik-Check ist ein seltenes
   Zusatzprodukt und soll deshalb nicht bei jedem Kunden als Option auftauchen,
   sondern erst entstehen, wenn er tatsaechlich beauftragt ist. Erkennung ueber den
   Positionstext (document_items speichert keine Produkt-Referenz, nur den zum
   Erstellungszeitpunkt kopierten Namen) - trifft die Automatik daneben, laesst sich
   ein Check unter "Technik-Checks" jederzeit auch von Hand anlegen. */
function maybeAutoTechCheck(PDO $p, string $documentId): void {
  $st = $p->prepare('select * from documents where id = ?');
  $st->execute([$documentId]);
  $d = $st->fetch();
  if (!$d || $d['doc_type'] !== 'angebot') return;
  $already = $p->prepare('select 1 from tech_checks where document_id = ?');
  $already->execute([$documentId]);
  if ($already->fetchColumn()) return;
  $names = $p->query("select name from products where sku in ('TECH-CHECK','TECH-CHECK-PLUS')")->fetchAll(PDO::FETCH_COLUMN);
  if (!$names) return;
  $items = $p->prepare('select description from document_items where document_id = ?');
  $items->execute([$documentId]);
  $match = false;
  foreach ($items->fetchAll(PDO::FETCH_COLUMN) as $desc) {
    foreach ($names as $n) {
      if ($n !== '' && stripos((string)$desc, (string)$n) !== false) { $match = true; break 2; }
    }
  }
  if (!$match) return;
  $p->prepare('insert into tech_checks (id, customer_id, document_id, data, created_at, updated_at) values (?,?,?,?,?,?)')
    ->execute([uuid(), $d['customer_id'], $documentId, '{}', now(), now()]);
  notifyOwner('Neuer Technik-Check angelegt', 'Ausgelöst durch Angebot ' . $d['number'] . ' – Protokoll jetzt unter „Technik-Checks" ausfüllen.');
}

/* Verschickt bei einer Technik-Check-Anfrage automatisch den Vorab-Fragebogen als
   ausgefüllten Formular-Link per Mail - kein manueller Admin-Klick nötig. Schlägt eine
   Teil-Aktion fehl (z. B. keine Firmen-Mail hinterlegt), bleibt der Kunde/die Anfrage
   trotzdem angelegt; der Aufruf erfolgt daher immer in einem eigenen try/catch. */
function autoTechCheckInvite(PDO $p, string $custId, array $row): ?string {
  if (empty($row['email'])) return null;
  $tpl = $p->prepare('select * from form_templates where name = ? limit 1');
  $tpl->execute(['Technik-Check – Vorab-Fragen']);
  $t = $tpl->fetch();
  if (!$t) return null;
  $token = bin2hex(random_bytes(24));
  $p->prepare('insert into forms (id, token, title, intro, fields, status, inquiry_id, customer_id, created_at)
      values (?,?,?,?,?,?,?,?,?)')
    ->execute([uuid(), $token, $t['name'], $t['intro'], $t['fields'], 'offen', $row['id'], $custId, now()]);
  $link = baseUrl() . '/portal.html?f=' . $token;
  $vn = trim((string)$row['name']) !== '' ? (preg_split('/\s+/', trim((string)$row['name']), 2)[0] ?? $row['name']) : 'zusammen';
  $mailed = sendMailSafe($row['email'], 'Kurzer Vorab-Fragebogen zu eurem Technik-Check',
    "Hallo $vn,\n\n" .
    "schön, dass ihr euren Technik-Check angefragt habt! Damit ich beim Termin direkt gezielt loslegen kann, " .
    "beantwortet mir vorab kurz ein paar Fragen zu eurer Anlage - dauert keine 5 Minuten:\n\n$link\n\n" .
    "Ich melde mich in Kürze bei euch, um einen Termin abzustimmen.\n\nBis bald!\nMarkus");
  /* Scheitert der Versand (Spamfilter, Tippfehler, Serverproblem), erfuhr das bisher
     niemand - der Bogen stand für immer auf "offen". Jetzt steht es in der Timeline,
     und der Link wird dem Kunden zusätzlich direkt auf der Bestätigungsseite gezeigt. */
  if (!$mailed)
    $p->prepare('insert into communications (id,customer_id,channel,direction,subject,content,occurred_at,created_at)
      values (?,?,?,?,?,?,?,?)')
      ->execute([uuid(), $custId, 'note', 'out', 'Vorab-Fragebogen konnte NICHT gemailt werden',
        "Bitte den Link manuell schicken:\n$link", now(), now()]);
  return $link;
}

/* Markus duzt auf der ganzen Seite durchgehend - die Angebots-/Rechnungs-Standardtexte
   und die Firmenfeier-Mailvorlage waren versehentlich noch im Sie-Ton. Ersetzt die
   Texte NUR, wenn sie noch exakt dem alten Sie-Standard entsprechen (eigene Anpassungen
   von Markus bleiben unangetastet). */
function fixSieToDuTexts(PDO $p): void {
  $cfg = json_decode((string)$p->query("select value from settings where key='defaults'")->fetchColumn() ?: '{}', true) ?: [];
  $changed = false;
  if (($cfg['quote_intro'] ?? null) === 'vielen Dank für Ihre Anfrage. Gerne biete ich Ihnen an:') {
    $cfg['quote_intro'] = 'vielen Dank für eure Anfrage. Gerne biete ich euch an:'; $changed = true;
  }
  if (($cfg['invoice_outro'] ?? null) === 'Bitte überweisen Sie den Betrag unter Angabe der Rechnungsnummer auf das unten genannte Konto.') {
    $cfg['invoice_outro'] = 'Bitte überweist den Betrag unter Angabe der Rechnungsnummer auf das unten genannte Konto.'; $changed = true;
  }
  if ($changed) {
    $p->prepare("update settings set value=?, updated_at=? where key='defaults'")
      ->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), now()]);
  }
  $old = ['subject' => 'Ihre Veranstaltung am {datum} – Rückmeldung von DJ Lauschgift',
    'body' => "Guten Tag {name},\n\nvielen Dank für Ihre Anfrage zu Ihrer Firmenveranstaltung am {datum}.\n\nDer Termin ist bei mir aktuell noch verfügbar. Gerne stimme ich mich kurz mit Ihnen (oder Ihrer Eventplanung) zum Ablauf ab – vom dezenten Empfang über Ton für Redebeiträge bis zum Partyprogramm. Auf dieser Basis erhalten Sie ein transparentes Angebot mit klar ausgewiesenen Posten für Dauer und Technik.\n\nFür Veranstaltungen unter der Woche oder tagsüber kalkuliere ich übrigens spürbar günstiger.\n\nWann darf ich Sie am besten anrufen?\n\nMit freundlichen Grüßen\nMarkus Jankowski – DJ Lauschgift\n\nPS: Stimmen bisheriger Kunden finden Sie hier: {bewertungen}"];
  $st = $p->prepare("select id from email_templates where subject=? and body=?");
  $st->execute([$old['subject'], $old['body']]);
  $id = $st->fetchColumn();
  if ($id) {
    $new = ['subject' => 'Deine Veranstaltung am {datum} – Rückmeldung von DJ Lauschgift',
      'body' => "Hallo {name},\n\nvielen Dank für deine Anfrage zu eurer Firmenveranstaltung am {datum}.\n\nDer Termin ist bei mir aktuell noch verfügbar. Gerne stimme ich mich kurz mit dir (oder eurer Eventplanung) zum Ablauf ab – vom dezenten Empfang über Ton für Redebeiträge bis zum Partyprogramm. Auf dieser Basis bekommst du ein transparentes Angebot mit klar ausgewiesenen Posten für Dauer und Technik.\n\nFür Veranstaltungen unter der Woche oder tagsüber kalkuliere ich übrigens spürbar günstiger.\n\nWann darf ich dich am besten anrufen?\n\nViele Grüße\nMarkus Jankowski – DJ Lauschgift\n\nPS: Stimmen bisheriger Kunden findest du hier: {bewertungen}"];
    $p->prepare("update email_templates set subject=?, body=? where id=?")->execute([$new['subject'], $new['body'], $id]);
  }
}

function friendsDdl(): string {
  return "create table if not exists friends (id text primary key, sort integer default 0,
    name text not null, category text, description text, website text,
    image_url text, image_focal text default '50% 50%',
    public integer default 1, created_at text)";
}

/* Logo-Leisten: kind "mitglied" = Netzwerke, Verbände, Bewertungsportale · kind "technik" =
   Marken, mit denen Markus arbeitet. Eine Tabelle mit kind-Spalte statt zweier gleicher.
   light_bg: dunkle Logos brauchen auf dem dunklen Seitenhintergrund eine helle Fläche,
   sonst sind sie schlicht nicht zu erkennen. */
/* Frei platzierbare Inhaltsmodule ("Baukasten"). Die festen Bereiche der Seite bleiben,
   Module haengen sich per anchor hinter einen davon - so laesst sich die Seite erweitern,
   ohne sie neu zu bauen. type bestimmt die Darstellung, media haelt Bilder/Videos als JSON. */
/* Veranstaltungsberichte: echte Feiern mit kurzem Text und Fotos. Bewusst eigene Tabelle
   statt eines Moduls je Bericht - so bleiben sie zentral pflegbar und die Seite kompakt:
   das Modul zeigt Kacheln, der ganze Text steht erst im Detailfenster. */
function eventReportsDdl(): string {
  return "create table if not exists event_reports (id text primary key, sort integer default 0,
    title text not null, meta text, teaser text, text text,
    media text default '[]', public integer default 1, created_at text)";
}

function blocksDdl(): string {
  return "create table if not exists blocks (id text primary key,
    page text default 'start', anchor text default 'ende', sort integer default 0,
    type text not null default 'kacheln',
    kicker text, title text, text text,
    media text default '[]', layout text default '3',
    public integer default 1, created_at text)";
}

function badgesDdl(): string {
  return "create table if not exists badges (id text primary key, kind text default 'mitglied',
    sort integer default 0, name text not null, subtitle text, website text,
    image_url text, light_bg integer default 0,
    public integer default 1, created_at text)";
}

function rentalContractsDdl(): string {
  return "create table if not exists rental_contracts (id text primary key,
    booking_id text not null references bookings(id) on delete cascade,
    token text unique, status text default 'offen', snapshot text,
    signed_name text, signature text, id_front text, id_back text,
    deposit_amount real,
    signed_at text, created_at text)";
}

function rentalContractDefault(): string {
  return "§ 1 Mietgegenstand und Mietzeit\nVermietet werden die im Vertrag aufgeführten Geräte für den genannten Zeitraum. Ein Miettag entspricht 24 Stunden ab Übergabe; jeder weitere Tag wird mit 50 % des Tagespreises berechnet. Übergabe und Rückgabe erfolgen, sofern nicht anders vereinbart, am Lager des Vermieters in Hemer.\n\n§ 2 Zustand, Einweisung und Nutzung\nDie Geräte werden in geprüftem, funktionsfähigem Zustand übergeben; der Mieter erhält eine kurze Einweisung. Die Nutzung erfolgt sachgemäß und nur durch den Mieter bzw. von ihm beauftragte, eingewiesene Personen.\n\n§ 3 Haftung des Mieters\nDer Mieter haftet ab Übergabe bis zur Rückgabe für Verlust, Diebstahl und Beschädigung der Mietsachen in Höhe des Wiederbeschaffungswerts bzw. der Reparaturkosten. Mängel und Schäden sind unverzüglich zu melden.\n\n§ 4 Rückgabe\nDie Rückgabe erfolgt vollständig, gereinigt und ordnungsgemäß verpackt zum vereinbarten Zeitpunkt. Bei verspäteter Rückgabe wird je angefangenem Tag der Folgetagespreis berechnet.\n\n§ 5 Kaution\nEine vereinbarte Kaution wird bei vollständiger, unbeschädigter Rückgabe erstattet.\n\n§ 6 Schlussbestimmungen\nEs gelten ergänzend die AGB des Vermieters. Es gilt deutsches Recht.";
}

/* ---------- Aktionsseiten (Kampagnen-Minipages) ----------
   Jede Zeile ist eine komplette Landingpage (hochzeit.html, abiball.html, ...),
   deren Inhalt kampagne.js aus dieser Tabelle lädt. Im Backoffice unter
   "Aktionsseiten" komplett editierbar und einzeln ein-/ausschaltbar.
   Alle Seiten starten AUSGESCHALTET - Markus schaltet sie bewusst frei. */
function campaignPagesDdl(): string {
  return "create table if not exists campaign_pages (id text primary key,
    slug text unique not null, enabled integer default 0, sort integer default 0,
    accent text, accent2 text, btn_txt text,
    page_title text, meta_desc text, badge text,
    h1_line1 text, h1_line2 text, sub text,
    kicker1 text, h2_1 text, cards text default '[]',
    kicker2 text, h2_2 text, features text default '[]', pricenote text,
    form_kicker text, form_h2 text, form_lead text, form_cfg text default '{}',
    footer_target text default 'index', created_at text, updated_at text)";
}

/* Nur einfügen, was noch fehlt (Slug-Abgleich) - Markus' Änderungen an
   bestehenden Seiten werden bei Migrationen nie überschrieben. */
function seedCampaignPages(PDO $p): void {
  $ins = $p->prepare('insert into campaign_pages (id, slug, enabled, sort, accent, accent2, btn_txt,
      page_title, meta_desc, badge, h1_line1, h1_line2, sub,
      kicker1, h2_1, cards, kicker2, h2_2, features, pricenote,
      form_kicker, form_h2, form_lead, form_cfg, footer_target, created_at, updated_at)
    values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $has = $p->prepare('select count(*) from campaign_pages where slug = ?');
  foreach (campaignPageRows() as $r) {
    $has->execute([$r['slug']]);
    if ((int)$has->fetchColumn()) continue;
    $ins->execute([uuid(), $r['slug'], 0, $r['sort'], $r['accent'], $r['accent2'], $r['btn_txt'],
      $r['page_title'], $r['meta_desc'], $r['badge'], $r['h1_line1'], $r['h1_line2'], $r['sub'],
      $r['kicker1'], $r['h2_1'], json_encode($r['cards'], JSON_UNESCAPED_UNICODE),
      $r['kicker2'], $r['h2_2'], json_encode($r['features'], JSON_UNESCAPED_UNICODE), $r['pricenote'],
      $r['form_kicker'], $r['form_h2'], $r['form_lead'],
      json_encode($r['form_cfg'], JSON_UNESCAPED_UNICODE), $r['footer_target'], now(), now()]);
  }
}

function campaignPageRows(): array {
  return [

  ['slug' => 'hochzeit', 'sort' => 10, 'accent' => '#d9a84e', 'accent2' => '#e8c078', 'btn_txt' => '#2b1d08',
   'page_title' => 'DJ für eure Hochzeit | DJ Lauschgift, Hemer',
   'meta_desc' => 'Hochzeits-DJ aus Hemer: Ich bin Markus, seit 23 Jahren DJ. Wir lernen uns vorher kennen, eure Musikwünsche zählen, und am Abend lese ich den Raum statt eine Standard-Playlist abzuspielen. Jetzt unverbindlich anfragen.',
   'badge' => 'Beliebte Samstage sind bei mir oft ein Jahr vorher weg – fragt lieber früh',
   'h1_line1' => 'Ihr heiratet.', 'h1_line2' => 'Ich sorge dafür, dass getanzt wird.',
   'sub' => 'Ich bin Markus, seit 23 Jahren DJ. Hochzeiten sind für mich immer noch das Schönste an diesem Beruf – und gleichzeitig der Tag, an dem nichts schiefgehen darf. Deshalb reden wir vorher in Ruhe, und am Abend kümmere ich mich um Musik, Ton und Licht, während ihr einfach feiert.',
   'kicker1' => 'So arbeite ich', 'h2_1' => 'Drei Dinge, auf die ihr euch verlassen könnt',
   'cards' => [
     ['icon' => 'chat', 'title' => 'Wir lernen uns vorher kennen',
      'text' => 'Bevor ihr euch festlegt, telefonieren wir oder treffen uns. Ich will wissen, wie ihr feiern wollt, welche Musik euch etwas bedeutet – und was auf gar keinen Fall laufen darf. Danach wisst ihr, ob es zwischen uns passt. Erst dann gibt es ein Angebot.'],
     ['icon' => 'music', 'title' => 'Ich lese den Raum',
      'text' => 'Auf einer Hochzeit sitzen drei Generationen an einem Tisch, und alle sollen einen schönen Abend haben. Ich ziehe kein Programm durch, sondern schaue, was gerade passiert: zum Sektempfang lockere Hintergrundmusik von Dire Straits über Motown bis House, beim Essen dezent, später die Klassiker – und wenn die Tanzfläche läuft, bleibe ich dran. Wünsche eurer Gäste nehme ich den ganzen Abend an.'],
     ['icon' => 'shield', 'title' => 'Es gibt immer einen Plan B',
      'text' => 'Die wichtige Technik habe ich doppelt dabei. Und sollte ich selbst mal ausfallen, lasse ich euch nicht hängen: Dann schlage ich euch persönlich Kollegen aus meinem Netzwerk vor, die ich kenne und denen ich eure Feier anvertrauen würde. Das steht übrigens auch so in meinen AGB, nicht nur hier.'],
   ],
   'kicker2' => 'Leistungen', 'h2_2' => 'Das ist mit dabei',
   'features' => [
     'Kennenlerngespräch, bevor ihr euch entscheidet',
     'Musikwünsche und No-Gos sammelt ihr bequem online – kein Zettelchaos',
     'Ton für die freie Trauung, Funkmikrofon für Reden und Spiele',
     'Profi-Tonanlage und dezentes Licht, passend zur Location',
     'Aufbau und Soundcheck, bevor der erste Gast da ist',
     'Ich bleibe den ganzen Abend ansprechbar – für euch und eure Gäste',
   ],
   'pricenote' => 'Was kostet das? Ehrliche Antwort: Es hängt von Dauer, Technik und Termin ab. Nach dem Kennenlerngespräch bekommt ihr einen Festpreis, in dem jeder Posten einzeln draufsteht – und der gilt dann auch.',
   'form_kicker' => 'Termin sichern', 'form_h2' => 'Wann ist euer großer Tag?',
   'form_lead' => 'Schreibt mir kurz, was ihr plant – ihr bekommt innerhalb von 24 Stunden eine ehrliche Antwort. Auch wenn der Termin bei mir schon vergeben ist: Dann sage ich euch das direkt und helfe euch trotzdem weiter.',
   'form_cfg' => ['event_types' => ['Hochzeit'], 'name_label' => 'Namen (Brautpaar) *', 'show_guests' => true,
     'location_label' => 'Location / Ort', 'location_ph' => 'z. B. Schloss, Scheune, Hemer …',
     'msg_label' => 'Erzählt kurz von eurer Feier', 'msg_ph' => 'z. B. freie Trauung vor Ort, Dinner, danach Party bis 2 Uhr, Musikrichtung …',
     'wa_text' => 'Hallo Markus, es geht um unsere Hochzeit: '],
   'footer_target' => 'index'],

  ['slug' => 'vereinsfest-technik', 'sort' => 20, 'accent' => '#3cc8b4', 'accent2' => '#5fdcc9', 'btn_txt' => '#0a2420',
   'page_title' => 'Tontechnik für Vereinsfeste | Lauschgift Veranstaltungstechnik, Hemer',
   'meta_desc' => 'Tontechnik fürs Vereinsfest aus Hemer: mieten und selbst bedienen (mit ordentlicher Einweisung) oder mit Techniker – Reden versteht man bis in die letzte Reihe. Faire Preise für Vereine, unter der Woche günstiger.',
   'badge' => 'Für Vereine, Schulen und alle, die im Saal oder Festzelt feiern',
   'h1_line1' => 'Vereinsfest geplant?', 'h1_line2' => 'Um den Ton kümmere ich mich.',
   'sub' => 'Ich bin Markus von Lauschgift Veranstaltungstechnik in Hemer. Ob Jubiläum, Sommerfest oder Karnevalssitzung: Ihr bekommt Technik, die einfach funktioniert – zum Selbstbedienen mit ordentlicher Einweisung, oder ich stehe selbst am Pult und ihr habt den Kopf frei für euer Fest.',
   'kicker1' => 'Zwei Wege', 'h2_1' => 'Mieten und selbst machen – oder machen lassen',
   'cards' => [
     ['icon' => 'gear', 'title' => 'Ihr macht es selbst',
      'text' => 'Ihr mietet eine kompakte Anlage, ich baue sie auf oder ihr holt sie ab – und dann zeige ich der Person, die den Abend macht, in Ruhe die paar Handgriffe, die sie braucht. Wenige Regler, klar beschriftet. Und falls am Festabend doch eine Frage aufkommt: Ihr habt meine Handynummer.'],
     ['icon' => 'mic', 'title' => 'Ich mache es für euch',
      'text' => 'Bei vollem Programm mit Reden, Ehrungen und Musik komme ich einfach mit. Dann kümmert sich niemand aus dem Verein um die Technik, und ich sorge dafür, dass man den Vorsitzenden auch in der letzten Reihe versteht – ohne Pfeifen, ohne Brummen. Reden, die ankommen, sind mein Spezialgebiet.'],
     ['icon' => 'money', 'title' => 'Preise, die zur Vereinskasse passen',
      'text' => 'Ich weiß, wie Vereinskassen aussehen – ich komme selbst aus der Ecke. Feste unter der Woche oder tagsüber kalkuliere ich spürbar günstiger, und im Angebot steht jeder Posten einzeln drin. Was da steht, gilt.'],
   ],
   'kicker2' => 'Im Detail', 'h2_2' => 'Womit ihr rechnen könnt',
   'features' => [
     'Tonanlage passend zur Größe von Saal oder Zelt',
     'Funkmikrofone für Reden, Ehrungen und Tombola',
     'Licht dazu, wenn abends getanzt werden soll',
     'Einweisung, bei der wirklich jeder mitkommt',
     'Auf- und Abbau nach Absprache, gern auch unter der Woche',
     'Mietvertrag digital, ohne Papierkram – alle Konditionen stehen drin',
   ],
   'pricenote' => 'Ihr bekommt ein klares Angebot, bevor ihr euch entscheidet. Und falls die fest eingebaute Anlage in eurem Vereinsheim sowieso schon länger Ärger macht: Das ist ein Thema für sich – schaut dafür mal auf meiner [Technik-Seite](technik.html) vorbei.',
   'form_kicker' => 'Jetzt anfragen', 'form_h2' => 'Was braucht euer Fest?',
   'form_lead' => 'Schreibt mir kurz, was ihr vorhabt – ihr bekommt innerhalb von 24 Stunden eine ehrliche Antwort mit Verfügbarkeit und Preisrahmen. Kostet nichts und verpflichtet zu nichts.',
   'form_cfg' => ['event_types' => ['Technik mieten', 'Techniker inkl. Technik buchen', 'Beratung / Sonstiges'],
     'type_label' => 'Was braucht ihr?', 'company_label' => 'Verein / Organisation',
     'location_label' => 'Ort / Vereinsheim', 'location_ph' => 'z. B. Vereinsheim in Hemer, Turnhalle …',
     'msg_label' => 'Was ihr vorhabt', 'msg_ph' => 'z. B. Jubiläumsfeier, ca. 80 Gäste, Reden und danach Musik …',
     'wa_text' => 'Hallo Markus, es geht um unser Vereinsfest: '],
   'footer_target' => 'technik'],

  ['slug' => 'abiball', 'sort' => 30, 'accent' => '#8b93ff', 'accent2' => '#a8afff', 'btn_txt' => '#1a1730',
   'page_title' => 'DJ & Technik für den Abiball | DJ Lauschgift, Hemer',
   'meta_desc' => 'Abiball-DJ aus Hemer: Die ganze Stufe trägt Musikwünsche online ein, ich baue daraus den Abend. Ordentlicher Ton fürs Programm, danach volle Tanzfläche – zu einem Festpreis, den die Abikasse trägt.',
   'badge' => 'Die Frühjahrs-Termine werden meist schon im Herbst vergeben',
   'h1_line1' => 'Einen Abiball feiert ihr genau einmal.', 'h1_line2' => 'Die Musik muss sitzen.',
   'sub' => 'Ich bin Markus, seit 23 Jahren DJ – ich habe Abibälle gespielt, da wart ihr noch nicht auf der Welt. Was ihr von mir bekommt: einen Abend, an dem eure Stufe und die Eltern gemeinsam auf der Tanzfläche stehen, sauberen Ton fürs Programm und einen Preis, den die Abikasse trägt.',
   'kicker1' => 'So läuft\'s', 'h2_1' => 'So wird das was mit eurem Abend',
   'cards' => [
     ['icon' => 'users', 'title' => 'Eure Stufe entscheidet mit',
      'text' => 'Ihr bekommt von mir einen Link, den ihr einfach in eure Stufengruppe werft: Jeder trägt ein, was laufen soll und was bitte nicht. Daraus baue ich den Abend. So bestimmt nicht der Geschmack von zwei Leuten aus dem Orga-Team, sondern von allen, die da feiern.'],
     ['icon' => 'mic', 'title' => 'Euer Programm läuft rund',
      'text' => 'Reden, Ehrungen, Auftritte, die Abizeitung: Ich stelle die Mikros, gebe die Einsätze und halte das Tempo hoch, damit es zwischen den Programmpunkten nicht zäh wird. Und wenn der offizielle Teil durch ist, wird durchgetanzt – auch die Lehrer, versprochen.'],
     ['icon' => 'money', 'title' => 'Ihr müsst euch fürs Geld rechtfertigen',
      'text' => 'Das Geld kommt aus der Abikasse, und ihr müsst vor der Stufe gerade dafür stehen. Deshalb gibt es von mir einen Festpreis mit allen Posten einzeln aufgeschlüsselt – den könnt ihr so ins Orga-Protokoll kopieren. Hinterher kommt nichts obendrauf.'],
   ],
   'kicker2' => 'Leistungen', 'h2_2' => 'Alles drin für euren Abend',
   'features' => [
     'Online-Umfrage für die Musikwünsche der ganzen Stufe',
     'DJ von der Begrüßung bis zum letzten Song',
     'Tonanlage und Licht passend zu Aula, Stadthalle oder Festsaal',
     'Funkmikros für Reden, Ehrungen und Auftritte',
     'Ein Ansprechpartner für Orga-Team und Elternbeirat',
     'Aufbau vor dem Einlass, Abbau nach dem letzten Gast',
   ],
   'pricenote' => 'Sagt mir Termin, Location und ungefähre Gästezahl – dann bekommt ihr ein Festpreis-Angebot, mit dem ihr in die Orga-Sitzung gehen könnt.',
   'form_kicker' => 'Termin sichern', 'form_h2' => 'Wann ist euer Abiball?',
   'form_lead' => 'Kurz eintragen – ihr bekommt innerhalb von 24 Stunden eine ehrliche Antwort mit Verfügbarkeit und Preisrahmen. Kostet nichts, verpflichtet zu nichts.',
   'form_cfg' => ['event_types' => ['Abiball'], 'name_label' => 'Name (Ansprechpartner) *',
     'company_label' => 'Schule / Jahrgangsstufe', 'show_guests' => true, 'guests_ph' => 'z. B. 150',
     'location_label' => 'Location', 'location_ph' => 'z. B. Aula, Stadthalle, Festsaal …',
     'msg_label' => 'Was ihr vorhabt', 'msg_ph' => 'z. B. Einlass, Programm mit Reden, danach Party bis 1 Uhr, Musikrichtung …',
     'wa_text' => 'Hallo Markus, es geht um unseren Abiball: '],
   'footer_target' => 'index'],

  ['slug' => 'firmensommerfest', 'sort' => 40, 'accent' => '#e0c93a', 'accent2' => '#ecdb6c', 'btn_txt' => '#2b2506',
   'page_title' => 'DJ & Technik fürs Firmen-Sommerfest | DJ Lauschgift, Hemer',
   'meta_desc' => 'DJ und Technik fürs Firmen-Sommerfest: Ich bin Markus aus Hemer – DJ und Veranstaltungstechniker. Musik vom Grillnachmittag bis zur Party am Abend, Strom und Wetter vorher geklärt. Termine unter der Woche kalkuliere ich günstiger.',
   'badge' => 'Termine unter der Woche kalkuliere ich spürbar günstiger als den Samstag',
   'h1_line1' => 'Sommerfest draußen?', 'h1_line2' => 'Ich bringe den Sound mit.',
   'sub' => 'Ich bin Markus – DJ und Veranstaltungstechniker aus Hemer. Fürs Sommerfest heißt das: Die Technik verträgt auch mal einen Schauer, der Strom ist vorher geklärt, nachmittags läuft entspannte Musik zum Grillen, und abends wird gefeiert. Ob Betriebsgelände, Garten oder gemietete Wiese.',
   'kicker1' => 'Draußen feiern', 'h2_1' => 'Worauf es bei einem Fest im Freien ankommt',
   'cards' => [
     ['icon' => 'cloud', 'title' => 'Draußen ist nicht drinnen',
      'text' => 'Auf der Wiese gibt es keine Steckdose alle fünf Meter, und das Wetter fragt nicht nach eurem Termin. Deshalb kläre ich Stromversorgung und Stellplatz vorher mit euch – am Telefon oder direkt vor Ort. Dann steht die Anlage sicher und trocken, auch wenn ein Schauer durchzieht.'],
     ['icon' => 'music', 'title' => 'Der Nachmittag gehört dem Grill',
      'text' => 'Um 15 Uhr will niemand Partybeschallung. Ich fange locker an – Motown, Funk, auch mal Dire Straits zum Bier –, die Ansprache der Geschäftsführung versteht jeder bis zum letzten Stehtisch, und wenn es dämmert, ziehe ich langsam Richtung House und Party an, bis von ganz allein getanzt wird. Das funktioniert besser, als um 20 Uhr das Licht auszuknipsen.'],
     ['icon' => 'home', 'title' => 'Die Nachbarn feiern nicht mit',
      'text' => 'Open Air hört eben nicht am Zaun auf. Lautstärke und Ruhezeiten sprechen wir vorher ab, und ich richte die Anlage so aus, dass die Stimmung bei euch bleibt statt beim Nachbarn im Schlafzimmer. So gibt es am Montag keine unangenehmen Anrufe.'],
   ],
   'kicker2' => 'Leistungen', 'h2_2' => 'Damit könnt ihr planen',
   'features' => [
     'Wetterfeste Ton- und Lichttechnik fürs Gelände',
     'Musik vom lockeren Grillnachmittag bis zur Party am Abend',
     'Funkmikrofon für Ansprache und Ehrungen',
     'Strom- und Stellplatz-Planung vorab',
     'Absprache zu Lautstärke und Ruhezeiten',
     'Auf- und Abbau passend zu eurem Ablauf',
   ],
   'pricenote' => 'Sommerfeste liegen oft auf einem Donnerstag oder Freitagnachmittag – genau die Termine, die ich günstiger anbieten kann als einen Samstagabend in der Hochsaison. Nennt mir euren Wunschtermin, ich rechne es ehrlich durch.',
   'form_kicker' => 'Termin sichern', 'form_h2' => 'Wann feiert ihr?',
   'form_lead' => 'Schreibt mir kurz, was ihr plant – ihr bekommt innerhalb von 24 Stunden eine ehrliche Antwort mit Verfügbarkeit und Preisrahmen. Unverbindlich, versteht sich.',
   'form_cfg' => ['event_types' => ['Firmenfeier'], 'company_label' => 'Firma', 'show_guests' => true, 'guests_ph' => 'z. B. 100',
     'location_label' => 'Ort / Location', 'location_ph' => 'z. B. Betriebsgelände, Garten, Vereinsplatz …',
     'msg_label' => 'Was ihr vorhabt', 'msg_ph' => 'z. B. Grillen ab 15 Uhr, Ansprache der Geschäftsführung, danach Party im Freien …',
     'wa_text' => 'Hallo Markus, es geht um unser Firmen-Sommerfest: '],
   'footer_target' => 'index'],

  ['slug' => 'betriebsversammlung', 'sort' => 50, 'accent' => '#7fb4e6', 'accent2' => '#a3cbf0', 'btn_txt' => '#0d1a26',
   'page_title' => 'Ton für Betriebsversammlungen | Lauschgift Veranstaltungstechnik, Hemer',
   'meta_desc' => 'Beschallung für Betriebs- und Mitarbeiterversammlungen: verständliche Sprache statt Hallensound, Saalmikrofon für Wortmeldungen, Auf- und Abbau im Takt eures Betriebs. Aus Hemer, unter der Woche mein Alltag.',
   'badge' => 'Unter der Woche, tagsüber, zwischen zwei Schichten – genau mein Terrain',
   'h1_line1' => 'Ihr habt eurer Belegschaft etwas zu sagen.', 'h1_line2' => 'Dann muss es auch ankommen.',
   'sub' => 'Ich bin Markus von Lauschgift Veranstaltungstechnik in Hemer. Betriebsversammlungen finden in Werkhallen, Kantinen und Lagern statt – Räume, die nie für Sprache gebaut wurden. Ich sorge dafür, dass eure Botschaft trotzdem bis in die letzte Reihe kommt. Und der Aufbau richtet sich nach eurem Betrieb, nicht umgekehrt.',
   'kicker1' => 'Worauf es ankommt', 'h2_1' => 'Eine Versammlung ist kein Konzert',
   'cards' => [
     ['icon' => 'mic', 'title' => 'Verstehen statt beschallen',
      'text' => 'Bei einer Betriebsversammlung geht es oft um Dinge, die die Leute persönlich betreffen: Zahlen, Veränderungen, Zukunft. Da darf nichts an einer scheppernden Anlage hängen bleiben. Ich stelle den Ton ganz auf Sprache ein – klar und unaufgeregt, ohne Hall-Soße.'],
     ['icon' => 'users', 'title' => 'Auch die leisen Fragen zählen',
      'text' => 'Die wichtigste Wortmeldung kommt selten vom Podium, sondern aus Reihe zwölf. Mit einem zweiten Funkmikro, das durch die Reihen geht, wird aus Zuhörern ein Gespräch – und niemand muss gegen die Halle anbrüllen, um eine Frage zu stellen.'],
     ['icon' => 'clock', 'title' => 'Rein, raus, fertig',
      'text' => 'Eure Halle ist zum Arbeiten da. Ich baue auf, bevor die Versammlung beginnt, und bin wieder draußen, bevor die nächste Schicht loslegt. Wenn es knapp ist, steht die Anlage in einer Stunde.'],
   ],
   'kicker2' => 'Leistungen', 'h2_2' => 'Damit könnt ihr rechnen',
   'features' => [
     'Beschallung passend zu Halle, Kantine oder Lager',
     'Funkmikrofon fürs Podium, zweites Mikro für Wortmeldungen',
     'Headset oder Rednerpult-Mikro nach Wunsch',
     'Laptop-Anschluss für Präsentationston',
     'Auf- und Abbau im Zeitfenster eures Betriebs',
     'Auf Wunsch bleibe ich da und fahre den Ton',
   ],
   'pricenote' => 'Termine unter der Woche und tagsüber sind mein Alltag, kein Zuschlag-Fall – genau solche Einsätze sind bei mir günstiger als jede Samstagnacht. Ihr bekommt vorher einen Festpreis.',
   'form_kicker' => 'Jetzt anfragen', 'form_h2' => 'Wann ist eure Versammlung?',
   'form_lead' => 'Schreibt mir Termin, Ort und ungefähre Teilnehmerzahl – ihr bekommt innerhalb von 24 Stunden eine klare Antwort mit Festpreis. Auch kurzfristig lohnt sich das Fragen.',
   'form_cfg' => ['event_types' => ['Betriebsversammlung'], 'company_label' => 'Firma',
     'show_guests' => true, 'guests_label' => 'Teilnehmer (ca.)', 'guests_ph' => 'z. B. 120',
     'location_label' => 'Ort / Halle', 'location_ph' => 'z. B. Werkhalle in Hemer, Kantine …',
     'msg_label' => 'Worum geht es?', 'msg_ph' => 'z. B. Versammlung 90 Minuten, zwei Redner, Fragen aus der Belegschaft, Beamerton …',
     'wa_text' => 'Hallo Markus, es geht um unsere Betriebsversammlung: '],
   'footer_target' => 'technik'],

  ['slug' => 'seminartechnik', 'sort' => 60, 'accent' => '#7ecb8f', 'accent2' => '#a1dbae', 'btn_txt' => '#0c2012',
   'page_title' => 'Tontechnik für Seminare & Fortbildungen | Lauschgift Veranstaltungstechnik',
   'meta_desc' => 'Ton für Seminare, Fortbildungen und Tagungen: Headset für die Stimme, Saalmikro für Fragen, Laptop-Ton fürs Video – dezent aufgebaut und zuverlässig. Aus Hemer, stundenweise betreut oder mit Einweisung.',
   'badge' => 'Für Trainer, Personaler und alle, die einen Raum voller Menschen erreichen wollen',
   'h1_line1' => 'Sechs Stunden reden ist anstrengend genug.', 'h1_line2' => 'Gegen den Raum anreden muss keiner.',
   'sub' => 'Ich bin Markus – Veranstaltungstechniker aus Hemer. Seminare und Fortbildungen leben von der Stimme der Person da vorne. Ich sorge mit dezenter Technik dafür, dass diese Stimme den ganzen Tag trägt: im Tagungsraum vom Hotel, im Schulungsraum der Firma oder in der angemieteten Halle.',
   'kicker1' => 'Worauf es ankommt', 'h2_1' => 'Kleine Technik, großer Unterschied',
   'cards' => [
     ['icon' => 'mic', 'title' => 'Die Stimme hält bis zum Schluss',
      'text' => 'Wer einen ganzen Tag ohne Mikrofon gegen einen Raum anredet, ist um 16 Uhr heiser – und die Teilnehmer sind es leid, sich anzustrengen. Ein unauffälliges Headset nimmt der Stimme die Arbeit ab. Und keine Sorge vor der Technik: Nach fünf Minuten vergisst man, dass es da ist.'],
     ['icon' => 'chat', 'title' => 'Fragen, die alle hören',
      'text' => 'Nichts ist zäher als eine Antwort auf eine Frage, die hinten keiner verstanden hat. Ein zweites Funkmikro für den Saal löst das – und hält die Gruppe auch am Nachmittag noch wach, weil jeder Teil des Gesprächs bleibt.'],
     ['icon' => 'gear', 'title' => 'Technik, die einfach läuft',
      'text' => 'Laptop-Ton fürs Video, Musik in den Pausen, alles vor Beginn getestet. Ihr könnt mich stundenweise dazubuchen, dann sitze ich hinten und regle – oder ihr übernehmt die Anlage nach einer kurzen Einweisung selbst. Je nachdem, wie viel Ruhe ihr haben wollt.'],
   ],
   'kicker2' => 'Leistungen', 'h2_2' => 'Damit könnt ihr planen',
   'features' => [
     'Headset oder Funkmikrofon für Referentin oder Referent',
     'Zweites Mikro für Fragen aus dem Raum',
     'Beschallung passend zur Raumgröße – dezent aufgebaut',
     'Laptop-Anschluss für Video- und Präsentationston',
     'Pausenmusik, wenn gewünscht',
     'Stundenweise betreut oder Anlage mit Einweisung',
   ],
   'pricenote' => 'Seminare liegen naturgemäß unter der Woche und tagsüber – genau die Termine, die ich am günstigsten anbieten kann. Sagt mir Raum, Dauer und Teilnehmerzahl, ihr bekommt einen Festpreis.',
   'form_kicker' => 'Jetzt anfragen', 'form_h2' => 'Wann ist euer Seminar?',
   'form_lead' => 'Kurz eintragen – ihr bekommt innerhalb von 24 Stunden eine klare Antwort. Auch für Seminarreihen mit mehreren Terminen lohnt sich das Fragen, das kalkuliere ich als Paket.',
   'form_cfg' => ['event_types' => ['Seminar / Fortbildung'], 'company_label' => 'Firma / Institut',
     'show_guests' => true, 'guests_label' => 'Teilnehmer (ca.)', 'guests_ph' => 'z. B. 40',
     'location_label' => 'Ort / Raum', 'location_ph' => 'z. B. Tagungsraum im Hotel, Schulungsraum …',
     'msg_label' => 'Worum geht es?', 'msg_ph' => 'z. B. Ganztages-Fortbildung, eine Trainerin, Videoeinspieler, Fragen aus dem Raum …',
     'wa_text' => 'Hallo Markus, es geht um Tontechnik für unser Seminar: '],
   'footer_target' => 'technik'],

  ['slug' => 'messe', 'sort' => 70, 'accent' => '#f0955b', 'accent2' => '#f5b183', 'btn_txt' => '#241105',
   'page_title' => 'Technik für euren Messestand | Lauschgift Veranstaltungstechnik, Hemer',
   'meta_desc' => 'Licht und Ton für Messestände: auffallen, ohne zu nerven – Standbeleuchtung, gerichtete Beschallung für Präsentationen, Auf- und Abbau nach Messeplan. Aus Hemer, Messetage sind Werktage.',
   'badge' => 'Messetage sind Werktage – genau die Termine, die ich mag',
   'h1_line1' => 'Euer Stand hat drei Sekunden,', 'h1_line2' => 'bis die Leute weitergehen.',
   'sub' => 'Ich bin Markus von Lauschgift Veranstaltungstechnik. Auf einer Messe entscheidet der erste Eindruck, ob jemand stehen bleibt oder weiterläuft. Ich baue Licht und Ton für euren Stand: hell, wo euer Produkt steht, verständlich, wo gesprochen wird – und alles wieder abgebaut, bevor die Halle schließt.',
   'kicker1' => 'Worauf es ankommt', 'h2_1' => 'Auffallen, ohne zu nerven',
   'cards' => [
     ['icon' => 'light', 'title' => 'Licht zieht Blicke',
      'text' => 'In einer Messehalle ist alles gleich hell und gleich grau. Gutes Licht auf eurem Produkt und eurer Rückwand ist der günstigste Weg aufzufallen – deutlich günstiger als der größere Stand, den ihr sonst bräuchtet, um genauso gesehen zu werden.'],
     ['icon' => 'mic', 'title' => 'Reden im Messelärm',
      'text' => 'Wenn ihr am Stand präsentiert, kämpft ihr gegen tausend Gespräche und die Halle nebenan. Eine kleine, gerichtete Beschallung sorgt dafür, dass man euch an eurem Stand versteht – ohne dass sich der Nachbarstand beim Veranstalter beschwert.'],
     ['icon' => 'calendar', 'title' => 'Auf- und Abbau nach Messeplan',
      'text' => 'Aufbau am Vortag im Zeitfenster des Veranstalters, Abbau nach Messeschluss, Nachweise für die Hallenregie, wenn nötig. Ihr kümmert euch um eure Kunden und euer Standpersonal – nicht um Kabel.'],
   ],
   'kicker2' => 'Leistungen', 'h2_2' => 'Damit könnt ihr planen',
   'features' => [
     'Standbeleuchtung für Produkt, Rückwand und Theke',
     'Dezente, gerichtete Beschallung für den Stand',
     'Funkmikrofon für Präsentationen und Vorführungen',
     'Ton für Monitore und Produktvideos',
     'Auf- und Abbau nach den Zeitfenstern des Veranstalters',
     'Betreut während der Messe oder mit Einweisung fürs Standpersonal',
   ],
   'pricenote' => 'Messen laufen werktags – für mich die besten Termine im Kalender, und das merkt ihr am Preis. Sagt mir Messe, Standgröße und was ihr vorhabt, ihr bekommt einen Festpreis.',
   'form_kicker' => 'Jetzt anfragen', 'form_h2' => 'Wann ist eure Messe?',
   'form_lead' => 'Schreibt mir Messe, Termin und Standgröße – ihr bekommt innerhalb von 24 Stunden eine klare Antwort mit Preisrahmen.',
   'form_cfg' => ['event_types' => ['Messe / Ausstellung'], 'company_label' => 'Firma',
     'location_label' => 'Messe / Halle', 'location_ph' => 'z. B. Messe Dortmund, Halle 4, Stand 12 m² …',
     'msg_label' => 'Was ihr vorhabt', 'msg_ph' => 'z. B. drei Messetage, Produktvideo mit Ton, zwei Kurzpräsentationen täglich …',
     'wa_text' => 'Hallo Markus, es geht um Technik für unseren Messestand: '],
   'footer_target' => 'technik'],

  ['slug' => 'objektbeleuchtung', 'sort' => 80, 'accent' => '#ffc247', 'accent2' => '#ffd47e', 'btn_txt' => '#241a05',
   'page_title' => 'Objekt- & Fassadenbeleuchtung | Lauschgift Veranstaltungstechnik, Hemer',
   'meta_desc' => 'Fassaden, Gärten und Firmengelände stimmungsvoll beleuchten: fürs Jubiläum, den Tag der offenen Tür oder die dunkle Jahreszeit. Outdoor-Scheinwerfer, sichere Kabelwege, steckerfertig – aus Hemer.',
   'badge' => 'Planbare Aufträge unter der Woche – vom Aufmaß bis zum Abbau',
   'h1_line1' => 'Nach Sonnenuntergang', 'h1_line2' => 'wird euer Gebäude zur Bühne.',
   'sub' => 'Ich bin Markus – Lichttechnik gehört bei mir zu jedem Auftrag, und Objektbeleuchtung ist die schönste Form davon: Fassaden, Gärten, Höfe und Eingänge so anstrahlen, dass Gäste schon beim Ankommen merken, dass heute etwas Besonderes ist. Fürs Firmenjubiläum, den Tag der offenen Tür oder die dunkle Jahreszeit.',
   'kicker1' => 'Worauf es ankommt', 'h2_1' => 'Licht, das man nicht sieht – nur seine Wirkung',
   'cards' => [
     ['icon' => 'home', 'title' => 'Die Fassade kann mehr',
      'text' => 'Tagsüber ist euer Gebäude Zweckbau, abends kann es Eindruck machen: farbige Akzente auf der Fassade, Bäume von unten angestrahlt, der Weg zum Eingang als Linie aus Licht. Das wirkt hochwertig, lange bevor der erste Gast drinnen ist – und auf jedem Foto vom Abend.'],
     ['icon' => 'shield', 'title' => 'Sauber und sicher aufgebaut',
      'text' => 'Draußen heißt: Feuchtigkeit, Publikum, Stolperfallen. Ich arbeite mit Outdoor-tauglichen Scheinwerfern, sichere Kabelwege ordentlich und schließe steckerfertig an. Für Arbeiten am Hausstromnetz selbst hole ich einen Elektro-Partnerbetrieb dazu – das gehört sich so, und das sage ich euch auch vorher.'],
     ['icon' => 'calendar', 'title' => 'Einmalig oder jedes Jahr wieder',
      'text' => 'Vieles davon wiederholt sich: die Beleuchtung zur Weihnachtszeit, das jährliche Sommerfest, der Tag der offenen Tür. Einmal geplant, wird der Aufbau jedes Jahr schneller – und ab dem zweiten Jahr auch günstiger, weil die Planung dann steht.'],
   ],
   'kicker2' => 'Leistungen', 'h2_2' => 'Damit könnt ihr planen',
   'features' => [
     'Fassaden- und Gartenbeleuchtung mit Outdoor-Scheinwerfern',
     'Wege- und Eingangsbeleuchtung für Gäste',
     'Farbkonzept passend zu Anlass oder Firmenfarben',
     'Sichere Kabelwege, steckerfertiger Anschluss',
     'Auf- und Abbau nach Plan, gern unter der Woche',
     'Wiederkehrende Aufbauten ab dem zweiten Jahr günstiger',
   ],
   'pricenote' => 'Ich schaue mir das Objekt vorher an – bei Dämmerung, wenn es sein muss – und ihr bekommt einen Festpreis. Dauerhafte Festinstallationen sind auch möglich, das ist ein Thema für die [Technik-Seite](technik.html).',
   'form_kicker' => 'Jetzt anfragen', 'form_h2' => 'Was soll leuchten?',
   'form_lead' => 'Beschreibt mir kurz Objekt und Anlass – ihr bekommt innerhalb von 24 Stunden eine ehrliche Einschätzung, was sich lohnt und was es kostet.',
   'form_cfg' => ['event_types' => ['Objektbeleuchtung'], 'company_label' => 'Firma / Verein',
     'location_label' => 'Objekt / Adresse', 'location_ph' => 'z. B. Firmengebäude in Hemer, Vereinsheim mit Garten …',
     'msg_label' => 'Was ihr vorhabt', 'msg_ph' => 'z. B. Firmenjubiläum im Oktober, Fassade und Einfahrt beleuchten, Firmenfarbe Blau …',
     'wa_text' => 'Hallo Markus, es geht um Objektbeleuchtung: '],
   'footer_target' => 'technik'],

  ['slug' => 'instore-dj', 'sort' => 90, 'accent' => '#ff8bc2', 'accent2' => '#ffaed4', 'btn_txt' => '#260d1b',
   'page_title' => 'Instore-DJ für Store & Kaufhaus | DJ Lauschgift, Hemer',
   'meta_desc' => 'DJ im Modegeschäft, Store oder Kaufhaus: Sale-Wochenende, Opening, verkaufsoffener Sonntag. Ich spiele für die Kundschaft, die gerade da ist – Deep House, Disco, French House. Kompakt aufgebaut, leise genug für die Kasse.',
   'badge' => 'Tagsüber, wenn eure Kunden da sind – Sale, Opening, verkaufsoffener Sonntag',
   'h1_line1' => 'Musik verkauft mit.', 'h1_line2' => 'Playlists nicht.',
   'sub' => 'Ich bin Markus, DJ seit 23 Jahren – und ich habe oft genug in Modegeschäften und Kaufhäusern aufgelegt, um zu wissen: Ein Laden mit echtem DJ fühlt sich anders an. Die Leute bleiben länger, das Team ist besser drauf, und aus einem Einkauf wird ein Erlebnis. Musikalisch: Deep House, Disco, French House – Sound, der gut klingt, ohne dass jemand schreien muss.',
   'kicker1' => 'Warum ein echter DJ', 'h2_1' => 'Der Unterschied zur Playlist',
   'cards' => [
     ['icon' => 'music', 'title' => 'Ich spiele für die Leute, die gerade da sind',
      'text' => 'Eine Playlist läuft stur weiter, egal ob gerade Familien mit Kinderwagen durchs Geschäft gehen oder die After-Work-Kundschaft reinkommt. Ich sehe, wer da ist, und passe Musik und Energie an – am Vormittag zurückhaltend, zum Feierabend hin treibender. Das spürt man, auch wenn es keiner benennen kann.'],
     ['icon' => 'zap', 'title' => 'Ein Grund zu kommen und zu bleiben',
      'text' => 'Sale-Wochenende, Store-Opening, verkaufsoffener Sonntag, neue Kollektion: Ein DJ macht aus einer Aktion einen Anlass. Und er ist gleich der Content für eure Kanäle mit – die Story mit DJ im Store teilt sich von selbst, da müsst ihr nichts inszenieren.'],
     ['icon' => 'gear', 'title' => 'Kompakt und leise genug für die Kasse',
      'text' => 'Mein Setup fürs Geschäft ist klein, sieht ordentlich aus und ist so eingepegelt, dass sich Kundinnen bei der Beratung und an der Kasse normal unterhalten können. Aufgebaut wird vor Ladenöffnung, versteht sich. Und was an Nebenkosten wie GEMA dazukommt, sage ich euch vorher ehrlich.'],
   ],
   'kicker2' => 'Leistungen', 'h2_2' => 'Damit könnt ihr planen',
   'features' => [
     'DJ-Sets von zwei Stunden bis zum ganzen Verkaufstag',
     'Kompaktes, sauberes Setup passend zum Ladenbild',
     'Musik abgestimmt auf Sortiment und Zielgruppe',
     'Pegel, der Beratung und Kasse nicht stört',
     'Aufbau vor Ladenöffnung, Abbau nach Ladenschluss',
     'Ehrliche Ansage vorab zu Nebenkosten wie GEMA',
   ],
   'pricenote' => 'Store-Termine sind Tages- und Wochenendgeschäft zu Ladenöffnungszeiten – dafür kalkuliere ich spürbar freundlicher als für eine Samstagnacht. Sagt mir Anlass und Öffnungszeiten, ihr bekommt einen Festpreis.',
   'form_kicker' => 'Jetzt anfragen', 'form_h2' => 'Wann ist eure Aktion?',
   'form_lead' => 'Schreibt mir kurz, was ihr plant – ihr bekommt innerhalb von 24 Stunden eine klare Antwort. Auch für wiederkehrende Termine, etwa jeden ersten Samstag, lohnt sich das Fragen.',
   'form_cfg' => ['event_types' => ['Instore-DJ / Store-Event'], 'company_label' => 'Geschäft / Marke',
     'location_label' => 'Store / Adresse', 'location_ph' => 'z. B. Modegeschäft in der Innenstadt von Iserlohn …',
     'msg_label' => 'Was ihr plant', 'msg_ph' => 'z. B. Sale-Samstag von 11 bis 18 Uhr, junge Zielgruppe, Ecke im Eingangsbereich frei …',
     'wa_text' => 'Hallo Markus, es geht um einen DJ für unser Geschäft: '],
   'footer_target' => 'index'],

  ['slug' => 'produktpraesentation', 'sort' => 100, 'accent' => '#59c3e8', 'accent2' => '#84d3ef', 'btn_txt' => '#07202b',
   'page_title' => 'DJ & Technik für Produktpräsentationen | DJ Lauschgift, Hemer',
   'meta_desc' => 'Launch-Events, Kundenabende, Showroom-Termine: Musik für den Empfang, Ton für die Präsentation, Licht auf dem Produkt – ein Ansprechpartner statt drei Gewerke. DJ und Veranstaltungstechniker aus Hemer.',
   'badge' => 'Launch-Events, Kundenabende, Showrooms – gern auch dienstags um 11',
   'h1_line1' => 'An eurem Produkt hängen Monate Arbeit.', 'h1_line2' => 'Der Auftritt entscheidet in Minuten.',
   'sub' => 'Ich bin Markus – DJ und Veranstaltungstechniker in einer Person. Bei Produktpräsentationen, Launch-Events und Kundenabenden heißt das: Musik, die den Empfang trägt, Ton, den die Präsentation verdient, und Licht, das euer Produkt zum Mittelpunkt macht. Ein Ansprechpartner statt drei Gewerke, die sich absprechen müssen.',
   'kicker1' => 'Worauf es ankommt', 'h2_1' => 'Drei Momente, die den Abend tragen',
   'cards' => [
     ['icon' => 'zap', 'title' => 'Der Moment der Enthüllung',
      'text' => 'Ob neues Modell im Autohaus, neue Kollektion im Showroom oder neue Maschine in der Halle: Der Moment, in dem das Tuch fällt, braucht Licht und Musik auf den Punkt. Den proben wir vorher durch – auf die Sekunde, mit festem Zeichen. Dann sitzt er auch, wenn alle Kameras draufhalten.'],
     ['icon' => 'music', 'title' => 'Empfang mit Haltung',
      'text' => 'Vor und nach dem offiziellen Teil lege ich auf: dezent und erwachsen, je nach Publikum von Motown und Funk bis zu ruhigem House. Musik, die Gespräche möglich macht und trotzdem klarstellt, dass hier gerade etwas stattfindet – kein Fahrstuhl-Geplätscher, keine Charts-Beschallung.'],
     ['icon' => 'mic', 'title' => 'Die Präsentation kommt an',
      'text' => 'Headset oder Funkmikro für die, die sprechen, Laptop-Ton fürs Video, Pegel im Griff. Eure Geschäftsführung soll souverän dastehen und sich auf ihre Worte konzentrieren können – für alles andere bin ich da, und zwar unauffällig.'],
   ],
   'kicker2' => 'Leistungen', 'h2_2' => 'Damit könnt ihr planen',
   'features' => [
     'DJ für Empfang, Übergänge und Ausklang',
     'Ton für Reden, Video und Einspieler',
     'Licht mit eurem Produkt im Mittelpunkt, gern in Firmenfarben',
     'Ablauf-Abstimmung mit Marketing oder Agentur',
     'Diskretes Auftreten vor euren Kunden',
     'Ein fester Ansprechpartner von Planung bis Abbau',
   ],
   'pricenote' => 'Solche Termine liegen fast immer unter der Woche – für mich die besten Termine im Kalender, und das rechnet sich für euch. Nennt mir Anlass und Rahmen, ihr bekommt einen Festpreis.',
   'form_kicker' => 'Jetzt anfragen', 'form_h2' => 'Wann ist euer Termin?',
   'form_lead' => 'Schreibt mir kurz Anlass, Ort und ungefähre Gästezahl – ihr bekommt innerhalb von 24 Stunden eine klare Antwort mit Preisrahmen.',
   'form_cfg' => ['event_types' => ['Produktpräsentation / Firmenevent'], 'company_label' => 'Firma',
     'show_guests' => true, 'guests_ph' => 'z. B. 60',
     'location_label' => 'Ort / Location', 'location_ph' => 'z. B. Autohaus, Showroom, Firmengebäude …',
     'msg_label' => 'Was ihr plant', 'msg_ph' => 'z. B. Kundenabend mit Enthüllung um 19 Uhr, danach Get-together mit Musik …',
     'wa_text' => 'Hallo Markus, es geht um unsere Produktpräsentation: '],
   'footer_target' => 'index'],

  ['slug' => 'tagesparty', 'sort' => 110, 'accent' => '#ff9e7a', 'accent2' => '#ffbca0', 'btn_txt' => '#26120a',
   'page_title' => 'Tagesparty mit House-Sounds | DJ Lauschgift, Hemer',
   'meta_desc' => 'Feiern, wenn die Sonne noch scheint: Gartenparty, Sundowner, runder Geburtstag am Nachmittag – mit Deep House, Disco und French House. Um Mitternacht zufrieden im Bett. DJ aus Hemer, Tagestermine günstiger.',
   'badge' => 'Feiern, wenn die Sonne noch scheint – und um Mitternacht zufrieden im Bett',
   'h1_line1' => 'Wer sagt eigentlich,', 'h1_line2' => 'dass Partys erst um 22 Uhr anfangen?',
   'sub' => 'Ich bin Markus, DJ seit 23 Jahren – und ehrlich: Einige der besten Feiern, die ich gespielt habe, liefen nachmittags. Gartenparty ab 14 Uhr, Sundowner auf der Terrasse, runder Geburtstag als langer Nachmittag statt kurzer Nacht. Musikalisch mein Lieblingsrevier: Deep House, Disco, French House – Sound zum Feiern und Unterhalten gleichzeitig.',
   'kicker1' => 'Die Idee', 'h2_1' => 'Der Nachmittag ist die neue Nacht',
   'cards' => [
     ['icon' => 'sun', 'title' => 'Alle können kommen – und alle sind fit',
      'text' => 'Tagsüber feiern heißt: Die Freunde mit kleinen Kindern sind dabei, die Großeltern auch, und niemand fährt um drei Uhr nachts übermüdet nach Hause. Wenn um Mitternacht Schluss ist, haben trotzdem alle zehn Stunden gefeiert – nur eben die schönen zehn Stunden.'],
     ['icon' => 'music', 'title' => 'House statt Halligalli',
      'text' => 'Keine Sorge, das wird keine Technoparty: Deep House, Disco und French House sind Musik, die sofort gute Laune macht und trotzdem Gespräche zulässt. Genau richtig für draußen, für ein Glas in der Sonne – und für eine Tanzfläche, die gegen 17 Uhr ganz von allein entsteht.'],
     ['icon' => 'users', 'title' => 'Es braucht keinen großen Anlass',
      'text' => 'Runder Geburtstag, Einweihung, Jubiläum, bestandene Prüfung oder einfach ein guter Sommer: Ein Nachmittag, gute Musik und Leute, die man mag, reichen völlig. Ich bringe Anlage, Sound und ein bisschen Licht für die Stunde nach Sonnenuntergang mit.'],
   ],
   'kicker2' => 'Leistungen', 'h2_2' => 'Damit könnt ihr planen',
   'features' => [
     'DJ-Set am Nachmittag und frühen Abend',
     'Kompakte Anlage für Garten, Terrasse, Hof oder Halle',
     'Lautstärke, die Nachbarn und Gespräche verträgt',
     'Musikwünsche vorab, wenn ihr mögt',
     'Licht für die Stunde, in der die Sonne weg ist',
     'Tagestermine deutlich günstiger als die Samstagnacht',
   ],
   'pricenote' => 'Tagestermine kann ich deutlich günstiger anbieten als eine Samstagnacht – diese Stunden gehören sonst niemandem. Wer also immer dachte, ein richtiger DJ sei zu teuer für eine private Feier: Nachmittags stimmt das oft nicht mehr.',
   'form_kicker' => 'Termin sichern', 'form_h2' => 'Wann wird gefeiert?',
   'form_lead' => 'Schreibt mir kurz, was ihr euch vorstellt – ihr bekommt innerhalb von 24 Stunden eine ehrliche Antwort mit Preisrahmen. Auch spontane Termine klappen tagsüber öfter, als man denkt.',
   'form_cfg' => ['event_types' => ['Tagesparty'], 'show_guests' => true, 'guests_ph' => 'z. B. 40',
     'location_label' => 'Ort / Location', 'location_ph' => 'z. B. Garten in Hemer, Terrasse, gemietete Scheune …',
     'msg_label' => 'Was ihr euch vorstellt', 'msg_ph' => 'z. B. 40. Geburtstag, ab 14 Uhr im Garten, entspannt mit Tanzen zum Abend …',
     'wa_text' => 'Hallo Markus, es geht um eine Tagesparty: '],
   'footer_target' => 'index'],

  ['slug' => 'technik-check', 'sort' => 120, 'accent' => '#3cc8b4', 'accent2' => '#5fdcc9', 'btn_txt' => '#0a2420',
   'page_title' => 'Technik-Check für eure Tonanlage | Lauschgift Veranstaltungstechnik',
   'meta_desc' => 'Die Anlage im Vereinsheim brummt, pfeift oder keiner traut sich ran? Ehrlicher Technik-Check mit schriftlichem Bericht: 149 Euro inkl. MwSt., wird bei Folgeauftrag verrechnet. Keine Verkaufsshow – aus Hemer.',
   'badge' => 'Für Vereinsheime, Gemeindehäuser, Schulen, Kneipen – überall, wo eine Anlage fest hängt',
   'h1_line1' => 'Eure Anlage brummt seit Jahren?', 'h1_line2' => 'Das muss sie nicht.',
   'sub' => 'Ich bin Markus von Lauschgift Veranstaltungstechnik. In fast jedem Vereinsheim hängt eine Anlage, die irgendwann mal jemand angeschlossen hat, der längst weggezogen ist. Seitdem: Brummen, Pfeifen und ein Mischpult, an das sich keiner traut. Ich schaue mir das an – gründlich, ehrlich und mit schriftlichem Bericht.',
   'kicker1' => 'So läuft der Check', 'h2_1' => 'Ehrlich hinschauen statt neu verkaufen',
   'cards' => [
     ['icon' => 'search', 'title' => 'Keine Verkaufsshow',
      'text' => 'Ich verkaufe euch beim Check nichts. Wenn eure Anlage gut ist und nur falsch eingestellt, steht genau das im Bericht – und dann ist nach zwei Stunden Einmessen einfach Ruhe. Neu kaufen empfehle ich nur, wenn wirklich nichts anderes hilft, und auch dann mit Preisspannen statt Fantasiezahlen.'],
     ['icon' => 'doc', 'title' => 'Ein Bericht, mit dem ihr arbeiten könnt',
      'text' => 'Ihr bekommt alles schriftlich: was da hängt, was es taugt, was ich direkt einstellen konnte und was sich zu ändern lohnt – nach Prioritäten sortiert. Damit könnt ihr in die Vorstandssitzung gehen und über Fakten entscheiden statt über Gefühle zu diskutieren.'],
     ['icon' => 'users', 'title' => 'Danach traut sich wieder jemand ran',
      'text' => 'Zum Check gehört, dass ich der Person, die die Anlage bedient, alles erkläre – beschriftet, fotografiert, mit Spickzettel für den Festabend. Die Angst, etwas kaputt zu machen, ist bei den meisten Anlagen das größte Problem. Die nehme ich mit.'],
   ],
   'kicker2' => 'Klartext', 'h2_2' => 'Was der Check kostet und was drinsteckt',
   'features' => [
     'Funktionsprüfung aller Komponenten vor Ort',
     'Einmessen und Neueinstellung, soweit direkt möglich',
     'Schriftlicher Bericht mit klarer Empfehlung und Prioritäten',
     'Beschriftung und Spickzettel für die Bedienung',
     'Vorab-Fragebogen kommt automatisch nach eurer Anfrage',
     '149 € inkl. MwSt. je Anlage bzw. Raum, jede weitere 79 € – wird bei Folgeauftrag verrechnet',
   ],
   'pricenote' => 'Beauftragt ihr nach dem Check etwas aus dem Bericht, wird der Check komplett verrechnet – ihr riskiert also nichts außer zwei Stunden eurer Zeit. Und wenn alles gut ist, wisst ihr das danach schwarz auf weiß.',
   'form_kicker' => 'Check anfragen', 'form_h2' => 'Was macht eure Anlage?',
   'form_lead' => 'Beschreibt kurz, was euch stört – direkt nach dem Absenden bekommt ihr von mir einen kurzen Vorab-Fragebogen per Mail, damit ich zum Termin gezielt vorbereitet komme.',
   'form_cfg' => ['event_types' => ['Technik-Check bestehende Anlage'], 'company_label' => 'Verein / Einrichtung',
     'show_date' => false,
     'location_label' => 'Ort / Gebäude', 'location_ph' => 'z. B. Vereinsheim in Hemer, Gemeindehaus …',
     'msg_label' => 'Was stört euch?', 'msg_ph' => 'z. B. Brummen sobald das Mischpult an ist, Reden versteht man hinten nicht …',
     'wa_text' => 'Hallo Markus, es geht um einen Technik-Check unserer Anlage: ',
     'success_text' => 'Danke! Eure Anfrage ist angekommen – schaut gleich in euer Postfach, dort wartet schon der kurze Vorab-Fragebogen. Ich melde mich innerhalb von 24 Stunden für die Terminabstimmung.'],
   'footer_target' => 'technik'],

  ['slug' => 'workshops', 'sort' => 130, 'accent' => '#b9a7ff', 'accent2' => '#d0c4ff', 'btn_txt' => '#151030',
   'page_title' => 'Tontechnik-Workshops | Lauschgift Veranstaltungstechnik, Hemer',
   'meta_desc' => 'Tontechnik verstehen statt fürchten: Workshops in kleinen Gruppen an echter Technik – für Vereine, Gemeinden und alle, die den Ton machen müssen. Auch bei euch vor Ort an eurer eigenen Anlage.',
   'badge' => 'Lernen am echten Mischpult – für Vereine, Gemeinden, Schulen und Neugierige',
   'h1_line1' => 'Eure Anlage kann mehr,', 'h1_line2' => 'als ihr euch traut.',
   'sub' => 'Ich bin Markus – und wenn ich bei meinen Technik-Checks eines gelernt habe, dann das: Das Problem ist selten die Anlage. Es hat nur nie jemand in Ruhe erklärt, wie sie funktioniert. Genau dafür sind meine Workshops da. Kleine Gruppen, echte Technik zum Anfassen, und dumme Fragen gibt es nicht.',
   'kicker1' => 'Für wen das ist', 'h2_1' => 'Vom Zufalls-Techniker zum sicheren Gefühl',
   'cards' => [
     ['icon' => 'users', 'title' => 'Für alle, die es machen müssen',
      'text' => 'In jedem Verein gibt es die Person, die den Ton macht, weil sie sich einmal nicht schnell genug weggeduckt hat. Wenn du diese Person bist: Der Workshop ist für dich. Danach weißt du, was die Regler wirklich tun – und hörst, warum es pfeift, bevor es pfeift.'],
     ['icon' => 'mic', 'title' => 'Üben an echter Technik',
      'text' => 'Wir arbeiten am richtigen Material, nicht an Folien: Mikrofon anschließen, Pegel einstellen, eine Rückkopplung absichtlich provozieren und wieder wegbekommen. Fehler machen ist hier ausdrücklich Teil des Programms – dafür ist es ein Workshop und keine Vorlesung.'],
     ['icon' => 'home', 'title' => 'Auf Wunsch bei euch vor Ort',
      'text' => 'Am meisten bringt der Workshop an der Anlage, mit der ihr nachher wirklich arbeitet. Ich komme mit dem Programm auch zu euch ins Vereinsheim oder Gemeindehaus – dann üben alle an genau den Knöpfen, die sie am Festabend drehen. Ab drei Leuten lohnt sich das.'],
   ],
   'kicker2' => 'Im Detail', 'h2_2' => 'So laufen die Workshops',
   'features' => [
     'Kleine Gruppen, damit jeder ans Pult kommt',
     'Offene Termine oder Inhouse bei euch vor Ort',
     'Echte Technik statt Folien und Theorie',
     'Von Grundlagen bis Rückkopplung im Griff',
     'Unterlagen und Spickzettel zum Mitnehmen',
     'Termine und Anmeldung auf der Technik-Seite',
   ],
   'pricenote' => 'Die aktuellen offenen Termine mit freien Plätzen findet ihr auf der [Technik-Seite](technik.html#workshops). Für einen eigenen Termin mit eurem Team schreibt mir einfach unten – dann stimmen wir Inhalt und Ort auf euch ab.',
   'form_kicker' => 'Anfragen', 'form_h2' => 'Workshop für euer Team?',
   'form_lead' => 'Schreibt mir kurz, wer ihr seid und was ihr lernen wollt – ihr bekommt innerhalb von 24 Stunden einen Vorschlag mit Termin-Optionen und Preis.',
   'form_cfg' => ['event_types' => ['Workshop besuchen'], 'company_label' => 'Verein / Einrichtung',
     'show_date' => false,
     'location_label' => 'Ort', 'location_ph' => 'z. B. bei euch im Vereinsheim oder bei mir …',
     'msg_label' => 'Was wollt ihr lernen?', 'msg_ph' => 'z. B. 5 Leute aus dem Verein, Grundlagen Mischpult und Funkmikros, gern an unserer Anlage …',
     'wa_text' => 'Hallo Markus, es geht um einen Tontechnik-Workshop: '],
   'footer_target' => 'technik'],

  ];
}

/* v65: Markus' Bandbreite bei Hintergrundmusik (Dire Straits, Motown, Funk bis House)
   in die Empfangs-Passagen dreier Aktionsseiten einweben. Ersetzt Karten-Texte NUR,
   wenn sie noch exakt dem v64-Seed entsprechen - eigene Änderungen bleiben unberührt. */
function campaignBackgroundMusicUpdate(PDO $p): void {
  $swaps = [
    'hochzeit' => [
      'Auf einer Hochzeit sitzen drei Generationen an einem Tisch, und alle sollen einen schönen Abend haben. Ich ziehe kein Programm durch, sondern schaue, was gerade passiert: beim Essen dezent, später die Klassiker, und wenn die Tanzfläche läuft, bleibe ich dran. Wünsche eurer Gäste nehme ich den ganzen Abend an.',
      'Auf einer Hochzeit sitzen drei Generationen an einem Tisch, und alle sollen einen schönen Abend haben. Ich ziehe kein Programm durch, sondern schaue, was gerade passiert: zum Sektempfang lockere Hintergrundmusik von Dire Straits über Motown bis House, beim Essen dezent, später die Klassiker – und wenn die Tanzfläche läuft, bleibe ich dran. Wünsche eurer Gäste nehme ich den ganzen Abend an.'],
    'firmensommerfest' => [
      'Um 15 Uhr will niemand Partybeschallung. Ich fange leise an, die Ansprache der Geschäftsführung versteht jeder bis zum letzten Stehtisch, und wenn es dämmert, ziehe ich langsam an – bis von ganz allein getanzt wird. Das funktioniert besser, als um 20 Uhr das Licht auszuknipsen und auf Party zu schalten.',
      'Um 15 Uhr will niemand Partybeschallung. Ich fange locker an – Motown, Funk, auch mal Dire Straits zum Bier –, die Ansprache der Geschäftsführung versteht jeder bis zum letzten Stehtisch, und wenn es dämmert, ziehe ich langsam Richtung House und Party an, bis von ganz allein getanzt wird. Das funktioniert besser, als um 20 Uhr das Licht auszuknipsen.'],
    'produktpraesentation' => [
      'Vor und nach dem offiziellen Teil lege ich auf: housig, dezent, erwachsen. Musik, die Gespräche möglich macht und trotzdem klarstellt, dass hier gerade etwas stattfindet – kein Fahrstuhl-Geplätscher, keine Charts-Beschallung.',
      'Vor und nach dem offiziellen Teil lege ich auf: dezent und erwachsen, je nach Publikum von Motown und Funk bis zu ruhigem House. Musik, die Gespräche möglich macht und trotzdem klarstellt, dass hier gerade etwas stattfindet – kein Fahrstuhl-Geplätscher, keine Charts-Beschallung.'],
  ];
  $sel = $p->prepare('select id, cards from campaign_pages where slug = ?');
  $upd = $p->prepare('update campaign_pages set cards = ?, updated_at = ? where id = ?');
  foreach ($swaps as $slug => [$old, $new]) {
    $sel->execute([$slug]);
    $row = $sel->fetch();
    if (!$row) continue;
    $cards = json_decode((string)$row['cards'], true);
    if (!is_array($cards)) continue;
    $changed = false;
    foreach ($cards as &$c) if (($c['text'] ?? '') === $old) { $c['text'] = $new; $changed = true; }
    unset($c);
    if ($changed) $upd->execute([json_encode($cards, JSON_UNESCAPED_UNICODE), now(), $row['id']]);
  }
}

/* v67: Der Technik-Check wird auf den Seiten als "149 € pauschal" beworben, im
   Produktkatalog sind 149 € aber ein NETTO-Preis (brutto 177,31 €). Für Vereine,
   Gemeinden und Schulen ohne Vorsteuerabzug ist das ein echter Preisunterschied,
   deshalb steht jetzt überall "zzgl. MwSt." dabei. Ersetzt nur den unveränderten
   Ausgangstext - eigene Formulierungen bleiben stehen. */
function campaignTechCheckPriceUpdate(PDO $p): void {
  $sel = $p->prepare('select id, features, meta_desc from campaign_pages where slug = ?');
  $sel->execute(['technik-check']);
  $row = $sel->fetch();
  if (!$row) return;
  $alt = '149 € pauschal je Anlage bzw. Raum, jede weitere 79 € – wird bei Folgeauftrag verrechnet';
  $neu = '149 € zzgl. MwSt. je Anlage bzw. Raum, jede weitere 79 € – wird bei Folgeauftrag verrechnet';
  $feats = json_decode((string)$row['features'], true);
  $changed = false;
  if (is_array($feats)) {
    foreach ($feats as &$f) if ($f === $alt) { $f = $neu; $changed = true; }
    unset($f);
  }
  $desc = (string)$row['meta_desc'];
  $descNeu = str_replace('149 Euro pauschal, wird bei Folgeauftrag verrechnet',
    '149 Euro zzgl. MwSt., wird bei Folgeauftrag verrechnet', $desc);
  if ($changed || $descNeu !== $desc)
    $p->prepare('update campaign_pages set features = ?, meta_desc = ?, updated_at = ? where id = ?')
      ->execute([json_encode($feats, JSON_UNESCAPED_UNICODE), $descNeu, now(), $row['id']]);
}

/* v69: Alle Preise werden brutto ausgewiesen (Entscheidung von Markus). Die mit v67
   eingefügte Formulierung "zzgl. MwSt." widersprach dem Katalogpreis - dort sind 149 €
   der Bruttopreis, den der Kunde auf der Rechnung sieht. Beworben wären es sonst
   177,31 € gewesen. Ersetzt nur die beiden bekannten Formulierungen. */
function campaignTechCheckBruttoUpdate(PDO $p): void {
  $sel = $p->prepare('select id, features, meta_desc from campaign_pages where slug = ?');
  $sel->execute(['technik-check']);
  $row = $sel->fetch();
  if (!$row) return;
  $neu = '149 € inkl. MwSt. je Anlage bzw. Raum, jede weitere 79 € – wird bei Folgeauftrag verrechnet';
  $alt = ['149 € zzgl. MwSt. je Anlage bzw. Raum, jede weitere 79 € – wird bei Folgeauftrag verrechnet',
          '149 € pauschal je Anlage bzw. Raum, jede weitere 79 € – wird bei Folgeauftrag verrechnet'];
  $feats = json_decode((string)$row['features'], true);
  $changed = false;
  if (is_array($feats)) {
    foreach ($feats as &$f) if (in_array($f, $alt, true)) { $f = $neu; $changed = true; }
    unset($f);
  }
  $desc = (string)$row['meta_desc'];
  $descNeu = str_replace(['149 Euro zzgl. MwSt., wird bei Folgeauftrag verrechnet',
      '149 Euro pauschal, wird bei Folgeauftrag verrechnet'],
    '149 Euro inkl. MwSt., wird bei Folgeauftrag verrechnet', $desc);
  if ($changed || $descNeu !== $desc)
    $p->prepare('update campaign_pages set features = ?, meta_desc = ?, updated_at = ? where id = ?')
      ->execute([json_encode($feats, JSON_UNESCAPED_UNICODE), $descNeu, now(), $row['id']]);
}

/* Datenschutzerklärung – eine Quelle für Seed und Migration (v25) */
function datenschutzText(): string {
  return "Datenschutzerklärung\n\n1. Verantwortlicher\nMarkus Jankowski, Büttmecker Weg 35c, 58675 Hemer, Telefon 01523 6439373.\n\n2. Hosting\nDiese Website wird bei der ALL-INKL.COM – Neue Medien Münnich (Deutschland) gehostet. Beim Aufruf der Seiten verarbeitet der Hoster technisch notwendige Daten (z. B. IP-Adresse, Zeitpunkt des Abrufs) in Server-Logfiles auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO (sicherer Betrieb der Website).\n\n3. Cookies und lokale Speicherung\nDiese Website verwendet keine Cookies zu Werbe- oder Tracking-Zwecken und bindet keine Dienste ein, die solche Cookies setzen. Ein Cookie-Banner ist deshalb nicht erforderlich. Nur im Kundenportal und im Partner-Bereich wird nach eurer aktiven Anmeldung ein technisch notwendiges Sitzungsmerkmal im Browser gespeichert (Local/Session Storage), damit ihr angemeldet bleibt (§ 25 Abs. 2 TDDDG).\n\n4. Schriftarten\nAlle Schriftarten liegen lokal auf dem Server dieser Website. Beim Seitenaufruf wird keine Verbindung zu Google Fonts oder anderen Drittanbietern aufgebaut.\n\n5. Reichweitenmessung\nZur Verbesserung des Angebots wird anonym gezählt, wie oft die einzelnen Seiten aufgerufen werden (nur Datum, Seitenname und ggf. die Domain der verweisenden Website). Dabei werden weder IP-Adressen noch Cookies oder sonstige Kennungen gespeichert – ein Bezug zu einzelnen Personen ist nicht möglich (Art. 6 Abs. 1 lit. f DSGVO).\n\n6. Anfrageformular\nWenn ihr das Anfrageformular nutzt, verarbeite ich die dort eingegebenen Daten (Name, E-Mail, Telefon, Angaben zur Feier, Nachricht) zur Bearbeitung eurer Anfrage und für die Vertragsanbahnung (Art. 6 Abs. 1 lit. b DSGVO). Die Daten werden auf dem eigenen Server dieser Website gespeichert und nicht an Dritte weitergegeben, sofern ihr nicht ausdrücklich eine Vermittlung an Partner-DJs wünscht.\n\n7. Newsletter\nFür den Workshop-Newsletter speichere ich eure E-Mail-Adresse erst nach Bestätigung über den zugesandten Link (Double-Opt-in) auf Grundlage eurer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO). Jede Mail enthält einen Abmeldelink; nach der Abmeldung erhaltet ihr keine weiteren Mails. Es wird kein Versanddienstleister eingesetzt – der Versand erfolgt über den eigenen Server.\n\n8. DJ-Vermittlung\nWünscht ihr eine Vermittlung an andere DJs, gebe ich die dafür erforderlichen Kontakt- und Veranstaltungsdaten an meine Partner-Agentur DJ Bande (Münster) weiter – ausschließlich mit eurer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO).\n\n9. Digitaler Mietvertrag und Ausweiskopie\nBei der Vermietung von Veranstaltungstechnik könnt ihr den Mietvertrag digital abschließen. Dabei werden eure Unterschrift sowie – mit eurer ausdrücklichen Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 20 PAuswG) – Fotos der Vorder- und Rückseite eures Personalausweises verarbeitet und in einem zugriffsgeschützten Bereich des eigenen Servers gespeichert. Nicht benötigte Angaben dürft ihr vor dem Fotografieren schwärzen. Die Ausweiskopien dienen ausschließlich der Absicherung des Mietverhältnisses und werden nach vollständiger Rückgabe der Mietsachen gelöscht.\n\n10. Kundenportal\nIm Kundenportal könnt ihr euch mit E-Mail-Adresse und Passwort anmelden, um eure Unterlagen einzusehen und Angaben zu eurer Feier zu pflegen. Das Passwort wird ausschließlich verschlüsselt (als Hash) gespeichert; alle Inhalte liegen auf dem eigenen Server dieser Website (Art. 6 Abs. 1 lit. b DSGVO).\n\n11. Eure Rechte\nIhr habt das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit sowie Beschwerde bei einer Aufsichtsbehörde. Meldet euch dafür einfach unter den oben genannten Kontaktdaten.\n\nStand: August 2026.";
}

function migrate(PDO $p): void {
  $p->exec(<<<SQL
create table users (id text primary key, email text unique not null, pass_hash text not null, created_at text);
create table tokens (token text primary key, user_id text not null, expires integer not null);
create table settings (key text primary key, value text not null default '{}', updated_at text);
create table site_content (key text primary key, value text not null default '{}', updated_at text);
create table packages (id text primary key, sort integer default 0, title text not null, subtitle text,
  description text, price_from real, price_note text, features text default '[]',
  image_url text, image_focal text default '50% 50%',
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
  highlight integer default 0, public integer default 1, created_at text,
  contact_name text, contact_phone text, technik_notes text);
create table content_versions (id text primary key, key text not null,
  label text, value text not null default '{}', created_at text);
create table inquiries (id text primary key, name text not null, email text, phone text,
  event_type text, event_date text, location text, guests text, message text,
  status text default 'neu', customer_id text, created_at text);
create table customers (id text primary key, kind text default 'privat', status text default 'lead',
  first_name text, last_name text, company text, email text, phone text, whatsapp text,
  street text, zip text, city text, source text, tags text default '[]', notes text, tech_check text,
  partner_name text, portal_hash text, portal_invite text, portal_invite_expires integer,
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
  event_plan text, event_plan_internal text,
  billable_days integer, open_ended integer default 0,
  review_requested integer default 0, created_at text, updated_at text);
create table event_plan_changes (id text primary key,
  booking_id text not null references bookings(id) on delete cascade,
  field_path text not null, field_label text, old_value text, new_value text,
  status text default 'offen', created_at text, reviewed_at text);
create table booking_equipment (id text primary key,
  booking_id text not null references bookings(id) on delete cascade,
  equipment_id text not null references equipment(id) on delete restrict,
  qty integer default 1, price_override real, out_done integer default 0,
  back_done integer default 0, notes text);
create table documents (id text primary key, share_token text, doc_type text not null, number text unique not null,
  price_mode text default 'brutto', discount_value real default 0, discount_type text default 'pct',
  customer_id text not null references customers(id) on delete restrict,
  booking_id text references bookings(id) on delete set null,
  parent_id text, status text default 'entwurf', doc_date text, valid_until text, due_date text,
  tax_rate real default 19, is_small_business integer default 0, intro_text text, outro_text text,
  rental_from text, rental_to text,
  total_net real default 0, total_tax real default 0, total_gross real default 0,
  deposit_deducted real default 0, total_override real, sent_at text, paid_at text,
  accepted_name text, accept_signature text, event_info text, created_at text, updated_at text);
create table tech_checks (id text primary key,
  customer_id text not null references customers(id) on delete cascade,
  document_id text references documents(id) on delete set null,
  data text default '{}', created_at text, updated_at text);
create table email_templates (id text primary key, sort integer default 0, name text not null,
  subject text, body text, created_at text);
create table products (id text primary key, sku text unique, sort integer default 0,
  category text, name text not null, description text, unit text default 'Stk.',
  kind text default 'artikel', price_net real, bundle text default '[]', addon_sku text,
  active integer default 1, created_at text);
create table quote_templates (id text primary key, sort integer default 0, name text not null,
  intro_text text, outro_text text, items text default '[]', created_at text);
create table partners (id text primary key, code text unique, name text not null, company text,
  kind text default 'dj', email text, phone text, status text default 'beantragt',
  discount_pct real, notes text, created_at text);
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
  discount_value real default 0, discount_type text default 'pct', is_header integer default 0, group_pos integer);
SQL);
  $p->exec(rentalContractsDdl());
  $p->exec(friendsDdl());
  $p->exec(badgesDdl());
  $p->exec(blocksDdl());
  $p->exec(eventReportsDdl());
  /* Instagram-Modul gleich anlegen, aber ausgeschaltet - so ist der frische Stand
     derselbe wie nach der Migration bestehender Installationen. */
  $p->prepare('insert into blocks (id,page,anchor,sort,type,kicker,title,media,layout,public,created_at)
    values (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([uuid(), 'start', 'galerie', 0, 'instagram', 'Frisch aus Instagram', '', '[]', '4', 0, now()]);
  foreach (workshopsDdl() as $sql) $p->exec($sql);
  $p->exec(docAuditDdl());
  foreach (portalAccountDdl() as $sql) $p->exec($sql);
  foreach (statsNewsletterDdl() as $sql) $p->exec($sql);
  $p->exec(campaignPagesDdl());
  seedCampaignPages($p);
  seed($p);
  seedExtraTemplates($p);
  seedServiceProducts($p);
  mergeOldCatalogPdf($p);
  reAddCorePositions($p);
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
    ['defaults', '{"tax_rate":19,"payment_days":14,"quote_valid_days":30,"quote_intro":"vielen Dank für eure Anfrage. Gerne biete ich euch an:","confirm_intro":"schön, dass ihr euch entschieden habt. Hiermit bestätige ich euch den Auftrag verbindlich – der Termin ist ab jetzt für euch reserviert.","invoice_outro":"Bitte überweist den Betrag unter Angabe der Rechnungsnummer auf das unten genannte Konto."}'],
  ] as [$k, $v]) $p->prepare('insert into settings (key,value,updated_at) values (?,?,?)')->execute([$k, $v, now()]);

  foreach ([
    ['hero', '{"title":"DJ Lauschgift","headline":"Volle Tanzfläche.\n*Ohne Schnickschnack.*","scrim":{"mode":"gleich","pct":30},"badges":[{"value": "23", "label": "Jahre hinter den Decks"}, {"value": "Plan B", "label": "immer inklusive"}, {"value": "Seeburg", "label": "Premium-Sound"}],"subtitle":"DJ für Hochzeiten, Geburtstage & Firmenfeiern · deutschlandweit","text":"Ich bin Markus – seit 23 Jahren DJ, quer durch Deutschland unterwegs. Keine Show um meine Person, kein Programm von der Stange: Ich lese den Raum und spiele das, was eure Gäste auf die Tanzfläche bringt. Ihr müsst euch um nichts kümmern – dafür bin ich da.","cta":"Unverbindlich anfragen","image":""}'],
    ['about', '{"title":"Einfach Markus. Und trotzdem kein Standard-DJ.","gear":["Seeburg Acoustic Line","ApeLabs","Sennheiser","Rane","Allen & Heath"],"text":"Angefangen hat alles mit zwei Plattenspielern und einem alten Mischpult zum 18. Geburtstag. Ein Jahr lang habe ich in der heimischen Garage geübt, bis ich für bekannte DJs das Warm-up in angesagten Clubs übernehmen durfte. Den eigentlichen Wendepunkt gab es aber bei einer ganz anderen Feier: Als meine Tante mich zu ihrem runden Geburtstag fragte, ob ich auch gemischte Musik auflegen könnte, war ich skeptisch – bis Jung und Alt gemeinsam auf der Tanzfläche standen und weitersangen, als ich den Regler runterzog. Seitdem ist mir in 23 Jahren kein einziger Abend langweilig geworden.\\n\\nWas mich von vielen anderen unterscheidet: Ich bin ein echter Technik- und Menschenfreund. Ich nehme euch und eure Gäste bewusst wahr und setze auf Licht- und Tontechnik, die man sonst eher von deutlich größeren Produktionen kennt – weil auch eine Feier mit 40 Gästen großartige Technik verdient. Mein Sound kommt von Seeburg Acoustic Line, einem der deutschen Top-Hersteller für mobile PA-Systeme – das hört man sofort. Dazu passe ich mich flexibel an jede Location an, ob Scheune, Schloss, Industriehalle oder Gartenparty: Ich kenne mein Equipment in- und auswendig und weiß, wie ich jeden Raum klanglich und optisch in Szene setze.","image":"img/markus_1.jpg"}'],
    ['services', '{"title":"Das bekommt ihr","text":"Vom Sektempfang bis zum letzten Song: Musik, Ton für die freie Trauung, dezentes Licht passend zur Location – und ein Plan B für alle Fälle. Ihr feiert, ich kümmere mich um den Rest.","image":""}'],
    ['guarantee', '{"title":"Schon ausgebucht? Ihr steht trotzdem nicht ohne DJ da.","text":"Wenn ich an eurem Termin keine Zeit habe – oder merke, dass ich nicht der richtige DJ für eure Feier bin – wähle ich persönlich bis zu fünf Kollegen aus meinem Partner-Netzwerk aus, die wirklich zu euch passen. Keine anonyme Liste: Ich kenne die Kollegen und ihre Stärken, und ihr bekommt die Vorschläge direkt von mir – auch günstigere Optionen sind dabei, falls euer Budget das erfordert. Und Transparenz gehört dazu: Für eine erfolgreiche Vermittlung erhalte ich eine kleine Provision (Details in den AGB)."}'],
    ['rental', '{"title":"Technik mieten","text":"Von der Anlage für Redenbeiträge bis zu LED-Spots für die Raumdeko – alles gewartet, geprüft und mit kurzer Einweisung bei der Abholung."}'],
    ['tech_hero', '{"headline":"Jedes Wort verständlich.\n*Auch in der schwierigsten Location.*","scrim":{"mode":"gleich","pct":30},"badges":[{"value": "24 h", "label": "= 1 Miettag"}, {"value": "50 %", "label": "jeder Folgetag"}, {"value": "Hemer", "label": "Lager & Abholung"}],"subtitle":"Lauschgift Veranstaltungstechnik · Hemer","text":"Große Bühnen mit viel Platz kann jeder beschallen. Die Kunst ist die kleine Location: niedrige Decke, harte Wände, Publikum direkt vor der Box. Genau darauf bin ich spezialisiert – Ton und Licht für Veranstaltungen von 30 bis 200 Gästen, mit hochwertiger Technik, die dafür gebaut ist."}'],
    ['tech_teaser', '{"title":"Lauschgift Veranstaltungstechnik","text":"Ton und Licht gehören für mich untrennbar zum DJ-Sein dazu – deshalb biete ich beides auch unabhängig voneinander an: Technik zum Mieten direkt aus meinem Lager in Hemer, oder mich als Techniker inklusive Equipment, ganz ohne Auflegen. Alle Details dazu auf der Technik-Seite."}'],
    ['contact', '{"title":"Kontakt","phone":"01523 6439373","email":"lauschgiftmarkus@gmail.com","address":"Büttmecker Weg 35c, 58675 Hemer","instagram":"https://www.instagram.com/dj_lauschgift/","whatsapp":""}'],
    ['theme', '{"preset":"koralle","primary":"#ff6f5b","bg":"#0f1012","font":"grotesk"}'],
    ['reviews', '{"google_url":"","djbande_url":"","tagline":""}'],
    ['loc_section', '{"title":"Orte, an denen ich besonders gerne auflege","text":"Deutschlandweit gibt es Locations, mit denen die Zusammenarbeit einfach herausragend läuft – eingespielte Teams, gute Technik-Bedingungen, tolle Räume. Diese Häuser empfehle ich aus voller Überzeugung."}'],
    ['gallery', '{"title":"So sieht\'s bei mir aus","images":["img/IMG_4061.png","img/IMG_4086.png","img/IMG_3296.png","img/IMG_9059.png","img/IMG_3591.png","img/spiegelkugel mittig.jpg","img/IMG_0850.png"]}'],
    ['pack_sec', '{"images":true}'],
    ['badges_sec', '{"mitglied": {"enabled": true, "show_tech": true, "title": "Wo ich mitmache", "text": "Netzwerke und Portale, in denen ich gelistet bin – wer mag, schaut dort nach, was andere über meine Arbeit schreiben."}, "technik": {"enabled": true, "show_tech": true, "title": "Womit ich arbeite", "text": "Die Technik, die bei mir im Wagen liegt. Keine Werbung, sondern eine ehrliche Auskunft darüber, was ich mitbringe."}}'],
    ['seo', '{"title":"DJ Lauschgift – Hochzeits-DJ & Event-DJ | Deutschlandweit","description":"DJ Lauschgift – Markus Jankowski. 23 Jahre Erfahrung für Hochzeiten, Geburtstage & Firmenfeiern. Deutschlandweit buchbar. Technikverleih in Hemer."}'],
    ['legal', json_encode([
      'impressum' => "Angaben gemäß § 5 DDG\n\nMarkus Jankowski\nDJ Lauschgift\nBüttmecker Weg 35c\n58675 Hemer\n\nTelefon: 01523 6439373\nE-Mail: lauschgiftmarkus@gmail.com\n\nVerantwortlich für den Inhalt: Markus Jankowski (Anschrift wie oben)\n\nVerbraucherstreitbeilegung: Ich bin nicht verpflichtet und nicht bereit, an einem Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen (§ 36 VSBG).",
      'datenschutz' => datenschutzText(),
      'reviewed' => false,
      'widerrufsformular' => widerrufsformularText(),
      'agb' => agbIntro() . "Allgemeine Geschäftsbedingungen (AGB)\n\n1. Geltungsbereich\nDiese AGB gelten ausschließlich für Verträge über DJ-Leistungen und Technikvermietung, die unmittelbar mit Markus Jankowski (DJ Lauschgift), Büttmecker Weg 35c, 58675 Hemer, geschlossen werden.\n\nSie gelten nicht für Verträge, die der Auftraggeber mit anderen DJs schließt – etwa nach einer Empfehlung bzw. Vermittlung über die Partner-Agentur (vgl. Ziffer 6) oder direkt mit dem jeweiligen DJ. Für solche Verträge gelten allein die Bedingungen des jeweiligen DJs bzw. der Agentur; der Auftragnehmer ist an diesen Verträgen nicht beteiligt und übernimmt für deren Inhalt und Erfüllung keine Haftung.\n\n2. Angebot und Vertragsschluss\nAngebote sind freibleibend. Der Vertrag kommt mit schriftlicher Bestätigung (auch per E-Mail) zustande. Erst mit der Bestätigung ist der Termin verbindlich reserviert.\n\n3. Preise\nDie Vergütung richtet sich nach Auslastung, Arbeitsstunden und technischem Aufwand der jeweiligen Veranstaltung; eine Unterscheidung nach Anlass (z. B. Hochzeit, Geburtstag, Firmenfeier) findet nicht statt. Alle Posten werden im Angebot ausgewiesen.\n\n4. Ausfall des Auftragnehmers und Ersatz (Plan B)\nFällt der Auftragnehmer aus (z. B. durch Krankheit), verpflichtet er sich, sich im Rahmen seiner Möglichkeiten um einen geeigneten Ersatz-DJ aus seinem Kollegen-Netzwerk zu bemühen und diesen dem Auftraggeber unverzüglich vorzuschlagen.\n\nDer Vorschlag ist für den Auftraggeber unverbindlich: Er kann frei entscheiden, ob er den vorgeschlagenen Ersatz-DJ beauftragt oder vom Vertrag zurücktritt. Bei Rücktritt werden bereits geleistete Zahlungen vollständig erstattet; weitergehende Ansprüche bestehen nur bei Vorsatz oder grober Fahrlässigkeit.\n\nEntscheidet sich der Auftraggeber für den Ersatz-DJ, kommt der Vertrag über dessen Leistung direkt mit dem Ersatz-DJ zustande. Wichtig: Der Ersatz-DJ rechnet zu seinen eigenen Preisen ab – der Endpreis kann daher vom ursprünglich vereinbarten Preis abweichen. Auch der Leistungsumfang, insbesondere die mitgeführte Ton- und Lichttechnik, kann vom Angebot des Auftragnehmers abweichen. Bereits an den Auftragnehmer geleistete Zahlungen werden in diesem Fall erstattet bzw. verrechnet.\n\n5. Stornierung durch den Auftraggeber\nSagt der Auftraggeber die Veranstaltung ab, kann kurzfristig in der Regel kein Ersatzauftrag mehr angenommen werden – insbesondere innerhalb von sechs Wochen vor dem Termin ist eine Neubelegung praktisch ausgeschlossen. Daher gilt folgende pauschale Ausfallvergütung (jeweils bezogen auf die vereinbarte Nettovergütung):\n– Absage bis 6 Monate vor dem Termin: 20 %\n– Absage bis 3 Monate vor dem Termin: 40 %\n– Absage bis 6 Wochen vor dem Termin: 60 %\n– Absage weniger als 6 Wochen vor dem Termin: 80 %\n– Absage weniger als 7 Tage vor dem Termin oder Nichtabnahme: 90 %\nErsparte Aufwendungen (z. B. nicht anfallende Fahrtkosten sowie stornierbare Übernachtungskosten) werden angerechnet und von der Ausfallvergütung abgezogen. Dem Auftraggeber bleibt der Nachweis unbenommen, dass kein oder ein wesentlich geringerer Schaden entstanden ist. Gelingt es dem Auftragnehmer, für den Termin einen gleichwertigen Ersatzauftrag anzunehmen, entfällt die Ausfallvergütung bis auf bereits entstandene Kosten. Maßgeblich für die Staffel ist der Zugang der Absage in Textform.\n\nUmbuchung auf einen Ersatztermin: Einigen sich beide Seiten auf einen Ersatztermin, kann der Auftragnehmer anstelle der Ausfallvergütung eine reduzierte Umbuchungspauschale ansetzen; bereits entstandene Kosten (z. B. nicht stornierbare Auslagen) werden zusätzlich berechnet. Die Umbuchung ist eine reine Kulanzregelung des Auftragnehmers: Ein Anspruch auf einen Ersatztermin oder auf eine reduzierte Pauschale besteht nicht. Ob und zu welchen Konditionen umgebucht wird, entscheidet der Auftragnehmer frei im Einzelfall – insbesondere abhängig von seiner Verfügbarkeit am Wunschtermin, davon, ob der ursprüngliche Termin anderweitig belegt werden kann, und vom Buchungswert des Ersatztermins.\n\n6. DJ-Vermittlung über Partner-Agentur\nIst der Auftragnehmer am gewünschten Termin verhindert oder kommt eine Zusammenarbeit aus anderen Gründen nicht zustande, kann er dem Interessenten auf Wunsch bis zu fünf passende DJs vorschlagen. Diese Empfehlung ist eine reine Vermittlungsleistung des Auftragnehmers und für den Interessenten kostenlos – sie wird ihm nicht in Rechnung gestellt.\n\nDie Vermittlung erfolgt über die Partner-Agentur DJ Bande (Münster). Der Vertrag über die DJ-Leistung kommt ausschließlich zwischen dem Interessenten und dem vermittelten DJ bzw. der Agentur zustande; die Abrechnung der DJ-Leistung erfolgt nicht über den Auftragnehmer. Die Vermittlungsleistung finanziert sich dadurch, dass der Auftragnehmer für eine erfolgreich zustande gekommene Vermittlung eine Aufwandsentschädigung (Provision) von der Agentur bzw. dem vermittelten DJ erhält. Für den Interessenten entstehen dadurch keine zusätzlichen Kosten. Die auf dieser Website genannten Preise und Preisbeispiele gelten ausschließlich für Leistungen des Auftragnehmers selbst; vermittelte DJs kalkulieren ihre Vergütung eigenständig, deren Konditionen können abweichen.\n\n7. Widerrufsrecht\nBei der Buchung von DJ- und Veranstaltungstechnik-Leistungen für einen bestimmten Termin besteht kein Widerrufsrecht. Gemäß § 312g Abs. 2 Nr. 9 BGB ist das Widerrufsrecht ausgeschlossen bei Verträgen zur Erbringung von Dienstleistungen im Zusammenhang mit Freizeitbetätigungen, wenn der Vertrag für die Erbringung einen spezifischen Termin oder Zeitraum vorsieht. Jede Buchung ist daher rechtsverbindlich und verpflichtet zur Abnahme und Bezahlung der Leistung.\n\nSofern eine Buchung im Einzelfall nicht unter § 312g Abs. 2 Nr. 9 BGB fallen sollte, gilt für Verbraucher: Sie haben das Recht, binnen vierzehn Tagen ab Vertragsschluss diesen Vertrag ohne Angabe von Gründen zu widerrufen. Der Widerruf ist zu richten an: Markus Jankowski, Büttmecker Weg 35c, 58675 Hemer (oder per E-Mail an die im Impressum genannte Adresse).\n\n8. Technikvermietung\nMietpreise gelten pro Miettag (24 Stunden); jeder Folgetag wird mit 50 % des Grundpreises berechnet. Der Mieter haftet für Verlust und Beschädigung der Mietsachen ab Übergabe bis zur Rückgabe.\n\n9. Zahlungsbedingungen\nRechnungen sind, sofern nicht anders vereinbart, innerhalb von 14 Tagen ohne Abzug zahlbar. Bei Buchungen kann eine Abschlagszahlung vereinbart werden.\n\n10. Schlussbestimmungen\nEs gilt deutsches Recht. Sollten einzelne Bestimmungen unwirksam sein, bleibt der Vertrag im Übrigen wirksam.\n\nStand: August 2026.",
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

  $ins('equipment', ['sort'=>1,'name'=>'Nebelmaschine klein','slug'=>'nebelmaschine-klein','category'=>'Nebel/Haze',
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
    [3, 'Firmenfeier – Erstantwort', 'Deine Veranstaltung am {datum} – Rückmeldung von DJ Lauschgift',
"Hallo {name},

vielen Dank für deine Anfrage zu eurer Firmenveranstaltung am {datum}.

Der Termin ist bei mir aktuell noch verfügbar. Gerne stimme ich mich kurz mit dir (oder eurer Eventplanung) zum Ablauf ab – vom dezenten Empfang über Ton für Redebeiträge bis zum Partyprogramm. Auf dieser Basis bekommst du ein transparentes Angebot mit klar ausgewiesenen Posten für Dauer und Technik.

Für Veranstaltungen unter der Woche oder tagsüber kalkuliere ich übrigens spürbar günstiger.

Wann darf ich dich am besten anrufen?

Viele Grüße
Markus Jankowski – DJ Lauschgift

PS: Stimmen bisheriger Kunden findest du hier: {bewertungen}"],
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

  /* Zugriffsregeln für nicht eingeloggte Aufrufer.
     Achtung: Filter auf Spalten, die es in der Tabelle nicht gibt, werden beim
     Bauen der Abfrage stillschweigend verworfen (siehe restQuery). Die
     Sichtbarkeits-Spalte muss deshalb pro Tabelle stimmen, sonst wäre die
     Tabelle unbemerkt komplett öffentlich. */
  if (!$auth) {
    if ($method === 'GET' && in_array($t, PUBLIC_READ)) {
      if ($t === 'campaign_pages') { $q['enabled'] = 'eq.true'; }   // Aktionsseiten: Entwürfe bleiben privat
      elseif ($t === 'site_content') { /* Website-Texte sind vollständig öffentlich */ }
      elseif ($t === 'equipment_set_items') { /* reine Zuordnungstabelle, Sichtbarkeit steuert das Set */ }
      else { $q['public'] = 'eq.true'; }
      if ($t === 'equipment') { $q['status'] = 'eq.aktiv'; }
    } elseif ($method === 'POST' && $t === 'inquiries') {
      /* Spam-Bremse ohne IP-Speicherung (die Datenschutzerklärung sagt zu, dass keine
         IP-Adressen verarbeitet werden):
         1. Honigtopf-Feld "website" - für Menschen unsichtbar, Bots füllen es aus.
            Antwort trotzdem freundlich, damit ein Bot nicht lernt, was ihn verraten hat.
         2. Drossel je E-Mail-Adresse gegen Dauerfeuer aufs Formular. */
      if (trim((string)(is_array($body) ? ($body['website'] ?? '') : '')) !== '')
        out(['ok' => true], 201);
      $row = array_intersect_key(is_array($body) ? $body : [], array_flip(INQUIRY_FIELDS));
      if (empty($row['name'])) fail('Name erforderlich.', 400);
      if (!empty($row['email'])) {
        $rl = $p->prepare("select count(*) from inquiries where lower(email) = ? and created_at > ?");
        $rl->execute([strtolower(trim((string)$row['email'])), gmdate('Y-m-d\TH:i:s\Z', time() - 600)]);
        if ((int)$rl->fetchColumn() >= 3)
          fail('Deine Anfrage ist schon bei mir angekommen – ich melde mich in Kürze. Wenn es eilt, ruf gern direkt an.', 429);
      }
      /* E-Mail serverseitig validieren: verhindert, dass krude Zeichenketten gespeichert und
         später im Backoffice weiterverarbeitet werden (Defense-in-Depth gegen Attribut-Ausbruch). */
      if (!empty($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL))
        fail('Bitte eine gültige E-Mail-Adresse angeben.', 400);
      /* Doppelklick / mehrfach abgeschicktes Formular: dieselbe Anfrage innerhalb von zehn
         Minuten nicht noch einmal ablegen, sonst steht sie zwei- oder dreimal in der Liste.
         Antwort bleibt freundlich 201 - für den Absender hat es ja geklappt. */
      if (!empty($row['email'])) {
        $dup = $p->prepare("select 1 from inquiries where lower(email) = ? and coalesce(message,'') = ?
          and coalesce(event_date,'') = ? and created_at > ? limit 1");
        $dup->execute([strtolower(trim((string)$row['email'])), (string)($row['message'] ?? ''),
          (string)($row['event_date'] ?? ''), gmdate('Y-m-d\TH:i:s\Z', time() - 600)]);
        if ($dup->fetchColumn()) out(['ok' => true], 201);
      }
      $row['id'] = uuid(); $row['status'] = 'neu'; $row['created_at'] = now();
      $cols = array_keys($row);
      $p->prepare("insert into inquiries (" . implode(',', $cols) . ") values (" .
        implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($row));
      /* Fehler beim automatischen Anlegen des Veranstaltungsplaners dürfen die Anfrage selbst
         nie verhindern - das ist ein Service-Extra, kein kritischer Pfad. */
      try { autoInquiryPlanner($p, $row); } catch (Throwable $e) {}
      notifyOwner('Neue Anfrage: ' . $row['name'] . (($row['event_type'] ?? '') ? ' – ' . $row['event_type'] : ''),
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
      /* Bei einer Technik-Check-Anfrage den Fragebogen-Link mitgeben: Die Seite verspricht
         ihn "sofort im Postfach" - falls die Mail hängt, sieht der Kunde ihn wenigstens hier. */
      out(!empty($GLOBALS['_techCheckFormLink']) ? ['ok' => true, 'form_link' => $GLOBALS['_techCheckFormLink']] : null, 201);
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
      $docStatusMap = [];
      if ($t === 'document_items') {
        $docIds = array_values(array_unique(array_filter(array_map(fn($r) => is_array($r) ? ($r['document_id'] ?? null) : null, $items))));
        if ($docIds) {
          $in = implode(',', array_fill(0, count($docIds), '?'));
          $chk = $p->prepare("select id, number, doc_type, status from documents where id in ($in)");
          $chk->execute($docIds);
          foreach ($chk->fetchAll() as $d) {
            if (docLockedRow($d))
              fail('Rechnung ' . $d['number'] . ' ist festgeschrieben (GoBD): Positionen können nicht mehr geändert werden.', 409);
            $docStatusMap[$d['id']] = $d['status'];
          }
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
        /* Nur protokollieren, wenn der Beleg schon versendet ist - sonst wuerde jede
           normale Ersterfassung eines Angebots das Protokoll mit "Position hinzugefuegt"
           je Zeile zumuellen. Genau der gemeinte Fall ist die nachtraegliche Korrektur
           eines Angebots, das der Kunde schon gesehen hat. */
        if ($t === 'document_items' && !empty($row['document_id'])
          && ($docStatusMap[$row['document_id']] ?? 'entwurf') !== 'entwurf')
          docAudit($p, $row['document_id'], 'Position hinzugefügt',
            ($row['description'] ?? '') . ' (' . ($row['qty'] ?? 1) . ' ' . ($row['unit'] ?? '') . ' à ' . number_format((float)($row['unit_price'] ?? 0), 2, ',', '.') . ' €)');
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
      $itemsBefore = null;
      if ($t === 'document_items') {
        $chkI = $p->prepare("select * from document_items$wsql"); $chkI->execute($args);
        $itemsBefore = $chkI->fetchAll();
      }
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
        /* Angebots-Annahme direkt im Backoffice (Status-Buttons) loest denselben
           Technik-Check-Automatismus aus wie die Annahme im Kundenportal. */
        if (($row['status'] ?? null) === 'angenommen')
          foreach ($before as $b) { try { maybeAutoTechCheck($p, $b['id']); } catch (Throwable $e) {} }
        /* ... und zieht den Termin genauso nach wie eine Rueckmeldung ueber das Portal. */
        if (isset($row['status']))
          foreach ($before as $b) { try { syncBookingFromDoc($p, $b, (string)$row['status']); } catch (Throwable $e) {} }
      }
      if ($t === 'document_items' && !empty($itemsBefore)) {
        $itemFields = ['description', 'qty', 'unit', 'unit_price', 'discount_value', 'discount_type'];
        foreach ($itemsBefore as $ib) {
          if (docStatusFor($p, $ib['document_id']) === 'entwurf') continue;
          $changes = [];
          foreach ($row as $c => $vNew) {
            if (!in_array($c, $itemFields)) continue;
            $old = $ib[$c] ?? null;
            if ((string)$old !== (string)$vNew) $changes[] = "$c: " . ($old ?? '–') . ' → ' . ($vNew ?? '–');
          }
          if ($changes) docAudit($p, $ib['document_id'], 'Position geändert',
            ($ib['description'] ?? '') . ' · ' . implode(', ', $changes));
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
      if ($t === 'document_items') {
        assertItemsUnlocked($p, $wsql, $args);
        $chkI = $p->prepare("select * from document_items$wsql"); $chkI->execute($args);
        foreach ($chkI->fetchAll() as $ib)
          if (docStatusFor($p, $ib['document_id']) !== 'entwurf')
            docAudit($p, $ib['document_id'], 'Position entfernt', (string)($ib['description'] ?? ''));
      }
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
  $st = $p->prepare('select d.*, c.first_name, c.last_name, c.company, c.street, c.zip, c.city, c.portal_hash
    from documents d join customers c on c.id = d.customer_id where d.share_token = ?');
  $st->execute([$token]);
  $d = $st->fetch();
  if (!$d) fail('Dieses Angebot wurde nicht gefunden oder der Link ist abgelaufen.', 404);
  /* Wer schon ein Kundenkonto hat und eingeloggt ist, hat seine Identitaet damit
     schon bestaetigt - die PLZ-Abfrage ist dann eine unnoetige zusaetzliche Huerde. */
  $me = custAuth();
  if ($me && $me['id'] === $d['customer_id']) return $d;
  if (trim((string)$d['zip']) === '')
    fail('Zu diesem Vorgang ist bei mir noch keine Postleitzahl hinterlegt – deshalb kann ich den Zugang nicht prüfen. Melde dich kurz bei mir (01523 6439373), dann schalte ich dich frei.', 409);
  if (trim($plz) === '' || trim($plz) !== trim((string)$d['zip'])) {
    plzBremse($token, true);
    usleep(500000);
    out(['need' => 'plz'], 401);
  }
  plzBremse($token, false);
  return $d;
}

/* Was bei der Annahme im Portal passiert ist (siehe portal/offer/.../action): null =
   nichts Besonderes, 'konflikt' oder 'abgelaufen'. Steht in doc_events, damit es auch
   nach einem Neuladen des Portals noch bekannt ist. */
function portalAcceptCase(PDO $p, string $docId): ?string {
  $st = $p->prepare("select kind, message from doc_events where document_id = ? and kind in ('konflikt','accept') order by created_at desc");
  $st->execute([$docId]);
  foreach ($st->fetchAll() as $e) {
    if ($e['kind'] === 'konflikt') return 'konflikt';
    if (str_starts_with((string)$e['message'], 'Angebot war abgelaufen')) return 'abgelaufen';
  }
  return null;
}

/* Bremse gegen das Durchprobieren von Postleitzahlen: zählt Fehlversuche je Vorgang
   in einer kleinen Datei (keine IP-Speicherung). Nach 10 Fehlversuchen ist der Zugang
   15 Minuten gesperrt - fünfstellige PLZ wären sonst durchprobierbar. */
function plzBremse(string $token, bool $fehler): void {
  $dir = DATA_DIR . '/plz';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $file = $dir . '/' . substr(hash('sha256', $token), 0, 32) . '.json';
  $st = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
  $n = (int)($st['n'] ?? 0); $bis = (int)($st['bis'] ?? 0);
  if ($bis > time()) {
    usleep(500000);
    fail('Zu viele Fehlversuche. Bitte in etwa 15 Minuten erneut versuchen – oder ruf mich einfach an: 01523 6439373.', 429);
  }
  /* Ist die Sperre abgelaufen, wieder bei null anfangen - sonst löst schon der nächste
     Fehlversuch sofort die nächsten 15 Minuten aus. */
  if ($bis && $bis <= time()) $n = 0;
  if (!$fehler) { @unlink($file); return; }
  $n++;
  @file_put_contents($file, json_encode(['n' => $n, 'bis' => $n >= 10 ? time() + 900 : 0]));
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
  /* Ohne hinterlegte PLZ könnte der Kunde sich nie einloggen - dann lieber sagen,
     woran es liegt, statt ihn endlos "falsche PLZ" probieren zu lassen. */
  if (trim((string)$r['zip']) === '')
    fail('Zu diesem Vorgang ist bei mir noch keine Postleitzahl hinterlegt – deshalb kann ich den Zugang nicht prüfen. Melde dich kurz bei mir (01523 6439373), dann schalte ich dich frei.', 409);
  if (trim($plz) === '' || trim($plz) !== trim((string)$r['zip'])) {
    plzBremse($token, true);
    usleep(500000);
    out(['need' => 'plz'], 401);
  }
  plzBremse($token, false);
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
  /* Nur für echte Teilnehmer: Auf der Warteliste steht die Zusage "bezahlt wird erst,
     wenn du wirklich einen Platz hast" - eine Rechnung dorthin wäre ein Wortbruch. */
  if (($s['status'] ?? '') !== 'angemeldet')
    return ['ok' => false, 'reason' => 'Für Wartelisten- und stornierte Anmeldungen wird keine Rechnung erstellt – erst nach dem Nachrücken.'];
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
  $cst = $p->prepare("select id, zip from customers where email = ?
    order by (portal_hash is not null) desc, coalesce(created_at,'') asc limit 1");
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
    /* w_price ist brutto (inkl. USt.) hinterlegt - netto wird heruntergerechnet, nicht draufgeschlagen. */
    $gross = round($price * $seats, 2);
    $net = $rate ? round($gross / (1 + $rate / 100), 2) : $gross;
    $tax = round($gross - $net, 2);
    $payDays = (int)($defs['payment_days'] ?? 14);
    $due = gmdate('Y-m-d', time() + $payDays * 86400);
    if ($s['w_date'] && $s['w_date'] > gmdate('Y-m-d') && $s['w_date'] < $due) $due = $s['w_date'];
    /* Deutsches Datumsformat: Auf einer Kundenrechnung hat "2026-10-15" nichts zu suchen. */
    $wDateDe = $s['w_date'] ? date('d.m.Y', strtotime((string)$s['w_date'])) : '';
    $dTitle = $s['w_title'] . ($wDateDe ? ' am ' . $wDateDe : '');
    $docId = uuid(); $token = bin2hex(random_bytes(24));
    /* price_mode ausdruecklich mitgeben: ohne den Wert greift der alte Spalten-Standard
       'netto', und die Workshop-Rechnung stuende als einzige netto da, obwohl alle Preise
       brutto gepflegt und ausgewiesen werden. */
    $p->prepare('insert into documents (id, share_token, doc_type, number, customer_id, status, doc_date, due_date,
        tax_rate, is_small_business, price_mode, intro_text, outro_text, total_net, total_tax, total_gross, created_at)
        values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$docId, $token, 'rechnung', $number, $cid, 'entwurf', gmdate('Y-m-d'), $due,
        $rate, $small ? 1 : 0, 'brutto',
        'vielen Dank für deine Anmeldung zum Workshop „' . $s['w_title'] . '“. Mit Zahlungseingang ist dein Platz verbindlich reserviert.',
        (string)($defs['invoice_outro'] ?? ''), $net, $tax, $gross, now()]);
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
    "danke für deine Anmeldung zum Workshop „" . $s['w_title'] . "“ am " . $wDateDe . "!\n\n" .
    "Hier ist deine Rechnung $number (" . number_format($gross, 2, ',', '.') . " €):\n$portal\n" .
    "Login: deine Postleitzahl ($custZip). Dort kannst du die Rechnung ansehen und als PDF speichern.\n\n" .
    "Mit Zahlungseingang ist dein Platz verbindlich reserviert. Zahlbar bis $due per Überweisung – die Bankverbindung steht auf der Rechnung.\n\n" .
    "Bis bald im Workshop!\n" . ($comp['owner'] ?? '') . "\n" . ($comp['name'] ?? '') .
    ($comp['phone'] ?? '' ? "\n" . $comp['phone'] : '');
  $mailed = sendMailSafe((string)$s['email'], "Rechnung $number – dein Workshop-Platz am " . $wDateDe, $bodyTxt);
  /* Die Rechnung ist ausgestellt, sobald sie hier steht: Nummer ist vergeben, der Kunde hat
     "Rechnung ist unterwegs" gelesen. Ein Mailfehler ist ein Zustellproblem, kein Grund,
     sie als Entwurf zu verstecken - als Entwurf fehlte sie im Portal, in "Offene Rechnungen"
     und im Mahnwesen und wurde schlicht vergessen. Deshalb immer 'versendet'; bei Mailfehler
     eine Wiedervorlage auf heute, damit sie im Dashboard unter "Wiedervorlagen" auftaucht -
     nachsenden geht im Dokument ueber "Per E-Mail senden". */
  $p->prepare('update documents set status = ?, sent_at = ? where id = ?')->execute(['versendet', now(), $docId]);
  $p->prepare('insert into communications (id, customer_id, channel, direction, subject, content, occurred_at, followup_at, created_at)
      values (?,?,?,?,?,?,?,?,?)')
    ->execute([uuid(), $cid, $mailed ? 'email' : 'note', 'out',
      'Workshop-Rechnung ' . $number . ($mailed ? ' automatisch versendet' : ' nicht zugestellt – bitte per E-Mail nachsenden'),
      'Workshop: ' . $dTitle . ' · ' . $seats . ' Platz/Plätze · ' . number_format($gross, 2, ',', '.') . " €\nPortal-Link: $portal" .
      ($mailed ? '' : "\n\nDer automatische Mailversand an " . $s['email'] . " ist fehlgeschlagen. Die Rechnung steht als 'versendet' im System (Nummer vergeben, Kunde informiert) – bitte im Dokument über „Per E-Mail senden“ nachschicken."),
      now(), $mailed ? null : gmdate('Y-m-d'), now()]);
  return ['ok' => true, 'number' => $number, 'mailed' => $mailed, 'portal' => $portal];
}

/* Benachrichtigung an den Inhaber (Firmen-E-Mail aus den Einstellungen) */
function notifyOwner(string $subject, string $body): bool {
  $comp = json_decode(db()->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true);
  $to = trim((string)($comp['email'] ?? ''));
  if ($to === '') return false;
  return sendMailSafe($to, $subject, $body . "\n\n– automatische Benachrichtigung deines Backoffice\n" . baseUrl() . "/admin.html");
}

/* Bestaetigungsmail an den Kunden nach Annahme im Portal. Das Portal verspricht an der
   Stelle "ihr bekommt eine Bestaetigung" - bisher ging nur die Nachricht an Markus.
   Text kommt aus der Vorlage "Angebot angenommen – Bestätigung" (unter Vorlagen
   anpassbar), ohne Vorlage greift ein eingebauter Text. Schlaegt der Versand fehl,
   scheitert die Annahme nicht - Markus bekommt eine Timeline-Notiz und einen Hinweis
   in seiner Benachrichtigung. Rueckgabe: true = raus, false = nicht zustellbar.
   $fall waehlt den Text: 'ok' (Termin fest), 'konflikt' (Termin inzwischen vergeben,
   DJ-Vermittlung anbieten) oder 'abgelaufen' (Annahme nach Ablauf, wird geprueft). */
function acceptConfirmationMail(PDO $p, array $d, string $fall = 'ok'): bool {
  try {
    $cst = $p->prepare('select email, first_name, last_name, company from customers where id = ?');
    $cst->execute([$d['customer_id']]);
    $c = $cst->fetch() ?: [];
    $to = trim((string)($c['email'] ?? ''));
    $comp = json_decode($p->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true);
    $link = baseUrl() . '/portal.html?a=' . $d['share_token'];
    $termin = 'euer Termin'; $datum = 'eurem Wunschtermin';
    if (!empty($d['booking_id'])) {
      $bst = $p->prepare('select event_date, venue_name from bookings where id = ?');
      $bst->execute([$d['booking_id']]);
      if ($b = $bst->fetch()) {
        if (!empty($b['event_date'])) { $datum = date('d.m.Y', strtotime((string)$b['event_date'])); $termin = 'der ' . $datum; }
        if (!empty($b['venue_name'])) $termin .= ($termin === 'euer Termin' ? ' in ' : ' – ') . $b['venue_name'];
      }
    }
    $vorname = trim((string)($c['first_name'] ?? '')) ?: (trim((string)($c['company'] ?? '')) ?: 'zusammen');
    $name = trim((string)($c['company'] ?? '')) ?: trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
    $map = ['{vorname}' => $vorname, '{name}' => $name, '{nummer}' => (string)$d['number'], '{nr}' => (string)$d['number'],
      '{termin}' => $termin, '{datum}' => $datum, '{link}' => $link, '{telefon}' => (string)($comp['phone'] ?? ''),
      '{gueltig}' => !empty($d['valid_until']) ? date('d.m.Y', strtotime((string)$d['valid_until'])) : '–',
      '{betrag}' => number_format((float)$d['total_gross'], 2, ',', '.') . ' €'];
    $texte = [
      'ok' => ['Angebot angenommen – Bestätigung', 'Angebot {nummer} angenommen – danke!',
        "Hallo {vorname},\n\ndanke für euer Vertrauen – ihr habt das Angebot {nummer} angenommen, damit ist {termin} fest bei mir reserviert.\n\nWie es weitergeht: Ihr bekommt von mir noch die Auftragsbestätigung und ggf. eine Abschlagsrechnung.\n\nEuer Angebot findet ihr jederzeit hier – Login ist eure Postleitzahl:\n{link}\n\nBei Fragen: einfach anrufen ({telefon}) oder auf diese Mail antworten.\n\nViele Grüße\nMarkus"],
      'konflikt' => ['Termin nicht mehr verfügbar – DJ-Vermittlung', 'Euer Termin am {datum} – leider inzwischen vergeben',
        "Hallo {vorname},\n\nihr wolltet gerade das Angebot {nummer} annehmen – und genau das tut mir jetzt richtig leid: euer Termin am {datum} ist bei mir in der Zwischenzeit fest gebucht worden. Das Angebot kann ich deshalb nicht mehr erfüllen.\n\nWas ich euch anbieten kann: Über meine Partner-Agentur DJ Bande (Münster) suche ich euch gern persönlich einen passenden DJ raus – kostenlos, ihr müsst nur kurz zustimmen. Das geht direkt hier:\n{link}\n\nOder ruft mich einfach an ({telefon}).\n\nViele Grüße\nMarkus"],
      'abgelaufen' => ['Angebot angenommen – abgelaufen, wird geprüft', 'Angebot {nummer} angenommen – ich prüfe das kurz',
        "Hallo {vorname},\n\ndanke für euer Vertrauen – ihr habt das Angebot {nummer} angenommen. Das Angebot war allerdings schon abgelaufen (gültig bis {gueltig}). Ich prüfe deshalb kurz, ob {termin} noch frei ist und die Preise noch passen, und melde mich schnell bei euch. Bis dahin ist der Termin noch nicht fest zugesagt.\n\nEuer Angebot: {link}\n\nFragen? Einfach anrufen ({telefon}) oder auf diese Mail antworten.\n\nViele Grüße\nMarkus"],
    ];
    [$tplName, $subject, $body] = $texte[$fall] ?? $texte['ok'];
    $tst = $p->prepare('select subject, body from email_templates where name = ? limit 1');
    $tst->execute([$tplName]);
    if ($tpl = $tst->fetch()) { $subject = (string)$tpl['subject']; $body = (string)$tpl['body']; }
    $subject = strtr($subject, $map);
    $body = strtr($body, $map);
    $mailed = $to !== '' && sendMailSafe($to, $subject, $body);
    $p->prepare('insert into communications (id, customer_id, booking_id, channel, direction, subject, content, occurred_at, followup_at, created_at)
        values (?,?,?,?,?,?,?,?,?,?)')
      ->execute([uuid(), $d['customer_id'], $d['booking_id'] ?: null, $mailed ? 'email' : 'note', 'out',
        $mailed ? $subject : 'Bestätigungsmail konnte nicht versendet werden – ' . $d['number'],
        $mailed ? $body : "Der Kunde hat das Angebot " . $d['number'] . " im Portal angenommen, die automatische Bestätigung an " .
          ($to !== '' ? $to : '(keine E-Mail-Adresse hinterlegt)') . " ist aber nicht rausgegangen. Bitte selbst bestätigen.\n\nVorgesehener Text:\n" . $body,
        now(), $mailed ? null : gmdate('Y-m-d'), now()]);
    return $mailed;
  } catch (Throwable $e) { return false; }
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
  /* Im Termin steht Art der Veranstaltung und Kundenname - der Status steckt darin,
     welchen der drei Kalender man gerade sieht, und damit in dessen Farbe. */
  $titel = function ($b) use ($custName) {
    $art = trim((string)($b['event_type'] ?: $b['title'] ?: 'Veranstaltung'));
    $k = $custName($b);
    return $k !== '' ? $art . ' · ' . $k : $art;
  };
  if ($typ === 'anfragen') {
    $q = $p->query("select b.*, c.first_name, c.last_name, c.company, c.phone from bookings b
      join customers c on c.id = b.customer_id where b.status in ('anfrage','angebot')");
    foreach ($q->fetchAll() as $b)
      $ev .= icsEvent($b['id'], $titel($b) . ($b['status'] === 'angebot' ? ' (Angebot offen)' : ' (Anfrage)'),
        $b['event_date'], $b['end_date'], $b['start_time'], $b['end_time'],
        $custName($b) . ($b['phone'] ? ' · ' . $b['phone'] : '') . ($b['guests'] ? ' · ' . $b['guests'] . ' Gäste' : ''),
        trim(($b['venue_name'] ?? '') . ' ' . ($b['venue_address'] ?? '')), true);
    /* Anfragen legen inzwischen selbst eine Veranstaltung an - die steht schon in der
       Schleife darueber. Ohne diesen Filter stand jede Anfrage zweimal im Kalender. */
    $qi = $p->query("select * from inquiries where status = 'neu' and event_date is not null and event_date != ''
      and (customer_id is null or customer_id = '')");
    foreach ($qi->fetchAll() as $i)
      $ev .= icsEvent('inq-' . $i['id'], ($i['event_type'] ?: 'Feier') . ' · ' . $i['name'] . ' (Anfrage)',
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
      $ev .= icsEvent($b['id'], $titel($b),
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
      $ev .= icsEvent($b['id'], $titel($b),
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
    where doc_type not in ('angebot','bestaetigung','lieferschein') and status = 'versendet' and due_date < '$today'")->fetch();
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
    $st = $p->prepare("select * from customers where lower(email) = ? and portal_hash is not null
      order by coalesce(created_at,'') asc limit 1");
    $st->execute([$email]);
    $c = $st->fetch();
    if (!$c || !password_verify($pass, (string)$c['portal_hash'])) { usleep(500000); fail('E-Mail oder Passwort falsch.', 401); }
    out(['token' => custToken($p, $c['id']), 'name' => trim(($c['company'] ?: trim($c['first_name'] . ' ' . $c['last_name']))),
      'partner' => partnerInfoForEmail($p, $email)]);
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
    $st = $p->prepare("select id, portal_hash from customers where lower(email) = ?
      order by (portal_hash is not null) desc, coalesce(created_at,'') asc limit 1");
    $st->execute([$email]);
    $existing = $st->fetch();
    if ($existing && $existing['portal_hash'] !== null) fail('Für diese E-Mail existiert bereits ein Konto – bitte einloggen.', 409);
    [$first, $last] = splitPersonName($name);
    $phone = mb_substr(trim((string)($body['phone'] ?? '')), 0, 60);
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    if ($existing) {
      /* SICHERHEIT: Zu dieser Adresse gibt es bereits einen Kundendatensatz (aus einer
         Anfrage oder von Markus angelegt) - dort hängen Angebote, Rechnungen, Verträge
         und der Veranstaltungsplaner dran. Wer die Adresse kennt, darf sich hier NICHT
         einfach Zugang verschaffen. Stattdessen geht ein Bestätigungslink an genau die
         Adresse; erst wer den anklickt, setzt sein Passwort (portal/account/set_password).
         Vorhandene Stammdaten werden dabei nicht überschrieben - eine gepflegte
         Telefonnummer darf nicht durch ein leeres Formularfeld verloren gehen. */
      /* Bremse: Ohne sie könnte jemand beliebig viele Mails an eine fremde Adresse
         auslösen und dabei jedes Mal einen zuvor verschickten gültigen Link entwerten. */
      if (!empty($existing['portal_invite_expires']) && (int)$existing['portal_invite_expires'] > time() + 2 * 86400 - 900)
        out(['pending' => true,
          'message' => 'Ich habe dir eben schon einen Bestätigungslink geschickt – schau bitte in dein Postfach (auch im Spam-Ordner).'], 202);
      $inv = bin2hex(random_bytes(24));
      $p->prepare('update customers set portal_invite = ?, portal_invite_expires = ?, updated_at = ? where id = ?')
        ->execute([$inv, time() + 2 * 86400, now(), $existing['id']]);
      $mailed = sendMailSafe($email, 'Dein Zugang zum Kundenkonto',
        "Hallo,\n\ndu möchtest dir ein Kundenkonto bei DJ Lauschgift anlegen – zu deiner E-Mail-Adresse gibt es bei mir schon einen Vorgang.\n\n" .
        "Damit niemand Fremdes an deine Unterlagen kommt, bestätige den Zugang bitte über diesen Link (48 Stunden gültig) und vergib dort dein Passwort:\n" .
        baseUrl() . "/portal.html?einladung=$inv\n\n" .
        "Warst du das nicht? Dann ignoriere diese Mail einfach – ohne den Link passiert nichts.\n\nViele Grüße\nMarkus");
      /* Kommt die Mail nicht raus, wartet der Kunde sonst vergeblich - Markus soll das sehen. */
      if (!$mailed)
        notifyOwner('Bestätigungsmail fürs Kundenkonto konnte nicht versendet werden',
          "Adresse: $email\nBitte den Zugang manuell klären.");
      out(['pending' => true,
        'message' => $mailed
          ? 'Fast geschafft: Zu deiner Adresse gibt es schon einen Vorgang bei mir. Ich habe dir gerade einen Bestätigungslink geschickt – damit legst du dein Passwort fest und kommst direkt rein.'
          : 'Zu deiner Adresse gibt es schon einen Vorgang bei mir. Die Bestätigungsmail konnte ich gerade nicht verschicken – melde dich kurz unter 01523 6439373, dann schalte ich dich frei.'], 202);
    } else {
      $custId = uuid();
      /* Anschrift gleich mitnehmen: Ohne PLZ lässt sich später kein Mietvertrag erzeugen
         (die PLZ ist zugleich der Login für die Vertragsansicht) und auf Rechnungen fehlt
         die Adresse. Pflicht ist sie hier nicht - wer nur Unterlagen ansehen will, braucht sie nicht. */
      $p->prepare('insert into customers (id, kind, status, first_name, last_name, email, phone, street, zip, city, portal_hash, source, created_at, updated_at)
        values (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$custId, 'privat', 'kunde', $first, $last, mb_substr($email,0,160), $phone,
          mb_substr(trim((string)($body['street'] ?? '')), 0, 120),
          mb_substr(trim((string)($body['zip'] ?? '')), 0, 10),
          mb_substr(trim((string)($body['city'] ?? '')), 0, 80),
          $hash, 'mietpark', now(), now()]);
    }
    if (!empty($body['partner_interest'])) {
      $kind = in_array($body['partner_kind'] ?? '', ['dj','band','musiker']) ? $body['partner_kind'] : 'dj';
      $st = $p->prepare("select 1 from partners where lower(email)=? limit 1");
      $st->execute([$email]);
      if (!$st->fetchColumn()) {
        $p->prepare('insert into partners (id,name,company,kind,email,phone,status,created_at) values (?,?,?,?,?,?,?,?)')
          ->execute([uuid(), mb_substr($name,0,120), '', $kind, mb_substr($email,0,160), $phone, 'beantragt', now()]);
      }
    }
    out(['token' => custToken($p, $custId), 'name' => $name, 'partner' => partnerInfoForEmail($p, $email)], 201);
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
    $st = $p->prepare("select * from customers where lower(email) = ? and portal_hash is not null
      order by coalesce(created_at,'') asc limit 1");
    $st->execute([$email]);
    $c = $st->fetch();
    /* Gleiche Bremse wie bei der Registrierung: kein Mail-Bombardement auf fremde
       Adressen, und ein eben verschickter gültiger Link wird nicht sofort entwertet. */
    if ($c && !(!empty($c['portal_invite_expires']) && (int)$c['portal_invite_expires'] > time() + 2 * 86400 - 900)) {
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
      $planLockDays = (int)((json_decode((string)$p->query("select value from settings where key='defaults'")->fetchColumn() ?: '{}', true)['plan_lock_days'] ?? 3));
      $bk = $p->prepare("select id, title, event_type, event_date, end_date, status, kind, customer_notes, event_plan
        from bookings where customer_id = ? and status != 'storniert' order by event_date desc");
      $bk->execute([$me['id']]);
      $bookings = array_map(function ($b) use ($planLockDays) {
        $daysUntil = (int)floor((strtotime($b['event_date']) - strtotime(date('Y-m-d'))) / 86400);
        $locked = $daysUntil <= $planLockDays;
        return ['id' => $b['id'], 'title' => $b['title'] ?: $b['event_type'],
        'event_date' => $b['event_date'], 'end_date' => $b['end_date'], 'status' => $b['status'], 'kind' => $b['kind'],
        'notes' => json_decode((string)($b['customer_notes'] ?? ''), true) ?: (object)[],
        'event_plan' => json_decode((string)($b['event_plan'] ?? ''), true) ?: (object)[],
        'plan_locked' => $locked];
      }, $bk->fetchAll());
      /* Termin je Buchung fuer die Beleg-Zuordnung: hat der Kunde mehrere Buchungen,
         war in "Meine Unterlagen" bisher nicht zu erkennen, welcher Beleg zu welchem
         Termin gehoert - alle Belege standen dort in einem ununterscheidbaren Topf. */
      $bd = $p->prepare('select id, event_date from bookings where customer_id = ?');
      $bd->execute([$me['id']]);
      $bookingDates = array_column($bd->fetchAll(), 'event_date', 'id');
      $dq = $p->prepare("select id, share_token, doc_type, number, status, doc_date, total_gross, booking_id
        from documents where customer_id = ? and status != 'entwurf' order by doc_date desc, created_at desc");
      $dq->execute([$me['id']]);
      $docs = [];
      foreach ($dq->fetchAll() as $d) {
        if (empty($d['share_token'])) {
          $d['share_token'] = bin2hex(random_bytes(24));
          $p->prepare('update documents set share_token = ? where id = ?')->execute([$d['share_token'], $d['id']]);
        }
        $docs[] = ['number' => $d['number'], 'doc_type' => $d['doc_type'], 'status' => $d['status'],
          'doc_date' => $d['doc_date'], 'total_gross' => $d['total_gross'], 'token' => $d['share_token'],
          'event_date' => $d['booking_id'] ? ($bookingDates[$d['booking_id']] ?? null) : null];
      }
      $rc = $p->prepare("select r.token, r.status, r.signed_at, b.event_date from rental_contracts r
        join bookings b on b.id = r.booking_id where b.customer_id = ?");
      $rc->execute([$me['id']]);
      $ff = $p->prepare('select id, booking_id, kind, name, size, created_at from customer_files where customer_id = ? order by created_at desc');
      $ff->execute([$me['id']]);
      $fm = $p->prepare("select token, title, status, created_at, submitted_at from forms where customer_id = ? order by created_at desc");
      $fm->execute([$me['id']]);
      out(['customer' => ['name' => trim(($me['company'] ?: trim($me['first_name'] . ' ' . $me['last_name']))), 'email' => $me['email'],
        /* Fuer die Vorbefuellung der Rechnungsadresse im Veranstaltungsplaner - Kundendaten
           und Planer-Rechnungsadresse sind sonst zwei getrennte, sich nie sehende Datentoepfe. */
        'company' => $me['company'], 'first_name' => $me['first_name'], 'last_name' => $me['last_name'],
        'street' => $me['street'], 'zip' => $me['zip'], 'city' => $me['city']],
        'bookings' => $bookings, 'documents' => $docs, 'rentals' => $rc->fetchAll(), 'files' => $ff->fetchAll(), 'forms' => $fm->fetchAll()]);
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
    if (preg_match('#^portal/account/booking/([a-f0-9-]{30,40})/plan-suggest$#', $path, $m) && $method === 'POST') {
      $chk = $p->prepare('select id, title, event_type, event_date, event_plan from bookings where id = ? and customer_id = ?');
      $chk->execute([$m[1], $me['id']]);
      $b = $chk->fetch();
      if (!$b) fail('Termin nicht gefunden.', 404);
      /* Bremse gegen Dauerfeuer: Die Grenze je Eingabe verhindert riesige Einzelwerte,
         aber ohne Obergrenze für die Anzahl der Änderungen könnte jemand die Datenbank
         trotzdem mit tausenden Einträgen fluten. 60 Änderungen pro Stunde reichen für
         jede echte Planung. */
      $rl = $p->prepare('select count(*) from event_plan_changes where booking_id = ? and created_at > ?');
      $rl->execute([$b['id'], gmdate('Y-m-d\TH:i:s\Z', time() - 3600)]);
      if ((int)$rl->fetchColumn() >= 60)
        fail('Das waren gerade sehr viele Änderungen auf einmal. Nimm dir kurz Zeit und trag den Rest später ein – oder ruf mich an, dann machen wir es zusammen.', 429);
      $planLockDays = (int)((json_decode((string)$p->query("select value from settings where key='defaults'")->fetchColumn() ?: '{}', true)['plan_lock_days'] ?? 3));
      $daysUntil = (int)floor((strtotime($b['event_date']) - strtotime(date('Y-m-d'))) / 86400);
      if ($daysUntil <= $planLockDays)
        fail('Der Ablaufplan ist jetzt final - Änderungswünsche bitte direkt klären.', 403);
      $fieldPath = (string)($body['field_path'] ?? '');
      if (!preg_match('/^[a-z_]+(\.[a-z_]+)*$/', $fieldPath)) fail('Ungültiges Feld.');
      $fieldLabel = mb_substr(trim((string)($body['field_label'] ?? $fieldPath)), 0, 120);
      $value = $body['value'] ?? null;
      /* music.playlists und timetable sind die einzigen Array-Felder im Planer - jeder andere
         Pfad muss ein Skalar sein. Ohne diese Prüfung könnte ein falscher Werttyp (z.B. ein
         String statt eines Arrays) unbemerkt in event_plan landen und fmtPlaylists()/
         fmtTimetable() beim Rendern crashen lassen (admin.html wie portal.html). */
      $verworfen = 0;
      $isArrayField = in_array($fieldPath, ['music.playlists', 'timetable'], true);
      if ($isArrayField) {
        if (!is_array($value)) fail('Ungültiger Wert für dieses Feld.');
        /* Obergrenze für die Anzahl der Einträge: Ohne sie kann eine einzige Eingabe
           mit zehntausenden Zeilen die Datenbank aufblähen und das Backoffice-Dashboard
           unbenutzbar machen (jede Zeile wird dort angezeigt). */
        $maxItems = $fieldPath === 'timetable' ? 100 : 20;
        if (count($value) > $maxItems)
          fail($fieldPath === 'timetable'
            ? 'Der Ablaufplan darf höchstens 100 Punkte haben – so viele passen selbst in die längste Feier nicht.'
            : 'Bitte höchstens 20 Playlist-Links.');
        $eingereicht = count($value);
        if ($fieldPath === 'music.playlists') {
          /* url landet später ungeprüft in einem href-Attribut (admin.html/portal.html
             fmtPlaylists) - ohne Schema-Prüfung könnte ein "javascript:"-Link gespeichert
             werden, der beim Anklicken im (privilegierten) Admin-Kontext ausgeführt wird. */
          $value = array_values(array_filter(array_map(function ($it) {
            if (!is_array($it) || empty($it['url']) || !is_string($it['url'])) return null;
            $url = mb_substr((string)$it['url'], 0, 500);
            if (!preg_match('#^https://#i', $url)) return null;
            return ['label' => is_string($it['label'] ?? null) ? mb_substr($it['label'], 0, 120) : 'Playlist',
              'url' => $url];
          }, $value)));
          /* Verworfene Zeilen (kein https) zählen und dem Kunden melden - sonst denkt
             er, alle Links seien angekommen. */
          $verworfen = $eingereicht - count($value);
        } else {
          $value = array_values(array_filter(array_map(function ($it) {
            if (!is_array($it) || !isset($it['label'])) return null;
            return ['time' => is_string($it['time'] ?? null) ? mb_substr($it['time'], 0, 10) : '',
              'label' => mb_substr((string)$it['label'], 0, 300)];
          }, $value)));
        }
      } elseif (is_array($value)) {
        fail('Ungültiger Wert für dieses Feld.');
      } else {
        $value = $value === null ? null : mb_substr((string)$value, 0, 2000);
      }
      $plan = json_decode((string)($b['event_plan'] ?? ''), true) ?: [];
      $current = planPathGet($plan, $fieldPath);
      $isEmpty = $current === null || $current === '' || (is_array($current) && count($current) === 0);
      $newEncoded = is_scalar($value) || $value === null ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
      if ($isEmpty) {
        planPathSet($plan, $fieldPath, $value);
        $p->prepare('update bookings set event_plan = ? where id = ?')
          ->execute([json_encode($plan, JSON_UNESCAPED_UNICODE), $b['id']]);
        $p->prepare('insert into event_plan_changes (id, booking_id, field_path, field_label, old_value, new_value, status, created_at, reviewed_at)
          values (?,?,?,?,?,?,?,?,?)')
          ->execute([uuid(), $b['id'], $fieldPath, $fieldLabel, null, $newEncoded, 'uebernommen', now(), now()]);
        out(['ok' => true, 'applied' => true,
          'hinweis' => $verworfen ? "$verworfen Playlist-Link(s) konnte ich nicht übernehmen – sie müssen mit https:// beginnen." : null], 201);
      }
      $currentEncoded = is_scalar($current) || $current === null ? (string)$current : json_encode($current, JSON_UNESCAPED_UNICODE);
      $p->prepare('insert into event_plan_changes (id, booking_id, field_path, field_label, old_value, new_value, status, created_at)
        values (?,?,?,?,?,?,?,?)')
        ->execute([uuid(), $b['id'], $fieldPath, $fieldLabel, $currentEncoded, $newEncoded, 'offen', now()]);
      out(['ok' => true, 'applied' => false,
        'hinweis' => $verworfen ? "$verworfen Playlist-Link(s) konnte ich nicht übernehmen – sie müssen mit https:// beginnen." : null], 201);
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
    $it = $p->prepare('select pos, description, note, qty, unit, unit_price, discount_value, discount_type, is_header, group_pos from document_items where document_id = ? order by pos');
    $it->execute([$d['id']]);
    $comp = json_decode($p->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true);
    $ups = [];
    if ($d['doc_type'] === 'angebot' && !in_array($d['status'], ['angenommen','abgelehnt','storniert']))
      $ups = $p->query('select id, title, description, price_net from upsells
        where active=1 and show_portal=1 order by sort')->fetchAll();
    out([
      'doc' => array_intersect_key($d, array_flip(['doc_type','number','status','doc_date','valid_until','due_date',
        'tax_rate','is_small_business','intro_text','outro_text','total_net','total_tax','total_gross','deposit_deducted',
        /* rental_from/rental_to standen bisher nicht in der Liste - die Mietzeitraum-Zeile
           im Portal blieb deshalb immer leer. */
        'price_mode','discount_value','discount_type','event_info','rental_from','rental_to'])),
      'customer' => trim(($d['company'] ? $d['company'] : ($d['first_name'].' '.$d['last_name']))),
      /* Rechnungsadresse fuer den Briefkopf im Portal - fehlte bisher komplett, weil
         das SQL oben nur die PLZ (fuer die Zugangspruefung) mitgeladen hat. */
      'customer_address' => (trim((string)$d['street']) !== '' || trim((string)$d['city']) !== '')
        ? ['street' => $d['street'], 'zip' => $d['zip'], 'city' => $d['city']] : null,
      /* Fuer die Erinnerung "leg dir ein Kundenkonto an" im Angebot - ohne Konto
         muss beim naechsten Besuch wieder die PLZ eingetippt werden. */
      'has_account' => !empty($d['portal_hash']),
      /* Sonderfaelle der Annahme, damit das Portal auch beim Neuladen die richtige Karte
         zeigt: 'konflikt' = Termin war inzwischen belegt (Beleg storniert), 'abgelaufen' =
         nach Ablauf angenommen, Markus prueft noch. 'bande' = Vermittlung schon gewuenscht. */
      'annahme' => portalAcceptCase($p, $d['id']),
      'bande' => (bool)$p->query("select 1 from doc_events where document_id = " . $p->quote($d['id']) . " and kind = 'bande' limit 1")->fetchColumn(),
      'today' => date('Y-m-d'),
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
    if ($kind === 'callback') {
      /* Ohne erkennbare Nummer kann Markus nicht zurückrufen - dann lieber gleich nachfragen. */
      if ($phone === '') fail('Bitte eine Rückrufnummer angeben.');
      if (strlen(preg_replace('/\D/', '', $phone)) < 6)
        fail('Diese Nummer sieht nicht vollständig aus – bitte mit Vorwahl angeben, damit ich zurückrufen kann.');
    }
    /* Annehmen/Ablehnen gibt es nur beim Angebot. Eine Rechnung ist keine Entscheidung,
       die der Kunde trifft - und sie ist ab "versendet" auch buchhalterisch festgeschrieben. */
    $bestMail = null;   /* null = keine Bestaetigungsmail faellig, sonst true/false */
    $evKind = $kind; $evMsg = $msg;
    $ownerSubject = null; $ownerExtra = ''; $resp = ['ok' => true];
    if (in_array($kind, ['accept','decline'], true)) {
      if (($d['doc_type'] ?? '') !== 'angebot')
        fail('Das ist eine Rechnung – die kann man nicht annehmen oder ablehnen. Bei Fragen dazu schreib mir einfach über „Frage stellen“.', 409);
      /* Eine getroffene Entscheidung bleibt stehen: Sonst ließe sich eine Ablehnung
         später wieder in eine Annahme (samt neuer Unterschrift) verwandeln. */
      if (in_array($d['status'] ?? '', ['angenommen','abgelehnt','bezahlt'], true))
        fail('Zu diesem Angebot liegt schon eine Rückmeldung vor. Wenn sich etwas geändert hat, schreib mir kurz – wir klären das persönlich.', 409);
    }
    if ($kind === 'accept' && $d['status'] !== 'storniert') {
      $accName = mb_substr(trim((string)($body['name'] ?? '')), 0, 120);
      $sigRaw = (string)($body['signature'] ?? '');
      $sig = ($sigRaw !== '' && decodeDataUrl($sigRaw, ['png'], 400 * 1024)) ? $sigRaw : null;
      /* Erst pruefen, ob Markus den Termin ueberhaupt noch hat: Seit dem Angebot kann eine
         andere Feier fest geworden sein. Dann darf die Annahme den Termin nicht auf
         "gebucht" ziehen - der Kunde bekommt stattdessen ehrlich Bescheid plus das
         Vermittlungsangebot, Markus eine deutliche Warnung. */
      $booking = null; $konflikte = [];
      if (!empty($d['booking_id'])) {
        $bst = $p->prepare('select * from bookings where id = ?');
        $bst->execute([$d['booking_id']]);
        if ($booking = $bst->fetch() ?: null) $konflikte = bookingConflicts($p, $booking);
      }
      if ($konflikte) {
        $p->prepare("update documents set status='storniert', accepted_name=?, updated_at=? where id=?")
          ->execute([$accName ?: null, now(), $d['id']]);
        docAudit($p, $d['id'], 'storniert', $d['number'] . ' – Termin inzwischen vergeben – Annahme nicht möglich (Portal' . ($accName ? ', Kunde: ' . $accName : '') . ')');
        if ($booking && !in_array($booking['status'], ['storniert','abgeschlossen'], true))
          $p->prepare("update bookings set status='storniert', updated_at=? where id=?")->execute([now(), $booking['id']]);
        $evKind = 'konflikt';
        $evMsg = 'Kunde wollte annehmen, der Termin ist aber inzwischen belegt: ' . implode(' · ', $konflikte);
        $bestMail = acceptConfirmationMail($p, $d, 'konflikt');
        $ownerSubject = 'ACHTUNG: Annahme bei belegtem Termin – ' . $d['number'] . ' – Kunde informiert';
        $ownerExtra = "\n\nBelegt durch: " . implode(' · ', $konflikte) . "\nAngebot und Termin sind storniert, der Kunde hat die Vermittlungs-Mail bekommen.";
        $resp['conflict'] = true;
      } else {
        $p->prepare("update documents set status='angenommen', accepted_name=?, accept_signature=?, updated_at=? where id=?")
          ->execute([$accName ?: null, $sig, now(), $d['id']]);
        docAudit($p, $d['id'], 'angenommen', $d['number'] . ' – vom Kunden angenommen' . ($accName ? ' und unterschrieben: ' . $accName : '') . ' (Portal)');
        try { maybeAutoTechCheck($p, $d['id']); } catch (Throwable $e) {}
        /* Nach Ablauf der Gueltigkeit bleibt die Annahme moeglich, aber der Termin wird nicht
           automatisch fest: Preise und Verfuegbarkeit koennen sich geaendert haben, das
           entscheidet Markus von Hand (Wiedervorlage im Dashboard). */
        $abgelaufen = !empty($d['valid_until']) && date('Y-m-d') > substr((string)$d['valid_until'], 0, 10);
        if ($abgelaufen) {
          $gueltig = date('d.m.Y', strtotime((string)$d['valid_until']));
          $evMsg = 'Angebot war abgelaufen (gültig bis ' . $gueltig . ') – manuelle Prüfung nötig' . ($msg !== '' ? "\n" . $msg : '');
          try {
            $p->prepare('insert into communications (id, customer_id, booking_id, channel, direction, subject, content, occurred_at, followup_at, created_at)
                values (?,?,?,?,?,?,?,?,?,?)')
              ->execute([uuid(), $d['customer_id'], $d['booking_id'] ?: null, 'note', 'in',
                'Abgelaufenes Angebot ' . $d['number'] . ' angenommen – bitte prüfen und Gig bestätigen oder absagen',
                'Der Kunde hat das Angebot ' . $d['number'] . ' im Portal angenommen, es war aber nur bis ' . $gueltig . ' gültig. Der Termin steht deshalb noch nicht auf gebucht – bitte Verfügbarkeit und Preise prüfen und den Gig im Beleg bestätigen oder absagen.',
                now(), date('Y-m-d'), now()]);
          } catch (PDOException $e) {}
          $bestMail = acceptConfirmationMail($p, $d, 'abgelaufen');
          $ownerSubject = 'Abgelaufenes Angebot angenommen – bitte prüfen: ' . $d['number'];
          $ownerExtra = "\n\nDas Angebot war nur bis $gueltig gültig. Der Termin steht noch NICHT auf gebucht – bitte im Beleg prüfen und den Gig bestätigen oder absagen (Wiedervorlage im Dashboard).";
          $resp['expired'] = true;
        } else {
          syncBookingFromDoc($p, $d, 'angenommen');
          $bestMail = acceptConfirmationMail($p, $d);
        }
      }
    }
    if ($kind === 'decline' && $d['status'] !== 'storniert') {
      $p->prepare("update documents set status='abgelehnt', updated_at=? where id=?")->execute([now(), $d['id']]);
      syncBookingFromDoc($p, $d, 'abgelehnt');
    }
    $p->prepare('insert into doc_events (id,document_id,kind,message,phone,created_at) values (?,?,?,?,?,?)')
      ->execute([uuid(), $d['id'], $evKind, $evMsg, $phone, now()]);
    $labels = ['accept' => 'Angebot ANGENOMMEN', 'decline' => 'Angebot abgelehnt', 'comment' => 'Frage zum Angebot',
      'callback' => 'Rückruf gewünscht', 'bande' => 'DJ-Vermittlung gewünscht'];
    notifyOwner($ownerSubject ?: ($labels[$kind] . ': ' . $d['number']),
      'Kunde: ' . trim(($d['company'] ?: $d['first_name'] . ' ' . $d['last_name'])) .
      "\nDokument: " . $d['number'] . ' über ' . number_format((float)$d['total_gross'], 2, ',', '.') . ' €' .
      ($msg !== '' ? "\n\nNachricht:\n$msg" : '') . ($phone !== '' ? "\nTelefon: $phone" : '') . $ownerExtra .
      ($bestMail === false ? "\n\nACHTUNG: Die Bestätigungsmail an den Kunden konnte nicht versendet werden – bitte selbst bestätigen (Timeline-Notiz vorhanden)." : '') .
      ($bestMail === true ? "\n\nBestätigungsmail an den Kunden ist raus." : ''));
    out($resp, 201);
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
      /* Ein komplett leer abgeschickter Bogen würde den Link unwiderruflich verbrauchen -
         der Kunde könnte nichts mehr nachtragen und Markus hätte nur Striche. */
      if (!array_filter($answers, fn($a) => $a !== ''))
        fail('Bitte fülle wenigstens eine Frage aus – sonst hilft mir der Bogen leider nicht weiter.');
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
    /* Ist der Vertrag unterschrieben, zählt der Snapshot von damals - der Kunde muss
       genau das wiedersehen, was er unterschrieben hat, auch wenn sich Mietsachen oder
       Bedingungen inzwischen geändert haben. */
    $snap = $r['status'] === 'unterschrieben' && !empty($r['snapshot'])
      ? (json_decode((string)$r['snapshot'], true) ?: null) : null;
    out([
      'status' => $r['status'], 'signed_at' => $r['signed_at'], 'signed_name' => $r['signed_name'],
      'customer' => ['name' => trim($r['company'] ?: trim($r['first_name'].' '.$r['last_name'])),
        'street' => $r['street'], 'zip_city' => trim($r['zip'].' '.$r['city'])],
      'company' => array_intersect_key($comp, array_flip(['name','owner','phone','email','street','zip_city'])),
      'booking' => ['title' => $r['title'],
        'event_date' => $snap['event_date'] ?? $r['event_date'], 'end_date' => $snap['end_date'] ?? $r['end_date'],
        'days' => $snap['days'] ?? rentalDays($r)],
      'items' => $snap['items'] ?? rentalItems($p, $r),
      'terms' => $snap['terms'] ?? (string)($terms['text'] ?? ''),
      'deposit_amount' => $snap ? ($snap['deposit_amount'] ?? null)
        : ($r['deposit_amount'] !== null ? (float)$r['deposit_amount'] : null),
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
      'terms' => (string)($terms['text'] ?? ''),
      'deposit_amount' => $r['deposit_amount'] !== null ? (float)$r['deposit_amount'] : null], JSON_UNESCAPED_UNICODE);
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
      'id' => $w['id'], 'title' => $w['title'], 'description' => $w['description'],
      'long_description' => $w['long_description'] ?? '', 'audience' => $w['audience'] ?? '',
      'event_date' => $w['event_date'], 'start_time' => $w['start_time'], 'end_time' => $w['end_time'],
      'location' => $w['location'], 'price_net' => $w['price_net'],
      'image_url' => $w['image_url'] ?? '', 'image_focal' => $w['image_focal'] ?? '50% 50%',
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
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
      fail('Bitte eine gültige E-Mail-Adresse angeben – sonst kommt die Bestätigung nicht an.');
    /* Dublette zuerst prüfen: Sonst bekommt jemand, der schon angemeldet ist, bei einem
       inzwischen vollen Termin fälschlich "ausgebucht" statt des richtigen Hinweises. */
    $dup = $p->prepare("select count(*) from workshop_signups where workshop_id = ? and email = ? and status in ('angemeldet','warteliste')");
    $dup->execute([$w['id'], $email]);
    if ((int)$dup->fetchColumn()) fail('Mit dieser E-Mail-Adresse bist du für diesen Termin schon angemeldet bzw. auf der Warteliste.', 409);
    /* Nicht stillschweigend kürzen: Eine gekürzte Platzzahl hieße auch eine Rechnung
       über den falschen Betrag. Größere Gruppen bekommen lieber einen eigenen Termin. */
    $seats = max(1, (int)($body['seats'] ?? 1));
    if ($seats > 5)
      fail('Mehr als 5 Plätze kann ich hier nicht auf einmal buchen. Schreib mir kurz – für eine ganze Gruppe machen wir am besten einen eigenen Termin.');
    $free = max(0, (int)$w['capacity'] - (int)$w['booked']);
    $wantWaitlist = !empty($body['waitlist']);
    if ($seats > $free && !$wantWaitlist)
      fail($free ? ($free === 1 ? 'Für diesen Termin ist nur noch 1 Platz frei.' : "Für diesen Termin sind nur noch $free Plätze frei.") : 'Dieser Termin ist leider ausgebucht.', 409);
    $status = ($seats > $free) ? 'warteliste' : 'angemeldet';
    $street = mb_substr(trim((string)($body['street'] ?? '')), 0, 160);
    $zip = mb_substr(trim((string)($body['zip'] ?? '')), 0, 10);
    $city = mb_substr(trim((string)($body['city'] ?? '')), 0, 80);
    /* Auf der Warteliste wird noch nichts berechnet ("bezahlt wird erst, wenn du wirklich
       einen Platz hast") - die Anschrift wird deshalb erst beim Nachrücken gebraucht. */
    if ($status === 'angemeldet' && (float)($w['price_net'] ?? 0) > 0 && ($street === '' || $zip === '' || $city === ''))
      fail('Bitte Anschrift angeben (Straße, PLZ, Ort) – sie wird für die Rechnung benötigt.');
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
      ($inv ? "\nRechnung: " . $inv['number'] . ($inv['mailed'] ? ' (automatisch gemailt)' : ' (Mail NICHT zugestellt – im Dokument per E-Mail nachsenden)') : ''));
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
    /* Umgedrehter Zeitraum ist doppelt gefährlich: Die Verfügbarkeitsprüfung sucht mit
       "event_date <= bis and end_date >= von" - eine Buchung mit vertauschten Daten wird
       dabei NIE gefunden, das Gerät gilt also weiter als frei und kann doppelt vermietet
       werden. Deshalb hier hart abweisen. */
    if ($to < $from) fail('Das Rückgabedatum liegt vor der Abholung – bitte den Zeitraum prüfen.');
    if ($from < gmdate('Y-m-d')) fail('Der Zeitraum liegt in der Vergangenheit – bitte ein Abholdatum ab heute wählen.');
    $cart = is_array($body['items'] ?? null) ? $body['items'] : [];
    if (!$cart) fail('Der Warenkorb ist leer.');
    /* Partnerpreis gilt schon ab dem Antrag (vorläufig) für DJ-/Band-/Musiker-Partner,
       nicht für Techniker-Partner - kein Code-Verfahren mehr, Zuordnung über das Kundenkonto. */
    $partnerInfo = partnerInfoForEmail($p, (string)$me['email']);
    $isPartner = $partnerInfo !== null;
    $partnerPct = $partnerInfo['discount_pct'] ?? 20;
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
      "\n\nGesamt (inkl. MwSt.): " . number_format($total, 2, ',', '.') . " €" . ($isPartner ? "\n(Partnerpreis angewendet" . (($partnerInfo['provisional'] ?? false) ? ', Partner noch nicht final freigeschaltet' : '') . ')' : ''));
    out(['ok' => true, 'booking_id' => $bookingId, 'items' => $lines, 'total' => round($total, 2),
      'partner' => $isPartner, 'partner_provisional' => $isPartner ? ($partnerInfo['provisional'] ?? false) : false], 201);
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
    /* Vorhandenes Bestätigungs-Token behalten, damit ein bereits verschickter Link
       gültig bleibt (sonst entwertet eine zweite Anmeldung die erste Mail). */
    $token = ($row && !empty($row['token'])) ? (string)$row['token'] : bin2hex(random_bytes(16));
    if ($row) {
      /* Wichtig: Eine Abmeldung wird hier NICHT aufgehoben. Wer sich erneut anmeldet,
         muss den Bestätigungslink erneut anklicken - sonst könnte jeder Dritte eine
         fremde, abgemeldete Adresse allein durch Absenden des Formulars reaktivieren. */
      $p->prepare('update newsletter set token=?, name=coalesce(nullif(?,\'\'), name), source=?,
          confirmed_at = case when unsubscribed_at is not null then null else confirmed_at end
        where id=?')
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
  /* Token bewusst großzügig entgegennehmen: Ein im Mailprogramm abgeschnittener Link
     soll die freundliche "Link ungültig"-Seite zeigen und nicht eine nackte
     JSON-Fehlermeldung aus dem Router. */
  if (preg_match('#^portal/newsletter/(confirm|unsubscribe)/([A-Za-z0-9]{1,64})$#', $path, $m) && $method === 'GET') {
    $st = $p->prepare('select * from newsletter where token = ?');
    $st->execute([$m[2]]);
    $row = $st->fetch();
    $ok = false; $title = 'Link ungültig'; $text = 'Dieser Link ist nicht mehr gültig. Melde dich einfach neu an – oder schreib mir kurz.';
    if ($row && $m[1] === 'confirm') {
      /* Auch nach einer früheren Abmeldung wieder aktivieren - die Anmeldung selbst
         hebt die Abmeldung nicht mehr auf, erst dieser Klick tut es. */
      if (!$row['confirmed_at'] || $row['unsubscribed_at']) {
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
      /* Absoluter Pfad: Die Seite liegt unter /api.php/portal/newsletter/…, ein relativer
         Link würde vom Browser dorthin aufgelöst und im Router als 404 landen. */
      '<a href="' . htmlspecialchars(baseUrl() . '/technik.html#workshops') . '" style="color:#3cc8b4;text-decoration:none;font-weight:600">' .
      ($ok && $m[1] === 'confirm' ? 'Zu den Workshop-Terminen →' : 'Zur Technik-Seite →') . '</a></div></body></html>';
    exit;
  }
  /* Kaputte/abgeschnittene Links aus Mailprogrammen landen hier - der Besucher darf
     keine Entwickler-Formulierung sehen, sondern braucht einen Ausweg. */
  fail('Dieser Link stimmt so nicht. Bitte kopiere ihn noch einmal komplett aus meiner E-Mail – oder melde dich kurz bei mir: 01523 6439373.', 404);
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
  /* Transparenz erhalten: PNG und WebP koennen einen Alphakanal haben - Logos ohne
     Hintergrund leben davon. GD wirft ihn beim Speichern weg, wenn man es nicht
     ausdruecklich anders sagt; transparente Flaechen wurden dadurch schwarz. */
  $transparent = ($mime === 'image/png' || $mime === 'image/webp');
  $w = imagesx($img); $h = imagesy($img);
  $scale = min(1, $maxDim / max($w, $h));
  if ($scale < 1) {
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    $resized = imagecreatetruecolor($nw, $nh);
    if ($transparent) {
      imagealphablending($resized, false);
      imagesavealpha($resized, true);
      /* Leere Flaeche zuerst wirklich durchsichtig machen, sonst bleibt Schwarz stehen,
         wo das Bild spaeter nichts hinmalt. */
      imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
    }
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);
    $img = $resized;
  }
  if ($transparent) { imagealphablending($img, false); imagesavealpha($img, true); }
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
/* ===== Medien-Pool: Ordner, Umbenennen, Archiv =====
   Ordner sind echte Unterordner von uploads/. Beim Verschieben und Umbenennen wandern
   die Verweise in der Datenbank mit, damit nirgends ein Loch entsteht. Das Archiv ist
   bewusst anders: dorthin verschobene Dateien lassen ihre Verweise ins Leere laufen -
   genau das ist der Sinn ("weg, aber zurueckholbar"), und davor wird gewarnt. */
const MEDIA_ARCHIV = '_archiv';
function mediaExtOk(string $name): bool {
  return (bool)preg_match('/\.(jpe?g|png|webp|gif|mp4|webm)$/i', $name);
}
/* Dateiname ohne jeden Pfadanteil - alles andere waere ein Loesch- und
   Ueberschreibwerkzeug fuer den ganzen Server. */
function mediaName(string $name): string {
  $n = basename($name);
  if ($n === '' || $n === '.' || $n === '..' || strpbrk($n, "/\\\0") !== false)
    fail('Ungültiger Dateiname.', 400);
  if (!mediaExtOk($n)) fail('Dateityp nicht erlaubt.', 400);
  return $n;
}
/* Ordnernamen bewusst eng: Kleinbuchstaben, Ziffern, Bindestrich. Reicht zum Sortieren
   und kann nirgends ausbrechen. */
function mediaFolderSlug(string $f): string {
  /* Umlaute ausschreiben statt wegwerfen - aus "Oeffentlich" wuerde sonst
     "ffentlich". */
  $f = strtr(trim($f), ['ä'=>'ae','ö'=>'oe','ü'=>'ue','Ä'=>'ae','Ö'=>'oe','Ü'=>'ue','ß'=>'ss']);
  $f = strtolower($f);
  $f = preg_replace('/[^a-z0-9-]+/', '-', $f);
  return trim((string)$f, '-');
}
function mediaFolderDir(string $f, bool $archivErlaubt = false): string {
  if ($f === '' ) return UPLOAD_DIR;
  if ($f === MEDIA_ARCHIV) {
    if (!$archivErlaubt) fail('Das Archiv ist kein normaler Ordner.', 400);
    return UPLOAD_DIR . '/' . MEDIA_ARCHIV;
  }
  if ($f === 'instagram') return UPLOAD_DIR . '/instagram';
  $slug = mediaFolderSlug($f);
  if ($slug === '') fail('Ungültiger Ordnername.', 400);
  return UPLOAD_DIR . '/' . $slug;
}
function mediaPfad(string $folder, string $name, bool $archivErlaubt = false): string {
  $dir = mediaFolderDir($folder, $archivErlaubt);
  $pfad = realpath($dir . '/' . mediaName($name));
  $basis = realpath($dir);
  if ($pfad === false || $basis === false || strpos($pfad, $basis . DIRECTORY_SEPARATOR) !== 0 || !is_file($pfad))
    fail('Datei nicht gefunden.', 404);
  return $pfad;
}
function mediaUrl(string $folder, string $name): string {
  return 'uploads/' . ($folder !== '' ? $folder . '/' : '') . $name;
}
/* Verweise auf eine Datei in der ganzen Datenbank umschreiben. Die URLs sind eindeutig
   genug (Zeitstempel im Namen), deshalb reicht ein einfaches Ersetzen ueber alle
   Textspalten - sonst muesste jedes Feld einzeln gepflegt werden und ginge irgendwann
   vergessen. */
function mediaVerweiseUmschreiben(PDO $p, string $alt, string $neu): int {
  if ($alt === '' || $alt === $neu) return 0;
  $n = 0;
  /* In den JSON-Spalten stehen die Schraegstriche maskiert (json_encode macht aus
     "uploads/x.jpg" ein "uploads\/x.jpg"). Beide Schreibweisen ersetzen, sonst geht
     genau der haeufigste Fall - Bilder in den Seiteninhalten - leer aus. */
  $paare = [[$alt, $neu]];
  $altJ = str_replace('/', '\\/', $alt);
  if ($altJ !== $alt) $paare[] = [$altJ, str_replace('/', '\\/', $neu)];
  /* Das Aenderungsprotokoll bleibt aussen vor: was dort steht, ist ein Protokoll und
     kein Verweis - nachtraeglich umgeschriebene Protokolleintraege waeren wertlos. */
  foreach (TABLES as $t) {
    if ($t === 'doc_audit') continue;
    try {
      $cols = $p->query("PRAGMA table_info(\"$t\")")->fetchAll();
    } catch (PDOException $e) { continue; }
    foreach ($cols as $c) {
      $typ = strtolower((string)($c['type'] ?? ''));
      if ($typ !== '' && strpos($typ, 'text') === false && strpos($typ, 'char') === false) continue;
      $spalte = (string)$c['name'];
      foreach ($paare as [$a, $b]) {
        try {
          $st = $p->prepare("update \"$t\" set \"$spalte\" = replace(\"$spalte\", ?, ?) where \"$spalte\" like ?");
          $st->execute([$a, $b, '%' . $a . '%']);
          $n += $st->rowCount();
        } catch (PDOException $e) {}
      }
    }
  }
  return $n;
}
/* Merkt sich, aus welchem Ordner eine archivierte Datei kam, damit "Wiederherstellen"
   sie genau dorthin zurueckbringt. */
function mediaArchivIndex(?array $neu = null): array {
  $datei = UPLOAD_DIR . '/' . MEDIA_ARCHIV . '/_herkunft.json';
  if ($neu !== null) {
    @file_put_contents($datei, json_encode($neu, JSON_UNESCAPED_UNICODE));
    return $neu;
  }
  if (!is_file($datei)) return [];
  return json_decode((string)@file_get_contents($datei), true) ?: [];
}
function handleUpload(string $name): never {
  if (!currentUser()) fail('Nicht angemeldet.', 401);
  ensureUploadDir(UPLOAD_DIR);
  $raw = file_get_contents('php://input');
  if (!$raw) fail('Datei fehlt.');
  $name = strtolower(preg_replace('/[^a-z0-9._-]+/i', '-', basename($name)));
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $video = in_array($ext, ['mp4','webm']);
  if (!$video && !in_array($ext, ['jpg','jpeg','png','webp','gif','avif']))
    fail('Nur Bilder (jpg, png, webp, gif, avif) oder Videos (mp4, webm) erlaubt.');
  if ($video) {
    if (strlen($raw) > MAX_UPLOAD_VIDEO)
      fail('Das Video ist zu groß (max. 24 MB). Kürzer schneiden oder stärker komprimieren – es muss von jedem Besucher geladen werden.');
    /* Inhalt prüfen, nicht nur die Endung: eine umbenannte Datei darf hier nicht durchrutschen. */
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
    if (!is_string($mime) || !str_starts_with($mime, 'video/'))
      fail('Das ist keine gültige Videodatei.');
    /* Endung und Inhalt muessen zusammenpassen: eine webm-Datei, die mp4 heisst, wird sonst
       mit falschem Typ ausgeliefert und spielt je nach Browser stumm nicht ab. */
    $erwartet = $ext === 'mp4' ? ['video/mp4','video/quicktime'] : ['video/webm','video/x-matroska'];
    if (!in_array($mime, $erwartet, true))
      fail($ext === 'mp4' ? 'Diese Datei ist kein MP4 – bitte als .webm hochladen oder vorher umwandeln.'
                          : 'Diese Datei ist kein WebM – bitte als .mp4 hochladen oder vorher umwandeln.');
  } else {
    if (strlen($raw) > MAX_UPLOAD) fail('Die Datei ist zu groß (max. 8 MB).');
    $info = @getimagesizefromstring($raw);
    if ($info === false) fail('Keine gültige Bilddatei.');
    $raw = processImage($raw);
  }
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
  /* Medienpool: Bilder und Videos im uploads-Ordner (inkl. gespiegelter Instagram-Bilder) – nur angemeldet */
  if ($path === 'media/list' && $method === 'GET') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    ensureUploadDir(UPLOAD_DIR);
    $archiv = ((string)($_GET['archiv'] ?? '')) === '1';
    $files = [];
    $scan = function (string $dir, string $folder) use (&$files) {
      if (!is_dir($dir)) return;
      foreach (glob($dir . '/*.{jpg,jpeg,png,webp,gif,mp4,webm}', GLOB_BRACE) ?: [] as $f)
        if (is_file($f)) {
          $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
          $files[] = ['name' => basename($f), 'url' => mediaUrl($folder, basename($f)),
            'folder' => $folder, 'kind' => in_array($ext, ['mp4','webm']) ? 'video' : 'bild',
            'ext' => $ext, 'size' => filesize($f), 'mtime' => filemtime($f),
            'source' => $folder === 'instagram' ? 'instagram' : 'upload'];
        }
    };
    if ($archiv) {
      $scan(UPLOAD_DIR . '/' . MEDIA_ARCHIV, MEDIA_ARCHIV);
      $herkunft = mediaArchivIndex();
      foreach ($files as &$f) $f['herkunft'] = (string)($herkunft[$f['name']] ?? '');
      unset($f);
    } else {
      $scan(UPLOAD_DIR, '');
      foreach (glob(UPLOAD_DIR . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $b = basename($d);
        if ($b === MEDIA_ARCHIV) continue;
        $scan($d, $b);
      }
    }
    usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    /* Ordnerliste immer mitliefern, auch die leeren - sonst verschwindet ein frisch
       angelegter Ordner sofort wieder aus der Auswahl. */
    $ordner = [];
    foreach (glob(UPLOAD_DIR . '/*', GLOB_ONLYDIR) ?: [] as $d) {
      $b = basename($d);
      if ($b === MEDIA_ARCHIV) continue;
      $ordner[] = $b;
    }
    sort($ordner);
    $arch = glob(UPLOAD_DIR . '/' . MEDIA_ARCHIV . '/*.{jpg,jpeg,png,webp,gif,mp4,webm}', GLOB_BRACE) ?: [];
    out(['files' => $files, 'folders' => $ordner, 'archiv_anzahl' => count($arch)]);
  }
  if ($path === 'media/folder' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $slug = mediaFolderSlug((string)($body['name'] ?? ''));
    if ($slug === '' || $slug === MEDIA_ARCHIV) fail('Bitte einen Ordnernamen aus Buchstaben und Ziffern angeben.', 400);
    $dir = UPLOAD_DIR . '/' . $slug;
    if (is_dir($dir)) fail('Diesen Ordner gibt es schon.', 409);
    if (!@mkdir($dir, 0755, true)) fail('Ordner ließ sich nicht anlegen (Schreibrechte prüfen).', 500);
    out(['ok' => true, 'name' => $slug]);
  }
  if ($path === 'media/folder/delete' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $slug = mediaFolderSlug((string)($body['name'] ?? ''));
    if ($slug === '' || $slug === MEDIA_ARCHIV || $slug === 'instagram') fail('Dieser Ordner lässt sich nicht löschen.', 400);
    $dir = UPLOAD_DIR . '/' . $slug;
    if (!is_dir($dir)) fail('Ordner nicht gefunden.', 404);
    if ((glob($dir . '/*') ?: []) !== []) fail('Der Ordner ist nicht leer – erst die Dateien verschieben oder archivieren.', 409);
    if (!@rmdir($dir)) fail('Ordner ließ sich nicht löschen.', 500);
    out(['ok' => true]);
  }
  /* Verschieben und Umbenennen ziehen die Verweise in der Datenbank mit. */
  if ($path === 'media/move' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $ziel = (string)($body['target'] ?? '');
    $zielDir = mediaFolderDir($ziel);
    if (!is_dir($zielDir) && !@mkdir($zielDir, 0755, true)) fail('Zielordner nicht gefunden.', 404);
    $p = db(); $n = 0;
    foreach ((array)($body['items'] ?? []) as $it) {
      $von = (string)($it['folder'] ?? '');
      $name = mediaName((string)($it['name'] ?? ''));
      if ($von === $ziel) continue;
      $quelle = mediaPfad($von, $name);
      $neu = $zielDir . '/' . $name;
      if (is_file($neu)) fail('Im Zielordner liegt schon eine Datei mit dem Namen „' . $name . '“.', 409);
      if (!@rename($quelle, $neu)) fail('Verschieben fehlgeschlagen.', 500);
      mediaVerweiseUmschreiben($p, mediaUrl($von, $name), mediaUrl($ziel === '' ? '' : basename($zielDir), $name));
      $n++;
    }
    out(['ok' => true, 'anzahl' => $n]);
  }
  if ($path === 'media/rename' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $folder = (string)($body['folder'] ?? '');
    $alt = mediaName((string)($body['name'] ?? ''));
    $ext = strtolower(pathinfo($alt, PATHINFO_EXTENSION));
    /* Endung bleibt, was drin ist, aendert sich ja nicht - der Rest wird auf
       unbedenkliche Zeichen gestutzt. */
    $roh = pathinfo((string)($body['neu'] ?? ''), PATHINFO_FILENAME);
    $roh = strtolower(preg_replace('/[^a-z0-9._-]+/i', '-', (string)$roh));
    $roh = trim($roh, '-.');
    if ($roh === '') fail('Bitte einen Namen angeben.', 400);
    $neuName = $roh . '.' . $ext;
    if ($neuName === $alt) out(['ok' => true, 'name' => $alt]);
    $quelle = mediaPfad($folder, $alt, true);
    $dir = dirname($quelle);
    if (is_file($dir . '/' . $neuName)) fail('Eine Datei mit dem Namen gibt es hier schon.', 409);
    if (!@rename($quelle, $dir . '/' . $neuName)) fail('Umbenennen fehlgeschlagen.', 500);
    if ($folder !== MEDIA_ARCHIV)
      mediaVerweiseUmschreiben(db(), mediaUrl($folder, $alt), mediaUrl($folder, $neuName));
    else {
      $idx = mediaArchivIndex();
      if (isset($idx[$alt])) { $idx[$neuName] = $idx[$alt]; unset($idx[$alt]); mediaArchivIndex($idx); }
    }
    out(['ok' => true, 'name' => $neuName]);
  }
  if ($path === 'media/archive' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $dir = UPLOAD_DIR . '/' . MEDIA_ARCHIV;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail('Archiv ließ sich nicht anlegen.', 500);
    $idx = mediaArchivIndex(); $n = 0;
    foreach ((array)($body['items'] ?? []) as $it) {
      $folder = (string)($it['folder'] ?? '');
      $name = mediaName((string)($it['name'] ?? ''));
      $quelle = mediaPfad($folder, $name);
      $ziel = $dir . '/' . $name;
      /* Namenskollision im Archiv: Zeitstempel davor, damit nichts ueberschrieben wird. */
      if (is_file($ziel)) { $name2 = time() . '-' . $name; $ziel = $dir . '/' . $name2; } else $name2 = $name;
      if (!@rename($quelle, $ziel)) fail('Verschieben ins Archiv fehlgeschlagen.', 500);
      $idx[$name2] = $folder;
      $n++;
    }
    mediaArchivIndex($idx);
    out(['ok' => true, 'anzahl' => $n]);
  }
  if ($path === 'media/restore' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $idx = mediaArchivIndex(); $n = 0;
    foreach ((array)($body['items'] ?? []) as $it) {
      $name = mediaName((string)($it['name'] ?? ''));
      $quelle = mediaPfad(MEDIA_ARCHIV, $name, true);
      $folder = (string)($idx[$name] ?? '');
      $zielDir = UPLOAD_DIR . ($folder !== '' ? '/' . basename($folder) : '');
      if (!is_dir($zielDir) && !@mkdir($zielDir, 0755, true)) $zielDir = UPLOAD_DIR;
      $ziel = $zielDir . '/' . $name;
      if (is_file($ziel)) fail('Am Ursprungsort liegt schon wieder eine Datei mit dem Namen „' . $name . '“.', 409);
      if (!@rename($quelle, $ziel)) fail('Wiederherstellen fehlgeschlagen.', 500);
      unset($idx[$name]);
      $n++;
    }
    mediaArchivIndex($idx);
    out(['ok' => true, 'anzahl' => $n]);
  }
  /* Endgueltig loeschen - nur aus dem Archiv. Aus dem Pool heraus geht ausschliesslich
     der Weg ueber das Archiv, damit ein Fehlgriff nichts kostet. */
  if ($path === 'media/delete' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $idx = mediaArchivIndex(); $n = 0;
    $liste = isset($body['items']) ? (array)$body['items'] : [['name' => (string)($body['name'] ?? '')]];
    foreach ($liste as $it) {
      $name = mediaName((string)($it['name'] ?? ''));
      $pfad = mediaPfad(MEDIA_ARCHIV, $name, true);
      if (!@unlink($pfad)) fail('Datei ließ sich nicht löschen (Schreibrechte prüfen).', 500);
      unset($idx[$name]);
      $n++;
    }
    mediaArchivIndex($idx);
    out(['ok' => true, 'anzahl' => $n]);
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
      db()->prepare('insert into communications (id, customer_id, booking_id, channel, direction, subject, content, occurred_at, created_at)
          values (?,?,?,?,?,?,?,?,?)')
        ->execute([uuid(), (string)$body['customer_id'], !empty($body['booking_id']) ? (string)$body['booking_id'] : null,
          'email', 'out', $subject, $text, now(), now()]);
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
  /* Öffentlich: nur USt.-Satz + Kleinunternehmer-Flag, damit die Technik-Mietseite Preise
     wahlweise brutto (Privatkunde) oder netto (Firmenkunde) anzeigen kann - kein Zugriff
     auf die restlichen (geschützten) Einstellungen. */
  if ($path === 'public/tax-info' && $method === 'GET') {
    $p = db();
    $defs = json_decode((string)$p->query("select value from settings where key='defaults'")->fetchColumn() ?: '{}', true) ?: [];
    $comp = json_decode((string)$p->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true) ?: [];
    out(['tax_rate' => (float)($defs['tax_rate'] ?? 19), 'small_business' => !empty($comp['small_business'])]);
  }
  /* KI-Textassistent: Konfiguration (Basis-URL/Modell/API-Schlüssel) liegt in settings.ai –
     nur angemeldet, der Schlüssel selbst wird nie zurückgegeben. */
  if ($path === 'ai/config' && in_array($method, ['GET', 'POST'])) {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $p = db();
    $cfg = json_decode((string)$p->query("select value from settings where key='ai'")->fetchColumn() ?: '{}', true) ?: [];
    if ($method === 'POST') {
      $provider = in_array((string)($body['provider'] ?? ''), ['claude', 'gemini', 'mistral', 'deepseek'], true)
        ? (string)$body['provider'] : 'openai';
      $cfg['provider'] = $provider;
      $defaults = AI_PROVIDER_DEFAULTS[$provider] ?? AI_PROVIDER_DEFAULTS['openai'];
      $baseUrl = trim((string)($body['base_url'] ?? '')) ?: $defaults['base_url'];
      if (!preg_match('#^https?://#', $baseUrl)) fail('Bitte eine gültige Basis-URL angeben (https://…).', 400);
      $cfg['base_url'] = $baseUrl;
      $cfg['model'] = trim((string)($body['model'] ?? '')) ?: $defaults['model'];
      if (isset($body['style'])) $cfg['style'] = trim((string)$body['style']);
      if (isset($body['workspace_id'])) $cfg['workspace_id'] = trim((string)$body['workspace_id']);
      if (!empty($body['api_key'])) $cfg['api_key'] = trim((string)$body['api_key']);
      $p->prepare("insert into settings (key, value, updated_at) values ('ai', ?, ?)
          on conflict(key) do update set value = excluded.value, updated_at = excluded.updated_at")
        ->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), now()]);
    }
    out(['provider' => $cfg['provider'] ?? 'openai', 'base_url' => $cfg['base_url'] ?? '', 'model' => $cfg['model'] ?? '',
      'style' => $cfg['style'] ?? '', 'workspace_id' => $cfg['workspace_id'] ?? '', 'has_key' => !empty($cfg['api_key'])]);
  }
  /* Automatische Fahrtstrecke: Konfiguration (OpenRouteService-API-Schlüssel) liegt in
     settings.routing - nur angemeldet, der Schlüssel selbst wird nie zurückgegeben. */
  if ($path === 'routing/config' && in_array($method, ['GET', 'POST'])) {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $p = db();
    $cfg = json_decode((string)$p->query("select value from settings where key='routing'")->fetchColumn() ?: '{}', true) ?: [];
    if ($method === 'POST') {
      if (!empty($body['api_key'])) $cfg['api_key'] = trim((string)$body['api_key']);
      $p->prepare("insert into settings (key, value, updated_at) values ('routing', ?, ?)
          on conflict(key) do update set value = excluded.value, updated_at = excluded.updated_at")
        ->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), now()]);
    }
    out(['has_key' => !empty($cfg['api_key'])]);
  }
  /* Fahrtstrecke Lager/Zuhause -> Location automatisch berechnen. Startpunkt ist die
     Firmenadresse aus den Firmendaten, Ziel die übergebene Adresse (i. d. R. die Location
     aus dem Rider). Ergebnis (km, Minuten) füllt das Rider-Formular vor, bleibt dort aber
     ein ganz normales, frei überschreibbares Eingabefeld. */
  if ($path === 'routing/distance' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $dest = trim((string)($body['destination'] ?? ''));
    if ($dest === '') fail('Bitte zuerst eine Adresse für die Location eintragen.', 400);
    $p = db();
    $comp = json_decode((string)$p->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true) ?: [];
    $origin = trim((string)($body['origin'] ?? '')) ?: trim(trim((string)($comp['street'] ?? '')) . ', ' . trim((string)($comp['zip_city'] ?? '')), ' ,');
    if ($origin === '') fail('Bitte zuerst deine Adresse (Lager/Zuhause) unter Einstellungen → Firmendaten hinterlegen.', 400);
    $cfg = json_decode((string)$p->query("select value from settings where key='routing'")->fetchColumn() ?: '{}', true) ?: [];
    $apiKey = trim((string)($cfg['api_key'] ?? ''));
    if ($apiKey === '') fail('Kein Routendienst eingerichtet – bitte in den Einstellungen unter „Automatische Fahrtstrecke" einen OpenRouteService-Schlüssel hinterlegen.', 400);
    $from = orsGeocode($origin, $apiKey);
    if (!$from) fail('Deine Adresse („' . $origin . '") konnte nicht gefunden werden – bitte in den Firmendaten prüfen.', 502);
    $to = orsGeocode($dest, $apiKey);
    if (!$to) fail('Die Adresse der Location („' . $dest . '") konnte nicht gefunden werden.', 502);
    out(orsDrivingRoute($from, $to, $apiKey));
  }
  /* KI-Textassistent: aus Stichpunkten/schlechtem Text einen fertigen Artikeltext machen.
     Nur der angemeldete Admin darf das aufrufen, sonst könnte jeder Besucher am eigenen
     API-Schlüssel des Betreibers mitverdienen. */
  if ($path === 'ai/rewrite' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $text = trim((string)($body['text'] ?? ''));
    if ($text === '') fail('Bitte zuerst einen Text eingeben.', 400);
    if (mb_strlen($text) > 4000) fail('Text ist zu lang (max. 4000 Zeichen).', 400);
    $kind = (string)($body['kind'] ?? 'page');
    $category = trim((string)($body['category'] ?? ''));
    $ai = aiConfigOrFail();
    $p = db();
    $cfg = json_decode((string)$p->query("select value from settings where key='ai'")->fetchColumn() ?: '{}', true) ?: [];
    $style = trim((string)($cfg['style'] ?? '')) ?: (
      'Du schreibst deutsche Texte im Ton von Markus, einem DJ und Verleiher für Veranstaltungstechnik: '
      . 'persönlich, locker, professionell – so, wie er es am Telefon sagen würde. Keine Emojis, keine '
      . 'Worthülsen oder Floskeln, keine Aufzählungen und keine Überschriften – nur zusammenhängender '
      . 'Fließtext. Ein Gedankenstrich wird als Halbgeviertstrich „–" geschrieben, nicht als Bindestrich. '
      . 'Du darfst sparsam **fett** oder *kursiv* markieren (einfaches Markdown, kein HTML).'
    );
    if ($kind === 'equipment') {
      $style .= ' Schreibe aus den gegebenen Stichpunkten/technischen Daten einen kurzen, prägnanten '
        . 'Vermietungs-Artikeltext (ca. 2–4 Sätze) für die Produktseite eines DJ- und Veranstaltungstechnik-'
        . 'Verleihs. Zielgruppe sind Kunden, die dieses Gerät mieten möchten.';
      if ($category !== '') $style .= ' Kategorie: ' . $category . '.';
    } else {
      $style .= ' Mach aus dem gegebenen Stichpunkte-Text bzw. Rohtext einen ansprechenden Seiten- oder '
        . 'Werbetext. Halte dich dabei ungefähr an die Länge des Eingabetexts.';
    }
    $targetLen = (array)($body['target_len'] ?? []);
    if (count($targetLen) === 2 && is_numeric($targetLen[0]) && is_numeric($targetLen[1])) {
      $style .= sprintf(' Ziel-Länge des Textes: etwa %d bis %d Zeichen (nicht strikt, aber orientiere dich daran).',
        (int)$targetLen[0], (int)$targetLen[1]);
    }
    $generated = aiCallLLM($ai['provider'], $ai['apiKey'], $ai['baseUrl'], $ai['model'], $ai['workspaceId'], $style, $text, 800);
    if ($generated === '') fail('KI-Anfrage fehlgeschlagen: keine Antwort erhalten.', 502);
    out(['text' => $generated]);
  }
  /* KI-Textassistent: FAQ-Vorschlag aus den Website-Inhalten generieren. Ein Klick = ein
     Vorschlag (Frage+Antwort); der Admin sieht ihn im FAQ-Editor und kann ihn vor dem
     Speichern noch anpassen. Bereits vorhandene Fragen werden mitgeschickt, damit die KI
     keine Dopplung vorschlägt. */
  if ($path === 'ai/suggest-faq' && $method === 'POST') {
    if (!currentUser()) fail('Nicht angemeldet.', 401);
    $ai = aiConfigOrFail();
    $p = db();
    $existing = $p->query("select question from faq order by sort")->fetchAll(PDO::FETCH_COLUMN);
    $contentRows = $p->query("select key, value from site_content where key in
      ('hero','about','guarantee','tech_hero','rental','tech_teaser')")->fetchAll();
    $siteText = '';
    foreach ($contentRows as $r) {
      $v = json_decode((string)$r['value'], true) ?: [];
      foreach (['subtitle', 'title', 'text'] as $f) if (!empty($v[$f])) $siteText .= $v[$f] . "\n";
    }
    $packages = $p->query("select title, description from packages where public = 1")->fetchAll();
    foreach ($packages as $pk) $siteText .= ($pk['title'] ?? '') . ': ' . ($pk['description'] ?? '') . "\n";
    if (trim($siteText) === '') fail('Es sind noch zu wenig Website-Inhalte gepflegt, um einen FAQ-Vorschlag daraus zu machen.', 400);
    $system = 'Du hilfst, die FAQ-Sektion einer DJ- und Veranstaltungstechnik-Verleih-Website zu ergänzen. '
      . 'Du bekommst Auszüge aus den Website-Inhalten und eine Liste bereits vorhandener FAQ-Fragen. '
      . 'Schlage GENAU EINE neue, sinnvolle Frage samt kurzer, passender Antwort vor, die zu den gegebenen '
      . 'Inhalten passt und sich klar von den vorhandenen Fragen unterscheidet. Antworte AUSSCHLIESSLICH als '
      . 'kompaktes JSON-Objekt ohne Markdown-Codeblock, exakt in der Form {"question":"...","answer":"..."}. '
      . 'Die Antwort im JSON soll im Ton von Markus formuliert sein: persönlich, locker, professionell, '
      . 'keine Floskeln, kein Bindestrich statt Halbgeviertstrich, ca. 1–3 Sätze.';
    $userText = "Website-Inhalte:\n" . mb_substr($siteText, 0, 3000)
      . "\n\nBereits vorhandene FAQ-Fragen (nicht wiederholen):\n" . ($existing ? implode("\n", $existing) : '(noch keine)');
    $raw = aiCallLLM($ai['provider'], $ai['apiKey'], $ai['baseUrl'], $ai['model'], $ai['workspaceId'], $system, $userText, 400);
    $raw = trim(preg_replace('#^```(?:json)?|```$#m', '', $raw));
    $j = json_decode($raw, true);
    if (!is_array($j) || empty($j['question']) || empty($j['answer'])) {
      fail('KI-Anfrage fehlgeschlagen: konnte keinen gültigen FAQ-Vorschlag erzeugen.', 502);
    }
    out(['question' => trim((string)$j['question']), 'answer' => trim((string)$j['answer'])]);
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
