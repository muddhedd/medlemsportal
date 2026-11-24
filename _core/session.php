<?php
declare(strict_types=1);

/**
 * Sessions are stored in the database instead of the host's shared temp
 * folder. Session ids are stored hashed, so a database leak does not
 * hand out live logins. The user id is stored alongside so that all of a
 * user's sessions can be ended at once (password reset, removal, etc.).
 */
final class GpSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(private PDO $pdo, private string $table)
    {
    }

    private function key(string $id): string
    {
        return hash('sha256', $id);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $v = db_val($this->pdo, 'SELECT data FROM ' . $this->table . ' WHERE id = ?', [$this->key($id)]);
        return is_string($v) ? $v : '';
    }

    public function write(string $id, string $data): bool
    {
        $uid = isset($_SESSION['uid']) && is_int($_SESSION['uid']) ? $_SESSION['uid'] : null;
        db_exec(
            $this->pdo,
            'INSERT INTO ' . $this->table . ' (id, user_id, data, last_activity) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), data = VALUES(data), last_activity = VALUES(last_activity)',
            [$this->key($id), $uid, $data, time()]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        db_exec($this->pdo, 'DELETE FROM ' . $this->table . ' WHERE id = ?', [$this->key($id)]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return db_exec($this->pdo, 'DELETE FROM ' . $this->table . ' WHERE last_activity < ?', [time() - $max_lifetime]);
    }

    public function validateId(string $id): bool
    {
        return db_val($this->pdo, 'SELECT 1 FROM ' . $this->table . ' WHERE id = ?', [$this->key($id)]) !== null;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        db_exec($this->pdo, 'UPDATE ' . $this->table . ' SET last_activity = ? WHERE id = ?', [time(), $this->key($id)]);
        return true;
    }
}

function gp_session_start(PDO $pdo, string $table, string $cookieName, string $cookiePath, int $maxLifetime): void
{
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string) $maxLifetime);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');

    session_set_save_handler(new GpSessionHandler($pdo, $table), true);
    session_name($cookieName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookiePath,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** New session id after login; optionally make the cookie persistent ("remember me"). */
function gp_session_login(int $persistSeconds = 0): void
{
    session_regenerate_id(true);
    $p = session_get_cookie_params();
    if ($persistSeconds > 0) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $persistSeconds,
            'path'     => $p['path'],
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function gp_session_logout(): void
{
    $p = session_get_cookie_params();
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $p['path'],
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** End every session belonging to a user (e.g. after a password reset or removal). */
function gp_kill_user_sessions(PDO $pdo, string $table, int $userId): void
{
    db_exec($pdo, 'DELETE FROM ' . $table . ' WHERE user_id = ?', [$userId]);
}
