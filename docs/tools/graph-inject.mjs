
// 将 build-code-structure.mjs 生成的 graph.json 内联进 graph-preview/index.html。
// 用法：node tools/graph-inject.mjs <graph.json> <previewDir> [--write]
// 不带 --write 时只校验并打印将发生的改动（dry-run）。
import fs from 'node:fs';
import path from 'node:path';

const graphPath = process.argv[2] || '/tmp/docparts/graph.json';
const here = path.dirname(new URL(import.meta.url).pathname);
const dir = process.argv[3] || path.join(here, '..', 'graph-preview');
const write = process.argv.includes('--write');
const graph = JSON.parse(fs.readFileSync(graphPath, 'utf8'));
const htmlPath = path.join(dir, 'index.html');
const html = fs.readFileSync(htmlPath, 'utf8');

// 1) 悬空边校验
const ids = new Set(graph.nodes.map((n) => n.id));
const dangling = graph.links.filter((l) => !ids.has(l.source) || !ids.has(l.target));
const selfLoop = graph.links.filter((l) => l.source === l.target);
if (dangling.length || selfLoop.length) {
  console.error('校验失败：悬空边', dangling.length, '自环', selfLoop.length);
  process.exit(1);
}

// 2) 定位 const DATA = {...} 并按花括号配对替换（跳过字符串内的括号）
const marker = 'const DATA = ';
const start = html.indexOf(marker);
if (start < 0) throw new Error('未找到 const DATA = ');
let i = start + marker.length, depth = 0, inStr = false, esc = false, end = -1;
for (; i < html.length; i++) {
  const ch = html[i];
  if (inStr) {
    if (esc) { esc = false; continue; }
    if (ch === '\\') { esc = true; continue; }
    if (ch === '"') inStr = false;
    continue;
  }
  if (ch === '"') { inStr = true; continue; }
  if (ch === '{') depth++;
  else if (ch === '}') { depth--; if (depth === 0) { end = i; break; } }
}
if (end < 0) throw new Error('DATA 字面量未闭合');

// 3) 统计数字与副标题按 graph.stats / meta 重写（正则，重复执行安全）
const st = graph.stats;
const nClass = graph.nodes.filter((n) => n.type === 'class').length;
const nMod = graph.nodes.filter((n) => n.type === 'module').length;
const version = (graph.meta && graph.meta.version) || '';
let out = html.slice(0, start + marker.length) + JSON.stringify(graph) + html.slice(end + 1);
const subs = [
  [/<b>\d+<\/b>文件/, '<b>' + st.files + '</b>文件'],
  [/<b>\d+<\/b>节点/, '<b>' + graph.nodes.length + '</b>节点'],
  [/<b>\d+<\/b>关系/, '<b>' + graph.links.length + '</b>关系'],
  [/<b>\d+<\/b>钩子/, '<b>' + st.hooks + '</b>钩子'],
  [/<p>sakura-tools[^<]*<\/p>/, '<p>sakura-tools v' + version + ' · ' + nClass + ' 类 / ' + nMod + ' 模块 · 静态扫描生成 · 点击节点查看邻域</p>'],
];
for (const [re, rep] of subs) {
  if (!re.test(out)) { console.error('警告：未匹配', re); continue; }
  out = out.replace(re, rep);
}

const summary = { nodes: graph.nodes.length, links: graph.links.length, classes: nClass, modules: nMod, hooks: st.hooks, dangling: 0, oldBytes: html.length, newBytes: out.length, changed: out !== html };
if (write) {
  fs.writeFileSync(htmlPath, out);
  fs.writeFileSync(path.join(dir, 'graph.json'), JSON.stringify(graph, null, 1));
  summary.written = true;
}
console.log(JSON.stringify(summary));
