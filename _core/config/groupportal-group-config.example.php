<?php
/* =============================================================
   MEMBER PAGES config (private!) – used by the member pages.
   Has NO access to the admin database.
   Copy to groupportal-group-config.php and fill in.
   ============================================================= */

define('GROUPS_DB_HOST', 'localhost:3306');
define('GROUPS_DB_NAME', 'portal_groups');
define('GROUPS_DB_USER', 'portal_groups');
define('GROUPS_DB_PASS', 'CHANGE-ME');

define('SITE_URL', 'https://example.org');  // no trailing slash
define('BASE_PATH', '');                     // '' in the web root, or e.g. '/portal'
define('DEFAULT_LANG', 'sv');                // sv or en
define('DEFAULT_PROFILE', 'assoc');
