echo "Scheduled sending of EMAIL"
curl "http://organizer-http-server/scheduled-email-sending" -k -s 2>&1
echo ""
echo "DONE"
echo ""