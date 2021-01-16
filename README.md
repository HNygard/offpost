
# Start

    sudo docker-compose up
    
Open Roundcube (mail client) should be ready with database at:
- http://localhost:25080/

If new db, Create the Roundcube identities (not persistent in Roundcube db):
- http://localhost:25081/update-identities.php

Update IMAP into git repo and sort into folders in IMAP:
- http://localhost:25081/update-imap.php

# Using

Organizer (My PHP client):
- http://localhost:25081/

PHPMyAdmin:
- http://localhost:25082/

Test tools:
- http://localhost:25081/send-test-mail.php