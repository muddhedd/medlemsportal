<?php
declare(strict_types=1);

function gp_pdo(string $host, string $name, string $user, string $pass): PDO
{
    try {
        return new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        gp_fail('database connection failed (' . $name . ')');
    }
}

/** The shared groups database (all groups, one table structure, rows keyed by group code). */
function db(): PDO
{
    static $pdo = null;
    return $pdo ??= gp_pdo(GROUPS_DB_HOST, GROUPS_DB_NAME, GROUPS_DB_USER, GROUPS_DB_PASS);
}

/** The admin portal's own database. Only defined in the admin config. */
function db_admin(): PDO
{
    static $pdo = null;
    if (!defined('ADMIN_DB_HOST')) {
        gp_fail('admin database requested outside admin portal');
    }
    return $pdo ??= gp_pdo(ADMIN_DB_HOST, ADMIN_DB_NAME, ADMIN_DB_USER, ADMIN_DB_PASS);
}

function db_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_all(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_val(PDO $pdo, string $sql, array $params = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}
