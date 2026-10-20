<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;

/**
 * Import only the structure of the three known worksheets. No source values are logged
 * or committed to the repository. The uploaded workbook is parsed in memory.
 */
final class EquipmentWorkbookImport
{
    private const TYPES = ['ПК'=>'pc','Ноутбук'=>'laptop','Смартфон'=>'smartphone','Планшет'=>'tablet'];
    private const PURPOSES = ['Разработка'=>'development','Менеджмент + QA'=>'management_qa','QA'=>'qa','Менеджмент'=>'management'];
    private const MAX_ERRORS = 120;

    public function run(Request $request, bool $apply): array
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) throw new RuntimeException('Файл не получен.');
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($file->getRealPath());
        try {
            foreach (['Словарь','Техника','Выдача'] as $name) {
                if (!$workbook->sheetNameExists($name)) throw new RuntimeException("Отсутствует лист «{$name}».");
            }
            $devices = $workbook->getSheetByName('Техника')->toArray(null, true, true, false);
            $issues = $workbook->getSheetByName('Выдача')->toArray(null, true, true, false);
            $dictionary = $workbook->getSheetByName('Словарь')->toArray(null, true, true, false);
            $this->checkHeaders($devices, ['Инв. номер','Тип','Изготовитель','Модель','Серийный номер']);
            $this->checkHeaders($issues, ['Инв. номер','Серийный номер','Выдан','Дата выдачи','Дата сдачи']);
            // Read the dictionary to validate the intended format, not the calculated count columns.
            if (!in_array('Назначение', $dictionary[1] ?? [], true)
                || !in_array('Изготовитель', $dictionary[1] ?? [], true)) {
                throw new RuntimeException('Лист «Словарь» не соответствует ожидаемой структуре.');
            }
            $employees = $this->employees($request);
            $byName = [];
            foreach ($employees as $person) {
                $name = $this->normalize($person['full_name'] ?? '');
                if ($name !== '') $byName[$name][] = (int) $person['id'];
            }
            $report = [
                'devices_total'=>0,'devices_created'=>0,'devices_existing'=>0,
                'assignments_total'=>0,'assignments_created'=>0,'assignments_existing'=>0,
                'skipped'=>0,'issues'=>[],
            ];
            $operation = function () use ($devices,$issues,$byName,$request,$apply,&$report) {
                $itemsByInventory = [];
                $itemsBySerial = [];
                $seenInventory = [];
                foreach (array_slice($devices, 1) as $index => $row) {
                    $line = $index+2;
                    $inventory = $this->cell($row[0] ?? null);
                    if ($inventory === '') continue;
                    $report['devices_total']++;
                    if (isset($seenInventory[$inventory])) {
                        $this->issue($report,'Техника',$line,'Повторяющийся инвентарный номер в файле.');
                        continue;
                    }
                    $seenInventory[$inventory] = true;
                    $type = self::TYPES[$this->cell($row[1] ?? null)] ?? null;
                    $purposeName = $this->cell($row[9] ?? null);
                    $purpose = self::PURPOSES[$purposeName] ?? 'unknown';
                    $manufacturer = $this->cell($row[2] ?? null);
                    $model = $this->cell($row[3] ?? null);
                    if (!$type || $manufacturer === '' || $model === '') {
                        $this->issue($report, 'Техника', $line, 'Не определён тип, изготовитель или модель.');
                        continue;
                    }
                    $serial = $this->cell($row[4] ?? null);
                    $q = DB::table('equipment_items')->where('inventory_number',$inventory);
                    $item = $q->first();
                    if (!$item && $serial !== '') {
                        $matching = DB::table('equipment_items')->where('serial_number',$serial)->limit(2)->get();
                        if ($matching->count() === 1) $item = $matching->first();
                        elseif ($matching->count() > 1) {
                            $this->issue($report,'Техника',$line,'Серийный номер найден у нескольких устройств.');
                            continue;
                        }
                    }
                    if ($item && $serial !== '' && $item->serial_number && (string)$item->serial_number !== $serial) {
                        $this->issue($report,'Техника',$line,'Инвентарный номер совпадает, но серийный номер отличается.');
                        continue;
                    }
                    if ($item) {
                        $report['devices_existing']++;
                        $id = (int)$item->id;
                    } else {
                        $fields = [
                            'inventory_number'=>$inventory, 'type'=>$type, 'manufacturer'=>$manufacturer,
                            'model'=>$model, 'serial_number'=>$serial ?: null, 'purpose'=>$purpose,
                            'cpu'=>$this->nullable($row[5] ?? null), 'ram'=>$this->nullable($row[6] ?? null),
                            'storage'=>$this->nullable($row[7] ?? null), 'gpu'=>$this->nullable($row[8] ?? null),
                            'os'=>$this->nullable($row[11] ?? null),'os_version'=>$this->nullable($row[12] ?? null),
                            'purchased_on'=>$this->date($row[16] ?? null),
                            'purchase_cost'=>$this->numeric($row[17] ?? null),
                            'condition'=>$this->cell($row[19] ?? null) !== '' ? 'damaged' : 'ok',
                            'comment'=>$this->nullable($row[19] ?? null),
                            'useful_life_months'=>$type==='pc'?48:36,
                            'created_at'=>now(),'updated_at'=>now(),
                        ];
                        $id = $apply ? DB::table('equipment_items')->insertGetId($fields) : -$line;
                        $report['devices_created']++;
                        if ($apply) $this->audit($id,'xlsx_import_created',$request);
                    }
                    $itemsByInventory[$inventory] = $id;
                    if ($serial !== '') $itemsBySerial[$serial] = $id;
                    // Write-offs require an explicit separate workflow; imported dates are not
                    // silently treated as an authorization to write off equipment.
                    if ($this->cell($row[22] ?? null) !== '') {
                        $this->issue($report,'Техника',$line,'Есть дата вывода из обращения: требуется отдельная проверка списания.');
                    }
                }
                foreach (array_slice($issues, 1) as $index => $row) {
                    $line=$index+2;
                    $inventory=$this->cell($row[0]??null);
                    $serial=$this->cell($row[1]??null);
                    $name=$this->cell($row[2]??null);
                    if ($inventory==='' && $serial==='' && $name==='') continue;
                    $report['assignments_total']++;
                    $itemId=$itemsByInventory[$inventory]??$itemsBySerial[$serial]??null;
                    if (!$itemId) {
                        $this->issue($report,'Выдача',$line,'Устройство не найдено на листе «Техника».');
                        continue;
                    }
                    $matches=$byName[$this->normalize($name)]??[];
                    if (count($matches)!==1) {
                        $this->issue($report,'Выдача',$line,'Не удалось однозначно сопоставить сотрудника с Employees.');
                        continue;
                    }
                    $from=$this->date($row[3]??null);
                    $to=$this->date($row[4]??null);
                    if (!$to && $this->cell($row[6]??null)==='0') {
                        $this->issue($report,'Выдача',$line,'В файле выдача завершена, но дата возврата отсутствует.');
                        continue;
                    }
                    if (!$from || ($to && $to < $from)) {
                        $this->issue($report,'Выдача',$line,'Некорректные даты выдачи или возврата.');
                        continue;
                    }
                    $employeeId=$matches[0];
                    $comment=$this->nullable($row[7]??null);
                    if ($itemId < 0) {
                        // Preview must not create any records.
                        $report['assignments_created']++;
                        continue;
                    }
                    $exists=DB::table('equipment_assignments')
                        ->where('equipment_item_id',$itemId)->where('employee_id',$employeeId)
                        ->whereDate('starts_on',$from)->exists();
                    if ($exists) { $report['assignments_existing']++;continue; }
                    $overlap=DB::table('equipment_assignments')
                        ->where('equipment_item_id',$itemId)
                        ->whereDate('starts_on','<=',$to??'9999-12-31')
                        ->where(fn($q)=>$q->whereNull('returned_on')->orWhereDate('returned_on','>=',$from))
                        ->exists();
                    if ($overlap) {
                        $this->issue($report,'Выдача',$line,'Период пересекается с существующей выдачей.');
                        continue;
                    }
                    $report['assignments_created']++;
                    if ($apply) {
                        DB::table('equipment_assignments')->insert([
                            'equipment_item_id'=>$itemId,'employee_id'=>$employeeId,
                            'starts_on'=>$from,'returned_on'=>$to,'issued_by'=>'xlsx-import',
                            'returned_by'=>$to?'xlsx-import':null,
                            'issue_comment'=>$comment,'created_at'=>now(),'updated_at'=>now(),
                        ]);
                        $this->audit($itemId,'xlsx_import_assignment',$request);
                    }
                }
            };
            if ($apply) DB::transaction($operation);
            else $operation();
            return $report;
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    private function employees(Request $request): array
    {
        $base=rtrim((string)env('EMPLOYEES_URL','http://employees:8000/api'),'/');
        $token=$request->bearerToken()?:$request->header('X-Irlix-Access-Token');
        $result=Http::withToken((string)$token)->timeout(15)->get("{$base}/equipment-directory", ['include_former'=>1]);
        if (!$result->successful()) throw new RuntimeException('Не удалось получить справочник сотрудников.');
        return $result->json('data')??[];
    }

    private function checkHeaders(array $rows,array $required): void
    {
        $headers=array_map(fn($v)=>$this->cell($v),$rows[0]??[]);
        foreach($required as $name) if (!in_array($name,$headers,true)) {
            throw new RuntimeException("Отсутствует колонка «{$name}».");
        }
    }

    private function cell(mixed $value): string
    {
        if ($value===null) return '';
        if (is_numeric($value) && preg_match('/^\d+\.0$/',(string)$value)) return substr((string)$value,0,-2);
        return trim((string)$value);
    }
    private function nullable(mixed $value): ?string { return ($v=$this->cell($value))===''?null:$v; }
    private function normalize(string $value): string { return mb_strtolower(preg_replace('/\s+/u',' ',trim($value))); }
    private function numeric(mixed $value): ?float {
        $value=str_replace([' ',' ',','],['','','.'],$this->cell($value));
        return is_numeric($value)?max(0,(float)$value):null;
    }
    private function date(mixed $value): ?string
    {
        if ($value===null || $value==='') return null;
        try {
            if (is_numeric($value)) return Date::excelToDateTimeObject((float)$value)->format('Y-m-d');
            return Carbon::parse((string)$value)->format('Y-m-d');
        } catch (\Throwable) { return null; }
    }
    private function issue(array &$report,string $sheet,int $row,string $message): void
    {
        $report['skipped']++;
        if (count($report['issues']) < self::MAX_ERRORS) {
            $report['issues'][]=['sheet'=>$sheet,'row'=>$row,'message'=>$message];
        }
    }
    private function audit(int $itemId,string $event,Request $request): void
    {
        DB::table('equipment_audit_log')->insert([
            'equipment_item_id'=>$itemId,'event'=>$event,
            'actor'=>(string)($request->attributes->get('identity')['preferred_username']??'xlsx-import'),
            'payload'=>json_encode(['source'=>'uploaded-workbook'],JSON_UNESCAPED_UNICODE),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
}
