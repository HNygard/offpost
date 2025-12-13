"""Data models for Jupiter Byggesak scraper."""

from dataclasses import dataclass
from typing import Optional
from datetime import datetime


@dataclass
class BuildingCase:
    """Represents a building permit case."""
    
    case_number: str
    municipality: str
    case_type: str
    status: str
    address: Optional[str] = None
    property_id: Optional[str] = None  # Combined gårdsnummer, bruksnummer, etc.
    application_date: Optional[datetime] = None
    decision_date: Optional[datetime] = None
    applicant: Optional[str] = None
    description: Optional[str] = None
    source_url: Optional[str] = None
    
    def to_dict(self):
        """Convert to dictionary representation."""
        return {
            'case_number': self.case_number,
            'municipality': self.municipality,
            'case_type': self.case_type,
            'status': self.status,
            'address': self.address,
            'property_id': self.property_id,
            'application_date': self.application_date.isoformat() if self.application_date else None,
            'decision_date': self.decision_date.isoformat() if self.decision_date else None,
            'applicant': self.applicant,
            'description': self.description,
            'source_url': self.source_url,
        }


@dataclass
class SearchResult:
    """Represents a search result from Jupiter Byggesak."""
    
    case_id: str
    case_number: str
    title: str
    status: str
    date: Optional[datetime] = None
    
    def to_dict(self):
        """Convert to dictionary representation."""
        return {
            'case_id': self.case_id,
            'case_number': self.case_number,
            'title': self.title,
            'status': self.status,
            'date': self.date.isoformat() if self.date else None,
        }
