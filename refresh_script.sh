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
REQUIRED_FILES=("${2/create/drop}" $2)
CONTAINER_NAME="$1"

# verify files exist
for file in "${REQUIRED_FILES[@]}"; do
  if [ ! -f "$SCRIPTS_DIR/$file" ]; then
    echo "Error: File $SCRIPTS_DIR/$file does not exist."
    exit 1
  fi
done

# execute into the mysql container and run the scripts
for file in "${REQUIRED_FILES[@]}"; do
  echo "Executing $file inside container..."
  if ! sudo docker exec -i "$CONTAINER_NAME" sh -c \
    "mysql -u root -p\"$MYSQL_ROOT_PASSWORD\" fossbilling < /var/lib/mysql/scripts/$file"; then
    echo "Error executing $file. Exiting."
    exit 1
  fi
done

echo "SQL scripts executed successfully."