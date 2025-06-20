<?php

namespace Nandan108\PropPath\Segment;

use Nandan108\PropPath\Support\ThrowMode;

abstract class ParsedSegment
{
    public string $raw = '';
    public ?ThrowMode $mode = null;
}
