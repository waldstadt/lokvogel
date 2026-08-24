<?php
/**
 * Fullservice DJ Homepage — Backend-API (Supabase-Ersatz für Shared Hosting)
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

/* Spalten, die als JSON bzw. Bool behandelt werden */
const JSON_COLS = [
  'settings' => ['value'], 'site_content' => ['value'],
  'packages' => ['features'], 'customers' => ['tags'],
];
const BOOL_COLS = [
  'packages' => ['public'], 'faq' => ['public'],
  'equipment' => ['public','rentable'],
  'booking_equipment' => ['out_done','back_done'],
  'communications' => ['followup_done'],
  'documents' => ['is_small_business'],
];
const TABLES = ['settings','site_content','packages','faq','equipment','inquiries',
  'customers','communications','bookings','booking_equipment','documents','document_items'];
const PK = ['settings' => 'key', 'site_content' => 'key'];   // sonst: id

/* Öffentliche Zugriffe (ohne Login) */
const PUBLIC_READ   = ['site_content','packages','faq','equipment'];
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
  if ($init) migrate($pdo);
  return $pdo;
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
create table equipment (id text primary key, sort integer default 0, name text not null, slug text,
  category text, description text, image_url text, day_rate real default 0, followup_pct integer default 50,
  qty_total integer default 1, rentable integer default 1, public integer default 1,
  status text default 'aktiv', notes text, created_at text);
create table inquiries (id text primary key, name text not null, email text, phone text,
  event_type text, event_date text, location text, guests text, message text,
  status text default 'neu', customer_id text, created_at text);
create table customers (id text primary key, kind text default 'privat', status text default 'lead',
  first_name text, last_name text, company text, email text, phone text, whatsapp text,
  street text, zip text, city text, source text, tags text default '[]', notes text,
  created_at text, updated_at text);
create table communications (id text primary key,
  customer_id text not null references customers(id) on delete cascade,
  booking_id text, channel text not null, direction text default 'out', subject text, content text,
  occurred_at text, followup_at text, followup_done integer default 0, created_at text);
create table bookings (id text primary key,
  customer_id text not null references customers(id) on delete cascade,
  status text default 'anfrage', kind text default 'dj', event_type text, title text,
  event_date text not null, end_date text, start_time text, end_time text,
  venue_name text, venue_address text, guests integer, fee_net real, notes text,
  created_at text, updated_at text);
create table booking_equipment (id text primary key,
  booking_id text not null references bookings(id) on delete cascade,
  equipment_id text not null references equipment(id) on delete restrict,
  qty integer default 1, price_override real, out_done integer default 0,
  back_done integer default 0, notes text);
create table documents (id text primary key, doc_type text not null, number text unique not null,
  customer_id text not null references customers(id) on delete restrict,
  booking_id text references bookings(id) on delete set null,
  parent_id text, status text default 'entwurf', doc_date text, valid_until text, due_date text,
  tax_rate real default 19, is_small_business integer default 0, intro_text text, outro_text text,
  total_net real default 0, total_tax real default 0, total_gross real default 0,
  deposit_deducted real default 0, sent_at text, paid_at text, created_at text, updated_at text);
create table document_items (id text primary key,
  document_id text not null references documents(id) on delete cascade,
  pos integer default 1, description text not null, qty real default 1, unit text, unit_price real default 0);
SQL);
  seed($p);
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
    ['company', '{"name":"DJ Lauschgift","owner":"Markus Jankowski","street":"Büttmecker Weg 35c","zip_city":"58675 Hemer","phone":"01523 6439373","email":"","website":"https://lauschgift.net","tax_id":"","vat_id":"","iban":"","bic":"","bank":"","small_business":false}'],
    ['numbering', '{"angebot":{"prefix":"AN-","next":1},"rechnung":{"prefix":"RE-","next":1},"year_in_number":true}'],
    ['defaults', '{"tax_rate":19,"payment_days":14,"quote_valid_days":30,"quote_intro":"vielen Dank für Ihre Anfrage. Gerne biete ich Ihnen an:","invoice_outro":"Bitte überweisen Sie den Betrag unter Angabe der Rechnungsnummer auf das unten genannte Konto."}'],
  ] as [$k, $v]) $p->prepare('insert into settings (key,value,updated_at) values (?,?,?)')->execute([$k, $v, now()]);

  foreach ([
    ['hero', '{"title":"DJ Lauschgift","subtitle":"Hochzeits-DJ & Event-DJ | Deutschlandweit","text":"23 Jahre Erfahrung für Hochzeiten, Geburtstage & Firmenfeiern. Buchbar deutschlandweit – von Dortmund über Düsseldorf bis München.","cta":"Jetzt unverbindlich anfragen","image":""}'],
    ['about', '{"title":"Über mich","text":"Nach über 23 Jahren ist jede Hochzeit, jede Gartenparty und jede Firmenfeier eine neue Herausforderung und nicht mit der Party davor vergleichbar – das ist mein persönliches Geheimnis für eine richtig gute Feier. Was mich auszeichnet: meine ruhige, unaufdringliche Art. Ich stelle mich nicht in den Mittelpunkt, sondern lese den Raum und spiele genau den richtigen Song.","image":""}'],
    ['services', '{"title":"Leistungen","text":"Vom Sektempfang bis zum letzten Gast: Ich bringe nicht nur Musik mit, sondern auch Tontechnik für die freie Trauung, dezentes Licht passend zu eurer Location – und einen Plan B für jeden Fall.","image":""}'],
    ['prices', '{"title":"Preise","text":"Eine Feier mit DJ Lauschgift beginnt bei 1.200 €. Eine feste Obergrenze gibt es nicht – Dauer und Technikumfang bestimmen den Preis. Ihr bekommt ein individuelles Angebot mit konkreten Vorschlägen zu Dauer und Equipment, zugeschnitten auf Location, Gästezahl und Ablauf.","from":1200}'],
    ['rental', '{"title":"Technik mieten","text":"Hochwertige Licht- und Tontechnik direkt aus meinem Lager in Hemer – vom System für Redenbeiträge bis zu LED-Spots für die Raumdeko, auf Wunsch mit Aufbau. Preise gelten für einen Miettag (24 Stunden), Folgetage 50 % des Grundpreises."}'],
    ['contact', '{"title":"Kontakt","phone":"01523 6439373","email":"","address":"Büttmecker Weg 35c, 58675 Hemer","instagram":"","whatsapp":""}'],
    ['theme', '{"preset":"dark-gold","primary":"#c9a227","bg":"#0d0d0f","font":"Inter"}'],
    ['seo', '{"title":"DJ Lauschgift – Hochzeits-DJ & Event-DJ | Deutschlandweit","description":"DJ Lauschgift – Markus Jankowski. 23 Jahre Erfahrung für Hochzeiten, Geburtstage & Firmenfeiern. Deutschlandweit buchbar. Technikverleih in Hemer."}'],
  ] as [$k, $v]) $p->prepare('insert into site_content (key,value,updated_at) values (?,?,?)')->execute([$k, $v, now()]);

  $faqs = [
    [1,'Spielst du Musikwünsche?','Ja – Musikwünsche von euch und euren Gästen gehören dazu. Vorab besprechen wir, was auf jeden Fall laufen soll und was gar nicht.'],
    [2,'Wie läuft die Buchung ab?','Anfrage über das Formular oder telefonisch, dann kurzes Kennenlerngespräch, individuelles Angebot – und mit der Bestätigung ist euer Termin fest reserviert.'],
    [3,'Was passiert, wenn du krank wirst?','Für den Notfall gibt es einen Plan B: ein Netzwerk erfahrener Kollegen, die mit meinen Unterlagen und eurer Musikvorbereitung einspringen können.'],
    [4,'Wie lange brauchst du für den Aufbau?','Je nach Umfang der Technik in der Regel 60–120 Minuten. Der Aufbau ist rechtzeitig vor Eintreffen der Gäste abgeschlossen.'],
  ];
  foreach ($faqs as [$s,$q,$a]) $ins('faq', ['sort'=>$s,'question'=>$q,'answer'=>$a,'public'=>1]);

  $packs = [
    [1,'Hochzeit','Vom Sektempfang bis zum letzten Gast',1200,'["Kennenlerngespräch & Musikplanung","Ton für freie Trauung","Dezentes Ambiente-Licht","Plan B / Backup-Technik"]'],
    [2,'Geburtstag & private Feier','Party genau nach eurem Geschmack',900,'["Musik nach euren Wünschen","Professionelle PA (Seeburg Acoustic Line)","Lichtsetup passend zur Location"]'],
    [3,'Firmenfeier','Souverän von Empfang bis Party',1200,'["Dezente Hintergrundmusik & Party","Mikrofon/Ton für Reden","Abstimmung mit Eventplanung"]'],
  ];
  foreach ($packs as [$s,$t,$sub,$pr,$f])
    $ins('packages', ['sort'=>$s,'title'=>$t,'subtitle'=>$sub,'price_from'=>$pr,'price_note'=>'ab','features'=>$f,'public'=>1]);

  $ins('equipment', ['sort'=>1,'name'=>'Nebelmaschine klein','slug'=>'nebelmaschine-klein','category'=>'Effekt',
    'description'=>'Kompakte Nebelmaschine inkl. Fluid – ideal für Partykeller und kleine Räume.','day_rate'=>25,'qty_total'=>1]);
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
      $row['id'] = uuid(); $row['status'] = 'neu'; $row['created_at'] = now();
      $cols = array_keys($row);
      $p->prepare("insert into inquiries (" . implode(',', $cols) . ") values (" .
        implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($row));
      out(null, 201);
    } else fail('Nicht angemeldet.', 401);
  }

  [$where, $args, $order, $embeds] = parseFilters($t, $q);
  $wsql = $where ? ' where ' . implode(' and ', $where) : '';

  switch ($method) {
    case 'GET':
      $st = $p->prepare("select * from \"$t\"$wsql$order"); $st->execute($args);
      $rows = array_map(fn($r) => decodeRow($t, $r), $st->fetchAll());
      out(attachEmbeds($t, $rows, $embeds));

    case 'POST':
      $items = is_array($body) && array_is_list($body) ? $body : [$body];
      $merge = in_array('resolution=merge-duplicates', $prefer);
      $pk = PK[$t] ?? 'id';
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
      if (in_array('updated_at', tableCols($t))) $row['updated_at'] = now();
      if (!$row) fail('Nichts zu ändern.');
      foreach ($row as $c => $v) $row[$c] = encodeVal($t, $c, $v);
      $set = implode(',', array_map(fn($c) => "\"$c\"=?", array_keys($row)));
      $st = $p->prepare("update \"$t\" set $set$wsql");
      $st->execute(array_merge(array_values($row), $args));
      if (in_array('return=representation', $prefer)) {
        $st = $p->prepare("select * from \"$t\"$wsql"); $st->execute($args);
        out(array_map(fn($r) => decodeRow($t, $r), $st->fetchAll()));
      }
      out(null, 204);

    case 'DELETE':
      if (!$where) fail('DELETE ohne Filter verweigert.', 400);
      try { $st = $p->prepare("delete from \"$t\"$wsql"); $st->execute($args); }
      catch (PDOException $e) { fail('Löschen nicht möglich (verknüpfte Daten): ' . $e->getMessage(), 409); }
      out(null, 204);
  }
  fail('Methode nicht unterstützt.', 405);
}

/* ---------- Upload ---------- */
function handleUpload(string $name): never {
  if (!currentUser()) fail('Nicht angemeldet.', 401);
  if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
  $raw = file_get_contents('php://input');
  if (!$raw || strlen($raw) > MAX_UPLOAD) fail('Datei fehlt oder ist zu groß (max. 8 MB).');
  $name = strtolower(preg_replace('/[^a-z0-9._-]+/i', '-', basename($name)));
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg','jpeg','png','webp','gif','avif'])) fail('Nur Bilddateien erlaubt.');
  $info = @getimagesizefromstring($raw);
  if ($info === false) fail('Keine gültige Bilddatei.');
  file_put_contents(UPLOAD_DIR . '/' . $name, $raw);
  out(['url' => 'uploads/' . $name], 201);
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
  if (preg_match('#^rest/(\w+)$#', $path, $m)) {
    $q = $_GET; unset($q['_p']);
    handleRest($m[1], $method, $q, $body, $prefer);
  }
  if (preg_match('#^storage/(.+)$#', $path, $m) && $method === 'POST') handleUpload($m[1]);
  fail('Unbekannter Endpunkt.', 404);
} catch (PDOException $e) {
  fail('Datenbankfehler: ' . $e->getMessage(), 500);
}
