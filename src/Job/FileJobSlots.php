<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/**
 * JobSlots for one host: $slots lock files in a directory, each held with flock. The operating system frees
 * a slot when its process dies, so a killed worker never keeps one.
 */
final class FileJobSlots implements JobSlots
{
    /**
     * @throws \InvalidArgumentException when $slots is under 1
     */
    public function __construct(
        private readonly string $dir,
        private readonly int $slots,
    ) {
        if ($slots < 1) {
            throw new \InvalidArgumentException('There must be at least one job slot.');
        }
    }

    public function take(): ?JobSlot
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create the job slots directory {$this->dir}.");
        }
        for ($i = 0; $i < $this->slots; ++$i) {
            $handle = @fopen("{$this->dir}/job-slot-$i.lock", 'c');
            if (false === $handle) {
                throw new \RuntimeException("Cannot open a job slot in {$this->dir}.");
            }
            if (flock($handle, \LOCK_EX | \LOCK_NB)) {
                return new class($handle) implements JobSlot {
                    /** @param ?resource $handle */
                    public function __construct(private mixed $handle)
                    {
                    }

                    public function release(): void
                    {
                        if (\is_resource($this->handle)) {
                            flock($this->handle, \LOCK_UN);
                            fclose($this->handle);
                        }
                        $this->handle = null;
                    }

                    public function __destruct()
                    {
                        $this->release();
                    }
                };
            }
            fclose($handle);
        }

        return null;
    }
}
