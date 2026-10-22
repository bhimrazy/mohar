#!/usr/bin/env bash
# Append a CPU / memory / disk snapshot every INTERVAL seconds (default 30) to LOG. Linux and macOS.
log="${1:-/tmp/resource-monitor.log}"
interval="${INTERVAL:-30}"
root="$(cd "$(dirname "$0")/.." && pwd)"
while true; do
    if [ -r /proc/loadavg ]; then
        load=$(cut -d' ' -f1-3 /proc/loadavg)
        mem=$(free -m | awk '/Mem:/ {printf "%d/%dMB used, %dMB avail", $3, $2, $7}')
        top=$(ps -eo pcpu,comm --sort=-pcpu | sed -n '2,4p' | awk '{printf "%s:%s%% ", $2, $1}')
    else
        load=$(sysctl -n vm.loadavg | tr -d '{}' | awk '{print $1, $2, $3}')
        total=$(($(sysctl -n hw.memsize) / 1048576))
        # Available = free + inactive + speculative + purgeable pages, as Activity Monitor counts it.
        avail=$(vm_stat | awk -v ps="$(sysctl -n hw.pagesize)" \
            '/Pages (free|inactive|speculative|purgeable)/ {gsub("\\.", "", $NF); n += $NF} END {printf "%d", n * ps / 1048576}')
        mem="$((total - avail))/${total}MB used, ${avail}MB avail"
        top=$(ps -Aro pcpu,comm | sed -n '2,4p' | awk '{n = split($2, p, "/"); printf "%s:%s%% ", p[n], $1}')
    fi
    disk=$(df -h "$root" | awk 'NR==2 {printf "%s used of %s (%s free)", $3, $2, $4}')
    echo "$(date '+%H:%M:%S') load=[$load] mem=[$mem] disk=[$disk] top=[$top]" >> "$log"
    sleep "$interval"
done
