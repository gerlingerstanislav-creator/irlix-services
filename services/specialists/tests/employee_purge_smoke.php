<?php
/** Synthetic independent professional-history isolation test. */
require __DIR__.'/graded_profile.php';

use App\Services\EmployeePurgeService;

$db->statement('CREATE TABLE specialist_competencies (id INTEGER PRIMARY KEY,employee_id INTEGER,technology_id INTEGER,created_at TEXT,updated_at TEXT)');
$db->table('specialist_competencies')->insert([
    ['id'=>1,'employee_id'=>11,'technology_id'=>1],
    ['id'=>2,'employee_id'=>12,'technology_id'=>2],
]);
$db->table('specialist_profiles')->insert(['employee_id'=>12,'grade'=>'Junior']);
$db->table('specialist_technologies')->insert(['employee_id'=>12,'technology_id'=>2]);
$db->table('specialist_timeline_events')->insert([
    'employee_id'=>12,'type'=>'comment','title'=>'Synthetic coworker note',
    'author_employee_id'=>11,'author'=>'Synthetic Employee 11',
]);
$eraser = new EmployeePurgeService();
$eraser->purge(11, 'Synthetic Employee 11', 'synthetic.11');
$eraser->purge(11, 'Synthetic Employee 11', 'synthetic.11');
$check(!$db->table('specialist_profiles')->where('employee_id',11)->exists()
    && !$db->table('specialist_technologies')->where('employee_id',11)->exists()
    && !$db->table('specialist_competencies')->where('employee_id',11)->exists()
    && !$db->table('specialist_timeline_events')->where('employee_id',11)->exists(),
    'The removed specialist still owns professional data');
$check($db->table('specialist_profiles')->where('employee_id',12)->exists()
    && $db->table('specialist_technologies')->where('employee_id',12)->exists()
    && $db->table('specialist_competencies')->where('employee_id',12)->exists(),
    'The coworker lost their professional profile');
$note=$db->table('specialist_timeline_events')->where('employee_id',12)->first();
$check($note && $note->author_employee_id===null && $note->author===null,
    'A coworker note still identifies the deleted employee');
echo "Specialists full profile and timeline erasure: PASS\n";
