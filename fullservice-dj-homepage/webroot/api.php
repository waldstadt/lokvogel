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
  'form_templates' => ['fields'], 'forms' => ['fields','answers'],
];
const BOOL_COLS = [
  'packages' => ['public'], 'faq' => ['public'], 'locations' => ['public'],
  'upsells' => ['active','show_portal'],
  'equipment' => ['public','rentable'],
  'booking_equipment' => ['out_done','back_done'],
  'communications' => ['followup_done'],
  'documents' => ['is_small_business'],
];
const TABLES = ['settings','site_content','packages','faq','equipment','locations','inquiries',
  'customers','communications','bookings','booking_equipment','documents','document_items','email_templates',
  'doc_events','form_templates','forms','upsells'];
const PK = ['settings' => 'key', 'site_content' => 'key'];   // sonst: id

/* Öffentliche Zugriffe (ohne Login) */
const PUBLIC_READ   = ['site_content','packages','faq','equipment','locations'];
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
  if ($init) { migrate($pdo); $pdo->exec('PRAGMA user_version=3'); }
  else upgrade($pdo);
  return $pdo;
}

/* Schema-Upgrades für bereits vorhandene Datenbanken (idempotent) */
function upgrade(PDO $p): void {
  $v = (int)$p->query('PRAGMA user_version')->fetchColumn();
  if ($v >= 2) return;
  foreach ([
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
  if (!(int)$p->query("select count(*) from form_templates")->fetchColumn()) seedFormTemplates($p);
  try { $p->exec("create table if not exists upsells (id text primary key, sort integer default 0,
    title text not null, description text, price_net real default 0, occasions text,
    active integer default 1, show_portal integer default 1, created_at text)"); } catch (PDOException $e) {}
  if (!(int)$p->query("select count(*) from upsells")->fetchColumn()) seedUpsells($p);
  $p->exec('PRAGMA user_version=3');
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
create table locations (id text primary key, sort integer default 0, name text not null,
  city text, region text, description text, image_url text, website text,
  public integer default 1, created_at text);
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
create table documents (id text primary key, share_token text, doc_type text not null, number text unique not null,
  customer_id text not null references customers(id) on delete restrict,
  booking_id text references bookings(id) on delete set null,
  parent_id text, status text default 'entwurf', doc_date text, valid_until text, due_date text,
  tax_rate real default 19, is_small_business integer default 0, intro_text text, outro_text text,
  total_net real default 0, total_tax real default 0, total_gross real default 0,
  deposit_deducted real default 0, sent_at text, paid_at text, created_at text, updated_at text);
create table email_templates (id text primary key, sort integer default 0, name text not null,
  subject text, body text, created_at text);
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
  pos integer default 1, description text not null, note text, qty real default 1, unit text, unit_price real default 0);
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
      'note' => 'Das gilt für klassische Abendveranstaltungen am Wochenende in der Hauptsaison. Tagsüber, montags bis donnerstags oder im November, Januar, Februar und März kalkuliere ich deutlich günstiger. Fragt einfach mit eurem Termin an.',
    ], JSON_UNESCAPED_UNICODE)],
    ['guarantee', '{"title":"Schon ausgebucht? Ihr steht trotzdem nicht ohne DJ da.","text":"Wenn ich an eurem Termin keine Zeit habe – oder merke, dass ich nicht der richtige DJ für eure Feier bin – wähle ich persönlich bis zu fünf Kollegen aus meinem Partner-Netzwerk aus, die wirklich zu euch passen. Keine anonyme Liste: Ich kenne die Kollegen und ihre Stärken, und ihr bekommt die Vorschläge direkt von mir. Wichtig: Die Preise auf dieser Seite gelten nur für mich selbst – empfohlene Kollegen kalkulieren eigenständig. Und Transparenz gehört dazu: Für eine erfolgreiche Vermittlung erhalte ich eine kleine Provision (Details in den AGB)."}'],
    ['rental', '{"title":"Technik mieten","text":"Von der Anlage für Redenbeiträge bis zu LED-Spots für die Raumdeko – alles gewartet, geprüft und mit kurzer Einweisung bei der Abholung."}'],
    ['tech_hero', '{"subtitle":"Lauschgift Veranstaltungstechnik · Hemer","text":"Profi-Technik aus meinem Lager in Hemer: mieten und selbst abholen – oder mich gleich als Techniker inklusive Licht- und Tontechnik buchen. Ehrliche Beratung, faire Tagespreise, alles geprüft und einsatzbereit."}'],
    ['tech_teaser', '{"title":"Lauschgift Veranstaltungstechnik","text":"Ihr braucht keinen DJ, sondern Technik? Ton- und Lichttechnik zum Mieten direkt aus meinem Lager in Hemer – oder mich als Techniker inklusive Equipment. Das ist ein eigenes Gewerk mit eigener Seite."}'],
    ['contact', '{"title":"Kontakt","phone":"01523 6439373","email":"","address":"Büttmecker Weg 35c, 58675 Hemer","instagram":"","whatsapp":""}'],
    ['theme', '{"preset":"koralle","primary":"#ff6f5b","bg":"#0f1012","font":"Space Grotesk"}'],
    ['seo', '{"title":"DJ Lauschgift – Hochzeits-DJ & Event-DJ | Deutschlandweit","description":"DJ Lauschgift – Markus Jankowski. 23 Jahre Erfahrung für Hochzeiten, Geburtstage & Firmenfeiern. Deutschlandweit buchbar. Technikverleih in Hemer."}'],
    ['legal', json_encode([
      'impressum' => "Angaben gemäß § 5 DDG\n\nMarkus Jankowski\nDJ Lauschgift\nBüttmecker Weg 35c\n58675 Hemer\n\nTelefon: 01523 6439373\nE-Mail: (bitte im Backoffice ergänzen)\n\nUmsatzsteuer: (Steuernummer / USt-IdNr. bitte im Backoffice ergänzen)\n\nVerantwortlich für den Inhalt: Markus Jankowski (Anschrift wie oben)",
      'datenschutz' => "Datenschutzerklärung\n\n1. Verantwortlicher\nMarkus Jankowski, Büttmecker Weg 35c, 58675 Hemer, Telefon 01523 6439373.\n\n2. Hosting\nDiese Website wird bei der ALL-INKL.COM – Neue Medien Münnich (Deutschland) gehostet. Beim Aufruf der Seiten verarbeitet der Hoster technisch notwendige Daten (z. B. IP-Adresse, Zeitpunkt des Abrufs) in Server-Logfiles auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO (sicherer Betrieb der Website).\n\n3. Anfrageformular\nWenn ihr das Anfrageformular nutzt, verarbeite ich die dort eingegebenen Daten (Name, E-Mail, Telefon, Angaben zur Feier, Nachricht) zur Bearbeitung eurer Anfrage und für die Vertragsanbahnung (Art. 6 Abs. 1 lit. b DSGVO). Die Daten werden auf dem eigenen Server dieser Website gespeichert und nicht an Dritte weitergegeben, sofern ihr nicht ausdrücklich eine Vermittlung an Partner-DJs wünscht.\n\n4. DJ-Vermittlung\nWünscht ihr eine Vermittlung an andere DJs, gebe ich die dafür erforderlichen Kontakt- und Veranstaltungsdaten an meine Partner-Agentur DJ Bande (Münster) weiter – ausschließlich mit eurer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO).\n\n5. Eure Rechte\nIhr habt das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit sowie Beschwerde bei einer Aufsichtsbehörde. Meldet euch dafür einfach unter den oben genannten Kontaktdaten.\n\nStand: bitte nach juristischer Prüfung ergänzen.",
      'agb' => "Allgemeine Geschäftsbedingungen (AGB)\n\n1. Geltungsbereich\nDiese AGB gelten für alle Verträge über DJ-Leistungen und Technikvermietung zwischen Markus Jankowski (DJ Lauschgift), Büttmecker Weg 35c, 58675 Hemer, und seinen Auftraggebern.\n\n2. Angebot und Vertragsschluss\nAngebote sind freibleibend. Der Vertrag kommt mit schriftlicher Bestätigung (auch per E-Mail) zustande. Erst mit der Bestätigung ist der Termin verbindlich reserviert.\n\n3. Preise\nDie Vergütung richtet sich nach Auslastung, Arbeitsstunden und technischem Aufwand der jeweiligen Veranstaltung; eine Unterscheidung nach Anlass (z. B. Hochzeit, Geburtstag, Firmenfeier) findet nicht statt. Alle Posten werden im Angebot ausgewiesen.\n\n4. Ausfall des Auftragnehmers und Ersatz (Plan B)\nFällt der Auftragnehmer aus (z. B. durch Krankheit), verpflichtet er sich, sich im Rahmen seiner Möglichkeiten um einen geeigneten Ersatz-DJ aus seinem Kollegen-Netzwerk zu bemühen und diesen dem Auftraggeber unverzüglich vorzuschlagen.\n\nDer Vorschlag ist für den Auftraggeber unverbindlich: Er kann frei entscheiden, ob er den vorgeschlagenen Ersatz-DJ beauftragt oder vom Vertrag zurücktritt. Bei Rücktritt werden bereits geleistete Zahlungen vollständig erstattet; weitergehende Ansprüche bestehen nur bei Vorsatz oder grober Fahrlässigkeit.\n\nEntscheidet sich der Auftraggeber für den Ersatz-DJ, kommt der Vertrag über dessen Leistung direkt mit dem Ersatz-DJ zustande. Wichtig: Der Ersatz-DJ rechnet zu seinen eigenen Preisen ab – der Endpreis kann daher vom ursprünglich vereinbarten Preis abweichen. Auch der Leistungsumfang, insbesondere die mitgeführte Ton- und Lichttechnik, kann vom Angebot des Auftragnehmers abweichen. Bereits an den Auftragnehmer geleistete Zahlungen werden in diesem Fall erstattet bzw. verrechnet.\n\n5. Stornierung durch den Auftraggeber\nSagt der Auftraggeber die Veranstaltung ab, kann kurzfristig in der Regel kein Ersatzauftrag mehr angenommen werden – insbesondere innerhalb von sechs Wochen vor dem Termin ist eine Neubelegung praktisch ausgeschlossen. Daher gilt folgende pauschale Ausfallvergütung (jeweils bezogen auf die vereinbarte Nettovergütung):\n– Absage bis 6 Monate vor dem Termin: 20 %\n– Absage bis 3 Monate vor dem Termin: 40 %\n– Absage bis 6 Wochen vor dem Termin: 60 %\n– Absage weniger als 6 Wochen vor dem Termin: 80 %\n– Absage weniger als 7 Tage vor dem Termin oder Nichtabnahme: 90 %\nErsparte Aufwendungen (z. B. nicht anfallende Fahrtkosten sowie stornierbare Übernachtungskosten) werden angerechnet und von der Ausfallvergütung abgezogen. Dem Auftraggeber bleibt der Nachweis unbenommen, dass kein oder ein wesentlich geringerer Schaden entstanden ist. Gelingt es dem Auftragnehmer, für den Termin einen gleichwertigen Ersatzauftrag anzunehmen, entfällt die Ausfallvergütung bis auf bereits entstandene Kosten. Maßgeblich für die Staffel ist der Zugang der Absage in Textform.\n\nUmbuchung auf einen Ersatztermin: Einigen sich beide Seiten auf einen Ersatztermin, kann der Auftragnehmer anstelle der Ausfallvergütung eine reduzierte Umbuchungspauschale ansetzen; bereits entstandene Kosten (z. B. nicht stornierbare Auslagen) werden zusätzlich berechnet. Die Umbuchung ist eine reine Kulanzregelung des Auftragnehmers: Ein Anspruch auf einen Ersatztermin oder auf eine reduzierte Pauschale besteht nicht. Ob und zu welchen Konditionen umgebucht wird, entscheidet der Auftragnehmer frei im Einzelfall – insbesondere abhängig von seiner Verfügbarkeit am Wunschtermin, davon, ob der ursprüngliche Termin anderweitig belegt werden kann, und vom Buchungswert des Ersatztermins.\n\n6. DJ-Vermittlung über Partner-Agentur\nIst der Auftragnehmer am gewünschten Termin verhindert oder kommt eine Zusammenarbeit aus anderen Gründen nicht zustande, kann er dem Interessenten auf Wunsch bis zu fünf passende DJs vorschlagen. Diese Vermittlung erfolgt über die Partner-Agentur DJ Bande (Münster). Der Auftragnehmer erhält für eine erfolgreich zustande gekommene Vermittlung eine Provision von der Agentur bzw. dem vermittelten DJ. Für den Interessenten entstehen dadurch keine zusätzlichen Kosten; ein Vertrag über die DJ-Leistung kommt in diesem Fall ausschließlich zwischen dem Interessenten und dem vermittelten DJ bzw. der Agentur zustande. Die auf dieser Website genannten Preise und Preisbeispiele gelten ausschließlich für Leistungen des Auftragnehmers selbst; vermittelte DJs kalkulieren ihre Vergütung eigenständig, deren Konditionen können abweichen.\n\n7. Widerrufsrecht\nBei der Buchung von DJ- und Veranstaltungstechnik-Leistungen für einen bestimmten Termin besteht kein Widerrufsrecht. Gemäß § 312g Abs. 2 Nr. 9 BGB ist das Widerrufsrecht ausgeschlossen bei Verträgen zur Erbringung von Dienstleistungen im Zusammenhang mit Freizeitbetätigungen, wenn der Vertrag für die Erbringung einen spezifischen Termin oder Zeitraum vorsieht. Jede Buchung ist daher rechtsverbindlich und verpflichtet zur Abnahme und Bezahlung der Leistung.\n\nSofern eine Buchung im Einzelfall nicht unter § 312g Abs. 2 Nr. 9 BGB fallen sollte, gilt für Verbraucher: Sie haben das Recht, binnen vierzehn Tagen ab Vertragsschluss diesen Vertrag ohne Angabe von Gründen zu widerrufen. Der Widerruf ist zu richten an: Markus Jankowski, Büttmecker Weg 35c, 58675 Hemer (oder per E-Mail an die im Impressum genannte Adresse).\n\n8. Technikvermietung\nMietpreise gelten pro Miettag (24 Stunden); jeder Folgetag wird mit 50 % des Grundpreises berechnet. Der Mieter haftet für Verlust und Beschädigung der Mietsachen ab Übergabe bis zur Rückgabe.\n\n9. Zahlungsbedingungen\nRechnungen sind, sofern nicht anders vereinbart, innerhalb von 14 Tagen ohne Abzug zahlbar. Bei Buchungen kann eine Abschlagszahlung vereinbart werden.\n\n10. Schlussbestimmungen\nEs gilt deutsches Recht. Sollten einzelne Bestimmungen unwirksam sein, bleibt der Vertrag im Übrigen wirksam.\n\nStand: bitte nach juristischer Prüfung ergänzen.",
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

  /* E-Mail-Antwortvorlagen — Platzhalter: {vorname} {name} {datum} {anlass} {ort} */
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
Markus Jankowski – DJ Lauschgift"],
    [2, 'Geburtstag / private Feier – Erstantwort', 'Eure Feier am {datum} – Rückmeldung von DJ Lauschgift',
"Hallo {vorname},

danke für eure Anfrage – klingt nach einer richtig guten Party!

Euer Wunschtermin am {datum} ist bei mir aktuell noch frei. Kleiner Tipp vorab: Falls eure Feier tagsüber oder unter der Woche stattfindet, kann ich deutlich günstiger kalkulieren – das besprechen wir gerne im Detail.

Am einfachsten telefonieren wir einmal kurz (15 Minuten reichen), dann klären wir Location, Gästezahl, Uhrzeiten und eure Musikrichtung – und ihr bekommt direkt danach ein klares Angebot.

Wann passt es euch am besten?

Viele Grüße
Markus Jankowski – DJ Lauschgift"],
    [3, 'Firmenfeier – Erstantwort', 'Ihre Veranstaltung am {datum} – Rückmeldung von DJ Lauschgift',
"Guten Tag {name},

vielen Dank für Ihre Anfrage zu Ihrer Firmenveranstaltung am {datum}.

Der Termin ist bei mir aktuell noch verfügbar. Gerne stimme ich mich kurz mit Ihnen (oder Ihrer Eventplanung) zum Ablauf ab – vom dezenten Empfang über Ton für Redebeiträge bis zum Partyprogramm. Auf dieser Basis erhalten Sie ein transparentes Angebot mit klar ausgewiesenen Posten für Dauer und Technik.

Für Veranstaltungen unter der Woche oder tagsüber kalkuliere ich übrigens spürbar günstiger.

Wann darf ich Sie am besten anrufen?

Mit freundlichen Grüßen
Markus Jankowski – DJ Lauschgift"],
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
    [5, 'Termin belegt – DJ-Vermittlung', 'Euer Termin am {datum} – ich habe trotzdem eine Lösung für euch',
"Hallo {vorname},

vielen Dank für eure Anfrage – und erstmal die weniger gute Nachricht: An eurem Termin am {datum} bin ich leider bereits gebucht.

Aber ich lasse euch nicht hängen – und ich schicke euch auch nicht einfach irgendeine Liste. Ich wähle persönlich bis zu fünf Kollegen aus meinem Partner-Netzwerk aus, die wirklich zu eurer Feier passen: zu eurer Musikrichtung, eurer Location und der Art, wie ihr feiern wollt. Ich kenne die Kollegen und ihre Stärken – und ihr bekommt die Vorschläge direkt von mir.

Damit meine Vorauswahl sitzt, habe ich einen kurzen Online-Fragebogen für euch (keine 5 Minuten):
{fragebogen}

Zur Transparenz: Für eine erfolgreiche Vermittlung erhalte ich eine kleine Provision; für euch entstehen dadurch keine zusätzlichen Kosten, und die Preise vereinbart ihr direkt mit dem jeweiligen DJ. Eure Angaben gebe ich erst nach eurem Einverständnis weiter (das fragt der Bogen mit ab).

Viele Grüße
Markus Jankowski – DJ Lauschgift"],
  ];
  foreach ($tpls as [$s,$n,$sub,$b])
    $ins('email_templates', ['sort'=>$s,'name'=>$n,'subject'=>$sub,'body'=>$b]);

  seedFormTemplates($p);
  seedUpsells($p);

  /* Beispiel-Location als Vorlage — erst nach Bearbeitung auf 'öffentlich' stellen */
  $ins('locations', ['sort'=>1,'name'=>'Beispiel-Location (bitte ersetzen)','city'=>'Musterstadt','region'=>'NRW',
    'description'=>'Kurz beschreiben, warum du dort so gerne auflegst und was das Team besonders gut macht.',
    'website'=>'','public'=>0]);
}

function seedFormTemplates(PDO $p): void {
  $tpls = [
    [1, 'DJ-Vorauswahl für eure Feier',
     "Damit ich euch nicht irgendwelche, sondern wirklich passende DJs vorschlagen kann, beantwortet mir bitte kurz diese Fragen – dauert keine 5 Minuten. Die Vorschläge bekommt ihr danach direkt von mir.",
     [
       ['label'=>'Anlass eurer Feier','type'=>'select','options'=>['Hochzeit','Geburtstag','Firmenfeier','Sonstiges']],
       ['label'=>'Datum der Feier','type'=>'text'],
       ['label'=>'Location & Ort (Name reicht)','type'=>'text'],
       ['label'=>'Ungefähre Gästezahl','type'=>'text'],
       ['label'=>'Welche Musikrichtungen sollen auf jeden Fall laufen?','type'=>'textarea'],
       ['label'=>'Was darf auf KEINEN Fall laufen?','type'=>'textarea'],
       ['label'=>'Wie soll euer DJ auftreten?','type'=>'select','options'=>['Zurückhaltend im Hintergrund','Moderiert & animiert aktiv','Mischung aus beidem','Egal, Hauptsache gute Musik']],
       ['label'=>'Euer ungefähres Budget für den DJ','type'=>'select','options'=>['bis 800 €','800–1.200 €','1.200–1.800 €','über 1.800 €','noch offen']],
       ['label'=>'Braucht ihr zusätzlich Ton für Reden oder eine freie Trauung?','type'=>'select','options'=>['Ja','Nein','Weiß noch nicht']],
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

function handlePortal(string $path, string $method, $body): never {
  $p = db();
  if (preg_match('#^portal/offer/([a-f0-9]+)$#', $path, $m) && $method === 'GET') {
    $d = portalDoc($m[1], (string)($_GET['plz'] ?? ''));
    $it = $p->prepare('select pos, description, note, qty, unit, unit_price from document_items where document_id = ? order by pos');
    $it->execute([$d['id']]);
    $comp = json_decode($p->query("select value from settings where key='company'")->fetchColumn() ?: '{}', true);
    $ups = [];
    if ($d['doc_type'] === 'angebot' && !in_array($d['status'], ['angenommen','abgelehnt','storniert']))
      $ups = $p->query('select id, title, description, price_net from upsells
        where active=1 and show_portal=1 order by sort')->fetchAll();
    out([
      'doc' => array_intersect_key($d, array_flip(['doc_type','number','status','doc_date','valid_until','due_date',
        'tax_rate','is_small_business','intro_text','outro_text','total_net','total_tax','total_gross','deposit_deducted'])),
      'customer' => trim(($d['company'] ? $d['company'] : ($d['first_name'].' '.$d['last_name']))),
      'items' => $it->fetchAll(),
      'company' => array_intersect_key($comp, array_flip(['name','owner','phone','email','street','zip_city','iban','bic','bank','tax_id'])),
      'upsells' => $ups,
    ]);
  }
  if (preg_match('#^portal/offer/([a-f0-9]+)/action$#', $path, $m) && $method === 'POST') {
    $d = portalDoc($m[1], (string)($body['plz'] ?? ''));
    $kind = (string)($body['action'] ?? '');
    if (!in_array($kind, ['accept','decline','comment','callback','bande'])) fail('Unbekannte Aktion.');
    $msg = mb_substr(trim((string)($body['message'] ?? '')), 0, 4000);
    $phone = mb_substr(trim((string)($body['phone'] ?? '')), 0, 60);
    if ($kind === 'accept' && $d['status'] !== 'storniert')
      $p->prepare("update documents set status='angenommen', updated_at=? where id=?")->execute([now(), $d['id']]);
    if ($kind === 'decline' && $d['status'] !== 'storniert')
      $p->prepare("update documents set status='abgelehnt', updated_at=? where id=?")->execute([now(), $d['id']]);
    $p->prepare('insert into doc_events (id,document_id,kind,message,phone,created_at) values (?,?,?,?,?,?)')
      ->execute([uuid(), $d['id'], $kind, $msg, $phone, now()]);
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
      out(['ok' => true], 201);
    }
  }
  fail('Unbekannter Portal-Endpunkt.', 404);
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
  if (str_starts_with($path, 'portal/')) handlePortal($path, $method, $body ?? []);
  if (preg_match('#^rest/(\w+)$#', $path, $m)) {
    $q = $_GET; unset($q['_p']);
    handleRest($m[1], $method, $q, $body, $prefer);
  }
  if (preg_match('#^storage/(.+)$#', $path, $m) && $method === 'POST') handleUpload($m[1]);
  fail('Unbekannter Endpunkt.', 404);
} catch (PDOException $e) {
  fail('Datenbankfehler: ' . $e->getMessage(), 500);
}
