# Output

Escaping, and what error messages and debug output reveal.

Applies to: `includes/wp-admin/**`, `templates/**`, `src/**`, `includes/transformer/class-preview.php`, `includes/class-blocks.php`

- Admin output uses `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- AT Protocol handles and display names shown in admin are escaped.
- AT-URIs shown in admin are validated and escaped.
- Meta box and editor panel output has no stored XSS vector.
- JSON preview and debug output does not reflect unsanitized input.
- Error messages do not leak tokens, keys, internal URLs or raw PDS responses.
