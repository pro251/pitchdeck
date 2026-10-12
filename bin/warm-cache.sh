#!/usr/bin/env bash
# Nightly picture warming for Pitch Deck.
#
# Every image the game shows is fetched over the network, and the network on
# the night is the wifi in the back room of a bar. This fills the cache ahead
# of time so a show mostly reads from disk -- and so a show with no network at
# all still produces decks.
#
# No wall-clock time to hit, so no at-eastern.sh wrapper and the crontab entry
# is plain UTC.
set -euo pipefail
cd "$(dirname "$0")/.."
exec php bin/warm-cache.php "${1:-60}"
