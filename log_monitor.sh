#!/bin/bash 
#
# Rotates the log files and sends alerts based on your configuration.
# Throw it in your crontab with whatever frequency you want. I suggest once per day, but you can do whatever. 
# 
# This was a one-shot for a relatively unimportant thing.. not sure I would go 
# with this approach again. 
# 
CONFIG_LOCATION=
if [[ -z $CONFIG_LOCATION ]]; then
    echo "you must define CONFIG_LOCATION"; 
fi; 

# both set in above ini file... 
# if you have [categories] it complains, but it still works. 
#NOTIFY_LOG_LVLS="error"
#EMAIL_NOTIFY_TO='james@unisolu.com'

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
