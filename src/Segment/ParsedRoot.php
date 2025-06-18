<?php

namespace Nandan108\PropPath\Segment;

final class ParsedRoot extends ParsedSegment
{
    public function __construct(
        public ?string $key,
    ) {
    }
}
