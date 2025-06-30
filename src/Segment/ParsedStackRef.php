<?php

namespace Nandan108\PropPath\Segment;

use Nandan108\PropPath\Support\ThrowMode;

final class ParsedStackRef extends ParsedSegment
{
    public function __construct(
        public int $index = 0,
        ?ThrowMode $mode,
        string $raw = '',
    ) {
        $this->raw = $raw;
        $this->mode = $mode;
    }
}
