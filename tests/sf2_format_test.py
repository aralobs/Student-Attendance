"""Catch SF2 downloads replacing the original form with a simplified report."""
from pathlib import Path
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(root / ".qa" / "pdf-tools"))
import pymupdf

subprocess.run(["php", "tests/report_pdf_download_test.php"], cwd=root, check=True)
document = pymupdf.open(root / ".qa" / "pdf" / "synthetic-sf2.pdf")
text = " ".join(" ".join(page.get_text().split()) for page in document)
for expected in (
    "GUIDELINES:",
    "REASONS/CAUSES FOR NLS",
    "Domestic-Related Factors",
    "Geographic/Environmental",
    "Generated thru LIS",
    "Attested by:",
    "Total for the Month",
    "School ID",
    "Name of School",
):
    assert expected in text, f"Original SF2 content missing from download: {expected}"
assert len(document) == 1, "A small roster should keep the complete original form on one page"
print("Original SF2 headings, legend, guidelines, metadata and signatures preserved.")
