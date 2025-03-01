# Docker container that pings the main app over HTTP
FROM ubuntu:latest

RUN apt-get update
RUN apt-get -y install cron curl

ADD curl-cron.sh /root/curl-cron.sh
RUN chmod +x /root/curl-cron.sh

# "This works because docker always treats the stdout from process 1 as the docker log stream.", https://gist.github.com/mowings/59790ae930accef486bfb9a417e9d446
RUN crontab -l | { cat; echo "* * * * * bash /root/curl-cron.sh > /proc/1/fd/1 2>&1"; } | crontab -

# Run cron in foreground. This in itself will not output anything.
CMD cron -f