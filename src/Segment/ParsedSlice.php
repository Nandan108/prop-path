<?php

namespace Nandan108\PropPath\Segment;

use Nandan108\PropPath\Support\ThrowMode;

final class ParsedSlice extends ParsedSegment
{
    public function __construct(
        public ?int $start,
        public ?int $end,
        public ?ThrowMode $mode,
        public string $raw,
        public bool $preserveKey,
    ) {
    }
}
