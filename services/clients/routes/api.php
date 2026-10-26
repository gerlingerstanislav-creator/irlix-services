<?php

use App\Http\Controllers\AbsenceApproversController;
use App\Http\Controllers\AttemptFunnelController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\ClientCardController;
use App\Http\Controllers\ClientContourPermissionsController;
use App\Http\Controllers\ClientsController;
use App\Http\Controllers\ClientAccountHandoffsController;
use App\Http\Controllers\ClientLogoController;
use App\Http\Controllers\ContactPeopleController;
use App\Http\Controllers\MemberTermsController;
use App\Http\Controllers\ProjectMemberCardController;
use App\Http\Controllers\PartnersController;
use App\Http\Controllers\ClientMembersController;
use App\Http\Controllers\ProjectMemberFeedbackController;
use App\Http\Controllers\ReportingPeriodsController;
use App\Http\Controllers\RequestWorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/health', \App\Http\Controllers\ClientsHealthController::class);
Route::get('/production-calendar', \App\Http\Controllers\ProductionCalendarController::class);

Route::get('/clients/{client}/client-members', [ClientMembersController::class, 'index'])->whereNumber('client');
Route::post('/clients/{client}/client-members', [ClientMembersController::class, 'store'])->whereNumber('client');
Route::get('/client-members/{member}', [ClientMembersController::class, 'show'])->whereNumber('member');
Route::post('/client-members/{member}/terms', [ClientMembersController::class, 'addTerm'])->whereNumber('member');
Route::patch('/client-terms/{term}', [ClientMembersController::class, 'updateTerm'])->whereNumber('term');
Route::patch('/project-assignments/{assignment}', [ClientMembersController::class, 'updateProjectAssignment'])->whereNumber('assignment');
Route::post('/client-members/{member}/project-assignments', [ClientMembersController::class, 'addProjectAssignment'])->whereNumber('member');
Route::post('/client-members/{member}/feedbacks', [ClientMembersController::class, 'addFeedback'])->whereNumber('member');
Route::get('/partner-specialists/catalog', [PartnersController::class, 'catalog']);
Route::get('/partners', [PartnersController::class, 'index']);
Route::post('/partners', [PartnersController::class, 'storePartner']);
Route::patch('/partners/{partner}', [PartnersController::class, 'updatePartner'])->whereNumber('partner');
Route::post('/partner-specialists', [PartnersController::class, 'storeSpecialist']);
Route::patch('/partner-specialists/{specialist}', [PartnersController::class, 'updateSpecialist'])->whereNumber('specialist');
Route::get('/overview', [ClientsController::class, 'overview']);
Route::get('/absence-approvers', AbsenceApproversController::class);
Route::get('/cash-flow', [CashFlowController::class, 'index']);
Route::get('/attempt-funnel', AttemptFunnelController::class);

Route::get('/clients/{client}/logo', [ClientLogoController::class, 'show'])->whereNumber('client');
Route::post('/clients/{client}/logo', [ClientLogoController::class, 'store'])->whereNumber('client');
Route::delete('/clients/{client}/logo', [ClientLogoController::class, 'destroy'])->whereNumber('client');
Route::post('/clients', [ClientsController::class, 'storeClient']);
Route::post('/clients/{client}/transfer', [ClientsController::class, 'transferClient'])->whereNumber('client');
Route::post('/clients/{client}/reassign', [ClientAccountHandoffsController::class, 'permanent'])->whereNumber('client');
Route::post('/clients/{client}/delegations', [ClientAccountHandoffsController::class, 'delegate'])->whereNumber('client');
Route::post('/clients/{client}/delegations/{delegation}/revoke', [ClientAccountHandoffsController::class, 'revoke'])->whereNumber('client')->whereNumber('delegation');
Route::patch('/clients/{client}', [ClientsController::class, 'updateClient'])->whereNumber('client');
Route::get('/clients/{client}/card', [ClientCardController::class, 'show'])->whereNumber('client');
Route::patch('/clients/{client}/card', [ClientCardController::class, 'update'])->whereNumber('client');
Route::post('/clients/{client}/legal-entities', [ClientCardController::class, 'storeLegalEntity'])->whereNumber('client');
Route::post('/clients/{client}/notes', [ClientCardController::class, 'storeNote'])->whereNumber('client');
Route::post('/clients/{client}/projects', [ClientsController::class, 'storeProject'])->whereNumber('client');
Route::patch('/projects/{project}', [ClientCardController::class, 'updateProject'])->whereNumber('project');
Route::delete('/projects/{project}', [ClientCardController::class, 'destroyProject'])->whereNumber('project');

Route::post('/leads', [ClientsController::class, 'storeLead']);
Route::patch('/leads/{lead}', [ClientsController::class, 'updateLead'])->whereNumber('lead');
Route::post('/leads/{lead}/comments', [ClientsController::class, 'storeLeadComment'])->whereNumber('lead');
Route::post('/leads/{lead}/legal-entities', [ClientsController::class, 'storeLeadLegalEntity'])->whereNumber('lead');
Route::post('/leads/{lead}/contacts', [ClientsController::class, 'storeLeadContact'])->whereNumber('lead');
Route::post('/leads/{lead}/convert', [ClientsController::class, 'convertLead'])->whereNumber('lead');

Route::post('/contacts', [ContactPeopleController::class, 'store']);
Route::get('/contacts/{contact}', [ContactPeopleController::class, 'show'])->whereNumber('contact');
Route::patch('/contacts/{contact}', [ContactPeopleController::class, 'update'])->whereNumber('contact');
Route::post('/contacts/{contact}/methods', [ContactPeopleController::class, 'storeMethod'])->whereNumber('contact');
Route::patch('/contacts/{contact}/methods/{method}', [ContactPeopleController::class, 'updateMethod'])->whereNumber('contact')->whereNumber('method');
Route::delete('/contacts/{contact}/methods/{method}', [ContactPeopleController::class, 'destroyMethod'])->whereNumber('contact')->whereNumber('method');
Route::post('/contacts/{contact}/client-relations', [ContactPeopleController::class, 'storeClientRelation'])->whereNumber('contact');
Route::patch('/contacts/{contact}/client-relations/{relation}', [ContactPeopleController::class, 'updateClientRelation'])->whereNumber('contact')->whereNumber('relation');
Route::delete('/contacts/{contact}/client-relations/{relation}', [ContactPeopleController::class, 'destroyClientRelation'])->whereNumber('contact')->whereNumber('relation');
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
Route::patch('/requests/{clientRequest}', [RequestWorkflowController::class, 'updateRequest'])->whereNumber('clientRequest');
Route::post('/requests/{clientRequest}/positions', [ClientsController::class, 'storePosition'])->whereNumber('clientRequest');
Route::patch('/positions/{position}', [RequestWorkflowController::class, 'updatePosition'])->whereNumber('position');
Route::post('/positions/{position}/attempts', [RequestWorkflowController::class, 'storeAttempt'])->whereNumber('position');
Route::delete('/attempts/{attempt}', [RequestWorkflowController::class, 'destroyAttempt'])->whereNumber('attempt');
Route::get('/attempts/{attempt}/cv', [RequestWorkflowController::class, 'downloadCv'])->whereNumber('attempt');
Route::post('/attempts/{attempt}/send-cv', [RequestWorkflowController::class, 'sendCv'])->whereNumber('attempt');
Route::post('/attempts/{attempt}/interviews', [RequestWorkflowController::class, 'scheduleInterview'])->whereNumber('attempt');
Route::post('/attempts/{attempt}/interviews/{interview}/complete', [RequestWorkflowController::class, 'completeInterview'])->whereNumber('attempt')->whereNumber('interview');
Route::post('/attempts/{attempt}/schedule-connection', [RequestWorkflowController::class, 'scheduleConnection'])->whereNumber('attempt');
Route::post('/attempts/{attempt}/close-success', [RequestWorkflowController::class, 'closeSuccess'])->whereNumber('attempt');
Route::post('/attempts/{attempt}/close-failure', [RequestWorkflowController::class, 'closeFailure'])->whereNumber('attempt');

Route::post('/reporting-periods', [ReportingPeriodsController::class, 'store']);
Route::post('/reporting-periods/{period}/notes', [ReportingPeriodsController::class, 'storeNote'])->whereNumber('period');
Route::patch('/reporting-periods/{period}/approved-hours/{term}', [ReportingPeriodsController::class, 'updateApprovedHours'])->whereNumber('period')->whereNumber('term');
Route::patch('/reporting-periods/{period}/overtime-multiplier', [ReportingPeriodsController::class, 'updateOvertimeMultiplier'])->whereNumber('period');
Route::get('/reporting-periods/{period}', [ReportingPeriodsController::class, 'show'])->whereNumber('period');
Route::patch('/reporting-periods/{period}', [ReportingPeriodsController::class, 'update'])->whereNumber('period');
Route::delete('/reporting-periods/{period}', [ReportingPeriodsController::class, 'destroy'])->whereNumber('period');
Route::get('/reporting-period-lock', [ReportingPeriodsController::class, 'lockStatus']);
