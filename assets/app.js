const modeCopy = {
  general: {
    title: "Conversación general",
    description: "Memoria de sesión activa con el modelo local.",
  },
  topic: {
    title: "Tema específico",
    description: "Recupera fragmentos relevantes de los documentos cargados en knowledge/.",
  },
  database: {
    title: "Base de datos",
    description: "Genera SQL de solo lectura con guardrails y resume los resultados.",
  },
};

const state = {
  mode: "general",
  requestTimeoutMs: 120000,
  maxMessageChars: 4000,
  pendingController: null,
  conversations: {
    general: [],
    topic: [],
    database: [],
  },
};

const elements = {
  mode: document.querySelector("#mode"),
  modeTitle: document.querySelector("#mode-title"),
  modeDescription: document.querySelector("#mode-description"),
  messages: document.querySelector("#messages"),
  form: document.querySelector("#chat-form"),
  prompt: document.querySelector("#prompt"),
  sendButton: document.querySelector("#send-button"),
  resetButton: document.querySelector("#reset-chat"),
  knowledgeList: document.querySelector("#knowledge-list"),
  modelBadge: document.querySelector("#model-badge"),
  dbBadge: document.querySelector("#db-badge"),
  template: document.querySelector("#message-template"),
  customMode: document.querySelector("#custom-mode"),
};

async function boot() {
  await loadState();
  bindCustomSelect();
  bindEvents();
  syncModeCopy();
  renderMessages();
}

function bindCustomSelect() {
  const wrapper = elements.customMode;

  if (!wrapper) {
    return;
  }

  const trigger = wrapper.querySelector(".custom-select-trigger");
  const options = Array.from(wrapper.querySelectorAll(".custom-option"));
  const nativeSelect = elements.mode;
  const triggerText = wrapper.querySelector(".custom-select-text");
  const triggerIcon = wrapper.querySelector(".custom-select-icon");

  const updateSelection = (option) => {
    nativeSelect.value = option.dataset.value;
    nativeSelect.dispatchEvent(new Event("change"));
    options.forEach((item) => {
      const isSelected = item === option;
      item.classList.toggle("selected", isSelected);
      item.setAttribute("aria-selected", isSelected ? "true" : "false");
    });
    triggerText.textContent = option.textContent.trim();
    triggerIcon.innerHTML = option.querySelector("svg").outerHTML;
    setSelectOpen(wrapper, false);
  };

  trigger.addEventListener("click", () => {
    setSelectOpen(wrapper, !wrapper.classList.contains("open"));
  });

  trigger.addEventListener("keydown", (event) => {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      setSelectOpen(wrapper, !wrapper.classList.contains("open"));
      if (wrapper.classList.contains("open")) {
        wrapper.querySelector(".custom-option.selected")?.focus();
      }
    }

    if (event.key === "ArrowDown") {
      event.preventDefault();
      setSelectOpen(wrapper, true);
      options[0]?.focus();
    }
  });

  options.forEach((option, index) => {
    option.addEventListener("click", () => updateSelection(option));
    option.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        updateSelection(option);
      }

      if (event.key === "ArrowDown") {
        event.preventDefault();
        options[(index + 1) % options.length]?.focus();
      }

      if (event.key === "ArrowUp") {
        event.preventDefault();
        options[(index - 1 + options.length) % options.length]?.focus();
      }

      if (event.key === "Escape") {
        event.preventDefault();
        setSelectOpen(wrapper, false);
        trigger.focus();
      }
    });
  });

  document.addEventListener("click", (event) => {
    if (!wrapper.contains(event.target)) {
      setSelectOpen(wrapper, false);
    }
  });
}

function setSelectOpen(wrapper, isOpen) {
  wrapper.classList.toggle("open", isOpen);
  wrapper.querySelector(".custom-select-trigger")?.setAttribute("aria-expanded", isOpen ? "true" : "false");
}

async function loadState() {
  const payload = await fetchJson("api/state.php", { timeoutMs: 30000 });

  state.conversations = payload.conversations || state.conversations;
  state.requestTimeoutMs = Math.max(5000, Number(payload.requestTimeout || 120) * 1000);
  state.maxMessageChars = Math.max(1, Number(payload.maxMessageChars || 4000));

  elements.modelBadge.textContent = payload.model || "Sin modelo";
  elements.dbBadge.textContent = payload.databaseConfigured ? "Configurada" : "Pendiente";
  elements.dbBadge.dataset.enabled = payload.databaseConfigured ? "1" : "0";
  elements.prompt.maxLength = String(state.maxMessageChars);

  const docs = payload.knowledgeFiles || [];
  elements.knowledgeList.innerHTML = docs.length
    ? docs.map((name) => `<li>${escapeHtml(name)}</li>`).join("")
    : "<li>No hay documentos cargados.</li>";
}

function bindEvents() {
  elements.mode.addEventListener("change", () => {
    state.mode = elements.mode.value;
    syncModeCopy();
    renderMessages();
  });

  elements.form.addEventListener("submit", async (event) => {
    event.preventDefault();
    await sendMessage();
  });

  elements.prompt.addEventListener("keydown", async (event) => {
    if (event.key === "Enter" && !event.shiftKey) {
      event.preventDefault();
      await sendMessage();
    }
  });

  elements.prompt.addEventListener("input", function () {
    this.style.height = "auto";
    this.style.height = `${this.scrollHeight}px`;
  });

  elements.resetButton.addEventListener("click", resetConversation);

  window.addEventListener("beforeunload", () => {
    state.pendingController?.abort();
  });
}

function syncModeCopy() {
  const copy = modeCopy[state.mode];
  elements.modeTitle.textContent = copy.title;
  elements.modeDescription.textContent = copy.description;
}

async function sendMessage() {
  const message = elements.prompt.value.trim();

  if (!message) {
    return;
  }

  if (message.length > state.maxMessageChars) {
    appendMessage({
      role: "assistant",
      content: `El mensaje supera el límite permitido de ${state.maxMessageChars} caracteres.`,
      meta: { error: true },
    });
    return;
  }

  setSending(true);
  appendMessage({ role: "user", content: message });
  elements.prompt.value = "";
  elements.prompt.style.height = "auto";

  const controller = new AbortController();
  state.pendingController = controller;

  try {
    const payload = await fetchJson("api/chat.php", {
      method: "POST",
      body: JSON.stringify({
        mode: state.mode,
        message,
      }),
      timeoutMs: state.requestTimeoutMs,
      signal: controller.signal,
    });

    state.conversations[state.mode] = payload.history || [];
    renderMessages();
  } catch (error) {
    appendMessage({
      role: "assistant",
      content: error.message || "Ocurrió un error inesperado.",
      meta: { error: true },
    });
  } finally {
    if (state.pendingController === controller) {
      state.pendingController = null;
    }

    setSending(false);
    elements.prompt.focus();
  }
}

async function resetConversation() {
  if (state.pendingController) {
    state.pendingController.abort();
    state.pendingController = null;
    setSending(false);
  }

  try {
    const payload = await fetchJson("api/reset.php", {
      method: "POST",
      body: JSON.stringify({ mode: state.mode }),
      timeoutMs: 15000,
    });

    state.conversations = payload.conversations || state.conversations;
    renderMessages();
  } catch (error) {
    appendMessage({
      role: "assistant",
      content: error.message || "No se pudo limpiar la conversación.",
      meta: { error: true },
    });
  }
}

function renderMessages() {
  const conversation = state.conversations[state.mode] || [];
  elements.messages.innerHTML = "";

  if (!conversation.length) {
    const empty = document.createElement("div");
    empty.className = "empty-state";
    empty.textContent = "Aún no hay mensajes en este modo.";
    elements.messages.appendChild(empty);
    return;
  }

  conversation.forEach(appendMessage);
  elements.messages.scrollTop = elements.messages.scrollHeight;
}

function appendMessage(message) {
  const fragment = elements.template.content.cloneNode(true);
  const article = fragment.querySelector(".message");
  const role = fragment.querySelector(".message-role");
  const body = fragment.querySelector(".message-body");
  const meta = fragment.querySelector(".message-meta");

  article.classList.add(message.role === "user" ? "is-user" : "is-assistant");
  role.textContent = message.role === "user" ? "Tú" : "Bot";

  if (message.role === "assistant") {
    body.innerHTML = renderRichText(message.content || "");
    body.classList.add("markdown-body");
  } else {
    body.textContent = message.content || "";
  }

  const metaItems = formatMeta(message.meta);

  if (metaItems.length) {
    meta.innerHTML = metaItems.map((item) => `<span class="meta-badge">${escapeHtml(item)}</span>`).join("");
  } else {
    meta.remove();
  }

  elements.messages.appendChild(fragment);
  elements.messages.scrollTop = elements.messages.scrollHeight;
}

function formatMeta(meta) {
  if (!meta || typeof meta !== "object") {
    return [];
  }

  const items = [];

  if (meta.mode) {
    items.push(String(meta.mode));
  }

  if (meta.driver) {
    items.push(String(meta.driver));
  }

  if (typeof meta.rowCount === "number") {
    items.push(`${meta.rowCount} filas`);
  }

  if (meta.planCacheHit) {
    items.push("cache");
  }

  if (meta.error) {
    items.push("error");
  }

  return items;
}

function setSending(isSending) {
  elements.sendButton.disabled = isSending;
  elements.prompt.disabled = isSending;
  elements.sendButton.textContent = isSending ? "Procesando..." : "Enviar";
}

async function fetchJson(url, options = {}) {
  const headers = {
    "Content-Type": "application/json",
    ...(options.headers || {}),
  };
  const controller = new AbortController();
  const forwardAbort = () => controller.abort();
  let timeoutId = window.setTimeout(() => controller.abort(), options.timeoutMs || 30000);

  if (options.signal) {
    if (options.signal.aborted) {
      controller.abort();
    } else {
      options.signal.addEventListener("abort", forwardAbort, { once: true });
    }
  }

  try {
    const response = await fetch(url, {
      method: options.method || "GET",
      headers,
      body: options.body,
      signal: controller.signal,
    });
    const raw = await response.text();
    let payload = {};

    try {
      payload = raw ? JSON.parse(raw) : {};
    } catch {
      payload = {};
    }

    if (!response.ok) {
      throw new Error(payload.error || `La petición falló con HTTP ${response.status}.`);
    }

    return payload;
  } catch (error) {
    if (error.name === "AbortError") {
      throw new Error("La petición fue cancelada o excedió el tiempo de espera.");
    }

    throw error;
  } finally {
    window.clearTimeout(timeoutId);

    if (options.signal) {
      options.signal.removeEventListener("abort", forwardAbort);
    }
  }
}

function renderRichText(value) {
  const escaped = escapeHtml(value);
  const codeBlocks = [];
  const withPlaceholders = escaped.replace(/```([\s\S]*?)```/g, (_, code) => {
    const token = `@@CODE_${codeBlocks.length}@@`;
    codeBlocks.push(`<pre><code>${code.trim()}</code></pre>`);
    return token;
  });

  const html = withPlaceholders
    .split(/\n{2,}/)
    .map((block) => block.trim())
    .filter(Boolean)
    .map((block) => {
      if (/^@@CODE_\d+@@$/.test(block)) {
        return block;
      }

      const lines = block.split("\n");

      if (lines.every((line) => /^[-*]\s+/.test(line))) {
        return `<ul>${lines
          .map((line) => `<li>${formatInlineMarkdown(line.replace(/^[-*]\s+/, ""))}</li>`)
          .join("")}</ul>`;
      }

      return `<p>${formatInlineMarkdown(lines.join("<br>"))}</p>`;
    })
    .join("");

  return html.replace(/@@CODE_(\d+)@@/g, (_, index) => codeBlocks[Number(index)] || "");
}

function formatInlineMarkdown(value) {
  return value
    .replace(/`([^`]+)`/g, "<code>$1</code>")
    .replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>")
    .replace(/\*([^*]+)\*/g, "<em>$1</em>");
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

boot().catch((error) => {
  elements.messages.innerHTML = `<div class="empty-state">${escapeHtml(
    error.message || "No se pudo inicializar la aplicación."
  )}</div>`;
});
