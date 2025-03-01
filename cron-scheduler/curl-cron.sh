echo "$(date) Scheduled sending of EMAIL"
curl "http://organizer/scheduled-email-sending" -k -v 2>&1
echo ""
echo "DONE"
echo ""