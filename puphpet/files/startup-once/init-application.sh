#!/bin/bash

echo "Loading databases..."
mysql -u dbuser -pdbuser gqm_fixtures < /var/www/gqm.vm/dev/fixtures.sql
mysql -u dbuser -pdbuser gqm < /var/www/gqm.vm/dev/fixtures.sql
