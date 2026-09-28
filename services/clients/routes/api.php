<?php

use App\Http\Controllers\AbsenceApproversController;
use App\Http\Controllers\ClientCardController;
use App\Http\Controllers\ClientsController;
use App\Http\Controllers\ContactPeopleController;
use App\Http\Controllers\MemberTermsController;
use App\Http\Controllers\ProjectMemberCardController;
use App\Http\Controllers\ProjectMemberFeedbackController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'service' => 'clients',
    'status' => 'ok',
    'database' => DB::select('select 1') ? 'ok' : 'error',
]));

Route::get('/overview', [ClientsController::class, 'overview']);
Route::get('/absence-approvers', AbsenceApproversController::class);

Route::post('/clients', [ClientsController::class, 'storeClient']);
Route::patch('/clients/{client}', [ClientsController::class, 'updateClient'])->whereNumber('client');
Route::get('/clients/{client}/card', [ClientCardController::class, 'show'])->whereNumber('client');
Route::patch('/clients/{client}/card', [ClientCardController::class, 'update'])->whereNumber('client');
Route::post('/clients/{client}/legal-entities', [ClientCardController::class, 'storeLegalEntity'])->whereNumber('client');
Route::post('/clients/{client}/notes', [ClientCardController::class, 'storeNote'])->whereNumber('client');
Route::post('/clients/{client}/projects', [ClientsController::class, 'storeProject'])->whereNumber('client');

Route::post('/leads', [ClientsController::class, 'storeLead']);
Route::patch('/leads/{lead}', [ClientsController::class, 'updateLead'])->whereNumber('lead');
Route::post('/leads/{lead}/convert', [ClientsController::class, 'convertLead'])->whereNumber('lead');

Route::post('/contacts', [ContactPeopleController::class, 'store']);
Route::get('/contacts/{contact}', [ContactPeopleController::class, 'show'])->whereNumber('contact');
Route::patch('/contacts/{contact}', [ContactPeopleController::class, 'update'])->whereNumber('contact');
Route::post('/contacts/{contact}/methods', [ContactPeopleController::class, 'storeMethod'])->whereNumber('contact');
Route::patch('/contacts/{contact}/methods/{method}', [ContactPeopleController::class, 'updateMethod'])->whereNumber('contact')->whereNumber('method');
Route::post('/contacts/{contact}/client-relations', [ContactPeopleController::class, 'storeClientRelation'])->whereNumber('contact');
Route::patch('/contacts/{contact}/client-relations/{relation}', [ContactPeopleController::class, 'updateClientRelation'])->whereNumber('contact')->whereNumber('relation');
// Legacy generic relation endpoint remains for lead bindings and older flows.
Route::post('/contacts/{contact}/relations', [ClientsController::class, 'attachContact'])->whereNumber('contact');
Route::patch('/legal-entities/{entity}', [ClientCardController::class, 'updateLegalEntity'])->whereNumber('entity');

Route::post('/projects/{project}/members', [ClientsController::class, 'storeMember'])->whereNumber('project');
Route::get('/members/{member}', [ProjectMemberCardController::class, 'show'])->whereNumber('member');
Route::patch('/members/{member}', [ProjectMemberCardController::class, 'update'])->whereNumber('member');
Route::patch('/members/{member}/project', [ClientsController::class, 'moveMember'])->whereNumber('member');
Route::post('/members/{member}/terms', [MemberTermsController::class, 'store'])->whereNumber('member');
Route::patch('/terms/{term}', [ClientsController::class, 'updateTerms'])->whereNumber('term');
Route::get('/members/{member}/feedbacks', [ProjectMemberFeedbackController::class, 'index'])->whereNumber('member');
Route::post('/members/{member}/feedbacks', [ProjectMemberFeedbackController::class, 'store'])->whereNumber('member');

Route::post('/requests', [ClientsController::class, 'storeRequest']);
Route::post('/requests/{clientRequest}/positions', [ClientsController::class, 'storePosition'])->whereNumber('clientRequest');
Route::post('/positions/{position}/attempts', [ClientsController::class, 'storeAttempt'])->whereNumber('position');
Route::patch('/attempts/{attempt}', [ClientsController::class, 'updateAttempt'])->whereNumber('attempt');

Route::post('/reporting-periods', [ClientsController::class, 'storeReportingPeriod']);
Route::patch('/reporting-periods/{period}', [ClientsController::class, 'updateReportingPeriod'])->whereNumber('period');
