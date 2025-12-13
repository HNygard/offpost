"""Tests for validator utilities."""

import unittest

from scrapers.common.validators import (
    validate_entity,
    validate_email,
    validate_org_num,
    normalize_entity_id
)


class TestValidateEntity(unittest.TestCase):
    """Tests for validate_entity function."""
    
    def test_validate_entity_valid(self):
        """Test validation of valid entity."""
        # :: Setup
        entity = {
            'entity_id': '958935420-oslo-kommune',
            'name': 'Oslo kommune',
            'type': 'municipality',
            'email': 'post@oslo.kommune.no',
            'org_num': '958935420'
        }
        
        # :: Act
        is_valid, error = validate_entity(entity)
        
        # :: Assert
        self.assertTrue(is_valid, f"Entity should be valid, but got error: {error}")
        self.assertIsNone(error)
    
    def test_validate_entity_minimal(self):
        """Test validation with only required fields."""
        # :: Setup
        entity = {
            'entity_id': 'test-entity',
            'name': 'Test Entity',
            'type': 'test'
        }
        
        # :: Act
        is_valid, error = validate_entity(entity)
        
        # :: Assert
        self.assertTrue(is_valid, f"Minimal entity should be valid, error: {error}")
    
    def test_validate_entity_missing_required_field(self):
        """Test validation fails with missing required field."""
        # :: Setup
        entity = {
            'entity_id': 'test-entity',
            'name': 'Test Entity'
            # Missing 'type'
        }
        
        # :: Act
        is_valid, error = validate_entity(entity)
        
        # :: Assert
        self.assertFalse(is_valid, "Validation should fail for missing field")
        self.assertIsNotNone(error)
        self.assertIn('type', error.lower())
    
    def test_validate_entity_invalid_entity_id(self):
        """Test validation fails with invalid entity_id format."""
        # :: Setup
        entity = {
            'entity_id': 'Invalid ID With Spaces',
            'name': 'Test Entity',
            'type': 'test'
        }
        
        # :: Act
        is_valid, error = validate_entity(entity)
        
        # :: Assert
        self.assertFalse(is_valid, "Validation should fail for invalid entity_id")
        self.assertIn('entity_id', error.lower())
    
    def test_validate_entity_invalid_type(self):
        """Test validation fails with invalid type."""
        # :: Setup
        entity = {
            'entity_id': 'test-entity',
            'name': 'Test Entity',
            'type': 'invalid-type'
        }
        
        # :: Act
        is_valid, error = validate_entity(entity)
        
        # :: Assert
        self.assertFalse(is_valid, "Validation should fail for invalid type")
        self.assertIn('type', error.lower())
    
    def test_validate_entity_invalid_email(self):
        """Test validation fails with invalid email."""
        # :: Setup
        entity = {
            'entity_id': 'test-entity',
            'name': 'Test Entity',
            'type': 'test',
            'email': 'not-an-email'
        }
        
        # :: Act
        is_valid, error = validate_entity(entity)
        
        # :: Assert
        self.assertFalse(is_valid, "Validation should fail for invalid email")
        self.assertIn('email', error.lower())
    
    def test_validate_entity_invalid_org_num(self):
        """Test validation fails with invalid org_num."""
        # :: Setup
        entity = {
            'entity_id': 'test-entity',
            'name': 'Test Entity',
            'type': 'test',
            'org_num': '123'  # Too short
        }
        
        # :: Act
        is_valid, error = validate_entity(entity)
        
        # :: Assert
        self.assertFalse(is_valid, "Validation should fail for invalid org_num")
        self.assertIn('org_num', error.lower())


class TestValidateEmail(unittest.TestCase):
    """Tests for validate_email function."""
    
    def test_validate_email_valid(self):
        """Test validation of valid email addresses."""
        # :: Setup
        valid_emails = [
            'test@example.com',
            'user.name@example.co.uk',
            'user+tag@example.com',
            'post@oslo.kommune.no',
        ]
        
        # :: Act & Assert
        for email in valid_emails:
            with self.subTest(email=email):
                self.assertTrue(validate_email(email), f"{email} should be valid")
    
    def test_validate_email_invalid(self):
        """Test validation of invalid email addresses."""
        # :: Setup
        invalid_emails = [
            'not-an-email',
            '@example.com',
            'user@',
            'user@domain',
            'user space@example.com',
        ]
        
        # :: Act & Assert
        for email in invalid_emails:
            with self.subTest(email=email):
                self.assertFalse(validate_email(email), f"{email} should be invalid")


class TestValidateOrgNum(unittest.TestCase):
    """Tests for validate_org_num function."""
    
    def test_validate_org_num_valid(self):
        """Test validation of valid org numbers."""
        # :: Setup
        valid_nums = [
            '958935420',
            '123456789',
            '000000000',
        ]
        
        # :: Act & Assert
        for num in valid_nums:
            with self.subTest(org_num=num):
                self.assertTrue(validate_org_num(num), f"{num} should be valid")
    
    def test_validate_org_num_invalid(self):
        """Test validation of invalid org numbers."""
        # :: Setup
        invalid_nums = [
            '12345678',  # Too short
            '1234567890',  # Too long
            'abcdefghi',  # Not digits
            '12345-678',  # Contains dash
        ]
        
        # :: Act & Assert
        for num in invalid_nums:
            with self.subTest(org_num=num):
                self.assertFalse(validate_org_num(num), f"{num} should be invalid")


class TestNormalizeEntityId(unittest.TestCase):
    """Tests for normalize_entity_id function."""
    
    def test_normalize_entity_id_with_org_num(self):
        """Test normalization with org number."""
        # :: Act
        result = normalize_entity_id('Oslo Kommune', '958935420')
        
        # :: Assert
        self.assertEqual(result, '958935420-oslo-kommune')
    
    def test_normalize_entity_id_without_org_num(self):
        """Test normalization without org number."""
        # :: Act
        result = normalize_entity_id('Test Entity')
        
        # :: Assert
        self.assertEqual(result, 'test-entity')
    
    def test_normalize_entity_id_norwegian_characters(self):
        """Test normalization of Norwegian characters."""
        # :: Act
        result = normalize_entity_id('Tromsø Kommune')
        
        # :: Assert
        self.assertEqual(result, 'tromso-kommune')
        self.assertNotIn('ø', result)
    
    def test_normalize_entity_id_special_characters(self):
        """Test normalization removes special characters."""
        # :: Act
        result = normalize_entity_id('Test & Entity (2024)')
        
        # :: Assert
        self.assertEqual(result, 'test-entity-2024')
        self.assertNotIn('&', result)
        self.assertNotIn('(', result)
        self.assertNotIn(')', result)
    
    def test_normalize_entity_id_multiple_spaces(self):
        """Test normalization handles multiple spaces."""
        # :: Act
        result = normalize_entity_id('Test    Entity')
        
        # :: Assert
        self.assertEqual(result, 'test-entity')
        # Should not have multiple consecutive hyphens
        self.assertNotIn('--', result)
    
    def test_normalize_entity_id_trailing_characters(self):
        """Test normalization removes trailing special chars."""
        # :: Act
        result = normalize_entity_id('  Test Entity  ')
        
        # :: Assert
        self.assertEqual(result, 'test-entity')
        self.assertFalse(result.startswith('-'))
        self.assertFalse(result.endswith('-'))


if __name__ == '__main__':
    unittest.main()
