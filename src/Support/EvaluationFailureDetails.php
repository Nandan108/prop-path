<?php

namespace Nandan108\PropPath\Support;

use Nandan108\PropPath\Exception\EvaluationErrorCode;

final class EvaluationFailureDetails
{
    /**
     * @param array<array-key, mixed>        $parameters
     * @param array<array-key, mixed>        $debug
     * @param array<array-key, mixed>        $roots
     * @param list<array{string, ThrowMode}> $keyStack
     * @param array<array-key, mixed>        $valueStack
     */
    public function __construct(
        public readonly EvaluationErrorCode $code,
        public readonly array $parameters = [],
        public readonly array $debug = [],
        public readonly string|array $paths = '',
        public readonly ThrowMode $startingThrowMode = ThrowMode::NEVER,
        public readonly ThrowMode $currentMode = ThrowMode::NEVER,
        public readonly array $roots = [],
        public readonly array $keyStack = [],
        public readonly array $valueStack = [],
    ) {
    }

    /**
     * Compute the property path from the key stack. This is used to format error messages.
     *
     * @template T of ?string
     *
     * @param T $fallbackIfEmpty
     *
     * @return string|T
     */
    public function getPropertyPath(bool $backtickOnLastSegment = false, ?string $fallbackIfEmpty = null): ?string
    {
        $keyStack = array_filter(
            array_map(
                /** @param array{?string, ?ThrowMode} $item */
                fn (array $item): ?string => $item[0],
                $this->keyStack,
            ),
            fn ($item): bool => null !== $item && '' !== $item
        );

        if (!$keyStack) {
            return $fallbackIfEmpty;
        }

        if (!$backtickOnLastSegment) {
            return implode('.', $keyStack);
        }

        $lastKey = array_pop($keyStack);

        return implode('.', $keyStack).($keyStack ? '.' : '')."`$lastKey`";
    }
}
