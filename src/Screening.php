<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * A site's own lists on AddressCheck, as one listener: the payer (when it sells) and payTo (when it buys).
 *
 * Every check, in order, the first refusal decides:
 * 1. OFAC's SDN list (OfacSdn), asked by the library before any listener: nothing here can let a sanctioned
 *    address through, the allowlist included;
 * 2. the site's allowlist ($dir/allow.txt): skips 3 and 4, never OFAC nor a listener registered apart
 *    (TokenBlacklist);
 * 3. the site's denylist ($dir/deny.txt): refused with address_denied;
 * 4. Chainalysis' sanctions API (ChainalysisApi), when one is given: asked when the verdict is read (just
 *    before settling, or signing), its answers cached for $cacheTtl seconds when there is a $cache.
 *
 * Both lists are AddressList's text format: one address per line, # for comments. A list file that does not
 * exist is empty; one that cannot be read refuses everyone.
 *
 * When the API cannot answer (a timeout, a 5xx, a reply that is not one), $unavailable decides: "reject" (the
 * default) or "allow", which lets the address pass and reports ScreeningSkipped. A 4xx (a wrong key) always
 * rejects.
 */
final class Screening
{
    public const string REJECT = 'reject';
    public const string ALLOW = 'allow';
    private const array LISTS = ['allow', 'deny'];

    /**
     * @param string $dir where allow.txt and deny.txt live; created on the first set()
     *
     * @throws \InvalidArgumentException when $unavailable is neither "reject" nor "allow"
     */
    public function __construct(
        private readonly string $dir,
        private readonly ?ChainalysisApi $api = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly int $cacheTtl = 3600,
        private readonly string $unavailable = self::REJECT,
    ) {
        if (!\in_array($unavailable, [self::REJECT, self::ALLOW], true)) {
            throw new \InvalidArgumentException(\sprintf('Screening: "%s" is no policy for an API that cannot answer; use "reject" or "allow".', $unavailable));
        }
    }

    public function __invoke(AddressCheck $check): void
    {
        $address = $check->address;
        if ($this->listed('allow', $address)) {
            return;
        }
        if ($this->listed('deny', $address)) {
            $check->reject('address_denied');

            return;
        }
        if (null === $this->api) {
            return;
        }
        $item = $this->cached($address);
        if (true === $item?->isHit()) {
            if (true === $item->get()) {
                $check->reject(OfacSdn::REASON);
            }

            return;
        }
        $check->defer(function () use ($check, $item): ?string {
            try {
                $sanctioned = $this->ask($check->address, $item);
            } catch (\Throwable $e) {
                // A 4xx is a key of ours that is wrong: never let it pass.
                if (self::ALLOW !== $this->unavailable || $e instanceof \LogicException) {
                    throw $e;
                }
                $check->skip(ChainalysisApi::class, $e);

                return null;
            }

            return $sanctioned ? OfacSdn::REASON : null;
        });
    }

    /**
     * Why an address would be refused, or NULL: everything above, OFAC ($ofac, or the list shipped with the
     * library) and the API included. For a command or an admin page. It waits for the API, and throws when
     * the API cannot answer, whatever the policy.
     */
    public function check(string $address, ?OfacSdn $ofac = null): ?string
    {
        return match (true) {
            ($ofac ?? OfacSdn::bundled())->listed($address) => OfacSdn::REASON,
            $this->listed('allow', $address) => null,
            $this->listed('deny', $address) => 'address_denied',
            null === $this->api => null,
            default => $this->sanctioned($address) ? OfacSdn::REASON : null,
        };
    }

    /**
     * Puts an address on the allow or the deny list (and off the other one), or on neither when $list is null.
     *
     * @throws \InvalidArgumentException when $address holds no address, or $list is not a list
     * @throws \RuntimeException         when the lists cannot be locked or written
     */
    public function set(string $address, ?string $list): void
    {
        $address = AddressList::parse($address)[0] ?? throw new \InvalidArgumentException('No address.');
        if (null !== $list && !\in_array($list, self::LISTS, true)) {
            throw new \InvalidArgumentException("No such list: $list.");
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create {$this->dir}.");
        }
        // Read-modify-write: two admins at once must not lose an entry.
        $lock = @fopen($this->dir.'/own.lock', 'c') ?: throw new \RuntimeException("Cannot lock {$this->dir}/own.lock.");
        flock($lock, \LOCK_EX);
        try {
            $own = $this->own();
            foreach (self::LISTS as $name) {
                $own[$name] = array_values(array_diff($own[$name], [$address]));
            }
            if (null !== $list) {
                $own[$list][] = $address;
            }
            foreach (self::LISTS as $name) {
                $this->write($name.'.txt', "# Our {$name}list, one address per line.\n".implode("\n", $own[$name])."\n");
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{allow: list<string>, deny: list<string>}
     */
    public function own(): array
    {
        return ['allow' => $this->read('allow'), 'deny' => $this->read('deny')];
    }

    private function sanctioned(string $address): bool
    {
        $item = $this->cached($address);

        return true === $item?->isHit() ? true === $item->get() : $this->ask($address, $item);
    }

    private function listed(string $list, string $address): bool
    {
        $file = $this->dir.'/'.$list.'.txt';

        return file_exists($file) && (new AddressList([$file]))->listed($address);
    }

    /**
     * @return list<string>
     */
    private function read(string $list): array
    {
        $file = $this->dir.'/'.$list.'.txt';
        if (!file_exists($file)) {
            return [];
        }
        $text = @file_get_contents($file);

        return AddressList::parse(false === $text ? throw new \RuntimeException("Cannot read $file.") : $text);
    }

    private function cached(string $address): ?CacheItemInterface
    {
        return $this->cache?->getItem('x402_screening_'.hash('sha256', strtolower($address)));
    }

    /**
     * The API's answer, cached; never caches a failure.
     */
    private function ask(string $address, ?CacheItemInterface $item): bool
    {
        \assert(null !== $this->api);
        $sanctioned = $this->api->sanctioned($address);
        if (null !== $item) {
            $this->cache?->save($item->set($sanctioned)->expiresAfter($this->cacheTtl));
        }

        return $sanctioned;
    }

    /**
     * Through a unique temp file and a rename: a check never reads half a file. Readable by the web and CLI users.
     */
    private function write(string $name, string $content): void
    {
        $file = $this->dir.'/'.$name;
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false === @file_put_contents($tmp, $content) || !@chmod($tmp, 0644) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write $file.");
        }
    }
}
