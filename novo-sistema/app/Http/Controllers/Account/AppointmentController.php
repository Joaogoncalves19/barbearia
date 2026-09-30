<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\View\View;

/**
 * Um agendamento do proprio cliente. A rota ja passou pela
 * AppointmentPolicy@view (can:view,appointment): agendamento de outro
 * cliente responde 404, mesmo trocando o codigo na URL.
 */
class AppointmentController extends Controller
{
    public function show(Appointment $appointment): View
    {
        $appointment->load('items');

        return view('account.appointment', ['appointment' => $appointment]);
    }
}
