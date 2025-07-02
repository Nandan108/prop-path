# Changelog

All notable changes to this project will be documented in this file.

This project adheres to [Semantic Versioning](https://semver.org).

## [0.2.0] – 2025-06-29
[0.2.0]: https://github.com/nandan108/prop-path/compare/v0.1.0...v0.2.0

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

## [0.1.0] - 2025-06-29

Initial public release.

See [README.md](README.md) for an overview of features, usage, and API.
