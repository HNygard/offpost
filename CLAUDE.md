# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# Offpost - Application to interact with public entities using email

Domain overview, architecture and components: see README.md.

## Rules

I pledge to follow the custom instructions.

- The current working directory is project root.
- When making changes, avoid unnecessary modifications that would complicate code review (e.g. formatting, variable renaming, etc.)
- After completing work, stage and commit the changes yourself, with a detailed message explaining what and why. Do not ask first, and do not just suggest the command.
- Do not push unless asked.
- When viewing git diffs, use git with "no-pager" parameter: git --no-pager diff
- Update relevant documentation in /docs when modifying features. Don't forget to update codebase documentation with changes.
- Keep README.md in sync with new capabilities
- Before and after any tool use, give me a confidence level (0-10) on how the tool use will help the project.

## Commands:

- Run unit tests using:
    ./organizer/src/vendor/bin/phpunit organizer/src/tests/

- Run e2e tests using:
    ./organizer/src/vendor/bin/phpunit organizer/src/e2e-tests/

- Run all tests (unit + e2e) using:
    ./organizer/src/vendor/bin/phpunit organizer/src/tests/ organizer/src/e2e-tests/

- The three commands above run on the host, which is how CI does it too (see
  .github/workflows/php.yml: docker provides only postgres/organizer, phpunit runs
  on the runner). They need PHP with pdo_pgsql and dev dependencies in
  organizer/src/vendor/ (composer install in organizer/src). The dev container
  mounts organizer/src including that vendor/, so it must exist before
  "docker compose up"; the entrypoint stops with instructions if it is missing.
  Production runs the image as built (code and vendor/ baked in, no mount).

- To run unit tests entirely in a container instead, with no host PHP or composer,
  use the `run-tests-in-container` skill (.claude/skills/run-tests-in-container/SKILL.md).

- If docker reports "permission denied ... /var/run/docker.sock" even though you
  are in the docker group, the shell session predates the group membership. Use
  `sg docker -c "<command>"` or start a new login session.

- Run system using:
    docker-compose -f docker-compose.dev.yaml up -d

- If migrations was added:
    See "restart" command below

- Complete reset of database (including recreating test data):
    ./reset-dev-database.sh

- Rebuild docker images:
    docker-compose -f docker-compose.dev.yaml down && docker-compose -f docker-compose.dev.yaml up --build -d

- Restart application/service:
    docker-compose -f docker-compose.dev.yaml down && docker-compose -f docker-compose.dev.yaml up -d

- Logs from organizer (the main container):
    docker-compose  -f docker-compose.dev.yaml logs organizer

- Check processes using:
    docker-compose -f docker-compose.dev.yaml ps

- Get into database:
    docker exec -it offpost_postgres_1 psql -U offpost -d offpost

- Reset Greenmail (clears emails and folders in mail server)
    docker-compose -f docker-compose.dev.yaml restart greenmail

## Code Structure

Per-feature docs live in `docs/` (np-api.md, thread-state.md, thread-analysis.md, thread-export-api.md, email-parsing.md).

## Database

All migrations are SQL scripts in `organizer/src/migrations/sql/`

To see the current schema for the database, read
`organizer/src/migrations/sql/99999-database-schema-after-migrations.sql`. This file is automatically updated when we make new migrations and run them.

## Security

### Sensitive Files

DO NOT read or modify:

-   .env files
-   \*_/config/secrets._
-   \*_/_.pem
-   Any file containing API keys, tokens, or credentials

### Security Practices

-   Never commit sensitive files
-   Use environment variables for secrets
-   Keep credentials out of logs and output


## Testing

Tests should be run after changes are made.

Rules:
- Assert message:
    - Give context to the assertion. But don't add messages telling the same as is asserted.
    - Asserting a size of array should always output the array in the error message. Use json_encode(..., JSON_PRETTY_PRINT).
- Structure tests as the following:
```php
// :: Setup

// :: Act

// :: Assert
```
- Tests must fail instead of skip:
    - Do NOT use markTestSkipped() or similar skip methods
    - If a test cannot run due to missing requirements, it should fail with a clear error message
    - This ensures test failures are visible and must be addressed
- Tests must be deterministic:
    - Do NOT use random values (mt_rand(), rand(), etc.)
    - Do NOT use time-based values (time(), microtime(), date(), etc.)
    - Use fixed, predictable values for test data
    - Tests should produce the same results every time they run
    - If uniqueness is needed, use sequential counters or fixed unique identifiers
- Assertions for deterministic outputs:
    - Use assertEquals instead of assertContains/assertStringContainsString when the output is deterministic
    - If the test has fixed inputs and produces predictable output, assert the exact expected value
    - Only use assertContains/assertStringContainsString when:
        - The order of elements may vary (e.g., database queries without ORDER BY)
        - The output includes non-deterministic data (e.g., timestamps, dynamic context)
        - Testing that specific content exists without requiring an exact match
    - Example: If a function generates a string from fixed dates and descriptions, use assertEquals with the full expected string
