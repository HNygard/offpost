echo "Scheduled sending of EMAIL"
curl "http://organizer-http-server/scheduled-email-sending" -k -v 2>&1
echo ""
echo "DONE"
echo ""