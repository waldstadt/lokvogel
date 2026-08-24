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
    ['hero', '{"title":"DJ Lauschgift","subtitle":"DJ für Hochzeiten, Geburtstage & Firmenfeiern","text":"Ich bin Markus – seit 23 Jahren DJ für Hochzeiten, Geburtstage und Firmenfeiern. Keine Show um meine Person, kein Programm von der Stange: Ich lese den Raum und spiele das, was eure Gäste auf die Tanzfläche bringt.","cta":"Unverbindlich anfragen","image":""}'],
    ['about', '{"title":"Musik ist mein Ding. Der Mittelpunkt gehört euch.","text":"Nach 23 Jahren hinter den Decks ist jede Feier immer noch anders – und genau das macht es aus. Ich bin kein DJ, der sich selbst inszeniert: Ich lese den Raum, spiele den richtigen Song zur richtigen Zeit und bleibe den ganzen Abend ansprechbar für euch und eure Gäste. Und weil ich ein echter Technik-Mensch bin, stehen Ton und Licht bei mir auf einem Niveau, das man sonst von deutlich größeren Produktionen kennt.","image":""}'],
    ['services', '{"title":"Das bekommt ihr","text":"Vom Sektempfang bis zum letzten Song: Musik, Ton für die freie Trauung, dezentes Licht passend zur Location – und ein Plan B für alle Fälle. Ihr feiert, ich kümmere mich um den Rest.","image":""}'],
    ['prices', json_encode([
      'title' => 'Was kostet das?',
      'text' => 'Ob Hochzeit, Geburtstag oder Firmenfeier, spielt für den Preis keine Rolle – bei mir zahlt niemand einen „Hochzeitsaufschlag“. Ich rechne nach Auslastung, Arbeitsstunden und Technikaufwand. Ihr bekommt ein individuelles Angebot mit klaren Posten, zugeschnitten auf eure Feier.',
      'from' => 1200,
      'examples' => [
        ['title' => 'Hochzeit', 'scope' => 'Sektempfang bis offenes Ende, Ton für die freie Trauung, dezentes Licht', 'price' => 'ca. 1.550 €'],
        ['title' => 'Geburtstag', 'scope' => 'Abendparty ca. 6 Stunden, kompakte Ton- und Lichttechnik', 'price' => 'ca. 1.000 €'],
        ['title' => 'Firmenfeier', 'scope' => 'Empfang, Reden-Ton und Party bis Mitternacht', 'price' => 'ca. 1.350 €'],
      ],
    ], JSON_UNESCAPED_UNICODE)],
    ['guarantee', '{"title":"Schon ausgebucht? Ihr steht trotzdem nicht ohne DJ da.","text":"Wenn ich an eurem Termin keine Zeit habe – oder merke, dass ich nicht der richtige DJ für eure Feier bin – schlage ich euch bis zu fünf Kollegen vor, die wirklich zu euch passen. Das läuft über meine Partner-Agentur DJ Bande aus Münster. Wichtig: Die Preise auf dieser Seite gelten nur für mich selbst – vermittelte Kollegen kalkulieren eigenständig, ihre Konditionen können abweichen. Und Transparenz gehört dazu: Für eine erfolgreiche Vermittlung erhalte ich eine kleine Provision (Details in den AGB)."}'],
    ['rental', '{"title":"Technik mieten – direkt aus Hemer","text":"Profi-Ton und -Licht aus meinem Lager in Hemer – von der Anlage für Redenbeiträge bis zu LED-Spots für die Raumdeko. Auf Wunsch mit Aufbau."}'],
    ['contact', '{"title":"Kontakt","phone":"01523 6439373","email":"","address":"Büttmecker Weg 35c, 58675 Hemer","instagram":"","whatsapp":""}'],
    ['theme', '{"preset":"koralle","primary":"#ff6f5b","bg":"#0f1012","font":"Space Grotesk"}'],
    ['seo', '{"title":"DJ Lauschgift – Hochzeits-DJ & Event-DJ | Deutschlandweit","description":"DJ Lauschgift – Markus Jankowski. 23 Jahre Erfahrung für Hochzeiten, Geburtstage & Firmenfeiern. Deutschlandweit buchbar. Technikverleih in Hemer."}'],
    ['legal', json_encode([
      'impressum' => "Angaben gemäß § 5 DDG\n\nMarkus Jankowski\nDJ Lauschgift\nBüttmecker Weg 35c\n58675 Hemer\n\nTelefon: 01523 6439373\nE-Mail: (bitte im Backoffice ergänzen)\n\nUmsatzsteuer: (Steuernummer / USt-IdNr. bitte im Backoffice ergänzen)\n\nVerantwortlich für den Inhalt: Markus Jankowski (Anschrift wie oben)",
      'datenschutz' => "Datenschutzerklärung\n\n1. Verantwortlicher\nMarkus Jankowski, Büttmecker Weg 35c, 58675 Hemer, Telefon 01523 6439373.\n\n2. Hosting\nDiese Website wird bei der ALL-INKL.COM – Neue Medien Münnich (Deutschland) gehostet. Beim Aufruf der Seiten verarbeitet der Hoster technisch notwendige Daten (z. B. IP-Adresse, Zeitpunkt des Abrufs) in Server-Logfiles auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO (sicherer Betrieb der Website).\n\n3. Anfrageformular\nWenn ihr das Anfrageformular nutzt, verarbeite ich die dort eingegebenen Daten (Name, E-Mail, Telefon, Angaben zur Feier, Nachricht) zur Bearbeitung eurer Anfrage und für die Vertragsanbahnung (Art. 6 Abs. 1 lit. b DSGVO). Die Daten werden auf dem eigenen Server dieser Website gespeichert und nicht an Dritte weitergegeben, sofern ihr nicht ausdrücklich eine Vermittlung an Partner-DJs wünscht.\n\n4. DJ-Vermittlung\nWünscht ihr eine Vermittlung an andere DJs, gebe ich die dafür erforderlichen Kontakt- und Veranstaltungsdaten an meine Partner-Agentur DJ Bande (Münster) weiter – ausschließlich mit eurer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO).\n\n5. Eure Rechte\nIhr habt das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit sowie Beschwerde bei einer Aufsichtsbehörde. Meldet euch dafür einfach unter den oben genannten Kontaktdaten.\n\nStand: bitte nach juristischer Prüfung ergänzen.",
      'agb' => "Allgemeine Geschäftsbedingungen (AGB)\n\n1. Geltungsbereich\nDiese AGB gelten für alle Verträge über DJ-Leistungen und Technikvermietung zwischen Markus Jankowski (DJ Lauschgift), Büttmecker Weg 35c, 58675 Hemer, und seinen Auftraggebern.\n\n2. Angebot und Vertragsschluss\nAngebote sind freibleibend. Der Vertrag kommt mit schriftlicher Bestätigung (auch per E-Mail) zustande. Erst mit der Bestätigung ist der Termin verbindlich reserviert.\n\n3. Preise\nDie Vergütung richtet sich nach Auslastung, Arbeitsstunden und technischem Aufwand der jeweiligen Veranstaltung; eine Unterscheidung nach Anlass (z. B. Hochzeit, Geburtstag, Firmenfeier) findet nicht statt. Alle Posten werden im Angebot ausgewiesen.\n\n4. Ausfall und Ersatz (Plan B)\nBei kurzfristiger Verhinderung (z. B. Krankheit) bemüht sich der Auftragnehmer nach besten Kräften um gleichwertigen Ersatz aus seinem Kollegen-Netzwerk. Gelingt dies nicht, werden bereits geleistete Zahlungen vollständig erstattet; weitergehende Ansprüche bestehen nur bei Vorsatz oder grober Fahrlässigkeit.\n\n5. DJ-Vermittlung über Partner-Agentur\nIst der Auftragnehmer am gewünschten Termin verhindert oder kommt eine Zusammenarbeit aus anderen Gründen nicht zustande, kann er dem Interessenten auf Wunsch bis zu fünf passende DJs vorschlagen. Diese Vermittlung erfolgt über die Partner-Agentur DJ Bande (Münster). Der Auftragnehmer erhält für eine erfolgreich zustande gekommene Vermittlung eine Provision von der Agentur bzw. dem vermittelten DJ. Für den Interessenten entstehen dadurch keine zusätzlichen Kosten; ein Vertrag über die DJ-Leistung kommt in diesem Fall ausschließlich zwischen dem Interessenten und dem vermittelten DJ bzw. der Agentur zustande. Die auf dieser Website genannten Preise und Preisbeispiele gelten ausschließlich für Leistungen des Auftragnehmers selbst; vermittelte DJs kalkulieren ihre Vergütung eigenständig, deren Konditionen können abweichen.\n\n6. Technikvermietung\nMietpreise gelten pro Miettag (24 Stunden); jeder Folgetag wird mit 50 % des Grundpreises berechnet. Der Mieter haftet für Verlust und Beschädigung der Mietsachen ab Übergabe bis zur Rückgabe.\n\n7. Zahlungsbedingungen\nRechnungen sind, sofern nicht anders vereinbart, innerhalb von 14 Tagen ohne Abzug zahlbar. Bei Buchungen kann eine Abschlagszahlung vereinbart werden.\n\n8. Schlussbestimmungen\nEs gilt deutsches Recht. Sollten einzelne Bestimmungen unwirksam sein, bleibt der Vertrag im Übrigen wirksam.\n\nStand: bitte nach juristischer Prüfung ergänzen.",
    ], JSON_UNESCAPED_UNICODE)],
  ] as [$k, $v]) $p->prepare('insert into site_content (key,value,updated_at) values (?,?,?)')->execute([$k, $v, now()]);

  $faqs = [
    [1,'Spielst du Musikwünsche?','Ja, klar – Wünsche von euch und euren Gästen gehören dazu. Vorab besprechen wir, was auf jeden Fall laufen soll und was gar nicht.'],
    [2,'Wie läuft die Buchung ab?','Anfrage über das Formular oder telefonisch, dann ein kurzes Kennenlerngespräch, ein klares Angebot – und mit eurer Bestätigung ist der Termin fest reserviert.'],
    [3,'Was passiert, wenn du krank wirst?','Dafür gibt es den Plan B: ein Netzwerk erfahrener Kollegen, die mit meinen Unterlagen und eurer Musikvorbereitung einspringen können. Eure Feier findet statt.'],
    [4,'Wie lange brauchst du für den Aufbau?','Je nach Technikumfang 60 bis 120 Minuten. Aufgebaut wird, bevor eure Gäste kommen – versprochen.'],
    [5,'Was ist, wenn du an unserem Termin schon ausgebucht bist?','Dann lasse ich euch nicht hängen: Über meine Partner-Agentur DJ Bande aus Münster schlage ich euch bis zu fünf Kollegen vor, die zu eurer Feier passen. Für eine erfolgreiche Vermittlung erhalte ich eine kleine Provision – das steht so auch transparent in den AGB.'],
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
