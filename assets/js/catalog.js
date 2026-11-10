// Каталог: поиск, фильтры и сортировка обновляют сетку без перезагрузки страницы.

import { fillSelects, plural, showStatus, startPage } from "./common.js";
import { createList } from "./cards.js";

await startPage();

const form = document.getElementById("filters");
const total = document.getElementById("total");
const list = createList("Ничего не нашлось. Попробуй изменить запрос или фильтры.");
// Пока человек печатает, запрос уходит после паузы, а не на каждую букву.
const isTyping = (event) => ["search", "number"].includes(event.target.type);
let timer;

fillSelects(form).catch((error) => showStatus(document.getElementById("status"), error.message));

// В запрос идут только заполненные поля формы.
async function search() {
  clearTimeout(timer);
  const params = Object.fromEntries([...new FormData(form)].filter(([, value]) => value !== ""));
  const data = await list.reload(params);
  if (data) {
    total.textContent = `Найдено: ${data.total} ${plural(data.total, ["материал", "материала", "материалов"])}`;
  }
}

form.addEventListener("submit", (event) => {
  event.preventDefault();
  search();
});
form.addEventListener("change", (event) => isTyping(event) || search());
form.addEventListener("input", (event) => {
  if (isTyping(event)) {
    clearTimeout(timer);
    timer = setTimeout(search, 300);
  }
});
form.addEventListener("reset", () => setTimeout(search));

search();
