<?php

namespace Nandan108\PropPath\Segment;

use Nandan108\PropPath\Support\ThrowMode;

final class ParsedOnEach extends ParsedSegment
{
    public function __construct(
        public readonly int $depth, // 1 for *, -1 for **
        ?ThrowMode $mode,
        public readonly bool $preserveKey,
        string $raw,
    ) {
        $this->raw = $raw;
        $this->mode = $mode;
    }
}
