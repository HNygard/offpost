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

## Deployed version

Every run of `deploy-cronjob.sh` writes `git rev-parse HEAD` to `organizer/src/git-sha.txt`
(gitignored). The page header shows the short SHA under "Logout", linked to the commit on
GitHub. The error page shows it too, and puts the full SHA at the top of the copyable error
details so bug reports include it. If it is missing, the cronjob has not run since the file was introduced.
