-- ============================================================================
-- Fullservice DJ Homepage (lauschgift.net) — Supabase/Postgres Schema  v1
-- ============================================================================
-- Module: Website-CMS, Anfragen, CRM/Kunden, Kommunikations-Timeline,
--         Buchungen, Angebote, Rechnungen (inkl. Abschlag/Schluss),
--         Technik-Verleih, Einstellungen.
--
-- Zugriffsmodell (DSGVO-bewusst):
--   * Backoffice-Zugriff nur mit Supabase-Auth-Login (Rolle authenticated).
--   * Die oeffentliche Homepage (anon key) darf NUR Website-Inhalte lesen
--     (site_content, packages, equipment mit public=true, faq) und neue
--     Anfragen (inquiries) EINFUEGEN. Keine Kundendaten oeffentlich.
-- ============================================================================

create extension if not exists "pgcrypto";

-- ----------------------------------------------------------------------------
-- 1) Website-Inhalte (CMS): Key-Value mit JSON, gepflegt im Backoffice
--    Keys z.B.: hero, about, prices_page, contact, theme, seo
-- ----------------------------------------------------------------------------
create table if not exists site_content (
  key text primary key,
  value jsonb not null default '{}'::jsonb,
  updated_at timestamptz not null default now()
);

-- Leistungspakete / Angebote auf der Homepage (und als Angebots-Vorlagen)
create table if not exists packages (
  id uuid primary key default gen_random_uuid(),
  sort int not null default 0,
  title text not null,
  subtitle text,
  description text,
  price_from numeric(10,2),
  price_note text,               -- z.B. "ab", "pauschal", "auf Anfrage"
  features jsonb not null default '[]'::jsonb,   -- Liste von Strings
  public boolean not null default true,
  created_at timestamptz not null default now()
);

-- FAQ auf der Homepage
create table if not exists faq (
  id uuid primary key default gen_random_uuid(),
  sort int not null default 0,
  question text not null,
  answer text not null,
  public boolean not null default true
);

-- ----------------------------------------------------------------------------
-- 2) Technik / Equipment  (dient Homepage-Verleihliste UND Auftrags-Logistik)
-- ----------------------------------------------------------------------------
create table if not exists equipment (
  id uuid primary key default gen_random_uuid(),
  sort int not null default 0,
  name text not null,
  slug text unique,              -- fuer /items/... auf der Homepage
  category text,                 -- PA, Licht, DJ, Nebel, Zubehoer ...
  description text,
  image_url text,
  day_rate numeric(10,2) default 0,      -- Preis pro Miettag (24h)
  followup_pct int not null default 50,  -- Folgetage in % des Tagespreises
  qty_total int not null default 1,      -- Bestand
  rentable boolean not null default true,  -- auf Homepage mietbar anzeigen
  public boolean not null default true,
  status text not null default 'aktiv' check (status in ('aktiv','wartung','inaktiv')),
  notes text,
  created_at timestamptz not null default now()
);

-- ----------------------------------------------------------------------------
-- 3) Anfragen von der Homepage (Formular, roh — werden im Backoffice zu Kunden)
-- ----------------------------------------------------------------------------
create table if not exists inquiries (
  id uuid primary key default gen_random_uuid(),
  name text not null,
  email text,
  phone text,
  event_type text,               -- Hochzeit, Geburtstag, Firmenfeier, Technik-Miete, Sonstiges
  event_date date,
  location text,
  guests text,
  message text,
  status text not null default 'neu' check (status in ('neu','in_bearbeitung','erledigt','archiviert')),
  customer_id uuid,              -- gesetzt, sobald in CRM uebernommen
  created_at timestamptz not null default now()
);

-- ----------------------------------------------------------------------------
-- 4) CRM: Kunden & Kontakte
-- ----------------------------------------------------------------------------
create table if not exists customers (
  id uuid primary key default gen_random_uuid(),
  kind text not null default 'privat' check (kind in ('privat','firma')),
  status text not null default 'lead' check (status in ('lead','kunde','inaktiv')),
  first_name text,
  last_name text,
  company text,
  email text,
  phone text,
  whatsapp text,
  street text,
  zip text,
  city text,
  source text,                   -- Homepage, Empfehlung, Instagram, ...
  tags text[] not null default '{}',
  notes text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

-- ----------------------------------------------------------------------------
-- 5) Kommunikations-Timeline (E-Mail / WhatsApp / Telefon / Notiz / Treffen)
--    v1: manuell protokolliert; API-Anbindungen spaeter.
-- ----------------------------------------------------------------------------
create table if not exists communications (
  id uuid primary key default gen_random_uuid(),
  customer_id uuid not null references customers(id) on delete cascade,
  booking_id uuid,
  channel text not null check (channel in ('email','whatsapp','phone','meeting','note')),
  direction text not null default 'out' check (direction in ('in','out')),
  subject text,
  content text,
  occurred_at timestamptz not null default now(),
  followup_at date,              -- Wiedervorlage / "nachfassen am"
  followup_done boolean not null default false,
  created_at timestamptz not null default now()
);

-- ----------------------------------------------------------------------------
-- 6) Buchungen / Auftraege
-- ----------------------------------------------------------------------------
create table if not exists bookings (
  id uuid primary key default gen_random_uuid(),
  customer_id uuid not null references customers(id) on delete cascade,
  status text not null default 'anfrage'
    check (status in ('anfrage','angebot','gebucht','abgeschlossen','storniert')),
  kind text not null default 'dj' check (kind in ('dj','technik','dj_technik')),
  event_type text,               -- Hochzeit, Geburtstag, Firmenfeier, ...
  title text,                    -- z.B. "Hochzeit Lisa & Tom"
  event_date date not null,
  end_date date,                 -- fuer mehrtaegige Technik-Vermietung
  start_time time,
  end_time time,
  venue_name text,
  venue_address text,
  guests int,
  fee_net numeric(10,2),         -- vereinbarte DJ-Gage netto (ohne Technik)
  notes text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index if not exists idx_bookings_date on bookings(event_date);

-- Technik-Zuordnung zu einem Auftrag (was ist wann wo im Einsatz/verliehen)
create table if not exists booking_equipment (
  id uuid primary key default gen_random_uuid(),
  booking_id uuid not null references bookings(id) on delete cascade,
  equipment_id uuid not null references equipment(id) on delete restrict,
  qty int not null default 1,
  price_override numeric(10,2),  -- abweichend vom Tagessatz, netto gesamt
  out_done boolean not null default false,     -- rausgegeben/aufgebaut
  back_done boolean not null default false,    -- zurueck/abgebaut
  notes text
);
create index if not exists idx_be_booking on booking_equipment(booking_id);

-- ----------------------------------------------------------------------------
-- 7) Dokumente: Angebote & Rechnungen (gemeinsame Struktur)
--    doc_type: angebot | rechnung | abschlag | schluss
--    Nummernkreise verwaltet die App ueber settings (prefix + laufende Nr).
-- ----------------------------------------------------------------------------
create table if not exists documents (
  id uuid primary key default gen_random_uuid(),
  doc_type text not null check (doc_type in ('angebot','rechnung','abschlag','schluss')),
  number text not null unique,               -- z.B. AN-2026-0012 / RE-2026-0034
  customer_id uuid not null references customers(id) on delete restrict,
  booking_id uuid references bookings(id) on delete set null,
  parent_id uuid references documents(id) on delete set null,
    -- Angebot -> daraus erzeugte Rechnung(en); Schlussrechnung -> Abschlaege
  status text not null default 'entwurf'
    check (status in ('entwurf','versendet','angenommen','abgelehnt','bezahlt','ueberfaellig','storniert')),
  doc_date date not null default current_date,
  valid_until date,              -- Angebote
  due_date date,                 -- Rechnungen
  tax_rate numeric(5,2) not null default 19,
  is_small_business boolean not null default false,  -- §19 UStG ohne USt.
  intro_text text,
  outro_text text,
  total_net numeric(12,2) not null default 0,
  total_tax numeric(12,2) not null default 0,
  total_gross numeric(12,2) not null default 0,
  deposit_deducted numeric(12,2) not null default 0, -- bei Schlussrechnung: verrechnete Abschlaege
  sent_at timestamptz,
  paid_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index if not exists idx_documents_customer on documents(customer_id);
create index if not exists idx_documents_booking on documents(booking_id);

create table if not exists document_items (
  id uuid primary key default gen_random_uuid(),
  document_id uuid not null references documents(id) on delete cascade,
  pos int not null default 1,
  description text not null,
  qty numeric(10,2) not null default 1,
  unit text default 'Stk.',
  unit_price numeric(12,2) not null default 0
);
create index if not exists idx_docitems_doc on document_items(document_id);

-- ----------------------------------------------------------------------------
-- 8) Einstellungen (Firmendaten, Nummernkreise, Theme, Bank etc.)
-- ----------------------------------------------------------------------------
create table if not exists settings (
  key text primary key,
  value jsonb not null default '{}'::jsonb,
  updated_at timestamptz not null default now()
);

insert into settings(key,value) values
 ('company','{"name":"DJ Lauschgift","owner":"Markus Jankowski","street":"Büttmecker Weg 35c","zip_city":"58675 Hemer","phone":"01523 6439373","email":"","website":"https://lauschgift.net","tax_id":"","vat_id":"","iban":"","bic":"","bank":"","small_business":false}'),
 ('numbering','{"angebot":{"prefix":"AN-","next":1},"rechnung":{"prefix":"RE-","next":1},"year_in_number":true}'),
 ('defaults','{"tax_rate":19,"payment_days":14,"quote_valid_days":30,"quote_intro":"vielen Dank für Ihre Anfrage. Gerne biete ich Ihnen an:","invoice_outro":"Bitte überweisen Sie den Betrag unter Angabe der Rechnungsnummer auf das unten genannte Konto."}')
on conflict (key) do nothing;

-- ----------------------------------------------------------------------------
-- updated_at-Trigger
-- ----------------------------------------------------------------------------
create or replace function set_updated_at() returns trigger as $$
begin new.updated_at = now(); return new; end;
$$ language plpgsql;

drop trigger if exists t_upd_customers on customers;
create trigger t_upd_customers before update on customers for each row execute function set_updated_at();
drop trigger if exists t_upd_bookings on bookings;
create trigger t_upd_bookings before update on bookings for each row execute function set_updated_at();
drop trigger if exists t_upd_documents on documents;
create trigger t_upd_documents before update on documents for each row execute function set_updated_at();
drop trigger if exists t_upd_site_content on site_content;
create trigger t_upd_site_content before update on site_content for each row execute function set_updated_at();
drop trigger if exists t_upd_settings on settings;
create trigger t_upd_settings before update on settings for each row execute function set_updated_at();

-- ----------------------------------------------------------------------------
-- Row Level Security
-- ----------------------------------------------------------------------------
alter table site_content      enable row level security;
alter table packages          enable row level security;
alter table faq               enable row level security;
alter table equipment         enable row level security;
alter table inquiries         enable row level security;
alter table customers         enable row level security;
alter table communications    enable row level security;
alter table bookings          enable row level security;
alter table booking_equipment enable row level security;
alter table documents         enable row level security;
alter table document_items    enable row level security;
alter table settings          enable row level security;

-- Backoffice: eingeloggte Nutzer duerfen alles
create policy "auth all" on site_content      for all to authenticated using (true) with check (true);
create policy "auth all" on packages          for all to authenticated using (true) with check (true);
create policy "auth all" on faq               for all to authenticated using (true) with check (true);
create policy "auth all" on equipment         for all to authenticated using (true) with check (true);
create policy "auth all" on inquiries         for all to authenticated using (true) with check (true);
create policy "auth all" on customers         for all to authenticated using (true) with check (true);
create policy "auth all" on communications    for all to authenticated using (true) with check (true);
create policy "auth all" on bookings          for all to authenticated using (true) with check (true);
create policy "auth all" on booking_equipment for all to authenticated using (true) with check (true);
create policy "auth all" on documents         for all to authenticated using (true) with check (true);
create policy "auth all" on document_items    for all to authenticated using (true) with check (true);
create policy "auth all" on settings          for all to authenticated using (true) with check (true);

-- Oeffentliche Homepage (anon): nur Inhalte lesen …
create policy "public read" on site_content for select to anon using (true);
create policy "public read" on packages     for select to anon using (public = true);
create policy "public read" on faq          for select to anon using (public = true);
create policy "public read" on equipment    for select to anon using (public = true and status = 'aktiv');
-- … und Anfragen einreichen (kein Lesen!)
create policy "public insert" on inquiries for insert to anon with check (true);

-- ----------------------------------------------------------------------------
-- Storage-Bucket fuer Fotos (im Supabase-Dashboard anlegen oder per SQL):
--   Bucket "media", public read; Upload nur authenticated.
-- ----------------------------------------------------------------------------
insert into storage.buckets (id, name, public)
values ('media','media', true)
on conflict (id) do nothing;

create policy "media public read" on storage.objects
  for select to anon using (bucket_id = 'media');
create policy "media auth write" on storage.objects
  for all to authenticated using (bucket_id = 'media') with check (bucket_id = 'media');

-- ----------------------------------------------------------------------------
-- Start-Inhalte (aus lauschgift.net rekonstruiert — im Backoffice anpassbar)
-- ----------------------------------------------------------------------------
insert into site_content(key,value) values
 ('hero','{"title":"DJ Lauschgift","subtitle":"Hochzeits-DJ & Event-DJ | Deutschlandweit","text":"23 Jahre Erfahrung für Hochzeiten, Geburtstage & Firmenfeiern. Buchbar deutschlandweit – von Dortmund über Düsseldorf bis München.","cta":"Jetzt unverbindlich anfragen","image":""}'),
 ('about','{"title":"Über mich","text":"Nach über 23 Jahren ist jede Hochzeit, jede Gartenparty und jede Firmenfeier eine neue Herausforderung und nicht mit der Party davor vergleichbar – das ist mein persönliches Geheimnis für eine richtig gute Feier. Was mich auszeichnet: meine ruhige, unaufdringliche Art. Ich stelle mich nicht in den Mittelpunkt, sondern lese den Raum und spiele genau den richtigen Song.","image":""}'),
 ('services','{"title":"Leistungen","text":"Vom Sektempfang bis zum letzten Gast: Ich bringe nicht nur Musik mit, sondern auch Tontechnik für die freie Trauung, dezentes Licht passend zu eurer Location – und einen Plan B für jeden Fall.","image":""}'),
 ('prices','{"title":"Preise","text":"Eine Feier mit DJ Lauschgift beginnt bei 1.200 €. Eine feste Obergrenze gibt es nicht – Dauer und Technikumfang bestimmen den Preis. Ihr bekommt ein individuelles Angebot mit konkreten Vorschlägen zu Dauer und Equipment, zugeschnitten auf Location, Gästezahl und Ablauf.","from":1200}'),
 ('rental','{"title":"Technik mieten","text":"Hochwertige Licht- und Tontechnik direkt aus meinem Lager in Hemer – vom System für Redenbeiträge bis zu LED-Spots für die Raumdeko, auf Wunsch mit Aufbau. Preise gelten für einen Miettag (24 Stunden), Folgetage 50 % des Grundpreises."}'),
 ('contact','{"title":"Kontakt","phone":"01523 6439373","email":"","address":"Büttmecker Weg 35c, 58675 Hemer","instagram":"","whatsapp":""}'),
 ('theme','{"preset":"dark-gold","primary":"#c9a227","bg":"#0d0d0f","font":"Inter"}'),
 ('seo','{"title":"DJ Lauschgift – Hochzeits-DJ & Event-DJ | Deutschlandweit","description":"DJ Lauschgift – Markus Jankowski. 23 Jahre Erfahrung für Hochzeiten, Geburtstage & Firmenfeiern. Deutschlandweit buchbar. Technikverleih in Hemer."}')
on conflict (key) do nothing;

insert into faq(sort,question,answer) values
 (1,'Spielst du Musikwünsche?','Ja – Musikwünsche von euch und euren Gästen gehören dazu. Vorab besprechen wir, was auf jeden Fall laufen soll und was gar nicht.'),
 (2,'Wie läuft die Buchung ab?','Anfrage über das Formular oder telefonisch, dann kurzes Kennenlerngespräch, individuelles Angebot – und mit der Bestätigung ist euer Termin fest reserviert.'),
 (3,'Was passiert, wenn du krank wirst?','Für den Notfall gibt es einen Plan B: ein Netzwerk erfahrener Kollegen, die mit meinen Unterlagen und eurer Musikvorbereitung einspringen können.'),
 (4,'Wie lange brauchst du für den Aufbau?','Je nach Umfang der Technik in der Regel 60–120 Minuten. Der Aufbau ist rechtzeitig vor Eintreffen der Gäste abgeschlossen.')
on conflict do nothing;

insert into packages(sort,title,subtitle,price_from,price_note,features) values
 (1,'Hochzeit','Vom Sektempfang bis zum letzten Gast',1200,'ab','["Kennenlerngespräch & Musikplanung","Ton für freie Trauung","Dezentes Ambiente-Licht","Plan B / Backup-Technik"]'),
 (2,'Geburtstag & private Feier','Party genau nach eurem Geschmack',900,'ab','["Musik nach euren Wünschen","Professionelle PA (Seeburg Acoustic Line)","Lichtsetup passend zur Location"]'),
 (3,'Firmenfeier','Souverän von Empfang bis Party',1200,'ab','["Dezente Hintergrundmusik & Party","Mikrofon/Ton für Reden","Abstimmung mit Eventplanung"]')
on conflict do nothing;

insert into equipment(sort,name,slug,category,description,day_rate,qty_total) values
 (1,'Nebelmaschine klein','nebelmaschine-klein','Effekt','Kompakte Nebelmaschine inkl. Fluid – ideal für Partykeller und kleine Räume.',25,1)
on conflict do nothing;
