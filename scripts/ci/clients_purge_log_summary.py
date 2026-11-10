#!/usr/bin/env python3
"""Emit only non-personal error fingerprints from the Clients service logs.

The raw input may contain employee names, tokens, SQL parameter values or other
personal information. Never print the input or an unbounded exception message.
"""
import collections
import re
import sys

STATE = re.compile(r'SQLSTATE(?:\[|\s*[:=]\s*)([A-Z0-9]{5})', re.I)
CONSTRAINT = re.compile(r'constraint ["\']([a-z][a-z0-9_]{0,79})["\']', re.I)
OBJECT = re.compile(r'(?:column|relation|table) ["\']([a-z][a-z0-9_]{0,79})["\']', re.I)
EXCEPTION = re.compile(r'(?<![A-Za-z])((?:[A-Za-z_][A-Za-z0-9_]*\\)*[A-Za-z_][A-Za-z0-9_]*(?:Exception|Error))\b')
FRAME = re.compile(r'(EmployeePurgeService|EmployeeReportingSnapshotPurge|EmployeePurgeController)\.php(?::|\()(\d{1,5})')
DATE = re.compile(r'20\d{2}-\d{2}-\d{2}')


def summarize(lines):
    fingerprints = collections.Counter()
    dates = {}
    current_date = 'unknown'
    for line in lines:
        day = DATE.search(line[:100])
        if day:
            current_date = day.group()
        state = STATE.search(line)
        # Only extract fields matching restricted identifiers, never SQL or messages.
        constraint = CONSTRAINT.search(line) if state else None
        obj = OBJECT.search(line) if state else None
        error = EXCEPTION.search(line)
        frame = FRAME.search(line)
        if not (state or frame or ('ERROR' in line and error)):
            continue
        category = ('SQLSTATE=' + state.group(1).upper()) if state else ('TYPE=' + error.group(1).split('\\')[-1] if error else 'PURGE_STACK')
        detail = (' constraint=' + constraint.group(1).lower()) if constraint else ''
        detail += (' object=' + obj.group(1).lower()) if obj else ''
        if frame:
            detail += ' frame=' + frame.group(1) + ':' + frame.group(2)
        key = category + detail
        fingerprints[key] += 1
        dates[key] = current_date
    return fingerprints, dates


def main():
    fingerprints, dates = summarize(sys.stdin)
    print('Clients diagnostic: restricted error fingerprints only; no raw logs or personal data')
    if not fingerprints:
        print('No recognizable SQLSTATE / exception / purge stack in retrieved log window')
        return
    for fingerprint, count in fingerprints.most_common(30):
        print(f'{fingerprint} occurrences={count} last_seen={dates[fingerprint]}')
    print(f'fingerprint_groups={len(fingerprints)}')


if __name__ == '__main__':
    main()
