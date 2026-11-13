#!/usr/bin/env python3
"""组装 sakura-tools 代码结构文档正文。

用法：
  python3 tools/assemble-doc.py [--parts DIR] [--chapters DIR] [--out FILE]

步骤：
  1) 读 chapters/p1..p6.md（正文分章），按固定顺序拼接；
  2) 用 parts/ 下生成的 modules.md、classes/*.md、module-matrix.md、top-edges.md、
     class-index.md、hooks.md、file-index.md 替换正文中的 {{...}} 占位符；
  3) 写出最终 Markdown，并打印占位符残留、行数与标题结构供核对。
"""
import argparse, os, re

ap = argparse.ArgumentParser()
ap.add_argument('--parts', default='/tmp/docparts')
ap.add_argument('--chapters', default=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'chapters'))
ap.add_argument('--out', default=os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'sakura-tools-代码结构-1.25.8.md'))
a = ap.parse_args()

def body(name, demote=False):
    s = open(os.path.join(a.parts, name), encoding='utf-8').read().strip()
    lines = s.split('\n')
    if lines and lines[0].startswith('#'):
        lines.pop(0)
    while lines and lines[0].strip() == '':
        lines.pop(0)
    s = '\n'.join(lines).strip()
    if demote:
        s = re.sub(r'(?m)^## ', '### ', s)
    return s

order = ['p1', 'p2', 'p5', 'p3', 'p4', 'p6']
chapters = [open(os.path.join(a.chapters, n + '.md'), encoding='utf-8').read() for n in order]
doc = '\n'.join(c.rstrip() + '\n' for c in chapters)

doc = doc.replace('{{MODULES}}', body('modules.md'))
mods = ['includes', 'src', 'src/Admin', 'src/Backup', 'src/Contracts', 'src/Filesystem', 'src/Image',
        'src/Oss', 'src/S3', 'src/Settings', 'src/Stats', 'src/Support', 'src/Sync', 'src/Thumbnail', 'src/UserFiles']
for m in mods:
    doc = doc.replace('{{CLASSES:' + m + '}}', body('classes/' + m.replace('/', '__') + '.md'))
doc = doc.replace('{{MATRIX}}', body('module-matrix.md'))
doc = doc.replace('{{TOP_EDGES}}', '\n'.join(body('top-edges.md').split('\n')[:12]))
doc = doc.replace('{{CLASS_INDEX}}', body('class-index.md'))
doc = doc.replace('{{HOOKS}}', body('hooks.md', demote=True))
doc = doc.replace('{{FILE_INDEX}}', body('file-index.md'))
doc = doc.replace('{{INCLUDES_CLASSES}}', body('classes/includes.md'))

left = re.findall(r'\{\{[^}]+\}\}', doc)
os.makedirs(os.path.dirname(a.out), exist_ok=True)
open(a.out, 'w', encoding='utf-8').write(doc)
print('out       ', a.out)
print('bytes     ', len(doc.encode()), 'lines', doc.count('\n'))
print('leftover  ', len(left), left[:5])
print('h2        ', [l[3:] for l in doc.split('\n') if l.startswith('## ')])
