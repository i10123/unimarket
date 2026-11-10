// Страница входа и регистрации: выбор экрана и шага, проверка полей, показ пароля и отправка в PHP.
// Файл подключён в head без defer: экран выбирается до отрисовки, без мигания.

const SECOND_STEP = "#register-2";

let csrf = "";
// Второй шаг открывается только после проверки первого.
let firstStepComplete = false;

// Экран и шаг задаёт адрес, поэтому «назад» в браузере и на мыши ходит между шагами.
function updateScreen() {
  if (location.hash === SECOND_STEP && !firstStepComplete) {
    history.replaceState(null, "", "#register");
  }

  const isRegister = location.hash === "#register" || location.hash === SECOND_STEP;
  const screen = isRegister ? "register" : "login";
  document.documentElement.dataset.screen = screen;
  document.documentElement.dataset.step = location.hash === SECOND_STEP ? "2" : "1";

  const skipLink = document.querySelector(".skip-link");
  if (skipLink) {
    skipLink.href = `#${screen}`;
  }
}

function showError(form, message) {
  const status = form.querySelector("[role=status]");
  status.textContent = message;
  status.hidden = false;
}

function showFieldError(field, message) {
  const fieldContainer = field.closest(".field");
  let errorMessage = fieldContainer.querySelector(".field__error");

  if (!errorMessage) {
    errorMessage = document.createElement("p");
    errorMessage.className = "field__error";
    errorMessage.id = `${field.id}-error`;
    fieldContainer.append(errorMessage);
    const description = field.getAttribute("aria-describedby");
    field.setAttribute("aria-describedby", description ? `${description} ${errorMessage.id}` : errorMessage.id);
  }

  errorMessage.textContent = message;
  errorMessage.hidden = false;
  field.setAttribute("aria-invalid", "true");
}

function clearFieldError(field) {
  const errorMessage = field.closest(".field").querySelector(".field__error");
  if (errorMessage) {
    errorMessage.hidden = true;
  }
  field.removeAttribute("aria-invalid");
}

// Подсказки браузера выключены (novalidate), тексты ошибок лежат в data-атрибутах полей.
function validateForm(form) {
  let firstInvalidField = null;

  form.querySelectorAll("input, select").forEach((field) => {
    clearFieldError(field);
    if (!field.validity.valid) {
      showFieldError(field, field.validity.valueMissing ? field.dataset.requiredMessage : field.dataset.format);
      firstInvalidField ??= field;
    }
  });

  const confirmPassword = form.elements.repeat;
  if (confirmPassword && confirmPassword.value && confirmPassword.value !== form.elements.password.value) {
    showFieldError(confirmPassword, "Пароли не совпадают.");
    firstInvalidField ??= confirmPassword;
  }

  if (firstInvalidField) {
    firstInvalidField.focus();
  }
  return !firstInvalidField;
}

// Кнопка-глаз переключает поле между password и text.
function togglePassword(button) {
  const field = document.getElementById(button.getAttribute("aria-controls"));
  const showPassword = field.type === "password";

  field.type = showPassword ? "text" : "password";
  button.setAttribute("aria-pressed", String(showPassword));
  button.setAttribute("aria-label", showPassword ? "Скрыть пароль" : "Показать пароль");
}

async function sendRequest(payload) {
  const response = await fetch("/api/auth", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": csrf,
    },
    body: JSON.stringify(payload),
  });
  const data = await response.json();
  if (!response.ok) {
    const errorMessage = new Error(data.error);
    errorMessage.status = response.status;
    throw errorMessage;
  }
}

function openCatalog() {
  location.replace("/pages/catalog.html");
}

async function logIn(form) {
  await sendRequest({
    action: "login",
    login: form.elements.login.value,
    password: form.elements.password.value,
  });
  openCatalog();
}

async function checkLogin(form) {
  await sendRequest({ action: "check_login", login: form.elements.login.value });
  firstStepComplete = true;
  location.hash = SECOND_STEP;
}

async function register(includeProfile) {
  const credentials = document.getElementById("credentials-step").elements;
  const profile = Object.fromEntries(new FormData(document.getElementById("profile-step")));

  await sendRequest({
    action: "register",
    login: credentials.login.value,
    password: credentials.password.value,
    rules: credentials.rules.checked,
    ...(includeProfile ? profile : {}),
  });
  openCatalog();
}

// Общая обёртка отправки: блокирует кнопки и показывает ошибку.
async function runAction(form, action) {
  const buttons = form.querySelectorAll("button:not([data-password-toggle])");

  buttons.forEach((button) => {
    button.disabled = true;
  });
  form.setAttribute("aria-busy", "true");
  form.querySelector("[role=status]").hidden = true;

  try {
    await action();
  } catch (error) {
    if (error.status === 409) {
      // Логин заняли, пока заполнялся второй шаг: возвращаем к полю логина.
      const field = document.getElementById("register-login");
      location.hash = "#register";
      showFieldError(field, error.message);
      field.focus();
      return;
    }
    // Обычный Error создаём сами из ответа сервера; у сбоя сети текст английский.
    const customMessage = error.name === "Error" && error.message;
    showError(form, customMessage || "Сервер недоступен. Попробуй ещё раз.");
  } finally {
    buttons.forEach((button) => {
      button.disabled = false;
    });
    form.removeAttribute("aria-busy");
  }
}

const ACTIONS = {
  login: logIn,
  check_login: checkLogin,
  register: () => register(true),
};

function submitForm(event) {
  event.preventDefault();
  const form = event.currentTarget;

  if (validateForm(form)) {
    runAction(form, () => ACTIONS[form.dataset.action](form));
  }
}

// Получаем CSRF-токен; без него сервер не примет форму, поэтому кнопки
// включаются только после ответа.
async function initializeForms(forms) {
  try {
    const response = await fetch("/api/auth");
    if (!response.ok) {
      throw new Error();
    }
    const data = await response.json();
    if (data.user) {
      openCatalog();
      return;
    }
    csrf = data.csrf;
    document.querySelectorAll(".auth__form button:not([data-password-toggle])").forEach((button) => {
      button.disabled = false;
    });
  } catch {
    const message = "Сервер недоступен. Обнови страницу позже.";
    forms.forEach((form) => {
      showError(form, message);
    });
  }
}

updateScreen();

window.addEventListener("hashchange", (event) => {
  updateScreen();
  window.scrollTo(0, 0);

  if (location.hash === SECOND_STEP) {
    document.getElementById("profile-first-name").focus();
  } else if (event.oldURL.endsWith(SECOND_STEP)) {
    document.getElementById("register-login").focus();
  }
});

document.addEventListener("DOMContentLoaded", () => {
  const forms = document.querySelectorAll(".auth__form");

  document.querySelectorAll("[data-password-toggle]").forEach((button) => {
    button.addEventListener("click", () => {
      togglePassword(button);
    });
  });
  forms.forEach((form) => {
    form.addEventListener("submit", submitForm);
    // Ошибка поля исчезает, как только его начали исправлять.
    form.addEventListener("input", (event) => {
      clearFieldError(event.target);
    });
  });

  const profile = document.getElementById("profile-step");
  document.getElementById("skip-profile").addEventListener("click", () => {
    runAction(profile, () => register(false));
  });

  initializeForms(forms);
});
