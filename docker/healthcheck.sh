#!/bin/sh
set -eu

command_line="$(tr '\0' ' ' < /proc/1/cmdline)"

case "$command_line" in
    *"artisan queue:work"*|*"artisan schedule:work"*)
        kill -0 1
        ;;
    *)
        curl -fsS http://127.0.0.1/up >/dev/null
        ;;
esac
