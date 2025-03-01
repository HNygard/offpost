echo "Scheduled sending of EMAIL"
curl cheat.sh/hello
echo "Real one:"
curl "http://organizer-http-server/scheduled-email-sending" -k -v 2>&1
echo ""
echo "DONE"
echo ""