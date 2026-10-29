<?php

namespace App\Services\App;

use App\Events\RegistrationEvent;
use App\Exceptions\Registration\RegistrationException;
use App\Exceptions\Transfer\TransferException;
use App\Jobs\UpdateBlockedSituationRegistration;
use App\Models\LegacyCourse;
use App\Models\LegacyEnrollment;
use App\Models\LegacyGrade;
use App\Models\LegacyGradeSequence;
use App\Models\LegacyIndividual;
use App\Models\LegacyInstitution;
use App\Models\LegacyRegistration;
use App\Models\LegacySchoolAcademicYear;
use App\Models\LegacySchoolClass;
use App\Models\LegacySchoolGrade;
use App\Models\LegacyStudent;
use App\Models\RegistrationStatus;
use App\Services\EnrollmentService;
use App\Services\GradeService;
use App\Services\PromotionService;
use App\Services\SchoolClass\AvailableTimeService;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Faz a matrícula com enturmação pela interface nova, com as mesmas regras da
 * tela "Nova matrícula" (intranet/educar_matricula_cad.php). Só a matrícula
 * comum: dependência e cópia de enturmações continuam na tela antiga.
 */
class NovaMatriculaService
{
    private const APROVADO = [1, 12, 13];

    private const REPROVADO = [2, 14];

    public function __construct(private readonly User $usuario) {}

    /**
     * A série em que o aluno deve ser matriculado, pela última matrícula:
     * aprovado segue a sequência de séries, reprovado repete a série. Null
     * quando não há restrição (sem matrícula anterior ou transferido).
     */
    public function serieSeguinte(int $alunoId): ?int
    {
        $ultima = LegacyRegistration::query()
            ->where('ref_cod_aluno', $alunoId)
            ->where('ativo', 1)
            ->orderByDesc('cod_matricula')
            ->first(['ref_ref_cod_serie', 'aprovado']);

        if (!$ultima || (int) $ultima->aprovado === RegistrationStatus::TRANSFERRED) {
            return null;
        }

        if (in_array((int) $ultima->aprovado, self::APROVADO, true)) {
            $destino = LegacyGradeSequence::query()->whereGradeOrigin($ultima->ref_ref_cod_serie)->active()->value('ref_serie_destino');

            return $destino ? (int) $destino : -1;
        }

        if (in_array((int) $ultima->aprovado, self::REPROVADO, true)) {
            return (int) $ultima->ref_ref_cod_serie;
        }

        // Matrícula em andamento ou outra situação: a tela antiga recusa por sequência.
        return -1;
    }

    /**
     * @throws MatriculaRecusada
     */
    public function matricular(LegacyStudent $aluno, LegacySchoolClass $turma, Carbon $data, bool $confirmouFaixaEtaria = false): LegacyRegistration
    {
        $turma->loadMissing(['course', 'school.institution']);
        $instituicao = $turma->school->institution;
        $escola = (int) $turma->ref_ref_cod_escola;
        $curso = (int) $turma->ref_cod_curso;
        $serie = (int) $turma->ref_ref_cod_serie;
        $ano = (int) $turma->ano;
        $alunoId = (int) $aluno->getKey();

        if ($turma->course?->bloquear_novas_matriculas) {
            $this->recusar('Não foi possível realizar a matrícula, o curso selecionado está configurado para não permitir a realização de novas matrículas.');
        }

        if (!LegacyStudent::query()->whereKey($alunoId)->active()->exists()) {
            $this->recusar('Não é possível matricular alunos inativos ou inexistentes.');
        }

        if (!empty($instituicao->data_fechamento)) {
            $fechamento = Carbon::parse($instituicao->data_fechamento)->setYear($ano)->format('Y-m-d');

            if ($data->format('Y-m-d') > $fechamento) {
                $this->recusar('Não é possível matricular alunos após a data de fechamento.');
            }
        }

        if (LegacyIndividual::query()->whereHas('student', fn ($q) => $q->whereKey($alunoId))->value('falecido')) {
            $this->recusar('Não é possível matricular alunos falecidos.');
        }

        $serieSeguinte = $this->serieSeguinte($alunoId);

        if ($instituicao->bloqueia_matricula_serie_nao_seguinte && $serieSeguinte !== null && $serieSeguinte !== $serie) {
            $this->recusar('Não é possível matricular alunos em séries fora da sequência de enturmação.');
        }

        if ($instituicao->matricula_apenas_bairro_escola && strcasecmp((string) $this->bairro('pmieducar.escola', 'cod_escola', $escola), (string) $this->bairro('pmieducar.aluno', 'cod_aluno', $alunoId)) !== 0) {
            $this->recusar('O aluno deve morar no mesmo bairro da escola');
        }

        $anoEmAndamento = LegacySchoolAcademicYear::query()->whereSchool($escola)->whereYearEq($ano)->inProgress()->active()->exists();

        if (!$anoEmAndamento) {
            $this->recusar('O ano (letivo) selecionado não está em andamento na escola selecionada.');
        }

        $this->verificarVagas($turma, $escola, $curso, $serie, $ano);
        $this->verificarOutrasMatriculas($alunoId, $escola, $curso, $serie, $ano);
        $this->verificarFaixaEtaria($alunoId, $serie, $ano, $confirmouFaixaEtaria);

        if (LegacyGrade::query()->whereKey($serie)->value('exigir_inep') && !$aluno->inep()->exists()) {
            $this->recusar('Não foi possível realizar matrícula, necessário inserir o INEP no cadastro do aluno.');
        }

        $this->verificarDatas($turma, $instituicao, $alunoId, $ano, $data);

        if ($instituicao->isMandatoryCensoFields() && !(new AvailableTimeService)->onlySchoolClassesInformedOnCensus()->isAvailable($alunoId, $turma->getKey())) {
            $this->recusar('O aluno já está matriculado em uma turma com esse horário.');
        }

        return DB::transaction(function () use ($alunoId, $turma, $escola, $curso, $serie, $ano, $data) {
            $matricula = LegacyRegistration::query()->create([
                'ref_ref_cod_escola' => $escola,
                'ref_ref_cod_serie' => $serie,
                'ref_cod_curso' => $curso,
                'ref_cod_aluno' => $alunoId,
                'ano' => $ano,
                'ref_usuario_cad' => $this->usuario->getKey(),
                'aprovado' => RegistrationStatus::ONGOING,
                'ativo' => 1,
                'ultima_matricula' => 1,
                'dependencia' => false,
                'data_matricula' => $data->format('Y-m-d'),
            ]);

            app(EnrollmentService::class)->createFromScreen(
                registrationId: $matricula->getKey(),
                schoolClassId: $turma->getKey(),
                createdBy: $this->usuario->getKey(),
                enrollmentDate: $data->format('Y-m-d'),
            );

            $this->continuarTransferencia($matricula, $alunoId, $serie, $ano);

            return $matricula->refresh();
        });
    }

    /**
     * Matrícula nova de quem foi transferido na mesma série e ano: copia os
     * dados da matrícula antiga, como a tela antiga faz.
     */
    private function continuarTransferencia(LegacyRegistration $matricula, int $alunoId, int $serie, int $ano): void
    {
        $anterior = LegacyRegistration::query()
            ->active()
            ->where('ref_cod_aluno', $alunoId)
            ->where('ref_ref_cod_serie', $serie)
            ->where('ano', $ano)
            ->whereNot('cod_matricula', $matricula->getKey())
            ->orderByDesc('cod_matricula')
            ->first();

        if ((int) $anterior?->aprovado !== RegistrationStatus::TRANSFERRED) {
            return;
        }

        $matricula->update(['bloquear_troca_de_situacao' => true]);

        try {
            event(new RegistrationEvent(registration: $matricula));
        } catch (TransferException) {
            // A tela antiga também segue sem os dados copiados nesse caso.
        } catch (RegistrationException $exception) {
            $this->recusar('Não é possível concluir a matrícula. ' . $exception->getMessage());
        }

        (new PromotionService(enrollment: $matricula->enrollments()->first()))->fakeRequest();

        UpdateBlockedSituationRegistration::dispatch($matricula)->delay(now()->addMinute());
    }

    private function verificarVagas(LegacySchoolClass $turma, int $escola, int $curso, int $serie, int $ano): void
    {
        $bloquearSemVaga = LegacySchoolGrade::query()
            ->joinGradeCourse()
            ->whereSchool($escola)
            ->whereGrade($serie)
            ->active()
            ->value('bloquear_enturmacao_sem_vagas');

        if (!$bloquearSemVaga) {
            return;
        }

        if ($turma->getTotalEnrolled() >= (int) $turma->max_aluno) {
            $this->recusar('Não existem vagas disponíveis para essa turma!');
        }

        $turmasDoTurno = LegacySchoolClass::query()
            ->where('ref_ref_cod_serie', $serie)
            ->where('ref_ref_cod_escola', $escola)
            ->where('turma_turno_id', $turma->turma_turno_id)
            ->where('ano', $ano)
            ->where('ativo', 1);

        $naFila = LegacyRegistration::query()
            ->where('ano', $ano)
            ->where('ref_ref_cod_escola', $escola)
            ->where('ref_cod_curso', $curso)
            ->where('ref_ref_cod_serie', $serie)
            ->where('turno_pre_matricula', $turma->turma_turno_id)
            ->where('aprovado', 11)
            ->count();

        $enturmadosNoTurno = LegacyEnrollment::query()
            ->active()
            ->whereHas('registration', fn ($q) => $q->whereGrade($serie)->whereCourse($curso)->whereSchool($escola)->where('dependencia', false)->whereHas('student', fn ($q) => $q->active()))
            ->whereHas('schoolClass', fn ($q) => $q->where('turma_turno_id', $turma->turma_turno_id)->where('ano', $ano))
            ->count();

        if ((int) $turmasDoTurno->sum('max_aluno') <= $naFila + $enturmadosNoTurno) {
            $this->recusar('Não existem vagas disponíveis para essa série/turno!');
        }
    }

    private function verificarOutrasMatriculas(int $alunoId, int $escola, int $curso, int $serie, int $ano): void
    {
        $atividadeComplementar = LegacyCourse::query()
            ->whereKey($curso)
            ->whereHas('educationType', fn ($q) => $q->where('atividade_complementar', true))
            ->exists();

        $nestaEscola = LegacyRegistration::query()
            ->filter(['yearEq' => $ano, 'school' => $escola, 'course' => $curso, 'student' => $alunoId])
            ->active()
            ->with('grade:cod_serie,nm_serie')
            ->where('aprovado', RegistrationStatus::ONGOING)
            ->where('dependencia', false)
            ->get(['ref_ref_cod_serie', 'ref_cod_curso']);

        if ($nestaEscola->isNotEmpty()) {
            if ($nestaEscola->firstWhere('ref_ref_cod_serie', $serie) && !$atividadeComplementar) {
                $this->recusar('Este aluno já está matriculado nesta série e curso, não é possivel matricular um aluno mais de uma vez na mesma série.');
            }

            if (LegacyCourse::query()->whereKey($curso)->value('multi_seriado') != 1) {
                $nomes = $nestaEscola->pluck('grade.nm_serie')->unique()->implode(', ');
                $this->recusar("Este aluno já está matriculado no(a) '{$nomes}' deste curso e escola. Como este curso não é multisseriado, não é possivel manter mais de uma matricula em andamento para o mesmo curso.");
            }

            return;
        }

        $emOutraEscola = LegacyRegistration::query()
            ->with(['grade:cod_serie,nm_serie', 'course:cod_curso,nm_curso', 'school.organization'])
            ->where('ativo', 1)
            ->where('ref_ref_cod_escola', '!=', $escola)
            ->where('ref_cod_aluno', $alunoId)
            ->where('dependencia', false)
            ->where('aprovado', RegistrationStatus::ONGOING)
            ->whereDoesntHave('transferEnd', fn ($q) => $q->where('ativo', 1))
            ->first();

        if (!$emOutraEscola || $atividadeComplementar) {
            return;
        }

        $mesmoCursoAno = (int) $emOutraEscola->ref_cod_curso === $curso && (int) $emOutraEscola->ano === $ano;

        if ($mesmoCursoAno || config('legacy.app.matricula.multiplas_matriculas') == 0) {
            $nomeSerie = $emOutraEscola->grade?->nm_serie ?? '';
            $nomeCurso = $emOutraEscola->course?->nm_curso ?? '';
            $nomeEscola = $emOutraEscola->school?->organization?->fantasia ?? '';

            $this->recusar("Este aluno já está matriculado no(a) '{$nomeSerie}' do curso '{$nomeCurso}' na escola '{$nomeEscola}', para matricular este aluno na sua escola solicite transferência ao secretário(a) da escola citada.");
        }
    }

    private function verificarFaixaEtaria(int $alunoId, int $serie, int $ano, bool $confirmou): void
    {
        $dados = LegacyGrade::query()->find($serie, ['cod_serie', 'alerta_faixa_etaria', 'bloquear_matricula_faixa_etaria']);

        if (!$dados || (!$dados->alerta_faixa_etaria && !$dados->bloquear_matricula_faixa_etaria)) {
            return;
        }

        $nascimento = LegacyIndividual::query()->whereHas('student', fn ($q) => $q->whereKey($alunoId))->value('data_nasc');

        if (app(GradeService::class)->isAgeWithinRange(gradeId: $serie, birthDate: $nascimento, year: $ano)) {
            return;
        }

        if ($dados->bloquear_matricula_faixa_etaria) {
            $this->recusar('Não foi possível realizar a matrícula, pois a idade do aluno está fora da faixa etária da série');
        }

        if (!$confirmou) {
            throw new MatriculaRecusada('A idade do aluno está fora da faixa etária da série. Deseja continuar com a matrícula?', precisaConfirmar: true);
        }
    }

    private function verificarDatas(LegacySchoolClass $turma, LegacyInstitution $instituicao, int $alunoId, int $ano, Carbon $data): void
    {
        $saida = LegacyRegistration::query()
            ->where('ref_cod_aluno', $alunoId)
            ->where('ano', $ano)
            ->where('aprovado', RegistrationStatus::TRANSFERRED)
            ->whereHas('transferEnd', fn ($q) => $q->where('ativo', 1))
            ->orderByDesc('cod_matricula')
            ->value('data_cancel');

        if ($saida && $data->format('Y-m-d') <= Carbon::parse($saida)->format('Y-m-d')) {
            $this->recusar(sprintf('Não é possível matricular o aluno. O mesmo possui enturmação com data de saída anterior à data informada. Favor alterar a data de matrícula para ser superior a %s.', Carbon::parse($saida)->format('d/m/Y')));
        }

        $inicio = $turma->begin_academic_year;
        $fim = $turma->end_academic_year;

        if (empty($fim)) {
            $this->recusar('Não está definida a data de término do ano letivo.');
        }

        if ($inicio && $data->format('Y-m-d') < Carbon::parse($inicio)->format('Y-m-d') && !$instituicao->allowRegistrationOutAcademicYear) {
            $this->recusar(sprintf('A data de matrícula precisa ser igual ou maior que a data de início do ano letivo da escola ou turma (%s).', Carbon::parse($inicio)->format('d/m/Y')));
        }

        if ($data->format('Y-m-d') > Carbon::parse($fim)->format('Y-m-d')) {
            $this->recusar(sprintf('A data de matrícula precisa ser igual ou menor que a data fim do ano letivo da escola ou turma (%s).', Carbon::parse($fim)->format('d/m/Y')));
        }
    }

    private function bairro(string $tabela, string $chave, int $id): ?string
    {
        return DB::table('addresses as a')
            ->join('person_has_place as php', 'a.id', '=', 'php.place_id')
            ->join("{$tabela} as t", 't.ref_idpes', '=', 'php.person_id')
            ->where("t.{$chave}", $id)
            ->value('a.neighborhood');
    }

    private function recusar(string $mensagem): never
    {
        throw new MatriculaRecusada($mensagem);
    }
}
