{{-- EQUIPE: os profissionais do cadastro (ativos, que recebem agendamento e estao no site). --}}
<x-site.page title="Equipe" :description="'Conheça a equipe da '.$cfg->name().' e agende com quem você prefere.'" current="team">
    <header class="masthead" aria-labelledby="equipe-titulo">
        <div class="container masthead__inner">
            <p class="eyebrow">Equipe</p>
            <h1 id="equipe-titulo" class="display caps masthead__title">Quem cuida de você</h1>
            <p class="lead">Escolha um profissional para ver o que ele faz e os horários livres.</p>
        </div>
    </header>

    <div class="container page-body">
        @if ($team->isEmpty())
            <x-ui.empty-state title="Equipe em breve" icon="users">A equipe aparece aqui assim que for publicada pela barbearia.</x-ui.empty-state>
        @else
            <ul class="team team--grid" role="list">
                @foreach ($team as $pro)
                    <li>@include('site.partials.pro-card', ['pro' => $pro])</li>
                @endforeach
            </ul>
        @endif
    </div>
</x-site.page>
