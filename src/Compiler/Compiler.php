<?php

namespace Nandan108\PropPath\Compiler;

use Nandan108\PropAccess\AccessorRegistry;
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
     * @param mixed $failParseWith
     *
     * @return array{context: ExtractContext, extractor: \Closure}
     */
    public static function compile(
        array|string $paths,
        string $fullPath,
        ?callable $failParseWith,
        // ?callable $failEvalWith,
        ThrowMode $defaultThrowMode,
    ): array {
        $context = new ExtractContext(
            roots: [], // Empty roots during compilation
            fullPath: $fullPath,
            throwMode: $defaultThrowMode,
            failParseWith: $failParseWith,
            // failWith: $failEvalWith, // Not needed during compilation
        );

        $extractor = is_array($paths)
            ? self::compileStructure($paths, $context)
            : self::compileString($paths, $context);

        return [
            'context'   => $context,
            'extractor' => $extractor,
        ];
    }

    private static function compileStructure(array $structure, ExtractContext $context): \Closure
    {
        $compiled = [];

        foreach ($structure as $key => $paths) {
            $compiled[$key] = (is_array($paths))
                ? self::compileStructure($paths, $context)
                : self::compileString($paths, $context);
        }

        return function (mixed $container) use (&$compiled): array {
            $output = [];
            /** @psalm-suppress UnnecessaryVarAnnotation */
            /** @var \Closure $fn */
            foreach ($compiled as $key => $fn) {
                $output[$key] = $fn($container);
            }

            return $output;
        };
    }

    private static function compileString(string $path, ExtractContext $context): \Closure
    {
        $stream = TokenStream::fromString($path);
        $parsed = Parser::parseChain($stream, $context);
        $result = self::compileChain($parsed, $context);

        return $result;
    }

    /**
     * @param array<Seg\ParsedPath|Seg\ParsedLiteral> $paths
     */
    public static function compileChain(array $paths, ExtractContext $context, ?\Closure $upstream = null, bool $preserveKey = false, bool $inBracket = false): \Closure
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
                $compiledPaths[] = [$key, $compiledPath];
            }
        }

        return function (mixed $container) use ($context, $upstream, $compiledPaths, $inBracket) {
            if ($upstream) {
                $container = $upstream($container);
            }

            $stackLevel = $context->getStackLevel();
            /** @psalm-suppress UnnecessaryVarAnnotation */
            /** @var \Closure $fn */
            foreach ($compiledPaths as [$key, $fn]) {
                // no need to catch exceptions here
                $result = $fn($container);
                $context->resetStackLevel($stackLevel); // reset context stack level after each segment

                // return the first non-null result
                if (null !== $result) {
                    return $inBracket
                        ? [$key, $result] // within bracket, we return a key too
                        : $result; // root chain just returns final result
                }
            }

            // all segments returned null, so we return null
            return $inBracket ? [null, null] : null;
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
            if ($seg instanceof Seg\ParsedRecursive) {
                $nextSegments = array_slice($segments, (int) $i + 1);
                $downstream = $nextSegments ? self::compilePath($nextSegments, $context) : null;

                return self::compileRecursiveSegment($seg, upstream: $pipeline ?? null, context: $context, downStream: $downstream);
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
            $seg instanceof Seg\ParsedSlice   => self::compileSliceSegment($seg, $upstream, $context),
            $seg instanceof Seg\ParsedBracket => self::compileBracketSegment($seg, $upstream, $context),
            // default will never happen, but let's keep static analysis happy
            default                           => throw new \InvalidArgumentException('Invalid segment type: '.get_class($seg)),
        };
    }

    private static function compileRecursiveSegment(Seg\ParsedRecursive $seg, ?\Closure $upstream, ExtractContext $context, ?\Closure $downStream): \Closure
    {
        return function (mixed $container) use ($seg, $upstream, $context, $downStream): array {
            $container = $upstream ? $upstream($container) : $container;
            $results = [];

            $context->push(1 === $seg->depth ? '*' : '**', $seg->mode);

            self::walkRecursive($container, $results, $seg->depth - 1, $seg->preserveKey, $context);

            $stackLevel = $context->getStackLevel();
            $output = [];
            foreach ($results as $k => $v) {
                // If a downstream closure is provided, apply it to each result
                if ($downStream) {
                    $v = $downStream($v);
                    $context->resetStackLevel($stackLevel); // reset context stack level after each downstream call
                }

                if (null !== $v) {
                    // If the downstream closure returns a value, replace the result
                    if ($seg->preserveKey) {
                        $output[$k] = $v;
                    } else {
                        $output[] = $v; // reindex if preserveKey is false
                    }
                }
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

        if (is_array($node) || $node instanceof \Traversable) {
            foreach ($node as $k => $v) {
                if ($preserveKey) {
                    $results[$k] = $v;
                } else {
                    $results[] = $v; // Add current value
                }
                if ($depth) { // Continue recursing if depth not exhausted
                    self::walkRecursive($v, $results, $depth - 1, $preserveKey, $context, $k);
                }
            }
        } elseif (is_object($node)) {
            foreach (AccessorRegistry::getGetterMap($node) as $k => $getter) {
                $v = $getter($node);
                if ($preserveKey) {
                    $results[$k] = $v;
                } else {
                    $results[] = $v; // Add current value
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

            $context->resetStackLevel(0); // reset stack level to 0, as we are at the root
            $context->push('$'.$key);

            return $context->roots[$key];
        };
    }

    private static function compileKeySegment(Seg\ParsedKey $seg, ?\Closure $upstream, ExtractContext $context): \Closure
    {
        /** @var \Closure(mixed): array{mixed, string|int, ExtractContext} $setup */
        $setup = function (mixed $container) use ($seg, $upstream, $context): array {
            if ($upstream) {
                $container = $upstream($container);
            }
            $context->push((string) $seg->key, $seg->mode);

            return [$container, $seg->key, $context];
        };

        $mode = $seg->mode ?? $context->currentMode();

        /** @psalm-suppress MissingClosureReturnType */
        return match ($mode) {
            ThrowMode::MISSING_KEY => function (mixed $parentContainer) use ($setup) {
                [$container, $key, $context] = $setup($parentContainer);

                if (is_array($container)) {
                    if (!array_key_exists($key, $container)) {
                        /** @psalm-suppress PossiblyFalseOperand */
                        $context->fail('not found in array');
                    }

                    // $context->pop(); // pop the key from context, pushed in $setup()

                    return $container[$key];
                }

                if ($container instanceof \ArrayAccess) {
                    if (!$container->offsetExists($key)) {
                        $context->fail('not found in ArrayAccess');
                    }

                    // $context->pop();

                    return $container[$key];
                }

                if (is_object($container)) {
                    $getter = AccessorRegistry::getGetterMap($container, (string) $key)[$key] ?? null;
                    if (!$getter) {
                        $context->fail('not accessible on '.get_class($container));
                    }

                    // $context->pop();

                    return $getter($container);
                }

                $context->fail('could not be extracted from non-container of type `'.get_debug_type($container).'`');
            },

            ThrowMode::NULL_VALUE => function (mixed $container) use ($setup) {
                [$container, $key, $context] = $setup($container);

                if (is_array($container)) {
                    if (!isset($container[$key])) {
                        $context->fail('is null but required');
                    }

                    // $context->pop();

                    return $container[$key];
                }
                if ($container instanceof \ArrayAccess) {
                    $value = $container[$key] ?? null;
                    if (null === $value) {
                        $context->fail('is null but required');
                    }

                    // $context->pop();

                    return $container[$key];
                }

                if (is_object($container)) {
                    $getter = AccessorRegistry::getGetterMap($container, [$key], true)[$key] ?? null;
                    if (!$getter) {
                        $context->fail('not accessible on '.get_class($container));
                    }
                    $value = $getter($container);
                    if (null === $value) {
                        $context->fail('is null but required');
                    }

                    // $context->pop();

                    return $value;
                }
            },
            // ThrowMode::NEVER
            default => function (mixed $container) use ($setup) {
                [$container, $key] = $setup($container);

                if (is_array($container)) {
                    return $container[$key] ?? null;
                }

                if ($container instanceof \ArrayAccess) {
                    return $container->offsetExists($key) ? $container[$key] : null;
                }

                if (is_object($container)) {
                    $getter = AccessorRegistry::getGetterMap($container, [$key], true)[$key] ?? null;

                    return $getter ? $getter($container) : null;
                }

                return null;
            },
        };
    }

    private static function compileSliceSegment(Seg\ParsedSlice $seg, ?\Closure $upstream, ExtractContext $context): \Closure
    {
        return function (mixed $container) use ($seg, $upstream, $context): array|\ArrayAccess {
            $start = $seg->start;
            $end = $seg->end;

            if ($upstream) {
                $container = $upstream($container);
            }
            $context->push($seg->raw);

            $fail = function (string $message) use ($context, $container): never {
                $type = get_debug_type($container);
                $message = str_replace('{type}', $type, $message);

                $context->fail($message);
            };

            if (null === $container) {
                $fail('cannot be sliced: {type} is null');
            }
            if (!(is_array($container) || $container instanceof \ArrayAccess)) {
                $fail('cannot be sliced: {type} is not an array or ArrayAccess');
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
                    $fail('cannot be sliced with negative start: {type} is not countable');
                }
                /** @psalm-suppress PossiblyInvalidArgument */
                $start += count($container);
            }

            if (null === $end) {
                if (!$isCountable) {
                    $fail('cannot be sliced with null end: {type} is not countable');
                }
                /** @psalm-suppress PossiblyInvalidArgument */
                $end = count($container); // means "slice to the end"
            } elseif ($end < 0) {
                if (!$isCountable) {
                    $fail('cannot be sliced with negative end: {type} is not countable');
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
                    if ($container->offsetExists($k)) {
                        if ($seg->preserveKey) {
                            $slice[$k] = $container[$k] ?? null;
                        } else {
                            $slice[] = $container[$k] ?? null;
                        }
                    }
                }
            }

            $throwMode = $seg->mode ?? $context->currentMode();

            // check slice keys and throw according to $seg->throwMode
            if (ThrowMode::MISSING_KEY === $throwMode) {
                if (count($slice) < ($end - $start)) {
                    [$length, $count] = [$end - $start, count($slice)];

                    $fail("slice is missing some keys: expected $length but got $count");
                }
            } elseif (ThrowMode::NULL_VALUE === $throwMode) {
                foreach ($slice as $k => $v) {
                    if (null === $v) {
                        $fail("slice contains a null value at key `$k`");
                    }
                }
            }

            // $context->pop();

            return $slice;
        };
    }

    private static function compileBracketSegment(Seg\ParsedBracket $seg, ?\Closure $upstream, ExtractContext $context): \Closure
    {
        $throwMode = $seg->mode ?? $context->currentMode();

        $context->push(mode: $throwMode);

        $compiledBracketParts = [];
        foreach ($seg->chains as $chain) {
            $extractor = self::compileChain($chain, $context, $upstream, $seg->preserveKey, true);
            $compiledBracketParts[] = $extractor;
        }

        $context->pop();

        // bracket segment (multi)
        return function (mixed $container) use ($context, $compiledBracketParts, $upstream) {
            if ($upstream) {
                $container = $upstream($container);
            }

            $bracket = [];
            $stackLevel = $context->getStackLevel();
            foreach ($compiledBracketParts as $compiledChainExtractor) {
                $result = $compiledChainExtractor($container);
                $context->resetStackLevel($stackLevel); // reset stack level after each chain extraction
                if ($result) {
                    [$key, $value] = $result;

                    // no need to catch exceptions here, they're already handled in the compiled chain
                    null === $key
                        ? ($bracket[] = $value)
                        : ($bracket[$key] = $value);
                }
            }

            $context->pop(); // mode

            return 1 === count($bracket) && array_is_list($bracket)
                ? reset($bracket) // if there's only one item in the bracket and preserveKey is false, return it directly
                : $bracket; // otherwise, return the whole bracket array
        };
    }
}
