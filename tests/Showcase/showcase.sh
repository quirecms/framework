#!/usr/bin/env bash
# Fills an empty site with content that shows every part of the default theme: the edition (this
# week's posts), the archive in months with its pager, a long read (contents, footnotes, a wide
# table, code, a quote, a task list), a long title, pages with sections and sub-pages, a privacy
# notice that lists the site's services, and an unknown address (404). Dates are relative to the day
# it runs, so the site looks current whenever it's filled.
#
# It uses only commands every site has (quire:content:create), so it runs on a site in production:
#
#   tests/Showcase/showcase.sh                         this checkout's development site
#   QUIRE_CONSOLE="ssh <host> sudo -n /usr/local/bin/quire-server site:console demo --" tests/Showcase/showcase.sh
#                                                      a site run by Quire Server, through its console
#
# Only for an empty site: it adds and never deletes. Images aren't added (no command uploads them yet).
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)

# Runs a site command; each argument is quoted for the shell on the other side of the SSH connection
console() {
	if [ -n "${QUIRE_CONSOLE:-}" ]; then
		# shellcheck disable=SC2086 # QUIRE_CONSOLE is a command line, split on purpose
		set -- $QUIRE_CONSOLE "$(printf '%q ' "$@")"
		"$@"
	else
		(cd "$here/../.." && docker compose exec -T php bin/console "$@")
	fi
}

# Creates an item and prints its ID
create() {
	console quire:content:create "$@" | tr -d '\n' | sed -n 's/.*with ID[[:space:]]*\([0-9a-f-]\{36\}\).*/\1/p'
}

listed=$(console quire:content:list --limit=1)
if printf '%s' "$listed" | grep -q '[0-9a-f]\{8\}-[0-9a-f]\{4\}-'; then
	echo 'The site has content already. The showcase is only added to an empty site.' >&2
	exit 1
fi

echo '== Posts: this week (the edition), then earlier months (the archive and its pager)'
create post 'The draw for the spring cup is made' --body "$(cat "$here/content/long-read.md")" \
	--excerpt 'Sixteen clubs, four groups and one weekend in April: how the draw was made, and what changes from last year.' \
	--published-at='-1 day' >/dev/null
create post 'Harbour Arena FC open with a win on Friday evening' --excerpt 'Three goals in the second half, in front of a full stand.' \
	--body 'The holders started the cup as they ended the last one: patient, then quick. Three goals after the break settled it.' --published-at='-2 days' >/dev/null
create post 'Referees for the first round' --excerpt 'Twelve referees, six of them new to the cup.' \
	--body 'Twelve referees take the first round, six of them for the first time. Each has two matches on Saturday.' --published-at='-3 days' >/dev/null
create post 'What a long title looks like when a newsroom writes one for a match that went to penalties after extra time on a wet Sunday afternoon' \
	--excerpt 'A title of a hundred and fifty characters, to see how every list and page wraps it.' \
	--body 'Some titles are long. This one is, on purpose: every list, card and page has to wrap it without breaking a word.' --published-at='-4 days' >/dev/null

months=('-9 days' '-16 days' '-23 days' '-5 weeks' '-6 weeks' '-7 weeks' '-9 weeks' '-11 weeks' '-13 weeks' '-15 weeks' '-17 weeks' '-19 weeks')
n=0
for when in "${months[@]}"; do
	n=$((n + 1))
	create post "Club news, part $n" --excerpt "Round-up number $n: transfers, training and the youth teams." \
		--body "What happened at the clubs this time: who joined, who trained where, and how the youth teams did. Part $n of the round-up." \
		--published-at="$when" >/dev/null
done

echo '== Pages: sections with sub-pages, and the privacy notice'
create page 'About' --body 'News, draws and results of the spring cup, written by the clubs'"'"' volunteers.' --published-at='-20 weeks' >/dev/null
venues=$(create page 'Venues' --body 'The cup is played at two venues, both reachable by tram.' --published-at='-20 weeks')
create page 'Harbour Arena' --parent "$venues" --body 'Two pitches by the water, with a stand for 800. Tram 4 to Harbour.' --published-at='-20 weeks' >/dev/null
create page 'Northside Hall' --parent "$venues" --body 'An indoor hall with one pitch, used when it rains. Tram 7 to Northside.' --published-at='-20 weeks' >/dev/null
rules=$(create page 'Rules' --body "$(printf '## Matches\n\nTwo halves of twenty minutes.\n\n## Squads\n\nFourteen players each.\n\n## Ties\n\nGoals scored, then penalties.')" --published-at='-20 weeks')
create page 'Penalty shoot-outs' --parent "$rules" --body 'Five penalties each, then one each until a miss.' --published-at='-20 weeks' >/dev/null
create page 'Privacy notice' --body "$(printf 'What this site keeps, and which services of other companies it may use, with your consent:\n\n[[privacy_services]]')" --published-at='-20 weeks' >/dev/null

echo 'Done. Also look at an unknown address for the 404 page, e.g. /no-such-page.'
