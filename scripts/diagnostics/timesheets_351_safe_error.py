#!/usr/bin/env python3
"""Read-only safe classification of Timesheets migration run 351 (no raw errors or PII)."""
import json, os, re, sqlite3, sys
sys.path.insert(0, "/opt/irlix-services/scripts")
import migration_control as control

con = sqlite3.connect("file:/data/migration.sqlite?mode=ro", uri=True, timeout=5)
con.row_factory = sqlite3.Row
row = con.execute("SELECT id, service, mode, status, progress_phase, processed_count, error FROM migration_runs WHERE id = ?", (351,)).fetchone()
if row is None:
    print(json.dumps({"probe": "run_351", "found": False}))
    sys.exit(2)
raw = row["error"] or ""
fixed = {
    "TIMESHEETS_MEMORY_PRESSURE": r"TIMESHEETS_MEMORY_PRESSURE",
    "MEMORY_EXHAUSTED": r"Allowed memory size|Out of memory|Cannot allocate memory",
    "DEPENDENCY_PLAN_CHANGED": r"dependency plan is missing or changed since dry-run",
    "CONTROL_DRYRUN_MISSING": r"нет завершённого без конфликтов Dry run|requires a completed dry-run",
    "SOURCE_BASELINE_CHANGED": r"Legacy Timesheets differ from the completed dry-run|Source Timesheets changed|batch inventory differs",
    "SOURCE_ROW_CONFLICT": r"SourceRowConflict|SOURCE_ROW_UNRESOLVED",
    "TARGET_TERM_GAP": r"TARGET_TERM_GAP|У подтверждённого подключения отсутствуют условия",
    "TABLE_MISSING": r"no such table|does not exist|Unknown column|Undefined table",
    "DATABASE_DEADLOCK": r"deadlock|database is locked",
    "DATABASE_CONNECTION": r"SQLSTATE\[080|could not connect|Connection refused",
    "PHP_TYPE_ERROR": r"TypeError|ArgumentCountError",
    "SQLITE_SPOOL": r"TIMESHEETS_SPOOL_UNAVAILABLE|database or disk is full|SQLite database",
    "MAPPING_COLLISION": r"IDENTITY_MAPPING_COLLISION|MAPPING_TARGET_MISSING",
    "PHP_EXCEPTION": r"Exception|RuntimeException|PDOException|ErrorException",
    "NO_ERROR_DETAILS": r"^$",
}
matches = [k for k,v in fixed.items() if re.search(v,raw,re.I)]
print(json.dumps({"probe":"run_351","found":True,"mode":row["mode"],"status":row["status"],
"phase":row["progress_phase"],"processed":row["processed_count"],"error_nonempty":bool(raw),
"error_chars":len(raw),"error_kind":control.classify_worker_failure(raw),"signatures":matches},sort_keys=True))
for table in ("migration_table_progress","migration_batch_progress","migration_conflicts","migration_run_events"):
    cols=[x[1] for x in con.execute("PRAGMA table_info("+table+")")]
    key="migration_run_id"
    if key in cols:
        count=con.execute("SELECT COUNT(*) FROM "+table+" WHERE "+key+"=?", (351,)).fetchone()[0]
        print(json.dumps({"probe":"run_351_table","table":table,"count":count},sort_keys=True))
        if table=="migration_table_progress":
            fields=[name for name in ("table_name","processed_count","total_count","status") if name in cols]
            if fields:
                for rec in con.execute("SELECT "+",".join(fields)+" FROM "+table+" WHERE "+key+"=?",(351,)):
                    print(json.dumps({"probe":"progress", **dict(rec)},sort_keys=True))
        if table=="migration_run_events" and "event" in cols:
            events=con.execute("SELECT event, count(*) AS n FROM "+table+" WHERE "+key+"=? GROUP BY event",(351,)).fetchall()
            print(json.dumps({"probe":"event_types","counts":{str(x["event"]):x["n"] for x in events}},sort_keys=True))
print(json.dumps({"probe":"readonly_complete","no_writes":True}))
