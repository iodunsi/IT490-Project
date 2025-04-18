   #!/bin/bash
   CONSUMER="/home/paa39/git/IT490-Project/email-automated/email_consumer.php"
   # Log file for output
   LOG="/home/paa39/git/IT490-Project/email-automated/email_consumer.log"
 if ! pgrep -f "$CONSUMER" > /dev/null; then
       echo "$(date): Starting email_consumer.php" >> "$LOG"
       /usr/bin/php "$CONSUMER" >> "$LOG" 2>&1 &
   fi