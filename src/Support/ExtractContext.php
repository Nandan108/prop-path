<?php

namespace Nandan108\PropPath\Support;

use Nandan108\PropPath\Exception\EvaluationError;
use Nandan108\PropPath\Exception\EvaluationErrorCode;
use Nandan108\PropPath\Exception\SyntaxError;
use Nandan108\PropPath\Parser\TokenStream;

/**
 * ExtractContext is used to hold the context of the extraction process.
 */
final class ExtractContext
{
    /** Sentinel used to detect omitted arguments in failEval(). */
    private const NOT_PROVIDED = '__PROPPATH_NOT_PROVIDED__';

    /**
     * The callable to invoke when evaluating a path segment fails.
     *
     * @var \Closure(string, EvaluationFailureDetails): never
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
     * Default callable to invoke when evaluating a path segment fails.
     *
     * @var \Closure(string, EvaluationFailureDetails): never
     */
    private \Closure $defaultFailWith;

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

        $this->defaultFailWith = function (string $msg, EvaluationFailureDetails $failure): never {
            throw new EvaluationError(message: 'Path segment '.$failure->getPropertyPath(true, '``')." $msg.", errorCode: $failure->code, messageParameters: $failure->parameters, propertyPath: $failure->getPropertyPath(), debug: $failure->debug);
        };
        $this->failWith = $this->defaultFailWith;
    }

    /**
     * Prepare the context for evaluation by setting the roots and possibly the failWith closure.
     *
     * @param ?\Closure(string, EvaluationFailureDetails): never $failWith
     *
     * @throws EvaluationError
     * @throws \InvalidArgumentException
     */
    public function prepareForEval(array $roots, ?\Closure $failWith = null): void
    {
        // Reset the key and value stacks.
        $this->keyStack = [];
        $this->valueStack = [];

        // Custom fail handler is scoped to this extraction call only.
        $this->failWith = $failWith ?? $this->defaultFailWith;

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
    private function getCurrentMode(): ThrowMode
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
     * Fail the evaluation with a given error code and message, along with
     * contextual information about the failure (like the current path, roots,
     * key stack, etc.) to help with debugging.
     *
     * @param array<array-key, mixed> $parameters
     * @param array<array-key, mixed> $debug
     *
     * @internal
     *
     * @psalm-internal Nandan108\PropPath
     *
     * @phpstan-internal Nandan108\PropPath
     */
    public function failEval(
        EvaluationErrorCode $code,
        string $message,
        array $parameters = [],
        array $debug = [],
        mixed $container = self::NOT_PROVIDED,
        int|string|\Stringable|null $key = self::NOT_PROVIDED,
    ): never {
        if (self::NOT_PROVIDED !== $container && !array_key_exists('containerType', $parameters)) {
            $parameters['containerType'] = get_debug_type($container);
        }

        if (self::NOT_PROVIDED !== $key && !array_key_exists('key', $parameters)) {
            $parameters['key'] = $key;
        }

        $failure = new EvaluationFailureDetails(
            code: $code,
            parameters: $parameters + ['errorCode' => $code->value],
            debug: $debug,
            paths: $this->paths,
            startingThrowMode: $this->throwMode,
            currentMode: $this->currentMode(),
            roots: $this->roots,
            keyStack: $this->keyStack,
            valueStack: $this->valueStack,
        );

        ($this->failWith)($message, $failure);
    }

    public function failParse(TokenStream $ts, string $message, int $additional = 0): never
    {
        ($this->failParseWith)($message, $ts->valueSince(0, $additional));
    }
}
