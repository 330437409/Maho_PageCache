# Maho_PageCache

Standalone distribution of the Maho page cache module, version 0.9.1.

The repository mirrors the paths the module owns in a Maho installation:

```text
app/code/local/Maho/PageCache/
app/etc/modules/Maho_PageCache.xml
app/design/adminhtml/default/default/template/maho/pagecache/
app/locale/en_US/Maho_PageCache.csv
lib/MahoCLI/Commands/PageCache*.php
tests/Backend/Unit/PageCache/
```

This repository is the standalone distribution. The module is developed in a private Maho workspace and released here as versioned snapshots. GitHub Actions overlays each snapshot on Maho main to run the page cache test suite and linters.

## Installation

Copy `app/` and `lib/` into the root of a Maho installation. `tests/` is optional and needs the Maho test harness.

```bash
composer dump-autoload
./maho migrate
./maho cache:flush
./maho dev:admin:theme:build   # when the admin theme lacks the statistics classes
```

Then open `System > Configuration > Page Cache` and enable the mode you need.

## Documentation

- `app/code/local/Maho/PageCache/README.md`: module guide, configuration and CLI.
- `app/code/local/Maho/PageCache/CHANGELOG.md`: release notes.
- `app/code/local/Maho/PageCache/docs/`: PRD, architecture, API and ADRs.

## License

PHP code is licensed under OSL-3.0. Templates, XML and front-end assets are licensed under AFL-3.0. See `LICENSE.txt` and `LICENSE_AFL.txt`.
