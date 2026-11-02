#!/usr/bin/env bash
# Detaches a sending worker from the web server process.
# Usage: spawn-worker.sh <php-binary> <send-worker.php> <user-id> <log-file>
# The secret payload arrives on file descriptor 3 and becomes the worker's stdin.

# Don't leak the web server's open sockets/files into the long-running worker.
for fd in /proc/$$/fd/*; do
    n=${fd##*/}
    if [[ $n =~ ^[0-9]+$ ]] && (( n > 3 )); then
        eval "exec ${n}>&-" 2>/dev/null
    fi
done

if command -v setsid >/dev/null 2>&1; then
    setsid "$1" "$2" "$3" 0<&3 3<&- >>"$4" 2>&1 &
else
    nohup "$1" "$2" "$3" 0<&3 3<&- >>"$4" 2>&1 &
fi
exit 0
