#!/usr/bin/env python3
"""Visual/DOM smoke test of four synthetic test interfaces using local Chromium.
Only verifies a bounded list of seeded defects. NOT complete accessibility or usability testing.
Run: python scripts/inspect_fixtures.py
"""
from pathlib import Path
from playwright.sync_api import sync_playwright
import json

ROOT = Path(__file__).resolve().parents[1]
FIX = ROOT / "tests" / "fixtures"
OUT = ROOT / "tests" / "results"
OUT.mkdir(parents=True, exist_ok=True)
CASES = {
    "dense-timesheet": {"width": 375, "checks": ["tiny-target", "nontext-status", "clipped-table"]},
    "mobile-preorder": {"width": 375, "checks": ["allergen-conflict", "seat-no-selected-state", "small-pay-target"]},
    "transfer-form": {"width": 390, "checks": ["unlabeled-amount", "premature-success", "no-validation"]},
    "warehouse-tablet": {"width": 800, "checks": ["tiny-target", "mouse-only-element", "nontext-status"]},
}


def inspect(page, case):
    stats = page.evaluate("""() => {
      const css = s => Array.from(document.querySelectorAll(s));
      const rect = e => {const r = e.getBoundingClientRect(); return {w:Math.round(r.width), h:Math.round(r.height)}};
      return {
        viewport: innerWidth,
        documentWidth: document.documentElement.scrollWidth,
        buttons: css('button').map(e=>({name:e.textContent.trim(), ...rect(e), ariaPressed:e.getAttribute('aria-pressed')})),
        inputs: css('input').map(e=>({name:e.getAttribute('aria-label'), id:e.id, hasLabel: !!(e.labels && e.labels.length)})),
        clickableDivs: css('div[onclick]').map(e=>({tag:e.tagName,tabindex:e.getAttribute('tabindex'),role:e.getAttribute('role')})),
        bareDots: css('.dot,.signal').filter(e=>!e.textContent.trim()&&!e.getAttribute('aria-label')).length,
        clippedTable: css('.tablewrap').some(e=>getComputedStyle(e).overflowX==='hidden' && e.scrollWidth>e.clientWidth),
        allergenConflict: document.body.innerText.includes('Содержит орехи') && document.body.innerText.includes('Аллергии из профиля: орехи'),
        hasAllergyBlock: css('[role=alert]').length > 0,
        seats: css('.seat').map(e=>({pressed:e.getAttribute('aria-pressed'), selected:e.classList.contains('selected')})),
        amountHasLabel: !!(document.getElementById('amount')?.labels?.length),
        liveRegions: css('[role=status],[aria-live]').length
      }
    }""")
    checks = {}
    checks["tiny-target"] = any(b["w"] < 24 or b["h"] < 24 for b in stats["buttons"])
    checks["nontext-status"] = stats["bareDots"] > 0
    checks["clipped-table"] = stats["clippedTable"]
    checks["allergen-conflict"] = stats["allergenConflict"] and not stats["hasAllergyBlock"]
    checks["seat-no-selected-state"] = any(s["selected"] and s["pressed"] is None for s in stats["seats"])
    checks["small-pay-target"] = any(b["name"] == "Оплатить" and b["h"] < 44 for b in stats["buttons"])
    checks["unlabeled-amount"] = not stats["amountHasLabel"] and case == "transfer-form"
    checks["no-validation"] = case == "transfer-form" and not page.locator("#amount").get_attribute("required")
    if case == "transfer-form":
        page.locator("#amount").fill("")
        page.locator("#send").click()
        checks["premature-success"] = page.locator("#done").is_visible() and stats["liveRegions"] == 0
    else:
        checks["premature-success"] = False
    checks["mouse-only-element"] = bool(stats["clickableDivs"]) and all(x["tabindex"] is None and x["role"] is None for x in stats["clickableDivs"])
    return stats, checks


def main():
    result = {}
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path="/usr/bin/chromium", args=["--no-sandbox"])
        for case, spec in CASES.items():
            page = browser.new_page(viewport={"width": spec["width"], "height": 700}, device_scale_factor=1)
            page.set_content((FIX / (case+".html")).read_text(encoding="utf-8"), wait_until="load")
            page.screenshot(path=str(OUT / (case+".png")), full_page=True)
            stats, checks = inspect(page, case)
            expected = spec["checks"]
            missing = [x for x in expected if checks[x] is not True]
            result[case] = {"expected": expected, "detected": {x:checks[x] for x in expected}, "pass": not missing, "observations": stats}
            print(("PASS" if not missing else "FAIL"), case, "detected", len(expected)-len(missing), "/",len(expected))
            page.close()
        browser.close()
    (OUT/"dom-smoke-results.json").write_text(json.dumps(result,indent=2,ensure_ascii=False),encoding="utf-8")
    if not all(x["pass"] for x in result.values()):
        raise SystemExit(1)


if __name__ == "__main__":
    main()
