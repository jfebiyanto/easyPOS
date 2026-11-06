import markdown
import re
from pathlib import Path

md = Path('docs/ERD.md').read_text(encoding='utf-8')
md = re.sub(r'```mermaid\s*\n(.*?)```', r'```\n\1```', md, flags=re.S)
html = markdown.markdown(md, extensions=['tables', 'fenced_code'])

prefix = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>ERD easyPOS</title><style>' \
         'body{font-family:"Segoe UI",Arial,sans-serif;font-size:9pt;line-height:1.3;margin:20px}' \
         'h1{font-size:18pt;border-bottom:2px solid #333;margin-bottom:12px}' \
         'h2{font-size:14pt;margin-top:18px;border-bottom:1px solid #ccc}' \
         'h3{font-size:12pt;margin-top:14px}' \
         'table{border-collapse:collapse;width:100%;font-size:8pt;margin-top:6px}' \
         'th,td{border:1px solid #999;padding:4px;vertical-align:top}' \
         'th{background:#f0f0f0}' \
         'code{background:#f5f5f5;padding:1px 3px;font-size:8pt}' \
         'pre{background:#f5f5f5;padding:8px;overflow-x:auto;font-size:7.5pt;white-space:pre-wrap}' \
         '</style></head><body>'
suffix = '</body></html>'

Path('docs/ERD.html').write_text(prefix + html + suffix, encoding='utf-8')
print('HTML created')
