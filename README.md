# Pimcore asset importer

Import assets including (sub)directories to Pimcore 5 or 6 via the command line.

## Requirements

Pimcore 5 or 6

## Installation

```bash
composer require youwe/pimcore-asset-importer:~1.0
```

## Usage

To view the available arguments and options:

```bash
./bin/console help youwe:import:assets
```

### Example

```bash
./bin/console youwe:import:assets /path/to/files/ --rootPath /Images/ --batchSize 10 --deleteOriginal --updateAssets
```

* Import assets from the /path/to/files/ directory.
* Into the Pimcore /Images/ asset folder.
* Import 10 assets at a time (not counting (sub)folders). Useful for automated import.
* Once successfully imported the original file is deleted within the /path/to/files/ directory (required for batch import).
* Assets are updated if they already exist (based on file name).
