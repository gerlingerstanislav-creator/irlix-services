#!/usr/bin/env python3
"""Fail-closed, once-per-UTC-day Migration Control runner.

An automatic import always starts with its own compatible checkpoint. One
durable daily claim survives an ops restart; ambiguous interrupted attempts
are never replayed during the same day. Old databases remain read-only.
"""
import datetime as dt
import os
import time
from pathlib import Path

import migration_console as console
import migration_control as control

STATE = Path('/ops/control-daily.json')
# 23:00 UTC is 03:00 in Ulyanovsk (UTC+04). Only this five-minute
# window is eligible: a deploy/restart at noon must not trigger a data write.
HOUR_UTC = 23
WINDOW_MINUTES = 5


def enabled():
    # A daily legacy-to-target write is deliberately paused while the V2
    # three-way reconciliation is being verified. The old switch alone must
    # not silently reactivate a financial/history-changing nightly import.
    return (os.environ.get('MIGRATION_DAILY_ENABLED', 'false').lower() == 'true'
            and os.environ.get('MIGRATION_DAILY_WRITE_APPROVED', 'false').lower() == 'true')


def read():
    try:
        result = control.read(STATE, {})
    except (OSError, ValueError, TypeError) as exc:
        raise RuntimeError('Daily migration ledger unreadable; launch blocked') from exc
    if not isinstance(result, dict):
        raise RuntimeError('Daily migration ledger invalid; launch blocked')
    return result


def record(**changes):
    state = {**read(), **changes, 'updated_at': time.time()}
    control.atomic(STATE, state)
    return state


def public_state():
    try:
        state = read()
        return {'enabled': enabled(), 'utc_hour': HOUR_UTC,
                'last_date': state.get('date'), 'status': state.get('status'),
                'checkpoint_id': state.get('checkpoint_id'),
                'operation_id': state.get('operation_id')}
    except RuntimeError:
        return {'enabled': enabled(), 'utc_hour': HOUR_UTC, 'status': 'ledger_unavailable'}


def reserve(now, snapshot_operation):
    """Called with the HTTP controller LOCK held, after authoritative idle checks.

    snapshot_operation is a factory so tests can enforce that we never reserve
    a second run on the same UTC date or outside the scheduling window.
    """
    if not enabled():
        return None
    if now.tzinfo is None:
        raise ValueError('UTC-aware clock required')
    now = now.astimezone(dt.timezone.utc)
    if now.hour != HOUR_UTC or now.minute >= WINDOW_MINUTES:
        return None
    date = now.date().isoformat()
    state = read()
    if isinstance(state.get('date'), str) and state['date'] >= date:
        return None
    if console.active():
        return None
    # Never race the standalone snapshot or restore operation.
    op = snapshot_operation()
    try:
        record(date=date, status='snapshot_running', checkpoint_id=None,
               snapshot_operation_id=op['id'], operation_id=None)
    except Exception:
        console.update(op, status='failed', phase='finished',
                       finished_at=time.time(),
                       message='Не удалось сохранить состояние расписания. Снимок не запускался.')
        raise
    return op


def run_reserved(snapshot):
    """Single worker thread: checkpoint -> Control stages -> durable result."""
    try:
        console.execute(snapshot)
        completed = console.state().get('operation') or {}
        if (str(completed.get('id')) != str(snapshot['id'])
                or completed.get('status') != 'completed'):
            record(status='checkpoint_failed')
            return
        snapshot_id = (completed.get('snapshot_ids') or {}).get('all')
        compatible = {item['id'] for item in console.checkpoints('all')
                      if item.get('compatible')}
        if not snapshot_id or str(snapshot_id) not in compatible:
            record(status='checkpoint_unverified')
            return
        record(status='checkpoint_completed', checkpoint_id=str(snapshot_id))
        operation = control.start({'scope': 'all', 'confirm': True,
                                   'requested_by': 'migration-daily'})
        record(status='migration_running', operation_id=operation['id'])
        control.execute(operation)
        latest = control.read(control.directory(operation) / 'operation.json', {})
        if str(latest.get('id')) != str(operation['id']):
            record(status='migration_outcome_unknown')
        else:
            result = latest.get('status')
            record(status=result if result in
                   ('completed', 'failed', 'partial', 'cancelled', 'interrupted')
                   else 'migration_outcome_unknown')
    except Exception as exc:
        # No silent retry: unknown writes, concurrent operations or failed
        # audit storage require investigation. Never log private row content.
        try:
            record(status='scheduler_failed')
        except Exception:
            pass
        print('Daily Migration Control failed: ' + type(exc).__name__, flush=True)
