<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chamada diária: a presença de cada aluno em cada dia de aula. As faltas da
 * etapa no boletim passam a ser a soma das faltas marcadas aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_attendances', function (Blueprint $table) {
            $table->id();
            $table->integer('school_class_id');
            $table->integer('registration_id');
            // Nulo quando a regra apura falta no geral, e não por componente.
            $table->integer('discipline_id')->nullable();
            $table->date('date');
            $table->smallInteger('stage');
            $table->boolean('present');
            $table->integer('user_id')->nullable();
            $table->timestamps();

            $table->foreign('school_class_id')->references('cod_turma')->on('pmieducar.turma')->cascadeOnDelete();
            $table->foreign('registration_id')->references('cod_matricula')->on('pmieducar.matricula')->cascadeOnDelete();
            $table->foreign('discipline_id')->references('id')->on('modules.componente_curricular')->cascadeOnDelete();
            $table->unique(['school_class_id', 'registration_id', 'discipline_id', 'date'])->nullsNotDistinct();
            $table->index(['registration_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_attendances');
    }
};
