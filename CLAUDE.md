# CLAUDE.md - Offpost Codebase Guide

This document provides AI assistants with essential context for working with the Offpost codebase.

## Project Overview

Offpost ("Offentlig post") is a Norwegian service for sending structured email requests to public authorities. Users create email threads with randomly assigned, unique email identities (using real Norwegian names in unique combinations). Each thread maintains its own profile (first name, last name, email) for the conversation lifecycle.

**Key Concepts:**
- **Thread**: A conversation with a public entity, identified by UUID
- **Profile**: Unique identity (name + email) assigned per thread
- **Entity**: A public authority/organization that receives requests
- **IMAP Folders**: Emails organized on IMAP server, one folder per thread

## Architecture

PHP 8.2 web application with PostgreSQL database. Email-centric design using IMAP for storage/retrieval and OpenAI for text extraction/summarization.

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Web Client    │────▶│   Organizer     │────▶│   PostgreSQL    │
│   (Browser)     │     │   (PHP/Apache)  │     │   (Database)    │
└─────────────────┘     └────────┬────────┘     └─────────────────┘
                                 │
                    ┌────────────┼────────────┐
                    ▼            ▼            ▼
            ┌───────────┐ ┌───────────┐ ┌───────────┐
            │   IMAP    │ │  OpenAI   │ │ SendGrid  │
            │  Server   │ │   API     │ │  (Prod)   │
            └───────────┘ └───────────┘ └───────────┘
```

## Directory Structure

```
/home/user/offpost/
├── organizer/                  # Main PHP application
│   ├── Dockerfile
│   ├── src/
│   │   ├── class/             # PHP classes
│   │   │   ├── Ai/            # OpenAI integration
│   │   │   ├── Enums/         # PHP enums
│   │   │   ├── Extraction/    # Email text extraction
│   │   │   │   └── Prompts/   # LLM prompt templates
│   │   │   └── Imap/          # IMAP operations
│   │   ├── api/               # REST API endpoints
│   │   ├── system-pages/      # Scheduled task endpoints
│   │   ├── tests/             # Unit tests (PHPUnit)
│   │   ├── e2e-tests/         # Integration tests
│   │   ├── migrations/sql/    # Database migrations
│   │   ├── webroot/           # Frontend assets (CSS, JS)
│   │   └── *.php              # Page entry points
├── auth/                       # Node.js OIDC service (dev only)
├── cron-scheduler/            # Scheduled job runner
├── data/                       # Static data and test files
│   ├── entities.json          # Public entity directory
│   └── test-emails/           # Sample EML files
├── secrets/                    # Runtime secrets (gitignored)
├── docker-compose.dev.yaml    # Development environment
└── docker-compose.prod.yaml   # Production deployment
```

## Key Classes

### Core Data Models
- `Thread.php` - Thread entity with status management
- `ThreadEmail.php` - Email message model
- `ThreadEmailAttachment.php` - Attachment metadata
- `Entity.php` - Public entity directory

### Database Operations
- `Database.php` - PDO singleton connection manager
- `ThreadDatabaseOperations.php` - Thread CRUD operations
- `ThreadStatusRepository.php` - Thread status tracking

### Email Processing
- `ThreadEmailService.php` - High-level email operations
- `ThreadEmailSending.php` - Email composition and sending
- `ThreadEmailMover.php` - Email folder organization
- `ThreadEmailDatabaseSaver.php` - Persist emails to database
- `ThreadEmailClassifier.php` - Incoming vs outgoing classification

### IMAP Integration (`class/Imap/`)
- `ImapConnection.php` - Low-level IMAP protocol
- `ImapWrapper.php` - Retry logic and error handling
- `ImapFolderManager.php` - Create/manage IMAP folders
- `ImapEmailProcessor.php` - Parse emails from IMAP
- `ImapAttachmentHandler.php` - Extract attachments and PDFs

### AI/Extraction (`class/Extraction/`)
- `ThreadEmailExtractionService.php` - Create extraction records
- `ThreadEmailExtractor.php` - Orchestrate extraction types
- `ThreadEmailExtractorEmailBody.php` - Parse email text
- `ThreadEmailExtractorAttachmentPdf.php` - PDF text extraction
- `ThreadEmailExtractorPrompt.php` - LLM prompting framework

### Scheduled Tasks
- `ThreadScheduledEmailSender.php` - Process staging queue
- `ThreadScheduledEmailReceiver.php` - Fetch from inbox
- `ThreadScheduledFollowUpSender.php` - Auto-generate follow-ups

## Development Commands

### Start development environment
```bash
docker-compose -f docker-compose.dev.yaml up -d
```

### Run unit tests
```bash
./organizer/src/vendor/bin/phpunit organizer/src/tests/
```

### Run E2E tests
```bash
./organizer/src/vendor/bin/phpunit organizer/src/e2e-tests/
```

### Run all tests
```bash
./organizer/src/vendor/bin/phpunit organizer/src/tests/ organizer/src/e2e-tests/
```

### Rebuild and restart containers
```bash
docker-compose -f docker-compose.dev.yaml down && docker-compose -f docker-compose.dev.yaml up --build -d
```

### View organizer logs
```bash
docker-compose -f docker-compose.dev.yaml logs organizer
```

### Access database
```bash
docker exec -it offpost_postgres_1 psql -U offpost -d offpost
```

### Reset database completely
```bash
docker-compose -f docker-compose.dev.yaml rm -s -f -v postgres organizer
docker volume rm offpost_postgres_data_development
docker-compose -f docker-compose.dev.yaml up -d
sleep 5
docker-compose -f docker-compose.dev.yaml logs organizer | grep "\\[migrate\\]"
```

### Reset mail server (GreenMail)
```bash
docker-compose -f docker-compose.dev.yaml restart greenmail
```

## Development Services

| Service | URL | Description |
|---------|-----|-------------|
| Organizer | http://localhost:25081/ | Main PHP application |
| Roundcube | http://localhost:25080/ | Webmail client |
| Auth | http://localhost:25083/ | OIDC provider (dev) |
| Adminer | http://localhost:25084/ | Database admin |
| PHPMyAdmin | http://localhost:25082/ | MySQL admin |
| GreenMail | http://localhost:25181/ | Mail server UI |

## Database Migrations

Migrations are SQL scripts in `organizer/src/migrations/sql/`, numbered sequentially (e.g., `020_add_column.sql`).

**Current schema**: `organizer/src/migrations/sql/99999-database-schema-after-migrations.sql`

Migrations run automatically on container startup. To add a new migration:
1. Create file with next sequence number in `organizer/src/migrations/sql/`
2. Restart the organizer container to apply

## Testing Guidelines

### Test Structure
```php
// :: Setup
$dependency = new MockDependency();
$subject = new ClassUnderTest($dependency);

// :: Act
$result = $subject->methodToTest();

// :: Assert
$this->assertEquals($expected, $result);
```

### Rules
- Tests must **fail**, not skip - never use `markTestSkipped()`
- Tests must be **deterministic** - no `rand()`, `time()`, etc.
- Use `assertEquals` for deterministic outputs, not `assertContains`
- Array assertions should include the array in error message: `json_encode($array, JSON_PRETTY_PRINT)`
- Clean up database state in `tearDown()`

## Coding Conventions

### Security
- Use PDO prepared statements for all queries
- Never commit secrets or credentials
- Validate user input at system boundaries
- Protect PII in error logs

### PHP Style
- Use type hints for parameters and return types
- Follow PSR-4 autoloading conventions
- Document complex logic with inline comments

### Git Workflow
- Never stage changes - staging is done by human user
- Use `git --no-pager diff` for viewing diffs
- Suggest commit messages explaining what and why
- Avoid unnecessary modifications (formatting, renaming)

## Common Patterns

### Database Query Pattern
```php
$pdo = Database::getInstance()->getPdo();
$stmt = $pdo->prepare("SELECT * FROM threads WHERE id = :id");
$stmt->execute([':id' => $threadId]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
```

### IMAP Connection Pattern
```php
$wrapper = new ImapWrapper($imapConfig);
$wrapper->connect();
try {
    $emails = $wrapper->fetchEmails($folder);
} finally {
    $wrapper->disconnect();
}
```

### Error Handling Pattern
```php
try {
    $result = $service->process();
} catch (Exception $e) {
    AdminNotificationService::notifyError($e, $context);
    throw $e;
}
```

## Entry Points

### Web Pages
- `index.php` - Main dashboard with thread list
- `start-thread.php` - Create new thread with profile
- `view-thread.php` - Thread details and emails
- `api.php` - REST API router
- `auth.php` / `callback.php` - OIDC authentication

### Scheduled Tasks (`system-pages/`)
- `scheduled-email-sending.php` - Send staged emails
- `scheduled-email-receiver.php` - Fetch incoming emails
- `scheduled-imap-handling.php` - IMAP folder management
- `scheduled-email-extraction.php` - AI text extraction
- `scheduled-thread-follow-up.php` - Auto follow-up generation

## File Naming Conventions

- Classes: `PascalCase.php` (e.g., `ThreadEmailService.php`)
- Tests: `ClassNameTest.php` (e.g., `ThreadEmailServiceTest.php`)
- Migrations: `NNN_description.sql` (e.g., `020_add_column.sql`)
- Pages: `kebab-case.php` (e.g., `view-thread.php`)

## Technology Stack

| Component | Technology |
|-----------|------------|
| Language | PHP 8.2 |
| Web Server | Apache 2.4 |
| Database | PostgreSQL 15 |
| Email Parsing | Laminas\Mail |
| Email Sending | PHPMailer, SendGrid |
| Testing | PHPUnit 10.5 |
| Authentication | OIDC (Auth0 in prod) |
| AI | OpenAI API |
| Containers | Docker Compose |

## Sensitive Files - DO NOT READ OR MODIFY

- `.env` files
- `**/config/secrets.*`
- `**/*.pem`
- `secrets/` directory contents
- Any file containing API keys, tokens, or credentials
