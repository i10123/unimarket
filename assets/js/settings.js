// Настройки: профиль и смена пароля по старому паролю.

import { api, courseOptions, showStatus, startPage } from "./common.js";
import { bindForm } from "./forms.js";

await startPage("settings");

const profileForm = document.getElementById("profile");
const passwordForm = document.getElementById("password");
const statusOf = (form) => form.querySelector("[role=alert]");

// Успех показывается спокойным текстом под кнопкой.
bindForm(profileForm, async (data) => {
  await api("/api/account", data);
  showStatus(statusOf(profileForm), "Изменения сохранены.", false);
});

bindForm(passwordForm, async (data) => {
  await api("/api/account", data);
  passwordForm.reset();
  showStatus(statusOf(passwordForm), "Пароль изменён.", false);
});

// Текущие значения профиля подставляются в поля после загрузки.
profileForm.elements.course.append(...courseOptions());
try {
  const { profile } = await api("/api/account");
  for (const [name, value] of Object.entries(profile)) {
    if (profileForm.elements[name]) {
      profileForm.elements[name].value = value ?? "";
    }
  }
} catch (error) {
  showStatus(statusOf(profileForm), error.message);
}
