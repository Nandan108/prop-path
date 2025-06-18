<?php

namespace Nandan108\PropPath\Parser;

use Nandan108\PropPath\Segment as Seg;
use Nandan108\PropPath\Support\ExtractContext;
use Nandan108\PropPath\Support\ThrowMode;
use Nandan108\PropPath\Support\TokenType;

final class Parser
{
    /**
     * Parses a fallback chain of paths, separated by `??`.
     *
     * @return array<Seg\ParsedPath|Seg\ParsedLiteral> an array of paths, each with segments and an optional key
     */
    public static function parseChain(TokenStream $ts, ExtractContext $context, bool $inBraket = false): array
    {
        $paths = [];

        while (!$ts->eof()) {
            $paths[] = $path = self::parseSinglePath($ts, $context, $inBraket);

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
    private static function parseSinglePath(TokenStream $ts, ExtractContext $context, bool $inBracket): Seg\ParsedPath|Seg\ParsedLiteral
    {
        $segments = [];

        $i = $ts->getIndex();
        $getRaw = fn (): string => $ts->valueSince($i);

        if ($customKeyToken = $ts->consume([
            [TokenType::Identifier, TokenType::Integer, TokenType::String],
            TokenType::Arrow,
        ])[0] ?? null) {
            // if the first token is an identifier followed by '=>', it's a key for the path
            $customKey = $customKeyToken->value;
        }

        // A path may be composed of a single integer or literal string value, in which case this is the
        // value that will be used.
        if ($token = $ts->consume([[TokenType::String, TokenType::Integer]])[0] ?? null) {
            $literal = TokenType::String === $token->type ? $token->value : (int) $token->value;

            return new Seg\ParsedLiteral($literal, $customKey ?? null);
        }

        // Dollar token indicates root segment
        if ($ts->consume([TokenType::Dollar])) {
            // try to get a root name
            $ident = $ts->consume([TokenType::Identifier])[0] ?? null;

            // root segment is always the first one, and it can be:
            // - "$foo.": explicit root "foo"
            // - "$.": default root (whichever root is first in $context->roots at evaluation time)
            $segments[] = new Seg\ParsedRoot($ident?->value);

            // then a dot, regardless of whether it's a root alias or an implicit default root
            $ts->expect(TokenType::Dot);
        }
        // In case no root was declared, but we're in a root chain (not within a bracket segment)
        // then an implicit default root must be inserted
        elseif (!$inBracket) {
            $segments[] = new Seg\ParsedRoot(null);
        }

        $preservedKey = null;

        while (!$ts->eof()) {
            $segments[] = $segment = self::parseSegment($ts, $context);
            if ($segment instanceof Seg\ParsedKey) {
                if ($segment->preserveKey) {
                    if (null !== $preservedKey) {
                        $context->failParse($ts, 'only one segment per path can have the preserve keys flag `@`');
                    }
                    $preservedKey = $segment->key;
                }
            }

            if ($ts->peekIsOneOf(TokenType::DblQstn, TokenType::Comma, TokenType::BracketClose)) {
                break;
            }

            $ts->consume([TokenType::Dot]);
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
        $mode = self::parseModePrefix($ts, $context);
        $i = $ts->getIndex();
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
            $depth = 1;
            while ($ts->consume([TokenType::Star])) {
                ++$depth;
            }

            return new Seg\ParsedRecursive(
                depth: $depth > 1 ? -1 : 1, // '**' means infinite
                mode: $mode,
                preserveKey: $preserveKey,
            );
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

        // if we reach here, it means we have an invalid segment
        // The ending ?? new Seg\ParsedKey('') will never be reached, but it is here to quiet intelephense,
        // and as a one-liner to avoid a non-coverable sad path.
        /** @psalm-suppress RedundantCondition */
        return $context->failParse($ts, 'expected identifier or slice, got: '.$ts->peek()->value) ?? new Seg\ParsedKey('');
    }

    /**
     * Parses a bracket expression into an array of fallback chains (one per comma).
     *
     * @return array<array<Seg\ParsedLiteral|Seg\ParsedPath>> a list of fallback chains
     */
    private static function parseBracket(TokenStream $ts, ExtractContext $context): array
    {
        $ts->expect(TokenType::BracketOpen); // '['
        $chains = [];

        while (!$ts->eof()) {
            $chains[] = self::parseChain($ts, $context, true);

            // skip comma
            if ($ts->consume([TokenType::Comma])) {
                continue;
            }

            // skip ']'
            if ($ts->consume([TokenType::BracketClose])) {
                break;
            }

            $token = json_encode($ts->peek()->value);
            $context->failParse($ts, "Unexpected token $token in bracket expression", 1);
        }

        $chains or $context->failParse($ts, 'invalid empty bracket expression');

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
