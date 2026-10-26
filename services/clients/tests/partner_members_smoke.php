<?php
require __DIR__.'/client_members_bootstrap.php';
use Illuminate\Support\Facades\DB;
clientSchemaLegacy();clientExpand();
Illuminate\Support\Facades\DB::connection()->getSchemaBuilder()->create('contact_people',function($t){
    $t->id();$t->string('full_name');$t->string('position')->nullable();$t->string('phone')->nullable();$t->string('email')->nullable();$t->timestamps();
});
$schema=Illuminate\Support\Facades\DB::connection()->getSchemaBuilder();
$schema->create('contact_methods',function($t){
    $t->id();$t->integer('contact_person_id');$t->string('type');$t->string('contact');$t->boolean('is_active')->default(true);$t->boolean('is_preferred')->default(false);$t->timestamps();
});
$schema->create('contact_relations',function($t){
    $t->id();$t->integer('contact_person_id');$t->string('entity_type');$t->integer('entity_id');$t->string('relation_role')->nullable();$t->boolean('active')->default(true);$t->timestamps();
});
foreach (['2026_10_26_000001_add_partner_profile_fields.php',
          '2026_10_26_000002_partner_profile_relations.php'] as $file) {
    (require dirname(__DIR__).'/database/migrations/'.$file)->up();
}

DB::table('clients')->insert([['id'=>1,'name'=>'Synthetic one'],['id'=>2,'name'=>'Synthetic two']]);
$partners=new App\Http\Controllers\PartnersController();
$org=$partners->storePartner(clientRequest(['name'=>'Synthetic partner']))->getData(true)['data']['id'];
$profile=$partners->showProfile(clientRequest([], 'GET'),$org)->getData(true)['data'];
clientCheck($profile['partner']['name']==='Synthetic partner','Partner card loads partner');
$linked=$partners->linkContact(clientRequest(['full_name'=>'Synthetic Contact','position'=>'Manager']),$org)->getData(true)['data'];
clientCheck(count($linked['contacts'])===1 && $linked['contacts'][0]['position']==='Manager','Partner contact is independently linked');
$relation=$linked['contacts'][0]['id'];
$contactId=$linked['contacts'][0]['contact_person_id'];
$partners->linkContact(clientRequest(['contact_id'=>$contactId,'position'=>'Director']),$org);
clientCheck(DB::table('partner_contact_relations')->count()===1,'Duplicate contact links reuse stable relation');
$withMethods=$partners->linkContact(clientRequest(['full_name'=>'Synthetic Full Contact','methods'=>[
    ['type'=>'Telegram','contact'=>'@synthetic','is_active'=>true,'is_preferred'=>true],
]]),$org)->getData(true)['data'];
clientCheck((int)DB::table('contact_methods')->where('contact_person_id',$withMethods['linked_contact_id'])->count()===1,
    'Partner contact creation reuses the shared ContactPerson methods model');
$partners->updateContactRelation(clientRequest(['active'=>false],'PATCH'),$org,$relation);
clientCheck((int) DB::table('partner_contact_relations')->find($relation)->active===0,'Contact relation can be disabled without deletion');
$partners->storeLegalEntity(clientRequest(['name'=>'Synthetic LLC','inn'=>'1234567890','full_name'=>'Synthetic Legal Company']),$org);
$entity=DB::table('partner_legal_entities')->first();
$partners->updateLegalEntity(clientRequest(['address'=>'Synthetic address'],'PATCH'),$org,$entity->id);
clientCheck(DB::table('partner_legal_entities')->find($entity->id)->address==='Synthetic address','Legal entity has independent history and updates');
$partners->addNote(clientRequest(['text'=>'Synthetic comment']),$org);
clientCheck(DB::table('partner_history_events')->where('type','comment')->count()===1,'Partner comments are appended to history');
$notAdmin=clientRequest([], 'GET');$notAdmin->attributes->set('client_contour_access',['platform_admin'=>false,'permissions'=>[]]);
clientReject(fn()=>$partners->showProfile($notAdmin,$org),403,'Non privileged user reads partner card');
clientCheck(DB::table('partner_history_events')->count()>=4,'Partner writes append auditable events');
$partner=$partners->storeSpecialist(clientRequest(['partner_id'=>$org,'full_name'=>'Synthetic Specialist']))->getData(true)['data']['id'];
$ctl=new App\Http\Controllers\ClientMembersController();
$m=$ctl->store(clientRequest(['partner_specialist_id'=>$partner]),1)->getData(true)['data']['id'];
clientCheck(DB::table('projects')->count()===0,'Client-only connection requires no project');
clientCheck($ctl->store(clientRequest(['partner_specialist_id'=>$partner]),1)->getData(true)['data']['id']===$m,'Returning partner reuses membership');
$employee=$ctl->store(clientRequest(['employee_id'=>$partner,'specialist_name'=>'Employee same numeric ID']),1)->getData(true)['data']['id'];
clientCheck($m!==$employee,'Partner and Employee namespaces differ');
$terms=['technology'=>'PHP','level'=>'Senior','hourly_rate'=>100,'hours_per_day'=>8,'valid_from'=>'2026-10-01','valid_to'=>'2026-10-31'];
$t=$ctl->addTerm(clientRequest($terms),$m)->getData(true)['data']['id'];
clientReject(fn()=>$ctl->addTerm(clientRequest([...$terms,'valid_from'=>'2026-10-15']),$m),422,'overlap');
$ctl->addTerm(clientRequest([...$terms,'valid_from'=>'2026-12-01','valid_to'=>'2026-12-31']),$m);
clientCheck(DB::table('member_terms')->where('client_member_id',$m)->count()===2,'Gaps and return use new terms');
$secondEmployee=DB::table('client_members')->insertGetId(['client_id'=>2,'employee_id'=>222,'specialist_name'=>'Second employee','created_at'=>now(),'updated_at'=>now()]);
DB::table('member_terms')->insert(['client_member_id'=>$secondEmployee,'technology'=>'PHP','level'=>'Middle','hours_per_day'=>8,'valid_from'=>'2026-10-01','created_at'=>now(),'updated_at'=>now()]);
$rosterMembers=new App\Services\ClientSpecialistsService();
$allRosterMembers=$rosterMembers->membersForClients([1,2]);
clientCheck(count($allRosterMembers)===2 && array_column($allRosterMembers,'employee_id')===[$partner,222],
    'Multi-client roster returns employee identities and periods without partner specialists');
clientCheck(count($rosterMembers->members(1))===1,'Single-client roster remains compatible');
clientReject(fn()=>$rosterMembers->membersForClients([1,999]),404,'Unknown client cannot be used in a batch');
DB::table('projects')->insert([['id'=>1,'client_id'=>1],['id'=>2,'client_id'=>1],['id'=>3,'client_id'=>2],['id'=>4,'client_id'=>1]]);
foreach([1,2] as $id)$ctl->addProjectAssignment(clientRequest(['project_id'=>$id,'valid_from'=>'2026-10-01']),$m);
clientReject(fn()=>$ctl->addProjectAssignment(clientRequest(['project_id'=>1,'valid_from'=>'2026-10-15']),$m),422,'same-project overlap');
clientReject(fn()=>$ctl->addProjectAssignment(clientRequest(['project_id'=>3,'valid_from'=>'2026-10-01']),$m),422,'cross-client project');
$ctl->addFeedback(clientRequest(['project_id'=>1,'text'=>'Historical feedback']),$m);
$ctl->addFeedback(clientRequest(['text'=>'Client feedback']),$m);
$ctl->addProjectAssignment(clientRequest(['project_id'=>4,'valid_from'=>'2026-12-01']),$m);
clientReject(fn()=>$ctl->addFeedback(clientRequest(['project_id'=>4,'text'=>'Future-only assignment']),$m),422,'future assignment is not historical feedback evidence');
clientReject(fn()=>$ctl->addFeedback(clientRequest(['project_id'=>3,'text'=>'Forbidden']),$m),422,'cross-client feedback');
clientReject(fn()=>$ctl->addFeedback(clientRequest(['text'=>'  ']),$m),422,'blank feedback');
DB::table('reporting_periods')->insert(['client_id'=>1,'period_start'=>'2026-10-01','period_end'=>'2026-10-31','status'=>'Счет оплачен']);
$ctl->updateTerm(clientRequest(['hourly_rate'=>'100.00','hours_per_day'=>'8.00'],'PATCH'),$t);
clientCheck((float)DB::table('member_terms')->find($t)->hourly_rate===100.0,'Protected numeric no-op accepts equivalent decimal representation');
App\Support\MemberIdentity::assertTermChangeEditable(1,(object)['technology'=>'PHP','level'=>'Senior','hourly_rate'=>'100.00','hours_per_day'=>'8.00','valid_from'=>'2026-10-01','valid_to'=>'2026-10-31'],['hourly_rate'=>100,'hours_per_day'=>8],'2026-10-01','2026-10-31');
clientReject(fn()=>$ctl->updateTerm(clientRequest(['hourly_rate'=>200],'PATCH'),$t),423,'paid conditions immutable');
clientReject(fn()=>$ctl->addTerm(clientRequest([...$terms,'valid_from'=>'2026-09-01']),$employee),423,'new retroactive terms protected');
$partners->updatePartner(clientRequest(['active'=>false],'PATCH'),$org);
clientReject(fn()=>$ctl->store(clientRequest(['partner_specialist_id'=>$partner]),2),422,'inactive organization cannot create new membership');
clientCheck(DB::table('client_members')->find($m)->partner_specialist_id===$partner,'Deactivation preserves identity and terms');
echo "ClientMember partner identity/projectless terms/return/parallel projects/feedback/paid protection PASS\n";
