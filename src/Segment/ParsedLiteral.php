<?php

namespace Nandan108\PropPath\Segment;

final class ParsedLiteral extends ParsedSegment
{
    public function __construct(
        public int|string $value,
        public ParsedPath|string|null $key,
    ) {
        $this->raw = (string) $value;
    }
}
