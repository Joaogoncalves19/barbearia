<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Enums\NoteVisibility;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Identity\Models\User;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Anotacoes dos profissionais sobre o cliente (Fase 12.5): preferencias e
 * cuidados ("disfarcado na zero", "pele sensivel"). E o lugar onde o
 * importador ja guarda as notas do barbeiro do sistema antigo (visibilidade
 * "profissionais"); as anotacoes internas da equipe ("equipe") nao aparecem
 * para o profissional.
 *
 * Quem pode ler e registrar e a CustomerPolicy (customers.notes_own + o
 * cliente ter agendamento com ele). A auditoria registra quem, quando e o
 * tamanho do texto, nunca o texto (pode ter dado sensivel). Nao entram na
 * exportacao de dados do cliente (anotacao interna, CustomerDataExport).
 */
final class CustomerNotes
{
    public const MAX_LENGTH = 500;

    /**
     * @return Collection<int, CustomerNote>
     */
    public function forProfessionals(Customer $customer): Collection
    {
        return CustomerNote::query()
            ->where('customer_id', $customer->id)
            ->where('visibility', NoteVisibility::Professionals->value)
            ->latest('id')
            ->get();
    }

    public function add(Customer $customer, User $author, string $body): CustomerNote
    {
        $texto = trim($body);
        if (mb_strlen($texto) < 2 || mb_strlen($texto) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Anotação precisa ter entre 2 e '.self::MAX_LENGTH.' caracteres.');
        }

        $nota = CustomerNote::query()->create([
            'customer_id' => $customer->id,
            'author_user_id' => $author->id,
            'author_label' => $author->professional->display_name ?? $author->name,
            'visibility' => NoteVisibility::Professionals,
            'body' => $texto,
        ]);

        AuditTrail::record('customer.note_added', $customer, $author, 'Anotação sobre o cliente registrada pelo profissional.', [
            'anotacao_id' => $nota->id, 'tamanho' => mb_strlen($texto),
        ]);

        return $nota;
    }

    /** So quem escreveu remove (corrigir um engano); fica a auditoria. */
    public function remove(CustomerNote $note, User $actor): void
    {
        if ($note->author_user_id !== $actor->id) {
            throw new InvalidArgumentException('Só quem escreveu pode remover a anotação.');
        }

        $cliente = $note->customer;
        $tamanho = mb_strlen((string) $note->body);
        $id = $note->id;
        $note->delete();

        AuditTrail::record('customer.note_removed', $cliente, $actor, 'Anotação sobre o cliente removida por quem a escreveu.', [
            'anotacao_id' => $id, 'tamanho' => $tamanho,
        ]);
    }
}
