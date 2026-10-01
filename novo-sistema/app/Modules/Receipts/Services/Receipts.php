<?php

namespace App\Modules\Receipts\Services;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Models\Advance;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\CommissionEntry;
use App\Modules\Finance\Models\CommissionPayout;
use App\Modules\Finance\Models\TipEntry;
use App\Modules\Finance\Services\CashRegister;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Models\GiftCard;
use App\Modules\Receipts\Enums\ReceiptType;
use App\Modules\Receipts\Mail\ReceiptMail;
use App\Modules\Receipts\Models\ReceiptDelivery;
use App\Modules\System\Models\Setting;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Comprovantes (comprovantes.md): um lugar so monta os dados de cada
 * documento (a MESMA fonte para a tela de impressao e para o e-mail) e envia
 * por e-mail, em fila, registrando cada envio (receipt_deliveries) e na
 * auditoria. Quem pode ver o documento e decidido na rota (policy/habilidade).
 */
final class Receipts
{
    public function __construct(private readonly CashRegister $cash) {}

    /**
     * Nome e contato da barbearia para o cabecalho (configuracao geral
     * importada do sistema antigo, ou o nome da aplicacao).
     *
     * @return array{name: string, phone: ?string, address: ?string}
     */
    public function business(): array
    {
        $geral = Setting::valueOf('legacy.config_geral', []);
        $geral = is_array($geral) ? $geral : [];

        return [
            'name' => is_string($geral['nome_barbearia'] ?? null) && $geral['nome_barbearia'] !== '' ? $geral['nome_barbearia'] : (string) config('app.name'),
            'phone' => is_string($geral['telefone_contato'] ?? null) ? $geral['telefone_contato'] : null,
            'address' => is_string($geral['endereco'] ?? null) ? $geral['endereco'] : null,
        ];
    }

    /**
     * Dados do documento para a view do tipo.
     *
     * @return array<string, mixed>
     */
    public function data(ReceiptType $type, int $id): array
    {
        $base = ['business' => $this->business(), 'type' => $type];

        return $base + match ($type) {
            ReceiptType::Attendance => ['attendance' => Attendance::query()->with(['items', 'discounts', 'payments' => fn ($q) => $q->orderBy('id')])->findOrFail($id)],
            ReceiptType::Payout => $this->payoutData(CommissionPayout::query()->with(['professional', 'createdBy', 'reversedBy'])->findOrFail($id)),
            ReceiptType::GiftCard => ['card' => GiftCard::query()->findOrFail($id)],
            ReceiptType::CashSession => $this->cashData(CashSession::query()->with(['openedBy', 'closedBy'])->findOrFail($id)),
        };
    }

    public function subject(ReceiptType $type, Model $doc): string
    {
        $nome = $this->business()['name'];

        return match ($type) {
            ReceiptType::Attendance => "{$nome} · Comprovante do atendimento ".$doc->getAttribute('code'),
            ReceiptType::Payout => "{$nome} · Recibo do repasse #".$doc->getKey(),
            ReceiptType::GiftCard => "{$nome} · Seu vale-presente",
            ReceiptType::CashSession => "{$nome} · Fechamento de caixa #".$doc->getKey(),
        };
    }

    /**
     * Envia o comprovante por e-mail (fila). Repetir com a mesma chave nao
     * envia de novo.
     *
     * @throws InvalidArgumentException e-mail invalido
     */
    public function send(ReceiptType $type, Model $doc, string $email, User|Customer $requester, string $key): ReceiptDelivery
    {
        $destino = mb_strtolower(trim($email));
        if (filter_var($destino, FILTER_VALIDATE_EMAIL) === false || mb_strlen($destino) > 255) {
            throw new InvalidArgumentException('Informe um e-mail válido.');
        }

        return DB::transaction(function () use ($type, $doc, $destino, $requester, $key): ReceiptDelivery {
            $existente = ReceiptDelivery::query()->where('request_key', $key)->first();
            if ($existente !== null) {
                return $existente;
            }
            $envio = ReceiptDelivery::query()->create([
                'receipt_type' => $type,
                'receipt_id' => (int) $doc->getKey(),
                'email' => $destino,
                'requested_by_user_id' => $requester instanceof User ? $requester->id : null,
                'requested_by_customer_id' => $requester instanceof Customer ? $requester->id : null,
                'request_key' => $key,
            ]);
            Mail::to($destino)->queue(new ReceiptMail($type, (int) $doc->getKey(), $this->subject($type, $doc)));
            AuditTrail::record('receipt.emailed', $doc, $requester instanceof User ? $requester : null, $type->label().' enviado por e-mail.', [
                'para' => self::mask($destino), 'pedido_por' => $requester instanceof Customer ? 'cliente' : 'equipe',
            ]);

            return $envio;
        });
    }

    /** "jo***@exemplo.test" para a auditoria (o envio guarda o endereco completo). */
    public static function mask(string $email): string
    {
        [$local, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$dominio;
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutData(CommissionPayout $p): array
    {
        $ids = fn (string $chave) => array_map(fn ($par) => (int) $par[0], (array) ($p->snapshot[$chave] ?? []));

        return [
            'payout' => $p,
            'commissions' => CommissionEntry::query()->whereIn('id', $ids('comissoes'))->with('attendance')->orderBy('occurred_at')->orderBy('id')->get(),
            'tips' => TipEntry::query()->whereIn('id', $ids('gorjetas'))->with('attendance')->orderBy('occurred_at')->orderBy('id')->get(),
            'advances' => Advance::query()->whereIn('id', $ids('vales'))->orderBy('id')->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cashData(CashSession $s): array
    {
        return [
            'session' => $s,
            'summary' => $this->cash->summary($s),
            'movements' => $s->movements()->with('createdBy')->orderBy('id')->get(),
        ];
    }
}
