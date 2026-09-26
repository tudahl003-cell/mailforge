#!/usr/bin/env python3
"""Regex delimiter audit for PHP files.
Catches the GGUF mangle where a preg_* call ends with '...pattern' + quote
but no closing delimiter before the quote (e.g. preg_match('/Foo', $s)).
Usage: python3 audit.py <files...>
Exit 1 if any suspicious pattern found.
"""
import re, sys

# A preg_* call whose first arg is a quoted regex. Flag if the quoted string
# ends with a character that is NOT a plausible regex delimiter (/, #, ~, !)
# and is not an escaped char — i.e. the closing delimiter was dropped.
RE = re.compile(
    r"preg_(match|match_all|replace|replace_callback|split|grep)\s*\(\s*"
    r"(['\"])(.*?)\2"
)

BAD_ENDS = set()

def plausible_delimiter(s):
    if len(s) < 2:
        return False
    # last two chars: delimiter + optional modifier flags [a-z]+
    m = re.search(r"([/!#~])([imsxuADSJUX]*)(?:\[[^\]]*\])?$", s)
    return bool(m)

problems = []
for path in sys.argv[1:]:
    src = open(path, encoding="utf-8", errors="replace").read()
    for m in RE.finditer(src):
        pat = m.group(3)
        if not pat:
            continue
        line = src[:m.start()].count("\n") + 1
        # skip if it looks like a heredoc / constant
        if plausible_delimiter(pat):
            continue
        problems.append(f"{path}:{line}: suspicious regex (no closing delimiter?): {pat[:80]!r}")

if problems:
    print("REGEX AUDIT FAIL:")
    for p in problems:
        print("  " + p)
    sys.exit(1)
print("regex audit: clean")
