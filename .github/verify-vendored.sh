#!/bin/sh
# Verify vendored assets against the digests recorded alongside them.
#
# Dependabot cannot see a standalone committed file, so this is the automated half of the tracking
# described in static/Sortable.VERSION: it fails the build if the checked-in bundle no longer
# matches the digest that was reviewed, whether from a bad update or an unreviewed edit.
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
status=0

for marker in "$root"/static/*.VERSION; do
	[ -e "$marker" ] || continue

	file=$(sed -n 's/^file=//p' "$marker")
	expected=$(sed -n 's/^sha256=//p' "$marker")
	version=$(sed -n 's/^version=//p' "$marker")
	target="$(dirname -- "$marker")/$file"

	if [ ! -f "$target" ]; then
		echo "FAIL $file: recorded in $(basename -- "$marker") but missing from the tree"
		status=1
		continue
	fi

	actual=$(sha256sum "$target" | cut -d' ' -f1)
	if [ "$actual" != "$expected" ]; then
		echo "FAIL $file: digest mismatch"
		echo "  expected $expected (version $version)"
		echo "  actual   $actual"
		echo "  If this update is intentional, verify it against the upstream release and update"
		echo "  $(basename -- "$marker")."
		status=1
		continue
	fi

	# The bundle states its own version; catch a digest updated without the version, or vice versa.
	if ! head -c 200 "$target" | grep -qF "$version"; then
		echo "FAIL $file: does not declare version $version recorded in $(basename -- "$marker")"
		status=1
		continue
	fi

	echo "ok   $file $version ($expected)"
done

exit $status
