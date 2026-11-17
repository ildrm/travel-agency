"""Validate documentation inventory and references; does not test application behavior."""
from pathlib import Path
import json
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
source = (ROOT / "_sources/ORIGINAL-PROMPT.md").read_text()
expected = list(dict.fromkeys(re.findall(r"`(DOC-\d{2}_[A-Z0-9-]+\.md)`", source)))
errors = []
warnings = []
documents = []
all_text = {}
id_refs = {}
local_links = []
required_headers = ["Purpose", "Prepared from perspectives", "Accountable reviewer", "Dependencies", "Approval gate", "Status"]
if len(expected) != 49:
    errors.append(f"Original registry yielded {len(expected)} files, expected 49")
for filename in expected:
    path = ROOT / filename
    if not path.exists():
        errors.append(f"Missing {filename}")
        continue
    content = path.read_text()
    all_text[filename] = content
    word_count = len(content.split())
    if word_count < 500:
        errors.append(f"{filename}: too short for requested substantive document ({word_count} whitespace words)")
    for header in required_headers:
        if header.lower() not in content.lower():
            errors.append(f"{filename}: missing {header}")
    if not re.search(r"self.assessment", content, re.I):
        errors.append(f"{filename}: missing self-assessment")
    if sum(1 for line in content.splitlines() if line.startswith("```")) % 2:
        errors.append(f"{filename}: unbalanced fenced code block")
    for link in re.findall(r"\]\(([^)]+)\)", content):
        target = link.strip("<>").split("#", 1)[0]
        if not target or re.match(r"[a-z]+://", target):
            continue
        local_links.append((filename, target, path.parent / target))
    dependencies = []
    for target in re.findall(r"\]\((DOC-\d{2}_[^)]+\.md)\)", content):
        if target != filename and target not in dependencies:
            dependencies.append(target)
    documents.append({"id": filename[:6], "filename": filename,
                      "status": "draft_written_review_pending", "word_count": word_count,
                      "references": dependencies})
    for identifier in set(re.findall(r"\b(?:FR|NFR|ENT|WF|UI|API|TC|RISK|ADR)-[A-Z0-9]+(?:-[A-Z0-9]+)*\b", content)):
        id_refs.setdefault(identifier, []).append(filename)

contract = (ROOT / "_meta/AUTHORING-CONTRACT.md").read_text()
functional = sorted(set(re.findall(r"\bFR-[A-Z0-9]+-001\b", contract)))
nonfunctional = sorted(set(re.findall(r"\bNFR-[A-Z0-9]+-001\b", contract)))
entities = sorted(set(re.findall(r"\bENT-[A-Z]+\b", contract)))
for identifier in functional + nonfunctional:
    for filename in ["DOC-07_BRD-SRS.md", "DOC-46_TRACEABILITY-MATRIX.md"]:
        if identifier not in all_text.get(filename, ""):
            errors.append(f"{identifier} missing from {filename}")
for identifier in entities:
    if identifier not in all_text.get("DOC-22_DATA-MODEL-ERD-DICTIONARY.md", ""):
        errors.append(f"{identifier} missing from data dictionary")
for number in range(1, 21):
    for identifier, filename in [(f"ADR-{number:03}", "DOC-06_TERMINOLOGY-RULES-DECISIONS.md"),
                                 (f"RISK-{number:03}", "DOC-45_RISKS-DECISIONS-CONTROLS.md")]:
        if identifier not in all_text.get(filename, ""):
            errors.append(f"{identifier} missing from {filename}")
for letter, docnum in [("A",30),("B",31),("C",29),("D",32),("E",34),("F",33),("G",35),("H",37)]:
    text = next((text for name,text in all_text.items() if name.startswith(f"DOC-{docnum:02}_")), "")
    if not re.search(rf"Scenario {letter}\b", text, re.I):
        errors.append(f"Missing Scenario {letter} in DOC-{docnum}")

actual = {path.name for path in ROOT.glob("DOC-*.md")}
if actual - set(expected):
    errors.append(f"Unexpected DOC files: {sorted(actual - set(expected))}")
manifest = {"package": "Atlas Leisure Ecosystem", "baseline_date": "2026-11-17",
            "source": "_sources/ORIGINAL-PROMPT.md", "state": "draft_specification_review_pending",
            "document_count": len(documents), "word_count": sum(d["word_count"] for d in documents),
            "implementation_test_status": "not_implemented_not_executed",
            "documents": documents}
(ROOT / "_meta/manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
(ROOT / "_meta/id-registry.json").write_text(json.dumps({
    "meaning": "Reference index, not an assertion of one definition or semantic correctness",
    "canonical_FR": functional, "canonical_NFR": nonfunctional, "canonical_entities": entities,
    "references": dict(sorted(id_refs.items()))}, indent=2) + "\n")
for filename, target, path in local_links:
    if not path.exists():
        errors.append(f"{filename}: broken relative link {target}")
result = {"documents": len(documents), "words": manifest["word_count"],
          "FR": len(functional), "NFR": len(nonfunctional), "entities": len(entities),
          "referenced_IDs": len(id_refs), "errors": errors, "warnings": warnings}
(ROOT / "_meta/validation-results.json").write_text(json.dumps(result, indent=2) + "\n")
print(json.dumps(result, indent=2))
sys.exit(bool(errors))
