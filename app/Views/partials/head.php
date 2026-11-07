<?php
/** @var string $csrf */
use App\Services\ThemeService;
$theme = ThemeService::settings();
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e($csrf) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<meta name="robots" content="noindex,nofollow">
<meta name="color-scheme" content="light dark">
<title><?= e($title ?? 'TAL Messaging') ?> · TAL Messaging</title>
<link rel="stylesheet" href="<?= e(url('theme.css')) ?>?v=<?= e(substr(md5(ThemeService::css()), 0, 8)) ?>">
<link rel="stylesheet" href="<?= asset('vendor/tabler/tabler-icons.min.css') ?>">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<script src="<?= asset('js/theme-init.js') ?>"></script>
<?php /* data-theme-default est posé sur <html> par les layouts via $theme['default_mode'] */ ?>
