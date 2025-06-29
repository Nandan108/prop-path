## PropPath Syntax Reference

This document explains the syntax and semantics of PropPath expressions.
It covers root chains, segment types, throw modes, special operators, and advanced usage like bracket mapping and recursive descent.

---

### 📐 EBNF Summary

```ebnf
rootChain   = path, { "??", path } ; (* top-level syntax *)
chain       = [ path, "=>" ], path, { "??", [ path, "=>" ], path } ;
path        = [ root ], segment, { segment } ;
root        = "$", [ identifier ], "." ;
segment     = [ "!" | "!!" | "?" ], [ "@" ], segmentBody ;
segmentBody = identifier
            | integer
            | literal
            | bracket
            | slice
            | regExp
            | flatten
            | onEach
            | onEachRecursive ;
slice       = [ integer ], ":", [ integer ] ;
regExp      = "/", { charExcept("/", "\\") | "\\" char }, "/", [ flags ] ;
flags       = { "i" | "m" | "s" | "u" | "x" } ;
bracket     = "[", chain, { ",", chain }, "]" ;
flatten     = "~" ;
onEach      = "*", [ integer ] ;
onEachRecursive = "**" ;
literal     = "'", { charExcept("'", "\\") | "\\" char }, "'"
            | "\"", { charExcept("\"", "\\") | "\\" char }, "\"";

comment      = blockComment | lineComment ;
blockComment = "/*", { character - "*/" }, "*/" ;
lineComment  = "//", { character - newline }, newline ;
```

Additional notes:
- **Whitespace**: Space characters (those for which [`ctype_space()`](https://www.php.net/manual/en/function.ctype-space.php) returns true) are simply ignored outside of literals. Although they can be used to separate path segments, using a dot preferred for clarity.
- **Quoted strings**: Both single and double-quotes are valid for literal keys: `'my.key'` and `"my.key"` behave identically.
- **Comments**: C-style line (`// ...`) and block (`/* ... */`) comments are supported and ignored by the parser.
- **RootChain**: The `rootChain` rule defines the top-level syntax accepted by `PropPath::compile(string $paths)` and `PropPath::extract(string $paths, array $roots)`.
If you pass an array instead of a string, it is treated as a structured mode definition.

---

## Chains

Chains are expressions composed of multiple `path` segments joined by fallback operators (`??`). The first non-null result is returned.
Within brackets, chains allow per-fallback key resolution — e.g. `['literalKey' => foo.bar ?? dynamic.key => qux ?? foo.@preservedKey.bar ?? "default", 'nextKey' => next.val ?? fallbackValue]` — enabling fallback logic for both value and key in associative outputs.

Note: if a key is specified in the first path in the chain, it will be inherited by subsequent fallbacks unless overridden.

---

## Path Segment Prefixes

### ThrowMode Prefixes: `?`, `!`, `!!`

Segment-level control for failure handling:

* `?` → `ThrowMode::NEVER`: return `null` if access fails
* `!` → `ThrowMode::MISSING_KEY`: throw if the key is missing
* `!!` → `ThrowMode::NULL_VALUE`: throw if value is `null`

In a fallback chain (`foo ?? bar`), only the last path inherits the global throw mode. All preceding paths default to `NEVER`.

### `@` Prefix: Work with Keys

The meaning of `@` depends on context:

* **On key segments**: In brackets, prefix a key segment to use its name as the resulting key
  *Note: you may only have one `@` key prefix in a given path.*
* **On brackets**: prefix the entire bracket to derive keys from first segment of each chain
    E.g. `@[foo.bar, baz.fux]` = `[@foo.bar, @baz.fux]` = `['foo' => foo.bar, 'baz' => baz.fux]`
* **On flatten/onEach segments**: preserves keys in the resulting array
* **On RegExp** segments: matches against keys rather than values (keys are always preserved).

Examples:

```php
// key derived from segment
[foo.@name, foo.@group.name] → ['name' => 'Alice', 'group' => 'FooBar Group']

// preserve keys while flattening
foo.bar.@~

// filter out keys not starting with 'a'
@/^a/
```

---

## Path Segment Types

### Root Segments

PropPath supports multiple roots. A root is referenced via `$`, optionally followed by a name: `$dto.`, `$context.`
If omitted, the default root is used.

```php
$roots = [
  'foo' => ['foo-0', 'foo-1', ['foo-2-0', 'foo-2-1', 'foo-2-2']],
  'bar' => ['bar-0', 'bar-1', ['bar-2-0', 'bar-2-1', 'bar-2-2']],
];
PropPath::extract('$', $roots);          // $roots['foo']
PropPath::extract('$foo.0', $roots);     // 'foo-0'
PropPath::extract('$bar.0', $roots);     // 'bar-0'
PropPath::extract('2[1]', $roots);       // 'foo-2-1'
PropPath::extract('2[$.1, 1, $bar.1]', $roots); // ['foo-1', 'foo-2-1', 'bar-1']
```

### Key Segments

* **Identifiers**: e.g. `foo`, `bar_baz` (any valid php variable name, UTF-8 chars excluded)
* **Integer indexes**: e.g. `foo.3`
* **Literals**: e.g. `foo."non-id/key"`

⚠️ A single literal (e.g. `"hello"`) without a prefix is treated as a literal value, not a key.
To access a literal key, prefix with dot : `$foo[."some/key", .'*1337*']`

**ThrowMode** on Key segments
- When the mode is `MISSING_KEY` key segments will throw if that key does not exist.
- When the mode is `NULL_VALUE`key segments will throw if either that key does not exist, or if its value is null.

### Slice Segments

`start:end` style segment to extract a range from indexed arrays, between start and end - 1.
Negative indices count from the end.
If start is missing, it means *start from first key*.
If end is missing, it means *to the end*.
When both are missing, the container is returned unchanged (no-op).

```php
PropPath::extract('foo.:2', $roots); // get first two elements
PropPath::extract('foo.-2:', $roots); // get last two elements
```

**ThrowMode** on Key segments
- When the mode is `MISSING_KEY` slice segments will throw if the full slice cannot be obtained for any reason (non-container, missing keys, etc..).
- When the mode is `NULL_VALUE` slice segments will throw if the result slice contains a null value.

### Bracket Segments

Brackets are used to
- build nested arrays or associative structures, or
- act as path delimiters — for example, to group the result of an on-each (*) segment before applying further operations.

* Custom literal key: `['id' => foo.id]`
* Custom dynamic key: `[foo.bar => foo.id]`
* Inherited custom key: `['bar' => foo.bar ?? baz.myBar ?? "not-found"]`
  Fallback chain links inherit custom keys declared on earlier links, but can override.
* Preserve keys: `[user.@role ?? user.@groupRole]`
* Implicit key: `@[foo.bar, bar.baz]` same as `[@foo.bar, @bar.baz]` 

⚠️ A single element bracket with no key specified returns the element's value directly, not an array. E.g. The following paths are all equivalent:
- `foo.bar.baz`
- `[foo]bar[baz]`
- `foo[bar[[baz]]]`
- `[foo.bar].baz`
- `[foo[.bar]baz]`

**ThrowMode**: ThrowMode prefixes on bracket segments set the default mode for elements within the bracket.

### On-Each Segments (`*`, `*n`, `**`)

These segments act as **wildcards** for iterating over containers:

* `*` → shallow wildcard (depth 1, direct children)
* `*2` → wildcard with limited depth (e.g. `*2`)
* `**` → recursive wildcard (depth up to 256)

Use brackets to stop downstream chaining:
  - `[**phone]0 ?? "no phone"` → get value of first 'phone' key found at any depth, or fallback
  - `store.books[*[isbn => title]].@~` → build a key-preserved map of titles by ISBN

Preserve keys with `@*` or `@**`.

### Flatten Segments `~`, `@~`

Flattens one level of nested containers.

* `~` returns merged values
* `@~` preserves keys

```php
// [['a', 'b'], ['c', 'd']] → ['a', 'b', 'c', 'd']
PropPath::extract('foo.bar.~', $roots);
```

### RegExp Segments

Filter container keys or values via regex:

* `/foo/i` filters by value
* `@/foo/` filters by key

Returns only matching items. Nulls and non-strings/stringable values are skipped.

Note that RexExp segments always preserve keys.

```php
// get array of book titles starting with 't' or 'T'
PropPath::extract('books.[*.title]./^T/i', $roots);
```

---

## Notes on Throw Modes

Flatten, RegEx and onEach segments share the same error handling behavior :

| Scenario        | `?` (`NEVER`) | `!` (`MISSING_KEY`) | `!!` (`NULL_VALUE`) |
| --------------- | ----------- | ------------------ | ------------------ |
| Not a container | `null`      | ❌ throw           | ❌ throw           |
| Empty container | `[]`        | ❌ throw           | ❌ throw           |
| Empty result    | `[]`        | `[]`               | ❌ throw           |

---

This syntax reference is a companion to the PropPath README. For installation, examples, and usage guides, see [README.md](../README.md).
