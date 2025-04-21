#!/bin/bash
NEW_IP="$1" # e.g., 100.66.202.59
ROLE="$2"   # broker or webserver

# Update IP (example for Ubuntu)
sudo sed -i "s/100.66.202.58/$NEW_IP/" /etc/netplan/01-netcfg.yaml
sudo netplan apply

# Remove dev tools
sudo apt remove code git -y
sudo apt autoremove -y

# Install dependencies
sudo apt update
sudo apt install php php-mysql php-amqp composer rabbitmq-server mysql-server apache2 -y

if [ "$ROLE" = "broker" ]; then
    # Configure RabbitMQ
    sudo systemctl enable rabbitmq-server
    sudo systemctl start rabbitmq-server
    sudo rabbitmqctl add_vhost emailhost
    sudo rabbitmqctl set_permissions -p emailhost guest ".*" ".*" ".*"
    # Configure MySQL
    mysql -u root -e "CREATE DATABASE login; CREATE USER 'testUser'@'localhost' IDENTIFIED BY '12345'; GRANT ALL ON login.* TO 'testUser'@'localhost';"
fi

if [ "$ROLE" = "webserver" ]; then
    sudo systemctl enable apache2
    sudo systemctl start apache2
fi

echo "Environment setup for $ROLE at $NEW_IP"
