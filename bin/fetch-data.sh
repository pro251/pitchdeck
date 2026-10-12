#!/usr/bin/env bash
#
# Download the two corpora the dictionary is built from.
#
# Neither is in git: together they are about 36 MB of somebody else's data, and
# the 31 MB SQLite file built from them is reproducible in seven seconds. What
# the repository carries is the code that turns one into the other.
#
# Run this once after cloning, then bin/build-wordnet.php.
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p data

# WordNet 3.1, Princeton. The semantic graph: synsets, and the pointers
# between them that the drift walk follows.
if [ ! -f data/dict/data.noun ]; then
    echo "==> WordNet 3.1 (~20 MB)"
    curl -fsSL --retry 2 -o /tmp/wn31.tar.gz https://wordnetcode.princeton.edu/wn3.1.dict.tar.gz
    tar -xzf /tmp/wn31.tar.gz -C data/
    rm -f /tmp/wn31.tar.gz
else
    echo "==> WordNet already present"
fi

# OpenSubtitles word counts. This is the familiarity floor -- the difference
# between "wombat", which a room recognises, and "entoproct", which it does
# not. Subtitle counts rather than book counts on purpose: it measures the
# words people say.
if [ ! -f data/en_full.txt ]; then
    echo "==> OpenSubtitles frequency list (~20 MB)"
    curl -fsSL --retry 2 -o data/en_full.txt \
        https://raw.githubusercontent.com/hermitdave/FrequencyWords/master/content/2018/en/en_full.txt
else
    echo "==> Frequency list already present"
fi

echo
echo "Now run: php bin/build-wordnet.php"
