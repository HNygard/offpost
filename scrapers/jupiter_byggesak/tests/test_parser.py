"""Tests for Jupiter Byggesak parser module."""

import unittest
import os
from datetime import datetime

from scrapers.jupiter_byggesak.parser import parse_search_results, parse_case_details, _parse_date
from scrapers.common.exceptions import ParsingError


class TestParseSearchResults(unittest.TestCase):
    """Tests for parse_search_results function."""
    
    def setUp(self):
        """Load fixtures."""
        fixture_path = os.path.join(
            os.path.dirname(__file__),
            'fixtures',
            'search_results.html'
        )
        with open(fixture_path, 'r', encoding='utf-8') as f:
            self.html = f.read()
    
    def test_parse_search_results_success(self):
        """Test parsing valid search results."""
        # :: Act
        results = parse_search_results(self.html)
        
        # :: Assert
        self.assertEqual(len(results), 3, f"Expected 3 results, got {len(results)}")
        
        # Check first result
        self.assertEqual(results[0].case_id, '12345')
        self.assertEqual(results[0].case_number, '2024/1234')
        self.assertEqual(results[0].title, 'Søknad om tilbygg - Eksempelveien 1')
        self.assertEqual(results[0].status, 'Under behandling')
        
        # Check second result
        self.assertEqual(results[1].case_id, '12346')
        self.assertEqual(results[1].case_number, '2024/1235')
        self.assertEqual(results[1].status, 'Godkjent')
        
        # Check third result
        self.assertEqual(results[2].case_id, '12347')
        self.assertEqual(results[2].status, 'Avslått')
    
    def test_parse_search_results_empty(self):
        """Test parsing page with no results."""
        # :: Setup
        html = '<html><body><table class="search-results"></table></body></html>'
        
        # :: Act
        results = parse_search_results(html)
        
        # :: Assert
        self.assertEqual(len(results), 0, "Expected empty results")
    
    def test_parse_search_results_invalid_html(self):
        """Test parsing completely invalid HTML."""
        # :: Setup
        html = '<html><body><p>No table here</p></body></html>'
        
        # :: Act
        results = parse_search_results(html)
        
        # :: Assert
        # Should return empty list rather than raise error
        self.assertEqual(len(results), 0)


class TestParseCaseDetails(unittest.TestCase):
    """Tests for parse_case_details function."""
    
    def setUp(self):
        """Load fixtures."""
        fixture_path = os.path.join(
            os.path.dirname(__file__),
            'fixtures',
            'case_details.html'
        )
        with open(fixture_path, 'r', encoding='utf-8') as f:
            self.html = f.read()
    
    def test_parse_case_details_success(self):
        """Test parsing valid case details."""
        # :: Act
        case = parse_case_details(self.html, '2024/1234', 'oslo')
        
        # :: Assert
        self.assertEqual(case.case_number, '2024/1234')
        self.assertEqual(case.municipality, 'oslo')
        self.assertEqual(case.case_type, 'Tilbygg')
        self.assertEqual(case.status, 'Under behandling')
        self.assertEqual(case.address, 'Eksempelveien 1, 0123 Oslo')
        self.assertEqual(case.property_id, '123/456')
        self.assertEqual(case.applicant, 'Ola Nordmann')
        self.assertIn('tilbygg', case.description.lower())
        
        # Check date parsing
        self.assertIsNotNone(case.application_date)
        self.assertEqual(case.application_date.year, 2024)
        self.assertEqual(case.application_date.month, 11)
        self.assertEqual(case.application_date.day, 15)
    
    def test_parse_case_details_missing_required_fields(self):
        """Test parsing with missing required fields."""
        # :: Setup
        html = '<html><body><dl><dt>Saksnummer</dt><dd>123</dd></dl></body></html>'
        
        # :: Act & Assert
        with self.assertRaises(ParsingError) as context:
            parse_case_details(html, '123', 'oslo')
        
        self.assertIn('required', str(context.exception).lower())
    
    def test_parse_case_details_minimal(self):
        """Test parsing with only required fields."""
        # :: Setup
        html = '''
        <html><body><dl>
            <dt>Søknadstype</dt><dd class="case-type">Tilbygg</dd>
            <dt>Status</dt><dd class="status">Godkjent</dd>
        </dl></body></html>
        '''
        
        # :: Act
        case = parse_case_details(html, '2024/999', 'bergen')
        
        # :: Assert
        self.assertEqual(case.case_number, '2024/999')
        self.assertEqual(case.municipality, 'bergen')
        self.assertEqual(case.case_type, 'Tilbygg')
        self.assertEqual(case.status, 'Godkjent')
        self.assertIsNone(case.address)
        self.assertIsNone(case.property_id)


class TestParseDateHelper(unittest.TestCase):
    """Tests for _parse_date helper function."""
    
    def test_parse_date_norwegian_format(self):
        """Test parsing Norwegian date format (dd.mm.yyyy)."""
        # :: Act
        result = _parse_date('15.11.2024')
        
        # :: Assert
        self.assertIsNotNone(result)
        self.assertEqual(result.year, 2024)
        self.assertEqual(result.month, 11)
        self.assertEqual(result.day, 15)
    
    def test_parse_date_iso_format(self):
        """Test parsing ISO date format (yyyy-mm-dd)."""
        # :: Act
        result = _parse_date('2024-12-13')
        
        # :: Assert
        self.assertIsNotNone(result)
        self.assertEqual(result.year, 2024)
        self.assertEqual(result.month, 12)
        self.assertEqual(result.day, 13)
    
    def test_parse_date_slash_format(self):
        """Test parsing date with slashes (dd/mm/yyyy)."""
        # :: Act
        result = _parse_date('31/12/2024')
        
        # :: Assert
        self.assertIsNotNone(result)
        self.assertEqual(result.day, 31)
        self.assertEqual(result.month, 12)
    
    def test_parse_date_invalid(self):
        """Test parsing invalid date string."""
        # :: Act
        result = _parse_date('not a date')
        
        # :: Assert
        self.assertIsNone(result, "Invalid date should return None")
    
    def test_parse_date_empty(self):
        """Test parsing empty string."""
        # :: Act
        result = _parse_date('')
        
        # :: Assert
        self.assertIsNone(result, "Empty string should return None")


if __name__ == '__main__':
    unittest.main()
