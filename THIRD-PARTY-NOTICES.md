# Third-party notices

## S3 Uploads

The S3-compatible storage integration preserves configuration and behavior compatibility with **S3 Uploads 1.3.1**, originally authored by 沈唁 (https://qq52o.me/). The original plugin declares the Apache License 2.0. The merged implementation has been reorganized and rewritten under the `Sakura\\ImageOptimizer` namespace; its duplicate filename-renaming feature is intentionally not included.

## AWS SDK for PHP and dependencies

`vendor/s3/` contains Composer-installed third-party packages used for S3 protocol support. The AWS SDK and its dependencies were copied with [Strauss](https://github.com/BrianHenryIE/strauss) into `vendor/s3/vendor-prefixed/` and re-namespaced under `Sakura\Vendor\` so that another plugin loading a different AWS SDK version cannot collide with this plugin. Package names, versions, source references and declared licenses are recorded in `vendor/s3/composer.lock`; package license files remain in their vendor directories where distributed.

## License of this plugin and of the bundled packages

This plugin is released under the **GNU General Public License v2.0 or later** (see `LICENSE`). It is distributed for use inside WordPress, which is itself GPLv2-or-later.

`vendor/s3/` bundles 15 packages: 13 under the MIT license and 2 under the Apache License 2.0 (`aws/aws-sdk-php`, `aws/aws-crt-php`). MIT and Apache-2.0 are both compatible with a GPLv2-or-later work when the combined distribution is offered under the GPL and the third-party notices are preserved, which is what this file does. Each package's own license text stays in its directory under `vendor/s3/vendor-prefixed/`; the authoritative list of names, versions and declared licenses is `vendor/s3/composer.lock`.

The packages are vendored rather than required at install time on purpose: WordPress plugins cannot rely on a shared Composer autoloader, and a site may already run a different AWS SDK version. [Strauss](https://github.com/BrianHenryIE/strauss) re-namespaces them under `Sakura\Vendor\` so the two cannot collide.

If you redistribute this plugin or a modified version of it, keep `LICENSE`, this file, and the third-party license texts in `vendor/s3/vendor-prefixed/`.
