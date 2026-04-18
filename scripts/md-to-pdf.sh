#!/usr/bin/env bash
#
# Convert a markdown file to PDF via pandoc + wkhtmltopdf.
# Usage: ./scripts/md-to-pdf.sh input.md [output.pdf]
#
set -euo pipefail

if [[ $# -lt 1 ]]; then
    echo "Usage: $0 input.md [output.pdf]" >&2; exit 1
fi

IN="$1"
OUT="${2:-${IN%.md}.pdf}"

if ! command -v pandoc &>/dev/null; then
    echo "pandoc not installed. sudo apt install pandoc wkhtmltopdf" >&2; exit 1
fi

pandoc "$IN" -o "$OUT" --from=gfm --pdf-engine=wkhtmltopdf
echo "Wrote $OUT"
