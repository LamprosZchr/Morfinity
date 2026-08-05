<?php ob_start(); ?><div class="section-head"><div><p class="eyebrow">Administration</p><h1><?= e($heading) ?></h1></div><?php if (!empty($newUrl)): ?><a class="button" href="<?= e(url($newUrl)) ?>">Add new</a><?php endif; ?></div><?= $inner ?><?php $adminContent=ob_get_clean(); require ROOT.'/app/views/admin/shell.php'; ?>

