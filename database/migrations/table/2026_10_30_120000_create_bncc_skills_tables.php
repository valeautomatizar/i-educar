<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Habilidades da BNCC (Ensino Fundamental) e objetivos de aprendizagem
 * (Educação Infantil), e as que o professor ligou a cada aula registrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bncc_skills', function (Blueprint $table) {
            $table->string('code', 16)->primary();
            $table->string('stage', 2);
            // Componente curricular (EF) ou campo de experiências (EI).
            $table->string('component');
            // Anos do Ensino Fundamental; vazio na Educação Infantil.
            $table->jsonb('years');
            $table->string('age_group')->nullable();
            $table->text('knowledge_objects')->nullable();
            $table->text('description');
            // Texto sem acentos e em minúsculas, base da busca por palavras.
            $table->text('search_text');
        });

        DB::statement("ALTER TABLE bncc_skills ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('portuguese', search_text)) STORED");
        DB::statement('CREATE INDEX bncc_skills_search_vector_index ON bncc_skills USING gin (search_vector)');

        Schema::create('lesson_record_bncc_skill', function (Blueprint $table) {
            $table->foreignId('lesson_record_id')->constrained('lesson_records')->cascadeOnDelete();
            $table->string('bncc_skill_code', 16);
            $table->smallInteger('position')->default(0);

            $table->foreign('bncc_skill_code')->references('code')->on('bncc_skills')->cascadeOnDelete();
            $table->primary(['lesson_record_id', 'bncc_skill_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_record_bncc_skill');
        Schema::dropIfExists('bncc_skills');
    }
};
