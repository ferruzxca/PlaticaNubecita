(() => {
  const roots = Array.from(document.querySelectorAll('[data-pwa-install-root]'));
  if (!roots.length) {
    return;
  }

  const isAndroid = /Android/i.test(window.navigator.userAgent || '');
  const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

  let deferredPrompt = null;

  const applyVisibility = (root, state) => {
    const button = root.querySelector('[data-pwa-install-button]');
    const manual = root.querySelector('[data-pwa-install-manual]');
    const status = root.querySelector('[data-pwa-install-status]');

    root.classList.add('is-hidden');
    button?.classList.add('is-hidden');
    manual?.classList.add('is-hidden');
    status?.classList.add('is-hidden');

    if (state === 'hidden') {
      return;
    }

    root.classList.remove('is-hidden');

    if (state === 'prompt') {
      button?.classList.remove('is-hidden');
      return;
    }

    if (state === 'manual') {
      manual?.classList.remove('is-hidden');
      return;
    }

    if (state === 'installed') {
      status?.classList.remove('is-hidden');
    }
  };

  const currentState = () => {
    if (isStandalone()) {
      return 'installed';
    }

    if (!isAndroid) {
      return 'hidden';
    }

    if (deferredPrompt) {
      return 'prompt';
    }

    return 'manual';
  };

  const refreshUi = () => {
    const state = currentState();
    roots.forEach((root) => applyVisibility(root, state));
  };

  const handleInstallClick = async () => {
    if (!deferredPrompt) {
      refreshUi();
      return;
    }

    try {
      await deferredPrompt.prompt();
      await deferredPrompt.userChoice;
    } catch (_error) {
      // El navegador decide el flujo; se mantiene la UI disponible.
    } finally {
      deferredPrompt = null;
      refreshUi();
    }
  };

  const registerServiceWorker = async () => {
    if (!('serviceWorker' in window.navigator) || !window.isSecureContext) {
      return;
    }

    try {
      await window.navigator.serviceWorker.register('/sw.js', { scope: '/' });
    } catch (_error) {
      // La instalación seguirá mostrando instrucciones manuales.
    }
  };

  const init = () => {
    roots.forEach((root) => {
      const button = root.querySelector('[data-pwa-install-button]');
      button?.addEventListener('click', handleInstallClick);
    });

    window.addEventListener('beforeinstallprompt', (event) => {
      event.preventDefault();
      deferredPrompt = event;
      refreshUi();
    });

    window.addEventListener('appinstalled', () => {
      deferredPrompt = null;
      refreshUi();
    });

    registerServiceWorker();
    refreshUi();

    window.setTimeout(() => {
      refreshUi();
    }, 900);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
