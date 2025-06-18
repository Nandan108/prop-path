<?php

namespace Nandan108\PropPath\Parser;

use Nandan108\PropPath\Exception\SyntaxError;
use Nandan108\PropPath\Support\Token;
use Nandan108\PropPath\Support\TokenType;

final class TokenStream
{
    /** @var Token[] */
    private array $tokens;

    private int $index = 0;

    public function __construct(array $tokens)
    {
        $this->tokens = array_values($tokens); // reset keys
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * Get the array of tokens in the stream. For debugging purposes.
     *
     * @return Token[]
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    public function eof(): bool
    {
        return $this->index >= count($this->tokens) - 1;
    }

    public function current(): ?Token
    {
        return $this->tokens[$this->index] ?? null;
    }

    public function peek(int $offset = 0): Token
    {
        return $this->tokens[$this->index + $offset] ?? new Token(TokenType::EOF);
    }

    public function peekIsOneOf(TokenType ...$types): bool
    {
        $currentType = $this->peek()->type;
        foreach ($types as $type) {
            if ($currentType === $type) {
                return true;
            }
        }

        return false;
    }

    public function match(TokenType $type): bool
    {
        return $this->current()?->type === $type;
    }

    public function expect(TokenType $type, bool $consume = true): void
    {
        $token = $this->current();

        $token or throw new SyntaxError("Expected token of type {$type->name}, but reached end of stream.");

        $token->type === $type or throw new SyntaxError("Expected token of type {$type->name}, got {$token->type->name}: `{$token->value}`");

        if ($consume) {
            ++$this->index;
        }
    }

    /**
     * Consume a sequence of one or more tokens of given types
     * If the sequence is not matched, the index is reset to the previous position.
     *
     * @param array<TokenType|array<TokenType>> $typeSequence
     *
     * @return Token[] consumed tokens
     */
    public function consume(array $typeSequence): array
    {
        $tokens = [];
        foreach ($typeSequence as $type) {
            $token = $this->current();

            if ($token && in_array($token->type, is_array($type) ? $type : [$type], true)) {
                $tokens[] = $token;
                ++$this->index;
            } else {
                // reset index if we failed to consume a token
                $this->index -= count($tokens);

                return [];
            }
        }

        return $tokens;
    }

    public function valueSince(int $startIndex, int $additional = 0): string
    {
        $value = '';
        for ($i = $startIndex; $i < $this->index + $additional; ++$i) {
            $value .= $this->tokens[$i]->value;
        }

        return $value;
    }

    public static function fromString(string $input): TokenStream
    {
        $pos = 0;
        $len = strlen($input);
        $peek = function () use (&$pos, $input): string|null {
            return $input[$pos + 1] ?? null;
        };

        /** @var Token[] $tokens */
        $tokens = [];

        while ($pos < $len) {
            $c = $input[$pos];

            // Skip whitespace
            if (ctype_space($c)) {
                ++$pos;
                continue;
            }

            $token = match ($c) {
                // Single or double-character operators
                '?' => new Token('?' === $peek() ? TokenType::DblQstn : TokenType::Qstn),
                '!' => new Token('!' === $peek() ? TokenType::DblBang : TokenType::Bang),
                // Double-character token
                '=' => '>' === $peek() ? new Token(TokenType::Arrow) : null,
                // Single-character tokens
                '*'     => new Token(TokenType::Star),
                '@'     => new Token(TokenType::At),
                ':'     => new Token(TokenType::Colon),
                '$'     => new Token(TokenType::Dollar),
                ','     => new Token(TokenType::Comma),
                '['     => new Token(TokenType::BracketOpen),
                ']'     => new Token(TokenType::BracketClose),
                '.'     => new Token(TokenType::Dot),
                default => null,
            };
            if ($token) {
                $tokens[] = $token;
                $pos += strlen($token->value);
                continue;
            }

            // String literals
            if ('"' === $c || "'" === $c) {
                $quote = $c;
                ++$pos;
                $start = $pos;

                while ($pos < $len && $input[$pos] !== $quote) {
                    if ('\\' === $input[$pos]) {
                        $pos += 2;
                        continue;
                    }
                    ++$pos;
                }

                $str = substr($input, $start, $pos - $start);
                $tokens[] = new Token(TokenType::String, stripcslashes($str));
                ++$pos; // skip closing quote
                continue;
            }

            // Identifiers
            if (preg_match('/\G([a-z_][\w-]*)/iA', $input, $m, 0, $pos)) {
                $tokens[] = new Token(TokenType::Identifier, $m[1]);
                $pos += strlen($m[1]);
                continue;
            }

            // Numbers, integer
            if (preg_match('/\G(-?\d+)/', $input, $m, 0, $pos)) {
                $tokens[] = new Token(TokenType::Integer, $m[0]);
                $pos += strlen($m[0]);
                continue;
            }

            throw new SyntaxError("Unexpected character '$c' at position $pos");
        }

        $tokens[] = new Token(TokenType::EOF);

        return new self($tokens);
    }

    /**
     * Dump the types of tokens in the stream for debugging purposes.
     *
     * @return array<int, array{string, string}>
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function dumpTokens(): array
    {
        $dump = [];
        foreach ($this->tokens as $token) {
            if (TokenType::EOF === $token->type) {
                break;
            }
            $dump[] = [$token->type->name, $token->value];
        }

        return $dump;
    }
}
