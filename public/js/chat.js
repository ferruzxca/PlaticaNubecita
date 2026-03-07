const shell = document.querySelector('.chat-shell');

if (shell) {
  const currentUserId = Number(shell.dataset.currentUserId || 0);
  const usersListEl = document.getElementById('users-list');
  const chatsListEl = document.getElementById('chats-list');
  const messagesPanelEl = document.getElementById('messages-panel');
  const titleEl = document.getElementById('chat-title');
  const subtitleEl = document.getElementById('chat-subtitle');
  const formEl = document.getElementById('send-message-form');
  const feedbackEl = document.getElementById('message-feedback');
  const logoutButtonEl = document.getElementById('logout-button');

  const profileNameEl = document.getElementById('profile-name');
  const profileStatusEl = document.getElementById('profile-status');
  const profileAvatarEl = document.getElementById('profile-avatar');
  const activeChatAvatarEl = document.getElementById('active-chat-avatar');

  const editProfileBtn = document.getElementById('edit-profile-button');
  const createGroupBtn = document.getElementById('create-group-button');
  const manageGroupBtn = document.getElementById('manage-group-button');
  const leaveGroupBtn = document.getElementById('leave-group-button');

  const profileModal = document.getElementById('profile-modal');
  const profileForm = document.getElementById('profile-form');
  const profileFeedbackEl = document.getElementById('profile-feedback');
  const profileInputName = document.getElementById('profile-input-name');
  const profileInputStatus = document.getElementById('profile-input-status');
  const profileAvatarUploadInput = document.getElementById('profile-avatar-upload-input');
  const profileAvatarCameraInput = document.getElementById('profile-avatar-camera-input');
  const profileUploadPreview = document.getElementById('profile-upload-preview');

  const groupCreateModal = document.getElementById('group-create-modal');
  const groupCreateForm = document.getElementById('group-create-form');
  const groupCreateCandidatesEl = document.getElementById('group-members-candidates');
  const groupCreateFeedbackEl = document.getElementById('group-create-feedback');

  const groupManageModal = document.getElementById('group-manage-modal');
  const groupRenameForm = document.getElementById('group-rename-form');
  const groupRenameInput = document.getElementById('group-rename-input');
  const groupAddMembersForm = document.getElementById('group-add-members-form');
  const groupAddCandidatesEl = document.getElementById('group-add-candidates');
  const groupMembersListEl = document.getElementById('group-members-list');
  const groupManageFeedbackEl = document.getElementById('group-manage-feedback');

  const csrfCreate = document.querySelector('meta[name="csrf-chat-create"]')?.content || '';
  const csrfSend = document.querySelector('meta[name="csrf-chat-send"]')?.content || '';
  const csrfLogout = document.querySelector('meta[name="csrf-auth-logout"]')?.content || '';
  const csrfProfile = document.querySelector('meta[name="csrf-profile-update"]')?.content || '';
  const csrfGroupCreate = document.querySelector('meta[name="csrf-chat-group-create"]')?.content || '';
  const csrfGroupManage = document.querySelector('meta[name="csrf-chat-group-manage"]')?.content || '';
  const csrfChatLeave = document.querySelector('meta[name="csrf-chat-leave"]')?.content || '';

  let profile = null;
  let chats = [];
  let users = [];
  let currentChatId = null;
  let currentChat = null;
  let lastMessageId = 0;
  let pollTimer = null;
  const renderedMessageIds = new Set();
  let selectedAvatarFile = null;
  let avatarProcessing = null;
  let sendingMessage = false;

  const escapeHtml = (value) =>
    String(value || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');

  const formatBytes = (size) => {
    const n = Number(size || 0);
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${Math.ceil(n / 1024)} KB`;
    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  };

  const initials = (name) => {
    const clean = String(name || '').trim();
    if (!clean) return 'PN';
    const parts = clean.split(/\s+/).slice(0, 2);
    return parts.map((part) => part[0]?.toUpperCase() || '').join('') || 'PN';
  };

  const setAvatar = (container, url, name) => {
    if (!container) return;
    const img = container.querySelector('.avatar-img');
    const label = container.querySelector('.avatar-initials');
    const text = initials(name);

    if (label) {
      label.textContent = text;
    }

    container.classList.remove('has-image');

    if (!(img instanceof HTMLImageElement)) {
      return;
    }

    img.onload = () => {
      container.classList.add('has-image');
      img.style.display = 'block';
    };
    img.onerror = () => {
      container.classList.remove('has-image');
      img.removeAttribute('src');
      img.style.display = 'none';
    };

    img.style.display = 'none';
    if (url) {
      img.src = url;
    } else {
      img.removeAttribute('src');
    }
  };

  const loadImageElement = (file) =>
    new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const image = new Image();
      image.onload = () => {
        URL.revokeObjectURL(url);
        resolve(image);
      };
      image.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error('No se pudo cargar la imagen.'));
      };
      image.src = url;
    });

  const createSquareAvatarBlob = async (file, size = 720, quality = 0.88) => {
    let sourceWidth = 0;
    let sourceHeight = 0;
    let drawFn = null;

    if (typeof createImageBitmap === 'function') {
      try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' }).catch(() => createImageBitmap(file));
        sourceWidth = bitmap.width;
        sourceHeight = bitmap.height;
        drawFn = (ctx, dx, dy, dw, dh) => ctx.drawImage(bitmap, dx, dy, dw, dh);
      } catch (_bitmapError) {
        const image = await loadImageElement(file);
        sourceWidth = image.naturalWidth || image.width;
        sourceHeight = image.naturalHeight || image.height;
        drawFn = (ctx, dx, dy, dw, dh) => ctx.drawImage(image, dx, dy, dw, dh);
      }
    } else {
      const image = await loadImageElement(file);
      sourceWidth = image.naturalWidth || image.width;
      sourceHeight = image.naturalHeight || image.height;
      drawFn = (ctx, dx, dy, dw, dh) => ctx.drawImage(image, dx, dy, dw, dh);
    }

    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      throw new Error('No se pudo preparar la imagen.');
    }

    const scale = Math.max(size / sourceWidth, size / sourceHeight);
    const drawWidth = sourceWidth * scale;
    const drawHeight = sourceHeight * scale;
    const offsetX = (size - drawWidth) / 2;
    const offsetY = (size - drawHeight) / 2;

    ctx.clearRect(0, 0, size, size);
    drawFn(ctx, offsetX, offsetY, drawWidth, drawHeight);

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    if (!(blob instanceof Blob)) {
      throw new Error('No se pudo optimizar la imagen.');
    }

    return blob;
  };

  const normalizedAvatarFile = async (file) => {
    if (!(file instanceof File) || file.size <= 0) {
      return null;
    }

    const blob = await createSquareAvatarBlob(file, 720, 0.88);
    return new File([blob], 'avatar.jpg', { type: 'image/jpeg', lastModified: Date.now() });
  };

  const setProfilePreview = (url, name) => {
    setAvatar(profileUploadPreview, url, name);
  };

  const setFeedback = (el, message, mode = '') => {
    if (!el) return;
    el.textContent = message || '';
    el.classList.remove('ok', 'error');
    if (mode) {
      el.classList.add(mode);
    }
  };

  const readFileAsDataUrl = (file) =>
    new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ''));
      reader.onerror = () => reject(new Error('No se pudo leer el archivo.'));
      reader.readAsDataURL(file);
    });

  const fetchJson = async (url, options = {}) => {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const text = await response.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      data = {};
    }

    if (!response.ok || data.status !== 'ok') {
      throw new Error(data.message || 'Error de API');
    }

    return data;
  };

  const openModal = (modalEl) => {
    modalEl?.classList.remove('is-hidden');
  };

  const closeModal = (modalEl) => {
    modalEl?.classList.add('is-hidden');
  };

  const renderProfile = () => {
    if (!profile) return;
    profileNameEl.textContent = profile.displayName;
    profileStatusEl.textContent = profile.statusText || 'Sin estado';
    const rawAvatarUrl = profile.avatarUrl || (profile.hasAvatar ? `/api/profile/avatar/${profile.id}` : '');
    const bustUrl = rawAvatarUrl ? `${rawAvatarUrl}${rawAvatarUrl.includes('?') ? '&' : '?'}ts=${Date.now()}` : '';
    setAvatar(profileAvatarEl, bustUrl, profile.displayName);
    setProfilePreview(bustUrl, profile.displayName);
  };

  const renderUsers = () => {
    usersListEl.innerHTML = '';

    users.forEach((user) => {
      const item = document.createElement('li');
      item.innerHTML = `
        <div class="mini-list__title">
          <span>${escapeHtml(user.displayName)}</span>
          ${user.isBot ? '<span class="badge-soft">IA</span>' : ''}
        </div>
        <div class="mini-list__meta">${escapeHtml(user.status || user.email || 'Disponible')}</div>
      `;

      item.addEventListener('click', async () => {
        try {
          const payload = new URLSearchParams();
          payload.set('targetUserId', String(user.id));
          payload.set('_csrf_token', csrfCreate);

          const data = await fetchJson('/api/chats', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
            body: payload,
          });

          await loadChats();
          openChat(data.chatId);
        } catch (error) {
          setFeedback(feedbackEl, error.message, 'error');
        }
      });

      usersListEl.appendChild(item);
    });

    renderGroupCandidateCheckboxes();
  };

  const renderChats = () => {
    chatsListEl.innerHTML = '';

    chats.forEach((chat) => {
      const item = document.createElement('li');
      item.dataset.chatId = String(chat.chatId);
      if (chat.chatId === currentChatId) {
        item.classList.add('active');
      }

      const badge = chat.type === 'group' ? '<span class="badge-soft">Grupo</span>' : chat.isAiChat ? '<span class="badge-soft">IA</span>' : '';
      item.innerHTML = `
        <div class="mini-list__title">
          <span>${escapeHtml(chat.name || 'Chat')}</span>
          ${badge}
        </div>
        <div class="mini-list__meta">${escapeHtml(chat.lastMessage || 'Sin mensajes')}</div>
      `;

      item.addEventListener('click', () => openChat(chat.chatId));
      chatsListEl.appendChild(item);
    });
  };

  const renderFileAttachment = (file) => {
    const safeName = escapeHtml(file.filename || 'archivo');
    const safeSize = formatBytes(file.sizeBytes || 0);
    const inlineUrl = file.inlineUrl || `/api/attachments/${file.id}?disposition=inline`;
    const downloadUrl = file.downloadUrl || `/api/attachments/${file.id}?disposition=attachment`;

    if (file.previewType === 'image') {
      return `
        <li class="file-chip">
          <img src="${inlineUrl}" alt="${safeName}" loading="lazy">
          <div class="file-chip__row"><span>${safeName}</span><a href="${downloadUrl}" target="_blank" rel="noopener">Descargar (${safeSize})</a></div>
        </li>
      `;
    }

    if (file.previewType === 'audio') {
      return `
        <li class="file-chip">
          <audio controls src="${inlineUrl}"></audio>
          <div class="file-chip__row"><span>${safeName}</span><a href="${downloadUrl}" target="_blank" rel="noopener">Descargar (${safeSize})</a></div>
        </li>
      `;
    }

    if (file.previewType === 'video') {
      return `
        <li class="file-chip">
          <video controls preload="metadata" src="${inlineUrl}"></video>
          <div class="file-chip__row"><span>${safeName}</span><a href="${downloadUrl}" target="_blank" rel="noopener">Descargar (${safeSize})</a></div>
        </li>
      `;
    }

    return `
      <li class="file-chip">
        <div class="file-chip__row"><span>${safeName}</span><a href="${downloadUrl}" target="_blank" rel="noopener">Descargar (${safeSize})</a></div>
      </li>
    `;
  };

  const renderMessage = (message) => {
    const messageId = Number(message?.id || 0);
    if (messageId > 0 && renderedMessageIds.has(messageId)) {
      return;
    }
    if (messageId > 0) {
      renderedMessageIds.add(messageId);
    }

    const item = document.createElement('article');
    item.className = `message ${message.senderId === currentUserId ? 'message--own' : ''}`;

    const files = (message.attachments || []).map(renderFileAttachment).join('');
    item.innerHTML = `
      <div class="message__meta">${escapeHtml(message.senderName)} • ${new Date(message.createdAt).toLocaleString()}</div>
      ${message.text ? `<div class="message__text">${escapeHtml(message.text)}</div>` : ''}
      ${files ? `<ul class="message__files">${files}</ul>` : ''}
    `;

    messagesPanelEl.appendChild(item);
    messagesPanelEl.scrollTop = messagesPanelEl.scrollHeight;
  };

  const applyChatHeader = () => {
    if (!currentChat) {
      titleEl.textContent = 'Selecciona una conversación';
      subtitleEl.textContent = 'Mensajes cifrados y sincronización cada 2.5 segundos.';
      setAvatar(activeChatAvatarEl, '', 'PN');
      manageGroupBtn.classList.add('is-hidden');
      leaveGroupBtn.classList.add('is-hidden');
      return;
    }

    titleEl.textContent = currentChat.name || 'Chat';
    subtitleEl.textContent = currentChat.status || (currentChat.type === 'group' ? `${currentChat.participantCount || 0} miembros` : 'Conversación cifrada');
    setAvatar(activeChatAvatarEl, currentChat.avatarUrl, currentChat.name || 'Chat');

    if (currentChat.type === 'group') {
      leaveGroupBtn.classList.remove('is-hidden');
      if (currentChat.canManage) {
        manageGroupBtn.classList.remove('is-hidden');
      } else {
        manageGroupBtn.classList.add('is-hidden');
      }
    } else {
      manageGroupBtn.classList.add('is-hidden');
      leaveGroupBtn.classList.add('is-hidden');
    }
  };

  const loadProfile = async () => {
    const data = await fetchJson('/api/profile');
    profile = data.profile;
    renderProfile();
  };

  const loadUsers = async () => {
    const data = await fetchJson('/api/users');
    users = data.users || [];
    renderUsers();
  };

  const loadChats = async () => {
    const data = await fetchJson('/api/chats');
    chats = data.chats || [];
    currentChat = chats.find((chat) => Number(chat.chatId) === Number(currentChatId)) || null;
    renderChats();
    applyChatHeader();
  };

  const loadMessages = async (chatId, afterId = 0, clear = false) => {
    const data = await fetchJson(`/api/chats/${chatId}/messages?afterId=${afterId}`);

    if (clear) {
      messagesPanelEl.innerHTML = '';
      lastMessageId = 0;
      renderedMessageIds.clear();
    }

    (data.messages || []).forEach((message) => {
      renderMessage(message);
      lastMessageId = Math.max(lastMessageId, Number(message.id || 0));
    });
  };

  const openChat = async (chatId) => {
    currentChatId = Number(chatId);
    currentChat = chats.find((chat) => Number(chat.chatId) === currentChatId) || null;
    renderChats();
    applyChatHeader();
    await loadMessages(currentChatId, 0, true);

    if (pollTimer) {
      clearInterval(pollTimer);
    }

    pollTimer = setInterval(async () => {
      if (!currentChatId) return;
      try {
        await loadMessages(currentChatId, lastMessageId, false);
      } catch (_error) {
      }
    }, 2500);
  };

  const checkedValues = (container) =>
    [...container.querySelectorAll('input[type="checkbox"]:checked')]
      .map((check) => Number(check.value || 0))
      .filter((value) => Number.isFinite(value) && value > 0);

  const renderGroupCandidateCheckboxes = () => {
    const candidates = users.filter((user) => !user.isBot);

    const html = candidates
      .map(
        (user) => `
          <label>
            <input type="checkbox" value="${user.id}">
            <span>${escapeHtml(user.displayName)}${user.status ? ` — ${escapeHtml(user.status)}` : ''}</span>
          </label>
        `
      )
      .join('');

    groupCreateCandidatesEl.innerHTML = html || '<p class="muted">No hay usuarios disponibles.</p>';
  };

  const refreshManageModal = async () => {
    if (!currentChatId || !currentChat || currentChat.type !== 'group') return;

    const data = await fetchJson(`/api/chats/${currentChatId}/members`);
    const members = data.members || [];

    groupMembersListEl.innerHTML = '';
    members.forEach((member) => {
      const li = document.createElement('li');
      li.innerHTML = `
        <div class="mini-list__title">
          <span>${escapeHtml(member.displayName)}</span>
          <span class="badge-soft">${escapeHtml(member.role)}</span>
        </div>
        <div class="mini-list__meta">${escapeHtml(member.status || 'Sin estado')}</div>
      `;

      if (currentChat.canManage && !member.isCurrentUser) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ghost-btn';
        btn.textContent = 'Quitar';
        btn.addEventListener('click', async () => {
          try {
            await fetchJson(`/api/chats/${currentChatId}/members/${member.userId}`, {
              method: 'DELETE',
              headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfGroupManage },
            });
            setFeedback(groupManageFeedbackEl, 'Miembro eliminado.', 'ok');
            await Promise.all([loadChats(), refreshManageModal()]);
          } catch (error) {
            setFeedback(groupManageFeedbackEl, error.message, 'error');
          }
        });
        li.appendChild(btn);
      }

      groupMembersListEl.appendChild(li);
    });

    const memberIds = new Set(members.map((member) => Number(member.userId)));
    const addCandidates = users.filter((user) => !user.isBot && !memberIds.has(Number(user.id)));

    groupAddCandidatesEl.innerHTML = addCandidates
      .map(
        (user) => `
          <label>
            <input type="checkbox" value="${user.id}">
            <span>${escapeHtml(user.displayName)}${user.status ? ` — ${escapeHtml(user.status)}` : ''}</span>
          </label>
        `
      )
      .join('') || '<p class="muted">No hay candidatos para agregar.</p>';
  };

  formEl.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (sendingMessage) {
      return;
    }
    if (!currentChatId) {
      setFeedback(feedbackEl, 'Primero selecciona un chat.', 'error');
      return;
    }

    sendingMessage = true;
    const submitButton = formEl.querySelector('button[type="submit"]');
    if (submitButton instanceof HTMLButtonElement) {
      submitButton.disabled = true;
    }

    try {
      const payload = new FormData(formEl);
      payload.set('_csrf_token', csrfSend);

      const response = await fetch(`/api/chats/${currentChatId}/messages`, {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
      });
      const data = await response.json();

      if (!response.ok || data.status !== 'ok') {
        throw new Error(data.message || 'No se pudo enviar el mensaje.');
      }

      formEl.reset();
      setFeedback(feedbackEl, 'Mensaje enviado.', 'ok');

      if (data.message) {
        renderMessage(data.message);
        lastMessageId = Math.max(lastMessageId, Number(data.message.id || 0));
      }

      if (data.aiMessage) {
        renderMessage(data.aiMessage);
        lastMessageId = Math.max(lastMessageId, Number(data.aiMessage.id || 0));
      }

      if (data.aiError) {
        setFeedback(feedbackEl, data.aiError, 'error');
      }

      await loadChats();
      applyChatHeader();
    } catch (error) {
      setFeedback(feedbackEl, error.message, 'error');
    } finally {
      sendingMessage = false;
      if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = false;
      }
    }
  });

  logoutButtonEl?.addEventListener('click', async () => {
    try {
      const payload = new URLSearchParams();
      payload.set('_csrf_token', csrfLogout);
      await fetchJson('/api/auth/logout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body: payload,
      });
      window.location.href = '/';
    } catch (error) {
      setFeedback(feedbackEl, error.message, 'error');
    }
  });

  editProfileBtn?.addEventListener('click', () => {
    if (profile) {
      profileInputName.value = profile.displayName || '';
      profileInputStatus.value = profile.statusText || '';
      const bustUrl = profile.avatarUrl ? `${profile.avatarUrl}${profile.avatarUrl.includes('?') ? '&' : '?'}ts=${Date.now()}` : '';
      setProfilePreview(bustUrl, profile.displayName);
    }
    selectedAvatarFile = null;
    if (profileAvatarUploadInput) profileAvatarUploadInput.value = '';
    if (profileAvatarCameraInput) profileAvatarCameraInput.value = '';
    const removeAvatarCheckbox = profileForm?.querySelector('input[name="removeAvatar"]');
    if (removeAvatarCheckbox instanceof HTMLInputElement) {
      removeAvatarCheckbox.checked = false;
    }
    setFeedback(profileFeedbackEl, '');
    openModal(profileModal);
  });

  const handleAvatarSelection = async (file) => {
    if (!(file instanceof File)) {
      selectedAvatarFile = null;
      setProfilePreview(profile?.avatarUrl || '', profile?.displayName || 'PN');
      return;
    }

    try {
      const optimized = await normalizedAvatarFile(file);
      if (optimized) {
        selectedAvatarFile = optimized;
        const previewUrl = URL.createObjectURL(optimized);
        setProfilePreview(previewUrl, profile?.displayName || 'PN');
        setTimeout(() => URL.revokeObjectURL(previewUrl), 7000);
        setFeedback(profileFeedbackEl, '');
      }
    } catch {
      selectedAvatarFile = file;
      const fallbackUrl = URL.createObjectURL(file);
      setProfilePreview(fallbackUrl, profile?.displayName || 'PN');
      setTimeout(() => URL.revokeObjectURL(fallbackUrl), 7000);
      setFeedback(profileFeedbackEl, 'No se pudo optimizar la foto. Se enviará la imagen original.', 'ok');
    }
    const removeAvatarCheckbox = profileForm?.querySelector('input[name="removeAvatar"]');
    if (removeAvatarCheckbox) {
      removeAvatarCheckbox.checked = false;
    }
  };

  profileAvatarUploadInput?.addEventListener('change', async () => {
    avatarProcessing = handleAvatarSelection(profileAvatarUploadInput.files?.[0] || null);
    await avatarProcessing;
    avatarProcessing = null;
  });

  profileAvatarCameraInput?.addEventListener('change', async () => {
    avatarProcessing = handleAvatarSelection(profileAvatarCameraInput.files?.[0] || null);
    await avatarProcessing;
    avatarProcessing = null;
  });

  profileForm?.querySelector('input[name="removeAvatar"]')?.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.checked) {
      selectedAvatarFile = null;
      if (profileAvatarUploadInput) profileAvatarUploadInput.value = '';
      if (profileAvatarCameraInput) profileAvatarCameraInput.value = '';
      setProfilePreview('', profile?.displayName || 'PN');
    }
  });

  profileForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setFeedback(profileFeedbackEl, '');

    try {
      if (avatarProcessing instanceof Promise) {
        await avatarProcessing;
      }

      const payload = new FormData();
      payload.set('_csrf_token', csrfProfile);
      payload.set('displayName', profileInputName?.value || '');
      payload.set('statusText', profileInputStatus?.value || '');

      const removeAvatarCheckbox = profileForm?.querySelector('input[name="removeAvatar"]');
      if (removeAvatarCheckbox?.checked) {
        payload.set('removeAvatar', '1');
      }

      let avatarFile = selectedAvatarFile;
      if (!(avatarFile instanceof File)) {
        const galleryFile = profileAvatarUploadInput?.files?.[0];
        const cameraFile = profileAvatarCameraInput?.files?.[0];
        const fallbackFile = galleryFile || cameraFile;
        if (fallbackFile instanceof File && fallbackFile.size > 0) {
          try {
            avatarFile = await normalizedAvatarFile(fallbackFile);
          } catch {
            avatarFile = fallbackFile;
          }
        }
      }

      if (avatarFile instanceof File && avatarFile.size > 0) {
        payload.set('avatar', avatarFile, avatarFile.name || 'avatar.jpg');
        try {
          const dataUrl = await readFileAsDataUrl(avatarFile);
          if (dataUrl) {
            payload.set('avatarData', dataUrl);
          }
        } catch {
          // Silencio: el backend ya recibirá el archivo si fue posible adjuntarlo.
        }
      } else if (!removeAvatarCheckbox?.checked) {
        const hasRawSelection =
          (profileAvatarUploadInput?.files?.length || 0) > 0 ||
          (profileAvatarCameraInput?.files?.length || 0) > 0;
        if (hasRawSelection) {
          throw new Error('No se pudo preparar la imagen. Prueba con otra foto JPG/PNG/WebP.');
        }
      }

      const response = await fetch('/api/profile', {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
      });
      const data = await response.json();

      if (!response.ok || data.status !== 'ok') {
        throw new Error(data.message || 'No se pudo guardar el perfil.');
      }

      await loadProfile();
      selectedAvatarFile = null;
      if (profileAvatarUploadInput) profileAvatarUploadInput.value = '';
      if (profileAvatarCameraInput) profileAvatarCameraInput.value = '';
      setFeedback(profileFeedbackEl, 'Perfil actualizado.', 'ok');
      await Promise.all([loadUsers(), loadChats()]);
      setTimeout(() => closeModal(profileModal), 350);
    } catch (error) {
      setFeedback(profileFeedbackEl, error.message, 'error');
    }
  });

  createGroupBtn?.addEventListener('click', () => {
    groupCreateForm.reset();
    setFeedback(groupCreateFeedbackEl, '');
    renderGroupCandidateCheckboxes();
    openModal(groupCreateModal);
  });

  groupCreateForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const name = String(groupCreateForm.elements.namedItem('name')?.value || '').trim();
    const memberIds = checkedValues(groupCreateCandidatesEl);

    try {
      const data = await fetchJson('/api/chats/groups', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfGroupCreate },
        body: JSON.stringify({ name, memberIds }),
      });

      setFeedback(groupCreateFeedbackEl, 'Grupo creado.', 'ok');
      await loadChats();
      closeModal(groupCreateModal);
      if (data.chatId) {
        await openChat(data.chatId);
      }
    } catch (error) {
      setFeedback(groupCreateFeedbackEl, error.message, 'error');
    }
  });

  manageGroupBtn?.addEventListener('click', async () => {
    if (!currentChat || currentChat.type !== 'group') return;
    groupRenameInput.value = currentChat.name || '';
    setFeedback(groupManageFeedbackEl, '');
    try {
      await refreshManageModal();
      openModal(groupManageModal);
    } catch (error) {
      setFeedback(feedbackEl, error.message, 'error');
    }
  });

  groupRenameForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!currentChatId) return;

    const name = String(groupRenameInput.value || '').trim();

    try {
      await fetchJson(`/api/chats/${currentChatId}/group`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfGroupManage },
        body: JSON.stringify({ name }),
      });

      setFeedback(groupManageFeedbackEl, 'Nombre actualizado.', 'ok');
      await loadChats();
      if (currentChatId) {
        currentChat = chats.find((chat) => Number(chat.chatId) === Number(currentChatId)) || currentChat;
        applyChatHeader();
      }
    } catch (error) {
      setFeedback(groupManageFeedbackEl, error.message, 'error');
    }
  });

  groupAddMembersForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!currentChatId) return;

    const memberIds = checkedValues(groupAddCandidatesEl);
    if (!memberIds.length) {
      setFeedback(groupManageFeedbackEl, 'Selecciona al menos un usuario.', 'error');
      return;
    }

    try {
      await fetchJson(`/api/chats/${currentChatId}/members`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfGroupManage },
        body: JSON.stringify({ memberIds }),
      });

      setFeedback(groupManageFeedbackEl, 'Miembros agregados.', 'ok');
      await Promise.all([loadChats(), refreshManageModal()]);
    } catch (error) {
      setFeedback(groupManageFeedbackEl, error.message, 'error');
    }
  });

  leaveGroupBtn?.addEventListener('click', async () => {
    if (!currentChatId || !currentChat || currentChat.type !== 'group') return;

    try {
      await fetchJson(`/api/chats/${currentChatId}/leave`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfChatLeave },
        body: JSON.stringify({}),
      });

      currentChatId = null;
      currentChat = null;
      messagesPanelEl.innerHTML = '';
      setFeedback(feedbackEl, 'Saliste del grupo.', 'ok');
      closeModal(groupManageModal);
      await loadChats();
      applyChatHeader();
    } catch (error) {
      setFeedback(feedbackEl, error.message, 'error');
    }
  });

  document.querySelectorAll('[data-close-modal]').forEach((button) => {
    button.addEventListener('click', () => {
      const modalId = button.getAttribute('data-close-modal');
      if (modalId) {
        closeModal(document.getElementById(modalId));
      }
    });
  });

  [profileModal, groupCreateModal, groupManageModal].forEach((modal) => {
    modal?.addEventListener('click', (event) => {
      if (event.target === modal) {
        closeModal(modal);
      }
    });
  });

  (async () => {
    try {
      await Promise.all([loadProfile(), loadUsers(), loadChats()]);
      if (chats.length > 0) {
        await openChat(chats[0].chatId);
      }
    } catch (error) {
      setFeedback(feedbackEl, error.message, 'error');
    }
  })();
}
