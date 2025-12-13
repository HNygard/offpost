"""Tests for Jupiter Byggesak scraper module."""

import unittest
from unittest.mock import patch, Mock, MagicMock
import requests

from ..scraper import JupiterByggesakScraper
from ...common.exceptions import ConfigurationError, NetworkError, ParsingError


class TestJupiterByggesakScraperInit(unittest.TestCase):
    """Tests for scraper initialization."""
    
    def test_init_valid_municipality(self):
        """Test initialization with valid municipality."""
        # :: Act
        scraper = JupiterByggesakScraper('test')
        
        # :: Assert
        self.assertEqual(scraper.municipality, 'test')
        self.assertIsNotNone(scraper.session)
        self.assertIsNotNone(scraper.rate_limiter)
    
    def test_init_invalid_municipality(self):
        """Test initialization with invalid municipality."""
        # :: Act & Assert
        with self.assertRaises(ConfigurationError) as context:
            JupiterByggesakScraper('invalid-municipality')
        
        self.assertIn('Unknown municipality', str(context.exception))
    
    def test_init_disabled_municipality(self):
        """Test initialization with disabled municipality."""
        # :: Act & Assert
        with self.assertRaises(ConfigurationError) as context:
            JupiterByggesakScraper('oslo')  # Oslo is disabled in config
        
        self.assertIn('not enabled', str(context.exception))


class TestJupiterByggesakScraperSearchResults(unittest.TestCase):
    """Tests for search results scraping."""
    
    def setUp(self):
        """Set up test fixtures."""
        self.scraper = JupiterByggesakScraper('test')
        
        # Load fixture
        import os
        fixture_path = os.path.join(
            os.path.dirname(__file__),
            'fixtures',
            'search_results.html'
        )
        with open(fixture_path, 'r', encoding='utf-8') as f:
            self.mock_html = f.read()
    
    @patch('scrapers.common.http_client.safe_get')
    def test_scrape_search_results_success(self, mock_safe_get):
        """Test successful search results scraping."""
        # :: Setup
        mock_response = Mock()
        mock_response.text = self.mock_html
        mock_safe_get.return_value = mock_response
        
        # :: Act
        results = self.scraper.scrape_search_results()
        
        # :: Assert
        self.assertEqual(len(results), 3, f"Expected 3 results, got {len(results)}")
        self.assertEqual(results[0].case_number, '2024/1234')
        self.assertEqual(results[1].case_number, '2024/1235')
        self.assertEqual(results[2].case_number, '2024/1236')
        
        # Verify safe_get was called correctly
        mock_safe_get.assert_called_once()
    
    @patch('scrapers.common.http_client.safe_get')
    def test_scrape_search_results_with_params(self, mock_safe_get):
        """Test search results scraping with parameters."""
        # :: Setup
        mock_response = Mock()
        mock_response.text = self.mock_html
        mock_safe_get.return_value = mock_response
        
        search_params = {'query': 'Eksempelveien', 'year': '2024'}
        
        # :: Act
        results = self.scraper.scrape_search_results(search_params)
        
        # :: Assert
        self.assertGreater(len(results), 0)
        
        # Verify params were passed
        call_args = mock_safe_get.call_args
        self.assertEqual(call_args.kwargs['params'], search_params)
    
    @patch('scrapers.common.http_client.safe_get')
    def test_scrape_search_results_network_error(self, mock_safe_get):
        """Test handling of network errors."""
        # :: Setup
        mock_safe_get.side_effect = NetworkError("Connection failed")
        
        # :: Act & Assert
        with self.assertRaises(NetworkError):
            self.scraper.scrape_search_results()


class TestJupiterByggesakScraperCaseDetails(unittest.TestCase):
    """Tests for case details scraping."""
    
    def setUp(self):
        """Set up test fixtures."""
        self.scraper = JupiterByggesakScraper('test')
        
        # Load fixture
        import os
        fixture_path = os.path.join(
            os.path.dirname(__file__),
            'fixtures',
            'case_details.html'
        )
        with open(fixture_path, 'r', encoding='utf-8') as f:
            self.mock_html = f.read()
    
    @patch('scrapers.common.http_client.safe_get')
    def test_scrape_case_details_success(self, mock_safe_get):
        """Test successful case details scraping."""
        # :: Setup
        mock_response = Mock()
        mock_response.text = self.mock_html
        mock_safe_get.return_value = mock_response
        
        # :: Act
        case = self.scraper.scrape_case_details('12345', '2024/1234')
        
        # :: Assert
        self.assertEqual(case.case_number, '2024/1234')
        self.assertEqual(case.municipality, 'test')
        self.assertEqual(case.case_type, 'Tilbygg')
        self.assertEqual(case.status, 'Under behandling')
        self.assertIsNotNone(case.source_url)
        self.assertIn('12345', case.source_url)
    
    @patch('scrapers.common.http_client.safe_get')
    def test_scrape_case_details_network_error(self, mock_safe_get):
        """Test handling of network errors in case details."""
        # :: Setup
        mock_safe_get.side_effect = NetworkError("Connection timeout")
        
        # :: Act & Assert
        with self.assertRaises(NetworkError):
            self.scraper.scrape_case_details('12345', '2024/1234')


class TestJupiterByggesakScraperMultipleCases(unittest.TestCase):
    """Tests for multiple case scraping."""
    
    def setUp(self):
        """Set up test fixtures."""
        self.scraper = JupiterByggesakScraper('test')
        
        # Load fixtures
        import os
        search_fixture = os.path.join(
            os.path.dirname(__file__),
            'fixtures',
            'search_results.html'
        )
        case_fixture = os.path.join(
            os.path.dirname(__file__),
            'fixtures',
            'case_details.html'
        )
        
        with open(search_fixture, 'r', encoding='utf-8') as f:
            self.search_html = f.read()
        with open(case_fixture, 'r', encoding='utf-8') as f:
            self.case_html = f.read()
    
    @patch('scrapers.common.http_client.safe_get')
    def test_scrape_multiple_cases_success(self, mock_safe_get):
        """Test scraping multiple cases successfully."""
        # :: Setup
        def mock_get_side_effect(session, url, **kwargs):
            response = Mock()
            if 'search' in url:
                response.text = self.search_html
            else:
                response.text = self.case_html
            return response
        
        mock_safe_get.side_effect = mock_get_side_effect
        
        # :: Act
        cases = self.scraper.scrape_multiple_cases()
        
        # :: Assert
        self.assertEqual(len(cases), 3, f"Expected 3 cases, got {len(cases)}")
        self.assertEqual(mock_safe_get.call_count, 4)  # 1 search + 3 cases
    
    @patch('scrapers.common.http_client.safe_get')
    def test_scrape_multiple_cases_with_limit(self, mock_safe_get):
        """Test scraping multiple cases with max_cases limit."""
        # :: Setup
        def mock_get_side_effect(session, url, **kwargs):
            response = Mock()
            if 'search' in url:
                response.text = self.search_html
            else:
                response.text = self.case_html
            return response
        
        mock_safe_get.side_effect = mock_get_side_effect
        
        # :: Act
        cases = self.scraper.scrape_multiple_cases(max_cases=2)
        
        # :: Assert
        self.assertEqual(len(cases), 2, "Should only scrape 2 cases")
        self.assertEqual(mock_safe_get.call_count, 3)  # 1 search + 2 cases
    
    @patch('scrapers.common.http_client.safe_get')
    def test_scrape_multiple_cases_partial_failure(self, mock_safe_get):
        """Test that scraping continues even if individual cases fail."""
        # :: Setup
        call_count = [0]
        
        def mock_get_side_effect(session, url, **kwargs):
            call_count[0] += 1
            response = Mock()
            if 'search' in url:
                response.text = self.search_html
            elif call_count[0] == 2:
                # Second call (first case) fails
                raise NetworkError("Connection failed")
            else:
                response.text = self.case_html
            return response
        
        mock_safe_get.side_effect = mock_get_side_effect
        
        # :: Act
        cases = self.scraper.scrape_multiple_cases()
        
        # :: Assert
        # Should get 2 cases (one failed)
        self.assertEqual(len(cases), 2, "Should get 2 cases despite one failure")


class TestJupiterByggesakScraperExport(unittest.TestCase):
    """Tests for data export functionality."""
    
    def setUp(self):
        """Set up test fixtures."""
        self.scraper = JupiterByggesakScraper('test')
    
    @patch('scrapers.common.http_client.safe_get')
    def test_export_to_dict(self, mock_safe_get):
        """Test exporting cases to dictionary format."""
        # :: Setup
        import os
        fixture_path = os.path.join(
            os.path.dirname(__file__),
            'fixtures',
            'case_details.html'
        )
        with open(fixture_path, 'r', encoding='utf-8') as f:
            mock_html = f.read()
        
        mock_response = Mock()
        mock_response.text = mock_html
        mock_safe_get.return_value = mock_response
        
        case = self.scraper.scrape_case_details('12345', '2024/1234')
        
        # :: Act
        result = self.scraper.export_to_dict([case])
        
        # :: Assert
        self.assertEqual(len(result), 1)
        self.assertIsInstance(result[0], dict)
        self.assertEqual(result[0]['case_number'], '2024/1234')
        self.assertEqual(result[0]['municipality'], 'test')
        self.assertIn('case_type', result[0])
        self.assertIn('status', result[0])


if __name__ == '__main__':
    unittest.main()
