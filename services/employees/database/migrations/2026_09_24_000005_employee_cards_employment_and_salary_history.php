<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('gender')->nullable();
            $table->string('login')->nullable()->unique();
            $table->string('work_email')->nullable()->unique();
            $table->string('personal_email')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('telegram')->nullable();
            $table->string('skype')->nullable();
            $table->string('specialization')->nullable();
            $table->boolean('is_remote')->default(false);
            $table->date('fired_at')->nullable();
            $table->string('onboarding_email_status')->default('not_requested');
        });

        Schema::create('employment_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('cooperation_type');
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('position')->nullable();
            $table->timestamps();
        });

        Schema::create('salary_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('effective_from');
            $table->decimal('gross_salary', 14, 2);
            $table->decimal('bonus', 14, 2)->nullable();
            $table->string('status')->default('Действует');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        $departmentIds = DB::table('departments')->pluck('id', 'name');
        $now = now();
        $seedEmployees = [
            ['first_name'=>'Тестовый','last_name'=>'Сотрудник 01','middle_name'=>null,'gender'=>'Не указан','login'=>'test.employee01','work_email'=>'test.employee01@example.test','personal_email'=>'test.employee01.personal@example.test','department'=>'Backend','position'=>'Backend Developer','status'=>'Трудоустроен','format'=>'Удалённо','type'=>'Штат','start'=>'2023-04-10','birth'=>'1990-01-01','city'=>'Тестовый город','telegram'=>'@test_employee01'],
            ['first_name'=>'Тестовый','last_name'=>'Сотрудник 02','middle_name'=>null,'gender'=>'Не указан','login'=>'test.employee02','work_email'=>'test.employee02@example.test','personal_email'=>'test.employee02.personal@example.test','department'=>'QA','position'=>'QA Engineer','status'=>'Трудоустроен','format'=>'Офис','type'=>'ГПХ','start'=>'2024-01-15','birth'=>'1990-01-02','city'=>'Тестовый город','telegram'=>'@test_employee02'],
            ['first_name'=>'Тестовый','last_name'=>'Сотрудник 03','middle_name'=>null,'gender'=>'Не указан','login'=>'test.employee03','work_email'=>'test.employee03@example.test','personal_email'=>'test.employee03.personal@example.test','department'=>'Frontend','position'=>'Frontend Developer','status'=>'Трудоустроен','format'=>'Удалённо','type'=>'ИП','start'=>'2022-08-01','birth'=>'1990-01-03','city'=>'Тестовый город','telegram'=>'@test_employee03'],
            ['first_name'=>'Тестовый','last_name'=>'Сотрудник 04','middle_name'=>null,'gender'=>'Не указан','login'=>'test.employee04','work_email'=>'test.employee04@example.test','personal_email'=>'test.employee04.personal@example.test','department'=>'Analytics','position'=>'Business Analyst','status'=>'Трудоустроен','format'=>'Офис','type'=>'Самозанятый','start'=>'2025-02-03','birth'=>'1990-01-04','city'=>'Тестовый город','telegram'=>'@test_employee04'],
            ['first_name'=>'Тестовый','last_name'=>'Сотрудник 05','middle_name'=>null,'gender'=>'Не указан','login'=>'test.employee05','work_email'=>'test.employee05@example.test','personal_email'=>'test.employee05.personal@example.test','department'=>'Mobile','position'=>'Mobile Developer','status'=>'Трудоустроен','format'=>'Удалённо','type'=>'Штат','start'=>'2025-06-16','birth'=>'1990-01-05','city'=>'Тестовый город','telegram'=>'@test_employee05'],
        ];

        foreach ($seedEmployees as $index => $seed) {
            if (DB::table('employees')->where('login', $seed['login'])->exists()) continue;
            $fullName = trim($seed['last_name'].' '.$seed['first_name'].' '.$seed['middle_name']);
            $employeeId = DB::table('employees')->insertGetId([
                'full_name'=>$fullName,'first_name'=>$seed['first_name'],'last_name'=>$seed['last_name'],'middle_name'=>$seed['middle_name'],
                'gender'=>$seed['gender'],'login'=>$seed['login'],'work_email'=>$seed['work_email'],'personal_email'=>$seed['personal_email'],
                'department_id'=>$departmentIds[$seed['department']] ?? null,'position'=>$seed['position'],'employment_status'=>$seed['status'],
                'work_format'=>$seed['format'],'cooperation_type'=>$seed['type'],'hired_at'=>$seed['start'],'birth_date'=>$seed['birth'],
                'city'=>$seed['city'],'telegram'=>$seed['telegram'],'is_remote'=>$seed['format']==='Удалённо','onboarding_email_status'=>'sent_demo',
                'created_at'=>$now,'updated_at'=>$now,
            ]);

            $periods = $index === 0
                ? [['Штат','2021-02-01','2022-05-31'],['ГПХ','2022-09-01','2023-03-31'],['Штат','2023-04-10',null]]
                : [[$seed['type'],$seed['start'],null]];
            foreach ($periods as [$type,$start,$end]) {
                DB::table('employment_periods')->insert([
                    'employee_id'=>$employeeId,'cooperation_type'=>$type,'started_at'=>$start,'ended_at'=>$end,
                    'department_id'=>$departmentIds[$seed['department']] ?? null,'position'=>$seed['position'],'created_at'=>$now,'updated_at'=>$now,
                ]);
            }

        }
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_history');
        Schema::dropIfExists('employment_periods');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['first_name','last_name','middle_name','gender','login','work_email','personal_email','birth_date','city','phone','telegram','skype','specialization','is_remote','fired_at','onboarding_email_status']);
        });
    }
};
