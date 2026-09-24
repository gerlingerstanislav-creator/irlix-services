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
            ['first_name'=>'Алексей','last_name'=>'Смирнов','middle_name'=>'Игоревич','gender'=>'Мужчина','login'=>'alexey.smirnov','personal_email'=>'alexey.smirnov@example.test','department'=>'Backend','position'=>'Backend Developer','status'=>'Трудоустроен','format'=>'Удалённо','type'=>'Штат','start'=>'2023-04-10','birth'=>'1994-06-18','city'=>'Казань','telegram'=>'@alexsmirnov'],
            ['first_name'=>'Мария','last_name'=>'Кузнецова','middle_name'=>'Олеговна','gender'=>'Женщина','login'=>'maria.kuznetsova','personal_email'=>'maria.kuznetsova@example.test','department'=>'QA','position'=>'QA Engineer','status'=>'Трудоустроен','format'=>'Офис','type'=>'ГПХ','start'=>'2024-01-15','birth'=>'1996-11-03','city'=>'Ульяновск','telegram'=>'@mkuznetsova'],
            ['first_name'=>'Денис','last_name'=>'Орлов','middle_name'=>'Андреевич','gender'=>'Мужчина','login'=>'denis.orlov','personal_email'=>'denis.orlov@example.test','department'=>'Frontend','position'=>'Frontend Developer','status'=>'Трудоустроен','format'=>'Удалённо','type'=>'ИП','start'=>'2022-08-01','birth'=>'1991-03-25','city'=>'Самара','telegram'=>'@denisorlov'],
            ['first_name'=>'Анна','last_name'=>'Волкова','middle_name'=>'Сергеевна','gender'=>'Женщина','login'=>'anna.volkova','personal_email'=>'anna.volkova@example.test','department'=>'Analytics','position'=>'Business Analyst','status'=>'Трудоустроен','format'=>'Офис','type'=>'Самозанятый','start'=>'2025-02-03','birth'=>'1998-08-12','city'=>'Москва','telegram'=>'@avolkova'],
            ['first_name'=>'Илья','last_name'=>'Морозов','middle_name'=>'Павлович','gender'=>'Мужчина','login'=>'ilya.morozov','personal_email'=>'ilya.morozov@example.test','department'=>'Mobile','position'=>'Mobile Developer','status'=>'Трудоустроен','format'=>'Удалённо','type'=>'Штат','start'=>'2025-06-16','birth'=>'1993-12-09','city'=>'Санкт-Петербург','telegram'=>'@imorozov'],
        ];

        foreach ($seedEmployees as $index => $seed) {
            if (DB::table('employees')->where('login', $seed['login'])->exists()) continue;
            $fullName = trim($seed['last_name'].' '.$seed['first_name'].' '.$seed['middle_name']);
            $employeeId = DB::table('employees')->insertGetId([
                'full_name'=>$fullName,'first_name'=>$seed['first_name'],'last_name'=>$seed['last_name'],'middle_name'=>$seed['middle_name'],
                'gender'=>$seed['gender'],'login'=>$seed['login'],'work_email'=>$seed['login'].'@irlix.ru','personal_email'=>$seed['personal_email'],
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

            $base = 120000 + ($index * 25000);
            foreach ([['2024-01-01',$base],['2025-01-01',$base+25000],['2026-01-01',$base+50000]] as [$date,$gross]) {
                DB::table('salary_history')->insert([
                    'employee_id'=>$employeeId,'effective_from'=>$date,'gross_salary'=>$gross,'bonus'=>$index % 2 ? 15000 : null,
                    'status'=>'Действует','comment'=>'Тестовая история для разработки','created_at'=>$now,'updated_at'=>$now,
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
