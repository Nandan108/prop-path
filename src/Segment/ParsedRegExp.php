<?php

namespace Nandan108\PropPath\Segment;

use Nandan108\PropPath\Exception\SyntaxError;
use Nandan108\PropPath\Support\ThrowMode;

final class ParsedRegExp extends ParsedSegment
{
    /** @var non-empty-string */
    public string $value;

    /** @param non-empty-string $value */
    public function __construct(
        string $value,
        string $raw,
        ?ThrowMode $mode,
        public bool $filterKeys,
    ) {
        $this->value = $this->raw = $value;
        $this->mode = $mode;
        $this->raw = $raw;

        // Validate the regular expression in $value;
        // Note: PHP does not have a built-in way to validate regex syntax without executing it.
        // The following is a workaround to check if the regex is valid.
        // If the regex is invalid, preg_match() will either return false or throw an error.
        set_error_handler(
            callback: static function ($_, $msg): never { throw new \RuntimeException($msg); },
            error_levels: E_WARNING
        );
        try {
            // This will throw a SyntaxError if the regex is invalid
            /** @psalm-suppress UnusedFunctionCall */
            if (false === preg_match($value, '')) {
                // If preg_match returns false, it means there was an error in the regex
                throw new SyntaxError('Invalid regular expression: '.$value.' - '.preg_last_error_msg());
            }
        } catch (\Throwable $e) {
            $e instanceof SyntaxError && throw $e;

            // If the regular expression is invalid, we throw an error
            throw new SyntaxError('Invalid regular expression: '.$value.' - '.$e->getMessage(), 0, $e);
        } finally {
            restore_error_handler();
        }
    }
}
