import pathlib
from playwright.sync_api import sync_playwright

base = pathlib.Path(__file__).resolve().parent
src = (base / "audit-risiko.html").as_uri()
out = str(base / "audit-risiko-3-sistem-susenas.pdf")

with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome")
    page = browser.new_page()
    page.goto(src, wait_until="load")
    page.emulate_media(media="print")
    page.pdf(
        path=out,
        format="A4",
        print_background=True,
        display_header_footer=True,
        header_template="<div style='font-size:7px;color:#888;width:100%;padding:0 12mm;'><b>Audit Risiko - 3 Sistem SUSENAS</b></div>",
        footer_template="<div style='font-size:7px;color:#888;width:100%;padding:0 12mm;text-align:right;Halaman <span class='pageNumber'></span> dari <span class='totalPages'></span></div>",
        margin={"top": "16mm", "bottom": "16mm", "left": "12mm", "right": "12mm"},
    )
    browser.close()

print("PDF:", out, pathlib.Path(out).stat().st_size, "bytes")
