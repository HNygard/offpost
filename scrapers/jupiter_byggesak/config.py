"""Configuration for Jupiter Byggesak scraper."""

from typing import Dict, Any

# Municipality-specific configurations
MUNICIPALITY_CONFIGS: Dict[str, Dict[str, Any]] = {
    'oslo': {
        'base_url': 'https://innsyn.oslo.kommune.no/jupiter/',
        'search_endpoint': '/search',
        'case_endpoint': '/case/{case_id}',
        'rate_limit': 30,  # requests per minute
        'enabled': False,  # Disabled by default (example configuration)
    },
    'bergen': {
        'base_url': 'https://innsyn.bergen.kommune.no/jupiter/',
        'search_endpoint': '/sok',
        'case_endpoint': '/sak/{case_id}',
        'rate_limit': 20,
        'enabled': False,  # Disabled by default (example configuration)
    },
    'test': {
        'base_url': 'http://localhost:8080/jupiter/',
        'search_endpoint': '/search',
        'case_endpoint': '/case/{case_id}',
        'rate_limit': 60,
        'enabled': True,
    }
}

# Default configuration
DEFAULT_CONFIG = {
    'timeout': 30,  # seconds
    'max_retries': 3,
    'user_agent': 'Offpost-JupiterByggesak-Scraper/1.0 (https://offpost.no; contact@offpost.no)',
}
