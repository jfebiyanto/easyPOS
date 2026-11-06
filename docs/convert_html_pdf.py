import weasyprint
from pathlib import Path

html = Path('docs/ERD.html').read_text(encoding='utf-8')
weasyprint.HTML(string=html, base_url=str(Path('docs').resolve())).write_pdf('docs/ERD.pdf')
print('PDF created: docs/ERD.pdf')
