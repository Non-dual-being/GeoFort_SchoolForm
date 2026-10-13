"""Optional artifact QA (pdfplumber + Pillow); run from the repository root."""
from pathlib import Path
import json
import re
import hashlib
from email import policy
from email.parser import BytesParser
import pdfplumber
from PIL import Image, ImageDraw

root = Path.cwd()
assets = root / 'public/assets/booking/roosters'
output = root / 'local-backups/four-module-public-review'
output.mkdir(parents=True, exist_ok=True)
labels = {
    'minecraft-klimaatspeurtocht': 'Minecraft Klimaatspeurtocht',
    'minecraft-programmeren': 'Minecraft Programmeren',
    'klimaat-experience': 'Klimaat Experience',
    'stop-de-klimaat-klok': 'Stop de Klimaat Klok',
    'earth-watch': 'Earth Watch', 'klimparcours': 'Vleermuizen Speurtuin',
}
reviewed = []
sheets = {}
for file in sorted((assets / 'pdf').glob('four-2027-*.pdf')):
    match = re.fullmatch(r'four-2027-(po|vo)-(.+)-(\d)', file.stem)
    sector, choice, groups = match.groups()
    groups = int(groups)
    with pdfplumber.open(file) as pdf:
        assert len(pdf.pages) == 1, file
        page = pdf.pages[0]
        text = ' '.join(page.extract_text().split())
        assert 'Voorbeeldrooster' in text and 'vier modules' in text, file
        for time in ['10:15-11:00', '11:00-11:45', '11:45-12:15', '12:15-13:00', '13:00-13:45', '13:45-14:00']:
            assert time in text, (file, time)
        assert '15:00' not in text
        for group in range(1, groups + 1):
            assert f'Groep {group}' in text, (file, group)
        # Extract each column separately: wrapped text on adjacent columns otherwise interleaves.
        body = page.crop((24, 170, page.width - 24, page.height - 70))
        cell_text = ' '.join(body.extract_text(layout=True).split())
        for char in page.chars:
            assert 20 <= char['x0'] <= char['x1'] <= page.width - 20, (file, char)
            assert 20 <= char['top'] <= char['bottom'] <= page.height - 20, (file, char)
        # Verify actual glyph strings, ignoring their inter-column ordering.
        glyphs = ''.join(c['text'] for c in page.chars)
        for label in ['Dynamische Globe', 'Voedsel Innovatie',
                      'Vleermuizen Speurtuin' if sector == 'po' else 'Klimaat Experience', labels[choice]]:
            assert glyphs.replace(' ', '').count(label.replace(' ', '')) == groups, (file, label)
    image = Image.open(assets / 'images' / (file.stem + '.png'))
    assert image.width >= 1400 and image.height >= 990
    key = f'{sector}-{choice}'
    sheets.setdefault(key, []).append((file.stem, image.copy()))
    reviewed.append({'file': file.name, 'pdf_sha256': hashlib.sha256(file.read_bytes()).hexdigest()})
assert len(reviewed) == 72
for key, items in sheets.items():
    sheet = Image.new('RGB', (1400, 2072), 'white')
    draw = ImageDraw.Draw(sheet)
    for i, (name, image) in enumerate(items):
        image.thumbnail((700, 495))
        x, y = (i % 2) * 700, (i // 2) * 518
        draw.text((x + 12, y + 3), name, fill='black')
        sheet.paste(image, (x, y + 22))
    sheet.save(output / ('contact-' + key + '.png'))

result_path = output / 'results.json'
if result_path.exists():
    results = json.loads(result_path.read_text())
    for i, case in enumerate(results['cases'], 1):
        mail = BytesParser(policy=policy.default).parsebytes((output / f'mail-{i}.eml').read_bytes())
        attachments = list(mail.iter_attachments())
        roster = next(a for a in attachments if a.get_filename() == 'GeoFort_Onderwijs_Conceptrooster.pdf')
        expected = root / 'public' / case['roster']['pdfUrl'].lstrip('/')
        assert roster.get_payload(decode=True) == expected.read_bytes(), f'Mail {i}: wrong PDF bytes'
        text = mail.get_body(preferencelist=('plain',)).get_content()
        price = case['quote']['total']['amountInclVatCents'] / 100
        amount = f'{price:,.2f}'.replace(',', 'X').replace('.', ',').replace('X', '.')
        assert amount in text, f'Mail {i}: wrong price'
        if i < 3:
            assert 'vier modules' in text and '14:00' in text, f'Mail {i}: wrong program'
    print('PASS: captured mail PDFs are byte-identical to downloads; mail prices equal stored quotes')
(output / 'pdf-review.json').write_text(json.dumps(reviewed, indent=2))
print('PASS: all 72 PDFs, text, group counts, four module visits, page bounds and PNG dimensions')
