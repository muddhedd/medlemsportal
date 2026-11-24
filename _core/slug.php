<?php
declare(strict_types=1);

/** Path names that can never be used as the first part of a group address. */
const RESERVED_SLUGS = [
    'group-admin', 'group-app', 'gp-assets', '_core', 'admin', 'api', 'assets',
    'index', 'login', 'logout', 'robots', 'sitemap', 'favicon', 'static', 'www',
];

/**
 * "Malmö" -> "malmo", "Båtklubben Ön" -> "batklubben-on".
 * Only a–z, 0–9 and single hyphens remain.
 */
function slugify(string $s): string
{
    $s = mb_strtolower(trim($s));
    // Explicit map first, so the result never depends on what the server has installed.
    $s = strtr($s, [
        'å' => 'a', 'ä' => 'a', 'ö' => 'o', 'æ' => 'ae', 'ø' => 'o', 'ü' => 'u',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a',
        'â' => 'a', 'ã' => 'a', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u',
        'û' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
        'þ' => 'th', 'ð' => 'd', 'ł' => 'l', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'č' => 'c', 'š' => 's', 'ž' => 'z', 'ř' => 'r', 'ě' => 'e', 'ů' => 'u',
    ]);
    if (class_exists('Transliterator')) {
        $t = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        if ($t !== null) {
            $s = (string) $t->transliterate($s);
        }
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return substr($s, 0, 60);
}

/** Build "country/city/group" (or a single segment) from parts; empty parts are skipped. */
function slug_path(array $parts): string
{
    $segments = array_values(array_filter(array_map('slugify', $parts), fn ($p) => $p !== ''));
    return implode('/', $segments);
}

/** Normalise a slug path typed by a human. */
function slug_path_clean(string $path): string
{
    return slug_path(explode('/', $path));
}

function slug_is_reserved(string $path): bool
{
    $first = explode('/', $path)[0] ?? '';
    return in_array($first, RESERVED_SLUGS, true);
}
