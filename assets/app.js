const modeCopy = {
  general: {
    title: "Conversacion general",
    description: "Memoria de sesion activa con el modelo local.",
  },
  topic: {
    title: "Tema especifico",
    description: "Responde usando primero los documentos cargados en knowledge/.",
  },
  database: {
    title: "Base de datos",
    description: "Genera SQL de solo lectura y resume los resultados devueltos.",
  },
};

const state = {
  mode: "general",
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
};

async function boot() {
  await loadState();
  bindCustomSelect();
  bindEvents();
  syncModeCopy();
  renderMessages();
}

function bindCustomSelect() {
  const wrapper = document.getElementById("custom-mode");
  const trigger = wrapper.querySelector(".custom-select-trigger");
  const options = wrapper.querySelectorAll(".custom-option");
  const nativeSelect = elements.mode;
  const triggerText = wrapper.querySelector(".custom-select-text");
  const triggerIcon = wrapper.querySelector(".custom-select-icon");

  trigger.addEventListener("click", () => {
    wrapper.classList.toggle("open");
  });

  document.addEventListener("click", (e) => {
    if (!wrapper.contains(e.target)) {
      wrapper.classList.remove("open");
    }
  });

  options.forEach(opt => {
    opt.addEventListener("click", () => {
      // Sync State
      nativeSelect.value = opt.dataset.value;
      nativeSelect.dispatchEvent(new Event("change"));

      // Update Custom UI
      options.forEach(o => o.classList.remove("selected"));
      opt.classList.add("selected");

      triggerText.textContent = opt.textContent.trim();
      triggerIcon.innerHTML = opt.querySelector("svg").outerHTML;

      wrapper.classList.remove("open");
    });
  });
}

async function loadState() {
  const response = await fetch("api/state.php");
  const payload = await response.json();

  if (!response.ok) {
    throw new Error(payload.error || "No se pudo cargar el estado inicial.");
  }

  state.conversations = payload.conversations || state.conversations;
  elements.modelBadge.textContent = payload.model || "Sin modelo";
  elements.dbBadge.textContent = payload.databaseConfigured ? "Configurada" : "Pendiente";
  elements.dbBadge.dataset.enabled = payload.databaseConfigured ? "1" : "0";

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

  elements.resetButton.addEventListener("click", resetConversation);
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

  setSending(true);
  appendMessage({ role: "user", content: message });
  elements.prompt.value = "";

  try {
    const response = await fetch("api/chat.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        mode: state.mode,
        message,
      }),
    });

    const payload = await response.json();

    if (!response.ok) {
      throw new Error(payload.error || "No se pudo enviar el mensaje.");
    }

    state.conversations[state.mode] = payload.history || [];
    renderMessages();
  } catch (error) {
    appendMessage({
      role: "assistant",
      content: error.message || "Ocurrio un error inesperado.",
      meta: { error: true },
    });
  } finally {
    setSending(false);
    elements.prompt.focus();
  }
}

async function resetConversation() {
  const response = await fetch("api/reset.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ mode: state.mode }),
  });

  const payload = await response.json();

  if (!response.ok) {
    appendMessage({
      role: "assistant",
      content: payload.error || "No se pudo limpiar la conversacion.",
      meta: { error: true },
    });
    return;
  }

  state.conversations = payload.conversations || state.conversations;
  renderMessages();
}

function renderMessages() {
  const conversation = state.conversations[state.mode] || [];
  elements.messages.innerHTML = "";

  if (!conversation.length) {
    const empty = document.createElement("div");
    empty.className = "empty-state";
    empty.textContent = "Aun no hay mensajes en este modo.";
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
  role.textContent = message.role === "user" ? "Tu" : "Bot";
  body.textContent = message.content;

  if (message.meta && Object.keys(message.meta).length) {
    if (message.meta.mode) {
      meta.innerHTML = `<span class="meta-badge">${escapeHtml(message.meta.mode)}</span>`;
      // Si hay más cosas, podrías agregarlas aquí, de momento solo formatea el mode
    } else {
      meta.textContent = JSON.stringify(message.meta, null, 2);
    }
  } else {
    meta.remove();
  }

  elements.messages.appendChild(fragment);
  elements.messages.scrollTop = elements.messages.scrollHeight;
}

function setSending(isSending) {
  elements.sendButton.disabled = isSending;
  elements.prompt.disabled = isSending;
  elements.sendButton.textContent = isSending ? "Pensando..." : "Enviar";
}

function escapeHtml(value) {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

boot().catch((error) => {
  elements.messages.innerHTML = `<div class="empty-state">${escapeHtml(
    error.message || "No se pudo inicializar la aplicacion."
  )}</div>`;
});
