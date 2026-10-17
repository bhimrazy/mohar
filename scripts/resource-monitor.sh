#!/usr/bin/env bash
# Append a CPU / memory / disk snapshot every INTERVAL seconds (default 30) to LOG.
log="${1:-/tmp/resource-monitor.log}"
interval="${INTERVAL:-30}"
while true; do
    load=$(cut -d' ' -f1-3 /proc/loadavg)
    mem=$(free -m | awk '/Mem:/ {printf "%d/%dMB used, %dMB avail", $3, $2, $7}')
    disk=$(df -h /workspaces | awk 'NR==2 {printf "%s used of %s (%s free)", $3, $2, $4}')
    top=$(ps -eo pcpu,comm --sort=-pcpu | sed -n '2,4p' | awk '{printf "%s:%s%% ", $2, $1}')
    echo "$(date '+%H:%M:%S') load=[$load] mem=[$mem] disk=[$disk] top=[$top]" >> "$log"
    sleep "$interval"
done
