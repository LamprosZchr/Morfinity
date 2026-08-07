<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($pageTitle) ?> | MORFINITY</title>
  <meta name="description" content="<?= e($description ?? 'Original products, independent brands, and creative production by MORFINITY.') ?>">
  <meta name="theme-color" content="#11110f"><link rel="canonical" href="<?= e(url(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>"><script defer src="<?= e(url('/assets/js/app.js')) ?>"></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header"><a class="wordmark" href="<?= e(url('/')) ?>" aria-label="MORFINITY home">MORFINITY<span>∞</span></a>
  <button class="nav-toggle" aria-expanded="false" aria-controls="nav">Menu</button>
  <nav id="nav" aria-label="Main navigation"><a href="<?= e(url('/shop')) ?>">Shop</a><a href="<?= e(url('/plans')) ?>">Plans</a><a href="<?= e(url('/originals')) ?>">Originals</a><a href="<?= e(url('/brands')) ?>">Brands</a><a href="<?= e(url('/production')) ?>">Production</a><a href="<?= e(url('/launch-your-brand')) ?>">Launch yours</a><a href="<?= e(url(is_user_signed_in() ? '/account' : '/account/login')) ?>"><?= is_user_signed_in() ? 'Account' : 'Sign in' ?></a><a href="<?= e(url('/cart')) ?>">Bag <span class="badge"><?= cart_count() ?></span></a></nav>
</header>
<?php foreach ($_SESSION['flash'] ?? [] as $notice): ?><div class="notice <?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<main id="main"><?= $content ?></main>
<footer class="site-footer"><div><a class="wordmark inverse" href="<?= e(url('/')) ?>">MORFINITY∞</a><p>More than a brand. A home for brands.</p></div>
  <div><h2>Explore</h2><a href="<?= e(url('/about')) ?>">About</a><a href="<?= e(url('/how-it-works')) ?>">How it works</a><a href="<?= e(url('/contact')) ?>">Contact</a><a href="<?= e(url('/faq')) ?>">FAQ</a></div>
  <div><h2>Policies</h2><a href="<?= e(url('/privacy')) ?>">Privacy</a><a href="<?= e(url('/terms')) ?>">Terms</a><a href="<?= e(url('/returns-shipping')) ?>">Returns & shipping</a></div>
  <div><h2>Stay close</h2><form method="post" action="<?= e(url('/newsletter')) ?>"><?= csrf_field() ?><label for="footer-email">Email address</label><div class="inline"><input id="footer-email" type="email" name="email" required placeholder="you@example.com"><button>Join</button></div></form><p><a href="<?= e(setting('instagram_url', '#')) ?>">Instagram</a> · <a href="<?= e(setting('tiktok_url', '#')) ?>">TikTok</a></p></div>
  <p class="footer-bottom">© <?= date('Y') ?> MORFINITY. Created and powered by MORFINITY.</p>
</footer>
</body></html>

