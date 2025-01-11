# Production server

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
