<?php

namespace Nandan108\PropPath\Segment;

use Nandan108\PropPath\Support\ThrowMode;

final class ParsedBracket extends ParsedSegment
{
    /**
     * @param list<array<ParsedLiteral|ParsedPath>> $chains
     */
    public function __construct(
        public array $chains,
        ?ThrowMode $mode,
        string $raw = '',
        public bool $preserveKey = false,
    ) {
        $this->raw = $raw;
        $this->mode = $mode;
    }
}
