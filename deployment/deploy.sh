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

PACKAGE_DIR="/home/paa39/packages"
BROKER_DEPLOY_DIR="/home/paa39/services"
WEB_DEPLOY_DIR="/var/www/html"
PACKAGE_NAME="newsnexus-$VERSION.tar.gz"
VERSION_FILE="$BROKER_DEPLOY_DIR/version.txt"

# Ensure running on broker VM
if [ "$(hostname -I | awk '{print $1}')" != "$BROKER_VM" ]; then
    echo "Run this on $BROKER_VM"
    exit 1
fi

# Backup current deployment
BACKUP_DIR="/home/paa39/backups"
mkdir -p "$BACKUP_DIR"
tar -czf "$BACKUP_DIR/backup-$(date +%Y%m%d%H%M%S).tar.gz" -C "$BROKER_DEPLOY_DIR" .
ssh paa39@$WEB_VM "mkdir -p $BACKUP_DIR; tar -czf $BACKUP_DIR/backup-$(date +%Y%m%d%H%M%S).tar.gz -C $WEB_DEPLOY_DIR ."

# Extract package
tar -xzf "$PACKAGE_DIR/$PACKAGE_NAME" -C "/tmp"
mkdir -p "$BROKER_DEPLOY_DIR"
mv "/tmp/newsnexus-$VERSION"/DBRabbitMQServer.php "$BROKER_DEPLOY_DIR/"
mv "/tmp/newsnexus-$VERSION"/email-automated "$BROKER_DEPLOY_DIR/"
mv "/tmp/newsnexus-$VERSION"/testRabbitMQ.ini "$BROKER_DEPLOY_DIR/"
mv "/tmp/newsnexus-$VERSION"/emailRabbitMQ.ini "$BROKER_DEPLOY_DIR/"
mv "/tmp/newsnexus-$VERSION"/vendor "$BROKER_DEPLOY_DIR/"

# Deploy frontend to webserver
ssh paa39@$WEB_VM "mkdir -p $WEB_DEPLOY_DIR"
scp "/tmp/newsnexus-$VERSION"/*.php paa39@$WEB_VM:$WEB_DEPLOY_DIR/

# Update configs
cat > "$BROKER_DEPLOY_DIR/.env" << EOF
DB_HOST=$BROKER_VM
DB_USER=testUser
DB_PASSWORD=12345
DB_NAME=login
EMAIL_USER=newsnexus498@gmail.com
EMAIL_PASS=<app-password>
EOF

# Update permissions
chmod -R 755 "$BROKER_DEPLOY_DIR"
ssh paa39@$WEB_VM "chmod -R 755 $WEB_DEPLOY_DIR"

# Restart services
pkill -f DBRabbitMQServer.php
nohup php "$BROKER_DEPLOY_DIR/DBRabbitMQServer.php" >> "$BROKER_DEPLOY_DIR/server.log" 2>&1 &
pkill -f email_consumer.php
echo "*/5 * * * * /bin/bash $BROKER_DEPLOY_DIR/email-automated/runconsumer.sh" | crontab -
ssh paa39@$WEB_VM "sudo systemctl restart apache2"

# Record version
echo "$VERSION" > "$VERSION_FILE"

echo "Deployed $PACKAGE_NAME to $ENV"
