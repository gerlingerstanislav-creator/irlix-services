#!/usr/bin/env python3
"""Summarize Clients failures without ever printing raw log text or bindings."""
import collections
import re
import sys

STATE = re.compile(r'SQLSTATE(?:\[|\s*[:=]\s*)([A-Z0-9]{5})', re.I)
ERROR = re.compile(r'(?<![A-Za-z])([A-Za-z_][A-Za-z0-9_]*(?:Exception|Error))\b')
FRAME = re.compile(r'(ClientContourAccess|ClientsAccountingAccess|ServiceEntitlement|ClientsOverviewPageService|ClientsOverviewService|ClientsController|ClientWorkflowVisibility)\.php(?::|\()(\d{1,5})')
DATE = re.compile(r'20\d{2}-\d{2}-\d{2}')
OBJECT = re.compile(r'(?:column|relation|table) ["\\']([a-z][a-z0-9_]{0,79})["\\']', re.I)

def summarize(lines):
    events = collections.Counter()
    for line in lines:
        state = STATE.search(line)
        frame = FRAME.search(line)
        err = ERROR.search(line)
        if not (state or frame or ('ERROR' in line and err)):
            continue
        key = []
        if state:
            key.append('SQLSTATE=' + state.group(1).upper())
            obj = OBJECT.search(line)
            if obj:
                key.append('object=' + obj.group(1).lower())
        if err:
            key.append('class=' + err.group(1))
        if frame:
            key.append('frame=' + frame.group(1) + ':' + frame.group(2))
        if key:
            events[' '.join(key)] += 1
    return events

if __name__ == '__main__':
    print('Clients read-only error fingerprints (no raw messages, tokens or SQL)')
    events = summarize(sys.stdin)
    for key, count in events.most_common(35):
        print(f'{key} occurrences={count}')
    print(f'fingerprint_groups={len(events)}')
