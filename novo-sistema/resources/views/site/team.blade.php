{{-- EQUIPE (Fase 11): os profissionais do cadastro (ativos, que recebem agendamento e estao no site). --}}
<x-site.page title="Equipe" :description="'Conheça a equipe da '.$cfg->name().' e agende com quem você prefere.'" current="team">
    <section class="section section--tight page-head-site" aria-labelledby="equipe-titulo">
        <div class="container stack">
            <p class="eyebrow">Equipe</p>
            <h1 id="equipe-titulo" class="h1">Quem cuida de você</h1>
            <p class="lead">Escolha um profissional para ver o que ele faz e os horários livres.</p>
        </div>
    </section>
    <div class="barber-stripe barber-stripe--thin" aria-hidden="true"></div>

    <section class="section section--tight">
        <div class="container">
            @if ($team->isEmpty())
                <x-ui.empty-state title="Equipe em breve" icon="users">A equipe aparece aqui assim que for publicada.</x-ui.empty-state>
            @else
                <ul class="team team--grid" role="list">
                    @foreach ($team as $pro)
                        <li>@include('site.partials.pro-card', ['pro' => $pro])</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>
</x-site.page>
