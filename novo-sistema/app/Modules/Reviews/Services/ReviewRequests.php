<?php

namespace App\Modules\Reviews\Services;

use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Communication\Services\CustomerMessages;
use App\Modules\Communication\Support\CommunicationSettings;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Scheduling\Support\BusinessTime;

/**
 * Pedido de avaliacao (D-49): algumas horas depois da conclusao (padrao 3 h),
 * e-mail + aviso na conta, UMA vez por atendimento (chave de unicidade), e
 * so enquanto puder ser avaliado (30 dias, sem avaliacao, cliente
 * cadastrado). Rotina a cada 10 minutos (app:review-requests).
 */
final class ReviewRequests
{
    public function __construct(
        private readonly Reviews $reviews,
        private readonly CustomerMessages $messages,
    ) {}

    public function run(): int
    {
        $cfg = CommunicationSettings::current();
        if (! $cfg->bool('review_request_enabled')) {
            return 0;
        }
        $agora = BusinessTime::now();
        $n = 0;
        $candidatos = Attendance::query()->where('status', AttendanceStatus::Completed->value)->whereNotNull('customer_id')
            ->where('completed_at', '<=', $agora->subHours($cfg->int('review_request_delay_hours')))
            ->where('completed_at', '>=', $agora->subDays(Reviews::WINDOW_DAYS))
            ->whereNotExists(fn ($q) => $q->from('reviews')->whereColumn('reviews.attendance_id', 'attendances.id'))
            ->orderBy('id')->lazyById(200);
        foreach ($candidatos as $at) {
            if (CustomerNotification::query()->where('dedupe_key', 'review_request:'.$at->id)->exists() || $this->reviews->ineligibilityReason($at) !== null) {
                continue;
            }
            $this->messages->reviewRequest($at);
            $n++;
        }

        return $n;
    }
}
