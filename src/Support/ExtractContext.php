<?php

namespace Nandan108\PropPath\Support;

use Nandan108\PropPath\Exception\EvaluationError;
use Nandan108\PropPath\Exception\SyntaxError;
use Nandan108\PropPath\Parser\TokenStream;

/**
 * ExtractContext is used to hold the context of the extraction process.
 *
 * @internal
 */
final class ExtractContext
{
    /**
     * The callable to invoke when evaluating a path segment fails.
     *
     * @var \Closure(array, string, array, string): never
     */
    public \Closure $failWith;

    /**
     * The callable to invoke when parsing a path segment fails.
     *
     * @var \Closure(string, string|null): never
     */
    public \Closure $failParseWith;

    /**
     * The stack of keys traversed so far in the path.
     * This is used to format error messages.
     *
     * @var array<int, array{string, ThrowMode}>
     */
    public array $keyStack = [];

    /**
     * Constructs a new HandleFail instance.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public array $roots,
        public string $fullPath,
        public ThrowMode $throwMode,
        ?callable $failWith = null,
        ?callable $failParseWith = null,
    ) {
        $this->failWith = $failWith ?? function (array $roots, string $fullPath, array $keyStack, string $msg): never {
            $keyStack = array_map(fn ($item): mixed => $item[0], $keyStack);
            $keyStack = array_filter($keyStack, fn ($item): bool => null !== $item && '' !== $item);
            $lastKey = array_pop($keyStack);
            $keyStackStr = implode('.', $keyStack).($keyStack ? '.' : '')."`$lastKey`";

            throw new EvaluationError("Path segment $keyStackStr $msg.");
        };

        $this->failParseWith = $failParseWith ?? function (string $msg, ?string $parsed = null) use ($fullPath): never {
            $fullPathJson = json_encode($fullPath);
            /** @psalm-suppress RiskyTruthyFalsyComparison, PossiblyFalseOperand */
            $msg = "Failed parsing $fullPathJson".($parsed ? ' near '.json_encode($parsed) : '').": $msg.";
            throw new SyntaxError($msg);
        };

        is_callable($this->failWith) or throw new \InvalidArgumentException('The failWith argument must be a callable.');
    }

    public function prepareForEval(array $roots, ?callable $failWith = null): static
    {
        $this->keyStack = [];
        $this->failWith = $failWith ?? $this->failWith;

        $this->roots = $roots;
        if (!$roots) {
            throw new \InvalidArgumentException('Roots must be a non-empty array.');
        }
        foreach (array_keys($roots) as $key) {
            if (!is_string($key) || !preg_match('/^[a-z_][\w-]*$/i', $key)) {
                throw new \InvalidArgumentException('Roots keys must be identifiers (strings matching \'/^[a-z_][\w-]*$/i\').');
            }
        }

        return $this;
    }

    public function push(?string $key = null, ?ThrowMode $mode = null): static
    {
        array_push($this->keyStack, [$key ?? '', $mode ?? $this->getCurrentMode()]);

        return $this;
    }

    public function getStackLevel(): int
    {
        return count($this->keyStack);
    }

    public function resetStackLevel(int $level): static
    {
        // Ensure the level is valid: it must be non-negative and not exceed the current stack size.
        // Using a one-liner syntax here to have full coverage without having to test never-happening sad path
        !$level || isset($this->keyStack[$level - 1]) or throw new \OutOfRangeException("Invalid stack level: $level");

        $this->keyStack = array_slice($this->keyStack, 0, $level);

        return $this;
    }

    /**
     * Get the current mode based on the last key in the stack.
     * If the stack is empty, return the default throw mode.
     */
    protected function getCurrentMode(): ThrowMode
    {
        return $this->keyStack ? end($this->keyStack)[1] : $this->throwMode;
    }

    public function pop(): static
    {
        array_pop($this->keyStack);

        return $this;
    }

    public function currentMode(): ThrowMode
    {
        return end($this->keyStack)[1] ?? $this->throwMode;
    }

    /**
     * @phpstan-return never
     *
     * @psalm-return never
     */
    public function fail(string $message): never
    {
        ($this->failWith)($this->roots, $this->fullPath, $this->keyStack, $message);
    }

    public function failParse(TokenStream $ts, string $message, int $additional = 0): never
    {
        ($this->failParseWith)($message, $ts->valueSince(0, $additional));
    }
}
