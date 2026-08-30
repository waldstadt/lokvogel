<?php
/* Dynamische Sitemap — listet die öffentlichen Seiten mit letztem Änderungsdatum. */
declare(strict_types=1);
header('Content-Type: application/xml; charset=utf-8');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'lauschgift.net') .
  rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$pages = [
  ['index.html', '1.0', 'weekly'],
  ['technik.html', '0.9', 'weekly'],
  ['weihnachtsfeier.html', '0.8', 'monthly'],
  ['halloween.html', '0.8', 'monthly'],
];
/* Aktionsseiten nur listen, wenn sie im Backoffice eingeschaltet sind */
try {
  $db = new PDO('sqlite:' . __DIR__ . '/data/dj.sqlite');
  foreach ($db->query("select slug from campaign_pages where enabled = 1 order by sort") as $row)
    $pages[] = [$row['slug'] . '.html', '0.8', 'monthly'];
} catch (Throwable $e) {}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as [$f, $prio, $freq]) {
  $file = __DIR__ . '/' . $f;
  if (!is_file($file)) continue;
  echo "  <url><loc>$base/" . ($f === 'index.html' ? '' : $f) . "</loc>" .
    '<lastmod>' . gmdate('Y-m-d', (int)filemtime($file)) . '</lastmod>' .
    "<changefreq>$freq</changefreq><priority>$prio</priority></url>\n";
}
echo "</urlset>\n";
