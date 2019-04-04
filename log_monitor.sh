#!/bin/bash 
#
# Rotates the log files and sends alerts based on your configuration.
# Throw it in your crontab with whatever frequency you want. I suggest once per day, but you can do whatever. 
# 
CONFIG_LOCATION=/home/james/dev/php/zero/app/config/config.ini
NOTIFY_LOG_LVLS="error"
EMAIL_NOTIFY_TO='james@unisolu.com'

source $CONFIG_LOCATION; 

for LOGLVL in $NOTIFY_LOG_LVLS; do  
    echo "$LOGLVL"
    LOGFILE=$LOG_FILE"_"$LOGLVL".log"
    echo $LOGFILE; 
    if [[ -s $LOG_FILE"_"$LOGLVL".log" ]]; then 
        echo "Please review" | mail -s "$LOGLVL found on $SITE_NAME" -A $LOG_FILE"_"$LOGLVL".log" $EMAIL_NOTIFY_TO
        cat $LOGFILE >> $LOG_FILE"_"$LOGLVL"_"$(date +%Y-%m)".log"
        >$LOGFILE
        echo "Sent mail, truncated file."
    fi
done

echo "Done;"
