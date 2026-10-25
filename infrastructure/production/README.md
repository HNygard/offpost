# Production server

Production servers are pulling updates from Github and deploying them to the server.

## Setup

1. Make directories
    ```
    mkdir -p /opt/offpost/logs /opt/offpost/run
    ```
    `/opt/offpost/run` must be writable by the user running the deploy cronjob (see
    "Pausing scheduled tasks during deploy"). Create it before the first `up -d`, otherwise
    docker creates it owned by root.
2. Pull git repo
    ```
    cd /opt/offpost
    git clone git@github.com:hnygard/offpost.git app
    ```

3. Initial start of service
    ```
    cd /opt/offpost/app/
    docker compose -f docker-compose.prod.yaml up -d
    ```

4. Setup cronjob for pulling in new changes
    ```
    # See deploy-cronjob.sh for the cronjob command
    crontab -e
    ```

    The cronjob runs every 10 minutes. When there are new commits on `main` it rebuilds,
    pulls images and runs `docker compose up -d`, which recreates every container whose
    image or config changed.

## Pausing scheduled tasks during deploy

Scheduled tasks (`cron-scheduler/curl-cron.sh`, started every minute in the `cron`
container) are paused around `docker compose up -d`, so a restart does not cut them off:

1. Build and pull run first, with tasks still running.
2. The deploy creates `/opt/offpost/run/scheduled-tasks-paused`. Each `curl-cron.sh` run
   checks for it before every task and stops; a task already running finishes.
3. The deploy waits for the runs to stop: every run holds a shared `flock` on
   `/opt/offpost/run/curl-cron.lock` (mounted read-only into `cron` as `/offpost-run`), and
   the deploy takes an exclusive lock on it.
4. `up -d`, then wait until the organizer answers HTTP (Apache starts after migrations).
5. The pause file is removed.

Limits and failure handling:
- Waiting is capped at `PAUSE_WAIT_SECONDS` (600) in `deploy-cronjob.sh`. When it runs out,
  the deploy goes ahead and emails the admins (`organizer/src/bin/notify-admin.php`).
- The pause file is removed on any exit of the deploy (`trap ... EXIT`), and a deploy that
  was killed hard (SIGKILL, reboot) leaves a stale file that the next cronjob run removes,
  since it holds the deploy lock (`/tmp/offpost-deploy-cronjob.lock`). Tasks are never
  paused longer than one cronjob interval after a deploy dies.
- If `/opt/offpost/run` is not writable, the deploy logs a warning and deploys without
  pausing, as before.
- A failing step (`git pull`, a build, `pull`, `up -d`, or the organizer not answering within
  `STARTUP_WAIT_SECONDS` after `up -d`) stops the deploy, emails the admins with the step,
  and resumes scheduled tasks. If the organizer container is down, the alert is sent from a
  one-off `docker compose run` container instead. A failed deploy is not retried (HEAD is
  already pulled); pushing a fix deploys again. The organizer runs the code and `vendor/`
  baked into its image (nothing is mounted over `/php-frontend`), so after a failed build
  the previous version keeps running unchanged. `git fetch` failures are only
  logged, so network blips do not send an email every interval.
- Not paused: web requests, and the analysis worker (it calls the API from outside).

To pause by hand (e.g. for maintenance), create the pause file; remove it to resume. The
next deploy cronjob run also removes it.

## PHP version

Organizer's Dockerfile and PHP CI pin PHP 8.2.35, the 2026-10-21 security release
fixing CVE-2026-109618 and CVE-2026-109619. See the
[PHP Foundation advisory](https://thephp.foundation/blog/2026/10/21/todays-php-security-release-and-how-were-strengthening-php-security/).
Keep both pins updated together when applying future PHP security releases.

The changed base-image tag forces the next deploy to rebuild Organizer with the
patched runtime. Existing containers are not patched until rebuilt and recreated.
To apply and verify manually from the repository root:

```bash
docker compose -f docker-compose.prod.yaml build --pull organizer
docker compose -f docker-compose.prod.yaml up -d --no-deps organizer
docker compose -f docker-compose.prod.yaml exec organizer php -v
```

Confirm that the running container reports PHP 8.2.35. For development, use
`docker-compose.dev.yaml` instead. This pin covers Organizer only; Roundcube,
Adminer and PHPMyAdmin ship their own PHP runtimes and need separate image updates
and runtime verification.

## Postgres version

`docker-compose.prod.yaml` pins the Postgres image to a patch release (`postgres:15.19`).
With the floating `postgres:15` tag, any deploy that happened to pull a new patch release
recreated the database container, dropping connections from running scheduled tasks
(this happened on 2026-10-10 at 16:12). To upgrade, change the tag in a commit of its own;
the next deploy restarts Postgres once.

The pin reduces restarts but does not stop them: the official image still rebuilds an
existing tag for base OS updates, and a deploy pulls that rebuild. Pinning by digest
(`postgres:15.19@sha256:...`) would stop that too, at the cost of not getting those updates.

## Deployed version

`deploy-cronjob.sh` builds the organizer image with `GIT_SHA=$(git rev-parse HEAD)`, and the
Dockerfile writes it to `/php-frontend/git-sha.txt` in the image. The page header shows the
short SHA under "Logout", linked to the commit on GitHub. The error page shows it too, and
puts the full SHA at the top of the copyable error details so bug reports include it. If it
is missing, the image was built without `GIT_SHA` (e.g. by hand, or development).

## Code and composer packages in the image

The organizer image carries its code and `vendor/` (`composer install --no-dev` at build
time); production mounts only data, secrets and config, never code. Until 2026-10 the code
was bind-mounted with `vendor/` in an anonymous volume, which docker compose kept across
container recreation, so composer changes never reached production (after PR #172 the
running code still loaded PHPMailer 6.10.0). To check what the running code loads:

```bash
docker compose -f docker-compose.prod.yaml exec -T organizer php -r 'require "vendor/autoload.php"; echo PHPMailer\PHPMailer\PHPMailer::VERSION, "\n";'
```

Do not use `docker compose up -d --renew-anon-volumes` (`-V`) for all services: `mysql`
(Roundcube's database) has no configured volume, so its data is in an anonymous volume.
