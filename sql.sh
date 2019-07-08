#!/bin/bash

. db.config
mysqldump -h 159.89.232.129 --port $port -u $user -p$pass oberlin_environmentaldashboard time_series > chart.sql