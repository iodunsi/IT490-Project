#!/bin/bash
ENV="$1" # qa or prod
VERSION="$2" # e.g., 1.0.0

if [ "$ENV" = "qa" ]; then
    BROKER_VM="100.66.202.61"
    WEB_VM="100.66.202.62"
elif [ "$ENV" = "prod" ]; then
    BROKER_VM="100.66.202.59"
    WEB_VM="100.66.202.60"
else
    echo "Specify env: qa or prod"
    exit 1
fi

VERSION_FILE="/home/paa39/services/version.txt"
URL="http://$WEB_VM/login.php"

# Check version
VERSION_CHECK=$(ssh paa39@$BROKER_VM "cat $VERSION_FILE")
if [ "$VERSION_CHECK" != "$VERSION" ]; then
    echo "Version mismatch: expected $VERSION, got $VERSION_CHECK"
    exit 1
fi

# Check web server
if curl -s -f "$URL" > /dev/null; then
    echo "Web server is up"
else
    echo "Web server is down"
    exit 1
fi

# Test login
LOGIN_RESPONSE=$(curl -s -d "username=testatesb&password=[REDACTED]" "$URL")
if echo "$LOGIN_RESPONSE" | grep -q "Welcome"; then
    echo "Login successful"
else
    echo "Login failed"
    exit 1
fi

# Manual tests
echo "Please test like, rate, comment, share at $URL"
echo "Check Mailtrap for welcome email"

echo "$ENV deployment verified"
