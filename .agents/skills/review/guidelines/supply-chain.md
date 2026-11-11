# Supply chain

Dependencies and what ends up in the release.

Applies to: `package.json`, `package-lock.json`, `composer.json`, `composer.lock`, `.gitattributes`

- With Composer runtime dependencies: run `composer audit` and flag high and critical.
- With npm dependencies: run `npm audit` and flag high and critical.
- Dev dependencies do not reach production: check `.gitattributes` `export-ignore` and the release script.
- `vendor/` is excluded from release archives when it only holds dev tools.
- Bundled vendor libraries match the expected versions and have no known CVEs.
