<script>
    // Variáveis PHP injetadas no JS
    const agendamentosDataById = <?= json_encode(array_reduce(array_merge($agendamentosAtivos, $agendamentosHistorico), function($acc, $ag){ $acc[$ag['id']] = $ag; return $acc; }, [])) ?> || {};
    const barbeirosData = <?= json_encode($barbeirosArr) ?> || {};
    const servicosData = <?= json_encode($servicosArr) ?> || {};
    const combosData = <?= json_encode($combosArr) ?> || {};
    const planosData = <?= json_encode($planosArr ?? []) ?> || {}; 
    const minhaContaCsrfToken = <?= json_encode($csrf_token) ?>;
    
    // Funções para exibição do alerta via AJAX
    function mostrarAlertaAjax(msg, tipo) {
        const container = document.getElementById('alerta-container');
        const icone = document.getElementById('alerta-icone');
        const texto = document.getElementById('alerta-texto');
        
        texto.innerText = msg;
        container.style.display = 'block';
        
        if (tipo === 'sucesso' || tipo === 'success') {
            container.style.backgroundColor = '#f0fdf4';
            container.style.borderColor = '#bbf7d0';
            container.style.color = '#15803d';
            icone.className = 'fa fa-check-circle';
        } else {
            container.style.backgroundColor = '#fef2f2';
            container.style.borderColor = '#fecaca';
            container.style.color = '#b91c1c';
            icone.className = 'fa fa-exclamation-circle';
        }
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Interceptador genérico para formulários AJAX
    function interceptarFormularioAjax(formId) {
        const form = document.getElementById(formId);
        if (!form) return;
        
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Aguarde...';
            btn.disabled = true;
            
            const formData = new FormData(form);
            formData.append('is_ajax', '1');
            
            fetch('cliente', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                
                const tipoConvertido = (data.status === 'success' || data.status === 'sucesso') ? 'sucesso' : 'erro';
                mostrarAlertaAjax(data.message, tipoConvertido);
                
                if (tipoConvertido === 'sucesso') {
                    if (formId === 'form-alterar-senha' || formId === 'form-reagendamento') {
                        document.querySelectorAll('.modal-overlay').forEach(m => {
                            m.classList.remove('active');
                            m.style.display = 'none'; 
                        });
                        form.reset();
                    }
                    if (formId === 'form-reagendamento') {
                        setTimeout(() => location.reload(), 1500); 
                    }
                }
            })
            .catch(err => {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                mostrarAlertaAjax('Ocorreu um erro de conexão com o servidor. Tente novamente.', 'erro');
                console.error(err);
            });
        });
    }

    // Lógica das Estrelinhas de Avaliação
    document.querySelectorAll('.rating-stars label').forEach(label => {
        label.addEventListener('mouseover', function() {
            let val = this.previousElementSibling.value;
            document.querySelectorAll('.rating-stars label').forEach(l => {
                if(l.previousElementSibling.value <= val) l.style.color = '#facc15';
                else l.style.color = '#cbd5e1';
            });
        });
    });
    document.querySelector('.rating-stars').addEventListener('mouseout', function() {
        let checked = document.querySelector('.rating-stars input:checked');
        let val = checked ? checked.value : 0;
        document.querySelectorAll('.rating-stars label').forEach(l => {
            if(l.previousElementSibling.value <= val) l.style.color = '#facc15';
            else l.style.color = '#cbd5e1';
        });
    });
    document.querySelectorAll('.rating-stars input').forEach(input => {
        input.addEventListener('change', function() {
            let val = this.value;
            document.querySelectorAll('.rating-stars label').forEach(l => {
                if(l.previousElementSibling.value <= val) l.style.color = '#facc15';
                else l.style.color = '#cbd5e1';
            });
        });
    });

    // Inicia os Scripts Principais
    document.addEventListener("DOMContentLoaded", function() {
        
        // Alternância de tema claro/escuro
        const btnTema = document.getElementById('btn-toggle-tema');
        const temaLabel = btnTema ? btnTema.querySelector('.theme-toggle-label') : null;
        function sincronizarLabelTema() {
            if (!temaLabel) return;
            const escuro = document.documentElement.getAttribute('data-theme') === 'dark';
            temaLabel.textContent = escuro ? 'Modo claro' : 'Modo escuro';
        }
        sincronizarLabelTema();
        if (btnTema) {
            btnTema.addEventListener('click', function() {
                const escuroAgora = document.documentElement.getAttribute('data-theme') === 'dark';
                if (escuroAgora) {
                    document.documentElement.removeAttribute('data-theme');
                    try { localStorage.setItem('cliente_tema', 'light'); } catch (e) {}
                } else {
                    document.documentElement.setAttribute('data-theme', 'dark');
                    try { localStorage.setItem('cliente_tema', 'dark'); } catch (e) {}
                }
                sincronizarLabelTema();
            });
        }

        const btnLogout = document.getElementById('btn-logout-cliente');
        if (btnLogout) {
            btnLogout.addEventListener('click', function(e) {
                e.preventDefault(); 
                const linkHref = this.getAttribute('href');
                
                this.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saindo...';
                this.style.opacity = '0.7';
                this.style.pointerEvents = 'none'; 
                
                setTimeout(() => {
                    window.location.href = linkHref;
                }, 1000);
            });
        }

        interceptarFormularioAjax('form-perfil');
        interceptarFormularioAjax('form-alterar-senha');
        interceptarFormularioAjax('form-reagendamento');

        // Exclusão de conta (fluxo próprio com redirecionamento)
        const formExcluir = document.getElementById('form-excluir-conta');
        if (formExcluir) {
            formExcluir.addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = formExcluir.querySelector('button[type="submit"]');
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Excluindo...';
                btn.disabled = true;

                const fd = new FormData(formExcluir);
                fd.append('is_ajax', '1');

                fetch('cliente', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            btn.innerHTML = '<i class="fa fa-check"></i> Conta excluída';
                            window.location.href = data.redirect || 'index';
                        } else {
                            btn.innerHTML = originalHtml;
                            btn.disabled = false;
                            document.querySelectorAll('.modal-overlay').forEach(m => { m.classList.remove('active'); m.style.display = 'none'; });
                            mostrarAlertaAjax(data.message || 'Não foi possível excluir a conta.', 'erro');
                        }
                    })
                    .catch(err => {
                        btn.innerHTML = originalHtml;
                        btn.disabled = false;
                        mostrarAlertaAjax('Erro de conexão. Tente novamente.', 'erro');
                        console.error(err);
                    });
            });
        }

        const notificationTriggers = document.querySelectorAll('[data-notification-trigger="true"]');
        notificationTriggers.forEach(trigger => {
            trigger.addEventListener('click', function() {
                const fd = new FormData();
                fd.append('action', 'marcar_lidas');
                fd.append('is_ajax', '1');
                fd.append('csrf_token', '<?= htmlspecialchars($csrf_token) ?>');
                
                fetch('cliente', { method: 'POST', body: fd })
                .then(() => {
                    let badge = document.getElementById('badge-notificacoes');
                    if(badge) badge.style.display = 'none';
                    document.querySelectorAll('.notificacoes-lista li.nao_lida').forEach(li => {
                        li.classList.remove('nao_lida');
                        li.style.background = '#fff';
                        li.style.borderLeft = '1px solid var(--border-soft)';
                    });
                }).catch(e => console.error(e));
            });
        });

        const historicoItems = document.querySelectorAll('.historico-item');
        const btnCarregarMais = document.getElementById('btn-carregar-mais-historico');
        let itensExibidos = 5;
        if (btnCarregarMais) {
            btnCarregarMais.addEventListener('click', function() {
                let max = itensExibidos + 5;
                for(let i = itensExibidos; i < max && i < historicoItems.length; i++) {
                    historicoItems[i].style.display = 'flex';
                }
                itensExibidos = max;
                if (itensExibidos >= historicoItems.length) btnCarregarMais.style.display = 'none';
            });
        }

        // Busca e filtro por status no histórico
        const buscaInput = document.getElementById('historico-busca');
        const statusSelect = document.getElementById('historico-filtro-status');
        const semResultados = document.getElementById('historico-sem-resultados');
        function filtroHistoricoAtivo() {
            return (buscaInput && buscaInput.value.trim() !== '') || (statusSelect && statusSelect.value !== '');
        }
        function aplicarFiltroHistorico() {
            const termo = (buscaInput ? buscaInput.value : '').trim().toLowerCase();
            const status = statusSelect ? statusSelect.value : '';
            if (!filtroHistoricoAtivo()) {
                historicoItems.forEach((it, i) => { it.style.display = i < itensExibidos ? 'flex' : 'none'; });
                if (btnCarregarMais) btnCarregarMais.style.display = (itensExibidos < historicoItems.length) ? '' : 'none';
                if (semResultados) semResultados.style.display = 'none';
                return;
            }
            let visiveis = 0;
            historicoItems.forEach(it => {
                const okBusca = !termo || (it.dataset.search || '').includes(termo);
                const okStatus = !status || it.dataset.status === status;
                const mostra = okBusca && okStatus;
                it.style.display = mostra ? 'flex' : 'none';
                if (mostra) visiveis++;
            });
            if (btnCarregarMais) btnCarregarMais.style.display = 'none';
            if (semResultados) semResultados.style.display = visiveis === 0 ? 'block' : 'none';
        }
        if (buscaInput) buscaInput.addEventListener('input', aplicarFiltroHistorico);
        if (statusSelect) statusSelect.addEventListener('change', aplicarFiltroHistorico);

        const historicoPontosItems = document.querySelectorAll('.historico-pontos-item');
        const btnCarregarMaisPontos = document.getElementById('btn-carregar-mais-pontos');
        let itensPontosExibidos = 5;
        if (btnCarregarMaisPontos) {
            btnCarregarMaisPontos.addEventListener('click', function() {
                let max = itensPontosExibidos + 5;
                for(let i = itensPontosExibidos; i < max && i < historicoPontosItems.length; i++) {
                    historicoPontosItems[i].style.display = 'flex'; 
                }
                itensPontosExibidos = max;
                if (itensPontosExibidos >= historicoPontosItems.length) btnCarregarMaisPontos.style.display = 'none';
            });
        }

        let cropper = null;
        const uploadInput = document.getElementById('upload-foto');
        const imageToCrop = document.getElementById('image-to-crop');
        const modalCropper = document.getElementById('modal-cropper');
        
        if(uploadInput) {
            uploadInput.addEventListener('change', function(e) {
                if (e.target.files && e.target.files.length > 0) {
                    const file = e.target.files[0];
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        imageToCrop.src = event.target.result;
                        modalCropper.classList.add('active');
                        if (cropper) cropper.destroy();
                        cropper = new Cropper(imageToCrop, {
                            aspectRatio: 1, viewMode: 1, dragMode: 'move', autoCropArea: 1, restore: false, guides: false, center: false, highlight: false, cropBoxMovable: true, cropBoxResizable: true, toggleDragModeOnDblclick: false,
                        });
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        document.getElementById('btn-crop-cancel')?.addEventListener('click', function() {
            modalCropper.classList.remove('active');
            uploadInput.value = '';
            if (cropper) { cropper.destroy(); cropper = null; }
        });

        document.getElementById('btn-crop-confirm')?.addEventListener('click', function() {
            if (!cropper) return;
            const canvas = cropper.getCroppedCanvas({ width: 400, height: 400 });
            const base64Image = canvas.toDataURL('image/png');
            document.getElementById('preview-foto').src = base64Image;
            document.getElementById('foto_perfil_base64').value = base64Image;
            modalCropper.classList.remove('active');
            if (cropper) { cropper.destroy(); cropper = null; }
        });
    });
</script>
