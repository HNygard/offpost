---
name: run-tests-in-container
description: Run the offpost PHPUnit unit tests entirely inside the organizer Docker container, with no host PHP or composer. Use when host PHP/pdo_pgsql or host vendor/ dev dependencies are missing.
---

# Run unit tests in a container

From the project root:

    docker compose -f docker-compose.dev.yaml run --rm --entrypoint sh --user www-data \
      -e DB_HOST=postgres -e DB_PORT=5432 \
      -e DB_PASSWORD_FILE=/run/secrets/postgres_password \
      -v "$PWD/data:/data:ro" \
      organizer -c '[ -x vendor/bin/phpunit ] || composer install --no-interaction --no-progress; ./vendor/bin/phpunit --exclude-group integration tests/'

Each flag earns its place:
 - DB_*: tests/bootstrap.php defaults to the host-side view (127.0.0.1:25432 and
   the repo's secrets/ path); neither resolves in a container. It only falls back
   to these defaults when the variables are unset, so host runs stay unchanged.
 - data:/data: Entity::loadEntities() resolves entities_test.json relative to the
   source tree, i.e. /data/ in the container, while compose mounts the data dir
   at /organizer-data. Without this every test touching an entity dies with
   "Entities JSON file not found".
 - --user www-data: composer install writes dev dependencies into the mounted
   checkout (organizer/src/vendor), where they persist and host tools see them.
   www-data has UID 1000 like the host user, so the files are not owned by root.
 - --exclude-group integration: matches what CI runs for the unit suite.
