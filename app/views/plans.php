<header class="page-hero"><p class="eyebrow">MORFINITY Studio</p><h1>Plans and access</h1><p class="lead">Choose an active Stripe-managed plan. Checkout and billing are securely hosted by Stripe.</p></header>
<section class="section container">
<?php if (!$stripeConfigured): ?><div class="empty"><h2>Plans are being prepared.</h2><p>Stripe Test mode is not configured on this environment yet.</p></div>
<?php elseif (!$plans): ?><div class="empty"><h2>No plans are available.</h2><p>Create an active Stripe Product and Price with metadata <code>morfinity_entitlement_key=studio_access</code>.</p></div>
<?php else: ?><div class="product-grid">
<?php foreach ($plans as $plan): ?><article class="product-card">
  <?php if (!empty($plan['images'][0])): ?><img src="<?= e($plan['images'][0]) ?>" alt=""><?php endif; ?>
  <div class="product-card-body"><p class="eyebrow"><?= e($plan['type'] === 'recurring' ? 'Subscription' : 'One-time access') ?></p><h2><?= e($plan['name']) ?></h2><p><?= e($plan['description']) ?></p>
  <p><strong><?= e($plan['currency']) ?> <?= number_format($plan['unit_amount']/100, 2) ?></strong><?php if ($plan['type'] === 'recurring'): ?> / <?= e($plan['recurring']['interval'] ?? 'period') ?><?php endif; ?></p>
  <?php if (is_user_signed_in()): ?><form method="post" action="<?= e(url('/stripe/checkout')) ?>"><?= csrf_field() ?><input type="hidden" name="price_id" value="<?= e($plan['price_id']) ?>"><button>Continue to Stripe</button></form>
  <?php else: ?><a class="button" href="<?= e(url('/account/login?next=/plans')) ?>">Sign in to continue</a><?php endif; ?>
  </div></article><?php endforeach; ?>
</div><?php endif; ?>
</section>
