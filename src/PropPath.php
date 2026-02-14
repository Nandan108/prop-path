<?php

namespace Nandan108\PropPath;

use Nandan108\PropAccess\PropAccess;
use Nandan108\PropPath\Compiler\Compiler;
use Nandan108\PropPath\Exception\SyntaxError;
use Nandan108\PropPath\Support\EvaluationFailureDetails;
use Nandan108\PropPath\Support\ThrowMode;

/**
 * PropPath is a library for extracting values from nested data structures using paths.
 * It allows you to define paths to access properties in objects or elements in arrays.
 *
 * @psalm-suppress UnusedClass
 */
final class PropPath
{
    /** @var array<string, \Closure(array, ?\Closure(string, EvaluationFailureDetails): never=): mixed> */
    public static array $cache = [];

    public static function boot(): void
    {
        static $booted = false;

        if (!$booted) {
            PropAccess::bootDefaultResolvers();

            $booted = true;
        }
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Compile a path or a list of paths into a closure.
     *
     * @param string|array                     $paths         a path or array of paths (possibly nested) to compile
     * @param \Closure(string, ?string): never $failParseWith a callable to invoke when path parsing fails (throws a SyntaxError by default)
     *
     * @return \Closure(array, ?\Closure(string, EvaluationFailureDetails): never=): mixed
     *
     * @throws \JsonException in case of invalid JSON encoding of paths
     * @throws SyntaxError    if a syntax  is invalid
     */
    public static function compile(
        string|array $paths,
        ThrowMode $defaultThrowMode = ThrowMode::NEVER,
        ?\Closure $failParseWith = null,
        bool $ignoreCache = false,
        bool $forceCacheRefresh = false,
    ): \Closure {
        $cacheKey = $defaultThrowMode->value.':'.hash(
            'xxh3',
            is_array($paths)
                ? json_encode($paths, JSON_THROW_ON_ERROR)
                : $paths
        );

        if (!$ignoreCache && !$forceCacheRefresh && isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // Compile just the path structure
        ['context' => $context, 'extractor' => $innerExtractor] = Compiler::compile(
            $paths,
            $failParseWith,
            $defaultThrowMode
        );

        // Here we add a compile step, which resets the roots on the extraction context,
        // and possibly sets a custom failure handler.
        $extractor =
            /**
             * @param array<array-key, mixed>                            $roots
             * @param ?\Closure(string, EvaluationFailureDetails): never $failEvalWith
             **/
            function (array $roots, ?\Closure $failEvalWith = null) use ($innerExtractor, $context): mixed {
                // Prepare context by setting roots to be used for extraction
                $context->prepareForEval($roots, failWith: $failEvalWith);

                return $innerExtractor();
            };

        if (!$ignoreCache) {
            // Cache the compiled extractor
            self::$cache[$cacheKey] = $extractor;
        }

        return $extractor;
    }

    /**
     * Extract a value from the roots using the given path or paths.
     *
     * @param \Closure(string, ?string): never $failParseWith
     */
    public static function extract(string|array $paths, array $roots, ThrowMode $throwMode = ThrowMode::NEVER, ?callable $failParseWith = null): mixed
    {
        self::boot();

        // Compile the path structure and return the extractor
        $extractor = self::compile($paths, $throwMode, $failParseWith, ignoreCache: true);

        // Call the extractor with the roots
        return $extractor($roots);
    }
}
