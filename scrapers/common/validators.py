"""Data validation utilities for scrapers."""

import re
from typing import Dict, Optional, Tuple


def validate_entity(entity: Dict) -> Tuple[bool, Optional[str]]:
    """
    Validate entity data structure and content.
    
    Args:
        entity: Entity dictionary to validate
        
    Returns:
        Tuple of (is_valid, error_message)
    """
    required_fields = ['entity_id', 'name', 'type']
    
    # Check required fields
    for field in required_fields:
        if field not in entity or not entity[field]:
            return False, f"Missing required field: {field}"
    
    # Validate entity_id format (lowercase alphanumeric and hyphens only)
    if not re.match(r'^[a-z0-9-]+$', entity['entity_id']):
        return False, f"Invalid entity_id format: {entity['entity_id']}"
    
    # Validate type is one of expected values
    valid_types = ['municipality', 'agency', 'technical', 'test']
    if entity['type'] not in valid_types:
        return False, f"Invalid type: {entity['type']}. Must be one of {valid_types}"
    
    # Validate email if present
    if 'email' in entity and entity['email']:
        if not validate_email(entity['email']):
            return False, f"Invalid email format: {entity['email']}"
    
    # Validate org_num if present (Norwegian organization number: 9 digits)
    if 'org_num' in entity and entity['org_num']:
        if not re.match(r'^\d{9}$', entity['org_num']):
            return False, f"Invalid org_num format: {entity['org_num']} (must be 9 digits)"
    
    return True, None


def validate_email(email: str) -> bool:
    """
    Validate email format.
    
    Args:
        email: Email address to validate
        
    Returns:
        True if valid, False otherwise
    """
    pattern = r'^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$'
    return bool(re.match(pattern, email))


def validate_org_num(org_num: str) -> bool:
    """
    Validate Norwegian organization number.
    
    Args:
        org_num: Organization number to validate
        
    Returns:
        True if valid, False otherwise
    """
    # Must be 9 digits
    if not re.match(r'^\d{9}$', org_num):
        return False
    
    # TODO: Implement MOD11 checksum validation if needed
    return True


def normalize_entity_id(name: str, org_num: Optional[str] = None) -> str:
    """
    Generate a normalized entity_id from name and optional org_num.
    
    Args:
        name: Entity name
        org_num: Optional organization number
        
    Returns:
        Normalized entity_id (lowercase, alphanumeric with hyphens)
    """
    # Start with org_num if available
    if org_num:
        entity_id = f"{org_num}-"
    else:
        entity_id = ""
    
    # Add normalized name
    normalized_name = name.lower()
    # Remove/replace special characters
    normalized_name = re.sub(r'[åä]', 'a', normalized_name)
    normalized_name = re.sub(r'[ø]', 'o', normalized_name)
    normalized_name = re.sub(r'[æ]', 'ae', normalized_name)
    normalized_name = re.sub(r'[^a-z0-9]+', '-', normalized_name)
    normalized_name = normalized_name.strip('-')
    
    entity_id += normalized_name
    
    return entity_id
