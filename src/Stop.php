<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * A stop switch for payments, by hand: while the flag file exists, the seller's middlewares answer "paused"
 * before they verify, claim or charge anything new, and the buyer's clients sign nothing. Pass the same file
 * to both sides of a deployment and one command stops them all.
 *
 * It is a file on purpose: any process sees it at once, `touch` is enough in an emergency, and nothing has to
 * be running to set it. Nothing sets it by itself: a person decides.
 *
 * What it does not do: recall a payment already signed (the seller may still settle it) or a settle already
 * sent. A payment the seller's ledger already holds is still delivered: it was paid. It checks the file each
 * time, so engaging it takes effect on the next request, in long-running workers too.
 *
 * It fails closed where it can tell: anything at the path (a directory, a link) counts as engaged, and so does
 * a flag directory this process cannot search, since it could not see a file there. The path must be absolute,
 * so every process looks at the same place, and in a directory only the application's user can write to
 * (never /tmp): engage() refuses a link, a file somebody else owns, and a directory others can write to.
 */
final class Stop
{
    /** The reason a refused purchase reports (PurchaseOutcome) and a paused sale answers with. */
    public const string REASON = 'payments_paused';

    public function __construct(private readonly string $file)
    {
        if (!str_starts_with($file, '/') && 1 !== preg_match('~^[A-Za-z]:[\\\\/]~', $file)) {
            throw new \InvalidArgumentException('The stop switch needs an absolute path: a relative one is not the same place in every process.');
        }
    }

    public function engaged(): bool
    {
        clearstatcache(true, $this->file);
        if (file_exists($this->file) || is_link($this->file)) {
            return true;
        }
        $dir = \dirname($this->file);

        // Nobody could set the flag there, or this process cannot see it: it must not look like "off".
        return is_dir($dir) && !is_executable($dir);
    }

    /**
     * @param string $why shown to whoever reads the file; not sent to buyers
     */
    public function engage(string $why = ''): void
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('The stop switch cannot be engaged: its directory cannot be created.');
        }
        if (is_link($this->file)) {
            throw new \RuntimeException('The stop switch cannot be engaged: its path is a link.');
        }
        if (0 !== (fileperms($dir) & 0022) || !self::mine($dir)) {
            throw new \RuntimeException('The stop switch cannot be engaged: its directory must belong to this user and not be writable by others.');
        }
        $handle = @fopen($this->file, 'x');
        if (false === $handle) {
            // Already there: rewritten only when it is a plain file of ours, never one somebody else made.
            $handle = is_file($this->file) && self::mine($this->file) ? @fopen($this->file, 'c') : false;
        }
        if (false === $handle) {
            throw new \RuntimeException('The stop switch cannot be engaged: its file cannot be created or is not ours.');
        }
        try {
            flock($handle, LOCK_EX);
            ftruncate($handle, 0);
            fwrite($handle, date('c')."\n".$why."\n");
        } finally {
            fclose($handle);
        }
        @chmod($this->file, 0600);
    }

    public function release(): void
    {
        if (is_dir($this->file) && !is_link($this->file)) {
            throw new \RuntimeException('The stop switch cannot be released: its path is a directory.');
        }
        if (!@unlink($this->file) && (is_file($this->file) || is_link($this->file))) {
            throw new \RuntimeException('The stop switch cannot be released: its file cannot be removed.');
        }
    }

    /**
     * When it was engaged and why, as written, or NULL when it is off or holds nothing readable.
     */
    public function note(): ?string
    {
        $note = is_file($this->file) ? @file_get_contents($this->file) : false;

        return false === $note ? null : trim($note);
    }

    private static function mine(string $path): bool
    {
        // Without POSIX there is no owner to compare: the directory check above is all there is.
        return !\function_exists('posix_geteuid') || fileowner($path) === posix_geteuid();
    }
}
