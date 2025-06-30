<?php

namespace Nandan108\PropPath\Parser;

use Nandan108\PropPath\Segment as Seg;
use Nandan108\PropPath\Support\ExtractContext;
use Nandan108\PropPath\Support\ThrowMode;
use Nandan108\PropPath\Support\TokenType;

final class Parser
{
    /**
     * Grammar:
     *  chain = [path, '=>'] path ('??' [path, '=>'] path)*
     *  rootChain = path ('??' path)*
     *  path = [root] (braket | '.' segment)+
     *  root = '$' [identifier] '.'
     *  bracket = '[' chain (',' chain)* ']'
     *  segment = ['!' | '!!' | '?'] ['@'] (identifier | integer | literal | bracket | slice | '~' | '*' | '**')
     */

    /**
     * Parses a fallback chain of paths, separated by `??`.
     *
     * @return array<Seg\ParsedPath|Seg\ParsedLiteral> an array of paths, each with segments and an optional key
     */
    public static function parseChain(TokenStream $ts, ExtractContext $context, bool $inBraket = false): array
    {
        $paths = [];

        while (!$ts->eof()) {
            $path = self::parseSinglePath($ts, $context, $inBraket);

            if ($ts->consume([TokenType::Arrow])) {
                $customKey = $path instanceof Seg\ParsedLiteral ? (string) $path->value : $path;
                $path = self::parseSinglePath($ts, $context, $inBraket, $customKey);
            } else {
                // a custom key given by the user, e.g. `"customKey" => somePath` on one path
                // will but used for all subsequent paths in the chain unless overridden by '@' or
                // until changed by a new custom key '=>'.
                // This allows for a natural syntax like `somekey => foo ?? bar ?? baz`.
                $path->key ??= $customKey ?? null;
            }
            $paths[] = $path;

            // After parsing a path, if we don't find a `??`, it's the end of the chain.
            if ($path instanceof Seg\ParsedLiteral || !$ts->consume([TokenType::DblQstn])) {
                break;
            }
        }

        return $paths;
    }

    /**
     * Parses a single path (sequence of segments), terminated by `??`, `,`, or `]`.
     */
    private static function parseSinglePath(TokenStream $ts, ExtractContext $context, bool $inBracket, Seg\ParsedPath|string|null $customKey = null): Seg\ParsedPath|Seg\ParsedLiteral
    {
        $pathEndTokens = [TokenType::DblQstn, TokenType::Comma, TokenType::BracketClose, TokenType::Arrow, TokenType::EOF];

        // A path may be composed of a single integer or literal string value,
        // in which case this is the value that will be used (results in a "literal" segment)
        if ($token = $ts->consume([TokenType::String])[0] ?? null) {
            // If there's no other segment after this, the path is just a literal value.
            if ($ts->peekIsOneOf(...$pathEndTokens)) {
                $literal = TokenType::String === $token->type ? $token->value : (int) $token->value;

                return new Seg\ParsedLiteral($literal, $customKey ?? null);
            }
            // If there are more segments, we rewind the stream to parse the path normally
            $ts->rewind(1);
        }

        $segments = [];
        $i = $ts->getIndex();
        $getRaw = fn (): string => $ts->valueSince($i);

        // Dollar token indicates root segment
        if ($ts->consume([TokenType::Dollar])) {
            // try to get a root name
            $ident = $ts->consume([TokenType::Identifier])[0] ?? null;

            // root segment is always the first one, and it can be:
            // - "$foo": explicit root "foo"
            // - "$.x": default root (whichever root is first in $context->roots at evaluation time)
            $segments[] = new Seg\ParsedRoot($ident?->value);

            // allow root-only paths, e.g. "$" or "$foo"
            if ($ts->peekIsOneOf(...$pathEndTokens)) {
                return new Seg\ParsedPath($segments, key: null, raw: $getRaw());
            }
        }
        // In case no root was declared, but we're in a root chain (not within a bracket segment)
        // then an implicit default root must be inserted
        elseif (!$inBracket) {
            $segments[] = new Seg\ParsedRoot(null);
        }

        $preservedKey = null;

        while (!$ts->eof()) {
            $ts->consume([TokenType::Dot]);

            $segments[] = $segment = self::parseSegment($ts, $context);
            if ($segment instanceof Seg\ParsedKey) {
                if ($segment->preserveKey) {
                    if (null !== $preservedKey) {
                        $context->failParse($ts, 'only one segment per path can have the preserve keys flag `@`');
                    }
                    $preservedKey = $segment->key;
                }
            }

            if ($ts->peekIsOneOf(...$pathEndTokens)) {
                break;
            }
        }

        return new Seg\ParsedPath(
            segments: $segments,
            key: $customKey ?? $preservedKey ?? null,
            raw: $getRaw(),
        );
    }

    /**
     * Parses a single segment (identifier, starred, or bracket).
     *
     * @return Seg\ParsedSegment associative array or object depending on your implementation
     */
    private static function parseSegment(TokenStream $ts, ExtractContext $context): Seg\ParsedSegment
    {
        $i = $ts->getIndex();

        $mode = self::parseModePrefix($ts, $context);
        $getRaw = fn (): string => $ts->valueSince($i);

        // check for preserve keys '@' flag
        $preserveKey = (bool) $ts->consume([TokenType::At]);

        // bracket segment
        if ($ts->match(TokenType::BracketOpen)) {
            $chains = self::parseBracket($ts, $context);

            return new Seg\ParsedBracket(chains: $chains, mode: $mode, raw: $getRaw(), preserveKey: $preserveKey);
        }

        // Handle standalone star segment
        if ($ts->consume([TokenType::Star])) {
            if ($ts->consume([TokenType::Star])) {
                $depth = 255;
            } else {
                $depth = ($ts->consume([TokenType::Integer])[0] ?? null)?->value ?? 1;
            }

            return new Seg\ParsedOnEach(
                depth: (int) $depth, // '**' means 255 which is ~= infinite
                mode: $mode,
                preserveKey: $preserveKey,
                raw: $getRaw(),
            );
        }

        if ($ts->consume([TokenType::Carret])) {
            // Stack reference segment, e.g. `^1`, `^n`, `^` === `^0`
            $index = (int) (($ts->consume([TokenType::Integer])[0] ?? null)?->value ?? 0);
            if ($index < 0) {
                $context->failParse($ts, 'negative indices are not allowed in ^n segments.');
            }

            return new Seg\ParsedStackRef($index, mode: $mode, raw: $getRaw());
        }

        // Identifier segment
        if ($identToken = $ts->consume([[TokenType::Identifier, TokenType::String]])[0] ?? null) {
            // If we have an identifier, we can return it directly
            return new Seg\ParsedKey(key: $identToken->value, mode: $mode, raw: $getRaw(), preserveKey: $preserveKey);
        }

        $newSlice = static function (?int $start, ?int $end) use ($mode, $getRaw, $preserveKey): Seg\ParsedSlice {
            return new Seg\ParsedSlice($start, $end, $mode, $getRaw(), $preserveKey);
        };

        // Numeric key, perhaps a slice?
        if ($start = $ts->consume([TokenType::Integer])[0] ?? null) {
            // yes, a ':' means it's a slice
            if ($ts->consume([TokenType::Colon])) {
                // slice with start index: "n:"
                $endValue = ($ts->consume([TokenType::Integer])[0] ?? null)?->value;

                return $newSlice((int) $start->value, null === $endValue ? null : (int) $endValue);
            }

            // treat single integer "n" as a key/identifier
            return new Seg\ParsedKey(key: $start->value, mode: $mode, raw: $getRaw(), preserveKey: $preserveKey);
        }
        // start with a ':' means it's a slice with no start index, e.g. ":" or ":n"
        if ($ts->consume([TokenType::Colon])) {
            // followed by an int?
            if ($end = $ts->consume([TokenType::Integer])[0] ?? null) {
                return $newSlice(null, (int) $end->value);
            }

            // slice with no indices, e.g. ":"
            return $newSlice(null, null);
        }

        // Flatten segment
        if ($ts->consume([TokenType::Tilde])) {
            return new Seg\ParsedFlatten($mode, $getRaw(), preserveKeys: $preserveKey);
        }

        // RegExp segment
        if ($regExp = $ts->consume([TokenType::RegExp])[0] ?? null) {
            /** @var non-empty-string $regExp->value */
            return new Seg\ParsedRegExp($regExp->value, $getRaw(), $mode, $preserveKey);
        }

        // if we reach here, it means we have an invalid segment
        // The ending ?? new Seg\ParsedKey('') will never be reached, but it is here to quiet intelephense,
        // and as a one-liner to avoid a non-coverable sad path.
        /** @psalm-suppress RedundantCondition */
        return $context->failParse($ts, 'expected identifier or slice, got: '.$ts->peek()->value) ?? new Seg\ParsedKey('');
    }

    /**
     * Parses a bracket expression into an array of fallback chains (one per comma).
     *
     * @return list<array<Seg\ParsedLiteral|Seg\ParsedPath>> a list of fallback chains
     */
    private static function parseBracket(TokenStream $ts, ExtractContext $context): array
    {
        $ts->expect(TokenType::BracketOpen); // '['
        $chains = [];

        while (!$ts->eof()) {
            $chains[] = self::parseChain($ts, $context, true);

            // if we find a closing bracket (before a comma), that's the end of the bracket expression
            if ($ts->consume([TokenType::BracketClose])) {
                break;
            }

            // consume the natural separator within bracket: the comma
            $ts->consume([TokenType::Comma]);

            // allow a trailing comma before the closing bracket:
            // if we find a closing bracket (after a comma), that's also the end of the bracket expression
            if ($ts->consume([TokenType::BracketClose])) {
                break;
            }
        }

        return $chains;
    }

    /**
     * Parses prefix modifiers (!, !!, ?) and returns the appropriate ThrowMode.
     *
     * @return ?ThrowMode an array with the ThrowMode and the raw prefix string
     */
    private static function parseModePrefix(TokenStream $ts, ExtractContext $context): ?ThrowMode
    {
        $bangs = $questions = null;

        while ($token = $ts->consume([[TokenType::DblBang, TokenType::Bang, TokenType::Qstn]])[0] ?? null) {
            /** @psalm-suppress UnhandledMatchCondition */
            match ($token->type) {
                TokenType::DblBang, => $bangs = max($bangs ?? 2, 2),
                TokenType::Bang,    => $bangs = max($bangs ?? 1, 1),
                TokenType::Qstn,    => $questions ??= 1,
            };
        }

        if ($bangs > 0 && $questions > 0) {
            $context->failParse($ts, 'a segment\'s mode cannot be both "required" and "optional"');
        }

        return match (true) {
            ($bangs ?? 0) > 1     => ThrowMode::NULL_VALUE,
            ($bangs ?? 0) === 1   => ThrowMode::MISSING_KEY,
            ($questions ?? 0) > 0 => ThrowMode::NEVER,
            default               => null,
        };
    }
}
