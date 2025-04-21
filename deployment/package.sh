#!/bin/bash
VERSION="1.0.0"
PACKAGE_DIR="/home/paa39/packages"
REPO_DIR="/home/paa39/git/IT490-Project"
PACKAGE_NAME="newsnexus-$VERSION.tar.gz"

mkdir -p "$PACKAGE_DIR"
STAGE_DIR="/tmp/newsnexus-$VERSION"
mkdir -p "$STAGE_DIR"

cp -r "$REPO_DIR"/*.php "$STAGE_DIR/" # Frontend
cp -r "$REPO_DIR"/DBRabbitMQServer.php "$STAGE_DIR/"
cp -r "$REPO_DIR"/email-automated "$STAGE_DIR/"
cp -r "$REPO_DIR"/vendor "$STAGE_DIR/"
cp "$REPO_DIR"/testRabbitMQ.ini "$REPO_DIR"/emailRabbitMQ.ini "$STAGE_DIR/"

tar -czf "$PACKAGE_DIR/$PACKAGE_NAME" -C "/tmp" "newsnexus-$VERSION"
rm -rf "$STAGE_DIR"

echo "Created package: $PACKAGE_DIR/$PACKAGE_NAME"
