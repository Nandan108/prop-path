<?php

namespace Nandan108\PropPath;

use Nandan108\PropAccess\AccessorRegistry;
use Nandan108\PropPath\Compiler\Compiler;
use Nandan108\PropPath\Support\ThrowMode;

/**
 * PropPath is a library for extracting values from nested data structures using paths.
 * It allows you to define paths to access properties in objects or elements in arrays.
 *
 * @psalm-suppress UnusedClass
 */
final class PropPath
{
    public static array $cache = [];

    public static function boot(): void
    {
        static $booted = false;

        if (!$booted) {
            AccessorRegistry::bootDefaultResolvers();

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
     * @param string|array $paths         a path or array of paths (possibly nested) to compile
     * @param mixed        $failParseWith a callable to invoke when path parsing fails (throws a SyntaxError by default)
     */
    public static function compile(
        string|array $paths,
        ?callable $failParseWith = null,
        ThrowMode $defaultThrowMode = ThrowMode::NEVER,
        bool $ignoreCache = false,
        bool $forceCacheRefresh = false,
    ): \Closure {
        $cacheKey = $defaultThrowMode->value.':'.(is_array($paths) ? hash('xxh3', serialize($paths)) : $paths);

        if (!$ignoreCache && !$forceCacheRefresh && isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        self::boot();

        // Compile just the path structure
        ['context' => $context, 'extractor' => $extractor] = Compiler::compile(
            $paths,
            $failParseWith,
            $defaultThrowMode
        );

        // Here we add a compile step, which resets the roots on the extraction context context
        $extractor = function (
            array $roots,
            ?callable $failEvalWith = null,
        ) use ($extractor, $context): mixed {
            // Prepare context by setting roots to be used for extraction
            $context->prepareForEval($roots, $failEvalWith);

            return $extractor($roots);
        };

        if (!$ignoreCache) {
            // Cache the compiled extractor
            self::$cache[$cacheKey] = $extractor;
        }

        return $extractor;
    }
}
