<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string) app_config('app.name')) ?></title>
    <!-- Google Fonts: Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="app-frame">
        <aside class="sidebar">
            <div class="brand-card">
                <p class="eyebrow">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/>
                    </svg>
                    Chatbot local con Ollama
                </p>
                <h1><?= htmlspecialchars((string) app_config('app.name')) ?></h1>
                <p class="lede">
                    Conversa con un modelo local, cambia a modo temático usando archivos propios
                    y activa consultas seguras sobre tu base de datos.
                </p>
            </div>

            <div class="sidebar-stack">
                <div class="panel">
                    <label class="label">Modo</label>
                    <div class="custom-select-wrapper">
                        <select id="mode" class="sr-only">
                            <option value="general" selected>Conversación general</option>
                            <option value="topic">Tema específico</option>
                            <option value="database">Base de datos</option>
                        </select>
                        <div class="custom-select" id="custom-mode">
                            <div class="custom-select-trigger">
                                <span class="custom-select-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                </span>
                                <span class="custom-select-text">Conversación general</span>
                                <svg class="custom-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                            </div>
                            <div class="custom-select-options">
                                <div class="custom-option selected" data-value="general">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                    Conversación general
                                </div>
                                <div class="custom-option" data-value="topic">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                    Tema específico
                                </div>
                                <div class="custom-option" data-value="database">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>
                                    Base de datos
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <p class="label">Estado</p>
                    <div class="status-list">
                        <div class="status-item">
                            <span class="status-name">Modelo</span>
                            <strong id="model-badge"><?= htmlspecialchars((string) app_config('app.model')) ?></strong>
                        </div>
                        <div class="status-item">
                            <span class="status-name">Base de datos</span>
                            <strong id="db-badge" data-enabled="<?= app_config('database.enabled') ? '1' : '0' ?>">
                                <?= app_config('database.enabled') ? 'Configurada' : 'Pendiente' ?>
                            </strong>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <p class="label">Fuentes</p>
                    <ul class="source-list" id="knowledge-list">
                        <li>Cargando documentos...</li>
                    </ul>
                </div>

                <div class="panel panel-note">
                    <p class="label">Cómo funciona</p>
                    <p><code>Conversación general</code> usa solo Ollama y memoria.</p>
                    <p><code>Tema específico</code> lee `.md`, `.txt`, `.csv` y `.xlsx` en knowledge/.</p>
                    <p><code>Base de datos</code> genera consultas de solo lectura.</p>
                </div>
            </div>
        </aside>

        <section class="chat-panel">
            <div class="toolbar">
                <div>
                    <h2 id="mode-title">Conversación general</h2>
                    <p id="mode-description">Memoria de sesion activa con el modelo local.</p>
                </div>
                <button id="reset-chat" type="button" title="Limpiar chat">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                </button>
            </div>

            <div id="messages" class="messages" aria-live="polite"></div>

            <form id="chat-form" class="composer">
                <label class="sr-only" for="prompt">Mensaje</label>
                <textarea id="prompt" rows="3" placeholder="Escribe tu mensaje..."></textarea>
                <div class="composer-actions">
                    <small id="hint">Enter envia. Shift+Enter agrega salto.</small>
                    <button id="send-button" type="submit">Enviar</button>
                </div>
            </form>
        </section>
    </main>

    <template id="message-template">
        <article class="message">
            <div class="message-role"></div>
            <div class="message-body"></div>
            <pre class="message-meta"></pre>
        </article>
    </template>

    <script src="assets/app.js" defer></script>
</body>
</html>
