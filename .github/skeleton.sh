#!/usr/bin/env bash
# Builds quire/skeleton, the starter project of new sites, from the application of this repository:
# the application is the skeleton, plus what only Quire's own development needs. So its files exist
# once, here, and what runs and is tested here is what new sites get.
#
#   .github/skeleton.sh <directory>     writes the skeleton there (an empty or missing directory)
#
# - The application's files, as Git has them (the directories and files below), but those only the
#   development needs (APPLICATION_ONLY).
# - In them, lines between "# >>> development only" and "# <<< development only" are left out, and a
#   line "# skeleton: <line>" becomes <line>.
# - skeleton/ has what only the skeleton has: its README, its workflow, and composer.json, whose values
#   "@application" are the application's (its requirements, scripts after installing…). Its
#   symfony.lock is the application's, without the recipes of development tools.
set -euo pipefail

out=${1:?Usage: .github/skeleton.sh <directory>}
root=$(cd "$(dirname "$0")/.." && pwd)

directories=(assets bin config frankenphp migrations public src templates translations)
files=(.dockerignore .editorconfig .env .env.dev .gitattributes .gitignore Dockerfile compose.override.yaml compose.prod.yaml compose.proxy.yaml compose.yaml extend.php importmap.php prod.env.dist)
# Only Quire's own development needs these.
application_only=(.env.test bin/phpunit config/packages/test/quire.yaml config/services_dev.yaml config/services_test.yaml)

if [ -e "$out" ] && [ -n "$(ls -A "$out")" ]; then
	echo "$out isn't empty." >&2
	exit 1
fi
mkdir -p "$out"

cd "$root"

# The application's files that Git has (nothing local, nothing ignored)
git ls-files -z -- "${directories[@]}" "${files[@]}" | while IFS= read -r -d '' path; do
	for only in "${application_only[@]}"; do
		if [ "$path" = "$only" ]; then continue 2; fi
	done
	mkdir -p "$out/$(dirname "$path")"
	if grep -q -e '^[[:space:]]*# >>> development only' -e '^[[:space:]]*# skeleton: ' "$path" 2>/dev/null; then
		awk '
			/^[[:space:]]*# >>> development only/ { skip = 1; next }
			/^[[:space:]]*# <<< development only/ { skip = 0; next }
			skip { next }
			{ if (match($0, /^[[:space:]]*# skeleton: /)) { print substr($0, 1, RSTART - 1) substr($0, RSTART + RLENGTH) } else { print } }
		' "$path" > "$out/$path"
		chmod --reference="$path" "$out/$path"
	else
		cp -p "$path" "$out/$path"
	fi
done

# What only the skeleton has
git ls-files -z -- skeleton | while IFS= read -r -d '' path; do
	target=${path#skeleton/}
	case "$target" in
		composer.json) continue ;;
	esac
	mkdir -p "$out/$(dirname "$target")"
	cp -p "$path" "$out/$target"
done

# composer.json: the skeleton's, with the application's values where it says "@application"; its
# symfony.lock: the application's recipes, but those of development tools. (PHP, which is everywhere
# this runs; objects decoded as objects, so {} stays {}.)
# shellcheck disable=SC2016 # PHP code: its $ are PHP's
php -r '
	[, $root, $out] = $argv;
	$read = static fn (string $path): object => json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
	$write = static fn (string $path, object $json) => file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
	$application = $read("$root/composer.json");
	$fill = static function (object $skeleton, object $application) use (&$fill): void {
		foreach ($skeleton as $key => $value) {
			if ("@application" === $value) {
				$skeleton->$key = $application->$key ?? throw new RuntimeException("The application has no \"$key\".");
			} elseif ($value instanceof stdClass) {
				$fill($value, $application->$key ?? new stdClass());
			}
		}
	};
	$skeleton = $read("$root/skeleton/composer.json");
	$fill($skeleton, $application);
	$write("$out/composer.json", $skeleton);
	$lock = $read("$root/symfony.lock");
	foreach (array_keys((array) ($application->{"require-dev"} ?? [])) as $development) {
		unset($lock->$development);
	}
	$write("$out/symfony.lock", $lock);
' "$root" "$out"

# Nothing of the development may be left.
if grep -rl -e '# >>> development only' -e '# <<< development only' "$out" >/dev/null 2>&1; then
	echo 'A "development only" block isn'\''t closed:' >&2
	grep -rl -e '# >>> development only' -e '# <<< development only' "$out" >&2
	exit 1
fi
