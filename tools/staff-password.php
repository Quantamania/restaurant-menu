<?php
/* Makes the password hash for the kitchen screen. */

// Command line only.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo "\n  Kitchen screen password\n";
echo "  ───────────────────────\n\n";

/* Reads a line from the terminal without echoing it. */
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
    // Ten is not arbitrary.
    fwrite(STDERR, "\n  Too short — use at least 10 characters.\n\n");
    exit(1);
}

$again = prompt_hidden('  Type it again:      ');

if (!hash_equals($first, $again)) {
    fwrite(STDERR, "\n  Those did not match. Nothing was changed.\n\n");
    exit(1);
}

// PASSWORD_DEFAULT rather than a named algorithm.
$hash = password_hash($first, PASSWORD_DEFAULT);

echo "\n  Done. Paste this into src/config.php, inside the 'staff' block:\n\n";
echo "    'password_hash' => '" . $hash . "',\n\n";
echo "  Or set it in the environment instead:\n\n";
echo "    STAFF_PASSWORD_HASH='" . $hash . "'\n\n";
echo "  The password itself is not stored anywhere — if you forget it, run\n";
echo "  this again to set a new one.\n\n";
