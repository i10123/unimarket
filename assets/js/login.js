// Страница входа.

import { api } from "./common.js";
import { bindForm } from "./forms.js";

// Токен защиты формы приходит с первым запросом; отправка ждёт его.
const ready = api("/api/auth");

bindForm(document.querySelector("form"), async (data) => {
  await ready;
  await api("/api/auth", { action: "login", login: data.get("login"), password: data.get("password") });
  location.replace("/pages/catalog.html");
});
