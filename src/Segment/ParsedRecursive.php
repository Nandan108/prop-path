<?php

namespace Nandan108\PropPath\Segment;

use Nandan108\PropPath\Support\ThrowMode;

final class ParsedRecursive extends ParsedSegment
{
    public function __construct(
        public readonly int $depth, // 1 for *, -1 for **
        ?ThrowMode $mode,
        public readonly bool $preserveKey,
    ) {
        $this->raw = 1 === $depth ? '*' : '**';
        $this->mode = $mode;
    }
}
