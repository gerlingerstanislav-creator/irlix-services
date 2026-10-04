#!/usr/bin/env python3
"""Restore a Vacations migration snapshot on the deployment host."""

from pathlib import Path
import os

ROOT = Path('/opt/irlix-services')
BASE = Path(os.environ.get('MIGRATION_SNAPSHOT_BASE', str(ROOT / '.ci' / 'migration-test-snapshots'))) / 'vacations'
