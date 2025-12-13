# Jupiter Byggesak Scraper - Implementation Notes

## Overview

This document provides implementation notes for the Jupiter Byggesak scraper reference implementation created for the Offpost project.

## Background

Jupiter Byggesak is a building permit management system used by Norwegian municipalities. Each municipality may have slightly different implementations, but they share common patterns.

## Architecture

The implementation follows a modular architecture:

```
scrapers/
├── common/                    # Shared utilities
│   ├── exceptions.py         # Custom exception classes
│   ├── http_client.py        # HTTP client with retry and rate limiting
│   ├── validators.py         # Data validation utilities
│   └── tests/                # Tests for common utilities
├── jupiter_byggesak/         # Jupiter Byggesak scraper
│   ├── config.py             # Municipality configurations
│   ├── models.py             # Data models
│   ├── parser.py             # HTML parsing logic
│   ├── scraper.py            # Main scraper implementation
│   └── tests/                # Tests and fixtures
└── README.md                 # Documentation
```

## Key Features

### 1. Rate Limiting

The scraper implements rate limiting to respect target servers:

```python
rate_limiter = RateLimiter(requests_per_minute=30)
rate_limiter.wait()  # Enforces minimum interval between requests
```

### 2. Retry Logic

Automatic retry for transient failures:

```python
retry = Retry(
    total=3,
    backoff_factor=0.3,
    status_forcelist=(500, 502, 504, 429)
)
```

### 3. Error Handling

Comprehensive error handling with custom exception classes:
- `NetworkError` - Network-related failures
- `ParsingError` - HTML parsing failures
- `ValidationError` - Data validation failures
- `ConfigurationError` - Configuration issues

### 4. Data Validation

All scraped data is validated before use:
- Entity ID format validation
- Email format validation
- Norwegian organization number validation
- Required field checks

### 5. Separation of Concerns

- **Scraper**: Handles HTTP requests and orchestration
- **Parser**: Handles HTML parsing
- **Models**: Data structures
- **Validators**: Data validation logic
- **HTTP Client**: Network communication

## Testing Strategy

### Test Coverage

- 52 tests with 92% code coverage
- Unit tests for all utility functions
- Integration tests for scraper functionality
- Test fixtures for HTML parsing
- Proper mocking for network calls

### Test Structure

Tests follow the Arrange-Act-Assert pattern with clear comments:

```python
def test_example(self):
    # :: Setup
    # Test setup code
    
    # :: Act
    # Code being tested
    
    # :: Assert
    # Verification
```

### Running Tests

```bash
# Install dependencies
pip install -r scrapers/requirements.txt

# Run all tests
cd /path/to/offpost
PYTHONPATH=/path/to/offpost python -m pytest scrapers/ -v

# Run specific test file
PYTHONPATH=/path/to/offpost python -m pytest scrapers/jupiter_byggesak/tests/test_parser.py -v

# Run with coverage report
PYTHONPATH=/path/to/offpost python -m pytest scrapers/ --cov=scrapers --cov-report=html
```

## Configuration

### Municipality Configuration

Each municipality has its own configuration in `config.py`:

```python
MUNICIPALITY_CONFIGS = {
    'oslo': {
        'base_url': 'https://innsyn.oslo.kommune.no/jupiter/',
        'search_endpoint': '/search',
        'case_endpoint': '/case/{case_id}',
        'rate_limit': 30,
        'enabled': False  # Disabled by default until URLs are verified
    }
}
```

### Enabling a Municipality

To enable scraping for a municipality:

1. Verify the actual URLs for the Jupiter Byggesak system
2. Update the configuration with correct URLs
3. Set `enabled: True` in the configuration
4. Test with a small number of cases first

## Usage

### Command-Line Interface

```bash
# Scrape from a specific municipality
python -m scrapers.jupiter_byggesak.scraper --municipality test --max-cases 10

# Output to JSON file
python -m scrapers.jupiter_byggesak.scraper \
    --municipality test \
    --max-cases 10 \
    --output results.json

# Enable debug logging
python -m scrapers.jupiter_byggesak.scraper \
    --municipality test \
    --verbose
```

### Programmatic Usage

```python
from scrapers.jupiter_byggesak.scraper import JupiterByggesakScraper

# Initialize scraper
scraper = JupiterByggesakScraper('test')

# Scrape search results
results = scraper.scrape_search_results({'year': '2024'})

# Scrape case details
case = scraper.scrape_case_details('12345', '2024/1234')

# Scrape multiple cases
cases = scraper.scrape_multiple_cases(max_cases=10)

# Export to dictionary
data = scraper.export_to_dict(cases)
```

## Best Practices

### 1. Always Use Rate Limiting

Never disable rate limiting. If faster scraping is needed, increase the rate limit gradually and monitor the target server's response.

### 2. Handle Errors Gracefully

The scraper continues processing even if individual cases fail. Always check the logs for errors.

### 3. Validate Data

All scraped data should be validated before being stored or used:

```python
from scrapers.common.validators import validate_entity

is_valid, error = validate_entity(entity_data)
if not is_valid:
    logger.error(f"Invalid entity data: {error}")
```

### 4. Test with Small Datasets First

When adding a new municipality or changing the parser, test with a small number of cases first:

```python
scraper.scrape_multiple_cases(max_cases=5)
```

### 5. Monitor and Log

Always monitor scraping operations:
- Check logs for errors
- Track scraping duration
- Monitor data quality metrics
- Alert on significant changes in data volume

## Known Limitations

1. **Municipality-Specific Variations**: Different municipalities may have different HTML structures. The parser may need municipality-specific adjustments.

2. **Authentication**: The current implementation only supports publicly accessible pages. Private data requires authentication support.

3. **Dynamic Content**: If the Jupiter Byggesak system uses heavy JavaScript rendering, a headless browser (e.g., Selenium, Playwright) may be needed.

4. **Data Privacy**: The scraper only collects publicly available data. Ensure compliance with data protection regulations.

## Future Enhancements

1. **Add More Municipalities**: Configure and test additional Norwegian municipalities

2. **Headless Browser Support**: Add support for JavaScript-rendered pages

3. **Data Storage**: Implement database storage for scraped data

4. **Change Detection**: Track changes in building cases over time

5. **Notification System**: Alert on new building permits or status changes

6. **API Integration**: If Jupiter Byggesak provides an API, integrate with it instead of scraping

## Troubleshooting

### Import Errors

If you get import errors, make sure to set PYTHONPATH:

```bash
export PYTHONPATH=/path/to/offpost
```

### Network Errors

If you encounter frequent network errors:
1. Check your internet connection
2. Verify the target URL is accessible
3. Check if the server is blocking requests (403, 429 errors)
4. Reduce the rate limit to be more conservative

### Parsing Errors

If HTML parsing fails:
1. Check if the website structure has changed
2. View the HTML in the error logs
3. Update selectors in `parser.py`
4. Add test fixtures with the new HTML structure

### Test Failures

If tests fail:
1. Ensure all dependencies are installed: `pip install -r scrapers/requirements.txt`
2. Check that PYTHONPATH is set correctly
3. Review the specific test failure message
4. Run tests with `-vv` for more detailed output

## Contributing

When contributing improvements:

1. Follow the existing code structure
2. Add tests for new functionality
3. Update documentation
4. Ensure all tests pass
5. Run security checks
6. Follow the guidelines in SCRAPER_GUIDELINES.md

## Resources

- [SCRAPER_GUIDELINES.md](../SCRAPER_GUIDELINES.md) - Comprehensive scraper guidelines
- [BeautifulSoup Documentation](https://www.crummy.com/software/BeautifulSoup/bs4/doc/)
- [Requests Documentation](https://requests.readthedocs.io/)
- [Pytest Documentation](https://docs.pytest.org/)

## Contact

For questions or issues with the scraper implementation, please open an issue on GitHub or contact the maintainers.
