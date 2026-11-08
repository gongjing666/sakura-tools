# Third-party notices

## S3 Uploads

The S3-compatible storage integration preserves configuration and behavior compatibility with **S3 Uploads 1.3.1**, originally authored by 沈唁 (https://qq52o.me/). The original plugin declares the Apache License 2.0. The merged implementation has been reorganized and rewritten under the `Sakura\\ImageOptimizer` namespace; its duplicate filename-renaming feature is intentionally not included.

## AWS SDK for PHP and dependencies

`vendor/s3/` contains Composer-installed third-party packages used for S3 protocol support. The AWS SDK and its dependencies were copied with [Strauss](https://github.com/BrianHenryIE/strauss) into `vendor/s3/vendor-prefixed/` and re-namespaced under `Sakura\Vendor\` so that another plugin loading a different AWS SDK version cannot collide with this plugin. Package names, versions, source references and declared licenses are recorded in `vendor/s3/composer.lock`; package license files remain in their vendor directories where distributed.
