echo "$(date) Scheduled sending of EMAIL"
curl "http://organizer/scheduled-email-sending" -s 2>&1
echo ""
echo "$(date) Scheduled receiving of EMAIL"
curl "http://organizer/scheduled-email-receiver" -s 2>&1
echo ""
echo "DONE"
echo ""