const shell = document.querySelector(".chat-shell");

if (shell) {
  const currentUserId = Number(shell.dataset.currentUserId || 0);
  const usersListEl = document.getElementById("users-list");
  const chatsListEl = document.getElementById("chats-list");
  const messagesPanelEl = document.getElementById("messages-panel");
  const titleEl = document.getElementById("chat-title");
  const subtitleEl = document.getElementById("chat-subtitle");
  const formEl = document.getElementById("send-message-form");
  const feedbackEl = document.getElementById("message-feedback");
  const logoutButtonEl = document.getElementById("logout-button");

  const csrfCreate = document.querySelector('meta[name="csrf-chat-create"]')?.content || "";
  const csrfSend = document.querySelector('meta[name="csrf-chat-send"]')?.content || "";
  const csrfLogout = document.querySelector('meta[name="csrf-auth-logout"]')?.content || "";

  let chats = [];
  let users = [];
  let currentChatId = null;
  let currentPeerName = "";
  let lastMessageId = 0;
  let pollTimer = null;

  const setFeedback = (message, mode = "") => {
    feedbackEl.textContent = message || "";
    feedbackEl.classList.remove("ok", "error");
    if (mode) {
      feedbackEl.classList.add(mode);
    }
  };

  const fetchJson = async (url, options = {}) => {
    const response = await fetch(url, { credentials: "same-origin", ...options });
    const data = await response.json();
    if (!response.ok || data.status !== "ok") {
      throw new Error(data.message || "Error de API");
    }

    return data;
  };

  const renderUsers = () => {
    usersListEl.innerHTML = "";
    users.forEach((user) => {
      const item = document.createElement("li");
      item.textContent = `${user.displayName} (${user.email})`;
      item.addEventListener("click", async () => {
        try {
          const payload = new URLSearchParams();
          payload.set("targetUserId", String(user.id));
          payload.set("_csrf_token", csrfCreate);

          const data = await fetchJson("/api/chats", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
            body: payload,
          });

          await loadChats();
          openChat(data.chatId, user.displayName);
        } catch (error) {
          setFeedback(error.message, "error");
        }
      });
      usersListEl.appendChild(item);
    });
  };

  const renderChats = () => {
    chatsListEl.innerHTML = "";
    chats.forEach((chat) => {
      const item = document.createElement("li");
      item.dataset.chatId = String(chat.chatId);
      item.innerHTML = `<strong>${chat.peer.displayName}</strong><br><small>${chat.lastMessage || "Sin mensajes"}</small>`;
      if (chat.chatId === currentChatId) {
        item.classList.add("active");
      }
      item.addEventListener("click", () => openChat(chat.chatId, chat.peer.displayName));
      chatsListEl.appendChild(item);
    });
  };

  const renderMessage = (message) => {
    const item = document.createElement("article");
    item.className = `message ${message.senderId === currentUserId ? "message--own" : ""}`;

    const files = (message.attachments || [])
      .map(
        (file) =>
          `<li><a href="/api/attachments/${file.id}" target="_blank" rel="noopener">${file.filename}</a> (${Math.ceil(file.sizeBytes / 1024)} KB)</li>`
      )
      .join("");

    item.innerHTML = `
      <div class="message__meta">${message.senderName} • ${new Date(message.createdAt).toLocaleString()}</div>
      <div>${message.text ? message.text.replace(/</g, "&lt;") : ""}</div>
      ${files ? `<ul class="message__files">${files}</ul>` : ""}
    `;

    messagesPanelEl.appendChild(item);
    messagesPanelEl.scrollTop = messagesPanelEl.scrollHeight;
  };

  const loadUsers = async () => {
    const data = await fetchJson("/api/users");
    users = data.users;
    renderUsers();
  };

  const loadChats = async () => {
    const data = await fetchJson("/api/chats");
    chats = data.chats;
    renderChats();
  };

  const loadMessages = async (chatId, afterId = 0, clear = false) => {
    const data = await fetchJson(`/api/chats/${chatId}/messages?afterId=${afterId}`);
    if (clear) {
      messagesPanelEl.innerHTML = "";
      lastMessageId = 0;
    }

    (data.messages || []).forEach((message) => {
      renderMessage(message);
      lastMessageId = Math.max(lastMessageId, Number(message.id || 0));
    });
  };

  const openChat = async (chatId, peerName) => {
    currentChatId = Number(chatId);
    currentPeerName = peerName;
    titleEl.textContent = `Chat con ${peerName}`;
    subtitleEl.textContent = "Mensajes cifrados, actualizados cada 2.5 segundos";
    renderChats();
    await loadMessages(currentChatId, 0, true);

    if (pollTimer) {
      clearInterval(pollTimer);
    }

    pollTimer = setInterval(async () => {
      if (!currentChatId) {
        return;
      }
      try {
        await loadMessages(currentChatId, lastMessageId, false);
      } catch (_error) {
      }
    }, 2500);
  };

  formEl.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!currentChatId) {
      setFeedback("Primero selecciona un chat.", "error");
      return;
    }

    try {
      const payload = new FormData(formEl);
      payload.set("_csrf_token", csrfSend);

      const response = await fetch(`/api/chats/${currentChatId}/messages`, {
        method: "POST",
        body: payload,
        credentials: "same-origin",
      });
      const data = await response.json();

      if (!response.ok || data.status !== "ok") {
        throw new Error(data.message || "No se pudo enviar el mensaje.");
      }

      formEl.reset();
      setFeedback("Mensaje enviado.", "ok");
      if (data.message) {
        renderMessage(data.message);
        lastMessageId = Math.max(lastMessageId, Number(data.message.id || 0));
      }
      await loadChats();
      titleEl.textContent = `Chat con ${currentPeerName}`;
    } catch (error) {
      setFeedback(error.message, "error");
    }
  });

  logoutButtonEl?.addEventListener("click", async () => {
    try {
      const payload = new URLSearchParams();
      payload.set("_csrf_token", csrfLogout);
      await fetchJson("/api/auth/logout", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
        body: payload,
      });
      window.location.href = "/";
    } catch (error) {
      setFeedback(error.message, "error");
    }
  });

  (async () => {
    try {
      await loadUsers();
      await loadChats();
    } catch (error) {
      setFeedback(error.message, "error");
    }
  })();
}
