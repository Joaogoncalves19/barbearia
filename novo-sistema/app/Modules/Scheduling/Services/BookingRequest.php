<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonImmutable;

/**
 * Pedido de agendamento, o mesmo para qualquer canal (site, painel, e no
 * futuro o assistente). $professional nulo = "sem preferencia".
 * Cliente sem cadastro (so pelo balcao): $customer nulo + nome/telefone.
 */
final class BookingRequest
{
    public function __construct(
        public readonly Service $service,
        public readonly ?Professional $professional,
        public readonly CarbonImmutable $start,
        public readonly Channel $channel,
        public readonly AppointmentSource $source,
        public readonly ?Customer $customer = null,
        public readonly ?string $contactName = null,
        public readonly ?string $contactPhone = null,
        public readonly ?string $notes = null,
        public readonly User|Customer|null $actor = null,
    ) {}
}
