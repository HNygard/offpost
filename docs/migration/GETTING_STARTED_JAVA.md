# Getting Started with Java Migration

This guide provides immediate next steps for starting the PHP to Java migration.

## Quick Start

### Prerequisites

- Java 21 LTS ([Download](https://adoptium.net/))
- Maven 3.9+ ([Download](https://maven.apache.org/download.cgi))
- Docker and Docker Compose
- IDE: IntelliJ IDEA (recommended) or Eclipse

### Step 1: Create Spring Boot Project

```bash
# Using Spring Initializr CLI (or visit https://start.spring.io/)
curl https://start.spring.io/starter.zip \
  -d dependencies=web,data-jpa,postgresql,security,mail,validation,actuator \
  -d groupId=no.offpost \
  -d artifactId=offpost \
  -d name=Offpost \
  -d description="Email thread management system for public entities" \
  -d packageName=no.offpost \
  -d javaVersion=21 \
  -d type=maven-project \
  -o offpost-java.zip

unzip offpost-java.zip -d offpost-java
cd offpost-java
```

### Step 2: Add Additional Dependencies

Edit `pom.xml` to add:

```xml
<dependencies>
    <!-- Already included from initializr -->
    <dependency>
        <groupId>org.springframework.boot</groupId>
        <artifactId>spring-boot-starter-web</artifactId>
    </dependency>
    <dependency>
        <groupId>org.springframework.boot</groupId>
        <artifactId>spring-boot-starter-data-jpa</artifactId>
    </dependency>
    <dependency>
        <groupId>org.postgresql</groupId>
        <artifactId>postgresql</artifactId>
        <scope>runtime</scope>
    </dependency>

    <!-- Additional dependencies -->
    <dependency>
        <groupId>com.sun.mail</groupId>
        <artifactId>jakarta.mail</artifactId>
        <version>2.0.1</version>
    </dependency>
    <dependency>
        <groupId>com.sendgrid</groupId>
        <artifactId>sendgrid-java</artifactId>
        <version>4.10.2</version>
    </dependency>
    <dependency>
        <groupId>org.flywaydb</groupId>
        <artifactId>flyway-core</artifactId>
    </dependency>
    <dependency>
        <groupId>org.projectlombok</groupId>
        <artifactId>lombok</artifactId>
        <optional>true</optional>
    </dependency>
    <dependency>
        <groupId>com.auth0</groupId>
        <artifactId>auth0-spring-security-api</artifactId>
        <version>1.5.3</version>
    </dependency>

    <!-- Testing -->
    <dependency>
        <groupId>org.springframework.boot</groupId>
        <artifactId>spring-boot-starter-test</artifactId>
        <scope>test</scope>
    </dependency>
    <dependency>
        <groupId>org.testcontainers</groupId>
        <artifactId>testcontainers</artifactId>
        <version>1.19.3</version>
        <scope>test</scope>
    </dependency>
    <dependency>
        <groupId>org.testcontainers</groupId>
        <artifactId>postgresql</artifactId>
        <version>1.19.3</version>
        <scope>test</scope>
    </dependency>
    <dependency>
        <groupId>com.icegreen</groupId>
        <artifactId>greenmail-junit5</artifactId>
        <version>2.0.1</version>
        <scope>test</scope>
    </dependency>
</dependencies>
```

### Step 3: Configure Application Properties

Create `src/main/resources/application.yml`:

```yaml
spring:
  application:
    name: Offpost
  datasource:
    url: jdbc:postgresql://localhost:5432/offpost
    username: offpost
    password: ${DB_PASSWORD}
  jpa:
    hibernate:
      ddl-auto: validate
    show-sql: false
    properties:
      hibernate:
        format_sql: true
  flyway:
    enabled: true
    locations: classpath:db/migration

# IMAP Configuration
imap:
  server: ${IMAP_SERVER:localhost}
  port: ${IMAP_PORT:993}
  email: ${IMAP_EMAIL}
  password: ${IMAP_PASSWORD}
  ssl: true

# SendGrid Configuration
sendgrid:
  api-key: ${SENDGRID_API_KEY}

# OpenAI Configuration
openai:
  api-key: ${OPENAI_API_KEY}

# Auth0 Configuration
auth0:
  domain: ${AUTH0_DOMAIN}
  client-id: ${AUTH0_CLIENT_ID}
  client-secret: ${AUTH0_CLIENT_SECRET}

# Management endpoints
management:
  endpoints:
    web:
      exposure:
        include: health,info,metrics
```

### Step 4: Create Basic Domain Model

Start with the core `Thread` entity:

```java
package no.offpost.domain;

import jakarta.persistence.*;
import lombok.Data;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;

/**
 * Core entity representing an email thread with a public entity.
 *
 * <p>A Thread represents a conversation between the system and a public entity,
 * with a unique profile (name and email) that is automatically generated for each thread.
 * The thread manages email lifecycle through status transitions: STAGING → READY_FOR_SENDING → SENDING → SENT.
 *
 * @author Offpost Team
 * @see ThreadEmail
 * @see SendingStatus
 */
@Entity
@Table(name = "threads")
@Data
public class Thread {

    /**
     * Unique identifier for the thread (UUID).
     */
    @Id
    private String id;

    /**
     * ID of the public entity this thread communicates with.
     */
    @Column(name = "entity_id")
    private Integer entityId;

    /**
     * Title or subject of the thread.
     */
    private String title;

    /**
     * Randomly generated name used as sender identity for this thread.
     */
    @Column(name = "my_name")
    private String myName;

    /**
     * Unique email address generated for this thread.
     */
    @Column(name = "my_email")
    private String myEmail;

    /**
     * Comma-separated labels for categorizing the thread.
     */
    private String labels;

    /**
     * Current sending status of the thread.
     */
    @Column(name = "sending_status")
    @Enumerated(EnumType.STRING)
    private SendingStatus sendingStatus;

    /**
     * Initial request text sent to the public entity.
     */
    @Column(name = "initial_request", columnDefinition = "TEXT")
    private String initialRequest;

    /**
     * Whether the thread has been archived.
     */
    private Boolean archived = false;

    /**
     * Whether the thread is publicly accessible.
     */
    @Column(name = "public")
    private Boolean publicThread = false;

    /**
     * Comment added when the email was sent.
     */
    @Column(name = "sent_comment")
    private String sentComment;

    /**
     * Legal basis for the request (e.g., Norwegian Freedom of Information Act).
     */
    @Column(name = "request_law_basis")
    @Enumerated(EnumType.STRING)
    private RequestLawBasis requestLawBasis;

    /**
     * Follow-up plan determining how aggressively to follow up (speedy or slow).
     */
    @Column(name = "request_follow_up_plan")
    @Enumerated(EnumType.STRING)
    private RequestFollowUpPlan requestFollowUpPlan;

    /**
     * Timestamp when the thread was created.
     */
    @Column(name = "created_at")
    private Instant createdAt;

    /**
     * Timestamp when the thread was last updated.
     */
    @Column(name = "updated_at")
    private Instant updatedAt;

    /**
     * All emails associated with this thread.
     */
    @OneToMany(mappedBy = "thread", cascade = CascadeType.ALL)
    private List<ThreadEmail> emails = new ArrayList<>();

    /**
     * JPA lifecycle callback executed before persisting a new thread.
     * Initializes ID, timestamps, and default sending status.
     */
    @PrePersist
    protected void onCreate() {
        if (id == null) {
            id = UUID.randomUUID().toString();
        }
        createdAt = Instant.now();
        updatedAt = Instant.now();
        if (sendingStatus == null) {
            sendingStatus = SendingStatus.STAGING;
        }
    }

    /**
     * JPA lifecycle callback executed before updating a thread.
     * Updates the updatedAt timestamp.
     */
    @PreUpdate
    protected void onUpdate() {
        updatedAt = Instant.now();
    }

    /**
     * Email sending status indicating the thread's lifecycle stage.
     */
    public enum SendingStatus {
        /** Thread is being prepared, not ready to send */
        STAGING,
        /** Thread is ready to be sent */
        READY_FOR_SENDING,
        /** Thread is currently being sent */
        SENDING,
        /** Thread has been successfully sent */
        SENT
    }

    /**
     * Legal basis for the information request.
     */
    public enum RequestLawBasis {
        /** Norwegian Freedom of Information Act (Offentleglova) */
        OFFENTLEGLOVA,
        /** Other legal basis */
        OTHER
    }

    /**
     * Follow-up strategy for the thread.
     */
    public enum RequestFollowUpPlan {
        /** Aggressive follow-up with shorter intervals */
        SPEEDY,
        /** Relaxed follow-up with longer intervals */
        SLOW
    }
}
```

### Step 5: Create Repository

```java
package no.offpost.repository;

import no.offpost.domain.Thread;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.stereotype.Repository;
import java.util.List;

/**
 * Repository interface for managing Thread entities.
 *
 * <p>Provides data access operations for email threads, including custom queries
 * for retrieving threads by various criteria such as archived status, entity association,
 * and sending status.
 *
 * @author Offpost Team
 * @see Thread
 */
@Repository
public interface ThreadRepository extends JpaRepository<Thread, String> {

    /**
     * Finds all non-archived threads ordered by last update time (most recent first).
     *
     * @return list of threads that are not archived, ordered by updatedAt descending
     */
    List<Thread> findByArchivedFalseOrderByUpdatedAtDesc();

    /**
     * Finds all non-archived threads for a specific entity.
     *
     * @param entityId the ID of the public entity
     * @return list of non-archived threads associated with the specified entity
     */
    List<Thread> findByEntityIdAndArchivedFalse(Integer entityId);

    /**
     * Finds all threads that are ready to be sent, ordered by creation time (oldest first).
     *
     * <p>This method is used by the scheduled email sender to retrieve threads
     * awaiting delivery to public entities.
     *
     * @return list of threads with READY_FOR_SENDING status, ordered by creation time ascending
     */
    @Query("SELECT t FROM Thread t WHERE t.sendingStatus = 'READY_FOR_SENDING' ORDER BY t.createdAt ASC")
    List<Thread> findReadyForSending();
}
```

### Step 6: Create Basic REST Controller

```java
package no.offpost.controller;

import no.offpost.domain.Thread;
import no.offpost.repository.ThreadRepository;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;
import lombok.RequiredArgsConstructor;
import java.util.List;

/**
 * REST controller for managing email threads.
 *
 * <p>Provides HTTP endpoints for CRUD operations on Thread entities.
 * All endpoints are prefixed with {@code /api/threads}.
 *
 * @author Offpost Team
 * @see Thread
 * @see ThreadRepository
 */
@RestController
@RequestMapping("/api/threads")
@RequiredArgsConstructor
public class ThreadController {

    private final ThreadRepository threadRepository;

    /**
     * Retrieves all non-archived threads.
     *
     * <p>Returns threads ordered by last update time, with most recently updated threads first.
     *
     * @return list of all non-archived threads
     */
    @GetMapping
    public List<Thread> getAllThreads() {
        return threadRepository.findByArchivedFalseOrderByUpdatedAtDesc();
    }

    /**
     * Retrieves a specific thread by its ID.
     *
     * @param id the unique identifier of the thread
     * @return HTTP 200 with the thread if found, HTTP 404 if not found
     */
    @GetMapping("/{id}")
    public ResponseEntity<Thread> getThread(@PathVariable String id) {
        return threadRepository.findById(id)
            .map(ResponseEntity::ok)
            .orElse(ResponseEntity.notFound().build());
    }

    /**
     * Creates a new thread.
     *
     * <p>The thread will be automatically assigned a UUID and timestamps.
     * Initial status will be set to STAGING if not specified.
     *
     * @param thread the thread to create
     * @return the created thread with generated ID and timestamps
     */
    @PostMapping
    public Thread createThread(@RequestBody Thread thread) {
        return threadRepository.save(thread);
    }

    /**
     * Updates an existing thread.
     *
     * <p>Replaces the thread with the given ID with the provided thread data.
     * The updatedAt timestamp will be automatically updated.
     *
     * @param id the unique identifier of the thread to update
     * @param thread the updated thread data
     * @return HTTP 200 with the updated thread if found, HTTP 404 if not found
     */
    @PutMapping("/{id}")
    public ResponseEntity<Thread> updateThread(
            @PathVariable String id,
            @RequestBody Thread thread) {
        if (!threadRepository.existsById(id)) {
            return ResponseEntity.notFound().build();
        }
        thread.setId(id);
        return ResponseEntity.ok(threadRepository.save(thread));
    }
}
```

### Step 7: Copy SQL Migrations

Copy the SQL migrations from PHP project:

```bash
# From project root
mkdir -p offpost-java/src/main/resources/db/migration
cp organizer/src/migrations/sql/*.sql offpost-java/src/main/resources/db/migration/

# Rename to Flyway format
cd offpost-java/src/main/resources/db/migration/
for file in *.sql; do
  # Extract number from filename and pad with zeros
  num=$(echo "$file" | grep -o '[0-9]*' | head -1)
  padded=$(printf "%03d" $num)
  # Rename to Flyway format: V###__description.sql
  newname=$(echo "$file" | sed "s/^[0-9]*-/V${padded}__/")
  mv "$file" "$newname"
done
```

### Step 8: Create Dockerfile

```dockerfile
# Build stage
FROM maven:3.9-eclipse-temurin-21 AS build
WORKDIR /app
COPY pom.xml .
COPY src ./src
RUN mvn clean package -DskipTests

# Runtime stage
FROM eclipse-temurin:21-jre
WORKDIR /app
COPY --from=build /app/target/*.jar app.jar
EXPOSE 8080
ENTRYPOINT ["java", "-jar", "app.jar"]
```

### Step 9: Create Docker Compose for Development

Create `docker-compose.yml` in Java project:

```yaml
version: '3.8'

services:
  postgres:
    image: postgres:15
    environment:
      POSTGRES_DB: offpost
      POSTGRES_USER: offpost
      POSTGRES_PASSWORD: offpost_dev
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data

  greenmail:
    image: greenmail/standalone:2.1.3
    ports:
      - "3025:3025"   # SMTP
      - "3143:3143"   # IMAP
      - "3993:3993"   # IMAPS
      - "8080:8080"   # Web UI
    environment:
      - GREENMAIL_OPTS=-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.users=greenmail-user:password@dev.offpost.no

  app:
    build: .
    ports:
      - "8081:8080"
    depends_on:
      - postgres
      - greenmail
    environment:
      - SPRING_DATASOURCE_URL=jdbc:postgresql://postgres:5432/offpost
      - SPRING_DATASOURCE_USERNAME=offpost
      - SPRING_DATASOURCE_PASSWORD=offpost_dev
      - IMAP_SERVER=greenmail
      - IMAP_PORT=3993
      - IMAP_EMAIL=greenmail-user@dev.offpost.no
      - IMAP_PASSWORD=password

volumes:
  postgres_data:
```

### Step 10: Test the Setup

```bash
# Build and run
mvn clean install
mvn spring-boot:run

# Or with Docker
docker-compose up --build

# Test the API
curl http://localhost:8080/api/threads
curl http://localhost:8080/actuator/health
```

## Next Steps

1. **Port Domain Models**: Complete all entity classes (Phase 2)
2. **Port Repositories**: Add remaining repository interfaces (Phase 3)
3. **IMAP Integration**: Implement email handling (Phase 4)
4. **Unit Tests**: Start porting tests alongside code
5. **Follow Migration Plan**: See MIGRATION_PLAN_PHP_TO_JAVA.md for detailed phases

## Common Issues

### Issue: Lombok not working in IDE
**Solution**: Install Lombok plugin and enable annotation processing

### Issue: Flyway migration fails
**Solution**: Check migration file naming (V###__description.sql) and ensure sequential versioning

### Issue: Cannot connect to PostgreSQL
**Solution**: Verify Docker container is running and port 5432 is not in use

## Resources

- [Spring Boot Documentation](https://spring.io/projects/spring-boot)
- [Spring Data JPA](https://spring.io/projects/spring-data-jpa)
- [JavaMail API](https://javaee.github.io/javamail/)
- [Flyway](https://flywaydb.org/documentation/)
- [Lombok](https://projectlombok.org/)

## Getting Help

- Review MIGRATION_PLAN_PHP_TO_JAVA.md for comprehensive migration strategy
- Check Spring Boot documentation for framework questions
- Refer to original PHP code for business logic reference
