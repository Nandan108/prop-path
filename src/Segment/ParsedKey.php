<?php

namespace Nandan108\PropPath\Segment;

use Nandan108\PropPath\Support\ThrowMode;

final class ParsedKey extends ParsedSegment
{
    public function __construct(
        public int|string $key,
        ?ThrowMode $mode = null,
        string $raw = '',
        public bool $preserveKey = false,
    ) {
        $this->raw = $raw;
        $this->mode = $mode;
    }
}
