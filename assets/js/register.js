// Регистрация в два шага. Аккаунт создаётся только в конце второго шага.

import { api, courseOptions, showStatus } from "./common.js";
import { bindForm } from "./forms.js";

const [credentialsForm, profileForm] = document.querySelectorAll("form");
const ready = api("/api/auth");
let credentials;

profileForm.elements.course.append(...courseOptions());

function showStep(step) {
  credentialsForm.hidden = step !== 1;
  profileForm.hidden = step !== 2;
  (step === 1 ? credentialsForm : profileForm).querySelector("input").focus();
}

// Шаг 1: проверяем, что логин свободен.
bindForm(credentialsForm, async (data) => {
  await ready;
  await api("/api/auth", { action: "check_login", login: data.get("login") });
  credentials = data;
  showStep(2);
});

// Шаг 2: создаём аккаунт с заполненным профилем (или пустым, если нажали «Пропустить»).
bindForm(profileForm, async (profile) => {
  try {
    await api("/api/auth", {
      action: "register",
      login: credentials.get("login"),
      password: credentials.get("password"),
      rules: true,
      ...Object.fromEntries(profile),
    });
  } catch (error) {
    if (error.status !== 409) {
      throw error;
    }
    // Логин заняли, пока заполнялся второй шаг: возвращаем к первому.
    showStep(1);
    showStatus(credentialsForm.querySelector("[role=alert]"), error.message);
    return;
  }
  location.replace("/pages/catalog.html");
});

document.querySelector(".auth__back").addEventListener("click", () => showStep(1));
document.getElementById("skip").addEventListener("click", () => {
  profileForm.reset();
  profileForm.requestSubmit();
});
