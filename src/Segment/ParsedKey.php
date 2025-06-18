<?php

namespace Nandan108\PropPath\Segment;

use Nandan108\PropPath\Support\ThrowMode;

final class ParsedKey extends ParsedSegment
{
    /** @psalm-suppress PossiblyUnusedProperty */
    public string $raw;

    public function __construct(
        public int|string $key,
        public ?ThrowMode $mode = null,
        string $raw = '',
        public bool $preserveKey = false,
    ) {
        $this->raw = $raw;
    }
}
