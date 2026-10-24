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
            $t->dropForeign(['project_member_id']);
            $t->unsignedBigInteger('project_member_id')->nullable()->change();
            $t->foreign('project_member_id')->references('id')->on('project_members')->restrictOnDelete();
        });
        Schema::table('member_terms', function (Blueprint $t) {
            $t->foreignId('superseded_by_term_id')->nullable()->constrained('member_terms')->restrictOnDelete();
        });
        Schema::create('member_term_lineage', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('source_term_id')->unique();
            $t->foreignId('canonical_term_id')->constrained('member_terms')->restrictOnDelete();
            $t->unsignedBigInteger('original_client_member_id');
            $t->text('original_payload');
        });
        Schema::create('client_member_feedbacks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_member_id')->constrained('client_members')->restrictOnDelete();
            $t->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $t->text('text');
            $t->unsignedBigInteger('source_project_member_feedback_id')->nullable()->unique();
            $t->string('created_by_username')->nullable();
            // Legacy feedback may have a business date/rating/interview reference
            // without an attributable author. Keep these source values distinct
            // from import timestamps and the new feedback's local identifier.
            $t->date('occurred_on')->nullable();
            $t->text('rating')->nullable();
            $t->unsignedBigInteger('legacy_interview_id')->nullable();
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
            if ($row->source_attempt_id !== null) {
                DB::table('connection_attempts')->where('id',$row->source_attempt_id)
                    ->update(['client_member_id'=>$memberId]);
            }
            DB::table('member_terms')->where('project_member_id',$row->id)->update(['client_member_id'=>$memberId]);

        }
        // Existing feedback remains attributable to the original project.
        // The historical ProjectMember row is documentary evidence even
        // when exact project assignment dates are not recoverable.
        foreach ($old as $row) {
            $identity=$row->partner_specialist_id !== null ? 'partner:'.$row->partner_specialist_id : 'employee:'.$row->specialist_id;
            $memberId=$ids[$row->client_id.':'.$identity];
            foreach (DB::table('project_member_feedbacks')->where('project_member_id',$row->id)->get() as $feedback) {
                DB::table('client_member_feedbacks')->insert([
                    'client_member_id'=>$memberId,'project_id'=>$row->project_id,
                    'source_project_member_feedback_id'=>$feedback->id,
                    'text'=>$feedback->text,'created_by_username'=>$feedback->created_by_username,
                    'created_at'=>$feedback->created_at,'updated_at'=>$feedback->updated_at
                ]);
            }
        }
        // Preserve original terms and every FK reference before coalescing compatible
        // overlaps. ProjectMember itself is historical project evidence; commercial
        // rate dates are never fabricated into project assignment dates.
        foreach (array_unique(array_values($ids)) as $memberId) {
            $terms=DB::table('member_terms')->where('client_member_id',$memberId)->orderBy('valid_from')->orderBy('id')->get();
            $groups=[];
            foreach($terms as $term) {
                if ($term->valid_to !== null && $term->valid_to < $term->valid_from) {
                    throw new RuntimeException('Invalid legacy commercial interval; reconcile before deployment.');
                }
                $last=count($groups)-1;
                if($last >= 0 && ($groups[$last]['to']===null || $groups[$last]['to'] >= $term->valid_from)) {
                    $base=$groups[$last]['terms'][0];
                    foreach(['technology','level','hourly_rate','hours_per_day'] as $field) {
                        if(($base->$field===null)!==($term->$field===null) || ($base->$field!==null &&
                            (in_array($field,['hourly_rate','hours_per_day'],true) ? (string)(float)$base->$field!==(string)(float)$term->$field : $base->$field!==$term->$field))) {
                            throw new RuntimeException('Incompatible overlapping ClientMember terms; explicit reconciliation required.');
                        }
                    }
                    $groups[$last]['terms'][]=$term;
                    $groups[$last]['to']=($groups[$last]['to']===null || $term->valid_to===null)?null:max($groups[$last]['to'],$term->valid_to);
                } else $groups[]=['from'=>$term->valid_from,'to'=>$term->valid_to,'terms'=>[$term]];
            }
            foreach($groups as $group) {
                $canonical=$group['terms'][0];
                $sourceIds=array_map(fn($t)=>(int)$t->id,$group['terms']);
                if(count($sourceIds)>1) {
                    $clientId=DB::table('client_members')->where('id',$memberId)->value('client_id');
                    $protected=DB::table('reporting_periods')->where('client_id',$clientId)->where('status','<>','Новый')
                        ->whereDate('period_start','<=',$group['to']??'9999-12-31')->whereDate('period_end','>=',$group['from'])->exists();
                    if($protected) throw new RuntimeException('Protected reporting history overlaps coalesced terms; verified financial snapshot reconciliation required.');
                    $ambiguous=DB::table('reporting_period_approved_hours')->whereIn('member_term_id',$sourceIds)
                        ->select('reporting_period_id')->groupBy('reporting_period_id')->havingRaw('COUNT(*) > 1')->exists();
                    if($ambiguous) throw new RuntimeException('Multiple approved-hour origins map to one commercial interval; explicit reconciliation required.');
                }
                foreach($group['terms'] as $source) {
                    DB::table('member_term_lineage')->insert(['source_term_id'=>$source->id,'canonical_term_id'=>$canonical->id,
                        'original_client_member_id'=>$memberId,'original_payload'=>json_encode((array)$source,JSON_THROW_ON_ERROR)]);
                    if($source->id!==$canonical->id) DB::table('member_terms')->where('id',$source->id)->update([
                        'client_member_id'=>null,'superseded_by_term_id'=>$canonical->id]);
                }
                DB::table('member_terms')->where('id',$canonical->id)->update(['valid_from'=>$group['from'],'valid_to'=>$group['to']]);
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE member_terms ADD CONSTRAINT member_terms_valid_dates CHECK (valid_to IS NULL OR valid_to >= valid_from)");
            DB::statement("ALTER TABLE member_terms ADD CONSTRAINT member_terms_canonical_owner CHECK ((client_member_id IS NOT NULL AND superseded_by_term_id IS NULL) OR (client_member_id IS NULL AND superseded_by_term_id IS NOT NULL AND superseded_by_term_id <> id))");
            DB::unprepared(<<<'SQL'
CREATE FUNCTION clients_member_interval_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_TABLE_NAME='member_terms' AND NEW.client_member_id IS NULL THEN
    IF NOT EXISTS(SELECT 1 FROM member_terms t WHERE t.id=NEW.superseded_by_term_id AND t.client_member_id IS NOT NULL AND t.superseded_by_term_id IS NULL) THEN
      RAISE EXCEPTION 'Archived commercial term must point directly to a canonical term' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
  END IF;
  PERFORM 1 FROM client_members WHERE id=NEW.client_member_id FOR UPDATE;
  IF TG_TABLE_NAME='member_terms' THEN
    IF EXISTS(SELECT 1 FROM member_terms t WHERE t.client_member_id=NEW.client_member_id AND t.id<>NEW.id
      AND t.valid_from<=COALESCE(NEW.valid_to,'9999-12-31'::date) AND COALESCE(t.valid_to,'9999-12-31'::date)>=NEW.valid_from) THEN
      RAISE EXCEPTION 'Overlapping commercial terms' USING ERRCODE='23514';
    END IF;
  ELSE
    IF NOT EXISTS(SELECT 1 FROM projects p JOIN client_members m ON m.client_id=p.client_id WHERE p.id=NEW.project_id AND m.id=NEW.client_member_id) THEN
      RAISE EXCEPTION 'Project belongs to a different client' USING ERRCODE='23514';
    END IF;
    IF EXISTS(SELECT 1 FROM project_assignments a WHERE a.client_member_id=NEW.client_member_id AND a.project_id=NEW.project_id AND a.id<>NEW.id
      AND a.valid_from<=COALESCE(NEW.valid_to,'9999-12-31'::date) AND COALESCE(a.valid_to,'9999-12-31'::date)>=NEW.valid_from) THEN
      RAISE EXCEPTION 'Overlapping project assignment' USING ERRCODE='23514';
    END IF;
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER member_terms_interval_guard BEFORE INSERT OR UPDATE ON member_terms FOR EACH ROW EXECUTE FUNCTION clients_member_interval_guard();
CREATE TRIGGER project_assignments_interval_guard BEFORE INSERT OR UPDATE ON project_assignments FOR EACH ROW EXECUTE FUNCTION clients_member_interval_guard();
SQL);
        }

    }
    public function down(): void
    {
        // Backwards schema migration cannot represent client-only terms.
        if (DB::table('member_terms')->whereNull('project_member_id')->exists()) {
            throw new RuntimeException('Client-only commercial terms require explicit reconciliation before rollback');
        }
        if(DB::getDriverName()==='pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS member_terms_interval_guard ON member_terms');
            DB::statement('DROP TRIGGER IF EXISTS project_assignments_interval_guard ON project_assignments');
            DB::statement('DROP FUNCTION IF EXISTS clients_member_interval_guard()');
            DB::statement('ALTER TABLE member_terms DROP CONSTRAINT IF EXISTS member_terms_canonical_owner');
        }
        foreach(DB::table('member_term_lineage')->orderBy('id')->get() as $lineage) {
            $original=json_decode($lineage->original_payload,true,512,JSON_THROW_ON_ERROR);
            DB::table('member_terms')->where('id',$lineage->source_term_id)->update([
                'valid_from'=>$original['valid_from'],'valid_to'=>$original['valid_to'],'superseded_by_term_id'=>null]);
        }
        Schema::dropIfExists('member_term_lineage');
        Schema::table('member_terms', fn(Blueprint $t) => $t->dropConstrainedForeignId('superseded_by_term_id'));
        Schema::table('member_terms', fn(Blueprint $t) => $t->dropConstrainedForeignId('client_member_id'));
        Schema::table('member_terms', fn(Blueprint $t) => $t->unsignedBigInteger('project_member_id')->nullable(false)->change());
        Schema::table('connection_attempts', fn(Blueprint $t) => $t->dropConstrainedForeignId('client_member_id'));
        Schema::dropIfExists('client_member_feedbacks');
        Schema::dropIfExists('project_assignments');
        Schema::dropIfExists('client_members');
    }
};
