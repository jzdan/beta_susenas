"""Inc 167 — buat gambar panduan (database/data/panduan/img/*.jpg) dari tangkapan layar halaman asli.

Pakai: python scripts/panduan_gambar.py <url-dasar> '<id JSON>' [kunci ...]
  mis. python scripts/panduan_gambar.py http://127.0.0.1:8010 '{"persiapan":14,"sep":8,"sepqv":8,"doc":740,"sample":789,"rule":116}' jadwal
  ({nama} di url panduan.json diganti dari JSON itu.) shot.fill / shot.select mengisi formulir dulu; shot.wait (ms)
  menunggu periksa langsung selesai.
Butuh Playwright (pip install playwright) + Microsoft Edge; server berisi data contoh LENGKAP dengan aturan
(SEED_ATURAN=true) & akun admin/admin123. Bagian yang dimaksud tiap langkah ('shot.mark' di panduan.json)
diberi kotak oranye bernomor; gambar dipotong ke sekitar bagian itu (tanpa menu samping).
"""
import json
import os
import sys

from playwright.sync_api import sync_playwright

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, "database", "data", "panduan")
OUT = os.path.join(DATA, "img")
# layar kantor umum (1920×1080) — tata letak di gambar sama dengan yang dilihat admin di komputer
W, H = 1920, 1080
CROP_MIN = 1000

MARK_JS = """([x, y, w, h, n]) => {
  document.querySelectorAll('.pg-mark').forEach(e => e.remove());
  const box = document.createElement('div');
  box.className = 'pg-mark';
  Object.assign(box.style, {position: 'fixed', left: (x - 6) + 'px', top: (y - 6) + 'px', width: (w + 12) + 'px',
    height: (h + 12) + 'px', border: '3px solid #e3542b', borderRadius: '12px', zIndex: 99999, pointerEvents: 'none',
    background: 'rgba(227,84,43,.05)', boxShadow: '0 0 0 6px rgba(227,84,43,.18)'});
  const b = document.createElement('div');
  b.textContent = n;
  Object.assign(b.style, {position: 'absolute', left: '-16px', top: '-16px', width: '30px', height: '30px',
    borderRadius: '50%', background: '#e3542b', color: '#fff', font: '700 16px/30px system-ui, sans-serif',
    textAlign: 'center', boxShadow: '0 2px 6px rgba(0,0,0,.3)'});
  box.appendChild(b);
  document.body.appendChild(box);
}"""


def main():
    base, ids = sys.argv[1].rstrip("/"), json.loads(sys.argv[2])
    only = set(sys.argv[3:])
    guides = json.load(open(os.path.join(DATA, "panduan.json"), encoding="utf-8"))
    os.makedirs(OUT, exist_ok=True)
    with sync_playwright() as p:
        b = p.chromium.launch(channel="msedge")
        ctx = b.new_context(viewport={"width": W, "height": H}, device_scale_factor=1)
        pg = ctx.new_page()
        pg.goto(base + "/login")
        pg.fill("input[name=username]", "admin")
        pg.fill("input[name=password]", "admin123")
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        for key, slides in guides.items():
            if key.startswith("_") or (only and key not in only):
                continue
            for n, s in enumerate(slides, 1):
                shot = s.get("shot") or {}
                url = shot.get("url", "/").format(**ids)
                pg.goto(base + url)
                pg.wait_for_load_state("networkidle")
                for sel, val in (shot.get("select") or {}).items():
                    pg.select_option(sel, val)
                for sel, val in (shot.get("fill") or {}).items():
                    pg.fill(sel, val)
                if shot.get("wait"):
                    pg.wait_for_timeout(shot["wait"])
                # tombol panduan & dialognya tidak ikut terpotret
                pg.add_style_tag(content=".panduan-btn, dialog.panduan { display: none !important; }")
                main_box = pg.locator("main").bounding_box()
                loc = pg.locator(shot["mark"]).first if shot.get("mark") else None
                bottom = 560
                if loc is not None and loc.count():
                    loc.evaluate("e => { e.scrollIntoView({block: 'start'}); const m = document.querySelector('main');"
                                 " if (m) m.scrollBy(0, -150); else window.scrollBy(0, -150); }")
                    pg.wait_for_timeout(150)
                    bb = loc.bounding_box()
                    pg.evaluate(MARK_JS, [bb["x"], bb["y"], bb["width"], bb["height"], n])
                    bottom = bb["y"] + bb["height"] + 36
                    # potong pas di sekitar bagian itu (minimal CROP_MIN lebar, supaya masih ada konteks)
                    cx = bb["x"] + bb["width"] / 2
                    width = min(W - main_box["x"], max(CROP_MIN, bb["width"] + 72))
                    left = max(main_box["x"], min(cx - width / 2, W - width))
                    top = max(62, bb["y"] - 70)
                else:
                    print(f"  ! {key}-{n}: bagian '{shot.get('mark')}' tidak ditemukan — gambar tanpa penanda")
                    left, width, top = main_box["x"], W - main_box["x"], 62
                height = max(300, min(H - top, bottom - top))
                path = os.path.join(OUT, s["image"])
                pg.screenshot(path=path, type="jpeg", quality=84,
                              clip={"x": left, "y": top, "width": width, "height": height})
                print(f"  {s['image']}  ({os.path.getsize(path) // 1024} KB)")
        b.close()


if __name__ == "__main__":
    main()
