<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Modules\System\Models\AuditLog;
use Illuminate\View\View;

/** Trilha de auditoria (so leitura; a trilha e so inclusao). */
class AuditLogController extends Controller
{
    public function index(): View
    {
        return view('panel.audit.index', [
            'entries' => AuditLog::query()->latest('id')->paginate(50),
        ]);
    }
}
