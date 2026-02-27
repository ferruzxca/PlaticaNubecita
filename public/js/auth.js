const postForm = async (form, feedbackEl, onOk) => {
  feedbackEl.textContent = "";
  feedbackEl.classList.remove("error", "ok");

  try {
    const payload = new URLSearchParams(new FormData(form));
    const response = await fetch(form.action, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: payload,
      credentials: "same-origin",
    });

    const data = await response.json();
    if (!response.ok || data.status !== "ok") {
      throw new Error(data.message || "La solicitud no se pudo completar.");
    }

    feedbackEl.textContent = data.message || "Operación exitosa.";
    feedbackEl.classList.add("ok");

    if (onOk) {
      onOk(data);
    }
  } catch (error) {
    feedbackEl.textContent = error.message || "Error inesperado.";
    feedbackEl.classList.add("error");
  }
};

const bind = (formId, feedbackId, onOk) => {
  const form = document.getElementById(formId);
  const feedback = document.getElementById(feedbackId);
  if (!form || !feedback) {
    return;
  }

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    postForm(form, feedback, onOk);
  });
};

document.addEventListener("DOMContentLoaded", () => {
  bind("login-form", "login-feedback", () => {
    window.location.href = "/chat";
  });

  bind("request-register-form", "register-link-feedback");
  bind("forgot-password-form", "forgot-feedback");

  bind("complete-register-form", "register-feedback", () => {
    window.location.href = "/chat";
  });

  bind("reset-password-form", "reset-feedback", () => {
    window.location.href = "/";
  });
});
