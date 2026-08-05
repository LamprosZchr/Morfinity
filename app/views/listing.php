<header class="page-hero"><p class="eyebrow"><?= e($eyebrow ?? 'MORFINITY') ?></p><h1><?= e($heading ?? $title) ?></h1><p class="lead"><?= e($intro ?? '') ?></p></header>
<section class="section container"><?php if (!empty($filters)): ?><nav class="filters" aria-label="Product filters"><a href="<?= e(url('/shop')) ?>">All</a><?php foreach ($filters as $filter): ?><a href="<?= e(url('/shop?category='.$filter['slug'])) ?>"><?= e($filter['name']) ?></a><?php endforeach; ?></nav><?php endif; ?>
<?php if ($products): ?><div class="grid"><?php foreach ($products as $product) require ROOT.'/app/views/partials/product-card.php'; ?></div><?php else: ?><div class="empty"><h2>Nothing here yet</h2><p>This edit is being prepared. Check back soon.</p></div><?php endif; ?></section>

