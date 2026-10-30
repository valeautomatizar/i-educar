<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diário do professor: o que foi ensinado em cada aula (lesson_records) e
 * as anotações sobre a turma ou sobre um aluno (class_notes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_records', function (Blueprint $table) {
            $table->id();
            $table->integer('school_class_id');
            // Nulo em turma sem componentes, como na educação infantil.
            $table->integer('discipline_id')->nullable();
            $table->integer('user_id');
            $table->date('date');
            $table->text('content');
            $table->timestamps();

            $table->foreign('school_class_id')->references('cod_turma')->on('pmieducar.turma')->cascadeOnDelete();
            $table->foreign('discipline_id')->references('id')->on('modules.componente_curricular')->cascadeOnDelete();
            $table->foreign('user_id')->references('cod_usuario')->on('pmieducar.usuario')->cascadeOnDelete();
            $table->unique(['school_class_id', 'discipline_id', 'user_id', 'date'])->nullsNotDistinct();
        });

        Schema::create('class_notes', function (Blueprint $table) {
            $table->id();
            $table->integer('school_class_id');
            // Nulo quando a anotação é sobre a turma toda.
            $table->integer('student_id')->nullable();
            $table->integer('user_id');
            $table->date('date');
            $table->text('text');
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('school_class_id')->references('cod_turma')->on('pmieducar.turma')->cascadeOnDelete();
            $table->foreign('student_id')->references('cod_aluno')->on('pmieducar.aluno')->cascadeOnDelete();
            $table->foreign('user_id')->references('cod_usuario')->on('pmieducar.usuario')->cascadeOnDelete();
            $table->index(['school_class_id', 'date']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_notes');
        Schema::dropIfExists('lesson_records');
    }
};
