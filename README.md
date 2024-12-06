# Production

Offpost (previously "email-engine" internally) is a service for sending emails to public entities.

## Architecture

Offpost is a PHP application running on a web server.

## Components
- organizer
        Main program.
        Provides a client, API and JSON storage for the email threads.
        Creates threads.
        Creates "identities" in Roundcube to let Roundcube know about the different threads.
        Organizer sorts email using IMAP folders directly on the server.
- Roundcube
        Webmail client using the IMAP server directly.
        The email threads are folders on the IMAP server.
        Roundcube is using MySQL for storage.
- Sendgrid
        Used to send emails.
        Copy to the IMAP server is done by Sendgrid.
- IMAP server
        Used to store email threads.
        The email threads are folders on the IMAP server.

-----

# Development

## Start

    docker-compose up
    
Open Roundcube (mail client) should be ready with database at:
- http://localhost:25080/
- User name and password for entering Roundcube is located in `username-password-imap.php`.

If new db, Create the Roundcube identities (not persistent in Roundcube db):
- http://localhost:25081/update-identities.php

Update IMAP into git repo and sort into folders in IMAP:
- http://localhost:25081/update-imap.php

## Using

Organizer (My PHP client):
- http://localhost:25081/

PHPMyAdmin:
- http://localhost:25082/

Test tools:
- http://localhost:25081/send-test-mail.php

## Sending email (starting new thread)

Start by generating a profile and start thread:

    php generate-profile.php

Get a link like:

- http://localhost:25081/start-thread.php?my_email=asmund.visnes%40offpost.no&my_name=%C3%85smund+Visnes

Open it an create a thread with title, label and connected to Entity.

Then sync identities with Roundcube:
- http://localhost:25081/update-identities.php

Open Roundcube and send from the identity.
- http://localhost:25080/

After, sync it to repo:
- http://localhost:25081/update-imap.php

Add details like
- sent = true
- status_type, status_text