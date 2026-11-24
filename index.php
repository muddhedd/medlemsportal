<?php
declare(strict_types=1);

/* Front page: neutral, reveals nothing. Members use their own address or QR code. */

require __DIR__ . '/_core/bootstrap.php';
gp_load_config('groupportal-group-config.php');
gp_security_headers();
i18n_init(defined('DEFAULT_LANG') ? DEFAULT_LANG : 'sv', defined('DEFAULT_PROFILE') ? DEFAULT_PROFILE : 'assoc');

$extra = '';
if (is_demo()) {
    demo_maybe_reset(false);
    $extra = '<section class="card narrow"><div class="stack">'
        . '<a class="btn" href="' . e(path('/' . DEMO_GROUP_SLUG . '/')) . '">' . e(t('landing.open_demo')) . '</a>'
        . '<a class="btn btn-ghost" href="' . e(path('/group-admin/')) . '">' . e(t('landing.open_admin')) . '</a>'
        . '</div></section>' . demo_login_help(false) . demo_login_help(true);
}

render_page(t('app.name'), '<section class="card narrow"><h1>' . e(t('app.name')) . '</h1><p>' . e(t('landing.text')) . '</p></section>' . $extra);
