<?php
/* ===========================================================================
 * Makes the password hash for the kitchen screen.
 *
 *     php tools/staff-password.php
 *
 * It asks for a password, hides it while you type, and prints the line to
 * paste into src/secrets.php.
 *
 * WHY A SCRIPT RATHER THAN A ONE-LINER
 *
 * The obvious way is:
 *
 *     php -r 'echo password_hash("hunter2", PASSWORD_DEFAULT);'
 *
 * — and that leaves the password sitting in ~/.bash_history for anyone who
 * later gets a shell on the machine. This reads it from the terminal
 * instead, so it is never written anywhere.
 * =========================================================================== */

// Command line only. If this were ever reachable over HTTP it would be a
// tool for generating admin credentials, served to the public.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo "\n  Kitchen screen password\n";
echo "  ───────────────────────\n\n";

/**
 * Reads a line from the terminal without echoing it.
 *
 * `stty -echo` is the portable Unix way. It is restored in a finally block
 * so a Ctrl-C part way through does not leave the terminal unable to show
 * what you type for the rest of the session.
 */
function prompt_hidden(string $label): string
{
    echo $label;

    $hasStty = stripos(PHP_OS_FAMILY, 'win') === false
            && shell_exec('command -v stty 2>/dev/null') !== null;

    if (!$hasStty) {
        // Windows without a POSIX shell: it has to be typed in the clear.
        echo "\n  (this terminal cannot hide what you type)\n  ";
        return rtrim((string) fgets(STDIN), "\r\n");
    }

    $before = shell_exec('stty -g 2>/dev/null');

    try {
        shell_exec('stty -echo 2>/dev/null');
        $value = rtrim((string) fgets(STDIN), "\r\n");
    } finally {
        if ($before !== null) {
            shell_exec('stty ' . trim($before) . ' 2>/dev/null');
        } else {
            shell_exec('stty echo 2>/dev/null');
        }
    }

    echo "\n";

    return $value;
}

$first = prompt_hidden('  Choose a password:  ');

if (strlen($first) < 10) {
    // Ten is not arbitrary: this one password guards every customer's name,
    // phone number and address, and it is typed on a shared tablet that
    // several people will watch.
    fwrite(STDERR, "\n  Too short — use at least 10 characters.\n\n");
    exit(1);
}

$again = prompt_hidden('  Type it again:      ');

if (!hash_equals($first, $again)) {
    fwrite(STDERR, "\n  Those did not match. Nothing was changed.\n\n");
    exit(1);
}

// PASSWORD_DEFAULT rather than a named algorithm, so this follows PHP's
// current recommendation as versions move on. The salt and the cost are
// stored inside the hash, which is why there is nothing else to keep.
$hash = password_hash($first, PASSWORD_DEFAULT);

echo "\n  Done. Paste this into src/secrets.php, inside the 'staff' block:\n\n";
echo "    'password_hash' => '" . $hash . "',\n\n";
echo "  Or set it in the environment instead:\n\n";
echo "    STAFF_PASSWORD_HASH='" . $hash . "'\n\n";
echo "  The password itself is not stored anywhere — if you forget it, run\n";
echo "  this again to set a new one.\n\n";
