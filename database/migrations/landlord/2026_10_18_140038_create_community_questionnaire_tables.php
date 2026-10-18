<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::connection('landlord')->create('community_questionnaires', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('kind', 12)->default('poll');
            $t->jsonb('audience');
            $t->string('state', 16)->default('draft');
            $t->timestampTz('opens_at')->nullable();
            $t->timestampTz('closes_at')->nullable();
            $t->timestampTz('results_published_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'id'], 'cq_tenant_id');
            $t->index(['tenant_id', 'state']);
        });
        Schema::connection('landlord')->create('community_questions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('questionnaire_id');
            $t->string('title');
            $t->string('type', 24);
            $t->unsignedInteger('position');
            $t->boolean('required')->default(true);
            $t->jsonb('settings')->nullable();
            $t->timestampsTz();
            $t->foreign(['tenant_id', 'questionnaire_id'], 'cquestion_form')->references(['tenant_id', 'id'])->on('community_questionnaires')->cascadeOnDelete();
            $t->unique(['tenant_id', 'questionnaire_id', 'id'], 'cquestion_context');
        });
        Schema::connection('landlord')->create('community_question_options', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('questionnaire_id');
            $t->uuid('question_id');
            $t->string('label');
            $t->unsignedInteger('position');
            $t->timestampsTz();
            $t->foreign(['tenant_id', 'questionnaire_id', 'question_id'], 'coption_question')->references(['tenant_id', 'questionnaire_id', 'id'])->on('community_questions')->cascadeOnDelete();
            $t->unique(['tenant_id', 'questionnaire_id', 'question_id', 'id'], 'coption_context');
        });
        Schema::connection('landlord')->create('community_submissions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('questionnaire_id');
            $t->uuid('member_id');
            $t->unsignedInteger('revision');
            $t->timestampsTz();
            $t->foreign(['tenant_id', 'questionnaire_id'], 'csubmission_form')->references(['tenant_id', 'id'])->on('community_questionnaires')->restrictOnDelete();
            $t->foreign(['tenant_id', 'member_id'], 'csubmission_member')->references(['tenant_id', 'id'])->on('members')->restrictOnDelete();
            $t->unique(['questionnaire_id', 'member_id'], 'csubmission_once');
            $t->unique(['tenant_id', 'questionnaire_id', 'id'], 'csubmission_context');
        });
        Schema::connection('landlord')->create('community_answers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('questionnaire_id');
            $t->uuid('submission_id');
            $t->uuid('question_id');
            $t->uuid('option_id')->nullable();
            $t->text('response_text')->nullable();
            $t->decimal('response_number', 18, 4)->nullable();
            $t->jsonb('response_payload')->nullable();
            $t->timestampsTz();
            $t->foreign(['tenant_id', 'questionnaire_id', 'submission_id'], 'canswer_submission')->references(['tenant_id', 'questionnaire_id', 'id'])->on('community_submissions')->cascadeOnDelete();
            $t->foreign(['tenant_id', 'questionnaire_id', 'question_id'], 'canswer_question')->references(['tenant_id', 'questionnaire_id', 'id'])->on('community_questions')->restrictOnDelete();
            $t->foreign(['tenant_id', 'questionnaire_id', 'question_id', 'option_id'], 'canswer_option')->references(['tenant_id', 'questionnaire_id', 'question_id', 'id'])->on('community_question_options')->restrictOnDelete();
            $t->unique(['submission_id', 'question_id'], 'canswer_once');
        });
        DB::connection('landlord')->statement("ALTER TABLE community_questionnaires ADD CONSTRAINT cq_state CHECK (state IN ('draft','open','closed','withdrawn')), ADD CONSTRAINT cq_kind CHECK (kind IN ('poll','survey')), ADD CONSTRAINT cq_window CHECK (opens_at IS NULL OR closes_at IS NULL OR closes_at > opens_at)");
    }

    public function down(): void
    {
        foreach (['community_answers', 'community_submissions', 'community_question_options', 'community_questions', 'community_questionnaires'] as $table) {
            Schema::connection('landlord')->dropIfExists($table);
        }
    }
};
