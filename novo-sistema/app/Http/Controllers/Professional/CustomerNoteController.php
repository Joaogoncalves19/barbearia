<?php

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Enums\NoteVisibility;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Customers\Services\CustomerNotes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Anotacoes do profissional sobre o proprio cliente (Fase 12.5). A rota
 * exige can:notes,customer (customers.notes_own + cliente dele; outro =
 * 404). Remover: so a anotacao que ele mesmo escreveu, do mesmo cliente.
 */
class CustomerNoteController extends Controller
{
    use ResolvesProfessional;

    public function store(Request $request, Customer $customer, CustomerNotes $notes): RedirectResponse
    {
        $dados = $request->validate(['note' => ['required', 'string', 'min:2', 'max:'.CustomerNotes::MAX_LENGTH]], [], ['note' => 'anotação']);
        $notes->add($customer, $this->user($request), $dados['note']);

        return back()->with('status', 'Anotação registrada.');
    }

    public function destroy(Request $request, Customer $customer, CustomerNote $note, CustomerNotes $notes): RedirectResponse
    {
        abort_unless($note->customer_id === $customer->id && $note->visibility === NoteVisibility::Professionals
            && $note->author_user_id === $this->user($request)->id, 404);
        $notes->remove($note, $this->user($request));

        return back()->with('status', 'Anotação removida.');
    }
}
