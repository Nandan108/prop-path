# Changelog

All notable changes to this project will be documented in this file.

This project adheres to [Semantic Versioning](https://semver.org).

## [v0.4.1] – 2026-02-22
[v0.4.1]: https://github.com/nandan108/prop-path/compare/v0.4.0...v0.4.1

### Changed
- Bumped dependency `nandan108/prop-access` from `^0.6.0` to `^0.7.0`.

## [v0.4.0] – 2026-02-14
[v0.4.0]: https://github.com/nandan108/prop-path/compare/v0.3.0...v0.4.0

### Added
- Added `EvaluationErrorCode` enum for machine-actionable evaluation failure classification.
- `EvaluationError` now exposes machine-readable metadata:
  - `public readonly EvaluationErrorCode $errorCode`
  - `getErrorCode(): EvaluationErrorCode`
  - `getMessageParameters(): array`
  - `getPropertyPath(): ?string`
  - `getDebugInfoMap(): array`
- Added `EvaluationFailureDetails` value object to capture evaluation failure context snapshot (code, parameters, debug, modes, paths, roots, stacks, property path).
- Added `proppath.eval.invalid_key_type` (`EvaluationErrorCode::INVALID_KEY_TYPE`) for flatten operations with non-`array-key` keys when preserving keys.

### Changed
- Breaking: custom evaluation failure handlers now receive `EvaluationFailureDetails` as second argument, instead of `ExtractContext`.
  - New signature:
    ```php
    fn (string $message, EvaluationFailureDetails $failure): never
    ```
- Replaced internal `fail(...)` usage with `failEval(...)` and propagated structured `EvaluationErrorCode`/parameters across compiler failure paths.
- `ExtractContext::failEval()` now auto-infers `containerType` and `key` parameters when provided.
- Default evaluation error formatting now uses the failure snapshot.
- Fixed custom eval failure handlers so they are scoped per extraction call and no longer leak into subsequent calls of the same compiled extractor.
- Internal `valueStack` is now reset in `prepareForEval()` to avoid stale stack data between extractions.
- Improved regex validation safety by temporarily overriding error handler
- Parser now throws a dedicated `SyntaxError` for unterminated quoted string literals (with start position).
- Refactored recursive traversal (`onEach`) internals to iterate objects through `AccessProxy`.
- Flatten now correctly processes `Traversable` inputs (instead of returning them unchanged).
- Flatten now validates preserved keys and fails with a typed evaluation error instead of relying on PHP offset-type failures.

## [v0.3.0] – 2025-07-06
[v0.3.0]: https://github.com/nandan108/prop-path/compare/v0.2.2...v0.3.0

### Changed
- Refactored internal error handling for clarity and compatibility with PHP 8.1.
- The evaluation failure handler (`failWith`) now receives an `ExtractContext` object as its second argument, instead of relying on `$this` inside the closure.
  - This avoids closure rebinding and improves static analysis compatibility.
  - If you passed a custom failure handler to `PropPath::compile()` or `ExtractContext::prepareForEval()`, update its signature to:
    ```php
    fn (string $message, ExtractContext $context): never
    ```
- Removed `@internal` annotation from `ExtractContext` class


## [v0.2.2] – 2025-07-05
[v0.2.2]: https://github.com/nandan108/prop-path/compare/v0.2.1...v0.2.2

### Changed
- Bumped dependency to `nandan108/prop-access` v0.5.0 to align with updated exception hierarchy.
- Tightened types on public APIs for better developer experience and improved static analysis (e.g. when consumed by `dto-toolkit`).

## [v0.2.1] – 2025-07-01
[v0.2.1]: https://github.com/nandan108/prop-path/compare/v0.2.0...v0.2.1

### Changed
- Replaced abstract `PropPathException` class with a `PropPathException` interface (`Contract\PropPathException`)
- `SyntaxError` now extends `\InvalidArgumentException`, and `EvaluationError` extends `\RuntimeException`
- Both error classes implement `PropPathException` for unified exception handling
- Refactored `ExtractContext::failWith()` to delegate formatting logic to `getEvalErrorMessage()`, making it easier to override or customize


## [v0.2.0] – 2025-06-29
[v0.2.0]: https://github.com/nandan108/prop-path/compare/v0.1.0...v0.2.0

### Added

* New `^n` **StackRef segment**, allowing access to previously resolved values.

  * `^0` or `^` refers to the current value (`foo.bar.^` === `foo.bar`)
  * `^1` to its parent, (`foo.bar.^1` === `foo`), and so on
  * Useful in on-each + bracket expressions like `books[*isbn[^ => ^1.title]].@~`
  * ThrowMode prefixes (`!`, `?`, `!!`) are supported; key prefix (`@`) is ignored

### Changed

* Internally refactored segment compiler logic to use a `valueStack`
* Introduced `wrapUpstream()` helper to reduce boilerplate in compiled closures
* Brackets receiving a null value (e.g. isbn is null in above example) now return
  `null` instead of evaluating the bracket expression and returning the result.

## v0.1.0 - 2025-06-29

Initial public release.

See [README.md](README.md) for an overview of features, usage, and API.
