# Forms for HTML Page Publisher

A WordPress add-on for [HTML Page Publisher](https://wordpress.org/plugins/html-page-publisher/) that puts working **Gravity Forms**, **Contact Form 7** or **WPForms** forms into static landing pages, and converts the raw `<form>` elements that AI-generated pages ship with into real forms.

## Placeholders

Drop a placeholder anywhere in a published page's `index.html`:

```html
<!-- form:gravity:12 -->
<!-- form:cf7:34 -->
<!-- form:wpforms:56 styles=page button-class="btn btn-primary" -->
```

- `styles=page` keeps the landing page's own CSS in charge: the form plugin's theme is turned off and the original button classes are carried over.
- `title=true` / `description=true` show the form's title and description.
- The older `<!-- gravity-form:12 -->` syntax still works.

Forms render with only the scripts and styles they need, and submissions go through each plugin's own handling: validation, anti-spam, entries, notifications and confirmations.

## Detect Forms

**HTML Pages → Detect Forms** lists pages that contain raw HTML forms, previews the fields it found, and converts a form with one click:

1. Creates the form in the plugin you choose, with the same fields, required flags, choices, button text, a confirmation and an admin email notification.
2. Replaces exactly that `<form>` with a placeholder. The heading and other content inside it, plus its class and id, stay in the page.
3. Saves the previous version in the page's history, so you can undo it.

## WP-CLI

```bash
wp htmlpp forms plugins
wp htmlpp forms detect
wp htmlpp forms convert spring-promo --to=cf7 --dry-run
wp htmlpp forms convert spring-promo --to=gravity --all --user=admin
wp htmlpp forms placeholders --problems
```

## Requirements

- WordPress 5.9+, PHP 7.4+
- [HTML Page Publisher](https://wordpress.org/plugins/html-page-publisher/)
- At least one of: Gravity Forms 2.9+, Contact Form 7, WPForms (Lite or Pro)

## Installation

Download this repository as a ZIP (or clone it) into `wp-content/plugins/forms-for-html-page-publisher`, then activate **Forms for HTML Page Publisher**. Or zip the plugin folder (named `forms-for-html-page-publisher`) and use Plugins → Add New → Upload Plugin.

## Documentation

[readme.txt](readme.txt) has the full details: field mapping per plugin, how the add-on hooks into HTML Page Publisher, filters and actions, writing an adapter for another form plugin, and known limitations.

## License

GPL-2.0-or-later. © Xavier Castaneda · [xcast3d.com](https://xcast3d.com)
