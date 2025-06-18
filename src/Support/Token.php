<?php

namespace Nandan108\PropPath\Support;

final class Token
{
    public readonly string $value;

    public function __construct(
        public readonly TokenType $type,
        ?string $value = null,
    ) {
        $this->value = $value ?? $type->value;
    }
}
