# sakura-tools

WordPress插件：樱花工具箱

## 发布

版本号以 `sakura-tools.php` 的 `Version` 为准，并必须与 `readme.txt` 的 `Stable tag`、`SAKURA_TOOLS_VERSION` 保持一致。

推送到 `main` 后，`.github/workflows/release.yml` 会自动读取版本号；如果对应的 `v<版本>` Release 还不存在，就构建插件包并创建标签与 Release。也可以在 Actions 里手动运行 `Release plugin`；已存在的版本会跳过，需先删除对应 Release 和标签才能重建：

- `sakura-tools-<版本>.zip`
- `sakura-tools-<版本>.zip.sha256`

本地构建（默认当前 HEAD）：

```bash
bin/build-plugin-zip.sh --version 1.23.0 --out dist
```

补建历史版本包：

```bash
bin/build-plugin-zip.sh --ref c8e6b4a --version 1.22.9 --out dist
```

包内顶层目录为 `sakura-tools/`，保留运行时依赖 `vendor/s3/`，不包含根 `vendor/`、`tests/`、`docs/`、`.github/`、`bin/`、Composer/PHPCS/PHPStan/PHPUnit 配置和开发文档。
