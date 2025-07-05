<?php

namespace Nandan108\PropPath\Compiler;

use Nandan108\PropAccess\PropAccess;
use Nandan108\PropPath\Parser\Parser;
use Nandan108\PropPath\Parser\TokenStream;
use Nandan108\PropPath\Segment as Seg;
use Nandan108\PropPath\Support\ExtractContext;
use Nandan108\PropPath\Support\ThrowMode;

final class Compiler
{
    /**
     * Compiles the given paths into a closure to be used to extract values from a container.
     *
     * @param \Closure(string, ?string): never $failParseWith
     *
     * @return array{context: ExtractContext, extractor: \Closure(): mixed}
     */
    public static function compile(
        array|string $paths,
        ?\Closure $failParseWith,
        ThrowMode $defaultThrowMode,
    ): array {
        '' !== $paths or throw new \InvalidArgumentException('Cannot compile an empty path.');

        $context = new ExtractContext(
            roots: [], // Empty roots during compilation
            paths: $paths,
            throwMode: $defaultThrowMode,
            failParseWith: $failParseWith,
        );

        $extractor = is_array($paths)
            ? self::compileInputStructure($paths, $context)
            : self::compileInputString($paths, $context);

        return [
            'context'   => $context,
            'extractor' => $extractor,
        ];
    }

    /**
     * Compiles an input structure (array of paths) into a closure that extracts values from a container.
     *
     * @return \Closure():mixed
     */
    private static function compileInputStructure(array $structure, ExtractContext $context): \Closure
    {
        $compiled = [];

        /** @var mixed $paths */
        foreach ($structure as $key => $paths) {
            '' !== $paths or throw new \InvalidArgumentException('Cannot compile an empty path.');

            $compiled[$key] = match (true) {
                is_array($paths)  => self::compileInputStructure($paths, $context),
                is_string($paths) => self::compileInputString($paths, $context),
                default           => throw new \InvalidArgumentException('Invalid path type: '.get_debug_type($paths)),
            };
        }

        return function () use (&$compiled): array {
            $output = [];
            /** @psalm-suppress UnnecessaryVarAnnotation */
            /** @var \Closure $fn */
            foreach ($compiled as $key => $fn) {
                /** @psalm-var mixed */
                $output[$key] = $fn();
            }

            return $output;
        };
    }

    /**
     * This function is made public for testing and debugging purposes.
     * Parses a path string into an AST (Abstract Syntax Tree) starting at a Chain node.
     *
     * @return array<Seg\ParsedLiteral|Seg\ParsedPath>
     */
    public static function getAst(string $path, ExtractContext $context): array
    {
        $ts = TokenStream::fromString($path);

        return Parser::parseChain($ts, $context, inBraket: false); // Parse the path into an AST (Abstract Syntax Tree)
    }

    /** @return \Closure(): mixed */
    private static function compileInputString(string $path, ExtractContext $context): \Closure
    {
        $AST = self::getAst($path, $context);
        $result = self::compileChain($AST, $context);

        return $result;
    }

    /**
     * @param array<Seg\ParsedPath|Seg\ParsedLiteral> $paths
     *
     * @return \Closure(): mixed
     */
    public static function compileChain(array $paths, ExtractContext $context, bool $preserveKey = false, ?int $bracketElementIdx = null): \Closure
    {
        $compiledPaths = [];

        $lastKey = array_key_last($paths);

        // We apply ThrowMode::NEVER for all but the last path in the chain.
        $context->push(mode: ThrowMode::NEVER);
        foreach ($paths as $i => $path) {
            if ($i === $lastKey) {
                $context->pop(); // pop ThrowMode::NEVER
            }

            $key = $path->key;

            if ($path instanceof Seg\ParsedLiteral) {
                $compiledPath = fn (): int|string => $path->value;
            } else {
                // resolve custom or preserved key for the path
                if (null === $key && $preserveKey) {
                    // if the path doesn't provide a custom or preserved key, but
                    // preserveKey is true (default mode for the whole bracket), we use the first segment as the key if possible.
                    $firstSegment = $path->segments[0];
                    if ($firstSegment instanceof Seg\ParsedKey) {
                        $key = $firstSegment->key;
                    }
                }
                $compiledPath = self::compilePath($path->segments, $context);
            }

            if (null === $key) {
                $compiledPaths[] = [null, $compiledPath];
            } else {
                if ($key instanceof Seg\ParsedPath) {
                    $key = self::compilePath($key->segments, $context);
                }
                $compiledPaths[] = [$key, $compiledPath];
            }
        }

        return function () use ($context, $compiledPaths, $bracketElementIdx): mixed {
            $keyStack = $context->keyStack;
            $valueStack = $context->valueStack;

            /** @psalm-suppress UnnecessaryVarAnnotation */
            /** @var \Closure $compiledPath */
            foreach ($compiledPaths as [$key, $compiledPath]) {
                // make sure we reset the context's stacks before each evaluating each path
                $context->resetStack($keyStack, $valueStack);

                $result = $compiledPath();

                // if return result is non-null, we return it
                if (null !== $result) {
                    // root chain just returns final result
                    if (null === $bracketElementIdx) {
                        return $result;
                    }

                    // If the key is a closure, we resolve it now
                    if ($key instanceof \Closure) {
                        $context->resetStack($keyStack, $valueStack);

                        /** @psalm-var mixed */
                        $key = $key();

                        // It's not the chain's job to throw, so ignore invalid keys
                        if (!(is_scalar($key) || $key instanceof \Stringable)) {
                            $key = null;
                        }
                        $hasExplicitKey = true;
                    } else {
                        $hasExplicitKey = null !== $key;
                    }

                    // If not $isRoot (within bracket), we return a key too
                    return [$key, $result, $hasExplicitKey];
                }
            }

            return null === $bracketElementIdx ? null : [null, null, false];
        };
    }

    /**
     * @param Seg\ParsedSegment[] $segments
     *
     * @return \Closure(): mixed
     */
    private static function compilePath(array $segments, ExtractContext $context): \Closure
    {
        // An empty path is not valid syntax, so this error would be caught by the parser.
        $segments or throw new \InvalidArgumentException('Cannot compile an empty path.');

        // Build a pipeline of closures, each segment operating on the previous value
        foreach ($segments as $i => $seg) {
            if ($seg instanceof Seg\ParsedOnEach) {
                $nextSegments = array_slice($segments, (int) $i + 1);
                $downstream = $nextSegments ? self::compilePath($nextSegments, $context) : null;

                return self::wrapUpstream(
                    upstream: $pipeline ?? null,
                    context: $context,
                    segmentFn: self::compileOnEachSegment($seg, $downstream)
                );
            }

            $pipeline = self::compileSegment($seg, $context, upstream: $pipeline ?? null);
        }

        return $pipeline;
    }

    /**
     * @param \Closure(ExtractContext, mixed):mixed $segmentFn
     *
     * @return \Closure(): mixed
     */
    private static function wrapUpstream(?\Closure $upstream, ExtractContext $context, \Closure $segmentFn): \Closure
    {
        return function () use ($upstream, $segmentFn, $context): mixed {
            // run upstream closure, if provided
            $upstream && $upstream();

            // run the segment closure with the current context and value
            // and push the resulting value onto the context stack
            return $context->push(value: $segmentFn($context, $context->valueStack[0] ?? null));
        };
    }

    /**
     * @param \Closure(): mixed $upstream
     *
     * @return \Closure(): mixed
     */
    private static function compileSegment(Seg\ParsedSegment $seg, ExtractContext $context, ?\Closure $upstream = null): \Closure
    {
        $segmentFn = match (true) {
            // if the segment is a ParsedKey, ParsedSlice, or ParsedBracket, compile it accordingly
            $seg instanceof Seg\ParsedRoot     => self::compileRootSegment($seg),
            $seg instanceof Seg\ParsedKey      => self::compileKeySegment($seg, $context),
            $seg instanceof Seg\ParsedFlatten  => self::compileFlattenSegment($seg),
            $seg instanceof Seg\ParsedSlice    => self::compileSliceSegment($seg),
            $seg instanceof Seg\ParsedBracket  => self::compileBracketSegment($seg, $context),
            $seg instanceof Seg\ParsedRegExp   => self::compileRegExpSegment($seg),
            $seg instanceof Seg\ParsedStackRef => self::compileStackRefSegment($seg),
        };

        // Wrap the segment function with upstream closure if provided
        return self::wrapUpstream($upstream, $context, $segmentFn);
    }

    /**
     * Compiles a flatten segment into a closure.
     *
     * @return \Closure(ExtractContext, mixed):mixed
     */
    private static function compileFlattenSegment(Seg\ParsedFlatten $seg): \Closure
    {
        $getItems = function (mixed $container, array $throwOn, Seg\ParsedFlatten $seg, ExtractContext $context): mixed {
            $values = null !== $container && !is_array($container)
                ? (is_array($container) || is_object($container)
                    ? PropAccess::getValueMap($container, throwOnNotFound: false)
                    : null)
                : $container;

            if (null === $values && in_array($seg->mode, $throwOn)) {
                $context->fail('Expected a container, got: '.get_debug_type($container));
            }

            return $values;
        };

        return function (ExtractContext $context, mixed $value) use ($seg, $getItems): mixed {
            $items = $getItems($value, [ThrowMode::MISSING_KEY, ThrowMode::NULL_VALUE], $seg, $context);

            if (!is_array($items)) {
                // No need to throw an "Expected a container" error, it would already
                // be thrown by $getItems if the mode is not ThrowMode::NEVER.
                return $items;
            }

            /** @var array<string|int, mixed> */
            $flattenedOutput = [];

            /** @var mixed $outerItem */
            foreach ($items as $outerKey => $outerItem) {
                // Merge items
                $subItems = $getItems($outerItem, [], $seg, $context)
                    ?? [$outerKey => $outerItem];

                /** @var mixed $innerItem */
                foreach ($subItems as $innerKey => $innerItem) {
                    if ($seg->preserveKeys) {
                        /** @psalm-var mixed */
                        $flattenedOutput[$innerKey] = $innerItem;
                    } else {
                        /** @psalm-var mixed */
                        $flattenedOutput[] = $innerItem;
                    }
                }
            }

            if (ThrowMode::NULL_VALUE === $seg->mode) {
                $flattenedOutput or $context->fail("flattened by `$seg->raw` is empty");
            }

            $context->push($seg->raw);

            return $flattenedOutput;
        };
    }

    /**
     * Compiles a RegExp key-filtering segment.
     * This segment is intended to filter keys of an array or object using a regular expression.
     *
     * @return \Closure(ExtractContext, mixed):mixed
     */
    private static function compileRegExpSegment(Seg\ParsedRegExp $seg): \Closure
    {
        // Not implemented yet, but we can return a placeholder for now.
        return function (ExtractContext $context, mixed $originalContainer) use ($seg): mixed {
            /** @psalm-var mixed */
            $mode = $seg->mode ?? $context->currentMode();
            $output = [];
            $container = null;
            $containerIsGetterMap = false;

            // attempt to convert the container to an array if it is not already one
            if (is_object($originalContainer)) {
                $container = PropAccess::getGetterMap($originalContainer, throwOnNotFound: false);
                $containerIsGetterMap = true;
            } elseif (is_array($originalContainer)) {
                $container = $originalContainer;
            }

            // If $originalContainer is not an array or object, $container will be null.
            // We could also have empty containers, in which there's nothing to filter.
            // this according to the ThrowMode.
            $errorMsg = match ($container) {
                null    => 'can\'t filter within '.get_debug_type($originalContainer),
                []      => 'requires keys to filter, but '.get_debug_type($originalContainer).' is empty',
                default => null,
            };
            if ($errorMsg) {
                $context->push($seg->raw);

                return ThrowMode::NEVER === $mode
                    ? $container
                    : $context->fail($errorMsg);
            }
            /** @var non-empty-array $container */

            /** @psalm-suppress PossibleRawObjectIteration */
            if ($seg->filterKeys) {
                // Filter out keys that don't match the regular expression
                /** @var mixed $value */
                foreach ($container as $key => $value) {
                    if (preg_match($seg->value, (string) $key)) {
                        /** @psalm-var mixed */
                        $output[$key] = $value;
                    }
                }
            } else {
                if ($containerIsGetterMap) {
                    // If the container is a getter map, we need to resolve the values first
                    /** @var array<string, Closure(mixed):mixed> $container */
                    $resolvedValues = PropAccess::resolveValues($container, $originalContainer);
                    $container = $resolvedValues;
                    $containerIsGetterMap = false; // Now we have a regular array
                }
                // Filter out non-(string|Stringable|numeric) values, and those that don't match the regular expression
                /** @var mixed $value */
                foreach ($container as $key => $value) {
                    if ((is_string($value) || is_numeric($value) || $value instanceof \Stringable)
                        && preg_match($seg->value, (string) $value)) {
                        $output[$key] = $value;
                    }
                }
            }

            // If the output is empty and mode is NULL_VALUE, we throw an error
            if (!$output && ThrowMode::NULL_VALUE === $mode) {
                $context->push($seg->raw);
                $context->fail('RegExp failed to match any '.($seg->filterKeys ? 'key' : 'value').' in '.get_debug_type($originalContainer));
            }

            // If we got a getter map from PropAccess, we resolve the values now.
            if ($containerIsGetterMap) {
                /** @var array<string, Closure(mixed):mixed> $output */
                return PropAccess::resolveValues($output, $originalContainer);
            }

            return $output; // return the filtered output
        };
    }

    /**
     * Compiles an onEach segment into a closure.
     *
     * @return \Closure(ExtractContext, mixed):mixed
     */
    private static function compileOnEachSegment(Seg\ParsedOnEach $seg, ?\Closure $downStream): \Closure
    {
        return function (ExtractContext $context, mixed $container) use ($seg, $downStream): ?array {
            $context->push(mode: $seg->mode);

            if (null === $container || is_scalar($container)) {
                if (ThrowMode::NEVER === $context->currentMode()) {
                    return null;
                } else {
                    $context->fail(sprintf('of type %s cannot be iterated over by `%s`',
                        get_debug_type($container),
                        $seg->raw)
                    );
                }
            }

            $context->push($seg->raw);

            $keyStack = $context->keyStack;
            $valueStack = $context->valueStack;

            /** @var array<array-key, mixed> */
            $results = [];
            self::walkRecursive($container, $results, $seg->depth - 1, $seg->preserveKey, $context);

            if (ThrowMode::NEVER !== $context->currentMode() && empty($results)) {
                // If the mode is NULL_VALUE, we throw an error if the output is empty
                $context->fail('onEach segment is empty');
            }

            $output = [];
            /** @var array{0: array-key, 1:mixed} $result */
            foreach ($results as $result) {
                /** @var mixed $v */
                [$k, $v] = $result;

                // If a downstream closure is provided, apply it to each result
                if ($downStream) {
                    $context->push(value: $v);
                    /** @psalm-var mixed */
                    $v = $downStream($v);
                    // reset context stack level after each downstream call
                    $context->resetStack($keyStack, $valueStack);
                }

                if (null !== $v) {
                    // If the downstream closure returns a value, replace the result
                    if ($seg->preserveKey) {
                        /** @psalm-var mixed */
                        $output[$k] = $v;
                    } else {
                        /** @psalm-var mixed */
                        $output[] = $v; // reindex if preserveKey is false
                    }
                }
            }

            if (ThrowMode::NULL_VALUE === $context->currentMode() && empty($output)) {
                // If the mode is NULL_VALUE, we throw an error if the output is empty
                $context->fail('onEach segment returned no results');
            }

            return $output;
        };
    }

    /**
     * Recursively walks through a data structure collecting matching values.
     *
     * @param mixed           $node       Current node being processed
     * @param array           &$results   Array to store matched results
     * @param int             $depth      Maximum recursion depth (-1 for infinite)
     * @param ExtractContext  $context    Extraction context
     * @param string|int|null $currentKey Current key being processed
     */
    private static function walkRecursive(
        mixed $node,
        array &$results,
        int $depth,
        bool $preserveKey,
        ExtractContext $context,
        string|int|null $currentKey = null,
    ): void {
        if (null !== $currentKey) {
            // If we have a current key, we push it to the context stack
            $context->push((string) $currentKey);
        }

        // TODO: dedupe code by use of AccessorProxy
        if (is_array($node) || $node instanceof \Traversable) {
            /** @var mixed $v */
            foreach ($node as $k => $v) {
                if (null === $v) {
                    continue;
                }
                if ($preserveKey) {
                    $results[] = [$k, $v];
                } else {
                    $results[] = [null, $v]; // Add current value
                }
                if ($depth) { // Continue recursing if depth not exhausted
                    self::walkRecursive($v, $results, $depth - 1, $preserveKey, $context, $k);
                }
            }
        } elseif (is_object($node)) {
            $getterMap = PropAccess::getGetterMap($node);
            /** @var callable(mixed)[] $getterMap */
            foreach ($getterMap as $k => $getter) {
                /** @psalm-var mixed */
                $v = $getter($node);
                if (null === $v) {
                    continue;
                }
                if ($preserveKey) {
                    $results[] = [$k, $v];
                } else {
                    $results[] = [null, $v]; // Add current value
                }
                if ($depth) {
                    self::walkRecursive($v, $results, $depth - 1, $preserveKey, $context, $k);
                }
            }
        }

        // if we have a current key, then it was pushed on the stack
        // so now we need to pop it
        if (null !== $currentKey) {
            $context->pop(key: true);
        }
    }

    /**
     * Compiles a root segment into a closure that extracts the root value from the context.
     *
     * @return \Closure(ExtractContext, mixed):mixed
     */
    private static function compileRootSegment(Seg\ParsedRoot $seg): \Closure
    {
        return function (ExtractContext $context) use ($seg): mixed {
            // resolve root key (custom key ?? (default key = first root key))
            // empty roots are not allowed, so we can safely assume that there is at least one root
            /** @var string|int $key */
            $key = $seg->key ?? array_key_first($context->roots);

            // If a root is not found, we throw an error regardless of the mode
            if (!array_key_exists($key, $context->roots)) {
                /** @var string $jsonRoots */
                $jsonRoots = json_encode(array_keys($context->roots));
                $context->fail(": root `$key` not found in roots (valid roots are: ".substr($jsonRoots, 1, -1).')');
            }

            // reset stack levels to 0, as we are at the root
            $context->resetStack([], []);
            $context->push(key: '$'.$key);

            return $context->roots[$key];
        };
    }

    /**
     * Compiles a key segment into a closure that extracts a value from a container.
     *
     * @return \Closure(ExtractContext, mixed):mixed
     */
    private static function compileKeySegment(Seg\ParsedKey $seg, ExtractContext $context): \Closure
    {
        // If we specifying a return type here, intelephense complains: "Not all paths return a value",
        // despite the presence of a final throw (via $this->fail()). So instead, we don't declare a
        // return type and suppress psalm's complaint.
        /** @psalm-suppress MissingClosureReturnType */
        return match ($seg->mode ?? $context->currentMode()) {
            ThrowMode::MISSING_KEY => function (ExtractContext $context, mixed $container) use ($seg) {
                $context->push((string) $seg->key, $seg->mode);

                if (is_array($container)) {
                    if (!array_key_exists($seg->key, $container)) {
                        // /** @psalm-suppress PossiblyFalseOperand */
                        $context->fail('not found in array');
                    }

                    return $container[$seg->key];
                }

                if ($container instanceof \ArrayAccess) {
                    if (!$container->offsetExists($seg->key)) {
                        $context->fail('not found in ArrayAccess');
                    }

                    return $container[$seg->key];
                }

                if (is_object($container)) {
                    $getter = PropAccess::getGetterMap($container, (string) $seg->key)[$seg->key] ?? null;
                    if (!$getter) {
                        $context->fail('not accessible on '.get_class($container));
                    }

                    return $getter($container);
                }

                $context->fail('could not be extracted from non-container of type `'.get_debug_type($container).'`');
            },

            ThrowMode::NULL_VALUE => function (ExtractContext $context, mixed $container) use ($seg) {
                $context->push((string) $seg->key, $seg->mode);

                if (is_array($container) || $container instanceof \ArrayAccess) {
                    if (!isset($container[$seg->key])) {
                        $context->fail('is null but required');
                    }

                    return $container[$seg->key];
                }

                if (is_object($container)) {
                    $getter = PropAccess::getGetterMap($container, [$seg->key], true)[$seg->key] ?? null;
                    if (!$getter) {
                        $context->fail('not accessible on '.get_class($container));
                    }
                    /** @psalm-var mixed $value */
                    $value = $getter($container);
                    if (null === $value) {
                        $context->fail('is null but required');
                    }

                    return $value;
                }
            },
            // ThrowMode::NEVER
            default => function (ExtractContext $context, mixed $container) use ($seg) {
                $context->push((string) $seg->key, $seg->mode);

                if (is_array($container)) {
                    return $container[$seg->key] ?? null;
                }

                if ($container instanceof \ArrayAccess) {
                    return $container->offsetExists($seg->key) ? $container[$seg->key] : null;
                }

                if (is_object($container)) {
                    $getter = PropAccess::getGetterMap($container, [$seg->key], true)[$seg->key] ?? null;

                    return $getter ? $getter($container) : null;
                }

                return null;
            },
        };
    }

    /**
     * Compiles a slice segment into a closure that extracts a slice from an array or ArrayAccess.
     *
     * @return \Closure(ExtractContext, mixed):mixed
     */
    private static function compileSliceSegment(Seg\ParsedSlice $seg): \Closure
    {
        return function (ExtractContext $context, mixed $container) use ($seg): array|\ArrayAccess|null {
            $start = $seg->start;
            $end = $seg->end;

            $context->push($seg->raw);

            $throwMode = $seg->mode ?? $context->currentMode();

            $fail = function (string $message) use ($throwMode, $context, $container): ?array {
                if (ThrowMode::NEVER === $throwMode) {
                    /** @psalm-var null */
                    return null; // if mode is NEVER, we just return null
                }
                $type = get_debug_type($container);
                $message = str_replace('{type}', $type, $message);

                array_pop($context->keyStack);
                $context->fail($message);
            };

            if (null === $container) {
                return $fail('is null and cannot be sliced');
            }
            if (!(is_array($container) || $container instanceof \ArrayAccess)) {
                return $fail('cannot be sliced: {type} is not an array or ArrayAccess');
            }
            $isCountable = is_array($container) || $container instanceof \Countable;

            // determine start and end values
            if (null === $start) {
                if (null === $end) {
                    // no start or end specified, just return container as-is
                    return $container;
                }
                $start = 0;
            } elseif ($start < 0) {
                if (!$isCountable) {
                    return $fail('cannot be sliced with negative start: {type} is not countable');
                }
                $start += count($container);
            }

            if (null === $end) {
                if (!$isCountable) {
                    return $fail("cannot be sliced by `$seg->raw` with null end: {type} is not countable");
                }
                /** @psalm-suppress PossiblyInvalidArgument */
                $end = count($container); // means "slice to the end"
            } elseif ($end < 0) {
                if (!$isCountable) {
                    return $fail("cannot be sliced by `$seg->raw` with negative end: {type} is not countable");
                }
                /** @psalm-suppress PossiblyInvalidArgument */
                $end += count($container);
            }

            // Make the slice
            if (is_array($container)) {
                $slice = array_slice($container, $start, $end - $start, true);
                if (!$seg->preserveKey) {
                    $slice = array_values($slice); // if preserveKey is false, reindex the slice
                }
            } else {
                // $container is ArrayAccess
                for ($slice = [], $k = $start; $k < $end; ++$k) {
                    /** @var \ArrayAccess $container */
                    if ($container->offsetExists($k)) {
                        if ($seg->preserveKey) {
                            /** @psalm-var mixed */
                            $slice[$k] = $container[$k] ?? null;
                        } else {
                            /** @psalm-var mixed */
                            $slice[] = $container[$k] ?? null;
                        }
                    }
                }
            }

            // check slice keys and throw according to $seg->throwMode
            if (ThrowMode::MISSING_KEY === $throwMode) {
                if (count($slice) < ($end - $start)) {
                    [$length, $count] = [$end - $start, count($slice)];

                    return $fail("slice `$seg->raw` is missing some keys: expected $length but got $count");
                }
            } elseif (ThrowMode::NULL_VALUE === $throwMode) {
                /** @var mixed $v */
                foreach ($slice as $k => $v) {
                    if (null === $v) {
                        return $fail("slice `$seg->raw` contains a null value at key `$k`");
                    }
                }
            }

            return $slice;
        };
    }

    /**
     * Compiles a bracket segment into a closure.
     *
     * @return \Closure(ExtractContext, mixed):mixed
     */
    private static function compileBracketSegment(Seg\ParsedBracket $seg, ExtractContext $context): \Closure
    {
        $throwMode = $seg->mode ?? $context->currentMode();

        $context->push(mode: $throwMode);

        $compiledBracketParts = [];
        foreach ($seg->chains as $bracketElementIdx => $chain) {
            $extractor = self::compileChain($chain, $context, $seg->preserveKey, $bracketElementIdx);
            $compiledBracketParts[] = $extractor;
        }

        $context->pop();

        // bracket segment (multi)
        return function (ExtractContext $context, mixed $value) use ($seg, $compiledBracketParts): mixed {
            $bracket = [];
            $keyStack = $context->keyStack;
            $valueStack = $context->valueStack;
            $hasExplicitKey = false;

            // If the value is null, abort and return or throw according to the throwMode
            if (null === $value) {
                if (ThrowMode::NEVER === ($seg->mode ?? $context->currentMode())) {
                    return null; // if mode is NEVER, we just return null
                }
                $context->fail("is null, therefore `$seg->raw` cannot be applied");
            }

            foreach ($compiledBracketParts as $compiledChainExtractor) {
                // resolve bracket element
                /** @var ?array{0: ?array-key, 1: mixed, 2: bool} $result */
                $result = $compiledChainExtractor();

                // reset context stack levels after each bracket element
                $context->resetStack($keyStack, $valueStack);

                if ($result) {
                    /** @var mixed $chainValue */
                    [$chainKey, $chainValue, $hasExplicitKey] = $result;

                    // no need to catch exceptions here, they're already handled in the compiled chain
                    if (null === $chainKey) {
                        /** @psalm-var mixed */
                        $bracket[] = $chainValue;
                    } else {
                        /** @psalm-var mixed */
                        $bracket[$chainKey] = $chainValue;
                    }
                }
            }

            $context->push($seg->raw);

            /** @psalm-var mixed */
            $result = 1 === count($bracket) && !$hasExplicitKey
                ? $bracket[0] // if there's only one item in the bracket and preserveKey is false, return it directly
                : $bracket; // otherwise, return the whole bracket array

            return $result;
        };
    }

    /**
     * Compiles a slice segment into a closure that extracts a slice from an array or ArrayAccess.
     *
     * @return \Closure(ExtractContext, mixed):mixed
     */
    private static function compileStackRefSegment(Seg\ParsedStackRef $seg): \Closure
    {
        return function (ExtractContext $context) use ($seg): mixed {
            $context->push($seg->raw);

            // If the stack reference is out of bounds, we return null or throw an error according to ThrowMode
            if (!array_key_exists($seg->index, $context->valueStack)) {
                $mode = $seg->mode ?? $context->currentMode();
                if (ThrowMode::NEVER === $mode) {
                    return $context->push(value: null); // if mode is NEVER, we just return null
                }
                $context->fail("references index {$seg->index} but the value stack has only ".count($context->valueStack).' items');
            }

            // Return the value from the stack
            return $context->push(value: $context->valueStack[$seg->index]);
        };
    }
}
