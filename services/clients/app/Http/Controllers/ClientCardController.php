<?php

namespace App\Http\Controllers;

use App\Services\ClientCardReadService;
use App\Services\ClientCardWriteService;
use Illuminate\Http\Request;

class ClientCardController extends Controller
{
    private function writes(): ClientCardWriteService
    {
        return app(ClientCardWriteService::class);
    }

    public function show(int $client)
    {
        return response()->json(['data' => app(ClientCardReadService::class)->show($client)]);
    }

    public function updateProject(Request $request, int $project)
    {
        $row = $this->writes()->project($project);
        abort_unless($row, 404, 'Project not found');
        abort_if((bool) $row->is_default, 422, 'Основной проект нельзя переименовать.');
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        return response()->json(['data' => $this->writes()->renameProject($project, trim($data['name']))]);
    }

    public function destroyProject(int $project)
    {
        $this->writes()->deleteProject($project);
        return response()->noContent();
    }

    public function update(Request $request, int $client)
    {
        abort_unless($this->writes()->clientExists($client), 404, 'Client not found');
        $data = $request->validate([
            'name'=>['sometimes','required','string','max:255'],
            'type'=>['sometimes','nullable','string','max:100'],
            'sector'=>['sometimes','nullable','string','max:150'],
            'sales_employee_id'=>['prohibited'],
            'account_employee_id'=>['prohibited'],
            'description'=>['sometimes','nullable','string','max:20000'],
            'act_approval_days'=>['sometimes','nullable','integer','min:0','max:3650'],
            'payment_days'=>['sometimes','nullable','integer','min:0','max:3650'],
            'act_approval_day_type'=>['sometimes','required','in:working,calendar'],
            'payment_day_type'=>['sometimes','required','in:working,calendar'],
            'overtime_multiplier'=>['sometimes','required','numeric','gt:0','max:99999.999','decimal:0,3'],
            'technologies'=>['sometimes','array'],
            'technologies.*'=>['string','max:100'],
        ]);
        if (array_key_exists('technologies', $data)) {
            $data['technologies'] = json_encode(
                array_values(array_unique(array_filter(array_map('trim', $data['technologies'])))),
                JSON_UNESCAPED_UNICODE
            );
        }
        return response()->json(['data' => $this->serializeClient(
            $this->writes()->updateClient($client, $data,
                $request->attributes->get('identity', [])['preferred_username'] ?? null)
        )]);
    }

    public function updateContact(Request $request, int $contact)
    {
        abort_unless($this->writes()->contactExists($contact), 404, 'Contact not found');
        $data = $request->validate([
            'full_name'=>['sometimes','required','string','max:255'],
            'position'=>['sometimes','nullable','string','max:255'],
            'phone'=>['sometimes','nullable','string','max:100'],
            'email'=>['sometimes','nullable','email','max:255'],
        ]);
        return response()->json(['data' => $this->writes()->updateContact($contact, $data)]);
    }

    public function storeLegalEntity(Request $request, int $client)
    {
        abort_unless($this->writes()->clientExists($client), 404, 'Client not found');
        $data = $request->validate($this->legalEntityRules(false));
        return response()->json(['data' => $this->writes()->storeLegalEntity(
            $client, $this->normalizeLegalEntity($data)
        )], 201);
    }

    public function updateLegalEntity(Request $request, int $entity)
    {
        abort_unless($this->writes()->legalEntityExists($entity), 404, 'Legal entity not found');
        $data = $request->validate($this->legalEntityRules(true));
        return response()->json(['data' => $this->writes()->updateLegalEntity(
            $entity, $this->normalizeLegalEntity($data)
        )]);
    }

    public function storeNote(Request $request, int $client)
    {
        abort_unless($this->writes()->clientExists($client), 404, 'Client not found');
        $data = $request->validate(['text' => ['required', 'string', 'max:20000']]);
        $text = trim($data['text']);
        abort_if($text === '', 422, 'Note text is required');
        $identity = $request->attributes->get('identity', []);
        return response()->json(['data' => $this->writes()->storeNote(
            $client, $text, $identity['preferred_username'] ?? null
        )], 201);
    }

    private function legalEntityRules(bool $partial):array
    {
        $presence=$partial?'sometimes':'required';
        return ['name'=>[$presence,'required','string','max:255'],'inn'=>[$presence,'required','string','max:32'],'full_name'=>[$presence,'required','string','max:500'],'ogrn'=>['sometimes','nullable','string','max:32'],'kpp'=>['sometimes','nullable','string','max:32'],'registration_date'=>['sometimes','nullable','date'],'okpo'=>['sometimes','nullable','string','max:32'],'oktmo'=>['sometimes','nullable','string','max:32'],'address'=>['sometimes','nullable','string','max:2000']];
    }

    private function normalizeLegalEntity(array $data):array
    {
        foreach(['name','inn','full_name','ogrn','kpp','okpo','oktmo','address'] as $field){if(!array_key_exists($field,$data))continue;$value=trim((string)$data[$field]);$data[$field]=in_array($field,['name','inn','full_name'],true)?$value:($value===''?null:$value);}return $data;
    }

    private function serializeClient(object $client):array
    {
        $row=ClientLogoController::serialize($client);$row['technologies']=$client->technologies?(json_decode($client->technologies,true)?:[]):[];return $row;
    }
}
