<?php
namespace App\Support;
use DomainException;
final class VacationDocumentNames
{
    public static function filename(string $employee, string $type, string $from, string $to, string $key, string $original): string
    {
        $types = ['paid_vacation'=>'Оплачиваемый отпуск','unpaid_vacation'=>'Неоплачиваемый отпуск','sick_leave'=>'Больничный','maternity_leave'=>'Декрет','day_off'=>'Отгул'];
        $label = in_array($type,['paid_vacation','unpaid_vacation'],true) ? 'Заявление' : 'Документ';
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext,['pdf','png','jpg','jpeg','doc','docx'],true)) throw new DomainException('Неподдерживаемый формат исходного документа');
        $name = $label.' — '.$employee.' — '.($types[$type] ?? $type).' — '.$from.'_'.$to;
        $name = preg_replace('/[\x00-\x1f\x7f<>:"\/\\\\|?*]/u',' ', $name);
        $name = trim(preg_replace('/\s+/u',' ', $name), '. ');
        $name = mb_strcut($name, 0, 205, 'UTF-8');
        return $name.' — '.substr(hash('sha256',$key),0,12).'.'.$ext;
    }

}
