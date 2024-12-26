#!/bin/sh
set -e

# Apache gets grumpy about PID files pre-existing
rm -f /var/run/apache2/apache2.pid

# Start Apache as root (required) but run workers as www-data
exec apache2-foreground
