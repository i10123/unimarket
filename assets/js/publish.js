// Публикация материала: форма с файлами уходит в API целиком (FormData).

import { api, fillSelects, showStatus, startPage } from "./common.js";
import { bindForm } from "./forms.js";

await startPage();

const form = document.querySelector("form");

bindForm(form, async (data) => {
  const { id } = await api("/api/market", data);
  location.assign(`/pages/material.html?id=${id}`);
});
fillSelects(form).catch((error) => showStatus(form.querySelector("[role=alert]"), error.message));
