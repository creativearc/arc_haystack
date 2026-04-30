# ARC Haystack

**Version:** 1.6.0
**Author:** CreativeArc
**Compatibility:** ExpressionEngine 6.x+

## Overview

ARC Haystack is a development and debugging addon for ExpressionEngine that provides detailed tracking of which templates, partials, and variables are used to generate any given page. It helps developers understand template dependencies, debug complex template structures, and audit template usage patterns.

## Features

- **Template Tracking** - Identify all templates (main, embeds, layouts) used to render a page
- **Nested Layout Chain Detection** - Resolve parent layouts recursively (layout -> parent layout -> ...)
- **Partial/Snippet Detection** - Track which snippets are utilized
- **Global Variable Tracking** - List all global variables referenced
- **Template Usage Grid** - See every template, partial, and variable in your site alongside its last-seen activity status
- **Automatic Logging** - Extension hooks log template activity without requiring a tag in every template
- **Database Logging** - Optionally log template usage for later analysis
- **Control Panel Interface** - Browse and analyze logged page views with sortable columns
- **Pages/Structure Support** - Works with Pages module URL mappings

---

## Installation

1. Copy the `arc_haystack` folder to `/system/user/addons/`
2. In the ExpressionEngine control panel, go to **Add-Ons**
3. Find "ARC Haystack" and click **Install**

---

## Template Tags

### {exp:arc_haystack:templates}

Lists all templates, partials, and variables used to generate the current page.

#### Parameters

| Parameter | Values | Default | Description |
|-----------|--------|---------|-------------|
| `include` | `templates`, `partials`, `variables`, `all` | `all` | Filter what types to include in output |
| `format` | `list`, `json`, `comma` | — | Output format when used as a single tag |

#### Tag Pair Usage

```
{exp:arc_haystack:templates include="all"}
    <li>{template_path} ({template_type})</li>
{/exp:arc_haystack:templates}
```

#### Variables (Tag Pair)

| Variable | Description |
|----------|-------------|
| `{template_group}` | Template group name |
| `{template_name}` | Template name |
| `{template_type}` | Type: `main`, `embed`, `layout`, `partial`, `variable`, `called_from` |
| `{template_path}` | Full path (group/name) |
| `{called_from}` | Template where this tag was placed |
| `{count}` | Current iteration count |
| `{total_results}` | Total number of items |

#### Single Tag Usage

Outputs an unordered HTML list:

```
{exp:arc_haystack:templates format="list"}
```

Outputs a JSON array:

```
{exp:arc_haystack:templates format="json"}
```

Outputs comma-separated values:

```
{exp:arc_haystack:templates format="comma"}
```

#### Examples

**Display all templates in a debug panel:**

```
<div class="debug-templates">
    <h3>Templates Used on This Page</h3>
    <ul>
    {exp:arc_haystack:templates include="templates"}
        <li>
            <strong>{template_path}</strong>
            <span class="type">({template_type})</span>
        </li>
    {/exp:arc_haystack:templates}
    </ul>
</div>
```

**Get JSON for JavaScript consumption:**

```
<script>
    var templatesUsed = {exp:arc_haystack:templates format="json"};
    console.log('Templates on this page:', templatesUsed);
</script>
```

**Show only partials/snippets:**

```
{exp:arc_haystack:templates include="partials"}
    Partial: {template_name}<br>
{/exp:arc_haystack:templates}
```

---

### {exp:arc_haystack:log}

Logs the current page's template usage to the database for later analysis. This tag produces no output.

#### Parameters

None.

#### What Gets Logged

- Current template path
- Page URL
- All embeds used (including nested embeds)
- All partials/snippets used
- All global variables used
- Main template and detected layout
- Which template contained the log tag
- Timestamp

Note: `layout_template` stores the first detected layout path for the request. ARC Haystack resolves the full nested layout chain when rendering control panel views and status activity.

#### Usage

Place this tag at the end of your layout template or main template for best results:

```
{!-- At the bottom of your layout template --}
{exp:arc_haystack:log}
```

**Best Practice:** Place in a single location (like your main layout) to avoid duplicate log entries per page view.

---

### {exp:arc_haystack:template_info}

Returns detailed information about a specific template.

#### Parameters

| Parameter | Description |
|-----------|-------------|
| `template` | Template path in `group/name` format |
| `template_id` | Template ID number (alternative to `template`) |
| `format` | Output format for single tag: `json` or `text` |

*Note: Use either `template` or `template_id`, not both.*

#### Variables (Tag Pair)

| Variable | Description |
|----------|-------------|
| `{template_id}` | Numeric template ID |
| `{template_name}` | Template name |
| `{template_group}` | Group name |
| `{template_path}` | Full path (group/name) |
| `{template_type}` | Type: `webpage`, `css`, `js`, `static`, `feed`, `xml`, `rss` |
| `{file_path}` | System file location or "Database only" |
| `{line_count}` | Number of lines in template |
| `{allow_php}` | PHP allowed: `y` or `n` |
| `{php_parse_location}` | PHP parse location: `i` (input) or `o` (output) |
| `{has_php_code}` | Contains PHP code: `y` or `n` |
| `{revision_count}` | Number of stored revisions |
| `{access_roles}` | Comma-separated role names with access |
| `{access_role_ids}` | Comma-separated role IDs |
| `{cache_enabled}` | Template caching enabled: `y` or `n` |
| `{cache_refresh}` | Cache refresh interval in seconds |
| `{hits}` | Number of times template has been viewed |
| `{last_edit_date}` | Last edited (human-readable format) |
| `{last_edit_timestamp}` | Last edited (Unix timestamp) |
| `{protect_javascript}` | JavaScript protection enabled: `y` or `n` |
| `{http_auth_enabled}` | HTTP auth enabled: `y` or `n` |
| `{template_notes}` | Template notes/description |

#### Tag Pair Example

```
{exp:arc_haystack:template_info template="blog/entry"}
    <div class="template-info">
        <h2>{template_path}</h2>
        <dl>
            <dt>Template ID</dt>
            <dd>{template_id}</dd>
            <dt>Type</dt>
            <dd>{template_type}</dd>
            <dt>Lines of Code</dt>
            <dd>{line_count}</dd>
            <dt>PHP Enabled</dt>
            <dd>{allow_php}</dd>
            <dt>Cache Enabled</dt>
            <dd>{cache_enabled}</dd>
            <dt>Hit Count</dt>
            <dd>{hits}</dd>
            <dt>Last Modified</dt>
            <dd>{last_edit_date}</dd>
        </dl>
    </div>
{/exp:arc_haystack:template_info}
```

#### Single Tag Examples

Get template info as JSON:

```
{exp:arc_haystack:template_info template="blog/entry" format="json"}
```

Look up by template ID:

```
{exp:arc_haystack:template_info template_id="42" format="json"}
```

---

## Control Panel

Access the control panel interface at: **Add-Ons -> ARC Haystack**

### Logging Toggle

At the top of the dashboard is a toggle to enable or disable all activity tracking without uninstalling the addon. When disabled, all tracking stops - the Usage Grid will show no active templates, and no log entries will be recorded.

### Template Usage Grid

A full inventory of every template, partial, and variable in your EE installation, with activity status derived from log data.

- Filterable by type (Template, Partial, Variable) and active status (All, Active, Never Seen)
- Columns are sortable: Template Group, Name, Type, Active, Last Seen
- **Active** and **Last Seen** columns are populated only when logging is enabled
- **Export** button downloads the current filtered grid as CSV or XML
- Layout templates are marked active when they are found anywhere in logged items, including nested layout chains

#### What populates the grid

| Column | Source |
|--------|--------|
| Template Group, Name, Type | EE database — always visible, no logging required |
| Active | Derived from log entries — requires logging enabled |
| Last Seen | Most recent log timestamp — requires logging enabled |

### Template Usage Logs

A paginated list of all recorded page views (50 per page), with sortable columns.

- Sortable by: Page URL, Logged At
- Click **View** to see full details for any log entry
- **Export Log** button to download log entries as CSV or XML
- **Clear All Logs** button to purge the log table

#### What gets logged and how

Two mechanisms write to the log table. They do not duplicate - if the tag fires, the extension skips that request.

| Mechanism | Captures | Requirement |
|-----------|----------|-------------|
| **Extension hooks** (automatic) | Main template, embeds, page URL, timestamp | Logging enabled - no tag placement needed |
| **`{exp:arc_haystack:log}` tag** | Everything above, plus: layout template, called-from template, partials, variables | Logging enabled + tag placed in templates |

For full tracking of partials and variables, place `{exp:arc_haystack:log}` in your main layout template.

### Export Log

The **Export Log** button (in the Template Usage Logs panel) opens an export form with the following options:

| Option | Description |
|--------|-------------|
| Format | CSV or XML |
| Start Date/Time | Only include entries on or after this date |
| End Date/Time | Only include entries on or before this date |
| Limit | Maximum number of records to export (leave blank for all) |

All filters are optional. The exported file includes one row per logged page view with: Main Template, Layout, Page URL, Embeds, Partials, Variables, and Logged At.

### Log Detail View

Each log entry detail view shows:

- **Main Template** - The primary content template with metadata (ID, type, file path, line count, PHP status, revision count, access roles, cache settings, hit count)
- **Layout Template Details** - Full layout chain in order, rendered as stacked layout blocks (layout 1, layout 2, ...)
- **Called From** - Which template contained the `{exp:arc_haystack:log}` tag
- **Embeds Used** - All embedded templates with file paths, line counts, and direct edit links
- **Partials Used** - All snippets with scope (Global/Site), file paths, and edit links
- **Variables Used** - All global variables with scope, file paths, and edit links

---

## Use Cases

### Development Debugging

Add the templates tag to a debug partial that only displays for super admins:

```
{if logged_in_group_id == 1}
<div id="template-debug" style="background:#333;color:#0f0;padding:10px;font-family:monospace;font-size:12px;">
    <strong>Templates:</strong>
    {exp:arc_haystack:templates include="templates" format="comma"}
    <br>
    <strong>Partials:</strong>
    {exp:arc_haystack:templates include="partials" format="comma"}
</div>
{/if}
```

### Template Audit

Use the logging feature to build a picture of which templates are actually being used across your site:

1. Add `{exp:arc_haystack:log}` to your main layout
2. Browse the site or let it collect data over time
3. Review logs in the control panel to identify unused templates

### Complex Template Debugging

When debugging a page with multiple nested embeds and layouts, use the JSON output:

```
<script>
console.table({exp:arc_haystack:templates format="json"});
</script>
```

---

## Database Table

The addon creates a `arc_haystack_logs` table:

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key (auto-increment) |
| `template_path` | VARCHAR(255) | Current template being parsed |
| `main_template` | VARCHAR(255) | Main content template |
| `layout_template` | VARCHAR(255) | Layout template used |
| `called_from` | VARCHAR(255) | Template containing the log tag |
| `page_url` | VARCHAR(2048) | Full URL of the request |
| `embeds_used` | TEXT | JSON array of embed paths |
| `partials_used` | TEXT | JSON array of partial names |
| `variables_used` | TEXT | JSON array of variable names |
| `logged_at` | INT | Unix timestamp |

---

## Changelog

### 1.6.1
- Added nested layout chain detection for logging and template usage tag output
- Updated status grid activity resolution so all layouts in logged chains can be marked active
- Updated log detail view to display the complete layout chain as stacked layout blocks
- Updated logs table to show `Layouts` count and removed `Main Template` column

### 1.6.0
- Added **Template Usage Grid** — a full inventory of every template, partial, and variable with activity status (active/last seen) derived from log data
- Added **automatic extension-based logging** via `template_fetch_template` and `template_post_parse` hooks — basic template activity is now recorded without placing the log tag in every template
- Added **grid export** (CSV or XML) for the filtered Template Usage Grid
- Added **sortable column headings** in both the Usage Grid and the Template Usage Logs table
- Added filtering in the Usage Grid by type (Template, Partial, Variable) and active status
- Updated logging toggle description to reflect that all activity tracking (grid and logs) stops when disabled

### 1.5.0
- Added persistent settings storage via `arc_haystack_settings` database table
- Logging enabled/disabled toggle is now saved across requests

### 1.4.0
- Converted control panel and tag routing to modern MVC structure

### 1.3.0
- Added "Export Log" feature supporting CSV and XML output with date range and limit filters
- Simplified log index table (removed Layout and Called From columns)
- Page URLs now display as relative paths in the log index

### 1.2.0
- Added `main_template`, `layout_template`, `called_from` tracking
- Improved layout detection
- Enhanced control panel detail views

### 1.1.0
- Added database logging functionality
- Added control panel interface
- Added `embeds_used`, `partials_used`, `variables_used` columns

### 1.0.0
- Initial release
- Template listing functionality
- Template info tag

---

## Support

For issues and feature requests, contact [CreativeArc](https://creativearc.com).
