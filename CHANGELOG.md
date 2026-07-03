# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Core contracts: `Policy`, `StateStore`, `Clock` and `Randomizer`, plus the immutable `Context`.
- `InMemoryStore`, a process-local state store for tests, CLI scripts and single-process workers.
- Deterministic test doubles (`FakeClock`, `FakeRandomizer`) and a shared behavioral contract test for stores.
- Tooling baseline: PHPUnit 12, PHPStan level 9, PHP CS Fixer and GitHub Actions CI covering PHP 8.3, 8.4 and 8.5.
