/* auth.js — comportamentos compartilhados das telas de autenticação */
(function () {
    'use strict';

    // 1) Mostrar / ocultar senha
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-toggle-password'));
            if (!input) return;
            var icon = btn.querySelector('i');
            var mostrar = input.type === 'password';
            input.type = mostrar ? 'text' : 'password';
            if (icon) {
                icon.classList.toggle('fa-eye', !mostrar);
                icon.classList.toggle('fa-eye-slash', mostrar);
            }
            btn.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
        });
    });

    // 2) Medidor de força de senha
    function avaliarForca(senha) {
        if (!senha) return 0;
        var score = 0;
        if (senha.length >= 8) score++;
        if (senha.length >= 12) score++;
        if (/[a-z]/.test(senha) && /[A-Z]/.test(senha)) score++;
        if (/\d/.test(senha)) score++;
        if (/[^A-Za-z0-9]/.test(senha)) score++;
        return Math.min(4, score);
    }
    var rotulos = ['', 'Fraca', 'Razoável', 'Boa', 'Forte'];

    document.querySelectorAll('[data-strength]').forEach(function (meter) {
        var input = document.getElementById(meter.getAttribute('data-strength'));
        if (!input) return;
        var label = meter.querySelector('.auth-strength-label b');
        input.addEventListener('input', function () {
            var score = avaliarForca(input.value);
            meter.setAttribute('data-score', input.value ? score : 0);
            if (label) label.textContent = input.value ? rotulos[score] : '—';
        });
    });

    // 3) Verificação "as senhas coincidem"
    document.querySelectorAll('[data-match]').forEach(function (confirm) {
        var alvo = document.getElementById(confirm.getAttribute('data-match'));
        var hint = document.getElementById(confirm.getAttribute('data-match-hint'));
        if (!alvo || !hint) return;
        function checar() {
            if (!confirm.value) { hint.classList.remove('show', 'ok', 'bad'); return; }
            var igual = confirm.value === alvo.value;
            hint.classList.add('show');
            hint.classList.toggle('ok', igual);
            hint.classList.toggle('bad', !igual);
            hint.innerHTML = igual
                ? '<i class="fa fa-check"></i> As senhas coincidem'
                : '<i class="fa fa-times"></i> As senhas não coincidem';
        }
        confirm.addEventListener('input', checar);
        alvo.addEventListener('input', checar);
    });

    // 4) Spinner ao enviar (sem atrasos artificiais)
    document.querySelectorAll('form[data-spinner]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('[type="submit"]');
            if (!btn || btn.dataset.loading) return;
            btn.dataset.loading = '1';
            btn.disabled = true;
            var texto = btn.getAttribute('data-loading-text') || 'Aguarde...';
            btn.innerHTML = '<i class="fa fa-spinner"></i> ' + texto;
        });
    });

    // 5) Máscaras (telefone / CPF) — reutilizáveis por data-mask
    document.querySelectorAll('[data-mask="telefone"]').forEach(function (el) {
        el.addEventListener('input', function (e) {
            var v = e.target.value.replace(/\D/g, '');
            v = v.replace(/^(\d{2})(\d)/g, '($1) $2').replace(/(\d{5})(\d)/, '$1-$2');
            e.target.value = v.slice(0, 15);
        });
    });
    document.querySelectorAll('[data-mask="cpf"]').forEach(function (el) {
        el.addEventListener('input', function (e) {
            var v = e.target.value.replace(/\D/g, '');
            v = v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            e.target.value = v.slice(0, 14);
        });
    });
})();
