"""Main Jupiter Byggesak scraper implementation."""

import logging
from typing import List, Optional, Dict, Any

from scrapers.common.http_client import get_http_session, safe_get, RateLimiter
from scrapers.common.exceptions import ConfigurationError, NetworkError, ParsingError
from scrapers.jupiter_byggesak.config import MUNICIPALITY_CONFIGS, DEFAULT_CONFIG
from scrapers.jupiter_byggesak.parser import parse_search_results, parse_case_details
from scrapers.jupiter_byggesak.models import BuildingCase, SearchResult


logger = logging.getLogger(__name__)


class JupiterByggesakScraper:
    """Scraper for Jupiter Byggesak building permit system."""
    
    def __init__(self, municipality: str):
        """
        Initialize scraper for a specific municipality.
        
        Args:
            municipality: Municipality code (e.g., 'oslo', 'bergen', 'test')
            
        Raises:
            ConfigurationError: If municipality configuration is not found or disabled
        """
        if municipality not in MUNICIPALITY_CONFIGS:
            raise ConfigurationError(
                f"Unknown municipality: {municipality}. "
                f"Available: {', '.join(MUNICIPALITY_CONFIGS.keys())}"
            )
        
        self.municipality = municipality
        self.config = MUNICIPALITY_CONFIGS[municipality]
        
        if not self.config.get('enabled', False):
            raise ConfigurationError(
                f"Municipality '{municipality}' is not enabled. "
                f"This may be because the actual URLs are not yet configured."
            )
        
        self.session = get_http_session(
            max_retries=DEFAULT_CONFIG['max_retries'],
            user_agent=DEFAULT_CONFIG['user_agent']
        )
        self.rate_limiter = RateLimiter(self.config['rate_limit'])
        
        logger.info(f"Initialized Jupiter Byggesak scraper for {municipality}")
    
    def scrape_search_results(self, search_params: Optional[Dict[str, Any]] = None) -> List[SearchResult]:
        """
        Scrape search results page.
        
        Args:
            search_params: Optional search parameters (e.g., {'query': 'address', 'year': '2024'})
            
        Returns:
            List of SearchResult objects
            
        Raises:
            NetworkError: If request fails
            ParsingError: If response cannot be parsed
        """
        self.rate_limiter.wait()
        
        url = self.config['base_url'] + self.config['search_endpoint']
        
        logger.info(f"Scraping search results from {url}")
        
        try:
            response = safe_get(
                self.session,
                url,
                params=search_params or {},
                timeout=DEFAULT_CONFIG['timeout']
            )
            
            results = parse_search_results(response.text)
            logger.info(f"Found {len(results)} search results")
            return results
            
        except NetworkError:
            raise
        except ParsingError:
            raise
        except Exception as e:
            logger.error(f"Unexpected error scraping search results: {e}")
            raise
    
    def scrape_case_details(self, case_id: str, case_number: str) -> BuildingCase:
        """
        Scrape individual case details.
        
        Args:
            case_id: Case ID from search results
            case_number: Case number for reference
            
        Returns:
            BuildingCase object
            
        Raises:
            NetworkError: If request fails
            ParsingError: If response cannot be parsed
        """
        self.rate_limiter.wait()
        
        url = (self.config['base_url'] + 
               self.config['case_endpoint'].format(case_id=case_id))
        
        logger.debug(f"Scraping case details from {url}")
        
        try:
            response = safe_get(
                self.session,
                url,
                timeout=DEFAULT_CONFIG['timeout']
            )
            
            case = parse_case_details(response.text, case_number, self.municipality)
            case.source_url = url
            
            logger.debug(f"Successfully scraped case {case_number}")
            return case
            
        except NetworkError:
            raise
        except ParsingError:
            raise
        except Exception as e:
            logger.error(f"Unexpected error scraping case {case_number}: {e}")
            raise
    
    def scrape_multiple_cases(
        self,
        search_params: Optional[Dict[str, Any]] = None,
        max_cases: Optional[int] = None
    ) -> List[BuildingCase]:
        """
        Scrape multiple cases: first get search results, then scrape each case.
        
        Args:
            search_params: Optional search parameters
            max_cases: Maximum number of cases to scrape (None = all)
            
        Returns:
            List of BuildingCase objects
        """
        logger.info(f"Starting multi-case scrape for {self.municipality}")
        
        # Get search results
        search_results = self.scrape_search_results(search_params)
        
        if max_cases:
            search_results = search_results[:max_cases]
            logger.info(f"Limited to {max_cases} cases")
        
        # Scrape each case
        cases = []
        for idx, result in enumerate(search_results, 1):
            try:
                logger.info(f"Scraping case {idx}/{len(search_results)}: {result.case_number}")
                case = self.scrape_case_details(result.case_id, result.case_number)
                cases.append(case)
            except Exception as e:
                logger.error(f"Failed to scrape case {result.case_number}: {e}")
                # Continue with next case
                continue
        
        logger.info(f"Successfully scraped {len(cases)}/{len(search_results)} cases")
        return cases
    
    def export_to_dict(self, cases: List[BuildingCase]) -> List[Dict[str, Any]]:
        """
        Export cases to dictionary format.
        
        Args:
            cases: List of BuildingCase objects
            
        Returns:
            List of dictionaries
        """
        return [case.to_dict() for case in cases]


def main():
    """Command-line interface for the scraper."""
    import argparse
    import json
    import sys
    
    # Set up logging
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
    )
    
    parser = argparse.ArgumentParser(description='Jupiter Byggesak scraper')
    parser.add_argument(
        '--municipality',
        required=True,
        choices=list(MUNICIPALITY_CONFIGS.keys()),
        help='Municipality to scrape'
    )
    parser.add_argument(
        '--max-cases',
        type=int,
        help='Maximum number of cases to scrape'
    )
    parser.add_argument(
        '--output',
        help='Output JSON file (default: stdout)'
    )
    parser.add_argument(
        '--verbose',
        action='store_true',
        help='Enable debug logging'
    )
    
    args = parser.parse_args()
    
    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)
    
    try:
        scraper = JupiterByggesakScraper(args.municipality)
        cases = scraper.scrape_multiple_cases(max_cases=args.max_cases)
        output_data = scraper.export_to_dict(cases)
        
        # Output results
        if args.output:
            with open(args.output, 'w', encoding='utf-8') as f:
                json.dump(output_data, f, indent=2, ensure_ascii=False)
            logger.info(f"Results written to {args.output}")
        else:
            print(json.dumps(output_data, indent=2, ensure_ascii=False))
        
        logger.info(f"Scraping completed successfully: {len(cases)} cases")
        return 0
        
    except ConfigurationError as e:
        logger.error(f"Configuration error: {e}")
        return 1
    except Exception as e:
        logger.error(f"Scraping failed: {e}", exc_info=True)
        return 1


if __name__ == '__main__':
    sys.exit(main())
