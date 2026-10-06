<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNotification;
use App\Modules\Loyalty\Pricing\PromotionEngine;
use App\Modules\Reviews\Services\Reviews;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inicio da area do cliente (Fase 12): o proximo horario e um resumo de cada
 * parte da conta (avisos, avaliacoes a fazer, beneficios de hoje,
 * assinatura). Tudo parte do cliente logado, nunca de um id da requisicao;
 * os numeros vem dos mesmos servicos das telas de cada parte.
 */
class AccountHomeController extends Controller
{
    public function __invoke(Request $request, PromotionEngine $engine, Reviews $reviews): View
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');
        $agora = BusinessTime::now();

        $proximos = $customer->appointments()->with('items')
            ->whereIn('status', ['pending', 'confirmed'])->where('ends_at', '>', $agora)
            ->orderBy('starts_at')->limit(3)->get();

        return view('account.home', [
            'customer' => $customer,
            'upcoming' => $proximos,
            'unread' => CustomerNotification::query()->where('customer_id', $customer->id)->whereNull('read_at')->count(),
            'pendingReviews' => $reviews->pendingFor($customer)->count(),
            'entitlements' => $engine->entitlements($customer, BusinessTime::today()),
            'subscription' => Subscription::query()->where('customer_id', $customer->id)->whereIn('status', SubscriptionStatus::currentValues())->with('plan')->first(),
            'lastReceipt' => Attendance::query()->where('customer_id', $customer->id)->where('status', AttendanceStatus::Completed->value)
                ->latest('completed_at')->first(),
        ]);
    }
}
