<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\ServiceCatalog;
use App\Modules\Scheduling\Services\Availability;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Services\ProfessionalDirectory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Agendamento pelo site, SEM login previo: servico -> profissional (ou sem
 * preferencia) -> dia -> horario. O login so e pedido na confirmacao
 * (Account\BookingController). Tudo o que aparece vem das regras de dominio
 * (ServiceCatalog, ProfessionalDirectory, Availability): esta tela nao decide
 * nada sobre disponibilidade.
 */
class BookingController extends Controller
{
    public function services(ServiceCatalog $catalog): View
    {
        return view('site.booking.services', ['groups' => $catalog->bookableByCategory()]);
    }

    public function professional(Service $service, ProfessionalDirectory $directory): View
    {
        $this->assertBookable($service);

        return view('site.booking.professional', [
            'service' => $service,
            'professionals' => $directory->bookableFor($service),
        ]);
    }

    public function slots(Request $request, Service $service, ProfessionalDirectory $directory, Availability $availability): View
    {
        $this->assertBookable($service);
        $pro = $this->professionalFrom($request, $service, $directory);

        $dias = $availability->bookableDates(Channel::Customer);
        $data = (string) $request->query('data', $dias[0] ?? BusinessTime::today());
        if (! in_array($data, $dias, true)) {
            $data = $dias[0] ?? BusinessTime::today();
        }

        return view('site.booking.slots', [
            'service' => $service,
            'professional' => $pro,
            'days' => $dias,
            'date' => $data,
            'slots' => $availability->slots($service, $pro, $data, Channel::Customer),
        ]);
    }

    /** "qualquer" (ou ausente) = sem preferencia; slug precisa poder atender. */
    private function professionalFrom(Request $request, Service $service, ProfessionalDirectory $directory): ?Professional
    {
        $slug = (string) $request->query('profissional', 'qualquer');
        if ($slug === 'qualquer') {
            return null;
        }

        $pro = $directory->bookableFor($service)->first(fn (Professional $p) => $p->slug === $slug);
        abort_if($pro === null, 404);

        return $pro;
    }

    private function assertBookable(Service $service): void
    {
        abort_unless(Service::query()->whereKey($service->id)->bookable()->exists(), 404);
    }
}
