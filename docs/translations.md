# How To Translate the ATmosphere Plugin

## Who translates ATmosphere? How can I get involved?

Once ATmosphere is hosted on the WordPress.org plugin directory, anyone can suggest new translations through the [WordPress.org translation platform](https://translate.wordpress.org/projects/wp-plugins/atmosphere/) (link will be live after the first wp.org release).

Once you've suggested a new translation, [a GlotPress validator](https://make.wordpress.org/polyglots/teams/) will review it. They will then approve, reject, or refine your suggestions. Approved changes ship automatically to all ATmosphere users running WordPress in your language.

## How does GlotPress work?

You can find detailed GlotPress documentation in [the WordPress Polyglots Handbook](https://make.wordpress.org/polyglots/handbook/tools/glotpress-translate-wordpress-org/).

## I want to change translations locally. Where do I download the .PO file for my language?

Each language page on translate.wordpress.org has options at the bottom to create and export `.PO` / `.MO` files.

## I found a missing translation, but I can't find the string in GlotPress

If you can't find a string in GlotPress, it may have been:

- Added very recently and not yet sync'd to translate.wordpress.org.
- Coming from a third-party integration that ships its own text domain.

Open an issue on [GitHub](https://github.com/Automattic/wordpress-atmosphere/issues) with the exact text and where you saw it (admin screen, error message, post sidebar, etc.) and we'll track it down.

## Plugin Text Domain

ATmosphere's text domain is `'atmosphere'`. All translatable strings must pass this exact string to `__()`, `_e()`, `_x()`, `_n()`, and friends:

```php
\__( 'Connect to Bluesky', 'atmosphere' );
\esc_html__( 'Cross-post settings', 'atmosphere' );
\_n( '%s post', '%s posts', $count, 'atmosphere' );
```

If you're writing a third-party integration, use **your own** text domain — never `'atmosphere'`. That way your strings live in your plugin's translation file and you control them.

## Writing Translation-Friendly Strings

- **Full sentences, not fragments.** Translators need context. `"Saved"` is harder to translate than `"Settings saved."`.
- **Use placeholders, not concatenation.** Languages reorder words differently. `\sprintf( \__( 'Published to %s.', 'atmosphere' ), 'Bluesky' )` works; `\__( 'Published to ', 'atmosphere' ) . 'Bluesky.'` doesn't.
- **Use `_n()` for plurals.** Languages have more than two plural forms. `\_n( '%s reply', '%s replies', $count, 'atmosphere' )` is required for translation to work.
- **Add context with `_x()` when the same string means different things.** `\_x( 'Post', 'verb', 'atmosphere' )` vs. `\_x( 'Post', 'noun', 'atmosphere' )`.
- **Don't translate dynamic data.** Handles, URLs, post titles, and other user content stay as-is.
- **Translator comments for non-obvious placeholders.** Add a `/* translators: %s is the Bluesky handle */` comment immediately before the string so the translator knows what to substitute.

```php
\sprintf(
    /* translators: %s is the Bluesky handle the post was published as. */
    \__( 'Posted as %s.', 'atmosphere' ),
    $handle
);
```
