<?php
/* Where sessions are kept: files on one server, the database when there is
 * no shared disk (serverless). Same $_SESSION either way. */


/** Should sessions live in the database rather than on disk? */
function sessions_in_database(): bool
{
    $driver = strtolower((string) getenv('SESSION_DRIVER'));

    if ($driver === 'database') { return true; }
    if ($driver === 'files')    { return false; }

    // Vercel sets this on every deployment; other serverless hosts can set
    // SESSION_DRIVER themselves.
    return getenv('VERCEL') !== false;
}


/* The sessions table, behaving like PHP's own file handler.
 *
 * This is a class rather than the six callbacks session_set_save_handler()
 * also accepts: PHP 8.4 deprecated that form, and the deprecation notice is
 * printed before any HTML, which sends headers early and then stops the
 * session from starting at all. */
final class DatabaseSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    /* '' for a session we have never seen, which is what PHP expects. */
    public function read(string $id): string
    {
        try {
            $stmt = getPDO()->prepare('SELECT payload FROM sessions WHERE id = ?');
            $stmt->execute([$id]);

            return (string) ($stmt->fetchColumn() ?: '');
        } catch (Throwable $ex) {
            error_log('session read: ' . $ex->getMessage());

            return '';
        }
    }

    /* One statement, so a new session and an updated one take the same path
     * and two parallel requests cannot race to insert. */
    public function write(string $id, string $data): bool
    {
        try {
            getPDO()->prepare(
                'INSERT INTO sessions (id, payload, last_seen) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE payload = VALUES(payload), last_seen = VALUES(last_seen)'
            )->execute([$id, $data, time()]);

            return true;
        } catch (Throwable $ex) {
            error_log('session write: ' . $ex->getMessage());

            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            getPDO()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);

            return true;
        } catch (Throwable $ex) {
            error_log('session destroy: ' . $ex->getMessage());

            return false;
        }
    }

    /* PHP calls this occasionally, so no cron job is needed. */
    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = getPDO()->prepare('DELETE FROM sessions WHERE last_seen < ?');
            $stmt->execute([time() - $max_lifetime]);

            return $stmt->rowCount();
        } catch (Throwable $ex) {
            error_log('session gc: ' . $ex->getMessage());

            return 0;
        }
    }
}


/* Registers the database handler. Must run before session_start(). Returns
 * false if the database is unreachable, so the caller can fall back. */
function use_database_sessions(): bool
{
    try {
        getPDO();
    } catch (Throwable $ex) {
        error_log('database sessions unavailable: ' . $ex->getMessage());

        return false;
    }

    return session_set_save_handler(new DatabaseSessionHandler(), true);
}
