# Filelink Usage

**Detect and track hard-coded file links in Drupal content to ensure accurate file usage.**

The `filelink_usage` module scans HTML-formatted text fields across a Drupal 10/11 site – currently nodes, custom block content, taxonomy terms, and comments – for hard-coded links to files in the public or private file systems. If a link matches a managed file, the module will add an entry to Drupal’s `file_usage` table (just as if the file were referenced via a File or Media field) so that the file is not treated as orphaned. This helps maintain correct file usage counts and prevents accidental deletion of files that are still in use.

## Features

* 🗄️ **Scans multiple content entity types with HTML fields:** Supports nodes, custom blocks, taxonomy terms, and comments by default. The module automatically checks **all** relevant text fields (e.g. Body or custom WYSIWYG fields) in those entities for file links.
* 🔍 **Intelligent file link detection:** Uses an HTML scanner with regex to find `<img>` `src` or `<a>` `href` links pointing to `/sites/default/files/...` (public) or `/system/files/...` (private) paths. Supports both absolute URLs (including site domain) and relative file paths.
* 🗃️ **Updates file usage accurately:** When a managed file is found linked in content, a usage record for that file–entity combination is added via Drupal’s File Usage service. Each file is counted **only once per entity**, no matter how many times it appears, to avoid duplicate usage entries.
* ♻️ **No duplicate entries:** The scanning logic deduplicates records by entity and file. It ensures only one usage is recorded per file per content entity (removing any redundant or stale entries). Before adding a usage, the module even removes old usage records for that file–entity pair (including ones added by other modules) to prevent double-counting.
* ⏱️ **Scheduled scans for content changes:** When content is created or updated, it is marked for scanning. Drupal cron (or the provided Drush command) processes marked entities to keep file usage in sync. Hard-coded file links are removed from tracking when an entity is deleted.
* 📥 **Responsive to new file uploads:** If content contains a file link before the file exists, run the scanner after uploading the file (or let cron do it) and the usage will be recorded at that time. This ensures new files become tracked once a scan sees them.
* 📅 **Drupal Cron integration:** Cron performs a three‑phase routine. First it scans eligible entities and populates the module’s matches table. Next it records usage for any of those links that now correspond to managed files. Finally it removes file usage rows that no longer have a matching link. Only entities that have gone past the configured interval (or when no data exists) are processed each run.
* 🪝 **Immediate updates via hooks:** Presave hooks for nodes, custom blocks, taxonomy terms, comments, and paragraphs call the manager’s `manageUsage()` method so file usage reflects changes as soon as content is saved. Delete hooks for those entities remove stored matches and usage. File entity inserts also check for saved links referencing the new file.
* 💻 **Manual scanning capability:** Provides a Drush command `drush filelink_usage:scan` to run the manager's scan routine on demand. This performs the same process used by cron to rescan any entities that need it.
* 🗑️ **Usage cleanup on deletion:** When content or files are removed, the module cleans up their file usage records so nothing is left hanging. Deleting a node automatically removes all file usage entries that module had added for that node. Likewise, deleting a file clears any usage records for it that were tracked by this module.
* 🔄 **Targeted cache invalidation:** The module clears render caches only for the affected entities when file usage changes, rather than a full cache flush. For example, when a file’s usage is updated or removed, it invalidates that file’s cache tag (and the content entity’s, if needed) so that usage counts and file lists reflect changes immediately. This minimizes cache clearing impact while ensuring accuracy.
* ⚙️ **Configurable and developer-friendly:** Includes a settings page for configuration and debugging. Verbose logging (`verbose_logging` setting) of scanner activity can be enabled to help troubleshoot issues (off by default). All functionality is implemented via Drupal services (`filelink_usage.manager`, `filelink_usage.scanner`, etc.) using proper interfaces and dependency injection, making the code maintainable and extensible. The module also comes with comprehensive kernel tests covering scans, cron behavior, and edge cases to ensure reliability.

## Installation

1. **Download the module:** Install the `filelink_usage` module as you would any Drupal module. If the module is available via Composer, run `composer require drupal/filelink_usage`. Otherwise, download or copy the module’s code into your Drupal installation (e.g. under `modules/custom/filelink_usage`). The module requires PHP 8.1+ and is compatible with Drupal 10 and 11.
2. **Enable the module:** Enable **Filelink Usage** on your site either through the **Extend** admin UI or with Drush:

   ```bash
   drush en filelink_usage
   ```
3. **Run database updates:** If installing on an existing site, run the update script to set up the required database tables. For example:

   ```bash
   drush updb
   ```

   This will create the custom tables (`filelink_usage_matches` and `filelink_usage_scan_status`) used to store link matches and scan timestamps.
4. **Configure settings:** After enabling, visit **Configuration → Content Authoring → Filelink Usage** (or run `drush cedit filelink_usage.settings`) to review module settings as described below.

## Configuration

On the settings page, you can adjust how and when the scanner runs:

* **Cron Scan Frequency:** Choose how often Drupal Cron should trigger scanning of content. Options range from “Every cron run” to “Hourly”, “Daily”, up to “Yearly”. This determines the maximum age of content before it’s eligible for rescan. By default, the frequency is set to **Yearly**, meaning each content entity will be re-scanned at most once per year via cron (unless triggered by content edits).
* **Verbose Logging (`verbose_logging`):** Enable this checkbox to turn on detailed logging. When enabled, the module will write log entries for each file link detected and each usage addition/removal. This is useful for debugging if you suspect a file link isn’t being detected. It’s recommended to keep this off on production for performance.
* **Scan Batch Size (`scan_batch_size`):** Number of entity IDs loaded at a time when the scanner runs. Lower values reduce memory usage on very large sites. The default is **50**.
* **Full Scan Now:** Use the **Run full scan now** button on the settings form to immediately scan all configured content types. This marks everything for rescanning, runs the scanner, and refreshes file usage in one step.

These configurations let you balance performance with immediacy. For example, on a large site you might set a weekly or monthly cron scan interval to gradually cover all content, whereas a smaller site could scan every cron run. Verbose logging (`verbose_logging`) can be toggled as needed to trace the module’s actions.

## Usage

Once installed and configured, Filelink Usage works mostly behind the scenes to keep file usage up-to-date. Typical usage scenarios include:

* **Scanning triggered by cron:** After you create or update content that has formatted text fields, the entity is marked for scanning. Run Drupal cron (or the provided Drush command) to process marked content and update file usage. Editors can simply paste in file URLs and the next scan will pick them up.

* **Automatic cleanup on delete:** If a piece of content is deleted (for example, an editor deletes a node that contained a file link), Filelink Usage will remove the corresponding usage record for that file. This cleanup happens during the entity deletion process. The file’s usage count will immediately decrement, and if no other content references that file, Drupal will know it’s no longer in use. Likewise, if a file entity is deleted, the module will purge any of its saved link references and usage entries as needed.

* **Responsive to new files:** If content references a file that does not yet exist, simply upload the file and run the scanner (or wait for cron). The scan will detect the link and record usage once the file is present.

* **Manual scanning:** In addition to automatic scans, you can manually trigger scanning of content whenever needed. The module provides a Drush command for convenience:

  ```bash
  drush filelink_usage:scan
  ```

  Running this executes the same cron-style scan immediately, processing any entities marked for rescanning based on your configured frequency. Developers can invoke the logic in code as well:

  ```php
  \Drupal::service('filelink_usage.manager')->runCron();
  ```

  (This runs the manager's routine directly.)

### Usage Examples

* **Manual re-scan via Drush:** Installation now performs a full scan automatically. Use `drush filelink_usage:scan` if you later need to re-scan all content (such as after purging saved links). The manager will process all configured entity types using the cron scan routine and refresh file usage records.

* **Adding a new file that content references:** Suppose an editor created a page with an HTML link to a PDF file that didn’t exist yet. After the file is uploaded, run `drush filelink_usage:scan` (or wait for cron) and the three cron scans will first record the link, then register its usage, ensuring it’s tracked once the file exists.
* **Instant updates when editing content:** Saving a node, block, taxonomy term, comment, or paragraph triggers the `manageUsage()` hooks. They update or remove file usage immediately based on the links present, so you rarely have to wait for cron after an edit.

* **Forcing a full rescan:** If you suspect the tracking is out of sync or just want to refresh everything, click the **Run full scan now** button. This will immediately rescan all content and update usage counts. Be aware that on large sites a full scan can be intensive, so consider running it during off‑peak hours.

## Testing

Kernel and unit tests are located under `tests/src`. Install the development dependencies first and run PHPUnit using the included configuration file.

```bash
composer install --dev
vendor/bin/phpunit
```

These tests verify the scanner hooks and overall module behaviour.
## Cron Behavior

Drupal’s Cron plays a key role in ongoing maintenance of file link usage data. On each cron run, `filelink_usage` checks if any content needs to be rescanned based on the configured frequency:

The cron routine itself runs in three distinct scans:

1. **Populate matches** – eligible entities are rendered and file links are recorded in the `filelink_usage_matches` table without touching `file_usage`.
2. **Record usage** – any links from the matches table that now reference real files are added to `file_usage`.
3. **Remove stale usage** – usage rows that lack a corresponding match (because the link was removed) are deleted.

* If **no file link usages are recorded yet** (for example, right after module install or if the tables were manually cleared), the module treats this as a first-run and will scan all content entities unconditionally on the next cron. This ensures initial data is collected without waiting for the interval.
* Otherwise, the module will only scan content that has gone longer than the set interval since its last scan. The last scan time for each entity is tracked in an internal table (`filelink_usage_scan_status`). For example, if frequency is “Monthly”, each content entity will be scanned at most once per month via cron. Content edited in the meantime is simply marked for scanning, so cron (or a manual run) processes those changes later.
* Cron uses a rolling approach: it queries for entities (currently nodes, and in future possibly other types) whose last scan timestamp is older than the threshold and scans them. This spreads out the work so that not all content is scanned every time, which is important for performance on large sites. If the frequency is set to “Every cron run,” then cron will try to scan all content each run (not recommended for big sites).
* After each cron-triggered scan, the module updates the `last_scan` time (in configuration or status table) for those entities. It also updates the global `last_scan` setting timestamp. If cron finds some content with no prior scan record, it includes those as well.
* If at any point the module’s tracking tables are empty (no saved matches), cron will override the interval and perform a full scan. This design means if the tables are cleared or the module is newly enabled, you don’t have to manually instruct a full crawl — cron knows to do it once to repopulate the data.

In summary, regular cron runs are recommended to catch any file links that might have been missed or that become valid later (such as files that are uploaded after content was created). Adjust the **Cron scan frequency** setting to control this behavior. For most sites the default (yearly per node) is conservative; if you prefer more frequent checking, set a smaller interval like weekly or daily, with the understanding that each interval’s cron run will add some overhead by scanning older content.

## Cache Clearing Behavior

To make file usage updates visible immediately without hurting performance, Filelink Usage employs targeted cache invalidation:

* **File entity cache tags:** Whenever the module adds or removes a usage for a file, it invalidates that file’s cache tag (`file:{fid}`). This ensures that any cached displays of that file (for example, the file’s usage listing in admin or any block showing file info) get updated with the new usage count. By targeting the specific file, the module avoids a broad cache clear.
* **Content entity cache:** In many cases, when content is saved or deleted, Drupal’s core will invalidate that content’s cache. For instance, saving a node with changes (like new links) will invalidate the node’s own cache, and deleting a node clears its caches. Thus, the module usually doesn’t need to manually clear the content entity’s cache on save/delete – it’s handled by core. The important part is clearing the file’s cache so that the file no longer appears unused.
* **No full cache flush:** The module does **not** call a full `cache_rebuild()` or flush all render caches when updating file usage. Only the relevant cache tags are purged. This approach keeps the site responsive – only pages related to the affected file or content are regenerated. In contrast, without this, one might not see file usage changes until caches expired, or a full cache clear would be needed (which is expensive). Filelink Usage strikes a balance by making sure usage changes are reflected immediately where it matters, with minimal impact elsewhere.

If you are integrating with other systems that display file usage, note that you might want to use Drupal’s cache tags (e.g. attach the `file:{fid}` cache tag to any custom block that shows file usage info) so that it clears when Filelink Usage updates that file.

## Architecture and Internals

The module’s architecture is designed for clarity and extensibility. Key components include:

* **FileLinkUsageScanner** (`filelink_usage.scanner` service): This service encapsulates the logic for scanning content entities for file links. It can scan individual entities or batches of entities. The scanner works by rendering the entity (in “full” view mode) to generate its HTML, then using a regex to find any file URLs (`/sites/default/files/...` or `/system/files/...`) in the output. For each link found, it converts the URL to the corresponding Drupal stream URI (`public://` or `private://`) and looks up the file entity by that URI. The scanner then compares the set of files found in the content against what was previously recorded for that entity:

  * It retrieves prior matches from the `filelink_usage_matches` table for that entity (if any exist).
  * It calculates additions (new file links that weren’t previously tracked) and removals (old links no longer present).
  * It updates the Drupal file usage via the File Usage service: removing usage for files no longer referenced, and adding usage for newly referenced files. The module ensures each file–entity pair is only counted once, deduplicating at this stage.
  * All matches (file links) for the entity are stored in the `filelink_usage_matches` table, which acts as the module’s record. This table is keyed by **entity type**, **entity ID**, **field name**, and **file URI** to uniquely identify each usage instance. (Storing the field can help identify where in the entity the link was found, though all detected links count toward usage.) Internally, the module uses an upsert/merge operation to insert matches, so running the scanner multiple times will not duplicate records for the same link reference.
  * As part of the scan process, the scanner invalidates the cache tags for any files whose usage changed (as described in *Cache Clearing* above).

* **FileLinkUsageManager** (`filelink_usage.manager` service): This service coordinates higher-level operations and integration with Drupal’s lifecycle:

  * It implements the logic for **scheduled scans** on cron. The manager’s `runCron()` method checks the configured interval and uses the scanner service to scan content that needs updating. It also handles the special case of full scans when no data is present.
  * It handles **rescan scheduling** for entities. For example, if an entity is deleted or if a file link is removed from content, the manager can mark that entity for rescan (or clean up immediately) as appropriate. In the code, `reconcileEntityUsage()` is used to either mark an entity for future scanning or purge its records if it’s gone.
  * The manager also responds to **file insertions**. When a new file is added, the `addUsageForFile()` callback simply invalidates that file's cache tag. Hard-coded links are tracked only when a file entity exists at the time of scanning or saving content.
    * It handles **cleanup on deletions**. When a content entity (node, block, taxonomy term, or comment) is deleted, `cleanupNode()` (or a generalized cleanup for any entity type) is called via hook_entity_delete. This removes all `filelink_usage` records for that entity and informs the File Usage service to decrement usage counts for the files that were referenced. Deleting a managed file no longer alters usage; its prior references remain recorded.
  * The manager and scanner are designed with separation of concerns: the manager decides **when** to scan or clean up, and the scanner handles **how** to scan and update records. Both are registered as Drupal services, allowing other modules or custom code to call them if needed.

* **Database tables**: The module defines two custom tables for its operation:

  * `filelink_usage_matches`: Stores each detected file link match. Columns include the entity type, entity ID, field name, the file URI (or file ID in newer schema versions), and a timestamp of when it was recorded. This table is essentially the memory of what file links have been seen in what content. It prevents duplicate scanning of the same content and allows the module to compare past vs. present links.
  * `filelink_usage_scan_status`: Tracks the last scan time for each content entity. This table has a composite key of entity type and entity ID, with a timestamp of the last successful scan. It’s used to decide which entities need scanning on cron. The module updates this whenever an entity is scanned (either via cron or the on-save hook). If an entity is deleted, its entry is removed from both tables.

* **Drupal hooks integration**: The module uses Drupal’s entity hooks to tie into content life cycle:

  * `hook_entity_insert` and `hook_entity_update`: Implemented to trigger scanning when content is created or updated. Currently, this is wired for node entities, custom block content, taxonomy terms, and comments (and can be extended to any entity type with text fields). These hooks call the scanner service on the new or updated entity.
  * `hook_entity_delete`: Implemented to clean up usage when content is deleted. Removes all matches for that entity and updates file usage counts accordingly.
  * `hook_cron`: Implemented to run the manager’s scheduled scanning logic on cron. This triggers the process described in **Cron Behavior** above.

* **Services and DI**: All major logic is contained in PHP classes (Scanner, Manager, etc.) which are declared as services in the module’s `filelink_usage.services.yml`. These classes make use of Drupal’s dependency injection for things like the Entity Type Manager, Database connection, File Usage service (`file.usage`), Renderer service (to render entities to HTML), and Logger. This makes the code more testable (indeed kernel tests instantiate these services) and maintainable. For example, the scanner uses `FileUsageInterface` to register usage and `RendererInterface` to get entity HTML, rather than static calls, enabling easier updates and overrides if needed.

By structuring the module this way, it remains robust against Drupal core changes and can be extended. Developers could, for instance, swap in a different link-detection regex or support additional file URL patterns by altering the Scanner service, without affecting the rest of the system.

## Use Cases

This module is useful in a variety of situations to ensure file references are properly tracked:

* **WYSIWYG Pasted Files:** Editors often paste images or file links directly into rich-text areas (via the source or an external HTML). Filelink Usage ensures those files are not missed by Drupal’s native tracking. It keeps the file usage count accurate even if a file is not embedded via a Media reference.
* **Preventing Orphan Files:** Without this module, a file inserted by a direct URL might never get a usage entry, leading Drupal to consider it unused. This can result in inadvertent deletion of files that are actually in use. Filelink Usage prevents such “false orphaning” by catching those references and preserving the files.
* **Content Audits and Migrations:** When auditing content or migrating from another system, you might have many hard-coded file links. By running the scanner (especially via Drush), you can quickly gather all file references across the site and ensure the file usage table is up-to-date. This can be invaluable for cleanup projects or before running file deletion scripts – you’ll know which files are truly unused versus merely untracked.
* **Site Performance and Caching:** The targeted cache invalidation means you can rely on Drupal’s caching without worrying that file usage info is stale. For example, if you have a view listing unused files, the moment a file becomes referenced, its cache tag is invalidated and the view will update to remove that file from “unused” list on next render. This provides correct data to site admins with minimal performance cost.

## Troubleshooting

Having issues or unexpected results with Filelink Usage? Consider these tips:

* **File link not detected:** Ensure the link’s format matches the expected pattern. The module looks for URLs containing `/sites/default/files/` or `/system/files/`. Links to external domains or non-standard paths won’t be recognized. If your site uses a CDN or custom file path, you may need to adjust the pattern (which would require altering the Scanner’s code).
* **Usage count not updating:** If you added a file link in content and the file’s usage count didn’t increase, a few things to check:

  * Was the file present as a managed file at the time? If not, the module would wait until the file is actually added. Try uploading the file (making sure the path/filename matches) – the usage should update on upload.
  * Is Drupal’s cron running regularly? If the content was created *before* the module was enabled, it might not have been scanned yet. Run `drush filelink_usage:scan` manually or wait for cron to cover it.
  * Verify that the content’s text format allows the link. Sometimes Drupal may filter out or alter HTML links in certain formats. The module can only detect what is actually saved in the database after filtering.
  * Enable **Verbose logging** (`verbose_logging` setting) in the settings and save the content again. Then check **Reports → Recent log messages** for entries related to filelink\_usage. The log (when verbose_logging is on) will report what links were found and any usage actions taken, which can help pinpoint the issue.
* **Duplicate or stale entries:** In normal operation, the module cleans up usage as content changes. If you suspect there are duplicate entries or a file still marked as in use after its link was removed, run the **Full scan now** action or the Drush command to refresh all usage data.
* **Performance considerations:** Scanning a lot of HTML can be intensive. The scanner loads entities in batches (controlled by `scan_batch_size`) to keep memory usage predictable. If you have thousands of content items with large text fields, consider using a longer cron interval or scanning specific sections of content during off-peak hours. You can also run the Drush command with Drupal’s `--batch` or `--memory-limit` options if needed. Monitor the logs for any PHP memory or execution time issues if you attempt to scan everything at once on a huge site.
* **Integration with Media module:** Note that this module is complementary to using Media entities. It does not replace Media; rather, it covers scenarios where editors do **not** use Media and paste links directly. If you consistently use Media for files, you might not need this module. However, it can still serve as a safety net for any hard-coded usage. It won’t interfere with Media’s own usage tracking (it actually removes any redundant usage so that each file–content pair is only counted once).

By following these troubleshooting steps and best practices, you can ensure that Filelink Usage continues to operate smoothly and that your file usage data remains accurate.

## Status

**Project Status:** This module is under active development and is being used to manage file usage on Drupal 10/11 sites. The feature set has recently expanded (scanning multiple entity types (nodes, custom block content, taxonomy terms, comments), deduplication improvements, etc.), and it is considered stable for production use, although further testing and feedback are welcome. Always back up your database before widespread usage, and consider testing on a staging environment if you have a very large site.

Contributions in the form of bug reports, feature requests, and patches are appreciated. If you encounter an issue or have ideas for improvement, please open an issue in the project repository.

## License

This project is licensed under the [GNU General Public License, version 2 or later](LICENSE). All contributions will be released under the same license. Use, share, and modify freely, but provide attribution and share improvements. Happy coding!
