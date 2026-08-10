#!/usr/bin/env bash
#
# Fährt alle Tests von rh-sync, die ohne WordPress auskommen.
#
#   bin/test.sh              alle
#   bin/test.sh schedule     nur die, deren Name "schedule" enthält
#
# Jede Datei unter tests/ ist ein eigenständiges PHP-Skript mit eigenen Attrappen für
# WordPress. Sie geben PASS/FAIL aus und enden mit 0 oder 1. Das reicht für diese Grösse und
# spart eine Testumgebung, die man erst hochfahren müsste.
#
# Was hier NICHT läuft, sind die Wege durch eine echte Datenbank. Dafür gibt es die
# Kommandozeile:
#
#   wp rh sync selftest --insecure --yes
#
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

FILTER="${1:-}"
FAILED=0
RAN=0

printf '\n  rh-sync, Tests ohne WordPress\n\n'

CHECKS=()

for file in tests/*.php; do
    [ -e "$file" ] || continue

    name="$(basename "$file" .php)"

    if [ -n "$FILTER" ] && [[ "$name" != *"$FILTER"* ]]; then
        continue
    fi

    # Konvention im Projekt: "-test" läuft allein, "-check" braucht eine echte Datenbank
    # und wird nur aufgelistet. Ein Check hier auszuführen scheitert an "undefined function
    # add_filter" und sagt damit nichts über den Code aus.
    if [[ "$name" == *-check ]]; then
        CHECKS+=("$file")
        continue
    fi

    RAN=$((RAN + 1))
    printf '  %-38s ' "$name"

    if output="$(php "$file" 2>&1)"; then
        printf 'ok\n'
    else
        printf 'FEHLGESCHLAGEN\n'
        FAILED=$((FAILED + 1))
        printf '%s\n' "$output" | sed 's/^/      /'
    fi
done

printf '\n'

if [ "${#CHECKS[@]}" -gt 0 ]; then
    printf '  Gegen die echte Datenbank, laufen hier nicht mit:\n'
    for file in "${CHECKS[@]}"; do
        printf '    ddev wp eval-file rh-sync/%s\n' "$file"
    done
    printf '\n'
fi

if [ "$RAN" -eq 0 ]; then
    printf '  Keine Testdatei gefunden%s.\n\n' "${FILTER:+ für \"$FILTER\"}"
    exit 1
fi

if [ "$FAILED" -gt 0 ]; then
    printf '  %d von %d fehlgeschlagen.\n\n' "$FAILED" "$RAN"
    exit 1
fi

printf '  %d Dateien, alles grün.\n\n' "$RAN"
