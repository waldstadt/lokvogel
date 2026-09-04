<?php
/**
 * Server-seitiges Grundgerüst für die Aktionsseiten (Kampagnen-Minipages).
 *
 * Diese Seiten waren bislang reine JavaScript-Hüllen: Titel/Meta-Description standen
 * im HTML, aber Überschrift, Fließtext und Aufzählungen kamen erst durch kampagne.js
 * per fetch() dazu. Für Crawler/Bots ohne JavaScript-Ausführung (und Vorschau-Bots
 * beim Teilen eines Links) war die Seite damit praktisch leer - kein <h1>, kein Text.
 *
 * Dieses Skript liefert denselben Inhalt bereits im ersten HTML aus (serverseitig aus
 * derselben Tabelle campaign_pages gelesen, die auch kampagne.js/das Backoffice
 * benutzen). kampagne.js überschreibt danach wie bisher komplett den Inhalt von
 * #app - Farben/Schrift-Theme, Icons und das interaktive Formular kommen weiterhin
 * ausschließlich von dort. Dieses Skript kennt keine Theme-Berechnung (das bleibt
 * Sache von theme.js/kampagne.js) - kampagne.css liefert dafür extra einen
 * dunklen Fallback-Farbsatz, bis das echte Theme geladen ist (siehe Kommentar dort).
 *
 * Aufruf je Aktionsseite: eine Zeile pro Datei (siehe abiball.html etc.):
 *   <?php $SLUG = basename(__FILE__, '.html'); require __DIR__ . '/campaign-render.php';
 * Serviert wird das nur, wenn die .htaccess die jeweilige Datei per ForceType als PHP
 * ausführen lässt (Apache-Produktion; der lokale php -S-Entwicklungsserver ignoriert
 * .htaccess ohnehin, wie an anderer Stelle im Projekt schon dokumentiert).
 */
declare(strict_types=1);

function cpEsc($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$pg = null;
try {
  $pdo = new PDO('sqlite:' . __DIR__ . '/data/dj.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $st = $pdo->prepare('select * from campaign_pages where slug = ? and enabled = 1');
  $st->execute([$SLUG]);
  $pg = $st->fetch() ?: null;
} catch (Throwable $e) {
  /* Datenbank nicht erreichbar o.ae. - wie bei unbekanntem/ausgeschaltetem Slug
     lieber sauber zur Startseite als eine kaputte Seite ausliefern. */
  $pg = null;
}

/* Ausgeschaltete oder unbekannte Aktionsseite: echter serverseitiger 302 statt des
   bisherigen client-seitigen location.replace() - schneller (kein Warten auf JS/fetch)
   und fuer Crawler eindeutig als Weiterleitung erkennbar. */
if (!$pg) {
  header('Location: index.html', true, 302);
  exit;
}

$cards = json_decode((string)($pg['cards'] ?? '[]'), true) ?: [];
$features = json_decode((string)($pg['features'] ?? '[]'), true) ?: [];
$homeHref = ($pg['footer_target'] ?? '') === 'technik' ? 'technik.html' : 'index.html';
$homeLabel = ($pg['footer_target'] ?? '') === 'technik' ? 'Zur Technik-Seite' : 'Zur Hauptseite';
$accent = preg_match('/^#[0-9a-f]{6}$/i', (string)($pg['accent'] ?? '')) ? substr((string)$pg['accent'], 1) : 'd9a84e';
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= cpEsc($pg['page_title'] ?: $SLUG) ?></title>
<meta name="description" content="<?= cpEsc($pg['meta_desc'] ?? '') ?>">
<link rel="canonical" href="https://lauschgift.net/<?= cpEsc($SLUG) ?>.html">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cg fill='%23<?= $accent ?>'%3E%3Crect x='3' y='11' width='5' height='10' rx='2.5'/%3E%3Crect x='10' y='5' width='5' height='22' rx='2.5'/%3E%3Crect x='17' y='9' width='5' height='14' rx='2.5'/%3E%3Crect x='24' y='13' width='5' height='6' rx='2.5'/%3E%3C/g%3E%3C/svg%3E">
<link href="fonts.css" rel="stylesheet">
<link href="kampagne.css" rel="stylesheet">
</head>
<body>
<div id="app">
<nav><div class="nav-in">
  <a class="logo" href="<?= cpEsc($homeHref) ?>">
    <svg viewBox="0 0 32 32" aria-hidden="true"><g fill="var(--acc)"><rect x="3" y="11" width="5" height="10" rx="2.5"/><rect x="10" y="5" width="5" height="22" rx="2.5"/><rect x="17" y="9" width="5" height="14" rx="2.5"/><rect x="24" y="13" width="5" height="6" rx="2.5"/></g></svg>
    <span class="wm">lauschgift<i>.</i></span>
  </a>
  <a href="#anfrage" class="btn" style="padding:9px 18px;font-size:12px">Anfragen</a>
</div></nav>

<header class="hero"><div class="wrap">
  <?php if (!empty($pg['badge'])): ?><div class="badge"><?= cpEsc($pg['badge']) ?></div><?php endif; ?>
  <h1><?= cpEsc($pg['h1_line1'] ?? '') ?><br><em><?= cpEsc($pg['h1_line2'] ?? '') ?></em></h1>
  <p class="sub"><?= cpEsc($pg['sub'] ?? '') ?></p>
  <a href="#anfrage" class="btn">Unverbindlich anfragen</a>
</div></header>

<section><div class="wrap">
  <div class="kicker"><?= cpEsc($pg['kicker1'] ?? '') ?></div>
  <h2><?= cpEsc($pg['h2_1'] ?? '') ?></h2>
  <div class="pts">
  <?php foreach ($cards as $c): ?>
    <div class="pt"><h3><?= cpEsc($c['title'] ?? '') ?></h3><p><?= cpEsc($c['text'] ?? '') ?></p></div>
  <?php endforeach; ?>
  </div>
</div></section>

<section class="inc"><div class="wrap">
  <div class="kicker"><?= cpEsc($pg['kicker2'] ?? '') ?></div>
  <h2><?= cpEsc($pg['h2_2'] ?? '') ?></h2>
  <ul>
  <?php foreach ($features as $f): ?><li><?= cpEsc($f) ?></li><?php endforeach; ?>
  </ul>
  <?php if (!empty($pg['pricenote'])): ?><p class="pricenote"><?= cpEsc($pg['pricenote']) ?></p><?php endif; ?>
</div></section>

<section id="anfrage"><div class="wrap">
  <div class="kicker"><?= cpEsc($pg['form_kicker'] ?: 'Jetzt anfragen') ?></div>
  <h2><?= cpEsc($pg['form_h2'] ?: 'Wann ist es so weit?') ?></h2>
  <p class="lead"><?= cpEsc($pg['form_lead'] ?? '') ?></p>
</div></section>

<footer><div class="wrap">
  <div><a href="<?= cpEsc($homeHref) ?>"><?= cpEsc($homeLabel) ?></a></div>
</div></footer>
</div>
<script src="kontakt.js"></script>
<script src="kampagne.js"></script>
</body>
</html>
