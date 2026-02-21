# Migration Plan: PHP to Java

**Status**: Planning Phase
**Created**: 2026-02-06
**Target Framework**: Spring Boot 3.x
**Build Tool**: Maven (recommended for enterprise Java applications)

## Executive Summary

This document outlines a comprehensive plan to migrate the Offpost email thread management system from PHP to Java. The application currently consists of 181 PHP files with a complex architecture involving email processing, IMAP integration, AI services, and multiple database backends.

## Current Architecture Overview

### Technology Stack (PHP)
- **Language**: PHP 8.x
- **Web Server**: Apache (via Docker)
- **Database**: PostgreSQL 15 (main app) + MySQL 5.6 (Roundcube)
- **Email**: IMAP (GreenMail in dev), SendGrid (production)
- **AI**: OpenAI API integration
- **Authentication**: Auth0 (production), custom service (dev)
- **Testing**: PHPUnit 10.5
- **Dependencies**: PHPMailer, Laminas Mail, PDO

### Core Components
1. **Organizer** - Main PHP application (181 files)
   - 57 class files in `organizer/src/class/`
   - API endpoints in `organizer/src/api/`
   - Web pages for UI
   - Scheduled tasks in `organizer/src/system-pages/`
   - 30 SQL migrations

2. **Roundcube** - Webmail client (Docker container)
3. **IMAP Server** - Email storage (GreenMail/production server)
4. **PostgreSQL** - Main application database
5. **MySQL** - Roundcube database
6. **Auth Service** - Development authentication

## Target Architecture (Java)

### Technology Stack (Java)
- **Language**: Java 21 LTS
- **Framework**: Spring Boot 3.2+
- **Build Tool**: Maven 3.9+
- **Database**: PostgreSQL 15 (main app) + MySQL 5.6 (Roundcube - unchanged)
- **ORM**: Spring Data JPA with Hibernate
- **Migration**: Flyway or Liquibase
- **Email**: JavaMail API for IMAP, SendGrid Java SDK
- **AI**: OpenAI Java SDK or HTTP client
- **Authentication**: Spring Security with Auth0 integration
- **Testing**: JUnit 5, Mockito, TestContainers, GreenMail
- **Scheduling**: Spring @Scheduled annotations
- **API**: Spring REST with OpenAPI/Swagger documentation

### Recommended Project Structure
```
offpost-java/
├── pom.xml
├── src/
│   ├── main/
│   │   ├── java/
│   │   │   └── no/offpost/
│   │   │       ├── domain/          # Domain entities
│   │   │       │   ├── Thread.java
│   │   │       │   ├── ThreadEmail.java
│   │   │       │   ├── Entity.java
│   │   │       │   └── enums/
│   │   │       ├── repository/      # JPA repositories
│   │   │       ├── service/         # Business logic
│   │   │       │   ├── imap/
│   │   │       │   ├── email/
│   │   │       │   ├── ai/
│   │   │       │   └── extraction/
│   │   │       ├── controller/      # REST API
│   │   │       ├── scheduler/       # Scheduled tasks
│   │   │       ├── config/          # Configuration
│   │   │       └── Application.java
│   │   └── resources/
│   │       ├── application.yml
│   │       ├── db/migration/        # Flyway migrations
│   │       └── templates/           # Thymeleaf (optional)
│   └── test/
│       └── java/
│           └── no/offpost/
│               ├── unit/
│               ├── integration/
│               └── e2e/
├── Dockerfile
└── README.md
```

## Migration Phases

### Phase 1: Foundation and Setup (Week 1-2)

**Objective**: Establish Java project infrastructure

**Tasks**:
1. Create Maven project with Spring Boot starter
2. Set up project structure (package hierarchy)
3. Configure `pom.xml` with dependencies:
   - Spring Boot Starter Web
   - Spring Boot Starter Data JPA
   - PostgreSQL JDBC driver
   - MySQL JDBC driver (for Roundcube)
   - JavaMail API
   - Spring Boot Starter Security
   - Spring Boot Starter Test
   - TestContainers
   - Lombok (for reducing boilerplate)
4. Create `application.yml` with environment-specific profiles
5. Set up Docker Compose for Java development environment
6. Configure logging (SLF4J with Logback)
7. Implement health check endpoints

**Deliverables**:
- Running Spring Boot application
- Docker Compose configuration
- Basic project structure
- CI/CD pipeline configuration

### Phase 2: Core Domain Model (Week 3-4)

**Objective**: Port domain entities and business objects

**Tasks**:
1. Create `Thread` entity with JPA annotations
   - Status enums: `ThreadSendingStatus` (STAGING, READY_FOR_SENDING, SENDING, SENT)
   - Law basis enums: `RequestLawBasis` (OFFENTLEGLOVA, OTHER)
   - Follow-up plan enums: `RequestFollowUpPlan` (SPEEDY, SLOW)
   - All properties from PHP version
   - Proper JPA relationships

2. Create `ThreadEmail` entity with attachments relationship
3. Create `ThreadEmailAttachment` entity
4. Create `Entity` class (public organization)
5. Create `Identity` class
6. Create `ImapFolderStatus` class
7. Create `ThreadHistory` class
8. Create `ImapFolderLog` class
9. Implement proper equals/hashCode/toString
10. Add validation annotations (JSR-303)

**PHP Files to Port**:
- `class/Thread.php`
- `class/ThreadEmail.php`
- `class/ThreadEmailAttachment.php`
- `class/Entity.php`
- `class/Identity.php`
- `class/ImapFolderStatus.php`
- `class/ThreadHistory.php`
- `class/ImapFolderLog.php`

**Deliverables**:
- Complete domain model in Java
- Unit tests for domain logic
- JSON serialization/deserialization working

### Phase 3: Database Layer (Week 5-6)

**Objective**: Implement database access and migration

**Tasks**:
1. Port 30 SQL migrations to Flyway format (`V1__*.sql` to `V30__*.sql`)
2. Create JPA repositories:
   - `ThreadRepository extends JpaRepository<Thread, String>`
   - `ThreadEmailRepository`
   - `EntityRepository`
   - `IdentityRepository`
   - `ImapFolderStatusRepository`
   - `ImapFolderLogRepository`
3. Port `ThreadDatabaseOperations` to service layer
4. Port `ThreadStatusRepository` with custom queries
5. Implement `ThreadStorageManager` functionality
6. Configure transaction management
7. Set up connection pooling (HikariCP)

**PHP Files to Port**:
- `class/Database.php` → Spring Data JPA configuration
- `class/ThreadDatabaseOperations.php` → `ThreadService.java`
- `class/ThreadStatusRepository.php` → Custom repository methods
- `class/ThreadStorageManager.php` → Service layer
- `migrations/sql/*.sql` → `db/migration/*.sql`

**Deliverables**:
- All migrations ported and tested
- Repository layer complete with tests
- Database integration tests using TestContainers

### Phase 4: IMAP Integration (Week 7-9)

**Objective**: Implement email handling with IMAP

**Tasks**:
1. Create `ImapWrapper` using JavaMail API
   - Connection management with retry logic
   - Exponential backoff for transient failures
   - Folder operations (list, create, rename, delete)
   - Message operations (fetch, move, delete)

2. Port `ImapConnection` class
   - Connection pooling
   - SSL/TLS configuration
   - Credential management

3. Port `ImapFolderManager`
   - Folder hierarchy management
   - Thread-specific folder operations

4. Port `ImapEmail` and `ImapEmailProcessor`
   - Email parsing from IMAP
   - Header extraction
   - Body parsing (text/HTML)

5. Port `ImapAttachmentHandler`
   - Attachment extraction
   - Binary data handling
   - Content type detection

6. Port `ThreadEmailMover`
   - Move emails between folders
   - Error handling and recovery

**PHP Files to Port**:
- `class/Imap/ImapWrapper.php`
- `class/Imap/ImapConnection.php`
- `class/Imap/ImapFolderManager.php`
- `class/Imap/ImapEmail.php`
- `class/Imap/ImapEmailProcessor.php`
- `class/Imap/ImapAttachmentHandler.php`
- `class/ThreadEmailMover.php`
- `class/ThreadFolderManager.php`

**Deliverables**:
- Complete IMAP integration
- Integration tests with GreenMail
- Retry logic verified under failure conditions

### Phase 5: Email Processing Services (Week 10-12)

**Objective**: Implement scheduled email processing tasks

**Tasks**:
1. Port `ThreadScheduledEmailReceiver`
   - Spring @Scheduled annotation
   - Poll IMAP for new emails
   - Process incoming messages
   - Error handling and logging

2. Port `ThreadScheduledEmailSender`
   - Process sending queue
   - SendGrid integration
   - Status updates
   - Failure handling

3. Port `ThreadScheduledFollowUpSender`
   - Automatic follow-up logic
   - Scheduling based on follow-up plan
   - Follow-up email generation

4. Port `ThreadEmailDatabaseSaver`
   - Parse EML files
   - Extract headers, body, attachments
   - Save to database
   - Handle multipart messages

5. Port `ThreadEmailSending` and `ThreadEmailService`
   - Email composition
   - Template handling
   - Sending via SendGrid
   - Copy to IMAP sent folder

6. Implement SendGrid integration
   - Configure SendGrid Java SDK
   - Event webhooks for delivery status
   - Error handling

**PHP Files to Port**:
- `class/ThreadScheduledEmailReceiver.php`
- `class/ThreadScheduledEmailSender.php`
- `class/ThreadScheduledFollowUpSender.php`
- `class/ThreadEmailDatabaseSaver.php`
- `class/ThreadEmailSending.php`
- `class/ThreadEmailService.php`
- `system-pages/scheduled-email-sending.php`
- `system-pages/scheduled-email-receiver.php`
- `system-pages/scheduled-imap-handling.php`
- `system-pages/scheduled-thread-follow-up.php`

**Deliverables**:
- Scheduled tasks running in Spring
- Email sending/receiving working
- Follow-up automation functional
- E2E tests for email processing

### Phase 6: AI Integration (Week 13-14)

**Objective**: Integrate OpenAI for email processing

**Tasks**:
1. Port OpenAI integration classes
   - HTTP client for OpenAI API
   - Request/response models
   - Error handling and retry logic
   - Token usage tracking

2. Port `SuggestedReplyGenerator`
   - Generate reply suggestions using GPT
   - Context from email thread
   - Template customization

3. Port `ThreadEmailClassifier`
   - Classify email types
   - Extract key information
   - Sentiment analysis

4. Port extraction services
   - PDF text extraction (using Apache PDFBox)
   - Image text extraction (OCR if needed)
   - HTML to text conversion
   - Content summarization

5. Configure OpenAI client
   - API key management
   - Rate limiting
   - Cost tracking

**PHP Files to Port**:
- `class/Ai/*.php` (all AI-related classes)
- `class/Extraction/*.php` (all extraction services)
- `class/SuggestedReplyGenerator.php`
- `class/ThreadEmailClassifier.php`
- `system-pages/scheduled-email-extraction.php`
- `api/thread_email_extraction.php`

**Deliverables**:
- OpenAI integration working
- Extraction services functional
- Unit tests with mocked API responses
- Integration tests with test API key

### Phase 7: REST API Layer (Week 15-16)

**Objective**: Create REST API for frontend/external access

**Tasks**:
1. Create Spring REST controllers:
   - `ThreadController` - CRUD operations for threads
   - `EmailController` - Email operations
   - `EntityController` - Public entity management
   - `ExtractionController` - AI extraction endpoints
   - `AdminController` - Administrative operations

2. Port authentication logic
   - Spring Security configuration
   - Auth0 integration (production)
   - JWT token validation
   - Role-based access control

3. Implement `ThreadAuthorization`
   - Check user permissions
   - Thread access control
   - Public thread handling

4. Port API endpoints from `api/` directory
   - Thread creation/update/delete
   - Email operations
   - Extraction triggers
   - Bulk operations

5. Implement `AdminNotificationService`
   - Alert on critical errors
   - Email notifications
   - Logging integration

6. Add OpenAPI/Swagger documentation
   - API documentation UI
   - Request/response schemas
   - Example payloads

**PHP Files to Port**:
- `api/*.php` (all API endpoints)
- `class/ThreadAuthorization.php`
- `class/AdminNotificationService.php`
- `auth.php`
- `callback.php`

**Deliverables**:
- Complete REST API
- API documentation (Swagger UI)
- Authentication working
- Integration tests for all endpoints

### Phase 8: Web Frontend (Week 17-20)

**Objective**: Provide web UI for the application

**Options to Consider**:

**Option A: Keep PHP Pages (Temporary)**
- Minimal changes to existing PHP pages
- API calls to Java backend
- Gradual migration path
- Good for quick transition

**Option B: Migrate to Thymeleaf**
- Server-side rendering with Spring
- Template-based approach
- Similar to PHP pages
- Less frontend complexity

**Option C: Separate SPA (React/Vue/Angular)**
- Modern frontend framework
- Better user experience
- Complete separation of concerns
- More development effort

**Recommended Approach**: Option B (Thymeleaf) or Option C (SPA)

**Tasks for Option B (Thymeleaf)**:
1. Set up Thymeleaf templates
2. Port page logic to Spring MVC controllers:
   - Index page (thread listing)
   - Thread view page
   - Thread creation page
   - Thread reply page
   - Entity management pages
   - Extraction overview pages
   - Bulk action pages
3. Port forms and validation
4. Handle file uploads
5. Implement session management
6. Port Roundcube integration logic

**PHP Files to Port**:
- `index.php`
- `view-thread.php`
- `start-thread.php`
- `thread-reply.php`
- `entities.php`
- `thread-bulk-actions.php`
- `update-identities.php`
- `update-imap.php`
- `recent-activity.php`
- `grant-thread-access.php`
- And all other UI pages

**Deliverables**:
- Functional web UI
- Feature parity with PHP version
- E2E tests for web pages
- User documentation

### Phase 9: Testing Infrastructure (Week 21-22)

**Objective**: Achieve comprehensive test coverage

**Tasks**:
1. Set up JUnit 5 and Mockito
2. Port unit tests:
   - Domain model tests
   - Service layer tests
   - Repository tests
   - IMAP integration tests
   - Email processing tests
   - AI integration tests
3. Port integration tests with TestContainers:
   - PostgreSQL container
   - GreenMail container
   - Full application context tests
4. Port E2E tests:
   - Web page tests (Selenium/Playwright)
   - API endpoint tests
   - Email flow tests
5. Configure test coverage reporting (JaCoCo)
6. Set up continuous testing in CI/CD

**PHP Test Files to Port** (57+ test files):
- `tests/*.php` (all unit tests)
- `e2e-tests/pages/*.php` (all E2E tests)

**Test Requirements to Maintain**:
- Tests must be deterministic (no random values, no time-based data)
- Tests must fail instead of skip (no `@Disabled` without good reason)
- Use exact assertions for deterministic outputs
- Follow Arrange-Act-Assert pattern
- Each test should be independent

**Deliverables**:
- Complete test suite (unit + integration + E2E)
- Test coverage > 80%
- All tests passing
- CI/CD integration

### Phase 10: Deployment and Migration (Week 23-24)

**Objective**: Deploy Java application and migrate data

**Tasks**:
1. Create production Dockerfile:
   - Multi-stage build for efficiency
   - JRE base image (Eclipse Temurin 21)
   - Non-root user
   - Health checks

2. Update Docker Compose files:
   - `docker-compose.dev.yaml` - Java development environment
   - `docker-compose.prod.yaml` - Production configuration

3. Create Kubernetes manifests (if applicable):
   - Deployment
   - Service
   - ConfigMap
   - Secret
   - Ingress

4. Migration strategy:
   - Database migration scripts (if schema changes)
   - Data validation scripts
   - Rollback procedures

5. Deployment approach:
   - **Blue-Green Deployment**: Run both versions, switch traffic
   - **Canary Deployment**: Gradually shift traffic to Java
   - **Feature Flags**: Control which version handles requests

6. Monitoring and observability:
   - Application metrics (Micrometer)
   - Logs (structured logging)
   - Health endpoints
   - Performance monitoring

7. Production cutover plan:
   - Backup procedures
   - Validation checkpoints
   - Rollback triggers
   - Communication plan

**Deliverables**:
- Production-ready Docker images
- Deployment automation
- Migration scripts
- Monitoring setup
- Cutover runbook

### Phase 11: Documentation (Week 25-26)

**Objective**: Comprehensive documentation for Java version

**Tasks**:
1. Update `README.md`:
   - Java/Maven setup instructions
   - Build commands
   - Development workflow
   - Deployment procedures

2. Update `CLAUDE.md` (or create `CLAUDE_JAVA.md`):
   - Java-specific conventions
   - Package structure
   - Testing guidelines
   - Common tasks

3. Create Java-specific documentation:
   - Architecture Decision Records (ADRs)
   - API documentation (Swagger/OpenAPI)
   - Database schema documentation
   - Email flow diagrams
   - Troubleshooting guide

4. Developer onboarding guide:
   - IDE setup (IntelliJ IDEA / Eclipse)
   - Code style configuration
   - Git workflow
   - Testing strategy

5. Operations runbook:
   - Deployment procedures
   - Monitoring and alerting
   - Backup and recovery
   - Common issues and solutions

**Deliverables**:
- Complete documentation set
- Updated repository README
- Developer onboarding guide
- Operations runbook

## Key Technical Decisions

### 1. Build Tool: Maven vs Gradle

**Recommendation: Maven**
- More widely used in enterprise Java
- Better IDE support
- Simpler for standard Spring Boot projects
- Larger ecosystem

### 2. Framework: Spring Boot

**Rationale**:
- Industry standard for Java web applications
- Comprehensive ecosystem (Data, Security, Cloud)
- Excellent documentation and community
- Built-in support for scheduled tasks, REST APIs, JPA
- Easy integration with Auth0, SendGrid, OpenAI

### 3. Migration Tool: Flyway vs Liquibase

**Recommendation: Flyway**
- Simpler SQL-based approach
- Easier migration from existing SQL scripts
- Good Spring Boot integration
- Sufficient for this use case

### 4. Frontend Approach

**Recommendation: Thymeleaf for MVP, consider SPA later**
- Thymeleaf provides quickest path to feature parity
- Server-side rendering reduces complexity
- Can transition to SPA incrementally
- Consider React/Vue for future enhancement

### 5. Scheduling: Spring @Scheduled

**Rationale**:
- Built into Spring Framework
- Simple annotation-based configuration
- Sufficient for current cron-like tasks
- Can scale to Quartz if needed

### 6. Email Library: JavaMail API

**Rationale**:
- Standard Java email API
- Comprehensive IMAP support
- Good Spring integration
- Well-documented

## Risk Analysis and Mitigation

### Technical Risks

**Risk 1: IMAP Behavior Differences**
- **Impact**: Email processing might behave differently
- **Mitigation**: Comprehensive integration tests with GreenMail; parallel run to compare behavior

**Risk 2: Performance Degradation**
- **Impact**: Java application might be slower or use more memory
- **Mitigation**: Performance testing early; profiling and optimization; proper JVM tuning

**Risk 3: Data Migration Issues**
- **Impact**: Data loss or corruption during migration
- **Mitigation**: Thorough testing of migration scripts; backup procedures; ability to rollback

**Risk 4: Third-Party Integration Changes**
- **Impact**: Different behavior with Auth0, SendGrid, OpenAI
- **Mitigation**: Integration tests; sandbox testing; gradual rollout

**Risk 5: Missing PHP Functionality**
- **Impact**: Some PHP-specific features might be hard to replicate
- **Mitigation**: Early identification of PHP-specific code; find Java alternatives; document workarounds

### Project Risks

**Risk 6: Scope Creep**
- **Impact**: Migration takes longer than planned
- **Mitigation**: Strict feature parity focus; defer enhancements; clear phase boundaries

**Risk 7: Knowledge Gap**
- **Impact**: Team unfamiliar with Java/Spring ecosystem
- **Mitigation**: Training and documentation; pair programming; code reviews

**Risk 8: Testing Coverage Gap**
- **Impact**: Bugs in production due to incomplete testing
- **Mitigation**: Port tests early; maintain coverage metrics; E2E testing

## Success Criteria

1. **Functional Parity**: All features from PHP version working in Java
2. **Test Coverage**: >80% code coverage with passing tests
3. **Performance**: Response times comparable to PHP version
4. **Stability**: No critical bugs in first month of production
5. **Documentation**: Complete and accurate documentation
6. **Deployment**: Successful production deployment with rollback capability

## Timeline Estimate

**Total Duration**: 26 weeks (6.5 months)

- Phase 1-2: Foundation and Domain (4 weeks)
- Phase 3-4: Database and IMAP (5 weeks)
- Phase 5-6: Email Processing and AI (5 weeks)
- Phase 7-8: API and Frontend (6 weeks)
- Phase 9-11: Testing, Deployment, Documentation (6 weeks)

**Note**: Timeline is aggressive and assumes:
- Full-time dedicated resources
- Team familiar with Java/Spring Boot
- Minimal scope changes
- Parallel work where possible

## Resource Requirements

- **Java Developers**: 2-3 full-time
- **DevOps Engineer**: 0.5 FTE (part-time)
- **QA Engineer**: 1 full-time
- **Technical Writer**: 0.25 FTE (part-time)

## Next Steps

1. **Review and Approve Plan**: Stakeholder review of this plan
2. **Resource Allocation**: Assign team members
3. **Environment Setup**: Prepare development infrastructure
4. **Phase 1 Kickoff**: Begin foundation work
5. **Weekly Reviews**: Track progress against plan

## References

- [Spring Boot Documentation](https://spring.io/projects/spring-boot)
- [JavaMail API Guide](https://javaee.github.io/javamail/)
- [Flyway Documentation](https://flywaydb.org/documentation/)
- [TestContainers](https://www.testcontainers.org/)
- [Auth0 Spring Security Integration](https://auth0.com/docs/quickstart/webapp/java-spring-boot)
