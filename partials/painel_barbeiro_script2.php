<script>
    const barbeiro_id = "<?= htmlspecialchars($barbeiro_id) ?>";
    const nome_barbeiro_sessao = "<?= htmlspecialchars($barbeiro_atual['nome'] ?? 'Barbeiro') ?>";

    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'inicio';

    document.addEventListener("DOMContentLoaded", function() {
        const tabs = document.querySelectorAll('.tab-pane');
        const btns = document.querySelectorAll('.sidebar-btn[data-tab]');
        
        function switchTab(tabId) {
            tabs.forEach(t => t.classList.remove('active'));
            btns.forEach(b => b.classList.remove('active'));
            const targetTab = document.getElementById('tab-' + tabId);
            if(targetTab) targetTab.classList.add('active');
            const targetBtns = document.querySelectorAll(`.sidebar-btn[data-tab="${tabId}"]`);
            targetBtns.forEach(b => b.classList.add('active'));
            
            const newUrl = new URL(window.location);
            newUrl.searchParams.set('tab', tabId);
            window.history.pushState({}, '', newUrl);
        }
        
        if(document.getElementById('tab-' + activeTab)) { switchTab(activeTab); }
        btns.forEach(btn => { btn.addEventListener('click', function(e) { e.preventDefault(); switchTab(this.getAttribute('data-tab')); }); });

        const viewToggles = document.querySelectorAll('.btn-view-toggle[data-agenda-view]');
        viewToggles.forEach(btn => {
            btn.addEventListener('click', function() {
                const target = this.getAttribute('data-agenda-view');
                viewToggles.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                document.querySelectorAll('.hoje-view').forEach(el => el.style.display = 'none');
                const alvo = document.querySelector('.hoje-view-' + target);
                if (alvo) alvo.style.display = 'block';
            });
        });

        const btnLogoutBarbeiro = document.getElementById('btn-logout-barbeiro');
        if (btnLogoutBarbeiro) {
            btnLogoutBarbeiro.addEventListener('click', function(e) {
                e.preventDefault(); 
                const linkHref = this.getAttribute('href');
                this.innerHTML = '<i class="fa fa-circle-notch fa-spin"></i> <span>Saindo...</span>';
                this.style.opacity = '0.7'; this.style.pointerEvents = 'none'; 
                setTimeout(() => { window.location.href = linkHref; }, 800);
            });
        }

        const toggleDarkMode = document.getElementById('btn-dark-mode');
        if (toggleDarkMode) {
            if (document.documentElement.classList.contains('dark-mode')) { toggleDarkMode.innerHTML = '<i class="fa fa-sun"></i>'; }
            toggleDarkMode.addEventListener('click', function() {
                document.documentElement.classList.toggle('dark-mode');
                if (document.documentElement.classList.contains('dark-mode')) {
                    localStorage.setItem('theme', 'dark');
                    this.innerHTML = '<i class="fa fa-sun"></i>';
                } else {
                    localStorage.setItem('theme', 'light');
                    this.innerHTML = '<i class="fa fa-moon"></i>';
                }
            });
        }

        // As notificações de novos agendamentos são tratadas pelo motor
        // compartilhado js/notif_agendamentos.js (cartões ricos + som, responsivo).
    });
</script>
