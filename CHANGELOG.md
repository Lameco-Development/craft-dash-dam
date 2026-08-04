# Changelog

## [1.2.0](https://github.com/Lameco-Development/craft-dash-dam/compare/1.1.0...1.2.0) (2026-08-04)


### Features

* sync images only, dropping video support ([#24](https://github.com/Lameco-Development/craft-dash-dam/issues/24)) ([13e4387](https://github.com/Lameco-Development/craft-dash-dam/commit/13e4387f1f80bdda10ee3c645ee9b184652d7015))

## [1.1.0](https://github.com/Lameco-Development/craft-dash-dam/compare/1.0.3...1.1.0) (2026-08-03)


### Features

* name the elements using a missing asset, and clean up the utility ([#22](https://github.com/Lameco-Development/craft-dash-dam/issues/22)) ([afe7886](https://github.com/Lameco-Development/craft-dash-dam/commit/afe78861de75d625bcf89f0c259bbe16e6be6ba4))

## [1.0.3](https://github.com/Lameco-Development/craft-dash-dam/compare/1.0.2...1.0.3) (2026-08-03)


### Bug Fixes

* verify a Dash preview is the original before serving it ([#20](https://github.com/Lameco-Development/craft-dash-dam/issues/20)) ([d0cdd87](https://github.com/Lameco-Development/craft-dash-dam/commit/d0cdd878ca2c87a10e0480fb0a6beedd19c4667c))

## [1.0.2](https://github.com/Lameco-Development/craft-dash-dam/compare/1.0.1...1.0.2) (2026-07-31)


### Bug Fixes

* surface the empty-selection state on the utility page ([#16](https://github.com/Lameco-Development/craft-dash-dam/issues/16)) ([9a719bb](https://github.com/Lameco-Development/craft-dash-dam/commit/9a719bbd06c9a61cf7889060f7c873b3ac0f68b0))

## [1.0.1](https://github.com/Lameco-Development/craft-dash-dam/compare/1.0.0...1.0.1) (2026-07-31)


### Bug Fixes

* refuse to sync when no folders are selected ([#14](https://github.com/Lameco-Development/craft-dash-dam/issues/14)) ([316350e](https://github.com/Lameco-Development/craft-dash-dam/commit/316350ee4b57e4f09424551d125c74939786a2db))

## 1.0.0 (2026-07-31)


### Features

* discover the Dash volume by filesystem type instead of a hardcoded handle ([f06de55](https://github.com/Lameco-Development/craft-dash-dam/commit/f06de55ba7430a8ef9c08d898b34d23c681512cc))
* read credentials and knobs from env-parseable plugin settings ([717bc19](https://github.com/Lameco-Development/craft-dash-dam/commit/717bc193a522baea0a05330a285f3a7e960f885b))
* rename plugin identity to dash-dam / Dash DAM ([5d21065](https://github.com/Lameco-Development/craft-dash-dam/commit/5d21065ae1238134de12c885615498305d702250))
* ship Dutch translations for every user-facing string ([2df50f5](https://github.com/Lameco-Development/craft-dash-dam/commit/2df50f5a1065d4e7e248efa3ffea9dd39a446671))


### Bug Fixes

* align nl tenant hint with the scrubbed acme example ([345f446](https://github.com/Lameco-Development/craft-dash-dam/commit/345f44678a8427a7c727ea378ee2ec2951ca5090))
* canonicalise multi-folder assets deterministically (in-scope first, alphabetical) ([04f8cec](https://github.com/Lameco-Development/craft-dash-dam/commit/04f8cecc0c3aa8af9755366e89fafa99dfb7907c))
* keep ancestors of occupied folders out of the empty-folder prune ([a740848](https://github.com/Lameco-Development/craft-dash-dam/commit/a7408488739ba36c867a7b46a3a43289d1e89f2b))
* never prune a volume root whose path is an empty string ([754b1e4](https://github.com/Lameco-Development/craft-dash-dam/commit/754b1e4e5f0b1ab22983351c6e36c0aaf266d8b2))


### Miscellaneous Chores

* add agent skills configuration ([95a6eda](https://github.com/Lameco-Development/craft-dash-dam/commit/95a6eda60cf836c940e17d4b92fd2f259a8f5c29))
* fix ECS and PHPStan findings ahead of CI ([bd18727](https://github.com/Lameco-Development/craft-dash-dam/commit/bd1872751bfb7ad254366ea68eae85e4416472a5))

## Changelog
