<?php
/**
 * Eigene, serverseitig gerenderte Adresse je Vermiet-Artikel - damit Google jeden
 * Artikel einzeln finden/indexieren kann und Markus einzelne Geräte gezielt
 * verlinken/empfehlen kann (z. B. in Backstage-Beiträgen oder an Kunden). Bisher gab
 * es nur die Kachel + das JS-Detailfenster in mieten.html, ohne eigene URL.
 * Gleiches Prinzip wie backstage.php/campaign-render.php: PHP liest direkt aus der
 * SQLite-Datenbank und liefert vollstaendiges HTML, damit Suchmaschinen-Crawler ohne
 * JavaScript-Ausfuehrung Titel/Beschreibung/Preis sehen.
 *
 * Aufruf: /technik/<slug> (huebsche URL ueber mod_rewrite in .htaccess; funktioniert
 * auch direkt als technik-artikel.php?slug=<slug>, falls mod_rewrite mal nicht greift).
 */
declare(strict_types=1);

function taEsc($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
/* Gleiches leichtgewichtiges Markdown wie rgMd() in backstage.php - die Beschreibung
   wird ueber denselben "Gross bearbeiten"-Editor gepflegt und kann Fett-, Kursiv- und
   Link-Auszeichnung enthalten. */
function taMd(string $s): string {
  $s = taEsc($s);
  $s = preg_replace_callback('/\[([^\]]+)\]\(([^()\s]+)\)/', function ($m) {
    if (!preg_match('#^https?://#i', $m[2])) return $m[0];
    return '<a href="' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
  }, $s);
  $s = preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $s);
  $s = preg_replace('/\*([^*\s][^*]*)\*/', '<i>$1</i>', $s);
  return nl2br($s);
}
function taEuro($v): string { return number_format((float)$v, 2, ',', '.') . ' €'; }

$slug = trim((string)($_GET['slug'] ?? ''), '/');
if ($slug === '') { header('Location: mieten.html', true, 302); exit; }

try {
  $pdo = new PDO('sqlite:' . __DIR__ . '/data/dj.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  header('Location: mieten.html', true, 302);
  exit;
}

$st = $pdo->prepare("select * from equipment where slug = ? and public = 1 and rentable = 1 and status = 'aktiv'");
$st->execute([$slug]);
$e = $st->fetch() ?: null;
if (!$e) { header('Location: mieten.html', true, 302); exit; }

$images = json_decode((string)($e['images'] ?? '[]'), true) ?: [];
if (!$images && !empty($e['image_url'])) $images = [$e['image_url']];
$url = 'https://lauschgift.net/technik/' . rawurlencode($slug);

function taRelated(PDO $pdo, array $ids, string $exceptId): array {
  $ids = array_values(array_filter(array_slice($ids, 0, 5), fn($id) => $id !== $exceptId));
  if (!$ids) return [];
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("select name, slug from equipment where id in ($ph) and public = 1 and rentable = 1 and status = 'aktiv'");
  $st->execute($ids);
  return $st->fetchAll();
}
$addonIds = json_decode((string)($e['addon_ids'] ?? '[]'), true) ?: [];
$fitsIds = json_decode((string)($e['fits_ids'] ?? '[]'), true) ?: [];
$addons = taRelated($pdo, $addonIds, $e['id']);
$fits = taRelated($pdo, $fitsIds, $e['id']);

$ld = [
  '@context' => 'https://schema.org', '@type' => 'Product',
  'name' => (string)$e['name'], 'category' => (string)($e['category'] ?? ''),
  'description' => (string)($e['description'] ?? ''), 'url' => $url,
];
if ($images) $ld['image'] = array_map(fn($i) => (strpos($i, 'http') === 0 ? $i : 'https://lauschgift.net/' . ltrim($i, '/')), $images);
if ($e['day_rate']) {
  $ld['offers'] = ['@type' => 'Offer', 'priceCurrency' => 'EUR', 'price' => number_format((float)$e['day_rate'], 2, '.', ''),
    'availability' => 'https://schema.org/InStock', 'url' => $url,
    'description' => 'Tagesmietpreis, zzgl. Anfahrt'];
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= taEsc($e['name']) ?> mieten<?= $e['category'] ? ' – ' . taEsc($e['category']) : '' ?> | Lauschgift Veranstaltungstechnik, Hemer</title>
<meta name="description" content="<?= taEsc(mb_substr((string)($e['description'] ?? ($e['name'] . ' zur Miete – Lauschgift Veranstaltungstechnik, Hemer.')), 0, 160)) ?>">
<link rel="canonical" href="<?= taEsc($url) ?>">
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<link href="fonts.css" rel="stylesheet">
<link href="kampagne.css" rel="stylesheet">
<style>.ta-wrap{max-width:860px}.ta-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:36px;align-items:start}
@media(max-width:760px){.ta-grid{grid-template-columns:1fr}}
.ta-img{width:100%;border-radius:12px;object-fit:cover;aspect-ratio:4/3;background:var(--card)}
.ta-thumbs{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}.ta-thumbs img{width:64px;height:48px;object-fit:cover;border-radius:6px}
.ta-price{font-size:26px;font-weight:700;margin:14px 0 4px}.ta-price small{font-size:13px;font-weight:400;color:var(--mut)}
.ta-desc{margin-top:14px;line-height:1.6}
.ta-rel{margin-top:36px}.ta-rel h2{font-size:18px;margin-bottom:10px}
.ta-rel-list{display:flex;flex-wrap:wrap;gap:10px}
.ta-rel-list a{border:1px solid var(--line);border-radius:6px;padding:8px 14px;font-size:14px;color:var(--txt)}
.ta-back{display:inline-block;margin-bottom:28px;color:var(--mut);font-size:14px}</style>
</head>
<body>
<div id="app">
<nav><div class="nav-in">
  <a class="logo" href="technik.html">
    <svg viewBox="0 0 32 32" aria-hidden="true"><g fill="var(--acc)"><rect x="3" y="11" width="5" height="10" rx="2.5"/><rect x="10" y="5" width="5" height="22" rx="2.5"/><rect x="17" y="9" width="5" height="14" rx="2.5"/><rect x="24" y="13" width="5" height="6" rx="2.5"/></g></svg>
    <span class="wm">lauschgift<i>.</i></span>
  </a>
  <a href="technik.html#anfrage" class="btn" style="padding:9px 18px;font-size:12px">Anfragen</a>
</div></nav>

<header class="hero" style="min-height:auto;padding:130px 0 30px"><div class="wrap ta-wrap">
  <a class="ta-back" href="mieten.html">&larr; Zum Vermietkatalog</a>
  <div class="ta-grid">
    <div>
      <?php if ($images): ?>
        <img class="ta-img" src="<?= taEsc($images[0]) ?>" alt="<?= taEsc($e['name']) ?>">
        <?php if (count($images) > 1): ?>
          <div class="ta-thumbs"><?php foreach (array_slice($images, 1, 6) as $img): ?><img src="<?= taEsc($img) ?>" alt=""><?php endforeach; ?></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div>
      <?php if (!empty($e['category'])): ?><div class="kicker"><?= taEsc($e['category']) ?></div><?php endif; ?>
      <h1 style="font-size:clamp(26px,4.5vw,38px)"><?= taEsc($e['name']) ?></h1>
      <?php if ($e['day_rate']): ?><div class="ta-price"><?= taEuro($e['day_rate']) ?> <small>/ Tag, zzgl. Anfahrt</small></div><?php endif; ?>
      <?php if (!empty($e['description'])): ?><div class="ta-desc"><?= taMd($e['description']) ?></div><?php endif; ?>
      <div style="margin-top:24px"><a class="btn" href="mieten.html">Zum Vermietkatalog – in den Tourcase legen</a></div>
    </div>
  </div>

  <?php if ($addons): ?>
  <div class="ta-rel"><h2>Passt dazu</h2><div class="ta-rel-list">
    <?php foreach ($addons as $a): ?><a href="technik/<?= taEsc($a['slug']) ?>"><?= taEsc($a['name']) ?></a><?php endforeach; ?>
  </div></div>
  <?php endif; ?>
  <?php if ($fits): ?>
  <div class="ta-rel"><h2>Passend für</h2><div class="ta-rel-list">
    <?php foreach ($fits as $a): ?><a href="technik/<?= taEsc($a['slug']) ?>"><?= taEsc($a['name']) ?></a><?php endforeach; ?>
  </div></div>
  <?php endif; ?>
</div></header>

<footer><div class="wrap">
  <div><a href="technik.html">Zur Technik-Seite</a></div>
</div></footer>
</div>
<script src="theme.js"></script>
<script>if(window.applyCachedTheme)applyCachedTheme('technik');</script>
</body>
</html>
