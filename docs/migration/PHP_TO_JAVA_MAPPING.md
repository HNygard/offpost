# PHP to Java Migration Mapping

This document provides a detailed mapping of PHP files to their Java equivalents.

## Directory Structure Mapping

| PHP Location | Java Location | Notes |
|--------------|---------------|-------|
| `organizer/src/class/` | `src/main/java/no/offpost/domain/` and `src/main/java/no/offpost/service/` | Split between entities and services |
| `organizer/src/api/` | `src/main/java/no/offpost/controller/` | REST controllers |
| `organizer/src/system-pages/` | `src/main/java/no/offpost/scheduler/` | Spring @Scheduled tasks |
| `organizer/src/tests/` | `src/test/java/no/offpost/unit/` | JUnit 5 tests |
| `organizer/src/e2e-tests/` | `src/test/java/no/offpost/e2e/` | Integration tests |
| `organizer/src/migrations/sql/` | `src/main/resources/db/migration/` | Flyway migrations |
| `organizer/src/*.php` (UI pages) | `src/main/resources/templates/` | Thymeleaf templates + controllers |

## Core Domain Classes

| PHP File | Java Package | Java Class(es) | Notes |
|----------|--------------|----------------|-------|
| `class/Thread.php` | `no.offpost.domain` | `Thread.java` | JPA entity with relationships |
| `class/ThreadEmail.php` | `no.offpost.domain` | `ThreadEmail.java` | JPA entity |
| `class/ThreadEmailAttachment.php` | `no.offpost.domain` | `ThreadEmailAttachment.java` | JPA entity |
| `class/Entity.php` | `no.offpost.domain` | `Entity.java` | JPA entity for public organizations |
| `class/Identity.php` | `no.offpost.domain` | `Identity.java` | JPA entity |
| `class/ImapFolderStatus.php` | `no.offpost.domain` | `ImapFolderStatus.java` | JPA entity |
| `class/ImapFolderLog.php` | `no.offpost.domain` | `ImapFolderLog.java` | JPA entity |
| `class/ThreadHistory.php` | `no.offpost.domain` | `ThreadHistory.java` | JPA entity |

## Enums

| PHP Constants | Java Package | Java Enum | Values |
|---------------|--------------|-----------|--------|
| `Thread::SENDING_STATUS_*` | `no.offpost.domain` | `Thread.SendingStatus` | STAGING, READY_FOR_SENDING, SENDING, SENT |
| `Thread::REQUEST_LAW_BASIS_*` | `no.offpost.domain` | `Thread.RequestLawBasis` | OFFENTLEGLOVA, OTHER |
| `Thread::REQUEST_FOLLOW_UP_PLAN_*` | `no.offpost.domain` | `Thread.RequestFollowUpPlan` | SPEEDY, SLOW |

## Database Layer

| PHP File | Java Package | Java Class(es) | Notes |
|----------|--------------|----------------|-------|
| `class/Database.php` | `org.springframework.boot.autoconfigure.jdbc` | (Spring Boot auto-config) | Replaced by Spring Data JPA configuration |
| `class/ThreadDatabaseOperations.php` | `no.offpost.service` | `ThreadService.java` | Business logic with repository injection |
| `class/ThreadStatusRepository.php` | `no.offpost.repository` | `ThreadRepository.java` with custom queries | JPA repository with @Query annotations |
| `class/ThreadStorageManager.php` | `no.offpost.service` | `ThreadStorageService.java` | Service layer, no longer singleton |

## Repository Interfaces (New in Java)

| PHP Context | Java Package | Java Interface | Extends |
|-------------|--------------|----------------|---------|
| Thread operations | `no.offpost.repository` | `ThreadRepository` | `JpaRepository<Thread, String>` |
| ThreadEmail operations | `no.offpost.repository` | `ThreadEmailRepository` | `JpaRepository<ThreadEmail, Long>` |
| Entity operations | `no.offpost.repository` | `EntityRepository` | `JpaRepository<Entity, Integer>` |
| Identity operations | `no.offpost.repository` | `IdentityRepository` | `JpaRepository<Identity, Long>` |
| ImapFolderStatus operations | `no.offpost.repository` | `ImapFolderStatusRepository` | `JpaRepository<ImapFolderStatus, Long>` |
| ImapFolderLog operations | `no.offpost.repository` | `ImapFolderLogRepository` | `JpaRepository<ImapFolderLog, Long>` |

## IMAP Integration

| PHP File | Java Package | Java Class(es) | Notes |
|----------|--------------|----------------|-------|
| `class/Imap/ImapWrapper.php` | `no.offpost.service.imap` | `ImapService.java` | Uses JavaMail API |
| `class/Imap/ImapConnection.php` | `no.offpost.service.imap` | `ImapConnectionManager.java` | Connection pooling with JavaMail |
| `class/Imap/ImapFolderManager.php` | `no.offpost.service.imap` | `ImapFolderService.java` | Folder operations |
| `class/Imap/ImapEmail.php` | `no.offpost.service.imap` | `ImapEmailParser.java` | Email parsing |
| `class/Imap/ImapEmailProcessor.php` | `no.offpost.service.imap` | `ImapEmailProcessor.java` | Email processing logic |
| `class/Imap/ImapAttachmentHandler.php` | `no.offpost.service.imap` | `ImapAttachmentHandler.java` | Attachment handling |
| `class/ThreadEmailMover.php` | `no.offpost.service.email` | `ThreadEmailMoverService.java` | Email moving operations |
| `class/ThreadFolderManager.php` | `no.offpost.service.email` | `ThreadFolderService.java` | Thread folder management |

## Email Processing Services

| PHP File | Java Package | Java Class(es) | Notes |
|----------|--------------|----------------|-------|
| `class/ThreadScheduledEmailReceiver.php` | `no.offpost.scheduler` | `EmailReceiverScheduler.java` | @Scheduled annotation |
| `class/ThreadScheduledEmailSender.php` | `no.offpost.scheduler` | `EmailSenderScheduler.java` | @Scheduled annotation |
| `class/ThreadScheduledFollowUpSender.php` | `no.offpost.scheduler` | `FollowUpScheduler.java` | @Scheduled annotation |
| `class/ThreadEmailDatabaseSaver.php` | `no.offpost.service.email` | `EmailDatabaseService.java` | EML parsing and saving |
| `class/ThreadEmailSending.php` | `no.offpost.service.email` | `EmailSendingService.java` | SendGrid integration |
| `class/ThreadEmailService.php` | `no.offpost.service.email` | `ThreadEmailService.java` | Email operations |
| `class/ThreadEmailHistory.php` | `no.offpost.service.email` | `EmailHistoryService.java` | Email history tracking |
| `class/ThreadEmailProcessingErrorManager.php` | `no.offpost.service.email` | `EmailErrorService.java` | Error handling |

## AI Integration

| PHP File | Java Package | Java Class(es) | Notes |
|----------|--------------|----------------|-------|
| `class/Ai/*.php` | `no.offpost.service.ai` | Various AI service classes | OpenAI integration |
| `class/SuggestedReplyGenerator.php` | `no.offpost.service.ai` | `ReplyGeneratorService.java` | GPT-based reply generation |
| `class/ThreadEmailClassifier.php` | `no.offpost.service.ai` | `EmailClassifierService.java` | Email classification |
| `class/Extraction/*.php` | `no.offpost.service.extraction` | Various extraction services | Content extraction (PDF, images, etc.) |

## REST API Controllers

| PHP File | Java Package | Java Class(es) | HTTP Methods |
|----------|--------------|----------------|--------------|
| `api/thread_email_extraction.php` | `no.offpost.controller` | `ExtractionController.java` | POST /api/extraction |
| Thread API operations | `no.offpost.controller` | `ThreadController.java` | GET, POST, PUT, DELETE /api/threads |
| Email operations | `no.offpost.controller` | `EmailController.java` | Various /api/emails endpoints |
| Entity operations | `no.offpost.controller` | `EntityController.java` | Various /api/entities endpoints |
| Admin operations | `no.offpost.controller` | `AdminController.java` | Various /api/admin endpoints |

## Scheduled Task Endpoints

| PHP File | Java Package | Java Class(es) | Trigger |
|----------|--------------|----------------|---------|
| `system-pages/scheduled-email-sending.php` | `no.offpost.scheduler` | `EmailSenderScheduler.java` | @Scheduled(cron = "...") |
| `system-pages/scheduled-email-receiver.php` | `no.offpost.scheduler` | `EmailReceiverScheduler.java` | @Scheduled(fixedDelay = ...) |
| `system-pages/scheduled-imap-handling.php` | `no.offpost.scheduler` | `ImapMaintenanceScheduler.java` | @Scheduled(cron = "...") |
| `system-pages/scheduled-email-extraction.php` | `no.offpost.scheduler` | `ExtractionScheduler.java` | @Scheduled(cron = "...") |
| `system-pages/scheduled-thread-follow-up.php` | `no.offpost.scheduler` | `FollowUpScheduler.java` | @Scheduled(cron = "...") |

## Authentication and Authorization

| PHP File | Java Package | Java Class(es) | Notes |
|----------|--------------|----------------|-------|
| `auth.php` | `no.offpost.config` | `SecurityConfig.java` | Spring Security configuration |
| `callback.php` | `no.offpost.controller` | `AuthController.java` | Auth0 callback handler |
| `class/ThreadAuthorization.php` | `no.offpost.service.security` | `ThreadAuthorizationService.java` | Permission checks |
| `username-password.php` | (Configuration) | `application.yml` or environment variables | Credentials configuration |

## Utility Classes

| PHP File | Java Package | Java Class(es) | Notes |
|----------|--------------|----------------|-------|
| `class/common.php` | `no.offpost.util` | Various utility classes | Common functions split into appropriate utilities |
| `class/ThreadUtils.php` | `no.offpost.util` | `ThreadUtils.java` | Thread-related utilities |
| `class/ThreadLabelFilter.php` | `no.offpost.util` | `LabelFilterUtil.java` | Label filtering logic |
| `class/random-profile.php` | `no.offpost.util` | `ProfileGenerator.java` | Random profile generation |
| `class/AdminNotificationService.php` | `no.offpost.service` | `AdminNotificationService.java` | Admin notifications |

## Web UI Pages (Option: Thymeleaf)

| PHP File | Java Controller | Template | Notes |
|----------|-----------------|----------|-------|
| `index.php` | `HomeController.java` | `templates/index.html` | Thread listing |
| `view-thread.php` | `ThreadViewController.java` | `templates/thread/view.html` | Thread details |
| `start-thread.php` | `ThreadController.java` | `templates/thread/create.html` | Thread creation form |
| `thread-reply.php` | `ThreadReplyController.java` | `templates/thread/reply.html` | Reply form |
| `entities.php` | `EntityController.java` | `templates/entity/list.html` | Entity management |
| `thread-bulk-actions.php` | `BulkActionsController.java` | `templates/thread/bulk.html` | Bulk operations |
| `update-identities.php` | `MaintenanceController.java` | `templates/maintenance/identities.html` | Identity updates |
| `update-imap.php` | `MaintenanceController.java` | `templates/maintenance/imap.html` | IMAP sync |
| `recent-activity.php` | `ActivityController.java` | `templates/activity/recent.html` | Activity log |
| `grant-thread-access.php` | `AccessController.java` | `templates/access/grant.html` | Access management |

## Test Files

| PHP Test File | Java Test Package | Java Test Class | Framework |
|---------------|-------------------|-----------------|-----------|
| `tests/ThreadScheduledEmailSenderTest.php` | `no.offpost.scheduler` | `EmailSenderSchedulerTest.java` | JUnit 5 + Mockito |
| `tests/ImapWrapperRetryTest.php` | `no.offpost.service.imap` | `ImapServiceRetryTest.java` | JUnit 5 + TestContainers |
| `tests/ThreadHistoryTest.php` | `no.offpost.service` | `ThreadHistoryServiceTest.java` | JUnit 5 |
| `tests/ThreadEmailHeaderProcessingTest.php` | `no.offpost.service.email` | `EmailHeaderProcessingTest.java` | JUnit 5 |
| `tests/Ai/OpenAiIntegrationTest.php` | `no.offpost.service.ai` | `OpenAiIntegrationTest.java` | JUnit 5 + WireMock |
| `e2e-tests/pages/*Test.php` | `no.offpost.e2e` | Various E2E test classes | JUnit 5 + Selenium/TestContainers |

## Configuration Files

| PHP/Current | Java/Spring Boot | Notes |
|-------------|------------------|-------|
| `composer.json` | `pom.xml` | Dependency management |
| `docker-compose.dev.yaml` | `docker-compose.yml` (updated) | Development environment |
| `docker-compose.prod.yaml` | `docker-compose.prod.yml` (updated) | Production environment |
| `.env` files | `application.yml` + environment variables | Configuration |
| PHP include paths | Spring component scanning | Automatic in Spring |

## Database Migrations

| PHP Migration | Flyway Migration | Notes |
|---------------|------------------|-------|
| `migrations/sql/00001-*.sql` | `db/migration/V001__*.sql` | Rename with Flyway convention |
| `migrations/sql/00002-*.sql` | `db/migration/V002__*.sql` | Sequential versioning |
| ... | ... | ... |
| `migrations/sql/00030-*.sql` | `db/migration/V030__*.sql` | All 30 migrations |
| `migrations/sql/99999-database-schema-after-migrations.sql` | (Generated by Flyway) | Schema documentation |

## PHP Function Mappings

### Common PHP → Java Equivalents

| PHP | Java | Notes |
|-----|------|-------|
| `require_once` | `import` | Java imports |
| `array()` or `[]` | `new ArrayList<>()` or `List.of()` | Collections |
| `json_encode()` | `ObjectMapper.writeValueAsString()` | Jackson library |
| `json_decode()` | `ObjectMapper.readValue()` | Jackson library |
| `file_get_contents()` | `Files.readString()` | Java NIO |
| `file_put_contents()` | `Files.writeString()` | Java NIO |
| `mt_rand()` | `Random.nextInt()` | Avoid in tests! |
| `time()` | `Instant.now().getEpochSecond()` | Avoid in tests! |
| `date()` | `LocalDateTime.format()` | Java Time API |
| `var_dump()` | `System.out.println()` or logger | Debugging |
| `die()` / `exit()` | `throw new RuntimeException()` | Error handling |
| `isset()` | `!= null` or `Optional.ofNullable()` | Null checking |
| `empty()` | `== null || isEmpty()` | Null/empty checking |
| `$_GET` / `$_POST` | `@RequestParam` / `@RequestBody` | Spring annotations |
| `$_SESSION` | `HttpSession` or JWT | Session management |
| PDO prepared statements | JPA/JDBC | ORM or prepared statements |

## Library Mappings

| PHP Library | Java Library | Purpose |
|-------------|--------------|---------|
| PHPMailer | JavaMail API | Email sending |
| Laminas Mail | JavaMail API | Email parsing |
| PDO | Spring Data JPA / JDBC | Database access |
| cURL | RestTemplate / WebClient | HTTP client |
| OpenSSL | Java Crypto API | Encryption |
| PHPUnit | JUnit 5 + Mockito | Testing |
| Composer | Maven | Dependency management |

## Naming Convention Changes

| PHP Convention | Java Convention | Example |
|----------------|-----------------|---------|
| snake_case variables | camelCase | `my_email` → `myEmail` |
| snake_case methods | camelCase | `get_thread_by_id()` → `getThreadById()` |
| PascalCase classes | PascalCase | Same: `ThreadEmail` |
| UPPER_CASE constants | UPPER_CASE | Same: `SENDING_STATUS_SENT` |
| snake_case DB columns | snake_case | Same (JPA @Column mapping) |
| `function` keyword | Method in class | All code in classes |

## Architecture Changes

| PHP Pattern | Java/Spring Pattern | Benefit |
|-------------|---------------------|---------|
| Singleton classes | Spring @Service beans | Automatic dependency injection |
| `require_once` dependencies | Constructor injection | Better testability |
| Global functions | Static utility methods | Better organization |
| Mixed SQL and logic | Repository pattern | Separation of concerns |
| Cron-triggered scripts | @Scheduled annotations | Application-managed scheduling |
| Session-based auth | JWT or OAuth2 | Stateless authentication |
| Mixed HTML/PHP | Thymeleaf or REST+SPA | Separation of presentation |

## Key Differences to Remember

1. **Type Safety**: Java is statically typed; all variables need explicit types
2. **Null Handling**: Java has `null`, consider using `Optional<T>` for nullable values
3. **Error Handling**: Use exceptions instead of return values for errors
4. **Collections**: Generic types required: `List<Thread>` not just `List`
5. **Package Structure**: Deep package hierarchy is standard in Java
6. **Testing**: Test classes in mirror package structure under `src/test/java`
7. **Configuration**: Properties in `application.yml` instead of `.env` files
8. **Dependency Injection**: Constructor injection preferred over field injection
9. **Annotations**: Heavy use of annotations for configuration (@Entity, @Service, @RestController)
10. **Build Process**: Maven handles compilation, packaging, and dependencies

## Implementation Priority

Recommended order for migration:

1. **Domain Entities** (Phase 2) - Start here
2. **Repository Layer** (Phase 3) - Enable data access
3. **Service Layer** (Parts of Phases 4-6) - Core business logic
4. **REST API** (Phase 7) - Enable frontend integration
5. **Scheduled Tasks** (Part of Phase 5) - Background processing
6. **Web UI** (Phase 8) - User interface
7. **Tests** (Phase 9) - Throughout all phases

## Getting Help

- For specific class ports, refer to the original PHP file for business logic
- Spring Boot documentation covers most framework questions
- JavaMail API documentation for IMAP operations
- JUnit 5 documentation for test porting
- Consult MIGRATION_PLAN_PHP_TO_JAVA.md for overall strategy
