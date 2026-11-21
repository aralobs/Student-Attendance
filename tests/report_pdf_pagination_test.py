"""Verify physical pagination and complete text using PyMuPDF (test dependency only).

Run the PHP layout test first, then this script with PyMuPDF available. Production
PDF generation needs only the locked Composer dependencies, not Python.
"""
from pathlib import Path
import sys

root = Path(__file__).resolve().parents[1]
local_tools = root / ".qa" / "pdf-tools"
if local_tools.is_dir():
    sys.path.insert(0, str(local_tools))
import pymupdf

for name in ("normal-sf2", "labelled-sf2", "small-labelled-sf2", "stress-sf2", "empty-sf2", "empty-sf4"):
    document = pymupdf.open(root / ".qa" / "pdf" / (name + ".pdf"))
    for number, page in enumerate(document, 1):
        assert f"Page {number} of {len(document)}" in page.get_text(), (
            f"{name}: footer does not match physical page count"
        )
    text = " ".join(" ".join(page.get_text().split()) for page in document).lower()
    if name == "stress-sf2":
        assert len(document) > 2, "Long remark must exercise automatic overflow"
        assert text.count("deliberately long first name") == 24, "Learner rows lost on overflow"
    else:
        assert len(document) == 1, "The complete form must fit on one page for a normal or empty roster"
    if name == "normal-sf2":
        assert "synthetic saved remark <check>" in text, "Saved remarks must survive PDF escaping"
        assert "signature of school head over printed name" in text, "Full summary and signatures must fit"
    print(f"{name}: {len(document)} physical page(s), correct footers")
