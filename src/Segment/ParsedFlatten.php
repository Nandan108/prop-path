<?php

namespace Nandan108\PropPath\Segment;

use Nandan108\PropPath\Support\ThrowMode;

final class ParsedFlatten extends ParsedSegment
{
    public function __construct(
        ?ThrowMode $mode,
        string $raw = '',
        public bool $preserveKeys = false,
    ) {
        $this->raw = $raw;
        $this->mode = $mode;
    }
}
