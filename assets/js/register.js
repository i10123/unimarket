// Регистрация в три шага: вход, профиль, контакты. Аккаунт создаётся только в конце третьего.

import { api, courseOptions, showStatus } from "./common.js";
import { bindForm } from "./forms.js";

const forms = document.querySelectorAll("form");
const [credentialsForm, profileForm, contactForm] = forms;
const ready = api("/api/auth");
let credentials;

profileForm.elements.course.append(...courseOptions());

function showStep(step) {
  forms.forEach((form, i) => (form.hidden = i !== step - 1));
  forms[step - 1].querySelector("input").focus();
}

// Шаг 1: проверяем, что логин свободен.
bindForm(credentialsForm, async (data) => {
  await ready;
  await api("/api/auth", { action: "check_login", login: data.get("login") });
  credentials = data;
  showStep(2);
});

// Шаг 2: профиль необязателен, дальше идём в любом случае.
bindForm(profileForm, async () => showStep(3));

// Шаг 3: создаём аккаунт с профилем (пустым, если нажали «Пропустить») и контактом.
bindForm(contactForm, async (contact) => {
  try {
    await api("/api/auth", {
      action: "register",
      login: credentials.get("login"),
      password: credentials.get("password"),
      rules: true,
      ...Object.fromEntries(new FormData(profileForm)),
      contact: contact.get("contact"),
    });
  } catch (error) {
    if (error.status !== 409) {
      throw error;
    }
    // Логин заняли, пока заполнялись шаги: возвращаем к первому.
    showStep(1);
    showStatus(credentialsForm.querySelector("[role=alert]"), error.message);
    return;
  }
  location.replace("/pages/catalog.html");
});

document.querySelectorAll(".auth__back").forEach((back) => back.addEventListener("click", () => showStep(Number(back.dataset.step))));
document.getElementById("skip").addEventListener("click", () => {
  profileForm.reset();
  showStep(3);
});
