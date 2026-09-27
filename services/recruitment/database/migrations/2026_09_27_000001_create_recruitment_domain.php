<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $t) { $t->id(); $t->string('full_name'); $t->string('role')->nullable(); $t->string('grade',64)->nullable(); $t->string('city',128)->nullable(); $t->string('salary_expectation',128)->nullable(); $t->string('source',128)->nullable(); $t->string('recruiter_name')->nullable(); $t->string('email')->nullable(); $t->string('phone',64)->nullable(); $t->jsonb('stack_json')->nullable(); $t->timestampTz('last_activity_at')->nullable(); $t->timestampsTz(); });
        Schema::create('recruitment_requests', function (Blueprint $t) { $t->id(); $t->string('title'); $t->string('department_name'); $t->string('manager_name'); $t->string('recruiter_name')->nullable(); $t->unsignedInteger('positions_count')->default(1); $t->string('priority',16)->default('medium'); $t->string('status',32)->default('new'); $t->text('description')->nullable(); $t->timestampsTz(); });
        Schema::create('hiring_processes', function (Blueprint $t) { $t->id(); $t->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete(); $t->foreignId('recruitment_request_id')->nullable()->constrained('recruitment_requests')->nullOnDelete(); $t->string('stage_type',32)->default('new'); $t->string('stage_label',128)->default('Новый'); $t->timestampTz('stage_changed_at')->nullable(); $t->string('recruiter_name')->nullable(); $t->timestampTz('closed_at')->nullable(); $t->timestampsTz(); });
        Schema::create('stage_history', function (Blueprint $t) { $t->id(); $t->foreignId('hiring_process_id')->constrained('hiring_processes')->cascadeOnDelete(); $t->string('stage_type',32); $t->string('stage_label',128); $t->string('actor')->nullable(); $t->timestampsTz(); });
        Schema::create('activities', function (Blueprint $t) { $t->id(); $t->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete(); $t->string('type',64); $t->string('channel',64)->nullable(); $t->string('result',128)->nullable(); $t->text('comment')->nullable(); $t->string('actor')->nullable(); $t->timestampTz('occurred_at'); $t->timestampTz('next_action_at')->nullable(); $t->timestampsTz(); });
        Schema::create('talent_pools', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->text('description')->nullable(); $t->boolean('active')->default(true); $t->timestampsTz(); });
        Schema::create('candidate_talent_pool', function (Blueprint $t) { $t->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete(); $t->foreignId('talent_pool_id')->constrained('talent_pools')->cascadeOnDelete(); $t->timestampsTz(); $t->primary(['candidate_id','talent_pool_id']); });
        Schema::create('interviews', function (Blueprint $t) { $t->id(); $t->foreignId('hiring_process_id')->constrained('hiring_processes')->cascadeOnDelete(); $t->string('type',64); $t->timestampTz('scheduled_at'); $t->string('status',32)->default('scheduled'); $t->string('participants')->nullable(); $t->timestampsTz(); });
        Schema::create('evaluations', function (Blueprint $t) { $t->id(); $t->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete(); $t->string('author'); $t->unsignedSmallInteger('hard_skills')->nullable(); $t->unsignedSmallInteger('soft_skills')->nullable(); $t->unsignedSmallInteger('motivation')->nullable(); $t->string('recommendation',64)->nullable(); $t->text('comment')->nullable(); $t->timestampsTz(); });
        Schema::create('offers', function (Blueprint $t) { $t->id(); $t->foreignId('hiring_process_id')->constrained('hiring_processes')->cascadeOnDelete(); $t->string('status',32)->default('draft'); $t->string('role'); $t->string('compensation')->nullable(); $t->date('planned_start_date')->nullable(); $t->timestampTz('sent_at')->nullable(); $t->timestampTz('decision_at')->nullable(); $t->timestampsTz(); });
        Schema::create('employment_requests', function (Blueprint $t) { $t->id(); $t->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete(); $t->foreignId('hiring_process_id')->nullable()->constrained('hiring_processes')->nullOnDelete(); $t->string('role'); $t->string('department_name'); $t->string('manager_name'); $t->date('planned_start_date'); $t->string('stage',32)->default('request'); $t->unsignedBigInteger('employee_id')->nullable(); $t->timestampsTz(); });
        Schema::create('onboarding_processes', function (Blueprint $t) { $t->id(); $t->foreignId('employment_request_id')->nullable()->constrained('employment_requests')->nullOnDelete(); $t->unsignedBigInteger('employee_id')->nullable(); $t->string('employee_name'); $t->string('role')->nullable(); $t->string('manager_name')->nullable(); $t->date('planned_start_date')->nullable(); $t->unsignedSmallInteger('completed_steps')->default(0); $t->unsignedSmallInteger('total_steps')->default(0); $t->string('next_action')->nullable(); $t->string('status',32)->default('active'); $t->timestampsTz(); });
        Schema::create('tasks', function (Blueprint $t) { $t->id(); $t->string('title'); $t->string('assignee')->nullable(); $t->string('entity_type',64)->nullable(); $t->unsignedBigInteger('entity_id')->nullable(); $t->timestampTz('due_at')->nullable(); $t->timestampTz('completed_at')->nullable(); $t->timestampsTz(); });

        $now = now();
        DB::table('candidates')->insert([
            ['id'=>1,'full_name'=>'Иван Петров','role'=>'Senior PHP Developer','grade'=>'Senior','city'=>'Москва','salary_expectation'=>'280–320 тыс.','source'=>'HH','recruiter_name'=>'Анна Смирнова','stack_json'=>json_encode(['PHP','Laravel','PostgreSQL'],JSON_UNESCAPED_UNICODE),'last_activity_at'=>$now,'created_at'=>$now,'updated_at'=>$now],
            ['id'=>2,'full_name'=>'Мария Орлова','role'=>'QA Engineer','grade'=>'Middle+','city'=>'Санкт-Петербург','salary_expectation'=>'190–220 тыс.','source'=>'Рекомендация','recruiter_name'=>'Ольга Ким','stack_json'=>json_encode(['Manual QA','API','SQL'],JSON_UNESCAPED_UNICODE),'last_activity_at'=>$now,'created_at'=>$now,'updated_at'=>$now],
            ['id'=>3,'full_name'=>'Алексей Волков','role'=>'Frontend Developer','grade'=>'Middle','city'=>'Казань','salary_expectation'=>'200–230 тыс.','source'=>'Telegram','recruiter_name'=>'Анна Смирнова','stack_json'=>json_encode(['Vue','TypeScript'],JSON_UNESCAPED_UNICODE),'created_at'=>$now,'updated_at'=>$now],
            ['id'=>4,'full_name'=>'Дарья Белова','role'=>'Project Manager','grade'=>'Senior','city'=>'Удалённо','salary_expectation'=>'260–300 тыс.','source'=>'База','recruiter_name'=>'Ольга Ким','stack_json'=>json_encode(['Delivery','Agile'],JSON_UNESCAPED_UNICODE),'last_activity_at'=>$now,'created_at'=>$now,'updated_at'=>$now],
            ['id'=>5,'full_name'=>'Сергей Котов','role'=>'DevOps Engineer','grade'=>'Senior','city'=>'Екатеринбург','salary_expectation'=>'320–360 тыс.','source'=>'LinkedIn','recruiter_name'=>'Анна Смирнова','stack_json'=>json_encode(['Kubernetes','Linux','CI/CD'],JSON_UNESCAPED_UNICODE),'created_at'=>$now,'updated_at'=>$now],
        ]);
        DB::table('recruitment_requests')->insert([
            ['id'=>101,'title'=>'Senior PHP Developer','department_name'=>'Backend','manager_name'=>'Александр Морозов','recruiter_name'=>'Анна Смирнова','positions_count'=>2,'priority'=>'high','status'=>'active','created_at'=>$now->copy()->subDays(18),'updated_at'=>$now],
            ['id'=>102,'title'=>'Middle QA Engineer','department_name'=>'QA','manager_name'=>'Елена Соколова','recruiter_name'=>'Ольга Ким','positions_count'=>1,'priority'=>'medium','status'=>'active','created_at'=>$now->copy()->subDays(9),'updated_at'=>$now],
            ['id'=>103,'title'=>'DevOps Engineer','department_name'=>'DevOps','manager_name'=>'Максим Левин','recruiter_name'=>'Анна Смирнова','positions_count'=>1,'priority'=>'high','status'=>'new','created_at'=>$now->copy()->subDays(3),'updated_at'=>$now],
        ]);
        DB::table('hiring_processes')->insert([
            ['id'=>1,'candidate_id'=>1,'recruitment_request_id'=>101,'stage_type'=>'manager_interview','stage_label'=>'HR + руководитель','stage_changed_at'=>$now,'recruiter_name'=>'Анна Смирнова','created_at'=>$now,'updated_at'=>$now],
            ['id'=>2,'candidate_id'=>2,'recruitment_request_id'=>102,'stage_type'=>'hr_interview','stage_label'=>'HR интервью','stage_changed_at'=>$now,'recruiter_name'=>'Ольга Ким','created_at'=>$now,'updated_at'=>$now],
            ['id'=>3,'candidate_id'=>3,'recruitment_request_id'=>101,'stage_type'=>'contact','stage_label'=>'Контакт','stage_changed_at'=>$now,'recruiter_name'=>'Анна Смирнова','created_at'=>$now,'updated_at'=>$now],
            ['id'=>4,'candidate_id'=>4,'recruitment_request_id'=>null,'stage_type'=>'offer','stage_label'=>'Оффер','stage_changed_at'=>$now,'recruiter_name'=>'Ольга Ким','created_at'=>$now,'updated_at'=>$now],
            ['id'=>5,'candidate_id'=>5,'recruitment_request_id'=>103,'stage_type'=>'new','stage_label'=>'Новый','stage_changed_at'=>$now,'recruiter_name'=>'Анна Смирнова','created_at'=>$now,'updated_at'=>$now],
        ]);
        DB::table('talent_pools')->insert([
            ['id'=>1,'name'=>'Готовы быстро выйти','description'=>'Проверенные кандидаты с подтверждённой актуальностью и коротким сроком выхода','created_at'=>$now,'updated_at'=>$now],
            ['id'=>2,'name'=>'Сильные QA','description'=>'Кандидаты QA-направления, к которым стоит возвращаться при новых заявках','created_at'=>$now,'updated_at'=>$now],
            ['id'=>3,'name'=>'Potential Leads','description'=>'Сильные кандидаты с руководительским потенциалом','created_at'=>$now,'updated_at'=>$now],
            ['id'=>4,'name'=>'Вернуться позже','description'=>'Хорошие кандидаты, контакт с которыми нужно возобновить позднее','created_at'=>$now,'updated_at'=>$now],
        ]);
        DB::table('candidate_talent_pool')->insert([
            ['candidate_id'=>1,'talent_pool_id'=>1,'created_at'=>$now,'updated_at'=>$now], ['candidate_id'=>2,'talent_pool_id'=>2,'created_at'=>$now,'updated_at'=>$now], ['candidate_id'=>4,'talent_pool_id'=>3,'created_at'=>$now,'updated_at'=>$now], ['candidate_id'=>5,'talent_pool_id'=>4,'created_at'=>$now,'updated_at'=>$now],
        ]);
        DB::table('employment_requests')->insert([
            ['id'=>1,'candidate_id'=>1,'role'=>'Backend Developer','department_name'=>'Backend','manager_name'=>'Александр Морозов','planned_start_date'=>'2026-10-05','stage'=>'approval','created_at'=>$now,'updated_at'=>$now],
            ['id'=>2,'candidate_id'=>2,'role'=>'QA Engineer','department_name'=>'QA','manager_name'=>'Елена Соколова','planned_start_date'=>'2026-10-12','stage'=>'documents','created_at'=>$now,'updated_at'=>$now],
        ]);
        DB::table('onboarding_processes')->insert([
            ['employee_name'=>'Анна Павлова','role'=>'QA Engineer','manager_name'=>'Елена Соколова','planned_start_date'=>'2026-10-12','completed_steps'=>4,'total_steps'=>7,'next_action'=>'Назначить buddy','created_at'=>$now,'updated_at'=>$now],
            ['employee_name'=>'Дмитрий Ильин','role'=>'DevOps Engineer','manager_name'=>'Максим Левин','planned_start_date'=>'2026-10-19','completed_steps'=>2,'total_steps'=>7,'next_action'=>'Заказать доступы','created_at'=>$now,'updated_at'=>$now],
        ]);
    }

    public function down(): void
    {
        foreach (['tasks','onboarding_processes','employment_requests','offers','evaluations','interviews','candidate_talent_pool','talent_pools','activities','stage_history','hiring_processes','recruitment_requests','candidates'] as $table) Schema::dropIfExists($table);
    }
};
