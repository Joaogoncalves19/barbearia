<?php

namespace App\Http\Controllers\Panel\Communication;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Enums\MessageCategory;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Mail\CommunicationMail;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Services\Outbox;
use App\Modules\Communication\Templates\TemplateRegistry;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Registro central de e-mails (emails.md §6): situacao, tentativas, erro e
 * motivo de nao envio. O endereco aparece MASCARADO; o corpo nao e guardado
 * (a pre-visualizacao dos modelos usa dados ficticios). Reenviar so o que
 * falhou (communications.retry), auditado.
 */
class EmailLogController extends Controller
{
    public function index(Request $request, TemplateRegistry $templates): View
    {
        $filtros = $request->validate([
            'situacao' => ['nullable', Rule::in(array_column(MessageStatus::cases(), 'value'))],
            'tipo' => ['nullable', Rule::in(array_column(MessageCategory::cases(), 'value'))],
            'modelo' => ['nullable', 'string', 'max:64'],
        ]);

        $q = EmailMessage::query()
            ->when($filtros['situacao'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filtros['tipo'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($filtros['modelo'] ?? null, fn ($q, $v) => $q->where('template', $v));

        return view('panel.emails.index', [
            'messages' => $q->orderByDesc('id')->paginate(30)->withQueryString(),
            'filters' => $filtros,
            'templates' => $templates->all(),
            'counts' => EmailMessage::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    /** Pre-visualizacao de um modelo com dados ficticios (mesmo layout do envio). */
    public function preview(string $template, TemplateRegistry $templates): Response
    {
        abort_unless($templates->has($template), 404);
        $mail = new CommunicationMail($templates->get($template)->preview(), 'previa');

        return response($mail->render())->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'self'");
    }

    public function retry(Request $request, EmailMessage $message, Outbox $outbox): RedirectResponse
    {
        if (! $outbox->retry($message)) {
            return back()->withErrors(['email' => 'Só e-mail com falha pode ser reenviado.']);
        }
        AuditTrail::record('email.retried', $message, $request->user(), 'E-mail reenviado para a fila ('.$message->template.').', ['para' => $message->maskedEmail()]);

        return back()->with('status', 'E-mail voltou para a fila de envio.');
    }
}
