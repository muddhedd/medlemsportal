<?php
/* =============================================================
   ADMIN config (private!) – used ONLY by /group-admin/.
   Copy to groupportal-admin-config.php and fill in.
   Best place: outside the web root (e.g. ../httpd.private/);
   otherwise in _core/config/ (blocked by .htaccess).
   ============================================================= */

// The admin portal's own database (may be the same database as below)
define('ADMIN_DB_HOST', 'localhost:3306');
define('ADMIN_DB_NAME', 'portal_admin');
define('ADMIN_DB_USER', 'portal_admin');
define('ADMIN_DB_PASS', 'CHANGE-ME');

// The shared database for all associations
define('GROUPS_DB_HOST', 'localhost:3306');
define('GROUPS_DB_NAME', 'portal_groups');
define('GROUPS_DB_USER', 'portal_groups');
define('GROUPS_DB_PASS', 'CHANGE-ME');

// Encrypts the admins' two-step secrets. 64 random hex characters, e.g. from
//   php -r "echo bin2hex(random_bytes(32));"
// Never change it after setup.
define('ADMIN_SECRET', 'CHANGE-ME-64-HEX-CHARACTERS');

// Needed once by group-admin/setup.php to create the first admin.
define('SETUP_CODE', 'CHANGE-ME-LONG-RANDOM');

define('SITE_URL', 'https://example.org');  // no trailing slash
define('BASE_PATH', '');                     // '' in the web root, or e.g. '/portal'
define('PORTAL_PROFILE', 'assoc');           // which words the admin portal uses
