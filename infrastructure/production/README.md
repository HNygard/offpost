# Production server

Production servers are pulling updates from Github and deploying them to the server.

## Setup

1. Make directories
    ```
    mkdir -p /opt/offpost/logs
    ```
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

Every run of `deploy-cronjob.sh` writes `git rev-parse HEAD` to `organizer/src/git-sha.txt`
(gitignored). The page header shows the short SHA under "Logout", linked to the commit on
GitHub. The error page shows it too, and puts the full SHA at the top of the copyable error
details so bug reports include it. If it is missing, the cronjob has not run since the file was introduced.
