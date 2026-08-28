<?php
/**
 * deploy.php — Selbst-Update des Webauftritts von GitHub (Branch "live").
 *
 * Funktionsweise (Pull-Deployment, kein FTP nötig):
 *   1. Fragt GitHub nach dem neuesten Commit des Deploy-Branches.
 *   2. Ist er neuer als der zuletzt installierte: Zipball herunterladen,
 *      Code-Dateien im Webroot ersetzen. data/ und uploads/ bleiben unberührt.
 *   3. Vorher: Datenbank-Snapshot + Kopie der ersetzten Dateien nach
 *      data/deploy-backup/. Danach: neuen Commit-Stand merken.
 *
 * Aufrufe (Schlüssel kommt aus data/deploy.json, sichtbar im Backoffice):
 *   deploy.php?key=…&action=status   → aktueller Stand (installiert vs. GitHub)
 *   deploy.php?key=…&action=run     → Update ausführen, wenn etwas Neues da ist
 *   deploy.php?key=…&action=run&force=1 → Update auch bei gleichem Stand
 *
 * Einrichtung über das Backoffice (Einstellungen → Website-Updates) oder
 * manuell: data/deploy.json mit {"key":"…","repo":"owner/repo",
 * "branch":"live","subdir":"fullservice-dj-homepage/webroot","token":"github_pat_…"}.
 * Für den Automatikbetrieb: All-Inkl-Cronjob (z. B. alle 15 Minuten) auf die
 * run-URL. Livestellen heißt dann nur noch: auf den Branch "live" pushen.
 */

declare(strict_types=1);
const DP_DATA = __DIR__ . '/data';
const DP_CONF = DP_DATA . '/deploy.json';
const DP_KEEP = ['data', 'uploads', 'img'];   // wird beim Update nie angefasst

header('Content-Type: application/json; charset=utf-8');
function dp_out(array $j, int $code = 200): never {
  http_response_code($code);
  echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;
}
function dp_conf(): array {
  if (!is_file(DP_CONF)) dp_out(['error' => 'Deployment ist noch nicht eingerichtet (Backoffice → Einstellungen → Website-Updates).'], 404);
  $c = json_decode((string)file_get_contents(DP_CONF), true);
  if (!is_array($c) || empty($c['key'])) dp_out(['error' => 'Deployment-Konfiguration unlesbar.'], 500);
  return $c;
}
function dp_save(array $c): void {
  if (!is_dir(DP_DATA)) mkdir(DP_DATA, 0755, true);
  $ht = DP_DATA . '/.htaccess';
  if (!file_exists($ht)) file_put_contents($ht, "Require all denied\n");
  file_put_contents(DP_CONF, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
function dp_http(string $url, string $token, bool $toFile = false): array {
  $ch = curl_init($url);
  $headers = ['User-Agent: lauschgift-deploy', 'Accept: application/vnd.github+json'];
  if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => !$toFile, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 120,
  ]);
  $fh = null;
  if ($toFile) {
    $tmp = tempnam(sys_get_temp_dir(), 'dpzip');
    $fh = fopen($tmp, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fh);
  }
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($fh) { fclose($fh); $body = $tmp; }
  return [$code, $body, $err];
}

$conf = dp_conf();
$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals((string)$conf['key'], $key)) { usleep(500000); dp_out(['error' => 'Ungültiger Schlüssel.'], 401); }

$repo   = (string)($conf['repo'] ?? '');
$branch = (string)($conf['branch'] ?? 'live');
$subdir = trim((string)($conf['subdir'] ?? ''), '/');
$token  = (string)($conf['token'] ?? '');
$action = (string)($_GET['action'] ?? 'status');
if ($repo === '') dp_out(['error' => 'Kein Repository konfiguriert.'], 500);

/* Neuester Commit auf dem Deploy-Branch */
[$code, $body, $err] = dp_http("https://api.github.com/repos/$repo/commits/" . rawurlencode($branch), $token);
if ($code !== 200) dp_out(['error' => "GitHub nicht erreichbar oder Zugriff verweigert (HTTP $code). Token/Repo prüfen.", 'detail' => $err ?: substr((string)$body, 0, 300)], 502);
$head = json_decode((string)$body, true);
$remoteSha = (string)($head['sha'] ?? '');
$remoteMsg = (string)($head['commit']['message'] ?? '');
$remoteDate = (string)($head['commit']['committer']['date'] ?? '');
if ($remoteSha === '') dp_out(['error' => 'Konnte den Branch-Stand nicht lesen.'], 502);

$status = [
  'installiert' => $conf['last_sha'] ?? null,
  'installiert_am' => $conf['last_time'] ?? null,
  'github' => $remoteSha,
  'github_commit' => strtok($remoteMsg, "\n"),
  'github_datum' => $remoteDate,
  'update_verfuegbar' => ($conf['last_sha'] ?? '') !== $remoteSha,
];
if ($action === 'status') dp_out(['ok' => true] + $status);
if ($action !== 'run') dp_out(['error' => 'Unbekannte Aktion.'], 400);
if (!$status['update_verfuegbar'] && empty($_GET['force'])) dp_out(['ok' => true, 'message' => 'Schon aktuell — nichts zu tun.'] + $status);

/* Gleichzeitige Läufe verhindern */
$lock = fopen(DP_DATA . '/deploy.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) dp_out(['error' => 'Ein Update läuft bereits.'], 423);

/* 1. Datenbank-Snapshot (best effort) */
$dbBackup = null;
if (is_file(DP_DATA . '/dj.sqlite')) {
  $bdir = DP_DATA . '/backups';
  if (!is_dir($bdir)) mkdir($bdir, 0755, true);
  $dbBackup = 'dj-' . gmdate('Ymd-Hi') . '-vor-update.sqlite.gz';
  @file_put_contents("$bdir/$dbBackup", gzencode((string)file_get_contents(DP_DATA . '/dj.sqlite'), 6));
}

/* 2. Zipball laden und entpacken */
[$code, $zipPath, $err] = dp_http("https://api.github.com/repos/$repo/zipball/" . rawurlencode($branch), $token, true);
if ($code !== 200) { @unlink((string)$zipPath); dp_out(['error' => "Download fehlgeschlagen (HTTP $code).", 'detail' => $err], 502); }
$zip = new ZipArchive();
if ($zip->open((string)$zipPath) !== true) { @unlink((string)$zipPath); dp_out(['error' => 'Zip-Archiv unlesbar.'], 500); }

/* Wurzelordner im Zip ermitteln (github-…-sha/) */
$rootPrefix = rtrim((string)$zip->getNameIndex(0), '/');
$srcPrefix = $rootPrefix . '/' . ($subdir !== '' ? $subdir . '/' : '');

/* 3. Alte Code-Dateien sichern, dann ersetzen */
$bakDir = DP_DATA . '/deploy-backup/' . gmdate('Ymd-His') . '-' . substr((string)($conf['last_sha'] ?? 'initial'), 0, 7);
$written = 0; $skipped = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
  $name = (string)$zip->getNameIndex($i);
  if (!str_starts_with($name, $srcPrefix) || str_ends_with($name, '/')) continue;
  $rel = substr($name, strlen($srcPrefix));
  if ($rel === '' || str_contains($rel, '..')) continue;
  $top = explode('/', $rel)[0];
  if (in_array($top, DP_KEEP) || $rel === 'data' || $rel === 'uploads') { $skipped++; continue; }
  $dest = __DIR__ . '/' . $rel;
  if (is_file($dest)) {
    $bakFile = "$bakDir/$rel";
    if (!is_dir(dirname($bakFile))) mkdir(dirname($bakFile), 0755, true);
    @copy($dest, $bakFile);
  }
  if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
  file_put_contents($dest, $zip->getFromIndex($i));
  $written++;
}
$zip->close();
@unlink((string)$zipPath);

/* Alte Deploy-Backups aufräumen (die letzten 5 behalten) */
$baks = glob(DP_DATA . '/deploy-backup/*') ?: [];
sort($baks);
while (count($baks) > 5) {
  $old = array_shift($baks);
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($old, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
  @rmdir($old);
}

/* 4. Neuen Stand merken */
$conf['last_sha'] = $remoteSha;
$conf['last_time'] = gmdate('c');
dp_save($conf);
flock($lock, LOCK_UN);

dp_out(['ok' => true, 'message' => 'Update installiert.', 'commit' => strtok($remoteMsg, "\n"),
  'sha' => $remoteSha, 'dateien' => $written, 'uebersprungen' => $skipped,
  'db_backup' => $dbBackup, 'code_backup' => basename($bakDir)], 200);
