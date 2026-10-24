<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Data;

/**
 * What happened to each queued record a moderator acted on.
 *
 * @internal
 */
final readonly class ReviewOutcome
{
    /**
     * @param  int  $resolved  decisions recorded through the core
     * @param  int  $skipped  records reviewed by someone else since the page loaded, removed, or not the user's to review
     * @param  list<string>  $failures  the core's reason for each record it refused
     */
    public function __construct(
        public int $resolved = 0,
        public int $skipped = 0,
        public array $failures = [],
    ) {}

    public function withResolved(): self
    {
        return new self($this->resolved + 1, $this->skipped, $this->failures);
    }

    public function withSkipped(): self
    {
        return new self($this->resolved, $this->skipped + 1, $this->failures);
    }

    public function withFailure(string $reason): self
    {
        return new self($this->resolved, $this->skipped, [...$this->failures, $reason]);
    }
}
