---
description: Upload files into Mnemon (docx, pptx, pdf, csv, png, jpg, webp, svg); their text becomes drawers
argument-hint: <file>... [--wing <slug> | --project <name>] [--room <slug>] [--note <text>]
allowed-tools: Bash(bash:*)
---

Upload these files into Mnemon: $ARGUMENTS

Run the upload script with the Bash tool, passing each file path and option
from the request above as its own argument, single-quoted so that spaces and
other characters in a name survive. Use the newest installed copy of the
plugin:

```bash
bash "$(ls -d ~/.claude/plugins/cache/*/mnemon/*/ | sort -V | tail -1)scripts/mnemon-upload.sh" [options] 'file1' 'file2'
```

- With no `--wing` or `--project`, the files go to the current repository's
  project wing, as session digests do. If the user named a wing or project in
  words, pass it as `--wing` or `--project`.
- Accepted types: docx, pptx, pdf, csv, png, jpg, webp, svg. Anything else is
  refused by the server; do not convert files without asking.
- Each image is described by the server's vision model, a paid call. If the
  user is uploading many images, say so before running.
- Do not read the files' contents into the conversation first; the server
  extracts them.

Relay the script's output to the user as it is, in a code block. For a file
that says "no text extracted" or "extraction failed", give the reason it
printed; a scanned PDF has no text layer, and Mnemon does not read those yet.
