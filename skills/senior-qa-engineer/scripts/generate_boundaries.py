#!/usr/bin/env python3
"""Print exact numeric boundary candidates, without project dependencies.

Examples:
 python scripts/generate_boundaries.py --minimum 1 --maximum 10 --step 1
 python scripts/generate_boundaries.py --minimum 0.01 --maximum 999.99 --step 0.01

The six candidates are not automatically expected outcomes: derive inclusivity,
validation and units from the product's actual rule. Useful only for numeric ranges.
"""
import argparse
import json
from decimal import Decimal, InvalidOperation


def values(lower: Decimal, upper: Decimal, step: Decimal) -> list[str]:
    if lower > upper:
        raise ValueError("minimum cannot exceed maximum")
    if step <= 0:
        raise ValueError("step must be positive")
    # For narrow ranges these overlap. Deduplicate and use exact decimal math.
    candidates = [lower - step, lower, lower + step,
                  upper - step, upper, upper + step]
    return [str(x) for x in sorted(set(candidates))]


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--minimum", required=True)
    parser.add_argument("--maximum", required=True)
    parser.add_argument("--step", required=True)
    args = parser.parse_args()
    try:
        a, b, delta = (Decimal(x) for x in (args.minimum, args.maximum, args.step))
        if not all(v.is_finite() for v in (a, b, delta)):
            raise ValueError("nonfinite numbers not permitted")
        print(json.dumps({"lower": str(a), "upper": str(b), "step": str(delta),
                          "candidates": values(a, b, delta)}, indent=2))
    except (InvalidOperation, ValueError) as exc:
        parser.error(str(exc))


if __name__ == "__main__":
    main()
