set -e
echo "=============================="

echo "$(date) Scheduled sending of EMAIL"
curl "http://organizer/scheduled-email-sending" -s 2>&1
echo ""

echo "$(date) Scheduled receiving of EMAIL"
curl "http://organizer/scheduled-email-receiver" -s 2>&1
echo ""

echo "$(date) Scheduled extraction from email BODY"
curl "http://organizer/scheduled-email-extraction" -s 2>&1
echo ""

echo "$(date) Scheduled extraction from email ATTACHMENT PDF"
curl "http://organizer/scheduled-email-extraction?type=attachment_pdf" -s 2>&1
echo ""

echo "$(date) Scheduled extraction from email PROMPT SAKSNUMMER"
curl "http://organizer/scheduled-email-extraction?type=prompt_saksnummer" -s 2>&1
echo ""

echo "$(date) Scheduled extraction from email PROMPT EMAIL LATEST REPLY"
curl "http://organizer/scheduled-email-extraction?type=prompt_email_latest_reply" -s 2>&1
echo ""

echo "$(date) Scheduled thread FOLLOW-UP"
curl "http://organizer/scheduled-thread-follow-up" -s 2>&1
echo ""

echo "DONE"
echo ""
