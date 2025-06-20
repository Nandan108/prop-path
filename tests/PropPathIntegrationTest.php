<?php

namespace Tests\Integration;

use Nandan108\PropPath\Exception\EvaluationError;
use Nandan108\PropPath\Exception\SyntaxError;
use Nandan108\PropPath\Parser\TokenStream;
use Nandan108\PropPath\PropPath;
use Nandan108\PropPath\Support\ThrowMode;
use PHPUnit\Framework\TestCase;

/**
 * @psalm-suppress UnusedClass
 */
final class PropPathIntegrationTest extends TestCase
{
    private array $roots = [];
    private ?object $foo = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->foo = $foo = new class {
            public int $bar = 5;
            public ?string $isNull = null;
            public array $zab = ['bar' => 'yes', 'can' => 'no'];
            public array $zab_baz = ['bar' => 'man', 'x' => 'y', 'zap' => null];

            public function getZabBaz(): array
            {
                return $this->zab_baz;
            }
        };

        /** @psalm-suppress MissingTemplateParam */
        $barr = new class implements \ArrayAccess {
            private function getOffset(mixed $key): int
            {
                return is_numeric($key) ? (int) $key : strlen((string) $key);
            }

            #[\Override]
            public function offsetExists(mixed $offset): bool
            {
                $offset = $this->getOffset($offset);

                return $offset >= 0 && $offset < 10;
            }

            #[\Override]
            public function offsetGet(mixed $offset): mixed
            {
                $offset = $this->getOffset($offset);
                $range = range($offset, $offset + 2);

                /** @psalm-suppress InvalidScalarArgument */
                return array_combine($range, $range);
            }

            #[\Override]
            public function offsetSet(mixed $offset, mixed $value): void
            {
                // No-op
            }

            #[\Override]
            public function offsetUnset(mixed $offset): void
            {
                // No-op
            }
        };

        $this->roots = [
            'value' => [
                'foo'      => $foo,
                'barr'     => $barr,
                '::'       => 'fwak',
                'boo'      => 'no',
                'list'     => range(0, 10),
                'hasNull'  => [0, 1, null, 2, 3],
                'qux'      => [
                    'bar' => 'can',
                    'x'   => 7,
                    'y'   => 8,
                    'z'   => 9,
                ],
                'quux' => [
                    ['x' => 1, 'y' => 2],
                    ['x' => 3, 'y' => null],
                    ['x' => 5, 'y' => 6],
                    null,
                ],
                'stdObj' => (object) [
                    'foo' => 'bar',
                    'baz' => 'qux',
                ],
            ],
            'dto' => [
                'foo'  => ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
                'bar'  => 'we',
                'book' => [
                    ['isbn' => '916-x', 'title' => 'B.One'],
                    ['isbn' => '923-x', 'title' => 'B.Two'],
                    ['isbn' => '912-x', 'title' => 'B.Three'],
                ],
            ],
            'doop' => [
                'baz' => 'yes',
                'bar' => 'no',
                'zab' => [
                    'bar' => 'we',
                    'nab' => ['baz' => 'can'],
                ],
            ],
        ];
    }

    private function extract(array|string $path, ?callable $failWith = null, ThrowMode $throwMode = ThrowMode::NEVER): mixed
    {
        $extractor = PropPath::compile($path, $failWith, defaultThrowMode: $throwMode);

        return $extractor($this->roots);
    }

    public function testTokenizer(): void
    {
        $dump = TokenStream::fromString('$foo.[1:3]')->dumpTokens();
        $this->assertEquals(
            [
                ['Dollar', '$'],
                ['Identifier', 'foo'],
                ['Dot', '.'],
                ['BracketOpen', '['],
                ['Integer', '1'],
                ['Colon', ':'],
                ['Integer', '3'],
                ['BracketClose', ']'],
            ],
            $dump,
        );
    }

    public function testGrabAllWithStars(): void
    {
        PropPath::clearCache();
        $this->assertEquals(count(PropPath::$cache), 0);

        // single stars
        $this->assertEquals(
            ['bar' => 'no', 'baz' => 'yes', 'zab' => ['nab' => ['baz' => 'can'], 'bar' => 'we']],
            $this->extract('$doop.@*')
        );

        // double stars recurse, and with key preservation there's a risk of overwriting keys, as seen here.
        $this->assertEquals(
            ['bar' => 'we', 'baz' => 'can', 'zab' => ['nab' => ['baz' => 'can'], 'bar' => 'we'], 'nab' => ['baz' => 'can']],
            $this->extract('$doop.@**')
        );

        $this->assertEquals(count(PropPath::$cache), 2);
        PropPath::clearCache();
        $this->assertEquals(count(PropPath::$cache), 0);
    }

    public function testTokenization(): void
    {
        $tokens = TokenStream::fromString('$dto.foo.bar')->getTokens();
        $this->assertCount(7, $tokens);

        $tokens2 = TokenStream::fromString('$dto."foo".bar')->getTokens();
        $this->assertCount(7, $tokens2);
    }

    public function testItResolvesSimplePropertyAccess(): void
    {
        $this->assertEquals('no', $this->extract('boo')); // Implicit default root
        $this->assertEquals('we', $this->extract('$dto.bar')); // Named root
        $this->assertEquals(5, $this->extract('$.foo.bar')); // explicit default root
        $this->assertEquals(5, $this->extract('foo.bar')); // Implicit default root
        $this->assertEquals('yes', $this->extract('foo.zab.bar')); // Nested property
        $this->assertEquals(5, $this->extract('quux.2.x')); //  integer key access
        $this->assertEquals($this->foo?->getZabBaz(), $this->extract('foo.zab_baz')); // Nested property
        $this->assertEquals($this->foo?->getZabBaz(), $this->extract('foo.zabBaz')); // Nested property
    }

    public function testItResolvesLiteralKeyNames(): void
    {
        $this->assertEquals(5, $this->extract('foo."bar"')); // Implicit default root
    }

    public function testItResolvesValuesInStdClassObjects(): void
    {
        $this->assertEquals('bar', $this->extract('stdObj.foo'));
    }

    public function testItResolvesRootSelection(): void
    {
        $this->assertEquals(
            ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
            $this->extract('$dto.foo')
        );
        $this->assertEquals(
            $this->foo,
            $this->extract('$value.foo')
        );
    }

    public function testItResolvesDynamicKeyAfterStarAndFlatten(): void
    {
        // extract all book (standard path $dto.book, nothing special here)
        $this->assertEquals(
            [
                ['isbn' => '916-x', 'title' => 'B.One'],
                ['isbn' => '923-x', 'title' => 'B.Two'],
                ['isbn' => '912-x', 'title' => 'B.Three'],
            ],
            $this->extract('$dto.book')
        );

        // for each book, extract isbn
        $this->assertEquals(['916-x', '923-x', '912-x'],
            $this->extract('$dto.book*isbn')
        );

        // for each book, extract [isbn => title]
        $this->assertEquals(
            [['916-x' => 'B.One'], ['923-x' => 'B.Two'], ['912-x' => 'B.Three']],
            $this->extract('$dto.book*[isbn => title]')
        );

        // '$dto.book*[isbn => title]~' means "in $dto.book, for each item: [make array [isbn => title] then flatten]"
        // because * consumes all the segments following it in the same path, and applies them
        // to each item it finds in the array at its own level (children of $dto.book node in this case).
        // flattening ['916-x' => 'B.One'] yields ['916-x' => 'B.One'], si here '~' makes no change.
        $this->assertEquals(
            [['916-x' => 'B.One'], ['923-x' => 'B.Two'], ['912-x' => 'B.Three']],
            $this->extract('$dto.book*[isbn => title]@~')
        );

        // However if we wrap the * and following segment to prevent * from consuming the rest of the path,
        // we get a more useful result:
        // in $dto.book, [for each item, make array [isbn => title]], then flatten (not preserving keys)
        $this->assertEquals(
            ['B.One', 'B.Two', 'B.Three'],
            $this->extract('$dto.book[*[isbn => title]]~')
        );

        // in $dto.book, [for each item, make array [isbn => title]], then @flatten (preserving keys)
        $this->assertEquals(
            ['916-x' => 'B.One', '923-x' => 'B.Two', '912-x' => 'B.Three'],
            $this->extract('$dto.book[*[isbn => title]]@~')
        );
    }

    public function testItCanFlattenObjects(): void
    {
        $this->roots['foo'] = (object) [
            'bar' => ['a' => 1, 'b' => 20],
            'baz' => ['a' => 10, 'b' => 2],
        ];
        $this->assertEquals(
            ['isNull' => null, 'can' => 'no', 'bar' => 'yes', 'x' => 'y', 'zap' => null],
            $this->extract('foo@~')
        );

        $this->assertEquals(
            ['man', 'y', null, 5, null, 'yes', 'no'],
            $this->extract('foo~')
        );
    }

    public function testItResolvesArraySlicing(): void
    {
        $list = $this->roots['dto']['foo'];

        $this->assertEquals($list, $this->extract('$dto.foo')); // Full slice
        $this->assertEquals($list, $this->extract('$dto.foo.:')); // Full slice
        $this->assertEquals(['a', 'b', 'c'], $this->extract('$dto.foo.:3')); // First 3
        $this->assertEquals(['c', 'd', 'e', 'f', 'g'], $this->extract('$dto.foo.2:')); // From index 2
        $this->assertEquals(['b', 'c'], $this->extract('$dto.foo.1:3')); // Range
        $this->assertEquals(['a', 'b', 'c', 'd'], $this->extract('$dto.foo.:-3')); // take all but the last 3
        $this->assertEquals(['e', 'f', 'g'], $this->extract('$dto.foo.-3:')); // take last 3
        $this->assertEquals(['f'], $this->extract('$dto.foo.-2:-1')); // take penultimate
    }

    public function testItFailsOnSlicesWithMissingOrNullKeys(): void
    {
        $this->assertEquals([['x' => 5, 'y' => 6], null], $this->extract('quux.2:4'));

        // slicing a non-sliceable in ThrowMode=NEVER, should return null
        $this->assertEquals(null, $this->extract('bar.2:5'));

        $this->assertThrows(
            fn () => $this->assertEquals([['x' => 5, 'y' => 6], null], $this->extract('quux.!2:5')),
            EvaluationError::class,
            'Path segment $value.`quux` slice `2:5` is missing some keys: expected 3 but got 2.'
        );

        $this->assertThrows(
            fn () => $this->assertEquals([['x' => 5, 'y' => 6], null], $this->extract('quux.!2:5')),
            EvaluationError::class,
            'Path segment $value.`quux` slice `2:5` is missing some keys: expected 3 but got 2.'
        );

        $this->assertThrows(
            fn () => $this->assertEquals([['x' => 5, 'y' => 6], null], $this->extract('qux.bar.!2:4')),
            EvaluationError::class,
            'Path segment $value.qux.`bar` cannot be sliced: string is not an array or ArrayAccess.'
        );
    }

    public function testItThrowsProperErrorsOnMissingKeysAfterRecursedAccess(): void
    {
        $this->assertThrows(
            fn () => $this->assertEquals('yes', $this->extract('**.zabBaz.!!zap')),
            EvaluationError::class,
            'Path segment $value.**.zabBaz.`zap` is null but required.'
        );

        $this->assertThrows(
            fn () => $this->assertEquals('yes', $this->extract('quux.2.!w')),
            EvaluationError::class,
            'Path segment $value.quux.2.`w` not found in array.'
        );

        $this->assertThrows(
            fn () => $this->assertEquals('yes', $this->extract('boo.!x')),
            EvaluationError::class,
            'could not be extracted from non-container of type `string`.'
        );
    }

    public function testItFailsWithCorrectErrorMessagesInComplexPaths(): void
    {
        // This is a complex path that combines various features, including bracket, null coalescing, a dynamic custom key and a slice.
        $this->assertEquals(
            ['zab' => 'yes', 'we' => 'can', 'quux' => [['x' => 5, 'y' => 6], null]],
            $this->extract('@[foo.@zab.bar, notThere ?? $dto.bar => qux.bar, quux.!2:4]')
        );

        $this->assertThrows(
            fn (): mixed => $this->extract('[foo.zab.bar, $dto.bar => qux.bar, quux.!!2:4]'),
            EvaluationError::class,
            // SyntaxError::class,
            'Path segment $value.`quux` slice `2:4` contains a null value at key `1`.'
        );
    }

    public function testItResolvesStructures(): void
    {
        $this->assertEquals(
            ['foo' => 'can', 'bar' => [5, 8]],
            $this->extract(['foo' => 'qux.bar', 'bar' => ['foo.bar', 'qux.y']])
        );
    }

    /**
     * Asserts that the given callback throws an exception of the specified class.
     *
     * @param class-string $exceptionClass
     */
    public function assertThrows(
        callable $callback,
        string $exceptionClass,
        string $message = '',
    ): void {
        try {
            $callback();
            $this->fail("Expected exception of type $exceptionClass, but none was thrown.");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exceptionClass, $e, "Expected exception of type $exceptionClass, got ".get_class($e));
            $actualMessage = $e->getMessage();
            $this->assertStringContainsString($message, $actualMessage, $message);
        }
    }

    public function testItResolvesRequiredKey(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('!missing ?? boo'),
            EvaluationError::class,
            'Path segment $value.`missing` not found in array'
        );
        $this->assertEquals('can', $this->extract('qux.bar'));
    }

    public function testItResolvesBasicChain(): void
    {
        // chain falls back all way to null
        $this->assertEquals(null, $this->extract('missing ?? anotherMissing ?? notThere ?? unknownKey'));
        // falls back to boo = 'no;
        $this->assertEquals('no', $this->extract('missing ?? anotherMissing ?? notThere ?? boo'));
        // basic chain with a fallback (a path consisting of a literal resolves to the literal value itself)
        $this->assertEquals('fallback', $this->extract('missing ?? qux.missing ?? "fallback"'));
    }

    public function testItResolvesBracketNotation(): void
    {
        $this->assertEquals(['x' => 7, 'y' => 8], $this->extract('qux@[x, y]'));
        $this->assertEquals('no', $this->extract('[boo]'));
        $this->assertEquals([5, 8], $this->extract('[foo.bar, qux.y]'));
        // A path that's only a literal will be interpretted as a literal value and returned as-is, which is useful
        // to provide a fallback value at the end of a fallback chain (e.g. "some.path ?? some.other.path ?? "default")
        // So if we need to access a key via a literal string (because it's not an identifier or int), directly under
        // a root, it needs to be preceeded by an explicit root (default or named): E.g.: $."*specialKey*"
        $this->assertEquals(
            ['fwak' => 'fwak', 'y' => 8, 'qux' => 8, 0 => 5],
            // key "::", with second colon escaped
            $this->extract('["fwak" => $.":\:", foo.bar, qux.@y, @qux.y]'
            ));
        $this->assertEquals(
            ['foo' => 'yes', 'qux' => 'can'],
            $this->extract('@[foo.zab.bar, qux.bar]')
        );
    }

    public function testItResolvesNullCoalescing(): void
    {
        $this->assertEquals('yes', $this->extract('[foo.zab.@y ?? foo.zab.bar]'));
        $this->assertEquals(['zab' => 'yes'], $this->extract('[foo.zab.@y ?? foo.@zab.bar]'));
        $this->assertEquals('default', $this->extract('[missing ?? $dto.missing ?? "default"]'));
    }

    public function testItResolvesRecursiveMatching(): void
    {
        $barr_bar = [3 => 3, 4 => 4, 5 => 5];
        // @*.bar - preserve immediate parent keys
        $this->assertEquals([
            'foo'  => 5,
            'barr' => $barr_bar,
            'qux'  => 'can',
        ], $this->extract('@*bar'));

        // *.bar - immediate children only
        $this->assertEquals([5, $barr_bar, 'can'], $this->extract('*.bar'));

        // **.bar - full recursion
        $this->assertEquals([5, 'man', 'yes', $barr_bar, 'can'], $this->extract('**.bar'));

        // **.bar - full recursion with preserveKeys
        $this->assertEquals(
            ['zabBaz' => 'man', 'zab' => 'yes', 'qux' => 'can', 'barr' => $barr_bar],
            $this->extract('@**.bar')
        );
    }

    public function testItHandlesErrorsProperly(): void
    {
        // Missing key (NEVER mode)
        $this->assertNull($this->extract('missing.key'));

        // Missing key (STRICT mode)
        $this->expectException(EvaluationError::class);
        $this->extract('!missing.key');

        // Syntax error
        $this->expectException(SyntaxError::class);
        $this->extract('invalid..path');
    }

    public function testItFailsWhenNamedRootIsMissing(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('$nope.foo'),
            EvaluationError::class,
            'Path segment `` : root `nope` not found in roots (valid roots are: "value","dto","doop").'
        );
    }

    public function testArrayAccessInNeverMode(): void
    {
        $this->assertEquals([3 => 3, 4 => 4, 5 => 5], $this->extract('barr.3')); // found
        $this->assertNull($this->extract('barr.99')); // not found, but NEVER mode
    }

    public function testBangPrefixArrayKeyFound(): void
    {
        $this->assertEquals(1, $this->extract('!hasNull.1'));
    }

    public function testBangPrefixArrayAccessKeyMissing(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('barr.!99'),
            EvaluationError::class,
            'Path segment $value.barr.`99` not found in ArrayAccess.'
        );
    }

    public function testBangPrefixArrayAccessKeyFound(): void
    {
        $this->assertEquals([3 => 3, 4 => 4, 5 => 5], $this->extract('!barr.3'));
    }

    public function testSlicesWithinArrayAccess(): void
    {
        // slice preserving
        $this->assertEquals([[3 => 3, 4 => 4, 5 => 5], [4 => 4, 5 => 5, 6 => 6]], $this->extract('barr.3:5'));
        $this->assertEquals([3 => [3 => 3, 4 => 4, 5 => 5], 4 => [4 => 4, 5 => 5, 6 => 6]], $this->extract('barr.@3:5'));
        // and single key in throwMode NULL_VALUE
        $this->assertEquals([3 => 3, 4 => 4, 5 => 5], $this->extract('!barr.!!3'));
    }

    public function testBangPrefixObjectKeyMissing(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('foo.!nope'),
            EvaluationError::class,
            'Path segment $value.foo.`nope` not accessible on class@anonymous'
        );
    }

    public function testDblBangPrefixOnBracket(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('foo.!![bar, baz]'),
            EvaluationError::class,
            'Path segment $value.foo.`baz` not accessible on class@anonymous'
        );
    }

    public function testDblBangPrefixOnBracketWithQuestionOnMissingItem(): void
    {
        // The `!!` prefix on the bracket will throw an error if any of the items is NULL or missing
        // The `?` prefix on 'baz' overrides the `!!` on the bracket, so it will not throw an error
        $this->assertSame([5, null], $this->extract('foo.!![bar, ?baz]'));
    }

    public function testDblBangPrefixOnObjectProp(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('foo.!!isNull'),
            EvaluationError::class,
            'Path segment $value.foo.`isNull` is null but required.'
        );
    }

    public function testDblBangPrefixOnBracketItem(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('foo.[!baz, bar]'),
            EvaluationError::class,
            'Path segment $value.foo.`baz` not accessible on class@anonymous'
        );
    }

    public function testBangPrefixObjectKeyFound(): void
    {
        $this->assertEquals(5, $this->extract('foo.!bar'));
    }

    public function testDoubleBangArrayAccessKeyMissing(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('barr.!!99'),
            EvaluationError::class,
            'Path segment $value.barr.`99` is null but required.'
        );
    }

    public function testDoubleBangArrayAccessKeyFound(): void
    {
        $this->assertEquals([3 => 3, 4 => 4, 5 => 5], $this->extract('!!barr.3'));
    }

    public function testDoubleBangObjectKeyMissing(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('foo.!!nope'),
            EvaluationError::class,
            'Path segment $value.foo.`nope` not accessible'
        );
    }

    public function testDoubleBangObjectKeyFound(): void
    {
        $this->assertEquals(5, $this->extract('foo.!!bar'));
    }

    public function testGetKeyInArrayAccessInMissingKeyThrowMode(): void
    {
        $this->assertEquals(4, $this->extract('barr.!bar.4'));
    }

    public function testComplexQuery(): void
    {
        $this->roots = [
            'dto' => [
                'user' => [
                    'name'      => 'Jane',
                    'email'     => 'jane@example.com',
                    'addresses' => [
                        'home'   => ['city' => 'Geneva', 'zip' => '1201'],
                        'office' => ['city' => 'Carouge', 'zip' => '1214', 'phone' => 1234],
                    ],
                ],
            ],
            'context' => ['request' => ['search' => 'foo']],
        ];

        $extracted = $this->extract('user[
            "zips" => addresses[*[city => zip]]@~,
            "phone" => [[**phone]0 ?? "no phone"],
            "fax" => [[**fax]0 ?? "no fax"],
            $context.request.@search
        ]');

        $this->assertEquals([
            'zips'   => ['Geneva' => '1201', 'Carouge' => '1214'],
            'search' => 'foo',
            'phone'  => 1234,
            'fax'    => 'no fax',
        ], $extracted);
    }

    public function testSliceOnNullContainerFails(): void
    {
        $this->roots['value']['nullish'] = null;
        $this->assertThrows(
            fn (): mixed => $this->extract('nullish.!1:2'),
            EvaluationError::class,
            'Path segment $value.`nullish` is null and cannot be sliced.'
        );
    }

    public function testSliceOnInvalidTypeFails(): void
    {
        $this->roots['value']['notSliceable'] = new \stdClass();
        $this->assertThrows(
            fn (): mixed => $this->extract('notSliceable.!1:3'),
            EvaluationError::class,
            'Path segment $value.`notSliceable` cannot be sliced: stdClass is not an array or ArrayAccess.'
        );
    }

    public function testNegativeStartFailsOnNonCountable(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('barr.!-2:3'),
            EvaluationError::class,
            'Path segment $value.`barr` cannot be sliced with negative start: ArrayAccess@anonymous is not countable.'
        );
    }

    public function testNegativeEndFailsOnNonCountable(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('barr.!1:-3'),
            EvaluationError::class,
            'Path segment $value.`barr` cannot be sliced by `1:-3` with negative end: ArrayAccess@anonymous is not countable.',
        );
    }

    public function testNullEndFailsOnNonCountable(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('barr.!3:'),
            EvaluationError::class,
            'Path segment $value.`barr` cannot be sliced by `3:` with null end: ArrayAccess@anonymous is not countable.'
        );
    }

    public function testTokenizerThrowsOnUnknownCharacter(): void
    {
        // The tokenizer should throw an error when it encounters an unknown character.
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unexpected character \'#\' at position 4');
        TokenStream::fromString('barr#');
    }

    public function testItFailsFlatteningANonContainer(): void
    {
        $this->assertSame('no', $this->extract('boo~'));

        // The tokenizer should throw an error when it encounters an unknown character.
        $this->assertThrows(
            fn (): mixed => $this->extract('boo!~'),
            EvaluationError::class,
            'Expected a container, got: string'
        );
    }

    public function testItFailsFlatteningWhenNoSubCountainersFound(): void
    {
        // The `~` operator flattens one level of sub-containers within a container.

        // One bang prefix (!~) means throw if the value is not a container.
        // $dto.quux[0] is an array, so it should not throw.
        $this->assertSame([1, 2], $this->extract('$.quux[0].!~'));

        // Two bangs (!!~) means throw if the value is not a container or if it does not contain any sub-containers.
        // $dto.quux[0] is an array but only contains scalar values, so it should throw.
        $this->expectException(EvaluationError::class);
        $this->expectExceptionMessage('Path segment $value.quux.`[0]` does not contain a sub-container to be flattened');
        $this->extract('$.quux[0].!!~');
    }

    public function testParserFailsOnMultipleAtFlagsInSinglePath(): void
    {
        // The '@' flag can only be used on one segment per path, so this should throw an error.
        // In this case, "foo.@zab.@can" would mean "use both `zab` and `can` as key" but this makes no sense,
        // since only one key can be used for a given value.
        $this->assertThrows(
            fn (): mixed => $this->extract('$.[foo.@zab.@can]'),
            SyntaxError::class,
            'Failed parsing "$.[foo.@zab.@can]" near "$.[foo.@zab.@can": only one segment per path can have the preserve keys flag `@`.'
        );
    }

    public function testParserFailsOnInvalidTokenInBracket(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract('$.[foo,=>]'),
            SyntaxError::class,
            'Failed parsing "$.[foo,=>]" near "$.[foo,": expected identifier or slice, got: =>.'
        );
    }

    public function testParserFailsOnInvalidTokenInBracket2(): void
    {
        // `baz` path segment has value 5, but next segment is a bracket in "mandatory mode" (!),
        // which expects the previous segment to be a container. 5 is not a container, so it fails.
        $this->assertThrows(
            fn (): mixed => $this->extract('foo.bar.baz![foo,1:3]'),
            EvaluationError::class,
            'Path segment $value.foo.bar.baz.`foo` could not be extracted from non-container of type `null`.'
        );
    }

    public function testParserFailsOnBangAndQuestionTogether(): void
    {
        // The bang (!) and question (?) prefixes cannot be used together, as they represent conflicting modes.
        $this->assertThrows(
            fn (): mixed => $this->extract('foo.bar.baz?![foo,1]'),
            SyntaxError::class,
            'Failed parsing "foo.bar.baz?![foo,1]" near "foo.bar.baz?!": a segment\'s mode cannot be both "required" and "optional".'
        );
    }

    public function testCompilerFailsOnEmptyPath(): void
    {
        $this->assertThrows(
            fn (): mixed => $this->extract(''),
            \InvalidArgumentException::class,
            'Cannot compile an empty path.'
        );
    }

    public function testEvaluationFailsOnEmptyArrayOrNonIdentifierRootKeys(): void
    {
        $this->roots = [];
        $this->assertThrows(
            fn (): mixed => $this->extract('foo.bar'),
            \InvalidArgumentException::class,
            'Roots must be a non-empty array.'
        );

        $this->roots = ['/' => $this->foo, 'foo' => $this->foo];
        $this->assertThrows(
            fn (): mixed => $this->extract('$foo.bar'),
            \InvalidArgumentException::class,
            'Roots keys must be identifiers (strings matching \'/^[a-z_][\w-]*$/i\').'
        );
    }
}
