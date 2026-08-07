<header class="page-hero"><p class="eyebrow">Customer account</p><h1><?= e($mode === 'register' ? 'Create account' : 'Sign in') ?></h1><p class="lead">Your account securely links purchases and subscriptions to your MORFINITY access.</p></header>
<section class="section container"><form method="post" class="form-grid" action="<?= e(url($mode === 'register' ? '/account/register' : '/account/login')) ?>"><?= csrf_field() ?>
<?php if ($mode === 'register'): ?><div class="field full"><label for="name">Name *</label><input id="name" name="name" required autocomplete="name" value="<?= old('name') ?>"></div><?php endif; ?>
<div class="field full"><label for="email">Email *</label><input id="email" type="email" name="email" required autocomplete="email" value="<?= old('email') ?>"></div>
<div class="field full"><label for="password">Password *</label><input id="password" type="password" name="password" required minlength="10" autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>"></div>
<div class="field full"><button><?= e($mode === 'register' ? 'Create account' : 'Sign in') ?></button></div></form>
<p><?= $mode === 'register' ? 'Already registered?' : 'New to MORFINITY?' ?> <a href="<?= e(url($mode === 'register' ? '/account/login' : '/account/register')) ?>"><?= $mode === 'register' ? 'Sign in' : 'Create an account' ?></a>.</p></section>
