<?php
/**
 * 提取 PHP 文件的类级 docblock，供 build-code-structure.mjs 生成“职责”列。
 * 用法：php collect-comments.php <plugin-root> > docs.json
 * 输出：[{file,type,name,line,doc}]，file 为相对路径，name 为短类名（与 scanner 的 name 字段一致）。
 */
if (PHP_SAPI !== 'cli') {
    // 文件随插件入库，禁止经 Web 访问执行（否则会扫描站点源码并输出类注释）。
    http_response_code(404);
    exit;
}
$root = rtrim($argv[1] ?? '.', '/');
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
    $rel = ltrim(substr($f->getPathname(), strlen($root)), '/');
    if (preg_match('#^(tests|stubs|node_modules|vendor|\.dsh-ops|docs)/#', $rel)) continue;
    $files[] = $rel;
}
sort($files);
$out = [];
foreach ($files as $rel) {
    $src = @file_get_contents($root . '/' . $rel);
    if ($src === false) continue;
    try { $tokens = token_get_all($src); } catch (Throwable $e) { continue; }
    $doc = '';
    $ns = '';
    $lastSignificant = null;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t)) {
            [$id, $text] = $t;
            if ($id === T_DOC_COMMENT) { $doc = $text; $lastSignificant = $id; continue; }
            if ($id === T_WHITESPACE || $id === T_COMMENT) continue;
            if ($id === T_NAMESPACE) {
                $buf = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    $tj = $tokens[$j];
                    if (is_array($tj) && in_array($tj[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED], true)) { $buf .= $tj[1]; continue; }
                    if (is_array($tj) && $tj[0] === T_WHITESPACE) continue;
                    break;
                }
                $ns = trim($buf, '\\');
                $lastSignificant = $id; $doc = ''; continue;
            }
            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT], true) || (defined('T_ENUM') && $id === T_ENUM)) {
                // 跳过 ::class 与匿名类
                $prev = $lastSignificant;
                if ($prev === T_DOUBLE_COLON || $prev === T_NEW) { $doc = ''; $lastSignificant = $id; continue; }
                $line = $t[2];
                for ($j = $i + 1; $j < $n; $j++) {
                    $tj = $tokens[$j];
                    if (is_array($tj) && $tj[0] === T_WHITESPACE) continue;
                    if (is_array($tj) && $tj[0] === T_STRING) {
                        $out[] = ['file' => $rel, 'type' => 'class', 'name' => $tj[1], 'line' => $line, 'doc' => $doc];
                        break;
                    }
                    break;
                }
                $doc = ''; $lastSignificant = $id; continue;
            }
            $lastSignificant = $id;
            continue;
        }
        $lastSignificant = $t;
    }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
