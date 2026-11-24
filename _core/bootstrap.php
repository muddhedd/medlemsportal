<?php
declare(strict_types=1);

/* =============================================================
   Group portal — shared bootstrap
   Loaded by /group-admin/ and /group-app/. Never reachable from
   the web on its own (see _core/.htaccess).
   ============================================================= */

const GP_CORE = __DIR__;

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Stockholm');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require GP_CORE . '/db.php';
require GP_CORE . '/session.php';
require GP_CORE . '/security.php';
require GP_CORE . '/i18n.php';
require GP_CORE . '/slug.php';
require GP_CORE . '/permissions.php';
require GP_CORE . '/groups.php';
require GP_CORE . '/meetings.php';
require GP_CORE . '/formal.php';
require GP_CORE . '/economy.php';
require GP_CORE . '/qr.php';
require GP_CORE . '/demo.php';
require GP_CORE . '/view.php';

/** Stop with a neutral message; details go to the server's error log only. */
function gp_fail(string $logMessage, int $status = 503): never
{
    error_log('groupportal: ' . $logMessage);
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Service temporarily unavailable.');
}

/**
 * Config files live outside the web root when possible:
 *   <account>/httpd.private/<name>
 * Fallback: _core/config/<name> (denied by .htaccess).
 */
function gp_load_config(string $name): void
{
    foreach ([dirname(__DIR__, 2) . '/httpd.private/' . $name, __DIR__ . '/config/' . $name] as $file) {
        if (@is_file($file)) {
            require_once $file;
            return;
        }
    }
    gp_fail('config file not found: ' . $name);
}

function gp_security_headers(): void
{
    header_remove('X-Powered-By');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; manifest-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'");
    header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=(), browsing-topics=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
}

/* ----------------------------- helpers ----------------------------- */

/** Installation folder, e.g. "" for the domain root or "/demo" (config BASE_PATH). */
function base_path(): string
{
    return defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
}

/** An absolute path inside the installation: path('/x/') -> '/demo/x/'. */
function path(string $p): string
{
    return base_path() . $p;
}

function asset(string $file): string
{
    return path('/gp-assets/' . $file);
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** POST field as string; arrays and other junk become ''. */
function post_str(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? $v : '';
}

function get_str(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? $v : '';
}

/** POST field as list of strings (checkbox groups). */
function post_list(string $key): array
{
    $v = $_POST[$key] ?? [];
    return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
}

/** Single-line user text: valid UTF-8, no control/bidi characters, collapsed whitespace. */
function clean_line(string $s, int $max): string
{
    if (!mb_check_encoding($s, 'UTF-8')) {
        return '';
    }
    $s = preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{200B}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

/** Multi-line user text. */
function clean_text(string $s, int $max): string
{
    if (!mb_check_encoding($s, 'UTF-8')) {
        return '';
    }
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}\x{200B}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s) ?? '';
    $s = preg_replace("/\n{4,}/", "\n\n\n", $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

/** Current local time in DB format. All times are written from PHP, never NOW() in SQL. */
function now_db(int $offsetSeconds = 0): string
{
    return date('Y-m-d H:i:s', time() + $offsetSeconds);
}

/** Redirect to an internal path only (must start with a single "/"). */
function redirect(string $path): never
{
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, "\n") || str_contains($path, "\r")) {
        $path = '/';
    }
    header('Location: ' . $path, true, 303);
    exit;
}

/* ------------------------- CSRF & flash ------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Every POST must come from our own pages and carry the session's CSRF token. */
function csrf_check(): bool
{
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($site !== '' && $site !== 'same-origin') {
        return false;
    }
    $sent = post_str('csrf');
    return $sent !== '' && isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

/** Occasionally run housekeeping (cheap probabilistic "cron" for shared hosting). */
function maybe(int $oneIn): bool
{
    return random_int(1, $oneIn) === 1;
}
