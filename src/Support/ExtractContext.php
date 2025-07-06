<?php

namespace Nandan108\PropPath\Support;

use Nandan108\PropPath\Exception\EvaluationError;
use Nandan108\PropPath\Exception\SyntaxError;
use Nandan108\PropPath\Parser\TokenStream;

/**
 * ExtractContext is used to hold the context of the extraction process.
 */
final class ExtractContext
{
    /**
     * The callable to invoke when evaluating a path segment fails.
     *
     * @var \Closure(string, ExtractContext): never
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
    public array $valueStack = [];

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

        $this->failWith = function (string $msg, ExtractContext $context): never {
            throw new EvaluationError($context->getEvalErrorMessage($msg));
        };
    }

    public function getEvalErrorMessage(string $message): string
    {
        $keyStack = array_map(
            /** @param array{?string, ?ThrowMode} $item */
            fn (array $item): ?string => $item[0],
            $this->keyStack,
        );
        $keyStack = array_filter($keyStack, fn ($item): bool => null !== $item && '' !== $item);
        $lastKey = array_pop($keyStack);
        $keyStackStr = implode('.', $keyStack).($keyStack ? '.' : '')."`$lastKey`";

        return "Path segment $keyStackStr $message.";
    }

    /**
     * Prepare the context for evaluation by setting the roots and possibly the failWith closure.
     *
     * @param ?\Closure(string, ExtractContext): never $failWith
     *
     * @throws EvaluationError
     * @throws \InvalidArgumentException
     */
    public function prepareForEval(array $roots, ?\Closure $failWith = null): void
    {
        // Reset the key and value stacks.
        $this->keyStack = [];

        // Override the failWith closure if provided.
        if ($failWith) {
            $this->failWith = $failWith;
        }

        // Check root validity, then set the roots for the evaluation.
        if (!$roots) {
            throw new \InvalidArgumentException('Roots must be a non-empty array.');
        }
        foreach (array_keys($roots) as $key) {
            if (!is_string($key) || !preg_match('/^[a-z_][\w-]*$/i', $key)) {
                throw new \InvalidArgumentException('Roots keys must be identifiers (strings matching \'/^[a-z_][\w-]*$/i\').');
            }
        }
        $this->roots = $roots;
    }

    /**
     * Push a key or mode onto the key stack, or a value on the value stack.
     *
     * @template T
     *
     * @param T $value
     *
     * @return T
     */
    public function push(?string $key = null, ?ThrowMode $mode = null, mixed $value = null): mixed
    {
        if (null !== $key || null !== $mode) {
            array_push($this->keyStack, [$key ?? '', $mode ?? $this->getCurrentMode()]);
        }

        // If a value is provided, push it onto the value stack.
        if (func_num_args() >= 3) {
            array_unshift($this->valueStack, $value);
        }

        /** @psalm-var T */
        return $value;
    }

    /**
     * Reset the key and value stacks.
     *
     * @param list<list{string, ThrowMode}> $keyStack
     */
    public function resetStack(array $keyStack = [], array $valueStack = []): void
    {
        $this->keyStack = $keyStack;
        $this->valueStack = $valueStack;
    }

    /**
     * Get the current mode based on the last key in the stack.
     * If the stack is empty, return the default throw mode.
     */
    protected function getCurrentMode(): ThrowMode
    {
        return $this->keyStack ? end($this->keyStack)[1] : $this->throwMode;
    }

    /**
     * Pop both:
     * - the last key from the key stack
     * - the last value from the value stack
     */
    public function pop(bool $key = false, bool $value = false): void
    {
        if (!$key && !$value) {
            $key = $value = true;
        }

        if ($key) {
            array_pop($this->keyStack);
        }

        // Popping the value stack only makes sense during evaluation, but
        // doesn't cost much to do it here.
        if ($value) {
            array_shift($this->valueStack);
        }
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
        ($this->failWith)($message, $this);
    }

    public function failParse(TokenStream $ts, string $message, int $additional = 0): never
    {
        ($this->failParseWith)($message, $ts->valueSince(0, $additional));
    }
}
