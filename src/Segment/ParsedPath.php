<?php

namespace Nandan108\PropPath\Segment;

final class ParsedPath
{
    /** @psalm-suppress PossiblyUnusedProperty */
    public string $raw;

    /**
     * @param ParsedSegment[] $segments
     **/
    public function __construct(
        public array $segments,
        public ?string $key,
        /** @psalm-suppress PossiblyUnusedProperty */
        string $raw,
    ) {
        $this->raw = $raw;
    }
}
