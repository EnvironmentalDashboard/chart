#!/bin/bash

if [ -f "db.config" ] || [ -f "/var/secret/db.config" ]
then
  if [ -f "/var/secret/db.config" ]
  then
    . /var/secret/db.config
  fi

  if [ -f "db.config" ]
  then
    . db.config
  fi
fi

docker run -dit --name chart -p 8000:80 --restart always -e "MYSQL_HOST=159.89.232.129" -e "MYSQL_DB=oberlin_environmentaldashboard" -e "MYSQL_USER=$user" -e "MYSQL_PASS=$pass" chart