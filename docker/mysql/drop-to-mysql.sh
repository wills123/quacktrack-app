#!/bin/sh
set -eu
# Preserve the official entrypoint's only gosu call without shipping an old Go runtime.
[ "$#" -ge 2 ] && [ "$1" = mysql ] || exit 1
shift
exec setpriv --reuid=mysql --regid=mysql --init-groups -- "$@"
