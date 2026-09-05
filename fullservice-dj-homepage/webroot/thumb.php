<?php
/**
 * Vorschaubilder fuer die Kacheln im Medienpool und auf der Website - werden nicht beim
 * Hochladen erzeugt, sondern beim allerersten Aufruf ueber die Rewrite-Regel
 * "uploads/.thumbs/<pfad> -> thumb.php?f=<pfad>" in .htaccess (php -S ignoriert
 * .htaccess, deshalb funktioniert der direkte Aufruf mit ?f=... auch im Entwicklungs-
 * Server, siehe backstage.php/technik-artikel.php fuer dasselbe Muster). Danach liegt
 * die Miniatur als ganz normale Datei unter uploads/.thumbs/... und wird von Apache
 * direkt ausgeliefert - dieses Skript laeuft fuer diesen Pfad nie wieder. Funktioniert
 * dadurch auch rueckwirkend fuer laengst hochgeladene Bilder, ganz ohne Migration.
 *
 * Gleiches Sicherheitsniveau wie das Original: wer uploads/<pfad> ohnehin oeffentlich
 * abrufen kann, darf auch dessen Vorschaubild sehen - deshalb keine Anmeldeprüfung hier.
 */
declare(strict_types=1);

const THUMB_UPLOAD_DIR = __DIR__ . '/uploads';
const THUMB_MAXDIM = 640;
const THUMB_QUALITY = 72;

function thumbFail(): never { http_response_code(404); exit; }

$f = (string)($_GET['f'] ?? '');
if ($f === '' || str_contains($f, '..') || str_contains($f, "\0") || str_starts_with($f, '.thumbs/'))
  thumbFail();
$f = ltrim($f, '/');
$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) thumbFail();

$basis = realpath(THUMB_UPLOAD_DIR);
$quelle = $basis !== false ? realpath($basis . '/' . $f) : false;
if ($basis === false || $quelle === false || strpos($quelle, $basis . DIRECTORY_SEPARATOR) !== 0 || !is_file($quelle))
  thumbFail();

$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$raw = (string)@file_get_contents($quelle);
$verkleinert = thumbErzeugen($raw);
$ziel = $basis . '/.thumbs/' . $f;
if ($verkleinert !== null) {
  @mkdir(dirname($ziel), 0755, true);
  @file_put_contents($ziel, $verkleinert);
  $raw = $verkleinert;
}
header('Content-Type: ' . $mimes[$ext]);
header('Cache-Control: public, max-age=31536000, immutable');
echo $raw;
exit;

/* Gleiche Grundlogik wie processImage() in api.php (verkleinern, EXIF-Rotation, Alpha
   bei PNG/WebP erhalten) - bewusst hier dupliziert statt geteilt, api.php ist kein
   einbindbares Modul, sondern fuehrt beim Einbinden sofort den ganzen Router aus
   (gleiches Prinzip wie rgMd()/taMd() in backstage.php/technik-artikel.php). */
function thumbErzeugen(string $raw): ?string {
  if (!extension_loaded('gd')) return null;
  $info = @getimagesizefromstring($raw);
  if (!$info) return null;
  $mime = $info['mime'] ?? '';
  if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
  $img = @imagecreatefromstring($raw);
  if (!$img) return null;
  if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
    $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($raw));
    switch ($exif['Orientation'] ?? 1) {
      case 3: $img = imagerotate($img, 180, 0); break;
      case 6: $img = imagerotate($img, 270, 0); break;
      case 8: $img = imagerotate($img, 90, 0); break;
    }
  }
  $transparent = ($mime === 'image/png' || $mime === 'image/webp');
  $w = imagesx($img); $h = imagesy($img);
  $scale = min(1, THUMB_MAXDIM / max($w, $h));
  $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
  $resized = imagecreatetruecolor($nw, $nh);
  if ($transparent) {
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
  }
  imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
  imagedestroy($img);
  if ($transparent) { imagealphablending($resized, false); imagesavealpha($resized, true); }
  ob_start();
  if ($mime === 'image/png') imagepng($resized, null, 6);
  elseif ($mime === 'image/webp') imagewebp($resized, null, THUMB_QUALITY);
  else imagejpeg($resized, null, THUMB_QUALITY);
  $out = ob_get_clean();
  imagedestroy($resized);
  return ($out !== false && strlen($out) > 0) ? $out : null;
}
