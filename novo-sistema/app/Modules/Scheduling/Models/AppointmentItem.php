<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item do atendimento com nome, preco e duracao FOTOGRAFADOS. Alterar o
 * catalogo depois nao muda este registro. So inclusao: corrigir um item de
 * atendimento concluido e feito por ajuste/estorno.
 *
 * Preco nulo so e permitido para item antigo cujo valor nao pode ser
 * reconstituido (price_source = legacy_unknown). Nunca se inventa um valor.
 */
class AppointmentItem extends Model
{
    use AppendOnly;

    protected $table = 'appointment_items';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'price_source' => PriceSource::class,
            'quantity' => 'integer',
            'unit_price_cents' => 'integer',
            'total_cents' => 'integer',
            'cost_cents' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $i): void {
            $semPreco = $i->unit_price_cents === null;
            if ($semPreco !== ($i->price_source === PriceSource::LegacyUnknown)) {
                throw DomainRuleViolation::rule('R-PRECO', 'Preco nulo somente para item antigo sem valor conhecido (legacy_unknown).');
            }
            if (($i->quantity ?? 1) < 1) {
                throw DomainRuleViolation::rule('R-PRECO', 'Quantidade deve ser ao menos 1.');
            }
            if (! $semPreco && $i->unit_price_cents < 0) {
                throw DomainRuleViolation::rule('R-DINHEIRO', 'Preco nao pode ser negativo.');
            }
            $i->total_cents = $semPreco ? null : $i->unit_price_cents * ($i->quantity ?? 1);
        });
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
