#!/bin/bash

# Usage check
if [ -z "$2" ]; then
  echo "Usage: $0 <mysql_container_id> <create_script_name.sql>"
  exit 1
fi

read -s -p "Enter MySQL root password: " MYSQL_ROOT_PASSWORD
echo ""

# define paths
SCRIPTS_DIR="./scripts"
VOLUME_DIR="./volumes/mysql/scripts"
CREATE_SCRIPT="$2"
REQUIRED_FILES=("${2/create/drop}" ${CREATE_SCRIPT})
CONTAINER_NAME="$1"

# create scripts directory in volumes if it doesn't exist
if [ ! -d "$VOLUME_DIR" ]; then
  echo "Creating $VOLUME_DIR..."
  sudo mkdir -p "$VOLUME_DIR"
fi

# verify files exist
for file in "${REQUIRED_FILES[@]}"; do
  if [ ! -f "$SCRIPTS_DIR/$file" ]; then
    echo "Error: File $SCRIPTS_DIR/$file does not exist."
    exit 1
  fi
done

# copy only the required scripts
echo "Copying SQL scripts to $VOLUME_DIR..."
for file in "${REQUIRED_FILES[@]}"; do
  sudo cp "$SCRIPTS_DIR/$file" "$VOLUME_DIR/"
done

# execute only the create script inside the container
echo "Executing $CREATE_SCRIPT inside container..."
if ! sudo docker exec -i "$CONTAINER_NAME" sh -c \
  "mysql -u root -p\"$MYSQL_ROOT_PASSWORD\" fossbilling < /var/lib/mysql/scripts/$CREATE_SCRIPT"; then
  echo "Error executing $CREATE_SCRIPT. Exiting."
  exit 1
fi

echo "${CREATE_SCRIPT} executed successfully."