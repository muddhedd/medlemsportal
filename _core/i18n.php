<?php
declare(strict_types=1);

/*
 * Two layers of text:
 *   lang/<lang>.php            all interface text (sv, en)
 *   lang/terms/<profile>.<lang>.php   words that differ between profiles,
 *                              e.g. "Chair" vs "Board chair" — overrides the first layer.
 * Profiles: every organisation-specific word lives ONLY in the terms files.
 */

const LANGS = ['sv', 'en'];

function i18n_init(string $lang, string $profile): void
{
    $lang = in_array($lang, LANGS, true) ? $lang : 'sv';
    $profile = preg_match('/^[a-z]{2,10}$/', $profile) ? $profile : 'default';

    $base = require GP_CORE . '/lang/' . $lang . '.php';
    $termsFile = GP_CORE . '/lang/terms/' . $profile . '.' . $lang . '.php';
    $terms = is_file($termsFile) ? require $termsFile : [];

    $GLOBALS['gp_i18n'] = ['lang' => $lang, 'text' => array_merge($base, $terms)];
}

function current_lang(): string
{
    return $GLOBALS['gp_i18n']['lang'] ?? 'sv';
}

/** Translate; {name} placeholders are replaced (values are NOT escaped — escape on output). */
function t(string $key, array $vars = []): string
{
    $text = $GLOBALS['gp_i18n']['text'] ?? [];
    $s = $text[$key] ?? $key;
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    // Profile words: {org}, {the_org}, {The_org} … come from "term.*" keys in the terms file.
    if (str_contains($s, '{')) {
        $s = preg_replace_callback('/\{([A-Za-z_]+)\}/', function ($m) use ($text) {
            $name = $m[1];
            $cap = $name[0] >= 'A' && $name[0] <= 'Z';
            $val = $text['term.' . strtolower($name[0]) . substr($name, 1)] ?? null;
            if ($val === null) {
                return $m[0];
            }
            return $cap ? mb_strtoupper(mb_substr($val, 0, 1)) . mb_substr($val, 1) : $val;
        }, $s) ?? $s;
    }
    return $s;
}

/** Available profiles = the terms files that exist (remove a file to remove a profile). */
function group_profiles(): array
{
    $out = [];
    foreach (glob(GP_CORE . '/lang/terms/*.sv.php') ?: [] as $f) {
        $out[] = basename($f, '.sv.php');
    }
    sort($out);
    return $out;
}

/** Profile facts from its Swedish terms file: label and address depth (1 or 3 levels). */
function profile_meta(string $profile): array
{
    static $cache = [];
    if (!isset($cache[$profile])) {
        $f = GP_CORE . '/lang/terms/' . $profile . '.sv.php';
        $terms = preg_match('/^[a-z]{2,10}$/', $profile) && is_file($f) ? require $f : [];
        $cache[$profile] = [
            'label'  => (string) ($terms['_label'] ?? $profile),
            'levels' => (int) ($terms['_levels'] ?? 1) === 3 ? 3 : 1,
            'contact' => !empty($terms['_contact_fields']), // may offer optional name/phone/e-mail
        ];
    }
    return $cache[$profile];
}

/** Name of a role: fixed roles come from the terms layer, custom roles from the DB. */
function role_name(array $role): string
{
    return $role['role_key'] !== null ? t('role.' . $role['role_key']) : (string) $role['name'];
}
