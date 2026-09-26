    <style>
        :root {
            --app-accent: <?= htmlspecialchars($app_accent) ?>;
            --premium-bg: #f8fafc;
            --card-bg: #ffffff;
            --surface-2: #f8fafc;   /* fundos suaves internos (chips, blocos) */
            --surface-3: #f1f5f9;   /* fundos um pouco mais fortes */
            --surface-4: #fafaf9;   /* colunas de ação */
            --text-main: #0f172a;
            --text-muted: #64748b;
            --text-faint: #94a3b8;
            --border-soft: #e2e8f0;
            --border-strong: #cbd5e1;
            --shadow-soft: 0 10px 30px -10px rgba(0,0,0,0.05);
            --shadow-hover: 0 20px 40px -10px rgba(0,0,0,0.08);
            --radius-lg: 20px;
            --radius-xl: 24px;
            --grid-ink: rgba(15,23,42,0.035);
            --app-accent-strong: color-mix(in srgb, var(--app-accent), #111827 18%);
            --app-accent-soft: color-mix(in srgb, var(--app-accent), #ffffff 88%);
            --app-accent-muted: color-mix(in srgb, var(--app-accent), #ffffff 72%);
            --app-accent-border: color-mix(in srgb, var(--app-accent), #ffffff 55%);
        }
        :root[data-theme="dark"] {
            --premium-bg: #0b1220;
            --card-bg: #131c2e;
            --surface-2: #1a2436;
            --surface-3: #202c42;
            --surface-4: #171f30;
            --text-main: #eef2f8;
            --text-muted: #9aa8bd;
            --text-faint: #64748b;
            --border-soft: #263247;
            --border-strong: #33415c;
            --shadow-soft: 0 10px 30px -12px rgba(0,0,0,0.55);
            --shadow-hover: 0 20px 44px -14px rgba(0,0,0,0.65);
            --grid-ink: rgba(148,163,184,0.06);
            --app-accent-soft: color-mix(in srgb, var(--app-accent), #0b1220 70%);
            --app-accent-muted: color-mix(in srgb, var(--app-accent), #0b1220 55%);
            --app-accent-border: color-mix(in srgb, var(--app-accent), #0b1220 40%);
            --app-accent-strong: color-mix(in srgb, var(--app-accent), #ffffff 22%);
        }
        body { background-color: var(--premium-bg); font-family: 'Inter', sans-serif; margin: 0; padding: 0; color: var(--text-main); transition: background-color 0.3s ease, color 0.3s ease; }
        
        .minha-conta-layout { display: flex; max-width: 1200px; margin: 40px auto; gap: 40px; padding: 0 20px; min-height: calc(100vh - 120px); animation: fadeIn 0.6s ease-in-out; }
        .minha-conta-sidebar { width: 320px; flex-shrink: 0; display: flex; flex-direction: column; gap: 25px; }
        
        .profile-card-modern { background: var(--card-bg); border-radius: var(--radius-xl); padding: 40px 25px; text-align: center; border: 1px solid rgba(255,255,255,0.8); box-shadow: var(--shadow-soft); position: relative; }
        .profile-img-wrapper { position: relative; display: inline-block; margin-bottom: 20px; }
        .profile-img { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid #fff; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15); transition: transform 0.3s ease; }
        .profile-card-modern:hover .profile-img { transform: scale(1.05); }
        .vip-badge-profile { position: absolute; bottom: 5px; right: 5px; background: linear-gradient(135deg, var(--app-accent), var(--app-accent-strong)); color: white; border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border: 3px solid white; box-shadow: 0 4px 10px rgba(0,0,0,0.2); font-size: 1rem; }
        
        .profile-info h2 { margin: 0 0 5px; color: var(--text-main); font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px; }
        .profile-info p { margin: 6px 0 0; color: var(--text-muted); font-size: 0.95rem; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .profile-info p i { color: #94a3b8; }
        
        .profile-actions { margin-top: 30px; display: flex; flex-direction: column; gap: 12px; }
        .profile-actions .btn { width: 100%; border-radius: 12px; font-weight: 600; justify-content: center; padding: 14px; font-size: 1rem; transition: all 0.3s; }
        .btn-logout-sidebar { background-color: transparent; color: #ef4444; border: 1px solid #fca5a5; }
        .btn-logout-sidebar:hover { background-color: #fef2f2; border-color: #ef4444; }
        
        .notification-count { position:absolute; top:-5px; right:-5px; background:#ef4444; color:white; font-size:0.65rem; padding:3px 6px; border-radius:50%; font-weight:800; border: 2px solid #fff; box-shadow: 0 2px 5px rgba(239, 68, 68, 0.4); }
        
        .nav-tabs-vertical { display: flex; flex-direction: column; gap: 10px; background: transparent; padding: 0; }
        .nav-tabs-vertical .nav-tab { padding: 16px 24px; border-radius: 14px; background: var(--card-bg); border: 1px solid var(--border-soft); font-size: 1rem; font-weight: 600; color: var(--text-muted); cursor: pointer; text-align: left; display: flex; align-items: center; gap: 15px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 2px 10px rgba(0,0,0,0.01); width: 100%; }
        .nav-tabs-vertical .nav-tab i { width: 24px; text-align: center; font-size: 1.2rem; color: #94a3b8; transition: all 0.3s; }
        .nav-tabs-vertical .nav-tab:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0,0,0,0.03); border-color: #cbd5e1; color: var(--text-main); }
        .nav-tabs-vertical .nav-tab.active { color: #fff; background: var(--app-accent); border-color: var(--app-accent); box-shadow: 0 10px 20px -5px rgba(0,0,0,0.2); }
        .nav-tabs-vertical .nav-tab.active i { color: #fff; }
        
        .minha-conta-main { flex-grow: 1; display: flex; flex-direction: column; gap: 30px; min-width: 0; }
        
        .main-header-greeting { margin-bottom: -10px; }
        .main-header-greeting h1 { font-size: 2rem; color: var(--text-main); font-weight: 800; margin: 0; letter-spacing: -0.5px; }
        .main-header-greeting p { color: var(--text-muted); font-size: 1.05rem; margin: 5px 0 0; font-weight: 500; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; }
        .stat-card { background: var(--card-bg); padding: 15px; border-radius: 16px; text-align: left; border: 1px solid var(--border-soft); box-shadow: var(--shadow-soft); transition: all 0.3s ease; position: relative; overflow: hidden; display: flex; align-items: center; gap: 15px; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-hover); border-color: #cbd5e1; }
        .stat-icon { width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; margin-bottom: 0; flex-shrink: 0; }
        .stat-icon.visits { background: #e0f2fe; color: #0284c7; }
        .stat-icon.invested { background: #dcfce7; color: #16a34a; }
        .stat-icon.saved { background: #f3e8ff; color: #9333ea; }
        .stat-icon.points { background: var(--app-accent-soft); color: var(--app-accent-strong); }
        .stat-info-wrapper { display: flex; flex-direction: column; }
        .stat-label { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-bottom: 2px;}
        .stat-value { font-size: 1.4rem; font-weight: 800; color: var(--text-main); line-height: 1; letter-spacing: -0.5px; }
        
        .vip-subscription-card { background: linear-gradient(135deg, #0f172a, #1e293b); color: white; padding: 25px 30px; border-radius: var(--radius-xl); position: relative; overflow: hidden; box-shadow: 0 15px 35px -10px rgba(15, 23, 42, 0.4); border: 1px solid #334155; }
        .vip-subscription-card::before { content: '\f521'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; top: -20px; right: -20px; font-size: 10rem; opacity: 0.05; transform: rotate(15deg); }
        .vip-card-content { position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: center; }
        .subscription-provider { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; padding: 5px 8px; border-radius: 6px; background: rgba(255,255,255,0.12); color: rgba(255,255,255,0.9); font-size: 0.75rem; font-weight: 700; }
        .subscription-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
        .subscription-manage-btn, .subscription-cancel-btn { min-height: 40px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; border-radius: 8px; font-size: 0.86rem; font-weight: 700; text-decoration: none; cursor: pointer; transition: opacity 0.2s ease, background-color 0.2s ease; }
        .subscription-manage-btn { background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.3); }
        .subscription-cancel-btn { background: rgba(239,68,68,0.16); color: #fecaca; border: 1px solid rgba(254,202,202,0.35); }
        .subscription-manage-btn:hover, .subscription-cancel-btn:hover { opacity: 0.86; }
        .subscription-cancel-note { max-width: 520px; margin-top: 10px; color: rgba(255,255,255,0.72); font-size: 0.78rem; line-height: 1.45; }
        .subscription-ending { display: inline-flex; align-items: flex-start; gap: 8px; margin-top: 14px; padding: 10px 12px; border-radius: 8px; background: rgba(245,158,11,0.17); color: #fde68a; font-size: 0.82rem; line-height: 1.4; }
        .subscription-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-top: 16px; }
        .subscription-fact { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 10px 14px; display: flex; flex-direction: column; gap: 4px; }
        .sf-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 800; color: rgba(255,255,255,0.65); display: inline-flex; align-items: center; gap: 6px; }
        .sf-label i { color: var(--app-accent); }
        .sf-value { font-size: 1rem; font-weight: 800; color: #f8fafc; }
        .subscription-included { margin-top: 16px; }
        .subscription-tag { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.16); color: #f1f5f9; font-size: 0.82rem; font-weight: 700; padding: 5px 12px; border-radius: 999px; }
        .vip-title { font-size: 1.4rem; font-weight: 800; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; color: #f8fafc; }
        .vip-title i { color: var(--app-accent); }
        .vip-detail { opacity: 0.8; font-size: 0.95rem; margin-bottom: 5px; font-weight: 500; }
        .vip-days-left { text-align: right; background: rgba(255,255,255,0.1); padding: 12px 20px; border-radius: 16px; backdrop-filter: blur(5px); border: 1px solid rgba(255,255,255,0.1); }
        .days-number { font-size: 2.2rem; font-weight: 800; line-height: 1; color: var(--app-accent); }
        .days-label { font-size: 0.75rem; text-transform: uppercase; opacity: 0.9; font-weight: 700; margin-top: 5px; letter-spacing: 1px;}
        
        .next-appointment-hero { background: var(--card-bg); border-radius: var(--radius-xl); overflow: hidden; box-shadow: var(--shadow-soft); border: 1px solid var(--border-soft); position: relative; }
        .hero-header { padding: 15px 25px; background: linear-gradient(to right, #f8fafc, #f1f5f9); border-bottom: 1px solid var(--border-soft); display: flex; justify-content: space-between; align-items: center; }
        .countdown-timer { font-size: 1rem; font-weight: 800; font-family: 'Inter', monospace; color: var(--app-accent); background: #fff; padding: 6px 15px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .hero-body { padding: 30px 25px; display: flex; align-items: center; gap: 30px; background: url('uploads/bg.webp') no-repeat center right; background-size: cover; position: relative; }
        .hero-body::before { content:''; position:absolute; top:0; left:0; right:0; bottom:0; background: linear-gradient(to right, rgba(255,255,255,1) 50%, rgba(255,255,255,0.8) 100%); z-index: 1; }
        .hero-content-wrapper { position: relative; z-index: 2; display: flex; width: 100%; align-items: center; gap: 25px; }
        .barber-hero-img { width: 80px; height: 80px; border-radius: 50%; border: 4px solid #fff; object-fit: cover; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .hero-info { flex-grow: 1; }
        .hero-info h3 { margin: 0 0 8px; font-size: 1.6rem; color: var(--text-main); font-weight: 800; letter-spacing: -0.5px; }
        .hero-services { color: var(--text-muted); font-size: 1rem; display: flex; align-items: center; gap: 10px; font-weight: 500; }
        .hero-actions { display: flex; gap: 12px; }
        .hero-actions .btn { padding: 12px 20px; border-radius: 12px; font-weight: 600; font-size: 0.95rem; }
        
        .tabcontent { display: none; animation: fadeEffect 0.4s; background: var(--card-bg); border-radius: var(--radius-xl); padding: 40px; border: 1px solid var(--border-soft); box-shadow: var(--shadow-soft); }
        .tabcontent.active { display: block; }
        @keyframes fadeEffect { from {opacity: 0; transform: translateY(10px);} to {opacity: 1; transform: translateY(0);} }
        .tabcontent-title { margin-top: 0; color: var(--text-main); font-size: 1.5rem; font-weight: 800; border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 30px; display: flex; align-items: center; gap: 12px; letter-spacing: -0.5px; }
        
        .appt-card { background: var(--card-bg); border: 1px solid var(--border-soft); border-radius: var(--radius-lg); padding: 0; margin-bottom: 20px; display: flex; align-items: stretch; justify-content: space-between; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 4px 15px rgba(0,0,0,0.02); overflow: hidden; }
        .appt-card:hover { border-color: #cbd5e1; transform: translateY(-3px); box-shadow: 0 15px 30px -10px rgba(0,0,0,0.08); }
        .appt-date { background: #f8fafc; padding: 25px 30px; border-right: 1px solid var(--border-soft); display: flex; flex-direction: column; align-items: center; justify-content: center; min-width: 120px; }
        .appt-day { font-size: 2.2rem; font-weight: 900; color: var(--text-main); display: block; line-height: 1; margin-bottom: 5px; }
        .appt-month { font-size: 1rem; text-transform: uppercase; color: var(--app-accent); font-weight: 800; }
        .appt-year { font-size: 0.85rem; color: #94a3b8; font-weight: 600; margin-top: 3px; }
        .appt-details { padding: 25px; flex-grow: 1; display: flex; flex-direction: column; justify-content: center; }
        .appt-status { font-size: 0.75rem; text-transform: uppercase; font-weight: 800; padding: 6px 12px; border-radius: 8px; letter-spacing: 0.5px; }
        .status-concluido { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-cancelado, .status-cancelado_pelo_cliente { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .status-pendente { background: var(--app-accent-soft); color: var(--app-accent-strong); border: 1px solid var(--app-accent-border); }
        .status-aprovado { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }
        .appt-actions-container { padding: 25px; display: flex; flex-direction: column; justify-content: center; gap: 10px; border-left: 1px solid #f1f5f9; background: #fafaf9; align-items: stretch; }
        
        .timeline-modern { position: relative; padding: 10px 0; list-style: none; margin: 0; }
        .timeline-modern:before { content: ''; position: absolute; top: 0; bottom: 0; left: 24px; width: 3px; background: #f1f5f9; border-radius: 3px; }
        .timeline-item { position: relative; margin-bottom: 30px; display: flex; align-items: flex-start; }
        .timeline-item:last-child { margin-bottom: 0; }
        .timeline-icon { width: 50px; height: 50px; border-radius: 14px; background: #fff; border: 2px solid var(--border-soft); display: flex; align-items: center; justify-content: center; z-index: 2; color: #64748b; flex-shrink: 0; margin-right: 25px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); font-size: 1.2rem; }
        .timeline-icon.positive { border-color: #10b981; color: #10b981; background-color: #ecfdf5; }
        .timeline-icon.negative { border-color: #ef4444; color: #ef4444; background-color: #fef2f2; }
        .timeline-content { background: #fff; border: 1px solid var(--border-soft); border-radius: 16px; padding: 20px 25px; width: 100%; position: relative; box-shadow: 0 2px 10px rgba(0,0,0,0.01); transition: box-shadow 0.3s; }
        .timeline-content:hover { box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); border-color: var(--app-accent); }
        .timeline-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .timeline-date { font-size: 0.8rem; color: #94a3b8; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .timeline-desc { font-weight: 600; color: var(--text-main); margin: 0; font-size: 1.05rem; }
        .timeline-points { font-weight: 900; font-size: 1.2rem; background: #f8fafc; padding: 4px 12px; border-radius: 8px; }
        .timeline-points.positive { color: #10b981; background: #ecfdf5; border: 1px solid #d1fae5; }
        .timeline-points.negative { color: #ef4444; background: #fef2f2; border: 1px solid #fee2e2; }
        
        .modal-overlay { background-color: rgba(15, 23, 42, 0.7); backdrop-filter: blur(8px); z-index: 99999 !important; display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-content { border-radius: var(--radius-xl); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.3); border: 1px solid rgba(255,255,255,0.2); max-height: 90vh; overflow-y: auto; position: relative; width: 100%;}
        
        .form-group label { font-weight: 700; color: var(--text-main); margin-bottom: 8px; display: flex; align-items: center; gap: 5px; }
        .form-group input, .form-group textarea { padding: 14px 16px; border-radius: 12px; border: 1px solid #cbd5e1; width: 100%; background: #f8fafc; transition: all 0.3s; font-size: 1rem; color: var(--text-main); font-family: 'Inter', sans-serif; box-sizing: border-box;}
        .form-group input:focus, .form-group textarea:focus { background: #fff; border-color: var(--app-accent); box-shadow: 0 0 0 4px rgba(0, 0, 0, 0.05); outline: none; }
        
        .btn-primary { background-color: var(--app-accent); color: white; border: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; cursor: pointer; }
        .btn-primary:hover { filter: brightness(1.1); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); }

        /* Premium refresh */
        body.admin-page {
            background:
                linear-gradient(180deg, #f8fafc 0%, #eef2f7 44%, #f8fafc 100%);
        }
        body.admin-page::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(15,23,42,0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(15,23,42,0.035) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,0.42), transparent 72%);
            z-index: -1;
        }
        .minha-conta-layout {
            max-width: 1320px !important;
            display: grid !important;
            grid-template-columns: 330px minmax(0, 1fr);
            gap: 28px !important;
            margin-top: 28px !important;
        }
        .minha-conta-sidebar {
            position: sticky;
            top: 18px;
            align-self: start;
        }
        .profile-card-modern {
            background: rgba(255,255,255,0.92) !important;
            border: 1px solid rgba(226,232,240,0.95) !important;
            box-shadow: 0 24px 60px -42px rgba(15,23,42,0.75) !important;
            backdrop-filter: blur(12px);
            overflow: hidden;
        }
        .profile-card-modern::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 92px;
            background:
                linear-gradient(135deg, rgba(15,23,42,0.88), rgba(15,23,42,0.34)),
                url('uploads/minha-conta-barbearia-illustration.webp') center 48% / cover no-repeat;
            z-index: 0;
        }
        .profile-card-modern > * {
            position: relative;
            z-index: 1;
        }
        .profile-img {
            width: 126px !important;
            height: 126px !important;
            border: 5px solid #fff !important;
        }
        .profile-info h2 {
            letter-spacing: 0 !important;
        }
        .profile-info p {
            overflow-wrap: anywhere;
        }
        .profile-actions .btn,
        .hero-actions .btn,
        .appt-actions-container .btn,
        #btn-carregar-mais-historico,
        #btn-carregar-mais-pontos {
            min-height: 44px;
            border-radius: 12px !important;
        }
        .nav-tabs-vertical {
            background: rgba(255,255,255,0.76) !important;
            border: 1px solid rgba(226,232,240,0.92);
            border-radius: 18px;
            padding: 8px !important;
            box-shadow: 0 18px 38px -34px rgba(15,23,42,0.8);
            backdrop-filter: blur(10px);
        }
        .nav-tabs-vertical .nav-tab {
            position: relative;
            border: 1px solid transparent !important;
            box-shadow: none !important;
            background: transparent !important;
            border-radius: 12px !important;
            min-height: 52px;
        }
        .nav-tabs-vertical .nav-tab::after {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: transparent;
            margin-left: auto;
        }
        .nav-tabs-vertical .nav-tab:hover {
            background: #f8fafc !important;
        }
        .nav-tabs-vertical .nav-tab.active {
            color: var(--text-main) !important;
            background: #fff !important;
            border-color: var(--app-accent-border) !important;
            box-shadow: 0 14px 30px -24px rgba(15,23,42,0.85) !important;
        }
        .nav-tabs-vertical .nav-tab.active i {
            color: var(--app-accent) !important;
        }
        .nav-tabs-vertical .nav-tab.active::after {
            background: var(--app-accent);
            box-shadow: 0 0 0 5px var(--app-accent-soft);
        }
        .account-command-center {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 22px;
            align-items: end;
            padding: 30px;
            border: 1px solid rgba(226,232,240,0.95);
            border-radius: 24px;
            background:
                linear-gradient(90deg, rgba(255,255,255,0.98) 0%, rgba(255,255,255,0.94) 46%, rgba(255,255,255,0.62) 100%),
                url('uploads/minha-conta-barbearia-illustration.webp') center right / cover no-repeat;
            box-shadow: 0 24px 60px -44px rgba(15,23,42,0.85);
            overflow: hidden;
            position: relative;
            min-height: 230px;
        }
        .account-command-center::after {
            content: '';
            position: absolute;
            inset: auto 24px 20px auto;
            width: 76px;
            height: 4px;
            border-radius: 999px;
            background: var(--app-accent);
            opacity: 0.9;
        }
        .account-command-center > * {
            position: relative;
            z-index: 1;
        }
        .account-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            color: var(--app-accent);
            font-size: 0.78rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .account-command-center h1 {
            margin: 0;
            color: var(--text-main);
            font-size: clamp(1.8rem, 2.8vw, 2.7rem);
            line-height: 1.05;
            letter-spacing: 0;
            text-align: left;
        }
        .account-command-center p {
            margin: 12px 0 0;
            color: var(--text-muted);
            font-size: 1rem;
            line-height: 1.55;
            max-width: 680px;
        }
        .account-quick-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }
        .quick-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 0 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: var(--text-main);
            text-decoration: none;
            font-weight: 800;
            font-size: 0.92rem;
            transition: all 0.22s ease;
            cursor: pointer;
            position: relative;
        }
        .quick-action .notification-count {
            top: -8px;
            right: -8px;
        }
        .quick-action:hover {
            transform: translateY(-2px);
            border-color: #cbd5e1;
            box-shadow: 0 12px 25px -20px rgba(15,23,42,0.9);
        }
        .quick-action.primary {
            color: #fff;
            border-color: transparent;
            background: linear-gradient(135deg, var(--app-accent), var(--app-accent-strong));
        }
        #alerta-container {
            border-radius: 16px !important;
            box-shadow: 0 16px 34px -28px rgba(15,23,42,0.8) !important;
        }
        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)) !important;
        }
        .stat-card {
            min-height: 112px;
            padding: 20px !important;
            border-radius: 20px !important;
            background: rgba(255,255,255,0.94) !important;
            box-shadow: 0 18px 38px -34px rgba(15,23,42,0.9) !important;
        }
        .stat-card::after {
            content: '';
            position: absolute;
            left: 20px;
            right: 20px;
            bottom: 0;
            height: 3px;
            border-radius: 999px 999px 0 0;
            background: linear-gradient(90deg, var(--app-accent), rgba(15,23,42,0.12));
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        .stat-card:hover::after { opacity: 1; }
        .stat-icon {
            width: 52px !important;
            height: 52px !important;
            border-radius: 16px !important;
        }
        .stat-value {
            font-size: 1.55rem !important;
            letter-spacing: 0 !important;
        }
        .vip-subscription-card,
        .next-appointment-hero,
        .tabcontent {
            border-radius: 24px !important;
            border: 1px solid rgba(226,232,240,0.96) !important;
            box-shadow: 0 24px 60px -44px rgba(15,23,42,0.85) !important;
        }
        .next-appointment-hero {
            background: #fff !important;
        }
        .hero-header {
            background: linear-gradient(90deg, #fff, #f8fafc) !important;
        }
        .hero-body {
            background-image: none !important;
        }
        .hero-body::before {
            background: linear-gradient(135deg, rgba(255,255,255,1), rgba(248,250,252,0.92)) !important;
        }
        .barber-hero-img {
            width: 86px !important;
            height: 86px !important;
        }
        .tabcontent {
            padding: 34px !important;
            background: rgba(255,255,255,0.96) !important;
        }
        .tabcontent-title {
            border-bottom: 1px solid #eef2f7 !important;
            text-align: left !important;
            letter-spacing: 0 !important;
        }
        .appt-card,
        .avaliacao-card,
        .timeline-content {
            border-color: #e5e7eb !important;
            box-shadow: 0 16px 32px -30px rgba(15,23,42,0.75) !important;
        }
        .appt-card {
            border-radius: 18px !important;
        }
        .appt-date {
            background: linear-gradient(180deg, #f8fafc, #fff) !important;
        }
        .appt-actions-container {
            background: #fbfcfe !important;
        }
        .appt-status {
            border-radius: 999px !important;
        }
        .empty-state-premium {
            background: #f8fafc !important;
            border: 1px dashed #cbd5e1 !important;
            border-radius: 18px !important;
        }
        .form-group input,
        .form-group textarea {
            min-height: 48px;
            border-radius: 12px !important;
            background: #fff !important;
        }
        .form-group input:disabled {
            background: #f1f5f9 !important;
        }
        .modal-overlay {
            padding: 20px;
        }
        .modal-overlay.active .modal-content,
        .modal-overlay[style*="flex"] .modal-content {
            animation: modalIn 0.22s ease both;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(12px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ============ TEMA ESCURO (overrides) ============ */
        :root[data-theme="dark"] body.admin-page {
            background: linear-gradient(180deg, #0b1220 0%, #0e1626 46%, #0b1220 100%);
            color: var(--text-main);
        }
        :root[data-theme="dark"] .profile-card-modern,
        :root[data-theme="dark"] .nav-tabs-vertical,
        :root[data-theme="dark"] .stat-card,
        :root[data-theme="dark"] .next-appointment-hero,
        :root[data-theme="dark"] .tabcontent,
        :root[data-theme="dark"] .vip-subscription-card {
            background: var(--card-bg) !important;
            border-color: var(--border-soft) !important;
        }
        :root[data-theme="dark"] .nav-tabs-vertical .nav-tab:hover { background: var(--surface-2) !important; }
        :root[data-theme="dark"] .nav-tabs-vertical .nav-tab.active {
            background: var(--surface-2) !important;
            color: var(--text-main) !important;
            border-color: var(--app-accent) !important;
        }
        :root[data-theme="dark"] .account-command-center {
            background: linear-gradient(90deg, rgba(19,28,46,0.98) 0%, rgba(19,28,46,0.9) 46%, rgba(19,28,46,0.6) 100%),
                        url('uploads/minha-conta-barbearia-illustration.webp') center right / cover no-repeat !important;
            border-color: var(--border-soft) !important;
        }
        :root[data-theme="dark"] .account-command-center h1,
        :root[data-theme="dark"] .main-header-greeting h1 { color: var(--text-main) !important; }
        :root[data-theme="dark"] .hero-header { background: linear-gradient(90deg, var(--surface-2), var(--card-bg)) !important; border-color: var(--border-soft); }
        :root[data-theme="dark"] .countdown-timer { background: var(--surface-3); }
        :root[data-theme="dark"] .hero-body::before { background: linear-gradient(135deg, rgba(19,28,46,1), rgba(19,28,46,0.9)) !important; }
        :root[data-theme="dark"] .appt-card,
        :root[data-theme="dark"] .avaliacao-card,
        :root[data-theme="dark"] .timeline-content { background: var(--card-bg) !important; border-color: var(--border-soft) !important; }
        :root[data-theme="dark"] .appt-date { background: linear-gradient(180deg, var(--surface-2), var(--card-bg)) !important; border-color: var(--border-soft); }
        :root[data-theme="dark"] .appt-actions-container { background: var(--surface-4) !important; border-color: var(--border-soft); }
        :root[data-theme="dark"] .timeline-icon { background: var(--surface-2); border-color: var(--border-soft); }
        :root[data-theme="dark"] .timeline-modern:before { background: var(--border-soft); }
        :root[data-theme="dark"] .empty-state-premium,
        :root[data-theme="dark"] .cliente-softblock { background: var(--surface-2) !important; border-color: var(--border-strong) !important; }
        :root[data-theme="dark"] .form-group input,
        :root[data-theme="dark"] .form-group textarea { background: var(--surface-2) !important; border-color: var(--border-strong); color: var(--text-main); }
        :root[data-theme="dark"] .form-group input:disabled { background: var(--surface-3) !important; }
        :root[data-theme="dark"] .modal-content { background: var(--card-bg) !important; }
        :root[data-theme="dark"] .modal-close { background: var(--surface-3); color: var(--text-muted); }
        :root[data-theme="dark"] #alerta-container { box-shadow: var(--shadow-soft) !important; }
        :root[data-theme="dark"] .quick-action { background: var(--surface-2); border-color: var(--border-soft); color: var(--text-main); }
        :root[data-theme="dark"] .cliente-chip { background: var(--surface-3) !important; color: var(--text-muted) !important; }

        /* Botão de alternância de tema */
        .theme-toggle {
            width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            min-height: 44px; padding: 12px; border-radius: 12px; cursor: pointer;
            border: 1px solid var(--border-soft); background: var(--card-bg); color: var(--text-muted);
            font-weight: 700; font-size: 0.95rem; font-family: 'Inter', sans-serif; transition: all 0.25s ease;
        }
        .theme-toggle:hover { color: var(--text-main); border-color: var(--border-strong); transform: translateY(-2px); }
        .theme-toggle .fa-sun { display: none; }
        :root[data-theme="dark"] .theme-toggle .fa-sun { display: inline-block; }
        :root[data-theme="dark"] .theme-toggle .fa-moon { display: none; }

        @media (max-width: 992px) {
            .minha-conta-layout { display: flex !important; flex-direction: column; margin: 15px auto; padding: 0 15px; gap: 20px; }
            .minha-conta-sidebar { width: 100%; gap: 15px; position: static; }
            .profile-card-modern { display: flex; align-items: center; text-align: left; padding: 20px; gap: 15px; flex-wrap: wrap; }
            .profile-card-modern::before { height: 100%; width: 92px; right: auto; background-position: center; }
            .profile-img-wrapper { margin-bottom: 0; }
            .profile-img { width: 70px !important; height: 70px !important; border-width: 3px !important; }
            .profile-info { flex-grow: 1; min-width: 150px; }
            .profile-info h2 { font-size: 1.3rem; }
            .profile-actions { margin-top: 10px; flex-direction: row; width: 100%; gap: 10px; flex-wrap: wrap; }
            .profile-actions .btn { flex: 1 1 45%; padding: 12px 10px; font-size: 0.9rem; justify-content: center; text-align: center; white-space: normal; line-height: 1.2;}
            
            .nav-tabs-vertical { flex-direction: row; overflow-x: auto; padding: 5px; background: #fff; border-radius: 16px; border: 1px solid var(--border-soft); box-shadow: var(--shadow-soft); scrollbar-width: none; -ms-overflow-style: none; -webkit-overflow-scrolling: touch; }
            .nav-tabs-vertical::-webkit-scrollbar { display: none; }
            .nav-tabs-vertical .nav-tab { width: auto; padding: 12px 20px; white-space: nowrap; border: none; box-shadow: none; background: transparent; }
            
            .minha-conta-main { gap: 20px; }
            .account-command-center { grid-template-columns: 1fr; padding: 22px; min-height: 260px; background: linear-gradient(180deg, rgba(255,255,255,0.97) 0%, rgba(255,255,255,0.9) 48%, rgba(255,255,255,0.72) 100%), url('uploads/minha-conta-barbearia-illustration.webp') center bottom / cover no-repeat; }
            .account-command-center h1 { font-size: 1.7rem; }
            .account-command-center p { font-size: 0.95rem; }
            .account-quick-actions { justify-content: flex-start; width: 100%; }
            .quick-action { flex: 1 1 auto; }

            .stats-grid { display: flex; flex-wrap: nowrap; overflow-x: auto; scroll-snap-type: x mandatory; padding-bottom: 10px; margin-left: -5px; margin-right: -15px; padding-left: 5px; padding-right: 15px; gap: 15px; -webkit-overflow-scrolling: touch; scrollbar-width: none; -ms-overflow-style: none; }
            .stats-grid::-webkit-scrollbar { display: none; }
            .stat-card { min-width: 220px; flex-shrink: 0; scroll-snap-align: start; }
            
            .vip-subscription-card { padding: 20px; }
            .vip-card-content { flex-direction: column; text-align: center; gap: 15px; }
            .vip-title { justify-content: center; font-size: 1.4rem; }
            .vip-days-left { width: 100%; justify-content: center; padding: 12px; }
            
            .hero-header { padding: 15px; }
            .hero-body { padding: 20px 15px; }
            .hero-content-wrapper { flex-direction: column; text-align: center; gap: 15px; }
            .barber-hero-img { width: 70px; height: 70px; }
            .hero-actions { width: 100%; justify-content: center; }
            .hero-actions .btn { flex: 1; padding: 10px; font-size: 0.9rem;}
            
            .appt-card { flex-direction: column; }
            .appt-date { border-right: none; border-bottom: 1px solid var(--border-soft); flex-direction: row; gap: 15px; align-items: baseline; padding: 20px; justify-content: flex-start; }
            .appt-actions-container { border-left: none; border-top: 1px solid var(--border-soft); flex-direction: row; padding: 20px; flex-wrap: wrap;}
            .appt-actions-container .btn { flex: 1; justify-content: center; min-width: 45%; }
            
            .tabcontent { padding: 20px; }
            .tabcontent-title { font-size: 1.3rem; margin-bottom: 20px; }
        }
    </style>
