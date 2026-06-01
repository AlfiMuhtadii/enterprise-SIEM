"""
Round-2 patch: fix w:t elements inside TOC hyperlinks that doc.paragraphs cannot reach.
Reads V4 docx, patches document.xml in-place, saves back.
"""
import zipfile
import shutil
import re
import os

SRC = r"D:\project\Detector\docs\thesis\Laporan KP.V4.docx"
TMP = r"D:\project\Detector\docs\thesis\Laporan KP.V4.tmp.docx"

with zipfile.ZipFile(SRC, "r") as zin:
    content = zin.read("word/document.xml").decode("utf-8")

original = content

# ---------------------------------------------------------------------------
# 1. DAFTAR TABLE -> DAFTAR TABEL  (inside <w:t> elements)
# ---------------------------------------------------------------------------
content = content.replace(">DAFTAR TABLE<", ">DAFTAR TABEL<")

# ---------------------------------------------------------------------------
# 2. TOC double-dot: 5..N -> 5.N  (inside <w:t> elements)
# ---------------------------------------------------------------------------
content = re.sub(r"(?<=>)5\.\.(\d)(?=<)", r"5.\1", content)

# ---------------------------------------------------------------------------
# 3. Table X.Y... -> Tabel X.Y...  (inside <w:t> elements, TOC list entries)
# ---------------------------------------------------------------------------
# Match ">Table " followed by a digit inside XML
content = re.sub(r"(?<=>)Table (\d)", r"Tabel \1", content)

# ---------------------------------------------------------------------------
# Verify changes
# ---------------------------------------------------------------------------
count_daftar  = content.count("DAFTAR TABLE")
count_double  = len(re.findall(r"5\.\.\d", content))
count_table_e = len(re.findall(r">Table \d", content))

print(f"Remaining 'DAFTAR TABLE': {count_daftar}  (expected 0)")
print(f"Remaining '5..N':         {count_double}   (expected 0)")
print(f"Remaining '>Table <dig>': {count_table_e}  (expected 0)")

if content == original:
    print("\nWARNING: No changes made to XML.")
else:
    changed_bytes = sum(1 for a, b in zip(original, content) if a != b)
    print(f"\nXML bytes changed: ~{changed_bytes}")

# ---------------------------------------------------------------------------
# Write patched docx
# ---------------------------------------------------------------------------
shutil.copy2(SRC, TMP)  # work on a copy

with zipfile.ZipFile(TMP, "a") as zout:
    # 'a' mode in Python's zipfile doesn't allow overwriting.
    # We need to rebuild the zip properly.
    pass

# Rebuild the zip from scratch
with zipfile.ZipFile(SRC, "r") as zin:
    with zipfile.ZipFile(TMP, "w", compression=zipfile.ZIP_DEFLATED) as zout:
        for item in zin.infolist():
            if item.filename == "word/document.xml":
                zout.writestr(item, content.encode("utf-8"))
            else:
                zout.writestr(item, zin.read(item.filename))

os.replace(TMP, SRC)
print(f"\nPatched and saved: {SRC}")
