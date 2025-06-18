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
    public static function boot(): void
    {
        static $booted = false;

        if (!$booted) {
            AccessorRegistry::bootDefaultResolvers();

            $booted = true;
        }
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
    ): \Closure {
        if (is_array($paths)) {
            $jsonPaths = json_encode($paths);
            if (false === $jsonPaths) {
                throw new \InvalidArgumentException('Failed to serialize $paths to JSON.');
            }
        } else {
            $jsonPaths = $paths;
        }

        static $cache = [];
        $cacheKey = $defaultThrowMode->value.':'.$jsonPaths;

        if (!$ignoreCache && isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        self::boot();

        // Compile just the path structure
        ['context' => $context, 'extractor' => $extractor] = Compiler::compile(
            $paths,
            $jsonPaths,
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

        return $cache[$cacheKey] = $extractor;
    }
}
