    <script>
        var countersAnimated = false;

        function animateCounters() {
            if (countersAnimated) return;
            const counters = document.querySelectorAll('.stat-number');
            const speed = 150;
            counters.forEach(counter => {
                const updateCount = () => {
                    const target = +counter.getAttribute('data-target');
                    const count = +counter.innerText;
                    const inc = target / speed;
                    if (count < target) {
                        counter.innerText = Math.ceil(count + inc);
                        setTimeout(updateCount, 15);
                    } else {
                        counter.innerText = target;
                    }
                };
                updateCount();
            });
            countersAnimated = true;
        }

        // Revela seções e grids conforme entram na tela
        const revealEls = document.querySelectorAll('.reveal, .reveal-stagger');
        if ('IntersectionObserver' in window) {
            const revealObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        if (entry.target.id === 'sobre') animateCounters();
                        revealObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.15 });
            revealEls.forEach(el => revealObserver.observe(el));
        } else {
            revealEls.forEach(el => el.classList.add('visible'));
            animateCounters();
        }

        // Destaca no menu flutuante a seção atualmente visível
        const navLinks = document.querySelectorAll('.nav-btn[data-section]');
        const spySections = document.querySelectorAll('section[id]');
        if ('IntersectionObserver' in window && navLinks.length && spySections.length) {
            const spyObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        navLinks.forEach(link => link.classList.toggle('active', link.dataset.section === entry.target.id));
                    }
                });
            }, { rootMargin: '-45% 0px -45% 0px', threshold: 0 });
            spySections.forEach(s => spyObserver.observe(s));
        }

        function openLegalModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        function closeLegalModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape") {
                document.querySelectorAll('.legal-modal').forEach(m => m.style.display = 'none');
            }
        });

        document.querySelectorAll('.legal-modal').forEach(modal => {
            modal.addEventListener('click', function(event) {
                if (event.target === this) {
                    this.style.display = 'none';
                }
            });
        });
    </script>
