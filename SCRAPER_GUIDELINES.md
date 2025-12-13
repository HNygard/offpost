# Scraper Guidelines for Offpost Project

## Overview

This document provides guidelines for building and maintaining scrapers that collect data for the Offpost project, particularly for updating `data/entities.json` with information about Norwegian public entities.

## General Principles

### 1. Reliability
- Scrapers must handle network failures gracefully
- Implement retry logic with exponential backoff
- Log all errors with sufficient context for debugging
- Never crash - always fail gracefully with appropriate error messages

### 2. Respectful Scraping
- Respect robots.txt directives
- Implement rate limiting to avoid overwhelming target servers
- Add appropriate User-Agent headers identifying the scraper
- Cache responses when appropriate to minimize requests
- Scrape during off-peak hours when possible

### 3. Data Quality
- Validate all scraped data before saving
- Handle missing or malformed data gracefully
- Preserve data provenance (source URL, scrape date)
- Implement data versioning or change tracking
- Document data transformations clearly

### 4. Maintainability
- Use clear, descriptive variable and function names
- Add comprehensive comments explaining scraping logic
- Document the structure of target websites
- Include examples of HTML/JSON structures being parsed
- Make selectors and patterns configurable

## Code Structure

### Recommended File Organization

```
scrapers/
├── README.md                 # Overview of all scrapers
├── guidelines.md             # This file (symlink)
├── common/
│   ├── __init__.py
│   ├── http_client.py       # Shared HTTP client with retry logic
│   ├── validators.py        # Data validation utilities
│   └── logging_config.py    # Logging configuration
├── jupiter_byggesak/
│   ├── __init__.py
│   ├── scraper.py           # Main scraper implementation
│   ├── config.py            # Configuration (URLs, selectors, etc.)
│   ├── parser.py            # HTML/JSON parsing logic
│   ├── models.py            # Data models
│   └── tests/
│       ├── __init__.py
│       ├── test_scraper.py
│       ├── test_parser.py
│       └── fixtures/         # Sample HTML/JSON for testing
└── other_scraper/
    └── ...
```

### Required Components

Each scraper must include:

1. **Main Scraper Class**
   - Inherits from a base scraper class
   - Implements `scrape()` method
   - Implements `validate()` method
   - Implements `transform()` method

2. **Configuration Module**
   - All URLs, selectors, and constants in one place
   - Environment-specific configuration
   - Rate limiting parameters

3. **Parser Module**
   - Separate parsing logic from scraping logic
   - Pure functions when possible
   - Handle multiple HTML/JSON structures if needed

4. **Tests**
   - Unit tests for parsers using fixtures
   - Integration tests with mocked HTTP responses
   - Test error handling scenarios
   - Minimum 80% code coverage

## Implementation Requirements

### Error Handling

```python
class ScraperError(Exception):
    """Base exception for scraper errors"""
    pass

class NetworkError(ScraperError):
    """Network-related errors"""
    pass

class ParsingError(ScraperError):
    """Data parsing errors"""
    pass

class ValidationError(ScraperError):
    """Data validation errors"""
    pass
```

All scrapers must catch and handle these error types appropriately.

### Logging

Use structured logging:

```python
logger.info("Starting scrape", extra={
    "scraper": "jupiter_byggesak",
    "target_url": url,
    "timestamp": datetime.utcnow().isoformat()
})
```

Log levels:
- `DEBUG`: Detailed scraping steps, HTML structure details
- `INFO`: Start/end of scraping, items processed
- `WARNING`: Recoverable errors, unexpected data formats
- `ERROR`: Scraping failures, validation errors
- `CRITICAL`: System failures requiring immediate attention

### Rate Limiting

Implement rate limiting using a configurable delay:

```python
import time
from datetime import datetime, timedelta

class RateLimiter:
    def __init__(self, requests_per_minute=30):
        self.requests_per_minute = requests_per_minute
        self.min_interval = 60.0 / requests_per_minute
        self.last_request = None
    
    def wait(self):
        if self.last_request:
            elapsed = (datetime.now() - self.last_request).total_seconds()
            if elapsed < self.min_interval:
                time.sleep(self.min_interval - elapsed)
        self.last_request = datetime.now()
```

### HTTP Client

Use a shared HTTP client with retry logic:

```python
from requests.adapters import HTTPAdapter
from requests.packages.urllib3.util.retry import Retry

def get_http_session(max_retries=3):
    session = requests.Session()
    retry = Retry(
        total=max_retries,
        read=max_retries,
        connect=max_retries,
        backoff_factor=0.3,
        status_forcelist=(500, 502, 504)
    )
    adapter = HTTPAdapter(max_retries=retry)
    session.mount('http://', adapter)
    session.mount('https://', adapter)
    session.headers.update({
        'User-Agent': 'Offpost-Scraper/1.0 (https://offpost.no; contact@offpost.no)'
    })
    return session
```

### Data Validation

All scraped data must be validated before saving:

```python
from typing import Dict, Optional
import re

def validate_entity(entity: Dict) -> tuple[bool, Optional[str]]:
    """
    Validate entity data.
    
    Returns:
        (is_valid, error_message)
    """
    required_fields = ['entity_id', 'name', 'type']
    
    # Check required fields
    for field in required_fields:
        if field not in entity or not entity[field]:
            return False, f"Missing required field: {field}"
    
    # Validate entity_id format
    if not re.match(r'^[a-z0-9-]+$', entity['entity_id']):
        return False, "Invalid entity_id format"
    
    # Validate email if present
    if 'email' in entity and entity['email']:
        if not re.match(r'^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$', entity['email']):
            return False, f"Invalid email format: {entity['email']}"
    
    # Validate org_num if present
    if 'org_num' in entity and entity['org_num']:
        if not re.match(r'^\d{9}$', entity['org_num']):
            return False, f"Invalid org_num format: {entity['org_num']}"
    
    return True, None
```

## Testing Requirements

### Unit Tests

Test parsing logic with fixtures:

```python
import unittest
from scrapers.jupiter_byggesak.parser import parse_entity

class TestJupiterByggesakParser(unittest.TestCase):
    def setUp(self):
        with open('tests/fixtures/entity_page.html', 'r') as f:
            self.html = f.read()
    
    def test_parse_entity_basic(self):
        entity = parse_entity(self.html)
        self.assertEqual(entity['name'], 'Oslo kommune')
        self.assertEqual(entity['org_num'], '958935420')
    
    def test_parse_entity_missing_email(self):
        html = '<html>...</html>'  # HTML without email
        entity = parse_entity(html)
        self.assertNotIn('email', entity)
    
    def test_parse_entity_invalid_html(self):
        with self.assertRaises(ParsingError):
            parse_entity('<html></html>')
```

### Integration Tests

Test the full scraping flow with mocked HTTP:

```python
import unittest
from unittest.mock import patch, Mock
from scrapers.jupiter_byggesak.scraper import JupiterByggesakScraper

class TestJupiterByggesakScraper(unittest.TestCase):
    @patch('requests.Session.get')
    def test_scrape_success(self, mock_get):
        # Setup mock response
        mock_response = Mock()
        mock_response.status_code = 200
        mock_response.text = '<html>...'
        mock_get.return_value = mock_response
        
        # Run scraper
        scraper = JupiterByggesakScraper()
        entities = scraper.scrape()
        
        # Verify results
        self.assertGreater(len(entities), 0)
        self.assertIsInstance(entities[0], dict)
    
    @patch('requests.Session.get')
    def test_scrape_network_error(self, mock_get):
        mock_get.side_effect = requests.RequestException("Network error")
        
        scraper = JupiterByggesakScraper()
        with self.assertRaises(NetworkError):
            scraper.scrape()
```

### Test Coverage Requirements

- Minimum 80% code coverage
- 100% coverage for critical paths (data validation, transformation)
- Test all error handling paths
- Include fixtures for all expected HTML/JSON structures

## Jupiter Byggesak Specific Guidelines

### System Overview

Jupiter Byggesak is a building permit management system used by Norwegian municipalities. Each municipality may have a slightly different implementation, but they generally share common patterns.

### Common Endpoints

- Public building case search
- Building case details
- Building permit applications
- Construction progress reports

### Data Points to Extract

For building cases:
- Case number (saksnummer)
- Municipality
- Property identifier (gårdsnummer, bruksnummer, festenummer)
- Case type (søknadstype)
- Case status (status)
- Application date (søknadsdato)
- Decision date (vedtaksdato) if available
- Applicant information (if public)
- Address

### Privacy Considerations

**IMPORTANT**: Building case data may contain personal information. Scrapers must:

1. Only collect publicly available data
2. Respect access controls and authentication requirements
3. Not store personal information unless explicitly allowed
4. Implement data retention policies
5. Document data privacy compliance

### Municipality-Specific Handling

Different municipalities may use different Jupiter Byggesak configurations:

```python
MUNICIPALITY_CONFIGS = {
    'oslo': {
        'base_url': 'https://innsyn.oslo.kommune.no/jupiter/',
        'search_endpoint': '/search',
        'case_endpoint': '/case/{case_id}',
        'rate_limit': 30  # requests per minute
    },
    'bergen': {
        'base_url': 'https://innsyn.bergen.kommune.no/jupiter/',
        'search_endpoint': '/sok',
        'case_endpoint': '/sak/{case_id}',
        'rate_limit': 20
    }
}
```

### Example Implementation Pattern

```python
class JupiterByggesakScraper:
    def __init__(self, municipality):
        self.municipality = municipality
        self.config = MUNICIPALITY_CONFIGS[municipality]
        self.session = get_http_session()
        self.rate_limiter = RateLimiter(self.config['rate_limit'])
    
    def scrape_search_results(self, search_params):
        """Scrape search results page"""
        self.rate_limiter.wait()
        url = self.config['base_url'] + self.config['search_endpoint']
        response = self.session.get(url, params=search_params)
        return self.parse_search_results(response.text)
    
    def scrape_case_details(self, case_id):
        """Scrape individual case details"""
        self.rate_limiter.wait()
        url = self.config['base_url'] + self.config['case_endpoint'].format(case_id=case_id)
        response = self.session.get(url)
        return self.parse_case_details(response.text)
    
    def parse_search_results(self, html):
        """Parse search results HTML"""
        # Implementation specific to HTML structure
        pass
    
    def parse_case_details(self, html):
        """Parse case details HTML"""
        # Implementation specific to HTML structure
        pass
```

## Maintenance

### Monitoring

- Set up monitoring for scraper failures
- Track scraping duration over time
- Monitor data quality metrics
- Alert on significant changes in scraped data volume

### Documentation

- Update this guide when new patterns are discovered
- Document any website changes that required scraper updates
- Maintain a changelog for each scraper
- Document known issues and workarounds

### Version Control

- Tag scraper versions
- Link scraper versions to data versions
- Document breaking changes
- Maintain backward compatibility when possible

## Resources

- [BeautifulSoup Documentation](https://www.crummy.com/software/BeautifulSoup/bs4/doc/)
- [Requests Documentation](https://requests.readthedocs.io/)
- [Python Logging](https://docs.python.org/3/library/logging.html)
- [robots.txt specification](https://www.robotstxt.org/)

## Questions or Issues

If you have questions about these guidelines or encounter issues:
1. Check existing scraper implementations for examples
2. Review the project's issue tracker
3. Contact the maintainers at [contact information]
