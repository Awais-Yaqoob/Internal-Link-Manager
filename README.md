Internal Link Manager – Smart Auto Internal Linking

Automatically insert internal links into WordPress posts using a safe DOM-based engine that prevents duplication, avoids disallowed sections, and works with Elementor templates.

🚀 Features

✅ Automatic keyword-to-URL linking

✅ Works with Elementor single post templates

✅ Prevents duplicate links in the same post

✅ Skips linking inside:

Existing <a> tags

Headings (H1–H6)

Scripts / Styles

Template embed areas (Elementor-safe)

✅ Optional support for linking keywords that appear in post titles (posts only)

✅ URL normalization to prevent duplicate detection errors

✅ PHP 8.1+ compatible (no deprecated warnings)

✅ DOM-based insertion (no fragile regex replacements)

✅ Keyword length sorting (longest first to prevent partial overlaps)

🧠 Why This Plugin Exists

Most auto internal linking plugins:

Break page builders

Duplicate links

Insert links inside headings

Cause performance issues

Use unsafe regex replacements

This plugin was built to:

Work safely with Elementor

Preserve layout structure

Respect SEO best practices

Avoid duplicate URL insertion

Provide deterministic insertion behavior

⚙️ How It Works

Loads post content.

Parses content into DOMDocument.

Collects valid text nodes.

Skips disallowed sections.

Checks if target URL already exists in content.

Inserts the link only once per URL.

Returns safely rendered HTML.

🧩 Post Type Behavior

✅ For Posts:

Keywords appearing in the post title (H1) will still be allowed to insert inside content.

🚫 For Pages / Custom Post Types:

Keywords in the main title will prevent insertion (to avoid duplicate emphasis).

This ensures blog SEO strength while protecting landing pages.

🛠 Installation

Upload the plugin folder to:

/wp-content/plugins/internal-link-manager/

Activate from WordPress Admin → Plugins.

Configure your keywords and URLs inside plugin settings (if applicable).

🧪 Compatibility

WordPress 5.8+

PHP 7.4 – 8.3+

Elementor (Single Post Templates supported)

WP Engine compatible

Cache-friendly

🧯 Performance Notes

Uses DOM parsing instead of regex.

Processes only singular posts.

Stops after successful insertion per keyword.

Avoids unnecessary DOM rewrites.

🔐 SEO Safety

No duplicate URLs per post

No heading linking (unless intentionally modified)

No linking inside existing anchors

No broken HTML output

Proper URL normalization

🐛 Debugging

If a keyword does not insert:

Check if URL already exists in content.

Ensure keyword is not inside a disallowed section.

Confirm post type behavior.

Clear cache if using server/page caching.

📌 Version

Current Version: 1.9.2

Added: Post-title linking support (Posts only)

Fixed: PHP 8.1+ deprecated warnings in RecursiveDOMIterator

Improved: Elementor-safe content detection

📄 License

GPLv2 or later
