// Публикация материала и его редактирование (адрес с ?id=N): форма с файлами уходит в API целиком (FormData).

import { api, el, fileSize, fillSelects, showStatus, startPage } from "./common.js";
import { bindForm } from "./forms.js";

await startPage();

const form = document.querySelector("form");
const status = form.querySelector("[role=alert]");
const id = new URLSearchParams(location.search).get("id");

bindForm(form, async (data) => {
  const result = await api("/api/market", data);
  location.assign(`/pages/material.html?id=${id ?? result.id}`);
});

// Список файлов с крестиками: выбранные новые и (при редактировании) уже загруженные.
const filesInput = document.getElementById("files");
const fileList = document.querySelector(".fayly");
const chosen = new DataTransfer(); // накопленные новые файлы: каждый выбор в окне добавляет, а не заменяет
const fileKey = (file) => `${file.name}|${file.size}|${file.lastModified}`;

function addRow(file, onRemove) {
  const text = `${file.name} (${fileSize(file.size)})`;
  const item = el(
    "li",
    { class: "fayly__item" },
    el("span", {}, text),
    el(
      "button",
      {
        class: "fayly__ubrat",
        type: "button",
        title: "Убрать",
        "aria-label": `Убрать «${text}»`,
        onclick: () => {
          item.remove();
          onRemove();
        },
      },
      "×"
    )
  );
  fileList.append(item);
}

function removeChosen(file) {
  const rest = [...chosen.files].filter((item) => fileKey(item) !== fileKey(file));
  chosen.items.clear();
  rest.forEach((item) => chosen.items.add(item));
  filesInput.files = chosen.files;
}

filesInput.addEventListener("change", () => {
  for (const file of filesInput.files) {
    if (![...chosen.files].some((item) => fileKey(item) === fileKey(file))) {
      chosen.items.add(file);
      addRow(file, () => removeChosen(file));
    }
  }
  filesInput.files = chosen.files;
});

// Форма превращается в редактирование: значения материала, список файлов с удалением, другие подписи.
async function startEdit() {
  const { material, files, own } = await api(`/api/market?id=${encodeURIComponent(id)}`);
  if (!own) {
    throw new Error("Редактировать можно только свой материал.");
  }

  document.title = "Редактировать материал — УниМаркет";
  document.querySelector("h1").textContent = "Редактировать";
  form.elements.action.value = "update";
  form.append(el("input", { type: "hidden", name: "id", value: id }));
  for (const name of ["title", "description", "subject", "type", "course", "price"]) {
    form.elements[name].value = material[name];
  }

  // Согласие нужно один раз, при публикации.
  form.elements.own.closest(".field").remove();
  filesInput.required = false;
  for (const file of files) {
    addRow({ name: file.name, size: file.size }, () => form.append(el("input", { type: "hidden", name: "remove[]", value: file.id })));
  }
  fileList.nextElementSibling.textContent = "Крестик убирает файл, кнопка выше добавляет новые. У материала должно остаться от 1 до 10 файлов, каждый до 15 МБ.";
  form.elements.price.nextElementSibling.textContent = "Новая цена действует для будущих покупок. 25 баллов начисляются один раз, при публикации бесплатного материала.";
  form.querySelector("button[type=submit]").textContent = "Сохранить";
  form.querySelector(".row__meta").textContent = "Изменения сразу видны в каталоге и на странице материала.";
}

try {
  await fillSelects(form);
  if (id) {
    await startEdit();
  }
} catch (error) {
  showStatus(status, error.message);
}
