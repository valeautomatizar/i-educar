<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\LegacyRegistration;
use App\Models\LegacyStudent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Busca e ficha do aluno para a interface nova.
 */
class AlunoController extends Controller
{
    use EscopoEscolas;

    public function index(Request $request)
    {
        $busca = trim((string) $request->query('busca', ''));

        if (mb_strlen($busca) < 2) {
            return ['alunos' => []];
        }

        $escolas = $this->escolasDoUsuario($request->user());
        $digitos = preg_replace('/\D/', '', $busca);

        $alunos = LegacyStudent::query()
            ->where('aluno.ativo', 1)
            ->join('cadastro.pessoa', 'pessoa.idpes', '=', 'aluno.ref_idpes')
            ->join('cadastro.fisica', 'fisica.idpes', '=', 'aluno.ref_idpes')
            ->where(function ($q) use ($busca, $digitos) {
                $q->whereUnaccent('pessoa.nome', $busca);

                // CPF digitado com ou sem pontuação.
                if (strlen($digitos) === 11) {
                    $q->orWhere('fisica.cpf', (int) $digitos);
                }
            })
            ->when($escolas !== null, fn ($q) => $q->whereHas('registrations', fn ($r) => $r->whereIn('ref_ref_cod_escola', $escolas)))
            ->with(['lastRegistration.grade', 'lastRegistration.school.person', 'lastRegistration.school.organization'])
            ->orderBy('pessoa.nome')
            ->limit(20)
            ->get(['aluno.cod_aluno', 'aluno.ref_idpes', 'pessoa.nome', 'fisica.data_nasc']);

        return [
            'alunos' => $alunos->map(fn (LegacyStudent $aluno) => [
                'id' => $aluno->cod_aluno,
                'nome' => $aluno->nome,
                'detalhe' => $this->detalheBusca($aluno),
            ])->values(),
        ];
    }

    public function show(Request $request, int $aluno)
    {
        $escolas = $this->escolasDoUsuario($request->user());

        $student = LegacyStudent::query()
            ->with(['person', 'individual', 'registrations' => fn ($q) => $q->where('ativo', 1)->orderByDesc('ano')->orderByDesc('cod_matricula')])
            ->findOrFail($aluno);

        $matriculas = $student->registrations;

        if ($escolas !== null && $matriculas->whereIn('ref_ref_cod_escola', $escolas)->isEmpty()) {
            abort(403, 'Este aluno não é de uma das suas escolas.');
        }

        $matriculas->load(['grade', 'school.person', 'school.organization', 'lastEnrollment.schoolClass.period']);

        /** @var LegacyRegistration|null $atual */
        $atual = $matriculas->first();
        $turma = $atual?->lastEnrollment?->schoolClass;
        $nascimento = $student->individual?->data_nasc;

        return [
            'id' => $student->cod_aluno,
            'nome' => $student->person->name,
            'nascimento' => $nascimento?->format('Y-m-d'),
            'turma' => $turma?->nm_turma ?? $atual?->grade?->name,
            'turno' => $turma?->period?->name,
            'matricula' => $atual ? (string) $atual->cod_matricula : null,
            'situacao' => $atual ? SituacaoMatricula::grupo((int) $atual->aprovado) : null,
            'situacaoTexto' => $atual ? SituacaoMatricula::texto((int) $atual->aprovado) : null,
            'presenca' => null,
            'faltas' => null,
            'media' => null,
            'responsavel' => $student->getGuardianName(),
            'transporte' => null,
            'alertas' => [],
            'historico' => $matriculas->map(fn (LegacyRegistration $m) => [
                'ano' => (int) $m->ano,
                'serie' => $m->grade?->name,
                'escola' => $m->school?->name,
                'situacao' => SituacaoMatricula::grupo((int) $m->aprovado),
                'situacaoTexto' => SituacaoMatricula::texto((int) $m->aprovado),
            ])->values(),
        ];
    }

    private function detalheBusca(LegacyStudent $aluno): string
    {
        $partes = [];

        if ($aluno->data_nasc) {
            $partes[] = Carbon::parse($aluno->data_nasc)->age . ' anos';
        }

        $ultima = $aluno->lastRegistration;

        $partes[] = $ultima
            ? SituacaoMatricula::texto((int) $ultima->aprovado) . ' no ' . ($ultima->grade?->name ?? 'série não informada') . ' em ' . $ultima->ano . ($ultima->school?->name ? ', ' . $ultima->school->name : '')
            : 'sem matrícula na rede';

        return implode(' · ', $partes);
    }
}
