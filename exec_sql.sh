#!/bin/bash

# Usage check
if [ -z "$1" ]; then
  echo "Usage: $0 <mysql_container_id>"
  exit 1
fi

read -s -p "Enter MySQL root password: " MYSQL_ROOT_PASSWORD
echo ""

# define paths
SCRIPTS_DIR="./scripts"
VOLUME_DIR="./volumes/mysql/scripts"
REQUIRED_FILES=("create_client_events.sql" "create_events_table.sql")
CONTAINER_NAME="$1"

# create scripts directory in volumes if it doesn't exist
if [ ! -d "$VOLUME_DIR" ]; then
  echo "Creating $VOLUME_DIR..."
  sudo mkdir -p "$VOLUME_DIR"
fi

# copy sql scripts to volumes/mysql/scripts
echo "Copying SQL scripts to $VOLUME_DIR..."
sudo cp $SCRIPTS_DIR/*.sql $VOLUME_DIR/

# execute into the mysql container and run the scripts
for file in "${REQUIRED_FILES[@]}"; do
  echo "Executing $file inside container..."
  sudo docker exec -i "$CONTAINER_NAME" sh -c \
    "mysql -u root -p\"$MYSQL_ROOT_PASSWORD\" < /var/lib/mysql/scripts/$file"
done

echo "SQL scripts executed successfully."