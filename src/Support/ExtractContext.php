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
     * @var \Closure(string, list<array{string, ThrowMode}>): never
     */
    public \Closure $failWith;

    /**
     * The callable to invoke when parsing a path segment fails.
     *
     * @var \Closure(string, ?string): never
     */
    public \Closure $failParseWith;

    /**
     * The stack of keys traversed so far in the path.
     * This is used to format error messages.
     *
     * @var list<array{string, ThrowMode}>
     */
    public array $keyStack = [];

    public array $roots;

    /**
     * Constructs a new ExtractContext instance.
     *
     * @param \Closure(string, ?string): never $failParseWith
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public string|array $paths,
        array $roots = [],
        public ThrowMode $throwMode = ThrowMode::NEVER,
        ?\Closure $failParseWith = null,
    ) {
        // Ensure the failParseWith closure is bound to the current instance.
        if ($failParseWith) {
            /** @var \Closure(string, ?string): never */
            $failParseWith = $failParseWith->bindTo($this, self::class) ?? $failParseWith;
        }

        $this->roots = $roots;

        $this->failParseWith = $failParseWith ?? function (string $msg, ?string $parsed = null): never {
            /** @psalm-suppress RiskyTruthyFalsyComparison */
            $fullPathJson = json_encode($this->paths) ?: '[$paths not serializable to JSON]';

            /** @psalm-suppress RiskyTruthyFalsyComparison, PossiblyFalseOperand */
            $parsedJson = $parsed ? json_encode($parsed) : null;
            $msg = "Failed parsing $fullPathJson".(null !== $parsed ? " near $parsedJson" : '').": $msg.";
            throw new SyntaxError($msg);
        };

        $this->failWith = function (string $msg, array $keyStack): never {
            $keyStack = array_map(
                /** @param array{?string, ?ThrowMode} $item */
                fn (array $item): ?string => $item[0],
                $keyStack
            );
            $keyStack = array_filter($keyStack, fn ($item): bool => null !== $item && '' !== $item);
            $lastKey = array_pop($keyStack);
            $keyStackStr = implode('.', $keyStack).($keyStack ? '.' : '')."`$lastKey`";

            throw new EvaluationError("Path segment $keyStackStr $msg.");
        };
    }

    /**
     * Prepare the context for evaluation by setting the roots and possibly the failWith closure.
     *
     * @param ?\Closure(string, list<array{string, ThrowMode}>): never $failWith
     *
     * @throws EvaluationError
     * @throws \InvalidArgumentException
     */
    public function prepareForEval(array $roots, ?\Closure $failWith = null): void
    {
        $this->keyStack = [];
        // Ensure the failWith closure is bound to the current instance so it has acccess to the whole context if necessary.
        if ($failWith) {
            /** @var \Closure(string, list<array{string, ThrowMode}>): never */
            $failWith = $failWith->bindTo($this, self::class) ?? $failWith;
        }

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
    }

    public function push(?string $key = null, ?ThrowMode $mode = null): static
    {
        array_push($this->keyStack, [$key ?? '', $mode ?? $this->getCurrentMode()]);

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
        ($this->failWith)($message, $this->keyStack);
    }

    public function failParse(TokenStream $ts, string $message, int $additional = 0): never
    {
        ($this->failParseWith)($message, $ts->valueSince(0, $additional));
    }
}
