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
     * @return array{context: ExtractContext, extractor: \Closure}
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
     * @return \Closure(mixed):mixed
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

        return function (mixed $container) use (&$compiled): array {
            $output = [];
            /** @psalm-suppress UnnecessaryVarAnnotation */
            /** @var \Closure $fn */
            foreach ($compiled as $key => $fn) {
                /** @psalm-var mixed */
                $output[$key] = $fn($container);
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

    private static function compileInputString(string $path, ExtractContext $context): \Closure
    {
        $AST = self::getAst($path, $context);
        $result = self::compileChain($AST, $context);

        return $result;
    }

    /**
     * @param array<Seg\ParsedPath|Seg\ParsedLiteral> $paths
     */
    public static function compileChain(array $paths, ExtractContext $context, bool $preserveKey = false, ?int $bracketElementNum = null): \Closure
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

        return function (mixed $container) use ($context, $compiledPaths, $bracketElementNum): mixed {
            $keyStack = $context->keyStack;

            /** @psalm-suppress UnnecessaryVarAnnotation */
            /** @var \Closure $compiledPath */
            foreach ($compiledPaths as $i => [$key, $compiledPath]) {
                // make sure we reset the context's $keyStack before each evaluating each path
                $context->keyStack = $keyStack;
                $result = $compiledPath($container);

                // if return result is non-null, we return it
                if (null !== $result) {
                    // root chain just returns final result
                    if (null === $bracketElementNum) {
                        return $result;
                    }

                    // If the key is a closure, we resolve it now
                    if ($key instanceof \Closure) {
                        /** @psalm-var mixed */
                        $key = $key($container);
                        if (!(is_scalar($key) || $key instanceof \Stringable)) {
                            $context->fail('of type '.get_debug_type($key)." is not a valid key! (bracket segment $bracketElementNum, chain link $i)");
                        }
                    }

                    // If not $isRoot (within bracket), we return a key too
                    return [$key, $result];
                }
            }

            return null === $bracketElementNum ? null : [null, null];
        };
    }

    /**
     * @param Seg\ParsedSegment[] $segments
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

                return self::compileOnEachSegment($seg, upstream: $pipeline ?? null, context: $context, downStream: $downstream);
            }

            $pipeline = self::compileSegment($seg, $context, upstream: $pipeline ?? null);
        }

        return $pipeline;
    }

    private static function compileSegment(Seg\ParsedSegment $seg, ExtractContext $context, ?\Closure $upstream = null): \Closure
    {
        return match (true) {
            // if the segment is a ParsedKey, ParsedSlice, or ParsedBracket, compile it accordingly
            $seg instanceof Seg\ParsedRoot    => self::compileRootSegment($seg, $context),
            $seg instanceof Seg\ParsedKey     => self::compileKeySegment($seg, $upstream, $context),
            $seg instanceof Seg\ParsedFlatten => self::compileFlattenSegment($seg, $upstream, $context),
            $seg instanceof Seg\ParsedSlice   => self::compileSliceSegment($seg, $upstream, $context),
            $seg instanceof Seg\ParsedBracket => self::compileBracketSegment($seg, $upstream, $context),
            $seg instanceof Seg\ParsedRegExp  => self::compileRegExpSegment($seg, $upstream, $context),
            // default will never happen, but let's keep static analysis happy
            default                           => throw new \InvalidArgumentException('Invalid segment type: '.get_class($seg)),
        };
    }

    private static function compileFlattenSegment(Seg\ParsedFlatten $seg, ?\Closure $upstream, ExtractContext $context): \Closure
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

        return function (mixed $data) use ($seg, $upstream, $context, $getItems): mixed {
            /** @psalm-var mixed */
            $value = $upstream ? $upstream($data) : $data;

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

    // Compile a RegExp key-filtering segment.
    // This segment is intended to filter keys of an array or object using a regular expression.
    private static function compileRegExpSegment(Seg\ParsedRegExp $seg, ?\Closure $upstream, ExtractContext $context): \Closure
    {
        // Not implemented yet, but we can return a placeholder for now.
        return function (mixed $upstreamContainer) use ($seg, $upstream, $context): mixed {
            /** @psalm-var mixed */
            $originalContainer = $upstream ? $upstream($upstreamContainer) : $upstreamContainer;
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

    private static function compileOnEachSegment(Seg\ParsedOnEach $seg, ?\Closure $upstream, ExtractContext $context, ?\Closure $downStream): \Closure
    {
        return function (mixed $upsteamContainer) use ($seg, $upstream, $context, $downStream): ?array {
            /** @psalm-var mixed */
            $container = $upstream ? $upstream($upsteamContainer) : $upsteamContainer;
            $results = [];

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
            $stack = $context->keyStack;

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
                    /** @psalm-var mixed */
                    $v = $downStream($v);
                    $context->keyStack = $stack; // reset context stack level after each downstream call
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
        $context->push(null !== $currentKey ? (string) $currentKey : null);
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

        $context->pop();
    }

    private static function compileRootSegment(Seg\ParsedRoot $seg, ExtractContext $context): \Closure
    {
        return function () use ($seg, $context): mixed {
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

            $context->keyStack = []; // reset stack level to 0, as we are at the root
            $context->push('$'.$key);

            return $context->roots[$key];
        };
    }

    private static function compileKeySegment(Seg\ParsedKey $seg, ?\Closure $upstream, ExtractContext $context): \Closure
    {
        /** @var \Closure(mixed): array{mixed, string|int, ExtractContext} $setup */
        $setup = function (mixed $container) use ($seg, $upstream, $context): array {
            if ($upstream) {
                /** @psalm-var mixed $container */
                $container = $upstream($container);
            }
            $context->push((string) $seg->key, $seg->mode);

            return [$container, $seg->key, $context];
        };

        $mode = $seg->mode ?? $context->currentMode();

        // If we specifying a return type here, intelephense complains: "Not all paths return a value",
        // despite the presence of a final throw (via $this->fail()). So instead, we don't declare a
        // return type and suppress psalm's complaint.
        /** @psalm-suppress MissingClosureReturnType */
        return match ($mode) {
            ThrowMode::MISSING_KEY => function (mixed $parentContainer) use ($setup) {
                /** @var mixed $container */
                [$container, $key, $context] = $setup($parentContainer);

                if (is_array($container)) {
                    if (!array_key_exists($key, $container)) {
                        // /** @psalm-suppress PossiblyFalseOperand */
                        $context->fail('not found in array');
                    }

                    return $container[$key];
                }

                if ($container instanceof \ArrayAccess) {
                    if (!$container->offsetExists($key)) {
                        $context->fail('not found in ArrayAccess');
                    }

                    return $container[$key];
                }

                if (is_object($container)) {
                    $getter = PropAccess::getGetterMap($container, (string) $key)[$key] ?? null;
                    if (!$getter) {
                        $context->fail('not accessible on '.get_class($container));
                    }

                    return $getter($container);
                }

                $context->fail('could not be extracted from non-container of type `'.get_debug_type($container).'`');
            },

            ThrowMode::NULL_VALUE => function (mixed $container) use ($setup) {
                /** @var mixed $container */
                [$container, $key, $context] = $setup($container);

                if (is_array($container) || $container instanceof \ArrayAccess) {
                    if (!isset($container[$key])) {
                        $context->fail('is null but required');
                    }

                    return $container[$key];
                }

                if (is_object($container)) {
                    $getter = PropAccess::getGetterMap($container, [$key], true)[$key] ?? null;
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
            default => function (mixed $container) use ($setup) {
                /** @var mixed $container */
                [$container, $key] = $setup($container);

                if (is_array($container)) {
                    return $container[$key] ?? null;
                }

                if ($container instanceof \ArrayAccess) {
                    return $container->offsetExists($key) ? $container[$key] : null;
                }

                if (is_object($container)) {
                    $getter = PropAccess::getGetterMap($container, [$key], true)[$key] ?? null;

                    return $getter ? $getter($container) : null;
                }

                return null;
            },
        };
    }

    private static function compileSliceSegment(Seg\ParsedSlice $seg, ?\Closure $upstream, ExtractContext $context): \Closure
    {
        return function (mixed $container) use ($seg, $upstream, $context): array|\ArrayAccess|null {
            $start = $seg->start;
            $end = $seg->end;

            if ($upstream) {
                /** @psalm-var mixed $container */
                $container = $upstream($container);
            }
            $context->push($seg->raw);

            $throwMode = $seg->mode ?? $context->currentMode();

            $fail = function (string $message) use ($throwMode, $context, $container): ?array {
                if (ThrowMode::NEVER === $throwMode) {
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

    private static function compileBracketSegment(Seg\ParsedBracket $seg, ?\Closure $upstream, ExtractContext $context): \Closure
    {
        $throwMode = $seg->mode ?? $context->currentMode();

        $context->push(mode: $throwMode);

        $compiledBracketParts = [];
        foreach ($seg->chains as $bracketElementNum => $chain) {
            $extractor = self::compileChain($chain, $context, $seg->preserveKey, $bracketElementNum);
            $compiledBracketParts[] = $extractor;
        }

        $context->pop();

        // bracket segment (multi)
        return function (mixed $upstreamContainer) use ($seg, $context, $compiledBracketParts, $upstream) {
            /** @psalm-var mixed */
            $container = $upstream
                ? $upstream($upstreamContainer)
                : $upstreamContainer; // upstream is optional, if not provided, we use the upstreamContainer directly

            $bracket = [];
            $keyStack = $context->keyStack;
            $hasExplicitKey = false;
            foreach ($compiledBracketParts as $compiledChainExtractor) {
                /** @var ?array{0: ?array-key, 1: mixed} $result */
                $result = $compiledChainExtractor($container);
                $context->keyStack = $keyStack; // reset context stack level after each segment
                if ($result) {
                    /** @var mixed $value */
                    [$key, $value] = $result;

                    // no need to catch exceptions here, they're already handled in the compiled chain
                    if (null === $key) {
                        /** @psalm-var mixed */
                        $bracket[] = $value;
                    } else {
                        /** @psalm-var mixed */
                        $bracket[$key] = $value;
                        $hasExplicitKey = true;
                    }
                }
            }

            $context->push($seg->raw);

            return 1 === count($bracket) && !$hasExplicitKey
                ? reset($bracket) // if there's only one item in the bracket and preserveKey is false, return it directly
                : $bracket; // otherwise, return the whole bracket array
        };
    }
}
