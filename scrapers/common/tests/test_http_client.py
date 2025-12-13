"""Tests for HTTP client utilities."""

import unittest
from unittest.mock import Mock, patch
import time
from datetime import datetime
import requests

from scrapers.common.http_client import RateLimiter, get_http_session, safe_get
from scrapers.common.exceptions import NetworkError


class TestRateLimiter(unittest.TestCase):
    """Tests for RateLimiter class."""
    
    def test_rate_limiter_init(self):
        """Test RateLimiter initialization."""
        # :: Act
        limiter = RateLimiter(requests_per_minute=30)
        
        # :: Assert
        self.assertEqual(limiter.requests_per_minute, 30)
        self.assertEqual(limiter.min_interval, 2.0)  # 60/30
        self.assertIsNone(limiter.last_request)
    
    def test_rate_limiter_first_wait(self):
        """Test that first wait doesn't sleep."""
        # :: Setup
        limiter = RateLimiter(requests_per_minute=60)
        
        # :: Act
        start = time.time()
        limiter.wait()
        elapsed = time.time() - start
        
        # :: Assert
        self.assertLess(elapsed, 0.1, "First wait should not sleep")
        self.assertIsNotNone(limiter.last_request)
    
    def test_rate_limiter_subsequent_wait(self):
        """Test that subsequent waits respect rate limit."""
        # :: Setup
        limiter = RateLimiter(requests_per_minute=60)  # 1 request per second
        
        # :: Act
        limiter.wait()
        start = time.time()
        limiter.wait()
        elapsed = time.time() - start
        
        # :: Assert
        # Should wait approximately 1 second
        self.assertGreater(elapsed, 0.9, "Should wait close to 1 second")
        self.assertLess(elapsed, 1.2, "Should not wait much more than 1 second")
    
    def test_rate_limiter_fast_succession(self):
        """Test multiple rapid calls are rate limited."""
        # :: Setup
        limiter = RateLimiter(requests_per_minute=120)  # 0.5 seconds between requests
        
        # :: Act
        start = time.time()
        limiter.wait()
        limiter.wait()
        limiter.wait()
        elapsed = time.time() - start
        
        # :: Assert
        # Three waits: first=0, second=0.5s, third=0.5s = ~1.0s total
        self.assertGreater(elapsed, 0.9, "Should enforce rate limit")


class TestGetHttpSession(unittest.TestCase):
    """Tests for get_http_session function."""
    
    def test_get_http_session_default(self):
        """Test creating session with default parameters."""
        # :: Act
        session = get_http_session()
        
        # :: Assert
        self.assertIsInstance(session, requests.Session)
        self.assertIn('User-Agent', session.headers)
        self.assertIn('Offpost', session.headers['User-Agent'])
    
    def test_get_http_session_custom_user_agent(self):
        """Test creating session with custom user agent."""
        # :: Setup
        custom_ua = 'TestBot/1.0'
        
        # :: Act
        session = get_http_session(user_agent=custom_ua)
        
        # :: Assert
        self.assertEqual(session.headers['User-Agent'], custom_ua)
    
    def test_get_http_session_has_retry_adapter(self):
        """Test that session has retry adapter configured."""
        # :: Act
        session = get_http_session(max_retries=5)
        
        # :: Assert
        # Check that adapters are configured
        self.assertIn('https://', session.adapters)
        self.assertIn('http://', session.adapters)


class TestSafeGet(unittest.TestCase):
    """Tests for safe_get function."""
    
    def test_safe_get_success(self):
        """Test successful GET request."""
        # :: Setup
        mock_session = Mock()
        mock_response = Mock()
        mock_response.status_code = 200
        mock_response.raise_for_status = Mock()
        mock_session.get.return_value = mock_response
        
        # :: Act
        result = safe_get(mock_session, 'http://example.com')
        
        # :: Assert
        self.assertEqual(result, mock_response)
        mock_session.get.assert_called_once_with('http://example.com')
    
    def test_safe_get_with_kwargs(self):
        """Test GET request with additional kwargs."""
        # :: Setup
        mock_session = Mock()
        mock_response = Mock()
        mock_response.raise_for_status = Mock()
        mock_session.get.return_value = mock_response
        
        # :: Act
        result = safe_get(
            mock_session,
            'http://example.com',
            params={'key': 'value'},
            timeout=30
        )
        
        # :: Assert
        mock_session.get.assert_called_once_with(
            'http://example.com',
            params={'key': 'value'},
            timeout=30
        )
    
    def test_safe_get_network_error(self):
        """Test handling of network errors."""
        # :: Setup
        mock_session = Mock()
        mock_session.get.side_effect = requests.ConnectionError("Connection failed")
        
        # :: Act & Assert
        with self.assertRaises(NetworkError) as context:
            safe_get(mock_session, 'http://example.com')
        
        self.assertIn('Connection failed', str(context.exception))
    
    def test_safe_get_http_error(self):
        """Test handling of HTTP errors."""
        # :: Setup
        mock_session = Mock()
        mock_response = Mock()
        mock_response.raise_for_status.side_effect = requests.HTTPError("404 Not Found")
        mock_session.get.return_value = mock_response
        
        # :: Act & Assert
        with self.assertRaises(NetworkError):
            safe_get(mock_session, 'http://example.com')
    
    def test_safe_get_timeout_error(self):
        """Test handling of timeout errors."""
        # :: Setup
        mock_session = Mock()
        mock_session.get.side_effect = requests.Timeout("Request timeout")
        
        # :: Act & Assert
        with self.assertRaises(NetworkError) as context:
            safe_get(mock_session, 'http://example.com')
        
        self.assertIn('timeout', str(context.exception).lower())


if __name__ == '__main__':
    unittest.main()
