document.addEventListener('DOMContentLoaded', function () {

    // Instancias vivas dos graficos.
    //
    // Antes cada grafico era guardado em window.<nome>Chart -- e o navegador
    // ja publica todo elemento com id como global de mesmo nome. Como os
    // canvas se chamam novosRecorrentesChart, horariosPicoChart etc., o
    // "if (window.xChart) window.xChart.destroy()" pegava o CANVAS, nao a
    // instancia, e estourava "destroy is not a function" logo na primeira
    // renderizacao. O try/catch engolia o erro e o grafico simplesmente nunca
    // aparecia. Um objeto proprio nao colide com id nenhum.
    const charts = (window.__adminCharts = window.__adminCharts || {});


    // Formata valores em R$ nos eixos e tooltips dos gráficos do dashboard.
    function brl(valor) {
        return 'R$ ' + Number(valor || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderDashboardCharts() {
        if (typeof dashboardChartData === 'undefined') return;

        try {
            // --- Receita por dia (área) ---
            // Estava só na aba Relatórios; no dashboard é o gráfico que responde
            // "o faturamento está subindo ou caindo dentro do período?".
            var ctxReceita = document.getElementById('receitaDiariaDashChart');
            if (ctxReceita && dashboardChartData.receitaDiaria && dashboardChartData.receitaDiaria.labels.length) {
                if (charts.receitaDiariaDashChart) charts.receitaDiariaDashChart.destroy();

                var ctx2d = ctxReceita.getContext('2d');
                // A cor de destaque do painel é configurável, então lemos do CSS
                // em vez de fixar um azul que brigaria com o tema escolhido.
                var corTema = (getComputedStyle(document.documentElement).getPropertyValue('--secondary-color') || '#007bff').trim();
                var gradiente = ctx2d.createLinearGradient(0, 0, 0, 280);
                gradiente.addColorStop(0, 'rgba(59, 130, 246, .28)');
                gradiente.addColorStop(1, 'rgba(59, 130, 246, .02)');

                charts.receitaDiariaDashChart = new Chart(ctx2d, {
                    type: 'line',
                    data: {
                        labels: dashboardChartData.receitaDiaria.labels,
                        datasets: [{
                            label: 'Receita',
                            data: dashboardChartData.receitaDiaria.data,
                            borderColor: corTema,
                            backgroundColor: gradiente,
                            borderWidth: 2.5,
                            fill: true,
                            tension: 0.35,
                            pointRadius: dashboardChartData.receitaDiaria.labels.length > 31 ? 0 : 3,
                            pointBackgroundColor: '#fff',
                            pointBorderColor: corTema,
                            pointBorderWidth: 2,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: { label: function (ctx) { return brl(ctx.parsed.y); } }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: { color: 'rgba(148, 163, 184, .15)' },
                                ticks: {
                                    color: '#94a3b8',
                                    callback: function (v) { return 'R$ ' + Number(v).toLocaleString('pt-BR'); }
                                }
                            },
                            x: {
                                grid: { display: false },
                                ticks: { color: '#94a3b8', maxRotation: 0, autoSkipPadding: 14 }
                            }
                        }
                    }
                });
            }

            // --- Novos vs. recorrentes (rosca) ---
            var ctxNovos = document.getElementById('novosRecorrentesChart');
            if (ctxNovos && dashboardChartData.novosRecorrentes) {
                if (charts.novosRecorrentesChart) charts.novosRecorrentesChart.destroy();
                var dadosNovos = dashboardChartData.novosRecorrentes.data || [];
                var totalNovos = dadosNovos.reduce(function (a, b) { return a + Number(b || 0); }, 0);

                charts.novosRecorrentesChart = new Chart(ctxNovos.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: dashboardChartData.novosRecorrentes.labels,
                        datasets: [{
                            data: dadosNovos,
                            backgroundColor: ['#a855f7', '#3b82f6'],
                            borderWidth: 0,
                            hoverOffset: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '62%',
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 12, padding: 16, color: '#475569' } },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var perc = totalNovos > 0 ? Math.round((ctx.parsed / totalNovos) * 100) : 0;
                                        return ctx.label + ': ' + ctx.parsed + ' (' + perc + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        } catch (e) {
            console.error("Erro ao renderizar gráficos do Dashboard:", e);
        }
    }

    function renderRelatoriosCharts() {
        if (typeof relatoriosChartData === 'undefined') return;
        
        try {
            var ctx1 = document.getElementById('receitaVsDespesaChart'); 
            if (ctx1 && relatoriosChartData.receitaDiaria) { 
                if (charts.receitaChart) charts.receitaChart.destroy(); 
                charts.receitaChart = new Chart(ctx1.getContext('2d'), { 
                    type: 'bar', 
                    data: { 
                        labels: relatoriosChartData.receitaDiaria.labels, 
                        datasets: [{ 
                            label: 'Receita Bruta (R$)', 
                            data: relatoriosChartData.receitaDiaria.data, 
                            backgroundColor: 'rgba(40, 167, 69, 0.7)' 
                        }] 
                    }, 
                    options: { scales: { y: { beginAtZero: true } }, plugins: { title: { display: true, text: 'Receita Bruta Diária no Período' } } } 
                }); 
            }
            
            var ctx2 = document.getElementById('analiseDescontosChart'); 
            if (ctx2 && relatoriosChartData.analiseDescontos) { 
                if (charts.descontosChart) charts.descontosChart.destroy(); 
                charts.descontosChart = new Chart(ctx2.getContext('2d'), { 
                    type: 'pie', 
                    data: { 
                        labels: relatoriosChartData.analiseDescontos.labels, 
                        datasets: [{ 
                            data: relatoriosChartData.analiseDescontos.data, 
                            backgroundColor: ['#0e7be1', '#ffc107', '#dc3545', '#17a2b8', '#6c757d', '#6f42c1', '#28a745', '#fd7e14'] 
                        }] 
                    }, 
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        plugins: { title: { display: true, text: 'Valor Total por Tipo de Desconto (R$)' } } 
                    } 
                }); 
            }

            var ctxPlanos = document.getElementById('planosPopularesChart');
            if (ctxPlanos && relatoriosChartData.popularidadePlanos) {
                if (charts.planosChart) charts.planosChart.destroy();
                charts.planosChart = new Chart(ctxPlanos.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: relatoriosChartData.popularidadePlanos.labels,
                        datasets: [{
                            label: 'Número de Assinantes Ativos',
                            data: relatoriosChartData.popularidadePlanos.data,
                            backgroundColor: 'rgba(111, 66, 193, 0.7)',
                            borderColor: 'rgba(111, 66, 193, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } },
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } }
                    }
                });
            }

            var ctxFontes = document.getElementById('fontesReceitaChart');
            if (ctxFontes && relatoriosChartData.fontesReceita) {
                if (charts.fontesChart) charts.fontesChart.destroy();
                charts.fontesChart = new Chart(ctxFontes.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: relatoriosChartData.fontesReceita.labels,
                        datasets: [{
                            data: relatoriosChartData.fontesReceita.data,
                            backgroundColor: ['#007bff', '#6f42c1', '#28a745']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { title: { display: true, text: 'Composição da Receita no Período' } }
                    }
                });
            }
            
            var ctx3 = document.getElementById('ocupacaoBarbeiroChart'); 
            if (ctx3 && relatoriosChartData.ocupacaoBarbeiro) { 
                if (charts.ocupacaoChart) charts.ocupacaoChart.destroy(); 
                charts.ocupacaoChart = new Chart(ctx3.getContext('2d'), { 
                    type: 'bar', 
                    data: { 
                        labels: relatoriosChartData.ocupacaoBarbeiro.labels, 
                        datasets: [{ 
                            label: 'Taxa de Ocupação (%)', 
                            data: relatoriosChartData.ocupacaoBarbeiro.data, 
                            backgroundColor: 'rgba(23, 162, 184, 0.7)' 
                        }] 
                    }, 
                    options: { 
                        indexAxis: 'y', 
                        scales: { x: { beginAtZero: true, max: 100 } }, 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        plugins: { title: { display: true, text: 'Taxa de Ocupação por Barbeiro' } } 
                    } 
                }); 
            }
            
            var ctx4 = document.getElementById('agendamentosPorDiaSemanaChart'); 
            if (ctx4 && relatoriosChartData.agendamentosDiaSemana) { 
                if (charts.diaSemanaChart) charts.diaSemanaChart.destroy(); 
                charts.diaSemanaChart = new Chart(ctx4.getContext('2d'), { 
                    type: 'line', 
                    data: { 
                        labels: relatoriosChartData.agendamentosDiaSemana.labels, 
                        datasets: [{ 
                            label: 'Nº de Agendamentos', 
                            data: relatoriosChartData.agendamentosDiaSemana.data, 
                            borderColor: 'rgba(220, 53, 69, 0.8)', 
                            tension: 0.1, 
                            fill: false 
                        }] 
                    }, 
                    options: { scales: { y: { beginAtZero: true } }, plugins: { title: { display: true, text: 'Agendamentos por Dia da Semana' } } } 
                }); 
            }
            
            var ctx5 = document.getElementById('novosClientesChart'); 
            if (ctx5 && relatoriosChartData.novosClientes) { 
                if (charts.novosClientesChart) charts.novosClientesChart.destroy(); 
                charts.novosClientesChart = new Chart(ctx5.getContext('2d'), { 
                    type: 'doughnut', 
                    data: { 
                        labels: ['Clientes Recorrentes', 'Novos Clientes'], 
                        datasets: [{ 
                            data: relatoriosChartData.novosClientes.data, 
                            backgroundColor: ['#ffc107', '#0e7be1'] 
                        }] 
                    }, 
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        plugins: { title: { display: true, text: 'Novos Clientes vs. Recorrentes no Período' } } 
                    } 
                }); 
            }
        
            var ctxPico = document.getElementById('horariosPicoChart'); 
            if (ctxPico && relatoriosChartData.horariosPico) { 
                if (charts.horariosPicoChart) charts.horariosPicoChart.destroy(); 
                charts.horariosPicoChart = new Chart(ctxPico.getContext('2d'), { 
                    type: 'bar', 
                    data: { 
                        labels: relatoriosChartData.horariosPico.labels, 
                        datasets: [{ 
                            label: 'Nº de Agendamentos', 
                            data: relatoriosChartData.horariosPico.data, 
                            backgroundColor: 'rgba(0, 123, 255, 0.7)' 
                        }] 
                    }, 
                    options: {
                        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                        plugins: { title: { display: true, text: 'Horários de Pico (Agendamentos por Hora)' } }
                    }
                });
            }

            var ctxCmp = document.getElementById('comparativoPeriodoChart');
            if (ctxCmp && relatoriosChartData.comparativo) {
                if (charts.comparativoChart) charts.comparativoChart.destroy();
                charts.comparativoChart = new Chart(ctxCmp.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: relatoriosChartData.comparativo.labels,
                        datasets: [
                            { label: 'Período anterior', data: relatoriosChartData.comparativo.anterior, backgroundColor: 'rgba(148, 163, 184, 0.65)' },
                            { label: 'Período atual', data: relatoriosChartData.comparativo.atual, backgroundColor: 'rgba(14, 123, 225, 0.75)' }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true } },
                        plugins: { title: { display: true, text: 'Este período vs. anterior (R$)' } }
                    }
                });
            }

            var ctxForma = document.getElementById('formasPagamentoChart');
            if (ctxForma && relatoriosChartData.formasPagamento) {
                if (charts.formasPagamentoChart) charts.formasPagamentoChart.destroy();
                charts.formasPagamentoChart = new Chart(ctxForma.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: relatoriosChartData.formasPagamento.labels,
                        datasets: [{
                            data: relatoriosChartData.formasPagamento.data,
                            backgroundColor: ['#22c55e', '#0ea5e9', '#6366f1', '#f59e0b', '#94a3b8', '#cbd5e1']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { title: { display: true, text: 'Entradas por Forma de Pagamento (R$)' } }
                    }
                });
            }

            var ctxDespCat = document.getElementById('despesasCategoriaChart');
            if (ctxDespCat && relatoriosChartData.despesasCategoria) {
                if (charts.despesasCategoriaChart) charts.despesasCategoriaChart.destroy();
                charts.despesasCategoriaChart = new Chart(ctxDespCat.getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: relatoriosChartData.despesasCategoria.labels,
                        datasets: [{
                            data: relatoriosChartData.despesasCategoria.data,
                            backgroundColor: ['#ef4444', '#f97316', '#f59e0b', '#8b5cf6', '#0ea5e9', '#14b8a6', '#64748b', '#ec4899']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { title: { display: true, text: 'Despesas por Categoria (R$)' } }
                    }
                });
            }
        } catch (e) {
            console.error("Erro ao renderizar gráficos de relatórios:", e);
        }
    }

    function renderAvaliacoesCharts() {
        if (typeof avaliacoesChartData === 'undefined') return;

        try {
            var ctx6 = document.getElementById('distribuicaoNotasChart'); 
            if (ctx6 && avaliacoesChartData.distribuicaoNotas) { 
                if (charts.distribuicaoNotasChart) charts.distribuicaoNotasChart.destroy(); 
                charts.distribuicaoNotasChart = new Chart(ctx6.getContext('2d'), { 
                    type: 'bar', 
                    data: { 
                        labels: ['5 ★', '4 ★', '3 ★', '2 ★', '1 ★'], 
                        datasets: [{ 
                            label: 'Quantidade de Avaliações', 
                            data: avaliacoesChartData.distribuicaoNotas.data, 
                            backgroundColor: 'rgba(255, 193, 7, 0.7)' 
                        }] 
                    }, 
                    options: { scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }, plugins: { title: { display: true, text: 'Distribuição de Notas' } } } 
                }); 
            }
            
            var ctx7 = document.getElementById('mediaPorBarbeiroChart'); 
            if (ctx7 && avaliacoesChartData.mediaPorBarbeiro) { 
                if (charts.mediaBarbeiroChart) charts.mediaBarbeiroChart.destroy(); 
                charts.mediaBarbeiroChart = new Chart(ctx7.getContext('2d'), { 
                    type: 'bar', 
                    data: { 
                        labels: avaliacoesChartData.mediaPorBarbeiro.labels, 
                        datasets: [{ 
                            label: 'Média de Notas', 
                            data: avaliacoesChartData.mediaPorBarbeiro.data, 
                            backgroundColor: 'rgba(0, 123, 255, 0.7)' 
                        }] 
                    }, 
                    options: { 
                        indexAxis: 'y', 
                        scales: { x: { beginAtZero: true, max: 5 } }, 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        plugins: { title: { display: true, text: 'Média de Avaliações por Barbeiro' } } 
                    } 
                }); 
            }
        
            // --- CÓDIGO DO SENTIMENTO GERAL CORRIGIDO ---
            var ctxSentimento = document.getElementById('sentimentoGeralChart');
            if (ctxSentimento && avaliacoesChartData.sentimentoGeral) {
                if (charts.sentimentoGeralChart) charts.sentimentoGeralChart.destroy();
                
                var dadosSentimento = avaliacoesChartData.sentimentoGeral.data;
                // Calcula se a soma das avaliações é zero
                var totalAvaliacoes = dadosSentimento.reduce((a, b) => a + b, 0);
                
                var chartLabels = ['Positivas (4-5 ★)', 'Neutras (3 ★)', 'Negativas (1-2 ★)'];
                var chartColors = ['rgba(40, 167, 69, 0.8)', 'rgba(255, 193, 7, 0.8)', 'rgba(220, 53, 69, 0.8)'];
                var chartBorders = ['#28a745', '#ffc107', '#dc3545'];
                
                // Se estiver zerado, cria um "anel fantasma" cinzento para não ficar em branco
                if (totalAvaliacoes === 0) {
                    dadosSentimento = [1];
                    chartLabels = ['Sem dados no período'];
                    chartColors = ['rgba(226, 232, 240, 0.8)']; // Cinza claro
                    chartBorders = ['#cbd5e1'];
                }

                charts.sentimentoGeralChart = new Chart(ctxSentimento.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            data: dadosSentimento,
                            backgroundColor: chartColors,
                            borderColor: chartBorders,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '65%', // Torna o anel um pouco mais fino e moderno
                        plugins: {
                            title: { display: true, text: 'Sentimento Geral' },
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        if (totalAvaliacoes === 0) return ' Nenhuma avaliação registada.';
                                        return ' ' + context.label + ': ' + context.raw + ' votos';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        } catch (e) {
            console.error("Erro ao renderizar gráficos de avaliações:", e);
        }
    }

    const chartRenderers = {
        'dashboard': renderDashboardCharts,
        'relatorios': renderRelatoriosCharts,
        'avaliacoes': renderAvaliacoesCharts
    };

    // Executa renderizador inicial
    const urlParamsRender = new URLSearchParams(window.location.search);
    const initialTabRender = urlParamsRender.get('tab') || 'dashboard';
    
    if (chartRenderers[initialTabRender]) {
        try { chartRenderers[initialTabRender](); } catch(e) {}
    }

    // Escuta evento customizado de troca de aba
    document.addEventListener('tabChanged', function(e) {
        const tabName = e.detail;
        if (chartRenderers[tabName]) {
            try { setTimeout(chartRenderers[tabName], 100); } catch(error){}
        }
    });

});