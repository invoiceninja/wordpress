"""Render the demo product photos (run inside the Playwright image; see README in this folder)."""
from playwright.sync_api import sync_playwright

ITEMS = {
    'mug': ('#e9e4d8', '#8a6f4d', 'M300 330h170v190a60 60 0 0 1-60 60h-50a60 60 0 0 1-60-60z M470 380h30a45 45 0 0 1 0 90h-30'),
    'bowl': ('#dfe6e1', '#5d7a6b', 'M220 380h360a180 150 0 0 1-360 0z M350 545h100'),
    'vase': ('#efe1dc', '#a3685a', 'M370 220h60v60c48 36 72 96 72 156a102 102 0 0 1-204 0c0-60 24-120 72-156z'),
    'plates': ('#e4e3ec', '#6c6a8c', 'M400 230a170 170 0 1 1 0 340a170 170 0 1 1 0-340z M400 310a90 90 0 1 1 0 180a90 90 0 1 1 0-180z'),
    'teapot': ('#efe8dc', '#8c7350', 'M300 360h200v130a70 70 0 0 1-70 70h-60a70 70 0 0 1-70-70z M340 360a60 40 0 0 1 120 0 M500 400l70-40 M300 410c-50 0-60 70-10 80'),
    'planter': ('#dee8e4', '#4f7563', 'M290 360h220l-30 200h-160z M400 360c0-80 40-120 90-130 M400 360c0-60-30-100-80-110'),
    'cone': ('#e6e9ee', '#566780', 'M290 300h220l-80 170h-60z M370 470h60v40h-60z M300 300h200'),
    'butter': ('#efe8e1', '#9b7b5a', 'M250 470h300v50h-300z M290 470v-80a20 20 0 0 1 20-20h180a20 20 0 0 1 20 20v80 M380 340h40'),
}

with sync_playwright() as p:
    page = p.chromium.launch().new_page(viewport={'width': 800, 'height': 800})
    for name, (bg, ink, path) in ITEMS.items():
        page.set_content(f'''<html><body style="margin:0;background:{bg}">
<svg width="800" height="800" viewBox="0 0 800 800" xmlns="http://www.w3.org/2000/svg">
<ellipse cx="400" cy="600" rx="230" ry="26" fill="{ink}" opacity="0.12"/>
<path d="{path}" fill="none" stroke="{ink}" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/>
</svg></body></html>''')
        page.screenshot(path=f'/out/{name}.png')
print('images ok')
