"""Shared HTTP client with retry logic and rate limiting."""

import time
import logging
from datetime import datetime
from typing import Optional
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

from .exceptions import NetworkError


logger = logging.getLogger(__name__)


class RateLimiter:
    """Rate limiter to control request frequency."""
    
    def __init__(self, requests_per_minute: int = 30):
        """
        Initialize rate limiter.
        
        Args:
            requests_per_minute: Maximum number of requests per minute
        """
        self.requests_per_minute = requests_per_minute
        self.min_interval = 60.0 / requests_per_minute
        self.last_request: Optional[datetime] = None
    
    def wait(self):
        """Wait if necessary to respect rate limit."""
        if self.last_request:
            elapsed = (datetime.now() - self.last_request).total_seconds()
            if elapsed < self.min_interval:
                sleep_time = self.min_interval - elapsed
                logger.debug(f"Rate limiting: sleeping for {sleep_time:.2f} seconds")
                time.sleep(sleep_time)
        self.last_request = datetime.now()


def get_http_session(
    max_retries: int = 3,
    user_agent: str = "Offpost-Scraper/1.0 (https://offpost.no; contact@offpost.no)"
) -> requests.Session:
    """
    Create an HTTP session with retry logic.
    
    Args:
        max_retries: Maximum number of retries for failed requests
        user_agent: User agent string to identify the scraper
        
    Returns:
        Configured requests.Session object
    """
    session = requests.Session()
    
    # Configure retry strategy
    retry = Retry(
        total=max_retries,
        read=max_retries,
        connect=max_retries,
        backoff_factor=0.3,
        status_forcelist=(500, 502, 504, 429)
    )
    
    adapter = HTTPAdapter(max_retries=retry)
    session.mount('http://', adapter)
    session.mount('https://', adapter)
    
    # Set user agent
    session.headers.update({
        'User-Agent': user_agent
    })
    
    return session


def safe_get(session: requests.Session, url: str, **kwargs) -> requests.Response:
    """
    Perform a GET request with error handling.
    
    Args:
        session: Requests session to use
        url: URL to fetch
        **kwargs: Additional arguments to pass to session.get()
        
    Returns:
        Response object
        
    Raises:
        NetworkError: If the request fails
    """
    try:
        logger.debug(f"GET {url}")
        response = session.get(url, **kwargs)
        response.raise_for_status()
        return response
    except requests.RequestException as e:
        logger.error(f"Network error fetching {url}: {e}")
        raise NetworkError(f"Failed to fetch {url}: {e}")
