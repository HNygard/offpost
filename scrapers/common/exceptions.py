"""Common exception classes for scrapers."""


class ScraperError(Exception):
    """Base exception for scraper errors."""
    pass


class NetworkError(ScraperError):
    """Network-related errors."""
    pass


class ParsingError(ScraperError):
    """Data parsing errors."""
    pass


class ValidationError(ScraperError):
    """Data validation errors."""
    pass


class ConfigurationError(ScraperError):
    """Configuration errors."""
    pass
