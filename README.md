# Filelink Usage

**Detect and track hard-coded file links in Drupal text fields to ensure accurate file usage.**

The `filelink_usage` module scans all text fields across your Drupal 10/11 site—including nodes, blocks, paragraphs, and other content entities—for hard-coded links to files in the public file system (e.g., `/sites/default/files/...`). If a matching managed file entity exists, the module adds proper entries to the `file_usage` table, just as if the file had been embedded via a Media or File field.

## Features

- 🔍 Scans all text fields (plain and formatted) for file links
- 🌐 Supports multilingual content (per-translation scanning)
- 🧠 Detects links in absolute (`https://yoursite.com/sites/default/files/...`) or relative format (`/sites/default/files/...`)
- 🗃️ Updates `file_usage` records to prevent accidental deletion of in-use files
- ⏱️ Daily cron-based cache and batch scanning
- 💾 Save hooks update usage in real time on content creation or update
- ⚙️ Configuration page to select which entity types and field types to include

## Use Cases

- Keep file usage counts accurate even when editors paste in direct links to files
- Prevent false orphaning of files used in WYSIWYG content
- Maintain cleaner file management and avoid accidental file deletions

## Status

This module is under active development. Contributions and feedback are welcome.
