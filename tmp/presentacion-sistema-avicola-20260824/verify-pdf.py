from pathlib import Path

import pypdfium2 as pdfium
from pypdf import PdfReader


ROOT = Path(r"C:\laragon\www\sistema-pollos")
PDF_PATH = (
    ROOT
    / "output"
    / "pdf"
    / "Sistema_Avicola_Presentacion_Comercial_Gustavo_Noriega.pdf"
)
RENDER_DIR = ROOT / "tmp" / "presentacion-sistema-avicola-20260824" / "pdf-render"


def main() -> None:
    reader = PdfReader(str(PDF_PATH))
    if len(reader.pages) != 13:
        raise RuntimeError(f"Expected 13 PDF pages, found {len(reader.pages)}")

    expected_ratio = 16 / 9
    for index, page in enumerate(reader.pages, start=1):
        width = float(page.mediabox.width)
        height = float(page.mediabox.height)
        if abs((width / height) - expected_ratio) > 0.001:
            raise RuntimeError(f"Page {index} is not 16:9: {width} x {height}")

    RENDER_DIR.mkdir(parents=True, exist_ok=True)
    document = pdfium.PdfDocument(str(PDF_PATH))
    if len(document) != 13:
        raise RuntimeError(f"Renderer found {len(document)} pages")

    for index in range(len(document)):
        bitmap = document[index].render(scale=2)
        image = bitmap.to_pil().convert("RGB")
        if image.getbbox() is None:
            raise RuntimeError(f"Page {index + 1} rendered blank")
        image.save(RENDER_DIR / f"page-{index + 1:02d}.png", optimize=True)

    metadata = reader.metadata or {}
    print(
        {
            "pages": len(reader.pages),
            "page_size": [
                float(reader.pages[0].mediabox.width),
                float(reader.pages[0].mediabox.height),
            ],
            "title": metadata.get("/Title"),
            "author": metadata.get("/Author"),
            "render_dir": str(RENDER_DIR),
        }
    )


if __name__ == "__main__":
    main()
