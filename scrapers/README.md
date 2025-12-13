# Scrapers for Offpost

This directory contains scrapers for collecting data about Norwegian public entities and related information.

## Overview

Scrapers in this directory are used to:
- Update `data/entities.json` with information about public entities
- Collect building permit data from Jupiter Byggesak systems
- Gather other publicly available information about municipalities and agencies

## Structure

- `common/` - Shared utilities and base classes used by all scrapers
- `jupiter_byggesak/` - Jupiter Byggesak scraper implementation
- Other scrapers will be added as subdirectories

## Usage

Each scraper can be run independently:

```bash
cd scrapers/jupiter_byggesak
python -m jupiter_byggesak.scraper --municipality oslo
```

## Development

See [SCRAPER_GUIDELINES.md](../SCRAPER_GUIDELINES.md) for detailed guidelines on building and maintaining scrapers.

## Testing

Run tests for all scrapers:

```bash
python -m pytest scrapers/
```

Run tests for a specific scraper:

```bash
python -m pytest scrapers/jupiter_byggesak/tests/
```

## Requirements

Install dependencies:

```bash
pip install -r requirements.txt
```

## Contributing

When adding a new scraper:
1. Follow the guidelines in SCRAPER_GUIDELINES.md
2. Include comprehensive tests
3. Update this README
4. Add configuration examples
