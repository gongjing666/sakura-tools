
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const SCAN = process.argv[2] || '/tmp/scan.json';
const OUTDIR = process.argv[3] || '/tmp/docparts';
const DOCS = process.argv[4] || '/tmp/docs.json';
const BT = String.fromCharCode(96);
const SEP = String.fromCharCode(0);
const k = (s) => BT + s + BT;

const scan = JSON.parse(fs.readFileSync(SCAN, 'utf8'));
const docsArr = JSON.parse(fs.readFileSync(DOCS, 'utf8'));
const here = path.dirname(new URL(import.meta.url).pathname);
const ROOT = scan.root || path.resolve(here, '../..');
const collector = path.join(here, 'collect-hooks.php');
const hooksData = JSON.parse(
  execFileSync(process.env.PHP_BIN || 'php', [collector, ROOT], {
    encoding: 'utf8',
    maxBuffer: 16 * 1024 * 1024,
  })
);
const hookList = hooksData.hooks;
const testFiles = [];
const walkTests = (dir) => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walkTests(full);
    else if (entry.name.endsWith('.php')) testFiles.push(path.relative(ROOT, full));
  }
};
walkTests(path.join(ROOT, 'tests'));
const testLines = testFiles.reduce((sum, f) => {
  const text = fs.readFileSync(path.join(ROOT, f), 'utf8');
  return sum + (text.endsWith('\n') ? text.split('\n').length - 1 : text.split('\n').length);
}, 0);
fs.mkdirSync(OUTDIR, { recursive: true });
fs.mkdirSync(path.join(OUTDIR, 'classes'), { recursive: true });

const clean = (s) => (s || '').replace(/\/\*\*|\*\//g, '').replace(/\n\s*\*\s?/g, ' ').replace(/\s+/g, ' ').trim();
const firstSentence = (s, max = 110) => {
  const t = clean(s);
  if (!t) return '';
  const parts = t.split(/(?<=[。.!?])\s/);
  let base = parts[0] && parts[0].length > 8 ? parts[0] : t.split('。')[0];
  if (!base || base.length < 6) base = t;
  return base.length > max ? base.slice(0, max - 1) + '…' : base;
};
// 中文职责表：优先取环境变量，其次 /tmp 工作副本，最后取与本脚本同目录的版本
const zhCandidates = [process.env.PURPOSES_ZH, '/tmp/docparts/purposes.zh.json', path.join(here, 'purposes.zh.json')].filter(Boolean);
const zhPath = zhCandidates.find((p) => fs.existsSync(p));
const ZH = zhPath ? JSON.parse(fs.readFileSync(zhPath, 'utf8')) : {};
const docOf = new Map();
for (const d of docsArr) if (d.type === 'class') docOf.set(d.file + '::' + d.name, d.doc);

const cls = scan.nodes.filter((n) => n.kind !== 'function');
const byId = new Map(cls.map((c) => [c.id, c]));
const PROD = (m) => m !== 'tests' && m !== 'stubs' && !m.startsWith('docs');
const prodCls = cls.filter((c) => PROD(c.module));
const prodFiles = scan.files.filter((f) => PROD(f.module));

const implOf = new Map();
for (const e of scan.edges) if (e.kind === 'implements') {
  const arr = implOf.get(e.from) || [];
  arr.push(String(e.to).replace(/^c:/, '').split('\\').pop());
  implOf.set(e.from, arr);
}
const hooksOf = new Map();
for (const h of hookList) {
  const arr = hooksOf.get(h.owner) || [];
  arr.push(h);
  hooksOf.set(h.owner, arr);
}
const inDeg = new Map(), outDeg = new Map();
for (const e of scan.edges) {
  outDeg.set(e.from, (outDeg.get(e.from) || 0) + 1);
  inDeg.set(e.to, (inDeg.get(e.to) || 0) + 1);
}

const mods = [...new Set(prodFiles.map((f) => f.module))];
const modStat = mods.map((m) => {
  const files = prodFiles.filter((f) => f.module === m);
  const classes = prodCls.filter((c) => c.module === m);
  return { module: m, files: files.length, lines: files.reduce((a, f) => a + (f.lines || 0), 0), classes: classes.length };
});
modStat.sort((a, b) => b.lines - a.lines);
const moduleNotes = {
  '根文件': '插件入口、卸载入口、前台快速检索入口',
  'includes': '1.3–1.9 时代的旧类，仍在承重（媒体库、优化器、缩略图、S3、处理器）',
  'includes/views': '旧式页面模板（由 includes 类 require 渲染）',
  'src': '组合根与生命周期：Plugin、Activation、Deactivation、Uninstaller',
  'src/Admin': '后台信息架构：Registry 路由表、Menu、Layout、Shell、Assets、环境自检',
  'src/Backup': '原图备份仓储与公网暴露探测',
  'src/Contracts': '五个接口：Module / Backup / File_System / Settings / Stats',
  'src/Filesystem': '文件系统仓储适配器',
  'src/Image': '图片处理管线：改名、备份、引擎选择、元数据、还原、尺寸目录',
  'src/Oss': 'HTML 过滤器与 URL 重写工具（缩略图策略复用的纯函数）',
  'src/S3': '对象存储：配置/加密、上传同步、删除队列、同步中心、监控告警',
  'src/Settings': '插件设置读写边界（选项读写、默认值与清洗）',
  'src/Stats': '统计仓储适配器',
  'src/Support': 'Hook_Loader（延迟注册）与 UTF-8 截断工具',
  'src/Sync': '持久化同步索引：扫描、任务、校验、CLI、快速检索查询',
  'src/Thumbnail': '缩略图 URL 重写策略：上下文、尺寸、供应商、探测、缓存',
  'src/UserFiles': '用户文件空间：上传、配额、审核执行、REST、Zibll 桥接',
};
let md = '# 模块总览\n\n| 模块 | 文件数 | 代码行 | 类(含接口) | 说明 |\n|---|---:|---:|---:|---|\n';
for (const m of modStat) md += '| ' + m.module + ' | ' + m.files + ' | ' + m.lines + ' | ' + m.classes + ' | ' + (moduleNotes[m.module] || '') + ' |\n';
const totFiles = modStat.reduce((a, m) => a + m.files, 0);
const totLines = modStat.reduce((a, m) => a + m.lines, 0);
const totCls = modStat.reduce((a, m) => a + m.classes, 0);
md += '| 合计 | ' + totFiles + ' | ' + totLines + ' | ' + totCls + ' | 生产代码；另有 tests ' + testFiles.length + ' 文件/' + testLines + ' 行、stubs 2 文件另计 |\n';
fs.writeFileSync(path.join(OUTDIR, 'modules.md'), md);

for (const m of mods) {
  const classes = prodCls.filter((c) => c.module === m).sort((a, b) => a.name.localeCompare(b.name));
  if (!classes.length) continue;
  let t = '| 类 | 文件:行 | 继承 / 实现 | 方法(公开/总) | 入度/出度 | 钩子 | 职责（据源码类注释译述） |\n|---|---|---|---:|---:|---:|---|\n';
  for (const c of classes) {
    const zh = ZH[c.fqn] || '';
    const doc = zh || firstSentence(docOf.get(c.file + '::' + c.name) || '');
    const parent = c.parent ? String(c.parent).split('\\').pop() : '';
    const impl = (implOf.get(c.id) || []).join(', ');
    const rel = [parent && ('extends ' + parent), impl && ('implements ' + impl)].filter(Boolean).join('；');
    const pub = (c.methodList || []).filter((x) => x.v === 'public').length;
    t += '| ' + k(c.name) + ' | ' + c.file + ':' + c.line + ' | ' + rel + ' | ' + pub + ' / ' + (c.methodList || []).length + ' | ' + (inDeg.get(c.id) || 0) + ' / ' + (outDeg.get(c.id) || 0) + ' | ' + ((hooksOf.get(c.fqn) || []).length || '') + ' | ' + doc + ' |\n';
  }
  fs.writeFileSync(path.join(OUTDIR, 'classes', m.replace(/\//g, '__') + '.md'), t);
}

const byModule = new Map();
let hookTotal = 0;
for (const h of hookList) {
  const owner = cls.find((c) => c.fqn === h.owner);
  if (owner && !PROD(owner.module)) continue;
  const m = owner ? owner.module : '未归属';
  const arr = byModule.get(m) || [];
  arr.push(h);
  byModule.set(m, arr);
  hookTotal++;
}
const actionTotal = hookList.filter((h) => h.kind === 'add_action').length;
const filterTotal = hookList.filter((h) => h.kind === 'add_filter').length;
let hm = '# 钩子索引\n\n';
for (const m of [...byModule.keys()].sort()) {
  const rows = byModule.get(m).sort((a, b) => (a.hook + a.line).localeCompare(b.hook + b.line));
  hm += '## ' + m + '（' + rows.length + ' 个）\n\n| 钩子 | 类型 | 回调 | 注册位置 |\n|---|---|---|---|\n';
  for (const h of rows) {
    const cb = h.callback || ((h.owner ? String(h.owner).split('\\').pop() + '::' : '') + (h.method || ''));
    hm += '| ' + k(h.hook) + ' | ' + String(h.kind).replace('add_', '') + ' | ' + String(cb).replace(/\|/g, '/') + ' | ' + h.file + ':' + h.line + ' |\n';
  }
  hm += '\n';
}
hm += '合计 ' + hookTotal + ' 条具名钩子（add_action ' + actionTotal + ' + add_filter ' + filterTotal + '；已排除 tests/stubs、add_submenu_page 与 Hook_Loader 通用转发）。\n';
fs.writeFileSync(path.join(OUTDIR, 'hooks.md'), hm);

let ci = '| # | 类 | 文件:行 | 方法数 |\n|---:|---|---|---:|\n';
prodCls.sort((a, b) => (a.module + a.name).localeCompare(b.module + b.name)).forEach((c, i) => {
  ci += '| ' + (i + 1) + ' | ' + k(c.fqn) + ' | ' + c.file + ':' + c.line + ' | ' + (c.methodList || []).length + ' |\n';
});
fs.writeFileSync(path.join(OUTDIR, 'class-index.md'), ci);

let fi = '| 文件 | 模块 | 行数 | 声明类 |\n|---|---|---:|---|\n';
for (const f of prodFiles.sort((a, b) => a.path.localeCompare(b.path))) {
  const names = prodCls.filter((c) => c.file === f.path).map((c) => c.name);
  fi += '| ' + f.path + ' | ' + f.module + ' | ' + (f.lines || 0) + ' | ' + (names.length ? names.map(k).join(', ') : '—') + ' |\n';
}
fs.writeFileSync(path.join(OUTDIR, 'file-index.md'), fi);

const modEdges = new Map();
for (const e of scan.edges) {
  const a = byId.get(e.from), b = byId.get(e.to);
  if (!a || !b || !PROD(a.module) || !PROD(b.module)) continue;
  if (a.module === b.module) continue;
  modEdges.set(a.module + SEP + b.module, (modEdges.get(a.module + SEP + b.module) || 0) + 1);
}
const modOrder = modStat.map((m) => m.module);
let mx = '| 从 / 到 | ' + modOrder.map((m) => m.replace('src/', '')).join(' | ') + ' |\n|---|' + modOrder.map(() => '---:').join('|') + '|\n';
for (const a of modOrder) {
  let row = '| ' + a.replace('src/', '') + ' |';
  for (const b of modOrder) row += ' ' + (modEdges.get(a + SEP + b) || '') + ' |';
  mx += row + '\n';
}
fs.writeFileSync(path.join(OUTDIR, 'module-matrix.md'), mx);

const kindName = { call: '调用', static: '静态调用', new: '实例化', type: '类型引用', callback: '回调', instanceof: 'instanceof', implements: '实现' };
const agg = new Map();
for (const e of scan.edges) {
  const a = byId.get(e.from), b = byId.get(e.to);
  if (!a || !b || !PROD(a.module) || !PROD(b.module)) continue;
  if (e.kind === 'implements') continue;
  const key = e.from + SEP + e.to + SEP + e.kind;
  const cur = agg.get(key) || { a, b, kind: e.kind, w: 0, lines: [] };
  cur.w += (e.weight || 1);
  if (cur.lines.length < 4 && e.lines) cur.lines.push(...e.lines.slice(0, 2));
  agg.set(key, cur);
}
const top = [...agg.values()].sort((x, y) => y.w - x.w).slice(0, 40);
let te = '| from | to | 类型 | 次数 | 证据行 |\n|---|---|---|---:|---|\n';
for (const e of top) te += '| ' + k(e.a.name) + ' | ' + k(e.b.name) + ' | ' + (kindName[e.kind] || e.kind) + ' | ' + e.w + ' | ' + e.a.file + ':' + e.lines.slice(0, 4).join(',') + ' |\n';
fs.writeFileSync(path.join(OUTDIR, 'top-edges.md'), te);

const exportFiles = prodFiles;
const exportCls = prodCls;
const modNames = [...new Set(exportFiles.map((f) => f.module))];
const modList = modNames.map((m) => ({ module: m, files: exportFiles.filter((f) => f.module === m).length, classes: exportCls.filter((c) => c.module === m).length })).sort((a, b) => b.files - a.files);
const jitter = (i) => (((i * 2654435761) % 1000) / 1000 - 0.5) * 2;
const fib = (i, n) => {
  const ga = Math.PI * (3 - Math.sqrt(5));
  const y = n === 1 ? 0 : 1 - (i / (n - 1)) * 2;
  const r = Math.sqrt(Math.max(0, 1 - y * y));
  const th = ga * i;
  return [Math.cos(th) * r, y, Math.sin(th) * r];
};
const R = 1450;
const modPos = new Map();
modList.forEach((m, i) => {
  const p = fib(i, modList.length);
  modPos.set(m.module, [p[0] * R, p[1] * R, p[2] * R]);
});
const palette = {
  '根文件': '#ffd166', includes: '#ff9f7f', 'includes/views': '#f7a1c4', src: '#9ad1ff',
  'src/Admin': '#7fd3ff', 'src/Backup': '#8fe3c4', 'src/Contracts': '#c9a7ff', 'src/Filesystem': '#a7c9ff',
  'src/Image': '#6ee7b7', 'src/Oss': '#f2c97d', 'src/S3': '#5aa9ff', 'src/Settings': '#b8a1ff',
  'src/Stats': '#9ae6b4', 'src/Support': '#cbd5e1', 'src/Sync': '#f59e9e', 'src/Thumbnail': '#7dd3fc', 'src/UserFiles': '#fca5a5',
};
const nodes = [];
for (const m of modList) {
  const p = modPos.get(m.module);
  nodes.push({ id: 'mod:' + m.module, label: m.module === '根文件' ? '根文件' : m.module, type: 'module', module: m.module, val: Math.max(4, m.files * 0.8), files: m.files, classes: m.classes, color: palette[m.module] || '#7fd3ff', x: +p[0].toFixed(1), y: +p[1].toFixed(1), z: +p[2].toFixed(1) });
}
const clsByMod = new Map();
for (const c of exportCls) {
  const arr = clsByMod.get(c.module) || [];
  arr.push(c);
  clsByMod.set(c.module, arr);
}
for (const [m, list] of clsByMod) {
  const c0 = modPos.get(m);
  list.sort((a, b) => a.name.localeCompare(b.name));
  list.forEach((c, i) => {
    const d = fib(i, list.length);
    const spread = 70 + Math.min(190, list.length * 4.2);
    const pub = (c.methodList || []).filter((x) => x.v === 'public').length;
    nodes.push({ id: 'cls:' + c.fqn, label: c.name, type: 'class', module: m, kind: c.kind, file: c.file, line: c.line, val: Math.max(1.2, Math.sqrt(pub) * 1.6), color: palette[m] || '#7fd3ff', x: +(c0[0] + d[0] * spread).toFixed(1), y: +(c0[1] + d[1] * spread).toFixed(1), z: +(c0[2] + d[2] * spread).toFixed(1) });
  });
}
const pairAgg = new Map();
for (const e of scan.edges) {
  const a = byId.get(e.from), b = byId.get(e.to);
  if (!a || !b || !PROD(a.module) || !PROD(b.module)) continue;
  const key = e.from + SEP + e.to;
  const cur = pairAgg.get(key) || { a, b, w: 0, kinds: new Set() };
  cur.w += (e.weight || 1);
  cur.kinds.add(e.kind);
  pairAgg.set(key, cur);
}
const links = [];
for (const p of pairAgg.values()) links.push({ source: 'cls:' + p.a.fqn, target: 'cls:' + p.b.fqn, type: 'ref', kinds: [...p.kinds], w: p.w });
for (const [m, list] of clsByMod) for (const c of list) links.push({ source: 'mod:' + m, target: 'cls:' + c.fqn, type: 'contains', w: 1 });
const modPair = new Map();
for (const e of scan.edges) {
  const a = byId.get(e.from), b = byId.get(e.to);
  if (!a || !b || !PROD(a.module) || !PROD(b.module) || a.module === b.module) continue;
  const key = 'mod:' + a.module + SEP + 'mod:' + b.module;
  modPair.set(key, (modPair.get(key) || 0) + 1);
}
for (const [key, w] of modPair) {
  const parts = key.split(SEP);
  links.push({ source: parts[0], target: parts[1], type: 'modref', w });
}
const maxR = Math.max(...nodes.map((n) => Math.hypot(n.x, n.y, n.z)));
// 版本号从插件主文件头读取，避免图谱与文档版本脱节
let pluginVersion = '1.24.4';
try {
  const rootDir = scan.root || '';
  const main = fs.readdirSync(rootDir).find((f) => /\.php$/.test(f) && /Plugin Name:/i.test(fs.readFileSync(path.join(rootDir, f), 'utf8').slice(0, 2000)));
  if (main) {
    const m = fs.readFileSync(path.join(rootDir, main), 'utf8').match(/^[ \t/*#@]*Version:\s*([\w.\-]+)/m);
    if (m) pluginVersion = m[1];
  }
} catch (e) { /* 读不到就保留默认值 */ }
const graph = {
  meta: { plugin: 'sakura-tools', version: pluginVersion, generatedAt: new Date().toISOString(), source: 'dsh-codegraph lib/scanner.php（token_get_all 三趟静态扫描）', doc: 'sakura-tools-代码结构-' + pluginVersion + '.md' },
  stats: { files: exportFiles.length, modules: modList.length, classes: exportCls.length, nodes: nodes.length, links: links.length, refLinks: links.filter((l) => l.type === 'ref').length, modrefLinks: links.filter((l) => l.type === 'modref').length, containsLinks: links.filter((l) => l.type === 'contains').length, hooks: hookTotal },
  layout: { radius: +maxR.toFixed(1), iterations: 0, method: '模块球面分布 + 模块内斐波那契错位（确定性，无迭代）' },
  nodes,
  links,
};
fs.writeFileSync(path.join(OUTDIR, 'graph.json'), JSON.stringify(graph));
fs.writeFileSync(path.join(OUTDIR, 'graph.pretty.json'), JSON.stringify(graph, null, 1));
console.log(JSON.stringify({ files: exportFiles.length, classes: exportCls.length, modules: modList.length, nodes: nodes.length, links: links.length, hooks: hookTotal, radius: graph.layout.radius }));
