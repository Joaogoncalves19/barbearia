<?php
// admin_tabs/landingpage.php
// Contém o HTML para configurar a Landing Page (index.php)
// Variáveis: $landingConfig (carregada via admin_data.php - SQLite)
$themeConfig = function_exists('_lerConfigSQLite') ? _lerConfigSQLite('theme_config', []) : [];

$csrf_token = generate_csrf_token(); // GERANDO O TOKEN DE SEGURANÇA PARA A ABA
?>

<style>
    /* ==========================================================================
       ESTILOS PREMIUM PARA EDITOR DA LANDING PAGE
       ========================================================================== */
    .landing-layout {
        display: flex;
        flex-direction: column;
        gap: 20px;
        animation: fadeIn 0.4s ease-out;
    }

    /* Sub-abas, cabeçalho de seção e campos vivem em css/admin_components.css */
    .landing-sub-content { display: none; animation: fadeIn 0.4s ease-out; }
    .landing-sub-content.active { display: block; }

    /* --- Cards de Configuração --- */
    .config-card-title {
        font-size: 1.15rem; font-weight: 800; color: #1e293b; margin-top: 0; margin-bottom: 10px;
        display: flex; align-items: center; gap: 10px;
    }
    .config-card-title i { color: var(--secondary-color, #007bff); opacity: 0.8; }
    .config-card-desc { font-size: 0.9rem; color: #64748b; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #f8fafc; }

    /* --- Inputs e Forms --- */
    

    .media-preview-container {
        background: #f1f5f9; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 20px;
        text-align: center; margin-top: 10px; transition: 0.2s;
    }
    .media-preview-container:hover { border-color: var(--secondary-color); }
    .file-input-wrapper { margin-top: 10px; }

    .color-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
    .color-picker-group { display: flex; align-items: center; gap: 12px; background: #f8fafc; padding: 10px; border-radius: 10px; border: 1px solid #e2e8f0; }
    .color-picker-group input[type="color"] { width: 45px; height: 45px; border: none; border-radius: 8px; cursor: pointer; padding: 0; background: none; }

    .btn-save-landing {
        background: var(--secondary-color, #007bff); color: white; border: none; padding: 16px 30px;
        border-radius: 12px; font-weight: 700; font-size: 1.1rem; cursor: pointer; transition: 0.2s;
        display: inline-flex; align-items: center; justify-content: center; gap: 10px; width: 100%;
        box-shadow: 0 4px 15px rgba(0,123,255,0.25); margin-top: 10px;
    }
    .btn-save-landing:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,123,255,0.35); }

    @media (max-width: 768px) {
        .modern-form-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="landing-layout" id="landing-wrapper">

    <div class="section-header-bar">
        <div class="section-header-info">
            <h3><i class="fa fa-paint-brush"></i> Design do Site Principal</h3>
            <p>Personalize textos, imagens e a cor de destaque das sessões do seu novo site interativo.</p>
        </div>
        <a href="index.php" target="_blank" class="sub-tab-btn" style="background: #1e293b; color: white; border: none;">
            <i class="fa fa-external-link-alt"></i> Visualizar Site
        </a>
    </div>

    <div class="modern-sub-tabs">
        <button class="sub-tab-btn active" data-sub="landing-hero"><i class="fa fa-home"></i> Tela Inicial</button>
        <button class="sub-tab-btn" data-sub="landing-aparencia"><i class="fa fa-palette"></i> Cor de Destaque</button>
        <button class="sub-tab-btn" data-sub="landing-sobre"><i class="fa fa-building"></i> Painel: Sobre Nós</button>
        <button class="sub-tab-btn" data-sub="landing-servicos"><i class="fa fa-list"></i> Painel: Serviços</button>
        <button class="sub-tab-btn" data-sub="landing-equipe"><i class="fa fa-users"></i> Painel: Equipe</button>
        <button class="sub-tab-btn" data-sub="landing-avaliacoes"><i class="fa fa-star"></i> Painel: Avaliações</button>
        <button class="sub-tab-btn" data-sub="landing-contato"><i class="fa fa-map-marker-alt"></i> Painel: Contato & Legal</button>
    </div>

    <form method="POST" action="admin_actions.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="salvar_landing_page">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

        <div id="landing-hero" class="landing-sub-content active">
            <div class="config-card">
                <h4 class="config-card-title"><i class="fa fa-tv"></i> Vídeo de Fundo e Textos Centrais</h4>
                <p class="config-card-desc">Estes elementos aparecem logo que o cliente entra no site, antes de clicar em qualquer menu.</p>
                
                <div class="modern-form-grid">
                    <div class="form-group">
                        <label>Vídeo em Loop (Opcional - Formato MP4/WEBM)</label>
                        <div class="media-preview-container">
                            <i class="fa fa-film" style="font-size: 2rem; color: #94a3b8; margin-bottom: 10px; display: block;"></i>
                            <?php if(!empty($landingConfig['hero_video_path'])): ?>
                                <span style="font-size: 0.85rem; color: #10b981; font-weight: 600;">Vídeo atual: <?= basename($landingConfig['hero_video_path']) ?></span>
                            <?php else: ?>
                                <span style="font-size: 0.85rem; color: #64748b;">Nenhum vídeo enviado (usará fundo elegante escuro)</span>
                            <?php endif; ?>
                            <div class="file-input-wrapper">
                                <input type="file" name="hero_video" accept="video/mp4,video/webm" class="modern-input" style="padding: 8px;">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Título Principal (Em Destaque)</label>
                        <input type="text" name="hero_title" class="modern-input" value="<?= htmlspecialchars($landingConfig['hero_title'] ?? '') ?>" placeholder="Sua Barbearia" style="margin-bottom: 15px;">
                        
                        <label>Subtítulo Curto</label>
                        <input type="text" name="hero_subtitle" class="modern-input" value="<?= htmlspecialchars($landingConfig['hero_subtitle'] ?? '') ?>" placeholder="Estilo e Tradição">
                        
                        <label style="margin-top: 15px;">Texto do Botão Central (Pulsante)</label>
                        <input type="text" name="hero_cta_button" class="modern-input" value="<?= htmlspecialchars($landingConfig['hero_cta_button'] ?? '') ?>">

                        <label style="margin-top: 15px;">Texto do Botão Secundário (Conheça nossos Barbeiros)</label>
                        <input type="text" name="hero_secondary_button" class="modern-input" value="<?= htmlspecialchars($landingConfig['hero_secondary_button'] ?? '') ?>" placeholder="Conheça nossos Barbeiros">
                    </div>
                </div>
            </div>
        </div>

        <div id="landing-aparencia" class="landing-sub-content">
            <div class="config-card">
                <h4 class="config-card-title"><i class="fa fa-palette"></i> Cor de Destaque (Site do Cliente)</h4>
                <p class="config-card-desc">Defina a cor principal que será usada para destacar botões, ícones e contornos da Landing Page e do painel do cliente.</p>
                
                <div class="color-grid">
                    <div class="color-picker-group">
                        <input type="color" name="secondary_color" id="secondary_color" value="<?= htmlspecialchars($themeConfig['secondary_color'] ?? '#f59e0b') ?>">
                        <label>Cor de Destaque (Accent)</label>
                    </div>
                </div>
            </div>
        </div>

        <div id="landing-sobre" class="landing-sub-content">
            <div class="config-card">
                <h4 class="config-card-title"><i class="fa fa-building"></i> Conteúdo do Painel: Sobre Nós</h4>
                <p class="config-card-desc">Informações exibidas quando o cliente abre a tela "Sobre Nós" no menu inferior.</p>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Título do Painel</label>
                    <input type="text" name="about_title" class="modern-input" value="<?= htmlspecialchars($landingConfig['about_title'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Texto Descritivo (Conte sua história)</label>
                    <textarea name="about_text" class="modern-textarea" rows="6"><?= htmlspecialchars($landingConfig['about_text'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="config-card">
                <h4 class="config-card-title"><i class="fa fa-chart-bar"></i> Números em Destaque (Contadores Animados)</h4>
                <div class="modern-form-grid">
                    <div class="form-group">
                        <label>Stat 1 (Número | Texto)</label>
                        <div style="display:flex; gap:10px;">
                            <input type="text" name="stat1_number" class="modern-input" value="<?= htmlspecialchars($landingConfig['stat1_number'] ?? '') ?>" style="width: 80px;">
                            <input type="text" name="stat1_label" class="modern-input" value="<?= htmlspecialchars($landingConfig['stat1_label'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Stat 2 (Número | Texto)</label>
                        <div style="display:flex; gap:10px;">
                            <input type="text" name="stat2_number" class="modern-input" value="<?= htmlspecialchars($landingConfig['stat2_number'] ?? '') ?>" style="width: 80px;">
                            <input type="text" name="stat2_label" class="modern-input" value="<?= htmlspecialchars($landingConfig['stat2_label'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Stat 3 (Número | Texto)</label>
                        <div style="display:flex; gap:10px;">
                            <input type="text" name="stat3_number" class="modern-input" value="<?= htmlspecialchars($landingConfig['stat3_number'] ?? '') ?>" style="width: 80px;">
                            <input type="text" name="stat3_label" class="modern-input" value="<?= htmlspecialchars($landingConfig['stat3_label'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="landing-servicos" class="landing-sub-content">
            <div class="config-card">
                <h4 class="config-card-title"><i class="fa fa-list"></i> Conteúdo do Painel: Serviços e Planos</h4>
                <p class="config-card-desc">Os serviços e os planos de assinatura são puxados automaticamente do seu banco de dados. Aqui você altera apenas o título.</p>
                
                <div class="form-group">
                    <label>Título do Painel</label>
                    <input type="text" name="services_title" class="modern-input" value="<?= htmlspecialchars($landingConfig['services_title'] ?? 'Nossos Serviços') ?>">
                </div>
            </div>
        </div>

        <div id="landing-equipe" class="landing-sub-content">
            <div class="config-card">
                <h4 class="config-card-title"><i class="fa fa-users"></i> Conteúdo do Painel: Equipe</h4>
                <p class="config-card-desc">A lista de profissionais e suas médias de avaliação são geradas automaticamente.</p>
                
                <div class="form-group">
                    <label>Título do Painel</label>
                    <input type="text" name="team_title" class="modern-input" value="<?= htmlspecialchars($landingConfig['team_title'] ?? '') ?>">
                </div>
            </div>
            
            <div class="config-card">
                <h4 class="config-card-title"><i class="fa fa-trophy"></i> Bloco "Barbeiro em Destaque"</h4>
                <p class="config-card-desc">O sistema seleciona sozinho o profissional com a maior nota para exibir em destaque.</p>
                
                <div class="modern-form-grid">
                    <div class="form-group">
                        <label>Título Especial (Ex: Barbeiro do Mês)</label>
                        <input type="text" name="featured_title" class="modern-input" value="<?= htmlspecialchars($landingConfig['featured_title'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Texto de Apoio</label>
                        <input type="text" name="featured_subtitle" class="modern-input" value="<?= htmlspecialchars($landingConfig['featured_subtitle'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div id="landing-avaliacoes" class="landing-sub-content">
            <div class="config-card">
                <h4 class="config-card-title"><i class="fa fa-star"></i> Conteúdo do Painel: Avaliações</h4>
                <p class="config-card-desc">Para escolher QUAIS avaliações devem aparecer aqui, acesse a aba principal <strong>Avaliações</strong> do sistema e clique em "Destacar no Site".</p>
                
                <div class="form-group">
                    <label>Título do Painel</label>
                    <input type="text" name="testimonials_title" class="modern-input" value="<?= htmlspecialchars($landingConfig['testimonials_title'] ?? '') ?>">
                </div>
            </div>

            <div class="config-card">
                <h4 class="config-card-title"><i class="fa fa-bullhorn"></i> Chamada Final (CTA)</h4>
                <p class="config-card-desc">Bloco de destaque exibido perto do fim do site, incentivando o cliente a agendar.</p>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Título da Chamada</label>
                    <input type="text" name="cta_title" class="modern-input" value="<?= htmlspecialchars($landingConfig['cta_title'] ?? '') ?>" placeholder="Pronto para o Próximo Nível?">
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Texto de Apoio</label>
                    <textarea name="cta_text" class="modern-textarea" rows="3" placeholder="Sua cadeira está esperando por você..."><?= htmlspecialchars($landingConfig['cta_text'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Texto do Botão</label>
                    <input type="text" name="cta_button" class="modern-input" value="<?= htmlspecialchars($landingConfig['cta_button'] ?? '') ?>" placeholder="Agendar Meu Horário">
                </div>
            </div>
        </div>

        <div id="landing-contato" class="landing-sub-content">
            <div class="config-card">
                <h4 class="config-card-title"><i class="fa fa-map-marker-alt"></i> Conteúdo do Painel: Contato e Endereço</h4>
                <p class="config-card-desc">O Endereço, o WhatsApp e as Redes Sociais são puxados automaticamente da aba de "Informações Gerais". O Mapa do Google é gerado sozinho.</p>
                
                <div class="form-group">
                    <label>Título do Painel</label>
                    <input type="text" name="contact_title" class="modern-input" value="<?= htmlspecialchars($landingConfig['contact_title'] ?? 'Informações e Localização') ?>">
                </div>
            </div>
            
            <div class="config-card">
                <h4 class="config-card-title"><i class="fa fa-balance-scale"></i> Páginas Jurídicas (Rodapé do Site)</h4>
                <p class="config-card-desc">Estes textos abrem em modais elegantes quando o cliente clica nos links do rodapé.</p>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Termos de Uso</label>
                    <textarea name="terms_of_use" class="modern-textarea" rows="6"><?= htmlspecialchars($landingConfig['terms_of_use'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Política de Privacidade</label>
                    <textarea name="privacy_policy" class="modern-textarea" rows="6"><?= htmlspecialchars($landingConfig['privacy_policy'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div style="position: sticky; bottom: 0; background: rgba(244, 247, 250, 0.9); backdrop-filter: blur(10px); padding: 20px 0; border-top: 1px solid #e2e8f0; z-index: 5;">
            <button type="submit" class="btn-save-landing">
                <i class="fa fa-save"></i> Salvar Todas as Alterações
            </button>
        </div>

    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const landingNavContainer = document.querySelector('#landing-wrapper .modern-sub-tabs');
    if (landingNavContainer) {
        const btns = landingNavContainer.querySelectorAll('.sub-tab-btn');
        const contents = document.querySelectorAll('.landing-sub-content');

        btns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                
                btns.forEach(b => b.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));
                
                this.classList.add('active');
                const targetId = this.getAttribute('data-sub');
                const targetContent = document.getElementById(targetId);
                
                if (targetContent) {
                    targetContent.classList.add('active');
                    if(window.innerWidth < 768) {
                        targetContent.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            });
        });
    }
});
</script>