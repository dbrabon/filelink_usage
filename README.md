# Filelink Usage

**Detect and track hard-coded file links in Drupal text fields to ensure accurate file usage.**

The `filelink_usage` module scans all text fields across your Drupal 10 or 11 site—including nodes, blocks, paragraphs, and other content entities—for hard-coded links to files in the public file system (e.g., `/sites/default/files/...`). If a matching managed file entity exists, the module adds proper entries to the `file_usage` table, just as if the file had been embedded via a Media or File field.

## Features

- 🔍 Scans `text_long` and `text_with_summary` fields for links to files
- 🧠 Detects links in absolute (`https://yoursite.com/sites/default/files/...`) or relative (`/sites/default/files/...`) format
- 🗃️ Updates `file_usage` records so referenced files are preserved
- ⏱️ Automatically scans during Drupal cron runs
- 💾 Save hooks keep file usage in sync on node create, update, and delete
- 💻 `drush filelink_usage:scan` command to run the scanner manually
- ⚙️ Configuration form with verbose logging enabled by default

## Use Cases

- Keep file usage counts accurate even when editors paste in direct links to files
- Prevent false orphaning of files used in WYSIWYG content
- Maintain cleaner file management and avoid accidental file deletions

## Status

This module is under active development. Contributions and feedback are welcome.

## License

This project is licensed under the [GNU General Public License v2 or later](LICENSE).
