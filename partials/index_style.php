    <style>
        <?php
        $app_bg = '#0f172a';
        $app_primary = '#1e293b';
        $app_accent = $themeConfig['secondary_color'] ?? '#f59e0b';

        $hexP = str_replace('#', '', $app_primary);
        if(strlen($hexP) == 3) { $hexP = $hexP[0].$hexP[0].$hexP[1].$hexP[1].$hexP[2].$hexP[2]; }
        $rP = hexdec(substr($hexP,0,2)); $gP = hexdec(substr($hexP,2,2)); $bP = hexdec(substr($hexP,4,2));

        $hexB = str_replace('#', '', $app_bg);
        if(strlen($hexB) == 3) { $hexB = $hexB[0].$hexB[0].$hexB[1].$hexB[1].$hexB[2].$hexB[2]; }
        $rB = hexdec(substr($hexB,0,2)); $gB = hexdec(substr($hexB,2,2)); $bB = hexdec(substr($hexB,4,2));

        $hexA = str_replace('#', '', $app_accent);
        if(strlen($hexA) == 3) { $hexA = $hexA[0].$hexA[0].$hexA[1].$hexA[1].$hexA[2].$hexA[2]; }
        $rA = hexdec(substr($hexA,0,2)); $gA = hexdec(substr($hexA,2,2)); $bA = hexdec(substr($hexA,4,2));
        ?>

        :root {
            --app-bg: <?= htmlspecialchars($app_bg) ?>;
            --app-accent: <?= htmlspecialchars($app_accent) ?>;
            --app-accent-rgb: <?= $rA ?>, <?= $gA ?>, <?= $bA ?>;
            --app-primary: <?= htmlspecialchars($app_primary) ?>;
            --app-text: #f8fafc;
            --glass-bg: rgba(<?= $rP ?>, <?= $gP ?>, <?= $bP ?>, 0.85);
            --glass-border: rgba(255, 255, 255, 0.08);
            --font-heading: 'Outfit', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        html { scroll-behavior: smooth; }
        body, html {
            margin: 0; padding: 0; width: 100%;
            font-family: var(--font-body);
            background-color: var(--app-bg); color: var(--app-text);
            -webkit-font-smoothing: antialiased;
        }

        img { max-width: 100%; }

        /* ===================== BARRAS DE ROLAGEM ===================== */
        * { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.28) transparent; }
        *::-webkit-scrollbar { width: 6px; height: 6px; }
        *::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        *::-webkit-scrollbar-thumb { background-color: rgba(255,255,255,0.28); border-radius: 10px; border: 1px solid transparent; background-clip: padding-box; }
        *::-webkit-scrollbar-thumb:hover { background-color: var(--app-accent); }

        /* ===================== HERO ===================== */
        .hero { position: relative; width: 100%; height: 100vh; min-height: 560px; overflow: hidden; display: flex; flex-direction: column; }

        .bg-container { position: absolute; inset: 0; z-index: 1; background-color: var(--app-bg); }
        .bg-video { width: 100%; height: 100%; object-fit: cover; filter: brightness(0.6); }
        .bg-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(<?= $rB ?>,<?= $gB ?>,<?= $bB ?>,0.85) 0%, rgba(<?= $rB ?>,<?= $gB ?>,<?= $bB ?>,0.4) 50%, rgba(<?= $rB ?>,<?= $gB ?>,<?= $bB ?>,0.95) 100%);
        }

        /* Ações do Topo */
        .top-actions {
            position: absolute; top: 30px; right: 40px;
            display: flex; gap: 15px; z-index: 10;
        }
        .action-btn {
            background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border); color: #fff;
            padding: 12px 24px; border-radius: 30px; font-size: 0.95rem; font-weight: 600; font-family: var(--font-heading);
            text-decoration: none; display: flex; align-items: center; gap: 8px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .action-btn:hover { background: rgba(255, 255, 255, 0.15); transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.2); }
        .action-btn.admin-btn { border-color: transparent; background: transparent; color: #94a3b8; box-shadow: none; }
        .action-btn.admin-btn:hover { color: #fff; background: rgba(255,255,255,0.05); }

        /* Conteúdo do Hero */
        .hero-content {
            position: relative; z-index: 2; width: 100%; flex: 1;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            text-align: center; padding: 20px; padding-bottom: 50px; box-sizing: border-box;
        }

        .logo-main { max-width: 240px; width: auto; height: auto; object-fit: contain; margin-bottom: 1.5rem; animation: fadeScaleDown 1s cubic-bezier(0.175, 0.885, 0.32, 1.275); filter: drop-shadow(0 10px 20px rgba(0,0,0,0.5)); }
        .hero-title { font-family: var(--font-heading); font-size: clamp(2.5rem, 7vw, 5.5rem); font-weight: 900; margin: 0 0 10px 0; letter-spacing: -2px; animation: fadeInUp 1s ease-out 0.2s both; line-height: 1.1; text-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .hero-subtitle { font-size: clamp(1.1rem, 2.5vw, 1.4rem); color: #cbd5e1; margin: 0 0 40px 0; max-width: 650px; animation: fadeInUp 1s ease-out 0.4s both; font-weight: 300; }

        .hero-btn-wrapper {
            animation: fadeInUp 1s ease-out 0.5s both;
            margin-top: 10px; width: 100%;
            display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 18px;
        }

        .btn-agendar {
            background: var(--app-accent);
            color: #fff;
            padding: 22px 55px;
            border-radius: 50px;
            font-size: 1.4rem;
            font-weight: 900;
            font-family: var(--font-heading);
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 2px;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            box-shadow: 0 10px 30px rgba(var(--app-accent-rgb), 0.5);
            border: 2px solid rgba(255, 255, 255, 0.25);
            animation: pulse-agendar 2s infinite ease-in-out;
        }
        .btn-agendar:hover {
            transform: translateY(-5px) scale(1.03);
            filter: brightness(1.15);
            box-shadow: 0 15px 40px rgba(var(--app-accent-rgb), 0.8);
            animation: none;
        }
        @keyframes pulse-agendar {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(var(--app-accent-rgb), 0.7); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 18px rgba(var(--app-accent-rgb), 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(var(--app-accent-rgb), 0); }
        }

        .btn-agendar-secondary {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            color: #fff;
            padding: 20px 42px;
            border-radius: 50px;
            font-size: 1.05rem;
            font-weight: 700;
            font-family: var(--font-heading);
            text-decoration: none;
            border: 2px solid rgba(255, 255, 255, 0.25);
            display: inline-flex; align-items: center; justify-content: center; gap: 12px;
            transition: all 0.3s ease;
        }
        .btn-agendar-secondary:hover { background: rgba(255,255,255,0.14); transform: translateY(-3px); }

        .scroll-hint {
            position: absolute; bottom: 118px; left: 50%; transform: translateX(-50%);
            z-index: 2; color: rgba(255,255,255,0.55); font-size: 1.3rem;
            animation: bounceHint 2s infinite; text-decoration: none;
        }
        @keyframes bounceHint { 0%, 100% { transform: translate(-50%, 0); } 50% { transform: translate(-50%, 8px); } }

        /* Dock de navegação flutuante (fixo, visível durante toda a rolagem) */
        .bottom-nav {
            position: fixed; bottom: 30px; left: 0; right: 0; margin: 0 auto;
            display: flex; flex-wrap: wrap; justify-content: center; align-items: center;
            gap: 10px; z-index: 40;
            background: rgba(15, 23, 42, 0.72);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            padding: 12px 20px; border-radius: 24px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 20px 40px rgba(0,0,0,0.35);
            width: max-content; max-width: 92vw;
        }
        .nav-btn {
            background: transparent; border: none; color: #94a3b8;
            padding: 12px 20px; border-radius: 50px; cursor: pointer;
            font-size: 0.95rem; font-weight: 600; font-family: var(--font-heading);
            display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s ease;
            white-space: nowrap; text-decoration: none;
        }
        .nav-btn:hover, .nav-btn.active { background: rgba(255, 255, 255, 0.12); color: #fff; }
        .nav-btn i { font-size: 1.1rem; color: var(--app-accent); transition: transform 0.3s; }
        .nav-btn:hover i, .nav-btn.active i { transform: scale(1.2); }

        /* ===================== FAIXA DE PROVA SOCIAL ===================== */
        .proof-strip { position: relative; z-index: 2; max-width: 1100px; margin: -55px auto 0; padding: 0 40px; }
        .proof-card {
            background: var(--glass-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border); border-radius: 24px; padding: 28px 40px;
            display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 35px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.35);
        }
        .proof-item { display: flex; align-items: center; gap: 12px; }
        .proof-item i { font-size: 1.5rem; color: var(--app-accent); }
        .proof-value { font-family: var(--font-heading); font-size: 1.5rem; font-weight: 800; color: #fff; line-height: 1; }
        .proof-label { font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
        .proof-divider { width: 1px; height: 36px; background: var(--glass-border); }

        /* ===================== SEÇÕES DE CONTEÚDO ===================== */
        .content-section { position: relative; max-width: 1100px; margin: 0 auto; padding: 100px 40px 20px; }
        .section-title { font-family: var(--font-heading); font-size: 2.2rem; margin: 0 0 25px 0; color: #fff; text-align: center; font-weight: 800; letter-spacing: -1px; }
        .text-center { text-align: center; }

        /* Animações ao rolar */
        .reveal { opacity: 0; transform: translateY(35px); transition: opacity 0.8s cubic-bezier(0.16,1,0.3,1), transform 0.8s cubic-bezier(0.16,1,0.3,1); }
        .reveal.visible { opacity: 1; transform: none; }
        .reveal-stagger > * { opacity: 0; transform: translateY(25px); transition: opacity 0.6s ease, transform 0.6s ease; }
        .reveal-stagger.visible > * { opacity: 1; transform: none; }
        .reveal-stagger.visible > *:nth-child(1) { transition-delay: 0.05s; }
        .reveal-stagger.visible > *:nth-child(2) { transition-delay: 0.15s; }
        .reveal-stagger.visible > *:nth-child(3) { transition-delay: 0.25s; }
        .reveal-stagger.visible > *:nth-child(4) { transition-delay: 0.35s; }
        .reveal-stagger.visible > *:nth-child(5) { transition-delay: 0.45s; }

        /* Painéis de Vidro (Cards) */
        .glass-card {
            background: linear-gradient(145deg, rgba(255,255,255,0.05), rgba(255,255,255,0.01));
            border: 1px solid var(--glass-border); border-radius: 24px; padding: 35px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2); transition: all 0.3s ease; position: relative; overflow: hidden;
        }
        .glass-card::before {
            content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: radial-gradient(circle at top right, rgba(var(--app-accent-rgb), 0.1), transparent 60%);
            opacity: 0; transition: opacity 0.3s; pointer-events: none;
        }
        .glass-card:hover::before { opacity: 1; }

        /* Grid Sobre */
        .about-grid { display: grid; grid-template-columns: 1fr; gap: 30px; max-width: 900px; margin: 0 auto; }
        .stats-container { display: flex; justify-content: space-around; flex-wrap: wrap; gap: 20px; margin-top: 40px; padding-top: 40px; border-top: 1px solid var(--glass-border); }
        .stat-item { text-align: center; }
        .stat-number { font-family: var(--font-heading); font-size: 4rem; font-weight: 900; color: var(--app-accent); line-height: 1; margin-bottom: 5px; text-shadow: 0 5px 15px rgba(var(--app-accent-rgb), 0.3); }
        .stat-label { font-size: 0.95rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 2px; font-weight: 600; }

        /* Grid Serviços */
        .services-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; margin-bottom: 50px; }
        .service-card { text-align: center; cursor: pointer; }
        .service-card:hover { transform: translateY(-8px); border-color: rgba(var(--app-accent-rgb), 0.5); box-shadow: 0 15px 35px rgba(0,0,0,0.3); }
        .service-icon { font-size: 3rem; color: var(--app-accent); margin-bottom: 20px; filter: drop-shadow(0 5px 10px rgba(var(--app-accent-rgb), 0.4)); }
        .service-card h3 { font-family: var(--font-heading); font-size: 1.4rem; margin: 0 0 10px 0; font-weight: 700; }

        .vip-card { border: 1px solid rgba(var(--app-accent-rgb), 0.3); background: linear-gradient(180deg, rgba(var(--app-accent-rgb),0.05), rgba(0,0,0,0.2)); display: flex; flex-direction: column; justify-content: space-between; }
        .vip-badge { position: absolute; top: 0; right: 0; background: var(--app-accent); color: white; padding: 8px 20px; border-bottom-left-radius: 20px; font-weight: 800; font-size: 0.85rem; letter-spacing: 1.5px; box-shadow: -5px 5px 15px rgba(0,0,0,0.2); }

        /* Equipe */
        .featured-barber { background: linear-gradient(135deg, rgba(var(--app-accent-rgb), 0.1) 0%, rgba(255,255,255,0.02) 100%); border-color: rgba(var(--app-accent-rgb), 0.4); text-align: center; max-width: 650px; margin: 0 auto 50px auto; padding: 40px; }
        .team-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 25px; }
        .team-card { text-align: center; }
        .team-card:hover { transform: translateY(-8px); border-color: rgba(255,255,255,0.2); }
        .team-avatar { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; margin-bottom: 20px; border: 4px solid var(--app-accent); padding: 4px; background: var(--app-bg); transition: transform 0.5s; }
        .team-card:hover .team-avatar { transform: rotate(5deg) scale(1.05); }
        .team-card h3 { font-family: var(--font-heading); font-size: 1.3rem; margin: 0 0 5px 0; }
        .rating-stars { color: var(--app-accent); font-size: 1.2rem; letter-spacing: 2px; }

        /* Avaliações */
        .reviews-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; }
        .review-card { display: flex; flex-direction: column; }
        .review-header { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .review-header img { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.2); }
        .review-comment { font-style: italic; color: #cbd5e1; line-height: 1.8; font-size: 1.05rem; flex-grow: 1; }

        /* ===================== CTA FINAL ===================== */
        .cta-final {
            position: relative; text-align: center; padding: 110px 40px; margin-top: 40px;
            background: radial-gradient(circle at 50% 0%, rgba(var(--app-accent-rgb),0.16), transparent 60%);
            border-top: 1px solid var(--glass-border); border-bottom: 1px solid var(--glass-border);
        }
        .cta-final h2 { font-family: var(--font-heading); font-size: clamp(1.8rem, 4vw, 2.8rem); font-weight: 900; margin: 0 0 15px; color: #fff; }
        .cta-final p { color: #94a3b8; font-size: 1.15rem; max-width: 600px; margin: 0 auto 35px; line-height: 1.6; }

        /* Contato */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
        .info-list { list-style: none; padding: 0; line-height: 2.5; color: #cbd5e1; font-size: 1.1rem; }
        .info-list i { color: var(--app-accent); width: 30px; text-align: center; font-size: 1.2rem; }
        .social-links { display: flex; gap: 15px; margin-top: 25px; }
        .social-btn { background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: #fff; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; text-decoration: none; transition: 0.3s; }
        .social-btn:hover { background: var(--app-accent); transform: translateY(-5px); border-color: var(--app-accent); box-shadow: 0 10px 20px rgba(var(--app-accent-rgb), 0.4); }
        #map-container { width: 100%; height: 100%; min-height: 350px; border-radius: 20px; overflow: hidden; border: 1px solid var(--glass-border); }

        /* Rodapé */
        .site-footer { max-width: 1100px; margin: 0 auto; padding: 20px 40px 140px; text-align: center; }
        .legal-links { margin-top: 50px; padding-top: 30px; border-top: 1px solid var(--glass-border); text-align: center; }
        .legal-links a { color: #64748b; text-decoration: none; margin: 0 15px; font-size: 0.95rem; transition: 0.2s; font-weight: 500; }
        .legal-links a:hover { color: #fff; }

        /* Botão flutuante do WhatsApp */
        .whatsapp-float {
            position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; border-radius: 50%;
            background: #25D366; color: #fff; display: flex; align-items: center; justify-content: center;
            font-size: 1.7rem; text-decoration: none; box-shadow: 0 10px 25px rgba(37,211,102,0.5);
            z-index: 45; transition: transform 0.3s ease, box-shadow 0.3s ease;
            animation: whatsappPulse 2.5s infinite;
        }
        .whatsapp-float:hover { transform: scale(1.08); box-shadow: 0 15px 35px rgba(37,211,102,0.65); }
        @keyframes whatsappPulse {
            0% { box-shadow: 0 0 0 0 rgba(37,211,102,0.5); }
            70% { box-shadow: 0 0 0 14px rgba(37,211,102,0); }
            100% { box-shadow: 0 0 0 0 rgba(37,211,102,0); }
        }

        /* Modais Legais */
        .legal-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.9); backdrop-filter: blur(10px); z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; }
        .legal-modal-content { background: var(--app-primary); color: #f8fafc; border-radius: 24px; padding: 40px; width: 100%; max-width: 800px; max-height: 85vh; overflow-y: auto; position: relative; border: 1px solid var(--glass-border); box-shadow: 0 25px 50px rgba(0,0,0,0.5); }
        .legal-text { line-height: 1.8; color: #cbd5e1; font-size: 1.05rem; }
        .close-btn {
            background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: #cbd5e1;
            width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; cursor: pointer; transition: all 0.3s ease; backdrop-filter: blur(5px);
        }
        .close-btn:hover { background: var(--app-accent); color: #fff; transform: rotate(90deg); border-color: var(--app-accent); }

        /* Animações */
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeScaleDown { from { opacity: 0; transform: scale(1.2); } to { opacity: 1; transform: scale(1); } }

        /* Responsividade Mobile */
        @media (max-width: 1024px) {
            .info-grid { grid-template-columns: 1fr; }
            #map-container { height: 350px; }
        }
        @media (max-width: 768px) {
            .top-actions { top: 20px; right: 20px; flex-direction: column; gap: 10px; }
            .action-btn { padding: 10px 18px; font-size: 0.85rem; border-radius: 12px; }
            .logo-main { max-width: 180px; height: auto; margin-top: 60px; }

            .btn-agendar, .btn-agendar-secondary {
                padding: 18px 0;
                font-size: 1.1rem;
                width: 90%;
                max-width: 350px;
                justify-content: center;
                border-radius: 16px;
            }
            .btn-agendar { font-size: 1.15rem; }

            .scroll-hint { display: none; }

            .bottom-nav {
                bottom: 20px;
                width: 95%;
                padding: 15px;
                justify-content: center;
                border-radius: 20px;
            }
            .nav-btn { flex: 0 0 auto; padding: 10px 15px; font-size: 0.85rem; background: rgba(255,255,255,0.05); border-radius: 10px; }

            .whatsapp-float { bottom: 165px; right: 16px; width: 54px; height: 54px; font-size: 1.5rem; }

            .proof-strip { padding: 0 20px; margin-top: -35px; }
            .proof-card { padding: 22px 20px; gap: 20px; }
            .proof-divider { display: none; }

            .content-section { padding: 70px 20px 10px; }
            .cta-final { padding: 70px 20px; }

            .stat-number { font-size: 2.5rem; }
            .glass-card { padding: 25px; border-radius: 20px; }

            .site-footer { padding-bottom: 160px; }
        }
    </style>
