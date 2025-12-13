"""HTML parsing logic for Jupiter Byggesak."""

import logging
import re
from datetime import datetime
from typing import List, Optional
from bs4 import BeautifulSoup

from ..common.exceptions import ParsingError
from .models import SearchResult, BuildingCase


logger = logging.getLogger(__name__)


def parse_search_results(html: str) -> List[SearchResult]:
    """
    Parse search results page.
    
    Args:
        html: HTML content of search results page
        
    Returns:
        List of SearchResult objects
        
    Raises:
        ParsingError: If HTML structure is unexpected
    """
    try:
        soup = BeautifulSoup(html, 'html.parser')
        
        # This is an example structure - actual structure depends on implementation
        results = []
        result_rows = soup.select('table.search-results tr.result-row')
        
        if not result_rows:
            logger.warning("No result rows found in search results")
            return results
        
        for row in result_rows:
            try:
                case_id = row.get('data-case-id', '')
                case_number = row.select_one('td.case-number')
                title = row.select_one('td.title')
                status = row.select_one('td.status')
                date_elem = row.select_one('td.date')
                
                if not all([case_id, case_number, title, status]):
                    logger.warning(f"Incomplete data in row, skipping")
                    continue
                
                result = SearchResult(
                    case_id=case_id,
                    case_number=case_number.text.strip(),
                    title=title.text.strip(),
                    status=status.text.strip(),
                    date=_parse_date(date_elem.text.strip()) if date_elem else None
                )
                results.append(result)
            except Exception as e:
                logger.warning(f"Error parsing result row: {e}")
                continue
        
        logger.info(f"Parsed {len(results)} search results")
        return results
        
    except Exception as e:
        raise ParsingError(f"Failed to parse search results: {e}")


def parse_case_details(html: str, case_number: str, municipality: str) -> BuildingCase:
    """
    Parse building case details page.
    
    Args:
        html: HTML content of case details page
        case_number: Case number for reference
        municipality: Municipality name
        
    Returns:
        BuildingCase object
        
    Raises:
        ParsingError: If HTML structure is unexpected or required data is missing
    """
    try:
        soup = BeautifulSoup(html, 'html.parser')
        
        # Extract case information - structure depends on actual implementation
        case_type = _extract_field(soup, 'case-type', 'Søknadstype')
        status = _extract_field(soup, 'status', 'Status')
        address = _extract_field(soup, 'address', 'Adresse')
        property_id = _extract_field(soup, 'property-id', 'Gårdsnr/Bruksnr')
        
        if not case_type or not status:
            raise ParsingError("Missing required fields: case_type or status")
        
        # Extract dates
        application_date_str = _extract_field(soup, 'application-date', 'Søknadsdato')
        decision_date_str = _extract_field(soup, 'decision-date', 'Vedtaksdato')
        
        application_date = _parse_date(application_date_str) if application_date_str else None
        decision_date = _parse_date(decision_date_str) if decision_date_str else None
        
        # Extract other fields
        applicant = _extract_field(soup, 'applicant', 'Søker')
        description = _extract_field(soup, 'description', 'Beskrivelse')
        
        case = BuildingCase(
            case_number=case_number,
            municipality=municipality,
            case_type=case_type,
            status=status,
            address=address,
            property_id=property_id,
            application_date=application_date,
            decision_date=decision_date,
            applicant=applicant,
            description=description
        )
        
        logger.debug(f"Parsed case details for {case_number}")
        return case
        
    except ParsingError:
        raise
    except Exception as e:
        raise ParsingError(f"Failed to parse case details: {e}")


def _extract_field(soup: BeautifulSoup, css_class: str, label: str) -> Optional[str]:
    """
    Extract a field value from the page using CSS class or label.
    
    Args:
        soup: BeautifulSoup object
        css_class: CSS class to look for
        label: Text label to look for as fallback
        
    Returns:
        Extracted text or None
    """
    # Try CSS class first
    elem = soup.select_one(f'.{css_class}')
    if elem:
        return elem.text.strip()
    
    # Try finding by label
    label_elem = soup.find('dt', string=re.compile(label, re.IGNORECASE))
    if label_elem:
        value_elem = label_elem.find_next_sibling('dd')
        if value_elem:
            return value_elem.text.strip()
    
    return None


def _parse_date(date_str: str) -> Optional[datetime]:
    """
    Parse a date string in common Norwegian formats.
    
    Args:
        date_str: Date string to parse
        
    Returns:
        datetime object or None if parsing fails
    """
    if not date_str:
        return None
    
    # Try common Norwegian date formats
    formats = [
        '%d.%m.%Y',  # 31.12.2024
        '%d/%m/%Y',  # 31/12/2024
        '%Y-%m-%d',  # 2024-12-31 (ISO)
    ]
    
    for fmt in formats:
        try:
            return datetime.strptime(date_str, fmt)
        except ValueError:
            continue
    
    logger.warning(f"Could not parse date: {date_str}")
    return None
