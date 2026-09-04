<?php
/**
 * Ratgeber (SEO-Inhaltsartikel) - serverseitig gerendert, damit Suchmaschinen den
 * kompletten Text im ersten HTML sehen (gleiches Prinzip wie campaign-render.php für
 * die Aktionsseiten). Anders als dort braucht ein neuer Artikel keine neue Datei -
 * jeder Slug aus der Tabelle "guides" wird über diese eine Datei ausgeliefert.
 *
 * Aufruf:
 *   /ratgeber            -> Liste aller veroeffentlichten Artikel
 *   /ratgeber/<slug>      -> einzelner Artikel
 * (Huebsche URLs ueber mod_rewrite in .htaccess; funktioniert auch direkt als
 * ratgeber.php bzw. ratgeber.php?slug=<slug>, falls mod_rewrite auf dem Hosting
 * mal nicht greift.)
 */
declare(strict_types=1);

function rgEsc($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$slug = trim((string)($_GET['slug'] ?? ''), '/');

try {
  $pdo = new PDO('sqlite:' . __DIR__ . '/data/dj.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  header('Location: index.html', true, 302);
  exit;
}

/* ---------- Einzelartikel ---------- */
if ($slug !== '') {
  $st = $pdo->prepare('select * from guides where slug = ? and published = 1');
  $st->execute([$slug]);
  $g = $st->fetch() ?: null;
  if (!$g) { header('Location: ratgeber', true, 302); exit; }

  $sections = json_decode((string)($g['sections'] ?? '[]'), true) ?: [];
  $faq = json_decode((string)($g['faq'] ?? '[]'), true) ?: [];
  $homeHref = ($g['footer_target'] ?? '') === 'technik' ? 'technik.html' : 'index.html';
  $homeLabel = ($g['footer_target'] ?? '') === 'technik' ? 'Zur Technik-Seite' : 'Zur Hauptseite';
  $url = 'https://lauschgift.net/ratgeber/' . rawurlencode($slug);

  $faqLd = null;
  if ($faq) {
    $faqLd = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(
      fn($f) => ['@type' => 'Question', 'name' => (string)($f['q'] ?? ''),
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string)($f['a'] ?? '')]],
      $faq
    )];
  }
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= rgEsc($g['title'] ?: $g['h1']) ?></title>
<meta name="description" content="<?= rgEsc($g['meta_desc'] ?? '') ?>">
<link rel="canonical" href="<?= rgEsc($url) ?>">
<script type="application/ld+json">
<?= json_encode(['@context' => 'https://schema.org', '@type' => 'Article',
  'headline' => (string)($g['h1'] ?? ''), 'description' => (string)($g['meta_desc'] ?? ''),
  'author' => ['@type' => 'Person', 'name' => 'Markus Jankowski'], 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
<?php if ($faqLd): ?><script type="application/ld+json"><?= json_encode($faqLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>
<link href="fonts.css" rel="stylesheet">
<link href="kampagne.css" rel="stylesheet">
<style>.rg-wrap{max-width:720px}.rg-art section{padding:0 0 40px}.rg-art .lead{font-size:18px;margin-bottom:8px}
.rg-art ul{margin:0 0 16px 20px}.rg-art li{margin-bottom:8px;color:var(--txt)}
.rg-faq h3{font-size:17px;margin:20px 0 6px}.rg-faq p{color:var(--mut)}
.rg-back{display:inline-block;margin-bottom:28px;color:var(--mut);font-size:14px}</style>
</head>
<body>
<div id="app">
<nav><div class="nav-in">
  <a class="logo" href="<?= rgEsc($homeHref) ?>">
    <svg viewBox="0 0 32 32" aria-hidden="true"><g fill="var(--acc)"><rect x="3" y="11" width="5" height="10" rx="2.5"/><rect x="10" y="5" width="5" height="22" rx="2.5"/><rect x="17" y="9" width="5" height="14" rx="2.5"/><rect x="24" y="13" width="5" height="6" rx="2.5"/></g></svg>
    <span class="wm">lauschgift<i>.</i></span>
  </a>
  <a href="<?= rgEsc($g['cta_href'] ?: ($homeHref . '#anfrage')) ?>" class="btn" style="padding:9px 18px;font-size:12px">Anfragen</a>
</div></nav>

<header class="hero" style="min-height:auto;padding:130px 0 20px"><div class="wrap rg-wrap">
  <a class="rg-back" href="ratgeber">&larr; Alle Ratgeber-Artikel</a>
  <?php if (!empty($g['kicker'])): ?><div class="kicker"><?= rgEsc($g['kicker']) ?></div><?php endif; ?>
  <h1 style="font-size:clamp(28px,5vw,44px)"><?= rgEsc($g['h1'] ?? '') ?></h1>
  <?php if (!empty($g['intro'])): ?><p class="sub lead"><?= rgEsc($g['intro']) ?></p><?php endif; ?>
</div></header>

<div class="wrap rg-wrap rg-art">
<?php foreach ($sections as $s): ?>
  <section>
    <?php if (!empty($s['heading'])): ?><h2 style="font-size:22px"><?= rgEsc($s['heading']) ?></h2><?php endif; ?>
    <?php if (!empty($s['text'])): ?><p class="lead" style="font-size:16px"><?= nl2br(rgEsc($s['text'])) ?></p><?php endif; ?>
    <?php if (!empty($s['items']) && is_array($s['items'])): ?>
      <ul><?php foreach ($s['items'] as $it): ?><li><?= rgEsc($it) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<?php if ($faq): ?>
  <section class="rg-faq">
    <h2 style="font-size:22px">Häufige Fragen</h2>
    <?php foreach ($faq as $f): ?>
      <h3><?= rgEsc($f['q'] ?? '') ?></h3>
      <p><?= rgEsc($f['a'] ?? '') ?></p>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<?php if (!empty($g['cta_label'])): ?>
  <section style="padding-top:0"><a class="btn" href="<?= rgEsc($g['cta_href'] ?: $homeHref) ?>"><?= rgEsc($g['cta_label']) ?></a></section>
<?php endif; ?>
</div>

<footer><div class="wrap">
  <div><a href="<?= rgEsc($homeHref) ?>"><?= rgEsc($homeLabel) ?></a></div>
</div></footer>
</div>
<script src="theme.js"></script>
<script>if(window.applyCachedTheme)applyCachedTheme('camp:<?= rgEsc($g['footer_target'] === 'technik' ? 'technik' : 'dj') ?>',{fontSelector:'h1,h2,h3,h4,.kicker,.btn,.logo .wm'});</script>
</body>
</html>
<?php
  exit;
}

/* ---------- Artikelliste ---------- */
$rows = $pdo->query("select slug, title, h1, meta_desc, kicker from guides where published = 1 order by sort")->fetchAll();
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Ratgeber | DJ Lauschgift &amp; Lauschgift Veranstaltungstechnik, Hemer</title>
<meta name="description" content="Antworten auf die Fragen, die vor der Buchung eines DJs oder von Veranstaltungstechnik am häufigsten aufkommen – Preise, Ablauf, Checklisten.">
<link rel="canonical" href="https://lauschgift.net/ratgeber">
<link href="fonts.css" rel="stylesheet">
<link href="kampagne.css" rel="stylesheet">
<style>.rg-list a{display:block;padding:20px 0;border-bottom:1px solid var(--line)}
.rg-list h3{font-size:19px;margin-bottom:6px}.rg-list p{color:var(--mut);font-size:14px}</style>
</head>
<body>
<div id="app">
<nav><div class="nav-in">
  <a class="logo" href="index.html">
    <svg viewBox="0 0 32 32" aria-hidden="true"><g fill="var(--acc)"><rect x="3" y="11" width="5" height="10" rx="2.5"/><rect x="10" y="5" width="5" height="22" rx="2.5"/><rect x="17" y="9" width="5" height="14" rx="2.5"/><rect x="24" y="13" width="5" height="6" rx="2.5"/></g></svg>
    <span class="wm">lauschgift<i>.</i></span>
  </a>
  <a href="index.html#anfrage" class="btn" style="padding:9px 18px;font-size:12px">Anfragen</a>
</div></nav>
<header class="hero" style="min-height:auto;padding:130px 0 20px"><div class="wrap">
  <h1>Ratgeber</h1>
  <p class="sub">Antworten auf die Fragen, die vor einer Buchung am häufigsten aufkommen.</p>
</div></header>
<div class="wrap rg-list">
<?php foreach ($rows as $r): ?>
  <a href="ratgeber/<?= rgEsc($r['slug']) ?>">
    <?php if (!empty($r['kicker'])): ?><div class="kicker" style="margin-bottom:4px"><?= rgEsc($r['kicker']) ?></div><?php endif; ?>
    <h3><?= rgEsc($r['h1'] ?: $r['title']) ?></h3>
    <p><?= rgEsc($r['meta_desc'] ?? '') ?></p>
  </a>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="mut">Noch keine Artikel veröffentlicht.</p><?php endif; ?>
</div>
<footer><div class="wrap"><div><a href="index.html">Zur Hauptseite</a></div></div></footer>
</div>
<script src="theme.js"></script>
<script>if(window.applyCachedTheme)applyCachedTheme('camp:dj',{fontSelector:'h1,h2,h3,h4,.kicker,.btn,.logo .wm'});</script>
</body>
</html>
