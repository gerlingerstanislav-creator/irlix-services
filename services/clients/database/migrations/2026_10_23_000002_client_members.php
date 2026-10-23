<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->foreignId('partner_specialist_id')->nullable()->constrained('partner_specialists')->restrictOnDelete();
            $t->string('specialist_name');
            $t->timestamps();
            $t->unique(['client_id', 'employee_id']);
            $t->unique(['client_id', 'partner_specialist_id']);
        });
        Schema::create('project_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_member_id')->constrained('client_members')->restrictOnDelete();
            $t->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->timestamps();
            $t->index(['client_member_id', 'project_id', 'valid_from']);
        });
        Schema::table('member_terms', function (Blueprint $t) {
            $t->foreignId('client_member_id')->nullable()->constrained('client_members')->restrictOnDelete();
        });
        Schema::table('member_terms', function (Blueprint $t) {
            $t->unsignedBigInteger('project_member_id')->nullable()->change();
        });
        Schema::create('client_member_feedbacks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_member_id')->constrained('client_members')->restrictOnDelete();
            $t->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $t->text('text');
            $t->string('created_by_username')->nullable();
            $t->timestamps();
        });
        Schema::table('connection_attempts', function (Blueprint $t) {
            $t->foreignId('client_member_id')->nullable()->constrained('client_members')->nullOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE client_members ADD CONSTRAINT client_members_identity_xor CHECK ((employee_id IS NULL) <> (partner_specialist_id IS NULL))');
            DB::statement('ALTER TABLE project_assignments ADD CONSTRAINT project_assignments_valid_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)');
        }

        // Preserve legacy members and terms during the expand phase. A multi-project
        // person must resolve to one immutable client membership, not one per project.
        $old = DB::table('project_members as pm')->join('projects as p','p.id','=','pm.project_id')
            ->select('pm.*','p.client_id')->orderBy('pm.id')->get();
        $ids = [];
        foreach ($old as $row) {
            $identity = $row->partner_specialist_id !== null ? 'partner:'.$row->partner_specialist_id : 'employee:'.$row->specialist_id;
            $key = $row->client_id.':'.$identity;
            if (!isset($ids[$key])) {
                $ids[$key] = DB::table('client_members')->insertGetId([
                    'client_id'=>$row->client_id, 'employee_id'=>$row->specialist_id,
                    'partner_specialist_id'=>$row->partner_specialist_id, 'specialist_name'=>$row->specialist_name,
                    'created_at'=>now(), 'updated_at'=>now(),
                ]);
            }
            $memberId = $ids[$key];
            DB::table('member_terms')->where('project_member_id',$row->id)->update(['client_member_id'=>$memberId]);
            foreach (DB::table('member_terms')->where('project_member_id',$row->id)->orderBy('valid_from')->get() as $term) {
                DB::table('project_assignments')->insert([
                    'client_member_id'=>$memberId,'project_id'=>$row->project_id,'valid_from'=>$term->valid_from,
                    'valid_to'=>$term->valid_to,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        }
    }
    public function down(): void
    {
        // Backwards schema migration cannot represent client-only terms.
        if (DB::table('member_terms')->whereNull('project_member_id')->exists()) {
            throw new RuntimeException('Client-only commercial terms require explicit reconciliation before rollback');
        }
        Schema::table('member_terms', fn(Blueprint $t) => $t->dropConstrainedForeignId('client_member_id'));
        Schema::table('member_terms', fn(Blueprint $t) => $t->unsignedBigInteger('project_member_id')->nullable(false)->change());
        Schema::table('connection_attempts', fn(Blueprint $t) => $t->dropConstrainedForeignId('client_member_id'));
        Schema::dropIfExists('client_member_feedbacks');
        Schema::dropIfExists('project_assignments');
        Schema::dropIfExists('client_members');
    }
};
