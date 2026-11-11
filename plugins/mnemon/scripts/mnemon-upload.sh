#!/usr/bin/env bash
# Mnemon upload: send files into memory.
#
# Usage: mnemon-upload.sh [--wing <slug> | --project <name>] [--room <slug>]
#                         [--note <text>] [--occurred-at <ISO-8601>] <file>...
#
# Each file goes to the instance's POST /api/documents with this device's
# credentials, the same ones the hooks use, and is bound by the same wing
# restrictions. Without --wing or --project it files under the project of the
# current directory's repository, as session digests do.
#
# Accepted: docx, pptx, pdf, csv, png, jpg, webp, svg. An image is described
# by the server's vision model, which is a paid call.
#
# Exit status: 0 when every file was stored and its text extracted, 1 when any
# was refused or had nothing extracted, 2 on a usage error.
#
# Plain bash, curl and jq, so it runs wherever the hooks run.
set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../hooks/lib/common.sh
. "$SCRIPT_DIR/../hooks/lib/common.sh"

usage() {
    cat <<'EOF'
Usage: mnemon-upload.sh [options] <file>...

  --wing <slug>          File into this wing.
  --project <name>       File into this project's wing (default: the
                         current directory's repository).
  --room <slug>          Room within the wing (default: documents).
  --note <text>          Context stored with each file; given to the vision
                         model with an image.
  --occurred-at <time>   When the files are from, as ISO-8601.
  -h, --help             This help.

Accepted: docx, pptx, pdf, csv, png, jpg, webp, svg.
EOF
}

wing="" project="" room="" note="" occurred_at=""
files=()
while [ "$#" -gt 0 ]; do
    case "$1" in
        --wing) [ "$#" -ge 2 ] || { usage >&2; exit 2; }; wing="$2"; shift 2 ;;
        --project) [ "$#" -ge 2 ] || { usage >&2; exit 2; }; project="$2"; shift 2 ;;
        --room) [ "$#" -ge 2 ] || { usage >&2; exit 2; }; room="$2"; shift 2 ;;
        --note) [ "$#" -ge 2 ] || { usage >&2; exit 2; }; note="$2"; shift 2 ;;
        --occurred-at) [ "$#" -ge 2 ] || { usage >&2; exit 2; }; occurred_at="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        --) shift; files+=("$@"); break ;;
        -*) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
        *) files+=("$1"); shift ;;
    esac
done

if [ "${#files[@]}" -eq 0 ]; then
    usage >&2
    exit 2
fi
if [ -n "$wing" ] && [ -n "$project" ]; then
    echo "Give --wing or --project, not both." >&2
    exit 2
fi
if [ -z "$wing" ] && [ -z "$project" ]; then
    project=$(mnemon_project_name "$PWD")
    if [ -z "$project" ]; then
        echo "Not in a git repository (or its project is in project_ignore): give --wing or --project." >&2
        exit 2
    fi
fi

for cmd in curl jq; do
    command -v "$cmd" >/dev/null 2>&1 || { echo "mnemon-upload needs $cmd." >&2; exit 2; }
done

pair=$(mnemon_token) || {
    echo "Mnemon is not set up on this device: no endpoint or token. Run the Mnemon setup first." >&2
    exit 2
}
endpoint="${pair%|*}"
token="${pair#*|}"
endpoint="${endpoint%/}"
url="${endpoint%/mcp}/api/documents"

# One upload. Args: <file> <token>. Echoes the body, then the HTTP status on
# its own last line; returns curl's exit status.
post() {
    local file="$1" bearer="$2" quoted
    # curl's -F reads `;` and `,` in a bare path as option separators. Quoted,
    # with `\` and `"` escaped, any name is taken literally.
    quoted=${file//\\/\\\\}
    quoted=${quoted//\"/\\\"}
    local args=(-sS -m 600 -w '\n%{http_code}' -X POST "$url"
        -H "Authorization: Bearer $bearer"
        -H "Accept: application/json"
        -F "file=@\"$quoted\"")
    # --form-string: a value beginning with @ or < is sent as text, never read
    # as a file name.
    [ -n "$wing" ] && args+=(--form-string "wing=$wing")
    [ -n "$project" ] && args+=(--form-string "project=$project")
    [ -n "$room" ] && args+=(--form-string "room=$room")
    [ -n "$note" ] && args+=(--form-string "note=$note")
    [ -n "$occurred_at" ] && args+=(--form-string "occurred_at=$occurred_at")
    curl "${args[@]}"
}

target="${wing:-project $project}"
echo "Uploading ${#files[@]} file(s) to ${endpoint%/mcp} ($target)"

failed=0
for file in "${files[@]}"; do
    name="${file##*/}"
    if [ ! -f "$file" ] || [ ! -r "$file" ]; then
        echo "  $name: not a readable file"
        failed=1
        continue
    fi

    rc=0
    raw=$(post "$file" "$token") || rc=$?
    status="${raw##*$'\n'}"

    # An expired access token: refresh once, as the hooks do, and retry.
    if [ "$rc" -eq 0 ] && [ "$status" = "401" ] && mnemon_refresh_available; then
        if fresh=$(mnemon_refresh "$token"); then
            token="$fresh"
            rc=0
            raw=$(post "$file" "$token") || rc=$?
            status="${raw##*$'\n'}"
        fi
    fi

    if [ "$rc" -ne 0 ]; then
        echo "  $name: could not reach the server (curl exit $rc)"
        failed=1
        continue
    fi

    body="${raw%$'\n'*}"
    if ! printf '%s' "$body" | jq -e . >/dev/null 2>&1; then
        case "$status" in
            413) echo "  $name: too large for the server or its proxy (HTTP 413)" ;;
            *) echo "  $name: unexpected response (HTTP $status)" ;;
        esac
        failed=1
        continue
    fi

    case "$status" in
        200|201)
            printf '%s' "$body" | jq -r --arg n "$name" '
                "  \($n): " + (
                    if .status == "processed" then
                        "\(.chunks) drawer\(if .chunks == 1 then "" else "s" end) in \(.wing)/\(.room)"
                        + (if .created then "" else " (already uploaded)" end)
                    elif .status == "no_text" then
                        "stored in \(.wing)/\(.room), no text extracted: \(.error)"
                    else
                        "stored in \(.wing)/\(.room), extraction failed: \(.error)"
                    end)'
            [ "$(printf '%s' "$body" | jq -r '.status')" = "processed" ] || failed=1
            ;;
        401)
            echo "  $name: token rejected or expired; re-run the Mnemon setup"
            failed=1
            ;;
        *)
            msg=$(printf '%s' "$body" | jq -r '.error // .message // "no message"')
            echo "  $name: refused (HTTP $status): $msg"
            failed=1
            ;;
    esac
done

exit "$failed"
