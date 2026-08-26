from pathlib import Path

from reportlab.pdfgen import canvas


ROOT = Path(r"C:\laragon\www\sistema-pollos")
SLIDES_DIR = ROOT / "tmp" / "presentacion-sistema-avicola-20260824" / "rendered"
OUTPUT = (
    ROOT
    / "output"
    / "pdf"
    / "Sistema_Avicola_Presentacion_Comercial_Gustavo_Noriega.pdf"
)

# 16:9 widescreen page, matching the presentation canvas exactly.
PAGE_WIDTH = 960
PAGE_HEIGHT = 540


def main() -> None:
    slides = sorted(SLIDES_DIR.glob("slide-*.png"))
    if len(slides) != 13:
        raise RuntimeError(f"Expected 13 rendered slides, found {len(slides)}")

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    document = canvas.Canvas(
        str(OUTPUT),
        pagesize=(PAGE_WIDTH, PAGE_HEIGHT),
        pageCompression=1,
        invariant=1,
    )
    document.setTitle("Sistema Avícola — Presentación comercial")
    document.setAuthor("Gustavo Noriega")
    document.setSubject("Control integral de una operación avícola")
    document.setCreator("Sistema Avícola")

    for slide in slides:
        document.drawImage(
            str(slide),
            0,
            0,
            width=PAGE_WIDTH,
            height=PAGE_HEIGHT,
            preserveAspectRatio=False,
            mask="auto",
        )
        document.showPage()

    document.save()
    print(OUTPUT)


if __name__ == "__main__":
    main()
