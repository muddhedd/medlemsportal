<?php
declare(strict_types=1);

/* Characters that cannot be confused when read aloud or copied from paper:
   no 0/O, 1/I/L. */
const GP_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

const PW_MIN_LEN = 10;
const PW_MAX_LEN = 200;
const OTP_TTL_SECONDS = 7 * 86400;

function random_str(int $len): string
{
    $out = '';
    $max = strlen(GP_ALPHABET) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= GP_ALPHABET[random_int(0, $max)];
    }
    return $out;
}

/** One-time password, e.g. K7QM-2XPA-9RTD (≈ 59 bits). */
function gen_one_time_password(): string
{
    return random_str(4) . '-' . random_str(4) . '-' . random_str(4);
}

/* ---------------------------- passwords ---------------------------- */

function pw_algo(): string|int|null
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function pw_hash(string $password): string
{
    return password_hash($password, pw_algo());
}

/** A fixed hash to verify against when the user doesn't exist (equal timing). */
function pw_dummy_verify(string $password): void
{
    static $dummy = null;
    $dummy ??= password_hash('dummy-password-for-timing', pw_algo());
    password_verify($password, $dummy);
}

/** Returns an i18n error key, or null when the password is acceptable. */
function pw_policy_error(string $password, string $username): ?string
{
    $len = mb_strlen($password);
    if ($len < PW_MIN_LEN) {
        return 'pw.too_short';
    }
    if ($len > PW_MAX_LEN) {
        return 'pw.too_long';
    }
    if (mb_strtolower($password) === mb_strtolower($username)) {
        return 'pw.same_as_user';
    }
    if (preg_match('/^(.)\1+$/u', $password)) {
        return 'pw.too_simple';
    }
    return null;
}

/* ------------------------ login throttling ------------------------ */

/** Seconds to lock after N consecutive failures: 0,0,0,15,30,60,120 … max 15 min. */
function lock_seconds(int $failures): int
{
    return $failures < 3 ? 0 : (int) min(900, 15 * (2 ** ($failures - 3)));
}

/* ------------------------------ TOTP ------------------------------ */

function base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
    $bits = '';
    foreach (str_split($b32) as $c) {
        $pos = strpos($alphabet, $c);
        if ($pos === false) {
            return '';
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));
}

function totp_code(string $secretB32, int $step): string
{
    $key = base32_decode($secretB32);
    $hash = hash_hmac('sha1', pack('J', $step), $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $num = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Verify a 6-digit code (±1 step for clock drift). Returns the matched
 * time step, or null. Callers store the step and reject reuse (replay).
 */
function totp_verify(string $secretB32, string $code, ?int $lastUsedStep): ?int
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return null;
    }
    $now = intdiv(time(), 30);
    foreach ([$now - 1, $now, $now + 1] as $step) {
        if ($lastUsedStep !== null && $step <= $lastUsedStep) {
            continue;
        }
        if (hash_equals(totp_code($secretB32, $step), $code)) {
            return $step;
        }
    }
    return null;
}

/* ---------------- secret encryption (TOTP secrets at rest) ---------------- */

function gp_enc_key(): string
{
    if (!defined('ADMIN_SECRET') || !function_exists('sodium_crypto_secretbox')) {
        gp_fail('ADMIN_SECRET or sodium missing');
    }
    return hash('sha256', 'totp-at-rest|' . ADMIN_SECRET, true);
}

function gp_encrypt(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, gp_enc_key()));
}

function gp_decrypt(string $stored): ?string
{
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, gp_enc_key());
    return $plain === false ? null : $plain;
}
