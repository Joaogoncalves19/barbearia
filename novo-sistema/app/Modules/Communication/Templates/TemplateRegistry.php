<?php

namespace App\Modules\Communication\Templates;

use InvalidArgumentException;

/** Os modelos de e-mail do dominio, num lugar so (templates.md). */
final class TemplateRegistry
{
    /** @var array<string, EmailTemplate>|null */
    private ?array $all = null;

    /**
     * @return array<string, EmailTemplate>
     */
    public function all(): array
    {
        if ($this->all === null) {
            $lista = [
                new ReceiptTemplate,
                new BookingTemplate('confirmed'),
                new BookingTemplate('rescheduled'),
                new BookingTemplate('cancelled'),
                new ReminderTemplate('day_before'),
                new ReminderTemplate('hours_before'),
                new ReviewRequestTemplate,
                new SubscriptionTemplate('activated'),
                new SubscriptionTemplate('payment_failed'),
                new SubscriptionTemplate('cancel_scheduled'),
                new SubscriptionTemplate('cancelled'),
                new CampaignTemplate,
                new CampaignTemplate(test: true),
            ];
            $this->all = [];
            foreach ($lista as $t) {
                $this->all[$t->key()] = $t;
            }
        }

        return $this->all;
    }

    public function get(string $key): EmailTemplate
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Modelo de e-mail desconhecido: {$key}");
    }
}
