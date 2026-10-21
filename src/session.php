<?php
/* Where sessions are kept.
 *
 * PHP stores sessions as files in a temp directory. That is fine on one
 * server and useless on a serverless host: each request may run in a fresh
 * container, so the next request cannot read what the last one wrote.
 *
 * On this site that would not merely break "stay signed in" — every form
 * carries a CSRF token kept in the session, so EVERY submission would come
 * back "your session expired". Ordering, booking, paying and the kitchen's
 * buttons would all fail.
 *
 * So when there is no shared disk, sessions go in the database, which every
 * container does share. Same API, same $_SESSION, different shelf.
 */


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


/**
 * Registers the database session handler.
 *
 * Must run before session_start(). Returns false if the database cannot be
 * reached, so the caller can fall back to files rather than failing to
 * start a session at all.
 */
function use_database_sessions(): bool
{
    try {
        getPDO();
    } catch (Throwable $ex) {
        error_log('database sessions unavailable: ' . $ex->getMessage());
        return false;
    }

    return session_set_save_handler(
        // open
        static fn (): bool => true,

        // close
        static fn (): bool => true,

        // read — '' for a session we have never seen, which is what PHP wants
        static function (string $id): string {
            try {
                $stmt = getPDO()->prepare('SELECT payload FROM sessions WHERE id = ?');
                $stmt->execute([$id]);

                return (string) ($stmt->fetchColumn() ?: '');
            } catch (Throwable $ex) {
                error_log('session read: ' . $ex->getMessage());
                return '';
            }
        },

        // write — one statement, so a new session and an updated one take the
        // same path and two parallel requests cannot race to insert
        static function (string $id, string $payload): bool {
            try {
                getPDO()->prepare(
                    'INSERT INTO sessions (id, payload, last_seen) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE payload = VALUES(payload), last_seen = VALUES(last_seen)'
                )->execute([$id, $payload, time()]);

                return true;
            } catch (Throwable $ex) {
                error_log('session write: ' . $ex->getMessage());
                return false;
            }
        },

        // destroy
        static function (string $id): bool {
            try {
                getPDO()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
                return true;
            } catch (Throwable $ex) {
                error_log('session destroy: ' . $ex->getMessage());
                return false;
            }
        },

        // gc — PHP calls this occasionally; no cron needed
        static function (int $maxLifetime): int {
            try {
                $stmt = getPDO()->prepare('DELETE FROM sessions WHERE last_seen < ?');
                $stmt->execute([time() - $maxLifetime]);

                return $stmt->rowCount();
            } catch (Throwable $ex) {
                error_log('session gc: ' . $ex->getMessage());
                return 0;
            }
        }
    );
}
