# PropPath

![CI](https://github.com/nandan108/prop-path/actions/workflows/ci.yml/badge.svg)
![Coverage](https://codecov.io/gh/nandan108/prop-path/branch/main/graph/badge.svg)
![Style](https://img.shields.io/badge/style-php--cs--fixer-brightgreen)
![Packagist](https://img.shields.io/packagist/v/nandan108/prop-path)
[![Psalm Level](https://img.shields.io/badge/psalm-level--1-brightgreen)](https://psalm.dev/)

> **TL;DR** PropPath is a powerful query engine for PHP that lets you extract deeply nested values from arrays and objects using concise, expressive syntax. Inspired by JSONPath but tailored for modern PHP, it supports recursive traversal, multi-key mapping, fallback resolution, bracket grouping, and structured mode — all compiled into fast, reusable closures.

---

## What is PropPath?

**PropPath** is a feature-rich, extensible query engine for extracting values from complex PHP object graphs, arrays, or a mix of both. Inspired by JSONPath and built for modern PHP codebases, PropPath compiles string-based or structured path expressions into efficient extractor closures that traverse nested structures using a powerful set of operators.

It powers advanced features in the [DTO Toolkit](https://packagist.org/packages/nandan108/dto-toolkit), enabling concise and expressive mapping between input payloads and strongly typed DTOs.

---

### Use cases and philosophy

PropPath is designed for:

* **Structured data extraction** from deeply nested objects or mixed arrays
* **Declarative field mapping** in DTO systems, data transformation layers, or form normalizers
* **Reusable compiled resolvers**, allowing precompiled paths to be cached or reused

It follows a few guiding principles:

* **Minimalism**: Do one thing well — extract values, not transform or mutate them.
* **Expressive and powerful**: Supports structured extraction, shallow and recursive wildcards, multi-key mapping, fallback resolution, flattening, and more.
* **Clarity over magic**: Although expressive, the syntax is designed to be predictable and consistent.

---

## 📦 Installation

```bash
composer require nandan108/prop-path
```

- Requires **PHP 8.1+**
- Only *one* runtime dependency: [nandan108/prop-access](https://github.com/nandan108/prop-access)

---

## 🚀 Quick Start

PropPath compiles a path string (or structured array) into an extractor closure:

```php
use Nandan108\PropPath\PropPath;
$roots = ['dto' => ['user' => ['email' => 'jane@example.com']]];
$extractor = PropPath::extract('$dto.user.email', $roots);
// $email === 'jane@example.com'
```

The compiled closure takes an associative array of **roots** as its argument.

### 📚 Nested Example

```php
$data = [
    'dto' => [
        'user' => [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'addresses' => [
                'home' => ['city' => 'Geneva', 'zip' => '1201'],
                'office' => ['city' => 'Vernier', 'zip' => '1214', 'phone' => '1234'],
            ],
        ],
    ],
    'context' => ['request' => ['search' => 'foo']],
];

$extractor = PropPath::compile('$dto.user.addresses.home.city');
$homeCity = $extractor($data); // 'Geneva'

// direct extraction:
PropPath::extract('$dto.user["homeCity" => addresses.home.city]', $data);
// ['homeCity' => 'Geneva']

PropPath::extract('user[
    // extract map of zip codes by city name from addresses
    "zips"  => addresses[*[city => zip]]@~,
    // get value of first "phone" field anywhere in structure,
    // (default to "no phone")
    "phone" => [**phone ?? "no phone"],
    "fax"   => [**fax ?? "no fax"],
    // grab "search" => request.search from $context (not default root)
    $context.request.@search
]', $data);
// $result === [
//     'zips' => ['Geneva' => '1201', 'Vernier' => 1214],
//     'phone' => '1234',
//     'fax' => 'no fax',
//     'search' => 'foo',
// ];
```

---

## 🧩 Syntax Reference

Find the full syntax reference at [docs/Syntax.md](docs/Syntax.md)

---

## 🧵 Structured Mode

Instead of a single path string, you can pass an array structure:

```php
$roots = ['root' => ['path' => ['a', 'b', 'c']]];
$result = PropPath::extract(['foo' => 'path.0', ['path.1', ['path.2']]], $roots);
// ['foo' => 'a', ['b', ['c']]]
```

This allows you to mirror a desired shape without building complex bracket paths.

---

## 🧠 How It Compares to JSONPath

PropPath is inspired by JSONPath but diverges where needed for better PHP ergonomics:

| Feature                          | JSONPath     | PropPath                          |
| -------------------------------- | ------------ | --------------------------------- |
| Root marker                      | `$`          | `$`, `$dto`, `$context`, etc.     |
| Wildcard                         | `*`          | `*`, with depth control           |
| Recursive descent                | `..`         | `**`                              |
| Filters                          | ✅            | 🔸 Not supported (may be added later via `symfony/expression-language`) |
| Multi-key extraction             | ❌            | ✅ `[foo, bar]` or `['x' => path]` |
| Fallback resolution (`??`)       | ❌            | ✅                                |
| Array flattening                 | ❌            | ✅ `~`, `@~`                       |
| Structured input mode            | ❌            | ✅                                |

> 🧠 **Container-agnostic access**
> JSONPath uses different syntax to access objects vs arrays. PropPath does not. It uses a unified syntax for all container types (arrays, objects, or `ArrayAccess`).
>
> Brackets in PropPath do **not** indicate container type — they serve to:
> 1. Build arrays from multiple values
> 2. Group expressions for correct evaluation order
> For example, `foo.*.bar.0` applies `.0` per item. `[foo.*.bar].0` applies `.0` to the overall result.

---

## 📌 Performance Notes

PropPath compiles each path into a memoized closure using an `xxh3`-based hash.
Structured and recursive queries (`**`) may be slower; typical paths are fast and safe to cache.

---

## ⚙️ Tooling + Integration

- PropPath depends only on [`nandan108/prop-access`](https://github.com/nandan108/prop-access)
- Integrates with [`dto-toolkit`](https://packagist.org/packages/nandan108/dto-toolkit)
- Easily pluggable with Laravel, Symfony, or standalone projects

---

## 🛠 API Reference

```php
PropPath::compile(string|array $paths, ...): \Closure
PropPath::extract(string|array $paths, array $roots, ...): mixed
PropPath::clearCache(): void
PropPath::boot(): void
```

### Evaluation Failures

`PropPath::compile()` returns an extractor with this shape:

```php
fn (array $roots, ?Closure $failEvalWith = null): mixed
```

Custom evaluation handlers receive a failure snapshot as second argument:

```php
use Nandan108\PropPath\Support\EvaluationFailureDetails;

$extractor = PropPath::compile('!user.email');

$value = $extractor($roots, function (string $message, EvaluationFailureDetails $failure): never {
    // Example: machine-readable code + normalized path
    throw new RuntimeException($failure->code->value.' at '.$failure->getPropertyPath());
});
```

When using the default handler, `EvaluationError` exposes machine-readable fields:

```php
try {
    PropPath::extract('!missing', ['value' => []]);
} catch (\Nandan108\PropPath\Exception\EvaluationError $e) {
    $code = $e->getErrorCode();               // EvaluationErrorCode enum
    $params = $e->getMessageParameters();     // includes "errorCode", often "key"/"containerType"
    $path = $e->getPropertyPath();            // e.g. "$value.missing"
    $debug = $e->getDebugInfoMap();
}
```

---

## ✅ Quality

- 100% test coverage
- Psalm: level 1 (the strictest)
- Code style enforced with [PHP-CS-Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer):
  - Based on the `@Symfony` rule set
  - Aligned `=>` for better readability
  - Disallows implicit loose comparisons (`==`, `!=`)


---

## 📄 License and Attribution

MIT License © [nandan108](https://github.com/nandan108)
Author: Samuel de Rougemont
